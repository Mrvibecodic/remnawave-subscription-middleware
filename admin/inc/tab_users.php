    <div class="card">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:1rem">
            <h2 style="margin:0;font-size:1rem">Пользователи панели (<?= count($users) ?>)</h2>
            <div style="display:flex;align-items:center;gap:.5rem">
                <input type="text" id="flt" placeholder="фильтр по имени / статусу / shortUuid" style="max-width:340px" oninput="filterRows()">
                <button type="button" class="qh" onclick="help('userflags')" aria-label="Справка по колонкам «Статус» и «Конфиг»">?</button>
            </div>
        </div>
        <p class="muted">Ссылки подписки показаны уже с адресом зеркала <code><?= h($mirror) ?></code> — их и раздавайте.</p>
        <?php if ($users_err): ?>
            <div class="warn">API недоступен: <?= h($users_err) ?>. Проверьте URL панели и токен во вкладке «Подключение».</div>
        <?php endif; ?>
        <style>
            .tag.src-mw{background:rgba(232,89,12,.18);color:#ffa94d}
            .tag.src-panel{background:rgba(47,158,68,.18);color:#69db7c}
            #utbl th.srt{cursor:pointer;user-select:none;white-space:nowrap}
            #utbl th.srt:hover{color:var(--accent-text)}
            #utbl th.srt .sar{font-size:.7rem;opacity:.85;margin-left:.25rem}
            .tag.grace{background:var(--c-info-bg);color:var(--c-info-fg)}
        </style>
        <table id="utbl">
            <tr>
                <th class="srt" onclick="sortUsers(0)">Пользователь<span class="sar"></span></th>
                <th class="srt" onclick="sortUsers(1)">Статус<span class="sar"></span></th>
                <th class="srt" onclick="sortUsers(2)">Истекает<span class="sar"></span></th>
                <th class="srt" onclick="sortUsers(3)">Конфиг<span class="sar"></span></th>
                <th>Лог</th>
                <th>Устройства</th>
                <th>Ссылка подписки (через зеркало)</th>
            </tr>
            <?php
            $grace_sq = grace_squad_uuid();
            foreach ($users as $u):
                $un  = $u['username'] ?? '';
                $st  = $u['status'] ?? '';
                $su  = $u['shortUuid'] ?? '';
                $uuid = $u['uuid'] ?? '';
                $lim  = (isset($u['hwidDeviceLimit']) && $u['hwidDeviceLimit'] !== null && $u['hwidDeviceLimit'] !== '') ? (string) $u['hwidDeviceLimit'] : '';
                $exp_ts = !empty($u['expireAt']) ? strtotime((string) $u['expireAt']) : null;
                if ($exp_ts === false) $exp_ts = null;
                $exp = $exp_ts !== null ? (date('Y-m-d', $exp_ts) . ' в ' . date('H:i', $exp_ts)) : '—';
                $mirror_link = ($mirror !== '' && $su !== '') ? ('https://' . $mirror . '/' . $su) : '';
                $ov  = $ov_index[$su] ?? null;
                $ovr = $ov['reason'] ?? '';

                $src = $ovr === 'blocked' ? 'mw' : 'panel';
                $in_grace = ($grace_sq !== '' && $st === 'ACTIVE' && in_array($grace_sq, grace_squads_from_user($u), true));
                $src_label = $src === 'mw' ? 'Прослойка' : ($in_grace ? 'Панель + Грейс' : 'Панель');
                $has_hwid_block = ($un !== '' && isset($blocked_hwid_users[mb_strtolower($un)]));
            ?>
            <tr>
                <td><?= h($un) ?></td>
                <td><?php if ($in_grace): ?><span class="tag grace">ГРЕЙС</span><?php else: ?><span class="tag <?= h($st) ?>"><?= h($st) ?></span><?php endif; ?></td>
                <td class="muted"<?= $exp_ts !== null ? ' data-ets="' . (int) $exp_ts . '"' : '' ?>><?= h($exp) ?></td>
                <td><span class="tag src-<?= h($src) ?>"><?= h($src_label) ?></span></td>
                <td><?php if ($su !== ''): $nl = isset($nolog_set[$su]); ?><button class="btn-sm nolog-btn<?= $nl ? ' on' : '' ?>" type="button" data-su="<?= h($su) ?>" data-name="<?= h($un) ?>"><?= $nl ? '🙈 Скрыт' : '👁 В логе' ?></button><?php else: ?><span class="muted">—</span><?php endif; ?></td>
                <td><?php if ($uuid !== ''): ?><button class="btn-sm hw-btn" type="button" data-uuid="<?= h($uuid) ?>" data-name="<?= h($un) ?>" data-limit="<?= h($lim) ?>">HWID</button><?php if ($has_hwid_block): ?><span class="tip hw-warn" data-tip="Есть активный блок HWID">!</span><?php endif; ?><?php else: ?><span class="muted">—</span><?php endif; ?></td>
                <td><input class="sublink" type="text" readonly value="<?= h($mirror_link) ?>" title="Нажмите, чтобы скопировать" onclick="subCopy(this)"></td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$users && !$users_err): ?><tr><td colspan="7" class="muted">Пусто</td></tr><?php endif; ?>
        </table>
    </div>

    <div id="hwModal" class="modal-overlay" onclick="if(event.target===this)hwClose()">
        <div class="modal">
            <div class="modal-head">
                <div>Устройства · <span id="hwUser"></span></div>
                <button type="button" class="modal-x" onclick="hwClose()">×</button>
            </div>
            <div class="modal-body">
                <div style="display:flex;align-items:center;justify-content:space-between;gap:1rem;margin-bottom:1rem">
                    <div id="hwCount" class="hw-count"></div>
                    <button id="hwDelAllBtn" class="btn-sm btn-del" type="button" style="display:none" onclick="hwDelAll()">🗑 Удалить все</button>
                </div>
                <div id="hwBody"></div>
            </div>
        </div>
    </div>

    <style>
        .btn-sm{background:transparent;border:1px solid var(--line);color:var(--text);border-radius:8px;padding:.42rem .8rem;font-size:.82rem;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:.35rem;white-space:nowrap}
        .btn-sm:hover{border-color:var(--accent);color:var(--accent-text)}
        .hw-count{font-size:.9rem;color:var(--muted);margin-bottom:.75rem}
        .hw-item{display:flex;align-items:center;justify-content:space-between;gap:1rem;padding:.85rem .9rem;border:1px solid var(--line);border-radius:12px;background:var(--bg2);margin-bottom:.6rem}
        .hw-item:last-child{margin-bottom:0}
        .hw-l{display:flex;align-items:center;gap:.85rem;min-width:0}
        .hw-os{flex:0 0 auto;display:flex;align-items:center;justify-content:center}
        .osico{display:inline-block;width:28px;height:28px;background:var(--text-strong);-webkit-mask-repeat:no-repeat;mask-repeat:no-repeat;-webkit-mask-position:center;mask-position:center;-webkit-mask-size:contain;mask-size:contain}
        .hw-info{min-width:0}
        .hw-model{color:var(--text-strong);font-weight:600;font-size:.95rem;display:flex;align-items:center;gap:.4rem;flex-wrap:wrap}
        .hw-client{color:var(--muted);font-size:.82rem;margin-top:.12rem;overflow-wrap:anywhere;word-break:normal}
        .hw-hwid{font-size:.76rem;color:var(--muted);overflow-wrap:anywhere;word-break:normal;margin-top:.28rem;font-family:monospace}
        .hw-act{display:flex;gap:.5rem;flex:0 0 auto}
        .btn-block{border-color:var(--c-warn-fg);color:var(--c-warn-fg)}
        .btn-block.on{border-color:var(--accent);color:var(--accent-text)}
        .nolog-btn.on{border-color:var(--c-warn-fg);color:var(--c-warn-fg)}
        .btn-del{border-color:var(--red);color:#ff9b94}
        .btn-del:hover{border-color:var(--red);color:#ff9b94;background:var(--c-bad-bg)}
        .hw-warn{display:inline-flex;align-items:center;justify-content:center;width:18px;height:18px;border-radius:50%;background:var(--red);color:#fff;font-size:.72rem;font-weight:700;margin-left:.45rem;cursor:help;vertical-align:middle}
        .tip{position:relative;cursor:help}
        .tip:hover::after{content:attr(data-tip);position:absolute;left:50%;transform:translateX(-50%);bottom:135%;background:var(--card);color:var(--text);border:1px solid var(--line);border-radius:8px;padding:.45rem .7rem;font-size:.78rem;font-weight:500;white-space:nowrap;box-shadow:var(--shadow);z-index:10}
    </style>
    <script>
    var HW_CSRF = <?= json_encode($token) ?>;
    <?php $bh=[]; foreach($overrides as $o){ if(($o['match_type']??'')==='hwid' && ($o['reason']??'')==='blocked') $bh[]=mb_strtolower($o['match_value']); } ?>
    var HW_BLOCKED = <?= json_encode($bh) ?>;
    var hwUuid='', hwLimit='', hwName='', hwDevices=[];
    function hwDate(s){if(!s)return '';var d=new Date(s);if(isNaN(d.getTime()))return '';function p(n){return(n<10?'0':'')+n;}return d.getFullYear()+'-'+p(d.getMonth()+1)+'-'+p(d.getDate())+' '+p(d.getHours())+':'+p(d.getMinutes());}
    function hwOpen(uuid,name,limit){
        hwUuid=uuid; hwLimit=limit||''; hwName=name||'';
        document.getElementById('hwUser').textContent=name;
        document.getElementById('hwBody').innerHTML='<div class="muted">Загрузка…</div>';
        document.getElementById('hwCount').textContent='';
        document.getElementById('hwDelAllBtn').style.display='none';
        document.getElementById('hwModal').classList.add('open');
        hwLoad();
    }
    function hwClose(){ document.getElementById('hwModal').classList.remove('open'); }
    function hwEsc(s){var d=document.createElement('div');d.textContent=(s==null?'':s);return d.innerHTML;}
    function hwOsIcon(p){p=String(p||'').toLowerCase();
        var f='device';
        if(/ios|iphone|ipad|ipados|mac|darwin|os ?x/.test(p))f='apple';
        else if(/android/.test(p))f='android';
        else if(/win/.test(p))f='windows';
        else if(/linux|ubuntu|debian|fedora|arch|centos/.test(p))f='linux';
        return '<span class="osico" style="-webkit-mask-image:url(assets/os/'+f+'.svg);mask-image:url(assets/os/'+f+'.svg)"></span>';}
    function hwLoad(){
        fetch('?ajax=hwids&uuid='+encodeURIComponent(hwUuid)).then(function(r){return r.json();}).then(function(d){
            var b=document.getElementById('hwBody'), c=document.getElementById('hwCount'), all=document.getElementById('hwDelAllBtn');
            if(!d.ok){ b.innerHTML='<div class="warn">Ошибка: '+hwEsc(d.error)+'</div>'; c.textContent=''; all.style.display='none'; return; }
            hwDevices=d.devices||[];
            var n=hwDevices.length;
            c.textContent='Устройств: '+n+(hwLimit?(' / лимит '+hwLimit):'');
            all.style.display=n>1?'':'none';
            if(!n){ b.innerHTML='<div class="muted">Устройств нет.</div>'; return; }
            b.innerHTML=hwDevices.map(function(x){
                var model=x.deviceModel||x.platform||'Устройство';
                var os=[x.platform,x.osVersion].filter(Boolean).join(' ')||'ОС неизвестна';
                var client=x.userAgent||x.user_agent||x.appVersion||'';
                var hwid=x.hwid||'';
                var dt=hwDate(x.updatedAt||x.createdAt||x.updated_at||x.created_at);
                var blocked=HW_BLOCKED.indexOf(String(hwid).toLowerCase())>-1;
                return '<div class="hw-item"><div class="hw-l">'+
                       '<span class="hw-os tip" data-tip="'+hwEsc(os)+'">'+hwOsIcon(x.platform)+'</span>'+
                       '<div class="hw-info">'+
                       '<div class="hw-model">'+hwEsc(model)+(blocked?' <span class="tag blocked" style="font-size:.64rem">заблокирован</span>':'')+'</div>'+
                       '<div class="hw-client">'+(client?('Клиент: '+hwEsc(client)):'<span style="opacity:.6">клиент не указан</span>')+'</div>'+
                       '<div class="hw-hwid">'+hwEsc(hwid)+(dt?(' · обновлён '+hwEsc(dt)):'')+'</div>'+
                       '</div></div>'+
                       '<div class="hw-act">'+
                       '<button class="btn-sm btn-block'+(blocked?' on':'')+' hw-block" type="button" data-hwid="'+hwEsc(hwid)+'">'+(blocked?'✅ Разблок.':'🚫 Блок')+'</button>'+
                       '<button class="btn-sm btn-del hw-del" type="button" data-hwid="'+hwEsc(hwid)+'">🗑 Удалить</button>'+
                       '</div></div>';
            }).join('');
            b.querySelectorAll('.hw-del').forEach(function(btn){btn.addEventListener('click',function(){hwDel(btn.dataset.hwid);});});
            b.querySelectorAll('.hw-block').forEach(function(btn){btn.addEventListener('click',function(){hwBlock(btn.dataset.hwid);});});
        }).catch(function(){document.getElementById('hwBody').innerHTML='<div class="warn">Сетевая ошибка</div>';});
    }
    function hwDelReq(hwid){
        var f=new FormData(); f.append('csrf',HW_CSRF); f.append('uuid',hwUuid); f.append('hwid',hwid);
        return fetch('?ajax=del_hwid',{method:'POST',body:f}).then(function(r){return r.json();});
    }
    function hwBlock(hwid){
        var lc=String(hwid).toLowerCase(), blocked=HW_BLOCKED.indexOf(lc)>-1;
        uiConfirm(blocked?('Снять блокировку HWID?\n'+hwid):('Заблокировать это устройство по HWID?\nКлиент с этим HWID перестанет получать рабочий конфиг. Снять можно здесь же или во вкладке «Оверрайды».\n'+hwid), function(){
            var f=new FormData(); f.append('csrf',HW_CSRF); f.append('hwid',hwid); f.append('username',hwName); f.append('block',blocked?'0':'1');
            fetch('?ajax=block_hwid',{method:'POST',body:f}).then(function(r){return r.json();}).then(function(d){
                if(d.ok){ if(blocked){HW_BLOCKED=HW_BLOCKED.filter(function(x){return x!==lc;});}else{HW_BLOCKED.push(lc);} hwLoad(); }
                else uiAlert('Ошибка: '+(d.error||''));
            }).catch(function(){uiAlert('Сетевая ошибка');});
        }, blocked?'Разблокировать':'Заблокировать', !blocked);
    }
    function hwDel(hwid){
        uiConfirm('Удалить это устройство?\n'+hwid, function(){
            hwDelReq(hwid).then(function(d){ if(d.ok) hwLoad(); else uiAlert('Не удалось удалить: '+(d.error||'')); }).catch(function(){uiAlert('Сетевая ошибка');});
        }, 'Удалить', true);
    }
    function hwDelAll(){
        var list=hwDevices.map(function(x){return x.hwid||'';}).filter(Boolean);
        if(!list.length) return;
        uiConfirm('Удалить ВСЕ устройства ('+list.length+') этого пользователя?', function(){
            var i=0; (function next(){ if(i>=list.length){ hwLoad(); return; } hwDelReq(list[i++]).then(next).catch(next); })();
        }, 'Удалить все', true);
    }
    function subCopy(el){
        if(!el.value) return;
        el.focus(); el.select(); el.setSelectionRange(0, el.value.length);
        var done=function(){ if(window.uiToast) uiToast('Ссылка скопирована'); };
        if(navigator.clipboard && navigator.clipboard.writeText){
            navigator.clipboard.writeText(el.value).then(done).catch(function(){ try{document.execCommand('copy');}catch(e){} done(); });
        } else { try{document.execCommand('copy');}catch(e){} done(); }
    }
    function filterRows(){var q=document.getElementById('flt').value.toLowerCase();
        document.querySelectorAll('#utbl tr').forEach(function(tr,i){if(i===0)return;
            tr.style.display=tr.textContent.toLowerCase().indexOf(q)>-1?'':'none';});}
    var uSort={col:-1,dir:1};
    function sortUsers(col){
        var tbl=document.getElementById('utbl'); if(!tbl||!tbl.tBodies.length) return;
        var body=tbl.tBodies[0];
        var rows=Array.prototype.slice.call(tbl.rows,1);
        if(rows.length<2){return;}
        var dir=(uSort.col===col)?-uSort.dir:1; uSort={col:col,dir:dir};
        rows.sort(function(a,b){
            var x=(a.cells[col]?a.cells[col].textContent:'').trim().toLowerCase();
            var y=(b.cells[col]?b.cells[col].textContent:'').trim().toLowerCase();
            if(x===y) return 0;
            if(x===''||x==='—') return 1;
            if(y===''||y==='—') return -1;
            return (x<y?-1:1)*dir;
        });
        rows.forEach(function(r){body.appendChild(r);});
        var hdr=tbl.rows[0];
        for(var i=0;i<hdr.cells.length;i++){var s=hdr.cells[i].querySelector('.sar'); if(s) s.textContent='';}
        var ar=hdr.cells[col]?hdr.cells[col].querySelector('.sar'):null; if(ar) ar.textContent=dir>0?'▲':'▼';
    }
    document.querySelectorAll('.hw-btn').forEach(function(b){
        b.addEventListener('click',function(){hwOpen(b.dataset.uuid,b.dataset.name||'',b.dataset.limit||'');});
    });
    function nologToggle(btn){
        var su=btn.dataset.su||'', on=btn.classList.contains('on');
        if(!su) return;
        var f=new FormData(); f.append('csrf',HW_CSRF); f.append('short_uuid',su); f.append('nolog',on?'0':'1');
        btn.disabled=true;
        fetch('?ajax=toggle_nolog',{method:'POST',body:f}).then(function(r){return r.json();}).then(function(d){
            btn.disabled=false;
            if(!d.ok){ uiAlert('Ошибка: '+(d.error||'')); return; }
            if(d.nolog){ btn.classList.add('on'); btn.innerHTML='🙈 Скрыт'; } else { btn.classList.remove('on'); btn.innerHTML='👁 В логе'; }
            if(window.uiToast) uiToast(d.nolog?'Запросы пользователя скрыты из лога':'Логирование пользователя включено');
        }).catch(function(){ btn.disabled=false; uiAlert('Сетевая ошибка'); });
    }
    document.querySelectorAll('.nolog-btn').forEach(function(b){
        b.addEventListener('click',function(){nologToggle(b);});
    });
    document.addEventListener('keydown',function(e){if(e.key==='Escape')hwClose();});
    document.querySelectorAll('#utbl td[data-ets]').forEach(function(td){
        var d=new Date(parseInt(td.dataset.ets,10)*1000);
        if(isNaN(d.getTime())) return;
        function p(n){return(n<10?'0':'')+n;}
        td.textContent=d.getFullYear()+'-'+p(d.getMonth()+1)+'-'+p(d.getDate())+' в '+p(d.getHours())+':'+p(d.getMinutes());
    });
    </script>
