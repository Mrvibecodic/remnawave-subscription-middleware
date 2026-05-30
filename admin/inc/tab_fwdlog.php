    <div class="card">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap">
            <h2 style="margin:0;font-size:1rem">Лог пересылки (последние 300)</h2>
            <form method="post" onsubmit="return uiConfirmForm(this,'Очистить лог пересылки?')" style="margin:0">
                <input type="hidden" name="csrf" value="<?= h($token) ?>">
                <input type="hidden" name="action" value="clear_fwdlog">
                <button class="danger" type="submit">🧹 Очистить</button>
            </form>
        </div>
        <p class="muted">Исходящие пересылки вебхука адресатам («тройник»). <code>ok</code> = адресат ответил 2xx. Настройка — во вкладке <a href="?tab=webhooks" style="color:var(--accent-text)">Вебхуки → Раздвоение</a>.</p>
        <table class="logtbl" style="margin-top:1rem">
            <tr><th>Время</th><th>Событие</th><th>Адресат</th><th>Код</th><th>Результат</th><th>Ошибка</th></tr>
            <?php foreach ($fwdlog as $r): ?>
            <tr>
                <td class="muted"><?= h($r['ts']) ?></td>
                <td><?= h($r['event']) ?></td>
                <td><?= h($r['target']) ?></td>
                <td class="muted"><?= $r['http_code'] !== null ? h($r['http_code']) : '—' ?></td>
                <td><?= $r['ok'] ? '<span class="tag normal">ok</span>' : '<span class="tag error">fail</span>' ?></td>
                <td class="muted"><?= h(mb_substr((string) $r['error'], 0, 80)) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$fwdlog): ?><tr><td colspan="6" class="muted">Пусто</td></tr><?php endif; ?>
        </table>
    </div>
