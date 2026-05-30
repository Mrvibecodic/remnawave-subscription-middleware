<?php

function detect_client_format() {
    $ua = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');
    $q  = strtolower($_SERVER['QUERY_STRING'] ?? '');
    if (preg_match('/clash|mihomo|meta|stash|flclash|clashx|verge/', $ua) || strpos($q, 'clash') !== false) {
        return 'clash';
    }
    return 'base64';
}

function build_override_body($reason, $format = 'base64') {
    if ($format === 'clash') return build_clash_body($reason);

    $lines = [];
    foreach (get_blocked_remarks() as $r) {
        $lines[] = 'vless://00000000-0000-0000-0000-000000000000@0.0.0.0:1?security=none&type=tcp&encryption=none&flow=#'
                 . rawurlencode($r);
    }
    return base64_encode(implode("\n", $lines));
}

function yaml_q($s) {
    return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $s) . '"';
}

function clash_unique($names) {
    $seen = []; $out = [];
    foreach ($names as $n) {
        $k = $n; $i = 0;
        while (isset($seen[$k])) { $i++; $k = $n . str_repeat("\u{200B}", $i); }
        $seen[$k] = true; $out[] = $k;
    }
    return $out;
}

function build_clash_body($reason) {
    $names = clash_unique(get_blocked_remarks());

    $out = [];
    foreach ($names as $n) {
        $out[] = '  - {name: ' . yaml_q($n) . ', type: ss, server: 127.0.0.1, port: 1, cipher: aes-128-gcm, password: "1", udp: false}';
    }

    $group = 'Информация';
    $grp = "proxy-groups:\n  - name: " . $group . "\n    type: select\n    proxies:\n";
    foreach ($names as $n) $grp .= '      - ' . yaml_q($n) . "\n";

    return "proxies:\n" . implode("\n", $out) . "\n" . $grp . "rules:\n  - MATCH," . $group . "\n";
}
