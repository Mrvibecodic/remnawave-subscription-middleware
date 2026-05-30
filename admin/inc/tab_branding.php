    <div class="card">
        <h2 style="margin-top:0;font-size:1rem">Брендинг сервиса <button type="button" class="qh" onclick="help('branding')" aria-label="Справка">?</button></h2>
        <p class="muted">Имя и лого тянутся из API панели → идут в название, лого и фавикон админки. Сейчас: «<?= h($brand['name']) ?>»<?= $brand_icon !== '' ? ', лого закешировано' : ', лого не задано' ?>.</p>
        <?php $bc_dbg = json_decode((string) setting('brand_cache', '{}'), true); if (!is_array($bc_dbg)) $bc_dbg = []; ?>
        <p class="muted" style="margin-top:.4rem">Из API: имя — <code><?= h(($bc_dbg['name'] ?? '') !== '' ? $bc_dbg['name'] : '(пусто)') ?></code>; URL лого — <code><?= h(($bc_dbg['logo_url'] ?? '') !== '' ? $bc_dbg['logo_url'] : '(не найден)') ?></code>; файл — <code><?= h(($bc_dbg['logo_file'] ?? '') !== '' ? $bc_dbg['logo_file'] : '(нет)') ?></code>.<?php if (($bc_dbg['api_error'] ?? '') !== ''): ?> <b style="color:var(--c-bad-fg)">Ошибка API:</b> <code><?= h($bc_dbg['api_error']) ?></code>.<?php endif; ?></p>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= h($token) ?>">
            <input type="hidden" name="action" value="save_branding">
            <div class="row">
                <div><label>Имя сервиса <span class="hint">ручное; пусто = из панели</span></label><input type="text" name="service_name" value="<?= h(setting('service_name','')) ?>" placeholder="пусто = из панели"></div>
                <div><label>URL лого <span class="hint">ручное; пусто = из панели</span></label><input type="text" name="service_logo_url" value="<?= h(setting('service_logo_url','')) ?>" placeholder="пусто = из панели"></div>
            </div>
            <?php if ($brand_icon !== ''): ?>
            <div style="margin-top:.85rem;display:flex;align-items:center;gap:.6rem">
                <img src="<?= h($brand_icon) ?>" alt="" style="width:34px;height:34px;border-radius:8px;border:1px solid var(--line);object-fit:contain;background:var(--bg2)">
                <span class="muted">текущее лого (кеш на диске)</span>
            </div>
            <?php endif; ?>
            <div style="margin-top:1.1rem"><button type="submit">💾 Сохранить и обновить из API</button></div>
        </form>
    </div>

    <section class="coll collapsed" data-coll="next_connection">
        <button type="button" class="coll-head" onclick="collToggle(this)">Дальше: Подключение →
            <svg class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
        </button>
        <div class="coll-body">
            <div class="info">В разделе <a href="?tab=connection" style="color:var(--accent-text)">Подключение</a> задаются: origin-домен подписки и домен зеркала, URL и API-токен панели, cookie eGames-защиты, секрет вебхука и таймаут проксирования.</div>
        </div>
    </section>
