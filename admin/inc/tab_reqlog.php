    <style>
        .rl-sum{background:var(--accent-light);border:1px solid var(--accent);border-radius:10px;padding:.7rem 1rem;font-size:.9rem;line-height:1.5;color:var(--text);margin:.1rem 0 1rem}
        .rl-sum b{color:var(--accent-text)}
        .rl-tip{position:relative;cursor:help}
        .rl-tip:hover::after{content:attr(data-tip);position:absolute;left:0;top:135%;white-space:nowrap;background:var(--card);color:var(--text);border:1px solid var(--line);border-radius:8px;padding:.45rem .7rem;font-size:.78rem;font-weight:500;box-shadow:var(--shadow);z-index:20}
    </style>
    <div class="card">
        <div class="loghead">
            <h2>Лог запросов (последние 300) <span id="rlAuto" class="muted" style="font-weight:400;font-size:.76rem"></span></h2>
            <div class="loghead-r">
                <div id="rl_pgrTop" class="pgr"></div>
                <button type="button" class="btn ghost" onclick="rlRefresh()">🔄 Обновить</button>
                <form method="post" onsubmit="return uiConfirmForm(this,'Очистить весь лог запросов?')" style="margin:0">
                    <input type="hidden" name="csrf" value="<?= h($token) ?>">
                    <input type="hidden" name="action" value="clear_reqlog">
                    <button class="danger" type="submit">🧹 Очистить</button>
                </form>
            </div>
        </div>
        <p class="muted">Имя пользователя подтягивается из панели по API (по shortUuid). Если API не настроен — показывается shortUuid. Лог обновляется автоматически, пока вкладка открыта.</p>
        <div class="rl-sum" id="rlSum" data-total-users="<?= (int) $rl_total_users ?>">📊 Сегодня, <?= h($rl_today_label) ?>: подписку обновили <b><?= (int) $rl_today_users ?></b><?= $rl_total_users ? (' из <b>' . (int) $rl_total_users . '</b>') : '' ?> пользователей · уникальных устройств (HWID) за сегодня: <b><?= (int) $rl_today_devices ?></b><?= $rl_total_devices ? (' из <b>' . (int) $rl_total_devices . '</b> известных в логе') : '' ?>.</div>
        <table class="logtbl" style="margin-top:1rem">
            <thead><tr><th>Время</th><th>Решение</th><th>Пользователь</th><th>IP</th><th>expire</th><th>Клиент</th></tr></thead>
            <tbody id="rlBody">
            <?php foreach ($reqlog as $r):
                $su = (string) $r['short_uuid'];
                $uname = ($su !== '' && isset($short2name[$su])) ? $short2name[$su] : '';
            ?>
            <tr>
                <td class="muted rl-time" data-ts="<?= (int) ($r['ts_epoch'] ?? 0) ?>"><?= h($r['ts']) ?></td>
                <td><?php $dec=(string)$r['decision']; $rhw=(string)($r['hwid'] ?? ''); if ($dec==='blocked' && $rhw!==''): $dev=$hwid2info[mb_strtolower($rhw)] ?? ''; ?><span class="tag blocked rl-tip" data-tip="HWID: <?= h($rhw) ?><?= $dev!=='' ? ' · '.h($dev) : '' ?>">HWID blocked</span><?php elseif ($dec==='grace'): ?><span class="tag normal">normal <span style="opacity:.6">(грейс)</span></span><?php else: ?><span class="tag <?= h($dec) ?>"><?= h($dec) ?></span><?php endif; ?></td>
                <td><?php if ($uname !== ''): ?><?= h($uname) ?><?php elseif ($su !== ''): ?><code><?= h($su) ?></code><?php else: ?><span class="muted">—</span><?php endif; ?></td>
                <td><?= h($r['ip']) ?></td>
                <td class="muted rl-exp" data-ts="<?= (int) $r['expire_ts'] ?>"><?= $r['expire_ts'] ? h(date('Y-m-d H:i', (int)$r['expire_ts'])) : '—' ?></td>
                <td class="muted"><?= h(mb_substr((string)$r['user_agent'],0,60)) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$reqlog): ?><tr><td colspan="6" class="muted">Пусто</td></tr><?php endif; ?>
            </tbody>
        </table>
        <div id="rl_pgrBot" class="pgr-bot"></div>
    </div>
    <script>
    var RL_NAMES = <?= json_encode((object) $short2name, JSON_UNESCAPED_UNICODE) ?>;
    var RL_HWIDS = <?= json_encode((object) $hwid2info, JSON_UNESCAPED_UNICODE) ?>;
    var rlPager = window.LogPager ? LogPager({bodyId:'rlBody', topId:'rl_pgrTop', botId:'rl_pgrBot', colspan:6, storeKey:'pg_reqlog'}) : null;
    function rlEsc(s){var d=document.createElement('div');d.textContent=(s==null?'':s);return d.innerHTML;}
    function rlSumRender(s){
        var el=document.getElementById('rlSum'); if(!el||!s) return;
        var totalU=parseInt(el.getAttribute('data-total-users'),10)||0;
        var uPart = s.today_users + (totalU ? (' из <b>'+totalU+'</b>') : '');
        var dPart = s.today_devices + (s.total_devices ? (' из <b>'+s.total_devices+'</b> известных в логе') : '');
        el.innerHTML = '📊 Сегодня, '+rlEsc(s.label)+': подписку обновили <b>'+uPart+'</b> пользователей · '+
                       'уникальных устройств (HWID) за сегодня: <b>'+dPart+'</b>.';
    }
    function rlLocal(ep){ep=parseInt(ep,10);if(!ep)return '';var d=new Date(ep*1000);if(isNaN(d.getTime()))return '';function p(n){return(n<10?'0':'')+n;}return d.getFullYear()+'-'+p(d.getMonth()+1)+'-'+p(d.getDate())+' '+p(d.getHours())+':'+p(d.getMinutes())+':'+p(d.getSeconds());}
    function rlDecCell(dec,hw){
        if(dec==='blocked'&&hw){var dev=RL_HWIDS[String(hw).toLowerCase()]||'';
            return '<span class="tag blocked rl-tip" data-tip="HWID: '+rlEsc(hw)+(dev?(' · '+rlEsc(dev)):'')+'">HWID blocked</span>';}
        if(dec==='grace'){return '<span class="tag normal">normal <span style="opacity:.6">(грейс)</span></span>';}
        return '<span class="tag '+rlEsc(dec)+'">'+rlEsc(dec)+'</span>';
    }
    function rlExpire(ts){ if(!ts) return '—'; var d=new Date(ts*1000); function p(n){return (n<10?'0':'')+n;}
        return d.getFullYear()+'-'+p(d.getMonth()+1)+'-'+p(d.getDate())+' '+p(d.getHours())+':'+p(d.getMinutes()); }
    function rlRender(rows){
        var b=document.getElementById('rlBody'); if(!b) return;
        if(!rows.length){ b.innerHTML='<tr><td colspan="6" class="muted">Пусто</td></tr>'; return; }
        b.innerHTML=rows.map(function(r){
            var su=r.short_uuid||'', name=(su && RL_NAMES[su])?RL_NAMES[su]:'';
            var who=name?rlEsc(name):(su?'<code>'+rlEsc(su)+'</code>':'<span class="muted">—</span>');
            var dec=r.decision||'normal';
            return '<tr><td class="muted">'+(r.ts_epoch?rlLocal(r.ts_epoch):rlEsc(r.ts))+'</td>'+
                   '<td>'+rlDecCell(dec,r.hwid||'')+'</td>'+
                   '<td>'+who+'</td><td>'+rlEsc(r.ip)+'</td>'+
                   '<td class="muted">'+rlExpire(r.expire_ts)+'</td>'+
                   '<td class="muted">'+rlEsc((r.user_agent||'').slice(0,60))+'</td></tr>';
        }).join('');
        if(rlPager) rlPager.refresh();
    }
    function rlRefresh(){
        var a=document.getElementById('rlAuto'); if(a) a.textContent='· обновление…';
        fetch('?ajax=reqlog').then(function(r){return r.json();}).then(function(d){
            if(d.ok){ rlRender(d.rows||[]); rlSumRender(d.stats); }
            if(a) a.textContent='· обновлено в '+new Date().toLocaleTimeString();
        }).catch(function(){ if(a) a.textContent='· ошибка обновления'; });
    }
    document.querySelectorAll('.rl-time[data-ts]').forEach(function(td){var v=rlLocal(td.getAttribute('data-ts'));if(v)td.textContent=v;});
    document.querySelectorAll('.rl-exp[data-ts]').forEach(function(td){td.textContent=rlExpire(parseInt(td.getAttribute('data-ts'),10));});
    setInterval(function(){ if(!document.hidden) rlRefresh(); }, 10000);
    document.addEventListener('visibilitychange',function(){ if(!document.hidden) rlRefresh(); });
    </script>
