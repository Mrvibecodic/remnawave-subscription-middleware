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
?>
    <div class="card">
        <h2 style="margin-top:0;font-size:1rem">Работает только с Clod Clash</h2>
        <p class="muted">Это протокол двух конкретных приложений и этой прослойки, а не стандарт подписок: никакой другой клиент про него не знает и по нему работать не будет. Обычные подписки идут прежним путём, канал их не касается.</p>
        <table class="logtbl">
            <tbody>
            <tr><td style="width:9rem">Clod Clash · ПК</td>
                <td><a href="https://github.com/Mrvibecodic/clod-clash" target="_blank" rel="noopener" style="color:var(--accent-text)">github.com/Mrvibecodic/clod-clash</a></td></tr>
            <tr><td>Clod Clash · Android</td>
                <td><a href="https://github.com/Mrvibecodic/clod-clash-android" target="_blank" rel="noopener" style="color:var(--accent-text)">github.com/Mrvibecodic/clod-clash-android</a></td></tr>
            </tbody>
        </table>
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
            <tr><td><?= $chan_idx['fresh'] && $chan_idx['count'] > 0 ? '✅' : '⚠️' ?></td>
                <td>Индекс меток — <?= (int) $chan_idx['count'] ?> подписок<?php if ((int) $chan_idx['ts'] > 0): ?>, обновлён <span class="ct-time" data-ts="<?= (int) $chan_idx['ts'] ?>"><?= h(date('Y-m-d H:i', (int) $chan_idx['ts'])) ?></span><?php endif; ?><?= $chan_idx['fresh'] ? '' : ' <b>(не за сегодня)</b>' ?>
                    <?php if ((int) $chan_idx['count'] === 0): ?>
                        <div class="muted" style="margin-top:.35rem">Индекс пока пуст: без него прослойка не узнаёт подписку по метке и отвечает как на мусорный адрес. Нажмите «Пересобрать» — дальше он обновляется сам, раз в сутки и на создание пользователя вебхуком.</div>
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
            <span class="hint">Обычно пересобирается сам: раз в сутки и на создание пользователя вебхуком.</span>
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

    <div class="card">
        <h2 style="margin-top:0;font-size:1rem">Как это устроено</h2>
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

    <div class="card">
        <h2 style="margin-top:0;font-size:1rem">Как включать</h2>
        <ol class="muted" style="line-height:1.7;padding-left:1.2rem">
            <li>Прослойка обновлена, тумблер выключен — не изменилось ничего, появилась эта вкладка.</li>
            <li>Вышли клиенты с галочкой «Защищённое соединение».</li>
            <li>Включаете тумблер. По-прежнему не изменилось ничего: галочку никто не поставил.</li>
            <li>Переводите себя: <b>отзываете свою ссылку в панели</b> и добавляете заново с галочкой. Отзыв обязателен — старый адрес посредник уже видел, и включение защиты задним числом этого не отменяет.</li>
            <li>Дальше по одному. Когда защищённых станет большинство — включаете «Закрыть HTML-страницу» и «Жёсткий режим».</li>
        </ol>
        <p class="muted">Откат: выключить тумблер. Релиз клиента для этого не нужен.</p>
    </div>
    <script>
    (function(){function p(n){return(n<10?'0':'')+n;}document.querySelectorAll('.ct-time[data-ts]').forEach(function(el){var ep=parseInt(el.getAttribute('data-ts'),10);if(!ep)return;var d=new Date(ep*1000);if(isNaN(d.getTime()))return;el.textContent=d.getFullYear()+'-'+p(d.getMonth()+1)+'-'+p(d.getDate())+' '+p(d.getHours())+':'+p(d.getMinutes());});})();
    </script>
