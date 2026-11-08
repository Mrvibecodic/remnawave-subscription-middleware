<?php

// Значения панели. С REALITY рабочие только chrome, firefox и safari (так и в тестах Xray: TestVlessRealityFingerprints)
// и randomized; random Xray выбирает из набора с ios, edge, 360 и qq — с REALITY он ломается через раз.
function hostov_fp_values($sec = null) {
    if ($sec === 'reality') return ['chrome', 'firefox', 'safari', 'randomized'];
    return ['chrome', 'firefox', 'safari', 'ios', 'android', 'edge', '360', 'qq', 'random', 'randomized'];
}

function hostov_fp_ok(array $r) {
    return in_array((string) ($r['val'] ?? ''), hostov_fp_values((string) ($r['host_sec'] ?? '')), true);
}

// Подмена, которая столько не уходила в приложение при пропусках хоста, считается недействующей.
const HOSTOV_STALE = 7 * 86400;

function hostov_ddl($drv) {
    if ($drv === 'mysql') {
        return ["CREATE TABLE IF NOT EXISTS host_ovr (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            short_uuid VARCHAR(64) NOT NULL, user_id BIGINT NULL, username VARCHAR(64) NOT NULL DEFAULT '',
            host_uuid VARCHAR(64) NOT NULL, host_name VARCHAR(255) NOT NULL DEFAULT '', host_rmk VARCHAR(255) NOT NULL DEFAULT '',
            host_addr VARCHAR(255) NOT NULL DEFAULT '', host_apat VARCHAR(255) NOT NULL DEFAULT '', host_port INT NOT NULL DEFAULT 0,
            host_sec VARCHAR(16) NOT NULL DEFAULT '', host_tr VARCHAR(16) NOT NULL DEFAULT '',
            host_sni VARCHAR(255) NOT NULL DEFAULT '', host_snipat VARCHAR(255) NOT NULL DEFAULT '',
            param VARCHAR(16) NOT NULL, pv VARCHAR(64) NOT NULL DEFAULT '', val VARCHAR(255) NOT NULL,
            till INT UNSIGNED NULL, created_at INT UNSIGNED NOT NULL, vat INT UNSIGNED NOT NULL,
            last_hit INT UNSIGNED NULL, last_app VARCHAR(128) NOT NULL DEFAULT '', last_miss INT UNSIGNED NULL, miss_app VARCHAR(128) NOT NULL DEFAULT '',
            UNIQUE KEY uq_host_ovr (short_uuid, host_uuid, param),
            KEY idx_host_ovr_uid (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"];
    }
    return ["CREATE TABLE IF NOT EXISTS host_ovr (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        short_uuid TEXT NOT NULL, user_id INTEGER NULL, username TEXT NOT NULL DEFAULT '',
        host_uuid TEXT NOT NULL, host_name TEXT NOT NULL DEFAULT '', host_rmk TEXT NOT NULL DEFAULT '',
        host_addr TEXT NOT NULL DEFAULT '', host_apat TEXT NOT NULL DEFAULT '', host_port INTEGER NOT NULL DEFAULT 0,
        host_sec TEXT NOT NULL DEFAULT '', host_tr TEXT NOT NULL DEFAULT '',
        host_sni TEXT NOT NULL DEFAULT '', host_snipat TEXT NOT NULL DEFAULT '',
        param TEXT NOT NULL, pv TEXT NOT NULL DEFAULT '', val TEXT NOT NULL,
        till INTEGER NULL, created_at INTEGER NOT NULL, vat INTEGER NOT NULL,
        last_hit INTEGER NULL, last_app TEXT NOT NULL DEFAULT '', last_miss INTEGER NULL, miss_app TEXT NOT NULL DEFAULT '',
        UNIQUE (short_uuid, host_uuid, param)
    )", 'CREATE INDEX IF NOT EXISTS idx_host_ovr_uid ON host_ovr (user_id)'];
}

// Колонки, которых не было в первой версии таблицы.
function hostov_cols_v2() {
    return ['host_apat' => 255, 'host_sni' => 255, 'host_snipat' => 255, 'miss_app' => 128];
}

function hostov_ensure() {
    static $ok = null;
    if ($ok !== null) return $ok;
    if (!function_exists('db') || !($p = db())) return false;
    if (setting('hostov_table', '') === '2') return $ok = true;
    try {
        $drv = db_driver();
        foreach (hostov_ddl($drv) as $sql) {
            try { $p->exec($sql); } catch (Throwable $e) { if (stripos($sql, 'CREATE TABLE') === 0) throw $e; }
        }
        foreach (hostov_cols_v2() as $col => $len) {
            try { $p->query('SELECT ' . $col . ' FROM host_ovr LIMIT 1'); continue; } catch (Throwable $e) {}
            $p->exec('ALTER TABLE host_ovr ADD COLUMN ' . $col . ($drv === 'mysql' ? ' VARCHAR(' . $len . ')' : ' TEXT') . " NOT NULL DEFAULT ''");
        }
        $p->query('SELECT id, host_apat, host_sni, host_snipat, miss_app FROM host_ovr LIMIT 1');
        set_setting('hostov_table', '2');
        $ok = true;
    } catch (Throwable $e) { error_log('submw host_ovr table: ' . $e->getMessage()); $ok = false; }
    return $ok;
}

// Число действующих подмен в настройках: без них путь подписки не ходит в таблицу.
function hostov_recount() {
    $n = 0;
    try {
        if (hostov_ensure()) {
            $st = db()->prepare('SELECT COUNT(*) FROM host_ovr WHERE till IS NULL OR till > ?');
            $st->execute([time()]);
            $n = (int) $st->fetchColumn();
        }
    } catch (Throwable $e) {}
    set_setting('hostov_n', (string) $n);
    return $n;
}

// Планировщик: подмены, у которых срок вышел больше недели назад, удаляются.
function hostov_prune() {
    if (!hostov_any() && (string) setting('hostov_table', '') === '') return;
    if (!hostov_ensure()) return;
    try { db()->prepare('DELETE FROM host_ovr WHERE till IS NOT NULL AND till <= ?')->execute([time() - HOSTOV_STALE]); } catch (Throwable $e) {}
    hostov_recount();
}

function hostov_any() {
    return (int) setting('hostov_n', '0') > 0;
}

function hostov_rows_for($short) {
    static $memo = [];
    $short = (string) $short;
    if ($short === '' || !hostov_any()) return [];
    if (isset($memo[$short])) return $memo[$short];
    $out = [];
    try {
        if (hostov_ensure()) {
            $st = db()->prepare('SELECT * FROM host_ovr WHERE short_uuid = ? AND (till IS NULL OR till > ?) ORDER BY id');
            $st->execute([$short, time()]);
            // Значение, которое этому хосту не годится, в выдачу не идёт и отметок «ушла / не было» не получает.
            $out = array_values(array_filter($st->fetchAll(PDO::FETCH_ASSOC), static fn($r) => ($r['param'] ?? '') !== 'fp' || hostov_fp_ok($r)));
        }
    } catch (Throwable $e) { error_log('submw host_ovr read: ' . $e->getMessage()); }
    return $memo[$short] = $out;
}

// ---- сопоставление узлов выдачи с хостами панели ----

// Имя хоста в панели — шаблон: {{…}} подставляется значением пользователя, к повтору имени панель дописывает « ^~N~^».
function hostov_name_rx($tpl) {
    $tpl = (string) $tpl;
    if ($tpl === '' || strpos($tpl, 'rwEncodeBase64:') === 0) return null;
    $parts = preg_split('~\{\{\w+(?::[^{}]*)?\}\}~u', $tpl);
    if ($parts === false) return null;
    $rx = implode('.*?', array_map(static fn($p) => preg_quote($p, '/'), $parts));
    return '/^' . $rx . '(?: \^~\d+~\^)?$/us';
}

// Адрес (и SNI) хоста в панели — шаблон: «a,b,c» — один из списка, «*» — 10 случайных символов nanoid панели (0-9, a-z без i и l).
function hostov_pat_ok($v, $pat) {
    $v = trim((string) $v); $pat = trim((string) $pat);
    if ($v === '' || $pat === '') return false;
    if (strpos($pat, ',') !== false) {
        foreach (explode(',', $pat) as $one) if (strcasecmp(trim($one), $v) === 0) return true;
        return false;
    }
    $star = strpos($pat, '*');
    if ($star !== false) {
        $rx = '/^' . preg_quote(substr($pat, 0, $star), '/') . '[0-9a-hjkm-z]{10}' . preg_quote(substr($pat, $star + 1), '/') . '$/i';
        return (bool) preg_match($rx, $v);
    }
    return strcasecmp($pat, $v) === 0;
}

function hostov_names_ok(array $names, array $row) {
    $name = (string) ($row['host_name'] ?? '');
    $rx = hostov_name_rx($row['host_rmk'] ?? '');
    foreach ($names as $n) {
        $n = (string) $n;
        if ($n === '') continue;
        if ($n === $name || ($rx !== null && @preg_match($rx, $n))) return true;
    }
    return false;
}

function hostov_tr_norm($t) {
    $t = strtolower((string) $t);
    $m = ['raw' => 'tcp', 'http' => 'tcp', 'splithttp' => 'xhttp', 'mkcp' => 'kcp', 'hysteria2' => 'hysteria'];
    return $m[$t] ?? $t;
}

function hostov_tr_ok(array $nd, array $row) {
    $a = hostov_tr_norm($row['host_tr'] ?? '');
    $b = hostov_tr_norm($nd['tr'] ?? '');
    return $a === '' || $b === '' || $a === $b;
}

// SNI хоста: шаблон из панели, иначе тот, что был при сохранении, — если он не взят из адреса (тот бывает случайным).
function hostov_sni_ok(array $nd, array $row) {
    $pat = (string) ($row['host_snipat'] ?? '');
    $sni = (string) ($row['host_sni'] ?? '');
    $v = (string) ($nd['sni'] ?? '');
    if ($pat !== '') return hostov_pat_ok($v, $pat);
    if ($sni === '' || strcasecmp($sni, (string) ($row['host_addr'] ?? '')) === 0) return true;
    return strcasecmp($v, $sni) === 0;
}

// Сужение одинаковых по адресу узлов: транспорт, SNI, точное имя. Шаг, после которого никого не осталось, пропускается.
function hostov_narrow(array $c, array $nodes, array $row) {
    $name = (string) ($row['host_name'] ?? '');
    $steps = [
        static fn($i) => hostov_tr_ok($nodes[$i], $row),
        static fn($i) => hostov_sni_ok($nodes[$i], $row),
        $name === '' ? null : static fn($i) => in_array($name, (array) $nodes[$i]['names'], true),
    ];
    foreach ($steps as $f) {
        if ($f === null || count($c) < 2) continue;
        $x = array_values(array_filter($c, $f));
        if ($x) $c = $x;
    }
    return $c;
}

// Узел: names — имена, под которыми он виден; named — имя точно от хоста (ссылки, mihomo, sing-box), а не тег шаблона;
// a/p — адрес и порт; sec — tls|reality|none; tr — транспорт; sni; g — группа (конфиг xray-json), внутри которой узлы сужаются.
// Хост узнаётся по порту, шаблону адреса и типу защиты. Узел с именем хоста должен подходить и по имени;
// узел без него (тег xray-json) — по транспорту и SNI.
function hostov_assign(array $nodes, array $rows) {
    $plan = [];
    foreach ($rows as $ri => $row) {
        $port = (int) ($row['host_port'] ?? 0);
        $pat = (string) ($row['host_apat'] ?? '') !== '' ? (string) $row['host_apat'] : (string) ($row['host_addr'] ?? '');
        $sec = (string) ($row['host_sec'] ?? '');
        $g = [];
        foreach ($nodes as $i => $nd) {
            if ((int) $nd['p'] !== $port || !hostov_pat_ok($nd['a'], $pat)) continue;
            if ($sec !== '' && (string) $nd['sec'] !== $sec) continue;
            $g[] = $i;
        }
        if (!$g) continue;
        $c = []; $strong = false;
        foreach ($g as $i) {
            if (!empty($nodes[$i]['named'])) {
                if (hostov_names_ok((array) $nodes[$i]['names'], $row)) { $c[] = $i; $strong = true; }
            } elseif (hostov_tr_ok($nodes[$i], $row) && hostov_sni_ok($nodes[$i], $row)) {
                $c[] = $i;
            }
        }
        if (!$c) continue;
        $by = [];
        foreach ($c as $i) $by[(string) ($nodes[$i]['g'] ?? '')][] = $i;
        $fin = [];
        foreach ($by as $grp) foreach (count($grp) > 1 ? hostov_narrow($grp, $nodes, $row) : $grp as $i) $fin[] = $i;
        $plan[] = [$strong ? 0 : 1, $ri, $fin];
    }
    usort($plan, static fn($a, $b) => ($a[0] <=> $b[0]) ?: ($a[1] <=> $b[1]));
    $out = [];
    foreach ($plan as [, $ri, $fin]) foreach ($fin as $i) if (!isset($out[$i])) $out[$i] = $rows[$ri];
    return $out;
}

// ---- подмена в теле ----

// Возвращает [тело, попадания]; попадание — [id подмены, имя узла, было, стало]. Ошибка разбора — тело как было.
function hostov_apply($body, $format, array $rows) {
    $rows = array_values(array_filter($rows, static fn($r) => ($r['param'] ?? '') === 'fp' && hostov_fp_ok($r)));
    if (!$rows || !is_string($body) || $body === '') return [$body, []];
    try {
        $kind = function_exists('squadconf_body_kind') ? squadconf_body_kind($body, $format) : ($format === 'clash' ? 'clash' : 'links');
        if ($kind === 'clash') return hostov_apply_clash($body, $rows);
        if ($kind === 'links') return hostov_apply_links($body, $rows);
        if ($kind === 'singbox') return hostov_apply_singbox($body, $rows);
        return hostov_apply_xray($body, $rows);
    } catch (Throwable $e) {
        error_log('submw host_ovr apply: ' . $e->getMessage());
        return [$body, []];
    }
}

function hostov_json_flags($body) {
    return JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | (strpos((string) $body, "\n") !== false ? JSON_PRETTY_PRINT : 0);
}

function hostov_hostport($auth) {
    $auth = (string) $auth;
    if ($auth !== '' && $auth[0] === '[') {
        $e = strpos($auth, ']');
        if ($e === false) return [$auth, 0];
        return [substr($auth, 1, $e - 1), (int) ltrim(substr($auth, $e + 1), ':')];
    }
    $c = strrpos($auth, ':');
    return $c === false ? [$auth, 0] : [substr($auth, 0, $c), (int) substr($auth, $c + 1)];
}

function hostov_dl_fp($dl, $sec, $v) {
    if (!is_object($dl)) return false;
    $k = $sec === 'reality' ? 'realitySettings' : 'tlsSettings';
    if (!isset($dl->$k) || !is_object($dl->$k) || !property_exists($dl->$k, 'fingerprint')) return false;
    $dl->$k->fingerprint = $v;
    return true;
}

// Имя — фрагмент до «?»: расширенным клиентам панель дописывает «?serverDescription=…» после имени,
// а «?» внутри самого имени она кодирует как %3F.
function hostov_link_parse($ln) {
    if (!preg_match('~^(vless|trojan|hysteria2|hy2|ss)://~i', $ln, $sm)) return null;
    $scheme = strtolower($sm[1]);
    $hp = strpos($ln, '#');
    $main = $hp === false ? $ln : substr($ln, 0, $hp);
    $qp = strpos($main, '?');
    $q = $qp === false ? '' : substr($main, $qp + 1);
    $rest = substr($qp === false ? $main : substr($main, 0, $qp), strlen($sm[0]));
    $at = strrpos($rest, '@');
    [$a, $p] = hostov_hostport(rtrim($at === false ? $rest : substr($rest, $at + 1), '/'));
    $prm = [];
    foreach ($q === '' ? [] : explode('&', $q) as $kv) {
        $e = strpos($kv, '=');
        if ($e !== false) $prm[substr($kv, 0, $e)] = rawurldecode(substr($kv, $e + 1));
    }
    $sec = $scheme === 'trojan' ? 'tls' : 'none';
    if (isset($prm['security'])) $sec = strtolower($prm['security']);
    $frag = $hp === false ? '' : substr($ln, $hp + 1);
    $fq = strpos($frag, '?');
    return ['scheme' => $scheme, 'n' => trim(rawurldecode($fq === false ? $frag : substr($frag, 0, $fq))), 'a' => $a, 'p' => $p, 'sec' => $sec,
            'fp' => (string) ($prm['fp'] ?? ''), 'sni' => (string) ($prm['sni'] ?? ''), 'type' => strtolower((string) ($prm['type'] ?? 'tcp')),
            'main' => $main, 'q' => $q, 'qp' => $qp, 'frag' => $hp === false ? '' : substr($ln, $hp)];
}

function hostov_apply_links($body, array $rows) {
    $t = trim($body);
    $b64 = false;
    $text = $body;
    if (strpos($t, '://') === false) {
        $d = base64_decode(preg_replace('~\s+~', '', $t), true);
        if (!is_string($d) || strpos($d, '://') === false || !mb_check_encoding($d, 'UTF-8')) return [$body, []];
        $text = $d;
        $b64 = true;
    }
    $lines = explode("\n", $text);
    $nodes = []; $info = [];
    foreach ($lines as $li => $line) {
        $ln = rtrim($line, "\r");
        $x = hostov_link_parse($ln);
        if ($x === null || ($x['scheme'] !== 'vless' && $x['scheme'] !== 'trojan')) continue;
        $nodes[] = ['names' => [$x['n']], 'named' => true, 'a' => $x['a'], 'p' => $x['p'], 'sec' => $x['sec'], 'tr' => $x['type'], 'sni' => $x['sni'], 'g' => ''];
        $info[] = ['li' => $li, 'cr' => $ln !== $line] + $x;
    }
    $hits = [];
    foreach (hostov_assign($nodes, $rows) as $i => $row) {
        $x = $info[$i];
        if ($x['sec'] !== 'tls' && $x['sec'] !== 'reality') continue;
        $v = (string) $row['val'];
        $old = '';
        $parts = $x['q'] === '' ? [] : explode('&', $x['q']);
        $set = false;
        foreach ($parts as $k => $kv) {
            $e = strpos($kv, '=');
            $key = $e === false ? $kv : substr($kv, 0, $e);
            if ($key === 'fp') { $old = rawurldecode((string) substr($kv, $e + 1)); $parts[$k] = 'fp=' . rawurlencode($v); $set = true; }
            elseif ($key === 'extra' && $e !== false) {
                $ex = json_decode(rawurldecode(substr($kv, $e + 1)));
                if (is_object($ex) && isset($ex->downloadSettings) && hostov_dl_fp($ex->downloadSettings, (string) ($ex->downloadSettings->security ?? $x['sec']), $v)) {
                    $parts[$k] = 'extra=' . rawurlencode(json_encode($ex, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                }
            }
        }
        if (!$set) $parts[] = 'fp=' . rawurlencode($v);
        $base = $x['qp'] === false ? $x['main'] : substr($x['main'], 0, $x['qp']);
        $lines[$x['li']] = $base . '?' . implode('&', $parts) . $x['frag'] . ($x['cr'] ? "\r" : '');
        $hits[] = [(int) $row['id'], $x['n'], $old, $v];
    }
    if (!$hits) return [$body, []];
    $out = implode("\n", $lines);
    return [$b64 ? base64_encode($out) : $out, $hits];
}

function hostov_yaml_scalar($s) {
    $s = trim((string) $s);
    if ($s === '') return '';
    if ($s[0] === "'") {
        $e = strrpos($s, "'");
        return str_replace("''", "'", substr($s, 1, $e > 0 ? $e - 1 : strlen($s) - 1));
    }
    if ($s[0] === '"') {
        $e = strrpos($s, '"');
        $in = substr($s, 1, $e > 0 ? $e - 1 : strlen($s) - 1);
        $j = json_decode('"' . $in . '"');
        return is_string($j) ? $j : stripcslashes($in);
    }
    $c = strpos($s, ' #');
    return rtrim($c === false ? $s : substr($s, 0, $c));
}

function hostov_yaml_val($v) {
    return preg_match('~^[0-9]~', (string) $v) ? '"' . $v . '"' : (string) $v;
}

// Списки узлов в YAML: «proxies:» верхнего уровня и «payload:» встроенных провайдеров в «proxy-providers:»
// (панель кладёт туда узлы, если у провайдера в шаблоне include-proxies). Возвращает [первая строка, отступ ключа].
function hostov_clash_lists(array $lines) {
    $out = []; $prov = false;
    foreach ($lines as $i => $ln) {
        if ($ln !== '' && $ln[0] !== ' ' && $ln[0] !== '#') $prov = (bool) preg_match('~^proxy-providers:\s*$~', $ln);
        if (preg_match('~^proxies:\s*$~', $ln)) $out[] = [$i + 1, 0];
        elseif ($prov && preg_match('~^(\s+)payload:\s*$~', $ln, $m)) $out[] = [$i + 1, strlen($m[1])];
    }
    return $out;
}

function hostov_apply_clash($body, array $rows) {
    $eol = strpos($body, "\r\n") !== false ? "\r\n" : "\n";
    $lines = explode("\n", str_replace("\r\n", "\n", $body));
    $items = [];
    foreach (hostov_clash_lists($lines) as [$start, $keyInd]) {
        $lit = null; $cur = null;
        for ($i = $start; $i < count($lines); $i++) {
            $ln = $lines[$i];
            if (trim($ln) === '' || preg_match('~^\s*#~', $ln)) continue;
            $ind = strlen($ln) - strlen(ltrim($ln));
            $dash = (bool) preg_match('~^\s*-(\s|$)~', $ln);
            if ($lit === null) {
                if (!$dash || $ind < $keyInd) break;
                $lit = $ind;
            }
            if ($dash && $ind === $lit) { if ($cur !== null) $items[] = $cur; $cur = [$i, $i + 1]; continue; }
            if ($ind <= $lit) break;
            $cur[1] = $i + 1;
        }
        if ($cur !== null) $items[] = $cur;
    }
    if (!$items) return [$body, []];

    $nodes = []; $meta = [];
    $none = ['names' => [], 'named' => true, 'a' => '', 'p' => 0, 'sec' => 'none', 'tr' => '', 'sni' => '', 'g' => ''];
    foreach ($items as [$s, $e]) {
        $first = $lines[$s];
        $ki = strlen($first) - strlen(ltrim($first)) + 2;
        $rest = ltrim(substr(ltrim($first), 1));
        if ($rest !== '' && $rest[0] === '{') {
            if (substr(rtrim($rest), -1) !== '}' || $e - $s > 1) { $nodes[] = $none; $meta[] = null; continue; }
            $get = static function ($k) use ($rest) {
                return preg_match('~(?:^|[{,]\s*)' . preg_quote($k, '~') . ':\s*("(?:[^"\\\\]|\\\\.)*"|\'(?:[^\']|\'\')*\'|[^,}]+)~', $rest, $m) ? hostov_yaml_scalar($m[1]) : null;
            };
            $type = strtolower((string) $get('type'));
            $sec = strpos($rest, 'reality-opts') !== false ? 'reality' : ($type === 'trojan' || strtolower((string) $get('tls')) === 'true' ? 'tls' : 'none');
            $net = strtolower((string) $get('network'));
            if ($net === 'ws' && preg_match('~v2ray-http-upgrade:\s*true~', $rest)) $net = 'httpupgrade';
            $nodes[] = ['names' => [(string) $get('name')], 'named' => true, 'a' => (string) $get('server'), 'p' => (int) $get('port'), 'sec' => $sec,
                        'tr' => $net === '' ? 'tcp' : $net, 'sni' => (string) ($get('servername') ?? $get('sni') ?? ''), 'g' => ''];
            $meta[] = ['flow' => true, 's' => $s, 'tls' => $sec !== 'none' && in_array($type, ['vless', 'vmess', 'trojan'], true)];
            continue;
        }
        $kv = []; $fpl = null; $typel = $s; $nested = []; $hu = false;
        for ($i = $s; $i < $e; $i++) {
            $ln = $i === $s ? str_repeat(' ', $ki) . $rest : $lines[$i];
            if (!preg_match('~^(\s*)([A-Za-z0-9_-]+):\s*(.*)$~', $ln, $m)) continue;
            if (strlen($m[1]) === $ki) {
                $kv[$m[2]] = $m[3];
                if ($m[2] === 'client-fingerprint') $fpl = $i;
                if ($m[2] === 'type') $typel = $i;
            } elseif ($m[2] === 'client-fingerprint' && strlen($m[1]) > $ki) {
                $nested[] = $i;
            } elseif ($m[2] === 'v2ray-http-upgrade' && strtolower(hostov_yaml_scalar($m[3])) === 'true') {
                $hu = true;
            }
        }
        $type = strtolower(hostov_yaml_scalar($kv['type'] ?? ''));
        $sec = array_key_exists('reality-opts', $kv) ? 'reality' : ($type === 'trojan' || strtolower(hostov_yaml_scalar($kv['tls'] ?? '')) === 'true' ? 'tls' : 'none');
        $net = strtolower(hostov_yaml_scalar($kv['network'] ?? ''));
        if ($net === 'ws' && $hu) $net = 'httpupgrade';
        $sni = hostov_yaml_scalar($kv['servername'] ?? ($kv['sni'] ?? ''));
        $nodes[] = ['names' => [hostov_yaml_scalar($kv['name'] ?? '')], 'named' => true, 'a' => hostov_yaml_scalar($kv['server'] ?? ''), 'p' => (int) hostov_yaml_scalar($kv['port'] ?? '0'),
                    'sec' => $sec, 'tr' => $net === '' ? 'tcp' : $net, 'sni' => $sni, 'g' => ''];
        $meta[] = ['flow' => false, 's' => $s, 'ki' => $ki, 'fpl' => $fpl, 'typel' => $typel, 'nested' => $nested, 'old' => hostov_yaml_scalar($kv['client-fingerprint'] ?? ''),
                   'tls' => $sec !== 'none' && in_array($type, ['vless', 'vmess', 'trojan'], true)];
    }
    $hits = []; $ins = [];
    foreach (hostov_assign($nodes, $rows) as $i => $row) {
        $x = $meta[$i];
        if (!$x || !$x['tls']) continue;
        $v = hostov_yaml_val((string) $row['val']);
        if ($x['flow']) {
            $ln = $lines[$x['s']];
            $old = preg_match('~client-fingerprint:\s*("[^"]*"|\'[^\']*\'|[^,}]+)~', $ln, $m) ? hostov_yaml_scalar($m[1]) : '';
            $ln2 = preg_replace('~(client-fingerprint:\s*)("[^"]*"|\'[^\']*\'|[^,}]+?)(\s*[,}])~', '${1}' . $v . '$3', $ln, 1, $cnt);
            if (!$cnt) $ln2 = preg_replace('~\s*\}\s*$~', ', client-fingerprint: ' . $v . '}', $ln, 1);
            $lines[$x['s']] = $ln2;
        } else {
            $old = $x['old'];
            if ($x['fpl'] !== null) {
                $lines[$x['fpl']] = preg_replace('~^(\s*(?:-\s+)?client-fingerprint:\s*).*$~', '${1}' . $v, $lines[$x['fpl']]);
            } else {
                $ins[$x['typel']] = str_repeat(' ', $x['ki']) . 'client-fingerprint: ' . $v;
            }
            foreach ($x['nested'] as $nl) $lines[$nl] = preg_replace('~^(\s*client-fingerprint:\s*).*$~', '${1}' . $v, $lines[$nl]);
        }
        $hits[] = [(int) $row['id'], (string) ($nodes[$i]['names'][0] ?? ''), $old, (string) $row['val']];
    }
    if (!$hits) return [$body, []];
    if ($ins) {
        krsort($ins);
        foreach ($ins as $after => $l) array_splice($lines, $after + 1, 0, [$l]);
    }
    return [implode($eol, $lines), $hits];
}

function hostov_apply_singbox($body, array $rows) {
    $o = json_decode($body);
    if (!is_object($o) || !isset($o->outbounds) || !is_array($o->outbounds)) return [$body, []];
    $nodes = []; $idx = [];
    foreach ($o->outbounds as $k => $ob) {
        if (!is_object($ob) || !isset($ob->server)) continue;
        $tls = isset($ob->tls) && is_object($ob->tls) && !empty($ob->tls->enabled) ? $ob->tls : null;
        $sec = $tls ? (isset($tls->reality) && is_object($tls->reality) && !empty($tls->reality->enabled) ? 'reality' : 'tls') : 'none';
        $tr = isset($ob->transport) && is_object($ob->transport) ? (string) ($ob->transport->type ?? '') : 'tcp';
        $nodes[] = ['names' => [(string) ($ob->tag ?? '')], 'named' => true, 'a' => (string) $ob->server, 'p' => (int) ($ob->server_port ?? 0),
                    'sec' => $sec, 'tr' => $tr, 'sni' => $tls ? (string) ($tls->server_name ?? '') : '', 'g' => ''];
        $idx[] = $k;
    }
    $hits = [];
    foreach (hostov_assign($nodes, $rows) as $i => $row) {
        $ob = $o->outbounds[$idx[$i]];
        if (!in_array((string) ($ob->type ?? ''), ['vless', 'vmess', 'trojan'], true) || $nodes[$i]['sec'] === 'none') continue;
        $u = isset($ob->tls->utls) && is_object($ob->tls->utls) ? $ob->tls->utls : null;
        $old = $u && !empty($u->enabled) ? (string) ($u->fingerprint ?? '') : '';
        if (!$u) { $u = new stdClass(); $ob->tls->utls = $u; }
        $u->enabled = true;
        $u->fingerprint = (string) $row['val'];
        $hits[] = [(int) $row['id'], $nodes[$i]['names'][0], $old, (string) $row['val']];
    }
    if (!$hits) return [$body, []];
    $out = json_encode($o, hostov_json_flags($body));
    return $out === false ? [$body, []] : [$out, $hits];
}

function hostov_xray_addr($ob) {
    $s = $ob->settings ?? null;
    if (!is_object($s)) return ['', 0];
    $t = null;
    if (isset($s->vnext) && is_array($s->vnext) && isset($s->vnext[0]) && is_object($s->vnext[0])) $t = $s->vnext[0];
    elseif (isset($s->servers) && is_array($s->servers) && isset($s->servers[0]) && is_object($s->servers[0])) $t = $s->servers[0];
    elseif (isset($s->address)) $t = $s;
    return $t ? [(string) ($t->address ?? ''), (int) ($t->port ?? 0)] : ['', 0];
}

function hostov_xray_sec($ob) {
    $ss = is_object($ob) ? ($ob->streamSettings ?? null) : null;
    if (!is_object($ss)) return ['none', 'tcp', ''];
    $sec = (string) ($ss->security ?? 'none');
    $k = $sec === 'reality' ? 'realitySettings' : 'tlsSettings';
    $sni = isset($ss->$k) && is_object($ss->$k) ? (string) ($ss->$k->serverName ?? '') : '';
    return [$sec === '' ? 'none' : $sec, (string) ($ss->network ?? 'tcp'), $sni];
}

function hostov_xray_set($ob, $v) {
    if (!is_object($ob) || !in_array((string) ($ob->protocol ?? ''), ['vless', 'vmess', 'trojan'], true)) return null;
    $ss = $ob->streamSettings ?? null;
    if (!is_object($ss)) return null;
    $sec = (string) ($ss->security ?? '');
    if ($sec !== 'tls' && $sec !== 'reality') return null;
    $k = $sec === 'reality' ? 'realitySettings' : 'tlsSettings';
    if (!isset($ss->$k) || !is_object($ss->$k)) $ss->$k = new stdClass();
    $old = (string) ($ss->$k->fingerprint ?? '');
    $ss->$k->fingerprint = $v;
    $dl = $ss->xhttpSettings->extra->downloadSettings ?? null;
    if (is_object($dl)) hostov_dl_fp($dl, (string) ($dl->security ?? $sec), $v);
    return $old;
}

// xray-json: у собственного узла конфига тег «proxy» и имя в remarks, у вставленных шаблоном — «proxy-N»,
// имя или тег хоста. «proxy» в шаблоне с инжектором может оказаться чужим хостом, поэтому тегам не верим как имени.
function hostov_apply_xray($body, array $rows) {
    $o = json_decode($body);
    $list = is_array($o) ? $o : (is_object($o) ? [$o] : null);
    if ($list === null) return [$body, []];
    $nodes = []; $obs = [];
    foreach ($list as $ci => $cfg) {
        if (!is_object($cfg) || !isset($cfg->outbounds) || !is_array($cfg->outbounds)) continue;
        foreach ($cfg->outbounds as $ob) {
            if (!is_object($ob) || !in_array((string) ($ob->protocol ?? ''), ['vless', 'vmess', 'trojan'], true)) continue;
            [$a, $p] = hostov_xray_addr($ob);
            [$sec, $tr, $sni] = hostov_xray_sec($ob);
            $tag = is_string($ob->tag ?? null) ? $ob->tag : '';
            $names = [$tag];
            if ($tag === 'proxy' && is_string($cfg->remarks ?? null)) $names[] = $cfg->remarks;
            $nodes[] = ['names' => $names, 'named' => false, 'a' => $a, 'p' => $p, 'sec' => $sec, 'tr' => $tr, 'sni' => $sni, 'g' => (string) $ci];
            $obs[] = [$ob, $tag === 'proxy' && is_string($cfg->remarks ?? null) ? $cfg->remarks : $tag];
        }
    }
    $hits = [];
    foreach (hostov_assign($nodes, $rows) as $i => $row) {
        $old = hostov_xray_set($obs[$i][0], (string) $row['val']);
        if ($old !== null) $hits[] = [(int) $row['id'], (string) $obs[$i][1], $old, (string) $row['val']];
    }
    if (!$hits) return [$body, []];
    $out = json_encode(is_array($o) ? $list : $list[0], hostov_json_flags($body));
    return $out === false ? [$body, []] : [$out, $hits];
}

function hostov_note(array $rows, array $hits, $app) {
    if (!$rows) return;
    $hit = [];
    foreach ($hits as $h) $hit[(int) $h[0]] = true;
    $now = time();
    $app = mb_substr((string) $app, 0, 128);
    try {
        $p = db();
        $up = $p->prepare('UPDATE host_ovr SET last_hit = ?, last_app = ? WHERE id = ?');
        $um = $p->prepare('UPDATE host_ovr SET last_miss = ?, miss_app = ? WHERE id = ?');
        foreach ($rows as $r) {
            if (isset($hit[(int) $r['id']])) $up->execute([$now, $app, (int) $r['id']]);
            else $um->execute([$now, $app, (int) $r['id']]);
        }
    } catch (Throwable $e) { error_log('submw host_ovr note: ' . $e->getMessage()); }
}

// ---- админка ----

// exp — срок вышел; inv — значение не годится для этого хоста (не применяется); wait — после изменения ещё не уходила;
// ok — ушла; part — ушла, но в последней выдаче (другое приложение или формат) хоста не было;
// bad — хоста не было, а уходила давно или ни разу.
function hostov_state(array $r, $now = null) {
    $now = $now ?? time();
    if ($r['till'] !== null && (int) $r['till'] <= $now) return 'exp';
    if (($r['param'] ?? 'fp') === 'fp' && !hostov_fp_ok($r)) return 'inv';
    $vat = (int) ($r['vat'] ?? 0);
    $lh = $r['last_hit'] !== null && (int) $r['last_hit'] >= $vat ? (int) $r['last_hit'] : null;
    $lm = $r['last_miss'] !== null && (int) $r['last_miss'] >= $vat ? (int) $r['last_miss'] : null;
    if ($lh === null) return $lm === null ? 'wait' : 'bad';
    if ($lm !== null && $lm > $lh) return $now - $lh > HOSTOV_STALE ? 'bad' : 'part';
    return 'ok';
}

function hostov_view(array $r) {
    return [
        'id' => (int) $r['id'], 'su' => (string) $r['short_uuid'], 'un' => (string) $r['username'], 'h' => (string) $r['host_uuid'],
        'n' => (string) $r['host_name'], 'sec' => (string) $r['host_sec'], 'tr' => hostov_tr_label($r['host_tr']), 'p' => (string) $r['param'],
        'pv' => (string) $r['pv'], 'v' => (string) $r['val'], 'till' => $r['till'] !== null ? (int) $r['till'] : null,
        'at' => (int) $r['vat'], 'lh' => $r['last_hit'] !== null ? (int) $r['last_hit'] : null, 'la' => (string) $r['last_app'],
        'lm' => $r['last_miss'] !== null ? (int) $r['last_miss'] : null, 'ma' => (string) ($r['miss_app'] ?? ''), 'st' => hostov_state($r),
    ];
}

function hostov_list($short = null) {
    if (!hostov_ensure()) return [];
    try {
        if ($short === null) $st = db()->query('SELECT * FROM host_ovr ORDER BY short_uuid, id');
        else { $st = db()->prepare('SELECT * FROM host_ovr WHERE short_uuid = ? ORDER BY id'); $st->execute([(string) $short]); }
        return array_map('hostov_view', $st->fetchAll(PDO::FETCH_ASSOC));
    } catch (Throwable $e) { return []; }
}

function hostov_till($v) {
    $t = (int) $v;
    return $t > 0 ? $t : null;
}

function hostov_save($short, array $h, $param, $val, $till, $uid, $username, &$err) {
    $err = '';
    $short = trim((string) $short);
    $huuid = trim((string) ($h['uuid'] ?? ''));
    if ($short === '' || $huuid === '') { $err = 'Не указан пользователь или хост'; return false; }
    if (!in_array((string) ($h['sec'] ?? ''), ['tls', 'reality'], true) || (int) ($h['port'] ?? 0) <= 0) { $err = 'У хоста нет TLS или REALITY'; return false; }
    if ($param !== 'fp' || !in_array((string) $val, hostov_fp_values((string) $h['sec']), true)) { $err = (string) $h['sec'] === 'reality' ? 'С REALITY работают только chrome, firefox, safari и randomized' : 'Недопустимое значение'; return false; }
    if (!hostov_ensure()) { $err = 'Нет таблицы подмен'; return false; }
    $now = time();
    $cut = static fn($s, $n) => mb_substr((string) $s, 0, $n);
    $f = [
        'user_id' => (int) $uid > 0 ? (int) $uid : null, 'username' => $cut($username, 64),
        'host_name' => $cut($h['name'] ?? '', 255), 'host_rmk' => $cut($h['rmk'] ?? '', 255), 'host_addr' => $cut($h['addr'] ?? '', 255),
        'host_apat' => $cut($h['apat'] ?? '', 255), 'host_port' => (int) ($h['port'] ?? 0), 'host_sec' => $cut($h['sec'] ?? '', 16),
        'host_tr' => $cut($h['trk'] ?? '', 16), 'host_sni' => $cut($h['sni'] ?? '', 255), 'host_snipat' => $cut($h['snipat'] ?? '', 255), 'pv' => $cut($h['fp'] ?? '', 64),
    ];
    $cols = array_keys($f);
    try {
        $p = db();
        $st = $p->prepare('SELECT id, val, vat FROM host_ovr WHERE short_uuid = ? AND host_uuid = ? AND param = ?');
        $st->execute([$short, $huuid, $param]);
        $ex = $st->fetch(PDO::FETCH_ASSOC);
        if ($ex) {
            $p->prepare('UPDATE host_ovr SET ' . implode(' = ?, ', $cols) . ' = ?, val = ?, till = ?, vat = ? WHERE id = ?')
              ->execute(array_merge(array_values($f), [(string) $val, hostov_till($till), (string) $ex['val'] === (string) $val ? (int) ($ex['vat'] ?? $now) : $now, (int) $ex['id']]));
        } else {
            $p->prepare('INSERT INTO host_ovr (short_uuid, host_uuid, param, ' . implode(', ', $cols) . ', val, till, created_at, vat) VALUES (' . implode(', ', array_fill(0, count($cols) + 7, '?')) . ')')
              ->execute(array_merge([$short, $huuid, $param], array_values($f), [(string) $val, hostov_till($till), $now, $now]));
        }
    } catch (Throwable $e) { $err = 'База не приняла запись'; error_log('submw host_ovr save: ' . $e->getMessage()); return false; }
    hostov_recount();
    return true;
}

function hostov_set($id, $val, $till, $setTill, &$err) {
    $err = '';
    if (!hostov_ensure()) { $err = 'Нет таблицы подмен'; return false; }
    try {
        $p = db();
        if ($val !== null) {
            $st = $p->prepare('SELECT host_sec FROM host_ovr WHERE id = ?');
            $st->execute([(int) $id]);
            $sec = (string) $st->fetchColumn();
            if (!in_array((string) $val, hostov_fp_values($sec), true)) { $err = $sec === 'reality' ? 'С REALITY работают только chrome, firefox, safari и randomized' : 'Недопустимое значение'; return false; }
            $p->prepare('UPDATE host_ovr SET val = ?, vat = ? WHERE id = ? AND val <> ?')->execute([(string) $val, time(), (int) $id, (string) $val]);
        }
        if ($setTill) $p->prepare('UPDATE host_ovr SET till = ? WHERE id = ?')->execute([hostov_till($till), (int) $id]);
    } catch (Throwable $e) { $err = 'База не приняла запись'; return false; }
    hostov_recount();
    return true;
}

function hostov_short_of($id) {
    try {
        $st = db()->prepare('SELECT short_uuid FROM host_ovr WHERE id = ?');
        $st->execute([(int) $id]);
        $v = $st->fetchColumn();
        return $v === false ? '' : (string) $v;
    } catch (Throwable $e) { return ''; }
}

function hostov_del($id) {
    if (!hostov_ensure()) return false;
    try { db()->prepare('DELETE FROM host_ovr WHERE id = ?')->execute([(int) $id]); } catch (Throwable $e) { return false; }
    hostov_recount();
    return true;
}

// «Удалить недействующие»: срок вышел, подмена не срабатывает или значение не годится (см. hostov_state).
function hostov_clear_off() {
    if (!hostov_ensure()) return 0;
    $n = 0;
    try {
        $p = db();
        $del = $p->prepare('DELETE FROM host_ovr WHERE id = ?');
        foreach ($p->query('SELECT * FROM host_ovr')->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (in_array(hostov_state($r), ['exp', 'bad', 'inv'], true)) { $del->execute([(int) $r['id']]); $n++; }
        }
    } catch (Throwable $e) {}
    hostov_recount();
    return $n;
}

function hostov_purge_user($short) {
    if ((string) $short === '' || !hostov_any() || !hostov_ensure()) return;
    try { db()->prepare('DELETE FROM host_ovr WHERE short_uuid = ?')->execute([(string) $short]); } catch (Throwable $e) {}
    hostov_recount();
}

// Новый shortUuid после перевыпуска подписки в панели: подмены переходят за пользователем.
function hostov_rekey($uid, $short) {
    $uid = (int) $uid; $short = (string) $short;
    if ($uid <= 0 || $short === '' || !hostov_any() || !hostov_ensure()) return;
    try { db()->prepare('UPDATE host_ovr SET short_uuid = ? WHERE user_id = ? AND short_uuid <> ?')->execute([$short, $uid, $short]); }
    catch (Throwable $e) { error_log('submw host_ovr rekey: ' . $e->getMessage()); }
}

function hostov_tr_label($t) {
    $m = ['tcp' => 'TCP', 'raw' => 'TCP', 'ws' => 'WS', 'xhttp' => 'XHTTP', 'grpc' => 'gRPC', 'httpupgrade' => 'HTTPUpgrade', 'kcp' => 'mKCP', 'hysteria' => 'Hysteria2'];
    return $m[(string) $t] ?? strtoupper((string) $t);
}

// Хост панели для ключа подключения — по тем же правилам, что и в выдаче: порт, шаблон адреса, имя.
function hostov_host_of(array $x, array $hosts) {
    $c = [];
    foreach ($hosts as $h) {
        if (!is_array($h) || (string) ($h['uuid'] ?? '') === '' || !empty($h['isDisabled']) || !empty($h['hid'])) continue;
        if ((int) ($h['port'] ?? 0) !== (int) $x['p'] || !hostov_pat_ok($x['a'], (string) ($h['address'] ?? ''))) continue;
        if (!hostov_names_ok([$x['n']], ['host_name' => (string) ($h['remark'] ?? ''), 'host_rmk' => (string) ($h['remark'] ?? '')])) continue;
        $c[] = $h;
    }
    if (count($c) > 1 && $x['sni'] !== '') {
        $s = array_values(array_filter($c, static fn($h) => (string) ($h['sni'] ?? '') !== '' && hostov_pat_ok($x['sni'], (string) $h['sni'])));
        if ($s) $c = $s;
    }
    return $c[0] ?? null;
}

// Хосты пользователя: его ключи подключения (без отметки «запрос подписки» и без проверки устройств) + хосты панели.
// Хосты, исключённые панелью из формата ссылок, в ключи не попадают.
function hostov_hosts($uid, &$why) {
    $why = '';
    $uid = (int) $uid;
    if ($uid <= 0) { $why = 'nouser'; return null; }
    [$ok, $code, $data] = remnawave_api_get('/api/subscriptions/connection-keys/' . $uid);
    if (!$ok) {
        $why = in_array((int) $code, [401, 403], true) ? 'right' : ((int) $code === 404 ? 'nouser' : 'panel');
        return null;
    }
    $keys = $data['response']['enabledKeys'] ?? null;
    if (!is_array($keys)) { $why = 'panel'; return null; }
    $he = '';
    $hosts = panel_ref('hosts', 60, $he);
    // Снимок до появления в нём SNI — один раз перечитать.
    if (is_array($hosts) && $hosts && !array_filter($hosts, static fn($h) => is_array($h) && array_key_exists('sni', $h))) $hosts = panel_ref('hosts', 0, $he) ?? $hosts;
    if (!is_array($hosts)) { $why = strpos($he, '403') !== false ? 'right' : 'panel'; return null; }
    $out = [];
    foreach ($keys as $k) {
        $x = hostov_link_parse(trim((string) $k));
        if ($x === null || $x['a'] === '0.0.0.0') continue;
        $h = hostov_host_of($x, $hosts);
        if ($h === null) continue;
        $no = '';
        if ($x['scheme'] === 'hysteria2' || $x['scheme'] === 'hy2') $no = 'Hysteria2 — отпечатка нет';
        elseif ($x['scheme'] === 'ss') $no = 'Shadowsocks — отпечатка нет';
        elseif ($x['sec'] !== 'tls' && $x['sec'] !== 'reality') $no = 'Без TLS — отпечатка нет';
        $tr = $x['scheme'] === 'hysteria2' || $x['scheme'] === 'hy2' ? 'hysteria' : ($x['scheme'] === 'ss' ? 'tcp' : $x['type']);
        $out[] = [
            'uuid' => (string) $h['uuid'], 'name' => $x['n'], 'rmk' => (string) ($h['remark'] ?? ''), 'addr' => $x['a'], 'apat' => (string) ($h['address'] ?? ''), 'port' => $x['p'],
            'sec' => $tr === 'hysteria' ? 'quic' : ($x['scheme'] === 'ss' ? 'none' : $x['sec']), 'tr' => hostov_tr_label($tr), 'trk' => $tr,
            'sni' => $x['sni'], 'snipat' => (string) ($h['sni'] ?? ''),
            'fp' => $no === '' ? ($x['fp'] !== '' ? $x['fp'] : ($x['sec'] === 'reality' ? 'chrome' : '')) : '', 'no' => $no,
        ];
    }
    return ['hosts' => $out, 'uid' => $uid];
}
