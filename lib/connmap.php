<?php

const CMAP_TTL = 900;
const CMAP_USER_ROWS = 3000;

function cmap_presets() {
    $keep = function_exists('sess_keep_days') ? sess_keep_days() : 30;
    return array_values(array_filter([1, 7, 30], fn($d) => $d <= $keep));
}

// Свой период — целые дни по поясу браузера: границы приходят из JS, сервер только обрезает их сроком хранения.
function cmap_period($p, $from = 0, $to = 0, $now = null) {
    $now = $now ?? time();
    $keep = function_exists('sess_keep_days') ? sess_keep_days() : 30;
    $min = $now - $keep * 86400;
    if ($p === 'c') {
        $from = max($min, (int) $from);
        $to = min($now, (int) $to);
        if ($to <= $from) return null;
        return ['key' => 'cmap_c', 'p' => 'c', 'from' => $from, 'to' => $to];
    }
    $d = (int) $p;
    if (!in_array($d, cmap_presets(), true)) $d = 1;
    return ['key' => 'cmap_p' . $d, 'p' => (string) $d, 'from' => $now - $d * 86400, 'to' => $now];
}

function cmap_names($maxAge) {
    $c = admin_cache_get('cmap_names', $maxAge);
    if (is_array($c) && is_array($c['u'] ?? null)) return $c['u'];
    $err = '';
    $out = [];
    $list = remnawave_all_users($err, null, ['id', 'username', 'shortUuid']);
    foreach ($list as $u) {
        if (!is_array($u) || !isset($u['id'])) continue;
        $out[(string) (int) $u['id']] = [mb_substr((string) ($u['username'] ?? ''), 0, 64), (string) ($u['shortUuid'] ?? '')];
    }
    if ($err !== '') {
        $old = admin_cache_get('cmap_names', PHP_INT_MAX);
        return is_array($old) && is_array($old['u'] ?? null) ? $out + $old['u'] : $out;
    }
    admin_cache_put('cmap_names', ['u' => $out]);
    return $out;
}

function cmap_nodes() {
    $out = [];
    foreach (sess_nodes_known() as $uuid => $n) {
        $h = is_array($n['h'] ?? null) ? $n['h'] : [];
        $out[(string) $uuid] = [(string) ($n['n'] ?? ''), (string) ($n['cc'] ?? ''), (string) ($h[0] ?? '')];
    }
    return $out;
}

function cmap_build(array $per, $namesAge = CMAP_TTL) {
    if (!sess_ensure() || !($p = db())) return null;
    $mysql = db_driver() === 'mysql';
    $every = sess_every();
    $dur = $mysql ? '(LEAST(last_ts, ?) - GREATEST(start_ts, ?) + ?)' : '(MIN(last_ts, ?) - MAX(start_ts, ?) + ?)';
    $from = (int) $per['from'];
    $to = (int) $per['to'];
    $R = []; $Ri = []; $N = []; $Ni = []; $U = []; $Ui = []; $G = []; $A = [];
    $known = cmap_nodes();
    $uix = static function ($uid) use (&$U, &$Ui) {
        if (!isset($Ui[$uid])) { $Ui[$uid] = count($U); $U[] = [$uid, '', '', 0, 0]; }
        return $Ui[$uid];
    };
    try {
        $st = $p->prepare("SELECT uid, COUNT(DISTINCT ip) AS ips, MAX(last_ts) AS ls FROM conn_sess WHERE last_ts >= ? AND start_ts <= ? GROUP BY uid");
        $st->execute([$from, $to]);
        while ($r = $st->fetch(PDO::FETCH_NUM)) {
            $i = $uix((int) $r[0]);
            $U[$i][3] = (int) $r[1];
            $U[$i][4] = min($to, (int) $r[2]);
        }
        $st->closeCursor();
        $st = $p->prepare("SELECT uid, cc, sub, node, asn, MAX(org) AS org, COUNT(DISTINCT ip) AS ips, SUM($dur) AS secs FROM conn_sess WHERE last_ts >= ? AND start_ts <= ? GROUP BY uid, cc, sub, node, asn");
        $st->execute([$to, $from, $every, $from, $to]);
        while ($r = $st->fetch(PDO::FETCH_NUM)) {
            $rk = $r[1] . '|' . $r[2];
            if (!isset($Ri[$rk])) { $Ri[$rk] = count($R); $R[] = [(string) $r[1], (string) $r[2]]; }
            $nk = (string) $r[3];
            if (!isset($Ni[$nk])) { $Ni[$nk] = count($N); $kn = $known[$nk] ?? ['', '', '']; $N[] = [$kn[2] !== '' ? $kn[2] : ($kn[0] !== '' ? $kn[0] : mb_substr($nk, 0, 8)), $kn[1], $kn[0], $nk]; }
            $asn = (int) $r[4];
            if ($asn > 0 && !isset($A[$asn])) $A[$asn] = (string) $r[5];
            $G[] = [$uix((int) $r[0]), $Ri[$rk], $Ni[$nk], $asn, (int) $r[6], max(0, (int) $r[7])];
        }
        $st->closeCursor();
    } catch (Throwable $e) {
        error_log('submw cmap: ' . $e->getMessage());
        return null;
    }
    $names = $U ? cmap_names($namesAge) : [];
    foreach ($U as $i => $u) {
        $nm = $names[(string) $u[0]] ?? null;
        if ($nm) { $U[$i][1] = $nm[0]; $U[$i][2] = $nm[1]; }
    }
    return ['ts' => time(), 'nk' => count($known), 'p' => $per['p'], 'from' => $from, 'to' => $to, 'U' => $U, 'R' => $R, 'N' => $N, 'G' => $G, 'A' => (object) $A];
}

function cmap_lock() {
    if (!function_exists('cron_data_dir')) return null;
    $f = @fopen(cron_data_dir() . '/.cmap.lock', 'c');
    if (!$f) return null;
    if (!flock($f, LOCK_EX | LOCK_NB)) { fclose($f); return false; }
    return $f;
}

// Устаревшая сводка отдаётся сразу, пересчёт — после ответа; без сводки считаем на месте.
function cmap_view(array $per, &$stale = false) {
    $stale = false;
    $c = admin_cache_get($per['key'], PHP_INT_MAX);
    $same = is_array($c) && ($per['p'] !== 'c' || ((int) ($c['from'] ?? 0) === $per['from'] && (int) ($c['to'] ?? 0) === $per['to']));
    if ($same && time() - (int) ($c['ts'] ?? 0) < CMAP_TTL) return $c;
    if ($same && $per['p'] !== 'c') {
        $stale = true;
        register_shutdown_function('cmap_refresh_after_response', $per['p']);
        return $c;
    }
    $lk = cmap_lock();
    $d = cmap_build($per);
    if ($lk) { flock($lk, LOCK_UN); fclose($lk); }
    if ($d !== null) admin_cache_put($per['key'], $d);
    return $d;
}

function cmap_refresh_after_response($p) {
    if (session_status() === PHP_SESSION_ACTIVE) @session_write_close();
    @ignore_user_abort(true);
    if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
    $lk = cmap_lock();
    if ($lk === false) return;
    $per = cmap_period((string) $p);
    $c = admin_cache_get($per['key'], PHP_INT_MAX);
    if (!is_array($c) || time() - (int) ($c['ts'] ?? 0) >= CMAP_TTL) {
        $d = cmap_build($per);
        if ($d !== null) admin_cache_put($per['key'], $d);
    }
    if ($lk) { flock($lk, LOCK_UN); fclose($lk); }
}

function cmap_user($uid, array $per) {
    if (!sess_ensure() || !($p = db())) return null;
    try {
        $st = $p->prepare('SELECT node, ip, cc, asn, org, sub, start_ts, last_ts FROM conn_sess WHERE uid = ? AND last_ts >= ? AND start_ts <= ? ORDER BY last_ts DESC LIMIT ' . CMAP_USER_ROWS);
        $st->execute([(int) $uid, (int) $per['from'], (int) $per['to']]);
        $rows = [];
        while ($r = $st->fetch(PDO::FETCH_NUM)) $rows[] = [(string) $r[0], (string) $r[1], (string) $r[2], (int) $r[3], (string) $r[4], (string) $r[5], (int) $r[6], (int) $r[7]];
        $st->closeCursor();
    } catch (Throwable $e) { return null; }
    $city = function_exists('geonet_cities') ? geonet_cities(array_values(array_unique(array_column($rows, 1)))) : [];
    return ['rows' => $rows, 'city' => (object) $city, 'cut' => count($rows) >= CMAP_USER_ROWS, 'every' => sess_every(), 'nodes' => (object) cmap_nodes()];
}

// Сводка на тысячи клиентов — мегабайты JSON; веб-сервер по умолчанию application/json не сжимает.
function cmap_send($json) {
    $json = (string) $json;
    $gz = strlen($json) > 32768 && function_exists('gzencode') && !ini_get('zlib.output_compression') && !headers_sent()
        && stripos((string) ($_SERVER['HTTP_ACCEPT_ENCODING'] ?? ''), 'gzip') !== false;
    if ($gz) {
        $z = gzencode($json, 5);
        if ($z !== false) { header('Content-Encoding: gzip'); header('Vary: Accept-Encoding'); echo $z; return; }
    }
    echo $json;
}
