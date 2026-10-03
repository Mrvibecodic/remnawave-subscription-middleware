    <style>.pk-sel[hidden]{display:none}.pk-users{width:100%;min-height:110px;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:.85rem}</style>
    <section class="<?= coll_cls('pagekeys_intro') ?>" data-coll="pagekeys_intro">
        <button type="button" class="coll-head" onclick="collToggle(this)"><span>Ключи на странице подписки</span>
            <span class="coll-hr"><svg width="30" height="30" class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg></span>
        </button>
        <div class="coll-body">
            <p class="muted">У страницы подписки (subscription-page) есть встроенный блок с ключами подключения: каждый конфиг отдельной строкой — <code>vless://</code>, <code>trojan://</code>, <code>wireguard://</code> и т.д., — с кнопками «копировать» и QR-кодом. Панель заполняет его, только когда в ней выключен лимит устройств (HWID): при включённом лимите список приходит пустым, и блок на странице не появляется.</p>
            <p class="muted">Эта настройка подкладывает ключи в страницу на стороне прослойки — панель и subscription-page перенастраивать не нужно. Ключи принадлежат тому пользователю, чья ссылка открыта в браузере. Хосты панели прослойка берёт через API (нужно право токена <code>subscriptions:by-short-uuid-protected</code>), доп. конфиги — из своих вкладок «Доп. конфиги» и «WG / AWG» по сквадам пользователя, серверы второй подписки — из «Слияния подписок». Всё одним списком в том же порядке, что и в подписке: хосты панели, доп. конфиги, серверы второй подписки; отдельно они не помечаются.</p>
            <p class="muted">Хосты-метки второй подписки (адрес <code>0.0.0.0</code> или <code>127.0.0.1</code> — подсказки, которые в клиенте видны строками без подключения) ключами не становятся: их текст выводится подзаголовком под названием блока. Туда же попадает заглушка про исчерпанный трафик второй подписки. Подзаголовок можно выключить.</p>
            <p class="muted">Ключи показываются только активному пользователю: заблокированному в прослойке, неактивному в панели или скрытому защищённым каналом страница отдаётся как обычно, без ключей. WG/AWG из пула в режиме «по устройствам» на странице не показываются — у браузера нет HWID; в режиме «по подписке» показывается уже выданный пользователю конфиг, новый на странице не выдаётся. Учтите: ключ, скопированный со страницы, работает в обход лимита устройств — поэтому показ можно ограничить сквадами и пользователями.</p>
            <p class="muted">Нужна сама страница: «Подключение» → адрес subscription-page, либо origin, который отдаёт страницу браузеру (режим «Зеркало»).</p>
        </div>
    </section>

    <?php if ($pk_probe !== null && !$pk_probe['ok']): ?>
        <div class="warn">Хосты панели на страницу не попадут: <?= h($pk_probe['msg']) ?>. Выдайте токену право <code>subscriptions:by-short-uuid-protected</code> (ресурс Subscriptions) или выключите «Хосты панели» ниже — доп. конфиги прослойки показываются и без него.</div>
    <?php elseif ($pk_probe !== null): ?>
        <div class="info">Право токена <code>subscriptions:by-short-uuid-protected</code>: <?= h($pk_probe['msg']) ?>.</div>
    <?php endif; ?>

    <div class="card">
        <form method="post" data-autosave>
            <input type="hidden" name="csrf" value="<?= h($token) ?>">
            <input type="hidden" name="action" value="save_pagekeys">
            <div class="set-row">
                <div class="set-info"><div class="set-t">Показывать ключи</div><div class="set-d">«Выключено» — страница отдаётся как есть. «Всем» — каждому активному пользователю. «Выбранным» — только тем, кто состоит в отмеченном скваде <b>или</b> есть в списке пользователей.</div></div>
                <select name="pagekeys_mode" id="pkMode">
                    <option value="off" <?= pagekeys_mode() === 'off' ? 'selected' : '' ?>>Выключено</option>
                    <option value="all" <?= pagekeys_mode() === 'all' ? 'selected' : '' ?>>Всем</option>
                    <option value="sel" <?= pagekeys_mode() === 'sel' ? 'selected' : '' ?>>Выбранным</option>
                </select>
            </div>
            <div class="set-row" style="margin-top:1rem">
                <div class="set-info"><div class="set-t">Хосты панели</div><div class="set-d">Конфиги, которые панель прячет при включённом лимите устройств. Если панель уже отдала их странице сама (лимит выключен), прослойка ничего не запрашивает.</div></div>
                <label class="switch"><input type="checkbox" name="pagekeys_panel" <?= pagekeys_with_panel() ? 'checked' : '' ?>><span class="sl"></span></label>
            </div>
            <div class="set-row" style="margin-top:1rem">
                <div class="set-info"><div class="set-t">Доп. конфиги прослойки</div><div class="set-d">VLESS, WireGuard и AmneziaWG из «Доп. конфигов» и «WG / AWG» — те же, что пользователь получает в подписке по своим сквадам, и ручные привязки. AmneziaWG — ссылкой <code>wg://</code>, WireGuard — <code>wireguard://</code>.</div></div>
                <label class="switch"><input type="checkbox" name="pagekeys_extra" <?= pagekeys_with_extra() ? 'checked' : '' ?>><span class="sl"></span></label>
            </div>
            <div class="set-row" style="margin-top:1rem">
                <div class="set-info"><div class="set-t">Серверы второй подписки</div><div class="set-d">Ключи из «Слияния подписок» — те же, что подмешиваются в подписку. Работает при включённом слиянии; в грейсе и при исчерпанном трафике второй подписки серверы не показываются, как и в подписке.</div></div>
                <label class="switch"><input type="checkbox" name="pagekeys_addsub" <?= pagekeys_with_addsub() ? 'checked' : '' ?>><span class="sl"></span></label>
            </div>
            <div class="set-row" style="margin-top:1rem">
                <div class="set-info"><div class="set-t">Метки второй подписки подзаголовком</div><div class="set-d">Текст хостов-меток второй подписки и заглушки про трафик показывать под названием блока ключей. Выключено — метки на странице не выводятся вовсе.</div></div>
                <label class="switch"><input type="checkbox" name="pagekeys_addsub_labels" <?= pagekeys_addsub_labels() ? 'checked' : '' ?>><span class="sl"></span></label>
            </div>

            <div class="pk-sel" id="pkSel"<?= pagekeys_mode() === 'sel' ? '' : ' hidden' ?>>
                <input type="hidden" name="pagekeys_squads_sent" value="1">
                <label style="margin-top:1.25rem">Сквады <span class="hint">ключи видят участники любого отмеченного сквада</span></label>
                <?php if ($pk_squads_err !== ''): ?>
                    <div class="warn" style="margin-top:.4rem">Список сквадов не получен: <?= h($pk_squads_err) ?></div>
                <?php elseif (!$pk_squads): ?>
                    <div class="muted" style="margin-top:.4rem">Сквадов нет или не заданы URL панели и API-токен.</div>
                <?php endif; ?>
                <?php $pk_sel = pagekeys_squads(); $pk_known = []; ?>
                <div class="sq-grid" id="pkGrid">
                    <?php foreach ($pk_squads as $s): $pk_known[$s['uuid']] = true; ?>
                        <label class="sq-item<?= in_array($s['uuid'], $pk_sel, true) ? ' on' : '' ?>"><input type="checkbox" name="pagekeys_squads[]" value="<?= h($s['uuid']) ?>" <?= in_array($s['uuid'], $pk_sel, true) ? 'checked' : '' ?>><span class="sq-n"><?= h($s['name']) ?></span><span class="muted" style="font-size:.78rem"><?= (int) $s['members'] ?></span></label>
                    <?php endforeach; ?>
                    <?php foreach ($pk_sel as $u): if (isset($pk_known[$u])) continue; ?>
                        <label class="sq-item on"><input type="checkbox" name="pagekeys_squads[]" value="<?= h($u) ?>" checked><span class="sq-n"><?= h($u) ?></span><span class="muted" style="font-size:.72rem">нет в панели</span></label>
                    <?php endforeach; ?>
                </div>
                <label style="margin-top:1.25rem">Пользователи <span class="hint">username или shortUuid — по одному в строке, через запятую или пробел; регистр не важен</span></label>
                <textarea class="pk-users" name="pagekeys_users" placeholder="tg_123456789&#10;Ab3dE7xYz"><?= h(pagekeys_users_raw()) ?></textarea>
            </div>
            <div style="margin-top:1.25rem"><button type="submit">Сохранить</button></div>
        </form>
    </div>
    <script>
    (function(){
        var m=document.getElementById('pkMode'), s=document.getElementById('pkSel');
        if(m&&s) m.addEventListener('change',function(){ s.hidden = (m.value!=='sel'); });
        var g=document.getElementById('pkGrid');
        if(g) g.addEventListener('change',function(e){ var cb=e.target; if(!cb||cb.type!=='checkbox') return; var l=cb.closest('.sq-item'); if(l) l.classList.toggle('on', cb.checked); });
    })();
    </script>
