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
                html += ' Работает в клиентах: '+d.clients.map(esc).join(', ')+'. Будет доступен после обновления подписки.';
            }
            if(d.warnings && d.warnings.length){
                html += '<ul>'+d.warnings.map(function(w){return '<li class="warn-line">'+esc(w)+'</li>';}).join('')+'</ul>';
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
        var C={'нидерланды':'NL','голландия':'NL','netherlands':'NL','holland':'NL','германия':'DE','germany':'DE','deutschland':'DE','сша':'US','америка':'US','usa':'US','united states':'US','america':'US','великобритания':'GB','британия':'GB','англия':'GB','united kingdom':'GB','britain':'GB','england':'GB','франция':'FR','france':'FR','финляндия':'FI','finland':'FI','швеция':'SE','sweden':'SE','норвегия':'NO','norway':'NO','дания':'DK','denmark':'DK','польша':'PL','poland':'PL','чехия':'CZ','czechia':'CZ','czech':'CZ','австрия':'AT','austria':'AT','швейцария':'CH','switzerland':'CH','италия':'IT','italy':'IT','испания':'ES','spain':'ES','португалия':'PT','portugal':'PT','ирландия':'IE','ireland':'IE','бельгия':'BE','belgium':'BE','люксембург':'LU','luxembourg':'LU','россия':'RU','russia':'RU','украина':'UA','ukraine':'UA','беларусь':'BY','belarus':'BY','казахстан':'KZ','kazakhstan':'KZ','турция':'TR','turkey':'TR','türkiye':'TR','оаэ':'AE','эмираты':'AE','uae':'AE','emirates':'AE','израиль':'IL','israel':'IL','канада':'CA','canada':'CA','бразилия':'BR','brazil':'BR','аргентина':'AR','argentina':'AR','япония':'JP','japan':'JP','корея':'KR','южная корея':'KR','korea':'KR','south korea':'KR','китай':'CN','china':'CN','гонконг':'HK','hong kong':'HK','hongkong':'HK','тайвань':'TW','taiwan':'TW','сингапур':'SG','singapore':'SG','индия':'IN','india':'IN','индонезия':'ID','indonesia':'ID','вьетнам':'VN','vietnam':'VN','таиланд':'TH','thailand':'TH','малайзия':'MY','malaysia':'MY','австралия':'AU','australia':'AU','новая зеландия':'NZ','new zealand':'NZ','юар':'ZA','south africa':'ZA','египет':'EG','egypt':'EG','сербия':'RS','serbia':'RS','румыния':'RO','romania':'RO','болгария':'BG','bulgaria':'BG','венгрия':'HU','hungary':'HU','греция':'GR','greece':'GR','латвия':'LV','latvia':'LV','литва':'LT','lithuania':'LT','эстония':'EE','estonia':'EE','исландия':'IS','iceland':'IS','молдова':'MD','молдавия':'MD','moldova':'MD','грузия':'GE','georgia':'GE','армения':'AM','armenia':'AM','азербайджан':'AZ','azerbaijan':'AZ','мексика':'MX','mexico':'MX','чили':'CL','chile':'CL','кипр':'CY','cyprus':'CY','мальта':'MT','malta':'MT','словакия':'SK','slovakia':'SK','словения':'SI','slovenia':'SI','хорватия':'HR','croatia':'HR'};
        var ISO={}; for(var k in C) ISO[C[k]]=1;
        function flag(iso){ if(!/^[A-Z]{2}$/.test(iso)) return ''; return String.fromCodePoint(0x1F1E6+iso.charCodeAt(0)-65)+String.fromCodePoint(0x1F1E6+iso.charCodeAt(1)-65); }
        function hasFlag(s){ try{ return /^[\u{1F1E6}-\u{1F1FF}]{2}/u.test(s); }catch(e){ return false; } }
        function detect(v){
            var t=v.trim().split(/\s+/), raw0=t[0]||'', low=v.trim().toLowerCase().split(/\s+/);
            var c2=low.slice(0,2).join(' '), c1=low[0]||'';
            if(C[c2]) return C[c2];
            if(C[c1]) return C[c1];
            if(/^[A-Z]{2}$/.test(raw0) && ISO[raw0]) return raw0;
            return '';
        }
        function apply(inp){
            var v=inp.value; if(!v.trim() || hasFlag(v)) return;
            var iso=detect(v); if(!iso) return;
            inp.value=flag(iso)+' '+v.trim();
        }
        document.querySelectorAll('.sqcfg-flag').forEach(function(i){ i.addEventListener('blur',function(){ apply(i); }); });
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
