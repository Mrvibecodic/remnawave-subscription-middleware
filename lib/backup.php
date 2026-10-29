<?php

// Файл: gzip, внутри JSON по строке — шапка, затем {"t":таблица} и строки-массивы, в конце {"end":…} со счётчиками.
// С паролем gzip режется на куски, каждый шифруется AES-256-GCM; номер куска и признак последнего входят
// в AAD, поэтому подмена, перестановка и обрезка кусков не проходят.

const BK_FMT     = 1;
const BK_MAGIC   = 'SUBMWBK1';
const BK_CHUNK   = 1048576;
const BK_ITER    = 300000;
const BK_MAXLINE = 25165824;
const BK_JSON    = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION;
const BK_KEEP    = ['auto' => 7, 'pre' => 3];

function backup_dir() { return dirname(default_db_path()) . '/backups'; }

function backup_dir_ready(&$err = '') {
    $d = backup_dir();
    if (!is_dir($d) && !@mkdir($d, 0770, true)) { $err = 'Не удалось создать папку ' . $d; return ''; }
    if (!is_file($d . '/.htaccess')) @file_put_contents($d . '/.htaccess', "Require all denied\nDeny from all\n");
    if (!is_writable($d)) { $err = 'Нет прав на запись в папку ' . $d; return ''; }
    return $d;
}

function backup_missing() {
    $m = [];
    if (!function_exists('deflate_init') || !function_exists('inflate_init')) $m[] = 'zlib';
    if (!function_exists('json_encode')) $m[] = 'json';
    return $m;
}

function backup_crypto_ok() {
    return function_exists('openssl_encrypt') && in_array('aes-256-gcm', array_map('strtolower', (array) @openssl_get_cipher_methods()), true);
}

function backup_kinds() {
    return ['manual' => 'вручную', 'auto' => 'по расписанию', 'pre' => 'снимок до восстановления', 'upload' => 'загружен'];
}

// Только свои таблицы: в общей базе MySQL могут лежать чужие — их не выгружаем и не стираем.
function backup_known_tables() {
    static $k = null;
    if ($k !== null) return $k;
    $k = [];
    foreach (glob(__DIR__ . '/*.php') ?: [] as $f) {
        if (preg_match_all('~CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+`?([A-Za-z0-9_]+)~i', (string) @file_get_contents($f), $m)) {
            foreach ($m[1] as $t) $k[$t] = true;
        }
    }
    $k = array_keys($k);
    return $k;
}

// Кэши прослойка заново наберёт из панели; старый кэш после восстановления отдал бы устаревшее.
function backup_cache_tables() { return ['admin_cache', 'squad_cache', 'addsub_cache', 'wg_user_cache']; }

function backup_log_tables() { return function_exists('gc_tables') ? array_keys(gc_tables()) : []; }

function backup_tables_now($p, $drv, $lite) {
    $skip = array_merge(backup_cache_tables(), ['login_attempts'], $lite ? backup_log_tables() : []);
    $out = array_values(array_diff(array_intersect(db_list_tables($p, $drv), backup_known_tables()), $skip));
    sort($out);
    return $out;
}

// Служебное этой установки (планировщик, обновления, установка, сам бэкап) остаётся текущим.
function backup_keep_setting($k, $keep_login) {
    $k = (string) $k;
    if ($keep_login && $k === 'admin_gate_key') return true;
    foreach (['cron_', 'update_', 'install', 'backup_'] as $pre) {
        if (strncmp($k, $pre, strlen($pre)) === 0) return true;
    }
    return false;
}

function backup_auto_on() { return setting('backup_auto', '0') === '1'; }

function backup_auto_pass() { return (string) setting('backup_auto_pass', ''); }

function backup_upload_limit() {
    $b = static function ($v) {
        $v = trim((string) $v);
        if ($v === '' || $v === '-1' || $v === '0') return PHP_INT_MAX;
        $n = (float) $v;
        $u = strtolower(substr($v, -1));
        if ($u === 'g') $n *= 1073741824; elseif ($u === 'm') $n *= 1048576; elseif ($u === 'k') $n *= 1024;
        return (int) $n;
    };
    return min($b(ini_get('upload_max_filesize')), $b(ini_get('post_max_size')));
}

// Микросекунды в хвосте: бэкапы одной секунды сортируются по времени создания, и хранение удаляет самые старые.
function backup_new_name($kind, $enc) {
    $t = microtime(true);
    return 'submw-' . gmdate('Ymd-His', (int) $t) . '-' . $kind . '-' . sprintf('%06d', (int) (($t - floor($t)) * 1000000)) . '.json.gz' . ($enc ? '.enc' : '');
}

function backup_name_ok($name) {
    return is_string($name) && strpos($name, '..') === false && (bool) preg_match('~^[A-Za-z0-9][A-Za-z0-9._-]{0,150}\.(gz|enc)$~', $name);
}

function backup_path($name) {
    if (!backup_name_ok($name)) return '';
    $p = backup_dir() . '/' . $name;
    return is_file($p) ? $p : '';
}

// Своя подпись вместо CSRF-токена сессии: ссылка попадает в журналы веб-сервера и прокси.
function backup_dl_url($name, $secret) {
    return 'index.php?bk_dl=' . rawurlencode((string) $name) . '&k=' . substr(hash_hmac('sha256', 'bkdl|' . $name, (string) $secret), 0, 32);
}

function backup_dl_ok($name, $secret, $k) {
    return is_string($k) && $k !== '' && hash_equals(substr(hash_hmac('sha256', 'bkdl|' . $name, (string) $secret), 0, 32), $k);
}

function backup_is_enc($path) { return (string) @file_get_contents($path, false, null, 0, 8) === BK_MAGIC; }

function backup_info($name, $path) {
    $kind = 'upload';
    $ts = (int) @filemtime($path);
    if (preg_match('~^submw-(\d{4})(\d\d)(\d\d)-(\d\d)(\d\d)(\d\d)-(manual|auto|pre|upload|dl)-[a-z0-9]{6}\.json\.gz(\.enc)?$~', $name, $m)) {
        $kind = $m[7];
        $ts = (int) gmmktime((int) $m[4], (int) $m[5], (int) $m[6], (int) $m[2], (int) $m[3], (int) $m[1]);
    }
    return ['name' => $name, 'kind' => $kind, 'ts' => $ts, 'size' => (int) @filesize($path), 'enc' => backup_is_enc($path)];
}

function backup_list() {
    $d = backup_dir();
    $out = [];
    foreach (is_dir($d) ? (scandir($d) ?: []) : [] as $n) {
        if (strncmp($n, '.part-', 6) === 0 && (int) @filemtime($d . '/' . $n) < time() - 86400) { @unlink($d . '/' . $n); continue; }
        if ($n === '' || $n[0] === '.' || !backup_name_ok($n) || !is_file($d . '/' . $n)) continue;
        $i = backup_info($n, $d . '/' . $n);
        if ($i['kind'] === 'dl') {
            if ($i['ts'] < time() - 3600) @unlink($d . '/' . $n);
            continue;
        }
        $out[] = $i;
    }
    usort($out, static function ($a, $b) { return $b['ts'] <=> $a['ts'] ?: strcmp($b['name'], $a['name']); });
    return $out;
}

function backup_prune($kind) {
    $keep = BK_KEEP[$kind] ?? 0;
    if ($keep <= 0) return;
    $same = array_values(array_filter(backup_list(), static function ($b) use ($kind) { return $b['kind'] === $kind; }));
    foreach (array_slice($same, $keep) as $b) @unlink(backup_dir() . '/' . $b['name']);
}

function backup_lock(&$err = '') {
    $d = backup_dir_ready($err);
    if ($d === '') return null;
    $h = @fopen($d . '/.lock', 'c');
    if (!$h) { $err = 'Нет прав на запись в папку ' . $d; return null; }
    if (!flock($h, LOCK_EX | LOCK_NB)) { fclose($h); $err = 'Уже идёт другой бэкап или восстановление — подождите, пока он закончится'; return null; }
    return $h;
}

function backup_unlock($h) {
    if (!$h) return;
    flock($h, LOCK_UN);
    fclose($h);
}

function bk_q($name, $drv) {
    return $drv === 'mysql' ? '`' . str_replace('`', '``', $name) . '`' : '"' . str_replace('"', '""', $name) . '"';
}

// Невалидный UTF-8 (SQLite хранит его молча) уходит base64-объектом: JSON его не примет, а терять байты нельзя.
function bk_row_json(array $vals) {
    $j = json_encode($vals, BK_JSON);
    if ($j !== false) return $j;
    foreach ($vals as $i => $v) {
        if (is_string($v) && !preg_match('//u', $v)) $vals[$i] = ['b' => base64_encode($v)];
    }
    return (string) json_encode($vals, BK_JSON | JSON_INVALID_UTF8_SUBSTITUTE);
}

// Читатель длиннее BK_MAXLINE не примет — лучше отказать сейчас, чем выдать файл, который не восстановить.
function bk_row_line(array $row, $t) {
    $j = bk_row_json($row);
    if (strlen($j) > BK_MAXLINE) throw new RuntimeException('В таблице ' . $t . ' есть запись больше ' . (BK_MAXLINE >> 20) . ' МБ — такой бэкап не получится восстановить');
    return $j;
}

// ---- запись ----

function bk_w_open($path, $pass, &$err) {
    $fh = @fopen($path, 'xb');
    if (!$fh) { $err = 'Не удалось создать файл в папке ' . dirname($path); return null; }
    @chmod($path, 0640);
    $w = ['fh' => $fh, 'path' => $path, 'z' => deflate_init(ZLIB_ENCODING_GZIP, ['level' => 6]), 'key' => '', 'hdr' => '', 'np' => '', 'n' => 0, 'buf' => '', 'err' => ''];
    if ((string) $pass !== '') {
        $salt = random_bytes(16);
        $w['np'] = random_bytes(8);
        $w['key'] = hash_pbkdf2('sha256', (string) $pass, $salt, BK_ITER, 32, true);
        $w['hdr'] = BK_MAGIC . pack('N', BK_ITER) . $salt . $w['np'];
        bk_w_out($w, $w['hdr']);
    }
    return $w;
}

function bk_w_out(array &$w, $bytes) {
    if ($w['err'] !== '' || $bytes === '') return;
    if (@fwrite($w['fh'], $bytes) !== strlen($bytes)) $w['err'] = 'Не удалось записать файл — возможно, на диске закончилось место';
}

function bk_w_seal(array &$w, $pt, $final) {
    $flag = $final ? 1 : 0;
    $tag = '';
    $ct = openssl_encrypt($pt, 'aes-256-gcm', $w['key'], OPENSSL_RAW_DATA, $w['np'] . pack('N', $w['n']), $tag, $w['hdr'] . pack('N', $w['n']) . chr($flag), 16);
    if ($ct === false) { $w['err'] = 'Не удалось зашифровать файл'; return; }
    bk_w_out($w, chr($flag) . pack('N', strlen($ct)) . $ct . $tag);
    $w['n']++;
}

function bk_w_raw(array &$w, $bytes, $final) {
    if ($w['key'] === '') { bk_w_out($w, $bytes); return; }
    $w['buf'] .= $bytes;
    while (strlen($w['buf']) > BK_CHUNK || (!$final && strlen($w['buf']) === BK_CHUNK)) {
        bk_w_seal($w, substr($w['buf'], 0, BK_CHUNK), false);
        $w['buf'] = (string) substr($w['buf'], BK_CHUNK);
    }
    if ($final) {
        bk_w_seal($w, $w['buf'], true);
        $w['buf'] = '';
    }
}

function bk_w_put(array &$w, $data) {
    if ($w['err'] !== '') return;
    $z = deflate_add($w['z'], $data, ZLIB_NO_FLUSH);
    if ($z === false) { $w['err'] = 'Не удалось сжать файл'; return; }
    bk_w_raw($w, $z, false);
}

function bk_w_close(array &$w, &$err) {
    $z = deflate_add($w['z'], '', ZLIB_FINISH);
    if ($z === false) $w['err'] = 'Не удалось сжать файл';
    else bk_w_raw($w, $z, true);
    if ($w['err'] === '' && !@fflush($w['fh'])) $w['err'] = 'Не удалось записать файл — возможно, на диске закончилось место';
    fclose($w['fh']);
    if ($w['err'] !== '') { $err = $w['err']; @unlink($w['path']); return false; }
    return true;
}

function bk_w_abort(array &$w) {
    @fclose($w['fh']);
    @unlink($w['path']);
}

// ---- чтение ----

function bk_r_open($path, $pass, &$err) {
    $err = '';
    $fh = @fopen($path, 'rb');
    if (!$fh) { $err = 'Не удалось открыть файл: нет прав на чтение'; return null; }
    $head = (string) fread($fh, 8);
    $r = ['fh' => $fh, 'z' => inflate_init(ZLIB_ENCODING_GZIP), 'key' => '', 'hdr' => '', 'np' => '', 'n' => 0, 'buf' => '', 'off' => 0, 'eof' => false, 'enc' => false];
    if (strncmp($head, "\x1f\x8b", 2) === 0) { rewind($fh); return $r; }
    if ($head !== BK_MAGIC) { fclose($fh); $err = 'Это не файл бэкапа прослойки'; return null; }
    $r['enc'] = true;
    if ((string) $pass === '') { fclose($fh); $err = 'Файл защищён паролем'; return null; }
    if (!backup_crypto_ok()) { fclose($fh); $err = 'На сервере нет поддержки шифрования (расширение PHP openssl) — файл с паролем здесь не открыть'; return null; }
    $rest = bk_read_exact($fh, 28);
    $iter = strlen($rest) === 28 ? (int) unpack('N', substr($rest, 0, 4))[1] : 0;
    if ($iter < 1000 || $iter > 5000000) { fclose($fh); $err = 'Файл повреждён'; return null; }
    $r['np'] = substr($rest, 20, 8);
    $r['hdr'] = BK_MAGIC . $rest;
    $r['key'] = hash_pbkdf2('sha256', (string) $pass, substr($rest, 4, 16), $iter, 32, true);
    return $r;
}

function bk_read_exact($fh, $len) {
    $out = '';
    while (strlen($out) < $len) {
        $s = fread($fh, $len - strlen($out));
        if ($s === false || $s === '') break;
        $out .= $s;
    }
    return $out;
}

function bk_r_more(array &$r, &$err) {
    if ($r['eof']) return false;
    if ($r['key'] === '') {
        $raw = fread($r['fh'], 262144);
        if ($raw === false || $raw === '') {
            $r['eof'] = true;
            $out = @inflate_add($r['z'], '', ZLIB_FINISH);
        } else {
            $out = @inflate_add($r['z'], $raw, ZLIB_SYNC_FLUSH);
        }
    } else {
        $h = bk_read_exact($r['fh'], 5);
        if (strlen($h) !== 5) { $err = 'Файл неполный — похоже, он скачался или загрузился не до конца'; return false; }
        $flag = ord($h[0]);
        $len = (int) unpack('N', substr($h, 1))[1];
        if ($flag > 1 || $len > BK_CHUNK + 64) { $err = 'Файл повреждён'; return false; }
        $ct = bk_read_exact($r['fh'], $len + 16);
        if (strlen($ct) !== $len + 16) { $err = 'Файл неполный — похоже, он скачался или загрузился не до конца'; return false; }
        $pt = openssl_decrypt(substr($ct, 0, $len), 'aes-256-gcm', $r['key'], OPENSSL_RAW_DATA, $r['np'] . pack('N', $r['n']), substr($ct, $len), $r['hdr'] . pack('N', $r['n']) . chr($flag));
        if ($pt === false) { $err = $r['n'] === 0 ? 'Неверный пароль или файл повреждён' : 'Файл повреждён'; return false; }
        $r['n']++;
        if ($flag === 1) {
            if (fread($r['fh'], 1) !== '') { $err = 'Файл повреждён'; return false; }
            $r['eof'] = true;
        }
        $out = @inflate_add($r['z'], $pt, $flag === 1 ? ZLIB_FINISH : ZLIB_SYNC_FLUSH);
    }
    if ($out === false) { $err = 'Файл повреждён'; return false; }
    if ($r['off'] > 0) { $r['buf'] = (string) substr($r['buf'], $r['off']); $r['off'] = 0; }
    $r['buf'] .= $out;
    return true;
}

function bk_r_line(array &$r, &$err) {
    while (true) {
        $pos = strpos($r['buf'], "\n", $r['off']);
        if ($pos !== false) {
            $line = substr($r['buf'], $r['off'], $pos - $r['off']);
            $r['off'] = $pos + 1;
            return $line;
        }
        if (strlen($r['buf']) - $r['off'] > BK_MAXLINE) { $err = 'Файл повреждён'; return null; }
        if (!bk_r_more($r, $err)) {
            if ($err === '' && strlen($r['buf']) > $r['off']) $err = 'Файл неполный — похоже, он скачался или загрузился не до конца';
            return null;
        }
    }
}

function bk_r_close(array &$r) { if (is_resource($r['fh'])) fclose($r['fh']); }

function bk_r_head(array &$r, &$err) {
    $line = bk_r_line($r, $err);
    if ($line === null) { if ($err === '') $err = 'Файл пустой'; return null; }
    $h = json_decode($line, true);
    if (!is_array($h) || !isset($h['submw_backup']) || !is_array($h['tables'] ?? null)) { $err = 'Это не файл бэкапа прослойки'; return null; }
    if ((int) $h['submw_backup'] > BK_FMT) { $err = 'Файл сделан более новой версией прослойки — сначала обновите прослойку'; return null; }
    return $h;
}

function bk_login(array $h) {
    $l = $h['login'] ?? null;
    if (!is_array($l)) return null;
    $u = $l['user'] ?? null;
    $hash = $l['hash'] ?? null;
    if (!is_string($u) || $u === '' || strlen($u) > 191 || !is_string($hash) || $hash === '') return null;
    $info = password_get_info($hash);
    if (empty($info['algo'])) return null;
    return ['user' => $u, 'hash' => $hash];
}

// ---- выгрузка ----

function backup_create($kind, $pass, $lite, ?callable $tick, &$err) {
    $lock = backup_lock($err);
    if (!$lock) return '';
    try {
        return bk_create($kind, $pass, $lite, $tick, $err);
    } finally {
        backup_unlock($lock);
    }
}

function bk_create($kind, $pass, $lite, ?callable $tick, &$err) {
    $err = '';
    $pass = (string) $pass;
    if ($m = backup_missing()) { $err = 'На сервере нет расширения PHP: ' . implode(', ', $m); return ''; }
    if ($pass !== '' && !backup_crypto_ok()) { $err = 'На сервере нет поддержки шифрования (расширение PHP openssl) — бэкап с паролем недоступен'; return ''; }
    $dir = backup_dir_ready($err);
    if ($dir === '') return '';
    $p = db();
    if (!$p) { $err = 'Нет подключения к базе данных'; return ''; }
    $drv = db_driver();
    @set_time_limit(0);
    $ver = function_exists('update_installed_commit') ? (string) update_installed_commit() : '';
    $C = cfg();
    $tables = backup_tables_now($p, $drv, $lite);
    $name = backup_new_name($kind, $pass !== '');
    $w = bk_w_open($dir . '/.part-' . bin2hex(random_bytes(6)), $pass, $err);
    if ($w === null) return '';
    $tx = false;
    $unbuf = $drv === 'mysql' && defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY');
    try {
        // Одна читающая транзакция — все таблицы из одного момента.
        $p->beginTransaction();
        $tx = true;
        $meta = [];
        $set = [];
        $total = 0;
        $gate = false;
        $chan = in_array('chan_key', $tables, true) ? bk_chan_spids($p) : [];
        foreach ($tables as $t) {
            $cols = db_table_columns($p, $drv, $t);
            if (!$cols) continue;
            if ($t === 'settings') {
                $ki = array_search('k', $cols, true);
                $vi = array_search('v', $cols, true);
                foreach ($p->query('SELECT ' . implode(',', array_map(static function ($c) use ($drv) { return bk_q($c, $drv); }, $cols)) . ' FROM settings')->fetchAll(PDO::FETCH_NUM) as $row) {
                    if ($ki !== false && strncmp((string) $row[$ki], 'backup_', 7) === 0) continue;
                    if ($ki !== false && $vi !== false && $row[$ki] === 'admin_gate_key') $gate = trim((string) $row[$vi]) !== '';
                    $set[] = $row;
                }
                $n = count($set);
            } else {
                $n = (int) $p->query('SELECT COUNT(*) FROM ' . bk_q($t, $drv))->fetchColumn();
            }
            $meta[] = ['t' => $t, 'c' => $cols, 'n' => $n];
            $total += $n;
        }
        $head = ['submw_backup' => BK_FMT, 'ts' => time(), 'ver' => $ver, 'drv' => $drv, 'kind' => (string) $kind, 'lite' => (bool) $lite, 'rows' => $total, 'gate' => $gate, 'chan' => $chan,
                 'login' => ['user' => (string) ($C['admin_user'] ?? ''), 'hash' => (string) ($C['admin_pass_hash'] ?? '')], 'tables' => $meta];
        bk_w_put($w, json_encode($head, BK_JSON) . "\n");
        $done = 0;
        $per = [];
        if ($tick) $tick('dump', '', 0, $total);
        foreach ($meta as $m) {
            $t = $m['t'];
            $per[$t] = 0;
            bk_w_put($w, json_encode(['t' => $t], BK_JSON) . "\n");
            $buf = '';
            if ($t === 'settings') {
                foreach ($set as $row) { $buf .= bk_row_line($row, $t) . "\n"; $per[$t]++; }
            } elseif ($m['n'] > 0) {
                if ($unbuf) $p->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
                $st = $p->query('SELECT ' . implode(',', array_map(static function ($c) use ($drv) { return bk_q($c, $drv); }, $m['c'])) . ' FROM ' . bk_q($t, $drv));
                while (($row = $st->fetch(PDO::FETCH_NUM)) !== false) {
                    $buf .= bk_row_line($row, $t) . "\n";
                    $per[$t]++;
                    if (strlen($buf) >= 262144) {
                        bk_w_put($w, $buf);
                        $buf = '';
                        if ($w['err'] !== '') break;
                    }
                    if ($tick && ($per[$t] & 1023) === 0) $tick('dump', $t, $done + $per[$t], $total);
                }
                $st->closeCursor();
                if ($unbuf) $p->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
            }
            if ($buf !== '') bk_w_put($w, $buf);
            if ($w['err'] !== '') throw new RuntimeException($w['err']);
            $done += $per[$t];
            if ($tick) $tick('dump', $t, $done, $total);
        }
        bk_w_put($w, json_encode(['end' => 1, 'rows' => $done, 'n' => (object) $per], BK_JSON) . "\n");
        $p->rollBack();
        $tx = false;
    } catch (Throwable $e) {
        if ($tx) { try { $p->rollBack(); } catch (Throwable $e2) {} }
        if ($unbuf) { try { $p->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true); } catch (Throwable $e2) {} }
        bk_w_abort($w);
        $err = $e->getMessage();
        return '';
    }
    $part = $w['path'];
    if (!bk_w_close($w, $err)) return '';
    if (!@rename($part, $dir . '/' . $name)) { @unlink($part); $err = 'Не удалось сохранить файл в папке ' . $dir; return ''; }
    backup_prune($kind);
    return $name;
}

function bk_chan_spids($p) {
    try { return array_map('strval', $p->query('SELECT spid FROM chan_key ORDER BY is_current DESC, created DESC')->fetchAll(PDO::FETCH_COLUMN)); }
    catch (Throwable $e) { return []; }
}

// ---- просмотр ----

function backup_preview($name, $pass, &$err) {
    $err = '';
    $path = backup_path($name);
    if ($path === '') { $err = 'Файл не найден'; return null; }
    if ($m = backup_missing()) { $err = 'На сервере нет расширения PHP: ' . implode(', ', $m); return null; }
    if (backup_is_enc($path) && (string) $pass === '') return ['need_pass' => true];
    $r = bk_r_open($path, $pass, $err);
    if ($r === null) return null;
    $h = bk_r_head($r, $err);
    $enc = $r['enc'];
    bk_r_close($r);
    if ($h === null) return null;
    backup_ensure_schema();
    $p = db();
    if (!$p) { $err = 'Нет подключения к базе данных'; return null; }
    $drv = db_driver();
    $cur = array_intersect(db_list_tables($p, $drv), backup_known_tables());
    $logs = backup_log_tables();
    $skipT = [];
    $skipC = [];
    $tables = 0;
    $rows = 0;
    $logRows = 0;
    $hasLogs = false;
    $phonesFile = 0;
    foreach ($h['tables'] as $m) {
        $t = is_array($m) ? (string) ($m['t'] ?? '') : '';
        if ($t === 'app_dev') $phonesFile = (int) ($m['n'] ?? 0);
        if ($t === '' || $t === 'login_attempts' || in_array($t, backup_cache_tables(), true)) continue;
        $n = (int) ($m['n'] ?? 0);
        if (!in_array($t, $cur, true)) { $skipT[] = $t; continue; }
        $miss = array_values(array_diff(array_map('strval', (array) ($m['c'] ?? [])), db_table_columns($p, $drv, $t)));
        if ($miss) $skipC[$t] = $miss;
        if (in_array($t, $logs, true)) { $hasLogs = true; $logRows += $n; continue; }
        $tables++;
        $rows += $n;
    }
    $phonesNow = 0;
    if (in_array('app_dev', $cur, true)) {
        try { $phonesNow = (int) $p->query('SELECT COUNT(*) FROM app_dev')->fetchColumn(); } catch (Throwable $e) {}
    }
    $C = cfg();
    $login = bk_login($h);
    $chanNow = in_array('chan_key', $cur, true) ? bk_chan_spids($p) : [];
    return [
        'ts'         => (int) ($h['ts'] ?? 0),
        'kind'       => (string) ($h['kind'] ?? ''),
        'ver'        => (string) ($h['ver'] ?? ''),
        'cur_ver'    => function_exists('update_installed_commit') ? (string) update_installed_commit() : '',
        'drv'        => ($h['drv'] ?? '') === 'mysql' ? 'mysql' : 'sqlite',
        'cur_drv'    => $drv,
        'enc'        => $enc,
        'tables'     => $tables,
        'rows'       => $rows,
        'logs'       => $hasLogs && empty($h['lite']),
        'log_rows'   => $logRows,
        'user'       => $login ? $login['user'] : '',
        'same_login' => $login !== null && $login['user'] === (string) ($C['admin_user'] ?? '') && $login['hash'] === (string) ($C['admin_pass_hash'] ?? ''),
        'skip_tables' => $skipT,
        'skip_cols'  => $skipC,
        'phones'     => $phonesFile,
        'phones_now' => $phonesNow,
        'gate'       => isset($h['gate']) ? (bool) $h['gate'] : null,
        'gate_now'   => trim((string) setting('admin_gate_key', '')) !== '',
        'chan_new'   => $chanNow && is_array($h['chan'] ?? null) && !in_array($chanNow[0], array_map('strval', $h['chan']), true),
    ];
}

function backup_ensure_schema() {
    $p = db();
    if (!$p) return;
    $drv = db_driver();
    $ddl = array_merge(install_statements($drv), [ddl_metrics_minute($drv), ddl_metrics_peak($drv)], migrate_extra_ddl($drv), rep_ddl($drv));
    foreach (['app_ddl', 'fc_ddl', 'addsub_swap_ddl', 'sess_ddl', 'apistat_ddl', 'geonet_ddl', 'pr_snap_ddl'] as $f) {
        if (function_exists($f)) $ddl = array_merge($ddl, (array) $f($drv));
    }
    foreach ($ddl as $sql) {
        // В install_statements есть и заполнение настроек по умолчанию: на рабочей базе оно дописало бы ключи, которых у старых установок нет.
        if (preg_match('~^\s*INSERT\b~i', (string) $sql)) continue;
        try { $p->exec($sql); } catch (Throwable $e) {}
    }
    foreach (['ensure_metrics_tables', 'ensure_forward_log', 'ensure_panel_write_log', 'ensure_grace_table', 'ensure_chat_tables', 'ensure_wg_user_cache',
              'ensure_reqlog_hwid', 'whlog_ensure_meta', 'rep_ensure', 'sess_ensure', 'apistat_ensure', 'pr_snap_ensure', 'geonet_ensure', 'junk_ensure',
              'addsub_ensure', 'addsub_swap_ensure', 'app_ensure', 'chan_ensure', 'fc_ensure', 'squadconf_ensure', 'squadconf_cache_ensure', 'wglease_ensure'] as $f) {
        if (function_exists($f)) { try { $f(); } catch (Throwable $e) {} }
    }
    if (function_exists('admin_cache_ensure')) { try { admin_cache_ensure($p); } catch (Throwable $e) {} }
}

// ---- восстановление ----

function backup_restore($name, $pass, array $opt, ?callable $tick, &$err) {
    $lock = backup_lock($err);
    if (!$lock) return null;
    try {
        return bk_restore($name, $pass, $opt, $tick, $err);
    } finally {
        backup_unlock($lock);
    }
}

function bk_cfg_prepare(array $login, &$err) {
    $C = cfg();
    $C['admin_user'] = $login['user'];
    $C['admin_pass_hash'] = $login['hash'];
    $php = "<?php\nreturn " . var_export($C, true) . ";\n";
    $dst = config_path();
    $tmp = dirname($dst) . '/.config-' . bin2hex(random_bytes(6)) . '.php';
    if (@file_put_contents($tmp, $php, LOCK_EX) !== strlen($php)) { @unlink($tmp); $err = 'Не удалось записать config.php: нет прав на запись в папку ' . dirname($dst); return ''; }
    @chmod($tmp, 0640);
    $chk = (static function ($f) { return include $f; })($tmp);
    if (!is_array($chk) || ($chk['admin_user'] ?? null) !== $login['user'] || ($chk['admin_pass_hash'] ?? null) !== $login['hash'] || ($chk['db'] ?? null) !== ($C['db'] ?? null)) {
        @unlink($tmp);
        $err = 'Не удалось записать config.php';
        return '';
    }
    return $tmp;
}

function bk_ins_prepare($p, $drv, $t, array $cols) {
    $verb = $drv === 'mysql' ? 'REPLACE' : 'INSERT OR REPLACE';
    $one = '(' . implode(',', array_fill(0, count($cols), '?')) . ')';
    $n = max(1, min(200, intdiv(900, max(1, count($cols)))));
    $head = $verb . ' INTO ' . bk_q($t, $drv) . ' (' . implode(',', array_map(static function ($c) use ($drv) { return bk_q($c, $drv); }, $cols)) . ') VALUES ';
    return ['one' => $p->prepare($head . $one), 'many' => $n > 1 ? $p->prepare($head . implode(',', array_fill(0, $n, $one))) : null, 'n' => $n, 'rows' => [], 'bytes' => 0];
}

function bk_ins_flush(array &$b) {
    $rows = $b['rows'];
    $b['rows'] = [];
    $b['bytes'] = 0;
    if (!$rows) return;
    if ($b['many'] !== null && count($rows) === $b['n']) { $b['many']->execute(array_merge(...$rows)); return; }
    foreach ($rows as $row) $b['one']->execute($row);
}

function bk_restore($name, $pass, array $opt, ?callable $tick, &$err) {
    $err = '';
    $keep_login = !empty($opt['keep_login']);
    $no_logs = !empty($opt['no_logs']);
    $path = backup_path($name);
    if ($path === '') { $err = 'Файл не найден'; return null; }
    if ($m = backup_missing()) { $err = 'На сервере нет расширения PHP: ' . implode(', ', $m); return null; }
    @set_time_limit(0);
    $r = bk_r_open($path, $pass, $err);
    if ($r === null) return null;
    $h = bk_r_head($r, $err);
    if ($h === null) { bk_r_close($r); return null; }
    $login = bk_login($h);
    if ($login === null) $keep_login = true;
    if (!empty($h['lite'])) $no_logs = true;
    $logs = backup_log_tables();
    $caches = backup_cache_tables();

    backup_ensure_schema();
    $p = db();
    if (!$p) { bk_r_close($r); $err = 'Нет подключения к базе данных'; return null; }
    $drv = db_driver();
    $cur = array_values(array_intersect(db_list_tables($p, $drv), backup_known_tables()));

    $plan = [];
    $skipped = [];
    foreach ($h['tables'] as $m) {
        $t = is_array($m) ? (string) ($m['t'] ?? '') : '';
        if ($t === '') continue;
        $plan[$t] = null;
        if ($t === 'login_attempts' || in_array($t, $caches, true) || ($no_logs && in_array($t, $logs, true))) continue;
        if (!in_array($t, $cur, true)) { $skipped[] = $t; continue; }
        $have = db_table_columns($p, $drv, $t);
        $idx = [];
        foreach (array_values((array) ($m['c'] ?? [])) as $i => $c) {
            if (in_array((string) $c, $have, true)) $idx[$i] = (string) $c;
        }
        if ($idx) $plan[$t] = ['idx' => $idx, 'n' => (int) ($m['n'] ?? 0)];
    }
    $total = 0;
    foreach ($plan as $pl) if ($pl) $total += $pl['n'];
    $wipe = array_values(array_filter($cur, static function ($t) use ($no_logs, $logs) { return $t !== 'login_attempts' && !($no_logs && in_array($t, $logs, true)); }));

    if ($tick) $tick('snap', '', 0, 0);
    $snapTick = $tick ? static function ($s, $t = '', $d = 0, $n = 0) use ($tick) { $tick('snap', $t, $d, $n); } : null;
    $snap = bk_create('pre', '', $no_logs, $snapTick, $e2);
    if ($snap === '') { bk_r_close($r); $err = 'Не удалось сохранить снимок текущих данных, поэтому восстановление не начато. ' . $e2; return null; }

    $C = cfg();
    $relogin = !$keep_login && ($login['user'] !== (string) ($C['admin_user'] ?? '') || $login['hash'] !== (string) ($C['admin_pass_hash'] ?? ''));
    $cfgTmp = '';
    if ($relogin) {
        $cfgTmp = bk_cfg_prepare($login, $err);
        if ($cfgTmp === '') { bk_r_close($r); @unlink(backup_dir() . '/' . $snap); return null; }
    }
    $keepRows = [];
    foreach ($p->query('SELECT k, v FROM settings')->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (backup_keep_setting($row['k'], $keep_login)) $keepRows[(string) $row['k']] = (string) $row['v'];
    }
    $oldGate = (string) setting('admin_gate_key', '');

    $loaded = 0;
    $per = [];
    $tx = false;
    // SQLite не следит за длиной и кодировкой колонок: в строгом режиме MySQL одна такая строка откатила бы всё
    // восстановление, без него MySQL подгоняет значение под колонку, как при обычной записи.
    $mode = null;
    if ($drv === 'mysql') {
        try {
            $mode = (string) $p->query('SELECT @@SESSION.sql_mode')->fetchColumn();
            $p->exec('SET SESSION sql_mode = ' . $p->quote(implode(',', array_diff(explode(',', $mode), ['STRICT_TRANS_TABLES', 'STRICT_ALL_TABLES']))));
        } catch (Throwable $e) { $mode = null; }
    }
    $modeBack = static function () use ($p, &$mode) {
        if ($mode === null) return;
        try { $p->exec('SET SESSION sql_mode = ' . $p->quote($mode)); } catch (Throwable $e) {}
        $mode = null;
    };
    try {
        if ($drv === 'mysql') { $p->beginTransaction(); $p->exec('SET FOREIGN_KEY_CHECKS=0'); }
        else $p->exec('BEGIN IMMEDIATE');
        $tx = true;
        foreach ($wipe as $t) {
            if ($tick) $tick('wipe', $t, 0, 0);
            if ($drv === 'mysql') {
                do {
                    $n = $p->exec('DELETE FROM ' . bk_q($t, $drv) . ' LIMIT 5000');
                    if ($tick) $tick('wipe', $t, 0, 0);
                } while ($n >= 5000);
            } else {
                $p->exec('DELETE FROM ' . bk_q($t, $drv));
            }
        }
        $t = null;
        $b = null;
        $isSet = false;
        $ki = false;
        $end = null;
        $e3 = '';
        if ($tick) $tick('load', '', 0, $total);
        while (($line = bk_r_line($r, $e3)) !== null) {
            if ($line === '') continue;
            if ($line[0] === '{') {
                $o = json_decode($line, true);
                if (is_array($o) && isset($o['end'])) { $end = $o; break; }
                if (!is_array($o) || !isset($o['t']) || !array_key_exists((string) $o['t'], $plan) || isset($per[(string) $o['t']])) throw new RuntimeException('Файл повреждён');
                if ($b) bk_ins_flush($b);
                $t = (string) $o['t'];
                $per[$t] = 0;
                $b = $plan[$t] ? bk_ins_prepare($p, $drv, $t, array_values($plan[$t]['idx'])) : null;
                $isSet = $t === 'settings';
                $ki = $plan[$t] ? array_search('k', array_values($plan[$t]['idx']), true) : false;
                continue;
            }
            if ($t === null) throw new RuntimeException('Файл повреждён');
            $per[$t]++;
            if (!$b) continue;
            $v = json_decode($line, true);
            if (!is_array($v)) throw new RuntimeException('Файл повреждён: таблица ' . $t);
            $row = [];
            foreach ($plan[$t]['idx'] as $i => $c) {
                $x = $v[$i] ?? null;
                if (is_array($x)) {
                    $x = base64_decode((string) ($x['b'] ?? ''), true);
                    if ($x === false) throw new RuntimeException('Файл повреждён: таблица ' . $t);
                }
                if ($drv === 'mysql' && is_string($x) && $x !== '' && !preg_match('//u', $x)) $x = mb_scrub($x, 'UTF-8');
                $row[] = $x;
            }
            if ($isSet && $ki !== false && backup_keep_setting($row[$ki], $keep_login)) continue;
            $b['rows'][] = $row;
            $b['bytes'] += strlen($line);
            if (count($b['rows']) >= $b['n'] || $b['bytes'] >= 524288) bk_ins_flush($b);
            $loaded++;
            if ($tick && ($loaded & 1023) === 0) $tick('load', $t, $loaded, $total);
        }
        if ($e3 !== '') throw new RuntimeException($e3);
        if ($end === null) throw new RuntimeException('Файл неполный — похоже, он скачался или загрузился не до конца');
        if ($b) bk_ins_flush($b);
        $declared = is_array($end['n'] ?? null) ? $end['n'] : [];
        if ((int) ($end['rows'] ?? -1) !== array_sum($per)) throw new RuntimeException('Файл повреждён: в нём не хватает записей');
        foreach ($declared as $dt => $dn) {
            if ((int) $dn !== (int) ($per[(string) $dt] ?? -1)) throw new RuntimeException('Файл повреждён: не хватает записей в таблице ' . $dt);
        }
        if ($keepRows && in_array('settings', $cur, true)) {
            $ins = $p->prepare(($drv === 'mysql' ? 'REPLACE' : 'INSERT OR REPLACE') . ' INTO settings (k, v) VALUES (?, ?)');
            foreach ($keepRows as $k => $v) $ins->execute([$k, $v]);
        }
        if ($tick) $tick('commit', '', $loaded, $total);
        if ($drv === 'mysql') { $p->exec('SET FOREIGN_KEY_CHECKS=1'); $p->commit(); }
        else $p->exec('COMMIT');
        $tx = false;
        $modeBack();
    } catch (Throwable $e) {
        if ($tx) {
            try {
                if ($drv === 'mysql') { $p->rollBack(); $p->exec('SET FOREIGN_KEY_CHECKS=1'); }
                else $p->exec('ROLLBACK');
            } catch (Throwable $e4) {}
        }
        $modeBack();
        bk_r_close($r);
        if ($cfgTmp !== '') @unlink($cfgTmp);
        @unlink(backup_dir() . '/' . $snap);
        $err = ($e instanceof PDOException ? 'Ошибка базы данных: ' : '') . $e->getMessage();
        return null;
    }
    bk_r_close($r);
    unset($GLOBALS['submw_settings_cache']);
    $warn = '';
    if ($cfgTmp !== '') {
        if (@rename($cfgTmp, config_path())) {
            if (function_exists('opcache_invalidate')) @opcache_invalidate(config_path(), true);
        } else {
            @unlink($cfgTmp);
            $relogin = false;
            $warn = 'Данные восстановлены, но логин и пароль из бэкапа записать не удалось — вход остался прежним.';
        }
    }
    $newGate = $keep_login ? $oldGate : (string) setting('admin_gate_key', '');
    return ['rows' => $loaded, 'snap' => $snap, 'skipped' => $skipped, 'relogin' => $relogin, 'gate' => $newGate !== $oldGate ? $newGate : null, 'warn' => $warn];
}

// ---- загрузка файла ----

function backup_upload(array $f, &$err) {
    $err = '';
    $code = (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE) { $err = 'Файл больше, чем сервер разрешает загружать'; return ''; }
    if ($code !== UPLOAD_ERR_OK) { $err = 'Файл не загрузился (код ошибки PHP ' . $code . ')'; return ''; }
    $tmp = (string) ($f['tmp_name'] ?? '');
    if (!submw_upload_ok($tmp)) { $err = 'Файл не загрузился'; return ''; }
    $enc = backup_is_enc($tmp);
    if (!$enc) {
        $r = bk_r_open($tmp, '', $err);
        if ($r === null) return '';
        $h = bk_r_head($r, $err);
        bk_r_close($r);
        if ($h === null) return '';
    }
    $dir = backup_dir_ready($err);
    if ($dir === '') return '';
    $name = backup_new_name('upload', $enc);
    $dst = $dir . '/' . $name;
    if (!@move_uploaded_file($tmp, $dst) && !@rename($tmp, $dst) && !@copy($tmp, $dst)) { $err = 'Не удалось сохранить файл в папке ' . $dir; return ''; }
    @chmod($dst, 0640);
    return $name;
}

// ---- потоковый ответ админке ----

function backup_stream_begin() {
    @set_time_limit(0);
    ignore_user_abort(true);
    header('Content-Type: application/x-ndjson; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Accel-Buffering: no');
    // В туннеле приложения ответ шифруется целиком из буфера — сбрасывать его нельзя.
    if (!app_tunnel()) {
        @ini_set('zlib.output_compression', '0');
        while (ob_get_level() > 0) @ob_end_flush();
    }
}

function backup_stream_out(array $o) {
    echo json_encode($o, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
    if (!app_tunnel()) @flush();
}

function backup_ticker() {
    $last = 0.0;
    $stage = '';
    return static function ($s, $t = '', $done = 0, $total = 0) use (&$last, &$stage) {
        $now = microtime(true);
        if ($s === $stage && $now - $last < 0.5) return;
        $last = $now;
        $stage = $s;
        backup_stream_out(['p' => $s, 't' => (string) $t, 'd' => (int) $done, 'n' => (int) $total]);
    };
}

function cron_task_backup($ctx) {
    $pass = backup_auto_pass();
    $err = '';
    return backup_create('auto', $pass, false, null, $err) !== '' ? '' : ($err !== '' ? $err : 'Не удалось создать бэкап');
}
