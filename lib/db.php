<?php

function config_path() {
    if (getenv('SUBMW_DOCKER') === '1') return dirname(__DIR__) . '/data/config.php';
    return dirname(__DIR__) . '/config.php';
}

function default_db_path() { return dirname(__DIR__) . '/data/submw.sqlite'; }

function submw_env_db() {
    $host = getenv('SUBMW_DB_HOST');
    if ($host === false || $host === '') return null;
    return [
        'driver' => 'mysql',
        'host'   => (string) $host,
        'port'   => (int) (getenv('SUBMW_DB_PORT') ?: 3306),
        'name'   => (string) getenv('SUBMW_DB_NAME'),
        'user'   => (string) getenv('SUBMW_DB_USER'),
        'pass'   => (string) getenv('SUBMW_DB_PASSWORD'),
    ];
}

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
        "CREATE INDEX IF NOT EXISTS idx_rl_hwid ON request_log(hwid)",
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
            PRIMARY KEY (id), KEY idx_rl_ts (ts), KEY idx_rl_short (short_uuid), KEY idx_rl_hwid (hwid)
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

function migrate_extra_ddl($drv) {
    if ($drv === 'mysql') {
        return [
            "CREATE TABLE IF NOT EXISTS squad_configs (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                squad_uuid VARCHAR(64) NOT NULL,
                type VARCHAR(32) NOT NULL DEFAULT 'amneziawg',
                name VARCHAR(191) NULL,
                enabled TINYINT(1) NOT NULL DEFAULT 1,
                raw MEDIUMTEXT NOT NULL,
                parsed MEDIUMTEXT NULL,
                squads MEDIUMTEXT NULL,
                grp VARCHAR(64) NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id), KEY idx_squad (squad_uuid)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS squad_cache (
                su VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                squads TEXT NULL, ts INT UNSIGNED NOT NULL DEFAULT 0, PRIMARY KEY (su)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS wg_lease (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                pool_id VARCHAR(96) NOT NULL, lease_key VARCHAR(191) NOT NULL,
                config_id INT UNSIGNED NOT NULL, short_uuid VARCHAR(64) NULL, hwid VARCHAR(191) NULL,
                manual TINYINT(1) NOT NULL DEFAULT 0, created_ts INT UNSIGNED NOT NULL DEFAULT 0,
                seen_ts INT UNSIGNED NOT NULL DEFAULT 0, ua VARCHAR(255) NULL,
                PRIMARY KEY (id), UNIQUE KEY uq_wgl_key (lease_key),
                UNIQUE KEY uq_wgl_slot (pool_id, config_id), UNIQUE KEY uq_wgl_cfg (config_id),
                KEY idx_wgl_pool (pool_id), KEY idx_wgl_short (short_uuid)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS hwid_devices (
                user_uuid VARCHAR(64) NOT NULL, hwid VARCHAR(191) NOT NULL, short_uuid VARCHAR(64) NULL,
                platform VARCHAR(64) NULL, seen_ts INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (user_uuid, hwid)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS wg_user_cache (
                short_uuid VARCHAR(64) NOT NULL, data TEXT NOT NULL, ts INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (short_uuid), KEY idx_wguc_ts (ts)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS addsub_map (
                main_short VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                add_url TEXT NOT NULL, note VARCHAR(191) NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (main_short)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS addsub_cache (
                main_short VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                add_url TEXT NULL, ts INT UNSIGNED NOT NULL DEFAULT 0, PRIMARY KEY (main_short)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS login_attempts (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, ip VARCHAR(45) NOT NULL,
                ts TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id), KEY idx_la_ip (ip), KEY idx_la_ts (ts)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS junk_hits (
                path VARCHAR(191) NOT NULL, hits BIGINT UNSIGNED NOT NULL DEFAULT 0,
                last_ts INT UNSIGNED NOT NULL DEFAULT 0, PRIMARY KEY (path)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "ALTER TABLE grace_users ADD COLUMN orig_external_squad VARCHAR(191) NULL",
        ];
    }
    return [
        "CREATE TABLE IF NOT EXISTS squad_configs (
            id INTEGER PRIMARY KEY AUTOINCREMENT, squad_uuid TEXT NOT NULL,
            type TEXT NOT NULL DEFAULT 'amneziawg', name TEXT NULL, enabled INTEGER NOT NULL DEFAULT 1,
            raw TEXT NOT NULL, parsed TEXT NULL, squads TEXT NULL, grp TEXT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )",
        "CREATE INDEX IF NOT EXISTS idx_squad_cfg ON squad_configs(squad_uuid)",
        "CREATE TABLE IF NOT EXISTS squad_cache (su TEXT NOT NULL PRIMARY KEY, squads TEXT NULL, ts INTEGER NOT NULL DEFAULT 0)",
        "CREATE TABLE IF NOT EXISTS wg_lease (
            id INTEGER PRIMARY KEY AUTOINCREMENT, pool_id TEXT NOT NULL, lease_key TEXT NOT NULL,
            config_id INTEGER NOT NULL, short_uuid TEXT NULL, hwid TEXT NULL, manual INTEGER NOT NULL DEFAULT 0,
            created_ts INTEGER NOT NULL DEFAULT 0, seen_ts INTEGER NOT NULL DEFAULT 0, ua TEXT NULL
        )",
        "CREATE UNIQUE INDEX IF NOT EXISTS uq_wgl_key ON wg_lease(lease_key)",
        "CREATE UNIQUE INDEX IF NOT EXISTS uq_wgl_slot ON wg_lease(pool_id, config_id)",
        "CREATE UNIQUE INDEX IF NOT EXISTS uq_wgl_cfg ON wg_lease(config_id)",
        "CREATE INDEX IF NOT EXISTS idx_wgl_pool ON wg_lease(pool_id)",
        "CREATE INDEX IF NOT EXISTS idx_wgl_short ON wg_lease(short_uuid)",
        "CREATE TABLE IF NOT EXISTS hwid_devices (
            user_uuid TEXT NOT NULL, hwid TEXT NOT NULL, short_uuid TEXT NULL, platform TEXT NULL,
            seen_ts INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (user_uuid, hwid)
        )",
        "CREATE TABLE IF NOT EXISTS wg_user_cache (short_uuid TEXT PRIMARY KEY, data TEXT NOT NULL, ts INTEGER NOT NULL DEFAULT 0)",
        "CREATE INDEX IF NOT EXISTS idx_wguc_ts ON wg_user_cache(ts)",
        "CREATE TABLE IF NOT EXISTS addsub_map (main_short TEXT NOT NULL PRIMARY KEY, add_url TEXT NOT NULL, note TEXT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)",
        "CREATE TABLE IF NOT EXISTS addsub_cache (main_short TEXT NOT NULL PRIMARY KEY, add_url TEXT NULL, ts INTEGER NOT NULL DEFAULT 0)",
        "CREATE TABLE IF NOT EXISTS login_attempts (id INTEGER PRIMARY KEY AUTOINCREMENT, ip TEXT NOT NULL, ts TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)",
        "CREATE INDEX IF NOT EXISTS idx_la_ip ON login_attempts(ip)",
        "CREATE INDEX IF NOT EXISTS idx_la_ts ON login_attempts(ts)",
        "CREATE TABLE IF NOT EXISTS junk_hits (path TEXT NOT NULL PRIMARY KEY, hits INTEGER NOT NULL DEFAULT 0, last_ts INTEGER NOT NULL DEFAULT 0)",
        "ALTER TABLE grace_users ADD COLUMN orig_external_squad TEXT NULL",
    ];
}

function db_list_tables($pdo, $drv) {
    $out = [];
    try {
        if ($drv === 'mysql') {
            foreach ($pdo->query('SHOW TABLES') as $row) { $out[] = array_values($row)[0]; }
        } else {
            foreach ($pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'") as $row) { $out[] = $row['name']; }
        }
    } catch (Throwable $e) { return []; }
    return $out;
}

function db_table_exists($pdo, $drv, $name) {
    try {
        if ($drv === 'mysql') {
            $st = $pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
        } else {
            $st = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name = ?");
        }
        $st->execute([$name]);
        return (bool) $st->fetchColumn();
    } catch (Throwable $e) { return false; }
}

function db_migrate(array $from, array $to, &$err = '') {
    $err = '';
    $e1 = ''; $e2 = '';
    $src = pdo_connect($from, $e1);
    if (!$src) { $err = 'источник недоступен: ' . $e1; return false; }
    $dst = pdo_connect($to, $e2);
    if (!$dst) { $err = 'приёмник недоступен: ' . $e2; return false; }
    $drv  = $to['driver'] ?? 'sqlite';
    $sdrv = $from['driver'] ?? 'sqlite';
    try {
        foreach (install_statements($drv) as $sql) $dst->exec($sql);
        $dst->exec(ddl_metrics_minute($drv));
        $dst->exec(ddl_metrics_peak($drv));
        foreach (migrate_extra_ddl($drv) as $sql) { try { $dst->exec($sql); } catch (Throwable $e) {} }
    } catch (Throwable $e) { $err = 'подготовка приёмника: ' . $e->getMessage(); return false; }

    $tables = db_list_tables($src, $sdrv);
    if (!$tables) {
        $tables = ['settings', 'overrides', 'request_log', 'webhook_log', 'forward_log', 'grace_users',
                   'chat_sessions', 'chat_messages', 'metrics_minute', 'metrics_peak', 'squad_configs',
                   'squad_cache', 'wg_lease', 'hwid_devices', 'wg_user_cache', 'addsub_map', 'addsub_cache', 'login_attempts', 'junk_hits'];
    }
    $verb = ($drv === 'mysql') ? 'REPLACE' : 'INSERT OR REPLACE';
    $tx = false;
    try {
        $dst->beginTransaction(); $tx = true;
        if ($drv === 'mysql') { try { $dst->exec('SET FOREIGN_KEY_CHECKS=0'); } catch (Throwable $e) {} }
        foreach ($tables as $t) {
            if (!db_table_exists($dst, $drv, $t)) continue;
            try { $rows = $src->query("SELECT * FROM $t")->fetchAll(PDO::FETCH_ASSOC); }
            catch (Throwable $e) { continue; }
            if (!$rows) continue;
            $cols = array_keys($rows[0]);
            $collist = implode(',', $cols);
            $ph = implode(',', array_fill(0, count($cols), '?'));
            $ins = $dst->prepare("$verb INTO $t ($collist) VALUES ($ph)");
            foreach ($rows as $row) $ins->execute(array_values($row));
        }
        if ($drv === 'mysql') { try { $dst->exec('SET FOREIGN_KEY_CHECKS=1'); } catch (Throwable $e) {} }
        $dst->commit(); $tx = false;
    } catch (Throwable $e) {
        if ($tx) { try { $dst->rollBack(); } catch (Throwable $e2) {} }
        $err = 'копирование: ' . $e->getMessage();
        return false;
    }
    $conf = cfg();
    $conf['db'] = $to;
    $php = "<?php\nreturn " . var_export($conf, true) . ";\n";
    if (@file_put_contents(config_path(), $php) === false) { $err = 'не удалось записать config.php'; return false; }
    @chmod(config_path(), 0640);
    return true;
}
