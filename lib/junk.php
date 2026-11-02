<?php

const JUNK_LOG_KEEP  = 500;
const JUNK_SUM_KEEP  = 1000;
const JUNK_PAIR_KEEP = 5000;
const JUNK_RULES_MAX = 500;
const JUNK_HOUR_KEEP = 8;

function junk_ddl($drv) {
    if ($drv === 'mysql') {
        $t = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        return [
            "CREATE TABLE IF NOT EXISTS junk_hits (
                path VARCHAR(191) NOT NULL, hits BIGINT UNSIGNED NOT NULL DEFAULT 0,
                last_ts INT UNSIGNED NOT NULL DEFAULT 0, PRIMARY KEY (path)
            )" . $t,
            "CREATE TABLE IF NOT EXISTS junk_ip (
                ip VARCHAR(45) NOT NULL, hits BIGINT UNSIGNED NOT NULL DEFAULT 0, paths INT UNSIGNED NOT NULL DEFAULT 0,
                first_ts INT UNSIGNED NOT NULL DEFAULT 0, last_ts INT UNSIGNED NOT NULL DEFAULT 0,
                last_path VARCHAR(191) NOT NULL DEFAULT '', last_ua VARCHAR(255) NOT NULL DEFAULT '',
                PRIMARY KEY (ip), KEY idx_jip_last (last_ts)
            )" . $t,
            "CREATE TABLE IF NOT EXISTS junk_pair (
                path VARCHAR(191) NOT NULL, ip VARCHAR(45) NOT NULL, ts INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (path, ip), KEY idx_jpair_ts (ts)
            )" . $t,
            "CREATE TABLE IF NOT EXISTS junk_log (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, ts INT UNSIGNED NOT NULL DEFAULT 0,
                ip VARCHAR(45) NOT NULL DEFAULT '', path VARCHAR(191) NOT NULL DEFAULT '', ua VARCHAR(255) NOT NULL DEFAULT '',
                kind VARCHAR(8) NOT NULL DEFAULT 'pass', code SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                resp VARCHAR(16) NOT NULL DEFAULT '', rule VARCHAR(191) NOT NULL DEFAULT '',
                PRIMARY KEY (id)
            )" . $t,
            "CREATE TABLE IF NOT EXISTS junk_hour (
                h INT UNSIGNED NOT NULL, n INT UNSIGNED NOT NULL DEFAULT 0, cut INT UNSIGNED NOT NULL DEFAULT 0,
                dry INT UNSIGNED NOT NULL DEFAULT 0, PRIMARY KEY (h)
            )" . $t,
            "CREATE TABLE IF NOT EXISTS junk_rules (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT, kind VARCHAR(8) NOT NULL, val VARCHAR(191) NOT NULL,
                resp VARCHAR(16) NOT NULL DEFAULT 'copy404', mode VARCHAR(8) NOT NULL DEFAULT 'dry',
                active TINYINT NOT NULL DEFAULT 1, until_ts INT UNSIGNED NOT NULL DEFAULT 0,
                hits BIGINT UNSIGNED NOT NULL DEFAULT 0, last_ts INT UNSIGNED NOT NULL DEFAULT 0,
                src VARCHAR(8) NOT NULL DEFAULT 'manual', created_ts INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (id), KEY idx_jrules_kind (kind, val)
            )" . $t,
        ];
    }
    return [
        "CREATE TABLE IF NOT EXISTS junk_hits (path TEXT NOT NULL PRIMARY KEY, hits INTEGER NOT NULL DEFAULT 0, last_ts INTEGER NOT NULL DEFAULT 0)",
        "CREATE TABLE IF NOT EXISTS junk_ip (ip TEXT NOT NULL PRIMARY KEY, hits INTEGER NOT NULL DEFAULT 0, paths INTEGER NOT NULL DEFAULT 0,
            first_ts INTEGER NOT NULL DEFAULT 0, last_ts INTEGER NOT NULL DEFAULT 0, last_path TEXT NOT NULL DEFAULT '', last_ua TEXT NOT NULL DEFAULT '')",
        "CREATE INDEX IF NOT EXISTS idx_jip_last ON junk_ip(last_ts)",
        "CREATE TABLE IF NOT EXISTS junk_pair (path TEXT NOT NULL, ip TEXT NOT NULL, ts INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (path, ip))",
        "CREATE INDEX IF NOT EXISTS idx_jpair_ts ON junk_pair(ts)",
        "CREATE TABLE IF NOT EXISTS junk_log (id INTEGER PRIMARY KEY AUTOINCREMENT, ts INTEGER NOT NULL DEFAULT 0, ip TEXT NOT NULL DEFAULT '',
            path TEXT NOT NULL DEFAULT '', ua TEXT NOT NULL DEFAULT '', kind TEXT NOT NULL DEFAULT 'pass', code INTEGER NOT NULL DEFAULT 0,
            resp TEXT NOT NULL DEFAULT '', rule TEXT NOT NULL DEFAULT '')",
        "CREATE TABLE IF NOT EXISTS junk_hour (h INTEGER NOT NULL PRIMARY KEY, n INTEGER NOT NULL DEFAULT 0, cut INTEGER NOT NULL DEFAULT 0, dry INTEGER NOT NULL DEFAULT 0)",
        "CREATE TABLE IF NOT EXISTS junk_rules (id INTEGER PRIMARY KEY AUTOINCREMENT, kind TEXT NOT NULL, val TEXT NOT NULL,
            resp TEXT NOT NULL DEFAULT 'copy404', mode TEXT NOT NULL DEFAULT 'dry', active INTEGER NOT NULL DEFAULT 1, until_ts INTEGER NOT NULL DEFAULT 0,
            hits INTEGER NOT NULL DEFAULT 0, last_ts INTEGER NOT NULL DEFAULT 0, src TEXT NOT NULL DEFAULT 'manual', created_ts INTEGER NOT NULL DEFAULT 0)",
        "CREATE INDEX IF NOT EXISTS idx_jrules_kind ON junk_rules(kind, val)",
    ];
}

function junk_ensure() {
    static $ok = null;
    if ($ok !== null) return $ok;
    if (!function_exists('db') || !($p = db())) return false;
    if (setting('junk_tables', '') === '2') return $ok = true;
    try {
        foreach (junk_ddl(db_driver()) as $sql) $p->exec($sql);
        set_setting('junk_tables', '2');
        $ok = true;
    } catch (Throwable $e) { error_log('submw junk tables: ' . $e->getMessage()); $ok = false; }
    return $ok;
}

// ---- что считается мусором и что правила не трогают ----

function junk_whitelist() {
    $a = json_decode((string) setting('junk_whitelist', '[]'), true);
    $out = [];
    if (is_array($a)) foreach ($a as $s) { $s = trim((string) $s); if ($s !== '') $out[] = $s; }
    return $out;
}

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

function junk_short_len_enabled() { return setting('junk_short_len', '0') === '1'; }

function junk_short_len_mismatch($path) {
    if (!junk_short_len_enabled()) return false;
    if (!function_exists('panel_short_uuid_len')) return false;
    $len = panel_short_uuid_len();
    if ($len <= 0) return false;
    $seg = (string) $path;
    $p = strpos($seg, '/');
    if ($p !== false) $seg = substr($seg, 0, $p);
    if ($seg === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $seg)) return false;
    return strlen($seg) !== $len;
}

// Статика и служебные адреса страницы подписки (в т.ч. под префиксом): страница грузит их сама, резать нельзя никогда.
function junk_protected($path) {
    $path = ltrim((string) $path, '/');
    if ($path === '') return true;
    if (preg_match('~(^|/)(assets|_next|static|locales)(/|$)~i', $path)) return true;
    if (preg_match('~^(\.well-known|cdn-cgi)(/|$)~i', $path)) return true;
    return (bool) preg_match('~(^|/)(favicon[^/]*|apple-touch-icon[^/]*|site\.webmanifest|manifest(\.[a-z0-9-]+)?\.(json|webmanifest)|browserconfig\.xml)$~i', $path);
}

// *.php и скрытые файлы (.env, .git) nginx прослойки отвечает сам, до PHP они не доходят — в набор не входят.
function junk_set_paths_ext() { return ['php3', 'php4', 'php5', 'php7', 'phtml', 'asp', 'aspx', 'jsp', 'cgi', 'pl', 'sh', 'bash', 'sql', 'bak', 'old', 'ini', 'conf', 'cfg', 'pem', 'key', 'crt', 'pfx', 'p12', 'so', 'dll', 'exe']; }
function junk_set_paths_dir() { return ['wp-admin', 'wp-login', 'wp-content', 'wp-includes', 'phpmyadmin', 'cgi-bin']; }

function junk_set_bots() {
    return ['googlebot', 'bingbot', 'bingpreview', 'slurp', 'duckduckbot', 'baiduspider', 'yandexbot', 'yandeximages', 'sogou', 'seznambot', 'mojeek', 'qwantify',
        'exabot', 'ia_archiver', 'petalbot', 'bytespider', 'applebot', 'ahrefsbot', 'semrushbot', 'mj12bot', 'dotbot', 'blexbot', 'dataforseo', 'gptbot', 'google-extended',
        'ccbot', 'claudebot', 'amazonbot', 'perplexitybot', 'zgrab', 'masscan', 'nmap', 'nuclei', 'censys', 'shodan', 'expanse', 'internetmeasurement',
        'netsystemsresearch', 'leakix', 'projectdiscovery', 'paloaltonetworks', 'l9scan'];
}

function junk_set_paths_match($path) {
    static $re = null;
    if ($re === null) {
        $q = static function (array $a) { return implode('|', array_map(static function ($s) { return preg_quote($s, '~'); }, $a)); };
        $re = '~\.(' . $q(junk_set_paths_ext()) . ')\d?($|/)|(^|/)(' . $q(junk_set_paths_dir()) . ')([/.]|$)~i';
    }
    return (bool) preg_match($re, (string) $path);
}

function junk_set_bots_match($ua) {
    $ua = (string) $ua;
    if ($ua === '') return false;
    foreach (junk_set_bots() as $b) if (stripos($ua, $b) !== false) return true;
    return false;
}

// ---- правила ----

function junk_resps() { return ['copy404' => 'Копия 404', 'e404' => 'Пустой 404', '403' => '403']; }
function junk_resp_ok($r) { return isset(junk_resps()[(string) $r]) ? (string) $r : 'copy404'; }

function junk_path_rule_norm($v) {
    $v = ltrim(trim((string) $v), '/');
    if ($v === '' || $v === '*' || strlen($v) > 191 || preg_match('~[\x00-\x1f\s]~', $v)) return '';
    if (strpos(rtrim($v, '*'), '*') !== false) return '';
    return $v;
}

function junk_path_rule_match($path, $rule) {
    $path = (string) $path;
    if (substr($rule, -1) === '*') {
        $pre = rtrim($rule, '*');
        return strncasecmp($path, $pre, strlen($pre)) === 0;
    }
    return strcasecmp($path, $rule) === 0;
}

function junk_rule_label(array $r) {
    if ($r['kind'] === 'set') return $r['val'] === 'bots' ? 'Боты по User-Agent' : 'Сканерские пути';
    return (['path' => 'Путь', 'ua' => 'User-Agent'][$r['kind']] ?? $r['kind']) . ' ' . $r['val'];
}

// Активные правила лежат копией в настройках: настройки и так читаются целиком на каждом запросе, так шлюз не ходит в базу.
function junk_rules_cached() {
    static $raw = null, $c = [];
    $s = (string) setting('junk_rules_cache', '[]');
    if ($s === $raw) return $c;
    $raw = $s;
    $c = json_decode($s, true);
    if (!is_array($c)) $c = [];
    return $c;
}

function junk_rules_rebuild() {
    if (!junk_ensure()) return;
    $now = time();
    $rows = [];
    try {
        $st = db()->prepare('SELECT id, kind, val, resp, mode, until_ts, src FROM junk_rules WHERE active = 1 AND (until_ts = 0 OR until_ts > ?) ORDER BY id LIMIT ' . JUNK_RULES_MAX);
        $st->execute([$now]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rows[] = ['id' => (int) $r['id'], 'kind' => (string) $r['kind'], 'val' => (string) $r['val'], 'resp' => junk_resp_ok($r['resp']),
                       'mode' => $r['mode'] === 'cut' ? 'cut' : 'dry', 'until' => (int) $r['until_ts'], 'src' => (string) $r['src']];
        }
    } catch (Throwable $e) { error_log('submw junk rules: ' . $e->getMessage()); return; }
    set_setting('junk_rules_cache', json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function junk_rules_all() {
    if (!junk_ensure()) return [];
    try { return db()->query('SELECT * FROM junk_rules ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC); }
    catch (Throwable $e) { return []; }
}

function junk_set_row($val) {
    if (!junk_ensure()) return null;
    try {
        $st = db()->prepare("SELECT * FROM junk_rules WHERE kind = 'set' AND val = ? ORDER BY id LIMIT 1");
        $st->execute([$val]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        $st->closeCursor();
        return $r ?: null;
    } catch (Throwable $e) { return null; }
}

function junk_set_save($val, $on, $mode, $resp) {
    if (!in_array($val, ['paths', 'bots'], true) || !junk_ensure()) return false;
    $mode = $mode === 'cut' ? 'cut' : 'dry';
    $resp = junk_resp_ok($resp);
    try {
        if ($r = junk_set_row($val)) {
            db()->prepare('UPDATE junk_rules SET active = ?, mode = ?, resp = ? WHERE id = ?')->execute([$on ? 1 : 0, $mode, $resp, (int) $r['id']]);
        } else {
            db()->prepare("INSERT INTO junk_rules (kind, val, resp, mode, active, src, created_ts) VALUES ('set', ?, ?, ?, ?, 'set', ?)")->execute([$val, $resp, $mode, $on ? 1 : 0, time()]);
        }
    } catch (Throwable $e) { error_log('submw junk set: ' . $e->getMessage()); return false; }
    junk_rules_rebuild();
    return true;
}

function junk_rule_add($kind, $val, $resp, $mode, $ttl, &$err = '') {
    $err = '';
    if (!junk_ensure()) { $err = 'нет базы'; return false; }
    if ($kind === 'path') $val = junk_path_rule_norm($val);
    elseif ($kind === 'ua') { $val = trim((string) $val); if (strlen($val) < 3 || strlen($val) > 191) $val = ''; }
    else { $err = 'неизвестное условие'; return false; }
    if ($val === '') {
        $err = $kind === 'path' ? 'путь не подходит: точный путь или начало пути со * в конце' : 'нужно от 3 символов строки User-Agent';
        return false;
    }
    if ($kind === 'path' && junk_protected($val)) { $err = 'этот адрес относится к странице подписки — правила его не трогают'; return false; }
    $until = (int) $ttl > 0 ? time() + (int) $ttl : 0;
    try {
        $st = db()->prepare("SELECT COUNT(*) FROM junk_rules WHERE kind <> 'set' AND active = 1 AND (until_ts = 0 OR until_ts > ?)");
        $st->execute([time()]);
        $c = (int) $st->fetchColumn();
        if ($c >= JUNK_RULES_MAX) { $err = 'правил слишком много (' . JUNK_RULES_MAX . ') — удалите лишние'; return false; }
        db()->prepare('INSERT INTO junk_rules (kind, val, resp, mode, active, until_ts, src, created_ts) VALUES (?, ?, ?, ?, 1, ?, ?, ?)')
            ->execute([$kind, $val, junk_resp_ok($resp), $mode === 'cut' ? 'cut' : 'dry', $until, 'manual', time()]);
    } catch (Throwable $e) { $err = 'не записалось: ' . $e->getMessage(); return false; }
    junk_rules_rebuild();
    return true;
}

function junk_rule_mode($id) {
    if (!junk_ensure()) return;
    try { db()->prepare("UPDATE junk_rules SET mode = CASE WHEN mode = 'cut' THEN 'dry' ELSE 'cut' END WHERE id = ? AND kind <> 'set'")->execute([(int) $id]); }
    catch (Throwable $e) {}
    junk_rules_rebuild();
}

function junk_rule_del($id) {
    if (!junk_ensure()) return;
    try { db()->prepare("DELETE FROM junk_rules WHERE id = ? AND kind <> 'set'")->execute([(int) $id]); } catch (Throwable $e) {}
    junk_rules_rebuild();
}

function junk_live_protect() { return setting('junk_protect_live', '1') === '1'; }

// Ссылка подписки, которую прослойка уже видела живой: её не режут правила по пути, а по User-Agent — пока включена защита.
// Смотрит два первых сегмента: префикс подписки у origin может быть не включён в прослойке.
function junk_is_live($path) {
    $segs = path_segments((string) $path);
    if (!$segs) return false;
    $ov = function_exists('find_override_in') ? find_override_in('shortuuid', $segs) : null;
    if ($ov) return true;
    foreach (array_slice($segs, 0, 2) as $short) {
        if (function_exists('nolog_is_set') && nolog_is_set((string) $short)) return true;
        if (function_exists('reqlog_known_token') && reqlog_known_token((string) $short)) return true;
    }
    return false;
}

// Возвращает сработавшее правило (cut важнее dry) или null. Защищённые адреса и живые ссылки не трогает.
function junk_gate($path, $ua) {
    $rules = junk_rules_cached();
    if (!$rules || junk_protected($path)) return null;
    $now = time();
    $hit = null;
    foreach ($rules as $r) {
        if ($r['until'] > 0 && $r['until'] <= $now) continue;
        if ($hit && $hit['mode'] === 'cut' && $r['mode'] !== 'cut') continue;
        $m = false;
        if ($r['kind'] === 'set') $m = $r['val'] === 'bots' ? junk_set_bots_match($ua) : junk_set_paths_match($path);
        elseif ($r['kind'] === 'path') $m = junk_path_rule_match($path, $r['val']);
        elseif ($r['kind'] === 'ua') $m = stripos((string) $ua, $r['val']) !== false;
        if (!$m) continue;
        $hit = $r;
        if ($r['mode'] === 'cut') break;
    }
    if (!$hit) return null;
    $by_path = $hit['kind'] === 'path' || ($hit['kind'] === 'set' && $hit['val'] === 'paths');
    if (($by_path || junk_live_protect()) && junk_is_live($path)) return null;
    return $hit;
}

function junk_404_copy() {
    $c = function_exists('admin_cache_get') ? admin_cache_get('junk_404', 86400 * 30) : null;
    if (!is_array($c) || !isset($c['b'])) return null;
    $b = base64_decode((string) $c['b'], true);
    return $b === false ? null : ['ct' => (string) ($c['ct'] ?? ''), 'body' => $b];
}

function junk_respond($resp) {
    header_remove('X-Powered-By');
    if ($resp === '403') { http_response_code(403); return; }
    http_response_code(404);
    if ($resp !== 'copy404' || (function_exists('mask_notfound') && mask_notfound())) return;
    $c = junk_404_copy();
    if (!$c) return;
    if ($c['ct'] !== '' && preg_match('~^[\w.+-]+/[\w.+-]+(;\s*[\w-]+=[\w.-]+)*$~', $c['ct'])) header('Content-Type: ' . $c['ct']);
    echo $c['body'];
}

// ---- статистика ----

function junk_note($path, $ip, $ua, $kind, $rule = null) {
    $GLOBALS['submw_junk_hit'] = ['path' => (string) $path, 'ip' => (string) $ip, 'ua' => (string) $ua, 'kind' => $kind,
                                  'resp' => $rule ? (string) $rule['resp'] : '', 'rule' => $rule ? junk_rule_label($rule) : '', 'rid' => $rule ? (int) $rule['id'] : 0];
}

function junk_404_capture($body, $ct) {
    if (!is_string($body) || strlen($body) > 16384 || !function_exists('admin_cache_get')) return;
    $GLOBALS['submw_junk_404'] = ['b' => $body, 'ct' => (string) $ct];
}

function junk_upd_ins($p, $upd, array $ua, $ins, array $ia) {
    $u = $p->prepare($upd);
    $u->execute($ua);
    if ($u->rowCount() > 0) return false;
    try { $p->prepare($ins)->execute($ia); return true; }
    catch (Throwable $e) { $u->execute($ua); return false; }
}

function junk_flush() {
    $h = $GLOBALS['submw_junk_hit'] ?? null;
    unset($GLOBALS['submw_junk_hit']);
    $cap = $GLOBALS['submw_junk_404'] ?? null;
    unset($GLOBALS['submw_junk_404']);
    if (!is_array($h) || !junk_ensure()) return;
    $p = db();
    $now = time();
    $path = mb_substr($h['path'], 0, 191);
    $ip   = mb_substr($h['ip'], 0, 45);
    $ua   = mb_substr($h['ua'], 0, 255);
    $code = (int) http_response_code();
    $kind = in_array($h['kind'], ['cut', 'dry'], true) ? $h['kind'] : 'pass';
    try {
        $p->beginTransaction();
        $p->prepare('INSERT INTO junk_log (ts, ip, path, ua, kind, code, resp, rule) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
          ->execute([$now, $ip, $path, $ua, $kind, $code, $h['resp'], mb_substr($h['rule'], 0, 191)]);
        $hr = intdiv($now, 3600) * 3600;
        junk_upd_ins($p, 'UPDATE junk_hour SET n = n + 1, cut = cut + ?, dry = dry + ? WHERE h = ?', [(int) ($kind === 'cut'), (int) ($kind === 'dry'), $hr],
                     'INSERT INTO junk_hour (h, n, cut, dry) VALUES (?, 1, ?, ?)', [$hr, (int) ($kind === 'cut'), (int) ($kind === 'dry')]);
        $newpair = $ip !== '' && junk_upd_ins($p, 'UPDATE junk_pair SET ts = ? WHERE path = ? AND ip = ?', [$now, $path, $ip],
                                              'INSERT INTO junk_pair (path, ip, ts) VALUES (?, ?, ?)', [$path, $ip, $now]);
        junk_upd_ins($p, 'UPDATE junk_hits SET hits = hits + 1, last_ts = ? WHERE path = ?', [$now, $path],
                     'INSERT INTO junk_hits (path, hits, last_ts) VALUES (?, 1, ?)', [$path, $now]);
        if ($ip !== '') {
            junk_upd_ins($p, 'UPDATE junk_ip SET hits = hits + 1, paths = paths + ?, last_ts = ?, last_path = ?, last_ua = ? WHERE ip = ?', [(int) $newpair, $now, $path, $ua, $ip],
                         'INSERT INTO junk_ip (ip, hits, paths, first_ts, last_ts, last_path, last_ua) VALUES (?, 1, 1, ?, ?, ?, ?)', [$ip, $now, $now, $path, $ua]);
        }
        if ($h['rid'] > 0) $p->prepare('UPDATE junk_rules SET hits = hits + 1, last_ts = ? WHERE id = ?')->execute([$now, $h['rid']]);
        $p->commit();
    } catch (Throwable $e) {
        try { $p->rollBack(); } catch (Throwable $e2) {}
        error_log('submw junk flush: ' . $e->getMessage());
        return;
    }
    if (is_array($cap) && $kind === 'pass' && $code === 404 && !admin_cache_get('junk_404', 86400)) {
        admin_cache_put('junk_404', ['b' => base64_encode($cap['b']), 'ct' => $cap['ct']]);
    }
    if (random_int(1, 100) === 1) junk_prune($p);
}

function junk_prune($p) {
    if (!junk_ensure()) return;
    $now = time();
    $cap = static function ($table, $order, $keep) use ($p) {
        try {
            $c = (int) $p->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
            if ($c <= $keep) return;
            $rm = $c - $keep;
            if (db_driver() === 'mysql') $p->exec('DELETE FROM ' . $table . ' ORDER BY ' . $order . ' ASC LIMIT ' . (int) $rm);
            else $p->exec('DELETE FROM ' . $table . ' WHERE rowid IN (SELECT rowid FROM ' . $table . ' ORDER BY ' . $order . ' ASC LIMIT ' . (int) $rm . ')');
        } catch (Throwable $e) {}
    };
    try {
        $mx = (int) $p->query('SELECT COALESCE(MAX(id), 0) FROM junk_log')->fetchColumn();
        $p->prepare('DELETE FROM junk_log WHERE id <= ?')->execute([$mx - JUNK_LOG_KEEP]);
        $p->prepare('DELETE FROM junk_hour WHERE h < ?')->execute([$now - JUNK_HOUR_KEEP * 86400]);
        $old = (int) $p->query("SELECT COUNT(*) FROM junk_rules WHERE until_ts > 0 AND until_ts <= " . $now)->fetchColumn();
        if ($old > 0) { $p->prepare('DELETE FROM junk_rules WHERE until_ts > 0 AND until_ts <= ?')->execute([$now]); junk_rules_rebuild(); }
    } catch (Throwable $e) {}
    $cap('junk_hits', 'last_ts', JUNK_SUM_KEEP);
    $cap('junk_ip', 'last_ts', JUNK_SUM_KEEP);
    $cap('junk_pair', 'ts', JUNK_PAIR_KEEP);
}

function junk_clear_stats() {
    if (!junk_ensure()) return;
    foreach (['junk_log', 'junk_hits', 'junk_ip', 'junk_pair', 'junk_hour'] as $t) {
        try { db()->exec('DELETE FROM ' . $t); } catch (Throwable $e) {}
    }
}

// Прежние index.php и admin/index.php в окне пофайлового обновления зовут старые имена.
function junk_record($path) {}
function junk_set_paths_dot() { return []; }

function junk_top($limit = 100) {
    if (!junk_ensure()) return [];
    try { return db()->query('SELECT path, hits, last_ts FROM junk_hits ORDER BY hits DESC, last_ts DESC LIMIT ' . (int) $limit)->fetchAll(PDO::FETCH_ASSOC); }
    catch (Throwable $e) { return []; }
}

function junk_forget($path) {
    if (!junk_ensure()) return;
    try { db()->prepare('DELETE FROM junk_hits WHERE path = ?')->execute([mb_substr((string) $path, 0, 191)]); }
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

// ---- данные для экрана ----

function junk_view() {
    $out = ['kpi' => ['n' => 0, 'cut' => 0, 'dry' => 0, 'peak' => 0, 'ips' => 0, 'top' => ''], 'hours' => [], 'log' => [], 'paths' => [], 'ips' => [], 'rules' => []];
    $now = time();
    $h0 = intdiv($now, 3600) * 3600 - 23 * 3600;
    $byh = [];
    $ok = junk_ensure();
    if ($ok) {
        try {
            $st = db()->prepare('SELECT h, n, cut, dry FROM junk_hour WHERE h >= ?');
            $st->execute([$h0]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $byh[(int) $r['h']] = $r;
        } catch (Throwable $e) {}
    }
    for ($i = 0; $i < 24; $i++) {
        $h = $h0 + $i * 3600;
        $r = $byh[$h] ?? ['n' => 0, 'cut' => 0, 'dry' => 0];
        $out['hours'][] = ['h' => $h, 'n' => (int) $r['n'], 'cut' => (int) $r['cut']];
        $out['kpi']['n'] += (int) $r['n'];
        $out['kpi']['cut'] += (int) $r['cut'];
        $out['kpi']['dry'] += (int) $r['dry'];
        $out['kpi']['peak'] = max($out['kpi']['peak'], (int) $r['n']);
    }
    if (!$ok) return $out;
    $p = db();
    try {
        $st = $p->prepare('SELECT COUNT(*) FROM junk_ip WHERE last_ts >= ?');
        $st->execute([$now - 86400]);
        $out['kpi']['ips'] = (int) $st->fetchColumn();
        $st = $p->prepare('SELECT ip FROM junk_ip WHERE last_ts >= ? ORDER BY hits DESC LIMIT 1');
        $st->execute([$now - 86400]);
        $out['kpi']['top'] = (string) $st->fetchColumn();
        $out['log']   = $p->query('SELECT * FROM junk_log ORDER BY id DESC LIMIT ' . JUNK_LOG_KEEP)->fetchAll(PDO::FETCH_ASSOC);
        $out['paths'] = $p->query('SELECT h.path, h.hits, h.last_ts, (SELECT COUNT(*) FROM junk_pair x WHERE x.path = h.path) AS ips FROM junk_hits h ORDER BY h.hits DESC, h.last_ts DESC LIMIT ' . JUNK_SUM_KEEP)->fetchAll(PDO::FETCH_ASSOC);
        $out['ips']   = $p->query('SELECT * FROM junk_ip ORDER BY hits DESC, last_ts DESC LIMIT ' . JUNK_SUM_KEEP)->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { error_log('submw junk view: ' . $e->getMessage()); }
    $out['rules'] = junk_rules_all();
    return $out;
}

// Что сейчас будет с путём / IP по активным правилам — для колонки «Сейчас».
function junk_state_path($path) {
    if (junk_protected($path)) return null;
    foreach (junk_rules_cached() as $r) {
        if ($r['until'] > 0 && $r['until'] <= time()) continue;
        if (($r['kind'] === 'set' && $r['val'] === 'paths' && junk_set_paths_match($path)) || ($r['kind'] === 'path' && junk_path_rule_match($path, $r['val']))) return $r;
    }
    return null;
}
