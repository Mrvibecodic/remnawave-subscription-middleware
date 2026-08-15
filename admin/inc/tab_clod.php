<?php
// Вкладка «Защищённый канал» (протокол c1, клиенты Clod Clash).
$chan_ok    = chan_ext_ok();
$chan_fp    = $chan_ok ? chan_fingerprint() : '';
$chan_idx   = chan_index_info();
$chan_st    = chan_stats();
$chan_rows  = chan_state_list(500);
$chan_len   = function_exists('panel_short_uuid_len') ? (int) panel_short_uuid_len() : 0;
$chan_api   = remnawave_url() !== '' && remnawave_token() !== '';
$chan_marks = implode("\n", chan_hard_remarks());
$chan_dbg   = chan_debug_on() ? chan_debug_list(chan_debug_keep()) : [];
// Звёзды рисуем из кэша (трое суток). В GitHub ходит ajax уже после загрузки
// страницы и только если кэш протух — вкладка не должна ждать сеть.
$chan_stars = chan_stars_cache();
$chan_upd   = chan_stars_stale($chan_stars);
$chan_plu   = function ($n, $one, $few, $many) {
    $t = abs((int) $n) % 100;
    if ($t > 10 && $t < 20) return $many;
    $t %= 10;
    if ($t === 1) return $one;
    if ($t >= 2 && $t <= 4) return $few;
    return $many;
};
?>
    <style>
    .cdbg{font-size:.78rem}
    .cdbg details{border-top:1px solid var(--line)}
    .cdbg details:first-of-type{border-top:0}
    .cdbg summary{cursor:pointer;padding:.34rem .2rem;display:flex;gap:.55rem;align-items:center;list-style:none;overflow:hidden;white-space:nowrap}
    .cdbg summary::-webkit-details-marker{display:none}
    .cdbg summary:hover{background:var(--accent-light)}
    .cdbg .n{font-variant-numeric:tabular-nums;flex:0 0 auto}
    .cdbg .g{flex:1 1 auto;overflow:hidden;text-overflow:ellipsis}
    .cdbg .lbl{font-weight:600;margin:.5rem 0 .15rem;font-size:.74rem}
    .cdbg pre{margin:0;padding:.45rem .55rem;font-size:.7rem;line-height:1.35;max-height:13rem;overflow:auto;white-space:pre-wrap;word-break:break-all;background:var(--bg2);border:1px solid var(--line)}
    .capps{display:flex;gap:.7rem;flex-wrap:wrap;margin:.9rem 0 0}
    .capp{flex:1 1 16rem;min-width:0;display:flex;align-items:center;gap:.7rem;padding:.7rem .8rem;border:1px solid var(--line);border-radius:12px;background:var(--bg2);color:var(--text);text-decoration:none;transition:border-color .18s,background .18s,transform .18s,box-shadow .18s}
    .capp:hover{border-color:var(--accent);background:var(--accent-light);transform:translateY(-1px);box-shadow:0 4px 14px rgba(0,0,0,.12)}
    .capp .gh{flex:0 0 auto;color:var(--text-strong)}
    .capp .tx{flex:1 1 auto;min-width:0;display:flex;flex-direction:column;line-height:1.25}
    .capp .nm{font-weight:600;font-size:.92rem;color:var(--text-strong)}
    .capp .os{font-size:.74rem;color:var(--muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    .capp .st{flex:0 0 auto;display:inline-flex;align-items:center;gap:.25rem;padding:.2rem .5rem;border:1px solid var(--line);border-radius:999px;background:var(--card);color:var(--muted);font-size:.78rem;font-weight:600;font-variant-numeric:tabular-nums}
    /* Иначе `display:inline-flex` перебивает браузерное `[hidden]`, и пустой
       бейдж висел бы одинокой звездой, пока не ответит GitHub. */
    .capp .st[hidden]{display:none}
    .capp:hover .st{border-color:var(--accent);color:var(--accent-text)}
    .capp .st svg{color:var(--amber)}
    </style>
    <div class="card">
        <h2 style="margin-top:0;font-size:1rem">Работает только с Clod Clash</h2>
        <p class="muted">Это протокол двух конкретных приложений и этой прослойки, а не стандарт подписок: никакой другой клиент про него не знает и по нему работать не будет. Обычные подписки идут прежним путём, канал их не касается.</p>
        <div class="capps" id="clodApps" data-upd="<?= $chan_upd ? '1' : '0' ?>">
            <?php foreach (chan_client_apps() as $app):
                $cs = is_array($chan_stars[$app['repo']] ?? null) ? $chan_stars[$app['repo']] : [];
                $cn = isset($cs['n']) ? (int) $cs['n'] : null; ?>
            <a class="capp" href="https://github.com/<?= h($app['repo']) ?>" target="_blank" rel="noopener"
               data-tip="github.com/<?= h($app['repo']) ?>">
                <svg class="gh" width="22" height="22" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82a7.4 7.4 0 0 1 2-.27c.68 0 1.36.09 2 .27 1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.01 8.01 0 0 0 16 8c0-4.42-3.58-8-8-8Z"/></svg>
                <span class="tx"><span class="nm"><?= h($app['name']) ?></span><span class="os"><?= h($app['os']) ?></span></span>
                <span class="st" data-repo="<?= h($app['repo']) ?>"<?= $cn === null ? ' hidden' : '' ?>>
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="m12 2 2.9 6.26 6.85.72-5.1 4.6 1.42 6.72L12 16.9 5.93 20.3l1.42-6.72-5.1-4.6 6.85-.72L12 2Z"/></svg>
                    <span class="n"><?= $cn === null ? '' : (int) $cn ?></span>
                </span>
            </a>
            <?php endforeach; ?>
        </div>
        <p class="muted" style="margin-top:.8rem">Галочку «Защищённое соединение» пользователь ставит сам, отдельно для каждой подписки. Пока её не поставили, запрос идёт открытым путём, как раньше. Канал — свойство запроса, а не пользователя.</p>
    </div>

    <div class="card">
        <h2 style="margin-top:0;font-size:1rem">Готовность</h2>
        <p class="muted">Канал прячет от посредника, который терминирует TLS, всё разом: адрес подписки вместе с токеном, карточку устройства, заголовки ответа и тело.</p>
        <table class="logtbl">
            <tbody>
            <tr><td style="width:2.2rem"><?= $chan_ok ? '✅' : '❌' ?></td>
                <td>Расширение <b>sodium</b> в PHP <?= h(PHP_VERSION) ?> · <?= h(PHP_SAPI) ?><?= $chan_ok ? '' : ' — нет в этой сборке, канал выключен принудительно. Что делать — в блоке ниже.' ?></td></tr>
            <tr><td><?= $chan_fp !== '' ? '✅' : '❌' ?></td>
                <td>Ключ прослойки<?= $chan_fp !== '' ? ' — отпечаток <code>' . h($chan_fp) . '</code>' : ' — ещё не создан, появится при первом защищённом запросе' ?></td></tr>
            <tr><td><?= $chan_api ? '✅' : '❌' ?></td>
                <td>Доступ к панели<?= $chan_api ? '' : ' — без URL и API-токена метки подписок собрать не из чего' ?></td></tr>
            <tr><td><?= $chan_idx['fresh'] ? '✅' : '⚠️' ?></td>
                <td>Индекс меток — <?= (int) $chan_idx['count'] ?> <?= $chan_plu((int) $chan_idx['count'], 'подписка', 'подписки', 'подписок') ?> на сегодня<?php if ((int) $chan_idx['ts'] > 0): ?>, полный обход <span class="ct-time" data-ts="<?= (int) $chan_idx['ts'] ?>"><?= h(date('Y-m-d H:i', (int) $chan_idx['ts'])) ?></span><?php endif; ?><?= $chan_idx['fresh'] || (int) $chan_idx['count'] === 0 ? '' : ' <b>(меток на сегодня нет)</b>' ?>
                    <?php if ((int) $chan_idx['count'] === 0): ?>
                        <div class="muted" style="margin-top:.35rem">Индекс пока пуст: без него прослойка не узнаёт подписку по метке и отвечает как на мусорный адрес. Нажмите «Пересобрать».</div>
                    <?php else: ?>
                        <div class="muted" style="margin-top:.35rem">Число считается по самому индексу, а не по последнему обходу: вебхук досыпает метки созданному пользователю и снимает их у удалённого, поэтому оно меняется сразу.</div>
                    <?php endif; ?>
                </td></tr>
            </tbody>
        </table>
        <?php if ($chan_len > 0 && $chan_len < 16): ?>
        <div class="warn" style="margin-top:1rem">
            <b>Короткий адрес подписки.</b> В панели shortUuid длиной <?= $chan_len ?> символа — это меньше 96 бит секрета,
            а весь канал держится именно на нём: токен и есть общий ключ. Такой секрет перебирается офлайн по одному
            перехваченному запросу. Закрепление ключа прослойки от этого не спасает: посредник, который знает токен,
            подставляет клиенту свой ключ при первом же контакте. Лечится только длиной shortUuid в панели.
        </div>
        <?php endif; ?>
        <form method="post" style="margin-top:1rem">
            <input type="hidden" name="csrf" value="<?= h($token) ?>">
            <input type="hidden" name="action" value="clod_reindex">
            <button type="submit" class="ghost">↻ Пересобрать индекс меток</button>
            <span class="hint">Обычно не нужно: созданного и удалённого проводит вебхук, а полный обход прослойка делает сама, когда встречает незнакомую метку.</span>
        </form>
    </div>

    <?php if (!$chan_ok): ?>
    <div class="card">
        <h2 style="margin-top:0;font-size:1rem">Если расширения sodium нет</h2>
        <p class="muted">Вся криптография канала — это ext-sodium и встроенный в PHP <code>hash_hkdf</code>, своей нет. Расширение входит в состав PHP и в большинстве сборок уже включено, так что чаще всего дело не в том, что его надо «доставить». По убыванию вероятности:</p>
        <ol class="muted" style="line-height:1.7;padding-left:1.2rem">
            <li><b>Смотреть надо на ту сборку, что названа выше</b> — <?= h(PHP_VERSION) ?> · <?= h(PHP_SAPI) ?>. На сервере обычно стоит несколько PHP сразу, и сайту отвечает не тот, что запускается в терминале: вывод <code>php -m</code> из консоли про эту строку не говорит ничего.</li>
            <li><b>PHP ставила панель управления хостингом</b> — у неё свой набор сборок и свой список модулей, отдельный для каждой версии. Включать расширение надо там, для версии <?= h(PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION) ?>.</li>
            <li><b>PHP из репозитория дистрибутива</b> — в Debian и Ubuntu sodium вкомпилен внутрь самого PHP, и пакета <code>php-sodium</code> там не существует вовсе: ставить нечего, помогает обновление PHP до штатной версии.</li>
            <li><b>Расширение собрано отдельным модулем</b> — включить его: <code>phpenmod -v <?= h(PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION) ?> sodium</code> либо строка <code>extension=sodium</code> в php.ini этой сборки.</li>
            <li><b>Докер</b> — пересобрать образ.</li>
        </ol>
        <p class="muted">После включения перезапустите обработчик PHP, который обслуживает сайт, и обновите эту страницу.</p>
        <p class="muted">Пока расширения нет, прослойка работает как обычно и ничего не теряет: выключен только канал, а адрес <code>/c1/…</code> обрабатывается как любой мусорный.</p>
    </div>
    <?php endif; ?>

    <div class="card">
        <h2 style="margin-top:0;font-size:1rem">Тумблеры</h2>
        <form method="post" data-autosave>
            <input type="hidden" name="csrf" value="<?= h($token) ?>">
            <input type="hidden" name="action" value="save_clod">
            <div class="set-row">
                <div class="set-info"><div class="set-t">Принимать защищённые запросы</div><div class="set-d">Главный выключатель. Пока выключен, адрес <code>/c1/…</code> для прослойки — обычный мусорный путь, и ничего не меняется вообще.</div></div>
                <label class="switch"><input type="checkbox" name="chan_enabled" <?= setting('chan_enabled', '0') === '1' ? 'checked' : '' ?> <?= $chan_ok ? '' : 'disabled' ?>><span class="sl"></span></label>
            </div>
            <div class="set-row">
                <div class="set-info"><div class="set-t">Выравнивать размер ответа</div><div class="set-d">Ответ дополняется до кратного 4096 символов. Без этого по длине ответа виден размер конфига, то есть сколько у человека серверов. Выключать незачем.</div></div>
                <label class="switch"><input type="checkbox" name="chan_pad" <?= setting('chan_pad', '1') === '1' ? 'checked' : '' ?>><span class="sl"></span></label>
            </div>
            <div class="set-row">
                <div class="set-info"><div class="set-t">Жёсткий режим для новых защищённых подписок</div><div class="set-d">Подписка, один раз сходившая по каналу, перестаёт работать по открытому HTTP — вместо конфига приходит заглушка. Включать, когда защищённых станет большинство: клиент сам не откатывается, значит откат означает посредника.</div></div>
                <label class="switch"><input type="checkbox" name="chan_hard_default" <?= setting('chan_hard_default', '0') === '1' ? 'checked' : '' ?>><span class="sl"></span></label>
            </div>
            <div class="set-row">
                <div class="set-info"><div class="set-t">Закрыть HTML-страницу подписки для защищённых</div><div class="set-d">Страница в браузере показывает адрес подписки целиком, то есть отменяет весь смысл канала. Включается отдельно, потому что ссылку иногда открывают руками.</div></div>
                <label class="switch"><input type="checkbox" name="chan_page_404" <?= setting('chan_page_404', '0') === '1' ? 'checked' : '' ?>><span class="sl"></span></label>
            </div>
            <label style="margin-top:1.25rem">Что увидит клиент, откатившийся на открытый HTTP</label>
            <textarea name="chan_hard_remarks" rows="4"><?= h($chan_marks) ?></textarea>
            <p class="muted" style="margin-top:.4rem">Каждая строка — отдельный «сервер»-заглушка в списке клиента. Рабочих хостов в этом теле нет ни одного.</p>
            <div style="margin-top:1.25rem"><button type="submit">💾 Сохранить</button></div>
        </form>
    </div>

    <div class="card">
        <h2 style="margin-top:0;font-size:1rem">Ключ прослойки</h2>
        <p class="muted">Клиентам ключ не раздаётся: он приезжает внутри первого зашифрованного ответа, и они его запоминают. Отпечаток виден в клиенте рядом с галочкой — по нему сверяют, что отвечала та самая прослойка. При смене ключа предыдущий остаётся рабочим, пока клиенты не подхватят новый.</p>
        <p>Текущий отпечаток: <code style="font-size:1.05rem"><?= $chan_fp !== '' ? h($chan_fp) : '—' ?></code></p>
        <form method="post" onsubmit="return confirm('Сменить ключ прослойки?')">
            <input type="hidden" name="csrf" value="<?= h($token) ?>">
            <input type="hidden" name="action" value="clod_rotate">
            <button type="submit" class="ghost">🔑 Сменить ключ</button>
        </form>
    </div>

    <div class="card">
        <div class="loghead"><h2>Кто ходит защищённо (<?= (int) $chan_st['subs'] ?>)</h2></div>
        <p class="muted">
            За сутки: <b><?= (int) $chan_st['day'] ?></b> ·
            запросов всего: <b><?= (int) $chan_st['hits'] ?></b> ·
            откатов: <b><?= (int) $chan_st['downgrades'] ?></b> ·
            в жёстком режиме: <b><?= (int) $chan_st['hard'] ?></b>
        </p>
        <?php if ((int) $chan_st['downgrades'] > 0): ?>
        <div class="warn">Есть откаты на открытый HTTP. Сам клиент так не делает, поэтому каждый случай — либо старая версия приложения, либо посредник, который режет защищённый путь.</div>
        <?php endif; ?>
        <table class="logtbl">
            <thead><tr><th>Подписка</th><th>Первый раз</th><th>Последний</th><th>Запросов</th><th>Откатов</th><th>Клиент</th><th>Жёстко</th></tr></thead>
            <tbody>
            <?php foreach ($chan_rows as $r): ?>
                <tr>
                    <td><code style="font-size:.78rem"><?= h((string) $r['short_uuid']) ?></code></td>
                    <td class="ct-time muted" data-ts="<?= (int) $r['first_seen'] ?>"><?= h(date('Y-m-d H:i', (int) $r['first_seen'])) ?></td>
                    <td class="ct-time muted" data-ts="<?= (int) $r['last_seen'] ?>"><?= h(date('Y-m-d H:i', (int) $r['last_seen'])) ?></td>
                    <td><?= (int) $r['hits'] ?></td>
                    <td><?= (int) $r['downgrades'] > 0 ? '<b>' . (int) $r['downgrades'] . '</b>' : '0' ?></td>
                    <td class="muted"><?= h(mb_substr((string) ($r['ua'] ?? ''), 0, 40)) ?></td>
                    <td>
                        <form method="post" style="display:inline">
                            <input type="hidden" name="csrf" value="<?= h($token) ?>">
                            <input type="hidden" name="action" value="clod_hard">
                            <input type="hidden" name="short" value="<?= h((string) $r['short_uuid']) ?>">
                            <input type="hidden" name="on" value="<?= (int) $r['hard'] === 1 ? '0' : '1' ?>">
                            <button type="submit" class="ghost"><?= (int) $r['hard'] === 1 ? 'вкл' : 'выкл' ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$chan_rows): ?><tr><td colspan="7" class="muted">Пусто. Подписка попадает сюда после первого успешного защищённого запроса.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>

    <section class="<?= coll_cls('chan_debug', true) ?>" data-coll="chan_debug">
        <button type="button" class="coll-head" onclick="collToggle(this)"><span>🔬 Диагностика<?= $chan_dbg ? ' · ' . count($chan_dbg) : '' ?></span>
            <span class="coll-hr"><svg width="30" height="30" class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg></span>
        </button>
        <div class="coll-body">
            <form method="post" data-autosave style="display:flex;gap:1rem;align-items:center;flex-wrap:wrap">
                <input type="hidden" name="csrf" value="<?= h($token) ?>">
                <input type="hidden" name="action" value="save_clod_debug">
                <label class="chk" style="margin:0"><input type="checkbox" name="chan_debug" <?= chan_debug_on() ? 'checked' : '' ?>> <span>Писать журнал</span></label>
                <label style="margin:0;display:flex;gap:.4rem;align-items:center">хранить <input type="number" name="chan_debug_keep" min="5" max="500" value="<?= (int) chan_debug_keep() ?>" style="width:5.5rem"> записей</label>
                <button type="submit">💾</button>
            </form>
            <p class="muted" style="margin:.6rem 0 0;font-size:.8rem">В записи попадает <b>расшифрованное тело подписки</b> и карточка устройства — то самое, что канал прячет от посредника. Включайте на время разбора и выключайте после. Пишутся и удачные запросы, и отказы, включая чужие: по ним видно, что вообще стучится по <code>/c1/…</code>.</p>
            <?php if (chan_debug_on()): ?>
                <form method="post" style="margin:.6rem 0 0">
                    <input type="hidden" name="csrf" value="<?= h($token) ?>">
                    <input type="hidden" name="action" value="clod_debug_clear">
                    <button type="submit" class="ghost">🗑 Очистить</button>
                </form>
            <?php endif; ?>
            <?php if (!$chan_dbg): ?>
                <p class="muted" style="margin:.8rem 0 0;font-size:.8rem"><?= chan_debug_on() ? 'Пока пусто — журнал ждёт первого запроса по каналу.' : 'Журнал выключен.' ?></p>
            <?php else: ?>
            <div class="cdbg" style="margin-top:.7rem">
                <?php foreach ($chan_dbg as $d): $ok = (int) $d['ok'] === 1; ?>
                <details>
                    <summary>
                        <span class="n ct-time muted" data-ts="<?= (int) $d['ts'] ?>"><?= h(date('H:i:s', (int) $d['ts'])) ?></span>
                        <span class="n"><?= $ok ? '✅' : '⛔' ?></span>
                        <span class="g"><?php if ($ok): ?><code><?= h((string) $d['short_uuid']) ?></code> · ответ <?= (int) $d['res_st'] ?> · тело <?= (int) $d['body_bytes'] ?> б → шифр <?= (int) $d['wire_bytes'] ?> симв<?php else: ?><span class="muted"><?= h(chan_debug_why($d['why'])) ?></span><?php endif; ?></span>
                    </summary>
                    <div class="lbl">1. Запрос снаружи — что видит посредник</div>
                    <pre><?= h((string) $d['req_path']) ?></pre>
                    <div class="lbl">2. Заголовки запроса снаружи</div>
                    <pre><?= h((string) $d['req_head']) ?></pre>
                    <?php if ((string) $d['req_json'] !== ''): ?>
                    <div class="lbl">3. Запрос расшифрованный</div>
                    <pre><?= h((string) $d['req_json']) ?></pre>
                    <?php endif; ?>
                    <?php if ($ok): ?>
                    <div class="lbl">4. Каким запрос ушёл в конвейер и в панель</div>
                    <pre><?= h((string) $d['req_fwd']) ?></pre>
                    <div class="lbl">5. Ответ до шифрования — заголовки панели</div>
                    <pre><?= h((string) $d['res_meta']) ?></pre>
                    <div class="lbl">6. Ответ до шифрования — тело</div>
                    <pre><?= h((string) $d['res_body']) ?></pre>
                    <div class="lbl">7. Ответ снаружи — заголовки и код, как их увидит клиент</div>
                    <pre><?= h((string) $d['res_outer']) ?></pre>
                    <div class="lbl">8. Ответ снаружи — шифротекст, байт в байт как ушёл клиенту</div>
                    <pre><?= h((string) $d['res_wire']) ?></pre>
                    <?php endif; ?>
                </details>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </section>

    <section class="<?= coll_cls('chan_how', true) ?>" data-coll="chan_how">
        <button type="button" class="coll-head" onclick="collToggle(this)"><span>Как это устроено</span>
            <span class="coll-hr"><svg width="30" height="30" class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg></span>
        </button>
        <div class="coll-body">
        <p class="muted">Общий секрет — <b>сам адрес подписки</b>. В сеть он не уходит ни разу: из него выводится ключ <code>psk = HKDF-SHA256(адрес, соль «clod-chan-v1», метка «psk»)</code>, 32 байта. Криптография одна и не согласуется: X25519, HKDF-SHA256, ChaCha20-Poly1305.</p>

        <div class="set-t" style="margin-top:1rem">Запрос</div>
        <p class="muted">Клиент запрашивает <code>&lt;префикс&gt;/c1/&lt;метка&gt;/&lt;отпечаток&gt;/&lt;блок&gt;</code> и не шлёт ни одного своего заголовка.</p>
        <ul class="muted" style="line-height:1.7;padding-left:1.2rem;margin-top:.3rem">
            <li><b>Метка</b> — первые 9 байт <code>HMAC-SHA256(psk, "kid|&lt;номер суток&gt;")</code> в base64url, 12 символов. Номер суток — это unix-время, делённое на 86400, поэтому метка меняется каждые сутки: два запроса одного человека в разные дни посредник между собой не свяжет. Прослойка держит метки на трое суток — вчера, сегодня и завтра — и по ним узнаёт подписку.</li>
            <li><b>Отпечаток</b> — 6 символов base64url от SHA-256 публичного ключа прослойки, либо <code>0</code>, если клиент этот ключ ещё не закрепил.</li>
            <li><b>Блок</b> — эфемерный публичный ключ клиента (32 байта), следом ChaCha20-Poly1305. Ключ шифрования — <code>HKDF(psk + X25519(эфемерный клиента, ключ прослойки), соль — метка, info — «req» и эфемерный ключ)</code>, в связанных данных «c1», метка и тот же ключ.</li>
            <li>Внутри блока едет то, что в открытом режиме ушло бы заголовками: <code>x-hwid</code>, <code>x-device-os</code>, <code>x-ver-os</code>, <code>x-device-model</code>, User-Agent, Accept и строка запроса, — плюс время и метка запроса из 16 случайных байт. Клиент дополняет всё это до кратного 512 байт: без выравнивания по длине адреса читается длина карточки устройства, то есть модель телефона и момент смены прошивки.</li>
        </ul>

        <div class="set-t" style="margin-top:1rem">Ответ</div>
        <p class="muted">Тело целиком в base64url. Снаружи остаются только <code>content-type: application/octet-stream</code> и <code>cache-control: no-store</code>, а код ответа всегда 200 — настоящий едет внутри шифра.</p>
        <ul class="muted" style="line-height:1.7;padding-left:1.2rem;margin-top:.3rem">
            <li>Эфемерная пара прослойки делается заново на каждый ответ. Ключ — <code>HKDF(psk + X25519(эфемерная прослойки, эфемерный клиента) + тот же DH, соль — метка, info — «res» и эфемерный ключ клиента)</code>, связанные данные — «c1r», метка и оба эфемерных ключа. Поэтому адрес подписки, утёкший позже, не расшифрует записанный разговор.</li>
            <li>Внутри: код ответа, эхо метки запроса, публичный ключ прослойки, все заголовки панели и тело. <code>etag</code> и <code>last-modified</code> снимаются — они описывают тело, которого клиент уже не увидит; <code>date</code> подставляется, по нему клиент считает сдвиг часов устройства.</li>
            <li>Сырой ответ дополняется до кратного 3072 байт — в base64url это ровно 4096 символов.</li>
        </ul>

        <div class="set-t" style="margin-top:1rem">Что дальше внутри прослойки</div>
        <p class="muted">Расшифрованный запрос подменяет собой обычный: путь, строка запроса и заголовки опознания встают на свои места, поэтому оверрайды, лимит устройств, доп. конфиги, слияние подписок и правила ответа работают ровно как в открытом режиме. Шифрование — самый последний шаг, уже после всех подмешиваний.</p>
        <p class="muted">На входе проверяются две вещи: время запроса в пределах ±300 секунд и метка запроса, которая принимается один раз и помнится 10 минут. Любая неудача — не расшифровалось, метка чужая, запрос повторён — выглядит снаружи одинаково: ровно тем же ответом, что и обращение по любому несуществующему адресу.</p>
        <p class="muted">Подписей в протоколе нет и не нужно: <code>psk</code> выведен из адреса подписки, который знают только клиент и прослойка, поэтому расшифровавшийся ответ сам по себе доказывает, что отвечала именно она. Смена ключа прослойки едет тем же путём — внутри очередного ответа.</p>
        <p class="muted">Чего канал не прячет: сам факт, что запрос защищённый — это видно по форме адреса; размер конфигурации с точностью до 4096 символов; и то, общался ли этот клиент раньше — по отпечатку вместо нуля.</p>
        </div>
    </section>

    <section class="<?= coll_cls('chan_start', true) ?>" data-coll="chan_start">
        <button type="button" class="coll-head" onclick="collToggle(this)"><span>Как включать</span>
            <span class="coll-hr"><svg width="30" height="30" class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg></span>
        </button>
        <div class="coll-body">
        <ol class="muted" style="line-height:1.7;padding-left:1.2rem">
            <li>Прослойка обновлена, тумблер выключен — не изменилось ничего, появилась эта вкладка.</li>
            <li>Вышли клиенты с галочкой «Защищённое соединение».</li>
            <li>Включаете тумблер. По-прежнему не изменилось ничего: галочку никто не поставил.</li>
            <li>Переводите себя: <b>отзываете свою ссылку в панели</b> и добавляете заново с галочкой. Отзыв обязателен — старый адрес посредник уже видел, и включение защиты задним числом этого не отменяет.</li>
            <li>Дальше по одному. Когда защищённых станет большинство — включаете «Закрыть HTML-страницу» и «Жёсткий режим».</li>
        </ol>
        <p class="muted">Откат: выключить тумблер. Релиз клиента для этого не нужен.</p>
        </div>
    </section>
    <script>
    // Звёзды: разметка уже отрисована из серверного кэша (трое суток), здесь
    // только освежаем — и только когда серверу пора идти в GitHub. Если у сервера
    // с api.github.com не вышло (там лимит на адрес, и датацентрам он достаётся
    // чаще), доспрашиваем из браузера, как счётчик в шапке, со своим кэшем на те
    // же трое суток. Всё молча: не получилось — остаётся то, что было.
    (function(){
        var w=document.getElementById('clodApps');
        if(!w)return;
        var TTL=3*86400*1000;
        function paint(el,n){if(typeof n!=='number')return;el.querySelector('.n').textContent=n;el.hidden=false;}
        function fallback(){
            w.querySelectorAll('.st[data-repo]').forEach(function(el){
                if(!el.hidden)return;
                var r=el.getAttribute('data-repo'),k='gh_stars:'+r;
                try{var c=JSON.parse(localStorage.getItem(k)||'null');if(c&&Date.now()-c.t<TTL){paint(el,c.n);return;}}catch(e){}
                fetch('https://api.github.com/repos/'+r).then(function(x){return x.json();}).then(function(d){
                    if(!d||typeof d.stargazers_count!=='number')return;
                    paint(el,d.stargazers_count);
                    try{localStorage.setItem(k,JSON.stringify({n:d.stargazers_count,t:Date.now()}));}catch(e){}
                }).catch(function(){});
            });
        }
        if(w.getAttribute('data-upd')!=='1'){fallback();return;}
        fetch('?ajax=clod_stars').then(function(r){return r.json();}).then(function(d){
            if(!d||!d.ok||!d.stars)return;
            w.querySelectorAll('.st[data-repo]').forEach(function(el){
                var e=d.stars[el.getAttribute('data-repo')];
                if(e)paint(el,e.n);
            });
        }).catch(function(){}).then(fallback);
    })();
    (function(){function p(n){return(n<10?'0':'')+n;}document.querySelectorAll('.ct-time[data-ts]').forEach(function(el){var ep=parseInt(el.getAttribute('data-ts'),10);if(!ep)return;var d=new Date(ep*1000);if(isNaN(d.getTime()))return;el.textContent=d.getFullYear()+'-'+p(d.getMonth()+1)+'-'+p(d.getDate())+' '+p(d.getHours())+':'+p(d.getMinutes());});})();
    </script>
