<?php

// Счётчик обращений к панели: кто (точка входа) какой адрес API дёргает. Копится в памяти процесса,
// в базу уходит одной транзакцией при завершении запроса — почасовыми суммами, без сырых строк.

const APISTAT_KEEP_DAYS = 7;

function apistat_src($src = null) {
    if ($src !== null) $GLOBALS['submw_api_src'] = mb_substr((string) $src, 0, 48);
    return (string) ($GLOBALS['submw_api_src'] ?? '');
}

function apistat_src_auto() {
    $s = apistat_src();
    if ($s !== '') return $s;
    if (PHP_SAPI === 'cli') return 'cli';
    $f = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if ($f === 'webhook.php') return 'webhook';
    if ($f === 'chat.php') return 'chat';
    return 'other';
}

// Идентификаторы в адресе заменяются на {x}: uuid, числа, shortUuid и прочие токены.
function apistat_ep($path) {
    $p = (string) parse_url((string) $path, PHP_URL_PATH);
    if ($p === '') $p = (string) $path;
    $segs = explode('/', trim($p, '/'));
    foreach ($segs as $i => $s) {
        if ($s === '' || preg_match('~^[a-z][a-z-]*$~', $s)) continue;
        if (preg_match('~^[0-9a-f]{8}-[0-9a-f]{4}-~i', $s) || preg_match('~^\d+$~', $s) || (strlen($s) >= 8 && preg_match('~^[A-Za-z0-9_-]+$~', $s))) $segs[$i] = '{x}';
    }
    return mb_substr('/' . implode('/', $segs), 0, 120);
}

function apistat_hit($method, $path, $code, $ms, $src = null) {
    $k = ($src ?? apistat_src_auto()) . '|' . strtoupper((string) $method) . '|' . apistat_ep($path);
    $b = &$GLOBALS['submw_apistat'];
    if (!is_array($b)) {
        $b = [];
        register_shutdown_function('apistat_flush');
    }
    if (!isset($b[$k])) $b[$k] = [0, 0, 0.0];
    $b[$k][0]++;
    if ((int) $code < 200 || (int) $code >= 400) $b[$k][1]++;
    $b[$k][2] += (float) $ms;
}

function apistat_ddl($drv) {
    if ($drv === 'mysql') {
        return ["CREATE TABLE IF NOT EXISTS api_stat_hour (
            h INT UNSIGNED NOT NULL, src VARCHAR(48) NOT NULL, method VARCHAR(8) NOT NULL, ep VARCHAR(120) NOT NULL,
            n INT UNSIGNED NOT NULL DEFAULT 0, err INT UNSIGNED NOT NULL DEFAULT 0, ms BIGINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (h, src, method, ep)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"];
    }
    return ["CREATE TABLE IF NOT EXISTS api_stat_hour (
        h INTEGER NOT NULL, src TEXT NOT NULL, method TEXT NOT NULL, ep TEXT NOT NULL,
        n INTEGER NOT NULL DEFAULT 0, err INTEGER NOT NULL DEFAULT 0, ms INTEGER NOT NULL DEFAULT 0,
        PRIMARY KEY (h, src, method, ep)
    )"];
}

function apistat_ensure() {
    static $ok = null;
    if ($ok !== null) return $ok;
    if (!function_exists('db') || !($p = db())) return false;
    // DDL в каждом запросе подписки — лишний metadata-lock на MySQL; флаг в настройках, как у метрик.
    if (setting('apistat_tables', '') === '1') return $ok = true;
    try { foreach (apistat_ddl(db_driver()) as $sql) $p->exec($sql); set_setting('apistat_tables', '1'); $ok = true; }
    catch (Throwable $e) { error_log('submw api_stat_hour table: ' . $e->getMessage()); $ok = false; }
    return $ok;
}

function apistat_flush() {
    $b = $GLOBALS['submw_apistat'] ?? null;
    unset($GLOBALS['submw_apistat']);
    if (!is_array($b) || !$b || !apistat_ensure()) return;
    $p = db();
    $h = intdiv(time(), 3600) * 3600;
    try {
        $p->beginTransaction();
        $upd = $p->prepare('UPDATE api_stat_hour SET n = n + ?, err = err + ?, ms = ms + ? WHERE h = ? AND src = ? AND method = ? AND ep = ?');
        $ins = $p->prepare('INSERT INTO api_stat_hour (h, src, method, ep, n, err, ms) VALUES (?, ?, ?, ?, ?, ?, ?)');
        foreach ($b as $k => [$n, $err, $ms]) {
            [$src, $m, $ep] = explode('|', $k, 3);
            $msi = (int) round($ms);
            $upd->execute([$n, $err, $msi, $h, $src, $m, $ep]);
            if ($upd->rowCount() === 0) {
                try { $ins->execute([$h, $src, $m, $ep, $n, $err, $msi]); }
                catch (Throwable $e) { $upd->execute([$n, $err, $msi, $h, $src, $m, $ep]); }
            }
        }
        $p->commit();
    } catch (Throwable $e) {
        try { $p->rollBack(); } catch (Throwable $e2) {}
        error_log('submw apistat flush: ' . $e->getMessage());
    }
}

function apistat_prune() {
    if (!apistat_ensure()) return;
    try {
        $st = db()->prepare('DELETE FROM api_stat_hour WHERE h < ?');
        $st->execute([intdiv(time(), 3600) * 3600 - APISTAT_KEEP_DAYS * 86400]);
    } catch (Throwable $e) {}
}

function apistat_src_label($src) {
    $src = (string) $src;
    $fixed = ['sub' => 'Запросы подписки', 'junk' => 'Неизвестные адреса (мусор)', 'webhook' => 'Вебхуки панели', 'chat' => 'Чат поддержки', 'chan' => 'Защищённый канал', 'app' => 'Приложение', 'page' => 'Страницы подписки и заглушка', 'cli' => 'Консоль', 'other' => 'Прочее', 'cron' => 'Планировщик'];
    if (isset($fixed[$src])) return $fixed[$src];
    if (strpos($src, 'cron:') === 0) {
        $t = function_exists('cron_tasks') ? cron_tasks() : [];
        $name = substr($src, 5);
        return 'Планировщик: ' . (isset($t[$name]) ? mb_strtolower($t[$name][0]) : $name);
    }
    if (strpos($src, 'admin:') === 0) {
        $tab = substr($src, 6);
        $titles = $GLOBALS['tab_titles'] ?? [];
        return 'Админка: ' . (isset($titles[$tab]) ? $titles[$tab] : $tab);
    }
    if (strpos($src, 'ajax:') === 0) {
        $ajax = ['usage' => 'трафик в карточке пользователя', 'sess_live' => '«сейчас онлайн» в карточке', 'sess_user' => 'сессии в карточке', 'sess_drop' => 'сброс соединений', 'hwids' => 'устройства в карточке', 'del_hwid' => 'удаление устройства', 'ua_user' => 'карточка пользователя', 'ua_meta' => 'справочники карточки (сквады, лимиты)', 'ua_save' => 'запись пользователя', 'ua_act' => 'действие с пользователем', 'tokscopes' => 'проверка прав токена', 'panelmeta' => 'версия панели', 'panelstats' => 'статистика панели на «О системе»', 'clod_names' => 'имена для статистики Clod Clash', 'pool_user' => 'поиск пользователя для WG / AWG', 'pool_sizing' => 'подсчёт устройств для пула', 'addsub_map_set' => 'привязка доп. подписки'];
        $n = substr($src, 5);
        return 'Админка, фоновый запрос: ' . ($ajax[$n] ?? $n);
    }
    if (strpos($src, 'post:') === 0) return 'Админка, действие: ' . substr($src, 5);
    return $src;
}

// Срез за $hours часов: итоги, ряд по часам, группы (точка входа) с адресами внутри.
function apistat_view($hours) {
    $hours = max(1, min(APISTAT_KEEP_DAYS * 24, (int) $hours));
    $out = ['hours' => $hours, 'total' => 0, 'err' => 0, 'ms' => 0, 'peak' => 0, 'peak_h' => 0, 'series' => [], 'groups' => []];
    $h0 = intdiv(time(), 3600) * 3600 - ($hours - 1) * 3600;
    for ($i = 0; $i < $hours; $i++) $out['series'][$h0 + $i * 3600] = 0;
    if (!apistat_ensure()) return $out;
    try {
        $st = db()->prepare('SELECT h, src, method, ep, n, err, ms FROM api_stat_hour WHERE h >= ? ORDER BY h');
        $st->execute([$h0]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return $out; }
    $g = [];
    foreach ($rows as $r) {
        $n = (int) $r['n']; $e = (int) $r['err']; $ms = (int) $r['ms']; $h = (int) $r['h'];
        $out['total'] += $n; $out['err'] += $e; $out['ms'] += $ms;
        if (isset($out['series'][$h])) $out['series'][$h] += $n;
        $src = (string) $r['src'];
        if (!isset($g[$src])) $g[$src] = ['src' => $src, 'label' => apistat_src_label($src), 'n' => 0, 'err' => 0, 'ms' => 0, 'eps' => []];
        $g[$src]['n'] += $n; $g[$src]['err'] += $e; $g[$src]['ms'] += $ms;
        $ek = $r['method'] . ' ' . $r['ep'];
        if (!isset($g[$src]['eps'][$ek])) $g[$src]['eps'][$ek] = ['ep' => $ek, 'n' => 0, 'err' => 0, 'ms' => 0];
        $g[$src]['eps'][$ek]['n'] += $n; $g[$src]['eps'][$ek]['err'] += $e; $g[$src]['eps'][$ek]['ms'] += $ms;
    }
    foreach ($out['series'] as $h => $n) if ($n > $out['peak']) { $out['peak'] = $n; $out['peak_h'] = $h; }
    foreach ($g as &$grp) {
        usort($grp['eps'], static fn($a, $b) => $b['n'] <=> $a['n']);
        $grp['eps'] = array_values($grp['eps']);
    }
    unset($grp);
    usort($g, static fn($a, $b) => $b['n'] <=> $a['n']);
    $out['groups'] = array_values($g);
    return $out;
}
