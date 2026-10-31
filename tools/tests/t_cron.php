<?php
declare(strict_types=1);

require __DIR__ . '/_boot.php';

function tc_fresh(): void { unset($GLOBALS['submw_settings_cache']); }

// Ответ по адресу уходит раньше задач: ждём, пока запуск отпустит блокировку.
function tc_wait(): void
{
    usleep(100000);
    for ($i = 0; $i < 200; $i++) {
        $f = @fopen(cron_lock_path(), 'c');
        if ($f && flock($f, LOCK_EX | LOCK_NB)) { flock($f, LOCK_UN); fclose($f); tc_fresh(); return; }
        if ($f) fclose($f);
        usleep(50000);
    }
}

function tc_state(array $tasks, ?int $beat = null): void
{
    set_setting('cron_state', json_encode(['beat' => $beat ?? time(), 'src' => 'cli', 'tasks' => $tasks]));
}

$state = $tt_root . '/data/mock_state.json';
$mlog  = $tt_root . '/data/mock.log';
$mock  = static function (array $extra = []) use ($state) {
    file_put_contents($state, json_encode(['users' => ['AbCdEfGhIjKlMnOp' => ['status' => 'ACTIVE']]] + $extra));
};
$mock();
$panel = t_serve($tt_root . '/tools', $tt_root . '/tools/mock_panel.php', ['MOCK_STATE' => $state, 'MOCK_LOG' => $mlog]);
t_settings(['sub_source' => 'panel', 'remnawave_url' => $panel, 'remnawave_api_key' => 'test-token', 'geoip_auto' => '0']);
// Проверка обновлений ходит на GitHub — в тесте она уже «была».
set_setting('update_state', json_encode(['checked_at' => time()]));
$mw = t_serve($tt_root, $tt_root . '/tools/tests/_router.php');

ensure_grace_table();
$ins = $tt_pdo->prepare('INSERT INTO grace_users (short_uuid, user_uuid, grace_until, ended_ts, retry_ts) VALUES (?, ?, ?, 0, 0)');
$addGrace = static function (int $n = 1) use ($tt_pdo, $ins) {
    $tt_pdo->exec("DELETE FROM grace_users");
    for ($i = 0; $i < $n; $i++) $ins->execute([sprintf('GraceGone%07d', $i), 'u-' . $i, time() - 3600]);
};
$graceLeft = static fn() => (int) $tt_pdo->query("SELECT COUNT(*) FROM grace_users")->fetchColumn();
$now = time();

// Секретный путь
[$c] = t_http('GET', $mw . '/' . str_repeat('Q', 32));
t_eq('без секрета путь из 32 символов — обычный неизвестный адрес', $c, 404);
t_eq('без секрета планировщик не запускался', cron_state(), []);
$sec = cron_secret_rotate();
t_ok('секрет: 32 буквы и цифры', (bool) preg_match('~^[A-Za-z0-9]{32}$~', $sec));
[$c] = t_http('GET', $mw . '/' . substr($sec, 0, 31) . 'x');
t_eq('чужой секрет — обычный неизвестный адрес', $c, 404);
tc_fresh();
t_eq('чужой секрет — планировщик не запускался', cron_state(), []);

// Кто проверяет грейс: хвост запроса подписки или планировщик
$addGrace();
t_http('GET', $mw . '/AbCdEfGhIjKlMnOp', ['User-Agent' => 'v2rayN/7.0']);
t_eq('без планировщика: хвост запроса проверил грейс', $graceLeft(), 0);

$addGrace();
tc_state(['grace' => ['start' => $now, 'ok_at' => $now]]);
t_http('GET', $mw . '/AbCdEfGhIjKlMnOp', ['User-Agent' => 'v2rayN/7.0']);
t_eq('планировщик проверяет грейс: хвост запроса его не трогает', $graceLeft(), 1);

tc_state(['grace' => ['start' => $now, 'ok_at' => $now - 60, 'err' => '']]);
t_ok('пока идёт очередная проверка, грейс остаётся за планировщиком', cron_owns_grace());
t_http('GET', $mw . '/AbCdEfGhIjKlMnOp', ['User-Agent' => 'v2rayN/7.0']);
t_eq('пока идёт очередная проверка: хвост запроса грейс не трогает', $graceLeft(), 1);
t_eq('пока идёт очередная проверка: на «О системе» — «идёт»', cron_view()['rows'][0]['state'], 'run');

tc_state(['grace' => ['start' => $now - 400, 'ok_at' => $now - 400]], $now - 400);
t_http('GET', $mw . '/AbCdEfGhIjKlMnOp', ['User-Agent' => 'v2rayN/7.0']);
t_eq('планировщик молчит 5 минут: хвост снова проверяет грейс', $graceLeft(), 0);

tc_state(['grace' => ['start' => $now + 3600, 'ok_at' => $now + 3600]], $now + 3600);
t_ok('часы ушли назад: отметка из будущего не владеет грейсом и не «жива»', !cron_owns_grace() && !cron_alive());
t_ok('часы ушли назад: задача снова по сроку', cron_task_due(cron_state()['tasks']['grace'], 60, time()));

// Запуск по адресу
$addGrace();
set_setting('cron_state', '');
[$c, $h, $b] = t_http('GET', $mw . '/' . $sec);
t_eq('секретный путь: 200 и «ok»', [$c, $b], [200, "ok\n"]);
t_eq('секретный путь: не кэшируется', $h['cache-control'] ?? null, 'no-store');
t_ok('секретный путь: Content-Length и Connection: close', ($h['content-length'] ?? '') === '3' && strtolower($h['connection'] ?? '') === 'close');
tc_wait();
$v = cron_view();
t_ok('запуск записан: по адресу, только что', $v['src'] === 'cron по адресу' && cron_alive());
$light = array_values(array_map(static fn($t) => $t[0], array_filter(cron_tasks(), static fn($t) => !$t[2])));
$okRows = array_values(array_map(static fn($r) => $r['title'], array_filter($v['rows'], static fn($r) => $r['state'] === 'ok')));
t_eq('без php-fpm по адресу — все быстрые задачи выполнены', $okRows, $light);
t_eq('без php-fpm по адресу — проверка обновлений «при заходе в админку»', $v['rows'][4]['state'], 'admin');
t_eq('задача grace разобрала просроченный грейс', $graceLeft(), 0);
t_ok('блокировка лежит в data/', is_file($tt_root . '/data/.cron.lock'));
t_ok('пользователь сайта запомнен', (string) setting('cron_web_uid', '') !== '');

$st1 = cron_state()['tasks'];
sleep(1);
t_http('GET', $mw . '/' . $sec);
tc_wait();
$st2 = cron_state()['tasks'];
t_ok('второй запуск через секунду: ни одна задача не повторена раньше срока', $st2['grace']['start'] === $st1['grace']['start'] && $st2['cleanup']['start'] === $st1['cleanup']['start']);

[$c] = t_http('GET', $mw . '/sub/' . $sec);
t_eq('секрет срабатывает и под префиксом пути', $c, 200);
tc_wait();

$lock = fopen(cron_lock_path(), 'c');
flock($lock, LOCK_EX);
$r = cron_run('cli');
t_ok('параллельный запуск пропускается', $r['ok'] === true && $r['ran'] === [] && ($r['skip'] ?? '') !== '');
flock($lock, LOCK_UN);
fclose($lock);

// Задача грейса
$addGrace(35);
set_setting('cron_state', '');
$r = cron_run('cli');
t_eq('грейс: за один запуск разобрано больше двадцати', $graceLeft(), 0);
t_ok('грейс: задача успешна', !empty($r['ran']['grace']['ok']));

$addGrace(30);
$mock(['latency_ms' => 400]);
$t0 = microtime(true);
cron_task_grace(['deadline' => microtime(true) + 1.5, 'detached' => true, 'can' => false]);
$took = microtime(true) - $t0;
$mock();
t_ok('грейс: время проверяется после каждой проверки, а не после пачки', $took < 3.5 && $graceLeft() >= 20, sprintf('%.1f с, осталось %d', $took, $graceLeft()));

// Настоящий запуск в отдельном процессе: пока он идёт, грейс не возвращается хвосту запроса.
$addGrace(6);
$mock(['latency_ms' => 400]);
tc_state(['grace' => ['start' => time() - 61, 'ok_at' => time() - 60]]);
$proc = proc_open([PHP_BINARY, $tt_root . '/tools/cron.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pp, $tt_root, ['SUBMW_TEST_BOX' => getenv('SUBMW_TEST_BOX')] + getenv());
$polls = 0;
$lost = 0;
while (proc_get_status($proc)['running']) {
    tc_fresh();
    $polls++;
    if (!cron_owns_grace()) $lost++;
    usleep(100000);
}
proc_close($proc);
$mock();
tc_fresh();
t_ok('идущий запуск не отдаёт грейс хвосту запроса', $polls >= 10 && $lost === 0 && $graceLeft() === 0, "опросов {$polls}, без планировщика {$lost}, осталось {$graceLeft()}");

$addGrace(3);
set_setting('remnawave_url', 'http://127.0.0.1:' . t_port());
set_setting('cron_state', '');
$r = cron_run('cli');
t_ok('панель недоступна: задача грейса — ошибка', empty($r['ran']['grace']['ok']) && strpos((string) ($r['ran']['grace']['err'] ?? ''), 'панель не ответила') === 0, var_export($r['ran']['grace'] ?? null, true));
t_ok('панель недоступна: хвост запроса снова проверяет грейс', !cron_owns_grace());
t_eq('панель недоступна: на «О системе» — «ошибка»', cron_view()['rows'][0]['state'], 'err');
set_setting('remnawave_url', $panel);

// Прерванные и неудачные попытки
tc_state(['cleanup' => ['start' => $now - 10, 'ok_at' => $now - 4000]]);
t_ok('прерванная часовая задача не повторяется сразу', !cron_task_due(cron_state()['tasks']['cleanup'], 3600, time()));
tc_state(['cleanup' => ['start' => $now - 301, 'ok_at' => $now - 4000]]);
t_ok('прерванная часовая задача повторяется через 5 минут, а не через час', cron_task_due(cron_state()['tasks']['cleanup'], 3600, time()));
tc_state(['cleanup' => ['start' => $now - 301, 'ok_at' => $now - 300]]);
t_ok('удачная часовая задача ждёт свой час', !cron_task_due(cron_state()['tasks']['cleanup'], 3600, time()));
tc_state(['cleanup' => ['start' => $now - 400, 'ok_at' => 0, 'err' => '']]);
$row = cron_view()['rows'][2];
t_eq('прерванная задача на «О системе» — «ошибка: не завершилась»', [$row['state'], $row['err']], ['err', 'не завершилась']);

cron_save(['beat' => time(), 'tasks' => ['x' => ['start' => 1, 'err' => mb_substr("ошибка \xD0", 0, 50)]]]);
t_ok('битый UTF-8 в тексте ошибки не стирает состояние', is_array(cron_state()['tasks']['x'] ?? null));

// Сбой запуска виден в админке
set_setting('cron_state', '');
@unlink(cron_lock_path());
mkdir(cron_lock_path());
$elog = ini_set('error_log', $tt_root . '/data/expected-errors.log');
$r = cron_run('cli');
ini_set('error_log', (string) $elog);
rmdir(cron_lock_path());
t_ok('сбой запуска: ошибка возвращена и записана', $r['ok'] === false && is_array(cron_view()['error']));

// Консоль: от пользователя сайта
t_ok('консоль: свой пользователь — запуск', cron_cli_ok(33, 33));
t_ok('консоль: чужой пользователь — отказ', !cron_cli_ok(1000, 33));
t_ok('консоль: root, сайт не от root — отказ', !cron_cli_ok(0, 33));
t_ok('консоль: пользователя не узнать — запуск', cron_cli_ok(null, 33) && cron_cli_ok(33, null));
$web = (string) setting('cron_web_uid', '');
set_setting('cron_web_uid', '4242');
t_eq('пользователь сайта берётся из веба, а не по владельцу файлов', cron_site_uid(), 4242);
set_setting('cron_web_uid', $web);

$cli = static function (string $env = '') use ($tt_root) {
    $out = [];
    exec($env . escapeshellarg(PHP_BINARY) . ' -d error_reporting=-1 -d display_errors=stderr ' . escapeshellarg($tt_root . '/tools/cron.php') . ' -v 2>&1', $out, $code);
    return [$code, implode("\n", $out)];
};
set_setting('cron_state', '');
[$code, $out] = $cli();
t_ok('консоль от пользователя сайта: все задачи выполнены', $code === 0 && substr_count($out, ': ок,') === count(cron_tasks()), $out);
t_ok('консоль: без предупреждений PHP', !preg_match('~(Warning|Notice|Deprecated)~', $out), $out);
set_setting('cron_web_uid', '65534');
[$code, $out] = $cli();
t_ok('консоль не от пользователя сайта: отказ с подсказкой', $code === 1 && strpos($out, 'crontab -u ') !== false, $out);
file_put_contents($tt_root . '/data/config.php', str_replace("__DIR__ . '/data/", "__DIR__ . '/", (string) file_get_contents($tt_root . '/config.php')));
set_setting('cron_state', '');
[$code, $out] = $cli('SUBMW_DOCKER=1 ');
unlink($tt_root . '/data/config.php');
set_setting('cron_web_uid', $web);
tc_fresh();
t_ok('Docker: пользователь от прежней установки не мешает, запуск отмечен как Docker', $code === 0 && (cron_state()['src'] ?? '') === 'docker', $out);

$cfg = (string) file_get_contents($tt_root . '/config.php');
file_put_contents($tt_root . '/config.php', "<?php\nreturn ['installed' => true, 'db' => ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => " . t_port() . ", 'name' => 'x', 'user' => 'x', 'pass' => 'x'], 'admin_user' => 'admin', 'admin_pass_hash' => 'x'];\n");
[$code, $out] = $cli();
file_put_contents($tt_root . '/config.php', $cfg);
t_ok('консоль без базы: выход 1 и понятная причина', $code === 1 && strpos($out, 'нет подключения к базе') !== false, $out);

// «О системе»
set_setting('mirror_domain', 'sub.example.test');
set_setting('cron_state', '');
cron_run('cli');
$jar = t_admin_login($mw);
[, , $page] = t_http('GET', $mw . '/admin/index.php?tab=sysinfo', [], null, $jar);
t_ok('«О системе»: планировщик работает', strpos($page, 'Планировщик <span class="tag normal">работает</span>') !== false);
t_ok('«О системе»: строка cron по адресу', strpos($page, 'curl -fsSL --max-time 120 -o /dev/null &#039;https://sub.example.test/' . $sec . '&#039;') !== false && strpos($page, '/' . $sec . '&#039; &gt;/dev/null 2&gt;&amp;1</code>') !== false);
t_ok('«О системе»: строка для консоли с путём к PHP', (bool) preg_match('~\* \* \* \* \* &#039;[^&]*php[^&]*&#039; &#039;[^&]*/tools/cron\.php&#039; &gt;/dev/null~', $page));
preg_match('~name="csrf" value="([0-9a-f]+)"~', $page, $m);
[$c] = t_http('POST', $mw . '/admin/index.php', ['content-type' => 'application/x-www-form-urlencoded'], http_build_query(['csrf' => $m[1] ?? '', 'action' => 'cron_secret_rotate']), $jar);
tc_fresh();
t_ok('«Сменить секрет»: секрет новый', $c === 302 && cron_secret() !== $sec && strlen(cron_secret()) === 32);
[$c] = t_http('GET', $mw . '/' . $sec);
t_eq('старый секрет больше не запускает', $c, 404);

file_put_contents($tt_root . '/data/config.php', str_replace("__DIR__ . '/data/", "__DIR__ . '/", (string) file_get_contents($tt_root . '/config.php')));
$dk = t_serve($tt_root, $tt_root . '/tools/tests/_router.php', ['SUBMW_DOCKER' => '1']);
$djar = t_admin_login($dk);
[, , $dpage] = t_http('GET', $dk . '/admin/index.php?tab=sysinfo', [], null, $djar);
unlink($tt_root . '/data/config.php');
t_ok('«О системе» в Docker: строк cron и смены секрета нет', strpos($dpage, 'Планировщик') !== false && strpos($dpage, 'curl -fsSL') === false && strpos($dpage, 'cron_secret_rotate') === false);

tc_state([], time() - 4000);
[, , $page] = t_http('GET', $mw . '/admin/index.php?tab=sysinfo', [], null, $jar);
t_ok('«О системе»: планировщик остановился', strpos($page, '<span class="tag blocked">остановился</span>') !== false);

// Пауза после сбоев растёт: 5 мин, 15 мин, час — но не дольше обычного интервала
$f = static fn(int $fails, int $ago, int $every = 86400) => cron_task_due(['start' => $now - $ago, 'ok_at' => 0, 'err' => 'x', 'fails' => $fails], $every, $now);
t_eq('первый сбой — повтор через 5 минут', [$f(1, 200), $f(1, 400)], [false, true]);
t_eq('второй подряд — через 15', [$f(2, 400), $f(2, 1000)], [false, true]);
t_eq('третий и дальше — через час', [$f(3, 1000), $f(7, 3700)], [false, true]);
t_eq('частая задача ждёт не дольше своего интервала', $f(5, 70, 60), true);

t_done();
