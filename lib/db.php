<?php

function config_path() { return dirname(__DIR__) . '/config.php'; }

function default_db_path() { return dirname(__DIR__) . '/data/submw.sqlite'; }

function db_conf() { return cfg()['db'] ?? null; }

function db_driver() {
    $c = db_conf();
    $d = is_array($c) ? ($c['driver'] ?? 'sqlite') : 'sqlite';
    return $d === 'mysql' ? 'mysql' : 'sqlite';
}

function ddl_settings($drv) {
    if ($drv === 'mysql') {
        return "CREATE TABLE IF NOT EXISTS settings (
            k VARCHAR(64) NOT NULL,
            v MEDIUMTEXT NOT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (k)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    }
    return "CREATE TABLE IF NOT EXISTS settings (
        k TEXT NOT NULL PRIMARY KEY,
        v TEXT NOT NULL,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )";
}

function ddl_forward_log($drv) {
    if ($drv === 'mysql') {
        return "CREATE TABLE IF NOT EXISTS forward_log (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            ts TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            event VARCHAR(64) NULL,
            target VARCHAR(255) NULL,
            http_code INT NULL,
            ok TINYINT(1) NOT NULL DEFAULT 0,
            error VARCHAR(255) NULL,
            PRIMARY KEY (id), KEY idx_fw_ts (ts)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    }
    return "CREATE TABLE IF NOT EXISTS forward_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ts TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        event TEXT NULL, target TEXT NULL, http_code INTEGER NULL,
        ok INTEGER NOT NULL DEFAULT 0, error TEXT NULL
    )";
}

function install_statements($drv = null) {
    $drv = $drv ?: db_driver();
    if ($drv === 'mysql') return install_statements_mysql();
    return install_statements_sqlite();
}

function install_seed_values() {
    return [
        'blocked_remarks'       => '["🚫 Устройство заблокировано","Обратитесь в поддержку","@your_support"]',
        'trust_header_expire'   => '1',
        'tls_verify'            => '1',
        'proxy_timeout'         => '30',
        'request_log_retention' => '50000',
        'forward_enabled'       => '0',
        'forward_targets'       => '[]',
        'forward_timeout'       => '8',
        'expired_grace_days'    => '7',
        'app_headers'           => '[]',
        'service_name'          => '',
        'service_logo_url'      => '',
        'brand_cache'           => '{}',
        'landing_preset'        => '1',
        'landing_fp'            => '',
        'landing_fp_ack'        => '',
        'chat_enabled'          => '0',
        'chat_tg_api_base'      => '',
    ];
}

function install_statements_sqlite() {
    return [
        ddl_settings('sqlite'),
        "CREATE TABLE IF NOT EXISTS overrides (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            match_type TEXT NOT NULL, match_value TEXT NOT NULL,
            reason TEXT NOT NULL, source TEXT NOT NULL DEFAULT 'manual',
            username TEXT NULL, note TEXT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(match_type, match_value)
        )",
        "CREATE INDEX IF NOT EXISTS idx_ov_value ON overrides(match_value)",
        "CREATE TABLE IF NOT EXISTS request_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ts TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            ip TEXT NULL, short_uuid TEXT NULL, path TEXT NULL, user_agent TEXT NULL,
            decision TEXT NOT NULL DEFAULT 'normal', expire_ts INTEGER NULL, hwid TEXT NULL,
            is_app INTEGER NOT NULL DEFAULT 1
        )",
        "CREATE INDEX IF NOT EXISTS idx_rl_ts ON request_log(ts)",
        "CREATE INDEX IF NOT EXISTS idx_rl_short ON request_log(short_uuid)",
        "CREATE TABLE IF NOT EXISTS webhook_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ts TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            event TEXT NULL, short_uuid TEXT NULL, username TEXT NULL, status TEXT NULL,
            sig_ok INTEGER NOT NULL DEFAULT 0, action TEXT NULL
        )",
        "CREATE INDEX IF NOT EXISTS idx_wh_ts ON webhook_log(ts)",
        ddl_forward_log('sqlite'),
        "CREATE INDEX IF NOT EXISTS idx_fw_ts ON forward_log(ts)",
        "CREATE TABLE IF NOT EXISTS grace_users (
            short_uuid TEXT NOT NULL PRIMARY KEY, user_uuid TEXT NOT NULL, username TEXT NULL,
            orig_squads TEXT NULL, orig_traffic_bytes INTEGER NOT NULL DEFAULT 0,
            orig_traffic_strategy TEXT NOT NULL DEFAULT 'NO_RESET', orig_expire TEXT NULL,
            orig_hwid_limit INTEGER NULL, grace_until INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )",
        "CREATE TABLE IF NOT EXISTS chat_sessions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            token TEXT NOT NULL, name TEXT NULL, ip TEXT NULL, user_agent TEXT NULL,
            status TEXT NOT NULL DEFAULT 'open',
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_seen TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_msg_at TEXT NULL, unread_agent INTEGER NOT NULL DEFAULT 0,
            tg_msg_id INTEGER NULL, UNIQUE(token)
        )",
        "CREATE INDEX IF NOT EXISTS idx_chat_last ON chat_sessions(last_msg_at)",
        "CREATE TABLE IF NOT EXISTS chat_messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            session_id INTEGER NOT NULL,
            sender TEXT NOT NULL, source TEXT NOT NULL DEFAULT 'site',
            body TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (session_id) REFERENCES chat_sessions (id) ON DELETE CASCADE
        )",
        "CREATE INDEX IF NOT EXISTS idx_chat_msg_session ON chat_messages(session_id, id)",
        seed_sql('sqlite'),
    ];
}

function install_statements_mysql() {
    return [
        ddl_settings('mysql'),
        "CREATE TABLE IF NOT EXISTS overrides (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            match_type VARCHAR(16) NOT NULL, match_value VARCHAR(191) NOT NULL,
            reason VARCHAR(16) NOT NULL, source VARCHAR(16) NOT NULL DEFAULT 'manual',
            username VARCHAR(191) NULL, note VARCHAR(255) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id), UNIQUE KEY uniq_match (match_type, match_value), KEY idx_ov_value (match_value)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS request_log (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            ts TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            ip VARCHAR(45) NULL, short_uuid VARCHAR(191) NULL, path VARCHAR(255) NULL, user_agent VARCHAR(255) NULL,
            decision VARCHAR(16) NOT NULL DEFAULT 'normal', expire_ts INT NULL, hwid VARCHAR(191) NULL,
            is_app TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY (id), KEY idx_rl_ts (ts), KEY idx_rl_short (short_uuid)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS webhook_log (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            ts TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            event VARCHAR(64) NULL, short_uuid VARCHAR(191) NULL, username VARCHAR(191) NULL, status VARCHAR(32) NULL,
            sig_ok TINYINT(1) NOT NULL DEFAULT 0, action VARCHAR(64) NULL,
            PRIMARY KEY (id), KEY idx_wh_ts (ts)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ddl_forward_log('mysql'),
        "CREATE TABLE IF NOT EXISTS grace_users (
            short_uuid VARCHAR(191) NOT NULL, user_uuid VARCHAR(191) NOT NULL, username VARCHAR(191) NULL,
            orig_squads MEDIUMTEXT NULL, orig_traffic_bytes BIGINT NOT NULL DEFAULT 0,
            orig_traffic_strategy VARCHAR(32) NOT NULL DEFAULT 'NO_RESET', orig_expire VARCHAR(40) NULL,
            orig_hwid_limit INT NULL, grace_until INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (short_uuid)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS chat_sessions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            token CHAR(32) NOT NULL, name VARCHAR(120) NULL, ip VARCHAR(45) NULL, user_agent VARCHAR(255) NULL,
            status ENUM('open','closed') NOT NULL DEFAULT 'open',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_seen TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_msg_at TIMESTAMP NULL, unread_agent INT UNSIGNED NOT NULL DEFAULT 0,
            tg_msg_id BIGINT NULL,
            PRIMARY KEY (id), UNIQUE KEY uniq_token (token), KEY idx_chat_last (last_msg_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS chat_messages (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            session_id BIGINT UNSIGNED NOT NULL,
            sender ENUM('visitor','agent','system') NOT NULL,
            source ENUM('site','telegram','webhook','admin','system') NOT NULL DEFAULT 'site',
            body TEXT NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id), KEY idx_chat_msg_session (session_id, id),
            CONSTRAINT fk_chat_msg_session FOREIGN KEY (session_id) REFERENCES chat_sessions (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        seed_sql('mysql'),
    ];
}

function seed_sql($drv) {
    $rows = [];
    foreach (install_seed_values() as $k => $v) {
        $rows[] = "('" . $k . "', '" . str_replace("'", "''", $v) . "')";
    }
    $vals = implode(",\n            ", $rows);
    if ($drv === 'mysql') {
        return "INSERT INTO settings (k, v) VALUES\n            " . $vals . "\n         ON DUPLICATE KEY UPDATE k = k";
    }
    return "INSERT INTO settings (k, v) VALUES\n            " . $vals . "\n         ON CONFLICT(k) DO NOTHING";
}

function ensure_forward_log() {
    static $done = false;
    if ($done) return;
    $done = true;
    if (!($p = db())) return;
    try { $p->exec(ddl_forward_log(db_driver())); }
    catch (Throwable $e) { error_log('submw ensure_forward_log: ' . $e->getMessage()); }
}

function cfg() {
    static $c = null;
    if ($c === null) {
        $path = config_path();
        $c = is_file($path) ? (require $path) : ['installed' => false];
        if (!is_array($c)) $c = ['installed' => false];
    }
    return $c;
}

function is_installed() {
    $c = cfg();
    return !empty($c['installed']) && !empty($c['db']);
}

function pdo_connect(array $c, &$err = '') {
    $err = '';
    try {
        if (($c['driver'] ?? 'sqlite') === 'mysql') {
            $charset = $c['charset'] ?? 'utf8mb4';
            $port    = $c['port'] ?? 3306;
            $dsn = "mysql:host={$c['host']};port={$port};dbname={$c['name']};charset={$charset}";
            $pdo = new PDO($dsn, $c['user'], $c['pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            return $pdo;
        }
        $path = !empty($c['path']) ? $c['path'] : default_db_path();
        $dir = dirname($path);
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec('PRAGMA busy_timeout=5000');
        $pdo->exec('PRAGMA synchronous=NORMAL');
        return $pdo;
    } catch (Throwable $e) {
        $err = $e->getMessage();
        return null;
    }
}

function db() {
    static $pdo = null;
    static $tried = false;
    if ($pdo !== null || $tried) return $pdo;
    $tried = true;
    $c = db_conf();
    if (!$c) return null;
    $e = '';
    $pdo = pdo_connect($c, $e);
    if ($pdo === null) error_log('submw db connect failed: ' . $e);
    return $pdo;
}

function setting($key, $default = null) {
    if (!isset($GLOBALS['submw_settings_cache'])) {
        $GLOBALS['submw_settings_cache'] = [];
        if ($p = db()) {
            try {
                foreach ($p->query('SELECT k, v FROM settings') as $row) {
                    $GLOBALS['submw_settings_cache'][$row['k']] = $row['v'];
                }
            } catch (Throwable $e) { error_log('submw settings read: ' . $e->getMessage()); }
        }
    }
    return array_key_exists($key, $GLOBALS['submw_settings_cache']) ? $GLOBALS['submw_settings_cache'][$key] : $default;
}

function set_setting($key, $value) {
    if (!($p = db())) return false;
    if (db_driver() === 'mysql') {
        $stmt = $p->prepare('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)');
    } else {
        $stmt = $p->prepare('INSERT INTO settings (k, v) VALUES (?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v, updated_at = CURRENT_TIMESTAMP');
    }
    $ok = $stmt->execute([$key, (string) $value]);
    if ($ok && isset($GLOBALS['submw_settings_cache'])) $GLOBALS['submw_settings_cache'][$key] = (string) $value;
    return $ok;
}

function sql_epoch($col) {
    return db_driver() === 'mysql' ? "UNIX_TIMESTAMP($col)" : "CAST(strftime('%s', $col) AS INTEGER)";
}

function db_migrate(array $from, array $to, &$err = '') {
    $err = '';
    $e1 = ''; $e2 = '';
    $src = pdo_connect($from, $e1);
    if (!$src) { $err = 'источник недоступен: ' . $e1; return false; }
    $dst = pdo_connect($to, $e2);
    if (!$dst) { $err = 'приёмник недоступен: ' . $e2; return false; }
    try {
        foreach (install_statements($to['driver']) as $sql) $dst->exec($sql);
        $dst->exec(ddl_metrics_minute($to['driver']));
        $dst->exec(ddl_metrics_peak($to['driver']));
        $tables = ['settings', 'overrides', 'request_log', 'webhook_log', 'forward_log', 'grace_users', 'chat_sessions', 'chat_messages', 'metrics_minute', 'metrics_peak'];
        $verb = ($to['driver'] === 'mysql') ? 'REPLACE' : 'INSERT OR REPLACE';
        foreach ($tables as $t) {
            try { $rows = $src->query("SELECT * FROM $t")->fetchAll(PDO::FETCH_ASSOC); }
            catch (Throwable $e) { continue; }
            if (!$rows) continue;
            $cols = array_keys($rows[0]);
            $collist = implode(',', $cols);
            $ph = implode(',', array_fill(0, count($cols), '?'));
            $ins = $dst->prepare("$verb INTO $t ($collist) VALUES ($ph)");
            foreach ($rows as $row) $ins->execute(array_values($row));
        }
    } catch (Throwable $e) { $err = 'копирование: ' . $e->getMessage(); return false; }
    $conf = cfg();
    $conf['db'] = $to;
    $php = "<?php\nreturn " . var_export($conf, true) . ";\n";
    if (@file_put_contents(config_path(), $php) === false) { $err = 'не удалось записать config.php'; return false; }
    @chmod(config_path(), 0640);
    return true;
}
