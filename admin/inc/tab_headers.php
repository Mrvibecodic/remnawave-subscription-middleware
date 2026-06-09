    <div class="info">
        Эти заголовки прослойка добавляет в ответ подписки — ими приложение-клиент (Happ, FlClashX) управляет своим видом и функциями. <b>По умолчанию список пуст и ничего не отправляется.</b> Добавьте нужные, впишите значение и включите тумблер «Вкл». Выключенные строки и строки с пустым значением не отправляются — поэтому пресеты безопасно держать выключенными, пока не настроите.
    </div>
    <div class="card">
        <h2 style="margin-top:0;font-size:1rem">Активные заголовки</h2>
        <p class="muted">Эти заголовки прослойка отдаёт в подписке. Впишите значение и оставьте включёнными. Пустые и выключенные строки не отправляются.</p>
        <form method="post" id="ahForm">
            <input type="hidden" name="csrf" value="<?= h($token) ?>">
            <input type="hidden" name="action" value="save_app_headers">
            <input type="hidden" name="app_headers_json" id="ah_json" value="">
            <div class="ah-head"><div>Вкл</div><div>Заголовок</div><div>Значение</div><div>Описание</div><div></div></div>
            <div id="ahRows"></div>
            <div class="muted" id="ahEmpty" style="display:none;padding:.5rem 0">Пока ничего не добавлено — выберите заголовки в каталоге ниже.</div>
            <div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-top:1rem">
                <button type="submit">💾 Сохранить</button>
                <button type="button" class="btn ghost" onclick="ahAdd({enabled:true})">➕ Свой заголовок</button>
            </div>
        </form>
    </div>
    <div class="card">
        <h2 style="margin-top:0;font-size:1rem">Каталог заголовков</h2>
        <p class="muted">Посмотрите, что делает каждый заголовок, и нажмите «Добавить», чтобы перенести его в активный список выше. Заголовки, уже заданные в панели, помечены — <b>включение такого тут перезапишет значение панели</b>.</p>
        <?php if ($panel_headers_err !== ''): ?><div class="warn" style="margin-bottom:.8rem">Не удалось получить заголовки панели: <?= h($panel_headers_err) ?></div><?php endif; ?>
        <div id="ahCatalog"></div>
    </div>
    <div class="card">
        <h2 style="margin-top:0;font-size:1rem">Как подключить Happ Premium</h2>
        <p class="muted">Премиум-функции Happ (смена User-Agent, инфо-блоки sub-info, sub-expire, new-url, hide-settings и т.д.) работают только при наличии Provider ID в подписке.</p>
        <ol class="muted" style="line-height:1.8;padding-left:1.1rem;margin:0">
            <li>Зарегистрируйтесь на <a href="https://happ-proxy.com" target="_blank" rel="noopener">happ-proxy.com</a> и скопируйте свой <b>Provider ID</b>.</li>
            <li>В каталоге разверните группу <b>«Happ — премиум»</b>, добавьте <code>providerid</code>, впишите ваш ID и оставьте включённым.</li>
            <li>Добавьте нужные премиум-заголовки (например <code>change-user-agent</code>, <code>sub-expire</code>) и задайте значения.</li>
            <li>Сохраните — прослойка начнёт отдавать их в подписке, Happ применит премиум.</li>
        </ol>
        <p class="muted" style="margin-top:.7rem">Provider ID можно отдавать заголовком <code>providerid</code> (этот способ), в URL <code>#?providerid=…</code> или в теле <code>#providerid …</code>.</p>
    </div>
    <style>
        .ah-head,.ah-row{display:grid;grid-template-columns:42px 1.2fr 1.5fr 1.6fr 38px;gap:.5rem;align-items:center}
        .ah-head{font-size:.72rem;text-transform:uppercase;letter-spacing:.03em;color:var(--muted);margin:.3rem 0}
        .ah-row{margin-bottom:.5rem}
        .ah-row input[type=text]{margin:0}
        .ah-chk{display:flex;align-items:center;justify-content:center}
        .ah-chk input{width:auto;margin:0}
        .ah-del{background:transparent;border:1px solid var(--line);color:var(--c-bad-fg);border-radius:7px;padding:.45rem 0;cursor:pointer;line-height:1}
        .ah-del:hover{border-color:var(--red)}
        .catgrp{border:1px solid var(--line);border-radius:10px;margin-bottom:.6rem;overflow:hidden}
        .cathead{width:100%;display:flex;align-items:center;justify-content:space-between;gap:1rem;background:var(--bg2);color:var(--text-strong);border:0;border-radius:0;padding:.7rem .9rem;cursor:pointer;font-weight:600;font-size:.92rem;text-align:left}
        .cathead:hover{background:var(--hover)}
        .cathead .chev{width:16px;height:16px;color:var(--muted);transition:transform .2s;flex:0 0 auto}
        .catgrp:not(.open) .chev{transform:rotate(-90deg)}
        .catbody{padding:.2rem .9rem .6rem}
        .catgrp:not(.open) .catbody{display:none}
        .catrow{display:flex;align-items:center;gap:.8rem;padding:.55rem 0;border-bottom:1px solid var(--line)}
        .catrow:last-child{border-bottom:0}
        .catrow .ci{flex:1;min-width:0}
        .catrow .cn{font-weight:600;color:var(--text-strong);font-size:.84rem;font-family:monospace}
        .catrow .cd{color:var(--muted);font-size:.78rem;margin-top:.12rem;line-height:1.4}
        .catadd{flex:0 0 auto;background:transparent;border:1px solid var(--line);color:var(--accent-text);border-radius:7px;padding:.35rem .8rem;font-size:.78rem;font-weight:600;cursor:pointer}
        .catadd:hover{border-color:var(--accent)}
        .catadd.added{color:var(--muted);cursor:default}
        .catrow.req{border-left:3px solid var(--accent);padding-left:.7rem;background:var(--accent-light);border-radius:0 8px 8px 0}
        .creq{font-size:.64rem;font-weight:700;color:var(--accent-text);border:1px solid var(--accent);border-radius:999px;padding:.05rem .45rem;margin-left:.35rem;vertical-align:middle}
        .cex{color:var(--muted);font-size:.76rem;margin-top:.2rem}
        .cex code{font-size:.92em}
        .catico{width:24px;height:24px;border-radius:6px;flex:0 0 auto;object-fit:cover;background:var(--bg2)}
        .catico.gold{box-shadow:0 0 0 2px #d4af37}
        .catgrp.gold{border-color:#d4af37}
        .catgrp.gold .cathead{background:linear-gradient(90deg,rgba(212,175,55,.14),transparent 60%)}
        .cpanel{font-size:.62rem;font-weight:700;color:var(--c-warn-fg);border:1px solid var(--c-warn-fg);border-radius:999px;padding:.05rem .45rem;margin-left:.35rem;vertical-align:middle}
        .cpv{color:var(--c-warn-fg);font-size:.78rem;margin-top:.25rem;word-break:break-word}
        .cpv code{font-size:.92em}
        .catrow.inpanel{border-left:3px solid var(--c-warn-fg);padding-left:.7rem}
        @media(max-width:760px){
            .ah-head{display:none}
            .ah-row{grid-template-columns:auto 1fr;gap:.45rem;border:1px solid var(--line);border-radius:10px;padding:.6rem;background:var(--bg2);margin-bottom:.55rem}
            .ah-row .a-name{grid-column:2}
            .ah-row .a-val,.ah-row .a-note{grid-column:1/-1}
            .ah-row .ah-del{grid-column:2;justify-self:end;width:auto;padding:.4rem .75rem}
            .catrow{flex-wrap:wrap}
            .catrow .catadd{margin-top:.3rem}
        }
    </style>
    <script>
    var AH_HEADERS = <?= json_encode(app_headers_all(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var PANEL_HEADERS = <?php $pl=[]; foreach(($panel_headers ?? []) as $pk=>$pvv){ $pl[strtolower($pk)]=$pvv; } echo json_encode($pl, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    var AH_CATALOG = [
        {group:'koala-clash', key:'koala', color:'#e8893c', letter:'K', items:[
            {name:'global-mode', note:'Глобальный режим прокси. Включён при любом значении КРОМЕ «false» (чтобы выключить — слать ровно false).', ex:'false'},
            {name:'profile-logo', note:'Логотип профиля (URL png/svg); koala скачивает и кеширует.', ex:'https://example.com/logo.svg'},
            {name:'custom-css', note:'Кастомный CSS оформления (URL на .css); koala скачивает по ссылке.', ex:'https://example.com/theme.css'},
            {name:'x-hwid-limit', note:'true — koala показывает экран «лимит устройств HWID» (ссылку берёт из support-url).', ex:'true'},
            {name:'x-hwid-max-devices-reached', note:'Альтернатива x-hwid-limit: true → тот же экран лимита HWID.', ex:'true'},
            {name:'announce', note:'Объявление в клиенте (koala тоже читает). Текст или base64:, \\n → перенос строки.', ex:'Профилактика до 18:00'}
        ]},
        {group:'FlClashX', key:'flclashx', color:'#3b82f6', letter:'F', items:[
            {name:'flclashx-widgets', note:'Порядок виджетов на дашборде.', ex:'announce,metainfo,outboundModeV2,networkDetection'},
            {name:'flclashx-view', note:'Вид страницы прокси.', ex:'type:list; sort:delay; layout:tight; icon:icon; card:shrink'},
            {name:'flclashx-custom', note:'Когда применять стиль: add (при добавлении) или update (при каждом обновлении).', ex:'update'},
            {name:'flclashx-denywidgets', note:'true — запретить пользователю редактировать дашборд.', ex:'true'},
            {name:'flclashx-servicename', note:'Название сервиса в виджете ServiceInfo.', ex:'My VPN'},
            {name:'flclashx-servicelogo', note:'Логотип png/svg по URL (работает с flclashx-servicename).', ex:'https://example.com/logo.svg'},
            {name:'flclashx-serverinfo', note:'Имя прокси-группы для виджета смены сервера.', ex:'Proxy'},
            {name:'flclashx-background', note:'URL фонового изображения приложения.', ex:'https://example.com/bg.jpg'},
            {name:'flclashx-settings', note:'Настройки через подписку (перечислить нужные).', ex:'minimize, autostart, autoupdate'},
            {name:'flclashx-globalmode', note:'false — скрыть в клиенте настройки режима прокси.', ex:'false'},
            {name:'flclashx-hex', note:'Тема приложения: HEX[:вариант][:pureblack].', ex:'FF5733:vibrant'}
        ]},
        {group:'Happ — стандарт', key:'happ', color:'#18b6c9', letter:'H', items:[
            {name:'profile-title', note:'Имя профиля подписки (до 25 символов; текст или base64:).', ex:'My VPN'},
            {name:'profile-update-interval', note:'Интервал авто-обновления подписки, часов.', ex:'1'},
            {name:'support-url', note:'Ссылка на поддержку (для t.me показывается иконка TG).', ex:'https://t.me/your_support'},
            {name:'profile-web-page-url', note:'Ссылка на веб-страницу подписки.', ex:'https://example.com'},
            {name:'announce', note:'Анонс: обычный текст или base64:, до 200 символов.', ex:'Профилактика до 18:00'},
            {name:'routing-enable', note:'0 — выключить routing в приложении.', ex:'0'}
        ]},
        {group:'Happ — премиум (нужен Provider ID)', key:'happ', gold:true, color:'#18b6c9', letter:'H', items:[
            {name:'providerid', req:true, note:'Provider ID с happ-proxy.com — БЕЗ него остальные премиум-заголовки Happ не работают.', ex:'5f3a9c2e10b8'},
            {name:'change-user-agent', note:'Свой User-Agent при запросе подписки.', ex:'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/135.0 Safari/537.36'},
            {name:'sub-info-text', note:'Текст инфо-блока в приложении, до 200 символов.', ex:'Спасибо, что с нами!'},
            {name:'sub-info-color', note:'Цвет инфо-блока.', ex:'blue'},
            {name:'sub-info-button-text', note:'Текст кнопки инфо-блока, до 25 символов.', ex:'Продлить'},
            {name:'sub-info-button-link', note:'Ссылка/диплинк кнопки инфо-блока.', ex:'https://t.me/your_bot'},
            {name:'sub-expire', note:'1 — показывать блок об истечении подписки (за 3 дня и после).', ex:'1'},
            {name:'sub-expire-button-link', note:'Ссылка кнопки «Renew» в блоке истечения.', ex:'https://t.me/your_bot'},
            {name:'new-url', note:'Полная замена URL подписки.', ex:'https://new.example.com/sub/abc123'},
            {name:'new-domain', note:'Сменить только домен подписки.', ex:'new.example.com'},
            {name:'fallback-url', note:'Резервный URL подписки (при ошибке/таймауте основного).', ex:'https://backup.example.com/sub/abc123'},
            {name:'hide-settings', note:'1 — скрыть/запретить просмотр и редактирование конфигов серверов.', ex:'1'},
            {name:'subscription-always-hwid-enable', note:'1 — запретить пользователю отключать HWID.', ex:'1'},
            {name:'notification-subs-expire', note:'1 — авто-уведомления об истечении подписки.', ex:'1'}
        ]}
    ];
    function ahEsc(s){var d=document.createElement('div');d.textContent=(s==null?'':s);return d.innerHTML;}
    function ahRowHtml(t){
        t=t||{};
        return '<div class="ah-row">'+
            '<label class="ah-chk"><input type="checkbox" class="a-en" '+(t.enabled?'checked':'')+'></label>'+
            '<input type="text" class="a-name" placeholder="header-name" value="'+ahEsc(t.name||'')+'">'+
            '<input type="text" class="a-val" placeholder="'+ahEsc(t.ex||'значение')+'" value="'+ahEsc(t.value||'')+'">'+
            '<input type="text" class="a-note" placeholder="описание" value="'+ahEsc(t.note||'')+'">'+
            '<button type="button" class="ah-del" aria-label="Удалить">×</button>'+
        '</div>';
    }
    function ahBind(row){var del=row.querySelector('.ah-del');if(del)del.addEventListener('click',function(){row.remove();ahSync();});}
    function ahRender(){var c=document.getElementById('ahRows');c.innerHTML=AH_HEADERS.map(ahRowHtml).join('');c.querySelectorAll('.ah-row').forEach(ahBind);ahSync();}
    function ahAdd(t){var c=document.getElementById('ahRows');c.insertAdjacentHTML('beforeend', ahRowHtml(t||{}));ahBind(c.lastElementChild);ahSync();}
    function ahHas(name){var f=false;document.querySelectorAll('#ahRows .a-name').forEach(function(i){if(i.value.trim().toLowerCase()===String(name).toLowerCase())f=true;});return f;}
    function ahCollect(){var out=[];document.querySelectorAll('#ahRows .ah-row').forEach(function(r){var name=r.querySelector('.a-name').value.trim();if(!name)return;out.push({name:name,value:r.querySelector('.a-val').value,note:r.querySelector('.a-note').value.trim(),enabled:r.querySelector('.a-en').checked});});return out;}
    function ahSync(){document.getElementById('ah_json').value=JSON.stringify(ahCollect());document.getElementById('ahEmpty').style.display=document.querySelectorAll('#ahRows .ah-row').length?'none':'';catSyncButtons();}
    function icoFallback(im){var s=document.createElement('span');s.style.cssText='display:inline-flex;align-items:center;justify-content:center;width:24px;height:24px;border-radius:6px;flex:0 0 auto;background:'+(im.getAttribute('data-c')||'#888')+';color:#fff;font-size:12px;font-weight:700'+(im.classList.contains('gold')?';box-shadow:0 0 0 2px #d4af37':'');s.textContent=im.getAttribute('data-l')||'?';im.replaceWith(s);}
    function catIcon(g){return '<img src="assets/icons/'+g.key+'.png" alt="" class="catico'+(g.gold?' gold':'')+'" data-l="'+ahEsc(g.letter)+'" data-c="'+g.color+'" onerror="icoFallback(this)">';}
    function catRender(){
        var c=document.getElementById('ahCatalog');
        c.innerHTML=AH_CATALOG.map(function(g,gi){
            var rows=g.items.map(function(it){
                var reqBadge=it.req?' <span class="creq">обязателен</span>':'';
                var exLine=it.ex?'<div class="cex">Пример: <code>'+ahEsc(it.ex)+'</code></div>':'';
                var pv=PANEL_HEADERS[String(it.name).toLowerCase()];
                var inPanel=(pv!==undefined&&pv!==null);
                var panelBadge=inPanel?' <span class="cpanel">задан в панели</span>':'';
                var panelLine=inPanel?'<div class="cpv">В панели: <code>'+ahEsc(pv!==''?pv:'(пусто)')+'</code> · включение тут перезапишет заголовок панели</div>':'';
                return '<div class="catrow'+(it.req?' req':'')+(inPanel?' inpanel':'')+'"><div class="ci"><div class="cn">'+ahEsc(it.name)+reqBadge+panelBadge+'</div><div class="cd">'+ahEsc(it.note)+'</div>'+exLine+panelLine+'</div>'+
                    '<button type="button" class="catadd" data-name="'+ahEsc(it.name)+'" data-note="'+ahEsc(it.note)+'" data-ex="'+ahEsc(it.ex||'')+'">➕ Добавить</button></div>';
            }).join('');
            return '<div class="catgrp'+(g.gold?' gold':'')+'" id="catg'+gi+'"><button type="button" class="cathead" onclick="catToggle('+gi+')"><span style="display:flex;align-items:center;gap:.55rem">'+catIcon(g)+'<span>'+ahEsc(g.group)+' <span class="muted" style="font-weight:400">('+g.items.length+')</span></span></span><svg class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg></button><div class="catbody">'+rows+'</div></div>';
        }).join('');
        c.querySelectorAll('.catadd').forEach(function(b){b.addEventListener('click',function(){catAdd(b);});});
        catSyncButtons();
    }
    function catToggle(i){var g=document.getElementById('catg'+i);if(g)g.classList.toggle('open');}
    function catAdd(btn){var name=btn.getAttribute('data-name');if(ahHas(name))return;ahAdd({name:name,note:btn.getAttribute('data-note'),ex:btn.getAttribute('data-ex'),value:'',enabled:true});}
    function catSyncButtons(){document.querySelectorAll('.catadd').forEach(function(b){var has=ahHas(b.getAttribute('data-name'));b.textContent=has?'✓ В списке':'➕ Добавить';b.classList.toggle('added',has);b.disabled=has;});}
    function ahOverride(btn){
        var name=btn.getAttribute('data-pn'), val=btn.getAttribute('data-pv');
        if(!ahHas(name)) ahAdd({name:name,value:val,enabled:true});
        var rows=document.querySelectorAll('#ahRows .ah-row');
        if(rows.length){ var last=rows[rows.length-1]; last.scrollIntoView({behavior:'smooth',block:'center'}); var inp=last.querySelector('.a-val'); if(inp) inp.focus(); }
        if(window.uiToast) uiToast('Заголовок «'+name+'» добавлен наверх — отредактируйте значение и нажмите «Сохранить»');
    }
    document.getElementById('ahForm').addEventListener('input',ahSync);
    document.getElementById('ahForm').addEventListener('submit',ahSync);
    ahRender();
    catRender();
    </script>

