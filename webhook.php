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

$ts_raw = $_SERVER['HTTP_X_REMNAWAVE_TIMESTAMP'] ?? '';
if ($ts_raw !== '') {
    $ts = strtotime((string) $ts_raw);
    if ($ts !== false && (time() - $ts) > 3600) {
        error_log('submw webhook: stale timestamp ' . $ts_raw . ' from ' . ($_SERVER['REMOTE_ADDR'] ?? '?'));
        http_response_code(409);
        echo 'Stale webhook';
        exit();
    }
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

if ($event === 'user_hwid_devices.added' || $event === 'user_hwid_devices.deleted') {
    $hw_dev   = is_array($data['hwidUserDevice'] ?? null) ? $data['hwidUserDevice'] : [];
    $hw_usr   = is_array($data['user'] ?? null) ? $data['user'] : [];
    // Панель 3.x не кладёт в payload uuid пользователя — только числовой id.
    $hw_ref   = rw_user_ref($hw_usr);
    if (!rw_ref_ok($hw_ref)) $hw_ref = rw_ref_coerce((string) ($data['userId'] ?? $data['userUuid'] ?? ''));
    $hw_uuid  = rw_ref_ok($hw_ref) ? (string) $hw_ref['val'] : '';
    $hw_hwid  = (string) ($hw_dev['hwid'] ?? $data['hwid'] ?? '');
    $hw_short = (string) ($hw_usr['shortUuid'] ?? $data['shortUuid'] ?? $short_uuid);
    $hw_plat  = (string) ($hw_dev['platform'] ?? $data['platform'] ?? '');
    // Имя и статус юзера лежат внутри объекта user, а не на верхнем уровне payload —
    // без этого юзер-лог показывал hwid-события с пустой колонкой «Пользователь».
    $hw_name  = trim((string) ($hw_usr['username'] ?? '')) !== '' ? (string) $hw_usr['username'] : $username;
    $hw_stat  = trim((string) ($hw_usr['status'] ?? '')) !== '' ? (string) $hw_usr['status'] : null;
    // Страховка для панелей, не кладущих объект user в hwid-payload: имя сначала
    // ищем в своём же логе по shortUuid, и только если его там нет — спрашиваем панель.
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
    // Без идентификатора запись в hwid_devices не попадёт, а тихо потерянные события
    // выглядят как «дедикейт-режим просто перестал работать» — поэтому пишем в лог.
    // У удаления пустой hwid по-прежнему означает «снести все устройства юзера».
    if ($hw_uuid === '') {
        error_log('submw webhook ' . $event . ': нет идентификатора пользователя, событие пропущено (short=' . $hw_short . ')');
    } elseif ($event === 'user_hwid_devices.added') {
        if ($hw_hwid === '') error_log('submw webhook ' . $event . ': нет hwid, событие пропущено (short=' . $hw_short . ')');
        else wglease_hwid_upsert($hw_uuid, $hw_hwid, $hw_short, $hw_plat);
    } else {
        wglease_hwid_delete($hw_uuid, $hw_hwid, $hw_short);
    }
    if ($hw_short !== '') squadconf_cache_drop($hw_short);
    log_webhook($event, ($hw_short !== '' ? $hw_short : null), $hw_name, $hw_stat, true, $event === 'user_hwid_devices.added' ? 'hwid_add' : 'hwid_del');
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

// Метки защищённого канала для новой подписки. Индекс пересобирается раз в
// сутки, и без этого человек, созданный сразу после обхода, не смог бы
// подключиться защищённо до следующего.
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
        // Метки канала снимаем сразу, а не ждём полного обхода: до него удалённый
        // человек продолжал бы ходить по /c1/ — подписку прослойка узнаёт по метке,
        // а не по панели. Без оглядки на chan_enabled(): канал могли выключить
        // вчера, а метки в индексе от этого никуда не делись.
        if (function_exists('chan_index_drop')) {
            try { chan_index_drop($short_uuid); chan_state_drop($short_uuid); }
            catch (Throwable $e) { error_log('submw chan index drop: ' . $e->getMessage()); }
        }
        $action = 'clear';
    } elseif ($status === 'EXPIRED' || $event === 'user.expired') {
        // Новый грейс стартует только на настоящем user.expired: событие user.modified
        // со status=EXPIRED прилетает и от нашего же restore-PATCH в конце грейса —
        // без этого ограничения грейс тут же выдавался заново (вечная петля).
        if (addsub_is_secondary($short_uuid, $username) && !grace_find($short_uuid)) {
            $action = 'addsub_skip';
        } else {
            $g = grace_on_expired($short_uuid, $username, $event === 'user.expired');
            if ($g === 'grace_started' || $g === 'grace_ended' || $g === 'grace_active') {
                delete_override('shortuuid', $short_uuid, 'webhook');
                $action = $g;
            } else {
                upsert_override('shortuuid', $short_uuid, 'expired', 'webhook', $username, 'auto: ' . $event);
                $action = 'set_expired';
            }
        }
    } elseif ($status === 'DISABLED' || $status === 'LIMITED') {
        upsert_override('shortuuid', $short_uuid, 'expired', 'webhook', $username, 'auto: ' . $event);
        $action = 'set_expired';
    } elseif ($is_active) {
        $renewed = grace_on_renew($short_uuid, (string) ($data['expireAt'] ?? ''));
        delete_override('shortuuid', $short_uuid, 'webhook');
        $action = $renewed ? 'grace_renewed' : 'reactivate';
    } elseif ($is_inactive) {
        upsert_override('shortuuid', $short_uuid, 'expired', 'webhook', $username, 'auto: ' . $event);
        $action = 'set_expired';
    }
}

log_webhook($event, $short_uuid ?: null, $username, $status, true, $action);

// Отвечаем панели сразу и закрываем соединение, а пересылку делаем после —
// чтобы медленный получатель не задерживал ответ и панель не ретраила событие.
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
// Освежаем кэш версии панели: ответ клиенту уже отправлен, так что задержки нет.
// Без этого версия обновлялась бы только при заходе в админку, и грейс не мог бы
// заранее понять, что панель перешла на числовые id.
try {
    if (remnawave_url() !== '' && remnawave_token() !== '') {
        $pm_err = '';
        $pm = remnawave_panel_meta(3600, $pm_err);
        // Конфигурацию тянем только если панель сейчас отвечает: иначе воркер
        // ждал бы два таймаута подряд уже после ответа на вебхук.
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
