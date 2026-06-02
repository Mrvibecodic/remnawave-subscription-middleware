<?php

function chat_enabled() { return setting('chat_enabled', '0') === '1'; }
function chat_agent_name() { $v = trim((string) setting('chat_agent_name', '')); return $v !== '' ? $v : 'Поддержка'; }
function chat_agent_photo() { return trim((string) setting('chat_agent_photo', '')); }
function chat_greeting() { return trim((string) setting('chat_greeting', '')); }
function chat_widget_preset() { $v = (int) setting('chat_widget_preset', '1'); return ($v >= 1 && $v <= 3) ? $v : 1; }
function chat_widget_position() { return setting('chat_widget_position', 'right') === 'left' ? 'left' : 'right'; }
function chat_widget_color() {
    $v = trim((string) setting('chat_widget_color', '#4f46e5'));
    return preg_match('/^#[0-9a-fA-F]{6}$/', $v) ? $v : '#4f46e5';
}
function chat_widget_text() { $v = trim((string) setting('chat_widget_text', '')); return $v !== '' ? $v : 'Напишите нам'; }
function chat_poll_interval() { return max(2, min(30, (int) setting('chat_poll_interval', '4'))); }

function chat_tg_enabled() { return setting('chat_tg_enabled', '0') === '1'; }
function chat_tg_token() { return trim((string) setting('chat_tg_bot_token', '')); }
function chat_tg_chat_id() { return trim((string) setting('chat_tg_chat_id', '')); }
function chat_tg_secret() {
    $v = trim((string) setting('chat_tg_secret', ''));
    if ($v === '') { $v = bin2hex(random_bytes(16)); set_setting('chat_tg_secret', $v); }
    return $v;
}

function chat_webhook_enabled() { return setting('chat_webhook_enabled', '0') === '1'; }
function chat_webhook_url() { return trim((string) setting('chat_webhook_url', '')); }
function chat_webhook_secret() { return (string) setting('chat_webhook_secret', ''); }
function chat_inbound_secret() {
    $v = trim((string) setting('chat_inbound_secret', ''));
    if ($v === '') { $v = bin2hex(random_bytes(16)); set_setting('chat_inbound_secret', $v); }
    return $v;
}

function ensure_chat_tables() {
    static $done = false;
    if ($done) return true;
    if (!($p = db())) return false;
    if (setting('chat_tables', '') !== '1') {
        try {
            if (db_driver() === 'mysql') {
                $p->exec("CREATE TABLE IF NOT EXISTS chat_sessions (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    token CHAR(32) NOT NULL,
                    name VARCHAR(120) NULL,
                    ip VARCHAR(45) NULL,
                    user_agent VARCHAR(255) NULL,
                    status ENUM('open','closed') NOT NULL DEFAULT 'open',
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    last_seen TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    last_msg_at TIMESTAMP NULL,
                    unread_agent INT UNSIGNED NOT NULL DEFAULT 0,
                    tg_msg_id BIGINT NULL,
                    PRIMARY KEY (id),
                    UNIQUE KEY uniq_token (token),
                    KEY idx_last (last_msg_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
                $p->exec("CREATE TABLE IF NOT EXISTS chat_messages (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    session_id BIGINT UNSIGNED NOT NULL,
                    sender ENUM('visitor','agent','system') NOT NULL,
                    source ENUM('site','telegram','webhook','admin','system') NOT NULL DEFAULT 'site',
                    body TEXT NOT NULL,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    KEY idx_session (session_id, id),
                    CONSTRAINT fk_chat_msg_session FOREIGN KEY (session_id) REFERENCES chat_sessions (id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            } else {
                $p->exec("CREATE TABLE IF NOT EXISTS chat_sessions (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    token TEXT NOT NULL,
                    name TEXT NULL,
                    ip TEXT NULL,
                    user_agent TEXT NULL,
                    status TEXT NOT NULL DEFAULT 'open',
                    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    last_seen TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    last_msg_at TEXT NULL,
                    unread_agent INTEGER NOT NULL DEFAULT 0,
                    tg_msg_id INTEGER NULL,
                    UNIQUE(token)
                )");
                $p->exec("CREATE INDEX IF NOT EXISTS idx_chat_last ON chat_sessions(last_msg_at)");
                $p->exec("CREATE TABLE IF NOT EXISTS chat_messages (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    session_id INTEGER NOT NULL,
                    sender TEXT NOT NULL,
                    source TEXT NOT NULL DEFAULT 'site',
                    body TEXT NOT NULL,
                    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (session_id) REFERENCES chat_sessions (id) ON DELETE CASCADE
                )");
                $p->exec("CREATE INDEX IF NOT EXISTS idx_chat_msg_session ON chat_messages(session_id, id)");
            }
            set_setting('chat_tables', '1');
        } catch (Throwable $e) {
            error_log('submw ensure_chat_tables: ' . $e->getMessage());
            return false;
        }
    }
    if (setting('chat_tg_msgid_col', '') !== '1') {
        try { $p->exec('ALTER TABLE chat_sessions ADD COLUMN tg_msg_id BIGINT NULL'); } catch (Throwable $e) {}
        set_setting('chat_tg_msgid_col', '1');
    }
    $done = true;
    return true;
}

function chat_token_new() { return bin2hex(random_bytes(16)); }

function chat_session_by_token($token) {
    if (!preg_match('/^[a-f0-9]{32}$/', (string) $token)) return null;
    if (!ensure_chat_tables() || !($p = db())) return null;
    try {
        $st = $p->prepare('SELECT * FROM chat_sessions WHERE token = ? LIMIT 1');
        $st->execute([$token]);
        $r = $st->fetch();
        return $r ?: null;
    } catch (Throwable $e) { return null; }
}

function chat_session_by_id($id) {
    if (!ensure_chat_tables() || !($p = db())) return null;
    try {
        $st = $p->prepare('SELECT * FROM chat_sessions WHERE id = ? LIMIT 1');
        $st->execute([(int) $id]);
        $r = $st->fetch();
        return $r ?: null;
    } catch (Throwable $e) { return null; }
}

function chat_session_create($ip, $ua) {
    if (!ensure_chat_tables() || !($p = db())) return null;
    $token = chat_token_new();
    try {
        $st = $p->prepare('INSERT INTO chat_sessions (token, ip, user_agent) VALUES (?, ?, ?)');
        $st->execute([$token, mb_substr((string) $ip, 0, 45), mb_substr((string) $ua, 0, 255)]);
        return chat_session_by_token($token);
    } catch (Throwable $e) { error_log('submw chat_session_create: ' . $e->getMessage()); return null; }
}

function chat_session_touch($id) {
    if (!($p = db())) return;
    try { $p->prepare('UPDATE chat_sessions SET last_seen = CURRENT_TIMESTAMP WHERE id = ?')->execute([(int) $id]); }
    catch (Throwable $e) {}
}

function chat_session_set_name($id, $name) {
    if (!($p = db())) return;
    try { $p->prepare('UPDATE chat_sessions SET name = ? WHERE id = ?')->execute([mb_substr(trim((string) $name), 0, 120), (int) $id]); }
    catch (Throwable $e) {}
}

function chat_session_delete($id) {
    if (!ensure_chat_tables() || !($p = db())) return false;
    $s = chat_session_by_id($id);
    if ($s && chat_tg_enabled() && !empty($s['tg_msg_id'])) {
        chat_tg_api('deleteMessage', ['chat_id' => chat_tg_chat_id(), 'message_id' => (int) $s['tg_msg_id']]);
    }
    try { $p->prepare('DELETE FROM chat_sessions WHERE id = ?')->execute([(int) $id]); return true; }
    catch (Throwable $e) { error_log('submw chat_session_delete: ' . $e->getMessage()); return false; }
}

function chat_add_message($session_id, $sender, $source, $body) {
    if (!ensure_chat_tables() || !($p = db())) return 0;
    $body = trim((string) $body);
    if ($body === '') return 0;
    $body = mb_substr($body, 0, 4000);
    try {
        $st = $p->prepare('INSERT INTO chat_messages (session_id, sender, source, body) VALUES (?, ?, ?, ?)');
        $st->execute([(int) $session_id, $sender, $source, $body]);
        $id = (int) $p->lastInsertId();
        if ($sender === 'visitor') {
            $p->prepare('UPDATE chat_sessions SET last_msg_at = CURRENT_TIMESTAMP, unread_agent = unread_agent + 1, status = \'open\' WHERE id = ?')->execute([(int) $session_id]);
        } else {
            $p->prepare('UPDATE chat_sessions SET last_msg_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([(int) $session_id]);
        }
        return $id;
    } catch (Throwable $e) { error_log('submw chat_add_message: ' . $e->getMessage()); return 0; }
}

function chat_messages_since($session_id, $after_id, $limit = 100) {
    $out = [];
    if (!($p = db())) return $out;
    try {
        $st = $p->prepare('SELECT id, sender, source, body, ' . sql_epoch('created_at') . ' AS ts FROM chat_messages WHERE session_id = ? AND id > ? ORDER BY id ASC LIMIT ' . (int) $limit);
        $st->execute([(int) $session_id, (int) $after_id]);
        foreach ($st as $r) $out[] = $r;
    } catch (Throwable $e) {}
    return $out;
}

function chat_messages_last($session_id, $limit = 20) {
    $out = [];
    if (!($p = db())) return $out;
    try {
        $st = $p->prepare('SELECT id, sender, source, body, ' . sql_epoch('created_at') . ' AS ts FROM chat_messages WHERE session_id = ? ORDER BY id DESC LIMIT ' . (int) $limit);
        $st->execute([(int) $session_id]);
        foreach ($st as $r) $out[] = $r;
    } catch (Throwable $e) {}
    return array_reverse($out);
}

function chat_messages_count($session_id) {
    if (!($p = db())) return 0;
    try {
        $st = $p->prepare('SELECT COUNT(*) FROM chat_messages WHERE session_id = ?');
        $st->execute([(int) $session_id]);
        return (int) $st->fetchColumn();
    } catch (Throwable $e) { return 0; }
}

function chat_session_set_tg_msg($id, $mid) {
    if (!($p = db())) return;
    try { $p->prepare('UPDATE chat_sessions SET tg_msg_id = ? WHERE id = ?')->execute([(int) $mid, (int) $id]); }
    catch (Throwable $e) {}
}

function chat_tg_format_history($session, $msgs, $note = '') {
    $esc = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    $who = $session['name'] ? $session['name'] : ($session['ip'] ?: 'гость');
    $agent = chat_agent_name();
    $lines = ["💬 <b>" . $esc($who) . "</b>  <code>#s" . (int) $session['id'] . "</code>"];
    if ($note !== '') $lines[] = "<i>" . $esc($note) . "</i>";
    $lines[] = "──────────";
    foreach ($msgs as $m) {
        $t = date('H:i', (int) ($m['ts'] ?? time()));
        $b = $esc(mb_substr((string) $m['body'], 0, 600));
        if ($m['sender'] === 'visitor')      $lines[] = "👤 <b>" . $esc($who) . "</b> <i>" . $t . "</i>\n" . $b;
        elseif ($m['sender'] === 'agent')    $lines[] = "🛟 <b>" . $esc($agent) . "</b> <i>" . $t . "</i>\n" . $b;
        else                                  $lines[] = "<i>" . $b . "</i>";
    }
    $text = implode("\n\n", $lines);
    if (mb_strlen($text) > 3900) $text = "…\n" . mb_substr($text, -3890);
    return $text;
}

function chat_sessions_list($limit = 100) {
    $out = [];
    if (!ensure_chat_tables() || !($p = db())) return $out;
    try {
        $sql = 'SELECT s.id, s.token, s.name, s.ip, s.status, s.unread_agent,
                       ' . sql_epoch('s.created_at') . ' AS created_ts,
                       ' . sql_epoch('s.last_msg_at') . ' AS last_ts,
                       (SELECT body FROM chat_messages m WHERE m.session_id = s.id ORDER BY m.id DESC LIMIT 1) AS last_body
                FROM chat_sessions s
                ORDER BY (s.last_msg_at IS NULL), s.last_msg_at DESC, s.id DESC
                LIMIT ' . (int) $limit;
        foreach ($p->query($sql) as $r) $out[] = $r;
    } catch (Throwable $e) {}
    return $out;
}

function chat_unread_total() {
    if (!ensure_chat_tables() || !($p = db())) return 0;
    try { return (int) $p->query('SELECT COALESCE(SUM(unread_agent),0) FROM chat_sessions')->fetchColumn(); }
    catch (Throwable $e) { return 0; }
}

function chat_mark_read($session_id) {
    if (!($p = db())) return;
    try { $p->prepare('UPDATE chat_sessions SET unread_agent = 0 WHERE id = ?')->execute([(int) $session_id]); }
    catch (Throwable $e) {}
}

function chat_tg_push_live($session) {
    if (!(chat_tg_enabled() && chat_tg_token() !== '' && chat_tg_chat_id() !== '')) return false;
    $sid  = (int) $session['id'];
    $prev = (int) ($session['tg_msg_id'] ?? 0);
    if ($prev > 0) chat_tg_api('deleteMessage', ['chat_id' => chat_tg_chat_id(), 'message_id' => $prev]);
    $total = chat_messages_count($sid);
    $msgs  = chat_messages_last($sid, 20);
    $note  = $total > 20 ? ('показаны последние 20 из ' . $total) : '';
    $text  = chat_tg_format_history($session, $msgs, $note) . "\n\n<i>↩️ «Ответить» — написать клиенту</i>";
    $kb = [[['text' => '✍️ Ответить', 'callback_data' => 'r' . $sid]]];
    if ($total > 20) $kb[] = [['text' => '📜 Весь чат', 'callback_data' => 'f' . $sid]];
    $kb[] = [['text' => '🗑 Завершить и удалить чат', 'callback_data' => 'e' . $sid]];
    [$ok, $res] = chat_tg_api('sendMessage', [
        'chat_id' => chat_tg_chat_id(),
        'text' => $text,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true,
        'reply_markup' => json_encode(['inline_keyboard' => $kb]),
    ]);
    if ($ok && is_array($res) && isset($res['message_id'])) chat_session_set_tg_msg($sid, (int) $res['message_id']);
    return $ok;
}

function chat_dispatch_visitor_message($session, $body) {
    $delivered = false;
    if (chat_tg_enabled() && chat_tg_token() !== '' && chat_tg_chat_id() !== '') {
        $delivered = chat_tg_push_live($session) || $delivered;
    }
    if (chat_webhook_enabled() && chat_webhook_url() !== '') {
        $ok = chat_webhook_out($session, 'visitor', $body);
        $delivered = $delivered || $ok;
    }
    return $delivered;
}

function chat_tg_api($method, array $params, $token = null, $timeout = 10) {
    $token = $token ?? chat_tg_token();
    if ($token === '') return [false, null, 'no token'];
    $url = 'https://api.telegram.org/bot' . $token . '/' . $method;
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($params),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => api_tls_verify(),
        CURLOPT_SSL_VERIFYHOST => api_tls_verify() ? 2 : 0,
    ]);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($err !== '') return [false, null, $err];
    $data = json_decode((string) $resp, true);
    if (!is_array($data) || empty($data['ok'])) {
        $desc = is_array($data) ? ($data['description'] ?? ('HTTP ' . $code)) : ('HTTP ' . $code);
        return [false, $data, $desc];
    }
    return [true, $data['result'] ?? null, ''];
}

function chat_tg_check($token = null) {
    [$ok, $res, $err] = chat_tg_api('getMe', [], $token, 8);
    if (!$ok) return ['ok' => false, 'error' => $err, 'reachable' => $err !== '' && stripos($err, 'resolve') === false && stripos($err, 'timed out') === false && stripos($err, 'connect') === false];
    return ['ok' => true, 'username' => $res['username'] ?? '', 'name' => $res['first_name'] ?? '', 'id' => $res['id'] ?? 0, 'reachable' => true];
}

function chat_tg_set_webhook($url) {
    return chat_tg_api('setWebhook', [
        'url' => $url,
        'secret_token' => chat_tg_secret(),
        'allowed_updates' => json_encode(['message', 'callback_query']),
        'drop_pending_updates' => true,
    ]);
}

function chat_tg_delete_webhook() {
    return chat_tg_api('deleteWebhook', ['drop_pending_updates' => false]);
}

function chat_tg_webhook_info($token = null) {
    [$ok, $res, $err] = chat_tg_api('getWebhookInfo', [], $token, 8);
    if (!$ok) return ['ok' => false, 'error' => $err];
    return ['ok' => true, 'info' => is_array($res) ? $res : []];
}

function chat_tg_handle_callback(array $cq) {
    $cqid    = (string) ($cq['id'] ?? '');
    $data    = (string) ($cq['data'] ?? '');
    $chat_id = $cq['message']['chat']['id'] ?? chat_tg_chat_id();
    $mid     = (int) ($cq['message']['message_id'] ?? 0);
    $esc     = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    $answer  = function ($text = '') use ($cqid) {
        if ($cqid === '') return;
        $p = ['callback_query_id' => $cqid];
        if ($text !== '') $p['text'] = $text;
        chat_tg_api('answerCallbackQuery', $p);
    };

    if ($data === 'd') {
        if ($mid > 0) chat_tg_api('deleteMessage', ['chat_id' => $chat_id, 'message_id' => $mid]);
        $answer();
        return true;
    }

    if (!preg_match('/^([rfe])(\d+)$/', $data, $m)) { $answer(); return false; }
    $kind = $m[1];
    $sid  = (int) $m[2];

    if ($kind === 'e') {
        chat_session_delete($sid);
        $answer('Чат завершён и удалён');
        return true;
    }

    $session = chat_session_by_id($sid);
    if (!$session) { $answer('Чат не найден'); return false; }

    if ($kind === 'r') {
        $who = $session['name'] ? $session['name'] : ($session['ip'] ?: 'гость');
        $prompt = "✍️ Ответ клиенту <b>" . $esc($who) . "</b>  <code>#s{$sid}</code>\nНапишите сообщение ⤵️";
        chat_tg_api('sendMessage', [
            'chat_id'      => $chat_id,
            'text'         => $prompt,
            'parse_mode'   => 'HTML',
            'reply_markup' => json_encode(['force_reply' => true, 'input_field_placeholder' => 'Ответ клиенту #s' . $sid]),
        ]);
        $answer();
        return true;
    }

    if ($kind === 'f') {
        $msgs = chat_messages_last($sid, 1000);
        $text = chat_tg_format_history($session, $msgs, 'полная переписка');
        chat_tg_api('sendMessage', [
            'chat_id'      => $chat_id,
            'text'         => $text,
            'parse_mode'   => 'HTML',
            'disable_web_page_preview' => true,
            'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '🗑 Удалить сообщение', 'callback_data' => 'd']]]]),
        ]);
        $answer();
        return true;
    }

    $answer();
    return false;
}

function chat_tg_handle_update(array $update) {
    if (isset($update['callback_query']) && is_array($update['callback_query'])) {
        return chat_tg_handle_callback($update['callback_query']);
    }
    $msg = $update['message'] ?? null;
    if (!is_array($msg)) return false;
    $text = trim((string) ($msg['text'] ?? ''));
    if ($text === '') return false;

    $sid = 0;
    $reply_text = '';
    if (preg_match('/^\/r\s+s?(\d+)\s+(.+)$/su', $text, $m)) {
        $sid = (int) $m[1];
        $reply_text = trim($m[2]);
    } else {
        $rt = $msg['reply_to_message']['text'] ?? '';
        if ($rt !== '' && preg_match('/#s(\d+)/', $rt, $m)) {
            $sid = (int) $m[1];
            $reply_text = $text;
        }
    }
    if ($sid <= 0 || $reply_text === '') {
        error_log('submw chat tg: no route (reply to forwarded msg with #s<id> or use /r <id> text)');
        return false;
    }

    $session = chat_session_by_id($sid);
    if (!$session) {
        error_log('submw chat tg: session #s' . $sid . ' not found');
        return false;
    }
    chat_add_message($sid, 'agent', 'telegram', $reply_text);

    $op_chat = $msg['chat']['id'] ?? chat_tg_chat_id();
    if (!empty($msg['message_id'])) chat_tg_api('deleteMessage', ['chat_id' => $op_chat, 'message_id' => (int) $msg['message_id']]);
    $rtid = (int) ($msg['reply_to_message']['message_id'] ?? 0);
    if ($rtid > 0) chat_tg_api('deleteMessage', ['chat_id' => $op_chat, 'message_id' => $rtid]);

    $fresh = chat_session_by_id($sid);
    if ($fresh) chat_tg_push_live($fresh);
    return true;
}

function chat_webhook_out($session, $sender, $body) {
    $url = chat_webhook_url();
    if ($url === '') return false;
    $payload = json_encode([
        'token'      => $session['token'],
        'session_id' => (int) $session['id'],
        'name'       => $session['name'],
        'ip'         => $session['ip'],
        'sender'     => $sender,
        'body'       => $body,
        'ts'         => time(),
    ], JSON_UNESCAPED_UNICODE);
    $sig = hash_hmac('sha256', $payload, chat_webhook_secret());
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => api_tls_verify(),
        CURLOPT_SSL_VERIFYHOST => api_tls_verify() ? 2 : 0,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'X-Chat-Signature: sha256=' . $sig,
        ],
    ]);
    curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $err === '' && $code >= 200 && $code < 300;
}

function chat_webhook_in($raw, $sig_header) {
    $expected = 'sha256=' . hash_hmac('sha256', (string) $raw, chat_inbound_secret());
    if (!is_string($sig_header) || !hash_equals($expected, $sig_header)) return [false, 'bad signature'];
    $data = json_decode((string) $raw, true);
    if (!is_array($data)) return [false, 'bad json'];
    $session = null;
    if (!empty($data['token'])) $session = chat_session_by_token((string) $data['token']);
    elseif (!empty($data['session_id'])) $session = chat_session_by_id((int) $data['session_id']);
    if (!$session) return [false, 'unknown session'];
    $text = trim((string) ($data['text'] ?? $data['body'] ?? ''));
    if ($text === '') return [false, 'empty text'];
    chat_add_message((int) $session['id'], 'agent', 'webhook', $text);
    return [true, ''];
}

function chat_inbound_url() {
    $host = mirror_domain() ?: ($_SERVER['HTTP_HOST'] ?? '');
    return 'https://' . $host . '/chat.php?inbound=1';
}

function chat_tg_webhook_url() {
    $host = mirror_domain() ?: ($_SERVER['HTTP_HOST'] ?? '');
    return 'https://' . $host . '/chat.php?tg=1';
}

function chat_widget_render() {
    if (!chat_enabled()) return;
    $cfg = [
        'agent'    => chat_agent_name(),
        'photo'    => chat_agent_photo(),
        'greeting' => chat_greeting(),
        'preset'   => chat_widget_preset(),
        'position' => chat_widget_position(),
        'color'    => chat_widget_color(),
        'text'     => chat_widget_text(),
        'poll'     => chat_poll_interval(),
    ];
    $json = json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $pos = $cfg['position'];
    $color = $cfg['color'];
    $preset = $cfg['preset'];
    $btext = htmlspecialchars($cfg['text'], ENT_QUOTES, 'UTF-8');
    ?>
<style>
    .swc-wrap{position:fixed;bottom:20px;<?= $pos ?>:20px;z-index:99999;font-family:'Segoe UI',Roboto,Helvetica,Arial,sans-serif}
    .swc-launch{border:0;cursor:pointer;background:<?= $color ?>;color:#fff;box-shadow:0 8px 24px rgba(0,0,0,.18);display:flex;align-items:center;gap:.5rem;transition:transform .15s}
    .swc-launch:hover{transform:translateY(-2px)}
    .swc-launch svg{width:26px;height:26px;flex:0 0 auto}
    .swc-p1 .swc-launch{width:60px;height:60px;border-radius:50%;justify-content:center;padding:0}
    .swc-p1 .swc-launch .swc-lt{display:none}
    .swc-p2 .swc-launch{border-radius:30px;padding:.7rem 1.1rem;font-size:.95rem;font-weight:600}
    .swc-p3 .swc-wrap,.swc-p3.swc-wrap{<?= $pos ?>:0;bottom:0}
    .swc-p3 .swc-launch{border-radius:14px 14px 0 0;padding:.8rem 1.4rem;font-size:.95rem;font-weight:600}
    .swc-badge{position:absolute;top:-4px;<?= $pos==='right'?'right':'left' ?>:-4px;background:#ef4444;color:#fff;border-radius:50%;min-width:20px;height:20px;font-size:.72rem;display:none;align-items:center;justify-content:center;padding:0 5px;font-weight:700}
    .swc-panel{position:absolute;bottom:74px;<?= $pos ?>:0;width:340px;max-width:calc(100vw - 32px);height:460px;max-height:calc(100vh - 120px);background:#fff;border-radius:16px;box-shadow:0 16px 48px rgba(0,0,0,.22);display:none;flex-direction:column;overflow:hidden}
    .swc-open .swc-panel{display:flex}
    .swc-open .swc-launch{display:none}
    .swc-head{background:<?= $color ?>;color:#fff;padding:.9rem 1rem;display:flex;align-items:center;gap:.7rem}
    .swc-ava{width:40px;height:40px;border-radius:50%;background:rgba(255,255,255,.25);background-size:cover;background-position:center;flex:0 0 auto;display:flex;align-items:center;justify-content:center;font-weight:700}
    .swc-htxt{flex:1;min-width:0}
    .swc-hname{font-weight:700;font-size:.95rem}
    .swc-hsub{font-size:.76rem;opacity:.85}
    .swc-x{background:0;border:0;color:#fff;cursor:pointer;font-size:1.3rem;line-height:1;opacity:.85}
    .swc-x:hover{opacity:1}
    .swc-body{flex:1;overflow-y:auto;padding:1rem;background:#f3f4f6;display:flex;flex-direction:column;gap:.5rem}
    .swc-msg{max-width:80%;padding:.55rem .8rem;border-radius:14px;font-size:.9rem;line-height:1.4;word-wrap:break-word;white-space:pre-wrap}
    .swc-v{align-self:flex-end;background:<?= $color ?>;color:#fff;border-bottom-right-radius:4px}
    .swc-a{align-self:flex-start;background:#fff;color:#1f2937;border:1px solid #e5e7eb;border-bottom-left-radius:4px}
    .swc-s{align-self:center;background:transparent;color:#6b7280;font-size:.78rem}
    .swc-foot{display:flex;gap:.5rem;padding:.7rem;border-top:1px solid #e5e7eb;background:#fff}
    .swc-in{flex:1;border:1px solid #d1d5db;border-radius:20px;padding:.6rem .9rem;font-size:.9rem;outline:none;resize:none;max-height:90px;font-family:inherit}
    .swc-in:focus{border-color:<?= $color ?>}
    .swc-send{border:0;background:<?= $color ?>;color:#fff;border-radius:50%;width:40px;height:40px;cursor:pointer;flex:0 0 auto;font-size:1.1rem}
    .swc-send:disabled{opacity:.5;cursor:default}
</style>
<div class="swc-wrap swc-p<?= $preset ?>" id="swcWrap">
    <button class="swc-launch" id="swcLaunch" type="button" aria-label="Открыть чат">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>
        <span class="swc-lt"><?= $btext ?></span>
        <span class="swc-badge" id="swcBadge">0</span>
    </button>
    <div class="swc-panel" id="swcPanel">
        <div class="swc-head">
            <div class="swc-ava" id="swcAva"></div>
            <div class="swc-htxt"><div class="swc-hname" id="swcName"></div><div class="swc-hsub">обычно отвечает быстро</div></div>
            <button class="swc-x" id="swcClose" type="button" aria-label="Закрыть">×</button>
        </div>
        <div class="swc-body" id="swcBody"></div>
        <div class="swc-foot">
            <textarea class="swc-in" id="swcIn" rows="1" placeholder="Сообщение…"></textarea>
            <button class="swc-send" id="swcSend" type="button">➤</button>
        </div>
    </div>
</div>
<script>
(function(){
    var CFG = <?= $json ?>;
    var API = '/chat.php';
    var lastId = 0, opened = false, polling = null, started = false, poll_busy = false, hasSession = false;
    var wrap=document.getElementById('swcWrap'), panel=document.getElementById('swcPanel'),
        body=document.getElementById('swcBody'), inp=document.getElementById('swcIn'),
        sendBtn=document.getElementById('swcSend'), badge=document.getElementById('swcBadge'),
        unread=0;
    document.getElementById('swcName').textContent = CFG.agent;
    var ava=document.getElementById('swcAva');
    if(CFG.photo){ava.style.backgroundImage='url('+CFG.photo+')';}
    else{ava.textContent=(CFG.agent||'?').charAt(0).toUpperCase();}
    var seen={};
    function esc(s){var d=document.createElement('div');d.textContent=(s==null?'':s);return d.innerHTML;}
    function add(m){
        if(m.id && seen[m.id]) return;
        if(m.id) seen[m.id]=1;
        var el=document.createElement('div');
        var cls=m.sender==='visitor'?'swc-v':(m.sender==='system'?'swc-s':'swc-a');
        el.className='swc-msg '+cls; el.textContent=m.body; body.appendChild(el);
        body.scrollTop=body.scrollHeight;
        if(m.id>lastId) lastId=m.id;
    }
    function setBadge(n){unread=n;if(n>0){badge.style.display='flex';badge.textContent=n>9?'9+':n;}else{badge.style.display='none';}}
    function api(q,opt){return fetch(API+q,Object.assign({credentials:'same-origin'},opt||{})).then(function(r){return r.json();});}
    function showGreeting(){
        if(!CFG.greeting) return;
        body.innerHTML='';
        var el=document.createElement('div'); el.className='swc-msg swc-a'; el.textContent=CFG.greeting; body.appendChild(el);
    }
    function startPolling(){ if(!polling) polling=setInterval(poll,(CFG.poll||4)*1000); }
    function start(){
        if(started)return Promise.resolve(); started=true;
        return api('?api=open',{method:'POST'}).then(function(d){
            if(!d||!d.ok)return;
            if(d.poll) CFG.poll=d.poll;
            if(d.exists){
                hasSession=true; lastId=0; body.innerHTML=''; seen={};
                (d.messages||[]).forEach(add);
                startPolling();
            } else {
                hasSession=false; showGreeting();
            }
        });
    }
    function resetChat(){ started=false; hasSession=false; lastId=0; seen={}; body.innerHTML=''; setBadge(0); start(); }
    function poll(){
        if(poll_busy||!hasSession)return; poll_busy=true;
        api('?api=poll&after='+lastId).then(function(d){
            poll_busy=false;
            if(!d)return;
            if(d.gone){ resetChat(); return; }
            if(!d.ok)return;
            (d.messages||[]).forEach(function(m){
                add(m);
                if(!opened && m.sender!=='visitor') setBadge(unread+1);
            });
        }).catch(function(){poll_busy=false;});
    }
    function open(){
        opened=true; wrap.classList.add('swc-open'); setBadge(0);
        start().then(function(){inp.focus();});
    }
    function close(){opened=false; wrap.classList.remove('swc-open');}
    function send(){
        var t=inp.value.trim(); if(!t)return;
        inp.value=''; sendBtn.disabled=true;
        var firstMsg=!hasSession;
        api('?api=send',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'body='+encodeURIComponent(t)})
            .then(function(d){
                sendBtn.disabled=false;
                if(!d||!d.ok)return;
                hasSession=true; startPolling();
                if(firstMsg){ started=false; seen={}; lastId=0; body.innerHTML=''; start(); }
                else { poll(); }
            })
            .catch(function(){sendBtn.disabled=false;});
    }
    document.getElementById('swcLaunch').addEventListener('click',open);
    document.getElementById('swcClose').addEventListener('click',close);
    sendBtn.addEventListener('click',send);
    inp.addEventListener('keydown',function(e){if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();send();}});
})();
</script>
    <?php
}
