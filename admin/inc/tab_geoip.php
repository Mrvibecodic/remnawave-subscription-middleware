<?php $gi_ip = function_exists('geoip_ipinfo') && geoip_ipinfo(); $gi_v = $gi_ip ? geonet_view() : null; $gi_st = $gi_ip ? [] : geoip_status(); ?>
<style>
.gi-grid{display:grid;grid-template-columns:1fr 1fr;gap:.55rem;margin-top:.7rem}
.gi-grid .set-row{margin:0}
.gi-grid .set-row>select{flex:0 1 auto;min-width:0;max-width:19rem}
.gi-grid .set-row>input[type=number]{width:7.5rem;flex:0 0 auto}
@media(max-width:1000px){.gi-grid{grid-template-columns:1fr}}
.gi-tbl{table-layout:fixed;width:100%;min-width:0}
.gi-tbl td{min-width:0;overflow-wrap:anywhere}
.gi-tbl td:first-child{width:2.2rem}
.gi-tbl code{white-space:normal;overflow-wrap:anywhere;word-break:break-all}
.gi-tok{display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;margin-top:.55rem}
.gi-tok input{flex:1 1 14rem;min-width:0;max-width:24rem}
.gi-tiles{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:.55rem;margin-top:.7rem}
.gi-tiles>div{background:var(--bg2);border:1px solid var(--line);border-radius:var(--radius);padding:.6rem .75rem;min-width:0}
.gi-tiles .k{font-size:.74rem;color:var(--muted)}
.gi-tiles .v{font-size:1.25rem;font-weight:600;color:var(--text-strong);font-variant-numeric:tabular-nums}
.gi-tiles .v small{font-size:.8rem;font-weight:500;color:var(--muted)}
.gi-tiles .d{font-size:.74rem;color:var(--muted);overflow-wrap:anywhere}
.gi-bar{height:8px;border-radius:99px;background:var(--line);overflow:hidden;margin:.45rem 0 .25rem}
.gi-bar>i{display:block;height:100%;background:var(--accent);transition:width .4s}
.gi-bar.is-w>i{background:var(--amber)}
.gi-bar.is-b>i{background:var(--red)}
.gi-ln{font-size:.82rem;color:var(--muted);margin-top:.45rem;overflow-wrap:anywhere}
.gi-ln b{color:var(--text)}
.gi-st{display:inline-block;font-size:.74rem;font-weight:600;padding:.12rem .5rem;border-radius:99px;margin-left:.4rem;vertical-align:middle}
.gi-st.is-ok{background:var(--c-ok-bg);color:var(--c-ok-fg)}
.gi-st.is-w{background:var(--c-warn-bg);color:var(--c-warn-fg)}
.gi-st.is-b{background:var(--c-bad-bg);color:var(--c-bad-fg)}
.gi-act{display:flex;gap:.5rem;flex-wrap:wrap;margin-top:.7rem}
@media(max-width:900px){.gi-tiles{grid-template-columns:repeat(2,minmax(0,1fr))}}
</style>
<div class="card">
    <h2 style="margin-top:0;font-size:1rem">Геобазы</h2>
    <p class="muted">По ним прослойка определяет страну, провайдера, регион и город адреса: в сессиях и аномалиях пользователей и в статистике Clod Clash.</p>
    <form method="post" data-autosave>
        <input type="hidden" name="csrf" value="<?= h($token) ?>">
        <input type="hidden" name="action" value="save_geoip_mode">
        <div class="gi-grid">
        <div class="set-row">
            <div class="set-info"><div class="set-t">Источник</div><div class="set-d">ipinfo.io точнее определяет регион и город. Локальные базы работают без запросов наружу, но регионы у них заметно хуже.</div></div>
            <select name="geoip_mode" data-reload><?php foreach (geoip_modes() as $gm_k => $gm_l): ?><option value="<?= h($gm_k) ?>" <?= geoip_mode() === $gm_k ? 'selected' : '' ?>><?= h($gm_l) ?></option><?php endforeach; ?></select>
        </div>
        <?php if ($gi_ip): ?>
        <div class="set-row">
            <div class="set-info"><div class="set-t">Запросов в сутки</div><div class="set-d">Без токена ipinfo отвечает примерно на 1000 запросов в сутки с IP сервера — по умолчанию 900, с запасом. Один запрос — одна подсеть (/24 для IPv4, /48 для IPv6).</div></div>
            <input type="number" name="ipinfo_cap" min="10" max="<?= (int) ipinfo_cap_max() ?>" value="<?= (int) ipinfo_cap() ?>">
        </div>
        <?php endif; ?>
        </div>
    </form>
<?php if ($gi_ip): ?>
    <form method="post" class="gi-tok">
        <input type="hidden" name="csrf" value="<?= h($token) ?>">
        <input type="hidden" name="action" value="save_ipinfo_token">
        <input type="password" name="ipinfo_token" autocomplete="off" placeholder="<?= ipinfo_token() !== '' ? 'Токен задан — введите новый, чтобы заменить' : 'Токен ipinfo (необязательно)' ?>">
        <button type="submit" class="btn ghost">Сохранить токен</button>
        <?php if (ipinfo_token() !== ''): ?><button type="submit" class="btn ghost" name="del" value="1">Удалить токен</button><?php endif; ?>
    </form>
    <p class="muted" style="font-size:.78rem;margin:.4rem 0 0">Токен не нужен: без него ipinfo отдаёт и регион, и город. С бесплатным токеном IPinfo Lite регион и город могут пропасть — его не вводите. Токен имеет смысл со старым бесплатным тарифом (50 тыс. запросов в месяц) или с платным тарифом с городами; лимит в сутки тогда выставьте по своему тарифу.</p>
</div>

<div class="card" id="giLive"></div>
<p class="muted" style="font-size:.78rem">Наружу уходит по одному адресу на подсеть: <a href="https://ipinfo.io" target="_blank" rel="noopener">IP address data powered by IPinfo</a>. Ответы хранятся в прослойке и не запрашиваются повторно, пока подсеть не встретится снова через 30 дней.</p>
<script>
(function(){
var V=<?= json_encode($gi_v, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?>,CSRF=<?= json_encode((string) $token) ?>,box=document.getElementById('giLive');
function esc(s){return String(s==null?'':s).replace(/[&<>"']/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];});}
function num(n){return Number(n||0).toLocaleString('ru-RU');}
function plural(n,a,b,c){n=Math.abs(n)%100;var m=n%10;if(n>10&&n<20)return c;if(m>1&&m<5)return b;if(m===1)return a;return c;}
function dur(s){s=Math.max(0,Math.round(s));if(s<60)return s+' с';var m=Math.round(s/60);if(m<60)return m+' мин';var h=Math.floor(m/60);m=m%60;if(h<48)return h+' ч'+(m?' '+m+' мин':'');var d=Math.round(h/24);return d+' '+plural(d,'день','дня','дней');}
function ago(s){return s<0?'':(s<5?'только что':dur(s)+' назад');}
function bar(p,cls){p=Math.max(0,Math.min(100,p));return '<div class="gi-bar'+(cls?' '+cls:'')+'"><i style="width:'+p.toFixed(1)+'%"></i></div>';}
function draw(){
 var v=V,h='',q=v.q||{},qa=(q['1']||0)+(q['2']||0)+(q['3']||0),pct=v.cap>0?v.used/v.cap*100:0,st;
 if(v.block>0)st='<span class="gi-st is-b">пауза ещё '+dur(v.block)+'</span>';
 else if(v.used>=v.cap)st='<span class="gi-st is-w">лимит на сутки исчерпан</span>';
 else if(qa>0)st='<span class="gi-st is-ok">'+(v.alive?'идёт':'идёт, пока открыта админка')+'</span>';
 else st='<span class="gi-st is-ok">очередь пуста</span>';
 h+='<h2 style="margin-top:0;font-size:1rem">Запросы к ipinfo'+st+'</h2>';
 if(!v.alive)h+='<div class="warn" style="margin:.5rem 0">Планировщик не запущен — очередь разбирается только пока открыта админка, понемногу. Строки для cron — на <a href="?tab=sysinfo">«О системе»</a>, блок «Планировщик».</div>';
 h+='<div class="gi-tiles">';
 h+='<div><div class="k">Сегодня</div><div class="v">'+num(v.used)+' <small>из '+num(v.cap)+'</small></div>'+bar(pct,pct>=100?'is-b':(pct>=80?'is-w':''))+'<div class="d">сброс через '+dur(v.reset)+' (полночь UTC)</div></div>';
 h+='<div><div class="k">Успешно</div><div class="v">'+num(v.ok)+'</div><div class="d">'+(v.ok_ago>=0?'последний ответ '+ago(v.ok_ago):'ответов ещё не было')+'</div></div>';
 h+='<div><div class="k">Ошибок</div><div class="v">'+num(v.fail)+'</div><div class="d">'+(v.err&&v.err_ago>=0?esc(v.err)+' · '+ago(v.err_ago):'—')+'</div></div>';
 h+='<div><div class="k">Отказов по лимиту</div><div class="v">'+num(v.lim)+'</div><div class="d">'+(v.lim_ago>=0?'последний '+ago(v.lim_ago):'ipinfo не отказывал')+'</div></div>';
 h+='</div>';
 h+='<div class="gi-ln">В очереди <b>'+num(qa)+'</b> '+plural(qa,'подсеть','подсети','подсетей')+(qa?' — сессии '+num(q['1']||0)+' · статистика '+num(q['2']||0)+' · перепроверка '+num(q['3']||0):'')+' · в кэше <b>'+num(v.cached)+'</b></div>';
 if(v.block>0)h+='<div class="gi-ln">ipinfo '+(v.lim_ago>=0&&v.lim_ago<3700?'отказал по лимиту':(v.err?'не ответил или отказал':'не ответил'))+' — следующая попытка через '+dur(v.block)+'.</div>';
 h+='<h2 style="font-size:1rem;margin:1.1rem 0 0">Перепроверка всех IP</h2>';
 h+='<p class="muted" style="font-size:.82rem;margin:.3rem 0 0">Заново спрашивает ipinfo обо всех подсетях из сессий и статистики. Идёт в пределах суточного лимита, после свежих адресов сессий и статистики. Регион, город и провайдер обновляются в уже записанных сессиях и отчётах; почасовые сводки «Статистики» — после «Пересобрать статистику».</p>';
 var rc=v.rc,running=rc&&rc.left>0;
 if(rc){
  var rp=rc.total>0?rc.done/rc.total*100:100;
  h+=bar(rp,'');
  if(running)h+='<div class="gi-ln">Перепроверено <b>'+num(rc.done)+'</b> из <b>'+num(rc.total)+'</b> · осталось '+num(rc.left)+' · '+(rc.days>0?'ещё примерно '+rc.days+' '+plural(rc.days,'сутки','суток','суток')+' при текущем лимите':'должна закончиться сегодня')+' · запущена '+ago(rc.ago)+(rc.auto?' (сама, после обновления)':'')+'</div>';
  else h+='<div class="gi-ln">Перепроверка завершена'+(rc.end_ago>=0?' '+ago(rc.end_ago):'')+': '+num(rc.total)+' '+plural(rc.total,'подсеть','подсети','подсетей')+'.</div>';
 }
 h+='<div class="gi-act"><form method="post" style="margin:0"><input type="hidden" name="csrf" value="'+esc(CSRF)+'"><input type="hidden" name="action" value="geo_recheck"><button type="submit" class="btn'+(running?' ghost':'')+'">'+(running?'Начать заново':'Перепроверить все IP')+'</button></form>';
 if(running)h+='<form method="post" style="margin:0"><input type="hidden" name="csrf" value="'+esc(CSRF)+'"><input type="hidden" name="action" value="geo_recheck_stop"><button type="submit" class="btn ghost">Остановить</button></form>';
 h+='</div>';
 box.innerHTML=h;
}
draw();
var busy=false;
function poll(){
 if(busy||document.hidden)return;busy=true;
 var fd=new FormData();fd.append('csrf',CSRF);
 fetch('?ajax=geo_stat',{method:'POST',body:fd,credentials:'same-origin'}).then(function(r){return r.json();}).then(function(j){busy=false;if(j&&j.ok&&j.v){V=j.v;draw();}}).catch(function(){busy=false;});
}
setInterval(poll,<?= !empty($gi_v['alive']) ? 15000 : 8000 ?>);
document.addEventListener('visibilitychange',function(){if(!document.hidden)poll();});
})();
</script>
<?php else: ?>
    <form method="post" data-autosave style="margin-top:.6rem">
        <input type="hidden" name="csrf" value="<?= h($token) ?>">
        <input type="hidden" name="action" value="save_geoip">
        <div class="gi-grid">
        <div class="set-row">
            <div class="set-info"><div class="set-t">Обновлять базы сами</div><div class="set-d">С зеркала ip-location-db — раз в неделю, DB-IP — раз в месяц. Выключите, если кладёте свои файлы.</div></div>
            <label class="switch"><input type="checkbox" name="geoip_auto" <?= geoip_auto() ? 'checked' : '' ?>><span class="sl"></span></label>
        </div>
        <div class="set-row">
            <div class="set-info"><div class="set-t">База городов</div><div class="set-d">Регион и координаты адреса — для регионов в сессиях и карты в статистике. GeoLite2 — около 45 МБ, DB-IP — 60 МБ скачивания и 130 на диске. Выключение удаляет файлы.</div></div>
            <label class="switch"><input type="checkbox" name="geoip_city" <?= geoip_city_on() ? 'checked' : '' ?>><span class="sl"></span></label>
        </div>
        <div class="set-row">
            <div class="set-info"><div class="set-t">База стран и регионов</div><div class="set-d">GeoLite2 с зеркала ip-location-db точнее по регионам, обновляется дважды в неделю. DB-IP Lite — раз в месяц. Смена источника перекачивает базы.</div></div>
            <select name="geoip_geo_src"><?php foreach (geoip_geo_srcs() as $gs_k => $gs_l): ?><option value="<?= h($gs_k) ?>" <?= geoip_geo_src() === $gs_k ? 'selected' : '' ?>><?= h($gs_l) ?></option><?php endforeach; ?></select>
        </div>
        <div class="set-row">
            <div class="set-info"><div class="set-t">База провайдеров (AS)</div><div class="set-d">origin-asn собрана из данных регистраторов и BGP, обновляется ежедневно, без регистрации и условий. DB-IP ASN Lite — раз в месяц, имена провайдеров беднее.</div></div>
            <select name="geoip_asn_src"><?php foreach (geoip_asn_srcs() as $gs_k => $gs_l): ?><option value="<?= h($gs_k) ?>" <?= geoip_asn_src() === $gs_k ? 'selected' : '' ?>><?= h($gs_l) ?></option><?php endforeach; ?></select>
        </div>
        </div>
    </form>
    <table class="logtbl gi-tbl" style="margin-top:1rem">
        <tbody>
        <?php foreach (['country' => 'Страна', 'asn' => 'Автономная система (провайдер)', 'city' => 'Города и координаты (регионы)', 'city6' => 'Города и координаты, IPv6'] as $gk => $gt): $g = $gi_st[$gk]; if (($gk === 'city' && !geoip_city_on() && !$g['ok']) || ($gk === 'city6' && !(geoip_city_on() && geoip_geo_src() !== 'dbip') && !$g['ok'])) continue; ?>
        <tr><td><?= $g['ok'] ? '✅' : '⚠️' ?></td>
            <td><?= h($gt) ?> — <?php if ($g['ok']): ?><code><?= h($g['type']) ?></code>, сборка <?= $g['built'] > 0 ? h(gmdate('Y-m-d', (int) $g['built'])) : '—' ?><?php else: ?>базы нет<?= is_file($g['path']) ? ', файл не читается' : '' ?><?php endif; ?>
                <div class="muted" style="font-size:.78rem"><code><?= h($g['path']) ?></code></div></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p class="muted" style="font-size:.8rem;margin-top:.6rem">Без баз сессии и отчёты всё равно пишутся, но страна, провайдер и регион у этих записей останутся пустыми. Свои файлы MaxMind DB (например, GeoLite2-Country, GeoLite2-ASN и GeoLite2-City) кладите по путям выше под теми же именами. Базы — с зеркала <a href="https://github.com/sapics/ip-location-db" target="_blank" rel="noopener">ip-location-db</a>: страна и города — GeoLite2 (данные <a href="https://www.maxmind.com" target="_blank" rel="noopener">MaxMind</a>), провайдеры — origin-asn (PDDL); запасной источник — <a href="https://db-ip.com" target="_blank" rel="noopener">IP Geolocation by DB-IP</a>, CC BY 4.0.</p>
    <form method="post" style="margin-top:.6rem">
        <input type="hidden" name="csrf" value="<?= h($token) ?>">
        <input type="hidden" name="action" value="geoip_update">
        <button type="submit" class="btn ghost">↻ Скачать базы сейчас</button>
    </form>
</div>
<?php endif; ?>
