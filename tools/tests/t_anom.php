<?php
declare(strict_types=1);

require __DIR__ . '/_boot.php';

$state = $tt_root . '/data/mock_state.json';
$N1 = '11111111-1111-4111-8111-111111111111';
$N2 = '22222222-2222-4222-8222-222222222222';
// id 1 — свой лимит 1; id 2 — «как в панели» (общий лимит 2); id 3 — без лимита (число из настроек).
$mock = static function (array $conns, array $extra = []) use ($state, $N1, $N2) {
    file_put_contents($state, json_encode([
        'users' => ['AbCdEfGhIjKlMnOp' => ['status' => 'ACTIVE', 'hw' => 1], 'SecondUserShort1' => ['status' => 'ACTIVE'], 'ThirdUserShort01' => ['status' => 'ACTIVE', 'hw' => 0]],
        'nodes' => [$N1 => ['name' => 'Amsterdam', 'cc' => 'NL'], $N2 => ['name' => 'Frankfurt', 'cc' => 'DE']],
        'hwid'  => ['enabled' => true, 'limit' => 2],
        'conns' => $conns,
    ] + $extra));
};
$mock([]);
$panel = t_serve($tt_root . '/tools', $tt_root . '/tools/mock_panel.php', ['MOCK_STATE' => $state]);
t_settings(['sub_source' => 'panel', 'remnawave_url' => $panel, 'remnawave_api_key' => 'test-token', 'geoip_auto' => '0']);
set_setting('update_state', json_encode(['checked_at' => time()]));
$mw = t_serve($tt_root, $tt_root . '/tools/tests/_router.php');

$rows = static fn(string $where = '1=1') => $tt_pdo->query("SELECT uid, gid, lvl, start_ts, last_ts, end_ts, why, ips FROM sess_anom WHERE $where ORDER BY uid, gid, id")->fetchAll(PDO::FETCH_ASSOC);
$fresh = static function () { unset($GLOBALS['submw_settings_cache'], $GLOBALS['submw_anom_rules']); };

// Чистые функции схемы
$v = sess_anom_vals([
    ['ip' => '1.1.1.1', 'node' => 'n', 'cc' => 'RU', 'asn' => 100, 'org' => 'A', 'sub' => 'Москва'],
    ['ip' => '1.1.1.2', 'node' => 'n', 'cc' => 'RU', 'asn' => 100, 'org' => 'A', 'sub' => 'Казань'],
    ['ip' => '2.2.2.2', 'node' => 'n', 'cc' => 'TR', 'asn' => 200, 'org' => 'B', 'sub' => ''],
    ['ip' => '3.3.3.3', 'node' => 'n', 'cc' => '', 'asn' => 0, 'org' => '', 'sub' => ''],
]);
t_eq('значения по снимку: IP, провайдеров (ASN или сеть), регионов у одного провайдера, стран', $v, ['ip' => 4, 'asn' => 3, 'sub' => 2, 'cc' => 2]);
$g = ['id' => 'g1', 'op' => 'or', 'c' => [['m' => 'ip', 'k' => 'mul', 'n' => 2], ['m' => 'asn', 'k' => 'add', 'n' => 1]]];
t_eq('ИЛИ при устройствах 2: IP 4 не больше 4, провайдеров 3 не больше 3 — не сработало', sess_anom_match($g, $v, 2), null);
t_eq('ИЛИ при устройствах 1: оба условия', sess_anom_match($g, $v, 1), [['ip', 4, 2], ['asn', 3, 2]]);
$g['op'] = 'and';
t_eq('И: одно из условий не выполнено — нет', sess_anom_match(['c' => [['m' => 'ip', 'k' => 'abs', 'n' => 3], ['m' => 'cc', 'k' => 'abs', 'n' => 5]]] + $g, $v, 1), null);
t_eq('И: все выполнены — список всех', sess_anom_match(['c' => [['m' => 'sub', 'k' => 'abs', 'n' => 1], ['m' => 'cc', 'k' => 'abs', 'n' => 1]]] + $g, $v, 1), [['sub', 2, 1], ['cc', 2, 1]]);
t_eq('нормализация: чужие метрики и пустые группы выбрасываются, границы режутся', sess_anom_norm([['name' => '  Моя  ', 'lvl' => 'x', 'op' => 'and', 'c' => [['m' => 'ip', 'k' => 'mul', 'n' => 99], ['m' => 'zzz', 'k' => 'abs', 'n' => 1]]], ['c' => []], 'junk']),
    [['id' => 'g1', 'name' => 'Моя', 'lvl' => 'bad', 'op' => 'and', 'c' => [['m' => 'ip', 'k' => 'mul', 'n' => 20]]]]);

// Умолчания: новая установка — пусто; со включённым сбором — перенос старых порогов
t_eq('сбор выключен — схема пустая', sess_anom_rules(), []);
$fresh();
$tt_pdo->exec("DELETE FROM settings WHERE k = 'sess_anom'");
t_settings(['sess_enabled' => '1', 'sess_every' => '120', 'sess_ip_per' => '3', 'sess_asn_extra' => '2']);
$r = sess_anom_rules();
t_eq('сбор уже включён — старые пороги одной красной группой и регионы жёлтой', [count($r), $r[0]['lvl'], $r[0]['op'], $r[0]['c'], $r[1]['lvl'], $r[1]['c']],
    [2, 'bad', 'or', [['m' => 'ip', 'k' => 'mul', 'n' => 3], ['m' => 'asn', 'k' => 'add', 'n' => 2], ['m' => 'cc', 'k' => 'abs', 'n' => 1]], 'warn', [['m' => 'sub', 'k' => 'abs', 'n' => 1]]]);

// Схема через форму: красная — IP больше устройств или провайдеров больше устройств; жёлтая — IP больше 2
$jar = t_admin_login($mw);
set_setting('cron_state', json_encode(['beat' => time(), 'src' => 'cli', 'tasks' => []]));
[, , $page] = t_http('GET', $mw . '/admin/index.php?tab=users', [], null, $jar);
t_ok('бейджи: «Сессии — собирается», «Аномалии — отслеживается»', strpos($page, 'Сессии <span class="tag normal">собирается</span>') !== false && strpos($page, 'Аномалии <span class="tag normal">отслеживается</span>') !== false);
preg_match('~name="csrf" value="([0-9a-f]+)"~', $page, $cm);
$scheme = json_encode([['name' => 'Лимит', 'lvl' => 'bad', 'op' => 'or', 'c' => [['m' => 'ip', 'k' => 'mul', 'n' => 1], ['m' => 'asn', 'k' => 'add', 'n' => 0]]], ['name' => 'Много IP', 'lvl' => 'warn', 'op' => 'and', 'c' => [['m' => 'ip', 'k' => 'abs', 'n' => 2]]]]);
$post = static function (array $kv) use ($mw, $jar, $cm) { return t_http('POST', $mw . '/admin/index.php', ['content-type' => 'application/x-www-form-urlencoded'], http_build_query(['csrf' => $cm[1], 'action' => 'save_anom', 'sess_nolim' => '3', 'xhr' => '1'] + $kv), $jar); };
[$c, , $b] = $post(['sess_anom' => $scheme, 'sess_anom_keep' => '99']);
$fresh();
t_ok('схема сохраняется формой, срок журнала режется до 30', $c === 200 && count(sess_anom_rules()) === 2 && sess_anom_rules()[0]['name'] === 'Лимит' && sess_anom_keep_days() === 30, $b);

// Снимок → события. ASN в тесте нет — провайдер считается сетью /24.
$mock([$N1 => ['1' => ['1.1.1.1', '2.2.2.2'], '2' => ['3.3.3.3', '3.3.3.4'], '3' => ['4.4.4.4', '5.5.5.5', '6.6.6.6']]]);
t_eq('опрос без ошибок', sess_collect(microtime(true) + 10), '');
$r = $rows();
t_eq('события: id 1 (лимит 1) — красная «Лимит»; id 3 (без лимита, 3 устройства) — жёлтая «Много IP»; id 2 (общий лимит 2) — чист', array_map(static fn($x) => $x['uid'] . ' ' . $x['gid'] . ' ' . $x['lvl'] . ' ' . ($x['end_ts'] ? 'closed' : 'open'), $r), ['1 g1 bad open', '3 g2 warn open']);
$why = json_decode($r[0]['why'], true);
t_eq('что сработало: IP 2 при пороге 1, провайдеров 2 при пороге 1, устройств 1', [$why['dev'], $why['h']], [1, [['ip', 2, 1], ['asn', 2, 1]]]);
t_eq('связанные IP записаны с нодой', array_map(static fn($x) => $x['ip'] . '@' . $x['node'], json_decode($r[0]['ips'], true)), ['1.1.1.1@' . $N1, '2.2.2.2@' . $N1]);
t_eq('пользователи с открытой аномалией для списка', sess_anom_open_map(), ['1' => ['l' => 'bad', 'n' => 1, 't' => 'Лимит: IP сейчас 2 при пороге 1, провайдеров сейчас 2 при пороге 1'], '3' => ['l' => 'warn', 'n' => 1, 't' => 'Много IP: IP сейчас 3 при пороге 2']]);

sleep(1);
sess_collect(microtime(true) + 10);
t_eq('та же картина — записей не прибавилось', count($rows()), 2);

$mock([$N1 => ['1' => ['1.1.1.1', '2.2.2.2'], '3' => ['4.4.4.4', '5.5.5.5', '6.6.6.6']], $N2 => ['1' => ['7.7.7.7']]]);
sess_collect(microtime(true) + 10);
$r = $rows('uid = 1');
t_eq('новый IP у открытого события дописывается; третий IP открыл и жёлтую группу', [count($r), array_column(json_decode($r[0]['ips'], true), 'ip'), $r[1]['gid'] . ' ' . $r[1]['lvl']], [2, ['1.1.1.1', '2.2.2.2', '7.7.7.7'], 'g2 warn']);

$mock([$N1 => ['1' => ['1.1.1.1']]]);
sess_collect(microtime(true) + 10);
$r = $rows();
t_eq('аномалия прошла — события закрыты, но остались: id 1 онлайн без аномалии, id 3 пропал из снимка', array_map(static fn($x) => $x['uid'] . ' ' . $x['gid'] . ' ' . ($x['end_ts'] ? 'closed' : 'open'), $r), ['1 g1 closed', '1 g2 closed', '3 g2 closed']);
t_ok('время закрытия проставлено', (int) $r[0]['end_ts'] >= (int) $r[0]['start_ts']);
t_eq('открытых больше нет', sess_anom_open_map(), []);
$tt_pdo->exec("INSERT INTO request_log (ts, ip, short_uuid, path, user_agent, decision) VALUES (" . (db_driver() === 'mysql' ? 'CURRENT_TIMESTAMP - INTERVAL 1 MINUTE' : "datetime('now', '-1 minute')") . ", '2.2.2.2', 'AbCdEfGhIjKlMnOp', '/sub', 'Happ/3.0', 'normal'), (" . (db_driver() === 'mysql' ? 'CURRENT_TIMESTAMP - INTERVAL 1 MINUTE' : "datetime('now', '-1 minute')") . ", '9.9.9.9', 'AbCdEfGhIjKlMnOp', '/sub', 'Happ/3.0', 'normal'), (" . (db_driver() === 'mysql' ? 'CURRENT_TIMESTAMP - INTERVAL 1 MINUTE' : "datetime('now', '-1 minute')") . ", '1.1.1.1', 'AbCdEfGhIjKlMnOp', '/sub', 'Mozilla/5.0', 'browser')");
$an = sess_anom_subips(sess_anom_user(1), 'AbCdEfGhIjKlMnOp');
$rq = [];
foreach ($an as $e) foreach ($e['ips'] as $x) if (isset($x['rq'])) $rq[$x['ip']] = $x['rq']['n'];
t_eq('совпадение с IP запросов подписки: 2.2.2.2 в обоих событиях; браузер и чужой IP не считаются', $rq, ['2.2.2.2' => 1]);
t_eq('без shortUuid журнал как есть', sess_anom_subips(sess_anom_user(1), ''), sess_anom_user(1));
$hm = sess_anom_hist_map();
t_ok('срез журнала: закрытые события есть у id 1 (красная и жёлтая) и id 3 (жёлтая), без «сейчас» в подсказке', array_keys($hm) === [1, 3] && $hm['1']['b'] > 0 && $hm['1']['w'] > 0 && $hm['3']['b'] === 0 && $hm['3']['w'] > 0 && mb_strpos($hm['3']['tw'], 'сейчас') === false && mb_strpos($hm['3']['tw'], 'Много IP: IP 3') === 0 && $hm['1']['tb'] !== '' && $hm['1']['tw'] !== '', json_encode($hm, JSON_UNESCAPED_UNICODE));

// Неполный опрос: отсутствие в снимке не закрывает
$tt_pdo->exec('UPDATE sess_anom SET end_ts = 0 WHERE uid = 3');
sess_anom_eval([], time(), false);
t_eq('часть нод не ответила — событие пропавшего пользователя остаётся открытым', count($rows('uid = 3 AND end_ts = 0')), 1);
sess_anom_eval([], time(), true);
t_eq('полный опрос без него — закрывается', count($rows('uid = 3 AND end_ts = 0')), 0);

// Карточка и список
$mock([$N1 => ['1' => ['1.1.1.1', '2.2.2.2']]]);
sess_collect(microtime(true) + 10);
[$c, , $b] = t_http('GET', $mw . '/admin/index.php?ajax=sess_user&id=1', [], null, $jar);
$j = json_decode($b, true);
t_ok('ajax sess_user: журнал — открытое первым, с группой, причиной и IP', $c === 200 && count($j['an']) === 3 && $j['an'][0]['e'] === 0 && $j['an'][0]['g'] === 'Лимит' && $j['an'][0]['h'][0] === ['ip', 2, 1] && $j['an'][0]['ips'][0]['ip'] === '1.1.1.1' && $j['an'][1]['e'] > 0 && $j['an'][2]['e'] > 0, $b);
[$c, , $page] = t_http('GET', $mw . '/admin/index.php?tab=users', [], null, $jar);
t_ok('список: карта открытых аномалий и схема в конфиге', $c === 200 && strpos($page, '"ano":{"1":{"l":"bad","n":1,') !== false && strpos($page, '"an":[{"id":"g1","name":"Лимит"') !== false && strpos($page, 'id="uxAnJson"') !== false, (string) $c);

// Пустая схема: бейдж и пересчёт — открытые закрываются сразу при сохранении
[$c, , $b] = $post(['sess_anom' => '[]']);
$fresh();
t_eq('схема пуста — открытые события закрыты при сохранении', [count(sess_anom_rules()), count($rows('end_ts = 0'))], [0, 0]);
[, , $page] = t_http('GET', $mw . '/admin/index.php?tab=users', [], null, $jar);
t_ok('бейдж «не отслеживается» красный при пустой схеме', strpos($page, 'Аномалии <span class="tag blocked">не отслеживается</span>') !== false);
sess_collect(microtime(true) + 10);
t_eq('без схемы новых событий нет', count($rows('end_ts = 0')), 0);

// Хранение и чистка
t_ok('таблица есть у «Базы данных»', isset(gc_tables()['sess_anom']));
$tt_pdo->prepare('INSERT INTO sess_anom (uid, gid, lvl, start_ts, last_ts, end_ts, why, ips) VALUES (9, ?, ?, ?, ?, ?, ?, ?)')->execute(['g1', 'bad', time() - 40 * 86400, time() - 40 * 86400, time() - 40 * 86400, '{}', '[]']);
set_setting('sess_purge_ts', '0');
sess_purge_maybe();
t_eq('события старше срока хранения удалены', count($rows('uid = 9')), 0);

t_done();
