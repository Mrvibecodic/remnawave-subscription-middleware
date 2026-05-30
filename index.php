<?php

ini_set('display_errors', 0);
error_reporting(E_ALL);

require __DIR__ . '/lib.php';

$target_domain = target_domain();

$request_uri = $_SERVER['REQUEST_URI'] ?? '/';
$parsed_url  = parse_url($request_uri);
$path        = isset($parsed_url['path']) ? ltrim($parsed_url['path'], '/') : '';
$query       = isset($parsed_url['query']) ? $parsed_url['query'] : '';

if (empty($path) || $path === 'index.php') {
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Личный кабинет — Вход</title>
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
<div class="card">
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
        <form id="loginForm" onsubmit="handleLogin(event)">
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
<script>
    function switchTab(t){var l=document.getElementById('login-content'),r=document.getElementById('register-content'),b=document.querySelectorAll('.tab'),e=document.getElementById('loginError');e.style.display='none';if(t==='login'){l.classList.remove('hidden');r.classList.add('hidden');b[0].classList.add('active');b[1].classList.remove('active');}else{l.classList.add('hidden');r.classList.remove('hidden');b[0].classList.remove('active');b[1].classList.add('active');}}
    function handleLogin(e){e.preventDefault();var m=document.getElementById('email').value,p=document.getElementById('password').value,b=document.getElementById('loginBtn');
    if(!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(m)){s('Введите корректный Email адрес');return;}
    if(p.length<14){s('Пароль должен содержать минимум 14 символов');return;}
    b.disabled=true;b.textContent='Проверка…';document.getElementById('loginError').style.display='none';setTimeout(function(){b.disabled=false;b.textContent='Войти';s('Пользователь не найден или пароль неверен');},800+Math.random()*1000);}
    function s(m){var e=document.getElementById('loginError');e.textContent=m;e.style.display='block';document.querySelector('.card').animate([{transform:'translateX(0)'},{transform:'translateX(-5px)'},{transform:'translateX(5px)'},{transform:'translateX(0)'}],{duration:300});}
</script>
</body>
</html>
<?php
    exit();
}

$skip_log =
    $path === ''
    || preg_match('~(^|/)(\.[^/]*|cdn-cgi/|assets/|static/|_next/)~i', $path)
    || preg_match('~\.(js|mjs|css|map|json|png|jpe?g|gif|svg|webp|ico|woff2?|ttf|eot|txt|xml|html?|env|bak|old|orig|save|swp|swo|copy|backup|tmp|sql|ya?ml|ini|conf|cfg|config|log|pem|key|crt|pfx|p12|zip|tar|gz|tgz|rar|7z|asp|aspx|jsp|cgi|exe|sh|bat|php\d?)$~i', $path)
    || substr($path, -1) === '~'
    || preg_match('~^(app|api|backend|frontend|server|config|credentials|secrets|keyfile|phpinfo\.php|wp-login\.php|wp-admin|xmlrpc\.php)$~i', $path)
    || preg_match('~(^|/)(favicon\.ico|robots\.txt|sitemap\.xml|browserconfig\.xml|apple-touch-icon[\w-]*\.png)$~i', $path);

$target_url = 'https://' . $target_domain . '/' . $path;
if ($query) $target_url .= '?' . $query;

$request_headers = [];
if (function_exists('getallheaders')) {
    foreach (getallheaders() as $key => $value) {
        if (strtolower($key) !== 'host') $request_headers[] = "$key: $value";
    }
} else {
    foreach ($_SERVER as $key => $value) {
        if (substr($key, 0, 5) === 'HTTP_') {
            $hn = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
            if (strtolower($hn) !== 'host') $request_headers[] = "$hn: $value";
        }
    }
}

$grabbed_headers = [];
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => $target_url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 5,
    CURLOPT_TIMEOUT        => proxy_timeout(),
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
    CURLOPT_HTTPHEADER     => $request_headers,
    CURLOPT_HEADER         => false,
    CURLOPT_HEADERFUNCTION => function ($curl, $header) use (&$grabbed_headers) {
        $len = strlen($header);
        $trim = trim($header);
        if ($trim === '' || strpos($trim, 'HTTP/') === 0) return $len;
        $parts = explode(':', $trim, 2);
        if (count($parts) === 2) {
            $grabbed_headers[strtolower(trim($parts[0]))] = trim($parts[1]);
        }
        return $len;
    },
]);
$response  = curl_exec($ch);
$curl_err  = curl_error($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$ip     = client_ip();
$ua     = $_SERVER['HTTP_USER_AGENT'] ?? '';
$segs   = path_segments($path);
$format = detect_client_format();

if ($curl_err) {
    http_response_code(502);
    if (!$skip_log) log_request($ip, $segs[0] ?? '', $path, $ua, 'error');
    die();
}

$current_hwid = $_SERVER['HTTP_X_HWID'] ?? '';
$expire_ts    = parse_expire_from_userinfo($grabbed_headers['subscription-userinfo'] ?? null);
$now          = time();
$trust_header = trust_header_expire();

$decision   = 'normal';
$short_uuid = '';

$blocked = false;
if ($current_hwid !== '') {
    $hwid_ov = find_override('hwid', $current_hwid);
    if ($hwid_ov && $hwid_ov['reason'] === 'blocked') $blocked = true;
}
$short_ov = find_override_in('shortuuid', $segs);
if ($short_ov) {
    $short_uuid = $short_ov['match_value'];
    if ($short_ov['reason'] === 'blocked') $blocked = true;
}

$expired = false;
$header_says_expired = ($expire_ts !== null && $expire_ts < $now);
$header_says_valid   = ($expire_ts !== null && $expire_ts >= $now);
$db_says_expired     = ($short_ov && $short_ov['reason'] === 'expired');

if ($trust_header) {
    if ($header_says_expired) {
        $expired = true;
    } elseif ($db_says_expired && !$header_says_valid) {
        $expired = true;
    }
} else {
    $expired = $db_says_expired;
}

if ($db_says_expired && $header_says_valid && $short_ov['source'] === 'webhook') {
    delete_override('shortuuid', $short_ov['match_value'], 'webhook');
}

$ov_created = is_array($short_ov) ? ($short_ov['created_at'] ?? null) : null;
if ($expired && expired_grace_passed($expire_ts, $ov_created, $now)) {
    $expired = false;
}

if ($blocked)      $decision = 'blocked';
elseif ($expired)  $decision = 'expired';

if ($short_uuid === '' && $segs) $short_uuid = $segs[0];

$passthrough = ['profile-title', 'support-url', 'profile-update-interval',
                'profile-web-page-url', 'subscription-userinfo', 'content-disposition',
                'announce', 'announce-url'];

$do_substitute = ($decision === 'blocked');
if ($do_substitute) {
    header('HTTP/1.1 200 OK');
    if (isset($grabbed_headers['content-type'])) {
        header('Content-Type: ' . $grabbed_headers['content-type']);
    } else {
        header('Content-Type: text/plain; charset=utf-8');
    }

    foreach ($passthrough as $h) {
        if (isset($grabbed_headers[$h])) header($h . ': ' . $grabbed_headers[$h]);
    }

    emit_app_headers();
    echo build_override_body($decision, $format);
    if (!$skip_log) log_request($ip, $short_uuid, $path, $ua, $decision, $expire_ts, $current_hwid);
    exit();
}

$unsafe = ['host', 'connection', 'transfer-encoding', 'content-length'];
http_response_code($http_code ?: 200);
foreach ($grabbed_headers as $name => $value) {
    if (!in_array($name, $unsafe, true)) header($name . ': ' . $value);
}
emit_app_headers();
echo $response;
if (!$skip_log) {
    $log_decision = grace_is_active($short_uuid) ? 'grace' : 'normal';
    log_request($ip, $short_uuid, $path, $ua, $log_decision, $expire_ts, $current_hwid);
}
