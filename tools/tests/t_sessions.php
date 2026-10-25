<?php
declare(strict_types=1);

require __DIR__ . '/_boot.php';

$state = $tt_root . '/data/mock_state.json';
$mlog  = $tt_root . '/data/mock.log';
$N1 = '11111111-1111-4111-8111-111111111111';
$N2 = '22222222-2222-4222-8222-222222222222';
$mock = static function (array $conns, array $extra = []) use ($state, $N1, $N2) {
    file_put_contents($state, json_encode([
        'users' => ['AbCdEfGhIjKlMnOp' => ['status' => 'ACTIVE'], 'SecondUserShort1' => ['status' => 'ACTIVE']],
        'nodes' => [$N1 => ['name' => 'Amsterdam', 'cc' => 'NL'], $N2 => ['name' => 'Frankfurt', 'cc' => 'DE']],
        'hosts' => [['remark' => '🇳🇱 Нидерланды', 'address' => 'ams.example.net', 'nodes' => [$N1]], ['remark' => 'NL · WS', 'address' => 'ams.example.net', 'nodes' => [$N1]], ['remark' => 'Выключенный', 'address' => 'x', 'nodes' => [$N1], 'off' => 1], ['remark' => 'Германия', 'address' => 'fra.example.net', 'nodes' => []]],
        'conns' => $conns,
    ] + $extra));
};
$mock([]);
$panel = t_serve($tt_root . '/tools', $tt_root . '/tools/mock_panel.php', ['MOCK_STATE' => $state, 'MOCK_LOG' => $mlog]);
t_settings(['sub_source' => 'panel', 'remnawave_url' => $panel, 'remnawave_api_key' => 'test-token', 'geoip_auto' => '0']);
set_setting('update_state', json_encode(['checked_at' => time()]));
$mw = t_serve($tt_root, $tt_root . '/tools/tests/_router.php');

$count = static fn(string $where = '1=1', array $a = []) => (int) (static function () use ($tt_pdo, $where, $a) { $s = $tt_pdo->prepare("SELECT COUNT(*) FROM conn_sess WHERE $where"); $s->execute($a); return $s->fetchColumn(); })();
$row = static function (int $uid, string $node, string $ip) use ($tt_pdo) { $s = $tt_pdo->prepare('SELECT * FROM conn_sess WHERE uid = ? AND node = ? AND ip = ? ORDER BY id DESC'); $s->execute([$uid, $node, $ip]); return $s->fetchAll(PDO::FETCH_ASSOC); };
$logHas = static fn(string $needle) => strpos((string) @file_get_contents($mlog), $needle) !== false;

// Выключено — задача молчит и ничего не трогает
t_eq('по умолчанию сбор выключен', sess_enabled(), false);
t_eq('выключенная задача планировщика — без ошибки', cron_task_sessions(['deadline' => microtime(true) + 10, 'detached' => true, 'can' => true]), '');
t_ok('таблица сессий есть у «Базы данных»', isset(gc_tables()['conn_sess']));

// Снимок → сессии
t_settings(['sess_enabled' => '1', 'sess_every' => '120']);
$mock([$N1 => ['1' => ['1.2.3.4']], $N2 => ['1' => ['1.2.3.4'], '2' => ['5.6.7.8', '9.9.9.9']]]);
t_eq('первый опрос без ошибок', sess_collect(microtime(true) + 10), '');
t_eq('снимок разложен по сессиям: пользователь × нода × IP', $count(), 4);
$st = sess_state();
t_eq('состояние опроса: ноды и подключения', [$st['nodes'], $st['ok'], $st['rows'], $st['fail']], [2, 2, 4, []]);
t_eq('имена нод запомнены', sess_nodes_known()[$N1]['n'] ?? '', 'Amsterdam');
t_eq('хосты ноды: по явной привязке, выключенный хост пропущен', sess_nodes_known()[$N1]['h'] ?? null, ['🇳🇱 Нидерланды', 'NL · WS']);
t_eq('хост без привязки к ноде — к ноде не относится', sess_nodes_known()[$N2]['h'] ?? null, []);
t_ok('панель 2.x — адреса ip-control', $logHas('POST /api/ip-control/fetch-users-ips/' . $N1) && !$logHas('/api/connections/'));

sleep(1);
sess_collect(microtime(true) + 10);
$r = $row(1, $N1, '1.2.3.4');
t_eq('повторный снимок продлевает ту же сессию', [count($r), (int) $r[0]['n']], [1, 2]);
t_ok('время последнего снимка сдвинулось', (int) $r[0]['last_ts'] > (int) $r[0]['start_ts']);

$mock([$N1 => ['1' => ['1.2.3.5']], $N2 => ['1' => ['1.2.3.4'], '2' => ['5.6.7.8', '9.9.9.9']]]);
sess_collect(microtime(true) + 10);
t_eq('новый IP — новая сессия, старая осталась', [$count(), count($row(1, $N1, '1.2.3.5'))], [5, 1]);

$tt_pdo->exec('UPDATE conn_sess SET last_ts = last_ts - ' . (sess_gap() + 30) . ', start_ts = start_ts - ' . (sess_gap() + 30));
sess_collect(microtime(true) + 10);
t_eq('после паузы длиннее двух интервалов открывается новая сессия', count($row(1, $N1, '1.2.3.5')), 2);

// Метрики для аномалий
$m = sess_metrics([1, 2]);
t_eq('метрики: у второго пик 2 IP одновременно, 2 провайдера (ASN нет — по сети), 2 разных IP', [$m[2]['pi'], $m[2]['da'], $m[2]['iw']], [2, 2, 2]);
t_eq('метрики: первый на двух нодах с двух IP одной сети', [$m[1]['pi'], $m[1]['iw'], $m[1]['da']], [2, 2, 1]);
t_ok('метрики всех пользователей', count(sess_metrics()) === 2);
$tn = time(); $ev = sess_every();
$ins = $tt_pdo->prepare('INSERT INTO conn_sess (uid, node, ip, cc, asn, org, sub, start_ts, last_ts, n) VALUES (3, ?, ?, ?, 0, \'\', \'\', ?, ?, 1)');
$ins->execute([$N1, '10.0.0.1', 'NL', $tn - 600, $tn - 480]);
$ins->execute([$N1, '10.0.0.2', 'NL', $tn - 480 + $ev, $tn - 100]);
$m3 = sess_metrics([3]);
t_eq('смена IP между опросами — не одновременность: пик 1 при 2 разных IP', [$m3[3]['pi'], $m3[3]['iw'], $m3[3]['pc']], [1, 2, 1]);
$ins->execute([$N2, '10.0.0.3', 'DE', $tn - 200, $tn - 100]);
$m3 = sess_metrics([3]);
t_eq('пересечение во времени — пик 2 IP и 2 страны', [$m3[3]['pi'], $m3[3]['pc']], [2, 2]);
$tt_pdo->exec('UPDATE conn_sess SET start_ts = start_ts - 2 * 86400, last_ts = last_ts - 2 * 86400 WHERE uid = 3');
t_eq('окно аномалий: за сутки пользователя нет, за 3 дня — есть', [isset(sess_metrics([3])[3]), sess_metrics([3], 3)[3]['pi'] ?? 0], [false, 2]);
$ins->execute([$N1, '10.0.0.9', 'RU', $tn - 100, $tn - 50]);
$tt_pdo->exec("UPDATE conn_sess SET org = 'OLD', asn = 1 WHERE uid = 3");
t_eq('пересчёт гео без баз ничего не трогает', [sess_regeo(), $tt_pdo->query("SELECT COUNT(*) FROM conn_sess WHERE uid = 3 AND org = 'OLD'")->fetchColumn()], [0, 4]);
$tt_pdo->exec('DELETE FROM conn_sess WHERE uid = 3');
$d = sess_user_data(2);
t_ok('данные карточки: история и имена нод', count($d['rows']) >= 2 && ($d['nodes'][$N2]['n'] ?? '') === 'Frankfurt' && $d['on'] === true);

// Живой опрос и сброс соединений
$e = '';
$live = sess_live('1', $e);
t_ok('живой опрос пользователя: две ноды, у первой имена хостов', is_array($live) && count($live) === 2 && $live[0]['name'] !== '' && count(array_filter($live, static fn($n) => $n['node'] === $N1 && $n['h'] === ['🇳🇱 Нидерланды', 'NL · WS'])) === 1, $e . ' ' . json_encode($live, JSON_UNESCAPED_UNICODE));
$ips = [];
foreach ($live ?: [] as $n) foreach ($n['ips'] as $x) $ips[] = $x['ip'];
sort($ips);
t_eq('живой опрос: адреса с обеих нод', $ips, ['1.2.3.4', '1.2.3.5']);
@unlink($mlog);
t_ok('сброс по IP принят', sess_drop('1', ['1.2.3.4'], $e), $e);
t_ok('сброс по IP: тело с адресом на все ноды', $logHas('"ipAddresses":["1.2.3.4"]') && $logHas('"target":"allNodes"'));
t_ok('сброс всех соединений пользователя (2.x — по uuid)', sess_drop('00000000-0000-4000-8000-000000000000', [], $e) && $logHas('"userUuids"'));
t_ok('мусорный IP отбрасывается, запрос уходит по пользователю', sess_drop('00000000-0000-4000-8000-000000000000', ['not-an-ip'], $e) && !$logHas('not-an-ip'));

// Панель 3.x — другое семейство адресов и числовой id
$mock([$N1 => ['1' => ['1.2.3.5']]], ['version' => '3.4.7']);
unset($GLOBALS['submw_sess_v3']);
remnawave_panel_meta(0);
t_eq('версия панели обновлена', panel_api_v3(), true);
@unlink($mlog);
t_eq('опрос на 3.x без ошибок', sess_collect(microtime(true) + 10), '');
t_ok('панель 3.x — адреса connections', $logHas('POST /api/connections/by-node/' . $N1) && !$logHas('ip-control'));
t_ok('сброс на 3.x — по числовому id', sess_drop('1', [], $e) && $logHas('"userIds":[1]'));
t_eq('права токена для 3.x', sess_scopes()['node'], 'connections:by-node');
$cat = array_column(tokscope_catalog(), 's');
t_ok('каталог прав содержит права обозревателя сессий', in_array('connections:by-node', $cat, true) && in_array('connections:by-node-result', $cat, true) && in_array('connections:drop', $cat, true));

// Нода не отвечает: частичный снимок помечен, остальные сессии продолжаются
$mock([$N1 => ['1' => ['1.2.3.5']], $N2 => ['2' => ['5.6.7.8']]], ['version' => '3.4.7', 'job_slow' => 1]);
$err = sess_collect(microtime(true) + 1.5);
$st = sess_state();
t_ok('ни одна нода не успела — ошибка и список не ответивших', $err !== '' && $st['ok'] === 0 && count($st['fail']) === 2, $err);
$mock([$N1 => ['1' => ['1.2.3.5']], $N2 => ['2' => ['5.6.7.8']]], ['version' => '3.4.7']);
$s = json_decode((string) file_get_contents($state), true);
$s['nodes'][$N2]['off'] = true;
file_put_contents($state, json_encode($s));
t_eq('выключенная нода не опрашивается', sess_collect(microtime(true) + 10), '');
t_eq('выключенная нода: опрошена одна', sess_state()['nodes'], 1);

// Задача планировщика и чистка по сроку
$mock([$N1 => ['1' => ['1.2.3.5']]], ['version' => '3.4.7']);
set_setting('cron_state', '');
$r = cron_run('cli');
t_ok('планировщик выполняет задачу сессий', isset($r['ran']['sessions']) && $r['ran']['sessions']['ok'] === true, json_encode($r, JSON_UNESCAPED_UNICODE));
t_eq('интервал задачи — из настроек', cron_tasks()['sessions'][1], 120);
$old = time() - 40 * 86400;
$tt_pdo->prepare('INSERT INTO conn_sess (uid, node, ip, start_ts, last_ts, n) VALUES (7, ?, ?, ?, ?, 1)')->execute([$N1, '8.8.8.8', $old - 600, $old]);
set_setting('sess_purge_ts', '0');
sess_purge_maybe();
t_eq('сессии старше срока хранения удалены', $count('uid = 7'), 0);
t_ok('свежие сессии остались', $count() > 0);

// Админка: вкладка, данные карточки, настройки
$jar = t_admin_login($mw);
[$c, , $page] = t_http('GET', $mw . '/admin/index.php?tab=users', [], null, $jar);
t_ok('вкладка «Пользователи» с блоком сессий', $c === 200 && strpos($page, 'Сессии и аномалии') !== false && strpos($page, 'id="uxAn"') !== false);
t_ok('метрики переданы в список', (bool) preg_match('~"sx":\{"1":\{"pi":~', $page));
[$c, , $b] = t_http('GET', $mw . '/admin/index.php?ajax=sess_user&id=1', [], null, $jar);
$j = json_decode($b, true);
t_ok('ajax sess_user: история и метрики', $c === 200 && !empty($j['ok']) && count($j['rows']) >= 1 && is_array($j['m']));
[$c, , $b] = t_http('GET', $mw . '/admin/index.php?ajax=sess_live&rv=1', [], null, $jar);
$j = json_decode($b, true);
t_ok('ajax sess_live: ноды с адресами', $c === 200 && !empty($j['ok']) && count($j['nodes']) === 1 && $j['nodes'][0]['ips'][0]['ip'] === '1.2.3.5');
preg_match('~name="csrf" value="([0-9a-f]+)"~', $page, $cm);
[$c, , $b] = t_http('POST', $mw . '/admin/index.php', ['content-type' => 'application/x-www-form-urlencoded'], http_build_query(['csrf' => $cm[1], 'action' => 'save_sess', 'sess_enabled' => '1', 'sess_every' => '300', 'sess_keep_days' => '3', 'sess_nolim' => '5', 'sess_asn_extra' => '2', 'sess_ip_per' => '0', 'xhr' => '1']), $jar);
unset($GLOBALS['submw_settings_cache']);
t_ok('настройки сохраняются с проверкой границ', $c === 200 && sess_every() === 300 && sess_keep_days() === 7 && sess_nolim() === 5 && sess_asn_extra() === 2 && sess_ip_per() === 1, $b);
[$c, , $b] = t_http('POST', $mw . '/admin/index.php', ['content-type' => 'application/x-www-form-urlencoded'], http_build_query(['csrf' => $cm[1], 'action' => 'save_sess', 'sess_every' => '999', 'sess_keep_days' => '30', 'sess_nolim' => '3', 'sess_asn_extra' => '1', 'xhr' => '1']), $jar);
unset($GLOBALS['submw_settings_cache']);
t_ok('выключение сбора: история остаётся, интервал вне списка — по умолчанию', !sess_enabled() && sess_every() === 120 && $count() > 0);
[$c, , $b] = t_http('POST', $mw . '/admin/index.php?ajax=sess_drop', ['content-type' => 'application/x-www-form-urlencoded'], http_build_query(['csrf' => $cm[1], 'rv' => '1', 'ip' => '1.2.3.5']), $jar);
$j = json_decode($b, true);
t_ok('ajax sess_drop принят', $c === 200 && !empty($j['ok']), $b);

t_done();
