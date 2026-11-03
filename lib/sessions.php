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
        "CREATE TABLE IF NOT EXISTS sess_poll (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, ts INT UNSIGNED NOT NULL,
            eng VARCHAR(4) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'php',
            nodes INT UNSIGNED NOT NULL DEFAULT 0, ok INT UNSIGNED NOT NULL DEFAULT 0, ms INT UNSIGNED NOT NULL DEFAULT 0,
            reqs INT UNSIGNED NOT NULL DEFAULT 0, conns INT UNSIGNED NOT NULL DEFAULT 0,
            err VARCHAR(255) NOT NULL DEFAULT '', fail TEXT NULL, retry TEXT NULL,
            PRIMARY KEY (id), KEY idx_sp_ts (ts)
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
        "CREATE TABLE IF NOT EXISTS sess_poll (
            id INTEGER PRIMARY KEY AUTOINCREMENT, ts INTEGER NOT NULL, eng TEXT NOT NULL DEFAULT 'php',
            nodes INTEGER NOT NULL DEFAULT 0, ok INTEGER NOT NULL DEFAULT 0, ms INTEGER NOT NULL DEFAULT 0,
            reqs INTEGER NOT NULL DEFAULT 0, conns INTEGER NOT NULL DEFAULT 0, err TEXT NOT NULL DEFAULT '', fail TEXT NULL, retry TEXT NULL
        )",
        'CREATE INDEX IF NOT EXISTS idx_sp_ts ON sess_poll(ts)',
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
    // Журнал опросов из первых сборок dev — без колонки повторов.
    try {
        $cols = $ok ? db_table_columns($p, db_driver(), 'sess_poll') : [];
        if ($cols && !in_array('retry', $cols, true)) $p->exec('ALTER TABLE sess_poll ADD COLUMN retry TEXT NULL');
    } catch (Throwable $e) { error_log('submw sess_poll retry column: ' . $e->getMessage()); }
    return $ok;
}

function sess_state() {
    $s = json_decode((string) setting('sess_state', ''), true);
    return is_array($s) ? $s : [];
}

// Снимок нод — в admin_cache, не в settings: settings целиком читаются на каждом запросе подписки.
function sess_nodes_known() {
    $c = function_exists('admin_cache_get') ? admin_cache_get('sess_nodes', PHP_INT_MAX) : null;
    return is_array($c) && is_array($c['n'] ?? null) ? $c['n'] : [];
}

// До 3.0 обозреватель сессий — /api/ip-control с uuid пользователя, с 3.0 — /api/connections с числовым id.
// Версия панели ещё неизвестна — считаем 3.x; промах исправит sess_api_flip.
function sess_api_v3() {
    $o = $GLOBALS['submw_sess_v3'] ?? null;
    if (is_bool($o)) return $o;
    $saved = (string) setting('sess_api_v3', '');
    return $saved === '1' ? true : ($saved === '0' ? false : (panel_version() === '' || panel_api_v3()));
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

// Задачи опрашиваются по кругу, пока все не завершатся или не выйдет время; пауза между кругами
// растёт с 0,3 до 2 с, чтобы десятки нод не превращались в сотни запросов за тик.
// Возвращает по ключу: массив результата, false — задача упала или не успела, null — не стартовала.
function sess_jobs_wait(array $jobs, callable $path, $deadline, &$reqs = 0) {
    $out = [];
    $pending = [];
    foreach ($jobs as $k => $job) {
        if ($job === '') $out[$k] = null;
        else $pending[$k] = $job;
    }
    $pause = 300000;
    while ($pending && microtime(true) < $deadline) {
        foreach ($pending as $k => $job) {
            if (microtime(true) >= $deadline) break;
            [$ok, , $data, ] = remnawave_api_get($path($job));
            $reqs++;
            if (!$ok) continue;
            $r = is_array($data) ? ($data['response'] ?? null) : null;
            if (!is_array($r)) continue;
            if (!empty($r['isFailed'])) { $out[$k] = false; unset($pending[$k]); continue; }
            if (!empty($r['isCompleted'])) { $out[$k] = is_array($r['result'] ?? null) ? $r['result'] : false; unset($pending[$k]); }
        }
        if ($pending) { usleep($pause); $pause = min(2000000, (int) ($pause * 1.5)); }
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
    // Хосты — то, что юзер видит в подписке. Ремарки меняются редко — снимок на 10 минут; новая нода в списке — повод перечитать сразу.
    $hosts = $fresh ? panel_ref('hosts', 0) : panel_ref_upgraded('hosts', 600, 'sqm');
    if (is_array($hosts)) {
        $map = [];
        foreach ($hosts as $h) {
            $rem = sess_host_remark($h);
            if ($rem === '') continue;
            foreach (sess_host_nodes($h, $byAddr) as $id) if (isset($known[$id]) && !in_array($rem, $map[$id] ?? [], true) && count($map[$id] ?? []) < 4) $map[$id][] = $rem;
        }
        foreach ($known as $u => $info) $known[$u]['h'] = $map[$u] ?? [];
        foreach ($live as $u => $info) $live[$u]['h'] = $map[$u] ?? [];
    }
    if (count($known) > 500) $known = array_slice($known, -500, null, true);
    if ($known !== sess_nodes_known()) admin_cache_put('sess_nodes', ['n' => $known]);
    // Возраст подписей считается от снимка, а не от пересборки: суммарно не старше прежних 10 минут.
    set_setting('sess_nodes_ts', (string) (panel_ref_ts('nodes') ?: time()));
    return $live;
}

function sess_host_remark($h) {
    if (!is_array($h) || !empty($h['isDisabled'])) return '';
    return mb_substr(trim(preg_replace('~\s+~u', ' ', (string) ($h['remark'] ?? ''))), 0, 48);
}

// Нода привязана к хосту явно (nodes) или совпадает адресом.
function sess_host_nodes(array $h, array $byAddr) {
    $ids = (array) ($h['nodes'] ?? []);
    return $ids ?: ($byAddr[strtolower(trim((string) ($h['address'] ?? '')))] ?? []);
}

// Хосты по нодам из уже снятых снимков панели, без запросов; null — снимка нет или он старого формата.
function sess_host_index() {
    $hs = panel_ref_cached('hosts');
    $ns = panel_ref_cached('nodes');
    if (!is_array($hs) || !is_array($ns)) return null;
    $memo = &$GLOBALS['submw_sess_hidx'];
    $mk = panel_ref_stamp('hosts') . '|' . panel_ref_stamp('nodes');
    if (is_array($memo) && $memo[0] === $mk) return $memo[1];
    $byAddr = [];
    foreach ($ns as $n) {
        $addr = is_array($n) ? strtolower(trim((string) ($n['address'] ?? ''))) : '';
        if ($addr !== '' && !empty($n['uuid'])) $byAddr[$addr][] = (string) $n['uuid'];
    }
    $idx = [];
    foreach ($hs as $h) {
        if (!is_array($h)) continue;
        if (!array_key_exists('sqm', $h)) { $idx = null; break; }
        $rem = sess_host_remark($h);
        if ($rem === '') continue;
        $row = ['r' => $rem, 'i' => strtolower((string) ($h['inbound'] ?? '')), 'a' => $h['sqm'] === 'A', 's' => array_map(static fn($x) => strtolower((string) $x), (array) ($h['sqs'] ?? [])), 'x' => !empty($h['hid'])];
        foreach (sess_host_nodes($h, $byAddr) as $id) $idx[(string) $id][] = $row;
    }
    $memo = [$mk, $idx];
    return $idx;
}

// Сквады пользователя → [инбаунд => сквады, которые его дают]; null — снимок сквадов без инбаундов, отбора не будет.
function sess_sq_inb($sq) {
    if (!is_array($sq)) return null;
    $list = panel_ref_upgraded('isquads', 600, 'inb');
    if (!is_array($list) || !$list) return null;
    $want = array_flip(array_map(static fn($x) => strtolower((string) $x), $sq));
    // Сквада нет в снимке — он новее снимка: перечитываем, но не чаще раза в минуту.
    $have = array_flip(array_map(static fn($s) => is_array($s) ? strtolower((string) ($s['uuid'] ?? '')) : '', $list));
    if (array_diff_key($want, $have)) {
        $l2 = panel_ref('isquads', 60);
        if (is_array($l2) && $l2) $list = $l2;
    }
    $out = [];
    foreach ($list as $s) {
        $id = is_array($s) ? strtolower((string) ($s['uuid'] ?? '')) : '';
        if (!isset($want[$id])) continue;
        if (!array_key_exists('inb', $s)) return null;
        foreach ((array) $s['inb'] as $in) $out[strtolower((string) $in)][] = $id;
    }
    return $out;
}

function sess_sq_param($v) {
    if (!is_string($v)) return null;
    $out = [];
    foreach (explode(',', $v) as $x) if (preg_match('~^[0-9a-f-]{36}$~i', $x = trim($x))) $out[] = strtolower($x);
    return $out;
}

// Как панель собирает подписку: инбаунд хоста есть в скваде пользователя, и этот сквад не исключён у хоста
// (EXCLUDE) или указан у него (ALLOW_ONLY). Скрытые хосты панель кладёт только в балансировщики — они идут в подпись, лишь если обычных нет.
function sess_user_hosts($uuid, array $node, $sqi) {
    $idx = $sqi === null ? null : sess_host_index();
    if ($idx === null) return array_values((array) ($node['h'] ?? []));
    $vis = []; $hid = [];
    foreach ($idx[(string) $uuid] ?? [] as $h) {
        $ok = false;
        foreach ($sqi[$h['i']] ?? [] as $sid) {
            if (in_array($sid, $h['s'], true) === $h['a']) { $ok = true; break; }
        }
        if (!$ok) continue;
        if (!$h['x']) $vis[$h['r']] = 1; else $hid[$h['r']] = 1;
    }
    $all = array_map('strval', array_keys($vis ?: $hid));
    if (count($all) > 4) return array_merge(array_slice($all, 0, 3), ['+' . (count($all) - 3)]);
    return $all;
}

function sess_nodes_for(array $known, $sqi) {
    foreach ($known as $u => $n) $known[$u]['h'] = sess_user_hosts($u, $n, $sqi);
    return $known;
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
    if ($nodes === null) {
        $eng = function_exists('go_wanted') && go_wanted() && !empty(go_state()['ok']) ? 'go' : 'php';
        sess_plog_add(['eng' => $eng, 'nodes' => 0, 'ok' => 0, 'rows' => 0, 'ms' => (int) round((microtime(true) - $t0) * 1000), 'fail' => [], 'err' => 'список нод: ' . $err], 0);
        return 'список нод: ' . $err;
    }
    $st = ['ts' => time(), 'nodes' => count($nodes), 'ok' => 0, 'rows' => 0, 'ms' => 0, 'fail' => [], 'err' => ''];
    if (!$nodes) { sess_state_save($st, $t0); return ''; }
    // Последние 3 с — на запись и схему аномалий: иначе одна зависшая нода съедает весь бюджет и аномалии не считаются никогда.
    $wait_until = $deadline - 3;
    $first = '';
    $reqs = 0;
    $res = null;
    $eng = 'php';
    $gerr = '';
    $retried = [];
    $tp = microtime(true);
    if (function_exists('go_wanted') && go_wanted()) {
        $res = go_sess_poll($nodes, $wait_until, $gerr, $first, $reqs, $retried);
        // Не ответил Go, который уже работал или задан руками, — это «PHP вместо Go»; у кого Go нет — просто PHP.
        $eng = $res !== null ? 'go' : (go_known() ? 'fb' : 'php');
        if ($res === null) { $first = ''; $reqs = 0; $retried = []; }
    }
    if ($res === null) {
        if ($eng === 'fb' && $wait_until - microtime(true) < 2) $res = array_fill_keys(array_keys($nodes), false);
        else $res = sess_poll_php($nodes, $wait_until, $first, $reqs);
    }
    $poll_ms = (int) round((microtime(true) - $tp) * 1000);
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
    $st['eng'] = $eng;
    $st['retry'] = array_slice($retried, 0, 50);
    $cur = [];
    if ($seen) $st['err'] = sess_store($seen, $now, $cur);
    elseif ($first !== '' && $st['ok'] === 0) $st['err'] = $first;
    if ($eng === 'fb') $st['go_err'] = $gerr;
    // Схема аномалий — по тому же снимку; закрывать события по «пропавшим» можно только при полном опросе.
    if ($st['ok'] > 0 && $st['err'] === '') $st['an'] = sess_anom_eval($cur, $now, !$st['fail'], $deadline);
    sess_state_save($st, $t0);
    sess_plog_add(['ms' => $poll_ms] + $st, $reqs);
    admin_cache_put('sess_mx', []);
    if ($st['ok'] === 0) return 'ни одна нода не ответила' . ($first !== '' ? ': ' . $first : '');
    return $st['err'];
}

const SESS_PLOG_DAYS = 7;

// Журнал опросов: кто опрашивал (go / php / fb — PHP вместо Go), сколько нод ответило, за сколько и каким числом запросов.
function sess_plog_add(array $st, $reqs) {
    if (!($p = db())) return;
    $err = (string) ($st['err'] ?? '');
    if (($st['eng'] ?? '') === 'fb') $err = 'Go: ' . (string) ($st['go_err'] ?? '') . ($err !== '' ? ' · ' . $err : '');
    $fail = array_slice(array_map('strval', (array) ($st['fail'] ?? [])), 0, 50);
    $retry = array_slice(array_map('strval', (array) ($st['retry'] ?? [])), 0, 50);
    $jf = JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;
    try {
        $p->prepare('INSERT INTO sess_poll (ts, eng, nodes, ok, ms, reqs, conns, err, fail, retry) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
          ->execute([time(), (string) ($st['eng'] ?? 'php'), (int) ($st['nodes'] ?? 0), (int) ($st['ok'] ?? 0), (int) ($st['ms'] ?? 0), (int) $reqs, (int) ($st['rows'] ?? 0),
                     mb_substr($err, 0, 250), $fail ? json_encode($fail, $jf) : null, $retry ? json_encode($retry, $jf) : null]);
    } catch (Throwable $e) { error_log('submw sess_poll: ' . $e->getMessage()); }
}

function sess_plog_filter($f, &$args) {
    $args = [];
    switch ((string) $f) {
        case 'go':   return "eng = 'go'";
        case 'php':  return "eng = 'php'";
        case 'fb':   return "eng = 'fb'";
        case 'part': return "(ok < nodes OR nodes = 0 OR err <> '')";
        case 'rt':   return 'retry IS NOT NULL';
    }
    return '1 = 1';
}

// Сводка за $hours часов отдельно по Go и по PHP (PHP вместо Go входит в PHP). Опросы, не получившие списка нод,
// входят только в число опросов: до нод они не дошли, и время с запросами исказили бы сравнение.
function sess_plog_summary($hours) {
    $hours = max(1, min(SESS_PLOG_DAYS * 24, (int) $hours));
    $out = ['hours' => $hours, 'go' => null, 'php' => null, 'fb' => 0, 'fb_last' => null, 'rt' => 0];
    if (!sess_ensure() || !($p = db())) return $out;
    $since = time() - $hours * 3600;
    try {
        $st = $p->prepare("SELECT eng, COUNT(*) n, SUM(CASE WHEN nodes > 0 THEN 1 ELSE 0 END) np, SUM(CASE WHEN nodes > 0 AND ok = nodes THEN 1 ELSE 0 END) fl,
            AVG(CASE WHEN nodes > 0 THEN ms END) ms, MAX(CASE WHEN nodes > 0 THEN ms END) mx, AVG(CASE WHEN nodes > 0 THEN reqs END) rq,
            AVG(CASE WHEN nodes > 0 THEN conns END) cn FROM sess_poll WHERE ts >= ? GROUP BY eng");
        $st->execute([$since]);
        $by = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $by[(string) $r['eng']] = $r;
        $mk = static function (array $rows) {
            $rows = array_values(array_filter($rows));
            if (!$rows) return null;
            $n = 0; $np = 0; $full = 0; $ms = 0.0; $mx = 0; $rq = 0.0; $cn = 0.0;
            foreach ($rows as $r) {
                $k = (int) $r['n'];
                $w = (int) $r['np'];
                $n += $k; $np += $w; $full += (int) $r['fl']; $mx = max($mx, (int) $r['mx']);
                $ms += (float) $r['ms'] * $w; $rq += (float) $r['rq'] * $w; $cn += (float) $r['cn'] * $w;
            }
            return ['n' => $n, 'full' => $np > 0 ? round($full * 100 / $np, 1) : null, 'ms' => $np > 0 ? (int) round($ms / $np) : 0, 'mx' => $mx,
                    'reqs' => $np > 0 ? (int) round($rq / $np) : 0, 'conns' => $np > 0 ? (int) round($cn / $np) : 0];
        };
        $out['go'] = $mk([$by['go'] ?? null]);
        $out['php'] = $mk([$by['php'] ?? null, $by['fb'] ?? null]);
        $out['fb'] = (int) ($by['fb']['n'] ?? 0);
        $st = $p->prepare('SELECT COUNT(*) FROM sess_poll WHERE ts >= ? AND retry IS NOT NULL');
        $st->execute([$since]);
        $out['rt'] = (int) $st->fetchColumn();
        if ($out['fb'] > 0) {
            $st = $p->prepare("SELECT ts, err FROM sess_poll WHERE eng = 'fb' AND ts >= ? ORDER BY id DESC LIMIT 1");
            $st->execute([$since]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if ($r) $out['fb_last'] = ['ts' => (int) $r['ts'], 'err' => (string) $r['err']];
        }
    } catch (Throwable $e) { error_log('submw sess_poll summary: ' . $e->getMessage()); }
    return $out;
}

function sess_plog_counts() {
    $out = ['all' => 0, 'go' => 0, 'php' => 0, 'fb' => 0, 'part' => 0, 'rt' => 0];
    if (!sess_ensure() || !($p = db())) return $out;
    try {
        $r = $p->query("SELECT COUNT(*) a, SUM(CASE WHEN eng = 'go' THEN 1 ELSE 0 END) g, SUM(CASE WHEN eng = 'php' THEN 1 ELSE 0 END) p,
            SUM(CASE WHEN eng = 'fb' THEN 1 ELSE 0 END) f, SUM(CASE WHEN ok < nodes OR nodes = 0 OR err <> '' THEN 1 ELSE 0 END) x,
            SUM(CASE WHEN retry IS NOT NULL THEN 1 ELSE 0 END) r FROM sess_poll")->fetch(PDO::FETCH_ASSOC);
        $out = ['all' => (int) $r['a'], 'go' => (int) $r['g'], 'php' => (int) $r['p'], 'fb' => (int) $r['f'], 'part' => (int) $r['x'], 'rt' => (int) $r['r']];
    } catch (Throwable $e) {}
    return $out;
}

function sess_plog_rows($f, $offset, $limit) {
    if (!sess_ensure() || !($p = db())) return [];
    $where = sess_plog_filter($f, $args);
    try {
        $st = $p->prepare("SELECT id, ts, eng, nodes, ok, ms, reqs, conns, err, fail, retry FROM sess_poll WHERE $where ORDER BY ts DESC, id DESC LIMIT " . max(1, min(5000, (int) $limit)) . ' OFFSET ' . max(0, (int) $offset));
        $st->execute($args);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $fail = json_decode((string) ($r['fail'] ?? ''), true);
            $rt = json_decode((string) ($r['retry'] ?? ''), true);
            $out[] = ['ts' => (int) $r['ts'], 'eng' => (string) $r['eng'], 'nodes' => (int) $r['nodes'], 'ok' => (int) $r['ok'], 'ms' => (int) $r['ms'],
                      'reqs' => (int) $r['reqs'], 'conns' => (int) $r['conns'], 'err' => (string) $r['err'], 'fail' => is_array($fail) ? array_map('strval', $fail) : [],
                      'retry' => is_array($rt) ? array_map('strval', $rt) : []];
        }
        return $out;
    } catch (Throwable $e) { return []; }
}

// Опрос нод из PHP: задания по очереди, ответы по кругу.
function sess_poll_php(array $nodes, $wait_until, &$first = '', &$reqs = 0) {
    $P = sess_paths();
    $jobs = [];
    $flipped = false;
    foreach ($nodes as $uuid => $info) {
        // Не успели стартовать — это «не ответила», а не «опрошена»: иначе опрос считался бы полным.
        if (microtime(true) >= $wait_until) { $jobs[$uuid] = ''; continue; }
        $e = '';
        $jobs[$uuid] = sess_job_start($P['node']($uuid), $e);
        $reqs++;
        if ($e === 'нет такого адреса API' && !$flipped) {
            // Версия панели в кэше могла быть не той: пробуем второе семейство адресов один раз.
            $flipped = true;
            sess_api_flip();
            $P = sess_paths();
            $jobs[$uuid] = sess_job_start($P['node']($uuid), $e);
            $reqs++;
        }
        if ($e !== '' && $first === '') $first = $e;
    }
    return sess_jobs_wait($jobs, $P['node_res'], $wait_until, $reqs);
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
    // Гео ищется внутри транзакции: таблицу кэша создаём до неё.
    if (function_exists('geonet_ensure') && geoip_ipinfo()) geonet_ensure();
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
            $g = function_exists('geoip_lookup') ? geoip_lookup($ip, 1) : [];
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
    if (sess_ensure() && function_exists('gc_purge')) { gc_purge('conn_sess', sess_keep_days(), 5.0); gc_purge('sess_anom', sess_anom_keep_days(), 2.0); gc_purge('sess_poll', SESS_PLOG_DAYS, 2.0); }
}

function cron_task_sessions($ctx) {
    if (!sess_enabled()) return '';
    // Список лимитов обновляется до опроса и только при запасе времени; сам опрос берёт снимок как есть.
    // Go берёт весь список за один заход — ему нужен запас на весь обход, а не на порцию PHP-докачки.
    $left = $ctx['deadline'] - microtime(true);
    $slot = function_exists('go_users_ready') && go_users_ready() ? min(60, $left - 17) : min(5, $left - 12);
    if ($slot > 1 && sess_anom_rules()) sess_users_limits(1800, microtime(true) + $slot);
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
    $days = max(1, (int) ($days ?? sess_win_days()));
    // Список пользователей просит метрики всех сразу на каждую отрисовку — считаем раз на опрос (сброс в sess_collect).
    $cacheKey = $uids === null ? 'sess_mx' : '';
    if ($cacheKey !== '') {
        $c = admin_cache_get($cacheKey, sess_every() * 2 + 60);
        if (is_array($c) && is_array($c['m'] ?? null) && (int) ($c['d'] ?? 0) === $days) return $c['m'];
    }
    $from = $now - $days * 86400;
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
    if ($cacheKey !== '') admin_cache_put($cacheKey, ['d' => $days, 'm' => $out]);
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
        $city = function_exists('geonet_cities') ? geonet_cities(array_unique(array_column($out, 'ip'))) : [];
        if ($city) foreach ($out as &$o) if (isset($city[$o['ip']])) $o['city'] = $city[$o['ip']];
        unset($o);
        return $out;
    } catch (Throwable $e) { return []; }
}

function sess_user_data($uid, $short = '', $sq = null) {
    $uid = (int) $uid;
    $an = $uid > 0 ? sess_anom_user($uid) : [];
    $m = sess_metrics([$uid]);
    return [
        'on'    => sess_enabled(),
        'alive' => function_exists('cron_alive') ? cron_alive() : false,
        'every' => sess_every(),
        'gap'   => sess_gap(),
        'keep'  => sess_keep_days(),
        'win'   => sess_win_days(),
        'state' => sess_state(),
        'nodes' => sess_nodes_for(sess_nodes_fresh(), sess_sq_inb($sq)),
        'rows'  => $uid > 0 ? sess_user_rows($uid) : [],
        'm'     => $m[$uid] ?? null,
        'an'    => $an ? sess_anom_subips($an, (string) $short) : $an,
        'city'  => function_exists('geoip_city_ready') && geoip_city_ready(),
    ];
}

// Пересчёт страны, провайдера и региона в накопленных сессиях по текущим базам: гео пишется
// при появлении сессии, и после смены базы история и идущие сессии иначе остаются со старыми именами.
function sess_regeo() {
    if (!sess_ensure() || !($p = db()) || !function_exists('geoip_reader')) return 0;
    // С ipinfo гео дописывает geonet_apply по мере ответов.
    if (function_exists('geoip_ipinfo') && geoip_ipinfo()) return 0;
    if (geoip_reader('country') === null && geoip_reader('asn') === null) return 0;
    $n = 0;
    try {
        // Индекса по ip нет — обновляем по id пачками с коммитом на пачку: один длинный UPDATE на
        // группу держал бы SQLite под write-lock минуты, и запросы подписки не могли бы писать лог.
        // Строки читаются потоком, отсортированные по группе: гео считается раз на группу, id копятся пачкой.
        $st = $p->prepare('SELECT id, ip, cc, asn, org, sub FROM conn_sess WHERE last_ts >= ? ORDER BY ip, cc, asn, org, sub');
        $st->execute([time() - sess_keep_days() * 86400]);
        $batch = [];
        $flush = static function () use ($p, &$batch, &$n) {
            if (!$batch) return;
            $p->beginTransaction();
            try {
                foreach ($batch as [$vals, $ids]) {
                    foreach (array_chunk($ids, 500) as $chunk) {
                        $q = $p->prepare('UPDATE conn_sess SET cc = ?, asn = ?, org = ?, sub = ? WHERE id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')');
                        $q->execute(array_merge($vals, $chunk));
                        $n += $q->rowCount();
                    }
                }
                $p->commit();
            } catch (Throwable $e) {
                try { $p->rollBack(); } catch (Throwable $e2) {}
                throw $e;
            }
            $batch = [];
        };
        $rowsInBatch = 0;
        $gk = null; $vals = null; $ids = [];
        $take = static function () use (&$batch, &$rowsInBatch, &$vals, &$ids, $flush) {
            if ($vals !== null && $ids) { $batch[] = [$vals, $ids]; $rowsInBatch += count($ids); }
            $ids = [];
            if ($rowsInBatch >= 2000) { $flush(); $rowsInBatch = 0; }
        };
        while (($r = $st->fetch(PDO::FETCH_ASSOC)) !== false) {
            $k = $r['ip'] . '|' . $r['cc'] . '|' . $r['asn'] . '|' . $r['org'] . '|' . $r['sub'];
            if ($k !== $gk) {
                $take();
                $gk = $k;
                $g = sess_geo((string) $r['ip']);
                // Чего новая база не знает — не стирать: лучше старое значение, чем пустое.
                $cc = $g['cc'] !== '' ? $g['cc'] : (string) $r['cc'];
                $asn = $g['asn'] > 0 ? $g['asn'] : (int) $r['asn'];
                $org = $g['asn'] > 0 ? mb_substr($g['org'], 0, 128) : (string) $r['org'];
                $sub = $g['sub'] !== '' ? mb_substr($g['sub'], 0, 64) : (string) $r['sub'];
                $same = $cc === (string) $r['cc'] && $asn === (int) $r['asn'] && $org === (string) $r['org'] && $sub === (string) $r['sub'];
                $vals = $same ? null : [$cc, $asn, $org, $sub];
            }
            if ($vals !== null) $ids[] = (int) $r['id'];
        }
        $take();
        $flush();
    } catch (Throwable $e) {
        error_log('submw sess regeo: ' . $e->getMessage());
    }
    return $n;
}

function sess_geo($ip) {
    $g = function_exists('geoip_lookup') ? geoip_lookup($ip, 1) : [];
    return ['cc' => (string) ($g['cc'] ?? ''), 'asn' => (int) ($g['asn'] ?? 0), 'org' => (string) ($g['org'] ?? ''), 'sub' => (string) ($g['sub'] ?? ''), 'city' => (string) ($g['city'] ?? '')];
}

// Живой опрос нод по одному пользователю: ждём ответа до 8 секунд, частичный результат тоже отдаём.
function sess_live($ref, &$err = '', $sq = null) {
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
    $known = sess_nodes_for(sess_nodes_fresh(), sess_sq_inb($sq));
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
    $want = count($ips);
    $ips = array_values(array_filter($ips, 'sess_ip_ok'));
    if ($want > 0 && !$ips) { $err = 'Некорректный IP-адрес'; return false; }
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
    // Пока сбор выключен, пустую схему не записывать: при включении первый вызов подставит типовую.
    if ($raw === null) return sess_enabled() ? sess_anom_save(sess_anom_legacy()) : [];
    $rules = sess_anom_norm(json_decode((string) $raw, true));
    return $rules;
}

function sess_anom_save($raw) {
    $rules = sess_anom_norm($raw);
    set_setting('sess_anom', json_encode($rules, JSON_UNESCAPED_UNICODE));
    $GLOBALS['submw_anom_rules'] = $rules;
    return $rules;
}

function sess_limits_from($list) {
    $part = [];
    foreach ((array) $list as $x) {
        if (!is_array($x) || !isset($x['id'])) continue;
        $hw = (isset($x['hwidDeviceLimit']) && $x['hwidDeviceLimit'] !== '' && $x['hwidDeviceLimit'] !== null) ? (int) $x['hwidDeviceLimit'] : null;
        $part[(string) (int) $x['id']] = [$hw, (string) ($x['externalSquadUuid'] ?? '')];
    }
    return $part;
}

// Лимиты устройств пользователей из панели: id → [свой лимит | null «как в панели», внешний сквад].
// Это весь список пользователей постранично, поэтому кэш полчаса; если общий снимок пользователей
// моложе — лимиты из него. Иначе докачка по страницам до $deadline с продолжением в следующем тике;
// пока список не собран целиком, отдаётся прежний.
function sess_users_limits($maxAge = 1800, $deadline = null) {
    $src = md5(remnawave_url() . "\n" . remnawave_token());
    $o = admin_cache_get('sess_users', PHP_INT_MAX);
    if (!is_array($o) || ($o['src'] ?? '') !== $src) $o = ['src' => $src, 'u' => null, 'ts0' => 0];
    $stale = is_array($o['u'] ?? null) ? $o['u'] : [];
    // Возраст — по ts0 собранного снимка, а не по записи в кэше: частичный прогресс его не продлевает.
    if (is_array($o['u'] ?? null) && time() - (int) ($o['ts0'] ?? 0) < $maxAge) return $stale;
    // Общий снимок пользователей моложе нашего и не старше maxAge — лимиты из него, панель не трогаем.
    $snap = function_exists('panel_users_ts');
    $pts = $snap ? panel_users_ts() : 0;
    if ($pts > (int) ($o['ts0'] ?? 0) && time() - $pts < $maxAge) {
        $part = sess_limits_from(panel_users_cached());
        admin_cache_put('sess_users', ['src' => $src, 'u' => $part, 'ts0' => $pts]);
        return $part;
    }
    // Снимка ещё не было — собрать сразу, иначе до первого свободного тика все пороги шли бы по общему лимиту.
    if ($deadline === null && !is_array($o['u'] ?? null)) $deadline = microtime(true) + 8;
    if ($deadline === null || remnawave_url() === '' || remnawave_token() === '') return $stale;
    // Через Go — весь список за один заход, без докачки по тикам.
    if (function_exists('go_users_list')) {
        $go_try = function_exists('go_users_ready') && go_users_ready();
        $t0 = time();
        $gl = go_users_list($snap ? PANEL_USERS_FIELDS : ['id', 'hwidDeviceLimit', 'externalSquadUuid'], $ge, max(2, $deadline - microtime(true)));
        if ($gl !== null) {
            $part = sess_limits_from($gl);
            if ($snap) panel_users_feed($gl, $t0);
            admin_cache_put('sess_users', ['src' => $src, 'u' => $part, 'ts0' => $t0]);
            return $part;
        }
        // Запас был рассчитан на Go; PHP докачивает обычной порцией, чтобы не задерживать опрос нод.
        if ($go_try) $deadline = min($deadline, microtime(true) + 5);
    }
    $part = is_array($o['part'] ?? null) ? $o['part'] : [];
    $rows = is_array($o['rows'] ?? null) ? $o['rows'] : [];
    $start = (int) ($o['next'] ?? 0);
    // Возраст списка, собранного за несколько тиков, — от первой страницы.
    $t0 = $start > 0 && (int) ($o['t0'] ?? 0) > 0 ? (int) $o['t0'] : time();
    $size = 250;
    $done = false;
    while (microtime(true) < $deadline) {
        [$ok, , $data, ] = remnawave_api_get("/api/users?size={$size}&start={$start}");
        if (!$ok) break;
        $resp = is_array($data) ? ($data['response'] ?? $data) : [];
        $users = is_array($resp) ? ($resp['users'] ?? $resp) : [];
        if (!is_array($users)) $users = [];
        $total = (int) ($resp['total'] ?? count($users));
        $part = sess_limits_from($users) + $part;
        if ($snap) foreach ($users as $x) if (($r = panel_users_row($x)) !== null) $rows[] = $r;
        $start += $size;
        if (!$users || $start >= $total || $start > 1000000) { $done = true; break; }
    }
    if ($done) {
        if ($snap) panel_users_feed($rows, $t0);
        admin_cache_put('sess_users', ['src' => $src, 'u' => $part, 'ts0' => $t0]);
        return $part;
    }
    admin_cache_put('sess_users', ['src' => $src, 'u' => is_array($o['u'] ?? null) ? $o['u'] : null, 'ts0' => (int) ($o['ts0'] ?? 0), 'part' => $part, 'rows' => $rows, 'next' => $start, 't0' => $t0]);
    return $stale;
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
    $close = [];
    try {
        $st = $p->query('SELECT id, uid, gid, ips FROM sess_anom WHERE end_ts = 0 ORDER BY id');
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $k = (int) $r['uid'] . '|' . $r['gid'];
            // Дубль открытого события (пересчёт шёл параллельно с опросом) — лишний закрывается, остаётся первый.
            if (isset($open[$k])) { $close[] = (int) $r['id']; continue; }
            $open[$k] = $r;
        }
    } catch (Throwable $e) { return 'read'; }
    $ins = []; $upd = [];
    if ($rules) {
        $nolim = sess_nolim();
        $limits = $cur ? sess_users_limits(1800, null) : [];
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
// Возвращает false, если сейчас идёт тик планировщика: тогда новую схему применит сам опрос.
function sess_anom_recalc() {
    $st = sess_state();
    $ts = (int) ($st['ts'] ?? 0);
    if ($ts <= 0 || !sess_ensure() || !($p = db())) return true;
    $lock = function_exists('cron_lock_path') ? @fopen(cron_lock_path(), 'c') : false;
    if ($lock && !flock($lock, LOCK_EX | LOCK_NB)) { fclose($lock); return false; }
    try { sess_anom_recalc_do($p, $st, $ts); }
    finally { if ($lock) { flock($lock, LOCK_UN); fclose($lock); } }
    return true;
}

function sess_anom_recalc_do($p, array $st, $ts) {
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

// Закрытые события журнала для среза в списке: uid → {b, w — когда последний раз закрылась красная/жёлтая; t — что было в последней}.
function sess_anom_hist_map() {
    $out = [];
    if (!sess_ensure() || !($p = db())) return $out;
    $names = [];
    foreach (sess_anom_rules() as $g) $names[$g['id']] = $g['name'];
    try {
        // По одному последнему закрытому событию каждого цвета у пользователя — подсказка у значка берёт текст события того же цвета.
        $st = $p->prepare('SELECT a.uid, a.gid, a.lvl, a.end_ts, a.why FROM sess_anom a JOIN (SELECT uid, lvl, MAX(end_ts) m FROM sess_anom WHERE last_ts >= ? AND end_ts > 0 GROUP BY uid, lvl) b ON a.uid = b.uid AND a.lvl = b.lvl AND a.end_ts = b.m');
        $st->execute([time() - sess_anom_keep_days() * 86400]);
        foreach ($st as $r) {
            $u = (string) (int) $r['uid'];
            $k = $r['lvl'] === 'bad' ? 'b' : 'w';
            if (!isset($out[$u])) $out[$u] = ['b' => 0, 'w' => 0, 'tb' => '', 'tw' => ''];
            $out[$u][$k] = (int) $r['end_ts'];
            $out[$u]['t' . $k] = ($names[$r['gid']] ?? $r['gid']) . ': ' . str_replace(' сейчас ', ' ', sess_anom_why_text(json_decode((string) $r['why'], true)));
        }
    } catch (Throwable $e) {}
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

// Совпадения IP аномалии с IP запросов подписки этого пользователя: за сутки до начала и пока аномалия шла.
function sess_anom_subips(array $ev, $short) {
    if ($short === '' || !($p = db())) return $ev;
    $from = PHP_INT_MAX; $to = 0; $now = time();
    $ips = [];
    foreach ($ev as $e) {
        $from = min($from, $e['s'] - 86400); $to = max($to, $e['e'] ?: $now);
        foreach ($e['ips'] as $x) if (($x['ip'] ?? '') !== '') $ips[(string) $x['ip']] = 1;
    }
    if (!$ips) return $ev;
    $ips = array_slice(array_keys($ips), 0, 500);
    $by = [];
    try {
        $ep = sql_epoch('ts');
        $st = $p->prepare("SELECT ip, user_agent, meta, $ep AS e FROM request_log WHERE short_uuid = ? AND ip IN (" . implode(',', array_fill(0, count($ips), '?')) . ") AND decision <> 'browser' AND $ep BETWEEN ? AND ? ORDER BY id DESC LIMIT 20000");
        $st->execute(array_merge([$short], $ips, [$from, $to]));
        foreach ($st as $r) if ((string) $r['ip'] !== '') $by[(string) $r['ip']][] = $r;
    } catch (Throwable $e) { return $ev; }
    if (!$by) return $ev;
    foreach ($ev as &$e) {
        $a = $e['s'] - 86400; $b = $e['e'] ?: $now;
        foreach ($e['ips'] as &$x) {
            $n = 0; $last = null;
            foreach ($by[$x['ip'] ?? ''] ?? [] as $r) {
                $t = (int) $r['e'];
                if ($t < $a || $t > $b) continue;
                $n++;
                if ($last === null || $t > (int) $last['e']) $last = $r;
            }
            if (!$n) continue;
            $c = function_exists('reqlog_client') ? reqlog_client($last['user_agent'] ?? '', reqlog_meta($last)['dv'] ?? null) : ['app' => '', 'os' => ''];
            $x['rq'] = ['n' => $n, 't' => (int) $last['e'], 'app' => trim($c['app'] . ' ' . $c['os'])];
        }
        unset($x);
    }
    unset($e);
    return $ev;
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
