<?php
declare(strict_types=1);

require __DIR__ . '/_boot.php';

$state = $tt_root . '/data/mock_state.json';
file_put_contents($state, json_encode(['users' => ['AbCdEfGhIjKlMnOp' => ['status' => 'ACTIVE']]]));
$plog  = $tt_root . '/data/mock_panel.log';
$panel = t_serve($tt_root . '/tools', $tt_root . '/tools/mock_panel.php', ['MOCK_STATE' => $state, 'MOCK_LOG' => $plog]);
t_settings(['sub_source' => 'panel', 'remnawave_url' => $panel, 'remnawave_api_key' => 'test-token', 'geoip_auto' => '0']);
// Проверка обновлений ходит на GitHub — в тесте она уже «была».
set_setting('update_state', json_encode(['checked_at' => time()]));

$mw  = t_serve($tt_root, $tt_root . '/tools/tests/_router.php', [], ['opcache.enable' => '0']);
$jar = t_admin_login($mw);
[$c, , $b] = t_http('GET', $mw . '/admin/index.php', [], null, $jar);
t_eq('админка: главная после входа', $c, 200);
preg_match_all('~[?&]tab=([a-z_]+)~', $b, $m);
$tabs = array_values(array_unique($m[1]));
t_ok('админка: в меню есть вкладки', count($tabs) >= 10, implode(',', $tabs));
foreach ($tabs as $tab) {
    [$c, , $page] = t_http('GET', $mw . '/admin/index.php?tab=' . $tab, [], null, $jar);
    t_ok('вкладка ' . $tab . ' открывается', $c === 200 && strpos($page, '</html>') !== false, 'код ' . $c);
}

$ulist = static function () use ($plog): int { return substr_count((string) @file_get_contents($plog), ' /api/users' . "\n"); };
db()->exec('DELETE FROM admin_cache');
$n0 = $ulist();
[$c, , $page] = t_http('GET', $mw . '/admin/index.php?tab=reqlog', [], null, $jar);
t_ok('лог запросов: открытие тянет список пользователей панели', $c === 200 && $ulist() > $n0);
$n1 = $ulist();
db()->exec('UPDATE admin_cache SET ts = ts - 60');
for ($i = 0; $i < 3; $i++) {
    [$c, , $b] = t_http('GET', $mw . '/admin/index.php?tab=reqlog&ajax=reqlog', [], null, $jar);
    $j = json_decode($b, true);
    t_ok('лог запросов: тик автообновления ' . ($i + 1), $c === 200 && !empty($j['ok']) && isset($j['html']));
}
t_eq('лог запросов: тики автообновления живут кэшем, панель не опрашивают', $ulist(), $n1);
[$c, , $b] = t_http('GET', $mw . '/admin/index.php?tab=reqlog&ajax=reqlog&fresh=1', [], null, $jar);
t_ok('лог запросов: кнопка «Обновить» тянет свежий список', $c === 200 && $ulist() > $n1);
t_ok('лог запросов: по умолчанию обновление раз в 60 с', strpos($page, 'value="60" selected') !== false);
preg_match('~name="csrf" value="([0-9a-f]+)"~', $page, $m);
$save = static function (string $v) use ($mw, $jar, $m): string {
    t_http('POST', $mw . '/admin/index.php', ['content-type' => 'application/x-www-form-urlencoded'], http_build_query(['csrf' => $m[1], 'action' => 'save_reqlog_refresh', 'reqlog_refresh' => $v, 'xhr' => '1']), $jar);
    [, , $p] = t_http('GET', $mw . '/admin/index.php?tab=reqlog', [], null, $jar);
    return preg_match('~<select name="reqlog_refresh".*?<option value="(\d+)" selected>~s', $p, $mm) ? $mm[1] : '';
};
t_eq('лог запросов: «при открытии» сохраняется', $save('0'), '0');
t_eq('лог запросов: чужое значение интервала не принимается', $save('7'), '0');

[, , $page] = t_http('GET', $mw . '/admin/index.php?tab=users', [], null, $jar);
t_ok('пользователи: по умолчанию сортировка по ID, новые сверху', strpos($page, '"sort":{"k":"id","d":-1}') !== false);
preg_match('~"csrf":"([0-9a-f]+)"~', $page, $m);
$usort = static function (string $v) use ($mw, $jar, $m): string {
    [, , $r] = t_http('POST', $mw . '/admin/index.php?ajax=users_sort', ['content-type' => 'application/x-www-form-urlencoded'], http_build_query(['csrf' => $m[1], 'sort' => $v]), $jar);
    [, , $p] = t_http('GET', $mw . '/admin/index.php?tab=users', [], null, $jar);
    return preg_match('~"sort":(\{[^}]*\})~', $p, $mm) ? $mm[1] : '';
};
t_eq('пользователи: сортировка сохраняется в настройках', $usort('name:asc'), '{"k":"name","d":1}');
t_eq('пользователи: чужой ключ сортировки не принимается', $usort('hack:asc'), '{"k":"name","d":1}');
$ucols = static function (string $v) use ($mw, $jar, $m): string {
    t_http('POST', $mw . '/admin/index.php?ajax=users_cols', ['content-type' => 'application/x-www-form-urlencoded'], http_build_query(['csrf' => $m[1], 'hide' => $v]), $jar);
    [, , $p] = t_http('GET', $mw . '/admin/index.php?tab=users', [], null, $jar);
    return preg_match('~"hide":(\[[^\]]*\])~', $p, $mm) ? $mm[1] : '';
};
t_ok('пользователи: по умолчанию все колонки видны', strpos($page, '"hide":[]') !== false);
t_eq('пользователи: скрытые колонки сохраняются', $ucols('sq,on'), '["sq","on"]');
t_eq('пользователи: чужие колонки отбрасываются, имя скрыть нельзя', $ucols('nm,hack,id'), '["id"]');
t_eq('пользователи: пустой список — все видны', $ucols(''), '[]');

[, , $page] = t_http('GET', $mw . '/admin/index.php?tab=sysinfo', [], null, $jar);
t_ok('OPcache выключен — предупреждение на «О системе»', strpos($page, '<b>OPcache выключен.</b>') !== false);

if (extension_loaded('Zend OPcache')) {
    $mw2 = t_serve($tt_root, $tt_root . '/tools/tests/_router.php', [], ['opcache.enable' => '1']);
    $jar2 = t_admin_login($mw2);
    [, , $page] = t_http('GET', $mw2 . '/admin/index.php?tab=sysinfo', [], null, $jar2);
    t_ok('OPcache включён — предупреждения нет', strpos($page, 'Окружение') !== false && strpos($page, 'OPcache выключен') === false);
    @mkdir($tt_root . '/data/opcache', 0700);
    $mw3 = t_serve($tt_root, $tt_root . '/tools/tests/_router.php', [], ['opcache.enable' => '1', 'opcache.file_cache' => $tt_root . '/data/opcache', 'opcache.file_cache_only' => '1']);
    $jar3 = t_admin_login($mw3);
    [, , $page] = t_http('GET', $mw3 . '/admin/index.php?tab=sysinfo', [], null, $jar3);
    t_ok('только файловый кэш OPcache — предупреждения нет', strpos($page, 'Окружение') !== false && strpos($page, 'OPcache выключен') === false);
}

// Вебхуки об устройствах: модель, ОС и клиент попадают в историю карточки и в лог вебхуков, hwid — нет
t_settings(['webhook_secret' => 'whsec']);
unset($GLOBALS['submw_settings_cache']);
$whPost = static function (array $payload) use ($mw): int {
    $raw = json_encode($payload, JSON_UNESCAPED_UNICODE);
    [$c] = t_http('POST', $mw . '/webhook.php', ['content-type' => 'application/json', 'x-remnawave-signature' => hash_hmac('sha256', $raw, 'whsec')], $raw);
    return $c;
};
$whUser = ['uuid' => '00000000-0000-4000-8000-000000000000', 'id' => 1, 'shortUuid' => 'AbCdEfGhIjKlMnOp', 'username' => 'user0', 'status' => 'ACTIVE'];
t_eq('hwid-вебхук принят', $whPost(['event' => 'user_hwid_devices.added', 'timestamp' => gmdate('c'), 'data' => ['user' => $whUser, 'hwidUserDevice' => ['hwid' => 'HW-SECRET-1', 'platform' => 'android', 'osVersion' => '14', 'deviceModel' => 'Pixel 8', 'userAgent' => "Happ/2.1.0\x01"]]]), 200);
t_eq('hwid-вебхук об удалении принят', $whPost(['event' => 'user_hwid_devices.deleted', 'timestamp' => gmdate('c'), 'data' => ['user' => $whUser, 'hwidUserDevice' => ['hwid' => 'HW-SECRET-1', 'platform' => 'ios', 'osVersion' => '17.4', 'deviceModel' => 'iPhone15,2']]]), 200);
$hist = ua_history('AbCdEfGhIjKlMnOp');
$hw = array_values(array_filter($hist, static fn($e) => $e['k'] === 'wh' && strpos($e['ev'], 'user_hwid') === 0));
t_eq('история: устройство из вебхука — модель, платформа, версия ОС, клиент', array_map(static fn($e) => $e['dev'], $hw), ['iPhone15,2 · ios 17.4', 'Pixel 8 · android 14 · Happ/2.1.0']);
$st = $tt_pdo->query("SELECT meta FROM webhook_log WHERE event LIKE 'user_hwid%'");
$metas = implode("\n", $st->fetchAll(PDO::FETCH_COLUMN));
t_ok('hwid в журнал вебхуков не пишется', strpos($metas, 'HW-SECRET') === false && strpos($metas, '"hw"') !== false);
[, , $page] = t_http('GET', $mw . '/admin/index.php?tab=whlog', [], null, $jar);
t_ok('лог вебхуков показывает устройство', strpos($page, 'Pixel 8 · android 14 · Happ/2.1.0') !== false && strpos($page, 'HW-SECRET') === false);

t_done();
