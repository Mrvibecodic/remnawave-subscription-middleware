<?php
$ico_dev    = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="2" width="14" height="20" rx="2"/><line x1="9" y1="18" x2="15" y2="18"/></svg>';
$ico_eye    = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/></svg>';
$ico_eyeoff = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9.9 4.2A9.1 9.1 0 0 1 12 4c6.5 0 10 7 10 7a13 13 0 0 1-2.2 3M6.6 6.6A13 13 0 0 0 2 11s3.5 7 10 7a9 9 0 0 0 4.5-1.2"/><line x1="2" y1="2" x2="22" y2="22"/></svg>';
?>
    <div class="card">
        <div class="utbl-head">
            <h2>Пользователи панели (<?= count($users) ?>)</h2>
            <?php if ($users): ?>
            <div class="utbl-tools">
                <input type="text" id="flt" placeholder="фильтр по имени / статусу / shortUuid" oninput="filterRows()">
                <div class="dens" title="Плотность строк">
                    <button type="button" class="on" onclick="utblDens(0,this)" aria-label="Комфортная плотность"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="4" y1="6" x2="20" y2="6"/><line x1="4" y1="12" x2="20" y2="12"/><line x1="4" y1="18" x2="20" y2="18"/></svg></button>
                    <button type="button" onclick="utblDens(1,this)" aria-label="Компактная плотность"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="4" y1="5" x2="20" y2="5"/><line x1="4" y1="9" x2="20" y2="9"/><line x1="4" y1="13" x2="20" y2="13"/><line x1="4" y1="17" x2="20" y2="17"/></svg></button>
                </div>
                <button type="button" class="qh" onclick="help('userflags')" aria-label="Справка по колонкам «Статус» и «Конфиг»">?</button>
            </div>
            <?php endif; ?>
        </div>
        <p class="muted">Ссылки подписки показаны уже с адресом зеркала <code><?= h($mirror) ?></code> — их и раздавайте.</p>
        <?php if ($users_err): ?>
            <div class="warn">API недоступен: <?= h($users_err) ?>. Проверьте URL панели и токен во вкладке «Подключение».</div>
        <?php endif; ?>

        <?php if ($users): ?>
        <div class="utbl-wrap">
        <table id="utbl">
            <thead>
            <tr>
                <th class="srt" onclick="sortUsers(0)">Пользователь<span class="sar"></span></th>
                <th class="srt" onclick="sortUsers(1)">Статус<span class="sar"></span></th>
                <th class="srt" onclick="sortUsers(2)">Истекает<span class="sar"></span></th>
                <th class="srt" onclick="sortUsers(3)">Конфиг<span class="sar"></span></th>
                <th>Ссылка подписки (через зеркало)</th>
                <th>Действия</th>
            </tr>
            </thead>
            <tbody>
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
                $nl = ($su !== '' && isset($nolog_set[$su]));
            ?>
            <tr>
                <td class="u-name"><?= h($un) ?></td>
                <td data-label="Статус"><?php if ($in_grace): ?><span class="tag grace"><span class="d"></span>ГРЕЙС</span><?php else: ?><span class="tag <?= h($st) ?>"><span class="d"></span><?= h($st) ?></span><?php endif; ?></td>
                <td data-label="Истекает" class="muted"<?= $exp_ts !== null ? ' data-ets="' . (int) $exp_ts . '"' : '' ?>><?= h($exp) ?></td>
                <td data-label="Конфиг"><span class="tag src-<?= h($src) ?>"><?= h($src_label) ?></span></td>
                <td data-label="Ссылка подписки"><?php if ($mirror_link !== ''): ?><input class="sublink" type="text" readonly value="<?= h($mirror_link) ?>" title="Нажмите, чтобы скопировать" onclick="subCopy(this)"><?php else: ?><span class="muted">—</span><?php endif; ?></td>
                <td data-label="Действия" class="u-actions">
                    <?php if ($uuid !== '' || $su !== ''): ?>
                    <div class="actcell">
                        <?php if ($uuid !== ''): ?><button class="icobtn hw-btn" type="button" data-uuid="<?= h($uuid) ?>" data-name="<?= h($un) ?>" data-limit="<?= h($lim) ?>" title="Устройства<?= $has_hwid_block ? ' · есть активный блок HWID' : '' ?>"><?= $ico_dev ?><?php if ($has_hwid_block): ?><span class="alert">!</span><?php endif; ?></button><?php endif; ?>
                        <?php if ($su !== ''): ?><button class="icobtn nolog-btn<?= $nl ? ' on' : '' ?>" type="button" data-su="<?= h($su) ?>" data-name="<?= h($un) ?>" title="<?= $nl ? 'Скрыт из лога — нажмите, чтобы вернуть' : 'В логе — нажмите, чтобы скрыть' ?>"><?= $nl ? $ico_eyeoff : $ico_eye ?></button><?php endif; ?>
                    </div>
                    <?php else: ?><span class="muted">—</span><?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php elseif (!$users_err): ?>
        <div class="uempty">
            <span class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="7" r="4"/><path d="M3 21v-2a4 4 0 0 1 4-4h4a4 4 0 0 1 4 4v2"/></svg></span>
            <b>Пользователей пока нет</b>
            <span>Список тянется из API панели Remnawave. Проверьте URL панели и токен во вкладке «Подключение».</span>
        </div>
        <?php endif; ?>
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
        .utbl-head{display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap}
        .utbl-head h2{margin:0;font-size:1rem}
        .utbl-tools{display:flex;align-items:center;gap:.5rem;flex-wrap:wrap}
        .utbl-tools input#flt{width:280px;max-width:48vw}
        .dens{display:inline-flex;border:1px solid var(--line);border-radius:8px;overflow:hidden}
        .dens button{background:transparent;border:0;color:var(--muted);padding:.45rem .55rem;cursor:pointer;display:flex;align-items:center}
        .dens button.on{background:var(--accent-light);color:var(--accent-text)}
        .dens button:hover{color:var(--text)}
        .dens svg{width:15px;height:15px}
        .utbl-wrap{overflow:auto;border:1px solid var(--line);border-radius:12px;margin-top:.9rem;max-height:calc(100vh - 210px);scrollbar-width:thin;scrollbar-color:var(--line) transparent}
        .utbl-wrap::-webkit-scrollbar{width:8px;height:8px}
        .utbl-wrap::-webkit-scrollbar-track{background:transparent}
        .utbl-wrap::-webkit-scrollbar-thumb{background:var(--line);border-radius:8px}
        .utbl-wrap:hover::-webkit-scrollbar-thumb{background:var(--muted)}
        #utbl{width:100%;border-collapse:separate;border-spacing:0;font-size:.88rem}
        #utbl thead th{position:sticky;top:0;z-index:2;background:var(--bg2);color:var(--muted);font-weight:600;font-size:.72rem;text-transform:uppercase;letter-spacing:.03em;text-align:left;padding:.7rem .8rem;box-shadow:inset 0 -1px 0 var(--line);white-space:nowrap}
        #utbl thead th.srt{cursor:pointer;user-select:none}
        #utbl thead th.srt:hover{color:var(--accent-text)}
        #utbl thead th .sar{font-size:.7rem;opacity:.85;margin-left:.2rem}
        #utbl tbody td{padding:.7rem .8rem;box-shadow:inset 0 -1px 0 var(--line);vertical-align:middle}
        #utbl tbody tr:last-child td{box-shadow:none}
        #utbl tbody tr:hover td{background:var(--hover2)}
        #utbl.compact tbody td{padding:.42rem .8rem}
        #utbl .u-name{color:var(--text-strong);font-weight:600}
        #utbl .tag{display:inline-flex;align-items:center;gap:.3rem}
        #utbl .tag .d{width:6px;height:6px;border-radius:50%;background:currentColor;flex:0 0 auto}
        .tag.grace{background:var(--c-info-bg);color:var(--c-info-fg)}
        #utbl .sublink{font-family:monospace}
        #utbl .u-actions{white-space:nowrap}
        .actcell{display:flex;gap:.35rem;align-items:center}
        .icobtn{display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;padding:0;line-height:1;border:1px solid var(--line);border-radius:8px;color:var(--muted);background:transparent;cursor:pointer;position:relative;flex:0 0 auto}
        .icobtn:hover{border-color:var(--accent);color:var(--accent-text)}
        .icobtn svg{width:15px;height:15px}
        .icobtn.on{border-color:var(--c-warn-fg);color:var(--c-warn-fg)}
        .icobtn .alert{position:absolute;top:-5px;right:-5px;width:14px;height:14px;border-radius:50%;background:var(--red);color:#fff;font-size:.6rem;font-weight:700;display:flex;align-items:center;justify-content:center;border:1.5px solid var(--card)}
        .uempty{display:flex;flex-direction:column;align-items:center;text-align:center;gap:.6rem;padding:2.4rem 1rem;color:var(--muted)}
        .uempty .ic{width:46px;height:46px;border-radius:12px;background:var(--bg2);border:1px solid var(--line);display:flex;align-items:center;justify-content:center}
        .uempty .ic svg{width:22px;height:22px;opacity:.7}
        .uempty b{color:var(--text);font-size:.95rem}
        @media(max-width:900px){
            .utbl-wrap{max-height:none;overflow:visible;border:0;margin-top:.6rem}
            #utbl{display:block}
            #utbl thead{display:none}
            #utbl tbody,#utbl tbody tr,#utbl tbody td{display:block;width:100%}
            #utbl tbody tr{border:1px solid var(--line);border-radius:12px;background:var(--bg2);padding:.85rem;margin-bottom:.7rem}
            #utbl tbody td{box-shadow:none;padding:.28rem 0;display:flex;justify-content:space-between;align-items:center;gap:1rem;white-space:normal}
            #utbl tbody td::before{content:attr(data-label);color:var(--muted);font-size:.8rem;flex:0 0 auto}
            #utbl tbody td.u-name{font-size:1rem;padding-bottom:.45rem}
            #utbl tbody td.u-name::before{display:none}
            #utbl tbody td[data-label="Ссылка подписки"]{display:block}
            #utbl tbody td[data-label="Ссылка подписки"]::before{display:block;margin-bottom:.3rem}
            #utbl .sublink{width:100%}
            #utbl tbody td.u-actions{justify-content:flex-start}
        }
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
        .btn-del{border-color:var(--red);color:#ff9b94}
        .btn-del:hover{border-color:var(--red);color:#ff9b94;background:var(--c-bad-bg)}
        .tip{position:relative;cursor:help}
        .tip:hover::after{content:attr(data-tip);position:absolute;left:50%;transform:translateX(-50%);bottom:135%;background:var(--card);color:var(--text);border:1px solid var(--line);border-radius:8px;padding:.45rem .7rem;font-size:.78rem;font-weight:500;white-space:nowrap;box-shadow:var(--shadow);z-index:10}
    </style>
    <script>
    var HW_CSRF = <?= json_encode($token) ?>;
    var NL_EYE = <?= json_encode($ico_eye) ?>;
    var NL_EYEOFF = <?= json_encode($ico_eyeoff) ?>;
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
        document.querySelectorAll('#utbl tbody tr').forEach(function(tr){
            tr.style.display=tr.textContent.toLowerCase().indexOf(q)>-1?'':'none';});}
    var uSort={col:-1,dir:1};
    function sortUsers(col){
        var tbl=document.getElementById('utbl'); if(!tbl||!tbl.tBodies.length) return;
        var body=tbl.tBodies[0];
        var rows=Array.prototype.slice.call(body.rows);
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
        var hdr=tbl.tHead?tbl.tHead.rows[0]:tbl.rows[0];
        for(var i=0;i<hdr.cells.length;i++){var s=hdr.cells[i].querySelector('.sar'); if(s) s.textContent='';}
        var ar=hdr.cells[col]?hdr.cells[col].querySelector('.sar'):null; if(ar) ar.textContent=dir>0?'▲':'▼';
    }
    function utblDens(c,btn){
        var t=document.getElementById('utbl'); if(t) t.classList.toggle('compact',c===1);
        document.querySelectorAll('.dens button').forEach(function(x){x.classList.remove('on');});
        if(btn) btn.classList.add('on');
        try{localStorage.setItem('utbl_dens',c===1?'1':'0');}catch(e){}
    }
    (function(){try{if(localStorage.getItem('utbl_dens')==='1'){var t=document.getElementById('utbl');if(t)t.classList.add('compact');var bs=document.querySelectorAll('.dens button');if(bs.length>1){bs.forEach(function(x){x.classList.remove('on');});bs[1].classList.add('on');}}}catch(e){}})();
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
            if(d.nolog){ btn.classList.add('on'); btn.innerHTML=NL_EYEOFF; btn.title='Скрыт из лога — нажмите, чтобы вернуть'; }
            else { btn.classList.remove('on'); btn.innerHTML=NL_EYE; btn.title='В логе — нажмите, чтобы скрыть'; }
            if(window.uiToast) uiToast(d.nolog?'Запросы пользователя скрыты из лога':'Логирование пользователя включено');
        }).catch(function(){ btn.disabled=false; uiAlert('Сетевая ошибка'); });
    }
    document.querySelectorAll('.nolog-btn').forEach(function(b){
        b.addEventListener('click',function(){nologToggle(b);});
    });
    document.addEventListener('keydown',function(e){if(e.key===