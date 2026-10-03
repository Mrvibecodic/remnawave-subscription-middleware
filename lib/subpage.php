<?php

function sub_source() {
    return setting('sub_source', 'mirror') === 'panel' ? 'panel' : 'mirror';
}

function subpage_active() {
    return sub_source() === 'panel';
}

function apisub_accept_active() {
    return setting('apisub_accept', '0') === '1';
}

function mask_notfound() {
    return setting('mask_notfound', '0') === '1';
}

function subpage_mirror_active() {
    return setting('subpage_mirror', '0') === '1';
}

function subpage_render_active() {
    return subpage_active() || subpage_mirror_active();
}

function subpage_external_url() {
    return rtrim(trim((string) setting('subpage_external_url', '')), '/');
}

function subpage_is_browser($ua) {
    if ($ua === '') return false;
    foreach (['Mozilla', 'Chrome', 'Safari', 'Firefox', 'Opera', 'Edge', 'TelegramBot', 'WhatsApp'] as $k) {
        if (strpos($ua, $k) !== false) return true;
    }
    return false;
}

function subpage_external_proxy($path, $query) {
    $base = subpage_external_url();
    if ($base === '') { http_response_code(502); return; }
    $url = $base . '/' . ltrim($path, '/');
    if ($query !== '') $url .= '?' . $query;

    $headers = [];
    $pk = pagekeys_active() && strpos('/' . ltrim((string) $path, '/'), '/assets/') !== 0;
    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            if (strtolower($k) === 'host') continue;
            if ($pk && in_array(strtolower($k), ['if-none-match', 'if-modified-since', 'if-match', 'if-unmodified-since', 'if-range'], true)) continue;
            $headers[] = "$k: $v";
        }
    }
    $headers[] = 'x-remnawave-real-ip: ' . client_ip();
    $headers = panel_auth_headers($headers);

    $grabbed = [];
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => proxy_timeout(),
        CURLOPT_SSL_VERIFYPEER => api_tls_verify(),
        CURLOPT_SSL_VERIFYHOST => api_tls_verify() ? 2 : 0,
        CURLOPT_ENCODING       => '',
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_HEADERFUNCTION => function ($c, $h) use (&$grabbed) {
            $len  = strlen($h);
            $trim = trim($h);
            if ($trim === '' || strpos($trim, 'HTTP/') === 0) return $len;
            $parts = explode(':', $trim, 2);
            if (count($parts) === 2) $grabbed[] = [trim($parts[0]), trim($parts[1])];
            return $len;
        },
    ]);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err) { http_response_code(502); return; }
    if (mask_notfound() && $code === 404) { header_remove('X-Powered-By'); http_response_code(404); return; }

    $is_html = false;
    foreach ($grabbed as $hv) {
        if (strtolower($hv[0]) === 'content-type' && stripos($hv[1], 'text/html') === 0) $is_html = true;
    }
    $resp_orig = $resp;
    if ($pk && $is_html && $code === 200 && is_string($resp)) $resp = pagekeys_apply($resp);
    $modified = ($resp !== $resp_orig);

    http_response_code($code ?: 200);
    $unsafe = ['transfer-encoding', 'content-length', 'content-encoding', 'connection'];
    foreach ($grabbed as $hv) {
        $lk = strtolower($hv[0]);
        if (in_array($lk, $unsafe, true)) continue;
        if ($modified && ($lk === 'etag' || $lk === 'last-modified')) continue;
        header($hv[0] . ': ' . $hv[1], false);
    }
    echo $resp;
}

function subpage_dispatch($path, $query, $wire_path = null) {
    if (!subpage_render_active()) return false;
    if (subpage_external_url() === '') return false;

    $p  = '/' . ltrim($path, '/');
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

    if (strpos($p, '/assets/') === 0 || subpage_is_browser($ua)) {
        $GLOBALS['submw_skip_metric'] = true;
        if (strpos($p, '/assets/') !== 0 && subpage_denied($path)) {
            header_remove('X-Powered-By');
            http_response_code(404);
            return true;
        }
        subpage_external_proxy($wire_path === null ? $path : $wire_path, $query);
        return true;
    }
    return false;
}

function subpage_denied($path) {
    $segs = path_segments($path);
    if (!$segs) return false;
    $ov = find_override_in('shortuuid', $segs);
    if ($ov && ($ov['reason'] ?? '') === 'blocked') return true;
    $short = $ov ? (string) $ov['match_value'] : (string) $segs[0];
    return !chan_active() && chan_page_404() && chan_state_get($short) !== null;
}
