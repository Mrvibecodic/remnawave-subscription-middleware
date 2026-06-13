<?php

function vless_struct_net($net) {
    return in_array($net, ['tcp', 'ws', 'grpc', 'http', 'httpupgrade'], true);
}

function vless_clients($p) {
    $out = ['base64 (v2rayNG, Streisand, Happ)'];
    if (vless_struct_net($p['net'] ?? '')) {
        $out[] = 'Mihomo / Clash.Meta';
        $out[] = 'sing-box (Hiddify и др.)';
        $out[] = 'Xray JSON';
    }
    return $out;
}

function vless_summary($p) {
    if (!is_array($p)) return '';
    $sec = ($p['security'] ?? '') === 'reality' ? 'Reality' : (($p['security'] ?? '') === 'tls' ? 'TLS' : 'no-TLS');
    return 'VLESS ' . strtoupper((string) ($p['net'] ?? 'tcp')) . ' + ' . $sec;
}

function vless_parse($raw) {
    $res = [
        'ok' => false, 'type' => 'vless', 'version' => '',
        'clients' => [], 'warnings' => [],
        'uuid' => '', 'host' => '', 'port' => 0,
        'net' => 'tcp', 'security' => 'none', 'flow' => '', 'encryption' => 'none',
        'sni' => '', 'alpn' => [], 'fp' => '', 'pbk' => '', 'sid' => '', 'spx' => '',
        'path' => '', 'hostHeader' => '', 'serviceName' => '', 'grpcMode' => '',
        'headerType' => '', 'allowInsecure' => false, 'remark' => '',
    ];
    $raw = trim((string) $raw);
    if (stripos($raw, 'vless://') !== 0) {
        $res['warnings'][] = 'Не похоже на vless:// ссылку.';
        return $res;
    }
    $s = substr($raw, 8);
    $hash = strpos($s, '#');
    if ($hash !== false) { $res['remark'] = rawurldecode(substr($s, $hash + 1)); $s = substr($s, 0, $hash); }
    $query = '';
    $qp = strpos($s, '?');
    if ($qp !== false) { $query = substr($s, $qp + 1); $s = substr($s, 0, $qp); }
    $at = strrpos($s, '@');
    if ($at === false) { $res['warnings'][] = 'Нет UUID@адрес в ссылке.'; return $res; }
    $res['uuid'] = rawurldecode(substr($s, 0, $at));
    $hp = substr($s, $at + 1);
    if (isset($hp[0]) && $hp[0] === '[') {
        $rb = strpos($hp, ']');
        if ($rb === false) { $res['warnings'][] = 'Битый IPv6-адрес.'; return $res; }
        $res['host'] = substr($hp, 1, $rb - 1);
        $tail = substr($hp, $rb + 1);
        if (isset($tail[0]) && $tail[0] === ':') $res['port'] = (int) substr($tail, 1);
    } else {
        $cp = strrpos($hp, ':');
        if ($cp === false) { $res['host'] = $hp; }
        else { $res['host'] = substr($hp, 0, $cp); $res['port'] = (int) substr($hp, $cp + 1); }
    }
    $p = [];
    parse_str($query, $p);
    $g = function ($k, $d = '') use ($p) { return (isset($p[$k]) && !is_array($p[$k])) ? (string) $p[$k] : $d; };

    $net = strtolower($g('type', 'tcp'));
    if ($net === '') $net = 'tcp';
    if ($net === 'h2') $net = 'http';
    $res['net'] = $net;

    $sec = strtolower($g('security', 'none'));
    if ($sec === '') $sec = 'none';
    $res['security'] = $sec;

    $res['flow'] = $g('flow', '');
    $enc = $g('encryption', 'none');
    $res['encryption'] = $enc !== '' ? $enc : 'none';
    $res['sni'] = $g('sni', $g('serverName', ''));
    $alpn = $g('alpn', '');
    if ($alpn !== '') $res['alpn'] = array_values(array_filter(array_map('trim', explode(',', $alpn)), fn($x) => $x !== ''));
    $res['fp'] = $g('fp', '');
    $res['pbk'] = $g('pbk', '');
    $res['sid'] = $g('sid', '');
    $res['spx'] = $g('spx', '');
    $res['headerType'] = $g('headerType', '');
    $ai = strtolower($g('allowInsecure', ''));
    $res['allowInsecure'] = ($ai === '1' || $ai === 'true');
    $res['path'] = $g('path', '');
    $res['hostHeader'] = $g('host', '');
    $res['serviceName'] = $g('serviceName', '');
    $res['grpcMode'] = strtolower($g('mode', ''));
    if ($res['security'] === 'reality' && $res['fp'] === '') $res['fp'] = 'chrome';

    if ($res['uuid'] === '') $res['warnings'][] = 'В ссылке нет UUID.';
    if ($res['host'] === '') $res['warnings'][] = 'В ссылке нет адреса сервера.';
    if ((int) $res['port'] <= 0) $res['warnings'][] = 'В ссылке нет порта.';
    if ($res['security'] === 'reality' && $res['pbk'] === '') $res['warnings'][] = 'Reality без pbk (publicKey) — узел нерабочий.';

    $uuid_ok = (bool) preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $res['uuid']);
    if ($res['uuid'] !== '' && !$uuid_ok) $res['warnings'][] = 'UUID неверного формата.';
    $base_ok = ($uuid_ok && $res['host'] !== '' && (int) $res['port'] > 0);
    $reality_ok = ($res['security'] !== 'reality' || $res['pbk'] !== '');
    $res['ok'] = $base_ok && $reality_ok;

    if ($res['ok']) {
        $res['clients'] = vless_clients($res);
        if (!vless_struct_net($res['net'])) {
            $res['warnings'][] = 'Транспорт «' . $res['net'] . '» в YAML/JSON-ядрах пока не собирается — уйдёт только в base64-подписку как ссылка.';
        }
    }
    return $res;
}

function vless_relabel_uri($raw, $name) {
    $raw = trim((string) $raw);
    $hash = strpos($raw, '#');
    if ($hash !== false) $raw = substr($raw, 0, $hash);
    if ($raw === '') return '';
    return $raw . '#' . rawurlencode((string) $name);
}

function vless_clash_net($net) {
    if ($net === 'http') return 'h2';
    if ($net === 'httpupgrade') return 'ws';
    if (in_array($net, ['ws', 'grpc', 'xhttp'], true)) return $net;
    return 'tcp';
}

function vless_to_clash($p, $name) {
    if (!is_array($p) || empty($p['ok']) || !vless_struct_net($p['net'] ?? '')) return '';
    $L = [];
    $L[] = '  - name: ' . yaml_q($name);
    $L[] = '    type: vless';
    $L[] = '    server: ' . $p['host'];
    $L[] = '    port: ' . (int) $p['port'];
    $L[] = '    uuid: ' . yaml_q($p['uuid']);
    $L[] = '    udp: true';
    if ($p['flow'] !== '' && $p['net'] === 'tcp') $L[] = '    flow: ' . $p['flow'];
    if (in_array($p['security'], ['tls', 'reality'], true)) {
        $L[] = '    tls: true';
        if ($p['sni'] !== '') $L[] = '    servername: ' . yaml_q($p['sni']);
        if ($p['alpn']) $L[] = '    alpn: [' . implode(', ', array_map('yaml_q', $p['alpn'])) . ']';
        if ($p['fp'] !== '') $L[] = '    client-fingerprint: ' . yaml_q($p['fp']);
        if ($p['allowInsecure']) $L[] = '    skip-cert-verify: true';
        if ($p['security'] === 'reality') {
            $L[] = '    reality-opts:';
            $L[] = '      public-key: ' . yaml_q($p['pbk']);
            if ($p['sid'] !== '') $L[] = '      short-id: ' . yaml_q($p['sid']);
        }
    }
    $cn = vless_clash_net($p['net']);
    $L[] = '    network: ' . $cn;
    if ($cn === 'ws') {
        $L[] = '    ws-opts:';
        if ($p['path'] !== '') $L[] = '      path: ' . yaml_q($p['path']);
        if ($p['hostHeader'] !== '') { $L[] = '      headers:'; $L[] = '        Host: ' . yaml_q($p['hostHeader']); }
        if ($p['net'] === 'httpupgrade') $L[] = '      v2ray-http-upgrade: true';
    } elseif ($cn === 'grpc') {
        $L[] = '    grpc-opts:';
        $L[] = '      grpc-service-name: ' . yaml_q($p['serviceName']);
    } elseif ($cn === 'h2') {
        $L[] = '    h2-opts:';
        if ($p['hostHeader'] !== '') $L[] = '      host: [' . yaml_q($p['hostHeader']) . ']';
        if ($p['path'] !== '') $L[] = '      path: ' . yaml_q($p['path']);
    }
    $L[] = '    encryption: ""';
    return implode("\n", $L);
}

function vless_to_singbox($p, $tag) {
    if (!is_array($p) || empty($p['ok']) || !vless_struct_net($p['net'] ?? '')) return null;
    $o = [
        'type' => 'vless',
        'tag' => ($tag !== '' ? $tag : 'vless-squad'),
        'server' => $p['host'],
        'server_port' => (int) $p['port'],
        'uuid' => $p['uuid'],
    ];
    if ($p['flow'] !== '' && $p['net'] === 'tcp') $o['flow'] = $p['flow'];
    if (in_array($p['security'], ['tls', 'reality'], true)) {
        $tls = ['enabled' => true];
        if ($p['sni'] !== '') $tls['server_name'] = $p['sni'];
        if ($p['allowInsecure']) $tls['insecure'] = true;
        if ($p['alpn']) $tls['alpn'] = $p['alpn'];
        if ($p['fp'] !== '') $tls['utls'] = ['enabled' => true, 'fingerprint' => $p['fp']];
        if ($p['security'] === 'reality') {
            if (!isset($tls['utls'])) $tls['utls'] = ['enabled' => true, 'fingerprint' => ($p['fp'] !== '' ? $p['fp'] : 'chrome')];
            $r = ['enabled' => true, 'public_key' => $p['pbk']];
            if ($p['sid'] !== '') $r['short_id'] = $p['sid'];
            $tls['reality'] = $r;
        }
        $o['tls'] = $tls;
    }
    $net = $p['net'];
    if ($net === 'ws') {
        $t = ['type' => 'ws'];
        if ($p['path'] !== '') $t['path'] = $p['path'];
        if ($p['hostHeader'] !== '') $t['headers'] = ['Host' => $p['hostHeader']];
        $o['transport'] = $t;
    } elseif ($net === 'grpc') {
        $o['transport'] = ['type' => 'grpc', 'service_name' => $p['serviceName']];
    } elseif ($net === 'http') {
        $t = ['type' => 'http'];
        if ($p['hostHeader'] !== '') $t['host'] = [$p['hostHeader']];
        if ($p['path'] !== '') $t['path'] = $p['path'];
        $o['transport'] = $t;
    } elseif ($net === 'httpupgrade') {
        $t = ['type' => 'httpupgrade'];
        if ($p['hostHeader'] !== '') $t['host'] = $p['hostHeader'];
        if ($p['path'] !== '') $t['path'] = $p['path'];
        $o['transport'] = $t;
    }
    return $o;
}

function vless_to_xray($p, $tag) {
    if (!is_array($p) || empty($p['ok']) || !vless_struct_net($p['net'] ?? '')) return null;
    $user = ['id' => $p['uuid'], 'encryption' => 'none'];
    if ($p['flow'] !== '' && $p['net'] === 'tcp') $user['flow'] = $p['flow'];
    $sec = $p['security'] === 'reality' ? 'reality' : ($p['security'] === 'tls' ? 'tls' : 'none');
    $stream = ['network' => $p['net'], 'security' => $sec];
    if ($sec === 'tls') {
        $t = [];
        if ($p['sni'] !== '') $t['serverName'] = $p['sni'];
        if ($p['alpn']) $t['alpn'] = $p['alpn'];
        if ($p['fp'] !== '') $t['fingerprint'] = $p['fp'];
        if ($t) $stream['tlsSettings'] = $t;
    } elseif ($sec === 'reality') {
        $r = [];
        if ($p['sni'] !== '') $r['serverName'] = $p['sni'];
        if ($p['fp'] !== '') $r['fingerprint'] = $p['fp'];
        if ($p['pbk'] !== '') $r['publicKey'] = $p['pbk'];
        if ($p['sid'] !== '') $r['shortId'] = $p['sid'];
        if ($p['spx'] !== '') $r['spiderX'] = $p['spx'];
        $stream['realitySettings'] = $r;
    }
    $net = $p['net'];
    if ($net === 'ws') {
        $w = [];
        if ($p['path'] !== '') $w['path'] = $p['path'];
        if ($p['hostHeader'] !== '') $w['headers'] = ['Host' => $p['hostHeader']];
        if ($w) $stream['wsSettings'] = $w;
    } elseif ($net === 'grpc') {
        $gs = ['serviceName' => $p['serviceName']];
        if ($p['grpcMode'] === 'multi') $gs['multiMode'] = true;
        $stream['grpcSettings'] = $gs;
    } elseif ($net === 'http') {
        $h = [];
        if ($p['path'] !== '') $h['path'] = $p['path'];
        if ($p['hostHeader'] !== '') $h['host'] = [$p['hostHeader']];
        if ($h) $stream['httpSettings'] = $h;
    } elseif ($net === 'httpupgrade') {
        $h = [];
        if ($p['path'] !== '') $h['path'] = $p['path'];
        if ($p['hostHeader'] !== '') $h['host'] = $p['hostHeader'];
        if ($h) $stream['httpupgradeSettings'] = $h;
    }
    $o = [
        'protocol' => 'vless',
        'settings' => ['vnext' => [['address' => $p['host'], 'port' => (int) $p['port'], 'users' => [$user]]]],
        'streamSettings' => $stream,
    ];
    if ($tag !== '') $o['tag'] = $tag;
    return $o;
}
