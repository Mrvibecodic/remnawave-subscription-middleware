<?php

function landing_preset() {
    $v = (int) setting('landing_preset', '1');
    return ($v >= 1 && $v <= 4) ? $v : 1;
}

function landing_fp_new() {
    try { return bin2hex(random_bytes(8)); }
    catch (Throwable $e) { return substr(md5(uniqid('', true) . mt_rand()), 0, 16); }
}

function landing_fp() {
    $fp = trim((string) setting('landing_fp', ''));
    if (!preg_match('~^[0-9a-f]{8,32}$~', $fp)) { $fp = landing_fp_new(); set_setting('landing_fp', $fp); }
    return $fp;
}

function landing_fp_regenerate() {
    set_setting('landing_fp', landing_fp_new());
    set_setting('landing_fp_ack', '1');
}

function landing_login_script() {
    ?>
<script>
(function(){
    var form=document.getElementById('loginForm');
    var savedLabel='';
    function shake(){var c=document.querySelector('.js-shake');if(c&&c.animate)c.animate([{transform:'translateX(0)'},{transform:'translateX(-6px)'},{transform:'translateX(6px)'},{transform:'translateX(0)'}],{duration:300});}
    function showErr(m){var e=document.getElementById('loginError');if(!e)return;e.textContent=m;e.style.display='block';shake();}
    if(form){form.addEventListener('submit',function(e){
        e.preventDefault();
        var m=(document.getElementById('email')||{}).value||'';
        var p=(document.getElementById('password')||{}).value||'';
        var b=document.getElementById('loginBtn');
        var err=document.getElementById('loginError'); if(err)err.style.display='none';
        if(!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(m)){showErr('Введите корректный Email адрес');return;}
        if(p.length<14){showErr('Пароль должен содержать минимум 14 символов');return;}
        if(b){b.disabled=true;savedLabel=b.textContent;b.textContent='Проверка…';}
        setTimeout(function(){if(b){b.disabled=false;b.textContent=savedLabel||'Войти';}showErr('Пользователь не найден или пароль неверен');},800+Math.random()*1000);
    });}
    window.switchTab=function(t){
        var l=document.getElementById('login-content'),r=document.getElementById('register-content'),
            tb=document.querySelectorAll('.tab'),e=document.getElementById('loginError');
        if(e)e.style.display='none';
        if(!l||!r)return;
        if(t==='login'){l.classList.remove('hidden');r.classList.add('hidden');if(tb[0])tb[0].classList.add('active');if(tb[1])tb[1].classList.remove('active');}
        else{l.classList.add('hidden');r.classList.remove('hidden');if(tb[0])tb[0].classList.remove('active');if(tb[1])tb[1].classList.add('active');}
    };
})();
</script>
    <?php
}

function landing_render() {
    $preset = landing_preset();
    $fp = landing_fp();
    $fp_titles = ['Личный кабинет — Вход', 'Вход в личный кабинет', 'Личный кабинет', 'Авторизация', 'Вход — личный кабинет', 'Кабинет — авторизация', 'Вход в систему'];
    $fp_title = $fp_titles[hexdec(substr($fp, 0, 4)) % count($fp_titles)];
    $fp_v = substr(sha1($fp), 0, 8);
    $fp_b = substr(sha1($fp . 'b'), 0, 12);
    echo "<!DOCTYPE html>\n";
    ?>
<html lang="ru" data-v="<?= $fp_v ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <meta name="build" content="<?= $fp_b ?>">
    <title><?= $fp_title ?></title>
<?php if ($preset === 2): ?>
    <style>
        :root{--accent:#4f46e5;--accent-h:#4338ca;--ink:#0f172a;--muted:#64748b;--line:#e2e8f0;--err:#ef4444}
        *{box-sizing:border-box}
        body{font-family:'Segoe UI',Roboto,Helvetica,Arial,sans-serif;margin:0;min-height:100vh;display:flex;color:var(--ink);background:#fff}
        .lp-side{flex:1;background:linear-gradient(150deg,#4f46e5,#7c3aed 55%,#0ea5e9);color:#fff;padding:3rem;display:flex;flex-direction:column;justify-content:center;gap:1.4rem}
        .lp-side .ic{width:54px;height:54px;border-radius:14px;background:rgba(255,255,255,.18);display:flex;align-items:center;justify-content:center}
        .lp-side h2{font-size:1.8rem;margin:0;letter-spacing:-.4px;line-height:1.2}
        .lp-side p{margin:0;opacity:.9;max-width:34ch;line-height:1.6}
        .lp-feat{display:flex;flex-direction:column;gap:.7rem;margin-top:.5rem}
        .lp-feat div{display:flex;align-items:center;gap:.6rem;font-size:.92rem;opacity:.95}
        .lp-feat span{width:22px;height:22px;border-radius:50%;background:rgba(255,255,255,.2);display:flex;align-items:center;justify-content:center;flex:0 0 auto}
        .lp-main{flex:1;display:flex;align-items:center;justify-content:center;padding:1.5rem}
        .lp-form{width:100%;max-width:380px}
        .lp-form h1{font-size:1.4rem;margin:0 0 .3rem}
        .lp-form .sub{color:var(--muted);font-size:.88rem;margin:0 0 1.6rem}
        label{display:block;font-size:.8rem;font-weight:600;margin:0 0 .4rem}
        .fg{margin-bottom:1.1rem}
        input{width:100%;padding:.78rem .85rem;border:1px solid var(--line);border-radius:10px;font-size:.95rem;background:#f8fafc;transition:border-color .15s,box-shadow .15s}
        input:focus{outline:none;border-color:var(--accent);box-shadow:0 0 0 3px rgba(79,70,229,.12);background:#fff}
        .hint{font-size:.72rem;color:var(--muted);margin-top:.3rem}
        .btn{width:100%;padding:.85rem;background:var(--accent);color:#fff;border:0;border-radius:10px;font-size:.95rem;font-weight:600;cursor:pointer;transition:background .15s}
        .btn:hover{background:var(--accent-h)}
        .err{color:var(--err);font-size:.82rem;margin-top:.9rem;text-align:center;min-height:1.1rem;display:none}
        @media(max-width:760px){.lp-side{display:none}}
    </style>
</head>
<body>
    <div class="lp-side">
        <span class="ic"><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l7 3v5c0 4.5-3 7.5-7 9-4-1.5-7-4.5-7-9V6z"/><path d="M9.5 12l1.8 1.8L15 9.8"/></svg></span>
        <h2>Личный кабинет</h2>
        <p>Управляйте вашим аккаунтом, подпиской и устройствами в одном месте.</p>
        <div class="lp-feat">
            <div><span>✓</span> Безопасный вход и защита аккаунта</div>
            <div><span>✓</span> Доступ к подписке и устройствам</div>
            <div><span>✓</span> Поддержка 24/7</div>
        </div>
    </div>
    <div class="lp-main">
        <div class="lp-form js-shake">
            <h1>С возвращением</h1>
            <p class="sub">Войдите, чтобы продолжить</p>
            <form id="loginForm">
                <div class="fg"><label for="email">Email</label><input type="email" id="email" required placeholder="name@example.com"></div>
                <div class="fg"><label for="password">Пароль</label><input type="password" id="password" required placeholder="••••••••••••••"><div class="hint">Минимум 14 символов</div></div>
                <button type="submit" class="btn" id="loginBtn">Войти</button>
                <div id="loginError" class="err"></div>
            </form>
        </div>
    </div>
<?php elseif ($preset === 3): ?>
    <style>
        :root{--accent:#6366f1;--accent-h:#4f46e5;--err:#f87171}
        *{box-sizing:border-box}
        body{font-family:'Segoe UI',Roboto,Helvetica,Arial,sans-serif;margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:1.2rem;color:#e2e8f0;background:#0b1020;background-image:radial-gradient(900px 500px at 15% 0%,rgba(99,102,241,.35),transparent),radial-gradient(800px 500px at 100% 100%,rgba(14,165,233,.28),transparent)}
        .card{width:100%;max-width:400px;background:rgba(255,255,255,.06);backdrop-filter:blur(14px);-webkit-backdrop-filter:blur(14px);border:1px solid rgba(255,255,255,.12);border-radius:18px;padding:2.3rem 2rem;box-shadow:0 24px 60px rgba(0,0,0,.45)}
        .brand{display:flex;flex-direction:column;align-items:center;gap:.55rem;margin-bottom:1.6rem}
        .brand .ic{width:54px;height:54px;border-radius:15px;background:linear-gradient(160deg,var(--accent),#22d3ee);display:flex;align-items:center;justify-content:center;color:#fff;box-shadow:0 8px 22px rgba(99,102,241,.45)}
        .brand h1{font-size:1.2rem;margin:0;font-weight:700}
        .brand p{margin:0;font-size:.8rem;color:#94a3b8}
        label{display:block;font-size:.8rem;font-weight:600;margin:0 0 .4rem;color:#cbd5e1}
        .fg{margin-bottom:1.1rem}
        input{width:100%;padding:.78rem .85rem;border:1px solid rgba(255,255,255,.14);border-radius:10px;font-size:.95rem;background:rgba(15,23,42,.55);color:#e2e8f0;transition:border-color .15s,box-shadow .15s}
        input::placeholder{color:#64748b}
        input:focus{outline:none;border-color:var(--accent);box-shadow:0 0 0 3px rgba(99,102,241,.25)}
        .hint{font-size:.72rem;color:#94a3b8;margin-top:.3rem}
        .btn{width:100%;padding:.85rem;background:linear-gradient(120deg,var(--accent),#22d3ee);color:#fff;border:0;border-radius:10px;font-size:.95rem;font-weight:600;cursor:pointer;transition:filter .15s}
        .btn:hover{filter:brightness(1.07)}
        .err{color:var(--err);font-size:.82rem;margin-top:.9rem;text-align:center;min-height:1.1rem;display:none}
    </style>
</head>
<body>
    <div class="card js-shake">
        <div class="brand">
            <span class="ic"><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l7 3v5c0 4.5-3 7.5-7 9-4-1.5-7-4.5-7-9V6z"/><path d="M9.5 12l1.8 1.8L15 9.8"/></svg></span>
            <h1>Личный кабинет</h1>
            <p>Вход в аккаунт</p>
        </div>
        <form id="loginForm">
            <div class="fg"><label for="email">Email</label><input type="email" id="email" required placeholder="name@example.com"></div>
            <div class="fg"><label for="password">Пароль</label><input type="password" id="password" required placeholder="••••••••••••••"><div class="hint">Минимум 14 символов</div></div>
            <button type="submit" class="btn" id="loginBtn">Войти</button>
            <div id="loginError" class="err"></div>
        </form>
    </div>
<?php elseif ($preset === 4): ?>
    <style>
        :root{--accent:#0f766e;--accent-h:#115e59;--bg:#f8fafc;--card:#fff;--text:#0f172a;--muted:#64748b;--line:#e5e7eb;--err:#dc2626}
        *{box-sizing:border-box}
        body{font-family:'Segoe UI',Roboto,Helvetica,Arial,sans-serif;margin:0;min-height:100vh;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:1.2rem;background:var(--bg);color:var(--text)}
        .brand{display:flex;align-items:center;gap:.6rem;margin-bottom:1.4rem}
        .brand .ic{width:38px;height:38px;border-radius:10px;background:var(--accent);display:flex;align-items:center;justify-content:center;color:#fff}
        .brand b{font-size:1.05rem;letter-spacing:-.2px}
        .card{background:var(--card);width:100%;max-width:380px;border:1px solid var(--line);border-radius:16px;padding:2rem 1.9rem;box-shadow:0 10px 30px rgba(15,23,42,.07)}
        .card h1{font-size:1.15rem;margin:0 0 .25rem;text-align:center}
        .card .sub{text-align:center;color:var(--muted);font-size:.84rem;margin:0 0 1.5rem}
        label{display:block;font-size:.8rem;font-weight:600;margin:0 0 .4rem}
        .fg{margin-bottom:1.05rem}
        input{width:100%;padding:.76rem .85rem;border:1px solid var(--line);border-radius:9px;font-size:.95rem;background:#fbfdfd;transition:border-color .15s,box-shadow .15s}
        input:focus{outline:none;border-color:var(--accent);box-shadow:0 0 0 3px rgba(15,118,110,.12)}
        .hint{font-size:.72rem;color:var(--muted);margin-top:.3rem}
        .btn{width:100%;padding:.82rem;background:var(--accent);color:#fff;border:0;border-radius:9px;font-size:.95rem;font-weight:600;cursor:pointer;transition:background .15s}
        .btn:hover{background:var(--accent-h)}
        .err{color:var(--err);font-size:.82rem;margin-top:.9rem;text-align:center;min-height:1.1rem;display:none}
        .foot{margin-top:1.5rem;font-size:.78rem;color:var(--muted);text-align:center}
    </style>
</head>
<body>
    <div class="brand">
        <span class="ic"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l7 3v5c0 4.5-3 7.5-7 9-4-1.5-7-4.5-7-9V6z"/><path d="M9.5 12l1.8 1.8L15 9.8"/></svg></span>
        <b>Личный кабинет</b>
    </div>
    <div class="card js-shake">
        <h1>Вход в аккаунт</h1>
        <p class="sub">Введите данные для входа</p>
        <form id="loginForm">
            <div class="fg"><label for="email">Email</label><input type="email" id="email" required placeholder="name@example.com"></div>
            <div class="fg"><label for="password">Пароль</label><input type="password" id="password" required placeholder="••••••••••••••"><div class="hint">Минимум 14 символов</div></div>
            <button type="submit" class="btn" id="loginBtn">Войти</button>
            <div id="loginError" class="err"></div>
        </form>
    </div>
    <div class="foot">Доступ предоставляется по приглашению.</div>
<?php else: ?>
    <style>
        :root{--accent:#4f46e5;--accent-h:#4338ca;--bg1:#eef2f7;--bg2:#dbe4f3;--card:#fff;--text:#1f2937;--muted:#6b7280;--line:#e5e7eb;--err:#ef4444}
        *{box-sizing:border-box}
        body{font-family:'Segoe UI',Roboto,Helvetica,Arial,sans-serif;margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:1.2rem;background:radial-gradient(1100px 560px at 50% -10%,var(--bg2),var(--bg1));color:var(--text)}
        .card{background:var(--card);width:100%;max-width:400px;border:1px solid var(--line);border-radius:18px;padding:2.2rem 2rem;box-shadow:0 20px 50px rgba(31,41,55,.10)}
        .brand{display:flex;flex-direction:column;align-items:center;gap:.55rem;margin-bottom:1.6rem}
        .brand .ic{width:52px;height:52px;border-radius:14px;background:linear-gradient(160deg,var(--accent),#7c73f0);display:flex;align-items:center;justify-content:center;color:#fff;box-shadow:0 8px 18px rgba(79,70,229,.35)}
        .brand h1{font-size:1.18rem;margin:0;font-weight:700;letter-spacing:-.2px}
        .brand p{margin:0;font-size:.8rem;color:var(--muted)}
        .tabs{display:flex;gap:.3rem;background:var(--bg1);border-radius:11px;padding:.28rem;margin-bottom:1.4rem}
        .tab{flex:1;text-align:center;padding:.55rem;border-radius:8px;cursor:pointer;font-size:.88rem;font-weight:600;color:var(--muted);transition:all .15s}
        .tab.active{background:var(--card);color:var(--accent);box-shadow:0 1px 3px rgba(0,0,0,.08)}
        label{display:block;font-size:.8rem;font-weight:600;margin:0 0 .4rem}
        .fg{margin-bottom:1.1rem}
        input{width:100%;padding:.75rem .85rem;border:1px solid var(--line);border-radius:10px;font-size:.95rem;background:#fbfcfe;transition:border-color .15s,box-shadow .15s}
        input:focus{outline:none;border-color:var(--accent);box-shadow:0 0 0 3px rgba(79,70,229,.12)}
        .hint{font-size:.72rem;color:var(--muted);margin-top:.3rem}
        .btn{width:100%;padding:.85rem;background:var(--accent);color:#fff;border:0;border-radius:10px;font-size:.95rem;font-weight:600;cursor:pointer;transition:background .15s}
        .btn:hover{background:var(--accent-h)}
        .btn:disabled{background:#9ca3af;cursor:not-allowed}
        .err{color:var(--err);font-size:.82rem;margin-top:.9rem;text-align:center;min-height:1.1rem;display:none}
        .note{background:#f3f4ff;border:1px solid #e0e3ff;border-radius:10px;padding:.9rem 1rem;font-size:.86rem;color:#4338ca;line-height:1.5}
        .sub{text-align:center;font-size:.84rem;color:var(--muted);margin-top:.8rem}
        .hidden{display:none}
    </style>
</head>
<body>
<div class="card js-shake">
    <div class="brand">
        <span class="ic"><svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l7 3v5c0 4.5-3 7.5-7 9-4-1.5-7-4.5-7-9V6z"/><path d="M9.5 12l1.8 1.8L15 9.8"/></svg></span>
        <h1>Личный кабинет</h1>
        <p>Вход в аккаунт</p>
    </div>
    <div class="tabs">
        <div class="tab active" onclick="switchTab('login')">Вход</div>
        <div class="tab" onclick="switchTab('register')">Регистрация</div>
    </div>
    <div id="login-content">
        <form id="loginForm">
            <div class="fg"><label for="email">Email</label><input type="email" id="email" required placeholder="name@example.com"></div>
            <div class="fg"><label for="password">Пароль</label><input type="password" id="password" required placeholder="••••••••••••••"><div class="hint">Минимум 14 символов</div></div>
            <button type="submit" class="btn" id="loginBtn">Войти</button>
            <div id="loginError" class="err"></div>
        </form>
    </div>
    <div id="register-content" class="hidden">
        <div class="note"><strong>Регистрация по приглашению</strong><br>Сейчас регистрация новых пользователей закрыта. Доступ предоставляется по инвайт-коду.</div>
        <div class="sub">Если у вас есть инвайт-код, обратитесь к администратору.</div>
    </div>
</div>
<?php endif; ?>
<?php landing_login_script(); ?>
<?php chat_widget_render(); ?>
</body>
</html>
<?php
}
