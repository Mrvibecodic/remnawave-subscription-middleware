<?php
$si_max = 1;
foreach ($sys_series as $pt) if ((int) $pt['hits'] > $si_max) $si_max = (int) $pt['hits'];
$si_factor = metrics_peak_factor();
$si_floor  = metrics_peak_floor();
$si_segs = ['mw', 'panel', 'env', 'custom'];
$si_seg = in_array(($_GET['seg'] ?? ''), $si_segs, true) ? $_GET['seg'] : (in_array(($_COOKIE['si_seg'] ?? ''), $si_segs, true) ? $_COOKIE['si_seg'] : 'mw');
$si_pc = json_decode((string) setting('panelstats_json', ''), true);
if (!is_array($si_pc)) $si_pc = null;
$si_pu = is_array($si_pc['users'] ?? null) ? $si_pc['users'] : [];
$si_po = is_array($si_pc['online'] ?? null) ? $si_pc['online'] : [];
$si_pn = is_array($si_pc['nodes'] ?? null) ? $si_pc['nodes'] : [];
$si_pf = function ($v) { return ($v === null || $v === '') ? '—' : number_format((int) $v, 0, '.', ' '); };
$si_pm  = panel_meta_cached();
$si_pmv = trim((string) ($si_pm['version'] ?? ''));
$si_non = (isset($si_pn['online']) && $si_pn['online'] !== null) ? (int) $si_pn['online'] : null;
$si_ntot = (isset($si_pn['total']) && $si_pn['total'] !== null) ? (int) $si_pn['total'] : null;
$si_cf = panel_config_cached();
$si_cf_on = panel_supports_config();
$si_cf_has = $si_cf_on && !empty($si_cf['ts']);
$si_cb = function ($v) { return $v === null ? '—' : ($v ? 'да' : 'нет'); };
$si_cl = function ($v) { return (is_array($v) && $v) ? implode(', ', $v) : ($v === null ? '—' : 'выкл'); };
$cr_on = function_exists('cron_view');
if ($cr_on) {
    cron_note_web_user();
    $cr_v     = cron_view();
    $cr_alive = $cr_v['status'] === 'alive';
    $cr_lines = [];
    if (!submw_in_docker()) {
        $cr_user  = cron_user_name(cron_site_uid());
        $cr_lines = [
            ['Из консоли (надёжнее) — PHP ' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . ' с теми же расширениями, что у сайта. Шаг 1: открыть crontab пользователя сайта', 'crontab -u ' . $cr_user . ' -e'],
            ['Шаг 2: вставить строку целиком и сохранить', '* * * * * ' . cron_cron_arg(cron_php_bin()) . ' ' . cron_cron_arg(dirname(__DIR__, 2) . '/tools/cron.php') . ' >/dev/null 2>&1'],
            ['Либо по адресу — тем же PHP, что отвечает сайту; без php-fpm ответ ждёт окончания задач, тяжёлые пропускаются', '* * * * * curl -fsSL --max-time 120 -o /dev/null ' . cron_cron_arg('https://' . mirror_domain() . '/' . cron_secret_ensure()) . ' >/dev/null 2>&1'],
        ];
    }
}
?>
    <style>
        .si-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:1rem;margin-bottom:1rem}
        .si-kv{display:flex;justify-content:space-between;gap:1rem;padding:.42rem 0;border-bottom:1px dashed var(--line);font-size:.9rem}
        .si-kv:last-child{border-bottom:0}
        .si-kv .k{color:var(--muted)}
        .cr-line{display:flex;gap:.6rem;align-items:center;flex-wrap:wrap;margin:.25rem 0 .9rem}
        .cr-line code{flex:1 1 320px;min-width:0;white-space:normal;word-break:break-all;padding:.45rem .6rem}
        .si-kv .v{font-weight:600;color:var(--text-strong);text-align:right;word-break:break-word}
        .si-stat{display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:.7rem;margin:.2rem 0 1rem}
        .si-card{background:var(--bg2);border:1px solid var(--line);border-radius:10px;padding:.7rem .85rem}
        .si-card .n{font-size:1.5rem;font-weight:700;color:var(--text-strong);line-height:1.1}
        .si-card .l{font-size:.76rem;color:var(--muted);margin-top:.2rem}
        .si-chart{display:flex;align-items:flex-end;gap:2px;height:120px;padding:.6rem;background:var(--bg2);border:1px solid var(--line);border-radius:10px;overflow:hidden}
        .si-chart-x{display:flex;justify-content:space-between;font-size:.72rem;color:var(--muted);margin-top:.3rem}
        .si-leg{display:flex;flex-wrap:wrap;gap:1.1rem;align-items:center;font-size:.8rem;color:var(--muted);margin:.1rem 0 .9rem}
        .si-dot{display:inline-block;width:10px;height:10px;border-radius:50%;margin-right:.35rem;vertical-align:middle}
        .si-dot.sub{background:var(--accent)}
        .si-dot.bot{background:var(--line);border:1px solid var(--muted)}
        .si-card .sb{font-size:.72rem;margin-top:.3rem;color:var(--muted)}
        .si-card .sb .vsub{color:var(--accent-text);font-weight:600}
        .si-bar2{flex:1 1 0;min-width:2px;display:flex;flex-direction:column;justify-content:flex-end;border-radius:2px 2px 0 0;overflow:hidden}
        .si-bar2.zero{background:var(--line);opacity:.5}
        .api-sw{display:inline-flex;border:1px solid var(--line);border-radius:10px;overflow:hidden;background:var(--bg2)}
        .api-sw a{padding:.35rem .7rem;font-size:.8rem;color:var(--muted);text-decoration:none;border-left:1px solid var(--line)}
        .api-sw a:first-child{border-left:0}
        .api-sw a.on{background:var(--accent-light);color:var(--accent-text);font-weight:600}
        .api-sp{display:flex;align-items:flex-end;gap:1px;height:56px;padding:.4rem .5rem;background:var(--bg2);border:1px solid var(--line);border-radius:10px;margin:.2rem 0 .9rem}
        .api-sp i{flex:1 1 0;min-width:1px;background:var(--accent);border-radius:1px 1px 0 0;opacity:.85}
        .api-sp i.z{background:var(--line);height:2px!important;opacity:.6}
        .api-g{border:1px solid var(--line);border-radius:10px;background:var(--bg2);margin-bottom:.45rem}
        .api-g>summary{list-style:none;cursor:pointer;display:grid;grid-template-columns:minmax(0,1fr) 5.5rem 4.5rem 4.5rem 4.5rem;gap:.6rem;align-items:center;padding:.55rem .8rem;font-size:.86rem}
        .api-g>summary::-webkit-details-marker{display:none}
        .api-g .nm{font-weight:600;color:var(--text-strong);display:flex;align-items:center;gap:.5rem;min-width:0}
        .api-g .nm span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        .api-g .nm svg{flex:none;color:var(--muted);transition:transform .15s}
        .api-g[open] .nm svg{transform:rotate(90deg)}
        .api-g .num{text-align:right;font-variant-numeric:tabular-nums;color:var(--text)}
        .api-g .num small{color:var(--muted);font-size:.74rem}
        .api-g .num.bad{color:var(--c-bad-fg)}
        .api-g .bar{grid-column:1/-1;height:3px;border-radius:999px;background:var(--line);overflow:hidden;margin-top:-.1rem}
        .api-g .bar i{display:block;height:100%;background:var(--accent)}
        .api-e{display:grid;grid-template-columns:minmax(0,1fr) 5.5rem 4.5rem 4.5rem 4.5rem;gap:.6rem;padding:.35rem .8rem .35rem 2.1rem;font-size:.8rem;border-top:1px dashed var(--line);align-items:center}
        .api-e code{font-size:.76rem;overflow-wrap:anywhere;padding:.05rem .35rem}
        .api-hd{display:grid;grid-template-columns:minmax(0,1fr) 5.5rem 4.5rem 4.5rem 4.5rem;gap:.6rem;padding:0 .8rem .3rem;font-size:.7rem;text-transform:uppercase;letter-spacing:.04em;color:var(--muted);text-align:right}
        .api-hd span:first-child{text-align:left}
        @media(max-width:640px){.api-g>summary,.api-e,.api-hd{grid-template-columns:minmax(0,1fr) 4.5rem 3.6rem}.api-g .c-err,.api-g .c-ms,.api-hd .c-err,.api-hd .c-ms,.api-e .c-err,.api-e .c-ms{display:none}}
        .si-bar2 .seg-bot{background:var(--line);opacity:.7}
        .si-bar2 .seg-sub{background:var(--accent);opacity:.9}
        .si-kpi{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:.7rem;margin-bottom:1.1rem}
        .si-kpi .si-card .n{font-size:1.75rem}
        .si-panels{min-height:440px}
        .si-sub{font-weight:700;font-size:.95rem;margin:1.1rem 0 .5rem;color:var(--text-strong)}
        .si-sub:first-child{margin-top:.2rem}
        .si-pnote{display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;font-size:.8rem;color:var(--muted);margin:.1rem 0 .9rem}
        .nh-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:.8rem;align-items:start}
        .nh-grp{border:1px solid var(--line);border-radius:var(--radius);background:var(--bg2);overflow:hidden}
        .nh-gh{display:flex;align-items:center;justify-content:space-between;gap:.8rem;padding:.65rem .8rem;border-bottom:1px solid var(--line);cursor:pointer;margin:0;color:var(--text)}
        .nh-gh .set-t,.nh-gh .set-d{display:block}
        .nh-gh .set-t{font-size:.86rem}
        .nh-gh .set-d{margin-top:.05rem}
        .nh-items{display:flex;flex-direction:column;padding:.3rem;transition:opacity .15s}
        .nh-it{display:flex;align-items:center;gap:.6rem;padding:.4rem .5rem;border-radius:6px;cursor:pointer;margin:0;font-size:.86rem;color:var(--text)}
        .nh-it:hover{background:var(--hover)}
        .nh-it>svg{width:16px;height:16px;flex:0 0 auto;color:var(--muted)}
        .nh-it>span:first-of-type{flex:1;min-width:0}
        .nh-it .nh-cur{font-size:.7rem;color:var(--muted);white-space:nowrap}
        .nh-it.off>svg,.nh-it.off>span:first-of-type{opacity:.55}
        .nh-it .switch{width:38px;height:22px}
        .nh-it .switch .sl::before{width:16px;height:16px}
        .nh-it .switch input:checked+.sl::before{transform:translateX(16px)}
        .nh-grp.off .nh-items{opacity:.45;pointer-events:none}
        .switch input:focus-visible+.sl{box-shadow:var(--focus)}
        @media(max-width:460px){#siSeg{display:grid;grid-template-columns:1fr 1fr;width:100%}#siSeg button{text-align:center}}
    </style>

    <?php if (!$sys_info['opcache']): ?>
    <div class="warn"><b>OPcache выключен.</b> Подписка отдаётся примерно в 8 раз медленнее. Включите OPcache (<code>zend_extension=opcache</code>, <code>opcache.enable=1</code>) в настройках PHP <?= h(PHP_VERSION) ?> · <?= h(PHP_SAPI) ?> — именно той сборки, что отвечает сайту.</div>
    <?php endif; ?>

    <div class="si-kpi">
        <div class="si-card"><div class="n" id="k_rpm"><?= h(number_format((float) ($sys_load['rpm_60'] ?? 0), 1)) ?></div><div class="l">запр/мин (час)</div></div>
        <div class="si-card"><div class="n" id="k_online"><?= $si_pf($si_po['now'] ?? null) ?></div><div class="l">онлайн сейчас</div></div>
        <div class="si-card"><div class="n" id="k_active"><?= $si_pf($si_pu['ACTIVE'] ?? null) ?></div><div class="l">активных юзеров</div></div>
        <div class="si-card"><div class="n"><span id="k_nodes_on"><?= $si_non === null ? '—' : $si_non ?></span> / <span id="k_nodes_tot"><?= $si_ntot === null ? '—' : $si_ntot ?></span></div><div class="l">ноды онлайн</div></div>
    </div>

    <div class="seg" id="siSeg">
        <button type="button" data-seg="mw" class="<?= $si_seg === 'mw' ? 'on' : '' ?>">Прослойка</button>
        <button type="button" data-seg="panel" class="<?= $si_seg === 'panel' ? 'on' : '' ?>">Панель</button>
        <button type="button" data-seg="env" class="<?= $si_seg === 'env' ? 'on' : '' ?>">Окружение</button>
        <button type="button" data-seg="custom" class="<?= $si_seg === 'custom' ? 'on' : '' ?>">Кастомизация</button>
    </div>

    <div class="si-panels">
    <div class="si-seg-panel" data-panel="mw"<?= $si_seg === 'mw' ? '' : ' hidden' ?>>
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

        <section class="<?= coll_cls('sysinfo_peaks', true) ?>" data-coll="sysinfo_peaks">
            <button type="button" class="coll-head" onclick="collToggle(this)"><span>Аномальные пики нагрузки</span>
                <span class="coll-hr"><svg width="30" height="30" class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg></span>
            </button>
            <div class="coll-body">
                <p class="muted">Минута помечается пиком, если запросов в неё ≥ <b><?= h(number_format($si_factor, 1)) ?>×</b> от средней за предыдущий час И не меньше порога <b><?= (int) $si_floor ?></b> запр/мин.</p>
                <div style="display:flex;gap:1rem;flex-wrap:wrap;align-items:flex-end;justify-content:space-between;margin-bottom:1rem">
                    <form method="post" data-autosave style="display:flex;gap:1rem;flex-wrap:wrap;align-items:flex-end;margin:0">
                        <input type="hidden" name="csrf" value="<?= h($token) ?>">
                        <input type="hidden" name="action" value="save_metrics_cfg">
                        <div><label style="font-size:.8rem;font-weight:600;margin-bottom:.3rem">Множитель (×средней)</label><input type="number" step="0.1" min="1.5" name="metrics_peak_factor" value="<?= h(number_format($si_factor, 1)) ?>" style="width:120px;padding:.5rem;border:1px solid var(--line)"></div>
                        <div><label style="font-size:.8rem;font-weight:600;margin-bottom:.3rem">Мин. порог, запр/мин</label><input type="number" min="5" name="metrics_peak_floor" value="<?= (int) $si_floor ?>" style="width:120px;padding:.5rem;border:1px solid var(--line)"></div>
                        <button class="btn" type="submit" style="padding:.55rem 1.1rem">Сохранить</button>
                    </form>
                    <form method="post" onsubmit="return uiConfirmForm(this,'Очистить лог пиков нагрузки?')" style="margin:0">
                        <input type="hidden" name="csrf" value="<?= h($token) ?>">
                        <input type="hidden" name="action" value="clear_peaks">
                        <button class="danger" type="submit">🧹 Очистить</button>
                    </form>
                </div>
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
        </section>

        <?php if ($cr_on): ?>
        <section class="<?= coll_cls('sysinfo_cron', $cr_alive) ?>" data-coll="sysinfo_cron">
            <button type="button" class="coll-head" onclick="collToggle(this)"><span>Планировщик <?php if ($cr_alive): ?><span class="tag normal">работает</span><?php elseif ($cr_v['status'] === 'stopped'): ?><span class="tag blocked">остановился</span><?php else: ?><span class="tag expired">не настроен</span><?php endif; ?></span>
                <span class="coll-hr"><svg width="30" height="30" class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg></span>
            </button>
            <div class="coll-body">
                <?php if ($cr_v['beat'] > 0): ?>
                <p class="muted" style="margin-top:0">Последний запуск <span class="cr-ts" data-ts="<?= $cr_v['beat'] ?>"><?= h(date('Y-m-d H:i', $cr_v['beat'])) ?></span><?= $cr_v['src'] !== '' ? ' · ' . h($cr_v['src']) : '' ?>.<?= $cr_alive ? '' : ' Пока он не запускается, фоновые задачи снова идут попутно с запросами, как без планировщика.' ?></p>
                <?php else: ?>
                <p class="muted" style="margin-top:0">Фоновые задачи идут попутно с запросами: чистки, проверки грейса, геобазы. Без трафика они не выполняются. Планировщик запускает их сам раз в минуту.</p>
                <?php endif; ?>
                <?php if ($cr_v['error']): ?>
                <div class="warn">Запуск не удался<?= ($cr_v['error']['ts'] ?? 0) ? ' <span class="cr-ts" data-ts="' . (int) $cr_v['error']['ts'] . '">' . h(date('Y-m-d H:i', (int) $cr_v['error']['ts'])) . '</span>' : '' ?>: <?= h((string) ($cr_v['error']['err'] ?? '')) ?></div>
                <?php endif; ?>
                <?php foreach ($cr_v['rows'] as $cr_r): ?>
                <div class="si-kv"><span class="k"><?= h($cr_r['title']) ?></span><span class="v"><?php if ($cr_r['state'] === 'never'): ?>—<?php elseif ($cr_r['state'] === 'admin'): ?><span class="muted" data-tip="Без php-fpm запуск по адресу делает только быстрые задачи">при заходе в админку</span><?php else: ?><?php if ($cr_r['state'] === 'run'): ?><span class="tag expired">идёт</span> <?php elseif ($cr_r['state'] === 'err'): ?><span class="tag blocked" data-tip="<?= h($cr_r['err']) ?>">ошибка</span> <?php endif; ?><span class="cr-ts" data-ts="<?= (int) $cr_r['ts'] ?>"><?= h(date('Y-m-d H:i', (int) $cr_r['ts'])) ?></span><?php endif; ?></span></div>
                <?php endforeach; ?>
                <?php if (submw_in_docker()): ?>
                <p class="muted" style="margin-bottom:0">В Docker планировщик запускается сам, раз в минуту.<?= $cr_alive ? '' : ' Если он не работает — обновите образ и пересоздайте контейнер.' ?></p>
                <?php else: ?>
                <h3 style="margin:1.1rem 0 .3rem;font-size:.95rem">Как запустить</h3>
                <p class="muted" style="margin:0 0 .5rem;font-size:.84rem">Строки с <code>* * * * *</code> — это расписание для crontab, а не команда на один раз. В панели хостинга (раздел cron сайта) расписание «каждую минуту» задаётся отдельно, а в команду идёт всё после <code>* * * * *</code>.</p>
                <?php foreach ($cr_lines as [$cr_lt, $cr_lv]): ?>
                <div class="set-d"><?= h($cr_lt) ?></div>
                <div class="cr-line"><code><?= h($cr_lv) ?></code><button type="button" class="btn ghost" data-cr-copy="<?= h($cr_lv) ?>">Копировать</button></div>
                <?php endforeach; ?>
                <form method="post" onsubmit="return uiConfirmForm(this, 'Сменить секрет? Строка cron со старым адресом перестанет работать.', 'Сменить')">
                    <input type="hidden" name="csrf" value="<?= h($token) ?>">
                    <input type="hidden" name="action" value="cron_secret_rotate">
                    <button type="submit" class="btn ghost">Сменить секрет</button>
                    <span class="hint">Адрес с секретом запускает задачи. Если он попал в чужие руки — смените.</span>
                </form>
                <?php endif; ?>
            </div>
        </section>
        <?php endif; ?>
        <?php if (function_exists('apistat_view')):
            $as_h = (int) ($_GET['api_h'] ?? 72);
            if (!in_array($as_h, [24, 72, 168], true)) $as_h = 72;
            $as = apistat_view($as_h);
            $as_n = static fn($v) => number_format((int) $v, 0, ',', ' ');
            $as_ms = static fn($ms, $n) => $n > 0 ? $as_n(round($ms / $n)) . ' мс' : '—';
            $as_pct = static fn($a, $b) => $b > 0 ? rtrim(rtrim(number_format($a / $b * 100, 1, ',', ''), '0'), ',') . '%' : '—';
            $as_max = max(1, $as['peak']);
        ?>
        <section class="<?= coll_cls('sysinfo_api', false) ?>" data-coll="sysinfo_api">
            <button type="button" class="coll-head" onclick="collToggle(this)"><span>Запросы к панели <span class="tag normal"><?= $as_n($as['total']) ?> за <?= $as_h === 24 ? 'сутки' : ($as_h === 72 ? '3 дня' : 'неделю') ?></span></span>
                <span class="coll-hr"><svg width="30" height="30" class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg></span>
            </button>
            <div class="coll-body">
                <div style="display:flex;gap:.8rem;align-items:center;flex-wrap:wrap;margin-bottom:.7rem">
                    <p class="muted" style="margin:0;flex:1 1 18rem">Кто из прослойки и как часто ходит в панель: проксирование подписки и каждый вызов API, по точкам входа и адресам. Считается в памяти, в базу пишется почасовыми суммами после ответа клиенту; хранится неделю.</p>
                    <span class="api-sw"><?php foreach ([24 => 'сутки', 72 => '3 дня', 168 => 'неделя'] as $as_k => $as_l): ?><a href="?tab=sysinfo&amp;seg=mw&amp;api_h=<?= $as_k ?>" class="<?= $as_h === $as_k ? 'on' : '' ?>"><?= $as_l ?></a><?php endforeach; ?></span>
                </div>
                <div class="si-stat">
                    <div class="si-card"><div class="n"><?= $as_n($as['total']) ?></div><div class="l">всего</div></div>
                    <div class="si-card"><div class="n"><?= $as_n(round($as['total'] / $as_h)) ?></div><div class="l">в час в среднем</div></div>
                    <div class="si-card"><div class="n"><?= $as_n($as['peak']) ?></div><div class="l">пик часа<?= $as['peak_h'] ? ' · <span class="cr-ts" data-ts="' . (int) $as['peak_h'] . '">' . h(date('d.m H:00', (int) $as['peak_h'])) . '</span>' : '' ?></div></div>
                    <div class="si-card"><div class="n"<?= $as['err'] > 0 ? ' style="color:var(--c-bad-fg)"' : '' ?>><?= $as_pct($as['err'], $as['total']) ?></div><div class="l">ошибок<?= $as['err'] > 0 ? ' · ' . $as_n($as['err']) : '' ?></div></div>
                    <div class="si-card"><div class="n"><?= $as_ms($as['ms'], $as['total']) ?></div><div class="l">среднее время ответа</div></div>
                </div>
                <div class="api-sp" data-tip="Запросов к панели по часам, слева направо">
                    <?php foreach ($as['series'] as $as_v): ?><i<?= $as_v > 0 ? ' style="height:' . max(4, (int) round($as_v / $as_max * 100)) . '%"' : ' class="z"' ?>></i><?php endforeach; ?>
                </div>
                <?php if (!$as['groups']): ?>
                <p class="muted" style="margin:0">За этот период обращений к панели не записано.</p>
                <?php else: ?>
                <div class="api-hd"><span>Откуда</span><span>Запросов</span><span>Доля</span><span class="c-err">Ошибок</span><span class="c-ms">Среднее</span></div>
                <?php foreach ($as['groups'] as $as_i => $as_g): ?>
                <details class="api-g"<?= $as_i === 0 ? ' open' : '' ?>>
                    <summary>
                        <span class="nm"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg><span title="<?= h($as_g['src']) ?>"><?= h($as_g['label']) ?></span></span>
                        <span class="num"><?= $as_n($as_g['n']) ?></span>
                        <span class="num"><?= $as_pct($as_g['n'], $as['total']) ?></span>
                        <span class="num c-err<?= $as_g['err'] > 0 ? ' bad' : '' ?>"><?= $as_g['err'] > 0 ? $as_n($as_g['err']) : '—' ?></span>
                        <span class="num c-ms"><?= $as_ms($as_g['ms'], $as_g['n']) ?></span>
                        <span class="bar"><i style="width:<?= $as['total'] > 0 ? round($as_g['n'] / $as['total'] * 100, 1) : 0 ?>%"></i></span>
                    </summary>
                    <?php foreach ($as_g['eps'] as $as_e): ?>
                    <div class="api-e"><span><code><?= h($as_e['ep']) ?></code></span><span class="num"><?= $as_n($as_e['n']) ?></span><span class="num"><?= $as_pct($as_e['n'], $as_g['n']) ?></span><span class="num c-err<?= $as_e['err'] > 0 ? ' bad' : '' ?>"><?= $as_e['err'] > 0 ? $as_n($as_e['err']) : '—' ?></span><span class="num c-ms"><?= $as_ms($as_e['ms'], $as_e['n']) ?></span></div>
                    <?php endforeach; ?>
                </details>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>
        <?php endif; ?>
        <script>
        document.querySelectorAll('[data-cr-copy]').forEach(function(b){ b.addEventListener('click', function(){ uiCopy(b.getAttribute('data-cr-copy'), b); }); });
        </script>
    </div>

    <div class="si-seg-panel" data-panel="panel"<?= $si_seg === 'panel' ? '' : ' hidden' ?>>
        <div class="card">
            <div class="loghead">
                <h2>Панель Remnawave</h2>
                <div class="loghead-r">
                    <button type="button" class="btn ghost" onclick="siPanelFetch(true)">🔄 Обновить</button>
                </div>
            </div>
            <div class="si-pnote">
                <span>Данные из REST API панели, кешируются на сервере на 45 с.</span>
                <span id="p_status" class="muted"></span>
            </div>
            <div class="si-sub">Версия</div>
            <div class="si-kv"><span class="k">Версия панели</span><span class="v" id="si_panel_ver" data-ver="<?= h($si_pmv) ?>"><?= $si_pmv !== '' ? h($si_pmv) : '—' ?><?php if ($si_pmv !== '' && !panel_version_supported()): ?> <span class="muted">(ниже поддерживаемой <?= h(panel_min_supported()) ?>)</span><?php endif; ?></span></div>
            <?php if (!empty($si_pm['build_time'])): ?><div class="si-kv"><span class="k">Сборка</span><span class="v"><?= h((string) $si_pm['build_time']) ?><?= !empty($si_pm['build_number']) ? ' · №' . h((string) $si_pm['build_number']) : '' ?></span></div><?php endif; ?>
            <?php if (!empty($si_pm['commit'])): ?><div class="si-kv"><span class="k">Коммит бэкенда</span><span class="v"><code><?= h(substr((string) $si_pm['commit'], 0, 7)) ?></code><?= !empty($si_pm['branch']) ? ' (' . h((string) $si_pm['branch']) . ')' : '' ?></span></div><?php endif; ?>
            <?php if ($si_pmv === '' && !empty($si_pm['error'])): ?><div class="si-kv"><span class="k">Ошибка</span><span class="v muted"><?= h((string) $si_pm['error']) ?></span></div><?php endif; ?>
            <div class="si-sub">Пользователи</div>
            <div class="si-stat">
                <div class="si-card"><div class="n" id="p_active"><?= $si_pf($si_pu['ACTIVE'] ?? null) ?></div><div class="l">активные</div></div>
                <div class="si-card"><div class="n" id="p_limited"><?= $si_pf($si_pu['LIMITED'] ?? null) ?></div><div class="l">лимит трафика</div></div>
                <div class="si-card"><div class="n" id="p_expired"><?= $si_pf($si_pu['EXPIRED'] ?? null) ?></div><div class="l">истёкшие</div></div>
                <div class="si-card"><div class="n" id="p_disabled"><?= $si_pf($si_pu['DISABLED'] ?? null) ?></div><div class="l">отключённые</div></div>
                <div class="si-card"><div class="n" id="p_total"><?= $si_pf($si_pu['total'] ?? null) ?></div><div class="l">всего</div></div>
            </div>
            <div class="si-sub">Онлайн и ноды</div>
            <div class="si-stat">
                <div class="si-card"><div class="n" id="p_on_now"><?= $si_pf($si_po['now'] ?? null) ?></div><div class="l">онлайн сейчас</div></div>
                <div class="si-card"><div class="n" id="p_on_day"><?= $si_pf($si_po['day'] ?? null) ?></div><div class="l">за сутки</div></div>
                <div class="si-card"><div class="n" id="p_on_week"><?= $si_pf($si_po['week'] ?? null) ?></div><div class="l">за неделю</div></div>
                <div class="si-card"><div class="n"><span id="p_nodes_on"><?= $si_non === null ? '—' : $si_non ?></span> / <span id="p_nodes_tot"><?= $si_ntot === null ? '—' : $si_ntot ?></span></div><div class="l">ноды онлайн</div></div>
            </div>
        </div>

        <section class="<?= coll_cls('sysinfo_panelconf', true) ?>" data-coll="sysinfo_panelconf" id="siPanelConf" data-sig="<?= h(panel_config_sig($si_cf)) ?>">
            <button type="button" class="coll-head" onclick="collToggle(this)"><span>Конфигурация панели</span>
                <span class="coll-hr"><svg width="30" height="30" class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg></span>
            </button>
            <div class="coll-body">
                <?php if (!$si_cf_on): ?>
                <p class="muted">Доступно с версии панели <?= h(panel_config_min()) ?> — она отдаёт свои настройки через <code>GET /api/system/configuration</code>. На более старых панелях эндпоинта нет, и прослойка его не запрашивает.</p>
                <?php else: ?>
                <p class="muted">Настройки самой панели из <code>GET /api/system/configuration</code>, кешируются на 10 минут и обновляются в фоне. Прослойка берёт отсюда домен подписки, состояние вебхуков и длину shortUuid; менять эти значения можно только в <code>.env</code> панели.</p>
                <?php if (!$si_cf_has): ?><p class="muted">Значения ещё не получены — нажмите «Обновить» выше.</p><?php endif; ?>
                <?php if (!empty($si_cf['error'])): ?><p class="muted">Последний запрос не удался: <?= h((string) $si_cf['error']) ?></p><?php endif; ?>
                <div class="si-sub">Уведомления</div>
                <div class="si-kv"><span class="k">Вебхуки</span><span class="v"><?= $si_cb($si_cf['webhook'] ?? null) ?></span></div>
                <div class="si-kv"><span class="k">Пороги по трафику, %</span><span class="v"><?= h($si_cl($si_cf['bandwidth_usage'] ?? null)) ?></span></div>
                <div class="si-kv"><span class="k">Давно не подключался, ч</span><span class="v"><?= h($si_cl($si_cf['not_connected_after'] ?? null)) ?></span></div>
                <div class="si-kv"><span class="k">Напоминания об истечении, дн</span><span class="v"><?= h($si_cl($si_cf['expiration_notifications'] ?? null)) ?></span></div>
                <div class="si-sub">Служебное</div>
                <div class="si-kv"><span class="k">Чистить историю потребления</span><span class="v"><?= $si_cb($si_cf['clean_usage_history'] ?? null) ?></span></div>
                <div class="si-kv"><span class="k">Не писать записи потребления</span><span class="v"><?= $si_cb($si_cf['disable_user_usage_records'] ?? null) ?></span></div>
                <div class="si-kv"><span class="k">Не писать SRH-записи</span><span class="v"><?= $si_cb($si_cf['disable_srh_records'] ?? null) ?></span></div>
                <div class="si-kv"><span class="k">Экспорт в Redis Stream</span><span class="v"><?= $si_cb($si_cf['export_to_redis_stream'] ?? null) ?></span></div>
                <div class="si-sub">Прочее</div>
                <div class="si-kv"><span class="k">Длина shortUuid</span><span class="v"><?= ((int) ($si_cf['short_uuid_length'] ?? 0)) ?: '—' ?></span></div>
                <div class="si-kv"><span class="k">Игнорировать потребление ниже</span><span class="v"><?= ($si_cf['user_usage_ignore_below_bytes'] ?? null) === null ? '—' : h(metrics_fmt_bytes((int) $si_cf['user_usage_ignore_below_bytes'])) ?></span></div>
                <div class="si-kv"><span class="k">Домен подписки панели</span><span class="v"><?= ($si_cf['sub_public_domain'] ?? '') !== '' ? '<code>' . h((string) $si_cf['sub_public_domain']) . '</code>' : '—' ?></span></div>
                <?php endif; ?>
            </div>
        </section>
    </div>

    <div class="si-seg-panel" data-panel="env"<?= $si_seg === 'env' ? '' : ' hidden' ?>>
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
    </div>

    <div class="si-seg-panel" data-panel="custom"<?= $si_seg === 'custom' ? '' : ' hidden' ?>>
        <div class="card">
            <div class="loghead">
                <h2>Разделы в меню слева</h2>
                <div class="loghead-r">
                    <button type="button" class="btn ghost" id="nhAll"<?= $nav_off_n ? '' : ' hidden' ?>>Показать все</button>
                </div>
            </div>
            <p class="muted">Выключенный раздел только пропадает из меню. Его настройки сохраняются как есть, он продолжает работать, прямые ссылки на него открываются. Пока что-то скрыто, в шапке виден значок со счётчиком — он ведёт сюда.</p>
            <form method="post" id="nhForm">
                <input type="hidden" name="csrf" value="<?= h($token) ?>">
                <input type="hidden" name="action" value="save_nav_hidden">
                <div class="nh-grid">
                <?php foreach ($nav_sections as $sec): $g = $sec['k']; $g_on = !isset($nav_hid['g'][$g]); $g_n = count($sec['items']); $g_cnt = $g_n . ' ' . (($g_n % 10 === 1 && $g_n % 100 !== 11) ? 'раздел' : (($g_n % 10 >= 2 && $g_n % 10 <= 4 && ($g_n % 100 < 10 || $g_n % 100 >= 20)) ? 'раздела' : 'разделов')); ?>
                    <div class="nh-grp<?= $g_on ? '' : ' off' ?>" data-g="<?= h($g) ?>">
                        <input type="hidden" name="nk_g[]" value="<?= h($g) ?>">
                        <label class="nh-gh">
                            <span class="set-info"><span class="set-t"><?= h($sec['l']) ?></span><span class="set-d" data-on="<?= h($g_cnt) ?>" data-off="Группа скрыта целиком"><?= $g_on ? h($g_cnt) : 'Группа скрыта целиком' ?></span></span>
                            <span class="switch"><input type="checkbox" name="nv_g[]" value="<?= h($g) ?>" aria-label="Показывать группу «<?= h($sec['l']) ?>»"<?= $g_on ? ' checked' : '' ?>><span class="sl"></span></span>
                        </label>
                        <div class="nh-items"<?= $g_on ? '' : ' inert' ?>>
                        <?php foreach ($sec['items'] as $key): $t_on = !isset($nav_hid['t'][$key]); ?>
                            <input type="hidden" name="nk_t[]" value="<?= h($key) ?>">
                            <label class="nh-it<?= $t_on ? '' : ' off' ?>" data-t="<?= h($key) ?>">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><?= $nav[$key][1] ?></svg>
                                <span><?= h($nav[$key][0]) ?></span>
                                <?php if ($key === $tab_nav): ?><span class="nh-cur">эта страница</span><?php endif; ?>
                                <span class="switch"><input type="checkbox" name="nv_t[]" value="<?= h($key) ?>" aria-label="Показывать «<?= h($nav[$key][0]) ?>» в меню"<?= $t_on ? ' checked' : '' ?>><span class="sl"></span></span>
                            </label>
                        <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
                </div>
                <noscript><button class="btn" type="submit" style="margin-top:1rem">Сохранить</button></noscript>
            </form>
        </div>
    </div>
    </div>

    <script>
    var SI_PANEL = null;
    function siFmtBytes(n){n=Number(n)||0;var u=['B','KB','MB','GB','TB'],i=0;while(n>=1024&&i<u.length-1){n/=1024;i++;}return (i===0?Math.round(n):(n>=100?Math.round(n):Math.round(n*10)/10))+' '+u[i];}
    function siLocal(ep,withDate){ep=parseInt(ep,10);if(!ep)return '';var d=new Date(ep*1000);function p(n){return(n<10?'0':'')+n;}var t=p(d.getHours())+':'+p(d.getMinutes());return withDate?(d.getFullYear()+'-'+p(d.getMonth()+1)+'-'+p(d.getDate())+' '+t):t;}
    function siPnum(v){return (v===null||v===undefined)?'—':Number(v).toLocaleString('ru-RU');}
    function siChart(series){
        var box=document.getElementById('si_chart'); if(!box||!series) return;
        var mx=1; series.forEach(function(p){ if(p.hits>mx) mx=p.hits; });
        box.innerHTML=series.map(function(p){
            var sub=Math.min(p.hits,p.sub||0);
            var hpx=p.hits>0?Math.max(3,Math.round(p.hits/mx*108)):2;
            var spx=p.hits>0?Math.round(hpx*sub/p.hits):0;
            var bpx=hpx-spx;
            return '<div class="si-bar2'+(p.hits===0?' zero':'')+'" style="height:'+hpx+'px" title="'+siLocal(p.ts)+' · подписка '+sub+' · краулеры '+(p.hits-sub)+' · всего '+p.hits+' · '+p.ms+' мс"><div class="seg-bot" style="height:'+bpx+'px"></div><div class="seg-sub" style="height:'+spx+'px"></div></div>';
        }).join('');
    }
    function siPeaks(rows){
        var b=document.getElementById('si_peaks'); if(!b) return;
        if(!rows||!rows.length){ b.innerHTML='<tr><td colspan="5" class="muted">Пиков не зафиксировано</td></tr>'; return; }
        b.innerHTML=rows.map(function(p){
            return '<tr><td class="muted">'+siLocal(p.minute_ts,true)+'</td>'+
                   '<td><span class="tag error">'+p.hits+'</span></td>'+
                   '<td class="muted">'+p.baseline+'</td>'+
                   '<td class="muted">'+p.dur_ms_max+' мс</td>'+
                   '<td class="muted">'+siFmtBytes(p.mem_max)+'</td></tr>';
        }).join('');
    }
    function siSet(id,v){var e=document.getElementById(id); if(e) e.textContent=v;}
    function siBreak(id,total,sub){var e=document.getElementById(id); if(!e) return; total=total||0; sub=Math.min(total,sub||0); e.innerHTML='<span class="si-dot sub"></span><span class="vsub">'+sub+'</span> <span class="si-dot bot"></span>'+(total-sub);}
    function siRefresh(){
        var a=document.getElementById('siAuto'); if(a) a.textContent='· обновление…';
        fetch('?ajax=sysinfo').then(function(r){return r.json();}).then(function(d){
            if(d.ok){
                var L=d.load||{};
                siSet('si_m1',L.m1); siSet('si_m5',L.m5); siSet('si_m60',L.m60); siSet('si_today',L.today);
                siBreak('si_m1b',L.m1,L.m1_sub); siBreak('si_m5b',L.m5,L.m5_sub); siBreak('si_m60b',L.m60,L.m60_sub); siBreak('si_todayb',L.today,L.today_sub);
                siSet('si_rpm',(L.rpm_60||0).toFixed(1)); siSet('k_rpm',(L.rpm_60||0).toFixed(1));
                var avg=document.getElementById('si_avg'); if(avg) avg.innerHTML=(L.avg_ms||0)+'<span style="font-size:.8rem"> мс</span>';
                var mxc=document.getElementById('si_max'); if(mxc) mxc.innerHTML=(L.max_ms_60||0)+'<span style="font-size:.8rem"> мс</span>';
                siChart(d.series); siPeaks(d.peaks);
                if(d.sys){ if(d.sys.load&&d.sys.load.length){ siSet('si_load', d.sys.load.map(function(x){return Number(x).toFixed(2);}).join(' · ')+(d.sys.cores?(' (ядер: '+d.sys.cores+')'):'')); } siSet('si_mem', siFmtBytes(d.sys.mem_peak)); }
                if(d.db) siSet('si_dbsize', siFmtBytes(d.db.size));
            }
            if(a) a.textContent='· обновлено в '+new Date().toLocaleTimeString();
        }).catch(function(){ if(a) a.textContent='· ошибка обновления'; });
    }
    function siPanelApply(s){
        if(!s) return;
        var u=s.users||{}, o=s.online||{}, n=s.nodes||{};
        siSet('k_online',siPnum(o.now)); siSet('k_active',siPnum(u.ACTIVE));
        siSet('k_nodes_on', n.online==null?'—':n.online); siSet('k_nodes_tot', n.total==null?'—':n.total);
        siSet('p_active',siPnum(u.ACTIVE)); siSet('p_limited',siPnum(u.LIMITED)); siSet('p_expired',siPnum(u.EXPIRED)); siSet('p_disabled',siPnum(u.DISABLED)); siSet('p_total',siPnum(u.total));
        siSet('p_on_now',siPnum(o.now)); siSet('p_on_day',siPnum(o.day)); siSet('p_on_week',siPnum(o.week));
        siSet('p_nodes_on', n.online==null?'—':n.online); siSet('p_nodes_tot', n.total==null?'—':n.total);
        var st=document.getElementById('p_status');
        if(st){ if(s.error){ st.textContent='· панель недоступна: '+s.error; } else { st.textContent='· обновлено '+(s.age||0)+' с назад'; } }
    }
    function siPanelFetch(force){
        var st=document.getElementById('p_status'); if(force&&st) st.textContent='· обновление…';
        fetch('?ajax=panelstats'+(force?'&force=1':'')).then(function(r){return r.json();}).then(function(d){ if(d&&d.stats){ SI_PANEL=d.stats; siPanelApply(d.stats); } }).catch(function(){ if(st) st.textContent='· ошибка запроса'; });
        if(force){
            var cur=document.getElementById('si_panel_ver');
            var conf=document.getElementById('siPanelConf');
            fetch('?ajax=panelmeta&force=1').then(function(r){return r.json();}).then(function(d){
                if(!d||!d.meta)return;
                if(cur&&(d.meta.version||'').trim()!==(cur.dataset.ver||'')){location.reload();return;}
                if(conf&&d.conf_sig&&d.conf_sig!==(conf.dataset.sig||'')) location.reload();
            }).catch(function(){});
        }
    }
    function siSeg(name){
        var btns=document.querySelectorAll('#siSeg button'); for(var i=0;i<btns.length;i++) btns[i].classList.toggle('on',btns[i].getAttribute('data-seg')===name);
        var pans=document.querySelectorAll('.si-seg-panel'); for(var j=0;j<pans.length;j++) pans[j].hidden=(pans[j].getAttribute('data-panel')!==name);
        try{document.cookie='si_seg='+name+';path=/;max-age=31536000;samesite=Lax';}catch(e){}
        try{var u=new URL(location.href);if(u.searchParams.has('seg')){u.searchParams.delete('seg');history.replaceState(history.state,'',u.pathname+u.search+u.hash);}}catch(e){}
        if(name==='panel' && (!SI_PANEL || (SI_PANEL.age||0)>30)) siPanelFetch(false);
    }
    (function(){var b=document.querySelectorAll('#siSeg button'); for(var i=0;i<b.length;i++) b[i].addEventListener('click',function(){siSeg(this.getAttribute('data-seg'));});})();
    (function(){
        var f=document.getElementById('nhForm'); if(!f) return;
        var all=document.getElementById('nhAll'), bd=document.getElementById('navHidBadge');
        var boxes=function(){return f.querySelectorAll('input[type=checkbox]');};
        var saved=[], busy=false, again=false;
        var state=function(){var r=[];boxes().forEach(function(c){r.push(c.checked);});return r;};
        saved=state();
        var paint=function(){
            var G={},T={},any=false;
            f.querySelectorAll('.nh-grp').forEach(function(g){
                var gc=g.querySelector('.nh-gh input'), on=gc.checked, d=g.querySelector('.nh-gh .set-d'), it=g.querySelector('.nh-items');
                g.classList.toggle('off',!on); if(d) d.textContent=on?d.getAttribute('data-on'):d.getAttribute('data-off');
                if(on) it.removeAttribute('inert'); else it.setAttribute('inert','');
                if(!on){G[gc.value]=1;any=true;}
            });
            f.querySelectorAll('.nh-it').forEach(function(l){var c=l.querySelector('input');l.classList.toggle('off',!c.checked);if(!c.checked){T[c.value]=1;any=true;}});
            if(all) all.hidden=!any;
            if(window.navHidApply) navHidApply(G,T);
        };
        var send=function(msg){
            if(busy){again=true;return;}
            busy=true;
            var sent=state(), fd=new FormData(f); fd.append('xhr','1');
            fetch('index.php',{method:'POST',credentials:'same-origin',body:fd}).then(function(r){return r.json();}).then(function(d){
                busy=false;
                if(!d||!d.ok) throw 0;
                saved=sent;
                if(again){again=false;send('Меню обновлено');return;}
                if(window.uiToast) uiToast(msg||d.msg||'Сохранено');
            }).catch(function(){
                busy=false; again=false;
                boxes().forEach(function(c,i){if(i<saved.length)c.checked=saved[i];}); paint();
                if(window.uiToast) uiToast('Не сохранено — переключатели вернулись к сохранённому');
            });
        };
        f.addEventListener('change',function(e){
            var c=e.target; if(!c||c.type!=='checkbox') return;
            paint();
            var it=c.closest('.nh-it'), gr=c.closest('.nh-grp'), nm;
            if(it){nm=it.querySelector('span').textContent.trim();send(c.checked?'«'+nm+'» снова в меню':'«'+nm+'» скрыт из меню — раздел работает, ссылка открывается');}
            else{nm=gr.querySelector('.set-t').textContent.trim();send(c.checked?'Группа «'+nm+'» снова в меню':'Группа «'+nm+'» скрыта из меню');}
        });
        f.addEventListener('submit',function(e){e.preventDefault();send();});
        if(all) all.addEventListener('click',function(){boxes().forEach(function(c){c.checked=true;});paint();send('Все разделы снова в меню');});
        if(bd) bd.addEventListener('click',function(e){if(e.button!==0||e.ctrlKey||e.metaKey||e.shiftKey||e.altKey)return;e.preventDefault();siSeg('custom');var p=document.querySelector('.si-seg-panel[data-panel="custom"]');if(p)p.scrollIntoView({block:'start',behavior:'smooth'});});
    })();
    siChart(<?= json_encode($sys_series) ?>);
    document.querySelectorAll('.si-pk-ts[data-ts],.cr-ts[data-ts]').forEach(function(td){var v=siLocal(td.getAttribute('data-ts'),true);if(v)td.textContent=v;});
    siPanelFetch(false);
    setInterval(function(){ if(document.hidden) return; siRefresh(); var on=document.querySelector('#siSeg button.on'); if(on&&on.getAttribute('data-seg')==='panel') siPanelFetch(false); }, 15000);
    document.addEventListener('visibilitychange',function(){ if(!document.hidden) siRefresh(); });
    </script>
