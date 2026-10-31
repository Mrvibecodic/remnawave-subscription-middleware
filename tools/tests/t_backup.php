<?php
declare(strict_types=1);

require __DIR__ . '/_boot.php';

$p = db();
$drv = db_driver();
backup_ensure_schema();
// В рабочей установке хеш пароля в config.php постоянный, а тестовый config считает его заново при каждом чтении.
$cfgPath = config_path();
$cfgBox = (string) file_get_contents($cfgPath);
$cfgOrig = "<?php\nreturn " . var_export(['installed' => true, 'db' => cfg()['db'], 'admin_user' => 'admin', 'admin_pass_hash' => password_hash('test', PASSWORD_DEFAULT)], true) . ";\n";
file_put_contents($cfgPath, $cfgOrig);

function bt_state(): array
{
    $p = db();
    $drv = db_driver();
    $out = [];
    $skip = array_merge(backup_cache_tables(), ['login_attempts']);
    foreach (array_diff(array_intersect(db_list_tables($p, $drv), backup_known_tables()), $skip) as $t) {
        $rows = $p->query('SELECT * FROM ' . bk_q($t, $drv))->fetchAll(PDO::FETCH_ASSOC);
        if ($t === 'settings') $rows = array_values(array_filter($rows, static function ($r) { return !backup_keep_setting($r['k'], false) && $r['k'] !== 'admin_gate_key'; }));
        $s = array_map(static function ($r) { return md5(serialize($r)); }, $rows);
        unset($rows);
        sort($s);
        $out[$t] = md5(implode(',', $s));
    }
    ksort($out);
    return $out;
}

function bt_count(string $t): int { return (int) db()->query('SELECT COUNT(*) FROM ' . $t)->fetchColumn(); }

function bt_fresh_settings(): void { unset($GLOBALS['submw_settings_cache']); }

// ---- данные ----
$bad = "abc\xff\xfe" . 'z';
t_settings(['service_name' => 'Тест', 'brand_cache' => '{}', 'cron_secret' => str_repeat('A', 32), 'backup_auto_pass' => 'секрет-авто', 'admin_gate_key' => '']);
$ins = $p->prepare('INSERT INTO overrides (match_type, match_value, reason, note) VALUES (?, ?, ?, ?)');
for ($i = 0; $i < 30; $i++) $ins->execute(['short', 'user' . $i, 'block', $i === 7 && $drv === 'sqlite' ? $bad : 'заметка ' . $i]);
$rlIns = $p->prepare('INSERT INTO request_log (short_uuid, ip, user_agent) VALUES (?, ?, ?)');
$p->beginTransaction();
for ($i = 0; $i < 20000; $i++) $rlIns->execute(['s' . ($i % 50), '10.0.' . ($i % 250) . '.1', 'UA ' . $i]);
$p->commit();
ensure_chat_tables();
$p->exec("INSERT INTO chat_sessions (id, token, name) VALUES (5, '" . str_repeat('c', 32) . "', 'chatuser')");
$p->exec("INSERT INTO chat_messages (session_id, sender, body) VALUES (5, 'visitor', 'привет')");
$p->exec("INSERT INTO squad_cache (su, squads, ts) VALUES ('cached', '[]', 1)");
ensure_login_attempts();
$p->exec("INSERT INTO login_attempts (ip) VALUES ('1.2.3.4')");
bt_fresh_settings();
$orig = bt_state();

t_ok('известные таблицы найдены в исходниках', count(backup_known_tables()) >= 40 && in_array('settings', backup_known_tables(), true) && in_array('chat_messages', backup_known_tables(), true));

// ---- полный бэкап и восстановление ----
$t0 = microtime(true);
$name = backup_create('manual', '', false, null, $err);
t_ok('бэкап создан', $name !== '', $err);
$ms = (int) ((microtime(true) - $t0) * 1000);
echo "  (выгрузка 20k строк лога: {$ms} мс, " . metrics_fmt_bytes(filesize(backup_dir() . '/' . $name)) . ")\n";
$list = backup_list();
t_eq('бэкап в списке, тип «вручную»', [$list[0]['name'] ?? '', $list[0]['kind'] ?? '', $list[0]['enc'] ?? null], [$name, 'manual', false]);
$raw = gzdecode((string) file_get_contents(backup_dir() . '/' . $name));
$head = json_decode(strtok($raw, "\n"), true);
t_ok('шапка: формат, логин, нет кэшей и login_attempts', ($head['submw_backup'] ?? 0) === 1 && ($head['login']['user'] ?? '') === 'admin'
    && !array_intersect(array_column($head['tables'], 't'), ['squad_cache', 'login_attempts', 'admin_cache']));
t_ok('пароль авто-бэкапов в файл не попал', strpos($raw, 'секрет-авто') === false);
t_ok('чужих таблиц в файле нет', !array_diff(array_column($head['tables'], 't'), backup_known_tables()));

$p->exec('DELETE FROM overrides');
$p->exec("INSERT INTO overrides (match_type, match_value, reason) VALUES ('short', 'новый', 'block')");
$p->exec("DELETE FROM request_log WHERE id % 2 = 0");
set_setting('service_name', 'Изменено');
set_setting('cron_secret', str_repeat('B', 32));
set_setting('update_branch', 'dev');
$p->exec("INSERT INTO squad_cache (su, squads, ts) VALUES ('after', '[]', 2)");
bt_fresh_settings();
$mod = bt_state();

$pv = backup_preview($name, '', $err);
t_ok('просмотр: строки, журналы, логин как сейчас', is_array($pv) && $pv['rows'] > 0 && $pv['logs'] === true && $pv['log_rows'] >= 20000 && $pv['same_login'] === true && !$pv['skip_tables'], $err . ' ' . json_encode($pv, JSON_UNESCAPED_UNICODE));

app_ensure();
$p->exec("INSERT INTO app_dev (did, dpub, name, created, last_seen) VALUES ('d-after', 'k', 'тел', 1, 1)");
$pv2 = backup_preview($name, '', $err);
t_ok('просмотр: телефоны в файле и сейчас', is_array($pv2) && $pv2['phones'] === 0 && $pv2['phones_now'] === 1);
$p->exec("DELETE FROM app_dev WHERE did = 'd-after'");

$ticks = [];
$t0 = microtime(true);
$res = backup_restore($name, '', ['keep_login' => false, 'no_logs' => false], static function ($s, $t = '', $d = 0, $n = 0) use (&$ticks) { $ticks[$s] = true; }, $err);
$ms = (int) ((microtime(true) - $t0) * 1000);
echo "  (восстановление со снимком: {$ms} мс)\n";
t_ok('восстановление прошло', is_array($res), $err);
bt_fresh_settings();
t_ok('данные как в момент бэкапа', bt_state() === $orig);
t_eq('этапы: снимок, очистка, загрузка', array_keys(array_intersect_key($ticks, ['snap' => 1, 'wipe' => 1, 'load' => 1])), ['snap', 'wipe', 'load']);
t_eq('секрет cron остался текущим', setting('cron_secret'), str_repeat('B', 32));
t_eq('ветка обновлений осталась текущей', setting('update_branch'), 'dev');
t_eq('пароль авто-бэкапов остался', setting('backup_auto_pass'), 'секрет-авто');
t_eq('кэш очищен', bt_count('squad_cache'), 0);
t_eq('login_attempts не тронуты', bt_count('login_attempts'), 1);
if ($drv === 'sqlite') {
    $v = $p->query("SELECT note FROM overrides WHERE match_value = 'user7'")->fetchColumn();
    t_ok('невалидный UTF-8 вернулся байт в байт', $v === $bad);
}
t_ok('связка чата цела', (int) $p->query("SELECT COUNT(*) FROM chat_messages m JOIN chat_sessions s ON s.id = m.session_id WHERE s.name = 'chatuser'")->fetchColumn() === 1);
t_eq('вход не менялся — перелогин не нужен', [$res['relogin'], $res['gate']], [false, null]);
$snaps = array_values(array_filter(backup_list(), static function ($b) { return $b['kind'] === 'pre'; }));
t_ok('снимок перед восстановлением в списке', count($snaps) === 1 && $snaps[0]['name'] === $res['snap']);

$res = backup_restore($res['snap'], '', [], null, $err);
bt_fresh_settings();
t_ok('откат снимком вернул изменённое состояние', is_array($res) && bt_state() === $mod, $err);
backup_restore($name, '', [], null, $err);
bt_fresh_settings();

// ---- пароль, порча, обрезка ----
$enc = backup_create('manual', 'пароль 123', false, null, $err);
t_ok('бэкап с паролем создан', $enc !== '' && backup_is_enc(backup_dir() . '/' . $enc), $err);
t_eq('просмотр без пароля просит пароль', backup_preview($enc, '', $err), ['need_pass' => true]);
t_ok('неверный пароль', backup_preview($enc, 'не тот', $err) === null && strpos($err, 'Неверный пароль') !== false, $err);
t_ok('верный пароль открывает', is_array(backup_preview($enc, 'пароль 123', $err)), $err);
$p->exec("DELETE FROM overrides WHERE match_value = 'user1'");
$before = bt_state();
t_ok('восстановление с неверным паролем ничего не меняет', backup_restore($enc, 'не тот', [], null, $err) === null && bt_state() === $before);
$bin = (string) file_get_contents(backup_dir() . '/' . $enc);
$pos = 36 + 5 + 100;
$bin2 = $bin; $bin2[$pos] = chr(ord($bin2[$pos]) ^ 1);
file_put_contents(backup_dir() . '/bad-enc.json.gz.enc', $bin2);
$preN = static function () { return count(array_filter(backup_list(), static function ($b) { return $b['kind'] === 'pre'; })); };
$pre0 = $preN();
$r = backup_restore('bad-enc.json.gz.enc', 'пароль 123', [], null, $err);
t_ok('подменённый байт: отказ, данные не тронуты', $r === null && bt_state() === $before && $preN() === $pre0, $err);
file_put_contents(backup_dir() . '/cut-enc.json.gz.enc', substr($bin, 0, (int) (strlen($bin) * 0.6)));
t_ok('обрезанный файл с паролем: отказ', backup_restore('cut-enc.json.gz.enc', 'пароль 123', [], null, $err) === null && bt_state() === $before, $err);
$res = backup_restore($enc, 'пароль 123', [], null, $err);
bt_fresh_settings();
t_ok('верный пароль: восстановлено', is_array($res) && bt_state() === $orig, $err);

$plain = (string) file_get_contents(backup_dir() . '/' . $name);
file_put_contents(backup_dir() . '/cut.json.gz', substr($plain, 0, (int) (strlen($plain) * 0.7)));
$p->exec("DELETE FROM overrides WHERE match_value = 'user2'");
$before = bt_state();
t_ok('обрезанный gzip: отказ, данные не тронуты', backup_restore('cut.json.gz', '', [], null, $err) === null && bt_state() === $before, $err);
$lines = explode("\n", rtrim(gzdecode($plain), "\n"));
array_pop($lines);
file_put_contents(backup_dir() . '/noend.json.gz', gzencode(implode("\n", $lines) . "\n"));
t_ok('без финальной строки: отказ', backup_restore('noend.json.gz', '', [], null, $err) === null && bt_state() === $before, $err);
$l2 = explode("\n", rtrim(gzdecode($plain), "\n"));
foreach ($l2 as $i => $l) { if (strpos($l, '"user5"') !== false) { unset($l2[$i]); break; } }
file_put_contents(backup_dir() . '/lost.json.gz', gzencode(implode("\n", $l2) . "\n"));
$names0 = array_column(backup_list(), 'name');
t_ok('пропала строка: отказ уже после очистки — всё откатилось', backup_restore('lost.json.gz', '', [], null, $err) === null && bt_state() === $before && strpos($err, 'не хватает записей') !== false, $err);
t_eq('пропала строка: лишний снимок убран', array_column(backup_list(), 'name'), $names0);
// Снимки «перед восстановлением»: неудачное восстановление не съедает самый старый из трёх.
while ($preN() < 3) backup_restore($name, '', [], null, $err);
$pre3 = array_column(array_values(array_filter(backup_list(), static function ($b) { return $b['kind'] === 'pre'; })), 'name');
backup_restore('lost.json.gz', '', [], null, $err);
t_eq('неудачное восстановление: три прежних снимка на месте', array_column(array_values(array_filter(backup_list(), static function ($b) { return $b['kind'] === 'pre'; })), 'name'), $pre3);
backup_restore($name, '', [], null, $err);
t_eq('удачное: снимков по-прежнему три', $preN(), 3);
// Файл старой версии без таблицы: её данные остаются как есть.
$l3 = explode("\n", rtrim(gzdecode($plain), "\n"));
$h3 = json_decode($l3[0], true);
$h3['tables'] = array_values(array_filter($h3['tables'], static fn($m) => $m['t'] !== 'overrides'));
$in = false; $out3 = [json_encode($h3, BK_JSON)]; $endN = 0;
foreach (array_slice($l3, 1) as $l) {
    if ($l !== '' && $l[0] === '{') { $o = json_decode($l, true); if (isset($o['end'])) { $nn = (array) $o['n']; $endN = (int) ($nn['overrides'] ?? 0); unset($nn['overrides']); $o['n'] = (object) $nn; $o['rows'] -= $endN; $out3[] = json_encode($o, BK_JSON); break; } $in = ($o['t'] ?? '') === 'overrides'; if ($in) continue; }
    if (!$in) $out3[] = $l;
}
file_put_contents(backup_dir() . '/old-noover.json.gz', gzencode(implode("\n", $out3) . "\n"));
$ov0 = bt_count('overrides');
$p->exec("INSERT INTO overrides (match_type, match_value, reason) VALUES ('short', 'after-backup', 'block')");
$pv = backup_preview('old-noover.json.gz', '', $err);
t_ok('просмотр: таблица, которой нет в файле, останется как есть', is_array($pv) && in_array('overrides', $pv['keep_tables'], true), $err);
t_ok('восстановление файла без таблицы проходит', is_array(backup_restore('old-noover.json.gz', '', [], null, $err)), $err);
t_eq('её строки не тронуты, включая добавленные после бэкапа', bt_count('overrides'), $ov0 + 1);
backup_restore($name, '', [], null, $err);
// Сжатый файл-«бомба»: отказ без падения по памяти.
$ml = ini_get('memory_limit');
ini_set('memory_limit', '128M');
$z = deflate_init(ZLIB_ENCODING_GZIP);
$bomb = deflate_add($z, $l3[0] . "\n", ZLIB_NO_FLUSH);
$chunk = str_repeat('a', 1048576);
for ($i = 0; $i < 300; $i++) $bomb .= deflate_add($z, $chunk, ZLIB_NO_FLUSH);
$bomb .= deflate_add($z, '', ZLIB_FINISH);
unset($chunk);
file_put_contents(backup_dir() . '/bomb.json.gz', $bomb);
unset($bomb);
$before = bt_state();
t_ok('«бомба» на 300 МБ: отказ, данные не тронуты', backup_restore('bomb.json.gz', '', [], null, $err) === null && strpos($err, 'повреждён') !== false && bt_state() === $before, $err);
t_ok('память при этом не выросла выше лимита', memory_get_peak_usage(true) < 128 * 1048576);
ini_set('memory_limit', (string) $ml);
foreach (['old-noover.json.gz', 'bomb.json.gz'] as $f) @unlink(backup_dir() . '/' . $f);
file_put_contents(backup_dir() . '/junk.json.gz', gzencode("hello\n"));
t_ok('чужой gzip: отказ', backup_preview('junk.json.gz', '', $err) === null, $err);
t_ok('имя с ../ не принимается', backup_path('../config.php') === '' && backup_path('..%2Fx.gz') === '');
backup_restore($name, '', [], null, $err);
bt_fresh_settings();

// ---- правки ревью ----
$p->exec("DELETE FROM settings WHERE k IN ('blocked_remarks', 'landing_fp_ack')");
bt_fresh_settings();
backup_preview($name, '', $err);
bt_fresh_settings();
t_ok('просмотр не дописывает настройки по умолчанию', setting('blocked_remarks') === null && setting('landing_fp_ack') === null);
backup_restore($name, '', [], null, $err);
bt_fresh_settings();
t_ok('права файла: владелец и группа читают, остальные нет', (fileperms(backup_dir() . '/' . $name) & 0777) === 0640);
touch(backup_dir() . '/.part-old', time() - 90000);
touch(backup_dir() . '/.part-new');
backup_list();
t_ok('брошенный .part старше суток убирается, свежий нет', !is_file(backup_dir() . '/.part-old') && is_file(backup_dir() . '/.part-new'));
unlink(backup_dir() . '/.part-new');
chan_ensure();
$p->exec("DELETE FROM chan_key");
$p->exec("INSERT INTO chan_key (spid, secret, created, is_current) VALUES ('AAAAAA', 's', 1, 1)");
set_setting('admin_gate_key', 'kabcdefg=' . str_repeat('2', 24));
$kn = backup_create('manual', '', true, null, $err);
$pv = backup_preview($kn, '', $err);
t_ok('просмотр: ключ канала тот же, секретная ссылка включена', is_array($pv) && $pv['chan_new'] === false && $pv['gate'] === true && $pv['gate_now'] === true, json_encode($pv));
$p->exec("UPDATE chan_key SET is_current = 0");
$p->exec("INSERT INTO chan_key (spid, secret, created, is_current) VALUES ('BBBBBB', 's', 2, 1)");
set_setting('admin_gate_key', '');
bt_fresh_settings();
$pv = backup_preview($kn, '', $err);
t_ok('просмотр: ключ канала сменился после бэкапа, ссылки сейчас нет', is_array($pv) && $pv['chan_new'] === true && $pv['gate'] === true && $pv['gate_now'] === false);
$p->exec("DELETE FROM chan_key");
if ($drv === 'sqlite') {
    $p->exec("INSERT INTO overrides (match_type, match_value, reason, note) VALUES ('short', 'huge', 'block', '" . str_repeat('z', BK_MAXLINE + 10) . "')");
    $hn = backup_create('manual', '', true, null, $err);
    t_ok('запись больше предела — бэкап не создаётся, понятная ошибка', $hn === '' && strpos($err, 'overrides') !== false && !glob(backup_dir() . '/.part-*'), $err);
    $p->exec("DELETE FROM overrides WHERE match_value = 'huge'");
}
backup_restore($name, '', [], null, $err);
bt_fresh_settings();

// ---- журналы ----
$lite = backup_create('manual', '', true, null, $err);
$lh = json_decode(strtok(gzdecode((string) file_get_contents(backup_dir() . '/' . $lite)), "\n"), true);
t_ok('без журналов: request_log в файле нет', $lite !== '' && !in_array('request_log', array_column($lh['tables'], 't'), true));
$p->exec('DELETE FROM request_log WHERE id < 100');
$rl = bt_count('request_log');
$pv = backup_preview($lite, '', $err);
t_ok('просмотр: журналов нет', is_array($pv) && $pv['logs'] === false);
backup_restore($lite, '', [], null, $err);
t_eq('восстановление без журналов не трогает текущие журналы', bt_count('request_log'), $rl);
backup_restore($name, '', ['no_logs' => true], null, $err);
t_eq('галка «без журналов» на полном файле — журналы те же', bt_count('request_log'), $rl);
backup_restore($name, '', [], null, $err);
t_eq('полное восстановление вернуло журнал', bt_count('request_log'), 20000);
bt_fresh_settings();

// ---- неизвестные таблицы и колонки, старая схема ----
$L = explode("\n", rtrim(gzdecode($plain), "\n"));
$H = json_decode($L[0], true);
foreach ($H['tables'] as &$m) {
    if ($m['t'] === 'overrides') { $m['c'][] = 'future_col'; }
    if ($m['t'] === 'grace_users') { $m['c'] = array_values(array_diff($m['c'], ['retry_ts'])); $gi = array_search('retry_ts', db_table_columns($p, $drv, 'grace_users'), true); }
}
unset($m);
$H['tables'][] = ['t' => 'zz_future', 'c' => ['a'], 'n' => 1];
$out = [json_encode($H, BK_JSON)];
$cur = '';
for ($i = 1; $i < count($L); $i++) {
    $l = $L[$i];
    if ($l[0] === '{') {
        $o = json_decode($l, true);
        if (isset($o['end'])) { $o['rows']++; $o['n']['zz_future'] = 1; $out[] = '{"t":"zz_future"}'; $out[] = '["x"]'; $out[] = json_encode($o, BK_JSON); continue; }
        $cur = $o['t'];
        $out[] = $l;
        continue;
    }
    if ($cur === 'overrides') { $v = json_decode($l, true); $v[] = 'будущее'; $l = json_encode($v, BK_JSON); }
    $out[] = $l;
}
file_put_contents(backup_dir() . '/future.json.gz', gzencode(implode("\n", $out) . "\n"));
$pv = backup_preview('future.json.gz', '', $err);
t_ok('просмотр показывает пропуски', is_array($pv) && $pv['skip_tables'] === ['zz_future'] && ($pv['skip_cols']['overrides'] ?? null) === ['future_col'], $err . json_encode($pv['skip_cols'] ?? null));
$res = backup_restore('future.json.gz', '', [], null, $err);
t_ok('файл от более новой версии восстанавливается с пропусками', is_array($res) && $res['skipped'] === ['zz_future'], $err);
t_eq('строки оверрайдов на месте', bt_count('overrides'), 30);
backup_restore($name, '', [], null, $err);
bt_fresh_settings();

// ---- логин ----
$H = json_decode($L[0], true);
$H['login'] = ['user' => 'other', 'hash' => password_hash('pw2', PASSWORD_DEFAULT)];
set_setting('admin_gate_key', 'kabcdefg=' . str_repeat('1', 24));
file_put_contents(backup_dir() . '/login.json.gz', gzencode(implode("\n", array_merge([json_encode($H, BK_JSON)], array_slice($L, 1))) . "\n"));
$pv = backup_preview('login.json.gz', '', $err);
t_ok('просмотр: другой логин', is_array($pv) && $pv['user'] === 'other' && $pv['same_login'] === false);
$res = backup_restore('login.json.gz', '', ['keep_login' => true], null, $err);
$c = include $cfgPath;
bt_fresh_settings();
t_ok('«оставить вход»: config и ключ входа не тронуты', is_array($res) && $res['relogin'] === false && $c['admin_user'] === 'admin' && setting('admin_gate_key') === 'kabcdefg=' . str_repeat('1', 24) && $res['gate'] === null, $err);
$res = backup_restore('login.json.gz', '', ['keep_login' => false], null, $err);
$c = (static function ($f) { return include $f; })($cfgPath);
bt_fresh_settings();
t_ok('вход из бэкапа: логин сменён, нужен перелогин', is_array($res) && $res['relogin'] === true && $c['admin_user'] === 'other' && password_verify('pw2', $c['admin_pass_hash']), $err);
t_ok('подключение к базе не тронуто', $c['db'] === cfg()['db']);
t_ok('ключ входа из бэкапа (там его нет) — снят', setting('admin_gate_key', '') === '' && $res['gate'] === '');
t_ok('временных config-файлов не осталось', !glob(dirname($cfgPath) . '/.config-*'));
file_put_contents($cfgPath, $cfgOrig);

// ---- хранение, блокировка, расписание ----
for ($i = 0; $i < 9; $i++) backup_create('auto', '', true, null, $err);
t_eq('по расписанию хранится 7', count(array_filter(backup_list(), static function ($b) { return $b['kind'] === 'auto'; })), 7);
t_ok('снимков не больше 3', count(array_filter(backup_list(), static function ($b) { return $b['kind'] === 'pre'; })) <= 3);
t_ok('ручные не удаляются', count(array_filter(backup_list(), static function ($b) { return $b['kind'] === 'manual'; })) >= 3);
$lk = backup_lock($err);
t_ok('вторая операция при занятой блокировке — отказ', backup_create('manual', '', true, null, $err) === '' && strpos($err, 'Уже идёт') !== false);
backup_unlock($lk);
set_setting('backup_auto', '0');
bt_fresh_settings();
t_ok('выключено — задачи в планировщике нет', !isset(cron_tasks()['backup']));
set_setting('backup_auto', '1');
bt_fresh_settings();
t_ok('включено — задача раз в сутки, только в фоне', (cron_tasks()['backup'] ?? null) === ['Бэкап по расписанию', 86400, true]);
set_setting('backup_auto_pass', 'авто');
$n0 = count(backup_list());
t_eq('задача планировщика создаёт бэкап с паролем', cron_task_backup([]), '');
$a = array_values(array_filter(backup_list(), static function ($b) { return $b['kind'] === 'auto'; }));
t_ok('авто-бэкап с паролем зашифрован', count($a) === 7 && in_array(true, array_column($a, 'enc'), true));

// ---- через админку ----
foreach (glob(backup_dir() . '/*') as $f) @unlink($f);
set_setting('update_state', json_encode(['checked_at' => time()]));
$mw = t_serve($tt_root, $tt_root . '/tools/tests/_router.php', [], ['opcache.enable' => '0']);
$jar = t_admin_login($mw);
[$c, , $page] = t_http('GET', $mw . '/admin/index.php?tab=backup', [], null, $jar);
t_ok('вкладка открывается', $c === 200 && strpos($page, 'id="bkCreate"') !== false && strpos($page, '</html>') !== false);
preg_match('~name="csrf" value="([0-9a-f]+)"~', $page, $m);
$csrf = $m[1] ?? '';
$post = static function (string $q, array $f) use ($mw, $jar, $csrf) {
    return t_http('POST', $mw . '/admin/index.php?ajax=' . $q, ['content-type' => 'application/x-www-form-urlencoded'], http_build_query($f + ['csrf' => $csrf]), $jar);
};
[$c, $hd, $b] = $post('bk_create', ['lite' => '0']);
$ls = array_values(array_filter(explode("\n", $b)));
$fin = json_decode((string) end($ls), true);
t_ok('создание: поток NDJSON с прогрессом и итогом', $c === 200 && strpos($hd['content-type'] ?? '', 'ndjson') !== false && count($ls) >= 2 && !empty($fin['ok']) && $fin['dl'] === '', $b);
$nm = (string) ($fin['name'] ?? '');
[$c, , $page] = t_http('GET', $mw . '/admin/index.php?tab=backup', [], null, $jar);
t_ok('новый бэкап в таблице', strpos($page, 'data-name="' . $nm . '"') !== false);
[$c, $hd, $file] = t_http('GET', $mw . '/admin/' . backup_dl_url($nm, $csrf), [], null, $jar);
t_ok('скачивание отдаёт файл байт в байт', $c === 200 && $file === file_get_contents(backup_dir() . '/' . $nm) && strpos($hd['content-disposition'] ?? '', 'attachment') !== false);
[$c] = t_http('GET', $mw . '/admin/index.php?bk_dl=' . rawurlencode($nm) . '&k=bad', [], null, $jar);
t_eq('скачивание с неверной подписью — 404', $c, 404);
[$c, , $b] = t_http('GET', $mw . '/admin/index.php?tab=backup', [], null, $jar);
t_ok('общий CSRF-токен в ссылках скачивания не светится', strpos($b, 'bk_dl=') !== false && !preg_match('~bk_dl=[^"]*csrf=~', $b));
[$c, , $b] = t_http('GET', $mw . '/admin/' . backup_dl_url($nm, $csrf));
t_ok('без входа — не файл', strpos($b, '{"submw_backup"') === false && $b !== $file);
[$c, $hd, $b] = $post('bk_create', ['lite' => '1', 'dl' => '1', 'pass' => 'x']);
$ls = array_values(array_filter(explode("\n", $b)));
$fin = json_decode((string) end($ls), true);
t_ok('«Скачать»: временный файл не в списке', !empty($fin['ok']) && strpos((string) $fin['dl'], 'bk_dl=') !== false && !array_filter(backup_list(), static function ($x) { return $x['kind'] === 'dl'; }));
[$c, $hd, $f2] = t_http('GET', $mw . '/admin/' . $fin['dl'], [], null, $jar);
t_ok('«Скачать»: файл отдан и удалён с сервера', $c === 200 && strncmp($f2, BK_MAGIC, 8) === 0 && strpos($hd['content-disposition'] ?? '', 'submw-backup-') !== false && !glob(backup_dir() . '/*-dl-*'));
$bnd = '----bk' . bin2hex(random_bytes(4));
$mp = "--{$bnd}\r\nContent-Disposition: form-data; name=\"csrf\"\r\n\r\n{$csrf}\r\n--{$bnd}\r\nContent-Disposition: form-data; name=\"file\"; filename=\"x.json.gz\"\r\nContent-Type: application/gzip\r\n\r\n{$file}\r\n--{$bnd}--\r\n";
[$c, , $b] = t_http('POST', $mw . '/admin/index.php?ajax=bk_upload', ['content-type' => 'multipart/form-data; boundary=' . $bnd], $mp, $jar);
$up = json_decode($b, true);
t_ok('загрузка файла', !empty($up['ok']) && strpos((string) $up['name'], '-upload-') !== false, $b);
$mp2 = str_replace($file, 'не бэкап', $mp);
[$c, , $b] = t_http('POST', $mw . '/admin/index.php?ajax=bk_upload', ['content-type' => 'multipart/form-data; boundary=' . $bnd], $mp2, $jar);
t_ok('загрузка мусора — отказ', ($j = json_decode($b, true)) && empty($j['ok']));
[$c, , $b] = $post('bk_preview', ['name' => $up['name']]);
$pv = json_decode($b, true);
t_ok('просмотр через админку', !empty($pv['ok']) && $pv['user'] === 'admin' && $pv['same_login'] === true, $b . ' / ' . file_get_contents($cfgPath));
[$c, , $b] = $post('bk_restore', ['name' => $up['name'], 'word' => 'да']);
t_ok('без слова подтверждения — отказ', strpos($b, '"ok":false') !== false);
[$c, , $b] = t_http('POST', $mw . '/admin/index.php?ajax=bk_restore', ['content-type' => 'application/x-www-form-urlencoded'], http_build_query(['name' => $up['name'], 'word' => 'восстановить', 'csrf' => 'bad']), $jar);
t_eq('без csrf — 400', $c, 400);
[$c, , $b] = $post('bk_restore', ['name' => $up['name'], 'word' => ' Восстановить ']);
$ls = array_values(array_filter(explode("\n", $b)));
$fin = json_decode((string) end($ls), true);
t_ok('восстановление через админку', !empty($fin['ok']) && $fin['relogin'] === false, $b);
[$c, , $page] = t_http('GET', $mw . '/admin/index.php?tab=backup', [], null, $jar);
t_ok('сессия жива после восстановления без смены входа', strpos($page, 'id="bkCreate"') !== false);
// Бэкап с другим логином: после восстановления текущая сессия закрыта.
$H = json_decode(strtok(gzdecode($file), "\n"), true);
$H['login'] = ['user' => 'other', 'hash' => password_hash('pw2', PASSWORD_DEFAULT)];
$LL = explode("\n", rtrim(gzdecode($file), "\n"));
$LL[0] = json_encode($H, BK_JSON);
file_put_contents(backup_dir() . '/login2.json.gz', gzencode(implode("\n", $LL) . "\n"));
$jar2 = t_admin_login($mw);
[$c, , $b] = $post('bk_restore', ['name' => 'login2.json.gz', 'word' => 'восстановить']);
$ls = array_values(array_filter(explode("\n", $b)));
$fin = json_decode((string) end($ls), true);
t_ok('восстановление с другим логином', !empty($fin['ok']) && $fin['relogin'] === true && $fin['next'] === '', $b);
[$c, , $page] = t_http('GET', $mw . '/admin/index.php?tab=backup', [], null, $jar);
t_ok('после смены входа — снова форма входа', strpos($page, 'id="password"') !== false && strpos($page, 'id="bkCreate"') === false);
[$c, , $page] = t_http('GET', $mw . '/admin/index.php?tab=backup', [], null, $jar2);
t_ok('и другая открытая сессия тоже закрыта', strpos($page, 'id="password"') !== false && strpos($page, 'id="bkCreate"') === false);
file_put_contents($cfgPath, $cfgOrig);
$jar = t_admin_login($mw);
[$c, , $page] = t_http('GET', $mw . '/admin/index.php?tab=backup', [], null, $jar);
preg_match('~name="csrf" value="([0-9a-f]+)"~', $page, $m);
[$c, $hd] = t_http('POST', $mw . '/admin/index.php', ['content-type' => 'application/x-www-form-urlencoded'], http_build_query(['csrf' => $m[1], 'action' => 'bk_delete', 'name' => 'login2.json.gz']), $jar);
t_ok('удаление', $c === 302 && !is_file(backup_dir() . '/login2.json.gz'));
[$c, $hd] = t_http('POST', $mw . '/admin/index.php', ['content-type' => 'application/x-www-form-urlencoded'], http_build_query(['csrf' => $m[1], 'action' => 'bk_delete', 'name' => '../config.php']), $jar);
t_ok('удаление вне папки — нет', is_file($cfgPath));
[$c, , $page] = t_http('GET', $mw . '/admin/index.php?tab=sysinfo', [], null, $jar);
t_ok('«О системе» открывается со строкой бэкапа в планировщике', $c === 200 && strpos($page, 'Бэкап по расписанию') !== false);
file_put_contents($cfgPath, $cfgBox);

t_done();
