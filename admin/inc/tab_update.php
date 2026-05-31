<?php
$u_state     = update_state();
$u_installed = update_installed_commit();
$u_avail     = update_available();
$u_backup    = trim((string) setting('update_last_backup', ''));
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
?>
    <div class="info">
        Обновление прослойки с GitHub по коммитам: тянутся только изменённые файлы из <b><?= h(update_repo()) ?></b> (ветка <b><?= h(update_branch()) ?></b>). Git на сервере не нужен — только доступ к GitHub. Проверка идёт автоматически раз в сутки; пункт меню «Обновление» подсвечивается, когда появились новые коммиты.
    </div>

    <div class="card">
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
            <p class="muted" style="margin:.2rem 0;font-size:.82rem">Последняя проверка: <?= h($u_ts($u_checked)) ?></p>
        <?php endif; ?>
        <?php if ($u_err !== ''): ?><div class="warn" style="margin-top:.6rem">Последняя ошибка: <?= h($u_err) ?></div><?php endif; ?>

        <form method="post" style="margin-top:.9rem;display:flex;gap:.5rem;flex-wrap:wrap">
            <input type="hidden" name="csrf" value="<?= h($token) ?>">
            <input type="hidden" name="action" value="update_check">
            <button type="submit" class="btn ghost">🔄 Проверить обновления</button>
        </form>
    </div>

    <?php if ($u_installed !== '' && $u_avail): ?>
    <div class="card" style="border-color:#f5b50a">
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
                <?php foreach ($u_files as $f): ?>
                    <div class="up-file"><?= $u_stbadge((string) ($f['status'] ?? '')) ?> <code><?= h((string) ($f['filename'] ?? '')) ?></code><?php if (($f['previous'] ?? '') !== ''): ?> <span class="muted">← <?= h((string) $f['previous']) ?></span><?php endif; ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <div class="warn" style="margin-top:.8rem">Обновление перезапишет перечисленные файлы версиями из репозитория. Любые локальные правки в коде этих файлов будут потеряны (кастомизацию держите в config.php и настройках). Перед записью делается бэкап в <code>backups/</code> — есть откат.</div>
        <form method="post" style="margin-top:.9rem" onsubmit="return uiConfirmForm(this,'Применить обновление? Изменённые файлы будут перезаписаны (бэкап сохранится в backups/).')">
            <input type="hidden" name="csrf" value="<?= h($token) ?>">
            <input type="hidden" name="action" value="update_apply">
            <button type="submit">⬇️ Обновить до <code><?= h(substr($u_latest, 0, 7)) ?></code></button>
        </form>
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
        <form method="post" onsubmit="return uiConfirmForm(this,'Откатить последнее обновление из бэкапа?')">
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
            <p class="muted" style="margin-top:0;line-height:1.7">Версия фиксируется как SHA коммита (<code>installed_commit</code>). Сравнение с веткой идёт через GitHub API (<code>compare</code>), скачиваются только изменённые файлы. Защищены и не трогаются: <code>config.php</code>, <code>config.example.php</code>, <code>.git</code>, <code>backups/</code>.</p>
            <p class="muted" style="line-height:1.7">Сначала все файлы скачиваются во временную папку и только потом применяются — при ошибке сети обновление не применится. Перед записью текущие версии копируются в <code>backups/</code>.</p>
            <p class="muted" style="line-height:1.7">Доступ к GitHub с сервера может резаться (DPI) — тогда будет ошибка сети, попробуйте позже. Без токена лимит GitHub — 60 запросов в час на IP, поэтому авто-проверка идёт раз в сутки.</p>
            <p class="muted" style="line-height:1.7">Если обновление требует изменений в БД — это указывается в описании коммита; код миграций применяется идемпотентно при загрузке (как перенос старых заголовков в правила).</p>
        </div>
    </section>

    <style>
        .up-sha{font-family:monospace;font-size:.86em;background:var(--bg2);padding:.05rem .35rem;border-radius:5px}
        .up-block{margin-top:.8rem}
        .up-hl{font-size:.74rem;text-transform:uppercase;letter-spacing:.03em;color:var(--muted);font-weight:700;margin-bottom:.4rem}
        .up-commit{padding:.25rem 0;line-height:1.5;border-bottom:1px dashed var(--line)}
        .up-file{padding:.22rem 0;display:flex;align-items:center;gap:.5rem;flex-wrap:wrap}
        .up-st{font-size:.68rem;text-transform:uppercase;letter-spacing:.03em;font-weight:700;padding:.1rem .4rem;border-radius:6px;flex:0 0 auto}
        .up-add{background:rgba(34,197,94,.18);color:#22c55e}
        .up-mod{background:rgba(245,181,10,.18);color:#f5b50a}
        .up-del{background:rgba(239,68,68,.18);color:#ef4444}
        .up-ren{background:rgba(59,130,246,.18);color:#3b82f6}
        .up-logbox{font-family:monospace;font-size:.82rem;line-height:1.6;max-height:320px;overflow:auto;background:var(--bg2);border:1px solid var(--line);border-radius:10px;padding:.7rem .9rem;word-break:break-word}
    </style>
