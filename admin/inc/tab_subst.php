<?php $gsq_name = ''; foreach ($grace_squads as $__s) { if ($__s['uuid'] === grace_squad_uuid()) { $gsq_name = $__s['name']; break; } } ?>
    <div class="card">
        <h2 style="margin-top:0;font-size:1rem">Грейс-сквад для истёкших</h2>
        <p class="muted">На хук <code>user.expired</code> прослойка переносит юзера в выбранный сквад панели (например only-Telegram), ставит лимит трафика/устройств и держит активным на грейс-период — конфиг отдаёт сама панель. После грейса — возврат исходного сквада и истечение; при оплате — возврат и коррекция даты «от сегодня».</p>
        <p class="muted">Сообщение юзеру («вы на лимитах», «продлите подписку») делается <b>в самой панели</b> — хостами-метками этого сквада. Прослойка ничего не инжектит. Как настроить — в справке ниже.</p>
    </div>

    <div class="card">
        <form method="post" data-autosave>
            <input type="hidden" name="csrf" value="<?= h($token) ?>">
            <input type="hidden" name="action" value="save_grace">
            <div class="set-row">
                <div class="set-info"><div class="set-t">Включить грейс-сквад</div><div class="set-d">На <code>user.expired</code> переносить истёкшего в ограниченный сквад вместо обычного истечения.</div></div>
                <label class="switch"><input type="checkbox" name="grace_squad_enabled" <?= grace_squad_enabled()?'checked':'' ?>><span class="sl"></span></label>
            </div>
            <label style="margin-top:1.25rem;display:block">Сквад для истёкших</label>
            <?php if ($grace_squads_err !== ''): ?>
                <div class="warn">Не удалось получить список сквадов: <?= h($grace_squads_err) ?>. Проверьте URL и токен в «Подключении».</div>
            <?php elseif (!$grace_squads): ?>
                <p class="muted">Список сквадов пуст или API не настроен — заполните «Подключение» (URL панели + токен).</p>
            <?php else: $cur = grace_squad_uuid(); ?>
                <div class="sq-grid">
                <?php foreach ($grace_squads as $s): ?>
                    <label class="sq-item<?= $cur === $s['uuid'] ? ' on' : '' ?>">
                        <input type="radio" name="grace_squad_uuid" value="<?= h($s['uuid']) ?>" <?= $cur === $s['uuid'] ? 'checked' : '' ?>>
                        <span class="sq-n"><?= h($s['name']) ?></span>
                        <span class="muted" style="font-size:.78rem"><?= (int) $s['members'] ?></span>
                    </label>
                <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <div class="row" style="margin-top:1rem">
                <div><label>Грейс, дней <span class="hint">пусто — значение по умолчанию</span></label><input type="number" name="grace_days" min="0" value="<?= h((string) setting('grace_days','')) ?>" placeholder="<?= h(expired_grace_days()) ?>"></div>
                <div><label>Лимит трафика, ГБ <span class="hint">0 — без лимита</span></label><input type="text" name="grace_traffic_gb" value="<?= h(rtrim(rtrim(number_format(grace_traffic_bytes() / 1073741824, 2, '.', ''), '0'), '.')) ?>" placeholder="0"></div>
                <div><label>Лимит устройств (HWID) <span class="hint">пусто — лимит не меняется</span></label><input type="text" name="grace_hwid_limit" value="<?= h(grace_hwid_limit_raw()) ?>" placeholder="не менять"></div>
                <div><label>Стратегия сброса трафика</label>
                    <select name="grace_traffic_strategy">
                        <?php foreach (['NO_RESET' => 'Без сброса', 'DAY' => 'Ежедневно', 'WEEK' => 'Еженедельно', 'MONTH' => 'Ежемесячно', 'MONTH_ROLLING' => 'Скользящий месяц'] as $k => $v): ?>
                            <option value="<?= $k ?>" <?= grace_traffic_strategy() === $k ? 'selected' : '' ?>><?= $v ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="set-row" style="margin-top:1.5rem">
                <div class="set-info"><div class="set-t">Внешний сквад на время грейса</div><div class="set-d">Дополнительно к внутреннему: на грейс юзеру выдаётся внешний сквад (его <code>announce</code> и заголовки из Subscription Settings панели), после грейса возвращается родной.</div></div>
                <label class="switch"><input type="checkbox" name="grace_external_enabled" <?= grace_external_enabled()?'checked':'' ?>><span class="sl"></span></label>
            </div>
            <label>Внешний сквад для грейса</label>
            <?php if ($ext_squads_err !== ''): ?>
                <div class="warn">Не удалось получить список внешних сквадов: <?= h($ext_squads_err) ?>. Проверьте URL и токен в «Подключении».</div>
            <?php elseif (!$ext_squads): ?>
                <p class="muted">Внешних сквадов нет или API не настроен. Создайте внешний сквад в панели и задайте в его Subscription Settings нужный <code>announce</code>/заголовки.</p>
            <?php else: $curx = grace_external_squad_uuid(); ?>
                <div class="sq-grid">
                <?php foreach ($ext_squads as $s): ?>
                    <label class="sq-item<?= $curx === $s['uuid'] ? ' on' : '' ?>">
                        <input type="radio" name="grace_external_squad_uuid" value="<?= h($s['uuid']) ?>" <?= $curx === $s['uuid'] ? 'checked' : '' ?>>
                        <span class="sq-n"><?= h($s['name']) ?></span>
                        <span class="muted" style="font-size:.78rem"><?= (int) $s['members'] ?></span>
                    </label>
                <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <label style="margin-top:1.5rem;display:block">Фолбэк: анонс из прослойки <span class="hint">работает, если внешний сквад не задан</span></label>
            <p class="muted" style="margin:.2rem 0 .7rem">Прослойка сама подставит этот <code>announce</code> на время грейса (поверх анонса панели). При активном внешнем скваде — игнорируется.</p>
            <div class="ga-wrap">
                <div class="ga-edit">
                    <textarea name="grace_announce" id="ga-input" rows="4" maxlength="400" placeholder="Подписка истекла &#10;Продлите — доступ вернётся&#10;Поддержка: @your_bot"><?= h(str_replace('\n', "\n", grace_announce())) ?></textarea>
                    <p class="muted" style="font-size:.8rem;margin:.5rem 0 0">Каждая строка — отдельный перенос (работает во всех клиентах, как в панели). Кириллица кодируется в base64 сама, лимит 200 символов.</p>
                </div>
                <div class="ga-prev">
                    <label style="margin-top:0">Превью в клиенте</label>
                    <div class="ga-client">
                        <div class="ga-c-head"><svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 11l18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/></svg>Объявление</div>
                        <div class="ga-c-body" id="ga-prev"></div>
                    </div>
                </div>
            </div>
            <div style="margin-top:1.25rem"><button type="submit">💾 Сохранить</button></div>
        </form>
        <div class="set-row" style="margin-top:1.1rem">
            <div class="set-info">
                <div class="set-t">Идентификаторы пользователей в грейсе</div>
                <div class="set-d">Прослойка запоминает, каким идентификатором панель звала пользователя в момент ухода в грейс. Панель <b>3.0</b> перешла с <code>uuid</code> на числовой <code>id</code>, поэтому записи, сделанные до обновления панели, устарели. Прослойка чинит их сама при первом же обращении, но эта кнопка пересчитает всё разом — и покажет записи, которых в панели уже нет.</div>
            </div>
            <form method="post" style="flex:0 0 auto">
                <input type="hidden" name="csrf" value="<?= h($token) ?>">
                <input type="hidden" name="action" value="grace_refresh_refs">
                <button type="submit" class="btn ghost">🔁 Обновить идентификаторы</button>
            </form>
        </div>
    </div>

    <section class="<?= coll_cls('g_squad_help', true) ?>" data-coll="g_squad_help">
        <button type="button" class="coll-head" onclick="collToggle(this)"><span>📘 Как сделать сквад с ремарками в панели</span>
            <span class="coll-hr"><svg width="30" height="30" class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg></span>
        </button>
        <div class="coll-body">
            <div class="subwrap">
                <div class="subedit">
                    <p class="muted" style="margin-top:0">Ремарки делаем <b>хостами в панели</b>, а не инжектим из прослойки: панель генерит их с настоящим UUID юзера → Happ/xray/clash их показывают. Адрес мёртвый → подключения нет, чистая метка.</p>

                    <p style="margin:.9rem 0 .3rem"><b>1. Config Profile → отдельный профиль «GRACE»</b></p>
                    <p class="muted" style="margin:.2rem 0">Лучше создать <b>отдельный</b> Config Profile и назвать его <code>GRACE</code> (чтобы не мешать рабочим профилям). В его массив <code>inbounds</code> добавь этот «инфо»-инбаунд — простой VLESS/TCP без TLS: клиент его показывает, но трафик никуда не идёт. Ноду под него поднимать НЕ нужно — подписка собирается из хостов.</p>
                    <div class="codeblk">
                        <button type="button" class="copybtn" onclick="copyGraceCfg(this)">⧉ Копировать</button>
<pre id="grace-inbound-json">{
  "tag": "INFO-GRACE",
  "listen": "127.0.0.1",
  "port": 8443,
  "protocol": "vless",
  "settings": {
    "clients": [],
    "decryption": "none"
  },
  "streamSettings": {
    "network": "tcp",
    "security": "none"
  },
  "sniffing": {
    "enabled": false,
    "destOverride": []
  }
}</pre>
                    </div>

                    <p style="margin:.9rem 0 .3rem"><b>2. Hosts → по хосту на каждую ремарку</b></p>
                    <p class="muted" style="margin:.2rem 0"><code>Hosts</code> → <code>Create new host</code>. На каждую строку-метку отдельный хост: <code>Remark</code> = текст метки, <code>Inbound</code> = <code>INFO-GRACE</code>, <code>Address</code> = <code>127.0.0.1</code>, <code>Port</code> — авто (8443) или 443, видимость ON.</p>

                    <p style="margin:.9rem 0 .3rem"><b>3. Порядок хостов</b></p>
                    <p class="muted" style="margin:.2rem 0">Строки отображаются у юзера в приложении в том же порядке, в каком хосты идут в списке <code>Hosts</code>. Перетащи их мышью, чтобы выставить нужную последовательность.</p>

                    <p style="margin:.9rem 0 .3rem"><b>4. Internal Squad → собрать грейс-сквад</b></p>
                    <p class="muted" style="margin:.2rem 0">Включить в скваде инбаунд <code>INFO-GRACE</code> (ремарки) <b>и</b> рабочие сервера, которые должны остаться у истёкшего — например Telegram-доступ. Так юзер видит и метки-подсказки, и реально работающий сервер рядом.</p>

                    <p style="margin:.9rem 0 .3rem"><b>5. Прослойка</b></p>
                    <p class="muted" style="margin:.2rem 0">Выше: включить грейс-сквад, выбрать этот сквад, сохранить. Перенос истёкших делает прослойка по хуку <code>user.expired</code>.</p>
                </div>
                <div class="subprev">
                    <label style="margin-top:0">Так это выглядит у юзера (схема)</label>
                    <div class="phone">
                        <div class="ph-top">
                            <div class="ph-app">VPN-клиент · подписка</div>
                            <div class="ph-title"><?= $gsq_name !== '' ? h($gsq_name) : 'Грейс-сквад' ?></div>
                            <div class="ph-sub">Лимитный режим — продлите подписку</div>
                        </div>
                        <div class="ph-list">
                            <div class="srow"><span class="dot"></span><span class="nm">🚫 Подписка истекла</span><span class="pg">—</span></div>
                            <div class="srow"><span class="dot"></span><span class="nm">Продлите — доступ вернётся</span><span class="pg">—</span></div>
                            <div class="srow"><span class="dot"></span><span class="nm">Поддержка в боте</span><span class="pg">—</span></div>
                            <div class="srow support"><span class="dot"></span><span class="nm">📨 Telegram-доступ<span class="ph-badge">рабочий</span></span><span class="pg">48 ms</span></div>
                        </div>
                    </div>
                    <p class="muted" style="margin-top:.6rem">Схема. Серые строки — метки-ремарки (нерабочие), зелёная — реальный сервер из сквада. Имена и порядок задаются хостами сквада<?= $gsq_name !== '' ? ' «'.h($gsq_name).'»' : '' ?> в панели.</p>
                </div>
            </div>
        </div>
    </section>
    <style>
    .ga-wrap{display:grid;grid-template-columns:1fr 280px;gap:1.1rem;align-items:start}
    @media(max-width:720px){.ga-wrap{grid-template-columns:1fr}}
    .ga-edit textarea{width:100%;box-sizing:border-box;resize:vertical;font-family:inherit;font-size:.9rem;line-height:1.5;padding:.6rem .75rem;border:1px solid var(--line);border-radius:9px;background:var(--bg2);color:var(--text)}
    .ga-edit textarea:focus{outline:none;border-color:var(--accent)}
    .ga-client{border:1px solid var(--line);border-radius:13px;overflow:hidden;background:var(--bg2);box-shadow:var(--shadow)}
    .ga-c-head{display:flex;align-items:center;gap:.45rem;padding:.55rem .85rem;font-weight:600;font-size:.82rem;background:var(--accent);color:var(--accent-text)}
    .ga-c-body{padding:.75rem .9rem;font-size:.87rem;line-height:1.6;color:var(--text);min-height:3.4rem;word-break:break-word}
    .ga-c-empty{color:var(--muted);font-style:italic}
    .codeblk{position:relative;margin:.5rem 0}
    .codeblk pre{margin:0;background:var(--bg2);border:1px solid var(--line);border-radius:9px;padding:2.3rem .9rem .85rem;overflow:auto;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:.78rem;line-height:1.5;white-space:pre;color:var(--text)}
    .codeblk .copybtn{position:absolute;top:.45rem;right:.45rem;padding:.28rem .62rem;font-size:.72rem;border:1px solid var(--line);border-radius:7px;background:var(--card);color:var(--text);cursor:pointer;font-weight:600;line-height:1}
    .codeblk .copybtn:hover{filter:brightness(1.1)}
    </style>
    <script>
    (function(){
        var inp = document.getElementById('ga-input'), pv = document.getElementById('ga-prev');
        if (!inp || !pv) return;
        function esc(s){ return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
        function upd(){
            var t = inp.value.replace(/\r\n?/g,'\n').replace(/^\n+|\n+$/g,'');
            pv.innerHTML = t === '' ? '<span class="ga-c-empty">— анонс выключен —</span>' : esc(t).replace(/\n/g,'<br>');
        }
        inp.addEventListener('input', upd); upd();
    })();
    function copyGraceCfg(btn){
        var el = document.getElementById('grace-inbound-json');
        if (!el) return;
        var txt = el.textContent;
        var done = function(){ var o = btn.textContent; btn.textContent = '✓ Скопировано'; setTimeout(function(){ btn.textContent = o; }, 1500); };
        if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(txt).then(done, function(){ fallbackCopy(txt); done(); }); }
        else { fallbackCopy(txt); done(); }
        function fallbackCopy(t){ var ta = document.createElement('textarea'); ta.value = t; document.body.appendChild(ta); ta.select(); try { document.execCommand('copy'); } catch(e){} document.body.removeChild(ta); }
    }
    </script>
