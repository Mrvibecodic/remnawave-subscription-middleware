<?php
$bk_miss   = backup_missing();
$bk_crypto = backup_crypto_ok();
$bk_direrr = '';
$bk_dirok  = backup_dir_ready($bk_direrr) !== '';
$bk_list   = backup_list();
$bk_kinds  = backup_kinds();
$bk_tagcls = ['manual' => 'manual', 'auto' => 'normal', 'pre' => 'grace', 'upload' => 'expired'];
$bk_lim    = backup_upload_limit();
$bk_auto   = backup_auto_on();
$bk_apass  = backup_auto_pass() !== '';
$bk_cst    = function_exists('cron_state') ? cron_state() : [];
$bk_alive  = function_exists('cron_alive') && cron_alive();
$bk_nofpm  = ($bk_cst['src'] ?? '') === 'url' && !function_exists('fastcgi_finish_request');
$bk_lastauto = 0;
foreach ($bk_list as $bk_b) { if ($bk_b['kind'] === 'auto') { $bk_lastauto = $bk_b['ts']; break; } }
$bk_logt = [];
foreach (gc_tables() as $bk_t) $bk_logt[] = $bk_t['title'];
$bk_ic = [
    'restore' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>',
    'dl'      => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 15V3"/><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5"/></svg>',
    'del'     => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>',
    'lock'    => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>',
    'up'      => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3v12"/><path d="m17 8-5-5-5 5"/><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/></svg>',
    'warn'    => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>',
];
$bk_user = function_exists('update_web_user') ? update_web_user() : '';
$bk_open = (string) ($_GET['open'] ?? '');
if (backup_path($bk_open) === '') $bk_open = '';
?>
<style>
.bk-opts{display:flex;gap:.6rem 1.4rem;flex-wrap:wrap;align-items:center;margin-top:.9rem}
.bk-opts .chk{margin:0}
.bk-pw{flex:1 1 16rem;min-width:0;max-width:24rem;margin:0}
.bk-note{display:flex;gap:.5rem;align-items:flex-start;font-size:.82rem;margin-top:.6rem;color:var(--c-warn-fg)}
.bk-note.ok{color:var(--muted)}
.bk-note svg{flex:none;width:16px;height:16px;margin-top:.12rem}
.bk-act{display:flex;gap:.5rem;flex-wrap:wrap;margin-top:.9rem;align-items:center}
.bk-act .muted{font-size:.8rem}
.bk-prog{margin-top:.9rem}
.bk-bar{height:8px;border-radius:99px;background:var(--line);overflow:hidden}
.bk-bar>i{display:block;height:100%;width:0;background:var(--accent);transition:width .3s}
.bk-bar.ind>i{width:30%;animation:bkInd 1.2s ease-in-out infinite}
@keyframes bkInd{0%{transform:translateX(-110%)}100%{transform:translateX(340%)}}
.bk-pt{font-size:.82rem;color:var(--muted);margin-top:.4rem;font-variant-numeric:tabular-nums;overflow-wrap:anywhere}
.bk-err{color:var(--c-bad-fg);font-size:.84rem;margin-top:.5rem;overflow-wrap:anywhere}
.bk-err:empty{display:none}
.bk-tbl td.n,.bk-tbl th.n{text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums}
.bk-tbl td.a{text-align:right;white-space:nowrap;width:1%}
.bk-tbl tr.hl>td{background:var(--accent-light)}
.bk-lock{display:inline-flex;vertical-align:-2px;margin-left:.4rem;color:var(--muted)}
.bk-ib,.bk-ib:hover,.bk-ib:active{all:unset;cursor:pointer;width:32px;height:32px;display:inline-grid;place-items:center;border-radius:8px;color:var(--muted);box-sizing:border-box;vertical-align:middle}
.bk-ib:hover,.bk-ib:focus-visible{background:var(--hover);color:var(--text-strong)}
.bk-ib.del:hover{color:var(--c-bad-fg)}
.bk-ib svg{width:16px;height:16px}
.bk-tbl form{display:inline}
.bk-empty{padding:.9rem 0 .2rem;color:var(--muted);font-size:.88rem}
.bk-foot{display:flex;gap:.6rem 1rem;flex-wrap:wrap;align-items:center;margin-top:1rem}
.bk-foot .muted{font-size:.8rem;flex:1 1 20rem;min-width:0}
.bk-foot .btn svg{width:16px;height:16px;vertical-align:-3px;margin-right:.35rem}
.bk-box{display:flex;gap:.55rem;align-items:flex-start;padding:.6rem .8rem;border-radius:var(--radius);font-size:.84rem;margin-top:.8rem;line-height:1.45}
.bk-box svg{flex:none;width:16px;height:16px;margin-top:.12rem}
.bk-box.amber{background:var(--c-warn-bg);color:var(--c-warn-fg)}
.bk-box.red{background:var(--c-bad-bg);color:var(--c-bad-fg)}
.bk-box.info{background:var(--bg2);color:var(--muted);border:1px solid var(--line)}
.bk-tok{display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;margin-top:.7rem}
.bk-tok input{flex:1 1 14rem;min-width:0;max-width:24rem;margin:0}
#bkDlg .modal{max-width:520px}
.bk-kv{display:flex;flex-direction:column;gap:.5rem}
.bk-kv>div{display:flex;gap:.65rem;align-items:flex-start;font-size:.88rem}
.bk-kv svg{flex:none;width:18px;height:18px;color:var(--accent-text);margin-top:.05rem}
.bk-kv .k{color:var(--muted);flex:0 0 11.5rem}
.bk-kv .v{color:var(--text-strong);font-weight:500;min-width:0;overflow-wrap:anywhere}
.bk-kv .v small{color:var(--muted);font-weight:400;font-size:.82rem}
.bk-chks{display:flex;flex-direction:column;gap:.45rem;margin-top:.9rem}
.bk-chks .chk{margin:0}
.bk-word{margin-top:.9rem}
.bk-word label{display:block;font-size:.84rem;color:var(--muted);margin-bottom:.35rem}
.bk-word input{width:100%;margin:0}
#bkGo:disabled{opacity:.45;cursor:default;filter:none;transform:none}
.bk-res{display:flex;gap:.75rem;align-items:flex-start}
.bk-res>svg{flex:none;width:28px;height:28px}
.bk-res.ok>svg{color:var(--c-ok-fg)}
.bk-res.bad>svg{color:var(--c-bad-fg)}
.bk-res.lk>svg{color:var(--accent-text)}
.bk-res b{color:var(--text-strong)}
.bk-res .muted{margin-top:.2rem}
.bk-pf{display:flex;gap:.5rem;flex-wrap:wrap;margin-top:.9rem}
.bk-pf input{flex:1 1 12rem;min-width:0;margin:0}
@media(max-width:600px){.bk-kv>div{flex-wrap:wrap}.bk-kv .k{flex:1 1 auto}.bk-kv .v{flex:1 1 100%;padding-left:calc(18px + .65rem)}}
</style>
<?php if ($bk_miss): ?>
<div class="card"><div class="bk-box red"><?= $bk_ic['warn'] ?><span>На сервере нет расширения PHP <b><?= h(implode(', ', $bk_miss)) ?></b>, без него бэкап не работает.</span></div></div>
<?php else: ?>
<?php if (!$bk_dirok): ?>
<div class="card"><div class="bk-box red"><?= $bk_ic['warn'] ?><span>Бэкапы негде хранить. <?= h($bk_direrr) ?>.</span></div></div>
<?php endif; ?>
<div class="card" id="bkCreate">
    <h2 style="margin-top:0;font-size:1rem">Создать бэкап</h2>
    <p class="muted">В бэкап попадают все настройки и данные прослойки, а также логин и пароль админки. Подключение к базе данных в него не входит: при восстановлении всё загрузится в ту базу, к которой прослойка подключена сейчас.</p>
    <div class="bk-opts">
        <?php if ($bk_crypto): ?><input type="password" class="bk-pw" id="bkPw" autocomplete="off" placeholder="Пароль на файл — по желанию" aria-label="Пароль на файл"><?php endif; ?>
        <label class="chk" data-tip="<?= h('Не сохранять: ' . mb_strtolower(implode(', ', $bk_logt)) . '. Файл будет намного меньше.') ?>"><input type="checkbox" id="bkLite"> Без журналов и статистики</label>
    </div>
    <div class="bk-note" id="bkPwNote"><?= $bk_ic['warn'] ?><span>Без пароля файл может прочитать любой, у кого он окажется, а в нём токен панели и другие секреты. Храните его надёжно<?= $bk_crypto ? ' или задайте пароль' : '' ?>.</span></div>
    <div class="bk-act">
        <button type="button" class="btn" data-go="save">Сохранить на сервере</button>
        <button type="button" class="btn ghost" data-go="dl" data-tip="Файл сразу скачается и не останется на сервере">Скачать</button>
    </div>
    <div class="bk-prog" id="bkProg" hidden></div>
</div>

<div class="card">
    <div class="loghead">
        <h2>Бэкапы на сервере<?= $bk_list ? ' (' . count($bk_list) . ')' : '' ?></h2>
        <div class="loghead-r"><div id="bk_pgrTop" class="pgr"></div></div>
    </div>
    <?php if (!$bk_list): ?>
    <div class="bk-empty">Сохранённых бэкапов пока нет.</div>
    <?php else: ?>
    <table class="logtbl bk-tbl">
        <thead><tr><th>Создан</th><th>Тип</th><th class="n">Размер</th><th></th></tr></thead>
        <tbody id="bkList" class="lp-cap">
        <?php foreach ($bk_list as $bk_b): $bk_dl = backup_dl_url($bk_b['name'], $token); ?>
            <tr data-name="<?= h($bk_b['name']) ?>" data-enc="<?= $bk_b['enc'] ? '1' : '0' ?>">
                <td><span class="bk-ts" data-ts="<?= (int) $bk_b['ts'] ?>"><?= h(gmdate('Y-m-d H:i', (int) $bk_b['ts'])) ?> UTC</span><?php if ($bk_b['enc']): ?><span class="bk-lock" role="img" aria-label="С паролем" data-tip="Файл защищён паролем"><?= $bk_ic['lock'] ?></span><?php endif; ?></td>
                <td><span class="tag <?= h($bk_tagcls[$bk_b['kind']] ?? 'manual') ?>"><?= h($bk_kinds[$bk_b['kind']] ?? $bk_b['kind']) ?></span></td>
                <td class="n"><?= h(metrics_fmt_bytes($bk_b['size'])) ?></td>
                <td class="a">
                    <button type="button" class="bk-ib" data-bk-restore data-tip="Восстановить" aria-label="Восстановить"><?= $bk_ic['restore'] ?></button>
                    <a class="bk-ib" href="<?= h($bk_dl) ?>" data-tip="Скачать" aria-label="Скачать"><?= $bk_ic['dl'] ?></a>
                    <form method="post" onsubmit="return uiConfirmForm(this,'Удалить этот бэкап? Файл удалится с сервера насовсем.')">
                        <input type="hidden" name="csrf" value="<?= h($token) ?>">
                        <input type="hidden" name="action" value="bk_delete">
                        <input type="hidden" name="name" value="<?= h($bk_b['name']) ?>">
                        <button type="submit" class="bk-ib del" data-tip="Удалить" aria-label="Удалить"><?= $bk_ic['del'] ?></button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <div id="bk_pgrBot" class="pgr-bot"></div>
    <?php endif; ?>
    <div class="bk-foot">
        <button type="button" class="btn ghost" id="bkUpBtn"><?= $bk_ic['up'] ?>Загрузить файл</button>
        <input type="file" id="bkUpFile" accept=".gz,.enc" hidden>
        <span class="muted"><?php if ($bk_lim < PHP_INT_MAX): ?>Сервер принимает файлы до <?= h(metrics_fmt_bytes($bk_lim)) ?>. <?php endif; ?><?php if (submw_in_docker()): ?>Файл больше можно скопировать в контейнер командой <code>docker cp файл &lt;контейнер&gt;:/var/www/html/data/backups/</code> — он появится в списке.<?php else: ?>Файл больше можно положить на сервер в папку <code><?= h(backup_dir()) ?></code> — он появится в списке. Прослойка работает от пользователя <b><?= h($bk_user) ?></b>, у него должен быть доступ к файлу.<?php endif; ?></span>
    </div>
    <div class="bk-box info">Перед каждым восстановлением прослойка сохраняет снимок текущих данных — из него можно вернуть всё как было. Хранятся 3 последних снимка и 7 последних бэкапов по расписанию, более старые удаляются сами. Сохранённые вручную и загруженные файлы не удаляются.</div>
</div>

<div class="card">
    <h2 style="margin-top:0;font-size:1rem">Бэкап по расписанию</h2>
    <form method="post" data-autosave>
        <input type="hidden" name="csrf" value="<?= h($token) ?>">
        <input type="hidden" name="action" value="save_backup_auto">
        <div class="set-row">
            <div class="set-info"><div class="set-t">Каждый день</div><div class="set-d">Раз в сутки прослойка сама сохраняет бэкап на сервере, хранятся 7 последних.<?php if ($bk_lastauto > 0): ?> Последний — <span class="bk-ts" data-ts="<?= (int) $bk_lastauto ?>"><?= h(gmdate('Y-m-d H:i', $bk_lastauto)) ?> UTC</span>.<?php endif; ?></div></div>
            <label class="switch"><input type="checkbox" name="backup_auto" data-reload <?= $bk_auto ? 'checked' : '' ?>><span class="sl"></span></label>
        </div>
    </form>
    <?php if ($bk_auto && !$bk_alive): ?>
    <div class="bk-box amber"><?= $bk_ic['warn'] ?><span>Планировщик не запущен, поэтому бэкап по расписанию не сработает. Как его включить — на вкладке <a href="?tab=sysinfo">«О системе»</a>.</span></div>
    <?php elseif ($bk_auto && $bk_nofpm): ?>
    <div class="bk-box amber"><?= $bk_ic['warn'] ?><span>Сейчас планировщик запускается по адресу, а без php-fpm ему не хватает времени на бэкап. Запустите планировщик из консоли — инструкция на вкладке <a href="?tab=sysinfo">«О системе»</a>.</span></div>
    <?php endif; ?>
    <?php if ($bk_crypto): ?>
    <form method="post" class="bk-tok">
        <input type="hidden" name="csrf" value="<?= h($token) ?>">
        <input type="hidden" name="action" value="save_backup_pass">
        <input type="password" name="backup_auto_pass" autocomplete="off" aria-label="Пароль для бэкапов по расписанию" placeholder="<?= $bk_apass ? 'Пароль задан — введите новый, чтобы заменить' : 'Пароль на бэкапы по расписанию — по желанию' ?>">
        <button type="submit" class="btn ghost">Сохранить пароль</button>
        <?php if ($bk_apass): ?><button type="submit" class="btn ghost" name="del" value="1">Убрать пароль</button><?php endif; ?>
    </form>
    <?php endif; ?>
</div>

<div id="bkDlg" class="modal-overlay">
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="bkDlgT">
        <div class="modal-head"><span id="bkDlgT">Восстановление</span><button type="button" class="modal-x" id="bkDlgX" aria-label="Закрыть">×</button></div>
        <div class="modal-body" id="bkBody"></div>
    </div>
</div>
<script>
(function(){
var CSRF=<?= json_encode((string) $token) ?>, LIM=<?= $bk_lim >= PHP_INT_MAX ? 0 : (int) $bk_lim ?>, CUR_USER=<?= json_encode((string) ($C['admin_user'] ?? ''), JSON_UNESCAPED_UNICODE) ?>, OPEN=<?= json_encode($bk_open, JSON_UNESCAPED_UNICODE) ?>;
var KINDS=<?= json_encode($bk_kinds + ['dl' => 'скачан'], JSON_UNESCAPED_UNICODE) ?>, DRV={sqlite:'SQLite',mysql:'MySQL / MariaDB'};
var SV=function(p){return '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'+p+'</svg>';};
var IC={
 warn:<?= json_encode($bk_ic['warn']) ?>,
 lock:SV('<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>'),
 cal:SV('<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4"/><path d="M8 2v4"/><path d="M3 10h18"/>'),
 ver:SV('<circle cx="12" cy="12" r="3"/><path d="M3 12h6"/><path d="M15 12h6"/>'),
 db:SV('<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5V19A9 3 0 0 0 21 19V5"/><path d="M3 12A9 3 0 0 0 21 12"/>'),
 tbl:SV('<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18"/><path d="M3 15h18"/><path d="M12 3v18"/>'),
 log:SV('<path d="M15 12h-5"/><path d="M15 8h-5"/><path d="M19 17V5a2 2 0 0 0-2-2H4"/><path d="M8 21h12a2 2 0 0 0 2-2v-1a1 1 0 0 0-1-1H11a1 1 0 0 0-1 1v1a2 2 0 1 1-4 0V5a2 2 0 1 0-4 0v2a1 1 0 0 0 1 1h3"/>'),
 user:SV('<circle cx="12" cy="8" r="5"/><path d="M20 21a8 8 0 0 0-16 0"/>'),
 key:SV('<path d="M2.586 17.414A2 2 0 0 0 2 18.828V21a1 1 0 0 0 1 1h3a1 1 0 0 0 1-1v-1a1 1 0 0 1 1-1h1a1 1 0 0 0 1-1v-1a1 1 0 0 1 1-1h.172a2 2 0 0 0 1.414-.586l.814-.814a6.5 6.5 0 1 0-4-4z"/><circle cx="16.5" cy="7.5" r=".5" fill="currentColor"/>'),
 info:'<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>',
 phone:SV('<rect x="5" y="2" width="14" height="20" rx="2" ry="2"/><path d="M12 18h.01"/>'),
 ok:SV('<circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/>'),
 bad:SV('<circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/>')
};
var STAGE={dump:'Выгружаю данные',snap:'Сохраняю снимок текущих данных',wipe:'Очищаю текущие данные',load:'Загружаю данные из файла',commit:'Завершаю'};
function $(id){return document.getElementById(id);}
function esc(s){return String(s==null?'':s).replace(/[&<>"']/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];});}
function num(n){return Number(n||0).toLocaleString('ru-RU');}
function p2(n){return(n<10?'0':'')+n;}
function dt(t){var d=new Date(t*1000);return p2(d.getDate())+'.'+p2(d.getMonth()+1)+'.'+d.getFullYear()+' '+p2(d.getHours())+':'+p2(d.getMinutes());}
function fmtB(n){var u=['Б','КБ','МБ','ГБ'],i=0;while(n>=1024&&i<u.length-1){n/=1024;i++;}return(i?n.toFixed(n>=100?0:1):n)+' '+u[i];}
function short(v){v=String(v||'');return /^[0-9a-f]{40}$/.test(v)?v.slice(0,7):v;}
function loc(){document.querySelectorAll('.bk-ts[data-ts]').forEach(function(e){e.textContent=dt(+e.getAttribute('data-ts'));});}
loc();

function stream(url,fd,onp){
    return fetch(url,{method:'POST',credentials:'same-origin',body:fd}).then(function(r){
        var fin=null,buf='';
        function eat(txt){buf+=txt;var i;while((i=buf.indexOf('\n'))>=0){var l=buf.slice(0,i);buf=buf.slice(i+1);if(!l)continue;var o;try{o=JSON.parse(l);}catch(e){continue;}if(o&&('ok' in o))fin=o;else if(o)onp(o);}}
        if(r.body&&r.body.getReader&&window.TextDecoder){
            var rd=r.body.getReader(),dec=new TextDecoder();
            var pump=function(){return rd.read().then(function(x){if(x.done){eat(dec.decode()+'\n');return fin;}eat(dec.decode(x.value,{stream:true}));return pump();});};
            return pump();
        }
        return r.text().then(function(t){eat(t+'\n');return fin;});
    }).then(function(f){return f||{ok:false,lost:true,error:'Сервер оборвал ответ'};},function(){return{ok:false,lost:true,error:'Связь с сервером прервалась'};});
}
function progHtml(){return '<div class="bk-bar ind"><i></i></div><div class="bk-pt">Начинаю…</div>';}
function progSet(box,o){
    var bar=box.querySelector('.bk-bar'),t=box.querySelector('.bk-pt');if(!bar||!t)return;
    var s=STAGE[o.p]||'Работаю';
    if(o.n>0){bar.classList.remove('ind');bar.firstElementChild.style.width=Math.min(100,o.d/o.n*100).toFixed(1)+'%';t.textContent=s+': '+num(o.d)+' из '+num(o.n)+' записей';}
    else{bar.classList.add('ind');bar.firstElementChild.style.width='';t.textContent=s+(o.t?' · '+o.t:'')+'…';}
}

var cr=$('bkCreate');
if(cr){
    var pw=$('bkPw'),note=$('bkPwNote'),go=cr.querySelectorAll('button[data-go]');
    var pwNote=function(){var on=pw&&pw.value!=='';note.className='bk-note'+(on?' ok':'');note.innerHTML=on?IC.lock.replace('width="18" height="18"','width="16" height="16"')+'<span>Запомните пароль: без него файл не восстановить, а прослойка его не хранит.</span>':IC.warn+'<span>Без пароля файл может прочитать любой, у кого он окажется, а в нём токен панели и другие секреты. Храните его надёжно или задайте пароль.</span>';};
    if(pw)pw.addEventListener('input',pwNote);
    var run=function(dl){
        var fd=new FormData();fd.append('csrf',CSRF);fd.append('lite',$('bkLite').checked?'1':'0');if(pw)fd.append('pass',pw.value);if(dl)fd.append('dl','1');
        var box=$('bkProg');box.hidden=false;box.innerHTML=progHtml();
        go.forEach(function(b){b.disabled=true;});
        stream('index.php?ajax=bk_create',fd,function(o){progSet(box,o);}).then(function(r){
            go.forEach(function(b){b.disabled=false;});
            if(!r.ok){box.innerHTML='<div class="bk-err">Бэкап не создан. '+esc(r.error||'')+'</div>';return;}
            if(dl){box.hidden=true;box.innerHTML='';if(pw){pw.value='';pwNote();}location.href=r.dl;if(window.uiToast)uiToast('Бэкап готов, скачивание началось');return;}
            location.href='index.php?tab=backup&made='+encodeURIComponent(r.name);
        });
    };
    cr.addEventListener('click',function(e){var b=e.target.closest&&e.target.closest('button[data-go]');if(b&&!b.disabled)run(b.getAttribute('data-go')==='dl');});
}

var dlg=$('bkDlg'),body=$('bkBody'),busy=false,cur=null;
function close(){if(busy)return;dlg.classList.remove('open');cur=null;body.innerHTML='';}
$('bkDlgX').addEventListener('click',close);
dlg.addEventListener('click',function(e){if(e.target===dlg)close();});
document.addEventListener('keydown',function(e){if(e.key==='Escape'&&dlg.classList.contains('open'))close();});
function resHtml(cls,icon,title,text){return '<div class="bk-res '+cls+'">'+icon+'<div><b>'+esc(title)+'</b>'+(text?'<div class="muted">'+text+'</div>':'')+'</div></div>';}
function fail(msg){body.innerHTML=resHtml('bad',IC.bad,'Не получилось',esc(msg))+'<div class="dlg-actions"><button type="button" class="btn ghost" data-x>Закрыть</button></div>';}
function askPass(err){
    body.innerHTML=resHtml('lk',IC.lock,'Файл защищён паролем','Введите пароль, который задали при создании бэкапа.')
        +'<form class="bk-pf" id="bkPf"><input type="password" id="bkPp" autocomplete="off" aria-label="Пароль от файла" required><button type="submit" class="btn">Открыть</button></form><div class="bk-err">'+esc(err||'')+'</div>';
    $('bkPp').focus();
    $('bkPf').addEventListener('submit',function(e){e.preventDefault();cur.pass=$('bkPp').value;preview();});
}
function preview(){
    body.innerHTML='<div class="bk-bar ind"><i></i></div><div class="bk-pt">Читаю файл…</div>';
    var fd=new FormData();fd.append('csrf',CSRF);fd.append('name',cur.name);fd.append('pass',cur.pass);
    fetch('index.php?ajax=bk_preview',{method:'POST',credentials:'same-origin',body:fd}).then(function(r){return r.json();}).then(function(d){
        if(!cur)return;
        if(d&&d.ok&&d.need_pass){askPass('');return;}
        if(!d||!d.ok){if(cur.pass!=='')askPass(d&&d.error);else fail(d&&d.error||'Файл не удалось прочитать');return;}
        summary(d);
    }).catch(function(){if(cur)fail('Ошибка сети');});
}
function kv(ic,k,v){return '<div>'+ic+'<span class="k">'+esc(k)+'</span><span class="v">'+v+'</span></div>';}
function summary(d){
    var who=!d.user?'нет в файле <small>· останется текущий</small>':(esc(d.user)+' <small>· '+(d.same_login?'как сейчас':(d.user===CUR_USER?'пароль другой':'логин другой'))+'</small>');
    var yn=function(v){return v?'включён':'выключен';};
    var h='<div class="bk-kv">'
        +kv(IC.cal,'Сделан',esc(dt(d.ts))+(KINDS[d.kind]?' <small>· '+esc(KINDS[d.kind])+'</small>':''))
        +kv(IC.ver,'Версия прослойки',esc(short(d.ver)||'неизвестна')+(d.cur_ver&&d.ver&&d.ver!==d.cur_ver?' <small>· сейчас '+esc(short(d.cur_ver))+'</small>':''))
        +kv(IC.db,'База',esc(DRV[d.drv]||d.drv)+(d.drv!==d.cur_drv?' <small>→ '+esc(DRV[d.cur_drv]||d.cur_drv)+'</small>':''))
        +kv(IC.tbl,'Данные',num(d.rows)+' записей <small>· таблиц: '+num(d.tables)+'</small>')
        +kv(IC.log,'Журналы и статистика',d.logs?num(d.log_rows)+' записей':'нет в файле')
        +kv(IC.user,'Вход в админку',who)
        +(d.gate!==null&&(d.gate||d.gate_now)?kv(IC.key,'Вход по секретной ссылке',yn(d.gate)+(d.gate!==d.gate_now?' <small>· сейчас '+yn(d.gate_now)+'</small>':'')):'')
        +(d.phones||d.phones_now?kv(IC.phone,'Телефоны админки',num(d.phones)+' <small>· сейчас '+num(d.phones_now)+'</small>'):'')
        +'</div>';
    var sk=[];(d.skip_tables||[]).forEach(function(t){sk.push(esc(t));});
    Object.keys(d.skip_cols||{}).forEach(function(t){sk.push(esc(t)+': '+d.skip_cols[t].map(esc).join(', '));});
    if(sk.length)h+='<div class="bk-box amber">'+IC.warn+'<span>Этих данных текущая версия прослойки не знает, они не загрузятся: '+sk.join('; ')+'</span></div>';
    if(d.gate===false&&d.gate_now)h+='<div class="bk-box amber">'+IC.warn+'<span>В бэкапе вход по секретной ссылке выключен. Если не оставить текущий вход, админка будет открываться по обычному адресу.</span></div>';
    if(d.phones||d.phones_now)h+='<div class="bk-box amber">'+IC.warn+'<span>Список телефонов станет как в бэкапе: телефоны, привязанные позже, придётся привязать заново, а отвязанные позже снова получат доступ к админке.</span></div>';
    if(d.chan_new)h+='<div class="bk-box info">'+IC.info+'<span>Ключ защищённого канала станет как в бэкапе. Clod Clash сам переключится на него при следующем обновлении подписки — делать ничего не нужно.</span></div>';
    var lockKeep=!d.user;
    h+='<div class="bk-chks">'
        +'<label class="chk" data-tip="Логин, пароль и секретная ссылка для входа останутся текущими"><input type="checkbox" id="bkKeep"'+(lockKeep?' checked disabled':'')+'> Оставить текущий вход в админку</label>'
        +'<label class="chk" data-tip="Текущие журналы и статистика останутся как есть, из файла они не загрузятся"><input type="checkbox" id="bkNoLog"'+(d.logs?'':' checked disabled')+'> Без журналов и статистики</label>'
        +'</div>';
    h+='<div class="bk-box red">'+IC.warn+'<span>Все текущие данные прослойки заменятся данными из бэкапа. Перед этим прослойка сохранит снимок — из него можно вернуть всё обратно. Пока идёт восстановление, подписки могут отвечать с задержкой.</span></div>';
    h+='<div class="bk-word"><label for="bkWord">Для подтверждения введите слово <b>восстановить</b></label><input type="text" id="bkWord" autocomplete="off" autocapitalize="none" spellcheck="false"></div>';
    h+='<div class="dlg-actions"><button type="button" class="btn ghost" data-x>Отмена</button><button type="button" class="btn danger" id="bkGo" disabled>Восстановить</button></div>';
    body.innerHTML=h;
    var w=$('bkWord'),g=$('bkGo');
    w.addEventListener('input',function(){g.disabled=w.value.trim().toLowerCase()!=='восстановить';});
    w.addEventListener('keydown',function(e){if(e.key==='Enter'&&!g.disabled)g.click();});
    g.addEventListener('click',function(){restore($('bkKeep').checked,$('bkNoLog').checked,w.value);});
    w.focus();
}
function restore(keep,nolog,word){
    var fd=new FormData();fd.append('csrf',CSRF);fd.append('name',cur.name);fd.append('pass',cur.pass);fd.append('keep_login',keep?'1':'0');fd.append('no_logs',nolog?'1':'0');fd.append('word',word);
    busy=true;$('bkDlgX').hidden=true;
    body.innerHTML=progHtml();
    stream('index.php?ajax=bk_restore',fd,function(o){progSet(body,o);}).then(function(r){
        if(!r.ok){
            busy=false;$('bkDlgX').hidden=false;
            body.innerHTML=resHtml('bad',IC.bad,'Не получилось',esc(r.error||'')+'<br>'+(r.lost?'Восстановление могло успеть завершиться — обновите страницу через минуту.':'Текущие данные не изменились.'))
                +'<div class="dlg-actions"><button type="button" class="btn ghost" data-x>Закрыть</button></div>';
            return;
        }
        var t='Загружено записей: '+num(r.rows)+'.';
        if(r.skipped&&r.skipped.length)t+='<br>Не загрузились таблицы, которых нет в этой версии: '+r.skipped.map(esc).join(', ')+'.';
        if(r.warn)t+='<br>'+esc(r.warn);
        if(r.relogin)t+='<br>Логин и пароль теперь как в бэкапе — войдите с ними.';
        if(r.gate===''&&r.gate!==null)t+='<br>Вход по секретной ссылке выключен: в бэкапе его не было.';
        else if(r.gate)t+='<br>Секретная ссылка для входа теперь как в бэкапе: <code>'+esc(location.origin+location.pathname+'?'+r.gate)+'</code>';
        var go=r.next||(r.relogin?'index.php':'index.php?tab=backup');
        body.innerHTML=resHtml('ok',IC.ok,'Готово',t)+'<div class="dlg-actions"><button type="button" class="btn" id="bkDone">'+(r.relogin?'Войти':'Готово')+'</button></div>';
        $('bkDone').addEventListener('click',function(){location.href=go;});
    });
}
body.addEventListener('click',function(e){if(e.target.closest&&e.target.closest('[data-x]'))close();});
function openRow(tr){
    if(!tr)return;
    cur={name:tr.getAttribute('data-name'),pass:''};
    $('bkDlgX').hidden=false;dlg.classList.add('open');
    if(tr.getAttribute('data-enc')==='1')askPass('');else preview();
}
document.querySelectorAll('[data-bk-restore]').forEach(function(b){b.addEventListener('click',function(){openRow(b.closest('tr'));});});

var ub=$('bkUpBtn'),uf=$('bkUpFile');
if(ub&&uf){
    ub.addEventListener('click',function(){uf.click();});
    uf.addEventListener('change',function(){
        var f=uf.files&&uf.files[0];if(!f)return;
        if(LIM>0&&f.size>LIM){uiAlert('Сервер принимает файлы до '+fmtB(LIM)+', а этот больше. Положите файл на сервер в папку с бэкапами — подсказка под списком.');uf.value='';return;}
        var fd=new FormData();fd.append('csrf',CSRF);fd.append('file',f);
        ub.disabled=true;ub.classList.add('busy');
        fetch('index.php?ajax=bk_upload',{method:'POST',credentials:'same-origin',body:fd}).then(function(r){return r.json();}).then(function(d){
            ub.disabled=false;ub.classList.remove('busy');uf.value='';
            if(!d||!d.ok){uiAlert((d&&d.error)||'Файл не загрузился');return;}
            location.href='index.php?tab=backup&open='+encodeURIComponent(d.name);
        }).catch(function(){ub.disabled=false;ub.classList.remove('busy');uf.value='';uiAlert('Файл не загрузился: нет связи с сервером или файл больше, чем сервер разрешает загружать.');});
    });
}

if(window.LogPager&&$('bkList'))LogPager({bodyId:'bkList',topId:'bk_pgrTop',botId:'bk_pgrBot',storeKey:'pg_backup'});

var qs=new URLSearchParams(location.search),made=qs.get('made');
if(made||OPEN){
    var tr=document.querySelector('.bk-tbl tr[data-name="'+(window.CSS&&CSS.escape?CSS.escape(made||OPEN):made||OPEN)+'"]');
    if(made){if(tr)tr.classList.add('hl');if(window.uiToast)uiToast('Бэкап сохранён на сервере');}
    if(OPEN&&tr)openRow(tr);
    try{history.replaceState(null,'','index.php?tab=backup');}catch(e){}
}
})();
</script>
<?php endif; ?>
