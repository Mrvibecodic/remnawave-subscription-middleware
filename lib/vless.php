<?php

function vless_net_norm($net) {
    $net = strtolower(trim((string) $net));
    if ($net === '' || $net === 'raw') return 'tcp';
    if ($net === 'websocket') return 'ws';
    if ($net === 'h2') return 'http';
    if ($net === 'splithttp') return 'xhttp';
    if ($net === 'mkcp') return 'kcp';
    return $net;
}

function vless_struct_net($net) {
    return in_array(vless_net_norm($net), ['tcp', 'ws', 'grpc', 'http', 'httpupgrade', 'xhttp', 'kcp', 'quic'], true);
}

function vless_obfs_http($p) {
    return vless_net_norm($p['net'] ?? '') === 'tcp' && strtolower((string) ($p['headerType'] ?? '')) === 'http';
}

function vless_enc_on($p) {
    $e = strtolower(trim((string) ($p['encryption'] ?? '')));
    return $e !== '' && $e !== 'none';
}

function vless_xhttp_mode($p) {
    $m = strtolower(trim((string) ($p['xmode'] ?? '')));
    return in_array($m, ['auto', 'packet-up', 'stream-up', 'stream-one'], true) ? $m : '';
}

function vless_core_ok($p, $core) {
    if (!is_array($p) || empty($p['ok'])) return false;
    $net = vless_net_norm($p['net'] ?? '');
    $sec = (string) ($p['security'] ?? 'none');
    if ($core === 'clash') {
        return in_array($net, ['tcp', 'ws', 'grpc', 'http', 'httpupgrade', 'xhttp'], true);
    }
    if ($core === 'singbox') {
        if (!in_array($net, ['tcp', 'ws', 'grpc', 'http', 'httpupgrade', 'quic'], true)) return false;
        if (vless_enc_on($p)) return false;
        if (vless_obfs_http($p)) return false;
        if ($net === 'quic' && !in_array($sec, ['tls', 'reality'], true)) return false;
        if ($net === 'grpc' && trim((string) ($p['serviceName'] ?? '')) === '') return false;
        return true;
    }
    if ($core === 'xray') {
        if (!in_array($net, ['tcp', 'ws', 'grpc', 'httpupgrade', 'xhttp', 'kcp'], true)) return false;
        if ($sec === 'reality' && !in_array($net, ['tcp', 'xhttp', 'grpc'], true)) return false;
        if ($net === 'kcp') {
            $legacy = trim((string) ($p['seed'] ?? '')) !== '' || trim((string) ($p['headerType'] ?? '')) !== '';
            if ($legacy && empty($p['fm'])) return false;
        }
        return true;
    }
    return false;
}

function vless_clients($p) {
    $out = ['base64 (v2rayNG, Streisand, Happ)'];
    if (vless_core_ok($p, 'clash')) $out[] = 'Mihomo / Clash.Meta';
    if (vless_core_ok($p, 'singbox')) $out[] = 'sing-box (Hiddify и др.)';
    if (vless_core_ok($p, 'xray')) $out[] = 'Xray JSON';
    return $out;
}

function vless_core_notes($p) {
    $out = [];
    $net = vless_net_norm($p['net'] ?? '');
    $sec = (string) ($p['security'] ?? 'none');
    $c = vless_core_ok($p, 'clash');
    $s = vless_core_ok($p, 'singbox');
    $x = vless_core_ok($p, 'xray');
    if (!$c && !$s && !$x) {
        $out[] = 'Транспорт «' . $net . '» не собирается ни одним ядром — конфиг уйдёт только в base64-подписку как ссылка.';
        return $out;
    }
    if ($net === 'xhttp') $out[] = 'XHTTP умеют Xray и Mihomo (с v1.19.22). В sing-box такого транспорта нет — там конфиг не появится.';
    if ($net === 'kcp') {
        if ($x) $out[] = 'mKCP собирается только для Xray JSON; в Clash и sing-box конфиг не появится.';
        else $out[] = 'mKCP с параметрами seed/headerType Xray больше не принимает (удалены в v26.3.23) — нужна ссылка с параметром fm. Конфиг уйдёт только в base64.';
    }
    if ($net === 'quic') $out[] = 'QUIC остался только в sing-box — Xray этот транспорт удалил, Mihomo его не умеет.';
    if ($net === 'http') $out[] = 'HTTP/2 удалён из Xray (v24.12.15) — в Xray JSON конфиг не попадёт. Clash и sing-box его собирают.';
    if (vless_obfs_http($p)) $out[] = 'HTTP-обфускация (headerType=http) собирается для Xray и Mihomo; в sing-box TCP-транспорта нет.';
    if (vless_enc_on($p)) $out[] = 'VLESS Encryption поддерживают Xray и Mihomo; sing-box такого поля не знает — там конфиг не появится.';
    if ($sec === 'reality' && !in_array($net, ['tcp', 'xhttp', 'grpc'], true)) $out[] = 'Xray разрешает REALITY только для RAW, XHTTP и gRPC — в Xray JSON конфиг не попадёт.';
    if ($net === 'grpc' && trim((string) ($p['serviceName'] ?? '')) === '') $out[] = 'gRPC без serviceName в sing-box даёт нерабочий путь — конфиг туда не попадёт.';
    if ($net === 'grpc' && strtolower((string) ($p['grpcMode'] ?? '')) === 'multi') $out[] = 'Режим multi у gRPC есть только в Xray; Mihomo и sing-box его не умеют.';
    if (!empty($p['allowInsecure'])) $out[] = 'Параметр allowInsecure удалён из Xray (v26.2.6) — в Xray JSON он не выводится.';
    return $out;
}

function vless_summary($p) {
    if (!is_array($p)) return '';
    $sec = ($p['security'] ?? '') === 'reality' ? 'Reality' : (($p['security'] ?? '') === 'tls' ? 'TLS' : 'no-TLS');
    $net = vless_net_norm($p['net'] ?? 'tcp');
    if (vless_obfs_http($p)) $net = 'tcp+http';
    return 'VLESS ' . strtoupper($net) . ' + ' . $sec;
}

function vless_parse($raw) {
    $res = [
        'ok' => false, 'type' => 'vless', 'version' => '',
        'clients' => [], 'warnings' => [], 'notes' => [],
        'uuid' => '', 'host' => '', 'port' => 0,
        'net' => 'tcp', 'security' => 'none', 'flow' => '', 'encryption' => 'none',
        'sni' => '', 'alpn' => [], 'fp' => '', 'pbk' => '', 'sid' => '', 'spx' => '', 'pqv' => '',
        'pcs' => '', 'vcn' => '',
        'path' => '', 'hostHeader' => '', 'serviceName' => '', 'grpcMode' => '',
        'xmode' => '', 'extra' => [], 'fm' => [], 'seed' => '', 'mtu' => 0, 'tti' => 0,
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

    $res['net'] = vless_net_norm($g('type', 'tcp'));

    $sec = strtolower($g('security', 'none'));
    if ($sec === '') $sec = 'none';
    $res['security'] = $sec;

    $res['flow'] = $g('flow', '');
    $enc = trim($g('encryption', 'none'));
    $res['encryption'] = $enc !== '' ? $enc : 'none';
    $res['sni'] = $g('sni', $g('serverName', ''));
    $alpn = $g('alpn', '');
    if ($alpn !== '') $res['alpn'] = array_values(array_filter(array_map('trim', explode(',', $alpn)), fn($x) => $x !== ''));
    $res['fp'] = $g('fp', '');
    $res['pbk'] = $g('pbk', '');
    $res['sid'] = $g('sid', '');
    $res['spx'] = $g('spx', '');
    $res['pqv'] = $g('pqv', '');
    $res['pcs'] = $g('pcs', '');
    $res['vcn'] = $g('vcn', '');
    $res['headerType'] = strtolower($g('headerType', ''));
    $ai = strtolower($g('allowInsecure', ''));
    $res['allowInsecure'] = ($ai === '1' || $ai === 'true');
    $res['path'] = $g('path', '');
    $res['hostHeader'] = $g('host', '');
    $res['serviceName'] = $g('serviceName', '');
    $res['grpcMode'] = strtolower($g('mode', ''));
    $res['xmode'] = strtolower($g('mode', ''));
    $res['seed'] = $g('seed', '');
    $res['mtu'] = (int) $g('mtu', '0');
    $res['tti'] = (int) $g('tti', '0');
    $ex = $g('extra', '');
    if ($ex !== '') {
        $d = json_decode($ex, true);
        if (is_array($d)) $res['extra'] = $d;
        else $res['warnings'][] = 'Параметр extra не разобрался как JSON — тонкие настройки XHTTP будут потеряны в YAML/JSON.';
    }
    $fmr = $g('fm', '');
    if ($fmr !== '') {
        $d = json_decode($fmr, true);
        if (is_array($d)) $res['fm'] = $d;
        else $res['warnings'][] = 'Параметр fm не разобрался как JSON.';
    }
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
        $res['notes'] = vless_core_notes($res);
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

function vless_yaml_key($k) {
    $k = (string) $k;
    return preg_match('/^[A-Za-z0-9_.-]+$/', $k) ? $k : yaml_q($k);
}

function vless_yaml_scalar($v) {
    if (is_bool($v)) return $v ? 'true' : 'false';
    if (is_int($v) || is_float($v)) return (string) $v;
    return yaml_q((string) $v);
}

function vless_yaml_block($val, $ind) {
    $pad = str_repeat(' ', $ind);
    $out = [];
    if (!is_array($val)) return [$pad . vless_yaml_scalar($val)];
    $is_list = ($val !== [] && array_keys($val) === range(0, count($val) - 1));
    if ($is_list) {
        foreach ($val as $v) {
            if (is_array($v)) { $out[] = $pad . '-'; foreach (vless_yaml_block($v, $ind + 2) as $l) $out[] = $l; }
            else $out[] = $pad . '- ' . vless_yaml_scalar($v);
        }
        return $out;
    }
    foreach ($val as $k => $v) {
        if (is_array($v)) {
            if ($v === []) continue;
            $out[] = $pad . vless_yaml_key($k) . ':';
            foreach (vless_yaml_block($v, $ind + 2) as $l) $out[] = $l;
        } else {
            $out[] = $pad . vless_yaml_key($k) . ': ' . vless_yaml_scalar($v);
        }
    }
    return $out;
}

function vless_extra_str($v) {
    if (is_bool($v)) return $v ? 'true' : 'false';
    if (is_int($v) || is_float($v)) return (string) $v;
    return (string) $v;
}

function vless_extra_bool($v) {
    if (is_bool($v)) return $v;
    $s = strtolower(trim((string) $v));
    return ($s === '1' || $s === 'true');
}

function vless_xhttp_extra_map() {
    return [
        'xPaddingBytes' => 'x-padding-bytes',
        'xPaddingObfsMode' => 'x-padding-obfs-mode',
        'xPaddingKey' => 'x-padding-key',
        'xPaddingHeader' => 'x-padding-header',
        'xPaddingPlacement' => 'x-padding-placement',
        'xPaddingMethod' => 'x-padding-method',
        'uplinkHTTPMethod' => 'uplink-http-method',
        'sessionIDPlacement' => 'session-placement',
        'sessionPlacement' => 'session-placement',
        'sessionIDKey' => 'session-key',
        'sessionKey' => 'session-key',
        'sessionIDTable' => 'session-table',
        'sessionIDLength' => 'session-length',
        'seqPlacement' => 'seq-placement',
        'seqKey' => 'seq-key',
        'uplinkDataPlacement' => 'uplink-data-placement',
        'uplinkDataKey' => 'uplink-data-key',
        'uplinkChunkSize' => 'uplink-chunk-size',
        'scMaxEachPostBytes' => 'sc-max-each-post-bytes',
        'scMinPostsIntervalMs' => 'sc-min-posts-interval-ms',
        'noGRPCHeader' => 'no-grpc-header',
    ];
}

function vless_xhttp_clash_opts($p) {
    $x = [];
    if ((string) ($p['path'] ?? '') !== '') $x['path'] = (string) $p['path'];
    if ((string) ($p['hostHeader'] ?? '') !== '') $x['host'] = (string) $p['hostHeader'];
    $mode = vless_xhttp_mode($p);
    if ($mode !== '') $x['mode'] = $mode;
    $e = $p['extra'] ?? null;
    if (!is_array($e) || !$e) return $x;
    $bools = ['no-grpc-header', 'x-padding-obfs-mode'];
    foreach (vless_xhttp_extra_map() as $src => $dst) {
        if (!array_key_exists($src, $e) || is_array($e[$src])) continue;
        $x[$dst] = in_array($dst, $bools, true) ? vless_extra_bool($e[$src]) : vless_extra_str($e[$src]);
    }
    if (isset($e['headers']) && is_array($e['headers'])) {
        $h = [];
        foreach ($e['headers'] as $k => $v) { if (!is_array($v)) $h[(string) $k] = (string) $v; }
        if ($h) $x['headers'] = $h;
    }
    if (isset($e['xmux']) && is_array($e['xmux'])) {
        $mux = [];
        $mm = [
            'maxConcurrency' => 'max-concurrency',
            'maxConnections' => 'max-connections',
            'cMaxReuseTimes' => 'c-max-reuse-times',
            'hMaxRequestTimes' => 'h-max-request-times',
            'hMaxReusableSecs' => 'h-max-reusable-secs',
        ];
        foreach ($mm as $src => $dst) {
            if (array_key_exists($src, $e['xmux']) && !is_array($e['xmux'][$src])) $mux[$dst] = vless_extra_str($e['xmux'][$src]);
        }
        if (array_key_exists('hKeepAlivePeriod', $e['xmux']) && !is_array($e['xmux']['hKeepAlivePeriod'])) {
            $mux['h-keep-alive-period'] = (int) $e['xmux']['hKeepAlivePeriod'];
        }
        if ($mux) $x['reuse-settings'] = $mux;
    }
    if (isset($e['downloadSettings']) && is_array($e['downloadSettings'])) {
        $d = $e['downloadSettings'];
        $ds = [];
        if (isset($d['address']) && !is_array($d['address'])) $ds['server'] = (string) $d['address'];
        if (isset($d['port']) && !is_array($d['port'])) $ds['port'] = (int) $d['port'];
        if (isset($d['security']) && !is_array($d['security'])) $ds['tls'] = in_array(strtolower((string) $d['security']), ['tls', 'reality'], true);
        $dx = $d['xhttpSettings'] ?? ($d['splithttpSettings'] ?? null);
        if (is_array($dx)) {
            if (isset($dx['path']) && !is_array($dx['path'])) $ds['path'] = (string) $dx['path'];
            if (isset($dx['host']) && !is_array($dx['host'])) $ds['host'] = (string) $dx['host'];
            if (isset($dx['mode']) && !is_array($dx['mode'])) $ds['mode'] = (string) $dx['mode'];
        }
        $dt = $d['tlsSettings'] ?? null;
        if (is_array($dt)) {
            if (isset($dt['serverName']) && !is_array($dt['serverName'])) $ds['servername'] = (string) $dt['serverName'];
            if (isset($dt['fingerprint']) && !is_array($dt['fingerprint'])) $ds['client-fingerprint'] = (string) $dt['fingerprint'];
        }
        if ($ds) $x['download-settings'] = $ds;
    }
    return $x;
}

function vless_clash_net($p) {
    $net = vless_net_norm($p['net'] ?? '');
    if (vless_obfs_http($p)) return 'http';
    if ($net === 'http') return 'h2';
    if ($net === 'httpupgrade') return 'ws';
    if (in_array($net, ['ws', 'grpc', 'xhttp'], true)) return $net;
    return 'tcp';
}

function vless_to_clash($p, $name) {
    if (!vless_core_ok($p, 'clash')) return '';
    $net = vless_net_norm($p['net'] ?? '');
    $L = [];
    $L[] = '  - name: ' . yaml_q($name);
    $L[] = '    type: vless';
    $L[] = '    server: ' . $p['host'];
    $L[] = '    port: ' . (int) $p['port'];
    $L[] = '    uuid: ' . yaml_q($p['uuid']);
    $L[] = '    udp: true';
    if (($p['flow'] ?? '') !== '' && $net === 'tcp' && !vless_obfs_http($p)) $L[] = '    flow: ' . $p['flow'];
    if (in_array($p['security'], ['tls', 'reality'], true)) {
        $L[] = '    tls: true';
        if (($p['sni'] ?? '') !== '') $L[] = '    servername: ' . yaml_q($p['sni']);
        if (!empty($p['alpn'])) $L[] = '    alpn: [' . implode(', ', array_map('yaml_q', $p['alpn'])) . ']';
        if (($p['fp'] ?? '') !== '') $L[] = '    client-fingerprint: ' . yaml_q($p['fp']);
        if (!empty($p['allowInsecure'])) $L[] = '    skip-cert-verify: true';
        if ($p['security'] === 'reality') {
            $L[] = '    reality-opts:';
            $L[] = '      public-key: ' . yaml_q($p['pbk']);
            if (($p['sid'] ?? '') !== '') $L[] = '      short-id: ' . yaml_q($p['sid']);
        }
    }
    $cn = vless_clash_net($p);
    $L[] = '    network: ' . $cn;
    if ($cn === 'ws') {
        $ws = [];
        if (($p['path'] ?? '') !== '') $ws['path'] = (string) $p['path'];
        if (($p['hostHeader'] ?? '') !== '') $ws['headers'] = ['Host' => (string) $p['hostHeader']];
        if ($net === 'httpupgrade') $ws['v2ray-http-upgrade'] = true;
        if ($ws) { $L[] = '    ws-opts:'; foreach (vless_yaml_block($ws, 6) as $l) $L[] = $l; }
    } elseif ($cn === 'grpc') {
        $L[] = '    grpc-opts:';
        $L[] = '      grpc-service-name: ' . yaml_q((string) ($p['serviceName'] ?? ''));
    } elseif ($cn === 'h2') {
        $h2 = [];
        if (($p['hostHeader'] ?? '') !== '') $h2['host'] = [(string) $p['hostHeader']];
        if (($p['path'] ?? '') !== '') $h2['path'] = (string) $p['path'];
        if ($h2) { $L[] = '    h2-opts:'; foreach (vless_yaml_block($h2, 6) as $l) $L[] = $l; }
    } elseif ($cn === 'http') {
        $ho = ['method' => 'GET', 'path' => [(($p['path'] ?? '') !== '') ? (string) $p['path'] : '/']];
        $hh = (($p['hostHeader'] ?? '') !== '') ? (string) $p['hostHeader'] : (string) $p['host'];
        $ho['headers'] = ['Host' => [$hh], 'Connection' => ['keep-alive']];
        $L[] = '    http-opts:';
        foreach (vless_yaml_block($ho, 6) as $l) $L[] = $l;
    } elseif ($cn === 'xhttp') {
        $xo = vless_xhttp_clash_opts($p);
        if ($xo) { $L[] = '    xhttp-opts:'; foreach (vless_yaml_block($xo, 6) as $l) $L[] = $l; }
    }
    if (vless_enc_on($p)) $L[] = '    encryption: ' . yaml_q((string) $p['encryption']);
    else $L[] = '    encryption: ""';
    return implode("\n", $L);
}

function vless_to_singbox($p, $tag) {
    if (!vless_core_ok($p, 'singbox')) return null;
    $net = vless_net_norm($p['net'] ?? '');
    $o = [
        'type' => 'vless',
        'tag' => ($tag !== '' ? $tag : 'vless-squad'),
        'server' => $p['host'],
        'server_port' => (int) $p['port'],
        'uuid' => $p['uuid'],
    ];
    if (($p['flow'] ?? '') !== '' && $net === 'tcp') $o['flow'] = $p['flow'];
    if (in_array($p['security'], ['tls', 'reality'], true)) {
        $tls = ['enabled' => true];
        if (($p['sni'] ?? '') !== '') $tls['server_name'] = $p['sni'];
        if (!empty($p['allowInsecure'])) $tls['insecure'] = true;
        if (!empty($p['alpn'])) $tls['alpn'] = $p['alpn'];
        if (($p['fp'] ?? '') !== '') $tls['utls'] = ['enabled' => true, 'fingerprint' => $p['fp']];
        if ($p['security'] === 'reality') {
            if (!isset($tls['utls'])) $tls['utls'] = ['enabled' => true, 'fingerprint' => (($p['fp'] ?? '') !== '' ? $p['fp'] : 'chrome')];
            $r = ['enabled' => true, 'public_key' => $p['pbk']];
            if (($p['sid'] ?? '') !== '') $r['short_id'] = $p['sid'];
            $tls['reality'] = $r;
        }
        $o['tls'] = $tls;
    }
    if ($net === 'ws') {
        $t = ['type' => 'ws'];
        if (($p['path'] ?? '') !== '') $t['path'] = $p['path'];
        if (($p['hostHeader'] ?? '') !== '') $t['headers'] = ['Host' => $p['hostHeader']];
        $o['transport'] = $t;
    } elseif ($net === 'grpc') {
        $o['transport'] = ['type' => 'grpc', 'service_name' => (string) $p['serviceName']];
    } elseif ($net === 'http') {
        $t = ['type' => 'http'];
        if (($p['hostHeader'] ?? '') !== '') $t['host'] = [$p['hostHeader']];
        if (($p['path'] ?? '') !== '') $t['path'] = $p['path'];
        $o['transport'] = $t;
    } elseif ($net === 'httpupgrade') {
        $t = ['type' => 'httpupgrade'];
        if (($p['hostHeader'] ?? '') !== '') $t['host'] = $p['hostHeader'];
        if (($p['path'] ?? '') !== '') $t['path'] = $p['path'];
        $o['transport'] = $t;
    } elseif ($net === 'quic') {
        $o['transport'] = ['type' => 'quic'];
    }
    return $o;
}

function vless_xray_http_header($p) {
    $host = (($p['hostHeader'] ?? '') !== '') ? (string) $p['hostHeader'] : (string) $p['host'];
    $path = (($p['path'] ?? '') !== '') ? (string) $p['path'] : '/';
    return [
        'type' => 'http',
        'request' => [
            'version' => '1.1',
            'method' => 'GET',
            'path' => [$path],
            'headers' => [
                'Host' => [$host],
                'Connection' => ['keep-alive'],
                'Accept-Encoding' => ['gzip, deflate'],
            ],
        ],
        'response' => [
            'version' => '1.1',
            'status' => '200',
            'reason' => 'OK',
            'headers' => [
                'Content-Type' => ['application/octet-stream'],
                'Transfer-Encoding' => ['chunked'],
                'Connection' => ['keep-alive'],
            ],
        ],
    ];
}

function vless_to_xray($p, $tag) {
    if (!vless_core_ok($p, 'xray')) return null;
    $net = vless_net_norm($p['net'] ?? '');
    $user = ['id' => $p['uuid'], 'encryption' => vless_enc_on($p) ? (string) $p['encryption'] : 'none'];
    if (($p['flow'] ?? '') !== '' && ($net === 'tcp' || vless_enc_on($p))) $user['flow'] = $p['flow'];
    $sec = $p['security'] === 'reality' ? 'reality' : ($p['security'] === 'tls' ? 'tls' : 'none');
    $stream = ['network' => $net === 'kcp' ? 'kcp' : $net, 'security' => $sec];
    if ($sec === 'tls') {
        $t = [];
        if (($p['sni'] ?? '') !== '') $t['serverName'] = $p['sni'];
        if (!empty($p['alpn'])) $t['alpn'] = $p['alpn'];
        if (($p['fp'] ?? '') !== '') $t['fingerprint'] = $p['fp'];
        if (($p['pcs'] ?? '') !== '') $t['pinnedPeerCertSha256'] = $p['pcs'];
        if (($p['vcn'] ?? '') !== '') $t['verifyPeerCertByName'] = $p['vcn'];
        if ($t) $stream['tlsSettings'] = $t;
    } elseif ($sec === 'reality') {
        $r = [];
        if (($p['sni'] ?? '') !== '') $r['serverName'] = $p['sni'];
        if (($p['fp'] ?? '') !== '') $r['fingerprint'] = $p['fp'];
        if (($p['pbk'] ?? '') !== '') $r['publicKey'] = $p['pbk'];
        if (($p['sid'] ?? '') !== '') $r['shortId'] = $p['sid'];
        if (($p['pqv'] ?? '') !== '') $r['mldsa65Verify'] = $p['pqv'];
        if (($p['spx'] ?? '') !== '') $r['spiderX'] = $p['spx'];
        $stream['realitySettings'] = $r;
    }
    if ($net === 'tcp') {
        if (vless_obfs_http($p)) $stream['rawSettings'] = ['header' => vless_xray_http_header($p)];
    } elseif ($net === 'ws') {
        $w = [];
        if (($p['path'] ?? '') !== '') $w['path'] = $p['path'];
        if (($p['hostHeader'] ?? '') !== '') $w['host'] = $p['hostHeader'];
        if ($w) $stream['wsSettings'] = $w;
    } elseif ($net === 'grpc') {
        $gs = ['serviceName' => (string) ($p['serviceName'] ?? '')];
        if (strtolower((string) ($p['grpcMode'] ?? '')) === 'multi') $gs['multiMode'] = true;
        $stream['grpcSettings'] = $gs;
    } elseif ($net === 'httpupgrade') {
        $h = [];
        if (($p['path'] ?? '') !== '') $h['path'] = $p['path'];
        if (($p['hostHeader'] ?? '') !== '') $h['host'] = $p['hostHeader'];
        if ($h) $stream['httpupgradeSettings'] = $h;
    } elseif ($net === 'xhttp') {
        $x = [];
        if (($p['path'] ?? '') !== '') $x['path'] = $p['path'];
        if (($p['hostHeader'] ?? '') !== '') $x['host'] = $p['hostHeader'];
        $mode = vless_xhttp_mode($p);
        if ($mode !== '') $x['mode'] = $mode;
        if (!empty($p['extra']) && is_array($p['extra'])) $x['extra'] = $p['extra'];
        $stream['xhttpSettings'] = $x;
    } elseif ($net === 'kcp') {
        $k = [];
        if ((int) ($p['mtu'] ?? 0) > 0) $k['mtu'] = (int) $p['mtu'];
        if ((int) ($p['tti'] ?? 0) > 0) $k['tti'] = (int) $p['tti'];
        if ($k) $stream['kcpSettings'] = $k;
    }
    if (!empty($p['fm']) && is_array($p['fm'])) $stream['finalmask'] = $p['fm'];
    $o = [
        'protocol' => 'vless',
        'settings' => ['vnext' => [['address' => $p['host'], 'port' => (int) $p['port'], 'users' => [$user]]]],
        'streamSettings' => $stream,
    ];
    if ($tag !== '') $o['tag'] = $tag;
    return $o;
}
