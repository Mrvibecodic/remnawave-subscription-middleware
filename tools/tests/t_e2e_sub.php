<?php
declare(strict_types=1);

require __DIR__ . '/_boot.php';
require __DIR__ . '/_c1.php';

$state = $tt_root . '/data/mock_state.json';
$mlog  = $tt_root . '/data/mock.log';
$users = ['AbCdEfGhIjKlMnOp' => ['status' => 'ACTIVE'], 'QrStUvWxYz012345' => ['status' => 'ACTIVE', 'mode' => 'hwidlimit'], 'Zz9Yy8Xx7Ww6Vv5U' => ['status' => 'DISABLED']];
file_put_contents($state, json_encode(['users' => $users]));
@unlink($mlog);

$panel = t_serve($tt_root . '/tools', $tt_root . '/tools/mock_panel.php', ['MOCK_STATE' => $state, 'MOCK_LOG' => $mlog]);
t_settings(['sub_source' => 'panel', 'remnawave_url' => $panel, 'remnawave_api_key' => 'test-token', 'chan_enabled' => '1', 'rep_enabled' => '1', 'geoip_auto' => '0']);
$sodium = extension_loaded('sodium');
if ($sodium) t_ok('индекс меток собран с мок-панели', chan_index_rebuild(true) === true);
$mw = t_serve($tt_root, $tt_root . '/tools/tests/_router.php');

$clashUa = 'ClashMeta/1.19.0 mihomo';
[$c, $h, $b] = t_http('GET', $mw . '/AbCdEfGhIjKlMnOp', ['User-Agent' => $clashUa, 'x-hwid' => 'hw-plain']);
t_eq('открытая подписка: код', $c, 200);
t_ok('открытая подписка: тело YAML от панели', strpos($b, 'proxies:') === 0 && strpos($b, 'AbCdEfGhIjKlMnOp-2') !== false, substr($b, 0, 120));
t_ok('открытая подписка: subscription-userinfo пробрасывается', strpos($h['subscription-userinfo'] ?? '', 'total=107374182400') !== false);
t_eq('открытая подписка: profile-title', $h['profile-title'] ?? null, 'base64:' . base64_encode('Тест'));
$plainClash = $b;

[$c, $h, $b] = t_http('GET', $mw . '/AbCdEfGhIjKlMnOp', ['User-Agent' => 'v2rayN/7.0']);
$lines = explode("\n", (string) base64_decode($b, true));
t_ok('base64-подписка: три узла', $c === 200 && count($lines) === 3 && strpos($lines[0], 'vless://') === 0);

[$c] = t_http('GET', $mw . '/NoSuchShortUuid0', ['User-Agent' => $clashUa]);
t_eq('неизвестный адрес: 404 от панели', $c, 404);

[$c, $h] = t_http('GET', $mw . '/QrStUvWxYz012345', ['User-Agent' => 'v2rayN/7.0']);
t_eq('лимит устройств: x-hwid-limit пробрасывается', $h['x-hwid-limit'] ?? null, 'true');

if (!$sodium) {
    echo "  пропуск: канал c1 и отчёты — в этой сборке PHP нет sodium\n";
    t_done();
}

$fields = ['hwid' => 'hw-chan-1', 'os' => 'Windows', 'osv' => '10', 'model' => 'PC', 'ua' => $clashUa, 'acc' => '*/*'];
[$path, $ctx] = c1_request('AbCdEfGhIjKlMnOp', null, $fields);
[$c, $h, $b] = t_http('GET', $mw . $path);
$r = c1_open($ctx, $b);
t_eq('c1 без закрепления: снаружи 200', $c, 200);
t_eq('c1: снаружи octet-stream', $h['content-type'] ?? null, 'application/octet-stream');
t_ok('c1: ответ расшифровался', is_array($r));
t_eq('c1: код внутри', $r['st'] ?? null, 200);
t_eq('c1: тело совпадает с открытым', $r['body'] ?? null, $plainClash);
t_ok('c1: длина ответа кратна 4096', strlen(trim($b)) % 4096 === 0);
t_eq('c1: метка приёма отчётов', $r['meta']['clod-report'][0] ?? null, 'true');
t_ok('c1: заголовки панели внутри шифра', strpos($r['meta']['subscription-userinfo'][0] ?? '', 'total=') !== false && !isset($h['subscription-userinfo']));
$sp = chan_unb64((string) ($r['sp'] ?? ''));
t_ok('c1: ключ прослойки в ответе', is_string($sp) && strlen($sp) === 32);
t_ok('c1: hwid из конверта дошёл до панели', strpos((string) @file_get_contents($mlog), '/api/sub/AbCdEfGhIjKlMnOp hwid=hw-chan-1') !== false);

[$c2] = t_http('GET', $mw . $path);
t_eq('c1: повтор того же запроса — как мусорный адрес', $c2, 404);

[$path, $ctx] = c1_request('AbCdEfGhIjKlMnOp', $sp, $fields);
[$c, , $b] = t_http('GET', $mw . $path);
$r = c1_open($ctx, $b);
t_eq('c1 с закреплённым ключом: код внутри', $r['st'] ?? null, 200);

[$path] = c1_request('NotInPanelAtAll0', null, $fields);
[$c] = t_http('GET', $mw . $path);
t_eq('c1 с чужой подпиской: 404', $c, 404);

$before = substr_count((string) @file_get_contents($mlog), "\n");
[$c] = t_http('POST', $mw . '/c1/AAAAAAAAAAAA/0/' . chan_b64(random_bytes(80)), [], 'x');
t_eq('мусорный POST на c1: 404', $c, 404);
t_eq('мусорный POST на c1: в панель не ходит', substr_count((string) @file_get_contents($mlog), "\n"), $before);

$top = intdiv(time(), 3600) * 3600 - 3600;
$hours = [];
for ($i = 0; $i < 3; $i++) {
    $hours[] = ['h' => $top - $i * 3600, 'ip4' => '95.24.1.' . (10 + $i), 'ping' => ['n0' => ['n' => 60, 'fail' => 1, 'b' => [40, 15, 3, 1, 0, 0]], 'n1' => ['n' => 60, 'fail' => 60, 'b' => [0, 0, 0, 0, 0, 0]]]];
}
$rep = json_encode(['v' => 1, 'to' => time(), 'platform' => 'pc', 'client' => '0.1.13', 'dev' => 'inst-test-1',
    'nodes' => ['n0' => ['type' => 'vless', 'server' => 'n0.example.net', 'port' => 443, 'name' => 'Узел 0', 'via' => 'tcp|a.example.com'],
                'n1' => ['type' => 'vless', 'server' => 'n1.example.net', 'port' => 443, 'name' => 'Узел 1', 'via' => 'tcp|b.example.com']],
    'networks' => ['0123456789abcdef' => ['kind' => 'wifi', 'hours' => $hours]]], JSON_UNESCAPED_UNICODE);

$send = static function () use ($sp, $fields, $rep, $mw) {
    [$path, $ctx] = c1_request('AbCdEfGhIjKlMnOp', $sp, ['op' => 'rep'] + $fields);
    [$c, , $b] = t_http('POST', $mw . $path, ['content-type' => 'text/plain'], c1_report($ctx, $rep));
    return [$c, c1_open($ctx, $b)];
};
[$c, $r] = $send();
t_eq('отчёт: снаружи 200', $c, 200);
t_eq('отчёт: принят (204 внутри)', $r['st'] ?? null, 204);
[, $r] = $send();
t_eq('отчёт: повтор раньше 5 часов — 429', $r['st'] ?? null, 429);
t_eq('отчёт: оба сохранены сырыми', (int) $tt_pdo->query('SELECT COUNT(*) FROM rep_raw')->fetchColumn(), 2);
t_eq('отчёт: пинги за повтор не задвоились', (int) $tt_pdo->query("SELECT SUM(pn) FROM rep_hour")->fetchColumn(), 360);

t_done();
