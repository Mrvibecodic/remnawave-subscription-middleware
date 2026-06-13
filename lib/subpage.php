<?php

function sub_source() {
    return setting('sub_source', 'mirror') === 'panel' ? 'panel' : 'mirror';
}

function subpage_active() {
    return sub_source() === 'panel';
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
    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            if (strtolower($k) === 'host') continue;
            $headers[] = "$k: $v";
        }
    }
    $headers[] = 'x-remnawave-real-ip: ' . client_ip();
    $headers = panel_cookie_header($headers);

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

    http_response_code($code ?: 200);
    $unsafe = ['transfer-encoding', 'content-length', 'content-encoding', 'connection'];
    foreach ($grabbed as $hv) {
        if (in_array(strtolower($hv[0]), $unsafe, true)) continue;
        header($hv[0] . ': ' . $hv[1], false);
    }
    echo $resp;
}

function subpage_dispatch($path, $query) {
    if (!subpage_active()) return false;
    if (subpage_external_url() === '') return false;

    $p  = '/' . ltrim($path, '/');
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

    if (strpos($p, '/assets/') === 0 || subpage_is_browser($ua)) {
        $GLOBALS['submw_skip_metric'] = true;
        subpage_external_proxy($path, $query);
        return true;
    }
    return false;
}
