<?php

const APP_EV_KEEP    = 604800;
const APP_CODE_TTL   = 300;
const APP_CODE_MAX   = 3;
const APP_PAIR_FAILS = 20;
const APP_DEV_FAILS  = 10;
const APP_REPO       = 'Mrvibecodic/submw-android';
const APP_APK_MAX    = 67108864;

function app_ext_ok() { return extension_loaded('sodium'); }

function app_enabled() { return app_ext_ok() && setting('app_enabled', '0') === '1'; }

function app_ev_wait_max() { return max(25, min(290, (int) (setting('app_ev_wait_max', '280') ?: 280))); }

function app_ev_slots() { return max(1, min(32, (int) (setting('app_ev_slots', '2') ?: 2))); }

function app_only_off() {
    $c = cfg();
    return !empty($c['app_only_off']) || getenv('SUBMW_APP_ONLY_OFF') === '1';
}

function app_only_on() { return app_enabled() && setting('app_only', '0') === '1' && !app_only_off() && app_dev_count() > 0; }

function app_tunnel() { return defined('SUBMW_APP_TUNNEL'); }

function app_only_closed() { return !app_tunnel() && app_only_on(); }

function submw_upload_ok($tmp) {
    $tmp = (string) $tmp;
    return $tmp !== '' && (is_uploaded_file($tmp) || !empty($GLOBALS['app_uploads'][$tmp]));
}

function app_ddl($drv) {
    if ($drv === 'mysql') {
        return [
            "CREATE TABLE IF NOT EXISTS app_key (
                spid VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                secret VARCHAR(64) NOT NULL,
                created INT UNSIGNED NOT NULL DEFAULT 0,
                is_current TINYINT(1) NOT NULL DEFAULT 0,
                PRIMARY KEY (spid)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS app_dev (
                did VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                dpub VARCHAR(64) NOT NULL,
                name VARCHAR(191) NULL, os VARCHAR(64) NULL, osv VARCHAR(64) NULL, app VARCHAR(64) NULL,
                created INT UNSIGNED NOT NULL DEFAULT 0,
                last_seen INT UNSIGNED NOT NULL DEFAULT 0,
                last_ip VARCHAR(64) NULL,
                PRIMARY KEY (did)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS app_code (
                code VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                created INT UNSIGNED NOT NULL DEFAULT 0,
                expires INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (code)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS app_nonce (
                n VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                ts INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (n), KEY idx_app_nonce_ts (ts)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS app_ev (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                ts INT UNSIGNED NOT NULL DEFAULT 0,
                k VARCHAR(80) NOT NULL,
                PRIMARY KEY (id), KEY idx_app_ev_ts (ts)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ];
    }
    return [
        'CREATE TABLE IF NOT EXISTS app_key (spid TEXT NOT NULL PRIMARY KEY, secret TEXT NOT NULL, created INTEGER NOT NULL DEFAULT 0, is_current INTEGER NOT NULL DEFAULT 0)',
        'CREATE TABLE IF NOT EXISTS app_dev (did TEXT NOT NULL PRIMARY KEY, dpub TEXT NOT NULL, name TEXT NULL, os TEXT NULL, osv TEXT NULL, app TEXT NULL, created INTEGER NOT NULL DEFAULT 0, last_seen INTEGER NOT NULL DEFAULT 0, last_ip TEXT NULL)',
        'CREATE TABLE IF NOT EXISTS app_code (code TEXT NOT NULL PRIMARY KEY, created INTEGER NOT NULL DEFAULT 0, expires INTEGER NOT NULL DEFAULT 0)',
        'CREATE TABLE IF NOT EXISTS app_nonce (n TEXT NOT NULL PRIMARY KEY, ts INTEGER NOT NULL DEFAULT 0)',
        'CREATE INDEX IF NOT EXISTS idx_app_nonce_ts ON app_nonce(ts)',
        'CREATE TABLE IF NOT EXISTS app_ev (id INTEGER PRIMARY KEY AUTOINCREMENT, ts INTEGER NOT NULL DEFAULT 0, k TEXT NOT NULL)',
        'CREATE INDEX IF NOT EXISTS idx_app_ev_ts ON app_ev(ts)',
    ];
}

function app_ensure() {
    static $done = false;
    if ($done) return true;
    if (!($p = db())) return false;
    $done = true;
    try {
        foreach (app_ddl(db_driver()) as $sql) $p->exec($sql);
    } catch (Throwable $e) { error_log('submw app tables: ' . $e->getMessage()); return false; }
    return true;
}


function app_keys() {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];
    if (!app_ext_ok() || !app_ensure() || !($p = db())) return $cache;
    $read = static function () use ($p) {
        $out = [];
        foreach ($p->query('SELECT spid, secret FROM app_key')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $raw = base64_decode((string) $row['secret'], true);
            if ($raw !== false && strlen($raw) === 32) $out[(string) $row['spid']] = $raw;
        }
        return $out;
    };
    try {
        $cache = $read();
        if (!$cache) {
            $lock = @fopen(chan_lock_path('appkey'), 'c');
            if ($lock) flock($lock, LOCK_EX);
            try {
                $cache = $read();
                if (!$cache) {
                    [$secret, $public] = chan_keygen();
                    $spid = chan_spid($public);
                    $p->prepare('INSERT INTO app_key (spid, secret, created, is_current) VALUES (?, ?, ?, 1)')
                      ->execute([$spid, base64_encode($secret), time()]);
                    $cache[$spid] = $secret;
                }
            } finally {
                if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
            }
        }
    } catch (Throwable $e) { error_log('submw app keys: ' . $e->getMessage()); }
    return $cache;
}

function app_current_secret() {
    $keys = app_keys();
    if (!$keys) return '';
    if (!($p = db())) return (string) reset($keys);
    try {
        $spid = (string) $p->query('SELECT spid FROM app_key WHERE is_current = 1 ORDER BY created DESC')->fetchColumn();
        if ($spid !== '' && isset($keys[$spid])) return $keys[$spid];
    } catch (Throwable $e) {}
    return (string) reset($keys);
}

function app_public_key() {
    $secret = app_current_secret();
    if ($secret === '') return '';
    try { return sodium_crypto_box_publickey_from_secretkey($secret); }
    catch (Throwable $e) { return ''; }
}

function app_fingerprint() {
    $pub = app_public_key();
    return $pub === '' ? '' : chan_spid($pub);
}

function app_rotate() {
    if (!app_ext_ok() || !app_ensure() || !($p = db())) return false;
    try {
        [$secret, $public] = chan_keygen();
        $p->exec('UPDATE app_key SET is_current = 0');
        $p->prepare('INSERT INTO app_key (spid, secret, created, is_current) VALUES (?, ?, ?, 1)')
          ->execute([chan_spid($public), base64_encode($secret), time()]);
        $old = $p->query('SELECT spid FROM app_key ORDER BY created DESC')->fetchAll(PDO::FETCH_COLUMN);
        foreach (array_slice($old, 2) as $dead) $p->prepare('DELETE FROM app_key WHERE spid = ?')->execute([$dead]);
        return true;
    } catch (Throwable $e) { error_log('submw app rotate: ' . $e->getMessage()); return false; }
}


function app_devs() {
    if (!app_ensure() || !($p = db())) return [];
    try {
        return $p->query('SELECT * FROM app_dev ORDER BY created ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { return []; }
}

function app_dev_count() {
    static $n = null;
    if ($n !== null) return $n;
    $n = 0;
    if (!app_ensure() || !($p = db())) return $n;
    try { $n = (int) $p->query('SELECT COUNT(*) FROM app_dev')->fetchColumn(); } catch (Throwable $e) {}
    return $n;
}

function app_lookup($kid, $secret) {
    static $memo = [];
    $k = $kid . '|' . bin2hex((string) $secret);
    if (array_key_exists($k, $memo)) return $memo[$k];
    $memo[$k] = null;
    $epoch = chan_epoch();
    foreach (app_devs() as $d) {
        $dpub = chan_unb64((string) $d['dpub']);
        $did  = chan_unb64((string) $d['did']);
        if ($dpub === null || $did === null) continue;
        $shared = chan_x25519((string) $secret, $dpub);
        if ($shared === null) continue;
        $kdev = app_kdev($shared, $did);
        for ($e = $epoch - 1; $e <= $epoch + 1; $e++) {
            if (hash_equals(app_kid($kdev, $e), (string) $kid)) {
                $memo[$k] = $d + ['kdev' => $kdev];
                return $memo[$k];
            }
        }
    }
    return null;
}

function app_dev_add($dpub, array $req) {
    if (!app_ensure() || !($p = db())) return '';
    $did = chan_b64(random_bytes(16));
    $now = time();
    try {
        $p->prepare('INSERT INTO app_dev (did, dpub, name, os, osv, app, created, last_seen, last_ip) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
          ->execute([$did, chan_b64((string) $dpub), app_scrub($req['name'] ?? ''), app_scrub($req['os'] ?? '', 64), app_scrub($req['osv'] ?? '', 64), app_scrub($req['app'] ?? '', 64), $now, $now, substr(client_ip(), 0, 64)]);
        return $did;
    } catch (Throwable $e) { error_log('submw app dev add: ' . $e->getMessage()); return ''; }
}

function app_dev_seen(array $ctx) {
    $d = $ctx['dev'] ?? [];
    $did = (string) ($d['did'] ?? '');
    if ($did === '' || !($p = db())) return;
    $now = time();
    $ip  = substr(client_ip(), 0, 64);
    if ($now - (int) ($d['last_seen'] ?? 0) < 60 && (string) ($d['last_ip'] ?? '') === $ip) return;
    try { $p->prepare('UPDATE app_dev SET last_seen = ?, last_ip = ? WHERE did = ?')->execute([$now, $ip, $did]); }
    catch (Throwable $e) {}
}

function app_dev_card($did, array $req) {
    if (!($p = db())) return;
    $set  = [];
    $args = [];
    foreach (['name' => APP_FIELD_MAX, 'os' => 64, 'osv' => 64, 'app' => 64] as $f => $max) {
        $v = app_scrub($req[$f] ?? '', $max);
        if ($v === '') continue;
        $set[]  = $f . ' = ?';
        $args[] = $v;
    }
    if (!$set) return;
    $args[] = (string) $did;
    try { $p->prepare('UPDATE app_dev SET ' . implode(', ', $set) . ' WHERE did = ?')->execute($args); }
    catch (Throwable $e) {}
}

function app_dev_alive($did) {
    static $memo = [];
    $did = (string) $did;
    if ($did === '' || !app_enabled()) return false;
    if (array_key_exists($did, $memo)) return $memo[$did];
    if (!app_ensure() || !($p = db())) return null;
    try {
        $st = $p->prepare('SELECT 1 FROM app_dev WHERE did = ?');
        $st->execute([$did]);
        return $memo[$did] = $st->fetchColumn() !== false;
    } catch (Throwable $e) { return null; }
}

function app_session_ok(array $s) {
    if (empty($s['auth'])) return false;
    $did = (string) ($s['app_did'] ?? '');
    return $did === '' || app_dev_alive($did) === true;
}

function app_dev_drop($did) {
    if (!app_ensure() || !($p = db())) return false;
    try {
        $p->prepare('DELETE FROM app_dev WHERE did = ?')->execute([(string) $did]);
        if ((int) $p->query('SELECT COUNT(*) FROM app_dev')->fetchColumn() === 0 && setting('app_only', '0') === '1') set_setting('app_only', '0');
        return true;
    } catch (Throwable $e) { error_log('submw app dev drop: ' . $e->getMessage()); return false; }
}


function app_codes() {
    if (!app_ensure() || !($p = db())) return [];
    try {
        $p->prepare('DELETE FROM app_code WHERE expires < ?')->execute([time()]);
        return $p->query('SELECT code, created, expires FROM app_code ORDER BY created DESC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { return []; }
}

function app_code_create() {
    if (!app_ext_ok() || !app_ensure() || !($p = db())) return null;
    $now = time();
    try {
        $live = app_codes();
        foreach (array_slice($live, APP_CODE_MAX - 1) as $old) $p->prepare('DELETE FROM app_code WHERE code = ?')->execute([(string) $old['code']]);
        $code = app_code_new();
        $p->prepare('INSERT INTO app_code (code, created, expires) VALUES (?, ?, ?)')->execute([$code, $now, $now + APP_CODE_TTL]);
        return ['code' => $code, 'expires' => $now + APP_CODE_TTL];
    } catch (Throwable $e) { error_log('submw app code: ' . $e->getMessage()); return null; }
}

function app_code_drop($code) {
    if (!app_ensure() || !($p = db())) return false;
    try { $p->prepare('DELETE FROM app_code WHERE code = ?')->execute([(string) $code]); return true; }
    catch (Throwable $e) { return false; }
}

function app_code_take($code) {
    if (!($p = db())) return false;
    try {
        $st = $p->prepare('DELETE FROM app_code WHERE code = ? AND expires >= ?');
        $st->execute([(string) $code, time()]);
        return $st->rowCount() === 1;
    } catch (Throwable $e) { return false; }
}


function app_nonce_take($n) {
    $n = (string) $n;
    if ($n === '' || !app_ensure() || !($p = db())) return false;
    $now = time();
    try {
        $p->prepare('INSERT INTO app_nonce (n, ts) VALUES (?, ?)')->execute([$n, $now]);
    } catch (Throwable $e) {
        return false;
    }
    try {
        if (random_int(1, 50) === 1) $p->prepare('DELETE FROM app_nonce WHERE ts < ?')->execute([$now - APP_NONCE_TTL]);
    } catch (Throwable $e) {}
    return true;
}


function app_ev_on() {
    static $on = null;
    if ($on === null) $on = app_enabled() && app_dev_count() > 0;
    return $on;
}

function app_ev_push($k) {
    $k = substr((string) $k, 0, 80);
    if ($k === '' || !app_ev_on() || !($p = db())) return;
    try {
        $now = time();
        $p->prepare('INSERT INTO app_ev (ts, k) VALUES (?, ?)')->execute([$now, $k]);
        if (random_int(1, 50) === 1) $p->prepare('DELETE FROM app_ev WHERE ts < ?')->execute([$now - APP_EV_KEEP]);
    } catch (Throwable $e) { error_log('submw app ev: ' . $e->getMessage()); }
}

function app_ev_head() {
    if (!app_ensure() || !($p = db())) return 0;
    try { return (int) $p->query('SELECT COALESCE(MAX(id), 0) FROM app_ev')->fetchColumn(); }
    catch (Throwable $e) { return 0; }
}

function app_ev_match($k, array $want) {
    foreach ($want as $w) {
        if ($w === $k) return true;
        if (substr($w, -1) === '*' && strncmp($k, $w, strlen($w) - 1) === 0) return true;
    }
    return false;
}

function app_ev_since($cursor, array $want) {
    $out = [];
    $last = (int) $cursor;
    if (!($p = db())) return [$out, $last];
    try {
        $st = $p->prepare('SELECT id, k FROM app_ev WHERE id > ? ORDER BY id ASC LIMIT 1000');
        $st->execute([(int) $cursor]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $last = max($last, (int) $r['id']);
            $k = (string) $r['k'];
            if (app_ev_match($k, $want)) $out[$k] = ($out[$k] ?? 0) + 1;
        }
    } catch (Throwable $e) {}
    return [$out, $last];
}

function app_ev_kinds() { return ['chat', 'rep', 'chan_new', 'wh:*']; }

function app_wh_events() {
    $out = ['user.created', 'user.modified', 'user.deleted', 'user.revoked', 'user.disabled', 'user.enabled', 'user.limited',
            'user.expired', 'user.traffic_reset', 'user.expiration', 'user.not_connected', 'user.first_connected',
            'user.bandwidth_usage_threshold_reached', 'user_hwid_devices.added', 'user_hwid_devices.deleted',
            'node.created', 'node.modified', 'node.disabled', 'node.enabled', 'node.deleted', 'node.connection_lost',
            'node.connection_restored', 'node.traffic_notify', 'service.panel_started', 'service.login_attempt_failed',
            'service.login_attempt_success'];
    if ($p = db()) {
        try {
            foreach ($p->query("SELECT DISTINCT event FROM (SELECT event FROM webhook_log ORDER BY id DESC LIMIT 5000) t WHERE event IS NOT NULL AND event <> '' LIMIT 200")->fetchAll(PDO::FETCH_COLUMN) as $e) {
                $e = (string) $e;
                if (preg_match('~^[a-z0-9_]+\.[a-z0-9_.]+$~', $e) && strlen($e) <= 77) $out[] = $e;
            }
        } catch (Throwable $e) {}
    }
    $out = array_values(array_unique($out));
    sort($out);
    return $out;
}


function app_apk_dir() { return dirname(__DIR__) . '/data/app'; }

function app_apk_meta() {
    $j = json_decode((string) setting('app_apk', '{}'), true);
    return is_array($j) ? $j : [];
}

function app_apk_path($m = null) {
    $m = is_array($m) ? $m : app_apk_meta();
    $f = (string) ($m['file'] ?? '');
    if ($f === '' || !preg_match('~^[A-Za-z0-9._-]+\.apk$~', $f)) return '';
    $path = app_apk_dir() . '/' . $f;
    return is_file($path) ? $path : '';
}

function app_apk_public() {
    $m = app_apk_meta();
    if (app_apk_path($m) === '') return null;
    return ['v' => (string) ($m['v'] ?? ''), 'size' => (int) ($m['size'] ?? 0), 'sha256' => (string) ($m['sha256'] ?? '')];
}

function app_apk_stale($m = null) {
    $m = is_array($m) ? $m : app_apk_meta();
    $now = time();
    return $now - (int) ($m['ts'] ?? 0) >= 86400 && $now - (int) ($m['try'] ?? 0) >= 3600;
}

function app_gh_url_ok($url) {
    $u = parse_url((string) $url);
    if (!is_array($u) || strtolower((string) ($u['scheme'] ?? '')) !== 'https' || isset($u['user']) || isset($u['pass'])) return false;
    if (isset($u['port']) && (int) $u['port'] !== 443) return false;
    return in_array(strtolower((string) ($u['host'] ?? '')), ['github.com', 'api.github.com', 'objects.githubusercontent.com', 'release-assets.githubusercontent.com'], true);
}

function app_gh_get($url, &$err = '', $fh = null, $max = 1048576) {
    $err = '';
    if (!function_exists('curl_init')) { $err = 'curl недоступен'; return null; }
    $url = (string) $url;
    for ($hop = 0; $hop <= 5; $hop++) {
        if (!app_gh_url_ok($url)) { $err = ($hop ? 'перенаправление' : 'адрес') . ' не на https-адрес GitHub: ' . (string) (parse_url($url, PHP_URL_HOST) ?: $url); return null; }
        $buf = '';
        $loc = '';
        $got = 0;
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => $fh ? 120 : 15,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT      => 'submw-app',
            CURLOPT_HTTPHEADER     => ['Accept: ' . ($fh ? 'application/octet-stream' : 'application/vnd.github+json')],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$loc) {
                if (stripos($line, 'location:') === 0) $loc = trim(substr($line, 9));
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION  => function ($c, $chunk) use (&$buf, &$got, $fh, $max) {
                $code = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
                if ($code < 200 || $code >= 300) return strlen($chunk);
                $got += strlen($chunk);
                if ($got > $max) return 0;
                if ($fh) return fwrite($fh, $chunk) === strlen($chunk) ? strlen($chunk) : 0;
                $buf .= $chunk;
                return strlen($chunk);
            },
        ]);
        $ok   = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);
        if ($ok === false) { $err = $got > $max ? 'файл больше ' . (int) ($max / 1048576) . ' МБ' : 'сеть: ' . $cerr; return null; }
        if ($code >= 300 && $code < 400 && $loc !== '') {
            if ($loc[0] === '/') $loc = 'https://' . (string) parse_url($url, PHP_URL_HOST) . $loc;
            $url = $loc;
            continue;
        }
        if ($code === 403 || $code === 429) { $err = 'лимит запросов GitHub (' . $code . '), попробуйте позже'; return null; }
        if ($code === 404) { $err = 'не найдено (404)'; return null; }
        if ($code < 200 || $code >= 300) { $err = 'HTTP ' . $code; return null; }
        return $fh ? true : $buf;
    }
    $err = 'слишком много перенаправлений';
    return null;
}

function app_apk_refresh($force = false) {
    $m = app_apk_meta();
    if (!$force && !app_apk_stale($m)) return $m;
    $m['try'] = time();
    set_setting('app_apk', json_encode($m, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $err  = '';
    $body = app_gh_get('https://api.github.com/repos/' . APP_REPO . '/releases/latest', $err);
    $rel  = $body === null ? null : json_decode($body, true);
    if ($body !== null && !is_array($rel)) $err = 'некорректный ответ GitHub';
    $asset = null;
    if (is_array($rel)) {
        foreach ((array) ($rel['assets'] ?? []) as $a) {
            if (is_array($a) && preg_match('~\.apk$~i', (string) ($a['name'] ?? '')) && (string) ($a['browser_download_url'] ?? '') !== '') { $asset = $a; break; }
        }
        if ($asset === null) $err = 'в последнем релизе нет APK';
        elseif (!app_gh_url_ok((string) $asset['browser_download_url'])) { $err = 'адрес APK не на https-адресе GitHub, файл не скачан'; $asset = null; }
    } elseif ($err === '' || strpos($err, '404') !== false) {
        $err = 'релизов пока нет';
    }
    $tag = is_array($rel) ? preg_replace('~[^A-Za-z0-9._-]~', '', (string) ($rel['tag_name'] ?? '')) : '';
    if ($asset !== null && $tag !== '') {
        if ($tag === (string) ($m['v'] ?? '') && app_apk_path($m) !== '') {
            $m['ts'] = time();
            $m['err'] = '';
        } else {
            $dir = app_apk_dir();
            if (!is_dir($dir)) @mkdir($dir, 0755, true);
            $file = 'submw-' . $tag . '.apk';
            $tmp  = $dir . '/.dl-' . bin2hex(random_bytes(4));
            if (app_apk_download((string) $asset['browser_download_url'], $tmp, $err)) {
                $size = (int) filesize($tmp);
                if ((int) ($asset['size'] ?? 0) > 0 && $size !== (int) $asset['size']) {
                    $err = 'файл скачался не целиком';
                    @unlink($tmp);
                } elseif (@rename($tmp, $dir . '/' . $file)) {
                    foreach (glob($dir . '/*.apk') ?: [] as $old) if (basename($old) !== $file) @unlink($old);
                    $m = ['v' => $tag, 'size' => $size, 'sha256' => hash_file('sha256', $dir . '/' . $file), 'file' => $file,
                          'url' => (string) ($rel['html_url'] ?? ''), 'ts' => time(), 'try' => time(), 'err' => ''];
                } else {
                    $err = 'не удалось сохранить файл в data/app';
                    @unlink($tmp);
                }
            }
        }
    }
    if ($err !== '') $m['err'] = $err;
    set_setting('app_apk', json_encode($m, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    return $m;
}

function app_apk_download($url, $path, &$err = '') {
    $err = '';
    $fh = @fopen($path, 'wb');
    if (!$fh) { $err = 'нет записи в data/app'; return false; }
    $ok = app_gh_get($url, $err, $fh, APP_APK_MAX);
    fclose($fh);
    if ($ok !== true) { @unlink($path); return false; }
    return true;
}


function admin_session_open($did = '') {
    admin_session_boot();
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    session_id(session_create_id());
    if (!@session_start()) return '';
    $_SESSION = ['auth' => true];
    if ((string) $did !== '') $_SESSION['app_did'] = (string) $did;
    $id = session_id();
    session_write_close();
    return (string) $id;
}


function app_intercept() {
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    if (strpos($uri, '/a1/') === false || !app_ext_ok()) return null;
    $route = app_route($uri);
    if ($route === null || strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST' || !app_enabled()) return null;
    [, $kid, $spid] = $route;
    $wire = trim((string) @file_get_contents('php://input', false, null, 0, app_max_wire() + 1));

    if ($kid === '0') {
        app_pair_answer($wire);
        return null;
    }

    $ctx = app_open($kid, $spid, $wire, 'app_lookup', app_keys());
    if ($ctx === null || !app_nonce_take($ctx['nonce'])) return null;
    $GLOBALS['submw_skip_metric'] = 1;
    app_dev_seen($ctx);

    if ($ctx['req']['op'] === 'web') return app_web_prepare($ctx);

    [$fields, $body, $st] = app_op($ctx);
    app_reply($ctx, $fields, $body, $st);
    exit();
}

function app_pair_answer($wire) {
    $lk = 'a1p|' . substr(hash('sha256', login_remote_ip()), 0, 24);
    if (login_recent_fails($lk) >= APP_PAIR_FAILS) return;
    $codes = array_map(fn($r) => (string) $r['code'], app_codes());
    $ctx = $codes ? app_pair_open((string) $wire, $codes) : null;
    if ($ctx === null || !app_nonce_take($ctx['nonce']) || !app_code_take($ctx['code'])) {
        login_record_fail($lk);
        return;
    }
    $did = app_dev_add($ctx['dpub'], $ctx['req']);
    if ($did === '') return;
    $GLOBALS['submw_skip_metric'] = 1;
    app_reply($ctx, ['did' => $did], '', 200);
    exit();
}

function app_reply(array $ctx, array $fields, $body, $st) {
    $fields['sp'] = chan_b64(app_public_key());
    $sealed = app_seal($ctx, $fields, (string) $body, (int) $st);
    if (headers_sent()) { error_log('submw app: заголовки уже ушли'); return; }
    header_remove();
    if ($sealed === null) { http_response_code(404); return; }
    http_response_code(200);
    header('content-type: application/octet-stream');
    header('cache-control: no-store');
    echo $sealed;
}

function app_op(array $ctx) {
    $r   = $ctx['req'];
    $did = (string) ($ctx['dev']['did'] ?? '');
    switch ($r['op']) {
        case 'hi':
            app_dev_card($did, $r);
            $mw = function_exists('update_installed_commit') ? (string) update_installed_commit() : '';
            return [['mw' => substr($mw, 0, 12), 'api' => APP_VERSION, 'apk' => app_apk_public(), 'ev' => app_ev_kinds(), 'wh' => app_wh_events(),
                     'evw' => app_ev_wait_max()], '', 200];
        case 'login':
            return app_op_login($ctx);
        case 'ev':
            return app_op_ev($ctx);
        case 'apk':
            $path = app_apk_path();
            if ($path === '') return [[], '', 404];
            $m = app_apk_meta();
            return [['v' => (string) ($m['v'] ?? ''), 'sha256' => (string) ($m['sha256'] ?? '')], (string) file_get_contents($path), 200];
        case 'bye':
            app_dev_drop($did);
            return [[], '', 200];
    }
    return [[], '', 400];
}

function app_op_login(array $ctx) {
    $C  = cfg();
    $ip = login_remote_ip();
    $dk = 'a1d|' . (string) ($ctx['dev']['did'] ?? '');
    if (login_is_locked($ip) || login_recent_fails($dk) >= APP_DEV_FAILS) {
        usleep(500000);
        return [[], '', 429];
    }
    $u = $ctx['req']['u'] ?? '';
    $p = $ctx['req']['p'] ?? '';
    if (is_string($u) && is_string($p) && hash_equals((string) ($C['admin_user'] ?? ''), $u) && password_verify($p, (string) ($C['admin_pass_hash'] ?? ''))) {
        login_clear($ip);
        login_clear($dk);
        $sid = admin_session_open((string) ($ctx['dev']['did'] ?? ''));
        return $sid === '' ? [[], '', 500] : [['ck' => session_name() . '=' . $sid], '', 200];
    }
    login_record_fail($ip);
    login_record_fail($dk);
    usleep(500000);
    return [[], '', 401];
}

function app_op_ev(array $ctx) {
    $r    = $ctx['req'];
    $c    = is_int($r['c'] ?? null) ? $r['c'] : -1;
    $w    = is_int($r['w'] ?? null) ? $r['w'] : 0;
    $want = [];
    foreach ((array) ($r['k'] ?? []) as $k) {
        if (is_string($k) && $k !== '' && strlen($k) <= 80) $want[] = $k;
        if (count($want) >= 300) break;
    }
    $max = app_ev_wait_max();
    $mxt = (int) ini_get('max_execution_time');
    $off = array_map('trim', explode(',', (string) ini_get('disable_functions')));
    if ($mxt > 0 && (!function_exists('set_time_limit') || in_array('set_time_limit', $off, true))) $max = min($max, max(0, $mxt - 5));
    $w = max(0, min($w, $max));
    @set_time_limit($w + 60);
    $t0 = microtime(true);
    $head = app_ev_head();
    if ($c < 0 || $c > $head) return [['c' => $head, 'e' => (object) [], 'w' => 0], '', 200];
    [$e, $c] = app_ev_since($c, $want);
    if ($e || $w <= 0) return [['c' => $c, 'e' => $e ?: (object) [], 'w' => 0], '', 200];
    // Новый запрос телефона гасит его прежний и ждёт место только если тот ещё ждал: так смена
    // сети не выбивает телефон из живых уведомлений, а лишние телефоны не держат процессы PHP.
    $mark = chan_lock_path('appev_' . substr(hash('sha256', (string) ($ctx['dev']['did'] ?? '')), 0, 16));
    $tok  = bin2hex(random_bytes(8));
    $prev = app_ev_mark_set($mark, $tok);
    $slot = app_ev_slot_take($prev !== '' ? 3.0 : 0.0);
    if ($slot === null) {
        app_ev_mark_clear($mark, $tok);
        return [['c' => $c, 'e' => (object) [], 'w' => (int) round(microtime(true) - $t0), 'r' => random_int(45, 75)], '', 200];
    }
    try {
        while (true) {
            $spent = microtime(true) - $t0;
            if ($spent >= $w) break;
            $next = min((float) $w, $spent + 2.0);
            while (($spent = microtime(true) - $t0) < $next) {
                usleep((int) (min(0.5, $next - $spent) * 1000000));
                if (@file_get_contents($mark) !== $tok) break 2;
            }
            [$e, $c] = app_ev_since($c, $want);
            if ($e) return [['c' => $c, 'e' => $e, 'w' => (int) round(microtime(true) - $t0)], '', 200];
        }
        return [['c' => $c, 'e' => (object) [], 'w' => (int) round(microtime(true) - $t0)], '', 200];
    } finally {
        @flock($slot, LOCK_UN);
        @fclose($slot);
        app_ev_mark_clear($mark, $tok);
    }
}

function app_ev_mark_set($mark, $tok) {
    $fh = @fopen($mark, 'c+');
    if ($fh === false) return '';
    @flock($fh, LOCK_EX);
    $prev = trim((string) stream_get_contents($fh));
    $age  = time() - (int) @filemtime($mark);
    if ($prev !== '' && $age > app_ev_wait_max() + 60) $prev = '';
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, $tok);
    fflush($fh);
    @flock($fh, LOCK_UN);
    fclose($fh);
    return $prev;
}

function app_ev_mark_clear($mark, $tok) {
    $fh = @fopen($mark, 'c+');
    if ($fh === false) return;
    @flock($fh, LOCK_EX);
    if (trim((string) stream_get_contents($fh)) === $tok) ftruncate($fh, 0);
    @flock($fh, LOCK_UN);
    fclose($fh);
}

// Ожидание ev держит процесс PHP, а их пул общий с подписками (и с каналом c1).
// Поэтому ждут одновременно не больше app_ev_slots телефонов; место — flock на файле,
// система снимает его сама, если процесс умер. Остальным — ответ сразу с паузой r.
function app_ev_slot_take($patience) {
    $n     = app_ev_slots();
    $until = microtime(true) + (float) $patience;
    while (true) {
        for ($i = 0; $i < $n; $i++) {
            $path = chan_lock_path('appevslot_' . $i);
            $fh = @fopen($path, 'c');
            if ($fh === false) { error_log('submw app ev: нет записи в ' . $path . ', ожидание уведомлений недоступно'); return null; }
            if (@flock($fh, LOCK_EX | LOCK_NB)) return $fh;
            @fclose($fh);
        }
        if (microtime(true) >= $until) return null;
        usleep(250000);
    }
}


function app_web_prepare(array $ctx) {
    $r = $ctx['req'];
    $m = strtoupper(is_string($r['m'] ?? null) ? $r['m'] : 'GET');
    $p = is_string($r['p'] ?? null) ? $r['p'] : '';
    $h = [];
    foreach ((array) ($r['h'] ?? []) as $k => $v) {
        if (!is_string($k) || !is_string($v) || !preg_match('~^[A-Za-z0-9-]{1,64}$~', $k)) continue;
        $h[strtolower($k)] = substr(strtr($v, ["\r" => '', "\n" => '', "\0" => '']), 0, 8192);
        if (count($h) >= 64) break;
    }
    $path  = (string) parse_url('http://x' . $p, PHP_URL_PATH);
    $query = (string) parse_url('http://x' . $p, PHP_URL_QUERY);
    if (!in_array($m, ['GET', 'POST', 'HEAD'], true) || $p === '' || $p[0] !== '/' || strpos($path, "\0") !== false
        || preg_match('~(^|/)\.{1,2}(/|$)~', $path) || strpos($path, '/admin/') !== 0) {
        app_reply($ctx, ['h' => []], '', 404);
        exit();
    }
    if (strpos($path, '/admin/assets/') === 0) {
        app_web_static($ctx, $path, $h);
        exit();
    }
    if ($path !== '/admin/' && $path !== '/admin/index.php') {
        app_reply($ctx, ['h' => []], '', 404);
        exit();
    }

    $keep = ['HTTP_HOST', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CF_CONNECTING_IP', 'HTTP_TRUE_CLIENT_IP', 'HTTP_X_FORWARDED_PROTO', 'HTTP_FORWARDED'];
    foreach (array_keys($_SERVER) as $k) {
        if (strpos($k, 'HTTP_') === 0 && !in_array($k, $keep, true)) unset($_SERVER[$k]);
    }
    foreach ($h as $k => $v) {
        $sk = 'HTTP_' . strtoupper(str_replace('-', '_', $k));
        if (in_array($sk, $keep, true) || in_array($k, ['content-length', 'transfer-encoding', 'connection', 'content-type'], true)) continue;
        $_SERVER[$sk] = $v;
    }
    $body = (string) $ctx['body'];
    $ct   = (string) ($h['content-type'] ?? '');
    $_SERVER['REQUEST_METHOD']  = $m;
    $_SERVER['REQUEST_URI']     = $path . ($query !== '' ? '?' . $query : '');
    $_SERVER['QUERY_STRING']    = $query;
    $_SERVER['SCRIPT_NAME']     = '/admin/index.php';
    $_SERVER['PHP_SELF']        = '/admin/index.php';
    $_SERVER['SCRIPT_FILENAME'] = dirname(__DIR__) . '/admin/index.php';
    $_SERVER['HTTPS']           = 'on';
    $_SERVER['CONTENT_TYPE']    = $ct;
    $_SERVER['CONTENT_LENGTH']  = (string) strlen($body);
    unset($_SERVER['PATH_INFO'], $_SERVER['ORIG_PATH_INFO']);

    $_COOKIE = [];
    foreach (explode(';', (string) ($h['cookie'] ?? '')) as $kv) {
        $kv = trim($kv);
        $eq = strpos($kv, '=');
        if ($eq === false || $eq === 0) continue;
        $name = substr($kv, 0, $eq);
        if (!array_key_exists($name, $_COOKIE)) $_COOKIE[$name] = urldecode(substr($kv, $eq + 1));
    }
    $_GET = [];
    parse_str($query, $_GET);
    $_POST  = [];
    $_FILES = [];
    if ($m === 'POST') {
        if (stripos($ct, 'application/x-www-form-urlencoded') === 0) {
            parse_str($body, $_POST);
        } elseif (stripos($ct, 'multipart/form-data') === 0 && preg_match('~boundary=(?:"([^"]+)"|([^;\s]+))~i', $ct, $bm)) {
            app_multipart($body, $bm[1] !== '' ? $bm[1] : $bm[2]);
        }
    }
    $_REQUEST = array_merge($_GET, $_POST);
    if (!app_web_authed()) {
        app_reply($ctx, ['h' => [], 'auth' => false], '', 401);
        exit();
    }

    define('SUBMW_APP_TUNNEL', 1);
    $GLOBALS['app_ctx'] = $ctx;
    ob_start();
    $GLOBALS['app_ob'] = ob_get_level();
    register_shutdown_function('app_web_flush');
    return 'web';
}

function app_web_authed() {
    admin_session_boot();
    $sid = (string) ($_COOKIE[session_name()] ?? '');
    if ($sid === '' || !preg_match('~^[A-Za-z0-9,-]{16,256}$~', $sid)) return false;
    session_id($sid);
    if (!@session_start(['read_and_close' => true])) return false;
    $ok = app_session_ok(is_array($_SESSION) ? $_SESSION : []);
    $_SESSION = [];
    return $ok;
}

function app_multipart($body, $boundary) {
    $pairs = [];
    $files = [];
    $body  = (string) $body;
    $delim = "\r\n--" . $boundary;
    $pos   = strpos($body, '--' . $boundary);
    $pos   = $pos === false ? strlen($body) : $pos + strlen($boundary) + 2;
    while ($pos < strlen($body) && substr($body, $pos, 2) !== '--') {
        $hs = strpos($body, "\r\n", $pos);
        if ($hs === false) break;
        $hs += 2;
        if (substr($body, $hs, 2) === "\r\n") {
            $head = '';
            $ds = $hs + 2;
        } else {
            $he = strpos($body, "\r\n\r\n", $hs);
            if ($he === false) break;
            $head = substr($body, $hs, $he - $hs);
            $ds = $he + 4;
        }
        $de = strpos($body, $delim, $ds);
        if ($de === false) break;
        $data = substr($body, $ds, $de - $ds);
        $pos  = $de + strlen($delim);
        if (!preg_match('~content-disposition:[^\r\n]*?\bname="([^"]*)"~i', $head, $mn)) continue;
        $name = $mn[1];
        if (preg_match('~content-disposition:[^\r\n]*?\bfilename="([^"]*)"~i', $head, $mf)) {
            $type = preg_match('~content-type:\s*([^\r\n]+)~i', $head, $mt) ? trim($mt[1]) : 'application/octet-stream';
            $f = ['name' => basename(str_replace('\\', '/', $mf[1])), 'full_path' => $mf[1], 'type' => '', 'tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE, 'size' => 0];
            if ($mf[1] !== '') {
                $tmp = @tempnam(sys_get_temp_dir(), 'a1u');
                if ($tmp !== false && @file_put_contents($tmp, $data) !== false) {
                    $GLOBALS['app_uploads'][$tmp] = true;
                    $f = ['name' => $f['name'], 'full_path' => $mf[1], 'type' => $type, 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => strlen($data)];
                } else {
                    $f['error'] = UPLOAD_ERR_CANT_WRITE;
                }
            }
            $files[] = [$name, $f];
        } else {
            $pairs[] = rawurlencode($name) . '=' . rawurlencode($data);
        }
    }
    $_POST = [];
    parse_str(implode('&', $pairs), $_POST);
    $_FILES = [];
    foreach ($files as [$name, $f]) {
        if (preg_match('~^([^\[\]]+)\[([^\[\]]*)\]$~', $name, $nm)) {
            foreach ($f as $prop => $val) {
                if ($nm[2] === '') $_FILES[$nm[1]][$prop][] = $val;
                else $_FILES[$nm[1]][$prop][$nm[2]] = $val;
            }
        } else {
            $_FILES[$name] = $f;
        }
    }
}

function app_web_static(array $ctx, $path, array $h) {
    $types = ['css' => 'text/css; charset=utf-8', 'js' => 'application/javascript; charset=utf-8', 'json' => 'application/json',
              'svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif',
              'webp' => 'image/webp', 'ico' => 'image/x-icon', 'woff2' => 'font/woff2', 'woff' => 'font/woff',
              'webmanifest' => 'application/manifest+json', 'txt' => 'text/plain; charset=utf-8'];
    $base = realpath(dirname(__DIR__) . '/admin/assets');
    $file = realpath(dirname(__DIR__) . $path);
    $ext  = strtolower(pathinfo((string) $file, PATHINFO_EXTENSION));
    if ($base === false || $file === false || strpos($file, $base . DIRECTORY_SEPARATOR) !== 0 || !is_file($file) || !isset($types[$ext])) {
        app_reply($ctx, ['h' => []], '', 404);
        return;
    }
    $etag = '"' . substr((string) md5_file($file), 0, 16) . '"';
    if (($h['if-none-match'] ?? '') === $etag) {
        app_reply($ctx, ['h' => [['etag', $etag]]], '', 304);
        return;
    }
    app_reply($ctx, ['h' => [['content-type', $types[$ext]], ['etag', $etag], ['cache-control', 'no-cache']]], (string) file_get_contents($file), 200);
}

function app_web_flush() {
    $ctx = $GLOBALS['app_ctx'] ?? null;
    if (empty($ctx)) return;
    $GLOBALS['app_ctx'] = null;

    $body  = '';
    $floor = max(1, (int) ($GLOBALS['app_ob'] ?? 1));
    while (ob_get_level() >= $floor && ob_get_level() > 0) {
        $chunk = ob_get_clean();
        if ($chunk === false) break;
        $body = $chunk . $body;
    }
    foreach (array_keys($GLOBALS['app_uploads'] ?? []) as $tmp) @unlink($tmp);
    $GLOBALS['app_uploads'] = [];

    if (headers_sent()) {
        error_log('submw app: заголовки уже ушли, шифровать нечего');
        return;
    }

    $status = http_response_code();
    if (!is_int($status) || $status < 100) $status = 200;
    $hdrs = [];
    $has_ct = false;
    foreach (headers_list() as $line) {
        $parts = explode(':', $line, 2);
        if (count($parts) !== 2) continue;
        $name = strtolower(trim($parts[0]));
        if ($name === '' || in_array($name, ['content-length', 'transfer-encoding', 'connection', 'content-encoding', 'x-powered-by'], true)) continue;
        if ($name === 'content-type') $has_ct = true;
        $hdrs[] = [$name, trim($parts[1])];
    }
    if (!$has_ct) $hdrs[] = ['content-type', 'text/html; charset=UTF-8'];
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'HEAD') $body = '';
    $auth = isset($_SESSION) && is_array($_SESSION) && app_session_ok($_SESSION);

    app_reply($ctx, ['h' => $hdrs, 'auth' => $auth], $body, $status);
}
