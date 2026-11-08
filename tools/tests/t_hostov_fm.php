<?php
declare(strict_types=1);

require __DIR__ . '/_boot.php';

// Подмена масок finalmask (Xray 26): фрагментация TCP и шум UDP

$J = static fn($x) => json_encode($x, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$caps = static fn($sec, $tr, $proto) => hostov_caps($sec, $tr, $proto);
$TLS = $caps('reality', 'tcp', 'vless');

// ---- что можно менять у какого хоста ----
t_eq('возможности: VLESS REALITY по TCP', $caps('reality', 'tcp', 'vless'), ['fp' => true, 'frag' => true, 'hello' => true, 'noise' => false]);
t_eq('возможности: VLESS без TLS', $caps('none', 'tcp', 'vless'), ['fp' => false, 'frag' => true, 'hello' => false, 'noise' => false]);
t_eq('возможности: Hysteria2', $caps('quic', 'hysteria', 'hysteria2'), ['fp' => false, 'frag' => false, 'hello' => false, 'noise' => true]);
t_eq('возможности: mKCP', $caps('none', 'kcp', 'vless'), ['fp' => false, 'frag' => false, 'hello' => false, 'noise' => true]);
t_eq('возможности: XHTTP по HTTP/3', $caps('tls', 'xhttp3', 'vless'), ['fp' => false, 'frag' => false, 'hello' => false, 'noise' => true]);
t_eq('возможности: XHTTP по TCP', $caps('tls', 'xhttp', 'vless'), ['fp' => true, 'frag' => true, 'hello' => true, 'noise' => false]);
t_eq('возможности: Shadowsocks — и фрагментация, и шум', $caps('none', 'tcp', 'ss'), ['fp' => false, 'frag' => true, 'hello' => false, 'noise' => true]);
t_eq('возможности: строка без протокола (старая версия) — только отпечаток', $caps('reality', 'tcp', ''), ['fp' => true, 'frag' => false, 'hello' => false, 'noise' => false]);

// ---- проверка масок по правилам Xray ----
$mb = static function ($p, $v, $json = null, $c = null) use ($TLS) {
    $e = ''; $n = [];
    $m = hostov_mask_build($p, $v, is_array($json) ? json_encode($json) : $json, $c ?? $TLS, $e, $n);
    return [$m === null ? null : json_decode($m, true), $e, $n];
};
foreach (hostov_presets() as $p => $list) foreach ($list as $k => $pr) {
    [$m, $e] = $mb($p, $k, null, $p === 'frag' && !$pr['tls'] ? hostov_caps('none', 'tcp', 'vless') : null);
    t_ok("пресет {$p}/{$k} проходит свою же проверку", $m !== null && $m['settings'] === $pr['s'], $e);
}
[$m, $e] = $mb('frag', 'std', null, hostov_caps('none', 'tcp', 'vless'));
t_ok('ClientHello-пресет хосту без TLS не даётся', $m === null && strpos($e, 'ClientHello') !== false, $e);
$bad = [
    ['frag', ['type' => 'fragment', 'settings' => ['packets' => 'tlshello', 'length' => 0]], 'length'],
    ['frag', ['type' => 'fragment', 'settings' => ['packets' => 'tlshello']], 'length'],
    ['frag', ['type' => 'fragment', 'settings' => ['packets' => '0-3', 'length' => '10-20']], 'packets'],
    ['frag', ['type' => 'fragment', 'settings' => ['packets' => 3, 'length' => '10-20']], 'packets'],
    ['frag', ['type' => 'fragment', 'settings' => ['packets' => 'tlshello', 'length' => '10-20', 'interval' => 5]], 'interval'],
    ['frag', ['type' => 'fragment', 'settings' => ['length' => '-5', 'delay' => 1]], 'length'],
    ['frag', ['type' => 'fragment', 'settings' => ['length' => 1.5]], 'length'],
    ['frag', ['type' => 'fragment', 'settings' => ['lengths' => ['10-20', 0]]], 'lengths'],
    ['frag', ['type' => 'header-custom', 'settings' => ['clients' => []]], 'Серверные'],
    ['frag', ['type' => 'fragment', 'settings' => ['length' => 5], 'x' => 1], 'Лишнее'],
    ['frag', ['type' => 'fragment'], 'settings'],
    ['noise', ['type' => 'noise', 'settings' => ['noise' => []]], 'хотя бы один'],
    ['noise', ['type' => 'noise', 'settings' => ['noise' => [['rand' => '10-20', 'type' => 'str', 'packet' => 'x']]]], 'вместе'],
    ['noise', ['type' => 'noise', 'settings' => ['noise' => [['type' => 'hex', 'packet' => 'abc']]]], 'HEX'],
    ['noise', ['type' => 'noise', 'settings' => ['noise' => [['type' => 'base64', 'packet' => 'не base64']]]], 'Base64'],
    ['noise', ['type' => 'noise', 'settings' => ['noise' => [['type' => 'str', 'packet' => '']]]], 'пустой'],
    ['noise', ['type' => 'noise', 'settings' => ['noise' => [['delay' => 5]]]], 'пустой'],
    ['noise', ['type' => 'noise', 'settings' => ['noise' => [['rand' => 10, 'randRange' => '0-300']]]], 'randRange'],
    ['noise', ['type' => 'noise', 'settings' => ['noise' => [['type' => 'zip', 'packet' => 'x']]]], 'тип'],
    ['noise', ['type' => 'noise', 'settings' => ['script' => 'function HandleNoise(p,a) end', 'noise' => [['rand' => 5]]]], 'вместе'],
    ['noise', ['type' => 'noise', 'settings' => ['script' => "function HandleNoise(p,a)\n end"]], '.lua'],
    ['noise', ['type' => 'noise', 'settings' => ['script' => 'noise.txt']], '.lua'],
    ['noise', ['type' => 'noise', 'settings' => ['script' => '']], 'script'],
    ['noise', ['type' => 'noise', 'settings' => ['noise' => [['type' => 'exp', 'packet' => '<r 10> мусор']]]], 'exp'],
    ['noise', ['type' => 'noise', 'settings' => ['noise' => [['type' => 'exp', 'packet' => '<t 5>']]]], 'exp'],
    ['noise', ['type' => 'noise', 'settings' => ['noise' => [['type' => 'exp', 'packet' => '<r 20-10>']]]], 'exp'],
    ['noise', ['type' => 'noise', 'settings' => ['noise' => [['rand' => 10]], 'reset' => 'час']], 'reset'],
];
foreach ($bad as $i => [$p, $j, $want]) {
    [$m, $e] = $mb($p, 'own', $j);
    t_ok('отклонено ' . ($i + 1) . ': ' . $want, $m === null && mb_stripos($e, $want) !== false, $e . ' ← ' . json_encode($j, JSON_UNESCAPED_UNICODE));
}
[$m, $e] = $mb('frag', 'own', '{"type":"fragment",');
t_ok('не JSON — отклонён', $m === null && $e !== '', $e);
$good = [
    ['frag', ['type' => 'fragment', 'settings' => ['packets' => '1-3', 'length' => '1-1', 'delay' => 0, 'maxSplit' => '2-4']], []],
    ['frag', ['type' => 'fragment', 'settings' => ['packets' => 'TLSHello', 'length' => 100]], []],
    ['frag', ['type' => 'fragment', 'settings' => ['length' => '50-60']], []],
    ['frag', ['type' => 'fragment', 'settings' => ['packets' => 'tlshello', 'lengths' => ['0', '20-40'], 'delays' => [0, '5-10']]], ['lengths', 'delays', 'без length']],
    ['noise', ['type' => 'noise', 'settings' => ['reset' => 30, 'noise' => [['rand' => '10-20', 'randRange' => '65-90', 'delay' => '5-10'], ['type' => 'str', 'packet' => 'GET / HTTP/1.1'], ['type' => 'hex', 'packet' => '16030100'], ['type' => 'base64', 'packet' => 'AAECAw=='], ['packet' => [1, 2, 255]]]]], []],
    ['noise', ['type' => 'noise', 'settings' => ['noise' => [['type' => 'exp', 'packet' => '<b 0x16 03> <r 10-20> <rc 5> <rd 3> <t> <c> <n>']]]], ['type exp']],
    ['noise', ['type' => 'noise', 'settings' => ['reset' => '30-60', 'script' => 'noise.lua']], ['script']],
];
foreach ($good as $i => [$p, $j, $new]) {
    [$m, $e, $n] = $mb($p, 'own', $j);
    t_ok('принято ' . ($i + 1), $m === $j, $e);
    t_eq('принято ' . ($i + 1) . ': поля новых сборок', $n, $new);
}

// ---- строки-подмены ----
$row = static fn(int $id, string $p, string $v, $mask, array $x = []) => $x + ['id' => $id, 'param' => $p, 'val' => $v, 'mval' => $mask === null ? null : (is_string($mask) ? $mask : json_encode($mask, JSON_UNESCAPED_SLASHES)),
    'host_rmk' => 'DE', 'host_name' => 'DE', 'host_addr' => 'de1', 'host_apat' => 'de1', 'host_port' => 443, 'host_sec' => 'reality', 'host_tr' => 'tcp', 'host_proto' => 'vless', 'host_sni' => '', 'host_snipat' => ''];
$pr = hostov_presets();
$FR = ['type' => 'fragment', 'settings' => $pr['frag']['std']['s']];
$NZ = ['type' => 'noise', 'settings' => $pr['noise']['one']['s']];
$fmq = static function (string $ln) { preg_match('~[?&]fm=([^&#]*)~', $ln, $m); return isset($m[1]) ? json_decode(rawurldecode($m[1]), true) : null; };

// Ссылки: отпечаток и фрагментация одного хоста — обе в одной строке
$L = 'vless://u@de1:443?security=reality&type=tcp&fp=chrome&sni=de.example#DE';
[$b, $h] = hostov_apply($L, 'base64', [$row(1, 'fp', 'firefox', null), $row(2, 'frag', 'std', $FR)]);
t_ok('ссылка: отпечаток и фрагментация вместе', strpos($b, 'fp=firefox') !== false && $fmq($b) === ['tcp' => [$FR]], $b);
t_eq('ссылка: попадания', array_map(static fn($x) => [$x[0], $x[2], $x[3], $x[4], $x[5]], $h), [[1, 'chrome', 'firefox', 'fp', 0], [2, '', 'Обычная', 'frag', 0]]);
t_eq('ссылка: значения маски в попадании', $h[1][6], 'packets tlshello · length 100-200 · delay 10-20');
t_ok('ссылка: имя и остальные параметры как были', substr($b, -3) === '#DE' && strpos($b, 'sni=de.example') !== false);
// Маски панели: серверная остаётся, фрагментация панели заменяется, наша — первой
$pfm = ['tcp' => [['type' => 'header-custom', 'settings' => ['clients' => [['packet' => [1]]]]], ['type' => 'fragment', 'settings' => ['packets' => 'tlshello', 'length' => '50-100', 'delay' => 5]]], 'quicParams' => ['congestion' => 'bbr']];
$L2 = 'vless://u@de1:443?security=reality&type=tcp&fm=' . rawurlencode($J($pfm)) . '#DE';
[$b, $h] = hostov_apply($L2, 'base64', [$row(2, 'frag', 'std', $FR)]);
t_eq('ссылка: наша маска первой, серверная на месте, фрагментация панели убрана', $fmq($b), ['tcp' => [$FR, $pfm['tcp'][0]], 'quicParams' => ['congestion' => 'bbr']]);
t_eq('ссылка: «было» — значения фрагментации панели', $h[0][2], 'packets tlshello · length 50-100 · delay 5');
// Trojan: панель fm не кладёт — добавляем
[$b] = hostov_apply('trojan://p@de1:443?security=tls&type=ws&sni=x#DE', 'base64', [$row(2, 'frag', 'std', $FR, ['host_sec' => 'tls', 'host_tr' => 'ws', 'host_proto' => 'trojan'])]);
t_eq('trojan: fm добавлен', $fmq($b), ['tcp' => [$FR]]);
// Hysteria2: шум после salamander и перед udphop; obfs и пароль не трогаются
$hfm = ['udp' => [['type' => 'salamander', 'settings' => ['password' => 'x']], ['type' => 'udphop', 'settings' => ['ports' => '20000-30000']]]];
$HY = 'hysteria2://p@hy:443/?obfs=salamander&obfs-password=x&sni=hy.example&fm=' . rawurlencode($J($hfm)) . '#HY';
$hyrow = static fn(array $x = []) => $row(3, 'noise', 'one', $NZ, $x + ['host_rmk' => 'HY', 'host_name' => 'HY', 'host_addr' => 'hy', 'host_apat' => 'hy', 'host_sec' => 'quic', 'host_tr' => 'hysteria', 'host_proto' => 'hysteria2']);
[$b, $h] = hostov_apply($HY, 'base64', [$hyrow()]);
t_eq('hysteria2: шум между salamander и udphop', $fmq($b), ['udp' => [$hfm['udp'][0], $NZ, $hfm['udp'][1]]]);
t_ok('hysteria2: obfs на месте', strpos($b, 'obfs=salamander&obfs-password=x&sni=hy.example&fm=') !== false && count($h) === 1, $b);
[$b] = hostov_apply('hysteria2://p@hy:443/#HY', 'base64', [$hyrow()]);
t_eq('hysteria2 без параметров: fm добавлен после «/»', $b, 'hysteria2://p@hy:443/?fm=' . rawurlencode($J(['udp' => [$NZ]])) . '#HY');
// Отпечаток не уходит на Hysteria2 того же адреса и порта
[$b, $h] = hostov_apply($HY . "\n" . 'vless://u@hy:443?security=tls&type=tcp&fp=chrome#HY', 'base64', [$row(4, 'fp', 'safari', null, ['host_rmk' => 'HY', 'host_name' => 'HY', 'host_addr' => 'hy', 'host_apat' => 'hy', 'host_sec' => 'tls'])]);
t_ok('отпечаток TLS-хоста не задевает Hysteria2 на том же порту', count($h) === 1 && strpos(explode("\n", $b)[0], 'fp=') === false || strpos(explode("\n", $b)[0], 'fp=safari') === false, $b);
// Shadowsocks в ссылке — «не применима»
$SS = 'ss://' . base64_encode('chacha20-ietf-poly1305:pw') . '@ss1:8388#SS';
$ssrow = static fn(string $p, array $m) => $row(5, $p, $p === 'frag' ? 'rec' : 'one', $m, ['host_rmk' => 'SS', 'host_name' => 'SS', 'host_addr' => 'ss1', 'host_apat' => 'ss1', 'host_port' => 8388, 'host_sec' => 'none', 'host_tr' => 'tcp', 'host_proto' => 'ss']);
[$b, $h] = hostov_apply($SS, 'base64', [$ssrow('noise', $NZ)]);
t_ok('ss://: тело не тронуто, отметка «не применима»', $b === $SS && count($h) === 1 && $h[0][5] === 1 && $h[0][7] === 'ss://', json_encode($h));
// mKCP и XHTTP/3 — шум; фрагментация на них не попадает
$KC = 'vless://u@k1:4443?security=none&type=kcp#KCP';
[$b, $h] = hostov_apply($KC, 'base64', [$row(6, 'noise', 'one', $NZ, ['host_rmk' => 'KCP', 'host_name' => 'KCP', 'host_addr' => 'k1', 'host_apat' => 'k1', 'host_port' => 4443, 'host_sec' => 'none', 'host_tr' => 'kcp'])]);
t_eq('mKCP: шум в fm', $fmq($b), ['udp' => [$NZ]]);
$X3 = 'vless://u@x3:443?security=tls&type=xhttp&alpn=h3&sni=x3.example&path=%2Fx#X3';
$x3row = static fn(string $p, array $m, string $tr) => $row(7, $p, $p === 'frag' ? 'std' : 'one', $m, ['host_rmk' => 'X3', 'host_name' => 'X3', 'host_addr' => 'x3', 'host_apat' => 'x3', 'host_sec' => 'tls', 'host_tr' => $tr]);
[$b, $h] = hostov_apply($X3, 'base64', [$x3row('noise', $NZ, 'xhttp3')]);
t_eq('XHTTP/3: шум в fm', $fmq($b), ['udp' => [$NZ]]);
[$b, $h] = hostov_apply(str_replace('alpn=h3', 'alpn=h2', $X3), 'base64', [$x3row('noise', $NZ, 'xhttp3')]);
t_ok('XHTTP, у которого в панели уже не h3: шум не ставится', $fmq($b) === null && !$h, $b);
// XHTTP: загрузка отдельным соединением (extra.downloadSettings) получает ту же маску
$ex = ['xPaddingBytes' => '100-1000', 'downloadSettings' => ['address' => 'dl', 'port' => 443, 'network' => 'xhttp', 'security' => 'tls', 'tlsSettings' => ['serverName' => 'dl.example', 'fingerprint' => 'chrome'], 'xhttpSettings' => ['path' => '/d']]];
$XH = 'vless://u@de1:443?security=tls&type=xhttp&sni=x&extra=' . rawurlencode($J($ex)) . '#DE';
[$b] = hostov_apply($XH, 'base64', [$row(8, 'frag', 'std', $FR, ['host_sec' => 'tls', 'host_tr' => 'xhttp'])]);
preg_match('~extra=([^&#]*)~', $b, $m);
$ex2 = json_decode(rawurldecode($m[1]), true);
t_eq('XHTTP: фрагментация и в downloadSettings', [$fmq($b), $ex2['downloadSettings']['finalmask'] ?? null, $ex2['xPaddingBytes']], [['tcp' => [$FR]], ['tcp' => [$FR]], '100-1000']);
// Описание сервера для расширенных клиентов после имени не мешает
[$b] = hostov_apply($L . '?serverDescription=' . base64_encode('Описание'), 'base64', [$row(2, 'frag', 'std', $FR)]);
t_ok('описание сервера сохранено', substr($b, -strlen('#DE?serverDescription=' . base64_encode('Описание'))) === '#DE?serverDescription=' . base64_encode('Описание') && $fmq(substr($b, 0, strpos($b, '#'))) === ['tcp' => [$FR]], $b);
// base64-тело
[$b] = hostov_apply(base64_encode($L . "\n" . $SS), 'base64', [$row(2, 'frag', 'std', $FR)]);
$d = base64_decode($b);
t_ok('base64-тело: остаётся base64, ss не тронут', $d !== false && $fmq(explode("\n", $d)[0]) === ['tcp' => [$FR]] && explode("\n", $d)[1] === $SS, (string) $d);
// Битая fm панели — не трогаем
[$b, $h] = hostov_apply('vless://u@de1:443?security=reality&type=tcp&fm=%7Bnope#DE', 'base64', [$row(2, 'frag', 'std', $FR)]);
t_ok('битый fm панели не трогается', strpos($b, 'fm=%7Bnope') !== false && !$h, $b);

// ---- xray-json ----
$ob = static fn(string $proto, array $settings, array $ss, string $tag = 'proxy') => ['tag' => $tag, 'protocol' => $proto, 'settings' => $settings, 'streamSettings' => $ss];
$vn = static fn($a, $p = 443) => ['vnext' => [['address' => $a, 'port' => $p, 'users' => [['id' => 'u']]]]];
$cfgs = [
    ['remarks' => 'DE', 'outbounds' => [$ob('vless', $vn('de1'), ['network' => 'tcp', 'security' => 'reality', 'realitySettings' => ['serverName' => 'de.example', 'fingerprint' => 'chrome'], 'finalmask' => ['udp' => [['type' => 'salamander', 'settings' => ['password' => 'q']]]]]), ['tag' => 'direct', 'protocol' => 'freedom']]],
    ['remarks' => 'HY', 'outbounds' => [$ob('hysteria', ['version' => 2, 'address' => 'hy', 'port' => 443], ['network' => 'hysteria', 'security' => 'tls', 'tlsSettings' => ['serverName' => 'hy.example'], 'finalmask' => $hfm])]],
    ['remarks' => 'SS', 'outbounds' => [$ob('shadowsocks', ['servers' => [['address' => 'ss1', 'port' => 8388, 'method' => 'chacha20-ietf-poly1305', 'password' => 'pw']]], ['network' => 'tcp'])]],
    ['remarks' => 'X3', 'outbounds' => [$ob('vless', $vn('x3'), ['network' => 'xhttp', 'security' => 'tls', 'tlsSettings' => ['serverName' => 'x3.example', 'alpn' => ['h3']], 'xhttpSettings' => ['path' => '/x', 'extra' => ['downloadSettings' => ['address' => 'dl', 'port' => 443, 'network' => 'xhttp', 'security' => 'tls', 'tlsSettings' => ['alpn' => 'h3']]]]])]],
];
$xr = [$row(1, 'fp', 'firefox', null), $row(2, 'frag', 'std', $FR), $hyrow(), $ssrow('frag', ['type' => 'fragment', 'settings' => $pr['frag']['rec']['s']]), array_merge($ssrow('noise', $NZ), ['id' => 9]), $x3row('noise', $NZ, 'xhttp3')];
[$b, $h] = hostov_apply($J($cfgs), 'base64', $xr);
$xa = json_decode($b, true);
$s0 = $xa[0]['outbounds'][0]['streamSettings'];
t_eq('xray: отпечаток и фрагментация, UDP-маски панели целы', [$s0['realitySettings']['fingerprint'], $s0['finalmask']], ['firefox', ['udp' => [['type' => 'salamander', 'settings' => ['password' => 'q']]], 'tcp' => [$FR]]]);
t_eq('xray: hysteria — шум перед udphop', $xa[1]['outbounds'][0]['streamSettings']['finalmask']['udp'], [$hfm['udp'][0], $NZ, $hfm['udp'][1]]);
t_eq('xray: shadowsocks — и фрагментация, и шум', $xa[2]['outbounds'][0]['streamSettings']['finalmask'], ['tcp' => [['type' => 'fragment', 'settings' => $pr['frag']['rec']['s']]], 'udp' => [$NZ]]);
$s3 = $xa[3]['outbounds'][0]['streamSettings'];
t_eq('xray: XHTTP/3 — шум и в загрузке по h3', [$s3['finalmask'], $s3['xhttpSettings']['extra']['downloadSettings']['finalmask'] ?? null], [['udp' => [$NZ]], ['udp' => [$NZ]]]);
t_eq('xray: попадания по всем подменам', array_map(static fn($x) => $x[0], $h), [1, 2, 5, 3, 9, 7]);
t_ok('xray: прочее не тронуто', $xa[0]['outbounds'][1] === $cfgs[0]['outbounds'][1] && $xa[2]['outbounds'][0]['settings'] === $cfgs[2]['outbounds'][0]['settings']);
// Фрагментация не уходит на UDP-узел того же адреса (XHTTP/3), шум — на TCP-узел
[$b, $h] = hostov_apply($J([$cfgs[3]]), 'base64', [$x3row('frag', $FR, 'xhttp')]);
t_ok('xray: фрагментация на XHTTP/3 не ставится', !$h && strpos($b, 'fragment') === false, $b);
// Хост без streamSettings в выдаче (shadowsocks по умолчанию) — streamSettings создаётся
$c4 = [['remarks' => 'SS', 'outbounds' => [['tag' => 'proxy', 'protocol' => 'shadowsocks', 'settings' => ['servers' => [['address' => 'ss1', 'port' => 8388, 'method' => 'aes-128-gcm', 'password' => 'x']]]]]]];
[$b] = hostov_apply($J($c4), 'base64', [array_merge($ssrow('noise', $NZ), ['id' => 9])]);
t_eq('xray: shadowsocks без streamSettings', json_decode($b, true)[0]['outbounds'][0]['streamSettings'] ?? null, ['finalmask' => ['udp' => [$NZ]]]);

// ---- mihomo и sing-box: маски не попадают, отпечаток — как раньше ----
$yaml = "proxies:\n  - name: DE\n    type: vless\n    server: de1\n    port: 443\n    uuid: u\n    tls: true\n    client-fingerprint: chrome\n    reality-opts:\n      public-key: k\n    servername: de.example\n";
[$b, $h] = hostov_apply($yaml, 'clash', [$row(1, 'fp', 'firefox', null), $row(2, 'frag', 'std', $FR)]);
t_ok('mihomo: отпечаток подменён, фрагментация — «не применима»', strpos($b, 'client-fingerprint: firefox') !== false && strpos($b, 'fragment') === false && count($h) === 2 && $h[1][5] === 1 && $h[1][7] === 'mihomo', json_encode($h, JSON_UNESCAPED_UNICODE));
[$b, $h] = hostov_apply($yaml, 'clash', [$row(2, 'frag', 'std', $FR)]);
t_ok('mihomo: только маска — тело байт в байт', $b === $yaml && count($h) === 1 && $h[0][5] === 1);
$sb = $J(['outbounds' => [['type' => 'vless', 'tag' => 'DE', 'server' => 'de1', 'server_port' => 443, 'tls' => ['enabled' => true, 'server_name' => 'de.example', 'reality' => ['enabled' => true]]]], 'route' => ['final' => 'DE']]);
[$b, $h] = hostov_apply($sb, 'singbox', [$row(2, 'frag', 'std', $FR)]);
t_ok('sing-box: тело байт в байт, отметка «не применима»', $b === $sb && ($h[0][7] ?? '') === 'sing-box' && $h[0][5] === 1);

// ---- база: сохранение, статусы ----
t_ok('таблица', hostov_ensure());
$host = ['uuid' => 'h-de', 'name' => 'DE', 'rmk' => 'DE', 'addr' => 'de1', 'apat' => 'de1', 'port' => 443, 'sec' => 'reality', 'trk' => 'tcp', 'proto' => 'vless', 'sni' => 'de.example', 'snipat' => '', 'fp' => 'chrome', 'pm' => ['tcp' => ['header-custom'], 'udp' => []], 'pfrag' => 'packets tlshello · length 50-100 · delay 5'];
$err = '';
t_ok('сохранение фрагментации пресетом', hostov_save('AbCdEfGhIjKlMnOp', $host, 'frag', 'std', 0, 7, 'alert_test', $err), $err);
t_ok('сохранение отпечатка того же хоста', hostov_save('AbCdEfGhIjKlMnOp', $host, 'fp', 'firefox', 0, 7, 'alert_test', $err), $err);
t_ok('шум TCP-хосту не сохраняется', !hostov_save('AbCdEfGhIjKlMnOp', $host, 'noise', 'one', 0, 7, 'alert_test', $err) && strpos($err, 'UDP') !== false, $err);
t_ok('своя маска с ошибкой не сохраняется', !hostov_save('AbCdEfGhIjKlMnOp', ['uuid' => 'h-hy', 'sec' => 'quic', 'trk' => 'hysteria', 'proto' => 'hysteria2'] + $host, 'noise', 'own', 0, 7, 'alert_test', $err, '{"type":"noise","settings":{"noise":[]}}') && $err !== '', $err);
t_ok('своя маска сохраняется', hostov_save('AbCdEfGhIjKlMnOp', ['uuid' => 'h-hy', 'name' => 'HY', 'addr' => 'hy', 'apat' => 'hy', 'sec' => 'quic', 'trk' => 'hysteria', 'proto' => 'hysteria2'] + $host, 'noise', 'own', 0, 7, 'alert_test', $err, '{"type":"noise","settings":{"noise":[{"type":"str","packet":"hi","delay":"5-10"}]}}'), $err);
$l = hostov_list('AbCdEfGhIjKlMnOp');
$by = []; foreach ($l as $x) $by[$x['p'] . ':' . $x['h']] = $x;
t_eq('список: фрагментация', [$by['frag:h-de']['vn'], $by['frag:h-de']['mt'], $by['frag:h-de']['pv'], $by['frag:h-de']['pm'], $by['frag:h-de']['proto'], $by['frag:h-de']['st']],
    ['Обычная', 'packets tlshello · length 100-200 · delay 10-20', 'packets tlshello · length 50-100 · delay 5', ['tcp' => ['header-custom'], 'udp' => []], 'vless', 'wait']);
t_eq('список: свой шум', [$by['noise:h-hy']['vn'], $by['noise:h-hy']['m']], ['Свои', ['type' => 'noise', 'settings' => ['noise' => [['type' => 'str', 'packet' => 'hi', 'delay' => '5-10']]]]]);
t_eq('счётчик подмен', (int) setting('hostov_n', '0'), 3);
$rows = hostov_rows_for('AbCdEfGhIjKlMnOp', true);
[$b, $h] = hostov_apply($yaml, 'clash', $rows);
hostov_note($rows, $h, 'FlClash 0.8.85 · Android');
$l = hostov_list('AbCdEfGhIjKlMnOp'); $by = []; foreach ($l as $x) $by[$x['p'] . ':' . $x['h']] = $x;
t_eq('после выдачи mihomo: отпечаток ушёл, маски «не применимы», хоста HY не было — тоже «не применима»', [$by['fp:h-de']['st'], $by['frag:h-de']['st'], $by['frag:h-de']['naf'], $by['frag:h-de']['nap'], $by['noise:h-hy']['st']], ['ok', 'na', 'mihomo', 'FlClash 0.8.85 · Android', 'na']);
sleep(1);
[$b, $h] = hostov_apply($L, 'base64', $rows);
hostov_note($rows, $h, 'v2rayNG 1.10.5 · Android');
$l = hostov_list('AbCdEfGhIjKlMnOp'); $by = []; foreach ($l as $x) $by[$x['p'] . ':' . $x['h']] = $x;
t_eq('после выдачи v2rayNG: фрагментация ушла, хоста HY в ссылках не было', [$by['frag:h-de']['st'], $by['frag:h-de']['la'], $by['noise:h-hy']['st']], ['ok', 'v2rayNG 1.10.5 · Android', 'bad']);
// Правка: смена пресета сдвигает отметку, та же маска — нет
$id = $by['frag:h-de']['id']; $at = $by['frag:h-de']['at'];
sleep(1);
t_ok('правка: тот же пресет', hostov_set($id, 'std', 0, false, $err), $err);
t_eq('правка: тот же пресет не сдвигает отметку', hostov_list('AbCdEfGhIjKlMnOp')[0]['at'] ?? null, $at);
t_ok('правка: свои значения', hostov_set($id, 'own', 0, false, $err, '{"type":"fragment","settings":{"packets":"tlshello","length":"20-60","delay":"1-3"}}'), $err);
$x = array_values(array_filter(hostov_list('AbCdEfGhIjKlMnOp'), static fn($r) => $r['id'] === $id))[0];
t_ok('правка: маска сменилась, отметка сдвинулась', $x['mt'] === 'packets tlshello · length 20-60 · delay 1-3' && $x['at'] > $at && $x['st'] === 'wait', json_encode($x, JSON_UNESCAPED_UNICODE));
t_ok('правка: ошибка в маске — ничего не меняется', !hostov_set($id, 'own', 0, false, $err, '{"type":"fragment","settings":{"length":0}}') && strpos($err, 'length') !== false, $err);
// Испорченная маска в базе — «не годится», в выдачу не идёт, «удалить недействующие» её убирает
db()->prepare('UPDATE host_ovr SET mval = ? WHERE id = ?')->execute(['{"type":"noise","settings":{}}', $id]);
$x = array_values(array_filter(hostov_list('AbCdEfGhIjKlMnOp'), static fn($r) => $r['id'] === $id))[0];
t_eq('испорченная маска — inv', $x['st'], 'inv');
t_ok('испорченная маска в выдачу не идёт', !array_filter(hostov_rows_for('AbCdEfGhIjKlMnOp', true), static fn($r) => (int) $r['id'] === $id));
t_eq('удалить недействующие: испорченная и «хоста не было»', hostov_clear_off(), 2);
// Предупреждение о совместимости со старым ядром
$w = '';
t_ok('lengths без length — нужно ядро 26.6.22+, иначе конфиг не соберётся', hostov_save('AbCdEfGhIjKlMnOp', $host, 'frag', 'own', 0, 7, 'alert_test', $err, '{"type":"fragment","settings":{"packets":"tlshello","lengths":["20-40"]}}', $w) && strpos($w, '26.6.22') !== false && strpos($w, 'не соберётся') !== false, $err . ' / ' . $w);
t_ok('lengths при length — ядро старше возьмёт length', hostov_save('AbCdEfGhIjKlMnOp', $host, 'frag', 'own', 0, 7, 'alert_test', $err, '{"type":"fragment","settings":{"packets":"tlshello","length":"50-60","lengths":["20-40"]}}', $w) && strpos($w, 'возьмёт length') !== false, $w);
$hyh = ['uuid' => 'h-hy2', 'name' => 'HY', 'addr' => 'hy', 'apat' => 'hy', 'sec' => 'quic', 'trk' => 'hysteria', 'proto' => 'hysteria2'] + $host;
t_ok('шум exp — 26.9.30+', hostov_save('AbCdEfGhIjKlMnOp', $hyh, 'noise', 'own', 0, 7, 'alert_test', $err, '{"type":"noise","settings":{"noise":[{"type":"exp","packet":"<r 10>"}]}}', $w) && strpos($w, '26.9.30') !== false, $err . $w);
t_ok('скрипт шума — файл на устройстве, 26.10.21+', hostov_save('AbCdEfGhIjKlMnOp', $hyh, 'noise', 'own', 0, 7, 'alert_test', $err, json_encode(['type' => 'noise', 'settings' => ['script' => '/etc/xray/noise.lua']]), $w) && strpos($w, '26.10.21') !== false && strpos($w, 'устройстве') !== false, $err . $w);
t_eq('скрипт: значения строкой', array_values(array_filter(hostov_list('AbCdEfGhIjKlMnOp'), static fn($r) => $r['h'] === 'h-hy2'))[0]['mt'] ?? '', 'script /etc/xray/noise.lua');
t_ok('пресет — без предупреждения', hostov_save('AbCdEfGhIjKlMnOp', $host, 'frag', 'std', 0, 7, 'alert_test', $err, null, $w) && $w === '', $w);
t_ok('у type "str" без packet ядро не соберёт конфиг', $mb('noise', 'own', ['type' => 'noise', 'settings' => ['noise' => [['rand' => 5, 'type' => 'str']]]])[0] === null);

// Пометка в логе запросов укладывается в бюджет meta (500 символов на всю строку лога)
$lh = [];
for ($i = 0; $i < 15; $i++) $lh[] = ['🇩🇪 Очень длинное имя хоста номер ' . $i . ' {{DAYS_LEFT}}', 'packets tlshello · length 50-100 · delay 5', 'Обычная', 'frag', $i % 2, 'packets tlshello · length 100-200 · delay 10-20', 'sing-box'];
array_unshift($lh, ['DE', 'chrome', 'firefox', 'fp', 0, '', '']);
$lm = hostov_log_meta($lh);
$enc = json_encode(['as' => ['s' => 'off'], 'wg' => 0, 'hv' => $lm], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
// Строка лога: meta больше 500 символов не режется посреди JSON — лишнее убирается целиком
$big = ['er' => str_repeat('е', 120), 'as' => ['s' => 'on', 'su' => 'AbCdEfGhIjKlMnOp'], 'hv' => ['c' => 9, 'h' => array_fill(0, 9, ['Очень длинное имя хоста', 'x', 'y', 'frag', 0, str_repeat('m', 80), ''])]];
$mj = log_meta_json($big);
$md = json_decode((string) $mj, true);
t_ok('лог: длинная meta — целый JSON, подмены посчитаны, ошибка на месте', is_array($md) && mb_strlen($mj) <= 500 && ($md['hv']['c'] ?? 0) === 9 && ($md['hv']['h'] ?? null) === [] && isset($md['er']), (string) $mj);
t_eq('лог: короткая meta как есть', log_meta_json(['wg' => 1]), '{"wg":1}');
t_ok('лог: пометка влезает в meta', mb_strlen($enc) <= 500 && $lm['c'] === 16 && count($lm['h']) >= 3 && $lm['h'][0] === ['DE', 'chrome', 'firefox'], mb_strlen($enc) . ' ' . $enc);

t_done();
