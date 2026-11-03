<?php
declare(strict_types=1);

require __DIR__ . '/_boot.php';

$state = $tt_root . '/data/mock_state.json';
$mlog  = $tt_root . '/data/mock.log';
$users = [
    'AbCdEfGhIjKlMnOp' => ['username' => 'user0', 'sq' => ['sq-1'], 'hw' => 2],
    'SecondUser000001' => ['username' => 'user0_addsub'],
    'ThirdUserShort22' => ['username' => 'vera', 'status' => 'DISABLED'],
];
file_put_contents($state, json_encode(['users' => $users]));
$panel = t_serve($tt_root . '/tools', $tt_root . '/tools/mock_panel.php', ['MOCK_STATE' => $state, 'MOCK_LOG' => $mlog]);
t_settings(['sub_source' => 'panel', 'remnawave_url' => $panel, 'remnawave_api_key' => 'test-token', 'geoip_auto' => '0']);
set_setting('update_state', json_encode(['checked_at' => time()]));
$mw = t_serve($tt_root, $tt_root . '/tools/tests/_router.php');

$cut  = static function () use ($mlog) { @file_put_contents($mlog, ''); rw_user_memo_drop(); };
$hits = static function (string $path) use ($mlog) { return substr_count((string) @file_get_contents($mlog), 'GET ' . $path . "\n"); };
$BS = '/api/users/by-short-uuid/AbCdEfGhIjKlMnOp';
$e = ''; $c = 0;

// В одном процессе — один запрос на пользователя
$cut();
$u1 = remnawave_get_user_by_short('AbCdEfGhIjKlMnOp', $e, $c);
$u2 = remnawave_get_user_by_short('AbCdEfGhIjKlMnOp', $e, $c);
t_eq('два чтения подряд — один запрос, тот же ответ', [$hits($BS), $u1 === $u2, $u1['username'] ?? '', $c], [1, true, 'user0', 200]);
remnawave_get_user_by_username('user0_addsub', $e);
remnawave_get_user_by_username('user0_addsub', $e);
t_eq('by-username помнится отдельно', [$hits('/api/users/by-username/user0_addsub'), $hits($BS)], [1, 1]);

// Отказ панели тоже помнится: второй модуль не ждёт ответ ещё раз
$cut();
$n1 = remnawave_get_user_by_short('NoSuchUser000001', $e1, $c1);
$n2 = remnawave_get_user_by_short('NoSuchUser000001', $e2, $c2);
t_eq('404 помнится с кодом и текстом', [$hits('/api/users/by-short-uuid/NoSuchUser000001'), $n1, $n2, $c1, $c2, $e2], [1, null, null, 404, 404, 'HTTP 404']);

// Своя запись в /api/users сбрасывает memo — следующее чтение свежее
$cut();
remnawave_get_user_by_short('AbCdEfGhIjKlMnOp', $e, $c);
file_put_contents($state, json_encode(['users' => ['AbCdEfGhIjKlMnOp' => ['username' => 'user0', 'sq' => ['sq-2'], 'hw' => 2]] + $users]));
remnawave_update_user(rw_ref('uuid', '00000000-0000-4000-8000-000000000000'), ['description' => 'x'], $e, $c);
$u3 = remnawave_get_user_by_short('AbCdEfGhIjKlMnOp', $e, $c);
t_eq('после PATCH — новый запрос, видны изменения панели', [$hits($BS), $u3['activeInternalSquads'][0]['uuid'] ?? ''], [2, 'sq-2']);
foreach (['reset_traffic' => fn() => remnawave_reset_traffic(rw_ref('uuid', '00000000-0000-4000-8000-000000000000'), $e),
          'user_post'     => fn() => remnawave_user_post(rw_ref('uuid', '00000000-0000-4000-8000-000000000000'), 'enable', 'enable', null, $e),
          'create_user'   => fn() => remnawave_create_user(['username' => 'zzz'], $e),
          'delete_user'   => fn() => remnawave_delete_user(rw_ref('uuid', '00000000-0000-4000-8000-000000000009'), $e)] as $name => $w) {
    $cut();
    remnawave_get_user_by_short('AbCdEfGhIjKlMnOp', $e, $c);
    $w();
    remnawave_get_user_by_short('AbCdEfGhIjKlMnOp', $e, $c);
    t_eq('запись ' . $name . ' сбрасывает memo', $hits($BS), 2);
}
file_put_contents($state, json_encode(['users' => $users]));

// Долгий процесс: memo не старше USER_MEMO_TTL
$cut();
remnawave_get_user_by_short('AbCdEfGhIjKlMnOp', $e, $c);
foreach ($GLOBALS['submw_user_memo'] as $mk => $mv) $GLOBALS['submw_user_memo'][$mk]['t'] -= USER_MEMO_TTL + 1;
remnawave_get_user_by_short('AbCdEfGhIjKlMnOp', $e, $c);
t_eq('через USER_MEMO_TTL — свежий запрос', $hits($BS), 2);
t_settings(['remnawave_url' => $panel . '/']);
remnawave_get_user_by_short('AbCdEfGhIjKlMnOp', $e, $c);
t_eq('хвостовой слэш в адресе — та же панель, memo общее', $hits($BS), 2);
t_settings(['remnawave_api_key' => 'other-token']);
remnawave_get_user_by_short('AbCdEfGhIjKlMnOp', $e, $c);
t_eq('другой токен — другое memo', $hits($BS), 3);
t_settings(['remnawave_url' => $panel, 'remnawave_api_key' => 'test-token']);

// Горячий путь: доп. конфиги + слияние подписок — by-short уходит один раз за выдачу, а не два
t_settings(['addsub_enabled' => '1', 'addsub_username_suffix' => '_addsub', 'apisub_accept' => '1']);
squadconf_ensure();
$tt_pdo->prepare("INSERT INTO squad_configs (squad_uuid, type, name, enabled, raw, parsed, squads) VALUES ('sq-1', 'amneziawg', 'cfg', 1, '[Interface]', NULL, '[\"sq-1\"]')")->execute();
$cut();
[$code] = t_http('GET', $mw . '/api/sub/AbCdEfGhIjKlMnOp', ['user-agent' => 'Happ/3.0.0']);
t_eq('выдача с addsub и доп. конфигами: подписка, by-short один раз, by-username один раз', [$code, $hits('/api/sub/AbCdEfGhIjKlMnOp'), $hits($BS), $hits('/api/users/by-username/user0_addsub')], [200, 1, 1, 1]);
$cut();
[$code] = t_http('GET', $mw . '/api/sub/AbCdEfGhIjKlMnOp', ['user-agent' => 'Happ/3.0.0']);
t_eq('повторная выдача: кэши squad_cache и addsub_cache — панель только за подпиской', [$code, $hits($BS), $hits('/api/users/by-username/user0_addsub')], [200, 0, 0]);
$tt_pdo->exec("DELETE FROM squad_cache"); $tt_pdo->exec("DELETE FROM addsub_cache");
t_settings(['addsub_parallel_fetch' => '1']);
$cut();
[$code] = t_http('GET', $mw . '/api/sub/AbCdEfGhIjKlMnOp', ['user-agent' => 'Happ/3.0.0']);
t_eq('параллельная докачка addsub: by-short тоже один раз', [$code, $hits($BS), $hits('/api/users/by-username/user0_addsub')], [200, 1, 1]);

// Админка: сохранение карточки — одно чтение до записи и одно после (ответ PATCH без пользователя в моке)
$jar = t_admin_login($mw);
[, , $page] = t_http('GET', $mw . '/admin/index.php?tab=users', [], null, $jar);
preg_match('~name="csrf" value="([0-9a-f]+)"~', $page, $m);
$csrf = $m[1] ?? (preg_match('~csrf[\'"]?\s*[:=]\s*[\'"]([0-9a-f]{16,})~', $page, $m2) ? $m2[1] : '');
$cut();
[$code, , $b] = t_http('POST', $mw . '/admin/index.php?ajax=ua_save', ['content-type' => 'application/x-www-form-urlencoded'], http_build_query(['csrf' => $csrf, 'su' => 'AbCdEfGhIjKlMnOp', 'fields' => json_encode(['description' => 'note'])]), $jar);
t_ok('карточка: сохранение прошло', $code === 200 && !empty(json_decode($b, true)['ok']), $b);
t_ok('карточка: by-short не чаще двух раз за сохранение', $hits($BS) <= 2, (string) $hits($BS));

t_done();
