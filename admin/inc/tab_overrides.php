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
        <?php
        $od_now = time(); $od_days = expired_grace_days();
        $od_cell = function ($o) use ($ov_expire, $od_now, $od_days) {
            $r = $o['reason'] ?? '';
            if ($r === 'blocked') return 'бессрочно';
            if ($r !== 'expired') return '—';
            $ov_exp  = (($o['match_type'] ?? '') === 'shortuuid' && !empty($ov_expire[$o['match_value']])) ? (int) $ov_expire[$o['match_value']] : 0;
            $till_ts = 0;
            if ($ov_exp > $od_now) {
                $till_ts = $ov_exp;
            } elseif ($od_days <= 0) {
                return 'до продления';
            } else {
                $since = $ov_exp ?: strtotime((string) ($o['created_at'] ?? ''));
                if ($since) $till_ts = (int) $since + $od_days * 86400;
            }
            if ($till_ts > 0) return '<span class="ov-till" data-ts="' . $till_ts . '">' . date('Y-m-d', $till_ts) . '</span>';
            return '—';
        };
        ?>
        <table>
            <tr><th>Тип</th><th>Значение</th><th>Причина</th><th>Источник</th><th>Юзер</th><th>Заметка</th><th>Обновлён</th><th title="До какого момента оверрайд влияет на юзера: blocked — бессрочно; expired с будущим expireAt — дата истечения из панели; с прошлым — expireAt + expired_grace_days; или «до продления»">Действует до</th><th></th></tr>
            <?php foreach ($overrides as $o): ?>
            <tr>
                <td><?= h($o['match_type']) ?></td>
                <td><code><?= h($o['match_value']) ?></code></td>
                <td><span class="tag <?= h($o['reason']) ?>"><?= h($o['reason']) ?></span></td>
                <td><span class="tag <?= h($o['source']) ?>"><?= h($o['source']) ?></span></td>
                <td><?= h($o['username']) ?></td>
                <td><?= h($o['note']) ?></td>
                <td class="muted"><?= h($o['updated_at']) ?></td>
                <td class="muted"><?= $od_cell($o) ?></td>
                <td><form method="post" onsubmit="return uiConfirmForm(this,'Удалить оверрайд?')">
                    <input type="hidden" name="csrf" value="<?= h($token) ?>">
                    <input type="hidden" name="action" value="del_override">
                    <input type="hidden" name="id" value="<?= (int)$o['id'] ?>">
                    <button class="danger" type="submit">×</button></form></td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$overrides): ?><tr><td colspan="9" class="muted">Пусто</td></tr><?php endif; ?>
        </table>
    </div>
    <script>
    document.querySelectorAll('.ov-till[data-ts]').forEach(function(td){var ep=parseInt(td.getAttribute('data-ts'),10);if(!ep)return;var d=new Date(ep*1000);if(isNaN(d.getTime()))return;function p(n){return(n<10?'0':'')+n;}td.textContent=d.getFullYear()+'-'+p(d.getMonth()+1)+'-'+p(d.getDate());});
    </script>
