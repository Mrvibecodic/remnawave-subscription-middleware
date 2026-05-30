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
            <div style="margin-top:1.25rem"><button type="submit">💾 Сохранить подключение</button></div>
        </form>
    </div>

    <section class="coll collapsed" data-coll="next_webhooks">
        <button type="button" class="coll-head" onclick="collToggle(this)">Дальше: Вебхуки →
            <svg class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
        </button>
        <div class="coll-body">
            <div class="info">В разделе <a href="?tab=webhooks" style="color:var(--accent-text)">Вебхуки</a> — как включить вебхук в <code>.env</code> панели и «раздвоение» (пересылка того же хука нескольким адресатам с переподписью своим ключом).</div>
        </div>
    </section>
