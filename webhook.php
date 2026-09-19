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

$ts_raw = (isset($payload['timestamp']) && is_scalar($payload['timestamp']) && (string) $payload['timestamp'] !== '')
    ? (string) $payload['timestamp']
    : (string) ($_SERVER['HTTP_X_REMNAWAVE_TIMESTAMP'] ?? '');
if ($ts_raw !== '') {
    if (ctype_digit($ts_raw)) {
        $ts = (int) $ts_raw;
        if ($ts > 100000000000) $ts = intdiv($ts, 1000);
    } else {
        $ts = strtotime($ts_raw);
    }
    if ($ts !== false && abs(time() - $ts) > 3600) {
        error_log('submw webhook: stale timestamp ' . $ts_raw . ' from ' . ($_SERVER['REMOTE_ADDR'] ?? '?'));
        http_response_code(409);
        echo 'Stale webhook';
        exit();
    }
}

$event = $payload['event'] ?? '';
$data  = is_array($payload['data'] ?? null) ? $payload['data'] : [];

$short_uuid = (string) ($data['shortUuid'] ?? '');
$username   = isset($data['username']) ? (string) $data['username'] : null;
$status     = isset($data['status'])   ? (string) $data['status']   : null;

if ($event === 'user_hwid_devices.added' || $event === 'user_hwid_devices.deleted') {
    $hw_dev   = is_array($data['hwidUserDevice'] ?? null) ? $data['hwidUserDevice'] : [];
    $hw_usr   = is_array($data['user'] ?? null) ? $data['user'] : [];
    $hw_ref   = rw_user_ref($hw_usr);
    if (!rw_ref_ok($hw_ref)) $hw_ref = rw_ref_coerce((string) ($data['userId'] ?? $data['userUuid'] ?? ''));
    $hw_uuid  = rw_ref_ok($hw_ref) ? (string) $hw_ref['val'] : '';
    $hw_hwid  = (string) ($hw_dev['hwid'] ?? $data['hwid'] ?? '');
    $hw_short = (string) ($hw_usr['shortUuid'] ?? $data['shortUuid'] ?? $short_uuid);
    $hw_plat  = (string) ($hw_dev['platform'] ?? $data['platform'] ?? '');
    $hw_name  = trim((string) ($hw_usr['username'] ?? '')) !== '' ? (string) $hw_usr['username'] : $username;
    $hw_stat  = trim((string) ($hw_usr['status'] ?? '')) !== '' ? (string) $hw_usr['status'] : null;
    if (trim((string) $hw_name) === '' && $hw_short !== '') {
        if ($p = db()) {
            try {
                $st = $p->prepare("SELECT username FROM webhook_log WHERE short_uuid = ? AND username IS NOT NULL AND username <> '' ORDER BY id DESC LIMIT 1");
                $st->execute([$hw_short]);
                $v = $st->fetchColumn();
                if (is_string($v) && $v !== '') $hw_name = $v;
            } catch (Throwable $e) {
                error_log('submw webhook hwid name lookup: ' . $e->getMessage());
            }
        }
        if (trim((string) $hw_name) === '') {
            $hw_u = remnawave_get_user_by_short($hw_short);
            if (is_array($hw_u)) {
                if (trim((string) ($hw_u['username'] ?? '')) !== '') $hw_name = (string) $hw_u['username'];
                if ($hw_stat === null && trim((string) ($hw_u['status'] ?? '')) !== '') $hw_stat = (string) $hw_u['status'];
            }
        }
    }
    if (trim((string) $hw_name) === '') $hw_name = null;
    if ($hw_uuid === '') {
        error_log('submw webhook ' . $event . ': нет идентификатора пользователя, событие пропущено (short=' . $hw_short . ')');
    } elseif ($event === 'user_hwid_devices.added') {
        if ($hw_hwid === '') error_log('submw webhook ' . $event . ': нет hwid, событие пропущено (short=' . $hw_short . ')');
        else wglease_hwid_upsert($hw_uuid, $hw_hwid, $hw_short, $hw_plat);
    } else {
        wglease_hwid_delete($hw_uuid, $hw_hwid, $hw_short);
    }
    if ($hw_short !== '') squadconf_cache_drop($hw_short);
    log_webhook($event, ($hw_short !== '' ? $hw_short : null), $hw_name, $hw_stat, true, $event === 'user_hwid_devices.added' ? 'hwid_add' : 'hwid_del', $hw_usr);
    http_response_code(200);
    echo 'OK';
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    try { forward_webhook($raw, $event); } catch (Throwable $e) { error_log('submw forward_webhook: ' . $e->getMessage()); }
    exit();
}

if ($short_uuid === '' && !empty($data['subscriptionUrl'])) {
    $segs = path_segments(parse_url((string) $data['subscriptionUrl'], PHP_URL_PATH) ?? '');
    if ($segs) $short_uuid = end($segs);
}

$action = 'ignored';

if ($short_uuid !== '') squadconf_cache_drop($short_uuid);
if ($short_uuid !== '') addsub_cache_drop($short_uuid);
if ($short_uuid !== '' && addsub_is_secondary($short_uuid, $username)) addsub_cache_drop_by_target($short_uuid);

if ($short_uuid !== '' && $event !== 'user.deleted' && function_exists('chan_index_add') && chan_enabled()) {
    try { chan_index_add($short_uuid); } catch (Throwable $e) { error_log('submw chan index add: ' . $e->getMessage()); }
}

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
        wglease_purge_user($short_uuid);
        if (function_exists('chan_index_drop')) {
            try { chan_index_drop($short_uuid); chan_state_drop($short_uuid); }
            catch (Throwable $e) { error_log('submw chan index drop: ' . $e->getMessage()); }
        }
        $action = 'clear';
    } elseif ($status === 'EXPIRED' || $event === 'user.expired') {
        if (addsub_is_secondary($short_uuid, $username) && !grace_find($short_uuid)) {
            $action = 'addsub_skip';
        } else {
            $g = grace_on_expired($short_uuid, $username, $event === 'user.expired', $data);
            if ($g === 'grace_started' || $g === 'grace_active' || $g === 'grace_renewed') {
                delete_override('shortuuid', $short_uuid, 'webhook');
                $action = $g;
            } elseif ($g === 'grace_ended') {
                $action = $g;
            } elseif ($g === 'grace_stale') {
                $action = 'stale';
            } else {
                upsert_override('shortuuid', $short_uuid, 'expired', 'webhook', $username, 'auto: ' . $event);
                $action = 'set_expired';
            }
        }
    } elseif ($status === 'DISABLED' || $status === 'LIMITED') {
        $g = grace_check($short_uuid, $data);
        if ($g === 'grace_ended' || ($g === 'grace_renewed' && $status === 'LIMITED')) {
            $action = $g;
        } elseif ($g === 'grace_stale') {
            $action = 'stale';
        } else {
            upsert_override('shortuuid', $short_uuid, 'expired', 'webhook', $username, 'auto: ' . $event);
            $action = 'set_expired';
        }
    } elseif ($is_active) {
        $g = grace_check($short_uuid, $data);
        if ($g === 'grace_ended') {
            $action = $g;
        } elseif ($g === 'grace_done' && !$expire_future) {
            upsert_override('shortuuid', $short_uuid, 'expired', 'webhook', $username, 'auto: ' . $event);
            $action = 'set_expired';
        } elseif ($g === 'grace_stale') {
            $action = 'stale';
        } else {
            delete_override('shortuuid', $short_uuid, 'webhook');
            $action = $g === 'grace_renewed' ? 'grace_renewed' : 'reactivate';
        }
    } elseif ($is_inactive) {
        upsert_override('shortuuid', $short_uuid, 'expired', 'webhook', $username, 'auto: ' . $event);
        $action = 'set_expired';
    }
}

log_webhook($event, $short_uuid ?: null, $username, $status, true, $action, $data);

ignore_user_abort(true);
http_response_code(200);
header('Content-Type: text/plain; charset=utf-8');
header('Content-Length: 2');
header('Connection: close');
echo 'OK';

if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    while (ob_get_level() > 0) { @ob_end_flush(); }
    @flush();
}
try {
    forward_webhook($raw, $event);
} catch (Throwable $e) {
    error_log('submw forward_webhook: ' . $e->getMessage());
}
try {
    if (remnawave_url() !== '' && remnawave_token() !== '') {
        $pm_err = '';
        $pm = remnawave_panel_meta(3600, $pm_err);
        if (!empty($pm['ok'])) { $pc_err = ''; remnawave_panel_config(3600, $pc_err); }
    }
} catch (Throwable $e) {
    error_log('submw panel meta: ' . $e->getMessage());
}
try {
    grace_retry_pending();
} catch (Throwable $e) {
    error_log('submw grace retry: ' . $e->getMessage());
}
