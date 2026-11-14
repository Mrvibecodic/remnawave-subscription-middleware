<?php
declare(strict_types=1);

require __DIR__ . '/_boot.php';

// Таблица первой версии (без шаблонов адреса и SNI) дополняется колонками, строки остаются
$mysql = db_driver() === 'mysql';
db()->exec('DROP TABLE IF EXISTS host_ovr');
db()->exec($mysql
    ? "CREATE TABLE host_ovr (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, short_uuid VARCHAR(64) NOT NULL, user_id BIGINT NULL, username VARCHAR(64) NOT NULL DEFAULT '', host_uuid VARCHAR(64) NOT NULL, host_name VARCHAR(255) NOT NULL DEFAULT '', host_rmk VARCHAR(255) NOT NULL DEFAULT '', host_addr VARCHAR(255) NOT NULL DEFAULT '', host_port INT NOT NULL DEFAULT 0, host_sec VARCHAR(16) NOT NULL DEFAULT '', host_tr VARCHAR(16) NOT NULL DEFAULT '', param VARCHAR(16) NOT NULL, pv VARCHAR(64) NOT NULL DEFAULT '', val VARCHAR(255) NOT NULL, till INT UNSIGNED NULL, created_at INT UNSIGNED NOT NULL, vat INT UNSIGNED NOT NULL, last_hit INT UNSIGNED NULL, last_app VARCHAR(128) NOT NULL DEFAULT '', last_miss INT UNSIGNED NULL, UNIQUE KEY uq_host_ovr (short_uuid, host_uuid, param)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    : "CREATE TABLE host_ovr (id INTEGER PRIMARY KEY AUTOINCREMENT, short_uuid TEXT NOT NULL, user_id INTEGER NULL, username TEXT NOT NULL DEFAULT '', host_uuid TEXT NOT NULL, host_name TEXT NOT NULL DEFAULT '', host_rmk TEXT NOT NULL DEFAULT '', host_addr TEXT NOT NULL DEFAULT '', host_port INTEGER NOT NULL DEFAULT 0, host_sec TEXT NOT NULL DEFAULT '', host_tr TEXT NOT NULL DEFAULT '', param TEXT NOT NULL, pv TEXT NOT NULL DEFAULT '', val TEXT NOT NULL, till INTEGER NULL, created_at INTEGER NOT NULL, vat INTEGER NOT NULL, last_hit INTEGER NULL, last_app TEXT NOT NULL DEFAULT '', last_miss INTEGER NULL, UNIQUE (short_uuid, host_uuid, param))");
db()->exec("INSERT INTO host_ovr (short_uuid, host_uuid, host_name, host_rmk, host_addr, host_port, host_sec, host_tr, param, val, created_at, vat) VALUES ('AbCdEfGhIjKlMnOp', 'h1', 'DE', 'DE', 'de1', 443, 'reality', 'TCP', 'fp', 'safari', 1, 1)");
set_setting('hostov_table', '1');
set_setting('hostov_n', '1');

t_ok('таблица первой версии дополнена', hostov_ensure());
t_eq('флаг версии', setting('hostov_table', ''), '4');
$r = hostov_rows_for('AbCdEfGhIjKlMnOp');
t_eq('строка на месте, новые колонки пустые', [$r[0]['val'] ?? '', $r[0]['host_apat'] ?? null, $r[0]['miss_app'] ?? null, $r[0]['host_proto'] ?? null, (array_key_exists('mval', $r[0]) ? $r[0]['mval'] : 'x'), (array_key_exists('na_at', $r[0]) ? $r[0]['na_at'] : 'x'), $r[0]['na_fmt'] ?? null], ['safari', '', '', '', null, null, '']);
[$b, $h] = hostov_apply('vless://u@de1:443?security=reality&type=tcp&fp=chrome#DE', 'base64', $r);
t_ok('старая строка работает по адресу', count($h) === 1 && strpos($b, 'fp=safari') !== false, $b);
t_eq('статус считается', hostov_list('AbCdEfGhIjKlMnOp')[0]['st'] ?? '', 'wait');

// Таблица второй версии (с шаблонами адреса и SNI, без масок) — тоже дополняется
db()->exec('DROP TABLE IF EXISTS host_ovr');
db()->exec($mysql
    ? "CREATE TABLE host_ovr (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, short_uuid VARCHAR(64) NOT NULL, user_id BIGINT NULL, username VARCHAR(64) NOT NULL DEFAULT '', host_uuid VARCHAR(64) NOT NULL, host_name VARCHAR(255) NOT NULL DEFAULT '', host_rmk VARCHAR(255) NOT NULL DEFAULT '', host_addr VARCHAR(255) NOT NULL DEFAULT '', host_apat VARCHAR(255) NOT NULL DEFAULT '', host_port INT NOT NULL DEFAULT 0, host_sec VARCHAR(16) NOT NULL DEFAULT '', host_tr VARCHAR(16) NOT NULL DEFAULT '', host_sni VARCHAR(255) NOT NULL DEFAULT '', host_snipat VARCHAR(255) NOT NULL DEFAULT '', param VARCHAR(16) NOT NULL, pv VARCHAR(64) NOT NULL DEFAULT '', val VARCHAR(255) NOT NULL, till INT UNSIGNED NULL, created_at INT UNSIGNED NOT NULL, vat INT UNSIGNED NOT NULL, last_hit INT UNSIGNED NULL, last_app VARCHAR(128) NOT NULL DEFAULT '', last_miss INT UNSIGNED NULL, miss_app VARCHAR(128) NOT NULL DEFAULT '', UNIQUE KEY uq_host_ovr (short_uuid, host_uuid, param)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    : "CREATE TABLE host_ovr (id INTEGER PRIMARY KEY AUTOINCREMENT, short_uuid TEXT NOT NULL, user_id INTEGER NULL, username TEXT NOT NULL DEFAULT '', host_uuid TEXT NOT NULL, host_name TEXT NOT NULL DEFAULT '', host_rmk TEXT NOT NULL DEFAULT '', host_addr TEXT NOT NULL DEFAULT '', host_apat TEXT NOT NULL DEFAULT '', host_port INTEGER NOT NULL DEFAULT 0, host_sec TEXT NOT NULL DEFAULT '', host_tr TEXT NOT NULL DEFAULT '', host_sni TEXT NOT NULL DEFAULT '', host_snipat TEXT NOT NULL DEFAULT '', param TEXT NOT NULL, pv TEXT NOT NULL DEFAULT '', val TEXT NOT NULL, till INTEGER NULL, created_at INTEGER NOT NULL, vat INTEGER NOT NULL, last_hit INTEGER NULL, last_app TEXT NOT NULL DEFAULT '', last_miss INTEGER NULL, miss_app TEXT NOT NULL DEFAULT '', UNIQUE (short_uuid, host_uuid, param))");
db()->exec("INSERT INTO host_ovr (short_uuid, host_uuid, host_name, host_rmk, host_addr, host_apat, host_port, host_sec, host_tr, param, val, created_at, vat) VALUES ('AbCdEfGhIjKlMnOp', 'h1', 'DE', 'DE', 'de1', 'de1', 443, 'reality', 'tcp', 'fp', 'firefox', 1, 1)");
set_setting('hostov_table', '2');
t_ok('вторая версия дополнена', hostov_ensure(true));
t_eq('флаг версии после второй', setting('hostov_table', ''), '4');
$r = hostov_rows_for('AbCdEfGhIjKlMnOp', true);
t_eq('строка второй версии работает', [$r[0]['val'] ?? '', array_key_exists('mval', $r[0]) ? $r[0]['mval'] : 'x'], ['firefox', null]);
[$b, $h] = hostov_apply('vless://u@de1:443?security=reality&type=tcp&fp=chrome#DE', 'base64', $r);
t_ok('и подменяется', count($h) === 1 && strpos($b, 'fp=firefox') !== false, $b);

// Третья версия (до подмен на приложение): старые подмены остаются общими, ключ таблицы меняется, строки и отметки целы
$v3 = hostov_ddl(db_driver());
$v3[0] = str_replace(
    $mysql ? ["            app_k VARCHAR(32) NOT NULL DEFAULT '', app_lbl VARCHAR(64) NOT NULL DEFAULT '',\n", 'UNIQUE KEY uq_host_ovr (short_uuid, app_k, host_uuid, param)']
           : ["        app_k TEXT NOT NULL DEFAULT '', app_lbl TEXT NOT NULL DEFAULT '',\n", 'UNIQUE (short_uuid, app_k, host_uuid, param)'],
    $mysql ? ['', 'UNIQUE KEY uq_host_ovr (short_uuid, host_uuid, param)'] : ['', 'UNIQUE (short_uuid, host_uuid, param)'],
    $v3[0]
);
db()->exec('DROP TABLE IF EXISTS host_ovr');
foreach ($v3 as $sql) { try { db()->exec($sql); } catch (Throwable $e) {} }
t_ok('таблица третьей версии собрана без колонок приложения', !db_has_cols(db(), 'host_ovr', ['app_k']) && !hostov_key_ok(db(), db_driver()));
db()->exec("INSERT INTO host_ovr (id, short_uuid, host_uuid, host_name, host_rmk, host_addr, host_apat, host_port, host_sec, host_tr, host_proto, param, val, till, created_at, vat, last_hit, last_app) VALUES (7, 'AbCdEfGhIjKlMnOp', 'h1', 'DE', 'DE', 'de1', 'de1', 443, 'reality', 'tcp', 'vless', 'fp', 'safari', NULL, 100, 100, 200, 'Happ 3.6 · iOS')");
db()->exec("INSERT INTO host_ovr (id, short_uuid, host_uuid, host_name, host_rmk, host_addr, host_apat, host_port, host_sec, host_tr, host_proto, param, val, mval, till, created_at, vat) VALUES (9, 'AbCdEfGhIjKlMnOp', 'h1', 'DE', 'DE', 'de1', 'de1', 443, 'reality', 'tcp', 'vless', 'frag', 'std', '{\"type\":\"fragment\",\"settings\":{\"packets\":\"tlshello\",\"length\":\"100-200\",\"delay\":\"10-20\"}}', 4102444800, 110, 110)");
$link = 'vless://u@de1:443?security=reality&type=tcp&fp=chrome#DE';
set_setting('hostov_table', '3');
t_ok('третья версия обновлена', hostov_ensure(true));
t_eq('флаг версии после третьей', setting('hostov_table', ''), '4');
t_ok('ключ таблицы — с приложением', hostov_key_ok(db(), db_driver()));
$r = hostov_rows_for('AbCdEfGhIjKlMnOp', true);
t_eq('старые подмены — общие, id, срок и отметки на месте', array_map(static fn($x) => [(int) $x['id'], $x['param'], $x['val'], (string) $x['app_k'], (string) $x['app_lbl'], $x['till'] === null ? null : (int) $x['till'], $x['last_hit'] === null ? null : (int) $x['last_hit'], (string) $x['last_app'], (int) $x['created_at']], $r),
    [[7, 'fp', 'safari', '', '', null, 200, 'Happ 3.6 · iOS', 100], [9, 'frag', 'std', '', '', 4102444800, null, '', 110]]);
t_eq('общие подмены уходят любому приложению', hostov_pick($r, 'FlClash/0.8.85'), $r);
[$b, $h] = hostov_apply($link, 'base64', $r);
t_ok('тело с общими подменами — как до обновления', count($h) === 2 && strpos($b, 'fp=safari') !== false && strpos($b, 'fm=') !== false, $b);
$H = ['uuid' => 'h1', 'name' => 'DE', 'rmk' => 'DE', 'addr' => 'de1', 'apat' => 'de1', 'port' => 443, 'sec' => 'reality', 'trk' => 'tcp', 'proto' => 'vless', 'fp' => 'chrome'];
t_ok('рядом с общей ставится подмена на приложение', hostov_save('AbCdEfGhIjKlMnOp', $H, 'fp', 'firefox', 0, 1, 'u', $e, null, $w, [['k' => 'happ', 'l' => 'Happ']]), (string) $e);
t_ok('новая строка получила следующий id', (int) db()->query("SELECT id FROM host_ovr WHERE app_k = 'happ'")->fetchColumn() > 9);

// Флаг версии переехал со старыми настройками (перенос базы, бэкап), а таблица уже новая — повтор ничего не ломает
set_setting('hostov_table', '3');
t_ok('повторный запуск на новой таблице', hostov_ensure(true));
t_eq('строки целы после повтора', (int) db()->query('SELECT COUNT(*) FROM host_ovr')->fetchColumn(), 3);
t_eq('флаг снова верный', setting('hostov_table', ''), '4');

// Два запроса обновляют таблицу одновременно (первые обращения после обновления кода): оба целы, строки на месте
db()->exec('DROP TABLE IF EXISTS host_ovr');
foreach ($v3 as $sql) { try { db()->exec($sql); } catch (Throwable $e) {} }
for ($i = 1; $i <= 40; $i++) db()->exec("INSERT INTO host_ovr (short_uuid, host_uuid, host_name, host_rmk, host_addr, host_apat, host_port, host_sec, host_tr, host_proto, param, val, created_at, vat) VALUES ('U{$i}', 'h1', 'DE', 'DE', 'de1', 'de1', 443, 'reality', 'tcp', 'vless', 'fp', 'safari', 100, 100)");
set_setting('hostov_table', '3');
$wk = $tt_root . '/data/hv_worker.php';
file_put_contents($wk, "<?php\n\$_SERVER['REQUEST_URI'] = '/'; \$_SERVER['REMOTE_ADDR'] = '127.0.0.1';\nrequire " . var_export($tt_root . '/lib.php', true) . ";\necho hostov_ensure(true) ? 'ok' : 'fail';\n");
$procs = []; $pipes = [];
for ($i = 0; $i < 4; $i++) $procs[$i] = proc_open([PHP_BINARY, $wk], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i]);
$res = [];
foreach ($procs as $i => $pr) { $res[] = stream_get_contents($pipes[$i][1]) . stream_get_contents($pipes[$i][2]); proc_close($pr); }
unset($GLOBALS['submw_settings_cache']);
t_eq('одновременное обновление: все четыре запроса прошли', $res, ['ok', 'ok', 'ok', 'ok']);
t_ok('одновременное обновление: ключ новый, строки целы', hostov_key_ok(db(), db_driver()) && (int) db()->query("SELECT COUNT(*) FROM host_ovr WHERE app_k = ''")->fetchColumn() === 40);

// Перенос базы со старой таблицей: в новой базе таблица уже нового вида, подмены переезжают общими
$srcPath = $tt_root . '/data/hv_src.sqlite';
@unlink($srcPath);
$from = ['driver' => 'sqlite', 'path' => $srcPath];
$src = pdo_connect($from, $e);
foreach (db_schema_ddl('sqlite') as $sql) { if (stripos($sql, 'host_ovr') !== false) continue; try { $src->exec($sql); } catch (Throwable $e) {} }
$old = hostov_ddl('sqlite');
$old[0] = str_replace(["        app_k TEXT NOT NULL DEFAULT '', app_lbl TEXT NOT NULL DEFAULT '',\n", 'UNIQUE (short_uuid, app_k, host_uuid, param)'], ['', 'UNIQUE (short_uuid, host_uuid, param)'], $old[0]);
foreach ($old as $sql) $src->exec($sql);
$src->exec("INSERT INTO host_ovr (short_uuid, host_uuid, host_name, host_rmk, host_addr, host_apat, host_port, host_sec, host_tr, host_proto, param, val, created_at, vat) VALUES ('MovedShortUuid01', 'h1', 'DE', 'DE', 'de1', 'de1', 443, 'reality', 'tcp', 'vless', 'fp', 'firefox', 100, 100)");
$src->exec("INSERT INTO settings (k, v) VALUES ('hostov_table', '3')");
$src->exec("INSERT INTO settings (k, v) VALUES ('hostov_n', '1')");
$src = null;
t_ok('перенос базы со старой таблицей прошёл', db_migrate($from, db_conf(), $err), (string) $err);
unset($GLOBALS['submw_settings_cache']);
$r = hostov_rows_for('MovedShortUuid01', true);
t_eq('перенос: подмена на месте и общая, ключ новый', [count($r), (string) ($r[0]['app_k'] ?? 'x'), $r[0]['val'] ?? '', hostov_ensure(true), hostov_key_ok(db(), db_driver()), setting('hostov_table', '')], [1, '', 'firefox', true, true, '4']);

t_done();
