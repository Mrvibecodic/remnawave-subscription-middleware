<?php

/**
 * Защищённый канал c1 — серверная половина: база, настройки и врезки.
 *
 * Ядро протокола лежит в `chan.php` и про прослойку не знает ничего. Здесь всё
 * остальное: таблицы, индекс меток, ключи, память о повторах, жёсткий режим и
 * две врезки в `index.php`.
 *
 * Врезок ровно две:
 *   chan_intercept() — в самом начале, до разбора адреса. Расшифровывает запрос
 *                      и притворяется обычным: подменяет путь и заголовки, чтобы
 *                      весь конвейер (оверрайды, HWID, squadconf_inject,
 *                      addsub_merge, правила ответа) работал без единой правки.
 *   chan_flush()     — на завершении скрипта. Забирает буфер вывода, заголовки
 *                      и код ответа и отдаёт вместо них шифротекст.
 *
 * Вторая работает через register_shutdown_function, а не по месту `echo`,
 * сознательно: у прослойки четыре выхода (обычная отдача, 502 при обрыве,
 * замаскированный 404 и подстановка заблокированному), и якорь на одном из них
 * означал бы, что на трёх остальных клиент получает открытый текст.
 */

function chan_ext_ok() { return extension_loaded('sodium'); }

function chan_enabled() { return chan_ext_ok() && setting('chan_enabled', '0') === '1'; }

function chan_pad_on() { return setting('chan_pad', '1') === '1'; }

function chan_hard_default() { return setting('chan_hard_default', '0') === '1'; }

function chan_page_404() { return setting('chan_page_404', '0') === '1'; }

// Диагностический журнал. Выключен по умолчанию не из осторожности вообще,
// а по делу: в записи попадает расшифрованное тело подписки и карточка
// устройства, то есть ровно то, что канал и прячет от посредника.
function chan_debug_on() { return setting('chan_debug', '0') === '1'; }

function chan_debug_keep() { return max(5, min(500, (int) (setting('chan_debug_keep', '50') ?: 50))); }

// Потолок на каждое длинное поле записи: диагностика не должна раздувать базу.
const CHAN_DEBUG_CUT = 8192;

// Пересбор индекса меток по промаху — не чаще, чем раз в столько секунд.
// Промах вызывает полный обход пользователей панели, и без дросселя мусорный
// трафик по /c1/ превратился бы в постоянный опрос API.
function chan_index_ttl() { return max(60, (int) (setting('chan_index_ttl', '900') ?: 900)); }

function chan_hard_remarks() {
    $a = json_decode((string) setting('chan_hard_remarks', ''), true);
    $out = [];
    if (is_array($a)) foreach ($a as $s) { $s = trim((string) $s); if ($s !== '') $out[] = $s; }
    if ($out) return $out;
    return ['⬆️ Обновите приложение', 'Подписка работает только через защищённое соединение', 'Обратитесь в поддержку'];
}

// ---------------------------------------------------------------- таблицы ---

function chan_ensure() {
    static $done = false;
    if ($done) return true;
    if (!($p = db())) return false;
    $done = true;
    try {
        if (db_driver() === 'mysql') {
            $p->exec("CREATE TABLE IF NOT EXISTS chan_kid (
                kid VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                short_uuid VARCHAR(64) NOT NULL,
                epoch INT NOT NULL,
                PRIMARY KEY (kid), KEY idx_chan_kid_epoch (epoch)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $p->exec("CREATE TABLE IF NOT EXISTS chan_nonce (
                n VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                ts INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (n), KEY idx_chan_nonce_ts (ts)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $p->exec("CREATE TABLE IF NOT EXISTS chan_key (
                spid VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                secret VARCHAR(64) NOT NULL,
                created INT UNSIGNED NOT NULL DEFAULT 0,
                is_current TINYINT(1) NOT NULL DEFAULT 0,
                PRIMARY KEY (spid)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $p->exec("CREATE TABLE IF NOT EXISTS chan_state (
                short_uuid VARCHAR(64) NOT NULL,
                first_seen INT UNSIGNED NOT NULL DEFAULT 0,
                last_seen INT UNSIGNED NOT NULL DEFAULT 0,
                hits BIGINT UNSIGNED NOT NULL DEFAULT 0,
                downgrades BIGINT UNSIGNED NOT NULL DEFAULT 0,
                hard TINYINT(1) NOT NULL DEFAULT 0,
                ua VARCHAR(255) NULL,
                PRIMARY KEY (short_uuid)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $p->exec("CREATE TABLE IF NOT EXISTS chan_debug (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, ts INT UNSIGNED NOT NULL DEFAULT 0,
                ok TINYINT(1) NOT NULL DEFAULT 0, why VARCHAR(32) NULL, short_uuid VARCHAR(64) NULL,
                kid VARCHAR(16) NULL, spid VARCHAR(16) NULL, req_path TEXT NULL, req_head TEXT NULL,
                req_json TEXT NULL, req_fwd TEXT NULL, res_st INT NULL, res_meta TEXT NULL,
                res_body MEDIUMTEXT NULL, res_wire MEDIUMTEXT NULL, res_outer TEXT NULL,
                body_bytes INT UNSIGNED NULL, wire_bytes INT UNSIGNED NULL,
                PRIMARY KEY (id), KEY idx_chan_debug_ts (ts)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } else {
            $p->exec("CREATE TABLE IF NOT EXISTS chan_kid (kid TEXT NOT NULL PRIMARY KEY, short_uuid TEXT NOT NULL, epoch INTEGER NOT NULL)");
            $p->exec("CREATE INDEX IF NOT EXISTS idx_chan_kid_epoch ON chan_kid(epoch)");
            $p->exec("CREATE TABLE IF NOT EXISTS chan_nonce (n TEXT NOT NULL PRIMARY KEY, ts INTEGER NOT NULL DEFAULT 0)");
            $p->exec("CREATE INDEX IF NOT EXISTS idx_chan_nonce_ts ON chan_nonce(ts)");
            $p->exec("CREATE TABLE IF NOT EXISTS chan_key (spid TEXT NOT NULL PRIMARY KEY, secret TEXT NOT NULL, created INTEGER NOT NULL DEFAULT 0, is_current INTEGER NOT NULL DEFAULT 0)");
            $p->exec("CREATE TABLE IF NOT EXISTS chan_state (short_uuid TEXT NOT NULL PRIMARY KEY, first_seen INTEGER NOT NULL DEFAULT 0, last_seen INTEGER NOT NULL DEFAULT 0, hits INTEGER NOT NULL DEFAULT 0, downgrades INTEGER NOT NULL DEFAULT 0, hard INTEGER NOT NULL DEFAULT 0, ua TEXT NULL)");
            $p->exec("CREATE TABLE IF NOT EXISTS chan_debug (
                id INTEGER PRIMARY KEY AUTOINCREMENT, ts INTEGER NOT NULL DEFAULT 0,
                ok INTEGER NOT NULL DEFAULT 0, why TEXT NULL, short_uuid TEXT NULL,
                kid TEXT NULL, spid TEXT NULL, req_path TEXT NULL, req_head TEXT NULL,
                req_json TEXT NULL, req_fwd TEXT NULL, res_st INTEGER NULL, res_meta TEXT NULL,
                res_body TEXT NULL, res_wire TEXT NULL, res_outer TEXT NULL,
                body_bytes INTEGER NULL, wire_bytes INTEGER NULL
            )");
            $p->exec("CREATE INDEX IF NOT EXISTS idx_chan_debug_ts ON chan_debug(ts)");
        }
        return true;
    } catch (Throwable $e) { error_log('submw chan tables: ' . $e->getMessage()); return false; }
}

// ------------------------------------------------------------------ ключи ---

/**
 * Ключи прослойки: [spid => секрет]. Текущий и, пока идёт ротация, предыдущий.
 *
 * Первый ключ создаётся сам при первом обращении. Никаких «сгенерируйте ключ
 * для клиентов»: клиентам ключ приезжает внутри первого зашифрованного ответа,
 * и они его запоминают.
 */
function chan_keys() {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];
    if (!chan_ext_ok() || !chan_ensure() || !($p = db())) return $cache;
    try {
        foreach ($p->query('SELECT spid, secret FROM chan_key')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $raw = base64_decode((string) $row['secret'], true);
            if ($raw !== false && strlen($raw) === 32) $cache[(string) $row['spid']] = $raw;
        }
        if (!$cache) {
            [$secret, $public] = chan_keygen();
            $spid = chan_spid($public);
            $p->prepare('INSERT INTO chan_key (spid, secret, created, is_current) VALUES (?, ?, ?, 1)')
              ->execute([$spid, base64_encode($secret), time()]);
            $cache[$spid] = $secret;
        }
    } catch (Throwable $e) { error_log('submw chan keys: ' . $e->getMessage()); }
    return $cache;
}

function chan_current_secret() {
    $keys = chan_keys();
    if (!$keys) return '';
    if (!($p = db())) return (string) reset($keys);
    try {
        $spid = (string) $p->query('SELECT spid FROM chan_key WHERE is_current = 1 ORDER BY created DESC')->fetchColumn();
        if ($spid !== '' && isset($keys[$spid])) return $keys[$spid];
    } catch (Throwable $e) {}
    return (string) reset($keys);
}

function chan_public_key() {
    $secret = chan_current_secret();
    if ($secret === '') return '';
    try { return sodium_crypto_box_publickey_from_secretkey($secret); }
    catch (Throwable $e) { return ''; }
}

/** Отпечаток текущего ключа — то, что видно на вкладке рядом с тумблером. */
function chan_fingerprint() {
    $pub = chan_public_key();
    return $pub === '' ? '' : chan_spid($pub);
}

/**
 * Ротация ключа. Предыдущий остаётся живым: клиенты, закрепившие его, узнают
 * о новом из первого же ответа — он едет внутри шифра полем `sp`.
 */
function chan_rotate() {
    if (!chan_ext_ok() || !chan_ensure() || !($p = db())) return false;
    try {
        [$secret, $public] = chan_keygen();
        $spid = chan_spid($public);
        $p->exec('UPDATE chan_key SET is_current = 0');
        $st = $p->prepare('INSERT INTO chan_key (spid, secret, created, is_current) VALUES (?, ?, ?, 1)');
        $st->execute([$spid, base64_encode($secret), time()]);
        // Держим только два: текущий и предыдущий. Третий уже никому не нужен,
        // а каждый лишний — это ещё один ключ, которым можно расшифровать запрос.
        $old = $p->query('SELECT spid FROM chan_key ORDER BY created DESC')->fetchAll(PDO::FETCH_COLUMN);
        foreach (array_slice($old, 2) as $dead) {
            $p->prepare('DELETE FROM chan_key WHERE spid = ?')->execute([$dead]);
        }
        return true;
    } catch (Throwable $e) { error_log('submw chan rotate: ' . $e->getMessage()); return false; }
}

// ------------------------------------------------------------ индекс меток ---

/**
 * Токен подписки по метке.
 *
 * Промах — обычное дело для мусорного трафика, поэтому он стоит одного SELECT.
 * Пересбор по промаху нужен ради подписки, созданной уже после последнего
 * обхода: без него человек не смог бы подключиться до следующих суток.
 */
function chan_lookup_token($kid) {
    if (!chan_ensure() || !($p = db())) return null;
    $epoch = chan_epoch();
    try {
        $st = $p->prepare('SELECT short_uuid FROM chan_kid WHERE kid = ? AND epoch BETWEEN ? AND ?');
        $st->execute([(string) $kid, $epoch - 1, $epoch + 1]);
        $short = $st->fetchColumn();
        if ($short !== false && $short !== null && $short !== '') return (string) $short;

        if (!chan_index_rebuild(false)) return null;

        $st->execute([(string) $kid, $epoch - 1, $epoch + 1]);
        $short = $st->fetchColumn();
        return ($short === false || $short === null || $short === '') ? null : (string) $short;
    } catch (Throwable $e) { error_log('submw chan lookup: ' . $e->getMessage()); return null; }
}

/**
 * Пересбор индекса меток на трое суток: вчера, сегодня, завтра.
 *
 * Десять тысяч подписок — это тридцать тысяч HMAC, то есть миллисекунды.
 * Дорогая часть — обход пользователей панели, поэтому он под дросселем.
 */
function chan_index_rebuild($force = false) {
    if (!chan_ext_ok() || !chan_ensure() || !($p = db())) return false;

    $now   = time();
    $epoch = chan_epoch($now);
    if (!$force) {
        $ts   = (int) setting('chan_index_ts', '0');
        $done = (int) setting('chan_index_epoch', '0');
        if ($done === $epoch && ($now - $ts) < chan_index_ttl()) return false;
    }
    // Отметку ставим до обхода: неудачный обход не должен превращаться
    // в попытку на каждом следующем запросе.
    set_setting('chan_index_ts', (string) $now);

    $err   = '';
    $users = function_exists('remnawave_all_users') ? remnawave_all_users($err) : [];
    if (!$users) {
        if ($err !== '') error_log('submw chan index: ' . $err);
        return false;
    }

    $count = 0;
    try {
        $p->beginTransaction();
        $sql = db_driver() === 'mysql'
            ? 'INSERT INTO chan_kid (kid, short_uuid, epoch) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE short_uuid = VALUES(short_uuid), epoch = VALUES(epoch)'
            : 'INSERT INTO chan_kid (kid, short_uuid, epoch) VALUES (?, ?, ?) ON CONFLICT(kid) DO UPDATE SET short_uuid = excluded.short_uuid, epoch = excluded.epoch';
        $st = $p->prepare($sql);
        foreach ($users as $u) {
            $short = trim((string) ($u['shortUuid'] ?? ''));
            if ($short === '') continue;
            foreach (chan_kids($short, $now) as $e => $kid) $st->execute([$kid, $short, $e]);
            $count++;
        }
        $p->prepare('DELETE FROM chan_kid WHERE epoch < ?')->execute([$epoch - 1]);
        $p->commit();
    } catch (Throwable $e) {
        if ($p->inTransaction()) $p->rollBack();
        error_log('submw chan index: ' . $e->getMessage());
        return false;
    }

    set_setting('chan_index_epoch', (string) $epoch);
    set_setting('chan_index_count', (string) $count);
    return true;
}

/** Досыпать метки одной подписке — вызывается вебхуком на создание юзера. */
function chan_index_add($short) {
    $short = trim((string) $short);
    if ($short === '' || !chan_ext_ok() || !chan_ensure() || !($p = db())) return;
    $now = time();
    try {
        $sql = db_driver() === 'mysql'
            ? 'INSERT INTO chan_kid (kid, short_uuid, epoch) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE short_uuid = VALUES(short_uuid), epoch = VALUES(epoch)'
            : 'INSERT INTO chan_kid (kid, short_uuid, epoch) VALUES (?, ?, ?) ON CONFLICT(kid) DO UPDATE SET short_uuid = excluded.short_uuid, epoch = excluded.epoch';
        $st = $p->prepare($sql);
        foreach (chan_kids($short, $now) as $e => $kid) $st->execute([$kid, $short, $e]);
    } catch (Throwable $e) { error_log('submw chan index add: ' . $e->getMessage()); }
}

function chan_index_info() {
    return [
        'count' => (int) setting('chan_index_count', '0'),
        'ts'    => (int) setting('chan_index_ts', '0'),
        'epoch' => (int) setting('chan_index_epoch', '0'),
        'fresh' => (int) setting('chan_index_epoch', '0') === chan_epoch(),
    ];
}

// ----------------------------------------------------------------- повторы ---

/**
 * Метка запроса: принимается один раз. Перехваченный посредником адрес,
 * отправленный повторно, обязан выглядеть так же, как мусорный путь.
 */
function chan_nonce_take($n) {
    $n = (string) $n;
    if ($n === '' || !chan_ensure() || !($p = db())) return false;
    $now = time();
    try {
        $p->prepare('INSERT INTO chan_nonce (n, ts) VALUES (?, ?)')->execute([$n, $now]);
    } catch (Throwable $e) {
        return false;   // дубль первичного ключа — это и есть повтор
    }
    try {
        if (random_int(1, 50) === 1) {
            $p->prepare('DELETE FROM chan_nonce WHERE ts < ?')->execute([$now - CHAN_NONCE_TTL]);
        }
    } catch (Throwable $e) {}
    return true;
}

// -------------------------------------------------------- состояние подписки ---

function chan_state_get($short) {
    $short = trim((string) $short);
    if ($short === '' || !chan_ensure() || !($p = db())) return null;
    try {
        $st = $p->prepare('SELECT * FROM chan_state WHERE short_uuid = ?');
        $st->execute([$short]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) { return null; }
}

/** Подписка сходила защищённо: отметить и, если так настроено, включить жёсткий режим. */
function chan_state_hit($short, $ua = '') {
    $short = trim((string) $short);
    if ($short === '' || !chan_ensure() || !($p = db())) return;
    $now  = time();
    $ua   = substr((string) $ua, 0, 255);
    $hard = chan_hard_default() ? 1 : 0;
    try {
        if (db_driver() === 'mysql') {
            $st = $p->prepare('INSERT INTO chan_state (short_uuid, first_seen, last_seen, hits, downgrades, hard, ua)
                VALUES (?, ?, ?, 1, 0, ?, ?)
                ON DUPLICATE KEY UPDATE last_seen = VALUES(last_seen), hits = hits + 1, ua = VALUES(ua)');
        } else {
            $st = $p->prepare('INSERT INTO chan_state (short_uuid, first_seen, last_seen, hits, downgrades, hard, ua)
                VALUES (?, ?, ?, 1, 0, ?, ?)
                ON CONFLICT(short_uuid) DO UPDATE SET last_seen = excluded.last_seen, hits = hits + 1, ua = excluded.ua');
        }
        $st->execute([$short, $now, $now, $hard, $ua]);
    } catch (Throwable $e) { error_log('submw chan state: ' . $e->getMessage()); }
}

/**
 * Открытый запрос по подписке, которая уже ходила защищённо.
 *
 * Это единственный способ увидеть посредника, который режет защищённый путь:
 * клиент сам на открытый HTTP не откатывается, значит откат сделали за него.
 */
function chan_state_downgrade($short) {
    $short = trim((string) $short);
    if ($short === '' || !chan_ensure() || !($p = db())) return;
    try {
        $p->prepare('UPDATE chan_state SET downgrades = downgrades + 1, last_seen = ? WHERE short_uuid = ?')
          ->execute([time(), $short]);
    } catch (Throwable $e) {}
}

function chan_hard($short) {
    if (!chan_enabled()) return false;
    $row = chan_state_get($short);
    return $row !== null && (int) $row['hard'] === 1;
}

function chan_hard_set($short, $on) {
    $short = trim((string) $short);
    if ($short === '' || !chan_ensure() || !($p = db())) return;
    $now = time();
    try {
        if (chan_state_get($short) === null) {
            $p->prepare('INSERT INTO chan_state (short_uuid, first_seen, last_seen, hits, downgrades, hard, ua) VALUES (?, ?, ?, 0, 0, ?, NULL)')
              ->execute([$short, $now, $now, $on ? 1 : 0]);
            return;
        }
        $p->prepare('UPDATE chan_state SET hard = ? WHERE short_uuid = ?')->execute([$on ? 1 : 0, $short]);
    } catch (Throwable $e) {}
}

function chan_state_list($limit = 500) {
    if (!chan_ensure() || !($p = db())) return [];
    try {
        return $p->query('SELECT * FROM chan_state ORDER BY last_seen DESC LIMIT ' . (int) $limit)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { return []; }
}

function chan_stats() {
    $out = ['subs' => 0, 'hits' => 0, 'downgrades' => 0, 'hard' => 0, 'day' => 0];
    if (!chan_ensure() || !($p = db())) return $out;
    try {
        $row = $p->query('SELECT COUNT(*) c, COALESCE(SUM(hits),0) h, COALESCE(SUM(downgrades),0) d, COALESCE(SUM(hard),0) k FROM chan_state')->fetch(PDO::FETCH_ASSOC);
        $out['subs']       = (int) ($row['c'] ?? 0);
        $out['hits']       = (int) ($row['h'] ?? 0);
        $out['downgrades'] = (int) ($row['d'] ?? 0);
        $out['hard']       = (int) ($row['k'] ?? 0);
        $st = $p->prepare('SELECT COUNT(*) FROM chan_state WHERE last_seen >= ?');
        $st->execute([time() - 86400]);
        $out['day'] = (int) $st->fetchColumn();
    } catch (Throwable $e) {}
    return $out;
}

// ------------------------------------------------- диагностический журнал ---

/** Длинное поле — к хранимому виду, с честной пометкой об обрезке. */
function chan_debug_cut($value) {
    $value = (string) $value;
    if (strlen($value) <= CHAN_DEBUG_CUT) return $value;

    return substr($value, 0, CHAN_DEBUG_CUT) . "\n… обрезано, всего " . strlen($value) . " байт";
}

/**
 * Заголовки, пришедшие снаружи, — то есть от посредника и клиента вместе.
 *
 * Снимаются ДО подмены: дальше по коду `$_SERVER` уже переписан содержимым
 * шифра, и настоящего входящего запроса в нём не остаётся.
 */
function chan_debug_incoming_headers() {
    $out = [];
    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) $out[strtolower((string) $k)] = (string) $v;
    } else {
        foreach ($_SERVER as $k => $v) {
            if (strpos($k, 'HTTP_') !== 0) continue;
            $out[strtolower(str_replace('_', '-', substr($k, 5)))] = (string) $v;
        }
    }
    ksort($out);

    return $out;
}

function chan_debug_record(array $r) {
    if (!chan_ensure() || !($p = db())) return;
    $j = static fn($v) => is_string($v) ? $v : (string) json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    try {
        $st = $p->prepare('INSERT INTO chan_debug
            (ts, ok, why, short_uuid, kid, spid, req_path, req_head, req_json, req_fwd, res_st, res_meta, res_body, res_wire, res_outer, body_bytes, wire_bytes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $st->execute([
            (int) ($r['ts'] ?? time()),
            !empty($r['ok']) ? 1 : 0,
            (string) ($r['why'] ?? ''),
            (string) ($r['short_uuid'] ?? ''),
            (string) ($r['kid'] ?? ''),
            (string) ($r['spid'] ?? ''),
            chan_debug_cut($r['req_path'] ?? ''),
            chan_debug_cut($j($r['req_head'] ?? [])),
            chan_debug_cut($r['req_json'] ?? ''),
            chan_debug_cut($j($r['req_fwd'] ?? [])),
            isset($r['res_st']) ? (int) $r['res_st'] : null,
            chan_debug_cut($j($r['res_meta'] ?? [])),
            chan_debug_cut($r['res_body'] ?? ''),
            chan_debug_cut($r['res_wire'] ?? ''),
            chan_debug_cut($j($r['res_outer'] ?? [])),
            isset($r['body_bytes']) ? (int) $r['body_bytes'] : null,
            isset($r['wire_bytes']) ? (int) $r['wire_bytes'] : null,
        ]);
        // Кольцо: журнал не должен расти. Мусорный трафик по /c1/ тоже сюда
        // попадает, иначе непонятно, что вообще стучится.
        $keep = chan_debug_keep();
        $p->exec('DELETE FROM chan_debug WHERE id <= (SELECT MAX(id) - ' . (int) $keep . ' FROM (SELECT id FROM chan_debug) t)');
    } catch (Throwable $e) { error_log('submw chan debug: ' . $e->getMessage()); }
}

function chan_debug_list($limit = 50) {
    if (!chan_ensure() || !($p = db())) return [];
    try {
        return $p->query('SELECT * FROM chan_debug ORDER BY id DESC LIMIT ' . (int) $limit)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { return []; }
}

function chan_debug_clear() {
    if (!chan_ensure() || !($p = db())) return;
    try { $p->exec('DELETE FROM chan_debug'); } catch (Throwable $e) {}
}

/** Причина отказа — человеческим языком. */
function chan_debug_why($why) {
    $map = [
        'blob'   => 'блок в адресе не разбирается — не наш клиент или мусор',
        'kid'    => 'метки нет в индексе — подписка неизвестна либо индекс не пересобран',
        'spid'   => 'клиент закрепил ключ, которого у прослойки уже нет',
        'dh'     => 'вырожденный эфемерный ключ в запросе',
        'aead'   => 'не расшифровалось: другой адрес подписки или подмена по дороге',
        'json'   => 'внутри не тот формат или версия протокола',
        'time'   => 'время запроса вне окна ±300 секунд — разъехались часы',
        'nonce'  => 'плохая метка запроса',
        'replay' => 'повтор: эта метка запроса уже принималась',
    ];

    return $map[(string) $why] ?? (string) $why;
}

// ------------------------------------------------------------- заглушка ---

/**
 * Тело для открытого запроса по подписке в жёстком режиме.
 *
 * Механизм тот же, что у заблокированных: строки-пустышки, которые клиент
 * покажет как список серверов. Ни одного рабочего хоста в нём нет.
 */
function chan_stub_body($format = 'base64') {
    $lines = chan_hard_remarks();
    if ($format === 'clash') {
        $names = clash_unique($lines);
        $out = [];
        foreach ($names as $n) {
            $out[] = '  - {name: ' . yaml_q($n) . ', type: ss, server: 127.0.0.1, port: 1, cipher: aes-128-gcm, password: "1", udp: false}';
        }
        $group = 'Информация';
        $grp = "proxy-groups:\n  - name: " . $group . "\n    type: select\n    proxies:\n";
        foreach ($names as $n) $grp .= '      - ' . yaml_q($n) . "\n";
        return "proxies:\n" . implode("\n", $out) . "\n" . $grp . "rules:\n  - MATCH," . $group . "\n";
    }
    $out = [];
    foreach ($lines as $r) {
        $out[] = 'vless://00000000-0000-0000-0000-000000000000@0.0.0.0:1?security=none&type=tcp&encryption=none&flow=#' . rawurlencode($r);
    }
    return base64_encode(implode("\n", $out));
}

// ------------------------------------------------------------------ врезки ---

/**
 * Ранняя врезка. Возвращает контекст канала либо null.
 *
 * null означает «это не наш запрос» ЛИБО «расшифровать не удалось», и разницы
 * снаружи быть не должно: в обоих случаях запрос идёт дальше обычным путём и
 * получает ровно то же, что любой мусорный адрес. Отдельный синтетический 404
 * тут не годится — у прослойки мусорный путь не 404, а проксирование на origin,
 * и подделать его ответ всё равно не выйдет.
 */
function chan_intercept() {
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    if (strpos($uri, '/c1/') === false) return null;
    if (!chan_ext_ok()) return null;

    $route = chan_route($uri);
    if ($route === null) return null;
    if (!chan_enabled()) return null;

    [$prefix, $kid, $spid, $blob] = $route;

    // Журнал снимает входящее ДО подмены: после неё настоящего запроса
    // в $_SERVER уже не остаётся.
    $dbg  = chan_debug_on();
    $head = $dbg ? chan_debug_incoming_headers() : [];

    $why = null;
    $ctx = chan_open($kid, $spid, $blob, 'chan_lookup_token', chan_keys(), null, $why);
    if ($ctx === null) {
        if ($dbg) {
            chan_debug_record(['ok' => 0, 'why' => $why, 'kid' => $kid, 'spid' => $spid,
                               'req_path' => $uri, 'req_head' => $head]);
        }

        return null;
    }
    if (!chan_nonce_take($ctx['nonce'])) {
        if ($dbg) {
            chan_debug_record(['ok' => 0, 'why' => 'replay', 'kid' => $kid, 'spid' => $spid,
                               'short_uuid' => $ctx['token'], 'req_path' => $uri,
                               'req_head' => $head, 'req_json' => $ctx['plain']]);
        }

        return null;
    }

    // Дальше запрос обязан выглядеть как обычный: подменяем путь, строку
    // запроса и заголовки опознания. Иначе половина конвейера — формат клиента,
    // лимит устройств, addsub — увидит не то, что в открытом режиме.
    $query = chan_request_query($ctx);
    $_SERVER['REQUEST_URI']  = $prefix . '/' . $ctx['token'] . ($query !== '' ? '?' . $query : '');
    $_SERVER['QUERY_STRING'] = $query;

    $headers = chan_request_headers($ctx);
    foreach ($headers as $name => $value) {
        $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
    }
    // Заголовки к панели собираются из getallheaders(), а не из $_SERVER,
    // поэтому одной подменой $_SERVER не обойтись: index.php берёт этот массив.
    $GLOBALS['chan_headers'] = $headers;
    $GLOBALS['chan_ctx']     = $ctx;

    if ($dbg) {
        $GLOBALS['chan_dbg'] = [
            'ts'         => time(),
            'kid'        => $kid,
            'spid'       => $spid,
            'short_uuid' => $ctx['token'],
            'req_path'   => $uri,
            'req_head'   => $head,
            'req_json'   => $ctx['plain'],
            'req_fwd'    => ['request_uri' => $_SERVER['REQUEST_URI'], 'headers' => $headers],
        ];
    }

    // Всё, что скрипт напишет дальше, — включая ветки с die() и exit() —
    // осядет здесь и уедет внутрь шифра.
    ob_start();
    $GLOBALS['chan_ob'] = ob_get_level();
    register_shutdown_function('chan_flush');

    return $ctx;
}

/** Идёт ли текущий запрос по защищённому каналу. */
function chan_active() { return !empty($GLOBALS['chan_ctx']); }

/**
 * Поздняя врезка: вместо тела и заголовков — шифротекст.
 *
 * Снаружи остаются только `content-type` и `cache-control`, а код ответа всегда
 * 200: настоящий уезжает внутрь шифра полем `st`. Иначе посредник читал бы по
 * коду, чем кончилось дело, — 404 у неизвестной подписки, 502 при обрыве.
 */
function chan_flush() {
    $ctx = $GLOBALS['chan_ctx'] ?? null;
    if (empty($ctx)) return;
    $GLOBALS['chan_ctx'] = null;   // защита от повторного входа

    $body  = '';
    $floor = max(1, (int) ($GLOBALS['chan_ob'] ?? 1));
    while (ob_get_level() >= $floor && ob_get_level() > 0) {
        $chunk = ob_get_clean();
        if ($chunk === false) break;
        $body = $chunk . $body;   // внешний буфер держит то, что записано раньше
    }

    if (headers_sent()) {
        error_log('submw chan: заголовки уже ушли, шифровать нечего');
        return;
    }

    $status = http_response_code();
    if (!is_int($status) || $status < 100) $status = 200;

    // Заголовки не отправляем, а забираем: они уезжают внутрь шифра.
    $drop = ['content-length', 'transfer-encoding', 'connection', 'content-encoding',
             'etag', 'last-modified'];   // последние два описывают тело, которого клиент уже не увидит
    $meta = [];
    foreach (headers_list() as $line) {
        $parts = explode(':', $line, 2);
        if (count($parts) !== 2) continue;
        $name = strtolower(trim($parts[0]));
        if ($name === '' || in_array($name, $drop, true)) continue;
        $meta[$name][] = trim($parts[1]);
    }
    header_remove();

    // `Date` ставит веб-сервер при отправке, в headers_list() его нет никогда.
    // Клиент считает по нему сдвиг часов устройства: без него поедет отсчёт
    // срока подписки, поэтому подставляем сами.
    if (!isset($meta['date'])) $meta['date'] = [gmdate('D, d M Y H:i:s') . ' GMT'];

    $sealed = chan_seal($ctx, $meta, $body, chan_public_key(), chan_pad_on(), $status);
    if ($sealed === null) {
        error_log('submw chan: ответ не собрался');
        http_response_code(404);
        return;
    }

    http_response_code(200);
    header('content-type: application/octet-stream');
    header('cache-control: no-store');
    echo $sealed;

    chan_state_hit($ctx['token'], (string) ($ctx['req']['ua'] ?? ''));

    if (!empty($GLOBALS['chan_dbg'])) {
        $rec = $GLOBALS['chan_dbg'];
        $GLOBALS['chan_dbg'] = null;
        $rec['ok']         = 1;
        $rec['res_st']     = $status;
        $rec['res_meta']   = $meta;
        $rec['res_body']   = $body;
        $rec['res_wire']   = $sealed;
        $rec['res_outer']  = ['status' => 200, 'headers' => headers_list()];
        $rec['body_bytes'] = strlen($body);
        $rec['wire_bytes'] = strlen($sealed);
        chan_debug_record($rec);
    }
}
