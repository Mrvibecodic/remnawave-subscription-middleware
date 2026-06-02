<?php

function ensure_grace_table() {
    static $done = false;
    if ($done) return;
    $done = true;
    if (!($p = db())) return;
    try {
        if (db_driver() === 'mysql') {
            $p->exec("CREATE TABLE IF NOT EXISTS grace_users (
                short_uuid VARCHAR(191) NOT NULL, user_uuid VARCHAR(191) NOT NULL, username VARCHAR(191) NULL,
                orig_squads MEDIUMTEXT NULL, orig_traffic_bytes BIGINT NOT NULL DEFAULT 0,
                orig_traffic_strategy VARCHAR(32) NOT NULL DEFAULT 'NO_RESET', orig_expire VARCHAR(40) NULL,
                orig_hwid_limit INT NULL, grace_until INT NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (short_uuid)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } else {
            $p->exec("CREATE TABLE IF NOT EXISTS grace_users (
                short_uuid TEXT NOT NULL PRIMARY KEY, user_uuid TEXT NOT NULL, username TEXT NULL,
                orig_squads TEXT NULL, orig_traffic_bytes INTEGER NOT NULL DEFAULT 0,
                orig_traffic_strategy TEXT NOT NULL DEFAULT 'NO_RESET', orig_expire TEXT NULL,
                orig_hwid_limit INTEGER NULL, grace_until INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )");
        }
    } catch (Throwable $e) { error_log('submw grace table: ' . $e->getMessage()); }
}

function grace_iso($ts) { return gmdate('Y-m-d\TH:i:s.000\Z', (int) $ts); }

function grace_find($short) {
    ensure_grace_table();
    if (!($p = db()) || $short === '') return null;
    try {
        $st = $p->prepare("SELECT * FROM grace_users WHERE short_uuid = ?");
        $st->execute([$short]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    } catch (Throwable $e) { return null; }
}

function grace_delete($short) {
    if (!($p = db()) || $short === '') return;
    try { $p->prepare("DELETE FROM grace_users WHERE short_uuid = ?")->execute([$short]); }
    catch (Throwable $e) {}
}

function grace_save($short, $uuid, $username, array $squads, $bytes, $strategy, $orig_expire, $hwid_limit, $grace_until) {
    ensure_grace_table();
    if (!($p = db())) return;
    try {
        $cols = "INSERT INTO grace_users (short_uuid, user_uuid, username, orig_squads, orig_traffic_bytes, orig_traffic_strategy, orig_expire, orig_hwid_limit, grace_until) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) ";
        if (db_driver() === 'mysql') {
            $st = $p->prepare($cols . "ON DUPLICATE KEY UPDATE user_uuid=VALUES(user_uuid), username=VALUES(username), orig_squads=VALUES(orig_squads), orig_traffic_bytes=VALUES(orig_traffic_bytes), orig_traffic_strategy=VALUES(orig_traffic_strategy), orig_expire=VALUES(orig_expire), orig_hwid_limit=VALUES(orig_hwid_limit), grace_until=VALUES(grace_until)");
        } else {
            $st = $p->prepare($cols . "ON CONFLICT(short_uuid) DO UPDATE SET user_uuid=excluded.user_uuid, username=excluded.username, orig_squads=excluded.orig_squads, orig_traffic_bytes=excluded.orig_traffic_bytes, orig_traffic_strategy=excluded.orig_traffic_strategy, orig_expire=excluded.orig_expire, orig_hwid_limit=excluded.orig_hwid_limit, grace_until=excluded.grace_until");
        }
        $st->execute([$short, $uuid, $username, json_encode(array_values($squads)), (int) $bytes, (string) $strategy, (string) $orig_expire, ($hwid_limit === null ? null : (int) $hwid_limit), (int) $grace_until]);
    } catch (Throwable $e) { error_log('submw grace save: ' . $e->getMessage()); }
}

function grace_squads_from_user($u) {
    $out = [];
    foreach (($u['activeInternalSquads'] ?? []) as $s) {
        if (is_array($s) && !empty($s['uuid'])) $out[] = (string) $s['uuid'];
        elseif (is_string($s) && $s !== '')     $out[] = $s;
    }
    return $out;
}

function grace_on_expired($short, $username = null) {
    if (!grace_squad_active() || $short === '') return 'grace_off';
    $existing = grace_find($short);

    if ($existing) {
        if ((int) $existing['grace_until'] > time()) return 'grace_active';
        $squads = json_decode((string) $existing['orig_squads'], true);
        if (!is_array($squads)) $squads = [];
        $e = '';
        $restore = [
            'activeInternalSquads'  => $squads,
            'trafficLimitBytes'     => (int) $existing['orig_traffic_bytes'],
            'trafficLimitStrategy'  => (string) $existing['orig_traffic_strategy'],
            'hwidDeviceLimit'       => ($existing['orig_hwid_limit'] === null ? null : (int) $existing['orig_hwid_limit']),
        ];
        if (!empty($existing['orig_expire'])) $restore['expireAt'] = (string) $existing['orig_expire'];
        remnawave_update_user((string) $existing['user_uuid'], $restore, $e);
        grace_delete($short);
        if ($e !== '') error_log('submw grace end: ' . $e);
        return 'grace_ended';
    }

    $e = '';
    $u = remnawave_get_user_by_short($short, $e);
    if (!is_array($u) || empty($u['uuid'])) { error_log('submw grace start get: ' . $e); return 'grace_err'; }
    $uuid        = (string) $u['uuid'];
    $squads      = grace_squads_from_user($u);
    $bytes       = (int) ($u['trafficLimitBytes'] ?? 0);
    $strategy    = (string) ($u['trafficLimitStrategy'] ?? 'NO_RESET');
    $orig_expire = (string) ($u['expireAt'] ?? '');
    $hwid_orig   = array_key_exists('hwidDeviceLimit', $u) ? $u['hwidDeviceLimit'] : null;
    $grace_until = time() + grace_days() * 86400;

    grace_save($short, $uuid, $username, $squads, $bytes, $strategy, $orig_expire, $hwid_orig, $grace_until);

    $patch = [
        'status'                => 'ACTIVE',
        'activeInternalSquads'  => [grace_squad_uuid()],
        'trafficLimitBytes'     => grace_traffic_bytes(),
        'trafficLimitStrategy'  => grace_traffic_strategy(),
        'expireAt'              => grace_iso($grace_until),
    ];
    $gh = grace_hwid_limit_raw();
    if ($gh !== '') $patch['hwidDeviceLimit'] = (int) $gh;
    $e = '';
    $ok = remnawave_update_user($uuid, $patch, $e);
    if (!$ok) { grace_delete($short); error_log('submw grace start patch: ' . $e); return 'grace_err'; }
    return 'grace_started';
}

function grace_on_renew($short, $new_expire_str) {
    if ($short === '') return false;
    $existing = grace_find($short);
    if (!$existing) return false;
    $new_ts      = $new_expire_str ? strtotime((string) $new_expire_str) : false;
    $grace_until = (int) $existing['grace_until'];
    if ($new_ts === false || $new_ts <= $grace_until) return false;

    $squads = json_decode((string) $existing['orig_squads'], true);
    if (!is_array($squads)) $squads = [];
    $corrected = time() + ($new_ts - $grace_until);
    $e = '';
    remnawave_update_user((string) $existing['user_uuid'], [
        'status'                => 'ACTIVE',
        'activeInternalSquads'  => $squads,
        'trafficLimitBytes'     => (int) $existing['orig_traffic_bytes'],
        'trafficLimitStrategy'  => (string) $existing['orig_traffic_strategy'],
        'hwidDeviceLimit'       => ($existing['orig_hwid_limit'] === null ? null : (int) $existing['orig_hwid_limit']),
        'expireAt'              => grace_iso($corrected),
    ], $e);
    grace_delete($short);
    if ($e !== '') error_log('submw grace renew: ' . $e);
    return true;
}

function grace_cleanup($short) { grace_delete($short); }

function grace_is_active($short) {
    if ($short === '' || !grace_squad_active()) return false;
    $r = grace_find($short);
    return ($r && (int) $r['grace_until'] > time());
}
