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
t_eq('флаг версии', setting('hostov_table', ''), '2');
$r = hostov_rows_for('AbCdEfGhIjKlMnOp');
t_eq('строка на месте, новые колонки пустые', [$r[0]['val'] ?? '', $r[0]['host_apat'] ?? null, $r[0]['miss_app'] ?? null], ['safari', '', '']);
[$b, $h] = hostov_apply('vless://u@de1:443?security=reality&type=tcp&fp=chrome#DE', 'base64', $r);
t_ok('старая строка работает по адресу', count($h) === 1 && strpos($b, 'fp=safari') !== false, $b);
t_eq('статус считается', hostov_list('AbCdEfGhIjKlMnOp')[0]['st'] ?? '', 'wait');

t_done();
