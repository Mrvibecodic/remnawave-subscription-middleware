<?php
$si_max = 1;
foreach ($sys_series as $pt) if ((int) $pt['hits'] > $si_max) $si_max = (int) $pt['hits'];
$si_factor = metrics_peak_factor();
$si_floor  = metrics_peak_floor();
?>
    <style>
        .si-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:1rem;margin-bottom:1rem}
        .si-kv{display:flex;justify-content:space-between;gap:1rem;padding:.42rem 0;border-bottom:1px dashed var(--line);font-size:.9rem}
        .si-kv:last-child{border-bottom:0}
        .si-kv .k{color:var(--muted)}
        .si-kv .v{font-weight:600;color:var(--text-strong);text-align:right;word-break:break-word}
        .si-stat{display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:.7rem;margin:.2rem 0 1rem}
        .si-card{background:var(--bg2);border:1px solid var(--line);border-radius:10px;padding:.7rem .85rem}
        .si-card .n{font-size:1.5rem;font-weight:700;color:var(--text-strong);line-height:1.1}
        .si-card .l{font-size:.76rem;color:var(--muted);margin-top:.2rem}
        .si-chart{display:flex;align-items:flex-end;gap:2px;height:120px;padding:.6rem;background:var(--bg2);border:1px solid var(--line);border-radius:10px;overflow:hidden}
        .si-bar{flex:1 1 0;min-width:2px;background:var(--accent);border-radius:2px 2px 0 0;opacity:.85;transition:height .2s}
        .si-bar.zero{background:var(--line);opacity:.5}
        .si-bar.peak{background:var(--err)}
        .si-chart-x{display:flex;justify-content:space-between;font-size:.72rem;color:var(--muted);margin-top:.3rem}
        .si-leg{display:flex;flex-wrap:wrap;gap:1.1rem;align-items:center;font-size:.8rem;color:var(--muted);margin:.1rem 0 .9rem}
        .si-dot{display:inline-block;width:10px;height:10px;border-radius:50%;margin-right:.35rem;vertical-align:middle}
        .si-dot.sub{background:var(--accent)}
        .si-dot.bot{background:var(--line);border:1px solid var(--muted)}
        .si-card .sb{font-size:.72rem;margin-top:.3rem;color:var(--muted)}
        .si-card .sb .vsub{color:var(--accent-text);font-weight:600}
        .si-bar2{flex:1 1 0;min-width:2px;display:flex;flex-direction:column;justify-content:flex-end;border-radius:2px 2px 0 0;overflow:hidden}
        .si-bar2.zero{background:var(--line);opacity:.5}
        .si-bar2 .seg-bot{background:var(--line);opacity:.7}
        .si-bar2 .seg-sub{background:var(--accent);opacity:.9}
    </style>
    <div class="si-grid">
        <div class="card">
            <h2 style="margin-top:0">Система</h2>
            <div class="si-kv"><span class="k">PHP</span><span class="v"><?= h($sys_info['php_version']) ?> · <?= h($sys_info['sapi']) ?></span></div>
            <div class="si-kv"><span class="k">ОС</span><span class="v"><?= h($sys_info['os']) ?></span></div>
            <?php if ($sys_info['server'] !== ''): ?><div class="si-kv"><span class="k">Веб-сервер</span><span class="v"><?= h($sys_info['server']) ?></span></div><?php endif; ?>
            <div class="si-kv"><span class="k">Load average</span><span class="v" id="si_load"><?php if (is_array($sys_info['load'])): echo h(implode(' · ', array_map(fn($x) => number_format((float) $x, 2), $sys_info['load']))); echo $sys_info['cores'] ? (' (ядер: ' . (int) $sys_info['cores'] . ')') : ''; else: echo '—'; endif; ?></span></div>
            <div class="si-kv"><span class="k">Память PHP</span><span class="v"><span id="si_mem"><?= h(metrics_fmt_bytes($sys_info['mem_peak'])) ?></span> / <?= h($sys_info['mem_limit']) ?></span></div>
            <div class="si-kv"><span class="k">OPcache · cURL</span><span class="v"><?= $sys_info['opcache'] ? 'вкл' : 'выкл' ?> · <?= $sys_info['curl'] ? 'есть' : 'нет' ?></span></div>
        </div>
        <div class="card">
            <h2 style="margin-top:0">База данных</h2>
            <div class="si-kv"><span class="k">Движок</span><span class="v"><?= $sys_db['driver'] === 'mysql' ? 'MySQL' : 'SQLite' ?></span></div>
            <div class="si-kv"><span class="k">Размер</span><span class="v" id="si_dbsize"><?= h(metrics_fmt_bytes($sys_db['size'])) ?></span></div>
            <div class="si-kv"><span class="k">Расположение</span><span class="v" style="font-size:.78rem"><?= h($sys_db['location']) ?></span></div>
            <?php foreach (($sys_db['tables'] ?? []) as $tname => $cnt): ?>
            <div class="si-kv"><span class="k"><?= h($tname) ?></span><span class="v"><?= $cnt === null ? '—' : (int) $cnt ?></span></div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="card">
        <div class="loghead">
            <h2>Нагрузка <span id="siAuto" class="muted" style="font-weight:400;font-size:.76rem"></span></h2>
            <div class="loghead-r">
                <button type="button" class="btn ghost" onclick="siRefresh()">🔄 Обновить</button>
            </div>
        </div>
        <p class="muted">Считается каждый запрос к прослойке. <span style="color:var(--accent-text)">Обновления подписки</span> (реальные клиенты с заголовком подписки) показаны отдельно от <b>краулеров и сканеров</b> — прочего фонового трафика. Время ответа учитывает поход к панели.</p>
        <div class="si-leg">
            <span><span class="si-dot sub"></span>Обновления подписки</span>
            <span><span class="si-dot bot"></span>Краулеры / сканеры</span>
        </div>
        <div class="si-stat">
            <div class="si-card"><div class="n" id="si_m1"><?= (int) $sys_load['m1'] ?></div><div class="l">за 1 мин</div><div class="sb" id="si_m1b"><span class="si-dot sub"></span><span class="vsub"><?= (int) $sys_load['m1_sub'] ?></span> <span class="si-dot bot"></span><?= (int) $sys_load['m1'] - (int) $sys_load['m1_sub'] ?></div></div>
            <div class="si-card"><div class="n" id="si_m5"><?= (int) $sys_load['m5'] ?></div><div class="l">за 5 мин</div><div class="sb" id="si_m5b"><span class="si-dot sub"></span><span class="vsub"><?= (int) $sys_load['m5_sub'] ?></span> <span class="si-dot bot"></span><?= (int) $sys_load['m5'] - (int) $sys_load['m5_sub'] ?></div></div>
            <div class="si-card"><div class="n" id="si_m60"><?= (int) $sys_load['m60'] ?></div><div class="l">за час</div><div class="sb" id="si_m60b"><span class="si-dot sub"></span><span class="vsub"><?= (int) $sys_load['m60_sub'] ?></span> <span class="si-dot bot"></span><?= (int) $sys_load['m60'] - (int) $sys_load['m60_sub'] ?></div></div>
            <div class="si-card"><div class="n" id="si_today"><?= (int) $sys_load['today'] ?></div><div class="l">за сутки</div><div class="sb" id="si_todayb"><span class="si-dot sub"></span><span class="vsub"><?= (int) $sys_load['today_sub'] ?></span> <span class="si-dot bot"></span><?= (int) $sys_load['today'] - (int) $sys_load['today_sub'] ?></div></div>
            <div class="si-card"><div class="n" id="si_rpm"><?= h(number_format((float) $sys_load['rpm_60'], 1)) ?></div><div class="l">запр/мин (час)</div></div>
            <div class="si-card"><div class="n" id="si_avg"><?= (int) $sys_load['avg_ms'] ?><span style="font-size:.8rem"> мс</span></div><div class="l">средн. ответ (час)</div></div>
            <div class="si-card"><div class="n" id="si_max"><?= (int) $sys_load['max_ms_60'] ?><span style="font-size:.8rem"> мс</span></div><div class="l">макс. ответ (час)</div></div>
        </div>
        <div class="si-chart" id="si_chart">
            <?php foreach ($sys_series as $pt): $hh = (int) $pt['hits']; $hs = min($hh, (int) $pt['sub']); $hpx = $hh > 0 ? max(3, (int) round($hh / $si_max * 108)) : 2; $spx = $hh > 0 ? (int) round($hpx * $hs / $hh) : 0; $bpx = $hpx - $spx; ?>
            <div class="si-bar2<?= $hh === 0 ? ' zero' : '' ?>" style="height:<?= $hpx ?>px" title="<?= h(date('H:i', (int) $pt['ts'])) ?> · подписка <?= $hs ?> · краулеры <?= $hh - $hs ?> · всего <?= $hh ?> · <?= (int) $pt['ms'] ?> мс"><div class="seg-bot" style="height:<?= $bpx ?>px"></div><div class="seg-sub" style="height:<?= $spx ?>px"></div></div>
            <?php endforeach; ?>
        </div>
        <div class="si-chart-x"><span>−60 мин</span><span>сейчас</span></div>
    </div>

    <div class="card">
        <div class="loghead">
            <h2>Аномальные пики нагрузки</h2>
            <div class="loghead-r">
                <form method="post" onsubmit="return uiConfirmForm(this,'Очистить лог пиков нагрузки?')" style="margin:0">
                    <input type="hidden" name="csrf" value="<?= h($token) ?>">
                    <input type="hidden" name="action" value="clear_peaks">
                    <button class="danger" type="submit">🧹 Очистить</button>
                </form>
            </div>
        </div>
        <p class="muted">Минута помечается пиком, если запросов в неё ≥ <b><?= h(number_format($si_factor, 1)) ?>×</b> от средней за предыдущий час И не меньше порога <b><?= (int) $si_floor ?></b> запр/мин.</p>
        <form method="post" style="display:flex;gap:1rem;flex-wrap:wrap;align-items:flex-end;margin-bottom:1rem">
            <input type="hidden" name="csrf" value="<?= h($token) ?>">
            <input type="hidden" name="action" value="save_metrics_cfg">
            <div><label style="display:block;font-size:.8rem;font-weight:600;margin-bottom:.3rem">Множитель (×средней)</label><input type="number" step="0.1" min="1.5" name="metrics_peak_factor" value="<?= h(number_format($si_factor, 1)) ?>" style="width:120px;padding:.5rem;border:1px solid var(--line);border-radius:8px;background:var(--bg2);color:var(--text)"></div>
            <div><label style="display:block;font-size:.8rem;font-weight:600;margin-bottom:.3rem">Мин. порог, запр/мин</label><input type="number" min="5" name="metrics_peak_floor" value="<?= (int) $si_floor ?>" style="width:120px;padding:.5rem;border:1px solid var(--line);border-radius:8px;background:var(--bg2);color:var(--text)"></div>
            <button class="btn" type="submit" style="width:auto;padding:.55rem 1.1rem">Сохранить</button>
        </form>
        <table class="logtbl">
            <thead><tr><th>Время</th><th>Запросов/мин</th><th>Базовая линия</th><th>Макс. ответ</th><th>Пик памяти</th></tr></thead>
            <tbody id="si_peaks">
            <?php foreach ($sys_peaks as $pk): ?>
            <tr>
                <td class="muted si-pk-ts" data-ts="<?= (int) $pk['minute_ts'] ?>"><?= h(date('Y-m-d H:i', (int) $pk['minute_ts'])) ?></td>
                <td><span class="tag error"><?= (int) $pk['hits'] ?></span></td>
                <td class="muted"><?= (int) $pk['baseline'] ?></td>
                <td class="muted"><?= (int) $pk['dur_ms_max'] ?> мс</td>
                <td class="muted"><?= h(metrics_fmt_bytes((int) $pk['mem_max'])) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$sys_peaks): ?><tr><td colspan="5" class="muted">Пиков не зафиксировано</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>

    <script>
    var SI_MAXBASE = <?= (int) $si_max ?>;
    function siFmtBytes(n){n=Number(n)||0;var u=['B','KB','MB','GB','TB'],i=0;while(n>=1024&&i<u.length-1){n/=1024;i++;}return (i===0?Math.round(n):(n>=100?Math.round(n):Math.round(n*10)/10))+' '+u[i];}
    function siLocal(ep,withDate){ep=parseInt(ep,10);if(!ep)return '';var d=new Date(ep*1000);function p(n){return(n<10?'0':'')+n;}var t=p(d.getHours())+':'+p(d.getMinutes());return withDate?(d.getFullYear()+'-'+p(d.getMonth()+1)+'-'+p(d.getDate())+' '+t):t;}
    function siEsc(s){var d=document.createElement('div');d.textContent=(s==null?'':s);return d.innerHTML;}
    function siChart(series){
        var box=document.getElementById('si_chart'); if(!box||!series) return;
        var mx=1; series.forEach(function(p){ if(p.hits>mx) mx=p.hits; });
        box.innerHTML=series.map(function(p){
            var sub=Math.min(p.hits,p.sub||0);
            var hpx=p.hits>0?Math.max(3,Math.round(p.hits/mx*108)):2;
            var spx=p.hits>0?Math.round(hpx*sub/p.hits):0;
            var bpx=hpx-spx;
            return '<div