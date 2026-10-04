<?php

// Экран «Статистика» Clod Clash: выборки из сводок отчётов клиентов (lib/reports.php).
// Только чтение; фильтры — период, тип сети, платформа, страна клиента, узел.

const REP_VIEW_PERIODS = [1, 7, 30];
const REP_VIEW_KINDS   = ['wifi' => 'Wi-Fi', 'mobile' => 'Мобильная', 'wired' => 'Кабель', 'other' => 'Другая'];
const REP_VIEW_PLATS   = ['pc' => 'ПК', 'android' => 'Android'];
// Ниже стольких пингов доля неудач — шум, а не оценка.
const REP_VIEW_MIN_PINGS = 20;

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

function rep_view_countries(array $f, $since) {
    $g = $f;
    $g['cc'] = '';
    [$w, $a] = rep_view_where($g, 'h', $since, ['kind', 'plat', 'node']);
    $out = [];
    foreach (rep_view_rows('SELECT cc, ' . rep_view_sums() . " FROM rep_hour WHERE $w AND cc <> '' GROUP BY cc", $a) as $r) {
        $out[$r['cc']] = rep_view_score($r);
    }

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

// Состояние по клиенту: по shortUuid или HWID — сети, адреса, узлы, последние вердикты.
function rep_view_client($q) {
    if ($q === '') return [];

    return rep_view_rows('SELECT short_uuid, hwid, net, nkey, kind, ip4, ip6, cc, asn, org, verdict, vstatus, vat, pn, pf, pmed, up, down, last_seen'
        . ' FROM rep_state WHERE short_uuid = ? OR hwid = ? ORDER BY last_seen DESC LIMIT 500', [$q, $q]);
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
