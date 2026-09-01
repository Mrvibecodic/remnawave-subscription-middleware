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
    ('landing_fp',            ''),
    ('landing_fp_ack',        ''),
    ('chat_enabled',          '0'),
    ('chat_tg_api_base',      ''),
    ('nolog_shortuuids',      '[]'),
    ('addsub_enabled',        '0'),
    ('addsub_username_suffix','_addsub'),
    ('addsub_cache_ttl',      '600'),
    ('addsub_label',          ''),
    ('addsub_stub_on_traffic','1'),
    ('addsub_stub_label',     'Трафик доп-сервера истёк'),
    ('addsub_merge_xray',     '0'),
    ('squad_xray_json_inject','0'),
    ('chan_enabled',          '0'),
    ('chan_pad',              '1'),
    ('chan_hard_default',     '0'),
    ('chan_page_404',         '0'),
    ('chan_index_ttl',        '900'),
    ('chan_debug',            '0'),
    ('chan_debug_keep',       '50')
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
    is_app INTEGER NOT NULL DEFAULT 1,
    fmt TEXT NULL,
    ctype TEXT NULL,
    bytes INTEGER NULL,
    meta TEXT NULL
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
    action TEXT NULL,
    meta TEXT NULL
);
CREATE INDEX IF NOT EXISTS idx_wh_ts ON webhook_log(ts);
CREATE INDEX IF NOT EXISTS idx_wh_short ON webhook_log(short_uuid);

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

CREATE TABLE IF NOT EXISTS panel_write_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    ts TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    short_uuid TEXT NULL,
    ref_key TEXT NULL,
    ref_val TEXT NULL,
    op TEXT NULL,
    src TEXT NULL,
    fields TEXT NULL,
    body TEXT NULL,
    ok INTEGER NOT NULL DEFAULT 0,
    http_code INTEGER NULL,
    error TEXT NULL
);
CREATE INDEX IF NOT EXISTS idx_pw_ts ON panel_write_log(ts);
CREATE INDEX IF NOT EXISTS idx_pw_short ON panel_write_log(short_uuid);

CREATE TABLE IF NOT EXISTS grace_users (
    short_uuid TEXT NOT NULL PRIMARY KEY,
    user_uuid TEXT NOT NULL,
    username TEXT NULL,
    orig_squads TEXT NULL,
    orig_traffic_bytes INTEGER NOT NULL DEFAULT 0,
    orig_traffic_strategy TEXT NOT NULL DEFAULT 'NO_RESET',
    orig_expire TEXT NULL,
    orig_hwid_limit INTEGER NULL,
    orig_external_squad TEXT NULL,
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

CREATE TABLE IF NOT EXISTS addsub_map (
    main_short TEXT NOT NULL PRIMARY KEY,
    add_url TEXT NOT NULL,
    note TEXT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS addsub_cache (
    main_short TEXT NOT NULL PRIMARY KEY,
    add_url TEXT NULL,
    ts INTEGER NOT NULL DEFAULT 0
);

-- Защищённый канал c1 (клиент ↔ прослойка).
-- chan_kid — метки подписок на трое суток: вчера, сегодня, завтра.
CREATE TABLE IF NOT EXISTS chan_kid (
    kid TEXT NOT NULL PRIMARY KEY,
    short_uuid TEXT NOT NULL,
    epoch INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_chan_kid_epoch ON chan_kid(epoch);
-- Под точечное снятие меток, когда юзера удалили в панели.
CREATE INDEX IF NOT EXISTS idx_chan_kid_short ON chan_kid(short_uuid);

-- chan_nonce — защита от повтора перехваченного запроса, живёт 10 минут.
CREATE TABLE IF NOT EXISTS chan_nonce (
    n TEXT NOT NULL PRIMARY KEY,
    ts INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS idx_chan_nonce_ts ON chan_nonce(ts);

-- chan_key — ключи прослойки: текущий и предыдущий на время ротации.
CREATE TABLE IF NOT EXISTS chan_key (
    spid TEXT NOT NULL PRIMARY KEY,
    secret TEXT NOT NULL,
    created INTEGER NOT NULL DEFAULT 0,
    is_current INTEGER NOT NULL DEFAULT 0
);

-- chan_state — кто уже ходит защищённо: для вкладки и для жёсткого режима.
CREATE TABLE IF NOT EXISTS chan_state (
    short_uuid TEXT NOT NULL PRIMARY KEY,
    first_seen INTEGER NOT NULL DEFAULT 0,
    last_seen INTEGER NOT NULL DEFAULT 0,
    hits INTEGER NOT NULL DEFAULT 0,
    downgrades INTEGER NOT NULL DEFAULT 0,
    hard INTEGER NOT NULL DEFAULT 0,
    ua TEXT NULL
);

-- chan_debug — диагностический журнал канала. Пишется, только когда включён
-- руками в админке: в записи попадает расшифрованное тело подписки.
CREATE TABLE IF NOT EXISTS chan_debug (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    ts INTEGER NOT NULL DEFAULT 0,
    ok INTEGER NOT NULL DEFAULT 0,
    why TEXT NULL,
    short_uuid TEXT NULL,
    kid TEXT NULL,
    spid TEXT NULL,
    req_path TEXT NULL,
    req_head TEXT NULL,
    req_json TEXT NULL,
    req_fwd TEXT NULL,
    res_st INTEGER NULL,
    res_meta TEXT NULL,
    res_body TEXT NULL,
    res_wire TEXT NULL,
    res_outer TEXT NULL,
    body_bytes INTEGER NULL,
    wire_bytes INTEGER NULL
);
CREATE INDEX IF NOT EXISTS idx_chan_debug_ts ON chan_debug(ts);
