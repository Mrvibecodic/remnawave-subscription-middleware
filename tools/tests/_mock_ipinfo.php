<?php
// Мок ipinfo.io для тестов: GET /<ip>/json. Режим — из файла MOCK_IPINFO: ok | 429 | 500 | 403;
// ответы по адресу — из того же файла (map), каждый запрос дописывается в MOCK_IPINFO_LOG.
if (PHP_SAPI !== 'cli-server') { http_response_code(404); exit; }
$st = json_decode((string) @file_get_contents((string) getenv('MOCK_IPINFO')), true) ?: [];
$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
if (!preg_match('~^/([0-9a-fA-F:.]+)/json$~', $path, $m)) { http_response_code(404); exit; }
$ip = $m[1];
file_put_contents((string) getenv('MOCK_IPINFO_LOG'), $ip . ' ' . ($_GET['token'] ?? '-') . "\n", FILE_APPEND);
$mode = (string) ($st['mode'] ?? 'ok');
if (isset($st['fail'][$ip])) $mode = (string) $st['fail'][$ip];
header('Content-Type: application/json');
if ($mode === '429') { http_response_code(429); echo '{"error":"Rate limit exceeded"}'; exit; }
if ($mode === '403') { http_response_code(403); echo '{"error":"Invalid token"}'; exit; }
if ($mode === '500') { http_response_code(500); echo 'oops'; exit; }
if ($mode === 'sleep') { sleep(10); }
$r = $st['map'][$ip] ?? ['city' => 'Moscow', 'region' => 'Moscow', 'country' => 'RU', 'loc' => '55.7522,37.6156', 'org' => 'AS8359 MTS PJSC'];
echo json_encode(['ip' => $ip] + $r, JSON_UNESCAPED_UNICODE);
