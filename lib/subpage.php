<?php

function sub_source() {
    return setting('sub_source', 'mirror') === 'panel' ? 'panel' : 'mirror';
}

function subpage_active() {
    return sub_source() === 'panel';
}

function subpage_render_mode() {
    return setting('subpage_render', 'embedded') === 'external' ? 'external' : 'embedded';
}

function subpage_external_url() {
    return rtrim(trim((string) setting('subpage_external_url', '')), '/');
}

function subpage_token() {
    if (setting('subpage_token_mode', 'shared') === 'separate') {
        $t = trim((string) setting('subpage_api_key', ''));
        if ($t !== '') return $t;
    }
    return remnawave_token();
}

function subpage_dir() {
    return dirname(__DIR__) . '/subpage';
}

function subpage_manifest() {
    static $m = null;
    if ($m !== null) return $m;
    $m = [];
    $f = subpage_dir() . '/manifest.json';
    if (is_file($f)) {
        $j = json_decode((string) file_get_contents($f), true);
        if (is_array($j)) $m = $j;
    }
    return $m;
}

function subpage_app_config_route() {
    $m = subpage_manifest();
    $r = $m['appConfigRoute'] ?? '/assets/.app-config-v2.json';
    return '/' . ltrim((string) $r, '/');
}

function subpage_placeholders() {
    $m = subpage_manifest();
    $p = is_array($m['placeholders'] ?? null) ? $m['placeholders'] : [];
    return [
        'metaTitle'       => (string) ($p['metaTitle'] ?? '<%= metaTitle %>'),
        'metaDescription' => (string) ($p['metaDescription'] ?? '<%= metaDescription %>'),
        'panelData'       => (string) ($p['panelData'] ?? '<%- panelData %>'),
    ];
}

function subpage_is_browser($ua) {
    if ($ua === '') return false;
    foreach (['Mozilla', 'Chrome', 'Safari', 'Firefox', 'Opera', 'Edge', 'TelegramBot', 'WhatsApp'] as $k) {
        if (strpos($ua, $k) !== false) return true;
    }
    return false;
}

function subpage_asset_ctype($file) {
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $map = [
        'js'    => 'application/javascript; charset=utf-8',
        'mjs'   => 'application/javascript; charset=utf-8',
        'css'   => 'text/css; charset=utf-8',
        'json'  => 'application/json; charset=utf-8',
        'svg'   => 'image/svg+xml',
        'png'   => 'image/png',
        'jpg'   => 'image/jpeg',
        'jpeg'  => 'image/jpeg',
        'gif'   => 'image/gif',
        'webp'  => 'image/webp',
        'ico'   => 'image/x-icon',
        'woff'  => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf'   => 'font/ttf',
        'map'   => 'application/json; charset=utf-8',
        'txt'   => 'text/plain; charset=utf-8',
    ];
    return $map[$ext] ?? 'application/octet-stream';
}

function subpage_serve_asset($relpath) {
    $base = realpath(subpage_dir());
    if ($base === false) { http_response_code(404); return; }
    $full = realpath($base . '/' . $relpath);
    if ($full === false || strpos($full, $base . DIRECTORY_SEPARATOR) !== 0 || !is_file($full)) {
        http_response_code(404);
        return;
    }
    header('Content-Type: ' . subpage_asset_ctype($full));
    header('Cache-Control: public, max-age=31536000, immutable');
    header('Content-Length: ' . filesize($full));
    readfile($full);
}

function subpage_api_get($path) {
    $base  = remnawave_url();
    $token = subpage_token();
    if ($base === '' || $token === '') return [false, 0, null];
    $headers = ['Authorization: Bearer ' . $token, 'Accept: application/json'];
    $cookie = remnawave_cookie();
    if ($cookie !== '') $headers[] = 'Cookie: ' . $cookie;

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $base . '/' . ltrim($path, '/'),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => api_tls_verify(),
        CURLOPT_SSL_VERIFYHOST => api_tls_verify() ? 2 : 0,
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($err || $code < 200 || $code >= 300) return [false, $code, null];
    return [true, $code, json_decode((string) $body, true)];
}

function subpage_public_get($path) {
    $base = remnawave_url();
    if ($base === '') return null;
    $headers = [
        'Accept: */*',
        'x-remnawave-real-ip: ' . client_ip(),
        'User-Agent: ' . ($_SERVER['HTTP_USER_AGENT'] ?? 'submw'),
    ];
    $cookie = remnawave_cookie();
    if ($cookie !== '') $headers[] = 'Cookie: ' . $cookie;

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $base . '/' . ltrim($path, '/'),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => proxy_timeout(),
        CURLOPT_SSL_VERIFYPEER => api_tls_verify(),
        CURLOPT_SSL_VERIFYHOST => api_tls_verify() ? 2 : 0,
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($err || $code < 200 || $code >= 300) return null;
    return (string) $body;
}

function subpage_visual_config() {
    static $cfg = null;
    if ($cfg !== null) return $cfg ?: null;

    $ttl = 300;
    $ts  = (int) setting('subpage_cfg_cache_ts', '0');
    $raw = (string) setting('subpage_cfg_cache', '');
    if ($raw !== '' && (time() - $ts) < $ttl) {
        $dec = json_decode($raw, true);
        if (is_array($dec)) { $cfg = $dec; return $cfg; }
    }

    [$ok, , $list] = subpage_api_get('/api/subscription-page-configs');
    if (!$ok) { $cfg = false; return null; }
    $resp    = $list['response'] ?? $list;
    $configs = $resp['configs'] ?? [];
    $uuid    = '';
    if (is_array($configs) && isset($configs[0]['uuid'])) $uuid = (string) $configs[0]['uuid'];
    if ($uuid === '') { $cfg = false; return null; }

    [$ok2, , $one] = subpage_api_get('/api/subscription-page-configs/' . rawurlencode($uuid));
    if (!$ok2) { $cfg = false; return null; }
    $r2  = $one['response'] ?? $one;
    $vis = $r2['config'] ?? null;
    if (!is_array($vis)) { $cfg = false; return null; }

    set_setting('subpage_cfg_cache', json_encode($vis, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    set_setting('subpage_cfg_cache_ts', (string) time());
    $cfg = $vis;
    return $cfg;
}

function subpage_serve_app_config() {
    $cfg = subpage_visual_config();
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if (!is_array($cfg)) { http_response_code(503); echo '{}'; return; }
    echo json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function subpage_render_page($short) {
    $cfg = subpage_visual_config();
    if (!is_array($cfg)) return false;

    $info = subpage_public_get('/api/sub/' . rawurlencode($short) . '/info');
    if ($info === null) return false;
    $data = json_decode($info, true);
    if (!is_array($data)) return false;

    $base = is_array($cfg['baseSettings'] ?? null) ? $cfg['baseSettings'] : [];
    $show = !empty($base['showConnectionKeys']);
    if (!$show && isset($data['response']) && is_array($data['response'])) {
        $data['response']['links']       = [];
        $data['response']['ssConfLinks'] = (object) [];
    }

    $panelData = base64_encode(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    $tpl = @file_get_contents(subpage_dir() . '/index.html');
    if ($tpl === false) return false;

    $ph    = subpage_placeholders();
    $title = htmlspecialchars((string) ($base['metaTitle'] ?? 'Subscription'), ENT_QUOTES, 'UTF-8');
    $desc  = htmlspecialchars((string) ($base['metaDescription'] ?? 'Subscription'), ENT_QUOTES, 'UTF-8');

    $tpl = str_replace($ph['metaTitle'], $title, $tpl);
    $tpl = str_replace($ph['metaDescription'], $desc, $tpl);
    $tpl = str_replace($ph['panelData'], $panelData, $tpl);

    $GLOBALS['submw_skip_metric'] = true;
    header('Content-Type: text/html; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow');
    emit_response_headers();
    echo $tpl;
    return true;
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
    if (remnawave_url() === '') return false;

    $p     = '/' . ltrim($path, '/');
    $route = subpage_app_config_route();
    $ua    = $_SERVER['HTTP_USER_AGENT'] ?? '';

    if (subpage_render_mode() === 'external') {
        $is_asset = ($p === $route) || strpos($p, '/assets/') === 0;
        if ($is_asset || subpage_is_browser($ua)) {
            $GLOBALS['submw_skip_metric'] = true;
            subpage_external_proxy($path, $query);
            return true;
        }
        return false;
    }

    if ($p === $route) {
        $GLOBALS['submw_skip_metric'] = true;
        subpage_serve_app_config();
        return true;
    }
    if (strpos($p, '/assets/') === 0) {
        $GLOBALS['submw_skip_metric'] = true;
        subpage_serve_asset(ltrim($path, '/'));
        return true;
    }
    if (subpage_is_browser($ua)) {
        $segs  = path_segments($path);
        $short = $segs[0] ?? '';
        if ($short === '') return false;
        return subpage_render_page($short);
    }
    return false;
}
