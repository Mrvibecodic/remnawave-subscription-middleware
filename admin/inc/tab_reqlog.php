    <style>
    .rl-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:.7rem;margin:0 0 1.25rem}
    .rl-kpi{border:1px solid var(--line);background:var(--bg2);border-radius:12px;padding:.8rem .95rem;display:flex;flex-direction:column;gap:.15rem;min-height:96px}
    .rl-kpi .k{font-size:.72rem;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;line-height:1.3}
    .rl-kpi .v{font-size:1.55rem;font-weight:700;color:var(--text-strong);line-height:1.2;font-variant-numeric:tabular-nums}
    .rl-kpi .v small{font-size:.95rem;font-weight:500;color:var(--muted)}
    .rl-kpi .d{font-size:.76rem;color:var(--muted);margin-top:auto}
    .rl-kpi .d.alert{color:var(--c-warn-fg)}
    @media(max-width:1100px){.rl-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}}
    @media(max-width:520px){.rl-kpis{grid-template-columns:1fr}}
    .spark{display:flex;align-items:flex-end;gap:3px;height:56px}
    .spark i{flex:1;background:var(--accent-light);border-radius:3px 3px 0 0;min-height:3px}
    .spark i.hi{background:var(--accent)}
    .sparkx{display:flex;justify-content:space-between;font-size:.7rem;color:var(--muted);margin-top:.35rem;font-variant-numeric:tabular-nums}
    .rl-flt{display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;margin:.2rem 0 .9rem}
    .rl-flt select,.rl-flt input[type=text],.rl-flt .btn{height:38px;min-height:38px;box-sizing:border-box;padding-top:0;padding-bottom:0;font-size:.84rem;border-radius:var(--radius)}
    .rl-flt select{width:auto;max-width:220px;flex:0 1 auto;font-weight:500}
    .rl-flt select:hover{border-color:var(--accent)}
    .rl-flt .btn{display:inline-flex;align-items:center;gap:.45rem;white-space:nowrap;line-height:1;font-weight:600}
    .rl-flt .btn svg{flex:0 0 auto}
    .rl-search{position:relative;flex:1 1 250px;max-width:360px}
    .rl-search input{width:100%;padding-left:2.1rem}
    .rl-search svg{position:absolute;left:.65rem;top:50%;transform:translateY(-50%);color:var(--muted);pointer-events:none}
    .rl-right{margin-left:auto;display:flex;gap:.5rem}
    .rl-wrap{border:1px solid var(--line);border-radius:12px;overflow-x:auto;overflow-y:hidden}
    .rl-tbl{width:100%;min-width:65rem;border-collapse:separate;border-spacing:0;font-size:.88rem;table-layout:fixed}
    .rl-tbl th{background:var(--bg2);color:var(--muted);font-weight:600;font-size:.71rem;text-transform:uppercase;letter-spacing:.03em;text-align:left;padding:.6rem .75rem;box-shadow:inset 0 -1px 0 var(--line);white-space:nowrap}
    .rl-tbl td{padding:.5rem .75rem;box-shadow:inset 0 -1px 0 var(--line);vertical-align:middle}
    .rl-tbl tr.rowb td{overflow:hidden;text-overflow:ellipsis}
    .rl-tbl td:first-child{text-overflow:clip;padding-right:.3rem}
    .rl-tbl tr.rowb{cursor:pointer}
    .rl-tbl tr.rowb:hover td,.rl-tbl tr.rowb.open td{background:var(--hover2)}
    .c-tgl{width:4%}.c-time{width:9%}.c-dec{width:10%}.c-type{width:13%}.c-as{width:13%}.c-user{width:16%}.c-client{width:18%}.c-cnt{width:17%}
    .tgl{display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;border:1px solid var(--line);border-radius:6px;background:var(--bg2);color:var(--muted);font-size:.9rem;line-height:1;transition:transform .15s,border-color .15s}
    tr.open .tgl{border-color:var(--accent);color:var(--accent-text);transform:rotate(90deg)}
    .rl-tbl .mono{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-variant-numeric:tabular-nums}
    .rl-tbl .dim{color:var(--muted)}
    .rl-tbl .tag{white-space:nowrap}
    .u-cell{display:flex;flex-direction:column;line-height:1.3;min-width:0}
    .u-cell .nm{color:var(--text-strong);font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    .u-cell .su{font-family:ui-monospace,monospace;font-size:.75rem;color:var(--muted)}
    .cl{display:flex;flex-direction:column;line-height:1.3;min-width:0}
    .cl .c1{color:var(--text)}
    .cl .c2{color:var(--muted);font-size:.75rem}
    .rep{display:inline-block;min-width:20px;text-align:center;background:var(--bg2);border:1px solid var(--line);border-radius:20px;padding:0 .35rem;font-size:.72rem;color:var(--muted);margin-left:.35rem}
    .cnt{display:flex;align-items:center;gap:.55rem}
    .cnt b{color:var(--text-strong);font-variant-numeric:tabular-nums;min-width:2.2ch;text-align:right}
    .bar{flex:1 1 auto;height:5px;border-radius:3px;background:var(--bg2);overflow:hidden}
    .bar i{display:block;height:100%;background:var(--accent);border-radius:3px}
    .ft{display:inline-flex;align-items:center;gap:.35rem;padding:.16rem .5rem;border-radius:6px;font-size:.72rem;font-weight:600;white-space:nowrap;border:1px solid transparent}
    .ft .dt{width:6px;height:6px;border-radius:50%;background:currentColor;flex:0 0 auto}
    .ft.base64{background:var(--c-info-bg);color:var(--c-info-fg)}
    .ft.json{background:var(--c-violet-bg);color:var(--c-violet-fg)}
    .ft.clash{background:var(--c-ok-bg);color:var(--c-ok-fg)}
    .ft.singbox{background:var(--c-warn-bg);color:var(--c-yellow-fg)}
    .ft.wg{background:var(--accent-light);color:var(--accent-text)}
    .ft.page,.ft.other{background:transparent;color:var(--muted);border-color:var(--line)}
    .as{display:inline-flex;align-items:center;gap:.3rem;font-size:.75rem;font-weight:600;padding:.14rem .45rem;border-radius:6px;white-space:nowrap;border:1px solid transparent}
    .as.on{background:var(--accent-light);color:var(--accent-text)}
    .as.stub{background:var(--c-warn-bg);color:var(--c-warn-fg)}
    .as.err{background:var(--c-bad-bg);color:var(--c-bad-fg)}
    .as.no{color:var(--muted);opacity:.6}
    .as.off{color:var(--muted);border-color:var(--line)}
    .row-x{display:none}
    .row-x.show{display:table-row}
    .row-x>td{padding:0;background:var(--bg2);box-shadow:inset 0 -1px 0 var(--line);overflow:visible}
    .xin{padding:.9rem 1rem 1rem;display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:1.15rem 1.6rem;align-items:start}
    @media(max-width:1100px){.xin{grid-template-columns:repeat(2,minmax(0,1fr))}}
    @media(max-width:700px){.xin{grid-template-columns:1fr}}
    .xcol{min-width:0}
    .xh{font-size:.7rem;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);font-weight:700;padding:0 0 .45rem;border-bottom:1px solid var(--line);margin-bottom:.15rem}
    .xr{display:grid;grid-template-columns:104px minmax(0,1fr);gap:.75rem;align-items:baseline;padding:.38rem 0;border-bottom:1px solid var(--line);font-size:.82rem;line-height:1.45}
    .xcol .xr:last-child{border-bottom:0}
    .xr>.l{color:var(--muted);white-space:nowrap}
    .xr>.v{color:var(--text);overflow-wrap:anywhere;min-width:0}
    .xr>.v.mono{font-size:.78rem;font-family:ui-monospace,SFMono-Regular,Menlo,monospace}
    .xacts{grid-column:1/-1;display:flex;gap:.5rem;flex-wrap:wrap;padding-top:.9rem;margin-top:.5rem;border-top:1px solid var(--line)}
    .xacts .btn{height:32px;min-height:32px;padding:0 .75rem;font-size:.79rem;display:inline-flex;align-items:center;gap:.4rem;border-radius:var(--radius)}
    .hist{display:flex;gap:3px;align-items:center}
    .hist i{width:10px;height:18px;border-radius:3px;background:var(--c-ok-bg);border:1px solid var(--c-ok-fg);opacity:.65;flex:0 0 auto}
    .hist i.b{background:var(--c-bad-bg);border-color:var(--c-bad-fg);opacity:1}
    .hist i.g{background:var(--c-info-bg);border-color:var(--c-info-fg);opacity:1}
    .hist i.e{background:var(--c-warn-bg);border-color:var(--c-warn-fg);opacity:1}
    .exp-ok{color:var(--c-ok-fg)}
    .exp-soon{color:var(--c-warn-fg)}
    .exp-bad{color:var(--c-bad-fg)}
    @media(max-width:900px){
        .rl-wrap{border:0;overflow:visible;border-radius:0}
        .rl-tbl{min-width:0;display:block;font-size:.86rem;white-space:normal;overflow-x:visible}
        .rl-tbl colgroup,.rl-tbl thead{display:none}
        .rl-tbl tbody,.rl-tbl tr,.rl-tbl td{display:block;width:100%}
        .rl-tbl tr.rowb{position:relative;border:1px solid var(--line);border-radius:12px;background:var(--bg2);padding:.7rem 2.6rem .7rem .8rem;margin-bottom:.55rem}
        .rl-tbl tr.rowb.open{border-color:var(--accent);margin-bottom:0;border-radius:12px 12px 0 0}
        .rl-tbl tr.rowb td{box-shadow:none;padding:.2rem 0;display:flex;justify-content:space-between;align-items:center;gap:1rem;overflow:visible}
        .rl-tbl tr.rowb td::before{content:attr(data-label);color:var(--muted);font-size:.76rem;flex:0 1 auto;min-width:0}
        .rl-tbl tr.rowb td:first-child{position:absolute;right:.6rem;top:.6rem;width:auto;padding:0}
        .rl-tbl tr.rowb td:first-child::before{content:none}
        .rl-tbl tr.rowb td:nth-child(6){order:-1;padding-bottom:.4rem}
        .rl-tbl tr.rowb td:nth-child(6)::before{content:none}
        .ft{white-space:normal;text-align:right}
        .u-cell,.cl{align-items:flex-end;text-align:right;min-width:0}
        .u-cell .nm{white-space:normal;overflow-wrap:anywhere}
        .cl .c1,.cl .c2{overflow-wrap:anywhere}
        .rl-tbl tr.rowb td:nth-child(6) .u-cell{align-items:flex-start;text-align:left}
        .cnt{flex:1 1 auto;min-width:0;max-width:60%}
        .row-x.show{display:block}
        .row-x>td{border:1px solid var(--accent);border-top:0;border-radius:0 0 12px 12px;margin-bottom:.55rem}
        .row-x .xr>.v,.row-x .xr{white-space:normal}
        .xin{padding:.8rem .8rem .9rem}
        .xr{grid-template-columns:96px minmax(0,1fr);gap:.6rem}
        .xacts .btn{flex:1 1 auto;justify-content:center}
    }
    </style>
    <div class="rl-kpis">
        <div class="rl-kpi"><div class="k">Обновили подписку</div><div class="v" id="rlKpiUsers"><?= (int) $rl_today_users ?><?= $rl_total_users ? '<small> / ' . (int) $rl_total_users . '</small>' : '' ?></div><div class="d">сегодня, <?= h($rl_today_label) ?></div></div>
        <div class="rl-kpi"><div class="k">Устройств (HWID)</div><div class="v" id="rlKpiDev"><?= (int) $rl_today_devices ?></div><div class="d"><?= $rl_total_devices ? 'из ' . (int) $rl_total_devices . ' известных в логе' : 'за сегодня' ?></div></div>
        <div class="rl-kpi"><div class="k">Запросов за сутки</div><div class="v" id="rlKpiTotal"><?= (int) $rl_over['total'] ?></div><div class="d" id="rlKpiPeak"><?= $rl_over['peak'] ? 'пик в ' . h(date('H:i', (int) $rl_over['peak_h'])) . ' — ' . (int) $rl_over['peak'] . ' за час' : 'за последние 24 часа' ?></div></div>
        <div class="rl-kpi"><div class="k">Блокировок HWID</div><div class="v" id="rlKpiBlocked"><?= (int) $rl_over['blocked'] ?></div><div class="d<?= $rl_over['blocked_users'] ? ' alert' : '' ?>" id="rlKpiBlockedU"><?= $rl_over['blocked_users'] ? 'у ' . (int) $rl_over['blocked_users'] . ' пользователей' : 'за последние 24 часа' ?></div></div>
    </div>

    <div class="card">
        <div class="loghead">
            <h2>Активность по часам <span class="muted" style="font-weight:400;font-size:.78rem">последние 24 часа</span></h2>
            <div class="loghead-r"><button type="button" class="btn ghost" onclick="rlRefresh()">Обновить</button></div>
        </div>
        <div class="spark" id="rlSpark"><?php $rl_peak = max(1, (int) $rl_over['peak']); foreach ($rl_over['hourly'] as $rl_hi => $rl_hv): ?><i class="<?= $rl_hv >= $rl_peak * .75 ? 'hi' : '' ?>" style="height:<?= max(6, (int) round(pow($rl_hv / $rl_peak, .62) * 100)) ?>%" title="<?= h(date('H:i', (intdiv(time(), 3600) - 23 + $rl_hi) * 3600)) ?> — <?= (int) $rl_hv ?>"></i><?php endforeach; ?></div>
        <div class="sparkx"><span><?= h(date('H:i', (intdiv(time(), 3600) - 23) * 3600)) ?></span><span><?= h(date('H:i', (intdiv(time(), 3600) - 16) * 3600)) ?></span><span><?= h(date('H:i', (intdiv(time(), 3600) - 8) * 3600)) ?></span><span><?= h(date('H:i', intdiv(time(), 3600) * 3600)) ?></span></div>
    </div>

    <div class="card">
        <div class="loghead">
            <h2>Запросы <span class="muted" style="font-weight:400;font-size:.78rem">в выборке: <span id="rlCount"><?= count($reqlog) ?></span><?= count($reqlog) >= 300 ? ' (показаны последние 300)' : '' ?></span></h2>
            <div class="loghead-r">
                <div id="rl_pgrTop" class="pgr"></div>
                <span id="rlAuto" class="muted" style="font-size:.78rem"></span>
                <form method="post" onsubmit="return uiConfirmForm(this,'Очистить весь лог запросов?')" style="margin:0">
                    <input type="hidden" name="csrf" value="<?= h($token) ?>">
                    <input type="hidden" name="action" value="clear_reqlog">
                    <button class="danger" type="submit">Очистить</button>
                </form>
            </div>
        </div>
        <form method="get" class="rl-flt">
            <input type="hidden" name="tab" value="reqlog">
            <span class="rl-search">
                <svg width="15" height="15" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input type="text" name="rl_q" value="<?= h($rl_f['q']) ?>" placeholder="имя, shortUuid, IP или HWID">
            </span>
            <select name="rl_dec" onchange="this.form.submit()">
                <option value="">Решение: все</option>
                <?php foreach (['normal' => 'normal', 'blocked' => 'blocked', 'grace' => 'грейс', 'expired' => 'expired', 'error' => 'error'] as $rl_k => $rl_v): ?>
                <option value="<?= h($rl_k) ?>" <?= $rl_f['dec'] === $rl_k ? 'selected' : '' ?>><?= h($rl_v) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="rl_fmt" onchange="this.form.submit()">
                <option value="">Тип: любой</option>
                <?php foreach (['base64', 'json', 'clash', 'singbox', 'wg', 'page', 'other'] as $rl_k): ?>
                <option value="<?= h($rl_k) ?>" <?= $rl_f['fmt'] === $rl_k ? 'selected' : '' ?>><?= h(reqlog_fmt_label($rl_k)) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="rl_hours" onchange="this.form.submit()">
                <option value="1" <?= $rl_f['hours'] === 1 ? 'selected' : '' ?>>Последний час</option>
                <option value="24" <?= $rl_f['hours'] === 24 ? 'selected' : '' ?>>За сутки</option>
                <option value="168" <?= $rl_f['hours'] === 168 ? 'selected' : '' ?>>Неделя</option>
                <option value="0" <?= $rl_f['hours'] === 0 ? 'selected' : '' ?>>За всё время</option>
            </select>
            <button class="btn ghost" type="submit">Найти</button>
            <?php if ($rl_f['q'] !== '' || $rl_f['dec'] !== '' || $rl_f['fmt'] !== '' || $rl_f['hours'] !== 24): ?>
            <a class="btn ghost" href="?tab=reqlog" title="Сбросить фильтры">Сброс</a>
            <?php endif; ?>
        </form>
        <div class="rl-wrap">
            <table class="rl-tbl">
                <colgroup><col class="c-tgl"><col class="c-time"><col class="c-dec"><col class="c-type"><col class="c-as"><col class="c-user"><col class="c-client"><col class="c-cnt"></colgroup>
                <thead><tr><th></th><th>Время</th><th>Решение</th><th>Тип ответа</th><th>Доп. подписка</th><th>Пользователь</th><th>Клиент</th><th>Запросов / сутки</th></tr></thead>
                <tbody id="rlBody" class="lp-cap"><?= reqlog_render_rows($reqlog, $rl_ctx) ?></tbody>
            </table>
        </div>
        <div id="rl_pgrBot" class="pgr-bot"></div>
        <p class="muted" style="margin-bottom:0">Строка раскрывается по клику. «Тип ответа» — что реально отдала прослойка: определяется по <code>Content-Type</code> ответа и суффиксу пути, для браузера это страница подписки. «Устройство» берётся из того, что клиент прислал в User-Agent. Имя пользователя подтягивается из панели по shortUuid.</p>
    </div>

    <div class="card">
        <h2>Мусорные запросы <span class="muted" style="font-weight:400;font-size:.76rem">(сканеры/боты — панель не опрашивается)</span></h2>
        <p class="muted">Запросы на несуществующие адреса (файлы, <code>/wp-login.php</code>, <code>/.env</code> и т.п.). Для них прослойка не обращается к API панели, чтобы не нагружать её. Обычная подписка (shortUuid) сюда не попадает. Если в списке оказался нужный адрес — нажмите «Исключить», и он снова будет обрабатываться как подписка.</p>
        <?php $jl_len = panel_short_uuid_len(); ?>
        <form method="post" data-autosave style="margin:.9rem 0 0">
            <input type="hidden" name="csrf" value="<?= h($token) ?>">
            <input type="hidden" name="action" value="save_junk_cfg">
            <div class="set-row">
                <div class="set-info">
                    <div class="set-t">Считать мусором пути не той длины</div>
                    <div class="set-d">
                        Длина shortUuid берётся из панели (<code>GET /api/system/configuration</code>, панель 3.2.0 и выше).
                        <?php if ($jl_len > 0): ?>Сейчас в панели — <b><?= (int) $jl_len ?></b>.<?php else: ?>Сейчас длина неизвестна — правило не действует, даже если включено.<?php endif; ?>
                        Первый сегмент пути другой длины считается сканером, и панель по нему не опрашивается.
                        <b>Включайте, только если все выданные ссылки одной длины:</b> смена длины в панели не перевыпускает уже созданные shortUuid, и по старым ссылкам перестанут подмешиваться доп. конфиги и вторая подписка, а сами запросы пропадут из лога. Блокировки, грейс и оверрайды продолжат работать в любом случае.
                    </div>
                </div>
                <label class="switch"><input type="checkbox" name="junk_short_len" <?= junk_short_len_enabled() ? 'checked' : '' ?>><span class="sl"></span></label>
            </div>
            <div class="set-row">
                <div class="set-info">
                    <div class="set-t">Писать в лог открытия страницы подписки</div>
                    <div class="set-d">Когда пользователь открывает ссылку в браузере, панель отдаёт HTML-страницу. По умолчанию такие запросы в лог не пишутся, чтобы он не забивался. Включите, если хотите видеть их с типом «страница подписки».</div>
                </div>
                <label class="switch"><input type="checkbox" name="reqlog_log_pages" <?= reqlog_pages_enabled() ? 'checked' : '' ?>><span class="sl"></span></label>
            </div>
        </form>
        <?php if (!empty($junk_wl)): ?>
        <p class="muted" style="margin-top:.7rem;margin-bottom:.3rem">Исключены из мусорных:</p>
        <div style="display:flex;flex-wrap:wrap;gap:.4rem;margin-bottom:.6rem">
            <?php foreach ($junk_wl as $w): ?>
            <form method="post" style="margin:0">
                <input type="hidden" name="csrf" value="<?= h($token) ?>">
                <input type="hidden" name="action" value="junk_include">
                <input type="hidden" name="path" value="<?= h($w) ?>">
                <button class="btn ghost" type="submit" title="Вернуть в мусорные">✓ <?= h($w) ?> &nbsp;✕</button>
            </form>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php if (!empty($junk_top)): ?>
        <table class="logtbl">
            <thead><tr><th>Путь</th><th>Запросов</th><th>Последний</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($junk_top as $j): ?>
            <tr>
                <td><code><?= h(mb_substr((string) $j['path'], 0, 120)) ?></code></td>
                <td><?= (int) $j['hits'] ?></td>
                <td class="muted"><?= (int) $j['last_ts'] ? h(date('Y-m-d H:i', (int) $j['last_ts'])) : '—' ?></td>
                <td>
                    <form method="post" style="margin:0">
                        <input type="hidden" name="csrf" value="<?= h($token) ?>">
                        <input type="hidden" name="action" value="junk_exclude">
                        <input type="hidden" name="path" value="<?= h($j['path']) ?>">
                        <button class="btn ghost" type="submit">Исключить</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <p class="muted">Пока пусто — мусорных запросов не зафиксировано.</p>
        <?php endif; ?>
    </div>
    <script>
    (function(){
        var body = document.getElementById('rlBody');
        var rlPager = window.LogPager ? LogPager({bodyId:'rlBody', topId:'rl_pgrTop', botId:'rl_pgrBot', colspan:8, storeKey:'pg_reqlog'}) : null;
        function p2(n){return (n<10?'0':'')+n;}
        function loc(ep, withDate){
            ep = parseInt(ep,10); if(!ep) return '';
            var d = new Date(ep*1000); if(isNaN(d.getTime())) return '';
            var t = p2(d.getHours())+':'+p2(d.getMinutes())+':'+p2(d.getSeconds());
            return withDate ? (d.getFullYear()+'-'+p2(d.getMonth()+1)+'-'+p2(d.getDate())+' '+t) : t;
        }
        function localize(root){
            (root||document).querySelectorAll('.rl-time[data-ts]').forEach(function(td){
                var rep = td.querySelector('.rep'), v = loc(td.getAttribute('data-ts'), false);
                if(!v) return;
                td.textContent = v;
                if(rep) td.appendChild(rep);
            });
            (root||document).querySelectorAll('.rl-full[data-ts]').forEach(function(el){
                var v = loc(el.getAttribute('data-ts'), true); if(v) el.textContent = v;
            });
        }
        function anyOpen(){ return !!document.querySelector('.row-x.show'); }
        body.addEventListener('click', function(e){
            if(e.target.closest('a, button, .xacts')) return;
            var tr = e.target.closest('.rowb'); if(!tr) return;
            var x = body.querySelector('.row-x[data-x="'+tr.getAttribute('data-i')+'"]'); if(!x) return;
            var open = x.classList.contains('show');
            x.classList.toggle('show', !open);
            tr.classList.toggle('open', !open);
        });
        body.addEventListener('click', function(e){
            var b = e.target.closest('.rl-copy'); if(!b) return;
            e.preventDefault();
            var v = b.getAttribute('data-copy') || '';
            if(navigator.clipboard) navigator.clipboard.writeText(v).then(function(){ if(window.toast) toast('HWID скопирован'); });
        });
        function kpi(id, val){ var el = document.getElementById(id); if(el && val !== undefined && val !== null) el.textContent = val; }
        window.rlRefresh = function(){
            var a = document.getElementById('rlAuto'); if(a) a.textContent = '· обновление…';
            var q = new URLSearchParams(window.location.search); q.set('ajax','reqlog');
            fetch('?'+q.toString()).then(function(r){ return r.json(); }).then(function(d){
                if(d.ok){
                    body.innerHTML = d.html;
                    localize(body);
                    var c = document.getElementById('rlCount'); if(c) c.textContent = d.count;
                    kpi('rlKpiUsers', d.kpi.users); kpi('rlKpiDev', d.kpi.devices);
                    kpi('rlKpiTotal', d.kpi.total); kpi('rlKpiBlocked', d.kpi.blocked);
                    var sp = document.getElementById('rlSpark'); if(sp && d.spark) sp.innerHTML = d.spark;
                    if(rlPager) rlPager.refresh();
                }
                if(a) a.textContent = '· обновлено в ' + new Date().toLocaleTimeString();
            }).catch(function(){ if(a) a.textContent = '· ошибка обновления'; });
        };
        localize(document);
        setInterval(function(){ if(!document.hidden && !anyOpen()) rlRefresh(); }, 10000);
        document.addEventListener('visibilitychange', function(){ if(!document.hidden && !anyOpen()) rlRefresh(); });
    })();
    </script>
