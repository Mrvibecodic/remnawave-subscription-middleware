    <div class="card">
        <h2 style="margin-top:0;font-size:1rem">Слияние подписок</h2>
        <p class="muted">Прослойка подмешивает узлы <b>второй подписки</b> в тело основной — клиенту отдаётся одна ссылка, а серверы из обеих подписок лежат вместе под общими группами. Вторая подписка — отдельный пользователь со <b>своим лимитом трафика</b>, поэтому лимит «на доп-сервер» независим от основного.</p>
        <p class="muted">Подмешивание идёт <b>только пока основная подписка активна</b>: истекла/заблокирована — узлы второй пропадают сами (страховка от криво выставленного времени). При исчерпании <b>трафика</b> второй подписки вместо её узлов подмешивается заглушка-метка.</p>
        <p class="muted"><b>Авто</b> — по совпадению имени (<code>tg_&lt;id&gt;</code> → <code>tg_&lt;id&gt;<?= h(addsub_suffix()) ?></code>), находится через API панели. <b>Ручное</b> — кнопкой «+» во вкладке «Пользователи»: вводится адрес второй подписки. Настройки ниже общие для обоих режимов.</p>
    </div>

    <div class="card">
        <form method="post">
            <input type="hidden" name="csrf" value="<?= h($token) ?>">
            <input type="hidden" name="action" value="save_addsub">
            <div class="set-row">
                <div class="set-info"><div class="set-t">Включить слияние подписок</div><div class="set-d">Выключено — выдача не меняется, ни авто, ни ручные привязки не применяются.</div></div>
                <label class="switch"><input type="checkbox" name="addsub_enabled" <?= addsub_enabled() ? 'checked' : '' ?>><span class="sl"></span></label>
            </div>
            <div class="row" style="margin-top:1rem">
                <div><label>Суффикс имени для авто <span class="hint">B = имя A + суффикс</span></label><input type="text" name="addsub_username_suffix" value="<?= h(addsub_suffix()) ?>" placeholder="_addsub"></div>
                <div><label>Кэш дискавери, сек <span class="hint">мин. 30</span></label><input type="number" name="addsub_cache_ttl" min="30" value="<?= h((string) addsub_cache_ttl()) ?>" placeholder="600"></div>
                <div><label>Префикс меток узлов B <span class="hint">пусто = без префикса</span></label><input type="text" name="addsub_label" value="<?= h(addsub_label()) ?>" placeholder="напр.: 🅑"></div>
            </div>
            <div class="set-row" style="margin-top:1.25rem">
                <div class="set-info"><div class="set-t">Заглушка при исчерпании трафика B</div><div class="set-d">Когда трафик второй подписки кончился — вместо её узлов подмешать строку-метку. Выкл — узлы B просто исчезают.</div></div>
                <label class="switch"><input type="checkbox" name="addsub_stub_on_traffic" <?= addsub_stub_on_traffic() ? 'checked' : '' ?>><span class="sl"></span></label>
            </div>
            <label style="margin-top:1rem">Текст заглушки трафика</label>
            <input type="text" name="addsub_stub_label" value="<?= h(addsub_stub_label()) ?>" placeholder="Трафик доп-сервера истёк" style="max-width:480px;box-sizing:border-box">
            <div class="set-row" style="margin-top:1.25rem">
                <div class="set-info"><div class="set-t">Слияние для xray-json</div><div class="set-d">Влить outbounds второй подписки в xray-json. По умолчанию выкл (как у доп-конфигов). base64 / Clash / sing-box работают всегда при включённом слиянии.</div></div>
                <label class="switch"><input type="checkbox" name="addsub_merge_xray" <?= addsub_xray_enabled() ? 'checked' : '' ?>><span class="sl"></span></label>
            </div>
            <div style="margin-top:1.25rem"><button type="submit">💾 Сохранить</button></div>
        </form>
    </div>

    <section class="coll collapsed" data-coll="addsub_help">
        <button type="button" class="coll-head" onclick="collToggle(this)"><span>📘 Как собрать вторую подписку с доп-заглушками</span>
            <span class="coll-hr"><svg class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg></span>
        </button>
        <div class="coll-body">
            <div class="subwrap">
                <div class="subedit">
                    <p class="muted" style="margin-top:0">Вторая подписка — это <b>отдельный пользователь панели</b> со своим лимитом трафика и своим сквадом. В её сквад можно положить и <b>рабочие доп-серверы</b>, и <b>хосты-метки</b> (подсказки), которые видны всегда — помимо инжекта прослойки.</p>

                    <p style="margin:.9rem 0 .3rem"><b>1. Отдельный пользователь B</b></p>
                    <p class="muted" style="margin:.2rem 0">Создай в панели пользователя <code>tg_&lt;id&gt;<?= h(addsub_suffix()) ?></code> (для авто-режима — точно этот суффикс), <b>без</b> <code>telegramId</code>, со своим лимитом трафика и сквадом. Для ручного режима имя любое — адрес его подписки вводится кнопкой «+».</p>

                    <p style="margin:.9rem 0 .3rem"><b>2. Сквад B: рабочие серверы + метки</b></p>
                    <p class="muted" style="margin:.2rem 0">В сквад второго пользователя добавь нужные доп-серверы. Если нужны статичные подсказки — добавь «инфо»-инбаунд (мёртвый VLESS/TCP без TLS) и по хосту-метке на каждую строку, как в грейс-скваде:</p>
                    <div class="codeblk">
                        <button type="button" class="copybtn" onclick="copyAddsubCfg(this)">⧉ Копировать</button>
<pre id="addsub-inbound-json">{
  "tag": "INFO-ADDSUB",
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
                    <p class="muted" style="margin:.2rem 0"><code>Hosts → Create new host</code>: <code>Remark</code> = текст метки, <code>Inbound</code> = <code>INFO-ADDSUB</code>, <code>Address</code> = <code>127.0.0.1</code>. Адрес мёртвый → чистая метка, подключения нет.</p>

                    <p style="margin:.9rem 0 .3rem"><b>3. Заглушка по трафику</b></p>
                    <p class="muted" style="margin:.2rem 0">Когда трафик B исчерпан, прослойка сама подмешает строку-метку «<?= h(addsub_stub_label()) ?>» (текст — в настройках выше). Это поверх меток сквада, без обращения к панели.</p>

                    <p style="margin:.9rem 0 .3rem"><b>4. Привязка</b></p>
                    <p class="muted" style="margin:.2rem 0">Авто — по суффиксу имени, ничего вводить не нужно. Ручное — во вкладке «Пользователи» кнопка «+» у нужного юзера, вставить адрес подписки B.</p>
                </div>
                <div class="subprev">
                    <label style="margin-top:0">Так это выглядит у юзера (схема)</label>
                    <div class="phone">
                        <div class="ph-top">
                            <div class="ph-app">VPN-клиент · подписка</div>
                            <div class="ph-title">Моя подписка</div>
                            <div class="ph-sub">основная + доп-сервер</div>
                        </div>
                        <div class="ph-list">
                            <div class="srow support"><span class="dot"></span><span class="nm">🇳🇱 Нидерланды · 1<span class="ph-badge">основ.</span></span><span class="pg">42 ms</span></div>
                            <div class="srow support"><span class="dot"></span><span class="nm">🇩🇪 Германия · 2<span class="ph-badge">основ.</span></span><span class="pg">58 ms</span></div>
                            <div class="srow support"><span class="dot"></span><span class="nm"><?= h(addsub_label() !== '' ? addsub_label() . ' ' : '') ?>🇫🇮 Доп-сервер<span class="ph-badge">довесок</span></span><span class="pg">61 ms</span></div>
                            <div class="srow"><span class="dot"></span><span class="nm">⚠️ <?= h(addsub_stub_label()) ?></span><span class="pg">—</span></div>
                        </div>
                    </div>
                    <p class="muted" style="margin-top:.6rem">Схема. Зелёные — рабочие серверы (основной + довесок в общих группах). Серая строка — заглушка, появляется когда трафик доп-сервера исчерпан.</p>
                </div>
            </div>
        </div>
    </section>

    <div class="card">
        <h2 style="margin-top:0;font-size:1rem">Ручные привязки (<?= count($addsub_list) ?>)</h2>
        <p class="muted">Добавляются кнопкой «+» во вкладке «Пользователи». Здесь — обзор и отвязка.</p>
        <?php if (!$addsub_list): ?>
            <p class="muted">Пока пусто.</p>
        <?php else: ?>
        <table class="logtbl">
            <thead><tr><th>shortUuid основной</th><th>Заметка</th><th>Адрес второй подписки</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($addsub_list as $m): $ms = (string) $m['main_short']; ?>
            <tr>
                <td style="font-family:monospace;font-size:.8rem"><?= h($ms) ?></td>
                <td><?= ($m['note'] ?? '') !== '' ? h((string) $m['note']) : '<span class="muted">—</span>' ?></td>
                <td style="font-family:monospace;font-size:.78rem;word-break:break-all"><?= h((string) $m['add_url']) ?></td>
                <td style="text-align:right;white-space:nowrap"><button type="button" class="danger addsub-del" data-su="<?= h($ms) ?>">🗑 Отвязать</button></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

    <style>
    .codeblk{position:relative;margin:.5rem 0}
    .codeblk pre{margin:0;background:var(--bg2);border:1px solid var(--line);border-radius:9px;padding:2.3rem .9rem .85rem;overflow:auto;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:.78rem;line-height:1.5;white-space:pre;color:var(--text)}
    .codeblk .copybtn{position:absolute;top:.45rem;right:.45rem;padding:.28rem .62rem;font-size:.72rem;border:1px solid var(--line);border-radius:7px;background:var(--card);color:var(--text);cursor:pointer;font-weight:600;line-height:1}
    .codeblk .copybtn:hover{filter:brightness(1.1)}
    </style>
    <script>
    var ADDSUB_CSRF = <?= json_encode($token) ?>;
    function copyAddsubCfg(btn){
        var el = document.getElementById('addsub-inbound-json');
        if (!el) return;
        var txt = el.textContent;
        var done = function(){ var o = btn.textContent; btn.textContent = '✓ Скопировано'; setTimeout(function(){ btn.textContent = o; }, 1500); };
        if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(txt).then(done, function(){ fallbackCopy(txt); done(); }); }
        else { fallbackCopy(txt); done(); }
        function fallbackCopy(t){ var ta = document.createElement('textarea'); ta.value = t; document.body.appendChild(ta); ta.select(); try { document.execCommand('copy'); } catch(e){} document.body.removeChild(ta); }
    }
    document.querySelectorAll('.addsub-del').forEach(function(b){
        b.addEventListener('click', function(){
            var su = b.dataset.su || '';
            if (!su) return;
            uiConfirm('Отвязать вторую подписку от ' + su + '?', function(){
                var f = new FormData(); f.append('csrf', ADDSUB_CSRF); f.append('short_uuid', su);
                fetch('?ajax=addsub_map_del', {method:'POST', body:f}).then(function(r){return r.json();}).then(function(d){
                    if (d.ok) { location.reload(); } else { uiAlert('Ошибка: ' + (d.error || '')); }
                }).catch(function(){ uiAlert('Сетевая ошибка'); });
            }, 'Отвязать', true);
        });
    });
    </script>
