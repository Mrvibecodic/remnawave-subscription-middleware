<?php
$app_ok    = app_ext_ok();
$app_on    = app_enabled();
$app_fp    = $app_ok && $app_on ? app_fingerprint() : '';
$app_devs  = app_devs();
$app_codes = app_codes();
$app_apk   = app_apk_meta();
$app_has   = app_apk_path($app_apk) !== '';
$app_upd   = app_apk_stale($app_apk);
$app_only  = setting('app_only', '0') === '1';
$app_off   = app_only_off();
$app_rel   = 'https://github.com/' . APP_REPO . '/releases/latest';
$app_ico   = function ($d, $s = 16) { return '<svg width="' . $s . '" height="' . $s . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $d . '</svg>'; };
$app_size  = function ($b) { return $b >= 1048576 ? number_format($b / 1048576, 1, ',', ' ') . ' МБ' : max(1, (int) round($b / 1024)) . ' КБ'; };
?>
    <style>
    .apg{display:grid;grid-template-columns:1fr 1fr;gap:.55rem;margin-top:.7rem}
    .apg .set-row{margin:0}
    @media(max-width:1000px){.apg{grid-template-columns:1fr}}
    .ap-ready{display:flex;flex-wrap:wrap;gap:.45rem 1.2rem;margin:.2rem 0 0;padding:0;list-style:none;font-size:.86rem}
    .ap-ready li{display:inline-flex;align-items:center;gap:.4rem}
    .ap-ready .y{color:var(--c-ok-fg)}
    .ap-ready .n{color:var(--c-bad-fg)}
    .ap-apk{display:flex;gap:1rem;align-items:center;flex-wrap:wrap;margin-top:1rem;padding:.8rem .9rem;border:1px solid var(--line);border-radius:12px;background:var(--bg2)}
    .ap-apk .tx{flex:1 1 14rem;min-width:0;display:flex;flex-direction:column;gap:.15rem}
    .ap-apk .nm{font-weight:600;color:var(--text-strong)}
    .ap-apk .ds{font-size:.8rem;color:var(--muted);overflow-wrap:anywhere}
    .ap-apk .act{display:flex;gap:.5rem;flex-wrap:wrap}
    .ap-apk .act a,.ap-apk .act button{display:inline-flex;align-items:center;gap:.4rem}
    .ap-qr{flex:0 0 auto;width:96px;height:96px;border-radius:8px;background:#fff;padding:4px}
    .ap-qr img{width:100%;height:100%;image-rendering:pixelated;display:block}
    .ap-add{display:inline-flex;align-items:center;gap:.45rem}
    .ap-codes{display:flex;flex-wrap:wrap;gap:.5rem;margin:.8rem 0 0}
    .ap-codes .cd{display:inline-flex;align-items:center;gap:.5rem;padding:.3rem .35rem .3rem .65rem;border:1px dashed var(--line);border-radius:999px;font-size:.8rem;color:var(--muted)}
    .ap-codes .cd code{background:none;border:0;padding:0;font-size:.82rem;color:var(--text-strong)}
    .ap-x,.ap-x:hover,.ap-x:active{padding:0;width:28px;height:28px;min-height:0;display:inline-flex;align-items:center;justify-content:center;background:none;border:1px solid var(--line);border-radius:8px;color:var(--muted);filter:none;transform:none}
    .ap-x:hover{color:var(--c-bad-fg);border-color:var(--c-bad-fg)}
    .ap-dev{display:flex;flex-direction:column;line-height:1.3;min-width:0}
    .ap-dev .nm{color:var(--text-strong);font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    .ap-dev .su{font-size:.76rem;color:var(--muted)}
    .ap-code{font:700 2rem/1.2 ui-monospace,SFMono-Regular,Menlo,monospace;letter-spacing:.08em;color:var(--text-strong);text-align:center;margin:.4rem 0 .2rem;user-select:all;overflow-wrap:anywhere}
    .ap-tm{text-align:center;color:var(--muted);font-size:.84rem;font-variant-numeric:tabular-nums}
    .ap-steps{display:flex;flex-direction:column;gap:.55rem;margin:1rem 0 .2rem;padding:0;list-style:none;font-size:.88rem}
    .ap-steps li{display:flex;gap:.6rem;align-items:flex-start}
    .ap-steps .i{flex:0 0 auto;width:28px;height:28px;border-radius:8px;background:var(--accent-light);color:var(--accent-text);display:flex;align-items:center;justify-content:center}
    .ap-wait{display:flex;align-items:center;justify-content:center;gap:.5rem;margin-top:1rem;color:var(--muted);font-size:.84rem}
    .ap-wait .dot{width:8px;height:8px;border-radius:50%;background:var(--accent);animation:apb 1.2s ease-in-out infinite}
    @keyframes apb{0%,100%{opacity:.25}50%{opacity:1}}
    </style>

    <div class="card">
        <h2 style="margin-top:0;font-size:1rem">Админка в телефоне</h2>
        <p class="muted">Приложение для Android открывает эту админку и присылает уведомления: сообщения в чате, вебхуки панели, отчёты Clod Clash. С прослойкой оно общается по своему защищённому каналу: снаружи видны только запросы с шумом вместо содержимого.</p>
        <ul class="ap-ready">
            <li><span class="<?= $app_ok ? 'y' : 'n' ?>"><?= $app_ico($app_ok ? '<path d="M20 6 9 17l-5-5"/>' : '<path d="M18 6 6 18M6 6l12 12"/>') ?></span>Расширение sodium<?= $app_ok ? '' : ' — нет, приложение не включить' ?></li>
            <li><span class="<?= $app_fp !== '' ? 'y' : 'n' ?>"><?= $app_ico($app_fp !== '' ? '<path d="M20 6 9 17l-5-5"/>' : '<circle cx="12" cy="12" r="9"/>') ?></span><?= $app_fp !== '' ? 'Ключ прослойки <code>' . h($app_fp) . '</code>' : 'Ключ появится после включения' ?></li>
            <li><span class="<?= $app_devs ? 'y' : 'n' ?>"><?= $app_ico($app_devs ? '<path d="M20 6 9 17l-5-5"/>' : '<circle cx="12" cy="12" r="9"/>') ?></span>Телефонов: <?= count($app_devs) ?></li>
        </ul>
        <form method="post" data-autosave style="margin-top:1rem">
            <input type="hidden" name="csrf" value="<?= h($token) ?>">
            <input type="hidden" name="action" value="save_app">
            <div class="apg">
                <div class="set-row">
                    <div class="set-info"><div class="set-t">Приложение</div><div class="set-d">Главный выключатель. Пока выключен, запросы приложения для прослойки — обычный мусорный адрес, а телефоны не входят и не получают уведомлений.</div></div>
                    <label class="switch"><input type="checkbox" name="app_enabled" data-reload <?= $app_on ? 'checked' : '' ?> <?= $app_ok ? '' : 'disabled' ?>><span class="sl"></span></label>
                </div>
                <div class="set-row">
                    <div class="set-info"><div class="set-t">Сколько держать соединение уведомлений, с</div><div class="set-d">Верхняя граница. Приложение само подбирает срок под сеть и не держит дольше, чем пропускают сервер и прокси.</div></div>
                    <input type="number" name="app_ev_wait_max" min="25" max="290" value="<?= (int) app_ev_wait_max() ?>">
                </div>
                <div class="set-row">
                    <div class="set-info"><div class="set-t">Сколько телефонов ждут уведомлений одновременно</div><div class="set-d">Каждое открытое соединение занимает процесс PHP, а их пул общий с подписками. Остальные телефоны проверяют события примерно раз в минуту, не занимая процесс. Поднимайте, только если процессов PHP с запасом.</div></div>
                    <input type="number" name="app_ev_slots" min="1" max="32" value="<?= (int) app_ev_slots() ?>">
                </div>
            </div>
        </form>
        <div class="ap-apk" id="apApk" data-upd="<?= $app_upd ? '1' : '0' ?>">
            <div class="ap-qr" data-tip="Наведите камеру телефона — откроется страница релиза"><img id="apQr" alt="QR-код страницы релиза"></div>
            <div class="tx">
                <span class="nm">Сервис для Android<?= $app_has ? ' · ' . h((string) $app_apk['v']) : '' ?></span>
                <span class="ds" id="apApkDs"><?= $app_has ? h($app_size((int) $app_apk['size'])) . ' · SHA-256 ' . h(substr((string) $app_apk['sha256'], 0, 16)) . '…' : (trim((string) ($app_apk['err'] ?? '')) !== '' ? 'Не скачан: ' . h((string) $app_apk['err']) : 'Прослойка скачает APK из релизов сама') ?></span>
                <span class="ds">Ставится вручную из APK. Обновления приложение берёт у прослойки, мимо магазинов.</span>
            </div>
            <div class="act">
                <?php if ($app_has): ?><a class="btn" href="?app_apk_dl=1"><?= $app_ico('<path d="M12 3v12"/><path d="m7 10 5 5 5-5"/><path d="M5 21h14"/>') ?>Скачать APK</a><?php endif; ?>
                <a class="btn ghost" href="<?= h($app_rel) ?>" target="_blank" rel="noopener"><?= $app_ico('<path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>') ?>Релизы</a>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="loghead"><h2>Телефоны (<?= count($app_devs) ?>)</h2>
            <div class="loghead-r"><button type="button" class="btn ap-add" id="apAdd" <?= $app_on ? '' : 'disabled data-tip="Сначала включите приложение"' ?>><?= $app_ico('<path d="M12 5v14M5 12h14"/>') ?>Добавить телефон</button></div>
        </div>
        <p class="muted">Телефон добавляется одноразовым кодом: он живёт 5 минут, пароль админки при этом не передаётся. Вход в админку в приложении — тем же логином и паролем, что и здесь.</p>
        <?php if ($app_codes): ?>
        <div class="ap-codes">
            <?php foreach ($app_codes as $c): ?>
            <span class="cd">Код <code><?= h(substr((string) $c['code'], 0, 4)) ?>-…</code> ещё <span class="ap-left" data-exp="<?= (int) $c['expires'] ?>"><?= max(0, (int) ceil(((int) $c['expires'] - time()) / 60)) ?> мин</span>
                <button type="button" class="ap-x" data-code="<?= h((string) $c['code']) ?>" aria-label="Отменить код" data-tip="Отменить код"><?= $app_ico('<path d="M18 6 6 18M6 6l12 12"/>', 14) ?></button></span>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <table class="logtbl" style="margin-top:.8rem">
            <thead><tr><th>Телефон</th><th>Приложение</th><th>Добавлен</th><th>Был на связи</th><th>Адрес</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($app_devs as $d): ?>
            <tr>
                <td><span class="ap-dev"><span class="nm"><?= h((string) ($d['name'] ?: 'Телефон')) ?></span><span class="su"><?= h(trim(((string) $d['os'] === 'android' ? 'Android' : (string) $d['os']) . ' ' . (string) $d['osv'])) ?></span></span></td>
                <td><?= (string) $d['app'] !== '' ? h((string) $d['app']) : '—' ?></td>
                <td class="ap-time muted" data-ts="<?= (int) $d['created'] ?>"><?= h(date('Y-m-d H:i', (int) $d['created'])) ?></td>
                <td class="ap-time muted" data-ts="<?= (int) $d['last_seen'] ?>"><?= h(date('Y-m-d H:i', (int) $d['last_seen'])) ?></td>
                <td class="muted"><?= h((string) ($d['last_ip'] ?? '')) ?></td>
                <td style="text-align:right">
                    <form method="post" style="display:inline" onsubmit="return uiConfirmForm(this,'Отключить «<?= h(addslashes((string) ($d['name'] ?: 'Телефон'))) ?>»? Он сразу потеряет доступ и уведомления.','Отключить')">
                        <input type="hidden" name="csrf" value="<?= h($token) ?>">
                        <input type="hidden" name="action" value="app_dev_drop">
                        <input type="hidden" name="did" value="<?= h((string) $d['did']) ?>">
                        <button type="submit" class="ap-x" aria-label="Отключить телефон" data-tip="Отключить телефон"><?= $app_ico('<path d="M18 6 6 18M6 6l12 12"/>', 14) ?></button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$app_devs): ?><tr><td colspan="6" class="muted">Пока ни одного. Нажмите «Добавить телефон».</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="card">
        <h2 style="margin-top:0;font-size:1rem">Админка только из приложения</h2>
        <form method="post" data-autosave>
            <input type="hidden" name="csrf" value="<?= h($token) ?>">
            <input type="hidden" name="action" value="save_app_only">
            <div class="set-row" style="margin-top:.6rem">
                <div class="set-info"><div class="set-t">Закрыть вход из браузера</div><div class="set-d">Адрес <code>/admin/</code> в браузере покажет страницу-приманку, админка откроется только в приложении. Включается, когда есть хотя бы один телефон.</div></div>
                <label class="switch"><input type="checkbox" name="app_only" <?= $app_only && $app_on ? 'checked' : '' ?> <?= $app_on && $app_devs ? '' : 'disabled' ?>><span class="sl"></span></label>
            </div>
        </form>
        <?php if ($app_off): ?>
        <div class="warn" style="margin-top:.8rem">Сейчас режим снят настройкой сервера, браузер пускает в админку.</div>
        <?php endif; ?>
        <p class="muted" style="margin-top:.8rem">Если телефон потерян: допишите <code>'app_only_off' =&gt; true,</code> в <code>config.php</code> или задайте <code>SUBMW_APP_ONLY_OFF=1</code> в Docker — браузер снова пустит в админку. Отключённый телефон теряет доступ сразу.</p>
    </div>

    <div class="card">
        <h2 style="margin-top:0;font-size:1rem">Ключ прослойки</h2>
        <p class="muted">Отдельный от канала Clod Clash. Телефоны получают новый ключ сами, предыдущий работает, пока они его не подхватят.</p>
        <p>Отпечаток: <code style="font-size:1.05rem"><?= $app_fp !== '' ? h($app_fp) : '—' ?></code></p>
        <form method="post" onsubmit="return uiConfirmForm(this,'Сменить ключ прослойки для приложения?','Сменить',false)">
            <input type="hidden" name="csrf" value="<?= h($token) ?>">
            <input type="hidden" name="action" value="app_rotate">
            <button type="submit" class="btn ghost" <?= $app_on ? '' : 'disabled' ?>>Сменить ключ</button>
        </form>
    </div>

    <section class="<?= coll_cls('app_how', true) ?>" data-coll="app_how">
        <button type="button" class="coll-head" onclick="collToggle(this)"><span>Уведомления и таймауты</span>
            <span class="coll-hr"><svg width="30" height="30" class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg></span>
        </button>
        <div class="coll-body">
        <p class="muted">Уведомления отправляет сама прослойка, без сторонних сервисов. Телефон держит одно открытое соединение и получает ответ сразу, как случилось событие. В уведомлении только тип события — без имён и текста сообщений.</p>
        <p class="muted">Пока соединение открыто, оно занимает один процесс PHP. Если сервер или прокси рвут долгие запросы раньше, приложение заметит это и укоротит срок. Где поднять предел:</p>
        <ul class="muted" style="line-height:1.7;padding-left:1.2rem">
            <li>Apache + mod_fcgid — <code>FcgidIOTimeout 300</code>;</li>
            <li>nginx — <code>fastcgi_read_timeout 300s</code>, за прокси ещё <code>proxy_read_timeout 300s</code>;</li>
            <li>Cloudflare обрывает ответ через 100 секунд — приложение уложится сам.</li>
        </ul>
        <p class="muted">В Docker-образе прослойки предел уже поднят.</p>
        </div>
    </section>

    <div id="apDlg" class="modal-overlay">
        <div class="modal" style="max-width:420px">
            <div class="modal-head"><span>Добавить телефон</span><button type="button" class="modal-x" id="apDlgX" aria-label="Закрыть">×</button></div>
            <div class="modal-body">
                <div class="ap-code" id="apCode">····-····-····</div>
                <div class="ap-tm" id="apTm"></div>
                <ul class="ap-steps">
                    <li><span class="i"><?= $app_ico('<rect x="5" y="2" width="14" height="20" rx="2"/><path d="M12 18h.01"/>') ?></span><span>Откройте приложение на телефоне.</span></li>
                    <li><span class="i"><?= $app_ico('<path d="M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.7 1.7"/><path d="M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.7-1.7"/>') ?></span><span>Введите адрес прослойки <code><?= h(mirror_domain() !== '' ? mirror_domain() : ($_SERVER['HTTP_HOST'] ?? '')) ?></code> и этот код.</span></li>
                    <li><span class="i"><?= $app_ico('<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>') ?></span><span>Дальше — логин и пароль админки.</span></li>
                </ul>
                <div class="ap-wait" id="apWait"><span class="dot"></span><span>Ждём телефон…</span></div>
            </div>
        </div>
    </div>

    <script src="assets/qrcode.js?v=<?= substr((string) @md5_file(__DIR__ . '/../assets/qrcode.js'), 0, 10) ?>"></script>
    <script>
    (function(){
        var tok=<?= json_encode($token) ?>, rel=<?= json_encode($app_rel) ?>;
        function p(n){return(n<10?'0':'')+n;}
        document.querySelectorAll('.ap-time[data-ts]').forEach(function(el){var ep=parseInt(el.getAttribute('data-ts'),10);if(!ep)return;var d=new Date(ep*1000);el.textContent=d.getFullYear()+'-'+p(d.getMonth()+1)+'-'+p(d.getDate())+' '+p(d.getHours())+':'+p(d.getMinutes());});
        var qi=document.getElementById('apQr');
        if(qi&&window.qrcode){try{var q=qrcode(0,'M');q.addData(rel);q.make();qi.src=q.createDataURL(4,0);}catch(e){}}
        var box=document.getElementById('apApk');
        if(box&&box.getAttribute('data-upd')==='1'){fetch('?ajax=app_apk').then(function(r){return r.json();}).then(function(d){if(d&&d.ok&&d.apk&&<?= $app_has ? 'false' : 'true' ?>)location.reload();}).catch(function(){});}
        document.querySelectorAll('.ap-codes .ap-x[data-code]').forEach(function(b){b.addEventListener('click',function(){
            var fd=new FormData();fd.append('csrf',tok);fd.append('code',b.getAttribute('data-code'));
            fetch('?ajax=app_code_del',{method:'POST',body:fd,credentials:'same-origin'}).then(function(){location.reload();});
        });});
        var add=document.getElementById('apAdd'),dlg=document.getElementById('apDlg'),code=document.getElementById('apCode'),tm=document.getElementById('apTm'),timer=null,poll=null,base=<?= count($app_devs) ?>;
        function stop(){clearInterval(timer);clearInterval(poll);timer=poll=null;}
        function close(){stop();dlg.classList.remove('open');}
        document.getElementById('apDlgX').addEventListener('click',close);
        dlg.addEventListener('click',function(e){if(e.target===dlg)close();});
        document.addEventListener('keydown',function(e){if(e.key==='Escape'&&dlg.classList.contains('open'))close();});
        if(add)add.addEventListener('click',function(){
            add.disabled=true;
            var fd=new FormData();fd.append('csrf',tok);
            fetch('?ajax=app_code',{method:'POST',body:fd,credentials:'same-origin'}).then(function(r){return r.json();}).then(function(d){
                add.disabled=false;
                if(!d||!d.ok){uiToast((d&&d.error)||'Код не создан');return;}
                code.textContent=d.code;dlg.classList.add('open');
                var end=Date.now()+d.ttl*1000;
                var tick=function(){var s=Math.max(0,Math.round((end-Date.now())/1000));tm.textContent=s?('Код действует ещё '+Math.floor(s/60)+':'+p(s%60)):'Код истёк — закройте окно и создайте новый';if(!s)stop();};
                tick();timer=setInterval(tick,1000);
                poll=setInterval(function(){fetch('?ajax=app_devs',{credentials:'same-origin'}).then(function(r){return r.json();}).then(function(x){if(x&&x.ok&&x.n>base){stop();uiToast('Телефон добавлен');setTimeout(function(){location.reload();},700);}}).catch(function(){});},3000);
            }).catch(function(){add.disabled=false;uiToast('Ошибка сети');});
        });
    })();
    </script>
