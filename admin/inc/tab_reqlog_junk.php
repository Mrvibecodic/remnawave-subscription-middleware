<?php
$jk      = is_array($jk ?? null) ? $jk : junk_view();
$jk_k    = $jk['kpi'];
$jk_resp = junk_resps();
$jk_pass = max(0, $jk_k['n'] - $jk_k['cut']);
$jk_max  = 1;
foreach ($jk['hours'] as $jh) $jk_max = max($jk_max, $jh['n']);
$jk_sets = ['paths' => junk_set_row('paths'), 'bots' => junk_set_row('bots')];
$jk_own  = array_values(array_filter($jk['rules'], static function ($r) { return $r['kind'] !== 'set'; }));
$jk_ts   = static function ($ts, $f = '') { return (int) $ts ? '<span class="jk-t" data-ts="' . (int) $ts . '"' . ($f !== '' ? ' data-f="' . h($f) . '"' : '') . '>' . h(date('d.m H:i', (int) $ts)) . '</span>' : '—'; };
$jk_ico  = [
    'rule'  => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 3H2l8 9.46V19l4 2v-8.54L22 3z"/></svg>',
    'ua'    => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="M6 8h.01"/><path d="M10 8h.01"/><path d="M6 12h12"/></svg>',
    'del'   => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>',
    'pause' => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="6" y="4" width="4" height="16" rx="1"/><rect x="14" y="4" width="4" height="16" rx="1"/></svg>',
    'play'  => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="6 3 20 12 6 21 6 3"/></svg>',
    'find'  => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>',
];
$jk_state = static function ($r, $cut_word) {
    if (!$r) return '';
    $lbl = h(junk_rule_label($r));
    return $r['mode'] === 'cut'
        ? '<span class="tag jk-cut">' . $cut_word . '</span><span class="jk-rule">' . $lbl . '</span>'
        : '<span class="tag jk-dry" data-tip="Правило в режиме «только считать»">только считает</span><span class="jk-rule">' . $lbl . '</span>';
};
?>
    <style>
    .rl-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:.7rem;margin:0 0 1.1rem}
    .rl-kpi{border:1px solid var(--line);background:var(--bg2);border-radius:12px;padding:.8rem .95rem;display:flex;flex-direction:column;gap:.15rem;min-height:96px}
    .rl-kpi .k{font-size:.72rem;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;line-height:1.3}
    .rl-kpi .v{font-size:1.55rem;font-weight:700;color:var(--text-strong);line-height:1.2;font-variant-numeric:tabular-nums}
    .rl-kpi .v small{font-size:.95rem;font-weight:500;color:var(--muted)}
    .rl-kpi .d{font-size:.76rem;color:var(--muted);margin-top:auto}
    @media(max-width:1100px){.rl-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}}
    @media(max-width:340px){.rl-kpis{grid-template-columns:1fr}}
    .jk-spark{display:flex;align-items:flex-end;gap:3px;height:56px}
    .jk-spark i{flex:1;border-radius:3px 3px 0 0;min-height:3px;background:var(--accent-light);position:relative}
    .jk-spark i b{position:absolute;left:0;right:0;bottom:0;background:var(--accent);border-radius:3px 3px 0 0}
    .jk-sparkx{display:flex;justify-content:space-between;font-size:.7rem;color:var(--muted);margin-top:.35rem;font-variant-numeric:tabular-nums}
    .jk-leg{display:flex;gap:1rem;font-size:.76rem;color:var(--muted);margin-top:.5rem;flex-wrap:wrap}
    .jk-leg span{display:inline-flex;align-items:center;gap:.35rem}
    .jk-leg i{width:10px;height:10px;border-radius:3px;display:inline-block}
    .jk-tabs{margin:0 0 1rem}
    .jk-pane{display:none}.jk-pane.on{display:block}
    .jk-flt{display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;margin:.2rem 0 .9rem}
    .jk-flt select,.jk-flt input[type=text]{height:38px;min-height:38px;padding-top:0;padding-bottom:0;font-size:.84rem;width:auto}
    .jk-flt .pgr{margin-left:auto}
    .jk-search{position:relative;flex:1 1 250px;max-width:360px}
    .jk-search input{width:100%!important;padding-left:2.1rem}
    .jk-search svg{position:absolute;left:.65rem;top:50%;transform:translateY(-50%);color:var(--muted);pointer-events:none}
    .jk-wrap{border:1px solid var(--line);border-radius:12px;overflow-x:auto;overflow-y:hidden}
    .jk-tbl{min-width:62rem;border-collapse:separate;font-size:.86rem;width:100%}
    .jk-tbl th{background:var(--bg2);font-size:.71rem;padding:.6rem .75rem;box-shadow:inset 0 -1px 0 var(--line);white-space:nowrap;text-align:left}
    .jk-tbl td{padding:.5rem .75rem;box-shadow:inset 0 -1px 0 var(--line);vertical-align:middle}
    .jk-tbl tr:last-child td{box-shadow:none}
    .jk-wrap thead th{position:static}
    .jk-tbl .ip{white-space:nowrap;word-break:normal}
    .jk-tbl code{font-size:.82rem;word-break:break-all}
    .jk-tbl .num{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}
    .jk-tbl .ts{white-space:nowrap;color:var(--muted);font-variant-numeric:tabular-nums}
    .jk-ua{color:var(--muted);font-size:.78rem;max-width:22rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;display:block}
    .jk-act{display:flex;gap:.35rem;justify-content:flex-end;white-space:nowrap}
    .jk-act form{margin:0;display:inline-flex}
    .jk-ib,.jk-ib:hover,.jk-ib:active{padding:0;width:30px;height:30px;min-height:30px;display:inline-flex;align-items:center;justify-content:center;border-radius:var(--radius);background:transparent;border:1px solid var(--line);color:var(--muted);cursor:pointer;filter:none;transform:none}
    .jk-ib:hover{border-color:var(--accent);color:var(--accent-text)}
    .jk-ib.bad:hover{border-color:var(--red);color:var(--red)}
    .jk-tbl .btn{height:30px;min-height:30px;padding:0 .7rem;font-size:.79rem}
    .tag.jk-pass{background:var(--bg2);color:var(--muted);border:1px solid var(--line)}
    .tag.jk-cut{background:var(--c-bad-bg);color:var(--c-bad-fg)}
    .tag.jk-dry{background:transparent;color:var(--c-warn-fg);border:1px dashed var(--c-warn-fg)}
    .tag.jk-type{background:var(--c-info-bg);color:var(--c-info-fg);font-weight:700}
    .jk-rule{font-size:.76rem;color:var(--muted);display:block;margin-top:.15rem}
    .jk-empty td{color:var(--muted);text-align:center;padding:1.2rem}
    .jk-prot{display:flex;gap:.75rem;align-items:flex-start;border:1px solid var(--line);background:var(--bg2);border-radius:12px;padding:.8rem .95rem;margin-bottom:1rem}
    .jk-prot svg{flex:0 0 auto;color:var(--c-ok-fg);margin-top:.1rem}
    .jk-prot .t{font-weight:600;color:var(--text-strong);font-size:.88rem}
    .jk-chips{display:flex;flex-wrap:wrap;gap:.35rem;margin-top:.45rem}
    .jk-chips code{font-size:.76rem;padding:.12rem .45rem;border:1px solid var(--line);border-radius:6px;background:var(--card)}
    .jk-h3{font-size:.95rem;margin:1.3rem 0 .2rem;color:var(--text-strong)}
    .set-row .jk-ctl{display:flex;gap:.45rem;align-items:center;flex-wrap:wrap;justify-content:flex-end}
    .set-row .jk-ctl select{width:auto;height:34px;min-height:34px;padding-top:0;padding-bottom:0;font-size:.82rem}
    .set-row .jk-ctl input[type=number]{width:72px;height:34px;min-height:34px;text-align:center;margin:0}
    .set-row .jk-ctl .lbl{font-size:.8rem;color:var(--muted)}
    .jk-more{font-size:.8rem;color:var(--accent-text);cursor:pointer;background:none;border:0;padding:0;margin-top:.3rem;min-height:0}
    .jk-more:hover{background:none;filter:none;transform:none;text-decoration:underline}
    .jk-list{display:none;margin-top:.45rem}
    .jk-list.open{display:block}
    .jk-f{margin-bottom:1rem}
    .jk-f>label{display:block;font-size:.8rem;font-weight:600;color:var(--text-strong);margin-bottom:.4rem}
    .jk-f .seg{margin-bottom:.5rem}
    .jk-f .hint{font-size:.76rem;color:var(--muted);margin-top:.35rem}
    .jk-mode{display:grid;grid-template-columns:1fr 1fr;gap:.5rem}
    .jk-mode label{display:flex;gap:.55rem;align-items:flex-start;border:1px solid var(--line);border-radius:10px;padding:.6rem .7rem;cursor:pointer;font-weight:500;margin:0;font-size:.84rem;color:var(--text)}
    .jk-mode label:has(input:checked){border-color:var(--accent);background:var(--accent-light)}
    .jk-mode input{width:auto;margin:.15rem 0 0}
    .jk-mode small{display:block;color:var(--muted);font-size:.74rem;font-weight:400;margin-top:.1rem}
    .jk-foot{display:flex;gap:.5rem;justify-content:flex-end;margin-top:1.1rem}
    .jk-warn{display:flex;gap:.5rem;align-items:flex-start;font-size:.8rem;color:var(--c-warn-fg);background:var(--c-warn-bg);border-radius:10px;padding:.55rem .7rem;margin-top:.6rem}
    .jk-warn svg{flex:0 0 auto;margin-top:.1rem}
    @media(max-width:560px){.set-row .jk-ctl{display:grid;grid-template-columns:minmax(0,1fr) auto;width:100%}.set-row .jk-ctl select{width:100%;min-width:0}.set-row .jk-ctl .switch{grid-column:2;grid-row:1/span 2}.jk-mode{grid-template-columns:1fr}}
    @media(max-width:900px){.jk-tbl .num{text-align:left}div.jk-wrap{overflow:visible;border:0;border-radius:0}.jk-ua{max-width:none;white-space:normal}.jk-act{justify-content:flex-end}}
    </style>

    <div class="seg">
        <a href="?tab=reqlog">Запросы</a>
        <a href="?tab=reqlog&amp;view=clients">Клиенты</a>
        <a class="on" href="?tab=reqlog&amp;view=junk">Мусор</a>
    </div>

    <div class="card">
        <div class="loghead">
            <h2>Мусор <span class="muted" style="font-weight:400;font-size:.78rem">сканеры и боты, за 24 часа</span></h2>
            <div class="loghead-r">
                <form method="post" onsubmit="return uiConfirmForm(this,'Очистить статистику мусора? Правила останутся.','Очистить')">
                    <input type="hidden" name="csrf" value="<?= h($token) ?>">
                    <input type="hidden" name="action" value="junk_clear">
                    <button class="btn ghost" type="submit" data-tip="Удалить последние запросы и сводки. Правила останутся">Очистить статистику</button>
                </form>
            </div>
        </div>
        <div class="rl-kpis" style="margin-top:1rem">
            <div class="rl-kpi"><span class="k">Мусорных запросов</span><span class="v"><?= number_format($jk_k['n'], 0, '', ' ') ?></span><span class="d">в час до <?= number_format($jk_k['peak'], 0, '', ' ') ?></span></div>
            <div class="rl-kpi"><span class="k">Отрезано правилами</span><span class="v"><?= number_format($jk_k['cut'], 0, '', ' ') ?><?php if ($jk_k['n'] > 0): ?> <small><?= (int) round($jk_k['cut'] * 100 / $jk_k['n']) ?>%</small><?php endif; ?></span><span class="d">панель не трогали</span></div>
            <div class="rl-kpi"><span class="k">Ушло в панель</span><span class="v"><?= number_format($jk_pass, 0, '', ' ') ?></span><span class="d"><?= $jk_k['dry'] > 0 ? 'ещё ' . number_format($jk_k['dry'], 0, '', ' ') . ' отрезал бы режим «только считать»' : 'прошли без правил' ?></span></div>
            <div class="rl-kpi"><span class="k">Уникальных IP</span><span class="v"><?= number_format($jk_k['ips'], 0, '', ' ') ?></span><span class="d"><?= $jk_k['top'] !== '' ? 'больше всех: ' . h($jk_k['top']) : '—' ?></span></div>
        </div>
        <div class="jk-spark" id="jkSpark">
            <?php foreach ($jk['hours'] as $jh): ?><i style="height:<?= max(3, (int) round($jh['n'] * 100 / $jk_max)) ?>%" data-h="<?= (int) $jh['h'] ?>" data-n="<?= (int) $jh['n'] ?>" data-c="<?= (int) $jh['cut'] ?>"><b style="height:<?= $jh['n'] > 0 ? (int) round($jh['cut'] * 100 / $jh['n']) : 0 ?>%"></b></i><?php endforeach; ?>
        </div>
        <div class="jk-sparkx" id="jkSparkX"><span data-h="<?= (int) $jk['hours'][0]['h'] ?>"></span><span data-h="<?= (int) $jk['hours'][6]['h'] ?>"></span><span data-h="<?= (int) $jk['hours'][12]['h'] ?>"></span><span data-h="<?= (int) $jk['hours'][18]['h'] ?>"></span><span>сейчас</span></div>
        <div class="jk-leg"><span><i style="background:var(--accent)"></i>отрезано</span><span><i style="background:var(--accent-light)"></i>ушло в панель</span></div>
    </div>

    <div class="card">
        <div class="seg jk-tabs" id="jkTabs">
            <button type="button" class="on" data-p="req">Запросы</button>
            <button type="button" data-p="paths">Пути</button>
            <button type="button" data-p="ips">IP</button>
            <button type="button" data-p="rules">Правила · <?= count($jk_own) ?></button>
        </div>

        <div class="jk-pane on" id="p-req">
            <div class="jk-flt">
                <div class="jk-search"><?= $jk_ico['find'] ?><input type="text" id="jkReqQ" placeholder="Путь, IP или User-Agent"></div>
                <select id="jkReqK"><option value="">Все ответы</option><option value="cut">Отрезанные</option><option value="pass">Ушли в панель</option><option value="dry">Отрезал бы</option></select>
                <div class="pgr" id="jkReqPg"></div>
            </div>
            <div class="jk-wrap">
                <table class="jk-tbl tcards">
                    <thead><tr><th style="width:8rem">Время</th><th style="width:9rem">IP</th><th>Путь</th><th>User-Agent</th><th style="width:12rem">Ответ</th><th style="width:5rem"></th></tr></thead>
                    <tbody id="jkReq">
                    <?php foreach ($jk['log'] as $r):
                        $rk = (string) $r['kind'];
                        $rs = h($jk_resp[(string) $r['resp']] ?? (string) $r['resp']);
                        if ($rk === 'cut') $tag = '<span class="tag jk-cut">отрезан · ' . $rs . '</span><span class="jk-rule">' . h($r['rule']) . '</span>';
                        elseif ($rk === 'dry') $tag = '<span class="tag jk-dry" data-tip="Правило в режиме «только считать»">отрезал бы · ' . $rs . '</span><span class="jk-rule">' . h($r['rule']) . '</span>';
                        else $tag = '<span class="tag jk-pass">в панель · ' . ((int) $r['code'] ? (int) $r['code'] : 'нет ответа') . '</span>';
                    ?>
                    <tr data-k="<?= h($rk) ?>" data-q="<?= h(mb_strtolower($r['ip'] . ' /' . $r['path'] . ' ' . $r['ua'])) ?>"><td class="ts" data-label="Время"><span class="jk-t" data-ts="<?= (int) $r['ts'] ?>" data-f="t"><?= h(date('H:i:s', (int) $r['ts'])) ?></span></td><td data-label="IP"><code class="ip"><?= h($r['ip']) ?></code></td><td data-label="Путь" data-wide><code>/<?= h($r['path']) ?></code></td><td data-label="User-Agent" data-wide><span class="jk-ua" data-tip="<?= h($r['ua']) ?>"><?= $r['ua'] !== '' ? h($r['ua']) : '—' ?></span></td><td data-label="Ответ" data-wide><?= $tag ?></td><td data-acts><div class="jk-act"><button class="jk-ib" type="button" data-new="path" data-v="<?= h($r['path']) ?>" data-tip="Правило на путь"><?= $jk_ico['rule'] ?></button><?php if ($r['ua'] !== ''): ?><button class="jk-ib" type="button" data-new="ua" data-v="<?= h(mb_substr($r['ua'], 0, 191)) ?>" data-tip="Правило на User-Agent"><?= $jk_ico['ua'] ?></button><?php endif; ?></div></td></tr>
                    <?php endforeach; ?>
                    <?php if (!$jk['log']): ?><tr class="jk-empty"><td colspan="6">Пока пусто — мусорных запросов не было</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="jk-pane" id="p-paths">
            <div class="jk-flt">
                <div class="jk-search"><?= $jk_ico['find'] ?><input type="text" id="jkPathQ" placeholder="Путь"></div>
                <select id="jkPathS"><option value="n">По числу запросов</option><option value="t">По последнему</option></select>
                <div class="pgr" id="jkPathPg"></div>
            </div>
            <div class="jk-wrap">
                <table class="jk-tbl tcards">
                    <thead><tr><th>Путь</th><th class="num" style="width:6rem">Запросов</th><th class="num" style="width:5rem">IP</th><th style="width:8rem">Последний</th><th style="width:13rem">Сейчас</th><th style="width:15rem"></th></tr></thead>
                    <tbody id="jkPaths">
                    <?php foreach ($jk['paths'] as $r): $st = junk_state_path((string) $r['path']); ?>
                    <tr data-q="<?= h(mb_strtolower('/' . $r['path'])) ?>" data-n="<?= (int) $r['hits'] ?>" data-t="<?= (int) $r['last_ts'] ?>"><td data-label="Путь" data-wide><code>/<?= h($r['path']) ?></code></td><td class="num" data-label="Запросов"><?= (int) $r['hits'] ?></td><td class="num" data-label="IP"><?= (int) $r['ips'] ?></td><td class="ts" data-label="Последний"><?= $jk_ts($r['last_ts']) ?></td><td data-label="Сейчас"><?= $st ? $jk_state($st, 'режется') : '<span class="tag jk-pass">в панель</span>' ?></td><td data-wide><div class="jk-act"><?php if (!$st): ?><button class="btn" type="button" data-new="path" data-v="<?= h($r['path']) ?>" data-ret="paths">Не пускать в панель</button><?php endif; ?><form method="post"><input type="hidden" name="csrf" value="<?= h($token) ?>"><input type="hidden" name="action" value="junk_exclude"><input type="hidden" name="ret" value="junk"><input type="hidden" name="path" value="<?= h($r['path']) ?>"><button class="btn ghost" type="submit" data-tip="Это настоящий адрес — не считать мусором">Исключить</button></form></div></td></tr>
                    <?php endforeach; ?>
                    <?php if (!$jk['paths']): ?><tr class="jk-empty"><td colspan="6">Пока пусто</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="jk-pane" id="p-ips">
            <div class="jk-flt">
                <div class="jk-search"><?= $jk_ico['find'] ?><input type="text" id="jkIpQ" placeholder="IP"></div>
                <select id="jkIpS"><option value="n">По числу запросов</option><option value="t">По последнему</option></select>
                <div class="pgr" id="jkIpPg"></div>
            </div>
            <div class="jk-wrap">
                <table class="jk-tbl tcards">
                    <thead><tr><th style="width:10rem">IP</th><th class="num" style="width:6rem">Запросов</th><th class="num" style="width:5rem">Путей</th><th style="width:8rem">Последний</th><th>Последний путь и User-Agent</th></tr></thead>
                    <tbody id="jkIps">
                    <?php foreach ($jk['ips'] as $r): ?>
                    <tr data-q="<?= h(mb_strtolower($r['ip'])) ?>" data-n="<?= (int) $r['hits'] ?>" data-t="<?= (int) $r['last_ts'] ?>"><td data-label="IP"><code class="ip"><?= h($r['ip']) ?></code></td><td class="num" data-label="Запросов"><?= (int) $r['hits'] ?></td><td class="num" data-label="Путей"><?= (int) $r['paths'] ?></td><td class="ts" data-label="Последний"><?= $jk_ts($r['last_ts']) ?></td><td data-label="Последний путь и User-Agent" data-wide><code>/<?= h($r['last_path']) ?></code><span class="jk-ua" data-tip="<?= h($r['last_ua']) ?>"><?= $r['last_ua'] !== '' ? h($r['last_ua']) : '—' ?></span></td></tr>
                    <?php endforeach; ?>
                    <?php if (!$jk['ips']): ?><tr class="jk-empty"><td colspan="5">Пока пусто</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="jk-pane" id="p-rules">
            <div class="jk-prot">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/></svg>
                <div>
                    <div class="t">Эти адреса правила не трогают никогда</div>
                    <div class="jk-chips"><code>живые ссылки подписки</code><code>assets/</code><code>&lt;префикс&gt;/assets/</code><code>.well-known/</code><code>cdn-cgi/</code><code>_next/</code><code>static/</code><code>locales/</code><code>favicon*</code><code>манифест</code><code>/</code></div>
                </div>
            </div>

            <form method="post" data-autosave>
                <input type="hidden" name="csrf" value="<?= h($token) ?>">
                <input type="hidden" name="action" value="junk_cfg">
                <div class="set-row">
                    <div class="set-info"><div class="set-t">Не трогать живые ссылки</div><div class="set-d">Ссылки, которые прослойка видела за 30 дней, проходят правила по User-Agent.</div></div>
                    <label class="switch"><input type="checkbox" name="protect_live" <?= junk_live_protect() ? 'checked' : '' ?>><span class="sl"></span></label>
                </div>

                <h3 class="jk-h3">Готовые наборы</h3>
                <?php foreach (['paths' => ['Сканерские пути', '.php, .env, .git, wp-*, phpmyadmin, cgi-bin, конфиги и ключи'], 'bots' => ['Боты по User-Agent', 'поисковики, SEO-боты, сканеры портов']] as $sk => [$st_t, $st_d]):
                    $sr = $jk_sets[$sk];
                    $s_on = $sr && (int) $sr['active'] === 1;
                    $s_mode = $sr ? (string) $sr['mode'] : 'dry';
                    $s_resp = $sr ? (string) $sr['resp'] : ($sk === 'bots' ? '403' : 'copy404');
                    $s_hits = $sr ? (int) $sr['hits'] : 0;
                    if ($sk === 'paths') {
                        $chips = array_merge(array_map(static function ($x) { return '*.' . $x; }, junk_set_paths_ext()), array_map(static function ($x) { return '.' . $x; }, junk_set_paths_dot()), array_map(static function ($x) { return $x . (strpos($x, '.') === false ? '/' : ''); }, junk_set_paths_dir()));
                    } else {
                        $chips = junk_set_bots();
                    }
                ?>
                <div class="set-row">
                    <div class="set-info"><div class="set-t"><?= h($st_t) ?></div><div class="set-d"><?= h($st_d) ?><?= $s_hits ? ' · ' . ($s_mode === 'cut' ? 'сработало ' : 'сработало бы ') . number_format($s_hits, 0, '', ' ') : '' ?></div>
                        <button type="button" class="jk-more" data-l="jkl_<?= $sk ?>">Что входит</button>
                        <div class="jk-list" id="jkl_<?= $sk ?>"><div class="jk-chips"><?php foreach ($chips as $c): ?><code><?= h($c) ?></code><?php endforeach; ?></div></div>
                    </div>
                    <div class="jk-ctl">
                        <select name="set_<?= $sk ?>_mode"><option value="dry"<?= $s_mode !== 'cut' ? ' selected' : '' ?>>Только считать</option><option value="cut"<?= $s_mode === 'cut' ? ' selected' : '' ?>>Резать</option></select>
                        <select name="set_<?= $sk ?>_resp"><?php foreach ($jk_resp as $rk => $rl): ?><option value="<?= h($rk) ?>"<?= $s_resp === $rk ? ' selected' : '' ?>><?= h($rl) ?></option><?php endforeach; ?></select>
                        <label class="switch"><input type="checkbox" name="set_<?= $sk ?>_on" <?= $s_on ? 'checked' : '' ?>><span class="sl"></span></label>
                    </div>
                </div>
                <?php endforeach; ?>

            </form>

            <div class="loghead" style="margin-top:1.4rem">
                <h3 class="jk-h3" style="margin:0">Свои правила</h3>
                <div class="loghead-r"><button class="btn" type="button" data-new="path" data-ret="rules">Добавить правило</button></div>
            </div>
            <div class="jk-wrap" style="margin-top:.7rem">
                <table class="jk-tbl tcards">
                    <thead><tr><th>Условие</th><th style="width:10rem">Ответ</th><th style="width:10rem">Режим</th><th style="width:9rem">До</th><th class="num" style="width:7rem">Сработало</th><th style="width:5.5rem"></th></tr></thead>
                    <tbody>
                    <?php foreach ($jk_own as $r): $cut = $r['mode'] === 'cut'; $kn = ['path' => 'Путь', 'ua' => 'User-Agent'][$r['kind']] ?? $r['kind']; ?>
                    <tr><td data-label="Условие" data-wide><span class="tag jk-type"><?= h($kn) ?></span> <code><?= h($r['val']) ?></code></td><td data-label="Ответ"><?= h($jk_resp[$r['resp']] ?? $r['resp']) ?></td><td data-label="Режим"><?= $cut ? '<span class="tag jk-cut">режет</span>' : '<span class="tag jk-dry">только считает</span>' ?></td><td class="ts" data-label="До"><?= (int) $r['until_ts'] ? $jk_ts($r['until_ts']) : 'навсегда' ?></td><td class="num" data-label="Сработало"><?= (int) $r['hits'] ?><?php if ((int) $r['last_ts']): ?><span class="jk-rule">последний <?= $jk_ts($r['last_ts']) ?></span><?php endif; ?></td><td data-acts><div class="jk-act"><form method="post"><input type="hidden" name="csrf" value="<?= h($token) ?>"><input type="hidden" name="action" value="junk_rule_mode"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button class="jk-ib" type="submit" data-tip="<?= $cut ? 'Перевести в «только считать»' : 'Начать резать' ?>"><?= $cut ? $jk_ico['pause'] : $jk_ico['play'] ?></button></form><form method="post" onsubmit="return uiConfirmForm(this,'Удалить правило?','Удалить',true)"><input type="hidden" name="csrf" value="<?= h($token) ?>"><input type="hidden" name="action" value="junk_rule_del"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button class="jk-ib bad" type="submit" data-tip="Удалить правило"><?= $jk_ico['del'] ?></button></form></div></td></tr>
                    <?php endforeach; ?>
                    <?php if (!$jk_own): ?><tr class="jk-empty"><td colspan="6">Своих правил пока нет</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div id="jkDlg" class="modal-overlay">
        <div class="modal" style="max-width:520px">
            <div class="modal-head"><span>Новое правило</span><button type="button" class="modal-x" id="jkX" aria-label="Закрыть">×</button></div>
            <div class="modal-body">
                <form method="post" id="jkForm">
                    <input type="hidden" name="csrf" value="<?= h($token) ?>">
                    <input type="hidden" name="action" value="junk_rule_add">
                    <input type="hidden" name="kind" id="jkKind" value="path">
                    <input type="hidden" name="ttl" id="jkTtl" value="0">
                    <input type="hidden" name="ret" id="jkRet" value="rules">
                    <div class="jk-f">
                        <label>Условие</label>
                        <div class="seg" id="jkType"><button type="button" class="on" data-t="path">Путь</button><button type="button" data-t="ua">User-Agent</button></div>
                        <input type="text" name="val" id="jkVal" placeholder="wp-admin/*" required>
                        <div class="hint" id="jkHint">Точный путь или начало пути со * на конце</div>
                    </div>
                    <div class="jk-f">
                        <label>Ответ</label>
                        <select name="resp"><option value="copy404">Копия 404 — как ответила бы панель</option><option value="e404">Пустой 404</option><option value="403">403</option></select>
                    </div>
                    <div class="jk-f">
                        <label>Режим</label>
                        <div class="jk-mode">
                            <label><input type="radio" name="mode" value="dry" checked><span>Только считать<small>видно, что отрезало бы</small></span></label>
                            <label><input type="radio" name="mode" value="cut"><span>Резать<small>отвечать без панели</small></span></label>
                        </div>
                    </div>
                    <div class="jk-f">
                        <label>Срок</label>
                        <div class="seg" id="jkTtlSeg" style="margin:0"><button type="button" data-v="3600">1 час</button><button type="button" data-v="86400">24 часа</button><button type="button" class="on" data-v="0">Навсегда</button></div>
                    </div>
                    <div class="jk-warn" id="jkIpWarn" style="display:none"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 8v4"/><path d="M12 16h.01"/></svg><span>Живые ссылки пройдут, если включено «Не трогать живые ссылки».</span></div>
                    <div class="jk-foot"><button type="button" class="btn ghost" id="jkCancel">Отмена</button><button type="submit" class="btn">Создать</button></div>
                </form>
            </div>
        </div>
    </div>

    <script>
    (function(){
    var $=function(id){return document.getElementById(id);};
    var p2=function(n){return (n<10?'0':'')+n;};
    var today=new Date();
    document.querySelectorAll('.jk-t[data-ts]').forEach(function(el){
        var d=new Date(parseInt(el.dataset.ts,10)*1000);if(isNaN(d.getTime()))return;
        var hm=p2(d.getHours())+':'+p2(d.getMinutes());
        if(el.dataset.f==='t'){el.textContent=hm+':'+p2(d.getSeconds());return;}
        el.textContent=(d.toDateString()===today.toDateString()?'сегодня ':p2(d.getDate())+'.'+p2(d.getMonth()+1)+' ')+hm;
    });
    document.querySelectorAll('#jkSpark i').forEach(function(el){
        var d=new Date(parseInt(el.dataset.h,10)*1000);
        el.setAttribute('data-tip',p2(d.getHours())+':00 — '+el.dataset.n+' запросов, отрезано '+el.dataset.c);
    });
    document.querySelectorAll('#jkSparkX span[data-h]').forEach(function(el){var d=new Date(parseInt(el.dataset.h,10)*1000);el.textContent=p2(d.getHours())+':00';});

    var lists={};
    function setup(key,bodyId,pgId,qId,kId,sId){
        var body=$(bodyId);if(!body)return;
        var rows=Array.prototype.filter.call(body.children,function(tr){return !tr.classList.contains('jk-empty');});
        if(!rows.length)return;
        var pager=window.LogPager?LogPager({bodyId:bodyId,topId:pgId,storeKey:'pg_junk_'+key}):null;
        function apply(){
            var q=($(qId).value||'').trim().toLowerCase(),k=kId?$(kId).value:'',s=sId?$(sId).value:'';
            var list=rows.filter(function(tr){return (!q||tr.dataset.q.indexOf(q)>-1)&&(!k||tr.dataset.k===k);});
            if(s)list.sort(function(a,b){return (parseInt(b.dataset[s],10)||0)-(parseInt(a.dataset[s],10)||0);});
            rows.forEach(function(tr){if(tr.parentNode)tr.parentNode.removeChild(tr);});
            list.forEach(function(tr){body.appendChild(tr);});
            if(pager)pager.refresh(true);
        }
        [qId,kId,sId].forEach(function(id){if(id&&$(id))$(id).addEventListener(id===qId?'input':'change',apply);});
        lists[key]=apply;
    }
    setup('req','jkReq','jkReqPg','jkReqQ','jkReqK',null);
    setup('paths','jkPaths','jkPathPg','jkPathQ',null,'jkPathS');
    setup('ips','jkIps','jkIpPg','jkIpQ',null,'jkIpS');

    function show(p){
        if(!$('p-'+p))p='req';
        document.querySelectorAll('#jkTabs button').forEach(function(x){x.classList.toggle('on',x.dataset.p===p);});
        document.querySelectorAll('.jk-pane').forEach(function(x){x.classList.toggle('on',x.id==='p-'+p);});
    }
    document.querySelectorAll('#jkTabs button').forEach(function(b){b.addEventListener('click',function(){show(b.dataset.p);try{history.replaceState(null,'','#'+b.dataset.p);}catch(e){}});});
    show((location.hash||'').replace('#',''));
    window.addEventListener('hashchange',function(){show((location.hash||'').replace('#',''));});
    document.querySelectorAll('.jk-more').forEach(function(b){b.addEventListener('click',function(){var l=$(b.dataset.l);l.classList.toggle('open');b.textContent=l.classList.contains('open')?'Скрыть':'Что входит';});});

    var hints={path:['Точный путь или начало пути со * на конце','wp-admin/*'],ua:['Часть строки User-Agent, без учёта регистра','python-requests']};
    function setType(t){
        if(!hints[t])t='path';
        $('jkKind').value=t;
        document.querySelectorAll('#jkType button').forEach(function(x){x.classList.toggle('on',x.dataset.t===t);});
        $('jkHint').textContent=hints[t][0];$('jkVal').placeholder=hints[t][1];
        $('jkIpWarn').style.display=t==='path'?'none':'flex';
    }
    document.querySelectorAll('#jkType button').forEach(function(b){b.addEventListener('click',function(){setType(b.dataset.t);});});
    document.querySelectorAll('#jkTtlSeg button').forEach(function(b){b.addEventListener('click',function(){
        document.querySelectorAll('#jkTtlSeg button').forEach(function(x){x.classList.toggle('on',x===b);});$('jkTtl').value=b.dataset.v;
    });});
    var dlg=$('jkDlg');
    document.addEventListener('click',function(e){
        var b=e.target.closest('[data-new]');if(!b)return;
        setType(b.dataset.new);
        $('jkVal').value=b.dataset.v||'';
        var on=document.querySelector('#jkTabs button.on');
        $('jkRet').value=b.dataset.ret||(on?on.dataset.p:'rules');
        dlg.classList.add('open');
        setTimeout(function(){$('jkVal').focus();},50);
    });
    function close(){dlg.classList.remove('open');}
    $('jkX').addEventListener('click',close);$('jkCancel').addEventListener('click',close);
    dlg.addEventListener('click',function(e){if(e.target===dlg)close();});
    document.addEventListener('keydown',function(e){if(e.key==='Escape'&&dlg.classList.contains('open'))close();});
    })();
    </script>
