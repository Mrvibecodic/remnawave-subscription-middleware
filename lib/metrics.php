<?php

function ddl_metrics_minute($drv) {
    if ($drv === 'mysql') {
        return "CREATE TABLE IF NOT EXISTS metrics_minute (
            minute_ts BIGINT UNSIGNED NOT NULL,
            hits BIGINT UNSIGNED NOT NULL DEFAULT 0,
            hits_sub BIGINT UNSIGNED NOT NULL DEFAULT 0,
            dur_ms_sum BIGINT UNSIGNED NOT NULL DEFAULT 0,
            dur_ms_max INT UNSIGNED NOT NULL DEFAULT 0,
            mem_max BIGINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (minute_ts)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    }
    return "CREATE TABLE IF NOT EXISTS metrics_minute (
        minute_ts INTEGER NOT NULL PRIMARY KEY,
        hits INTEGER NOT NULL DEFAULT 0,
        hits_sub INTEGER NOT NULL DEFAULT 0,
        dur_ms_sum INTEGER NOT NULL DEFAULT 0,
        dur_ms_max INTEGER NOT NULL DEFAULT 0,
        mem_max INTEGER NOT NULL DEFAULT 0
    )";
}

function ddl_metrics_peak($drv) {
    if ($drv === 'mysql') {
        return "CREATE TABLE IF NOT EXISTS metrics_peak (
            minute_ts BIGINT UNSIGNED NOT NULL,
            hits BIGINT UNSIGNED NOT NULL DEFAULT 0,
            baseline INT UNSIGNED NOT NULL DEFAULT 0,
            dur_ms_max INT UNSIGNED NOT NULL DEFAULT 0,
            mem_max BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (minute_ts)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    }
    return "CREATE TABLE IF NOT EXISTS metrics_peak (
        minute_ts INTEGER NOT NULL PRIMARY KEY,
        hits INTEGER NOT NULL DEFAULT 0,
        baseline INTEGER NOT NULL DEFAULT 0,
        dur_ms_max INTEGER NOT NULL DEFAULT 0,
        mem_max INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )";
}

function ensure_metrics_tables() {
    static $done = false;
    if ($done) return true;
    if (!($p = db())) return false;
    if (setting('metrics_tables', '') !== '2') {
        try {
            $drv = db_driver();
            $p->exec(ddl_metrics_minute($drv));
            $p->exec(ddl_metrics_peak($drv));
            set_setting('metrics_tables', '2');
        } catch (Throwable $e) {
            error_log('submw ensure_metrics_tables: ' . $e->getMessage());
            return false;
        }
    }
    if (setting('metrics_subcol', '') !== '1') {
        try { $p->exec('ALTER TABLE metrics_minute ADD COLUMN hits_sub ' . (db_driver() === 'mysql' ? 'BIGINT UNSIGNED' : 'INTEGER') . ' NOT NULL DEFAULT 0'); } catch (Throwable $e) {}
        set_setting('metrics_subcol', '1');
    }
    $done = true;
    return true;
}

function metrics_peak_factor() {
    $v = (float) setting('metrics_peak_factor', '3');
    return $v >= 1.5 ? $v : 3.0;
}

function metrics_peak_floor() {
    $v = (int) setting('metrics_peak_floor', '30');
    return $v >= 5 ? $v : 30;
}

function metrics_tick($dur_ms, $mem_bytes, $is_sub = false) {
    if (!ensure_metrics_tables()) return;
    if (!($p = db())) return;
    $minute = time() - (time() % 60);
    $dur    = (int) max(0, round($dur_ms));
    $mem    = (int) max(0, $mem_bytes);
    $sub    = $is_sub ? 1 : 0;
    try {
        if (db_driver() === 'mysql') {
            $sql = 'INSERT INTO metrics_minute (minute_ts, hits, hits_sub, dur_ms_sum, dur_ms_max, mem_max)
                    VALUES (?, 1, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE hits = hits + 1,
                        hits_sub = hits_sub + VALUES(hits_sub),
                        dur_ms_sum = dur_ms_sum + VALUES(dur_ms_sum),
                        dur_ms_max = GREATEST(dur_ms_max, VALUES(dur_ms_max)),
                        mem_max = GREATEST(mem_max, VALUES(mem_max))';
        } else {
            $sql = 'INSERT INTO metrics_minute (minute_ts, hits, hits_sub, dur_ms_sum, dur_ms_max, mem_max)
                    VALUES (?, 1, ?, ?, ?, ?)
                    ON CONFLICT(minute_ts) DO UPDATE SET hits = hits + 1,
                        hits_sub = hits_sub + excluded.hits_sub,
                        dur_ms_sum = dur_ms_sum + excluded.dur_ms_sum,
                        dur_ms_max = MAX(dur_ms_max, excluded.dur_ms_max),
                        mem_max = MAX(mem_max, excluded.mem_max)';
        }
        $p->prepare($sql)->execute([$minute, $sub, $dur, $dur, $mem]);
        metrics_detect_peak($p, $minute);
        if (random_int(1, 400) === 1) metrics_prune($p);
    } catch (Throwable $e) {
        error_log('submw metrics_tick: ' . $e->getMessage());
    }
}

function metrics_detect_peak($p, $minute) {
    try {
        $st = $p->prepare('SELECT hits, dur_ms_max, mem_max FROM metrics_minute WHERE minute_ts = ?');
        $st->execute([$minute]);
        $cur = $st->fetch();
        if (!$cur) return;
        $hits = (int) $cur['hits'];
        $floor = metrics_peak_floor();
        if ($hits < $floor) return;

        $st = $p->prepare('SELECT AVG(hits) FROM metrics_minute WHERE minute_ts >= ? AND minute_ts < ?');
        $st->execute([$minute - 3600, $minute]);
        $baseline = (float) $st->fetchColumn();
        $threshold = max($floor, $baseline * metrics_peak_factor());
        if ($hits < $threshold) return;

        if (db_driver() === 'mysql') {
            $sql = 'INSERT INTO metrics_peak (minute_ts, hits, baseline, dur_ms_max, mem_max)
                    VALUES (?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE hits = VALUES(hits), baseline = VALUES(baseline),
                        dur_ms_max = VALUES(dur_ms_max), mem_max = VALUES(mem_max)';
        } else {
            $sql = 'INSERT INTO metrics_peak (minute_ts, hits, baseline, dur_ms_max, mem_max)
                    VALUES (?, ?, ?, ?, ?)
                    ON CONFLICT(minute_ts) DO UPDATE SET hits = excluded.hits, baseline = excluded.baseline,
                        dur_ms_max = excluded.dur_ms_max, mem_max = excluded.mem_max';
        }
        $p->prepare($sql)->execute([$minute, $hits, (int) round($baseline), (int) $cur['dur_ms_max'], (int) $cur['mem_max']]);
    } catch (Throwable $e) {
        error_log('submw metrics_detect_peak: ' . $e->getMessage());
    }
}

function metrics_prune($p) {
    try {
        $p->prepare('DELETE FROM metrics_minute WHERE minute_ts < ?')->execute([time() - 14 * 86400]);
        $p->prepare('DELETE FROM metrics_peak WHERE minute_ts < ?')->execute([time() - 90 * 86400]);
    } catch (Throwable $e) {}
}

function metrics_window_hits($p, $seconds) {
    try {
        $st = $p->prepare('SELECT COALESCE(SUM(hits),0) FROM metrics_minute WHERE minute_ts >= ?');
        $st->execute([time() - $seconds]);
        return (int) $st->fetchColumn();
    } catch (Throwable $e) { return 0; }
}

function metrics_window_sub($p, $seconds) {
    try {
        $st = $p->prepare('SELECT COALESCE(SUM(hits_sub),0) FROM metrics_minute WHERE minute_ts >= ?');
        $st->execute([time() - $seconds]);
        return (int) $st->fetchColumn();
    } catch (Throwable $e) { return 0; }
}

function metrics_load_summary() {
    $out = [
        'm1' => 0, 'm5' => 0, 'm60' => 0, 'h24' => 0, 'today' => 0,
        'm1_sub' => 0, 'm5_sub' => 0, 'm60_sub' => 0, 'h24_sub' => 0, 'today_sub' => 0,
        'avg_ms' => 0, 'max_ms_60' => 0, 'mem_max_60' => 0, 'rpm_60' => 0.0,
    ];
    if (!($p = db())) return $out;
    if (!ensure_metrics_tables()) return $out;
    $now = time();
    $out['m1']  = metrics_window_hits($p, 60);
    $out['m5']  = metrics_window_hits($p, 300);
    $out['m60'] = metrics_window_hits($p, 3600);
    $out['h24'] = metrics_window_hits($p, 86400);
    $out['m1_sub']  = metrics_window_sub($p, 60);
    $out['m5_sub']  = metrics_window_sub($p, 300);
    $out['m60_sub'] = metrics_window_sub($p, 3600);
    $out['h24_sub'] = metrics_window_sub($p, 86400);
    $tzoff    = isset($_COOKIE['tzoff']) ? max(-720, min(840, (int) $_COOKIE['tzoff'])) * 60 : (int) date('Z');
    $nowLocal = $now + $tzoff;
    $dayStart = $nowLocal - ($nowLocal % 86400) - $tzoff;
    try {
        $st = $p->prepare('SELECT COALESCE(SUM(hits),0) h, COALESCE(SUM(hits_sub),0) s FROM metrics_minute WHERE minute_ts >= ?');
        $st->execute([$dayStart]); $row = $st->fetch(); $out['today'] = (int) ($row['h'] ?? 0); $out['today_sub'] = (int) ($row['s'] ?? 0);

        $st = $p->prepare('SELECT COALESCE(SUM(dur_ms_sum),0) s, COALESCE(SUM(hits),0) h, COALESCE(MAX(dur_ms_max),0) mx, COALESCE(MAX(mem_max),0) mm FROM metrics_minute WHERE minute_ts >= ?');
        $st->execute([$now - 3600]);
        $r = $st->fetch();
        $h = (int) ($r['h'] ?? 0);
        $out['avg_ms']     = $h > 0 ? (int) round(((int) $r['s']) / $h) : 0;
        $out['max_ms_60']  = (int) ($r['mx'] ?? 0);
        $out['mem_max_60'] = (int) ($r['mm'] ?? 0);
        $out['rpm_60']     = round($out['m60'] / 60, 1);
    } catch (Throwable $e) {}
    return $out;
}

function metrics_minute_series($minutes = 60) {
    $series = [];
    if (!($p = db())) return $series;
    if (!ensure_metrics_tables()) return $series;
    $now   = time();
    $start = ($now - ($now % 60)) - ($minutes - 1) * 60;
    $map = [];
    try {
        $st = $p->prepare('SELECT minute_ts, hits, hits_sub, dur_ms_max FROM metrics_minute WHERE minute_ts >= ? ORDER BY minute_ts ASC');
        $st->execute([$start]);
        foreach ($st as $row) $map[(int) $row['minute_ts']] = $row;
    } catch (Throwable $e) {}
    for ($t = $start; $t <= $now - ($now % 60); $t += 60) {
        $series[] = [
            'ts'   => $t,
            'hits' => isset($map[$t]) ? (int) $map[$t]['hits'] : 0,
            'sub'  => isset($map[$t]) ? (int) $map[$t]['hits_sub'] : 0,
            'ms'   => isset($map[$t]) ? (int) $map[$t]['dur_ms_max'] : 0,
        ];
    }
    return $series;
}

function metrics_recent_peaks($limit = 200) {
    $rows = [];
    if (!($p = db())) return $rows;
    if (!ensure_metrics_tables()) return $rows;
    try {
        $st = $p->query('SELECT minute_ts, hits, baseline, dur_ms_max, mem_max FROM metrics_peak ORDER BY minute_ts DESC LIMIT ' . (int) $limit);
        foreach ($st as $row) $rows[] = $row;
    } catch (Throwable $e) {}
    return $rows;
}

function metrics_db_info() {
    $out = ['driver' => db_driver(), 'size' => 0, 'location' => '', 'tables' => []];
    if ($out['driver'] === 'sqlite') {
        $c = db_conf();
        $path = (is_array($c) && !empty($c['path'])) ? $c['path'] : default_db_path();
        $out['location'] = $path;
        $size = 0;
        foreach (['', '-wal', '-shm'] as $suf) {
            $f = $path . $suf;
            if (is_file($f)) $size += (int) @filesize($f);
        }
        $out['size'] = $size;
    } else {
        $c = db_conf();
        $out['location'] = (is_array($c) ? (($c['host'] ?? '') . ':' . ($c['port'] ?? 3306) . '/' . ($c['name'] ?? '')) : '');
        if ($p = db()) {
            try {
                $st = $p->query('SELECT COALESCE(SUM(data_length + index_length),0) FROM information_schema.tables WHERE table_schema = DATABASE()');
                $out['size'] = (int) $st->fetchColumn();
            } catch (Throwable $e) {}
        }
    }
    if ($p = db()) {
        foreach (['overrides', 'request_log', 'webhook_log', 'forward_log', 'grace_users', 'metrics_minute', 'metrics_peak'] as $t) {
            try { $out['tables'][$t] = (int) $p->query("SELECT COUNT(*) FROM $t")->fetchColumn(); }
            catch (Throwable $e) { $out['tables'][$t] = null; }
        }
    }
    return $out;
}

function metrics_system_info() {
    $load = function_exists('sys_getloadavg') ? @sys_getloadavg() : null;
    $cores = 0;
    if (is_readable('/proc/cpuinfo')) {
        $cores = (int) preg_match_all('/^processor\s*:/mi', (string) @file_get_contents('/proc/cpuinfo'));
    }
    if ($cores < 1) {
        $nproc = @shell_exec('nproc 2>/dev/null');
        if ($nproc !== null && (int) $nproc > 0) $cores = (int) $nproc;
    }
    return [
        'php_version' => PHP_VERSION,
        'sapi'        => PHP_SAPI,
        'os'          => php_uname('s') . ' ' . php_uname('r') . ' (' . php_uname('m') . ')',
        'server'      => $_SERVER['SERVER_SOFTWARE'] ?? '',
        'load'        => is_array($load) ? $load : null,
        'cores'       => $cores ?: null,
        'mem_limit'   => ini_get('memory_limit'),
        'mem_peak'    => memory_get_peak_usage(true),
        'opcache'     => (function_exists('opcache_get_status') && @opcache_get_status(false)) ? true : false,
        'curl'        => function_exists('curl_version'),
    ];
}

function metrics_fmt_bytes($n) {
    $n = (float) $n;
    $u = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    while ($n >= 1024 && $i < count($u) - 1) { $n /= 1024; $i++; }
    return ($i === 0 ? (int) $n : round($n, $n >= 100 ? 0 : 1)) . ' ' . $u[$i];
}
