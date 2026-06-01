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
    if (setting('reqlog_isapp_col', '') !== '1') {
        try { $p->exec('ALTER TABLE request_log ADD COLUMN is_app INTEGER NOT NULL DEFAULT 1'); } catch (Throwable $e) {}
        set_setting('reqlog_isapp_col', '1');
    }
}

function is_browser_ua($ua) {
    $ua = strtolower((string) $ua);
    if ($ua === '') return false;
    if (preg_match('~v2ray|nekobox|nekoray|sing-box|sing_box|hiddify|streisand|shadowrocket|stash|clash|mihomo|\bmeta\b|flclash|clashx|verge|happ|ktor|okhttp|go-http|v2box|foxray|karing|\bloon\b|surge|quantumult|throne|exclave|husi|matsuri|wireguard|outline|sfa|sfi|sft~', $ua)) return false;
    return (strpos($ua, 'mozilla') !== false || strpos($ua, 'applewebkit') !== false || strpos($ua, 'gecko') !== false)
        && preg_match('~chrome|chromium|safari|firefox|\bedg|\bopr\b|trident|gecko/~', $ua);
}

function log_request($ip, $short_uuid, $path, $ua, $decision, $expire_ts = null, $hwid = '') {
    if (!($p = db())) return;
    ensure_reqlog_hwid();
    try {
        $stmt = $p->prepare(
            'INSERT INTO request_log (ip, short_uuid, path, user_agent, decision, expire_ts, hwid, is_app)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $ip,
            $short_uuid !== '' ? $short_uuid : null,
            mb_substr((string) $path, 0, 255),
            mb_substr((string) $ua, 0, 255),
            $decision,
            $expire_ts,
            $hwid !== '' ? mb_substr((string) $hwid, 0, 191) : null,
            is_browser_ua($ua) ? 0 : 1,
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

function reqlog_is_real($grabbed_headers, $decision, $short_ov) {
    if (is_array($grabbed_headers) && isset($grabbed_headers['subscription-userinfo'])) return true;
    if (in_array($decision, ['blocked', 'expired', 'grace'], true)) return true;
    if (!empty($short_ov)) return true;
    return false;
}

function reqlog_today_stats() {
    $out = ['today_users' => 0, 'today_devices' => 0, 'total_devices' => 0, 'label' => date('d.m.Y')];
    if (!($p = db())) return $out;
    ensure_reqlog_hwid();
    $tzoff    = isset($_COOKIE['tzoff']) ? max(-720, min(840, (int) $_COOKIE['tzoff'])) * 60 : (int) date('Z');
    $nowLocal = time() + $tzoff;
    $dayStart = $nowLocal - ($nowLocal % 86400) - $tzoff;
    $out['label'] = gmdate('d.m.Y', $nowLocal);
    try {
        $st = $p->prepare("SELECT COUNT(DISTINCT short_uuid) FROM request_log WHERE short_uuid IS NOT NULL AND is_app = 1 AND " . sql_epoch('ts') . " >= ?");
        $st->execute([$dayStart]); $out['today_users'] = (int) $st->fetchColumn();
        $st = $p->prepare("SELECT COUNT(DISTINCT hwid) FROM request_log WHERE hwid IS NOT NULL AND hwid <> '' AND " . sql_epoch('ts') . " >= ?");
        $st->execute([$dayStart]); $out['today_devices'] = (int) $st->fetchColumn();
        $out['total_devices'] = (int) $p->query("SELECT COUNT(DISTINCT hwid) FROM request_log WHERE hwid IS NOT NULL AND hwid <> ''")->fetchColumn();
    } catch (Throwable $e) {}
    return $out;
}

function log_webhook($event, $short_uuid, $username, $status, $sig_ok, $action) {
    if (!($p = db())) return;
    try {
        $stmt = $p->prepare(
            'INSERT INTO webhook_log (event, short_uuid, username, status, sig_ok, action)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$event, $short_uuid, $username, $status, $sig_ok ? 1 : 0, $action]);
        if (random_int(1, 100) === 1) {
            $p->exec("DELETE FROM webhook_log WHERE id < (
                SELECT id FROM (SELECT id FROM webhook_log ORDER BY id DESC LIMIT 1 OFFSET 20000) t
            )");
        }
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
