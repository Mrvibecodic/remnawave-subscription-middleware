    <?php
    $wh_full   = ($tab === 'whlog');
    $wh_tabkey = $wh_full ? 'whlog' : 'whlog_other';
    // База текущих фильтров — чтобы клик по значению в таблице не сбрасывал остальные.
    $wh_qbase = ['tab' => $wh_tabkey];
    if ($wh_event !== '') $wh_qbase['wh_event'] = $wh_event;
    if ($wh_sig === '1' || $wh_sig === '0') $wh_qbase['wh_sig'] = $wh_sig;
    if ($wh_hours > 0) $wh_qbase['wh_hours'] = $wh_hours;
    if ($wh_full && $wh_act !== '') $wh_qbase['wh_act'] = $wh_act;
    if ($wh_full && $wh_flt !== '') $wh_qbase['wh_user'] = $wh_flt;
    $wh_link = function (array $over) use ($wh_qbase) {
        $q = array_filter(array_merge($wh_qbase, $over), fn ($v) => $v !== '' && $v !== null && $v !== 0);
        return '?' . http_build_query($q);
    };
    $wh_has_flt = count($wh_qbase) > 1;
    $wh_evcls = function ($e) {
        if (strpos((string) $e, 'user_hwid') === 0) return 'manual';
        if (in_array($e, ['user.expired', 'user.disabled', 'user.limited'], true)) return 'EXPIRED';
        if (in_array($e, ['user.deleted', 'user.revoked'], true)) return 'error';
        if (strpos((string) $e, 'user.') === 0) return 'webhook';
        return '';
    };
    $wh_cols = $wh_full ? 7 : 4;
    ?>
    <div class="card">
        <div class="loghead">
            <h2><?= $wh_full ? 'Юзер-лог вебхуков' : 'Прочие события' ?> <span class="muted" style="font-weight:500;font-size:.8rem">хранится: <?= (int) $wh_total ?> · в выборке: <?= (int) $wh_matched ?><?= $wh_matched > 3000 ? ' (показаны последние 3000)' : '' ?></span></h2>
            <div class="loghead-r"><div id="wh_pgrTop" class="pgr"></div></div>
        </div>
        <form method="get" class="wh-flt">
            <input type="hidden" name="tab" value="<?= h($wh_tabkey) ?>">
            <select name="wh_event" onchange="this.form.submit()">
                <option value="">Событие: все</option>
                <?php foreach ($wh_events as $we => $wc): ?>
                <option value="<?= h($we) ?>" <?= $wh_event === (string) $we ? 'selected' : '' ?>><?= h($we) ?> (<?= (int) $wc ?>)</option>
                <?php endforeach; ?>
            </select>
            <?php if ($wh_full): ?>
            <select name="wh_act" onchange="this.form.submit()">
                <option value="">Действие: все</option>
                <?php foreach ($wh_actions as $wa): ?>
                <option value="<?= h($wa) ?>" <?= $wh_act === (string) $wa ? 'selected' : '' ?>><?= h($wa) ?></option>
                <?php endforeach; ?>
            </select>
            <?php endif; ?>
            <select name="wh_sig" onchange="this.form.submit()">
                <option value="">Подпись: любая</option>
                <option value="1" <?= $wh_sig === '1' ? 'selected' : '' ?>>ok</option>
                <option value="0" <?= $wh_sig === '0' ? 'selected' : '' ?>>bad</option>
            </select>
            <select name="wh_hours" onchange="this.form.submit()">
                <option value="0">За всё время</option>
                <option value="1" <?= $wh_hours === 1 ? 'selected' : '' ?>>Последний час</option>
                <option value="24" <?= $wh_hours === 24 ? 'selected' : '' ?>>Сутки</option>
                <option value="168" <?= $wh_hours === 168 ? 'selected' : '' ?>>Неделя</option>
            </select>
            <?php if ($wh_full): ?>
            <input type="text" name="wh_user" value="<?= h($wh_flt) ?>" placeholder="имя / shortUuid" style="max-width:180px">
            <button class="btn ghost" type="submit">Найти</button>
            <?php endif; ?>
            <?php if ($wh_has_flt): ?><a class="btn ghost" href="?tab=<?= h($wh_tabkey) ?>" title="Сбросить все фильтры">✕ Сброс</a><?php endif; ?>
            <button class="btn ghost" type="submit" name="wh_csv" value="1" title="Выгрузить текущую выборку целиком (по фильтру, не только видимую страницу)">⬇ CSV</button>
        </form>
        <p class="muted"><?= $wh_full
            ? 'События, связанные с пользователями: user.*, либо с shortUuid/именем. Клик по событию, пользователю, shortUuid или действию в таблице ставит фильтр по этому значению.'
            : 'Всё остальное: служебные/прочие события и хуки без привязки к пользователю (включая неверную подпись). Клик по событию ставит фильтр.' ?></p>
        <table class="logtbl">
            <tr><th>Время</th><th>Событие</th><th>Подпись</th><th>Действие</th><?php if ($wh_full): ?><th>shortUuid</th><th>Пользователь</th><th>Статус</th><?php endif; ?></tr>
            <tbody id="whBody" class="lp-cap">
            <?php foreach ($whlog as $r): ?>
            <?php $wh_ec = $wh_evcls($r['event']); ?>
            <tr>
                <td class="muted wh-time" data-ts="<?= (int) ($r['ts_epoch'] ?? 0) ?>"><?= h($r['ts']) ?></td>
                <td><a href="<?= h($wh_link(['wh_event' => (string) $r['event']])) ?>" title="Фильтровать по событию"><?= $wh_ec !== '' ? '<span class="tag ' . h($wh_ec) . '">' . h($r['event']) . '</span>' : h($r['event']) ?></a></td>
                <td><?= $r['sig_ok'] ? '<span class="tag normal">ok</span>' : '<span class="tag error">bad</span>' ?></td>
                <td><?php if ($wh_full && (string) $r['action'] !== ''): ?><a href="<?= h($wh_link(['wh_act' => (string) $r['action']])) ?>" title="Фильтровать по действию"><span class="tag <?= h($r['action']) ?>"><?= h($r['action']) ?></span></a><?php else: ?><span class="tag <?= h($r['action']) ?>"><?= h($r['action']) ?></span><?php endif; ?></td>
                <?php if ($wh_full): ?>
                <td><?= (string) $r['short_uuid'] !== '' ? '<a href="' . h($wh_link(['wh_user' => (string) $r['short_uuid']])) . '" title="Фильтровать по shortUuid"><code>' . h($r['short_uuid']) . '</code></a>' : '<span class="muted">—</span>' ?></td>
                <td><?= (string) $r['username'] !== '' ? '<a href="' . h($wh_link(['wh_user' => (string) $r['username']])) . '" title="Фильтровать по пользователю">' . h($r['username']) . '</a>' : '<span class="muted">—</span>' ?></td>
                <td><?= (string) $r['status'] !== '' ? '<span class="tag ' . h($r['status']) . '">' . h($r['status']) . '</span>' : '<span class="muted">—</span>' ?></td>
                <?php endif; ?>
            </tr>
            <?php endforeach; ?>
            <?php if (!$whlog): ?><tr><td colspan="<?= $wh_cols ?>" class="muted">Пусто</td></tr><?php endif; ?>
            </tbody>
        </table>
        <div id="wh_pgrBot" class="pgr-bot"></div>
        <style>
        .wh-flt{display:flex;gap:.45rem;flex-wrap:wrap;align-items:center;margin:.2rem 0 .7rem}
        .wh-flt select{width:auto;max-width:280px}
        .wh-flt .btn.ghost{display:inline-flex;align-items:center;white-space:nowrap}
        .logtbl a:hover .tag,.logtbl a:hover code{outline:1px solid var(--accent);outline-offset:1px}
        </style>
        <script>
        (function(){
            function whLocal(ep){ep=parseInt(ep,10);if(!ep)return '';var d=new Date(ep*1000);if(isNaN(d.getTime()))return '';function p(n){return(n<10?'0':'')+n;}return d.getFullYear()+'-'+p(d.getMonth()+1)+'-'+p(d.getDate())+' '+p(d.getHours())+':'+p(d.getMinutes())+':'+p(d.getSeconds());}
            document.querySelectorAll('.wh-time[data-ts]').forEach(function(td){var v=whLocal(td.getAttribute('data-ts'));if(v)td.textContent=v;});
            if(window.LogPager) LogPager({bodyId:'whBody', topId:'wh_pgrTop', botId:'wh_pgrBot', colspan:<?= $wh_cols ?>, storeKey:'pg_whlog_<?= $wh_full ? 'user' : 'other' ?>'});
        })();
        </script>
    </div>
