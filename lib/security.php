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
