<?php

// Трафик пользователя по дням и нодам — статистика панели (/api/bandwidth-stats/users):
// до 3.0 адрес по uuid пользователя, с 3.0 — по числовому id; формат ответа одинаковый.

function usage_scope() { return 'bandwidth-stats:user-usage'; }

function usage_days_opts() { return [7, 30, 90]; }

function usage_days($d) {
    $d = (int) $d;
    return in_array($d, usage_days_opts(), true) ? $d : 30;
}

function usage_path($ref, $days) {
    $end = gmdate('Y-m-d');
    $start = gmdate('Y-m-d', time() - (usage_days($days) - 1) * 86400);
    return '/api/bandwidth-stats/users/' . rawurlencode((string) $ref) . '?start=' . $start . '&end=' . $end . '&topNodesLimit=100';
}

function usage_fetch($ref, $days, &$err = '') {
    $err = '';
    $ref = rw_ref_coerce($ref);
    if (!rw_ref_ok($ref)) { $err = 'Пустой идентификатор пользователя'; return null; }
    [$ok, $code, $data, $e] = remnawave_api_get(usage_path($ref['val'], $days));
    if (!$ok) {
        $msg = is_array($data) ? (string) ($data['message'] ?? '') : '';
        if ($code === 404 && strpos($msg, 'Cannot GET') === 0) $err = 'Панель не отдаёт статистику трафика по этому адресу — нужна Remnawave 2.7 или новее';
        else $err = $msg !== '' ? $msg : ($e ?: ('HTTP ' . $code));
        return null;
    }
    $r = is_array($data) ? ($data['response'] ?? null) : null;
    if (!is_array($r)) { $err = 'Панель вернула неожиданный ответ'; return null; }
    $known = function_exists('sess_nodes_fresh') ? sess_nodes_fresh() : [];
    $cats = array_values(array_map('strval', (array) ($r['categories'] ?? [])));
    $n = count($cats);
    $series = [];
    $tot = array_fill(0, $n, 0);
    foreach ((array) ($r['series'] ?? []) as $s) {
        if (!is_array($s)) continue;
        $nu = (string) ($s['uuid'] ?? '');
        $vals = array_values(array_map('intval', (array) ($s['data'] ?? [])));
        $vals = array_slice(array_pad($vals, $n, 0), 0, $n);
        $total = (int) ($s['total'] ?? array_sum($vals));
        if ($total <= 0 && !array_filter($vals)) continue;
        foreach ($vals as $i => $v) $tot[$i] += $v;
        $col = (string) ($s['color'] ?? '');
        $series[] = [
            'node'  => $nu,
            'c'     => preg_match('/^#[0-9a-f]{6}$/i', $col) ? strtolower($col) : '',
            'name'  => mb_substr((string) ($s['name'] ?? ($known[$nu]['n'] ?? '')), 0, 64),
            'cc'    => strtoupper(mb_substr((string) ($s['countryCode'] ?? ($known[$nu]['cc'] ?? '')), 0, 2)),
            'h'     => array_values((array) ($known[$nu]['h'] ?? [])),
            'total' => $total,
            'data'  => $vals,
        ];
    }
    if (!$series) {
        $sp = array_values(array_map('intval', (array) ($r['sparklineData'] ?? [])));
        if (count($sp) === $n) $tot = $sp;
    }
    usort($series, fn($a, $b) => $b['total'] <=> $a['total']);
    return ['days' => $cats, 'tot' => $tot, 'series' => $series, 'sum' => array_sum($tot), 'd' => usage_days($days), 'ts' => time()];
}
