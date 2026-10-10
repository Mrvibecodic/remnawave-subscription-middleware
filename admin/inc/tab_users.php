<?php
$ux_list = [];
foreach ($users as $__u) { $__n = ua_norm($__u); if ($__n && $__n['su'] !== '') $ux_list[] = $__n; }
$ux_ovb = [];
foreach ($ov_index as $__s => $__o) if (($__o['reason'] ?? '') === 'blocked') $ux_ovb[$__s] = 1;
$ux_nl = [];
foreach ($nolog_set as $__k => $__v) $ux_nl[(string) $__k] = 1;
$ux_ts = [];
$__tc = tokscope_cached();
if ($__tc) foreach ($__tc['rows'] as $__r) if (isset($__r['s'], $__r['st'])) $ux_ts[(string) $__r['s']] = (string) $__r['st'];
$ux_hwb = [];
foreach ($overrides as $__o) if (($__o['match_type'] ?? '') === 'hwid' && ($__o['reason'] ?? '') === 'blocked') $ux_hwb[] = mb_strtolower((string) $__o['match_value']);
$ux_cfg = [
    'csrf'  => $token,
    'gsq'   => grace_squad_uuid(),
    'reset' => grace_reset_traffic_on_exit(),
    'gr'    => (object) ua_grace_map(),
    'ovb'   => (object) $ux_ovb,
    'as'    => (object) $addsub_links,
    'nl'    => (object) $ux_nl,
    'ts'    => (object) $ux_ts,
    'hwb'   => $ux_hwb,
    'mirror'=> $mirror !== '',
    'qr'    => 'assets/qrcode.js?v=' . substr(md5_file(__DIR__ . '/../assets/qrcode.js'), 0, 10),
];
$__jf = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
?>
    <style>
.ux{--ux-gap:.9rem}
.ux .card{margin-bottom:var(--ux-gap);padding:1rem 1.1rem}
.ux .card h2{font-size:1rem;margin:0}
.ux .wb,.ux-dr .wb,.ux-menu .wb{background:var(--bg2);color:var(--text);border:1px solid var(--line);border-radius:8px;padding:.42rem .8rem;font-size:.82rem;font-weight:600;display:inline-flex;align-items:center;gap:.4rem;white-space:nowrap;line-height:1.2;min-height:0;cursor:pointer}
.ux .wb:hover,.ux-dr .wb:hover{background:var(--hover);filter:none;border-color:var(--accent)}
.ux .wb:active,.ux-dr .wb:active{transform:none}
.ux .wb.pri,.ux-dr .wb.pri{background:var(--accent);color:var(--on-accent);border-color:var(--accent)}
.ux .wb.pri:hover,.ux-dr .wb.pri:hover{background:var(--accent-h)}
.ux .wb.sm,.ux-dr .wb.sm{padding:.26rem .55rem;font-size:.76rem}
.ux .wb.bad,.ux-dr .wb.bad{color:var(--c-bad-fg)}
.ux .wb:disabled,.ux-dr .wb:disabled{opacity:.5;cursor:not-allowed;border-color:var(--line)}
.ux .wb svg,.ux-dr .wb svg{width:15px;height:15px;flex:none}
.ux-ib{width:30px;height:30px;padding:0!important;display:inline-flex;align-items:center;justify-content:center;border-radius:8px;border:1px solid transparent;background:none;color:var(--muted);cursor:pointer;min-height:0}
.ux-ib,.ux-ib:hover,.ux-ib:active{filter:none;transform:none}
.ux-ib:hover{background:var(--hover);color:var(--text-strong);border-color:var(--line)}
.ux-ib svg{width:16px;height:16px}
.ux-kpis{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:.7rem}
.ux .ux-kpi{border:1px solid var(--line);background:var(--bg2);border-radius:12px;padding:.75rem .9rem;display:flex;flex-direction:column;gap:.15rem;text-align:left;cursor:pointer;color:var(--text);font-weight:400;min-height:0;transition:border-color .12s}
.ux .ux-kpi:hover{border-color:var(--accent);background:var(--bg2);filter:none}
.ux .ux-kpi:active{transform:none}
.ux .ux-kpi.on{border-color:var(--accent);box-shadow:0 0 0 1px var(--accent) inset;background:var(--accent-light)}
.ux-kpi .k{font-size:.72rem;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;display:flex;align-items:center;gap:.4rem}
.ux-kpi .k i{width:7px;height:7px;border-radius:50%;flex:none}
.ux-kpi .v{font-size:1.45rem;font-weight:700;color:var(--text-strong);font-variant-numeric:tabular-nums;line-height:1.2}
.ux-kpi .d{font-size:.76rem;color:var(--muted)}
.ux-h{display:flex;align-items:center;justify-content:space-between;gap:.6rem 1rem;flex-wrap:wrap;margin-bottom:.85rem}
.ux-h .ux-cnt{font-weight:500;color:var(--muted);font-size:.86rem;margin-left:.35rem}
.ux-acts{display:flex;gap:.5rem;align-items:center;flex-wrap:wrap}
.ux-flt{display:flex;flex-wrap:wrap;gap:.5rem;align-items:center}
.ux-flt .sp{flex:1}
.ux-flt input#uxQ{width:20rem;flex:0 1 20rem;min-width:10rem;height:38px;min-height:38px;margin:0}
.ux-flt select{width:auto;height:38px;min-height:38px;padding-top:0;padding-bottom:0;font-size:.84rem;margin:0}
.ux-chip{display:inline-flex;align-items:center;gap:.35rem;padding:.3rem .6rem;border:1px solid var(--accent);border-radius:999px;background:var(--accent-light);color:var(--accent-text);font-size:.8rem;font-weight:600}
.ux .ux-chip button{all:unset;cursor:pointer;line-height:1;opacity:.8}
.ux-seg{display:inline-flex;border:1px solid var(--line);border-radius:10px;overflow:hidden;background:var(--bg2)}
.ux .ux-seg button,.ux-dr .ux-seg button{padding:.45rem .7rem;font-size:.82rem;color:var(--muted);border:0;border-left:1px solid var(--line);background:transparent;border-radius:0;min-height:0;height:auto;font-weight:500;cursor:pointer;display:inline-flex;align-items:center;gap:.35rem}
.ux .ux-seg button:first-child,.ux-dr .ux-seg button:first-child{border-left:0}
.ux .ux-seg button:hover,.ux-dr .ux-seg button:hover{background:var(--hover);filter:none;color:var(--text)}
.ux .ux-seg button:active,.ux-dr .ux-seg button:active{transform:none}
.ux .ux-seg button.on,.ux-dr .ux-seg button.on{background:var(--accent-light);color:var(--accent-text);font-weight:600}
.ux-seg svg{width:15px;height:15px}
.ux-bulk{display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;margin-top:.75rem;padding:.5rem .6rem .5rem .85rem;border:1px solid var(--accent);background:var(--accent-light);border-radius:10px;font-size:.84rem}
.ux-bulk[hidden]{display:none}
.ux-bulk b{color:var(--text-strong);margin-right:.4rem}
.ux-bulk .sp{flex:1}
.ux-wrap{margin-top:.85rem;border:1px solid var(--line);border-radius:12px;overflow:clip}
.ux-tbl{width:100%;border-collapse:separate;border-spacing:0;font-size:.86rem;min-width:0}
.ux-tbl thead th{position:sticky;top:64px;z-index:5;background:var(--bg2);font-size:.71rem;text-transform:uppercase;letter-spacing:.04em;color:var(--muted);font-weight:600;text-align:left;padding:0 .7rem;height:40px;white-space:nowrap;box-shadow:inset 0 -1px 0 var(--line);border:0}
.ux-tbl thead th.srt{cursor:pointer;user-select:none}
.ux-tbl thead th.srt:hover{color:var(--accent-text)}
.ux-tbl thead th .sar{font-size:.65rem;margin-left:.25rem}
.ux-tbl thead th.num,.ux-tbl td.num{text-align:right}
.ux-tbl td{padding:.55rem .7rem;height:46px;box-shadow:inset 0 -1px 0 var(--line);border:0;vertical-align:middle;background:transparent}
.ux-tbl tbody tr:last-child td{box-shadow:none}
.ux-tbl tbody tr{cursor:pointer}
.ux-tbl tbody tr:hover td{background:var(--hover2)}
.ux-tbl tbody tr.sel td{background:var(--accent-light)}
.ux-tbl.compact td{padding-top:.3rem;padding-bottom:.3rem;height:34px}
.ux-tbl.compact .u-sub{display:none}
.ux-tbl .cb{width:34px;padding-right:0}
.ux-tbl .acts{width:1%;white-space:nowrap;text-align:right;padding-right:.45rem}
.ux-tbl .acts .ux-ib.q{opacity:0}
.ux-tbl tr:hover .acts .ux-ib.q,.ux-tbl tr:focus-within .acts .ux-ib.q{opacity:1}
.ux-cbx{appearance:none;-webkit-appearance:none;width:16px;height:16px;margin:0;border:1.5px solid var(--muted);border-radius:4px;background:transparent;cursor:pointer;position:relative;vertical-align:middle;display:inline-block;padding:0;min-height:0}
.ux-cbx:checked{background:var(--accent);border-color:var(--accent)}
.ux-cbx:checked::after{content:"";position:absolute;left:4px;top:1px;width:4px;height:8px;border:solid var(--on-accent);border-width:0 2px 2px 0;transform:rotate(45deg)}
.ux-cbx:indeterminate{background:var(--accent);border-color:var(--accent)}
.ux-cbx:indeterminate::after{content:"";position:absolute;left:3px;right:3px;top:6px;height:2px;background:var(--on-accent)}
.u-nm{color:var(--text-strong);font-weight:600;display:flex;align-items:center;gap:.4rem;min-width:0}
.u-nm span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.u-sub{display:block;color:var(--muted);font-size:.76rem;margin-top:.1rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:16rem}
.ux-pill{display:inline-flex;align-items:center;gap:.3rem;padding:.1rem .5rem;border-radius:999px;font-size:.74rem;font-weight:600;white-space:nowrap;font-variant-numeric:tabular-nums;line-height:1.5}
.ux-pill i{width:6px;height:6px;border-radius:50%;background:currentColor;flex:none}
.st-ok{background:var(--c-ok-bg);color:var(--c-ok-fg)}
.st-warn{background:var(--c-warn-bg);color:var(--c-warn-fg)}
.st-bad{background:var(--c-bad-bg);color:var(--c-bad-fg)}
.st-info{background:var(--c-info-bg);color:var(--c-info-fg)}
.st-vio{background:var(--c-violet-bg);color:var(--c-violet-fg)}
.st-mut{background:var(--hover);color:var(--muted)}
.ux-tag{display:inline-flex;font-size:.66rem;font-weight:700;padding:.05rem .4rem;border-radius:5px;letter-spacing:.03em;background:var(--hover);color:var(--muted);border:1px solid var(--line);flex:none}
.ux-tr{min-width:8rem}
.ux-tr .t{display:flex;justify-content:space-between;gap:.5rem;font-size:.8rem;font-variant-numeric:tabular-nums;white-space:nowrap}
.ux-tr .t b{color:var(--text-strong);font-weight:600}
.ux-tr .t span{color:var(--muted)}
.ux-bar{height:4px;border-radius:999px;background:var(--hover);overflow:hidden;margin-top:.3rem}
.ux-bar i{display:block;height:100%;background:var(--accent);border-radius:999px}
.ux-bar.lv-w i{background:var(--c-warn-fg)}
.ux-bar.lv-b i{background:var(--c-bad-fg)}
.ux-bar.lv-i i{background:var(--muted);opacity:.45}
.ux-dt{white-space:nowrap;font-variant-numeric:tabular-nums}
.ux-dt .r{display:block;font-size:.76rem;color:var(--muted)}
.ux-dt .r.lv-w{color:var(--c-warn-fg)}
.ux-dt .r.lv-b{color:var(--c-bad-fg)}
.ux-sqs{display:flex;gap:.25rem;flex-wrap:wrap;max-width:10rem}
.ux-sq{display:inline-block;max-width:8rem;overflow:hidden;text-overflow:ellipsis;font-size:.72rem;padding:.08rem .45rem;border-radius:999px;background:var(--bg2);border:1px solid var(--line);color:var(--text);white-space:nowrap}
.ux-sq.gr{background:var(--c-violet-bg);color:var(--c-violet-fg);border-color:transparent}
.ux-on{display:inline-flex;align-items:center;gap:.4rem;white-space:nowrap;font-size:.82rem;color:var(--muted)}
.ux-on i{width:7px;height:7px;border-radius:50%;background:var(--line);flex:none}
.ux-on.live{color:var(--text)}
.ux-on.live i{background:var(--c-ok-fg);box-shadow:0 0 0 3px var(--c-ok-bg)}
.ux-dv{font-variant-numeric:tabular-nums;white-space:nowrap}
.ux-dv.full{color:var(--c-warn-fg);font-weight:600}
.ux-pgr{display:flex;align-items:center;justify-content:space-between;gap:.75rem;margin-top:.75rem;flex-wrap:wrap;font-size:.82rem;color:var(--muted);min-height:36px}
.ux-pgr .nav{display:flex;gap:.35rem;align-items:center}
.ux-pgr select{width:auto;height:32px;min-height:32px;padding-top:0;padding-bottom:0;font-size:.82rem;margin:0 0 0 .35rem}
.ux-empty{padding:2.2rem 1rem;text-align:center;color:var(--muted)}
.ux-menu{position:fixed;z-index:98;min-width:15rem;background:var(--card);border:1px solid var(--line);border-radius:10px;box-shadow:var(--shadow);padding:.3rem;display:none}
.ux-menu.open{display:block}
.ux-menu button{all:unset;box-sizing:border-box;width:100%;display:flex;align-items:center;gap:.6rem;padding:.48rem .65rem;border-radius:7px;font-size:.85rem;color:var(--text);cursor:pointer}
.ux-menu button:hover,.ux-menu button:focus-visible{background:var(--hover);color:var(--text-strong)}
.ux-menu button svg{width:16px;height:16px;color:var(--muted);flex:none}
.ux-menu button.bad,.ux-menu button.bad svg{color:var(--c-bad-fg)}
.ux-menu button .kb{margin-left:auto;font-size:.72rem;color:var(--muted)}
.ux-menu hr{border:0;border-top:1px solid var(--line);margin:.3rem .2rem}
.ux-ov{position:fixed;inset:0;z-index:95;background:rgba(2,6,23,.45);backdrop-filter:blur(3px);-webkit-backdrop-filter:blur(3px);opacity:0;pointer-events:none;transition:opacity .16s}
.ux-ov.open{opacity:1;pointer-events:auto}
.ux-dr{position:absolute;top:0;right:0;bottom:0;width:min(760px,100vw);background:var(--card);border-left:1px solid var(--line);box-shadow:var(--shadow);display:flex;flex-direction:column;transform:translateX(24px);transition:transform .18s ease-out;border-radius:var(--radius) 0 0 var(--radius)}
.ux-ov.open .ux-dr{transform:none}
.ux-dh{padding:1rem 1.2rem .8rem;border-bottom:1px solid var(--line);display:flex;flex-direction:column;gap:.7rem}
.ux-dh .r1{display:flex;align-items:flex-start;gap:.8rem}
.ux-av{width:42px;height:42px;border-radius:12px;background:var(--accent-light);color:var(--accent-text);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:1rem;flex:none;text-transform:uppercase}
.ux-dh .nm{font-size:1.15rem;font-weight:700;color:var(--text-strong);display:flex;align-items:center;gap:.5rem;flex-wrap:wrap}
.ux-dh .id{font-size:.78rem;color:var(--muted);margin-top:.15rem;display:flex;gap:.6rem;flex-wrap:wrap;align-items:center}
.ux-dh .id code{font-size:.76rem;cursor:copy;padding:.05rem .35rem}
.ux-dh .x{margin-left:auto}
.ux-dh .r2{display:flex;gap:.45rem;flex-wrap:wrap;align-items:center}
.ux-tabs{display:flex;gap:.2rem;padding:0 1.2rem;border-bottom:1px solid var(--line)}
.ux-dr .ux-tabs button{all:unset;cursor:pointer;padding:.65rem .75rem;font-size:.86rem;font-weight:600;color:var(--muted);border-bottom:2px solid transparent;margin-bottom:-1px;display:inline-flex;gap:.4rem;align-items:center}
.ux-dr .ux-tabs button:hover{color:var(--text-strong)}
.ux-dr .ux-tabs button.on{color:var(--accent-text);border-bottom-color:var(--accent)}
.ux-dr .ux-tabs button:disabled{opacity:.45;cursor:not-allowed}
.ux-tabs .n{font-size:.7rem;background:var(--hover);color:var(--muted);border-radius:999px;padding:.02rem .4rem;font-weight:700}
.ux-db{flex:1;overflow:auto;padding:1rem 1.2rem 1.4rem}
.ux-df{border-top:1px solid var(--line);padding:.75rem 1.2rem;display:flex;align-items:center;gap:.6rem;background:var(--card);border-radius:0 0 0 var(--radius)}
.ux-df .sp{flex:1}
.ux-df .chg{font-size:.82rem;color:var(--muted)}
.ux-df .chg b{color:var(--text-strong)}
.ux-dk{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:.6rem}
.ux-dk>div{border:1px solid var(--line);background:var(--bg2);border-radius:12px;padding:.65rem .8rem;min-width:0}
.ux-dk .k{font-size:.7rem;color:var(--muted);text-transform:uppercase;letter-spacing:.04em}
.ux-dk .v{font-size:1.08rem;font-weight:700;color:var(--text-strong);font-variant-numeric:tabular-nums;margin-top:.1rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ux-dk .d{font-size:.74rem;color:var(--muted);margin-top:.1rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ux-sect{font-size:.72rem;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);font-weight:700;margin:1.2rem 0 .55rem;display:flex;align-items:center;gap:.6rem}
.ux-sect:first-child{margin-top:0}
.ux-sect .sp{flex:1;height:1px;background:var(--line)}
.ux-dl{display:grid;grid-template-columns:minmax(9rem,auto) minmax(0,1fr);gap:.45rem 1rem;font-size:.86rem;align-items:baseline}
.ux-dl dt{color:var(--muted)}
.ux-dl dd{margin:0;color:var(--text);min-width:0;overflow-wrap:anywhere;display:flex;align-items:center;gap:.4rem;flex-wrap:wrap}
.ux-dl dd .m{color:var(--muted)}
.ux-mw{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.6rem}
.ux-mw>div{border:1px solid var(--line);border-radius:10px;padding:.6rem .75rem;display:flex;gap:.6rem;align-items:flex-start;min-width:0}
.ux-mw .ic{width:30px;height:30px;border-radius:8px;background:var(--bg2);display:flex;align-items:center;justify-content:center;color:var(--muted);flex:none}
.ux-mw .ic svg{width:16px;height:16px}
.ux-mw .tx{min-width:0;flex:1}
.ux-mw .tt{font-size:.8rem;color:var(--muted)}
.ux-mw .tv{font-size:.86rem;color:var(--text-strong);font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.ux-mw .ta{margin-top:.35rem}
.ux-gb{border:1px solid var(--c-violet-fg);background:var(--c-violet-bg);border-radius:12px;padding:.8rem .9rem;margin-bottom:1rem}
.ux-gb .h{display:flex;gap:.6rem;align-items:center;color:var(--text-strong);font-weight:700;font-size:.92rem}
.ux-gb .h svg{width:18px;height:18px;color:var(--c-violet-fg);flex:none}
.ux-gb p{margin:.35rem 0 0;font-size:.84rem;color:var(--text)}
.ux-gb .acts{display:flex;gap:.5rem;margin-top:.65rem;flex-wrap:wrap}
.ux-rt{display:grid;grid-template-columns:max-content minmax(0,max-content) max-content minmax(0,1fr);gap:.3rem .6rem;font-size:.82rem;margin-top:.6rem;align-items:center}
.ux-rt .k{color:var(--muted)}
.ux-rt .a{color:var(--muted);text-decoration:line-through;text-decoration-color:var(--muted)}
.ux-rt .b{color:var(--text-strong);font-weight:600}
.ux-rt .ar{color:var(--c-violet-fg)}
.ux-fs{border:1px solid var(--line);border-radius:12px;padding:.85rem .95rem;margin-bottom:.8rem}
.ux-fs>.t{font-weight:700;color:var(--text-strong);font-size:.9rem;margin-bottom:.7rem;display:flex;align-items:center;gap:.5rem}
.ux-fs>.t svg{width:16px;height:16px;color:var(--muted)}
.ux-fs>.t .sp{flex:1}
.ux-fs .hint2{font-size:.78rem;color:var(--muted);margin-top:.3rem}
.ux-g2{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.75rem}
.ux-f{display:flex;flex-direction:column;gap:.3rem;min-width:0}
.ux-f>label{font-size:.8rem;color:var(--muted);font-weight:600;display:flex;align-items:center;gap:.4rem;margin:0}
.ux-dr input[type=text],.ux-dr input[type=number],.ux-dr input[type=email],.ux-dr input[type=datetime-local],.ux-dr select,.ux-dr textarea{width:100%;margin:0;height:38px;min-height:38px;font-size:.88rem}
.ux-dr textarea{height:auto;min-height:64px;resize:vertical}
.ux-dr input.mono{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:.82rem}
.ux-dr input.chg,.ux-dr select.chg,.ux-dr textarea.chg{border-color:var(--accent);box-shadow:0 0 0 1px var(--accent) inset}
.ux-dr input.err{border-color:var(--red);box-shadow:0 0 0 1px var(--red) inset}
.ux-err{font-size:.78rem;color:var(--c-bad-fg)}
.ux-err:empty{display:none}
.ux-pre{display:flex;gap:.35rem;flex-wrap:wrap;margin-top:.45rem}
.ux-dr .ux-pre button{all:unset;cursor:pointer;font-size:.76rem;font-weight:600;padding:.22rem .55rem;border-radius:999px;border:1px solid var(--line);background:var(--bg2);color:var(--text)}
.ux-dr .ux-pre button:hover{border-color:var(--accent);color:var(--accent-text)}
.ux-will{font-size:.8rem;color:var(--muted);margin-top:.35rem}
.ux-will b{color:var(--text-strong)}
.ux-inl{display:flex;gap:.45rem;align-items:center}
.ux-inl>input{flex:1}
.ux-inl>select{width:auto;flex:none}
.ux-was{font-size:.68rem;font-weight:700;padding:.04rem .4rem;border-radius:999px;background:var(--c-violet-bg);color:var(--c-violet-fg);text-transform:none;letter-spacing:0}
.ux-sqg{display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:.45rem}
.ux-dr .ux-sqi{display:flex;align-items:center;gap:.55rem;border:1px solid var(--line);padding:.5rem .65rem;border-radius:9px;background:var(--bg2);cursor:pointer;margin:0;font-size:.85rem;color:var(--text);min-width:0}
.ux-sqi.on{border-color:var(--accent);background:var(--accent-light)}
.ux-sqi .nm{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-weight:600;color:var(--text-strong)}
.ux-sqi .c{font-size:.72rem;color:var(--muted)}
.ux-sqi.gr .nm::after{content:"грейс";margin-left:.35rem;font-size:.64rem;font-weight:700;padding:.02rem .35rem;border-radius:999px;background:var(--c-violet-bg);color:var(--c-violet-fg);vertical-align:1px}
.ux-sw{display:flex;align-items:center;justify-content:space-between;gap:1rem;font-size:.86rem}
.ux-sw .d{font-size:.78rem;color:var(--muted);margin-top:.1rem}
.ux-opt{display:flex;gap:.6rem;align-items:flex-start;font-size:.86rem;cursor:pointer;margin:0}
.ux-opt .ux-cbx{margin-top:.15rem;flex:none}
.ux-opt b{color:var(--text-strong)}
.ux-opt .d{display:block;font-size:.78rem;color:var(--muted);margin-top:.1rem;font-weight:400}
.ux-more{border:1px dashed var(--line);border-radius:12px;padding:.1rem .95rem;margin-bottom:.8rem}
.ux-more summary{cursor:pointer;font-size:.86rem;font-weight:700;color:var(--text);padding:.65rem 0;list-style:none;display:flex;gap:.5rem;align-items:center}
.ux-more summary::-webkit-details-marker{display:none}
.ux-more summary::before{content:'';width:.4rem;height:.4rem;border-right:2px solid var(--muted);border-bottom:2px solid var(--muted);transform:rotate(-45deg);margin-right:.15rem;transition:transform .15s}
.ux-more[open] summary::before{transform:rotate(45deg)}
.ux-more[open]{padding-bottom:.9rem}
.ux-more summary .m{font-weight:400;color:var(--muted);font-size:.8rem}
.ux-dev{display:flex;align-items:center;justify-content:space-between;gap:1rem;padding:.7rem .8rem;border:1px solid var(--line);border-radius:12px;background:var(--bg2);margin-bottom:.55rem}
.ux-dev .l{display:flex;gap:.75rem;align-items:center;min-width:0}
.ux-dev .os{width:34px;height:34px;border-radius:9px;background:var(--card);border:1px solid var(--line);display:flex;align-items:center;justify-content:center;color:var(--muted);flex:none}
.ux-dev .os svg{width:17px;height:17px}
.ux-dev .m1{color:var(--text-strong);font-weight:600;font-size:.9rem;display:flex;gap:.4rem;align-items:center}
.ux-dev .m2{color:var(--muted);font-size:.78rem}
.ux-dev .m3{color:var(--muted);font-size:.74rem;font-family:ui-monospace,monospace;overflow-wrap:anywhere}
.ux-dev .a{display:flex;gap:.4rem;flex:none}
.ux-tl{display:flex;flex-direction:column}
.ux-tl>div{display:grid;grid-template-columns:7.5rem 1.6rem minmax(0,1fr);gap:.2rem .5rem;padding:.5rem 0;border-top:1px solid var(--line);font-size:.84rem;align-items:start}
.ux-tl>div:first-child{border-top:0}
.ux-tl .tm{color:var(--muted);font-variant-numeric:tabular-nums;font-size:.8rem;padding-top:.1rem}
.ux-tl .ic{width:22px;height:22px;border-radius:6px;display:flex;align-items:center;justify-content:center}
.ux-tl .ic svg{width:13px;height:13px}
.ux-tl .tx b{color:var(--text-strong);font-weight:600}
.ux-tl .tx .s{display:block;color:var(--muted);font-size:.78rem}
.ux-ok{display:flex;flex-direction:column;align-items:center;text-align:center;gap:.7rem;padding:1.5rem .5rem}
.ux-ok .ic{width:52px;height:52px;border-radius:14px;background:var(--c-ok-bg);color:var(--c-ok-fg);display:flex;align-items:center;justify-content:center}
.ux-ok .ic svg{width:26px;height:26px}
.ux-ok h3{margin:0;color:var(--text-strong);font-size:1.1rem}
.ux-ok .lnk{font-family:ui-monospace,monospace;font-size:.82rem;background:var(--bg2);border:1px solid var(--line);border-radius:8px;padding:.5rem .7rem;max-width:100%;overflow-wrap:anywhere;cursor:copy}
.ux-qr{display:flex;flex-direction:column;align-items:center;gap:.8rem}
.ux-qr .box{background:var(--qr-bg);padding:12px;border-radius:12px;line-height:0}
.ux-qr .box svg{width:240px;height:240px;display:block}
.ux-qr code{font-size:.78rem;overflow-wrap:anywhere;text-align:center}
@media(max-width:1600px){.ux-tbl .c-sq{display:none}}
@media(max-width:1280px){.ux-kpis{grid-template-columns:repeat(3,minmax(0,1fr))}.ux-tbl .c-on{display:none}}
@media(min-width:1101px) and (max-width:1180px){.ux-tr{min-width:6.5rem}.ux-tr .t{flex-direction:column;gap:0}.u-sub{max-width:11rem}}
@media(max-width:900px){.ux-dk{grid-template-columns:repeat(2,minmax(0,1fr))}.ux-mw{grid-template-columns:minmax(0,1fr)}}
@media(max-width:1100px){
.ux-wrap{border:0;overflow:visible}
.ux-tbl,.ux-tbl tbody{display:block}
.ux-tbl thead{display:none}
.ux-tbl tbody tr{display:grid;grid-template-columns:auto minmax(0,1fr) auto;gap:.35rem .6rem;border:1px solid var(--line);border-radius:12px;background:var(--bg2);padding:.75rem .8rem;margin-bottom:.6rem;align-items:center}
.ux-tbl tbody tr.sel{border-color:var(--accent)}
.ux-tbl tbody tr:hover td,.ux-tbl tbody tr.sel td{background:transparent}
.ux-tbl td{display:block;padding:0;height:auto;box-shadow:none}
.ux-tbl td.cb{grid-row:1;grid-column:1}
.ux-tbl td.c-nm{grid-row:1;grid-column:2}
.ux-tbl td.acts{grid-row:1;grid-column:3}
.ux-tbl td.c-st,.ux-tbl td.c-tr,.ux-tbl td.c-ex,.ux-tbl td.c-dv,.ux-tbl td.c-on,.ux-tbl td.c-sq{grid-column:2/4;display:flex;justify-content:space-between;align-items:center;gap:1rem}
.ux-tbl td.c-on,.ux-tbl td.c-sq{display:flex}
.ux-tbl td[data-l]::before{content:attr(data-l);color:var(--muted);font-size:.78rem}
.ux-tbl .acts .ux-ib.q{opacity:1}
.ux-tr{min-width:0;width:60%}
}
@media(max-width:820px){
.ux-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}
.ux-flt input#uxQ{flex:1 1 100%;width:100%}
.ux-g2{grid-template-columns:minmax(0,1fr)}
.ux-dl{grid-template-columns:minmax(0,1fr)}
.ux-dl dt{margin-top:.35rem}
.ux-rt{grid-template-columns:auto minmax(0,1fr)}
.ux-rt .ar{display:none}
.ux-dr{border-radius:0}
}
@media(max-width:520px){.ux-dk{grid-template-columns:minmax(0,1fr) minmax(0,1fr)}}

.ux-dr input[type=email],.ux-dr input[type=datetime-local]{padding:.6rem .7rem;background:var(--bg2);border:1px solid var(--line);color:var(--text);font-family:inherit;border-radius:var(--radius);transition:border-color .16s,box-shadow .16s}
.ux-dr input[type=email]:focus,.ux-dr input[type=datetime-local]:focus{border-color:var(--accent);box-shadow:var(--focus);outline:none}
html[data-theme=dark] .ux-dr input[type=datetime-local],html[data-theme=black] .ux-dr input[type=datetime-local]{color-scheme:dark}
.ux-sqs{flex-wrap:nowrap}
.ux input[type=radio]{accent-color:var(--accent)}

.modal.ux-cf{max-width:540px;overflow:hidden}
.ux-cfh{display:flex;gap:.8rem;align-items:center;padding:1rem 1.15rem;border-bottom:1px solid var(--line)}
.ux-cfh .modal-x{margin-left:auto;align-self:flex-start}
.ux-cfi{width:40px;height:40px;border-radius:11px;display:flex;align-items:center;justify-content:center;flex:none}
.ux-cfi svg{width:20px;height:20px}
.tn-acc{background:var(--accent-light);color:var(--accent-text)}
.tn-bad{background:var(--c-bad-bg);color:var(--c-bad-fg)}
.tn-vio{background:var(--c-violet-bg);color:var(--c-violet-fg)}
.tn-warn{background:var(--c-warn-bg);color:var(--c-warn-fg)}
.tn-info{background:var(--c-info-bg);color:var(--c-info-fg)}
.tn-ok{background:var(--c-ok-bg);color:var(--c-ok-fg)}
.ux-cfh .t{font-weight:700;color:var(--text-strong);font-size:1rem;line-height:1.3}
.ux-cfh .s{font-size:.8rem;color:var(--muted);margin-top:.1rem;display:flex;gap:.4rem;align-items:center;flex-wrap:wrap}
.ux-cfh .s b{color:var(--text);font-weight:600}
.ux-cfb{padding:.35rem 1.15rem 1rem;overflow:auto}
.ux-cfb:empty{display:none}
.ux-cfr{display:grid;grid-template-columns:30px minmax(6.5rem,max-content) minmax(0,1fr);gap:.7rem;align-items:center;padding:.5rem 0;border-top:1px solid var(--line)}
.ux-cfr:first-child{border-top:0}
.ux-cfr .i{width:30px;height:30px;border-radius:8px;background:var(--bg2);border:1px solid var(--line);color:var(--muted);display:flex;align-items:center;justify-content:center}
.ux-cfr .i svg{width:15px;height:15px}
.ux-cfr .l{font-size:.82rem;color:var(--muted)}
.ux-cfr .v{display:flex;align-items:center;gap:.45rem;flex-wrap:wrap;min-width:0;font-size:.87rem;justify-content:flex-end;text-align:right}
.ux-cfr .o{color:var(--muted);text-decoration:line-through;text-decoration-color:var(--muted)}
.ux-cfr .o.ch{text-decoration:none;opacity:.55;display:inline-flex;gap:.25rem;flex-wrap:wrap}
.ux-cfr .ar{color:var(--muted);display:inline-flex}
.ux-cfr .ar svg{width:14px;height:14px}
.ux-cfr .n{color:var(--text-strong);font-weight:600;display:inline-flex;gap:.25rem;flex-wrap:wrap;justify-content:flex-end}
.ux-cfr .n .ux-sq{border-color:var(--accent);background:var(--accent-light);color:var(--accent-text)}
.ux-cfm{font-size:.8rem;color:var(--muted);padding:.3rem 0 0 2.6rem}
.ux-cfn{display:flex;gap:.65rem;align-items:center;padding:.6rem .75rem;border-radius:10px;margin-top:.55rem;font-size:.84rem}
.ux-cfn:first-child{margin-top:.65rem}
.ux-cfn svg{width:17px;height:17px;flex:none}
.ux-cfn b{font-weight:700}
.ux-cfn span{color:var(--text);opacity:.85}
.ux-cff{display:flex;align-items:center;gap:.6rem;padding:.8rem 1.15rem;border-top:1px solid var(--line);background:var(--bg2)}
.ux-cff .sp{flex:1}
.ux-cff .h{font-size:.76rem;color:var(--muted);display:flex;gap:.35rem;align-items:center}
.ux-cff .h svg{width:14px;height:14px;flex:none}
.ux-cff .btn{white-space:nowrap}
@media(max-width:560px){.ux-cfr{grid-template-columns:30px minmax(0,1fr)}.ux-cfr .v{grid-column:2;justify-content:flex-start;text-align:left}.ux-cff{flex-wrap:wrap}.ux-cff .h{flex:1 1 100%}}
.ux-tbl thead th:first-child{border-top-left-radius:12px}
.ux-tbl thead th:last-child{border-top-right-radius:12px}
.ux-load{color:var(--muted);font-size:.86rem;padding:1rem 0}
.ux-warn{background:var(--c-bad-bg);color:var(--c-bad-fg);border-radius:10px;padding:.6rem .8rem;font-size:.85rem;margin-bottom:.8rem}
.ux-dr .ux-tag{font-size:.7rem}
.ux-dv{white-space:nowrap}
    </style>
<div class="ux" id="ux">
    <?php if ($users_err): ?>
    <div class="warn">API недоступен: <?= h($users_err) ?>. Проверьте URL панели и токен во вкладке «Подключение».</div>
    <?php endif; ?>
    <div class="card">
        <div class="ux-kpis" id="uxKpis"></div>
    </div>

    <div class="card">
        <div class="ux-h">
            <h2>Пользователи панели<span class="ux-cnt" id="uxCnt"></span></h2>
            <div class="ux-acts">
                <a class="wb" href="?tab=users" id="uxRefresh"><svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a9 9 0 1 1-3-6.7L21 8"/><path d="M21 3v5h-5"/></svg>Обновить</a>
                <button type="button" class="qh" onclick="help('userflags')" aria-label="Справка по вкладке">?</button>
                <button type="button" class="wb pri" id="uxCreate" data-w="users:create"><svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>Создать пользователя</button>
            </div>
        </div>
        <div class="ux-flt">
            <input type="text" id="uxQ" role="searchbox" placeholder="Имя, shortUuid, описание, Telegram, email" autocomplete="off" spellcheck="false">
            <select id="uxSq" aria-label="Сквад"><option value="">Все сквады</option></select>
            <select id="uxTag" aria-label="Тег" hidden><option value="">Все теги</option></select>
            <span id="uxChips"></span>
            <span class="sp"></span>
            <div class="ux-seg" data-tip="Плотность строк">
                <button type="button" class="on" data-dens="0" aria-label="Обычная плотность"><svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg></button>
                <button type="button" data-dens="1" aria-label="Компактная плотность"><svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 5h16M4 9.7h16M4 14.3h16M4 19h16"/></svg></button>
            </div>
        </div>
        <div class="ux-bulk" id="uxBulk" hidden>
            <b id="uxBulkN"></b>
            <button type="button" class="wb sm" data-bulk="extend" data-w="users:update">Продлить…</button>
            <button type="button" class="wb sm" data-bulk="reset" data-w="users:reset-traffic">Сбросить трафик</button>
            <button type="button" class="wb sm" data-bulk="squads" data-w="users:update">Сквады…</button>
            <button type="button" class="wb sm" data-bulk="disable" data-w="users:disable">Выключить</button>
            <button type="button" class="wb sm" data-bulk="enable" data-w="users:enable">Включить</button>
            <span class="sp"></span>
            <button type="button" class="wb sm" data-bulk="clear">Снять выбор</button>
        </div>
        <div class="ux-wrap">
            <table class="ux-tbl" id="uxTbl">
                <thead><tr>
                    <th class="cb"><input type="checkbox" class="ux-cbx" id="uxAll" aria-label="Выбрать всех на странице"></th>
                    <th class="srt" data-s="name">Пользователь<span class="sar"></span></th>
                    <th class="srt" data-s="st">Статус<span class="sar"></span></th>
                    <th class="srt" data-s="tr">Трафик<span class="sar"></span></th>
                    <th class="srt" data-s="exp">Истекает<span class="sar"></span></th>
                    <th class="c-sq">Сквады</th>
                    <th class="srt c-on" data-s="on">Онлайн<span class="sar"></span></th>
                    <th class="acts"></th>
                </tr></thead>
                <tbody id="uxBody" class="lp-cap"></tbody>
            </table>
        </div>
        <div class="ux-pgr" id="uxPgr"></div>
    </div>

    <div class="ux-menu" id="uxMenu" role="menu"></div>

    <div class="ux-ov" id="uxOv">
        <aside class="ux-dr" id="uxDr" role="dialog" aria-modal="true" aria-label="Пользователь"></aside>
    </div>

    <div class="modal-overlay" id="uxQrOv">
        <div class="modal" style="max-width:380px">
            <div class="modal-head"><span id="uxQrT">QR-код подписки</span><button type="button" class="modal-x" data-close="qr" aria-label="Закрыть">×</button></div>
            <div class="modal-body"><div class="ux-qr"><div class="box" id="uxQrBox"></div><code id="uxQrL"></code><button type="button" class="btn" id="uxQrCopy">Скопировать ссылку</button></div></div>
        </div>
    </div>

    <div class="modal-overlay" id="uxPickOv">
        <div class="modal" style="max-width:520px">
            <div class="modal-head"><span id="uxPickT"></span><button type="button" class="modal-x" data-close="pick" aria-label="Закрыть">×</button></div>
            <div class="modal-body" id="uxPickB"></div>
        </div>
    </div>

    <div class="modal-overlay" id="uxCfOv">
        <div class="modal ux-cf" role="alertdialog" aria-modal="true" aria-labelledby="uxCfT">
            <div class="ux-cfh"><span class="ux-cfi" id="uxCfI"></span><div style="min-width:0"><div class="t" id="uxCfT"></div><div class="s" id="uxCfS"></div></div><button type="button" class="modal-x" data-close="cf" aria-label="Закрыть">×</button></div>
            <div class="ux-cfb" id="uxCfB"></div>
            <div class="ux-cff"><span class="h" id="uxCfH"></span><span class="sp"></span><button type="button" class="btn ghost" data-close="cf">Отмена</button><button type="button" class="btn" id="uxCfOk"></button></div>
        </div>
    </div>
</div>
    <script>
    window.UX_CFG = <?= json_encode($ux_cfg, $__jf) ?>;
    window.UX_USERS = <?= json_encode($ux_list, $__jf) ?>;
    </script>
    <script>
(function(){
document.documentElement.classList.remove('lp');
var C=window.UX_CFG,U=window.UX_USERS||[];
var GSQ=C.gsq||'',DAY=86400000,GB=1073741824;
var S={f:'all',q:'',sq:'',tag:'',sort:'exp',dir:1,page:1,size:50,sel:{},dens:0};
try{var v0=parseInt(localStorage.getItem('utbl_size'),10);if([10,25,50,0].indexOf(v0)>-1)S.size=v0;}catch(e){}
try{if(localStorage.getItem('utbl_dens')==='1')S.dens=1;}catch(e){}
var STRAT=[['NO_RESET','Без сброса'],['DAY','Каждый день'],['WEEK','Каждую неделю'],['MONTH','Каждый месяц'],['MONTH_ROLLING','Ежемесячно от даты создания']];
var STRAT_S={NO_RESET:'',DAY:'день',WEEK:'неделя',MONTH:'мес',MONTH_ROLLING:'мес ↻'};
var SQN={};U.forEach(function(u){u.sq.forEach(function(id,i){if(u.sqn[i])SQN[id]=u.sqn[i];});});
var META=null,METAP=null;
function $(id){return document.getElementById(id);}
function esc(s){return String(s==null?'':s).replace(/[&<>"']/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];});}
var P={
copy:'<rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>',
qr:'<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><path d="M14 14h3v3h-3zM20 14v.01M14 20h.01M17 17h4v4h-4"/>',
more:'<circle cx="12" cy="5" r="1.4"/><circle cx="12" cy="12" r="1.4"/><circle cx="12" cy="19" r="1.4"/>',
user:'<circle cx="12" cy="8" r="4"/><path d="M4 21v-1a6 6 0 0 1 6-6h4a6 6 0 0 1 6 6v1"/>',
edit:'<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>',
cal:'<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
calp:'<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18M12 14v4M10 16h4"/>',
reset:'<path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/>',
power:'<path d="M12 2v10"/><path d="M18.4 6.6a9 9 0 1 1-12.8 0"/>',
dev:'<rect x="5" y="2" width="14" height="20" rx="2"/><path d="M9 18h6"/>',
plus:'<path d="M12 5v14M5 12h14"/>',
eye:'<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/>',
eyeoff:'<path d="M9.9 4.2A9.1 9.1 0 0 1 12 4c6.5 0 10 7 10 7a13 13 0 0 1-2.2 3M6.6 6.6A13 13 0 0 0 2 11s3.5 7 10 7a9 9 0 0 0 4.5-1.2"/><path d="M2 2l20 20"/>',
key:'<circle cx="7.5" cy="15.5" r="4.5"/><path d="M10.7 12.3 21 2M16 7l3 3M14 9l2 2"/>',
trash:'<path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>',
x:'<path d="M18 6 6 18M6 6l12 12"/>',
shield:'<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
link:'<path d="M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.7 1.7"/><path d="M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.7-1.7"/>',
net:'<rect x="9" y="2" width="6" height="6" rx="1"/><rect x="2" y="16" width="6" height="6" rx="1"/><rect x="16" y="16" width="6" height="6" rx="1"/><path d="M12 8v4M5 16v-2h14v2"/>',
log:'<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M8 13h8M8 17h5"/>',
send:'<path d="m22 2-7 20-4-9-9-4z"/><path d="M22 2 11 13"/>',
hour:'<path d="M5 22h14M5 2h14M17 22v-4.2a2 2 0 0 0-.6-1.4L12 12l-4.4 4.4a2 2 0 0 0-.6 1.4V22M7 2v4.2a2 2 0 0 0 .6 1.4L12 12l4.4-4.4a2 2 0 0 0 .6-1.4V2"/>',
back:'<path d="M9 14 4 9l5-5"/><path d="M4 9h11a5 5 0 0 1 0 10h-1"/>',
check:'<path d="M20 6 9 17l-5-5"/>',
ban:'<circle cx="12" cy="12" r="10"/><path d="m4.9 4.9 14.2 14.2"/>',
refresh:'<path d="M21 12a9 9 0 1 1-3-6.7L21 8"/><path d="M21 3v5h-5"/>',
wave:'<path d="M2 12h3l3-8 4 16 3-8h7"/>',
arr:'<path d="M5 12h14M13 6l6 6-6 6"/>',
mail:'<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 6L2 7"/>',
info:'<circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/>',
alert:'<path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><path d="M12 9v4M12 17h.01"/>',
save:'<path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><path d="M17 21v-8H7v8M7 3v5h8"/>',
tag:'<path d="M20.6 13.4 13.4 20.6a2 2 0 0 1-2.8 0L2 12V2h10l8.6 8.6a2 2 0 0 1 0 2.8z"/><circle cx="7" cy="7" r="1.5"/>'
};
function ico(n,w){return '<svg viewBox="0 0 24 24" width="'+(w||16)+'" height="'+(w||16)+'" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'+(P[n]||'')+'</svg>';}
function now(){return Date.now();}
function p2(n){return(n<10?'0':'')+n;}
function fdt(ts){var d=new Date(ts);return d.getFullYear()+'-'+p2(d.getMonth()+1)+'-'+p2(d.getDate())+' в '+p2(d.getHours())+':'+p2(d.getMinutes());}
function fd(ts){var d=new Date(ts);return d.getFullYear()+'-'+p2(d.getMonth()+1)+'-'+p2(d.getDate());}
function dlv(ts){var d=new Date(ts);return d.getFullYear()+'-'+p2(d.getMonth()+1)+'-'+p2(d.getDate())+'T'+p2(d.getHours())+':'+p2(d.getMinutes());}
function plural(n,a,b,c){n=Math.abs(n)%100;var m=n%10;if(n>10&&n<20)return c;if(m>1&&m<5)return b;if(m===1)return a;return c;}
function dd(n){return n+' '+plural(n,'день','дня','дней');}
function rel(ts){var df=ts-now(),a=Math.abs(df),s;if(a<3600000)s=Math.max(1,Math.round(a/60000))+' мин';else if(a<DAY)s=Math.round(a/3600000)+' ч';else s=dd(Math.round(a/DAY));return df>=0?'через '+s:s+' назад';}
function ago(ts){var a=now()-ts;if(a<120000)return 'только что';if(a<3600000)return Math.round(a/60000)+' мин назад';if(a<DAY)return Math.round(a/3600000)+' ч назад';return dd(Math.round(a/DAY))+' назад';}
function fb(b){b=+b||0;if(b>=1024*GB)return(b/1024/GB).toFixed(b>=10*1024*GB?0:1).replace('.',',')+' ТБ';if(b>=GB)return(b/GB).toFixed(b>=100*GB?0:1).replace('.',',')+' ГБ';if(b>=1048576)return Math.round(b/1048576)+' МБ';return b>0?Math.round(b/1024)+' КБ':'0 Б';}
function gbv(b){return Math.round(b/GB*100)/100;}
function expMs(u){return u.exp*1000;}
function gInfo(u){return C.gr[u.su]||null;}
function isGr(u){return !!gInfo(u)&&u.st==='ACTIVE';}
function sqName(id){if(META)for(var i=0;i<META.sq.length;i++)if(META.sq[i].uuid===id)return META.sq[i].name;return SQN[id]||(id?id.slice(0,8):'');}
function exName(id){if(!id)return '';if(META)for(var i=0;i<META.ex.length;i++)if(META.ex[i].uuid===id)return META.ex[i].name;return id.slice(0,8);}
function nodeName(id){return META&&META.nodes&&META.nodes[id]||'';}
function soon(u){var e=expMs(u);return u.st==='ACTIVE'&&!isGr(u)&&e>now()&&e-now()<=7*DAY;}
function stInfo(u){if(isGr(u))return{c:'st-vio',t:'Грейс'};return({ACTIVE:{c:'st-ok',t:'Активен'},EXPIRED:{c:'st-bad',t:'Истёк'},DISABLED:{c:'st-mut',t:'Выключен'},LIMITED:{c:'st-warn',t:'Лимит'}})[u.st]||{c:'st-mut',t:u.st||'—'};}
function pill(u){var s=stInfo(u);return '<span class="ux-pill '+s.c+'"'+(u.st==='LIMITED'&&!isGr(u)?' data-tip="Трафик закончился"':'')+'><i></i>'+s.t+'</span>';}
function hwTxt(v){return v===null||v===undefined?'как в панели':(v===0?'без лимита':String(v));}
function hwSrc(u){if(!META||!META.hwid)return null;var s=META.hwid,from='panel';if(u.ex)for(var i=0;i<META.ex.length;i++)if(META.ex[i].uuid===u.ex&&META.ex[i].hwid){s=META.ex[i].hwid;from='ex';}return{s:s,from:from};}
function hwEff(u){var h=hwSrc(u);if(u.hw===0)return{lim:null,txt:'без лимита',d:'без лимита'};if(!h){return u.hw?{lim:u.hw,txt:String(u.hw),d:'свой'}:{lim:null,txt:'как в панели',d:'как в панели'};}if(!h.s.enabled)return{lim:null,txt:'выключен в панели',d:'выключен в панели'};if(u.hw)return{lim:u.hw,txt:String(u.hw),d:'свой'};var w=h.from==='ex'?'из внешнего сквада':'общий из панели';return{lim:h.s.limit,txt:h.s.limit+' — '+w,d:w};}
function bySu(su){for(var i=0;i<U.length;i++)if(U[i].su===su)return U[i];return null;}
function canW(sc){return !sc||C.ts[sc]!=='miss';}
function roTip(sc){return 'Нет права '+sc+' у API-токена';}
function toast(m){if(window.uiToast)uiToast(m);}
function copy(v,m){if(!v)return;var done=function(){toast(m||'Скопировано');};try{navigator.clipboard.writeText(v).then(done,function(){fb2(v);done();});}catch(e){fb2(v);done();}}
function fb2(v){try{var t=document.createElement('textarea');t.value=v;t.style.position='fixed';t.style.opacity='0';document.body.appendChild(t);t.select();document.execCommand('copy');t.remove();}catch(e){}}
function post(a,data){var f=new FormData();f.append('csrf',C.csrf);Object.keys(data||{}).forEach(function(k){f.append(k,data[k]);});return fetch('?ajax='+a,{method:'POST',body:f,credentials:'same-origin'}).then(function(r){return r.json();}).catch(function(){return{ok:false,error:'Сетевая ошибка'};});}
function getj(a,q){return fetch('?ajax='+a+(q||''),{credentials:'same-origin',cache:'no-store'}).then(function(r){return r.json();}).catch(function(){return{ok:false,error:'Сетевая ошибка'};});}
function loadMeta(){if(META)return Promise.resolve(META);if(!METAP)METAP=getj('ua_meta').then(function(d){if(d&&d.ok){META={sq:d.sq||[],ex:d.ex||[],nodes:d.nodes||{},hwid:d.hwid||null};META.sq.forEach(function(s){SQN[s.uuid]=s.name;});}else{METAP=null;}return META;});return METAP;}
function applyMw(su,mw){if(!mw)return;if(mw.grace)C.gr[su]=mw.grace;else delete C.gr[su];if(mw.addsub)C.as[su]=mw.addsub;else delete C.as[su];if(mw.nolog)C.nl[su]=1;else delete C.nl[su];var b=(mw.ov||[]).some(function(o){return o.t==='shortuuid'&&o.r==='blocked';});if(b)C.ovb[su]=1;else delete C.ovb[su];}
function putUser(nu,mw,oldSu){var i=-1;for(var k=0;k<U.length;k++)if(U[k].su===(oldSu||nu.su)){i=k;break;}if(i>-1)U[i]=nu;else U.unshift(nu);nu.sq.forEach(function(id,j){if(nu.sqn[j])SQN[id]=nu.sqn[j];});applyMw(nu.su,mw);if(CARD&&!CARD.create&&CARD.u.su===(oldSu||nu.su)){CARD.u=nu;if(mw)CARD.mw=mw;}return nu;}

var FLT={
 all:{k:'Всего',dot:'',fn:function(){return true;}},
 active:{k:'Активны',dot:'var(--c-ok-fg)',fn:function(u){return u.st==='ACTIVE'&&!isGr(u);}},
 soon:{k:'Истекают ≤ 7 дн.',dot:'var(--c-warn-fg)',fn:soon},
 grace:{k:'В грейсе',dot:'var(--c-violet-fg)',fn:isGr},
 expired:{k:'Истекли',dot:'var(--c-bad-fg)',fn:function(u){return u.st==='EXPIRED';}},
 off:{k:'Выкл. и лимит',dot:'var(--muted)',fn:function(u){return u.st==='DISABLED'||u.st==='LIMITED';}}
};
function renderKpis(){
 var n={},on24=0;
 Object.keys(FLT).forEach(function(k){n[k]=U.filter(FLT[k].fn).length;});
 U.forEach(function(u){if(u.on&&now()-u.on*1000<DAY)on24++;});
 var dis=U.filter(function(u){return u.st==='DISABLED';}).length,lim=U.filter(function(u){return u.st==='LIMITED';}).length;
 var d={all:'онлайн за сутки — '+on24,active:'подписка работает',soon:'пора продлить',grace:'тариф запомнен',expired:'без грейса',off:dis+' выключ. · '+lim+' лимит'};
 $('uxKpis').innerHTML=Object.keys(FLT).map(function(k){var f=FLT[k];return '<button type="button" class="ux-kpi'+(S.f===k?' on':'')+'" data-f="'+k+'"><span class="k">'+(f.dot?'<i style="background:'+f.dot+'"></i>':'')+f.k+'</span><span class="v">'+n[k]+'</span><span class="d">'+d[k]+'</span></button>';}).join('');
}
function fillSelects(){
 var sq=$('uxSq'),tg=$('uxTag'),tags={},ids={};
 U.forEach(function(u){if(u.tag)tags[u.tag]=1;u.sq.forEach(function(id){ids[id]=1;});});
 if(META)META.sq.forEach(function(s){ids[s.uuid]=1;});
 var l=Object.keys(ids).sort(function(a,b){return sqName(a).localeCompare(sqName(b));});
 sq.innerHTML='<option value="">Все сквады</option>'+l.map(function(id){return '<option value="'+esc(id)+'">'+esc(sqName(id))+'</option>';}).join('');
 var tl=Object.keys(tags).sort();
 tg.hidden=!tl.length;
 tg.innerHTML='<option value="">Все теги</option>'+tl.map(function(t){return '<option>'+esc(t)+'</option>';}).join('')+'<option value="-">Без тега</option>';
 sq.value=S.sq;tg.value=S.tag;
}
function matches(u){
 if(!FLT[S.f].fn(u))return false;
 if(S.sq&&u.sq.indexOf(S.sq)<0)return false;
 if(S.tag==='-'&&u.tag)return false;
 if(S.tag&&S.tag!=='-'&&u.tag!==S.tag)return false;
 if(S.q){var h=[u.un,u.su,u.desc,u.tg,u.em,u.tag].join(' ').toLowerCase();if(h.indexOf(S.q)<0)return false;}
 return true;
}
var STO={ACTIVE:1,LIMITED:2,DISABLED:3,EXPIRED:4};
function skey(u){switch(S.sort){
 case 'name':return u.un.toLowerCase();
 case 'st':return isGr(u)?0:(STO[u.st]||5);
 case 'tr':return u.tl?u.used/u.tl:-1+u.used/1e15;
 case 'exp':return u.exp;
 case 'on':return -u.on;
}return 0;}
function rowHtml(u){
 var e=expMs(u),pc=u.tl?Math.min(100,u.used/u.tl*100):0;
 var bc=u.tl?(pc>=100?'lv-b':(pc>=80?'lv-w':'')):'lv-i';
 var rc=e<now()?'lv-b':(e-now()<=7*DAY?'lv-w':'');
 var sh=u.sq.slice(0,1).map(function(id){return '<span class="ux-sq'+(id===GSQ?' gr':'')+'">'+esc(sqName(id))+'</span>';}).join('')+(u.sq.length>1?'<span class="ux-sq" data-tip="'+esc(u.sq.slice(1).map(sqName).join(', '))+'">+'+(u.sq.length-1)+'</span>':'');
 var ot=u.on*1000,live=u.on&&now()-ot<5*60000,sel=!!S.sel[u.su];
 var sub=u.desc?esc(u.desc):'<span style="font-family:ui-monospace,monospace">'+esc(u.su)+'</span>';
 return '<tr data-su="'+esc(u.su)+'"'+(sel?' class="sel"':'')+'>'+
  '<td class="cb"><input type="checkbox" class="ux-cbx" data-cb'+(sel?' checked':'')+' aria-label="Выбрать '+esc(u.un)+'"></td>'+
  '<td class="c-nm"><div class="u-nm"><span>'+esc(u.un)+'</span>'+(u.tag?'<span class="ux-tag">'+esc(u.tag)+'</span>':'')+(C.ovb[u.su]?'<span class="ux-pill st-bad" data-tip="Заблокирован в оверрайдах" style="font-size:.66rem">блок</span>':'')+'</div><span class="u-sub">'+sub+'</span></td>'+
  '<td class="c-st" data-l="Статус">'+pill(u)+'</td>'+
  '<td class="c-tr" data-l="Трафик"><div class="ux-tr"><div class="t"><b>'+fb(u.used)+'</b><span>'+(u.tl?'из '+fb(u.tl):'без лимита')+(STRAT_S[u.ts]?' · '+STRAT_S[u.ts]:'')+'</span></div><div class="ux-bar '+bc+'"><i style="width:'+(u.tl?pc.toFixed(1):100)+'%"></i></div></div></td>'+
  '<td class="c-ex" data-l="Истекает"><div class="ux-dt">'+(u.exp?fdt(e)+'<span class="r '+rc+'">'+(isGr(u)?'грейс, ':'')+rel(e)+'</span>':'—')+'</div></td>'+
  '<td class="c-sq" data-l="Сквады"><div class="ux-sqs">'+(sh||'<span class="muted">—</span>')+'</div></td>'+
  '<td class="c-on" data-l="Онлайн"><span class="ux-on'+(live?' live':'')+'"><i></i>'+(u.on?ago(ot):'не подключался')+'</span></td>'+
  '<td class="acts"><button type="button" class="ux-ib q" data-act="copy" data-tip="'+(u.lk?'Скопировать ссылку подписки':'Не задан адрес зеркала')+'" aria-label="Скопировать ссылку"'+(u.lk?'':' disabled')+'>'+ico('copy')+'</button><button type="button" class="ux-ib" data-act="menu" aria-label="Действия" aria-haspopup="menu">'+ico('more')+'</button></td></tr>';
}
var VIEW=[];
function pageRows(){var per=S.size||VIEW.length||1,st=(S.page-1)*per;return VIEW.slice(st,st+per);}
function render(){
 renderKpis();
 var list=U.filter(matches);
 list.sort(function(a,b){var x=skey(a),y=skey(b);return(x<y?-1:x>y?1:0)*S.dir;});
 VIEW=list;
 var per=S.size||list.length||1,pages=Math.max(1,Math.ceil(list.length/per));
 if(S.page>pages)S.page=pages;
 var st=(S.page-1)*per,pg=list.slice(st,st+per);
 $('uxBody').innerHTML=pg.length?pg.map(rowHtml).join(''):'<tr><td colspan="8"><div class="ux-empty">'+(U.length?'Под фильтр никто не подходит.':'Пользователей пока нет.')+'</div></td></tr>';
 $('uxCnt').textContent=list.length===U.length?'· '+U.length:'· '+list.length+' из '+U.length;
 $('uxTbl').classList.toggle('compact',S.dens===1);
 document.querySelectorAll('.ux-flt [data-dens]').forEach(function(b){b.classList.toggle('on',+b.dataset.dens===S.dens);});
 document.querySelectorAll('#uxTbl th.srt').forEach(function(th){th.querySelector('.sar').textContent=th.dataset.s===S.sort?(S.dir>0?'▲':'▼'):'';});
 var chips=[];if(S.sq)chips.push(['sq','Сквад: '+sqName(S.sq)]);if(S.tag)chips.push(['tag',S.tag==='-'?'Без тега':'Тег: '+S.tag]);
 $('uxChips').innerHTML=chips.map(function(c){return '<span class="ux-chip">'+esc(c[1])+'<button type="button" data-unchip="'+c[0]+'" aria-label="Сбросить фильтр">×</button></span>';}).join(' ');
 $('uxPgr').innerHTML='<span>'+(list.length?(st+1)+'–'+Math.min(st+per,list.length)+' из '+list.length:'0')+'</span><div class="nav">'+(pages>1?'<button type="button" class="wb sm" data-pg="-1"'+(S.page<=1?' disabled':'')+' aria-label="Предыдущая страница">◀</button><span>стр. '+S.page+' / '+pages+'</span><button type="button" class="wb sm" data-pg="1"'+(S.page>=pages?' disabled':'')+' aria-label="Следующая страница">▶</button>':'')+'<label style="margin:0;display:flex;align-items:center;font-size:.82rem">На странице<select id="uxSize"><option value="10">10</option><option value="25">25</option><option value="50">50</option><option value="0">Все</option></select></label></div>';
 $('uxSize').value=String(S.size);
 var all=$('uxAll'),c=pg.filter(function(u){return S.sel[u.su];}).length,ns=Object.keys(S.sel).length;
 all.checked=c>0&&c===pg.length;all.indeterminate=c>0&&c<pg.length;
 $('uxBulk').hidden=ns===0;$('uxBulkN').textContent='Выбрано: '+ns;
 applyRo(document.getElementById('ux'));
}
function applyRo(root){root.querySelectorAll('[data-w]').forEach(function(b){var sc=b.dataset.w;var dis=!canW(sc);if(dis){b.disabled=true;b.setAttribute('data-tip',roTip(sc));}else if((b.getAttribute('data-tip')||'').indexOf('Нет права')===0){b.removeAttribute('data-tip');}});}

var CF=null;
function cfm(o){
 CF=o;$('uxCfI').className='ux-cfi tn-'+(o.tone||'acc');$('uxCfI').innerHTML=ico(o.ic||'check',20);
 $('uxCfT').textContent=o.title;$('uxCfS').innerHTML=o.sub||'';
 var h=(o.rows||[]).map(function(r){return '<div class="ux-cfr"><span class="i">'+ico(r[0])+'</span><span class="l">'+r[1]+'</span><span class="v">'+(r[2]!=null?'<span class="o'+(r[4]?' ch':'')+'">'+r[2]+'</span><span class="ar">'+ico('arr',14)+'</span>':'')+'<span class="n">'+r[3]+'</span></span></div>';}).join('');
 if(o.more)h+='<div class="ux-cfm">'+o.more+'</div>';
 h+=(o.notes||[]).map(function(n){return '<div class="ux-cfn tn-'+n[1]+'">'+ico(n[0],17)+'<div><b>'+n[2]+'</b>'+(n[3]?' <span>'+n[3]+'</span>':'')+'</div></div>';}).join('');
 $('uxCfB').innerHTML=h;
 $('uxCfH').innerHTML=o.hint?ico('info',14)+o.hint:'';
 var ok=$('uxCfOk');ok.textContent=o.ok||'OK';ok.className='btn'+(o.danger?' danger':'');ok.disabled=false;
 $('uxCfOv').classList.add('open');setTimeout(function(){ok.focus();},30);
}
function cfClose(){$('uxCfOv').classList.remove('open');CF=null;}
function chips(ids){return ids&&ids.length?ids.map(function(id){return '<span class="ux-sq'+(id===GSQ?' gr':'')+'">'+esc(sqName(id))+'</span>';}).join(''):'нет';}
function nUsers(n){return '<b>'+n+'</b> '+plural(n,'пользователь','пользователя','пользователей');}

var menuFor=null;
function openMenu(btn,u){
 var m=$('uxMenu'),off=u.st==='DISABLED',nl=!!C.nl[u.su];
 var it=[['open','user','Открыть карточку'],['edit','edit','Изменить',null,'users:update'],'-',
  ['ext30','calp','Продлить на 30 дней',null,'users:update'],['ext','cal','Продлить…',null,'users:update'],['reset','reset','Сбросить трафик',null,'users:reset-traffic'],
  [off?'enable':'disable','power',off?'Включить':'Выключить',null,off?'users:enable':'users:disable'],'-',
  ['qr','qr','QR-код'],['devs','dev','Устройства'],['addsub','link',C.as[u.su]?'Доп-подписка ✓':'Доп-подписка…'],['nolog',nl?'eye':'eyeoff',nl?'Вернуть в лог запросов':'Скрыть из лога запросов'],'-',
  ['revoke','key','Новые ключи…',null,'users:revoke-subscription'],['del','trash','Удалить…','bad','users:delete']];
 m.innerHTML=it.map(function(x){if(x==='-')return '<hr>';var dis=(x[0]==='qr'&&!u.lk)||(x[4]&&!canW(x[4]));var tip=x[4]&&!canW(x[4])?roTip(x[4]):(x[0]==='qr'&&!u.lk?'Не задан адрес зеркала':'');return '<button type="button" role="menuitem" data-m="'+x[0]+'"'+(x[3]?' class="'+x[3]+'"':'')+(dis?' disabled style="opacity:.45;cursor:not-allowed"':'')+(tip?' data-tip="'+esc(tip)+'"':'')+'>'+ico(x[1])+esc(x[2])+'</button>';}).join('');
 m.classList.add('open');menuFor=u;
 var r=btn.getBoundingClientRect(),mw=m.offsetWidth,mh=m.offsetHeight;
 var left=Math.max(8,Math.min(r.right-mw,innerWidth-mw-8)),top=r.bottom+4;if(top+mh>innerHeight-8)top=Math.max(8,r.top-mh-4);
 m.style.left=left+'px';m.style.top=top+'px';
 var f=m.querySelector('button:not([disabled])');if(f)f.focus();
}
function closeMenu(){$('uxMenu').classList.remove('open');menuFor=null;}

function busy(on){var ok=$('uxCfOk');if(ok){ok.disabled=on;ok.classList.toggle('busy',on);}}
function runOne(a,data,okMsg){busy(true);return post(a,data).then(function(d){busy(false);if(!d.ok){if(d.conflict&&d.user){cfClose();putUser(d.user,d.mw,data.su);render();if(CARD)drawCard();}if(window.uiAlert)uiAlert(d.conflict?'Пользователя изменили, пока страница была открыта. Данные обновлены — проверьте и повторите.':(d.error||'Ошибка'),d.conflict?'Данные устарели':'Панель не приняла');return d;}cfClose();if(d.user)putUser(d.user,d.mw,data.su);if(d.warn&&window.uiAlert)uiAlert(d.warn);else if(okMsg)toast(okMsg);render();if(CARD)drawCard();return d;});}
function runMany(list,mk,label){
 cfClose();var i=0,ok=0,fail=[];
 (function next(){
  if(i>=list.length){toast(label+': '+ok+(fail.length?' · ошибок '+fail.length:''));if(fail.length&&window.uiAlert)uiAlert(fail.slice(0,8).join('\n'),'Не получилось');render();return;}
  var u=list[i++],q=mk(u);toast(label+'… '+i+' / '+list.length);
  post(q[0],q[1]).then(function(d){if(d.ok){ok++;if(d.user)putUser(d.user,d.mw,u.su);}else{if(d.conflict&&d.user)putUser(d.user,d.mw,u.su);fail.push(u.un+': '+(d.conflict?'изменён в панели, данные обновлены — повторите':(d.error||'ошибка')));}next();});
 })();
}
function graceRestore(u){var g=gInfo(u);if(!g)return{};var f={trafficLimitBytes:g.tl,trafficLimitStrategy:g.ts,hwidDeviceLimit:g.hw};if(g.sq&&g.sq.length)f.activeInternalSquads=g.sq;if(g.ex!==null)f.externalSquadUuid=g.ex||null;return f;}
function extFields(u,days){var b=isGr(u)?now():Math.max(now(),expMs(u));var f=isGr(u)?graceRestore(u):{};f.expireAt=Math.round((b+days*DAY)/1000);return f;}
function extend(list,days){
 var ng=list.filter(isGr).length,ne=list.filter(function(u){return u.st==='EXPIRED';}).length,notes=[],one=list.length===1;
 if(ng)notes.push(['hour','vio',one?'Грейс завершится':'В грейсе: '+ng,'прежний тариф вернётся']);
 if(ne)notes.push(['check','ok',one?'Станет «Активен»':'Истёкших: '+ne,one?'срок — от сегодня':'станут «Активен»']);
 cfm({ic:'calp',title:'Продлить на '+dd(days),sub:one?'<b>'+esc(list[0].un)+'</b>':nUsers(list.length),
  rows:list.slice(0,6).map(function(u){return ['cal',one?'Срок':esc(u.un),u.exp?fd(expMs(u)):'—',fd(extFields(u,days).expireAt*1000)];}),more:list.length>6?'и ещё '+(list.length-6):'',
  notes:notes,hint:'Активным — от текущей даты окончания',ok:'Продлить',onOk:function(){
   if(one){var u=list[0];runOne('ua_save',{su:u.su,fields:JSON.stringify(extFields(u,days)),upd:u.upd,reset:isGr(u)&&C.reset?'1':''},'Продлено · '+u.un);}
   else runMany(list,function(u){return ['ua_save',{su:u.su,fields:JSON.stringify(extFields(u,days)),upd:u.upd,reset:isGr(u)&&C.reset?'1':''}];},'Продлено');
  }});
}
function act(u,a){
 var one={su:u.su,act:a};
 if(a==='reset')return cfm({ic:'reset',tone:'info',title:'Сбросить трафик',sub:'<b>'+esc(u.un)+'</b>',rows:[['wave','Использовано',fb(u.used),'0 Б']],notes:u.st==='LIMITED'?[['check','ok','Статус «Лимит» снимется','']]:[],hint:'Лимит и срок не меняются',ok:'Сбросить',onOk:function(){runOne('ua_act',one,'Трафик сброшен · '+u.un);}});
 if(a==='disable')return cfm({ic:'power',tone:'bad',title:'Выключить пользователя',sub:'<b>'+esc(u.un)+'</b>',rows:[['power','Статус',stInfo(u).t,'Выключен']],notes:[['ban','bad','Подписка перестанет работать','до включения']],hint:'Срок и трафик не меняются',ok:'Выключить',danger:true,onOk:function(){runOne('ua_act',one,'Выключен · '+u.un);}});
 if(a==='enable')return cfm({ic:'power',tone:'ok',title:'Включить пользователя',sub:'<b>'+esc(u.un)+'</b>',rows:[['power','Статус','Выключен',expMs(u)<now()?'Истёк':'Активен']],notes:expMs(u)<now()?[['alert','warn','Срок уже прошёл','продлите, чтобы подписка заработала']]:[],ok:'Включить',onOk:function(){runOne('ua_act',one,'Включён · '+u.un);}});
 if(a==='revoke')return cfm({ic:'key',tone:'warn',title:'Новые ключи',sub:'<b>'+esc(u.un)+'</b>',notes:[['link','info','Ссылка остаётся прежней','меняются пароли VLESS, Trojan, Shadowsocks'],['refresh','warn','Клиенту нужно обновить подписку','старые конфиги перестанут работать']],ok:'Выпустить',onOk:function(){runOne('ua_act',one,'Ключи перевыпущены · '+u.un);}});
 if(a==='del')return cfm({ic:'trash',tone:'bad',title:'Удалить пользователя',sub:'<b>'+esc(u.un)+'</b> · из панели',notes:[['alert','bad','Это необратимо','ссылка перестанет работать сразу'],['shield','info','Прослойка подчистит своё','оверрайды, доп-подписку, WG/AWG, грейс']],ok:'Удалить',danger:true,onOk:function(){busy(true);post('ua_act',{su:u.su,act:'delete'}).then(function(d){busy(false);if(!d.ok){if(window.uiAlert)uiAlert(d.error||'Ошибка','Панель не приняла');return;}cfClose();U=U.filter(function(x){return x.su!==u.su;});delete S.sel[u.su];closeCard(true);toast('Удалён · '+u.un);render();});}});
}
function doAction(a,u){
 if(a==='open')return openCard(u,'ov');
 if(a==='edit')return openCard(u,'edit');
 if(a==='devs')return openCard(u,'dev');
 if(a==='copy')return copy(u.lk,'Ссылка подписки скопирована');
 if(a==='qr')return openQr(u);
 if(a==='ext30')return extend([u],30);
 if(a==='ext')return pickExtend([u]);
 if(a==='addsub')return pickAddsub(u);
 if(a==='nolog'){var on=!C.nl[u.su];post('toggle_nolog',{short_uuid:u.su,nolog:on?'1':'0'}).then(function(d){if(!d.ok){if(window.uiAlert)uiAlert(d.error||'Ошибка');return;}if(d.nolog)C.nl[u.su]=1;else delete C.nl[u.su];if(CARD&&CARD.mw&&CARD.u.su===u.su)CARD.mw.nolog=!!d.nolog;toast(d.nolog?'Запросы '+u.un+' скрыты из лога':'Запросы '+u.un+' снова в логе');if(CARD)drawCard();});return;}
 if(['reset','disable','enable','revoke','del'].indexOf(a)>-1)return act(u,a);
}
function openPick(t,html){$('uxPickT').textContent=t;$('uxPickB').innerHTML=html;$('uxPickOv').classList.add('open');applyRo($('uxPickB'));}
function closePick(){$('uxPickOv').classList.remove('open');}
function pickExtend(list){
 openPick(list.length===1?'Продлить · '+list[0].un:'Продлить выбранных ('+list.length+')',
  '<div class="ux-pre" style="margin:0 0 .9rem">'+[[30,'+1 месяц'],[90,'+3 месяца'],[180,'+6 месяцев'],[365,'+1 год']].map(function(p){return '<button type="button" class="wb" data-days="'+p[0]+'">'+p[1]+'</button>';}).join(' ')+'</div>'+
  '<label for="uxPkD" style="font-size:.84rem;color:var(--muted);font-weight:600">Своё число дней</label><div style="display:flex;gap:.5rem;margin-top:.35rem"><input type="number" id="uxPkD" min="1" value="14" style="margin:0;max-width:9rem"><button type="button" class="btn" id="uxPkGo">Продлить</button></div>'+
  '<p class="muted" style="font-size:.8rem;margin:.8rem 0 0">Истёкшим — от сегодня, остальным — от даты окончания.</p>');
 $('uxPickB').onclick=function(e){var b=e.target.closest('[data-days]');if(b){closePick();extend(list,+b.dataset.days);}if(e.target.id==='uxPkGo'){var d=parseInt($('uxPkD').value,10);if(d>0){closePick();extend(list,d);}}};
}
function pickSquads(list){
 loadMeta().then(function(){
  var sq=(META?META.sq:[]).filter(function(s){return s.uuid!==GSQ;});
  if(!sq.length){if(window.uiAlert)uiAlert('Не удалось получить список сквадов из панели');return;}
  openPick('Сквады выбранных ('+list.length+')',
   '<div class="ux-seg" id="uxPkM" style="margin-bottom:.8rem"><button type="button" class="on" data-m="add">Добавить</button><button type="button" data-m="del">Убрать</button><button type="button" data-m="set">Заменить</button></div>'+
   '<div class="ux-sqg">'+sq.map(function(s){return '<label class="ux-sqi" style="display:flex;align-items:center;gap:.55rem;border:1px solid var(--line);padding:.5rem .65rem;border-radius:9px;background:var(--bg2);cursor:pointer;margin:0"><input type="checkbox" class="ux-cbx" value="'+esc(s.uuid)+'"><span style="flex:1;font-weight:600;color:var(--text-strong);overflow:hidden;text-overflow:ellipsis;white-space:nowrap">'+esc(s.name)+'</span></label>';}).join('')+'</div>'+
   '<p class="muted" style="font-size:.8rem;margin:.8rem 0">Грейс-сквадом управляет прослойка, его здесь нет.</p><button type="button" class="btn" id="uxPkGo">Применить</button>');
  var mode='add';
  $('uxPickB').onclick=function(e){var b=e.target.closest('#uxPkM button');if(b){mode=b.dataset.m;$('uxPkM').querySelectorAll('button').forEach(function(x){x.classList.toggle('on',x===b);});}
   if(e.target.id==='uxPkGo'){var ids=[].map.call($('uxPickB').querySelectorAll('input:checked'),function(i){return i.value;});if(!ids.length)return;closePick();
    var nx=function(u){var cur=u.sq.filter(function(x){return x!==GSQ;});return mode==='add'?cur.concat(ids.filter(function(x){return cur.indexOf(x)<0;})):mode==='del'?cur.filter(function(x){return ids.indexOf(x)<0;}):ids.slice();};
    cfm({ic:'net',title:mode==='add'?'Добавить сквады':mode==='del'?'Убрать сквады':'Заменить сквады',sub:nUsers(list.length),rows:[[mode==='del'?'x':'plus',mode==='add'?'Добавить':mode==='del'?'Убрать':'Станут',null,chips(ids)]],notes:list.some(isGr)?[['hour','vio','У кого грейс — он завершится','']]:[],ok:'Применить',onOk:function(){runMany(list,function(u){return ['ua_save',{su:u.su,fields:JSON.stringify({activeInternalSquads:nx(u)}),upd:u.upd}];},'Сквады обновлены');}});}};
 });
}
function pickAddsub(u){
 var cur=C.as[u.su]||'';
 openPick('Доп-подписка · '+u.un,'<p class="muted" style="margin-top:0;font-size:.86rem">Серверы второй подписки подмешаются в ссылку этого пользователя. Его ссылка не меняется.</p><input type="text" id="uxPkU" placeholder="https://…/sub/…" spellcheck="false" style="width:100%;font-family:ui-monospace,monospace;font-size:.82rem;margin:0" value="'+esc(cur)+'"><div class="ux-err" id="uxPkE"></div><div style="display:flex;gap:.6rem;margin-top:1rem"><button type="button" class="btn" id="uxPkGo">Сохранить</button>'+(cur?'<button type="button" class="btn ghost" id="uxPkDel">Отвязать</button>':'')+'</div>');
 $('uxPickB').onclick=function(e){
  if(e.target.id==='uxPkGo'){var v=$('uxPkU').value.trim();if(!/^https?:\/\//i.test(v)){$('uxPkE').textContent='Адрес должен начинаться с http:// или https://';return;}post('addsub_map_set',{short_uuid:u.su,url:v}).then(function(d){if(!d.ok){$('uxPkE').textContent=d.error||'Не сохранилось';return;}C.as[u.su]=v;if(CARD&&CARD.mw&&CARD.u.su===u.su)CARD.mw.addsub=v;closePick();toast('Доп-подписка привязана');if(CARD)drawCard();});}
  if(e.target.id==='uxPkDel'){post('addsub_map_del',{short_uuid:u.su}).then(function(d){if(!d.ok){$('uxPkE').textContent=d.error||'Не получилось';return;}delete C.as[u.su];if(CARD&&CARD.mw&&CARD.u.su===u.su)CARD.mw.addsub='';closePick();toast('Доп-подписка отвязана');if(CARD)drawCard();});}
 };
}
var QRP=null;
function qrLib(){if(window.qrcode)return Promise.resolve();if(!QRP)QRP=new Promise(function(res,rej){var s=document.createElement('script');s.src=C.qr;s.onload=res;s.onerror=function(){QRP=null;rej();};document.head.appendChild(s);});return QRP;}
function qrSvg(t){var q=qrcode(0,'M');q.addData(t);q.make();return q.createSvgTag({cellSize:4,margin:0,scalable:true});}
function openQr(u){if(!u.lk)return;qrLib().then(function(){$('uxQrBox').innerHTML=qrSvg(u.lk);$('uxQrT').textContent='QR-код · '+u.un;$('uxQrL').textContent=u.lk;$('uxQrCopy').onclick=function(){copy(u.lk,'Ссылка подписки скопирована');};$('uxQrOv').classList.add('open');},function(){if(window.uiAlert)uiAlert('Не загрузился генератор QR-кода');});}

var CARD=null;
function openCard(u,tab){CARD={u:u,tab:tab||'ov',create:false,F:null,restore:true,resetTr:!!C.reset,mw:null,devs:null,hist:null,seq:0};drawCard();$('uxOv').classList.add('open');document.body.style.overflow='hidden';refreshCard();}
function refreshCard(){if(!CARD||CARD.create)return;var su=CARD.u.su,s=++CARD.seq;loadMeta().then(function(){if(CARD&&!CARD.create&&CARD.seq===s){var f=$('uxForm'),sc=f?f.scrollTop:0;drawCard();f=$('uxForm');if(f)f.scrollTop=sc;}});getj('ua_user','&su='+encodeURIComponent(su)).then(function(d){if(!CARD||CARD.create||CARD.u.su!==su)return;if(d.ok){putUser(d.user,d.mw);if(CARD.tab==='edit'&&CARD.F&&CARD.O&&CARD.O.upd!==d.user.upd&&!diffList().length)CARD.F=null;if(CARD.tab!=='edit'||!CARD.F)drawCard();render();}else{CARD.err=d.error;if(CARD.tab!=='edit')drawCard();}});}
function openCreate(){CARD={u:null,tab:'edit',create:true,F:null,done:null};drawCard();$('uxOv').classList.add('open');document.body.style.overflow='hidden';loadMeta().then(function(){if(CARD&&CARD.create&&!CARD.done){var sc=$('uxForm')?$('uxForm').scrollTop:0;drawCard();if($('uxForm'))$('uxForm').scrollTop=sc;}});setTimeout(function(){var i=$('fUser');if(i)i.focus();},60);}
function closeCard(force){
 if(!CARD)return;
 if(!force&&CARD.tab==='edit'&&!CARD.done&&CARD.F&&diffList().length){cfm({ic:'alert',tone:'warn',title:'Закрыть без сохранения?',sub:'изменения в форме пропадут',ok:'Закрыть',danger:true,onOk:function(){cfClose();closeCard(true);}});return;}
 $('uxOv').classList.remove('open');document.body.style.overflow='';CARD=null;
}
function head(u){
 var off=u.st==='DISABLED',ini=u.un.replace(/[^a-zA-Zа-яА-Я0-9]/g,'').slice(0,2)||'?';
 return '<div class="ux-dh"><div class="r1"><div class="ux-av">'+esc(ini)+'</div><div style="min-width:0"><div class="nm">'+esc(u.un)+' '+pill(u)+(u.tag?' <span class="ux-tag">'+esc(u.tag)+'</span>':'')+'</div>'+
  '<div class="id"><code data-copy="'+esc(u.su)+'" data-tip="shortUuid — нажмите, чтобы скопировать">'+esc(u.su)+'</code>'+(u.id?'<span>ID '+esc(u.id)+'</span>':'')+(u.cr?'<span>создан '+fd(u.cr*1000)+'</span>':'')+'</div></div>'+
  '<button type="button" class="ux-ib x" data-close aria-label="Закрыть">'+ico('x',18)+'</button></div>'+
  '<div class="r2"><button type="button" class="wb sm" data-a="copy"'+(u.lk?'':' disabled data-tip="Не задан адрес зеркала"')+'>'+ico('copy')+'Ссылка</button><button type="button" class="wb sm" data-a="qr"'+(u.lk?'':' disabled')+'>'+ico('qr')+'QR</button>'+
  '<button type="button" class="wb sm" data-a="ext30" data-w="users:update">'+ico('calp')+'+30 дней</button>'+
  '<button type="button" class="wb sm" data-a="reset" data-w="users:reset-traffic">'+ico('reset')+'Сбросить трафик</button>'+
  '<button type="button" class="wb sm'+(off?'':' bad')+'" data-a="'+(off?'enable':'disable')+'" data-w="'+(off?'users:enable':'users:disable')+'">'+ico('power')+(off?'Включить':'Выключить')+'</button>'+
  '<button type="button" class="ux-ib" data-a="menu" aria-label="Ещё действия" style="margin-left:auto">'+ico('more')+'</button></div></div>'+
  '<div class="ux-tabs" role="tablist">'+[['ov','Обзор'],['edit','Изменить'],['dev','Устройства'],['hist','История']].map(function(t){return '<button type="button" role="tab" data-tab="'+t[0]+'" class="'+(CARD.tab===t[0]?'on':'')+'"'+(t[0]==='edit'&&!canW('users:update')?' disabled data-tip="'+esc(roTip('users:update'))+'"':'')+'>'+t[1]+'</button>';}).join('')+'</div>';
}
function drawCard(){
 if(!CARD)return;
 var dr=$('uxDr'),u=CARD.u;
 if(CARD.create){dr.innerHTML=CARD.done?createDone():formHtml(true);if(!CARD.done)bindForm(true);applyRo(dr);if(CARD.done)qrLib().then(function(){var b=$('uxOkQr');if(b&&CARD&&CARD.done)b.innerHTML=qrSvg(CARD.done.lk);},function(){});return;}
 if(CARD.tab==='edit'){dr.innerHTML=head(u)+formHtml(false);bindForm(false);applyRo(dr);return;}
 var h=head(u)+'<div class="ux-db">'+(CARD.err?'<div class="ux-warn">'+esc(CARD.err)+'</div>':'');
 if(CARD.tab==='ov')h+=ovHtml(u);
 else if(CARD.tab==='dev')h+=devHtml(u);
 else h+=histHtml(u);
 dr.innerHTML=h+'</div>';applyRo(dr);
 if((CARD.tab==='dev'||CARD.tab==='ov')&&CARD.devs===null)loadDevs();
 if(CARD.tab==='hist'&&CARD.hist===null)loadHist();
}
function graceRows(u,g){
 var tl=function(b,s){return(b?fb(b):'без лимита')+(STRAT_S[s]?', сброс: '+STRAT_S[s]:'');};
 var R=[['Сквады',u.sq.map(sqName).join(', ')||'—',g.sq.map(sqName).join(', ')||'—'],['Трафик',tl(u.tl,u.ts),tl(g.tl,g.ts)],['Устройства',hwTxt(u.hw),hwTxt(g.hw)]];
 if(g.ex!==null)R.push(['Внешний сквад',exName(u.ex)||'нет',exName(g.ex)||'нет']);
 return '<div class="ux-rt">'+R.map(function(r){return '<span class="k">'+r[0]+'</span><span class="a">'+esc(r[1])+'</span><span class="ar">→</span><span class="b">'+esc(r[2])+'</span>';}).join('')+'</div>';
}
function ovHtml(u){
 var h='',e=expMs(u),mw=CARD.mw,g=isGr(u)?gInfo(u):null;
 if(g){
  h+='<div class="ux-gb"><div class="h">'+ico('hour',18)+'В грейсе до '+fdt(g.until*1000)+' · '+rel(g.until*1000)+'</div>'+
  '<p>'+(g.oexp?'Подписка истекла '+fdt(g.oexp*1000)+'. ':'')+'При продлении вернётся прежний тариф:</p>'+graceRows(u,g)+
  '<div class="acts"><button type="button" class="wb pri sm" data-a="restore" data-w="users:update">'+ico('back')+'Вернуть тариф и продлить…</button></div></div>';
 }
 var pc=u.tl?Math.min(100,u.used/u.tl*100):0,bc=u.tl?(pc>=100?'lv-b':(pc>=80?'lv-w':'')):'lv-i';
 h+='<div class="ux-dk">'+
  '<div><div class="k">Трафик</div><div class="v">'+fb(u.used)+'</div><div class="d">'+(u.tl?'из '+fb(u.tl):'без лимита')+(STRAT_S[u.ts]?' · '+STRAT_S[u.ts]:'')+'</div><div class="ux-bar '+bc+'"><i style="width:'+(u.tl?pc.toFixed(1):100)+'%"></i></div></div>'+
  '<div><div class="k">Истекает</div><div class="v">'+(u.exp?fd(e):'—')+'</div><div class="d" style="color:'+(e<now()?'var(--c-bad-fg)':(e-now()<7*DAY?'var(--c-warn-fg)':'var(--muted)'))+'">'+(u.exp?rel(e):'')+'</div></div>'+
  '<div><div class="k">Устройства</div><div class="v">'+(CARD.devs?CARD.devs.length:'…')+(hwEff(u).lim?' / '+hwEff(u).lim:'')+'</div><div class="d">лимит: '+esc(hwEff(u).d)+'</div></div>'+
  '<div><div class="k">Онлайн</div><div class="v" style="font-size:.98rem">'+(u.on?ago(u.on*1000):'—')+'</div><div class="d">'+(u.node?esc(nodeName(u.node)||'нода '+u.node.slice(0,8)):'не подключался')+'</div></div></div>';
 var sqs=u.sq.map(function(id){return '<span class="ux-sq'+(id===GSQ?' gr':'')+'">'+esc(sqName(id))+'</span>';}).join(' ')||'<span class="m">нет</span>';
 var row=function(k,v){return '<dt>'+k+'</dt><dd>'+v+'</dd>';},emp='<span class="m">—</span>';
 h+='<div class="ux-sect">Панель<span class="sp"></span></div><dl class="ux-dl">'+
  row('Внутренние сквады',sqs)+row('Внешний сквад',u.ex?esc(exName(u.ex)):emp)+
  row('Сброс трафика',esc((STRAT.filter(function(s){return s[0]===u.ts;})[0]||['',u.ts])[1]))+row('Трафик за всё время',fb(u.life))+
  row('Telegram ID',u.tg?'<code>'+esc(u.tg)+'</code>':emp)+row('Email',u.em?esc(u.em):emp)+row('Описание',u.desc?esc(u.desc):emp)+
  row('Первое подключение',u.first?fdt(u.first*1000):emp)+row('Изменён',u.upd?fdt(Date.parse(u.upd))+' <span class="m">· '+ago(Date.parse(u.upd))+'</span>':emp)+'</dl>';
 var tile=function(ic,t,v,a){return '<div><span class="ic">'+ico(ic)+'</span><div class="tx"><div class="tt">'+t+'</div><div class="tv">'+v+'</div>'+(a?'<div class="ta">'+a+'</div>':'')+'</div></div>';};
 var lo='<span class="muted" style="font-weight:400">загрузка…</span>';
 var blk=mw?(mw.ov||[]).some(function(o){return o.r==='blocked';}):!!C.ovb[u.su];
 var served=blk?'<span style="color:var(--c-bad-fg)">Заглушка — блок в оверрайдах</span>':(g?'Конфиги грейс-сквада':(u.st==='ACTIVE'?'Конфиг панели':'Ответ панели — подписка не активна'));
 var lr=mw&&mw.last,decL={blocked:'блок',expired:'истёк',grace:'грейс',error:'ошибка'};
 var as=mw?mw.addsub:(C.as[u.su]||''),nl=mw?mw.nolog:!!C.nl[u.su];
 var wg=mw?(mw.wg.m?'Ручная привязка'+(mw.wg.a?' и из пула: '+mw.wg.a:''):(mw.wg.a?'Из пула: '+mw.wg.a:'<span class="muted" style="font-weight:400">не выдаётся</span>')):lo;
 var nov=mw?(mw.ov||[]).length:0;
 h+='<div class="ux-sect">Прослойка<span class="sp"></span></div><div class="ux-mw">'+
  tile('shield','Что отдаётся',served,nov?'<a href="?tab=overrides" class="muted" style="font-size:.78rem">Оверрайды: '+nov+' →</a>':'')+
  tile('send','Последний запрос',mw?(lr?esc(lr.app||'клиент не распознан')+' · '+ago(lr.t*1000):'<span class="muted" style="font-weight:400">запросов не было</span>'):lo,lr?'<span class="muted" style="font-size:.78rem">'+esc(lr.os||'')+(lr.dec&&decL[lr.dec]?(lr.os?' · ':'')+decL[lr.dec]:'')+'</span>':'')+
  tile('link','Доп-подписка',as?'<span style="font-family:ui-monospace,monospace;font-size:.8rem">'+esc(as)+'</span>':'<span class="muted" style="font-weight:400">не привязана</span>','<button type="button" class="wb sm" data-a="addsub">'+(as?'Изменить':'Привязать')+'</button>')+
  tile('net','WG / AWG',wg,'<a href="?tab=wg_pool" class="muted" style="font-size:.78rem">Открыть WG / AWG →</a>')+
  tile('log','Лог запросов',nl?'Скрыт из лога':'Пишется в лог','<button type="button" class="wb sm" data-a="nolog">'+(nl?'Вернуть в лог':'Скрыть')+'</button> <a href="?tab=reqlog&amp;rl_hours=0&amp;rl_q='+encodeURIComponent(u.su)+'" class="muted" style="font-size:.78rem;margin-left:.4rem">Открыть лог →</a>')+'</div>';
 return h;
}
function loadDevs(){var su=CARD.u.su;CARD.devs=false;getj('hwids','&uuid='+encodeURIComponent(CARD.u.rv)).then(function(d){if(!CARD||CARD.create||CARD.u.su!==su)return;CARD.devs=d.ok?(d.devices||[]):[];CARD.devErr=d.ok?'':(d.error||'Ошибка');if(CARD.tab==='dev'||CARD.tab==='ov')drawCard();});}
function devHtml(u){
 if(!CARD.devs)return '<div class="ux-load">Загрузка…</div>';
 if(CARD.devErr)return '<div class="ux-warn">'+esc(CARD.devErr)+'</div>';
 var ds=CARD.devs,h='<div style="display:flex;align-items:center;justify-content:space-between;gap:1rem;margin-bottom:.8rem"><span class="muted" style="font-size:.88rem">Устройств: <b style="color:var(--text-strong)">'+ds.length+'</b>'+(hwEff(u).lim?' из '+hwEff(u).lim:'')+' · лимит: '+esc(hwEff(u).d)+'</span>'+(ds.length>1?'<button type="button" class="wb sm bad" data-a="delall" data-w="hwid-user-devices:delete">'+ico('trash')+'Удалить все</button>':'')+'</div>';
 if(!ds.length)return h+'<div class="ux-empty">Устройств нет — клиент ещё не присылал HWID.</div>';
 return h+ds.map(function(d,i){var hw=d.hwid||'',bl=C.hwb.indexOf(String(hw).toLowerCase())>-1,dt=Date.parse(d.updatedAt||d.createdAt||'');return '<div class="ux-dev"><div class="l"><span class="os">'+ico('dev',17)+'</span><div style="min-width:0"><div class="m1">'+esc(d.deviceModel||d.platform||'Устройство')+(bl?' <span class="ux-pill st-bad" style="font-size:.66rem">блок</span>':'')+'</div><div class="m2">'+esc([d.platform,d.osVersion].filter(Boolean).join(' ')||'ОС неизвестна')+(d.userAgent?' · '+esc(d.userAgent):'')+'</div><div class="m3">'+esc(hw)+(isNaN(dt)?'':' · '+fdt(dt))+'</div></div></div><div class="a"><button type="button" class="wb sm" data-a="hwblock" data-i="'+i+'">'+ico(bl?'check':'ban')+(bl?'Разблок.':'Блок')+'</button><button type="button" class="wb sm bad" data-a="hwdel" data-i="'+i+'" data-w="hwid-user-devices:delete">'+ico('trash')+'Удалить</button></div></div>';}).join('');
}
function loadHist(){var su=CARD.u.su;CARD.hist=false;getj('ua_hist','&su='+encodeURIComponent(su)).then(function(d){if(!CARD||CARD.create||CARD.u.su!==su)return;CARD.hist=d.ok?d.ev:[];if(CARD.tab==='hist')drawCard();});}
var EVN={'user.created':'Создан','user.modified':'Изменён в панели','user.deleted':'Удалён','user.revoked':'Новые ключи или ссылка','user.disabled':'Выключен','user.enabled':'Включён','user.limited':'Лимит трафика','user.expired':'Истёк','user.traffic_reset':'Сброс трафика','user.expiration':'Напоминание о сроке','user.first_connected':'Первое подключение','user.not_connected':'Давно не подключался','user.bandwidth_usage_threshold_reached':'Порог трафика','user_hwid_devices.added':'Новое устройство','user_hwid_devices.deleted':'Устройство удалено'};
var OPN={patch:'Изменение',reset_traffic:'Сброс трафика',enable:'Включение',disable:'Выключение',revoke:'Новые ключи',create:'Создание',delete:'Удаление'};
function srcL(s){if(s==='admin')return 'из админки';if(/^grace/.test(s))return 'грейс';return s;}
function histHtml(u){
 if(!CARD.hist)return '<div class="ux-load">Загрузка…</div>';
 var ev=CARD.hist;
 var foot='<p class="muted" style="font-size:.8rem;margin-top:1rem"><a href="?tab=reqlog&amp;rl_hours=0&amp;rl_q='+encodeURIComponent(u.su)+'">Лог запросов →</a> &nbsp; <a href="?tab=whlog&amp;wh_user='+encodeURIComponent(u.su)+'">Лог вебхуков →</a></p>';
 if(!ev.length)return '<div class="ux-empty">Записей пока нет.</div>'+foot;
 var dv=function(x){return typeof x==='number'?fdt(x*1000):esc(x);};
 return '<div class="ux-tl">'+ev.map(function(e){
  var ic,tn,t,s;
  if(e.k==='rq'){ic='send';tn='st-info';t='Запрос подписки';s=esc([e.app||'клиент не распознан',e.os].filter(Boolean).join(' · '))+(e.dec&&e.dec!=='normal'?' <span class="ux-pill '+(e.dec==='blocked'||e.dec==='error'?'st-bad':(e.dec==='grace'?'st-vio':'st-warn'))+'" style="font-size:.66rem">'+esc(({blocked:'блок',expired:'истёк',grace:'грейс',error:'ошибка'})[e.dec]||e.dec)+'</span>':'');}
  else if(e.k==='wh'){ic='edit';tn='st-mut';t=EVN[e.ev]||e.ev;s=(e.d||[]).map(function(x){return esc(x.l)+': '+dv(x.a)+' → '+dv(x.b);}).join('<br>')+(e.mw===1?' <span class="ux-pill st-ok" style="font-size:.66rem">прослойка</span>':(e.mw===0?' <span class="ux-pill st-mut" style="font-size:.66rem">извне</span>':''));}
  else{ic='shield';tn=e.ok?'st-ok':'st-bad';t='Прослойка: '+(OPN[e.op]||e.op).toLowerCase();s=esc(srcL(e.src))+(e.f&&e.f.length?' · '+esc(e.f.join(', ')):'')+(e.ok?'':' · <span style="color:var(--c-bad-fg)">'+esc(e.err||'ошибка')+'</span>');}
  return '<div><span class="tm">'+fdt(e.t*1000).replace(' в ',' ')+'</span><span class="ic '+tn+'">'+ico(ic,13)+'</span><span class="tx"><b>'+esc(t)+'</b><span class="s">'+s+'</span></span></div>';
 }).join('')+'</div>'+foot;
}

function snapF(u){return{upd:u.upd||'',exp:expMs(u),status:u.st==='DISABLED'?'DISABLED':'ACTIVE',tl:u.tl,ts:u.ts,hw:u.hw,sq:u.sq.slice().sort(),ex:u.ex||'',tag:u.tag||'',desc:u.desc||'',tg:u.tg||'',email:u.em||''};}
function newF(){var d=new Date(now()+30*DAY);d.setSeconds(0,0);return{exp:d.getTime(),status:'ACTIVE',tl:0,ts:'NO_RESET',hw:null,sq:[],ex:'',tag:'',desc:'',tg:'',email:'',username:'',short:'',vless:'',trojan:'',ss:'',addsub:'',nolog:false,tpl:''};}
function graceF(g,F){F.sq=g.sq.slice().sort();F.tl=g.tl;F.ts=g.ts;F.hw=g.hw;if(g.ex!==null)F.ex=g.ex||'';}
var LBL={exp:'Срок',status:'Статус',tl:'Лимит трафика',ts:'Сброс трафика',hw:'Лимит устройств',sq:'Сквады',ex:'Внешний сквад',tag:'Тег',desc:'Описание',tg:'Telegram ID',email:'Email'};
var FICO={exp:'cal',status:'power',tl:'wave',ts:'refresh',hw:'dev',sq:'net',ex:'link',tag:'tag',desc:'edit',tg:'send',email:'mail'};
var API={exp:'expireAt',status:'status',tl:'trafficLimitBytes',ts:'trafficLimitStrategy',hw:'hwidDeviceLimit',sq:'activeInternalSquads',ex:'externalSquadUuid',tag:'tag',desc:'description',tg:'telegramId',email:'email'};
function fmtF(k,v){if(k==='exp')return v?fdt(v):'—';if(k==='status')return v==='ACTIVE'?'включён':'выключен';if(k==='tl')return v?fb(v):'без лимита';if(k==='ts')return(STRAT.filter(function(s){return s[0]===v;})[0]||['',v])[1];if(k==='hw')return hwTxt(v);if(k==='sq')return v.map(sqName).join(', ')||'нет';if(k==='ex')return exName(v)||'нет';return v===''?'—':v;}
function fmtH(k,v){if(k==='sq')return chips(v);return esc(fmtF(k,v));}
function apiVal(k,v){if(k==='exp')return Math.round(v/1000);if(k==='ex')return v||null;return v;}
function diffList(){
 if(!CARD||!CARD.F)return[];if(CARD.create){var F0=CARD.F;return(F0.username||F0.addsub||F0.desc.trim()||F0.tag||F0.tg||F0.email||F0.short||F0.vless||F0.trojan||F0.ss)?[1]:[];}
 var O=CARD.O,F=CARD.F,out=[];
 Object.keys(LBL).forEach(function(k){var a=O[k],b=F[k];if(k==='sq'){if(a.join()!==b.join())out.push(k);}else if(k==='exp'){if(Math.abs(a-b)>59000)out.push(k);}else if(String(a==null?'':a)!==String(b==null?'':b)||(k==='hw'&&a!==b))out.push(k);});
 return out;
}
function grTouch(d){return d.some(function(k){return ['exp','status','tl','ts','hw','sq','ex'].indexOf(k)>-1;});}
function errs(){
 var F=CARD.F,E={},cr=CARD.create;
 if(F.exp<=now()&&(cr||Math.abs(F.exp-CARD.O.exp)>59000))E.exp='Панель не принимает дату в прошлом';
 if(cr){if(!/^[a-zA-Z0-9_-]{3,36}$/.test(F.username))E.username=F.username?'3–36 символов: латиница, цифры, _ и -':'Обязательное поле';else if(U.some(function(u){return u.un.toLowerCase()===F.username.toLowerCase();}))E.username='Такое имя уже есть в панели';
  if(F.short&&(F.short.length<16||F.short.length>64))E.short='От 16 до 64 символов';
  if(F.vless&&!/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(F.vless))E.vless='Нужен UUID';
  if(F.trojan&&(F.trojan.length<8||F.trojan.length>32))E.trojan='От 8 до 32 символов';
  if(F.ss&&(F.ss.length<8||F.ss.length>32))E.ss='От 8 до 32 символов';
  if(F.addsub&&!/^https?:\/\//i.test(F.addsub))E.addsub='Адрес начинается с http:// или https://';}
 if(F.tag&&!/^[A-Z0-9_]{1,16}$/.test(F.tag))E.tag='До 16 символов: A–Z, 0–9, _';
 if(F.email&&!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(F.email))E.email='Неверный email';
 if(F.tg&&!/^\d{1,15}$/.test(F.tg))E.tg='Только цифры';
 if(F.hw!==null&&F.hw!==0&&!(F.hw>=1))E.hw='Укажите число';
 if(!cr&&isGr(CARD.u)&&CARD.restore&&Math.abs(F.exp-CARD.O.exp)<60000)E.exp='Поставьте новую дату';
 return E;
}
function fsec(ic,t,body){return '<div class="ux-fs"><div class="t">'+ico(ic)+t+'<span class="sp"></span></div>'+body+'</div>';}
function was(){var u=CARD.u;if(CARD.create||!u||!isGr(u)||!CARD.restore)return '';return ' <span class="ux-was">прежнее</span>';}
function formHtml(create){
 if(!CARD.F){CARD.F=create?newF():snapF(CARD.u);CARD.O=create?newF():snapF(CARD.u);if(!create&&isGr(CARD.u)&&CARD.restore)graceF(gInfo(CARD.u),CARD.F);if(create&&META&&!CARD.F.sq.length){}}
 var F=CARD.F,u=CARD.u,gr=!create&&isGr(u),h='';
 if(create)h+='<div class="ux-dh"><div class="r1"><div class="ux-av">'+ico('plus',20)+'</div><div><div class="nm">Новый пользователь</div><div class="id">Создаётся в панели Remnawave</div></div><button type="button" class="ux-ib x" data-close aria-label="Закрыть">'+ico('x',18)+'</button></div></div>';
 h+='<div class="ux-db" id="uxForm">';
 if(create){
  h+='<div class="ux-fs"><div class="ux-g2"><div class="ux-f"><label for="fUser">Имя пользователя</label><input type="text" id="fUser" class="mono" value="'+esc(F.username)+'" placeholder="ivan_petrov" autocomplete="off" spellcheck="false"><div class="ux-err" data-e="username"></div><div class="hint2" style="font-size:.76rem;color:var(--muted)">Латиница, цифры, _ и -, 3–36 символов. Изменить потом нельзя.</div></div>'+
  '<div class="ux-f"><label for="fTpl">Заполнить как у пользователя</label><select id="fTpl"><option value="">— не заполнять —</option>'+U.slice().sort(function(a,b){return a.un.localeCompare(b.un);}).map(function(x){return '<option value="'+esc(x.su)+'"'+(F.tpl===x.su?' selected':'')+'>'+esc(x.un)+'</option>';}).join('')+'</select><div class="hint2" style="font-size:.76rem;color:var(--muted)">Возьмёт сквады, трафик, устройства и тег.</div></div></div></div>';
 }
 if(gr){var g=gInfo(u);
  h+='<div class="ux-gb"><label class="ux-opt"><input type="checkbox" class="ux-cbx" id="fRestore"'+(CARD.restore?' checked':'')+'><span><b>Вернуть прежний тариф</b><span class="d">Поставьте новую дату — остальное вернётся как до грейса.</span></span></label>'+
  (CARD.restore?graceRows(u,g):'<p style="font-size:.82rem">Грейс завершится, сохранятся значения из формы.</p>')+
  '<label class="ux-opt" style="margin-top:.7rem"><input type="checkbox" class="ux-cbx" id="fResetTr"'+(CARD.resetTr?' checked':'')+'><span><b>Сбросить использованный трафик</b><span class="d">Как при обычном выходе из грейса.</span></span></label></div>';
 }
 h+=fsec('cal','Срок и статус',
  '<div class="ux-g2"><div class="ux-f"><label for="fExp">Дата окончания'+(gr&&CARD.restore?' <span class="ux-was">новая</span>':'')+'</label><input type="datetime-local" id="fExp" value="'+dlv(F.exp)+'"><div class="ux-err" data-e="exp"></div>'+
  '<div class="ux-pre">'+[[1,'+1 мес'],[3,'+3 мес'],[6,'+6 мес'],[12,'+1 год']].map(function(p){return '<button type="button" data-mon="'+p[0]+'">'+p[1]+'</button>';}).join('')+'<button type="button" data-mon="2099">2099</button></div>'+
  '<div class="ux-will" id="fWill"></div></div>'+
  '<div class="ux-f"><label for="fSt">Статус</label><div class="ux-sw" style="border:1px solid var(--line);border-radius:9px;padding:.55rem .7rem;background:var(--bg2)"><div><b style="color:var(--text-strong);font-size:.88rem">'+(create?'Создать включённым':'Подписка включена')+'</b><div class="d">'+(create?'Выключите, чтобы завести заранее':'Выключенный не подключается')+'</div></div><label class="switch" style="margin:0"><input type="checkbox" id="fSt"'+(F.status==='ACTIVE'?' checked':'')+'><span class="sl"></span></label></div>'+
  (!create&&u.st==='EXPIRED'?'<div class="hint2" style="font-size:.76rem;color:var(--muted);margin-top:.3rem">Новая дата в будущем вернёт статус «Активен».</div>':'')+
  (!create&&u.st==='LIMITED'?'<div class="hint2" style="font-size:.76rem;color:var(--c-warn-fg);margin-top:.3rem">«Лимит» снимется, если поднять или убрать лимит трафика.</div>':'')+'</div></div>');
 var tb=F.tl&&F.tl>=1024*GB&&F.tl%(1024*GB)===0,tlVal=F.tl?(tb?F.tl/1024/GB:gbv(F.tl)):'';
 h+=fsec('wave','Трафик'+was(),
  '<div class="ux-g2"><div class="ux-f"><label for="fTl">Лимит трафика</label><div class="ux-inl"><input type="number" id="fTl" min="0" step="any" value="'+tlVal+'" placeholder="без лимита"><select id="fTlU" aria-label="Единица"><option value="gb"'+(tb?'':' selected')+'>ГБ</option><option value="tb"'+(tb?' selected':'')+'>ТБ</option></select></div>'+
  '<div class="ux-pre">'+[0,50,100,200,500].map(function(x){return '<button type="button" data-gb="'+x+'">'+(x?x+' ГБ':'Без лимита')+'</button>';}).join('')+'</div>'+
  (!create?'<div class="hint2" style="font-size:.76rem;color:var(--muted);margin-top:.35rem">Использовано: '+fb(u.used)+'</div>':'')+'</div>'+
  '<div class="ux-f"><label for="fTs">Сброс трафика</label><select id="fTs">'+STRAT.map(function(s){return '<option value="'+s[0]+'"'+(F.ts===s[0]?' selected':'')+'>'+s[1]+'</option>';}).join('')+'</select><div class="hint2" style="font-size:.76rem;color:var(--muted)">Когда панель обнуляет использованное.</div></div></div>');
 var hm=F.hw===null?'def':(F.hw===0?'off':'num');
 h+=fsec('dev','Устройства'+was(),
  '<div class="ux-seg" id="fHwM">'+[['def','Как в панели'+(function(){var h=create?(META&&META.hwid?{s:META.hwid}:null):hwSrc(u);return h&&h.s.enabled&&h.s.limit!=null?' · '+h.s.limit:'';})()],['off','Без лимита'],['num','Своё число']].map(function(m){return '<button type="button" data-hm="'+m[0]+'" class="'+(hm===m[0]?'on':'')+'">'+m[1]+'</button>';}).join('')+'</div>'+
  '<div class="ux-inl" id="fHwBox" style="margin-top:.6rem;max-width:14rem'+(hm==='num'?'':';display:none')+'"><input type="number" id="fHw" min="1" value="'+(F.hw>0?F.hw:3)+'" aria-label="Лимит устройств"><span class="muted" style="font-size:.84rem;white-space:nowrap">устройств</span></div><div class="ux-err" data-e="hw"></div>'+
  '<div class="hint2">'+(function(){var h=create?(META&&META.hwid?{s:META.hwid}:null):hwSrc(u);if(!h)return 'Работает, если в панели включён лимит HWID.';if(!h.s.enabled)return 'Лимит HWID в панели выключен — число устройств сейчас не ограничено.';return 'Общий лимит '+(h.from==='ex'?'внешнего сквада':'панели')+': '+h.s.limit+'.';})()+'</div>');
 var sqList=META?META.sq:null;
 h+=fsec('net','Доступ'+was(),
  '<div class="ux-f"><label>Внутренние сквады</label>'+(sqList?'<div class="ux-sqg" id="fSq">'+sqList.map(function(s){var on=F.sq.indexOf(s.uuid)>-1;return '<label class="ux-sqi'+(on?' on':'')+(s.uuid===GSQ?' gr':'')+'"><input type="checkbox" class="ux-cbx" value="'+esc(s.uuid)+'"'+(on?' checked':'')+'><span class="nm">'+esc(s.name)+'</span><span class="c" data-tip="Пользователей в скваде">'+(s.members||0)+'</span></label>';}).join('')+'</div>':'<div class="ux-load">Загрузка сквадов…</div>')+
  (F.sq.indexOf(GSQ)>-1&&GSQ?'<div class="hint2" style="color:var(--c-violet-fg)">Грейс-сквад обычно ставит и снимает прослойка.</div>':'')+'</div>'+
  '<div class="ux-f" style="margin-top:.8rem;max-width:22rem"><label for="fEx">Внешний сквад</label><select id="fEx"><option value="">— нет —</option>'+(META?META.ex.map(function(x){return '<option value="'+esc(x.uuid)+'"'+(F.ex===x.uuid?' selected':'')+'>'+esc(x.name)+'</option>';}).join(''):(F.ex?'<option value="'+esc(F.ex)+'" selected>'+esc(exName(F.ex))+'</option>':''))+'</select></div>');
 h+=fsec('tag','Метки и контакты',
  '<div class="ux-g2"><div class="ux-f"><label for="fTag">Тег</label><input type="text" id="fTag" class="mono" value="'+esc(F.tag)+'" placeholder="VIP" maxlength="16" spellcheck="false"><div class="ux-err" data-e="tag"></div></div>'+
  '<div class="ux-f"><label for="fTg">Telegram ID</label><input type="text" id="fTg" inputmode="numeric" class="mono" value="'+esc(F.tg)+'" placeholder="528114200"><div class="ux-err" data-e="tg"></div></div>'+
  '<div class="ux-f"><label for="fEm">Email</label><input type="email" id="fEm" value="'+esc(F.email)+'" placeholder="user@example.com"><div class="ux-err" data-e="email"></div></div>'+
  '<div class="ux-f" style="grid-column:1/-1"><label for="fDesc">Описание</label><textarea id="fDesc" rows="2" placeholder="Видно только в панели и здесь">'+esc(F.desc)+'</textarea></div></div>');
 if(create){
  h+='<details class="ux-more"><summary>Дополнительно <span class="m">— shortUuid и ключи</span></summary><div class="ux-g2">'+
   [['short','fShort','Свой shortUuid','16–64 символа. Лучше оставить панели.'],['vless','fVless','VLESS UUID',''],['trojan','fTrojan','Пароль Trojan','8–32 символа'],['ss','fSs','Пароль Shadowsocks','8–32 символа']].map(function(x){return '<div class="ux-f"><label for="'+x[1]+'">'+x[2]+'</label><input type="text" id="'+x[1]+'" class="mono" value="'+esc(F[x[0]])+'" placeholder="сгенерирует панель" spellcheck="false" autocomplete="off"><div class="ux-err" data-e="'+x[0]+'"></div>'+(x[3]?'<div class="hint2" style="font-size:.76rem;color:var(--muted)">'+x[3]+'</div>':'')+'</div>';}).join('')+'</div></details>';
  h+=fsec('shield','Прослойка',
   '<div class="ux-f"><label for="fAdd">Доп-подписка</label><input type="text" id="fAdd" class="mono" value="'+esc(F.addsub)+'" placeholder="https://…/sub/… — необязательно" spellcheck="false"><div class="ux-err" data-e="addsub"></div><div class="hint2">Серверы второй подписки подмешаются в ссылку.</div></div>'+
   '<label class="ux-opt" style="margin-top:.8rem"><input type="checkbox" class="ux-cbx" id="fNolog"'+(F.nolog?' checked':'')+'><span><b>Не писать запросы в лог</b><span class="d">Например, для своих тестовых пользователей.</span></span></label>');
 }
 h+='</div><div class="ux-df"><span class="chg" id="fChg"></span><span class="sp"></span>'+(create?'<button type="button" class="wb" data-close>Отмена</button>':'<button type="button" class="wb" id="fRevert">Сбросить</button>')+'<button type="button" class="wb pri" id="fSave" data-w="'+(create?'users:create':'users:update')+'">'+(create?'Создать':'Сохранить')+'</button></div>';
 return h;
}
function addMon(ts,m){var d=new Date(ts);if(m===2099)d.setFullYear(2099);else d.setMonth(d.getMonth()+m);return d.getTime();}
function bindForm(create){
 var root=$('uxDr'),F=CARD.F;
 function upd(){
  if(!CARD||!CARD.F)return;
  var E=errs(),allE=errs(),d=diffList(),u=CARD.u,T=CARD.touched||(CARD.touched={});
  Object.keys(E).forEach(function(k){if(create&&!CARD.tried&&!T[k])delete E[k];});
  root.querySelectorAll('[data-e]').forEach(function(el){el.textContent=E[el.dataset.e]||'';});
  var map={exp:'fExp',username:'fUser',tag:'fTag',tg:'fTg',email:'fEm',short:'fShort',vless:'fVless',trojan:'fTrojan',ss:'fSs',addsub:'fAdd'};
  Object.keys(map).forEach(function(k){var el=$(map[k]);if(el)el.classList.toggle('err',!!E[k]);});
  if(!create){var cm={exp:'fExp',tl:'fTl',ts:'fTs',ex:'fEx',tag:'fTag',tg:'fTg',email:'fEm',desc:'fDesc'};Object.keys(cm).forEach(function(k){var el=$(cm[k]);if(el)el.classList.toggle('chg',d.indexOf(k)>-1&&!E[k]);});}
  var w=$('fWill');if(w)w.innerHTML=F.exp>now()?'До <b>'+fdt(F.exp)+'</b> · '+rel(F.exp):'';
  var c=$('fChg');if(c){if(create)c.textContent=Object.keys(E).length?'Исправьте поля, отмеченные красным':'';else c.innerHTML=d.length?'Изменено: <b>'+d.length+'</b> · '+d.map(function(k){return LBL[k].toLowerCase();}).join(', '):'Нет изменений';}
  var s=$('fSave');if(s){var sc=create?'users:create':'users:update';s.disabled=!canW(sc)||(!create&&(!!Object.keys(allE).length||!d.length));if(!create)s.textContent=isGr(u)&&grTouch(d)&&CARD.restore?'Вернуть тариф и сохранить':'Сохранить';}
 }
 CARD.upd=upd;
 var on=function(id,ev,fn){var el=$(id);if(el)el.addEventListener(ev,fn);};
 var touch=function(k){if(CARD)(CARD.touched||(CARD.touched={}))[k]=1;};
 on('fUser','input',function(){F.username=this.value.trim();upd();});
 on('fUser','blur',function(){touch('username');upd();});
 on('fExp','input',function(){var t=new Date(this.value).getTime();if(!isNaN(t))F.exp=t;touch('exp');upd();});
 on('fSt','change',function(){F.status=this.checked?'ACTIVE':'DISABLED';upd();});
 var tlRead=function(){var v=parseFloat($('fTl').value),m=$('fTlU').value==='tb'?1024*GB:GB;F.tl=v>0?Math.round(v*m):0;upd();};
 on('fTl','input',tlRead);on('fTlU','change',tlRead);
 on('fTs','change',function(){F.ts=this.value;upd();});
 on('fHw','input',function(){F.hw=parseInt(this.value,10)||NaN;upd();});
 on('fEx','change',function(){F.ex=this.value;upd();});
 on('fTag','input',function(){var p=this.selectionStart;this.value=this.value.toUpperCase();this.setSelectionRange(p,p);F.tag=this.value.trim();upd();});
 on('fTag','blur',function(){touch('tag');upd();});
 on('fTg','input',function(){F.tg=this.value.trim();upd();});
 on('fTg','blur',function(){touch('tg');upd();});
 on('fEm','input',function(){F.email=this.value.trim();upd();});
 on('fEm','blur',function(){touch('email');upd();});
 on('fDesc','input',function(){F.desc=this.value;upd();});
 [['fShort','short'],['fVless','vless'],['fTrojan','trojan'],['fSs','ss'],['fAdd','addsub']].forEach(function(x){on(x[0],'input',function(){F[x[1]]=x[1]==='trojan'||x[1]==='ss'?this.value:this.value.trim();upd();});on(x[0],'blur',function(){touch(x[1]);upd();});});
 on('fNolog','change',function(){F.nolog=this.checked;});
 on('fTpl','change',function(){F.tpl=this.value;var x=bySu(this.value);if(x){var s=snapF(x);F.sq=s.sq.filter(function(q){return q!==GSQ;});F.tl=s.tl;F.ts=s.ts;F.hw=s.hw;F.ex=s.ex;F.tag=s.tag;if(isGr(x))graceF(gInfo(x),F);}var sc=$('uxForm').scrollTop;drawCard();$('uxForm').scrollTop=sc;});
 on('fRestore','change',function(){CARD.restore=this.checked;var keep={exp:F.exp,status:F.status,tag:F.tag,desc:F.desc,tg:F.tg,email:F.email};CARD.F=snapF(CARD.u);Object.keys(keep).forEach(function(k){CARD.F[k]=keep[k];});if(CARD.restore)graceF(gInfo(CARD.u),CARD.F);var sc=$('uxForm').scrollTop;drawCard();$('uxForm').scrollTop=sc;});
 on('fResetTr','change',function(){CARD.resetTr=this.checked;});
 on('fRevert','click',function(){CARD.F=null;CARD.touched={};drawCard();});
 root.querySelectorAll('[data-mon]').forEach(function(b){b.addEventListener('click',function(){var m=+b.dataset.mon,base=create||isGr(CARD.u)?now():Math.max(now(),CARD.O.exp);F.exp=addMon(base,m);$('fExp').value=dlv(F.exp);upd();});});
 root.querySelectorAll('[data-gb]').forEach(function(b){b.addEventListener('click',function(){var x=+b.dataset.gb;F.tl=x*GB;$('fTl').value=x||'';$('fTlU').value='gb';upd();});});
 root.querySelectorAll('#fHwM [data-hm]').forEach(function(b){b.addEventListener('click',function(){var m=b.dataset.hm;root.querySelectorAll('#fHwM button').forEach(function(x){x.classList.toggle('on',x===b);});$('fHwBox').style.display=m==='num'?'':'none';F.hw=m==='def'?null:(m==='off'?0:(parseInt($('fHw').value,10)||3));upd();});});
 root.querySelectorAll('#fSq input').forEach(function(i){i.addEventListener('change',function(){var v=i.value;F.sq=F.sq.filter(function(x){return x!==v;});if(i.checked)F.sq.push(v);F.sq.sort();i.closest('.ux-sqi').classList.toggle('on',i.checked);upd();});});
 on('fSave','click',function(){if(create)doCreate();else doSave();});
 upd();
}
function doSave(){
 var u=CARD.u,F=CARD.F,O=CARD.O,d=diffList(),gr=isGr(u)&&grTouch(d);if(!d.length)return;
 var notes=[];
 if(gr)notes.push(['hour','vio','Грейс завершится',CARD.restore?'прежний тариф вернётся':'сохранятся значения из формы']);
 if(gr&&CARD.resetTr)notes.push(['reset','info','Трафик обнулится','сейчас '+fb(u.used)]);
 if(!gr&&u.st==='EXPIRED'&&d.indexOf('exp')>-1&&F.exp>now()&&F.status==='ACTIVE')notes.push(['check','ok','Статус станет «Активен»','']);
 var fields={};d.forEach(function(k){fields[API[k]]=apiVal(k,F[k]);});
 cfm({ic:'save',tone:gr?'vio':'acc',title:'Сохранить изменения',sub:'<b>'+esc(u.un)+'</b> · '+d.length+' '+plural(d.length,'поле','поля','полей'),
  rows:d.map(function(k){return [FICO[k],LBL[k],k==='hw'?esc(hwEff({hw:O[k],ex:F.ex}).txt):fmtH(k,O[k]),k==='hw'?esc(hwEff({hw:F[k],ex:F.ex}).txt):fmtH(k,F[k]),k==='sq'];}),notes:notes,hint:'В панель уйдут только эти поля',ok:'Сохранить',onOk:function(){
   busy(true);
   post('ua_save',{su:u.su,fields:JSON.stringify(fields),upd:CARD.O.upd,reset:gr&&CARD.resetTr?'1':''}).then(function(r){
    busy(false);
    if(r.conflict){cfClose();putUser(r.user,r.mw);cfm({ic:'alert',tone:'warn',title:'Пользователя изменили',sub:'пока форма была открыта — например, бот или панель',notes:[['refresh','info','Форма покажет свежие данные','ваши правки нужно будет повторить']],ok:'Обновить форму',onOk:function(){cfClose();CARD.F=null;CARD.touched={};drawCard();}});render();return;}
    if(!r.ok){if(window.uiAlert)uiAlert(r.error||'Ошибка','Панель не приняла');return;}
    cfClose();putUser(r.user,r.mw);CARD.F=null;CARD.tab='ov';CARD.devs=null;CARD.hist=null;
    if(r.warn&&window.uiAlert)uiAlert(r.warn);else toast(r.grace_closed?(CARD.restore?'Тариф возвращён · ':'Грейс завершён · ')+u.un:'Сохранено · '+u.un);
    render();drawCard();
   });
  }});
}
function doCreate(){
 var F=CARD.F;if(Object.keys(errs()).length){CARD.tried=true;CARD.upd();var el=$('uxDr').querySelector('input.err');if(el){el.focus();el.scrollIntoView({block:'center'});}return;}
 var notes=[];if(F.status==='DISABLED')notes.push(['power','warn','Создаётся выключенным','']);if(F.addsub)notes.push(['link','info','Доп-подписка',esc(F.addsub)]);if(F.nolog)notes.push(['eyeoff','info','Запросы не пишутся в лог','']);
 cfm({ic:'plus',title:'Создать пользователя',sub:'<b>'+esc(F.username)+'</b> · в панели Remnawave',
  rows:[['cal','Срок',null,fdt(F.exp)],['wave','Трафик',null,esc(fmtF('tl',F.tl)+(F.tl&&F.ts!=='NO_RESET'?' · '+fmtF('ts',F.ts).toLowerCase():''))],['dev','Устройства',null,esc(hwTxt(F.hw))],['net','Сквады',null,chips(F.sq)],['link','Внешний сквад',null,fmtH('ex',F.ex)]],
  notes:notes,hint:(F.short||F.vless||F.trojan||F.ss)?'Часть ключей — свои':'shortUuid и ключи сгенерирует панель',ok:'Создать',onOk:function(){
   var f={username:F.username,expireAt:Math.round(F.exp/1000),status:F.status,trafficLimitBytes:F.tl,trafficLimitStrategy:F.ts,activeInternalSquads:F.sq};
   if(F.hw!==null)f.hwidDeviceLimit=F.hw;if(F.ex)f.externalSquadUuid=F.ex;if(F.tag)f.tag=F.tag;if(F.desc.trim())f.description=F.desc;if(F.tg)f.telegramId=F.tg;if(F.email)f.email=F.email;
   if(F.short)f.shortUuid=F.short;if(F.vless)f.vlessUuid=F.vless;if(F.trojan)f.trojanPassword=F.trojan;if(F.ss)f.ssPassword=F.ss;
   busy(true);
   post('ua_create',{fields:JSON.stringify(f),addsub:F.addsub,nolog:F.nolog?'1':''}).then(function(r){
    busy(false);
    if(!r.ok){if(window.uiAlert)uiAlert(r.error||'Ошибка','Панель не приняла');return;}
    cfClose();putUser(r.user,r.mw);CARD.done=r.user;render();drawCard();
    if(r.warn&&window.uiAlert)uiAlert(r.warn);else toast('Создан · '+r.user.un);
   });
  }});
}
function createDone(){
 var u=CARD.done;
 return '<div class="ux-dh"><div class="r1"><div class="ux-av">'+ico('check',20)+'</div><div><div class="nm">'+esc(u.un)+' '+pill(u)+'</div><div class="id">Пользователь создан в панели</div></div><button type="button" class="ux-ib x" data-close aria-label="Закрыть">'+ico('x',18)+'</button></div></div>'+
  '<div class="ux-db"><div class="ux-ok"><div class="ic">'+ico('check',26)+'</div><h3>Готово — ссылку можно отдавать</h3>'+
  (u.lk?'<p class="muted" style="margin:0;font-size:.86rem">Ссылка уже с адресом зеркала. Действует до '+fdt(expMs(u))+'.</p><div class="lnk" data-copy="'+esc(u.lk)+'" data-tip="Нажмите, чтобы скопировать">'+esc(u.lk)+'</div><div class="ux-qr"><div class="box" id="uxOkQr"></div></div>':'<p class="muted" style="margin:0;font-size:.86rem">Адрес зеркала не задан — ссылку возьмите в панели.</p>')+
  '<div style="display:flex;gap:.5rem;flex-wrap:wrap;justify-content:center">'+(u.lk?'<button type="button" class="wb pri" data-a="copy">'+ico('copy')+'Скопировать ссылку</button>':'')+'<button type="button" class="wb" data-a="opencard">'+ico('user')+'Открыть карточку</button><button type="button" class="wb" data-a="again" data-w="users:create">'+ico('plus')+'Создать ещё</button></div></div></div>';
}

document.addEventListener('click',function(e){
 var t=e.target;
 if(!t.closest)return;
 if($('uxMenu').classList.contains('open')&&!t.closest('#uxMenu')&&!t.closest('[data-act="menu"],[data-a="menu"]'))closeMenu();
 var mi=t.closest('#uxMenu [data-m]');if(mi){if(mi.disabled)return;var u0=menuFor;closeMenu();doAction(mi.dataset.m,u0);return;}
 if(!t.closest('#ux'))return;
 if(t.id==='uxCfOk'){if(CF&&CF.onOk&&!t.disabled)CF.onOk();return;}
 if(t.closest('[data-close="cf"]')||t.id==='uxCfOv'){cfClose();return;}
 if(t.closest('[data-close="qr"]')||t.id==='uxQrOv'){$('uxQrOv').classList.remove('open');return;}
 if(t.closest('[data-close="pick"]')||t.id==='uxPickOv'){closePick();return;}
 if(t.id==='uxOv'){closeCard();return;}
 var k=t.closest('.ux-kpi');if(k){S.f=S.f===k.dataset.f&&k.dataset.f!=='all'?'all':k.dataset.f;S.page=1;render();return;}
 var th=t.closest('#uxTbl th.srt');if(th){var s=th.dataset.s;if(S.sort===s)S.dir=-S.dir;else{S.sort=s;S.dir=(s==='tr')?-1:1;}render();return;}
 var dn=t.closest('.ux-flt [data-dens]');if(dn){S.dens=+dn.dataset.dens;try{localStorage.setItem('utbl_dens',String(S.dens));}catch(_){}render();return;}
 var uc=t.closest('[data-unchip]');if(uc){S[uc.dataset.unchip]='';fillSelects();S.page=1;render();return;}
 var pg=t.closest('[data-pg]');if(pg){S.page+=+pg.dataset.pg;render();return;}
 var bk=t.closest('[data-bulk]');if(bk){if(bk.disabled)return;var L=U.filter(function(u){return S.sel[u.su];}),a=bk.dataset.bulk;
  if(a==='clear'){S.sel={};render();}else if(a==='extend')pickExtend(L);else if(a==='squads')pickSquads(L);
  else if(a==='reset')cfm({ic:'reset',tone:'info',title:'Сбросить трафик',sub:nUsers(L.length),rows:[['wave','Использовано',fb(L.reduce(function(x,u){return x+u.used;},0))+' всего','0 Б']],hint:'Лимиты и сроки не меняются',ok:'Сбросить',onOk:function(){runMany(L,function(u){return ['ua_act',{su:u.su,act:'reset'}];},'Трафик сброшен');}});
  else if(a==='disable')cfm({ic:'power',tone:'bad',title:'Выключить выбранных',sub:nUsers(L.length),notes:[['ban','bad','Подписки перестанут работать','до включения']],hint:'Сроки и трафик не меняются',ok:'Выключить',danger:true,onOk:function(){runMany(L,function(u){return ['ua_act',{su:u.su,act:'disable'}];},'Выключено');}});
  else if(a==='enable')cfm({ic:'power',tone:'ok',title:'Включить выбранных',sub:nUsers(L.length),hint:'С прошедшим сроком станут «Истёк»',ok:'Включить',onOk:function(){runMany(L,function(u){return ['ua_act',{su:u.su,act:'enable'}];},'Включено');}});
  return;}
 var tr=t.closest('#uxBody tr[data-su]');
 if(tr){var u=bySu(tr.dataset.su);if(!u)return;
  if(t.closest('[data-cb]')){if(t.checked)S.sel[u.su]=1;else delete S.sel[u.su];render();return;}
  if(t.closest('.cb'))return;
  var ab=t.closest('[data-act]');if(ab){if(ab.disabled)return;if(ab.dataset.act==='menu'){if(menuFor===u)closeMenu();else openMenu(ab,u);}else doAction(ab.dataset.act,u);return;}
  openCard(u,'ov');return;}
 if(t.closest('#uxDr')){
  if(t.closest('[data-close]')){closeCard();return;}
  var cp=t.closest('[data-copy]');if(cp){copy(cp.dataset.copy,'Скопировано');return;}
  var tb=t.closest('[data-tab]');if(tb){if(tb.disabled)return;var to=tb.dataset.tab;if(CARD.tab==='edit'&&to!=='edit'&&CARD.F&&diffList().length){cfm({ic:'alert',tone:'warn',title:'Уйти без сохранения?',sub:'изменения в форме пропадут',ok:'Уйти',danger:true,onOk:function(){cfClose();CARD.F=null;CARD.tab=to;drawCard();}});return;}CARD.tab=to;if(to==='edit'){CARD.F=null;CARD.restore=true;CARD.touched={};}drawCard();return;}
  var da=t.closest('[data-a]');if(da){if(da.disabled)return;var a2=da.dataset.a,cu=CARD.create?CARD.done:CARD.u;
   if(a2==='menu'){if(menuFor)closeMenu();else openMenu(da,cu);return;}
   if(a2==='restore'){CARD.tab='edit';CARD.F=null;CARD.restore=true;CARD.touched={};drawCard();var fe=$('fExp');if(fe){fe.focus();fe.scrollIntoView({block:'center'});}return;}
   if(a2==='hwblock'){var dv=CARD.devs[+da.dataset.i],hw=String(dv.hwid||''),lc=hw.toLowerCase(),bl=C.hwb.indexOf(lc)>-1;
    cfm({ic:'ban',tone:bl?'ok':'bad',title:bl?'Снять блок устройства':'Заблокировать устройство',sub:'<b>'+esc(dv.deviceModel||dv.platform||'Устройство')+'</b> · '+esc(cu.un),notes:[bl?['check','ok','Клиент снова получит конфиг','']:['ban','bad','Клиент с этим HWID получит заглушку','снять можно здесь или в «Оверрайдах»']],hint:esc(hw),ok:bl?'Снять блок':'Заблокировать',danger:!bl,onOk:function(){busy(true);post('block_hwid',{hwid:hw,username:cu.un,block:bl?'0':'1'}).then(function(d){busy(false);if(!d.ok){if(window.uiAlert)uiAlert(d.error||'Ошибка');return;}cfClose();if(bl)C.hwb=C.hwb.filter(function(x){return x!==lc;});else C.hwb.push(lc);toast(bl?'Блок снят':'Устройство заблокировано');drawCard();});}});return;}
   if(a2==='hwdel'){var dv2=CARD.devs[+da.dataset.i];cfm({ic:'trash',tone:'bad',title:'Удалить устройство',sub:'<b>'+esc(dv2.deviceModel||dv2.platform||'Устройство')+'</b> · '+esc(cu.un),notes:[['info','info','Освободит место в лимите','клиент сможет подключиться снова']],hint:esc(dv2.hwid||''),ok:'Удалить',danger:true,onOk:function(){busy(true);post('del_hwid',{uuid:cu.rv,hwid:dv2.hwid||''}).then(function(d){busy(false);if(!d.ok){if(window.uiAlert)uiAlert(d.error||'Ошибка','Панель не приняла');return;}cfClose();toast('Устройство удалено');CARD.devs=null;drawCard();});}});return;}
   if(a2==='delall'){var list=CARD.devs.slice();cfm({ic:'trash',tone:'bad',title:'Удалить все устройства',sub:'<b>'+list.length+'</b> · '+esc(cu.un),ok:'Удалить все',danger:true,onOk:function(){cfClose();var i=0;(function next(){if(i>=list.length){toast('Устройства удалены');CARD.devs=null;drawCard();return;}post('del_hwid',{uuid:cu.rv,hwid:list[i++].hwid||''}).then(next);})();}});return;}
   if(a2==='opencard'){openCard(cu,'ov');return;}
   if(a2==='again'){openCreate();return;}
   doAction(a2,cu);return;}
 }
});
$('uxCreate').addEventListener('click',function(){if(!this.disabled)openCreate();});
$('uxQ').addEventListener('input',function(){S.q=this.value.trim().toLowerCase();S.page=1;render();});
$('uxSq').addEventListener('change',function(){S.sq=this.value;S.page=1;render();});
$('uxTag').addEventListener('change',function(){S.tag=this.value;S.page=1;render();});
document.addEventListener('change',function(e){var t=e.target;if(t.id==='uxSize'){S.size=parseInt(t.value,10);S.page=1;try{localStorage.setItem('utbl_size',String(S.size));}catch(_){}render();}if(t.id==='uxAll'){pageRows().forEach(function(u){if(t.checked)S.sel[u.su]=1;else delete S.sel[u.su];});render();}});
document.addEventListener('keydown',function(e){if(e.key!=='Escape')return;var dlg=document.getElementById('uiDlg');if(dlg&&dlg.classList.contains('open'))return;if($('uxCfOv').classList.contains('open')){cfClose();return;}if($('uxMenu').classList.contains('open')){closeMenu();return;}if($('uxPickOv').classList.contains('open')){closePick();return;}if($('uxQrOv').classList.contains('open')){$('uxQrOv').classList.remove('open');return;}if(CARD)closeCard();});
window.addEventListener('resize',closeMenu);
document.addEventListener('scroll',closeMenu,true);
fillSelects();render();
})();
    </script>
