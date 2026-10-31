<?php
declare(strict_types=1);

// Go-сервис для «Сессий». Живой Go — если задан SUBMW_TEST_GO_BIN (cd go && go build -o /tmp/submw-go .), иначе только PHP-часть.
require __DIR__ . '/_boot.php';

$state = $tt_root . '/data/mock_state.json';
$mlog  = $tt_root . '/data/mock.log';
$N1 = '11111111-1111-4111-8111-111111111111';
$N2 = '22222222-2222-4222-8222-222222222222';
$N3 = '33333333-3333-4333-8333-333333333333';
$mock = static function (array $extra = []) use ($state, $N1, $N2, $N3) {
    file_put_contents($state, json_encode([
        'users' => ['AbCdEfGhIjKlMnOp' => ['status' => 'ACTIVE']],
        'nodes' => [$N1 => ['name' => 'Amsterdam', 'cc' => 'NL'], $N2 => ['name' => 'Frankfurt', 'cc' => 'DE'], $N3 => ['name' => 'Helsinki', 'cc' => 'FI']],
        'hosts' => [],
        'conns' => [$N1 => ['1' => ['1.2.3.4']], $N2 => ['1' => ['1.2.3.4'], '2' => ['5.6.7.8', '2001:db8::1']], $N3 => ['3' => ['9.9.9.9']]],
    ] + $extra));
};
$mock(['version' => '3.4.7']);
$panel = t_serve($tt_root . '/tools', $tt_root . '/tools/mock_panel.php', ['MOCK_STATE' => $state, 'MOCK_LOG' => $mlog]);
t_settings(['sub_source' => 'panel', 'remnawave_url' => $panel, 'remnawave_api_key' => 'test-token', 'remnawave_xapikey' => 'xk', 'geoip_auto' => '0', 'sess_enabled' => '1']);
set_setting('update_state', json_encode(['checked_at' => time()]));
remnawave_panel_meta(0);
$logHas = static fn(string $needle) => strpos((string) @file_get_contents($mlog), $needle) !== false;
$snap = static function () use ($tt_pdo) {
    $out = [];
    foreach ($tt_pdo->query('SELECT uid, node, ip FROM conn_sess ORDER BY uid, node, ip')->fetchAll(PDO::FETCH_NUM) as $r) $out[] = implode('|', $r);
    return $out;
};
$plog = static fn() => $tt_pdo->query('SELECT * FROM sess_poll ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: [];
$reset = static function () use ($tt_pdo) {
    sess_ensure();
    $tt_pdo->exec('DELETE FROM conn_sess');
    $tt_pdo->exec('DELETE FROM sess_poll');
    set_setting('go_state', '');
    unset($GLOBALS['submw_settings_cache']);
};

// Адрес и секрет
t_eq('адрес: схема, хост и порт', go_url_norm(' http://submw-go:8090/ '), 'http://submw-go:8090');
t_eq('адрес: пустой — по умолчанию', go_url_norm(''), '');
foreach (['submw-go:8090', 'ftp://x', 'http://u:p@x', 'http://x/path', 'http://x?a=1'] as $bad) t_eq('адрес отклонён: ' . $bad, go_url_norm($bad), null);
t_eq('по умолчанию вне Docker — 127.0.0.1:8090', go_url_default(), 'http://127.0.0.1:8090');
$sec = go_secret();
t_ok('секрет — 32 знака, создаётся один раз', (bool) preg_match('~^[A-Za-z0-9]{32}$~', $sec) && go_secret() === $sec);

// Go нет и не было — опрос из PHP, без тревоги
$closed = t_port();
set_setting('go_url', '');
$reset();
t_eq('без Go опрос из PHP без ошибок', sess_collect(microtime(true) + 10), '');
$php_snap = $snap();
$r = $plog();
t_eq('журнал: PHP, все ноды', [$r['eng'] ?? '', (int) $r['nodes'], (int) $r['ok'], (int) $r['conns']], ['php', 3, 3, 5]);
t_ok('журнал: запросы к панели посчитаны', (int) $r['reqs'] >= 6, (string) ($r['reqs'] ?? ''));
t_eq('бейдж без Go — PHP, не тревога', go_view()['mode'], 'none');
t_eq('Go, которого нет, интервал не трогает', sess_every(), 120);

// Адрес задан, а Go молчит — «PHP вместо Go», опрос не теряется
set_setting('go_url', 'http://127.0.0.1:' . $closed);
$reset();
set_setting('go_url', 'http://127.0.0.1:' . $closed);
unset($GLOBALS['submw_settings_cache']);
t_eq('Go задан, но не отвечает — опрос всё равно прошёл', sess_collect(microtime(true) + 10), '');
$r = $plog();
t_eq('журнал: PHP вместо Go, все ноды', [$r['eng'], (int) $r['ok']], ['fb', 3]);
t_ok('журнал: причина — соединение отклонено', strpos((string) $r['err'], 'Go: не отвечает: соединение отклонено') === 0, (string) $r['err']);
t_eq('снимок тот же, что у PHP', $snap(), $php_snap);
t_eq('бейдж: тревога', go_view()['mode'], 'warn');
t_eq('журнал: в сессиях отмечено, кто опрашивал', sess_state()['eng'] ?? '', 'fb');

$bin = (string) getenv('SUBMW_TEST_GO_BIN');
if ($bin === '' || !is_executable($bin)) {
    echo "  пропуск живого Go: SUBMW_TEST_GO_BIN не задан\n";
    t_done();
}

$gport = t_port();
$gstart = static function (string $secret) use ($bin, $gport, $tt_root) {
    $log = $tt_root . '/data/go-' . $gport . '.log';
    $env = getenv();
    $env['SUBMW_GO_LISTEN'] = '127.0.0.1:' . $gport;
    $env['SUBMW_GO_SECRET'] = $secret;
    $p = proc_open([$bin], [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, null, $env);
    for ($i = 0; $i < 100; $i++) { $c = @fsockopen('127.0.0.1', $gport, $no, $er, 0.1); if ($c) { fclose($c); break; } usleep(50000); }
    return $p;
};
$gstop = static function ($p) { @proc_terminate($p); @proc_close($p); };
$go = $gstart($sec);
register_shutdown_function(static function () use (&$go, $gstop) { if ($go) $gstop($go); });
set_setting('go_url', 'http://127.0.0.1:' . $gport);
$reset();
set_setting('go_url', 'http://127.0.0.1:' . $gport);
unset($GLOBALS['submw_settings_cache']);

$e = '';
$h = go_hello($e);
t_ok('Go отвечает на проверку', is_array($h) && (int) $h['bridge'] === GO_BRIDGE, $e);
t_eq('первое появление Go: интервал стал 1 минута', sess_every(), 60);
set_setting('sess_every', '300');
unset($GLOBALS['submw_settings_cache']);
go_hello($e);
t_eq('интервал меняется один раз — дальше за админом', sess_every(), 300);

@unlink($mlog);
t_eq('опрос через Go без ошибок', sess_collect(microtime(true) + 10), '');
$r = $plog();
t_eq('журнал: Go, все ноды', [$r['eng'], (int) $r['nodes'], (int) $r['ok'], (int) $r['conns']], ['go', 3, 3, 5]);
t_eq('снимок через Go совпадает с PHP', $snap(), $php_snap);
t_ok('Go ходит по адресам 3.x', $logHas('POST /api/connections/by-node/' . $N1) && !$logHas('ip-control'));
t_eq('бейдж: Go', go_view()['mode'], 'go');
apistat_flush();
$n = (int) $tt_pdo->query("SELECT COALESCE(SUM(n), 0) FROM api_stat_hour WHERE src = 'go:sessions'")->fetchColumn();
t_ok('«Запросы к панели» видят запросы Go отдельной строкой', $n === (int) $r['reqs'] && $n >= 6, $n . ' / ' . $r['reqs']);
t_eq('подпись группы', apistat_src_label('go:sessions'), 'Go-сервис: опрос нод');

// Панель 2.x, а в кэше 3.x — Go получает «Cannot POST», прослойка меняет семейство и повторяет
$mock(['version' => '2.7.4', 'only_v2' => 1]);
set_setting('sess_api_v3', '1');
unset($GLOBALS['submw_sess_v3'], $GLOBALS['submw_settings_cache']);
@unlink($mlog);
t_eq('смена семейства адресов через Go', sess_collect(microtime(true) + 10), '');
t_ok('после смены — адреса 2.x', $logHas('POST /api/ip-control/fetch-users-ips/' . $N1));
t_eq('журнал: Go, все ноды после смены', [$plog()['eng'], (int) $plog()['ok']], ['go', 3]);
$mock(['version' => '3.4.7']);
set_setting('sess_api_v3', '1');
unset($GLOBALS['submw_sess_v3'], $GLOBALS['submw_settings_cache']);

// Сбой старта задания по ноде — Go повторяет её один раз, в журнале отметка повтора
foreach (glob($tt_root . '/data/mock_once_*') ?: [] as $f) unlink($f);
$mock(['version' => '3.4.7', 'start_fail_once' => [$N2]]);
@unlink($mlog);
t_eq('сбой старта по ноде — опрос всё равно полный', sess_collect(microtime(true) + 10), '');
$r = $plog();
t_eq('журнал: Go, все ноды, повтор отмечен по имени ноды', [$r['eng'], (int) $r['ok'], json_decode((string) $r['retry'], true)], ['go', 3, ['Frankfurt']]);
t_eq('повторно стартовала только сбойная нода', substr_count((string) file_get_contents($mlog), 'POST /api/connections/by-node/' . $N2), 2);
t_eq('фильтр «С повтором»', count(sess_plog_rows('rt', 0, 50)), 1);
t_eq('сводка считает опросы с повтором', sess_plog_summary(24)['rt'], 1);
$mock(['version' => '3.4.7']);

// Нода не отвечает — частичный снимок, ошибка с именем
$mock(['version' => '3.4.7', 'job_slow' => 1]);
$t0 = microtime(true);
$err = sess_collect(microtime(true) + 6);
$r = $plog();
t_ok('медленные ноды: опрос укладывается в бюджет', microtime(true) - $t0 < 8, (string) (microtime(true) - $t0));
t_ok('ни одна не успела — ошибка и список', $err !== '' && $r['eng'] === 'go' && (int) $r['ok'] === 0 && count(json_decode((string) $r['fail'], true)) === 3, $err);
$mock(['version' => '3.4.7']);

// Чужой секрет — PHP вместо Go и понятная причина
go_secret_rotate();
unset($GLOBALS['submw_settings_cache']);
t_eq('чужой секрет — опрос всё равно прошёл', sess_collect(microtime(true) + 10), '');
t_eq('журнал: PHP вместо Go из-за секрета', [$plog()['eng'], strpos((string) $plog()['err'], 'Go: секрет не подходит') === 0], ['fb', true]);
t_eq('бейдж: тревога с причиной', [go_view()['mode'], go_view()['err']], ['warn', 'секрет не подходит — впишите текущий в Go-контейнер']);

// Выключен вручную — PHP, даже когда Go жив
$gstop($go);
$go = $gstart(go_secret());
set_setting('go_off', '1');
unset($GLOBALS['submw_settings_cache']);
sess_collect(microtime(true) + 10);
t_eq('«Не использовать Go» — опрос из PHP', $plog()['eng'], 'php');
t_eq('бейдж: выключено', go_view()['mode'], 'off');
set_setting('go_off', '0');
unset($GLOBALS['submw_settings_cache']);
sess_collect(microtime(true) + 10);
t_eq('включили обратно — снова Go', $plog()['eng'], 'go');

// Go упал посреди работы — следующий опрос делает PHP
$gstop($go);
$go = null;
t_eq('Go упал — опрос не потерян', sess_collect(microtime(true) + 10), '');
t_eq('журнал: PHP вместо Go', [$plog()['eng'], (int) $plog()['ok']], ['fb', 3]);

// Сводка, журнал и CSV в админке
$sum = sess_plog_summary(24);
t_ok('сводка: Go и PHP отдельно, переходы посчитаны', $sum['go']['n'] >= 3 && $sum['php']['n'] >= 3 && $sum['fb'] >= 2 && $sum['fb_last'] !== null, json_encode($sum, JSON_UNESCAPED_UNICODE));
t_eq('фильтр «С пропусками» находит медленный опрос', count(sess_plog_rows('part', 0, 50)) >= 1, true);
$mw = t_serve($tt_root, $tt_root . '/tools/tests/_router.php');
$jar = t_admin_login($mw);
[$c, , $page] = t_http('GET', $mw . '/admin/index.php?tab=users', [], null, $jar);
t_ok('вкладка: бейдж, блок Go-сервиса и журнал', $c === 200 && strpos($page, 'ux-eng wn') !== false && strpos($page, 'id="uxGo"') !== false && strpos($page, 'id="uxPlog"') !== false && strpos($page, GO_DOC_URL) !== false);
t_ok('секрет передан только в данные страницы админки', strpos($page, go_secret()) !== false);
[$c, , $b] = t_http('GET', $mw . '/admin/index.php?ajax=sess_plog&f=fb&p=1&h=72', [], null, $jar);
$j = json_decode($b, true);
t_ok('ajax журнала: фильтр и сводка', $c === 200 && !empty($j['ok']) && count($j['rows']) >= 2 && $j['rows'][0]['eng'] === 'fb' && $j['sum']['hours'] === 72, substr($b, 0, 300));
[$c, $hd, $b] = t_http('GET', $mw . '/admin/index.php?sess_plog_csv=1', [], null, $jar);
t_ok('CSV: заголовок и строки, колонка повторов', $c === 200 && strpos($hd['content-type'] ?? '', 'text/csv') === 0 && strpos($b, 'ts;time_utc;engine') !== false && strpos($b, ';retried') !== false && strpos($b, ';Frankfurt') !== false && substr_count($b, "\n") > 5);
[$c, , $b] = t_http('GET', $mw . '/admin/index.php?sess_plog_csv=1');
t_ok('CSV без входа не отдаётся', strpos($b, 'time_utc') === false);
preg_match('~name="csrf" value="([0-9a-f]+)"~', $page, $cm);
[$c, , $b] = t_http('GET', $mw . '/admin/index.php?ajax=go_check', [], null, $jar);
t_ok('проверка Go без POST и CSRF не выполняется', strpos($b, '"go"') === false);
[$c, , $b] = t_http('POST', $mw . '/admin/index.php?ajax=go_check', ['content-type' => 'application/x-www-form-urlencoded'], http_build_query(['csrf' => $cm[1]]), $jar);
$j = json_decode($b, true);
t_ok('ajax проверки Go: состояние и настоящая причина', $c === 200 && !empty($j['ok']) && $j['alive'] === false && $j['go']['mode'] === 'warn' && strpos((string) $j['err'], 'соединение отклонено') !== false, $b);
[$c, , $b] = t_http('POST', $mw . '/admin/index.php', ['content-type' => 'application/x-www-form-urlencoded'], http_build_query(['csrf' => $cm[1], 'action' => 'save_go', 'go_url' => 'http://x/path', 'xhr' => '1']), $jar);
unset($GLOBALS['submw_settings_cache']);
t_ok('неверный адрес не сохраняется', go_url_set() === 'http://127.0.0.1:' . $gport, go_url_set());
[$c, , $b] = t_http('POST', $mw . '/admin/index.php', ['content-type' => 'application/x-www-form-urlencoded'], http_build_query(['csrf' => $cm[1], 'action' => 'save_go', 'go_url' => '', 'go_off' => '1', 'xhr' => '1']), $jar);
unset($GLOBALS['submw_settings_cache']);
t_ok('адрес сброшен на «сам», Go выключен', go_url_set() === '' && go_off());
[$c, , $b] = t_http('POST', $mw . '/admin/index.php?ajax=go_rotate', ['content-type' => 'application/x-www-form-urlencoded'], http_build_query(['csrf' => 'bad']), $jar);
t_ok('смена секрета без CSRF отклонена', $c === 400);

// Опрос, не получивший списка нод, не портит среднее время и запросы в сводке
$tt_pdo->exec('DELETE FROM sess_poll');
$tt_pdo->exec("INSERT INTO sess_poll (ts, eng, nodes, ok, ms, reqs, conns) VALUES (" . time() . ", 'go', 3, 3, 1000, 10, 5), (" . time() . ", 'go', 0, 0, 1, 0, 0)");
$sum = sess_plog_summary(24);
t_eq('сводка: опрос без списка нод — только в числе опросов', [$sum['go']['n'], $sum['go']['ms'], $sum['go']['reqs'], $sum['go']['full']], [2, 1000, 10, 100.0]);

// Чужая программа на адресе Go: токен панели ей не уходит
$flog = $tt_root . '/data/foreign.log';
file_put_contents($tt_root . '/data/foreign.php', '<?php file_put_contents(' . var_export($flog, true) . ', ($_SERVER["REQUEST_METHOD"] ?? "") . " " . ($_SERVER["REQUEST_URI"] ?? "") . " " . file_get_contents("php://input") . "\n", FILE_APPEND); header("Content-Type: application/json"); echo "{\"ok\":true}";');
$foreign = t_serve($tt_root . '/data', $tt_root . '/data/foreign.php');
set_setting('go_off', '0');
set_setting('go_url', $foreign);
$reset();
set_setting('go_url', $foreign);
unset($GLOBALS['submw_settings_cache']);
t_eq('чужая программа на адресе Go — опрос из PHP', sess_collect(microtime(true) + 10), '');
t_eq('журнал: PHP вместо Go', $plog()['eng'], 'fb');
t_ok('чужой программе не ушёл ни токен панели, ни запрос опроса', strpos((string) @file_get_contents($flog), 'test-token') === false && strpos((string) @file_get_contents($flog), '/v1/sessions/poll') === false, (string) @file_get_contents($flog));
t_ok('причина: по адресу не Go-сервис', strpos(go_view()['err'], 'не Go-сервис') !== false, go_view()['err']);

// Чистка журнала по сроку
$tt_pdo->prepare('INSERT INTO sess_poll (ts, eng, nodes, ok) VALUES (?, ?, 1, 1)')->execute([time() - 9 * 86400, 'php']);
set_setting('sess_purge_ts', '0');
sess_purge_maybe();
t_eq('журнал старше 7 дней удалён', (int) $tt_pdo->query('SELECT COUNT(*) FROM sess_poll WHERE ts < ' . (time() - 8 * 86400))->fetchColumn(), 0);
t_ok('таблица журнала есть у «Базы данных»', isset(gc_tables()['sess_poll']));

t_done();
