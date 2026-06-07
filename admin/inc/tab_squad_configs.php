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
                    <label for="sqcfg_name">3. Метка (необязательно)</label>
                    <input type="text" id="sqcfg_name" name="name" placeholder="напр.: WG · Сервер 1" maxlength="191">
                </div>
            </div>
            <div class="muted" style="font-size:.8rem;margin-top:.45rem">WireGuard — base64 (v2rayNG) и Mihomo (clash). AmneziaWG — только Mihomo (clash); в base64 и xray не уйдёт.</div>

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
        <?php else: ?>
        <table class="logtbl">
            <thead><tr><th>Сквад</th><th>Тип</th><th>Метка</th><th>Статус</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($sqcfg_list as $c):
                $pn = json_decode((string) ($c['parsed'] ?? ''), true);
                $sumr = is_array($pn) ? awg_summary($pn) : ($c['type'] ?? '');
                $sqname = $sqcfg_names[$c['squad_uuid']] ?? $c['squad_uuid'];
                $on = (int) $c['enabled'] === 1;
                $ptype = is_array($pn) ? ($pn['type'] ?? '') : '';
                $clabel = ($c['name'] !== null && trim((string) $c['name']) !== '') ? trim((string) $c['name']) : 'WireGuard';
                $prev = in_array($ptype, ['wireguard', 'amneziawg'], true) ? awg_to_clash($pn, $clabel) : '';
                $prevuri = ($ptype === 'wireguard') ? wg_to_uri($pn, $clabel) : '';
            ?>
            <tr>
                <td><?= h($sqname) ?><div class="muted" style="font-size:.72rem"><code><?= h($c['squad_uuid']) ?></code></div></td>
                <td><span class="tag normal"><?= h($sumr) ?></span></td>
                <td><?= $c['name'] !== null && $c['name'] !== '' ? h($c['name']) : '<span class="muted">—</span>' ?></td>
                <td>
                    <form method="post" style="margin:0;display:inline">
                        <input type="hidden" name="csrf" value="<?= h($token) ?>">
                        <input type="hidden" name="action" value="toggle_squad_config">
                        <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                        <input type="hidden" name="enabled" value="<?= $on ? '0' : '1' ?>">
                        <button type="submit" class="sqcfg-btn <?= $on ? '' : 'off' ?>"><?= $on ? '✅ Включён' : '⛔ Выключен' ?></button>
                    </form>
                </td>
                <td style="text-align:right">
                    <form method="post" style="margin:0;display:inline" onsubmit="return uiConfirmForm(this,'Удалить этот конфиг?')">
                        <input type="hidden" name="csrf" value="<?= h($token) ?>">
                        <input type="hidden" name="action" value="del_squad_config">
                        <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                        <button type="submit" class="danger">🗑 Удалить</button>
                    </form>
                </td>
            </tr>
            <?php if ($prev !== ''): ?>
            <tr><td colspan="5" style="padding-top:0">
                <details>
                    <summary class="muted" style="cursor:pointer;font-size:.8rem">Показать запись (что уйдёт в подписку)</summary>
                    <div class="muted" style="font-size:.74rem;margin:.5rem 0 .2rem">clash / Mihomo:</div>
                    <pre style="margin:0;padding:.7rem;background:var(--bg2);border:1px solid var(--line);border-radius:8px;overflow:auto;font-size:.76rem;white-space:pre"><?= h($prev) ?></pre>
                    <?php if ($prevuri !== ''): ?>
                    <div class="muted" style="font-size:.74rem;margin:.6rem 0 .2rem">base64 (wireguard://):</div>
                    <pre style="margin:0;padding:.7rem;background:var(--bg2);border:1px solid var(--line);border-radius:8px;overflow:auto;font-size:.76rem;white-space:pre-wrap;word-break:break-all"><?= h($prevuri) ?></pre>
                    <?php endif; ?>
                </details>
            </td></tr>
            <?php endif; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
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
    </script>
