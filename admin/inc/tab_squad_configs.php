<?php $sq_psize = pager_cookie_size('sqcfg_size'); ?>
    <style>
        .mc-grid{display:grid;grid-template-columns:1fr 1fr;gap:1rem;align-items:start}
        @media(max-width:720px){.mc-grid{grid-template-columns:1fr}}
        .sqcfg-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:.7rem 1rem;align-items:end}
        .sqcfg-grid select,.sqcfg-grid input{width:100%;box-sizing:border-box}
        .sqcfg-grid label{display:block;margin-bottom:.3rem;font-weight:600;font-size:.82rem}
        .sqcfg-sel{appearance:none;-webkit-appearance:none;-moz-appearance:none;padding-right:2.2rem;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23888' stroke-width='2.4' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right .75rem center;background-size:.95rem}
        .sqcfg-hint{margin-top:1rem;border:1px solid var(--line);border-radius:10px;padding:.8rem 1rem;font-size:.86rem;line-height:1.5;background:var(--bg2)}
        .sqcfg-hint.ok{border-color:var(--accent)}
        .sqcfg-hint.bad{border-color:var(--c-warn-fg)}
        .sqcfg-hint b{color:var(--accent-text)}
        .sqcfg-hint ul{margin:.4rem 0 0;padding-left:1.1rem}
        .sqcfg-hint .warn-line{color:var(--c-warn-fg)}
        .sqcfg-hint .note-line{color:var(--muted)}
        #sqEditModal label:not(.sq-item){display:block;margin-bottom:.3rem;font-weight:600;font-size:.82rem}
        .card label{display:block;margin-bottom:.35rem;font-weight:600;font-size:.85rem}
        .sq-tag{display:inline-block;background:var(--bg2);border:1px solid var(--line);border-radius:6px;padding:.08rem .45rem;font-size:.74rem;margin:.1rem .25rem .1rem 0;white-space:nowrap}
        .sq-manual{padding-top:.4rem;padding-bottom:.4rem}
        .sq-manual .sq-mtxt{display:flex;flex-direction:column;justify-content:center;gap:.05rem;flex:1;min-width:0}
        .sq-manual .sq-n{flex:none;line-height:1.15;font-size:.86rem}
    </style>
    <section class="<?= coll_cls('sqcfg_about') ?>" data-coll="sqcfg_about">
        <button type="button" class="coll-head" onclick="collToggle(this)"><span>Что это и как настраивать</span>
            <span class="coll-hr"><svg width="30" height="30" class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg></span>
        </button>
        <div class="coll-body">
            <p class="muted" style="margin-top:0">Прослойка дописывает в подписку дополнительные конфиги, привязанные к внутреннему скваду Remnawave: пользователь сквада получает свои узлы плюс эти. Конфиги отдаются <b>только пока подписка активна</b> — при истечении или блокировке они исчезают из подписки (остаются заглушки).</p>
            <ul class="muted" style="margin:.2rem 0 .2rem;padding-left:1.1rem;line-height:1.7">
                <li><b>Простые (VLESS)</b> — <b>эта вкладка</b>. Конфиг дописывается всем подписчикам сквада одинаково. Без раздельных настроек: вставил <code>vless://</code>, выбрал сквады — готово. Можно и закрепить конфиг за конкретным пользователем (ниже).</li>
                <li><b>WG / AmneziaWG</b> — вкладка <b>«WG / AWG»</b>. Там ограничение «один ключ = одно одновременное устройство», поэтому пул, выдача на пользователя/устройство, пакетная загрузка.</li>
            </ul>
            <p class="muted" style="margin:.2rem 0">Транспорты ядра поддерживают по-разному, поэтому конфиг может уйти не во все форматы сразу. В base64-подписку попадает любая ссылка. В YAML/JSON собираются: <code>tcp</code>, <code>ws</code>, <code>grpc</code>, <code>httpupgrade</code> — везде; <code>xhttp</code> — Xray и Mihomo; HTTP/2 (<code>type=http</code>) — Mihomo и sing-box; <code>tcp</code> с <code>headerType=http</code> — Xray и Mihomo; <code>kcp</code> — только Xray; <code>quic</code> — только sing-box. После вставки ссылки прослойка сама пишет, куда конфиг уйдёт и почему.</p>
            <p class="muted" style="margin-bottom:0">Порядок: сначала во вкладке «Подключение» укажи URL панели и токен — без этого сквады не подтянутся; затем выбери сквады и добавь конфиг.</p>
        </div>
    </section>

    <div class="card">
        <form method="post" data-autosave>
            <input type="hidden" name="csrf" value="<?= h($token) ?>">
            <input type="hidden" name="action" value="save_sqcfg_settings">
            <div class="set-row">
                <div class="set-info"><div class="set-t">Инжект для xray-json</div><div class="set-d">Вливать WG/VLESS-конфиги сквада в подписки формата xray-json (напр. Happ). По умолчанию выкл. base64 / Clash / sing-box работают всегда при наличии конфигов. AmneziaWG в xray-json не вливается — ядро не умеет обфускацию. Если панель отдаёт xray-json списком профилей, на каждый конфиг копируется её шаблон: из копии убираются балансировщики и observatory, а правила с balancerTag переводятся на прямой выход — поэтому по умолчанию выключено.</div></div>
                <label class="switch"><input type="checkbox" name="squad_xray_json_inject" <?= squadconf_xray_json_enabled() ? 'checked' : '' ?>><span class="sl"></span></label>
            </div>
        </form>
    </div>

    <section class="<?= coll_cls('sqcfg_add') ?>" data-coll="sqcfg_add">
        <button type="button" class="coll-head" onclick="collToggle(this)"><span>Добавить простой конфиг (VLESS)</span>
            <span class="coll-hr"><svg width="30" height="30" class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg></span>
        </button>
        <div class="coll-body">
            <?php if ($sqcfg_squads_err !== ''): ?>
                <div class="warn">Список сквадов недоступен: <?= h($sqcfg_squads_err) ?>. Проверьте URL панели и токен во вкладке «Подключение».</div>
            <?php elseif (!$sqcfg_squads): ?>
                <div class="warn">Внутренние сквады не получены. Настройте подключение к панели.</div>
            <?php endif; ?>

            <form method="post" autocomplete="off">
                <input type="hidden" name="csrf" value="<?= h($token) ?>">
                <input type="hidden" name="action" value="save_squad_config">
                <input type="hidden" name="kind" value="simple">
                <input type="hidden" name="ret" value="squad_configs">

                <label>Куда <span class="muted" style="font-weight:400">— ручная привязка (по умолчанию) или сквады</span></label>
                <div class="sq-grid">
                    <label class="sq-item sq-manual"><input type="checkbox" name="squads[]" value="__manual__" checked><span class="sq-mtxt"><span class="sq-n">🔧 Ручная привязка</span><span class="muted" style="font-size:.72rem">в обход сквадов</span></span></label>
                    <?php foreach ($sqcfg_squads as $s): ?>
                        <label class="sq-item"><input type="checkbox" name="squads[]" value="<?= h($s['uuid']) ?>"><span class="sq-n"><?= h($s['name']) ?></span><span class="muted" style="font-size:.78rem"><?= (int) $s['members'] ?></span></label>
                    <?php endforeach; ?>
                </div>

                <div class="mc-grid" style="margin-top:1rem">
                    <div>
                        <label for="sqcfg_name">Метка</label>
                        <input type="text" id="sqcfg_name" name="name" class="sqcfg-flag" placeholder="напр.: Нидерланды · VLESS" maxlength="191" required style="width:100%;box-sizing:border-box">
                        <div class="muted" style="font-size:.8rem;margin-top:.5rem;line-height:1.5">Введёшь страну — флаг подставится сам (Нидерланды → 🇳🇱).</div>
                    </div>
                    <div>
                        <label for="sqcfg_raw">Конфиг</label>
                        <textarea id="sqcfg_raw" name="raw" rows="5" spellcheck="false" placeholder="vless://…" style="width:100%;font-family:monospace;font-size:.82rem;box-sizing:border-box"></textarea>
                    </div>
                </div>
                <div id="sqcfg_hint" class="sqcfg-hint" style="display:none"></div>
                <div style="margin-top:1rem;display:flex;align-items:center;gap:.75rem;flex-wrap:wrap">
                    <button type="submit" class="btn">Добавить конфиг</button>
                    <span class="muted" style="font-size:.8rem">Секреты хранятся в БД и отдаются только при активной подписке.</span>
                </div>
            </form>
        </div>
    </section>

    <section class="<?= coll_cls('sqcfg_manual') ?>" data-coll="sqcfg_manual">
        <button type="button" class="coll-head" onclick="collToggle(this)"><span>Закрепить конфиг за пользователем</span>
            <span class="coll-hr"><svg width="30" height="30" class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg></span>
        </button>
        <div class="coll-body">
            <p class="muted" style="margin-top:0">Выбери пользователя и конфиг из группы <b>«Ручная привязка»</b> (конфиги, добавленные выше с галочкой «Ручная привязка» — они идут в обход сквадов, только привязанным юзерам). Привязка <b>одна на пользователя</b>: новая заменяет прежнюю. Действует, пока подписка активна.</p>
            <form method="post" autocomplete="off">
                <input type="hidden" name="csrf" value="<?= h($token) ?>">
                <input type="hidden" name="action" value="pool_manual_add">
                <input type="hidden" name="ret" value="squad_configs">
                <input type="hidden" name="short_uuid" id="wgm_short">
                <div class="sqcfg-grid">
                    <div>
                        <label>Пользователь (shortUuid или имя)</label>
                        <div style="display:flex;gap:.4rem"><input type="text" id="wgm_q" placeholder="shortUuid / username" style="flex:1;box-sizing:border-box"><button type="button" class="sqcfg-btn" id="wgm_find">Найти</button></div>
                    </div>
                    <div>
                        <label>Конфиг</label>
                        <select name="config_id" id="wgm_cfg" class="sqcfg-sel">
                            <option value="">—</option>
                            <?php foreach ($sqcfg_simple as $c): if ((int) $c['enabled'] !== 1 || !in_array('__manual__', squadconf_squads_of($c), true)) continue; ?><option value="<?= (int) $c['id'] ?>"><?= h(($c['name'] !== null && $c['name'] !== '') ? $c['name'] : ('#' . $c['id'])) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div id="wgm_info" class="muted" style="font-size:.82rem;margin:.6rem 0"></div>
                <button type="submit" class="btn" id="wgm_submit" disabled>Закрепить</button>
            </form>
            <?php
                $simple_ids = [];
                foreach ($sqcfg_simple as $c) $simple_ids[(int) $c['id']] = (string) ($c['name'] ?? '');
                $man = array_values(array_filter($sqcfg_leases, fn($l) => (int) $l['manual'] === 1 && isset($simple_ids[(int) $l['config_id']])));
            ?>
            <h2 style="font-size:.95rem;margin:1.3rem 0 .5rem">Закреплённые конфиги (<?= count($man) ?>)</h2>
            <?php if (!$man): ?><p class="muted">Пока пусто.</p><?php else: ?>
            <table class="logtbl">
                <thead><tr><th>Конфиг</th><th>Пользователь</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($man as $l): $lcid = (int) $l['config_id']; ?>
                    <tr>
                        <td><?= $simple_ids[$lcid] !== '' ? h($simple_ids[$lcid]) : ('#' . $lcid) ?></td>
                        <td style="font-family:monospace;font-size:.78rem"><?= h((string) $l['short_uuid']) ?></td>
                        <td style="text-align:right">
                            <form method="post" style="margin:0" onsubmit="return uiConfirmForm(this,'Снять привязку?')">
                                <input type="hidden" name="csrf" value="<?= h($token) ?>">
                                <input type="hidden" name="action" value="pool_manual_del">
                                <input type="hidden" name="ret" value="squad_configs">
                                <input type="hidden" name="id" value="<?= (int) $l['id'] ?>">
                                <button type="submit" class="danger">🗑</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </section>

    <div class="card">
        <div class="loghead">
            <h2>Простые конфиги (<?= count($sqcfg_simple) ?>)</h2>
            <?php if ($sqcfg_simple): ?>
            <div class="loghead-r">
                <label class="pgr-size" style="display:inline-flex;align-items:center;gap:.4rem;margin:0;font-weight:400">На странице:
                    <select id="sqcfgSize" onchange="SQCFGP.setSize(parseInt(this.value,10))">
                        <option value="25"<?= $sq_psize==25?' selected':'' ?>>25</option>
                        <option value="50"<?= $sq_psize==50?' selected':'' ?>>50</option>
                        <option value="100"<?= $sq_psize==100?' selected':'' ?>>100</option>
                        <option value="200"<?= $sq_psize==200?' selected':'' ?>>200</option>
                    </select>
                </label>
            </div>
            <?php endif; ?>
        </div>
        <?php if (!$sqcfg_simple): ?>
            <p class="muted">Пока пусто. Простые конфиги (VLESS) добавляются выше.</p>
        <?php else: $sqcfg_edit = []; ?>
        <style>#sqcfgTbl.pgr-pre tbody tr:nth-child(n+<?= $sq_psize + 1 ?>){display:none}#sqcfgPager{min-height:2.1rem}</style>
        <table class="logtbl pgr-pre" id="sqcfgTbl">
            <thead><tr><th>Сквады</th><th>Тип</th><th>Метка</th><th>Статус</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($sqcfg_simple as $c):
                $pn = json_decode((string) ($c['parsed'] ?? ''), true);
                $sumr = is_array($pn) ? squadconf_summary($pn) : ($c['type'] ?? '');
                $csquads = squadconf_squads_of($c);
                $on = (int) $c['enabled'] === 1;
                $sqcfg_edit[(int) $c['id']] = ['squads' => array_values($csquads), 'name' => (string) ($c['name'] ?? ''), 'raw' => (string) $c['raw']];
            ?>
            <tr>
                <td><?php foreach ($csquads as $sq): ?><span class="sq-tag"><?= h($sqcfg_names[$sq] ?? $sq) ?></span><?php endforeach; ?></td>
                <td><span class="tag normal"><?= h($sumr) ?></span></td>
                <td><?= $c['name'] !== null && $c['name'] !== '' ? h($c['name']) : '<span class="muted">—</span>' ?></td>
                <td>
                    <form method="post" style="margin:0;display:inline">
                        <input type="hidden" name="csrf" value="<?= h($token) ?>">
                        <input type="hidden" name="action" value="toggle_squad_config">
                        <input type="hidden" name="ret" value="squad_configs">
                        <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                        <input type="hidden" name="enabled" value="<?= $on ? '0' : '1' ?>">
                        <button type="submit" class="sqcfg-btn <?= $on ? '' : 'off' ?>"><?= $on ? '✅ Включён' : '⛔ Выключен' ?></button>
                    </form>
                </td>
                <td style="text-align:right;white-space:nowrap">
                    <button type="button" class="sqcfg-btn sqcfg-edit" data-id="<?= (int) $c['id'] ?>">✎ Изменить</button>
                    <form method="post" style="margin:0;display:inline" onsubmit="return uiConfirmForm(this,'Удалить этот конфиг?')">
                        <input type="hidden" name="csrf" value="<?= h($token) ?>">
                        <input type="hidden" name="action" value="del_squad_config">
                        <input type="hidden" name="ret" value="squad_configs">
                        <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                        <button type="submit" class="danger">🗑 Удалить</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <div id="sqcfgPager" style="margin-top:.85rem;display:flex;justify-content:flex-end"></div>
        <?php endif; ?>
    </div>

    <div id="sqEditModal" class="modal-overlay">
        <div class="modal">
            <div class="modal-head">
                <div>Редактировать конфиг</div>
                <button type="button" class="modal-x" onclick="sqEditClose()">×</button>
            </div>
            <div class="modal-body">
                <form method="post" autocomplete="off">
                    <input type="hidden" name="csrf" value="<?= h($token) ?>">
                    <input type="hidden" name="action" value="edit_squad_config">
                    <input type="hidden" name="ret" value="squad_configs">
                    <input type="hidden" name="id" id="sqedit_id" value="">
                    <div style="margin-bottom:.85rem">
                        <label>Сквады</label>
                        <div class="sq-grid" id="sqedit_chips">
                            <label class="sq-item sq-manual"><input type="checkbox" name="squads[]" value="__manual__"><span class="sq-mtxt"><span class="sq-n">🔧 Ручная привязка</span><span class="muted" style="font-size:.72rem">в обход сквадов</span></span></label>
                            <?php foreach ($sqcfg_squads as $s): ?>
                                <label class="sq-item"><input type="checkbox" name="squads[]" value="<?= h($s['uuid']) ?>"><span class="sq-n"><?= h($s['name']) ?></span><span class="muted" style="font-size:.78rem"><?= (int) $s['members'] ?></span></label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div style="margin-bottom:.85rem">
                        <label>Метка</label>
                        <input type="text" name="name" id="sqedit_name" class="sqcfg-flag" maxlength="191" required style="width:100%;box-sizing:border-box">
                    </div>
                    <div style="margin-bottom:.85rem">
                        <label>Конфиг</label>
                        <textarea name="raw" id="sqedit_raw" rows="9" spellcheck="false" required style="width:100%;font-family:monospace;font-size:.82rem;box-sizing:border-box"></textarea>
                    </div>
                    <div style="display:flex;gap:.6rem">
                        <button type="submit" class="btn">Сохранить изменения</button>
                        <button type="button" class="sqcfg-btn" onclick="sqEditClose()">Отмена</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php include __DIR__ . '/_sqcfg_js.php'; ?>
    <script>
    window.SQCFG = <?= json_encode($sqcfg_edit ?? [], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    sqcfgInitEdit();
    sqcfgInitPager('sqcfgTbl', 'sqcfgPager', 'sqcfgSize', 'sqcfg_size');
    sqcfgInitManual(<?= json_encode($sqcfg_names, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);
    </script>
