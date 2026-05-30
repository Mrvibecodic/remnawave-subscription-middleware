    <div class="card">
        <h2 style="margin-top:0;font-size:1rem">Добавить оверрайд вручную</h2>
        <p class="muted"><b>blocked</b> — жёсткая блокировка по HWID: прослойка отдаёт конфиг-заглушку, снимается только здесь. Тексты заглушки — во вкладке <a href="?tab=hwid" style="color:var(--accent-text)">HWID</a>. <b>expired</b> — пометка истёкшей подписки (будущий expire из заголовка перебьёт); подменой для истёкших занимается грейс-сквад, см. вкладку <a href="?tab=subst" style="color:var(--accent-text)">Грейс-сквад</a>.</p>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= h($token) ?>">
            <input type="hidden" name="action" value="add_override">
            <div class="row">
                <div><label>Тип</label><select name="match_type"><option value="shortuuid">shortUuid</option><option value="hwid">HWID</option></select></div>
                <div><label>Значение</label><input type="text" name="match_value" placeholder="shortUuid или HWID"></div>
                <div><label>Причина</label><select name="reason"><option value="expired">expired</option><option value="blocked">blocked</option></select></div>
                <div><label>Заметка</label><input type="text" name="note"></div>
                <div style="flex:0"><button type="submit">➕ Добавить</button></div>
            </div>
        </form>
    </div>
    <div class="card">
        <h2 style="margin-top:0;font-size:1rem">Активные оверрайды (<?= count($overrides) ?>)</h2>
        <table>
            <tr><th>Тип</th><th>Значение</th><th>Причина</th><th>Источник</th><th>Юзер</th><th>Заметка</th><th>Обновлён</th><th></th></tr>
            <?php foreach ($overrides as $o): ?>
            <tr>
                <td><?= h($o['match_type']) ?></td>
                <td><code><?= h($o['match_value']) ?></code></td>
                <td><span class="tag <?= h($o['reason']) ?>"><?= h($o['reason']) ?></span></td>
                <td><span class="tag <?= h($o['source']) ?>"><?= h($o['source']) ?></span></td>
                <td><?= h($o['username']) ?></td>
                <td><?= h($o['note']) ?></td>
                <td class="muted"><?= h($o['updated_at']) ?></td>
                <td><form method="post" onsubmit="return uiConfirmForm(this,'Удалить оверрайд?')">
                    <input type="hidden" name="csrf" value="<?= h($token) ?>">
                    <input type="hidden" name="action" value="del_override">
                    <input type="hidden" name="id" value="<?= (int)$o['id'] ?>">
                    <button class="danger" type="submit">×</button></form></td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$overrides): ?><tr><td colspan="8" class="muted">Пусто</td></tr><?php endif; ?>
        </table>
    </div>
