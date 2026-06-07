<?php
$c_preset = chat_widget_preset();
$c_pos    = chat_widget_position();
$c_color  = chat_widget_color();
?>
    <style>
        .cb-presets{display:flex;gap:.7rem;flex-wrap:wrap;margin:.3rem 0}
        .cb-pre{flex:1 1 140px;border:2px solid var(--line);border-radius:12px;padding:.8rem;cursor:pointer;background:var(--bg2);text-align:center;transition:border-color .15s}
        .cb-pre.sel{border-color:var(--accent)}
        .cb-pre input{display:none}
        .cb-pre .cb-demo{height:54px;display:flex;align-items:flex-end;justify-content:center;margin-bottom:.5rem}
        .cb-bubble{width:46px;height:46px;border-radius:50%;background:var(--accent);display:flex;align-items:center;justify-content:center;color:#fff}
        .cb-pill{padding:.6rem 1rem;border-radius:24px;background:var(--accent);color:#fff;font-size:.82rem;font-weight:600}
        .cb-bar{padding:.6rem 1.2rem;border-radius:12px 12px 0 0;background:var(--accent);color:#fff;font-size:.82rem;font-weight:600}
        .cb-pre .cb-lbl{font-size:.8rem;color:var(--muted)}
        .cb-chat{display:grid;grid-template-columns:300px 1fr;gap:0;border:1px solid var(--line);border-radius:12px;overflow:hidden;height:560px}
        @media(max-width:760px){.cb-chat{grid-template-columns:1fr;height:auto}}
        .cb-list{border-right:1px solid var(--line);overflow-y:auto;background:var(--bg2)}
        .cb-sess{padding:.7rem .85rem;border-bottom:1px solid var(--line);cursor:pointer}
        .cb-sess:hover{background:var(--hover)}
        .cb-sess.sel{background:var(--hover2)}
        .cb-sess .cb-st{display:flex;justify-content:space-between;gap:.5rem;align-items:center}
        .cb-sess .cb-sn{font-weight:600;font-size:.86rem;color:var(--text-strong)}
        .cb-sess .cb-sp{font-size:.78rem;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:100%}
        .cb-unread{background:var(--red);color:#fff;border-radius:10px;font-size:.7rem;padding:0 6px;min-width:18px;text-align:center}
        .cb-conv{display:flex;flex-direction:column;min-height:0}
        .cb-chead{display:flex;align-items:center;justify-content:space-between;gap:.5rem;padding:.55rem .8rem;border-bottom:1px solid var(--line);background:var(--card)}
        .cb-chead .cb-hn{font-weight:600;color:var(--text-strong);font-size:.9rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        .cb-msgs{flex:1;overflow-y:auto;padding:1rem;display:flex;flex-direction:column;gap:.45rem;background:var(--bg)}
        .cb-m{max-width:78%;padding:.5rem .75rem;border-radius:12px;font-size:.88rem;line-height:1.4;white-space:pre-wrap;word-break:break-word}
        .cb-m.visitor{align-self:flex-start;background:var(--bg2);border:1px solid var(--line);color:var(--text-strong)}
        .cb-m.agent{align-self:flex-end;background:var(--accent);color:#fff}
        .cb-m.system{align-self:center;background:transparent;color:var(--muted);font-size:.78rem}
        .cb-m .cb-src{font-size:.66rem;opacity:.7;margin-top:.15rem}
        .cb-reply{display:flex;gap:.5rem;padding:.7rem;border-top:1px solid var(--line);background:var(--card)}
        .cb-reply textarea{flex:1;border:1px solid var(--line);border-radius:10px;padding:.55rem .8rem;font-family:inherit;font-size:.9rem;resize:none;background:var(--bg2);color:var(--text)}
        .cb-empty{display:flex;align-items:center;justify-content:center;height:100%;color:var(--muted);font-size:.9rem}
    </style>

    <div class="card">
        <div class="loghead">
            <h2 style="margin-top:0">Диалоги <span id="cbAuto" class="muted" style="font-weight:400;font-size:.76rem"></span></h2>
            <div class="loghead-r"><button type="button" class="btn ghost" onclick="cbLoadSessions()">🔄 Обновить</button></div>
        </div>
        <div class="cb-chat">
            <div class="cb-list" id="cbList"></div>
            <div class="cb-conv">
                <div class="cb-chead" id="cbHead" style="display:none">
                    <span class="cb-hn" id="cbHeadName"></span>
                    <button type="button" class="btn ghost" onclick="cbDelete()">🗑 Удалить чат</button>
                </div>
                <div class="cb-msgs" id="cbMsgs"><div class="cb-empty">Выберите диалог слева</div></div>
                <div class="cb-reply" style="display:none" id="cbReplyBox">
                    <textarea id="cbReply" rows="1" placeholder="Ответ клиенту…"></textarea>
                    <button class="btn" type="button" onclick="cbSend()">Отправить</button>
                </div>
            </div>
        </div>
    </div>

    <section class="coll collapsed" data-coll="chat_settings">
        <button type="button" class="coll-head" onclick="collToggle(this)">Настройки чата поддержки
            <svg class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
        </button>
        <div class="coll-body">
        <p class="muted" style="margin-bottom:.6rem">Встроенный в страницу-заглушку чат. Сообщения посетителей переотправляются в Telegram и/или на вебхук; отвечать можно отсюда, из Telegram (ответом на пересланное сообщение) или через вебхук.</p>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= h($token) ?>">
            <input type="hidden" name="action" value="save_chat_cfg">

            <div class="set-row">
                <div class="set-info"><div class="set-t">Включить чат на сайте</div><div class="set-d">Показывать окно чата на странице-заглушке.</div></div>
                <label class="switch"><input type="checkbox" name="chat_enabled" <?= chat_enabled()?'checked':'' ?>><span class="sl"></span></label>
            </div>

            <div class="row">
                <div><label>Имя агента поддержки</label><input type="text" name="chat_agent_name" value="<?= h(chat_agent_name()) ?>" placeholder="Поддержка"></div>
                <div><label>Фото агента (URL)</label><input type="text" name="chat_agent_photo" value="<?= h(chat_agent_photo()) ?>" placeholder="https://…/avatar.png"></div>
            </div>
            <div class="row">
                <div><label>Приветствие</label><input type="text" name="chat_greeting" value="<?= h(chat_greeting()) ?>" placeholder="Здравствуйте! Чем можем помочь?"></div>
                <div><label>Интервал опроса, сек</label><input type="number" min="2" max="30" name="chat_poll_interval" value="<?= (int) chat_poll_interval() ?>"></div>
            </div>

            <label style="display:block;margin:.4rem 0 .2rem">Вид свёрнутого окна</label>
            <div class="cb-presets">
                <label class="cb-pre <?= $c_preset===1?'sel':'' ?>" data-pre="1"><input type="radio" name="chat_widget_preset" value="1" <?= $c_preset===1?'checked':'' ?>><div class="cb-demo"><span class="cb-bubble"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 11.5a8.5 8.5 0 0 1-8.5 8.3 8.4 8.4 0 0 1-3.8-.9L3 21l1.9-5.7a8.5 8.5 0 1 1 16.1-3.8z"/></svg></span></div><div class="cb-lbl">Круглый пузырь</div></label>
                <label class="cb-pre <?= $c_preset===2?'sel':'' ?>" data-pre="2"><input type="radio" name="chat_widget_preset" value="2" <?= $c_preset===2?'checked':'' ?>><div class="cb-demo"><span class="cb-pill">💬 Напишите нам</span></div><div class="cb-lbl">Пилюля с текстом</div></label>
                <label class="cb-pre <?= $c_preset===3?'sel':'' ?>" data-pre="3"><input type="radio" name="chat_widget_preset" value="3" <?= $c_preset===3?'checked':'' ?>><div class="cb-demo"><span class="cb-bar">💬 Напишите нам</span></div><div class="cb-lbl">Бар снизу</div></label>
            </div>

            <div class="row">
                <div><label>Текст на кнопке (пресеты 2–3)</label><input type="text" name="chat_widget_text" value="<?= h(chat_widget_text()) ?>" placeholder="Напишите нам"></div>
                <div><label>Цвет</label><input type="color" name="chat_widget_color" value="<?= h($c_color) ?>" style="height:42px;padding:.2rem"></div>
            </div>
            <div class="set-row">
                <div class="set-info"><div class="set-t">Положение</div><div class="set-d">С какой стороны экрана показывать кнопку.</div></div>
                <select name="chat_widget_position">
                    <option value="right" <?= $c_pos==='right'?'selected':'' ?>>Справа</option>
                    <option value="left" <?= $c_pos==='left'?'selected':'' ?>>Слева</option>
                </select>
            </div>

            <hr style="border:0;border-top:1px solid var(--line);margin:1.2rem 0">

            <div class="set-row">
                <div class="set-info"><div class="set-t">Переотправка в Telegram</div><div class="set-d">Отдельный бот. Оператор отвечает в TG <b>ответом</b> на пересланное сообщение (или командой <code>/r &lt;id&gt; текст</code>).</div></div>
                <label class="switch"><input type="checkbox" name="chat_tg_enabled" <?= chat_tg_enabled()?'checked':'' ?>><span class="sl"></span></label>
            </div>
            <div class="warn" style="margin:.2rem 0 .6rem">⚠️ <b>После подключения бота обязательно активируйте вебхук</b> — иначе ответы оператора из Telegram не дойдут до сайта. Достаточно <b>сохранить настройки</b> с включённым TG (вебхук поставится сам) или нажать «📡 Установить вебхук бота», затем проверить «ℹ️ Статус вебхука».</div>
            <div class="row">
                <div><label>Токен бота</label><input type="password" name="chat_tg_bot_token" value="" placeholder="<?= chat_tg_token()?'•••••• задан':'123456:ABC…' ?>"></div>
                <div><label>Chat ID оператора / группы</label><input type="text" name="chat_tg_chat_id" value="<?= h(chat_tg_chat_id()) ?>" placeholder="123456789 или -100…"></div>
            </div>
            <div class="row">
                <div><label>Базовый URL Telegram API <span class="hint">пусто = api.telegram.org; укажите свой reverse-proxy, если Telegram заблокирован на сервере зеркала</span></label><input type="text" name="chat_tg_api_base" value="<?= h(trim((string) setting('chat_tg_api_base',''))) ?>" placeholder="https://api.telegram.org (по умолчанию)"></div>
            </div>
            <div style="display:flex;gap:.6rem;flex-wrap:wrap;margin:.2rem 0 .2rem">
                <button type="button" class="btn ghost" onclick="cbTgCheck()">🔌 Проверить доступ TG</button>
                <button type="button" class="btn ghost" onclick="cbSetWh()">📡 Установить вебхук бота</button>
                <button type="button" class="btn ghost" onclick="cbWhInfo()">ℹ️ Статус вебхука</button>
                <button type="button" class="btn ghost" onclick="cbDelWh()">🧹 Удалить вебхук</button>
            </div>
            <div id="cbTgRes" class="muted" style="font-size:.84rem;min-height:1.2rem"></div>
            <p class="muted" style="font-size:.8rem">Вебхук бота: <code><?= h(chat_tg_webhook_url()) ?></code> — нужен, чтобы ответы из TG доходили до сайта. При сохранении настроек с включённым TG он ставится автоматически; если ответы не приходят, нажмите «Статус вебхука».</p>

            <hr style="border:0;border-top:1px solid var(--line);margin:1.2rem 0">

            <div class="set-row">
                <div class="set-info"><div class="set-t">Вебхук-fallback (если TG заблокирован)</div><div class="set-d">Двусторонний: новые сообщения POST-ятся на ваш URL с подписью; ответы агента принимаются на входящий endpoint.</div></div>
                <label class="switch"><input type="checkbox" name="chat_webhook_enabled" <?= chat_webhook_enabled()?'checked':'' ?>><span class="sl"></span></label>
            </div>
            <div class="row">
                <div><label>Исходящий URL (куда слать сообщения)</label><input type="text" name="chat_webhook_url" value="<?= h(chat_webhook_url()) ?>" placeholder="https://your-endpoint/chat"></div>
                <div><label>Секрет исходящих (HMAC)</label><input type="password" name="chat_webhook_secret" value="" placeholder="<?= chat_webhook_secret()?'•••••• задан':'не задан' ?>"></div>
            </div>
            <p class="muted" style="font-size:.8rem">Входящий endpoint (для ответов агента): <code><?= h(chat_inbound_url()) ?></code><br>
            Заголовок подписи входящих: <code>X-Chat-Signature: sha256=HMAC(body, входящий_секрет)</code>. Входящий секрет: <code><?= h(chat_inbound_secret()) ?></code></p>

            <div style="margin-top:1.25rem"><button type="submit">💾 Сохранить настройки чата</button></div>
        </form>
        </div>
    </section>

    <script>
    var CB_CSRF = <?= json_encode($token) ?>;
    var CB_SESS = <?= json_encode($chat_sessions, JSON_UNESCAPED_UNICODE) ?>;
    var cbCur = 0, cbLast = 0, cbPoll = null, cbSeen = {}, cbBusy = false;
    function cbEsc(s){var d=document.createElement('div');d.textContent=(s==null?'':s);return d.innerHTML;}
    function cbLocal(ep){ep=parseInt(ep,10);if(!ep)return '';var d=new Date(ep*1000);function p(n){return(n<10?'0':'')+n;}return p(d.getHours())+':'+p(d.getMinutes());}
    document.querySelectorAll('.cb-pre').forEach(function(el){el.addEventListener('click',function(){document.querySelectorAll('.cb-pre').forEach(function(x){x.classList.remove('sel');});el.classList.add('sel');});});
    function cbRenderList(rows){
        var box=document.getElementById('cbList');
        if(!rows||!rows.length){box.innerHTML='<div class="cb-empty" style="height:80px">Пока нет диалогов</div>';return;}
        box.innerHTML=rows.map(function(s){
            var nm=s.name||('IP '+(s.ip||'—'));
            var pv=(s.last_body||'').slice(0,46);
            var un=parseInt(s.unread_agent,10)||0;
            return '<div class="cb-sess'+(s.id==cbCur?' sel':'')+'" data-id="'+s.id+'" onclick="cbOpen('+s.id+')">'+
                   '<div class="cb-st"><span class="cb-sn">'+cbEsc(nm)+'</span>'+(un?'<span class="cb-unread">'+un+'</span>':'<span class="cb-sp">'+cbLocal(s.last_ts)+'</span>')+'</div>'+
                   '<div class="cb-sp">'+cbEsc(pv)+'</div></div>';
        }).join('');
    }
    function cbLoadSessions(){
        var a=document.getElementById('cbAuto');
        fetch('?ajax=chat_sessions',{credentials:'same-origin'}).then(function(r){return r.json();}).then(function(d){
            if(d.ok){CB_SESS=d.sessions||[];cbRenderList(CB_SESS);}
            if(a)a.textContent='· обновлено '+new Date().toLocaleTimeString();
        }).catch(function(){});
    }
    function cbAddMsg(m){
        if(m.id && cbSeen[m.id]) return;
        if(m.id) cbSeen[m.id]=1;
        var box=document.getElementById('cbMsgs');
        var el=document.createElement('div');
        el.className='cb-m '+m.sender;
        var src=(m.source&&m.sender==='agent'&&m.source!=='admin')?('<div class="cb-src">via '+cbEsc(m.source)+'</div>'):'';
        el.innerHTML=cbEsc(m.body)+src;
        box.appendChild(el);box.scrollTop=box.scrollHeight;
        if(m.id>cbLast)cbLast=m.id;
    }
    function cbOpen(id){
        cbCur=id;cbLast=0;cbSeen={};
        document.querySelectorAll('.cb-sess').forEach(function(x){x.classList.toggle('sel',x.getAttribute('data-id')==id);});
        var s=(CB_SESS||[]).filter(function(x){return x.id==id;})[0];
        document.getElementById('cbHeadName').textContent=s?(s.name||('IP '+(s.ip||'—'))):('#'+id);
        document.getElementById('cbHead').style.display='flex';
        var box=document.getElementById('cbMsgs');box.innerHTML='';
        document.getElementById('cbReplyBox').style.display='flex';
        fetch('?ajax=chat_msgs&sid='+id+'&after=0',{credentials:'same-origin'}).then(function(r){return r.json();}).then(function(d){
            if(d.ok)(d.messages||[]).forEach(cbAddMsg);
        });
    }
    function cbDelete(){
        if(!cbCur)return;
        var sid=cbCur;
        uiConfirm('Удалить чат? Он исчезнет и у клиента на сайте.',function(){
            var fd=new URLSearchParams();fd.set('csrf',CB_CSRF);fd.set('sid',sid);
            fetch('?ajax=chat_delete',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:fd.toString()})
                .then(function(r){return r.json();}).then(function(){
                    cbCur=0;cbLast=0;cbSeen={};
                    document.getElementById('cbMsgs').innerHTML='<div class="cb-empty">Выберите диалог слева</div>';
                    document.getElementById('cbReplyBox').style.display='none';
                    document.getElementById('cbHead').style.display='none';
                    cbLoadSessions();
                });
        },'Удалить',true);
    }
    function cbPollMsgs(){
        if(!cbCur||cbBusy)return; cbBusy=true;
        fetch('?ajax=chat_msgs&sid='+cbCur+'&after='+cbLast,{credentials:'same-origin'}).then(function(r){return r.json();}).then(function(d){
            cbBusy=false; if(d.ok)(d.messages||[]).forEach(cbAddMsg);
        }).catch(function(){cbBusy=false;});
    }
    function cbSend(){
        var ta=document.getElementById('cbReply');var t=ta.value.trim();if(!t||!cbCur)return;
        ta.value='';
        var fd=new URLSearchParams();fd.set('csrf',CB_CSRF);fd.set('sid',cbCur);fd.set('body',t);
        fetch('?ajax=chat_reply',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:fd.toString()})
            .then(function(r){return r.json();}).then(function(){cbPollMsgs();cbLoadSessions();});
    }
    function cbTgApi(act,extra,cb){
        var fd=new URLSearchParams();fd.set('csrf',CB_CSRF);if(extra)Object.keys(extra).forEach(function(k){fd.set(k,extra[k]);});
        return fetch('?ajax='+act,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:fd.toString()}).then(function(r){return r.json();}).then(cb);
    }
    function cbTgCheck(){
        var tok=document.querySelector('[name=chat_tg_bot_token]').value.trim();
        document.getElementById('cbTgRes').textContent='проверяю…';
        cbTgApi('chat_check',{token:tok},function(d){
            var el=document.getElementById('cbTgRes');
            if(d.ok)el.innerHTML='✅ бот @'+cbEsc(d.username||'')+' доступен';
            else el.innerHTML='❌ '+cbEsc(d.error||'недоступно')+(d.reachable===false?' (TG похоже заблокирован — используйте вебхук)':'');
        });
    }
    function cbSetWh(){document.getElementById('cbTgRes').textContent='устанавливаю вебхук…';cbTgApi('chat_setwh',{},function(d){document.getElementById('cbTgRes').innerHTML=d.ok?'✅ вебхук установлен':'❌ '+cbEsc(d.error||'ошибка');});}
    function cbDelWh(){cbTgApi('chat_delwh',{},function(d){document.getElementById('cbTgRes').innerHTML=d.ok?'🧹 вебхук удалён':'❌ '+cbEsc(d.error||'ошибка');});}
    function cbWhInfo(){
        document.getElementById('cbTgRes').textContent='запрашиваю статус…';
        cbTgApi('chat_whinfo',{},function(d){
            var el=document.getElementById('cbTgRes');
            if(!d.ok){el.innerHTML='❌ '+cbEsc(d.error||'ошибка');return;}
            var i=d.info||{};
            var url=i.url||'';
            var parts=[];
            parts.push(url?('URL: <code>'+cbEsc(url)+'</code>'):'<b>вебхук не установлен</b> — нажмите «Установить вебхук бота»');
            if(typeof i.pending_update_count!=='undefined')parts.push('в очереди: '+i.pending_update_count);
            if(i.last_error_message)parts.push('❌ последняя ошибка: '+cbEsc(i.last_error_message));
            else if(url)parts.push('✅ ошибок нет');
            el.innerHTML=parts.join(' · ');
        });
    }
    document.getElementById('cbReply').addEventListener('keydown',function(e){if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();cbSend();}});
    cbRenderList(CB_SESS);
    cbPoll=setInterval(function(){if(!document.hidden){cbPollMsgs();cbLoadSessions();}},5000);
    </script>
