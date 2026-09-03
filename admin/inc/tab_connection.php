    <style>
        .cn-spd{font-size:.8rem;color:var(--muted);margin-top:.45rem;display:flex;align-items:center;gap:.5rem;flex-wrap:wrap}
        .cn-spd-btn{width:auto;min-height:0;padding:.25rem .65rem;font-size:.78rem;line-height:1.3}
        .set-grid2{display:grid;grid-template-columns:1fr 1fr;gap:.55rem;margin-top:.7rem}
        .set-grid2 .set-row{margin-top:0}
        @media(max-width:720px){.set-grid2{grid-template-columns:1fr}}
        .uahk-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:.5rem}
        .uahk{display:flex;align-items:center;gap:.65rem;border:1px solid var(--line);background:var(--bg2);border-radius:10px;padding:.6rem .8rem;cursor:pointer;transition:border-color .15s}
        .uahk:hover{border-color:var(--accent)}
        .uahk input{appearance:none;-webkit-appearance:none;width:18px;height:18px;border:2px solid var(--line);border-radius:50%;flex:0 0 auto;margin:0;cursor:pointer;position:relative;transition:border-color .15s,background .15s}
        .uahk input:checked{border-color:var(--accent);background:var(--accent)}
        .uahk input:checked::after{content:"";position:absolute;left:50%;top:50%;width:6px;height:6px;border-radius:50%;background:var(--accent-text);transform:translate(-50%,-50%)}
        .uahk-txt{min-width:0;display:flex;flex-direction:column;gap:.1rem;line-height:1.3}
        .uahk-txt .muted{font-size:.76rem}
    </style>
    <div class="card">
        <h2 style="margin-top:0;font-size:1rem">Подключение</h2>
        <p class="muted" style="margin-bottom:.4rem">Нажмите <b>?</b> у любого поля — справа откроется справка с примером. Формат: <b>домены — без</b> <code>https://</code>, а <b>URL панели — со схемой</b> <code>https://</code>.</p>
        <?php if (submw_in_docker()): ?><div class="info" style="margin-bottom:.6rem">Docker-режим: адреса панели и subscription-page предзаполнены из окружения контейнера при установке и дальше хранятся в БД. Обычно достаточно задать <b>API-токен панели</b>; если прослойка стоит отдельно от панели или нужен режим «Зеркало» — поменяйте поля здесь.</div><?php endif; ?>
        <form method="post" data-autosave>
            <input type="hidden" name="csrf" value="<?= h($token) ?>">
            <input type="hidden" name="action" value="save_connection">
            <div class="row">
                <div><label>Origin — домен подписки <button type="button" class="qh" onclick="help('origin')" aria-label="Справка">?</button></label><input type="text" id="cnTarget" name="target_domain" value="<?= h(target_domain()) ?>" placeholder="sub.example.com"></div>
                <div><label>Домен зеркала <button type="button" class="qh" onclick="help('mirror')" aria-label="Справка">?</button></label><input type="text" name="mirror_domain" value="<?= h($mirror) ?>" placeholder="mirror.example.com"></div>
            </div>
            <?php $cn_spd = panel_sub_public_domain(); ?>
            <?php if ($cn_spd !== '' && strcasecmp($cn_spd, target_domain()) !== 0): ?>
            <div class="cn-spd">В панели домен подписки — <code><?= h($cn_spd) ?></code>. <button type="button" class="btn ghost cn-spd-btn" data-domain="<?= h($cn_spd) ?>" onclick="cnUseOrigin(this)">Подставить в origin</button></div>
            <?php elseif ($cn_spd !== ''): ?>
            <div class="cn-spd">Origin совпадает с доменом подписки в панели.</div>
            <?php endif; ?>
            <div class="row">
                <div><label>URL панели Remnawave <button type="button" class="qh" onclick="help('rwurl')" aria-label="Справка">?</button></label><input type="text" name="remnawave_url" value="<?= h(remnawave_url()) ?>" placeholder="https://panel.example.com"></div>
                <div><label>Cookie панели (eGames-защита) <button type="button" class="qh" onclick="help('cookie')" aria-label="Справка">?</button></label><input type="text" name="remnawave_cookie" value="<?= h(remnawave_cookie()) ?>" placeholder="aB3xK9pQ=Zt7mW2nR"></div>
            </div>
            <div class="row">
                <div><label>API-токен панели <button type="button" class="qh" onclick="help('apikey')" aria-label="Справка">?</button></label><input type="password" name="remnawave_api_key" value="" placeholder="<?= remnawave_token() ? '•••••• задан' : 'не задан' ?>"></div>
                <div><label>Секрет вебхука <button type="button" class="qh" onclick="help('whsecret')" aria-label="Справка">?</button></label><input type="password" name="webhook_secret" value="" placeholder="<?= webhook_secret() ? '•••••• задан' : 'не задан' ?>"></div>
            </div>
            <div class="row">
                <div><label>X-Api-Key (caddy-with-auth) <button type="button" class="qh" onclick="help('xapikey')" aria-label="Справка">?</button></label><input type="text" name="remnawave_xapikey" value="<?= h(remnawave_xapikey()) ?>" placeholder="если панель за Caddy with custom path; иначе пусто"></div>
            </div>
            <div class="set-row">
                <div class="set-info"><div class="set-t">Таймаут проксирования, сек <button type="button" class="qh" onclick="help('timeout')" aria-label="Справка">?</button></div><div class="set-d">Сколько ждать ответа origin при запросе подписки.</div></div>
                <input type="number" name="proxy_timeout" value="<?= h(proxy_timeout()) ?>">
            </div>
            <div class="set-grid2">
                <div class="set-row">
                    <div class="set-info"><div class="set-t">Доверять заголовку expire <button type="button" class="qh" onclick="help('trust')" aria-label="Справка">?</button></div><div class="set-d">Рекомендуется — продление подписки чинит себя само.</div></div>
                    <label class="switch"><input type="checkbox" name="trust_header_expire" <?= trust_header_expire()?'checked':'' ?>><span class="sl"></span></label>
                </div>
                <div class="set-row">
                    <div class="set-info"><div class="set-t">Проверять TLS-сертификат панели и origin <button type="button" class="qh" onclick="help('tls')" aria-label="Справка">?</button></div><div class="set-d">Защита от MITM при запросах к панели и origin. Выключайте только при самоподписанном сертификате.</div></div>
                    <label class="switch"><input type="checkbox" name="tls_verify" <?= api_tls_verify()?'checked':'' ?>><span class="sl"></span></label>
                </div>
            </div>
            <?php $eff_mode = sub_source(); $show_mirror_row = ($eff_mode === 'mirror'); $show_url_row = ($eff_mode === 'panel' || subpage_mirror_active()); ?>
            <div class="set-row">
                <div class="set-info"><div class="set-t">Источник подписки</div><div class="set-d">«Зеркало» — проксирование origin-домена (как раньше). «Панель» — прослойка сама становится подпиской (sub-сервис Remnawave): конфиги берутся с <code>/api/sub</code> панели, а в браузере рендерится страница подписки. Адрес панели — это контейнер/loopback (рядом с панелью) <b>или</b> публичный <code>https://</code>-домен (если прослойка на отдельном сервере).</div></div>
                <select name="sub_source" id="subSourceSel">
                    <option value="mirror" <?= sub_source()==='mirror'?'selected':'' ?>>Зеркало (origin)</option>
                    <option value="panel" <?= sub_source()==='panel'?'selected':'' ?>>Панель (sub-сервис)</option>
                </select>
            </div>
            <div class="set-row" id="rowSubpageMirror"<?= $show_mirror_row ? '' : ' style="display:none"' ?>>
                <div class="set-info"><div class="set-t">Браузерную страницу брать с отдельного адреса (Зеркало)</div><div class="set-d">Только для режима «Зеркало». Тумблер направляет браузерные запросы (и <code>/assets</code>) на адрес subscription-page ниже вместо origin; приложения продолжают получать конфиг с origin. Нужен лишь когда ваш origin отдаёт голый конфиг без HTML-страницы — тогда браузер получит страницу подписки. Если origin уже является subscription-page, он и так отдаёт страницу браузеру, и тумблер не требуется. Требуется заданный адрес subscription-page. На режим «Панель» не влияет.</div></div>
                <label class="switch"><input type="checkbox" name="subpage_mirror" id="subpageMirrorChk" <?= subpage_mirror_active()?'checked':'' ?>><span class="sl"></span></label>
            </div>
            <div class="row" id="rowSubpageUrl"<?= $show_url_row ? '' : ' style="display:none"' ?>>
                <div><label>Адрес subscription-page (режимы «Панель» / «Зеркало»)</label><input type="text" name="subpage_external_url" value="<?= h(subpage_external_url()) ?>" placeholder="https://panel.example.com или http://127.0.0.1:3010"><div class="muted" style="font-size:.8rem;margin-top:.3rem">Рядом с панелью — адрес контейнера/loopback; на отдельном сервере — публичный https-адрес панели. Используется как источник страницы подписки для браузера в обоих режимах.</div></div>
                <div></div>
            </div>
            <div class="set-row">
                <div class="set-info"><div class="set-t">Ссылки в формате <code>/api/sub/</code></div><div class="set-d">Показывать ссылки подписки во вкладке «Пользователи» как <code>/api/sub/&lt;shortUuid&gt;</code> вместо голого <code>/&lt;shortUuid&gt;</code>. Нужно, если по голой ссылке origin не отдаёт страницу подписки, а по <code>/api/sub/&lt;shortUuid&gt;</code> — отдаёт.</div></div>
                <label class="switch"><input type="checkbox" name="sub_link_apisub" <?= sub_link_apisub()?'checked':'' ?>><span class="sl"></span></label>
            </div>
            <?php $pfx_on = setting('sub_prefix_enabled', '0') === '1'; ?>
            <div class="set-row">
                <div class="set-info"><div class="set-t">Префикс подписки (<code>CUSTOM_SUB_PREFIX</code>) <button type="button" class="qh" onclick="help('subprefix')" aria-label="Справка">?</button></div><div class="set-d">Если origin — штатный subscription-page на отдельном сервере с <code>CUSTOM_SUB_PREFIX</code>, ссылки панели выглядят как <code>/&lt;prefix&gt;/&lt;shortUuid&gt;</code>. Включите, чтобы прослойка снимала префикс перед определением shortUuid (грейс, HWID, лог, доп. конфиги) и возвращала его в запросе к origin. Ссылки без префикса продолжают работать как раньше.</div></div>
                <label class="switch"><input type="checkbox" name="sub_prefix_enabled" id="subPrefixOn" <?= $pfx_on?'checked':'' ?>><span class="sl"></span></label>
            </div>
            <div class="row" id="rowSubPrefix"<?= $pfx_on ? '' : ' style="display:none"' ?>>
                <div><label>Значение префикса</label><input type="text" name="sub_prefix" value="<?= h((string) setting('sub_prefix', '')) ?>" placeholder="sub" autocomplete="off"><div class="muted" style="font-size:.8rem;margin-top:.3rem">Как в <code>.env</code> subscription-page: без слэшей по краям, например <code>sub</code>.</div></div>
                <div></div>
            </div>
            <div class="set-row" id="rowSubLinkPrefix"<?= $pfx_on ? '' : ' style="display:none"' ?>>
                <div class="set-info"><div class="set-t">Ссылки с префиксом</div><div class="set-d">Показывать ссылки во вкладке «Пользователи» как <code>/&lt;prefix&gt;/&lt;shortUuid&gt;</code> — один в один с тем, что выдаёт панель. Выключено — ссылки прослойки остаются голыми <code>/&lt;shortUuid&gt;</code> (или <code>/api/sub/</code>, если включён тумблер выше); работают оба варианта.</div></div>
                <label class="switch"><input type="checkbox" name="sub_link_prefix" <?= setting('sub_link_prefix', '0') === '1'?'checked':'' ?>><span class="sl"></span></label>
            </div>
            <div class="set-row">
                <div class="set-info"><div class="set-t">Обезличивать 404</div><div class="set-d">На неизвестный путь или несуществующий UUID отдаётся <b>собственный пустой 404</b> без тела и заголовков панели — probe видит обычное «страницы нет», как у любого сайта, без признаков что за фронтом что-то есть. Держите выключенным, если клиентам нужен проксируемый ответ панели.</div></div>
                <label class="switch"><input type="checkbox" name="mask_notfound" <?= mask_notfound()?'checked':'' ?>><span class="sl"></span></label>
            </div>
            <?php $ua_keys_now = ua_hwid_keys(); $ua_key_meta = ['x-hwid' => 'идентификатор устройства (влияет на лимит)', 'x-device-os' => 'ОС устройства', 'x-ver-os' => 'версия ОС', 'x-device-model' => 'модель устройства']; ?>
            <div class="set-row">
                <div class="set-info"><div class="set-t">HWID из User-Agent <button type="button" class="qh" onclick="help('uahwid')" aria-label="Справка">?</button></div><div class="set-d">Если клиент (v2rayNG, Clash) не умеет слать HTTP-заголовки, но даёт менять User-Agent — извлекать device-заголовки из строки вида <code>...; x-hwid=значение; ...)</code> и форвардить на панель. Реальный заголовок всегда главнее. <b>Внимание:</b> User-Agent задаётся пользователем вручную, поэтому <code>x-hwid</code> так можно подменить — это ослабляет лимит устройств; HWID здесь удобство, не защита. Держите выключенным, если не требуется.</div></div>
                <label class="switch"><input type="checkbox" name="ua_hwid_parse" <?= ua_hwid_parse() ? 'checked' : '' ?> onchange="document.getElementById('uahkBlock').style.display=this.checked?'block':'none'"><span class="sl"></span></label>
            </div>
            <div class="set-row" id="uahkBlock" style="display:<?= ua_hwid_parse() ? 'block' : 'none' ?>">
                <div class="set-info" style="margin-bottom:.65rem"><div class="set-t">Какие ключи извлекать</div><div class="set-d">Отмеченные заголовки берутся из User-Agent, когда настоящего заголовка в запросе нет.</div></div>
                <div class="uahk-grid">
                    <?php foreach ($ua_key_meta as $uk => $ud): ?>
                        <label class="uahk"><input type="checkbox" name="ua_hwid_keys[]" value="<?= h($uk) ?>" <?= in_array($uk, $ua_keys_now, true) ? 'checked' : '' ?>><span class="uahk-txt"><code><?= h($uk) ?></code><span class="muted"><?= h($ud) ?></span></span></label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div style="margin-top:1.25rem"><button type="submit">💾 Сохранить подключение</button></div>
        </form>
    </div>
    <script>
    function cnUseOrigin(btn){
        var inp=document.getElementById('cnTarget'); if(!inp||!btn) return;
        var f=inp.form; if(!f) return;
        inp.value=btn.getAttribute('data-domain')||'';
        btn.disabled=true;
        var fd=new FormData(f); fd.append('xhr','1');
        fetch('index.php',{method:'POST',credentials:'same-origin',body:fd})
            .then(function(r){return r.json();})
            .then(function(d){
                if(d&&d.ok){location.reload();return;}
                btn.disabled=false;
                if(window.uiToast)uiToast('Не сохранено');
            })
            .catch(function(){
                btn.disabled=false;
                if(window.uiToast)uiToast('Ошибка сети — не сохранено');
            });
    }
    (function(){
        var sel=document.getElementById('subSourceSel');
        if(!sel) return;
        var rowMirror=document.getElementById('rowSubpageMirror');
        var rowUrl=document.getElementById('rowSubpageUrl');
        var chk=document.getElementById('subpageMirrorChk');
        function sync(){
            var m=sel.value, mir=chk&&chk.checked;
            if(rowMirror) rowMirror.style.display=(m==='mirror')?'':'none';
            if(rowUrl) rowUrl.style.display=(m==='panel'||mir)?'':'none';
        }
        sel.addEventListener('change',sync);
        if(chk) chk.addEventListener('change',sync);
        sync();
    })();
    (function(){
        var on=document.getElementById('subPrefixOn');
        if(!on) return;
        var rows=[document.getElementById('rowSubPrefix'),document.getElementById('rowSubLinkPrefix')];
        function sync(){rows.forEach(function(r){if(r) r.style.display=on.checked?'':'none';});}
        on.addEventListener('change',sync);
        sync();
    })();
    </script>

