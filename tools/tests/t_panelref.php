<?php
declare(strict_types=1);

require __DIR__ . '/_boot.php';

$state = $tt_root . '/data/mock_state.json';
$mlog  = $tt_root . '/data/mock.log';
$N1 = '11111111-1111-4111-8111-111111111111';
$N2 = '22222222-2222-4222-8222-222222222222';
$N3 = '33333333-3333-4333-8333-333333333333';
$base = [
    'users'   => ['AbCdEfGhIjKlMnOp' => ['status' => 'ACTIVE']],
    'nodes'   => [$N1 => ['name' => 'Amsterdam', 'cc' => 'NL'], $N2 => ['name' => 'Frankfurt', 'cc' => 'DE', 'off' => true]],
    'hosts'   => [['remark' => 'NL · WS', 'address' => 'ams.example.net', 'nodes' => [$N1]], ['remark' => 'DE', 'address' => 'fra.example.net', 'nodes' => [$N2]]],
    'squads'  => [['uuid' => 'sq-1', 'name' => 'Base', 'members' => 3]],
    'esquads' => [['uuid' => 'ex-1', 'name' => 'Ext', 'members' => 1]],
    'hwid'    => ['enabled' => true, 'limit' => 2],
];
$mock = static function (array $over = []) use ($state, $base) { file_put_contents($state, json_encode(array_replace($base, $over))); };
$mock();
$panel = t_serve($tt_root . '/tools', $tt_root . '/tools/mock_panel.php', ['MOCK_STATE' => $state, 'MOCK_LOG' => $mlog]);
t_settings(['sub_source' => 'panel', 'remnawave_url' => $panel, 'remnawave_api_key' => 'test-token', 'geoip_auto' => '0']);
set_setting('update_state', json_encode(['checked_at' => time()]));
$mw = t_serve($tt_root, $tt_root . '/tools/tests/_router.php');

// Запросы к мок-панели с последнего среза: адрес → число
$cut  = static function () use ($mlog) { @file_put_contents($mlog, ''); };
$hits = static function () use ($mlog) {
    $out = [];
    foreach (preg_split('~\R~', trim((string) @file_get_contents($mlog))) ?: [] as $l) if ($l !== '') $out[$l] = ($out[$l] ?? 0) + 1;
    return $out;
};
$n = static fn(array $h, string $path) => $h['GET ' . $path] ?? 0;
$names = static fn($list) => array_map(static fn($x) => $x['name'], (array) $list);
$e = '';

// Свежий запрос заполняет снимок, следующие читают его
$cut();
t_eq('maxAge 0 — ответ панели', $names(panel_ref('nodes', 0)), ['Amsterdam', 'Frankfurt']);
t_eq('в снимке только нужные поля', array_keys(panel_ref('nodes', 600)[0]), ['uuid', 'name', 'countryCode', 'isConnected', 'isDisabled', 'isConnecting']);
panel_ref('nodes', 600);
remnawave_hwid_settings($e, 600);
t_eq('настройки hwid нормализованы', remnawave_hwid_settings($e, 600), ['enabled' => true, 'limit' => 2]);
$h = $hits();
t_eq('повторные чтения в пределах maxAge — без запросов к панели', [$n($h, '/api/nodes'), $n($h, '/api/subscription-settings')], [1, 1]);

// Свежий запрос видит изменения в панели и обновляет снимок для остальных
$mock(['nodes' => $base['nodes'] + [$N3 => ['name' => 'Paris', 'cc' => 'FR']]]);
t_eq('maxAge > 0 — прежний снимок, пока не истёк', count(panel_ref('nodes', 600)), 2);
t_eq('maxAge 0 — свежий список', count(panel_ref('nodes', 0)), 3);
t_eq('… и он же обновил снимок', count(panel_ref('nodes', 600)), 3);

// Снимок общий для процессов: карточка в админке читает его из базы
$cut();
$jar = t_admin_login($mw);
[$c, , $b] = t_http('GET', $mw . '/admin/index.php?ajax=ua_meta', [], null, $jar);
$j = json_decode($b, true);
$h = $hits();
t_ok('карточка: имена нод из снимка, сквады и лимиты — свежие', $c === 200 && ($j['nodes'][$N3] ?? '') === 'Paris' && ($j['sq'][0]['name'] ?? '') === 'Base' && ($j['ex'][0]['name'] ?? '') === 'Ext' && ($j['hwid']['limit'] ?? 0) === 2, $b);
t_eq('карточка не ходила за нодами, но ходила за сквадами и лимитами', [$n($h, '/api/nodes'), $n($h, '/api/internal-squads'), $n($h, '/api/external-squads'), $n($h, '/api/subscription-settings')], [0, 1, 1, 1]);

// «Панель» на «О системе»: ноды из снимка, свежие — только по кнопке
$cut();
$perr = '';
$s = remnawave_system_stats(45, $perr);
t_eq('счётчик нод из снимка: всего и онлайн', [$s['nodes']['total'], $s['nodes']['online']], [3, 2]);
$h = $hits();
t_eq('статистика — один запрос, без /api/nodes', [$n($h, '/api/system/stats'), $n($h, '/api/nodes')], [1, 0]);
$cut();
remnawave_system_stats(0, $perr);
t_eq('принудительное обновление — свежие ноды', $n($hits(), '/api/nodes'), 1);

// Сбой панели: снимок остаётся, повторная попытка не раньше чем через PANEL_REF_RETRY
$cut();
unset($GLOBALS['submw_pref_memo']);
$c = json_decode((string) setting('pref_nodes', ''), true);
$c['ts'] = time() - 700;
set_setting('pref_nodes', json_encode($c));
t_settings(['remnawave_url' => $panel . '/down']);
t_eq('устаревший снимок при сбое панели отдаётся, ошибка — в $error', [count((array) panel_ref('nodes', 600, $e)), $e !== ''], [3, true]);
panel_ref('nodes', 600, $e);
panel_ref('nodes', 600, $e);
t_eq('после сбоя — одна попытка, остальные ждут', $n($hits(), '/down/api/nodes'), 1);
t_eq('maxAge 0 при сбое — null, старое не подставляется', panel_ref('nodes', 0, $e), null);
t_ok('ошибка — текстом', $e !== '');
t_settings(['remnawave_url' => $panel]);
unset($GLOBALS['submw_pref_memo']);

// Опрос нод для сессий: ноды каждый раз свежие, хосты — из снимка, пока не появилась новая нода
$cut();
t_settings(['sess_enabled' => '1', 'sess_every' => '120']);
$live = sess_nodes_fetch($e, 0);
t_eq('опрос: только подключённые ноды, с подписями хостов', array_map(static fn($x) => $x['h'], $live), [$N1 => ['NL · WS'], $N3 => []]);
sess_nodes_fetch($e, 0);
sess_nodes_fetch($e, 0);
$h = $hits();
t_eq('три опроса — три /api/nodes и один /api/hosts', [$n($h, '/api/nodes'), $n($h, '/api/hosts')], [3, 1]);
$cut();
$mock(['nodes' => $base['nodes'] + [$N3 => ['name' => 'Paris', 'cc' => 'FR']], 'hosts' => $base['hosts'] + [2 => ['remark' => 'FR', 'address' => 'par.example.net', 'nodes' => [$N3]]]]);
sess_nodes_fetch($e, 0);
t_eq('новый хост без новой ноды — подождёт снимка', $n($hits(), '/api/hosts'), 0);
set_setting('sess_nodes', json_encode([$N1 => ['n' => 'Amsterdam', 'cc' => 'NL', 'h' => []]]));
$live = sess_nodes_fetch($e, 0);
t_eq('новая нода в списке — хосты перечитаны сразу', [$n($hits(), '/api/hosts'), $live[$N3]['h'] ?? null], [1, ['FR']]);
$cut();
t_eq('подписи для вкладок — из снимка, без запросов', [count(sess_nodes_fresh()), $n($hits(), '/api/nodes')], [3, 0]);

// Отчёты: индекс адресов из общих снимков
$cut();
$idx = rep_panel_index(1800);
t_eq('индекс адресов: хост → нода из снимка', $idx['par.example.net'][0]['name'] ?? '', 'Paris');
$h = $hits();
t_eq('отчёты — только свой запрос за inbounds', [$n($h, '/api/nodes'), $n($h, '/api/hosts'), $n($h, '/api/config-profiles/inbounds')], [0, 0, 1]);

// Вкладки сквадов — всегда свежие
$cut();
remnawave_internal_squads($e);
remnawave_internal_squads($e);
t_eq('без maxAge — каждый вызов идёт в панель', $n($hits(), '/api/internal-squads'), 2);

// Планировщик: бейдж ошибок рядом с «работает»
set_setting('cron_state', json_encode(['beat' => time(), 'src' => 'cli', 'tasks' => [
    'grace'   => ['start' => time() - 10, 'ok_at' => time() - 5, 'err' => ''],
    'reports' => ['start' => time() - 10, 'ok_at' => 0, 'err' => 'нет доступа к файлу'],
    'brand'   => ['start' => time() - 400, 'ok_at' => 0, 'err' => ''],
]]));
$v = cron_view();
t_eq('ошибки задач посчитаны: явная и зависшая', [$v['status'], $v['errors']], ['alive', 2]);
[$c, , $page] = t_http('GET', $mw . '/admin/index.php?tab=sysinfo&seg=mw', [], null, $jar);
t_ok('бейдж «ошибки: 2» рядом с «работает»', $c === 200 && preg_match('~работает</span> <span class="tag blocked">ошибки: 2</span>~u', $page) === 1, (string) $c);
set_setting('cron_state', json_encode(['beat' => time(), 'src' => 'cli', 'tasks' => ['grace' => ['start' => time() - 10, 'ok_at' => time() - 5, 'err' => '']]]));
[$c, , $page] = t_http('GET', $mw . '/admin/index.php?tab=sysinfo&seg=mw', [], null, $jar);
t_ok('без ошибок бейджа нет', $c === 200 && strpos($page, 'ошибки:') === false);

t_done();
