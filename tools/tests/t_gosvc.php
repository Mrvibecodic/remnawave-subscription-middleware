<?php
declare(strict_types=1);

// Go-сервис: опрос нод для «Сессий» и список пользователей. Живой Go — если задан SUBMW_TEST_GO_BIN (cd go && go build -o /tmp/submw-go .), иначе только PHP-часть.
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
t_eq('локальный адрес Go: localhost, 127.*, имя контейнера', array_map('go_url_local', ['http://127.0.0.1:8090', 'http://localhost:8090', 'http://submw-go:8090', 'http://go.example.com:8090', 'http://10.0.0.5:8090']), [true, true, true, false, false]);
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
// Без Go список пользователей — как раньше: PHP, без проверок Go и без записей о нём
$t0 = microtime(true);
$l = remnawave_all_users($e1);
t_ok('без Go список собирает PHP, полный объект', count($l) === 1 && $e1 === '' && ($l[0]['shortUuid'] ?? '') === 'AbCdEfGhIjKlMnOp' && isset($l[0]['status']), $e1);
t_eq('без Go: поля места вызова не мешают PHP', count(remnawave_all_users($e1, null, ['shortUuid'])[0] ?? []) > 1, true);
admin_cache_put('sess_users', ['src' => '', 'u' => null, 'ts0' => 0]);
t_eq('без Go: лимиты для «Сессий» — PHP', array_keys(sess_users_limits(1800, microtime(true) + 5)), [1]);
unset($GLOBALS['submw_settings_cache']);
t_eq('без Go: о Go ничего не записано', [setting('go_state', ''), setting('go_users', ''), go_users_view()['eng']], ['', '', '']);
$seek = time() - 10;
set_setting('go_seek', (string) $seek);
unset($GLOBALS['submw_settings_cache']);
sess_collect(microtime(true) + 10);
unset($GLOBALS['submw_settings_cache']);
t_eq('незнакомый Go ищется не чаще раза в 5 минут', [(int) setting('go_seek', '0'), $plog()['eng'] ?? ''], [$seek, 'php']);
set_setting('go_seek', '');
unset($GLOBALS['submw_settings_cache']);
sess_collect(microtime(true) + 10);
unset($GLOBALS['submw_settings_cache']);
t_ok('через 5 минут незнакомый Go ищется снова', (int) setting('go_seek', '0') >= time() - 15);
set_setting('go_seek', '');
apistat_flush();
t_eq('без Go: в «Запросах к панели» строк Go нет', (int) $tt_pdo->query("SELECT COUNT(*) FROM api_stat_hour WHERE src LIKE 'go:%'")->fetchColumn(), 0);
t_ok('без Go: список не ждёт Go', microtime(true) - $t0 < 3, (string) (microtime(true) - $t0));
t_eq('без Go: сверки в планировщике нет', isset(cron_tasks()['gocheck']), false);

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

// Список пользователей через Go
t_eq('Go рассказал, что умеет', [go_can('sessions'), go_can('users')], [true, true]);
// Образ Go обновили, прослойка его ещё не спрашивала (опрос нод hello не делает) — список узнаёт сам, без «Проверить»
$mock(['version' => '3.4.7', 'users_n' => 5]);
foreach ([null, 'old|abc'] as $case) {
    $s = go_state();
    if ($case === null) unset($s['caps'], $s['caps_of']); else $s['caps_of'] = $case;
    go_state_put($s);
    unset($GLOBALS['submw_settings_cache']);
    sess_collect(microtime(true) + 10);
    t_eq('после опроса нод умение Go неизвестно' . ($case === null ? ' (состояние до обновления)' : ' (сменился образ)'), [go_view()['mode'], go_users_view()['cap']], ['go', null]);
    $l = remnawave_all_users($e1, null, ['shortUuid']);
    t_eq('список сам спросил Go и пошёл через него', [count($l), go_can('users'), go_users_view()['eng'], go_users_view()['cap']], [5, true, 'go', true]);
}
sess_collect(microtime(true) + 10);
t_eq('опрос нод тем же Go умение не сбрасывает', go_can('users'), true);
apistat_flush();
$go_hits0 = (int) $tt_pdo->query("SELECT COALESCE(SUM(n), 0) FROM api_stat_hour WHERE src = 'go:users'")->fetchColumn();
$noexp = static function (array $l) { foreach ($l as &$u) unset($u['expireAt']); return $l; };
$users_reset = static function () {
    foreach (['go_users', 'go_users_block', 'go_users_check'] as $k) set_setting($k, '');
    unset($GLOBALS['submw_settings_cache']);
};
$users_reset();
$mock(['version' => '3.4.7', 'users_n' => 600]);
$e = '';
$gl = go_users_list(['shortUuid'], $e);
t_ok('Go отдал весь список', is_array($gl) && count($gl) === 600, $e);
t_eq('Go: только запрошенные поля, порядок панели', [$gl[0] ?? null, $gl[599] ?? null], [['shortUuid' => 'U000000000000000'], ['shortUuid' => 'U000000000000599']]);
$uv = go_users_view();
t_eq('строка «Список пользователей»: Go, 3 страницы, 600', [$uv['eng'], $uv['pages'], $uv['n']], ['go', 3, 600]);
apistat_flush();
$n = (int) $tt_pdo->query("SELECT COALESCE(SUM(n), 0) FROM api_stat_hour WHERE src = 'go:users'")->fetchColumn();
t_eq('«Запросы к панели»: строка Go — список пользователей', $n - $go_hits0, 3);
t_eq('подпись группы списка', apistat_src_label('go:users'), 'Go-сервис: список пользователей');
$e1 = $e2 = '';
$full_go = remnawave_all_users($e1);
$full_php = remnawave_all_users_php($e2);
t_ok('полный объект через Go совпадает с PHP', $e1 === '' && $e2 === '' && count($full_go) === 600 && $noexp($full_go) === $noexp($full_php), $e1 . ' ' . $e2);
$f = remnawave_all_users($e1, null, ['shortUuid', 'username']);
t_eq('обёртка: поля места вызова', $f[5] ?? null, ['shortUuid' => 'U000000000000005', 'username' => 'user5']);
$chunks = [];
t_eq('обёртка с $each: ничего не копит', remnawave_all_users($e1, static function (array $c) use (&$chunks) { $chunks[] = count($c); }, ['shortUuid']), []);
t_eq('обёртка с $each: страницы по 250', $chunks, [250, 250, 100]);
admin_cache_put('sess_users', ['src' => '', 'u' => null, 'ts0' => 0]);
$lim = sess_users_limits(1800, microtime(true) + 10);
t_ok('лимиты для «Сессий» через Go — весь список за раз', count($lim) === 600 && array_key_exists('1', $lim), (string) count($lim));
// Обход дольше порции PHP-докачки (5 с): планировщик даёт Go запас на весь список, PHP не зовётся.
$mock(['version' => '3.4.7', 'users_n' => 4000, 'latency_ms' => 350]);
admin_cache_put('sess_users', ['src' => md5(remnawave_url() . "\n" . remnawave_token()), 'u' => ['1' => [null, '']], 'ts0' => time() - 4000]);
@unlink($mlog);
cron_task_sessions(['deadline' => microtime(true) + 120, 'detached' => true, 'can' => true]);
$lim = admin_cache_get('sess_users', PHP_INT_MAX);
t_eq('долгий список лимитов: Go собрал целиком, без PHP-докачки', [count((array) ($lim['u'] ?? [])), substr_count((string) @file_get_contents($mlog), "GET /api/users\n")], [4000, 16]);
$mock(['version' => '3.4.7', 'users_n' => 600]);

// Сбой страницы — Go ничего не отдаёт, список собирает PHP
foreach (glob($tt_root . '/data/mock_once_*') ?: [] as $f) unlink($f);
$mock(['version' => '3.4.7', 'users_n' => 600, 'users_fail_once' => [250]]);
$l = remnawave_all_users($e1, null, ['shortUuid']);
$uv = go_users_view();
t_ok('сбой страницы у Go — полный список от PHP', count($l) === 600 && $e1 === '' && $uv['eng'] === 'fb' && strpos($uv['err'], 'HTTP 502') !== false, $uv['err']);
t_eq('сбой панели — не тревога Go', go_view()['mode'], 'go');
t_ok('PHP отдал полный объект, а не только поля', isset($l[0]['status']));

// Сверка
$mock(['version' => '3.4.7', 'users_n' => 600]);
t_eq('сверка: совпало', go_users_compare($d), true);
t_eq('сверка записана', [go_users_view()['check']['ok'] ?? null, go_users_blocked()], [true, null]);
foreach (glob($tt_root . '/data/mock_once_*') ?: [] as $f) unlink($f);
$mock(['version' => '3.4.7', 'users_extra_on' => [1, 3]]);
t_eq('сверка: Go дважды отдал не то — не совпало', go_users_compare($d), false);
t_eq('причина расхождения', $d, 'у Go 2, у PHP 1 пользователей');
t_eq('после несовпадения Go списком не пользуются', [go_users_list(['shortUuid'], $e), $e], [null, 'сверка с Go не совпала']);
t_eq('список собирает PHP', count(remnawave_all_users($e1, null, ['shortUuid'])), 1);
t_eq('строка в блоке: PHP, причина', [go_users_view()['eng'], go_users_view()['block']['detail']], ['block', 'у Go 2, у PHP 1 пользователей']);
foreach (glob($tt_root . '/data/mock_once_*') ?: [] as $f) unlink($f);
$mock(['version' => '3.4.7', 'users_extra_on' => [1]]);
t_eq('пользователя добавили между обходами — второй ответ Go совпал', go_users_compare($d), true);
$mock(['version' => '3.4.7', 'users_extra_on' => [1, 3]]);
foreach (glob($tt_root . '/data/mock_once_*') ?: [] as $f) unlink($f);
t_ok('задача планировщика сообщает о расхождении', strpos(cron_task_gocheck([]), 'не совпал') !== false);
t_ok('сверка есть в планировщике', isset(cron_tasks()['gocheck']));
$mock(['version' => '3.4.7']);
t_eq('панель снова в порядке — сверка снимает блок', go_users_compare($d), true);
t_eq('Go снова собирает список', is_array(go_users_list(['shortUuid'], $e)), true);

// Go упал и поднялся — без «Сессий» список сам проверяет Go, не чаще раза в 5 минут
go_mark_fail('down', 'тест');
$s = go_state(); $s['probe'] = 0; go_state_put($s);
unset($GLOBALS['submw_settings_cache']);
$mock(['version' => '3.4.7', 'users_n' => 3]);
remnawave_all_users($e1, null, ['shortUuid']);
t_eq('Go снова отвечает — список вернулся на Go сам', [go_view()['mode'], go_users_view()['eng'], go_users_view()['n']], ['go', 'go', 3]);
go_mark_fail('down', 'тест');
unset($GLOBALS['submw_settings_cache']);
go_users_note(['eng' => '']);
remnawave_all_users($e1, null, ['shortUuid']);
t_eq('проверка не чаще раза в 5 минут — пока список собирает PHP', [go_view()['mode'], go_users_view()['eng']], ['warn', '']);
go_hello($e);

// «Не использовать Go» выключает и список
set_setting('go_off', '1');
unset($GLOBALS['submw_settings_cache']);
t_eq('«Не использовать Go» — список без Go', go_users_list(['shortUuid'], $e), null);
set_setting('go_off', '0');
unset($GLOBALS['submw_settings_cache']);
$users_reset();

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
t_ok('«Пользователи»: бейдж, журнал и ссылка на блок Go', $c === 200 && strpos($page, 'ux-eng wn') !== false && strpos($page, 'id="uxPlog"') !== false && strpos($page, '?tab=sysinfo#siGo') !== false && strpos($page, 'id="uxGo"') === false);
t_ok('на «Пользователях» секрета нет', strpos($page, go_secret()) === false);
[$c, , $page] = t_http('GET', $mw . '/admin/index.php?tab=sysinfo', [], null, $jar);
t_ok('«О системе»: блок Go-сервиса со строками задач', $c === 200 && strpos($page, 'id="siGo"') !== false && strpos($page, 'Опрос нод для «Сессий»') !== false && strpos($page, 'Список пользователей панели') !== false && strpos($page, GO_DOC_URL) !== false);
t_ok('секрет передан только в данные страницы админки', strpos($page, go_secret()) !== false);
t_ok('предупреждение про чужой сервер скрыто для локального адреса', (bool) preg_match('~id="siGoRemote" hidden~', $page));
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
t_ok('ajax проверки Go: состояние и настоящая причина', $c === 200 && !empty($j['ok']) && $j['alive'] === false && $j['go']['mode'] === 'warn' && strpos((string) $j['err'], 'соединение отклонено') !== false && is_array($j['users']), $b);
t_ok('«О системе» сама спрашивает Go при открытии, без «Проверить»', strpos($page, "post('go_check','auto')") !== false);
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

// Go старой версии (без списка) и Go, не видящий панель: список — PHP, опрос нод Go не теряет
$fmode = $tt_root . '/data/fakego.mode';
file_put_contents($tt_root . '/data/fakego.php', '<?php $m = trim((string) @file_get_contents(' . var_export($fmode, true) . ')); header("Content-Type: application/json"); $u = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);'
    . ' if ($u === "/v1/hello") { echo json_encode(["ok" => true, "v" => "old", "bridge" => 1] + ($m === "old" ? [] : ["caps" => ["sessions", "users"]])); exit; }'
    . ' if ($u === "/v1/users/list" && $m === "pdown") { echo json_encode(["ok" => true, "bridge" => 1, "v" => "x", "hits" => [["m" => "GET", "p" => "/api/users?size=250&start=0", "c" => 0, "ms" => 1]], "users" => null, "panel_down" => "dial tcp: lookup remnawave: no such host"]); exit; }'
    . ' http_response_code(404); header("Content-Type: text/plain"); echo "404 page not found";');
$fake = t_serve($tt_root . '/data', $tt_root . '/data/fakego.php');
set_setting('go_off', '0');
set_setting('go_url', $fake);
foreach (['go_state', 'go_users', 'go_users_block', 'go_users_check'] as $k) set_setting($k, '');
unset($GLOBALS['submw_settings_cache']);
file_put_contents($fmode, 'old');
go_hello($e);
t_eq('старый Go: опрос нод умеет, список — нет', [go_view()['mode'], go_can('sessions'), go_can('users')], ['go', false, false]);
t_eq('старый Go: список собирает PHP, Go не тревога', [count(remnawave_all_users($e1, null, ['shortUuid'])), go_view()['mode'], go_users_view()['cap']], [3, 'go', false]);
t_eq('старый Go: сверки в планировщике нет', isset(cron_tasks()['gocheck']), false);
$s = go_state(); $s['caps'] = ['sessions', 'users']; go_state_put($s);
unset($GLOBALS['submw_settings_cache']);
t_eq('Go заменили старым без проверки — список PHP, Go не тревога, умение снято', [count(remnawave_all_users($e1, null, ['shortUuid'])), go_view()['mode'], go_can('users'), go_users_view()['err']], [3, 'go', false, go_err_text('nocap')]);
file_put_contents($fmode, 'pdown');
go_hello($e);
t_eq('Go не видит панель: список PHP, причина видна, Go ждёт проверки', [count(remnawave_all_users($e1, null, ['shortUuid'])), go_view()['mode'], go_view()['kind'], strpos(go_users_view()['err'], 'не достучался до панели') === 0], [3, 'warn', 'panel', true]);
$mock(['version' => '3.4.7']);

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
