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
                orig_hwid_limit INT NULL, orig_external_squad VARCHAR(191) NULL, grace_patch MEDIUMTEXT NULL, grace_until INT NOT NULL DEFAULT 0,
                ended_ts INT NOT NULL DEFAULT 0, retry_ts INT NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (short_uuid)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } else {
            $p->exec("CREATE TABLE IF NOT EXISTS grace_users (
                short_uuid TEXT NOT NULL PRIMARY KEY, user_uuid TEXT NOT NULL, username TEXT NULL,
                orig_squads TEXT NULL, orig_traffic_bytes INTEGER NOT NULL DEFAULT 0,
                orig_traffic_strategy TEXT NOT NULL DEFAULT 'NO_RESET', orig_expire TEXT NULL,
                orig_hwid_limit INTEGER NULL, orig_external_squad TEXT NULL, grace_patch TEXT NULL, grace_until INTEGER NOT NULL DEFAULT 0,
                ended_ts INTEGER NOT NULL DEFAULT 0, retry_ts INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )");
        }
    } catch (Throwable $e) { error_log('submw grace table: ' . $e->getMessage()); }
    if (setting('grace_cols', '') === '2') return;
    try { $p->exec('ALTER TABLE grace_users ADD COLUMN orig_external_squad ' . (db_driver() === 'mysql' ? 'VARCHAR(191)' : 'TEXT') . ' NULL'); } catch (Throwable $e) {}
    try { $p->exec('ALTER TABLE grace_users ADD COLUMN grace_patch ' . (db_driver() === 'mysql' ? 'MEDIUMTEXT' : 'TEXT') . ' NULL'); } catch (Throwable $e) {}
    try { $p->exec('ALTER TABLE grace_users ADD COLUMN ended_ts ' . (db_driver() === 'mysql' ? 'INT' : 'INTEGER') . ' NOT NULL DEFAULT 0'); } catch (Throwable $e) {}
    try { $p->exec('ALTER TABLE grace_users ADD COLUMN retry_ts ' . (db_driver() === 'mysql' ? 'INT' : 'INTEGER') . ' NOT NULL DEFAULT 0'); } catch (Throwable $e) {}
    if (db_has_cols($p, 'grace_users', ['orig_external_squad', 'grace_patch', 'ended_ts', 'retry_ts'])) set_setting('grace_cols', '2');
}

function grace_iso($ts) { return gmdate('Y-m-d\TH:i:s.000\Z', (int) $ts); }

function grace_announce_normalize($raw) {
    $raw   = str_replace(["\r\n", "\r"], "\n", (string) $raw);
    $lines = array_map(fn($l) => trim($l), explode("\n", $raw));
    while ($lines && $lines[0] === '') array_shift($lines);
    while ($lines && end($lines) === '') array_pop($lines);
    return mb_substr(implode("\n", $lines), 0, 200);
}

function grace_memo($short = null, $row = false) {
    static $m = [];
    if ($short === null) { $m = []; return null; }
    if ($row !== false) { $m[$short] = $row; return $row; }
    return array_key_exists($short, $m) ? $m[$short] : false;
}

function grace_find($short) {
    ensure_grace_table();
    $short = (string) $short;
    if (!($p = db()) || $short === '') return null;
    $c = grace_memo($short);
    if ($c !== false) return $c;
    try {
        $st = $p->prepare("SELECT * FROM grace_users WHERE short_uuid = ?");
        $st->execute([$short]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return grace_memo($short, $r ?: null);
    } catch (Throwable $e) { return null; }
}

function grace_delete($short) {
    grace_memo();
    if (!($p = db()) || $short === '') return;
    try { $p->prepare("DELETE FROM grace_users WHERE short_uuid = ?")->execute([$short]); }
    catch (Throwable $e) {}
}

function grace_ended($row) { return is_array($row) && (int) ($row['ended_ts'] ?? 0) > 0; }

function grace_claim_end($short) {
    grace_memo();
    if (!($p = db()) || $short === '') return false;
    try {
        $st = $p->prepare('UPDATE grace_users SET ended_ts = ? WHERE short_uuid = ? AND ended_ts = 0');
        $st->execute([time(), $short]);
        return $st->rowCount() === 1;
    } catch (Throwable $e) { return null; }
}

function grace_unclaim_end($short) {
    grace_memo();
    if (!($p = db()) || $short === '') return;
    try { $p->prepare('UPDATE grace_users SET ended_ts = 0, retry_ts = ? WHERE short_uuid = ?')->execute([time(), $short]); }
    catch (Throwable $e) {}
}

function grace_save($short, $uuid, $username, array $squads, $bytes, $strategy, $orig_expire, $hwid_limit, $orig_external_squad, $grace_until, $grace_patch = null) {
    ensure_grace_table();
    grace_memo();
    if (!($p = db())) return false;
    try {
        $cols = "INSERT INTO grace_users (short_uuid, user_uuid, username, orig_squads, orig_traffic_bytes, orig_traffic_strategy, orig_expire, orig_hwid_limit, orig_external_squad, grace_patch, grace_until, ended_ts, retry_ts) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0) ";
        if (db_driver() === 'mysql') {
            $st = $p->prepare($cols . "ON DUPLICATE KEY UPDATE user_uuid=VALUES(user_uuid), username=VALUES(username), orig_squads=VALUES(orig_squads), orig_traffic_bytes=VALUES(orig_traffic_bytes), orig_traffic_strategy=VALUES(orig_traffic_strategy), orig_expire=VALUES(orig_expire), orig_hwid_limit=VALUES(orig_hwid_limit), orig_external_squad=VALUES(orig_external_squad), grace_patch=VALUES(grace_patch), grace_until=VALUES(grace_until), ended_ts=0, retry_ts=0");
        } else {
            $st = $p->prepare($cols . "ON CONFLICT(short_uuid) DO UPDATE SET user_uuid=excluded.user_uuid, username=excluded.username, orig_squads=excluded.orig_squads, orig_traffic_bytes=excluded.orig_traffic_bytes, orig_traffic_strategy=excluded.orig_traffic_strategy, orig_expire=excluded.orig_expire, orig_hwid_limit=excluded.orig_hwid_limit, orig_external_squad=excluded.orig_external_squad, grace_patch=excluded.grace_patch, grace_until=excluded.grace_until, ended_ts=0, retry_ts=0");
        }
        $st->execute([$short, $uuid, $username, json_encode(array_values($squads)), (int) $bytes, (string) $strategy, (string) $orig_expire, ($hwid_limit === null ? null : (int) $hwid_limit), ($orig_external_squad === null ? null : (string) $orig_external_squad), ($grace_patch === null ? null : (string) $grace_patch), (int) $grace_until]);
        return true;
    } catch (Throwable $e) { error_log('submw grace save: ' . $e->getMessage()); return false; }
}

function grace_squads_from_user($u) {
    $out = [];
    foreach (($u['activeInternalSquads'] ?? []) as $s) {
        if (is_array($s) && !empty($s['uuid'])) $out[] = (string) $s['uuid'];
        elseif (is_string($s) && $s !== '')     $out[] = $s;
    }
    return $out;
}


function grace_squads_norm($squads) {
    $out = [];
    foreach ((array) $squads as $s) {
        $s = is_array($s) ? (string) ($s['uuid'] ?? '') : (string) $s;
        if ($s !== '') $out[$s] = true;
    }
    $out = array_keys($out);
    sort($out);
    return $out;
}

function grace_state_from_user($u) {
    if (!is_array($u)) return null;
    $st = [];
    if (is_array($u['activeInternalSquads'] ?? null)) $st['sq'] = grace_squads_norm($u['activeInternalSquads']);
    if (array_key_exists('trafficLimitBytes', $u))    $st['tl'] = (int) $u['trafficLimitBytes'];
    if (array_key_exists('trafficLimitStrategy', $u)) $st['ts'] = (string) $u['trafficLimitStrategy'];
    if (array_key_exists('hwidDeviceLimit', $u))      $st['hw'] = ($u['hwidDeviceLimit'] === null ? null : (int) $u['hwidDeviceLimit']);
    if (array_key_exists('externalSquadUuid', $u))    $st['ex'] = (string) ($u['externalSquadUuid'] ?? '');
    if (!empty($u['expireAt']) && ($t = strtotime((string) $u['expireAt'])) !== false) $st['exp'] = (int) $t;
    if (!empty($u['status'])) $st['st'] = strtoupper((string) $u['status']);
    return $st ?: null;
}

function grace_state_fetch($short, &$http_code = 0) {
    $http_code = 0;
    if ((string) $short === '' || remnawave_url() === '' || remnawave_token() === '') return null;
    $e = '';
    return grace_state_from_user(remnawave_get_user_by_short((string) $short, $e, $http_code));
}

function grace_state_applied($existing) {
    $j = json_decode((string) ($existing['grace_patch'] ?? ''), true);
    if (!is_array($j)) $j = [];
    return [
        'sq' => grace_squads_norm(array_key_exists('sq', $j) ? $j['sq'] : [grace_squad_uuid()]),
        'tl' => array_key_exists('tl', $j) ? (int) $j['tl'] : grace_traffic_bytes(),
        'ts' => array_key_exists('ts', $j) ? (string) $j['ts'] : grace_traffic_strategy(),
        'hw' => array_key_exists('hw', $j) ? (int) $j['hw'] : false,
        'ex' => array_key_exists('ex', $j) ? (string) $j['ex'] : false,
    ];
}

function grace_restore_patch($existing, $cur, &$kept = []) {
    $kept = [];
    $exp  = grace_state_applied($existing);
    $has  = function ($k) use ($cur) { return is_array($cur) && array_key_exists($k, $cur); };

    $squads = json_decode((string) ($existing['orig_squads'] ?? ''), true);
    if (!is_array($squads)) $squads = [];
    $squads = array_values(array_filter($squads, function ($s) { return is_string($s) && $s !== ''; }));

    if ($has('sq') && $cur['sq'] !== $exp['sq']) {
        $live = array_values(array_diff($cur['sq'], [grace_squad_uuid()]));
        if ($live) { $squads = $live; $kept[] = 'sq'; }
    }

    $patch = [];
    if ($squads) $patch['activeInternalSquads'] = array_values($squads);

    if ($has('tl') && (int) $cur['tl'] !== (int) $exp['tl']) $kept[] = 'tl';
    else $patch['trafficLimitBytes'] = (int) $existing['orig_traffic_bytes'];

    if ($has('ts') && (string) $cur['ts'] !== (string) $exp['ts']) $kept[] = 'ts';
    else $patch['trafficLimitStrategy'] = (string) $existing['orig_traffic_strategy'];

    if ($exp['hw'] !== false) {
        if ($has('hw') && (int) $cur['hw'] !== (int) $exp['hw']) $kept[] = 'hw';
        else $patch['hwidDeviceLimit'] = (($existing['orig_hwid_limit'] ?? null) === null ? null : (int) $existing['orig_hwid_limit']);
    }

    if ($exp['ex'] !== false && array_key_exists('orig_external_squad', $existing) && $existing['orig_external_squad'] !== null) {
        if ($has('ex') && (string) $cur['ex'] !== (string) $exp['ex']) $kept[] = 'ex';
        else $patch['externalSquadUuid'] = ($existing['orig_external_squad'] === '' ? null : (string) $existing['orig_external_squad']);
    }
    return $patch;
}

function grace_log_kept($where, $short, array $kept) {
    if (!$kept) return;
    error_log('submw grace ' . $where . ': за время грейса изменены поля (' . implode(', ', $kept) . '), откат по ним пропущен (short=' . $short . ')');
}


function grace_set_ref($short, $ref) {
    if (!($p = db()) || (string) $short === '' || !rw_ref_ok($ref)) return;
    try { $p->prepare('UPDATE grace_users SET user_uuid = ? WHERE short_uuid = ?')->execute([(string) $ref['val'], (string) $short]); }
    catch (Throwable $e) { error_log('submw grace set ref: ' . $e->getMessage()); }
}

function grace_ref_resolve($short, &$err = '', &$http_code = 0) {
    $err = ''; $http_code = 0;
    $short = (string) $short;
    if ($short === '') { $err = 'Пустой shortUuid'; return null; }
    $u = remnawave_get_user_by_short($short, $err, $http_code);
    if (!is_array($u)) return null;
    $ref = rw_user_ref($u);
    if (!rw_ref_ok($ref)) { $err = 'В ответе панели нет идентификатора пользователя'; return null; }
    grace_set_ref($short, $ref);
    return $ref;
}

function grace_ref(&$existing, &$err = '') {
    $err = '';
    $short = (string) ($existing['short_uuid'] ?? '');
    $ref   = rw_ref_coerce((string) ($existing['user_uuid'] ?? ''));
    if (rw_ref_ok($ref) && !($ref['key'] === 'uuid' && panel_api_v3())) return $ref;
    $fresh = grace_ref_resolve($short, $err);
    if ($fresh) $existing['user_uuid'] = (string) $fresh['val'];
    return $fresh ?: $ref;
}

function grace_patch(&$existing, array $patch, &$err = '', &$code = 0) {
    $err = ''; $code = 0;
    $short = (string) ($existing['short_uuid'] ?? '');
    $ref = grace_ref($existing, $err);
    if (!rw_ref_ok($ref)) { $err = $err ?: 'Нет идентификатора пользователя'; return false; }

    $code = 0; $err = '';
    if (remnawave_update_user($ref, $patch, $err, $code)) return true;
    if (!in_array((int) $code, [400, 404], true)) return false;

    $re = '';
    $ref2 = grace_ref_resolve($short, $re);
    if (!$ref2 || $ref2['val'] === $ref['val']) return false;
    $existing['user_uuid'] = (string) $ref2['val'];
    error_log('submw grace: идентификатор ' . $short . ' перерезолвлен (' . $ref['key'] . ' -> ' . $ref2['key'] . '), повтор PATCH');
    $err = '';
    return remnawave_update_user($ref2, $patch, $err, $code);
}

function grace_exit_reset_traffic(&$existing) {
    if (!grace_reset_traffic_on_exit()) return;
    $short = (string) ($existing['short_uuid'] ?? '');
    api_ctx('grace_exit_reset', $short);
    $e   = '';
    $ref = grace_ref($existing, $e);
    if (!rw_ref_ok($ref)) { error_log('submw grace exit reset-traffic: ' . ($e ?: 'нет идентификатора пользователя') . ' (short=' . $short . ')'); return; }
    $re = '';
    if (!remnawave_reset_traffic($ref, $re)) error_log('submw grace exit reset-traffic: ' . $re . ' (short=' . $short . ')');
}

function grace_changed($existing, $cur) {
    if (!is_array($cur)) return [];
    $exp = grace_state_applied($existing);
    $chg = [];
    foreach (['sq', 'tl', 'ts', 'hw', 'ex'] as $k) {
        if (!array_key_exists($k, $cur) || $exp[$k] === false) continue;
        if ($k === 'sq') { if ($cur['sq'] !== $exp['sq']) $chg[] = 'sq'; }
        elseif ($k === 'tl' || $k === 'hw') { if ((int) $cur[$k] !== (int) $exp[$k]) $chg[] = $k; }
        elseif ((string) $cur[$k] !== (string) $exp[$k]) $chg[] = $k;
    }
    if (isset($cur['exp']) && abs((int) $cur['exp'] - (int) $existing['grace_until']) > 5) $chg[] = 'exp';
    return $chg;
}

function grace_stale($existing, $cur) {
    if (!is_array($cur) || !isset($cur['exp']) || empty($existing['orig_expire'])) return false;
    $oe = strtotime((string) $existing['orig_expire']);
    return $oe !== false && abs((int) $cur['exp'] - $oe) <= 5;
}

function grace_end(&$existing, $cur, $reason) {
    $short = (string) ($existing['short_uuid'] ?? '');
    $alive = $reason === 'ext' && isset($cur['exp']) && (int) $cur['exp'] > time() && (($cur['st'] ?? '') !== 'DISABLED');
    $claim = grace_claim_end($short);
    if ($claim === null) return 'grace_err';
    if (!$claim) return $alive ? 'grace_renewed' : 'grace_ended';
    api_ctx($reason === 'ext' ? 'grace_renew' : 'grace_end', $short);
    $kept  = [];
    $patch = grace_restore_patch($existing, $cur, $kept);
    grace_log_kept($reason === 'ext' ? 'renew' : 'end', $short, $kept);
    if ($alive) {
        $patch['status'] = 'ACTIVE';
    } elseif ($reason === 'due' && !empty($existing['orig_expire'])) {
        $oe = strtotime((string) $existing['orig_expire']);
        if ($oe !== false && $oe > time() + 60) $patch['expireAt'] = (string) $existing['orig_expire'];
    }
    $ok = true; $e = ''; $code = 0;
    if ($patch && !grace_patch($existing, $patch, $e, $code)) {
        error_log('submw grace ' . $reason . ': ' . $e . ' (short=' . $short . ')');
        $ok = false;
        if (!empty($patch['activeInternalSquads'])) {
            $e2 = '';
            $ok = grace_patch($existing, ['activeInternalSquads' => $patch['activeInternalSquads']], $e2, $code);
            if ($ok) error_log('submw grace ' . $reason . ': squads-only ok (short=' . $short . ')');
        }
        if (!$ok && in_array((int) $code, [400, 404], true)) {
            error_log('submw grace ' . $reason . ': панель отвергла восстановление (' . $code . '), грейс закрыт без отката (short=' . $short . ')');
            $ok = true;
        }
    }
    if (!$ok) { grace_unclaim_end($short); return 'grace_err'; }
    grace_exit_reset_traffic($existing);
    if ($alive) delete_override('shortuuid', $short, 'webhook');
    else upsert_override('shortuuid', $short, 'expired', 'webhook', $existing['username'] ?? null, 'auto: grace end');
    return $alive ? 'grace_renewed' : 'grace_ended';
}

function grace_check($short, $data = null) {
    $short = (string) $short;
    if ($short === '') return 'grace_off';
    $existing = grace_find($short);
    if (!$existing) return 'grace_off';
    $cur = grace_state_from_user($data);
    if (grace_ended($existing)) {
        if (grace_stale($existing, $cur)) return 'grace_stale';
        if (isset($cur['exp']) && abs((int) $cur['exp'] - (int) $existing['grace_until']) > 5) { grace_delete($short); return 'grace_off'; }
        return 'grace_done';
    }
    if (remnawave_url() === '' || remnawave_token() === '') return 'grace_active';
    $due = (int) $existing['grace_until'] <= time();
    if (is_array($cur)) {
        if (grace_stale($existing, $cur)) return 'grace_stale';
    } else {
        $hc = 0;
        $cur = grace_state_fetch($short, $hc);
        if (!is_array($cur) && $hc === 404) { grace_delete($short); return 'grace_off'; }
        if (!is_array($cur)) return $due ? 'grace_err' : 'grace_active';
    }
    $chg = grace_changed($existing, $cur);
    if ($chg) return grace_end($existing, $cur, 'ext');
    if (($cur['st'] ?? '') === 'EXPIRED') $due = true;
    if ($due) return grace_end($existing, $cur, 'due');
    return 'grace_active';
}

function grace_touch($short, $expire_ts = null) {
    $short = (string) $short;
    if ($short === '' || !grace_squad_active()) return;
    $existing = grace_find($short);
    if (!$existing) return;
    $gu = (int) $existing['grace_until'];
    if (grace_ended($existing)) {
        if ($expire_ts !== null && abs((int) $expire_ts - $gu) > 5) grace_delete($short);
        return;
    }
    if ($gu > time() && ($expire_ts === null || abs((int) $expire_ts - $gu) <= 5)) return;
    if ((int) ($existing['retry_ts'] ?? 0) > time() - 60 || !($p = db())) return;
    try { $p->prepare('UPDATE grace_users SET retry_ts = ? WHERE short_uuid = ?')->execute([time(), $short]); } catch (Throwable $e) { return; }
    grace_memo();
    grace_check($short);
}

function grace_on_expired($short, $username = null, $allow_start = true, $data = null) {
    if ($short === '') return 'grace_off';
    $existing = grace_find($short);
    if ($existing) {
        $g = grace_check($short, $data);
        if ($g !== 'grace_off') return $g;
    }

    if (!$allow_start) return 'grace_off';
    if (!grace_squad_active()) return 'grace_off';

    $e = '';
    $u = remnawave_get_user_by_short($short, $e);
    $ref = rw_user_ref($u);
    if (!is_array($u) || !rw_ref_ok($ref)) { error_log('submw grace start get: ' . $e); return 'grace_err'; }
    $u_exp = !empty($u['expireAt']) ? strtotime((string) $u['expireAt']) : false;
    if (strtoupper((string) ($u['status'] ?? '')) !== 'EXPIRED' && $u_exp !== false && $u_exp > time() + 5) return 'grace_stale';
    $squads      = array_values(array_diff(grace_squads_from_user($u), [grace_squad_uuid()]));
    if (!$squads) { error_log('submw grace start: empty or grace-only squads for ' . $short . ', skipping grace'); return 'grace_off'; }
    $bytes       = (int) ($u['trafficLimitBytes'] ?? 0);
    $strategy    = (string) ($u['trafficLimitStrategy'] ?? 'NO_RESET');
    $orig_expire = (string) ($u['expireAt'] ?? '');
    $hwid_orig   = array_key_exists('hwidDeviceLimit', $u) ? $u['hwidDeviceLimit'] : null;
    $ext_orig    = grace_external_active() ? (string) ($u['externalSquadUuid'] ?? '') : null;
    $grace_until = time() + grace_days() * 86400;
    api_ctx('grace_start', $short);

    $gh      = grace_hwid_limit_raw();
    $applied = ['sq' => [grace_squad_uuid()], 'tl' => grace_traffic_bytes(), 'ts' => grace_traffic_strategy()];
    if ($gh !== '') $applied['hw'] = (int) $gh;
    if (grace_external_active()) $applied['ex'] = grace_external_squad_uuid();

    if (!grace_save($short, $ref['val'], $username, $squads, $bytes, $strategy, $orig_expire, $hwid_orig, $ext_orig, $grace_until, json_encode($applied))) return 'grace_err';

    $patch = [
        'status'                => 'ACTIVE',
        'activeInternalSquads'  => [grace_squad_uuid()],
        'trafficLimitBytes'     => grace_traffic_bytes(),
        'trafficLimitStrategy'  => grace_traffic_strategy(),
        'expireAt'              => grace_iso($grace_until),
    ];
    if ($gh !== '') $patch['hwidDeviceLimit'] = (int) $gh;
    if (grace_external_active()) $patch['externalSquadUuid'] = grace_external_squad_uuid();
    $e = '';
    $ok = remnawave_update_user($ref, $patch, $e);
    if (!$ok) {
        $chk = grace_state_fetch($short);
        if (is_array($chk) && isset($chk['exp']) && abs((int) $chk['exp'] - $grace_until) <= 5) {
            error_log('submw grace start patch: ' . $e . ' — но панель уже применила, грейс сохранён');
        } else {
            grace_delete($short);
            error_log('submw grace start patch: ' . $e);
            return 'grace_err';
        }
    }
    if (grace_traffic_bytes() > 0) {
        $re = '';
        remnawave_reset_traffic($ref, $re);
        if ($re !== '') error_log('submw grace reset-traffic: ' . $re);
    }
    return 'grace_started';
}

function grace_cleanup($short) { grace_delete($short); }

function grace_refresh_refs($limit = 200) {
    ensure_grace_table();
    $out = ['total' => 0, 'updated' => 0, 'same' => 0, 'missing' => 0, 'errors' => 0, 'left' => 0, 'error' => '', 'error_net' => ''];
    if (remnawave_url() === '' || remnawave_token() === '') { $out['error'] = 'Не заданы URL панели или API-токен'; return $out; }
    if (!($p = db())) { $out['error'] = 'Нет связи с БД'; return $out; }
    try {
        $st = $p->query('SELECT short_uuid, user_uuid FROM grace_users WHERE ended_ts = 0 ORDER BY grace_until ASC');
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { $out['error'] = 'Ошибка чтения таблицы грейса'; return $out; }

    $major = panel_major();
    $want  = $major >= 3 ? 'id' : ($major > 0 ? 'uuid' : '');
    $calls = 0; $err_streak = 0;
    foreach ($rows as $r) {
        $out['total']++;
        $short = (string) ($r['short_uuid'] ?? '');
        $old   = (string) ($r['user_uuid'] ?? '');
        $ref   = rw_ref_coerce($old);
        if ($want !== '' && rw_ref_ok($ref) && $ref['key'] === $want) { $out['same']++; continue; }
        if ($calls >= $limit || $err_streak >= 3) { $out['left']++; continue; }
        $calls++;
        $err = ''; $code = 0;
        $fresh = grace_ref_resolve($short, $err, $code);
        if ($fresh) {
            $err_streak = 0;
            if ((string) $fresh['val'] !== $old) $out['updated']++;
            else $out['same']++;
            continue;
        }
        if ($code === 404) {
            $out['missing']++;
            $err_streak = 0;
        } else {
            $out['errors']++;
            $err_streak++;
            if ($out['error_net'] === '') $out['error_net'] = $err;
        }
    }
    return $out;
}

function grace_retry_pending($limit = 2) {
    if (remnawave_url() === '' || remnawave_token() === '') return;
    ensure_grace_table();
    if (!($p = db())) return;
    $now = time();
    try {
        if (random_int(1, 200) === 1) $p->prepare('DELETE FROM grace_users WHERE ended_ts > 0 AND ended_ts < ?')->execute([$now - 30 * 86400]);
        $st = $p->prepare('SELECT short_uuid FROM grace_users WHERE ended_ts = 0 AND grace_until < ? AND retry_ts < ? ORDER BY retry_ts ASC, grace_until ASC LIMIT ' . (int) $limit);
        $st->execute([$now - 120, $now - 60]);
        $rows = $st->fetchAll(PDO::FETCH_COLUMN);
        if ($rows) {
            $in = implode(',', array_fill(0, count($rows), '?'));
            $p->prepare("UPDATE grace_users SET retry_ts = ? WHERE short_uuid IN ($in)")->execute(array_merge([$now], $rows));
        }
    } catch (Throwable $e) { return; }
    grace_memo();
    foreach ($rows as $short) grace_check((string) $short);
}

function grace_is_active($short) {
    static $memo = [];
    if ($short === '' || !grace_squad_active()) return false;
    if (array_key_exists($short, $memo)) return $memo[$short];
    $r = grace_find($short);
    return $memo[$short] = ($r && !grace_ended($r) && (int) $r['grace_until'] > time());
}
