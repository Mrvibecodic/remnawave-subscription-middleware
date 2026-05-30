<?php

function app_headers_all() {
    $arr = json_decode((string) setting('app_headers', '[]'), true);
    if (!is_array($arr)) return [];
    $out = [];
    foreach ($arr as $t) {
        if (!is_array($t)) continue;
        $name = trim((string) ($t['name'] ?? ''));
        if ($name === '') continue;
        $out[] = [
            'name'    => $name,
            'value'   => (string) ($t['value'] ?? ''),
            'enabled' => !empty($t['enabled']),
            'note'    => trim((string) ($t['note'] ?? '')),
        ];
    }
    return $out;
}

function app_headers_send() {
    $blocked = ['content-length', 'content-encoding', 'transfer-encoding', 'connection', 'host', 'content-type'];
    $out = [];
    foreach (app_headers_all() as $t) {
        if (!$t['enabled']) continue;
        if (!preg_match('/^[A-Za-z0-9-]+$/', $t['name'])) continue;
        if (in_array(strtolower($t['name']), $blocked, true)) continue;
        $value = str_replace(["\r", "\n"], '', $t['value']);
        if ($value === '') continue;
        $out[$t['name']] = $value;
    }
    return $out;
}

function emit_app_headers() {
    foreach (app_headers_send() as $name => $value) {
        header($name . ': ' . $value);
    }
}

function remnawave_panel_headers(&$error = '') {
    $error = '';
    $out = [];
    [$ok, $code, $data, $e] = remnawave_api_get('/api/subscription-settings');
    if (!$ok) { $error = $e ?: ('HTTP ' . $code); return $out; }
    $resp = $data['response'] ?? $data;
    if (!is_array($resp)) return $out;
    $crh = $resp['customResponseHeaders'] ?? null;
    if (is_array($crh)) {
        foreach ($crh as $k => $v) {
            if (is_string($k) && $k !== '') $out[$k] = is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE);
        }
    }
    return $out;
}

function brand_dir() { return dirname(__DIR__) . '/admin/assets'; }

function brand_find_logo($node, $depth = 0) {
    if (!is_array($node) || $depth > 8) return '';
    foreach ($node as $k => $v) {
        if (is_string($v) && $v !== '' && preg_match('~^(https?://|/|data:image)~i', $v)) {
            $lk = is_string($k) ? strtolower($k) : '';
            if (preg_match('/(logo|favicon|icon|image|brand)/', $lk)) return $v;
        }
    }
    foreach ($node as $v) {
        if (is_array($v)) { $r = brand_find_logo($v, $depth + 1); if ($r !== '') return $r; }
    }
    return '';
}

function brand_find_title($node, $depth = 0) {
    if (!is_array($node) || $depth > 6) return '';
    foreach ($node as $k => $v) {
        if (is_string($v) && $v !== '' && mb_strlen($v) <= 60 && !preg_match('~^https?://~i', $v)) {
            $lk = is_string($k) ? strtolower($k) : '';
            if (preg_match('/(^title$|tuititle|brandtitle|brandingtitle|brandname|brand_title)/', $lk)) return $v;
        }
    }
    foreach ($node as $v) {
        if (is_array($v)) { $r = brand_find_title($v, $depth + 1); if ($r !== '') return $r; }
    }
    return '';
}

function remnawave_branding(&$error = '') {
    $error = '';
    $name = ''; $logo = '';
    [$ok, $code, $data, $e] = remnawave_api_get('/api/auth/status');
    if ($ok && is_array($data)) {
        $resp = $data['response'] ?? $data;
        $name = brand_find_title($resp);
        $logo = brand_find_logo($resp);
    } elseif ($error === '') {
        $error = ($e ?: ('HTTP ' . $code)) . ' /api/auth/status';
    }
    if ($name === '' || $logo === '') {
        [$ok2, $code2, $data2, $e2] = remnawave_api_get('/api/remnawave-settings');
        if ($ok2 && is_array($data2)) {
            $resp2 = $data2['response'] ?? $data2;
            $b = (is_array($resp2) && isset($resp2['brandingSettings']) && is_array($resp2['brandingSettings'])) ? $resp2['brandingSettings'] : [];
            if ($name === '') $name = trim((string) ($b['title'] ?? ''));
            if ($logo === '') $logo = trim((string) ($b['logoUrl'] ?? ''));
        } elseif ($name === '' && $logo === '') {
            $error = ($e2 ?: ('HTTP ' . $code2)) . ' /api/remnawave-settings (токену нужны права на настройки)';
        }
    }
    return ['name' => $name, 'logo' => $logo, 'name_score' => $name !== '' ? 3 : 0];
}

function brand_download_logo($url) {
    $url = trim((string) $url);
    if ($url === '' || !preg_match('~^https?://~i', $url)) return '';
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 4,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);
    $cookie = remnawave_cookie();
    if ($cookie !== '') curl_setopt($ch, CURLOPT_HTTPHEADER, ['Cookie: ' . $cookie]);
    $body = curl_exec($ch);
    $ct   = strtolower((string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE));
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $code < 200 || $code >= 300 || strlen($body) > 1048576) return '';
    $map = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/svg+xml' => 'svg', 'image/webp' => 'webp', 'image/gif' => 'gif', 'image/x-icon' => 'ico', 'image/vnd.microsoft.icon' => 'ico'];
    $ext = '';
    foreach ($map as $m => $x) { if (strpos($ct, $m) !== false) { $ext = $x; break; } }
    if ($ext === '') {
        if (strncmp($body, "\x89PNG", 4) === 0) $ext = 'png';
        elseif (strncmp($body, "\xFF\xD8", 2) === 0) $ext = 'jpg';
        elseif (stripos(substr($body, 0, 256), '<svg') !== false) $ext = 'svg';
        else return '';
    }
    $dir = brand_dir();
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    if (!is_dir($dir)) return '';
    foreach (glob($dir . '/brand_logo.*') ?: [] as $old) @unlink($old);
    if (@file_put_contents($dir . '/brand_logo.' . $ext, $body) === false) return '';
    return 'assets/brand_logo.' . $ext;
}

function brand_refresh(&$error = '') {
    $error = '';
    $manual_name = trim((string) setting('service_name', ''));
    $manual_logo = trim((string) setting('service_logo_url', ''));
    $auto = ['name' => '', 'logo' => ''];
    if ($manual_name === '' || $manual_logo === '') $auto = remnawave_branding($error);
    $name     = $manual_name !== '' ? $manual_name : $auto['name'];
    $logo_url = $manual_logo !== '' ? $manual_logo : $auto['logo'];
    $logo_file = $logo_url !== '' ? brand_download_logo($logo_url) : '';
    $cache = ['name' => $name, 'logo_url' => $logo_url, 'logo_file' => $logo_file, 'ts' => time(), 'v' => 5, 'api_error' => $error];
    set_setting('brand_cache', json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    return $cache;
}

function brand_lead_emoji($s) {
    $s = trim((string) $s);
    if ($s === '') return '';
    if (preg_match('/^(\X)/u', $s, $m)) {
        $g = $m[1];
        if (preg_match('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{2190}-\x{21FF}\x{1F1E6}-\x{1F1FF}\x{FE0F}\x{2122}\x{2139}]/u', $g)) {
            return $g;
        }
    }
    return '';
}

function service_brand() {
    $cache = json_decode((string) setting('brand_cache', '{}'), true);
    if (!is_array($cache)) $cache = [];
    $manual_name = trim((string) setting('service_name', ''));
    $raw  = $manual_name !== '' ? $manual_name : (string) ($cache['name'] ?? '');
    $logo = (string) ($cache['logo_file'] ?? '');
    $emoji = $logo === '' ? brand_lead_emoji($raw) : '';
    $name = $raw;
    if ($emoji !== '') {
        $stripped = trim(preg_replace('/^\X[\x{FE0F}\x{200D}\s]*/u', '', $raw));
        if ($stripped !== '') $name = $stripped;
    }
    return [
        'name'      => $name !== '' ? $name : 'Прослойка',
        'logo_file' => $logo,
        'emoji'     => $emoji,
    ];
}
