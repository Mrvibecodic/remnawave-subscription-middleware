<?php

ini_set('display_errors', 0);
error_reporting(E_ALL);

require __DIR__ . '/lib.php';

if (!is_installed()) {
    http_response_code(503);
    echo 'Not installed';
    exit();
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo 'Method Not Allowed';
    exit();
}

$raw    = file_get_contents('php://input');
$sig    = $_SERVER['HTTP_X_REMNAWAVE_SIGNATURE'] ?? '';
$secret = webhook_secret();

$expected = hash_hmac('sha256', $raw, $secret);
$sig_ok   = $secret !== '' && is_string($sig) && hash_equals($expected, $sig);

if (!$sig_ok) {
    error_log('submw webhook: bad signature from ' . ($_SERVER['REMOTE_ADDR'] ?? '?'));
    http_response_code(401);
    echo 'Invalid signature';
    exit();
}

$payload = json_decode($raw, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo 'Bad JSON';
    exit();
}

$event = $payload['event'] ?? '';
$data  = is_array($payload['data'] ?? null) ? $payload['data'] : [];

$short_uuid = (string) ($data['shortUuid'] ?? '');
$username   = isset($data['username']) ? (string) $data['username'] : null;
$status     = isset($data['status'])   ? (string) $data['status']   : null;

if ($short_uuid === '' && !empty($data['subscriptionUrl'])) {
    $segs = path_segments(parse_url((string) $data['subscriptionUrl'], PHP_URL_PATH) ?? '');
    if ($segs) $short_uuid = end($segs);
}

$action = 'ignored';

if ($short_uuid !== '') squadconf_cache_drop($short_uuid);

if ($short_uuid !== '') {
    $expire_future = false;
    if (!empty($data['expireAt'])) {
        $ts = strtotime((string) $data['expireAt']);
        $expire_future = ($ts !== false && $ts > time());
    }
    $is_active     = ($status === 'ACTIVE') || $expire_future;
    $is_inactive   = in_array($status, ['EXPIRED', 'DISABLED', 'LIMITED'], true);

    if ($event === 'user.deleted') {
        delete_override('shortuuid', $short_uuid, 'webhook');
        grace_cleanup($short_uuid);
        $action = 'clear';
    } elseif ($is_active) {
        $renewed = grace_on_renew($short_uuid, (string) ($data['expireAt'] ?? ''));
        delete_override('shortuuid', $short_uuid, 'webhook');
        $action = $renewed ? 'grace_renewed' : 'reactivate';
    } elseif ($event === 'user.expired') {
        $g = grace_on_expired($short_uuid, $username);
        if ($g === 'grace_started' || $g === 'grace_ended' || $g === 'grace_active') {
            delete_override('shortuuid', $short_uuid, 'webhook');
            $action = $g;
        } else {
            upsert_override('shortuuid', $short_uuid, 'expired', 'webhook', $username, 'auto: ' . $event);
            $action = 'set_expired';
        }
    } elseif ($is_inactive) {
        upsert_override('shortuuid', $short_uuid, 'expired', 'webhook', $username, 'auto: ' . $event);
        $action = 'set_expired';
    }
}

log_webhook($event, $short_uuid ?: null, $username, $status, true, $action);

http_response_code(200);
echo 'OK';

if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}
try {
    forward_webhook($raw, $event);
} catch (Throwable $e) {
    error_log('submw forward_webhook: ' . $e->getMessage());
}
try {
    grace_retry_pending();
} catch (Throwable $e) {
    error_log('submw grace retry: ' . $e->getMessage());
}
