    <div class="card">
        <h2 style="margin-top:0;font-size:1rem">Доп. конфиги по внутреннему скваду</h2>
        <p class="muted">Конфиги, привязанные к внутреннему скваду Remnawave, дописываются в подписку пользователям этого сквада. Доступны <b>только пока подписка активна</b>: при истечении / блокировке конфиг из подписки исчезает (остаются заглушки). Поддерживается WireGuard (clash + base64) и AmneziaWG (<b>только Mihomo/clash</b>) — вставьте клиентский <code>.conf</code>.</p>
        <?php if ($sqcfg_squads_err !== ''): ?>
            <div class="warn">Список сквадов недоступен: <?= h($sqcfg_squads_err) ?>. Проверьте URL панели и токен во вкладке «Подключение».</div>
        <?php elseif (!$sqcfg_squads): ?>
            <div class="warn">Внутренние сквады не получены. Настройте подключение к панели.</div>
        <?php endif; ?>

        <form method="post" autocomplete="off">
            <input type="hidden" name="csrf" value="<?= h($token) ?>">
            <input type="hidden" name="action" value="save_squad_config">

            <div class="sqcfg-grid">
                <div>
                    <label for="sqcfg_squad">1. Сквад</label>
                    <select id="sqcfg_squad" name="squad_uuid" class="sqcfg-sel" required>
                        <option value="" selected>— выберите сквад —</option>
                        <?php foreach ($sqcfg_squads as $s): ?>
                            <option value="<?= h($s['uuid']) ?>"><?= h($s['name']) ?> <?= $s['members'] ? '(' . (int) $s['members'] . ')' : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="sqcfg_type">2. Тип конфига</label>
                    <select id="sqcfg_type" name="type" class="sqcfg-sel" required>
                        <option value="" selected>— выберите тип —</option>
                        <option value="wireguard">WireGuard (WG)</option>
                        <option value="amneziawg">AmneziaWG (AWG) — только Mihomo</option>
                    </select>
                </div>
                <div>
                    <label for="sqcfg_name">3. Метка</label>
                    <input type="text" id="sqcfg_name" name="name" class="sqcfg-flag" placeholder="напр.: Нидерланды · Сервер 1" maxlength="191" required>
                </div>
            </div>
            <div class="muted" style="font-size:.8rem;margin-top:.45rem">WireGuard — base64 (v2rayNG, Throne) и Mihomo (clash). AmneziaWG — Mihomo (clash) и Throne (wg://); в v2rayNG/xray — нет. Введёшь страну в метке — флаг подставится автоматически (Нидерланды → 🇳🇱).</div>

            <div class="form-row" style="margin-top:1rem">
                <label for="sqcfg_raw">4. Конфиг (.conf)</label>
                <textarea id="sqcfg_raw" name="raw" rows="12" spellcheck="false" placeholder="[Interface]&#10;PrivateKey = ...&#10;Address = 10.8.1.2/32&#10;&#10;[Peer]&#10;PublicKey = ...&#10;Endpoint = host:port&#10;AllowedIPs = 0.0.0.0/0, ::/0" style="width:100%;font-family:monospace;font-size:.82rem;box-sizing:border-box"></textarea>
            </div>
            <div id="sqcfg_hint" class="sqcfg-hint" style="display:none"></div>
            <div style="margin-top:1rem;display:flex;align-items:center;gap:.75rem">
                <button type="submit" class="btn">Добавить конфиг</button>
                <span class="muted" style="font-size:.8rem">Секреты конфига хранятся в БД и отдаются только при активной подписке.</span>
            </div>
        </form>
    </div>

    <div class="card">
        <h2 style="margin-top:0;font-size:1rem">Добавленные конфиги (<?= count($sqcfg_list) ?>)</h2>
        <?php if (!$sqcfg_list): ?>
            <p class="muted">Пока пусто.</p>
        <?php else: $sqcfg_edit = []; ?>
        <table class="logtbl">
            <thead><tr><th>Сквад</th><th>Тип</th><th>Метка</th><th>Xray</th><th>sing-box</th><th>Mihomo</th><th>Статус</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($sqcfg_list as $c):
                $pn = json_decode((string) ($c['parsed'] ?? ''), true);
                $sumr = is_array($pn) ? awg_summary($pn) : ($c['type'] ?? '');
                $sqname = $sqcfg_names[$c['squad_uuid']] ?? $c['squad_uuid'];
                $on = (int) $c['enabled'] === 1;
                $ptype = is_array($pn) ? ($pn['type'] ?? '') : '';
                $sqcfg_edit[(int) $c['id']] = ['squad' => (string) $c['squad_uuid'], 'name' => (string) ($c['name'] ?? ''), 'raw' => (string) $c['raw']];
            ?>
            <tr>
                <td><?= h($sqname) ?></td>
                <td><span class="tag normal"><?= h($sumr) ?></span></td>
                <td><?= $c['name'] !== null && $c['name'] !== '' ? h($c['name']) : '<span class="muted">—</span>' ?></td>
                <td style="font-size:.78rem"><?php if ($ptype === 'wireguard'): ?>base64<?php else: ?><span class="muted">—</span><?php endif; ?></td>
                <td style="font-size:.78rem"><?php if (in_array($ptype, ['wireguard', 'amneziawg'], true)): ?>wg://<?php else: ?><span class="muted">—</span><?php endif; ?></td>
                <td style="font-size:.78rem"><?php if (in_array($ptype, ['wireguard', 'amneziawg'], true)): ?>clash<?php else: ?><span class="muted">—</span><?php endif; ?></td>
                <td>
                    <form method="post" style="margin:0;display:inline">
                        <input type="hidden" name="csrf" value="<?= h($token) ?>">
                        <input type="hidden" name="action" value="toggle_squad_config">
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
                        <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                        <button type="submit" class="danger">🗑 Удалить</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

    <div id="sqEditModal" class="modal-overlay" onclick="if(event.target===this)sqEditClose()">
        <div class="modal">
            <div class="modal-head">
                <div>Редактировать конфиг</div>
                <button type="button" class="modal-x" onclick="sqEditClose()">×</button>
            </div>
            <div class="modal-body">
                <form method="post" autocomplete="off">
                    <input type="hidden" name="csrf" value="<?= h($token) ?>">
                    <input type="hidden" name="action" value="edit_squad_config">
                    <input type="hidden" name="id" id="sqedit_id" value="">
                    <div style="margin-bottom:.85rem">
                        <label>Сквад</label>
                        <select name="squad_uuid" id="sqedit_squad" class="sqcfg-sel" required style="width:100%;box-sizing:border-box">
                            <?php foreach ($sqcfg_squads as $s): ?>
                                <option value="<?= h($s['uuid']) ?>"><?= h($s['name']) ?> <?= $s['members'] ? '(' . (int) $s['members'] . ')' : '' ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div style="margin-bottom:.85rem">
                        <label>Метка</label>
                        <input type="text" name="name" id="sqedit_name" class="sqcfg-flag" maxlength="191" required style="width:100%;box-sizing:border-box">
                    </div>
                    <div style="margin-bottom:.85rem">
                        <label>Конфиг (.conf)</label>
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

    <style>
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
        .sqcfg-btn{background:transparent;border:1px solid var(--line);color:var(--text);border-radius:8px;padding:.4rem .75rem;font-size:.82rem;font-weight:600;cursor:pointer}
        .sqcfg-btn.off{opacity:.65}
        .sqcfg-edit{margin-right:.45rem}
        #sqEditModal label{display:block;margin-bottom:.3rem;font-weight:600;font-size:.82rem}
        .card label{display:block;margin-bottom:.35rem;font-weight:600;font-size:.85rem}
    </style>
    <script>
    (function(){
        var SQ_CSRF = <?= json_encode($token) ?>;
        var ta = document.getElementById('sqcfg_raw'), hint = document.getElementById('sqcfg_hint'), t = null;
        function esc(s){var d=document.createElement('div');d.textContent=(s==null?'':s);return d.innerHTML;}
        function render(d){
            if(!d){ hint.style.display='none'; return; }
            var cls = d.ok ? 'ok' : 'bad';
            var html = d.ok
                ? ('Распознан конфиг <b>'+esc(d.summary)+'</b>.')
                : ('<span class="warn-line">Конфиг не распознан как WireGuard.</span>');
            if(d.ok && d.clients && d.clients.length){
                html += ' Работает в клиентах: '+d.clients.map(esc).join(', ')+'. Будет доступен пользователю после обновления подписки.';
            }
            if(d.warnings && d.warnings.length){
                html += '<ul>'+d.warnings.map(function(w){return '<li class="warn-line">'+esc(w)+'</li>';}).join('')+'</ul>';
            }
            hint.className='sqcfg-hint '+cls; hint.innerHTML=html; hint.style.display='';
        }
        function check(){
            var raw = ta.value;
            if(raw.trim()===''){ hint.style.display='none'; return; }
            var f=new FormData(); f.append('csrf',SQ_CSRF); f.append('raw',raw);
            fetch('?ajax=parse_config',{method:'POST',body:f}).then(function(r){return r.json();}).then(render).catch(function(){});
        }
        if(ta){ ta.addEventListener('input',function(){ clearTimeout(t); t=setTimeout(check,400); }); }
    })();
    (function(){
        var C={'нидерланды':'NL','голландия':'NL','netherlands':'NL','holland':'NL','германия':'DE','germany':'DE','deutschland':'DE','сша':'US','америка':'US','usa':'US','united states':'US','america':'US','великобритания':'GB','британия':'GB','англия':'GB','united kingdom':'GB','britain':'GB','england':'GB','франция':'FR','france':'FR','финляндия':'FI','finland':'FI','швеция':'SE','sweden':'SE','норвегия':'NO','norway':'NO','дания':'DK','denmark':'DK','польша':'PL','poland':'PL','чехия':'CZ','czechia':'CZ','czech':'CZ','австрия':'AT','austria':'AT','швейцария':'CH','switzerland':'CH','италия':'IT','italy':'IT','испания':'ES','spain':'ES','португалия':'PT','portugal':'PT','ирландия':'IE','ireland':'IE','бельгия':'BE','belgium':'BE','люксембург':'LU','luxembourg':'LU','россия':'RU','russia':'RU','украина':'UA','ukraine':'UA','беларусь':'BY','belarus':'BY','казахстан':'KZ','kazakhstan':'KZ','турция':'TR','turkey':'TR','türkiye':'TR','оаэ':'AE','эмираты':'AE','uae':'AE','emirates':'AE','израиль':'IL','israel':'IL','канада':'CA','canada':'CA','бразилия':'BR','brazil':'BR','аргентина':'AR','argentina':'AR','япония':'JP','japan':'JP','корея':'KR','южная корея':'KR','korea':'KR','south korea':'KR','китай':'CN','china':'CN','гонконг':'HK','hong kong':'HK','hongkong':'HK','тайвань':'TW','taiwan':'TW','сингапур':'SG','singapore':'SG','индия':'IN','india':'IN','индонезия':'ID','indonesia':'ID','вьетнам':'VN','vietnam':'VN','таиланд':'TH','thailand':'TH','малайзия':'MY','malaysia':'MY','австралия':'AU','australia':'AU','новая зеландия':'NZ','new zealand':'NZ','юар':'ZA','south africa':'ZA','египет':'EG','egypt':'EG','сербия':'RS','serbia':'RS','румыния':'RO','romania':'RO','болгария':'BG','bulgaria':'BG','венгрия':'HU','hungary':'HU','греция':'GR','greece':'GR','латвия':'LV','latvia':'LV','литва':'LT','lithuania':'LT','эстония':'EE','estonia':'EE','исландия':'IS','iceland':'IS','молдова':'MD','молдавия':'MD','moldova':'MD','грузия':'GE','georgia':'GE','армения':'AM','armenia':'AM','азербайджан':'AZ','azerbaijan':'AZ','мексика':'MX','mexico':'MX','чили':'CL','chile':'CL','кипр':'CY','cyprus':'CY','мальта':'MT','malta':'MT','словакия':'SK','slovakia':'SK','словения':'SI','slovenia':'SI','хорватия':'HR','croatia':'HR'};
        var ISO={}; for(var k in C) ISO[C[k]]=1;
        function flag(iso){ if(!/^[A-Z]{2}$/.test(iso)) return ''; return String.fromCodePoint(0x1F1E6+iso.charCodeAt(0)-65)+String.fromCodePoint(0x1F1E6+iso.charCodeAt(1)-65); }
        function hasFlag(s){ try{ return /^[\u{1F1E6}-\u{1F1FF}]{2}/u.test(s); }catch(e){ return false; } }
        function detect(v){
            var t=v.trim().split(/\s+/), raw0=t[0]||'', low=v.trim().toLowerCase().split(/\s+/);
            var c2=low.slice(0,2).join(' '), c1=low[0]||'';
            if(C[c2]) return C[c2];
            if(C[c1]) return C[c1];
            if(/^[A-Z]{2}$/.test(raw0) && ISO[raw0]) return raw0;
            return '';
        }
        function apply(inp){
            var v=inp.value; if(!v.trim() || hasFlag(v)) return;
            var iso=detect(v); if(!iso) return;
            inp.value=flag(iso)+' '+v.trim();
        }
        document.querySelectorAll('.sqcfg-flag').forEach(function(i){ i.addEventListener('blur',function(){ apply(i); }); });
    })();
    window.SQCFG = <?= json_encode($sqcfg_edit ?? [], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    (function(){
        var modal = document.getElementById('sqEditModal');
        window.sqEditClose = function(){ if(modal) modal.classList.remove('open'); };
        function openEdit(id){
            var d = (window.SQCFG || {})[id]; if(!d || !modal) return;
            document.getElementById('sqedit_id').value = id;
            var sel = document.getElementById('sqedit_squad'), found = false;
            for(var i=0;i<sel.options.length;i++){ if(sel.options[i].value === d.squad){ found = true; break; } }
            if(!found){ var o = document.createElement('option'); o.value = d.squad; o.textContent = d.squad; sel.appendChild(o); }
            sel.value = d.squad;
            document.getElementById('sqedit_name').value = d.name || '';
            document.getElementById('sqedit_raw').value = d.raw || '';
            modal.classList.add('open');
        }
        document.querySelectorAll('.sqcfg-edit').forEach(function(b){ b.addEventListener('click',function(){ openEdit(b.dataset.id); }); });
        document.addEventListener('keydown',function(e){ if(e.key === 'Escape') sqEditClose(); });
    })();
    </script>
