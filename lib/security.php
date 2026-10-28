<?php

function ensure_login_attempts() {
    static $done = false;
    if ($done) return;
    $done = true;
    if (!($p = db())) return;
    try {
        if (db_driver() === 'mysql') {
            $p->exec("CREATE TABLE IF NOT EXISTS login_attempts (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                ip VARCHAR(45) NOT NULL,
                ts TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id), KEY idx_la_ip (ip), KEY idx_la_ts (ts)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } else {
            $p->exec("CREATE TABLE IF NOT EXISTS login_attempts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                ip TEXT NOT NULL,
                ts TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )");
            $p->exec("CREATE INDEX IF NOT EXISTS idx_la_ip ON login_attempts(ip)");
            $p->exec("CREATE INDEX IF NOT EXISTS idx_la_ts ON login_attempts(ts)");
        }
    } catch (Throwable $e) { error_log('submw login_attempts table: ' . $e->getMessage()); }
}

function admin_session_boot() {
    session_name('submw_admin');
    $ttl = 86400;
    $dir = dirname(__DIR__) . '/data/sessions';
    if (!is_dir($dir)) { @mkdir($dir, 0700, true); @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n"); }
    if (is_dir($dir) && is_writable($dir)) {
        @ini_set('session.save_path', $dir);
        @ini_set('session.gc_probability', '1');
        @ini_set('session.gc_divisor', '100');
    }
    @ini_set('session.gc_maxlifetime', (string) $ttl);
    @ini_set('session.cookie_lifetime', (string) $ttl);
    session_set_cookie_params([
        'lifetime' => $ttl,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => ((($_SERVER['HTTPS'] ?? '') === 'on') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')),
    ]);
}

function login_window() { return 900; }

function login_max_fails() { return 10; }

function login_remote_ip() { return (string) ($_SERVER['REMOTE_ADDR'] ?? ''); }

function login_recent_fails($ip) {
    if ($ip === '' || !($p = db())) return 0;
    ensure_login_attempts();
    try {
        $st = $p->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND ' . sql_epoch('ts') . ' >= ?');
        $st->execute([$ip, time() - login_window()]);
        return (int) $st->fetchColumn();
    } catch (Throwable $e) { return 0; }
}

function login_is_locked($ip) {
    return login_recent_fails($ip) >= login_max_fails();
}

function login_record_fail($ip) {
    if ($ip === '' || !($p = db())) return;
    ensure_login_attempts();
    try {
        $p->prepare('INSERT INTO login_attempts (ip) VALUES (?)')->execute([$ip]);
        if (random_int(1, 50) === 1) {
            $p->prepare('DELETE FROM login_attempts WHERE ' . sql_epoch('ts') . ' < ?')->execute([time() - 86400]);
        }
    } catch (Throwable $e) { error_log('submw login_record_fail: ' . $e->getMessage()); }
}

function login_clear($ip) {
    if ($ip === '' || !($p = db())) return;
    try { $p->prepare('DELETE FROM login_attempts WHERE ip = ?')->execute([$ip]); }
    catch (Throwable $e) {}
}

// Ключ-гейт админки: «имя=значение». Пусто — гейт выключен (установки до мастера).
function admin_gate_key() {
    $k = trim((string) setting('admin_gate_key', ''));
    return preg_match('~^[a-z][a-z0-9]{3,15}=[a-f0-9]{16,64}$~', $k) ? $k : '';
}

function admin_gate_new() {
    $a = 'abcdefghijklmnopqrstuvwxyz0123456789';
    $k = 'k';
    for ($i = 0; $i < 7; $i++) $k .= $a[random_int(0, 35)];
    $key = $k . '=' . bin2hex(random_bytes(12));
    set_setting('admin_gate_key', $key);
    return $key;
}

function admin_gate_link() {
    $key = admin_gate_key();
    if ($key === '') return '';
    $host = mirror_domain();
    return 'https://' . $host . '/admin/?' . $key;
}

function admin_gate_cookie_secure() { return (($_SERVER['HTTPS'] ?? '') === 'on') || (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'); }

// Lax, не Strict: по ссылке из мессенджера или почты Strict-кука не уходит и на редиректе той же цепочки.
function admin_gate_setcookie($key) {
    [$gk, $gv] = explode('=', $key, 2);
    setcookie($gk, $gv, ['expires' => time() + 86400 * 365, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => admin_gate_cookie_secure()]);
}

// Общий вход по ключу для мастера и админки. Ссылка с ключом ставит куку и ведёт на index.php?<имя> —
// имя ключа без значения доступа не даёт, но отличает «кука не вернулась» от обычного захода без ссылки.
function admin_gate_check($key) {
    [$gk, $gv] = explode('=', $key, 2);
    if (isset($_GET[$gk]) && is_string($_GET[$gk]) && $_GET[$gk] !== '' && hash_equals($gv, $_GET[$gk])) {
        admin_gate_setcookie($key);
        header('Location: index.php?' . $gk);
        exit();
    }
    $ok = isset($_COOKIE[$gk]) && hash_equals($gv, (string) $_COOKIE[$gk]);
    if (isset($_GET[$gk]) && $_GET[$gk] === '') {
        if ($ok) {
            $rest = $_GET;
            unset($rest[$gk]);
            header('Location: index.php' . ($rest ? '?' . http_build_query($rest) : ''));
            exit();
        }
        admin_gate_nocookie_page();
    }
    return $ok;
}

function admin_gate_pass() {
    $key = admin_gate_key();
    if ($key === '') return true;
    return admin_gate_check($key);
}

function admin_gate_nocookie_page() {
    http_response_code(403);
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow');
    $https = admin_gate_cookie_secure();
    submw_plain_page('Браузер не вернул cookie входа',
        '<p>Ссылка верная. Если вы открыли её из мессенджера или почты, браузер мог не отправить cookie на переходе — нажмите «Продолжить».</p>'
        . '<p><a class="go" href="index.php">Продолжить</a></p>'
        . ($https ? '<p>Если и так не входит — в браузере запрещены cookie для этого сайта; разрешите их и откройте ссылку снова.</p>'
            : '<p>Если и так не входит — откройте ссылку по <code>https://</code>: без HTTPS браузер может не сохранять cookie.</p>'));
}

// Служебная страница вне admin.css: ошибки до входа и до установки.
function submw_plain_page($title, $html, $code = 403) {
    http_response_code($code);
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow');
    echo '<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow"><title>Прослойка подписки</title>'
        . '<style>body{margin:0;font:15px/1.5 system-ui,sans-serif;background:#0f1115;color:#e6e8ee;display:flex;min-height:100vh;align-items:center;justify-content:center;padding:24px}main{max-width:620px}h1{font-size:1.25rem;margin:0 0 .6rem}code{background:#1b1f27;padding:.1rem .4rem;border-radius:6px;word-break:break-all}p{margin:.5rem 0}ol{padding-left:1.2rem}a.go{display:inline-block;background:#e6e8ee;color:#0f1115;text-decoration:none;font-weight:600;padding:.5rem 1.1rem;border-radius:8px}</style></head><body><main>'
        . '<h1>' . htmlspecialchars((string) $title, ENT_QUOTES, 'UTF-8') . '</h1>' . $html
        . '</main></body></html>';
    exit();
}

// Прослойка живёт только за реверсом по домену: по IP или localhost отдаём приманку.
function submw_host_is_local() {
    $host = strtolower(trim((string) ($_SERVER['HTTP_HOST'] ?? '')));
    if ($host === '') return true;
    $host = preg_replace('~:\d+$~', '', $host);
    $host = trim($host, '[]');
    if ($host === 'localhost' || substr($host, -10) === '.localhost') return true;
    return filter_var($host, FILTER_VALIDATE_IP) !== false;
}
