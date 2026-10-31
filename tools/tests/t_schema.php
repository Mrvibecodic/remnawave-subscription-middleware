<?php
declare(strict_types=1);

require __DIR__ . '/_boot.php';

$p = db();
$drv = db_driver();

// Реестр схемы знает все таблицы, которые создают модули
foreach (['ensure_metrics_tables', 'ensure_forward_log', 'ensure_panel_write_log', 'ensure_grace_table', 'ensure_chat_tables', 'ensure_wg_user_cache',
          'ensure_reqlog_hwid', 'whlog_ensure_meta', 'rep_ensure', 'sess_ensure', 'apistat_ensure', 'pr_snap_ensure', 'geonet_ensure', 'junk_ensure',
          'addsub_ensure', 'addsub_swap_ensure', 'app_ensure', 'chan_ensure', 'fc_ensure', 'squadconf_ensure', 'squadconf_cache_ensure', 'wglease_ensure',
          'ensure_login_attempts', 'gc_ensure'] as $f) {
    if (function_exists($f)) { try { $f(); } catch (Throwable $e) {} }
}
admin_cache_ensure($p);
$reg = db_schema_tables($drv);
t_eq('реестр: каждая таблица модулей в нём есть', array_values(array_diff(db_list_tables($p, $drv), $reg)), []);
t_eq('реестр: таблицы бэкапа берутся из него', backup_known_tables(), $reg);
t_ok('реестр: без заполнения настроек', !array_filter(db_schema_ddl($drv), static fn($s) => (bool) preg_match('~^\s*INSERT\b~i', $s)));

// Перенос: SQLite-источник со сжатым отчётом, битым текстом и снимком правил → текущая база
$srcPath = $tt_root . '/data/src.sqlite';
@unlink($srcPath);
$from = ['driver' => 'sqlite', 'path' => $srcPath];
$src = pdo_connect($from, $e);
foreach (db_schema_ddl('sqlite') as $sql) { try { $src->exec($sql); } catch (Throwable $e) {} }
$json = json_encode(['report' => str_repeat('данные ', 40), 'n' => 1], JSON_UNESCAPED_UNICODE);
$gz = gzencode($json, 6);
t_ok('исходный архив отчёта — не UTF-8', !preg_match('//u', $gz));
$src->prepare('INSERT INTO rep_raw (ts, short_uuid, dev, hwid, inst, plat, client, model, os, osv, st, why, bytes, gz, body) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
    ->execute([time(), 'AbCdEfGhIjKlMnOp', 'd1', '', '', 'android', 'clod', '', '', '', 200, '', strlen($json), 1, $gz]);
$broken = "/sub/\xC3\x28bad";
$src->prepare("INSERT INTO request_log (ip, short_uuid, path, user_agent) VALUES ('1.2.3.4', 'AbCdEfGhIjKlMnOp', ?, 'v2rayN/7.0')")->execute([$broken]);
$src->prepare("INSERT INTO panel_rules_snap (ts, kind, note, n, panel_upd, hash, body) VALUES (?, 'manual', 'до переноса', 1, NULL, ?, ?)")
    ->execute([time(), str_repeat('a', 32), '{"rules":[]}']);
$src->exec("INSERT INTO settings (k, v) VALUES ('pr_snap_table', '1')");
$src = null;

// Приёмник без таблицы снимков, как новая база MySQL: прежде перенос её пропускал, а флаг из settings врал, что она есть.
$p->exec('DROP TABLE panel_rules_snap');
$to = db_conf();
t_ok('перенос: прошёл', db_migrate($from, $to, $err), $err);
$dst = pdo_connect($to, $e);
$body = (string) $dst->query("SELECT body FROM rep_raw WHERE short_uuid = 'AbCdEfGhIjKlMnOp'")->fetchColumn();
t_eq('перенос: сжатый отчёт читается', @gzdecode($body), $json);
$path = (string) $dst->query("SELECT path FROM request_log WHERE ip = '1.2.3.4'")->fetchColumn();
t_ok('перенос: битый текст дошёл', $path !== '' && strpos($path, '/sub/') === 0, $path);
if ($drv === 'mysql') t_ok('перенос: в MySQL текст стал валидным UTF-8', (bool) preg_match('//u', $path));
t_ok('перенос: снимок правил переехал', (int) $dst->query("SELECT COUNT(*) FROM panel_rules_snap WHERE note = 'до переноса'")->fetchColumn() === 1);
$dst = null;

// Бэкап и восстановление в текущую базу не трогают двоичные данные
unset($GLOBALS['submw_settings_cache']);
$name = backup_create('manual', '', false, null, $err);
t_ok('бэкап: создан', $name !== '', $err);
db()->exec('DELETE FROM rep_raw');
$res = backup_restore($name, '', ['keep_login' => true], null, $err);
t_ok('восстановление: прошло', $res !== null, $err);
$body = (string) db()->query("SELECT body FROM rep_raw WHERE short_uuid = 'AbCdEfGhIjKlMnOp'")->fetchColumn();
t_eq('восстановление: сжатый отчёт читается', @gzdecode($body), $json);

t_done();
