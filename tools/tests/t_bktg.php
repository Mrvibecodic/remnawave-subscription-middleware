<?php
declare(strict_types=1);

require __DIR__ . '/_boot.php';

$p = db();
backup_ensure_schema();

function bg_fresh(): void { unset($GLOBALS['submw_settings_cache']); }

function bg_send_one(string $name, string $raw): string
{
    $err = '';
    return bk_tg_send_one(['n' => $name, 'p' => 0], $raw, microtime(true) + 240, $err);
}

$mst  = $tt_root . '/data/mock_tg.json';
$mlog = $tt_root . '/data/mock_tg.log';
$mdir = $tt_root . '/data/mock_tg_files';
@mkdir($mdir, 0700, true);
$tgState = static function (array $st) use ($mst) { file_put_contents($mst, json_encode($st)); };
$tg = static function (array $st) use ($tgState, $mlog, $mdir) {
    $tgState($st);
    file_put_contents($mlog, '');
    foreach (glob($mdir . '/*') ?: [] as $f) unlink($f);
};
$calls = static function (string $m = '') use ($mlog): array {
    $out = [];
    foreach (file($mlog) ?: [] as $l) {
        $c = json_decode($l, true);
        if (is_array($c) && ($m === '' || $c['m'] === $m)) $out[] = $c;
    }
    return $out;
};
$got = static function () use ($mdir): array { $f = glob($mdir . '/*') ?: []; sort($f); return $f; };
$after = static function (): void {
    $a = $GLOBALS['submw_cron_after'] ?? [];
    unset($GLOBALS['submw_cron_after']);
    foreach ($a as $fn) $fn();
};
$tg([]);
$srv = t_serve($tt_root . '/tools', $tt_root . '/tools/tests/_mock_tg.php', ['MOCK_TG' => $mst, 'MOCK_TG_LOG' => $mlog, 'MOCK_TG_DIR' => $mdir], ['post_max_size' => '300M', 'upload_max_filesize' => '300M']);
set_setting('chat_tg_api_base', $srv);
$TOK = '123456789:' . str_repeat('A', 35);

// ---- токен и поиск чата ----
t_ok('токен: формат проверяется', backup_tg_token_ok($TOK) && !backup_tg_token_ok('abc') && !backup_tg_token_ok('123:abc') && !backup_tg_token_ok('123456:' . str_repeat('A', 30) . '/../x') && !backup_tg_token_ok(''));
$r = backup_tg_check($TOK, $err);
t_ok('нет сообщений боту — просим написать', $r === null && strpos($err, 'Напишите боту @submw_test_bot') !== false, $err);
$tg(['bad_token' => $TOK]);
$r = backup_tg_check($TOK, $err);
t_ok('чужой токен — понятная ошибка', $r === null && strpos($err, 'не принял токен') !== false, $err);
$tg(['hook' => 1]);
$r = backup_tg_check($TOK, $err);
t_ok('у бота вебхук — нужен отдельный бот', $r === null && strpos($err, 'вебхук') !== false, $err);
$tg(['updates' => [
    ['update_id' => 1, 'message' => ['chat' => ['id' => 555, 'type' => 'private', 'first_name' => 'Ал', 'username' => 'al'], 'text' => 'hi']],
    ['update_id' => 2, 'my_chat_member' => ['chat' => ['id' => -100777, 'title' => 'Ушёл'], 'new_chat_member' => ['status' => 'kicked']]],
]]);
$r = backup_tg_check($TOK, $err);
$sm = $calls('sendMessage');
t_ok('чат найден по последнему сообщению, откуда бота выгнали — пропущен', is_array($r) && $r['chat'] === '555' && $r['title'] === 'Ал @al' && $r['bot'] === 'submw_test_bot', $err);
t_ok('в найденный чат ушло тестовое сообщение', count($sm) === 1 && $sm[0]['chat'] === '555');
$tg(['updates' => [
    ['update_id' => 1, 'message' => ['chat' => ['id' => 555, 'type' => 'private', 'first_name' => 'Ал']]],
    ['update_id' => 2, 'my_chat_member' => ['chat' => ['id' => -100888, 'title' => 'Группа админов', 'type' => 'supergroup'], 'new_chat_member' => ['status' => 'member']]],
]]);
$r = backup_tg_check($TOK, $err);
t_ok('бота добавили в группу — берётся группа', is_array($r) && $r['chat'] === '-100888' && $r['title'] === 'Группа админов', $err);
set_setting('backup_tg_token', $TOK);
set_setting('backup_tg_chat', '555');
set_setting('backup_tg_title', 'Ал');
$tg([]);
$r = backup_tg_check($TOK, $err);
t_ok('тот же токен без новых сообщений — остаётся найденный чат', is_array($r) && $r['chat'] === '555' && $r['title'] === 'Ал', $err);
$tg(['chat_gone' => 1]);
$r = backup_tg_check($TOK, $err);
t_ok('в чат не написать — ошибка, а не «связь есть»', $r === null && strpos($err, 'написать в него не удалось') !== false, $err);

// ---- готовность и расписание ----
set_setting('backup_auto_pass', '');
set_setting('backup_tg', '1');
bg_fresh();
t_ok('без пароля отправка не работает', !backup_tg_ready() && !backup_tg_on());
set_setting('backup_auto_pass', 'авто-пароль');
bg_fresh();
t_ok('с паролем, токеном и чатом — работает', backup_tg_ready() && backup_tg_on());
set_setting('backup_auto', '1');
foreach (['3' => 10800, '1' => 3600, '24' => 86400, '0' => 86400, '25' => 86400, 'x' => 86400] as $h => $sec) {
    set_setting('backup_auto_hours', (string) $h);
    bg_fresh();
    t_eq('интервал задачи при «' . $h . '» ч', cron_tasks()['backup'][1] ?? null, $sec);
}
set_setting('backup_auto_hours', '24');
bg_fresh();
t_ok('нет очереди — задачи повтора отправки нет', !isset(cron_tasks()['bktg']));
t_ok('без отметки задача не подталкивается', !cron_kicked('backup', ['start' => time() - 100]));
cron_kick('backup');
bg_fresh();
t_ok('отметка после прошлого запуска — задача пойдёт сразу', cron_kicked('backup', ['start' => time() - 100]) && cron_kicked('backup', null));
t_ok('запуск после отметки — больше не подталкивается', !cron_kicked('backup', ['start' => time() + 1]));
$st0 = ['tasks' => ['backup' => ['start' => time() - 60, 'ok_at' => time() - 59]]];
t_ok('свежий бэкап — по интервалу не пора', !cron_task_due($st0['tasks']['backup'], 86400, time()));

// ---- отправка бэкапа ----
$tg([]);
foreach (glob(backup_dir() . '/*') ?: [] as $f) @unlink($f);
t_eq('задача создаёт бэкап', cron_task_backup(['deadline' => microtime(true) + 240]), '');
t_ok('внутри задачи ничего не отправляется — отправка после планировщика', !$calls() && isset($GLOBALS['submw_cron_after']['backup_tg_after']));
$after();
$auto = array_values(array_filter(backup_list(), static fn($b) => $b['kind'] === 'auto'));
$files = $got();
$sd = $calls('sendDocument');
t_ok('файл ушёл в чат одним документом, байт в байт', count($auto) === 1 && count($files) === 1 && count($sd) === 1 && $sd[0]['chat'] === '555' && md5_file($files[0]) === md5_file(backup_dir() . '/' . $auto[0]['name']) && $sd[0]['file'] === $auto[0]['name']);
t_ok('в подписи — что это и размер', strpos($sd[0]['text'], 'Бэкап прослойки') !== false && strpos($sd[0]['text'], 'UTC') !== false);
bg_fresh();
t_ok('очередь пуста, отметка «отправлен»', backup_tg_pending() === null && !empty(backup_tg_last()['ok']));

$tg(['fail' => 1]);
t_eq('Telegram отказал — бэкап всё равно удачный', cron_task_backup(['deadline' => microtime(true) + 240]), '');
$after();
bg_fresh();
$q = backup_tg_pending();
t_ok('файл ждёт повтора, ошибка видна', is_array($q) && (int) $q['p'] === 0 && empty(backup_tg_last()['ok']) && strpos((string) backup_tg_last()['err'], 'test failure') !== false);
t_ok('появилась задача повтора отправки', (cron_tasks()['bktg'] ?? null) === ['Бэкап: отправка в Telegram', 900, true]);
$nAuto = count(array_filter(backup_list(), static fn($b) => $b['kind'] === 'auto'));
$tg([]);
$e = cron_task_bktg(['deadline' => microtime(true) + 240]);
t_ok('ошибка прошлой попытки — ошибка задачи повтора (планировщик реже повторяет)', strpos($e, 'test failure') !== false, $e);
t_ok('повтор тоже идёт после планировщика', !$calls() && isset($GLOBALS['submw_cron_after']['backup_tg_after']));
$after();
bg_fresh();
$sd = $calls('sendDocument');
t_ok('ушёл ровно один документ — тот, что ждал', count($sd) === 1 && $sd[0]['file'] === $q['n'] && backup_tg_pending() === null);
t_eq('повтор не создаёт новый бэкап', count(array_filter(backup_list(), static fn($b) => $b['kind'] === 'auto')), $nAuto);
t_eq('после удачи задача повтора без ошибки', cron_task_bktg([]), '');
unset($GLOBALS['submw_cron_after']);

$one = $q['n'];
set_setting('backup_tg_q', json_encode(['n' => $one, 'p' => 0]));
$tg(['fail' => 1, 'fail_code' => 400]);
$ok = backup_tg_send(microtime(true) + 240, $err);
bg_fresh();
t_ok('постоянная ошибка Telegram — без повторов, ошибка видна', !$ok && backup_tg_pending() === null && strpos($err, 'Отправка остановлена') !== false && strpos((string) backup_tg_last()['err'], 'остановлена') !== false, $err);
t_ok('после остановки задачи повтора нет', !isset(cron_tasks()['bktg']));

set_setting('backup_tg_q', json_encode(['n' => $one, 'p' => 0]));
$tg(['migrate' => ['555', '-100999']]);
$ok = backup_tg_send(microtime(true) + 240, $err);
bg_fresh();
$sd = array_values(array_filter($calls('sendDocument'), static fn($c) => empty($c['migrated'])));
t_ok('группа стала супергруппой — id подхвачен, файл ушёл туда', $ok && backup_tg_chat() === '-100999' && count($sd) === 1 && $sd[0]['chat'] === '-100999' && count($got()) === 1, $err);
set_setting('backup_tg_chat', '555');

set_setting('backup_tg_q', json_encode(['n' => $one, 'p' => 0]));
$tg([]);
$lkh = fopen(backup_dir() . '/.tg.lock', 'c');
flock($lkh, LOCK_EX);
$ok = backup_tg_send(microtime(true) + 240, $err);
flock($lkh, LOCK_UN);
fclose($lkh);
bg_fresh();
t_ok('отправка уже идёт — вторая не начинается', $ok && !$calls() && backup_tg_pending() !== null);
$qa = json_encode(['n' => $one, 'p' => 0]);
$qb = json_encode(['n' => 'submw-20000101-000000-auto-000001.json.gz.enc', 'p' => 0]);
set_setting('backup_tg_q', $qb);
t_ok('очередь меняется только если в ней то, что прочитали', !bk_tg_q_swap($qa, '') && bk_tg_q_raw() === $qb);
t_eq('во время отправки в очередь встал новый бэкап — старая отправка его не затирает', bg_send_one($one, $qa), 'next');
t_eq('новый бэкап остался в очереди', bk_tg_q_raw(), $qb);
set_setting('backup_tg_q', '');

$plain = backup_create('manual', '', true, null, $err);
set_setting('backup_tg_q', json_encode(['n' => $plain, 'p' => 0]));
$tg([]);
$ok = backup_tg_send(microtime(true) + 240, $err);
bg_fresh();
t_ok('файл без пароля в Telegram не уходит', !$ok && strpos($err, 'без пароля') !== false && !$calls('sendDocument') && backup_tg_pending() === null);
set_setting('backup_tg_q', json_encode(['n' => 'submw-20000101-000000-auto-000000.json.gz.enc', 'p' => 0]));
$ok = backup_tg_send(microtime(true) + 240, $err);
bg_fresh();
t_ok('файла уже нет — очередь снимается молча', $ok && $err === '' && backup_tg_pending() === null && !$calls());

// ---- большой файл частями ----
$big = backup_new_name('auto', true);
$bigPath = backup_dir() . '/' . $big;
$fh = fopen($bigPath, 'wb');
fwrite($fh, BK_MAGIC);
$left = 2 * BK_TG_PART + 4321;
while ($left > 0) { $n = min($left, 1048576); fwrite($fh, random_bytes($n)); $left -= $n; }
fclose($fh);
$bigMd5 = md5_file($bigPath);
set_setting('backup_tg_q', json_encode(['n' => $big, 'p' => 0]));
$tg(['fail' => 2]);
$ok = backup_tg_send(microtime(true) + 1000, $err);
bg_fresh();
$q = backup_tg_pending();
t_ok('часть 2 не ушла — очередь помнит, что первая уже в чате', !$ok && is_array($q) && (int) $q['p'] === 1 && count($got()) === 1, $err);
$tgState([]);
$ok = backup_tg_send(microtime(true) + 1000, $err);
bg_fresh();
$files = $got();
$sd = $calls('sendDocument');
$sent = array_values(array_filter($sd, static fn($c) => empty($c['refused'])));
t_ok('досланы только недостающие части', $ok && backup_tg_pending() === null && count($files) === 3 && count($sent) === 3, $err);
t_eq('имена частей .001–.003', array_column($sent, 'file'), [$big . '.001', $big . '.002', $big . '.003']);
$cat = hash_init('md5');
foreach ($files as $f) hash_update_file($cat, $f);
t_eq('склеенные части = исходный файл', hash_final($cat), $bigMd5);
t_ok('в подписи частей — номер и как собрать', strpos($sent[1]['text'], 'Часть 2 из 3') !== false && strpos($sent[1]['text'], '7-Zip') !== false);
t_ok('каждая часть не больше 49 МБ', max(array_column($sent, 'size')) <= BK_TG_PART);
t_ok('временные части удалены', !glob(backup_dir() . '/.part-tg-*'));
set_setting('backup_tg_q', json_encode(['n' => $big, 'p' => 0]));
$tg([]);
$ok = backup_tg_send(microtime(true) + 100, $err);
bg_fresh();
$q = backup_tg_pending();
t_ok('мало времени — одна часть, остальное в следующий запуск', $ok && count($got()) === 1 && is_array($q) && (int) $q['p'] === 1);
set_setting('backup_tg_q', '');
@unlink($bigPath);
foreach ([BK_TG_PART => 1, BK_TG_PART + 1 => 2] as $sz => $want) {
    $edge = backup_new_name('auto', true);
    $fh = fopen(backup_dir() . '/' . $edge, 'wb');
    fwrite($fh, BK_MAGIC . str_repeat("\0", $sz - strlen(BK_MAGIC)));
    fclose($fh);
    set_setting('backup_tg_q', json_encode(['n' => $edge, 'p' => 0]));
    $tg([]);
    backup_tg_send(microtime(true) + 1000, $err);
    $sent = $calls('sendDocument');
    t_ok('файл ' . $sz . ' байт — ' . $want . ' ' . ($want === 1 ? 'документ целиком' : 'части'), count($sent) === $want && ($want === 1 ? $sent[0]['file'] === $edge : $sent[1]['size'] === 1), $err);
    @unlink(backup_dir() . '/' . $edge);
}

// ---- сообщение о сбое ----
set_setting('backup_tg_alert', '0');
$bdir = backup_dir();
rename($bdir, $bdir . '.x');
file_put_contents($bdir, 'не папка');
$tg([]);
$e1 = cron_task_backup(['deadline' => microtime(true) + 240]);
$e2 = cron_task_backup(['deadline' => microtime(true) + 240]);
$sm = $calls('sendMessage');
t_ok('бэкап не создался — ошибка у задачи', $e1 !== '' && $e2 !== '');
t_ok('на серию сбоев — одно сообщение в чат', count($sm) === 1 && strpos($sm[0]['text'], 'не создан') !== false && $sm[0]['chat'] === '555');
unlink($bdir);
rename($bdir . '.x', $bdir);
t_eq('бэкап снова создаётся', cron_task_backup(['deadline' => microtime(true) + 240]), '');
bg_fresh();
t_eq('после удачи сбой снова сообщается', setting('backup_tg_alert', ''), '0');
$tg([]);
$lk = backup_lock($err);
$e3 = cron_task_backup(['deadline' => microtime(true) + 240]);
backup_unlock($lk);
t_ok('занято другим бэкапом — не сбой, в чат не пишем', $e3 === BK_BUSY && !$calls('sendMessage'));

// ---- хранение ----
set_setting('backup_tg', '0');
set_setting('backup_auto_keep', '2');
bg_fresh();
for ($i = 0; $i < 4; $i++) backup_create('auto', '', true, null, $err);
t_eq('хранится заданное число бэкапов по расписанию', count(array_filter(backup_list(), static fn($b) => $b['kind'] === 'auto')), 2);
t_ok('ручные не трогаются', count(array_filter(backup_list(), static fn($b) => $b['kind'] === 'manual')) === 1);
set_setting('backup_auto_keep', '7');

// ---- через админку ----
set_setting('update_state', json_encode(['checked_at' => time()]));
set_setting('backup_tg', '0');
set_setting('backup_auto', '0');
$mw = t_serve($tt_root, $tt_root . '/tools/tests/_router.php', [], ['opcache.enable' => '0']);
$jar = t_admin_login($mw);
[$c, , $page] = t_http('GET', $mw . '/admin/index.php?tab=backup', [], null, $jar);
t_ok('вкладка «Бэкап» открывается с блоком Telegram', $c === 200 && strpos($page, 'Отправка в Telegram') !== false && strpos($page, 'name="backup_auto_hours"') !== false && strpos($page, '</html>') !== false);
t_ok('найденный чат показан', strpos($page, 'Чат: <b>Ал</b>') !== false);
preg_match('~name="csrf" value="([0-9a-f]+)"~', $page, $m);
$csrf = $m[1] ?? '';
$post = static function (array $f) use ($mw, $jar, $csrf): string {
    t_http('POST', $mw . '/admin/index.php', ['content-type' => 'application/x-www-form-urlencoded'], http_build_query($f + ['csrf' => $csrf]), $jar);
    [, , $b] = t_http('GET', $mw . '/admin/index.php?tab=backup', [], null, $jar);
    return preg_match('~id="flashMsg" data-msg="([^"]*)"~', $b, $mm) ? html_entity_decode($mm[1], ENT_QUOTES) : '';
};
$fl = $post(['action' => 'save_backup_auto', 'backup_auto' => '1', 'backup_auto_hours' => '6', 'backup_auto_keep' => '9']);
bg_fresh();
$k1 = (int) setting('cron_kick_backup', '0');
t_ok('включение — сохранено и бэкап подтолкнут', setting('backup_auto') === '1' && setting('backup_auto_hours') === '6' && setting('backup_auto_keep') === '9' && $k1 >= time() - 5 && strpos($fl, 'включён') !== false, $fl);
set_setting('cron_kick_backup', '1');
$fl = $post(['action' => 'save_backup_auto', 'backup_auto' => '1', 'backup_auto_hours' => '6', 'backup_auto_keep' => '20']);
bg_fresh();
t_ok('часы те же — не подталкивается', setting('cron_kick_backup') === '1' && setting('backup_auto_keep') === '20');
$fl = $post(['action' => 'save_backup_auto', 'backup_auto' => '1', 'backup_auto_hours' => '99', 'backup_auto_keep' => '0']);
bg_fresh();
t_ok('смена часов — подталкивается; границы 1–24 и 1–200', (int) setting('cron_kick_backup') >= time() - 5 && setting('backup_auto_hours') === '24' && setting('backup_auto_keep') === '1' && strpos($fl, 'каждые 24 ч') !== false, $fl);
set_setting('backup_auto_hours', '6');
set_setting('backup_auto_keep', '15');
set_setting('cron_kick_backup', '1');
foreach (['' => 'пустые поля', 'abc' => 'не число', ' ' => 'пробел', '-3' => 'минус'] as $bad => $what) {
    $post(['action' => 'save_backup_auto', 'backup_auto' => '1', 'backup_auto_hours' => $bad, 'backup_auto_keep' => $bad]);
    bg_fresh();
    t_ok('«' . $what . '» — прежние значения остаются, бэкап не подталкивается', setting('backup_auto_hours') === '6' && setting('backup_auto_keep') === '15' && setting('cron_kick_backup') === '1');
}
[, , $page] = t_http('GET', $mw . '/admin/index.php?tab=backup', [], null, $jar);
t_ok('в поле — сохранённое значение', (bool) preg_match('~name="backup_auto_keep" min="1" max="200" value="15"~', $page));
$fl = $post(['action' => 'bk_tg_check', 'backup_tg_token' => 'не токен']);
t_ok('не токен — отказ', strpos($fl, 'не похоже на токен') !== false, $fl);
$tg(['updates' => [['update_id' => 5, 'message' => ['chat' => ['id' => 42, 'type' => 'private', 'first_name' => 'Новый']]]]]);
$TOK2 = '987654321:' . str_repeat('B', 35);
$fl = $post(['action' => 'bk_tg_check', 'backup_tg_token' => $TOK2]);
bg_fresh();
t_ok('новый токен — найден новый чат, смена сказана', setting('backup_tg_token') === $TOK2 && setting('backup_tg_chat') === '42' && strpos($fl, 'Чат сменён: был «Ал», теперь «Новый»') !== false, $fl);
set_setting('backup_auto_pass', '');
$fl = $post(['action' => 'save_backup_tg', 'backup_tg' => '1']);
bg_fresh();
t_ok('без пароля включить отправку нельзя', setting('backup_tg') === '0' && strpos($fl, 'пароль') !== false, $fl);
set_setting('backup_auto_pass', 'p');
$fl = $post(['action' => 'save_backup_tg', 'backup_tg' => '1']);
bg_fresh();
t_ok('с паролем — включается', setting('backup_tg') === '1', $fl);
[, , $page] = t_http('GET', $mw . '/admin/index.php?tab=backup', [], null, $jar);
t_ok('тумблер отправки включён и доступен', (bool) preg_match('~name="backup_tg" data-reload checked\s*>~', $page));
$tg(['updates' => [['update_id' => 6, 'message' => ['chat' => ['id' => 77, 'type' => 'private', 'first_name' => 'Чужой']]]]]);
$fl = $post(['action' => 'bk_tg_check', 'backup_tg_token' => '']);
bg_fresh();
t_ok('чат сменился — это сказано явно', setting('backup_tg_chat') === '77' && strpos($fl, 'Чат сменён: был «Новый», теперь «Чужой»') !== false, $fl);
$fl = $post(['action' => 'save_backup_pass', 'del' => '1']);
bg_fresh();
t_ok('убрали пароль — отправка выключилась', setting('backup_tg') === '0' && setting('backup_auto_pass') === '' && strpos($fl, 'Telegram выключена') !== false, $fl);
$tg(['bad_token' => $TOK2]);
$fl = $post(['action' => 'bk_tg_check', 'backup_tg_token' => '']);
t_ok('пустое поле — проверяется сохранённый токен', strpos($fl, 'не принял токен') !== false, $fl);
[$c, , $page] = t_http('GET', $mw . '/admin/index.php?tab=backup', [], null, $jar);
t_ok('токен в страницу не выводится', $c === 200 && strpos($page, $TOK2) === false && strpos($page, str_repeat('B', 35)) === false);

t_done();
