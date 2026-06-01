<?php

ini_set('display_errors', 0);
error_reporting(E_ALL);

require __DIR__ . '/../lib.php';

header('X-Robots-Tag: noindex, nofollow');
header('X-Frame-Options: DENY');

function h($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

if (!is_installed()) {
    $err = '';
    $ok  = false;

    $prefill_file = dirname(__DIR__) . '/data/install.json';
    $prefill = is_file($prefill_file) ? json_decode((string) @file_get_contents($prefill_file), true) : null;
    if (!is_array($prefill)) $prefill = [];
    $pf_db = (isset($prefill['db']) && is_array($prefill['db'])) ? $prefill['db'] : ['driver' => 'sqlite', 'path' => default_db_path()];

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $f = fn($k) => trim((string) ($_POST[$k] ?? ''));

        $db = $pf_db;
        $target   = $f('target_domain');
        $mirror   = $f('mirror_domain');
        $rw_url   = rtrim($f('remnawave_url'), '/');
        $rw_key   = $f('remnawave_api_key');
        $rw_cookie= $f('remnawave_cookie');
        $wh_sec   = $f('webhook_secret');
        $au       = $f('admin_user');
        $ap       = (string) ($_POST['admin_pass'] ?? '');
        $ap2      = (string) ($_POST['admin_pass2'] ?? '');

        if ($target === '' || $au === '' || $ap === '') {
            $err = 'Заполните обязательные поля (origin-домен, логин и пароль админки).';
        } elseif ($ap !== $ap2) {
            $err = 'Пароли админки не совпадают.';
        } elseif (strlen($ap) < 8) {
            $err = 'Пароль админки слишком короткий (минимум 8 символов).';
        } else {
            $ce = '';
            $pdo = pdo_connect($db, $ce);
            if ($pdo === null) $err = 'Не удалось подключиться к БД: ' . $ce;

            if ($pdo && !$err) {
                try {
                    $drv = $db['driver'] ?? 'sqlite';
                    foreach (install_statements($drv) as $sql) {
                        $pdo->exec($sql);
                    }
                    $set = function ($k, $v) use ($pdo, $drv) {
                        if ($drv === 'mysql') $st = $pdo->prepare('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)');
                        else $st = $pdo->prepare('INSERT INTO settings (k, v) VALUES (?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v');
                        $st->execute([$k, $v]);
                    };
                    $set('target_domain', $target);
                    $set('mirror_domain', $mirror !== '' ? $mirror : ($_SERVER['HTTP_HOST'] ?? ''));
                    $set('webhook_secret', $wh_sec);
                    $set('remnawave_url', $rw_url);
                    $set('remnawave_api_key', $rw_key);
                    $set('remnawave_cookie', $rw_cookie);
                } catch (Throwable $e) {
                    $err = 'Ошибка создания таблиц: ' . $e->getMessage();
                }
            }

            if ($pdo && !$err) {
                $conf = [
                    'installed'           => true,
                    'db'                  => $db,
                    'admin_user'          => $au,
                    'admin_pass_hash'     => password_hash($ap, PASSWORD_DEFAULT),
                    'admin_cookie_secret' => bin2hex(random_bytes(24)),
                ];
                $php = "<?php\nreturn "
                     . var_export($conf, true) . ";\n";
                if (@file_put_contents(config_path(), $php) !== false) {
                    @chmod(config_path(), 0640);
                    @unlink($prefill_file);
                    $ok = true;
                    header('Location: index.php?installed=1');
                    exit();
                } else {
                    $err = 'Таблицы созданы, но не удалось записать config.php (нет прав). '
                         . 'Создайте файл config.php в корне прослойки со следующим содержимым:';
                    $GLOBALS['manual_config'] = $php;
                }
            }
        }
    }

    $host_guess = $prefill['mirror_domain'] ?? ($_SERVER['HTTP_HOST'] ?? '');
    ?>
    <!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Установка прослойки</title>
    <link rel="icon" href="data:image/svg+xml,%3Csvg%20xmlns='http://www.w3.org/2000/svg'%20viewBox='0%200%2024%2024'%20fill='%2322b8cf'%3E%3Cpath%20d='M12%202l8%203v6c0%205-3.5%208.5-8%2010-4.5-1.5-8-5-8-10V5z'/%3E%3C/svg%3E">
    <style>
        body{font-family:Segoe UI,Roboto,Arial,sans-serif;background:#0f172a;color:#e2e8f0;margin:0;padding:2rem}
        .wrap{max-width:640px;margin:0 auto}
        h1{font-size:1.3rem}
        .card{background:#1e293b;border:1px solid #334155;border-radius:.6rem;padding:1.25rem;margin-bottom:1.25rem}
        h2{font-size:1rem;margin:0 0 .5rem}
        label{display:block;font-size:.8rem;color:#94a3b8;margin:.7rem 0 .25rem}
        input{width:100%;padding:.55rem;background:#0f172a;border:1px solid #334155;color:#e2e8f0;border-radius:.4rem;box-sizing:border-box}
        .row{display:flex;gap:1rem}.row>div{flex:1}
        button{margin-top:1.25rem;padding:.75rem 1.5rem;background:#4f46e5;color:#fff;border:0;border-radius:.4rem;font-weight:600;cursor:pointer;font-size:1rem}
        .err{background:#7f1d1d;color:#fecaca;padding:.7rem 1rem;border-radius:.4rem;margin-bottom:1rem;white-space:pre-wrap}
        .muted{color:#94a3b8;font-size:.82rem}
        pre{background:#0b1220;padding:1rem;border-radius:.4rem;overflow:auto;font-size:.8rem}
        code{background:#0b1220;padding:.1rem .35rem;border-radius:.25rem}
    </style></head><body><div class="wrap">
    <h1>Установка прослойки подписки</h1>
    <?php if ($err): ?><div class="err"><?= h($err) ?></div><?php endif; ?>
    <?php if (!empty($GLOBALS['manual_config'])): ?>
        <div class="card"><pre><?= h($GLOBALS['manual_config']) ?></pre></div>
    <?php endif; ?>
    <form method="post">
        <div class="card">
            <h2>База данных</h2>
            <?php if (($pf_db['driver'] ?? 'sqlite') === 'mysql'): ?>
            <p class="muted">MySQL/MariaDB — параметры подготовлены установщиком (база <code><?= h($pf_db['name'] ?? '') ?></code>). Заполнять ничего не нужно.</p>
            <?php else: ?>
            <p class="muted">SQLite — отдельный сервер БД не нужен. Файл создаётся автоматически в <code>data/submw.sqlite</code>.</p>
            <?php endif; ?>
        </div>
        <div class="card">
            <h2>Домены</h2>
            <label>Origin — реальный домен подписки Remnawave *</label>
            <input name="target_domain" value="<?= h($_POST['target_domain'] ?? ($prefill['target_domain'] ?? '')) ?>" placeholder="sub.example.com">
            <p class="muted">Только домен, без <code>https://</code> и без пути. Пример: <code>sub.example.com</code></p>
            <label>Домен зеркала (где стоит прослойка)</label>
            <input name="mirror_domain" value="<?= h($_POST['mirror_domain'] ?? $host_guess) ?>" placeholder="mirror.example.com">
            <p class="muted">Тоже только домен, без <code>https://</code>. На зеркало уже указывают подписки юзеров — менять его не нужно.</p>
        </div>
        <div class="card">
            <h2>API Remnawave (для списка юзеров)</h2>
            <label>URL панели</label><input name="remnawave_url" value="<?= h($_POST['remnawave_url'] ?? '') ?>" placeholder="https://panel.example.com">
            <p class="muted">Полный адрес <b>со схемой</b> <code>https://</code> и без <code>/</code> на конце. Пример: <code>https://panel.example.com</code></p>
            <label>Cookie панели (если защита eGames; иначе пусто)</label><input name="remnawave_cookie" value="<?= h($_POST['remnawave_cookie'] ?? '') ?>" placeholder="aB3xK9pQ=Zt7mW2nR">
            <p class="muted">Нужна, только если панель закрыта cookie-защитой eGames reverse-proxy (без верной куки панель отдаёт 404); иначе оставьте пустым. Формат <code>имя=значение</code>.<br><b>Где взять:</b> проще всего в браузере — войдите в панель, F12 → Application (Storage) → Cookies → выберите домен панели → скопируйте защитную куку (её имя и значение). Либо в конфиге вашего eGames reverse-proxy (nginx/Caddy), где проверяется кука, или в выводе установщика eGames при настройке.</p>
            <label>API-токен (раздел API Tokens в панели)</label><input name="remnawave_api_key" value="<?= h($_POST['remnawave_api_key'] ?? '') ?>">
        </div>
        <div class="card">
            <h2>Вебхук</h2>
            <label>Секрет вебхука (то же значение пойдёт в .env панели)</label>
            <input name="webhook_secret" value="<?= h($_POST['webhook_secret'] ?? bin2hex(random_bytes(32))) ?>">
            <p class="muted">Минимум 32 символа, только <code>a-z A-Z 0-9</code> (требование панели). Сгенерированный подходит. После установки во вкладке «Подключение» будут готовые строки для <code>.env</code> панели.</p>
        </div>
        <div class="card">
            <h2>Доступ в админку</h2>
            <label>Логин *</label><input name="admin_user" value="<?= h($_POST['admin_user'] ?? 'admin') ?>">
            <div class="row">
                <div><label>Пароль *</label><input name="admin_pass" type="password"></div>
                <div><label>Повтор пароля *</label><input name="admin_pass2" type="password"></div>
            </div>
        </div>
        <button type="submit">🚀 Установить</button>
    </form>
    </div></body></html>
    <?php
    exit();
}

$C = cfg();

session_name('submw_admin');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => (($_SERVER['HTTPS'] ?? '') === 'on')]);
session_start();

function csrf_token() {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}
function csrf_ok() { return isset($_POST['csrf'], $_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $_POST['csrf']); }
function is_auth() { return !empty($_SESSION['auth']); }
function flash($m) { $_SESSION['flash'] = $m; }
function take_flash() { $m = $_SESSION['flash'] ?? null; unset($_SESSION['flash']); return $m; }

if (isset($_GET['logout'])) {
    $_SESSION = []; session_destroy();
    header('Location: index.php'); exit();
}

if (!is_auth()) {
    $err = '';
    $just_installed = isset($_GET['installed']);
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $lip = login_remote_ip();
        if (login_is_locked($lip)) {
            $err = 'Слишком много попыток входа. Подождите 15 минут и попробуйте снова.';
            usleep(500000);
        } else {
            $u = $_POST['user'] ?? '';
            $p = $_POST['pass'] ?? '';
            if (hash_equals((string) $C['admin_user'], (string) $u) && password_verify($p, $C['admin_pass_hash'])) {
                login_clear($lip);
                session_regenerate_id(true);
                $_SESSION['auth'] = true;
                header('Location: index.php'); exit();
            }
            login_record_fail($lip);
            $err = 'Неверный логин или пароль';
            usleep(500000);
        }
    }
    ?>
    <!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Админка · вход</title>
    <link rel="icon" href="data:image/svg+xml,%3Csvg%20xmlns='http://www.w3.org/2000/svg'%20viewBox='0%200%2024%2024'%20fill='%2322b8cf'%3E%3Cpath%20d='M12%202l8%203v6c0%205-3.5%208.5-8%2010-4.5-1.5-8-5-8-10V5z'/%3E%3C/svg%3E">
    <style>
        body{font-family:Segoe UI,Roboto,Arial,sans-serif;background:#0f172a;color:#e2e8f0;display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0}
        .card{background:#1e293b;padding:2rem;border-radius:.75rem;width:320px;box-shadow:0 10px 30px rgba(0,0,0,.4)}
        h1{font-size:1.1rem;margin:0 0 1.25rem}
        label{display:block;font-size:.8rem;margin:.75rem 0 .25rem;color:#94a3b8}
        input{width:100%;padding:.6rem;border:1px solid #334155;background:#0f172a;color:#e2e8f0;border-radius:.4rem;box-sizing:border-box}
        button{width:100%;margin-top:1.25rem;padding:.7rem;background:#4f46e5;color:#fff;border:0;border-radius:.4rem;font-weight:600;cursor:pointer}
        .err{color:#f87171;font-size:.85rem;margin-top:.75rem;min-height:1rem}
        .ok{color:#4ade80;font-size:.85rem;margin-bottom:.75rem}
    </style></head><body>
    <form class="card" method="post">
        <h1>Прослойка подписки · вход</h1>
        <?php if ($just_installed): ?><div class="ok">Установка завершена. Войдите.</div><?php endif; ?>
        <label>Логин</label><input name="user" autofocus>
        <label>Пароль</label><input name="pass" type="password">
        <button type="submit">🔑 Войти</button>
        <div class="err"><?= h($err) ?></div>
    </form></body></html>
    <?php
    exit();
}

if (isset($_GET['ajax']) && is_auth()) {
    header('Content-Type: application/json; charset=utf-8');
    $a = $_GET['ajax'];

    if ($a === 'hwids') {
        $uuid = $_GET['uuid'] ?? '';
        $err = '';
        $devices = $uuid !== '' ? remnawave_user_hwids($uuid, $err) : [];
        echo json_encode(['ok' => $err === '', 'error' => $err, 'devices' => $devices], JSON_UNESCAPED_UNICODE);
        exit();
    }

    if ($a === 'del_hwid' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        if (!csrf_ok()) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'CSRF']); exit(); }
        $uuid = $_POST['uuid'] ?? '';
        $hwid = $_POST['hwid'] ?? '';
        [$ok, $code, $data, $e] = remnawave_delete_hwid($uuid, $hwid);
        echo json_encode(['ok' => $ok, 'error' => $e]);
        exit();
    }

    if ($a === 'block_hwid' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        if (!csrf_ok()) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'CSRF']); exit(); }
        $hwid  = trim($_POST['hwid'] ?? '');
        $uname = trim($_POST['username'] ?? '');
        if ($hwid === '') { echo json_encode(['ok' => false, 'error' => 'empty hwid']); exit(); }
        if (($_POST['block'] ?? '1') === '1') {
            upsert_override('hwid', $hwid, 'blocked', 'manual', $uname !== '' ? $uname : null, 'HWID-бан из «Устройств»');
        } else {
            delete_override('hwid', $hwid);
        }
        echo json_encode(['ok' => true]);
        exit();
    }

    if ($a === 'test_forward' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        if (!csrf_ok()) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'CSRF']); exit(); }
        $payload = json_encode(['event' => 'test.ping', 'data' => ['ts' => time(), 'source' => 'middleware']], JSON_UNESCAPED_UNICODE);
        $results = forward_webhook($payload, 'test.ping', true);
        echo json_encode(['ok' => true, 'results' => $results], JSON_UNESCAPED_UNICODE);
        exit();
    }

    if ($a === 'reqlog') {
        $rows = [];
        ensure_reqlog_hwid();
        if ($pdo = db()) {
            try {
                foreach ($pdo->query('SELECT ts, ip, short_uuid, user_agent, decision, expire_ts, hwid, ' . sql_epoch('ts') . ' AS ts_epoch FROM request_log ORDER BY id DESC LIMIT 300') as $r) {
                    $rows[] = $r;
                }
            } catch (Throwable $e) {}
        }
        echo json_encode(['ok' => true, 'rows' => $rows, 'stats' => reqlog_today_stats()], JSON_UNESCAPED_UNICODE);
        exit();
    }

    if ($a === 'sysinfo') {
        echo json_encode([
            'ok'     => true,
            'load'   => metrics_load_summary(),
            'series' => metrics_minute_series(60),
            'peaks'  => metrics_recent_peaks(200),
            'sys'    => ['load' => metrics_system_info()['load'], 'mem_peak' => memory_get_peak_usage(true)],
            'db'     => ['size' => metrics_db_info()['size']],
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    if ($a === 'save_rules' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        if (!csrf_ok()) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'CSRF']); exit(); }
        rules_save_from_json($_POST['response_rules_json'] ?? '[]');
        echo json_encode(['ok' => true]);
        exit();
    }

    if ($a === 'test_rule' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        if (!csrf_ok()) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'CSRF']); exit(); }
        $ov = ['user-agent' => (string) ($_POST['ua'] ?? '')];
        $os = strtolower(trim((string) ($_POST['os'] ?? '')));
        if ($os !== '') $ov['x-device-os'] = $os;
        $res = rules_test($ov);
        echo json_encode(['ok' => true, 'matched' => $res['matched'], 'headers' => $res['headers']], JSON_UNESCAPED_UNICODE);
        exit();
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'unknown ajax']);
    exit();
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && is_auth()) {
    if (!csrf_ok()) { http_response_code(400); die('CSRF'); }
    $action = $_POST['action'] ?? '';

    if ($action === 'save_hwid') {
        $split = fn($v) => array_values(array_filter(array_map('trim', explode("\n", str_replace("\r", '', (string) $v))), fn($s) => $s !== ''));
        set_setting('blocked_remarks', json_encode($split($_POST['blocked_remarks'] ?? ''), JSON_UNESCAPED_UNICODE));
        flash('Настройки HWID-блокировки сохранены');
        header('Location: index.php?tab=hwid'); exit();
    }

    if ($action === 'save_grace') {
        set_setting('grace_squad_enabled', isset($_POST['grace_squad_enabled']) ? '1' : '0');
        set_setting('grace_squad_uuid', trim($_POST['grace_squad_uuid'] ?? ''));
        $gb = (float) str_replace(',', '.', (string) ($_POST['grace_traffic_gb'] ?? '0'));
        set_setting('grace_traffic_bytes', (string) (int) round(max(0, $gb) * 1073741824));
        $strat = (string) ($_POST['grace_traffic_strategy'] ?? 'NO_RESET');
        set_setting('grace_traffic_strategy', in_array($strat, ['NO_RESET', 'DAY', 'WEEK', 'MONTH', 'MONTH_ROLLING'], true) ? $strat : 'NO_RESET');
        $gh = trim((string) ($_POST['grace_hwid_limit'] ?? ''));
        set_setting('grace_hwid_limit', $gh === '' ? '' : (string) max(0, (int) $gh));
        set_setting('grace_days', ($_POST['grace_days'] ?? '') === '' ? '' : (string) max(0, (int) $_POST['grace_days']));
        flash('Настройки грейс-сквада сохранены');
        header('Location: index.php?tab=subst'); exit();
    }

    if ($action === 'save_connection') {
        set_setting('target_domain', trim($_POST['target_domain'] ?? ''));
        set_setting('mirror_domain', trim($_POST['mirror_domain'] ?? ''));
        set_setting('remnawave_url', rtrim(trim($_POST['remnawave_url'] ?? ''), '/'));
        set_setting('remnawave_cookie', trim($_POST['remnawave_cookie'] ?? ''));
        if (($_POST['remnawave_api_key'] ?? '') !== '') set_setting('remnawave_api_key', trim($_POST['remnawave_api_key']));
        if (($_POST['webhook_secret'] ?? '') !== '')   set_setting('webhook_secret', trim($_POST['webhook_secret']));
        set_setting('trust_header_expire', isset($_POST['trust_header_expire']) ? '1' : '0');
        set_setting('tls_verify', isset($_POST['tls_verify']) ? '1' : '0');
        set_setting('proxy_timeout', (string) max(5, (int) ($_POST['proxy_timeout'] ?? 30)));
        flash('Настройки подключения сохранены');
        header('Location: index.php?tab=connection'); exit();
    }

    if ($action === 'save_branding') {
        set_setting('service_name', trim($_POST['service_name'] ?? ''));
        set_setting('service_logo_url', trim($_POST['service_logo_url'] ?? ''));
        $be = '';
        brand_refresh($be);
        flash($be !== '' ? ('Брендинг сохранён. API панели: ' . $be) : 'Брендинг сохранён и обновлён');
        header('Location: index.php?tab=branding'); exit();
    }

    if ($action === 'save_forward') {
        set_setting('forward_enabled', isset($_POST['forward_enabled']) ? '1' : '0');
        set_setting('forward_timeout', (string) max(2, (int) ($_POST['forward_timeout'] ?? 8)));
        $arr   = json_decode((string) ($_POST['forward_targets_json'] ?? '[]'), true);
        $clean = [];
        if (is_array($arr)) {
            foreach ($arr as $t) {
                if (!is_array($t)) continue;
                $url = trim((string) ($t['url'] ?? ''));
                if ($url === '') continue;
                $clean[] = [
                    'name'    => trim((string) ($t['name'] ?? '')),
                    'url'     => $url,
                    'secret'  => (string) ($t['secret'] ?? ''),
                    'enabled' => !empty($t['enabled']),
                ];
            }
        }
        set_setting('forward_targets', json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        ensure_forward_log();
        flash('Настройки раздвоения сохранены');
        header('Location: index.php?tab=webhooks'); exit();
    }

    if ($action === 'clear_fwdlog') {
        if ($pdo = db()) { try { $pdo->exec('DELETE FROM forward_log'); } catch (Throwable $e) {} flash('Лог пересылки очищен'); }
        header('Location: index.php?tab=fwdlog'); exit();
    }

    if ($action === 'save_response_rules') {
        rules_save_from_json($_POST['response_rules_json'] ?? '[]');
        flash('Правила ответа сохранены');
        header('Location: index.php?tab=rules'); exit();
    }

    if ($action === 'update_check') {
        $e = '';
        $r = update_refresh($e);
        flash($r !== null ? 'Проверка обновлений выполнена' : ('Ошибка проверки: ' . ($e !== '' ? $e : 'нет связи с GitHub')));
        header('Location: index.php?tab=update'); exit();
    }

    if ($action === 'update_set_current') {
        $e = '';
        flash(update_set_current($e) ? 'Текущая версия отмечена базовым коммитом' : ('Ошибка: ' . $e));
        header('Location: index.php?tab=update'); exit();
    }

    if ($action === 'update_apply') {
        $e = ''; $log = [];
        $ok = update_apply($log, $e);
        set_setting('update_last_log', json_encode($log, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        flash($ok ? ('Обновление применено, файлов: ' . count($log)) : ('Обновление не выполнено: ' . $e));
        header('Location: index.php?tab=update'); exit();
    }

    if ($action === 'update_rollback') {
        $e = ''; $log = [];
        $ok = update_rollback($log, $e);
        set_setting('update_last_log', json_encode($log, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        flash($ok ? ('Откат выполнен, файлов: ' . count($log)) : ('Откат не выполнен: ' . $e));
        header('Location: index.php?tab=update'); exit();
    }

    if ($action === 'save_app_headers') {
        $arr = json_decode((string) ($_POST['app_headers_json'] ?? '[]'), true);
        $clean = [];
        if (is_array($arr)) {
            foreach ($arr as $t) {
                if (!is_array($t)) continue;
                $name = trim((string) ($t['name'] ?? ''));
                if ($name === '') continue;
                $clean[] = [
                    'name'    => $name,
                    'value'   => (string) ($t['value'] ?? ''),
                    'note'    => trim((string) ($t['note'] ?? '')),
                    'enabled' => !empty($t['enabled']),
                ];
            }
        }
        set_setting('app_headers', json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        flash('Заголовки приложений сохранены');
        header('Location: index.php?tab=headers'); exit();
    }

    if ($action === 'add_override') {
        $mt = $_POST['match_type'] === 'hwid' ? 'hwid' : 'shortuuid';
        $mv = trim($_POST['match_value'] ?? '');
        $rs = $_POST['reason'] === 'blocked' ? 'blocked' : 'expired';
        $note = trim($_POST['note'] ?? '');
        if ($mv !== '') { upsert_override($mt, $mv, $rs, 'manual', null, $note !== '' ? $note : 'manual'); flash('Оверрайд добавлен'); }
        header('Location: index.php?tab=overrides'); exit();
    }

    if ($action === 'del_override') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id && ($pdo = db())) { $pdo->prepare('DELETE FROM overrides WHERE id = ?')->execute([$id]); flash('Оверрайд удалён'); }
        header('Location: index.php?tab=overrides'); exit();
    }

    if ($action === 'clear_reqlog') {
        if ($pdo = db()) { $pdo->exec('DELETE FROM request_log'); flash('Лог запросов очищен'); }
        header('Location: index.php?tab=reqlog'); exit();
    }

    if ($action === 'clear_peaks') {
        ensure_metrics_tables();
        if ($pdo = db()) { try { $pdo->exec('DELETE FROM metrics_peak'); } catch (Throwable $e) {} flash('Лог пиков нагрузки очищен'); }
        header('Location: index.php?tab=sysinfo'); exit();
    }

    if ($action === 'save_metrics_cfg') {
        $f = (float) str_replace(',', '.', (string) ($_POST['metrics_peak_factor'] ?? '3'));
        set_setting('metrics_peak_factor', (string) ($f >= 1.5 ? $f : 3));
        set_setting('metrics_peak_floor', (string) max(5, (int) ($_POST['metrics_peak_floor'] ?? 30)));
        flash('Пороги детектора пиков сохранены');
        header('Location: index.php?tab=sysinfo'); exit();
    }

    if ($action === 'migrate_db') {
        $to  = $_POST['to'] ?? '';
        $cur = db_driver();
        $e = '';
        if ($to === 'mysql' && $cur !== 'mysql') {
            $mc = [
                'driver' => 'mysql',
                'host'   => (trim($_POST['m_host'] ?? '') ?: '127.0.0.1'),
                'port'   => (int) (trim($_POST['m_port'] ?? '') ?: 3306),
                'name'   => trim($_POST['m_name'] ?? ''),
                'user'   => trim($_POST['m_user'] ?? ''),
                'pass'   => (string) ($_POST['m_pass'] ?? ''),
            ];
            if ($mc['name'] === '' || $mc['user'] === '') flash('Укажите имя БД и пользователя MySQL.');
            else flash(db_migrate(db_conf(), $mc, $e) ? 'Миграция на MySQL завершена. Прослойка переключена на MySQL.' : ('Ошибка миграции: ' . $e));
        } elseif ($to === 'sqlite' && $cur !== 'sqlite') {
            flash(db_migrate(db_conf(), ['driver' => 'sqlite', 'path' => default_db_path()], $e) ? 'Миграция на SQLite завершена. Прослойка переключена на SQLite.' : ('Ошибка миграции: ' . $e));
        } else {
            flash('Нечего мигрировать — уже на этой БД.');
        }
        header('Location: index.php?tab=migrate'); exit();
    }
}

$tab   = $_GET['tab'] ?? 'users';
if ($tab === 'settings') $tab = 'connection';
if ($tab === 'headers') $tab = 'rules';
rules_migrate_legacy();
update_autocheck();
$token = csrf_token();
$flash = take_flash();
$pdo   = db();
$db_ok = $pdo !== null;

$overrides = [];
if ($db_ok) foreach ($pdo->query('SELECT * FROM overrides ORDER BY updated_at DESC LIMIT 500') as $r) $overrides[] = $r;
$ov_index = [];
foreach ($overrides as $o) if ($o['match_type'] === 'shortuuid') $ov_index[$o['match_value']] = $o;

$users = []; $users_err = '';
if ($tab === 'users') $users = remnawave_all_users($users_err);
$grace_shorts = [];
if ($tab === 'users' && $db_ok) {
    ensure_grace_table();
    try {
        $st = $pdo->prepare('SELECT short_uuid FROM grace_users WHERE grace_until > ?');
        $st->execute([time()]);
        foreach ($st as $g) $grace_shorts[(string) $g['short_uuid']] = true;
    } catch (Throwable $e) {}
}

$panel_headers = []; $panel_headers_err = '';
if ($tab === 'headers') $panel_headers = remnawave_panel_headers($panel_headers_err);

$reqlog = [];
if ($db_ok && $tab === 'reqlog') { ensure_reqlog_hwid(); foreach ($pdo->query('SELECT *, ' . sql_epoch('ts') . ' AS ts_epoch FROM request_log ORDER BY id DESC LIMIT 300') as $r) $reqlog[] = $r; }
$whlog = [];
$wh_user_cond = "(event LIKE 'user.%' OR short_uuid IS NOT NULL OR username IS NOT NULL)";
if ($db_ok && $tab === 'whlog') foreach ($pdo->query("SELECT * FROM webhook_log WHERE $wh_user_cond ORDER BY id DESC LIMIT 300") as $r) $whlog[] = $r;
if ($db_ok && $tab === 'whlog_other') foreach ($pdo->query("SELECT * FROM webhook_log WHERE NOT $wh_user_cond ORDER BY id DESC LIMIT 300") as $r) $whlog[] = $r;
$fwdlog = [];
if ($db_ok && $tab === 'fwdlog') {
    ensure_forward_log();
    try { foreach ($pdo->query('SELECT * FROM forward_log ORDER BY id DESC LIMIT 300') as $r) $fwdlog[] = $r; } catch (Throwable $e) {}
}

$short2name = [];
$hwid2info  = [];
$rl_total_users = 0; $rl_today_users = 0; $rl_today_devices = 0; $rl_total_devices = 0; $rl_today_label = date('d.m.Y');
if ($tab === 'reqlog') {
    $tmp_e = '';
    $all_u = remnawave_all_users($tmp_e);
    $rl_total_users = count($all_u);
    foreach ($all_u as $u) {
        if (!empty($u['shortUuid'])) $short2name[$u['shortUuid']] = (string) ($u['username'] ?? '');
    }
    foreach ($overrides as $o) {
        if (($o['match_type'] ?? '') === 'hwid') {
            $lbl = trim((string) ($o['username'] ?? '')); if ($lbl === '') $lbl = trim((string) ($o['note'] ?? ''));
            $hwid2info[mb_strtolower((string) $o['match_value'])] = $lbl;
        }
    }
    if ($db_ok) {
        $rl_stats = reqlog_today_stats();
        $rl_today_users   = $rl_stats['today_users'];
        $rl_today_devices = $rl_stats['today_devices'];
        $rl_total_devices = $rl_stats['total_devices'];
        $rl_today_label   = $rl_stats['label'];
    }
}

$sys_info = []; $sys_db = []; $sys_load = []; $sys_series = []; $sys_peaks = [];
if ($tab === 'sysinfo') {
    ensure_metrics_tables();
    $sys_info   = metrics_system_info();
    $sys_db     = metrics_db_info();
    $sys_load   = metrics_load_summary();
    $sys_series = metrics_minute_series(60);
    $sys_peaks  = metrics_recent_peaks(200);
}

$grace_list = [];
if ($db_ok && $tab === 'grace_users') {
    ensure_grace_table();
    try { foreach ($pdo->query('SELECT *, ' . sql_epoch('created_at') . ' AS created_epoch FROM grace_users ORDER BY grace_until DESC LIMIT 500') as $r) $grace_list[] = $r; } catch (Throwable $e) {}
}

$blocked_text  = implode("\n", get_blocked_remarks());
$grace_squads  = []; $grace_squads_err = '';
if ($tab === 'subst' && remnawave_url() !== '' && remnawave_token() !== '') {
    $grace_squads = remnawave_internal_squads($grace_squads_err);
}
$mirror        = mirror_domain();
$wh_url        = ($mirror !== '' ? ('https://' . $mirror . '/webhook.php') : '/webhook.php');

$tab_titles = ['users' => 'Пользователи', 'branding' => 'Брендинг', 'connection' => 'Подключение', 'webhooks' => 'Вебхуки', 'subst' => 'Грейс-сквад для истёкших', 'headers' => 'Заголовки приложений', 'rules' => 'Правила ответа по приложению', 'hwid' => 'HWID — заблокированные', 'overrides' => 'Оверрайды', 'reqlog' => 'Лог запросов', 'whlog' => 'Лог вебхуков · юзеры', 'whlog_other' => 'Лог вебхуков · прочее', 'fwdlog' => 'Лог пересылки', 'grace_users' => 'Грейс-юзеры', 'sysinfo' => 'О системе', 'update' => 'Обновление', 'migrate' => 'Миграция БД'];
$tab_title  = $tab_titles[$tab] ?? 'Админка';
$bc_now = json_decode((string) setting('brand_cache', '{}'), true);
if (!is_array($bc_now)) $bc_now = [];
$manual_brand = trim((string) setting('service_name', '')) !== '' && trim((string) setting('service_logo_url', '')) !== '';
$brand_stale = !$manual_brand && (
    (int) ($bc_now['v'] ?? 0) < 5
    || ((($bc_now['name'] ?? '') === '' || ($bc_now['logo_file'] ?? '') === '') && (time() - (int) ($bc_now['ts'] ?? 0) > 600))
);
if ($db_ok && $brand_stale && remnawave_url() !== '' && remnawave_token() !== '') { brand_refresh(); }
$brand      = service_brand();
$brand_icon = $brand['logo_file'] !== '' ? $brand['logo_file'] : '';
$brand_emoji = (string) ($brand['emoji'] ?? '');
$default_logo = "data:image/svg+xml,%3Csvg%20xmlns='http://www.w3.org/2000/svg'%20viewBox='0%200%2024%2024'%20fill='%2322b8cf'%3E%3Cpath%20d='M12%202l8%203v6c0%205-3.5%208.5-8%2010-4.5-1.5-8-5-8-10V5z'/%3E%3C/svg%3E";
$emoji_favicon = ($brand_icon === '' && $brand_emoji !== '')
    ? 'data:image/svg+xml,' . rawurlencode("<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'><text x='32' y='36' font-size='50' text-anchor='middle' dominant-baseline='central'>" . $brand_emoji . "</text></svg>")
    : '';
$fav_href = $brand_icon !== '' ? $brand_icon : ($emoji_favicon !== '' ? $emoji_favicon : $default_logo);
?>
<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($brand['name']) ?> · админка</title>
<link rel="icon" href="<?= $brand_icon !== '' ? h($brand_icon) : $fav_href ?>">
<script>(function(){try{var t=localStorage.getItem('submw_theme');if(!t||t==='system')t=matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light';document.documentElement.setAttribute('data-theme',t);}catch(e){document.documentElement.setAttribute('data-theme','dark');}})();</script>
<script>
window.phEsc=function(s){var d=document.createElement('div');d.textContent=(s==null?'':s);return d.innerHTML;};
window.phLines=function(id){var el=document.getElementById(id);if(!el)return [];return el.value.split('\n').map(function(s){return s.trim();}).filter(function(s){return s.length;});};
window.phSupportName=function(id){var el=document.getElementById(id);if(!el)return '';var v=el.value.trim();if(!v)return '';var h=v.indexOf('#');if(h<0)return 'Тех. поддержка';var f=v.substring(h+1);try{f=decodeURIComponent(f);}catch(e){}return f||'Тех. поддержка';};
window.phRow=function(name,support){return '<div class="srow'+(support?' support':'')+'"><span class="dot"></span><span class="nm">'+phEsc(name)+(support?'<span class="ph-badge">рабочий</span>':'')+'</span><span class="pg">'+(support?'42 ms':'—')+'</span></div>';};
window.phRender=function(o){var rows=[];(o.list||[]).forEach(function(lid){phLines(lid).forEach(function(n){rows.push(phRow(n,false));});});if(o.support){var en=o.supportChk?document.getElementById(o.supportChk):null;if(!en||en.checked){var sn=phSupportName(o.support);if(sn)rows.push(phRow(sn,true));}}var t=o.title?((document.getElementById(o.title).value||'').trim()||'(как у origin)'):(o.titleText||'');var te=document.getElementById(o.titleEl);if(te)te.textContent=t;var se=document.getElementById(o.subEl);if(se)se.textContent=o.sub||'';var le=document.getElementById(o.listEl);if(le)le.innerHTML=rows.length?rows.join(''):'<div class="ph-empty">пусто — добавьте строки слева</div>';};
window.LogPager=function(opts){
    var sizes = opts.sizes || [10,25,50,0];
    var body  = document.getElementById(opts.bodyId);
    var top   = document.getElementById(opts.topId);
    var bot   = opts.botId ? document.getElementById(opts.botId) : null;
    if(!body || !top) return null;
    var size, page = 1;
    try { size = parseInt(localStorage.getItem(opts.storeKey),10); } catch(e){}
    if(isNaN(size) || sizes.indexOf(size)<0) size = 25;
    function dataRows(){
        return Array.prototype.filter.call(body.children, function(tr){
            return !tr.querySelector('td[colspan]');
        });
    }
    function label(s){ return s===0 ? 'Все' : String(s); }
    function buildSelect(){
        var sel = '<label class="pgr-size">На странице: <select>';
        sizes.forEach(function(s){ sel += '<option value="'+s+'"'+(s===size?' selected':'')+'>'+label(s)+'</option>'; });
        return sel + '</select></label>';
    }
    function render(){
        var rows = dataRows(), total = rows.length;
        var per = size===0 ? (total||1) : size;
        var pages = Math.max(1, Math.ceil(total/per));
        if(page>pages) page = pages;
        var start = (page-1)*per, end = start+per;
        rows.forEach(function(tr,i){ tr.style.display = (i>=start && i<end) ? '' : 'none'; });
        var nav = '';
        if(total>per){
            nav = '<div class="pgr-nav">'
                + '<button type="button" class="pgr-b" data-go="prev"'+(page<=1?' disabled':'')+'>◀</button>'
                + '<span class="pgr-st">'+((total?start+1:0))+'–'+Math.min(end,total)+' из '+total+' · стр. '+page+'/'+pages+'</span>'
                + '<button type="button" class="pgr-b" data-go="next"'+(page>=pages?' disabled':'')+'>▶</button>'
                + '</div>';
        } else {
            nav = '<div class="pgr-nav"><span class="pgr-st">Всего: '+total+'</span></div>';
        }
        top.innerHTML = buildSelect() + nav;
        if(bot) bot.innerHTML = total>per ? nav : '';
        function wire(host){
            if(!host) return;
            var s = host.querySelector('select');
            if(s) s.addEventListener('change', function(){
                size = parseInt(this.value,10); page = 1;
                try{ localStorage.setItem(opts.storeKey, String(size)); }catch(e){}
                render();
            });
            host.querySelectorAll('.pgr-b').forEach(function(b){
                b.addEventListener('click', function(){
                    if(this.dataset.go==='prev' && page>1) page--;
                    if(this.dataset.go==='next') page++;
                    render();
                });
            });
        }
        wire(top); wire(bot);
    }
    render();
    return { refresh: function(resetPage){ if(resetPage) page=1; render(); } };
};
</script>
<link rel="stylesheet" href="assets/fonts.css?v=<?= substr(@md5_file(__DIR__ . '/assets/fonts.css') ?: '0', 0, 10) ?>">
<link rel="stylesheet" href="assets/admin.css?v=<?= substr(@md5_file(__DIR__ . '/assets/admin.css') ?: '0', 0, 10) ?>">
</head><body>
<?php
$nav = [
    'users'     => ['Пользователи', '<circle cx="9" cy="7" r="4"/><path d="M3 21v-2a4 4 0 0 1 4-4h4a4 4 0 0 1 4 4v2"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/><path d="M21 21v-2a4 4 0 0 0-3-3.85"/>'],
    'branding'  => ['Брендинг', '<circle cx="12" cy="12" r="9"/><path d="M12 7v10M8.5 9.5h5a1.75 1.75 0 0 1 0 3.5H9a1.75 1.75 0 0 0 0 3.5h5.5"/>'],
    'connection'=> ['Подключение', '<path d="M9 7H6a3 3 0 0 0 0 6h3"/><path d="M15 7h3a3 3 0 0 1 0 6h-3"/><line x1="8" y1="10" x2="16" y2="10"/>'],
    'webhooks'  => ['Вебхуки', '<path d="M18 8a3 3 0 1 0-2.6-4.5"/><circle cx="6" cy="16" r="3"/><circle cx="18" cy="18" r="3"/><path d="M12 11l-3.6 6"/><path d="M12 7v4l3.6 6"/>'],
    'subst'     => ['Грейс-сквад', '<path d="M4 4h16v6H4z"/><path d="M4 14h16v6H4z"/><path d="M8 17h8"/>'],
    'headers'   => ['Заголовки', '<polyline points="7 8 3 12 7 16"/><polyline points="17 8 21 12 17 16"/><line x1="13.5" y1="4" x2="10.5" y2="20"/>'],
    'rules'     => ['Правила ответа', '<path d="M4 6h10"/><path d="M4 12h7"/><path d="M4 18h10"/><circle cx="18" cy="8" r="2"/><circle cx="16" cy="16" r="2"/>'],
    'hwid'      => ['HWID', '<rect x="5" y="2" width="14" height="20" rx="2"/><line x1="9" y1="18" x2="15" y2="18"/><path d="M9 6h6M9 9h6"/>'],
    'overrides' => ['Оверрайды', '<path d="M12 3l7 3v5c0 4.5-3 7.5-7 9-4-1.5-7-4.5-7-9V6z"/><path d="M9.5 12l1.8 1.8L15 9.8"/>'],
    'reqlog'    => ['Лог запросов', '<line x1="8" y1="6" x2="20" y2="6"/><line x1="8" y1="12" x2="20" y2="12"/><line x1="8" y1="18" x2="20" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/>'],
    'whlog'       => ['Юзер-лог', '<path d="M13 2L3 14h7l-1 8 10-12h-7z"/>'],
    'whlog_other' => ['Прочие события', '<circle cx="12" cy="12" r="9"/><path d="M12 7.5v5l3 2"/>'],
    'fwdlog'    => ['Лог пересылки', '<path d="M4 12h12"/><path d="M12 6l6 6-6 6"/><path d="M20 4v16"/>'],
    'grace_users' => ['Грейс-юзеры', '<circle cx="9" cy="7" r="3"/><path d="M3 21v-1a5 5 0 0 1 5-5h2"/><path d="M16 11l2 2 4-4"/>'],
    'sysinfo'   => ['О системе', '<path d="M22 12h-4l-3 9L9 3l-3 9H2"/>'],
    'update'    => ['Обновление', '<path d="M21 12a9 9 0 1 1-3-6.7"/><polyline points="21 3 21 9 15 9"/>'],
    'migrate'   => ['Миграция БД', '<ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v14c0 1.6 3.6 3 8 3s8-1.4 8-3V5"/><path d="M4 12c0 1.6 3.6 3 8 3s8-1.4 8-3"/>'],
];
?>
<?php
$nav_groups = [
    'Обзор'      => ['users'],
    'Настройки'  => ['branding', 'connection', 'webhooks'],
    'Управление' => ['subst', 'rules', 'hwid', 'overrides'],
    'Логи'       => ['reqlog', 'whlog', 'whlog_other', 'fwdlog', 'grace_users'],
    'Обслуживание' => ['sysinfo', 'migrate'],
];
function nav_link($key, $it, $active, $badge = false) {
    $svg = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . $it[1] . '</svg>';
    $dot = $badge ? '<span class="nav-dot" title="Доступно обновление"></span>' : '';
    return '<a href="?tab=' . $key . '" class="' . ($active ? 'active' : '') . '">' . $svg . '<span>' . h($it[0]) . '</span>' . $dot . '</a>';
}
?>
<div class="rw-app">
    <aside class="rw-side">
        <div class="rw-brand"><?php if ($brand_icon !== ''): ?><img src="<?= h($brand_icon) ?>" alt=""><?php elseif ($brand_emoji !== ''): ?><span class="rw-emoji"><?= $brand_emoji ?></span><?php else: ?><img src="<?= $default_logo ?>" alt=""><?php endif; ?><b><?= h($brand['name']) ?></b></div>
        <nav class="rw-nav">
            <?php foreach ($nav_groups as $glabel => $keys): ?>
                <div class="navgroup"><?= h($glabel) ?></div>
                <?php foreach ($keys as $key): ?>
                    <?= nav_link($key, $nav[$key], $tab === $key, $key === 'update' && update_available()) ?>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </nav>
        <div class="rw-foot">
            <div class="theme-seg" role="group" aria-label="Тема оформления">
                <button type="button" data-theme-set="light" onclick="setTheme('light')" aria-label="Светлая тема" title="Светлая"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg><span>Свет</span></button>
                <button type="button" data-theme-set="system" onclick="setTheme('system')" aria-label="Системная тема" title="Как в системе"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="12" rx="2"/><path d="M8 20h8M12 16v4"/></svg><span>Авто</span></button>
                <button type="button" data-theme-set="dark" onclick="setTheme('dark')" aria-label="Тёмная тема" title="Тёмная"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg><span>Тьма</span></button>
            </div>
        </div>
    </aside>
    <main class="rw-main">
        <header class="rw-header">
            <div style="display:flex;align-items:center;gap:.7rem;min-width:0">
                <button type="button" class="navtoggle" aria-label="Меню" onclick="document.querySelector('.rw-app').classList.toggle('nav-open')">☰</button>
                <h1 class="pagetitle"><?= h($tab_title) ?></h1>
            </div>
            <div class="rw-hcontrols">
                <a class="hbtn" href="https://github.com/Mrvibecodic/remnawave-subscription-middleware" target="_blank" rel="noopener" title="GitHub — поставьте звезду ⭐"><svg class="hbtn-star" viewBox="0 0 24 24" fill="#f5b50a" stroke="#1a1a1a" stroke-width="1.4" stroke-linejoin="round" stroke-linecap="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg><span id="ghStarCount"></span></a>
                <a class="hbtn hbtn-ver" href="?tab=update" title="<?= update_available() ? 'Доступно обновление прослойки' : 'Версия прослойки' ?>">Версия <code><?php $iv = update_installed_commit(); echo $iv !== '' ? h(substr($iv, 0, 7)) : '—'; ?></code><?php if (update_available()): ?><span class="hbtn-dot" title="Доступно обновление"></span><?php endif; ?></a>
                <a class="hbtn" href="?logout=1" title="Выйти" aria-label="Выйти"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg></a>
            </div>
        </header>
        <div class="rw-content">
<?php if (!$db_ok): ?><div class="warn">Нет связи с БД. Проверьте config.php.</div><?php endif; ?>
<?php if ($flash): ?><div id="flashMsg" data-msg="<?= h($flash) ?>" style="display:none"></div><?php endif; ?>

<?php if ($tab === 'users'): ?>
    <?php include __DIR__ . '/inc/tab_users.php'; ?>
<?php elseif ($tab === 'branding'): ?>
    <?php include __DIR__ . '/inc/tab_branding.php'; ?>
<?php elseif ($tab === 'connection'): ?>
    <?php include __DIR__ . '/inc/tab_connection.php'; ?>
<?php elseif ($tab === 'webhooks'): ?>
    <?php include __DIR__ . '/inc/tab_webhooks.php'; ?>

<?php elseif ($tab === 'subst'): ?>
    <?php include __DIR__ . '/inc/tab_subst.php'; ?>

<?php elseif ($tab === 'headers'): ?>
    <?php include __DIR__ . '/inc/tab_headers.php'; ?>

<?php elseif ($tab === 'rules'): ?>
    <?php include __DIR__ . '/inc/tab_rules.php'; ?>

<?php elseif ($tab === 'hwid'): ?>
    <?php include __DIR__ . '/inc/tab_hwid.php'; ?>

<?php elseif ($tab === 'overrides'): ?>
    <?php include __DIR__ . '/inc/tab_overrides.php'; ?>

<?php elseif ($tab === 'reqlog'): ?>
    <?php include __DIR__ . '/inc/tab_reqlog.php'; ?>

<?php elseif ($tab === 'whlog' || $tab === 'whlog_other'): ?>
    <?php include __DIR__ . '/inc/tab_whlog.php'; ?>

<?php elseif ($tab === 'fwdlog'): ?>
    <?php include __DIR__ . '/inc/tab_fwdlog.php'; ?>

<?php elseif ($tab === 'grace_users'): ?>
    <?php include __DIR__ . '/inc/tab_grace_users.php'; ?>
<?php elseif ($tab === 'migrate'): ?>
    <?php include __DIR__ . '/inc/tab_migrate.php'; ?>

<?php elseif ($tab === 'sysinfo'): ?>
    <?php include __DIR__ . '/inc/tab_sysinfo.php'; ?>
<?php elseif ($tab === 'update'): ?>
    <?php include __DIR__ . '/inc/tab_update.php'; ?>
<?php endif; ?>
    </div>
    </main>
</div>

<div id="helpOv" class="help-ov" onclick="if(event.target===this)helpClose()">
    <aside class="help-drawer" role="dialog" aria-label="Справка">
        <div class="help-h"><span id="helpTitle">Справка</span><button type="button" class="modal-x" onclick="helpClose()" aria-label="Закрыть">×</button></div>
        <div class="help-b" id="helpBody"></div>
        <div style="padding:.8rem 1.1rem;border-top:1px solid var(--line)"><button type="button" class="btn ghost" style="width:100%" onclick="helpClose()">Закрыть</button></div>
    </aside>
</div>
<style>
    .qh{display:inline-flex;align-items:center;justify-content:center;width:18px;height:18px;border-radius:50%;border:1px solid var(--line);color:var(--muted);font-size:.7rem;font-weight:700;cursor:pointer;background:transparent;vertical-align:middle;margin-left:.3rem;padding:0;line-height:1}
    .qh:hover{border-color:var(--accent);color:var(--accent-text)}
    .hint{display:block;font-weight:400;color:var(--muted);font-size:.84rem;margin-top:.25rem;line-height:1.5}
    .hint code{font-size:.92em}
    .help-ov{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:140}
    .help-ov.open{display:block}
    .help-drawer{position:fixed;top:0;right:0;height:100vh;width:390px;max-width:92vw;background:var(--card);border-left:1px solid var(--line);box-shadow:var(--shadow);display:flex;flex-direction:column;transform:translateX(100%);transition:transform .2s}
    .help-ov.open .help-drawer{transform:none}
    .help-h{display:flex;align-items:center;justify-content:space-between;gap:1rem;padding:1rem 1.1rem;border-bottom:1px solid var(--line);color:var(--text-strong);font-weight:700}
    .help-b{padding:1rem 1.1rem;overflow:auto;font-size:.88rem;line-height:1.6;flex:1}
    .help-b h4{color:var(--text-strong);margin:1rem 0 .3rem;font-size:.9rem}
    .help-b p{margin:.5rem 0}
    .help-b ul{margin:.4rem 0;padding-left:1.15rem}
    .help-b li{margin:.25rem 0}
    .help-b code{background:var(--bg2);padding:.1rem .35rem;border-radius:5px;border:1px solid var(--line);font-size:.85em;word-break:break-all}
</style>
<div id="uiToast" class="toast"></div>

<div id="uiDlg" class="modal-overlay" onclick="if(event.target===this)uiDlgClose()">
    <div class="modal" style="max-width:430px">
        <div class="modal-head"><span id="uiDlgTitle">Подтверждение</span><button type="button" class="modal-x" onclick="uiDlgClose()">×</button></div>
        <div class="modal-body">
            <div id="uiDlgMsg" style="white-space:pre-wrap;font-size:.9rem;color:var(--text)"></div>
            <div class="dlg-actions">
                <button type="button" class="btn ghost" id="uiDlgCancel" onclick="uiDlgClose()">Отмена</button>
                <button type="button" class="btn" id="uiDlgOk">OK</button>
            </div>
        </div>
    </div>
</div>
<script>
var HELP={
'origin':{t:'Origin — домен подписки',h:'<p>Реальный домен подписки Remnawave, откуда прослойка берёт рабочий конфиг для активных пользователей.</p><h4>Формат</h4><p>Только домен, <b>без</b> <code>https://</code> и без пути.</p><p>Пример: <code>sub.example.com</code></p><h4>Где взять</h4><p>Это домен публичной подписки панели. В <code>.env</code> панели — переменная <code>SUB_PUBLIC_DOMAIN</code> (часть до <code>/api/sub</code>).</p>'},
'mirror':{t:'Домен зеркала',h:'<p>Домен, на котором установлена эта прослойка — именно его видят клиенты в ссылках подписки. Менять обычно не нужно, подставляется автоматически.</p><h4>Формат</h4><p>Только домен, <b>без</b> <code>https://</code>.</p><p>Пример: <code>mirror.example.com</code></p><h4>Совет: ставьте зеркало на РФ-сервер</h4><p>РФ-сервер почти всегда доступен из России без VPN, поэтому ссылка подписки открывается стабильно. А проксирование с РФ-сервера на origin часто продолжает работать, даже если сам origin заблокирован у клиента или спрятан за Cloudflare-прокси — прослойка ходит на origin со своей стороны. Итог: клиенты обновляют подписку надёжнее.</p>'},
'rwurl':{t:'URL панели Remnawave',h:'<p>Адрес панели для обращения к её API (список пользователей, HWID-устройства, авто-брендинг). Работает с API-токеном ниже.</p><h4>Формат</h4><p><b>Полный URL со схемой</b> <code>https://</code> и <b>без</b> <code>/</code> на конце.</p><p>Пример: <code>https://panel.example.com</code></p><p>Отличие от Origin: здесь со схемой <code>https://</code>, а Origin — только домен.</p>'},
'cookie':{t:'Cookie панели (eGames)',h:'<p>Нужна, только если панель закрыта <b>cookie-защитой</b> reverse-proxy eGames (без верной куки панель отдаёт 404). Если такой защиты нет — оставьте поле пустым.</p><h4>Формат</h4><p>Одна кука вида <code>имя=значение</code>.</p><p>Пример: <code>aB3xK9pQ=Zt7mW2nR</code></p><h4>Где взять</h4><ul><li>в конфиге reverse-proxy eGames (nginx/Caddy), где проверяется защитная кука;</li><li>в выводе установщика eGames при настройке;</li><li>проще всего — в браузере: войдите в панель, откройте DevTools → Application → Cookies и скопируйте имя и значение защитной куки.</li></ul><p>Проект: <code>github.com/eGamesAPI/remnawave-reverse-proxy</code></p>'},
'apikey':{t:'API-токен панели',h:'<p>Bearer-токен для доступа к API панели Remnawave.</p><h4>Где взять</h4><p>Панель → раздел <b>API Tokens</b>: создайте токен и вставьте сюда.</p><h4>Поведение</h4><p>Оставьте поле пустым, чтобы не менять уже сохранённый токен.</p>'},
'whsecret':{t:'Секрет вебхука',h:'<p>Ключ, которым панель подписывает вебхуки (заголовок <code>X-Remnawave-Signature</code>), а прослойка сверяет подпись.</p><h4>Формат</h4><p>Минимум 32 символа, только <code>a-z A-Z 0-9</code>. Должен совпадать со значением <code>WEBHOOK_SECRET_HEADER</code> в <code>.env</code> панели.</p><h4>Где взять</h4><p>Вы задаёте его сами (например командой <code>openssl rand -hex 32</code>) и прописываете в <code>.env</code> панели. Готовые строки для <code>.env</code> — в разделе «Как включить вебхук в Remnawave». Пусто — не менять сохранённый.</p>'},
'trust':{t:'Доверять заголовку expire',h:'<p>Заголовок <code>subscription-userinfo: expire=…</code> от origin становится главным арбитром срока подписки.</p><h4>Рекомендуется включить</h4><p>Тогда продление само себя чинит: будущий <code>expire</code> мгновенно снимает флаг истечения, даже если вебхук о продлении потерялся. Выключать стоит только при отладке.</p>'},
'timeout':{t:'Таймаут проксирования',h:'<p>Сколько секунд прослойка ждёт ответа от origin при запросе подписки, прежде чем вернуть ошибку.</p><p>По умолчанию <code>30</code>. Можно уменьшить, если origin быстрый, или увеличить при медленной сети.</p>'},
'branding':{t:'Брендинг сервиса',h:'<p>Имя и логотип берутся автоматически из API панели Remnawave (Настройки кастомизации → <code>brandingSettings</code>: «Название бренда» и «Ссылка на логотип») и кешируются на диск и в БД — идут в название, лого и фавикон админки.</p><h4>Источник</h4><p>Публичный <code>/api/auth/status</code> (работает без прав токена), фолбэк — <code>/api/remnawave-settings</code>.</p><h4>Ручные поля</h4><p>Перебивают авто и независимы: можно задать только имя или только лого — второе останется из панели. Пусто = из API. Кнопка «Сохранить и обновить» заново запрашивает панель и перекачивает лого в кеш.</p>'},
'webhook_env':{t:'Включение вебхука в .env',h:'<p>UI для вебхуков в панели нет — они включаются в файле <code>.env</code> панели.</p><h4>Что добавить</h4><p><code>WEBHOOK_ENABLED=true</code>, <code>WEBHOOK_URL</code> = адрес этой прослойки (можно несколько через запятую без пробелов), <code>WEBHOOK_SECRET_HEADER</code> = ваш секрет (один на все URL). Затем перезапустите панель.</p><h4>Важно</h4><p>Секрет должен совпадать с полем «Секрет вебхука» в разделе Подключение. После перезапуска события появятся в Логе вебхуков с подписью <b>ok</b>.</p>'},
'forward':{t:'Раздвоение вебхука (тройник)',h:'<p>Сама панель умеет слать хук на <b>несколько</b> URL — через запятую (без пробелов) в <code>WEBHOOK_URL</code>. Но все они подписываются <b>одним</b> <code>WEBHOOK_SECRET_HEADER</code>.</p><h4>Зачем тогда тройник</h4><p>Он нужен, когда адресатам нужны <b>разные секреты</b> — прослойка переподписывает каждого <b>его</b> ключом в <code>X-Remnawave-Signature</code> (адресат примет пересылку как настоящий хук панели). Или когда пересылать надо <b>после</b> обработки прослойкой (грейс, блокировки и т.п.).</p><h4>Если хватает одного секрета</h4><p>Проще не использовать тройник, а перечислить URL-ы через запятую прямо в панели.</p>'}
};
function help(k){var d=HELP[k];if(!d)return;document.getElementById('helpTitle').textContent=d.t;document.getElementById('helpBody').innerHTML=d.h;document.getElementById('helpOv').classList.add('open');}
function helpClose(){var o=document.getElementById('helpOv');if(o)o.classList.remove('open');}
document.addEventListener('keydown',function(e){if(e.key==='Escape')helpClose();});
function themeMark(t){if(!t){try{t=localStorage.getItem('submw_theme')||'system';}catch(e){t='system';}}document.querySelectorAll('.theme-seg button').forEach(function(b){b.classList.toggle('on',b.getAttribute('data-theme-set')===t);});}
function setTheme(t){try{localStorage.setItem('submw_theme',t);}catch(e){}var eff=t;if(t==='system'){eff=matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light';}document.documentElement.setAttribute('data-theme',eff);themeMark(t);}
document.addEventListener('DOMContentLoaded',function(){themeMark();});
if(window.matchMedia){matchMedia('(prefers-color-scheme: dark)').addEventListener('change',function(e){var t='system';try{t=localStorage.getItem('submw_theme')||'system';}catch(_){}if(t==='system')document.documentElement.setAttribute('data-theme',e.matches?'dark':'light');});}
(function(){
    var dlg=document.getElementById('uiDlg'), msg=document.getElementById('uiDlgMsg'),
        title=document.getElementById('uiDlgTitle'), ok=document.getElementById('uiDlgOk'),
        cancel=document.getElementById('uiDlgCancel'), cb=null;
    window.uiDlgClose=function(){dlg.classList.remove('open');cb=null;};
    window.uiConfirm=function(message,onOk,okLabel,danger){
        cb=onOk; title.textContent='Подтверждение'; msg.textContent=message;
        cancel.style.display=''; ok.textContent=okLabel||'OK';
        ok.className='btn'+(danger?' danger':'');
        dlg.classList.add('open');
    };
    window.uiAlert=function(message,ttl){
        cb=null; title.textContent=ttl||'Сообщение'; msg.textContent=message;
        cancel.style.display='none'; ok.textContent='OK'; ok.className='btn';
        dlg.classList.add('open');
    };
    window.uiConfirmForm=function(form,message){ uiConfirm(message,function(){form.submit();},'Удалить',true); return false; };
    var toastEl=document.getElementById('uiToast'), toastT=null;
    window.uiToast=function(message){
        if(!toastEl) return;
        toastEl.textContent=message; toastEl.classList.add('show');
        clearTimeout(toastT); toastT=setTimeout(function(){toastEl.classList.remove('show');},10000);
    };
    toastEl && toastEl.addEventListener('click',function(){toastEl.classList.remove('show');});
    var fm=document.getElementById('flashMsg'); if(fm && fm.getAttribute('data-msg')) uiToast(fm.getAttribute('data-msg'));
    window.collToggle=function(b){var s=b.closest('.coll');if(!s)return;s.classList.toggle('collapsed');if(!/^next_/.test(s.dataset.coll||'')){try{localStorage.setItem('coll_'+s.dataset.coll,s.classList.contains('collapsed')?'1':'0');}catch(e){}}};
    document.querySelectorAll('.coll').forEach(function(s){if(/^next_/.test(s.dataset.coll||''))return;try{var v=localStorage.getItem('coll_'+s.dataset.coll);if(v==='1')s.classList.add('collapsed');else if(v==='0')s.classList.remove('collapsed');}catch(e){}});
    document.addEventListener('click',function(e){var app=document.querySelector('.rw-app');if(app&&app.classList.contains('nav-open')&&!e.target.closest('.rw-side')&&!e.target.closest('.navtoggle'))app.classList.remove('nav-open');});
    try{document.cookie='tzoff='+(-new Date().getTimezoneOffset())+';path=/;max-age=31536000;samesite=Lax';}catch(e){}
    ok.addEventListener('click',function(){var f=cb; uiDlgClose(); if(f)f();});
    document.addEventListener('keydown',function(e){if(e.key==='Escape')uiDlgClose();});
    (function(){var el=document.getElementById('ghStarCount');if(!el)return;try{var c=JSON.parse(localStorage.getItem('gh_stars')||'null');if(c&&Date.now()-c.t<21600000){el.textContent=c.n;return;}}catch(e){}fetch('https://api.github.com/repos/Mrvibecodic/remnawave-subscription-middleware').then(function(r){return r.json();}).then(function(d){if(d&&typeof d.stargazers_count==='number'){el.textContent=d.stargazers_count;try{localStorage.setItem('gh_stars',JSON.stringify({n:d.stargazers_count,t:Date.now()}));}catch(e){}}}).catch(function(){});})();
})();
</script>
</body></html>
