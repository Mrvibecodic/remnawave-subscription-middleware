<?php
declare(strict_types=1);

require __DIR__ . '/_boot.php';

$mst  = $tt_root . '/data/mock_ipinfo.json';
$mlog = $tt_root . '/data/mock_ipinfo.log';
$mock = static function (array $st) use ($mst) { file_put_contents($mst, json_encode($st)); };
$mock(['mode' => 'ok']);
$srv = t_serve($tt_root . '/tools', $tt_root . '/tools/tests/_mock_ipinfo.php', ['MOCK_IPINFO' => $mst, 'MOCK_IPINFO_LOG' => $mlog]);
putenv('SUBMW_TEST_IPINFO=' . $srv . '/');
$reqs = static fn() => count(array_filter(explode("\n", (string) @file_get_contents($mlog))));
$net = static function (string $k) use ($tt_pdo) { $s = $tt_pdo->prepare('SELECT * FROM geo_net WHERE k = ?'); $s->execute([$k]); return $s->fetch(PDO::FETCH_ASSOC) ?: null; };
$fresh = static function () { geoip_reset(); unset($GLOBALS['submw_settings_cache']); };
$step = static fn(float $sec = 8.0, int $max = 120) => geonet_step(microtime(true) + $sec, $max);
// Перепроверка «после обновления» проверяется в конце отдельно.
t_settings(['geonet_init' => '1']);

// Ключи подсетей
t_eq('IPv4 — /24', geonet_key('1.2.3.4'), 'v4:1.2.3');
t_eq('IPv6 — /48, адреса одной /48 дают один ключ', [geonet_key('2a02:6b8:a::1'), geonet_key('2a02:06b8:000a:ffff::2')], ['v6:2a0206b8000a', 'v6:2a0206b8000a']);
t_eq('IPv4 внутри IPv6 — как IPv4', geonet_key('::ffff:1.2.3.9'), 'v4:1.2.3');
t_eq('частные, служебные и мусор — без ключа', [geonet_key('10.1.2.3'), geonet_key('127.0.0.1'), geonet_key('192.168.0.1'), geonet_key('fd00::1'), geonet_key('x'), geonet_key('')], ['', '', '', '', '', '']);
t_eq('шаблон LIKE подсети', [geonet_like('v4:1.2.3'), geonet_like('v6:2a0206b8000a')], ['1.2.3.%', '2a02:%']);

// По умолчанию — ipinfo; неизвестная подсеть встаёт в очередь, поиск ничего не ждёт
t_eq('источник по умолчанию — ipinfo', geoip_mode(), 'ipinfo');
t_ok('в ipinfo регионы считаются доступными', geoip_city_ready() && geoip_geo_ready());
$t0 = microtime(true);
$g = geoip_lookup('1.2.3.4');
t_ok('поиск неизвестного адреса не ходит наружу', microtime(true) - $t0 < 0.5 && $reqs() === 0);
t_eq('неизвестная подсеть — пусто и ждёт', [$g['cc'], $g['sub'], $g['wait']], ['', '', true]);
$r = $net('v4:1.2.3');
t_eq('подсеть в очереди статистики', [(int) $r['want'], (int) $r['prio'], (int) $r['ts'], $r['ip']], [1, 2, 0, '1.2.3.4']);
$fresh();
geoip_lookup('1.2.3.77', 1);
t_eq('адрес той же /24 из сессий — та же строка, приоритет поднят', [(int) $tt_pdo->query('SELECT COUNT(*) FROM geo_net')->fetchColumn(), (int) $net('v4:1.2.3')['prio']], [1, 1]);
t_eq('частный адрес в очередь не попадает', [geoip_lookup('10.0.0.5')['wait'], (int) $tt_pdo->query('SELECT COUNT(*) FROM geo_net')->fetchColumn()], [false, 1]);

// Уже записанные сессии и отчёты получают гео после ответа
sess_ensure();
rep_ensure();
$now = time();
$ins = $tt_pdo->prepare('INSERT INTO conn_sess (uid, node, ip, cc, asn, org, sub, start_ts, last_ts, n) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)');
$ins->execute([1, 'n1', '1.2.3.4', '', 0, '', '', $now - 600, $now]);
$ins->execute([1, 'n1', '1.2.3.77', 'NL', 1136, 'KPN', 'Holland', $now - 600, $now]);
$ins->execute([2, 'n1', '1.2.30.4', '', 0, '', '', $now - 600, $now]);
$tt_pdo->prepare('INSERT INTO rep_net_ip (short_uuid, hwid, net, ip, last_h) VALUES (?, ?, ?, ?, ?)')->execute(['s1', 'h1', 'n', '1.2.3.4', $now]);
$tt_pdo->prepare('INSERT INTO rep_state (short_uuid, hwid, net, nkey, ip4, last_seen) VALUES (?, ?, ?, ?, ?, ?)')->execute(['s1', 'h1', 'n', 'k', '1.2.3.5', $now]);
t_eq('пока ждёт ipinfo — отчёт не закрывается заглушкой «-»', [rep_geo_backfill_ips(0), $tt_pdo->query("SELECT loc FROM rep_net_ip WHERE ip = '1.2.3.4'")->fetchColumn()], [0, '']);
$r = $step();
t_eq('шаг: один запрос на подсеть', [$r['n'], $r['ok'], $r['err'], $reqs()], [1, 1, '', 1]);
$q = $tt_pdo->query("SELECT ip, cc, asn, org, sub FROM conn_sess WHERE uid = 1 ORDER BY ip")->fetchAll(PDO::FETCH_ASSOC);
t_eq('сессии подсети обновлены, в т.ч. со старым гео', $q, [
    ['ip' => '1.2.3.4', 'cc' => 'RU', 'asn' => 8359, 'org' => 'MTS PJSC', 'sub' => 'Moscow'],
    ['ip' => '1.2.3.77', 'cc' => 'RU', 'asn' => 8359, 'org' => 'MTS PJSC', 'sub' => 'Moscow'],
]);
t_eq('соседняя /24 не тронута', $tt_pdo->query("SELECT cc FROM conn_sess WHERE uid = 2")->fetchColumn(), '');
t_eq('адреса отчётов — регион и координаты', $tt_pdo->query("SELECT cc, loc, sub FROM rep_net_ip WHERE ip = '1.2.3.4'")->fetch(PDO::FETCH_NUM), ['RU', '55.75,37.62', 'Moscow']);
t_eq('состояние устройства по IPv4', $tt_pdo->query("SELECT cc, asn, sub FROM rep_state")->fetch(PDO::FETCH_NUM), ['RU', 8359, 'Moscow']);
$fresh();
$g = geoip_lookup('1.2.3.200');
t_eq('из кэша — страна, регион, город, провайдер, без запроса', [$g['cc'], $g['sub'], $g['city'], $g['asn'], $g['org'], $g['wait'], $reqs()], ['RU', 'Moscow', 'Moscow', 8359, 'MTS PJSC', false, 1]);
t_eq('город подсети для карточки', geonet_cities(['1.2.3.4', '8.8.8.8']), ['1.2.3.4' => 'Moscow']);
$st = ipinfo_stat();
t_eq('счётчик суток', [$st['n'], $st['ok'], $st['fail'], $st['lim']], [1, 1, 0, 0]);

// Через месяц подсеть, которая снова попалась, уточняется заново; данные до ответа — прежние
$tt_pdo->exec("UPDATE geo_net SET ts = " . ($now - 31 * 86400) . " WHERE k = 'v4:1.2.3'");
$fresh();
$g = geoip_lookup('1.2.3.4', 1);
t_eq('устаревшая подсеть снова в очереди, старые данные отдаются', [(int) $net('v4:1.2.3')['want'], $g['sub'], $g['wait']], [1, 'Moscow', false]);
$mock(['mode' => 'ok', 'map' => ['1.2.3.4' => ['city' => 'Zelenograd', 'region' => 'Moscow Oblast', 'country' => 'RU', 'loc' => '55.98,37.18', 'org' => 'AS8359 MTS PJSC']]]);
$step();
t_eq('после переуточнения — новый регион в сессиях', $tt_pdo->query("SELECT DISTINCT sub FROM conn_sess WHERE uid = 1")->fetchAll(PDO::FETCH_COLUMN), ['Moscow Oblast']);

// Суточный лимит
$mock(['mode' => 'ok']);
t_settings(['ipinfo_cap' => '12']);
for ($i = 1; $i <= 15; $i++) { $fresh(); geoip_lookup("5.$i.1.1"); }
$before = $reqs();
$r = $step(10.0);
t_eq('лимит суток: запросов не больше лимита', [$r['pause'], ipinfo_stat()['n'], $reqs() - $before], ['cap', 12, 10]);
t_eq('остаток ждёт следующих суток', (int) $tt_pdo->query('SELECT COUNT(*) FROM geo_net WHERE want = 1')->fetchColumn(), 5);
$v = geonet_view();
t_eq('вкладка: использовано / лимит / очередь', [$v['used'], $v['cap'], $v['q']['2']], [12, 12, 5]);
t_ok('сброс счётчика — до полуночи UTC', $v['reset'] > 0 && $v['reset'] <= 86400);
t_settings(['ipinfo_cap' => '900']);

// Отказ по лимиту — пауза, без новых запросов
$mock(['mode' => '429']);
$r = $step();
$st = ipinfo_stat();
t_eq('429 — пауза на час', [$r['pause'], $st['lim'], $st['block'] > time() + 3000], ['block', 1, true]);
$before = $reqs();
$step();
t_eq('во время паузы наружу не ходим', $reqs() - $before, 0);
t_eq('очередь при 429 не теряется', (int) $tt_pdo->query('SELECT COUNT(*) FROM geo_net WHERE want = 1')->fetchColumn(), 5);

// ipinfo лежит целиком — пауза 10 минут, адреса не штрафуются
$st['block'] = 0; ipinfo_stat_put($st);
$mock(['mode' => '500']);
$r = $step();
t_ok('5xx на всю пачку — ошибка и пауза', $r['err'] !== '' && $r['pause'] === 'block' && ipinfo_stat()['block'] <= time() + 600);
t_eq('штрафов адресам нет', (int) $tt_pdo->query('SELECT MAX(fails) FROM geo_net')->fetchColumn(), 0);

// Один адрес ipinfo не отдаёт при живом сервисе — после трёх попыток откладывается
$st = ipinfo_stat(); $st['block'] = 0; ipinfo_stat_put($st);
$mock(['mode' => 'ok', 'fail' => ['5.1.1.1' => '500']]);
$step(); $step(); $step();
$r = $net('v4:5.1.1');
t_eq('трижды без ответа — отложен на месяц', [(int) $r['want'], (int) $r['ts'] > 0, (int) $r['fails']], [0, true, 0]);
t_eq('остальные разобраны', (int) $tt_pdo->query('SELECT COUNT(*) FROM geo_net WHERE want = 1')->fetchColumn(), 0);

// bogon и токен
$mock(['mode' => 'ok', 'map' => ['100.64.1.1' => ['bogon' => true]]]);
$fresh(); geoip_lookup('100.64.1.1');
$step();
t_eq('bogon — без гео и без повторов', [(int) $net('v4:100.64.1')['want'], $net('v4:100.64.1')['cc']], [0, '']);
t_settings(['ipinfo_token' => 'abc123token']);
$fresh(); geoip_lookup('7.7.7.7');
$step();
t_ok('токен уходит в запросе', strpos((string) file_get_contents($mlog), "7.7.7.7 abc123token") !== false);
$mock(['mode' => '403']);
$fresh(); geoip_lookup('7.7.8.1');
$st = ipinfo_stat(); $st['block'] = 0; ipinfo_stat_put($st);
$step();
t_eq('неверный токен — пауза и понятная ошибка', [ipinfo_stat()['last_err'], ipinfo_stat()['block'] > time()], ['ipinfo не принял токен', true]);
$st = ipinfo_stat(); $st['block'] = 0; ipinfo_stat_put($st);
$lite = [];
for ($i = 1; $i <= 6; $i++) { $lite["6.$i.1.1"] = ['country' => 'RU', 'org' => 'AS8359 MTS PJSC']; $fresh(); geoip_lookup("6.$i.1.1"); }
$mock(['mode' => 'ok', 'map' => $lite]);
$step();
$st = ipinfo_stat();
t_eq('токен без регионов: пауза и подсказка удалить токен', [$st['last_err'], $st['block'] > time(), (int) $tt_pdo->query("SELECT COUNT(*) FROM geo_net WHERE k LIKE 'v4:6.%' AND ts > 0")->fetchColumn()], ['с этим токеном ipinfo не отдаёт регион и город — удалите токен', true, 0]);
t_eq('ответы без регионов не сохранены — подсети ждут', (int) $tt_pdo->query("SELECT COUNT(*) FROM geo_net WHERE k LIKE 'v4:6.%' AND want = 1")->fetchColumn(), 6);
t_settings(['ipinfo_token' => '']);
$st = ipinfo_stat(); $st['block'] = 0; $st['noreg'] = 0; ipinfo_stat_put($st);
$mock(['mode' => 'ok']);
$step();
t_eq('без токена те же подсети получают регион', (int) $tt_pdo->query("SELECT COUNT(*) FROM geo_net WHERE k LIKE 'v4:6.%' AND sub <> ''")->fetchColumn(), 6);

// Перепроверка всех IP с ходом
$mock(['mode' => 'ok']);
$step();
$n = geonet_recheck_start();
$v = geonet_view();
t_ok('перепроверка: подсети из сессий, отчётов и кэша', $n >= 4 && $v['rc']['total'] === $n && $v['rc']['left'] === $n && $v['rc']['done'] === 0);
t_eq('перепроверка — в своей очереди', $v['q']['3'] >= 1, true);
$step(10.0, 2);
$v = geonet_view();
t_eq('ход перепроверки', [$v['rc']['done'], $v['rc']['left']], [2, $n - 2]);
geonet_recheck_stop();
$v = geonet_view();
t_eq('остановка: очередь перепроверки снята, отчёт о ходе убран', [$v['rc'], (int) $tt_pdo->query('SELECT COUNT(*) FROM geo_net WHERE rq = 1')->fetchColumn(), $v['q']['3']], [null, 0, 0]);
geonet_recheck_start();
$step(15.0);
$v = geonet_view();
t_eq('перепроверка до конца', [$v['rc']['left'], $v['rc']['done'] === $v['rc']['total'], $v['rc']['end_ago'] >= 0], [0, true, true]);

// Первый запуск после обновления ставит перепроверку сам
admin_cache_put('geonet_rc', []);
t_settings(['geonet_init' => '']);
$step(10.0, 0);
t_ok('первый шаг после обновления запускает перепроверку', is_array(geonet_view()['rc']) && setting('geonet_init', '') === '1');

// Планировщик и «База данных»
t_ok('задача планировщика есть в режиме ipinfo', isset(cron_tasks()['geo']));
t_ok('кэш подсетей — в «Базе данных»', isset(gc_tables()['geo_net']));

// Локальные базы — старое поведение, geo_net не трогается
t_settings(['geoip_mode' => 'local']);
$fresh();
$c = (int) $tt_pdo->query('SELECT COUNT(*) FROM geo_net')->fetchColumn();
$g = geoip_lookup('9.9.9.9');
t_eq('локальный режим: без баз — пусто, без очереди', [$g['cc'], $g['wait'], (int) $tt_pdo->query('SELECT COUNT(*) FROM geo_net')->fetchColumn()], ['', false, $c]);
t_ok('локальный режим: задачи ipinfo нет', !isset(cron_tasks()['geo']));
t_eq('локальный режим: шаг ipinfo ничего не делает', geonet_step(microtime(true) + 5)['n'], 0);

// Вкладка «Геобазы»: счётчик, ход перепроверки на лету, кнопки, переключение источника, токен
t_settings(['geoip_mode' => 'ipinfo']);
set_setting('update_state', json_encode(['checked_at' => time()]));
$mw  = t_serve($tt_root, $tt_root . '/tools/tests/_router.php', [], ['opcache.enable' => '0']);
$jar = t_admin_login($mw);
[$c, , $page] = t_http('GET', $mw . '/admin/index.php?tab=geoip', [], null, $jar);
t_ok('вкладка в режиме ipinfo: счётчик и перепроверка', $c === 200 && strpos($page, 'id="giLive"') !== false && strpos($page, 'Запросов в сутки') !== false && strpos($page, 'Скачать базы сейчас') === false);
preg_match('~name="csrf" value="([^"]+)"~', $page, $m);
$csrf = $m[1] ?? '';
$post = static fn(array $f, string $q = '') => t_http('POST', $mw . '/admin/index.php' . $q, [], http_build_query($f), $jar);
[$c, , $b] = $post(['csrf' => $csrf], '?ajax=geo_stat');
$j = json_decode($b, true);
t_ok('опрос хода отдаёт счётчик и очередь', $c === 200 && isset($j['v']['used'], $j['v']['cap'], $j['v']['q']), $b);
[$c] = $post(['csrf' => 'bad'], '?ajax=geo_stat');
t_eq('опрос без CSRF — отказ', $c, 400);
[, , $b] = $post(['csrf' => $csrf, 'action' => 'geo_recheck', 'xhr' => '1']);
t_ok('«Перепроверить все IP» ставит очередь', strpos($b, 'Перепроверка запущена') !== false, $b);
[, , $b] = $post(['csrf' => $csrf], '?ajax=geo_stat');
$j = json_decode($b, true);
t_ok('после запуска виден ход', is_array($j['v']['rc'] ?? null) && $j['v']['rc']['total'] > 0, $b);
[, , $b] = $post(['csrf' => $csrf, 'action' => 'geo_recheck_stop', 'xhr' => '1']);
t_ok('«Остановить»', strpos($b, 'остановлена') !== false && (int) $tt_pdo->query('SELECT COUNT(*) FROM geo_net WHERE rq = 1')->fetchColumn() === 0, $b);
$post(['csrf' => $csrf, 'action' => 'save_ipinfo_token', 'ipinfo_token' => 'bad token!', 'xhr' => '1']);
unset($GLOBALS['submw_settings_cache']);
t_eq('мусорный токен не сохраняется', ipinfo_token(), '');
$post(['csrf' => $csrf, 'action' => 'save_ipinfo_token', 'ipinfo_token' => 'goodtoken42', 'xhr' => '1']);
unset($GLOBALS['submw_settings_cache']);
[, , $page] = t_http('GET', $mw . '/admin/index.php?tab=geoip', [], null, $jar);
t_ok('токен сохранён и на странице не показывается', ipinfo_token() === 'goodtoken42' && strpos($page, 'goodtoken42') === false && strpos($page, 'Удалить токен') !== false);
$post(['csrf' => $csrf, 'action' => 'save_ipinfo_token', 'del' => '1', 'xhr' => '1']);
$post(['csrf' => $csrf, 'action' => 'save_geoip_mode', 'geoip_mode' => 'ipinfo', 'ipinfo_cap' => '50000', 'xhr' => '1']);
unset($GLOBALS['submw_settings_cache']);
t_eq('без токена лимит не выше 1000', [ipinfo_token(), ipinfo_cap()], ['', 1000]);
$post(['csrf' => $csrf, 'action' => 'save_geoip_mode', 'geoip_mode' => 'local', 'xhr' => '1']);
unset($GLOBALS['submw_settings_cache']);
[, , $page] = t_http('GET', $mw . '/admin/index.php?tab=geoip', [], null, $jar);
t_ok('переключение на локальные базы', geoip_mode() === 'local' && strpos($page, 'Скачать базы сейчас') !== false && strpos($page, 'id="giLive"') === false);
foreach (['users', 'reports'] as $tb) {
    [$c, , $page] = t_http('GET', $mw . '/admin/index.php?tab=' . $tb, [], null, $jar);
    t_ok('вкладка ' . $tb . ' в локальном режиме', $c === 200 && strpos($page, '</html>') !== false);
}
t_settings(['geoip_mode' => 'ipinfo']);
foreach (['users', 'reports'] as $tb) {
    [$c, , $page] = t_http('GET', $mw . '/admin/index.php?tab=' . $tb, [], null, $jar);
    t_ok('вкладка ' . $tb . ' в режиме ipinfo', $c === 200 && strpos($page, '</html>') !== false);
}

t_done();
