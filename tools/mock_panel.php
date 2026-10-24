<?php
declare(strict_types=1);

// Мок панели Remnawave для тестов: php -S 127.0.0.1:<порт> tools/mock_panel.php
// Состояние — JSON из MOCK_STATE (перечитывается на каждый запрос), журнал запросов — MOCK_LOG.
// users: {"<shortUuid>": {"status": "ACTIVE|DISABLED|LIMITED|EXPIRED", "mode": "hwidlimit|404|apidown"}}

if (PHP_SAPI !== 'cli-server') { http_response_code(404); exit; }

$state = json_decode((string) @file_get_contents((string) getenv('MOCK_STATE')), true);
if (!is_array($state)) $state = [];
$users = is_array($state['users'] ?? null) ? $state['users'] : [];
$path  = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$log   = (string) getenv('MOCK_LOG');
if ($log !== '') {
    $hw = (string) ($_SERVER['HTTP_X_HWID'] ?? '');
    @file_put_contents($log, ($_SERVER['REQUEST_METHOD'] ?? 'GET') . ' ' . $path . ($hw !== '' ? ' hwid=' . $hw : '') . "\n", FILE_APPEND | LOCK_EX);
}
if (!empty($state['latency_ms'])) usleep((int) $state['latency_ms'] * 1000);

function mp_json(int $code, $data): void
{
    http_response_code($code);
    header('content-type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function mp_user(string $short, array $u, int $i): array
{
    return [
        'uuid'      => sprintf('00000000-0000-4000-8000-%012d', $i),
        'id'        => $i + 1,
        'shortUuid' => $short,
        'username'  => (string) ($u['username'] ?? ('user' . $i)),
        'status'    => (string) ($u['status'] ?? 'ACTIVE'),
        'expireAt'  => gmdate('Y-m-d\TH:i:s.000\Z', time() + 30 * 86400),
        'activeInternalSquads' => [],
    ];
}

if (preg_match('~^/api/sub/([A-Za-z0-9_-]+)~', $path, $m)) {
    $short = $m[1];
    $u = $users[$short] ?? null;
    if ($u === null || ($u['mode'] ?? '') === '404') { mp_json(404, ['message' => 'Not found', 'statusCode' => 404]); exit; }
    if (($u['mode'] ?? '') === 'apidown') { http_response_code(502); echo 'Bad Gateway'; exit; }
    $ua    = strtolower((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    $clash = (bool) preg_match('/clash|mihomo/', $ua);
    $exp   = time() + 30 * 86400;
    $st    = (string) ($u['status'] ?? 'ACTIVE');
    header('subscription-userinfo: upload=1024; download=2048; total=107374182400; expire=' . ($st === 'EXPIRED' ? time() - 86400 : $exp));
    header('profile-title: base64:' . base64_encode('Тест'));
    header('profile-update-interval: 12');
    header('content-disposition: attachment; filename="' . $short . '"');
    if (($u['mode'] ?? '') === 'hwidlimit') {
        header('x-hwid-limit: true');
        header('announce: base64:' . base64_encode('Лимит устройств'));
        echo base64_encode('vless://00000000-0000-0000-0000-000000000000@0.0.0.0:1?type=tcp#limit');
        exit;
    }
    $nodes = $st === 'ACTIVE' ? 3 : 1;
    if ($clash) {
        header('content-type: text/yaml; charset=utf-8');
        echo "proxies:\n";
        for ($i = 0; $i < $nodes; $i++) echo "  - {name: \"{$short}-{$i}\", type: vless, server: n{$i}.example.net, port: 443, uuid: 11111111-2222-4333-8444-55555555555{$i}, network: tcp, tls: true}\n";
        echo "proxy-groups:\n  - {name: PROXY, type: select, proxies: [\"{$short}-0\"]}\nrules:\n  - MATCH,PROXY\n";
    } else {
        header('content-type: text/plain; charset=utf-8');
        $lines = [];
        for ($i = 0; $i < $nodes; $i++) $lines[] = "vless://11111111-2222-4333-8444-55555555555{$i}@n{$i}.example.net:443?type=tcp&security=tls#" . rawurlencode($short . '-' . $i . ($st === 'ACTIVE' ? '' : '-' . strtolower($st)));
        echo base64_encode(implode("\n", $lines));
    }
    exit;
}

if ($path === '/api/users') {
    $size  = max(1, (int) ($_GET['size'] ?? 250));
    $start = max(0, (int) ($_GET['start'] ?? 0));
    $all   = [];
    $i = 0;
    foreach ($users as $short => $u) $all[] = mp_user((string) $short, (array) $u, $i++);
    mp_json(200, ['response' => ['users' => array_slice($all, $start, $size), 'total' => count($all)]]);
    exit;
}

if (preg_match('~^/api/users/by-short-uuid/([A-Za-z0-9_-]+)$~', $path, $m)) {
    $keys = array_keys($users);
    $i = array_search($m[1], $keys, true);
    if ($i === false) { mp_json(404, ['message' => 'User not found', 'statusCode' => 404]); exit; }
    mp_json(200, ['response' => mp_user($m[1], (array) $users[$m[1]], (int) $i)]);
    exit;
}

// Обозреватель сессий: nodes: {"<uuid>": {"name": "…", "cc": "NL", "off": false}},
// conns: {"<nodeUuid>": {"<userId>": ["1.2.3.4", …]}}; принимает оба семейства адресов (2.x и 3.x).
$nodes = is_array($state['nodes'] ?? null) ? $state['nodes'] : [];
$conns = is_array($state['conns'] ?? null) ? $state['conns'] : [];
if ($path === '/api/nodes') {
    $out = [];
    foreach ($nodes as $nu => $n) $out[] = ['uuid' => (string) $nu, 'name' => (string) ($n['name'] ?? $nu), 'countryCode' => (string) ($n['cc'] ?? 'XX'), 'isConnected' => empty($n['off']), 'isDisabled' => false, 'isConnecting' => false];
    mp_json(200, ['response' => $out]);
    exit;
}
if ($path === '/api/hosts') {
    // hosts: [{"remark": "…", "address": "…", "nodes": ["<nodeUuid>"], "off": false}]
    $out = [];
    foreach ((array) ($state['hosts'] ?? []) as $h) $out[] = ['uuid' => md5((string) ($h['remark'] ?? '')), 'remark' => (string) ($h['remark'] ?? ''), 'address' => (string) ($h['address'] ?? ''), 'port' => 443, 'isDisabled' => !empty($h['off']), 'nodes' => array_values((array) ($h['nodes'] ?? []))];
    mp_json(200, ['response' => $out]);
    exit;
}
if (preg_match('~^/api/(connections/by-node|ip-control/fetch-users-ips)/([^/]+)$~', $path, $m) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($nodes[$m[2]])) { mp_json(404, ['message' => 'Node not found', 'errorCode' => 'A011']); exit; }
    mp_json(201, ['response' => ['jobId' => 'n-' . $m[2]]]);
    exit;
}
if (preg_match('~^/api/(connections/by-node|ip-control/fetch-users-ips/result)/n-([^/]+)$~', $path, $m)) {
    $users = [];
    foreach ((array) ($conns[$m[2]] ?? []) as $uid => $ips) $users[] = ['userId' => (string) $uid, 'ips' => array_map(static fn($ip) => ['ip' => $ip, 'lastSeen' => gmdate('Y-m-d\TH:i:s.000\Z')], (array) $ips)];
    $slow = !empty($state['job_slow']);
    mp_json(200, ['response' => ['isCompleted' => !$slow, 'isFailed' => false, 'result' => $slow ? null : ['success' => true, 'nodeUuid' => $m[2], 'users' => $users]]]);
    exit;
}
if (preg_match('~^/api/(connections/by-user|ip-control/fetch-ips)/([^/]+)$~', $path, $m) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    mp_json(201, ['response' => ['jobId' => 'u-' . $m[2]]]);
    exit;
}
if (preg_match('~^/api/(connections/by-user|ip-control/fetch-ips/result)/u-([^/]+)$~', $path, $m)) {
    $ref = $m[2];
    $i = 0; $uid = null;
    foreach ($users as $short => $u) { $mu = mp_user((string) $short, (array) $u, $i++); if ((string) $mu['id'] === $ref || $mu['uuid'] === $ref) $uid = $mu['id']; }
    $out = [];
    foreach ($conns as $nu => $byUser) {
        $ips = (array) ($byUser[(string) $uid] ?? []);
        if ($ips) $out[] = ['nodeUuid' => (string) $nu, 'nodeName' => (string) ($nodes[$nu]['name'] ?? $nu), 'countryCode' => (string) ($nodes[$nu]['cc'] ?? 'XX'), 'ips' => array_map(static fn($ip) => ['ip' => $ip, 'lastSeen' => gmdate('Y-m-d\TH:i:s.000\Z')], $ips)];
    }
    mp_json(200, ['response' => ['isCompleted' => true, 'isFailed' => false, 'progress' => ['total' => count($nodes), 'completed' => count($nodes), 'percent' => 100], 'result' => ['success' => true, 'userId' => (int) $uid, 'nodes' => $out]]]);
    exit;
}
if (($path === '/api/connections/drop' || $path === '/api/ip-control/drop-connections') && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($log !== '') @file_put_contents($log, 'DROP ' . (string) file_get_contents('php://input') . "\n", FILE_APPEND | LOCK_EX);
    mp_json(202, ['response' => true]);
    exit;
}

if ($path === '/api/system/metadata') {
    mp_json(200, ['response' => ['version' => (string) ($state['version'] ?? '2.8.1'), 'build' => ['time' => '2026-01-01T00:00:00Z', 'number' => '1'], 'git' => ['backend' => ['commitSha' => 'test', 'branch' => 'main', 'commitUrl' => '']]]]);
    exit;
}

mp_json(404, ['message' => 'Cannot ' . ($_SERVER['REQUEST_METHOD'] ?? 'GET') . ' ' . $path, 'statusCode' => 404]);
