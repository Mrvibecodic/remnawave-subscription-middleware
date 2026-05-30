<?php

function ensure_reqlog_hwid() {
    static $done = false;
    if ($done) return;
    $done = true;
    if (!($p = db())) return;
    if (setting('reqlog_hwid_col', '') !== '1') {
        try { $p->exec('ALTER TABLE request_log ADD COLUMN hwid TEXT NULL'); } catch (Throwable $e) {}
        set_setting('reqlog_hwid_col', '1');
    }
}

function log_request($ip, $short_uuid, $path, $ua, $decision, $expire_ts = null, $hwid = '') {
    if (!($p = db())) return;
    ensure_reqlog_hwid();
    try {
        $stmt = $p->prepare(
            'INSERT INTO request_log (ip, short_uuid, path, user_agent, decision, expire_ts, hwid)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $ip,
            $short_uuid !== '' ? $short_uuid : null,
            mb_substr((string) $path, 0, 255),
            mb_substr((string) $ua, 0, 255),
            $decision,
            $expire_ts,
            $hwid !== '' ? mb_substr((string) $hwid, 0, 191) : null,
        ]);
        if (random_int(1, 200) === 1) {
            $keep = request_log_retention();
            if ($keep > 0) {
                $p->exec("DELETE FROM request_log WHERE id < (
                    SELECT id FROM (SELECT id FROM request_log ORDER BY id DESC LIMIT 1 OFFSET $keep) t
                )");
            }
        }
    } catch (Throwable $e) {
        error_log('submw log_request: ' . $e->getMessage());
    }
}

function log_webhook($event, $short_uuid, $username, $status, $sig_ok, $action) {
    if (!($p = db())) return;
    try {
        $stmt = $p->prepare(
            'INSERT INTO webhook_log (event, short_uuid, username, status, sig_ok, action)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$event, $short_uuid, $username, $status, $sig_ok ? 1 : 0, $action]);
    } catch (Throwable $e) {
        error_log('submw log_webhook: ' . $e->getMessage());
    }
}

function log_forward($event, $target, $http_code, $ok, $error = '') {
    ensure_forward_log();
    if (!($p = db())) return;
    try {
        $stmt = $p->prepare(
            'INSERT INTO forward_log (event, target, http_code, ok, error) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $event !== null ? mb_substr((string) $event, 0, 64) : null,
            mb_substr((string) $target, 0, 255),
            $http_code !== null ? (int) $http_code : null,
            $ok ? 1 : 0,
            $error !== '' ? mb_substr((string) $error, 0, 255) : null,
        ]);
        if (random_int(1, 100) === 1) {
            $p->exec("DELETE FROM forward_log WHERE id < (
                SELECT id FROM (SELECT id FROM forward_log ORDER BY id DESC LIMIT 1 OFFSET 5000) t
            )");
        }
    } catch (Throwable $e) { error_log('submw log_forward: ' . $e->getMessage()); }
}

function parse_expire_from_userinfo($userinfo) {
    if (!$userinfo) return null;
    if (preg_match('/expire\s*=\s*(\d+)/i', $userinfo, $m)) {
        $v = (int) $m[1];
        return $v > 0 ? $v : null;
    }
    return null;
}

function path_segments($path) {
    $segs = array_filter(explode('/', trim((string) $path, '/')), fn($s) => $s !== '');
    return array_values($segs);
}

function client_ip() {
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR'] as $h) {
        if (!empty($_SERVER[$h])) {
            $ip = trim(explode(',', $_SERVER[$h])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? '';
}
