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

    <?php $lp = landing_preset(); ?>
    <div class="card">
        <h2 style="margin-top:0;font-size:1rem">Дизайн страницы-приманки</h2>
        <p class="muted">Как выглядит публичная корневая страница зеркала — фальшивый вход в личный кабинет, который видят посторонние и сканеры. Выберите один из вариантов.<?= chat_enabled() ? ' Сейчас на ней также показывается виджет чата поддержки.' : ' Виджет чата появится на ней, если включить чат в разделе «Чат поддержки».' ?></p>
        <style>
            .lpick{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:.8rem;margin:.6rem 0}
            .lpick label{display:block;border:2px solid var(--line);border-radius:12px;padding:.7rem;cursor:pointer;background:var(--bg2);transition:border-color .15s}
            .lpick label.sel{border-color:var(--accent)}
            .lpick input{display:none}
            .lpick .pv{height:96px;border-radius:8px;overflow:hidden;border:1px solid var(--line);display:flex;align-items:center;justify-content:center;background:#eef2f7}
            .lpick .nm{font-size:.82rem;font-weight:600;color:var(--text-strong);margin-top:.5rem;text-align:center}
            .pv-card{width:62px;height:74px;background:#fff;border:1px solid #e5e7eb;border-radius:8px;box-shadow:0 6px 14px rgba(31,41,55,.12);display:flex;flex-direction:column;align-items:center;gap:5px;padding:9px 8px}
            .pv-dot{width:18px;height:18px;border-radius:6px;background:linear-gradient(160deg,#4f46e5,#7c73f0)}
            .pv-l{width:100%;height:7px;border-radius:3px;background:#e5e7eb}
            .pv-b{width:100%;height:9px;border-radius:3px;background:#4f46e5;margin-top:auto}
            .pv2{display:flex;width:84px;height:74px;border-radius:8px;overflow:hidden;box-shadow:0 6px 14px rgba(31,41,55,.12)}
            .pv2 .s{width:42%;background:linear-gradient(150deg,#4f46e5,#7c3aed)}
            .pv2 .m{flex:1;background:#fff;display:flex;flex-direction:column;gap:5px;padding:9px 8px}
            .pv3{background:#0b1020;background-image:radial-gradient(60px 40px at 20% 0,rgba(99,102,241,.6),transparent)}
            .pv3 .pv-card{background:rgba(255,255,255,.08);border-color:rgba(255,255,255,.18)}
            .pv3 .pv-l{background:rgba(255,255,255,.18)}
            .pv3 .pv-dot{background:linear-gradient(160deg,#6366f1,#22d3ee)}
            .pv3 .pv-b{background:linear-gradient(120deg,#6366f1,#22d3ee)}
            .pv4{background:#f8fafc}
            .pv4 .pv-dot{background:#0f766e;border-radius:50%}
            .pv4 .pv-b{background:#0f766e}
        </style>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= h($token) ?>">
            <input type="hidden" name="action" value="save_landing">
            <div class="lpick">
                <label class="<?= $lp===1?'sel':'' ?>"><input type="radio" name="landing_preset" value="1" <?= $lp===1?'checked':'' ?>>
                    <div class="pv"><div class="pv-card"><div class="pv-dot"></div><div class="pv-l"></div><div class="pv-l"></div><div class="pv-b"></div></div></div>
                    <div class="nm">Классическая карточка</div></label>
                <label class="<?= $lp===2?'sel':'' ?>"><input type="radio" name="landing_preset" value="2" <?= $lp===2?'checked':'' ?>>
                    <div class="pv"><div class="pv2"><div class="s"></div><div class="m"><div class="pv-l"></div><div class="pv-l"></div><div class="pv-b"></div></div></div></div>
                    <div class="nm">Сплит-экран</div></label>
                <label class="<?= $lp===3?'sel':'' ?>"><input type="radio" name="landing_preset" value="3" <?= $lp===3?'checked':'' ?>>
                    <div class="pv pv3"><div class="pv-card"><div class="pv-dot"></div><div class="pv-l"></div><div class="pv-l"></div><div class="pv-b"></div></div></div>
                    <div class="nm">Тёмная (стекло)</div></label>
                <label class="<?= $lp===4?'sel':'' ?>"><input type="radio" name="landing_preset" value="4" <?= $lp===4?'checked':'' ?>>
                    <div class="pv pv4"><div class="pv-card"><div class="pv-dot"></div><div class="pv-l"></div><div class="pv-l"></div><div class="pv-b"></div></div></div>
                    <div class="nm">Минимализм</div></label>
            </div>
            <div style="margin-top:.6rem"><button type="submit">💾 Сохранить дизайн</button></div>
        </form>
        <script>document.querySelectorAll('.lpick label').forEach(function(l){l.addEventListener('click',function(){document.querySelectorAll('.lpick label').forEach(function(x){x.classList.remove('sel');});l.classList.add('sel');});});</script>
    </div>

