<?php
if (isset($_COOKIE['tzoff']) && (string) setting('rep_tzoff', '') === '') set_setting('rep_tzoff', (string) (max(-720, min(840, (int) $_COOKIE['tzoff'])) * 60));
$rv_f      = rep_view_filters($_GET);
$rv_now    = time();
$rv_since  = rep_view_since($rv_f, $rv_now);
$rv_tot    = rep_view_totals($rv_f, $rv_since);
$rv_series = rep_view_series($rv_f, $rv_since, $rv_now);
$rv_isps   = rep_view_isps($rv_f, $rv_since);
$rv_nodes  = rep_view_nodes($rv_f, $rv_since);
$rv_panel  = rep_panel_index();
rep_geo_backfill($rv_since);
rep_dev_fill();
$rv_mapd   = rep_view_map($rv_f, $rv_since, $rv_panel);
$rv_mx     = rep_view_matrix($rv_f, $rv_since);
$rv_hst    = rep_view_hist_stat($rv_since);
$rep_st    = rep_stats();
$rep_geo   = geoip_status();
$rv_node   = null;
foreach ($rv_nodes as $n) if ($n['nkey'] === $rv_f['node']) $rv_node = $n;
if ($rv_f['node'] !== '' && $rv_node === null) {
    foreach (rep_view_rows('SELECT nkey, type, server, port, name FROM rep_node WHERE nkey = ?', [$rv_f['node']]) as $n) {
        $rv_node = ['nkey' => $n['nkey'], 'name' => $n['name'], 'type' => $n['type'], 'server' => $n['server'], 'port' => (int) $n['port']];
    }
}

$rv_dead_node = [];
$rv_dead_cl   = [];
foreach ($rv_mapd['nets'] as $x) {
    if (!$x[10]) continue;
    $rv_dead_cl[$rv_mapd['devs'][$x[0]]['s']] = true;
    foreach ($x[10] as $ni) $rv_dead_node[$rv_mapd['nodes'][$ni]['k']][$x[0]] = true;
}
$rv_names = [];
$rv_all_names = chan_names_map();
foreach ($rv_mapd['devs'] as $d) $rv_names[$d['s']] = (string) ($rv_all_names[$d['s']] ?? '');

$rv_url = function (array $over = []) use ($rv_f) {
    $q = ['tab' => 'reports'] + array_filter(array_merge(['p' => $rv_f['p'], 'kind' => $rv_f['kind'], 'plat' => $rv_f['plat'], 'cc' => $rv_f['cc'], 'node' => $rv_f['node']], $over), static fn($v) => $v !== '' && $v !== null);

    return '?' . http_build_query($q);
};
$rv_pct = static fn($x) => $x === null ? '—' : (($x * 100 < 10 ? number_format($x * 100, 1, ',', ' ') : (string) round($x * 100)) . ' %');
$rv_num = static fn($n) => number_format((int) $n, 0, ',', ' ');
$rv_bytes = static function ($b) {
    $b = (int) $b;
    foreach (['Б', 'КБ', 'МБ', 'ГБ', 'ТБ'] as $i => $u) {
        if ($b < 1024 ** ($i + 1) || $u === 'ТБ') return ($i === 0 ? $b : number_format($b / 1024 ** $i, $b / 1024 ** $i < 10 ? 1 : 0, ',', ' ')) . ' ' . $u;
    }
};
$rv_med = static fn($m) => [-1 => '—', 0 => '< 100 мс', 1 => '100–200 мс', 2 => '200–400 мс', 3 => '400–800 мс', 4 => '0,8–1,5 с', 5 => '> 1,5 с'][$m] ?? '—';
$rv_qfail = static function ($x) {
    if ($x === null) return 'q0';
    if ($x < .01) return 'q1';
    if ($x < .03) return 'q2';
    if ($x < .10) return 'q3';
    if ($x < .25) return 'q4';
    return 'q5';
};
$rv_qmed = static fn($m) => $m < 0 ? 'q0' : 'q' . min(5, $m + 1);
$rv_frz = static function ($s) {
    if ($s['fok'] + $s['ffr'] + $s['fdd'] === 0) return '<span class="muted">—</span>';
    $out = [];
    if ($s['ffr'] > 0) $out[] = '<span class="rq q4" title="Режется">✂ ' . (int) $s['ffr'] . '</span>';
    if ($s['fdd'] > 0) $out[] = '<span class="rq q5" title="Не отвечает">✕ ' . (int) $s['fdd'] . '</span>';
    if ($s['fok'] > 0) $out[] = '<span class="rq q1" title="Работает">✓ ' . (int) $s['fok'] . '</span>';

    return implode(' ', $out);
};
$rv_frzb = static function ($s, $nk = '', $asn = 0) use ($rv_frz) {
    if ($s['fok'] + $s['ffr'] + $s['fdd'] === 0) return $rv_frz($s);
    $at = ($nk !== '' ? ' data-nk="' . h($nk) . '"' : '') . ((int) $asn > 0 ? ' data-asn="' . (int) $asn . '"' : '');
    $b  = static fn($v, $q, $ic, $n, $t) => '<button type="button" class="rq ' . $q . ' rv-frzbtn" data-v="' . $v . '"' . $at . ' data-tip="' . h($t . ': ' . $n . ' — показать устройства') . '">' . $ic . ' ' . (int) $n . '</button>';
    $out = [];
    if ($s['ffr'] > 0) $out[] = $b('fr', 'q4', '✂', $s['ffr'], 'Режется');
    if ($s['fdd'] > 0) $out[] = $b('dd', 'q5', '✕', $s['fdd'], 'Не отвечает');
    if ($s['fok'] > 0) $out[] = $b('ok', 'q1', '✓', $s['fok'], 'Работает');

    return implode(' ', $out);
};
$rv_flag = static function ($cc) {
    if (!preg_match('~^[A-Z]{2}$~', (string) $cc)) return '';

    return '<span class="rv-fl">' . mb_chr(0x1F1E6 + ord($cc[0]) - 65) . mb_chr(0x1F1E6 + ord($cc[1]) - 65) . '</span>';
};
$rv_nflag = static fn($cc, $name) => preg_match('~^\s*[\x{1F1E6}-\x{1F1FF}]{2}~u', (string) $name) ? '' : $rv_flag($cc);
$rv_panel_of = static function ($server) use ($rv_panel) {
    $hit = $rv_panel[strtolower((string) $server)] ?? [];

    return is_array($hit) ? $hit : [];
};

$rv_per_t = [1 => 'за 24 часа', 7 => 'за 7 дней', 30 => 'за 30 дней'][$rv_f['p']] ?? 'за период';
$rv_win = static function ($w) use ($rv_per_t) {
    $t = [
        'p'  => [$rv_per_t, 'Сумма всех часов с замерами за выбранный период, с учётом фильтров сети и платформы.'],
        'pd' => [$rv_per_t, 'Считается целыми сутками UTC, поэтому захватывает и начало первых суток периода. Фильтр платформы здесь не применяется.'],
        '48' => ['последние 48 ч устройств', 'В каждой сети устройства — 48 часов до её последнего часа с замерами, а не весь период. Период лишь отбирает сети, по которым пришёл отчёт. Проверка 16–20 — последняя, не старше 3 суток.'],
        '6'  => ['по последним отчётам', 'Молчание узла — по последним (до 6) часам с замерами в каждой сети устройства: ни одного ответа за эти часы. «Режется» — по последней проверке 16–20 не старше 3 суток. Отчёт приходит не чаще раза в 6 часов, при плановом обновлении подписки, так что это не «прямо сейчас».'],
    ][$w];

    return '<span class="rv-win" data-tip="' . h($t[1]) . '"><svg class="rvi"><use href="#rvi-clock"/></svg>' . h($t[0]) . '</span>';
};

$rv_max = 1;
foreach ($rv_series['points'] as $pt) $rv_max = max($rv_max, $pt['pn']);
$rv_js = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE;
?>
    <style>
    .rvp{--rq1:#10b981;--rq2:#84cc16;--rq3:#f59e0b;--rq4:#f97316;--rq5:#ef4444}
    .rvp button,.rvp input{font-family:inherit}
    .rvp .btn{display:inline-flex;align-items:center;gap:.4rem}
    .rvp .btn svg.rvi{flex:0 0 auto}
    .rv-deadbtn,.rv-frzbtn{border:0;cursor:pointer;min-height:0;height:auto;padding:.08rem .45rem;font-family:inherit}
    .rv-deadbtn:hover,.rv-frzbtn:hover{filter:brightness(1.15)}
    .rv-days{display:flex;flex-wrap:wrap;gap:.35rem;margin:.1rem 0 .7rem}
    .rv-day{display:inline-flex;align-items:center;gap:.35rem;padding:.3rem .65rem;min-height:0;height:auto;border:1px solid var(--line);border-radius:999px;background:transparent;color:var(--text);font-family:inherit;font-size:.78rem;font-weight:500;cursor:pointer}
    .rv-day:hover{background:var(--hover);filter:none}
    .rv-day.on{background:var(--accent-light);border-color:var(--accent);color:var(--accent-text);font-weight:600}
    .rv-day small{color:var(--muted);font-size:.7rem;font-weight:500}
    .rv-day i{width:7px;height:7px;border-radius:50%;flex:none;background:var(--rq1)}
    .rv-day i.st-warn{background:var(--rq3)}.rv-day i.st-bad{background:var(--rq5)}.rv-day i.st-mut{background:var(--line)}
    .rv-sect label.off{opacity:.55;cursor:default}
    .rv-mxd table.rv-lt tr.cur td{background:var(--accent-light)}
    .rv-vb{display:inline-flex;gap:.25rem;flex-wrap:wrap}
    .rv-mod{position:fixed;left:50%;top:50%;transform:translate(-50%,-50%);width:min(440px,94vw);max-height:80vh;display:none;flex-direction:column;background:var(--card);border:1px solid var(--line);border-radius:14px;box-shadow:0 18px 48px rgba(0,0,0,.4);z-index:92;overflow:hidden}
    .rv-mod.open{display:flex}
    .rv-flt{display:flex;flex-wrap:wrap;gap:.5rem;align-items:center}
    .rv-flt select{width:auto;height:38px;min-height:38px;padding-top:0;padding-bottom:0;font-size:.84rem}
    .rv-flt .sp{flex:1}
    .rv-flt input[type=search],.rv-flt #rvQ{width:16rem;flex:0 1 16rem;min-width:9rem;height:38px;min-height:38px}
    #rvFind{flex-wrap:nowrap}
    #rvFind .btn,#rvFind button{flex:none;white-space:nowrap}
    .rv-seg{display:inline-flex;border:1px solid var(--line);border-radius:10px;overflow:hidden;background:var(--bg2)}
    .rv-seg a,.rv-seg button{padding:.45rem .8rem;font-size:.84rem;color:var(--text);text-decoration:none;border:0;border-left:1px solid var(--line);background:transparent;border-radius:0;min-height:0;height:auto;font-weight:500;cursor:pointer}
    .rv-seg a:first-child,.rv-seg button:first-child{border-left:0}
    .rv-seg button:hover{background:var(--hover);filter:none}
    .rv-seg a.on,.rv-seg button.on,.rv-seg button.on:hover{background:var(--accent-light);color:var(--accent-text);font-weight:600}
    .rv-chip{display:inline-flex;align-items:center;gap:.35rem;padding:.3rem .6rem;border:1px solid var(--accent);border-radius:999px;background:var(--accent-light);color:var(--accent-text);font-size:.82rem;text-decoration:none}
    .rv-kpis{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:.7rem;margin:1rem 0 0}
    .rv-kpi{border:1px solid var(--line);background:var(--bg2);border-radius:12px;padding:.75rem .9rem;display:flex;flex-direction:column;gap:.15rem}
    .rv-kpi .k{font-size:.72rem;color:var(--muted);text-transform:uppercase;letter-spacing:.04em}
    .rv-kpi .v{font-size:1.45rem;font-weight:700;color:var(--text-strong);font-variant-numeric:tabular-nums;line-height:1.2}
    .rv-kpi .v.bad{color:var(--c-bad-fg)}
    .rv-kpi .d{font-size:.76rem;color:var(--muted)}
    @media(max-width:1300px){.rv-kpis{grid-template-columns:repeat(3,minmax(0,1fr))}}
    @media(max-width:700px){.rv-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}}
    .rq{display:inline-flex;align-items:center;gap:.2rem;padding:.08rem .45rem;border-radius:999px;font-size:.78rem;font-weight:600;font-variant-numeric:tabular-nums;white-space:nowrap}
    .rq.q0{color:var(--muted)}
    .rq.q1{background:color-mix(in srgb,var(--rq1) 16%,transparent);color:var(--rq1)}
    .rq.q2{background:color-mix(in srgb,var(--rq2) 16%,transparent);color:var(--rq2)}
    .rq.q3{background:color-mix(in srgb,var(--rq3) 18%,transparent);color:var(--rq3)}
    .rq.q4{background:color-mix(in srgb,var(--rq4) 18%,transparent);color:var(--rq4)}
    .rq.q5{background:color-mix(in srgb,var(--rq5) 18%,transparent);color:var(--rq5)}
    .rv-fl{font-family:'Twemoji Country Flags',sans-serif;font-size:1.05em;line-height:1}
    .rv-chart{width:100%;height:170px;display:block}
    .rv-chart .ok{fill:var(--accent);opacity:.55}
    .rv-chart .bad{fill:var(--rq5);opacity:.85}
    .rv-chart .ax{fill:var(--muted);font-size:10px}
    .rv-chart .gl{stroke:var(--line);stroke-width:1}
    .rv-legend{display:flex;gap:.6rem;flex-wrap:wrap;align-items:center;font-size:.78rem;color:var(--muted);margin-top:.5rem}
    .rv-legend i{display:inline-block;width:.8rem;height:.8rem;border-radius:3px;margin-right:.25rem;vertical-align:-1px}
    .rv-legend .dl{display:inline-block;width:.75rem;height:.75rem;border-radius:99px;margin-right:.3rem;vertical-align:-1px}
    .rv-legend .sv{width:1px;height:14px;background:var(--line)}
    .rv-tbl{width:100%;font-size:.86rem}
    .rv-tbl th{font-size:.71rem;white-space:nowrap}
    .rv-tbl td{vertical-align:top}
    .rv-tbl .num{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}
    .rv-tbl .sub{display:block;color:var(--muted);font-size:.76rem}
    .rv-tbl code{font-size:.8rem}
    .rv-tbl details summary{cursor:pointer;list-style:none}
    .rv-tbl details summary::-webkit-details-marker{display:none}
    .rv-ips{margin:.4rem 0 .2rem;width:100%;font-size:.8rem}
    .rv-ips td{padding:.25rem .4rem;border-top:1px solid var(--line)}
    .rv-wrap{overflow-x:auto}
    .rv-wrap .rv-tbl thead th{position:static}
    .rv-node a{color:var(--text-strong);font-weight:600;text-decoration:none}
    .rv-node a:hover{color:var(--accent-text)}
    .ctg{display:grid;grid-template-columns:1fr 1fr;gap:.55rem;margin-top:.7rem}
    .ctg .set-row{margin:0}
    @media(max-width:1000px){.ctg{grid-template-columns:1fr}}
    svg.rvi{width:15px;height:15px;flex:0 0 auto;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;vertical-align:-3px}
    .rv-mgrid{display:grid;grid-template-columns:minmax(0,1fr) 300px;gap:1rem;align-items:start}
    @media(max-width:1180px){.rv-mgrid{grid-template-columns:1fr}}
    .rv-mapbox{position:relative;border:1px solid var(--line);border-radius:14px;background:var(--bg2);overflow:hidden}
    .rv-mapbox svg.rv-map{display:block;width:100%;height:auto;aspect-ratio:1000/540}
    .rv-mapbox{touch-action:pan-y;cursor:grab;user-select:none;-webkit-user-select:none}
    .rv-mapbox.dragging{cursor:grabbing}
    .rv-mapbox.dragging .rv-dot{pointer-events:none}
    .rv-zoom{position:absolute;right:.6rem;top:.6rem;z-index:6;display:flex;flex-direction:column;border:1px solid var(--line);border-radius:10px;overflow:hidden;background:var(--card);box-shadow:0 2px 10px rgba(0,0,0,.25)}
    .rv-zoom button{width:34px;height:34px;min-height:0;padding:0;border:0;border-top:1px solid var(--line);border-radius:0;background:transparent;color:var(--text);display:flex;align-items:center;justify-content:center;cursor:pointer}
    .rv-zoom button:first-child{border-top:0}
    .rv-zoom button:hover{background:var(--hover);filter:none}
    .rv-zoom button:disabled{opacity:.35;cursor:default;background:transparent}
    .rv-zoom button[hidden]{display:none}
    .rv-zhint{position:absolute;left:50%;bottom:.7rem;transform:translateX(-50%);z-index:7;background:var(--card);border:1px solid var(--line);border-radius:999px;padding:.35rem .8rem;font-size:.78rem;color:var(--text);box-shadow:var(--shadow);opacity:0;pointer-events:none;transition:opacity .2s;white-space:nowrap;max-width:calc(100% - 1rem);overflow:hidden;text-overflow:ellipsis}
    .rv-zhint.on{opacity:1}
    .rv-map path.rg{fill:var(--line);fill-opacity:.55;stroke:var(--card);stroke-width:.5;vector-effect:non-scaling-stroke}
    .rv-map path.rg.has{cursor:pointer;fill:var(--accent);fill-opacity:.32}
    .rv-map path.rg.has.q1,.rv-map path.rg.has.q2,.rv-map path.rg.has.q3,.rv-map path.rg.has.q4,.rv-map path.rg.has.q5{fill-opacity:.55}
    .rv-map path.rg.has:hover{fill-opacity:.8}
    .rv-map path.rg.q1{fill:var(--rq1)}.rv-map path.rg.q2{fill:var(--rq2)}.rv-map path.rg.q3{fill:var(--rq3)}.rv-map path.rg.q4{fill:var(--rq4)}.rv-map path.rg.q5{fill:var(--rq5)}
    .rv-map path.cb{fill:none;stroke:var(--muted);stroke-opacity:.6;stroke-width:1;vector-effect:non-scaling-stroke;pointer-events:none}
    .rv-dots{position:absolute;inset:0;pointer-events:none}
    .rv-dot{position:absolute;transform:translate(-50%,-50%);pointer-events:auto;border:2px solid var(--card);border-radius:999px;display:flex;align-items:center;justify-content:center;font-weight:700;font-variant-numeric:tabular-nums;padding:0;min-height:0;height:auto;line-height:1;box-shadow:0 2px 8px rgba(0,0,0,.35);cursor:pointer}
    .rv-dot:hover{transform:translate(-50%,-50%) scale(1.12);z-index:3;filter:none}
    .rv-dot.st-ok:hover{background:var(--rq1)}.rv-dot.st-warn:hover{background:var(--rq3)}.rv-dot.st-bad:hover{background:var(--rq5)}
    .rv-dot.st-ok{background:var(--rq1);color:#04241c}
    .rv-dot.st-warn{background:var(--rq3);color:#2a1a02}
    .rv-dot.st-bad{background:var(--rq5);color:#fff}
    .rv-dot.st-bad::after{content:"";position:absolute;inset:-6px;border-radius:999px;border:2px solid var(--rq5);opacity:.45}
    .rv-dot.sel{outline:3px solid var(--text-strong);outline-offset:2px;z-index:4}
    .rv-dot.s{width:22px;height:22px;font-size:.66rem}
    .rv-dot.m{width:28px;height:28px;font-size:.72rem}
    .rv-dot.l{width:36px;height:36px;font-size:.8rem}
    .rv-tip{position:absolute;pointer-events:none;background:var(--card);border:1px solid var(--line);border-radius:10px;padding:.5rem .65rem;font-size:.8rem;box-shadow:0 6px 22px rgba(0,0,0,.2);display:none;z-index:8;min-width:11rem}
    .rv-tip b{color:var(--text-strong)}
    .rv-pop{position:absolute;width:340px;max-width:calc(100% - 16px);display:none;flex-direction:column;background:var(--card);border:1px solid var(--line);border-radius:14px;box-shadow:0 18px 48px rgba(0,0,0,.35);z-index:9;overflow:hidden}
    .rv-pop.open{display:flex}
    .rv-ph{padding:.7rem .85rem .55rem;border-bottom:1px solid var(--line);display:flex;gap:.5rem;align-items:flex-start}
    .rv-ph b{color:var(--text-strong);font-size:.95rem}
    .rv-ph .x{margin-left:auto;border:0;background:transparent;color:var(--muted);padding:.2rem;border-radius:6px;min-height:0;height:auto}
    .rv-ph .x:hover{background:var(--hover);filter:none}
    .rv-pst{display:flex;gap:.35rem;flex-wrap:wrap;margin-top:.35rem}
    .rv-pl{overflow:auto}
    .rv-crow{display:flex;gap:.6rem;padding:.55rem .85rem;border:0;border-bottom:1px solid var(--line);align-items:flex-start;cursor:pointer;width:100%;text-align:left;background:transparent;color:var(--text);border-radius:0;min-height:0;height:auto;font-weight:400}
    .rv-crow:hover{background:var(--hover2);filter:none}
    .rv-crow:last-child{border-bottom:0}
    .rv-crow .av{width:30px;height:30px;border-radius:9px;background:var(--bg2);border:1px solid var(--line);display:flex;align-items:center;justify-content:center;color:var(--muted);flex:0 0 auto;font-size:.72rem;font-weight:700}
    .rv-crow .bd{flex:1;min-width:0}
    .rv-crow .nm{font-weight:600;color:var(--text-strong);font-size:.86rem;display:flex;gap:.4rem;align-items:center;flex-wrap:wrap}
    .rv-crow .nm code{font-weight:400;color:var(--muted);font-size:.74rem;background:none;padding:0}
    .rv-crow .sub{font-size:.74rem;color:var(--muted);display:flex;gap:.35rem;align-items:center;flex-wrap:wrap;margin-top:.12rem}
    .rv-crow .st{display:block;margin-top:.3rem;min-width:0}
    .rv-crow .st .rv-pill{white-space:normal;max-width:100%;line-height:1.3}
    .rv-crow > svg.rvi{margin-top:.4rem}
    .rv-pill{display:inline-flex;align-items:center;gap:.3rem;padding:.1rem .5rem;border-radius:999px;font-size:.76rem;font-weight:600;white-space:nowrap;font-variant-numeric:tabular-nums}
    .rv-pill.st-ok{background:var(--c-ok-bg);color:var(--c-ok-fg)}
    .rv-pill.st-warn{background:var(--c-warn-bg);color:var(--c-warn-fg)}
    .rv-pill.st-bad{background:var(--c-bad-bg);color:var(--c-bad-fg)}
    .rv-pill.st-info{background:var(--c-info-bg);color:var(--c-info-fg)}
    .rv-pill.st-mut{background:var(--hover);color:var(--muted)}
    .rv-side{display:flex;flex-direction:column;gap:.6rem}
    .rv-sbox{border:1px solid var(--line);border-radius:14px;background:var(--bg2);overflow:hidden}
    .rv-sh{display:flex;align-items:center;gap:.5rem;padding:.65rem .8rem;border-bottom:1px solid var(--line)}
    .rv-sh b{color:var(--text-strong);font-size:.9rem;flex:1}
    .rv-srow{display:flex;align-items:center;gap:.55rem;padding:.5rem .8rem;border:0;border-bottom:1px solid var(--line);cursor:pointer;background:transparent;color:var(--text);width:100%;text-align:left;border-radius:0;min-height:0;height:auto;font-weight:400}
    .rv-srow:hover{background:var(--hover2);filter:none}
    .rv-srow.on:hover{background:var(--accent-light)}
    .rv-srow.on{background:var(--accent-light)}
    .rv-srow .n{flex:1;min-width:0;font-size:.85rem}
    .rv-srow .n small{display:block;color:var(--muted);font-size:.72rem}
    .rv-srow .c{font-variant-numeric:tabular-nums;font-weight:600;font-size:.85rem;color:var(--text-strong)}
    .rv-sexp{background:var(--card);border-bottom:1px solid var(--line)}
    .rv-sdot{width:9px;height:9px;border-radius:99px;flex:0 0 auto;background:var(--muted)}
    .rv-sdot.st-ok{background:var(--rq1)}.rv-sdot.st-warn{background:var(--rq3)}.rv-sdot.st-bad{background:var(--rq5)}
    .rv-cc{display:inline-flex;align-items:center;justify-content:center;min-width:1.9rem;height:1.25rem;padding:0 .3rem;border-radius:5px;border:1px solid var(--line);font-size:.66rem;font-weight:700;color:var(--muted)}
    .rv-inc{display:flex;gap:.75rem;padding:.75rem 0;border-top:1px solid var(--line);align-items:flex-start}
    .rv-inc:first-child{border-top:0}
    .rv-inc .ic{width:32px;height:32px;border-radius:10px;display:flex;align-items:center;justify-content:center;flex:0 0 auto}
    .rv-inc .ic.st-bad{background:var(--c-bad-bg);color:var(--c-bad-fg)}
    .rv-inc .ic.st-warn{background:var(--c-warn-bg);color:var(--c-warn-fg)}
    .rv-inc .ic.st-info{background:var(--c-info-bg);color:var(--c-info-fg)}
    .rv-inc .t{font-weight:600;color:var(--text-strong);font-size:.9rem}
    .rv-inc .why{font-size:.8rem;color:var(--muted);margin-top:.2rem;line-height:1.45}
    .rv-inc .why b{color:var(--text)}
    .rv-inc .act{display:flex;gap:.4rem;margin-top:.45rem;flex-wrap:wrap}
    .rv-tag{font-size:.68rem;font-weight:700;padding:.12rem .45rem;border-radius:6px;text-transform:uppercase;letter-spacing:.04em}
    .rv-tag.isp,.rv-tag.node{background:var(--c-bad-bg);color:var(--c-bad-fg)}
    .rv-tag.dev{background:var(--c-info-bg);color:var(--c-info-fg)}
    .rv-tag.frz{background:var(--c-warn-bg);color:var(--c-warn-fg)}
    .rv-tag.far{background:var(--hover);color:var(--muted)}
    table.rv-mx{border-collapse:separate;border-spacing:3px;font-size:.8rem}
    table.rv-mx th{text-transform:none;letter-spacing:0;font-size:.7rem;color:var(--muted);font-weight:600;text-align:center;padding:.25rem .3rem;vertical-align:bottom;white-space:nowrap;background:none;position:static}
    table.rv-mx th.rh{text-align:left}
    .rv-mxbar{display:flex;gap:.6rem;align-items:center;flex-wrap:wrap;margin:.2rem 0 .7rem}
    .rv-mxbar .muted{font-size:.78rem}
    .rv-mxs{overscroll-behavior-x:contain;padding-bottom:.25rem}
    .rvp table.rv-mx{display:table;overflow:visible}
    table.rv-mxw{table-layout:fixed;width:max-content;border-spacing:8px 4px;margin-left:-8px}
    table.rv-mxw th.pc{width:118px;min-width:118px;max-width:118px}
    table.rv-mxw th.pc .o{color:var(--text);margin:.15rem auto 0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    table.rv-mx th.rh,table.rv-mx td.rh{position:sticky;left:0;top:auto;z-index:2;background:var(--card);box-shadow:4px 0 0 var(--card)}
    table.rv-mxw th.rh,table.rv-mxw td.rh{width:200px;min-width:200px;max-width:200px;overflow:hidden;text-overflow:ellipsis;padding-left:8px;box-shadow:-8px 0 0 var(--card),8px 0 0 var(--card)}
    table.rv-mxw td.rh small{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    table.rv-mxw td.rh small code{white-space:nowrap}
    @media(max-width:640px){table.rv-mxw th.rh,table.rv-mxw td.rh{width:150px;min-width:150px;max-width:150px}}
    .rv-mxs.sx table.rv-mx th.rh,.rv-mxs.sx table.rv-mx td.rh{box-shadow:4px 0 0 var(--card),10px 0 10px -6px color-mix(in srgb,var(--text-strong) 28%,transparent)}
    table.rv-mx td{padding:0;border:0;background:none}
    table.rv-mx td.rh{white-space:nowrap;padding:.2rem .5rem .2rem 0}
    table.rv-mx td.rh b{color:var(--text-strong);font-weight:600}
    table.rv-mx td.rh small{display:block;color:var(--muted);font-size:.7rem}
    .rv-mc{min-width:78px;height:40px;border-radius:8px;text-align:center;font-variant-numeric:tabular-nums;border:1px solid transparent;padding:.2rem .3rem;line-height:1.15;display:flex;flex-direction:column;justify-content:center;background:var(--hover2);color:var(--muted)}
    button.rv-mc{width:100%;cursor:pointer;min-height:0;font-weight:400}
    button.rv-mc:hover{box-shadow:inset 0 0 0 1.5px var(--text-strong);filter:none}
    button.rv-mc:focus-visible{outline:2px solid var(--accent);outline-offset:-2px}
    button.rv-mc.q0:hover{background:var(--hover)}
    button.rv-mc.q1:hover,button.rv-mc.q2:hover,button.rv-mc.q3:hover,button.rv-mc.q4:hover,button.rv-mc.q5:hover,button.rv-mc.dead:hover{filter:brightness(1.08)}
    .rv-mc.sel,button.rv-mc.sel:hover{box-shadow:inset 0 0 0 2px var(--text-strong)}
    .rv-mc .a{font-weight:700;font-size:.8rem}
    .rv-mc .b{font-size:.68rem;opacity:.85;white-space:nowrap;display:flex;align-items:center;justify-content:center;gap:.2rem}
    .rv-mc .b svg.rvi{width:11px;height:11px}
    .rv-mc.q1{background:color-mix(in srgb,var(--rq1) 22%,transparent);color:var(--text-strong)}
    .rv-mc.q2{background:color-mix(in srgb,var(--rq2) 22%,transparent);color:var(--text-strong)}
    .rv-mc.q3{background:color-mix(in srgb,var(--rq3) 26%,transparent);color:var(--text-strong)}
    .rv-mc.q4{background:color-mix(in srgb,var(--rq4) 28%,transparent);color:var(--text-strong)}
    .rv-mc.q5{background:color-mix(in srgb,var(--rq5) 30%,transparent);color:var(--text-strong)}
    .rv-mc.dead{background:repeating-linear-gradient(135deg,color-mix(in srgb,var(--rq5) 36%,transparent) 0 6px,color-mix(in srgb,var(--rq5) 22%,transparent) 6px 12px);color:var(--text-strong)}
    .rv-mxd{margin-top:.8rem;border:1px solid var(--line);border-radius:12px;background:var(--bg2);padding:.8rem .9rem}
    .rv-mxd h3{margin:0 0 .3rem;font-size:.9rem;color:var(--text-strong)}
    .rv-hist{display:grid;grid-template-columns:repeat(48,minmax(0,1fr));gap:2px;margin-top:.5rem}
    .rv-hist i{height:22px;border-radius:3px;background:var(--line)}
    .rv-hist.sm{gap:1px;margin:0;min-width:150px}
    .rv-hist.sm i{height:10px;border-radius:2px}
    .rv-hist i.h0{background:var(--rq1)}.rv-hist i.h1{background:var(--rq2)}.rv-hist i.h2{background:var(--rq3)}.rv-hist i.h3{background:var(--rq4)}.rv-hist i.h4,.rv-hist i.h5{background:var(--rq5)}
    .rv-hist i.hx{background:repeating-linear-gradient(135deg,var(--rq5) 0 3px,color-mix(in srgb,var(--rq5) 40%,transparent) 3px 6px)}
    .rv-hax{display:flex;justify-content:space-between;font-size:.68rem;color:var(--muted);margin-top:.25rem}
    .rv-drw-bg{position:fixed;inset:0;background:rgba(2,6,16,.55);opacity:0;pointer-events:none;transition:opacity .2s;z-index:90}
    .rv-drw-bg.open{opacity:1;pointer-events:auto}
    .rv-drw{position:fixed;top:0;right:0;bottom:0;width:min(1060px,96vw);background:var(--bg);border-left:1px solid var(--line);box-shadow:-20px 0 60px rgba(0,0,0,.35);transform:translateX(102%);transition:transform .22s ease;z-index:91;display:flex;flex-direction:column}
    .rv-drw.open{transform:none}
    .rv-dh .btn{display:inline-flex;align-items:center;gap:.4rem}
    .rv-dh{display:flex;align-items:center;gap:.8rem;padding:.9rem 1.2rem;border-bottom:1px solid var(--line);background:var(--card);flex-wrap:wrap}
    .rv-dh h2{margin:0;font-size:1.2rem;color:var(--text-strong)}
    .rv-dh .av{width:40px;height:40px;border-radius:12px;background:var(--accent-light);color:var(--accent-text);display:flex;align-items:center;justify-content:center;font-weight:700}
    .rv-dh .sp{flex:1}
    .rv-db{overflow:auto;padding:1rem 1.2rem 2rem;flex:1}
    .rv-sect{font-size:.72rem;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);font-weight:700;margin:1.1rem 0 .5rem;display:flex;align-items:center;gap:.6rem}
    .rv-sect label{text-transform:none;letter-spacing:0;font-weight:500;margin-left:auto;display:inline-flex;gap:.4rem;align-items:center;cursor:pointer}
    .rv-dtabs{display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:.8rem}
    .rv-dtab{display:flex;gap:.6rem;align-items:center;border:1px solid var(--line);background:var(--card);color:var(--text);border-radius:12px;padding:.55rem .8rem;text-align:left;min-width:250px;min-height:0;height:auto;font-weight:400;cursor:pointer}
    .rv-dtab:hover{background:var(--hover);filter:none}
    .rv-dtab.on,.rv-dtab.on:hover{border-color:var(--accent);background:var(--accent-light)}
    .rv-dtab .t{font-weight:600;color:var(--text-strong);font-size:.88rem;display:block}
    .rv-dtab .s{font-size:.74rem;color:var(--muted);display:block}
    .rv-kv{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:.6rem}
    .rv-ipl{margin-top:.45rem;font-size:.76rem;color:var(--muted)}
    .rv-ipl summary{cursor:pointer;color:var(--text);font-weight:500;list-style-position:inside}
    .rv-ipl table{width:100%;border-collapse:collapse;margin-top:.3rem}
    .rv-ipl td{padding:.2rem .3rem .2rem 0;border:0;background:none;vertical-align:top;overflow-wrap:anywhere}
    .rv-ipl td.n{text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums}
    .rv-kv div{background:var(--bg2);border:1px solid var(--line);border-radius:10px;padding:.5rem .7rem}
    .rv-kv span{display:block;font-size:.68rem;color:var(--muted);text-transform:uppercase;letter-spacing:.04em}
    .rv-kv b{display:block;font-size:.86rem;color:var(--text-strong);font-weight:600;margin-top:.1rem;overflow-wrap:anywhere}
    .rv-nets{display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:.6rem}
    .rv-net{border:1px solid var(--line);border-radius:12px;background:var(--card);padding:.65rem .8rem}
    .rv-net{min-width:0}
    .rv-net .h{display:flex;gap:.35rem .45rem;align-items:center;flex-wrap:wrap;font-weight:600;color:var(--text-strong);font-size:.88rem}
    .rv-net .h .rv-pill,.rv-net .h .rq{max-width:100%;white-space:normal}
    .rv-net .h .sp{flex:1}
    .rv-net .r{font-size:.78rem;color:var(--muted);margin-top:.3rem;line-height:1.5;overflow-wrap:anywhere}
    .rv-diag{border:1px solid var(--line);border-radius:12px;padding:.7rem .85rem;display:flex;gap:.65rem;margin-bottom:.5rem;background:var(--card)}
    .rv-diag[data-jump]{cursor:pointer}
    .rv-diag[data-jump]:hover{background:var(--hover)}
    .rv-diag .go{margin-left:auto;align-self:center;color:var(--muted);font-size:.74rem;white-space:nowrap}
    .rv-dtab .rv-jump{cursor:pointer;border:0;min-height:0;height:auto;font:inherit;font-size:.74rem;font-weight:600}
    .rv-dtab .rv-jump:hover{filter:brightness(1.15);text-decoration:underline;text-underline-offset:2px}
    .rv-drw table.rv-mx th.rh,.rv-drw table.rv-mx td.rh{background:var(--bg);box-shadow:4px 0 0 var(--bg)}
    table.rv-mx tr.cur td.rh,.rv-drw table.rv-mx tr.cur td.rh{box-shadow:inset 3px 0 var(--accent),4px 0 0 var(--bg);padding-left:.5rem;background:color-mix(in srgb,var(--accent) 16%,var(--bg))}
    .rv-drw table.rv-mx tr.cur td{background:color-mix(in srgb,var(--accent) 9%,transparent)}
    .rv-drw table.rv-mx tr.cur td.rh b{color:var(--accent-text)}
    table.rv-mx tr.rv-flash td{animation:rvFlash 2.4s ease-out}
    @keyframes rvFlash{0%,35%{background:color-mix(in srgb,var(--accent) 24%,transparent)}100%{background:transparent}}
    .rv-diag.st-bad{border-color:color-mix(in srgb,var(--rq5) 45%,var(--line))}
    .rv-diag.st-warn{border-color:color-mix(in srgb,var(--rq3) 45%,var(--line))}
    .rv-diag.st-ok{border-color:color-mix(in srgb,var(--rq1) 45%,var(--line))}
    .rv-diag .t{font-weight:600;color:var(--text-strong);font-size:.88rem}
    .rv-diag .w{font-size:.8rem;color:var(--muted);margin-top:.15rem;line-height:1.45}
    .rv-diag .ic.st-bad{color:var(--c-bad-fg)}.rv-diag .ic.st-warn{color:var(--c-warn-fg)}.rv-diag .ic.st-ok{color:var(--c-ok-fg)}
    table.rv-lt{width:100%;border-collapse:collapse;font-size:.82rem}
    table.rv-lt th{font-size:.7rem;color:var(--muted);font-weight:600;text-align:left;padding:.4rem .5rem;border-bottom:1px solid var(--line);white-space:nowrap;background:none;position:static}
    table.rv-lt td{padding:.45rem .5rem;border-bottom:1px solid var(--line);vertical-align:middle}
    table.rv-lt tr:last-child td{border-bottom:0}
    table.rv-lt tr.clk{cursor:pointer}
    table.rv-lt tr.clk:hover td{background:var(--hover2)}
    .rv-win{display:inline-flex;align-items:center;gap:.3rem;font-size:.74rem;font-weight:500;color:var(--muted);border:1px solid var(--line);border-radius:999px;padding:.12rem .6rem;white-space:nowrap;cursor:help}
    .rv-win svg.rvi{width:12px;height:12px}
    .loghead .rv-win{margin-left:.6rem;margin-right:auto}
    .rv-fresh{display:flex;align-items:flex-start;gap:.4rem;font-size:.78rem;margin:.8rem 0 0}
    .rv-fresh svg.rvi{width:13px;height:13px;flex:none;margin-top:.15rem}
    .rv-hist i.hp{background:transparent;box-shadow:inset 0 0 0 1px var(--line)}
    .rv-share{display:flex;align-items:center;gap:.5rem;justify-content:flex-end}
    .rv-share .bar{width:56px;height:6px;border-radius:3px;background:var(--hover);overflow:hidden;flex:none}
    .rv-share .bar i{display:block;height:100%;background:var(--accent);border-radius:3px}
    .rv-share .pc{min-width:2.8rem;text-align:right;color:var(--muted);font-size:.78rem}
    .rv-hrs{margin-top:.7rem}
    .rv-hrs summary{cursor:pointer;font-size:.82rem;color:var(--accent-text);font-weight:600}
    .rv-hrs table.rv-lt td,.rv-hrs table.rv-lt th{padding:.32rem .5rem}
    .rv-hrs td.n,.rv-hrs th.n{text-align:right;font-variant-numeric:tabular-nums}
    .rv-hrs tr.w6 td{background:color-mix(in srgb,var(--amber) 8%,transparent)}
    .rv-hrs tr.w6 td:first-child{box-shadow:inset 3px 0 0 var(--amber)}
    .rv-hrs tr.ol td{opacity:.55}
    .rv-hb{display:inline-flex;align-items:flex-end;gap:2px;height:16px;vertical-align:middle}
    .rv-hb i{width:6px;border-radius:2px 2px 0 0;min-height:1px;background:var(--line)}
    .rv-hb i.h0{background:var(--rq1)}.rv-hb i.h1{background:var(--rq2)}.rv-hb i.h2{background:var(--rq3)}.rv-hb i.h3{background:var(--rq4)}.rv-hb i.h4,.rv-hb i.h5{background:var(--rq5)}
    </style>

<svg width="0" height="0" style="position:absolute" aria-hidden="true">
  <symbol id="rvi-wifi" viewBox="0 0 24 24"><path d="M5 12.55a11 11 0 0 1 14.08 0"/><path d="M1.42 9a16 16 0 0 1 21.16 0"/><path d="M8.53 16.11a6 6 0 0 1 6.95 0"/><path d="M12 20h.01"/></symbol>
  <symbol id="rvi-mobile" viewBox="0 0 24 24"><path d="M2 20h.01"/><path d="M7 20v-4"/><path d="M12 20v-8"/><path d="M17 20V8"/><path d="M22 4v16"/></symbol>
  <symbol id="rvi-wired" viewBox="0 0 24 24"><rect x="16" y="16" width="6" height="6" rx="1"/><rect x="2" y="16" width="6" height="6" rx="1"/><rect x="9" y="2" width="6" height="6" rx="1"/><path d="M5 16v-3a1 1 0 0 1 1-1h12a1 1 0 0 1 1 1v3"/><path d="M12 12V8"/></symbol>
  <symbol id="rvi-other" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></symbol>
  <symbol id="rvi-android" viewBox="0 0 24 24"><rect x="5" y="2" width="14" height="20" rx="2"/><path d="M12 18h.01"/></symbol>
  <symbol id="rvi-pc" viewBox="0 0 24 24"><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8"/><path d="M12 17v4"/></symbol>
  <symbol id="rvi-globe" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M2 12h20"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></symbol>
  <symbol id="rvi-xoct" viewBox="0 0 24 24"><polygon points="7.86 2 16.14 2 22 7.86 22 16.14 16.14 22 7.86 22 2 16.14 2 7.86 7.86 2"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></symbol>
  <symbol id="rvi-scissors" viewBox="0 0 24 24"><circle cx="6" cy="6" r="3"/><path d="M8.12 8.12 12 12"/><path d="M20 4 8.12 15.88"/><circle cx="6" cy="18" r="3"/><path d="M14.8 14.8 20 20"/></symbol>
  <symbol id="rvi-clock" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></symbol>
  <symbol id="rvi-phone" viewBox="0 0 24 24"><rect x="5" y="2" width="14" height="20" rx="2"/><path d="m9 9 6 6"/><path d="m15 9-6 6"/></symbol>
  <symbol id="rvi-server" viewBox="0 0 24 24"><rect x="2" y="2" width="20" height="8" rx="2"/><rect x="2" y="14" width="20" height="8" rx="2"/><path d="M6 6h.01"/><path d="M6 18h.01"/></symbol>
  <symbol id="rvi-check" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/></symbol>
  <symbol id="rvi-x" viewBox="0 0 24 24"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></symbol>
  <symbol id="rvi-chev" viewBox="0 0 24 24"><path d="m9 18 6-6-6-6"/></symbol>
  <symbol id="rvi-list" viewBox="0 0 24 24"><path d="M8 6h13"/><path d="M8 12h13"/><path d="M8 18h13"/><path d="M3 6h.01"/><path d="M3 12h.01"/><path d="M3 18h.01"/></symbol>
  <symbol id="rvi-pin" viewBox="0 0 24 24"><path d="M20 10c0 4.99-5.54 10.19-7.4 11.8a1 1 0 0 1-1.2 0C9.54 20.19 4 14.99 4 10a8 8 0 0 1 16 0"/><circle cx="12" cy="10" r="3"/></symbol>
  <symbol id="rvi-plus" viewBox="0 0 24 24"><path d="M5 12h14"/><path d="M12 5v14"/></symbol>
  <symbol id="rvi-minus" viewBox="0 0 24 24"><path d="M5 12h14"/></symbol>
  <symbol id="rvi-reset" viewBox="0 0 24 24"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></symbol>
  <symbol id="rvi-user" viewBox="0 0 24 24"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></symbol>
</svg>

<div class="rvp">
<?php if (!rep_enabled()): ?>
    <div class="warn">Приём отчётов выключен — новые данные не приходят. Включается ниже, в блоке <a href="#repSettings">«Приём отчётов»</a><?= chan_enabled() ? '' : ', и нужен включённый <a href="?tab=clod">защищённый канал</a>' ?>.</div>
<?php endif; ?>

    <div class="card">
        <div class="rv-flt">
            <form method="get" class="rv-flt">
                <input type="hidden" name="tab" value="reports">
                <?php foreach (['cc', 'node'] as $k): if ($rv_f[$k] !== ''): ?><input type="hidden" name="<?= $k ?>" value="<?= h($rv_f[$k]) ?>"><?php endif; endforeach; ?>
                <span class="rv-seg">
                    <?php foreach ([1 => '24 часа', 7 => '7 дней', 30 => '30 дней'] as $p => $pt): ?>
                    <a class="<?= $rv_f['p'] === $p ? 'on' : '' ?>" href="<?= h($rv_url(['p' => $p])) ?>"><?= h($pt) ?></a>
                    <?php endforeach; ?>
                </span>
                <input type="hidden" name="p" value="<?= (int) $rv_f['p'] ?>">
                <select name="kind" onchange="this.form.submit()">
                    <option value="">Все сети</option>
                    <?php foreach (['home' => 'Домашний (Wi-Fi и кабель)', 'mobile' => 'Мобильная'] + ($rv_f['kind'] !== '' && !in_array($rv_f['kind'], ['home', 'mobile'], true) ? [$rv_f['kind'] => REP_VIEW_KINDS[$rv_f['kind']] ?? $rv_f['kind']] : []) as $k => $kt): ?><option value="<?= h($k) ?>"<?= $rv_f['kind'] === $k ? ' selected' : '' ?>><?= h($kt) ?></option><?php endforeach; ?>
                </select>
                <select name="plat" onchange="this.form.submit()">
                    <option value="">Все платформы</option>
                    <?php foreach (REP_VIEW_PLATS as $k => $kt): ?><option value="<?= h($k) ?>"<?= $rv_f['plat'] === $k ? ' selected' : '' ?>><?= h($kt) ?></option><?php endforeach; ?>
                </select>
                <?php if ($rv_f['cc'] !== ''): ?><a class="rv-chip" href="<?= h($rv_url(['cc' => ''])) ?>" title="Снять фильтр"><?= $rv_flag($rv_f['cc']) ?> <span data-ccname="<?= h($rv_f['cc']) ?>"><?= h($rv_f['cc']) ?></span> ✕</a><?php endif; ?>
                <?php if ($rv_f['node'] !== ''): ?><a class="rv-chip" href="<?= h($rv_url(['node' => ''])) ?>" title="Снять фильтр">Узел: <?= h($rv_node['name'] ?? $rv_f['node']) ?> ✕</a><?php endif; ?>
            </form>
            <span class="sp"></span>
            <form class="rv-flt" id="rvFind">
                <input type="text" id="rvQ" value="<?= h($rv_f['q']) ?>" placeholder="Имя, shortUuid или HWID" autocomplete="off">
                <button type="submit" class="btn ghost">Найти</button>
            </form>
        </div>

        <div class="rv-kpis">
            <div class="rv-kpi"><span class="k">Устройств</span><span class="v"><?= $rv_num($rv_tot['devices']) ?></span><span class="d">прислали отчёт за период</span></div>
            <div class="rv-kpi"><span class="k">Не отвечает узел</span><span class="v<?= $rv_dead_cl ? ' bad' : '' ?>"><?= $rv_num(count($rv_dead_cl)) ?></span><span class="d" data-tip="По последним (до 6) часам с замерами в каждой сети устройства, приславшего отчёт за период: узел не ответил ни на один пинг. Сеть, в которой устройство давно не было, тоже учитывается, пока её последний отчёт попадает в период.">клиентов, у кого узел молчит — <b>по последним часам с замерами</b></span></div>
            <div class="rv-kpi"><span class="k">Пинги</span><span class="v"><?= $rv_num($rv_tot['pn']) ?></span><span class="d">неудачных <span class="rq <?= $rv_qfail($rv_tot['fail']) ?>"><?= h($rv_pct($rv_tot['fail'])) ?></span></span></div>
            <div class="rv-kpi"><span class="k">Медианный пинг</span><span class="v" style="font-size:1.15rem"><?= h($rv_med($rv_tot['med'])) ?></span><span class="d">по <?= $rv_num($rv_tot['nodes']) ?> узлам</span></div>
            <div class="rv-kpi"><span class="k">Проверка 16–20</span><span class="v" style="font-size:1.05rem"><?= $rv_frzb($rv_tot) ?></span><span class="d" data-tip="Сколько проверок за период закончились так. Это число проверок, а не устройств. Нажмите на число — у каких устройств, в какой сети и до какого узла.">проверок за период: режется · не отвечает · работает</span></div>
            <div class="rv-kpi"><span class="k">Трафик через узлы</span><span class="v"><?= h($rv_bytes($rv_tot['bytes'])) ?></span><span class="d">у клиентов с отчётами</span></div>
        </div>
        <?php if ((int) $rep_st['last'] > 0): ?><p class="muted rv-fresh"><svg class="rvi"><use href="#rvi-clock"/></svg><span>Последний отчёт пришёл <b class="ct-ago" data-ts="<?= (int) $rep_st['last'] ?>"><?= h(date('d.m H:i', (int) $rep_st['last'])) ?></b>. Устройство присылает отчёт не чаще раза в 6 часов, при плановом обновлении подписки, и только за закрытые часы, поэтому данные отстают от реального времени минимум на час, обычно на несколько часов.</span></p><?php endif; ?>
    </div>

    <div class="card">
        <div class="loghead"><h2>Карта клиентов</h2><?= $rv_win('48') ?>
            <div class="rv-flt"><span class="rv-seg" id="rvMetric"><button type="button" class="on" data-m="fail">Неудачные пинги</button><button type="button" data-m="med">Медианный пинг</button><button type="button" data-m="frz">Проверка 16–20</button></span></div>
        </div>
        <p class="muted" style="font-size:.82rem">Точка — клиенты в регионе. Регион устройства — где оно провело больше всего часов, по домашним сетям (Wi-Fi, кабель), если они есть: динамические адреса и мобильный оператор устройство не переносят, сеть в другой стране показывается отдельно; число — устройств, цвет — худшее состояние среди них. Регион закрашен по выбранной метрике. Нажмите на точку или регион — список клиентов, на клиента — его устройства, сети и пинги.</p>
        <div class="rv-mgrid">
            <div>
                <div class="rv-mapbox" id="rvMapBox">
                    <svg class="rv-map" id="rvMap" viewBox="0 0 1000 540" xmlns="http://www.w3.org/2000/svg"><g id="rvGR"></g><g id="rvGC"></g></svg>
                    <div class="rv-dots" id="rvDots"></div>
                    <div class="rv-tip" id="rvTip"></div>
                    <div class="rv-pop" id="rvPop"></div>
                    <div class="rv-zoom" role="group" aria-label="Масштаб карты">
                        <button type="button" id="rvZin" aria-label="Приблизить" title="Приблизить"><svg class="rvi"><use href="#rvi-plus"/></svg></button>
                        <button type="button" id="rvZout" aria-label="Отдалить" title="Отдалить" disabled><svg class="rvi"><use href="#rvi-minus"/></svg></button>
                        <button type="button" id="rvZreset" aria-label="Вся карта" title="Вся карта" hidden><svg class="rvi"><use href="#rvi-reset"/></svg></button>
                    </div>
                    <div class="rv-zhint" id="rvZhint">Нажмите на карту или держите Ctrl, чтобы приближать колёсиком</div>
                </div>
                <div class="rv-legend" id="rvLegend"></div>
                <p class="muted" style="font-size:.78rem;margin:.4rem 0 0">Регион — по координатам адреса из базы DB-IP City Lite<?= !empty($rep_geo['city']['ok']) ? '' : ' (базы сейчас нет — точки появятся после её скачивания и новых отчётов)' ?>. У мобильных операторов адрес часто числится в точке выхода оператора (Москва, Санкт-Петербург, Новосибирск), а не там, где абонент.<?= $rv_mapd['cut'] ? ' Показана часть данных — сузьте период или фильтры.' : '' ?></p>
            </div>
            <div class="rv-side" id="rvSide"></div>
        </div>
    </div>

    <div class="card">
        <div class="loghead"><h2>Что не работает сейчас</h2><?= $rv_win('6') ?></div>
        <p class="muted" style="font-size:.82rem">По последним отчётам устройств. Узел молчит у большинства клиентов одного провайдера, а у других провайдеров работает — блок у провайдера; молчит только у одного клиента во всех его сетях — дело в устройстве; пинг есть, а проверка 16–20 режется — DPI рвёт соединение после первых килобайт.</p>
        <div id="rvIncs"></div>
    </div>

    <div class="card" id="rvMxCard">
        <div class="loghead"><h2>Узлы × провайдеры</h2><?= $rv_win('48') ?></div>
        <p class="muted" style="font-size:.82rem">Строка — узел, столбец — провайдер клиентов (AS), по последнему состоянию устройств за период. В ячейке — доля неудачных пингов и медиана; заштриховано и «N/M» — в стольких сетях из M узел не отвечает совсем. Нажмите на ячейку — кто именно, в какой сети и как менялось за 48 часов.</p>
        <div class="rv-mxbar"><span class="rv-seg" id="rvMxKind"><button type="button" data-k="">Все сети</button><button type="button" data-k="home" title="Wi-Fi и кабель — домашний интернет">Домашний</button><button type="button" data-k="mobile">Мобильная</button></span><span class="muted" id="rvMxNote"></span></div>
        <div id="rvMxT"></div>
        <div class="rv-mxd" id="rvMxd"><span class="muted" style="font-size:.82rem">Выберите ячейку — здесь появятся сети этого провайдера и их пинги до узла.</span></div>
    </div>

    <div class="card">
        <div class="loghead"><h2>Пинги по <?= $rv_series['step'] === 3600 ? 'часам' : 'суткам (UTC)' ?><?= $rv_node ? ' — ' . h($rv_node['name'] !== '' ? $rv_node['name'] : $rv_node['server']) : '' ?></h2><?= $rv_win('p') ?></div>
        <?php
        $pts = $rv_series['points'];
        $cnt = max(1, count($pts));
        $W = 1000; $H = 150; $bw = $W / $cnt;
        $rv_dt = static fn($fmt, $t) => $rv_series['step'] === 3600 ? date($fmt, $t) : gmdate($fmt, $t);
        ?>
        <svg class="rv-chart" viewBox="0 0 <?= $W ?> <?= $H + 18 ?>" preserveAspectRatio="none">
            <line class="gl" x1="0" y1="<?= $H ?>" x2="<?= $W ?>" y2="<?= $H ?>"/>
            <?php foreach ($pts as $i => $pt):
                if ($pt['pn'] <= 0) continue;
                $x = $i * $bw + $bw * .1;
                $w = max(1, $bw * .8);
                $hOk = ($pt['pn'] - $pt['pf']) / $rv_max * ($H - 6);
                $hBad = $pt['pf'] / $rv_max * ($H - 6);
                $tr = ' — пингов ' . $pt['pn'] . ', неудачных ' . $pt['pf'] . ($pt['fail'] !== null ? ' (' . $rv_pct($pt['fail']) . ')' : '') . ', медиана ' . $rv_med($pt['med']);
                $tt = $rv_dt($rv_series['step'] === 3600 ? 'd.m H:00' : 'd.m', $pt['t']) . $tr;
            ?>
            <g data-tip="<?= h($tt) ?>"<?= $rv_series['step'] === 3600 ? ' data-ts="' . (int) $pt['t'] . '" data-tipr="' . h($tr) . '"' : '' ?>>
                <rect class="ok" x="<?= round($x, 1) ?>" y="<?= round($H - $hOk - $hBad, 1) ?>" width="<?= round($w, 1) ?>" height="<?= round($hOk, 1) ?>"/>
                <?php if ($hBad > 0): ?><rect class="bad" x="<?= round($x, 1) ?>" y="<?= round($H - $hBad, 1) ?>" width="<?= round($w, 1) ?>" height="<?= round(max($hBad, 1), 1) ?>"/><?php endif; ?>
            </g>
            <?php endforeach; ?>
            <?php
            $marks = $rv_series['step'] === 3600 ? ($rv_f['p'] === 1 ? 6 : 24) : 5;
            foreach ($pts as $i => $pt):
                if ($i % $marks !== 0 || $i * $bw > $W - 40) continue; ?>
            <text class="ax" x="<?= round($i * $bw + 2, 1) ?>" y="<?= $H + 13 ?>"<?= $rv_series['step'] === 3600 ? ' data-ts="' . (int) $pt['t'] . '" data-f="' . ($rv_f['p'] === 1 ? 'hm' : 'dm') . '"' : '' ?>><?= h($rv_dt($rv_series['step'] === 3600 && $rv_f['p'] === 1 ? 'H:00' : 'd.m', $pt['t'])) ?></text>
            <?php endforeach; ?>
        </svg>
        <div class="rv-legend"><span><i style="background:var(--accent);opacity:.55"></i>удачные</span><span><i style="background:var(--rq5)"></i>неудачные</span><span>Наведите на столбец — подробности.</span></div>
    </div>

    <div class="card">
        <div class="loghead"><h2>Провайдеры клиентов (<?= count($rv_isps) ?>)</h2><?= $rv_win('pd') ?></div>
        <p class="muted" style="font-size:.82rem">Провайдер — номер автономной системы (AS) по адресу клиента; название из базы GeoIP может ошибаться, номер AS и адрес — нет. Нажмите на строку, чтобы увидеть адреса.<?= $rv_f['plat'] !== '' ? ' Фильтр по платформе к провайдерам не применяется.' : '' ?></p>
        <?php if (!$rv_isps): ?>
        <p class="muted">Нет данных за период.</p>
        <?php else: ?>
        <div class="rv-wrap"><table class="logtbl rv-tbl">
            <thead><tr><th>Страна</th><th>Провайдер (AS)</th><th class="num">Адресов</th><th class="num">Устройств</th><th class="num">Пингов</th><th class="num">Неудачи</th><th>Медиана</th><th>16–20</th><th class="num">Трафик</th></tr></thead>
            <tbody>
            <?php foreach ($rv_isps as $isp): ?>
            <tr>
                <td><a href="<?= h($rv_url(['cc' => $isp['cc']])) ?>" style="text-decoration:none"><?= $rv_flag($isp['cc']) ?> <span data-ccname="<?= h($isp['cc']) ?>"><?= h($isp['cc'] ?: '—') ?></span></a></td>
                <td><details><summary><b><?= $isp['asn'] > 0 ? 'AS' . (int) $isp['asn'] : 'AS —' ?></b> <?= h($isp['org'] ?: '—') ?> <span class="muted">▾</span></summary>
                    <table class="rv-ips"><tbody>
                    <?php foreach ($isp['ips'] as $ip): ?>
                    <tr><td><code><?= h($ip['ip']) ?></code><span class="sub"><?= $isp['asn'] > 0 ? 'AS' . (int) $isp['asn'] : '' ?> <?= h($ip['org']) ?></span></td>
                        <td class="num"><?= $rv_num($ip['pn']) ?></td>
                        <td><span class="rq <?= $rv_qfail($ip['fail']) ?>"><?= h($rv_pct($ip['fail'])) ?></span></td>
                        <td><span class="rq <?= $rv_qmed($ip['med']) ?>"><?= h($rv_med($ip['med'])) ?></span></td>
                        <td><?= $rv_frz($ip) ?></td>
                        <td class="muted"><?= h(gmdate('d.m', $ip['last'])) ?></td></tr>
                    <?php endforeach; ?>
                    </tbody></table></details></td>
                <td class="num"><?= $rv_num(count($isp['ips'])) ?></td>
                <td class="num"><?= $rv_num($isp['devices']) ?></td>
                <td class="num"><?= $rv_num($isp['pn']) ?></td>
                <td class="num"><span class="rq <?= $rv_qfail($isp['fail']) ?>"><?= h($rv_pct($isp['fail'])) ?></span></td>
                <td><span class="rq <?= $rv_qmed($isp['med']) ?>"><?= h($rv_med($isp['med'])) ?></span></td>
                <td><?= $rv_frzb($isp, '', (int) $isp['asn']) ?></td>
                <td class="num"><?= h($rv_bytes($isp['bytes'])) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="loghead"><h2>Узлы (<?= count($rv_nodes) ?>)</h2><?= $rv_win('p') ?><span class="sp" style="flex:1"></span><?php if (count($rv_nodes) > 1): ?><span class="rv-seg" id="rvNdSort"><button type="button" class="on" data-s="fail">Сначала проблемные</button><button type="button" data-s="b">Сначала популярные</button></span><?php endif; ?></div>
        <p class="muted" style="font-size:.82rem">«Трафик» — сколько прошло через узел у клиентов с отчётами и его доля от трафика всех узлов: видно, какими узлами реально пользуются. Сверху — узлы с наибольшей долей неудачных пингов. «Молчит у» — у скольких устройств узел не ответил ни на один пинг в последних часах с замерами (до 6). «Хуже всего» — страна и провайдер клиентов, у которых этому узлу хуже всего (от <?= REP_VIEW_MIN_PINGS ?> пингов); адреса, у которых провайдер не определился, тут не учитываются. Нажмите на узел — график и провайдеры только по нему.</p>
        <?php if (!$rv_nodes): ?>
        <p class="muted">Нет данных за период.</p>
        <?php else: ?>
        <div class="rv-wrap"><table class="logtbl rv-tbl">
            <thead><tr><th>Узел</th><th>В панели</th><th class="num">Пингов</th><th class="num">Неудачи</th><th>Медиана</th><th>16–20</th><th class="num" data-tip="По последним (до 6) часам с замерами в каждой сети устройства, приславшего отчёт за период: узел не ответил ни на один пинг. Сеть, в которой устройство давно не было, тоже учитывается, пока её последний отчёт попадает в период.">Молчит у</th><th class="num" data-tip="<?= $rv_f['node'] !== '' ? 'Трафик через узел за период.' : 'Трафик через узел за период и его доля от трафика всех узлов при тех же фильтрах.' ?>">Трафик<?= $rv_f['node'] !== '' ? '' : ' · доля' ?></th><th>Хуже всего</th></tr></thead>
            <tbody id="rvNdBody">
            <?php $rv_btot = max(1, array_sum(array_column($rv_nodes, 'bytes'))); foreach ($rv_nodes as $n): $pnl = $rv_panel_of($n['server']); $nd = count($rv_dead_node[$n['nkey']] ?? []); $rv_sh = $n['bytes'] / $rv_btot; ?>
            <tr data-b="<?= (int) $n['bytes'] ?>">
                <td class="rv-node"><a href="<?= h($rv_url(['node' => $n['nkey']])) ?>"><?= h($n['name'] !== '' ? $n['name'] : $n['server']) ?></a>
                    <span class="sub"><?= h(rep_proto_label($n['type'], $n['server'], $n['port'])) ?></span></td>
                <td><?php if ($pnl): foreach ($pnl as $pn): ?><div><?= $rv_nflag($pn['cc'], $pn['name']) ?> <?= h($pn['name']) ?></div><?php endforeach; else: ?><span class="muted">—</span><?php endif; ?></td>
                <td class="num"><?= $rv_num($n['pn']) ?></td>
                <td class="num"><span class="rq <?= $rv_qfail($n['fail']) ?>"><?= h($rv_pct($n['fail'])) ?></span></td>
                <td><span class="rq <?= $rv_qmed($n['med']) ?>"><?= h($rv_med($n['med'])) ?></span></td>
                <td><?= $rv_frzb($n, $n['nkey']) ?></td>
                <td class="num"><?= $nd > 0 ? '<button type="button" class="rq q5 rv-deadbtn" data-nk="' . h($n['nkey']) . '" title="Показать, у кого">' . $rv_num($nd) . ' устр.</button>' : '<span class="muted">—</span>' ?></td>
                <td class="num"><?php if ($n['bytes'] > 0 && $rv_f['node'] !== ''): ?><?= h($rv_bytes($n['bytes'])) ?><?php elseif ($n['bytes'] > 0): ?><div class="rv-share"><?= h($rv_bytes($n['bytes'])) ?><span class="bar"><i style="width:<?= round($rv_sh * 100, 1) ?>%"></i></span><span class="pc"><?= h($rv_pct($rv_sh)) ?></span></div><?php else: ?><span class="muted">—</span><?php endif; ?></td>
                <td><?php if ($n['worst'] && $n['worst']['fail'] > 0): ?><?= $rv_flag($n['worst']['cc']) ?> <span data-tip="<?= h('AS' . (int) $n['worst']['asn'] . ($n['worst']['org'] !== '' ? ' · ' . $n['worst']['org'] : '') . ' — ' . $rv_num($n['worst']['pn']) . ' пингов') ?>"><?= h($n['worst']['org'] !== '' ? $n['worst']['org'] : 'AS' . (int) $n['worst']['asn']) ?></span> <span class="rq <?= $rv_qfail($n['worst']['fail']) ?>"><?= h($rv_pct($n['worst']['fail'])) ?></span><?php else: ?><span class="muted">—</span><?php endif; ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </div>

    <div class="card" id="repSettings">
        <h2 style="margin-top:0;font-size:1rem">Приём отчётов</h2>
        <p class="muted">Клиенты Clod Clash с защищённой подпиской после планового обновления, не чаще раза в 6 часов, присылают отчёт: итоги проверки 16–20, пинги узлов, трафик по узлам, тип сети (Wi-Fi, мобильная, провод) и внешний IP, с которого делались замеры. Отчёт едет только по защищённому каналу. Пока тумблер выключен, прослойка отвечает клиенту «не принимаю», и он ничего не теряет — данные полежат у него до следующей попытки.</p>
        <form method="post" data-autosave>
            <input type="hidden" name="csrf" value="<?= h($token) ?>">
            <input type="hidden" name="action" value="save_clod_rep">
            <div class="ctg">
            <div class="set-row">
                <div class="set-info"><div class="set-t">Принимать отчёты</div><div class="set-d">Работает только при включённом главном выключателе защищённого канала.</div></div>
                <label class="switch"><input type="checkbox" name="rep_enabled" <?= setting('rep_enabled', '0') === '1' ? 'checked' : '' ?> <?= chan_ext_ok() ? '' : 'disabled' ?>><span class="sl"></span></label>
            </div>
            <div class="set-row">
                <div class="set-info"><div class="set-t">Сколько дней хранить</div><div class="set-d">Старше — удаляется само. От 7 до 365.</div></div>
                <input type="number" name="rep_keep_days" min="7" max="365" value="<?= (int) rep_keep_days() ?>">
            </div>
            <div class="set-row">
                <div class="set-info"><div class="set-t">Обновлять базы GeoIP сами</div><div class="set-d">Раз в месяц скачиваются бесплатные DB-IP Lite (страна и AS). Выключите, если кладёте свои файлы.</div></div>
                <label class="switch"><input type="checkbox" name="geoip_auto" <?= geoip_auto() ? 'checked' : '' ?>><span class="sl"></span></label>
            </div>
            <div class="set-row">
                <div class="set-info"><div class="set-t">Регионы на карте</div><div class="set-d">База городов DB-IP City Lite: координаты адреса клиента. Около 60 МБ скачивания и 130 МБ на диске. Выключение удаляет файл.</div></div>
                <label class="switch"><input type="checkbox" name="geoip_city" <?= geoip_city_on() ? 'checked' : '' ?>><span class="sl"></span></label>
            </div>
            </div>
        </form>
        <table class="logtbl" style="margin-top:1rem">
            <tbody>
            <tr><td style="width:2.2rem"><?= (int) $rep_st['week'] > 0 ? '✅' : '—' ?></td>
                <td>Устройств с отчётами: за сутки <b><?= (int) $rep_st['day'] ?></b>, за неделю <b><?= (int) $rep_st['week'] ?></b><?php if ((int) $rep_st['last'] > 0): ?> · последний отчёт <span class="ct-time" data-ts="<?= (int) $rep_st['last'] ?>"><?= h(date('Y-m-d H:i', (int) $rep_st['last'])) ?></span><?php endif; ?></td></tr>
            <?php foreach (['country' => 'Страна', 'asn' => 'Автономная система (провайдер)', 'city' => 'Города и координаты (регионы на карте)'] as $gk => $gt): $g = $rep_geo[$gk]; if ($gk === 'city' && !geoip_city_on() && !$g['ok']) continue; ?>
            <tr><td><?= $g['ok'] ? '✅' : '⚠️' ?></td>
                <td><?= h($gt) ?> — <?php if ($g['ok']): ?><code><?= h($g['type']) ?></code>, сборка <?= $g['built'] > 0 ? h(gmdate('Y-m-d', (int) $g['built'])) : '—' ?><?php else: ?>базы нет<?= is_file($g['path']) ? ', файл не читается' : '' ?><?php endif; ?>
                    <div class="muted" style="font-size:.78rem"><code><?= h($g['path']) ?></code></div></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p class="muted" style="font-size:.8rem;margin-top:.6rem">Без баз отчёты всё равно принимаются, но страна, провайдер и регион у этих записей останутся пустыми. Свои файлы MaxMind DB (например, GeoLite2-Country, GeoLite2-ASN и GeoLite2-City) кладите по путям выше под теми же именами. Базы по умолчанию: <a href="https://db-ip.com" target="_blank" rel="noopener">IP Geolocation by DB-IP</a>, лицензия CC BY 4.0.</p>
        <form method="post" style="margin-top:.6rem">
            <input type="hidden" name="csrf" value="<?= h($token) ?>">
            <input type="hidden" name="action" value="clod_geoip_update">
            <button type="submit" class="btn ghost">↻ Скачать базы DB-IP сейчас</button>
        </form>
    </div>
</div>

<div class="rv-drw-bg" id="rvDrwBg"></div>
<div class="rv-mod rvp" id="rvMod" role="dialog" aria-modal="true" aria-label="Устройства"></div>
<aside class="rv-drw rvp" id="rvDrw" aria-label="Клиент"></aside>

    <script src="assets/cis.js?v=<?= substr(@md5_file(__DIR__ . '/../assets/cis.js') ?: '0', 0, 10) ?>"></script>
    <script>
    (function () {
        var D = <?= json_encode($rv_mapd, $rv_js) ?>, HST = <?= json_encode($rv_hst, $rv_js) ?>;
        (function () {
            var p2 = function (n) { return (n < 10 ? '0' : '') + n; };
            var dm = function (d) { return p2(d.getDate()) + '.' + p2(d.getMonth() + 1); }, hm = function (d) { return p2(d.getHours()) + ':' + p2(d.getMinutes()); };
            document.querySelectorAll('.rvp [data-ts]').forEach(function (el) {
                var d = new Date(parseInt(el.getAttribute('data-ts'), 10) * 1000); if (isNaN(d.getTime())) return;
                if (el.classList.contains('ct-time')) el.textContent = d.getFullYear() + '-' + p2(d.getMonth() + 1) + '-' + p2(d.getDate()) + ' ' + hm(d);
                else if (el.classList.contains('ct-ago')) { var mn = Math.max(0, Math.round((Date.now() - d.getTime()) / 60000)); el.textContent = dm(d) + ' в ' + hm(d) + ' (' + (mn < 60 ? Math.max(1, mn) + ' мин' : mn < 2880 ? Math.round(mn / 60) + ' ч' : Math.round(mn / 1440) + ' дн') + ' назад)'; }
                else if (el.hasAttribute('data-tipr')) el.setAttribute('data-tip', dm(d) + ' ' + hm(d) + el.getAttribute('data-tipr'));
                else if (el.hasAttribute('data-f')) el.textContent = el.getAttribute('data-f') === 'hm' ? hm(d) : dm(d);
            });
        })();
        var NAMES = <?= json_encode((object) $rv_names, $rv_js) ?>;
        var Q0 = <?= json_encode($rv_f['q'], $rv_js) ?>;
        var MX = <?= json_encode($rv_mx, $rv_js) ?>, MXK = <?= json_encode($rv_f['kind'], $rv_js) ?>;
        var MXQ = <?= json_encode(http_build_query(array_filter(['p' => $rv_f['p'], 'plat' => $rv_f['plat'], 'cc' => $rv_f['cc'], 'node' => $rv_f['node']], static fn($v) => $v !== '' && $v !== null)), $rv_js) ?>;
        var FQ = <?= json_encode(http_build_query(array_filter(['p' => $rv_f['p'], 'kind' => $rv_f['kind'], 'plat' => $rv_f['plat'], 'cc' => $rv_f['cc']], static fn($v) => $v !== '' && $v !== null)), $rv_js) ?>;
        var M = window.SUBMW_CIS;
        var CIS = {RU:1, BY:1, UA:1, MD:1, GE:1, AM:1, AZ:1, KZ:1, UZ:1, TM:1, KG:1, TJ:1};
        var KIND = {wifi: 'Wi-Fi', mobile: 'Мобильная', wired: 'Кабель', other: 'Другая'};
        var MED = ['< 100 мс', '100–200 мс', '200–400 мс', '400–800 мс', '0,8–1,5 с', '> 1,5 с'];
        var VIEWS = {all: [0, 0, 1000, 540]};
        var st = {view: 'all', vb: VIEWS.all.slice(), metric: 'fail', sel: null, side: null};
        var dn = null;
        try { dn = new Intl.DisplayNames(['ru'], {type: 'region'}); } catch (e) {}
        var ccName = function (cc) { try { return dn && cc ? dn.of(cc) : cc; } catch (e) { return cc; } };
        var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]; }); };
        var ico = function (id, cls) { return '<svg class="rvi' + (cls ? ' ' + cls : '') + '"><use href="#rvi-' + id + '"/></svg>'; };
        var hasFlag = function (x) { return /^\s*[\u{1F1E6}-\u{1F1FF}]{2}/u.test(String(x || '')); };
        var placeNm = function (rg, cc) { return rg && M.r[rg] ? M.r[rg].n + ', ' + ccName(cc) : (cc ? ccName(cc) : 'без геопозиции'); };
        var flagFor = function (cc, name) { return hasFlag(name) || !cc ? '' : flag(cc) + ' '; };
        var flag = function (cc) { return /^[A-Z]{2}$/.test(cc || '') ? '<span class="rv-fl" title="' + cc + '">' + String.fromCodePoint(0x1F1A5 + cc.charCodeAt(0), 0x1F1A5 + cc.charCodeAt(1)) + '</span>' : '<span class="rv-cc">??</span>'; };
        var fl = function (pf, pn) { return pn >= 20 ? pct(pf / pn) : pn > 0 ? pf + ' из ' + pn : '—'; };
        var pct = function (x) { return x === null || x === undefined ? '—' : x === 0 ? '0 %' : (x * 100 < 10 ? (x * 100).toFixed(1).replace('.', ',') : Math.round(x * 100)) + ' %'; };
        var qFail = function (x) { return x === null || x === undefined ? 0 : x < .01 ? 1 : x < .03 ? 2 : x < .10 ? 3 : x < .25 ? 4 : 5; };
        var qMed = function (m) { return m < 0 ? 0 : Math.min(5, m + 1); };
        var plural = function (n, a, b, c) { var m = n % 10, h = n % 100; return m === 1 && h !== 11 ? a : (m >= 2 && m <= 4 && (h < 10 || h >= 20) ? b : c); };
        var ago = function (ts) { if (!ts) return '—'; var s = Math.max(0, Date.now() / 1000 - ts); return s < 3600 ? Math.max(1, Math.round(s / 60)) + ' мин назад' : s < 172800 ? Math.round(s / 3600) + ' ч назад' : Math.round(s / 86400) + ' дн назад'; };
        var nameOf = function (s) { return NAMES[s] || ''; };
        document.querySelectorAll('[data-ccname]').forEach(function (el) { var cc = el.getAttribute('data-ccname'); if (/^[A-Z]{2}$/.test(cc)) el.textContent = ccName(cc); });

        var NODES = D.nodes || [];
        var DEVS = (D.devs || []).map(function (d, i) { return {i: i, s: d.s, p: d.p, m: d.m, o: d.o, c: d.c, lr: d.lr, nets: []}; });
        var NETS = (D.nets || []).map(function (x) {
            var n = {d: DEVS[x[0]], k: x[1], ip: x[2], cc: x[3], asn: x[4], org: x[5], loc: x[6], pn: x[7], pf: x[8], med: x[9], dead: x[10], frz: x[11], vok: x[12], hh: x[13], seen: x[14], ms: (D.ms && D.ms[x[15]]) || [], sub: x[16] || '', rg: null};
            n.fail = n.pn >= 20 ? n.pf / n.pn : null;
            n.d.nets.push(n);
            return n;
        });

        var rad = Math.PI / 180;
        var proj = function (la, lo) {
            var P = M.p, l = lo + P.l0; l = ((l + 180) % 360 + 360) % 360 - 180;
            var s0 = Math.sin(P.p1 * rad), n = (s0 + Math.sin(P.p2 * rad)) / 2, c = 1 + s0 * (2 * n - s0);
            var r = Math.sqrt(Math.max(0, c - 2 * n * Math.sin(la * rad))) / n, r0 = Math.sqrt(c) / n;
            return [P.tx + P.k * r * Math.sin(l * rad * n), P.ty - P.k * (r0 - r * Math.cos(l * rad * n))];
        };

        var gR = document.getElementById('rvGR'), gC = document.getElementById('rvGC'), NS = 'http://www.w3.org/2000/svg', PATHS = {};
        if (M && gR) {
            Object.keys(M.r).forEach(function (id) {
                var p = document.createElementNS(NS, 'path'); p.setAttribute('d', M.r[id].d); p.setAttribute('class', 'rg'); p.dataset.id = id; gR.appendChild(p); PATHS[id] = p;
            });
            Object.keys(M.c).forEach(function (cc) { var p = document.createElementNS(NS, 'path'); p.setAttribute('d', M.c[cc]); p.setAttribute('class', 'cb'); gC.appendChild(p); });
        }
        var BB = {};
        var nrm = function (x) { return String(x || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^a-z0-9]+/g, ' ').trim(); };
        var RTYPES = /\b(oblast|oblasti|oblisi|oblysy|voblasts|voblasc|voblast|region|respublikasi|republic|respublika|kray|krai|krayi|province|viloyati|viloyat|city|gorod|autonomous|avtonomnyy|okrug|district|rayon|raion|municipality|mkhare|qalasy|shahri|economic|of|the|a|s|s r|ao|aok|okr|skaya|skiy|g)\b/g;
        var strp = function (x) { return nrm(x).replace(RTYPES, ' ').replace(/\s+/g, ' ').trim(); };
        var regionOf = (function () {
            var cache = {};
            return function (loc, cc, sub) {
                if (!M || !CIS[cc]) return null;
                var key = (loc || '') + '|' + cc + '|' + (sub || '');
                if (key in cache) return cache[key];
                if (sub && M.a) {
                    var byName = M.a[cc + '|' + nrm(sub)] || M.a[cc + '|s:' + strp(sub)];
                    if (byName && M.r[byName]) return cache[key] = byName;
                }
                if (!loc) return cache[key] = null;
                var a = loc.split(','), xy = proj(+a[0], +a[1]), hit = null, ha = 1e12, best = null, bd = 1e9;
                Object.keys(PATHS).forEach(function (id) {
                    if (M.r[id].cc !== cc) return;
                    var b = BB[id] || (BB[id] = PATHS[id].getBBox());
                    var dx = Math.max(b.x - xy[0], 0, xy[0] - b.x - b.width), dy = Math.max(b.y - xy[1], 0, xy[1] - b.y - b.height), d0 = Math.hypot(dx, dy);
                    if (d0 === 0 && b.width * b.height < ha) {
                        var pt = (window.DOMPoint ? new DOMPoint(xy[0], xy[1]) : null);
                        try { if (pt && PATHS[id].isPointInFill(pt)) { hit = id; ha = b.width * b.height; } } catch (e) {}
                    }
                    var dc = Math.hypot(M.r[id].x - xy[0], M.r[id].y - xy[1]) + d0 * 4;
                    if (dc < bd) { bd = dc; best = id; }
                });
                return cache[key] = hit || (bd < 40 ? best : null);
            };
        })();
        NETS.forEach(function (n) { n.rg = regionOf(n.loc, n.cc, n.sub); });

        var netSt = function (nets) {
            var dead = {}, frz = {}, pn = 0, pf = 0, fr = 0, vok = 0, mb = [0, 0, 0, 0, 0, 0];
            nets.forEach(function (n) { n.dead.forEach(function (k) { dead[k] = 1; }); n.frz.forEach(function (k) { frz[k] = 1; }); pn += n.pn; pf += n.pf; fr += n.frz.length; vok += n.vok; if (n.med >= 0) mb[n.med] += n.pn - n.pf; });
            var tot = mb.reduce(function (a, b) { return a + b; }, 0), run = 0, med = -1;
            for (var i = 0; i < 6 && tot > 0; i++) { run += mb[i]; if (run * 2 >= tot) { med = i; break; } }
            var fail = pn >= 20 ? pf / pn : null, dk = Object.keys(dead).map(Number), fk = Object.keys(frz).map(Number);
            var s = 'ok', t = 'в порядке';
            if (dk.length) { s = 'bad'; t = dk.slice(0, 3).map(function (k) { return NODES[k].nm; }).join(', ') + (dk.length > 3 ? ' и ещё ' + (dk.length - 3) : '') + (dk.length === 1 ? ' не отвечает' : ' не отвечают'); }
            else if (fail !== null && fail >= .1) { s = 'bad'; t = 'неудачных пингов ' + pct(fail); }
            else if (fk.length) { s = 'warn'; t = '16–20 режется: ' + fk.length + ' ' + plural(fk.length, 'узел', 'узла', 'узлов'); }
            else if (fail !== null && fail >= .03) { s = 'warn'; t = 'неудачных пингов ' + pct(fail); }
            else if (med >= 3) { s = 'warn'; t = 'медиана ' + MED[med]; }
            return {st: s, stt: t, fail: fail, med: med, pn: pn, pf: pf, fr: fr, vok: vok, mb: mb};
        };
        DEVS.forEach(function (d) {
            d.here = d.nets.slice().sort(function (a, b) { return (b.hh - a.hh) || (b.seen - a.seen); })[0] || null;
            var r = netSt(d.nets);
            d.st = r.st; d.stt = r.stt; d.fail = r.fail; d.med = r.med;
        });

        var rank = {ok: 0, warn: 1, bad: 2};
        var bag = function (entries) {
            var pn = 0, pf = 0, fr = 0, vok = 0, mb = [0, 0, 0, 0, 0, 0], s = 'ok', cl = {};
            entries.forEach(function (e) {
                var r = e.r || (e.r = netSt(e.nets));
                pn += r.pn; pf += r.pf; fr += r.fr; vok += r.vok;
                for (var i = 0; i < 6; i++) mb[i] += r.mb[i];
                if (rank[r.st] > rank[s]) s = r.st;
                cl[e.d.s] = 1;
            });
            var tot = mb.reduce(function (a, b) { return a + b; }, 0), run = 0, med = -1;
            for (var i = 0; i < 6 && tot > 0; i++) { run += mb[i]; if (run * 2 >= tot) { med = i; break; } }
            return {devs: entries, n: entries.length, k: Object.keys(cl).length, pn: pn, fail: pn >= 20 ? pf / pn : null, med: med, fr: fr, vok: vok, st: s};
        };
        var REG = {}, OUT = {}, NOREG = {}, NOGEO = [];
        var putIdx = {};
        var put = function (m, key, d, n) {
            var list = m[key] || (m[key] = []), ik = key + '|' + d.i, e = putIdx[ik];
            if (!e || e.m !== m) { e = putIdx[ik] = {d: d, nets: [], m: m}; list.push(e); }
            e.nets.push(n);
        };
        DEVS.forEach(function (d) {
            var placed = false;
            d.nets.forEach(function (n) {
                if (n.rg) { put(REG, n.rg, d, n); placed = true; }
                else if (n.cc && !CIS[n.cc]) { put(OUT, n.cc, d, n); placed = true; }
            });
            if (placed || !d.here) return;
            var withCc = d.nets.filter(function (n) { return n.cc; })[0];
            if (withCc) put(NOREG, withCc.cc, d, withCc); else NOGEO.push({d: d, nets: d.nets.slice()});
        });
        var RB = {};
        Object.keys(REG).forEach(function (id) { RB[id] = bag(REG[id]); });

        var regionQ = function (id) {
            var r = RB[id]; if (!r) return 0;
            if (st.metric === 'fail') return r.fail !== null ? qFail(r.fail) : 0;
            if (st.metric === 'med') return qMed(r.med);
            var all = r.fr + r.vok; if (!all) return 0; var b = r.fr / all;
            return b === 0 ? 1 : b < .1 ? 2 : b < .25 ? 3 : b < .5 ? 4 : 5;
        };
        var paint = function () {
            Object.keys(PATHS).forEach(function (id) {
                var p = PATHS[id], q = regionQ(id);
                p.setAttribute('class', 'rg' + (RB[id] ? ' has' : '') + (q ? ' q' + q : ''));
            });
            document.getElementById('rvMap').setAttribute('viewBox', st.vb.join(' '));
            dots();
            var L = {fail: ['< 1 %', '1–3 %', '3–10 %', '10–25 %', '≥ 25 %'], med: ['< 100 мс', '100–200 мс', '200–400 мс', '400–800 мс', '> 0,8 с'], frz: ['всё проходит', '< 10 % режется', '10–25 %', '25–50 %', '≥ 50 %']}[st.metric];
            document.getElementById('rvLegend').innerHTML = L.map(function (t, i) { return '<span><i style="background:var(--rq' + (i + 1) + ');opacity:.6"></i>' + t + '</span>'; }).join('')
                + '<span><i style="background:var(--accent);opacity:.4"></i>клиенты есть, данных мало</span><span><i style="background:var(--line)"></i>нет клиентов</span><span class="sv"></span>'
                + '<span><span class="dl" style="background:var(--rq1)"></span>всё работает</span><span><span class="dl" style="background:var(--rq3)"></span>деградация</span><span><span class="dl" style="background:var(--rq5)"></span>узлы не отвечают</span>';
        };
        var clusters = function () {
            var vb = st.vb, box = document.getElementById('rvMapBox').getBoundingClientRect(), k = box.width / vb[2] || 1, out = [];
            Object.keys(RB).map(function (id) { return {ids: [id], x: M.r[id].x, y: M.r[id].y, n: RB[id].n}; })
                .filter(function (p) { return p.x >= vb[0] + vb[2] * .02 && p.x <= vb[0] + vb[2] * .98 && p.y >= vb[1] + vb[3] * .03 && p.y <= vb[1] + vb[3] * .97; })
                .sort(function (a, b) { return b.n - a.n; })
                .forEach(function (p) {
                    var hit = null;
                    out.forEach(function (o) { if (!hit && Math.hypot((o.x - p.x) * k, (o.y - p.y) * k) < 30) hit = o; });
                    if (hit) { hit.ids = hit.ids.concat(p.ids); hit.n += p.n; } else out.push(p);
                });
            return out;
        };
        var bagOf = function (ids) { var a = []; ids.forEach(function (id) { a = a.concat(REG[id] || []); }); return bag(a); };
        var dots = function () {
            var vb = st.vb, el = document.getElementById('rvDots');
            el.innerHTML = clusters().map(function (c) {
                var b = bagOf(c.ids), sz = b.n >= 10 ? 'l' : b.n >= 4 ? 'm' : 's', key = c.ids.slice().sort().join('+');
                return '<button type="button" class="rv-dot st-' + b.st + ' ' + sz + (st.sel && st.sel.key === key ? ' sel' : '') + '" style="left:' + ((c.x - vb[0]) / vb[2] * 100).toFixed(2) + '%;top:' + ((c.y - vb[1]) / vb[3] * 100).toFixed(2) + '%" data-ids="' + c.ids.join(',') + '" data-x="' + c.x + '" data-y="' + c.y + '" aria-label="' + esc(c.ids.map(function (id) { return M.r[id].n; }).join(', ')) + ': ' + b.n + '">' + b.n + '</button>';
            }).join('');
            el.querySelectorAll('.rv-dot').forEach(function (d) { d.addEventListener('click', function (e) { e.stopPropagation(); openPop(d.dataset.ids.split(','), +d.dataset.x, +d.dataset.y); }); });
            if (st.sel) placePop();
        };

        var rows = function (entries, where) {
            var by = {}, order = [];
            entries.forEach(function (e) { if (!e.r) e.r = netSt(e.nets); if (!by[e.d.s]) { by[e.d.s] = []; order.push(e.d.s); } by[e.d.s].push(e); });
            var worst = function (s) { return Math.max.apply(null, by[s].map(function (e) { return rank[e.r.st]; })); };
            order.sort(function (a, b) { return (worst(b) - worst(a)) || (by[b].length - by[a].length); });
            return order.map(function (s) {
                var es = by[s].slice().sort(function (a, b) { return rank[b.r.st] - rank[a.r.st]; }), e = es[0], nm = nameOf(s);
                var nets = []; es.forEach(function (x) { x.nets.forEach(function (n) { nets.push(n); }); });
                var n = nets[0] || {};
                var place = where ? (n.rg ? M.r[n.rg].n : (n.cc ? ccName(n.cc) : 'адрес неизвестен')) + ' · ' : '';
                var kinds = nets.map(function (x) { return ico(KIND[x.k] ? x.k : 'other'); }).join('');
                var asns = []; nets.forEach(function (x) { var t = x.asn ? 'AS' + x.asn + ' ' + x.org : 'AS —'; if (asns.indexOf(t) < 0) asns.push(t); });
                return '<button type="button" class="rv-crow" data-s="' + esc(s) + '"><span class="av">' + esc((nm || s).slice(0, 2).toUpperCase()) + '</span><span class="bd">'
                    + '<span class="nm">' + esc(nm || s) + (nm ? '<code>' + esc(s.slice(0, 10)) + '</code>' : '') + '</span>'
                    + '<span class="sub">' + es.map(function (x) { return ico(x.d.p === 'pc' ? 'pc' : 'android'); }).join('') + '<span>' + es.length + ' ' + plural(es.length, 'устройство', 'устройства', 'устройств') + '</span><span>·</span>' + kinds + '<span>' + esc(place) + esc(asns.join(', ')) + '</span></span>'
                    + '<span class="st"><span class="rv-pill st-' + e.r.st + '">' + esc(e.r.stt) + '</span></span></span>' + ico('chev') + '</button>';
            }).join('');
        };
        var bindRows = function (root) { root.querySelectorAll('.rv-crow').forEach(function (b) { b.addEventListener('click', function () { openClient(b.dataset.s); }); }); };

        var openPop = function (ids, x, y) {
            st.sel = {ids: ids, x: x, y: y, key: ids.slice().sort().join('+')};
            var b = bagOf(ids), pop = document.getElementById('rvPop');
            pop.innerHTML = '<div class="rv-ph">' + flag(M.r[ids[0]].cc) + '<div style="min-width:0"><b>' + esc(ids.map(function (id) { return M.r[id].n; }).join(', ')) + '</b>'
                + '<div class="rv-pst"><span class="rv-pill st-mut">' + b.n + ' устр. · ' + b.k + ' ' + plural(b.k, 'клиент', 'клиента', 'клиентов') + '</span><span class="rq q' + qFail(b.fail) + '">неудачи ' + pct(b.fail) + '</span><span class="rv-pill st-mut">медиана ' + (MED[b.med] || '—') + '</span></div></div>'
                + '<button type="button" class="x" aria-label="Закрыть">' + ico('x') + '</button></div><div class="rv-pl">' + rows(b.devs, ids.length > 1) + '</div>';
            pop.classList.add('open');
            pop.querySelector('.x').addEventListener('click', closePop);
            bindRows(pop);
            dots();
        };
        var placePop = function () {
            var pop = document.getElementById('rvPop'), vb = st.vb, s = st.sel, box = document.getElementById('rvMapBox').getBoundingClientRect();
            var px = (s.x - vb[0]) / vb[2] * 100, py = (s.y - vb[1]) / vb[3] * 100;
            pop.style.left = pop.style.right = '';
            pop.style.maxHeight = (box.height - 16) + 'px';
            if (box.width < 560) pop.style.left = '8px';
            else if (px < 58) pop.style.left = 'calc(' + px + '% + 26px)'; else pop.style.right = 'calc(' + (100 - px) + '% + 26px)';
            var h = pop.offsetHeight, yy = py / 100 * box.height;
            pop.style.top = Math.max(8, Math.min(box.height - h - 8, yy - 40)) + 'px';
            var z = document.querySelector('.rv-zoom');
            if (z) {
                var zr = z.getBoundingClientRect(), pr = pop.getBoundingClientRect();
                if (pr.right > zr.left - 6 && pr.top < zr.bottom + 6 && pr.bottom > zr.top - 6) {
                    var below = zr.bottom - box.top + 8, dx = px / 100 * box.width;
                    var nl = pr.left - box.left - (pr.right - zr.left + 8);
                    if (below + h <= box.height - 8) pop.style.top = below + 'px';
                    else if (nl >= 8 && (nl + pr.width < dx - 14 || nl > dx + 14)) { pop.style.right = ''; pop.style.left = nl + 'px'; }
                    else { pop.style.top = below + 'px'; pop.style.maxHeight = Math.max(160, box.height - below - 8) + 'px'; }
                }
            }
        };
        document.addEventListener('click', function (e) {
            if (!st.sel || Date.now() - drag.end < 250) return;
            var pop = document.getElementById('rvPop');
            if (pop.contains(e.target) || (e.target.closest && e.target.closest('.rv-dot, #rvGR path.has, .rv-drw, .rv-drw-bg'))) return;
            closePop();
        });
        var closePop = function () { st.sel = null; document.getElementById('rvPop').classList.remove('open'); dots(); };

        var side = function () {
            var list = Object.keys(OUT).map(function (cc) { return {cc: cc, b: bag(OUT[cc])}; }).sort(function (a, b) { return b.b.n - a.b.n; });
            var total = list.reduce(function (s, x) { return s + x.b.n; }, 0);
            var row = function (key, title, sub, b, fl) {
                var on = st.side === key;
                return '<button type="button" class="rv-srow' + (on ? ' on' : '') + '" data-k="' + esc(key) + '">' + fl + '<span class="n">' + esc(title) + '<small>' + esc(sub) + '</small></span><span class="rv-sdot st-' + b.st + '"></span><span class="c">' + b.n + '</span></button>'
                    + (on ? '<div class="rv-sexp">' + rows(b.devs, false) + '</div>' : '');
            };
            var h = '<div class="rv-sbox"><div class="rv-sh">' + ico('globe') + '<b>Вне СНГ</b><span class="rv-pill st-mut">' + total + ' устр.</span></div>'
                + (list.length ? list.map(function (x) { var n0 = x.b.devs[0].nets[0]; return row('o:' + x.cc, ccName(x.cc), x.b.k + ' ' + plural(x.b.k, 'клиент', 'клиента', 'клиентов') + (n0 && n0.asn ? ' · AS' + n0.asn + ' ' + n0.org : ''), x.b, flag(x.cc)); }).join('') : '<div class="muted" style="padding:.6rem .8rem;font-size:.8rem">Нет устройств за пределами СНГ.</div>') + '</div>';
            var nr = Object.keys(NOREG);
            if (nr.length) h += '<div class="rv-sbox"><div class="rv-sh">' + ico('pin') + '<b>СНГ, регион неизвестен</b></div>' + nr.map(function (cc) { var b = bag(NOREG[cc]); return row('r:' + cc, ccName(cc), 'нет координат адреса — нет базы городов или старые отчёты', b, flag(cc)); }).join('') + '</div>';
            if (NOGEO.length) h += '<div class="rv-sbox">' + row('n:', 'Без геопозиции', 'базы GeoIP нет или адрес ей неизвестен', bag(NOGEO), '<span class="rv-cc">??</span>') + '</div>';
            var el = document.getElementById('rvSide');
            el.innerHTML = h;
            el.querySelectorAll('.rv-srow').forEach(function (b) { b.addEventListener('click', function () { st.side = st.side === b.dataset.k ? null : b.dataset.k; side(); }); });
            bindRows(el);
        };

        var placeName = function (n) { return n.rg ? M.r[n.rg].n : (n.cc ? ccName(n.cc) : 'без геопозиции'); };
        var incidents = function () {
            var out = [], groups = {}, devSide = {}, ak = {}, pk = {};
            var cnt = function (o) { return o ? Object.keys(o).length : 0; };
            var bump = function (m, key, dead) { var c = m[key] || (m[key] = {ms: 0, dead: 0}); c.ms++; if (dead) c.dead++; };
            NETS.forEach(function (n) {
                if (!n.asn) return;
                n.ms.forEach(function (k) { var dd = n.dead.indexOf(k) >= 0; bump(ak, n.asn + '|' + k, dd); bump(pk, (n.rg || n.cc) + '|' + k, dd); });
            });
            DEVS.forEach(function (d) {
                var dk = {};
                d.nets.forEach(function (n) { n.dead.forEach(function (k) { dk[k] = 1; }); });
                Object.keys(dk).map(Number).forEach(function (k) {
                    var mine = d.nets.filter(function (n) { return n.ms.indexOf(k) >= 0; });
                    if (!mine.length || !mine.every(function (n) { return n.dead.indexOf(k) >= 0; })) return;
                    var own = {}, pm = 0, pd = 0;
                    mine.forEach(function (n) { if (n.asn) own[n.asn] = (own[n.asn] || 0) + 1; });
                    Object.keys(own).forEach(function (a) { var c = ak[a + '|' + k]; if (c) { pm += c.ms - own[a]; pd += c.dead - own[a]; } });
                    var ok = pm - pd;
                    if (pm >= 2 && ok / pm >= .8) { devSide[d.i + '|' + k] = 1; out.push({sev: 2.5, tag: 'dev', tagT: 'на устройстве', ic: 'phone', icc: 'info', t: NODES[k].nm + ' не отвечает только у ' + (nameOf(d.s) || d.s) + ' — во всех его сетях', why: esc([d.m, d.o, d.c].filter(Boolean).join(', ')) + (d.m || d.o || d.c ? '. ' : '') + 'У ' + ok + ' из ' + pm + ' других сетей тех же провайдеров узел работает. Вероятно, устаревший конфиг или неверное время на устройстве — пусть обновит подписку.', client: d.s}); }
                });
            });
            var nodeAll = {};
            NODES.forEach(function (nd, k) {
                var ms = 0, dd = 0, asn = {};
                NETS.forEach(function (n) { if (n.ms.indexOf(k) < 0) return; ms++; if (n.asn) asn[n.asn] = 1; if (n.dead.indexOf(k) >= 0 && !devSide[n.d.i + '|' + k]) dd++; });
                if (ms >= 3 && dd / ms >= .8) { nodeAll[k] = 1; out.push({sev: 4, tag: 'node', tagT: 'узел недоступен', ic: 'server', icc: 'bad', t: nd.nm + ' не отвечает почти ни у кого', why: 'Без ответа в <b>' + dd + ' из ' + ms + '</b> ' + plural(ms, 'сети', 'сетей', 'сетей') + (cnt(asn) >= 2 ? ' у разных провайдеров — скорее всего, лежит сам узел или его порт.' : ' — все они у одного провайдера, так что это может быть и блок у него.') + ' <code>' + esc(nd.a) + '</code>', node: nd.k}); }
            });
            NETS.forEach(function (n) {
                if (!n.asn) return;
                var key = n.asn + '|' + (n.rg || n.cc);
                var g = groups[key] = groups[key] || {asn: n.asn, org: n.org, pkey: n.rg || n.cc, place: placeName(n), rg: n.rg, devs: {}, ms: {}, dead: {}, frz: {}, msN: {}, deadN: {}};
                g.devs[n.d.i] = 1;
                n.ms.forEach(function (k) { (g.ms[k] = g.ms[k] || {})[n.d.i] = 1; g.msN[k] = (g.msN[k] || 0) + 1; if (n.dead.indexOf(k) >= 0) g.deadN[k] = (g.deadN[k] || 0) + 1; });
                n.dead.forEach(function (k) { if (!devSide[n.d.i + '|' + k]) (g.dead[k] = g.dead[k] || {})[n.d.i] = 1; });
                n.frz.forEach(function (k) { (g.frz[k] = g.frz[k] || {})[n.d.i] = 1; });
            });
            var isp = {}, frz = [];
            Object.keys(groups).forEach(function (key) {
                var g = groups[key], cmp = {};
                var dn = Object.keys(g.dead).map(Number).filter(function (k) {
                    if (nodeAll[k] || cnt(g.dead[k]) < 2 || cnt(g.dead[k]) / Math.max(1, cnt(g.ms[k])) < .5) return false;
                    var c = pk[g.pkey + '|' + k] || {ms: 0, dead: 0}, om = c.ms - (g.msN[k] || 0), od = c.dead - (g.deadN[k] || 0);
                    if (om > 0 && od / om >= .5) return false;
                    cmp[k] = om > 0;
                    return true;
                });
                if (dn.length) {
                    var hit = {}; dn.forEach(function (k) { Object.keys(g.dead[k]).forEach(function (i) { hit[i] = 1; }); });
                    var ik = dn.join(',');
                    (isp[ik] = isp[ik] || {dn: dn, places: []}).places.push({org: g.org, place: g.place, hit: cnt(hit), tot: cnt(g.devs), asn: g.asn, rg: g.rg, cmp: dn.every(function (k) { return cmp[k]; })});
                }
                var fz = Object.keys(g.frz).map(Number).filter(function (k) { return cnt(g.frz[k]) >= 2 && cnt(g.frz[k]) / Math.max(1, cnt(g.ms[k])) >= .5; });
                if (fz.length) { var fh = {}; fz.forEach(function (k) { Object.keys(g.frz[k]).forEach(function (i) { fh[i] = 1; }); }); frz.push({org: g.org, place: g.place, fz: fz, hit: cnt(fh), tot: cnt(g.devs), asn: g.asn, rg: g.rg}); }
            });
            var byAsn = function (list, part) {
                var o = {}, order = [];
                list.forEach(function (p) { if (!o[p.asn]) { o[p.asn] = {org: p.org, asn: p.asn, items: [], hit: 0}; order.push(p.asn); } o[p.asn].items.push(p); o[p.asn].hit += p.hit; });
                order.sort(function (a, b) { return o[b].hit - o[a].hit; });
                return {n: order.length, html: order.slice(0, 5).map(function (a) { var g = o[a]; return '<b>' + esc(g.org || 'AS' + a) + ' (AS' + a + ')</b>: ' + g.items.slice(0, 6).map(part).join(', ') + (g.items.length > 6 ? ' и ещё ' + (g.items.length - 6) : ''); }).join('; ') + (order.length > 5 ? '; ещё ' + (order.length - 5) + ' ' + plural(order.length - 5, 'провайдер', 'провайдера', 'провайдеров') : '')};
            };
            Object.keys(isp).forEach(function (ik) {
                var x = isp[ik], one = x.dn.length === 1, hy = x.dn.every(function (k) { return /hysteria|tuic|wireguard|quic/i.test(NODES[k].t); });
                x.places.sort(function (a, b) { return b.hit - a.hit; });
                var ga = byAsn(x.places, function (p) { return esc(p.place) + ' — ' + p.hit + ' из ' + p.tot + ' ' + plural(p.tot, 'устройства', 'устройств', 'устройств'); });
                out.push({sev: 3 + x.places.length / 1000, tag: 'isp', tagT: 'блок у провайдера', ic: 'xoct', icc: 'bad',
                    t: x.dn.map(function (k) { return NODES[k].nm; }).join(', ') + ' не ' + (one ? 'отвечает' : 'отвечают') + ' у ' + ga.n + ' ' + plural(ga.n, 'провайдера', 'провайдеров', 'провайдеров'),
                    why: ga.html + '. ' + (x.places.every(function (p) { return p.cmp; }) ? 'У других провайдеров в тех же местах ' + (one ? 'узел работает' : 'узлы работают') + '.' : x.places.some(function (p) { return p.cmp; }) ? 'Где есть с кем сравнить, у других провайдеров ' + (one ? 'узел работает' : 'узлы работают') + '; в остальных местах других провайдеров в отчётах нет.' : 'Других провайдеров в этих местах в отчётах нет — сравнить не с кем.') + (hy ? ' Все — на UDP (' + esc(NODES[x.dn[0]].t) + '): похоже, режется UDP.' : ''),
                    mx: {nk: NODES[x.dn[0]].k, asn: x.places[0].asn}, rg: x.places[0].rg});
            });
            if (frz.length) {
                frz.sort(function (a, b) { return b.hit - a.hit; });
                var gf = byAsn(frz, function (p) { return esc(p.place) + ' — ' + p.fz.length + ' ' + plural(p.fz.length, 'узел', 'узла', 'узлов') + ', ' + p.hit + ' из ' + p.tot + ' ' + plural(p.tot, 'устройства', 'устройств', 'устройств'); });
                out.push({sev: 2, tag: 'frz', tagT: 'режется 16–20', ic: 'scissors', icc: 'warn', t: '16–20 режется у ' + gf.n + ' ' + plural(gf.n, 'провайдера', 'провайдеров', 'провайдеров') + ' — пинг есть, данные не идут',
                    why: gf.html + '. Соединение рвётся после первых килобайт — так работает DPI. Помогает другой транспорт или порт.',
                    mx: {nk: NODES[frz[0].fz[0]].k, asn: frz[0].asn}, rg: frz[0].rg});
            }
            var far = Object.keys(RB).filter(function (id) { return RB[id].med >= 3 && RB[id].pn >= 100; });
            if (far.length) out.push({sev: 1, tag: 'far', tagT: 'высокий пинг', ic: 'clock', icc: 'info', t: 'Высокий пинг: ' + far.slice(0, 6).map(function (id) { return M.r[id].n; }).join(', ') + (far.length > 6 ? ' и ещё ' + (far.length - 6) : ''), why: 'Медиана ' + MED[RB[far[0]].med] + ' и выше. Клиентам там стоит предложить узлы поближе.', rg: far[0]});
            return out.sort(function (a, b) { return b.sev - a.sev; });
        };
        var renderIncs = function () {
            var list = incidents(), el = document.getElementById('rvIncs'), lim = 8;
            if (!list.length) { el.innerHTML = '<div class="rv-inc"><span class="ic st-info">' + ico('check') + '</span><div><div class="t">Явных проблем нет</div><div class="why">' + (DEVS.length ? 'Ни один узел не молчит у групп клиентов, 16–20 не режется.' : 'Нет отчётов за период.') + '</div></div></div>'; return; }
            var draw = function (all) {
                el.innerHTML = list.slice(0, all ? list.length : lim).map(function (x, i) {
                    return '<div class="rv-inc"><span class="ic st-' + x.icc + '">' + ico(x.ic) + '</span><div style="flex:1;min-width:0"><div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap"><span class="rv-tag ' + x.tag + '">' + x.tagT + '</span><span class="t">' + esc(x.t) + '</span></div><div class="why">' + x.why + '</div><div class="act">'
                        + (x.mx ? '<button type="button" class="btn ghost" data-i="' + i + '" data-a="mx">' + ico('list') + 'Кто именно</button>' : '')
                        + (x.rg ? '<button type="button" class="btn ghost" data-i="' + i + '" data-a="map">' + ico('pin') + 'На карте</button>' : '')
                        + (x.client ? '<button type="button" class="btn ghost" data-i="' + i + '" data-a="cl">' + ico('user') + 'Открыть клиента</button>' : '')
                        + (x.node ? '<a class="btn ghost" href="?' + esc(FQ) + '&tab=reports&node=' + esc(x.node) + '">' + ico('server') + 'Только этот узел</a>' : '')
                        + '</div></div></div>';
                }).join('') + (!all && list.length > lim ? '<div style="padding-top:.5rem"><button type="button" class="btn ghost" id="rvIncAll">Показать все (' + list.length + ')</button></div>' : '');
                var more = document.getElementById('rvIncAll'); if (more) more.addEventListener('click', function () { draw(true); });
                el.querySelectorAll('[data-a]').forEach(function (b) {
                    b.addEventListener('click', function (e) {
                        e.stopPropagation();
                        var x = list[+b.dataset.i];
                        if (b.dataset.a === 'cl') openClient(x.client);
                        if (b.dataset.a === 'mx') { var c = document.querySelector('.rv-mc[data-nk="' + x.mx.nk + '"][data-asn="' + x.mx.asn + '"]'); if (c) { c.click(); document.getElementById('rvMxCard').scrollIntoView({behavior: 'smooth'}); } }
                        if (b.dataset.a === 'map') showRegion(x.rg);
                    });
                });
            };
            draw(false);
        };
        var showRegion = function (id) {
            var r = M.r[id]; if (!r) return;
            var v = st.vb, inside = r.x >= v[0] + v[2] * .05 && r.x <= v[0] + v[2] * .95 && r.y >= v[1] + v[3] * .05 && r.y <= v[1] + v[3] * .95;
            if (!inside || v[2] > 500) { var w = Math.min(v[2], 360); zoomTo([r.x - w / 2, r.y - w * .27, w, w * .54], false); }
            document.getElementById('rvMapBox').scrollIntoView({behavior: 'smooth', block: 'center'});
            var dot = Array.prototype.find.call(document.querySelectorAll('.rv-dot'), function (d) { return d.dataset.ids.split(',').indexOf(id) >= 0; });
            if (dot) openPop(dot.dataset.ids.split(','), +dot.dataset.x, +dot.dataset.y);
        };

        var MAPW = 1000, MAPH = 540, MINW = 45;
        var clampVB = function (v) {
            var w = Math.max(MINW, Math.min(MAPW, v[2])), h = w * MAPH / MAPW;
            var cx = v[0] + v[2] / 2, cy = v[1] + v[3] / 2;
            var x = Math.max(-w * .25, Math.min(MAPW - w * .75, cx - w / 2)), y = Math.max(-h * .25, Math.min(MAPH - h * .75, cy - h / 2));
            if (w >= MAPW) { x = 0; y = 0; }
            return [x, y, w, h];
        };
        var raf = 0, anim = 0;
        var redraw = function () { if (raf) return; raf = requestAnimationFrame(function () { raf = 0; document.getElementById('rvMap').setAttribute('viewBox', st.vb.map(function (v) { return v.toFixed(2); }).join(' ')); zoomUi(); dots(); }); };
        var zoomUi = function () {
            var near = function (a) { return a.every(function (v, i) { return Math.abs(v - st.vb[i]) < .5; }); };
            var zi = document.getElementById('rvZin'), zo = document.getElementById('rvZout'), zr = document.getElementById('rvZreset');
            if (zi) zi.disabled = st.vb[2] <= MINW + .5;
            if (zo) zo.disabled = st.vb[2] >= MAPW - .5;
            if (zr) zr.hidden = near(VIEWS.all);
        };
        var zoomTo = function (v, animate) {
            var to = clampVB(v), from = st.vb.slice();
            cancelAnimationFrame(anim);
            if (!animate || window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches) { st.vb = to; redraw(); return; }
            var t0 = performance.now();
            var step = function (t) {
                var k = Math.min(1, (t - t0) / 220), e = 1 - Math.pow(1 - k, 3);
                st.vb = from.map(function (f, i) { return f + (to[i] - f) * e; });
                redraw();
                if (k < 1) anim = requestAnimationFrame(step);
            };
            anim = requestAnimationFrame(step);
        };
        var zoomAt = function (factor, px, py, animate) {
            var v = st.vb, w = Math.max(MINW, Math.min(MAPW, v[2] / factor)), f = w / v[2];
            zoomTo([px - (px - v[0]) * f, py - (py - v[1]) * f, w, w * MAPH / MAPW], animate);
        };
        var mapBox = document.getElementById('rvMapBox');
        var toMap = function (cx, cy) { var b = mapBox.getBoundingClientRect(), v = st.vb; return [v[0] + (cx - b.left) / b.width * v[2], v[1] + (cy - b.top) / b.height * v[3]]; };
        var drag = {end: 0, ptrs: {}, moved: false, start: null};
        var hint = document.getElementById('rvZhint'), hintT = 0, mapActive = false;
        var showHint = function () { if (!hint) return; hint.classList.add('on'); clearTimeout(hintT); hintT = setTimeout(function () { hint.classList.remove('on'); }, 1400); };
        if (mapBox) {
            mapBox.addEventListener('wheel', function (e) {
                if (e.target.closest && e.target.closest('.rv-pop')) return;
                if (!mapActive && !e.ctrlKey && !e.metaKey) { showHint(); return; }
                e.preventDefault();
                var m = toMap(e.clientX, e.clientY);
                zoomAt(Math.pow(1.0018, -(e.deltaMode === 1 ? e.deltaY * 33 : e.deltaY)), m[0], m[1], false);
            }, {passive: false});
            mapBox.addEventListener('mouseleave', function () { mapActive = false; });
            mapBox.addEventListener('dblclick', function (e) {
                if (e.target.closest && e.target.closest('.rv-pop, .rv-zoom, .rv-dot')) return;
                e.preventDefault();
                var m = toMap(e.clientX, e.clientY); zoomAt(e.shiftKey ? .5 : 2, m[0], m[1], true);
            });
            mapBox.addEventListener('pointerdown', function (e) {
                if (e.button > 0 || (e.target.closest && e.target.closest('.rv-pop, .rv-zoom, .rv-dot'))) return;
                mapActive = true;
                drag.ptrs[e.pointerId] = {x: e.clientX, y: e.clientY};
                drag.moved = false;
                drag.start = {vb: st.vb.slice(), ptrs: JSON.parse(JSON.stringify(drag.ptrs))};
            });
            window.addEventListener('pointermove', function (e) {
                if (!drag.ptrs[e.pointerId] || !drag.start) return;
                drag.ptrs[e.pointerId] = {x: e.clientX, y: e.clientY};
                var ids = Object.keys(drag.ptrs), b = mapBox.getBoundingClientRect(), v0 = drag.start.vb, p0 = drag.start.ptrs;
                if (ids.length >= 2 && p0[ids[0]] && p0[ids[1]]) {
                    var a = drag.ptrs[ids[0]], c = drag.ptrs[ids[1]], a0 = p0[ids[0]], c0 = p0[ids[1]];
                    var d0 = Math.hypot(a0.x - c0.x, a0.y - c0.y) || 1, d1 = Math.hypot(a.x - c.x, a.y - c.y) || 1;
                    var w = Math.max(MINW, Math.min(MAPW, v0[2] * d0 / d1)), mx0 = (a0.x + c0.x) / 2, my0 = (a0.y + c0.y) / 2, mx1 = (a.x + c.x) / 2, my1 = (a.y + c.y) / 2;
                    var px = v0[0] + (mx0 - b.left) / b.width * v0[2], py = v0[1] + (my0 - b.top) / b.height * v0[3], h = w * MAPH / MAPW;
                    st.vb = clampVB([px - (mx1 - b.left) / b.width * w, py - (my1 - b.top) / b.height * h, w, h]);
                    drag.moved = true; redraw(); return;
                }
                var q = p0[e.pointerId]; if (!q) return;
                var dx = e.clientX - q.x, dy = e.clientY - q.y;
                if (!drag.moved && Math.hypot(dx, dy) < 5) return;
                if (!drag.moved) { drag.moved = true; mapBox.classList.add('dragging'); tip.style.display = 'none'; }
                st.vb = clampVB([v0[0] - dx / b.width * v0[2], v0[1] - dy / b.height * v0[3], v0[2], v0[3]]);
                redraw();
            });
            var up = function (e) {
                if (!drag.ptrs[e.pointerId]) return;
                delete drag.ptrs[e.pointerId];
                if (drag.moved) drag.end = Date.now();
                mapBox.classList.remove('dragging');
                drag.start = Object.keys(drag.ptrs).length ? {vb: st.vb.slice(), ptrs: JSON.parse(JSON.stringify(drag.ptrs))} : null;
            };
            window.addEventListener('pointerup', up);
            window.addEventListener('pointercancel', up);
            var zbtn = function (id, fn) { var el = document.getElementById(id); if (el) el.addEventListener('click', fn); };
            zbtn('rvZin', function () { zoomAt(2, st.vb[0] + st.vb[2] / 2, st.vb[1] + st.vb[3] / 2, true); });
            zbtn('rvZout', function () { zoomAt(.5, st.vb[0] + st.vb[2] / 2, st.vb[1] + st.vb[3] / 2, true); });
            zbtn('rvZreset', function () { closePop(); zoomTo(VIEWS.all.slice(), true); });
        }

        var histWhy = function (seen) {
            if (!HST.with) return 'Почасовой истории ещё нет ни в одном отчёте: все отчёты за период пришли до обновления прослойки. Клиент шлёт отчёт при плановом обновлении подписки, не чаще раза в 6 часов — полоса заполнится со следующего.';
            if (seen < HST.first) return 'Этот отчёт пришёл ' + ago(seen) + ', до обновления прослойки — по часам он не разложен. Первый отчёт с историей пришёл ' + ago(HST.first) + '; у этого устройства полоса заполнится со следующего отчёта (не чаще раза в 6 часов).';
            return 'В отчёте ' + ago(seen) + ' не было часов с пингами до этого узла в этой сети.';
        };
        var hoursTbl = function (r, day, dayNm) {
            if (!r.hc || !r.hh) return '';
            var cells = r.hc.split(','), len = cells.length, rows = [], w6 = 0;
            for (var i = len - 1; i >= 0; i--) {
                if (!cells[i]) continue;
                var v = cells[i].split('.').map(function (x) { return parseInt(x, 36) || 0; });
                while (v.length < 8) v.push(0);
                var row = {t: r.hh - (len - 1 - i) * 3600, pn: v[0], pf: Math.min(v[1], v[0]), b: v.slice(2, 8), w6: false};
                if (row.pn > 0 && w6 < 6) { row.w6 = true; w6++; }
                rows.push(row);
            }
            if (!rows.length) return '';
            if (day) {
                rows = rows.filter(function (x) { return x.t >= day && x.t < day + 86400; });
                if (!rows.length) return '<div class="muted" style="font-size:.78rem;margin-top:.6rem">Почасовых замеров за этот день нет: по часам хранятся только последние 48 часов.</div>';
            }
            var p2 = function (n) { return (n < 10 ? '0' : '') + n; };
            var tf = function (t) { var d = new Date(t * 1000); return p2(d.getDate()) + '.' + p2(d.getMonth() + 1) + ' ' + p2(d.getHours()) + ':' + p2(d.getMinutes()); };
            var edge = Math.floor(Date.now() / 3600000) * 3600 - 47 * 3600, old = rows.filter(function (x) { return x.t < edge; }).length;
            var med = function (b) { var t = 0, s = 0; b.forEach(function (x) { t += x; }); if (!t) return -1; for (var i = 0; i < 6; i++) { s += b[i]; if (s * 2 >= t) return i; } return 5; };
            var bars = function (b) { var m = Math.max.apply(null, b.concat([1])); return '<span class="rv-hb">' + b.map(function (x, i) { return '<i class="h' + i + '" style="height:' + (x ? Math.max(2, Math.round(x / m * 16)) : 1) + 'px' + (x ? '' : ';opacity:.3') + '"></i>'; }).join('') + '</span>'; };
            return '<details class="rv-hrs"' + (CS.hro ? ' open' : '') + '><summary>По часам' + (day ? ' за ' + esc(dayNm) : '') + ' — ' + rows.length + ' ' + plural(rows.length, 'час', 'часа', 'часов') + ' с замерами</summary>'
                + '<div class="muted" style="font-size:.76rem;margin:.35rem 0 .2rem">Время — ваше. Подсвечены последние ' + w6 + ' ' + plural(w6, 'час', 'часа', 'часов') + ' с замерами: только по ним решается «не отвечает». Отдельные пинги клиент не присылает — только итоги за час.' + (old ? ' Бледные строки — ' + old + ' ' + plural(old, 'час', 'часа', 'часов') + ' раньше полосы: таблица идёт до последнего часа с замерами в этой сети.' : '') + '</div>'
                + '<div class="rv-wrap"><table class="rv-lt"><thead><tr><th>Час</th><th class="n">Пингов</th><th class="n">Неудачных</th><th>Медиана</th><th>Задержки</th></tr></thead><tbody>'
                + rows.map(function (x) { var m = med(x.b); return '<tr' + (x.w6 ? ' class="w6"' : x.t < edge ? ' class="ol" data-tip="Раньше полосы: этот час старше 48 часов от текущего момента"' : '') + '><td>' + tf(x.t) + '</td><td class="n">' + x.pn + '</td><td class="n">' + (x.pf ? '<span class="rq q' + (x.pf >= x.pn ? 5 : qFail(x.pf / x.pn)) + '">' + x.pf + '</span>' : '0') + '</td><td>' + (m >= 0 ? '<span class="rq q' + Math.min(5, m + 1) + '">' + MED[m] + '</span>' : '<span class="muted">—</span>') + '</td><td data-tip="' + esc(x.b.map(function (c, i) { return MED[i] + ': ' + c; }).join(' · ')) + '">' + bars(x.b) + '</td></tr>'; }).join('')
                + '</tbody></table></div></details>';
        };
        var histCells = function (hist, hh, lh) {
            var now = Math.floor(Date.now() / 3600000) * 3600, out = '';
            for (var i = 0; i < 48; i++) {
                var t = now - (47 - i) * 3600, ch = '.';
                if (hh > 0 && hist) { var j = hist.length - 1 - Math.round((hh - t) / 3600); if (j >= 0 && j < hist.length) ch = hist.charAt(j); }
                out += '<i class="' + (ch === 'x' ? 'hx' : /[0-5]/.test(ch) ? 'h' + ch : lh > 0 && t > lh ? 'hp' : '') + '"></i>';
            }
            return out;
        };
        var mxd = document.getElementById('rvMxd'), mxd0 = mxd ? mxd.innerHTML : '', mxT = document.getElementById('rvMxT'), mxNote = document.getElementById('rvMxNote'), mxSeq = 0, cellSeq = 0;
        var mxQ = function () { return MXQ + (MXQ ? '&' : '') + (MXK ? 'kind=' + encodeURIComponent(MXK) : ''); };
        var drawMx = function () {
            if (!mxT) return;
            document.querySelectorAll('#rvMxKind button').forEach(function (b) { b.classList.toggle('on', b.dataset.k === MXK); });
            var cols = MX.cols || [], cells = MX.cells || {};
            var rowsN = NODES.filter(function (n) { return cols.some(function (c) { return cells[n.k + '|' + c.asn]; }); });
            mxNote.textContent = cols.length ? cols.length + ' ' + plural(cols.length, 'провайдер', 'провайдера', 'провайдеров') + (MX.total > cols.length ? ' из ' + MX.total + ' — самые многочисленные' : '') + (cols.length > 6 ? ' · листаются вбок' : '') : '';
            mxd.style.minHeight = ''; mxd.style.opacity = ''; mxd.innerHTML = mxd0;
            if (!cols.length || !rowsN.length) { mxT.innerHTML = '<p class="muted">Нет данных за период' + (MXK ? ' в сетях «' + esc(MXK === 'home' ? 'Домашний' : KIND[MXK] || MXK) + '»' : '') + '.</p>'; return; }
            mxT.innerHTML = '<div class="rv-wrap rv-mxs"><table class="rv-mx rv-mxw"><thead><tr><th class="rh">Узел</th>' + cols.map(function (c) {
                return '<th class="pc">' + flag(c.cc) + '<div class="o" title="' + esc(c.org) + '">' + esc(c.org || '—') + '</div><div style="font-weight:400">AS' + c.asn + ' · ' + c.n + '</div></th>';
            }).join('') + '</tr></thead><tbody>' + rowsN.map(function (n) {
                return '<tr><td class="rh">' + flagFor(n.cc, n.nm) + '<b>' + esc(n.nm) + '</b><small>' + esc(n.t) + '</small></td>' + cols.map(function (col) {
                    var c = cells[n.k + '|' + col.asn];
                    if (!c || c.pn <= 0) return '<td><div class="rv-mc"><span class="a">—</span></div></td>';
                    var cls = c.dead > 0 && c.dead >= c.n ? 'dead' : 'q' + qFail(c.fail);
                    return '<td><button type="button" class="rv-mc ' + cls + '" data-nk="' + esc(n.k) + '" data-asn="' + col.asn + '" title="' + esc(n.nm + ' × AS' + col.asn) + '"><span class="a">' + pct(c.fail) + (c.ffr > 0 ? ' <svg class="rvi" style="width:11px;height:11px"><use href="#rvi-scissors"/></svg>' : '') + '</span>'
                        + '<span class="b">' + (c.dead > 0 ? '<svg class="rvi"><use href="#rvi-xoct"/></svg> ' + c.dead + '/' + c.n : (MED[c.med] || '—')) + '</span></button></td>';
                }).join('') + '</tr>';
            }).join('') + '</tbody></table></div>';
            var wr = mxT.querySelector('.rv-mxs'); if (wr) wr.addEventListener('scroll', function () { wr.classList.toggle('sx', wr.scrollLeft > 2); }, {passive: true});
        };
        document.querySelectorAll('#rvMxKind button').forEach(function (b) {
            b.addEventListener('click', function () {
                if (b.dataset.k === MXK) return;
                MXK = b.dataset.k; var seq = ++mxSeq; cellSeq++;
                document.querySelectorAll('#rvMxKind button').forEach(function (x) { x.classList.toggle('on', x === b); });
                mxT.style.minHeight = mxT.offsetHeight + 'px'; mxT.style.opacity = '.55';
                fetch('?ajax=rep_mx&' + mxQ(), {credentials: 'same-origin'}).then(function (r) { return r.json(); }).then(function (j) {
                    if (seq !== mxSeq) return;
                    MX = j && j.ok ? j : {cols: [], cells: {}, total: 0}; mxT.style.opacity = ''; drawMx(); mxT.style.minHeight = '';
                }).catch(function () { if (seq !== mxSeq) return; mxT.style.opacity = ''; mxT.style.minHeight = ''; mxT.innerHTML = '<span class="muted">Не удалось загрузить.</span>'; });
            });
        });
        drawMx();
        if (mxT) mxT.addEventListener('click', function (e) {
            var c = e.target.closest('button.rv-mc'); if (!c) return;
            (function () {
                if (c.classList.contains('sel')) {
                    c.classList.remove('sel');
                    mxd.style.minHeight = ''; mxd.style.opacity = '';
                    mxd.innerHTML = mxd0;
                    return;
                }
                mxT.querySelectorAll('button.rv-mc.sel').forEach(function (x) { x.classList.remove('sel'); });
                c.classList.add('sel');
                var nd = NODES.find(function (n) { return n.k === c.dataset.nk; }) || {nm: c.dataset.nk, t: '', a: ''};
                mxd.style.minHeight = mxd.offsetHeight + 'px';
                mxd.style.opacity = '.55';
                var cseq = ++cellSeq;
                fetch('?ajax=rep_cell&' + mxQ() + '&nk=' + encodeURIComponent(c.dataset.nk) + '&asn=' + encodeURIComponent(c.dataset.asn), {credentials: 'same-origin'}).then(function (r) { return r.json(); }).then(function (j) {
                    if (!c.classList.contains('sel') || cseq !== cellSeq || !c.isConnected) return;
                    var rs = (j && j.rows) || [];
                    rs.forEach(function (r) { r.rg = regionOf(r.loc, r.cc, r.sub); if (r.name) NAMES[r.s] = r.name; });
                    mxd.style.opacity = '';
                    mxd.innerHTML = '<h3>' + esc(nd.nm) + ' × AS' + esc(c.dataset.asn) + ' — ' + rs.length + ' ' + plural(rs.length, 'сеть', 'сети', 'сетей') + '</h3><div class="muted" style="font-size:.8rem;margin-bottom:.5rem">' + esc(nd.t) + ' · <code>' + esc(nd.a) + '</code></div>'
                        + '<div class="rv-wrap"><table class="rv-lt"><thead><tr><th>Клиент</th><th>Устройство</th><th>Сеть</th><th>Регион</th><th>Адрес</th><th>Неудачи</th><th>Медиана</th><th>16–20</th><th>За 48 ч</th><th>Отчёт</th></tr></thead><tbody>'
                        + rs.map(function (r) {
                            return '<tr class="clk" data-s="' + esc(r.s) + '"><td><b style="color:var(--text-strong)">' + esc(r.name || r.s) + '</b>' + (r.name ? '<div class="muted"><code>' + esc(r.s.slice(0, 10)) + '</code></div>' : '') + '</td>'
                                + '<td>' + ico(r.p === 'pc' ? 'pc' : 'android') + ' ' + esc(r.m || (r.p === 'pc' ? 'ПК' : r.p === 'android' ? 'Android' : '—')) + (r.o ? '<div class="muted" style="font-size:.74rem">' + esc(r.o) + '</div>' : '') + '</td>'
                                + '<td>' + ico(KIND[r.k] ? r.k : 'other') + ' ' + esc(KIND[r.k] || r.k) + '</td><td>' + flag(r.cc) + ' ' + esc(r.rg ? M.r[r.rg].n : ccName(r.cc) || '—') + '</td><td><code>' + esc(r.ip || '—') + '</code></td>'
                                + '<td>' + (r.dead ? '<span class="rq q5">нет ответа</span>' : '<span class="rq q' + qFail(r.pn ? r.pf / r.pn : null) + '">' + fl(r.pf, r.pn) + '</span>') + '</td><td>' + (r.med >= 0 ? '<span class="rq q' + qMed(r.med) + '">' + MED[r.med] + '</span>' : '—') + '</td>'
                                + '<td>' + (r.v === 'frozen' ? '<span class="rv-pill st-warn">режется</span>' : r.v === 'ok' ? '<span class="rv-pill st-ok">проходит</span>' : r.v === 'dead' ? '<span class="rv-pill st-bad">не отвечает</span>' : '<span class="muted">—</span>') + '</td>'
                                + '<td><div class="rv-hist sm"' + (r.hist ? '' : ' title="' + esc(histWhy(r.seen)) + '"') + '>' + histCells(r.hist, r.hh) + '</div></td><td class="muted" style="white-space:nowrap">' + ago(r.seen) + '</td></tr>';
                        }).join('') + '</tbody></table></div>';
                    mxd.style.minHeight = '';
                    mxd.querySelectorAll('tr.clk').forEach(function (t) { t.addEventListener('click', function () { openClient(t.dataset.s); }); });
                }).catch(function () { if (!c.classList.contains('sel') || cseq !== cellSeq) return; mxd.style.minHeight = ''; mxd.style.opacity = ''; mxd.innerHTML = '<span class="muted">Не удалось загрузить.</span>'; });
            })();
        });

        var drw = document.getElementById('rvDrw'), drwBg = document.getElementById('rvDrwBg'), mod = document.getElementById('rvMod'), CS = null;
        var closeMod = function () { mod.classList.remove('open'); if (!drw.classList.contains('open')) drwBg.classList.remove('open'); };
        var closeClient = function () { drw.classList.remove('open'); if (!mod.classList.contains('open')) drwBg.classList.remove('open'); };
        document.querySelectorAll('.rv-deadbtn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var k = -1; NODES.forEach(function (n, i) { if (n.k === btn.dataset.nk) k = i; });
                var devs = [];
                DEVS.forEach(function (d) { var dn = d.nets.filter(function (n) { return n.dead.indexOf(k) >= 0; }); if (dn.length) devs.push({d: d, nets: dn}); });
                var nd = NODES[k] || {nm: btn.dataset.nk, t: '', a: ''};
                mod.innerHTML = '<div class="rv-ph"><div style="min-width:0"><b>' + esc(nd.nm) + ' не отвечает</b><div class="rv-pst"><span class="rv-pill st-mut">' + devs.length + ' ' + plural(devs.length, 'устройство', 'устройства', 'устройств') + '</span><span class="rv-pill st-mut">' + esc(nd.t) + '</span></div></div><button type="button" class="x" aria-label="Закрыть">' + ico('x') + '</button></div>'
                    + '<div class="rv-pl">' + rows(devs, true) + '</div>';
                mod.classList.add('open'); drwBg.classList.add('open');
                mod.querySelector('.x').addEventListener('click', closeMod);
                mod.querySelectorAll('.rv-crow').forEach(function (b) { b.addEventListener('click', function () { closeMod(); openClient(b.dataset.s); }); });
            });
        });
        var frzSeq = 0;
        document.querySelectorAll('.rv-frzbtn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var v = btn.dataset.v, VN = {fr: 'режется', dd: 'не отвечает', ok: 'работает'}, seq = ++frzSeq;
                var head = function (extra) { return '<div class="rv-ph"><div style="min-width:0"><b>Проверка 16–20: ' + VN[v] + '</b>' + (extra || '') + '</div><button type="button" class="x" aria-label="Закрыть">' + ico('x') + '</button></div>'; };
                mod.innerHTML = head() + '<div class="rv-pl"><div class="muted" style="padding:1rem">Загружаю…</div></div>';
                mod.classList.add('open'); drwBg.classList.add('open');
                mod.querySelector('.x').addEventListener('click', closeMod);
                fetch('?ajax=rep_frz&' + FQ + '&v=' + v + (btn.dataset.nk ? '&nk=' + encodeURIComponent(btn.dataset.nk) : '') + (btn.dataset.asn ? '&asn=' + encodeURIComponent(btn.dataset.asn) : ''), {credentials: 'same-origin'}).then(function (r) { return r.json(); }).then(function (j) {
                    if (seq !== frzSeq || !mod.classList.contains('open')) return;
                    var rows = (j && j.rows) || [], dv = {}, st = j && j.src === 'state';
                    rows.forEach(function (x) { dv[x.s + '|' + x.hw] = 1; });
                    var ndn = function (k) { var r = k; NODES.forEach(function (n) { if (n.k === k) r = n.nm; }); return r; };
                    var dd = function (t) { var x = new Date(t * 1000); return (x.getDate() < 10 ? '0' : '') + x.getDate() + '.' + (x.getMonth() < 9 ? '0' : '') + (x.getMonth() + 1); };
                    mod.innerHTML = head('<div class="rv-pst"><span class="rv-pill st-mut">' + Object.keys(dv).length + ' ' + plural(Object.keys(dv).length, 'устройство', 'устройства', 'устройств') + '</span><span class="rv-pill st-mut">' + (st ? 'по последней проверке' : 'по дням периода') + '</span></div>')
                        + '<div class="rv-pl">' + (rows.length ? rows.map(function (x) {
                            var nm = x.nm || NAMES[x.s] || x.s, de = [x.m || (x.p === 'pc' ? 'ПК' : x.p === 'android' ? 'Android' : ''), x.cl ? 'Clod Clash ' + x.cl : ''].filter(Boolean).join(' · ');
                            return '<button type="button" class="rv-crow" data-s="' + esc(x.s) + '" data-hw="' + esc(x.hw) + '" data-k="' + esc(x.n + '|' + x.net) + '" data-d="' + (st ? 0 : x.ld) + '"><span class="av">' + esc(nm.slice(0, 2).toUpperCase()) + '</span><span class="bd"><span class="nm">' + esc(nm) + '</span><span class="sub">' + esc(de || x.hw.slice(0, 16)) + '</span><span class="sub">' + esc(ndn(x.n)) + ' · ' + esc((KIND[x.k] || x.k) + (x.org ? ' · ' + x.org : (x.asn ? ' · AS' + x.asn : ''))) + '</span>'
                                + '<span class="st"><span class="rq ' + (v === 'ok' ? 'q1' : v === 'fr' ? 'q4' : 'q5') + '">' + (st ? VN[v] : x.c + ' из ' + x.t + ' ' + plural(x.t, 'проверки', 'проверок', 'проверок')) + '</span> <span class="muted" style="font-size:.74rem">' + (st ? 'последняя ' + dd(x.ld) : (x.days > 1 ? 'дней: ' + x.days + ', последний ' : '') + dd(x.ld)) + '</span></span></span></button>';
                        }).join('') : '<div class="muted" style="padding:1rem">Устройств с таким итогом за период нет.</div>') + '</div>';
                    mod.querySelector('.x').addEventListener('click', closeMod);
                    mod.querySelectorAll('.rv-crow').forEach(function (b) { b.addEventListener('click', function () { closeMod(); openClient(b.dataset.s, {hw: b.dataset.hw, cell: b.dataset.k, day: +b.dataset.d}); }); });
                }).catch(function () { if (seq === frzSeq) { var pl = mod.querySelector('.rv-pl'); if (pl) pl.innerHTML = '<div class="muted" style="padding:1rem">Не удалось загрузить</div>'; } });
            });
        });
        drwBg.addEventListener('click', function () { closeMod(); closeClient(); });
        document.addEventListener('keydown', function (e) { if (e.key !== 'Escape') return; if (mod.classList.contains('open')) closeMod(); else if (drw.classList.contains('open')) closeClient(); });
        var clientSeq = 0;
        var openClient = function (q, opt) {
            var seq = ++clientSeq;
            drw.innerHTML = '<div class="rv-dh"><h2>Загружаю…</h2><span class="sp"></span><button type="button" class="btn ghost" id="rvDrwX" aria-label="Закрыть">' + ico('x') + '</button></div>';
            drw.querySelector('#rvDrwX').addEventListener('click', closeClient);
            drw.classList.add('open'); drwBg.classList.add('open');
            fetch('?ajax=rep_client&' + FQ + '&q=' + encodeURIComponent(q), {credentials: 'same-origin'}).then(function (r) { return r.json(); }).then(function (j) {
                if (seq !== clientSeq) return;
                if (!j || !j.ok || !j.card) { drw.querySelector('h2').textContent = 'Отчётов от этого клиента нет'; return; }
                var c = j.card;
                c.nk = {}; (c.nodes || []).forEach(function (n) { c.nk[n.k] = n; });
                c.rows.forEach(function (r) { r.rg = regionOf(r.loc, r.cc, r.sub); r.rgo = regionOf(r.ol || '', r.occ || r.cc, r.osb || ''); });
                c.devs.forEach(function (d) { if (d.pl) d.pl.rg = regionOf(d.pl.loc, d.pl.cc, d.pl.sub); });
                Object.keys(c.ips || {}).forEach(function (k) { c.ips[k].forEach(function (x) { x.rg = regionOf(x.loc, x.cc, x.sub); }); });
                if (c.name) NAMES[c.short] = c.name;
                CS = {c: c, dev: 0, cell: null, only: false};
                if (opt) {
                    c.devs.forEach(function (x, i) { if (x.hw === opt.hw) CS.dev = i; });
                    if (opt.cell) { CS.cell = opt.cell; CS.jump = opt.cell; }
                    if (opt.day !== undefined) CS.day = opt.day;
                }
                drawClient();
            }).catch(function () { if (seq !== clientSeq) return; var h = drw.querySelector('h2'); if (h) h.textContent = 'Не удалось загрузить'; });
        };
        var drawClient = function (keep) {
            var oldDb = drw.querySelector('.rv-db'), oldTop = oldDb ? oldDb.scrollTop : 0;
            var c = CS.c, d = c.devs[CS.dev] || c.devs[0], nm = c.name || nameOf(c.short);
            var rs = c.rows.filter(function (r) { return r.hw === d.hw; });
            var nets = [], nmap = {};
            rs.forEach(function (r) { if (!nmap[r.net]) { nmap[r.net] = {net: r.net, k: r.k, ip: '', ip6: '', cc: '', asn: 0, org: '', rg: null, hh: 0, seen: 0, cells: {}, ah: -1, rgo: null, occ: ''}; nets.push(nmap[r.net]); } var n = nmap[r.net]; n.cells[r.n] = r; n.hh = Math.max(n.hh, r.hh); n.seen = Math.max(n.seen, r.seen); if (r.ip && r.hh >= n.ah) { n.ah = r.hh; n.ip = r.ip; n.ip6 = r.ip6; n.cc = r.cc; n.asn = r.asn; n.org = r.org; n.rg = r.rg; n.rgo = r.rgo; n.occ = r.occ || r.cc; n.k = r.k; } });
            nets.sort(function (a, b) { return (b.hh - a.hh) || (b.seen - a.seen); });
            var label = function (n) { return (KIND[n.k] || n.k) + ' · ' + (n.org || (n.asn ? 'AS' + n.asn : 'провайдер неизвестен')); };
            var nkeys = []; rs.forEach(function (r) { if (r.pn > 0 && nkeys.indexOf(r.n) < 0) nkeys.push(r.n); });
            var nodeNm = function (k) { return (c.nk[k] && c.nk[k].nm) || k; };
            nkeys.sort(function (a, b) { return nodeNm(a).localeCompare(nodeNm(b)); });
            var peer = function (asn, k) { return c.peers[asn + '|' + k] || null; };
            var diag = [];
            nkeys.forEach(function (k) {
                var on = nets.filter(function (n) { return n.cells[k] && n.cells[k].pn > 0; }), dd = on.filter(function (n) { return n.cells[k].dead; });
                if (!dd.length) return;
                var pn = 0, pd = 0; dd.forEach(function (n) { var p = peer(n.asn, k); if (p) { pn += p.n; pd += p.dead; } });
                if (dd.length === on.length) {
                    if (pn >= 2 && pd / pn < .2) diag.push({j: k + '|' + dd[0].net, s: 'bad', ic: 'phone', t: nodeNm(k) + ' не отвечает ни в одной сети устройства', w: 'У других клиентов тех же провайдеров (' + pn + ' ' + plural(pn, 'сеть', 'сети', 'сетей') + ') узел работает — дело в устройстве: обновить подписку, проверить дату и время, переустановить конфиг.'});
                    else diag.push({j: k + '|' + dd[0].net, s: 'bad', ic: 'xoct', t: nodeNm(k) + ' не отвечает ни в одной сети устройства', w: pn ? 'У других клиентов тех же провайдеров тоже: без ответа ' + pd + ' из ' + pn + ' ' + plural(pn, 'сети', 'сетей', 'сетей') + ' — проблема не на устройстве.' : 'Сравнить не с кем — других клиентов этих провайдеров в отчётах нет.'});
                } else {
                    dd.forEach(function (n) {
                        var p = peer(n.asn, k), alt = nkeys.filter(function (x) { var y = n.cells[x]; return y && !y.dead && y.v !== 'frozen' && (y.fail === null || y.fail < .05); }).slice(0, 2).map(nodeNm);
                        diag.push({j: k + '|' + n.net, s: 'bad', ic: 'xoct', t: nodeNm(k) + ' не отвечает в сети «' + label(n) + '»', w: 'В других сетях устройства узел работает — режет сеть, а не узел.' + (p ? ' У других клиентов этого провайдера без ответа ' + p.dead + ' из ' + p.n + '.' : '') + (alt.length ? ' В этой сети работают: ' + esc(alt.join(', ')) + '.' : '')});
                    });
                }
            });
            nets.forEach(function (n) {
                var fz = nkeys.filter(function (k) { return n.cells[k] && n.cells[k].v === 'frozen'; });
                if (fz.length) diag.push({j: fz[0] + '|' + n.net, s: 'warn', ic: 'scissors', t: '16–20 режется в сети «' + label(n) + '»: ' + fz.map(nodeNm).join(', '), w: 'Пинг до узлов проходит, а соединение рвётся после первых килобайт — признак DPI. Помогает другой транспорт или порт.'});
            });
            var mb = [0, 0, 0, 0, 0, 0], tp = 0, tf = 0;
            rs.forEach(function (r) { tp += r.pn; tf += r.pf; if (r.med >= 0) mb[r.med] += r.pn - r.pf; });
            var tot = mb.reduce(function (a, b) { return a + b; }, 0), run = 0, med = -1;
            for (var i = 0; i < 6 && tot > 0; i++) { run += mb[i]; if (run * 2 >= tot) { med = i; break; } }
            if (med >= 3) {
                var cur = nets[0], best = cur ? nkeys.filter(function (k) { return cur.cells[k] && cur.cells[k].med >= 0 && !cur.cells[k].dead; }).sort(function (a, b) { return cur.cells[a].med - cur.cells[b].med; }).slice(0, 2).map(nodeNm) : [];
                diag.push({s: 'warn', ic: 'clock', t: 'Высокий пинг: медиана ' + MED[med], w: best.length ? 'Ближе всего сейчас: ' + esc(best.join(', ')) + '.' : ''});
            }
            if (!diag.length) diag.push({s: 'ok', ic: 'check', t: 'Все узлы отвечают во всех сетях', w: 'Неудачных пингов ' + pct(tp >= 20 ? tf / tp : null) + ', медиана ' + (MED[med] || '—') + '.'});
            var q2 = function (n) { return (n < 10 ? '0' : '') + n; };
            var tfm = function (t) { var x = new Date(t * 1000); return q2(x.getDate()) + '.' + q2(x.getMonth() + 1) + ' ' + q2(x.getHours()) + ':' + q2(x.getMinutes()); };
            var medOf = function (b) { var t = 0, s2 = 0; b.forEach(function (x) { t += x; }); if (!t) return -1; for (var i = 0; i < 6; i++) { s2 += b[i]; if (s2 * 2 >= t) return i; } return 5; };
            var dRows = (c.days || []).filter(function (r) { return r.hw === d.hw; });
            var dayList = []; dRows.forEach(function (r) { if (dayList.indexOf(r.d) < 0) dayList.push(r.d); }); dayList.sort(function (a, b) { return b - a; });
            if (CS.day === undefined || (CS.day && dayList.indexOf(CS.day) < 0)) CS.day = dayList.length ? (dayList.indexOf(c.today) >= 0 ? c.today : dayList[0]) : 0;
            var dName = function (x) { if (x === c.today) return 'Сегодня'; if (x === c.today - 86400) return 'Вчера'; var t = new Date((x + c.tz) * 1000); return q2(t.getUTCDate()) + '.' + q2(t.getUTCMonth() + 1); };
            var dWd = function (x) { return ['вс', 'пн', 'вт', 'ср', 'чт', 'пт', 'сб'][new Date((x + c.tz) * 1000).getUTCDay()]; };
            var dState = function (x) { var st = 'ok'; dRows.forEach(function (r) { if (r.d !== x || !r.pn) return; if (r.pf >= r.pn || r.fdd > 0) st = 'bad'; else if (st !== 'bad' && (r.ffr > 0 || (r.pn >= 20 && r.pf / r.pn >= .10))) st = 'warn'; }); return st; };
            var buildM = function (day) {
                if (!day) return {nets: nets, map: nmap, keys: nkeys};
                var o = {nets: [], map: {}, keys: []};
                dRows.forEach(function (r) {
                    if (r.d !== day) return;
                    var n = o.map[r.net];
                    if (!n) { var s = nmap[r.net]; n = o.map[r.net] = {net: r.net, k: s ? s.k : r.k, ip: s ? s.ip : '', ip6: '', cc: s ? s.cc : '', asn: s ? s.asn : r.asn, org: s ? s.org : r.org, hh: s ? s.hh : 0, seen: s ? s.seen : 0, cells: {}}; o.nets.push(n); }
                    var pf = Math.min(r.pf, r.pn);
                    n.cells[r.n] = {pn: r.pn, pf: pf, b: r.b, med: medOf(r.b), fail: r.pn >= 20 ? pf / r.pn : null, dead: r.pn > 0 && pf >= r.pn, fok: r.fok, ffr: r.ffr, fdd: r.fdd, vl: r.vl, vs: r.vs, va: r.va, hrs: r.hrs, tb: r.tb};
                    if (r.pn > 0 && o.keys.indexOf(r.n) < 0) o.keys.push(r.n);
                });
                o.nets.sort(function (a, b) { return (b.hh - a.hh) || (b.seen - a.seen); });
                o.keys.sort(function (a, b) { return nodeNm(a).localeCompare(nodeNm(b)); });
                return o;
            };
            var M = buildM(CS.day);
            if (CS.jump && CS.day && CS.cell) { var jp = CS.cell.split('|'); if (!(M.map[jp[1]] && M.map[jp[1]].cells[jp[0]] && M.map[jp[1]].cells[jp[0]].pn)) { CS.day = 0; M = buildM(0); } }
            var mNets = M.nets, mMap = M.map, mKeys = M.keys;
            var isBad = function (y) { return !!y && y.pn > 0 && (y.dead || y.ffr > 0 || y.fdd > 0 || y.v === 'frozen' || y.v === 'dead' || (y.fail !== null && y.fail >= .10) || y.med >= 3); };
            var probKeys = mKeys.filter(function (k) { return mNets.some(function (n) { return isBad(n.cells[k]); }); });
            if (!probKeys.length) CS.only = false;
            var show = CS.only ? probKeys : mKeys;
            if (!CS.cell || !mMap[CS.cell.split('|')[1]] || !mMap[CS.cell.split('|')[1]].cells[CS.cell.split('|')[0]]) {
                CS.cell = null;
                mKeys.some(function (k) { return mNets.some(function (n) { if (n.cells[k] && n.cells[k].dead) { CS.cell = k + '|' + n.net; return true; } return false; }); });
                if (!CS.cell) mKeys.some(function (k) { return mNets.some(function (n) { if (isBad(n.cells[k])) { CS.cell = k + '|' + n.net; return true; } return false; }); });
                if (!CS.cell && mKeys.length && mNets.length) CS.cell = mKeys[0] + '|' + mNets[0].net;
            }
            if (CS.only && !CS.jump && CS.cell && show.indexOf(CS.cell.split('|')[0]) < 0) {
                CS.cell = null;
                show.some(function (k) { return mNets.some(function (n) { if (isBad(n.cells[k])) { CS.cell = k + '|' + n.net; return true; } return false; }); });
            }
            var cell = function (r, key) {
                if (!r || !r.pn) return '<div class="rv-mc"><span class="a">—</span></div>';
                var sel = CS.cell === key ? ' sel' : '';
                if (r.dead) return '<button type="button" class="rv-mc dead' + sel + '" data-cell="' + esc(key) + '"><span class="a">нет ответа</span><span class="b">0 из ' + r.pn + '</span></button>';
                return '<button type="button" class="rv-mc q' + qFail(r.pf / r.pn) + sel + '" data-cell="' + esc(key) + '"><span class="a">' + fl(r.pf, r.pn) + (r.v === 'frozen' || r.ffr > 0 ? ' <svg class="rvi" style="width:11px;height:11px"><use href="#rvi-scissors"/></svg>' + (r.ffr > 1 ? r.ffr : '') : '') + (r.fdd > 0 ? ' ✕' + (r.fdd > 1 ? r.fdd : '') : '') + '</span><span class="b">' + (MED[r.med] || '—') + '</span></button>';
            };
            var worst = function (hw) {
                var best = null, bs = 0;
                c.rows.forEach(function (r) {
                    if (r.hw !== hw || !r.pn) return;
                    var sc = r.dead ? 4 : r.v === 'frozen' ? 3 : r.fail !== null && r.fail >= .03 ? 2 + r.fail : r.med >= 3 ? 1 + r.med / 10 : 0;
                    if (sc > bs) { bs = sc; best = r.n + '|' + r.net; }
                });
                return best;
            };
            var ipList = function (hw, n) {
                var list = (c.ips || {})[hw + '|' + n.net] || [];
                if (list.length < 2) return '';
                var tot = list.reduce(function (s, x) { return s + x.h; }, 0);
                return '<details class="rv-ipl" data-net="' + esc(n.net) + '"' + (CS.ipo && CS.ipo[n.net] ? ' open' : '') + '><summary>Адреса сети: ' + list.length + '</summary><table>'
                    + list.map(function (x) { return '<tr><td><code>' + esc(x.ip) + '</code></td><td>' + flag(x.cc) + ' ' + esc(placeNm(x.rg, x.cc)) + '</td><td class="n" data-tip="Часов с замерами с этого адреса и доля от всех часов сети">' + x.h + ' ч' + (tot ? ' · ' + Math.round(x.h * 100 / tot) + ' %' : '') + '</td></tr>'; }).join('')
                    + '</table></details>';
            };
            var devTabs = c.devs.map(function (x, i) {
                var xr = c.rows.filter(function (r) { return r.hw === x.hw; }), xp = 0, xf = 0; xr.forEach(function (r) { xp += r.pn; xf += r.pf; }); var bad = xr.some(function (r) { return r.dead; }) || (xp >= 20 && xf / xp >= .1), warn = xr.some(function (r) { return r.v === 'frozen'; }) || (xp >= 20 && xf / xp >= .03);
                return '<div role="button" tabindex="0" class="rv-dtab' + (i === CS.dev ? ' on' : '') + '" data-i="' + i + '">' + ico(x.p === 'pc' ? 'pc' : 'android') + '<span><span class="t">' + esc(x.m || (x.p === 'pc' ? 'ПК' : x.p === 'android' ? 'Android' : 'Устройство')) + '</span><span class="s">' + esc([x.o, x.c ? 'Clod Clash ' + x.c : ''].filter(Boolean).join(' · ') || x.hw.slice(0, 16)) + '</span></span><span style="flex:1"></span>' + (bad || warn ? '<button type="button" class="rv-pill rv-jump st-' + (bad ? 'bad' : 'warn') + '" title="Показать проблемный узел">' + (bad ? 'проблемы' : 'деградация') + '</button>' : '<span class="rv-pill st-ok">в порядке</span>') + '</div>';
            }).join('');
            var sel = CS.cell ? CS.cell.split('|') : null, sr = sel && mMap[sel[1]] ? mMap[sel[1]].cells[sel[0]] : null, sn = sel ? mMap[sel[1]] : null, sp = sr && sn ? peer(sn.asn, sel[0]) : null;
            var lastSeen = 0; rs.forEach(function (r) { lastSeen = Math.max(lastSeen, r.seen); });
            var use = {}, useT = 0; rs.forEach(function (r) { if (r.tb > 0 && r.seen === lastSeen) { use[r.n] = (use[r.n] || 0) + r.tb; useT += r.tb; } });
            var gb = function (b) { var u = ['Б', 'КБ', 'МБ', 'ГБ', 'ТБ'], i = 0; while (b >= 1024 && i < 4) { b /= 1024; i++; } return (i ? b.toFixed(b < 10 ? 1 : 0).replace('.', ',') : b) + ' ' + u[i]; };
            var p90b = function (b) { var t = 0, s2 = 0; b.forEach(function (x) { t += x; }); if (t < 20) return ''; for (var i = 0; i < 6; i++) { s2 += b[i]; if (s2 >= t * .9) return i < 5 ? ' · 90 % ответов — быстрее ' + ['100 мс', '200 мс', '400 мс', '800 мс', '1,5 с'][i] : ' · больше 10 % ответов — дольше 1,5 с'; } return ''; };
            var p90 = function (hc) { var b = [0, 0, 0, 0, 0, 0]; String(hc || '').split(',').forEach(function (c) { if (!c) return; c.split('.').slice(2, 8).forEach(function (x, i) { b[i] += parseInt(x, 36) || 0; }); }); return p90b(b); };
            var useTop = Object.keys(use).sort(function (a, b) { return use[b] - use[a]; }).slice(0, 3);
            var VTX = {ok: 'работает', frozen: 'режется', dead: 'не отвечает'};
            var dayDetail = function (r, n, s) {
                var k = s[0], dnm = CS.day === c.today ? 'сегодня' : CS.day === c.today - 86400 ? 'вчера' : dName(CS.day), fz = r.fok + r.ffr + r.fdd;
                var hist = dRows.filter(function (x) { return x.n === k && x.net === n.net; }).sort(function (a, b) { return b.d - a.d; });
                var vcell = function (x) { if (!(x.fok + x.ffr + x.fdd)) return '<span class="muted">—</span>'; return '<span class="rv-vb">' + (x.ffr ? '<span class="rq q4">✂ ' + x.ffr + '</span>' : '') + (x.fdd ? '<span class="rq q5">✕ ' + x.fdd + '</span>' : '') + (x.fok ? '<span class="rq q1">✓ ' + x.fok + '</span>' : '') + '</span>'; };
                var s48 = nmap[n.net] && nmap[n.net].cells[k];
                return '<div class="rv-mxd"><h3>' + esc(nodeNm(k)) + ' в сети «' + esc(label(n)) + '» — ' + esc(dnm) + '</h3>'
                    + '<div class="muted" style="font-size:.8rem">Пингов ' + r.pn + ', неудачных ' + r.pf + (r.pn >= 20 ? ' (' + pct(r.pf / r.pn) + ')' : '') + ' · медиана ' + (MED[r.med] || '—') + p90b(r.b) + ' · часов с замерами ' + r.hrs
                    + (fz ? ' · 16–20: ' + [r.ffr ? 'режется ' + r.ffr : '', r.fdd ? 'не отвечает ' + r.fdd : '', r.fok ? 'работает ' + r.fok : ''].filter(Boolean).join(', ') + (r.vl && VTX[r.vl] ? '; последняя — ' + VTX[r.vl] + (r.vs ? ', код ' + r.vs : '') + (r.va ? ', ' + tfm(r.va) : '') : '') : ' · 16–20 в этот день не проверялась') + '</div>'
                    + (hist.length > 1 ? '<div class="rv-wrap" style="margin-top:.6rem"><table class="rv-lt"><thead><tr><th>День</th><th class="n">Пингов</th><th class="n">Неудачных</th><th>Медиана</th><th>16–20</th></tr></thead><tbody>'
                        + hist.map(function (x) { var m = medOf(x.b), pf = Math.min(x.pf, x.pn); return '<tr class="clk' + (x.d === CS.day ? ' cur' : '') + '" data-day="' + x.d + '" title="Показать этот день"><td>' + esc(dName(x.d)) + (x.d < c.today - 86400 ? ' <span class="muted">' + dWd(x.d) + '</span>' : '') + '</td><td class="n">' + x.pn + '</td><td class="n">' + (pf ? '<span class="rq q' + (pf >= x.pn ? 5 : qFail(pf / x.pn)) + '">' + (x.pn >= 20 ? pct(pf / x.pn) : pf + ' из ' + x.pn) + '</span>' : '0') + '</td><td>' + (m >= 0 ? '<span class="rq q' + Math.min(5, m + 1) + '">' + MED[m] + '</span>' : '<span class="muted">—</span>') + '</td><td>' + vcell(x) + '</td></tr>'; }).join('')
                        + '</tbody></table></div>' : '')
                    + (s48 ? hoursTbl(s48, CS.day, dnm) : '') + '</div>';
            };
            drw.innerHTML = '<div class="rv-dh"><span class="av">' + esc((nm || c.short).slice(0, 2).toUpperCase()) + '</span><div style="min-width:0"><h2>' + esc(nm || c.short) + '</h2><div class="muted" style="font-size:.78rem">' + (nm ? 'имя из панели · ' : '') + '<code>' + esc(c.short) + '</code></div></div>'
                + '<span class="rv-pill st-mut">' + c.devs.length + ' ' + plural(c.devs.length, 'устройство', 'устройства', 'устройств') + '</span><span class="sp"></span>'
                + '<a class="btn ghost" href="?tab=reqlog&rl_q=' + encodeURIComponent(c.short) + '">' + ico('list') + 'Лог запросов</a>'
                + '<button type="button" class="btn ghost" id="rvDrwX" aria-label="Закрыть">' + ico('x') + '</button></div>'
                + '<div class="rv-db"><div class="rv-sect" style="margin-top:0">Устройства</div><div class="rv-dtabs">' + devTabs + '</div>'
                + '<div class="rv-kv"><div><span>Модель и ОС</span><b>' + esc([d.m, d.o].filter(Boolean).join(' · ') || '—') + '</b></div><div><span>Клиент</span><b>' + esc(d.c ? 'Clod Clash ' + d.c : '—') + (d.p ? ' · ' + (d.p === 'pc' ? 'ПК' : 'Android') : '') + '</b></div><div><span>HWID</span><b><code>' + esc(d.hw) + '</code></b></div><div><span>Отчётов</span><b>' + (d.r || '—') + ' · последний ' + ago(d.lr) + '</b></div>'
                + (useTop.length ? '<div><span>' + (Math.abs(lastSeen - d.lr) < 600 ? 'Трафик в последнем отчёте' : 'Трафик в отчёте ' + esc(ago(lastSeen))) + '</span><b data-tip="Через какие узлы шёл трафик устройства в отчёте, пришедшем ' + esc(ago(lastSeen)) + ': за часы с предыдущего отчёта. Всего ' + esc(gb(useT)) + '.">' + useTop.map(function (k) { return esc(nodeNm(k)) + ' <em style="font-style:normal;font-weight:500;color:var(--muted);white-space:nowrap">' + pct(use[k] / useT) + ' · ' + esc(gb(use[k])) + '</em>'; }).join('<br>') + '</b></div>' : '')
                + (d.pl ? '<div><span>Регион устройства</span><b data-tip="' + esc((d.pl.src === 'home' ? 'По домашним сетям (Wi-Fi и кабель)' : 'По всем сетям устройства') + ': ' + d.pl.w + ' из ' + d.pl.tw + ' часов замеров' + (d.pl.n > 1 ? ', адреса числились в ' + d.pl.n + ' местах' : '') + '. Мобильная сеть и динамические адреса регион не переносят; сеть в другой стране показывается отдельно.') + '">' + flag(d.pl.cc) + ' ' + esc(placeNm(d.pl.rg, d.pl.cc)) + '</b></div>' : '') + '</div>'
                + '<div class="rv-sect">Диагноз</div>' + diag.map(function (x) { return '<div class="rv-diag st-' + x.s + '"' + (x.j ? ' data-jump="' + esc(x.j) + '" role="button" tabindex="0" title="Показать в таблице пингов"' : '') + '><span class="ic st-' + x.s + '">' + ico(x.ic) + '</span><div><div class="t">' + esc(x.t) + '</div>' + (x.w ? '<div class="w">' + x.w + '</div>' : '') + '</div>' + (x.j ? '<span class="go">к узлу ↓</span>' : '') + '</div>'; }).join('')
                + '<div class="rv-sect">Сети устройства</div><div class="rv-nets">' + nets.map(function (n, i) {
                    var pn = 0, pf = 0, dd = 0; nkeys.forEach(function (k) { var y = n.cells[k]; if (y) { pn += y.pn; pf += y.pf; if (y.dead) dd++; } });
                    return '<div class="rv-net"><div class="h">' + ico(KIND[n.k] ? n.k : 'other') + esc(KIND[n.k] || n.k) + (i === 0 ? ' <span class="rv-pill st-info">последняя</span>' : '') + '<span class="sp"></span>' + (dd ? '<span class="rv-pill st-bad">' + dd + ' без ответа</span>' : '<span class="rq q' + qFail(pn ? pf / pn : null) + '">' + fl(pf, pn) + '</span>') + '</div>'
                        + '<div class="r"><b style="color:var(--text)">' + (n.asn ? 'AS' + n.asn + ' ' : '') + esc(n.org || '—') + '</b><br><code>' + esc(n.ip || '—') + '</code>' + (n.ip6 ? ' · <code>' + esc(n.ip6) + '</code>' : '') + '<br>' + flag(n.cc) + ' ' + esc(placeNm(n.rg, n.cc))
                        + ((n.rgo !== n.rg || n.occ !== n.cc) && (n.rgo || n.occ) ? '<br><span data-tip="Где адрес сети числится в базе адресов. Устройство стоит там, где провело больше всего часов: динамические адреса провайдера заведены на разные регионы.">адрес оператора — ' + esc(placeNm(n.rgo, n.occ)) + '</span>' : '')
                        + '<br>замер ' + ago(n.seen) + '</div>' + ipList(d.hw, n) + '</div>';
                }).join('') + '</div>'
                + '<div class="rv-sect">Пинги до узлов по сетям<label' + (probKeys.length ? '' : ' class="off"') + ' data-tip="Проблемный узел — не отвечает, режется или не проходит 16–20, неудачных пингов от 10 % или медиана от 400 мс хотя бы в одной сети."><input type="checkbox" id="rvOnly"' + (CS.only ? ' checked' : '') + (probKeys.length ? '' : ' disabled') + '> только проблемные (' + probKeys.length + ' из ' + mKeys.length + ')</label></div>'
                + (dayList.length ? '<div class="rv-days">' + dayList.slice(0, 14).map(function (x) { return '<button type="button" class="rv-day' + (CS.day === x ? ' on' : '') + '" data-day="' + x + '"><i class="st-' + dState(x) + '"></i>' + dName(x) + (x < c.today - 86400 ? ' <small>' + dWd(x) + '</small>' : '') + '</button>'; }).join('') + '<button type="button" class="rv-day' + (!CS.day ? ' on' : '') + '" data-day="0" data-tip="Сумма последних 48 часов с замерами в каждой сети, без деления по дням."><i class="st-mut"></i>48 ч сводно</button></div>' : '<div class="muted" style="font-size:.78rem;margin:.1rem 0 .6rem">По дням данные копятся с обновления прослойки — пока показано за последние 48 часов.</div>')
                + (mKeys.length ? '<div class="rv-wrap"><table class="rv-mx"><thead><tr><th class="rh">Узел</th>' + mNets.map(function (n) { return '<th style="min-width:140px">' + ico(KIND[n.k] ? n.k : 'other') + '<div style="color:var(--text);margin-top:.15rem">' + esc(label(n)) + '</div><div style="font-weight:400"><code>' + esc(n.ip || '—') + '</code></div></th>'; }).join('') + '</tr></thead><tbody>'
                    + show.map(function (k) { var nd = c.nk[k] || {}; return '<tr data-k="' + esc(k) + '"' + (sel && sel[0] === k ? ' class="cur"' : '') + '><td class="rh">' + flagFor(nd.cc, nodeNm(k)) + '<b>' + esc(nodeNm(k)) + '</b><small>' + esc(nd.t || '') + '</small></td>' + mNets.map(function (n) { return '<td>' + cell(n.cells[k], k + '|' + n.net) + '</td>'; }).join('') + '</tr>'; }).join('')
                    + '</tbody></table></div>' : '<p class="muted">' + (CS.day ? 'За этот день пингов нет.' : 'Пингов в отчётах нет.') + '</p>')
                + (sr && CS.day ? dayDetail(sr, sn, sel) : '')
                + (sr && !CS.day ? '<div class="rv-mxd"><h3>' + esc(nodeNm(sel[0])) + ' в сети «' + esc(label(sn)) + '» — последние 48 часов</h3><div class="muted" style="font-size:.8rem">Пингов ' + sr.pn + ', неудачных ' + sr.pf + ' · медиана ' + (MED[sr.med] || '—') + p90(sr.hc) + (sr.v && VTX[sr.v] ? ' · 16–20: ' + VTX[sr.v] + (sr.vs ? ', код ' + sr.vs : '') + (sr.va ? ', ' + new Date(sr.va * 1000).toLocaleString('ru-RU', {day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit'}) : '') : '') + (sp ? ' · у других клиентов этого провайдера: неудач ' + pct(sp.fail) + ', медиана ' + (MED[sp.med] || '—') + (sp.dead ? ', без ответа ' + sp.dead + ' из ' + sp.n : '') : '') + '</div>'
                    + '<div class="rv-hist">' + histCells(sr.hist, sr.hh, d.lh) + '</div><div class="rv-hax"><span>48 ч назад</span><span>24 ч</span><span>сейчас</span></div>'
                    + '<div class="rv-legend"><span><i style="background:var(--rq1)"></i>&lt; 100 мс</span><span><i style="background:var(--rq2)"></i>100–200</span><span><i style="background:var(--rq3)"></i>200–400</span><span><i style="background:var(--rq4)"></i>400–800</span><span><i style="background:var(--rq5)"></i>&gt; 0,8 с</span><span><i class="hx" style="background:repeating-linear-gradient(135deg,var(--rq5) 0 3px,transparent 3px 6px)"></i>нет ответа</span><span><i style="background:var(--line)"></i>нет замеров в этой сети</span><span><i style="background:transparent;box-shadow:inset 0 0 0 1px var(--line)"></i>отчёт ещё не пришёл</span></div>'
                    + (sr.hist ? '' : '<div class="muted" style="font-size:.78rem;margin-top:.3rem">' + esc(histWhy(sr.seen)) + '</div>') + hoursTbl(sr) + '</div>' : '')
                + '</div>';
            drw.querySelector('#rvDrwX').addEventListener('click', closeClient);
            var newDb = drw.querySelector('.rv-db'); if (keep && newDb) newDb.scrollTop = oldTop;
            drw.querySelectorAll('.rv-dtab').forEach(function (b) {
                var pick = function (jump) {
                    CS.dev = +b.dataset.i; CS.cell = null;
                    if (jump) { var w = worst(c.devs[CS.dev].hw); if (w) { CS.cell = w; CS.jump = w; } }
                    drawClient(true);
                };
                b.addEventListener('click', function (e) { pick(!!(e.target.closest && e.target.closest('.rv-jump'))); });
                b.addEventListener('keydown', function (e) { if (e.target === b && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); pick(false); } });
                var jb = b.querySelector('.rv-jump'); if (jb) jb.addEventListener('click', function (e) { e.stopPropagation(); pick(true); });
            });
            var jumpTo = function (key) { CS.cell = key; CS.jump = key; drawClient(true); };
            drw.querySelectorAll('.rv-diag[data-jump]').forEach(function (b) {
                b.addEventListener('click', function () { jumpTo(b.dataset.jump); });
                b.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); jumpTo(b.dataset.jump); } });
            });
            if (CS.jump) {
                var jk = CS.jump.split('|')[0]; CS.jump = null;
                if (CS.only && show.indexOf(jk) < 0) { CS.only = false; CS.jump = CS.cell; drawClient(true); return; }
                var tr = drw.querySelector('tr[data-k="' + (window.CSS && CSS.escape ? CSS.escape(jk) : jk) + '"]');
                if (tr) {
                    var box = drw.querySelector('.rv-db'), rb = tr.getBoundingClientRect(), bb = box.getBoundingClientRect();
                    var to = Math.max(0, Math.min(box.scrollTop + rb.top - bb.top - Math.max(16, (bb.height - rb.height) / 3), box.scrollHeight - box.clientHeight));
                    if (Math.abs(to - box.scrollTop) > 2) {
                        try { box.scrollTo({top: to, behavior: 'smooth'}); } catch (e) { box.scrollTop = to; }
                        setTimeout(function () { if (Math.abs(box.scrollTop - to) > 4) box.scrollTop = to; }, 650);
                    }
                    var fc = tr.querySelector('.rv-mc.sel'); if (fc) { try { fc.focus({preventScroll: true}); } catch (e) {} }
                    var sc = tr.querySelector('.rv-mc.sel'); if (sc && sc.scrollIntoView) { var wr = tr.closest('.rv-wrap'); if (wr) wr.scrollLeft = Math.max(0, sc.parentNode.offsetLeft - wr.clientWidth / 2); }
                    tr.classList.add('rv-flash'); setTimeout(function () { tr.classList.remove('rv-flash'); }, 2500);
                }
            }
            drw.querySelectorAll('[data-cell]').forEach(function (b) { b.addEventListener('click', function () { CS.cell = b.dataset.cell; drawClient(true); }); });
            drw.querySelectorAll('[data-day]').forEach(function (b) { b.addEventListener('click', function () { CS.day = +b.dataset.day; drawClient(true); }); });
            var only = drw.querySelector('#rvOnly'); if (only) only.addEventListener('change', function () { CS.only = only.checked; drawClient(true); });
            var hrs = drw.querySelector('.rv-hrs'); if (hrs) hrs.addEventListener('toggle', function () { CS.hro = hrs.open; });
            drw.querySelectorAll('.rv-ipl').forEach(function (el) { el.addEventListener('toggle', function () { CS.ipo = CS.ipo || {}; CS.ipo[el.dataset.net] = el.open; }); });
        };

        window.rvOpenClient = openClient;
        document.getElementById('rvFind').addEventListener('submit', function (e) {
            e.preventDefault();
            var v = document.getElementById('rvQ').value.trim(); if (!v) return;
            var lv = v.toLowerCase(), hit = null;
            Object.keys(NAMES).some(function (s) { if ((NAMES[s] || '').toLowerCase() === lv) { hit = s; return true; } return false; });
            if (!hit) Object.keys(NAMES).some(function (s) { if ((NAMES[s] || '').toLowerCase().indexOf(lv) >= 0) { hit = s; return true; } return false; });
            openClient(hit || v);
        });
        document.querySelectorAll('#rvMetric button').forEach(function (b) { b.addEventListener('click', function () { st.metric = b.dataset.m; document.querySelectorAll('#rvMetric button').forEach(function (x) { x.classList.toggle('on', x === b); }); paint(); }); });
        var tip = document.getElementById('rvTip'), box = document.getElementById('rvMapBox');
        if (gR) {
            gR.addEventListener('mousemove', function (e) {
                var id = e.target.dataset && e.target.dataset.id; if (!id) { tip.style.display = 'none'; return; }
                var r = RB[id], b = box.getBoundingClientRect();
                tip.innerHTML = '<b>' + esc(M.r[id].n) + '</b> ' + flag(M.r[id].cc) + (r ? '<br>' + r.n + ' устр. · ' + r.k + ' ' + plural(r.k, 'клиент', 'клиента', 'клиентов') + '<br>Неудачных: ' + pct(r.fail) + ' · медиана ' + (MED[r.med] || '—') + (r.fr ? '<br>16–20 режется: ' + r.fr : '') : '<br><span class="muted">клиентов нет</span>');
                tip.style.display = 'block';
                var x = e.clientX - b.left + 14; if (x + tip.offsetWidth > b.width) x = e.clientX - b.left - tip.offsetWidth - 10;
                tip.style.left = x + 'px'; tip.style.top = (e.clientY - b.top + 14) + 'px';
            });
            gR.addEventListener('mouseleave', function () { tip.style.display = 'none'; });
            gR.addEventListener('click', function (e) { if (Date.now() - drag.end < 250) return; var id = e.target.dataset && e.target.dataset.id; if (id && RB[id]) showRegion(id); });
        }
        var ndSort = document.getElementById('rvNdSort'), ndBody = document.getElementById('rvNdBody');
        if (ndSort && ndBody) {
            var ndRows = Array.prototype.slice.call(ndBody.children);
            ndSort.addEventListener('click', function (e) {
                var b = e.target.closest('button'); if (!b) return;
                ndSort.querySelectorAll('button').forEach(function (x) { x.classList.toggle('on', x === b); });
                var list = b.dataset.s === 'b' ? ndRows.slice().sort(function (x, y) { return +y.dataset.b - +x.dataset.b; }) : ndRows;
                list.forEach(function (tr) { ndBody.appendChild(tr); });
            });
        }
        window.addEventListener('resize', function () { dots(); });
        zoomUi();

        paint();
        side();
        renderIncs();
        if (Q0) openClient(Q0);
        if (DEVS.length) {
            fetch('?ajax=clod_names', {credentials: 'same-origin'}).then(function (r) { return r.json(); }).then(function (j) {
                if (!j || !j.names) return;
                var ch = false;
                Object.keys(NAMES).forEach(function (s) { if (j.names[s] && j.names[s] !== NAMES[s]) { NAMES[s] = j.names[s]; ch = true; } });
                if (ch) { side(); renderIncs(); if (st.sel) openPop(st.sel.ids, st.sel.x, st.sel.y); }
            }).catch(function () {});
        }
    })();
    </script>
