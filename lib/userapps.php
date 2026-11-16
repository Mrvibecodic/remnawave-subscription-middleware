<?php

const UAPPS_TTL = 900;

// Устройства пользователей из панели (HWID): приложение, версия и платформа каждого. Панель опрашивается только здесь —
// когда вкладку открыли, а снимок старше UAPPS_TTL; фоновых обновлений нет. Статус версии считается при выдаче:
// каталог версий мог обновиться позже снимка.
function uapps_view(&$error = '') {
    $error = '';
    $key = 'pref_hwdev';
    $due = static fn($c) => !is_array($c) || !is_array($c['D'] ?? null) || time() - (int) ($c['ts'] ?? 0) >= UAPPS_TTL;
    $paused = static fn($c) => is_array($c) && time() - (int) ($c['e'] ?? 0) < PANEL_REF_RETRY;
    $c = cmap_cache_get($key);
    if ($due($c) && !$paused($c)) {
        // Второе одновременное открытие ждёт первое и берёт его снимок.
        $lk = panel_ref_lock('hwdev');
        try {
            $c = cmap_cache_get($key);
            if ($due($c) && !$paused($c)) {
                $t0 = time();
                $e = '';
                $keep = ['userId' => 1, 'deviceModel' => 1, 'platform' => 1, 'osVersion' => 1, 'userAgent' => 1, 'updatedAt' => 1, 'createdAt' => 1];
                $devs = remnawave_hwid_all_devices($e, static fn($d) => is_array($d) ? array_intersect_key($d, $keep) : $d);
                $A = []; $Ai = []; $U = []; $Ui = []; $D = []; $seen = [];
                if ($e === '') {
                    $names = cmap_names(false);
                    foreach ($devs as $d) {
                        if (is_array($d) && !isset($names[(string) (int) ($d['userId'] ?? 0)])) {
                            // Пользователя нет в снимке — он новее снимка: список перечитывается один раз.
                            $names = cmap_names(true) ?: $names;
                            break;
                        }
                    }
                    foreach ($devs as $d) {
                        $uid = is_array($d) ? (int) ($d['userId'] ?? 0) : 0;
                        if ($uid <= 0) continue;
                        $hw = whlog_device_info($d);
                        $ua = (string) ($hw['ua'] ?? '');
                        $cl = $seen[$ua] ??= reqlog_client($ua);
                        $ver = (string) $cl['ver'];
                        $name = $cl['key'] === '' ? '' : (string) $cl['app'];
                        if ($ver !== '' && substr($name, -strlen(' ' . $ver)) === ' ' . $ver) $name = substr($name, 0, -strlen(' ' . $ver));
                        if ($ver === '' && $name !== '' && preg_match('~\(build (\d{1,9})[;)]~i', $ua, $bm)) $ver = 'сборка ' . $bm[1];
                        $ver = mb_substr($ver, 0, 24);
                        $ak = (string) $cl['key'];
                        if (!isset($Ai[$ak])) { $Ai[$ak] = count($A); $A[] = [$ak, mb_substr($name, 0, 48)]; }
                        if (!isset($Ui[$uid])) { $Ui[$uid] = count($U); $nm = $names[(string) $uid] ?? ['', '']; $U[] = [$nm[0], $nm[1], $uid]; }
                        $pl = strtolower((string) ($hw['p'] ?? ''));
                        $g = reqlog_os_norm($pl);
                        if ($g === '') $g = (string) $cl['os'];
                        if (strpos($pl, 'tv') !== false) $pk = 'tv';
                        elseif ($g === 'android' || $g === 'ios') $pk = $g;
                        elseif (strpos($pl, 'win') === 0) $pk = 'windows';
                        elseif (preg_match('~mac|darwin|osx~', $pl)) $pk = 'macos';
                        elseif (strpos($pl, 'linux') !== false) $pk = 'linux';
                        else $pk = $g === 'desktop' ? 'pc' : 'other';
                        $os = (string) ($hw['p'] ?? '');
                        $ov = (string) ($hw['os'] ?? '');
                        if ($ov !== '' && stripos($os, $ov) === false) $os = trim($os . ' ' . $ov);
                        $ts = strtotime((string) ($d['updatedAt'] ?? $d['createdAt'] ?? ''));
                        $D[] = [$Ai[$ak], $pk, $g, $ver, $Ui[$uid], (string) ($hw['m'] ?? ''), $os, $ts === false ? 0 : $ts];
                    }
                }
                if ($e === '') $c = ['ts' => $t0, 'A' => $A, 'U' => $U, 'D' => $D];
                else { $c = is_array($c) ? $c : []; $c['e'] = time(); $c['em'] = $e; }
                cmap_cache_put($key, $c);
            }
        } finally {
            if ($lk) { flock($lk, LOCK_UN); fclose($lk); }
        }
    }
    if ($paused($c)) $error = (string) ($c['em'] ?? 'панель недоступна');
    if (!is_array($c) || !is_array($c['D'] ?? null)) return null;
    $st = [];
    foreach ($c['D'] as $i => $r) {
        $k = $r[0] . '|' . $r[2] . '|' . $r[3];
        $c['D'][$i][2] = $st[$k] ??= ['ok' => 0, 'ahead' => 0, 'patch' => 1, 'minor' => 2][clientver_status($c['A'][$r[0]][0] ?? '', $r[3], $r[2])['s']] ?? 3;
    }
    unset($c['e'], $c['em']);
    return $c + ['ttl' => UAPPS_TTL, 'cv' => clientver_enabled()];
}
