    <div class="card">
        <h2 style="margin-top:0;font-size:1rem">Грейс-юзеры (<?= count($grace_list) ?>)</h2>
        <p class="muted">Юзеры, переведённые в грейс-сквад. «Грейс до» — момент окончания грейса: юзер вернётся в исходный сквад и истечёт, если не продлит. Время — по вашему часовому поясу.</p>
        <table class="logtbl">
            <thead><tr><th>Пользователь</th><th>Переведён</th><th>Грейс до</th><th>Статус</th></tr></thead>
            <tbody>
            <?php foreach ($grace_list as $g): $active = (int) $g['grace_until'] > time(); ?>
            <tr>
                <td><?php if (($g['username'] ?? '') !== ''): ?><b><?= h($g['username']) ?></b><?php else: ?><code style="font-size:.78rem"><?= h($g['short_uuid']) ?></code><?php endif; ?></td>
                <td class="gt-time muted" data-ts="<?= (int) ($g['created_epoch'] ?? 0) ?>"><?= h((string) ($g['created_at'] ?? '')) ?></td>
                <td class="gt-time" data-ts="<?= (int) $g['grace_until'] ?>"><?= h(date('Y-m-d H:i', (int) $g['grace_until'])) ?></td>
                <td><?php if ($active): ?><span class="tag normal">активен</span><?php else: ?><span class="tag expired">завершён</span><?php endif; ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$grace_list): ?><tr><td colspan="4" class="muted">Пусто — грейс-юзеров нет.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <script>
    (function(){function p(n){return(n<10?'0':'')+n;}document.querySelectorAll('.gt-time[data-ts]').forEach(function(td){var ep=parseInt(td.getAttribute('data-ts'),10);if(!ep)return;var d=new Date(ep*1000);if(isNaN(d.getTime()))return;td.textContent=d.getFullYear()+'-'+p(d.getMonth()+1)+'-'+p(d.getDate())+' '+p(d.getHours())+':'+p(d.getMinutes());});})();
    </script>
