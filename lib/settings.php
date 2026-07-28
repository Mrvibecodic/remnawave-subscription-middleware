<?php

function target_domain() {
    return trim((string) setting('target_domain', ''));
}

function mirror_domain() {
    $v = trim((string) setting('mirror_domain', ''));
    if ($v !== '') return $v;
    return $_SERVER['HTTP_HOST'] ?? '';
}

function webhook_secret() { return (string) setting('webhook_secret', ''); }

function remnawave_url() { return rtrim((string) setting('remnawave_url', ''), '/'); }

function remnawave_token() { return (string) setting('remnawave_api_key', ''); }

function remnawave_cookie() { return trim((string) setting('remnawave_cookie', '')); }

function proxy_timeout() { return (int) (setting('proxy_timeout', '30') ?: 30); }

function trust_header_expire() { return setting('trust_header_expire', '1') === '1'; }

function api_tls_verify() { return setting('tls_verify', '1') === '1'; }

function sub_link_apisub() { return setting('sub_link_apisub', '0') === '1'; }

function ua_hwid_parse() { return setting('ua_hwid_parse', '0') === '1'; }

function ua_hwid_keys_all() { return ['x-hwid', 'x-device-os', 'x-ver-os', 'x-device-model']; }

function ua_hwid_keys() {
    $all = ua_hwid_keys_all();
    $raw = json_decode((string) setting('ua_hwid_keys', '["x-hwid"]'), true);
    if (!is_array($raw)) return ['x-hwid'];
    $out = [];
    foreach ($raw as $k) {
        $k = strtolower(trim((string) $k));
        if (in_array($k, $all, true) && !in_array($k, $out, true)) $out[] = $k;
    }
    return $out ?: ['x-hwid'];
}

function ua_hwid_extract($ua, $key) {
    $ua  = (string) $ua;
    $key = (string) $key;
    if ($ua === '' || $key === '') return '';
    if (!preg_match('/' . preg_quote($key, '/') . '=([^;)]+)/i', $ua, $m)) return '';
    $val = preg_replace('/[\x00-\x1F\x7F]/', '', trim($m[1]));
    return strlen($val) > 256 ? substr($val, 0, 256) : $val;
}

function expired_grace_days() { return max(0, (int) (setting('expired_grace_days', '7'))); }

function expired_grace_passed($expire_ts, $created_at_str = null, $now = null) {
    $days = expired_grace_days();
    if ($days <= 0) return false;
    $now   = $now ?? time();
    $since = null;
    if ($expire_ts !== null && (int) $expire_ts > 0) {
        $since = (int) $expire_ts;
    } elseif ($created_at_str) {
        $ts = strtotime((string) $created_at_str);
        if ($ts !== false) $since = $ts;
    }
    if ($since === null) return false;
    return ($now - $since) > $days * 86400;
}

function request_log_retention() { return (int) (setting('request_log_retention', '50000') ?: 50000); }

function grace_squad_enabled() { return setting('grace_squad_enabled', '0') === '1'; }

function grace_squad_uuid() { return trim((string) setting('grace_squad_uuid', '')); }

function grace_traffic_bytes() { return max(0, (int) setting('grace_traffic_bytes', '0')); }

function grace_traffic_strategy() {
    $s  = (string) setting('grace_traffic_strategy', 'NO_RESET');
    $ok = ['NO_RESET', 'DAY', 'WEEK', 'MONTH', 'MONTH_ROLLING'];
    return in_array($s, $ok, true) ? $s : 'NO_RESET';
}

function grace_hwid_limit_raw() { return trim((string) setting('grace_hwid_limit', '')); }

function grace_announce() { return (string) setting('grace_announce', ''); }

function grace_days() {
    $v = setting('grace_days', '');
    return $v === '' ? expired_grace_days() : max(0, (int) $v);
}

function grace_squad_active() {
    return grace_squad_enabled() && grace_squad_uuid() !== '' && remnawave_url() !== '' && remnawave_token() !== '';
}

function grace_external_enabled() { return setting('grace_external_enabled', '0') === '1'; }

function grace_external_squad_uuid() { return trim((string) setting('grace_external_squad_uuid', '')); }

function grace_external_active() {
    return grace_external_enabled() && grace_external_squad_uuid() !== '' && remnawave_url() !== '' && remnawave_token() !== '';
}

function forward_enabled() { return setting('forward_enabled', '0') === '1'; }

function forward_timeout() { return max(2, (int) (setting('forward_timeout', '8') ?: 8)); }

function forward_targets() {
    $arr = json_decode((string) setting('forward_targets', '[]'), true);
    if (!is_array($arr)) return [];
    $out = [];
    foreach ($arr as $t) {
        if (!is_array($t)) continue;
        $url = trim((string) ($t['url'] ?? ''));
        if ($url === '') continue;
        $out[] = [
            'name'    => trim((string) ($t['name'] ?? '')),
            'url'     => $url,
            'secret'  => (string) ($t['secret'] ?? ''),
            'enabled' => !empty($t['enabled']),
        ];
    }
    return $out;
}

function forward_webhook($raw_body, $event = null, $force = false, $targets = null) {
    if (!$force && !forward_enabled()) return [];
    if ($targets === null) $targets = forward_targets();
    if (!$targets) return [];

    $timeout = forward_timeout();
    $results = [];
    foreach ($targets as $t) {
        if (!$t['enabled']) continue;
        $label = $t['name'] !== '' ? $t['name'] : $t['url'];
        $sig   = hash_hmac('sha256', $raw_body, $t['secret']);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $t['url'],
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $raw_body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_NOSIGNAL       => true,
            CURLOPT_SSL_VERIFYPEER => api_tls_verify(),
            CURLOPT_SSL_VERIFYHOST => api_tls_verify() ? 2 : 0,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'X-Remnawave-Signature: ' . $sig,
            ],
        ]);
        curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $ok = ($err === '' && $code >= 200 && $code < 300);
        $results[] = ['name' => $t['name'], 'url' => $t['url'], 'code' => $code, 'ok' => $ok, 'error' => $err];
        log_forward($event, $label, $code, $ok, $err);
    }
    return $results;
}
