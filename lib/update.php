<?php

function update_repo() { return 'Mrvibecodic/remnawave-subscription-middleware'; }
function update_branch() { return 'main'; }
function update_root() { return dirname(__DIR__); }

function update_web_user() {
    if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
        $u = @posix_getpwuid(posix_geteuid());
        if (is_array($u) && !empty($u['name'])) return $u['name'];
    }
    $u = getenv('USER') ?: getenv('USERNAME');
    return ($u !== false && $u !== '') ? $u : 'www-data';
}

function update_protected_paths() {
    return ['config.php', 'config.example.php', '.git', 'backups', '.backups', 'docs', 'README.md', 'install.sh'];
}

function update_path_ok($rel) {
    $rel = ltrim(str_replace('\\', '/', (string) $rel), '/');
    if ($rel === '' || strpos($rel, '..') !== false || $rel[0] === '/') return false;
    if (!preg_match('~^[A-Za-z0-9._/\- ]+$~', $rel)) return false;
    foreach (update_protected_paths() as $p) {
        if ($rel === $p || strpos($rel, $p . '/') === 0) return false;
    }
    return true;
}

function update_http_get($url, &$err = null, $accept = 'application/vnd.github+json') {
    $err = null;
    if (!function_exists('curl_init')) { $err = 'curl недоступен'; return null; }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_USERAGENT      => 'submw-updater',
        CURLOPT_ENCODING       => '',
        CURLOPT_HTTPHEADER     => ['Accept: ' . $accept, 'X-GitHub-Api-Version: 2022-11-28'],
    ]);
    $body = curl_exec($ch);
    if ($body === false) { $err = 'Сеть: ' . curl_error($ch); curl_close($ch); return null; }
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code === 403 || $code === 429) { $err = 'GitHub: лимит запросов (' . $code . '), попробуйте позже'; return null; }
    if ($code === 404) { $err = 'GitHub: не найдено (404) — проверьте репозиторий/ветку'; return null; }
    if ($code < 200 || $code >= 300) { $err = 'GitHub HTTP ' . $code; return null; }
    return $body;
}

function update_api($path, &$err = null) {
    $body = update_http_get('https://api.github.com/repos/' . update_repo() . $path, $err);
    if ($body === null) return null;
    $j = json_decode($body, true);
    if (!is_array($j)) { $err = 'Некорректный ответ GitHub'; return null; }
    return $j;
}

function update_latest_commit(&$err = null) {
    $j = update_api('/commits/' . rawurlencode(update_branch()), $err);
    if ($j === null) return null;
    return [
        'sha'  => (string) ($j['sha'] ?? ''),
        'date' => (string) ($j['commit']['committer']['date'] ?? ($j['commit']['author']['date'] ?? '')),
        'msg'  => trim((string) ($j['commit']['message'] ?? '')),
    ];
}

function update_local_git_commit() {
    $gitdir = update_root() . '/.git';
    if (is_file($gitdir)) {
        $c = trim((string) @file_get_contents($gitdir));
        if (strpos($c, 'gitdir:') === 0) $gitdir = trim(substr($c, 7));
    }
    if (!is_dir($gitdir)) return '';
    $head = trim((string) @file_get_contents($gitdir . '/HEAD'));
    if ($head === '') return '';
    if (strpos($head, 'ref:') === 0) {
        $ref = trim(substr($head, 4));
        $rp = $gitdir . '/' . $ref;
        if (is_file($rp)) {
            $sha = trim((string) @file_get_contents($rp));
            if (preg_match('/^[0-9a-f]{40}$/', $sha)) return $sha;
        }
        $pr = $gitdir . '/packed-refs';
        if (is_file($pr)) {
            foreach (@file($pr) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#' || $line[0] === '^') continue;
                $p = preg_split('/\s+/', $line);
                if (count($p) === 2 && $p[1] === $ref && preg_match('/^[0-9a-f]{40}$/', $p[0])) return $p[0];
            }
        }
        return '';
    }
    return preg_match('/^[0-9a-f]{40}$/', $head) ? $head : '';
}

function update_installed_commit() {
    $stored = trim((string) setting('installed_commit', ''));
    if ($stored !== '') return $stored;
    $git = update_local_git_commit();
    if ($git !== '') { set_setting('installed_commit', $git); return $git; }
    return '';
}

function update_compare($base, $head, &$err = null) {
    $j = update_api('/compare/' . rawurlencode($base) . '...' . rawurlencode($head), $err);
    if ($j === null) return null;
    $files = [];
    foreach ((array) ($j['files'] ?? []) as $f) {
        $files[] = [
            'filename' => (string) ($f['filename'] ?? ''),
            'status'   => (string) ($f['status'] ?? ''),
            'previous' => (string) ($f['previous_filename'] ?? ''),
        ];
    }
    $commits = [];
    foreach ((array) ($j['commits'] ?? []) as $c) {
        $commits[] = [
            'sha' => substr((string) ($c['sha'] ?? ''), 0, 7),
            'msg' => trim(strtok((string) ($c['commit']['message'] ?? ''), "\n")),
        ];
    }
    return [
        'ahead_by' => (int) ($j['ahead_by'] ?? 0),
        'commits'  => $commits,
        'files'    => $files,
    ];
}

function update_state() {
    $s = json_decode((string) setting('update_state', '{}'), true);
    return is_array($s) ? $s : [];
}

function update_save_state($st) {
    set_setting('update_state', json_encode($st, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function update_refresh(&$err = null) {
    $latest = update_latest_commit($err);
    if ($latest === null || $latest['sha'] === '') {
        $st = update_state();
        $st['checked_at'] = time();
        $st['last_err']   = (string) $err;
        update_save_state($st);
        return null;
    }
    $installed = update_installed_commit();
    $st = [
        'checked_at'    => time(),
        'latest_sha'    => $latest['sha'],
        'latest_date'   => $latest['date'],
        'latest_msg'    => $latest['msg'],
        'installed_sha' => $installed,
        'ahead_by'      => 0,
        'commits'       => [],
        'files'         => [],
        'last_err'      => '',
    ];
    if ($installed !== '' && $installed !== $latest['sha']) {
        $cmp = update_compare($installed, $latest['sha'], $e2);
        if ($cmp !== null) {
            $st['ahead_by'] = $cmp['ahead_by'];
            $st['commits']  = $cmp['commits'];
            $st['files']    = $cmp['files'];
        } else {
            $st['last_err'] = (string) $e2;
        }
    }
    update_save_state($st);
    return $st;
}

function update_autocheck() {
    $st = update_state();
    if (time() - (int) ($st['checked_at'] ?? 0) >= 86400) {
        update_refresh($e);
    }
}

function update_available() {
    $st = update_state();
    $installed = update_installed_commit();
    if ($installed === '') return false;
    $latest = (string) ($st['latest_sha'] ?? '');
    return $latest !== '' && $latest !== $installed && (int) ($st['ahead_by'] ?? 0) > 0;
}

function update_set_current(&$err = null) {
    $latest = update_latest_commit($err);
    if ($latest === null || $latest['sha'] === '') return false;
    set_setting('installed_commit', $latest['sha']);
    update_refresh($e);
    return true;
}

function update_rmrf($dir) {
    if (!is_dir($dir)) return;
    $items = @scandir($dir);
    if ($items === false) return;
    foreach ($items as $it) {
        if ($it === '.' || $it === '..') continue;
        $p = $dir . '/' . $it;
        if (is_dir($p)) update_rmrf($p); else @unlink($p);
    }
    @rmdir($dir);
}

function update_raw_url($sha, $rel) {
    $parts = array_map('rawurlencode', explode('/', $rel));
    return 'https://raw.githubusercontent.com/' . update_repo() . '/' . rawurlencode($sha) . '/' . implode('/', $parts);
}

function update_apply(&$log, &$err = null) {
    $log = [];
    $installed = update_installed_commit();
    if ($installed === '') { $err = 'Базовый коммит не задан — сначала «Отметить текущую версию».'; return false; }
    $latest = update_latest_commit($err);
    if ($latest === null || $latest['sha'] === '') return false;
    if ($installed === $latest['sha']) { $err = 'Уже на последней версии.'; return false; }
    $cmp = update_compare($installed, $latest['sha'], $err);
    if ($cmp === null) return false;

    $root = update_root();
    $bdir = $root . '/.backups';
    if (!is_dir($bdir) && !@mkdir($bdir, 0775, true)) { $err = 'Не удалось создать каталог .backups/'; return false; }
    if (!is_writable($bdir)) { $err = 'Каталог .backups/ недоступен на запись'; return false; }
    if (!is_writable($root)) { $err = 'Корень установки недоступен на запись'; return false; }

    $writes = [];
    $deletes = [];
    foreach ($cmp['files'] as $f) {
        $rel = $f['filename'];
        if (!update_path_ok($rel)) { $log[] = 'пропущен (защищён): ' . $rel; continue; }
        if ($f['status'] === 'removed') { $deletes[] = $rel; continue; }
        $writes[] = $rel;
        if ($f['status'] === 'renamed' && $f['previous'] !== '' && update_path_ok($f['previous'])) {
            $deletes[] = $f['previous'];
        }
    }
    if (!$writes && !$deletes) { $err = 'Нет файлов для применения (всё защищено или пусто).'; return false; }

    $unwritable = [];
    foreach ($writes as $rel) {
        $dst = $root . '/' . $rel;
        if (is_file($dst)) {
            if (!is_writable($dst)) $unwritable[] = $rel;
        } else {
            $d = dirname($dst);
            while (!is_dir($d) && strlen($d) > strlen($root)) $d = dirname($d);
            if (!is_writable($d)) $unwritable[] = $rel;
        }
    }
    foreach ($deletes as $rel) {
        $dst = $root . '/' . $rel;
        if (is_file($dst) && !is_writable(dirname($dst))) $unwritable[] = $rel;
    }
    if ($unwritable) {
        $err = 'Нет прав на запись (' . count($unwritable) . '): ' . implode(', ', array_slice($unwritable, 0, 4)) . (count($unwritable) > 4 ? ' …' : '') . '. Дайте веб-серверу права на каталог установки или обновитесь через git pull (см. README).';
        return false;
    }

    $tmp = $bdir . '/.tmp-' . substr($latest['sha'], 0, 12);
    update_rmrf($tmp);
    if (!@mkdir($tmp, 0775, true)) { $err = 'Не удалось создать временный каталог'; return false; }

    foreach ($writes as $rel) {
        $data = update_http_get(update_raw_url($latest['sha'], $rel), $e2, 'application/vnd.github.raw');
        if ($data === null) { update_rmrf($tmp); $err = 'Не удалось скачать ' . $rel . ': ' . $e2; return false; }
        $tp = $tmp . '/' . $rel;
        if (!is_dir(dirname($tp)) && !@mkdir(dirname($tp), 0775, true)) { update_rmrf($tmp); $err = 'Не создать temp-подкаталог для ' . $rel; return false; }
        if (@file_put_contents($tp, $data) === false) { update_rmrf($tmp); $err = 'Не записать временный ' . $rel; return false; }
    }

    $backup = $bdir . '/' . date('Ymd-His') . '_' . substr($installed, 0, 7) . '-' . substr($latest['sha'], 0, 7);
    if (!@mkdir($backup, 0775, true)) { update_rmrf($tmp); $err = 'Не удалось создать каталог бэкапа'; return false; }
    $added = [];
    $restored = [];
    foreach (array_merge($writes, $deletes) as $rel) {
        $src = $root . '/' . $rel;
        if (is_file($src)) {
            $bp = $backup . '/' . $rel;
            if (!is_dir(dirname($bp))) @mkdir(dirname($bp), 0775, true);
            @copy($src, $bp);
            $restored[] = $rel;
        } elseif (in_array($rel, $writes, true)) {
            $added[] = $rel;
        }
    }

    $manifest = ['base' => $installed, 'target' => $latest['sha'], 'added' => array_values(array_unique($added)), 'restored' => array_values(array_unique($restored)), 'ts' => time()];
    @file_put_contents($backup . '/.manifest.json', json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    set_setting('update_last_backup', basename($backup));

    foreach ($writes as $rel) {
        $dst = $root . '/' . $rel;
        if ((!is_dir(dirname($dst)) && !@mkdir(dirname($dst), 0775, true)) || !@copy($tmp . '/' . $rel, $dst)) {
            $rb = []; update_rollback($rb, $e3);
            update_rmrf($tmp);
            $err = 'Сбой записи ' . $rel . ' — изменения откачены из бэкапа.';
            return false;
        }
        $log[] = (in_array($rel, $added, true) ? 'добавлен: ' : 'обновлён: ') . $rel;
    }
    foreach ($deletes as $rel) {
        $dst = $root . '/' . $rel;
        if (is_file($dst)) { @unlink($dst); $log[] = 'удалён: ' . $rel; }
    }
    update_rmrf($tmp);

    set_setting('installed_commit', $latest['sha']);
    update_refresh($e);
    return true;
}

function update_rollback(&$log, &$err = null) {
    $log = [];
    $name = trim((string) setting('update_last_backup', ''));
    if ($name === '' || strpos($name, '/') !== false || strpos($name, '..') !== false) { $err = 'Нет корректного последнего бэкапа.'; return false; }
    $root = update_root();
    $bdir = $root . '/.backups/' . $name;
    if (!is_dir($bdir)) { $err = 'Бэкап не найден: ' . $name; return false; }
    $manifest = json_decode((string) @file_get_contents($bdir . '/.manifest.json'), true);
    if (!is_array($manifest)) $manifest = [];

    foreach ((array) ($manifest['restored'] ?? []) as $rel) {
        if (!update_path_ok($rel)) continue;
        $bp = $bdir . '/' . $rel;
        if (!is_file($bp)) continue;
        $dst = $root . '/' . $rel;
        if (!is_dir(dirname($dst))) @mkdir(dirname($dst), 0775, true);
        if (@copy($bp, $dst)) $log[] = 'восстановлен: ' . $rel;
    }
    foreach ((array) ($manifest['added'] ?? []) as $rel) {
        if (!update_path_ok($rel)) continue;
        $dst = $root . '/' . $rel;
        if (is_file($dst)) { @unlink($dst); $log[] = 'убран добавленный: ' . $rel; }
    }
    if (!empty($manifest['base'])) set_setting('installed_commit', (string) $manifest['base']);
    update_refresh($e);
    return true;
}
