    <?php $wh_full = ($tab === 'whlog'); ?>
    <div class="card">
        <div class="loghead">
            <h2><?= $wh_full ? 'Юзер-лог вебхуков' : 'Прочие события' ?> (последние 300)</h2>
            <div class="loghead-r"><div id="wh_pgrTop" class="pgr"></div></div>
        </div>
        <p class="muted"><?= $wh_full ? 'События, связанные с пользователями: user.*, либо с shortUuid/именем.' : 'Всё остальное: служебные/прочие события и хуки без привязки к пользователю (включая неверную подпись).' ?></p>
        <table class="logtbl">
            <tr><th>Время</th><th>Событие</th><th>Подпись</th><th>Действие</th><?php if ($wh_full): ?><th>shortUuid</th><th>Пользователь</th><th>Статус</th><?php endif; ?></tr>
            <tbody id="whBody">
            <?php foreach ($whlog as $r): ?>
            <tr>
                <td class="muted"><?= h($r['ts']) ?></td>
                <td><?= h($r['event']) ?></td>
                <td><?= $r['sig_ok'] ? '<span class="tag normal">ok</span>' : '<span class="tag error">bad</span>' ?></td>
                <td><span class="tag <?= h($r['action']) ?>"><?= h($r['action']) ?></span></td>
                <?php if ($wh_full): ?>
                <td><code><?= h($r['short_uuid']) ?></code></td>
                <td><?= h($r['username']) ?></td>
                <td><?= h($r['status']) ?></td>
                <?php endif; ?>
            </tr>
            <?php endforeach; ?>
            <?php if (!$whlog): ?><tr><td colspan="<?= $wh_full ? 7 : 4 ?>" class="muted">Пусто</td></tr><?php endif; ?>
            </tbody>
        </table>
        <div id="wh_pgrBot" class="pgr-bot"></div>
        <script>
        (function(){ if(window.LogPager) LogPager({bodyId:'whBody', topId:'wh_pgrTop', botId:'wh_pgrBot', colspan:<?= $wh_full ? 7 : 4 ?>, storeKey:'pg_whlog_<?= $wh_full ? 'user' : 'other' ?>'}); })();
        </script>
    </div>
