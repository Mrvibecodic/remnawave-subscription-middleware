<?php
declare(strict_types=1);

require __DIR__ . '/_boot.php';
require_once $tt_root . '/lib/connmap.php';

$state = $tt_root . '/data/mock_state.json';
$mlog  = $tt_root . '/data/mock.log';
$base = [
    'users' => [
        'AbCdEfGhIjKlMnOp' => ['username' => 'anna', 'sq' => ['sq-1'], 'hw' => 3],
        'SecondUserShort1' => ['username' => 'boris', 'status' => 'DISABLED'],
        'ThirdUserShort22' => ['username' => 'vera'],
    ],
    'nodes' => [],
];
$mock = static function (array $over = []) use ($state, $base) { file_put_contents($state, json_encode(array_replace($base, $over))); };
$mock();
$panel = t_serve($tt_root . '/tools', $tt_root . '/tools/mock_panel.php', ['MOCK_STATE' => $state, 'MOCK_LOG' => $mlog]);
t_settings(['sub_source' => 'panel', 'remnawave_url' => $panel, 'remnawave_api_key' => 'test-token', 'geoip_auto' => '0', 'sess_enabled' => '1', 'sess_every' => '120']);
set_setting('update_state', json_encode(['checked_at' => time()]));
$mw = t_serve($tt_root, $tt_root . '/tools/tests/_router.php');

$cut   = static function () use ($mlog) { @file_put_contents($mlog, ''); };
$calls = static fn() => substr_count((string) @file_get_contents($mlog), "GET /api/users\n");
$age   = static function (int $sec) { $c = admin_cache_get('pref_users', PHP_INT_MAX); $c['ts'] -= $sec; admin_cache_put('pref_users', $c); unset($GLOBALS['submw_pusers_memo']); };
$forget = static function () { unset($GLOBALS['submw_pusers_memo']); };
$e = '';

// Прежние копии списка у отдельных потребителей убираются при первом обращении
admin_cache_put('reqlog_users', ['names' => []]);
admin_cache_put('cmap_names', ['u' => []]);

// Свежий обход заполняет снимок, в нём только нужные поля
$cut();
$list = panel_users(0, $e);
t_eq('maxAge 0 — обход панели, три строки', [count((array) $list), $calls()], [3, 1]);
t_eq('в строке снимка только поля потребителей', array_keys($list[0]), ['id', 'uuid', 'shortUuid', 'username', 'status', 'hwidDeviceLimit', 'expireAt', 'activeInternalSquads']);
t_eq('сквады — только uuid', $list[0]['activeInternalSquads'], [['uuid' => 'sq-1']]);
t_eq('прежние копии удалены', [admin_cache_get('reqlog_users', PHP_INT_MAX), admin_cache_get('cmap_names', PHP_INT_MAX)], [null, null]);
panel_users(300);
$forget();
panel_users(300);
t_eq('повторные чтения в пределах maxAge — без запросов', $calls(), 1);

// Один снимок на всех потребителей: лог запросов, лимиты сессий, карта, имена c1
$cut();
require_once $tt_root . '/admin/inc/_reqlog_rows.php';
[$names, $users, $total] = reqlog_users(true);
t_eq('лог запросов: имена и статусы из снимка', [$names['AbCdEfGhIjKlMnOp'] ?? '', $users['SecondUserShort1']['status'] ?? '', $users['AbCdEfGhIjKlMnOp']['lim'] ?? null, $total], ['anna', 'DISABLED', 3, 3]);
admin_cache_put('sess_users', ['src' => '', 'u' => null, 'ts0' => 0]);
$lim = sess_users_limits(1800, microtime(true) + 5);
t_eq('лимиты сессий: из снимка, свой лимит и «как в панели»', [$lim['1'] ?? null, $lim['2'] ?? null], [[3, ''], [null, '']]);
t_eq('карта: имена по id из снимка', cmap_names(true)['1'] ?? null, ['anna', 'AbCdEfGhIjKlMnOp']);
t_eq('имена c1 — без похода в панель', chan_names_refresh(), []);
t_eq('четыре потребителя — ноль запросов к панели', $calls(), 0);

// Полная выкачка на вкладке «Пользователи» обновляет снимок для остальных
$age(1000);
$cut();
$jar = t_admin_login($mw);
[$c] = t_http('GET', $mw . '/admin/index.php?tab=users', [], null, $jar);
$forget();
t_eq('вкладка «Пользователи» — один обход, снимок свежий', [$c, $calls(), time() - panel_users_ts() < 5], [200, 1, true]);
reqlog_users(true);
t_eq('лог запросов после вкладки — без запроса', $calls(), 1);
upsert_override('shortuuid', 'SecondUserShort1', 'expired', 'webhook', 'boris', 'test');
[$c, , $page] = t_http('GET', $mw . '/admin/index.php?tab=overrides', [], null, $jar);
t_ok('«Оверрайды» показывают срок из снимка', strpos($page, 'boris') !== false);
t_eq('«Оверрайды» — тоже из снимка', [$c, $calls()], [200, 1]);

// Новый пользователь в панели: лимиты сессий по своему сроку, клиент c1 без имени — свежий обход сразу
$mock(['users' => $base['users'] + ['FourthUserShort4' => ['username' => 'gleb']]]);
$cut();
sess_users_limits(1800, microtime(true) + 5);
t_eq('лимиты сессий: снимок свежий — панель не трогается', $calls(), 0);
chan_ensure();
$tt_pdo->exec("INSERT INTO chan_state (short_uuid, last_seen) VALUES ('FourthUserShort4', " . time() . ')');
set_setting('chan_names', '{}');
t_eq('имя нового клиента c1 — свежий обход', [chan_names_refresh()['FourthUserShort4'] ?? null, $calls()], ['gleb', 1]);
$forget();
t_eq('… и он же обновил снимок', count((array) panel_users(300)), 4);

// Сбой панели: снимок остаётся, повтор не раньше PANEL_REF_RETRY; maxAge 0 — null
$age(1000);
t_settings(['remnawave_url' => $panel . '/down']);
$cut();
t_eq('устаревший снимок при сбое отдаётся, ошибка — текстом', [count((array) panel_users(300, $e)), $e !== ''], [4, true]);
$forget();
panel_users(300, $e);
t_eq('после сбоя — одна попытка', substr_count((string) @file_get_contents($mlog), "GET /down/api/users\n"), 1);
t_eq('maxAge 0 при сбое — null', panel_users(0, $e), null);
t_eq('лог запросов при сбое — прежние имена', count(reqlog_users(true)[0]), 4);
t_settings(['remnawave_url' => $panel]);

// Правка из админки — одна строка снимка, возраст снимка прежний
$ts = panel_users_ts();
panel_users_patch(['shortUuid' => 'AbCdEfGhIjKlMnOp', 'username' => 'anna2', 'status' => 'LIMITED', 'id' => 1]);
$forget();
$row = null; foreach ((array) panel_users_cached() as $r) if ($r['shortUuid'] === 'AbCdEfGhIjKlMnOp') $row = $r;
t_eq('правка: строка заменена, возраст не менялся', [$row['username'] ?? '', $row['status'] ?? '', panel_users_ts()], ['anna2', 'LIMITED', $ts]);
panel_users_patch(['shortUuid' => 'NewOneShortUuid1', 'username' => 'new'], false);
panel_users_patch(['shortUuid' => 'SecondUserShort1'], true);
$forget();
$su = array_column((array) panel_users_cached(), 'shortUuid');
t_eq('создание добавляет, удаление убирает', [in_array('NewOneShortUuid1', $su, true), in_array('SecondUserShort1', $su, true), count($su)], [true, false, 4]);

// Вебхук: созданный и удалённый пользователь — в снимок после ответа
$secret = 'whsecret';
t_settings(['webhook_secret' => $secret]);
$wh = static function (string $event, array $data) use ($mw, $secret) {
    $raw = json_encode(['scope' => 'user', 'event' => $event, 'timestamp' => gmdate('c'), 'data' => $data]);
    return t_http('POST', $mw . '/webhook.php', ['content-type' => 'application/json', 'x-remnawave-signature' => hash_hmac('sha256', $raw, $secret)], $raw)[0];
};
$c1 = $wh('user.created', ['shortUuid' => 'WebhookCreated01', 'username' => 'wh', 'status' => 'ACTIVE', 'id' => 77]);
$c2 = $wh('user.deleted', ['shortUuid' => 'ThirdUserShort22', 'username' => 'vera', 'status' => 'ACTIVE', 'id' => 3]);
usleep(300000);
$forget();
$su = array_column((array) panel_users_cached(), 'shortUuid');
t_eq('вебхук: created добавил, deleted убрал', [$c1, $c2, in_array('WebhookCreated01', $su, true), in_array('ThirdUserShort22', $su, true)], [200, 200, true, false]);

// Смена панели — снимки справочников и пользователей сброшены
panel_ref('nodes', 0);
panel_snapshots_drop();
t_eq('после смены подключения снимков нет', [panel_users_cached(), admin_cache_get('pref_nodes', PHP_INT_MAX)], [null, null]);

// Многостраничный список: лимиты сессий собирают страницы и кормят снимок
$mock(['users_n' => 600]);
admin_cache_put('sess_users', ['src' => '', 'u' => null, 'ts0' => 0]);
$cut();
$lim = sess_users_limits(1800, microtime(true) + 10);
$forget();
t_eq('600 пользователей — три страницы, снимок полный', [count($lim), $calls(), count((array) panel_users(300))], [600, 3, 600]);
$cut();
reqlog_users(true);
t_eq('лог запросов после сессий — без запроса', $calls(), 0);

t_done();
