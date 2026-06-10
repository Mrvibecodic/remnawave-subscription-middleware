<?php
$u_state     = update_state();
$u_installed = update_installed_commit();
$u_avail     = update_available();
$u_backup    = trim((string) setting('update_last_backup', ''));
$u_isgit     = update_local_git_commit() !== '';
$u_user      = update_web_user();
$u_writable  = is_writable(update_root());
$u_log       = json_decode((string) setting('update_last_log', '[]'), true);
if (!is_array($u_log)) $u_log = [];
$u_checked   = (int) ($u_state['checked_at'] ?? 0);
$u_err       = (string) ($u_state['last_err'] ?? '');
$u_latest    = (string) ($u_state['latest_sha'] ?? '');
$u_commits   = (array) ($u_state['commits'] ?? []);
$u_files     = (array) ($u_state['files'] ?? []);
$u_ts        = function ($ts) { return $ts ? date('d.m.Y H:i', (int) $ts) : '—'; };
$u_stbadge   = function ($s) {
    $map = ['added' => ['добавлен', 'add'], 'modified' => ['изменён', 'mod'], 'removed' => ['удалён', 'del'], 'renamed' => ['переименован', 'ren']];
    $m = $map[$s] ?? [$s, 'mod'];
    return '<span class="up-st up-' . $m[1] . '">' . h($m[0]) . '</span>';
};
$u_branch    = update_branch();
$u_branches  = array_values(array_unique(array_filter(['main', 'dev', $u_branch])));
?>
<?php if (submw_in_docker()): ?>
    <div class="info">
        Прослойка запущена в <b>Docker</b>. Обновление — через <code>docker pull</code> образа в консоли сервера; записи файлов и git здесь нет.
    </div>
    <div class="card">
        <h2 style="margin-top:0;font-size:1rem">Версия (Docker)</h2>
        <p style="margin:.2rem 0"><span class="muted">Образ:</span> <code class="up-sha"><?= h($u_installed !== '' ? substr($u_installed, 0, 12) : '—') ?></code> · тег <code><?= h($u_branch) ?></code></p>
        <?php if ($u_latest !== ''): ?><p style="margin:.2rem 0"><span class="muted">Последний в ветке:</span> <code class="up-sha"><?= h(substr($u_latest, 0, 12)) ?></code></p><?php endif; ?>
        <p class="muted" style="margin:.2rem 0;font-size:.82rem">Последняя проверка: <span class="up-localtime" data-ts="<?= (int) $u_checked ?>"><?= h($u_ts($u_checked)) ?></span></p>
        <?php if ($u_err !== ''): ?><div class="warn" style="margin-top:.6rem">Последняя ошибка: <?= h($u_err) ?></div><?php endif; ?>
        <form method="post" style="margin-top:.9rem;display:flex;gap:.5rem;flex-wrap:wrap">
            <input type="hidden" name="csrf" value="<?= h($token) ?>">
            <input type="hidden" name="action" value="update_check">
            <button type="submit" class="btn ghost">🔄 Проверить обновления</button>
        </form>
    </div>
    <div class="card">
        <h2 style="margin-top:0;font-size:1rem">Ветка (тег образа)</h2>
        <p style="margin:.2rem 0"><span class="muted">Текущая ветка:</span> <code><?= h($u_branch) ?></code></p>
        <p class="muted" style="margin:.2rem 0;font-size:.82rem"><b>main</b> — стабильная, <b>dev</b> — тестовая. Ветка = тег образа в реестре.</p>
        <form method="post" style="margin-top:.7rem;display:flex;gap:.5rem;flex-wrap:wrap;align-items:center" onsubmit="return (function(f){uiConfirm('Переключить отслеживаемую ветку? Проверки обновлений будут сверяться с её последним коммитом.',function(){f.submit();},'Переключить',false);return false;})(this)">
            <input type="hidden" name="csrf" value="<?= h($token) ?>">
            <input type="hidden" name="action" value="update_switch_branch">
            <select name="branch" style="min-width:150px;padding:.4rem .6rem">
                <?php foreach ($u_branches as $b): ?>
                    <option value="<?= h($b) ?>"<?= $b === $u_branch ? ' selected' : '' ?>><?= h($b) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn">Переключить</button>
        </form>
        <p class="muted" style="margin:.5rem 0 0;font-size:.8rem">Чтобы перейти на выбранную ветку: смените тег образа в <code>docker-compose.yml</code> на <code>:<?= h($u_branch) ?></code>, затем <code>docker compose pull &amp;&amp; docker compose up -d</code>.</p>
    </div>
    <?php if ($u_installed !== '' && $u_avail): ?>
    <div class="card" style="border-color:var(--amber)">
        <h2 style="margin-top:0;font-size:1rem">Доступно обновление · коммитов: <?= (int) ($u_state['ahead_by'] ?? count($u_commits)) ?></h2>
        <?php if ($u_commits): ?><div class="up-block"><div class="up-hl">Новые коммиты</div><?php foreach ($u_commits as $c): ?><div class="up-commit"><code class="up-sha"><?= h((string) ($c['sha'] ?? '')) ?></code> <?= h((string) ($c['msg'] ?? '')) ?></div><?php endforeach; ?></div><?php endif; ?>
        <div class="up-hl" style="margin-top:.9rem">Обновить (в консоли сервера, каталог docker-compose панели)</div>
        <div class="up-logbox"><div>docker compose pull remnawave-subscription-middleware</div><div>docker compose up -d remnawave-subscription-middleware</div></div>
        <p class="muted" style="margin-top:.6rem;font-size:.82rem">Образ тянется из реестра; данные (config.php, БД) лежат в volume и не теряются.</p>
    </div>
    <?php elseif ($u_installed !== '' && $u_latest !== ''): ?>
    <div class="card"><p style="margin:0">✅ Установлена последняя версия образа — обновлять нечего.</p></div>
    <?php endif; ?>
<?php else: ?>
    <div class="info">
        Обновление прослойки с GitHub по коммитам: тянутся только изменённые файлы из <b><?= h(update_repo()) ?></b> (ветка <b><?= h(update_branch()) ?></b>). Git на сервере не нужен — только доступ к GitHub. Проверка идёт автоматически раз в 12 часов; пункт меню «Обновление» подсвечивается, когда появились новые коммиты.
    </div>

    <section class="coll" data-coll="update_perms">
        <button type="button" class="coll-head" onclick="collToggle(this)"><span>🔑 Шаг 1 — права на запись (нужно для кнопки «Обновить»)</span>
            <span class="coll-hr"><svg class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg></span>
        </button>
        <div class="coll-body">
            <?php if ($u_writable): ?><div class="info" style="margin-top:0">✓ Права на запись уже есть — шаг выполнен, можно сворачивать.</div><?php endif; ?>
            <p class="muted" style="margin-top:0;line-height:1.7">Чтобы кнопка «Обновить» могла записать новые файлы из GitHub, веб-серверу (пользователь <code><?= h($u_user) ?></code>) нужно право записи в каталог установки. Один раз выполните на сервере под root:</p>
            <div class="up-logbox"><div>cd <?= h(update_root()) ?></div><div>sudo chown -R <?= h($u_user) ?>: <?= h(update_root()) ?></div></div>
            <p class="muted" style="line-height:1.7"><b>Что делает:</b> назначает владельцем всех файлов и папок установки пользователя веб-сервера (<code><?= h($u_user) ?></code>), чтобы PHP при обновлении мог перезаписывать файлы и складывать бэкап в <code>.backups/</code>.</p>
            <p class="muted" style="line-height:1.7"><b>Насколько безопасно:</b> права действуют <u>только внутри этого каталога</u> — за его пределы (на систему, другие сайты) доступ не распространяется. Это штатный режим для самообновляющихся приложений (как WordPress). Единственный риск: при взломе самого сайта злоумышленник сможет переписать код в этой папке — поэтому держите доступ к админке и серверу закрытым. <code>config.php</code> и база наружу не отдаются (закрыты конфигом веб-сервера).</p>
            <button type="button" class="btn" onclick="collToggle(this)">✓ Я сделал — свернуть</button>
        </div>
    </section>

    <div style="display:flex;gap:1rem;flex-wrap:wrap;align-items:stretch;margin-bottom:1rem">
    <div class="card" style="flex:1 1 300px;margin:0">
        <h2 style="margin-top:0;font-size:1rem">Текущая версия</h2>
        <?php if ($u_installed === ''): ?>
            <div class="warn">Базовый коммит не задан. Если вы только что обновили файлы вручную — нажмите «Отметить текущую версию», чтобы зафиксировать, с какого коммита установлена прослойка. После этого новые коммиты будут отслеживаться.</div>
            <form method="post" style="margin-top:.8rem">
                <input type="hidden" name="csrf" value="<?= h($token) ?>">
                <input type="hidden" name="action" value="update_set_current">
                <button type="submit">📌 Отметить текущую версию</button>
            </form>
        <?php else: ?>
            <p style="margin:.2rem 0"><span class="muted">Установлен коммит:</span> <code class="up-sha"><?= h(substr($u_installed, 0, 12)) ?></code></p>
            <?php if ($u_latest !== ''): ?>
                <p style="margin:.2rem 0"><span class="muted">Последний в ветке:</span> <code class="up-sha"><?= h(substr($u_latest, 0, 12)) ?></code><?php if (($u_state['latest_date'] ?? '') !== ''): ?> · <span class="muted"><?= h(substr((string) $u_state['latest_date'], 0, 10)) ?></span><?php endif; ?></p>
            <?php endif; ?>
            <p class="muted" style="margin:.2rem 0;font-size:.82rem">Последняя проверка: <span class="up-localtime" data-ts="<?= (int) $u_checked ?>"><?= h($u_ts($u_checked)) ?></span></p>
        <?php endif; ?>
        <?php if ($u_err !== ''): ?><div class="warn" style="margin-top:.6rem">Последняя ошибка: <?= h($u_err) ?></div><?php endif; ?>

        <form method="post" style="margin-top:.9rem;display:flex;gap:.5rem;flex-wrap:wrap">
            <input type="hidden" name="csrf" value="<?= h($token) ?>">
            <input type="hidden" name="action" value="update_check">
            <button type="submit" class="btn ghost">🔄 Проверить обновления</button>
        </form>
    </div>

    <div class="card" style="flex:1 1 300px;margin:0">
        <h2 style="margin-top:0;font-size:1rem">Ветка обновлений</h2>
        <p style="margin:.2rem 0"><span class="muted">Текущая ветка:</span> <code><?= h($u_branch) ?></code></p>
        <p class="muted" style="margin:.2rem 0;font-size:.82rem"><b>main</b> — стабильная, <b>dev</b> — тестовая. Обновления тянутся из выбранной ветки.</p>
        <form method="post" style="margin-top:.7rem;display:flex;gap:.5rem;flex-wrap:wrap;align-items:center" onsubmit="return (function(f){uiConfirm('Переключить ветку обновлений? После этого проверится последний коммит выбранной ветки.',function(){f.submit();},'Переключить',false);return false;})(this)">
            <input type="hidden" name="csrf" value="<?= h($token) ?>">
            <input type="hidden" name="action" value="update_switch_branch">
            <select name="branch" style="min-width:150px;padding:.4rem .6rem">
                <?php foreach ($u_branches as $b): ?>
                    <option value="<?= h($b) ?>"<?= $b === $u_branch ? ' selected' : '' ?>><?= h($b) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn">Переключить</button>
        </form>
        <p class="muted" style="margin:.5rem 0 0;font-size:.8rem">После переключения нажми «Проверить обновления», затем «Обновить» — прослойка перейдёт на последний коммит выбранной ветки.</p>
    </div>
    </div>

    <?php if ($u_installed !== '' && $u_avail): ?>
    <div class="card" style="border-color:var(--amber)">
        <h2 style="margin-top:0;font-size:1rem">Доступно обновление · коммитов: <?= (int) ($u_state['ahead_by'] ?? count($u_commits)) ?></h2>
        <?php if ($u_commits): ?>
            <div class="up-block">
                <div class="up-hl">Новые коммиты</div>
                <?php foreach ($u_commits as $c): ?>
                    <div class="up-commit"><code class="up-sha"><?= h((string) ($c['sha'] ?? '')) ?></code> <?= h((string) ($c['msg'] ?? '')) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php if ($u_files): ?>
            <div class="up-block">
                <div class="up-hl">Изменённые файлы (<?= count($u_files) ?>)</div>
                <?php foreach ($u_files as $f): $fn = (string) ($f['filename'] ?? ''); $prot = ($fn !== '' && !update_path_ok($fn)); ?>
                    <div class="up-file"<?= $prot ? ' style="opacity:.45"' : '' ?>><?= $u_stbadge((string) ($f['status'] ?? '')) ?> <code><?= h($fn) ?></code><?php if (($f['previous'] ?? '') !== ''): ?> <span class="muted">← <?= h((string) $f['previous']) ?></span><?php endif; ?><?php if ($prot): ?> <span class="up-st" style="background:var(--line);color:var(--muted)">не обновляется</span><?php endif; ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <div class="warn" style="margin-top:.8rem">Обновление перезапишет перечисленные файлы версиями из репозитория. Любые локальные правки в коде этих файлов будут потеряны (кастомизацию держите в config.php и настройках). Перед записью делается бэкап в <code>.backups/</code> — есть откат.</div>
        <form method="post" style="margin-top:.9rem" onsubmit="var f=this;uiConfirm('Применить обновление? Изменённые файлы будут перезаписаны (бэкап в .backups/).',function(){f.submit();},'Обновить',false);return false;">
            <input type="hidden" name="csrf" value="<?= h($token) ?>">
            <input type="hidden" name="action" value="update_apply">
            <button type="submit">⬇️ Обновить до <code><?= h(substr($u_latest, 0, 7)) ?></code></button>
        </form>
        <?php if ($u_isgit): ?>
            <p class="muted" style="margin-top:.6rem;font-size:.82rem">Эта установка под git. Кнопка «Обновить» скачивает файлы из GitHub напрямую (после неё <code>.git</code> станет «грязным» — для последующего <code>git pull</code> сделайте сначала <code>git reset --hard origin/<?= h(update_branch()) ?></code>). Либо обновляйтесь через <code>git pull</code> на сервере.</p>
        <?php endif; ?>
    </div>
    <?php elseif ($u_installed !== '' && $u_latest !== ''): ?>
    <div class="card"><p style="margin:0">✅ Установлена последняя версия — обновлять нечего.</p></div>
    <?php endif; ?>

    <?php if ($u_log): ?>
    <div class="card">
        <h2 style="margin-top:0;font-size:1rem">Журнал последней операции</h2>
        <div class="up-logbox"><?php foreach ($u_log as $line): ?><div><?= h((string) $line) ?></div><?php endforeach; ?></div>
    </div>
    <?php endif; ?>

    <?php if ($u_backup !== ''): ?>
    <div class="card">
        <h2 style="margin-top:0;font-size:1rem">Откат</h2>
        <p class="muted" style="margin-top:0">Последний бэкап: <code><?= h($u_backup) ?></code>. Откат вернёт файлы к состоянию до последнего обновления.</p>
        <form method="post" onsubmit="var f=this;uiConfirm('Откатить последнее обновление из бэкапа?',function(){f.submit();},'Откатить',false);return false;">
            <input type="hidden" name="csrf" value="<?= h($token) ?>">
            <input type="hidden" name="action" value="update_rollback">
            <button type="submit" class="btn ghost">↩️ Откатить последнее обновление</button>
        </form>
    </div>
    <?php endif; ?>

    <section class="coll collapsed" data-coll="update_help">
        <button type="button" class="coll-head" onclick="collToggle(this)"><span>❓ Как это работает и на что обратить внимание</span>
            <span class="coll-hr"><svg class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg></span>
        </button>
        <div class="coll-body">
            <p class="muted" style="margin-top:0;line-height:1.7">Версия фиксируется как SHA коммита (<code>installed_commit</code>). Сравнение с веткой идёт через GitHub API (<code>compare</code>), скачиваются только изменённые файлы. Защищены и не трогаются: <code>config.php</code>, <code>config.example.php</code>, <code>.git</code>, <code>.backups/</code>.</p>
            <p class="muted" style="line-height:1.7">Сначала все файлы скачиваются во временную папку и только потом применяются — при ошибке сети обновление не применится. Перед записью текущие версии копируются в <code>.backups/</code>.</p>
            <p class="muted" style="line-height:1.7">Доступ к GitHub с сервера может резаться (DPI) — тогда будет ошибка сети, попробуйте позже. Без токена лимит GitHub — 60 запросов в час на IP, поэтому авто-проверка идёт раз в 12 часов.</p>
            <p class="muted" style="line-height:1.7">Если обновление требует изменений в БД — это указывается в описании коммита; код миграций применяется идемпотентно при загрузке (как перенос старых заголовков в правила).</p>
        </div>
    </section>
<?php endif; ?>

    <style>
        .up-sha{font-family:monospace;font-size:.86em;background:var(--bg2);padding:.05rem .35rem;border-radius:5px}
        .up-block{margin-top:.8rem}
        .up-hl{font-size:.74rem;text-transform:uppercase;letter-spacing:.03em;color:var(--muted);font-weight:700;margin-bottom:.4rem}
        .up-commit{padding:.25rem 0;line-height:1.5;border-bottom:1px dashed var(--line)}
        .up-file{padding:.22rem 0;display:flex;align-items:center;gap:.5rem;flex-wrap:wrap}
        .up-st{font-size:.68rem;text-transform:uppercase;letter-spacing:.03em;font-weight:700;padding:.1rem .4rem;border-radius:6px;flex:0 0 auto}
        .up-add{background:rgba(34,197,94,.18);color:var(--c-ok-fg)}
        .up-mod{background:rgba(245,181,10,.18);color:var(--amber)}
        .up-del{background:rgba(239,68,68,.18);color:var(--c-bad-fg)}
        .up-ren{background:rgba(59,130,246,.18);color:var(--c-info-fg)}
        .up-logbox{font-family:monospace;font-size:.82rem;line-height:1.6;max-height:320px;overflow:auto;background:var(--bg2);border:1px solid var(--line);border-radius:10px;padding:.7rem .9rem;word-break:break-word}
    </style>
    <script>
    (function(){function p(n){return (n<10?'0':'')+n;}document.querySelectorAll('.up-localtime[data-ts]').forEach(function(el){var ep=parseInt(el.getAttribute('data-ts'),10);if(!ep)return;var d=new Date(ep*1000);if(isNaN(d.getTime()))return;el.textContent=p(d.getDate())+'.'+p(d.getMonth()+1)+'.'+d.getFullYear()+' '+p(d.getHours())+':'+p(d.getMinutes());});})();
    </script>
