<?php
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
$rv_flag = static function ($cc) {
    if (!preg_match('~^[A-Z]{2}$~', (string) $cc)) return '';

    return '<span class="rv-fl">' . mb_chr(0x1F1E6 + ord($cc[0]) - 65) . mb_chr(0x1F1E6 + ord($cc[1]) - 65) . '</span>';
};
$rv_nflag = static fn($cc, $name) => preg_match('~^\s*[\x{1F1E6}-\x{1F1FF}]{2}~u', (string) $name) ? '' : $rv_flag($cc);
$rv_panel_of = static function ($server) use ($rv_panel) {
    $hit = $rv_panel[strtolower((string) $server)] ?? [];

    return is_array($hit) ? $hit : [];
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
    .rv-deadbtn{border:0;cursor:pointer;min-height:0;height:auto;padding:.08rem .45rem;font-family:inherit}
    .rv-deadbtn:hover{filter:brightness(1.15)}
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
    button.rv-mc:hover{border-color:var(--text-strong);filter:none}
    button.rv-mc.q0:hover{background:var(--hover)}
    button.rv-mc.q1:hover,button.rv-mc.q2:hover,button.rv-mc.q3:hover,button.rv-mc.q4:hover,button.rv-mc.q5:hover,button.rv-mc.dead:hover{filter:brightness(1.08)}
    .rv-mc.sel{border-color:var(--text-strong);box-shadow:0 0 0 2px var(--text-strong) inset}
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
    .rv-kv{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:.6rem}
    @media(max-width:800px){.rv-kv{grid-template-columns:repeat(2,minmax(0,1fr))}}
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
    table.rv-mx tr.cur td.rh,.rv-drw table.rv-mx tr.cur td.rh{box-shadow:inset 3px 0 var(--accent),4px 0 0 var(--bg);padding-left:.5rem}
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
                    <?php foreach (REP_VIEW_KIND_SET_NAMES + REP_VIEW_KINDS as $k => $kt): ?><option value="<?= h($k) ?>"<?= $rv_f['kind'] === $k ? ' selected' : '' ?>><?= h($kt) ?></option><?php endforeach; ?>
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
            <div class="rv-kpi"><span class="k">Не отвечает узел</span><span class="v<?= $rv_dead_cl ? ' bad' : '' ?>"><?= $rv_num(count($rv_dead_cl)) ?></span><span class="d">клиентов, у кого хоть один узел молчит</span></div>
            <div class="rv-kpi"><span class="k">Пинги</span><span class="v"><?= $rv_num($rv_tot['pn']) ?></span><span class="d">неудачных <span class="rq <?= $rv_qfail($rv_tot['fail']) ?>"><?= h($rv_pct($rv_tot['fail'])) ?></span></span></div>
            <div class="rv-kpi"><span class="k">Медианный пинг</span><span class="v" style="font-size:1.15rem"><?= h($rv_med($rv_tot['med'])) ?></span><span class="d">по <?= $rv_num($rv_tot['nodes']) ?> узлам</span></div>
            <div class="rv-kpi"><span class="k">Проверка 16–20</span><span class="v" style="font-size:1.05rem"><?= $rv_frz($rv_tot) ?></span><span class="d">режется · не отвечает · работает</span></div>
            <div class="rv-kpi"><span class="k">Трафик через узлы</span><span class="v"><?= h($rv_bytes($rv_tot['bytes'])) ?></span><span class="d">у клиентов с отчётами</span></div>
        </div>
    </div>

    <div class="card">
        <div class="loghead"><h2>Карта клиентов</h2>
            <div class="rv-flt"><span class="rv-seg" id="rvView"><button type="button" class="on" data-v="all">Всё СНГ</button><button type="button" data-v="west">Европейская часть, Урал, Кавказ</button></span>
            <span class="rv-seg" id="rvMetric"><button type="button" class="on" data-m="fail">Неудачные пинги</button><button type="button" data-m="med">Медианный пинг</button><button type="button" data-m="frz">Проверка 16–20</button></span></div>
        </div>
        <p class="muted" style="font-size:.82rem">Точка — клиенты в регионе по адресу, с которого устройство мерило пинги последним; число — устройств, цвет — худшее состояние среди них. Регион закрашен по выбранной метрике. Нажмите на точку или регион — список клиентов, на клиента — его устройства, сети и пинги.</p>
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
        <div class="loghead"><h2>Что не работает сейчас</h2></div>
        <p class="muted" style="font-size:.82rem">По последним отчётам устройств. Узел молчит у большинства клиентов одного провайдера, а у других провайдеров работает — блок у провайдера; молчит только у одного клиента во всех его сетях — дело в устройстве; пинг есть, а проверка 16–20 режется — DPI рвёт соединение после первых килобайт.</p>
        <div id="rvIncs"></div>
    </div>

    <div class="card" id="rvMxCard">
        <div class="loghead"><h2>Узлы × провайдеры</h2></div>
        <p class="muted" style="font-size:.82rem">Строка — узел, столбец — провайдер клиентов (AS), по последнему состоянию устройств за период. В ячейке — доля неудачных пингов и медиана; заштриховано и «N/M» — в стольких сетях из M узел не отвечает совсем. Нажмите на ячейку — кто именно, в какой сети и как менялось за 48 часов.</p>
        <div class="rv-mxbar"><span class="rv-seg" id="rvMxKind"><button type="button" data-k="">Все сети</button><button type="button" data-k="home" title="Wi-Fi и кабель — домашний интернет">Домашний</button><button type="button" data-k="mobile">Мобильная</button></span><span class="muted" id="rvMxNote"></span></div>
        <div id="rvMxT"></div>
        <div class="rv-mxd" id="rvMxd"><span class="muted" style="font-size:.82rem">Выберите ячейку — здесь появятся сети этого провайдера и их пинги до узла.</span></div>
    </div>

    <div class="card">
        <h2 style="margin-top:0;font-size:1rem">Пинги по <?= $rv_series['step'] === 3600 ? 'часам' : 'суткам (UTC)' ?><?= $rv_node ? ' — ' . h($rv_node['name'] !== '' ? $rv_node['name'] : $rv_node['server']) : '' ?></h2>
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
                $tt = $rv_dt($rv_series['step'] === 3600 ? 'd.m H:00' : 'd.m', $pt['t']) . ' — пингов ' . $pt['pn'] . ', неудачных ' . $pt['pf'] . ($pt['fail'] !== null ? ' (' . $rv_pct($pt['fail']) . ')' : '') . ', медиана ' . $rv_med($pt['med']);
            ?>
            <g><title><?= h($tt) ?></title>
                <rect class="ok" x="<?= round($x, 1) ?>" y="<?= round($H - $hOk - $hBad, 1) ?>" width="<?= round($w, 1) ?>" height="<?= round($hOk, 1) ?>"/>
                <?php if ($hBad > 0): ?><rect class="bad" x="<?= round($x, 1) ?>" y="<?= round($H - $hBad, 1) ?>" width="<?= round($w, 1) ?>" height="<?= round(max($hBad, 1), 1) ?>"/><?php endif; ?>
            </g>
            <?php endforeach; ?>
            <?php
            $marks = $rv_series['step'] === 3600 ? ($rv_f['p'] === 1 ? 6 : 24) : 5;
            foreach ($pts as $i => $pt):
                if ($i % $marks !== 0 || $i * $bw > $W - 40) continue; ?>
            <text class="ax" x="<?= round($i * $bw + 2, 1) ?>" y="<?= $H + 13 ?>"><?= h($rv_dt($rv_series['step'] === 3600 && $rv_f['p'] === 1 ? 'H:00' : 'd.m', $pt['t'])) ?></text>
            <?php endforeach; ?>
        </svg>
        <div class="rv-legend"><span><i style="background:var(--accent);opacity:.55"></i>удачные</span><span><i style="background:var(--rq5)"></i>неудачные</span><span>Наведите на столбец — подробности.</span></div>
    </div>

    <div class="card">
        <div class="loghead"><h2>Провайдеры клиентов (<?= count($rv_isps) ?>)</h2></div>
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
                <td><?= $rv_frz($isp) ?></td>
                <td class="num"><?= h($rv_bytes($isp['bytes'])) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="loghead"><h2>Узлы (<?= count($rv_nodes) ?>)</h2></div>
        <p class="muted" style="font-size:.82rem">Сверху — узлы с наибольшей долей неудачных пингов. «Не отвечает у» — у скольких устройств узел не ответил ни на один пинг в последнем отчёте. «Хуже всего» — страна и провайдер клиентов, у которых этому узлу хуже всего (от <?= REP_VIEW_MIN_PINGS ?> пингов). Нажмите на узел — график и провайдеры только по нему.</p>
        <?php if (!$rv_nodes): ?>
        <p class="muted">Нет данных за период.</p>
        <?php else: ?>
        <div class="rv-wrap"><table class="logtbl rv-tbl">
            <thead><tr><th>Узел</th><th>В панели</th><th class="num">Пингов</th><th class="num">Неудачи</th><th>Медиана</th><th>16–20</th><th class="num">Не отвечает у</th><th class="num">Трафик</th><th>Хуже всего</th></tr></thead>
            <tbody>
            <?php foreach ($rv_nodes as $n): $pnl = $rv_panel_of($n['server']); $nd = count($rv_dead_node[$n['nkey']] ?? []); ?>
            <tr>
                <td class="rv-node"><a href="<?= h($rv_url(['node' => $n['nkey']])) ?>"><?= h($n['name'] !== '' ? $n['name'] : $n['server']) ?></a>
                    <span class="sub"><?= h(rep_proto_label($n['type'], $n['server'], $n['port'])) ?> · <code><?= h($n['server']) ?>:<?= (int) $n['port'] ?></code></span></td>
                <td><?php if ($pnl): foreach ($pnl as $pn): ?><div><?= $rv_nflag($pn['cc'], $pn['name']) ?> <?= h($pn['name']) ?></div><?php endforeach; else: ?><span class="muted">—</span><?php endif; ?></td>
                <td class="num"><?= $rv_num($n['pn']) ?></td>
                <td class="num"><span class="rq <?= $rv_qfail($n['fail']) ?>"><?= h($rv_pct($n['fail'])) ?></span></td>
                <td><span class="rq <?= $rv_qmed($n['med']) ?>"><?= h($rv_med($n['med'])) ?></span></td>
                <td><?= $rv_frz($n) ?></td>
                <td class="num"><?= $nd > 0 ? '<button type="button" class="rq q5 rv-deadbtn" data-nk="' . h($n['nkey']) . '" title="Показать, у кого">' . $rv_num($nd) . ' устр.</button>' : '<span class="muted">—</span>' ?></td>
                <td class="num"><?= h($rv_bytes($n['bytes'])) ?></td>
                <td><?php if ($n['worst'] && $n['worst']['fail'] > 0): ?><?= $rv_flag($n['worst']['cc']) ?> AS<?= (int) $n['worst']['asn'] ?> <span class="rq <?= $rv_qfail($n['worst']['fail']) ?>"><?= h($rv_pct($n['worst']['fail'])) ?></span><?php else: ?><span class="muted">—</span><?php endif; ?></td>
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
        var NAMES = <?= json_encode((object) $rv_names, $rv_js) ?>;
        var Q0 = <?= json_encode($rv_f['q'], $rv_js) ?>;
        var MX = <?= json_encode($rv_mx, $rv_js) ?>, MXK = <?= json_encode($rv_f['kind'], $rv_js) ?>;
        var MXQ = <?= json_encode(http_build_query(array_filter(['p' => $rv_f['p'], 'plat' => $rv_f['plat'], 'cc' => $rv_f['cc'], 'node' => $rv_f['node']], static fn($v) => $v !== '' && $v !== null)), $rv_js) ?>;
        var FQ = <?= json_encode(http_build_query(array_filter(['p' => $rv_f['p'], 'kind' => $rv_f['kind'], 'plat' => $rv_f['plat'], 'cc' => $rv_f['cc']], static fn($v) => $v !== '' && $v !== null)), $rv_js) ?>;
        var M = window.SUBMW_CIS;
        var CIS = {RU:1, BY:1, UA:1, MD:1, GE:1, AM:1, AZ:1, KZ:1, UZ:1, TM:1, KG:1, TJ:1};
        var KIND = {wifi: 'Wi-Fi', mobile: 'Мобильная', wired: 'Кабель', other: 'Другая'};
        var MED = ['< 100 мс', '100–200 мс', '200–400 мс', '400–800 мс', '0,8–1,5 с', '> 1,5 с'];
        var VIEWS = {all: [0, 0, 1000, 540], west: [95, 150, 440, 238]};
        var st = {view: 'all', vb: VIEWS.all.slice(), metric: 'fail', sel: null, side: null};
        var dn = null;
        try { dn = new Intl.DisplayNames(['ru'], {type: 'region'}); } catch (e) {}
        var ccName = function (cc) { try { return dn && cc ? dn.of(cc) : cc; } catch (e) { return cc; } };
        var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]; }); };
        var ico = function (id, cls) { return '<svg class="rvi' + (cls ? ' ' + cls : '') + '"><use href="#rvi-' + id + '"/></svg>'; };
        var hasFlag = function (x) { return /^\s*[\u{1F1E6}-\u{1F1FF}]{2}/u.test(String(x || '')); };
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
            return {devs: entries, n: entries.length, k: Object.keys(cl).length, pn: pn, fail: pn > 0 ? pf / pn : null, med: med, fr: fr, vok: vok, st: s};
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
            if (st.metric === 'fail') return r.pn > 0 ? qFail(r.fail) : 0;
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
        var redraw = function () { if (raf) return; raf = requestAnimationFrame(function () { raf = 0; document.getElementById('rvMap').setAttribute('viewBox', st.vb.map(function (v) { return v.toFixed(2); }).join(' ')); dots(); zoomUi(); }); };
        var zoomUi = function () {
            var near = function (a) { return a.every(function (v, i) { return Math.abs(v - st.vb[i]) < .5; }); };
            document.querySelectorAll('#rvView button').forEach(function (b) { b.classList.toggle('on', near(VIEWS[b.dataset.v])); });
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
        var histCells = function (hist, hh) {
            var now = Math.floor(Date.now() / 3600000) * 3600, out = '';
            for (var i = 0; i < 48; i++) {
                var t = now - (47 - i) * 3600, ch = '.';
                if (hh > 0 && hist) { var j = hist.length - 1 - Math.round((hh - t) / 3600); if (j >= 0 && j < hist.length) ch = hist.charAt(j); }
                out += '<i class="' + (ch === 'x' ? 'hx' : /[0-5]/.test(ch) ? 'h' + ch : '') + '"></i>';
            }
            return out;
        };
        var mxd = document.getElementById('rvMxd'), mxd0 = mxd ? mxd.innerHTML : '', mxT = document.getElementById('rvMxT'), mxNote = document.getElementById('rvMxNote'), mxSeq = 0;
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
                return '<tr><td class="rh" title="' + esc(n.a) + '">' + flagFor(n.cc, n.nm) + '<b>' + esc(n.nm) + '</b><small>' + esc(n.t) + '</small></td>' + cols.map(function (col) {
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
                MXK = b.dataset.k; var seq = ++mxSeq;
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
                fetch('?ajax=rep_cell&' + mxQ() + '&nk=' + encodeURIComponent(c.dataset.nk) + '&asn=' + encodeURIComponent(c.dataset.asn), {credentials: 'same-origin'}).then(function (r) { return r.json(); }).then(function (j) {
                    if (!c.classList.contains('sel')) return;
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
                }).catch(function () { if (!c.classList.contains('sel')) return; mxd.style.minHeight = ''; mxd.style.opacity = ''; mxd.innerHTML = '<span class="muted">Не удалось загрузить.</span>'; });
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
        drwBg.addEventListener('click', function () { closeMod(); closeClient(); });
        document.addEventListener('keydown', function (e) { if (e.key !== 'Escape') return; if (mod.classList.contains('open')) closeMod(); else if (drw.classList.contains('open')) closeClient(); });
        var openClient = function (q) {
            drw.innerHTML = '<div class="rv-dh"><h2>Загружаю…</h2><span class="sp"></span><button type="button" class="btn ghost" id="rvDrwX" aria-label="Закрыть">' + ico('x') + '</button></div>';
            drw.querySelector('#rvDrwX').addEventListener('click', closeClient);
            drw.classList.add('open'); drwBg.classList.add('open');
            fetch('?ajax=rep_client&' + FQ + '&q=' + encodeURIComponent(q), {credentials: 'same-origin'}).then(function (r) { return r.json(); }).then(function (j) {
                if (!j || !j.ok || !j.card) { drw.querySelector('h2').textContent = 'Отчётов от этого клиента нет'; return; }
                var c = j.card;
                c.nk = {}; (c.nodes || []).forEach(function (n) { c.nk[n.k] = n; });
                c.rows.forEach(function (r) { r.rg = regionOf(r.loc, r.cc, r.sub); });
                if (c.name) NAMES[c.short] = c.name;
                CS = {c: c, dev: 0, cell: null, only: false};
                drawClient();
            }).catch(function () { drw.querySelector('h2').textContent = 'Не удалось загрузить'; });
        };
        var drawClient = function (keep) {
            var oldDb = drw.querySelector('.rv-db'), oldTop = oldDb ? oldDb.scrollTop : 0;
            var c = CS.c, d = c.devs[CS.dev] || c.devs[0], nm = c.name || nameOf(c.short);
            var rs = c.rows.filter(function (r) { return r.hw === d.hw; });
            var nets = [], nmap = {};
            rs.forEach(function (r) { if (!nmap[r.net]) { nmap[r.net] = {net: r.net, k: r.k, ip: '', ip6: '', cc: '', asn: 0, org: '', rg: null, hh: 0, seen: 0, cells: {}, ah: -1}; nets.push(nmap[r.net]); } var n = nmap[r.net]; n.cells[r.n] = r; n.hh = Math.max(n.hh, r.hh); n.seen = Math.max(n.seen, r.seen); if (r.ip && r.hh >= n.ah) { n.ah = r.hh; n.ip = r.ip; n.ip6 = r.ip6; n.cc = r.cc; n.asn = r.asn; n.org = r.org; n.rg = r.rg; n.k = r.k; } });
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
            var show = CS.only ? nkeys.filter(function (k) { return nets.some(function (n) { var y = n.cells[k]; return y && (y.dead || y.v === 'frozen' || (y.fail !== null && y.fail >= .03) || y.med >= 3); }); }) : nkeys;
            if (!CS.cell || !nmap[CS.cell.split('|')[1]] || !nmap[CS.cell.split('|')[1]].cells[CS.cell.split('|')[0]]) {
                CS.cell = null;
                nkeys.some(function (k) { return nets.some(function (n) { if (n.cells[k] && n.cells[k].dead) { CS.cell = k + '|' + n.net; return true; } return false; }); });
                if (!CS.cell && nkeys.length && nets.length) CS.cell = nkeys[0] + '|' + nets[0].net;
            }
            var cell = function (r, key) {
                if (!r || !r.pn) return '<div class="rv-mc"><span class="a">—</span></div>';
                var sel = CS.cell === key ? ' sel' : '';
                if (r.dead) return '<button type="button" class="rv-mc dead' + sel + '" data-cell="' + esc(key) + '"><span class="a">нет ответа</span><span class="b">0 из ' + r.pn + '</span></button>';
                return '<button type="button" class="rv-mc q' + qFail(r.pf / r.pn) + sel + '" data-cell="' + esc(key) + '"><span class="a">' + fl(r.pf, r.pn) + (r.v === 'frozen' ? ' <svg class="rvi" style="width:11px;height:11px"><use href="#rvi-scissors"/></svg>' : '') + '</span><span class="b">' + (MED[r.med] || '—') + '</span></button>';
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
            var devTabs = c.devs.map(function (x, i) {
                var xr = c.rows.filter(function (r) { return r.hw === x.hw; }), xp = 0, xf = 0; xr.forEach(function (r) { xp += r.pn; xf += r.pf; }); var bad = xr.some(function (r) { return r.dead; }) || (xp >= 20 && xf / xp >= .1), warn = xr.some(function (r) { return r.v === 'frozen'; }) || (xp >= 20 && xf / xp >= .03);
                return '<div role="button" tabindex="0" class="rv-dtab' + (i === CS.dev ? ' on' : '') + '" data-i="' + i + '">' + ico(x.p === 'pc' ? 'pc' : 'android') + '<span><span class="t">' + esc(x.m || (x.p === 'pc' ? 'ПК' : x.p === 'android' ? 'Android' : 'Устройство')) + '</span><span class="s">' + esc([x.o, x.c ? 'Clod Clash ' + x.c : ''].filter(Boolean).join(' · ') || x.hw.slice(0, 16)) + '</span></span><span style="flex:1"></span>' + (bad || warn ? '<button type="button" class="rv-pill rv-jump st-' + (bad ? 'bad' : 'warn') + '" title="Показать проблемный узел">' + (bad ? 'проблемы' : 'деградация') + '</button>' : '<span class="rv-pill st-ok">в порядке</span>') + '</div>';
            }).join('');
            var sel = CS.cell ? CS.cell.split('|') : null, sr = sel && nmap[sel[1]] ? nmap[sel[1]].cells[sel[0]] : null, sn = sel ? nmap[sel[1]] : null, sp = sr && sn ? peer(sn.asn, sel[0]) : null;
            drw.innerHTML = '<div class="rv-dh"><span class="av">' + esc((nm || c.short).slice(0, 2).toUpperCase()) + '</span><div style="min-width:0"><h2>' + esc(nm || c.short) + '</h2><div class="muted" style="font-size:.78rem">' + (nm ? 'имя из панели · ' : '') + '<code>' + esc(c.short) + '</code></div></div>'
                + '<span class="rv-pill st-mut">' + c.devs.length + ' ' + plural(c.devs.length, 'устройство', 'устройства', 'устройств') + '</span><span class="sp"></span>'
                + '<a class="btn ghost" href="?tab=reqlog&rl_q=' + encodeURIComponent(c.short) + '">' + ico('list') + 'Лог запросов</a>'
                + '<button type="button" class="btn ghost" id="rvDrwX" aria-label="Закрыть">' + ico('x') + '</button></div>'
                + '<div class="rv-db"><div class="rv-sect" style="margin-top:0">Устройства</div><div class="rv-dtabs">' + devTabs + '</div>'
                + '<div class="rv-kv"><div><span>Модель и ОС</span><b>' + esc([d.m, d.o].filter(Boolean).join(' · ') || '—') + '</b></div><div><span>Клиент</span><b>' + esc(d.c ? 'Clod Clash ' + d.c : '—') + (d.p ? ' · ' + (d.p === 'pc' ? 'ПК' : 'Android') : '') + '</b></div><div><span>HWID</span><b><code>' + esc(d.hw) + '</code></b></div><div><span>Отчётов</span><b>' + (d.r || '—') + ' · последний ' + ago(d.lr) + '</b></div></div>'
                + '<div class="rv-sect">Диагноз</div>' + diag.map(function (x) { return '<div class="rv-diag st-' + x.s + '"' + (x.j ? ' data-jump="' + esc(x.j) + '" role="button" tabindex="0" title="Показать в таблице пингов"' : '') + '><span class="ic st-' + x.s + '">' + ico(x.ic) + '</span><div><div class="t">' + esc(x.t) + '</div>' + (x.w ? '<div class="w">' + x.w + '</div>' : '') + '</div>' + (x.j ? '<span class="go">к узлу ↓</span>' : '') + '</div>'; }).join('')
                + '<div class="rv-sect">Сети устройства</div><div class="rv-nets">' + nets.map(function (n, i) {
                    var pn = 0, pf = 0, dd = 0; nkeys.forEach(function (k) { var y = n.cells[k]; if (y) { pn += y.pn; pf += y.pf; if (y.dead) dd++; } });
                    return '<div class="rv-net"><div class="h">' + ico(KIND[n.k] ? n.k : 'other') + esc(KIND[n.k] || n.k) + (i === 0 ? ' <span class="rv-pill st-info">последняя</span>' : '') + '<span class="sp"></span>' + (dd ? '<span class="rv-pill st-bad">' + dd + ' без ответа</span>' : '<span class="rq q' + qFail(pn ? pf / pn : null) + '">' + fl(pf, pn) + '</span>') + '</div>'
                        + '<div class="r"><b style="color:var(--text)">' + (n.asn ? 'AS' + n.asn + ' ' : '') + esc(n.org || '—') + '</b><br><code>' + esc(n.ip || '—') + '</code>' + (n.ip6 ? ' · <code>' + esc(n.ip6) + '</code>' : '') + '<br>' + flag(n.cc) + ' ' + esc(n.rg ? M.r[n.rg].n + ', ' + ccName(n.cc) : (n.cc ? ccName(n.cc) : 'без геопозиции')) + '<br>замер ' + ago(n.seen) + '</div></div>';
                }).join('') + '</div>'
                + '<div class="rv-sect">Пинги до узлов по сетям<label><input type="checkbox" id="rvOnly"' + (CS.only ? ' checked' : '') + '> только проблемные</label></div>'
                + (nkeys.length ? '<div class="rv-wrap"><table class="rv-mx"><thead><tr><th class="rh">Узел</th>' + nets.map(function (n) { return '<th style="min-width:140px">' + ico(KIND[n.k] ? n.k : 'other') + '<div style="color:var(--text);margin-top:.15rem">' + esc(label(n)) + '</div><div style="font-weight:400"><code>' + esc(n.ip || '—') + '</code></div></th>'; }).join('') + '</tr></thead><tbody>'
                    + show.map(function (k) { var nd = c.nk[k] || {}; return '<tr data-k="' + esc(k) + '"' + (sel && sel[0] === k ? ' class="cur"' : '') + '><td class="rh">' + flagFor(nd.cc, nodeNm(k)) + '<b>' + esc(nodeNm(k)) + '</b><small>' + esc(nd.t || '') + '</small></td>' + nets.map(function (n) { return '<td>' + cell(n.cells[k], k + '|' + n.net) + '</td>'; }).join('') + '</tr>'; }).join('')
                    + '</tbody></table></div>' : '<p class="muted">Пингов в отчётах нет.</p>')
                + (sr ? '<div class="rv-mxd"><h3>' + esc(nodeNm(sel[0])) + ' в сети «' + esc(label(sn)) + '» — последние 48 часов</h3><div class="muted" style="font-size:.8rem">Пингов ' + sr.pn + ', неудачных ' + sr.pf + ' · медиана ' + (MED[sr.med] || '—') + (sp ? ' · у других клиентов этого провайдера: неудач ' + pct(sp.fail) + ', медиана ' + (MED[sp.med] || '—') + (sp.dead ? ', без ответа ' + sp.dead + ' из ' + sp.n : '') : '') + '</div>'
                    + '<div class="rv-hist">' + histCells(sr.hist, sr.hh) + '</div><div class="rv-hax"><span>48 ч назад</span><span>24 ч</span><span>сейчас</span></div>'
                    + '<div class="rv-legend"><span><i style="background:var(--rq1)"></i>&lt; 100 мс</span><span><i style="background:var(--rq2)"></i>100–200</span><span><i style="background:var(--rq3)"></i>200–400</span><span><i style="background:var(--rq4)"></i>400–800</span><span><i style="background:var(--rq5)"></i>&gt; 0,8 с</span><span><i class="hx" style="background:repeating-linear-gradient(135deg,var(--rq5) 0 3px,transparent 3px 6px)"></i>нет ответа</span><span><i style="background:var(--line)"></i>нет замеров в этой сети</span></div>'
                    + (sr.hist ? '' : '<div class="muted" style="font-size:.78rem;margin-top:.3rem">' + esc(histWhy(sr.seen)) + '</div>') + '</div>' : '')
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
                    box.scrollTo({top: box.scrollTop + rb.top - bb.top - Math.max(16, (bb.height - rb.height) / 3), behavior: 'smooth'});
                    var sc = tr.querySelector('.rv-mc.sel'); if (sc && sc.scrollIntoView) { var wr = tr.closest('.rv-wrap'); if (wr) wr.scrollLeft = Math.max(0, sc.parentNode.offsetLeft - wr.clientWidth / 2); }
                    tr.classList.add('rv-flash'); setTimeout(function () { tr.classList.remove('rv-flash'); }, 2500);
                }
            }
            drw.querySelectorAll('[data-cell]').forEach(function (b) { b.addEventListener('click', function () { CS.cell = b.dataset.cell; drawClient(true); }); });
            var only = drw.querySelector('#rvOnly'); if (only) only.addEventListener('change', function () { CS.only = only.checked; drawClient(true); });
        };

        document.getElementById('rvFind').addEventListener('submit', function (e) {
            e.preventDefault();
            var v = document.getElementById('rvQ').value.trim(); if (!v) return;
            var lv = v.toLowerCase(), hit = null;
            Object.keys(NAMES).some(function (s) { if ((NAMES[s] || '').toLowerCase() === lv) { hit = s; return true; } return false; });
            if (!hit) Object.keys(NAMES).some(function (s) { if ((NAMES[s] || '').toLowerCase().indexOf(lv) >= 0) { hit = s; return true; } return false; });
            openClient(hit || v);
        });
        document.querySelectorAll('#rvMetric button').forEach(function (b) { b.addEventListener('click', function () { st.metric = b.dataset.m; document.querySelectorAll('#rvMetric button').forEach(function (x) { x.classList.toggle('on', x === b); }); paint(); }); });
        document.querySelectorAll('#rvView button').forEach(function (b) { b.addEventListener('click', function () { closePop(); zoomTo(VIEWS[b.dataset.v].slice(), true, b.dataset.v); }); });
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
