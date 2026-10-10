<?php
$wg_uc = wglease_user_cache();
$wgx_members = [];
foreach ($sqcfg_squads as $s) $wgx_members[$s['uuid']] = (int) $s['members'];
$wgx_sizing = $sqcfg_sizing['rows'] ?? [];
$wgx_lz = []; $wgx_lany = [];
foreach ($sqcfg_leases as $l) {
    $lp = (int) $l['manual'] === 1 ? '__manual__' : (string) $l['pool_id'];
    $lk = $lp . ':' . (int) $l['config_id'];
    if (!isset($wgx_lz[$lk]) || (int) $l['manual'] === 1) $wgx_lz[$lk] = $l;
    $lc = (int) $l['config_id'];
    if (!isset($wgx_lany[$lc]) || (int) $l['manual'] === 1) $wgx_lany[$lc] = $l;
}
$wgx_cfg = [];
$wgx_cell = [];
$wgx_pools = [];
foreach ($sqcfg_squads as $s) $wgx_pools[$s['uuid']] = ['mode' => $sqcfg_modes[$s['uuid']] ?? wglease_mode($s['uuid']), 'groups' => [], 'vers' => [], 'n' => 0];
$wgx_kpi = ['all' => 0, 'used' => 0, 'free' => 0, 'shared' => 0, 'off' => 0, 'v3' => 0];
$wgx_cnt = ['' => 0, 'free' => 0, 'used' => 0, 'all' => 0, 'off' => 0];
$sqcfg_edit = [];
foreach ($sqcfg_wg as $c) {
    $id = (int) $c['id'];
    $pn = json_decode((string) ($c['parsed'] ?? ''), true);
    $pn = is_array($pn) ? $pn : [];
    $t = (string) ($c['type'] ?? '');
    $ver = $t === 'amneziawg' ? awg_version($pn) : '';
    $vl = $t === 'amneziawg' ? ('AWG ' . $ver) : 'WG';
    $sqs = squadconf_squads_of($c);
    if (!$sqs) $sqs = ['__manual__'];
    $grp = trim((string) ($c['grp'] ?? ''));
    $on = (int) $c['enabled'] === 1;
    $busy = false; $free = false; $shared = false;
    foreach ($sqs as $pk) {
        $mode = $pk === '__manual__' ? 'manual' : ($sqcfg_modes[$pk] ?? wglease_mode($pk));
        $lz = $mode === 'shared' ? null : ($wgx_lz[$pk . ':' . $id] ?? ($wgx_lany[$id] ?? null));
        $st = !$on ? 'off' : ($lz ? 'used' : ($mode === 'shared' ? 'all' : 'free'));
        if ($st === 'used') $busy = true;
        if ($st === 'free') $free = true;
        if ($st === 'all') $shared = true;
        $wgx_cnt[''] ++; $wgx_cnt[$st]++;
        $su = $lz ? trim((string) ($lz['short_uuid'] ?? '')) : '';
        $hw = $lz ? (string) ($lz['hwid'] ?? '') : '';
        $dev = ($su !== '' && $hw !== '' && !empty($wg_uc[$su]['d'][$hw])) ? $wg_uc[$su]['d'][$hw] : null;
        $lua = $lz ? trim((string) ($lz['ua'] ?? '')) : '';
        $wgx_cell[$pk . ':' . $id] = [
            'id' => $id, 'n' => (string) ($c['name'] ?? ''), 'v' => $vl, 'vc' => awg_ver_class($vl), 'p' => $pk, 'sq' => array_values($sqs), 'g' => $grp,
            'ep' => (string) ($pn['peer']['Endpoint'] ?? ''), 'st' => $st, 'on' => $on ? 1 : 0,
            'su' => $su, 'u' => ($su !== '' && !empty($wg_uc[$su]['u'])) ? (string) $wg_uc[$su]['u'] : '', 'hw' => $hw,
            'dm' => $dev ? (string) ($dev['m'] ?? '') : '', 'dp' => $hw !== '' ? (string) ($dev['p'] ?? ($sqcfg_hwid_plat[$hw] ?? '')) : '',
            'cl' => squadconf_client_label($lua), 'seen' => $lz ? (int) ($lz['seen_ts'] ?? 0) : 0,
            'man' => $lz ? (int) ($lz['manual'] ?? 0) : 0,
        ];
        if (!isset($wgx_pools[$pk])) $wgx_pools[$pk] = ['mode' => $mode, 'groups' => [], 'vers' => [], 'n' => 0];
        $gk = $grp !== '' ? $grp : ($t === 'amneziawg' ? 'AWG' : 'WG');
        if (!isset($wgx_pools[$pk]['groups'][$gk])) $wgx_pools[$pk]['groups'][$gk] = ['label' => $grp, 'ids' => [], 'on' => 0, 'free' => 0, 'vers' => []];
        $wgx_pools[$pk]['groups'][$gk]['ids'][] = $id;
        $wgx_pools[$pk]['groups'][$gk]['vers'][$vl] = true;
        if ($on) $wgx_pools[$pk]['groups'][$gk]['on']++;
        if ($st === 'free') $wgx_pools[$pk]['groups'][$gk]['free']++;
        $wgx_pools[$pk]['vers'][$vl] = true;
        $wgx_pools[$pk]['n']++;
    }
    $wgx_cfg[$id] = ['n' => (string) ($c['name'] ?? ''), 'v' => $vl, 'busy' => $busy];
    $sqcfg_edit[$id] = ['squads' => array_values($sqs), 'name' => (string) ($c['name'] ?? ''), 'raw' => (string) $c['raw'], 'grp' => $grp];
    $wgx_kpi['all']++;
    if (!$on) $wgx_kpi['off']++; elseif ($busy) $wgx_kpi['used']++; elseif ($free) $wgx_kpi['free']++; elseif ($shared) $wgx_kpi['shared']++;
    if ($ver === '3.0' || $ver === '3.1') $wgx_kpi['v3']++;
}
$wgx_order = array_values(array_filter(array_keys($wgx_pools), fn($pk) => $pk !== '__manual__'));
$wgx_filled = array_values(array_filter($wgx_order, fn($pk) => $wgx_pools[$pk]['n'] > 0));
$wgx_man = array_values(array_filter($sqcfg_leases, fn($l) => (int) $l['manual'] === 1));
$wgx_man_cfgs = $wgx_pools['__manual__']['n'] ?? 0;
$wgx_need = function ($pk) use ($wgx_sizing, $wgx_pools) {
    $r = $wgx_sizing[$pk] ?? null;
    if (!$r) return null;
    $m = $wgx_pools[$pk]['mode'] ?? 'shared';
    if ($m === 'users') return (int) ($r['active'] ?? 0);
    if ($m === 'devices') return (int) ($r['devices'] ?? 0);
    return null;
};
$wgx_panel_ok = $sqcfg_squads_err === '' && $sqcfg_squads;
$wgx_pname = fn($pk) => $pk === '__manual__' ? $sqcfg_names['__manual__'] : ($sqcfg_names[$pk] ?? ($wgx_panel_ok ? 'Сквад удалён из панели' : ('Сквад ' . mb_substr($pk, 0, 8) . '…')));
$wgx_except = squadconf_wg_except();
$wgx_sq_all = $sqcfg_squads;
foreach ($wgx_order as $pk) if (!isset($sqcfg_names[$pk])) $wgx_sq_all[] = ['uuid' => $pk, 'name' => $wgx_pname($pk), 'members' => ''];
$wgx_names = ['__manual__' => $wgx_pname('__manual__')];
foreach ($wgx_order as $pk) $wgx_names[$pk] = $wgx_pname($pk);
?>
    <?php include __DIR__ . '/_sqcfg_css.php'; ?>
    <style>
        .wgx{--wgx-gap:.9rem}
        .wgx .card{margin-bottom:var(--wgx-gap);padding:1rem 1.1rem}
        .wgx .card h2{font-size:1rem;margin:0}
        .wgx-top{display:flex;flex-wrap:wrap;align-items:center;gap:.6rem 1rem}
        .wgx-kpi{display:flex;flex-wrap:wrap;align-items:baseline;gap:.25rem 1.1rem;font-size:.86rem;color:var(--muted);flex:1 1 auto;min-width:0}
        .wgx-kpi b{color:var(--text-strong);font-size:1.15rem;font-variant-numeric:tabular-nums;margin-right:.2rem}
        .wgx-acts{display:flex;flex-wrap:wrap;gap:.5rem;align-items:center}
        .wgx .wb{background:var(--bg2);color:var(--text);border:1px solid var(--line);border-radius:8px;padding:.42rem .8rem;font-size:.82rem;font-weight:600;display:inline-flex;align-items:center;gap:.4rem;white-space:nowrap;line-height:1.2}
        .wgx .wb:hover{background:var(--hover);filter:none;border-color:var(--accent)}
        .wgx .wb:active{transform:none}
        .wgx .wb.pri{background:var(--accent);color:var(--on-accent);border-color:var(--accent)}
        .wgx .wb.pri:hover{background:var(--accent-h)}
        .wgx .wb.sm{padding:.26rem .55rem;font-size:.76rem}
        .wgx .wb.bad{color:var(--c-bad-fg)}
        .wgx .wb svg{width:15px;height:15px;flex:none}
        .wgx-note{display:flex;gap:.6rem;align-items:flex-start;font-size:.82rem;padding:.55rem .8rem;border-radius:8px;margin-top:.7rem;background:var(--c-info-bg)}
        .wgx-note.bad{background:var(--c-bad-bg)}
        .wgx-note b{color:var(--text-strong)}
        .wgx-grid{display:grid;grid-template-columns:minmax(300px,24rem) minmax(0,1fr);gap:var(--wgx-gap);align-items:start}
        .wgx-grid>.card{margin:0;min-width:0}
        @media(max-width:1100px){.wgx-grid{grid-template-columns:minmax(0,1fr)}}
        .wgx-h{display:flex;align-items:center;justify-content:space-between;gap:.6rem;margin-bottom:.75rem;flex-wrap:wrap}
        .wgx-pools{display:flex;flex-direction:column;gap:.6rem}
        .wgx-pool{border:1px solid var(--line);border-radius:10px;background:var(--bg2);padding:.65rem .75rem;display:flex;flex-direction:column;gap:.55rem}
        .wgx-pool.empty{background:transparent;padding:.5rem .75rem;gap:.4rem}
        .wgx-pool.sel{border-color:var(--accent);box-shadow:0 0 0 1px var(--accent) inset}
        .wgx-ph{display:flex;align-items:center;gap:.45rem;flex-wrap:wrap}
        .wgx .wgx-pname{background:none;border:0;padding:0;color:var(--text-strong);font-weight:700;font-size:.92rem;cursor:pointer;text-align:left}
        .wgx .wgx-pname:hover{background:none;filter:none;color:var(--accent-text)}
        .wgx .wgx-pname:active{transform:none}
        .wgx-pm{font-size:.74rem;color:var(--muted)}
        .wgx-vt{display:inline-flex;font-size:.68rem;font-weight:700;padding:.08rem .4rem;border-radius:999px;white-space:nowrap}
        .wv-wg{background:var(--c-info-bg);color:var(--c-info-fg)}
        .wv-2{background:var(--c-violet-bg);color:var(--c-violet-fg)}
        .wv-3{background:var(--c-warn-bg);color:var(--c-warn-fg)}
        .wgx-ph .wgx-vts{display:flex;gap:.25rem;margin-left:auto}
        .wgx-seg{display:flex;border:1px solid var(--line);border-radius:7px;overflow:hidden;background:var(--card)}
        .wgx .wgx-seg label{display:block;flex:1 1 0;min-width:0;margin:0;font-size:.72rem;font-weight:600;color:var(--muted);text-align:center;padding:.32rem .2rem;cursor:pointer;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
        .wgx-seg label+label{border-left:1px solid var(--line)}
        .wgx-seg input{position:absolute;opacity:0;pointer-events:none;width:1px;height:1px}
        .wgx-seg label:has(input:checked){background:var(--accent-light);color:var(--accent-text)}
        .wgx-seg label:has(input:focus-visible){outline:2px solid var(--accent);outline-offset:-2px}
        .wgx-gr{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:.15rem .6rem;font-size:.78rem;align-items:center}
        .wgx-gr .gn{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        .wgx-gr .gc{color:var(--muted);font-variant-numeric:tabular-nums}
        .wgx-gr .gc b{color:var(--text-strong)}
        .wgx-gr .gc.low b{color:var(--c-warn-fg)}
        .wgx-bar{grid-column:1/-1;height:4px;border-radius:999px;background:var(--hover);overflow:hidden}
        .wgx-bar i{display:block;height:100%;background:var(--accent)}
        .wgx-bar.low i{background:var(--c-warn-fg)}
        .wgx-pf{display:flex;align-items:center;gap:.4rem;flex-wrap:wrap}
        .wgx-man{display:flex;flex-direction:column;font-size:.78rem}
        .wgx-man>div{display:flex;align-items:center;gap:.5rem;padding:.3rem 0;border-top:1px dashed var(--line)}
        .wgx-man>div:first-child{border-top:0}
        .wgx-man .mu{font-weight:600;color:var(--text-strong);min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        .wgx-man .mc{color:var(--muted);min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;flex:1}
        .wgx-man form{margin:0}
        .wgx .wx{background:none;border:0;padding:.1rem .3rem;color:var(--muted);font-size:.9rem;line-height:1;border-radius:5px}
        .wgx .wx:hover{background:var(--hover);color:var(--c-bad-fg);filter:none}
        .wgx-days{display:flex;align-items:center;flex-wrap:wrap;gap:.4rem;font-size:.78rem;color:var(--muted);margin-top:.7rem}
        .wgx .wgx-days input[type=number]{width:3.8rem;height:auto;min-height:0;padding:.22rem .4rem;font-size:.8rem;margin:0}
        .wgx-tools{display:flex;flex-wrap:wrap;gap:.45rem;align-items:center}
        .wgx-chf{display:flex;flex-wrap:wrap;gap:.3rem}
        .wgx .wgx-chf button{background:var(--card);color:var(--text);border:1px solid var(--line);border-radius:999px;padding:.22rem .65rem;font-size:.76rem;font-weight:600}
        .wgx .wgx-chf button:hover{filter:none;border-color:var(--accent);background:var(--card)}
        .wgx .wgx-chf button.on{background:var(--accent);color:var(--on-accent);border-color:var(--accent)}
        .wgx .wgx-chf button span{color:inherit;opacity:.75;margin-left:.2rem;font-variant-numeric:tabular-nums}
        .wgx-tools select,.wgx-tools input[type=search]{width:auto;padding:.32rem .55rem;font-size:.8rem;min-width:0}
        .wgx-tools input[type=search]{flex:1 1 10rem;max-width:none;background:var(--bg2);border:1px solid var(--line);color:var(--text);border-radius:8px}
        .wgx-sec{margin-top:.8rem}
        .wgx-sec-h{font-size:.74rem;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:var(--muted);margin-bottom:.35rem;display:flex;gap:.5rem;align-items:center}
        .wgx-grp{margin-bottom:.55rem}
        .wgx-grp-h{font-size:.76rem;color:var(--muted);margin-bottom:.3rem;display:flex;gap:.4rem;align-items:center;flex-wrap:wrap}
        .wgx-grp-h b{color:var(--text)}
        .wgx-chips{display:flex;flex-wrap:wrap;gap:.3rem}
        .wgx .wgx-chip{background:var(--bg2);color:var(--text);border:1px solid var(--line);border-radius:7px;padding:.22rem .5rem .22rem .4rem;font-size:.76rem;font-weight:500;display:inline-flex;align-items:center;gap:.35rem;max-width:15rem}
        .wgx .wgx-chip:hover{filter:none;background:var(--hover);border-color:var(--accent)}
        .wgx .wgx-chip:active{transform:none}
        .wgx-chip .lb{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        .wgx-chip .dt{width:7px;height:7px;border-radius:50%;flex:none;background:var(--muted)}
        .wgx-chip.st-free .dt{background:var(--c-ok-fg)}
        .wgx-chip.st-used .dt,.wgx-chip.st-all .dt{background:var(--accent)}
        .wgx-chip.st-off{opacity:.55}
        .wgx-chip.st-off .lb{text-decoration:line-through}
        .wgx .wgx-chip.pk{border-color:var(--accent);background:var(--accent-light)}
        .wgx .wgx-chip.chk{border-color:var(--accent);background:var(--accent);color:var(--on-accent)}
        .wgx .wgx-chip.chk .dt{background:var(--on-accent)}
        .wgx-legend{display:flex;gap:.8rem;flex-wrap:wrap;font-size:.72rem;color:var(--muted);margin-top:.6rem}
        .wgx-legend i{display:inline-block;width:7px;height:7px;border-radius:50%;margin-right:.3rem;vertical-align:1px}
        .wgx-empty{color:var(--muted);font-size:.84rem;padding:.6rem 0}
        .wgx-pop{position:fixed;z-index:60;width:min(340px,calc(100vw - 24px));background:var(--card);border:1px solid var(--line);border-radius:12px;box-shadow:var(--shadow);padding:.8rem .9rem;display:flex;flex-direction:column;gap:.5rem;font-size:.8rem}
        .wgx-pop .pt{display:flex;align-items:center;gap:.45rem;font-weight:700;color:var(--text-strong);font-size:.9rem}
        .wgx-pop dl{display:grid;grid-template-columns:auto minmax(0,1fr);gap:.2rem .7rem;margin:0}
        .wgx-pop dt{color:var(--muted)}
        .wgx-pop dd{margin:0;min-width:0;overflow-wrap:anywhere}
        .wgx-pop .pa{display:flex;flex-wrap:wrap;gap:.35rem;padding-top:.45rem;border-top:1px solid var(--line)}
        .wgx-bulk{position:sticky;bottom:.6rem;z-index:5;display:flex;flex-wrap:wrap;align-items:center;gap:.45rem;margin-top:.8rem;padding:.55rem .7rem;border-radius:10px;background:var(--card);border:1px solid var(--accent);box-shadow:var(--shadow);font-size:.8rem}
        .wgx-bulk select,.wgx-bulk input{width:auto;padding:.28rem .5rem;font-size:.8rem;min-width:0}
        .wgx-bulk input{flex:1 1 7rem;max-width:12rem}
        .wgx-who{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:1rem}
        @media(max-width:900px){.wgx-who{grid-template-columns:minmax(0,1fr)}}
        .wgx-fmt{display:grid;grid-template-columns:auto auto minmax(0,1fr);gap:.3rem .8rem;font-size:.8rem;align-items:center}
        .wgx-fmt .fh{font-size:.68rem;letter-spacing:.05em;text-transform:uppercase;color:var(--muted)}
        .wgx-fmt b{color:var(--text-strong);font-weight:600}
        .wgx-ok{color:var(--c-ok-fg);font-weight:600}
        .wgx-no{color:var(--muted)}
        .wgx-sub{font-size:.78rem;color:var(--muted);margin-top:.55rem;line-height:1.5}
        .wgx-ex{display:flex;flex-direction:column;gap:.35rem}
        .wgx-ex-r{display:flex;gap:.35rem;align-items:center}
        .wgx-ex-r input{flex:1 1 auto;padding:.3rem .5rem;font-size:.8rem;font-family:monospace}
        .wgx .wgx-ex-r select{width:auto;flex:none;min-width:10.5rem;white-space:nowrap;padding:.3rem 1.8rem .3rem .5rem;font-size:.8rem}
        .wgx details summary{cursor:pointer;font-size:.82rem;color:var(--accent-text);font-weight:600}
        .wgx details p,.wgx details li{font-size:.8rem;color:var(--muted);line-height:1.55}
        .wgx-dr-ov{position:fixed;inset:0;z-index:90;background:rgba(3,6,15,.55)}
        .wgx-dr{position:fixed;top:0;right:0;bottom:0;z-index:91;width:min(620px,100vw);background:var(--card);border-left:1px solid var(--line);box-shadow:var(--shadow);display:flex;flex-direction:column}
        .wgx-dr-h{display:flex;align-items:center;justify-content:space-between;padding:.85rem 1.1rem;border-bottom:1px solid var(--line);font-weight:700;color:var(--text-strong)}
        .wgx-dr-b{padding:1rem 1.1rem;overflow-y:auto;display:flex;flex-direction:column;gap:.8rem;flex:1}
        .wgx-dr-f{padding:.75rem 1.1rem;border-top:1px solid var(--line);display:flex;align-items:center;justify-content:space-between;gap:.6rem;flex-wrap:wrap}
        .wgx-drop{border:1.5px dashed var(--line);border-radius:10px;padding:1rem;text-align:center;font-size:.82rem;color:var(--muted);display:flex;flex-direction:column;gap:.5rem;align-items:center}
        .wgx-drop.on{border-color:var(--accent);background:var(--accent-light)}
        .wgx-dr textarea{font-family:monospace;font-size:.78rem}
        .wgx-dr .lbl{font-size:.78rem;font-weight:600;color:var(--muted);margin:0 0 .3rem}
        .wgx-dr .row2{display:grid;grid-template-columns:1fr 1fr;gap:.6rem}
        .wgx-dr .row2 input{padding:.4rem .55rem;font-size:.84rem}
        .wgx-prev{width:100%;border-collapse:collapse;font-size:.78rem}
        .wgx-prev td{padding:.3rem .35rem;border-top:1px solid var(--line);vertical-align:top}
        .wgx-prev td:first-child,.wgx-prev td:nth-child(2){white-space:nowrap}
        .wgx-prev td.ep{font-family:monospace;font-size:.72rem;color:var(--muted);overflow-wrap:anywhere}
        .wgx-prev .bad{color:var(--c-bad-fg)}
        .wgx-prev .wn{color:var(--c-warn-fg)}
        .wgx .sq-grid{grid-template-columns:repeat(auto-fill,minmax(150px,1fr))}
        [hidden]{display:none!important}
    </style>

<div class="wgx">
    <div class="card">
        <div class="wgx-top">
            <div class="wgx-kpi">
                <span><b><?= (int) $wgx_kpi['all'] ?></b>конфигов</span>
                <span><b><?= (int) $wgx_kpi['used'] ?></b>выдано</span>
                <span><b><?= (int) $wgx_kpi['free'] ?></b>свободно</span>
                <?php if ($wgx_kpi['shared']): ?><span><b><?= (int) $wgx_kpi['shared'] ?></b>общих</span><?php endif; ?>
                <?php if ($wgx_kpi['off']): ?><span><b><?= (int) $wgx_kpi['off'] ?></b>выключено</span><?php endif; ?>
                <?php if ($wgx_man): ?><span><b><?= count($wgx_man) ?></b>вручную</span><?php endif; ?>
            </div>
            <div class="wgx-acts">
                <button type="button" class="wb" id="wgxManOpen"><svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg>Назначить вручную</button>
                <button type="button" class="wb pri" data-upload=""><svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 9l5-5 5 5M12 4v12"/></svg>Загрузить .conf</button>
            </div>
        </div>
        <?php if ($wg_mig_note): ?>
            <div class="wgx-note"><div><b>После обновления прослойки</b><ul style="margin:.3rem 0 0;padding-left:1.1rem"><?php foreach ($wg_mig_note as $ln): ?><li><?= h($ln) ?></li><?php endforeach; ?></ul></div><form method="post" style="margin:0 0 0 auto"><input type="hidden" name="csrf" value="<?= h($token) ?>"><input type="hidden" name="action" value="wg_mig_ack"><button type="submit" class="wb sm">Понятно</button></form></div>
        <?php endif; ?>
        <?php if ($sqcfg_squads_err !== ''): ?>
            <div class="wgx-note bad"><span>Список сквадов недоступен: <?= h($sqcfg_squads_err) ?>. Проверьте URL панели и токен во вкладке «Подключение».</span></div>
        <?php endif; ?>
        <?php if ($sqcfg_dupes): ?>
            <div class="wgx-note bad"><span>Дубли выдачи: <b><?= count($sqcfg_dupes) ?></b> конфиг(ов) числятся выданными больше одного раза — это наследие прошлых версий.</span><form method="post" style="margin:0 0 0 auto" onsubmit="return uiConfirmForm(this,'Сбросить все автовыдачи? Ручные назначения останутся. Клиентам нужно будет один раз обновить подписку.','Сбросить')"><input type="hidden" name="csrf" value="<?= h($token) ?>"><input type="hidden" name="action" value="pool_reset_leases"><button type="submit" class="wb sm">Сбросить выдачи</button></form></div>
        <?php endif; ?>
        <?php if ($wgx_kpi['v3']): ?>
            <div class="wgx-note"><span><span class="wgx-vt wv-3">AWG 3.x</span> В пулах есть конфиги AmneziaWG 3.x — их получат: <b><?= h(implode(', ', squadconf_awg_audience('3.0'))) ?></b>. Throne до версии <?= h(awg_client_min('throne', '3.0')) ?> их не получает, а на mihomo до <?= h(awg_client_min('mihomo', '3.0')) ?> узел будет в списке, но не подключится.</span></div>
        <?php endif; ?>
    </div>

    <div class="wgx-grid">
        <div class="card">
            <div class="wgx-h"><h2>Пулы</h2><span class="wgx-pm">пул = сквад панели</span></div>
            <?php if (!$wgx_order && !$wgx_man_cfgs): ?>
                <p class="wgx-empty"><?= $sqcfg_squads_err !== '' ? 'Сквады панели недоступны — пока можно загрузить конфиги только в ручную привязку.' : 'В панели нет внутренних сквадов. Конфиги можно загрузить в ручную привязку.' ?></p>
            <?php endif; ?>
            <form method="post" id="wgxModes" autocomplete="off">
                <input type="hidden" name="csrf" value="<?= h($token) ?>">
                <input type="hidden" name="action" value="save_pool_modes">
                <div class="wgx-pools">
                <?php foreach ($wgx_order as $pk): $pl = $wgx_pools[$pk]; $pm = $pl['mode']; $need = $wgx_need($pk); ?>
                    <div class="wgx-pool<?= $pl['n'] ? '' : ' empty' ?>" data-pool="<?= h($pk) ?>">
                        <div class="wgx-ph">
                            <button type="button" class="wgx-pname" data-pool="<?= h($pk) ?>" title="Показать конфиги этого пула"><?= h($wgx_pname($pk)) ?></button>
                            <span class="wgx-pm"><?= isset($wgx_members[$pk]) ? (int) $wgx_members[$pk] . ' польз.' : '' ?></span>
                            <span class="wgx-vts"><?php if (!$pl['n']): ?><span class="wgx-pm">пусто</span><?php endif; ?><?php foreach (array_keys($pl['vers']) as $v): ?><span class="wgx-vt <?= awg_ver_class($v) ?>"><?= h($v) ?></span><?php endforeach; ?></span>
                        </div>
                        <div class="wgx-seg" role="radiogroup" aria-label="Как выдавать в <?= h($wgx_pname($pk)) ?>">
                            <?php foreach (['shared' => 'Всем', 'users' => 'Пользователю', 'devices' => 'Устройству'] as $mv => $ml): ?>
                                <label title="<?= $mv === 'shared' ? 'Одни и те же конфиги всем подписчикам сквада' : ($mv === 'users' ? 'Отдельный ключ каждому пользователю' : 'Отдельный ключ каждому устройству (нужен hwid)') ?>"><input type="radio" name="pool_mode[<?= h($pk) ?>]" value="<?= $mv ?>" data-was="<?= $pm === $mv ? '1' : '0' ?>"<?= $pm === $mv ? ' checked' : '' ?>><?= $ml ?></label>
                            <?php endforeach; ?>
                        </div>
                        <?php foreach ($pl['groups'] as $gk => $g): $tot = (int) $g['on']; $fr = (int) $g['free']; $short = $need !== null && $tot < $need; $low = $pm !== 'shared' && (($tot > 0 && $fr === 0) || $short); $pct = $tot > 0 ? (int) round(($pm === 'shared' ? $tot : $fr) * 100 / $tot) : 0; ?>
                            <div class="wgx-gr">
                                <span class="gn"><?= h($g['label'] !== '' ? $g['label'] : 'без группы') ?> <span class="wgx-pm">· <?= h(implode(', ', array_keys($g['vers']))) ?></span></span>
                                <?php if ($pm === 'shared'): ?>
                                    <span class="gc"><b><?= $tot ?></b> всем</span>
                                <?php else: ?>
                                    <span class="gc<?= $low ? ' low' : '' ?>"<?= $short ? ' title="Нужно ~' . (int) $need . ', а конфигов ' . $tot . '"' : '' ?>><b><?= $fr ?></b> из <?= $tot ?> свободно<?= $short ? ' · нужно ~' . (int) $need : '' ?></span>
                                <?php endif; ?>
                                <span class="wgx-bar<?= $low ? ' low' : '' ?>"><i style="width:<?= $pct ?>%"></i></span>
                            </div>
                        <?php endforeach; ?>
                        <div class="wgx-pf">
                            <button type="button" class="wb sm" data-upload="<?= h($pk) ?>">+ Загрузить сюда</button>
                            <?php if ($need !== null): ?><span class="wgx-pm">в каждую группу нужно ~<?= (int) $need ?> — по числу <?= $pm === 'devices' ? 'устройств' : 'активных' ?></span><?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
                <?php if ($wgx_man_cfgs || $wgx_man): ?>
                    <div class="wgx-pool" data-pool="__manual__">
                        <div class="wgx-ph">
                            <button type="button" class="wgx-pname" data-pool="__manual__" title="Показать конфиги ручной привязки">Ручная привязка</button>
                            <span class="wgx-pm"><?= count($wgx_man) ?> из <?= (int) $wgx_man_cfgs ?> назначено</span>
                            <span class="wgx-vts"><?php foreach (array_keys($wgx_pools['__manual__']['vers'] ?? []) as $v): ?><span class="wgx-vt <?= awg_ver_class($v) ?>"><?= h($v) ?></span><?php endforeach; ?></span>
                        </div>
                        <?php if ($wgx_man): ?>
                        <div class="wgx-man">
                            <?php foreach ($wgx_man as $l): $lc = $wgx_cfg[(int) $l['config_id']] ?? ['n' => '#' . (int) $l['config_id'] . ' — конфиг удалён']; $lsu = (string) $l['short_uuid']; $lhw = (string) ($l['hwid'] ?? ''); $ln = !empty($wg_uc[$lsu]['u']) ? (string) $wg_uc[$lsu]['u'] : $lsu; $ldev = $lhw !== '' ? (string) ($wg_uc[$lsu]['d'][$lhw]['m'] ?? ($sqcfg_hwid_plat[$lhw] ?? 'устройство')) : 'любое устройство'; ?>
                                <div>
                                    <span class="mu" title="<?= h($lsu) ?>"><?= h($ln) ?></span>
                                    <span class="mc"><?= h($lc['n'] !== '' ? $lc['n'] : ('#' . (int) $l['config_id'])) ?> · <?= h($ldev) ?></span>
                                    <button type="button" class="wx" title="Снять назначение" data-mandel="<?= (int) $l['id'] ?>" aria-label="Снять назначение">✕</button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                        <div class="wgx-pf"><button type="button" class="wb sm" data-manopen>Назначить</button><button type="button" class="wb sm" data-upload="__manual__">+ Загрузить сюда</button></div>
                    </div>
                <?php endif; ?>
                </div>
                <div class="wgx-days" title="Если клиент не обновлял подписку столько дней, его автовыдача освобождается для других">Слот вернётся в пул через <input type="number" name="wgpool_reclaim_days" id="wgxDays" value="<?= (int) $sqcfg_reclaim_days ?>" min="1" max="365" aria-label="Дней"> дн. тишины</div>
            </form>
            <?php if (!empty($sqcfg_sizing['warn'])): ?><div class="wgx-note bad" style="margin-top:.6rem"><span>Потребность посчитана не полностью: <?= h($sqcfg_sizing['warn']) ?></span></div><?php endif; ?>
            <?php if ($wgx_order): ?>
            <div class="wgx-pf" style="margin-top:.6rem">
                <button type="button" class="wb sm" id="wgxCalc" title="Посчитать по панели, сколько активных пользователей и устройств в каждом скваде">Посчитать потребность</button>
                <form method="post" style="margin:0" onsubmit="return uiConfirmForm(this,'Сбросить все автовыдачи? Ручные назначения останутся. Клиентам нужно будет один раз обновить подписку.','Сбросить')"><input type="hidden" name="csrf" value="<?= h($token) ?>"><input type="hidden" name="action" value="pool_reset_leases"><button type="submit" class="wb sm">Сбросить автовыдачи</button></form>
                <span class="wgx-pm" id="wgxCalcMsg"></span>
            </div>
            <?php endif; ?>
        </div>

        <div class="card" id="wgxCfg">
            <div class="wgx-h">
                <h2>Конфиги</h2>
                <?php if ($sqcfg_wg): ?><button type="button" class="wb sm" id="wgxSelMode" aria-pressed="false">Выбрать</button><?php endif; ?>
            </div>
            <div class="wgx-tools">
                    <div class="wgx-chf" id="wgxSt" role="group" aria-label="Состояние">
                        <button type="button" class="on" data-st="">Все<span><?= (int) $wgx_cnt[''] ?></span></button>
                        <button type="button" data-st="free">Свободны<span><?= (int) $wgx_cnt['free'] ?></span></button>
                        <button type="button" data-st="used">Выданы<span><?= (int) $wgx_cnt['used'] ?></span></button>
                        <?php if ($wgx_cnt['all']): ?><button type="button" data-st="all">Общие<span><?= (int) $wgx_cnt['all'] ?></span></button><?php endif; ?>
                        <?php if ($wgx_cnt['off']): ?><button type="button" data-st="off">Выключены<span><?= (int) $wgx_cnt['off'] ?></span></button><?php endif; ?>
                    </div>
                    <select id="wgxPool" aria-label="Пул">
                        <option value="">Все пулы</option>
                        <?php foreach ($wgx_filled as $pk): ?><option value="<?= h($pk) ?>"><?= h($wgx_pname($pk)) ?></option><?php endforeach; ?>
                        <?php if ($wgx_man_cfgs): ?><option value="__manual__">Ручная привязка</option><?php endif; ?>
                    </select>
                    <input type="search" id="wgxQ" placeholder="метка, адрес, пользователь" aria-label="Поиск">
            </div>
            <?php if (!$sqcfg_wg): ?>
                <p class="wgx-empty">Здесь будут конфиги. Загрузите .conf-файлы — можно сразу пачкой.</p>
            <?php endif; ?>
            <div id="wgxList">
            <?php foreach (array_merge($wgx_filled, $wgx_man_cfgs ? ['__manual__'] : []) as $pk): $pl = $wgx_pools[$pk]; ?>
                <div class="wgx-sec" data-pool="<?= h($pk) ?>">
                    <div class="wgx-sec-h"><?= h($wgx_pname($pk)) ?> <span style="text-transform:none;letter-spacing:0;font-weight:500"><?= $pl['mode'] === 'manual' ? '' : h(['shared' => '· всем', 'users' => '· на пользователя', 'devices' => '· на устройство'][$pl['mode']] ?? '') ?></span></div>
                    <?php foreach ($pl['groups'] as $gk => $g): $mixed = count($g['vers']) > 1; ?>
                        <div class="wgx-grp">
                            <div class="wgx-grp-h"><b><?= h($g['label'] !== '' ? $g['label'] : 'без группы') ?></b><?php if (!$mixed): ?><span class="wgx-vt <?= awg_ver_class(array_key_first($g['vers'])) ?>"><?= h(array_key_first($g['vers'])) ?></span><?php endif; ?><span><?= count($g['ids']) ?> шт.</span></div>
                            <div class="wgx-chips">
                            <?php foreach ($g['ids'] as $cid): $x = $wgx_cell[$pk . ':' . $cid]; $tip = $x['n'] . ($x['st'] === 'used' ? ' — ' . ($x['u'] !== '' ? $x['u'] : $x['su']) : ($x['st'] === 'free' ? ' — свободен' : ($x['st'] === 'off' ? ' — выключен' : ' — всем'))); ?>
                                <button type="button" class="wgx-chip st-<?= $x['st'] ?>" data-id="<?= $cid ?>" data-k="<?= h($pk . ':' . $cid) ?>" data-st="<?= $x['st'] ?>" data-q="<?= h(mb_strtolower($x['n'] . ' ' . $x['ep'] . ' ' . $x['u'] . ' ' . $x['su'])) ?>" title="<?= h($tip) ?>"><i class="dt"></i><span class="lb"><?= h($x['n'] !== '' ? $x['n'] : ('#' . $cid)) ?></span><?php if ($mixed): ?><span class="wgx-vt <?= h($x['vc']) ?>"><?= h(str_replace('AWG ', '', $x['v'])) ?></span><?php endif; ?></button>
                            <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
            </div>
            <?php if ($sqcfg_wg): ?>
            <p class="wgx-empty" id="wgxNone" hidden>Ничего не найдено — измените фильтр.</p>
            <div class="wgx-legend"><span><i style="background:var(--c-ok-fg)"></i>свободен</span><span><i style="background:var(--accent)"></i>выдан или общий</span><span><i style="background:var(--muted)"></i>выключен</span><span>нажмите на конфиг — подробности и действия</span></div>
            <div class="wgx-bulk" id="wgxBulk" hidden>
                <b id="wgxBulkN">0</b> выбрано
                <select id="wgxBulkAct" aria-label="Действие">
                    <option value="grp">Задать группу</option>
                    <option value="mtu">MTU</option>
                    <option value="keepalive">PersistentKeepalive</option>
                    <option value="dns">DNS</option>
                    <option value="allowedips">AllowedIPs</option>
                    <option value="del">Удалить</option>
                </select>
                <input type="text" id="wgxBulkVal" placeholder="значение (пусто — убрать)" aria-label="Значение">
                <button type="button" class="wb sm pri" id="wgxBulkGo">Применить</button>
                <button type="button" class="wb sm" id="wgxBulkAll">Выбрать видимые</button>
                <button type="button" class="wb sm" id="wgxBulkX">Отмена</button>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="wgx-h"><h2>Кто что получает</h2><span class="wgx-pm">решается само — по формату, который панель отдала клиенту</span></div>
        <div class="wgx-who">
            <div>
                <div class="wgx-fmt">
                    <span class="fh">Формат</span><span class="fh">Ядро</span><span class="fh">Добавляем</span>
                    <?php
                    $wgx_enc = ['wireguard' => 'wireguard://', 'wg' => 'wg://', 'incy_uri' => 'amneziawg://', 'incy_box' => 'JSON xray', 'amnezia_wg' => 'JSON sing-box', 'singbox' => 'JSON sing-box', 'xray' => 'JSON xray'];
                    foreach (squadconf_delivery_matrix() as $r):
                        $wgk = $r['wg'];
                        $wgh = array_values(array_unique(array_filter(array_map(fn($e) => ($r['core'] === 'свой' || str_ends_with($wgx_enc[$e] ?? '', '://')) ? ($wgx_enc[$e] ?? '') : '', $wgk))));
                        $awh = array_values(array_unique(array_filter(array_map(fn($e) => $r['core'] === 'свой' ? ($wgx_enc[$e] ?? '') : '', $r['awg']))));
                        $vh = $r['min'] !== '' ? awg_client_ranges($r['min']) : '';
                    ?>
                    <b><?= h($r['label']) ?></b><span><?= h($r['core']) ?></span><span><?php if ($wgk): ?><span class="wgx-ok">WG</span><?= $wgh ? ' <span class="wgx-pm">' . h(implode(', ', $wgh)) . '</span>' : '' ?><?php else: ?><span class="wgx-no">без WG</span><?= $r['wg_toggle'] ? ' <span class="wgx-pm">(включается на «Доп. конфигах»)</span>' : '' ?><?php endif; ?> · <?php if ($r['awg']): ?><span class="wgx-ok">AWG</span><?php $ah = array_filter([$r['note'] ?? '', $awh ? implode(', ', $awh) : '', $vh]); ?><?= $ah ? ' <span class="wgx-pm">' . h(implode('; ', $ah)) . '</span>' : '' ?><?php else: ?><span class="wgx-no">без AWG</span><?php endif; ?></span>
                    <?php endforeach; ?>
                </div>
                <p class="wgx-sub">Поддерживаются официальные ядра Xray, mihomo и sing-box, а также Throne и INCY — у них AmneziaWG в собственном движке. Другие сборки-форки (sing-box-extended, sing-box-lx, amnezia-box и прочие) получают то же, что официальный sing-box или Xray. Конфиг, который клиент не примет, не уходит ему и не занимает слот пула.</p>
            </div>
            <form method="post" autocomplete="off" id="wgxExForm">
                <input type="hidden" name="csrf" value="<?= h($token) ?>">
                <input type="hidden" name="action" value="save_wg_except">
                <div class="lbl" style="font-size:.8rem;font-weight:600;margin-bottom:.4rem">Исключения <span class="wgx-pm" style="font-weight:400">— подстрока User-Agent клиента</span></div>
                <div class="wgx-ex" id="wgxEx">
                    <?php foreach ($wgx_except as $r): ?>
                        <div class="wgx-ex-r"><input type="text" name="ex_ua[]" value="<?= h($r['ua']) ?>" maxlength="60" aria-label="Подстрока UA"><select name="ex_block[]" aria-label="Что не отдавать"><option value="awg"<?= $r['block'] === 'awg' ? ' selected' : '' ?>>без AWG</option><option value="all"<?= $r['block'] === 'all' ? ' selected' : '' ?>>без WG и AWG</option></select><button type="button" class="wx" data-exdel aria-label="Убрать">✕</button></div>
                    <?php endforeach; ?>
                </div>
                <div class="wgx-pf" style="margin-top:.45rem"><button type="button" class="wb sm" id="wgxExAdd">+ Исключение</button><button type="submit" class="wb sm pri" id="wgxExSave" hidden>Сохранить</button></div>
                <p class="wgx-sub" style="margin-top:.4rem">Например, Clash-клиент не на mihomo, который не тянет AmneziaWG, или Throne, если ему нужен только WG. Обычно список пуст.</p>
            </form>
        </div>
        <details style="margin-top:.6rem">
            <summary>Как работает пул</summary>
            <p>WireGuard и AmneziaWG узнают клиента по публичному ключу: если два устройства работают с одним ключом, соединение «прыгает» между ними. Поэтому прослойка раздаёт готовые конфиги из пула и закрепляет за пользователем или устройством отдельный ключ. Ключи она не создаёт — их делают в Amnezia, WG-панели или CLI.</p>
            <ul>
                <li><b>Всем</b> — конфиги сквада получают все его подписчики. Когда уникальный ключ не нужен.</li>
                <li><b>На пользователя</b> — каждому подписчику свой конфиг из каждой группы; hwid не нужен.</li>
                <li><b>На устройство</b> — свой конфиг на каждое устройство (hwid). Клиент без hwid конфиг не получает.</li>
                <li><b>Группа</b> делит пул сквада: загрузите германские конфиги с группой DE, нидерландские — с NL, и каждый получит 1 DE + 1 NL.</li>
                <li><b>Throne и INCY</b> получают AmneziaWG в своём формате. INCY на компьютере AWG не поддерживает — ему уходит только WG, и слот AWG он не занимает.</li>
                <li><b>Ручная привязка</b> — конфиг закрепляется за конкретным пользователем в обход сквадов. В грейсе не выдаётся.</li>
            </ul>
        </details>
    </div>
</div>

<div class="wgx"><div class="wgx-pop" id="wgxPop" hidden role="dialog" aria-label="Конфиг">
    <div class="pt"><span id="wgxPopN"></span><span class="wgx-vt" id="wgxPopV"></span><button type="button" class="wx" id="wgxPopX" style="margin-left:auto" aria-label="Закрыть">✕</button></div>
    <dl id="wgxPopD"></dl>
    <div class="pa">
        <button type="button" class="wb sm sqcfg-edit" id="wgxPopEdit" data-id="">Изменить</button>
        <button type="button" class="wb sm" id="wgxPopFree">Освободить</button>
        <button type="button" class="wb sm" id="wgxPopTog">Выключить</button>
        <button type="button" class="wb sm" id="wgxPopDl">Скачать .conf</button>
        <button type="button" class="wb sm bad" id="wgxPopDel">Удалить</button>
    </div>
</div></div>

<form method="post" id="wgxF1" hidden><input type="hidden" name="csrf" value="<?= h($token) ?>"><input type="hidden" name="action" value=""><input type="hidden" name="ret" value="wg_pool"><input type="hidden" name="id" value=""><input type="hidden" name="enabled" value=""></form>
<form method="post" id="wgxFB" hidden><input type="hidden" name="csrf" value="<?= h($token) ?>"><input type="hidden" name="action" value=""><input type="hidden" name="ret" value="wg_pool"><input type="hidden" name="ids" value=""><input type="hidden" name="param" value=""><input type="hidden" name="value" value=""><input type="hidden" name="group" value=""></form>

<div class="wgx-dr-ov" id="wgxDrOv" hidden></div>
<div class="wgx-dr" id="wgxDr" hidden role="dialog" aria-label="Загрузка конфигов">
    <form method="post" enctype="multipart/form-data" autocomplete="off" id="wgUpForm" style="display:contents">
        <input type="hidden" name="csrf" value="<?= h($token) ?>">
        <input type="hidden" name="action" value="batch_wg_config">
        <input type="hidden" name="files_json" id="wgFilesJson">
        <div class="wgx-dr-h"><span>Загрузка конфигов</span><button type="button" class="modal-x" id="wgxDrX" aria-label="Закрыть">×</button></div>
        <div class="wgx-dr-b wgx">
            <div class="wgx-drop" id="wgxDrop">
                <span>Перетащите .conf-файлы сюда — до 200 за раз</span>
                <label class="wb sm" style="margin:0;cursor:pointer"><input type="file" name="conf_files[]" id="wgFiles" accept=".conf,.txt" multiple hidden>Выбрать файлы</label>
            </div>
            <div>
                <div class="lbl">…или вставьте текст нескольких конфигов подряд</div>
                <textarea name="raw_batch" id="wgxRaw" rows="4" spellcheck="false" placeholder="[Interface]&#10;PrivateKey = …&#10;[Peer]&#10;…"></textarea>
            </div>
            <table class="wgx-prev" id="wgxPrev" hidden><tbody></tbody></table>
            <div>
                <div class="lbl">Куда</div>
                <div class="sq-grid" id="wgxDest">
                    <label class="sq-item sq-manual"><input type="checkbox" name="squads[]" value="__manual__"><span class="sq-mtxt"><span class="sq-n">Ручная привязка</span><span class="muted" style="font-size:.72rem">в обход сквадов</span></span></label>
                    <?php foreach ($sqcfg_squads as $s): ?>
                        <label class="sq-item"><input type="checkbox" name="squads[]" value="<?= h($s['uuid']) ?>"><span class="sq-n"><?= h($s['name']) ?></span><span class="muted" style="font-size:.78rem"><?= $s['members'] === '' ? '' : (int) $s['members'] ?></span></label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="row2">
                <div><div class="lbl">Группа</div><input type="text" name="grp" maxlength="64" placeholder="напр. DE"></div>
                <div><div class="lbl">Префикс метки</div><input type="text" name="label_prefix" class="sqcfg-flag" maxlength="120" placeholder="напр. Германия"></div>
            </div>
            <p class="wgx-sub" style="margin:0">Флаг страны подставится сам: «Германия» → 🇩🇪. Без префикса метка берётся из имени файла. Битые и не WG/AWG конфиги пропускаются.</p>
        </div>
        <div class="wgx-dr-f wgx"><span class="wgx-pm" id="wgxUpSum">Выберите файлы или вставьте текст</span><button type="submit" class="wb pri" id="wgxUpGo" disabled>Загрузить</button></div>
    </form>
</div>

<div id="wgxManModal" class="modal-overlay">
    <div class="modal">
        <div class="modal-head"><div>Назначить конфиг вручную</div><button type="button" class="modal-x" data-manclose>×</button></div>
        <div class="modal-body">
            <form method="post" autocomplete="off">
                <input type="hidden" name="csrf" value="<?= h($token) ?>">
                <input type="hidden" name="action" value="pool_manual_add">
                <input type="hidden" name="ret" value="wg_pool">
                <input type="hidden" name="short_uuid" id="wgm_short">
                <label>Пользователь</label>
                <div style="display:flex;gap:.4rem;margin-bottom:.4rem"><input type="text" id="wgm_q" placeholder="shortUuid или имя" style="flex:1"><button type="button" class="btn ghost" id="wgm_find">Найти</button></div>
                <div id="wgm_info" class="muted" style="font-size:.82rem;margin-bottom:.8rem"></div>
                <label>Конфиг</label>
                <select name="config_id" id="wgm_cfg" style="margin-bottom:.8rem">
                    <option value="">—</option>
                    <?php foreach ($sqcfg_wg as $c): if ((int) $c['enabled'] !== 1 || !in_array('__manual__', squadconf_squads_of($c), true)) continue; $mx = $wgx_cfg[(int) $c['id']]; ?><option value="<?= (int) $c['id'] ?>"><?= h(($mx['n'] !== '' ? $mx['n'] : ('#' . $c['id'])) . ' · ' . $mx['v'] . ($mx['busy'] ? ' · занят' : '')) ?></option><?php endforeach; ?>
                </select>
                <label>Устройство</label>
                <select id="wgm_hwid" name="hwid" style="margin-bottom:1rem"><option value="">любое (на пользователя)</option></select>
                <p class="muted" style="font-size:.78rem;margin:0 0 1rem">Конфиги для ручной привязки — загруженные в «Ручную привязку». Одно назначение на пользователя или устройство: новое заменяет прежнее. Один конфиг не назначайте двум людям — это один ключ на двоих.</p>
                <button type="submit" class="btn" id="wgm_submit" disabled>Назначить</button>
            </form>
        </div>
    </div>
</div>

<form method="post" id="wgxManDelF" hidden><input type="hidden" name="csrf" value="<?= h($token) ?>"><input type="hidden" name="action" value="pool_manual_del"><input type="hidden" name="ret" value="wg_pool"><input type="hidden" name="id" value=""></form>

<div id="sqEditModal" class="modal-overlay">
    <div class="modal">
        <div class="modal-head"><div>Изменить конфиг</div><button type="button" class="modal-x" onclick="sqEditClose()">×</button></div>
        <div class="modal-body">
            <form method="post" autocomplete="off">
                <input type="hidden" name="csrf" value="<?= h($token) ?>">
                <input type="hidden" name="action" value="edit_squad_config">
                <input type="hidden" name="ret" value="wg_pool">
                <input type="hidden" name="id" id="sqedit_id" value="">
                <label>Куда</label>
                <div class="sq-grid" id="sqedit_chips" style="margin-bottom:.85rem">
                    <label class="sq-item sq-manual"><input type="checkbox" name="squads[]" value="__manual__"><span class="sq-mtxt"><span class="sq-n">Ручная привязка</span><span class="muted" style="font-size:.72rem">в обход сквадов</span></span></label>
                    <?php foreach ($wgx_sq_all as $s): ?>
                        <label class="sq-item"><input type="checkbox" name="squads[]" value="<?= h($s['uuid']) ?>"><span class="sq-n"><?= h($s['name']) ?></span><span class="muted" style="font-size:.78rem"><?= $s['members'] === '' ? '' : (int) $s['members'] ?></span></label>
                    <?php endforeach; ?>
                </div>
                <div style="display:grid;grid-template-columns:2fr 1fr;gap:.6rem;margin-bottom:.85rem">
                    <div><label>Метка</label><input type="text" name="name" id="sqedit_name" class="sqcfg-flag" maxlength="191" required></div>
                    <div><label>Группа</label><input type="text" name="grp" id="sqedit_grp" maxlength="64" placeholder="напр. NL"></div>
                </div>
                <label>Конфиг</label>
                <textarea name="raw" id="sqedit_raw" rows="11" spellcheck="false" required style="font-family:monospace;font-size:.8rem;margin-bottom:.85rem"></textarea>
                <div style="display:flex;gap:.6rem"><button type="submit" class="btn">Сохранить</button><button type="button" class="btn ghost" onclick="sqEditClose()">Отмена</button></div>
            </form>
        </div>
    </div>
</div>

<?php include __DIR__ . '/_sqcfg_js.php'; ?>
<script>
window.SQCFG = <?= json_encode($sqcfg_edit, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
window.WGX = <?= json_encode($wgx_cell, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
sqcfgInitEdit();
(function(){
    var NAMES = <?= json_encode($wgx_names, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var CSRF = <?= json_encode($token) ?>;
    var D = window.WGX || {};
    var $ = function(id){ return document.getElementById(id); };
    function esc(s){ var d = document.createElement('div'); d.textContent = (s == null ? '' : String(s)); return d.innerHTML; }
    function ago(ts){ if (!ts) return ''; var d = Math.max(0, Math.floor(Date.now() / 1000) - ts); if (d < 3600) return Math.max(1, Math.floor(d / 60)) + ' мин назад'; if (d < 86400) return Math.floor(d / 3600) + ' ч назад'; return Math.floor(d / 86400) + ' дн назад'; }

    var stF = '', poolF = '', q = '', sel = false, picked = {};
    var chips = Array.prototype.slice.call(document.querySelectorAll('.wgx-chip'));
    function apply(){
        var any = 0;
        document.querySelectorAll('.wgx-sec').forEach(function(sec){
            var secVis = 0;
            if (poolF && sec.dataset.pool !== poolF) { sec.hidden = true; return; }
            sec.querySelectorAll('.wgx-grp').forEach(function(g){
                var n = 0;
                g.querySelectorAll('.wgx-chip').forEach(function(c){
                    var ok = (!stF || c.dataset.st === stF) && (!q || c.dataset.q.indexOf(q) > -1);
                    c.hidden = !ok; if (ok) n++;
                });
                g.hidden = n === 0; secVis += n;
            });
            sec.hidden = secVis === 0; any += secVis;
        });
        if ($('wgxNone')) $('wgxNone').hidden = any > 0 || !chips.length;
        document.querySelectorAll('.wgx-pool').forEach(function(p){ p.classList.toggle('sel', !!poolF && p.dataset.pool === poolF); });
    }
    var stBox = $('wgxSt');
    if (stBox) stBox.addEventListener('click', function(e){ var b = e.target.closest('button'); if (!b) return; stF = b.dataset.st; stBox.querySelectorAll('button').forEach(function(x){ x.classList.toggle('on', x === b); }); apply(); });
    if ($('wgxPool')) $('wgxPool').addEventListener('change', function(){ poolF = this.value; apply(); });
    if ($('wgxQ')) $('wgxQ').addEventListener('input', function(){ q = this.value.trim().toLowerCase(); apply(); });
    document.querySelectorAll('.wgx-pname').forEach(function(b){ b.addEventListener('click', function(){ var v = b.dataset.pool; poolF = poolF === v ? '' : v; if ($('wgxPool')) $('wgxPool').value = poolF; apply(); if (poolF && window.innerWidth <= 1100) $('wgxCfg').scrollIntoView({behavior: 'smooth', block: 'start'}); }); });

    var pop = $('wgxPop'), cur = null, f1 = $('wgxF1');
    function f1go(action, id, extra){ f1.elements.action.value = action; f1.elements.id.value = id; f1.elements.enabled.value = extra || ''; f1.submit(); }
    function popClose(){ pop.hidden = true; document.querySelectorAll('.wgx-chip.pk').forEach(function(c){ c.classList.remove('pk'); }); cur = null; }
    function cid(){ return cur && D[cur] ? String(D[cur].id) : ''; }
    function popOpen(chip){
        var k = chip.dataset.k, id = chip.dataset.id, x = D[k]; if (!x) return;
        if (cur) popClose();
        cur = k; chip.classList.add('pk');
        $('wgxPopN').textContent = x.n || ('#' + id);
        var v = $('wgxPopV'); v.textContent = x.v; v.className = 'wgx-vt ' + x.vc;
        var pn = function(s){ return NAMES[s] || s; };
        var also = (x.sq || []).filter(function(s){ return s !== x.p; }).map(pn).join(', ');
        var rows = [['Пул', pn(x.p) + (x.g ? ' · группа ' + x.g : '')]];
        if (also) rows.push(['Также в', also]);
        rows.push(['Адрес', x.ep || '—']);
        if (x.st === 'used') {
            var who = (x.u || x.su) + (x.man ? ' · вручную' : '');
            var dev = x.hw ? ((x.dm ? x.dm + ' · ' : '') + (x.dp || 'устройство')) : 'любое устройство';
            rows.push(['Выдан', who]); rows.push(['Устройство', dev]);
            if (x.cl || x.seen) rows.push(['Клиент', (x.cl || '—') + (x.seen ? ' · ' + ago(x.seen) : '')]);
        } else rows.push(['Состояние', x.st === 'free' ? 'свободен' : (x.st === 'off' ? 'выключен' : 'общий — получают все в скваде')]);
        $('wgxPopD').innerHTML = rows.map(function(r){ return '<dt>' + esc(r[0]) + '</dt><dd>' + esc(r[1]) + '</dd>'; }).join('');
        $('wgxPopEdit').dataset.id = id;
        $('wgxPopFree').hidden = !(x.st === 'used' && !x.man);
        $('wgxPopTog').textContent = x.on ? 'Выключить' : 'Включить';
        var r = chip.getBoundingClientRect(), pw = Math.min(340, window.innerWidth - 24);
        pop.hidden = false;
        var ph = pop.offsetHeight, top = r.bottom + 6;
        if (top + ph > window.innerHeight - 8) top = Math.max(8, r.top - ph - 6);
        pop.style.top = top + 'px';
        pop.style.left = Math.min(Math.max(12, r.left), window.innerWidth - pw - 12) + 'px';
    }
    function bulkSync(){ var n = Object.keys(picked).length; $('wgxBulkN').textContent = n; }
    function setSel(on){ sel = on; $('wgxSelMode').setAttribute('aria-pressed', on ? 'true' : 'false'); $('wgxSelMode').classList.toggle('pri', on); $('wgxBulk').hidden = !on; if (!on) { picked = {}; chips.forEach(function(c){ c.classList.remove('chk'); }); } bulkSync(); popClose(); }
    document.addEventListener('click', function(e){
        var chip = e.target.closest('.wgx-chip');
        if (chip) {
            if (sel) { var id = chip.dataset.id, on = !picked[id]; if (on) picked[id] = 1; else delete picked[id]; document.querySelectorAll('.wgx-chip[data-id="' + id + '"]').forEach(function(c){ c.classList.toggle('chk', on); }); bulkSync(); return; }
            if (cur === chip.dataset.k) popClose(); else popOpen(chip);
            return;
        }
        if (!pop.hidden && !e.target.closest('#wgxPop')) popClose();
    });
    document.addEventListener('keydown', function(e){ if (e.key === 'Escape') { popClose(); drClose(); manClose(); } });
    window.addEventListener('resize', popClose);
    document.addEventListener('scroll', function(){ if (!pop.hidden) popClose(); }, true);
    $('wgxPopX').addEventListener('click', popClose);
    $('wgxPopEdit').addEventListener('click', popClose);
    $('wgxPopFree').addEventListener('click', function(){ var id = cid(); uiConfirm('Освободить конфиг? Выдача снимется, и конфиг уйдёт следующему подходящему клиенту.', function(){ f1go('pool_free_slot', id); }, 'Освободить', false); });
    $('wgxPopTog').addEventListener('click', function(){ var id = cid(), x = D[cur]; f1go('toggle_squad_config', id, x.on ? '0' : '1'); });
    $('wgxPopDel').addEventListener('click', function(){ var id = cid(), x = D[cur]; uiConfirm('Удалить конфиг «' + (x.n || ('#' + id)) + '»?' + (x.sq && x.sq.length > 1 ? ' Он удалится из всех пулов.' : ''), function(){ f1go('del_squad_config', id); }, 'Удалить', true); });
    $('wgxPopDl').addEventListener('click', function(){ var x = (window.SQCFG || {})[cid()]; if (!x) return; var a = document.createElement('a'); a.href = URL.createObjectURL(new Blob([x.raw], {type: 'text/plain'})); a.download = (String(x.name || 'wg').replace(/[^\w.\-]+/g, '_').replace(/^_+|_+$/g, '') || 'wg') + '.conf'; document.body.appendChild(a); a.click(); setTimeout(function(){ URL.revokeObjectURL(a.href); a.remove(); }, 500); });

    if ($('wgxSelMode')) $('wgxSelMode').addEventListener('click', function(){ setSel(!sel); });
    if ($('wgxBulkX')) $('wgxBulkX').addEventListener('click', function(){ setSel(false); });
    if ($('wgxBulkAll')) $('wgxBulkAll').addEventListener('click', function(){ chips.forEach(function(c){ if (!c.hidden && c.offsetParent !== null) { picked[c.dataset.id] = 1; c.classList.add('chk'); } }); bulkSync(); });
    if ($('wgxBulkAct')) $('wgxBulkAct').addEventListener('change', function(){ $('wgxBulkVal').hidden = this.value === 'del'; });
    if ($('wgxBulkGo')) $('wgxBulkGo').addEventListener('click', function(){
        var ids = Object.keys(picked); if (!ids.length) return;
        var a = $('wgxBulkAct').value, v = $('wgxBulkVal').value.trim(), f = $('wgxFB');
        f.elements.ids.value = ids.join(',');
        if (a === 'del') { f.elements.action.value = 'del_squad_configs'; uiConfirm('Удалить выбранные конфиги: ' + ids.length + '?', function(){ f.submit(); }, 'Удалить', true); return; }
        if (a === 'grp') { f.elements.action.value = 'bulk_set_group'; f.elements.group.value = v; uiConfirm('Задать группу «' + (v || 'без группы') + '» у ' + ids.length + ' конфиг(ов)?', function(){ f.submit(); }, 'Применить', false); return; }
        f.elements.action.value = 'bulk_edit_param'; f.elements.param.value = a; f.elements.value.value = v;
        uiConfirm('Изменить ' + $('wgxBulkAct').options[$('wgxBulkAct').selectedIndex].text + ' = «' + (v || 'убрать поле') + '» у ' + ids.length + ' конфиг(ов)?', function(){ f.submit(); }, 'Применить', false);
    });

    var mf = $('wgxModes');
    if (mf) mf.addEventListener('change', function(e){
        var t = e.target;
        if (t.id === 'wgxDays') { mf.submit(); return; }
        if (t.type !== 'radio') return;
        var grp = mf.querySelectorAll('input[name="' + t.name + '"]'), prev = null;
        grp.forEach(function(r){ if (r.dataset.was === '1') prev = r; });
        var nm = (t.closest('.wgx-pool').querySelector('.wgx-pname') || {}).textContent || '';
        var pending = true, dlg = document.getElementById('uiDlg');
        function back(){ if (pending && prev) prev.checked = true; pending = false; }
        uiConfirm('Сменить режим пула «' + nm + '» на «' + t.parentNode.textContent.trim() + '»? Автовыдачи этого пула сбросятся, клиенты получат конфиги заново при следующем обновлении подписки.', function(){ pending = false; mf.submit(); }, 'Сменить', false);
        if (dlg && window.MutationObserver) { var mo = new MutationObserver(function(){ if (!dlg.classList.contains('open')) { mo.disconnect(); setTimeout(back, 0); } }); mo.observe(dlg, {attributes: true, attributeFilter: ['class']}); }
    });

    document.addEventListener('click', function(e){
        var d = e.target.closest('[data-mandel]');
        if (!d) return;
        uiConfirm('Снять ручное назначение?', function(){ var f = $('wgxManDelF'); f.elements.id.value = d.dataset.mandel; f.submit(); }, 'Снять', true);
    });

    var calc = $('wgxCalc');
    if (calc) calc.addEventListener('click', function(){
        var m = $('wgxCalcMsg'); m.textContent = 'Считаю по панели…'; calc.disabled = true;
        fetch('?ajax=pool_sizing&csrf=' + encodeURIComponent(CSRF)).then(function(r){ return r.json(); }).then(function(d){
            if (!d.ok) { calc.disabled = false; m.textContent = 'Ошибка: ' + (d.error || 'нет данных'); return; }
            location.reload();
        }).catch(function(){ calc.disabled = false; m.textContent = 'Панель не ответила'; });
    });

    var exBox = $('wgxEx'), exSave = $('wgxExSave');
    function exDirty(){ if (exSave) exSave.hidden = false; }
    if ($('wgxExAdd')) $('wgxExAdd').addEventListener('click', function(){
        var r = document.createElement('div'); r.className = 'wgx-ex-r';
        r.innerHTML = '<input type="text" name="ex_ua[]" maxlength="60" placeholder="напр. stash" aria-label="Подстрока UA"><select name="ex_block[]" aria-label="Что не отдавать"><option value="awg">без AWG</option><option value="all">без WG и AWG</option></select><button type="button" class="wx" data-exdel aria-label="Убрать">✕</button>';
        exBox.appendChild(r); r.querySelector('input').focus(); exDirty();
    });
    if (exBox) { exBox.addEventListener('click', function(e){ var b = e.target.closest('[data-exdel]'); if (b) { b.parentNode.remove(); exDirty(); } }); exBox.addEventListener('input', exDirty); exBox.addEventListener('change', exDirty); }

    var dr = $('wgxDr'), drOv = $('wgxDrOv'), upF = $('wgUpForm'), files = $('wgFiles'), raw = $('wgxRaw'), prev = $('wgxPrev'), upGo = $('wgxUpGo'), sum = $('wgxUpSum');
    function drOpen(pool){
        dr.hidden = false; drOv.hidden = false;
        $('wgxDest').querySelectorAll('input[type=checkbox]').forEach(function(cb){ cb.checked = !!pool && cb.value === pool; var l = cb.closest('.sq-item'); if (l) l.classList.toggle('on', cb.checked); });
        refresh();
    }
    function drClose(){ if (dr) { dr.hidden = true; drOv.hidden = true; } }
    document.querySelectorAll('[data-upload]').forEach(function(b){ b.addEventListener('click', function(){ drOpen(b.dataset.upload); }); });
    if ($('wgxDrX')) $('wgxDrX').addEventListener('click', drClose);
    if (drOv) drOv.addEventListener('click', drClose);
    var fileItems = [], parsed = [], seq = 0;
    function render(){
        var tb = prev.querySelector('tbody'), ok = 0, html = '';
        parsed.forEach(function(p){
            var st = !p.ok ? '<span class="bad">' + esc(p.w) + '</span>' : (p.w ? '<span class="wn">' + esc(p.w) + '</span>' : '<span style="color:var(--c-ok-fg)">ок</span>');
            if (p.ok) ok++;
            html += '<tr><td>' + esc(p.n) + '</td><td>' + (p.v ? '<span class="wgx-vt ' + esc(p.vc) + '">' + esc(p.v) + '</span>' : '') + '</td><td class="ep">' + esc(p.ep || '') + '</td><td>' + st + '</td></tr>';
        });
        tb.innerHTML = html; prev.hidden = !parsed.length;
        var dest = $('wgxDest').querySelectorAll('input:checked').length;
        upGo.disabled = !ok || !dest;
        upGo.textContent = ok ? 'Загрузить ' + ok : 'Загрузить';
        sum.textContent = !parsed.length ? 'Выберите файлы или вставьте текст' : (ok + ' из ' + parsed.length + ' подходят' + (parsed.length - ok ? ' · ' + (parsed.length - ok) + ' будет пропущено' : '') + (dest ? '' : ' · отметьте, куда загрузить'));
    }
    function refresh(){
        var my = ++seq;
        if (!fileItems.length && !raw.value.trim()) { parsed = []; render(); return; }
        upGo.disabled = true; sum.textContent = 'Проверяю…';
        var f = new FormData(); f.append('csrf', CSRF); f.append('files_json', JSON.stringify(fileItems)); f.append('raw_batch', raw.value); f.append('label_prefix', upF.elements.label_prefix.value);
        fetch('?ajax=parse_batch', {method: 'POST', body: f}).then(function(r){ return r.json(); }).then(function(d){
            if (my !== seq) return;
            parsed = (d && d.items) || []; render();
            if (d && d.cut) sum.textContent += ' · сверх лимита в 200 не загрузится: ' + d.cut;
        }).catch(function(){ if (my === seq) { parsed = []; render(); sum.textContent = 'Не удалось проверить — загрузка всё равно пропустит битые конфиги'; upGo.disabled = !$('wgxDest').querySelectorAll('input:checked').length; } });
    }
    function readFiles(list){
        var arr = Array.prototype.slice.call(list || []), left = arr.length; fileItems = []; ++seq; upGo.disabled = true; sum.textContent = 'Читаю файлы…';
        if (!left) { refresh(); return; }
        arr.forEach(function(f){ var fr = new FileReader(); fr.onload = function(){ fileItems.push({n: f.name, c: String(fr.result)}); if (--left === 0) refresh(); }; fr.onerror = function(){ if (--left === 0) refresh(); }; fr.readAsText(f); });
    }
    if (files) files.addEventListener('change', function(){ readFiles(files.files); });
    if (raw) raw.addEventListener('input', function(){ clearTimeout(raw._t); raw._t = setTimeout(refresh, 250); });
    if (upF) upF.elements.label_prefix.addEventListener('input', function(){ clearTimeout(raw._t); raw._t = setTimeout(refresh, 400); });
    if ($('wgxDest')) $('wgxDest').addEventListener('change', render);
    var drop = $('wgxDrop');
    if (drop) {
        ['dragenter', 'dragover'].forEach(function(ev){ drop.addEventListener(ev, function(e){ e.preventDefault(); drop.classList.add('on'); }); });
        ['dragleave', 'drop'].forEach(function(ev){ drop.addEventListener(ev, function(e){ e.preventDefault(); drop.classList.remove('on'); }); });
        drop.addEventListener('drop', function(e){ readFiles(e.dataTransfer.files); });
    }
    if (upF) upF.addEventListener('submit', function(){ $('wgFilesJson').value = JSON.stringify(fileItems); if (files) files.disabled = true; upGo.classList.add('busy'); });

    var man = $('wgxManModal');
    function manOpen(){ if (man) man.classList.add('open'); var qi = $('wgm_q'); if (qi) setTimeout(function(){ qi.focus(); }, 30); }
    function manClose(){ if (man) man.classList.remove('open'); }
    document.querySelectorAll('#wgxManOpen,[data-manopen]').forEach(function(b){ b.addEventListener('click', manOpen); });
    document.querySelectorAll('[data-manclose]').forEach(function(b){ b.addEventListener('click', manClose); });
    if (man) man.addEventListener('click', function(e){ if (e.target === man) manClose(); });
    if ($('wgm_q')) $('wgm_q').addEventListener('keydown', function(e){ if (e.key === 'Enter') { e.preventDefault(); $('wgm_find').click(); } });
    sqcfgInitManual(NAMES);
    apply();
})();
</script>
