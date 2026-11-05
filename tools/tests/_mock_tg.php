<?php
// Мок Telegram Bot API для тестов: POST /bot<токен>/<метод>. Состояние — из файла MOCK_TG (JSON):
// updates — ответ getUpdates, hook — «включён вебхук», fail — с какого по счёту sendDocument отвечать ошибкой fail_code
// (по умолчанию 500), bad_token — токен, на который getMe отвечает 401, migrate — [старый id, новый id] группы.
// Каждый вызов дописывается строкой JSON в MOCK_TG_LOG, принятые файлы складываются в MOCK_TG_DIR по порядку.
if (PHP_SAPI !== 'cli-server') { http_response_code(404); exit; }
$st = json_decode((string) @file_get_contents((string) getenv('MOCK_TG')), true) ?: [];
$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
header('Content-Type: application/json');
if (!preg_match('~^/bot([^/]+)/([A-Za-z]+)$~', $path, $m)) { http_response_code(404); echo '{"ok":false,"error_code":404,"description":"Not Found"}'; exit; }
[, $tok, $method] = $m;
$log = (string) getenv('MOCK_TG_LOG');
$dir = (string) getenv('MOCK_TG_DIR');
$entry = ['m' => $method, 'tok' => $tok, 'chat' => (string) ($_POST['chat_id'] ?? ''), 'text' => (string) ($_POST['text'] ?? $_POST['caption'] ?? '')];
$out = static function ($ok, $res = null, $desc = '', $code = 200) {
    http_response_code($code);
    echo json_encode($ok ? ['ok' => true, 'result' => $res] : ['ok' => false, 'error_code' => $code, 'description' => $desc], JSON_UNESCAPED_UNICODE);
};
if (($st['bad_token'] ?? '') === $tok) { file_put_contents($log, json_encode($entry, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND); $out(false, null, 'Unauthorized', 401); exit; }
if (isset($st['migrate']) && ($method === 'sendDocument' || $method === 'sendMessage') && $entry['chat'] === (string) $st['migrate'][0]) {
    file_put_contents($log, json_encode($entry + ['migrated' => 1], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
    http_response_code(400);
    echo json_encode(['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: group chat was upgraded to a supergroup chat', 'parameters' => ['migrate_to_chat_id' => (int) $st['migrate'][1]]]);
    exit;
}
if ($method === 'sendDocument') {
    $f = $_FILES['document'] ?? null;
    $entry['file'] = is_array($f) ? (string) $f['name'] : '';
    $entry['size'] = is_array($f) ? (int) $f['size'] : -1;
    $entry['up_err'] = is_array($f) ? (int) $f['error'] : -1;
    $n = count(glob($dir . '/*') ?: []);
    $fail = (int) ($st['fail'] ?? 0);
    if ($fail > 0 && $n + 1 >= $fail) { $entry['refused'] = 1; file_put_contents($log, json_encode($entry, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND); $fc = (int) ($st['fail_code'] ?? 500); $out(false, null, $fc === 500 ? 'Internal Server Error: test failure' : 'Bad Request: test failure', $fc); exit; }
    if (is_array($f) && (int) $f['error'] === 0) move_uploaded_file($f['tmp_name'], $dir . '/' . sprintf('%04d', $n + 1) . '-' . basename((string) $f['name']));
}
file_put_contents($log, json_encode($entry, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
if ($method === 'getMe') { $out(true, ['id' => 1, 'is_bot' => true, 'username' => 'submw_test_bot']); exit; }
if ($method === 'getUpdates') {
    if (!empty($st['hook'])) { $out(false, null, "Conflict: can't use getUpdates method while webhook is active; use deleteWebhook to delete the webhook first", 409); exit; }
    $out(true, $st['updates'] ?? []);
    exit;
}
if ($method === 'sendMessage' || $method === 'sendDocument') {
    if (!empty($st['chat_gone'])) { $out(false, null, 'Bad Request: chat not found', 400); exit; }
    $out(true, ['message_id' => 1]);
    exit;
}
$out(false, null, 'Not Found', 404);
