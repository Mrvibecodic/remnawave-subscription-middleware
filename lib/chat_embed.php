<?php

function chat_embed_loader() {
    header('Content-Type: application/javascript; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    if (!chat_embed_enabled()) {
        header('Cache-Control: no-store');
        echo "/* off */\n";
        return;
    }
    header('Cache-Control: public, max-age=300');
    ?>
(function(){
'use strict';
if(window.__submwChat)return;window.__submwChat=1;
if(!/^https?:$/.test(location.protocol))return;
var cs=document.currentScript,u;
if(!cs||!cs.src)return;
try{u=new URL(cs.src,location.href);}catch(e){return;}
var org=u.origin,f=document.createElement('iframe'),st={open:false,w:0,h:0,pos:'right'},
    mq=window.matchMedia?window.matchMedia('(max-width:520px)'):{matches:false},vv=window.visualViewport;
var dz=u.searchParams?u.searchParams.get('widget')||'':'';
f.src=org+u.pathname+'?embed=1&site='+encodeURIComponent(location.origin)+(/^[a-z]{2,8}$/.test(dz)?'&d='+dz:'');
f.title='Чат поддержки';
f.setAttribute('allowtransparency','true');
// color-scheme:light — иначе на сайте с тёмной схемой браузер заливает прозрачный iframe непрозрачным фоном.
f.style.cssText='position:fixed;bottom:0;right:0;width:120px;height:120px;max-width:100%;max-height:100%;margin:0;padding:0;border:0;background:transparent;color-scheme:light;z-index:2147483000;visibility:hidden';
function full(){return st.open&&mq.matches;}
function apply(){
    var s=f.style,l=st.pos==='left';
    if(full()){s.top=(vv?vv.offsetTop:0)+'px';s.bottom='auto';s.left='0';s.right='auto';s.width='100%';s.height=vv?vv.height+'px':'100%';}
    else{s.top='auto';s.bottom='0';s.left=l?'0':'auto';s.right=l?'auto':'0';s.width=st.w+'px';s.height=st.h+'px';}
    s.visibility='visible';
    try{f.contentWindow.postMessage({submwChat:1,full:full(),narrow:!!mq.matches},org);}catch(e){}
}
window.addEventListener('message',function(e){
    if(e.origin!==org||e.source!==f.contentWindow)return;
    var d=e.data;if(!d||d.submwChat!==1)return;
    st.open=!!d.open;st.w=Math.max(0,Math.min(600,+d.w||0));st.h=Math.max(0,Math.min(900,+d.h||0));st.pos=d.pos==='left'?'left':'right';
    apply();
});
function re(){if(st.w)apply();}
if(mq.addEventListener)mq.addEventListener('change',re);else if(mq.addListener)mq.addListener(re);
if(vv){vv.addEventListener('resize',function(){if(full())apply();});vv.addEventListener('scroll',function(){if(full())apply();});}
function mount(){document.body.appendChild(f);}
if(document.body)mount();else document.addEventListener('DOMContentLoaded',mount);
})();
<?php
}

function chat_embed_page($site, $design = '') {
    $origin = chat_embed_origin($site);
    header('X-Robots-Tag: noindex');
    header('Cache-Control: no-store');
    header('Referrer-Policy: no-referrer');
    header('X-Content-Type-Options: nosniff');
    if (!chat_embed_enabled() || !chat_embed_site_ok($origin)) {
        http_response_code(403);
        header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
        header('Content-Type: text/plain; charset=utf-8');
        echo 'off';
        return;
    }
    $nonce = base64_encode(random_bytes(16));
    header("Content-Security-Policy: default-src 'none'; script-src 'nonce-{$nonce}'; style-src 'nonce-{$nonce}'; img-src https: data:; connect-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors {$origin}");
    header('Content-Type: text/html; charset=utf-8');
    echo chat_embed_html([
        'site'     => $origin,
        'design'   => in_array($design, chat_embed_designs(), true) ? $design : chat_embed_design(),
        'preset'   => chat_widget_preset(),
        'pos'      => chat_widget_position(),
        'text'     => chat_widget_text(),
        'agent'    => chat_agent_name(),
        'photo'    => chat_agent_photo(),
        'greeting' => chat_greeting(),
        'poll'     => chat_poll_interval(),
        'auto'     => chat_embed_autoopen(),
    ], $nonce);
}

function chat_embed_html(array $c, $nonce) {
    $design = in_array($c['design'] ?? '', chat_embed_designs(), true) ? $c['design'] : 'rw';
    $preset = (int) ($c['preset'] ?? 1);
    if ($preset < 1 || $preset > 3) $preset = 1;
    $pos = ($c['pos'] ?? 'right') === 'left' ? 'left' : 'right';
    $cfg = [
        'site'     => (string) ($c['site'] ?? ''),
        'preset'   => $preset,
        'pos'      => $pos,
        'agent'    => (string) ($c['agent'] ?? ''),
        'photo'    => (string) ($c['photo'] ?? ''),
        'greeting' => (string) ($c['greeting'] ?? ''),
        'poll'     => (int) ($c['poll'] ?? 4),
        'auto'     => (int) ($c['auto'] ?? 0),
    ];
    $json = json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $esc = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    $n = $esc($nonce);
    ob_start();
    ?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="robots" content="noindex">
<title><?= $esc($cfg['agent']) ?></title>
<style nonce="<?= $n ?>">
*{box-sizing:border-box;margin:0;padding:0;-webkit-tap-highlight-color:transparent}
html,body{height:100%;background:transparent;overflow:hidden}
body{-webkit-font-smoothing:antialiased;-webkit-text-size-adjust:100%}
button,textarea{font:inherit;color:inherit}
svg{flex:none;display:block}
.w{position:fixed;top:0;right:0;bottom:0;left:0;font:15px/1.45 var(--font);color:var(--text)}
.w :focus-visible{outline:2px solid var(--focus);outline-offset:2px}
.w .in:focus-visible{outline:0}

.lnch{position:absolute;bottom:20px;display:inline-flex;align-items:center;justify-content:center;gap:9px;width:max-content;border:0;cursor:pointer;white-space:nowrap;font-weight:600;font-size:14.5px;line-height:1;transition:transform .15s ease}
.lnch:hover{transform:translateY(-2px)}
.lnch:active{transform:scale(.97)}
.pos-right .lnch{right:20px}
.pos-left .lnch{left:20px}
.pre1 .lnch{width:60px;height:60px;border-radius:50%}
.pre1 .lt{display:none}
.pre2 .lnch{height:50px;padding:0 20px 0 17px;border-radius:999px}
.pre3 .lnch{bottom:0;height:48px;padding:0 22px 0 19px;border-radius:14px 14px 0 0}
.pre3 .lnch:hover,.pre3 .lnch:active{transform:none}
.lnch::after{content:"";position:absolute;top:0;right:0;bottom:0;left:0;border-radius:inherit;pointer-events:none;animation:pls 2.2s ease-out 1s 4}
@keyframes pls{from{box-shadow:0 0 0 0 var(--pulse)}to{box-shadow:0 0 0 16px transparent}}
.bdg{position:absolute;top:-5px;right:-5px;min-width:21px;height:21px;padding:0 6px;border-radius:11px;background:#ef4444;color:#fff;font-size:11.5px;font-weight:700;line-height:21px;text-align:center;box-shadow:0 0 0 2px var(--bdg-ring,transparent)}
.pos-left .bdg{right:auto;left:-5px}
.bdg[hidden]{display:none}

.pnl{position:absolute;top:20px;right:20px;bottom:20px;left:20px;display:none;flex-direction:column;overflow:hidden;border-radius:var(--r);isolation:isolate}
.open .pnl{display:flex;animation:pin .24s cubic-bezier(.22,1,.36,1)}
.open .lnch{display:none}
.w.full .pnl{top:0;right:0;bottom:0;left:0;border-radius:0;border:0;box-shadow:none}
@keyframes pin{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:none}}
@media(prefers-reduced-motion:reduce){.open .pnl{animation:none}.lnch{transition:none}.lnch::after{animation:none}}

.hd{position:relative;z-index:1;flex:none;display:flex;align-items:center;gap:12px;padding:13px 12px 13px 16px}
.full .hd{padding-top:calc(13px + env(safe-area-inset-top,0px))}
.ava{flex:none;width:40px;height:40px;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:16px;background-size:cover;background-position:center;overflow:hidden}
.ht{flex:1;min-width:0}
.hn{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.hs{display:flex;align-items:center;gap:6px;margin-top:1px;font-size:12.5px;line-height:1.3}
.hs i{flex:none;width:7px;height:7px;border-radius:50%;background:var(--ok)}
.x{flex:none;width:36px;height:36px;display:inline-flex;align-items:center;justify-content:center;border:0;background:none;cursor:pointer;transition:background .15s,color .15s}

.msgs{position:relative;z-index:1;flex:1;min-height:0;display:flex;flex-direction:column;gap:8px;padding:16px;overflow-y:auto;overscroll-behavior:contain;scrollbar-width:thin;scrollbar-color:var(--sb) transparent}
.msgs::-webkit-scrollbar{width:8px}
.msgs::-webkit-scrollbar-thumb{background:var(--sb);border-radius:8px;border:2px solid transparent;background-clip:padding-box}
.m{max-width:84%;padding:9px 13px;font-size:14.5px;line-height:1.42;white-space:pre-wrap;overflow-wrap:anywhere}
.m.v{align-self:flex-end}
.m.a{align-self:flex-start}
.m.s{align-self:center;max-width:100%;padding:2px 8px;font-size:12.5px;text-align:center}

.ft{position:relative;z-index:1;flex:none;display:flex;align-items:flex-end;gap:8px;padding:12px}
.full .ft{padding-bottom:calc(12px + env(safe-area-inset-bottom,0px))}
.in{flex:1;min-width:0;height:44px;min-height:44px;max-height:104px;padding:11px 14px;resize:none;outline:none;font-size:15px;line-height:20px;transition:border-color .2s}
@media(pointer:coarse){.in{font-size:16px}}
.snd{flex:none;width:44px;height:44px;display:inline-flex;align-items:center;justify-content:center;border:0;cursor:pointer;transition:transform .12s ease,opacity .15s,background .15s}
.snd:active{transform:scale(.95)}
.snd:disabled{opacity:.5;cursor:default;transform:none}

.d-rw{--font:Montserrat,-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;--text:#c9d1d9;--ok:#40c057;--sb:#30363d;--focus:#3bc9db;--r:16px;--bdg-ring:#161b23;--pulse:rgba(34,211,238,.6)}
.d-rw .lnch{color:#06262d;background:linear-gradient(135deg,#22d3ee,#06b6d4);box-shadow:0 0 0 1px rgba(8,51,68,.25),0 6px 18px rgba(34,211,238,.35)}
.d-rw .pnl{background-color:#161b23;background-image:radial-gradient(ellipse 60% 40% at 25% 40%,rgba(151,117,250,.08),transparent 50%),radial-gradient(ellipse 50% 50% at 85% 85%,rgba(34,211,238,.06),transparent 50%),linear-gradient(to right,rgba(128,128,128,.05) 1px,transparent 1px),linear-gradient(to bottom,rgba(128,128,128,.05) 1px,transparent 1px);background-size:auto,auto,60px 60px,60px 60px;border:1px solid rgba(34,211,238,.6);box-shadow:0 0 0 1px rgba(34,211,238,.12),0 6px 20px rgba(0,0,0,.5)}
.d-rw .hd{background:rgba(22,27,35,.8);border-bottom:1px solid rgba(255,255,255,.05);-webkit-backdrop-filter:blur(12px);backdrop-filter:blur(12px)}
.d-rw .ava{border-radius:12px;color:#3bc9db;background-color:#161b23;background-image:linear-gradient(135deg,rgba(34,211,238,.2),rgba(6,182,212,.1));border:1px solid rgba(34,211,238,.3)}
.d-rw .hn{font-family:Unbounded,Montserrat,-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;font-size:15px;font-weight:600;color:#fff}
.d-rw .hs{color:#8b949e}
.d-rw .x{border-radius:12px;color:#8b949e}
.d-rw .x:hover{color:#fff;background:rgba(255,255,255,.05)}
.d-rw .m{border-radius:12px}
.d-rw .m.a{background:rgba(255,255,255,.02);border:1px solid rgba(255,255,255,.08);-webkit-backdrop-filter:blur(10px);backdrop-filter:blur(10px)}
.d-rw .m.v{color:#e3fafc;background:linear-gradient(135deg,rgba(34,211,238,.1),rgba(6,182,212,.05));border:1px solid rgba(34,211,238,.2)}
.d-rw .m.s{color:#6e7681}
.d-rw .ft{background:rgba(22,27,35,.8);border-top:1px solid rgba(255,255,255,.05)}
.d-rw .in{color:#c9d1d9;background:rgba(255,255,255,.02);border:1px solid rgba(255,255,255,.08);border-radius:12px}
.d-rw .in:focus{border-color:rgba(34,211,238,.5)}
.d-rw .in::placeholder{color:#6e7681}
.d-rw .snd{border-radius:16px;color:#3bc9db;background:transparent;border:1px solid #3bc9db}
.d-rw .snd:hover:not(:disabled){background:rgba(59,201,219,.08)}

.d-lk{--font:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;--text:#eef2f8;--ok:#34d399;--sb:rgba(255,255,255,.16);--focus:#9d7bff;--r:20px;--bdg-ring:#0b0f17;--pulse:rgba(124,131,255,.6)}
.d-lk .lnch{color:#fff;background:linear-gradient(120deg,#5b8cff,#9d7bff);box-shadow:0 6px 18px rgba(91,140,255,.4)}
.d-lk .pnl{background:radial-gradient(circle at 0 0,rgba(91,140,255,.34),transparent 46%),radial-gradient(circle at 100% 100%,rgba(157,123,255,.30),transparent 46%),radial-gradient(120% 80% at 50% -10%,rgba(91,140,255,.16),transparent 60%),linear-gradient(180deg,#0e1320,#0b0f17 40%);border:1px solid rgba(132,152,255,.62);box-shadow:0 4px 20px rgba(91,140,255,.3)}
.d-lk .hd{background:rgba(255,255,255,.045);border-bottom:1px solid rgba(255,255,255,.10);-webkit-backdrop-filter:blur(18px) saturate(140%);backdrop-filter:blur(18px) saturate(140%)}
.d-lk .ava{border-radius:50%;color:#fff;background-color:#5b8cff;background-image:linear-gradient(120deg,#5b8cff,#9d7bff)}
.d-lk .hn{font-size:16px;font-weight:700;letter-spacing:-.2px}
.d-lk .hs{color:#8b94a7}
.d-lk .hs i{box-shadow:0 0 0 3px rgba(52,211,153,.2)}
.d-lk .x{border-radius:50%;color:#8b94a7;background:rgba(255,255,255,.045);border:1px solid rgba(255,255,255,.10)}
.d-lk .x:hover{color:#eef2f8;background:rgba(255,255,255,.07)}
.d-lk .m.a{border-radius:18px 18px 18px 6px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.10);box-shadow:inset 0 1px 0 rgba(255,255,255,.06);-webkit-backdrop-filter:blur(12px);backdrop-filter:blur(12px)}
.d-lk .m.v{border-radius:18px 18px 6px 18px;color:#fff;background:linear-gradient(120deg,#5b8cff,#9d7bff);box-shadow:0 6px 18px rgba(91,140,255,.28)}
.d-lk .m.s{color:#8b94a7}
.d-lk .ft{background:rgba(11,15,23,.55);border-top:1px solid rgba(255,255,255,.10);-webkit-backdrop-filter:blur(18px);backdrop-filter:blur(18px)}
.d-lk .in{color:#eef2f8;background:rgba(0,0,0,.25);border:1px solid rgba(255,255,255,.10);border-radius:14px}
.d-lk .in:focus{border-color:#5b8cff}
.d-lk .in::placeholder{color:#5f6877}
.d-lk .snd{border-radius:14px;color:#fff;background:linear-gradient(120deg,#5b8cff,#9d7bff);box-shadow:0 6px 16px rgba(91,140,255,.35)}

.d-lkmin{--font:-apple-system,BlinkMacSystemFont,"SF Pro Text","Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;--bg:#f4f5f7;--card:#fff;--text:#0f1115;--muted:#667085;--faint:#98a2b3;--line:#e4e7ec;--soft:#eef0f3;--accent:#2f6bff;--on-accent:#fff;--ok:#067647;--shadow:0 1px 2px rgba(16,24,40,.04),0 1px 3px rgba(16,24,40,.06);--top-bg:rgba(244,245,247,.88);--sb:#d0d5dd;--focus:#2f6bff;--r:18px;--bdg-ring:#fff;--frame:#4a7bff;--pnl-shadow:0 6px 20px rgba(16,24,40,.2);--lnch-shadow:0 6px 16px rgba(47,107,255,.32);--pulse:rgba(47,107,255,.5)}
@media(prefers-color-scheme:dark){.d-lkmin{--bg:#0d0e11;--card:#17191e;--text:#f1f3f6;--muted:#9aa1ad;--faint:#6b7280;--line:#262930;--soft:#1f2228;--accent:#5b8dff;--ok:#34d399;--shadow:none;--top-bg:rgba(13,14,17,.88);--sb:#3a3f48;--focus:#7aa2ff;--bdg-ring:#0d0e11;--frame:#4a72d6;--pnl-shadow:0 0 0 1px rgba(91,141,255,.16),0 6px 20px rgba(0,0,0,.6);--lnch-shadow:0 6px 16px rgba(0,0,0,.4);--pulse:rgba(91,141,255,.55)}}
.d-lkmin .lnch{color:var(--on-accent);background:var(--accent);box-shadow:var(--lnch-shadow)}
.d-lkmin .pnl{background:var(--bg);border:1px solid var(--frame);box-shadow:var(--pnl-shadow)}
.d-lkmin .hd{background:var(--top-bg);border-bottom:1px solid var(--line);-webkit-backdrop-filter:saturate(160%) blur(14px);backdrop-filter:saturate(160%) blur(14px)}
.d-lkmin .ava{border-radius:10px;color:var(--on-accent);background-color:var(--accent)}
.d-lkmin .hn{font-size:17px;font-weight:700;letter-spacing:-.2px}
.d-lkmin .hs{color:var(--muted)}
.d-lkmin .x{border-radius:50%;color:var(--text);background:var(--card);border:1px solid var(--line)}
.d-lkmin .x:hover{background:var(--soft)}
.d-lkmin .m.a{border-radius:18px 18px 18px 6px;background:var(--card);border:1px solid var(--line);box-shadow:var(--shadow)}
.d-lkmin .m.v{border-radius:18px 18px 6px 18px;color:var(--on-accent);background:var(--accent)}
.d-lkmin .m.s{color:var(--muted)}
.d-lkmin .ft{background:var(--card);border-top:1px solid var(--line)}
.d-lkmin .in{color:var(--text);background:var(--soft);border:1px solid transparent;border-radius:14px}
.d-lkmin .in:focus{border-color:var(--accent)}
.d-lkmin .in::placeholder{color:var(--faint)}
.d-lkmin .snd{border-radius:14px;color:var(--on-accent);background:var(--accent)}
</style>
</head>
<body>
<div class="w d-<?= $design ?> pos-<?= $pos ?> pre<?= $preset ?>" id="w">
    <button class="lnch" id="lnch" type="button" aria-label="Открыть чат">
        <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7.9 20A9 9 0 1 0 4 16.1L2 22Z"/></svg>
        <span class="lt"><?= $esc($c['text'] ?? '') ?></span>
        <span class="bdg" id="bdg" hidden></span>
    </button>
    <section class="pnl" id="pnl" aria-label="Чат поддержки">
        <header class="hd">
            <div class="ava" id="ava" aria-hidden="true"></div>
            <div class="ht"><div class="hn"><?= $esc($cfg['agent']) ?></div><div class="hs"><i></i>обычно отвечает быстро</div></div>
            <button class="x" id="x" type="button" aria-label="Свернуть чат"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg></button>
        </header>
        <div class="msgs" id="msgs" role="log" aria-live="polite"></div>
        <div class="ft">
            <textarea class="in" id="in" rows="1" maxlength="4000" placeholder="Сообщение…" aria-label="Сообщение"></textarea>
            <button class="snd" id="snd" type="button" aria-label="Отправить"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14.536 21.686a.5.5 0 0 0 .937-.024l6.5-19a.496.496 0 0 0-.635-.635l-19 6.5a.5.5 0 0 0-.024.937l7.93 3.18a2 2 0 0 1 1.112 1.11z"/><path d="m21.854 2.147-10.94 10.939"/></svg></button>
        </div>
    </section>
</div>
<script nonce="<?= $n ?>">
(function(){
    'use strict';
    var CFG = <?= $json ?>;
    var SITE = CFG.site, API = location.pathname, KEY = 'submw_chat_tok:' + SITE, framed = window.parent !== window;
    var $ = function(id){ return document.getElementById(id); };
    var root = $('w'), lnch = $('lnch'), msgs = $('msgs'), inp = $('in'), snd = $('snd'), bdg = $('bdg'), ava = $('ava');
    var tok = '', lastId = 0, seen = {}, opened = false, want = false, started = false, has = false, busy = false, timer = null, unread = 0, noteEl = null;
    var coarse = window.matchMedia && matchMedia('(pointer:coarse)').matches;
    var SHUT = 'submw_chat_shut:' + SITE, shut = false, narrow = null, byAuto = false, lazy = false;
    try { shut = sessionStorage.getItem(SHUT) === '1'; } catch (e) {}
    function setShut(v){
        shut = v;
        try { if (v) sessionStorage.setItem(SHUT, '1'); else sessionStorage.removeItem(SHUT); } catch (e) {}
    }

    try { tok = localStorage.getItem(KEY) || ''; } catch (e) {}
    if (!/^[a-f0-9]{32}$/.test(tok)) tok = '';
    function keep(t){
        tok = t || '';
        try { if (tok) localStorage.setItem(KEY, tok); else localStorage.removeItem(KEY); } catch (e) {}
    }

    if (CFG.photo) ava.style.backgroundImage = 'url("' + CFG.photo.replace(/["\\\s]/g, '') + '")';
    else ava.textContent = (CFG.agent || '?').charAt(0).toUpperCase();

    function api(q, o){
        o = o || {};
        o.credentials = 'same-origin';
        o.headers = o.headers || {};
        if (tok) o.headers['X-Chat-Token'] = tok;
        return fetch(API + q + '&e=' + encodeURIComponent(SITE), o).then(function(r){ return r.json(); });
    }
    function bubble(cls, text){
        var el = document.createElement('div');
        el.className = 'm ' + cls;
        el.textContent = text;
        msgs.appendChild(el);
        msgs.scrollTop = msgs.scrollHeight;
        return el;
    }
    function add(m){
        if (m.id && seen[m.id]) return false;
        if (m.id) seen[m.id] = 1;
        bubble(m.sender === 'visitor' ? 'v' : (m.sender === 'system' ? 's' : 'a'), m.body);
        if (m.id > lastId) lastId = m.id;
        return true;
    }
    function note(t){
        if (noteEl && noteEl.parentNode) noteEl.parentNode.removeChild(noteEl);
        noteEl = t ? bubble('s', t) : null;
    }
    function setBadge(n){
        unread = n;
        bdg.hidden = !n;
        bdg.textContent = n > 9 ? '9+' : String(n);
    }
    function greet(){
        msgs.textContent = '';
        if (CFG.greeting) bubble('a', CFG.greeting);
    }
    function startPoll(){ if (!timer) timer = setInterval(poll, (CFG.poll || 4) * 1000); }
    function stopPoll(){ if (timer) { clearInterval(timer); timer = null; } }
    function start(){
        if (started) return Promise.resolve();
        started = true;
        return api('?api=open', {method: 'POST'}).then(function(d){
            if (!d || !d.ok) { started = false; return; }
            if (d.exists) {
                has = true;
                if (d.token) keep(d.token);
                lastId = 0; seen = {}; msgs.textContent = '';
                (d.messages || []).forEach(add);
                startPoll();
            } else {
                has = false;
                keep('');
                greet();
            }
        }).catch(function(){ started = false; });
    }
    function reset(){
        started = false; has = false; lastId = 0; seen = {};
        keep(''); setBadge(0); stopPoll();
        if (opened) start(); else msgs.textContent = '';
    }
    function poll(){
        if (busy || !has || document.hidden) return;
        busy = true;
        api('?api=poll&after=' + lastId).then(function(d){
            busy = false;
            if (!d) return;
            if (d.gone) { reset(); return; }
            if (!d.ok) return;
            (d.messages || []).forEach(function(m){
                if (add(m) && !opened && m.sender !== 'visitor') setBadge(unread + 1);
            });
        }).catch(function(){ busy = false; });
    }
    function grow(){
        inp.style.height = 'auto';
        inp.style.height = Math.max(44, Math.min(inp.scrollHeight + 2, 104)) + 'px';
    }
    function send(){
        var t = inp.value.trim();
        if (!t || snd.disabled) return;
        inp.value = ''; grow();
        snd.disabled = true;
        var first = !has;
        var back = function(msg){
            snd.disabled = false;
            if (!inp.value) { inp.value = t; grow(); }
            note(msg);
        };
        api('?api=send', {method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: 'body=' + encodeURIComponent(t)}).then(function(d){
            if (!d || !d.ok) {
                back(d && (d.error === 'rate' || d.error === 'limit') ? 'Слишком много сообщений. Попробуйте позже.' : 'Не отправилось. Попробуйте ещё раз.');
                return;
            }
            snd.disabled = false;
            note('');
            if (d.token) keep(d.token);
            has = true;
            startPoll();
            if (first) { started = false; start(); } else poll();
        }).catch(function(){ back('Нет связи. Попробуйте ещё раз.'); });
    }

    function post(o){
        if (!framed) return;
        o.submwChat = 1;
        o.pos = CFG.pos;
        try { parent.postMessage(o, SITE); } catch (e) {}
    }
    function tell(){
        if (opened || want) { post({open: true, w: 420, h: 640}); return; }
        var r = lnch.getBoundingClientRect();
        post({open: false, w: Math.ceil(r.width) + 40, h: Math.ceil(r.height) + (CFG.preset === 3 ? 20 : 40)});
    }
    function reveal(){
        if (opened) return;
        opened = true; want = false;
        root.classList.add('open');
        setBadge(0);
        var auto = byAuto;
        byAuto = false;
        // Окно раскрылось само, а диалога у посетителя, скорее всего, нет: сервер не трогаем, пока он не заинтересовался.
        if (auto && !tok && !started) { lazy = true; greet(); return; }
        start().then(function(){
            msgs.scrollTop = msgs.scrollHeight;
            if (!auto && !coarse) inp.focus();
        });
    }
    function wake(){
        if (!lazy) return;
        lazy = false;
        start();
    }
    function openChat(){
        if (opened || want) return;
        if (!framed) { root.classList.add('full'); reveal(); return; }
        want = true;
        tell();
        setTimeout(function(){ if (want) reveal(); }, 400);
    }
    function closeChat(){
        if (!opened) return;
        opened = false; want = false; lazy = false;
        root.classList.remove('open');
        setShut(true);
        tell();
        lnch.focus();
    }
    // Окно раскрывается само один раз: если посетитель его свернул, до конца визита оно ждёт нажатия. На телефоне не раскрывается — там оно закрыло бы всю страницу.
    function autoOpen(){
        if (opened || want || shut || narrow !== false) return;
        if (document.hidden) {
            document.addEventListener('visibilitychange', function h(){
                if (document.hidden) return;
                document.removeEventListener('visibilitychange', h);
                setTimeout(autoOpen, 1000);
            });
            return;
        }
        byAuto = true;
        openChat();
    }
    window.addEventListener('message', function(e){
        if (!framed || e.source !== parent || e.origin !== SITE) return;
        var d = e.data;
        if (!d || d.submwChat !== 1) return;
        root.classList.toggle('full', !!d.full);
        narrow = !!d.narrow;
        if (want) reveal();
    });

    lnch.addEventListener('click', function(){ setShut(false); openChat(); });
    $('x').addEventListener('click', closeChat);
    $('pnl').addEventListener('pointerdown', wake);
    inp.addEventListener('focus', wake);
    snd.addEventListener('click', send);
    inp.addEventListener('input', grow);
    inp.addEventListener('keydown', function(e){
        if (e.key === 'Enter' && !e.shiftKey && !e.isComposing && !coarse) { e.preventDefault(); send(); }
    });
    document.addEventListener('keydown', function(e){ if (e.key === 'Escape') closeChat(); });
    document.addEventListener('visibilitychange', function(){ if (!document.hidden) poll(); });

    tell();
    if (document.fonts && document.fonts.ready) document.fonts.ready.then(function(){ if (!opened && !want) tell(); });
    if (tok) start();
    if (framed && CFG.auto > 0 && !shut) setTimeout(autoOpen, CFG.auto * 1000);
})();
</script>
</body>
</html>
<?php
    return (string) ob_get_clean();
}
