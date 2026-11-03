<?php
$cm_on = function_exists('sess_enabled') && sess_enabled();
$cm_stale = false;
$cm_d = cmap_view(cmap_period((string) (cmap_presets()[0] ?? 1)), $cm_stale);
$cm_has = is_array($cm_d) && !empty($cm_d['U']);
$cm_keep = sess_keep_days();
$cm_js = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE;
$cm_cfg = ['on' => $cm_on, 'keep' => $cm_keep, 'presets' => cmap_presets(), 'ttl' => CMAP_TTL, 'd' => is_array($cm_d) ? ['stale' => $cm_stale] + $cm_d : null];
// Большая сводка в HTML не встраивается — её приносит сжатый ajax из того же кэша.
if ($cm_cfg['d'] !== null && strlen((string) json_encode($cm_cfg['d'], $cm_js)) > 200000) $cm_cfg['d'] = null;
?>
    <style>
    .cmw button,.cmw input,.cmw select{font-family:inherit}
    .cmw{--cm1:#5544b0;--cm2:#6b55d2;--cm3:#8a70ea;--cm4:#ab94f6;--cm5:#d2c4ff}
    html[data-theme="light"] .cmw{--cm1:#a993f2;--cm2:#8c6fe6;--cm3:#7150d6;--cm4:#5735b8;--cm5:#3e2190}
    svg.cmi{width:15px;height:15px;flex:0 0 auto;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;vertical-align:-3px}
    .cm-sub{font-weight:400;font-size:.78rem;color:var(--muted)}
    .cm-per{display:inline-flex;align-items:center;gap:.5rem;flex-wrap:wrap;position:relative}
    .cm-per .seg{margin:0}
    .cm-per .seg button{display:inline-flex;align-items:center;gap:.35rem;white-space:nowrap}
    .cm-pp{position:absolute;right:0;top:calc(100% + 6px);z-index:30;width:300px;max-width:calc(100vw - 24px);background:var(--card);border:1px solid var(--line);border-radius:12px;box-shadow:var(--shadow);padding:.85rem .9rem;display:none}
    .cm-pp.open{display:block}
    .cm-pp h4{margin:0 0 .6rem;font-size:.86rem;color:var(--text-strong);display:flex;align-items:center;gap:.4rem}
    .cm-pp .r{display:grid;grid-template-columns:1fr 1fr;gap:.5rem}
    .cm-pp label{font-size:.75rem;color:var(--muted);display:flex;flex-direction:column;gap:.25rem}
    .cm-pp input{width:100%;box-sizing:border-box}
    .cm-pp .q{display:flex;gap:.35rem;flex-wrap:wrap;margin:.6rem 0 .1rem}
    .cm-pp .q button{font-size:.75rem;padding:.25rem .55rem;min-height:0;height:auto;border-radius:999px;background:var(--bg2);border:1px solid var(--line);color:var(--text);font-weight:500}
    .cm-pp .q button:hover{border-color:var(--accent);filter:none}
    .cm-pp .f{display:flex;gap:.5rem;justify-content:flex-end;margin-top:.75rem}
    .cm-pp .e{font-size:.74rem;color:var(--c-bad-fg);margin-top:.4rem;min-height:1em}
    .cm-sel{height:36px;font-size:.82rem;width:auto;min-width:11rem;max-width:16rem}
    .cm-fresh{display:flex;gap:.45rem;align-items:flex-start;font-size:.8rem;color:var(--muted);margin:.7rem 0 1rem}
    .cm-fresh b{color:var(--text)}
    .cm-fresh svg.cmi{width:14px;height:14px;margin-top:.12rem}
    .cm-kpis{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:.7rem;margin:0 0 1rem}
    .cm-kpi{border:1px solid var(--line);background:var(--bg2);border-radius:12px;padding:.75rem .9rem;display:flex;flex-direction:column;gap:.15rem;min-height:86px}
    .cm-kpi .k{font-size:.72rem;color:var(--muted);text-transform:uppercase;letter-spacing:.04em}
    .cm-kpi .v{font-size:1.5rem;font-weight:700;color:var(--text-strong);line-height:1.2;font-variant-numeric:tabular-nums}
    .cm-kpi .d{font-size:.75rem;color:var(--muted);margin-top:auto}
    @media(max-width:1180px){.cm-kpis{grid-template-columns:repeat(3,minmax(0,1fr))}}
    @media(max-width:640px){.cm-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}.cm-kpi{min-height:0}.cm-kpi .v{font-size:1.25rem}}
    .cm-grid{display:grid;grid-template-columns:minmax(0,1fr) 340px;gap:1rem;align-items:start}
    @media(max-width:1180px){.cm-grid{grid-template-columns:1fr}}
    .cm-box{position:relative;border:1px solid var(--line);border-radius:14px;background:var(--bg2);overflow:hidden}
    .cm-mapw{position:relative;flex:1 1 auto;min-width:0;touch-action:pan-y;cursor:grab;user-select:none;-webkit-user-select:none}
    .cm-box.drag .cm-mapw{cursor:grabbing}
    .cm-box.drag .cm-ov *{pointer-events:none!important}
    .cm-mapw svg.cm-map{display:block;width:100%;height:auto;aspect-ratio:1000/540}
    .cm-box.fs{position:fixed;inset:0;z-index:89;border:0;border-radius:0}
    .cm-box.fs .cm-mapw{touch-action:none}
    .cm-box.fs svg.cm-map{width:100%;height:100%;aspect-ratio:auto}
    .cm-map path.rg{fill:var(--line);fill-opacity:.55;stroke:var(--card);stroke-width:.5;vector-effect:non-scaling-stroke;transition:fill-opacity .15s}
    .cm-map path.rg.has{cursor:pointer;fill-opacity:1}
    .cm-map path.rg.q1{fill:var(--cm1)}.cm-map path.rg.q2{fill:var(--cm2)}.cm-map path.rg.q3{fill:var(--cm3)}.cm-map path.rg.q4{fill:var(--cm4)}.cm-map path.rg.q5{fill:var(--cm5)}
    .cm-map path.rg.has:hover{fill-opacity:.82}
    .cm-map.focus path.rg{fill-opacity:.22}
    .cm-map.focus path.rg.has{fill-opacity:.28}
    .cm-map.focus path.rg.on{fill-opacity:1;stroke:var(--text-strong);stroke-width:1.6}
    .cm-map.cli path.rg.has{fill-opacity:.13}
    .cm-map.cli path.rg.on,.cm-map.cli path.rg.has.on{fill:var(--cm4);fill-opacity:1}
    html[data-theme="light"] .cm-map.cli path.rg.on,html[data-theme="light"] .cm-map.cli path.rg.has.on{fill:var(--cm3)}
    .cm-map path.cb{fill:none;stroke:var(--muted);stroke-opacity:.6;stroke-width:1;vector-effect:non-scaling-stroke;pointer-events:none}
    .cm-ov{position:absolute;inset:0;pointer-events:none}
    .cm-b{position:absolute;z-index:3;transform:translate(-50%,-50%);pointer-events:auto;border:1.5px solid var(--line);background:var(--card);color:var(--text-strong);border-radius:999px;display:flex;align-items:center;justify-content:center;font-weight:700;font-variant-numeric:tabular-nums;padding:0 .4rem;min-height:0;height:22px;min-width:22px;font-size:.7rem;line-height:1;box-shadow:0 2px 8px rgba(0,0,0,.28);cursor:pointer}
    .cm-b.m{height:26px;min-width:26px;font-size:.75rem}
    .cm-b.l{height:32px;min-width:32px;font-size:.8rem}
    .cm-b:hover,.cm-b:active{background:var(--card);transform:translate(-50%,-50%) scale(1.1);z-index:3;filter:none;border-color:var(--cm4)}
    .cm-b.sel{outline:3px solid var(--text-strong);outline-offset:1px;z-index:4}
    .cm-o{position:absolute;transform:translate(-50%,-50%);width:12px;height:12px;border-radius:99px;background:var(--cm5);border:2px solid var(--card);box-shadow:0 0 0 3px color-mix(in srgb,var(--cm4) 45%,transparent);pointer-events:auto;z-index:4}
    html[data-theme="light"] .cm-o{background:var(--cm4)}
    .cm-o .lb{position:absolute;left:12px;top:50%;transform:translateY(-50%);white-space:nowrap;font-size:.7rem;font-weight:600;color:var(--text-strong);text-shadow:0 0 3px var(--card),0 0 3px var(--card),0 0 2px var(--card)}
    .cm-o.edge{border-style:dashed}
    .cm-o .lb em{font-style:normal;font-weight:500;color:var(--muted);margin-left:.15rem}
    .cm-o.hl{transform:translate(-50%,-50%) scale(1.35)}
    .cm-zoom{position:absolute;right:.6rem;top:.6rem;z-index:6;display:flex;flex-direction:column;border:1px solid var(--line);border-radius:10px;overflow:hidden;background:var(--card);box-shadow:0 2px 10px rgba(0,0,0,.25)}
    .cm-zoom button{width:34px;height:34px;min-height:0;padding:0;border:0;border-top:1px solid var(--line);border-radius:0;background:transparent;color:var(--text);display:flex;align-items:center;justify-content:center;cursor:pointer}
    .cm-zoom button:first-child{border-top:0}
    .cm-zoom button:hover{background:var(--hover);filter:none}
    .cm-zoom button:disabled{opacity:.35;cursor:default;background:transparent}
    .cm-zoom button[hidden]{display:none}
    .cm-focus{position:absolute;left:.6rem;top:.6rem;z-index:6;display:none;align-items:center;gap:.5rem;background:var(--card);border:1px solid var(--line);border-radius:10px;padding:.3rem .35rem .3rem .65rem;box-shadow:0 2px 10px rgba(0,0,0,.25);font-size:.8rem;max-width:calc(100% - 4.5rem)}
    .cm-focus.on{display:flex}
    .cm-focus b{color:var(--text-strong);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .cm-focus button{width:26px;height:26px;min-height:0;padding:0;border:0;border-radius:7px;background:transparent;color:var(--muted);display:flex;align-items:center;justify-content:center;flex:none}
    .cm-focus button:hover{background:var(--hover);color:var(--text);filter:none}
    .cm-hint{position:absolute;left:50%;bottom:.7rem;transform:translateX(-50%);z-index:7;background:var(--card);border:1px solid var(--line);border-radius:999px;padding:.35rem .8rem;font-size:.78rem;color:var(--text);box-shadow:var(--shadow);opacity:0;pointer-events:none;transition:opacity .2s;white-space:nowrap}
    .cm-hint.on{opacity:1}
    .cm-tip{position:absolute;pointer-events:none;background:var(--card);border:1px solid var(--line);border-radius:10px;padding:.5rem .65rem;font-size:.8rem;box-shadow:0 6px 22px rgba(0,0,0,.2);display:none;z-index:8;min-width:10rem;max-width:16rem}
    .cm-tip b{color:var(--text-strong)}
    .cm-tip .m{color:var(--muted);font-size:.75rem;margin-top:.15rem}
    .cm-pop{position:absolute;width:330px;max-width:calc(100% - 16px);max-height:calc(100% - 16px);display:none;flex-direction:column;background:var(--card);border:1px solid var(--line);border-radius:14px;box-shadow:0 18px 48px rgba(0,0,0,.35);z-index:9;overflow:hidden;cursor:default}
    .cm-pop.open{display:flex}
    .cm-ph{padding:.7rem .85rem .6rem;border-bottom:1px solid var(--line);display:flex;gap:.55rem;align-items:flex-start}
    .cm-ph b{color:var(--text-strong);font-size:.95rem}
    .cm-ph .fl{font-size:1.15rem;line-height:1.2}
    .cm-ph .x{margin-left:auto;border:0;background:transparent;color:var(--muted);padding:.2rem;border-radius:6px;min-height:0;height:auto}
    .cm-ph .x:hover{background:var(--hover);filter:none}
    .cm-pb{padding:.55rem .85rem .7rem}
    .cm-h5{font-size:.7rem;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);margin:.35rem 0 .35rem;font-weight:700}
    .cm-bar{display:grid;grid-template-columns:minmax(0,1fr) 70px 2.2rem;gap:.5rem;align-items:center;font-size:.8rem;padding:.18rem 0}
    .cm-bar .t{display:flex;gap:.35rem;align-items:center;min-width:0}
    .cm-bar .t span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    .cm-bar .g{height:6px;border-radius:99px;background:var(--hover);overflow:hidden}
    .cm-bar .g i{display:block;height:100%;border-radius:99px;background:var(--accent)}
    .cm-bar .c{text-align:right;font-variant-numeric:tabular-nums;color:var(--text-strong);font-weight:600}
    .cm-bar.hov{cursor:pointer;border-radius:6px}
    .cm-bar.hov:hover{background:var(--hover2)}
    .cm-pl{border-top:1px solid var(--line);overflow:auto;min-height:0;flex:1 1 auto}
    .cm-pl .cm-crow{border-left:0;border-right:0}
    .cm-more{display:block;width:100%;border:0;border-top:1px solid var(--line);border-radius:0;background:transparent;color:var(--accent-text);font-size:.8rem;font-weight:600;padding:.55rem;min-height:0}
    .cm-more:hover{background:var(--hover2);filter:none}
    .cm-side{display:flex;flex-direction:column;gap:.6rem;min-width:0}
    .cm-sbox{border:1px solid var(--line);border-radius:14px;background:var(--bg2);overflow:hidden}
    .cm-sh{display:flex;align-items:center;gap:.5rem;padding:.65rem .8rem;border-bottom:1px solid var(--line)}
    .cm-sh b{color:var(--text-strong);font-size:.9rem;flex:1;min-width:0}
    .cm-sh .n{font-size:.78rem;color:var(--muted);font-variant-numeric:tabular-nums}
    .cm-sf{display:flex;gap:.4rem;padding:.55rem .8rem;border-bottom:1px solid var(--line)}
    .cm-sf input{flex:1;min-width:0;height:34px;font-size:.84rem}
    .cm-sf select{height:34px;font-size:.8rem;width:auto;flex:none}
    .cm-chip{display:flex;align-items:center;gap:.4rem;padding:.4rem .8rem;border-bottom:1px solid var(--line);font-size:.78rem;color:var(--muted);background:var(--card)}
    .cm-chip b{color:var(--text)}
    .cm-chip button{margin-left:auto;width:24px;height:24px;min-height:0;padding:0;border:0;border-radius:6px;background:transparent;color:var(--muted);display:flex;align-items:center;justify-content:center}
    .cm-chip button:hover{background:var(--hover);filter:none;color:var(--text)}
    .cm-crow{display:flex;gap:.6rem;padding:.55rem .8rem;border:0;border-bottom:1px solid var(--line);align-items:center;cursor:pointer;width:100%;text-align:left;background:transparent;color:var(--text);border-radius:0;min-height:0;height:auto;font-weight:400}
    .cm-crow:hover,.cm-crow:active{background:var(--hover2);filter:none}
    .cm-crow:last-child{border-bottom:0}
    .cm-crow .av{width:30px;height:30px;border-radius:9px;background:var(--c-violet-bg);color:var(--c-violet-fg);display:flex;align-items:center;justify-content:center;flex:0 0 auto;font-size:.72rem;font-weight:700;text-transform:uppercase}
    .cm-crow .bd{flex:1;min-width:0}
    .cm-crow .nm{font-weight:600;color:var(--text-strong);font-size:.86rem;display:flex;gap:.4rem;align-items:baseline;min-width:0}
    .cm-crow .nm span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    .cm-crow .nm code{font-weight:400;color:var(--muted);font-size:.72rem;background:none;padding:0;border:0;white-space:nowrap}
    .cm-crow .sub{display:block;font-size:.74rem;color:var(--muted);margin-top:.1rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .cm-crow .ct{font-variant-numeric:tabular-nums;font-weight:700;color:var(--text-strong);font-size:.85rem;text-align:right;flex:none}
    .cm-crow .ct small{display:block;font-weight:400;color:var(--muted);font-size:.68rem}
    .cm-pg{display:flex;align-items:center;justify-content:space-between;gap:.5rem;padding:.45rem .6rem;border-top:1px solid var(--line);font-size:.78rem;color:var(--muted)}
    .cm-pg button{width:30px;height:30px;min-height:0;padding:0;border:1px solid var(--line);border-radius:8px;background:var(--card);color:var(--text);display:flex;align-items:center;justify-content:center}
    .cm-pg button:hover{filter:none;border-color:var(--accent)}
    .cm-pg button:disabled{opacity:.4;cursor:default;border-color:var(--line)}
    .cm-srow{display:flex;align-items:center;gap:.55rem;padding:.5rem .8rem;border:0;border-bottom:1px solid var(--line);cursor:pointer;background:transparent;color:var(--text);width:100%;text-align:left;border-radius:0;min-height:0;height:auto;font-weight:400}
    .cm-srow:last-child{border-bottom:0}
    .cm-srow:hover,.cm-srow:active{background:var(--hover2);filter:none}
    .cm-srow.on,.cm-srow.on:hover{background:var(--accent-light)}
    .cm-srow .fl{font-size:1rem;flex:none}
    .cm-srow .n{flex:1;min-width:0;font-size:.85rem}
    .cm-srow .n small{display:block;color:var(--muted);font-size:.72rem}
    .cm-srow .c{font-variant-numeric:tabular-nums;font-weight:600;font-size:.85rem;color:var(--text-strong)}
    .cm-empty{padding:.7rem .8rem;font-size:.8rem;color:var(--muted)}
    .cm-dh{padding:.7rem .8rem .75rem;border-bottom:1px solid var(--line)}
    .cm-dh .top{display:flex;align-items:center;gap:.4rem;margin-bottom:.55rem}
    .cm-back{display:inline-flex;align-items:center;gap:.3rem;border:0;background:transparent;color:var(--muted);font-size:.8rem;padding:.25rem .4rem .25rem .2rem;border-radius:7px;min-height:0;height:auto;font-weight:500}
    .cm-back:hover{background:var(--hover);color:var(--text);filter:none}
    .cm-open{margin-left:auto;display:inline-flex;align-items:center;gap:.3rem;font-size:.78rem;color:var(--accent-text);text-decoration:none;font-weight:600;padding:.25rem .4rem;border-radius:7px}
    .cm-open:hover{background:var(--hover)}
    .cm-dh .nm{display:flex;gap:.6rem;align-items:center}
    .cm-dh .nm .av{width:36px;height:36px;border-radius:10px;background:var(--c-violet-bg);color:var(--c-violet-fg);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.8rem;text-transform:uppercase;flex:none}
    .cm-dh .nm b{display:block;color:var(--text-strong);font-size:1rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    .cm-dh .nm code{font-size:.74rem;color:var(--muted);background:none;border:0;padding:0}
    .cm-dh .ls{font-size:.76rem;color:var(--muted);margin-top:.45rem}
    .cm-tiles{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.4rem;padding:.65rem .8rem;border-bottom:1px solid var(--line)}
    .cm-tile{background:var(--card);border:1px solid var(--line);border-radius:10px;padding:.4rem .5rem}
    .cm-tile .v{font-weight:700;color:var(--text-strong);font-size:1.05rem;font-variant-numeric:tabular-nums;line-height:1.2}
    .cm-tile .k{font-size:.66rem;color:var(--muted);text-transform:uppercase;letter-spacing:.03em}
    .cm-act{padding:.6rem .8rem .7rem;border-bottom:1px solid var(--line)}
    .cm-hist{display:grid;gap:2px;margin-top:.2rem}
    .cm-hist i{height:16px;border-radius:3px;background:var(--hover)}
    .cm-hist i.a1{background:color-mix(in srgb,var(--accent) 35%,transparent)}
    .cm-hist i.a2{background:color-mix(in srgb,var(--accent) 65%,transparent)}
    .cm-hist i.a3{background:var(--accent)}
    .cm-hx{display:flex;justify-content:space-between;font-size:.66rem;color:var(--muted);margin-top:.25rem;font-variant-numeric:tabular-nums}
    .cm-sec{padding:.55rem .8rem .65rem;border-bottom:1px solid var(--line)}
    .cm-sec:last-child{border-bottom:0}
    .cm-rg{border:1px solid var(--line);border-radius:10px;background:var(--card);margin-top:.45rem;overflow:hidden}
    .cm-rg.hl{border-color:var(--cm4)}
    .cm-rgh{display:flex;gap:.5rem;align-items:center;padding:.45rem .6rem;cursor:pointer}
    .cm-rgh:hover{background:var(--hover2)}
    .cm-rgh .fl{font-size:1rem}
    .cm-rgh .t{flex:1;min-width:0}
    .cm-rgh .t b{display:block;font-size:.84rem;color:var(--text-strong);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    .cm-rgh .t small{display:block;font-size:.72rem;color:var(--muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    .cm-rgh .h{font-size:.74rem;color:var(--muted);font-variant-numeric:tabular-nums;white-space:nowrap}
    .cm-ip{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:.15rem .5rem;padding:.4rem .6rem;border-top:1px solid var(--line);font-size:.78rem;align-items:center}
    .cm-ip code{font-size:.76rem;background:none;border:0;padding:0;color:var(--text);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    .cm-ip .w{font-size:.72rem;color:var(--muted);white-space:nowrap;font-variant-numeric:tabular-nums}
    .cm-ip .ns{grid-column:1/-1;display:flex;gap:.3rem;flex-wrap:wrap}
    .cm-nc{display:inline-flex;align-items:center;gap:.3rem;font-size:.7rem;padding:.08rem .45rem;border-radius:999px;background:var(--accent-light);color:var(--accent-text);font-weight:600;white-space:nowrap}
    .cm-nc::before{content:"";width:6px;height:6px;background:var(--accent);transform:rotate(45deg);border-radius:1px}
    .cm-legend{display:flex;gap:.4rem 1.1rem;flex-wrap:wrap;align-items:center;margin-top:.6rem;font-size:.76rem;color:var(--muted)}
    .cm-legend .sc{display:inline-flex;align-items:center;gap:.4rem}
    .cm-legend .sc span{display:inline-flex}
    .cm-legend .sw{display:flex;gap:2px}
    .cm-legend .sw em{font-style:normal;display:flex;flex-direction:column;align-items:center;gap:.15rem;font-size:.68rem;font-variant-numeric:tabular-nums}
    .cm-legend .s{display:inline-flex;align-items:center;gap:.35rem}
    .cm-legend .s .og{width:9px;height:9px;border-radius:99px;background:var(--cm5)}
    html[data-theme="light"] .cm-legend .s .og{background:var(--cm4)}
    .cm-legend .sw em i{width:34px;height:10px;display:block}
    .cm-legend .sw em i.q1{background:var(--cm1)}.cm-legend .sw em i.q2{background:var(--cm2)}.cm-legend .sw em i.q3{background:var(--cm3)}.cm-legend .sw em i.q4{background:var(--cm4)}.cm-legend .sw em i.q5{background:var(--cm5)}
    .cm-legend .sw em i.f{border-radius:3px 0 0 3px}.cm-legend .sw em i.l{border-radius:0 3px 3px 0}.cm-legend .sw em i.f.l{border-radius:3px}
    .cm-of{font-size:.9rem;color:var(--muted);font-weight:500}
    .cm-bar .g i.v{background:var(--cm3)}
    .cm-pv{font-size:.7rem;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:100%}
    .cmw.busy .cm-kpis,.cmw.busy .cm-grid{opacity:.55;pointer-events:none;transition:opacity .2s}
    .cm-off{display:flex;gap:.6rem;align-items:flex-start;font-size:.84rem;margin:0 0 1rem}
    .cm-hist i{cursor:default}
    .cm-note{font-size:.78rem;color:var(--muted);margin:.55rem 0 0;line-height:1.5}
    @media(max-width:640px){
        .cm-per{width:100%}
        .cm-per .seg{display:flex;width:100%}
        .cm-per .seg button{flex:1;justify-content:center;padding:.45rem .4rem}
        .cm-sel{width:100%}
        .cm-pop{left:8px!important;right:8px;width:auto;max-width:none}
        .cm-zoom button{width:30px;height:30px}
    }
    </style>

    <svg width="0" height="0" style="position:absolute" aria-hidden="true">
        <symbol id="cmi-clock" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></symbol>
        <symbol id="cmi-cal" viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/></symbol>
        <symbol id="cmi-plus" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></symbol>
        <symbol id="cmi-minus" viewBox="0 0 24 24"><path d="M5 12h14"/></symbol>
        <symbol id="cmi-reset" viewBox="0 0 24 24"><path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/></symbol>
        <symbol id="cmi-full" viewBox="0 0 24 24"><path d="M15 3h6v6M9 21H3v-6M21 3l-7 7M3 21l7-7"/></symbol>
        <symbol id="cmi-x" viewBox="0 0 24 24"><path d="M18 6 6 18M6 6l12 12"/></symbol>
        <symbol id="cmi-back" viewBox="0 0 24 24"><path d="m15 18-6-6 6-6"/></symbol>
        <symbol id="cmi-next" viewBox="0 0 24 24"><path d="m9 18 6-6-6-6"/></symbol>
        <symbol id="cmi-ext" viewBox="0 0 24 24"><path d="M15 3h6v6M10 14 21 3M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/></symbol>
        <symbol id="cmi-users" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></symbol>
        <symbol id="cmi-globe" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/></symbol>
        <symbol id="cmi-pin" viewBox="0 0 24 24"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></symbol>
    </svg>

    <div class="seg">
        <a href="?tab=users">Список</a>
        <a class="on" href="?tab=users&amp;view=map">Карта подключений</a>
    </div>

<?php if (!$cm_on && !$cm_has): ?>
    <div class="card">
        <div class="loghead"><h2>Карта подключений</h2></div>
        <p class="muted" style="margin:.7rem 0 0">Карта строится из «Сессий» — включите сбор в блоке <a href="?tab=users#uxSess">«Сессии»</a> на вкладке «Список». Первые данные появятся после нескольких опросов нод.</p>
    </div>
<?php else: ?>
    <div class="card cmw" id="cmCard">
        <div class="loghead">
            <h2>Карта подключений <span class="cm-sub" id="cmPerT">за 24 часа</span></h2>
            <div class="loghead-r">
                <div class="cm-per">
                    <div class="seg" id="cmPer">
<?php foreach (cmap_presets() as $__i => $__d): ?>
                        <button type="button"<?= $__i === 0 ? ' class="on"' : '' ?> data-p="<?= (int) $__d ?>"><?= $__d === 1 ? '24 часа' : (int) $__d . ' дней' ?></button>
<?php endforeach; ?>
                        <button type="button" data-p="c" id="cmPerC" aria-haspopup="dialog" data-tip="Свой период"><svg class="cmi" width="15" height="15"><use href="#cmi-cal"/></svg><span>Период</span></button>
                    </div>
                    <div class="cm-pp" id="cmPP" role="dialog" aria-label="Свой период">
                        <h4><svg class="cmi" width="15" height="15"><use href="#cmi-cal"/></svg>Свой период</h4>
                        <div class="r">
                            <label for="cmFrom">С<input type="date" id="cmFrom"></label>
                            <label for="cmTo">По<input type="date" id="cmTo"></label>
                        </div>
                        <div class="q" id="cmPQ">
                            <button type="button" data-q="y">Вчера</button>
                            <button type="button" data-q="w">Прошлая неделя</button>
                            <button type="button" data-q="m">Этот месяц</button>
                        </div>
                        <div class="e" id="cmPE"></div>
                        <div class="f">
                            <button type="button" class="btn ghost" id="cmPX">Отмена</button>
                            <button type="button" class="btn" id="cmPOk">Показать</button>
                        </div>
                    </div>
                </div>
                <select class="cm-sel" id="cmNode" aria-label="Нода"><option value="-1">Все ноды</option></select>
            </div>
        </div>
        <p class="cm-fresh" id="cmFresh"></p>
<?php if (!$cm_on): ?>
        <div class="warn cm-off">Сбор «Сессий» выключен — новых данных нет, показана накопленная история. Включить можно в блоке <a href="?tab=users#uxSess">«Сессии»</a> на вкладке «Список».</div>
<?php endif; ?>

        <div class="cm-kpis" id="cmKpi"></div>

        <div class="cm-grid">
            <div>
                <div class="cm-box" id="cmBox">
                    <div class="cm-mapw" id="cmMapW">
                    <svg class="cm-map" id="cmMap" viewBox="0 0 1000 540" xmlns="http://www.w3.org/2000/svg"><g id="cmGR"></g><g id="cmGC"></g></svg>
                    <div class="cm-ov" id="cmOv"></div>
                    <div class="cm-focus" id="cmFocus"><span class="cm-fl" id="cmFocusF"></span><b id="cmFocusT"></b><button type="button" id="cmFocusX" aria-label="Снять выделение" data-tip="Снять выделение"><svg class="cmi" width="15" height="15"><use href="#cmi-x"/></svg></button></div>
                    <div class="cm-zoom">
                        <button type="button" id="cmZin" aria-label="Приблизить" data-tip="Приблизить"><svg class="cmi" width="15" height="15"><use href="#cmi-plus"/></svg></button>
                        <button type="button" id="cmZout" aria-label="Отдалить" data-tip="Отдалить" disabled><svg class="cmi" width="15" height="15"><use href="#cmi-minus"/></svg></button>
                        <button type="button" id="cmZreset" aria-label="Вся карта" data-tip="Вся карта" hidden><svg class="cmi" width="15" height="15"><use href="#cmi-reset"/></svg></button>
                        <button type="button" id="cmZfs" aria-label="Во весь экран" data-tip="Во весь экран"><svg class="cmi" width="15" height="15"><use href="#cmi-full"/></svg></button>
                    </div>
                    <div class="cm-tip" id="cmTip"></div>
                    <div class="cm-pop" id="cmPop" role="dialog"></div>
                    <div class="cm-hint" id="cmHint">Ctrl + колесо — масштаб</div>
                    </div>
                </div>
                <div class="cm-legend" id="cmLeg"></div>
                <p class="cm-note">Регион — по адресу клиента (ipinfo.io или база городов). У мобильных операторов адрес часто числится в городе, где оператор выходит в интернет, а не там, где абонент. История хранится столько же, сколько сессии — <?= (int) $cm_keep ?> <?= ($cm_keep % 10 === 1 && $cm_keep % 100 !== 11) ? 'день' : (($cm_keep % 10 >= 2 && $cm_keep % 10 <= 4 && ($cm_keep % 100 < 10 || $cm_keep % 100 >= 20)) ? 'дня' : 'дней') ?>.</p>
            </div>
            <div class="cm-side" id="cmSide"></div>
        </div>
    </div>

    <script>window.CMAP_CFG = <?= json_encode($cm_cfg, $cm_js) ?>;</script>
    <script src="assets/cis.js?v=<?= substr(@md5_file(__DIR__ . '/../assets/cis.js') ?: '0', 0, 10) ?>"></script>
    <script src="assets/cisname.js?v=<?= substr(@md5_file(__DIR__ . '/../assets/cisname.js') ?: '0', 0, 10) ?>"></script>
    <script>
    (function () {
        'use strict';
        var M = window.SUBMW_CIS, C = window.CMAP_CFG, $ = function (id) { return document.getElementById(id); };
        if (!M || !C || !$('cmBox')) return;
        var NS = 'http://www.w3.org/2000/svg', DAY = 86400;
        var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]; }); };
        var plural = function (n, a, b, c) { var x = n % 10, y = n % 100; return x === 1 && y !== 11 ? a : (x >= 2 && x <= 4 && (y < 10 || y >= 20) ? b : c); };
        var fmt = function (n) { return Number(n).toLocaleString('ru-RU'); };
        var flag = function (cc) { return /^[A-Z]{2}$/.test(cc || '') ? String.fromCodePoint(127397 + cc.charCodeAt(0), 127397 + cc.charCodeAt(1)) : ''; };
        var nflag = function (cc, nm) { return /^\uD83C[\uDDE6-\uDDFF]/.test(String(nm || '')) ? '' : flag(cc) + ' '; };
        var dn = null; try { dn = new Intl.DisplayNames(['ru'], {type: 'region'}); } catch (e) {}
        var ccName = function (cc) { try { return dn && cc ? dn.of(cc) : cc; } catch (e) { return cc; } };
        var p2 = function (n) { return (n < 10 ? '0' : '') + n; };
        var nowS = function () { return Math.floor(Date.now() / 1000); };
        var tFmt = function (t, f) { var d = new Date(t * 1000); return f === 't' ? p2(d.getHours()) + ':' + p2(d.getMinutes()) : p2(d.getDate()) + '.' + p2(d.getMonth() + 1) + (f === 'd' ? '' : ' ' + p2(d.getHours()) + ':' + p2(d.getMinutes())); };
        var ago = function (t) { var s = Math.max(0, nowS() - t); return s < 3600 ? Math.max(1, Math.round(s / 60)) + ' мин назад' : s < 86400 ? Math.round(s / 3600) + ' ч назад' : Math.round(s / 86400) + ' дн назад'; };
        var dur = function (s) { return s < 3600 ? Math.max(1, Math.round(s / 60)) + ' мин' : Math.round(s / 3600) + ' ч'; };
        var ico = function (id) { return '<svg class="cmi"><use href="#cmi-' + id + '"/></svg>'; };
        var locTs = function (root) { (root || document).querySelectorAll('.cm-ts[data-ts]').forEach(function (e) { var t = +e.getAttribute('data-ts'); if (t) e.textContent = tFmt(t, e.getAttribute('data-f')); }); };
        var getj = function (q) { return fetch('?ajax=' + q, {credentials: 'same-origin', cache: 'no-store'}).then(function (r) { return r.json(); }).catch(function () { return {ok: false, error: 'Нет связи с сервером'}; }); };

        var CISCC = {};
        Object.keys(M.r).forEach(function (id) { CISCC[M.r[id].cc] = 1; });
        var regionOf = function (cc, sub) { return CISCC[cc] && window.cisRegionByName ? window.cisRegionByName(M, cc, sub) : null; };

        var D = null, NODES = [], REG = [], CL = [], AG = null, DET = null, detSeq = 0, loadSeq = 0, retryN = 0, retryT = 0;
        var st = {p: String(C.presets[0] || 1), f: 0, t: 0, node: -1, client: -1, region: null, ab: null, page: 0, q: '', sort: 'ip', cc: '', rgf: null, vb: [0, 0, 1000, 540], hovRg: null};

        var setData = function (d) {
            D = d;
            NODES = d.N.map(function (n, i) { return {k: i, nm: n[0], cc: n[1], full: n[2], uuid: n[3]}; });
            REG = d.R.map(function (r) { return {cc: r[0], sub: r[1], rg: regionOf(r[0], r[1]), cis: !!CISCC[r[0]]}; });
            CL = d.U.map(function (u, i) { return {i: i, uid: u[0], nm: u[1] || '#' + u[0], su: u[2], ip: u[3], last: u[4], g: []}; });
            d.G.forEach(function (g) { if (CL[g[0]]) CL[g[0]].g.push(g); });
            if (st.node >= NODES.length) st.node = -1;
        };
        var regKey = function (R) { return R.rg || (R.cis ? 'nr:' + R.cc : 'ab:' + R.cc + '|' + R.sub); };
        var clientStat = function (c) {
            var by = {}, out = {links: [], rg: {}, prov: {}, nodes: {}, secs: 0, cc: {}};
            c.g.forEach(function (g) {
                if (st.node >= 0 && g[2] !== st.node) return;
                var R = REG[g[1]], key = regKey(R), L = by[key];
                if (!L) { L = by[key] = {key: key, R: R, nodes: {}, prov: {}, secs: 0}; out.links.push(L); }
                L.nodes[g[2]] = (L.nodes[g[2]] || 0) + g[5]; L.secs += g[5];
                if (g[3] > 0) { L.prov[g[3]] = 1; out.prov[g[3]] = 1; }
                out.nodes[g[2]] = (out.nodes[g[2]] || 0) + g[5]; out.secs += g[5];
                out.rg[key] = 1; out.cc[R.cc] = 1;
            });
            return out.links.length ? out : null;
        };
        var aggregate = function () {
            var RB = {}, OUT = {}, NOREG = {}, ips = 0, prov = {}, nodes = {}, list = [], ccs = {};
            CL.forEach(function (c) {
                var s = clientStat(c); c.s = s; if (!s) return;
                list.push(c); ips += c.ip;
                Object.keys(s.cc).forEach(function (x) { ccs[x] = 1; });
                Object.keys(s.prov).forEach(function (a) { prov[a] = 1; });
                Object.keys(s.nodes).forEach(function (k) { (nodes[k] || (nodes[k] = {}))[c.i] = 1; });
                s.links.forEach(function (L) {
                    var R = L.R, bag = R.rg ? (RB[R.rg] || (RB[R.rg] = {cl: {}})) : !R.cis ? (OUT[R.cc] || (OUT[R.cc] = {cl: {}, pl: {}})) : (NOREG[R.cc] || (NOREG[R.cc] = {cl: {}}));
                    bag.cl[c.i] = 1;
                    if (!R.cis && R.sub) bag.pl[R.sub] = 1;
                });
            });
            [RB, OUT, NOREG].forEach(function (o) { Object.keys(o).forEach(function (k) { o[k].n = Object.keys(o[k].cl).length; }); });
            var mx = 0; Object.keys(RB).forEach(function (id) { if (RB[id].n > mx) mx = RB[id].n; });
            AG = {RB: RB, OUT: OUT, NOREG: NOREG, list: list, ips: ips, prov: Object.keys(prov).length, nodes: nodes, cc: Object.keys(ccs).length, br: breaks(mx)};
        };
        var NICE = [1, 2, 3, 4, 5, 6, 8, 10, 12, 15, 20, 25, 30, 40, 50, 60, 75, 100, 120, 150, 200, 250, 300, 400, 500, 600, 750, 1000, 1500, 2000, 2500, 3000, 5000, 7500, 10000, 15000, 20000, 50000];
        var breaks = function (mx) {
            if (mx <= 5) return [1, 2, 3, 4, 5].slice(0, Math.max(1, mx)).map(function (v) { return [v, v]; });
            var t = [1], f = Math.pow(mx, 1 / 5);
            for (var i = 1; i < 5; i++) { var v = Math.pow(f, i), nv = NICE.filter(function (x) { return x >= v; })[0] || v; if (nv <= t[t.length - 1]) nv = t[t.length - 1] + 1; t.push(Math.round(nv)); }
            var out = [];
            for (i = 0; i < 5; i++) { var lo = i === 0 ? 1 : t[i - 1] + 1, hi = i === 4 ? Infinity : t[i]; out.push([lo, hi]); }
            return out;
        };
        var qOf = function (i) { return AG.br.length === 5 ? i + 1 : Math.min(5, Math.round((i + 1) * 5 / AG.br.length)); };
        var bucket = function (n) { for (var i = 0; i < AG.br.length; i++) if (n <= AG.br[i][1]) return qOf(i); return 5; };
        var brLabel = function (b) { return b[1] === Infinity ? b[0] + '+' : b[0] === b[1] ? String(b[0]) : b[0] + '–' + b[1]; };

        var gR = $('cmGR'), gC = $('cmGC'), PATHS = {};
        Object.keys(M.r).forEach(function (id) { var p = document.createElementNS(NS, 'path'); p.setAttribute('d', M.r[id].d); p.setAttribute('class', 'rg'); p.dataset.id = id; gR.appendChild(p); PATHS[id] = p; });
        Object.keys(M.c || {}).forEach(function (cc) { var p = document.createElementNS(NS, 'path'); p.setAttribute('d', M.c[cc]); p.setAttribute('class', 'cb'); gC.appendChild(p); });

        var cur = function () { return st.client >= 0 && CL[st.client] && CL[st.client].s ? CL[st.client] : null; };
        var paint = function () {
            var c = cur(), on = {};
            if (c && st.hovRg) on[st.hovRg] = 1;
            else if (c) c.s.links.forEach(function (L) { if (L.R.rg) on[L.R.rg] = 1; });
            if (st.region) on[st.region] = 1;
            $('cmMap').classList.toggle('focus', !!(c || st.region || st.ab)); $('cmMap').classList.toggle('cli', !!c);
            Object.keys(PATHS).forEach(function (id) {
                var b = AG.RB[id], cl = 'rg';
                if (b) cl += ' has q' + bucket(b.n);
                if (on[id]) cl += ' on';
                PATHS[id].setAttribute('class', cl);
            });
        };

        var box = $('cmBox'), mw = $('cmMapW'), ov = $('cmOv');
        var asp = function () { if (!box.classList.contains('fs')) return 540 / 1000; var b = mw.getBoundingClientRect(); return b.width > 0 && b.height > 0 ? b.height / b.width : .54; };
        var pct = function (x, y) { var vb = st.vb; return 'left:' + ((x - vb[0]) / vb[2] * 100).toFixed(3) + '%;top:' + ((y - vb[1]) / vb[3] * 100).toFixed(3) + '%'; };
        var scale = function () { var b = mw.getBoundingClientRect(); return (b.width || 1000) / st.vb[2]; };
        var clusters = function () {
            var k = scale(), items = Object.keys(AG.RB).map(function (id) { return {ids: [id], x: M.r[id].x, y: M.r[id].y, cl: Object.assign({}, AG.RB[id].cl)}; });
            items.sort(function (a, b) { return Object.keys(b.cl).length - Object.keys(a.cl).length; });
            var out = [];
            items.forEach(function (it) {
                var hit = null;
                out.forEach(function (c) { if (!hit && Math.hypot((c.x - it.x) * k, (c.y - it.y) * k) < 26) hit = c; });
                if (hit) { hit.ids.push(it.ids[0]); Object.assign(hit.cl, it.cl); } else out.push(it);
            });
            out.forEach(function (c) { c.n = Object.keys(c.cl).length; });
            return out;
        };
        var detIps = function (key) {
            if (!DET || !cur() || DET.uid !== cur().uid) return null;
            var g = DET.G[key]; return g ? Object.keys(g.ips).length : null;
        };
        var drawOv = function () {
            var h = '', c = cur();
            if (c) {
                c.s.links.forEach(function (L) {
                    if (!L.R.rg) return;
                    var n = detIps(L.key);
                    h += '<span class="cm-o' + (st.hovRg === L.key ? ' hl' : '') + '" style="' + pct(M.r[L.R.rg].x, M.r[L.R.rg].y) + '"><span class="lb">' + flag(L.R.cc) + ' ' + esc(M.r[L.R.rg].n) + (n ? ' <em>' + n + ' IP</em>' : '') + '</span></span>';
                });
            } else if (!st.ab) {
                clusters().forEach(function (cl) {
                    var sz = cl.n >= 25 ? 'l' : cl.n >= 6 ? 'm' : '', sel = st.region && cl.ids.indexOf(st.region) >= 0 ? ' sel' : '';
                    h += '<button type="button" class="cm-b ' + sz + sel + '" style="' + pct(cl.x, cl.y) + '" data-ids="' + cl.ids.join(',') + '" aria-label="' + esc(cl.ids.map(function (id) { return M.r[id].n; }).join(', ')) + ': ' + cl.n + '">' + cl.n + '</button>';
                });
            }
            ov.innerHTML = h;
            placePop();
        };

        var perText = function () {
            if (st.p === 'c') return tFmt(Math.max(st.f, minTs()), 'd') + ' – ' + tFmt(Math.min(st.t, nowS()), 'd');
            var d = +st.p; return d === 1 ? 'за 24 часа' : 'за ' + d + ' ' + plural(d, 'день', 'дня', 'дней');
        };
        var kpis = function () {
            var cl = AG.list.length, rgN = Object.keys(AG.RB).length, outN = Object.keys(AG.OUT).reduce(function (s, k) { return s + AG.OUT[k].n; }, 0), nn = Object.keys(AG.nodes).length;
            var K = [
                ['Клиентов', fmt(cl), perText()],
                ['IP-адресов', fmt(AG.ips), cl ? 'в среднем ' + (AG.ips / cl).toFixed(1).replace('.', ',') + ' на клиента' : '—'],
                ['Регионов', fmt(rgN), AG.cc + ' ' + plural(AG.cc, 'страна', 'страны', 'стран') + (outN ? ', ' + outN + ' ' + plural(outN, 'клиент', 'клиента', 'клиентов') + ' вне СНГ' : '')],
                ['Провайдеров', fmt(AG.prov), 'по номеру AS'],
                ['Нод', nn + (D.nk ? ' <span class="cm-of">из ' + D.nk + '</span>' : ''), st.node >= 0 ? 'фильтр: ' + esc(NODES[st.node].nm) : 'принимали подключения']
            ];
            $('cmKpi').innerHTML = K.map(function (x) { return '<div class="cm-kpi"><span class="k">' + x[0] + '</span><span class="v">' + x[1] + '</span><span class="d">' + x[2] + '</span></div>'; }).join('');
        };
        var legend = function () {
            var cols = AG.br.map(function (b, i) { return '<em><i class="q' + qOf(i) + (i === 0 ? ' f' : '') + (i === AG.br.length - 1 ? ' l' : '') + '"></i>' + brLabel(b) + '</em>'; }).join('');
            $('cmLeg').innerHTML = '<span class="sc">Клиентов в регионе<span class="sw">' + cols + '</span></span><span class="s"><span class="og"></span>откуда подключался выбранный клиент</span>';
        };
        var fresh = function () {
            var el = $('cmFresh');
            if (!D) { el.innerHTML = ''; return; }
            el.innerHTML = ico('clock') + '<span>Обновлено <b class="cm-ts" data-ts="' + D.ts + '" data-f="dt"></b>' + (D.stale ? (retryN < RETRY.length ? ', сейчас пересчитывается' : ', свежая сводка появится при следующем открытии') : ', следующее — не раньше <b class="cm-ts" data-ts="' + (D.ts + C.ttl) + '" data-f="t"></b>') + '. Сводка строится из «Сессий».</span>';
            locTs(el);
        };

        var initials = function (nm) { var p = String(nm).replace(/[^a-zа-яё]+/gi, ' ').trim().split(' '); return ((p[0] || '?').slice(0, 1) + (p[1] ? p[1].slice(0, 1) : (p[0] || '').slice(1, 2))) || '?'; };
        var regCount = function (s) { return Object.keys(s.rg).length; };
        var regName = function (R) { return R.rg ? M.r[R.rg].n : R.cis ? (R.sub || 'Регион не определён') : (R.sub || ccName(R.cc)); };
        var sideList = function () {
            var list = AG.list.slice(), q = st.q.toLowerCase();
            if (st.rgf) list = list.filter(function (c) { return c.s.links.some(function (L) { return L.R.rg === st.rgf; }); });
            if (st.cc) list = list.filter(function (c) { return c.s.links.some(function (L) { return !L.R.cis && L.R.cc === st.cc; }); });
            if (q) list = list.filter(function (c) { return c.nm.toLowerCase().indexOf(q) >= 0 || String(c.su).toLowerCase().indexOf(q) >= 0 || String(c.uid) === q; });
            var sorters = {ip: function (a, b) { return b.ip - a.ip || regCount(b.s) - regCount(a.s); }, rg: function (a, b) { return regCount(b.s) - regCount(a.s) || b.ip - a.ip; }, hr: function (a, b) { return b.s.secs - a.s.secs; }, nm: function (a, b) { return a.nm.localeCompare(b.nm); }};
            list.sort(sorters[st.sort]);
            var per = 10, pages = Math.max(1, Math.ceil(list.length / per)); if (st.page >= pages) st.page = pages - 1;
            var rows = list.slice(st.page * per, st.page * per + per).map(function (c) {
                var s = c.s, ccs = Object.keys(s.cc).map(flag).join(' '), rn = regCount(s), nn = Object.keys(s.nodes).length;
                return '<button type="button" class="cm-crow" data-c="' + c.i + '"><span class="av">' + esc(initials(c.nm)) + '</span><span class="bd"><span class="nm"><span>' + esc(c.nm) + '</span>' + (c.su ? '<code>' + esc(String(c.su).slice(0, 8)) + '</code>' : '') + '</span>'
                    + '<span class="sub">' + ccs + ' ' + rn + ' ' + plural(rn, 'регион', 'региона', 'регионов') + ' · ' + nn + ' ' + plural(nn, 'нода', 'ноды', 'нод') + ' · ' + dur(s.secs) + '</span></span>'
                    + '<span class="ct">' + c.ip + '<small>IP</small></span></button>';
            }).join('') || '<div class="cm-empty">' + (AG.list.length ? 'Никого не нашлось.' : 'За этот период подключений нет.') + '</div>';
            var h = '<div class="cm-sbox"><div class="cm-sh">' + ico('users') + '<b>Клиенты</b><span class="n">' + fmt(list.length) + '</span></div>'
                + '<div class="cm-sf"><input type="text" inputmode="search" autocomplete="off" id="cmQ" placeholder="Имя или shortUuid" value="' + esc(st.q) + '" aria-label="Поиск клиента">'
                + '<select id="cmSort" aria-label="Сортировка"><option value="ip"' + (st.sort === 'ip' ? ' selected' : '') + '>Больше IP</option><option value="rg"' + (st.sort === 'rg' ? ' selected' : '') + '>Больше регионов</option><option value="hr"' + (st.sort === 'hr' ? ' selected' : '') + '>Дольше в сети</option><option value="nm"' + (st.sort === 'nm' ? ' selected' : '') + '>По имени</option></select></div>'
                + (st.rgf ? '<div class="cm-chip">Регион: <b>' + flag(M.r[st.rgf].cc) + ' ' + esc(M.r[st.rgf].n) + '</b><button type="button" id="cmRgX" aria-label="Сбросить" data-tip="Показать всех">' + ico('x') + '</button></div>' : '')
                + (st.cc ? '<div class="cm-chip">Подключались из: <b>' + flag(st.cc) + ' ' + esc(ccName(st.cc)) + '</b><button type="button" id="cmCcX" aria-label="Сбросить" data-tip="Показать всех">' + ico('x') + '</button></div>' : '')
                + '<div id="cmRows">' + rows + '</div>'
                + (pages > 1 ? '<div class="cm-pg"><button type="button" id="cmPgP" aria-label="Назад"' + (st.page ? '' : ' disabled') + '>' + ico('back') + '</button><span>' + (st.page + 1) + ' из ' + pages + '</span><button type="button" id="cmPgN" aria-label="Дальше"' + (st.page < pages - 1 ? '' : ' disabled') + '>' + ico('next') + '</button></div>' : '')
                + '</div>';
            var outL = Object.keys(AG.OUT).map(function (cc) { return {cc: cc, b: AG.OUT[cc]}; }).sort(function (a, b) { return b.b.n - a.b.n; });
            h += '<div class="cm-sbox"><div class="cm-sh">' + ico('globe') + '<b>За пределами СНГ</b><span class="n">' + outL.reduce(function (s, x) { return s + x.b.n; }, 0) + '</span></div>'
                + (outL.length ? outL.map(function (x) { var pl = Object.keys(x.b.pl); return '<button type="button" class="cm-srow' + (st.cc === x.cc ? ' on' : '') + '" data-cc="' + x.cc + '"><span class="fl">' + flag(x.cc) + '</span><span class="n">' + esc(ccName(x.cc)) + (pl.length ? '<small>' + esc(pl.slice(0, 4).join(', ') + (pl.length > 4 ? ' и ещё ' + (pl.length - 4) : '')) + '</small>' : '') + '</span><span class="c">' + x.b.n + '</span></button>'; }).join('') : '<div class="cm-empty">За этот период — никого.</div>') + '</div>';
            var nr = Object.keys(AG.NOREG);
            if (nr.length) h += '<div class="cm-sbox"><div class="cm-sh">' + ico('pin') + '<b>Регион не определён</b><span class="n">' + nr.reduce(function (s, k) { return s + AG.NOREG[k].n; }, 0) + '</span></div>' + nr.map(function (cc) { return '<div class="cm-srow" style="cursor:default"><span class="fl">' + flag(cc) + '</span><span class="n">' + esc(ccName(cc)) + '<small>страна известна, региона в базе нет</small></span><span class="c">' + AG.NOREG[cc].n + '</span></div>'; }).join('') + '</div>';
            return h;
        };

        var detBuild = function (d, c) {
            var f = D.from, t = D.to, ev = d.every || 0, G = {}, nodes = {}, cells = [], hourly = t - f <= DAY + 60, start, step;
            if (hourly) { step = 3600; start = t - 24 * 3600; for (var i = 0; i < 24; i++) cells.push(0); }
            else {
                var d0 = new Date(f * 1000); d0.setHours(0, 0, 0, 0); start = Math.floor(d0.getTime() / 1000); step = DAY;
                for (var x = start; x < t; x += DAY) cells.push(0);
            }
            var nodeName = function (u) { var n = d.nodes[u]; return n ? (n[2] || n[0] || u.slice(0, 8)) : u.slice(0, 8); };
            var nodeCc = function (u) { var n = d.nodes[u]; return n ? n[1] : ''; };
            d.rows.forEach(function (r) {
                var R = {cc: r[2], sub: r[5], rg: regionOf(r[2], r[5]), cis: !!CISCC[r[2]]}, key = regKey(R);
                if (st.node >= 0 && r[0] !== NODES[st.node].uuid) return;
                var s = Math.max(r[6], f), e = Math.min(r[7], t), sec = Math.max(0, e - s) + ev;
                var g = G[key] || (G[key] = {key: key, R: R, secs: 0, prov: {}, ips: {}});
                g.secs += sec;
                var pk = r[3] || ('?' + r[4]), pv = g.prov[pk] || (g.prov[pk] = {asn: r[3], org: r[4], ips: {}});
                var ip = pv.ips[r[1]] || (pv.ips[r[1]] = {last: 0, nodes: {}});
                ip.last = Math.max(ip.last, Math.min(r[7], t)); ip.nodes[nodeName(r[0])] = 1; g.ips[r[1]] = 1;
                var nn = nodeName(r[0]); nodes[nn] = nodes[nn] || {cc: nodeCc(r[0]), secs: 0}; nodes[nn].secs += sec;
                for (var a = Math.floor((s - start) / step), z = Math.floor((Math.max(s, e) - start) / step); a <= z; a++) {
                    if (a < 0 || a >= cells.length) continue;
                    var c0 = start + a * step, c1 = c0 + step;
                    cells[a] += Math.max(0, Math.min(e + ev, c1) - Math.max(s, c0));
                }
            });
            return {uid: c.uid, G: G, nodes: nodes, cells: cells, hourly: hourly, step: step, start: start, cut: d.cut, city: d.city || {}};
        };
        var histHtml = function () {
            if (!DET) return '';
            var q = function (v) { var r = v / DET.step; return v <= 0 ? 0 : r < .25 ? 1 : r < .6 ? 2 : 3; };
            var n = DET.cells.length, lab = DET.hourly ? ['24 ч назад', '12 ч', 'сейчас'] : [tFmt(DET.start, 'd'), '', tFmt(DET.start + (n - 1) * DAY, 'd')];
            return '<div class="cm-act"><div class="cm-h5" style="margin-top:0">В сети ' + (DET.hourly ? 'по часам' : 'по дням') + '</div><div class="cm-hist" style="grid-template-columns:repeat(' + n + ',minmax(0,1fr))">'
                + DET.cells.map(function (v, i) { var t0 = DET.start + i * DET.step; return '<i class="a' + q(v) + '" data-tip="' + esc((DET.hourly ? tFmt(t0, 't') + '–' + tFmt(t0 + 3600, 't') : tFmt(t0, 'd')) + ' · ' + (v > 0 ? dur(v) : 'не в сети')) + '"></i>'; }).join('')
                + '</div><div class="cm-hx"><span>' + lab[0] + '</span><span>' + lab[1] + '</span><span>' + lab[2] + '</span></div></div>';
        };
        var sideClient = function (c) {
            var s = c.s, provN = Object.keys(s.prov).length, nn = Object.keys(s.nodes).length, rn = regCount(s);
            var h = '<div class="cm-sbox"><div class="cm-dh"><div class="top"><button type="button" class="cm-back" id="cmBack">' + ico('back') + 'Все клиенты</button>' + (c.su ? '<a class="cm-open" href="?tab=users&amp;card=' + encodeURIComponent(c.su) + '" data-tip="Карточка пользователя — вкладка «Сессии»">Карточка' + ico('ext') + '</a>' : '') + '</div>'
                + '<div class="nm"><span class="av">' + esc(initials(c.nm)) + '</span><div style="min-width:0"><b>' + esc(c.nm) + '</b>' + (c.su ? '<code>' + esc(c.su) + '</code>' : '') + '</div></div>'
                + (c.last ? '<div class="ls">Последнее подключение — ' + ago(c.last) + ' (<span class="cm-ts" data-ts="' + c.last + '" data-f="dt"></span>)</div>' : '') + '</div>'
                + '<div class="cm-tiles"><div class="cm-tile"><div class="v">' + c.ip + '</div><div class="k">IP</div></div><div class="cm-tile"><div class="v">' + rn + '</div><div class="k">' + plural(rn, 'регион', 'региона', 'регионов') + '</div></div><div class="cm-tile"><div class="v">' + provN + '</div><div class="k">' + plural(provN, 'провайдер', 'провайдера', 'провайдеров') + '</div></div><div class="cm-tile"><div class="v">' + nn + '</div><div class="k">' + plural(nn, 'нода', 'ноды', 'нод') + '</div></div></div>';
            if (!DET || DET.uid !== c.uid) return h + '<div class="cm-sec"><div class="cm-empty">' + (DET && DET.err ? esc(DET.err) : 'Загружаю подключения…') + '</div></div></div>';
            h += histHtml();
            var gs = Object.keys(DET.G).map(function (k) { return DET.G[k]; }).sort(function (a, b) { return b.secs - a.secs; });
            h += '<div class="cm-sec"><div class="cm-h5">Откуда подключался</div>' + gs.map(function (g) {
                var R = g.R, prov = Object.keys(g.prov).map(function (k) { return g.prov[k]; });
                var ipRows = '';
                prov.forEach(function (pv) {
                    Object.keys(pv.ips).sort(function (a, b) { return pv.ips[b].last - pv.ips[a].last; }).forEach(function (ip) {
                        var x = pv.ips[ip], city = DET.city[ip];
                        ipRows += '<div class="cm-ip"><code>' + esc(ip) + '</code><span class="w">последний раз <span class="cm-ts" data-ts="' + x.last + '" data-f="dt"></span></span>'
                            + '<span class="ns">' + (prov.length > 1 || city ? '<span class="cm-pv">' + esc((pv.asn ? 'AS' + pv.asn + ' ' : '') + (pv.org || '') + (city ? ' · ' + city : '')) + '</span>' : '') + Object.keys(x.nodes).map(function (n) { return '<span class="cm-nc">' + esc(n) + '</span>'; }).join('') + '</span></div>';
                    });
                });
                var sub = prov.length === 1 ? (prov[0].asn ? 'AS' + prov[0].asn + ' ' : '') + (prov[0].org || '') : prov.length + ' ' + plural(prov.length, 'провайдер', 'провайдера', 'провайдеров');
                return '<div class="cm-rg' + (st.hovRg === g.key ? ' hl' : '') + '" data-rg="' + esc(g.key) + '"><div class="cm-rgh"' + (R.rg ? '' : ' style="cursor:default"') + '><span class="fl">' + flag(R.cc) + '</span><span class="t"><b>' + esc(regName(R)) + (R.cis ? '' : ' <span class="cm-sub">' + esc(ccName(R.cc)) + '</span>') + '</b><small>' + esc(sub) + '</small></span><span class="h">' + dur(g.secs) + '</span></div>' + ipRows + '</div>';
            }).join('') + (DET.cut ? '<div class="cm-empty">Показаны последние подключения — сузьте период, чтобы увидеть все.</div>' : '') + '</div>';
            var nk = Object.keys(DET.nodes).sort(function (a, b) { return DET.nodes[b].secs - DET.nodes[a].secs; }), mx = nk.length ? DET.nodes[nk[0]].secs : 1;
            h += '<div class="cm-sec"><div class="cm-h5">Ноды</div>' + nk.map(function (k) { var n = DET.nodes[k]; return '<div class="cm-bar"><span class="t">' + nflag(n.cc, k) + '<span>' + esc(k) + '</span></span><span class="g"><i style="width:' + Math.max(3, Math.round(n.secs / mx * 100)) + '%"></i></span><span class="c">' + dur(n.secs) + '</span></div>'; }).join('') + '</div></div>';
            return h;
        };
        var side = function () {
            var el = $('cmSide'), c = cur();
            el.innerHTML = c ? sideClient(c) : sideList();
            locTs(el);
            var q = $('cmQ'); if (q) { q.addEventListener('input', function () { st.q = q.value; st.page = 0; var pos = q.selectionStart; side(); var n = $('cmQ'); n.focus(); try { n.setSelectionRange(pos, pos); } catch (e) {} }); }
            var so = $('cmSort'); if (so) so.addEventListener('change', function () { st.sort = so.value; st.page = 0; side(); });
        };
        var focusBar = function () {
            var f = $('cmFocus'), t = '', fl = '', c = cur();
            if (c) { t = c.nm; fl = Object.keys(c.s.cc).map(flag).join(' '); }
            else if (st.region) { t = M.r[st.region].n; fl = flag(M.r[st.region].cc); }
            else if (st.ab) { t = ccName(st.ab); fl = flag(st.ab); }
            f.classList.toggle('on', !!t); $('cmFocusT').textContent = t; $('cmFocusF').textContent = fl;
        };

        var pop = $('cmPop');
        var provs = function (id) {
            var o = {};
            AG.list.forEach(function (c) { c.g.forEach(function (g) { if (REG[g[1]].rg !== id || g[3] <= 0 || (st.node >= 0 && g[2] !== st.node)) return; var x = o[g[3]] || (o[g[3]] = {asn: g[3], org: D.A[g[3]] || '', cl: {}}); x.cl[c.i] = 1; }); });
            return Object.keys(o).map(function (k) { o[k].n = Object.keys(o[k].cl).length; return o[k]; }).sort(function (a, b) { return b.n - a.n; }).slice(0, 5);
        };
        var popRegion = function (id) {
            var b = AG.RB[id]; if (!b) return closePop();
            var cl = Object.keys(b.cl).map(function (i) { return CL[i]; }).sort(function (a, c) { return c.ip - a.ip; }), pv = provs(id), mx = pv.length ? pv[0].n : 1;
            pop.innerHTML = '<div class="cm-ph"><span class="fl">' + flag(M.r[id].cc) + '</span><div style="min-width:0"><b>' + esc(M.r[id].n) + '</b><div class="cm-sub">' + b.n + ' ' + plural(b.n, 'клиент', 'клиента', 'клиентов') + ' ' + perText() + '</div></div><button type="button" class="x" id="cmPopX" aria-label="Закрыть">' + ico('x') + '</button></div>'
                + (pv.length ? '<div class="cm-pb"><div class="cm-h5">Провайдеры</div>' + pv.map(function (x) { return '<div class="cm-bar"><span class="t"><span>' + esc(x.org || 'AS' + x.asn) + ' <span class="cm-sub">AS' + x.asn + '</span></span></span><span class="g"><i class="v" style="width:' + Math.round(x.n / mx * 100) + '%"></i></span><span class="c">' + x.n + '</span></div>'; }).join('') + '</div>' : '')
                + '<div class="cm-pl">' + cl.slice(0, 5).map(function (c) { return '<button type="button" class="cm-crow" data-c="' + c.i + '"><span class="av">' + esc(initials(c.nm)) + '</span><span class="bd"><span class="nm"><span>' + esc(c.nm) + '</span>' + (c.su ? '<code>' + esc(String(c.su).slice(0, 8)) + '</code>' : '') + '</span><span class="sub">' + Object.keys(c.s.nodes).map(function (k) { return esc(NODES[k].nm); }).join(', ') + '</span></span><span class="ct">' + c.ip + '<small>IP</small></span></button>'; }).join('') + '</div>'
                + (cl.length > 5 ? '<button type="button" class="cm-more" id="cmPopMore">Все ' + cl.length + ' — в списке клиентов</button>' : '');
            pop.classList.add('open'); placePop();
        };
        var closePop = function () { pop.classList.remove('open'); };
        var placePop = function () {
            if (!pop.classList.contains('open') || !st.region) return;
            var bx = mw.getBoundingClientRect(), vb = st.vb, x = (M.r[st.region].x - vb[0]) / vb[2] * bx.width, y = (M.r[st.region].y - vb[1]) / vb[3] * bx.height;
            var w = pop.offsetWidth, hh = pop.offsetHeight, left = x + 22, top = y - 30;
            if (left + w > bx.width - 50) left = x - w - 22;
            if (left < 8) left = 8;
            if (top + hh > bx.height - 8) top = bx.height - hh - 8;
            if (top < 8) top = 8;
            pop.style.left = left + 'px'; pop.style.top = top + 'px';
        };

        var render = function () { fillNodes(); aggregate(); paint(); drawOv(); kpis(); legend(); side(); focusBar(); fresh(); $('cmPerT').textContent = perText(); zoomBtns(); };
        var refresh = function () { paint(); drawOv(); side(); focusBar(); };
        var resetSel = function () { st.client = -1; st.region = null; st.ab = null; st.hovRg = null; DET = null; closePop(); };

        var loadDet = function (c) {
            var s = ++detSeq, q = 'cmap_user&id=' + encodeURIComponent(c.uid) + '&p=c&f=' + D.from + '&t=' + D.to;
            getj(q).then(function (d) {
                if (s !== detSeq || cur() !== c) return;
                DET = d.ok ? detBuild(d, c) : {uid: -1, err: d.error || 'Не загрузилось'};
                refresh();
            });
        };
        var selectClient = function (i) {
            st.client = i; st.region = null; st.ab = null; st.hovRg = null; DET = null; closePop(); refresh();
            var c = cur(); if (!c) return;
            loadDet(c);
            var pts = []; c.s.links.forEach(function (L) { if (L.R.rg) pts.push([M.r[L.R.rg].x, M.r[L.R.rg].y]); });
            if (pts.length) fitTo(pts);
        };
        var clearSel = function () { resetSel(); refresh(); };

        var RETRY = [5, 10, 20, 40];
        var retryStale = function (d) { clearTimeout(retryT); if (d && d.stale && retryN < RETRY.length) retryT = setTimeout(function () { load(true); }, RETRY[retryN++] * 1000); };
        var load = function (quiet) {
            var s = ++loadSeq, q = 'cmap&p=' + encodeURIComponent(st.p) + (st.p === 'c' ? '&f=' + st.f + '&t=' + st.t : '');
            if (!quiet) $('cmCard').classList.add('busy');
            getj(q).then(function (d) {
                if (s !== loadSeq) return;
                $('cmCard').classList.remove('busy');
                if (!d.ok) { if (!quiet && window.uiAlert) uiAlert(d.error || 'Сводка не загрузилась'); return; }
                var keep = cur() ? cur().uid : null;
                setData(d);
                if (keep !== null) { st.client = -1; for (var i = 0; i < CL.length; i++) if (CL[i].uid === keep) { st.client = i; break; } if (st.client < 0) resetSel(); }
                render();
                if (keep !== null && cur() && (!DET || DET.uid !== keep)) loadDet(cur());
                retryStale(d);
            });
        };

        var clampVb = function () {
            var vb = st.vb, a = asp(), cl = function (v, lo, hi) { return Math.max(lo, Math.min(hi, v)); };
            vb[2] = cl(vb[2], 110, 1000); vb[3] = vb[2] * a;
            vb[0] = vb[2] >= 1000 ? 0 : cl(vb[0], 0, 1000 - vb[2]);
            vb[1] = vb[3] >= 540 ? (540 - vb[3]) / 2 : cl(vb[1], 0, 540 - vb[3]);
        };
        var applyVb = function () { clampVb(); $('cmMap').setAttribute('viewBox', st.vb.map(function (v) { return v.toFixed(2); }).join(' ')); if (AG) drawOv(); zoomBtns(); };
        var zoomBtns = function () { $('cmZout').disabled = st.vb[2] >= 999; $('cmZreset').hidden = st.vb[2] >= 999; $('cmZin').disabled = st.vb[2] <= 111; };
        var zoomAt = function (fx, fy, f) { var vb = st.vb, w = Math.max(110, Math.min(1000, vb[2] * f)), r = w / vb[2]; vb[0] = fx - (fx - vb[0]) * r; vb[1] = fy - (fy - vb[1]) * r; vb[2] = w; applyVb(); };
        var fitTo = function (pts) {
            if (!pts.length) return;
            var x0 = 1e9, y0 = 1e9, x1 = -1e9, y1 = -1e9; pts.forEach(function (p) { x0 = Math.min(x0, p[0]); y0 = Math.min(y0, p[1]); x1 = Math.max(x1, p[0]); y1 = Math.max(y1, p[1]); });
            var a = asp(), w = Math.max(340, (x1 - x0) * 1.5, (y1 - y0) * 1.9 / a);
            if (w >= 900) w = 1000;
            st.vb = [(x0 + x1) / 2 - w / 2, (y0 + y1) / 2 - w * a / 2, w, w * a]; applyVb();
        };
        $('cmZin').addEventListener('click', function () { zoomAt(st.vb[0] + st.vb[2] / 2, st.vb[1] + st.vb[3] / 2, 1 / 1.6); });
        $('cmZout').addEventListener('click', function () { zoomAt(st.vb[0] + st.vb[2] / 2, st.vb[1] + st.vb[3] / 2, 1.6); });
        $('cmZreset').addEventListener('click', function () { st.vb = [0, 0, 1000, 540]; applyVb(); });
        $('cmZfs').addEventListener('click', function () { box.classList.toggle('fs'); requestAnimationFrame(applyVb); });
        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape') return;
            if ($('cmPP').classList.contains('open')) { $('cmPP').classList.remove('open'); return; }
            if (pop.classList.contains('open')) { st.region = null; closePop(); refresh(); return; }
            if (box.classList.contains('fs')) { box.classList.remove('fs'); requestAnimationFrame(applyVb); return; }
            if (st.client >= 0 || st.ab) clearSel();
        });
        var hintT = 0, hint = function () { var h = $('cmHint'); h.classList.add('on'); clearTimeout(hintT); hintT = setTimeout(function () { h.classList.remove('on'); }, 1300); };
        mw.addEventListener('wheel', function (e) {
            if (!(e.ctrlKey || e.metaKey) && !box.classList.contains('fs')) { hint(); return; }
            e.preventDefault(); var b = mw.getBoundingClientRect(), vb = st.vb;
            zoomAt(vb[0] + (e.clientX - b.left) / b.width * vb[2], vb[1] + (e.clientY - b.top) / b.height * vb[3], e.deltaY > 0 ? 1.25 : 1 / 1.25);
        }, {passive: false});
        var drag = null, moved = false;
        mw.addEventListener('pointerdown', function (e) {
            if (e.button !== 0 || e.target.closest('.cm-zoom,.cm-pop,.cm-focus')) return;
            drag = {x: e.clientX, y: e.clientY, vb: st.vb.slice(), id: e.pointerId}; moved = false;
        });
        window.addEventListener('pointermove', function (e) {
            if (!drag) return;
            var dx = e.clientX - drag.x, dy = e.clientY - drag.y;
            if (!moved && Math.hypot(dx, dy) < 5) return;
            if (!moved) { moved = true; box.classList.add('drag'); try { mw.setPointerCapture(drag.id); } catch (x) {} hideTip(); }
            var b = mw.getBoundingClientRect(); st.vb[0] = drag.vb[0] - dx / b.width * drag.vb[2]; st.vb[1] = drag.vb[1] - dy / b.height * drag.vb[3]; applyVb();
        });
        window.addEventListener('pointerup', function () { if (drag) { drag = null; box.classList.remove('drag'); setTimeout(function () { moved = false; }, 0); } });
        window.addEventListener('resize', function () { applyVb(); });

        var tip = $('cmTip'), hideTip = function () { tip.style.display = 'none'; };
        var showTip = function (e, html) {
            tip.innerHTML = html; tip.style.display = 'block';
            var b = box.getBoundingClientRect(), x = e.clientX - b.left + 14, y = e.clientY - b.top + 14;
            if (x + tip.offsetWidth > b.width - 6) x = e.clientX - b.left - tip.offsetWidth - 14;
            if (y + tip.offsetHeight > b.height - 6) y = e.clientY - b.top - tip.offsetHeight - 14;
            tip.style.left = Math.max(6, x) + 'px'; tip.style.top = Math.max(6, y) + 'px';
        };
        box.addEventListener('mousemove', function (e) {
            if ((drag && moved) || !AG) return;
            var t = e.target, p = t.closest && t.closest('path.rg'), b0 = t.closest && t.closest('.cm-b');
            if (t.closest && t.closest('.cm-pop,.cm-zoom,.cm-focus')) return hideTip();
            if (b0) { var ids = b0.dataset.ids.split(','), n = +b0.textContent; return showTip(e, '<b>' + esc(ids.slice(0, 3).map(function (id) { return M.r[id].n; }).join(', ')) + (ids.length > 3 ? ' и ещё ' + (ids.length - 3) : '') + '</b><div class="m">' + n + ' ' + plural(n, 'клиент', 'клиента', 'клиентов') + (ids.length > 1 ? ' · нажмите, чтобы приблизить' : '') + '</div>'); }
            if (p) { var id = p.dataset.id, rb = AG.RB[id]; return showTip(e, '<b>' + flag(M.r[id].cc) + ' ' + esc(M.r[id].n) + '</b><div class="m">' + (rb ? rb.n + ' ' + plural(rb.n, 'клиент', 'клиента', 'клиентов') : 'никого') + ' ' + perText() + '</div>'); }
            hideTip();
        });
        box.addEventListener('mouseleave', hideTip);
        box.addEventListener('click', function (e) {
            if (moved || !AG) return;
            var t = e.target;
            if (t.closest('#cmPopX')) { st.region = null; closePop(); refresh(); return; }
            if (t.closest('#cmPopMore')) { st.rgf = st.region; st.cc = ''; st.q = ''; st.page = 0; st.region = null; closePop(); refresh(); $('cmSide').scrollIntoView({behavior: 'smooth', block: 'nearest'}); return; }
            if (t.closest('#cmFocusX')) { clearSel(); return; }
            var cr = t.closest('.cm-crow'); if (cr) { selectClient(+cr.dataset.c); return; }
            if (t.closest('.cm-pop,.cm-zoom')) return;
            var b0 = t.closest('.cm-b');
            if (b0) {
                var ids = b0.dataset.ids.split(',');
                if (ids.length > 1) { fitTo(ids.map(function (id) { return [M.r[id].x, M.r[id].y]; })); return; }
                st.region = ids[0]; st.ab = null; paint(); drawOv(); popRegion(ids[0]); focusBar(); return;
            }
            var p = t.closest('path.rg');
            if (p && AG.RB[p.dataset.id] && st.client < 0) { st.region = p.dataset.id; st.ab = null; paint(); drawOv(); popRegion(p.dataset.id); focusBar(); return; }
            if (st.client >= 0 || st.region || st.ab) clearSel();
        });
        $('cmSide').addEventListener('click', function (e) {
            var t = e.target;
            var cr = t.closest('.cm-crow'); if (cr) { selectClient(+cr.dataset.c); if (window.innerWidth <= 1180) box.scrollIntoView({behavior: 'smooth', block: 'center'}); return; }
            if (t.closest('#cmBack')) { clearSel(); st.vb = [0, 0, 1000, 540]; applyVb(); return; }
            if (t.closest('#cmPgP')) { st.page--; side(); return; }
            if (t.closest('#cmPgN')) { st.page++; side(); return; }
            if (t.closest('#cmRgX')) { st.rgf = null; st.page = 0; refresh(); return; }
            if (t.closest('#cmCcX')) { st.cc = ''; st.ab = null; st.page = 0; refresh(); return; }
            var cc = t.closest('.cm-srow[data-cc]');
            if (cc) { var v = cc.dataset.cc; st.rgf = null; st.cc = st.cc === v ? '' : v; st.ab = st.cc || null; st.region = null; closePop(); st.page = 0; refresh(); return; }
            var rg = t.closest('.cm-rgh');
            if (rg) { var key = rg.parentNode.dataset.rg; if (key.indexOf(':') >= 0) return; st.hovRg = st.hovRg === key ? null : key; refresh(); }
        });

        var nsel = $('cmNode');
        var fillNodes = function () {
            nsel.innerHTML = '<option value="-1">Все ноды</option>' + NODES.map(function (n) { return '<option value="' + n.k + '"' + (st.node === n.k ? ' selected' : '') + '>' + nflag(n.cc, n.nm) + esc(n.nm) + '</option>'; }).join('');
        };
        nsel.addEventListener('change', function () { st.node = +nsel.value; resetSel(); st.cc = ''; st.rgf = null; st.page = 0; render(); });

        var pp = $('cmPP'), ymd = function (t) { var d = new Date(t * 1000); return d.getFullYear() + '-' + p2(d.getMonth() + 1) + '-' + p2(d.getDate()); };
        var dayStart = function (v) { var p = v.split('-'); return Math.floor(new Date(+p[0], +p[1] - 1, +p[2]).getTime() / 1000); };
        var minTs = function () { return nowS() - C.keep * DAY; };
        var setPer = function (btn) { document.querySelectorAll('#cmPer button').forEach(function (x) { x.classList.toggle('on', x === btn); }); };
        var periodChanged = function () { resetSel(); st.cc = ''; st.rgf = null; st.page = 0; st.node = -1; retryN = 0; clearTimeout(retryT); load(false); };
        document.querySelectorAll('#cmPer button').forEach(function (b) {
            b.addEventListener('click', function (e) {
                if (b.dataset.p === 'c') {
                    e.stopPropagation();
                    $('cmFrom').min = $('cmTo').min = ymd(minTs() + DAY); $('cmFrom').max = $('cmTo').max = ymd(nowS());
                    if (!$('cmFrom').value) { $('cmFrom').value = ymd(nowS() - 6 * DAY); $('cmTo').value = ymd(nowS()); }
                    $('cmPE').textContent = ''; pp.classList.toggle('open'); return;
                }
                pp.classList.remove('open'); setPer(b); st.p = b.dataset.p; $('cmPerC').querySelector('span').textContent = 'Период';
                periodChanged();
            });
        });
        $('cmPQ').addEventListener('click', function (e) {
            var b = e.target.closest('button'); if (!b) return;
            var n = nowS(), t = new Date(n * 1000), q = b.dataset.q;
            if (q === 'y') { $('cmFrom').value = $('cmTo').value = ymd(n - DAY); }
            if (q === 'w') { var wd = (t.getDay() + 6) % 7; $('cmFrom').value = ymd(n - (wd + 7) * DAY); $('cmTo').value = ymd(n - (wd + 1) * DAY); }
            if (q === 'm') { $('cmFrom').value = ymd(n - (t.getDate() - 1) * DAY); $('cmTo').value = ymd(n); }
        });
        $('cmPX').addEventListener('click', function () { pp.classList.remove('open'); });
        $('cmPOk').addEventListener('click', function () {
            var f = $('cmFrom').value, to = $('cmTo').value, er = $('cmPE');
            if (!f || !to) { er.textContent = 'Укажите обе даты'; return; }
            var a = dayStart(f), b = dayStart(to); if (a > b) { var x = a; a = b; b = x; }
            var end = new Date(b * 1000); end.setDate(end.getDate() + 1); b = Math.floor(end.getTime() / 1000) - 1;
            if (b < minTs()) { er.textContent = 'История хранится ' + C.keep + ' ' + plural(C.keep, 'день', 'дня', 'дней') + ' — не раньше ' + tFmt(minTs() + DAY, 'd'); return; }
            st.p = 'c'; st.f = a; st.t = b;
            pp.classList.remove('open'); setPer($('cmPerC'));
            $('cmPerC').querySelector('span').textContent = tFmt(Math.max(a, minTs()), 'd') + ' – ' + tFmt(Math.min(b, nowS()), 'd');
            periodChanged();
        });
        document.addEventListener('click', function (e) { if (pp.classList.contains('open') && !pp.contains(e.target) && !e.target.closest('#cmPerC')) pp.classList.remove('open'); });

        if (C.d) {
            setData(C.d); render();
            retryStale(C.d);
        } else load(false);
    })();
    </script>
<?php endif; ?>
