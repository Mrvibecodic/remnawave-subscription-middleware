<?php

function wglease_ensure() {
    static $done = false;
    if ($done) return;
    $done = true;
    if (!($p = db())) return;
    try {
        if (db_driver() === 'mysql') {
            $p->exec("CREATE TABLE IF NOT EXISTS wg_lease (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                pool_id VARCHAR(96) NOT NULL,
                lease_key VARCHAR(191) NOT NULL,
                config_id INT UNSIGNED NOT NULL,
                short_uuid VARCHAR(64) NULL,
                hwid VARCHAR(191) NULL,
                manual TINYINT(1) NOT NULL DEFAULT 0,
                created_ts INT UNSIGNED NOT NULL DEFAULT 0,
                seen_ts INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (id),
                UNIQUE KEY uq_wgl_key (lease_key),
                UNIQUE KEY uq_wgl_slot (pool_id, config_id),
                KEY idx_wgl_pool (pool_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $p->exec("CREATE TABLE IF NOT EXISTS hwid_devices (
                user_uuid VARCHAR(64) NOT NULL,
                hwid VARCHAR(191) NOT NULL,
                short_uuid VARCHAR(64) NULL,
                platform VARCHAR(64) NULL,
                seen_ts INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (user_uuid, hwid)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } else {
            $p->exec("CREATE TABLE IF NOT EXISTS wg_lease (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                pool_id TEXT NOT NULL,
                lease_key TEXT NOT NULL,
                config_id INTEGER NOT NULL,
                short_uuid TEXT NULL,
                hwid TEXT NULL,
                manual INTEGER NOT NULL DEFAULT 0,
                created_ts INTEGER NOT NULL DEFAULT 0,
                seen_ts INTEGER NOT NULL DEFAULT 0
            )");
            $p->exec("CREATE UNIQUE INDEX IF NOT EXISTS uq_wgl_key ON wg_lease(lease_key)");
            $p->exec("CREATE UNIQUE INDEX IF NOT EXISTS uq_wgl_slot ON wg_lease(pool_id, config_id)");
            $p->exec("CREATE INDEX IF NOT EXISTS idx_wgl_pool ON wg_lease(pool_id)");
            $p->exec("CREATE INDEX IF NOT EXISTS idx_wgl_short ON wg_lease(short_uuid)");
            $p->exec("CREATE TABLE IF NOT EXISTS hwid_devices (
                user_uuid TEXT NOT NULL,
                hwid TEXT NOT NULL,
                short_uuid TEXT NULL,
                platform TEXT NULL,
                seen_ts INTEGER NOT NULL DEFAULT 0,
                PRIMARY KEY (user_uuid, hwid)
            )");
        }
    } catch (Throwable $e) { error_log('submw wglease ensure: ' . $e->getMessage()); }
    wglease_ensure_cfg_uidx($p);
    if (setting('wgl_ua_col', '') !== '1') {
        try { $p->exec('ALTER TABLE wg_lease ADD COLUMN ua ' . (db_driver() === 'mysql' ? 'VARCHAR(255)' : 'TEXT') . ' NULL'); } catch (Throwable $e) {}
        set_setting('wgl_ua_col', '1');
    }
}

function wglease_cfg_uidx_exists($p) {
    try {
        if (db_driver() === 'mysql') {
            $st = $p->prepare("SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'wg_lease' AND index_name = 'uq_wgl_cfg' LIMIT 1");
            $st->execute();
            return (bool) $st->fetchColumn();
        }
        $st = $p->query("SELECT 1 FROM sqlite_master WHERE type='index' AND name='uq_wgl_cfg' LIMIT 1");
        return (bool) $st->fetchColumn();
    } catch (Throwable $e) { return false; }
}

function wglease_try_cfg_uidx($p) {
    try {
        if (db_driver() === 'mysql') $p->exec('ALTER TABLE wg_lease ADD UNIQUE KEY uq_wgl_cfg (config_id)');
        else $p->exec('CREATE UNIQUE INDEX IF NOT EXISTS uq_wgl_cfg ON wg_lease (config_id)');
        return true;
    } catch (Throwable $e) { return false; }
}

function wglease_ensure_cfg_uidx($p) {
    if (setting('wgl_cfg_uidx', '') === '1') return;
    if (wglease_cfg_uidx_exists($p)) { set_setting('wgl_cfg_uidx', '1'); return; }
    if (wglease_try_cfg_uidx($p)) { set_setting('wgl_cfg_uidx', '1'); return; }
    // индекс не создался — в таблице есть повторяющиеся config_id; оставляем по одной аренде на config_id
    try {
        $dups = $p->query('SELECT config_id FROM wg_lease GROUP BY config_id HAVING COUNT(*) > 1')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($dups as $cid) {
            $keep = $p->prepare('SELECT id FROM wg_lease WHERE config_id = ? ORDER BY manual DESC, id ASC LIMIT 1');
            $keep->execute([(int) $cid]);
            $keepId = (int) $keep->fetchColumn();
            if ($keepId > 0) $p->prepare('DELETE FROM wg_lease WHERE config_id = ? AND id <> ?')->execute([(int) $cid, $keepId]);
        }
    } catch (Throwable $e) { error_log('submw wglease dedupe: ' . $e->getMessage()); return; }
    if (wglease_try_cfg_uidx($p)) set_setting('wgl_cfg_uidx', '1');
}

function wglease_mode($squad_uuid) {
    $squad_uuid = (string) $squad_uuid;
    if ($squad_uuid === '') return 'shared';
    $m = setting('wgpool_mode_' . $squad_uuid, 'shared');
    return in_array($m, ['shared', 'users', 'devices'], true) ? $m : 'shared';
}

function wglease_set_mode($squad_uuid, $mode) {
    $squad_uuid = (string) $squad_uuid;
    if ($squad_uuid === '') return;
    $mode = in_array($mode, ['shared', 'users', 'devices'], true) ? $mode : 'shared';
    set_setting('wgpool_mode_' . $squad_uuid, $mode);
}

function wglease_reclaim_days() { return max(1, (int) (setting('wgpool_reclaim_days', '14') ?: 14)); }

function wglease_select($short_uuid, $hwid, array $u_squads, $types = null) {
    $short_uuid = (string) $short_uuid;
    $hwid = (string) $hwid;
    $ua = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    if (!$u_squads) return [];
    $cfgs = squadconf_for_squads($u_squads);
    if (!$cfgs) return [];
    $by_squad = [];
    foreach ($cfgs as $c) {
        foreach (squadconf_squads_of($c) as $sq) {
            if (in_array($sq, $u_squads, true)) $by_squad[$sq][] = $c;
        }
    }
    $out = []; $added = [];
    foreach ($u_squads as $sq) {
        if (wglease_mode($sq) !== 'shared') continue;
        foreach ($by_squad[$sq] ?? [] as $c) {
            $id = (int) $c['id'];
            if (!isset($added[$id])) { $added[$id] = true; $out[] = $c; }
        }
    }
    foreach ($u_squads as $sq) {
        $mode = wglease_mode($sq);
        if ($mode === 'shared') continue;
        $by_group = [];
        foreach ($by_squad[$sq] ?? [] as $c) {
            $id = (int) $c['id'];
            if (isset($added[$id])) continue;
            $t = (string) ($c['type'] ?? '');
            if ($t === 'vless') { $added[$id] = true; $out[] = $c; continue; }
            if ($types !== null && !in_array($t, $types, true)) continue;
            $g = trim((string) ($c['grp'] ?? ''));
            // пустая группа → старый формат ключа (t:тип), чтобы уже выданные аренды не перевыдавались
            $sub = ($g === '') ? ('t:' . $t) : ('t:' . $t . '|g:' . $g);
            $by_group[$sub][] = $c;
        }
        if (!$by_group) continue;
        if ($mode === 'devices') {
            if ($hwid === '') continue;
            $base = 'd:' . $short_uuid . '|' . $hwid;
        } else {
            $base = 's:' . $short_uuid;
        }
        foreach ($by_group as $sub => $cands) {
            $pick = wglease_pick($sq, $base . '|' . $sub, $short_uuid, ($mode === 'devices' ? $hwid : ''), $cands, $ua);
            if ($pick) { $added[(int) $pick['id']] = true; $out[] = $pick; }
        }
    }
    foreach (wglease_manual_for_user($short_uuid, $hwid) as $c) {
        $id = (int) $c['id'];
        if (!isset($added[$id])) { $added[$id] = true; $out[] = $c; }
    }
    return $out;
}

function wglease_key($pool_id, $subkey) { return sha1((string) $pool_id . '|' . (string) $subkey); }

function wglease_pick($pool_id, $subkey, $short_uuid, $hwid, array $cands, $ua = '') {
    wglease_ensure();
    if (!($p = db()) || !$cands) return null;
    $ua = (string) $ua;
    $lease_key = wglease_key($pool_id, $subkey);
    $by_id = [];
    foreach ($cands as $c) $by_id[(int) $c['id']] = $c;
    $cand_ids = array_keys($by_id);
    $now = time();
    try {
        $st = $p->prepare('SELECT id, config_id, manual FROM wg_lease WHERE lease_key = ? LIMIT 1');
        $st->execute([$lease_key]);
        $row = $st->fetch();
    } catch (Throwable $e) { $row = null; }
    if ($row && isset($by_id[(int) $row['config_id']])) {
        if ($ua !== '') { try { $p->prepare('UPDATE wg_lease SET seen_ts = ?, ua = ? WHERE id = ?')->execute([$now, $ua, (int) $row['id']]); } catch (Throwable $e) {} }
        else { try { $p->prepare('UPDATE wg_lease SET seen_ts = ? WHERE id = ?')->execute([$now, (int) $row['id']]); } catch (Throwable $e) {} }
        return $by_id[(int) $row['config_id']];
    }
    if ($row && !isset($by_id[(int) $row['config_id']])) {
        if ((int) $row['manual'] === 0) {
            try { $p->prepare('DELETE FROM wg_lease WHERE id = ?')->execute([(int) $row['id']]); } catch (Throwable $e) {}
        } else {
            return null;
        }
    }
    if ($hwid !== '') {
        try {
            $st = $p->prepare('SELECT config_id FROM wg_lease WHERE lease_key = ? AND manual = 1 LIMIT 1');
            $st->execute([wglease_key($pool_id, 's:' . $short_uuid)]);
            $m = $st->fetchColumn();
            if ($m !== false && isset($by_id[(int) $m])) return $by_id[(int) $m];
        } catch (Throwable $e) {}
    }
    $cfg = wglease_assign($p, $pool_id, $lease_key, $short_uuid, $hwid, $cand_ids, $by_id, $now, $ua);
    if ($cfg === null) {
        try {
            if ($hwid === '') $p->prepare('DELETE FROM wg_lease WHERE pool_id = ? AND manual = 0 AND hwid IS NOT NULL')->execute([$pool_id]);
            else $p->prepare('DELETE FROM wg_lease WHERE pool_id = ? AND manual = 0 AND hwid IS NULL')->execute([$pool_id]);
        } catch (Throwable $e) {}
        wglease_reclaim($pool_id);
        $cfg = wglease_assign($p, $pool_id, $lease_key, $short_uuid, $hwid, $cand_ids, $by_id, $now, $ua);
    }
    return $cfg;
}

function wglease_assign($p, $pool_id, $lease_key, $short_uuid, $hwid, array $cand_ids, array $by_id, $now, $ua = '') {
    if (!$cand_ids) return null;
    try {
        $in = implode(',', array_fill(0, count($cand_ids), '?'));
        $st = $p->prepare("SELECT config_id FROM wg_lease WHERE config_id IN ($in)");
        $st->execute($cand_ids);
        $taken = [];
        foreach ($st->fetchAll() as $r) $taken[(int) $r['config_id']] = true;
    } catch (Throwable $e) { $taken = []; }
    foreach ($cand_ids as $cid) {
        if (isset($taken[$cid])) continue;
        try {
            $ins = $p->prepare('INSERT INTO wg_lease (pool_id, lease_key, config_id, short_uuid, hwid, manual, created_ts, seen_ts, ua) VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?)');
            $ins->execute([$pool_id, $lease_key, $cid, ($short_uuid !== '' ? $short_uuid : null), ($hwid !== '' ? $hwid : null), $now, $now, ((string) $ua !== '' ? (string) $ua : null)]);
            return $by_id[$cid];
        } catch (Throwable $e) {
            try {
                $st2 = $p->prepare('SELECT config_id FROM wg_lease WHERE lease_key = ? LIMIT 1');
                $st2->execute([$lease_key]);
                $ex = $st2->fetchColumn();
                if ($ex !== false && isset($by_id[(int) $ex])) return $by_id[(int) $ex];
            } catch (Throwable $e2) {}
        }
    }
    return null;
}

function wglease_reclaim($pool_id) {
    wglease_ensure();
    if (!($p = db())) return;
    $cut = time() - wglease_reclaim_days() * 86400;
    try { $p->prepare('DELETE FROM wg_lease WHERE pool_id = ? AND manual = 0 AND seen_ts < ?')->execute([(string) $pool_id, $cut]); }
    catch (Throwable $e) {}
}

function wglease_clear_pool_auto($pool_id) {
    wglease_ensure();
    $pool_id = (string) $pool_id;
    if (!($p = db()) || $pool_id === '') return;
    try { $p->prepare('DELETE FROM wg_lease WHERE pool_id = ? AND manual = 0')->execute([$pool_id]); }
    catch (Throwable $e) {}
}

function wglease_manual_add($config_id, $short_uuid, $hwid = '') {
    wglease_ensure();
    $config_id = (int) $config_id; $short_uuid = trim((string) $short_uuid); $hwid = trim((string) $hwid);
    if (!($p = db()) || $config_id <= 0 || $short_uuid === '') return [false, 'Не хватает данных'];
    $pool = 'manual:' . $short_uuid;
    $sub = $hwid !== '' ? ('d:' . $short_uuid . '|' . $hwid) : ('u:' . $short_uuid);
    $key = wglease_key($pool, $sub);
    $now = time();
    try {
        $p->prepare('DELETE FROM wg_lease WHERE lease_key = ?')->execute([$key]);
        $p->prepare('DELETE FROM wg_lease WHERE config_id = ?')->execute([$config_id]);
        $ins = $p->prepare('INSERT INTO wg_lease (pool_id, lease_key, config_id, short_uuid, hwid, manual, created_ts, seen_ts) VALUES (?, ?, ?, ?, ?, 1, ?, ?)');
        $ins->execute([$pool, $key, $config_id, $short_uuid, ($hwid !== '' ? $hwid : null), $now, $now]);
        return [true, ''];
    } catch (Throwable $e) { return [false, $e->getMessage()]; }
}

function wglease_del($id) {
    wglease_ensure();
    $id = (int) $id;
    if (!($p = db()) || $id <= 0) return false;
    try { return $p->prepare('DELETE FROM wg_lease WHERE id = ?')->execute([$id]); }
    catch (Throwable $e) { return false; }
}

function wglease_reset_auto() {
    wglease_ensure();
    if (!($p = db())) return 0;
    try {
        $n = (int) $p->query('SELECT COUNT(*) FROM wg_lease WHERE manual = 0')->fetchColumn();
        $p->exec('DELETE FROM wg_lease WHERE manual = 0');
        set_setting('wgl_cfg_uidx', '');
        wglease_ensure_cfg_uidx($p);
        return $n;
    } catch (Throwable $e) { return 0; }
}

function wglease_free($config_id) {
    wglease_ensure();
    $config_id = (int) $config_id;
    if (!($p = db()) || $config_id <= 0) return 0;
    try {
        $st = $p->prepare('DELETE FROM wg_lease WHERE config_id = ? AND manual = 0');
        $st->execute([$config_id]);
        return (int) $st->rowCount();
    } catch (Throwable $e) { return 0; }
}

function wglease_dupes() {
    wglease_ensure();
    if (!($p = db())) return [];
    $out = [];
    try { foreach ($p->query('SELECT config_id, COUNT(*) c FROM wg_lease GROUP BY config_id HAVING COUNT(*) > 1') as $r) $out[(int) $r['config_id']] = (int) $r['c']; }
    catch (Throwable $e) {}
    return $out;
}

function wglease_list($pool_id = '') {
    wglease_ensure();
    if (!($p = db())) return [];
    try {
        if ($pool_id !== '') {
            $st = $p->prepare('SELECT * FROM wg_lease WHERE pool_id = ? ORDER BY manual DESC, seen_ts DESC');
            $st->execute([(string) $pool_id]);
            return $st->fetchAll();
        }
        $out = [];
        foreach ($p->query('SELECT * FROM wg_lease ORDER BY pool_id, manual DESC, seen_ts DESC') as $r) $out[] = $r;
        return $out;
    } catch (Throwable $e) { return []; }
}

function wglease_manual_count() {
    wglease_ensure();
    if (!($p = db())) return 0;
    try { return (int) $p->query('SELECT COUNT(*) FROM wg_lease WHERE manual = 1')->fetchColumn(); }
    catch (Throwable $e) { return 0; }
}

function wglease_hwid_platforms() {
    wglease_ensure();
    if (!($p = db())) return [];
    $out = [];
    try { foreach ($p->query('SELECT hwid, platform FROM hwid_devices') as $r) { $h = (string) ($r['hwid'] ?? ''); if ($h !== '') $out[$h] = (string) ($r['platform'] ?? ''); } }
    catch (Throwable $e) {}
    return $out;
}

function wglease_hwid_upsert($user_uuid, $hwid, $short_uuid = '', $platform = '') {
    wglease_ensure();
    $user_uuid = trim((string) $user_uuid); $hwid = trim((string) $hwid);
    if (!($p = db()) || $user_uuid === '' || $hwid === '') return;
    $now = time();
    try {
        if (db_driver() === 'mysql') {
            $st = $p->prepare('INSERT INTO hwid_devices (user_uuid, hwid, short_uuid, platform, seen_ts) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE short_uuid = VALUES(short_uuid), platform = VALUES(platform), seen_ts = VALUES(seen_ts)');
        } else {
            $st = $p->prepare('INSERT INTO hwid_devices (user_uuid, hwid, short_uuid, platform, seen_ts) VALUES (?, ?, ?, ?, ?) ON CONFLICT(user_uuid, hwid) DO UPDATE SET short_uuid = excluded.short_uuid, platform = excluded.platform, seen_ts = excluded.seen_ts');
        }
        $st->execute([$user_uuid, $hwid, ($short_uuid !== '' ? $short_uuid : null), ($platform !== '' ? $platform : null), $now]);
    } catch (Throwable $e) { error_log('submw wglease hwid_upsert: ' . $e->getMessage()); }
}

function wglease_hwid_delete($user_uuid, $hwid) {
    wglease_ensure();
    $user_uuid = trim((string) $user_uuid); $hwid = trim((string) $hwid);
    if (!($p = db())) return;
    try {
        if ($hwid !== '') {
            $p->prepare('DELETE FROM hwid_devices WHERE user_uuid = ? AND hwid = ?')->execute([$user_uuid, $hwid]);
            $p->prepare('DELETE FROM wg_lease WHERE manual = 0 AND hwid = ?')->execute([$hwid]);
        } else {
            $p->prepare('DELETE FROM hwid_devices WHERE user_uuid = ?')->execute([$user_uuid]);
        }
    } catch (Throwable $e) {}
}

function wglease_purge_user($short_uuid) {
    wglease_ensure();
    $short_uuid = trim((string) $short_uuid);
    if (!($p = db()) || $short_uuid === '') return;
    try { $p->prepare('DELETE FROM wg_lease WHERE manual = 0 AND short_uuid = ?')->execute([$short_uuid]); }
    catch (Throwable $e) {}
}

function wglease_manual_for_user($short_uuid, $hwid) {
    wglease_ensure();
    $short_uuid = (string) $short_uuid; $hwid = (string) $hwid;
    if ($short_uuid === '' || !($p = db())) return [];
    $ids = [];
    try {
        $st = $p->prepare('SELECT config_id, hwid FROM wg_lease WHERE manual = 1 AND short_uuid = ?');
        $st->execute([$short_uuid]);
        foreach ($st->fetchAll() as $r) {
            $lh = (string) ($r['hwid'] ?? '');
            if ($lh === '' || ($hwid !== '' && $lh === $hwid)) $ids[] = (int) $r['config_id'];
        }
    } catch (Throwable $e) {}
    if (!$ids) return [];
    $out = [];
    foreach (squadconf_by_ids($ids) as $c) if ((int) ($c['enabled'] ?? 0) === 1) $out[] = $c;
    return $out;
}

function wglease_sizing(&$err = '', &$warn = '', &$totals = null) {
    $err = '';
    $warn = '';
    $totals = ['records' => 0, 'unique' => 0];
    if (remnawave_url() === '' || remnawave_token() === '') { $err = 'Нет подключения к панели'; return []; }
    $users = remnawave_all_users($err);
    if ($err !== '') return [];
    $te = '';
    $devices = remnawave_hwid_all_devices($te);
    $warn = $te;
    $dev_by_user = []; $uniq_hwid = [];
    foreach ($devices as $d) {
        if (!is_array($d)) continue;
        $du = (string) ($d['userId'] ?? $d['userUuid'] ?? '');
        if ($du !== '') $dev_by_user[$du] = ($dev_by_user[$du] ?? 0) + 1;
        $hh = (string) ($d['hwid'] ?? '');
        if ($hh !== '') $uniq_hwid[$hh] = 1;
    }
    $totals = ['records' => count($devices), 'unique' => count($uniq_hwid)];
    $out = [];
    foreach ($users as $u) {
        if (!is_array($u)) continue;
        $uu = (string) ($u['uuid'] ?? '');
        $active = strtoupper((string) ($u['status'] ?? '')) === 'ACTIVE';
        $lim = $u['hwidDeviceLimit'] ?? null;
        $dev = $dev_by_user[(string) ($u['id'] ?? '')] ?? ($dev_by_user[$uu] ?? 0);
        $squads = $u['activeInternalSquads'] ?? [];
        if (!is_array($squads)) continue;
        foreach ($squads as $sq) {
            $sid = is_array($sq) ? (string) ($sq['uuid'] ?? '') : '';
            if ($sid === '') continue;
            if (!isset($out[$sid])) $out[$sid] = ['users' => 0, 'active' => 0, 'devices' => 0, 'limit_sum' => 0, 'nolimit' => 0];
            $out[$sid]['users']++;
            if ($active) $out[$sid]['active']++;
            $out[$sid]['devices'] += $dev;
            $li = ($lim === null || $lim === '') ? 0 : (int) $lim;
            if ($li <= 0) $out[$sid]['nolimit']++;
            else $out[$sid]['limit_sum'] += $li;
        }
    }
    return $out;
}

function wglease_sizing_save($rows, $totals = null) {
    set_setting('wgpool_sizing_cache', json_encode(is_array($rows) ? $rows : [], JSON_UNESCAPED_UNICODE));
    set_setting('wgpool_sizing_totals', json_encode(is_array($totals) ? $totals : ['records' => 0, 'unique' => 0], JSON_UNESCAPED_UNICODE));
    set_setting('wgpool_sizing_ts', (string) time());
}

function wglease_sizing_cached() {
    $j = (string) setting('wgpool_sizing_cache', '');
    $rows = $j !== '' ? json_decode($j, true) : [];
    if (!is_array($rows)) $rows = [];
    $tj = (string) setting('wgpool_sizing_totals', '');
    $tot = $tj !== '' ? json_decode($tj, true) : [];
    if (!is_array($tot)) $tot = [];
    return ['rows' => $rows, 'ts' => (int) setting('wgpool_sizing_ts', '0'), 'totals' => ['records' => (int) ($tot['records'] ?? 0), 'unique' => (int) ($tot['unique'] ?? 0)]];
}


function wglease_user_cache_ttl() { return 3600; }

function ensure_wg_user_cache() {
    static $done = false;
    if ($done) return true;
    if (!($p = db())) return false;
    try {
        if (db_driver() === 'mysql') {
            $p->exec("CREATE TABLE IF NOT EXISTS wg_user_cache (
                short_uuid VARCHAR(64) NOT NULL,
                data TEXT NOT NULL,
                ts INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (short_uuid),
                KEY idx_wguc_ts (ts)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } else {
            $p->exec("CREATE TABLE IF NOT EXISTS wg_user_cache (
                short_uuid TEXT PRIMARY KEY,
                data TEXT NOT NULL,
                ts INTEGER NOT NULL DEFAULT 0
            )");
            $p->exec("CREATE INDEX IF NOT EXISTS idx_wguc_ts ON wg_user_cache(ts)");
        }
    } catch (Throwable $e) { error_log('submw wg_user_cache ensure: ' . $e->getMessage()); return false; }
    $done = true;
    return true;
}

function wglease_user_cache() {
    if (!ensure_wg_user_cache() || !($p = db())) return [];
    $out = [];
    try {
        $st = $p->prepare('SELECT short_uuid, data, ts FROM wg_user_cache WHERE ts > ?');
        $st->execute([time() - wglease_user_cache_ttl()]);
        foreach ($st as $r) {
            $e = json_decode((string) ($r['data'] ?? ''), true);
            if (!is_array($e)) continue;
            $e['ts'] = (int) ($r['ts'] ?? 0);
            $out[(string) $r['short_uuid']] = $e;
        }
    } catch (Throwable $e) {}
    return $out;
}

function wglease_user_cache_put($short_uuid, array $entry) {
    $short_uuid = trim((string) $short_uuid);
    if ($short_uuid === '' || !ensure_wg_user_cache() || !($p = db())) return;
    unset($entry['ts']);
    $now = time();
    try {
        if (db_driver() === 'mysql') {
            $sql = 'INSERT INTO wg_user_cache (short_uuid, data, ts) VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE data = VALUES(data), ts = VALUES(ts)';
        } else {
            $sql = 'INSERT INTO wg_user_cache (short_uuid, data, ts) VALUES (?, ?, ?)
                    ON CONFLICT(short_uuid) DO UPDATE SET data = excluded.data, ts = excluded.ts';
        }
        $p->prepare($sql)->execute([$short_uuid, json_encode($entry, JSON_UNESCAPED_UNICODE), $now]);
        if (random_int(1, 50) === 1) {
            try {
                $p->prepare('DELETE FROM wg_user_cache WHERE ts < ?')->execute([$now - wglease_user_cache_ttl()]);
                if (db_driver() === 'mysql') {
                    $p->exec('DELETE FROM wg_user_cache WHERE short_uuid NOT IN (SELECT s FROM (SELECT short_uuid s FROM wg_user_cache ORDER BY ts DESC LIMIT 3000) t)');
                } else {
                    $p->exec('DELETE FROM wg_user_cache WHERE short_uuid NOT IN (SELECT short_uuid FROM wg_user_cache ORDER BY ts DESC LIMIT 3000)');
                }
            } catch (Throwable $e) {}
        }
    } catch (Throwable $e) { error_log('submw wg_user_cache put: ' . $e->getMessage()); }
}
