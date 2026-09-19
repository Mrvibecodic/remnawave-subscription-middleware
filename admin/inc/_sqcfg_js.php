    <script>
    (function(){
        var SQ_CSRF = <?= json_encode($token) ?>;
        var ta = document.getElementById('sqcfg_raw'), hint = document.getElementById('sqcfg_hint'), t = null;
        if(!ta || !hint) return;
        function esc(s){var d=document.createElement('div');d.textContent=(s==null?'':s);return d.innerHTML.replace(/"/g,'&quot;');}
        function render(d){
            if(!d){ hint.style.display='none'; return; }
            var cls = d.ok ? 'ok' : 'bad';
            var html = d.ok
                ? ('Распознан конфиг <b>'+esc(d.summary)+'</b>.')
                : ('<span class="warn-line">Конфиг не распознан.</span>');
            if(d.ok && d.clients && d.clients.length){
                html += ' Попадёт в: '+d.clients.map(esc).join(', ')+'. Будет доступен после обновления подписки.';
            }
            if(d.warnings && d.warnings.length){
                html += '<ul>'+d.warnings.map(function(w){return '<li class="warn-line">'+esc(w)+'</li>';}).join('')+'</ul>';
            }
            if(d.notes && d.notes.length){
                html += '<ul>'+d.notes.map(function(w){return '<li class="note-line">'+esc(w)+'</li>';}).join('')+'</ul>';
            }
            hint.className='sqcfg-hint '+cls; hint.innerHTML=html; hint.style.display='';
        }
        function check(){
            var raw = ta.value;
            if(raw.trim()===''){ hint.style.display='none'; return; }
            var f=new FormData(); f.append('csrf',SQ_CSRF); f.append('raw',raw);
            fetch('?ajax=parse_config',{method:'POST',body:f}).then(function(r){return r.json();}).then(render).catch(function(){});
        }
        ta.addEventListener('input',function(){ clearTimeout(t); t=setTimeout(check,400); });
    })();
    (function(){
        var C=<?= json_encode(squadconf_country_map(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        var ISO={}; <?= json_encode(squadconf_flag_codes()) ?>.forEach(function(c){ ISO[c]=1; });
        function flag(iso){ if(!/^[A-Z]{2}$/.test(iso)) return ''; return String.fromCodePoint(0x1F1E6+iso.charCodeAt(0)-65)+String.fromCodePoint(0x1F1E6+iso.charCodeAt(1)-65); }
        function hasFlag(s){ try{ return /^[\u{1F1E6}-\u{1F1FF}]{2}/u.test(s); }catch(e){ return false; } }
        function detect(s){
            var low=s.toLowerCase().replace(/ё/g,'е'), vs=[low, low.replace(/-/g,' ')], i, n, w, k, c;
            for(i=0;i<2;i++){
                w=vs[i].trim().split(/\s+/);
                for(n=3;n>=1;n--){ if(w.length<n) continue; k=w.slice(0,n).join(' '); if(Object.prototype.hasOwnProperty.call(C,k)) return C[k]; }
            }
            vs=[s, s.replace(/-/g,' ')];
            for(i=0;i<2;i++){
                c=vs[i].trim().split(/\s+/)[0]||''; if(c==='UK') c='GB';
                if(/^[A-Z]{2}$/.test(c) && ISO[c]) return c;
            }
            return '';
        }
        function apply(inp){
            var t=inp.value.trim(); if(!t || hasFlag(t)) return;
            var iso=detect(t); if(!iso) return;
            inp.value=flag(iso)+' '+t;
        }
        document.querySelectorAll('.sqcfg-flag').forEach(function(i){ i.addEventListener('blur',function(){ apply(i); }); });
        document.addEventListener('submit',function(e){ var f=e.target; if(f && f.querySelectorAll) f.querySelectorAll('.sqcfg-flag').forEach(apply); },true);
    })();
    (function(){
        function sync(cb){ var l = cb.closest('.sq-item'); if(l) l.classList.toggle('on', cb.checked); }
        document.querySelectorAll('.sq-grid').forEach(function(grid){
            var manual = grid.querySelector('input[type=checkbox][value="__manual__"]');
            grid.querySelectorAll('input[type=checkbox]').forEach(function(cb){
                sync(cb);
                cb.addEventListener('change', function(){
                    if(manual){
                        if(cb === manual){
                            if(cb.checked) grid.querySelectorAll('input[type=checkbox]').forEach(function(o){ if(o !== manual && o.checked){ o.checked = false; sync(o); } });
                        } else if(cb.checked && manual.checked){ manual.checked = false; sync(manual); }
                    }
                    sync(cb);
                });
            });
        });
        document.querySelectorAll('.sq-search').forEach(function(inp){
            inp.addEventListener('input', function(){
                var q = inp.value.trim().toLowerCase();
                var box = inp.parentNode.querySelector('.sq-grid'); if(!box) return;
                box.querySelectorAll('.sq-item').forEach(function(ch){
                    var nm = (ch.querySelector('.sq-n') || {}).textContent || '';
                    ch.style.display = nm.toLowerCase().indexOf(q) > -1 ? '' : 'none';
                });
            });
        });
    })();
    window.sqcfgInitEdit = function(){
        var modal = document.getElementById('sqEditModal');
        window.sqEditClose = function(){ if(modal) modal.classList.remove('open'); };
        function openEdit(id){
            var d = (window.SQCFG || {})[id]; if(!d || !modal) return;
            document.getElementById('sqedit_id').value = id;
            var sqs = d.squads || [];
            modal.querySelectorAll('#sqedit_chips input[type=checkbox]').forEach(function(cb){
                cb.checked = sqs.indexOf(cb.value) > -1;
                var l = cb.closest('.sq-item'); if(l) l.classList.toggle('on', cb.checked);
            });
            document.getElementById('sqedit_name').value = d.name || '';
            var g = document.getElementById('sqedit_grp'); if (g) g.value = d.grp || '';
            document.getElementById('sqedit_raw').value = d.raw || '';
            modal.classList.add('open');
        }
        document.querySelectorAll('.sqcfg-edit').forEach(function(b){ b.addEventListener('click',function(){ openEdit(b.dataset.id); }); });
        document.addEventListener('keydown',function(e){ if(e.key === 'Escape') sqEditClose(); });
    };
    window.sqcfgInitPager = function(tblId, pagerId, sizeId, storeKey){
        var SIZES = [25, 50, 100, 200], size = 25, page = 1;
        var PGK = 'pgr_' + storeKey;
        function pgrCkGet(){ var m = document.cookie.match(new RegExp('(?:^|;\\s*)' + PGK + '=([^;]*)')); return m ? parseInt(m[1], 10) : NaN; }
        function pgrCkSet(v){ try { document.cookie = PGK + '=' + v + ';path=/;max-age=31536000;samesite=Lax'; } catch (e) {} }
        var cv = pgrCkGet();
        if (SIZES.indexOf(cv) > -1) { size = cv; }
        else { try { var s = parseInt(localStorage.getItem(storeKey), 10); if (SIZES.indexOf(s) > -1) { size = s; pgrCkSet(s); } } catch (e) {} }
        function rows(){ var t = document.getElementById(tblId); if (!t || !t.tBodies.length) return []; return Array.prototype.slice.call(t.tBodies[0].rows); }
        function render(){
            var all = rows(), total = all.length, per = size, pages = Math.max(1, Math.ceil(total / per));
            if (page > pages) page = pages; if (page < 1) page = 1;
            var start = (page - 1) * per, end = start + per;
            all.forEach(function(tr, i){ tr.style.display = (i >= start && i < end) ? '' : 'none'; });
            var bot = document.getElementById(pagerId);
            if (bot) {
                if (total > per) {
                    bot.innerHTML = '<div class="pgr-nav">'
                        + '<button type="button" class="pgr-b" data-go="prev"' + (page <= 1 ? ' disabled' : '') + '>◀</button>'
                        + '<span class="pgr-st">' + (total ? start + 1 : 0) + '–' + Math.min(end, total) + ' из ' + total + ' · стр. ' + page + '/' + pages + '</span>'
                        + '<button type="button" class="pgr-b" data-go="next"' + (page >= pages ? ' disabled' : '') + '>▶</button>'
                        + '</div>';
                    bot.querySelectorAll('.pgr-b').forEach(function(b){ b.addEventListener('click', function(){ if (b.dataset.go === 'prev' && page > 1) page--; if (b.dataset.go === 'next' && page < pages) page++; render(); }); });
                } else bot.innerHTML = '';
            }
        }
        window.SQCFGP = { setSize: function(v){ if (SIZES.indexOf(v) < 0) v = 25; size = v; page = 1; try { localStorage.setItem(storeKey, String(v)); } catch (e) {} pgrCkSet(v); render(); } };
        var sel = document.getElementById(sizeId); if (sel) sel.value = String(size);
        var tEl0 = document.getElementById(tblId); if (tEl0) tEl0.classList.remove('pgr-pre');
        render();
    };
        window.sqcfgInitManual = function(NAMES){
        NAMES = NAMES || {};
        var cfgSel = document.getElementById('wgm_cfg');
        if(!cfgSel) return;
        function chkReady(){
            var btn = document.getElementById('wgm_submit'); if(!btn) return;
            btn.disabled = !(document.getElementById('wgm_short').value && cfgSel.value);
        }
        cfgSel.addEventListener('change', chkReady);
        var findBtn = document.getElementById('wgm_find');
        if(findBtn){
            findBtn.addEventListener('click', function(){
                var q = document.getElementById('wgm_q').value.trim(); if(!q) return;
                var info = document.getElementById('wgm_info'); info.textContent = 'Ищу…';
                fetch('?ajax=pool_user&q=' + encodeURIComponent(q)).then(function(r){ return r.json(); }).then(function(d){
                    if(!d.ok){ info.textContent = d.error || 'Не найден'; document.getElementById('wgm_short').value = ''; chkReady(); return; }
                    document.getElementById('wgm_short').value = d.user.shortUuid || '';
                    var sqn = (d.user.squads || []).map(function(s){ return NAMES[s.uuid] || s.name || s.uuid; }).join(', ');
                    var lim = (d.user.hwidDeviceLimit == null ? '' : (' · лимит устройств: ' + d.user.hwidDeviceLimit));
                    info.innerHTML = 'Пользователь: <b>' + esc(d.user.username || '') + '</b>' + lim + (sqn ? (' · сквады: ' + esc(sqn)) : '');
                    var hw = document.getElementById('wgm_hwid');
                    if(hw){
                        hw.innerHTML = '<option value="">— любое (на пользователя)</option>';
                        (d.devices || []).forEach(function(dv){ var o=document.createElement('option'); o.value=dv.hwid; o.textContent=(dv.platform||dv.deviceModel||'')+' · '+(dv.hwid||''); hw.appendChild(o); });
                    }
                    chkReady();
                }).catch(function(){ info.textContent = 'Ошибка запроса'; });
            });
        }
    };
    window.sqcfgInitFileBtn = function(inputId, infoId){
        var inp = document.getElementById(inputId), info = document.getElementById(infoId);
        if(!inp) return;
        inp.addEventListener('change', function(){
            var n = inp.files ? inp.files.length : 0;
            if(!info) return;
            if(!n){ info.textContent = 'Файлы не выбраны'; return; }
            var names = []; for(var i=0;i<inp.files.length && i<6;i++) names.push(inp.files[i].name);
            info.textContent = 'Выбрано файлов: ' + n + ' — ' + names.join(', ') + (n>6?' …':'');
        });
    };
    </script>
