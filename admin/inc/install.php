<?php
// Мастер установки. Подключается из admin/index.php, пока нет config.php. Состояние шагов — data/install.json.
require_once dirname(__DIR__, 2) . '/lib/install_probe.php';

function inst_state_path() { return dirname(__DIR__, 2) . '/data/install.json'; }

function inst_state_load() {
    $f = inst_state_path();
    $s = is_file($f) ? json_decode((string) @file_get_contents($f), true) : null;
    if (!is_array($s)) $s = [];
    // Прежний формат install.json (entrypoint до мастера).
    if (!isset($s['panel']) && isset($s['remnawave_url'])) {
        $s['panel'] = ['url' => (string) $s['remnawave_url']];
        if (!empty($s['subpage_external_url'])) $s['subpage'] = ['url' => (string) $s['subpage_external_url']];
        if (($s['mode'] ?? '') === 'panel' && empty($s['scenario'])) $s['scenario'] = 'panel';
        if (!empty($s['target_domain'])) $s['origin'] = ['raw' => (string) $s['target_domain']];
    }
    $s += ['key' => '', 'scenario' => '', 'mode' => 'panel', 'step' => 'env', 'panel' => [], 'scopes' => [], 'origin' => [], 'subpage' => [], 'webhook_secret' => '', 'admin' => [], 'db' => [], 'pending' => [], 'user_short' => '', 'user_name' => ''];
    $s['panel'] += ['url' => '', 'token' => '', 'cookie' => '', 'xkey' => '', 'tls' => 1, 'version' => '', 'st' => '', 'sub_public_domain' => '', 'webhook' => null];
    $s['subpage'] += ['url' => '', 'enabled' => 1, 'st' => ''];
    $s['origin'] += ['raw' => '', 'domain' => '', 'prefix' => '', 'short' => '', 'st' => '', 'browser' => null];
    $s['admin'] += ['user' => 'admin'];
    if (!is_array($s['db']) || empty($s['db']['driver'])) $s['db'] = ['driver' => 'sqlite', 'path' => default_db_path()];
    return $s;
}

function inst_state_save(array $s) {
    $dir = dirname(inst_state_path());
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    $ok = @file_put_contents(inst_state_path(), json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX) !== false;
    @chmod(inst_state_path(), 0640);
    return $ok;
}

function inst_key_new() {
    $a = 'abcdefghijklmnopqrstuvwxyz0123456789';
    $k = 'k';
    for ($i = 0; $i < 7; $i++) $k .= $a[random_int(0, 35)];
    return $k . '=' . bin2hex(random_bytes(12));
}

function inst_steps(array $s) {
    $sub = ($s['mode'] === 'mirror') ? 'origin' : 'subpage';
    return ['env', 'scenario', 'panel', 'scopes', $sub, 'webhook', 'admin'];
}

function inst_step_title($id) {
    return ['env' => 'Окружение', 'scenario' => 'Сценарий', 'panel' => 'Панель', 'scopes' => 'Права токена', 'subpage' => 'Страница подписки', 'origin' => 'Origin', 'webhook' => 'Вебхук', 'admin' => 'Админ'][$id] ?? $id;
}

function inst_json_out($d) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit();
}

function inst_cookie_secure() { return (($_SERVER['HTTPS'] ?? '') === 'on') || (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'); }

function inst_panel_conn(array $s) {
    $p = $s['panel'];
    return ['url' => $p['url'], 'token' => $p['token'], 'cookie' => $p['cookie'], 'xkey' => $p['xkey'], 'tls' => !empty($p['tls'])];
}

function inst_post($k) { return trim((string) ($_POST[$k] ?? '')); }

// ---- страница «только по домену» ----
$self = inst_self();
if ($self['is_local']) {
    http_response_code(403);
    header('Cache-Control: no-store');
    ?><!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow"><title>Прослойка подписки</title>
    <style>body{margin:0;font:15px/1.5 system-ui,sans-serif;background:#0f1115;color:#e6e8ee;display:flex;min-height:100vh;align-items:center;justify-content:center;padding:24px}main{max-width:620px}h1{font-size:1.25rem;margin:0 0 .6rem}code{background:#1b1f27;padding:.1rem .4rem;border-radius:6px}p{margin:.5rem 0}ol{padding-left:1.2rem}</style></head><body><main>
    <h1>Прослойка работает только за реверс-прокси по домену</h1>
    <p>Вы открыли её напрямую по адресу <code><?= h($self['host']) ?></code>. Так мастер установки не запускается: клиентам нужен HTTPS, а прослойке — настоящее имя домена.</p>
    <p>Если вы открыли по домену, а видите это — реверс-прокси не передаёт заголовок <code>Host</code>: в nginx добавьте <code>proxy_set_header Host $host;</code> (и <code>X-Forwarded-Proto $scheme;</code>, <code>X-Forwarded-For $proxy_add_x_forwarded_for;</code>).</p>
    <ol>
        <li>Направьте домен подписки на прослойку: в реверс-прокси сделайте <code>proxy_pass</code> / <code>reverse_proxy</code> на <code>127.0.0.1:8080</code> (порт из docker-compose.yml). Для установщика панели: Caddy — <code>SUB_BACKEND_URL=127.0.0.1:8080</code>, nginx — <code>upstream json { server 127.0.0.1:8080; }</code>.</li>
        <li>Откройте <code>https://&lt;домен&gt;/admin/?…</code> по ссылке установки из <code>docker compose logs</code> (или из <code>data/install.json</code>, поле <code>key</code>).</li>
    </ol>
    </main></body></html><?php
    exit();
}

// ---- ключ установки: без него /admin/ отдаёт приманку ----
$S = inst_state_load();
if ($S['key'] === '' || strpos($S['key'], '=') === false) {
    $S['key'] = inst_key_new();
    inst_state_save($S);
    error_log('submw: ссылка установки: ' . $self['base'] . '/admin/?' . $S['key']);
}
[$gk, $gv] = explode('=', $S['key'], 2);
if (isset($_GET[$gk]) && is_string($_GET[$gk]) && hash_equals($gv, $_GET[$gk])) {
    setcookie($gk, $gv, ['expires' => time() + 86400 * 365, 'path' => '/', 'httponly' => true, 'samesite' => 'Strict', 'secure' => inst_cookie_secure()]);
    header('Location: index.php');
    exit();
}
if (!isset($_COOKIE[$gk]) || !hash_equals($gv, (string) $_COOKIE[$gk])) {
    landing_render();
    exit();
}
$csrf_ok = isset($_POST['_g']) && hash_equals($S['key'], (string) $_POST['_g']);

// ---- AJAX-проверки ----
if (isset($_GET['inst']) && $_GET['inst'] === 'probe') {
    if (!$csrf_ok) inst_json_out(['ok' => false, 'msg' => 'Страница устарела — обновите её.']);
    $what = inst_post('what');
    if ($what === 'panel') {
        $u = inst_url_parse(inst_post('url'));
        $c = ['url' => $u['ok'] ? $u['url'] : inst_post('url'), 'token' => inst_post('token'), 'cookie' => inst_post('cookie'), 'xkey' => inst_post('xkey'), 'tls' => inst_post('tls') !== '0'];
        $from_link = false;
        if ($c['cookie'] === '' && $u['ok'] && $u['cookie_hint'] !== '') { $c['cookie'] = $u['cookie_hint']; $from_link = true; }
        $r = inst_panel_probe($c, $S['scenario'] === 'panel' ? 'panel' : 'standalone');
        $r['url_norm'] = $u['ok'] ? $u['url'] : '';
        $r['url_note'] = $u['ok'] ? $u['note'] : '';
        $r['cookie_used'] = $c['cookie'];
        $r['cookie_from_link'] = $from_link;
        $r['token_warn'] = inst_token_shape($c['token']);
        if ($r['ok']) {
            $S['panel'] = array_merge($S['panel'], ['url' => $c['url'], 'token' => $c['token'], 'cookie' => $c['cookie'], 'xkey' => $c['xkey'], 'tls' => $c['tls'] ? 1 : 0, 'version' => $r['version'], 'st' => 'ok']);
            $cfg = inst_panel_config($c, $r['version']);
            $S['panel']['sub_public_domain'] = $cfg['sub_public_domain'];
            $S['panel']['webhook'] = $cfg['webhook'];
            $fu = inst_first_user($c);
            $S['user_short'] = (string) ($fu['short'] ?? '');
            $S['user_name'] = (string) ($fu['username'] ?? '');
            $S['scopes'] = [];
            inst_state_save($S);
            $r['sub_public_domain'] = $cfg['sub_public_domain'];
            $r['self_host'] = $self['bare'];
        }
        inst_json_out($r);
    }
    if ($what === 'scopes') {
        $r = inst_scopes_probe(inst_panel_conn($S), ['subpage' => $S['mode'] === 'panel' && !empty($S['subpage']['enabled'])]);
        $S['scopes'] = ['ok' => $r['ok'], 'missing' => $r['missing'], 'ts' => time()];
        inst_state_save($S);
        inst_json_out($r);
    }
    if ($what === 'subpage') {
        $r = inst_subpage_probe(inst_post('url'), $S['user_short']);
        $r['user'] = $S['user_name'];
        if ($r['ok']) { $S['subpage']['url'] = $r['url']; $S['subpage']['st'] = $r['st']; inst_state_save($S); }
        inst_json_out($r);
    }
    if ($what === 'origin') {
        $o = inst_origin_parse(inst_post('raw'));
        if (!$o['ok']) inst_json_out(['ok' => false, 'st' => 'url', 'msg' => $o['err']]);
        $ph = inst_url_parse($S['panel']['url']);
        $r = inst_origin_probe($o, !empty($S['panel']['tls']), $self['bare'], $ph['ok'] ? $ph['host'] : '');
        $r += ['domain' => $o['domain'], 'prefix' => $o['prefix'], 'short' => $o['short']];
        if ($r['ok']) { $S['origin'] = array_merge($S['origin'], ['raw' => inst_post('raw'), 'domain' => $o['domain'], 'prefix' => $o['prefix'], 'short' => $o['short'], 'st' => $r['st'], 'browser' => $r['browser']]); inst_state_save($S); }
        inst_json_out($r);
    }
    if ($what === 'db') inst_json_out(inst_db_probe($S['db']));
    inst_json_out(['ok' => false, 'msg' => 'Неизвестная проверка']);
}

// ---- переходы между шагами ----
$err = '';
$steps = inst_steps($S);
if (!in_array($S['step'], $steps, true)) $S['step'] = 'env';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['act'])) {
    if (!$csrf_ok) {
        $err = 'Страница устарела — обновите её и повторите.';
    } elseif ($_POST['act'] === 'back') {
        $i = array_search($S['step'], $steps, true);
        if ($i > 0) $S['step'] = $steps[$i - 1];
        inst_state_save($S);
        header('Location: index.php'); exit();
    } elseif ($_POST['act'] === 'goto') {
        $to = inst_post('to');
        $i = array_search($S['step'], $steps, true);
        $j = array_search($to, $steps, true);
        if ($j !== false && $j <= $i) { $S['step'] = $to; inst_state_save($S); }
        header('Location: index.php'); exit();
    } elseif ($_POST['act'] === 'next') {
        $cur = $S['step'];
        if ($cur === 'env') {
            $dbr = inst_db_probe($S['db']);
            if (!$dbr['ok']) $err = $dbr['msg'];
        } elseif ($cur === 'scenario') {
            $sc = inst_post('scenario');
            $md = inst_post('mode');
            if (!in_array($sc, ['panel', 'standalone', 'host'], true)) $err = 'Выберите сценарий.';
            else {
                $S['scenario'] = $sc;
                $S['mode'] = ($sc !== 'panel' && $md === 'mirror') ? 'mirror' : 'panel';
                if ($sc === 'panel' && $S['panel']['url'] === '') $S['panel']['url'] = 'http://remnawave:3000';
                if ($sc === 'panel' && $S['subpage']['url'] === '') $S['subpage']['url'] = 'http://remnawave-subscription-page:3010';
                if ($sc !== 'panel' && $S['subpage']['url'] === '') $S['subpage']['url'] = 'http://remnawave-subscription-page:3010';
                $steps = inst_steps($S);
            }
        } elseif ($cur === 'panel') {
            $u = inst_url_parse(inst_post('url'));
            $c = ['url' => $u['ok'] ? $u['url'] : '', 'token' => inst_post('token'), 'cookie' => inst_post('cookie'), 'xkey' => inst_post('xkey'), 'tls' => inst_post('tls') !== '0'];
            if ($c['cookie'] === '' && $u['ok'] && $u['cookie_hint'] !== '') $c['cookie'] = $u['cookie_hint'];
            $r = $u['ok'] ? inst_panel_probe($c, $S['scenario'] === 'panel' ? 'panel' : 'standalone') : ['ok' => false, 'msg' => $u['err']];
            if (!$r['ok']) $err = $r['msg'] . (!empty($r['hint']) ? ' ' . $r['hint'] : '');
            else {
                $changed = $S['panel']['url'] !== $c['url'] || $S['panel']['token'] !== $c['token'];
                $S['panel'] = array_merge($S['panel'], ['url' => $c['url'], 'token' => $c['token'], 'cookie' => $c['cookie'], 'xkey' => $c['xkey'], 'tls' => $c['tls'] ? 1 : 0, 'version' => $r['version'], 'st' => 'ok']);
                $cfg = inst_panel_config($c, $r['version']);
                $S['panel']['sub_public_domain'] = $cfg['sub_public_domain'];
                $S['panel']['webhook'] = $cfg['webhook'];
                $fu = inst_first_user($c);
                $S['user_short'] = (string) ($fu['short'] ?? '');
                $S['user_name'] = (string) ($fu['username'] ?? '');
                if ($changed) $S['scopes'] = [];
            }
        } elseif ($cur === 'scopes') {
            $r = inst_scopes_probe(inst_panel_conn($S), ['subpage' => $S['mode'] === 'panel' && !empty($S['subpage']['enabled'])]);
            $S['scopes'] = ['ok' => $r['ok'], 'missing' => $r['missing'], 'ts' => time()];
            if (!$r['ok']) $err = $r['msg'];
        } elseif ($cur === 'subpage') {
            $S['subpage']['enabled'] = inst_post('enabled') === '1' ? 1 : 0;
            if ($S['subpage']['enabled']) {
                $u = inst_url_parse(inst_post('url'));
                if (!$u['ok']) $err = $u['err'];
                else {
                    $r = inst_subpage_probe($u['url'], $S['user_short']);
                    $S['subpage']['url'] = $u['url'];
                    $S['subpage']['st'] = $r['st'];
                    if (!$r['ok']) $S['pending']['subpage'] = $r['msg'];
                    else unset($S['pending']['subpage']);
                }
            } else {
                unset($S['pending']['subpage']);
            }
        } elseif ($cur === 'origin') {
            $o = inst_origin_parse(inst_post('raw'));
            if (!$o['ok']) $err = $o['err'];
            else {
                $ph = inst_url_parse($S['panel']['url']);
                $r = inst_origin_probe($o, !empty($S['panel']['tls']), $self['bare'], $ph['ok'] ? $ph['host'] : '');
                if (in_array($r['st'], ['loop', 'panel'], true)) $err = $r['msg'] . ' ' . $r['hint'];
                else {
                    $S['origin'] = array_merge($S['origin'], ['raw' => inst_post('raw'), 'domain' => $o['domain'], 'prefix' => $o['prefix'], 'short' => $o['short'], 'st' => $r['st'], 'browser' => $r['browser'], 'mirror_page' => inst_post('mirror_page') === '1' ? 1 : 0]);
                    if (!$r['ok']) $S['pending']['origin'] = $r['msg']; else unset($S['pending']['origin']);
                }
            }
        } elseif ($cur === 'webhook') {
            $sec = inst_post('secret');
            if (!inst_webhook_secret_ok($sec)) $err = 'Секрет вебхука — ровно 64 символа, только латиница и цифры.';
            else $S['webhook_secret'] = $sec;
            if ($S['panel']['webhook'] === false) $S['pending']['webhook'] = 'В панели вебхуки выключены — пропишите WEBHOOK_* в .env панели.';
            else unset($S['pending']['webhook']);
        } elseif ($cur === 'admin') {
            $au = inst_post('admin_user');
            $ap = (string) ($_POST['admin_pass'] ?? '');
            $ap2 = (string) ($_POST['admin_pass2'] ?? '');
            if ($au === '' || $ap === '') $err = 'Заполните логин и пароль.';
            elseif ($ap !== $ap2) $err = 'Пароли не совпадают.';
            elseif (strlen($ap) < 8) $err = 'Пароль слишком короткий (минимум 8 символов).';
            else {
                $S['admin']['user'] = $au;
                $fin = inst_finalize($S, $ap, $self);
                if ($fin['ok']) { inst_render_done($S, $fin, $self); exit(); }
                $err = $fin['err'];
            }
        }
        if ($err === '') {
            $i = array_search($cur, $steps, true);
            if ($i !== false && $i < count($steps) - 1) $S['step'] = $steps[$i + 1];
            inst_state_save($S);
            header('Location: index.php'); exit();
        }
        inst_state_save($S);
    }
}

// ---- запись БД и config.php ----
function inst_finalize(array &$S, $pass, array $self) {
    $db = $S['db'];
    $ce = '';
    $pdo = pdo_connect($db, $ce);
    if ($pdo === null) return ['ok' => false, 'err' => 'Не удалось подключиться к БД: ' . $ce];
    $drv = $db['driver'] ?? 'sqlite';
    try {
        foreach (install_statements($drv) as $sql) $pdo->exec($sql);
        $set = function ($k, $v) use ($pdo, $drv) {
            $st = $drv === 'mysql'
                ? $pdo->prepare('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)')
                : $pdo->prepare('INSERT INTO settings (k, v) VALUES (?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v');
            $st->execute([$k, (string) $v]);
        };
        $mirror = $S['mode'] === 'mirror';
        $set('sub_source', $mirror ? 'mirror' : 'panel');
        $set('target_domain', $mirror ? $S['origin']['domain'] : '');
        $set('mirror_domain', $self['host']);
        $set('remnawave_url', $S['panel']['url']);
        $set('remnawave_api_key', $S['panel']['token']);
        $set('remnawave_cookie', $S['panel']['cookie']);
        $set('remnawave_xapikey', $S['panel']['xkey']);
        $set('tls_verify', !empty($S['panel']['tls']) ? '1' : '0');
        $set('webhook_secret', $S['webhook_secret']);
        if (!$mirror && !empty($S['subpage']['enabled']) && $S['subpage']['url'] !== '') $set('subpage_external_url', rtrim($S['subpage']['url'], '/'));
        if ($mirror) {
            $set('subpage_mirror', !empty($S['origin']['mirror_page']) ? '1' : '0');
            if ($S['origin']['prefix'] !== '') { $set('sub_prefix_enabled', '1'); $set('sub_prefix', $S['origin']['prefix']); }
        }
        $set('admin_gate_key', $S['key']);
        $set('install_scenario', $S['scenario']);
        if (!empty($S['pending'])) $set('install_pending', json_encode($S['pending'], JSON_UNESCAPED_UNICODE));
        if ($S['panel']['version'] !== '') $set('panelmeta_json', json_encode(['ok' => true, 'ts' => time(), 'cached' => false, 'age' => 0, 'error' => '', 'version' => $S['panel']['version'], 'build_time' => '', 'build_number' => '', 'commit' => '', 'branch' => '', 'commit_url' => ''], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        if ($S['panel']['version'] !== '') $set('panelmeta_ts', (string) time());
    } catch (Throwable $e) {
        return ['ok' => false, 'err' => 'Ошибка создания таблиц: ' . $e->getMessage()];
    }
    $conf = ['installed' => true, 'db' => $db, 'admin_user' => $S['admin']['user'], 'admin_pass_hash' => password_hash($pass, PASSWORD_DEFAULT)];
    $php = "<?php\nreturn " . var_export($conf, true) . ";\n";
    if (@file_put_contents(config_path(), $php) === false) {
        return ['ok' => false, 'err' => 'Таблицы созданы, но не удалось записать ' . config_path() . ' — проверьте права на каталог.'];
    }
    @chmod(config_path(), 0640);
    @unlink(inst_state_path());
    // Сквозной тест уже на рабочей прослойке: подписка первого пользователя через свой домен.
    $prefix = $S['mode'] === 'mirror' ? $S['origin']['prefix'] : '';
    $e2e = inst_e2e($self['base'], $S['user_short'], $prefix, !empty($S['panel']['tls']));
    return ['ok' => true, 'e2e' => $e2e];
}

// ---- рендер ----
function inst_head($title) {
    ?><!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="color-scheme" content="light dark">
    <meta name="robots" content="noindex, nofollow">
    <title><?= h($title) ?></title>
    <script>(function(){try{var t=localStorage.getItem('submw_theme');if(!t||t==='system')t=matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light';document.documentElement.setAttribute('data-theme',t);}catch(e){document.documentElement.setAttribute('data-theme','dark');}})();</script>
    <link rel="icon" href="assets/icon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/fonts.css?v=<?= substr(@md5_file(__DIR__ . '/../assets/fonts.css') ?: '0', 0, 10) ?>">
    <link rel="stylesheet" href="assets/admin.css?v=<?= substr(@md5_file(__DIR__ . '/../assets/admin.css') ?: '0', 0, 10) ?>">
    <style>
        html[data-theme=light]{color-scheme:light}
        html[data-theme=dark],html[data-theme=black]{color-scheme:dark}
        body{background:radial-gradient(120% 50% at 50% 0%,var(--accent-light),transparent 60%),var(--bg)}
        .wrap{max-width:720px;margin:0 auto;padding:max(28px,env(safe-area-inset-top)) max(16px,env(safe-area-inset-right)) max(32px,env(safe-area-inset-bottom)) max(16px,env(safe-area-inset-left))}
        .in-h{display:flex;align-items:center;gap:.8rem;margin:0 0 1rem}
        .in-logo{width:44px;height:44px;border-radius:12px;background:var(--accent-light);color:var(--accent);display:flex;align-items:center;justify-content:center;flex:none}
        .in-h h1{font-size:1.3rem;margin:0;color:var(--text-strong);line-height:1.25}
        .in-h p{margin:.15rem 0 0;color:var(--muted);font-size:.86rem}
        .in-steps{display:flex;flex-wrap:wrap;gap:.35rem;margin:0 0 1rem;padding:0;list-style:none}
        .in-steps li{font-size:.78rem;padding:.3rem .65rem;border-radius:999px;border:1px solid var(--line);color:var(--muted);background:var(--card)}
        .in-steps li.done{color:var(--c-ok-fg);background:var(--c-ok-bg);border-color:transparent}
        .in-steps li.cur{color:var(--accent-text);background:var(--accent-light);border-color:var(--accent);font-weight:600}
        .in-steps li button{all:unset;cursor:pointer}
        .card h2{font-size:1.05rem;margin:0 0 .5rem}
        .card .muted{font-size:.84rem}
        .warn{border-radius:12px;white-space:pre-wrap}
        .in-nav{display:flex;gap:.6rem;justify-content:space-between;align-items:center;margin-top:1rem;flex-wrap:wrap}
        .in-go{min-height:46px;font-size:1rem;border-radius:12px;padding:0 1.4rem}
        .in-go:disabled{opacity:.55;cursor:default}
        input[type=text],input[type=password],select{min-height:44px}
        @media(max-width:900px){input,select{font-size:16px!important}}
        .st{display:flex;gap:.6rem;align-items:flex-start;padding:.65rem .8rem;border-radius:10px;margin:.6rem 0;font-size:.88rem;line-height:1.45;background:var(--bg2);border:1px solid var(--line)}
        .st svg{flex:none;width:18px;height:18px;margin-top:.1rem}
        .st.ok{background:var(--c-ok-bg);color:var(--c-ok-fg);border-color:transparent}
        .st.bad{background:var(--c-bad-bg);color:var(--c-bad-fg);border-color:transparent}
        .st.warn{background:var(--c-warn-bg);color:var(--c-warn-fg);border-color:transparent;white-space:normal}
        .st.info{background:var(--c-info-bg);color:var(--c-info-fg);border-color:transparent}
        .st .hint{display:block;margin-top:.25rem;font-size:.82rem;opacity:.9}
        .sc{display:grid;gap:.6rem;grid-template-columns:1fr}
        .sc label{display:flex;gap:.7rem;align-items:flex-start;border:1px solid var(--line);border-radius:12px;padding:.8rem .9rem;cursor:pointer;background:var(--card)}
        .sc label:has(input:checked){border-color:var(--accent);background:var(--accent-light)}
        .sc input{margin-top:.25rem}
        .sc b{display:block;color:var(--text-strong)}
        .sc span{font-size:.82rem;color:var(--muted)}
        .env{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:.5rem;margin:.6rem 0}
        .env div{border:1px solid var(--line);border-radius:10px;padding:.55rem .7rem;font-size:.84rem;background:var(--bg2)}
        .env div b{display:block;font-size:.76rem;color:var(--muted);font-weight:500}
        .ts-tbl{width:100%;border-collapse:collapse;margin-top:.6rem;font-size:.85rem}
        .ts-tbl td{padding:.4rem .5rem;border-top:1px solid var(--line);vertical-align:top}
        .ts-tbl .tag{white-space:nowrap}
        .row2{display:grid;grid-template-columns:1fr 1fr;gap:.6rem}
        @media(max-width:640px){.row2{grid-template-columns:1fr}}
        .codeblk{position:relative;margin:.5rem 0}
        .codeblk pre{margin:0;padding:.7rem .8rem;border-radius:10px;background:var(--bg2);border:1px solid var(--line);font-size:.8rem;overflow:auto;white-space:pre-wrap;word-break:break-all}
        .copybtn{position:absolute;top:.4rem;right:.4rem;min-height:0;padding:.2rem .55rem;font-size:.74rem}
        .in-link{font-family:ui-monospace,monospace;font-size:.9rem;word-break:break-all}
        .chk-inline{display:flex;align-items:center;gap:.5rem;margin:.6rem 0;font-size:.88rem}
        .chk-inline input{width:18px;height:18px;margin:0}
    </style></head><body><div class="wrap">
    <div class="in-h"><div class="in-logo"><svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2l8 3v6c0 5-3.5 8.5-8 10-4.5-1.5-8-5-8-10V5z"/></svg></div><div><h1>Установка прослойки подписки</h1><p>Мастер проверяет каждый шаг — дальше всё настраивается в админке</p></div></div>
    <?php
}

function inst_foot() { echo '</div></body></html>'; }

function inst_icon($kind) {
    $p = ['ok' => '<path d="M20 6 9 17l-5-5"/>', 'bad' => '<circle cx="12" cy="12" r="10"/><path d="M15 9l-6 6M9 9l6 6"/>', 'warn' => '<path d="M12 9v4M12 17h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/>', 'info' => '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/>'][$kind] ?? '';
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
}

function inst_st($kind, $msg, $hint = '') {
    echo '<div class="st ' . h($kind) . '">' . inst_icon($kind) . '<div>' . h($msg) . ($hint !== '' ? '<span class="hint">' . h($hint) . '</span>' : '') . '</div></div>';
}

function inst_render_done(array $S, array $fin, array $self) {
    $e2e = $fin['e2e'];
    $link = $self['base'] . '/admin/?' . $S['key'];
    inst_head('Установка завершена');
    ?>
    <div class="card">
        <h2>Готово — прослойка установлена</h2>
        <?php if ($e2e['ok']): ?>
            <?php inst_st('ok', 'Сквозная проверка прошла: подписка пользователя ' . ($S['user_name'] !== '' ? '«' . $S['user_name'] . '» ' : '') . 'отдаётся через ' . $self['host'] . '.'); ?>
        <?php elseif ($e2e['st'] === 'nouser'): ?>
            <?php inst_st('info', $e2e['msg'], 'Создайте пользователя в панели и проверьте его ссылку подписки в клиенте.'); ?>
        <?php else: ?>
            <?php inst_st('warn', 'Сквозная проверка не прошла: ' . $e2e['msg'], ($e2e['hint'] !== '' ? $e2e['hint'] . ' ' : '') . 'Повторить можно на вкладке «Подключение».'); ?>
        <?php endif; ?>
        <?php if (!empty($S['pending'])): ?>
            <?php inst_st('warn', 'Остались предупреждения: ' . implode('; ', array_map(fn($k, $v) => inst_step_title($k) . ' — ' . $v, array_keys($S['pending']), $S['pending'])), 'Они будут показаны после входа, пока не закроете.'); ?>
        <?php endif; ?>
    </div>
    <div class="card">
        <h2>Ссылка для входа в админку</h2>
        <p class="muted">Без неё адрес <code>/admin/</code> показывает ту же страницу-приманку, что и корень сайта. Сохраните ссылку — она же выдаёт доступ новому браузеру и телефону. Перевыпустить её можно на вкладке «Подключение» или командой <code>php tools/admin-gate.php</code>.</p>
        <div class="codeblk"><button type="button" class="copybtn" onclick="var b=this;navigator.clipboard&&navigator.clipboard.writeText(b.nextElementSibling.textContent).then(function(){b.textContent='Скопировано';setTimeout(function(){b.textContent='Копировать';},1500);});">Копировать</button><pre class="in-link"><?= h($link) ?></pre></div>
        <?php if ($S['scenario'] === 'panel' || ($S['scenario'] !== 'panel' && $S['mode'] === 'panel')): ?>
        <p class="muted">Если домен подписки ещё не переключён на прослойку: Caddy (установщик панели) — <code>SUB_BACKEND_URL=127.0.0.1:8080</code> у контейнера Caddy и перезапуск; nginx — <code>upstream json { server 127.0.0.1:8080; }</code> и <code>nginx -s reload</code>.</p>
        <?php endif; ?>
        <form method="get" action="index.php"><button type="submit" class="in-go" style="width:100%">Войти в админку</button></form>
    </div>
    <?php
    inst_foot();
}

$step = $S['step'];
$env = inst_env_report();
$G = $S['key'];
inst_head('Установка прослойки');
?>
<ul class="in-steps"><?php foreach ($steps as $i => $id): $ci = array_search($step, $steps, true); $cls = $i < $ci ? 'done' : ($i === $ci ? 'cur' : ''); ?><li class="<?= $cls ?>"><?php if ($i < $ci): ?><form method="post" style="display:inline"><input type="hidden" name="_g" value="<?= h($G) ?>"><input type="hidden" name="act" value="goto"><input type="hidden" name="to" value="<?= h($id) ?>"><button type="submit"><?= h(inst_step_title($id)) ?></button></form><?php else: ?><?= h(inst_step_title($id)) ?><?php endif; ?></li><?php endforeach; ?></ul>
<?php if ($err !== ''): ?><div class="warn" role="alert"><?= h($err) ?></div><?php endif; ?>
<form method="post" id="inst" autocomplete="off">
<input type="hidden" name="_g" value="<?= h($G) ?>">
<input type="hidden" name="act" value="next">
<?php if ($step === 'env'): ?>
    <div class="card">
        <h2>Окружение</h2>
        <div class="env">
            <div><b>Домен прослойки</b><?= h($self['host']) ?> <span class="muted">(<?= h($self['scheme']) ?>)</span></div>
            <div><b>PHP</b><?= h($env['php']) ?> · <?= h($env['sapi']) ?></div>
            <div><b>Запуск</b><?= $env['docker'] ? 'Docker' . ($env['image'] !== '' ? ', образ ' . h($env['image']) : '') : 'файлы на сервере' ?></div>
            <div><b>База данных</b><?= ($S['db']['driver'] ?? 'sqlite') === 'mysql' ? 'MySQL · ' . h($S['db']['host'] ?? '') . ' / ' . h($S['db']['name'] ?? '') : 'SQLite · data/submw.sqlite' ?></div>
        </div>
        <?php foreach (['curl' => 'запросы к панели', 'pdo_sqlite' => 'SQLite', 'pdo_mysql' => 'MySQL', 'mbstring' => 'строки', 'dom' => 'разбор страниц', 'sodium' => 'защищённый канал приложения'] as $e => $why): if (empty($env['ext'][$e])): ?>
            <?php inst_st(in_array($e, ['curl', 'mbstring'], true) || ($e === 'pdo_sqlite' && ($S['db']['driver'] ?? 'sqlite') === 'sqlite') || ($e === 'pdo_mysql' && ($S['db']['driver'] ?? '') === 'mysql') ? 'bad' : 'warn', 'Нет PHP-расширения ' . $e . ' (' . $why . ').'); ?>
        <?php endif; endforeach; ?>
        <?php if (!$env['data_ok']): ?><?php inst_st('bad', 'Каталог data/ недоступен для записи: ' . $env['data_dir'], 'Прослойка хранит там БД, конфиг и сессии.'); ?><?php endif; ?>
        <?php if ($self['scheme'] !== 'https'): ?><?php inst_st('warn', 'Мастер открыт по http. Клиентам подписки нужен https — проверьте, что реверс отдаёт сертификат и передаёт X-Forwarded-Proto.'); ?><?php endif; ?>
        <div id="dbSt"></div>
        <div class="in-nav"><span class="muted">Проверка БД выполняется при переходе дальше.</span><button type="submit" class="in-go">Далее</button></div>
    </div>
<?php elseif ($step === 'scenario'): ?>
    <div class="card">
        <h2>Как установлена прослойка</h2>
        <?php $sc = $S['scenario'] !== '' ? $S['scenario'] : ($env['docker'] ? 'panel' : 'host'); if ($env['scenario'] !== '' && $S['scenario'] === '') $sc = $env['scenario'] === 'standalone' ? 'standalone' : 'panel'; ?>
        <div class="sc">
            <label><input type="radio" name="scenario" value="panel" <?= $sc === 'panel' ? 'checked' : '' ?>><div><b>Рядом с панелью</b><span>Контейнер в docker-сети панели. Прослойка сама отдаёт подписку с панели по внутреннему адресу, страницу в браузере — через subscription-page панели.</span></div></label>
            <label><input type="radio" name="scenario" value="standalone" <?= $sc === 'standalone' ? 'checked' : '' ?>><div><b>Отдельный сервер</b><span>Панель на другом сервере, прослойка ходит к ней по публичному адресу.</span></div></label>
            <?php if (!$env['docker']): ?><label><input type="radio" name="scenario" value="host" <?= $sc === 'host' ? 'checked' : '' ?>><div><b>Файловая установка на сервере</b><span>nginx + php-fpm без Docker (install.sh).</span></div></label><?php endif; ?>
        </div>
        <div id="modeBox" style="margin-top:.9rem">
            <h2 style="font-size:.95rem">Что делает прослойка</h2>
            <div class="sc">
                <label><input type="radio" name="mode" value="panel" <?= $S['mode'] !== 'mirror' ? 'checked' : '' ?>><div><b>Стоит на домене подписки</b><span>Домен подписки (SUB_PUBLIC_DOMAIN панели) ведёт на прослойку; ссылки пользователей не меняются. Нужны адрес панели и API-токен.</span></div></label>
                <label><input type="radio" name="mode" value="mirror" <?= $S['mode'] === 'mirror' ? 'checked' : '' ?>><div><b>Зеркало</b><span>У прослойки свой домен, подписки она берёт с домена подписки панели (origin). Пользователям выдаются ссылки на зеркало.</span></div></label>
            </div>
        </div>
        <div class="in-nav"><button type="submit" class="btn ghost" name="act" value="back">Назад</button><button type="submit" class="in-go">Далее</button></div>
    </div>
    <script>(function(){var r=document.querySelectorAll('input[name=scenario]'),m=document.getElementById('modeBox');function s(){var v=document.querySelector('input[name=scenario]:checked');m.style.display=(v&&v.value==='panel')?'none':'';}r.forEach(function(x){x.addEventListener('change',s);});s();})();</script>
<?php elseif ($step === 'panel'): ?>
    <div class="card">
        <h2>Панель Remnawave</h2>
        <p class="muted"><?= $S['scenario'] === 'panel' ? 'Внутренний адрес панели в docker-сети, обычно <code>http://remnawave:3000</code>.' : 'Публичный адрес панели со схемой. Можно вставить ссылку входа из вывода установщика панели (<code>https://panel…/auth/login?имя=значение</code>) — защитная cookie подставится сама.' ?></p>
        <label>Адрес панели</label>
        <input name="url" id="pUrl" type="text" inputmode="url" autocapitalize="none" autocorrect="off" spellcheck="false" value="<?= h($S['panel']['url']) ?>" placeholder="<?= $S['scenario'] === 'panel' ? 'http://remnawave:3000' : 'https://panel.example.com' ?>">
        <label>API-токен</label>
        <input name="token" id="pTok" type="text" autocapitalize="none" autocorrect="off" spellcheck="false" value="<?= h($S['panel']['token']) ?>" placeholder="eyJhbGciOi…">
        <p class="muted">Панель → Settings → API Tokens → создать. Проще выдать все права; минимум — проверится на следующем шаге.</p>
        <details <?= ($S['panel']['cookie'] !== '' || $S['panel']['xkey'] !== '' || empty($S['panel']['tls'])) ? 'open' : '' ?>><summary class="muted" style="cursor:pointer">Панель закрыта защитой (cookie eGames, X-Api-Key, самоподписанный сертификат)</summary>
            <label>Cookie панели (eGames / remnawave-reverse-proxy)</label>
            <input name="cookie" id="pCookie" type="text" autocapitalize="none" autocorrect="off" spellcheck="false" value="<?= h($S['panel']['cookie']) ?>" placeholder="имя=значение">
            <label>X-Api-Key (caddy-with-auth / Tiny Auth)</label>
            <input name="xkey" id="pXkey" type="text" autocapitalize="none" autocorrect="off" spellcheck="false" value="<?= h($S['panel']['xkey']) ?>">
            <label class="chk-inline"><input type="checkbox" name="tls" id="pTls" value="1" <?= !empty($S['panel']['tls']) ? 'checked' : '' ?>>Проверять TLS-сертификат панели</label>
            <input type="hidden" name="tls" value="0" id="pTlsOff" disabled>
        </details>
        <div id="pSt"></div>
        <div class="in-nav"><button type="submit" class="btn ghost" name="act" value="back">Назад</button><div style="display:flex;gap:.6rem"><button type="button" class="btn ghost" id="pCheck">Проверить</button><button type="submit" class="in-go" id="pNext" <?= $S['panel']['st'] === 'ok' ? '' : 'disabled' ?>>Далее</button></div></div>
    </div>
<?php elseif ($step === 'scopes'): ?>
    <div class="card">
        <h2>Права API-токена</h2>
        <p class="muted">Панель <?= h($S['panel']['version']) ?>. Мастер спрашивает панель по каждому праву. Без отмеченных «нужно» прослойка не будет работать; остальные — для отдельных функций, их можно добавить позже.</p>
        <div id="sSt"><div class="st info"><?= inst_icon('info') ?><div>Проверяю…</div></div></div>
        <div class="in-nav"><button type="submit" class="btn ghost" name="act" value="back">Назад</button><div style="display:flex;gap:.6rem"><button type="button" class="btn ghost" id="sCheck">Проверить снова</button><button type="submit" class="in-go" id="sNext" disabled>Далее</button></div></div>
    </div>
<?php elseif ($step === 'subpage'): ?>
    <div class="card">
        <h2>Страница подписки в браузере</h2>
        <p class="muted">Когда ссылку открывают в браузере, прослойка показывает страницу контейнера <code>subscription-page</code>. Без него по ссылке придёт текст конфига.</p>
        <label class="chk-inline"><input type="checkbox" name="enabled" id="spOn" value="1" <?= !empty($S['subpage']['enabled']) ? 'checked' : '' ?>>Использовать subscription-page</label>
        <div id="spBox">
            <label>Адрес контейнера subscription-page</label>
            <input name="url" id="spUrl" type="text" inputmode="url" autocapitalize="none" autocorrect="off" spellcheck="false" value="<?= h($S['subpage']['url']) ?>" placeholder="http://remnawave-subscription-page:3010">
            <?php if ($S['scenario'] !== 'panel'): ?><p class="muted">На отдельном сервере subscription-page поднимается в том же docker-compose.yml: ему нужны <code>REMNAWAVE_PANEL_URL</code>, <code>REMNAWAVE_API_TOKEN</code> и, если панель за cookie-защитой, <code>EGAMES_COOKIE</code>.</p><?php endif; ?>
            <?php if ($S['panel']['sub_public_domain'] !== '' && strtolower($S['panel']['sub_public_domain']) !== $self['bare'] && strpos(strtolower($S['panel']['sub_public_domain']), $self['bare'] . '/') !== 0): ?>
                <?php inst_st('warn', 'В панели SUB_PUBLIC_DOMAIN = ' . $S['panel']['sub_public_domain'] . ', а прослойка открыта как ' . $self['bare'] . '.', 'Панель кладёт SUB_PUBLIC_DOMAIN в ссылки подписки — они должны вести на прослойку. Поправьте .env панели или откройте мастер по тому домену.'); ?>
            <?php endif; ?>
        </div>
        <div id="spSt"></div>
        <div class="in-nav"><button type="submit" class="btn ghost" name="act" value="back">Назад</button><div style="display:flex;gap:.6rem"><button type="button" class="btn ghost" id="spCheck">Проверить</button><button type="submit" class="in-go" id="spNext">Далее</button></div></div>
    </div>
<?php elseif ($step === 'origin'): ?>
    <div class="card">
        <h2>Origin — откуда зеркало берёт подписки</h2>
        <p class="muted">Вставьте ссылку подписки любого пользователя из панели (например <code>https://sub.example.com/abc123…</code>) — мастер определит домен, префикс и проверит, что origin отдаёт подписку. Можно указать и просто домен.</p>
        <label>Ссылка подписки или домен подписки панели</label>
        <input name="raw" id="oRaw" type="text" inputmode="url" autocapitalize="none" autocorrect="off" spellcheck="false" value="<?= h($S['origin']['raw']) ?>" placeholder="https://sub.example.com/shortUuid">
        <label class="chk-inline"><input type="checkbox" name="mirror_page" value="1" <?= (!isset($S['origin']['mirror_page']) || !empty($S['origin']['mirror_page'])) ? 'checked' : '' ?>>Страницу для браузера брать с origin (иначе в браузере придёт текст конфига)</label>
        <div id="oSt"></div>
        <div class="in-nav"><button type="submit" class="btn ghost" name="act" value="back">Назад</button><div style="display:flex;gap:.6rem"><button type="button" class="btn ghost" id="oCheck">Проверить</button><button type="submit" class="in-go" id="oNext">Далее</button></div></div>
    </div>
<?php elseif ($step === 'webhook'): ?>
    <?php $sec = $S['webhook_secret'] !== '' ? $S['webhook_secret'] : inst_webhook_secret_new(); ?>
    <div class="card">
        <h2>Вебхук панели</h2>
        <p class="muted">Панель присылает события по пользователям (истечение, лимиты, устройства) — на них держатся грейс и лог. Секрет — ровно 64 символа латиницы и цифр, такое требование у панели.</p>
        <label>Секрет вебхука</label>
        <input name="secret" type="text" autocapitalize="none" autocorrect="off" spellcheck="false" value="<?= h($sec) ?>">
        <?php if ($S['panel']['webhook'] === true): ?><?php inst_st('ok', 'В панели вебхуки включены.', 'Проверьте, что WEBHOOK_URL ведёт на ' . $self['base'] . '/webhook.php и секрет совпадает.'); ?>
        <?php elseif ($S['panel']['webhook'] === false): ?><?php inst_st('warn', 'В панели вебхуки выключены (WEBHOOK_ENABLED=false).', 'Пропишите строки ниже в .env панели и перезапустите её. Можно сделать позже — прослойка будет работать, но без событий панели.'); ?>
        <?php else: ?><?php inst_st('info', 'Панель старше 3.2.0 не сообщает состояние вебхуков — сверьте .env панели вручную.'); ?><?php endif; ?>
        <div class="codeblk"><button type="button" class="copybtn" onclick="var b=this;navigator.clipboard&&navigator.clipboard.writeText(b.nextElementSibling.textContent).then(function(){b.textContent='Скопировано';setTimeout(function(){b.textContent='Копировать';},1500);});">Копировать</button><pre id="whEnv">WEBHOOK_ENABLED=true
WEBHOOK_URL=<?= h($self['base']) ?>/webhook.php
WEBHOOK_SECRET_HEADER=<?= h($sec) ?></pre></div>
        <div class="in-nav"><button type="submit" class="btn ghost" name="act" value="back">Назад</button><button type="submit" class="in-go">Далее</button></div>
    </div>
    <script>document.querySelector('input[name=secret]').addEventListener('input',function(){var p=document.getElementById('whEnv');p.textContent=p.textContent.replace(/WEBHOOK_SECRET_HEADER=.*/, 'WEBHOOK_SECRET_HEADER='+this.value);});</script>
<?php elseif ($step === 'admin'): ?>
    <div class="card">
        <h2>Доступ в админку</h2>
        <p class="muted">После установки адрес <code>/admin/</code> открывается только с ссылкой-ключом (она показана сейчас в адресной строке и будет на последнем экране), затем — логин и пароль.</p>
        <label for="admin_user">Логин</label><input id="admin_user" name="admin_user" type="text" autocomplete="username" autocapitalize="none" autocorrect="off" spellcheck="false" value="<?= h($S['admin']['user']) ?>">
        <div class="row2">
            <div><label for="admin_pass">Пароль</label><input id="admin_pass" name="admin_pass" type="password" autocomplete="new-password"></div>
            <div><label for="admin_pass2">Повтор пароля</label><input id="admin_pass2" name="admin_pass2" type="password" autocomplete="new-password"></div>
        </div>
        <p class="muted">Минимум 8 символов.</p>
        <div class="in-nav"><button type="submit" class="btn ghost" name="act" value="back">Назад</button><button type="submit" class="in-go" id="aGo">Установить</button></div>
    </div>
<?php endif; ?>
</form>
<script>
(function(){
    var G = <?= json_encode($G, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var ICON = {ok:'<path d="M20 6 9 17l-5-5"/>', bad:'<circle cx="12" cy="12" r="10"/><path d="M15 9l-6 6M9 9l6 6"/>', warn:'<path d="M12 9v4M12 17h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/>', info:'<circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/>'};
    function st(kind, msg, hint){ var d=document.createElement('div'); d.className='st '+kind; d.innerHTML='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'+ICON[kind]+'</svg><div></div>'; var t=d.lastChild; t.appendChild(document.createTextNode(msg)); if(hint){var s=document.createElement('span');s.className='hint';s.textContent=hint;t.appendChild(s);} return d; }
    function probe(what, data){ var fd=new FormData(); fd.append('_g',G); fd.append('what',what); Object.keys(data||{}).forEach(function(k){fd.append(k,data[k]);}); return fetch('index.php?inst=probe',{method:'POST',body:fd,credentials:'same-origin'}).then(function(r){return r.json();}); }
    function busy(b, on, txt){ if(!b) return; if(on){ b.dataset.t=b.textContent; b.textContent=txt||'Проверяю…'; b.disabled=true; } else { b.textContent=b.dataset.t||b.textContent; b.disabled=false; } }
    var f = document.getElementById('inst');
    f.addEventListener('submit', function(){ var g=f.querySelector('.in-go'); if(g && !g.disabled) setTimeout(function(){ g.disabled=true; }, 0); });
    addEventListener('pageshow', function(e){ if(e.persisted){ var g=f.querySelector('.in-go'); if(g) g.disabled=false; } });

    var tls = document.getElementById('pTls');
    if (tls) {
        var off = document.getElementById('pTlsOff');
        function syncTls(){ off.disabled = tls.checked; }
        tls.addEventListener('change', syncTls); syncTls();
        var box = document.getElementById('pSt'), btn = document.getElementById('pCheck'), next = document.getElementById('pNext');
        function runPanel(){
            busy(btn, true); box.textContent='';
            probe('panel', {url: document.getElementById('pUrl').value, token: document.getElementById('pTok').value, cookie: document.getElementById('pCookie').value, xkey: document.getElementById('pXkey').value, tls: tls.checked ? '1' : '0'}).then(function(d){
                if (d.url_norm && d.url_norm !== document.getElementById('pUrl').value) { document.getElementById('pUrl').value = d.url_norm; if (d.url_note) box.appendChild(st('info', d.url_note)); }
                if (d.cookie_from_link) { document.getElementById('pCookie').value = d.cookie_used; document.querySelector('details').open = true; box.appendChild(st('info', 'Из ссылки взята защитная cookie: ' + d.cookie_used)); }
                if (d.token_warn) box.appendChild(st('warn', d.token_warn));
                if (d.fix && d.fix.url) { document.getElementById('pUrl').value = d.fix.url; }
                if (d.fix && d.fix.tls === 0) { document.querySelector('details').open = true; }
                box.appendChild(st(d.ok ? 'ok' : 'bad', d.msg || (d.ok ? 'OK' : 'Ошибка'), d.hint || ''));
                if (d.ok && d.sub_public_domain && d.self_host && d.sub_public_domain.toLowerCase() !== d.self_host && d.sub_public_domain.toLowerCase().indexOf(d.self_host + '/') !== 0) box.appendChild(st('warn', 'В панели SUB_PUBLIC_DOMAIN = ' + d.sub_public_domain + ', а прослойка открыта как ' + d.self_host + '.', 'Ссылки подписки панель строит по SUB_PUBLIC_DOMAIN — они должны вести на прослойку.'));
                next.disabled = !d.ok;
            }).catch(function(){ box.appendChild(st('bad', 'Сеть: проверка не выполнена.')); }).then(function(){ busy(btn, false); });
        }
        btn.addEventListener('click', runPanel);
        ['pUrl','pTok','pCookie','pXkey'].forEach(function(id){ document.getElementById(id).addEventListener('input', function(){ next.disabled = true; }); });
        if (document.getElementById('pUrl').value && document.getElementById('pTok').value && next.disabled) runPanel();
    }

    var sBox = document.getElementById('sSt');
    if (sBox) {
        var sBtn = document.getElementById('sCheck'), sNext = document.getElementById('sNext');
        var ST = {ok:['есть','normal'], miss:['нет','blocked'], na:['нет в этой версии','expired'], auth:['токен отклонён','blocked'], err:['нет ответа','DISABLED']};
        function runScopes(){
            busy(sBtn, true); sBox.textContent=''; sBox.appendChild(st('info','Проверяю…'));
            probe('scopes', {}).then(function(d){
                sBox.textContent='';
                if (d.msg) sBox.appendChild(st(d.ok ? 'warn' : 'bad', d.msg)); else sBox.appendChild(st('ok', 'Все нужные права есть.'));
                var opt = (d.rows||[]).filter(function(r){ return r.st==='miss' && !r.need; }).map(function(r){ return r.s; });
                if (d.ok && opt.length) sBox.appendChild(st('info', 'Ещё нет (по желанию): ' + opt.join(', '), 'Нужны для отдельных функций — добавить можно потом, проверка есть на вкладке «Подключение».'));
                if (d.missing && d.missing.length) { var cb=document.createElement('div'); cb.className='codeblk'; var pre=document.createElement('pre'); pre.textContent=JSON.stringify(d.missing); var b=document.createElement('button'); b.type='button'; b.className='copybtn'; b.textContent='Копировать'; b.addEventListener('click',function(){ navigator.clipboard&&navigator.clipboard.writeText(pre.textContent).then(function(){b.textContent='Скопировано';setTimeout(function(){b.textContent='Копировать';},1500);}); }); cb.appendChild(b); cb.appendChild(pre); sBox.appendChild(cb); }
                var t=document.createElement('table'); t.className='ts-tbl';
                (d.rows||[]).forEach(function(r){ var tr=document.createElement('tr'); var s=ST[r.st]||[r.st,'']; tr.innerHTML='<td><code></code></td><td></td><td class="'+(r.need?'':'muted')+'">'+(r.need?'нужно':'по желанию')+'</td><td><span class="tag '+s[1]+'"></span></td>'; tr.children[0].firstChild.textContent=r.s; tr.children[1].textContent=r.w; tr.children[3].firstChild.textContent=s[0]; t.appendChild(tr); });
                sBox.appendChild(t);
                sNext.disabled = !d.ok;
            }).catch(function(){ sBox.textContent=''; sBox.appendChild(st('bad','Сеть: проверка не выполнена.')); }).then(function(){ busy(sBtn, false); });
        }
        sBtn.addEventListener('click', runScopes);
        runScopes();
    }

    var spOn = document.getElementById('spOn');
    if (spOn) {
        var spBox=document.getElementById('spBox'), spSt=document.getElementById('spSt'), spBtn=document.getElementById('spCheck');
        function syncSp(){ spBox.style.display = spOn.checked ? '' : 'none'; spBtn.style.display = spOn.checked ? '' : 'none'; }
        spOn.addEventListener('change', syncSp); syncSp();
        function runSp(){ busy(spBtn,true); spSt.textContent=''; probe('subpage',{url:document.getElementById('spUrl').value}).then(function(d){ spSt.appendChild(st(d.ok?'ok':'warn', d.msg, d.hint||'')); if(!d.ok) spSt.appendChild(st('info','Можно продолжить: проверка повторится на вкладке «Подключение».')); }).catch(function(){ spSt.appendChild(st('bad','Сеть: проверка не выполнена.')); }).then(function(){ busy(spBtn,false); }); }
        spBtn.addEventListener('click', runSp);
        if (spOn.checked && document.getElementById('spUrl').value) runSp();
    }

    var oRaw = document.getElementById('oRaw');
    if (oRaw) {
        var oSt=document.getElementById('oSt'), oBtn=document.getElementById('oCheck');
        function runO(){ busy(oBtn,true); oSt.textContent=''; probe('origin',{raw:oRaw.value}).then(function(d){
            if (d.domain) oSt.appendChild(st('info', 'Домен: ' + d.domain + (d.prefix ? ' · префикс /' + d.prefix : '') + (d.short ? ' · shortUuid ' + d.short : '')));
            oSt.appendChild(st(d.ok ? 'ok' : (d.st==='loop'||d.st==='panel'||d.st==='url' ? 'bad' : 'warn'), d.msg, d.hint||''));
            if (d.ok && d.browser === false) oSt.appendChild(st('warn', 'На браузерный запрос origin не отдаёт HTML-страницу.', 'Галочку «страницу брать с origin» лучше снять — иначе в браузере будет ошибка.'));
        }).catch(function(){ oSt.appendChild(st('bad','Сеть: проверка не выполнена.')); }).then(function(){ busy(oBtn,false); }); }
        oBtn.addEventListener('click', runO);
        if (oRaw.value) runO();
    }
})();
</script>
<?php
inst_foot();
exit();
