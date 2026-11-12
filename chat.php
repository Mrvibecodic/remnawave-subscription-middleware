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

if (isset($_GET['widget']) || isset($_GET['embed'])) {
    require __DIR__ . '/lib/chat_embed.php';
    if (isset($_GET['widget'])) chat_embed_loader();
    else chat_embed_page($_GET['embed'] === '1' ? ($_GET['site'] ?? '') : '', $_GET['d'] ?? '', $_GET['u'] ?? '');
    exit();
}

$embed = isset($_GET['e']) ? chat_embed_origin($_GET['e']) : null;
$acct  = null;
if ($embed !== null) {
    $own = chat_embed_own($embed);
    if (!(chat_embed_enabled() && chat_embed_site_ok($embed)) && !$own) chat_json(['ok' => false, 'error' => 'embed off'], 403);
    if ($own) $acct = chat_subpage_ident($_GET['u'] ?? null);
    // Запросы виджета шлёт только его собственная страница: чужой сайт не должен писать в чат посетителя его cookie.
    $sfs = strtolower((string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? ''));
    if ($sfs !== '' && $sfs !== 'same-origin') chat_json(['ok' => false, 'error' => 'origin'], 403);
} elseif (!chat_enabled()) {
    chat_json(['ok' => false, 'error' => 'chat disabled'], 403);
}

chat_maybe_gc();

$api = $_GET['api'] ?? '';
// У каждого сайта своя cookie: поддомены одного домена делят хранилище cookie, а диалоги у них раздельные.
// На странице подписки диалог свой у каждой учётной записи: с одного устройства могут открывать несколько подписок.
$cookie_name = $embed !== null ? 'submw_chat_e' . substr(hash('sha256', $embed . ($acct ? "\n" . $acct['short'] : '')), 0, 8) : 'submw_chat';

function chat_set_cookie($name, $token, $third_party = false) {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    if ($third_party) {
        // Окно на чужом сайте — сторонний контекст: cookie доходит только как SameSite=None; Secure, а Partitioned держит её отдельно для каждого сайта.
        // setcookie() в PHP 8.3 не знает Partitioned, поэтому заголовок собирается вручную.
        if ($secure || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') {
            header('Set-Cookie: ' . $name . '=' . $token . '; Max-Age=' . (30 * 86400) . '; Path=/; Secure; HttpOnly; SameSite=None; Partitioned', false);
        }
        return;
    }
    setcookie($name, $token, [
        'expires'  => time() + 30 * 86400,
        'path'     => '/',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

$token   = $_COOKIE[$cookie_name] ?? '';
if ($embed !== null && !empty($_SERVER['HTTP_X_CHAT_TOKEN'])) $token = (string) $_SERVER['HTTP_X_CHAT_TOKEN'];
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
    if (!chat_rate_ok($session)) chat_json(['ok' => false, 'error' => 'rate'], 429);
    if (!$session) {
        if (chat_recent_sessions_from_ip(client_ip()) >= chat_max_sessions_per_ip()) chat_json(['ok' => false, 'error' => 'rate'], 429);
        $session = chat_session_create(client_ip(), $_SERVER['HTTP_USER_AGENT'] ?? '', (string) $embed, $acct);
        if (!$session) chat_json(['ok' => false, 'error' => 'cannot create'], 500);
        chat_set_cookie($cookie_name, $session['token'], $embed !== null);
        if (chat_greeting() !== '') chat_add_message($session['id'], 'agent', 'system', chat_greeting());
    } elseif (chat_session_msg_count($session['id']) >= chat_max_msgs_per_session()) {
        chat_json(['ok' => false, 'error' => 'limit'], 429);
    } elseif ($acct && empty($session['sub_short'])) {
        chat_session_set_acct($session['id'], $acct);
        $session = chat_session_by_token($session['token']) ?: $session;
    }
    $id = chat_add_message($session['id'], 'visitor', 'site', $body);
    if (!$id) chat_json(['ok' => false, 'error' => 'store failed'], 500);
    try { chat_dispatch_visitor_message($session, $body); }
    catch (Throwable $e) { error_log('submw chat dispatch: ' . $e->getMessage()); }
    chat_json($embed !== null ? ['ok' => true, 'id' => $id, 'token' => $session['token']] : ['ok' => true, 'id' => $id]);
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
