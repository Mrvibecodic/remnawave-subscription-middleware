<?php
declare(strict_types=1);

require __DIR__ . '/_boot.php';
require_once $tt_root . '/lib/connmap.php';

$state = $tt_root . '/data/mock_state.json';
$mlog  = $tt_root . '/data/mock.log';
$N1 = '11111111-1111-4111-8111-111111111111';
$N2 = '22222222-2222-4222-8222-222222222222';
file_put_contents($state, json_encode([
    'users' => ['AbCdEfGhIjKlMnOp' => ['username' => 'anna'], 'SecondUserShort1' => ['username' => 'boris'], 'ThirdUserShort22' => ['username' => 'vera']],
    'nodes' => [$N1 => ['name' => 'Amsterdam', 'cc' => 'NL'], $N2 => ['name' => 'Frankfurt', 'cc' => 'DE']],
    'hosts' => [['remark' => 'Нидерланды', 'address' => 'ams.example.net', 'nodes' => [$N1]]],
    'conns' => [],
]));
$panel = t_serve($tt_root . '/tools', $tt_root . '/tools/mock_panel.php', ['MOCK_STATE' => $state, 'MOCK_LOG' => $mlog]);
t_settings(['sub_source' => 'panel', 'remnawave_url' => $panel, 'remnawave_api_key' => 'test-token', 'geoip_auto' => '0', 'sess_enabled' => '1', 'sess_every' => '120', 'sess_keep_days' => '30']);
set_setting('update_state', json_encode(['checked_at' => time()]));
sess_ensure();
sess_nodes_fetch($e, 0);
$usersCalls = static fn() => substr_count((string) @file_get_contents($mlog), "GET /api/users\n");
$age = static function (string $k, int $sec) { $c = admin_cache_get($k, PHP_INT_MAX); $c['ts'] -= $sec; admin_cache_put($k, $c); };

$now = time();
$ins = $tt_pdo->prepare('INSERT INTO conn_sess (uid, node, ip, cc, asn, org, sub, start_ts, last_ts, n) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)');
$put = static fn(int $uid, string $node, string $ip, string $cc, int $asn, string $org, string $sub, int $s, int $e) => $ins->execute([$uid, $node, $ip, $cc, $asn, $org, $sub, $s, $e]);
// anna: Москва на двух нодах одним IP + второй IP; boris: Свердловская сегодня и Анталья 5 дней назад; vera: регион неизвестен; старая сессия за пределом 30 дней
$put(1, $N1, '1.1.1.1', 'RU', 8359, 'MTS', 'Moscow', $now - 7200, $now - 3600);
$put(1, $N2, '1.1.1.1', 'RU', 8359, 'MTS', 'Moscow', $now - 5000, $now - 4000);
$put(1, $N1, '2.2.2.2', 'RU', 3216, 'Beeline', 'Moscow', $now - 600, $now - 60);
$put(2, $N2, '3.3.3.3', 'RU', 12389, 'Rostelecom', 'Sverdlovsk Oblast', $now - 90000, $now - 1000);
$put(2, $N1, '4.4.4.4', 'TR', 9121, 'Turk Telekom', 'Antalya', $now - 5 * 86400 - 3600, $now - 5 * 86400);
$put(3, $N1, '5.5.5.5', 'RU', 15378, 'T2', '', $now - 3000, $now - 2000);
$put(3, $N1, '6.6.6.6', 'RU', 15378, 'T2', '', $now - 40 * 86400, $now - 40 * 86400 + 60);

// Периоды
t_eq('периоды по сроку хранения 30 дней', cmap_presets(), [1, 7, 30]);
set_setting('sess_keep_days', '7');
t_eq('срок хранения 7 дней — без «30 дней»', cmap_presets(), [1, 7]);
t_eq('недоступный период — 24 часа', cmap_period('30', 0, 0, $now)['p'], '1');
$c = cmap_period('c', $now - 20 * 86400, $now + 999, $now);
t_eq('свой период обрезан сроком хранения и текущим моментом', [$c['from'], $c['to']], [$now - 7 * 86400, $now]);
t_eq('пустой свой период отклонён', cmap_period('c', $now, $now - 5, $now), null);
set_setting('sess_keep_days', '30');

// Сводка за сутки
$d = cmap_build(cmap_period('1'));
$U = []; foreach ($d['U'] as $u) $U[$u[0]] = $u;
t_eq('за сутки — три клиента', count($d['U']), 3);
t_eq('IP клиента — разные адреса за период (один IP на двух нодах — один)', $U[1][3], 2);
t_eq('Анталья пятидневной давности не попала в сутки', in_array(['TR', 'Antalya'], $d['R'], true), false);
t_eq('имена и shortUuid из панели', [$U[1][1], $U[1][2], $U[2][1]], ['anna', 'AbCdEfGhIjKlMnOp', 'boris']);
$nodes = array_column($d['N'], 0, 3);
t_eq('нода подписана хостом, без хоста — именем', [$nodes[$N1] ?? '', $nodes[$N2] ?? ''], ['Нидерланды', 'Frankfurt']);
$secs = 0; foreach ($d['G'] as $g) if ($d['U'][$g[0]][0] === 2) $secs += $g[5];
t_eq('время в сети обрезано началом периода (+ интервал опроса)', $secs, (($now - 1000) - $d['from']) + 120);
t_eq('провайдеры по AS в справочнике', [$d['A']->{8359} ?? '', $d['A']->{15378} ?? ''], ['MTS', 'T2']);
t_ok('сессия старше срока не попадает даже в 30 дней', !in_array('6.6.6.6', array_column(cmap_user(3, cmap_period('30'))['rows'], 1), true));

// 7 дней
$d7 = cmap_build(cmap_period('7'));
t_ok('за 7 дней — Анталья есть', in_array(['TR', 'Antalya'], $d7['R'], true));

// Кэш: повтор в пределах 15 минут — без запросов к панели и без пересчёта
$tt_pdo->exec("DELETE FROM admin_cache WHERE k LIKE 'cmap%'");
$before = $usersCalls();
$v1 = cmap_view(cmap_period('1'), $stale);
t_eq('первое открытие — считается сразу', [$stale, count($v1['U'])], [false, 3]);
t_eq('имена — одним проходом по списку', $usersCalls() - $before, 1);
$put(1, $N2, '9.9.9.9', 'RU', 8359, 'MTS', 'Moscow', $now - 100, $now - 50);
$v2 = cmap_view(cmap_period('1'), $stale);
t_eq('в пределах 15 минут — та же сводка', [$stale, $v2['ts'], $v2['U']], [false, $v1['ts'], $v1['U']]);
t_eq('повтор — без похода в панель', $usersCalls() - $before, 1);
$age('cmap_p1', CMAP_TTL + 5);
$v3 = cmap_view(cmap_period('1'), $stale);
t_eq('устаревшая — отдаётся сразу с пометкой', [$stale, $v3['U']], [true, $v1['U']]);
cmap_refresh_after_response('1');
$v4 = admin_cache_get('cmap_p1', PHP_INT_MAX);
$U4 = []; foreach ($v4['U'] as $u) $U4[$u[0]] = $u;
t_eq('после ответа пересчитана', $U4[1][3], 3);
t_eq('имена при пересчёте — из кэша, пока свежие', $usersCalls() - $before, 1);
$lk = cmap_lock(false);
$age('cmap_p1', CMAP_TTL + 5);
$t0 = admin_cache_get('cmap_p1', PHP_INT_MAX)['ts'];
cmap_refresh_after_response('1');
t_eq('параллельный пересчёт не запускается второй раз', admin_cache_get('cmap_p1', PHP_INT_MAX)['ts'], $t0);
if ($lk) { flock($lk, LOCK_UN); fclose($lk); }

// Имена: при ответе — из кэша любой давности, обход панели — только в фоне
$nm = cmap_cache_get('cmap_names'); $nm['at'] -= CMAP_TTL + 5; $nm['try'] -= CMAP_TTL + 5; cmap_cache_put('cmap_names', $nm);
$tt_pdo->exec("DELETE FROM admin_cache WHERE k = 'cmap_p7'");
$b1 = $usersCalls();
cmap_view(cmap_period('7'), $stale);
t_eq('первое открытие периода со старыми именами — панель не обходится', $usersCalls() - $b1, 0);
$age('cmap_p7', CMAP_TTL + 5);
cmap_refresh_after_response('7');
t_eq('фоновый пересчёт обновляет старые имена', $usersCalls() - $b1, 1);
$nm = cmap_cache_get('cmap_names'); $nm['at'] -= CMAP_TTL + 5; $nm['try'] -= CMAP_TTL + 5; cmap_cache_put('cmap_names', $nm);
set_setting('remnawave_url', 'http://127.0.0.1:9');
$t1 = microtime(true); $n1 = cmap_names(true);
t_eq('панель недоступна — старые имена остаются', $n1['1'][0] ?? '', 'anna');
$t1 = microtime(true); cmap_names(true); $t1 = microtime(true) - $t1;
t_ok('неудачная попытка запомнена — повтор не раньше чем через 15 минут', $t1 < 0.05, round($t1, 3) . ' с');
set_setting('remnawave_url', $panel);

// Свой период: «с начала хранения» и «по сейчас» попадают в кэш, хотя секунды границ сдвигаются
$tt_pdo->exec("DELETE FROM admin_cache WHERE k = 'cmap_c'");
$pc = cmap_period('c', $now - 60 * 86400, $now + 86400);
t_eq('свой период до сегодня от начала хранения — метки в ключе', $pc['id'], 'min-now');
$c1 = cmap_view($pc, $stale);
$c2 = cmap_view(cmap_period('c', $now - 60 * 86400, $now + 86400, time() + 3), $stale);
t_eq('повтор того же своего периода через секунды — из кэша', [$stale, $c2['ts']], [false, $c1['ts']]);
$c3 = cmap_view(cmap_period('c', $now - 3 * 86400, $now - 86400), $stale);
t_eq('другой свой период — считается заново', [$c3['id'], (int) $c3['from']], [($now - 3 * 86400) . '-' . ($now - 86400), $now - 3 * 86400]);

// Крупная сводка хранится сжатой и читается обратно
$big = ['ts' => time(), 'x' => str_repeat('абвгд', 200000)];
cmap_cache_put('cmap_test', $big);
$raw = json_decode((string) $tt_pdo->query("SELECT v FROM admin_cache WHERE k = 'cmap_test'")->fetchColumn(), true);
t_ok('крупная сводка в кэше — сжатой', isset($raw['z']) && strlen((string) $raw['z']) < 200000);
t_eq('сжатая сводка читается как была', cmap_cache_get('cmap_test'), $big);
$tt_pdo->exec("DELETE FROM admin_cache WHERE k = 'cmap_test'");

// Подробности клиента
$u2 = cmap_user(2, cmap_period('7'));
t_eq('клиент: все его сессии за период', count($u2['rows']), 2);
t_eq('клиент: строки — нода, IP, страна, AS, провайдер, регион, начало, конец', array_slice($u2['rows'][0], 0, 6), [$N2, '3.3.3.3', 'RU', 12389, 'Rostelecom', 'Sverdlovsk Oblast']);
t_eq('клиент: справочник нод', ($u2['nodes']->{$N1}[2] ?? ''), 'Нидерланды');

// Админка
$mw = t_serve($tt_root, $tt_root . '/tools/tests/_router.php');
$jar = t_admin_login($mw);
$b0 = $usersCalls();
[$code, , $page] = t_http('GET', $mw . '/admin/index.php?tab=users&view=map', [], null, $jar);
t_eq('вкладка карты открывается', $code, 200);
t_ok('сводка встроена в страницу', strpos($page, 'window.CMAP_CFG') !== false && strpos($page, 'assets/cis.js?v=') !== false);
t_ok('сегмент «Список / Карта подключений»', strpos($page, 'view=map">Карта подключений</a>') !== false);
t_eq('карта не тянет полный список пользователей из панели', $usersCalls() - $b0, 0);
[$code, , $j] = t_http('GET', $mw . '/admin/index.php?ajax=cmap&p=7', [], null, $jar);
$j = json_decode($j, true);
t_eq('ajax: сводка за 7 дней', [$code, $j['ok'] ?? null, $j['p'] ?? null, count($j['U'] ?? [])], [200, true, '7', 3]);
[, , $j] = t_http('GET', $mw . '/admin/index.php?ajax=cmap_user&id=1&p=1', [], null, $jar);
$j = json_decode($j, true);
t_eq('ajax: подключения клиента', [$j['ok'] ?? null, count($j['rows'] ?? [])], [true, 4]);
[, , $j] = t_http('GET', $mw . '/admin/index.php?ajax=cmap&p=c&f=' . ($now + 100) . '&t=' . ($now + 50), [], null, $jar);
t_eq('ajax: неверный период', json_decode($j, true)['ok'] ?? null, false);
[$code, , $j] = t_http('GET', $mw . '/admin/index.php?ajax=cmap&p=1');
t_ok('ajax без входа — не отдаёт сводку', strpos($j, '"U"') === false);
[$code, , $list] = t_http('GET', $mw . '/admin/index.php?tab=users', [], null, $jar);
t_ok('список пользователей по-прежнему открывается и ведёт на карту', $code === 200 && strpos($list, 'view=map">Карта подключений</a>') !== false && strpos($list, 'id="ux"') !== false);

set_setting('sess_enabled', '0');
[, , $page] = t_http('GET', $mw . '/admin/index.php?tab=users&view=map', [], null, $jar);
t_ok('сбор выключен, история есть — карта с пометкой', strpos($page, 'новых данных нет') !== false && strpos($page, 'window.CMAP_CFG') !== false);
$tt_pdo->exec('DELETE FROM conn_sess');
$tt_pdo->exec("DELETE FROM admin_cache WHERE k LIKE 'cmap%'");
[, , $page] = t_http('GET', $mw . '/admin/index.php?tab=users&view=map', [], null, $jar);
t_ok('сбор выключен и истории нет — подсказка включить', strpos($page, 'включите сбор') !== false && strpos($page, 'window.CMAP_CFG') === false);
$tt_pdo->exec('DROP TABLE conn_sess');
$tt_pdo->exec("DELETE FROM admin_cache WHERE k LIKE 'cmap%'");
$e0 = cmap_build(cmap_period('1'));
t_eq('сессии никогда не включали — карта пустая и таблицу не создаёт', [count($e0['U']), db_table_columns($tt_pdo, db_driver(), 'conn_sess')], [0, []]);

t_done();
