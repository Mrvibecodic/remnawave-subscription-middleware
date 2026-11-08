<?php
$hv_rows = function_exists('hostov_list') ? hostov_list() : [];
$hv_jf = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
?>
    <style>
.hv-kpis{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:1rem;margin-bottom:1.25rem}
.hv-kpi{background:var(--card);border:1px solid var(--line);border-radius:var(--radius);padding:.9rem 1rem;box-shadow:var(--shadow)}
.hv-kpi .k{font-size:.72rem;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);font-weight:600}
.hv-kpi .v{font-size:1.6rem;font-weight:700;color:var(--text-strong);margin-top:.15rem;font-variant-numeric:tabular-nums;line-height:1.2}
.hv-kpi .d{font-size:.78rem;color:var(--muted);margin-top:.15rem}
.hv-kpi.w .v{color:var(--c-warn-fg)}
.hv-kpi.m .v{color:var(--muted)}
.hv-intro{display:flex;gap:.9rem;align-items:flex-start}
.hv-intro .ic{width:40px;height:40px;border-radius:11px;background:var(--c-violet-bg);color:var(--c-violet-fg);display:flex;align-items:center;justify-content:center;flex:none}
.hv-intro .ic svg{width:20px;height:20px}
.hv-intro p{margin:.1rem 0 0;color:var(--muted);font-size:.86rem;line-height:1.5}
.hv-intro b{color:var(--text-strong)}
.hv-tools{display:flex;gap:.6rem;align-items:center;flex-wrap:wrap;margin:.2rem 0 .9rem}
.hv-tools .srch{flex:1 1 16rem;max-width:26rem;position:relative}
.hv-tools .srch svg{position:absolute;left:.7rem;top:50%;transform:translateY(-50%);width:16px;height:16px;color:var(--muted)}
.hv-tools .srch input{padding-left:2.2rem;width:100%}
.hv-tools .sp{flex:1}
.hv-sg{display:inline-flex;border:1px solid var(--line);border-radius:9px;overflow:hidden;background:var(--bg2)}
.hv-sg button{all:unset;cursor:pointer;padding:.42rem .7rem;font-size:.8rem;color:var(--muted);border-left:1px solid var(--line);white-space:nowrap}
.hv-sg button:first-child{border-left:0}
.hv-sg button:hover{background:var(--hover);color:var(--text)}
.hv-sg button.on{background:var(--accent-light);color:var(--accent-text);font-weight:600}
.hv-sg button .c{margin-left:.3rem;font-size:.72rem;opacity:.8;font-variant-numeric:tabular-nums}
.hv-tw2{border:1px solid var(--line);border-radius:12px;overflow:hidden}
table.hv-tb{width:100%;border-collapse:collapse;font-size:.86rem}
.hv-tb th{text-align:left;font-size:.72rem;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);font-weight:600;padding:.65rem .8rem;border-bottom:1px solid var(--line);background:var(--bg2);white-space:nowrap}
.hv-tb td{padding:.6rem .8rem;border-top:1px solid var(--line);vertical-align:middle}
.hv-tb tr:first-child td{border-top:0}
.hv-tb tr:hover td{background:var(--hover2)}
.hv-tb tr.off td{color:var(--muted)}
.hv-tb tr.off .hv-un,.hv-tb tr.off .hv-hn,.hv-tb tr.off .hv-nv{opacity:.6}
.hv-un{all:unset;cursor:pointer;display:flex;flex-direction:column;min-width:0}
.hv-un .n{font-weight:600;color:var(--text-strong)}
.hv-un .s{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:.72rem;color:var(--muted)}
.hv-un:hover .n{color:var(--accent-text)}
.hv-hn{font-weight:500;color:var(--text)}
.hv-nv{display:inline-flex;align-items:center;gap:.4rem;white-space:nowrap}
.hv-nv b{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;color:var(--text-strong)}
.hv-tb .acts{display:flex;gap:.2rem;justify-content:flex-end}
.hv-tb .lst{font-size:.8rem;display:flex;flex-direction:column;gap:.05rem}
.hv-tb .lst .a{color:var(--muted);font-size:.74rem}
.hv-tb .tl{font-size:.82rem;white-space:nowrap}
.hv-tb .tl .r{display:block;color:var(--muted);font-size:.74rem}
.hv-ft{display:flex;align-items:center;gap:.6rem;margin-top:.8rem;font-size:.8rem;color:var(--muted);flex-wrap:wrap}
.hv-ft .sp{flex:1}
.hv-emp{padding:2.4rem 1rem;text-align:center;color:var(--muted)}
.hv-emp .ic{width:46px;height:46px;border-radius:13px;background:var(--bg2);border:1px solid var(--line);display:inline-flex;align-items:center;justify-content:center;margin-bottom:.7rem}
.hv-emp .ic svg{width:22px;height:22px}
.hv-emp b{display:block;color:var(--text-strong);font-size:.95rem;margin-bottom:.25rem}
@media(max-width:1100px){.hv-tb .c-ls{display:none}}
@media(max-width:820px){
 .hv-kpis{grid-template-columns:repeat(3,minmax(0,1fr));gap:.5rem}
 .hv-kpi{padding:.7rem .75rem}.hv-kpi .v{font-size:1.3rem}.hv-kpi .d{display:none}
 .hv-tw2{border:0;border-radius:0}
 .hv-tools .srch{max-width:none;flex:1 1 100%}
 .hv-sg{display:grid;grid-template-columns:1fr 1fr;width:100%}
 .hv-sg button{text-align:center;border-left:0;border-top:1px solid var(--line)}
 .hv-sg button:nth-child(-n+2){border-top:0}.hv-sg button:nth-child(even){border-left:1px solid var(--line)}
 .hv-tools .sp{display:none}.hv-tools .btn{width:100%;justify-content:center}
 table.hv-tb,.hv-tb tbody,.hv-tb tr,.hv-tb td{display:block}
 .hv-tb thead{display:none}
 .hv-tb tr{border:1px solid var(--line);border-radius:12px;margin-bottom:.6rem;padding:.65rem .75rem;display:grid;grid-template-columns:minmax(0,1fr) auto;gap:.35rem .8rem;background:var(--card)}
 .hv-tb tr:hover td{background:none}
 .hv-tb td{border:0;padding:0}
 .hv-tb td.c-us{grid-column:1}
 .hv-tb td.c-ac{grid-column:2;grid-row:1}
 .hv-tb td.c-hs,.hv-tb td.c-vl,.hv-tb td.c-tl,.hv-tb td.c-ls{grid-column:1/-1}
 .hv-tb td.c-ls{display:block}
 .hv-tb td[data-l]:before{content:attr(data-l);display:block;font-size:.7rem;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;margin-bottom:.1rem}
}
@media(max-width:560px){.hv-kpis{grid-template-columns:repeat(3,minmax(0,1fr))}.hv-kpi .k{font-size:.62rem}}
.hv-tb thead th{position:static}
.hv-tools .btn{display:inline-flex;align-items:center;gap:.45rem;min-height:38px;width:auto;margin-left:0}
.hv-tools .btn svg{width:15px;height:15px}
.hv-tools .btn:disabled{opacity:.45;cursor:not-allowed;filter:none}
.hv-ar{color:var(--muted);display:inline-flex}
.hv-ar svg{width:14px;height:14px}
.hv-pill{display:inline-flex;align-items:center;padding:.1rem .5rem;border-radius:999px;font-size:.74rem;font-weight:600;white-space:nowrap;line-height:1.5;width:max-content}
.hv-pill.w{background:var(--c-warn-bg);color:var(--c-warn-fg)}.hv-pill.b{background:var(--c-bad-bg);color:var(--c-bad-fg)}.hv-pill.m{background:var(--hover);color:var(--muted)}
.hv-ib,.hv-ib:active{width:30px;height:30px;padding:0;display:inline-flex;align-items:center;justify-content:center;border-radius:8px;border:1px solid transparent;background:none;color:var(--muted);cursor:pointer;min-height:0;filter:none;transform:none;text-decoration:none}
.hv-ib:hover{background:var(--hover);color:var(--text-strong);border-color:var(--line)}
.hv-ib svg{width:16px;height:16px}
.hv-ib.bad{color:var(--c-bad-fg)}.hv-ib.bad:hover{background:var(--c-bad-bg);border-color:transparent;color:var(--c-bad-fg)}
.hvtab .card{margin-bottom:1.25rem}

    </style>
    <div class="hvtab" id="hvTab"></div>
    <script>
(function(){
var R=<?= json_encode($hv_rows, $hv_jf) ?>,CSRF=<?= json_encode((string) $token, $hv_jf) ?>,F='all',Q='';
var P={server:'<rect x="2" y="2" width="20" height="8" rx="2"/><rect x="2" y="14" width="20" height="8" rx="2"/><path d="M6 6h.01M6 18h.01"/>',search:'<circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/>',arrr:'<path d="M5 12h14M13 6l6 6-6 6"/>',trash:'<path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>',pencil:'<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>'};
function ico(n,w){return '<svg viewBox="0 0 24 24" width="'+(w||16)+'" height="'+(w||16)+'" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'+(P[n]||'')+'</svg>';}
function esc(s){return String(s==null?'':s).replace(/[&<>"']/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];});}
function pl(n,a,b,c){var x=n%10,y=n%100;return x===1&&y!==11?a:(x>=2&&x<=4&&(y<10||y>=20)?b:c);}
function p2(n){return(n<10?'0':'')+n;}
function fdt(t){var d=new Date(t*1000);return p2(d.getDate())+'.'+p2(d.getMonth()+1)+' в '+p2(d.getHours())+':'+p2(d.getMinutes());}
function now(){return Date.now()/1000;}
function ago(t){var s=now()-t;return s<120?'только что':s<3600?Math.round(s/60)+' мин назад':s<86400?Math.round(s/3600)+' ч назад':Math.round(s/86400)+' '+pl(Math.round(s/86400),'день','дня','дней')+' назад';}
function left(t){var s=t-now();if(s<86400)return 'через '+Math.max(1,Math.round(s/3600))+' ч';var d=Math.round(s/86400);return 'через '+d+' '+pl(d,'день','дня','дней');}
function st(o){if(o.till&&o.till<=now())return 'exp';return o.st==='part'?'ok':(o.st==='inv'?'bad':o.st);}
function draw(){
 var box=document.getElementById('hvTab'),n={ok:0,wait:0,exp:0,bad:0},us={};
 R.forEach(function(o){var s=st(o);n[s]++;if(s==='ok'||s==='wait')us[o.su]=1;});
 var nu=Object.keys(us).length,off=n.exp+n.bad;
 var h='<div class="hv-kpis"><div class="hv-kpi"><div class="k">Действуют</div><div class="v">'+(n.ok+n.wait)+'</div><div class="d">у '+nu+' '+pl(nu,'пользователя','пользователей','пользователей')+'</div></div>'+
  '<div class="hv-kpi'+(n.wait?' w':'')+'"><div class="k">Ещё не уходили</div><div class="v">'+n.wait+'</div><div class="d">приложение не обновляло подписку</div></div>'+
  '<div class="hv-kpi m"><div class="k">Не действуют</div><div class="v">'+off+'</div><div class="d">срок вышел или подмена не срабатывает</div></div></div>';
 h+='<div class="card"><div class="loghead"><h2>Подмена хостов <span class="muted" style="font-weight:400;font-size:.78rem">для тестов, панель не меняется</span></h2></div>'+
  '<div class="hv-intro" style="margin:.8rem 0 1rem"><span class="ic">'+ico('server',20)+'</span><p>Подмена меняет параметры выбранного хоста в подписке <b>одного пользователя</b> — например, отпечаток TLS, чтобы проверить его на своём устройстве. Добавить подмену — в карточке пользователя, вкладка «Изменить».</p></div>';
 if(!R.length){box.innerHTML=h+'<div class="hv-emp"><span class="ic">'+ico('server',22)+'</span><b>Подмен нет</b>Все пользователи получают хосты как в панели.</div></div>';return;}
 var q=Q.toLowerCase(),L=R.filter(function(o){var s=st(o);if(F==='act'&&s!=='ok'&&s!=='wait')return false;if(F==='wait'&&s!=='wait')return false;if(F==='off'&&s!=='exp'&&s!=='bad')return false;return !q||(o.un+' '+o.su+' '+o.n).toLowerCase().indexOf(q)>-1;});
 h+='<div class="hv-tools"><div class="srch">'+ico('search',16)+'<input type="text" id="hvQ" placeholder="пользователь, shortUuid или хост" aria-label="Поиск" value="'+esc(Q)+'"></div>'+
  '<div class="hv-sg" role="group" aria-label="Фильтр">'+[['all','Все',R.length],['act','Действуют',n.ok+n.wait],['wait','Не уходили',n.wait],['off','Не действуют',off]].map(function(f){return '<button type="button" data-f="'+f[0]+'" class="'+(F===f[0]?'on':'')+'">'+f[1]+'<span class="c">'+f[2]+'</span></button>';}).join('')+'</div>'+
  '<span class="sp"></span><button type="button" class="btn danger" id="hvClr"'+(off?'':' disabled')+'>'+ico('trash',15)+'Удалить недействующие'+(off?' · '+off:'')+'</button></div>';
 h+='<div class="hv-tw2"><table class="hv-tb"><thead><tr><th>Пользователь</th><th>Хост</th><th>Отпечаток TLS</th><th>Срок</th><th class="c-ls">Последняя выдача</th><th></th></tr></thead><tbody>'+
  (L.length?L.map(function(o){var s=st(o),off=s==='exp'||s==='bad',lk='?tab=users&card='+encodeURIComponent(o.su)+'&ct=edit';
   var lx=s==='bad'?'<span class="hv-pill b">'+(o.st==='inv'?'не работает с REALITY':(o.lh&&o.lh>=o.at?'неделю не уходила':'хоста не было в выдаче'))+'</span>'+(o.ma?'<span class="a">'+esc(o.ma)+'</span>':''):(s==='exp'?'<span class="hv-pill m">срок вышел</span>':(s==='wait'?'<span class="hv-pill w">ещё не уходила</span>':'<span>'+ago(o.lh)+'</span>'+(o.la?'<span class="a">'+esc(o.la)+'</span>':'')+(o.st==='part'?'<span class="a">в '+esc(o.ma||'другом приложении')+' хоста нет</span>':'')));
   return '<tr class="'+(off?'off':'')+'"><td class="c-us"><a class="hv-un" href="'+lk+'" data-tip="Открыть карточку"><span class="n">'+esc(o.un||o.su)+'</span><span class="s">'+esc(o.su)+'</span></a></td>'+
   '<td class="c-hs" data-l="Хост"><span class="hv-hn">'+esc(o.n)+'</span></td>'+
   '<td class="c-vl" data-l="Отпечаток TLS"><span class="hv-nv">'+(o.pv?'<span class="hv-was" data-tip="Сейчас в панели">'+esc(o.pv)+'</span><span class="hv-ar">'+ico('arrr',14)+'</span>':'')+'<b>'+esc(o.v)+'</b></span></td>'+
   '<td class="c-tl" data-l="Срок"><span class="tl">'+(o.till?(o.till<=now()?'истёк '+fdt(o.till):'до '+fdt(o.till)+'<span class="r">'+left(o.till)+'</span>'):'<span class="muted">без срока</span>')+'</span></td>'+
   '<td class="c-ls" data-l="Последняя выдача"><span class="lst">'+lx+'</span></td>'+
   '<td class="c-ac"><span class="acts"><a class="hv-ib" href="'+lk+'" data-tip="Открыть карточку пользователя" aria-label="Открыть карточку">'+ico('pencil')+'</a><button type="button" class="hv-ib bad" data-del="'+o.id+'" data-tip="Снять подмену" aria-label="Снять подмену">'+ico('trash')+'</button></span></td></tr>';}).join(''):'<tr><td colspan="6" class="muted" style="text-align:center;padding:1.4rem">Ничего не найдено</td></tr>')+
  '</tbody></table></div><div class="hv-ft">Всего подмен: '+R.length+'<span class="sp"></span>Последняя выдача — когда приложение пользователя в последний раз получило подписку с этой подменой.</div></div>';
 box.innerHTML=h;
}
function post(a,d){var f=new FormData();f.append('csrf',CSRF);Object.keys(d).forEach(function(k){f.append(k,d[k]);});return fetch('?ajax='+a,{method:'POST',body:f,credentials:'same-origin'}).then(function(r){return r.json();}).catch(function(){return{ok:false,error:'Сетевая ошибка'};});}
function done(d,msg){if(!d||!d.ok||!Array.isArray(d.all)){if(window.uiAlert)uiAlert((d&&d.error)||'Ошибка','Не получилось');return;}R=d.all;draw();if(msg&&window.uiToast)uiToast(msg);}
var box=document.getElementById('hvTab');
box.addEventListener('click',function(e){var t=e.target,b;
 if((b=t.closest('[data-f]'))){F=b.getAttribute('data-f');draw();return;}
 if((b=t.closest('[data-del]'))){post('hv_del',{id:b.getAttribute('data-del'),all:'1'}).then(function(d){done(d,'Подмена снята — хост снова как в панели');});return;}
 if(t.closest('#hvClr')&&!t.closest('#hvClr').disabled){var go=function(){post('hv_clear',{}).then(function(d){done(d,'Недействующие подмены удалены');});};if(window.uiConfirm)uiConfirm('Удалить подмены, у которых вышел срок или которые не срабатывают?',go,'Удалить',true);else go();}
});
box.addEventListener('input',function(e){if(e.target.id!=='hvQ')return;Q=e.target.value;var p=e.target.selectionStart;draw();var q=document.getElementById('hvQ');q.focus();q.setSelectionRange(p,p);});
draw();
})();
    </script>
