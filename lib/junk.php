<?php

function junk_ensure() {
    static $done = false;
    if ($done) return;
    $done = true;
    if (!($p = db())) return;
    try {
        if (db_driver() === 'mysql') {
            $p->exec("CREATE TABLE IF NOT EXISTS junk_hits (
                path VARCHAR(191) NOT NULL,
                hits BIGINT UNSIGNED NOT NULL DEFAULT 0,
                last_ts INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (path)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } else {
            $p->exec("CREATE TABLE IF NOT EXISTS junk_hits (
                path TEXT NOT NULL PRIMARY KEY,
                hits INTEGER NOT NULL DEFAULT 0,
                last_ts INTEGER NOT NULL DEFAULT 0
            )");
        }
    } catch (Throwable $e) { error_log('submw junk table: ' . $e->getMessage()); }
}

function junk_whitelist() {
    $a = json_decode((string) setting('junk_whitelist', '[]'), true);
    $out = [];
    if (is_array($a)) foreach ($a as $s) { $s = trim((string) $s); if ($s !== '') $out[] = $s; }
    return $out;
}

// Путь исключён из «мусорных» — обрабатываем его как обычную подписку.
// Поддержка префикса со звёздочкой в конце: "shop/*".
function junk_excluded($path) {
    $path = (string) $path;
    if ($path === '') return false;
    foreach (junk_whitelist() as $w) {
        if ($w === $path) return true;
        if (substr($w, -1) === '*') {
            $pre = rtrim($w, '*');
            if ($pre !== '' && strncmp($path, $pre, strlen($pre)) === 0) return true;
        }
    }
    return false;
}

function junk_record($path) {
    $path = mb_substr((string) $path, 0, 191);
    if ($path === '') return;
    junk_ensure();
    if (!($p = db())) return;
    $now = time();
    try {
        if (db_driver() === 'mysql') {
            $st = $p->prepare('INSERT INTO junk_hits (path, hits, last_ts) VALUES (?, 1, ?) ON DUPLICATE KEY UPDATE hits = hits + 1, last_ts = VALUES(last_ts)');
        } else {
            $st = $p->prepare('INSERT INTO junk_hits (path, hits, last_ts) VALUES (?, 1, ?) ON CONFLICT(path) DO UPDATE SET hits = hits + 1, last_ts = excluded.last_ts');
        }
        $st->execute([$path, $now]);
        if (function_exists('random_int') && random_int(1, 500) === 1) junk_prune($p);
    } catch (Throwable $e) {}
}

function junk_prune($p) {
    try {
        $c = (int) $p->query('SELECT COUNT(*) FROM junk_hits')->fetchColumn();
        if ($c <= 1000) return;
        $rm = $c - 800;
        if (db_driver() === 'mysql') {
            $st = $p->prepare('DELETE FROM junk_hits ORDER BY last_ts ASC LIMIT ' . (int) $rm);
            $st->execute();
        } else {
            $p->exec('DELETE FROM junk_hits WHERE path IN (SELECT path FROM junk_hits ORDER BY last_ts ASC LIMIT ' . (int) $rm . ')');
        }
    } catch (Throwable $e) {}
}

function junk_top($limit = 100) {
    junk_ensure();
    if (!($p = db())) return [];
    try {
        $st = $p->query('SELECT path, hits, last_ts FROM junk_hits ORDER BY hits DESC, last_ts DESC LIMIT ' . (int) $limit);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }
}

function junk_forget($path) {
    junk_ensure();
    if (!($p = db())) return;
    try { $p->prepare('DELETE FROM junk_hits WHERE path = ?')->execute([mb_substr((string) $path, 0, 191)]); }
    catch (Throwable $e) {}
}

function junk_whitelist_add($path) {
    $path = trim((string) $path);
    if ($path === '') return;
    $a = junk_whitelist();
    if (!in_array($path, $a, true)) {
        $a[] = $path;
        set_setting('junk_whitelist', json_encode(array_values($a), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}

function junk_whitelist_del($path) {
    $path = trim((string) $path);
    $a = array_values(array_filter(junk_whitelist(), fn($x) => $x !== $path));
    set_setting('junk_whitelist', json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}
