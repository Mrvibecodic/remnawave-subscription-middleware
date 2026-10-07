<?php

// Экран «Статистика» Clod Clash: выборки из сводок отчётов клиентов (lib/reports.php).
// Только чтение; фильтры — период, тип сети, платформа, страна клиента, узел.

const REP_VIEW_PERIODS = [1, 7, 30];
const REP_VIEW_KINDS   = ['wifi' => 'Wi-Fi', 'mobile' => 'Мобильная', 'wired' => 'Кабель', 'other' => 'Другая'];
const REP_VIEW_PLATS   = ['pc' => 'ПК', 'android' => 'Android'];
// Ниже стольких пингов доля неудач — шум, а не оценка.
const REP_VIEW_MIN_PINGS = 20;
const REP_VIEW_VERDICT_TTL = 259200;

function rep_view_filters(array $q) {
    $p = (int) ($q['p'] ?? 7);
    $cc = strtoupper((string) ($q['cc'] ?? ''));
    $node = (string) ($q['node'] ?? '');

    return [
        'p'    => in_array($p, REP_VIEW_PERIODS, true) ? $p : 7,
        'kind' => isset(REP_VIEW_KINDS[$q['kind'] ?? '']) ? (string) $q['kind'] : '',
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
function rep_view_where(array $f, $time, $since, array $cols) {
    $sql = ["$time >= ?"];
    $args = [$since];
    foreach (['kind', 'plat', 'cc', 'node'] as $k) {
        if ($f[$k] === '' || !in_array($k, $cols, true)) continue;
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

function rep_view_totals(array $f, $since) {
    [$w, $a] = rep_view_where($f, 'h', $since, ['kind', 'plat', 'cc', 'node']);
    $rows = rep_view_rows('SELECT ' . rep_view_sums() . ", COUNT(DISTINCT nkey) AS nodes FROM rep_hour WHERE $w", $a);
    $out = rep_view_score($rows[0] ?? []);
    $out['nodes'] = (int) ($rows[0]['nodes'] ?? 0);

    [$w, $a] = rep_view_where($f, 'last_seen', $since, ['kind', 'cc', 'node']);
    $dev = rep_view_rows("SELECT COUNT(*) AS n FROM (SELECT short_uuid, hwid FROM rep_state WHERE $w GROUP BY short_uuid, hwid) t", $a);
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
    foreach (rep_view_rows('SELECT nkey, cc, asn, ' . rep_view_sums() . " FROM rep_hour WHERE $w GROUP BY nkey, cc, asn", $a) as $r) {
        $s = rep_view_score($r);
        if ($s['fail'] === null) continue;
        if (!isset($worst[$r['nkey']]) || $s['fail'] > $worst[$r['nkey']]['fail']) {
            $worst[$r['nkey']] = ['cc' => (string) $r['cc'], 'asn' => (int) $r['asn'], 'fail' => $s['fail'], 'pn' => $s['pn']];
        }
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
    if ($hok) {
        foreach ($list($hd, 'hosts') as $host) {
            if (!is_array($host)) continue;
            $addr = strtolower(trim((string) ($host['address'] ?? '')));
            if ($addr === '' || isset($out[$addr])) continue;
            foreach ((array) ($host['nodes'] ?? []) as $ref) {
                $id = is_array($ref) ? (string) ($ref['uuid'] ?? $ref['nodeUuid'] ?? '') : (string) $ref;
                if ($id !== '' && isset($nodes[$id])) $out[$addr][] = $nodes[$id];
            }
        }
    }

    set_setting('rep_panel_json', json_encode($out, JSON_UNESCAPED_UNICODE));
    set_setting('rep_panel_ts', (string) $now);

    return $out;
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
            't'  => (string) $n['type'],
            'a'  => $n['server'] . ':' . (int) $n['port'],
            'cc' => is_array($hit) && isset($hit[0]['cc']) ? (string) $hit[0]['cc'] : '',
        ];
    }

    return $out;
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
        $st = $p->prepare('SELECT short_uuid, hwid, net, nkey, kind, ip4, ip6, cc, asn, org, verdict, vat, pn, pf, pmed, last_seen, ' . rep_sel(['loc', 'hist_h'])
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
                'm'  => (string) ($dv['model'] ?? ''),
                'o'  => (string) ($dv['os'] ?? ''),
                'c'  => (string) ($dv['client'] ?? ''),
                'lr' => (int) ($dv['last_report'] ?? 0),
            ];
        }
        $nk = $dk . '|' . $r['net'];
        if (!isset($nets[$nk])) {
            $nets[$nk] = ['d' => $didx[$dk], 'k' => (string) $r['kind'], 'ip' => '', 'cc' => '', 'asn' => 0, 'org' => '', 'loc' => '',
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
        if ($ni >= 0 && $pn > 0 && $pf >= $pn) $x['dead'][] = $ni;
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
            $x['loc'] = (string) $r['loc'];
            $x['k']   = (string) $r['kind'];
        }
        unset($x);
    }
    $st->closeCursor();
    if ($unbuf) $p->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
    $sets = [];
    foreach ($nets as $x) {
        $ms = array_values(array_unique($x['ms']));
        sort($ms);
        $sk = implode(',', $ms);
        if (!isset($sets[$sk])) { $sets[$sk] = count($out['ms']); $out['ms'][] = $ms; }
        $out['nets'][] = [$x['d'], $x['k'], $x['ip'], $x['cc'], $x['asn'], $x['org'], $x['loc'], $x['pn'], $x['pf'],
                          rep_median_bucket($x['b']), array_values(array_unique($x['dead'])), array_values(array_unique($x['frz'])), $x['vok'], $x['hh'], $x['seen'], $sets[$sk]];
    }

    return $out;
}

function rep_view_matrix(array $f, $since, $cols = 12) {
    $out = ['cols' => [], 'cells' => []];
    $join = $f['plat'] !== '' ? ' JOIN rep_dev d ON d.short_uuid = s.short_uuid AND d.hwid = s.hwid' : '';
    [$w, $a] = rep_view_where($f, 's.last_seen', $since, ['kind', 'cc', 'node', 'plat']);
    $w = str_replace(['kind = ?', 'cc = ?', 'nkey = ?', 'plat = ?'], ['s.kind = ?', 's.cc = ?', 's.nkey = ?', 'd.plat = ?'], $w);
    $isps = rep_view_rows('SELECT asn, MAX(cc) AS cc, MAX(org) AS org, COUNT(*) AS n FROM (SELECT s.asn, MAX(s.cc) AS cc, MAX(s.org) AS org FROM rep_state s'
        . $join . " WHERE $w AND s.asn > 0 GROUP BY s.asn, s.short_uuid, s.hwid) t GROUP BY asn ORDER BY n DESC, asn ASC LIMIT " . (int) $cols, $a);
    if (!$isps) return $out;
    $asns = [];
    foreach ($isps as $r) {
        $asns[] = (int) $r['asn'];
        $out['cols'][] = ['asn' => (int) $r['asn'], 'cc' => (string) $r['cc'], 'org' => (string) $r['org'], 'n' => (int) $r['n']];
    }
    $bs = [];
    for ($i = 0; $i < REP_BUCKETS; $i++) $bs[] = "SUM(CASE WHEN s.pmed = $i AND s.pn > s.pf THEN s.pn - s.pf ELSE 0 END) AS b$i";
    $rows = rep_view_rows('SELECT s.nkey, s.asn, COUNT(*) AS n, SUM(s.pn) AS pn, SUM(s.pf) AS pf,'
        . ' SUM(CASE WHEN s.pn > 0 AND s.pf >= s.pn THEN 1 ELSE 0 END) AS dead,'
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
    $w = str_replace(['kind = ?', 'cc = ?', 'plat = ?'], ['s.kind = ?', 's.cc = ?', 'd.plat = ?'], $w);
    $rows = rep_view_rows('SELECT s.short_uuid, s.hwid, s.net, s.kind, s.ip4, s.ip6, s.cc, s.pn, s.pf, s.pmed, s.verdict, s.vat, s.last_seen, ' . rep_sel(['loc', 'hist', 'hist_h'], 's.') . ','
        . ' d.plat, d.client, ' . rep_sel(['model', 'os'], 'd.') . ' FROM rep_state s LEFT JOIN rep_dev d ON d.short_uuid = s.short_uuid AND d.hwid = s.hwid'
        . " WHERE $w AND s.nkey = ? AND s.asn = ? ORDER BY s.last_seen DESC LIMIT 500", array_merge($a, [(string) $nkey, (int) $asn]));
    $out = [];
    foreach ($rows as $r) $out[] = rep_view_state_row($r);
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
        'cc' => (string) $r['cc'], 'loc' => (string) $r['loc'], 'pn' => $pn, 'pf' => $pf, 'med' => (int) $r['pmed'],
        'fail' => $pn >= REP_VIEW_MIN_PINGS ? $pf / $pn : null, 'dead' => $pn > 0 && $pf >= $pn ? 1 : 0,
        'v' => (string) $r['verdict'], 'hist' => (string) $r['hist'], 'hh' => (int) $r['hist_h'], 'seen' => (int) $r['last_seen'],
        'p' => (string) ($r['plat'] ?? ''), 'm' => (string) ($r['model'] ?? ''), 'o' => (string) ($r['os'] ?? ''), 'c' => (string) ($r['client'] ?? ''),
    ];
}

function rep_view_client_card($q, $since) {
    $q = trim((string) $q);
    if ($q === '' || !rep_ensure()) return null;
    $hit = rep_view_rows('SELECT short_uuid FROM rep_dev WHERE short_uuid = ? OR hwid = ? ORDER BY last_report DESC LIMIT 1', [$q, $q]);
    if (!$hit) $hit = rep_view_rows('SELECT short_uuid FROM rep_state WHERE short_uuid = ? OR hwid = ? ORDER BY last_seen DESC LIMIT 1', [$q, $q]);
    if (!$hit) return null;
    $short = (string) $hit[0]['short_uuid'];
    $devs = [];
    foreach (rep_view_rows('SELECT hwid, plat, client, ' . rep_sel(['model', 'os']) . ', first_seen, last_report, reports FROM rep_dev WHERE short_uuid = ? ORDER BY last_report DESC', [$short]) as $d) {
        $devs[] = ['hw' => (string) $d['hwid'], 'p' => (string) $d['plat'], 'c' => (string) $d['client'], 'm' => (string) $d['model'], 'o' => (string) $d['os'],
                   'fs' => (int) $d['first_seen'], 'lr' => (int) $d['last_report'], 'r' => (int) $d['reports']];
    }
    $rows = [];
    $asns = [];
    foreach (rep_view_rows('SELECT short_uuid, hwid, net, nkey, kind, ip4, ip6, cc, asn, org, pn, pf, pmed, verdict, vat, last_seen, ' . rep_sel(['loc', 'hist', 'hist_h'])
        . ' FROM rep_state WHERE short_uuid = ? ORDER BY last_seen DESC LIMIT 3000', [$short]) as $r) {
        $x = rep_view_state_row($r);
        $x['n'] = (string) $r['nkey'];
        $x['asn'] = (int) $r['asn'];
        $x['org'] = (string) $r['org'];
        $rows[] = $x;
        if ((int) $r['asn'] > 0) $asns[(int) $r['asn']] = true;
        $known = false;
        foreach ($devs as $d) if ($d['hw'] === $x['hw']) $known = true;
        if (!$known) $devs[] = ['hw' => $x['hw'], 'p' => '', 'c' => '', 'm' => '', 'o' => '', 'fs' => 0, 'lr' => $x['seen'], 'r' => 0];
    }
    $peers = [];
    if ($asns) {
        $bs = [];
        for ($i = 0; $i < REP_BUCKETS; $i++) $bs[] = "SUM(CASE WHEN pmed = $i AND pn > pf THEN pn - pf ELSE 0 END) AS b$i";
        foreach (rep_view_rows('SELECT asn, nkey, COUNT(*) AS n, SUM(pn) AS pn, SUM(pf) AS pf, SUM(CASE WHEN pn > 0 AND pf >= pn THEN 1 ELSE 0 END) AS dead, '
            . implode(', ', $bs) . ' FROM rep_state WHERE asn IN (' . implode(',', array_keys($asns)) . ') AND short_uuid <> ? AND last_seen >= ? GROUP BY asn, nkey',
            [$short, (int) $since]) as $r) {
            $b = [];
            for ($i = 0; $i < REP_BUCKETS; $i++) $b[] = (int) $r['b' . $i];
            $pn = (int) $r['pn'];
            $peers[(int) $r['asn'] . '|' . $r['nkey']] = ['n' => (int) $r['n'], 'dead' => (int) $r['dead'], 'fail' => $pn >= REP_VIEW_MIN_PINGS ? (int) $r['pf'] / $pn : null, 'med' => rep_median_bucket($b)];
        }
    }

    return ['short' => $short, 'devs' => $devs, 'rows' => $rows, 'peers' => $peers];
}
