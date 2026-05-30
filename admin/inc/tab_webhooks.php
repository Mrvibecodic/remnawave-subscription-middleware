    <div class="card">
        <h2 style="margin-top:0;font-size:1rem">Как включить вебхук в Remnawave <button type="button" class="qh" onclick="help('webhook_env')" aria-label="Справка">?</button></h2>
        <p class="muted">Добавьте эти строки в <code>.env</code> панели и перезапустите её:</p>
        <pre>WEBHOOK_ENABLED=true
WEBHOOK_URL=<?= h($wh_url) ?>

WEBHOOK_SECRET_HEADER=<?= h(webhook_secret() ?: '<секрет из «Подключения»>') ?></pre>
        <p class="muted">После перезапуска события появятся в «Логе вебхуков» с подписью <span class="tag normal">ok</span>.</p>
    </div>

    <style>
        .fwd-row{display:grid;grid-template-columns:1fr 2fr 1.4fr auto auto;gap:.6rem;align-items:center;margin-bottom:.55rem}
        .fwd-row input[type=text],.fwd-row input[type=password]{margin:0}
        .fwd-chk{display:flex;align-items:center;gap:.4rem;font-size:.78rem;color:var(--muted);white-space:nowrap}
        .fwd-chk input{width:auto}
        .fwd-del{background:transparent;border:1px solid var(--line);color:#ff8787;border-radius:7px;padding:.45rem .65rem;font-size:.85rem;cursor:pointer;line-height:1}
        .fwd-del:hover{border-color:var(--red)}
        .fwd-head{display:grid;grid-template-columns:1fr 2fr 1.4fr auto auto;gap:.6rem;font-size:.7rem;text-transform:uppercase;letter-spacing:.03em;color:var(--muted);margin:.4rem 0}
        .fwd-empty{color:var(--muted);font-size:.85rem;padding:.5rem 0}
        .fwd-res{margin-top:.8rem;font-size:.82rem}
        .fwd-res .ln{padding:.35rem 0;border-bottom:1px solid var(--line)}
        .fwd-res .ln:last-child{border-bottom:0}
        @media(max-width:760px){
            .fwd-head{display:none}
            .fwd-row{grid-template-columns:1fr;gap:.4rem;padding:.7rem;border:1px solid var(--line);border-radius:10px;background:var(--bg2)}
        }
    </style>
    <div class="card">
        <h2 style="margin-top:0;font-size:1rem">Раздвоение вебхука («тройник») <button type="button" class="qh" onclick="help('forward')" aria-label="Справка">?</button></h2>
        <p class="muted">Нужен, если адресатам нужны <b>разные секреты</b> или пересылка после обработки прослойкой. Если всем хватает одного секрета — проще перечислить URL-ы через запятую прямо в <code>WEBHOOK_URL</code> панели. Подробнее — по «?». URL прослойки: <code><?= h($wh_url) ?></code>.</p>
        <form method="post" id="fwdForm">
            <input type="hidden" name="csrf" value="<?= h($token) ?>">
            <input type="hidden" name="action" value="save_forward">
            <input type="hidden" name="forward_targets_json" id="fwd_json" value="">
            <div class="set-row">
                <div class="set-info"><div class="set-t">Включить пересылку</div><div class="set-d">Пересылать входящие вебхуки адресатам из списка ниже.</div></div>
                <label class="switch"><input type="checkbox" name="forward_enabled" <?= forward_enabled()?'checked':'' ?>><span class="sl"></span></label>
            </div>
            <div class="set-row">
                <div class="set-info"><div class="set-t">Таймаут на адрес, сек</div><div class="set-d">Сколько ждать ответа каждого адресата.</div></div>
                <input type="number" name="forward_timeout" min="2" value="<?= h(forward_timeout()) ?>">
            </div>

            <label style="margin-top:1.5rem;margin-bottom:.3rem">Адресаты</label>
            <div class="fwd-head" id="fwdHead" style="display:none"><div>Имя</div><div>URL</div><div>Секрет (ключ)</div><div>Вкл</div><div></div></div>
            <div id="fwdRows"></div>
            <div class="fwd-empty" id="fwdEmpty" style="display:none">Адресатов нет — добавьте первый.</div>

            <div style="display:flex;gap:.6rem;margin-top:.9rem;flex-wrap:wrap">
                <button type="button" class="btn ghost" onclick="fwdAdd()">➕ Добавить адресата</button>
                <button type="button" class="btn ghost" onclick="fwdTest()">🧪 Тест пересылки</button>
                <button type="submit">💾 Сохранить раздвоение</button>
            </div>
            <div class="fwd-res" id="fwdRes"></div>
        </form>
    </div>

    <section class="coll collapsed" data-coll="next_branding">
        <button type="button" class="coll-head" onclick="collToggle(this)">← Вернуться в Брендинг
            <svg class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
        </button>
        <div class="coll-body">
            <div class="info">В разделе <a href="?tab=branding" style="color:var(--accent-text)">Брендинг</a> — имя и логотип сервиса из API панели идут в название, лого и фавикон админки; можно перебить вручную.</div>
        </div>
    </section>
    <script>
    var FWD_TARGETS = <?= json_encode(forward_targets(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var FWD_CSRF = <?= json_encode($token) ?>;
    function fwdEsc(s){var d=document.createElement('div');d.textContent=(s==null?'':s);return d.innerHTML;}
    function fwdRowHtml(t){
        t=t||{};
        return '<div class="fwd-row">'+
            '<input type="text" class="f-name" placeholder="бот" value="'+fwdEsc(t.name||'')+'">'+
            '<input type="text" class="f-url" placeholder="https://bot.example.com/webhook" value="'+fwdEsc(t.url||'')+'">'+
            '<input type="password" class="f-secret" placeholder="секрет" value="'+fwdEsc(t.secret||'')+'">'+
            '<label class="fwd-chk"><input type="checkbox" class="f-en" '+((t.enabled===false)?'':'checked')+'> вкл</label>'+
            '<button type="button" class="fwd-del" onclick="this.closest(\'.fwd-row\').remove();fwdSync()">×</button>'+
        '</div>';
    }
    function fwdRender(){
        var c=document.getElementById('fwdRows');
        c.innerHTML=FWD_TARGETS.map(fwdRowHtml).join('');
        fwdSync();
    }
    function fwdAdd(){
        var c=document.getElementById('fwdRows');
        c.insertAdjacentHTML('beforeend', fwdRowHtml({enabled:true}));
        fwdSync();
    }
    function fwdCollect(){
        var out=[];
        document.querySelectorAll('#fwdRows .fwd-row').forEach(function(r){
            var url=r.querySelector('.f-url').value.trim();
            if(!url) return;
            out.push({
                name:r.querySelector('.f-name').value.trim(),
                url:url,
                secret:r.querySelector('.f-secret').value,
                enabled:r.querySelector('.f-en').checked
            });
        });
        return out;
    }
    function fwdSync(){
        document.getElementById('fwd_json').value=JSON.stringify(fwdCollect());
        var n=document.querySelectorAll('#fwdRows .fwd-row').length;
        document.getElementById('fwdEmpty').style.display=n?'none':'';
        var hd=document.getElementById('fwdHead'); if(hd) hd.style.display=n?'':'none';
    }
    document.getElementById('fwdForm').addEventListener('submit',fwdSync);
    document.getElementById('fwdForm').addEventListener('input',fwdSync);
    function fwdTest(){
        fwdSync();
        var res=document.getElementById('fwdRes');
        res.innerHTML='<div class="muted">Отправка теста…</div>';
        var f=new FormData(); f.append('csrf',FWD_CSRF);
        fetch('?ajax=test_forward',{method:'POST',body:f}).then(function(r){return r.json();}).then(function(d){
            if(!d.ok){ res.innerHTML='<div class="warn">Ошибка: '+fwdEsc(d.error||'')+'</div>'; return; }
            var rows=d.results||[];
            if(!rows.length){ res.innerHTML='<div class="muted">Нет включённых адресатов. Отметьте «Вкл» и нажмите «Сохранить раздвоение», затем тест.</div>'; return; }
            res.innerHTML=rows.map(function(x){
                var ok=x.ok?'<span class="tag normal">ok '+x.code+'</span>':'<span class="tag error">'+(x.code||'—')+(x.error?(' '+fwdEsc(x.error)):'')+'</span>';
                return '<div class="ln">'+ok+' &nbsp;'+fwdEsc(x.name||x.url)+'</div>';
            }).join('');
        }).catch(function(){ res.innerHTML='<div class="warn">Сетевая ошибка</div>'; });
    }
    fwdRender();
    </script>
