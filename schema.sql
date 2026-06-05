-- Remnawave Subscription Middleware — схема БД (SQLite).
-- Создаётся автоматически при установке; этот файл — справочный.
PRAGMA journal_mode=WAL;

CREATE TABLE IF NOT EXISTS settings (
    k TEXT NOT NULL PRIMARY KEY,
    v TEXT NOT NULL,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO settings (k, v) VALUES
    ('blocked_remarks',       '["🚫 Устройство заблокировано","Обратитесь в поддержку","@your_support"]'),
    ('trust_header_expire',   '1'),
    ('tls_verify',            '1'),
    ('proxy_timeout',         '30'),
    ('request_log_retention', '50000'),
    ('forward_enabled',       '0'),
    ('forward_targets',       '[]'),
    ('forward_timeout',       '8'),
    ('expired_grace_days',    '7'),
    ('app_headers',           '[]'),
    ('service_name',          ''),
    ('service_logo_url',      ''),
    ('brand_cache',           '{}'),
    ('landing_preset',        '1'),
    ('chat_enabled',          '0'),
    ('chat_tg_api_base',      '')
ON CONFLICT(k) DO NOTHING;

CREATE TABLE IF NOT EXISTS overrides (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    match_type TEXT NOT NULL,
    match_value TEXT NOT NULL,
    reason TEXT NOT NULL,
    source TEXT NOT NULL DEFAULT 'manual',
    username TEXT NULL,
    note TEXT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(match_type, match_value)
);
CREATE INDEX IF NOT EXISTS idx_ov_value ON overrides(match_value);

CREATE TABLE IF NOT EXISTS request_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    ts TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ip TEXT NULL,
    short_uuid TEXT NULL,
    path TEXT NULL,
    user_agent TEXT NULL,
    decision TEXT NOT NULL DEFAULT 'normal',
    expire_ts INTEGER NULL,
    hwid TEXT NULL,
    is_app INTEGER NOT NULL DEFAULT 1
);
CREATE INDEX IF NOT EXISTS idx_rl_ts ON request_log(ts);
CREATE INDEX IF NOT EXISTS idx_rl_short ON request_log(short_uuid);

CREATE TABLE IF NOT EXISTS webhook_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    ts TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    event TEXT NULL,
    short_uuid TEXT NULL,
    username TEXT NULL,
    status TEXT NULL,
    sig_ok INTEGER NOT NULL DEFAULT 0,
    action TEXT NULL
);
CREATE INDEX IF NOT EXISTS idx_wh_ts ON webhook_log(ts);

CREATE TABLE IF NOT EXISTS forward_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    ts TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    event TEXT NULL,
    target TEXT NULL,
    http_code INTEGER NULL,
    ok INTEGER NOT NULL DEFAULT 0,
    error TEXT NULL
);
CREATE INDEX IF NOT EXISTS idx_fw_ts ON forward_log(ts);

CREATE TABLE IF NOT EXISTS grace_users (
    short_uuid TEXT NOT NULL PRIMARY KEY,
    user_uuid TEXT NOT NULL,
    username TEXT NULL,
    orig_squads TEXT NULL,
    orig_traffic_bytes INTEGER NOT NULL DEFAULT 0,
    orig_traffic_strategy TEXT NOT NULL DEFAULT 'NO_RESET',
    orig_expire TEXT NULL,
    orig_hwid_limit INTEGER NULL,
    grace_until INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS chat_sessions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    token TEXT NOT NULL,
    name TEXT NULL,
    ip TEXT NULL,
    user_agent TEXT NULL,
    status TEXT NOT NULL DEFAULT 'open',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_msg_at TEXT NULL,
    unread_agent INTEGER NOT NULL DEFAULT 0,
    tg_msg_id INTEGER NULL,
    UNIQUE(token)
);
CREATE INDEX IF NOT EXISTS idx_chat_last ON chat_sessions(last_msg_at);

CREATE TABLE IF NOT EXISTS chat_messages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    session_id INTEGER NOT NULL,
    sender TEXT NOT NULL,
    source TEXT NOT NULL DEFAULT 'site',
    body TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (session_id) REFERENCES chat_sessions (id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_chat_msg_session ON chat_messages(session_id, id);
