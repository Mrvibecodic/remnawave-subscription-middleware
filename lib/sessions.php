<?php

// Сессии подключений: планировщик снимает с нод через панель «кто с какого IP сейчас онлайн»
// и склеивает снимки в сессии. Сама панель историю не хранит — у неё только живой опрос.

function sess_enabled() { return setting('sess_enabled', '0') === '1'; }

function sess_every_opts() { return [60, 120, 300, 600]; }

function sess_every() {
    $v = (int) setting('sess_every', '120');
    return in_array($v, sess_every_opts(), true) ? $v : 120;
}

function sess_keep_days() { return max(7, min(365, (int) setting('sess_keep_days', '30'))); }

function sess_nolim() { return max(1, min(50, (int) setting('sess_nolim', '3'))); }

function sess_asn_extra() { return max(0, min(20, (int) setting('sess_asn_extra', '1'))); }

// Сессия жива, пока её видели не раньше двух интервалов назад: один пропущенный тик её не рвёт.
function sess_gap() { return sess_every() * 2 + 60; }

function sess_ddl($drv) {
    if ($drv === 'mysql') {
        return ["CREATE TABLE IF NOT EXISTS conn_sess (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, uid BIGINT UNSIGNED NOT NULL,
            node VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            ip VARCHAR(45) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            cc CHAR(2) NOT NULL DEFAULT '', asn INT UNSIGNED NOT NULL DEFAULT 0,
            org VARCHAR(128) NOT NULL DEFAULT '', sub VARCHAR(64) NOT NULL DEFAULT '',
            start_ts INT UNSIGNED NOT NULL, last_ts INT UNSIGNED NOT NULL, n INT UNSIGNED NOT NULL DEFAULT 1,
            PRIMARY KEY (id), KEY idx_cs_uid (uid, last_ts), KEY idx_cs_last (last_ts)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"];
    }
    return [
        "CREATE TABLE IF NOT EXISTS conn_sess (
            id INTEGER PRIMARY KEY AUTOINCREMENT, uid INTEGER NOT NULL, node TEXT NOT NULL, ip TEXT NOT NULL,
            cc TEXT NOT NULL DEFAULT '', asn INTEGER NOT NULL DEFAULT 0, org TEXT NOT NULL DEFAULT '', sub TEXT NOT NULL DEFAULT '',
            start_ts INTEGER NOT NULL, last_ts INTEGER NOT NULL, n INTEGER NOT NULL DEFAULT 1
        )",
        'CREATE INDEX IF NOT EXISTS idx_cs_uid ON conn_sess(uid, last_ts)',
        'CREATE INDEX IF NOT EXISTS idx_cs_last ON conn_sess(last_ts)',
    ];
}

function sess_ensure() {
    static $ok = null;
    if ($ok !== null) return $ok;
    if (!($p = db())) return false;
    try { foreach (sess_ddl(db_driver()) as $sql) $p->exec($sql); $ok = true; }
    catch (Throwable $e) { error_log('submw conn_sess table: ' . $e->getMessage()); $ok = false; }
    return $ok;
}

function sess_state() {
    $s = json_decode((string) setting('sess_state', ''), true);
    return is_array($s) ? $s : [];
}

function sess_nodes_known() {
    $s = json_decode((string) setting('sess_nodes', ''), true);
    return is_array($s) ? $s : [];
}

// До 3.0 обозреватель сессий — /api/ip-control с uuid пользователя, с 3.0 — /api/connections с числовым id.
function sess_api_v3() {
    $o = $GLOBALS['submw_sess_v3'] ?? null;
    return is_bool($o) ? $o : panel_api_v3();
}

function sess_paths() {
    if (sess_api_v3()) {
        return [
            'node' => fn($uuid) => '/api/connections/by-node/' . rawurlencode($uuid),
            'node_res' => fn($job) => '/api/connections/by-node/' . rawurlencode($job),
            'user' => fn($ref) => '/api/connections/by-user/' . rawurlencode($ref),
            'user_res' => fn($job) => '/api/connections/by-user/' . rawurlencode($job),
            'drop' => '/api/connections/drop',
            'drop_by' => 'userIds',
        ];
    }
    return [
        'node' => fn($uuid) => '/api/ip-control/fetch-users-ips/' . rawurlencode($uuid),
        'node_res' => fn($job) => '/api/ip-control/fetch-users-ips/result/' . rawurlencode($job),
        'user' => fn($ref) => '/api/ip-control/fetch-ips/' . rawurlencode($ref),
        'user_res' => fn($job) => '/api/ip-control/fetch-ips/result/' . rawurlencode($job),
        'drop' => '/api/ip-control/drop-connections',
        'drop_by' => 'userUuids',
    ];
}

function sess_scopes() {
    if (sess_api_v3()) return ['node' => 'connections:by-node', 'node_res' => 'connections:by-node-result', 'user' => 'connections:by-user', 'user_res' => 'connections:by-user-result', 'drop' => 'connections:drop'];
    return ['node' => 'ip-control:fetch-users-ips', 'node_res' => 'ip-control:fetch-users-ips-result', 'user' => 'ip-control:fetch-ips', 'user_res' => 'ip-control:fetch-ips-result', 'drop' => 'ip-control:drop-connections'];
}

function sess_job_start($path, &$err = '') {
    $err = '';
    [$ok, $code, $data, $e] = remnawave_api_request('POST', $path);
    if (!$ok) {
        $msg = is_array($data) ? (string) ($data['message'] ?? '') : '';
        if ($code === 404 && strpos($msg, 'Cannot POST') === 0) $err = 'нет такого адреса API';
        else $err = $msg !== '' ? $msg : ($e ?: ('HTTP ' . $code));
        return '';
    }
    $job = (string) (($data['response'] ?? [])['jobId'] ?? '');
    if ($job === '') $err = 'панель не вернула jobId';
    return $job;
}

// Задачи опрашиваются по кругу, пока все не завершатся или не выйдет время.
// Возвращает по ключу: массив результата, false — задача упала или не успела, null — не стартовала.
function sess_jobs_wait(array $jobs, callable $path, $deadline) {
    $out = [];
    $pending = [];
    foreach ($jobs as $k => $job) {
        if ($job === '') $out[$k] = null;
        else $pending[$k] = $job;
    }
    while ($pending && microtime(true) < $deadline) {
        foreach ($pending as $k => $job) {
            if (microtime(true) >= $deadline) break;
            [$ok, , $data, ] = remnawave_api_get($path($job));
            if (!$ok) continue;
            $r = is_array($data) ? ($data['response'] ?? null) : null;
            if (!is_array($r)) continue;
            if (!empty($r['isFailed'])) { $out[$k] = false; unset($pending[$k]); continue; }
            if (!empty($r['isCompleted'])) { $out[$k] = is_array($r['result'] ?? null) ? $r['result'] : false; unset($pending[$k]); }
        }
        if ($pending) usleep(300000);
    }
    foreach ($pending as $k => $job) $out[$k] = false;
    return $out;
}

function sess_ip_ok($ip) {
    return is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) !== false;
}

function sess_nodes_fetch(&$err = '') {
    $err = '';
    [$ok, $code, $data, $e] = remnawave_api_get('/api/nodes');
    if (!$ok) { $err = $e ?: ('HTTP ' . $code); return null; }
    $resp = is_array($data) ? ($data['response'] ?? $data) : [];
    $list = is_array($resp) ? ($resp['nodes'] ?? (isset($resp[0]) ? $resp : [])) : [];
    $known = sess_nodes_known();
    $live = [];
    foreach ((array) $list as $n) {
        if (!is_array($n) || empty($n['uuid'])) continue;
        $u = (string) $n['uuid'];
        $known[$u] = ['n' => mb_substr((string) ($n['name'] ?? ''), 0, 64), 'cc' => strtoupper(mb_substr((string) ($n['countryCode'] ?? ''), 0, 2))];
        if (!empty($n['isConnected']) && empty($n['isDisabled']) && empty($n['isConnecting'])) $live[$u] = $known[$u];
    }
    if (count($known) > 500) $known = array_slice($known, -500, null, true);
    set_setting('sess_nodes', json_encode($known, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    return $live;
}

// Снимок со всех подключённых нод → сессии. Возвращает текст ошибки для планировщика или ''.
function sess_collect($deadline) {
    $t0 = microtime(true);
    if (!sess_ensure()) return 'нет таблицы сессий';
    $err = '';
    $nodes = sess_nodes_fetch($err);
    if ($nodes === null) return 'список нод: ' . $err;
    $st = ['ts' => time(), 'nodes' => count($nodes), 'ok' => 0, 'rows' => 0, 'ms' => 0, 'fail' => [], 'err' => ''];
    if (!$nodes) { sess_state_save($st, $t0); return ''; }
    $P = sess_paths();
    $jobs = [];
    $first = '';
    $flipped = false;
    foreach ($nodes as $uuid => $info) {
        if (microtime(true) >= $deadline) break;
        $e = '';
        $jobs[$uuid] = sess_job_start($P['node']($uuid), $e);
        if ($e === 'нет такого адреса API' && !$flipped) {
            // Версия панели в кэше могла быть не той: пробуем второе семейство адресов один раз.
            $flipped = true;
            $GLOBALS['submw_sess_v3'] = !sess_api_v3();
            $P = sess_paths();
            $jobs[$uuid] = sess_job_start($P['node']($uuid), $e);
        }
        if ($e !== '' && $first === '') $first = $e;
    }
    $res = sess_jobs_wait($jobs, $P['node_res'], $deadline);
    $now = time();
    $seen = [];
    foreach ($res as $uuid => $r) {
        if (!is_array($r)) { $st['fail'][] = (string) ($nodes[$uuid]['n'] ?? $uuid); continue; }
        $st['ok']++;
        foreach ((array) ($r['users'] ?? []) as $u) {
            if (!is_array($u)) continue;
            $uid = (string) ($u['userId'] ?? '');
            if (!ctype_digit($uid) || $uid === '0') continue;
            foreach ((array) ($u['ips'] ?? []) as $ipr) {
                $ip = is_array($ipr) ? ($ipr['ip'] ?? '') : $ipr;
                if (!sess_ip_ok($ip)) continue;
                $seen[(int) $uid . '|' . $uuid . '|' . $ip] = [(int) $uid, (string) $uuid, (string) $ip];
            }
        }
    }
    $st['rows'] = count($seen);
    if ($seen) $st['err'] = sess_store($seen, $now);
    elseif ($first !== '' && $st['ok'] === 0) $st['err'] = $first;
    sess_state_save($st, $t0);
    if ($st['ok'] === 0) return 'ни одна нода не ответила' . ($first !== '' ? ': ' . $first : '');
    return $st['err'];
}

function sess_state_save(array $st, $t0) {
    $st['ms'] = (int) round((microtime(true) - $t0) * 1000);
    $st['fail'] = array_slice($st['fail'], 0, 20);
    set_setting('sess_state', json_encode($st, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
}

function sess_store(array $seen, $now) {
    $p = db();
    if (!$p) return 'нет базы';
    $open = [];
    try {
        $st = $p->prepare('SELECT id, uid, node, ip FROM conn_sess WHERE last_ts >= ?');
        $st->execute([$now - sess_gap()]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $open[(int) $r['uid'] . '|' . $r['node'] . '|' . $r['ip']] = (int) $r['id'];
    } catch (Throwable $e) { return 'чтение сессий: ' . $e->getMessage(); }
    try {
        $p->beginTransaction();
        $upd = $p->prepare('UPDATE conn_sess SET last_ts = ?, n = n + 1 WHERE id = ?');
        $ins = $p->prepare('INSERT INTO conn_sess (uid, node, ip, cc, asn, org, sub, start_ts, last_ts, n) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)');
        foreach ($seen as $k => [$uid, $node, $ip]) {
            if (isset($open[$k])) { $upd->execute([$now, $open[$k]]); continue; }
            $g = function_exists('geoip_lookup') ? geoip_lookup($ip) : [];
            $ins->execute([$uid, $node, $ip, (string) ($g['cc'] ?? ''), (int) ($g['asn'] ?? 0), (string) ($g['org'] ?? ''), (string) ($g['sub'] ?? ''), $now, $now]);
        }
        $p->commit();
    } catch (Throwable $e) {
        try { $p->rollBack(); } catch (Throwable $e2) {}
        return 'запись сессий: ' . $e->getMessage();
    }
    return '';
}

function sess_purge_maybe() {
    if (time() - (int) setting('sess_purge_ts', '0') < 3600) return;
    set_setting('sess_purge_ts', (string) time());
    if (sess_ensure() && function_exists('gc_purge')) gc_purge('conn_sess', sess_keep_days(), 5.0);
}

function cron_task_sessions($ctx) {
    if (!sess_enabled()) return '';
    $err = sess_collect(min(microtime(true) + 15, $ctx['deadline'] - 2));
    sess_purge_maybe();
    return $err;
}

// Сырые метрики по пользователям за 7 дней; пороги применяет интерфейс, у него под рукой лимит устройств.
function sess_metrics(?array $uids = null) {
    $out = [];
    if (!sess_ensure() || !($p = db())) return $out;
    $now = time();
    $gap = sess_gap();
    try {
        $sql = 'SELECT uid, node, ip, cc, asn, sub, start_ts, last_ts FROM conn_sess WHERE last_ts >= ?';
        $args = [$now - 7 * 86400];
        if ($uids !== null) {
            $uids = array_values(array_unique(array_map('intval', $uids)));
            if (!$uids) return $out;
            $sql .= ' AND uid IN (' . implode(',', array_fill(0, count($uids), '?')) . ')';
            $args = array_merge($args, $uids);
        }
        $st = $p->prepare($sql);
        $st->execute($args);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return $out; }
    $acc = [];
    foreach ($rows as $r) {
        $u = (int) $r['uid'];
        if (!isset($acc[$u])) $acc[$u] = ['li' => [], 'lc' => [], 'lr' => [], 'da' => [], 'i7' => [], 'ls' => 0];
        $a = &$acc[$u];
        $ip = (string) $r['ip'];
        $asn = (int) $r['asn'];
        $cc = (string) $r['cc'];
        $sub = (string) $r['sub'];
        $pk = $asn > 0 ? 'a' . $asn : 'i' . $ip;
        $a['i7'][$ip] = 1;
        $a['ls'] = max($a['ls'], (int) $r['last_ts']);
        if ((int) $r['last_ts'] >= $now - 86400) $a['da'][$pk] = 1;
        if ((int) $r['last_ts'] >= $now - $gap) {
            $a['li'][$ip] = 1;
            if ($cc !== '') $a['lc'][$cc] = 1;
            if ($asn > 0 && $sub !== '') $a['lr'][$asn][$sub] = 1;
        }
        unset($a);
    }
    foreach ($acc as $u => $a) {
        $lr = 0;
        foreach ($a['lr'] as $subs) $lr = max($lr, count($subs));
        $out[$u] = ['li' => count($a['li']), 'lc' => count($a['lc']), 'lr' => $lr, 'da' => count($a['da']), 'i7' => count($a['i7']), 'ls' => $a['ls']];
    }
    return $out;
}

function sess_user_rows($uid, $days = 30, $limit = 600) {
    if (!sess_ensure() || !($p = db())) return [];
    try {
        $st = $p->prepare('SELECT node, ip, cc, asn, org, sub, start_ts, last_ts, n FROM conn_sess WHERE uid = ? AND last_ts >= ? ORDER BY last_ts DESC LIMIT ' . (int) $limit);
        $st->execute([(int) $uid, time() - (int) $days * 86400]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = ['node' => (string) $r['node'], 'ip' => (string) $r['ip'], 'cc' => (string) $r['cc'], 'asn' => (int) $r['asn'], 'org' => (string) $r['org'], 'sub' => (string) $r['sub'], 's' => (int) $r['start_ts'], 'e' => (int) $r['last_ts'], 'n' => (int) $r['n']];
        }
        return $out;
    } catch (Throwable $e) { return []; }
}

function sess_user_data($uid) {
    $uid = (int) $uid;
    $m = sess_metrics([$uid]);
    return [
        'on'    => sess_enabled(),
        'alive' => function_exists('cron_alive') ? cron_alive() : false,
        'every' => sess_every(),
        'gap'   => sess_gap(),
        'keep'  => sess_keep_days(),
        'state' => sess_state(),
        'nodes' => sess_nodes_known(),
        'rows'  => $uid > 0 ? sess_user_rows($uid) : [],
        'm'     => $m[$uid] ?? null,
        'city'  => function_exists('geoip_city_ready') && geoip_city_ready(),
    ];
}

function sess_geo($ip) {
    $g = function_exists('geoip_lookup') ? geoip_lookup($ip) : [];
    return ['cc' => (string) ($g['cc'] ?? ''), 'asn' => (int) ($g['asn'] ?? 0), 'org' => (string) ($g['org'] ?? ''), 'sub' => (string) ($g['sub'] ?? '')];
}

// Живой опрос нод по одному пользователю: ждём ответа до 8 секунд, частичный результат тоже отдаём.
function sess_live($ref, &$err = '') {
    $err = '';
    $ref = rw_ref_coerce($ref);
    if (!rw_ref_ok($ref)) { $err = 'Пустой идентификатор пользователя'; return null; }
    $P = sess_paths();
    $job = sess_job_start($P['user']($ref['val']), $err);
    if ($job === '' && $err === 'нет такого адреса API') {
        $GLOBALS['submw_sess_v3'] = !sess_api_v3();
        $P = sess_paths();
        $job = sess_job_start($P['user']($ref['val']), $err);
    }
    if ($job === '') return null;
    $res = sess_jobs_wait(['u' => $job], $P['user_res'], microtime(true) + 8);
    $r = $res['u'] ?? null;
    if (!is_array($r)) { $err = $r === false ? 'Панель не успела опросить ноды — попробуйте ещё раз' : 'Нет ответа'; return null; }
    $known = sess_nodes_known();
    $out = [];
    foreach ((array) ($r['nodes'] ?? []) as $n) {
        if (!is_array($n)) continue;
        $nu = (string) ($n['nodeUuid'] ?? '');
        $ips = [];
        foreach ((array) ($n['ips'] ?? []) as $ipr) {
            $ip = is_array($ipr) ? ($ipr['ip'] ?? '') : $ipr;
            if (!sess_ip_ok($ip)) continue;
            $seen = is_array($ipr) ? strtotime((string) ($ipr['lastSeen'] ?? '')) : false;
            $ips[] = ['ip' => (string) $ip, 'seen' => $seen ? (int) $seen : 0] + sess_geo($ip);
        }
        if (!$ips) continue;
        $out[] = ['node' => $nu, 'name' => (string) ($n['nodeName'] ?? ($known[$nu]['n'] ?? '')), 'ncc' => strtoupper((string) ($n['countryCode'] ?? ($known[$nu]['cc'] ?? ''))), 'ips' => $ips];
    }
    return $out;
}

function sess_drop($ref, array $ips = [], &$err = '') {
    $err = '';
    $P = sess_paths();
    $ips = array_values(array_filter($ips, 'sess_ip_ok'));
    if ($ips) {
        $by = ['by' => 'ipAddresses', 'ipAddresses' => $ips];
    } else {
        $ref = rw_ref_coerce($ref);
        if (!rw_ref_ok($ref)) { $err = 'Пустой идентификатор пользователя'; return false; }
        $by = $P['drop_by'] === 'userIds' ? ['by' => 'userIds', 'userIds' => [(int) $ref['val']]] : ['by' => 'userUuids', 'userUuids' => [(string) $ref['val']]];
    }
    [$ok, $code, $data, $e] = remnawave_api_request('POST', $P['drop'], ['dropBy' => $by, 'targetNodes' => ['target' => 'allNodes']]);
    if (!$ok) {
        $msg = is_array($data) ? ($data['message'] ?? '') : '';
        $err = is_string($msg) && $msg !== '' ? $msg : ($e ?: ('HTTP ' . $code));
        return false;
    }
    return true;
}
