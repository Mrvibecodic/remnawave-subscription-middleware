<?php
// Мастер установки. Подключается из admin/index.php, пока нет config.php. Состояние шагов — data/install.json.
require_once dirname(__DIR__, 2) . '/lib/install_probe.php';

function inst_state_path() { return dirname(__DIR__, 2) . '/data/install.json'; }

function inst_state_load() {
    $f = inst_state_path();
    $s = is_file($f) ? json_decode((string) @file_get_contents($f), true) : null;
    if (!is_array($s)) $s = [];
    // Прежние форматы install.json: entrypoint до мастера (remnawave_url, mode) и старый install.sh на хосте (target_domain).
    if (!isset($s['panel']) && (isset($s['remnawave_url']) || isset($s['target_domain']))) {
        $s['panel'] = ['url' => (string) ($s['remnawave_url'] ?? '')];
        if (!empty($s['subpage_external_url'])) $s['subpage'] = ['url' => (string) $s['subpage_external_url']];
        if (($s['mode'] ?? '') === 'panel') {
            if (empty($s['scenario'])) $s['scenario'] = 'panel';
        } elseif (!empty($s['target_domain'])) {
            $s['origin'] = ['raw' => (string) $s['target_domain']];
            $s['mode'] = 'mirror';
            if (empty($s['scenario']) && getenv('SUBMW_DOCKER') !== '1') $s['scenario'] = 'host';
        }
    }
    $s += ['key' => '', 'scenario' => '', 'mode' => 'panel', 'step' => 'start', 'panel' => [], 'scopes' => [], 'origin' => [], 'subpage' => [], 'webhook_secret' => '', 'admin' => [], 'db' => [], 'pending' => [], 'user_short' => '', 'user_name' => ''];
    if ($s['step'] === 'env' || $s['step'] === 'scenario') $s['step'] = 'start';
    if ($s['step'] === 'scopes') $s['step'] = 'panel';
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
    return ['start', 'panel', $s['mode'] === 'mirror' ? 'origin' : 'subpage', 'webhook', 'admin'];
}

function inst_step_title($id) {
    return ['start' => 'Начало', 'panel' => 'Панель', 'subpage' => 'Страница подписки', 'origin' => 'Домен подписки', 'webhook' => 'Вебхук', 'admin' => 'Пароль'][$id] ?? $id;
}

function inst_json_out($d) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit();
}

function inst_panel_conn(array $s) {
    $p = $s['panel'];
    return ['url' => $p['url'], 'token' => $p['token'], 'cookie' => $p['cookie'], 'xkey' => $p['xkey'], 'tls' => !empty($p['tls'])];
}

function inst_post($k) { return trim((string) ($_POST[$k] ?? '')); }

// Права subpage нужны токену только в standalone: там subscription-page из compose ходит в панель этим же токеном.
function inst_need_subpage(array $s) { return $s['scenario'] === 'standalone' && $s['mode'] === 'panel' && !empty($s['subpage']['enabled']); }

// Панель + права одним действием: мастер показывает это на одном экране.
function inst_panel_apply(array &$S, array $c, $scenario) {
    $r = inst_panel_probe($c, $scenario);
    if (!$r['ok']) return $r;
    $changed = $S['panel']['url'] !== $c['url'] || $S['panel']['token'] !== $c['token'];
    $S['panel'] = array_merge($S['panel'], ['url' => $c['url'], 'token' => $c['token'], 'cookie' => $c['cookie'], 'xkey' => $c['xkey'], 'tls' => $c['tls'] ? 1 : 0, 'version' => $r['version'], 'st' => 'ok']);
    $cfg = inst_panel_config($c, $r['version']);
    $S['panel']['sub_public_domain'] = $cfg['sub_public_domain'];
    $S['panel']['webhook'] = $cfg['webhook'];
    $fu = inst_first_user($c);
    $S['user_short'] = (string) ($fu['short'] ?? '');
    $S['user_name'] = (string) ($fu['username'] ?? '');
    if ($changed) $S['scopes'] = [];
    $sc = inst_scopes_probe($c, ['subpage' => inst_need_subpage($S)]);
    $S['scopes'] = ['ok' => $sc['ok'], 'missing' => $sc['missing'], 'ts' => time()];
    $r['scopes'] = $sc;
    $r['ok'] = $sc['ok'];
    return $r;
}

// ---- страница «только по домену» ----
$self = inst_self();
if ($self['is_local']) {
    http_response_code(403);
    header('Cache-Control: no-store');
    ?><!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow"><title>Прослойка подписки</title>
    <style>body{margin:0;font:15px/1.5 system-ui,sans-serif;background:#0f1115;color:#e6e8ee;display:flex;min-height:100vh;align-items:center;justify-content:center;padding:24px}main{max-width:620px}h1{font-size:1.25rem;margin:0 0 .6rem}code{background:#1b1f27;padding:.1rem .4rem;border-radius:6px;word-break:break-all}p{margin:.5rem 0}ol{padding-left:1.2rem}</style></head><body><main>
    <h1>Откройте прослойку по домену</h1>
    <p>Сейчас она открыта по адресу <code><?= h($self['real_host'] !== '' ? $self['real_host'] : $self['host']) ?></code>. Прослойка работает только через реверс-прокси по домену подписки — клиентам нужен HTTPS.</p>
    <ol>
        <li>В реверс-прокси направьте домен подписки на <code>127.0.0.1:8080</code>. Установщик панели: Caddy — <code>SUB_BACKEND_URL=127.0.0.1:8080</code>, nginx — <code>upstream json { server 127.0.0.1:8080; }</code>.</li>
        <li>Откройте ссылку мастера из <code>docker compose logs submw</code>.</li>
    </ol>
    <p>Если вы уже открыли по домену и всё равно видите это — реверс-прокси не передаёт заголовок <code>Host</code>. В nginx добавьте <code>proxy_set_header Host $host;</code>.</p>
    </main></body></html><?php
    exit();
}

// ---- ключ установки: без него /admin/ отдаёт приманку ----
$S = inst_state_load();
if ($S['key'] === '' || strpos($S['key'], '=') === false) {
    $S['key'] = inst_key_new();
    if (!inst_state_save($S)) {
        // Иначе ключ менялся бы на каждом запросе, а /admin/ вечно показывал приманку без объяснений.
        $dir = h(dirname(inst_state_path()));
        submw_plain_page('Папка data/ недоступна для записи',
            '<p>Прослойке нужно писать в <code>' . $dir . '</code>: там база, сессии и состояние установки.</p>'
            . '<p>Дайте пользователю веб-сервера права на запись в эту папку (например, <code>chown -R www-data:www-data data && chmod 750 data</code>) и обновите страницу.</p>', 500);
    }
    error_log('submw: ссылка установки: ' . $self['base'] . '/admin/?' . $S['key']);
}
if (!admin_gate_check($S['key'])) {
    landing_render();
    exit();
}
// Проверки ходят к панели, subpage и своему домену по очереди; лимит fpm в 30 с для этого мал.
@set_time_limit(180);
$csrf_ok = isset($_POST['_g']) && hash_equals($S['key'], (string) $_POST['_g']);
$env = inst_env_report();
$scenario_fixed = $env['docker'] && in_array($env['scenario'], ['panel', 'standalone'], true);
if ($scenario_fixed && $S['scenario'] === '') $S['scenario'] = $env['scenario'];
if ($S['scenario'] === 'panel' && $S['mode'] !== 'panel') $S['mode'] = 'panel';

// ---- AJAX-проверки ----
if (isset($_GET['inst']) && $_GET['inst'] === 'probe') {
    if (!$csrf_ok) inst_json_out(['ok' => false, 'msg' => 'Страница устарела — обновите её.']);
    $what = inst_post('what');
    if ($what === 'panel') {
        $u = inst_url_parse(inst_post('url'));
        $c = ['url' => $u['ok'] ? $u['url'] : inst_post('url'), 'token' => inst_post('token'), 'cookie' => inst_post('cookie'), 'xkey' => inst_post('xkey'), 'tls' => inst_post('tls') !== '0'];
        $from_link = false;
        if ($c['cookie'] === '' && $u['ok'] && $u['cookie_hint'] !== '') { $c['cookie'] = $u['cookie_hint']; $from_link = true; }
        $r = inst_panel_apply($S, $c, $S['scenario'] === 'panel' ? 'panel' : 'standalone');
        $r['url_norm'] = $u['ok'] ? $u['url'] : '';
        $r['url_note'] = $u['ok'] ? $u['note'] : '';
        $r['cookie_used'] = $c['cookie'];
        $r['cookie_from_link'] = $from_link;
        $r['token_warn'] = inst_token_shape($c['token']);
        $r['sub_public_domain'] = $S['panel']['sub_public_domain'];
        $r['self_host'] = $self['bare'];
        inst_state_save($S);
        inst_json_out($r);
    }
    if ($what === 'subpage') {
        $r = inst_subpage_probe(inst_post('url'), $S['user_short']);
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
    inst_json_out(['ok' => false, 'msg' => 'Неизвестная проверка']);
}

// ---- переходы между шагами ----
$err = '';
$steps = inst_steps($S);
if (!in_array($S['step'], $steps, true)) $S['step'] = 'start';
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
        if ($cur === 'start') {
            $sc = $scenario_fixed ? $S['scenario'] : inst_post('scenario');
            $md = inst_post('mode');
            if (!in_array($sc, ['panel', 'standalone', 'host'], true)) $err = 'Выберите, как установлена прослойка.';
            else {
                $S['scenario'] = $sc;
                $S['mode'] = ($sc !== 'panel' && $md === 'mirror') ? 'mirror' : 'panel';
                if ($sc === 'panel' && $S['panel']['url'] === '') $S['panel']['url'] = 'http://remnawave:3000';
                if ($S['subpage']['url'] === '') $S['subpage']['url'] = $sc === 'panel' ? 'http://remnawave-subscription-page:3010' : 'http://subpage:3010';
                $steps = inst_steps($S);
                $dbr = inst_db_probe($S['db']);
                if (!$dbr['ok']) $err = $dbr['msg'];
            }
        } elseif ($cur === 'panel') {
            $u = inst_url_parse(inst_post('url'));
            $c = ['url' => $u['ok'] ? $u['url'] : '', 'token' => inst_post('token'), 'cookie' => inst_post('cookie'), 'xkey' => inst_post('xkey'), 'tls' => inst_post('tls') !== '0'];
            if ($c['cookie'] === '' && $u['ok'] && $u['cookie_hint'] !== '') $c['cookie'] = $u['cookie_hint'];
            $r = $u['ok'] ? inst_panel_apply($S, $c, $S['scenario'] === 'panel' ? 'panel' : 'standalone') : ['ok' => false, 'msg' => $u['err'], 'hint' => ''];
            if (!$r['ok']) $err = isset($r['scopes']) && !$r['scopes']['ok'] ? $r['scopes']['msg'] : $r['msg'] . (!empty($r['hint']) ? ' ' . $r['hint'] : '');
        } elseif ($cur === 'subpage') {
            $S['subpage']['enabled'] = inst_post('enabled') === '1' ? 1 : 0;
            if ($S['subpage']['enabled']) {
                $u = inst_url_parse(inst_post('url'));
                if (!$u['ok']) $err = $u['err'];
                else {
                    $r = inst_subpage_probe($u['url'], $S['user_short']);
                    $S['subpage']['url'] = $u['url'];
                    $S['subpage']['st'] = $r['st'];
                    if (!$r['ok']) $S['pending']['subpage'] = $r['msg']; else unset($S['pending']['subpage']);
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
            if (!inst_webhook_secret_ok($sec)) $err = 'Секрет вебхука — ровно 64 символа, только латинские буквы и цифры.';
            else $S['webhook_secret'] = $sec;
            if ($S['panel']['webhook'] === false) $S['pending']['webhook'] = 'в панели вебхуки выключены'; else unset($S['pending']['webhook']);
        } elseif ($cur === 'admin') {
            $au = inst_post('admin_user');
            $ap = (string) ($_POST['admin_pass'] ?? '');
            $ap2 = (string) ($_POST['admin_pass2'] ?? '');
            if ($au === '' || $ap === '') $err = 'Заполните логин и пароль.';
            elseif ($ap !== $ap2) $err = 'Пароли не совпадают.';
            elseif (strlen($ap) < 8) $err = 'Пароль короче 8 символов.';
            else {
                $S['admin']['user'] = $au;
                $S['admin']['gate'] = inst_post('gate') === '1' ? 1 : 0;
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
    if ($pdo === null) return ['ok' => false, 'err' => 'База данных не отвечает: ' . $ce];
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
        $set('geoip_mode', 'ipinfo');
        if (!$mirror && !empty($S['subpage']['enabled']) && $S['subpage']['url'] !== '') $set('subpage_external_url', rtrim($S['subpage']['url'], '/'));
        if ($mirror) {
            $set('subpage_mirror', !empty($S['origin']['mirror_page']) ? '1' : '0');
            if ($S['origin']['prefix'] !== '') { $set('sub_prefix_enabled', '1'); $set('sub_prefix', $S['origin']['prefix']); }
        }
        // Постоянный ключ админки — не ключ мастера: тот напечатан в логах контейнера, install.sh и реверса.
        $gate_key = '';
        if (!empty($S['admin']['gate'])) { $gate_key = inst_key_new(); $set('admin_gate_key', $gate_key); }
        $set('install_scenario', $S['scenario']);
        if (!empty($S['pending'])) $set('install_pending', json_encode($S['pending'], JSON_UNESCAPED_UNICODE));
        if ($S['panel']['version'] !== '') {
            $set('panelmeta_json', json_encode(['ok' => true, 'ts' => time(), 'cached' => false, 'age' => 0, 'error' => '', 'version' => $S['panel']['version'], 'build_time' => '', 'build_number' => '', 'commit' => '', 'branch' => '', 'commit_url' => ''], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $set('panelmeta_ts', (string) time());
        }
    } catch (Throwable $e) {
        return ['ok' => false, 'err' => 'Не удалось создать таблицы: ' . $e->getMessage()];
    }
    $conf = ['installed' => true, 'db' => $db, 'admin_user' => $S['admin']['user'], 'admin_pass_hash' => password_hash($pass, PASSWORD_DEFAULT)];
    $php = "<?php\nreturn " . var_export($conf, true) . ";\n";
    if (@file_put_contents(config_path(), $php) === false) {
        return ['ok' => false, 'err' => 'Не удалось записать ' . config_path() . ' — проверьте права на папку.'];
    }
    @chmod(config_path(), 0640);
    @unlink(inst_state_path());
    if ($gate_key !== '') admin_gate_setcookie($gate_key);
    // Сквозной тест уже на рабочей прослойке: подписка первого пользователя через свой домен.
    $prefix = $S['mode'] === 'mirror' ? $S['origin']['prefix'] : '';
    $e2e = inst_e2e($self['base'], $S['user_short'], $prefix, !empty($S['panel']['tls']));
    return ['ok' => true, 'e2e' => $e2e, 'gate_key' => $gate_key];
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
        html{background:var(--bg)}
        body{min-height:100vh;min-height:100dvh;margin:0;background:radial-gradient(120% 50% at 50% 0%,var(--accent-light),transparent 60%),var(--bg)}
        .wrap{max-width:680px;margin:0 auto;padding:max(24px,env(safe-area-inset-top)) max(16px,env(safe-area-inset-right)) max(32px,env(safe-area-inset-bottom)) max(16px,env(safe-area-inset-left))}
        .in-h{display:flex;align-items:center;gap:.8rem;margin:0 0 1rem}
        .in-h h1{font-size:1.25rem;margin:0;color:var(--text-strong);line-height:1.25}
        .in-h p{margin:.15rem 0 0;color:var(--muted);font-size:.86rem}
        .in-steps{display:flex;gap:.35rem;margin:0 0 1rem;padding:0 0 .2rem;list-style:none;overflow-x:auto;-webkit-overflow-scrolling:touch;scrollbar-width:none}
        .in-steps::-webkit-scrollbar{display:none}
        .in-steps li{flex:none;font-size:.78rem;padding:.3rem .7rem;border-radius:999px;border:1px solid var(--line);color:var(--muted);background:var(--card);white-space:nowrap}
        .in-steps li.done{color:var(--c-ok-fg);background:var(--c-ok-bg);border-color:transparent}
        .in-steps li.cur{color:var(--accent-text);background:var(--accent-light);border-color:var(--accent);font-weight:600}
        .in-steps li form{display:inline}
        .in-steps li button{all:unset;cursor:pointer}
        .card{padding:1.1rem 1.1rem 1rem}
        .card h2{font-size:1.05rem;margin:0 0 .35rem}
        .card .lead{font-size:.88rem;color:var(--muted);margin:0 0 .9rem;line-height:1.45}
        .card label{margin-top:.7rem}
        .card .fh{font-size:.8rem;color:var(--muted);margin:.3rem 0 0;line-height:1.4}
        .warn{border-radius:12px;white-space:pre-wrap}
        .in-nav{display:flex;gap:.6rem;justify-content:space-between;align-items:center;margin-top:1.1rem}
        .in-nav .r{display:flex;gap:.6rem;margin-left:auto}
        .in-go{min-height:46px;font-size:1rem;border-radius:12px;padding:0 1.4rem}
        .in-go:disabled{opacity:.55;cursor:default}
        input[type=text],input[type=password]{min-height:44px}
        @media(max-width:900px){input{font-size:16px!important}}
        @media(max-width:560px){.in-nav{flex-wrap:wrap}.in-nav .r{width:100%;margin-left:0}.in-nav .r button{flex:1}.in-nav>button.btn{width:100%}.in-go{width:100%}}
        .st{display:flex;gap:.6rem;align-items:flex-start;padding:.65rem .8rem;border-radius:10px;margin:.6rem 0 0;font-size:.88rem;line-height:1.45;background:var(--bg2);border:1px solid var(--line)}
        .st svg{flex:none;width:18px;height:18px;margin-top:.1rem}
        .st>div{min-width:0;overflow-wrap:anywhere}
        .st.ok{background:var(--c-ok-bg);color:var(--c-ok-fg);border-color:transparent}
        .st.bad{background:var(--c-bad-bg);color:var(--c-bad-fg);border-color:transparent}
        .st.warn{background:var(--c-warn-bg);color:var(--c-warn-fg);border-color:transparent}
        .st.info{background:var(--c-info-bg);color:var(--c-info-fg);border-color:transparent}
        .st .hint{display:block;margin-top:.25rem;font-size:.82rem;opacity:.9}
        .sc{display:grid;gap:.55rem;grid-template-columns:1fr;margin-top:.4rem}
        .sc label{display:flex;gap:.7rem;align-items:flex-start;border:1px solid var(--line);border-radius:12px;padding:.75rem .85rem;cursor:pointer;background:var(--card);margin:0}
        .sc label:has(input:checked){border-color:var(--accent);background:var(--accent-light)}
        .sc input{margin:.2rem 0 0;flex:none}
        .sc b{display:block;color:var(--text-strong);font-size:.92rem}
        .sc span{font-size:.8rem;color:var(--muted);line-height:1.4}
        .kv{display:flex;flex-wrap:wrap;gap:.4rem .9rem;font-size:.84rem;color:var(--muted);margin:.2rem 0 .6rem}
        .kv b{color:var(--text);font-weight:500}
        .rights{margin-top:.5rem;border:1px solid var(--line);border-radius:10px;overflow:hidden;font-size:.84rem}
        .rights div{display:flex;gap:.6rem;align-items:center;padding:.45rem .7rem;border-top:1px solid var(--line)}
        .rights div:first-child{border-top:0}
        .rights code{flex:1;min-width:0;overflow-wrap:anywhere}
        .rights .tag{flex:none}
        .rights .w{display:none}
        @media(min-width:640px){.rights .w{display:block;flex:1.4;color:var(--muted)}}
        details.more{margin-top:.8rem}
        details.more summary{cursor:pointer;font-size:.86rem;color:var(--accent-text);font-weight:600;list-style:none}
        details.more summary::-webkit-details-marker{display:none}
        details.more summary::before{content:"▸ ";font-size:.8em}
        details.more[open] summary::before{content:"▾ "}
        .row2{display:grid;grid-template-columns:1fr 1fr;gap:.6rem}
        @media(max-width:560px){.row2{grid-template-columns:1fr}}
        .codeblk{position:relative;margin:.6rem 0 0}
        .codeblk pre{margin:0;padding:.7rem 5.2rem .7rem .8rem;border-radius:10px;background:var(--bg2);border:1px solid var(--line);font-size:.8rem;overflow:auto;white-space:pre-wrap;word-break:break-all}
        .copybtn{position:absolute;top:.4rem;right:.4rem;min-height:0;padding:.25rem .6rem;font-size:.74rem}
        .in-link{font-family:ui-monospace,monospace;font-size:.88rem}
        .chk-inline{display:flex;align-items:center;gap:.55rem;margin:.7rem 0 0;font-size:.88rem;cursor:pointer}
        .chk-inline input{width:18px;height:18px;margin:0;flex:none}
    </style></head><body><div class="wrap">
    <div class="in-h"><div><h1>Установка прослойки</h1><p>Пять шагов, каждый проверяется</p></div></div>
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

function inst_copy_btn() {
    return '<button type="button" class="copybtn" onclick="var b=this;navigator.clipboard&&navigator.clipboard.writeText(b.nextElementSibling.textContent).then(function(){b.textContent=\'Готово\';setTimeout(function(){b.textContent=\'Копировать\';},1500);});">Копировать</button>';
}

function inst_render_done(array $S, array $fin, array $self) {
    $e2e = $fin['e2e'];
    $link = $self['base'] . '/admin/?' . ($fin['gate_key'] ?? $S['key']);
    inst_head('Установка завершена');
    ?>
    <div class="card">
        <h2>Готово</h2>
        <p class="lead">Прослойка установлена и работает на <?= h($self['host']) ?>.</p>
        <?php if ($e2e['ok']): ?>
            <?php inst_st('ok', 'Подписка пользователя ' . ($S['user_name'] !== '' ? '«' . $S['user_name'] . '» ' : '') . 'открывается через ваш домен.'); ?>
        <?php elseif ($e2e['st'] === 'nouser'): ?>
            <?php inst_st('info', 'В панели ещё нет пользователей.', 'Создайте пользователя и откройте его ссылку в VPN-клиенте.'); ?>
        <?php else: ?>
            <?php inst_st('warn', $e2e['msg'], ($e2e['hint'] !== '' ? $e2e['hint'] . ' ' : '') . 'Повторить проверку: Подключение → Проверка связи.'); ?>
        <?php endif; ?>
        <?php if (!empty($S['pending'])): ?>
            <?php inst_st('warn', 'Не прошли проверки: ' . implode('; ', array_map(fn($k, $v) => mb_strtolower(inst_step_title($k)) . ' — ' . $v, array_keys($S['pending']), $S['pending'])) . '.', 'Напоминание будет в админке, пока не закроете.'); ?>
        <?php endif; ?>
    </div>
    <?php if (!empty($S['admin']['gate'])): ?>
    <div class="card">
        <h2>Ссылка для входа</h2>
        <p class="lead">Сохраните её. Без этой ссылки адрес <code>/admin/</code> показывает страницу-заглушку — так админку не найти посторонним. Откройте ссылку один раз в каждом браузере и на телефоне.</p>
        <div class="codeblk"><?= inst_copy_btn() ?><pre class="in-link"><?= h($link) ?></pre></div>
        <p class="fh">Потеряли — выполните <code>docker compose exec submw php tools/admin-gate.php</code>, она напечатает ссылку. Выключить защиту: вкладка «Подключение».</p>
        <form method="get" action="index.php" style="margin-top:1rem"><button type="submit" class="in-go" style="width:100%">Войти в админку</button></form>
    </div>
    <?php else: ?>
    <div class="card">
        <h2>Вход в админку</h2>
        <p class="lead">Адрес: <code><?= h($self['base']) ?>/admin/</code>. Дополнительную защиту секретной ссылкой можно включить потом на вкладке «Подключение».</p>
        <form method="get" action="index.php"><button type="submit" class="in-go" style="width:100%">Войти в админку</button></form>
    </div>
    <?php endif; ?>
    <?php
    inst_foot();
}

$step = $S['step'];
$G = $S['key'];
inst_head('Установка прослойки');
?>
<ul class="in-steps"><?php foreach ($steps as $i => $id): $ci = array_search($step, $steps, true); $cls = $i < $ci ? 'done' : ($i === $ci ? 'cur' : ''); ?><li class="<?= $cls ?>"><?php if ($i < $ci): ?><form method="post"><input type="hidden" name="_g" value="<?= h($G) ?>"><input type="hidden" name="act" value="goto"><input type="hidden" name="to" value="<?= h($id) ?>"><button type="submit"><?= ($i + 1) . '. ' . h(inst_step_title($id)) ?></button></form><?php else: ?><?= ($i + 1) . '. ' . h(inst_step_title($id)) ?><?php endif; ?></li><?php endforeach; ?></ul>
<?php if ($err !== ''): ?><div class="warn" role="alert"><?= h($err) ?></div><?php endif; ?>
<form method="post" id="inst" autocomplete="off">
<input type="hidden" name="_g" value="<?= h($G) ?>">
<input type="hidden" name="act" value="next">
<?php if ($step === 'start'): ?>
    <div class="card">
        <h2>Начало</h2>
        <div class="kv"><span>Домен: <b><?= h($self['host']) ?></b></span><span>База данных: <b><?= ($S['db']['driver'] ?? 'sqlite') === 'mysql' ? 'MySQL' : 'SQLite' ?></b></span><span>PHP <b><?= h($env['php']) ?></b></span><?php if ($env['docker']): ?><span>Docker<?= $env['image'] !== '' ? ', образ <b>' . h(preg_match('~^[0-9a-f]{12,}$~', $env['image']) ? substr($env['image'], 0, 7) : $env['image']) . '</b>' : '' ?></span><?php endif; ?></div>
        <?php foreach (['curl' => 'нужно для связи с панелью', 'pdo_sqlite' => 'нужно для базы SQLite', 'pdo_mysql' => 'нужно для MySQL', 'mbstring' => 'нужно для работы с текстом', 'dom' => 'нужно для страницы подписки', 'sodium' => 'нужно для приложения Clod Clash'] as $e => $why): if (empty($env['ext'][$e])): ?>
            <?php inst_st(in_array($e, ['curl', 'mbstring'], true) || ($e === 'pdo_sqlite' && ($S['db']['driver'] ?? 'sqlite') === 'sqlite') || ($e === 'pdo_mysql' && ($S['db']['driver'] ?? '') === 'mysql') ? 'bad' : 'warn', 'В PHP нет расширения ' . $e . ' — ' . $why . '.'); ?>
        <?php endif; endforeach; ?>
        <?php if (!$env['data_ok']): ?><?php inst_st('bad', 'Папка data/ недоступна для записи.', $env['data_dir']); ?><?php endif; ?>
        <?php if ($self['scheme'] !== 'https'): ?><?php inst_st('warn', 'Мастер открыт без HTTPS.', 'Клиентам нужен https — проверьте сертификат в реверс-прокси.'); ?><?php endif; ?>
        <?php if ($scenario_fixed): ?>
            <p class="lead" style="margin-top:.6rem"><?= $S['scenario'] === 'panel' ? 'Прослойка стоит рядом с панелью и будет отдавать подписку с неё по внутреннему адресу.' : 'Прослойка стоит на отдельном сервере.' ?></p>
        <?php else: ?>
            <?php $sc = $S['scenario'] !== '' ? $S['scenario'] : ($env['docker'] ? 'panel' : 'host'); ?>
            <label>Как установлена прослойка</label>
            <div class="sc">
                <label><input type="radio" name="scenario" value="panel" <?= $sc === 'panel' ? 'checked' : '' ?>><div><b>Рядом с панелью</b><span>На том же сервере, в docker-сети панели.</span></div></label>
                <label><input type="radio" name="scenario" value="standalone" <?= $sc === 'standalone' ? 'checked' : '' ?>><div><b>Отдельный сервер</b><span>Панель на другом сервере.</span></div></label>
                <?php if (!$env['docker']): ?><label><input type="radio" name="scenario" value="host" <?= $sc === 'host' ? 'checked' : '' ?>><div><b>Без Docker</b><span>Установка скриптом install.sh.</span></div></label><?php endif; ?>
            </div>
        <?php endif; ?>
        <?php if ($S['scenario'] !== 'panel'): ?>
        <div id="modeBox">
            <label>Какой домен у прослойки</label>
            <div class="sc">
                <label><input type="radio" name="mode" value="panel" <?= $S['mode'] !== 'mirror' ? 'checked' : '' ?>><div><b>Домен подписки</b><span>Тот, что указан в панели как SUB_PUBLIC_DOMAIN. Ссылки у пользователей не меняются.</span></div></label>
                <label><input type="radio" name="mode" value="mirror" <?= $S['mode'] === 'mirror' ? 'checked' : '' ?>><div><b>Свой домен (зеркало)</b><span>Подписки берутся с домена подписки панели. Пользователям выдаются новые ссылки.</span></div></label>
            </div>
        </div>
        <?php endif; ?>
        <div class="in-nav"><div class="r"><button type="submit" class="in-go">Далее</button></div></div>
    </div>
    <?php if (!$scenario_fixed): ?><script>(function(){var r=document.querySelectorAll('input[name=scenario]'),m=document.getElementById('modeBox');if(!m)return;function s(){var v=document.querySelector('input[name=scenario]:checked');m.style.display=(v&&v.value==='panel')?'none':'';}r.forEach(function(x){x.addEventListener('change',s);});s();})();</script><?php endif; ?>
<?php elseif ($step === 'panel'): ?>
    <div class="card">
        <h2>Панель</h2>
        <p class="lead"><?= $S['scenario'] === 'panel' ? 'Адрес панели внутри docker-сети уже подставлен. Осталось вставить API-токен.' : 'Вставьте адрес панели и API-токен. Если панель ставилась установщиком с защитной cookie — вставьте ссылку входа из него целиком, cookie подставится сама.' ?></p>
        <label>Адрес панели</label>
        <input name="url" id="pUrl" type="text" inputmode="url" autocapitalize="none" autocorrect="off" spellcheck="false" value="<?= h($S['panel']['url']) ?>" placeholder="<?= $S['scenario'] === 'panel' ? 'http://remnawave:3000' : 'https://panel.example.com' ?>">
        <label>API-токен</label>
        <input name="token" id="pTok" type="text" autocapitalize="none" autocorrect="off" spellcheck="false" value="<?= h($S['panel']['token']) ?>" placeholder="eyJhbGciOi…">
        <p class="fh">Панель → Settings → API Tokens → Create. Выберите все права.</p>
        <details class="more" <?= ($S['panel']['cookie'] !== '' || $S['panel']['xkey'] !== '' || empty($S['panel']['tls'])) ? 'open' : '' ?>><summary>Панель закрыта защитой</summary>
            <label>Cookie панели</label>
            <input name="cookie" id="pCookie" type="text" autocapitalize="none" autocorrect="off" spellcheck="false" value="<?= h($S['panel']['cookie']) ?>" placeholder="имя=значение">
            <p class="fh">Часть ссылки входа после «?». Нужна, если панель ставилась скриптом eGames / remnawave-reverse-proxy.</p>
            <label>X-Api-Key</label>
            <input name="xkey" id="pXkey" type="text" autocapitalize="none" autocorrect="off" spellcheck="false" value="<?= h($S['panel']['xkey']) ?>">
            <p class="fh">Нужен, если панель закрыта авторизацией Caddy (caddy-with-auth, Tiny Auth).</p>
            <label class="chk-inline"><input type="checkbox" name="tls" id="pTls" value="1" <?= !empty($S['panel']['tls']) ? 'checked' : '' ?>>Проверять сертификат панели</label>
            <input type="hidden" name="tls" value="0" id="pTlsOff" disabled>
        </details>
        <div id="pSt"></div>
        <div class="in-nav"><button type="submit" class="btn ghost" name="act" value="back">Назад</button><div class="r"><button type="button" class="btn ghost" id="pCheck">Проверить</button><button type="submit" class="in-go" id="pNext" <?= ($S['panel']['st'] === 'ok' && !empty($S['scopes']['ok'])) ? '' : 'disabled' ?>>Далее</button></div></div>
    </div>
<?php elseif ($step === 'subpage'): ?>
    <div class="card">
        <h2>Страница подписки</h2>
        <p class="lead">Когда пользователь открывает ссылку в браузере, он видит страницу подписки из контейнера subscription-page.</p>
        <label class="chk-inline" style="margin-top:0"><input type="checkbox" name="enabled" id="spOn" value="1" <?= !empty($S['subpage']['enabled']) ? 'checked' : '' ?>>Показывать страницу подписки</label>
        <div id="spBox">
            <label>Адрес контейнера subscription-page</label>
            <input name="url" id="spUrl" type="text" inputmode="url" autocapitalize="none" autocorrect="off" spellcheck="false" value="<?= h($S['subpage']['url']) ?>" placeholder="http://remnawave-subscription-page:3010">
            <?php if ($S['scenario'] !== 'panel'): ?><p class="fh">Контейнер из вашего docker-compose.yml. Если он не запускается — в нём не заполнены адрес панели и токен.</p><?php endif; ?>
        </div>
        <div id="spSt"></div>
        <div class="in-nav"><button type="submit" class="btn ghost" name="act" value="back">Назад</button><div class="r"><button type="button" class="btn ghost" id="spCheck">Проверить</button><button type="submit" class="in-go" id="spNext">Далее</button></div></div>
    </div>
<?php elseif ($step === 'origin'): ?>
    <div class="card">
        <h2>Домен подписки</h2>
        <p class="lead">Откуда зеркало будет брать подписки. Вставьте ссылку подписки любого пользователя из панели — домен и префикс определятся сами.</p>
        <label>Ссылка подписки</label>
        <input name="raw" id="oRaw" type="text" inputmode="url" autocapitalize="none" autocorrect="off" spellcheck="false" value="<?= h($S['origin']['raw']) ?>" placeholder="https://sub.example.com/abc123…">
        <label class="chk-inline"><input type="checkbox" name="mirror_page" value="1" <?= (!isset($S['origin']['mirror_page']) || !empty($S['origin']['mirror_page'])) ? 'checked' : '' ?>>Показывать в браузере страницу подписки с этого домена</label>
        <div id="oSt"></div>
        <div class="in-nav"><button type="submit" class="btn ghost" name="act" value="back">Назад</button><div class="r"><button type="button" class="btn ghost" id="oCheck">Проверить</button><button type="submit" class="in-go" id="oNext">Далее</button></div></div>
    </div>
<?php elseif ($step === 'webhook'): ?>
    <?php $sec = $S['webhook_secret'] !== '' ? $S['webhook_secret'] : inst_webhook_secret_new(); ?>
    <div class="card">
        <h2>Вебхук</h2>
        <p class="lead">Панель будет сообщать прослойке об изменениях у пользователей. Скопируйте три строки в <code>.env</code> панели и перезапустите её. Можно сделать и после установки.</p>
        <div class="codeblk"><?= inst_copy_btn() ?><pre id="whEnv">WEBHOOK_ENABLED=true
WEBHOOK_URL=<?= h($self['base']) ?>/webhook.php
WEBHOOK_SECRET_HEADER=<?= h($sec) ?></pre></div>
        <?php if ($S['panel']['webhook'] === true): ?><?php inst_st('ok', 'В панели вебхуки уже включены.', 'Проверьте, что адрес и секрет в .env совпадают с этими.'); ?>
        <?php elseif ($S['panel']['webhook'] === false): ?><?php inst_st('warn', 'В панели вебхуки пока выключены.', 'Без них прослойка не узнает об истечении и блокировках сразу.'); ?><?php endif; ?>
        <details class="more"><summary>Свой секрет</summary>
            <label>Секрет вебхука</label>
            <input name="secret" type="text" autocapitalize="none" autocorrect="off" spellcheck="false" value="<?= h($sec) ?>">
            <p class="fh">Ровно 64 символа: латинские буквы и цифры.</p>
        </details>
        <div class="in-nav"><button type="submit" class="btn ghost" name="act" value="back">Назад</button><div class="r"><button type="submit" class="in-go">Далее</button></div></div>
    </div>
    <script>document.querySelector('input[name=secret]').addEventListener('input',function(){var p=document.getElementById('whEnv');p.textContent=p.textContent.replace(/WEBHOOK_SECRET_HEADER=.*/, 'WEBHOOK_SECRET_HEADER='+this.value);});</script>
<?php elseif ($step === 'admin'): ?>
    <div class="card">
        <h2>Пароль</h2>
        <p class="lead">Логин и пароль для входа в админку.</p>
        <label for="admin_user">Логин</label><input id="admin_user" name="admin_user" type="text" autocomplete="username" autocapitalize="none" autocorrect="off" spellcheck="false" value="<?= h($S['admin']['user']) ?>">
        <div class="row2">
            <div><label for="admin_pass">Пароль</label><input id="admin_pass" name="admin_pass" type="password" autocomplete="new-password"></div>
            <div><label for="admin_pass2">Ещё раз</label><input id="admin_pass2" name="admin_pass2" type="password" autocomplete="new-password"></div>
        </div>
        <p class="fh">Не короче 8 символов.</p>
        <label class="chk-inline" style="margin-top:1rem"><input type="checkbox" name="gate" value="1" <?= !empty($S['admin']['gate']) ? 'checked' : '' ?>>Дополнительная защита: открывать админку только по секретной ссылке</label>
        <p class="fh">Без ссылки адрес <code>/admin/</code> будет показывать страницу-заглушку, как корень сайта. Ссылку покажем после установки; включить или выключить можно потом на вкладке «Подключение».</p>
        <div class="in-nav"><button type="submit" class="btn ghost" name="act" value="back">Назад</button><div class="r"><button type="submit" class="in-go" id="aGo">Установить</button></div></div>
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
        var ST = {ok:['есть','normal'], miss:['нет','blocked'], na:['нет в этой версии','expired'], auth:['токен отклонён','blocked'], err:['нет ответа','DISABLED']};
        function rights(sc){
            if (sc.msg) box.appendChild(st(sc.ok ? 'warn' : 'bad', sc.msg));
            else box.appendChild(st('ok', 'Права токена в порядке.'));
            var opt = (sc.rows||[]).filter(function(r){ return r.st==='miss' && !r.need; }).map(function(r){ return r.s; });
            if (sc.ok && opt.length) box.appendChild(st('info', 'Без прав ' + opt.join(', ') + ' часть функций будет недоступна.', 'Их можно добавить токену позже.'));
            var d=document.createElement('details'); d.className='more'; var sm=document.createElement('summary'); sm.textContent='Все права'; d.appendChild(sm);
            var t=document.createElement('div'); t.className='rights';
            (sc.rows||[]).forEach(function(r){ var row=document.createElement('div'); var s=ST[r.st]||[r.st,'']; var c=document.createElement('code'); c.textContent=r.s; var w=document.createElement('span'); w.className='w'; w.textContent=r.w; var tg=document.createElement('span'); tg.className='tag '+s[1]; tg.textContent=s[0]+(r.need?'':' · по желанию'); row.appendChild(c); row.appendChild(w); row.appendChild(tg); t.appendChild(row); });
            d.appendChild(t); box.appendChild(d);
        }
        function runPanel(){
            busy(btn, true); box.textContent='';
            probe('panel', {url: document.getElementById('pUrl').value, token: document.getElementById('pTok').value, cookie: document.getElementById('pCookie').value, xkey: document.getElementById('pXkey').value, tls: tls.checked ? '1' : '0'}).then(function(d){
                if (d.url_norm && d.url_norm !== document.getElementById('pUrl').value) { document.getElementById('pUrl').value = d.url_norm; if (d.url_note) box.appendChild(st('info', d.url_note)); }
                if (d.cookie_from_link) { document.getElementById('pCookie').value = d.cookie_used; document.querySelector('details.more').open = true; box.appendChild(st('info', 'Cookie взята из ссылки: ' + d.cookie_used)); }
                if (d.token_warn) box.appendChild(st('warn', d.token_warn));
                if (d.fix && d.fix.url) { document.getElementById('pUrl').value = d.fix.url; }
                if (d.fix && d.fix.tls === 0) { document.querySelector('details.more').open = true; }
                box.appendChild(st(d.st === 'ok' ? 'ok' : 'bad', d.msg || (d.ok ? 'OK' : 'Ошибка'), d.hint || ''));
                if (d.st === 'ok' && d.sub_public_domain && d.self_host && d.sub_public_domain.toLowerCase() !== d.self_host && d.sub_public_domain.toLowerCase().indexOf(d.self_host + '/') !== 0) box.appendChild(st('warn', 'В панели домен подписки — ' + d.sub_public_domain + ', а прослойка открыта как ' + d.self_host + '.', 'Ссылки пользователей ведут на домен из панели.'));
                if (d.scopes) rights(d.scopes);
                next.disabled = !d.ok;
            }).catch(function(){ box.appendChild(st('bad', 'Нет связи с прослойкой — попробуйте ещё раз.')); }).then(function(){ busy(btn, false); });
        }
        btn.addEventListener('click', runPanel);
        ['pUrl','pTok','pCookie','pXkey'].forEach(function(id){ document.getElementById(id).addEventListener('input', function(){ next.disabled = true; }); });
        if (document.getElementById('pUrl').value && document.getElementById('pTok').value && next.disabled) runPanel();
    }

    var spOn = document.getElementById('spOn');
    if (spOn) {
        var spBox=document.getElementById('spBox'), spSt=document.getElementById('spSt'), spBtn=document.getElementById('spCheck');
        function syncSp(){ spBox.style.display = spOn.checked ? '' : 'none'; spBtn.style.display = spOn.checked ? '' : 'none'; }
        spOn.addEventListener('change', syncSp); syncSp();
        function runSp(){ busy(spBtn,true); spSt.textContent=''; probe('subpage',{url:document.getElementById('spUrl').value}).then(function(d){ spSt.appendChild(st(d.ok?'ok':'warn', d.msg, d.hint||'')); if(!d.ok) spSt.appendChild(st('info','Можно продолжить и вернуться к этому позже: Подключение → Проверка связи.')); }).catch(function(){ spSt.appendChild(st('bad','Нет связи с прослойкой — попробуйте ещё раз.')); }).then(function(){ busy(spBtn,false); }); }
        spBtn.addEventListener('click', runSp);
        if (spOn.checked && document.getElementById('spUrl').value) runSp();
    }

    var oRaw = document.getElementById('oRaw');
    if (oRaw) {
        var oSt=document.getElementById('oSt'), oBtn=document.getElementById('oCheck');
        function runO(){ busy(oBtn,true); oSt.textContent=''; probe('origin',{raw:oRaw.value}).then(function(d){
            if (d.domain) oSt.appendChild(st('info', 'Домен подписки: ' + d.domain + (d.prefix ? ', префикс /' + d.prefix : '')));
            oSt.appendChild(st(d.ok ? 'ok' : (d.st==='loop'||d.st==='panel'||d.st==='url' ? 'bad' : 'warn'), d.msg, d.hint||''));
            if (d.ok && d.browser === false) oSt.appendChild(st('warn', 'В браузере этот домен страницу не показывает.', 'Снимите галочку «Показывать в браузере страницу подписки».'));
        }).catch(function(){ oSt.appendChild(st('bad','Нет связи с прослойкой — попробуйте ещё раз.')); }).then(function(){ busy(oBtn,false); }); }
        oBtn.addEventListener('click', runO);
        if (oRaw.value) runO();
    }
})();
</script>
<?php
inst_foot();
exit();
