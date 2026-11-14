<?php

// Значения панели. С REALITY рабочие только chrome, firefox и safari (так и в тестах Xray: TestVlessRealityFingerprints)
// и randomized; random Xray выбирает из набора с ios, edge, 360 и qq — с REALITY он ломается через раз.
function hostov_fp_values($sec = null) {
    if ($sec === 'reality') return ['chrome', 'firefox', 'safari', 'randomized'];
    return ['chrome', 'firefox', 'safari', 'ios', 'android', 'edge', '360', 'qq', 'random', 'randomized'];
}

// Уровень логов — параметр всей подписки, а не хоста. Общая шкала; у каждого ядра свои ключ и слова (см. hostov_apply_log).
function hostov_log_values() {
    return ['off', 'error', 'warning', 'info', 'debug'];
}

function hostov_fp_ok(array $r) {
    return in_array((string) ($r['val'] ?? ''), hostov_fp_values((string) ($r['host_sec'] ?? '')), true);
}

// ---- маски finalmask (Xray 26): фрагментация TCP и шум UDP ----
// Только клиентские маски: серверу их пара не нужна. Правила — по Xray v26.10.31. Пресеты — на полях, которые понимает
// любая сборка с finalmask (packets/length/delay, rand/delay/reset); новые поля — со своими версиями, см. hostov_mask_need.

function hostov_presets() {
    $r3 = static fn($x) => [$x, $x, $x];
    return [
        'frag' => [
            'std'   => ['n' => 'Обычная', 'd' => 'ClientHello кусками 100–200 Б, пауза 10–20 мс', 'tls' => true, 's' => ['packets' => 'tlshello', 'length' => '100-200', 'delay' => '10-20']],
            'one'   => ['n' => 'Одним пакетом', 'd' => 'ClientHello режется на записи, но уходит одним TCP-пакетом', 'tls' => true, 's' => ['packets' => 'tlshello', 'length' => '100-200', 'delay' => 0]],
            'small' => ['n' => 'Мелкая', 'd' => 'куски 10–30 Б, пауза 5–10 мс — медленнее', 'tls' => true, 's' => ['packets' => 'tlshello', 'length' => '10-30', 'delay' => '5-10']],
            'rec'   => ['n' => 'Первые записи', 'd' => 'записи 1–3 кусками 100–200 Б, пауза 10–20 мс', 'tls' => false, 's' => ['packets' => '1-3', 'length' => '100-200', 'delay' => '10-20']],
            'recs'  => ['n' => 'Мелко', 'd' => 'записи 1–2 кусками 10–30 Б, пауза 5–10 мс', 'tls' => false, 's' => ['packets' => '1-2', 'length' => '10-30', 'delay' => '5-10']],
        ],
        'noise' => [
            'one' => ['n' => 'Один пакет', 'd' => '50–100 случайных байт перед соединением', 's' => ['noise' => [['rand' => '50-100']]]],
            'ser' => ['n' => 'Серия', 'd' => '3 пакета по 50–150 Б, пауза 10–20 мс', 's' => ['noise' => $r3(['rand' => '50-150', 'delay' => '10-20'])]],
            'rep' => ['n' => 'С повтором', 'd' => '1 пакет; снова, если соединение молчало 30–60 с', 's' => ['reset' => '30-60', 'noise' => [['rand' => '50-100']]]],
        ],
    ];
}

function hostov_preset_label($p, $v) {
    $x = hostov_presets()[$p][$v] ?? null;
    return $x ? $x['n'] : 'Свои';
}

// Что можно менять у хоста: отпечаток — у TLS и REALITY по TCP; фрагментация — у TCP-транспортов (ClientHello — только с TLS);
// шум — у UDP: mKCP, Hysteria2, XHTTP по HTTP/3, а также Shadowsocks (его UDP идёт через finalmask).
function hostov_caps($sec, $tr, $proto) {
    $tr = strtolower((string) $tr); $proto = (string) $proto;
    $udp = in_array($tr, ['kcp', 'mkcp', 'hysteria', 'xhttp3'], true) || $proto === 'hysteria2';
    $tls = in_array((string) $sec, ['tls', 'reality'], true);
    $known = $proto !== '';
    return [
        'fp' => $tls && !in_array($tr, ['hysteria', 'xhttp3'], true) && $proto !== 'ss' && $proto !== 'hysteria2',
        'frag' => $known && !$udp, 'hello' => $known && !$udp && $tls,
        'noise' => $known && ($udp || $proto === 'ss'),
    ];
}

function hostov_mask_of(array $r) {
    $m = json_decode((string) ($r['mval'] ?? ''), true);
    return is_array($m) ? $m : null;
}

function hostov_row_ok(array $r) {
    $p = (string) ($r['param'] ?? '');
    if ($p === 'fp') return hostov_fp_ok($r);
    if ($p === 'log') return (string) ($r['host_uuid'] ?? '') === '' && in_array((string) ($r['val'] ?? ''), hostov_log_values(), true);
    if ($p !== 'frag' && $p !== 'noise') return false;
    $m = hostov_mask_of($r);
    if (!$m || ($m['type'] ?? '') !== ($p === 'frag' ? 'fragment' : 'noise') || !is_array($m['settings'] ?? null) || !$m['settings']) return false;
    return !empty(hostov_caps((string) ($r['host_sec'] ?? ''), (string) ($r['host_tr'] ?? ''), (string) ($r['host_proto'] ?? ''))[$p]);
}

// Значения маски строкой: «packets tlshello · length 100-200 · delay 10-20», «3 × rand 50-150 delay 10-20».
function hostov_mask_txt($p, $m) {
    $s = is_array($m) && array_key_exists('settings', $m) ? $m['settings'] : $m;
    if (!is_array($s)) return '';
    $v = static fn($x) => is_array($x) ? implode(',', array_map(static fn($y) => is_scalar($y) ? (string) $y : json_encode($y, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $x)) : (is_bool($x) ? ($x ? 'true' : 'false') : (string) $x);
    $kv = static function ($a) use ($v) { $o = []; foreach ($a as $k => $x) $o[] = $k . ' ' . $v($x); return $o; };
    if ($p === 'frag') return mb_substr(implode(' · ', $kv($s)), 0, 200);
    if (isset($s['script'])) return 'script ' . $v($s['script']) . (isset($s['reset']) ? ' · reset ' . $v($s['reset']) : '');
    $it = [];
    foreach ((array) ($s['noise'] ?? []) as $n) if (is_array($n)) $it[] = implode(' ', $kv($n));
    $u = array_values(array_unique($it));
    $t = count($u) === 1 && count($it) > 1 ? count($it) . ' × ' . $u[0] : implode(' | ', $it);
    if (isset($s['reset'])) $t .= ' · reset ' . $v($s['reset']);
    return mb_substr($t, 0, 200);
}

// Int32Range Xray: число или строка «N» / «N-M». Отрицательные не нужны ни в одной маске.
function hostov_rng($x, &$a = null, &$b = null) {
    if (is_int($x)) { $a = $b = $x; return $x >= 0 && $x <= 2147483647; }
    if (!is_string($x) || !preg_match('~^(\d{1,10})(?:-(\d{1,10}))?$~', $x, $m)) return false;
    $a = (int) $m[1]; $b = isset($m[2]) ? (int) $m[2] : $a;
    if ($a > $b) [$a, $b] = [$b, $a];
    return $b <= 2147483647;
}

// Проверка по правилам Xray 26.10.31: то, что отклонит ядро, ломает клиенту весь конфиг. $new — поля, которым нужна сборка новее первой с finalmask.
function hostov_frag_check(array $s, &$err, array &$new) {
    $keys = ['packets', 'length', 'delay', 'lengths', 'delays', 'maxSplit'];
    foreach ($s as $k => $x) if (!in_array((string) $k, $keys, true)) { $err = 'Неизвестное поле «' . $k . '» — Xray его не читает'; return false; }
    if (array_key_exists('packets', $s)) {
        $pk = $s['packets'];
        if (!is_string($pk)) { $err = '«packets» — строка: "tlshello" или "1-3"'; return false; }
        if ($pk !== '' && strtolower($pk) !== 'tlshello') {
            if (!hostov_rng($pk, $a) || $a < 1) { $err = '«packets»: "tlshello" или номера записей с 1, например "1-3"'; return false; }
        }
    }
    foreach (['length', 'delay', 'maxSplit'] as $k) if (array_key_exists($k, $s) && !hostov_rng($s[$k])) { $err = '«' . $k . '»: целое число от 0 или диапазон "N-M"'; return false; }
    foreach (['lengths', 'delays'] as $k) {
        if (!array_key_exists($k, $s)) continue;
        if (!is_array($s[$k]) || !array_is_list($s[$k])) { $err = '«' . $k . '» — список диапазонов'; return false; }
        foreach ($s[$k] as $x) if (!hostov_rng($x)) { $err = '«' . $k . '»: целые числа от 0 или диапазоны "N-M"'; return false; }
        if ($s[$k]) $new[] = $k;
    }
    if (!empty($s['lengths'])) {
        hostov_rng(end($s['lengths']), $a);
        if ($a < 1) { $err = 'Последняя длина в «lengths» — от 1 байта: с нулём Xray не запустится'; return false; }
        if (!array_key_exists('length', $s)) $new[] = 'без length';
    } else {
        $a = 0;
        if (array_key_exists('length', $s)) hostov_rng($s['length'], $a);
        if ($a < 1) { $err = 'Длина куска («length») — от 1 байта: с нулём Xray не запустится'; return false; }
    }
    return true;
}

function hostov_noise_exp_ok($exp, &$err) {
    if (!is_string($exp)) { $err = 'У type "exp" поле «packet» — строка'; return false; }
    $n = preg_match_all('~<\s*([a-z]+)(?:\s+([^>]*?))?\s*>~', $exp, $mm, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
    $last = 0;
    foreach ($mm as $m) {
        if (trim(substr($exp, $last, $m[0][1] - $last)) !== '') { $err = 'Выражение exp: лишний текст «' . substr($exp, $last, $m[0][1] - $last) . '»'; return false; }
        $last = $m[0][1] + strlen($m[0][0]);
        $key = $m[1][0]; $arg = isset($m[2]) && $m[2][1] >= 0 ? $m[2][0] : '';
        if ($key === 'b') {
            $hx = preg_replace('~^0x~i', '', preg_replace('~\s+~', '', $arg));
            if ($hx === '' || strlen($hx) % 2 || !ctype_xdigit($hx)) { $err = 'Выражение exp: <b …> — байты в hex'; return false; }
        } elseif (in_array($key, ['r', 'rc', 'rd'], true)) {
            if (!hostov_rng($arg, $a, $b) || $b > 65535 || preg_match('~^(\d+)-(\d+)$~', $arg, $q) && (int) $q[1] > (int) $q[2]) { $err = 'Выражение exp: <' . $key . ' N> или <' . $key . ' N-M>, до 65535'; return false; }
        } elseif (in_array($key, ['t', 'c', 'n'], true)) {
            if ($arg !== '') { $err = 'Выражение exp: <' . $key . '> без аргумента'; return false; }
        } else { $err = 'Выражение exp: неизвестное <' . $key . '>'; return false; }
    }
    if (trim(substr($exp, $last)) !== '') { $err = 'Выражение exp: лишний текст в конце'; return false; }
    if (!$n) { $err = 'Выражение exp пустое'; return false; }
    return true;
}

function hostov_noise_check(array $s, &$err, array &$new) {
    foreach ($s as $k => $x) {
        if (!in_array((string) $k, ['reset', 'noise', 'script'], true)) { $err = 'Неизвестное поле «' . $k . '» — Xray его не читает'; return false; }
    }
    // Скрипт Lua (Xray 26.10.21+) вместо списка пакетов. В 26.10.31 «script» — путь к файлу .lua на устройстве клиента:
    // встроенный скрипт ядро пока не принимает (common/lua: «currently unavailable»), файла нет — конфиг не соберётся.
    if (array_key_exists('script', $s)) {
        if (array_key_exists('noise', $s)) { $err = '«script» и «noise» вместе нельзя — либо скрипт, либо пакеты'; return false; }
        $sc = $s['script'];
        if (!is_string($sc) || trim($sc) === '') { $err = '«script» — путь к файлу .lua на устройстве клиента'; return false; }
        if (strpbrk($sc, "\r\n") !== false || !preg_match('~\.lua$~i', $sc) || strlen($sc) > 512) { $err = '«script» в Xray 26.10.31 — только путь к файлу .lua на устройстве клиента; текст скрипта ядро пока не принимает'; return false; }
        if (array_key_exists('reset', $s) && !hostov_rng($s['reset'])) { $err = '«reset»: секунды, целое число от 0 или "N-M"'; return false; }
        $new[] = 'script';
        return true;
    }
    if (array_key_exists('reset', $s) && !hostov_rng($s['reset'])) { $err = '«reset»: секунды, целое число от 0 или "N-M"'; return false; }
    $L = $s['noise'] ?? null;
    if (!is_array($L) || !array_is_list($L) || !$L) { $err = 'Нужен хотя бы один пакет в «noise»'; return false; }
    if (count($L) > 16) { $err = 'Не больше 16 пакетов'; return false; }
    foreach ($L as $i => $it) {
        $n = 'Пакет ' . ($i + 1) . ': ';
        if (!is_array($it) || ($it && array_is_list($it))) { $err = $n . 'нужен объект'; return false; }
        foreach ($it as $k => $x) if (!in_array((string) $k, ['rand', 'randRange', 'type', 'packet', 'delay'], true)) { $err = $n . 'неизвестное поле «' . $k . '»'; return false; }
        $rb = 0;
        if (array_key_exists('rand', $it) && !hostov_rng($it['rand'], $ra, $rb)) { $err = $n . '«rand»: размер, целое число или "N-M"'; return false; }
        if (array_key_exists('delay', $it) && !hostov_rng($it['delay'])) { $err = $n . '«delay»: мс, целое число от 0 или "N-M"'; return false; }
        if (array_key_exists('randRange', $it) && (!hostov_rng($it['randRange'], $a, $b) || $b > 255)) { $err = $n . '«randRange»: байты 0–255, "N-M"'; return false; }
        $type = strtolower((string) ($it['type'] ?? ''));
        if (array_key_exists('type', $it) && !is_string($it['type'])) { $err = $n . '«type» — строка'; return false; }
        if (array_key_exists('packet', $it) && $it['packet'] === null) { $err = $n . '«packet» пустой'; return false; }
        $has = array_key_exists('packet', $it);
        // У str, hex, base64 и exp ядро читает «packet» всегда — без него конфиг не соберётся.
        if (!$has && in_array($type, ['str', 'hex', 'base64', 'exp'], true)) { $err = $n . 'у type "' . $type . '" нужен «packet»'; return false; }
        if ($has && $rb > 0) { $err = $n . '«packet» и «rand» вместе нельзя'; return false; }
        if ($type === 'exp') {
            $new[] = 'type exp';
            if (!hostov_noise_exp_ok($it['packet'] ?? null, $e)) { $err = $n . $e; return false; }
            continue;
        }
        if (!in_array($type, ['', 'array', 'str', 'hex', 'base64'], true)) { $err = $n . 'тип — array, str, hex, base64 или exp'; return false; }
        $bytes = 0;
        if ($has) {
            $pk = $it['packet'];
            if ($type === 'str') { if (!is_string($pk)) { $err = $n . 'у type "str" «packet» — строка'; return false; } $bytes = strlen($pk); }
            elseif ($type === 'hex') { if (!is_string($pk) || strlen($pk) % 2 || ($pk !== '' && !ctype_xdigit($pk))) { $err = $n . 'HEX — пары символов 0–9, a–f'; return false; } $bytes = strlen($pk) / 2; }
            elseif ($type === 'base64') { $d = is_string($pk) ? base64_decode($pk, true) : false; if ($d === false || base64_encode($d) !== $pk) { $err = $n . 'это не Base64'; return false; } $bytes = strlen($d); }
            elseif (is_string($pk)) { $d = base64_decode($pk, true); if ($d === false || base64_encode($d) !== $pk) { $err = $n . 'у type "array" строка «packet» читается как Base64 — она не Base64'; return false; } $bytes = strlen($d); }
            elseif (is_array($pk) && array_is_list($pk)) { foreach ($pk as $by) if (!is_int($by) || $by < 0 || $by > 255) { $err = $n . 'массив байтов — числа 0–255'; return false; } $bytes = count($pk); }
            else { $err = $n . '«packet» — массив байтов или строка'; return false; }
        }
        if (!$bytes && $rb < 1) { $err = $n . 'пустой — нужен «rand» или «packet»'; return false; }
    }
    return true;
}

// С какой сборки Xray ядро понимает поле маски (по тегам Xray-core): lengths/delays — 26.6.22, type exp — 26.9.30, script — 26.10.21.
function hostov_mask_need(array $new) {
    $v = '';
    foreach ($new as $n) {
        $x = in_array($n, ['lengths', 'delays', 'без length'], true) ? '26.6.22' : ($n === 'type exp' ? '26.9.30' : ($n === 'script' ? '26.10.21' : ''));
        if ($x !== '' && version_compare($x, $v === '' ? '0' : $v, '>')) $v = $x;
    }
    return $v;
}

function hostov_mask_warn(array $new) {
    $v = hostov_mask_need($new);
    if ($v === '') return '';
    $soft = !array_diff($new, ['lengths', 'delays']);
    $w = 'Нужно ядро Xray ' . $v . ' или новее. ';
    $w .= $soft ? 'Ядро старше возьмёт length/delay и куски по списку не применит.' : 'На ядре старше у клиента не соберётся конфиг.';
    if (in_array('script', $new, true)) $w .= ' Файл скрипта должен лежать на устройстве клиента (рядом с конфигом Xray или по полному пути) и определять HandleNoise — без файла конфиг не соберётся; в мобильных приложениях так не выйдет.';
    return $w;
}

// Маска для сохранения: пресет — по ключу, «own» — из JSON админа после проверки. Возвращает канонический JSON или null.
function hostov_mask_build($p, $val, $json, array $caps, &$err, &$new = null) {
    $err = ''; $new = [];
    $type = $p === 'frag' ? 'fragment' : 'noise';
    $pr = hostov_presets()[$p][$val] ?? null;
    if ($pr) {
        if ($p === 'frag' && $pr['tls'] && empty($caps['hello'])) { $err = 'У хоста без TLS нет ClientHello — этот пресет к нему не подходит'; return null; }
        $m = ['type' => $type, 'settings' => $pr['s']];
    } elseif ($val === 'own') {
        $m = is_string($json) ? json_decode($json, true) : (is_array($json) ? $json : null);
        if (!is_array($m) || array_is_list($m)) { $err = 'Нужна одна маска: { "type": "' . $type . '", "settings": { … } }'; return null; }
        foreach ($m as $k => $x) if (!in_array((string) $k, ['type', 'settings'], true)) { $err = 'Лишнее поле «' . $k . '» — у маски только type и settings'; return null; }
        if (($m['type'] ?? null) !== $type) { $err = 'Здесь только маска «' . $type . '». Серверные маски хоста меняются в панели'; return null; }
        if (!is_array($m['settings'] ?? null) || !$m['settings'] || array_is_list($m['settings'])) { $err = 'Нет «settings»'; return null; }
        $m = ['type' => $type, 'settings' => $m['settings']];
    } else { $err = 'Неизвестный пресет'; return null; }
    $ok = $p === 'frag' ? hostov_frag_check($m['settings'], $err, $new) : hostov_noise_check($m['settings'], $err, $new);
    if (!$ok) return null;
    $out = json_encode($m, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($out === false || strlen($out) > 8000) { $err = 'Маска слишком большая'; return null; }
    return $out;
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
            host_proto VARCHAR(16) NOT NULL DEFAULT '', host_pm VARCHAR(255) NOT NULL DEFAULT '',
            param VARCHAR(16) NOT NULL, pv VARCHAR(64) NOT NULL DEFAULT '', val VARCHAR(255) NOT NULL, mval TEXT NULL,
            till INT UNSIGNED NULL, created_at INT UNSIGNED NOT NULL, vat INT UNSIGNED NOT NULL,
            last_hit INT UNSIGNED NULL, last_app VARCHAR(128) NOT NULL DEFAULT '', last_miss INT UNSIGNED NULL, miss_app VARCHAR(128) NOT NULL DEFAULT '',
            na_at INT UNSIGNED NULL, na_app VARCHAR(128) NOT NULL DEFAULT '', na_fmt VARCHAR(16) NOT NULL DEFAULT '',
            app_k VARCHAR(32) NOT NULL DEFAULT '', app_lbl VARCHAR(64) NOT NULL DEFAULT '',
            UNIQUE KEY uq_host_ovr (short_uuid, app_k, host_uuid, param),
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
        host_proto TEXT NOT NULL DEFAULT '', host_pm TEXT NOT NULL DEFAULT '',
        param TEXT NOT NULL, pv TEXT NOT NULL DEFAULT '', val TEXT NOT NULL, mval TEXT NULL,
        till INTEGER NULL, created_at INTEGER NOT NULL, vat INTEGER NOT NULL,
        last_hit INTEGER NULL, last_app TEXT NOT NULL DEFAULT '', last_miss INTEGER NULL, miss_app TEXT NOT NULL DEFAULT '',
        na_at INTEGER NULL, na_app TEXT NOT NULL DEFAULT '', na_fmt TEXT NOT NULL DEFAULT '',
        app_k TEXT NOT NULL DEFAULT '', app_lbl TEXT NOT NULL DEFAULT '',
        UNIQUE (short_uuid, app_k, host_uuid, param)
    )", 'CREATE INDEX IF NOT EXISTS idx_host_ovr_uid ON host_ovr (user_id)'];
}

// Колонки, которых не было в первых версиях таблицы: [MySQL, SQLite].
function hostov_cols_add() {
    $s = static fn($n) => ["VARCHAR($n) NOT NULL DEFAULT ''", "TEXT NOT NULL DEFAULT ''"];
    return [
        'host_apat' => $s(255), 'host_sni' => $s(255), 'host_snipat' => $s(255), 'miss_app' => $s(128),
        'host_proto' => $s(16), 'host_pm' => $s(255), 'mval' => ['TEXT NULL', 'TEXT NULL'],
        'na_at' => ['INT UNSIGNED NULL', 'INTEGER NULL'], 'na_app' => $s(128), 'na_fmt' => $s(16),
        'app_k' => $s(32), 'app_lbl' => $s(64),
    ];
}

// Ключ таблицы: на хост и параметр — одна общая подмена (app_k пуст) и по одной на клиент.
function hostov_key_ok($p, $drv) {
    try {
        if ($drv === 'mysql') {
            $c = [];
            foreach ($p->query("SHOW INDEX FROM host_ovr WHERE Key_name = 'uq_host_ovr'")->fetchAll(PDO::FETCH_ASSOC) as $r) $c[(int) $r['Seq_in_index']] = strtolower((string) $r['Column_name']);
            ksort($c);
            return array_values($c) === ['short_uuid', 'app_k', 'host_uuid', 'param'];
        }
        $st = $p->query("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'host_ovr'");
        $sql = (string) $st->fetchColumn();
        $st->closeCursor();
        return (bool) preg_match('~UNIQUE\s*\(\s*short_uuid\s*,\s*app_k\s*,\s*host_uuid\s*,\s*param\s*\)~i', $sql);
    } catch (Throwable $e) { return false; }
}

// Прежний ключ (пользователь, хост, параметр) меняется на новый. Смотрим на саму таблицу, а не на флаг версии:
// флаг переезжает с настройками при переносе базы и из бэкапа и может с ней не совпасть.
function hostov_key_fix($p, $drv) {
    if (hostov_key_ok($p, $drv)) return;
    if ($drv === 'mysql') {
        $has = false;
        try { $has = (bool) $p->query("SHOW INDEX FROM host_ovr WHERE Key_name = 'uq_host_ovr'")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}
        try { $p->exec('ALTER TABLE host_ovr ' . ($has ? 'DROP INDEX uq_host_ovr, ' : '') . 'ADD UNIQUE KEY uq_host_ovr (short_uuid, app_k, host_uuid, param)'); }
        catch (Throwable $e) { if (!hostov_key_ok($p, $drv)) throw $e; }
        return;
    }
    // SQLite ключ таблицы не меняет — таблица пересобирается. Второй такой же запрос дождётся первого и увидит готовую.
    $p->exec('BEGIN IMMEDIATE');
    try {
        if (!hostov_key_ok($p, $drv)) {
            $cols = static function ($t) use ($p) { $o = []; foreach ($p->query('PRAGMA table_info(' . $t . ')')->fetchAll(PDO::FETCH_ASSOC) as $r) $o[] = (string) $r['name']; return $o; };
            $p->exec('DROP TABLE IF EXISTS host_ovr_new');
            $p->exec(preg_replace('~^CREATE TABLE IF NOT EXISTS host_ovr\b~', 'CREATE TABLE host_ovr_new', hostov_ddl('sqlite')[0]));
            $c = implode(', ', array_values(array_intersect($cols('host_ovr_new'), $cols('host_ovr'))));
            $p->exec("INSERT INTO host_ovr_new ($c) SELECT $c FROM host_ovr");
            $p->exec('DROP TABLE host_ovr');
            $p->exec('ALTER TABLE host_ovr_new RENAME TO host_ovr');
            $p->exec('CREATE INDEX IF NOT EXISTS idx_host_ovr_uid ON host_ovr (user_id)');
        }
        $p->exec('COMMIT');
    } catch (Throwable $e) {
        try { $p->exec('ROLLBACK'); } catch (Throwable $e2) {}
        throw $e;
    }
}

function hostov_ensure($again = false) {
    static $ok = null;
    if ($again) $ok = null;
    if ($ok !== null) return $ok;
    if (!function_exists('db') || !($p = db())) return false;
    if (setting('hostov_table', '') === '4') return $ok = true;
    try {
        $drv = db_driver();
        foreach (hostov_ddl($drv) as $sql) {
            try { $p->exec($sql); } catch (Throwable $e) { if (stripos($sql, 'CREATE TABLE') === 0) throw $e; }
        }
        foreach (hostov_cols_add() as $col => $t) {
            try { $p->query('SELECT ' . $col . ' FROM host_ovr LIMIT 1'); continue; } catch (Throwable $e) {}
            $p->exec('ALTER TABLE host_ovr ADD COLUMN ' . $col . ' ' . $t[$drv === 'mysql' ? 0 : 1]);
        }
        hostov_key_fix($p, $drv);
        $p->query('SELECT id, ' . implode(', ', array_keys(hostov_cols_add())) . ' FROM host_ovr LIMIT 1');
        if (!hostov_key_ok($p, $drv)) throw new RuntimeException('unique key');
        set_setting('hostov_table', '4');
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

function hostov_rows_for($short, $fresh = false) {
    static $memo = [];
    $short = (string) $short;
    if ($short === '' || !hostov_any()) return [];
    if (isset($memo[$short]) && !$fresh) return $memo[$short];
    $out = [];
    try {
        if (hostov_ensure()) {
            $st = db()->prepare('SELECT * FROM host_ovr WHERE short_uuid = ? AND (till IS NULL OR till > ?) ORDER BY id');
            $st->execute([$short, time()]);
            // Значение, которое этому хосту не годится, в выдачу не идёт и отметок «ушла / не было» не получает.
            $out = array_values(array_filter($st->fetchAll(PDO::FETCH_ASSOC), 'hostov_row_ok'));
        }
    } catch (Throwable $e) { error_log('submw host_ovr read: ' . $e->getMessage()); }
    return $memo[$short] = $out;
}

// ---- кому: все устройства пользователя или выбранные ----

// Устройство в запросе узнаётся по клиенту из User-Agent — имени без версии и ОС. Пусто — клиент не распознан: ему только общие подмены.
// Ключ сравнивается целиком: длинное имя не обрезается до общего с другим клиентом начала, а сворачивается с хешем.
function hostov_client_key($ua) {
    $c = function_exists('reqlog_client') ? reqlog_client((string) $ua) : [];
    $k = strtolower(trim((string) ($c['key'] ?? '')));
    if (!preg_match('~^[a-z0-9._/-]+$~', $k)) return '';
    return strlen($k) <= 32 ? $k : substr($k, 0, 24) . '-' . substr(md5($k), 0, 7);
}

// Можно ли отправить параметр клиенту с таким ядром: пусто — да, иначе причина. Маски Xray не понимают mihomo и sing-box.
function hostov_fits($param, $core) {
    if ($param !== 'frag' && $param !== 'noise') return '';
    $core = (string) $core;
    if ($core !== 'mihomo' && $core !== 'sing-box') return '';
    return $core . ': ' . ($param === 'frag' ? 'фрагментации' : 'шума') . ' там нет';
}

function hostov_core($ua) {
    return function_exists('squadconf_ua_core') ? (string) squadconf_ua_core((string) $ua) : '';
}

// Устройство пользователя из панели для выбора «кому»: k — ключ клиента, l — клиент без версии, a — с версией,
// no — параметры, которые его ядру не отправить.
function hostov_dev_info($ua) {
    $ua = trim((string) $ua);
    $c = function_exists('reqlog_client') ? reqlog_client($ua) : [];
    $a = (string) ($c['app'] ?? ''); $v = (string) ($c['ver'] ?? '');
    $l = $v !== '' && substr($a, -strlen($v) - 1) === ' ' . $v ? substr($a, 0, -strlen($v) - 1) : $a;
    $k = hostov_client_key($ua);
    $no = [];
    foreach (['frag', 'noise'] as $p) if (($w = hostov_fits($p, hostov_core($ua))) !== '') $no[$p] = $w;
    return ['k' => $k, 'l' => $k !== '' ? mb_substr($l, 0, 64) : '', 'a' => $k !== '' ? mb_substr($a, 0, 64) : '', 'no' => (object) $no];
}

// Подмены для этого запроса: общие и своего клиента; на один хост и параметр подмена клиента важнее общей.
function hostov_pick(array $rows, $ua) {
    $any = false;
    foreach ($rows as $r) if ((string) ($r['app_k'] ?? '') !== '') { $any = true; break; }
    if (!$any) return $rows;
    $k = hostov_client_key($ua);
    $own = [];
    if ($k !== '') foreach ($rows as $r) if ((string) $r['app_k'] === $k) $own[$r['host_uuid'] . "\0" . $r['param']] = true;
    $out = [];
    foreach ($rows as $r) {
        $a = (string) ($r['app_k'] ?? '');
        if ($a === '' ? !isset($own[$r['host_uuid'] . "\0" . $r['param']]) : ($k !== '' && $a === $k)) $out[] = $r;
    }
    return $out;
}

// [подмены хостов, подмены всей подписки]
function hostov_split(array $rows) {
    $h = []; $s = [];
    foreach ($rows as $r) { if ((string) ($r['param'] ?? '') === 'log') $s[] = $r; else $h[] = $r; }
    return [$h, $s];
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
    $m = ['raw' => 'tcp', 'http' => 'tcp', 'splithttp' => 'xhttp', 'xhttp3' => 'xhttp', 'mkcp' => 'kcp', 'hysteria2' => 'hysteria'];
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
        $proto = (string) ($row['host_proto'] ?? '');
        $g = [];
        foreach ($nodes as $i => $nd) {
            if ((int) $nd['p'] !== $port || !hostov_pat_ok($nd['a'], $pat)) continue;
            if ($sec !== '' && (string) $nd['sec'] !== $sec) continue;
            if ($proto !== '' && (string) ($nd['proto'] ?? '') !== '' && (string) $nd['proto'] !== $proto) continue;
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

// Попадание: [id подмены, имя узла, было, стало, параметр, не применима (1 — в этой выдаче параметра нет), значения маски, формат,
// когда подмена создана, 1 — подмена не для всех устройств].
function hostov_hit(array $r, $name, $old, $na = 0, $fmt = '') {
    $p = (string) ($r['param'] ?? 'fp');
    $plain = $p === 'fp' || $p === 'log';
    return [(int) $r['id'], (string) $name, (string) $old, $plain ? (string) $r['val'] : hostov_preset_label($p, (string) $r['val']),
            $p, $na ? 1 : 0, $plain ? '' : hostov_mask_txt($p, hostov_mask_of($r)), (string) $fmt,
            (int) ($r['created_at'] ?? 0), (string) ($r['app_k'] ?? '') !== '' ? 1 : 0];
}

// Попадание для лога запросов: [имя узла, было, стало, параметр, не применима, значения маски, формат, не для всех устройств, когда создана, id].
function hostov_log_row(array $h) {
    return [$h[1], $h[2], $h[3], $h[4] ?? 'fp', $h[5] ?? 0, mb_substr((string) ($h[6] ?? ''), 0, 120), $h[7] ?? '', (int) ($h[9] ?? 0), (int) ($h[8] ?? 0), (int) ($h[0] ?? 0)];
}

function hostov_by_param(array $rows) {
    $g = [];
    foreach ($rows as $r) $g[(string) ($r['param'] ?? 'fp')][] = $r;
    return $g;
}

// Возвращает [тело, попадания]. Ошибка разбора — тело как было. $ua — клиент запроса.
function hostov_apply($body, $format, array $rows, $ua = '') {
    $rows = array_values(array_filter($rows, static fn($r) => (string) ($r['param'] ?? '') !== 'log' && hostov_row_ok($r)));
    if (!$rows || !is_string($body) || $body === '') return [$body, []];
    try {
        // Подмена, поставленная на устройство, не уходит клиенту, чьё ядро этот параметр не понимает, — в каком бы формате он ни получил подписку.
        $skip = [];
        $core = hostov_core($ua);
        foreach ($rows as $i => $r) {
            if ((string) ($r['app_k'] ?? '') === '' || hostov_fits((string) $r['param'], $core) === '') continue;
            $skip[] = hostov_hit($r, (string) $r['host_name'], '', 1, $core);
            unset($rows[$i]);
        }
        $rows = array_values($rows);
        if (!$rows) return [$body, $skip];
        $kind = function_exists('squadconf_body_kind') ? squadconf_body_kind($body, $format) : ($format === 'clash' ? 'clash' : 'links');
        if ($kind === 'clash' || $kind === 'singbox') {
            $fp = hostov_by_param($rows)['fp'] ?? [];
            [$out, $hits] = $fp ? ($kind === 'clash' ? hostov_apply_clash($body, $fp) : hostov_apply_singbox($body, $fp)) : [$body, []];
            // Маски Xray (фрагментация, шум) в форматы mihomo и sing-box не попадают — «не применима», а не «хоста не было».
            foreach ($rows as $r) if ($r['param'] !== 'fp') $hits[] = hostov_hit($r, (string) $r['host_name'], '', 1, $kind === 'clash' ? 'mihomo' : 'sing-box');
            return [$out, array_merge($hits, $skip)];
        }
        [$out, $hits] = $kind === 'links' ? hostov_apply_links($body, $rows) : hostov_apply_xray($body, $rows);
        return [$out, array_merge($hits, $skip)];
    } catch (Throwable $e) {
        error_log('submw host_ovr apply: ' . $e->getMessage());
        return [$body, []];
    }
}

// sing-box незнакомых ключей не принимает, поэтому его конфиг узнаётся и без route / inbounds: у исходящих «type», а не «protocol».
function hostov_is_singbox($o) {
    if (!is_object($o) || !isset($o->outbounds) || !is_array($o->outbounds) || !isset($o->outbounds[0]) || !is_object($o->outbounds[0])) return false;
    return isset($o->outbounds[0]->type) && !isset($o->outbounds[0]->protocol);
}

// Уровень логов — в готовом теле, после доп. конфигов и слияния со второй подпиской. Ставится только в распознанный конфиг,
// ключом и словом его ядра; всё остальное уходит как пришло. «Было» — в общей шкале (off — логи выключены).
function hostov_apply_log($body, $format, array $rows) {
    $row = null;
    foreach ($rows as $r) if ((string) ($r['param'] ?? '') === 'log' && hostov_row_ok($r)) { $row = $r; break; }
    if ($row === null || !is_string($body) || $body === '') return [$body, []];
    $v = (string) $row['val'];
    try {
        $kind = function_exists('squadconf_body_kind') ? squadconf_body_kind($body, $format) : ($format === 'clash' ? 'clash' : 'links');
        if ($kind === 'clash') {
            // Формат «clash» бывает назван по клиенту, а не по телу: строку получает только конфиг mihomo.
            if (!preg_match('~^(proxies|proxy-providers|proxy-groups)[ \t]*:~m', $body)) return [$body, []];
            $val = $v === 'off' ? 'silent' : $v;
            if (preg_match('~^log-level:[ \t]*([^\r\n]*)~m', $body, $m, PREG_OFFSET_CAPTURE)) {
                $old = strtolower(hostov_yaml_scalar($m[1][0]));
                $out = substr($body, 0, $m[0][1]) . 'log-level: ' . $val . substr($body, $m[0][1] + strlen($m[0][0]));
            } else {
                $old = '';
                $eol = strpos($body, "\r\n") !== false ? "\r\n" : "\n";
                preg_match('~^(?:\xEF\xBB\xBF)?(?:---[ \t]*\r?\n)?~', $body, $d);
                $at = strlen($d[0] ?? '');
                $out = substr($body, 0, $at) . 'log-level: ' . $val . $eol . substr($body, $at);
            }
            return [$out, [hostov_hit($row, '', $old === 'silent' ? 'off' : $old)]];
        }
        if ($kind === 'links') {
            // Ссылки — настроек ядра в них нет. Тело, которое и не ссылки, остаётся без отметки.
            $t = trim($body);
            $d = strpos($t, '://') === false ? base64_decode(preg_replace('~\s+~', '', $t), true) : $t;
            return [$body, is_string($d) && strpos($d, '://') !== false ? [hostov_hit($row, '', '', 1, 'ссылки')] : []];
        }
        $o = json_decode($body);
        if ($kind === 'singbox' || hostov_is_singbox($o)) {
            if (!hostov_is_singbox($o)) return [$body, []];
            $lg = isset($o->log) && is_object($o->log) ? $o->log : null;
            $old = !$lg ? '' : (!empty($lg->disabled) ? 'off' : strtolower((string) ($lg->level ?? '')));
            if (!$lg) { $lg = new stdClass(); $o->log = $lg; }
            if ($v === 'off') $lg->disabled = true;
            else { $lg->level = $v === 'warning' ? 'warn' : $v; $lg->disabled = false; }
            $out = json_encode($o, hostov_json_flags($body));
            return $out === false ? [$body, []] : [$out, [hostov_hit($row, '', $old === 'warn' ? 'warning' : $old)]];
        }
        $list = is_array($o) ? $o : (is_object($o) ? [$o] : null);
        if ($list === null) return [$body, []];
        $n = 0; $old = '';
        foreach ($list as $cfg) {
            if (!is_object($cfg) || !isset($cfg->outbounds) || !is_array($cfg->outbounds) || hostov_is_singbox($cfg)) continue;
            $lg = isset($cfg->log) && is_object($cfg->log) ? $cfg->log : null;
            if (!$lg) { $lg = new stdClass(); $cfg->log = $lg; }
            // Xray читает ключ без учёта регистра: шаблон с «logLevel» получил бы два ключа.
            $key = 'loglevel';
            foreach (array_keys(get_object_vars($lg)) as $k) if (strtolower((string) $k) === 'loglevel') { $key = (string) $k; break; }
            if (!$n) $old = strtolower(is_string($lg->$key ?? null) ? $lg->$key : '');
            $lg->$key = $v === 'off' ? 'none' : $v;
            $n++;
        }
        if (!$n) return [$body, []];
        $out = json_encode(is_array($o) ? $list : $list[0], hostov_json_flags($body));
        return $out === false ? [$body, []] : [$out, [hostov_hit($row, '', $old === 'none' ? 'off' : $old)]];
    } catch (Throwable $e) {
        error_log('submw host_ovr log: ' . $e->getMessage());
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

function hostov_proto($p) {
    $p = strtolower((string) $p);
    return ['shadowsocks' => 'ss', 'hy2' => 'hysteria2', 'hysteria' => 'hysteria2'][$p] ?? $p;
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
    $hy = $scheme === 'hysteria2' || $scheme === 'hy2';
    $sec = $scheme === 'trojan' ? 'tls' : 'none';
    if (isset($prm['security'])) $sec = strtolower($prm['security']);
    if ($hy) $sec = 'quic';
    $frag = $hp === false ? '' : substr($ln, $hp + 1);
    $fq = strpos($frag, '?');
    return ['scheme' => $scheme, 'n' => trim(rawurldecode($fq === false ? $frag : substr($frag, 0, $fq))), 'a' => $a, 'p' => $p, 'sec' => $sec,
            'fp' => (string) ($prm['fp'] ?? ''), 'sni' => (string) ($prm['sni'] ?? ''), 'type' => $hy ? 'hysteria' : strtolower((string) ($prm['type'] ?? 'tcp')),
            'alpn' => (string) ($prm['alpn'] ?? ''), 'fm' => (string) ($prm['fm'] ?? ''), 'extra' => (string) ($prm['extra'] ?? ''),
            'main' => $main, 'q' => $q, 'qp' => $qp, 'frag' => $hp === false ? '' : substr($ln, $hp)];
}

// Ссылка с другим набором параметров: $fn получает [[ключ, сырое значение|null], …] в исходном порядке.
function hostov_link_edit($ln, callable $fn) {
    $x = hostov_link_parse($ln);
    $kv = [];
    foreach ($x['q'] === '' ? [] : explode('&', $x['q']) as $part) {
        $e = strpos($part, '=');
        $kv[] = $e === false ? [$part, null] : [substr($part, 0, $e), substr($part, $e + 1)];
    }
    $kv = $fn($kv);
    $base = $x['qp'] === false ? $x['main'] : substr($x['main'], 0, $x['qp']);
    return $base . ($kv ? '?' . implode('&', array_map(static fn($p) => $p[1] === null ? $p[0] : $p[0] . '=' . $p[1], $kv)) : '') . $x['frag'];
}

// Узел по UDP: mKCP, Hysteria, XHTTP по HTTP/3 (ALPN хоста — ровно h3; так решает и Xray, decideHTTPVersion).
function hostov_alpn_h3($alpn) {
    $l = is_array($alpn) ? $alpn : (is_string($alpn) && $alpn !== '' ? explode(',', $alpn) : []);
    return count($l) === 1 && trim((string) $l[0]) === 'h3';
}

function hostov_ss_udp($ss) {
    if (!is_object($ss)) return false;
    $net = hostov_tr_norm((string) ($ss->network ?? 'tcp'));
    if ($net === 'kcp' || $net === 'hysteria') return true;
    return $net === 'xhttp' && (string) ($ss->security ?? '') === 'tls' && hostov_alpn_h3($ss->tlsSettings->alpn ?? null);
}

// Своя маска в finalmask: fragment — первым в tcp, вместо фрагментации панели (иначе с серверной маской он молча не работает);
// noise — в udp последним, но перед масками, которые сами ставят соединение (udphop, xicmp). Серверные маски не трогаются.
// Возвращает значения прежней такой же маски из панели ('' — её не было).
function hostov_fm_put($fm, $p, array $mask) {
    $k = $p === 'frag' ? 'tcp' : 'udp';
    $type = (string) $mask['type'];
    $list = isset($fm->$k) && is_array($fm->$k) ? $fm->$k : [];
    $old = ''; $keep = [];
    foreach ($list as $m) {
        if (is_object($m) && strtolower((string) ($m->type ?? '')) === $type) {
            $old = hostov_mask_txt($p, json_decode(json_encode($m->settings ?? new stdClass()), true)) ?: $type;
            continue;
        }
        $keep[] = $m;
    }
    $mine = json_decode(json_encode($mask, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    if ($p === 'frag') {
        array_unshift($keep, $mine);
    } else {
        $at = count($keep);
        foreach ($keep as $i => $m) if (is_object($m) && in_array(strtolower((string) ($m->type ?? '')), ['udphop', 'xicmp'], true)) { $at = $i; break; }
        array_splice($keep, $at, 0, [$mine]);
    }
    $fm->$k = $keep;
    return $old;
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
        if ($x === null) continue;
        $nodes[] = ['names' => [$x['n']], 'named' => true, 'a' => $x['a'], 'p' => $x['p'], 'sec' => $x['sec'], 'tr' => $x['type'], 'sni' => $x['sni'], 'g' => '', 'proto' => hostov_proto($x['scheme'])];
        $info[] = ['li' => $li, 'cr' => $ln !== $line, 'scheme' => $x['scheme'], 'n' => $x['n'], 'sec' => $x['sec']];
    }
    $hits = []; $changed = false;
    foreach (hostov_by_param($rows) as $p => $prow) {
        foreach (hostov_assign($nodes, $prow) as $i => $row) {
            $nf = $info[$i];
            $ln = rtrim($lines[$nf['li']], "\r");
            $x = hostov_link_parse($ln);
            $old = null;
            if ($p === 'fp') {
                if (($x['scheme'] !== 'vless' && $x['scheme'] !== 'trojan') || ($x['sec'] !== 'tls' && $x['sec'] !== 'reality')) continue;
                $v = (string) $row['val'];
                $old = $x['fp'];
                $ln = hostov_link_edit($ln, static function ($kv) use ($v, $x) {
                    $set = false;
                    foreach ($kv as $k => [$key, $raw]) {
                        if ($key === 'fp') { $kv[$k][1] = rawurlencode($v); $set = true; }
                        elseif ($key === 'extra' && $raw !== null) {
                            $ex = json_decode(rawurldecode($raw));
                            if (is_object($ex) && isset($ex->downloadSettings) && hostov_dl_fp($ex->downloadSettings, (string) ($ex->downloadSettings->security ?? $x['sec']), $v)) {
                                $kv[$k][1] = rawurlencode(json_encode($ex, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                            }
                        }
                    }
                    if (!$set) $kv[] = ['fp', rawurlencode($v)];
                    return $kv;
                });
            } else {
                // В ссылке ss:// параметров нет — маска туда не попадает.
                if ($x['scheme'] === 'ss') { $hits[] = hostov_hit($row, $x['n'], '', 1, 'ss://'); continue; }
                $udp = $x['type'] === 'hysteria' || $x['type'] === 'kcp' || ($x['type'] === 'xhttp' && $x['sec'] === 'tls' && hostov_alpn_h3($x['alpn']));
                if ($p === 'frag' ? $udp : !$udp) continue;
                $fm = $x['fm'] === '' ? new stdClass() : json_decode($x['fm']);
                if (!is_object($fm)) continue;
                $mask = hostov_mask_of($row);
                $old = hostov_fm_put($fm, $p, $mask);
                $ln = hostov_link_edit($ln, static function ($kv) use ($fm, $mask, $p) {
                    $enc = rawurlencode(json_encode($fm, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                    $set = false;
                    foreach ($kv as $k => [$key, $raw]) {
                        if ($key === 'fm') { $kv[$k][1] = $enc; $set = true; }
                        elseif ($key === 'extra' && $raw !== null) {
                            $ex = json_decode(rawurldecode($raw));
                            $dl = is_object($ex) ? ($ex->downloadSettings ?? null) : null;
                            if (is_object($dl) && ($p === 'frag' ? !hostov_ss_udp($dl) : hostov_ss_udp($dl))) {
                                if (!isset($dl->finalmask) || !is_object($dl->finalmask)) $dl->finalmask = new stdClass();
                                hostov_fm_put($dl->finalmask, $p, $mask);
                                $kv[$k][1] = rawurlencode(json_encode($ex, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                            }
                        }
                    }
                    if (!$set) $kv[] = ['fm', $enc];
                    return $kv;
                });
            }
            $lines[$nf['li']] = $ln . ($nf['cr'] ? "\r" : '');
            $changed = true;
            $hits[] = hostov_hit($row, $x['n'], $old);
        }
    }
    if (!$changed) return [$body, $hits];
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
        $hits[] = hostov_hit($row, (string) ($nodes[$i]['names'][0] ?? ''), $old);
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
        $hits[] = hostov_hit($row, $nodes[$i]['names'][0], $old);
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

function hostov_xray_mask($ob, $p, array $mask) {
    if (!is_object($ob)) return null;
    $proto = hostov_proto($ob->protocol ?? '');
    if (!in_array($proto, ['vless', 'vmess', 'trojan', 'ss', 'hysteria2'], true)) return null;
    if (!isset($ob->streamSettings) || !is_object($ob->streamSettings)) $ob->streamSettings = new stdClass();
    $ss = $ob->streamSettings;
    $udp = $proto === 'hysteria2' || hostov_ss_udp($ss);
    if ($p === 'frag' ? $udp : !($udp || $proto === 'ss')) return null;
    if (!isset($ss->finalmask) || !is_object($ss->finalmask)) $ss->finalmask = new stdClass();
    $old = hostov_fm_put($ss->finalmask, $p, $mask);
    // У XHTTP загрузка может идти отдельным соединением со своими streamSettings — маска и туда, если подходит по транспорту.
    $dl = $ss->xhttpSettings->extra->downloadSettings ?? null;
    if (is_object($dl) && ($p === 'frag' ? !hostov_ss_udp($dl) : hostov_ss_udp($dl))) {
        if (!isset($dl->finalmask) || !is_object($dl->finalmask)) $dl->finalmask = new stdClass();
        hostov_fm_put($dl->finalmask, $p, $mask);
    }
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
            if (!is_object($ob)) continue;
            $proto = hostov_proto($ob->protocol ?? '');
            if (!in_array($proto, ['vless', 'vmess', 'trojan', 'ss', 'hysteria2'], true)) continue;
            [$a, $p] = hostov_xray_addr($ob);
            [$sec, $tr, $sni] = hostov_xray_sec($ob);
            if ($proto === 'hysteria2') $sec = 'quic';
            $tag = is_string($ob->tag ?? null) ? $ob->tag : '';
            $names = [$tag];
            if ($tag === 'proxy' && is_string($cfg->remarks ?? null)) $names[] = $cfg->remarks;
            $nodes[] = ['names' => $names, 'named' => false, 'a' => $a, 'p' => $p, 'sec' => $sec, 'tr' => $tr, 'sni' => $sni, 'g' => (string) $ci, 'proto' => $proto];
            $obs[] = [$ob, $tag === 'proxy' && is_string($cfg->remarks ?? null) ? $cfg->remarks : $tag];
        }
    }
    $hits = [];
    foreach (hostov_by_param($rows) as $p => $prow) {
        foreach (hostov_assign($nodes, $prow) as $i => $row) {
            $old = $p === 'fp' ? hostov_xray_set($obs[$i][0], (string) $row['val']) : hostov_xray_mask($obs[$i][0], $p, hostov_mask_of($row));
            if ($old !== null) $hits[] = hostov_hit($row, (string) $obs[$i][1], $old);
        }
    }
    if (!$hits) return [$body, []];
    $out = json_encode(is_array($o) ? $list : $list[0], hostov_json_flags($body));
    return $out === false ? [$body, []] : [$out, $hits];
}

// Пометка в логе запросов: весь meta строки лога режется на 500 символов, поэтому подмены укладываются в свой бюджет —
// сначала без значений маски, потом сколько влезет; «c» — сколько всего.
function hostov_log_meta(array $log_hv, $budget = 340) {
    if (!$log_hv) return null;
    $f = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    $len = static fn($h) => mb_strlen((string) json_encode(['c' => count($log_hv), 'h' => $h], $f));
    // Последняя созданная подмена — первой: в карточке запроса на виду она, остальные свёрнуты.
    $log_hv = array_values($log_hv);
    $ord = array_keys($log_hv);
    usort($ord, static fn($a, $b) => ((int) ($log_hv[$b][8] ?? 0) <=> (int) ($log_hv[$a][8] ?? 0)) ?: ((int) ($log_hv[$b][9] ?? 0) <=> (int) ($log_hv[$a][9] ?? 0)) ?: ($a <=> $b));
    $h = [];
    foreach ($ord as $oi) {
        $x = array_values($log_hv[$oi]);
        $own = !empty($x[7]);
        if (($x[3] ?? 'fp') === 'fp' && !$own) $x = array_slice($x, 0, 3);
        else {
            $x = [mb_substr((string) $x[0], 0, 48), mb_substr((string) $x[1], 0, 60), (string) $x[2], (string) ($x[3] ?? 'fp'), (int) ($x[4] ?? 0), mb_substr((string) ($x[5] ?? ''), 0, 90), (string) ($x[6] ?? '')];
            if ($own) $x[] = 1;
        }
        $x[0] = mb_substr((string) $x[0], 0, 48);
        $try = array_merge($h, [$x]);
        if ($len($try) > $budget && count($x) > 3) { $x[5] = ''; $x[1] = $x[1] !== '' ? '…' : ''; $try = array_merge($h, [$x]); }
        if ($len($try) > $budget) break;
        $h = $try;
    }
    return ['c' => count($log_hv), 'h' => $h];
}

function hostov_note(array $rows, array $hits, $app) {
    if (!$rows) return;
    $hit = []; $na = [];
    foreach ($hits as $h) {
        if (!empty($h[5])) $na[(int) $h[0]] = (string) ($h[7] ?? '');
        else $hit[(int) $h[0]] = (string) ($h[2] ?? '');
    }
    $now = time();
    $app = mb_substr((string) $app, 0, 128);
    try {
        $p = db();
        $up = $p->prepare('UPDATE host_ovr SET last_hit = ?, last_app = ? WHERE id = ?');
        $um = $p->prepare('UPDATE host_ovr SET last_miss = ?, miss_app = ? WHERE id = ?');
        $un = $p->prepare('UPDATE host_ovr SET na_at = ?, na_app = ?, na_fmt = ? WHERE id = ?');
        // Что было в шаблоне панели, у уровня логов видно только из самой выдачи.
        $ul = $p->prepare('UPDATE host_ovr SET last_hit = ?, last_app = ?, pv = ? WHERE id = ?');
        foreach ($rows as $r) {
            $id = (int) $r['id'];
            if (isset($hit[$id]) && (string) ($r['param'] ?? '') === 'log') $ul->execute([$now, $app, mb_substr($hit[$id], 0, 64), $id]);
            elseif (isset($hit[$id])) $up->execute([$now, $app, $id]);
            elseif (isset($na[$id])) $un->execute([$now, $app, mb_substr($na[$id], 0, 16), $id]);
            else $um->execute([$now, $app, $id]);
        }
    } catch (Throwable $e) { error_log('submw host_ovr note: ' . $e->getMessage()); }
}

// ---- админка ----

// exp — срок вышел; inv — значение не годится для этого хоста (не применяется); wait — после изменения ещё не уходила;
// ok — ушла; part — ушла, но в последней выдаче (другое приложение или формат) хоста не было;
// na — в последней выдаче параметра нет: формат без масок Xray (mihomo, sing-box, ss://), ссылки для уровня логов, не то ядро у клиента;
// bad — хоста не было, а уходила давно или ни разу.
function hostov_state(array $r, $now = null) {
    $now = $now ?? time();
    if ($r['till'] !== null && (int) $r['till'] <= $now) return 'exp';
    if (!hostov_row_ok($r)) return 'inv';
    $vat = (int) ($r['vat'] ?? 0);
    $lh = $r['last_hit'] !== null && (int) $r['last_hit'] >= $vat ? (int) $r['last_hit'] : null;
    $lm = $r['last_miss'] !== null && (int) $r['last_miss'] >= $vat ? (int) $r['last_miss'] : null;
    $na = ($r['param'] ?? 'fp') !== 'fp' && ($r['na_at'] ?? null) !== null && (int) $r['na_at'] >= $vat ? (int) $r['na_at'] : null;
    // При равенстве секунд — в пользу выдачи с подменой (как и с «хоста не было»).
    if ($na !== null && $na > (int) $lh && $na >= (int) $lm) return 'na';
    if ($lh === null) return $lm === null ? 'wait' : 'bad';
    if ($lm !== null && $lm > $lh) return $now - $lh > HOSTOV_STALE ? 'bad' : 'part';
    return 'ok';
}

function hostov_view(array $r) {
    $p = (string) $r['param'];
    $plain = $p === 'fp' || $p === 'log';
    $m = $plain ? null : hostov_mask_of($r);
    $pm = json_decode((string) ($r['host_pm'] ?? ''), true);
    return [
        'id' => (int) $r['id'], 'su' => (string) $r['short_uuid'], 'un' => (string) $r['username'], 'h' => (string) $r['host_uuid'],
        'n' => (string) $r['host_name'], 'sec' => (string) $r['host_sec'], 'tr' => hostov_tr_label($r['host_tr']), 'trk' => strtolower((string) $r['host_tr']),
        'proto' => (string) ($r['host_proto'] ?? ''), 'pm' => is_array($pm) ? $pm : null, 'p' => $p,
        'pv' => (string) $r['pv'], 'v' => (string) $r['val'], 'vn' => $plain ? (string) $r['val'] : hostov_preset_label($p, (string) $r['val']),
        'm' => $m, 'mt' => $m ? hostov_mask_txt($p, $m) : '', 'till' => $r['till'] !== null ? (int) $r['till'] : null,
        'at' => (int) $r['vat'], 'lh' => $r['last_hit'] !== null ? (int) $r['last_hit'] : null, 'la' => (string) $r['last_app'],
        'lm' => $r['last_miss'] !== null ? (int) $r['last_miss'] : null, 'ma' => (string) ($r['miss_app'] ?? ''),
        'nt' => ($r['na_at'] ?? null) !== null ? (int) $r['na_at'] : null, 'nap' => (string) ($r['na_app'] ?? ''), 'naf' => (string) ($r['na_fmt'] ?? ''),
        'st' => hostov_state($r), 'ak' => (string) ($r['app_k'] ?? ''), 'al' => (string) ($r['app_lbl'] ?? ''), 'cr' => (int) ($r['created_at'] ?? 0),
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

function hostov_param_name($p) {
    return ['fp' => 'Отпечаток TLS', 'frag' => 'Фрагментация', 'noise' => 'Шум', 'log' => 'Уровень логов'][$p] ?? $p;
}

// Клиенты из админки: [ключ => подпись] или null, если ключ не похож на имя клиента.
function hostov_keys_norm(array $apps) {
    $o = [];
    foreach ($apps as $a) {
        $k = is_array($a) ? (string) ($a['k'] ?? '') : (string) $a;
        if (!preg_match('~^[a-z0-9._/-]{1,32}$~', $k)) return null;
        $o[$k] = mb_substr(trim(is_array($a) ? (string) ($a['l'] ?? '') : ''), 0, 64);
    }
    return $o;
}

// Параметр, который ядру клиента не отправить, на его устройства не ставится: пусто — можно, иначе причина.
function hostov_keys_unfit($param, array $keys) {
    foreach ($keys as $k => $l) {
        $w = hostov_fits((string) $param, hostov_core((string) $k));
        if ($w !== '') return ((string) $l !== '' ? $l : $k) . ' — ' . $w;
    }
    return '';
}

// $h — хост из hostov_hosts (что пришло из админки); возможности хоста считаются здесь заново, а не берутся на веру.
// У уровня логов хоста нет. $apps пуст — общая подмена, иначе по строке на клиент.
function hostov_save($short, array $h, $param, $val, $till, $uid, $username, &$err, $mask = null, &$warn = null, array $apps = []) {
    $err = ''; $warn = '';
    $short = trim((string) $short);
    $param = (string) $param;
    $sub = $param === 'log';
    $huuid = $sub ? '' : trim((string) ($h['uuid'] ?? ''));
    if ($short === '' || (!$sub && $huuid === '')) { $err = 'Не указан пользователь или хост'; return false; }
    $keys = hostov_keys_norm($apps);
    if ($keys === null) { $err = 'Устройство не распознано'; return false; }
    if (($w = hostov_keys_unfit($param, $keys)) !== '') { $err = $w; return false; }
    $cut = static fn($s, $n) => mb_substr((string) $s, 0, $n);
    $mv = null;
    $f = ['user_id' => (int) $uid > 0 ? (int) $uid : null, 'username' => $cut($username, 64)];
    if ($sub) {
        if (!in_array((string) $val, hostov_log_values(), true)) { $err = 'Недопустимое значение'; return false; }
    } else {
        if (!in_array($param, ['fp', 'frag', 'noise'], true) || (int) ($h['port'] ?? 0) <= 0) { $err = 'Неизвестный параметр'; return false; }
        $proto = hostov_proto($h['proto'] ?? '');
        $trk = strtolower((string) ($h['trk'] ?? ''));
        $caps = hostov_caps((string) ($h['sec'] ?? ''), $trk, $proto);
        if ($param === 'fp' && (!in_array((string) ($h['sec'] ?? ''), ['tls', 'reality'], true) || ($proto !== '' && empty($caps['fp'])))) { $err = 'У хоста нет отпечатка TLS'; return false; }
        if ($param !== 'fp' && empty($caps[$param])) { $err = $param === 'frag' ? 'Фрагментация — только для хостов по TCP' : 'Шум — только для хостов по UDP: mKCP, Hysteria2, XHTTP/3, Shadowsocks'; return false; }
        if ($param === 'fp') {
            if (!in_array((string) $val, hostov_fp_values((string) $h['sec']), true)) { $err = (string) $h['sec'] === 'reality' ? 'С REALITY работают только chrome, firefox, safari и randomized' : 'Недопустимое значение'; return false; }
        } else {
            $mv = hostov_mask_build($param, (string) $val, $mask, $caps, $err, $new);
            if ($mv === null) return false;
            $warn = hostov_mask_warn($new);
        }
        $pm = is_array($h['pm'] ?? null) ? json_encode(['tcp' => array_values(array_map('strval', (array) ($h['pm']['tcp'] ?? []))), 'udp' => array_values(array_map('strval', (array) ($h['pm']['udp'] ?? [])))], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '';
        $pv = $param === 'fp' ? (string) ($h['fp'] ?? '') : ($param === 'frag' ? (string) ($h['pfrag'] ?? '') : (string) ($h['pnoise'] ?? ''));
        $f += [
            'host_name' => $cut($h['name'] ?? '', 255), 'host_rmk' => $cut($h['rmk'] ?? '', 255), 'host_addr' => $cut($h['addr'] ?? '', 255),
            'host_apat' => $cut($h['apat'] ?? '', 255), 'host_port' => (int) ($h['port'] ?? 0), 'host_sec' => $cut($h['sec'] ?? '', 16),
            'host_tr' => $cut($trk, 16), 'host_sni' => $cut($h['sni'] ?? '', 255), 'host_snipat' => $cut($h['snipat'] ?? '', 255),
            'host_proto' => $cut($proto, 16), 'host_pm' => strlen($pm) <= 255 ? $pm : '', 'pv' => $cut($pv, 64),
        ];
    }
    if (!hostov_ensure()) { $err = 'Нет таблицы подмен'; return false; }
    $now = time();
    $p = db();
    try {
        $p->beginTransaction();
        $st = $p->prepare('SELECT id, val, mval, vat FROM host_ovr WHERE short_uuid = ? AND app_k = ? AND host_uuid = ? AND param = ?');
        foreach ($keys ?: ['' => ''] as $k => $l) {
            $k = (string) $k;
            $g = $f + ['app_lbl' => $l];
            $cols = array_keys($g);
            $st->execute([$short, $k, $huuid, $param]);
            $ex = $st->fetch(PDO::FETCH_ASSOC);
            $st->closeCursor();
            if ($ex) {
                $same = (string) $ex['val'] === (string) $val && (string) ($ex['mval'] ?? '') === (string) $mv;
                $p->prepare('UPDATE host_ovr SET ' . implode(' = ?, ', $cols) . ' = ?, val = ?, mval = ?, till = ?, vat = ? WHERE id = ?')
                  ->execute(array_merge(array_values($g), [(string) $val, $mv, hostov_till($till), $same ? (int) ($ex['vat'] ?? $now) : $now, (int) $ex['id']]));
            } else {
                $p->prepare('INSERT INTO host_ovr (short_uuid, app_k, host_uuid, param, ' . implode(', ', $cols) . ', val, mval, till, created_at, vat) VALUES (' . implode(', ', array_fill(0, count($cols) + 9, '?')) . ')')
                  ->execute(array_merge([$short, $k, $huuid, $param], array_values($g), [(string) $val, $mv, hostov_till($till), $now, $now]));
            }
        }
        $p->commit();
    } catch (Throwable $e) {
        try { if ($p->inTransaction()) $p->rollBack(); } catch (Throwable $e2) {}
        $err = 'База не приняла запись'; error_log('submw host_ovr save: ' . $e->getMessage()); return false;
    }
    hostov_recount();
    return true;
}

// Смена «кому» у подмены: $ids — её строки (общая или по одной на клиент), $apps — новый список, пусто — общая.
// Значение, срок и время создания остаются; у новых строк отметки «ушла» начинаются заново.
function hostov_rescope(array $ids, array $apps, &$err) {
    $err = '';
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    $keys = hostov_keys_norm($apps);
    if (!$ids) { $err = 'Подмены уже нет'; return false; }
    if ($keys === null) { $err = 'Устройство не распознано'; return false; }
    if (!hostov_ensure()) { $err = 'Нет таблицы подмен'; return false; }
    $p = db();
    try {
        $rows = $p->query('SELECT * FROM host_ovr WHERE id IN (' . implode(',', $ids) . ') ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) { $err = 'Подмены уже нет'; return false; }
        $b = $rows[0];
        $have = [];
        foreach ($rows as $r) {
            foreach (['short_uuid', 'host_uuid', 'param', 'val', 'mval'] as $c) if ((string) ($r[$c] ?? '') !== (string) ($b[$c] ?? '')) { $err = 'Это разные подмены'; return false; }
            $have[(string) ($r['app_k'] ?? '')] = (int) $r['id'];
        }
        $want = $keys ?: ['' => ''];
        if (($w = hostov_keys_unfit((string) $b['param'], array_diff_key($keys, $have))) !== '') { $err = $w; return false; }
        $q = $p->prepare('SELECT id FROM host_ovr WHERE short_uuid = ? AND app_k = ? AND host_uuid = ? AND param = ?');
        foreach ($want as $k => $l) {
            $k = (string) $k;
            if (isset($have[$k])) continue;
            $q->execute([(string) $b['short_uuid'], $k, (string) $b['host_uuid'], (string) $b['param']]);
            $dup = $q->fetchColumn();
            $q->closeCursor();
            if ($dup !== false) { $err = $k === '' ? 'Общая подмена этого параметра уже есть' : 'У клиента «' . ($l !== '' ? $l : $k) . '» уже есть своя подмена этого параметра'; return false; }
        }
        $copy = array_diff_key($b, array_flip(['id', 'app_k', 'app_lbl', 'vat', 'last_hit', 'last_app', 'last_miss', 'miss_app', 'na_at', 'na_app', 'na_fmt']));
        $now = time();
        $p->beginTransaction();
        $del = $p->prepare('DELETE FROM host_ovr WHERE id = ?');
        foreach ($have as $k => $id) if (!isset($want[$k])) $del->execute([$id]);
        foreach ($want as $k => $l) {
            if (isset($have[(string) $k])) continue;
            $g = $copy + ['app_k' => (string) $k, 'app_lbl' => $l, 'vat' => $now];
            $p->prepare('INSERT INTO host_ovr (' . implode(', ', array_keys($g)) . ') VALUES (' . implode(', ', array_fill(0, count($g), '?')) . ')')->execute(array_values($g));
        }
        $p->commit();
    } catch (Throwable $e) {
        try { if ($p->inTransaction()) $p->rollBack(); } catch (Throwable $e2) {}
        $err = 'База не приняла запись'; error_log('submw host_ovr scope: ' . $e->getMessage()); return false;
    }
    hostov_recount();
    return true;
}

function hostov_set($id, $val, $till, $setTill, &$err, $mask = null, &$warn = null) {
    $err = ''; $warn = '';
    if (!hostov_ensure()) { $err = 'Нет таблицы подмен'; return false; }
    try {
        $p = db();
        if ($val !== null) {
            $st = $p->prepare('SELECT * FROM host_ovr WHERE id = ?');
            $st->execute([(int) $id]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if (!$r) { $err = 'Подмены уже нет'; return false; }
            $sec = (string) $r['host_sec'];
            $mv = null;
            if ((string) $r['param'] === 'log') {
                if (!in_array((string) $val, hostov_log_values(), true)) { $err = 'Недопустимое значение'; return false; }
            } elseif ((string) $r['param'] === 'fp') {
                if (!in_array((string) $val, hostov_fp_values($sec), true)) { $err = $sec === 'reality' ? 'С REALITY работают только chrome, firefox, safari и randomized' : 'Недопустимое значение'; return false; }
            } else {
                $mv = hostov_mask_build((string) $r['param'], (string) $val, $mask, hostov_caps($sec, (string) $r['host_tr'], (string) ($r['host_proto'] ?? '')), $err, $new);
                if ($mv === null) return false;
                $warn = hostov_mask_warn($new);
            }
            if ((string) $r['val'] !== (string) $val || (string) ($r['mval'] ?? '') !== (string) $mv) {
                $p->prepare('UPDATE host_ovr SET val = ?, mval = ?, vat = ? WHERE id = ?')->execute([(string) $val, $mv, time(), (int) $id]);
            }
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
    $m = ['tcp' => 'TCP', 'raw' => 'TCP', 'ws' => 'WS', 'xhttp' => 'XHTTP', 'xhttp3' => 'XHTTP/3', 'grpc' => 'gRPC', 'httpupgrade' => 'HTTPUpgrade', 'kcp' => 'mKCP', 'hysteria' => 'Hysteria2'];
    $t = strtolower((string) $t);
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
        $proto = hostov_proto($x['scheme']);
        $sec = $proto === 'hysteria2' ? 'quic' : ($proto === 'ss' ? 'none' : $x['sec']);
        $tr = $proto === 'ss' ? 'tcp' : $x['type'];
        if ($tr === 'xhttp' && $sec === 'tls' && hostov_alpn_h3($x['alpn'])) $tr = 'xhttp3';
        $caps = hostov_caps($sec, $tr, $proto);
        $why = [
            'fp' => $caps['fp'] ? '' : ($proto === 'hysteria2' ? 'У Hysteria2 отпечатка TLS нет' : ($proto === 'ss' ? 'У Shadowsocks отпечатка TLS нет' : ($tr === 'xhttp3' ? 'XHTTP/3 идёт по QUIC — отпечатка TLS нет' : 'Без TLS отпечатка нет'))),
            'frag' => $caps['frag'] ? '' : 'Транспорт по UDP — фрагментация только для TCP',
            'noise' => $caps['noise'] ? '' : 'Транспорт по TCP — шум только для UDP',
        ];
        // Маски, которые панель уже отдаёт хосту (параметр fm есть у vless и hysteria2): тип каждой и значения фрагментации и шума.
        $pm = ['tcp' => [], 'udp' => []]; $pfrag = ''; $pnoise = '';
        $fm = $x['fm'] !== '' ? json_decode($x['fm'], true) : null;
        if (is_array($fm)) {
            foreach (['tcp', 'udp'] as $kk) foreach ((array) ($fm[$kk] ?? []) as $mm) {
                if (!is_array($mm)) continue;
                $t = strtolower((string) ($mm['type'] ?? ''));
                $pm[$kk][] = $t;
                if ($kk === 'tcp' && $t === 'fragment') $pfrag = hostov_mask_txt('frag', $mm['settings'] ?? []) ?: 'fragment';
                if ($kk === 'udp' && $t === 'noise') $pnoise = hostov_mask_txt('noise', $mm['settings'] ?? []) ?: 'noise';
            }
        }
        $ex = $x['extra'] !== '' ? json_decode($x['extra'], true) : null;
        $out[] = [
            'uuid' => (string) $h['uuid'], 'name' => $x['n'], 'rmk' => (string) ($h['remark'] ?? ''), 'addr' => $x['a'], 'apat' => (string) ($h['address'] ?? ''), 'port' => $x['p'],
            'sec' => $sec, 'tr' => hostov_tr_label($tr), 'trk' => $tr, 'proto' => $proto, 'ln' => $proto === 'hysteria2' ? 'hysteria2://' : $x['scheme'] . '://',
            'sni' => $x['sni'], 'snipat' => (string) ($h['sni'] ?? ''),
            'fp' => $caps['fp'] ? ($x['fp'] !== '' ? $x['fp'] : ($x['sec'] === 'reality' ? 'chrome' : '')) : '', 'no' => $why['fp'],
            'caps' => $caps, 'why' => $why, 'pm' => $pm, 'pfrag' => mb_substr($pfrag, 0, 64), 'pnoise' => mb_substr($pnoise, 0, 64),
            'dl' => is_array($ex) && is_array($ex['downloadSettings'] ?? null),
        ];
    }
    return ['hosts' => $out, 'uid' => $uid];
}
