<?php

// Планировщик дополняет фоновые задачи, которые идут попутно с запросами, и ничего из них не
// выключает. Исключение одно: пока задача grace проходит, хвост запроса подписки её не повторяет.
//
// Состояние: beat — начало последнего запуска; у задачи start — начало последней попытки,
// ok_at — конец последней удачной. Попытка удалась, если ok_at >= start. Расписание, «жив» и
// «грейс на планировщике» выводятся только из этих отметок.

const CRON_ALIVE = 300;
const CRON_GRACE_SECONDS = 30;

// [название, интервал, только когда ответ уже отдан: долгие сетевые задачи]
function cron_tasks() {
    return [
        'grace'   => ['Проверки грейса', 60, false],
        'reports' => ['Отчёты: срок хранения и геобазы', 3600, false],
        'cleanup' => ['Чистка метрик, мусорных адресов и чата', 3600, false],
        'brand'   => ['Брендинг из панели', 3600, false],
        'update'  => ['Проверка обновлений', 3600, true],
    ] + (function_exists('cron_task_sessions') ? ['sessions' => ['Сессии: опрос нод', sess_every(), false]] : [])
      + (function_exists('cron_task_geo') && geoip_ipinfo() ? ['geo' => ['Гео: уточнение адресов через ipinfo', 60, false]] : [])
      + (function_exists('cron_task_backup') && backup_auto_on() ? ['backup' => ['Бэкап по расписанию', 86400, true]] : []);
}

// Отметка из будущего (часы сервера ушли назад) свежей не считается.
function cron_fresh($ts, $now, $window) {
    $age = (int) $now - (int) $ts;
    return (int) $ts > 0 && $age >= 0 && $age < $window;
}

function cron_state() {
    $s = json_decode((string) setting('cron_state', ''), true);
    return is_array($s) ? $s : [];
}

function cron_save(array $st) {
    set_setting('cron_state', json_encode($st, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
}

function cron_task_ok(array $t) { return (int) ($t['start'] ?? 0) > 0 && (int) ($t['ok_at'] ?? 0) >= (int) $t['start']; }

// Неудачная или прерванная попытка повторяется через 5 минут, удачная — по своему интервалу.
function cron_task_due($t, $every, $now) {
    if (!is_array($t) || (int) ($t['start'] ?? 0) <= 0) return true;
    $wait = cron_task_ok($t) ? $every : min($every, 300);
    return !cron_fresh($t['start'], $now, $wait - 5);
}

function cron_alive($state = null, $now = null) {
    $st = $state ?? cron_state();
    return cron_fresh($st['beat'] ?? 0, $now ?? time(), CRON_ALIVE);
}

function cron_owns_grace($now = null) {
    $t = cron_state()['tasks']['grace'] ?? null;
    return is_array($t) && cron_fresh($t['ok_at'] ?? 0, $now ?? time(), CRON_ALIVE);
}

function cron_secret() { return (string) setting('cron_secret', ''); }

function cron_secret_ensure() {
    $s = cron_secret();
    if (preg_match('~^[A-Za-z0-9]{32}$~', $s)) return $s;
    return cron_secret_rotate();
}

function cron_secret_rotate() {
    $a = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    $s = '';
    for ($i = 0; $i < 32; $i++) $s .= $a[random_int(0, 61)];
    set_setting('cron_secret', $s);
    return $s;
}

function cron_data_dir() { return dirname(default_db_path()); }

// Блокировка в data/: у сайта и консоли временные каталоги бывают разные.
function cron_lock_path() { return cron_data_dir() . '/.cron.lock'; }

function cron_euid() {
    if (function_exists('posix_geteuid')) return posix_geteuid();
    $f = @tempnam(cron_data_dir(), '.cron');
    if ($f === false) return null;
    clearstatcache();
    $o = @fileowner($f);
    @unlink($f);
    return $o === false ? null : (int) $o;
}

// Пользователь сайта запоминается из веба; консоль сверяется с ним.
function cron_note_web_user() {
    $u = cron_euid();
    if ($u !== null && (string) $u !== (string) setting('cron_web_uid', '')) set_setting('cron_web_uid', (string) $u);
}

// Пользователь, от которого должна идти консоль: сайт, а пока он не известен — владелец базы или data/.
function cron_site_uid() {
    $w = (string) setting('cron_web_uid', '');
    if ($w !== '' && ctype_digit($w)) return (int) $w;
    $c = db_conf();
    $db = (is_array($c) && ($c['driver'] ?? 'sqlite') !== 'mysql') ? (string) ($c['path'] ?? '') : '';
    clearstatcache();
    $o = ($db !== '' && is_file($db)) ? @fileowner($db) : @fileowner(cron_data_dir());
    return $o === false ? null : (int) $o;
}

function cron_user_name($uid) {
    if ($uid !== null && function_exists('posix_getpwuid')) {
        $pw = @posix_getpwuid($uid);
        if (is_array($pw) && ($pw['name'] ?? '') !== '') return (string) $pw['name'];
    }
    return $uid === null ? update_web_user() : (string) $uid;
}

// Чужой пользователь в консоли оставил бы в data/ свои файлы, и сайт перестал бы в них писать.
function cron_cli_ok($me, $site) {
    return $me === null || $site === null || $me === $site;
}

// Путь к PHP той же версии, что у сайта; под open_basedir его не проверить — тогда без проверки.
function cron_php_bin() {
    $cands = [PHP_BINDIR . '/php' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION, PHP_BINDIR . '/php'];
    foreach ($cands as $b) {
        if (@is_file($b) && @is_executable($b)) return $b;
    }
    return PHP_BINDIR !== '' ? $cands[1] : 'php';
}

function cron_cron_arg($s) { return str_replace('%', '\%', escapeshellarg((string) $s)); }

function cron_fail($src, $err) {
    error_log('submw cron (' . $src . '): ' . $err);
    try { set_setting('cron_error', json_encode(['ts' => time(), 'src' => (string) $src, 'err' => (string) $err], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)); } catch (Throwable $e) {}
}

function cron_ctx($src) {
    $detached = $src !== 'url' || function_exists('fastcgi_finish_request');
    $can = function_exists('set_time_limit');
    $budget = $detached ? 240 : 25;
    if (!$can) {
        $max = (int) ini_get('max_execution_time');
        if ($max > 0) $budget = min($budget, max(5, $max - 10));
    }
    return ['detached' => $detached, 'can' => $can, 'deadline' => microtime(true) + $budget];
}

// Задачи сами меняют лимит времени (rep_purge и др.) — возвращаем его к остатку бюджета.
function cron_limit(array $ctx) {
    if ($ctx['can']) @set_time_limit((int) max(30, $ctx['deadline'] - microtime(true) + 60));
}

// По одному: время проверяется после каждой проверки, а не после пачки.
function cron_task_grace($ctx) {
    $end = min(microtime(true) + ($ctx['grace_cap'] ?? CRON_GRACE_SECONDS), $ctx['deadline'] - 2);
    $n = 0;
    $err = 0;
    do {
        $r = grace_retry_pending(1);
        if (!is_array($r) || (int) $r['n'] === 0) break;
        $n += (int) $r['n'];
        $err += (int) $r['err'];
    } while (microtime(true) < $end);
    return ($n > 0 && $err === $n) ? 'панель не ответила ни на одну проверку (' . $n . ')' : '';
}

function cron_task_reports($ctx) {
    $sess = function_exists('sess_enabled') && sess_enabled();
    if (!rep_enabled() && !$sess) return '';
    if (rep_enabled()) rep_purge_maybe();
    if ($ctx['detached']) geoip_maybe_update();
    return '';
}

function cron_task_cleanup($ctx) {
    $p = db();
    if (!$p) return 'нет базы';
    if (ensure_metrics_tables()) metrics_prune($p);
    junk_ensure();
    junk_prune($p);
    chat_gc();
    if (function_exists('apistat_prune')) apistat_prune();
    return '';
}

function cron_task_brand($ctx) {
    brand_refresh_if_stale();
    return '';
}

function cron_task_update($ctx) {
    update_autocheck();
    return '';
}

function cron_run($src) {
    if (!is_installed()) return ['ok' => true, 'ran' => [], 'skip' => 'прослойка ещё не установлена'];
    if (!db()) {
        $err = 'нет подключения к базе (PHP ' . PHP_VERSION . ' · ' . PHP_SAPI . ')';
        error_log('submw cron (' . $src . '): ' . $err);
        return ['ok' => false, 'err' => $err];
    }
    $lock = @fopen(cron_lock_path(), 'c');
    if (!$lock) {
        $err = 'не открыть ' . cron_lock_path() . ' — нет записи в data/';
        cron_fail($src, $err);
        return ['ok' => false, 'err' => $err];
    }
    if (!flock($lock, LOCK_EX | LOCK_NB)) { fclose($lock); return ['ok' => true, 'ran' => [], 'skip' => 'предыдущий запуск ещё идёт']; }
    @ignore_user_abort(true);
    $ctx = cron_ctx($src);
    cron_limit($ctx);
    $ran = [];
    try {
        // Под блокировкой — только свежее состояние: настройки этого процесса могли загрузиться до неё.
        unset($GLOBALS['submw_settings_cache']);
        $now = time();
        $st = cron_state();
        $st['beat'] = $now;
        $st['src'] = (string) $src;
        if (!is_array($st['tasks'] ?? null)) $st['tasks'] = [];
        cron_save($st);
        $tasks = cron_tasks();
        // Короткий бюджет «по адресу» без fpm: грейс не должен забирать его целиком, иначе опрос сессий не стартует никогда.
        if (!$ctx['detached'] && isset($tasks['sessions']) && sess_enabled() && cron_task_due($st['tasks']['sessions'] ?? null, $tasks['sessions'][1], $now)) $ctx['grace_cap'] = 8;
        foreach ($tasks as $name => [$title, $every, $heavy]) {
            if ($heavy && !$ctx['detached']) continue;
            if (!cron_task_due($st['tasks'][$name] ?? null, $every, $now)) continue;
            if (microtime(true) > $ctx['deadline']) break;
            $t = is_array($st['tasks'][$name] ?? null) ? $st['tasks'][$name] : [];
            $t['start'] = time();
            $t['err'] = '';
            $st['tasks'][$name] = $t;
            cron_save($st);
            $m = microtime(true);
            if (function_exists('apistat_src')) apistat_src('cron:' . $name);
            try {
                $err = (string) ('cron_task_' . $name)($ctx);
            } catch (Throwable $e) {
                $err = $e->getMessage();
                error_log('submw cron ' . $name . ': ' . $err);
            }
            cron_limit($ctx);
            if ($err === '') $t['ok_at'] = time();
            $t['err'] = mb_substr($err, 0, 200);
            $st['tasks'][$name] = $t;
            $ran[$name] = ['ok' => $err === '', 'err' => $t['err'], 'ms' => (int) round((microtime(true) - $m) * 1000)];
            cron_save($st);
        }
    } catch (Throwable $e) {
        cron_fail($src, 'не записать состояние: ' . $e->getMessage());
        return ['ok' => false, 'err' => 'не записать состояние: ' . $e->getMessage()];
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    return ['ok' => true, 'ran' => $ran];
}

// Для блока «Планировщик»: всё уже разобрано, шаблону остаётся вывести.
function cron_view($now = null) {
    $now = $now ?? time();
    $st = cron_state();
    $beat = (int) ($st['beat'] ?? 0);
    $src = (string) ($st['src'] ?? '');
    $rows = [];
    foreach (cron_tasks() as $name => [$title, $every, $heavy]) {
        $t = $st['tasks'][$name] ?? null;
        if (!is_array($t) || (int) ($t['start'] ?? 0) <= 0) {
            $rows[] = ['title' => $title, 'state' => ($heavy && $src === 'url') ? ($name === 'update' ? 'admin' : 'nofpm') : 'never', 'ts' => 0, 'err' => ''];
        } elseif (cron_task_ok($t)) {
            $rows[] = ['title' => $title, 'state' => 'ok', 'ts' => (int) $t['ok_at'], 'err' => ''];
        } elseif ((string) ($t['err'] ?? '') === '' && cron_fresh($t['start'], $now, 300)) {
            $rows[] = ['title' => $title, 'state' => 'run', 'ts' => (int) $t['start'], 'err' => ''];
        } else {
            $rows[] = ['title' => $title, 'state' => 'err', 'ts' => (int) $t['start'], 'err' => (string) ($t['err'] ?? '') !== '' ? (string) $t['err'] : 'не завершилась'];
        }
    }
    $e = json_decode((string) setting('cron_error', ''), true);
    $error = (is_array($e) && (int) ($e['ts'] ?? 0) >= $beat) ? $e : null;
    $status = cron_alive($st, $now) ? 'alive' : ($beat > 0 ? 'stopped' : 'never');
    $label = ['url' => 'cron по адресу', 'cli' => 'cron из консоли', 'docker' => 'Docker'][$src] ?? '';
    $errors = count(array_filter($rows, static function ($r) { return $r['state'] === 'err'; })) + ($error ? 1 : 0);
    return ['status' => $status, 'beat' => $beat, 'src' => $label, 'rows' => $rows, 'error' => $error, 'errors' => $errors];
}

// Секретный путь: без верного секрета запрос идёт дальше как обычный неизвестный адрес.
function cron_intercept() {
    $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    if (strlen($path) < 33 || !preg_match('~/([A-Za-z0-9]{32})$~', $path, $m)) return false;
    $sec = cron_secret();
    if (strlen($sec) !== 32 || !hash_equals($sec, $m[1])) return false;
    $GLOBALS['submw_skip_metric'] = 1;
    header_remove('X-Powered-By');
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    header('Content-Length: 3');
    header('Connection: close');
    echo "ok\n";
    if (function_exists('fastcgi_finish_request')) {
        @fastcgi_finish_request();
    } else {
        while (ob_get_level() > 0) @ob_end_flush();
        @flush();
    }
    cron_note_web_user();
    cron_run('url');
    return true;
}
