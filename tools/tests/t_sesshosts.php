<?php
declare(strict_types=1);

require __DIR__ . '/_boot.php';

$state = $tt_root . '/data/mock_state.json';
$mlog  = $tt_root . '/data/mock.log';
$N1 = '11111111-1111-4111-8111-111111111111';
$N2 = '22222222-2222-4222-8222-222222222222';
$I1 = 'aaaaaaaa-0000-4000-8000-000000000001';
$I2 = 'aaaaaaaa-0000-4000-8000-000000000002';
$S1 = 'bbbbbbbb-0000-4000-8000-000000000001';
$S2 = 'bbbbbbbb-0000-4000-8000-000000000002';
$S3 = 'bbbbbbbb-0000-4000-8000-000000000003';
$base = [
    'users' => [
        'MainUserShort001' => ['status' => 'ACTIVE', 'sq' => [$S1], 'node' => $N1],
        'SubUserShort0002' => ['status' => 'ACTIVE', 'sq' => [$S2], 'node' => $N1],
    ],
    'nodes' => [$N1 => ['name' => 'MSK2-node', 'cc' => 'RU'], $N2 => ['name' => 'AMS-node', 'cc' => 'NL', 'addr' => 'ams.example.net']],
    'squads' => [['uuid' => $S1, 'name' => 'Основа', 'inb' => [$I1]], ['uuid' => $S2, 'name' => 'Сабка', 'inb' => [$I2]], ['uuid' => $S3, 'name' => 'Отдельный', 'inb' => [$I1]]],
    'hosts' => [
        ['remark' => 'MSK2', 'address' => 'msk2.example.net', 'nodes' => [$N1], 'inb' => $I1],
        ['remark' => 'MSK2 -> AMS1', 'address' => 'msk2.example.net', 'nodes' => [$N1], 'inb' => $I1],
        ['remark' => 'MSK2 -> AMS2', 'address' => 'msk2.example.net', 'nodes' => [$N1], 'inb' => $I1],
        ['remark' => 'MSK2 -> AMS3', 'address' => 'msk2.example.net', 'nodes' => [$N1], 'inb' => $I1],
        ['remark' => 'Резерв 1', 'address' => 'msk2.example.net', 'nodes' => [$N1], 'inb' => $I2],
        ['remark' => 'Резерв 2', 'address' => 'msk2.example.net', 'nodes' => [$N1], 'inb' => $I2],
        ['remark' => 'AMS', 'address' => 'ams.example.net', 'nodes' => [], 'inb' => $I1],
        ['remark' => 'AMS скрытый', 'address' => 'ams.example.net', 'nodes' => [], 'inb' => $I2, 'hid' => 1],
    ],
];
$mock = static function (array $over = []) use ($state, $base) { file_put_contents($state, json_encode(array_replace($base, $over))); };
$mock();
$panel = t_serve($tt_root . '/tools', $tt_root . '/tools/mock_panel.php', ['MOCK_STATE' => $state, 'MOCK_LOG' => $mlog, 'PHP_CLI_SERVER_WORKERS' => '4']);
t_settings(['sub_source' => 'panel', 'remnawave_url' => $panel, 'remnawave_api_key' => 'test-token', 'geoip_auto' => '0', 'sess_enabled' => '1']);
set_setting('update_state', json_encode(['checked_at' => time()]));
$mw = t_serve($tt_root, $tt_root . '/tools/tests/_router.php');
$hits = static fn(string $p) => substr_count((string) @file_get_contents($mlog), ' ' . $p);
$reset = static function () { unset($GLOBALS['submw_pref_memo']); db()->exec("DELETE FROM admin_cache WHERE k IN ('pref_nodes', 'pref_hosts', 'pref_isquads', 'sess_nodes')"); };
$hostsFor = static function (?array $sq, string $node) {
    $kn = sess_nodes_fresh();
    return sess_user_hosts($node, $kn[$node], sess_sq_inb($sq));
};

$e = '';
sess_nodes_fetch($e, 0);
$kn = sess_nodes_known();
t_eq('подпись ноды для всех — как раньше: первые четыре хоста', $kn[$N1]['h'], ['MSK2', 'MSK2 -> AMS1', 'MSK2 -> AMS2', 'MSK2 -> AMS3']);
t_ok('снимок нод не вырос: полного списка хостов в нём нет', !isset($kn[$N1]['hx']) && array_keys($kn[$N1]) === ['n', 'cc', 'h']);
t_eq('пользователь сабки — только хосты её инбаунда', $hostsFor([$S2], $N1), ['Резерв 1', 'Резерв 2']);
t_eq('основной пользователь — хосты основного инбаунда', $hostsFor([$S1], $N1), ['MSK2', 'MSK2 -> AMS1', 'MSK2 -> AMS2', 'MSK2 -> AMS3']);
t_eq('оба сквада — шесть хостов: три и «+3»', $hostsFor([$S1, $S2], $N1), ['MSK2', 'MSK2 -> AMS1', 'MSK2 -> AMS2', '+3']);
t_eq('без сквадов — хостов нет, будет имя ноды', $hostsFor([], $N1), []);
t_eq('сквады не переданы — подпись ноды как раньше', $hostsFor(null, $N1), $kn[$N1]['h']);
t_eq('регистр uuid не важен', $hostsFor([strtoupper($S2)], $N1), ['Резерв 1', 'Резерв 2']);
t_eq('хост по адресу ноды: обычный есть — скрытый не показан', $hostsFor([$S1, $S2], $N2), ['AMS']);
t_eq('только скрытый хост — показан он', $hostsFor([$S2], $N2), ['AMS скрытый']);

// 3.4+: internalSquads {mode, squads}
$mock(['hosts' => [
    ['remark' => 'Всем', 'address' => 'msk2.example.net', 'nodes' => [$N1], 'inb' => $I1, 'isq' => ['mode' => 'EXCLUDE', 'squads' => []]],
    ['remark' => 'Кроме основы', 'address' => 'msk2.example.net', 'nodes' => [$N1], 'inb' => $I1, 'isq' => ['mode' => 'EXCLUDE', 'squads' => [$S1]]],
    ['remark' => 'Только отдельный', 'address' => 'msk2.example.net', 'nodes' => [$N1], 'inb' => $I1, 'isq' => ['mode' => 'ALLOW_ONLY', 'squads' => [$S3]]],
]]);
$reset();
sess_nodes_fetch($e, 0);
t_eq('EXCLUDE: исключённый сквад хост не получает', $hostsFor([$S1], $N1), ['Всем']);
t_eq('ALLOW_ONLY: хост только у указанного сквада', $hostsFor([$S3], $N1), ['Всем', 'Кроме основы', 'Только отдельный']);
t_eq('исключение по одному скваду не мешает другому с тем же инбаундом', $hostsFor([$S1, $S3], $N1), ['Всем', 'Кроме основы', 'Только отдельный']);

// 3.0–3.3: excludedInternalSquads
$mock(['hosts' => [
    ['remark' => 'Всем', 'address' => 'msk2.example.net', 'nodes' => [$N1], 'inb' => $I1],
    ['remark' => 'Кроме основы', 'address' => 'msk2.example.net', 'nodes' => [$N1], 'inb' => $I1, 'exsq' => [$S1]],
]]);
$reset();
sess_nodes_fetch($e, 0);
t_eq('3.0–3.3: excludedInternalSquads работает как EXCLUDE', [$hostsFor([$S1], $N1), $hostsFor([$S3], $N1)], [['Всем'], ['Всем', 'Кроме основы']]);

// Снимки прежней версии без новых полей — перечитываются один раз
$mock();
$reset();
sess_nodes_fetch($e, 0);
remnawave_internal_squads($e, 600);
$c = admin_cache_get('pref_isquads', PHP_INT_MAX);
foreach ($c['d'] as $k => $v) unset($c['d'][$k]['inb']);
admin_cache_put('pref_isquads', $c);
$h = admin_cache_get('pref_hosts', PHP_INT_MAX);
foreach ($h['d'] as $k => $v) unset($h['d'][$k]['sqm'], $h['d'][$k]['sqs'], $h['d'][$k]['hid']);
admin_cache_put('pref_hosts', $h);
unset($GLOBALS['submw_pref_memo']);
$hs0 = $hits('/api/hosts'); $sq0 = $hits('/api/internal-squads');
sess_nodes_fetch($e, 0);
t_eq('снимки прежней версии без новых полей перечитаны — отбор работает', sess_user_hosts($N1, sess_nodes_known()[$N1], sess_sq_inb([$S2])), ['Резерв 1', 'Резерв 2']);
t_eq('старые снимки перечитаны по разу', [$hits('/api/hosts') - $hs0, $hits('/api/internal-squads') - $sq0], [1, 1]);
sess_nodes_fetch($e, 0);
sess_sq_inb([$S2]);
t_eq('дальше — из снимков, без запросов', [$hits('/api/hosts') - $hs0, $hits('/api/internal-squads') - $sq0], [1, 1]);
$h = admin_cache_get('pref_hosts', PHP_INT_MAX);
foreach ($h['d'] as $k => $v) unset($h['d'][$k]['sqm']);
admin_cache_put('pref_hosts', $h);
unset($GLOBALS['submw_pref_memo'], $GLOBALS['submw_sess_hidx']);
t_eq('снимок хостов старого формата — подпись ноды, без запросов', [sess_user_hosts($N1, ['h' => ['MSK2']], sess_sq_inb([$S2])), $hits('/api/hosts') - $hs0], [['MSK2'], 1]);
db()->exec("DELETE FROM admin_cache WHERE k = 'pref_hosts'");
unset($GLOBALS['submw_pref_memo'], $GLOBALS['submw_sess_hidx']);
t_eq('снимка хостов нет — подпись ноды, без запросов', [sess_user_hosts($N1, ['h' => ['MSK2']], sess_sq_inb([$S2])), $hits('/api/hosts') - $hs0], [['MSK2'], 1]);

// Много пользователей: подпись считается по сочетанию «нода + сквады», в конфиг — только отличные от подписи ноды
$u = [];
for ($i = 0; $i < 300; $i++) $u[sprintf('Bulk%012d', $i)] = ['status' => 'ACTIVE', 'sq' => [$i % 2 ? $S1 : $S2], 'node' => $N1];
$mock(['users' => $base['users'] + $u]);
$reset();
sess_nodes_fetch($e, 0);

// Админка: карточка, онлайн, трафик и список
$jar = t_admin_login($mw);
[$c, , $b] = t_http('GET', $mw . '/admin/index.php?ajax=sess_user&id=2&sq=' . $S2, [], null, $jar);
$j = json_decode($b, true);
t_ok('ajax sess_user: хосты пользователя', $c === 200 && ($j['nodes'][$N1]['h'] ?? null) === ['Резерв 1', 'Резерв 2'], $b);
[$c, , $b] = t_http('GET', $mw . '/admin/index.php?ajax=sess_user&id=2', [], null, $jar);
$j = json_decode($b, true);
t_ok('ajax sess_user без сквадов — подпись ноды', $c === 200 && ($j['nodes'][$N1]['h'] ?? null) === ['MSK2', 'MSK2 -> AMS1', 'MSK2 -> AMS2', 'MSK2 -> AMS3'], $b);
[$c, , $b] = t_http('GET', $mw . '/admin/index.php?ajax=sess_user&id=2&sq=' . rawurlencode("x';--," . $S2), [], null, $jar);
$j = json_decode($b, true);
t_ok('мусор в sq отбрасывается', $c === 200 && ($j['nodes'][$N1]['h'] ?? null) === ['Резерв 1', 'Резерв 2'], $b);
$mock(['users' => $base['users'] + $u, 'conns' => [$N1 => ['2' => ['1.2.3.4']]], 'usage' => ['2' => [$N1 => [1048576]]]]);
[$c, , $b] = t_http('GET', $mw . '/admin/index.php?ajax=sess_live&rv=2&sq=' . $S2, [], null, $jar);
$j = json_decode($b, true);
t_ok('ajax sess_live: хосты пользователя', $c === 200 && !empty($j['ok']) && ($j['nodes'][0]['h'] ?? null) === ['Резерв 1', 'Резерв 2'], $b);
[$c, , $b] = t_http('GET', $mw . '/admin/index.php?ajax=usage&rv=2&d=7&sq=' . $S2, [], null, $jar);
$j = json_decode($b, true);
t_ok('ajax usage: хосты пользователя', $c === 200 && !empty($j['ok']) && ($j['series'][0]['h'] ?? null) === ['Резерв 1', 'Резерв 2'], $b);
[$c, , $page] = t_http('GET', $mw . '/admin/index.php?tab=users', [], null, $jar);
preg_match('~"nh":(\{[^}]*\})~u', $page, $m);
$nh = json_decode($m[1] ?? 'null', true);
t_eq('список: в конфиге одна подпись — для сабки; у основы она совпадает с подписью ноды и не передаётся', $nh, [$N1 . '|' . $S2 => ['Резерв 1', 'Резерв 2']]);
t_ok('список: в строках пользователей подписей нет', $c === 200 && strpos($page, '"nh":[') === false);

// Пользователь в грейсе: хосты и грейс-сквада, и прежних
db()->prepare('INSERT INTO grace_users (short_uuid, user_uuid, orig_squads, grace_until, ended_ts) VALUES (?, ?, ?, ?, 0)')->execute(['SubUserShort0002', 'x', json_encode([$S1]), time() + 86400]);
[$c, , $page] = t_http('GET', $mw . '/admin/index.php?tab=users', [], null, $jar);
preg_match('~"nh":(\{[^}]*\})~u', $page, $m);
$k = ua_sq_all([$S2], ['sq' => [$S1]]);
t_eq('грейс: ключ — объединение сквадов, подпись — хосты обоих', json_decode($m[1] ?? 'null', true)[$N1 . '|' . implode(',', $k)] ?? null, ['MSK2', 'MSK2 -> AMS1', 'MSK2 -> AMS2', '+3']);

// Карточка, изменённая на открытой странице: подпись приходит вместе с пользователем
[$c, , $b] = t_http('GET', $mw . '/admin/index.php?ajax=ua_user&su=MainUserShort001', [], null, $jar);
$j = json_decode($b, true);
t_eq('ajax ua_user: подпись хостов пользователя в ответе', $j['user']['nh'] ?? null, ['MSK2', 'MSK2 -> AMS1', 'MSK2 -> AMS2', 'MSK2 -> AMS3']);
[$c, , $b] = t_http('GET', $mw . '/admin/index.php?ajax=ua_user&su=SubUserShort0002', [], null, $jar);
$j = json_decode($b, true);
t_eq('ajax ua_user: в грейсе — хосты обоих наборов сквадов', $j['user']['nh'] ?? null, ['MSK2', 'MSK2 -> AMS1', 'MSK2 -> AMS2', '+3']);

// Сквад новее снимка — снимок перечитывается, но не чаще раза в минуту
$S4 = 'bbbbbbbb-0000-4000-8000-000000000004';
$mock(['users' => $base['users'], 'squads' => array_merge($base['squads'], [['uuid' => $S4, 'name' => 'Новый', 'inb' => [$I2]]])]);
unset($GLOBALS['submw_pref_memo']);
$c = admin_cache_get('pref_isquads', PHP_INT_MAX); $c['ts'] -= 120; admin_cache_put('pref_isquads', $c);
$q0 = $hits('/api/internal-squads');
t_eq('новый сквад: снимок перечитан, инбаунд найден', [sess_sq_inb([$S4]), $hits('/api/internal-squads') - $q0], [[strtolower($I2) => [$S4]], 1]);
$D = 'bbbbbbbb-0000-4000-8000-0000000000dd';
sess_sq_inb([$D]); sess_sq_inb([$D]);
t_eq('сквада нет и в панели — без повторных запросов, пока снимку меньше минуты', $hits('/api/internal-squads') - $q0, 1);

// Параллельные вызовы за одним справочником
file_put_contents($tt_root . '/data/pref.php', '<?php $_SERVER["REQUEST_URI"] = "/"; $_SERVER["REMOTE_ADDR"] = "127.0.0.1"; require ' . var_export($tt_root . '/lib.php', true) . '; usleep((int) $argv[3] * 1000); $t = microtime(true); $e = ""; $r = panel_ref($argv[1], (int) $argv[2], $e); echo json_encode(["n" => is_array($r) ? count($r) : null, "ms" => (int) round((microtime(true) - $t) * 1000), "e" => $e]);');
$run = static function (array $calls) use ($tt_root) {
    $ps = []; $out = [];
    foreach ($calls as $i => $a) $ps[$i] = proc_open([PHP_BINARY, $tt_root . '/data/pref.php', $a[0], (string) $a[1], (string) ($a[2] ?? 0)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pp[$i]);
    foreach ($ps as $i => $p) { $out[$i] = json_decode((string) stream_get_contents($pp[$i][1]), true); proc_close($p); }
    return $out;
};
$age = static function () { unset($GLOBALS['submw_pref_memo']); $c = admin_cache_get('pref_isquads', PHP_INT_MAX); $c['ts'] -= 3600; unset($c['e'], $c['etm'], $c['em']); admin_cache_put('pref_isquads', $c); };
$mock(['users' => $base['users'], 'latency_ms' => 700]);
$age();
$q0 = $hits('/api/internal-squads');
$r = $run([['isquads', 600], ['isquads', 600, 150], ['isquads', 600, 300]]);
t_eq('три вызова со снимком старше срока — один запрос, данные у всех', [$hits('/api/internal-squads') - $q0, array_column($r, 'n')], [1, [3, 3, 3]]);
$q0 = $hits('/api/internal-squads');
$run([['isquads', 0]]); $run([['isquads', 0]]);
t_eq('принудительное обновление подряд — каждый раз свежий запрос', $hits('/api/internal-squads') - $q0, 2);

$mock(['users' => $base['users'], 'latency_ms' => 1000]);
$age();
$q0 = $hits('/api/internal-squads');
$r = $run([['isquads', 600], ['isquads', 0, 300]]);
t_ok('принудительное обновление не ждёт чужой запрос', $hits('/api/internal-squads') - $q0 === 2 && $r[1]['ms'] < 1600, json_encode($r));

$mock(['users' => $base['users'], 'latency_ms' => 700, 'squads' => array_merge($base['squads'], [['uuid' => $S4, 'name' => 'Новый', 'inb' => [$I2]]])]);
$age();
$c = admin_cache_get('pref_isquads', PHP_INT_MAX); $c['d'] = array_slice($c['d'], 0, 1); admin_cache_put('pref_isquads', $c);
$r = $run([['isquads', 600], ['isquads', 600, 200]]);
t_eq('ждущий берёт свежий ответ, в снимке — свежие данные', [array_column($r, 'n'), count(admin_cache_get('pref_isquads', PHP_INT_MAX)['d'])], [[4, 4], 4]);

t_settings(['remnawave_url' => $panel . '/down']);
$age();
$r = $run([['isquads', 600], ['isquads', 600, 200]]);
$h = admin_cache_get('pref_isquads', PHP_INT_MAX);
t_ok('панель упала: ждущий берёт ошибку первого, без второго запроса; снимок не затёрт', $hits('/down/api/internal-squads') === 1 && $r[1]['n'] === 4 && $r[1]['e'] !== '' && count($h['d']) === 4, json_encode($r));

// Снимок старого формата при недоступной панели: перечитывание не повторяется
unset($GLOBALS['submw_pref_memo'], $GLOBALS['submw_pref_upg']);
$c = admin_cache_get('pref_isquads', PHP_INT_MAX);
foreach ($c['d'] as $k => $v) unset($c['d'][$k]['inb']);
unset($c['e'], $c['etm'], $c['em']);
admin_cache_put('pref_isquads', $c);
$d0 = $hits('/down/api/internal-squads');
sess_sq_inb([$S2]); sess_sq_inb([$S1]); sess_sq_inb([$S2]);
t_eq('старый снимок, панель недоступна — одна попытка перечитать', $hits('/down/api/internal-squads') - $d0, 1);
t_settings(['remnawave_url' => $panel]);

@unlink($tt_root . '/data/pref.php');
@unlink($mlog);
t_done();
