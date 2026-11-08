<?php
declare(strict_types=1);

require __DIR__ . '/_boot.php';

$DE = '🇩🇪 Германия';
$row = static fn(int $id, string $tpl, string $name, string $addr, string $v, array $x = []) => $x + ['id' => $id, 'param' => 'fp', 'val' => $v, 'host_rmk' => $tpl, 'host_name' => $name, 'host_addr' => $addr, 'host_apat' => $addr, 'host_port' => 443, 'host_sec' => 'reality', 'host_tr' => 'tcp', 'host_sni' => '', 'host_snipat' => ''];
$L = static fn(string $n, string $a, int $p = 443, string $q = 'security=reality&type=tcp&fp=chrome') => "vless://u@{$a}:{$p}?{$q}#" . rawurlencode($n);
$hitn = static function (string $body, array $rows): array {
    [$b, $h] = hostov_apply($body, 'base64', $rows);
    return [array_map(static fn($x) => $x[1], $h), $b];
};

// Имя хоста с переменными панели: сегодня одно, завтра другое — подмена держится за шаблон
[$n] = $hitn($L($DE . ' · 29 дн.', 'de1.example.com') . "\n" . $L('🇳🇱 NL', 'nl1'), [$row(1, $DE . ' · {{DAYS_LEFT}} дн.', $DE . ' · 30 дн.', 'de1.example.com', 'firefox')]);
t_eq('сопоставление: шаблон с переменной', $n, [$DE . ' · 29 дн.']);
[$n] = $hitn($L('X', 'a1') . "\n" . $L('X ^~2~^', 'a2'), [$row(1, 'X', 'X', 'a2', 'safari')]);
t_eq('сопоставление: одинаковые имена различает адрес', $n, ['X ^~2~^']);
[$n] = $hitn($L('X', 'a1') . "\n" . $L('X ^~2~^', 'a1'), [$row(1, 'X', 'X ^~2~^', 'a1', 'safari')]);
t_eq('сопоставление: одинаковые имя и адрес различает суффикс панели', $n, ['X ^~2~^']);
[$n] = $hitn($L('base64:Zm9v', 'a1'), [$row(1, 'rwEncodeBase64:{{USERNAME}}', 'base64:YmFy', 'a1', 'safari')]);
t_eq('сопоставление: имя в base64 по шаблону не ищется', $n, []);
// Расширенным клиентам (свой список панели или регулярка в правилах ответа) панель дописывает описание после имени
[$n, $b] = $hitn($L('DE 1', 'de1') . '?serverDescription=' . base64_encode('Описание'), [$row(1, 'DE 1', 'DE 1', 'de1', 'safari')]);
t_ok('ссылки: описание сервера после имени не мешает', $n === ['DE 1'] && strpos($b, '&fp=safari#DE%201?serverDescription=') !== false && substr($b, -strlen('?serverDescription=' . base64_encode('Описание'))) === '?serverDescription=' . base64_encode('Описание'), $b);
[$n] = $hitn($L('A?B', 'de1'), [$row(1, 'A?B', 'A?B', 'de1', 'safari')]);
t_eq('ссылки: «?» внутри имени (панель кодирует %3F)', $n, ['A?B']);
// Подмена не уходит на чужие хосты
$TPL = '{{COUNTRY}} {{TRAFFIC_LEFT}}';
[$n] = $hitn($L('DE 5GB', 'de2') . "\n" . $L('NL 5GB', 'nl') . "\n" . $L('FI 5GB', 'fi'), [$row(1, $TPL, 'DE 7GB', 'de', 'safari')]);
t_eq('сопоставление: адрес хоста сменили в панели — подмены нет нигде', $n, []);
[$n] = $hitn($L('NL 5GB', 'nl'), [$row(1, $TPL, 'DE 7GB', 'de', 'safari')]);
t_eq('сопоставление: хост удалили — похожий по шаблону не задет', $n, []);
[$n] = $hitn($L('Другое имя', 'de'), [$row(1, 'DE', 'DE', 'de', 'safari')]);
t_eq('сопоставление: тот же адрес, другое имя — другой хост', $n, []);
[$n] = $hitn($L('DE', 'de', 8443), [$row(1, 'DE', 'DE', 'de', 'safari')]);
t_eq('сопоставление: другой порт — другой хост', $n, []);
[$n] = $hitn($L('DE', 'de', 443, 'security=tls&type=ws&fp=chrome'), [$row(1, 'DE', 'DE', 'de', 'safari')]);
t_eq('сопоставление: другой тип защиты — другой хост', $n, []);
// Адрес в панели — шаблон: список через запятую или «*»
[$n] = $hitn($L('DE', 'b.example.com'), [$row(1, 'DE', 'DE', 'a.example.com', 'safari', ['host_apat' => 'a.example.com, b.example.com,c.example.com'])]);
t_eq('сопоставление: адрес из списка панели', $n, ['DE']);
[$n] = $hitn($L('DE', 'o3x9m2q7wz.cdn.example.com'), [$row(1, 'DE', 'DE', 'p0p0p0p0p0.cdn.example.com', 'safari', ['host_apat' => '*.cdn.example.com'])]);
t_eq('сопоставление: адрес со звёздочкой', $n, ['DE']);
[$n] = $hitn($L('DE', 'evil.example.com'), [$row(1, 'DE', 'DE', 'x', 'safari', ['host_apat' => '*.cdn.example.com'])]);
t_eq('сопоставление: звёздочка не ловит чужой домен', $n, []);
// Два хоста на одном адресе и порту с одинаковым шаблоном имени: различает SNI
[$n] = $hitn($L('Same', 'n1', 443, 'security=reality&type=tcp&fp=chrome&sni=one.com') . "\n" . $L('Same ^~2~^', 'n1', 443, 'security=reality&type=tcp&fp=chrome&sni=two.com'), [$row(1, 'Same', 'Same', 'n1', 'safari', ['host_snipat' => 'two.com'])]);
t_eq('сопоставление: близнецы по адресу — различает SNI', $n, ['Same ^~2~^']);

// base64-ссылки: TLS и REALITY меняются, без TLS и Hysteria2 — нет
$rows = [$row(1, $DE, $DE, 'de1', 'firefox'), $row(2, 'NL', 'NL', 'nl1', '360', ['host_sec' => 'tls']), $row(3, 'Plain', 'Plain', 'p1', 'ios', ['host_sec' => '']), $row(4, 'HY', 'HY', 'h1', 'ios', ['host_sec' => ''])];
$links = 'vless://u@de1:443?security=reality&type=tcp&fp=chrome&pbk=k&sni=a.com#' . rawurlencode($DE) . "\n"
       . 'trojan://p@nl1:443?type=xhttp&sni=b&extra=' . rawurlencode('{"downloadSettings":{"address":"d","security":"tls","tlsSettings":{"fingerprint":"chrome"}}}') . '#NL' . "\n"
       . 'vless://u@p1:80?security=none&type=ws#Plain' . "\n"
       . 'hysteria2://p@h1:443?sni=h#HY';
[$b, $h] = hostov_apply(base64_encode($links), 'base64', $rows);
$out = explode("\n", (string) base64_decode($b, true));
t_ok('ссылки: REALITY получил fp', strpos($out[0], '&fp=firefox&') !== false && strpos($out[0], 'fp=chrome') === false, $out[0]);
t_ok('ссылки: trojan без fp получил fp', strpos($out[1], '&fp=360#NL') !== false, $out[1]);
t_ok('ссылки: загрузка XHTTP тоже', strpos(rawurldecode($out[1]), '"fingerprint":"360"') !== false, $out[1]);
t_eq('ссылки: без TLS и Hysteria2 не тронуты', [$out[2], $out[3]], ['vless://u@p1:80?security=none&type=ws#Plain', 'hysteria2://p@h1:443?sni=h#HY']);
t_eq('ссылки: попадания', array_map(static fn($x) => [$x[0], $x[2], $x[3]], $h), [[1, 'chrome', 'firefox'], [2, '', '360']]);
[$b2] = hostov_apply($links, 'base64', $rows);
t_ok('ссылки: тело без base64 остаётся без base64', strpos($b2, 'vless://') === 0 && strpos($b2, 'fp=firefox') !== false);
[$b3, $h3] = hostov_apply(base64_encode($links), 'base64', [$row(9, 'Нет такого', 'Нет такого', 'x', 'ios')]);
t_ok('ссылки: нет совпадений — тело байт в байт', $b3 === base64_encode($links) && $h3 === []);

// mihomo: блочный YAML панели, кавычки в имени, вставка ключа, загрузка XHTTP, поток в одну строку
$yaml = "mixed-port: 7890\nproxies:\n"
      . "  - name: {$DE}\n    type: vless\n    server: de1\n    port: 443\n    tls: true\n    client-fingerprint: chrome\n    reality-opts:\n      public-key: k\n"
      . "  - name: 'NL ''x'''\n    type: trojan\n    server: nl1\n    port: 443\n    network: xhttp\n    xhttp-opts:\n      download-settings:\n        client-fingerprint: chrome\n"
      . "  - name: Plain\n    type: vless\n    server: p1\n    port: 80\n    client-fingerprint: chrome\n"
      . "  - {name: \"Flow\", type: vless, server: f1, port: 443, tls: true}\n"
      . "proxy-groups:\n  - name: G\n    type: select\n    proxies:\n      - {$DE}\n";
$rows = [$row(1, $DE, $DE, 'de1', 'safari'), $row(2, "NL 'x'", "NL 'x'", 'nl1', '360', ['host_sec' => 'tls']), $row(3, 'Plain', 'Plain', 'p1', 'ios', ['host_sec' => '']), $row(4, 'Flow', 'Flow', 'f1', 'edge', ['host_sec' => 'tls'])];
[$y, $h] = hostov_apply($yaml, 'clash', $rows);
t_ok('mihomo: REALITY', strpos($y, "    tls: true\n    client-fingerprint: safari\n    reality-opts:") !== false, $y);
t_ok('mihomo: trojan — ключ вставлен, число в кавычках', strpos($y, "    type: trojan\n    client-fingerprint: \"360\"\n") !== false, $y);
t_ok('mihomo: загрузка XHTTP тоже', strpos($y, "      download-settings:\n        client-fingerprint: \"360\"\n") !== false, $y);
t_ok('mihomo: без TLS не тронут', strpos($y, "    port: 80\n    client-fingerprint: chrome\n") !== false, $y);
t_ok('mihomo: строка-поток', strpos($y, '{name: "Flow", type: vless, server: f1, port: 443, tls: true, client-fingerprint: edge}') !== false, $y);
t_ok('mihomo: группы и прочее не тронуты', strpos($y, "proxy-groups:\n  - name: G\n    type: select\n    proxies:\n      - {$DE}\n") !== false && strpos($y, "mixed-port: 7890\n") === 0);
t_eq('mihomo: попаданий', count($h), 3);

// mihomo: узлы во встроенном провайдере (payload) и список без отступа под ключом
$py = "proxies:\n- name: Top\n  type: vless\n  server: t1\n  port: 443\n  tls: true\n  reality-opts:\n    public-key: k\n"
    . "proxy-providers:\n  inl:\n    type: inline\n    payload:\n      - name: Prov\n        type: vless\n        server: p1\n        port: 443\n        tls: true\n        client-fingerprint: chrome\n        reality-opts:\n          public-key: k\n"
    . "      - {name: ProvFlow, type: trojan, server: p2, port: 443}\n    health-check:\n      enable: true\n"
    . "proxy-groups:\n  - name: Auto\n    type: url-test\n    use: [inl]\n    proxies:\n      - Top\n";
[$y, $h] = hostov_apply($py, 'clash', [$row(1, 'Top', 'Top', 't1', 'safari'), $row(2, 'Prov', 'Prov', 'p1', 'randomized'), $row(3, 'ProvFlow', 'ProvFlow', 'p2', 'edge', ['host_sec' => 'tls'])]);
t_eq('mihomo: узлы провайдера и списка без отступа', array_map(static fn($x) => $x[1], $h), ['Top', 'Prov', 'ProvFlow']);
t_ok('mihomo: payload правится на месте, health-check и группы не тронуты', strpos($y, "  type: vless\n  client-fingerprint: safari\n") !== false && strpos($y, "        client-fingerprint: randomized\n") !== false
    && strpos($y, "{name: ProvFlow, type: trojan, server: p2, port: 443, client-fingerprint: edge}") !== false && strpos($y, "    health-check:\n      enable: true\nproxy-groups:\n  - name: Auto\n    type: url-test\n    use: [inl]\n    proxies:\n      - Top\n") !== false, $y);

// sing-box: utls появляется только у узлов с TLS, лишних полей нет
$sb = ['log' => new stdClass(), 'outbounds' => [
    ['type' => 'vless', 'tag' => $DE, 'server' => 'de1', 'server_port' => 443, 'tls' => ['enabled' => true, 'reality' => ['enabled' => true], 'utls' => ['enabled' => true, 'fingerprint' => 'chrome']]],
    ['type' => 'trojan', 'tag' => 'NL', 'server' => 'nl1', 'server_port' => 443, 'tls' => ['enabled' => true, 'server_name' => 'b']],
    ['type' => 'vless', 'tag' => 'Plain', 'server' => 'p1', 'server_port' => 80],
    ['type' => 'selector', 'tag' => 'proxy', 'outbounds' => [$DE]],
], 'route' => ['rules' => []]];
$sbj = json_encode($sb, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
[$sj, $h] = hostov_apply($sbj, 'base64', [$row(1, $DE, $DE, 'de1', 'firefox'), $row(2, 'NL', 'NL', 'nl1', 'ios', ['host_sec' => 'tls']), $row(3, 'Plain', 'Plain', 'p1', 'ios', ['host_sec' => ''])]);
$so = json_decode($sj, true);
t_eq('sing-box: REALITY', $so['outbounds'][0]['tls']['utls'], ['enabled' => true, 'fingerprint' => 'firefox']);
t_eq('sing-box: TLS без utls', $so['outbounds'][1]['tls'], ['enabled' => true, 'server_name' => 'b', 'utls' => ['enabled' => true, 'fingerprint' => 'ios']]);
t_ok('sing-box: без TLS — tls не появился', !isset($so['outbounds'][2]['tls']));
t_ok('sing-box: пустой объект остался объектом', strpos($sj, '"log": {}') !== false, $sj);

// xray-json: свой исходящий proxy, исходящие других хостов по имени, загрузка XHTTP, {} не превращается в []
$xr = [
    ['remarks' => $DE, 'stats' => new stdClass(), 'outbounds' => [
        ['tag' => 'proxy', 'protocol' => 'vless', 'settings' => ['vnext' => [['address' => 'de1', 'port' => 443]]], 'streamSettings' => ['network' => 'xhttp', 'security' => 'reality', 'realitySettings' => ['fingerprint' => 'chrome'], 'xhttpSettings' => ['extra' => ['downloadSettings' => ['security' => 'tls', 'tlsSettings' => ['fingerprint' => 'chrome']]]]]],
        ['tag' => 'direct', 'protocol' => 'freedom']]],
    ['remarks' => 'Баланс', 'outbounds' => [
        ['tag' => 'proxy', 'protocol' => 'vless', 'settings' => ['vnext' => [['address' => 'z', 'port' => 443]]], 'streamSettings' => ['security' => 'tls', 'tlsSettings' => ['fingerprint' => 'chrome']]],
        ['tag' => 'NL', 'protocol' => 'trojan', 'settings' => ['servers' => [['address' => 'nl1', 'port' => 443]]], 'streamSettings' => ['security' => 'tls', 'tlsSettings' => ['serverName' => 'b']]]]],
];
$xj = json_encode($xr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
[$xo, $h] = hostov_apply($xj, 'base64', [$row(1, $DE, $DE, 'de1', 'firefox', ['host_tr' => 'xhttp']), $row(2, 'NL', 'NL', 'nl1', 'qq', ['host_sec' => 'tls'])]);
$xa = json_decode($xo, true);
t_eq('xray-json: свой исходящий', $xa[0]['outbounds'][0]['streamSettings']['realitySettings']['fingerprint'], 'firefox');
t_eq('xray-json: загрузка XHTTP', $xa[0]['outbounds'][0]['streamSettings']['xhttpSettings']['extra']['downloadSettings']['tlsSettings']['fingerprint'], 'firefox');
t_eq('xray-json: исходящий хоста внутри чужого профиля', $xa[1]['outbounds'][1]['streamSettings']['tlsSettings'], ['serverName' => 'b', 'fingerprint' => 'qq']);
t_eq('xray-json: чужой proxy не тронут', $xa[1]['outbounds'][0]['streamSettings']['tlsSettings']['fingerprint'], 'chrome');
t_ok('xray-json: stats остался объектом', strpos($xo, '"stats":{}') !== false);
[$x1] = hostov_apply(json_encode($xr[0], JSON_UNESCAPED_UNICODE), 'base64', [$row(1, $DE, $DE, 'de1', 'safari', ['host_tr' => 'xhttp'])]);
t_ok('xray-json: одиночный объект', is_array(json_decode($x1, true)) && !array_is_list(json_decode($x1, true)) && strpos($x1, '"fingerprint":"safari"') !== false, $x1);
// xray-json с инжектором панели: «proxy» может быть чужим хостом, вставленные — «proxy-N» или тег хоста; один хост — в нескольких конфигах
$ob = static fn(string $tag, string $addr, string $sec = 'reality') => ['tag' => $tag, 'protocol' => 'vless', 'settings' => ['vnext' => [['address' => $addr, 'port' => 443, 'users' => [['id' => 'u']]]]], 'streamSettings' => ['network' => 'tcp', 'security' => $sec, $sec === 'tls' ? 'tlsSettings' : 'realitySettings' => ['serverName' => 's', 'fingerprint' => 'chrome']]];
$xi = [
    ['remarks' => 'Авто', 'outbounds' => [$ob('proxy', 'y1'), $ob('proxy-2', 'z1'), ['tag' => 'direct', 'protocol' => 'freedom']], 'routing' => ['balancers' => [['tag' => 'b', 'selector' => ['proxy']]]], 'burstObservatory' => ['subjectSelector' => ['proxy']]],
    ['remarks' => 'Y', 'outbounds' => [$ob('proxy', 'y1')]],
    ['remarks' => 'Другой', 'outbounds' => [$ob('proxy', 'w1'), $ob('NL-tag', 'z1')]],
];
$xij = json_encode($xi, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
[$xo, $h] = hostov_apply($xij, 'base64', [$row(1, 'Авто', 'Авто', 'x1', 'firefox'), $row(2, 'Y', 'Y', 'y1', 'safari'), $row(3, 'Z', 'Z', 'z1', 'randomized')]);
$xa = json_decode($xo, true);
$fpx = static fn($c, $i) => $xa[$c]['outbounds'][$i]['streamSettings']['realitySettings']['fingerprint'];
t_eq('xray-инжектор: «proxy» чужого конфига получает подмену своего хоста, не хоста из remarks', [$fpx(0, 0), $fpx(0, 1), $fpx(1, 0), $fpx(2, 0), $fpx(2, 1)], ['safari', 'randomized', 'safari', 'chrome', 'randomized']);
t_eq('xray-инжектор: попадания', array_map(static fn($x) => $x[0], $h), [2, 2, 3, 3]);
t_ok('xray-инжектор: балансировщик и наблюдатель не тронуты', $xa[0]['routing'] === $xi[0]['routing'] && $xa[0]['burstObservatory'] === $xi[0]['burstObservatory']);

// Статусы подмены
$now = time();
$sr = static fn(array $x) => $x + ['param' => 'fp', 'val' => 'firefox', 'host_sec' => 'reality', 'till' => null, 'vat' => $now - 1000, 'last_hit' => null, 'last_miss' => null];
t_eq('статус: ещё не уходила', hostov_state($sr([])), 'wait');
t_eq('статус: ушла', hostov_state($sr(['last_hit' => $now - 10])), 'ok');
t_eq('статус: ушла, но в последней выдаче другого приложения хоста не было', hostov_state($sr(['last_hit' => $now - 100, 'last_miss' => $now - 10])), 'part');
t_eq('статус: неделю не уходила, хоста нет', hostov_state($sr(['vat' => $now - 9 * 86400, 'last_hit' => $now - 8 * 86400, 'last_miss' => $now - 10])), 'bad');
t_eq('статус: ни разу не ушла, хоста нет', hostov_state($sr(['last_miss' => $now - 10])), 'bad');
t_eq('статус: после смены значения старые отметки не считаются', hostov_state($sr(['vat' => $now - 5, 'last_hit' => $now - 100, 'last_miss' => $now - 50])), 'wait');
t_eq('статус: значение, которое не работает с REALITY', hostov_state($sr(['val' => 'ios', 'last_hit' => $now - 10])), 'inv');
t_eq('статус: то же значение у TLS-хоста', hostov_state($sr(['val' => 'ios', 'host_sec' => 'tls', 'last_hit' => $now - 10])), 'ok');
// С REALITY — только chrome, firefox, safari, randomized; у TLS — весь список панели
t_eq('значения: REALITY', hostov_fp_values('reality'), ['chrome', 'firefox', 'safari', 'randomized']);
[$b, $h] = hostov_apply($L('DE', 'de1') . "\n" . $L('NL', 'nl1', 443, 'security=tls&type=ws&fp=chrome'), 'base64', [$row(1, 'DE', 'DE', 'de1', 'ios'), $row(2, 'NL', 'NL', 'nl1', 'ios', ['host_sec' => 'tls'])]);
t_eq('ios: REALITY не трогается, TLS получает', array_map(static fn($x) => $x[1], $h), ['NL']);
t_eq('статус: срок вышел', hostov_state($sr(['till' => $now - 1, 'last_hit' => $now - 10])), 'exp');

[$bad, $hb] = hostov_apply('{"outbounds":[', 'base64', [$row(1, $DE, $DE, 'de1', 'ios')]);
t_ok('битое тело — как было', $bad === '{"outbounds":[' && $hb === []);
[$nv] = hostov_apply($links, 'base64', [['id' => 1, 'param' => 'fp', 'val' => 'chrome_psk', 'host_rmk' => $DE, 'host_name' => $DE, 'host_addr' => 'de1', 'host_port' => 443]]);
t_ok('значение не из списка панели не применяется', $nv === $links);

// База: число подмен в настройках, срок, правка значения, перевыпуск подписки, удаление
t_ok('без подмен — флаг выключен', !hostov_any());
$host = ['uuid' => 'h-de', 'name' => $DE, 'rmk' => $DE, 'addr' => 'de1', 'apat' => 'de1', 'port' => 443, 'sec' => 'reality', 'tr' => 'TCP', 'trk' => 'tcp', 'fp' => 'chrome'];
t_ok('сохранение', hostov_save('AbCdEfGhIjKlMnOp', $host, 'fp', 'firefox', 0, 7, 'alert_test', $err), $err);
t_ok('флаг включился', hostov_any());
t_ok('REALITY: ios отклонён', !hostov_save('AbCdEfGhIjKlMnOp', $host, 'fp', 'ios', 0, 7, 'alert_test', $err) && strpos($err, 'REALITY') !== false, $err);
t_ok('TLS: ios принят', hostov_save('AbCdEfGhIjKlMnOp', ['uuid' => 'h-tls', 'sec' => 'tls'] + $host, 'fp', 'ios', 0, 7, 'alert_test', $err), $err);
hostov_del(hostov_list('AbCdEfGhIjKlMnOp')[1]['id']);
t_ok('недопустимое значение отклонено', !hostov_save('AbCdEfGhIjKlMnOp', $host, 'fp', 'hellochrome_131', 0, 7, 'alert_test', $err));
t_ok('сохранение второго хоста с прошедшим сроком', hostov_save('AbCdEfGhIjKlMnOp', ['uuid' => 'h-nl'] + $host, 'fp', 'safari', time() - 5, 7, 'alert_test', $err), $err);
$l = hostov_list('AbCdEfGhIjKlMnOp');
t_eq('список пользователя', array_column($l, 'v'), ['firefox', 'safari']);
t_eq('в выдачу идёт только действующая', array_column(hostov_rows_for('AbCdEfGhIjKlMnOp'), 'host_uuid'), ['h-de']);
t_ok('REALITY: смена на qq отклонена', !hostov_set($l[0]['id'], 'qq', 0, false, $err) && hostov_list('AbCdEfGhIjKlMnOp')[0]['v'] === 'firefox', $err);
db()->prepare("UPDATE host_ovr SET val = 'ios' WHERE id = ?")->execute([$l[0]['id']]);
t_eq('REALITY + ios из старой строки: в списке «не годится»', hostov_list('AbCdEfGhIjKlMnOp')[0]['st'], 'inv');
db()->prepare("UPDATE host_ovr SET val = 'firefox' WHERE id = ?")->execute([$l[0]['id']]);
$at0 = $l[0]['at'];
db()->exec('UPDATE host_ovr SET vat = vat - 100');
hostov_set($l[0]['id'], 'safari', 0, false, $err);
$l = hostov_list('AbCdEfGhIjKlMnOp');
t_ok('смена значения сдвигает отметку «ещё не уходила»', $l[0]['v'] === 'safari' && $l[0]['at'] >= $at0);
hostov_note(hostov_rows_for('AbCdEfGhIjKlMnOp') ?: [['id' => $l[0]['id']]], [[$l[0]['id'], $DE, 'chrome', 'safari']], 'Happ 3.6 · iOS');
$l = hostov_list('AbCdEfGhIjKlMnOp');
t_ok('отметка выдачи', $l[0]['lh'] !== null && $l[0]['la'] === 'Happ 3.6 · iOS');
hostov_rekey(7, 'NewShortUuid0001');
t_eq('перевыпуск: подмены перешли на новый shortUuid', count(hostov_list('NewShortUuid0001')), 2);
$l = hostov_list('NewShortUuid0001');
db()->prepare('UPDATE host_ovr SET last_miss = ?, miss_app = ? WHERE id = ?')->execute([time() + 5, 'FlClash X · Windows', $l[0]['id']]);
t_eq('частичная подмена в списке', [hostov_list('NewShortUuid0001')[0]['st'], hostov_list('NewShortUuid0001')[0]['ma']], ['part', 'FlClash X · Windows']);
t_eq('недействующие удаляются, частичная остаётся', hostov_clear_off(), 1);
t_eq('осталась частичная', array_column(hostov_list('NewShortUuid0001'), 'st'), ['part']);
hostov_save('NewShortUuid0001', ['uuid' => 'h-old'] + $host, 'fp', 'safari', time() - 8 * 86400, 7, 'alert_test', $err);
hostov_save('NewShortUuid0001', ['uuid' => 'h-ex'] + $host, 'fp', 'safari', time() - 60, 7, 'alert_test', $err);
hostov_prune();
t_eq('планировщик: просроченные больше недели удалены, свежая просроченная видна', array_column(hostov_list('NewShortUuid0001'), 'h'), ['h-de', 'h-ex']);
t_eq('счётчик — только действующие', (int) setting('hostov_n', '0'), 1);
t_eq('карточка: перевыпуск без вебхука переносит подмены при открытии', [count(ua_mw('Rekeyed000000001', 'alert_test', 7)['hv']), count(hostov_list('NewShortUuid0001'))], [2, 0]);
hostov_rekey(7, 'NewShortUuid0001');
hostov_purge_user('NewShortUuid0001');
t_ok('удаление пользователя: подмен нет, флаг выключен', hostov_list() === [] && !hostov_any());

// Выдача подписки через мок-панель
$state = $tt_root . '/data/mock_state.json';
$A = 'AbCdEfGhIjKlMnOp'; $B = 'QrStUvWxYz012345';
$linksA = 'vless://u@de1:443?security=reality&type=tcp&fp=chrome&pbk=k#' . rawurlencode($DE . ' · 30 дн.');
$users = [
    $A => ['status' => 'ACTIVE', 'bodies' => ['base64' => base64_encode($linksA), 'clash' => str_replace("  - name: {$DE}\n", "  - name: {$DE} · 29 дн.\n", $yaml), 'singbox' => $sbj, 'xray' => $xj]],
    $B => ['status' => 'ACTIVE', 'bodies' => ['base64' => base64_encode($linksA)]],
];
file_put_contents($state, json_encode(['users' => $users, 'version' => '3.4.8',
    'hosts' => [['remark' => $DE . ' · {{DAYS_LEFT}} дн.', 'address' => 'de1'], ['remark' => 'HY', 'address' => 'h1'], ['remark' => 'Чужой', 'address' => 'zz']],
    'ckeys' => [
        '11' => [$linksA, 'hysteria2://p@h1:443?sni=h#HY', 'vless://00000000-0000-0000-0000-000000000000@0.0.0.0:1?type=tcp#Off', 'vless://u@q:443?security=tls#Нет в панели'],
        '12' => '403',
    ]], JSON_UNESCAPED_UNICODE));
$panel = t_serve($tt_root . '/tools', $tt_root . '/tools/mock_panel.php', ['MOCK_STATE' => $state]);
t_settings(['sub_source' => 'panel', 'remnawave_url' => $panel, 'remnawave_api_key' => 'test-token', 'geoip_auto' => '0', 'apisub_accept' => '1']);
set_setting('update_state', json_encode(['checked_at' => time()]));
$mw = t_serve($tt_root, $tt_root . '/tools/tests/_router.php', [], ['opcache.enable' => '0']);

[$c, $hh, $body] = t_http('GET', $mw . '/' . $A, ['User-Agent' => 'v2rayNG/1.10']);
t_ok('без подмен: тело панели байт в байт и etag на месте', $c === 200 && $body === base64_encode($linksA) && isset($hh['etag']));
[$c] = t_http('GET', $mw . '/' . $A, ['User-Agent' => 'v2rayNG/1.10', 'If-None-Match' => $hh['etag']]);
t_eq('без подмен: 304 от панели проходит как раньше', $c, 304);

$jar = t_admin_login($mw);
[, , $page] = t_http('GET', $mw . '/admin/index.php?tab=users', [], null, $jar);
preg_match('~"csrf":"([0-9a-f]+)"~', $page, $cm);
$csrf = $cm[1] ?? '';
[, , $j] = t_http('GET', $mw . '/admin/index.php?ajax=hv_hosts&uid=11', [], null, $jar);
$j = json_decode($j, true);
t_ok('хосты пользователя из панели', ($j['ok'] ?? false) && count($j['hosts']) === 2 && $j['uid'] === 11, json_encode($j, JSON_UNESCAPED_UNICODE));
t_eq('хосты: имя для пользователя, шаблон и uuid из панели', [$j['hosts'][0]['name'] ?? '', $j['hosts'][0]['rmk'] ?? '', $j['hosts'][0]['uuid'] ?? ''], [$DE . ' · 30 дн.', $DE . ' · {{DAYS_LEFT}} дн.', md5($DE . ' · {{DAYS_LEFT}} дн.')]);
t_eq('хосты: Hysteria2 без отпечатка, заглушка 0.0.0.0 и ключи без хоста скрыты', [$j['hosts'][0]['fp'] ?? '', $j['hosts'][0]['sec'] ?? '', $j['hosts'][1]['no'] ?? ''], ['chrome', 'reality', 'У Hysteria2 отпечатка TLS нет']);
[, , $j2] = t_http('GET', $mw . '/admin/index.php?ajax=hv_hosts&uid=12', [], null, $jar);
t_eq('хосты: нет права у токена', json_decode($j2, true)['why'] ?? '', 'right');
$post = static function (string $a, array $f) use ($mw, $jar, $csrf) {
    [, , $r] = t_http('POST', $mw . '/admin/index.php?ajax=' . $a, ['content-type' => 'application/x-www-form-urlencoded'], http_build_query(['csrf' => $csrf] + $f), $jar);
    return json_decode($r, true);
};
$r = $post('hv_save', ['su' => $A, 'uid' => 11, 'un' => 'alert_test', 'host' => json_encode($j['hosts'][0], JSON_UNESCAPED_UNICODE), 'v' => 'firefox', 'till' => 0]);
t_ok('ajax: подмена сохранена', ($r['ok'] ?? false) && ($r['hv'][0]['v'] ?? '') === 'firefox', json_encode($r, JSON_UNESCAPED_UNICODE));
[$c] = t_http('POST', $mw . '/admin/index.php?ajax=hv_del', ['content-type' => 'application/x-www-form-urlencoded'], http_build_query(['csrf' => 'bad', 'id' => 1]), $jar);
t_eq('ajax: без CSRF не пишет', $c, 400);

[$c, $hh, $body] = t_http('GET', $mw . '/' . $A, ['User-Agent' => 'v2rayNG/1.10', 'If-None-Match' => $hh['etag'] ?? '']);
t_ok('подмена: условный запрос не даёт 304, приходит тело с подменой', $c === 200 && strpos((string) base64_decode($body), 'fp=firefox') !== false, $c . ' ' . $body);
t_ok('подмена: etag панели не отдаётся', !isset($hh['etag']));
[$c, , $body] = t_http('GET', $mw . '/' . $A, ['User-Agent' => 'clash-verge/2.0 mihomo']);
t_ok('подмена: mihomo', $c === 200 && strpos($body, "    tls: true\n    client-fingerprint: firefox\n    reality-opts:") !== false, substr($body, 0, 300));
[$c, $hh2, $body] = t_http('GET', $mw . '/' . $B, ['User-Agent' => 'v2rayNG/1.10']);
t_ok('другой пользователь: без изменений, etag на месте', $c === 200 && $body === base64_encode($linksA) && isset($hh2['etag']));

$lr = db()->query("SELECT meta FROM request_log WHERE short_uuid = '{$A}' ORDER BY id DESC LIMIT 10")->fetchAll(PDO::FETCH_COLUMN);
$hvm = null;
foreach ($lr as $mj) { $mm = json_decode((string) $mj, true); if (!empty($mm['hv'])) { $hvm = $mm['hv']; break; } }
t_ok('лог запросов: пометка подмены', is_array($hvm) && $hvm['c'] === 1 && $hvm['h'][0][2] === 'firefox', json_encode($lr, JSON_UNESCAPED_UNICODE));
$ok = false;
for ($i = 0; $i < 20 && !$ok; $i++) { $l = hostov_list($A); $ok = ($l[0]['lh'] ?? null) !== null; if (!$ok) usleep(100000); }
t_ok('отметка «ушла» с приложением', $ok && $l[0]['la'] !== '', json_encode($l, JSON_UNESCAPED_UNICODE));

$r = $post('hv_set', ['id' => $l[0]['id'], 'till' => time() - 1]);
[$c, , $body] = t_http('GET', $mw . '/' . $A, ['User-Agent' => 'v2rayNG/1.10']);
t_ok('срок вышел — подмены нет', ($r['ok'] ?? false) && $body === base64_encode($linksA));
$r = $post('hv_clear', []);
t_ok('ajax: недействующие удалены', ($r['ok'] ?? false) && $r['n'] === 1 && $r['all'] === []);
t_ok('после удаления всех подмен флаг выключен', !hostov_any());

// Вторая подписка: её серверы получают подмены её собственного пользователя, подмены основного их не трогают
set_setting('addsub_enabled', '1');
addsub_map_set($A, $panel . '/api/sub/' . $B);
$st = json_decode((string) file_get_contents($state), true);
$st['users'][$B]['bodies']['base64'] = base64_encode('vless://u@b1:443?security=tls&type=ws&fp=chrome#' . rawurlencode('Сервер B'));
file_put_contents($state, json_encode($st, JSON_UNESCAPED_UNICODE));
hostov_save($B, ['uuid' => 'h-b', 'name' => 'Сервер B', 'rmk' => 'Сервер B', 'addr' => 'b1', 'apat' => 'b1', 'port' => 443, 'sec' => 'tls', 'tr' => 'WS', 'trk' => 'ws', 'fp' => 'chrome'], 'fp', 'ios', 0, 12, 'second', $err);
hostov_save($A, ['uuid' => 'h-b', 'name' => 'Сервер B', 'rmk' => 'Сервер B', 'addr' => 'b1', 'apat' => 'b1', 'port' => 443, 'sec' => 'tls', 'tr' => 'WS', 'trk' => 'ws', 'fp' => 'chrome'], 'fp', 'edge', 0, 11, 'alert_test', $err);
[$c, , $body] = t_http('GET', $mw . '/' . $A, ['User-Agent' => 'v2rayNG/1.10']);
$dec = (string) base64_decode($body);
t_ok('вторая подписка: свой сервер основного — как в панели, сервер второй — с её подменой', $c === 200 && substr_count($dec, 'fp=chrome') === 1 && substr_count($dec, 'fp=ios') === 1, $dec);
hostov_purge_user($B);
hostov_purge_user($A);

t_done();
