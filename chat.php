<?php

ini_set('display_errors', 0);
error_reporting(E_ALL);

require __DIR__ . '/lib.php';

function chat_json($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit();
}

if (!is_installed()) chat_json(['ok' => false, 'error' => 'not installed'], 503);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if (isset($_GET['tg'])) {
    if (!chat_tg_enabled()) { http_response_code(403); echo 'off'; exit(); }
    $hdr = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
    if (!hash_equals(chat_tg_secret(), (string) $hdr)) {
        error_log('submw chat tg: bad secret token (re-set webhook from admin)');
        http_response_code(401); echo 'bad secret'; exit();
    }
    $raw = file_get_contents('php://input');
    $update = json_decode((string) $raw, true);
    if (is_array($update)) {
        try { chat_tg_handle_update($update); }
        catch (Throwable $e) { error_log('submw chat tg: ' . $e->getMessage()); }
    }
    http_response_code(200);
    echo 'OK';
    exit();
}

if (isset($_GET['inbound'])) {
    if (!chat_webhook_enabled()) chat_json(['ok' => false, 'error' => 'off'], 403);
    if ($method !== 'POST') chat_json(['ok' => false, 'error' => 'method'], 405);
    $raw = file_get_contents('php://input');
    $sig = $_SERVER['HTTP_X_CHAT_SIGNATURE'] ?? '';
    [$ok, $err] = chat_webhook_in($raw, $sig);
    chat_json(['ok' => $ok, 'error' => $err], $ok ? 200 : 400);
}

if (!chat_enabled()) chat_json(['ok' => false, 'error' => 'chat disabled'], 403);

$api = $_GET['api'] ?? '';
$cookie_name = 'submw_chat';

function chat_set_cookie($name, $token) {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    setcookie($name, $token, [
        'expires'  => time() + 30 * 86400,
        'path'     => '/',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

$token   = $_COOKIE[$cookie_name] ?? '';
$session = $token !== '' ? chat_session_by_token($token) : null;

if ($api === 'open') {
    if ($session) {
        chat_session_touch($session['id']);
        chat_json([
            'ok'       => true,
            'exists'   => true,
            'token'    => $session['token'],
            'agent'    => ['name' => chat_agent_name(), 'photo' => chat_agent_photo()],
            'poll'     => chat_poll_interval(),
            'messages' => chat_messages_since($session['id'], 0, 200),
        ]);
    }
    chat_json([
        'ok'       => true,
        'exists'   => false,
        'agent'    => ['name' => chat_agent_name(), 'photo' => chat_agent_photo()],
        'poll'     => chat_poll_interval(),
        'greeting' => chat_greeting(),
        'messages' => [],
    ]);
}

if ($api === 'send') {
    if ($method !== 'POST') chat_json(['ok' => false, 'error' => 'method'], 405);
    $body = trim((string) ($_POST['body'] ?? ''));
    if ($body === '') chat_json(['ok' => false, 'error' => 'empty'], 400);
    if (!$session) {
        $session = chat_session_create(client_ip(), $_SERVER['HTTP_USER_AGENT'] ?? '');
        if (!$session) chat_json(['ok' => false, 'error' => 'cannot create'], 500);
        chat_set_cookie($cookie_name, $session['token']);
        if (chat_greeting() !== '') chat_add_message($session['id'], 'agent', 'system', chat_greeting());
    }
    $id = chat_add_message($session['id'], 'visitor', 'site', $body);
    if (!$id) chat_json(['ok' => false, 'error' => 'store failed'], 500);
    try { chat_dispatch_visitor_message($session, $body); }
    catch (Throwable $e) { error_log('submw chat dispatch: ' . $e->getMessage()); }
    chat_json(['ok' => true, 'id' => $id]);
}

if ($api === 'poll') {
    if (!$session) chat_json(['ok' => true, 'gone' => true, 'messages' => []]);
    chat_session_touch($session['id']);
    $after = (int) ($_GET['after'] ?? 0);
    chat_json(['ok' => true, 'messages' => chat_messages_since($session['id'], $after, 100)]);
}

if ($api === 'name') {
    if ($method !== 'POST') chat_json(['ok' => false, 'error' => 'method'], 405);
    if (!$session) chat_json(['ok' => false, 'error' => 'no session'], 400);
    chat_session_set_name($session['id'], (string) ($_POST['name'] ?? ''));
    chat_json(['ok' => true]);
}

chat_json(['ok' => false, 'error' => 'unknown api'], 400);
