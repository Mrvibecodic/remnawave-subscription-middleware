<?php
declare(strict_types=1);
require __DIR__ . '/_boot.php';
// Вторая половина идёт отдельным процессом: _boot.php даёт ей пустую базу, как на новом сервере.
$phase = ($argv[1] ?? '') === 'T' ? 'T' : 'S';
$share = backup_dir_ready() . '/..';
$p = db(); $drv = db_driver();
function schema_sig(): array {
    $p = db(); $drv = db_driver(); $o = [];
    foreach (array_intersect(db_list_tables($p, $drv), backup_known_tables()) as $t) {
        $cols = db_table_columns($p, $drv, $t); sort($cols);
        $idx = [];
        if ($drv === 'mysql') { foreach ($p->query('SHOW INDEX FROM `' . $t . '`') as $r) $idx[$r['Key_name']][] = $r['Column_name']; }
        else { foreach ($p->query("PRAGMA index_list('" . $t . "')") as $r) { if (strpos($r['name'], 'sqlite_autoindex') === 0) { $nm = 'auto:' . implode(',', array_column($p->query("PRAGMA index_info('" . $r['name'] . "')")->fetchAll(), 'name')); } else $nm = $r['name']; $idx[$nm] = array_column($p->query("PRAGMA index_info('" . $r['name'] . "')")->fetchAll(), 'name'); } }
        ksort($idx);
        $o[$t] = ['c' => $cols, 'i' => $idx];
    }
    ksort($o);
    return $o;
}
function cnts(): array { $p = db(); $drv = db_driver(); $o = []; foreach (array_intersect(db_list_tables($p, $drv), backup_known_tables()) as $t) $o[$t] = (int) $p->query('SELECT COUNT(*) FROM ' . bk_q($t, $drv))->fetchColumn(); ksort($o); return $o; }
if ($phase === 'S') {
    backup_ensure_schema();
    $have = array_intersect(db_list_tables($p, $drv), backup_known_tables());
    $missing = array_values(array_diff(backup_known_tables(), $have));
    t_eq('S: на чистой базе созданы все таблицы из исходников', $missing, []);
    if ($drv === 'mysql') $p->exec('SET FOREIGN_KEY_CHECKS=0');
    $fail = [];
    foreach ($have as $t) {
        if ((int) $p->query('SELECT COUNT(*) FROM ' . bk_q($t, $drv))->fetchColumn() > 0) continue;
        $cols = []; $vals = [];
        if ($drv === 'mysql') {
            foreach ($p->query('SHOW COLUMNS FROM `' . $t . '`') as $c) {
                if (strpos($c['Extra'], 'auto_increment') !== false) continue;
                $ty = strtolower($c['Type']);
                if (preg_match("~^enum\('([^']*)'~", $ty, $m)) $v = $m[1];
                elseif (preg_match('~int|decimal|float|double~', $ty)) $v = 7;
                elseif (preg_match('~timestamp|datetime~', $ty)) $v = '2026-01-02 03:04:05';
                elseif ($ty === 'date') $v = '2026-01-02';
                elseif (preg_match('~(?:var)?char\((\d+)\)~', $ty, $m)) $v = substr('xfill-' . $t, 0, (int) $m[1]);
                else $v = 'fill «' . $t . '» 🚀';
                $cols[] = '`' . $c['Field'] . '`'; $vals[] = $v;
            }
        } else {
            foreach ($p->query("PRAGMA table_info('" . $t . "')") as $c) {
                $ty = strtoupper((string) $c['type']);
                $v = strpos($ty, 'INT') !== false ? 7 : ('fill «' . $t . '» 🚀');
                $cols[] = '"' . $c['name'] . '"'; $vals[] = $v;
            }
        }
        try { $p->prepare('INSERT INTO ' . bk_q($t, $drv) . ' (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')->execute($vals); }
        catch (Throwable $e) { $fail[$t] = $e->getMessage(); }
    }
    if ($drv === 'mysql') $p->exec('SET FOREIGN_KEY_CHECKS=1');
    t_eq('S: каждая таблица получила строку', $fail, []);
    $c = cnts();
    t_ok('S: пустых таблиц нет', !array_filter($c, static function ($n) { return $n === 0; }), json_encode(array_keys(array_filter($c, static function ($n) { return $n === 0; }))));
    $n = backup_create('manual', '', false, null, $err);
    t_ok('S: бэкап', $n !== '', $err);
    copy(backup_dir() . '/' . $n, $share . '/full.gz');
    file_put_contents($share . '/full.json', json_encode(['c' => $c, 's' => schema_sig(), 'drv' => $drv]));
    $proc = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_reporting=-1', __FILE__, 'T'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2));
    $out = stream_get_contents($pipes[1]);
    $er = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    echo $out;
    t_ok('T: вторая половина без ошибок PHP', $code === 0 && trim($er) === '', $er);
    @unlink($share . '/full.gz');
    @unlink($share . '/full.json');
} else {
    $want = json_decode((string) file_get_contents($share . '/full.json'), true);
    t_ok('T: в новой базе пока только таблицы установки', count(array_intersect(db_list_tables($p, $drv), backup_known_tables())) < count($want['c']));
    copy($share . '/full.gz', backup_dir() . '/full.json.gz');
    $r = backup_restore('full.json.gz', '', [], null, $err);
    t_ok('T: восстановлено, ничего не пропущено', is_array($r) && $r['skipped'] === [], $err . json_encode($r['skipped'] ?? null));
    $got = cnts();
    $skip = array_merge(backup_cache_tables(), ['login_attempts']);
    $w = array_diff_key($want['c'], array_flip($skip));
    $g = array_diff_key($got, array_flip($skip));
    $g['settings'] = $w['settings'] ?? 0;
    t_eq('T: строки во всех таблицах как в источнике (кроме кэшей)', $g, $w);
    foreach ($skip as $t) if (isset($got[$t]) && $t !== 'login_attempts') t_eq('T: кэш ' . $t . ' пуст', $got[$t], 0);
    if ($want['drv'] === $drv) {
        $s = schema_sig();
        $diff = [];
        foreach ($want['s'] as $t => $sig) if (($s[$t] ?? null) != $sig) $diff[$t] = ['src' => $sig, 'dst' => $s[$t] ?? null];
        t_eq('T: структура таблиц (колонки и индексы) совпадает с источником', $diff, []);
    }
}
t_done();
