<?php

ini_set('display_errors', 0);
error_reporting(E_ALL);

require __DIR__ . '/lib.php';

$target_domain = target_domain();

$request_uri = $_SERVER['REQUEST_URI'] ?? '/';
$parsed_url  = parse_url($request_uri);
$path        = isset($parsed_url['path']) ? ltrim($parsed_url['path'], '/') : '';
$query       = isset($parsed_url['query']) ? $parsed_url['query'] : '';

if (empty($path) || $path === 'index.php') {
    header('X-Robots-Tag: noindex, nofollow');
    landing_render();
    exit();
}

register_shutdown_function(function () {
    if (!function_exists('fastcgi_finish_request') || !function_exists('metrics_tick') || !empty($GLOBALS['submw_skip_metric'])) return;
    $t0 = $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true);
    @fastcgi_finish_request();
    metrics_tick((microtime(true) - $t0) * 1000, memory_get_peak_usage(true), !empty($GLOBALS['submw_real_sub']));
});
register_shutdown_function(function () {
    if (!function_exists('grace_retry_pending') || !empty($GLOBALS['submw_skip_metric'])) return;
    if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
    grace_retry_pending();
});

$skip_log =
    $path === ''
    || preg_match('~(^|/)(\.[^/]*|cdn-cgi/|assets/|static/|_next/)~i', $path)
    || preg_match('~\.(js|mjs|css|map|json|png|jpe?g|gif|svg|webp|ico|woff2?|ttf|eot|txt|xml|html?|env|bak|old|orig|save|swp|swo|copy|backup|tmp|sql|ya?ml|ini|conf|cfg|config|log|pem|key|crt|pfx|p12|zip|tar|gz|tgz|rar|7z|asp|aspx|jsp|cgi|exe|sh|bat|php\d?)$~i', $path)
    || substr($path, -1) === '~'
    || preg_match('~^(app|api|backend|frontend|server|config|credentials|secrets|keyfile|phpinfo\.php|wp-login\.php|wp-admin|xmlrpc\.php)$~i', $path)
    || preg_match('~(^|/)(favicon\.ico|robots\.txt|sitemap\.xml|browserconfig\.xml|apple-touch-icon[\w-]*\.png)$~i', $path);

$target_url = 'https://' . $target_domain . '/' . $path;
if ($query) $target_url .= '?' . $query;

$request_headers = [];
if (function_exists('getallheaders')) {
    foreach (getallheaders() as $key => $value) {
        if (strtolower($key) !== 'host') $request_headers[] = "$key: $value";
    }
} else {
    foreach ($_SERVER as $key => $value) {
        if (substr($key, 0, 5) === 'HTTP_') {
            $hn = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
            if (strtolower($hn) !== 'host') $request_headers[] = "$hn: $value";
        }
    }
}

$grabbed_headers = [];
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => $target_url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 5,
    CURLOPT_TIMEOUT        => proxy_timeout(),
    CURLOPT_SSL_VERIFYPEER => api_tls_verify(),
    CURLOPT_SSL_VERIFYHOST => api_tls_verify() ? 2 : 0,
    CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
    CURLOPT_ENCODING       => '',
    CURLOPT_HTTPHEADER     => $request_headers,
    CURLOPT_HEADER         => false,
    CURLOPT_HEADERFUNCTION => function ($curl, $header) use (&$grabbed_headers) {
        $len = strlen($header);
        $trim = trim($header);
        if ($trim === '' || strpos($trim, 'HTTP/') === 0) return $len;
        $parts = explode(':', $trim, 2);
        if (count($parts) === 2) {
            $grabbed_headers[strtolower(trim($parts[0]))] = trim($parts[1]);
        }
        return $len;
    },
]);
$response  = curl_exec($ch);
$curl_err  = curl_error($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$ip     = client_ip();
$ua     = $_SERVER['HTTP_USER_AGENT'] ?? '';
$segs   = path_segments($path);
$format = detect_client_format();

$short_ov = find_override_in('shortuuid', $segs);

if ($curl_err) {
    http_response_code(502);
    if (!$skip_log && $short_ov) log_request($ip, $short_ov['match_value'], $path, $ua, 'error');
    die();
}

$current_hwid = $_SERVER['HTTP_X_HWID'] ?? '';
$expire_ts    = parse_expire_from_userinfo($grabbed_headers['subscription-userinfo'] ?? null);
$now          = time();
$trust_header = trust_header_expire();

$decision   = 'normal';
$short_uuid = '';

$blocked = false;
if ($current_hwid !== '') {
    $hwid_ov = find_override('hwid', $current_hwid);
    if ($hwid_ov && $hwid_ov['reason'] === 'blocked') $blocked = true;
}
if ($short_ov) {
    $short_uuid = $short_ov['match_value'];
    if ($short_ov['reason'] === 'blocked') $blocked = true;
}

$expired = false;
$header_says_expired = ($expire_ts !== null && $expire_ts < $now);
$header_says_valid   = ($expire_ts !== null && $expire_ts >= $now);
$db_says_expired     = ($short_ov && $short_ov['reason'] === 'expired');

if ($trust_header) {
    if ($header_says_expired) {
        $expired = true;
    } elseif ($db_says_expired && !$header_says_valid) {
        $expired = true;
    }
} else {
    $expired = $db_says_expired;
}

if ($db_says_expired && $header_says_valid && $short_ov['source'] === 'webhook') {
    delete_override('shortuuid', $short_ov['match_value'], 'webhook');
}

$ov_created = is_array($short_ov) ? ($short_ov['created_at'] ?? null) : null;
if ($expired && expired_grace_passed($expire_ts, $ov_created, $now)) {
    $expired = false;
}

if ($blocked)      $decision = 'blocked';
elseif ($expired)  $decision = 'expired';

if ($short_uuid === '' && $segs) $short_uuid = $segs[0];

if (!$skip_log && nolog_is_set($short_uuid)) $skip_log = true;

$passthrough = ['profile-title', 'support-url', 'profile-update-interval',
                'profile-web-page-url', 'subscription-userinfo', 'content-disposition',
                'announce', 'announce-url'];

$is_page = stripos($grabbed_headers['content-type'] ?? '', 'text/html') === 0;
if ($is_page || preg_match('~^(assets|\.well-known|cdn-cgi)(/|$)~i', $path)) $GLOBALS['submw_skip_metric'] = true;

$do_substitute = ($decision === 'blocked');
if ($do_substitute) {
    header('HTTP/1.1 200 OK');
    if (isset($grabbed_headers['content-type'])) {
        header('Content-Type: ' . $grabbed_headers['content-type']);
    } else {
        header('Content-Type: text/plain; charset=utf-8');
    }

    foreach ($passthrough as $h) {
        if (isset($grabbed_headers[$h])) header($h . ': ' . $grabbed_headers[$h]);
    }

    emit_response_headers();
    echo build_override_body($decision, $format);
    if (!$skip_log && !$is_page && reqlog_is_real($grabbed_headers, $decision, $short_ov)) {
        $GLOBALS['submw_real_sub'] = true;
        log_request($ip, $short_uuid, $path, $ua, $decision, $expire_ts, $current_hwid);
    }
    exit();
}

if ($decision === 'normal' && $short_uuid !== '' && ($format === 'clash' || $format === 'base64') && squadconf_any()) {
    try {
        $u_squads = squadconf_user_squads($short_uuid);
        if ($u_squads) {
            $u_cfgs = squadconf_for_squads($u_squads);
            if ($u_cfgs) {
                if ($format === 'clash') {
                    $response = squadconf_inject_clash($response, $u_cfgs);
                } else {
                    $trim = ltrim((string) $response);
                    $is_json = ($trim !== '' && ($trim[0] === '[' || $trim[0] === '{'));
                    if ($is_json && setting('squad_xray_json_inject', '0') === '1') {
                        $response = squadconf_inject_xray_json($response, $u_cfgs);
                    } elseif (!$is_json) {
                        $response = squadconf_inject_base64($response, $u_cfgs);
                    }
                }
            }
        }
    } catch (Throwable $e) { error_log('submw squadconf inject: ' . $e->getMessage()); }
}

$unsafe = ['host', 'connection', 'transfer-encoding', 'content-length', 'content-encoding'];
http_response_code($http_code ?: 200);
foreach ($grabbed_headers as $name => $value) {
    if (!in_array($name, $unsafe, true)) header($name . ': ' . $value);
}
emit_response_headers();
echo $response;
if (!$skip_log) {
    $log_decision = grace_is_active($short_uuid) ? 'grace' : $decision;
    if (!$is_page && reqlog_is_real($grabbed_headers, $log_decision, $short_ov)) {
        $GLOBALS['submw_real_sub'] = true;
        log_request($ip, $short_uuid, $path, $ua, $log_decision, $expire_ts, $current_hwid);
    }
}

if ($decision === 'expired' && $short_uuid !== '') {
    try { grace_restore_due($short_uuid); } catch (Throwable $e) { error_log('submw grace restore-due: ' . $e->getMessage()); }
}
