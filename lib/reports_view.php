<?php

// Экран «Статистика» Clod Clash: выборки из сводок отчётов клиентов (lib/reports.php).
// Только чтение; фильтры — период, тип сети, платформа, страна клиента, узел.

const REP_VIEW_PERIODS = [1, 7, 30];
const REP_VIEW_KINDS   = ['wifi' => 'Wi-Fi', 'mobile' => 'Мобильная', 'wired' => 'Кабель', 'other' => 'Другая'];
const REP_VIEW_PLATS   = ['pc' => 'ПК', 'android' => 'Android'];
// Wi-Fi и кабель — один и тот же домашний провайдер, разница только в последнем метре.
const REP_VIEW_KIND_SETS = ['home' => ['wifi', 'wired']];
const REP_VIEW_KIND_SET_NAMES = ['home' => 'Домашний (Wi-Fi и кабель)'];
// Ниже стольких пингов доля неудач — шум, а не оценка.
const REP_VIEW_MIN_PINGS = 20;
const REP_VIEW_VERDICT_TTL = 259200;

function rep_view_filters(array $q) {
    $p = (int) ($q['p'] ?? 7);
    $cc = strtoupper((string) ($q['cc'] ?? ''));
    $node = (string) ($q['node'] ?? '');

    return [
        'p'    => in_array($p, REP_VIEW_PERIODS, true) ? $p : 7,
        'kind' => isset(REP_VIEW_KINDS[$q['kind'] ?? '']) || isset(REP_VIEW_KIND_SETS[$q['kind'] ?? '']) ? (string) $q['kind'] : '',
        'plat' => isset(REP_VIEW_PLATS[$q['plat'] ?? '']) ? (string) $q['plat'] : '',
        'cc'   => preg_match('~^[A-Z]{2}$~', $cc) ? $cc : '',
        'node' => preg_match('~^[0-9a-f]{12}$~', $node) ? $node : '',
        'q'    => rep_str(trim((string) ($q['q'] ?? '')), 128),
    ];
}

function rep_view_since(array $f, $now = null) {
    $now = $now ?? time();

    return intdiv($now, 3600) * 3600 - $f['p'] * 86400;
}

// Условие WHERE по фильтрам; $time — колонка времени, $cols — какие фильтры у таблицы есть.
// Узел «не отвечает»: ни одного ответа в последних часах с замерами (rpn/rpf);
// строки от прежних версий, где их нет, — по всему окну, как раньше.
function rep_dead_sql($p = '') {
    if (!rep_new_cols()) return "(CASE WHEN {$p}pn > 0 AND {$p}pf >= {$p}pn THEN 1 ELSE 0 END)";

    return "(CASE WHEN {$p}rpn > 0 THEN (CASE WHEN {$p}rpf >= {$p}rpn THEN 1 ELSE 0 END) WHEN {$p}pn > 0 AND {$p}pf >= {$p}pn THEN 1 ELSE 0 END)";
}

function rep_is_dead(array $r) {
    $rpn = (int) ($r['rpn'] ?? 0);
    if ($rpn > 0) return (int) ($r['rpf'] ?? 0) >= $rpn;

    return (int) $r['pn'] > 0 && (int) $r['pf'] >= (int) $r['pn'];
}

function rep_view_where(array $f, $time, $since, array $cols) {
    $sql = ["$time >= ?"];
    $args = [$since];
    foreach (['kind', 'plat', 'cc', 'node'] as $k) {
        if ($f[$k] === '' || !in_array($k, $cols, true)) continue;
        if ($k === 'kind' && isset(REP_VIEW_KIND_SETS[$f[$k]])) {
            $set = REP_VIEW_KIND_SETS[$f[$k]];
            $sql[] = 'kind IN (' . implode(', ', array_fill(0, count($set), '?')) . ')';
            array_push($args, ...$set);
            continue;
        }
        $sql[] = ($k === 'node' ? 'nkey' : $k) . ' = ?';
        $args[] = $f[$k];
    }

    return [implode(' AND ', $sql), $args];
}

function rep_view_sums($prefix = '') {
    $out = [];
    foreach (['pn', 'pf', 'b0', 'b1', 'b2', 'b3', 'b4', 'b5', 'up', 'down', 'fok', 'ffr', 'fdd'] as $c) {
        $out[] = "SUM($prefix$c) AS $c";
    }

    return implode(', ', $out);
}

function rep_view_rows($sql, array $args) {
    if (!rep_ensure() || !($p = db())) return [];
    try {
        $st = $p->prepare($sql);
        $st->execute($args);

        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('submw rep view: ' . $e->getMessage());

        return [];
    }
}

// Оценка набора сумм: доля неудач, медианная корзина, вердикты, трафик.
function rep_view_score(array $r) {
    $pn = (int) ($r['pn'] ?? 0);
    $pf = (int) ($r['pf'] ?? 0);
    $b = [];
    for ($i = 0; $i < REP_BUCKETS; $i++) $b[] = (int) ($r['b' . $i] ?? 0);

    return [
        'pn'    => $pn,
        'pf'    => $pf,
        'fail'  => $pn >= REP_VIEW_MIN_PINGS ? $pf / $pn : null,
        'med'   => rep_median_bucket($b),
        'b'     => $b,
        'fok'   => (int) ($r['fok'] ?? 0),
        'ffr'   => (int) ($r['ffr'] ?? 0),
        'fdd'   => (int) ($r['fdd'] ?? 0),
        'bytes' => (int) ($r['up'] ?? 0) + (int) ($r['down'] ?? 0),
    ];
}

// Сколько сетей уже с почасовой историей и когда пришёл первый такой отчёт:
// строки, записанные до обновления прослойки, её не получат до следующего отчёта.
function rep_view_hist_stat($since) {
    $out = ['rows' => 0, 'with' => 0, 'first' => 0];
    if (!rep_new_cols()) return $out;
    $r = rep_view_rows("SELECT COUNT(*) AS n, SUM(CASE WHEN hist <> '' THEN 1 ELSE 0 END) AS w, MIN(CASE WHEN hist <> '' THEN last_seen END) AS f FROM rep_state WHERE last_seen >= ?", [(int) $since]);
    if ($r) $out = ['rows' => (int) $r[0]['n'], 'with' => (int) $r[0]['w'], 'first' => (int) $r[0]['f']];

    return $out;
}

function rep_view_totals(array $f, $since) {
    [$w, $a] = rep_view_where($f, 'h', $since, ['kind', 'plat', 'cc', 'node']);
    $rows = rep_view_rows('SELECT ' . rep_view_sums() . ", COUNT(DISTINCT nkey) AS nodes FROM rep_hour WHERE $w", $a);
    $out = rep_view_score($rows[0] ?? []);
    $out['nodes'] = (int) ($rows[0]['nodes'] ?? 0);

    [$w, $a] = rep_view_where($f, 's.last_seen', $since, ['kind', 'cc', 'node', 'plat']);
    $w = str_replace(['kind = ?', 'kind IN (', 'cc = ?', 'nkey = ?', 'plat = ?'], ['s.kind = ?', 's.kind IN (', 's.cc = ?', 's.nkey = ?', 'd.plat = ?'], $w);
    $join = $f['plat'] !== '' ? ' JOIN rep_dev d ON d.short_uuid = s.short_uuid AND d.hwid = s.hwid' : '';
    $dev = rep_view_rows("SELECT COUNT(*) AS n FROM (SELECT s.short_uuid, s.hwid FROM rep_state s$join WHERE $w GROUP BY s.short_uuid, s.hwid) t", $a);
    $out['devices'] = (int) ($dev[0]['n'] ?? 0);

    return $out;
}

// Провайдеры (страна + AS) и их адреса: адрес всегда рядом со своей AS, потому что
// база может ошибиться с названием провайдера, а номер AS и адрес — нет.
function rep_view_isps(array $f, $since, $limit = 200) {
    $day = intdiv($since, 86400) * 86400;
    [$w, $a] = rep_view_where($f, 'd', $day, ['kind', 'cc', 'node']);
    $ips = rep_view_rows('SELECT cc, asn, ip, MAX(org) AS org, MAX(d) AS last, ' . rep_view_sums()
        . " FROM rep_ip_day WHERE $w GROUP BY cc, asn, ip ORDER BY SUM(pn) DESC LIMIT 5000", $a);

    $isps = [];
    foreach ($ips as $r) {
        $k = $r['cc'] . '|' . $r['asn'];
        if (!isset($isps[$k])) {
            $isps[$k] = ['cc' => (string) $r['cc'], 'asn' => (int) $r['asn'], 'org' => '', 'ips' => [], 'sum' => []];
        }
        if ($isps[$k]['org'] === '' && (string) $r['org'] !== '') $isps[$k]['org'] = (string) $r['org'];
        $isps[$k]['ips'][] = ['ip' => (string) $r['ip'], 'org' => (string) $r['org'], 'last' => (int) $r['last']] + rep_view_score($r);
        foreach (['pn', 'pf', 'b0', 'b1', 'b2', 'b3', 'b4', 'b5', 'up', 'down', 'fok', 'ffr', 'fdd'] as $c) {
            $isps[$k]['sum'][$c] = ($isps[$k]['sum'][$c] ?? 0) + (int) $r[$c];
        }
    }

    [$w, $a] = rep_view_where($f, 'last_seen', $since, ['kind', 'cc', 'node']);
    $devs = [];
    foreach (rep_view_rows("SELECT cc, asn, COUNT(*) AS n FROM (SELECT cc, asn, short_uuid, hwid FROM rep_state WHERE $w GROUP BY cc, asn, short_uuid, hwid) t GROUP BY cc, asn", $a) as $r) {
        $devs[$r['cc'] . '|' . $r['asn']] = (int) $r['n'];
    }

    foreach ($isps as $k => &$isp) {
        $isp += rep_view_score($isp['sum']);
        $isp['devices'] = $devs[$k] ?? 0;
        unset($isp['sum']);
    }
    unset($isp);

    uasort($isps, static fn($x, $y) => $y['pn'] <=> $x['pn']);

    return array_slice(array_values($isps), 0, $limit);
}

function rep_view_nodes(array $f, $since) {
    [$w, $a] = rep_view_where($f, 'h', $since, ['kind', 'plat', 'cc', 'node']);
    $rows = rep_view_rows('SELECT nkey, ' . rep_view_sums() . " FROM rep_hour WHERE $w GROUP BY nkey", $a);
    if (!$rows) return [];

    $info = [];
    foreach (rep_view_rows('SELECT nkey, type, server, port, name FROM rep_node', []) as $n) $info[$n['nkey']] = $n;

    // Где узлу хуже всего: страна + AS с наибольшей долей неудач (при достаточном числе пингов).
    $worst = [];
    foreach (rep_view_rows('SELECT nkey, cc, asn, ' . rep_view_sums() . " FROM rep_hour WHERE $w AND asn > 0 GROUP BY nkey, cc, asn", $a) as $r) {
        $s = rep_view_score($r);
        if ($s['fail'] === null) continue;
        if (!isset($worst[$r['nkey']]) || $s['fail'] > $worst[$r['nkey']]['fail']) {
            $worst[$r['nkey']] = ['cc' => (string) $r['cc'], 'asn' => (int) $r['asn'], 'org' => '', 'fail' => $s['fail'], 'pn' => $s['pn']];
        }
    }
    if ($worst) {
        $asns = array_values(array_unique(array_map(static fn($x) => (int) $x['asn'], $worst)));
        $orgs = [];
        foreach (rep_view_rows('SELECT asn, MAX(org) AS org FROM rep_ip_day WHERE asn IN (' . implode(', ', $asns) . ') GROUP BY asn', []) as $o) $orgs[(int) $o['asn']] = (string) $o['org'];
        foreach ($worst as &$x) $x['org'] = $orgs[$x['asn']] ?? '';
        unset($x);
    }

    $out = [];
    foreach ($rows as $r) {
        $n = $info[$r['nkey']] ?? [];
        $out[] = [
            'nkey'   => (string) $r['nkey'],
            'name'   => (string) ($n['name'] ?? ''),
            'type'   => (string) ($n['type'] ?? ''),
            'server' => (string) ($n['server'] ?? ''),
            'port'   => (int) ($n['port'] ?? 0),
            'worst'  => $worst[$r['nkey']] ?? null,
        ] + rep_view_score($r);
    }

    usort($out, static function ($x, $y) {
        $fx = $x['fail'] ?? -1;
        $fy = $y['fail'] ?? -1;
        if ($fx !== $fy) return $fy <=> $fx;

        return $y['pn'] <=> $x['pn'];
    });

    return $out;
}

// Ход по времени: по часам за сутки и неделю, по суткам за месяц.
function rep_view_series(array $f, $since, $now = null) {
    $now = $now ?? time();
    [$w, $a] = rep_view_where($f, 'h', $since, ['kind', 'plat', 'cc', 'node']);
    $step = $f['p'] > 7 ? 86400 : 3600;
    $from = intdiv($since, $step) * $step;
    $to = intdiv($now, $step) * $step;

    $slots = [];
    for ($t = $from; $t <= $to; $t += $step) $slots[$t] = [];
    foreach (rep_view_rows('SELECT h, ' . rep_view_sums() . " FROM rep_hour WHERE $w GROUP BY h ORDER BY h", $a) as $r) {
        $t = intdiv((int) $r['h'], $step) * $step;
        if (!isset($slots[$t])) continue;
        foreach ($r as $c => $v) {
            if ($c === 'h') continue;
            $slots[$t][$c] = ($slots[$t][$c] ?? 0) + (int) $v;
        }
    }

    $out = [];
    foreach ($slots as $t => $s) $out[] = ['t' => $t] + rep_view_score($s);

    return ['step' => $step, 'points' => $out];
}

// Узлы панели по адресу: адрес узла или хоста (как в подписке) → имя узла и страна.
// Из API панели, раз в полчаса; без панели — пусто.
function rep_panel_index($maxAge = 1800) {
    $now = time();
    $ts = (int) setting('rep_panel_ts', '0');
    $cached = json_decode((string) setting('rep_panel_json', ''), true);
    if (is_array($cached) && $ts > 0 && $now - $ts <= $maxAge) return $cached;
    if (remnawave_url() === '' || remnawave_token() === '') return is_array($cached) ? $cached : [];

    $list = static function ($data, $key) {
        $r = $data['response'] ?? $data;
        if (!is_array($r)) return [];
        if (isset($r[$key]) && is_array($r[$key])) return $r[$key];

        return isset($r[0]) ? $r : [];
    };

    [$nok, , $nd, ] = remnawave_api_get('/api/nodes');
    if (!$nok) {
        set_setting('rep_panel_ts', (string) $now);

        return is_array($cached) ? $cached : [];
    }

    $nodes = [];
    $out = [];
    foreach ($list($nd, 'nodes') as $n) {
        if (!is_array($n)) continue;
        $one = ['name' => rep_str((string) ($n['name'] ?? ''), 64), 'cc' => strtoupper(rep_str((string) ($n['countryCode'] ?? ''), 2))];
        if (!empty($n['uuid'])) $nodes[(string) $n['uuid']] = $one;
        $addr = strtolower(trim((string) ($n['address'] ?? '')));
        if ($addr !== '') $out[$addr][] = $one;
    }

    [$hok, , $hd, ] = remnawave_api_get('/api/hosts');
    $tr = [];
    if ($hok) {
        $ib = [];
        [$iok, , $id, ] = remnawave_api_get('/api/config-profiles/inbounds');
        if ($iok) {
            foreach ($list($id, 'inbounds') as $in) {
                if (is_array($in) && !empty($in['uuid'])) $ib[(string) $in['uuid']] = $in;
            }
        }
        foreach ($list($hd, 'hosts') as $host) {
            if (!is_array($host)) continue;
            $addr = strtolower(trim((string) ($host['address'] ?? '')));
            $in = $ib[(string) ($host['inbound']['configProfileInboundUuid'] ?? '')] ?? null;
            if ($addr !== '' && is_array($in)) {
                $sl  = strtoupper((string) ($host['securityLayer'] ?? 'DEFAULT'));
                $sec = $sl === 'TLS' ? 'tls' : ($sl === 'NONE' ? 'none' : strtolower((string) ($in['security'] ?? '')));
                $one = ['t' => rep_str((string) ($in['type'] ?? ''), 24), 'n' => rep_str((string) ($in['network'] ?? ''), 24), 's' => rep_str($sec, 16)];
                $tk  = $addr . '|' . (int) ($host['port'] ?? 0);
                if (!in_array($one, $tr[$tk] ?? [], true)) $tr[$tk][] = $one;
            }
            if ($addr === '' || isset($out[$addr])) continue;
            foreach ((array) ($host['nodes'] ?? []) as $ref) {
                $id = is_array($ref) ? (string) ($ref['uuid'] ?? $ref['nodeUuid'] ?? '') : (string) $ref;
                if ($id !== '' && isset($nodes[$id])) $out[$addr][] = $nodes[$id];
            }
        }
    }

    set_setting('rep_panel_json', json_encode($out, JSON_UNESCAPED_UNICODE));
    if ($hok) set_setting('rep_panel_tr', json_encode($tr, JSON_UNESCAPED_UNICODE));
    set_setting('rep_panel_ts', (string) $now);

    return $out;
}

function rep_panel_tr() {
    $j = json_decode((string) setting('rep_panel_tr', ''), true);

    return is_array($j) ? $j : [];
}

function rep_proto_label($type, $server = '', $port = 0, ?array $tr = null) {
    static $cache = null;
    if ($tr === null) $tr = $cache ?? ($cache = rep_panel_tr());
    $hits  = $tr[strtolower((string) $server) . '|' . (int) $port] ?? [];
    if (!is_array($hits)) $hits = [];
    if (isset($hits['t'])) $hits = [$hits];
    $fam   = static fn($x) => ['hy2' => 'hysteria', 'hysteria2' => 'hysteria', 'ss' => 'shadowsocks'][strtolower((string) $x)] ?? strtolower((string) $x);
    $hit   = null;
    foreach ($hits as $h) {
        if (!is_array($h)) continue;
        if ((string) $type === '' ? count($hits) === 1 : $fam($h['t'] ?? '') === $fam($type)) { $hit = $h; break; }
    }
    $t     = strtolower((string) ((string) $type !== '' ? $type : (is_array($hit) ? ($hit['t'] ?? '') : '')));
    $names = ['vless' => 'VLESS', 'vmess' => 'VMess', 'trojan' => 'Trojan', 'shadowsocks' => 'Shadowsocks', 'ss' => 'Shadowsocks',
              'hysteria2' => 'Hysteria2', 'hy2' => 'Hysteria2', 'hysteria' => 'Hysteria', 'tuic' => 'TUIC', 'wireguard' => 'WireGuard',
              'socks' => 'SOCKS', 'http' => 'HTTP', 'anytls' => 'AnyTLS', 'ssh' => 'SSH', 'mieru' => 'Mieru'];
    $out   = $names[$t] ?? strtoupper($t);
    $udp   = in_array($t, ['hysteria2', 'hy2', 'hysteria', 'tuic', 'wireguard'], true);
    if (is_array($hit)) {
        $nets = ['tcp' => 'RAW (TCP)', 'raw' => 'RAW (TCP)', 'ws' => 'WebSocket', 'grpc' => 'gRPC', 'xhttp' => 'XHTTP', 'splithttp' => 'XHTTP',
                 'httpupgrade' => 'HTTPUpgrade', 'kcp' => 'mKCP', 'mkcp' => 'mKCP', 'quic' => 'QUIC', 'h2' => 'HTTP/2', 'http' => 'HTTP/2'];
        $n = strtolower((string) ($hit['n'] ?? ''));
        if ($n !== '' && !$udp) $out .= ' ' . ($nets[$n] ?? strtoupper($n));
        $sec = strtolower((string) ($hit['s'] ?? ''));
        if ($sec === 'reality') $out .= ' · Reality';
        elseif ($sec === 'tls' && !$udp) $out .= ' · TLS';
    }

    return $udp ? $out . ' (UDP)' : $out;
}

function rep_geo_backfill($since, $cap = 3000, $short = null) {
    if (!function_exists('geoip_city_ready') || !geoip_city_ready() || !rep_new_cols()) return 0;

    return rep_geo_backfill_state($since, $cap, $short) + rep_geo_backfill_ips($since, $cap, $short);
}

function rep_geo_backfill_state($since, $cap = 3000, $short = null) {
    if (!function_exists('geoip_city_ready') || !geoip_city_ready() || !rep_new_cols() || !($p = db())) return 0;
    try {
        $st = $p->prepare("SELECT id, ip4, ip6, loc, sub FROM rep_state WHERE (loc = '' OR sub = '') AND (ip4 <> '' OR ip6 <> '') AND last_seen >= ?" . ($short !== null ? ' AND short_uuid = ?' : '') . ' LIMIT 20000');
        $st->execute($short !== null ? [(int) $since, (string) $short] : [(int) $since]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return 0; }
    if (!$rows) return 0;
    $by = [];
    foreach ($rows as $r) {
        $ip = $r['ip4'] !== '' ? (string) $r['ip4'] : (string) $r['ip6'];
        if (!isset($by[$ip]) && count($by) >= $cap) continue;
        $by[$ip][] = $r;
    }
    $n = 0;
    try {
        $p->beginTransaction();
        $up = $p->prepare('UPDATE rep_state SET loc = ?, sub = ? WHERE id = ?');
        foreach ($by as $ip => $list) {
            $g   = geoip_lookup($ip);
            $loc = (string) ($g['loc'] ?? '') !== '' ? (string) $g['loc'] : '-';
            $sub = (string) ($g['sub'] ?? '') !== '' ? (string) $g['sub'] : '-';
            foreach ($list as $r) {
                $up->execute([(string) $r['loc'] !== '' ? (string) $r['loc'] : $loc, (string) $r['sub'] !== '' ? (string) $r['sub'] : $sub, (int) $r['id']]);
                $n++;
            }
        }
        $p->commit();
    } catch (Throwable $e) {
        if ($p->inTransaction()) $p->rollBack();
        error_log('submw rep geo backfill: ' . $e->getMessage());
    }

    return $n;
}

// То же для адресов сетей (rep_net_ip): без региона они не участвуют в выборе места.
function rep_geo_backfill_ips($since, $cap = 3000, $short = null) {
    if (!($p = db())) return 0;
    try {
        $st = $p->prepare("SELECT id, ip, loc, sub FROM rep_net_ip WHERE (loc = '' OR sub = '') AND last_h >= ?" . ($short !== null ? ' AND short_uuid = ?' : '') . ' LIMIT 20000');
        $st->execute($short !== null ? [(int) $since, (string) $short] : [(int) $since]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return 0; }
    if (!$rows) return 0;
    $by = [];
    foreach ($rows as $r) {
        if (!isset($by[$r['ip']]) && count($by) >= $cap) continue;
        $by[(string) $r['ip']][] = $r;
    }
    $n = 0;
    try {
        $p->beginTransaction();
        $up = $p->prepare('UPDATE rep_net_ip SET loc = ?, sub = ? WHERE id = ?');
        foreach ($by as $ip => $list) {
            $g   = geoip_lookup((string) $ip);
            $loc = (string) ($g['loc'] ?? '') !== '' ? (string) $g['loc'] : '-';
            $sub = (string) ($g['sub'] ?? '') !== '' ? (string) $g['sub'] : '-';
            foreach ($list as $r) {
                $up->execute([(string) $r['loc'] !== '' ? (string) $r['loc'] : $loc, (string) $r['sub'] !== '' ? (string) $r['sub'] : $sub, (int) $r['id']]);
                $n++;
            }
        }
        $p->commit();
    } catch (Throwable $e) {
        if ($p->inTransaction()) $p->rollBack();
        error_log('submw rep geo backfill ips: ' . $e->getMessage());
    }

    return $n;
}

function rep_dev_label($meta) {
    $m  = is_array($meta) ? $meta : json_decode((string) $meta, true);
    $dv = is_array($m['dv'] ?? null) ? $m['dv'] : [];
    $os = trim((string) ($dv['o'] ?? ''));
    $v  = trim((string) ($dv['v'] ?? ''));
    if ($v !== '' && stripos($os, $v) === false) $os = trim($os . ' ' . $v);

    return [rep_str((string) ($dv['m'] ?? ''), 64), rep_str($os, 48)];
}

function rep_dev_fill($short = null, $cap = 200) {
    if (!rep_new_cols() || !($p = db())) return 0;
    if ($short === null) {
        if (time() - (int) setting('rep_dev_fill_ts', '0') < 600) return 0;
        set_setting('rep_dev_fill_ts', (string) time());
    }
    if (!db_has_cols($p, 'request_log', ['hwid', 'meta'])) return 0;
    $sql  = "SELECT short_uuid, hwid FROM rep_dev WHERE model = '' AND hwid <> '' AND hwid NOT LIKE '~%'" . ($short !== null ? ' AND short_uuid = ?' : '') . ' ORDER BY last_report DESC LIMIT ' . (int) $cap;
    $devs = rep_view_rows($sql, $short !== null ? [(string) $short] : []);
    if (!$devs) return 0;
    $n = 0;
    try {
        $q  = $p->prepare('SELECT meta FROM request_log WHERE hwid = ? AND meta LIKE ? ORDER BY id DESC LIMIT 1');
        $up = $p->prepare("UPDATE rep_dev SET model = ?, os = ? WHERE short_uuid = ? AND hwid = ? AND model = ''");
        foreach ($devs as $d) {
            $q->execute([(string) $d['hwid'], '%"dv"%']);
            $meta = $q->fetchColumn();
            $q->closeCursor();
            [$m, $o] = is_string($meta) && $meta !== '' ? rep_dev_label($meta) : ['', ''];
            // Не нашлось — «-», чтобы очередь шла дальше; модель из следующего отчёта всё равно запишется.
            $up->execute([$m !== '' ? $m : '-', $o, (string) $d['short_uuid'], (string) $d['hwid']]);
            $n++;
        }
    } catch (Throwable $e) { error_log('submw rep dev fill: ' . $e->getMessage()); }

    return $n;
}

function rep_view_devmap() {
    $out = [];
    foreach (rep_view_rows('SELECT short_uuid, hwid, plat, client, ' . rep_sel(['model', 'os']) . ', reports, last_report FROM rep_dev', []) as $r) {
        $out[$r['short_uuid'] . '|' . $r['hwid']] = $r;
    }

    return $out;
}

function rep_view_nodelist(array $panel = []) {
    $out = [];
    foreach (rep_view_rows('SELECT nkey, type, server, port, name FROM rep_node', []) as $n) {
        $hit = $panel[strtolower((string) $n['server'])] ?? [];
        $out[$n['nkey']] = [
            'k'  => (string) $n['nkey'],
            'nm' => (string) ($n['name'] !== '' ? $n['name'] : $n['server']),
            't'  => rep_proto_label((string) $n['type'], (string) $n['server'], (int) $n['port']),
            'a'  => $n['server'] . ':' . (int) $n['port'],
            'cc' => is_array($hit) && isset($hit[0]['cc']) ? (string) $hit[0]['cc'] : '',
        ];
    }

    return $out;
}

// Где устройство на самом деле. У одного устройства один регион, а скачки адреса
// между регионами — шум базы адресов (динамические пулы провайдера заведены на
// разные регионы). Берётся место, где устройство мерило больше всего часов;
// домашние сети (Wi-Fi, кабель) важнее мобильной: мобильный оператор выводит
// трафик через узловой город. Сеть в другой стране остаётся на своём месте.
function rep_place_key($cc, $sub, $loc) {
    $sub = (string) $sub === '-' ? '' : (string) $sub;
    $loc = (string) $loc === '-' ? '' : (string) $loc;
    if ($sub !== '') return $cc . '|' . strtolower($sub);
    if ($loc !== '' && strpos($loc, ',') !== false) {
        [$la, $lo] = array_map('floatval', explode(',', $loc, 2));

        return $cc . '|' . round($la) . ',' . round($lo);
    }

    return $cc . '|';
}

function rep_place_pick(array $items) {
    // Адреса, о которых база знает только страну, не перевешивают известный регион.
    $known = array_filter($items, static fn($x) => $x['cc'] !== '' && (trim((string) $x['sub'], '-') !== '' || trim((string) $x['loc'], '-') !== ''));
    if ($known) $items = $known;
    $home = array_filter($items, static fn($x) => in_array($x['k'], ['wifi', 'wired'], true) && $x['cc'] !== '');
    $pool = $home ?: array_filter($items, static fn($x) => $x['cc'] !== '');
    if (!$pool) return null;
    $g = [];
    foreach ($pool as $x) {
        $key = rep_place_key($x['cc'], $x['sub'], $x['loc']);
        if (!isset($g[$key])) $g[$key] = ['w' => 0, 'last' => -1, 'x' => null];
        $g[$key]['w'] += max(1, (int) $x['w']);
        if ((int) $x['last'] > $g[$key]['last'] || ($g[$key]['x']['loc'] ?? '') === '') { $g[$key]['last'] = (int) $x['last']; $g[$key]['x'] = $x; }
    }
    uasort($g, static fn($a, $b) => ($b['w'] <=> $a['w']) ?: ($b['last'] <=> $a['last']));
    $win = reset($g);
    $tw  = array_sum(array_column($g, 'w'));

    return ['cc' => (string) $win['x']['cc'], 'sub' => (string) $win['x']['sub'] === '-' ? '' : (string) $win['x']['sub'], 'loc' => (string) $win['x']['loc'] === '-' ? '' : (string) $win['x']['loc'],
            'src' => $home ? 'home' : 'any', 'w' => (int) $win['w'], 'tw' => (int) $tw, 'n' => count($g)];
}

// Места устройств по адресам их сетей за период; $shorts — только эти подписки.
function rep_view_dev_places($since, ?array $shorts = null) {
    $out = [];
    if (!rep_ensure() || !($p = db())) return $out;
    if ($shorts !== null && !$shorts) return $out;
    $sql = "SELECT short_uuid, hwid, kind, cc, sub, MAX(loc) AS loc, SUM(hours) AS hours, MAX(last_h) AS last_h FROM rep_net_ip WHERE last_h >= ?";
    $args = [(int) $since];
    if ($shorts !== null) {
        $shorts = array_values(array_unique(array_map('strval', $shorts)));
        $sql .= ' AND short_uuid IN (' . implode(',', array_fill(0, count($shorts), '?')) . ')';
        $args = array_merge($args, $shorts);
    }
    $sql .= " GROUP BY short_uuid, hwid, kind, cc, sub, CASE WHEN sub = '' OR sub = '-' THEN loc ELSE '' END";
    $items = [];
    try {
        $st = $p->prepare($sql);
        $st->execute($args);
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $items[$r['short_uuid'] . '|' . $r['hwid']][] = ['k' => (string) $r['kind'], 'cc' => (string) $r['cc'], 'sub' => (string) $r['sub'], 'loc' => (string) $r['loc'], 'w' => (int) $r['hours'], 'last' => (int) $r['last_h']];
        }
        $st->closeCursor();
    } catch (Throwable $e) { return $out; }
    foreach ($items as $dk => $list) {
        $pl = rep_place_pick($list);
        if ($pl !== null) $out[$dk] = $pl;
    }

    return $out;
}

// Ставит строки состояния (cc/loc/sub) одного устройства на его место; исходные
// координаты сети остаются в occ/ol/osb — карточка показывает адрес оператора.
function rep_place_rows(array &$rows, array $places, $shortKey = 's', $hwKey = 'hw') {
    $fall = [];
    foreach ($rows as $r) {
        $dk = $r[$shortKey] . '|' . $r[$hwKey];
        if (!isset($places[$dk])) $fall[$dk][] = ['k' => (string) $r['k'], 'cc' => (string) $r['cc'], 'sub' => (string) $r['sub'], 'loc' => (string) $r['loc'], 'w' => 1, 'last' => (int) $r['hh']];
    }
    foreach ($fall as $dk => $list) if (($pl = rep_place_pick($list)) !== null) $places[$dk] = $pl;
    foreach ($rows as &$r) {
        $r['occ'] = $r['cc'];
        $r['ol']  = $r['loc'];
        $r['osb'] = $r['sub'];
        $pl = $places[$r[$shortKey] . '|' . $r[$hwKey]] ?? null;
        if ($pl === null || ($r['cc'] !== '' && $r['cc'] !== $pl['cc']) || ($pl['loc'] === '' && $pl['sub'] === '')) continue;
        $r['cc']  = $pl['cc'];
        $r['loc'] = $pl['loc'];
        $r['sub'] = $pl['sub'];
    }
    unset($r);

    return $places;
}

function rep_view_map(array $f, $since, array $panel = [], $limit = 300000) {
    $out = ['nodes' => [], 'devs' => [], 'nets' => [], 'ms' => [], 'cut' => false];
    if (!rep_ensure() || !($p = db())) return $out;
    $nodes = rep_view_nodelist($panel);
    $nidx  = [];
    foreach ($nodes as $k => $n) { $nidx[$k] = count($out['nodes']); $out['nodes'][] = $n; }
    $devs = rep_view_devmap();
    [$w, $a] = rep_view_where($f, 'last_seen', $since, ['kind', 'cc', 'node']);
    $vcut = time() - REP_VIEW_VERDICT_TTL;
    $unbuf = db_driver() === 'mysql' && defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY');
    try {
        if ($unbuf) $p->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        $st = $p->prepare('SELECT short_uuid, hwid, net, nkey, kind, ip4, ip6, cc, asn, org, verdict, vat, pn, pf, pmed, last_seen, ' . rep_sel(['loc', 'hist_h', 'sub', 'rpn', 'rpf'])
            . " FROM rep_state WHERE $w ORDER BY last_seen DESC LIMIT " . ((int) $limit + 1));
        $st->execute($a);
    } catch (Throwable $e) {
        if ($unbuf) $p->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
        error_log('submw rep map: ' . $e->getMessage());
        return $out;
    }
    $didx = [];
    $nets = [];
    $rows = 0;
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        if (++$rows > $limit) { $out['cut'] = true; break; }
        $dk = $r['short_uuid'] . '|' . $r['hwid'];
        $dv = $devs[$dk] ?? null;
        if ($f['plat'] !== '' && ($dv['plat'] ?? '') !== $f['plat']) continue;
        if (!isset($didx[$dk])) {
            $didx[$dk] = count($out['devs']);
            $out['devs'][] = [
                's'  => (string) $r['short_uuid'],
                'p'  => (string) ($dv['plat'] ?? ''),
                'm'  => (string) ($dv['model'] ?? '') === '-' ? '' : (string) ($dv['model'] ?? ''),
                'o'  => (string) ($dv['os'] ?? ''),
                'c'  => (string) ($dv['client'] ?? ''),
                'lr' => (int) ($dv['last_report'] ?? 0),
            ];
        }
        $nk = $dk . '|' . $r['net'];
        if (!isset($nets[$nk])) {
            $nets[$nk] = ['dk' => $dk, 'd' => $didx[$dk], 'k' => (string) $r['kind'], 'ip' => '', 'cc' => '', 'asn' => 0, 'org' => '', 'loc' => '', 'sub' => '',
                          'pn' => 0, 'pf' => 0, 'b' => array_fill(0, REP_BUCKETS, 0), 'dead' => [], 'frz' => [], 'ms' => [], 'vok' => 0, 'hh' => 0, 'seen' => 0, 'ah' => -1];
        }
        $x = &$nets[$nk];
        $pn = (int) $r['pn'];
        $pf = (int) $r['pf'];
        $x['pn'] += $pn;
        $x['pf'] += $pf;
        $m = (int) $r['pmed'];
        if ($m >= 0 && $m < REP_BUCKETS && $pn > $pf) $x['b'][$m] += $pn - $pf;
        $ni = $nidx[$r['nkey']] ?? -1;
        if ($ni >= 0 && $pn > 0) $x['ms'][] = $ni;
        if ($ni >= 0 && rep_is_dead($r)) $x['dead'][] = $ni;
        $fresh = (int) $r['vat'] >= $vcut;
        if ($ni >= 0 && $fresh && $r['verdict'] === 'frozen') $x['frz'][] = $ni;
        if ($fresh && $r['verdict'] === 'ok') $x['vok']++;
        $x['hh']   = max($x['hh'], (int) $r['hist_h']);
        $x['seen'] = max($x['seen'], (int) $r['last_seen']);
        $ip = $r['ip4'] !== '' ? (string) $r['ip4'] : (string) $r['ip6'];
        if ($ip !== '' && (int) $r['hist_h'] >= $x['ah']) {
            $x['ah']  = (int) $r['hist_h'];
            $x['ip']  = $ip;
            $x['cc']  = (string) $r['cc'];
            $x['asn'] = (int) $r['asn'];
            $x['org'] = (string) $r['org'];
            $x['loc'] = (string) $r['loc'] === '-' ? '' : (string) $r['loc'];
            $x['sub'] = (string) $r['sub'] === '-' ? '' : (string) $r['sub'];
            $x['k']   = (string) $r['kind'];
        }
        unset($x);
    }
    $st->closeCursor();
    if ($unbuf) $p->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
    $places = rep_view_dev_places($since);
    $fall = [];
    foreach ($nets as $x) {
        if (!isset($places[$x['dk']])) $fall[$x['dk']][] = ['k' => $x['k'], 'cc' => $x['cc'], 'sub' => $x['sub'], 'loc' => $x['loc'], 'w' => 1, 'last' => $x['hh']];
    }
    foreach ($fall as $dk => $list) if (($pl = rep_place_pick($list)) !== null) $places[$dk] = $pl;
    foreach ($nets as &$x) {
        $pl = $places[$x['dk']] ?? null;
        if ($pl === null || ($x['cc'] !== '' && $x['cc'] !== $pl['cc']) || ($pl['loc'] === '' && $pl['sub'] === '')) continue;
        $x['cc']  = $pl['cc'];
        $x['loc'] = $pl['loc'];
        $x['sub'] = $pl['sub'];
    }
    unset($x);
    $sets = [];
    foreach ($nets as $x) {
        $ms = array_values(array_unique($x['ms']));
        sort($ms);
        $sk = implode(',', $ms);
        if (!isset($sets[$sk])) { $sets[$sk] = count($out['ms']); $out['ms'][] = $ms; }
        $out['nets'][] = [$x['d'], $x['k'], $x['ip'], $x['cc'], $x['asn'], $x['org'], $x['loc'], $x['pn'], $x['pf'],
                          rep_median_bucket($x['b']), array_values(array_unique($x['dead'])), array_values(array_unique($x['frz'])), $x['vok'], $x['hh'], $x['seen'], $sets[$sk], $x['sub']];
    }

    return $out;
}

function rep_view_matrix(array $f, $since, $cols = 40) {
    $out = ['cols' => [], 'cells' => [], 'total' => 0];
    $join = $f['plat'] !== '' ? ' JOIN rep_dev d ON d.short_uuid = s.short_uuid AND d.hwid = s.hwid' : '';
    [$w, $a] = rep_view_where($f, 's.last_seen', $since, ['kind', 'cc', 'node', 'plat']);
    $w = str_replace(['kind = ?', 'kind IN (', 'cc = ?', 'nkey = ?', 'plat = ?'], ['s.kind = ?', 's.kind IN (', 's.cc = ?', 's.nkey = ?', 'd.plat = ?'], $w);
    $isps = rep_view_rows('SELECT asn, MAX(cc) AS cc, MAX(org) AS org, COUNT(*) AS n FROM (SELECT s.asn, MAX(s.cc) AS cc, MAX(s.org) AS org FROM rep_state s'
        . $join . " WHERE $w AND s.asn > 0 GROUP BY s.asn, s.short_uuid, s.hwid) t GROUP BY asn ORDER BY n DESC, asn ASC LIMIT " . (int) $cols, $a);
    if (!$isps) return $out;
    $out['total'] = count($isps);
    if (count($isps) >= $cols) {
        $t = rep_view_rows('SELECT COUNT(DISTINCT s.asn) AS n FROM rep_state s' . $join . " WHERE $w AND s.asn > 0", $a);
        $out['total'] = (int) ($t[0]['n'] ?? count($isps));
    }
    $asns = [];
    foreach ($isps as $r) {
        $asns[] = (int) $r['asn'];
        $out['cols'][] = ['asn' => (int) $r['asn'], 'cc' => (string) $r['cc'], 'org' => (string) $r['org'], 'n' => (int) $r['n']];
    }
    $bs = [];
    for ($i = 0; $i < REP_BUCKETS; $i++) $bs[] = "SUM(CASE WHEN s.pmed = $i AND s.pn > s.pf THEN s.pn - s.pf ELSE 0 END) AS b$i";
    $rows = rep_view_rows('SELECT s.nkey, s.asn, COUNT(*) AS n, SUM(s.pn) AS pn, SUM(s.pf) AS pf,'
        . ' SUM(' . rep_dead_sql('s.') . ') AS dead,'
        . " SUM(CASE WHEN s.verdict = 'frozen' AND s.vat >= ? THEN 1 ELSE 0 END) AS ffr, " . implode(', ', $bs)
        . ' FROM rep_state s' . $join . " WHERE $w AND s.asn IN (" . implode(',', $asns) . ') GROUP BY s.nkey, s.asn', array_merge([time() - REP_VIEW_VERDICT_TTL], $a));
    foreach ($rows as $r) {
        $b = [];
        for ($i = 0; $i < REP_BUCKETS; $i++) $b[] = (int) $r['b' . $i];
        $pn = (int) $r['pn'];
        $out['cells'][$r['nkey'] . '|' . (int) $r['asn']] = [
            'n' => (int) $r['n'], 'pn' => $pn, 'pf' => (int) $r['pf'], 'dead' => (int) $r['dead'], 'ffr' => (int) $r['ffr'],
            'fail' => $pn >= REP_VIEW_MIN_PINGS ? (int) $r['pf'] / $pn : null, 'med' => rep_median_bucket($b),
        ];
    }

    return $out;
}

function rep_view_cell(array $f, $since, $nkey, $asn) {
    [$w, $a] = rep_view_where($f, 's.last_seen', $since, ['kind', 'cc', 'plat']);
    $w = str_replace(['kind = ?', 'kind IN (', 'cc = ?', 'plat = ?'], ['s.kind = ?', 's.kind IN (', 's.cc = ?', 'd.plat = ?'], $w);
    $rows = rep_view_rows('SELECT s.short_uuid, s.hwid, s.net, s.kind, s.ip4, s.ip6, s.cc, s.pn, s.pf, s.pmed, s.verdict, s.vat, s.last_seen, ' . rep_sel(['loc', 'hist', 'hist_h', 'sub', 'rpn', 'rpf'], 's.') . ','
        . ' d.plat, d.client, ' . rep_sel(['model', 'os'], 'd.') . ' FROM rep_state s LEFT JOIN rep_dev d ON d.short_uuid = s.short_uuid AND d.hwid = s.hwid'
        . " WHERE $w AND s.nkey = ? AND s.asn = ? ORDER BY s.last_seen DESC LIMIT 500", array_merge($a, [(string) $nkey, (int) $asn]));
    $out = [];
    foreach ($rows as $r) $out[] = rep_view_state_row($r);
    rep_place_rows($out, rep_view_dev_places($since, array_column($out, 's')));
    usort($out, static function ($x, $y) {
        if ($x['dead'] !== $y['dead']) return $y['dead'] <=> $x['dead'];
        return ($y['fail'] ?? -1) <=> ($x['fail'] ?? -1);
    });

    return $out;
}

function rep_view_state_row(array $r) {
    $pn = (int) $r['pn'];
    $pf = min((int) $r['pf'], $pn);
    if ((int) ($r['vat'] ?? 0) < time() - REP_VIEW_VERDICT_TTL) $r['verdict'] = '';

    return [
        's' => (string) $r['short_uuid'], 'hw' => (string) $r['hwid'], 'net' => (string) $r['net'], 'k' => (string) $r['kind'],
        'ip' => (string) ($r['ip4'] !== '' ? $r['ip4'] : $r['ip6']), 'ip6' => $r['ip4'] !== '' ? (string) $r['ip6'] : '',
        'cc' => (string) $r['cc'], 'loc' => (string) $r['loc'] === '-' ? '' : (string) $r['loc'], 'sub' => (string) ($r['sub'] ?? '') === '-' ? '' : (string) ($r['sub'] ?? ''), 'pn' => $pn, 'pf' => $pf, 'med' => (int) $r['pmed'],
        'fail' => $pn >= REP_VIEW_MIN_PINGS ? $pf / $pn : null, 'dead' => rep_is_dead($r) ? 1 : 0,
        'v' => (string) $r['verdict'], 'hist' => (string) $r['hist'], 'hh' => (int) $r['hist_h'], 'seen' => (int) $r['last_seen'],
        'p' => (string) ($r['plat'] ?? ''), 'm' => (string) ($r['model'] ?? '') === '-' ? '' : (string) ($r['model'] ?? ''), 'o' => (string) ($r['os'] ?? ''), 'c' => (string) ($r['client'] ?? ''),
    ];
}

function rep_view_client_card($q, $since) {
    $q = trim((string) $q);
    if ($q === '' || !rep_ensure()) return null;
    $hit = rep_view_rows('SELECT short_uuid FROM rep_dev WHERE short_uuid = ? OR hwid = ? ORDER BY last_report DESC LIMIT 1', [$q, $q]);
    if (!$hit) $hit = rep_view_rows('SELECT short_uuid FROM rep_state WHERE short_uuid = ? OR hwid = ? ORDER BY last_seen DESC LIMIT 1', [$q, $q]);
    if (!$hit) return null;
    $short = (string) $hit[0]['short_uuid'];
    rep_dev_fill($short, 20);
    rep_geo_backfill(0, 500, $short);
    $devs = [];
    foreach (rep_view_rows('SELECT hwid, plat, client, ' . rep_sel(['model', 'os']) . ', first_seen, last_report, last_h, reports FROM rep_dev WHERE short_uuid = ? ORDER BY last_report DESC', [$short]) as $d) {
        $devs[] = ['hw' => (string) $d['hwid'], 'p' => (string) $d['plat'], 'c' => (string) $d['client'], 'm' => (string) $d['model'] === '-' ? '' : (string) $d['model'], 'o' => (string) $d['os'],
                   'fs' => (int) $d['first_seen'], 'lr' => (int) $d['last_report'], 'lh' => (int) $d['last_h'], 'r' => (int) $d['reports']];
    }
    $rows = [];
    $asns = [];
    foreach (rep_view_rows('SELECT short_uuid, hwid, net, nkey, kind, ip4, ip6, cc, asn, org, pn, pf, pmed, verdict, vstatus, vat, up, down, last_seen, ' . rep_sel(['loc', 'hist', 'hist_h', 'sub', 'rpn', 'rpf', 'hc'])
        . ' FROM rep_state WHERE short_uuid = ? ORDER BY last_seen DESC LIMIT 3000', [$short]) as $r) {
        $x = rep_view_state_row($r);
        $x['n'] = (string) $r['nkey'];
        $x['asn'] = (int) $r['asn'];
        $x['org'] = (string) $r['org'];
        $x['hc'] = (string) $r['hc'];
        $x['tb'] = (int) $r['up'] + (int) $r['down'];
        $x['vs'] = $x['v'] !== '' ? (int) $r['vstatus'] : 0;
        $x['va'] = $x['v'] !== '' ? (int) $r['vat'] : 0;
        $rows[] = $x;
        if ((int) $r['asn'] > 0) $asns[(int) $r['asn']] = true;
        $known = false;
        foreach ($devs as $d) if ($d['hw'] === $x['hw']) $known = true;
        if (!$known) $devs[] = ['hw' => $x['hw'], 'p' => '', 'c' => '', 'm' => '', 'o' => '', 'fs' => 0, 'lr' => $x['seen'], 'r' => 0];
    }
    $places = rep_place_rows($rows, rep_view_dev_places($since, [$short]));
    foreach ($devs as &$d) {
        $pl = $places[$short . '|' . $d['hw']] ?? null;
        if ($pl !== null) $d['pl'] = ['cc' => $pl['cc'], 'sub' => $pl['sub'], 'loc' => $pl['loc'], 'src' => $pl['src'], 'w' => $pl['w'], 'tw' => $pl['tw'], 'n' => $pl['n']];
    }
    unset($d);
    $ips = [];
    foreach (rep_view_rows('SELECT hwid, net, ip, kind, cc, asn, org, sub, loc, hours, last_h FROM rep_net_ip WHERE short_uuid = ? AND last_h >= ? ORDER BY last_h DESC LIMIT 2000', [$short, (int) $since]) as $r) {
        $k = $r['hwid'] . '|' . $r['net'];
        if (count($ips[$k] ?? []) >= 40) continue;
        $ips[$k][] = ['ip' => (string) $r['ip'], 'cc' => (string) $r['cc'], 'asn' => (int) $r['asn'], 'org' => (string) $r['org'], 'sub' => (string) $r['sub'] === '-' ? '' : (string) $r['sub'],
                      'loc' => (string) $r['loc'] === '-' ? '' : (string) $r['loc'], 'h' => (int) $r['hours'], 'lh' => (int) $r['last_h']];
    }
    $peers = [];
    if ($asns) {
        $bs = [];
        for ($i = 0; $i < REP_BUCKETS; $i++) $bs[] = "SUM(CASE WHEN pmed = $i AND pn > pf THEN pn - pf ELSE 0 END) AS b$i";
        foreach (rep_view_rows('SELECT asn, nkey, COUNT(*) AS n, SUM(pn) AS pn, SUM(pf) AS pf, SUM(' . rep_dead_sql() . ') AS dead, '
            . implode(', ', $bs) . ' FROM rep_state WHERE asn IN (' . implode(',', array_keys($asns)) . ') AND short_uuid <> ? AND last_seen >= ? GROUP BY asn, nkey',
            [$short, (int) $since]) as $r) {
            $b = [];
            for ($i = 0; $i < REP_BUCKETS; $i++) $b[] = (int) $r['b' . $i];
            $pn = (int) $r['pn'];
            $peers[(int) $r['asn'] . '|' . $r['nkey']] = ['n' => (int) $r['n'], 'dead' => (int) $r['dead'], 'fail' => $pn >= REP_VIEW_MIN_PINGS ? (int) $r['pf'] / $pn : null, 'med' => rep_median_bucket($b)];
        }
    }

    $tz    = rep_tzoff();
    $today = rep_day_of(time(), $tz);
    $days  = [];
    foreach (rep_view_rows('SELECT hwid, net, nkey, d, kind, asn, org, ' . implode(', ', rep_day_cols()) . ', vl, vs, va FROM rep_dday WHERE short_uuid = ? AND d >= ? ORDER BY d DESC LIMIT 8000', [$short, $today - 13 * 86400]) as $r) {
        $b = [];
        for ($i = 0; $i < REP_BUCKETS; $i++) $b[] = (int) $r['b' . $i];
        $days[] = [
            'hw' => (string) $r['hwid'], 'net' => (string) $r['net'], 'n' => (string) $r['nkey'], 'd' => (int) $r['d'], 'k' => (string) $r['kind'], 'asn' => (int) $r['asn'], 'org' => (string) $r['org'],
            'pn' => (int) $r['pn'], 'pf' => min((int) $r['pf'], (int) $r['pn']), 'b' => $b, 'fok' => (int) $r['fok'], 'ffr' => (int) $r['ffr'], 'fdd' => (int) $r['fdd'],
            'tb' => (int) $r['up'] + (int) $r['down'], 'hrs' => (int) $r['hrs'], 'vl' => (string) $r['vl'], 'vs' => (int) $r['vs'], 'va' => (int) $r['va'],
        ];
    }

    return ['short' => $short, 'devs' => $devs, 'rows' => $rows, 'peers' => $peers, 'ips' => $ips, 'days' => $days, 'tz' => $tz, 'today' => $today];
}

function rep_view_frz(array $f, $since, $v, $nkey = '', $asn = 0) {
    $col = ['ok' => 'fok', 'fr' => 'ffr', 'dd' => 'fdd'][$v] ?? null;
    $out = ['rows' => [], 'src' => 'day'];
    if ($col === null || !rep_ensure()) return $out;
    $from = rep_day_of($since);
    $w = ["x.d >= ?", "x.$col > 0"];
    $a = [$from];
    if ($f['kind'] !== '') {
        $set = REP_VIEW_KIND_SETS[$f['kind']] ?? [$f['kind']];
        $w[] = 'x.kind IN (' . implode(', ', array_fill(0, count($set), '?')) . ')';
        array_push($a, ...$set);
    }
    if ($f['cc'] !== '') { $w[] = 'x.cc = ?'; $a[] = $f['cc']; }
    $nkey = (string) $nkey !== '' ? (string) $nkey : $f['node'];
    if ($nkey !== '') { $w[] = 'x.nkey = ?'; $a[] = $nkey; }
    if ((int) $asn > 0) { $w[] = 'x.asn = ?'; $a[] = (int) $asn; }
    $join = '';
    if ($f['plat'] !== '') { $join = ' JOIN rep_dev dv ON dv.short_uuid = x.short_uuid AND dv.hwid = x.hwid AND dv.plat = ?'; array_unshift($a, $f['plat']); }
    $rows = rep_view_rows("SELECT x.short_uuid, x.hwid, x.net, x.nkey, MAX(x.kind) AS kind, MAX(x.asn) AS asn, MAX(x.org) AS org, SUM(x.$col) AS n, SUM(x.fok + x.ffr + x.fdd) AS t, MAX(x.d) AS ld, COUNT(*) AS days"
        . " FROM rep_dday x$join WHERE " . implode(' AND ', $w) . ' GROUP BY x.short_uuid, x.hwid, x.net, x.nkey ORDER BY n DESC, ld DESC LIMIT 300', $a);
    if (!$rows && !rep_view_rows('SELECT 1 AS x FROM rep_dday WHERE d >= ? LIMIT 1', [$from])) {
        $out['src'] = 'state';
        $vn = ['fok' => 'ok', 'ffr' => 'frozen', 'fdd' => 'dead'][$col];
        $w = ['s.verdict = ?', 's.vat >= ?'];
        $a = [$vn, (int) $since];
        if ($nkey !== '') { $w[] = 's.nkey = ?'; $a[] = $nkey; }
        if ((int) $asn > 0) { $w[] = 's.asn = ?'; $a[] = (int) $asn; }
        $rows = rep_view_rows('SELECT s.short_uuid, s.hwid, s.net, s.nkey, s.kind, s.asn, s.org, 1 AS n, 1 AS t, s.vat AS ld, 1 AS days FROM rep_state s WHERE ' . implode(' AND ', $w) . ' ORDER BY s.vat DESC LIMIT 300', $a);
    }
    if (!$rows) return $out;
    $devs = [];
    $shorts = array_values(array_unique(array_map('strval', array_column($rows, 'short_uuid'))));
    foreach (rep_view_rows('SELECT short_uuid, hwid, plat, client, ' . rep_sel(['model']) . ' FROM rep_dev WHERE short_uuid IN (' . implode(', ', array_fill(0, count($shorts), '?')) . ')', $shorts) as $d) {
        $devs[$d['short_uuid'] . '|' . $d['hwid']] = $d;
    }
    foreach ($rows as $r) {
        $d = $devs[$r['short_uuid'] . '|' . $r['hwid']] ?? [];
        $out['rows'][] = [
            's' => (string) $r['short_uuid'], 'hw' => (string) $r['hwid'], 'net' => (string) $r['net'], 'n' => (string) $r['nkey'], 'k' => (string) $r['kind'],
            'asn' => (int) $r['asn'], 'org' => (string) $r['org'], 'c' => (int) $r['n'], 't' => (int) $r['t'], 'ld' => (int) $r['ld'], 'days' => (int) $r['days'],
            'm' => (string) ($d['model'] ?? '') === '-' ? '' : (string) ($d['model'] ?? ''), 'p' => (string) ($d['plat'] ?? ''), 'cl' => (string) ($d['client'] ?? ''),
        ];
    }

    return $out;
}

function rep_view_news($limit = 12) {
    $out = ['items' => [], 'fresh' => 0];
    if (!rep_ensure()) return $out;
    $seen   = (int) setting('rep_seen_at', '0');
    $unseen = rep_unseen();
    $devs   = rep_view_rows('SELECT short_uuid, hwid, plat, client, ' . rep_sel(['model', 'os']) . ', last_report FROM rep_dev ORDER BY last_report DESC LIMIT ' . max(1, (int) $limit), []);
    if (!$devs) return $out;
    $shorts = array_values(array_unique(array_map('strval', array_column($devs, 'short_uuid'))));
    $st = [];
    $rows = rep_view_rows('SELECT s.short_uuid, s.hwid, s.nkey, MAX(' . rep_dead_sql('s.') . ') AS dead, MAX(CASE WHEN s.verdict = ? AND s.vat >= ? THEN 1 ELSE 0 END) AS frz'
        . ' FROM rep_state s JOIN rep_dev d ON d.short_uuid = s.short_uuid AND d.hwid = s.hwid AND s.last_seen = d.last_report'
        . ' WHERE s.short_uuid IN (' . implode(', ', array_fill(0, count($shorts), '?')) . ') GROUP BY s.short_uuid, s.hwid, s.nkey',
        array_merge(['frozen', time() - REP_VIEW_VERDICT_TTL], $shorts));
    foreach ($rows as $r) {
        $k = $r['short_uuid'] . '|' . $r['hwid'];
        $st[$k]['dead'] = ($st[$k]['dead'] ?? 0) + (int) $r['dead'];
        $st[$k]['frz']  = ($st[$k]['frz'] ?? 0) + (int) $r['frz'];
    }
    $names = chan_names_map();
    foreach ($devs as $i => $d) {
        $k   = $d['short_uuid'] . '|' . $d['hwid'];
        $new = $seen > 0 ? (int) $d['last_report'] > $seen : $i < $unseen;
        if ($new) $out['fresh']++;
        $out['items'][] = [
            's' => (string) $d['short_uuid'], 'nm' => (string) ($names[$d['short_uuid']] ?? ''),
            'm' => (string) $d['model'] === '-' ? '' : (string) $d['model'], 'o' => (string) $d['os'], 'p' => (string) $d['plat'], 'c' => (string) $d['client'],
            't' => (int) $d['last_report'], 'dead' => (int) ($st[$k]['dead'] ?? 0), 'frz' => (int) ($st[$k]['frz'] ?? 0), 'nw' => $new ? 1 : 0,
        ];
    }

    return $out;
}
