    <?php $wh_full = ($tab === 'whlog'); ?>
    <div class="card">
        <div class="loghead">
            <h2><?= $wh_full ? 'Юзер-лог вебхуков' : 'Прочие события' ?> (последние 300<?= $wh_full && $wh_flt !== '' ? ' по фильтру' : '' ?>)</h2>
            <div class="loghead-r">
                <?php if ($wh_full): ?>
                <form method="get" style="gap:.4rem">
                    <input type="hidden" name="tab" value="whlog">
                    <input type="text" name="wh_user" value="<?= h($wh_flt) ?>" placeholder="имя / shortUuid" style="max-width:200px">
                    <button class="btn ghost" type="submit">Найти</button>
                    <?php if ($wh_flt !== ''): ?><button class="btn ghost" type="submit" name="wh_user" value="" title="Сбросить фильтр">✕</button><?php endif; ?>
                </form>
                <?php endif; ?>
                <div id="wh_pgrTop" class="pgr"></div>
            </div>
        </div>
        <p class="muted"><?= $wh_full ? 'События, связанные с пользователями: user.*, либо с shortUuid/именем. Фильтр ищет по имени и shortUuid по всему хранимому логу.' : 'Всё остальное: служебные/прочие события и хуки без привязки к пользователю (включая неверную подпись).' ?></p>
        <table class="logtbl">
            <tr><th>Время</th><th>Событие</th><th>Подпись</th><th>Действие</th><?php if ($wh_full): ?><th>shortUuid</th><th>Пользователь</th><th>Статус</th><?php endif; ?></tr>
            <tbody id="whBody" class="lp-cap">
            <?php foreach ($whlog as $r): ?>
            <tr>
                <td class="muted wh-time" data-ts="<?= (int) ($r['ts_epoch'] ?? 0) ?>"><?= h($r['ts']) ?></td>
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
        (function(){
            function whLocal(ep){ep=parseInt(ep,10);if(!ep)return '';var d=new Date(ep*1000);if(isNaN(d.getTime()))return '';function p(n){return(n<10?'0':'')+n;}return d.getFullYear()+'-'+p(d.getMonth()+1)+'-'+p(d.getDate())+' '+p(d.getHours())+':'+p(d.getMinutes())+':'+p(d.getSeconds());}
            document.querySelectorAll('.wh-time[data-ts]').forEach(function(td){var v=whLocal(td.getAttribute('data-ts'));if(v)td.textContent=v;});
            if(window.LogPager) LogPager({bodyId:'whBody', topId:'wh_pgrTop', botId:'wh_pgrBot', colspan:<?= $wh_full ? 7 : 4 ?>, storeKey:'pg_whlog_<?= $wh_full ? 'user' : 'other' ?>'});
        })();
        </script>
    </div>
