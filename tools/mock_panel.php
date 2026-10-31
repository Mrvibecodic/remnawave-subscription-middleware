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
    $out = [
        'uuid'      => sprintf('00000000-0000-4000-8000-%012d', $i),
        'id'        => $i + 1,
        'shortUuid' => $short,
        'username'  => (string) ($u['username'] ?? ('user' . $i)),
        'status'    => (string) ($u['status'] ?? 'ACTIVE'),
        'expireAt'  => gmdate('Y-m-d\TH:i:s.000\Z', time() + 30 * 86400),
        'activeInternalSquads' => [],
    ];
    // hw: лимит устройств (0 — без лимита); отсутствует — «как в панели».
    if (array_key_exists('hw', $u)) $out['hwidDeviceLimit'] = (int) $u['hw'];
    // node: "<nodeUuid>" — пользователь только что был онлайн на этой ноде.
    if (!empty($u['node'])) $out['userTraffic'] = ['onlineAt' => gmdate('Y-m-d\TH:i:s.000\Z', time() - 30), 'lastConnectedNodeUuid' => (string) $u['node'], 'usedTrafficBytes' => 0];
    return $out;
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

if (preg_match('~^/api/users/by-username/([^/]+)$~', $path, $m)) {
    $name = rawurldecode($m[1]);
    foreach (array_keys($users) as $i => $short) {
        if ((string) (($users[$short]['username'] ?? null) ?? ('user' . $i)) === $name) { mp_json(200, ['response' => mp_user($short, (array) $users[$short], (int) $i)]); exit; }
    }
    mp_json(404, ['message' => 'User not found', 'statusCode' => 404]);
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
    foreach ((array) ($state['hosts'] ?? []) as $h) $out[] = ['uuid' => md5((string) ($h['remark'] ?? '')), 'remark' => (string) ($h['remark'] ?? ''), 'address' => (string) ($h['address'] ?? ''), 'port' => 443, 'isDisabled' => !empty($h['off']), 'nodes' => array_values((array) ($h['nodes'] ?? [])), 'tags' => array_values((array) ($h['tags'] ?? []))];
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
// usage: {"<userId|uuid>": {"<nodeUuid>": [байт в день, по кругу]}}; no_usage — панель без этого эндпоинта
if (empty($state['no_usage']) && preg_match('~^/api/bandwidth-stats/users/([^/]+)$~', $path, $m)) {
    $q = [];
    parse_str((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_QUERY), $q);
    $s = strtotime((string) ($q['start'] ?? '') . ' UTC');
    $e = strtotime((string) ($q['end'] ?? '') . ' UTC');
    if (!$s || !$e || $e < $s) { mp_json(400, ['message' => 'Validation failed', 'statusCode' => 400]); exit; }
    if ($log !== '') @file_put_contents($log, 'USAGE ' . $m[1] . ' ' . (string) ($q['start'] ?? '') . ' ' . (string) ($q['end'] ?? '') . "\n", FILE_APPEND | LOCK_EX);
    $cats = [];
    for ($t = $s; $t <= $e; $t += 86400) $cats[] = gmdate('Y-m-d', $t);
    $series = [];
    $spark = array_fill(0, count($cats), 0);
    foreach ((array) (($state['usage'] ?? [])[$m[1]] ?? []) as $nu => $per) {
        $per = array_values((array) $per);
        $data = [];
        foreach ($cats as $i => $d) { $v = $per ? (int) $per[$i % count($per)] : 0; $data[] = $v; $spark[$i] += $v; }
        $series[] = ['uuid' => (string) $nu, 'name' => (string) ($nodes[$nu]['name'] ?? $nu), 'color' => (string) ($nodes[$nu]['color'] ?? '#888888'), 'countryCode' => (string) ($nodes[$nu]['cc'] ?? 'XX'), 'total' => array_sum($data), 'data' => $data];
    }
    mp_json(200, ['response' => ['categories' => $cats, 'sparklineData' => $spark, 'topNodes' => array_map(static fn($x) => array_diff_key($x, ['data' => 1]), $series), 'series' => $series]]);
    exit;
}
if (($path === '/api/connections/drop' || $path === '/api/ip-control/drop-connections') && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($log !== '') @file_put_contents($log, 'DROP ' . (string) file_get_contents('php://input') . "\n", FILE_APPEND | LOCK_EX);
    mp_json(202, ['response' => true]);
    exit;
}

// Справочники: squads: [{"uuid": "…", "name": "…", "members": 3}], esquads — то же, hwid: {"enabled": true, "limit": 2}
if ($path === '/api/internal-squads' || $path === '/api/external-squads') {
    $out = [];
    foreach ((array) ($state[$path === '/api/internal-squads' ? 'squads' : 'esquads'] ?? []) as $s) $out[] = ['uuid' => (string) ($s['uuid'] ?? ''), 'name' => (string) ($s['name'] ?? ''), 'info' => ['membersCount' => (int) ($s['members'] ?? 0)]];
    mp_json(200, ['response' => [$path === '/api/internal-squads' ? 'internalSquads' : 'externalSquads' => $out]]);
    exit;
}
// Правила ответа: srr — конфиг как в панели (по умолчанию — дефолтные правила панели), srr_upd — updatedAt.
function mp_srr_default(): array
{
    $c = static fn(string $h, string $op, string $v, bool $cs) => ['headerName' => $h, 'operator' => $op, 'value' => $v, 'caseSensitive' => $cs];
    $r = static fn(string $n, array $cond, string $t) => ['name' => $n, 'enabled' => true, 'operator' => 'AND', 'conditions' => $cond, 'responseType' => $t];
    return ['version' => '1', 'settings' => [], 'rules' => [
        $r('Browser Subscription', [$c('accept', 'CONTAINS', 'text/html', true)], 'BROWSER'),
        $r('Mihomo Clients', [$c('user-agent', 'REGEX', '^(?:flclash|rabbit|flowvy|murge|mihomo|prizrak-box|koala-clash|clash(?:-verge|-nyanpasu|x meta|[-.]?meta))', false)], 'MIHOMO'),
        $r('Stash (iOS, macOS)', [$c('user-agent', 'REGEX', '^stash', false)], 'STASH'),
        $r('Sing-box clients', [$c('user-agent', 'REGEX', '^sfa|sfi|sfm|sft|karing|singbox', false)], 'SINGBOX'),
        $r('Clash Core Clients', [$c('user-agent', 'REGEX', '^clash', false)], 'CLASH'),
        $r('Fallback Base64', [], 'XRAY_BASE64'),
    ]];
}

// Проверка конфига как у панели (Zod): неизвестные ключи молча выкидываются, ошибка — текстом.
function mp_srr_parse($in, ?string &$err): ?array
{
    $err = null;
    if (!is_array($in)) { $err = 'responseRules: expected object'; return null; }
    if (($in['version'] ?? null) !== '1') { $err = 'version: Invalid enum value'; return null; }
    if (!is_array($in['rules'] ?? null)) { $err = 'rules: Required'; return null; }
    $ops = ['EQUALS', 'NOT_EQUALS', 'CONTAINS', 'NOT_CONTAINS', 'STARTS_WITH', 'NOT_STARTS_WITH', 'ENDS_WITH', 'NOT_ENDS_WITH', 'REGEX', 'NOT_REGEX'];
    $types = ['XRAY_BASE64', 'XRAY_JSON', 'MIHOMO', 'STASH', 'CLASH', 'SINGBOX', 'BROWSER', 'BLOCK', 'STATUS_CODE_404', 'STATUS_CODE_451', 'SOCKET_DROP'];
    $out = ['version' => '1', 'rules' => []];
    if (isset($in['settings']) && is_array($in['settings'])) {
        $out['settings'] = [];
        if (array_key_exists('disableSubscriptionAccessByPath', $in['settings'])) {
            if (!is_bool($in['settings']['disableSubscriptionAccessByPath'])) { $err = 'settings.disableSubscriptionAccessByPath: Expected boolean'; return null; }
            $out['settings']['disableSubscriptionAccessByPath'] = $in['settings']['disableSubscriptionAccessByPath'];
        }
    }
    foreach ($in['rules'] as $i => $r) {
        if (!is_array($r)) { $err = "rules.$i: Expected object"; return null; }
        $n = $r['name'] ?? null;
        if (!is_string($n) || $n === '') { $err = "rules.$i.name: Name is required"; return null; }
        if (mb_strlen($n) > 50) { $err = "rules.$i.name: Name must be less than 50 characters"; return null; }
        if (array_key_exists('description', $r) && (!is_string($r['description']) || $r['description'] === '' || mb_strlen($r['description']) > 250)) { $err = "rules.$i.description: invalid"; return null; }
        if (!is_bool($r['enabled'] ?? null)) { $err = "rules.$i.enabled: Expected boolean"; return null; }
        if (!in_array($r['operator'] ?? null, ['AND', 'OR'], true)) { $err = "rules.$i.operator: Invalid enum value"; return null; }
        if (!is_array($r['conditions'] ?? null)) { $err = "rules.$i.conditions: Required"; return null; }
        if (!in_array($r['responseType'] ?? null, $types, true)) { $err = "rules.$i.responseType: Invalid response type"; return null; }
        $o = ['name' => $n, 'enabled' => $r['enabled'], 'operator' => $r['operator'], 'conditions' => [], 'responseType' => $r['responseType']];
        if (isset($r['description'])) $o['description'] = $r['description'];
        foreach ($r['conditions'] as $j => $c) {
            if (!is_array($c) || !is_string($c['headerName'] ?? null) || !preg_match('/^[!#$%&\'*+.0-9A-Z^_`a-z|~-]+$/', $c['headerName'])) { $err = "rules.$i.conditions.$j.headerName: Invalid header name"; return null; }
            if (!in_array($c['operator'] ?? null, $ops, true)) { $err = "rules.$i.conditions.$j.operator: Invalid operator"; return null; }
            if (!is_string($c['value'] ?? null) || $c['value'] === '' || mb_strlen($c['value']) > 255) { $err = "rules.$i.conditions.$j.value: Value is required"; return null; }
            if (!is_bool($c['caseSensitive'] ?? null)) { $err = "rules.$i.conditions.$j.caseSensitive: Expected boolean"; return null; }
            if (in_array($c['operator'], ['REGEX', 'NOT_REGEX'], true) && @preg_match('~' . str_replace('~', '\\~', $c['value']) . '~', '') === false) { $err = 'Invalid regex in rule "' . $n . '": ' . $c['value']; return null; }
            $o['conditions'][] = ['headerName' => $c['headerName'], 'operator' => $c['operator'], 'value' => $c['value'], 'caseSensitive' => $c['caseSensitive']];
        }
        if (isset($r['responseModifications']) && is_array($r['responseModifications'])) {
            $m = $r['responseModifications']; $mo = [];
            if (isset($m['headers'])) {
                if (!is_array($m['headers'])) { $err = "rules.$i.responseModifications.headers: Expected array"; return null; }
                $mo['headers'] = [];
                foreach ($m['headers'] as $h) {
                    if (!is_array($h) || !is_string($h['key'] ?? null) || !preg_match('/^[!#$%&\'*+.0-9A-Z^_`a-z|~-]+$/', $h['key']) || !is_string($h['value'] ?? null) || $h['value'] === '') { $err = "rules.$i.responseModifications.headers: invalid"; return null; }
                    $mo['headers'][] = ['key' => $h['key'], 'value' => $h['value']];
                }
            }
            foreach (['applyHeadersToEnd', 'ignoreHostXrayJsonTemplate', 'ignoreServeJsonAtBaseSubscription', 'disableHwidCheck'] as $k) if (isset($m[$k])) { if (!is_bool($m[$k])) { $err = "rules.$i.responseModifications.$k: Expected boolean"; return null; } $mo[$k] = $m[$k]; }
            if (isset($m['subscriptionTemplate'])) { if (!is_string($m['subscriptionTemplate']) || $m['subscriptionTemplate'] === '') { $err = "rules.$i.responseModifications.subscriptionTemplate: required"; return null; } $mo['subscriptionTemplate'] = $m['subscriptionTemplate']; }
            if (isset($m['additionalExtendedClientsRegex'])) { foreach ((array) $m['additionalExtendedClientsRegex'] as $re) if (!is_string($re) || $re === '' || @preg_match('~' . str_replace('~', '\\~', $re) . '~', '') === false) { $err = 'Invalid regex in rule "' . $n . '": ' . (string) $re; return null; } $mo['additionalExtendedClientsRegex'] = array_values($m['additionalExtendedClientsRegex']); }
            if (isset($m['encryption'])) { if (!is_array($m['encryption']) || !in_array($m['encryption']['method'] ?? null, ['age1', 'age1pq1'], true) || !is_string($m['encryption']['key'] ?? null)) { $err = "rules.$i.responseModifications.encryption: invalid"; return null; } $mo['encryption'] = ['method' => $m['encryption']['method'], 'key' => $m['encryption']['key']]; }
            if (isset($m['excludeHostsByTags'])) { if (!is_array($m['excludeHostsByTags']) || !$m['excludeHostsByTags']) { $err = "rules.$i.responseModifications.excludeHostsByTags: too small"; return null; } foreach ($m['excludeHostsByTags'] as $t) if (!is_string($t) || !preg_match('~^[A-Z0-9_:]+$~', $t) || strlen($t) > 36) { $err = 'Each tag must be less than 36 characters'; return null; } $mo['excludeHostsByTags'] = array_values($m['excludeHostsByTags']); }
            if (isset($m['respondWithRemarks'])) { if (!is_array($m['respondWithRemarks'])) { $err = "rules.$i.responseModifications.respondWithRemarks: Expected array"; return null; } $mo['respondWithRemarks'] = array_values(array_map('strval', $m['respondWithRemarks'])); }
            $o['responseModifications'] = $mo;
        }
        $out['rules'][] = $o;
    }
    return $out;
}

// Сопоставление как в панели: заголовок в нижнем регистре; нет заголовка — ложь при любом операторе;
// без учёта регистра опускаются и значение, и шаблон.
function mp_srr_match(array $cfg, array $headers): array
{
    foreach ($cfg['rules'] as $rule) {
        if (empty($rule['enabled'])) continue;
        $res = [];
        foreach ($rule['conditions'] as $c) {
            $hv = $headers[strtolower($c['headerName'])] ?? null;
            if ($hv === null) { $res[] = false; continue; }
            $v = $c['value'];
            if (empty($c['caseSensitive'])) { $hv = strtolower($hv); $v = strtolower($v); }
            $re = static fn() => (bool) @preg_match('~' . str_replace('~', '\\~', $v) . '~' . (empty($c['caseSensitive']) ? 'i' : ''), $hv);
            $res[] = match ($c['operator']) {
                'EQUALS' => $hv === $v, 'NOT_EQUALS' => $hv !== $v,
                'CONTAINS' => str_contains($hv, $v), 'NOT_CONTAINS' => !str_contains($hv, $v),
                'STARTS_WITH' => str_starts_with($hv, $v), 'NOT_STARTS_WITH' => !str_starts_with($hv, $v),
                'ENDS_WITH' => str_ends_with($hv, $v), 'NOT_ENDS_WITH' => !str_ends_with($hv, $v),
                'REGEX' => $re(), 'NOT_REGEX' => !$re(), default => false,
            };
        }
        $ok = !$rule['conditions'] || ($rule['operator'] === 'OR' ? in_array(true, $res, true) : !in_array(false, $res, true));
        if ($ok) return ['matched' => true, 'matchedRule' => $rule, 'responseType' => $rule['responseType']];
    }
    return ['matched' => false, 'matchedRule' => null, 'responseType' => 'BLOCK'];
}

function mp_srr_state(array $state): array
{
    return is_array($state['srr'] ?? null) ? $state['srr'] : mp_srr_default();
}

function mp_settings_body(array $state): array
{
    $hw = is_array($state['hwid'] ?? null) ? ['enabled' => !empty($state['hwid']['enabled']), 'fallbackDeviceLimit' => (int) ($state['hwid']['limit'] ?? 0)] : null;
    return ['uuid' => 'a1b2c3d4-0000-4000-8000-000000000001', 'serveJsonAtBaseSubscription' => !empty($state['serve_json']), 'isShowCustomRemarks' => !empty($state['custom_remarks']),
            'customRemarks' => [], 'customResponseHeaders' => null, 'randomizeHosts' => false, 'responseRules' => mp_srr_state($state), 'hwidSettings' => $hw,
            'createdAt' => '2026-01-01T00:00:00.000Z', 'updatedAt' => (string) ($state['srr_upd'] ?? '2026-01-01T00:00:00.000Z')];
}

if ($path === '/api/subscription-settings' && $_SERVER['REQUEST_METHOD'] === 'PATCH') {
    $body = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($body) || ($body['uuid'] ?? '') === '') { mp_json(400, ['message' => 'uuid: Required', 'statusCode' => 400]); exit; }
    if (!empty($state['srr_forbid'])) { mp_json(403, ['message' => 'Forbidden', 'statusCode' => 403]); exit; }
    if (array_key_exists('responseRules', $body)) {
        $state['srr_raw'] = $body['responseRules'];
        $err = null;
        $parsed = mp_srr_parse($body['responseRules'], $err);
        if ($parsed === null) { mp_json(400, ['message' => 'Config validation error: ' . $err, 'statusCode' => 400]); exit; }
        $state['srr'] = $parsed;
        $state['srr_upd'] = gmdate('Y-m-d\TH:i:s.000\Z');
        @file_put_contents((string) getenv('MOCK_STATE'), json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
    mp_json(200, ['response' => mp_settings_body($state)]);
    exit;
}
if ($path === '/api/subscription-settings') {
    mp_json(200, ['response' => mp_settings_body($state)]);
    exit;
}
if ($path === '/api/system/testers/srr-matcher' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!empty($state['srr_tester_forbid'])) { mp_json(403, ['message' => 'Forbidden', 'statusCode' => 403]); exit; }
    $body = json_decode((string) file_get_contents('php://input'), true);
    $err = null;
    $parsed = mp_srr_parse(is_array($body) ? ($body['responseRules'] ?? null) : null, $err);
    if ($parsed === null) { mp_json(500, ['error' => 'Internal server error', 'message' => (string) $err]); exit; }
    $hdr = [];
    foreach ($_SERVER as $k => $v) if (strpos($k, 'HTTP_') === 0) $hdr[strtolower(str_replace('_', '-', substr($k, 5)))] = (string) $v;
    if (isset($_SERVER['CONTENT_TYPE'])) $hdr['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
    if (($state['srr_tester_fail_ua'] ?? '') !== '' && stripos($hdr['user-agent'] ?? '', (string) $state['srr_tester_fail_ua']) !== false) { mp_json(502, ['message' => 'Bad gateway']); exit; }
    $r = mp_srr_match($parsed, $hdr);
    mp_json(200, ['response' => ['matched' => $r['matched'], 'matchedRule' => $r['matchedRule'], 'responseType' => $r['responseType'], 'inputHeaders' => $hdr, 'outputHeaders' => $r['matchedRule']['responseModifications']['headers'] ?? []]]);
    exit;
}
if ($path === '/api/subscription-templates') {
    $tpl = is_array($state['templates'] ?? null) ? $state['templates'] : [['name' => 'Default', 'type' => 'XRAY_JSON'], ['name' => 'Default', 'type' => 'MIHOMO'], ['name' => 'Gaming', 'type' => 'XRAY_JSON']];
    $out = [];
    foreach ($tpl as $i => $t) $out[] = ['uuid' => sprintf('t-%03d', $i), 'name' => (string) $t['name'], 'templateType' => (string) $t['type'], 'viewPosition' => $i];
    mp_json(200, ['response' => ['templates' => $out]]);
    exit;
}
if ($path === '/api/config-profiles/inbounds') {
    mp_json(200, ['response' => ['inbounds' => array_values((array) ($state['inbounds'] ?? []))]]);
    exit;
}
if ($path === '/api/system/stats') {
    $on = 0;
    foreach ($nodes as $n) if (empty($n['off'])) $on++;
    mp_json(200, ['response' => ['users' => ['statusCounts' => ['ACTIVE' => count($users), 'LIMITED' => 0, 'EXPIRED' => 0, 'DISABLED' => 0], 'totalUsers' => count($users)], 'onlineStats' => ['onlineNow' => 0, 'lastDay' => 0, 'lastWeek' => 0], 'nodes' => ['totalOnline' => $on]]]);
    exit;
}

if ($path === '/api/system/metadata') {
    mp_json(200, ['response' => ['version' => (string) ($state['version'] ?? '2.8.1'), 'build' => ['time' => '2026-01-01T00:00:00Z', 'number' => '1'], 'git' => ['backend' => ['commitSha' => 'test', 'branch' => 'main', 'commitUrl' => '']]]]);
    exit;
}

mp_json(404, ['message' => 'Cannot ' . ($_SERVER['REQUEST_METHOD'] ?? 'GET') . ' ' . $path, 'statusCode' => 404]);
