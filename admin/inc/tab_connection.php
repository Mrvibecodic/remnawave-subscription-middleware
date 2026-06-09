    <div class="card">
        <h2 style="margin-top:0;font-size:1rem">Подключение</h2>
        <p class="muted" style="margin-bottom:.4rem">Нажмите <b>?</b> у любого поля — справа откроется справка с примером. Формат: <b>домены — без</b> <code>https://</code>, а <b>URL панели — со схемой</b> <code>https://</code>.</p>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= h($token) ?>">
            <input type="hidden" name="action" value="save_connection">
            <div class="row">
                <div><label>Origin — домен подписки <button type="button" class="qh" onclick="help('origin')" aria-label="Справка">?</button></label><input type="text" name="target_domain" value="<?= h(target_domain()) ?>" placeholder="sub.example.com"></div>
                <div><label>Домен зеркала <button type="button" class="qh" onclick="help('mirror')" aria-label="Справка">?</button></label><input type="text" name="mirror_domain" value="<?= h($mirror) ?>" placeholder="mirror.example.com"></div>
            </div>
            <div class="row">
                <div><label>URL панели Remnawave <button type="button" class="qh" onclick="help('rwurl')" aria-label="Справка">?</button></label><input type="text" name="remnawave_url" value="<?= h(remnawave_url()) ?>" placeholder="https://panel.example.com"></div>
                <div><label>Cookie панели (eGames-защита) <button type="button" class="qh" onclick="help('cookie')" aria-label="Справка">?</button></label><input type="text" name="remnawave_cookie" value="<?= h(remnawave_cookie()) ?>" placeholder="aB3xK9pQ=Zt7mW2nR"></div>
            </div>
            <div class="row">
                <div><label>API-токен панели <button type="button" class="qh" onclick="help('apikey')" aria-label="Справка">?</button></label><input type="password" name="remnawave_api_key" value="" placeholder="<?= remnawave_token() ? '•••••• задан' : 'не задан' ?>"></div>
                <div><label>Секрет вебхука <button type="button" class="qh" onclick="help('whsecret')" aria-label="Справка">?</button></label><input type="password" name="webhook_secret" value="" placeholder="<?= webhook_secret() ? '•••••• задан' : 'не задан' ?>"></div>
            </div>
            <div class="set-row">
                <div class="set-info"><div class="set-t">Таймаут проксирования, сек <button type="button" class="qh" onclick="help('timeout')" aria-label="Справка">?</button></div><div class="set-d">Сколько ждать ответа origin при запросе подписки.</div></div>
                <input type="number" name="proxy_timeout" value="<?= h(proxy_timeout()) ?>">
            </div>
            <div class="set-row">
                <div class="set-info"><div class="set-t">Доверять заголовку expire <button type="button" class="qh" onclick="help('trust')" aria-label="Справка">?</button></div><div class="set-d">Рекомендуется — продление подписки чинит себя само.</div></div>
                <label class="switch"><input type="checkbox" name="trust_header_expire" <?= trust_header_expire()?'checked':'' ?>><span class="sl"></span></label>
            </div>
            <div class="set-row">
                <div class="set-info"><div class="set-t">Проверять TLS-сертификат панели и origin</div><div class="set-d">Защита от MITM при запросах к панели и origin. Выключайте только при самоподписанном сертификате.</div></div>
                <label class="switch"><input type="checkbox" name="tls_verify" <?= api_tls_verify()?'checked':'' ?>><span class="sl"></span></label>
            </div>
            <div class="set-row">
                <div class="set-info"><div class="set-t">Источник подписки</div><div class="set-d">«Зеркало» — проксирование origin-домена (как раньше). «Панель» — прослойка работает как sub-сервис Remnawave: конфиги клиентам берутся напрямую с <code>/api/sub</code> панели, а в браузере рендерится страница подписки.</div></div>
                <select name="sub_source">
                    <option value="mirror" <?= sub_source()==='mirror'?'selected':'' ?>>Зеркало (origin)</option>
                    <option value="panel" <?= sub_source()==='panel'?'selected':'' ?>>Панель (sub-сервис)</option>
                </select>
            </div>
            <div class="set-row">
                <div class="set-info"><div class="set-t">Рендер страницы подписки</div><div class="set-d">Только для режима «Панель». «Встроенный бандл» — страницу отдаёт сама прослойка. «Внешний контейнер» — reverse-proxy на отдельно поднятый официальный subscription-page.</div></div>
                <select name="subpage_render">
                    <option value="embedded" <?= subpage_render_mode()==='embedded'?'selected':'' ?>>Встроенный бандл</option>
                    <option value="external" <?= subpage_render_mode()==='external'?'selected':'' ?>>Внешний контейнер</option>
                </select>
            </div>
            <div class="row">
                <div><label>URL внешнего контейнера</label><input type="text" name="subpage_external_url" value="<?= h(subpage_external_url()) ?>" placeholder="http://127.0.0.1:3010"></div>
                <div><label>Токен для страницы</label>
                    <select name="subpage_token_mode">
                        <option value="shared" <?= setting('subpage_token_mode','shared')==='shared'?'selected':'' ?>>API-токен панели</option>
                        <option value="separate" <?= setting('subpage_token_mode','shared')==='separate'?'selected':'' ?>>Отдельный токен</option>
                    </select>
                </div>
            </div>
            <div class="row">
                <div><label>Отдельный токен страницы</label><input type="password" name="subpage_api_key" value="" placeholder="<?= trim((string)setting('subpage_api_key',''))?'•••••• задан':'не задан' ?>"></div>
                <div></div>
            </div>
            <div style="margin-top:1.25rem"><button type="submit">💾 Сохранить подключение</button></div>
        </form>
    </div>

