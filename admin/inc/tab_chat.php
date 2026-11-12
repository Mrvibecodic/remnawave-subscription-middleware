<?php
$c_preset = chat_widget_preset();
$c_pos    = chat_widget_position();
$c_color  = chat_widget_color();
$c_design = chat_embed_design();
$c_sites  = chat_embed_sites();
$c_spd    = chat_subpage_design();
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
        .cs-grid{display:grid;grid-template-columns:minmax(0,1fr);gap:1rem;align-items:start;margin-top:.9rem}
        @media(min-width:1200px){.cs-grid{grid-template-columns:repeat(2,minmax(0,1fr));grid-template-rows:auto 1fr auto;grid-template-areas:"w tg" "fb tg" "ex ex"}.cs-g-w{grid-area:w}.cs-g-tg{grid-area:tg}.cs-g-fb{grid-area:fb}.cs-g-ex{grid-area:ex}}
        .cs-grp{border:1px solid var(--line);border-radius:12px;background:var(--bg2);padding:1rem 1.1rem 1.1rem;min-width:0}
        .cs-h{display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;padding-bottom:.8rem;margin-bottom:.9rem;border-bottom:1px solid var(--line)}
        .cs-h .switch{margin-top:.15rem}
        .cs-t{font-size:.95rem;font-weight:600;color:var(--text-strong)}
        .cs-b>*{margin-top:0;margin-bottom:0}
        .cs-b>*+*{margin-top:.85rem}
        .cs-b>[hidden]+*{margin-top:0}
        .cs-b .cb-presets{margin:.35rem 0 0}
        .cs-b .row>.cs-num{flex:0 0 180px;min-width:0}
        .cs-b>#cbTgRes{margin-top:.45rem}
        .cs-b .cb-pill,.cs-b .cb-bar{white-space:nowrap}
        .cs-b .row>.cs-color{flex:0 0 auto;min-width:0}
        .cs-color input{width:56px;height:42px;padding:.2rem}
        .cs-b .set-row>select{flex:0 0 auto;width:180px;margin:0}
        @media(max-width:560px){.cs-b .set-row>select{width:100%}}
        .cs-btns{display:flex;gap:.6rem;flex-wrap:wrap}
        .cs-note{font-size:.8rem}
        .cs-ex{display:grid;grid-template-columns:minmax(0,1fr);gap:.85rem 1.4rem}
        @media(min-width:900px){.cs-ex{grid-template-columns:minmax(0,5fr) minmax(0,6fr)}}
        .cs-ex>div>*{margin-top:0;margin-bottom:0}
        .cs-ex>div>*+*{margin-top:.85rem}
        .cs-ex label{margin:0 0 .3rem}
        .cs-ex textarea{min-height:76px;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:.82rem}
        .cs-ex .codeblk{margin:0}
        .cs-ex .codeblk pre{white-space:pre-wrap;word-break:break-all}
        @media(max-width:560px){.cs-b .set-row.cs-sp{flex-wrap:wrap}.cs-b .set-row.cs-sp>.set-info{flex:1 1 100%}.cs-b .set-row.cs-sp>select{flex:1 1 auto;width:auto}}
        .cd-pre{flex:1 1 150px;min-width:0}
        .cd-th{height:126px;border-radius:10px;overflow:hidden;display:flex;flex-direction:column;margin-bottom:.55rem}
        .cd-th i{display:block;box-sizing:border-box}
        .cd-th .cd-hd{display:flex;align-items:center;gap:6px;padding:7px 8px}
        .cd-av{width:16px;height:16px;flex:none}
        .cd-nm{width:46px;height:5px;border-radius:3px}
        .cd-th .cd-bd{flex:1;display:flex;flex-direction:column;gap:5px;padding:8px}
        .cd-a,.cd-v{height:14px}
        .cd-a{width:58%}
        .cd-v{width:46%;align-self:flex-end}
        .cd-th .cd-ft{display:flex;gap:5px;padding:6px 8px}
        .cd-in{flex:1;height:13px}
        .cd-sn{width:13px;height:13px;flex:none}
        .cd-rw{background-color:#161b23;background-image:linear-gradient(to right,rgba(128,128,128,.08) 1px,transparent 1px),linear-gradient(to bottom,rgba(128,128,128,.08) 1px,transparent 1px);background-size:20px 20px}
        .cd-rw .cd-hd{border-bottom:1px solid rgba(255,255,255,.07)}
        .cd-rw .cd-av{border-radius:5px;background:rgba(34,211,238,.18);border:1px solid rgba(34,211,238,.4)}
        .cd-rw .cd-nm{background:#fff}
        .cd-rw .cd-a,.cd-rw .cd-v{border-radius:5px}
        .cd-rw .cd-a{background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.12)}
        .cd-rw .cd-v{background:rgba(34,211,238,.12);border:1px solid rgba(34,211,238,.32)}
        .cd-rw .cd-ft{border-top:1px solid rgba(255,255,255,.07)}
        .cd-rw .cd-in{border-radius:5px;border:1px solid rgba(255,255,255,.12)}
        .cd-rw .cd-sn{border-radius:5px;border:1px solid #3bc9db}
        .cd-lk{background:radial-gradient(circle at 0 0,rgba(91,140,255,.42),transparent 55%),radial-gradient(circle at 100% 100%,rgba(157,123,255,.36),transparent 55%),#0b0f17}
        .cd-lk .cd-hd{border-bottom:1px solid rgba(255,255,255,.1);background:rgba(255,255,255,.05)}
        .cd-lk .cd-av{border-radius:50%;background:linear-gradient(120deg,#5b8cff,#9d7bff)}
        .cd-lk .cd-nm{background:#eef2f8}
        .cd-lk .cd-a{border-radius:7px 7px 7px 3px;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.12)}
        .cd-lk .cd-v{border-radius:7px 7px 3px 7px;background:linear-gradient(120deg,#5b8cff,#9d7bff)}
        .cd-lk .cd-ft{border-top:1px solid rgba(255,255,255,.1)}
        .cd-lk .cd-in{border-radius:5px;background:rgba(0,0,0,.3);border:1px solid rgba(255,255,255,.1)}
        .cd-lk .cd-sn{border-radius:5px;background:linear-gradient(120deg,#5b8cff,#9d7bff)}
        .cd-lkmin{background:#f4f5f7}
        .cd-lkmin .cd-hd{border-bottom:1px solid #e4e7ec}
        .cd-lkmin .cd-av{border-radius:5px;background:#2f6bff}
        .cd-lkmin .cd-nm{background:#0f1115}
        .cd-lkmin .cd-a{border-radius:7px 7px 7px 3px;background:#fff;border:1px solid #e4e7ec}
        .cd-lkmin .cd-v{border-radius:7px 7px 3px 7px;background:#2f6bff}
        .cd-lkmin .cd-ft{border-top:1px solid #e4e7ec}
        .cd-lkmin .cd-in{border-radius:5px;background:#eef0f3}
        .cd-lkmin .cd-sn{border-radius:5px;background:#2f6bff}
        .cb-site{color:var(--accent-text)}
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
        .cb-reply textarea{flex:1;border:1px solid var(--line);border-radius:10px;padding:.55rem .8rem;resize:none}
        .cb-empty{display:flex;align-items:center;justify-content:center;height:100%;color:var(--muted);font-size:.9rem}
        .cb-reply textarea{min-height:40px;max-height:140px;overflow-y:auto}
        .cb-back{display:none}
        .cb-hl{display:flex;align-items:center;gap:.4rem;min-width:0}
        @media(max-width:760px){
            .cb-chat{display:block;height:auto;border-radius:12px}
            .cb-list{border-right:0;max-height:none;overflow:visible}
            .cb-sess{padding:.85rem .9rem}
            .cb-sess:last-child{border-bottom:0}
            .cb-sess .cb-sn{font-size:.95rem}
            .cb-sess .cb-sp{font-size:.82rem}
            .cb-conv{display:none}
            .cb-chat.cb-open .cb-conv{display:flex;position:fixed;left:0;right:0;top:0;height:100vh;height:100dvh;z-index:90;background:var(--bg)}
            .cb-chat.cb-open .cb-chead{padding:calc(.45rem + env(safe-area-inset-top)) max(.6rem,env(safe-area-inset-right)) .45rem max(.4rem,env(safe-area-inset-left));min-height:56px}
            .cb-back{display:inline-flex;align-items:center;justify-content:center;flex:0 0 auto;width:40px;height:40px;padding:0;border:0;background:none;color:var(--text-strong);cursor:pointer}
            .cb-back:hover,.cb-back:active{background:var(--hover);filter:none;transform:none;border-radius:10px}
            .cb-chead .cb-hn{font-size:1rem}
            .cb-del .cb-dt{display:none}
            .cb-msgs{padding:.8rem max(.7rem,env(safe-area-inset-right)) .8rem max(.7rem,env(safe-area-inset-left));overscroll-behavior:contain}
            .cb-m{max-width:86%;font-size:.95rem}
            .cb-reply{padding:.55rem max(.6rem,env(safe-area-inset-right)) calc(.55rem + env(safe-area-inset-bottom)) max(.6rem,env(safe-area-inset-left));align-items:flex-end}
            .cb-reply .btn{min-height:40px}
            html:has(.cb-chat.cb-open){overflow:hidden}
        }
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
                    <div class="cb-hl"><button type="button" class="cb-back" onclick="cbBack()" aria-label="К списку диалогов"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg></button><span class="cb-hn" id="cbHeadName"></span></div>
                    <button type="button" class="btn ghost cb-del" onclick="cbDelete()" aria-label="Удалить чат">🗑<span class="cb-dt"> Удалить чат</span></button>
                </div>
                <div class="cb-msgs" id="cbMsgs"><div class="cb-empty">Выберите диалог слева</div></div>
                <div class="cb-reply" style="display:none" id="cbReplyBox">
                    <textarea id="cbReply" rows="1" placeholder="Ответ клиенту…"></textarea>
                    <button class="btn" type="button" onclick="cbSend()">Отправить</button>
                </div>
            </div>
        </div>
    </div>

    <section class="<?= coll_cls('chat_settings', true) ?>" data-coll="chat_settings">
        <button type="button" class="coll-head" onclick="collToggle(this)">Настройки чата поддержки
            <svg width="30" height="30" class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
        </button>
        <div class="coll-body">
        <p class="muted">Встроенный в страницу-заглушку чат. Сообщения посетителей переотправляются в Telegram и/или на вебхук; отвечать можно отсюда, из Telegram (ответом на пересланное сообщение) или через вебхук.</p>
        <form method="post" data-autosave>
            <input type="hidden" name="csrf" value="<?= h($token) ?>">
            <input type="hidden" name="action" value="save_chat_cfg">

            <div class="cs-grid">
            <div class="cs-grp cs-g-w">
                <div class="cs-h">
                    <div class="set-info"><div class="cs-t">Включить чат на сайте</div><div class="set-d">Показывать окно чата на странице-заглушке.</div></div>
                    <label class="switch"><input type="checkbox" name="chat_enabled" <?= chat_enabled()?'checked':'' ?>><span class="sl"></span></label>
                </div>
                <div class="cs-b">
                    <div class="row">
                        <div><label>Имя агента поддержки</label><input type="text" name="chat_agent_name" value="<?= h(chat_agent_name()) ?>" placeholder="Поддержка"></div>
                        <div><label>Фото агента (URL)</label><input type="text" name="chat_agent_photo" value="<?= h(chat_agent_photo()) ?>" placeholder="https://…/avatar.png"></div>
                    </div>
                    <div class="row">
                        <div><label>Приветствие</label><input type="text" name="chat_greeting" value="<?= h(chat_greeting()) ?>" placeholder="Здравствуйте! Чем можем помочь?"></div>
                        <div class="cs-num"><label>Интервал опроса, сек</label><input type="number" min="2" max="30" name="chat_poll_interval" value="<?= (int) chat_poll_interval() ?>"></div>
                    </div>
                    <div>
                        <label>Вид свёрнутого окна</label>
                        <div class="cb-presets">
                            <label class="cb-pre <?= $c_preset===1?'sel':'' ?>" data-pre="1"><input type="radio" name="chat_widget_preset" value="1" <?= $c_preset===1?'checked':'' ?>><div class="cb-demo"><span class="cb-bubble"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 11.5a8.5 8.5 0 0 1-8.5 8.3 8.4 8.4 0 0 1-3.8-.9L3 21l1.9-5.7a8.5 8.5 0 1 1 16.1-3.8z"/></svg></span></div><div class="cb-lbl">Круглый пузырь</div></label>
                            <label class="cb-pre <?= $c_preset===2?'sel':'' ?>" data-pre="2"><input type="radio" name="chat_widget_preset" value="2" <?= $c_preset===2?'checked':'' ?>><div class="cb-demo"><span class="cb-pill">💬 Напишите нам</span></div><div class="cb-lbl">Пилюля с текстом</div></label>
                            <label class="cb-pre <?= $c_preset===3?'sel':'' ?>" data-pre="3"><input type="radio" name="chat_widget_preset" value="3" <?= $c_preset===3?'checked':'' ?>><div class="cb-demo"><span class="cb-bar">💬 Напишите нам</span></div><div class="cb-lbl">Бар снизу</div></label>
                        </div>
                    </div>
                    <div class="row">
                        <div><label>Текст на кнопке (пресеты 2–3)</label><input type="text" name="chat_widget_text" value="<?= h(chat_widget_text()) ?>" placeholder="Напишите нам"></div>
                        <div class="cs-color"><label>Цвет</label><input type="color" name="chat_widget_color" value="<?= h($c_color) ?>"></div>
                    </div>
                    <div class="set-row">
                        <div class="set-info"><div class="set-t">Положение</div><div class="set-d">С какой стороны экрана показывать кнопку.</div></div>
                        <select name="chat_widget_position">
                            <option value="right" <?= $c_pos==='right'?'selected':'' ?>>Справа</option>
                            <option value="left" <?= $c_pos==='left'?'selected':'' ?>>Слева</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="cs-grp cs-g-tg">
                <div class="cs-h">
                    <div class="set-info"><div class="cs-t">Переотправка в Telegram</div><div class="set-d">Отдельный бот. Оператор отвечает в TG <b>ответом</b> на пересланное сообщение (или командой <code>/r &lt;id&gt; текст</code>).</div></div>
                    <label class="switch"><input type="checkbox" name="chat_tg_enabled" <?= chat_tg_enabled()?'checked':'' ?>><span class="sl"></span></label>
                </div>
                <div class="cs-b">
                    <div class="warn" id="cbWhWarn"<?= chat_tg_hook_ok() ? ' hidden' : '' ?>>⚠️ <b>После подключения бота обязательно активируйте вебхук</b> — иначе ответы оператора из Telegram не дойдут до сайта. Достаточно <b>сохранить настройки</b> с включённым TG (вебхук поставится сам) или нажать «📡 Установить вебхук бота», затем проверить «ℹ️ Статус вебхука».</div>
                    <div class="row">
                        <div><label>Токен бота</label><input type="password" name="chat_tg_bot_token" value="" placeholder="<?= chat_tg_token()?'•••••• задан':'123456:ABC…' ?>"></div>
                        <div><label>Chat ID оператора / группы</label><input type="text" name="chat_tg_chat_id" value="<?= h(chat_tg_chat_id()) ?>" placeholder="123456789 или -100…"></div>
                    </div>
                    <div><label>Базовый URL Telegram API <span class="hint">пусто — используется api.telegram.org. Свой reverse-proxy нужен, только если Telegram заблокирован на сервере зеркала</span></label><input type="text" name="chat_tg_api_base" value="<?= h(trim((string) setting('chat_tg_api_base',''))) ?>" placeholder="https://api.telegram.org (по умолчанию)"></div>
                    <div><label>Адрес для вебхука бота <span class="hint">пусто — домен прослойки. Другой сервер нужен, только если Telegram не может достучаться до сервера прослойки и ответы оператора не доходят</span></label><input type="text" name="chat_tg_hook_base" value="<?= h(trim((string) setting('chat_tg_hook_base',''))) ?>" placeholder="домен прослойки (по умолчанию)"></div>
                    <div class="cs-btns">
                        <button type="button" class="btn ghost" onclick="cbTgCheck()">🔌 Проверить доступ TG</button>
                        <button type="button" class="btn ghost" onclick="cbSetWh()">📡 Установить вебхук бота</button>
                        <button type="button" class="btn ghost" onclick="cbWhInfo()">ℹ️ Статус вебхука</button>
                        <button type="button" class="btn ghost" onclick="cbDelWh()">🧹 Удалить вебхук</button>
                    </div>
                    <div id="cbTgRes" class="muted" style="font-size:.84rem;min-height:1.2rem"></div>
                    <p class="muted cs-note">Вебхук бота: <code><?= h(chat_tg_webhook_url()) ?></code> — нужен, чтобы ответы из TG доходили до сайта. При сохранении настроек с включённым TG он ставится автоматически; если ответы не приходят, нажмите «Статус вебхука».</p>
                </div>
            </div>

            <div class="cs-grp cs-g-fb">
                <div class="cs-h">
                    <div class="set-info"><div class="cs-t">Вебхук-fallback (если TG заблокирован)</div><div class="set-d">Двусторонний: новые сообщения POST-ятся на ваш URL с подписью; ответы агента принимаются на входящий endpoint.</div></div>
                    <label class="switch"><input type="checkbox" name="chat_webhook_enabled" <?= chat_webhook_enabled()?'checked':'' ?>><span class="sl"></span></label>
                </div>
                <div class="cs-b">
                    <div class="row">
                        <div><label>Исходящий URL (куда слать сообщения)</label><input type="text" name="chat_webhook_url" value="<?= h(chat_webhook_url()) ?>" placeholder="https://your-endpoint/chat"></div>
                        <div><label>Секрет исходящих (HMAC)</label><input type="password" name="chat_webhook_secret" value="" placeholder="<?= chat_webhook_secret()?'•••••• задан':'не задан' ?>"></div>
                    </div>
                    <p class="muted cs-note">Входящий endpoint (для ответов агента): <code><?= h(chat_inbound_url()) ?></code><br>
                    Заголовок подписи входящих: <code>X-Chat-Signature: sha256=HMAC(body, входящий_секрет)</code>. Входящий секрет: <code><?= h(chat_inbound_secret()) ?></code></p>
                </div>
            </div>

            <div class="cs-grp cs-g-ex">
                <div class="cs-h">
                    <div class="set-info"><div class="cs-t">Виджет для других сайтов</div><div class="set-d">Чат подключается к любому вашему сайту одной строкой кода. Работает отдельно от чата на странице-заглушке.</div></div>
                    <label class="switch"><input type="checkbox" name="chat_embed_enabled" <?= chat_embed_enabled()?'checked':'' ?>><span class="sl"></span></label>
                </div>
                <div class="cs-b">
                    <div class="set-row cs-sp">
                        <div class="set-info"><div class="set-t">Чат на странице подписки</div><div class="set-d">Прослойка сама добавит окно на страницу подписки в браузере. Код, список сайтов и выключатель блока для этого не нужны.</div></div>
                        <select name="chat_subpage_design" aria-label="Дизайн окна на странице подписки">
                            <option value="rw" <?= $c_spd==='rw'?'selected':'' ?>>Remnawave</option>
                            <option value="lk" <?= $c_spd==='lk'?'selected':'' ?>>Кабинет — классика</option>
                            <option value="lkmin" <?= $c_spd==='lkmin'?'selected':'' ?>>Кабинет — минимализм</option>
                        </select>
                        <label class="switch"><input type="checkbox" name="chat_subpage" <?= chat_subpage_enabled()?'checked':'' ?>><span class="sl"></span></label>
                    </div>
                    <div class="info" id="cbExNoSites"<?= (chat_embed_enabled() && !$c_sites) ? '' : ' hidden' ?>>Добавьте хотя бы один сайт — пока список пуст, виджет нигде не откроется.</div>
                    <div class="cs-ex">
                        <div>
                            <div><label for="cbExSites">Сайты, где разрешён виджет <span class="hint">по одному в строке. Адрес с www и без www — два разных сайта</span></label><textarea id="cbExSites" name="chat_embed_sites" rows="3" spellcheck="false" placeholder="https://example.com"><?= h(implode("\n", $c_sites)) ?></textarea></div>
                            <div><label>Код для вставки <span class="hint">на страницы сайта перед &lt;/body&gt;. Дизайн записан в коде: для другого дизайна выберите его и скопируйте код заново</span></label><div class="codeblk"><button type="button" class="copybtn" onclick="uiCopy(this.nextElementSibling.textContent,this)">Копировать</button><pre id="cbExCode"><?= h(chat_embed_code()) ?></pre></div></div>
                            <div class="set-row">
                                <div class="set-info"><div class="set-t">Раскрывать окно само через, сек</div><div class="set-d">0 — не раскрывать. Свёрнутое посетителем окно само больше не раскрывается. На телефоне — только по нажатию.</div></div>
                                <input type="number" min="0" max="60" name="chat_embed_autoopen" value="<?= (int) chat_embed_autoopen() ?>">
                            </div>
                        </div>
                        <div>
                            <div>
                                <label>Дизайн окна</label>
                                <div class="cb-presets">
                                    <label class="cb-pre cd-pre <?= $c_design==='rw'?'sel':'' ?>"><input type="radio" name="chat_embed_design" value="rw" <?= $c_design==='rw'?'checked':'' ?>><div class="cd-th cd-rw"><i class="cd-hd"><i class="cd-av"></i><i class="cd-nm"></i></i><i class="cd-bd"><i class="cd-a"></i><i class="cd-v"></i><i class="cd-a"></i></i><i class="cd-ft"><i class="cd-in"></i><i class="cd-sn"></i></i></div><div class="cb-lbl">Remnawave — как страница подписки</div></label>
                                    <label class="cb-pre cd-pre <?= $c_design==='lk'?'sel':'' ?>"><input type="radio" name="chat_embed_design" value="lk" <?= $c_design==='lk'?'checked':'' ?>><div class="cd-th cd-lk"><i class="cd-hd"><i class="cd-av"></i><i class="cd-nm"></i></i><i class="cd-bd"><i class="cd-a"></i><i class="cd-v"></i><i class="cd-a"></i></i><i class="cd-ft"><i class="cd-in"></i><i class="cd-sn"></i></i></div><div class="cb-lbl">Кабинет — классика</div></label>
                                    <label class="cb-pre cd-pre <?= $c_design==='lkmin'?'sel':'' ?>"><input type="radio" name="chat_embed_design" value="lkmin" <?= $c_design==='lkmin'?'checked':'' ?>><div class="cd-th cd-lkmin"><i class="cd-hd"><i class="cd-av"></i><i class="cd-nm"></i></i><i class="cd-bd"><i class="cd-a"></i><i class="cd-v"></i><i class="cd-a"></i></i><i class="cd-ft"><i class="cd-in"></i><i class="cd-sn"></i></i></div><div class="cb-lbl">Кабинет — минимализм</div></label>
                                </div>
                            </div>
                            <p class="muted cs-note">Имя и фото агента, приветствие, вид и положение кнопки берутся из блока «Включить чат на сайте». Цвета задаёт дизайн; у минимализма тема та же, что на устройстве посетителя.</p>
                        </div>
                    </div>
                </div>
            </div>
            </div>

            <div style="margin-top:1.25rem"><button type="submit">💾 Сохранить настройки чата</button></div>
        </form>
        </div>
    </section>

    <script>
    var CB_CSRF = <?= json_encode($token) ?>;
    var CB_SESS = <?= json_encode($chat_sessions, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var cbCur = 0, cbLast = 0, cbSeen = {}, cbBusy = false, cbSBusy = false, cbSAgain = false, cbListSig = null, cbPushed = false;
    var cbMq = matchMedia('(max-width:760px)');
    function cbFit(){var c=document.querySelector('.cb-chat.cb-open .cb-conv');var v=window.visualViewport;if(!c)return;if(!v||!cbMq.matches){c.style.height='';c.style.top='';return;}c.style.height=v.height+'px';c.style.top=v.offsetTop+'px';}
    if(window.visualViewport){visualViewport.addEventListener('resize',cbFit);visualViewport.addEventListener('scroll',cbFit);}
    function cbGrow(){var t=document.getElementById('cbReply');if(!t)return;t.style.height='auto';t.style.height=Math.min(t.scrollHeight+2,140)+'px';}
    function cbClose(){
        cbCur=0;cbLast=0;cbSeen={};
        document.querySelector('.cb-chat').classList.remove('cb-open');
        var cv=document.querySelector('.cb-conv');if(cv){cv.style.height='';cv.style.top='';}
        document.getElementById('cbMsgs').innerHTML='<div class="cb-empty">Выберите диалог слева</div>';
        document.getElementById('cbReplyBox').style.display='none';
        document.getElementById('cbHead').style.display='none';
        document.querySelectorAll('.cb-sess').forEach(function(x){x.classList.remove('sel');});
        cbListSig=null;cbLoadSessions();
    }
    function cbBack(){if(cbPushed){cbPushed=false;history.back();}cbClose();}
    window.addEventListener('popstate',function(){if(cbPushed){cbPushed=false;cbClose();}});
    cbMq.addEventListener('change',function(){if(cbMq.matches&&cbCur&&!cbPushed){history.pushState({cbChat:cbCur},'');cbPushed=true;}cbFit();});
    function cbEsc(s){var d=document.createElement('div');d.textContent=(s==null?'':s);return d.innerHTML.replace(/"/g,'&quot;');}
    function cbSite(u){return String(u||'').replace(/^https?:\/\//,'');}
    function cbLocal(ep){ep=parseInt(ep,10);if(!ep)return '';var d=new Date(ep*1000);function p(n){return(n<10?'0':'')+n;}return p(d.getHours())+':'+p(d.getMinutes());}
    document.querySelectorAll('.cb-pre').forEach(function(el){el.addEventListener('click',function(){el.parentNode.querySelectorAll('.cb-pre').forEach(function(x){x.classList.remove('sel');});el.classList.add('sel');});});
    function cbRenderList(rows){
        var box=document.getElementById('cbList');
        var sig=cbCur+'|'+JSON.stringify(rows);
        if(sig===cbListSig)return;
        cbListSig=sig;
        if(!rows||!rows.length){box.innerHTML='<div class="cb-empty" style="height:80px">Пока нет диалогов</div>';return;}
        box.innerHTML=rows.map(function(s){
            var nm=s.name||('IP '+(s.ip||'—'));
            var pv=(s.last_body||'').slice(0,46);
            var st=s.site?'<span class="cb-site">'+cbEsc(cbSite(s.site))+'</span> · ':'';
            var un=parseInt(s.unread_agent,10)||0;
            return '<div class="cb-sess'+(s.id==cbCur?' sel':'')+'" data-id="'+s.id+'" onclick="cbOpen('+s.id+')">'+
                   '<div class="cb-st"><span class="cb-sn">'+cbEsc(nm)+'</span>'+(un?'<span class="cb-unread">'+un+'</span>':'<span class="cb-sp">'+cbLocal(s.last_ts)+'</span>')+'</div>'+
                   '<div class="cb-sp">'+st+cbEsc(pv)+'</div></div>';
        }).join('');
    }
    function cbLoadSessions(){
        if(cbSBusy){cbSAgain=true;return;}
        cbSBusy=true;
        var a=document.getElementById('cbAuto');
        fetch('?ajax=chat_sessions',{credentials:'same-origin'}).then(function(r){return r.json();}).then(function(d){
            if(d.ok){CB_SESS=d.sessions||[];cbRenderList(CB_SESS);}
            if(a)a.textContent='· обновлено '+new Date().toLocaleTimeString();
        }).catch(function(){}).then(function(){
            cbSBusy=false;
            if(cbSAgain){cbSAgain=false;cbLoadSessions();}
        });
    }
    function cbPutMsg(m){
        if(m.id && cbSeen[m.id]) return false;
        if(m.id) cbSeen[m.id]=1;
        var box=document.getElementById('cbMsgs');
        var el=document.createElement('div');
        el.className='cb-m '+m.sender;
        var src=(m.source&&m.sender==='agent'&&m.source!=='admin')?('<div class="cb-src">via '+cbEsc(m.source)+'</div>'):'';
        el.innerHTML=cbEsc(m.body)+src;
        box.appendChild(el);
        if(m.id>cbLast)cbLast=m.id;
        return true;
    }
    function cbAddMsg(m){
        if(!cbPutMsg(m))return;
        var box=document.getElementById('cbMsgs');
        box.scrollTop=box.scrollHeight;
    }
    function cbOpen(id){
        cbCur=id;cbLast=0;cbSeen={};
        document.querySelectorAll('.cb-sess').forEach(function(x){x.classList.toggle('sel',x.getAttribute('data-id')==id);});
        var s=(CB_SESS||[]).filter(function(x){return x.id==id;})[0];
        document.getElementById('cbHeadName').textContent=s?((s.name||('IP '+(s.ip||'—')))+(s.site?' · '+cbSite(s.site):'')):('#'+id);
        document.getElementById('cbHead').style.display='flex';
        var box=document.getElementById('cbMsgs');box.innerHTML='';
        document.getElementById('cbReplyBox').style.display='flex';
        document.querySelector('.cb-chat').classList.add('cb-open');
        if(cbMq.matches&&!cbPushed){history.pushState({cbChat:id},'');cbPushed=true;}
        cbFit();
        fetch('?ajax=chat_msgs&sid='+id+'&after=0&csrf='+encodeURIComponent(CB_CSRF),{credentials:'same-origin'}).then(function(r){return r.json();}).then(function(d){
            if(!d.ok||id!==cbCur)return;
            var n=0;
            (d.messages||[]).forEach(function(m){if(cbPutMsg(m))n++;});
            if(n)box.scrollTop=box.scrollHeight;
        });
    }
    function cbDelete(){
        if(!cbCur)return;
        var sid=cbCur;
        uiConfirm('Удалить чат? Он исчезнет и у клиента на сайте.',function(){
            var fd=new URLSearchParams();fd.set('csrf',CB_CSRF);fd.set('sid',sid);
            fetch('?ajax=chat_delete',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:fd.toString()})
                .then(function(r){return r.json();}).then(function(){
                    if(cbCur===sid)cbBack();else cbLoadSessions();
                });
        },'Удалить',true);
    }
    function cbPollMsgs(){
        if(!cbCur||cbBusy)return; cbBusy=true;
        var sid=cbCur;
        fetch('?ajax=chat_msgs&sid='+sid+'&after='+cbLast,{credentials:'same-origin'}).then(function(r){return r.json();}).then(function(d){
            cbBusy=false; if(d.ok&&sid===cbCur)(d.messages||[]).forEach(cbAddMsg);
        }).catch(function(){cbBusy=false;});
    }
    function cbSend(){
        var ta=document.getElementById('cbReply');var t=ta.value.trim();if(!t||!cbCur)return;
        ta.value='';cbGrow();
        var fd=new URLSearchParams();fd.set('csrf',CB_CSRF);fd.set('sid',cbCur);fd.set('body',t);
        fetch('?ajax=chat_reply',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:fd.toString()})
            .then(function(r){return r.json();}).then(function(){cbPollMsgs();cbLoadSessions();});
    }
    function cbTgApi(act,extra,cb){
        var fd=new URLSearchParams();fd.set('csrf',CB_CSRF);if(extra)Object.keys(extra).forEach(function(k){fd.set(k,extra[k]);});
        return fetch('?ajax='+act,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:fd.toString()}).then(function(r){return r.json();}).then(function(d){var w=document.getElementById('cbWhWarn');if(w&&d&&typeof d.hook_ok==='boolean')w.hidden=d.hook_ok;cb(d);});
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
            if(url&&d.expected&&url!==d.expected)parts.push('⚠️ адрес не тот, что задан в прослойке (<code>'+cbEsc(d.expected)+'</code>) — нажмите «Установить вебхук бота»; если адрес снова меняется, этот бот подключён ещё к другой программе');
            if(typeof i.pending_update_count!=='undefined')parts.push('в очереди: '+i.pending_update_count);
            if(i.last_error_message){var ea=i.last_error_date?Math.max(0,Math.round(Date.now()/1000-i.last_error_date)):-1;var es=ea<0?'':ea<60?' (только что)':ea<3600?' ('+Math.round(ea/60)+' мин назад)':ea<172800?' ('+Math.round(ea/3600)+' ч назад)':' ('+Math.round(ea/86400)+' дн назад)';parts.push('❌ последняя ошибка'+es+': '+cbEsc(i.last_error_message));}
            else if(url)parts.push('✅ ошибок нет');
            el.innerHTML=parts.join(' · ');
        });
    }
    document.getElementById('cbReply').addEventListener('keydown',function(e){if(e.key==='Enter'&&!e.shiftKey&&!e.isComposing&&!matchMedia('(pointer:coarse)').matches){e.preventDefault();cbSend();}});
    document.getElementById('cbReply').addEventListener('input',cbGrow);
    if(history.state&&history.state.cbChat){if(cbMq.matches){cbPushed=true;cbOpen(history.state.cbChat);}else history.replaceState(null,'');}
    cbRenderList(CB_SESS);
    (function(){
        var on=document.querySelector('[name=chat_embed_enabled]'),ta=document.getElementById('cbExSites'),box=document.getElementById('cbExNoSites');
        function upd(){box.hidden=!(on.checked&&!ta.value.trim());}
        on.addEventListener('change',upd);ta.addEventListener('input',upd);
        var code=document.getElementById('cbExCode');
        document.querySelectorAll('[name=chat_embed_design]').forEach(function(r){r.addEventListener('change',function(){code.textContent=code.textContent.replace(/widget=[a-z0-9]+/,'widget='+r.value);});});
    })();
    setInterval(function(){if(!document.hidden){cbPollMsgs();cbLoadSessions();}},5000);
    </script>
