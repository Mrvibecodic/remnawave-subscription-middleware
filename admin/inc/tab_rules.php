    <div class="info">
        Отдавайте заголовки клиентам подписки. Правило <b>«Все клиенты»</b> уходит всем; правило под конкретное приложение <b>добавляет</b> свои заголовки поверх для него. Заголовки выбираются из списка с описанием — вписывать ничего не нужно.
    </div>

    <section class="coll collapsed" data-coll="rules_help">
        <button type="button" class="coll-head" onclick="collToggle(this)"><span>❓ Как это работает (простым языком)</span>
            <span class="coll-hr"><svg class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg></span>
        </button>
        <div class="coll-body">
            <p class="muted" style="margin-top:0;line-height:1.7"><b>1. Кому отдать</b> — выберите приложение из списка (или «Все клиенты»). Приложение узнаётся по «подписи» (User-Agent), подпись подставляется сама.</p>
            <p class="muted" style="line-height:1.7"><b>2. Платформа</b> — нужна редко. Различать Android/iOS умеет в основном Happ. Остальным оставьте «Любая».</p>
            <p class="muted" style="line-height:1.7"><b>3. Что показать</b> — выберите заголовки из списка (с описанием) и впишите значение. Кириллица в <code>profile-title</code>/<code>announce</code> кодируется автоматически.</p>
            <p class="muted" style="line-height:1.7">Заголовки всех подходящих правил складываются. Критичные служебные заголовки изменить нельзя — они защищены. Удаление правила сохраняется сразу.</p>
        </div>
    </section>

    <section class="coll collapsed" data-coll="rules_happ_prem">
        <button type="button" class="coll-head" onclick="collToggle(this)"><span>💎 Как подключить Happ Premium</span>
            <span class="coll-hr"><svg class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg></span>
        </button>
        <div class="coll-body">
            <p class="muted" style="margin-top:0">Премиум-функции Happ (инфо-блоки <code>sub-info-*</code>, <code>sub-expire</code>, <code>new-url</code>, <code>hide-settings</code> и т.д.) работают только при наличии Provider ID.</p>
            <ol class="muted" style="line-height:1.8;padding-left:1.1rem;margin:0">
                <li>Получите <b>Provider ID</b> на <a href="https://happ-proxy.com/security/login" target="_blank" rel="noopener">happ-proxy.com/security/login</a>.</li>
                <li>Создайте правило для приложения <b>Happ</b>, добавьте заголовок <code>providerid</code>, впишите ваш ID.</li>
                <li>Добавьте нужные премиум-заголовки (например <code>sub-info-text</code>, <code>sub-expire</code>) и задайте значения.</li>
                <li>Сохраните — прослойка начнёт отдавать их Happ.</li>
            </ol>
        </div>
    </section>

    <div class="card">
        <h2 style="margin-top:0;font-size:1rem">Правила</h2>
        <form method="post" id="rrForm">
            <input type="hidden" name="csrf" value="<?= h($token) ?>">
            <input type="hidden" name="action" value="save_response_rules">
            <input type="hidden" name="response_rules_json" id="rr_json" value="">
            <div id="rrList"></div>
            <div class="muted" id="rrEmpty" style="display:none;padding:.5rem 0">Пока нет ни одного правила. Нажмите «Добавить».</div>
            <div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-top:1rem">
                <button type="submit">💾 Сохранить</button>
                <button type="button" class="btn ghost" onclick="rrAdd()">➕ Добавить</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h2 style="margin-top:0;font-size:1rem">Проверка по User-Agent</h2>
        <p class="muted">Вставьте подпись приложения (User-Agent) — покажу, какие правила сработают и какие заголовки уйдут клиенту.</p>
        <div class="rr-test">
            <input type="text" id="rrTestUa" placeholder="например: Happ/2.10.0 (Android 14)">
            <select id="rrTestOs">
                <option value="">Платформа: любая</option>
                <option value="windows">Windows</option>
                <option value="macos">macOS</option>
                <option value="linux">Linux</option>
                <option value="android">Android</option>
                <option value="ios">iOS</option>
            </select>
            <button type="button" onclick="rrTest()">Проверить</button>
        </div>
        <div id="rrTestOut" class="rr-test-out muted" style="display:none"></div>
    </div>

    <style>
        .rr-group{font-size:.72rem;text-transform:uppercase;letter-spacing:.04em;color:var(--accent-text);font-weight:700;margin:1.1rem 0 .5rem;padding-bottom:.25rem;border-bottom:1px solid var(--line)}
        .rr-group:first-child{margin-top:.2rem}
        .rr-rule{border:1px solid var(--line);border-radius:12px;padding:.8rem .9rem;margin-bottom:.55rem;background:var(--bg2)}
        .rr-rule.off{opacity:.55}
        .rr-top{display:flex;align-items:center;gap:.7rem;flex-wrap:wrap}
        .rr-top .nm{font-weight:600;color:var(--text-strong);flex:1;min-width:120px}
        .rr-chk{display:flex;align-items:center;gap:.35rem;font-size:.8rem;color:var(--muted)}
        .rr-chk input{width:auto;margin:0}
        .rr-iconbtn{background:transparent;border:1px solid var(--line);color:var(--muted);border-radius:7px;padding:.3rem .55rem;cursor:pointer;line-height:1}
        .rr-iconbtn:hover{border-color:var(--accent);color:var(--text)}
        .rr-iconbtn.del{color:#ff8787}
        .rr-fields{display:grid;grid-template-columns:1.3fr 1fr;gap:.6rem;margin:.7rem 0}
        .rr-fields label,.rr-hlbl{display:block;font-size:.74rem;text-transform:uppercase;letter-spacing:.03em;color:var(--muted);margin-bottom:.25rem}
        .rr-ua{margin:.4rem 0 .2rem}
        .rr-hdrs{margin-top:.5rem;border-top:1px dashed var(--line);padding-top:.6rem}
        .rr-hrow{display:grid;grid-template-columns:1.2fr 1.3fr 36px;gap:.45rem;align-items:center;margin:.45rem 0 .15rem}
        .rr-hrow select,.rr-hrow input{margin:0}
        .rr-hint{font-size:.76rem;color:var(--muted);margin:0 0 .5rem;min-height:1em;line-height:1.4}
        .rr-hint b{color:var(--text-strong);font-family:monospace}
        .rr-addh{background:transparent;border:1px solid var(--line);color:var(--accent-text);border-radius:7px;padding:.32rem .7rem;font-size:.78rem;font-weight:600;cursor:pointer}
        .rr-addh:hover{border-color:var(--accent)}
        .rr-test{display:flex;gap:.5rem;flex-wrap:wrap;align-items:center}
        .rr-test input{flex:1;min-width:220px;margin:0}
        .rr-test select{margin:0}
        .rr-test-out{margin-top:.8rem;border:1px solid var(--line);border-radius:10px;padding:.7rem .9rem;line-height:1.6;word-break:break-word}
        .rr-test-out b{color:var(--text-strong)}
        .rr-test-out code{font-size:.86em}
        @media(max-width:760px){.rr-fields{grid-template-columns:1fr}.rr-hrow{grid-template-columns:1fr 1fr 32px}}
    </style>

    <script>
    var RR_RULES   = <?= json_encode(response_rules_all(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var RR_CLIENTS = <?= json_encode(rules_client_catalog(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var RR_CAT     = <?= json_encode(app_headers_catalog(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var RR_TOKEN   = <?= json_encode($token) ?>;

    function rrEsc(s){var d=document.createElement('div');d.textContent=(s==null?'':s);return d.innerHTML;}
    function rrCatFind(name){for(var g in RR_CAT){for(var i=0;i<RR_CAT[g].length;i++){if(RR_CAT[g][i].name===name)return RR_CAT[g][i];}}return null;}
    function rrClientOptions(sel){
        var o='<option value=""'+(sel===''?' selected':'')+'>— выберите —</option>';
        o+='<option value="all"'+(sel==='all'?' selected':'')+'>Все клиенты (общие)</option>';
        var opt=function(c){return '<option value="'+rrEsc(c.key)+'"'+(sel===c.key?' selected':'')+'>'+rrEsc(c.label)+'</option>';};
        var pop=RR_CLIENTS.filter(function(c){return c.group==='popular';});
        var oth=RR_CLIENTS.filter(function(c){return c.group!=='popular';});
        if(pop.length){o+='<optgroup label="Популярные">'+pop.map(opt).join('')+'</optgroup>';}
        if(oth.length){o+='<optgroup label="Остальные">'+oth.map(opt).join('')+'</optgroup>';}
        o+='<option value="custom"'+(sel==='custom'?' selected':'')+'>Своё (указать подпись)</option>';
        return o;
    }
    function rrHdrOptions(sel){
        var isCustom=sel && !rrCatFind(sel);
        var o='<option value="">— выберите заголовок —</option>';
        for(var g in RR_CAT){
            o+='<optgroup label="'+rrEsc(g)+'">';
            RR_CAT[g].forEach(function(it){o+='<option value="'+rrEsc(it.name)+'"'+(sel===it.name?' selected':'')+'>'+rrEsc(it.name)+'</option>';});
            o+='</optgroup>';
        }
        o+='<option value="__custom__"'+(isCustom?' selected':'')+'>Другое (вписать вручную)…</option>';
        return o;
    }
    function rrHintHtml(name){var it=rrCatFind(name);if(!it)return '';return '<b>'+rrEsc(it.name)+'</b> — '+rrEsc(it.note)+(it.ex?' · пример: '+rrEsc(it.ex):'');}
    function rrHdrRow(h){
        h=h||{};
        var isCustom=h.key && !rrCatFind(h.key);
        var it=rrCatFind(h.key);
        return '<div class="rr-hrow">'+
            '<select class="rr-hk">'+rrHdrOptions(h.key||'')+'</select>'+
            '<input type="text" class="rr-hv" placeholder="'+rrEsc(it?it.ex:'значение')+'" value="'+rrEsc(h.value||'')+'">'+
            '<button type="button" class="rr-iconbtn del rr-hdel" aria-label="Удалить">×</button>'+
        '</div>'+
        '<input type="text" class="rr-hkc" placeholder="имя заголовка" value="'+(isCustom?rrEsc(h.key):'')+'" style="margin:.1rem 0 .2rem;'+(isCustom?'':'display:none')+'">'+
        '<div class="rr-hint">'+rrHintHtml(h.key||'')+'</div>';
    }
    function rrHdrBlock(headers){
        var rows=(headers||[]).map(rrHdrRow).join('');
        return '<div class="rr-hdrs"><span class="rr-hlbl">Какие заголовки отдавать</span>'+
            '<div class="rr-hlist">'+rows+'</div>'+
            '<button type="button" class="rr-addh">➕ Заголовок</button></div>';
    }
    function rrRuleHtml(r){
        r=r||{};
        var isAll=(r.client==='all');
        return '<div class="rr-rule'+(r.enabled===false?' off':'')+'">'+
            '<div class="rr-top">'+
                '<label class="rr-chk"><input type="checkbox" class="rr-en" '+(r.enabled!==false?'checked':'')+'> вкл</label>'+
                '<input type="text" class="nm rr-name" placeholder="название (необязательно)" value="'+rrEsc(r.name||'')+'">'+
                '<button type="button" class="rr-iconbtn del rr-del" title="Удалить правило">🗑</button>'+
            '</div>'+
            '<div class="rr-fields">'+
                '<div><label>Кому отдать</label><select class="rr-client">'+rrClientOptions(r.client||'')+'</select></div>'+
                '<div class="rr-oswrap" style="'+(isAll?'display:none':'')+'"><label>Платформа</label><select class="rr-os">'+
                    '<option value=""'+(!r.os?' selected':'')+'>Любая</option>'+
                    '<option value="windows"'+(r.os==='windows'?' selected':'')+'>Windows</option>'+
                    '<option value="macos"'+(r.os==='macos'?' selected':'')+'>macOS</option>'+
                    '<option value="linux"'+(r.os==='linux'?' selected':'')+'>Linux</option>'+
                    '<option value="android"'+(r.os==='android'?' selected':'')+'>Android</option>'+
                    '<option value="ios"'+(r.os==='ios'?' selected':'')+'>iOS</option>'+
                '</select></div>'+
            '</div>'+
            '<div class="rr-ua" style="'+(r.client==='custom'?'':'display:none')+'"><label class="rr-hlbl">Подпись (User-Agent содержит)</label><input type="text" class="rr-uacustom" placeholder="например: koala" value="'+rrEsc(r.ua_custom||'')+'"></div>'+
            rrHdrBlock(r.headers)+
        '</div>';
    }
    function rrGroupLabel(c){
        if(c==='all')return 'Все клиенты (общие)';
        if(c==='custom')return 'По своей подписи';
        if(c==='')return 'Не выбрано';
        var f=RR_CLIENTS.filter(function(x){return x.key===c;});return f.length?f[0].label:c;
    }
    function rrGroupOrder(c){
        if(c==='all')return -1;
        if(c==='custom')return 9998;
        if(c==='')return 9999;
        for(var i=0;i<RR_CLIENTS.length;i++)if(RR_CLIENTS[i].key===c)return i;
        return 9997;
    }
    function rrHdrSync(row){
        var sel=row.querySelector('.rr-hk');
        var custom=row.nextElementSibling;
        var hint=custom&&custom.nextElementSibling?custom.nextElementSibling:null;
        var isCustom=(sel.value==='__custom__');
        if(custom)custom.style.display=isCustom?'':'none';
        if(hint)hint.innerHTML=isCustom?'':rrHintHtml(sel.value);
        var v=row.querySelector('.rr-hv');var it=rrCatFind(sel.value);
        if(it&&v)v.setAttribute('placeholder',it.ex||'значение');
    }
    function rrBindHdr(row){
        row.querySelector('.rr-hk').addEventListener('change',function(){rrHdrSync(row);rrSync();});
        row.querySelector('.rr-hdel').addEventListener('click',function(){var c=row.nextElementSibling,hn=c?c.nextElementSibling:null;if(hn)hn.remove();if(c)c.remove();row.remove();rrSync();});
    }
    function rrBind(card){
        card.querySelector('.rr-del').addEventListener('click',function(){card.remove();RR_RULES=rrCollect();rrRender();rrAutoSave('Правило удалено и сохранено');});
        card.querySelector('.rr-addh').addEventListener('click',function(){
            var list=card.querySelector('.rr-hlist');
            list.insertAdjacentHTML('beforeend',rrHdrRow());
            var rows=list.querySelectorAll('.rr-hrow');rrBindHdr(rows[rows.length-1]);rrSync();
        });
        card.querySelector('.rr-client').addEventListener('change',function(){RR_RULES=rrCollect();rrRender();});
        card.querySelectorAll('.rr-hlist .rr-hrow').forEach(rrBindHdr);
    }
    function rrRender(){
        var rules=RR_RULES.slice();
        rules.sort(function(a,b){return rrGroupOrder(a.client||'')-rrGroupOrder(b.client||'');});
        var html='';var lastG=null;
        rules.forEach(function(r){
            var g=r.client||'';
            if(g!==lastG){html+='<div class="rr-group">'+rrEsc(rrGroupLabel(g))+'</div>';lastG=g;}
            html+=rrRuleHtml(r);
        });
        var c=document.getElementById('rrList');
        c.innerHTML=html;
        c.querySelectorAll('.rr-rule').forEach(rrBind);
        rrSync();
    }
    function rrAdd(){RR_RULES=rrCollect();RR_RULES.push({enabled:true,client:'',headers:[]});rrRender();var cards=document.querySelectorAll('#rrList .rr-rule');if(cards.length)cards[cards.length-1].scrollIntoView({behavior:'smooth',block:'center'});}
    function rrCollect(){
        var out=[];
        document.querySelectorAll('#rrList .rr-rule').forEach(function(card){
            var hdrs=[];
            card.querySelectorAll('.rr-hlist .rr-hrow').forEach(function(row){
                var sel=row.querySelector('.rr-hk');var key=sel.value;
                if(key==='__custom__'){var ci=row.nextElementSibling;key=ci?ci.value.trim():'';}
                if(!key||key==='__custom__')return;
                hdrs.push({key:key,value:row.querySelector('.rr-hv').value});
            });
            out.push({
                name:card.querySelector('.rr-name').value.trim(),
                enabled:card.querySelector('.rr-en').checked,
                client:card.querySelector('.rr-client').value,
                os:card.querySelector('.rr-os').value,
                ua_custom:card.querySelector('.rr-uacustom').value.trim(),
                headers:hdrs
            });
            card.classList.toggle('off',!card.querySelector('.rr-en').checked);
        });
        return out;
    }
    function rrSync(){
        document.getElementById('rr_json').value=JSON.stringify(rrCollect());
        document.getElementById('rrEmpty').style.display=document.querySelectorAll('#rrList .rr-rule').length?'none':'';
    }
    function rrAutoSave(msg){
        var body='ajax=1&csrf='+encodeURIComponent(RR_TOKEN)+'&response_rules_json='+encodeURIComponent(document.getElementById('rr_json').value);
        fetch('index.php?ajax=save_rules',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:body})
            .then(function(r){return r.json();})
            .then(function(d){if(window.uiToast)uiToast(d&&d.ok?(msg||'Сохранено'):'Ошибка сохранения');})
            .catch(function(){if(window.uiToast)uiToast('Ошибка сети');});
    }
    function rrTest(){
        var ua=document.getElementById('rrTestUa').value;
        var os=document.getElementById('rrTestOs').value;
        var out=document.getElementById('rrTestOut');
        out.style.display='block';out.innerHTML='Проверяю…';
        var body='ajax=1&csrf='+encodeURIComponent(RR_TOKEN)+'&ua='+encodeURIComponent(ua)+'&os='+encodeURIComponent(os);
        fetch('index.php?ajax=test_rule',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:body})
            .then(function(r){return r.json();})
            .then(function(d){
                if(!d||!d.ok){out.innerHTML='Ошибка проверки';return;}
                var m=(d.matched&&d.matched.length)?d.matched.map(rrEsc).join(', '):'нет правила — уйдут только общие заголовки';
                var hk=Object.keys(d.headers||{});
                var hl=hk.length?hk.map(function(k){return '<div><code>'+rrEsc(k)+': '+rrEsc(d.headers[k])+'</code></div>';}).join(''):'<div class="muted">заголовков нет</div>';
                out.innerHTML='<div>Сработает: <b>'+m+'</b></div><div style="margin-top:.5rem">Клиент получит:</div>'+hl;
            }).catch(function(){out.innerHTML='Ошибка сети';});
    }
    document.getElementById('rrForm').addEventListener('input',rrSync);
    document.getElementById('rrForm').addEventListener('submit',rrSync);
    rrRender();
    </script>
