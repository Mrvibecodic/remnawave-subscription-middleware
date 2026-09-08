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
    // Даты отдаём через .wh-time[data-ts]: их локализует тот же скрипт, что и колонку
    // «Время», иначе в раскрытии висел бы UTC-ISO из панели.
    $whlog_cell = function ($k, $v) {
        if (whlog_is_date($k) && ($ep = whlog_epoch($v)) > 0) {
            return '<span class="wh-time" data-ts="' . $ep . '">' . h(whlog_fmt_value($k, $v)) . '</span>';
        }
        return h(whlog_fmt_value($k, $v));
    };
    $wh_cols = $wh_full ? 8 : 4;
    ?>
    <style>
    .wh-flt{display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;margin:.2rem 0 .8rem}
    .wh-flt select,.wh-flt input[type=text],.wh-flt .btn{height:38px;min-height:38px;box-sizing:border-box;padding-top:0;padding-bottom:0;font-size:.84rem;border-radius:var(--radius)}
    .wh-flt select{width:auto;max-width:300px;flex:0 1 auto;text-overflow:ellipsis;font-weight:500}
    .wh-flt select:hover{border-color:var(--accent)}
    .wh-flt input[type=text]{width:190px;flex:0 1 auto}
    .wh-flt .btn{display:inline-flex;align-items:center;gap:.45rem;white-space:nowrap;line-height:1;font-weight:600}
    .wh-flt .btn svg{width:15px;height:15px;flex:0 0 auto}
    .wh-exp{margin-left:auto;display:flex;align-items:center;gap:.6rem;flex-wrap:wrap}
    .wh-anon{display:inline-flex;align-items:center;gap:.35rem;font-size:.82rem;white-space:nowrap;cursor:pointer}
    .wh-anon input{width:15px;height:15px;margin:0;flex:0 0 auto}
    .wh-tg{display:flex;align-items:flex-start;gap:.4rem;padding:0;margin:0;border:0;background:none;color:inherit;font:inherit;cursor:pointer;text-align:left}
    .wh-tg .wh-cv{width:13px;height:13px;margin-top:.3rem;flex:0 0 auto;color:var(--muted);transition:transform .15s}
    .wh-tg:hover .wh-cv{color:var(--accent-text)}
    .wh-tg[aria-expanded="true"] .wh-cv{transform:rotate(90deg)}
    .wh-tg .wh-tgs{display:flex;gap:.35rem;flex-wrap:wrap;align-items:center;min-width:0}
    .wh-det>td{padding:0}
    .wh-pan{display:flex;flex-direction:column;align-items:center;gap:.55rem;padding:.85rem 1rem;background:var(--hover2)}
    .wh-dt-w{max-width:100%;overflow-x:auto}
    .wh-dt{margin:0;border-collapse:collapse;font-size:.8rem;background:var(--card)}
    .wh-dt th,.wh-dt td{padding:.3rem .7rem;border:1px solid var(--line);text-align:left;vertical-align:top;white-space:nowrap}
    .wh-snap{margin:0;font-size:.78rem;text-align:center}
    .logtbl a:hover .tag,.logtbl a:hover code{outline:1px solid var(--accent);outline-offset:1px}
    @media(max-width:760px){
        .wh-flt select,.wh-flt input[type=text]{flex:1 1 46%;max-width:none;width:auto}
        .wh-flt .btn{flex:1 0 auto;justify-content:center}
        .wh-exp{margin-left:0;flex:1 1 100%}
    }
    </style>
    <div class="seg">
        <a class="<?= $wh_full ? 'on' : '' ?>" href="?tab=whlog">Юзер-лог</a>
        <a class="<?= $wh_full ? '' : 'on' ?>" href="?tab=whlog_other">Прочие события</a>
    </div>
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
            <input type="text" name="wh_user" value="<?= h($wh_flt) ?>" placeholder="имя / shortUuid">
            <button class="btn ghost" type="submit"><svg width="15" height="15" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>Найти</button>
            <?php endif; ?>
            <?php if ($wh_has_flt): ?><a class="btn ghost" href="?tab=<?= h($wh_tabkey) ?>" title="Сбросить все фильтры"><svg width="15" height="15" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>Сброс</a><?php endif; ?>
            <span class="wh-exp"><label class="wh-anon" title="Заменить shortUuid, имя пользователя и uuid сквадов на стабильные метки — файл можно показать постороннему"><input type="checkbox" name="wh_mask" value="1" checked> Обезличить</label><button class="btn ghost" type="submit" name="wh_csv" value="1" title="Выгрузить текущую выборку целиком (по фильтру, не только видимую страницу)"><svg width="15" height="15" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>Скачать CSV</button></span>
        </form>
        <p class="muted"><?= $wh_full
            ? 'События, связанные с пользователями: user.*, либо с shortUuid/именем. Клик по событию, пользователю, shortUuid или действию в таблице ставит фильтр по этому значению.'
            : 'Всё остальное: служебные/прочие события и хуки без привязки к пользователю (включая неверную подпись). Клик по событию ставит фильтр.' ?></p>
        <table class="logtbl">
            <tr><th>Время</th><th>Событие</th><th>Подпись</th><th>Действие</th><?php if ($wh_full): ?><th>shortUuid</th><th>Пользователь</th><th>Статус</th><th>Изменения</th><?php endif; ?></tr>
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
                <td class="wh-chg">
                    <?php $wm = whlog_meta($r); ?>
                    <?php if ($wm && $wm['d']): ?>
                    <button type="button" class="wh-tg" aria-expanded="false">
                        <svg class="wh-cv" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
                        <span class="wh-tgs">
                            <span class="tag manual"><?= h(implode(', ', array_map('whlog_field_label', array_keys($wm['d'])))) ?></span>
                            <?php if ($wm['mw'] === 1): ?><span class="tag normal" title="Это изменение сделала прослойка<?= $wm['src'] !== '' ? ': ' . h($wm['src']) : '' ?>">прослойка</span>
                            <?php elseif ($wm['mw'] === 0): ?><span class="tag error" title="Перед этим событием прослойка ничего такого в панель не писала">извне</span><?php endif; ?>
                        </span>
                    </button>
                    <template class="wh-tpl">
                        <div class="wh-pan">
                            <div class="wh-dt-w">
                                <table class="wh-dt">
                                    <tr><th>Поле</th><th>Было</th><th>Стало</th></tr>
                                    <?php foreach ($wm['d'] as $dk => $dv): ?>
                                    <tr>
                                        <td><?= h(whlog_field_label($dk)) ?></td>
                                        <td class="muted"><?= $whlog_cell($dk, $dv[0] ?? null) ?></td>
                                        <td><?= $whlog_cell($dk, $dv[1] ?? null) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </table>
                            </div>
                            <?php if ($wm['s']): ?>
                            <p class="muted wh-snap">Снимок: сквады <?= h(whlog_fmt_value('sq', whlog_squad_names($wm['s']['sq'] ?? []))) ?> · лимит <?= h(whlog_fmt_value('tl', $wm['s']['tl'] ?? null)) ?> · израсходовано <?= h(whlog_fmt_value('used', $wm['s']['used'] ?? null)) ?> · устройств <?= h(whlog_fmt_value('hw', $wm['s']['hw'] ?? null)) ?> · истекает <?= $whlog_cell('exp', $wm['s']['exp'] ?? null) ?></p>
                            <?php endif; ?>
                        </div>
                    </template>
                    <?php elseif ($wm && $wm['s']): ?><span class="muted" title="Состояние пользователя не изменилось">без изменений</span>
                    <?php else: ?><span class="muted">—</span><?php endif; ?>
                </td>
                <?php endif; ?>
            </tr>
            <?php endforeach; ?>
            <?php if (!$whlog): ?><tr><td colspan="<?= $wh_cols ?>" class="muted">Пусто</td></tr><?php endif; ?>
            </tbody>
        </table>
        <div id="wh_pgrBot" class="pgr-bot"></div>
        <script>
        (function(){
            function whLocal(ep){ep=parseInt(ep,10);if(!ep)return '';var d=new Date(ep*1000);if(isNaN(d.getTime()))return '';function p(n){return(n<10?'0':'')+n;}return d.getFullYear()+'-'+p(d.getMonth()+1)+'-'+p(d.getDate())+' '+p(d.getHours())+':'+p(d.getMinutes())+':'+p(d.getSeconds());}
            document.querySelectorAll('.wh-time[data-ts]').forEach(function(td){var v=whLocal(td.getAttribute('data-ts'));if(v)td.textContent=v;});
            if(window.LogPager) LogPager({bodyId:'whBody', topId:'wh_pgrTop', botId:'wh_pgrBot', colspan:<?= $wh_cols ?>, storeKey:'pg_whlog_<?= $wh_full ? 'user' : 'other' ?>'});
            var whBody=document.getElementById('whBody');
            if(whBody){
                // Панель раскрытия живёт отдельной строкой на всю ширину, а не в ячейке
                // «Изменения»: иначе её содержимое расширяет колонку и тянет всю таблицу.
                // До первого клика она лежит в теге template — не рисуется и не сбивает
                // ни анти-FOUC отсечку по nth-child, ни счёт строк в LogPager.
                function whSync(){
                    whBody.querySelectorAll('tr.wh-det').forEach(function(tr){
                        var p=tr.previousElementSibling;
                        var vis=tr.classList.contains('open') && !(p && p.style.display==='none');
                        tr.style.display=vis?'':'none';
                    });
                }
                whBody.addEventListener('click',function(e){
                    var b=e.target.closest ? e.target.closest('.wh-tg') : null;
                    if(!b||!whBody.contains(b))return;
                    var tr=b.parentNode;
                    while(tr&&tr.tagName!=='TR')tr=tr.parentNode;
                    if(!tr)return;
                    var det=tr.nextElementSibling;
                    if(!det||!det.classList.contains('wh-det')){
                        var tpl=tr.querySelector('.wh-tpl');
                        if(!tpl)return;
                        det=document.createElement('tr');
                        det.className='wh-det';
                        var td=document.createElement('td');
                        td.colSpan=<?= $wh_cols ?>;
                        td.appendChild(tpl.content.cloneNode(true));
                        det.appendChild(td);
                        det.style.display='none';
                        tr.parentNode.insertBefore(det,tr.nextSibling);
                    }
                    var open=!det.classList.contains('open');
                    det.classList.toggle('open',open);
                    b.setAttribute('aria-expanded',open?'true':'false');
                    whSync();
                });
                ['wh_pgrTop','wh_pgrBot'].forEach(function(id){
                    var el=document.getElementById(id);
                    if(!el)return;
                    el.addEventListener('click',function(){setTimeout(whSync,0);});
                    el.addEventListener('change',function(){setTimeout(whSync,0);});
                });
            }
        })();
        </script>
    </div>
