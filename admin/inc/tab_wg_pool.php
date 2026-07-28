<?php
$wg_psize = pager_cookie_size('wgpool_size');
$wg_uc = wglease_user_cache();
$wgp_hint = 'Добавлено — реально зарегистрированные устройства из базы hwid. Красным — пул меньше факта.';
$wgp_ts = (int) ($sqcfg_sizing['ts'] ?? 0);
$wgp_msg0 = '';
if (($sqcfg_sizing['rows'] ?? []) && $wgp_ts > 0) {
    $d = max(0, time() - $wgp_ts);
    $ago = $d < 60 ? 'только что' : ($d < 3600 ? intdiv($d, 60) . ' мин назад' : ($d < 86400 ? intdiv($d, 3600) . ' ч назад' : intdiv($d, 86400) . ' дн назад'));
    $wgp_msg0 = 'Последний расчёт: ' . $ago . '. ' . $wgp_hint;
}
?>
    <style>
        .wg-up-grid{display:grid;grid-template-columns:1fr 1fr;gap:1rem;align-items:start}
        .mc-grid{display:grid;grid-template-columns:1fr 1fr;gap:1rem;align-items:start}
        @media(max-width:720px){.wg-up-grid,.mc-grid{grid-template-columns:1fr}}
        .file-btn{display:inline-flex;align-items:center;gap:.5rem;border:1px solid var(--line);background:var(--bg2);color:var(--text);border-radius:9px;padding:.6rem .95rem;font-size:.86rem;font-weight:600;cursor:pointer;transition:border-color .15s,background .15s}
        .file-btn:hover{border-color:var(--accent);background:var(--accent-light)}
        .file-btn svg{color:var(--accent-text)}
        .sqcfg-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:.7rem 1rem;align-items:end}
        .sqcfg-grid select,.sqcfg-grid input{width:100%;box-sizing:border-box}
        .sqcfg-grid label{display:block;margin-bottom:.3rem;font-weight:600;font-size:.82rem}
        .sqcfg-sel{appearance:none;-webkit-appearance:none;-moz-appearance:none;padding-right:2.2rem;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23888' stroke-width='2.4' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right .75rem center;background-size:.95rem}
        #sqEditModal label:not(.sq-item){display:block;margin-bottom:.3rem;font-weight:600;font-size:.82rem}
        .card label{display:block;margin-bottom:.35rem;font-weight:600;font-size:.85rem}
        .sq-tag{display:inline-block;background:var(--bg2);border:1px solid var(--line);border-radius:6px;padding:.08rem .45rem;font-size:.74rem;margin:.1rem .25rem .1rem 0;white-space:nowrap}
        .wgpool-tbl td,.wgpool-tbl th{vertical-align:middle}
        .wgpool-tbl .sqcfg-sel{padding:.3rem 2rem .3rem .6rem;font-size:.82rem}
        .wgp-warn{color:var(--c-warn-fg);font-weight:700}
        .sq-manual{padding-top:.4rem;padding-bottom:.4rem}
        .sq-manual .sq-mtxt{display:flex;flex-direction:column;justify-content:center;gap:.05rem;flex:1;min-width:0}
        .sq-manual .sq-n{flex:none;line-height:1.15;font-size:.86rem}
        .wg-bulkbar{display:flex;align-items:center;gap:.75rem;margin:0 0 .8rem;flex-wrap:wrap}
        .wg-leasebar{display:flex;align-items:center;gap:.75rem;margin:0 0 .7rem;flex-wrap:wrap}
        .wg-editbar{margin:0 0 .8rem}
        .wg-editbar .we-lbl{font-size:.82rem;margin-bottom:.4rem}
        .wg-editbar .we-grid{display:grid;grid-template-columns:minmax(150px,240px) 1fr;gap:.6rem;align-items:center}
        .wg-editbar .we-grid select,.wg-editbar .we-grid input{width:100%;box-sizing:border-box;margin:0}
        .wg-editbar .we-btns{display:flex;justify-content:flex-end;gap:.5rem;margin-top:.5rem}
        .wg-dupe-warn{color:var(--red);font-weight:600;font-size:.82rem}
        #wgTbl td:first-child,#wgTbl th:first-child{text-align:center}
        .osico{display:inline-block;width:15px;height:15px;vertical-align:-2px;background:var(--text-strong);-webkit-mask-repeat:no-repeat;mask-repeat:no-repeat;-webkit-mask-position:center;mask-position:center;-webkit-mask-size:contain;mask-size:contain}
        .wg-issued-dev{display:inline-flex;align-items:center;gap:3px;opacity:.85}
        .wg-issued-name{font-weight:600}
        .wg-cli{display:inline-block;background:var(--bg2);border:1px solid var(--line);border-radius:6px;padding:.02rem .4rem;font-size:.72rem;color:var(--muted);vertical-align:1px}
        .wg-tip{position:relative;cursor:help}
        .wg-tip:hover::after{content:attr(data-tip);position:absolute;left:0;bottom:145%;white-space:pre-line;text-align:left;min-width:210px;max-width:340px;background:var(--card);color:var(--text);border:1px solid var(--line);border-radius:9px;padding:.55rem .75rem;font-size:.76rem;font-weight:500;line-height:1.55;box-shadow:var(--shadow);z-index:30}
        #wgTbl td:nth-child(6),#wgTbl th:nth-child(6){min-width:210px}
        .wg-issued{display:inline-flex;flex-wrap:wrap;align-items:baseline;gap:.1rem .3rem;max-width:100%;vertical-align:bottom}
        .wg-issued>.wg-tip{display:inline-flex;flex-wrap:wrap;align-items:baseline;gap:.1rem .3rem;min-width:0;max-width:100%}
        .wg-issued-name{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;min-width:0}
    </style>
    <section class="<?= coll_cls('wgpool_help') ?>" data-coll="wgpool_help">
        <button type="button" class="coll-head" onclick="collToggle(this)"><span>Как работает пул и почему так</span>
            <span class="coll-hr"><svg class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg></span>
        </button>
        <div class="coll-body">
            <p class="muted" style="margin-top:0">WireGuard/AmneziaWG используют публичный ключ как идентификатор пира: у пира один текущий endpoint, и при одновременной работе двух устройств с <b>одним</b> ключом endpoint перетирается на «последнего» — соединения флапают. Поэтому одно одновременное устройство = один ключ = один peer на сервере.</p>
            <p class="muted">Прослойка ключи не генерит (их делают в Amnezia / WG-панели / CLI), а <b>раздаёт</b> готовые конфиги из пула, закрепляя за подписчиком или устройством отдельный, никем больше не используемый <code>.conf</code>. Закрепление «липкое»: после импорта клиент держит ключ, пока сам не перечитает подписку.</p>
            <ul class="muted" style="margin:.2rem 0 0;padding-left:1.1rem;line-height:1.65">
                <li><b>Общий</b> — конфиги сквада дописываются всем подписчикам. Когда уникальность ключа не нужна.</li>
                <li><b>На пользователя</b> — из пула выдаётся один WG/AWG-конфиг на подписчика; hwid не нужен.</li>
                <li><b>На устройство</b> — один конфиг на пару пользователь+устройство (hwid). Клиент не прислал hwid → конфиг не подмешиваем (иначе флап).</li>
            </ul>
            <p class="muted" style="margin-bottom:0">VLESS-конфиги сквада в любом режиме отдаются всем — управляй ими во вкладке «Доп. конфиги». Конфигов в пуле меньше, чем нужно устройств → части подписчиков WG не достанется (без флапа). Смотри расчёт ниже.</p>
        </div>
    </section>

    <section class="<?= coll_cls('wgpool_upload') ?>" data-coll="wgpool_upload">
        <button type="button" class="coll-head" onclick="collToggle(this)"><span>Загрузка WG / AWG конфигов</span>
            <span class="coll-hr"><svg class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg></span>
        </button>
        <div class="coll-body">
            <?php if ($sqcfg_squads_err !== ''): ?>
                <div class="warn">Список сквадов недоступен: <?= h($sqcfg_squads_err) ?>. Проверьте URL панели и токен во вкладке «Подключение».</div>
            <?php elseif (!$sqcfg_squads): ?>
                <div class="warn">Внутренние сквады не получены. Настройте подключение к панели.</div>
            <?php endif; ?>
            <p class="muted" style="margin-top:0">Пакетно: выбери <code>.conf</code>-файлы (метка из имени файла) и/или вставь несколько конфигов подряд — режутся по секции <code>[Interface]</code>. Тип определяется автоматически; <b>битые и не-WG/AWG молча пропускаются</b> — после загрузки покажу, сколько добавлено и сколько пропущено. <b>До 200 файлов за раз</b> — они читаются прямо в браузере, серверный лимит на число файлов не мешает.</p>
            <form method="post" enctype="multipart/form-data" autocomplete="off" id="wgUpForm">
                <input type="hidden" name="csrf" value="<?= h($token) ?>">
                <input type="hidden" name="action" value="batch_wg_config">
                <input type="hidden" name="files_json" id="wgFilesJson">
                <label>Куда <span class="muted" style="font-weight:400">— сквады (пул) или ручная привязка</span></label>
                <div class="sq-grid">
                    <label class="sq-item sq-manual"><input type="checkbox" name="squads[]" value="__manual__" checked><span class="sq-mtxt"><span class="sq-n">🔧 Ручная привязка</span><span class="muted" style="font-size:.72rem">в обход сквадов</span></span></label>
                    <?php foreach ($sqcfg_squads as $s): ?>
                        <label class="sq-item"><input type="checkbox" name="squads[]" value="<?= h($s['uuid']) ?>"><span class="sq-n"><?= h($s['name']) ?></span><span class="muted" style="font-size:.78rem"><?= (int) $s['members'] ?></span></label>
                    <?php endforeach; ?>
                </div>

                <div class="wg-up-grid" style="margin-top:1rem">
                    <div>
                        <label>Файлы .conf <span class="muted" style="font-weight:400">— можно несколько</span></label>
                        <label class="file-btn">
                            <input type="file" name="conf_files[]" id="wgFiles" accept=".conf,.txt" multiple hidden>
                            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 9 12 4 17 9"/><line x1="12" y1="4" x2="12" y2="16"/></svg>
                            <span>Выбрать .conf файлы</span>
                        </label>
                        <div id="wgFilesInfo" class="muted" style="font-size:.8rem;margin-top:.5rem">Файлы не выбраны</div>
                    </div>
                    <div>
                        <label>…и/или вставка текстом</label>
                        <textarea name="raw_batch" rows="7" spellcheck="false" placeholder="[Interface]&#10;PrivateKey = …&#10;[Peer]&#10;…&#10;&#10;[Interface]&#10;… следующий конфиг …" style="width:100%;font-family:monospace;font-size:.82rem;box-sizing:border-box"></textarea>
                    </div>
                </div>
                <div class="mc-grid" style="margin-top:1rem;align-items:end">
                    <div>
                        <label>Префикс метки <span class="muted" style="font-weight:400">— необязательно</span></label>
                        <input type="text" name="label_prefix" class="sqcfg-flag" maxlength="120" placeholder="напр.: Нидерланды" style="width:100%;box-sizing:border-box">
                    </div>
                    <div style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap">
                        <button type="submit" class="btn">Загрузить</button>
                        <span class="muted" style="font-size:.8rem">Метки потом можно переименовать в списке.</span>
                    </div>
                </div>
            </form>
        </div>
    </section>

    <section class="<?= coll_cls('wgpool_modes') ?>" data-coll="wgpool_modes">
        <button type="button" class="coll-head" onclick="collToggle(this)"><span>Режим пула по сквадам</span>
            <span class="coll-hr"><svg class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg></span>
        </button>
        <div class="coll-body">
            <p class="muted" style="margin-top:0">Как раздаются WG/AWG-конфиги каждого сквада. «Потребность» считается по панели: фактические устройства из базы hwid.</p>
            <?php $wgpT0 = $sqcfg_sizing['totals'] ?? ['records' => 0, 'unique' => 0]; ?>
            <div id="wgpTotals" class="muted" style="font-size:.82rem;margin:.2rem 0 .8rem">Всего регистраций устройств: <b id="wgpTotRec"><?= (int) $wgpT0['records'] ?></b> · уникальных hwid: <b id="wgpTotUniq"><?= (int) $wgpT0['unique'] ?></b></div>
            <?php if (!$sqcfg_squads): ?>
                <div class="warn">Сквады не получены — настройте подключение к панели.</div>
            <?php else: ?>
            <form method="post" data-autosave>
                <input type="hidden" name="csrf" value="<?= h($token) ?>">
                <input type="hidden" name="action" value="save_pool_modes">
                <table class="logtbl wgpool-tbl">
                    <thead><tr><th>Сквад</th><th>Режим</th><th>В пуле (своб/всего)</th><th>Учёток: актив. / всего</th><th>Добавлено (факт)</th></tr></thead>
                    <tbody>
                    <?php foreach ($sqcfg_squads as $s): $pu = $s['uuid']; $pm = $sqcfg_modes[$pu] ?? 'shared'; ?>
                        <tr>
                            <td><?= h($s['name']) ?></td>
                            <td>
                                <select name="pool_mode[<?= h($pu) ?>]" class="sqcfg-sel">
                                    <option value="shared"<?= $pm === 'shared' ? ' selected' : '' ?>>Общий</option>
                                    <option value="users"<?= $pm === 'users' ? ' selected' : '' ?>>На пользователя</option>
                                    <option value="devices"<?= $pm === 'devices' ? ' selected' : '' ?>>На устройство</option>
                                </select>
                            </td>
                            <td><b><?= (int) ($sqcfg_free[$pu] ?? 0) ?></b> / <?= (int) ($sqcfg_stock[$pu] ?? 0) ?></td>
                            <?php $sz = $sqcfg_sizing['rows'][$pu] ?? null; $szHas = !empty($sqcfg_sizing['rows']); $uTxt = $sz ? ((int) ($sz['active'] ?? 0) . ' / ' . (int) ($sz['users'] ?? 0)) : ($szHas ? '0' : '—'); $dVal = $sz ? (int) ($sz['devices'] ?? 0) : ($szHas ? 0 : null); $dWarn = ($dVal !== null && (int) ($sqcfg_free[$pu] ?? 0) < $dVal) ? ' wgp-warn' : ''; ?>
                            <td class="wgp-u" data-su="<?= h($pu) ?>"><?= h($uTxt) ?></td>
                            <td class="wgp-d<?= $dWarn ?>" data-su="<?= h($pu) ?>"><?= $dVal === null ? '—' : (int) $dVal ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <div style="display:flex;gap:.75rem;align-items:center;flex-wrap:wrap;margin-top:1rem">
                    <button type="submit" class="btn">Сохранить режимы</button>
                    <button type="button" class="sqcfg-btn" id="wgpCalc">Рассчитать потребность</button>
                    <label class="muted" style="display:flex;align-items:center;gap:.4rem;margin:0;font-weight:400">авто-возврат слота через
                        <input type="number" name="wgpool_reclaim_days" value="<?= (int) $sqcfg_reclaim_days ?>" min="1" max="365" style="width:5rem;box-sizing:border-box"> дн. неактивности</label>
                </div>
                <div id="wgpCalcMsg" class="muted" style="font-size:.8rem;margin-top:.5rem"><?= h($wgp_msg0) ?></div>
                <div class="muted" style="font-size:.78rem;line-height:1.6;margin-top:.7rem">
                    <b>В пуле</b> — свободных / всего WG/AWG-конфигов (слотов); выданные устройствам вычитаются из «свободных». <b>Учёток</b> — пользователей сквада: активных / всего. <b>Добавлено (факт)</b> — реально зарегистрировано устройств юзерами сквада, из базы hwid. Красным, если конфигов меньше факта.
                </div>
            </form>
            <?php endif; ?>
        </div>
    </section>

    <section class="<?= coll_cls('wgpool_uarules') ?>" data-coll="wgpool_uarules">
        <button type="button" class="coll-head" onclick="collToggle(this)"><span>Правила отдачи по UA</span>
            <span class="coll-hr"><svg class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg></span>
        </button>
        <div class="coll-body">
            <p class="muted" style="margin-top:0">Клиент определяется по подстроке в его User-Agent. Если UA подходит под правило, отмеченный тип конфигов ему <b>не отдаётся и не занимает слот пула</b>. AmneziaWG умеют только клиенты на ядре <b>mihomo (Clash)</b> и нативные wg://-клиенты (Throne); на ядрах <b>xray</b> и <b>sing-box</b> обфускации нет — таким идёт обычный WireGuard. Галочки можно менять, правила — дописывать своими UA.</p>
            <form method="post" autocomplete="off">
                <input type="hidden" name="csrf" value="<?= h($token) ?>">
                <input type="hidden" name="action" value="save_ua_rules">
                <div class="ua-rules-wrap">
                <table class="logtbl ua-rules-tbl" id="uaRulesTbl">
                    <thead><tr><th>Клиент</th><th>UA содержит</th><th>Ядро</th><th class="ua-c">Без AWG</th><th class="ua-c">Без WG</th><th class="ua-c"></th></tr></thead>
                    <tbody>
                    <?php $uar = squadconf_ua_rules(); $ri = 0; foreach ($uar as $r): ?>
                        <tr>
                            <td><input type="text" name="rule_label[<?= $ri ?>]" value="<?= h($r['label']) ?>" maxlength="60" placeholder="Название"></td>
                            <td><input type="text" name="rule_ua[<?= $ri ?>]" value="<?= h($r['ua']) ?>" maxlength="60" placeholder="напр. happ" class="ua-mono"></td>
                            <td><input type="text" name="rule_core[<?= $ri ?>]" value="<?= h($r['core']) ?>" maxlength="24" placeholder="—" class="ua-core"></td>
                            <td class="ua-c"><input type="checkbox" class="ua-ck" name="rule_no_awg[<?= $ri ?>]" value="1"<?= !empty($r['no_awg']) ? ' checked' : '' ?>></td>
                            <td class="ua-c"><input type="checkbox" class="ua-ck" name="rule_no_wg[<?= $ri ?>]" value="1"<?= !empty($r['no_wg']) ? ' checked' : '' ?>></td>
                            <td class="ua-c"><button type="button" class="danger ua-del" title="Удалить строку">🗑</button></td>
                        </tr>
                    <?php $ri++; endforeach; ?>
                    </tbody>
                </table>
                </div>
                <div style="display:flex;gap:.6rem;align-items:center;flex-wrap:wrap;margin-top:1rem">
                    <button type="submit" class="btn">Сохранить правила</button>
                    <button type="button" class="sqcfg-btn" id="uaAddRow">+ Клиент</button>
                    <button type="submit" class="sqcfg-btn" name="reset" value="1" onclick="return confirm('Сбросить правила отдачи к стандартным?')">Сбросить к стандартным</button>
                </div>
            </form>
        </div>
    </section>
    <style>
        .ua-rules-wrap{overflow-x:auto}
        .ua-rules-tbl td{vertical-align:middle}
        .ua-rules-tbl input[type="text"]{width:100%;box-sizing:border-box;font-size:.82rem;padding:.35rem .5rem;background:var(--bg2);border:1px solid var(--line);border-radius:7px;color:var(--text)}
        .ua-rules-tbl .ua-mono{font-family:monospace}
        .ua-rules-tbl .ua-core{max-width:8rem}
        .ua-rules-tbl .ua-c{text-align:center;white-space:nowrap;width:1%}
        .ua-rules-tbl .ua-del{padding:.3rem .55rem}
        .ua-rules-tbl .ua-ck{width:18px;height:18px;accent-color:var(--accent);cursor:pointer;margin:0}
    </style>
    <script>
    (function(){
        var tbl=document.getElementById('uaRulesTbl'); if(!tbl) return;
        var tb=tbl.querySelector('tbody');
        var nextIdx=tb.querySelectorAll('tr').length;
        function rowHtml(i){
            return '<td><input type="text" name="rule_label['+i+']" maxlength="60" placeholder="Название"></td>'
                +'<td><input type="text" name="rule_ua['+i+']" maxlength="60" placeholder="напр. happ" class="ua-mono"></td>'
                +'<td><input type="text" name="rule_core['+i+']" maxlength="24" placeholder="—" class="ua-core"></td>'
                +'<td class="ua-c"><input type="checkbox" class="ua-ck" name="rule_no_awg['+i+']" value="1"></td>'
                +'<td class="ua-c"><input type="checkbox" class="ua-ck" name="rule_no_wg['+i+']" value="1"></td>'
                +'<td class="ua-c"><button type="button" class="danger ua-del" title="Удалить строку">🗑</button></td>';
        }
        var add=document.getElementById('uaAddRow');
        if(add) add.addEventListener('click',function(){
            var tr=document.createElement('tr'); tr.innerHTML=rowHtml(nextIdx++); tb.appendChild(tr);
            var inp=tr.querySelector('input[name^="rule_label"]'); if(inp) inp.focus();
        });
        tb.addEventListener('click',function(e){
            var b=e.target.closest?e.target.closest('.ua-del'):null;
            if(b){ var tr=b.closest('tr'); if(tr) tr.parentNode.removeChild(tr); }
        });
    })();
    </script>

    <section class="<?= coll_cls('wgpool_manual') ?>" data-coll="wgpool_manual">
        <button type="button" class="coll-head" onclick="collToggle(this)"><span>Ручная привязка конфига</span>
            <span class="coll-hr"><svg class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg></span>
        </button>
        <div class="coll-body">
            <p class="muted" style="margin-top:0">Выбери пользователя и конфиг из группы <b>«Ручная привязка»</b> (загруженные выше с галочкой «Ручная привязка» — в обход сквадов). Закрепить можно на пользователя целиком или на конкретное устройство (hwid). Привязка одна на пользователя/устройство — новая заменяет прежнюю. <b>Важно:</b> один WG/AWG-конфиг не привязывай разным юзерам/устройствам — это два устройства на одном ключе и флап.</p>
            <form method="post" autocomplete="off">
                <input type="hidden" name="csrf" value="<?= h($token) ?>">
                <input type="hidden" name="action" value="pool_manual_add">
                <input type="hidden" name="ret" value="wg_pool">
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
                            <?php foreach ($sqcfg_wg as $c): if ((int) $c['enabled'] !== 1 || !in_array('__manual__', squadconf_squads_of($c), true)) continue; ?><option value="<?= (int) $c['id'] ?>"><?= h(($c['name'] !== null && $c['name'] !== '') ? $c['name'] : ('#' . $c['id'])) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label>Устройство (необязательно)</label>
                        <select id="wgm_hwid" name="hwid" class="sqcfg-sel"><option value="">— любое (на пользователя)</option></select>
                    </div>
                </div>
                <div id="wgm_info" class="muted" style="font-size:.82rem;margin:.6rem 0"></div>
                <button type="submit" class="btn" id="wgm_submit" disabled>Привязать</button>
            </form>
            <?php
                $wg_ids = [];
                foreach ($sqcfg_wg as $c) $wg_ids[(int) $c['id']] = (string) ($c['name'] ?? '');
                $man = array_values(array_filter($sqcfg_leases, fn($l) => (int) $l['manual'] === 1 && isset($wg_ids[(int) $l['config_id']])));
            ?>
            <h2 style="font-size:.95rem;margin:1.3rem 0 .5rem">Текущие привязки (<?= count($man) ?>)</h2>
            <?php if (!$man): ?><p class="muted">Пока пусто.</p><?php else: ?>
            <table class="logtbl">
                <thead><tr><th>Конфиг</th><th>Пользователь</th><th>Устройство</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($man as $l): $lcid = (int) $l['config_id']; ?>
                    <tr>
                        <td><?= $wg_ids[$lcid] !== '' ? h($wg_ids[$lcid]) : ('#' . $lcid) ?></td>
                        <td style="font-family:monospace;font-size:.78rem"><?= h((string) $l['short_uuid']) ?></td>
                        <td style="font-family:monospace;font-size:.76rem"><?= $l['hwid'] !== null && $l['hwid'] !== '' ? h((string) $l['hwid']) : '<span class="muted">любое</span>' ?></td>
                        <td style="text-align:right">
                            <form method="post" style="margin:0" onsubmit="return uiConfirmForm(this,'Снять привязку?')">
                                <input type="hidden" name="csrf" value="<?= h($token) ?>">
                                <input type="hidden" name="action" value="pool_manual_del">
                                <input type="hidden" name="ret" value="wg_pool">
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
            <h2>WG / AWG конфиги (<?= count($sqcfg_wg) ?>)</h2>
            <?php if ($sqcfg_wg): ?>
            <div class="loghead-r">
                <label class="pgr-size" style="display:inline-flex;align-items:center;gap:.4rem;margin:0;font-weight:400">На странице:
                    <select id="wgSize" onchange="SQCFGP.setSize(parseInt(this.value,10))">
                        <option value="25"<?= $wg_psize==25?' selected':'' ?>>25</option>
                        <option value="50"<?= $wg_psize==50?' selected':'' ?>>50</option>
                        <option value="100"<?= $wg_psize==100?' selected':'' ?>>100</option>
                        <option value="200"<?= $wg_psize==200?' selected':'' ?>>200</option>
                    </select>
                </label>
            </div>
            <?php endif; ?>
        </div>
        <?php if (!$sqcfg_wg): ?>
            <p class="muted">Пока пусто. Загрузите WG/AWG-конфиги выше.</p>
        <?php else: $sqcfg_edit = []; ?>
        <div class="wg-leasebar">
            <?php if ($sqcfg_dupes): ?><span class="wg-dupe-warn">⚠ дубли выдачи: <?= count($sqcfg_dupes) ?> конфиг(ов) числятся выданными более одного раза (наследие прошлых версий) — нажми «Сбросить выдачи»</span><?php endif; ?>
            <form method="post" style="margin:0;margin-left:auto" onsubmit="return uiConfirmForm(this,'Сбросить все авто-выдачи пула? Ручные привязки останутся. Клиентам нужно будет один раз обновить подписку.')">
                <input type="hidden" name="csrf" value="<?= h($token) ?>">
                <input type="hidden" name="action" value="pool_reset_leases">
                <button type="submit" class="sqcfg-btn" title="Удалить авто-выдачи (manual=0) — пул переразложится начисто, без дублей">↻ Сбросить выдачи</button>
            </form>
        </div>
        <div class="wg-bulkbar">
            <span id="wgChkCount" class="muted">Выбрано: 0</span>
            <span style="flex:1"></span>
            <button type="button" id="wgDelSel" class="danger" disabled>🗑 Удалить выбранные</button>
            <button type="button" id="wgDelAll" class="danger">🗑 Удалить все</button>
        </div>
        <form method="post" id="wgBulkForm" style="display:none">
            <input type="hidden" name="csrf" value="<?= h($token) ?>">
            <input type="hidden" name="action" value="del_squad_configs">
            <input type="hidden" name="ret" value="wg_pool">
            <input type="hidden" name="ids" id="wgBulkIds">
        </form>
        <div class="wg-editbar">
            <div class="we-lbl muted">Массово изменить параметр:</div>
            <div class="we-grid">
                <select id="wgEditParam" class="sqcfg-sel">
                    <option value="mtu">MTU</option>
                    <option value="keepalive">PersistentKeepalive</option>
                    <option value="dns">DNS</option>
                    <option value="allowedips">AllowedIPs</option>
                </select>
                <input type="text" id="wgEditValue" placeholder="значение (пусто — убрать поле)">
            </div>
            <div class="we-btns">
                <button type="button" id="wgEditSel" class="sqcfg-btn" disabled>К выбранным</button>
                <button type="button" id="wgEditAll" class="sqcfg-btn">Ко всем</button>
            </div>
        </div>
        <form method="post" id="wgEditForm" style="display:none">
            <input type="hidden" name="csrf" value="<?= h($token) ?>">
            <input type="hidden" name="action" value="bulk_edit_param">
            <input type="hidden" name="ret" value="wg_pool">
            <input type="hidden" name="ids" id="wgEditIds">
            <input type="hidden" name="param" id="wgEditParamH">
            <input type="hidden" name="value" id="wgEditValueH">
        </form>
        <style>#wgTbl.pgr-pre tbody tr:nth-child(n+<?= $wg_psize + 1 ?>){display:none}#wgPager{min-height:2.1rem}</style>
        <table class="logtbl pgr-pre" id="wgTbl">
            <thead><tr><th style="width:1%"><input type="checkbox" id="wgChkAll" aria-label="Выбрать все"></th><th>Сквады</th><th>Тип</th><th>Метка</th><th>Статус</th><th>Выдан</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($sqcfg_wg as $c):
                $pn = json_decode((string) ($c['parsed'] ?? ''), true);
                $sumr = is_array($pn) ? squadconf_summary($pn) : ($c['type'] ?? '');
                $csquads = squadconf_squads_of($c);
                $on = (int) $c['enabled'] === 1;
                $sqcfg_edit[(int) $c['id']] = ['squads' => array_values($csquads), 'name' => (string) ($c['name'] ?? ''), 'raw' => (string) $c['raw']];
                $lz = $sqcfg_lease_by_cfg[(int) $c['id']] ?? null;
            ?>
            <tr>
                <td><input type="checkbox" class="wg-chk" value="<?= (int) $c['id'] ?>"></td>
                <td><?php foreach ($csquads as $sq): ?><span class="sq-tag"><?= h($sqcfg_names[$sq] ?? $sq) ?></span><?php endforeach; ?></td>
                <td><span class="tag normal"><?= h($sumr) ?></span></td>
                <td><?= $c['name'] !== null && $c['name'] !== '' ? h($c['name']) : '<span class="muted">—</span>' ?></td>
                <td>
                    <form method="post" style="margin:0;display:inline">
                        <input type="hidden" name="csrf" value="<?= h($token) ?>">
                        <input type="hidden" name="action" value="toggle_squad_config">
                        <input type="hidden" name="ret" value="wg_pool">
                        <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                        <input type="hidden" name="enabled" value="<?= $on ? '0' : '1' ?>">
                        <button type="submit" class="sqcfg-btn <?= $on ? '' : 'off' ?>"><?= $on ? '✅ Включён' : '⛔ Выключен' ?></button>
                    </form>
                </td>
                <td>
                <?php if ($lz): $lsu = trim((string) ($lz['short_uuid'] ?? '')); $lhw = (string) ($lz['hwid'] ?? ''); $lplat = $lhw !== '' ? (string) ($sqcfg_hwid_plat[$lhw] ?? '') : ''; $lua = (string) ($lz['ua'] ?? ''); ?>
                    <?php $lname = ($lsu !== '' && !empty($wg_uc[$lsu]['u'])) ? (string) $wg_uc[$lsu]['u'] : ($lsu !== '' ? $lsu : '—'); ?>
                    <span class="wg-issued" data-su="<?= h($lsu) ?>" data-hwid="<?= h($lhw) ?>" data-plat="<?= h($lplat) ?>" data-ua="<?= h($lua) ?>" data-manual="<?= (int) ($lz['manual'] ?? 0) ?>"><span class="wg-issued-name"><?= h($lname) ?></span></span>
                <?php else: ?>
                    <span class="muted">свободен</span>
                <?php endif; ?>
                </td>
                <td style="text-align:right;white-space:nowrap">
                    <?php if ($lz && (int) ($lz['manual'] ?? 0) === 0): ?>
                    <form method="post" style="margin:0;display:inline" onsubmit="return uiConfirmForm(this,'Освободить слот? Текущая выдача снимется, конфиг станет свободным и уйдёт следующему совместимому устройству.')">
                        <input type="hidden" name="csrf" value="<?= h($token) ?>">
                        <input type="hidden" name="action" value="pool_free_slot">
                        <input type="hidden" name="ret" value="wg_pool">
                        <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                        <button type="submit" class="sqcfg-btn" title="Снять выдачу — конфиг станет свободным">✕ Слот</button>
                    </form>
                    <?php endif; ?>
                    <button type="button" class="sqcfg-btn sqcfg-edit" data-id="<?= (int) $c['id'] ?>">✎ Изменить</button>
                    <form method="post" style="margin:0;display:inline" onsubmit="return uiConfirmForm(this,'Удалить этот конфиг?')">
                        <input type="hidden" name="csrf" value="<?= h($token) ?>">
                        <input type="hidden" name="action" value="del_squad_config">
                        <input type="hidden" name="ret" value="wg_pool">
                        <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                        <button type="submit" class="danger">🗑 Удалить</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <div id="wgPager" style="margin-top:.85rem;display:flex;justify-content:flex-end"></div>
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
                    <input type="hidden" name="ret" value="wg_pool">
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
                        <textarea name="raw" id="sqedit_raw" rows="11" spellcheck="false" required style="width:100%;font-family:monospace;font-size:.82rem;box-sizing:border-box"></textarea>
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
    window.WG_UCACHE = <?= json_encode($wg_uc, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    sqcfgInitEdit();
    sqcfgInitPager('wgTbl', 'wgPager', 'wgSize', 'wgpool_size');
    sqcfgInitFileBtn('wgFiles', 'wgFilesInfo');
    (function(){
        var form = document.getElementById('wgUpForm'), inp = document.getElementById('wgFiles'), hid = document.getElementById('wgFilesJson');
        if (!form || !inp || !hid) return;
        var done = false;
        form.addEventListener('submit', function(e){
            if (done) return;
            if (!inp.files || !inp.files.length) return;
            e.preventDefault();
            var files = Array.prototype.slice.call(inp.files), out = [], left = files.length;
            files.forEach(function(f){
                var fr = new FileReader();
                fr.onload = function(){ out.push({n: f.name, c: String(fr.result)}); if (--left === 0) fin(); };
                fr.onerror = function(){ if (--left === 0) fin(); };
                fr.readAsText(f);
            });
            function fin(){ hid.value = JSON.stringify(out); inp.disabled = true; done = true; form.submit(); }
        });
    })();
    (function(){
        var form = document.getElementById('wgEditForm');
        if (!form) return;
        var selB = document.getElementById('wgEditSel'), allB = document.getElementById('wgEditAll');
        var pIn = document.getElementById('wgEditParam'), vIn = document.getElementById('wgEditValue');
        var idsH = document.getElementById('wgEditIds'), pH = document.getElementById('wgEditParamH'), vH = document.getElementById('wgEditValueH');
        function chks(){ return Array.prototype.slice.call(document.querySelectorAll('.wg-chk')); }
        function checked(){ return chks().filter(function(c){ return c.checked; }).map(function(c){ return c.value; }); }
        function upd(){ if (selB) selB.disabled = checked().length === 0; }
        document.addEventListener('change', function(e){ if (e.target && e.target.classList && (e.target.classList.contains('wg-chk') || e.target.id === 'wgChkAll')) upd(); });
        function go(ids){
            if (!ids.length) return;
            var label = pIn.options[pIn.selectedIndex].text, v = vIn.value;
            uiConfirm('Изменить «' + label + '» = "' + (v || '(убрать поле)') + '" у ' + ids.length + ' конфиг(ов)?', function(){
                idsH.value = ids.join(','); pH.value = pIn.value; vH.value = v; form.submit();
            }, 'Применить', false);
        }
        if (selB) selB.addEventListener('click', function(){ go(checked()); });
        if (allB) allB.addEventListener('click', function(){ go(chks().map(function(c){ return c.value; })); });
        upd();
    })();
    (function(){
        var all = document.getElementById('wgChkAll'), selBtn = document.getElementById('wgDelSel'), allBtn = document.getElementById('wgDelAll');
        var cnt = document.getElementById('wgChkCount'), form = document.getElementById('wgBulkForm'), idsInp = document.getElementById('wgBulkIds');
        if (!form) return;
        function chks(){ return Array.prototype.slice.call(document.querySelectorAll('.wg-chk')); }
        function upd(){ var a = chks(), n = a.filter(function(c){ return c.checked; }).length; if (cnt) cnt.textContent = 'Выбрано: ' + n; if (selBtn) selBtn.disabled = n === 0; if (all){ all.checked = n > 0 && n === a.length; all.indeterminate = n > 0 && n < a.length; } }
        if (all) all.addEventListener('change', function(){ var c = all.checked; chks().forEach(function(x){ x.checked = c; }); upd(); });
        document.addEventListener('change', function(e){ if (e.target && e.target.classList && e.target.classList.contains('wg-chk')) upd(); });
        function go(ids, msg){ if (!ids.length) return; uiConfirm(msg, function(){ idsInp.value = ids.join(','); form.submit(); }, 'Удалить', true); }
        if (selBtn) selBtn.addEventListener('click', function(){ var ids = chks().filter(function(c){ return c.checked; }).map(function(c){ return c.value; }); go(ids, 'Удалить выбранные конфиги: ' + ids.length + '?'); });
        if (allBtn) allBtn.addEventListener('click', function(){ var ids = chks().map(function(c){ return c.value; }); go(ids, 'Удалить ВСЕ ' + ids.length + ' конфигов из пула? Отменить нельзя.'); });
        upd();
    })();
    (function(){
        var cells = Array.prototype.slice.call(document.querySelectorAll('.wg-issued'));
        if (!cells.length) return;
        var CACHE = window.WG_UCACHE || {};
        function esc(s){ var d=document.createElement('div'); d.textContent=(s==null?'':s); return d.innerHTML.replace(/"/g,'&quot;'); }
        function osFile(p){ p=String(p||'').toLowerCase();
            if(/ios|iphone|ipad|ipados|mac|darwin|os ?x/.test(p)) return 'apple';
            if(/android/.test(p)) return 'android';
            if(/win/.test(p)) return 'windows';
            if(/linux|ubuntu|debian|fedora|arch|centos/.test(p)) return 'linux';
            return 'device'; }
        function ico(f){ return '<span class="osico" style="-webkit-mask-image:url(assets/os/'+f+'.svg);mask-image:url(assets/os/'+f+'.svg)"></span>'; }
        function clientName(ua){ var raw=String(ua||''), u=raw.toLowerCase(); if(!u) return '';
            if(/v2rayng/.test(u)) return 'v2rayNG';
            if(/v2rayn(?!g)/.test(u)) return 'v2rayN';
            if(/v2raytun/.test(u)) return 'v2RayTun';
            if(/happ/.test(u)) return 'Happ';
            if(/incy/.test(u)) return 'INCY';
            if(/throne/.test(u)) return 'Throne';
            if(/hiddify/.test(u)) return 'Hiddify';
            if(/karing/.test(u)) return 'Karing';
            if(/clash|mihomo|meta|stash|flclash|verge|koala/.test(u)) return 'Clash/Mihomo';
            if(/sing[- ]?box/.test(u)) return 'sing-box';
            if(/streisand/.test(u)) return 'Streisand';
            if(/shadowrocket/.test(u)) return 'Shadowrocket';
            if(/nekobox|nekoray/.test(u)) return 'NekoBox';
            var m=raw.split(/[\/ ]/)[0]; return m ? m.slice(0,24) : raw.slice(0,24); }
        function render(c, name, model, plat, lim){
            var su=c.dataset.su||'', hw=c.dataset.hwid||'', man=c.dataset.manual==='1';
            var ua=c.dataset.ua||'', cli=clientName(ua);
            plat = plat || c.dataset.plat || '';
            var disp = name || su || '—';
            var lines = [];
            if (name) lines.push(name);
            if (su) lines.push('ID: ' + su);
            lines.push('клиент: ' + (cli || 'неизвестен'));
            if (hw) { lines.push((model?model+' · ':'') + (plat||'ОС не определена')); lines.push('hwid: ' + hw); }
            else lines.push('привязка на пользователя');
            if (lim != null) lines.push('лимит устройств: ' + lim);
            if (man) lines.push('ручная привязка');
            if (ua) lines.push('UA: ' + ua);
            var tip = lines.join('\n');
            var dev = hw
                ? ('<span class="wg-issued-dev">(' + ico(osFile(plat)) + ')</span>')
                : ('<span class="muted" style="font-size:.72rem">(на польз.)</span>');
            var cliTag = cli ? (' <span class="wg-cli">' + esc(cli) + '</span>') : '';
            c.innerHTML = '<span class="wg-tip" data-tip="'+esc(tip)+'"><span class="wg-issued-name">'+esc(disp)+'</span> '+dev+cliTag+(man?' <span class="muted">🔧</span>':'')+'</span>';
        }
        function fromCache(c){
            var su=c.dataset.su||'', e=su?CACHE[su]:null; if(!e) return false;
            var hw=c.dataset.hwid||'', dv=(e.d&&e.d[hw])?e.d[hw]:null;
            render(c, e.u||su, dv?(dv.m||''):'', dv?(dv.p||''):(c.dataset.plat||''), (e.lim!=null?e.lim:null));
            return true;
        }
        var bySu={};
        cells.forEach(function(c){
            if(fromCache(c)) return;
            render(c,'','',c.dataset.plat||'',null);
            var su=c.dataset.su||''; if(su)(bySu[su]=bySu[su]||[]).push(c);
        });
        Object.keys(bySu).forEach(function(su){
            fetch('?ajax=pool_user&q='+encodeURIComponent(su)).then(function(r){return r.json();}).then(function(d){
                if(!d||!d.ok||!d.user) return;
                var uname=d.user.username||su, lim=(d.user.hwidDeviceLimit!=null?d.user.hwidDeviceLimit:null), devs=d.devices||[];
                bySu[su].forEach(function(c){
                    var hw=c.dataset.hwid||'', dev=null;
                    for(var i=0;i<devs.length;i++){ if(String(devs[i].hwid||'')===hw){ dev=devs[i]; break; } }
                    render(c, uname, dev?(dev.deviceModel||''):'', dev?(dev.platform||''):(c.dataset.plat||''), lim);
                });
            }).catch(function(){});
        });
    })();
    (function(){
        var NAMES = <?= json_encode($sqcfg_names, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        var SIZING = <?= json_encode($sqcfg_sizing['rows'] ?? [], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        var SIZING_TS = <?= (int) ($sqcfg_sizing['ts'] ?? 0) ?>;
        sqcfgInitManual(NAMES);
        function wgpFmtAgo(ts){ if(!ts) return ''; var s=Math.max(0,Math.floor(Date.now()/1000-ts)); if(s<60) return 'только что'; if(s<3600) return Math.floor(s/60)+' мин назад'; if(s<86400) return Math.floor(s/3600)+' ч назад'; return Math.floor(s/86400)+' дн назад'; }
        function wgpApply(rows){
            document.querySelectorAll('.wgp-u').forEach(function(td){
                var row = td.parentNode, su = td.dataset.su, r = (rows || {})[su];
                var dd = row.querySelector('.wgp-d');
                var stock = parseInt((row.children[2] || {}).textContent || '0', 10);
                if (!r) { td.textContent = '0'; dd.textContent = '0'; dd.classList.remove('wgp-warn'); return; }
                td.textContent = (r.active || 0) + ' / ' + (r.users || 0);
                dd.textContent = r.devices || 0;
                if (stock < (r.devices || 0)) dd.classList.add('wgp-warn'); else dd.classList.remove('wgp-warn');
            });
        }
        var WGP_HINT = 'Добавлено — реально зарегистрированные устройства из базы hwid. Красным — пул меньше факта.';
        var calc = document.getElementById('wgpCalc'), wgpMsg = document.getElementById('wgpCalcMsg');
        if (calc) {
            calc.addEventListener('click', function(){
                if (wgpMsg) wgpMsg.textContent = 'Считаю по панели…'; calc.disabled = true;
                fetch('?ajax=pool_sizing&csrf=<?= h($token) ?>').then(function(r){ return r.json(); }).then(function(d){
                    calc.disabled = false;
                    if (!d.ok) { if (wgpMsg) wgpMsg.textContent = 'Ошибка: ' + (d.error || 'нет данных'); return; }
                    wgpApply(d.rows);
                    if (d.totals) { var tr = document.getElementById('wgpTotRec'), tu = document.getElementById('wgpTotUniq'); if (tr) tr.textContent = d.totals.records || 0; if (tu) tu.textContent = d.totals.unique || 0; }
                    var extra = d.warn ? (' <span class="wgp-warn">hwid-эндпоинт: ' + d.warn + '</span>') : '';
                    if (wgpMsg) wgpMsg.innerHTML = 'Рассчитано только что. ' + WGP_HINT + extra;
                }).catch(function(){ calc.disabled = false; if (wgpMsg) wgpMsg.textContent = 'Ошибка запроса'; });
            });
        }
    })();
    </script>
