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

// По умолчанию 2: у одного устройства часто два адреса сразу (IPv4 + IPv6, смена Wi-Fi ↔ LTE в окне склейки).
function sess_ip_per() { return max(1, min(10, (int) setting('sess_ip_per', '2'))); }

// Общий лимит устройств панели — для порогов в списке, пока карточка не подгрузила META. Кэш 10 минут.
function sess_hwid_cached() {
    $e = '';
    return remnawave_hwid_settings($e, 600);
}

// Без базы ASN провайдера нет — считаем сетью /24 (IPv6 — /48), чтобы смена адреса внутри пула не выглядела новым провайдером.
function sess_net_key($ip) {
    if (strpos($ip, ':') !== false) {
        $bin = @inet_pton($ip);
        return 'n6:' . ($bin !== false ? bin2hex(substr($bin, 0, 6)) : $ip);
    }
    $parts = explode('.', $ip);
    return 'n4:' . implode('.', array_slice($parts, 0, 3));
}

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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS sess_anom (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, uid BIGINT UNSIGNED NOT NULL,
            gid VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            lvl VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'bad',
            start_ts INT UNSIGNED NOT NULL, last_ts INT UNSIGNED NOT NULL, end_ts INT UNSIGNED NOT NULL DEFAULT 0,
            why TEXT NOT NULL, ips TEXT NOT NULL,
            PRIMARY KEY (id), KEY idx_sa_uid (uid, end_ts), KEY idx_sa_last (last_ts)
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
        "CREATE TABLE IF NOT EXISTS sess_anom (
            id INTEGER PRIMARY KEY AUTOINCREMENT, uid INTEGER NOT NULL, gid TEXT NOT NULL, lvl TEXT NOT NULL DEFAULT 'bad',
            start_ts INTEGER NOT NULL, last_ts INTEGER NOT NULL, end_ts INTEGER NOT NULL DEFAULT 0,
            why TEXT NOT NULL DEFAULT '', ips TEXT NOT NULL DEFAULT ''
        )",
        'CREATE INDEX IF NOT EXISTS idx_sa_uid ON sess_anom(uid, end_ts)',
        'CREATE INDEX IF NOT EXISTS idx_sa_last ON sess_anom(last_ts)',
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
    if (is_bool($o)) return $o;
    $saved = (string) setting('sess_api_v3', '');
    return $saved === '1' ? true : ($saved === '0' ? false : panel_api_v3());
}

// Панель ответила «нет такого адреса» — семейство API угадано неверно; запоминаем другое.
function sess_api_flip() {
    $GLOBALS['submw_sess_v3'] = !sess_api_v3();
    set_setting('sess_api_v3', $GLOBALS['submw_sess_v3'] ? '1' : '0');
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

// maxAge 0 — свежий список нод из панели (опрос); иначе — общий снимок не старше maxAge.
function sess_nodes_fetch(&$err = '', $maxAge = 0) {
    $err = '';
    $list = panel_ref('nodes', $maxAge, $err);
    if (!is_array($list)) { if ($err === '') $err = 'панель недоступна'; return null; }
    $known = sess_nodes_known();
    $live = [];
    $byAddr = [];
    $fresh = false;
    foreach ($list as $n) {
        if (!is_array($n) || empty($n['uuid'])) continue;
        $u = (string) $n['uuid'];
        if (!isset($known[$u])) $fresh = true;
        $known[$u] = ['n' => mb_substr((string) ($n['name'] ?? ''), 0, 64), 'cc' => strtoupper(mb_substr((string) ($n['countryCode'] ?? ''), 0, 2)), 'h' => $known[$u]['h'] ?? []];
        $addr = strtolower(trim((string) ($n['address'] ?? '')));
        if ($addr !== '') $byAddr[$addr][] = $u;
        if (!empty($n['isConnected']) && empty($n['isDisabled']) && empty($n['isConnecting'])) $live[$u] = $known[$u];
    }
    // Хосты — то, что юзер видит в подписке; нода привязана к хосту явно (nodes) или совпадает адресом.
    // Ремарки меняются редко — снимок на 10 минут; новая нода в списке — повод перечитать сразу.
    $hosts = panel_ref('hosts', $fresh ? 0 : 600);
    if (is_array($hosts)) {
        $map = [];
        foreach ($hosts as $h) {
            if (!is_array($h) || !empty($h['isDisabled'])) continue;
            $rem = trim(preg_replace('~\s+~u', ' ', (string) ($h['remark'] ?? '')));
            if ($rem === '') continue;
            $rem = mb_substr($rem, 0, 48);
            $ids = (array) ($h['nodes'] ?? []);
            if (!$ids) $ids = $byAddr[strtolower(trim((string) ($h['address'] ?? '')))] ?? [];
            foreach ($ids as $id) if (isset($known[$id]) && !in_array($rem, $map[$id] ?? [], true) && count($map[$id] ?? []) < 4) $map[$id][] = $rem;
        }
        foreach ($known as $u => $info) $known[$u]['h'] = $map[$u] ?? [];
        foreach ($live as $u => $info) $live[$u]['h'] = $map[$u] ?? [];
    }
    if (count($known) > 500) $known = array_slice($known, -500, null, true);
    $json = json_encode($known, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json !== (string) setting('sess_nodes', '')) set_setting('sess_nodes', $json);
    // Возраст подписей считается от снимка, а не от пересборки: суммарно не старше прежних 10 минут.
    set_setting('sess_nodes_ts', (string) (panel_ref_ts('nodes') ?: time()));
    return $live;
}

function sess_nodes_fresh() {
    if (time() - (int) setting('sess_nodes_ts', '0') < 600) return sess_nodes_known();
    $e = '';
    sess_nodes_fetch($e, 600);
    return sess_nodes_known();
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
            sess_api_flip();
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
    $cur = [];
    if ($seen) $st['err'] = sess_store($seen, $now, $cur);
    elseif ($first !== '' && $st['ok'] === 0) $st['err'] = $first;
    // Схема аномалий — по тому же снимку; закрывать события по «пропавшим» можно только при полном опросе.
    if ($st['ok'] > 0 && $st['err'] === '') $st['an'] = sess_anom_eval($cur, $now, !$st['fail'], $deadline);
    sess_state_save($st, $t0);
    if ($st['ok'] === 0) return 'ни одна нода не ответила' . ($first !== '' ? ': ' . $first : '');
    return $st['err'];
}

function sess_state_save(array $st, $t0) {
    $st['ms'] = (int) round((microtime(true) - $t0) * 1000);
    $st['fail'] = array_slice($st['fail'], 0, 20);
    set_setting('sess_state', json_encode($st, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
}

// $cur — тот же снимок с гео по пользователям (uid → строки ip/node/cc/asn/org/sub), для расчёта аномалий без второго прохода по базе.
function sess_store(array $seen, $now, ?array &$cur = null) {
    $p = db();
    if (!$p) return 'нет базы';
    $open = [];
    $cur = [];
    try {
        $st = $p->prepare('SELECT id, uid, node, ip, cc, asn, org, sub FROM conn_sess WHERE last_ts >= ?');
        $st->execute([$now - sess_gap()]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $open[(int) $r['uid'] . '|' . $r['node'] . '|' . $r['ip']] = $r;
    } catch (Throwable $e) { return 'чтение сессий: ' . $e->getMessage(); }
    try {
        $p->beginTransaction();
        $upd = $p->prepare('UPDATE conn_sess SET last_ts = ?, n = n + 1 WHERE id = ?');
        $ins = $p->prepare('INSERT INTO conn_sess (uid, node, ip, cc, asn, org, sub, start_ts, last_ts, n) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)');
        foreach ($seen as $k => [$uid, $node, $ip]) {
            if (isset($open[$k])) {
                $r = $open[$k];
                $upd->execute([$now, (int) $r['id']]);
                $cur[$uid][] = ['ip' => $ip, 'node' => $node, 'cc' => (string) $r['cc'], 'asn' => (int) $r['asn'], 'org' => (string) $r['org'], 'sub' => (string) $r['sub']];
                continue;
            }
            $g = function_exists('geoip_lookup') ? geoip_lookup($ip) : [];
            $row = ['ip' => $ip, 'node' => $node, 'cc' => (string) ($g['cc'] ?? ''), 'asn' => (int) ($g['asn'] ?? 0), 'org' => (string) ($g['org'] ?? ''), 'sub' => (string) ($g['sub'] ?? '')];
            $ins->execute([$uid, $node, $ip, $row['cc'], $row['asn'], $row['org'], $row['sub'], $now, $now]);
            $cur[$uid][] = $row;
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
    if (sess_ensure() && function_exists('gc_purge')) { gc_purge('conn_sess', sess_keep_days(), 5.0); gc_purge('sess_anom', sess_anom_keep_days(), 2.0); }
}

function cron_task_sessions($ctx) {
    if (!sess_enabled()) return '';
    $err = sess_collect(min(microtime(true) + 15, $ctx['deadline'] - 2));
    sess_purge_maybe();
    return $err;
}

// Окно аномалий в днях: по нему считаются значок в списке и плитки в карточке.
function sess_win_days() { return max(1, min(30, (int) setting('sess_win_days', '1'))); }

// Метрики за окно: pi/pc/pr — пик одновременных IP / стран / регионов одного провайдера в любой
// момент окна (а не «сейчас» и не «разных за окно»: мобильный IP меняется по нескольку раз в день),
// da — разных провайдеров, iw — разных IP, ls — последний раз виден. Пороги применяет интерфейс,
// у него под рукой лимит устройств. Строки читаются по пользователям подряд, в памяти — интервалы одного.
function sess_metrics(?array $uids = null, $days = null) {
    $out = [];
    if (!sess_ensure() || !($p = db())) return $out;
    $now = time();
    $from = $now - max(1, (int) ($days ?? sess_win_days())) * 86400;
    $every = sess_every();
    try {
        $sql = 'SELECT uid, ip, cc, asn, sub, start_ts, last_ts FROM conn_sess WHERE last_ts >= ?';
        $args = [$from];
        if ($uids !== null) {
            $uids = array_values(array_unique(array_map('intval', $uids)));
            if (!$uids) return $out;
            $sql .= ' AND uid IN (' . implode(',', array_fill(0, count($uids), '?')) . ')';
            $args = array_merge($args, $uids);
        }
        $st = $p->prepare($sql . ' ORDER BY uid');
        $st->execute($args);
    } catch (Throwable $e) { return $out; }
    $cur = null; $rows = [];
    $flush = static function () use (&$cur, &$rows, &$out, $every, $from) {
        if ($cur === null) return;
        $ips = []; $as = []; $ls = 0; $ev = [];
        foreach ($rows as $r) {
            $ips[$r['ip']] = 1;
            $as[$r['asn'] > 0 ? 'a' . $r['asn'] : sess_net_key($r['ip'])] = 1;
            $ls = max($ls, $r['e']);
            $s = max($r['s'], $from); $e = $r['e'] + $every;
            if ($e <= $s) continue;
            $ev[] = [$s, 1, $r]; $ev[] = [$e, -1, $r];
        }
        // Конец интервала раньше начала другого в тот же момент: смена IP между опросами — не одновременность.
        usort($ev, static fn($a, $b) => $a[0] <=> $b[0] ?: $a[1] <=> $b[1]);
        $act = ['ip' => [], 'cc' => [], 'sub' => []];
        $n = ['ip' => 0, 'cc' => 0]; $pk = ['ip' => 0, 'cc' => 0, 'sub' => 0]; $nsub = [];
        foreach ($ev as [$t, $d, $r]) {
            $keys = ['ip' => $r['ip'], 'cc' => $r['cc']];
            foreach ($keys as $k => $v) {
                if ($v === '') continue;
                $c = ($act[$k][$v] ?? 0) + $d;
                if ($c <= 0) { unset($act[$k][$v]); $n[$k]--; }
                elseif ($c === $d) { $act[$k][$v] = $c; $n[$k]++; }
                else $act[$k][$v] = $c;
                if ($n[$k] > $pk[$k]) $pk[$k] = $n[$k];
            }
            if ($r['asn'] > 0 && $r['sub'] !== '') {
                $a = $r['asn']; $v = $r['sub'];
                $c = ($act['sub'][$a][$v] ?? 0) + $d;
                if ($c <= 0) { unset($act['sub'][$a][$v]); $nsub[$a]--; }
                elseif ($c === $d) { $act['sub'][$a][$v] = $c; $nsub[$a] = ($nsub[$a] ?? 0) + 1; }
                else $act['sub'][$a][$v] = $c;
                if (($nsub[$a] ?? 0) > $pk['sub']) $pk['sub'] = $nsub[$a];
            }
        }
        $out[$cur] = ['pi' => $pk['ip'], 'pc' => $pk['cc'], 'pr' => $pk['sub'], 'da' => count($as), 'iw' => count($ips), 'ls' => $ls];
        $rows = [];
    };
    while (($r = $st->fetch(PDO::FETCH_ASSOC)) !== false) {
        $u = (int) $r['uid'];
        if ($u !== $cur) { $flush(); $cur = $u; }
        $rows[] = ['ip' => (string) $r['ip'], 'cc' => (string) $r['cc'], 'asn' => (int) $r['asn'], 'sub' => (string) $r['sub'], 's' => (int) $r['start_ts'], 'e' => (int) $r['last_ts']];
    }
    $flush();
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
        'win'   => sess_win_days(),
        'state' => sess_state(),
        'nodes' => sess_nodes_fresh(),
        'rows'  => $uid > 0 ? sess_user_rows($uid) : [],
        'm'     => $m[$uid] ?? null,
        'an'    => $uid > 0 ? sess_anom_user($uid) : [],
        'city'  => function_exists('geoip_city_ready') && geoip_city_ready(),
    ];
}

// Пересчёт страны, провайдера и региона в накопленных сессиях по текущим базам: гео пишется
// при появлении сессии, и после смены базы история и идущие сессии иначе остаются со старыми именами.
function sess_regeo() {
    if (!sess_ensure() || !($p = db()) || !function_exists('geoip_reader')) return 0;
    if (geoip_reader('country') === null && geoip_reader('asn') === null) return 0;
    $n = 0;
    try {
        $st = $p->prepare('SELECT ip, cc, asn, org, sub FROM conn_sess WHERE last_ts >= ? GROUP BY ip, cc, asn, org, sub');
        $st->execute([time() - sess_keep_days() * 86400]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $upd = $p->prepare('UPDATE conn_sess SET cc = ?, asn = ?, org = ?, sub = ? WHERE ip = ? AND cc = ? AND asn = ? AND org = ? AND sub = ?');
        $p->beginTransaction();
        foreach ($rows as $r) {
            $g = sess_geo((string) $r['ip']);
            // Чего новая база не знает — не стирать: лучше старое значение, чем пустое.
            $cc = $g['cc'] !== '' ? $g['cc'] : (string) $r['cc'];
            $asn = $g['asn'] > 0 ? $g['asn'] : (int) $r['asn'];
            $org = $g['asn'] > 0 ? mb_substr($g['org'], 0, 128) : (string) $r['org'];
            $sub = $g['sub'] !== '' ? mb_substr($g['sub'], 0, 64) : (string) $r['sub'];
            if ($cc === (string) $r['cc'] && $asn === (int) $r['asn'] && $org === (string) $r['org'] && $sub === (string) $r['sub']) continue;
            $upd->execute([$cc, $asn, $org, $sub, $r['ip'], $r['cc'], $r['asn'], $r['org'], $r['sub']]);
            $n += $upd->rowCount();
        }
        $p->commit();
    } catch (Throwable $e) {
        try { $p->rollBack(); } catch (Throwable $e2) {}
        error_log('submw sess regeo: ' . $e->getMessage());
    }
    return $n;
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
        sess_api_flip();
        $P = sess_paths();
        $job = sess_job_start($P['user']($ref['val']), $err);
    }
    if ($job === '') return null;
    $res = sess_jobs_wait(['u' => $job], $P['user_res'], microtime(true) + 8);
    $r = $res['u'] ?? null;
    if (!is_array($r)) { $err = $r === false ? 'Панель не успела опросить ноды — попробуйте ещё раз' : 'Нет ответа'; return null; }
    $known = sess_nodes_fresh();
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
        $out[] = ['node' => $nu, 'name' => (string) ($n['nodeName'] ?? ($known[$nu]['n'] ?? '')), 'ncc' => strtoupper((string) ($n['countryCode'] ?? ($known[$nu]['cc'] ?? ''))), 'h' => array_values((array) ($known[$nu]['h'] ?? [])), 'ips' => $ips];
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

// ===== Аномалии: схема условий, расчёт по снимку, журнал =====

function sess_anom_keep_days() { return max(7, min(30, (int) setting('sess_anom_keep', '30'))); }

function sess_anom_metrics() {
    return ['ip' => 'IP сейчас', 'asn' => 'Провайдеров сейчас', 'sub' => 'Регионов у провайдера', 'cc' => 'Стран сейчас'];
}

function sess_anom_kinds() { return ['mul' => 'устройств ×', 'add' => 'устройств +', 'abs' => 'числа']; }

// Типовая схема: красная — IP или провайдеров больше, чем устройств; жёлтая — регионы одного провайдера или две страны.
function sess_anom_default() {
    return [
        ['name' => 'Группа 1', 'lvl' => 'bad', 'op' => 'or', 'c' => [['m' => 'ip', 'k' => 'mul', 'n' => 2], ['m' => 'asn', 'k' => 'add', 'n' => 1]]],
        ['name' => 'Группа 2', 'lvl' => 'warn', 'op' => 'or', 'c' => [['m' => 'sub', 'k' => 'abs', 'n' => 1], ['m' => 'cc', 'k' => 'abs', 'n' => 1]]],
    ];
}

// Прежние пороги (IP на устройство, запас по провайдерам, две страны, регионы) — одной схемой.
function sess_anom_legacy() {
    return [
        ['name' => 'Группа 1', 'lvl' => 'bad', 'op' => 'or', 'c' => [['m' => 'ip', 'k' => 'mul', 'n' => sess_ip_per()], ['m' => 'asn', 'k' => 'add', 'n' => sess_asn_extra()], ['m' => 'cc', 'k' => 'abs', 'n' => 1]]],
        ['name' => 'Группа 2', 'lvl' => 'warn', 'op' => 'and', 'c' => [['m' => 'sub', 'k' => 'abs', 'n' => 1]]],
    ];
}

function sess_anom_norm($raw) {
    if (!is_array($raw)) return [];
    $out = [];
    $i = 0;
    foreach ($raw as $g) {
        if (!is_array($g) || count($out) >= 10) break;
        $c = [];
        foreach ((array) ($g['c'] ?? []) as $x) {
            if (!is_array($x) || count($c) >= 8) continue;
            $m = (string) ($x['m'] ?? ''); $k = (string) ($x['k'] ?? '');
            if (!isset(sess_anom_metrics()[$m]) || !isset(sess_anom_kinds()[$k])) continue;
            $n = (int) ($x['n'] ?? 0);
            $n = $k === 'mul' ? max(1, min(20, $n)) : max(0, min(999, $n));
            $c[] = ['m' => $m, 'k' => $k, 'n' => $n];
        }
        if (!$c) continue;
        $i++;
        $name = trim(preg_replace('~\s+~u', ' ', (string) ($g['name'] ?? '')));
        $out[] = [
            'id'   => 'g' . $i,
            'name' => $name !== '' ? mb_substr($name, 0, 40) : 'Группа ' . $i,
            'lvl'  => ($g['lvl'] ?? '') === 'warn' ? 'warn' : 'bad',
            'op'   => ($g['op'] ?? '') === 'and' ? 'and' : 'or',
            'c'    => $c,
        ];
    }
    return $out;
}

// Схема из настроек. Первый вызов на установке с уже включённым сбором переносит старые пороги, иначе схема пустая.
function sess_anom_rules() {
    $rules = &$GLOBALS['submw_anom_rules'];
    if (is_array($rules)) return $rules;
    $raw = setting('sess_anom', null);
    if ($raw === null) return sess_anom_save(sess_enabled() ? sess_anom_legacy() : []);
    $rules = sess_anom_norm(json_decode((string) $raw, true));
    return $rules;
}

function sess_anom_save($raw) {
    $rules = sess_anom_norm($raw);
    set_setting('sess_anom', json_encode($rules, JSON_UNESCAPED_UNICODE));
    $GLOBALS['submw_anom_rules'] = $rules;
    return $rules;
}

// Лимиты устройств пользователей из панели: id → [свой лимит | null «как в панели», внешний сквад]. Кэш 10 минут.
function sess_users_limits($maxAge = 600) {
    $src = md5(remnawave_url() . "\n" . remnawave_token());
    $c = admin_cache_get('sess_users', $maxAge);
    if (is_array($c) && ($c['src'] ?? '') === $src && is_array($c['u'] ?? null)) return $c['u'];
    $stale = static function () use ($src) { $o = admin_cache_get('sess_users', PHP_INT_MAX); return (is_array($o) && ($o['src'] ?? '') === $src && is_array($o['u'] ?? null)) ? $o['u'] : []; };
    if (remnawave_url() === '' || remnawave_token() === '') return $stale();
    $err = '';
    $u = [];
    remnawave_all_users($err, static function (array $page) use (&$u) {
        foreach ($page as $x) {
            if (!is_array($x) || !isset($x['id'])) continue;
            $hw = (isset($x['hwidDeviceLimit']) && $x['hwidDeviceLimit'] !== '' && $x['hwidDeviceLimit'] !== null) ? (int) $x['hwidDeviceLimit'] : null;
            $u[(string) (int) $x['id']] = [$hw, (string) ($x['externalSquadUuid'] ?? '')];
        }
    });
    if ($err !== '') return $stale();
    admin_cache_put('sess_users', ['src' => $src, 'u' => $u]);
    return $u;
}

// «Устройства» для порогов: свой лимит → внешний сквад → общий лимит панели; без лимита — число из настроек.
function sess_anom_devices($lim, array $exh, $glob, $nolim) {
    $hw = $lim[0] ?? null;
    $ex = (string) ($lim[1] ?? '');
    if ($hw === 0) return $nolim;
    if ($hw > 0) return $hw;
    $s = ($ex !== '' && isset($exh[$ex])) ? $exh[$ex] : $glob;
    if (!is_array($s) || empty($s['enabled']) || (int) ($s['limit'] ?? 0) <= 0) return $nolim;
    return (int) $s['limit'];
}

// Значения метрик по снимку одного пользователя.
function sess_anom_vals(array $rows) {
    $ip = []; $as = []; $cc = []; $sub = [];
    foreach ($rows as $r) {
        $ip[$r['ip']] = 1;
        $ak = $r['asn'] > 0 ? 'a' . $r['asn'] : sess_net_key($r['ip']);
        $as[$ak] = 1;
        if ($r['cc'] !== '') $cc[$r['cc']] = 1;
        if ($r['asn'] > 0 && $r['sub'] !== '') $sub[$r['asn']][$r['sub']] = 1;
    }
    $pr = 0;
    foreach ($sub as $v) $pr = max($pr, count($v));
    return ['ip' => count($ip), 'asn' => count($as), 'sub' => $pr, 'cc' => count($cc)];
}

function sess_anom_thr(array $c, $dev) {
    if ($c['k'] === 'mul') return $dev * $c['n'];
    if ($c['k'] === 'add') return $dev + $c['n'];
    return $c['n'];
}

// Что сработало: [[метрика, значение, порог], …] или null.
function sess_anom_match(array $g, array $vals, $dev) {
    $hit = [];
    foreach ($g['c'] as $c) {
        $thr = sess_anom_thr($c, $dev);
        $ok = $vals[$c['m']] > $thr;
        if ($ok) $hit[] = [$c['m'], $vals[$c['m']], $thr];
        elseif ($g['op'] === 'and') return null;
    }
    return $hit ? $hit : null;
}

function sess_anom_ips(array $rows, $cap = 20) {
    $out = [];
    foreach ($rows as $r) {
        if (count($out) >= $cap) break;
        $out[$r['ip']] = ['ip' => $r['ip'], 'cc' => $r['cc'], 'asn' => $r['asn'], 'org' => mb_substr($r['org'], 0, 48), 'sub' => mb_substr($r['sub'], 0, 48), 'node' => $r['node']];
    }
    return array_values($out);
}

// Прогон схемы по снимку. Пишется только изменение: открылось, добавились IP, закрылось.
// $full — опрошены все ноды: только тогда отсутствие пользователя в снимке закрывает его события.
function sess_anom_eval(array $cur, $now, $full, $deadline = null) {
    if ($deadline !== null && microtime(true) > $deadline - 1) return 'skip';
    if (!sess_ensure() || !($p = db())) return 'nodb';
    $rules = sess_anom_rules();
    $open = [];
    try {
        $st = $p->query('SELECT id, uid, gid, ips FROM sess_anom WHERE end_ts = 0');
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $open[(int) $r['uid'] . '|' . $r['gid']] = $r;
    } catch (Throwable $e) { return 'read'; }
    $ins = []; $upd = []; $close = [];
    if ($rules) {
        $nolim = sess_nolim();
        $limits = $cur ? sess_users_limits() : [];
        $exh = [];
        $needEx = false;
        foreach ($cur as $uid => $rows) if ((string) ($limits[(string) $uid][1] ?? '') !== '') { $needEx = true; break; }
        if ($needEx) foreach ((array) panel_ref('esquads', 600) as $s) if (is_array($s) && !empty($s['uuid'])) $exh[(string) $s['uuid']] = $s['hwid'] ?? null;
        $e = '';
        $glob = remnawave_hwid_settings($e, 600);
        foreach ($cur as $uid => $rows) {
            $vals = sess_anom_vals($rows);
            $dev = sess_anom_devices($limits[(string) $uid] ?? [null, ''], $exh, $glob, $nolim);
            foreach ($rules as $g) {
                $hit = sess_anom_match($g, $vals, $dev);
                if ($hit === null) continue;
                $key = (int) $uid . '|' . $g['id'];
                $why = ['dev' => $dev, 'h' => $hit];
                if (isset($open[$key])) {
                    $o = $open[$key];
                    unset($open[$key]);
                    $have = json_decode((string) $o['ips'], true);
                    $have = is_array($have) ? $have : [];
                    $known = array_column($have, 'ip');
                    $add = [];
                    foreach (sess_anom_ips($rows) as $x) if (!in_array($x['ip'], $known, true)) $add[] = $x;
                    if ($add) $upd[] = [(int) $o['id'], array_slice(array_merge($have, $add), 0, 40), $why];
                    continue;
                }
                $ins[] = [(int) $uid, $g['id'], $g['lvl'], $why, sess_anom_ips($rows)];
            }
        }
    }
    // Осталось открытым, но не совпало: пользователь онлайн без аномалии — закрыть; пользователя нет в снимке — только при полном опросе.
    foreach ($open as $o) if ($full || isset($cur[(int) $o['uid']])) $close[] = (int) $o['id'];
    if (!$ins && !$upd && !$close) return '';
    try {
        $p->beginTransaction();
        if ($ins) {
            $st = $p->prepare('INSERT INTO sess_anom (uid, gid, lvl, start_ts, last_ts, end_ts, why, ips) VALUES (?, ?, ?, ?, ?, 0, ?, ?)');
            foreach ($ins as [$uid, $gid, $lvl, $why, $ips]) $st->execute([$uid, $gid, $lvl, $now, $now, json_encode($why, JSON_UNESCAPED_UNICODE), json_encode($ips, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)]);
        }
        if ($upd) {
            $st = $p->prepare('UPDATE sess_anom SET last_ts = ?, ips = ?, why = ? WHERE id = ?');
            foreach ($upd as [$id, $ips, $why]) $st->execute([$now, json_encode($ips, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), json_encode($why, JSON_UNESCAPED_UNICODE), $id]);
        }
        if ($close) {
            $st = $p->prepare('UPDATE sess_anom SET end_ts = ?, last_ts = ? WHERE id = ?');
            foreach ($close as $id) $st->execute([$now, $now, $id]);
        }
        $p->commit();
    } catch (Throwable $e) {
        try { $p->rollBack(); } catch (Throwable $e2) {}
        return 'write';
    }
    return '';
}

// Пересчёт по последнему снимку (после смены схемы): текущие — строки сессий, живые на момент последнего опроса.
function sess_anom_recalc() {
    $st = sess_state();
    $ts = (int) ($st['ts'] ?? 0);
    if ($ts <= 0 || !sess_ensure() || !($p = db())) return;
    $cur = [];
    try {
        $q = $p->prepare('SELECT uid, node, ip, cc, asn, org, sub FROM conn_sess WHERE last_ts >= ?');
        $q->execute([$ts]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $cur[(int) $r['uid']][] = ['ip' => (string) $r['ip'], 'node' => (string) $r['node'], 'cc' => (string) $r['cc'], 'asn' => (int) $r['asn'], 'org' => (string) $r['org'], 'sub' => (string) $r['sub']];
    } catch (Throwable $e) { return; }
    sess_anom_eval($cur, time(), empty($st['fail']));
}

// Открытые события для списка: uid → {l: bad|warn, n, t: подсказка}.
function sess_anom_open_map() {
    $out = [];
    if (!sess_ensure() || !($p = db())) return $out;
    $names = [];
    foreach (sess_anom_rules() as $g) $names[$g['id']] = $g['name'];
    try {
        foreach ($p->query('SELECT uid, gid, lvl, why FROM sess_anom WHERE end_ts = 0') as $r) {
            $u = (string) (int) $r['uid'];
            $t = sess_anom_why_text(json_decode((string) $r['why'], true));
            if (!isset($out[$u])) $out[$u] = ['l' => $r['lvl'], 'n' => 0, 't' => []];
            if ($r['lvl'] === 'bad') $out[$u]['l'] = 'bad';
            $out[$u]['n']++;
            $out[$u]['t'][] = ($names[$r['gid']] ?? $r['gid']) . ': ' . $t;
        }
    } catch (Throwable $e) {}
    foreach ($out as $u => $v) $out[$u]['t'] = implode('; ', $v['t']);
    return $out;
}

function sess_anom_why_text($why) {
    if (!is_array($why)) return '';
    $m = sess_anom_metrics();
    $parts = [];
    foreach ((array) ($why['h'] ?? []) as $h) {
        if (!is_array($h) || count($h) < 3) continue;
        $l = (string) ($m[$h[0]] ?? $h[0]);
        if (preg_match('~^[А-ЯЁ][а-яё]~u', $l)) $l = mb_strtolower(mb_substr($l, 0, 1)) . mb_substr($l, 1);
        $parts[] = $l . ' ' . (int) $h[1] . ' при пороге ' . (int) $h[2];
    }
    return implode(', ', $parts);
}

// Журнал пользователя: последние события, открытые первыми.
function sess_anom_user($uid, $limit = 100) {
    if (!sess_ensure() || !($p = db())) return [];
    $names = [];
    foreach (sess_anom_rules() as $g) $names[$g['id']] = $g['name'];
    try {
        $st = $p->prepare('SELECT gid, lvl, start_ts, last_ts, end_ts, why, ips FROM sess_anom WHERE uid = ? ORDER BY end_ts = 0 DESC, start_ts DESC LIMIT ' . (int) $limit);
        $st->execute([(int) $uid]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $why = json_decode((string) $r['why'], true);
            $ips = json_decode((string) $r['ips'], true);
            $out[] = ['g' => $names[$r['gid']] ?? $r['gid'], 'lvl' => (string) $r['lvl'], 's' => (int) $r['start_ts'], 'l' => (int) $r['last_ts'], 'e' => (int) $r['end_ts'],
                'dev' => (int) ($why['dev'] ?? 0), 'h' => is_array($why['h'] ?? null) ? $why['h'] : [], 'ips' => is_array($ips) ? $ips : []];
        }
        return $out;
    } catch (Throwable $e) { return []; }
}
