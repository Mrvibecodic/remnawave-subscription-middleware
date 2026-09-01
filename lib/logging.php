<?php

function ensure_reqlog_hwid() {
    static $done = false;
    if ($done) return;
    $done = true;
    if (!($p = db())) return;
    if (setting('reqlog_hwid_col', '') !== '1') {
        try { $p->exec('ALTER TABLE request_log ADD COLUMN hwid TEXT NULL'); } catch (Throwable $e) {}
        set_setting('reqlog_hwid_col', '1');
    }
    if (setting('reqlog_isapp_col', '') !== '1') {
        try { $p->exec('ALTER TABLE request_log ADD COLUMN is_app INTEGER NOT NULL DEFAULT 1'); } catch (Throwable $e) {}
        set_setting('reqlog_isapp_col', '1');
    }
    if (setting('reqlog_hwid_idx', '') !== '1') {
        try {
            if (db_driver() === 'mysql') $p->exec('ALTER TABLE request_log ADD INDEX idx_rl_hwid (hwid)');
            else $p->exec('CREATE INDEX IF NOT EXISTS idx_rl_hwid ON request_log(hwid)');
        } catch (Throwable $e) {}
        set_setting('reqlog_hwid_idx', '1');
    }
    if (setting('reqlog_meta_cols', '') !== '1') {
        $my = db_driver() === 'mysql';
        $cols = $my
            ? ['fmt VARCHAR(16) NULL', 'ctype VARCHAR(128) NULL', 'bytes INT UNSIGNED NULL', 'meta TEXT NULL']
            : ['fmt TEXT NULL', 'ctype TEXT NULL', 'bytes INTEGER NULL', 'meta TEXT NULL'];
        foreach ($cols as $c) {
            try { $p->exec('ALTER TABLE request_log ADD COLUMN ' . $c); } catch (Throwable $e) {}
        }
        set_setting('reqlog_meta_cols', '1');
    }
    if (setting('reqlog_browser_purge', '') !== '2') {
        try { $p->exec("DELETE FROM request_log WHERE decision = 'browser' OR user_agent LIKE 'Mozilla/%'"); } catch (Throwable $e) {}
        set_setting('reqlog_browser_purge', '2');
    }
}

function is_browser_ua($ua) {
    $ua = strtolower((string) $ua);
    if ($ua === '') return false;
    if (preg_match('~v2ray|nekobox|nekoray|sing-box|sing_box|hiddify|streisand|shadowrocket|stash|clash|mihomo|\bmeta\b|flclash|clashx|verge|happ|ktor|okhttp|go-http|v2box|foxray|karing|\bloon\b|surge|quantumult|throne|exclave|husi|matsuri|wireguard|outline|sfa|sfi|sft~', $ua)) return false;
    return (strpos($ua, 'mozilla') !== false || strpos($ua, 'applewebkit') !== false || strpos($ua, 'gecko') !== false)
        && preg_match('~chrome|chromium|safari|firefox|\bedg|\bopr\b|trident|gecko/~', $ua);
}

function nolog_shortuuids() {
    $arr = json_decode((string) setting('nolog_shortuuids', '[]'), true);
    $out = [];
    if (is_array($arr)) {
        foreach ($arr as $s) {
            $s = trim((string) $s);
            if ($s !== '') $out[$s] = true;
        }
    }
    return $out;
}

function nolog_is_set($short_uuid) {
    $short_uuid = trim((string) $short_uuid);
    if ($short_uuid === '') return false;
    $set = nolog_shortuuids();
    return isset($set[$short_uuid]);
}

function nolog_set($short_uuid, $on) {
    $short_uuid = trim((string) $short_uuid);
    if ($short_uuid === '') return false;
    $set = nolog_shortuuids();
    if ($on) {
        $set[$short_uuid] = true;
    } else {
        unset($set[$short_uuid]);
    }
    return set_setting('nolog_shortuuids', json_encode(array_keys($set), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function log_request($ip, $short_uuid, $path, $ua, $decision, $expire_ts = null, $hwid = '', array $meta = []) {
    if (!($p = db())) return;
    ensure_reqlog_hwid();
    $extra = [];
    foreach (['as', 'wg', 'grace', 'sub', 'dv'] as $k) {
        if (isset($meta[$k]) && $meta[$k] !== '' && $meta[$k] !== null && $meta[$k] !== []) $extra[$k] = $meta[$k];
    }
    try {
        $stmt = $p->prepare(
            'INSERT INTO request_log (ip, short_uuid, path, user_agent, decision, expire_ts, hwid, is_app, fmt, ctype, bytes, meta)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $ip,
            $short_uuid !== '' ? $short_uuid : null,
            mb_substr((string) $path, 0, 255),
            mb_substr((string) $ua, 0, 255),
            $decision,
            $expire_ts,
            $hwid !== '' ? mb_substr((string) $hwid, 0, 191) : null,
            is_browser_ua($ua) ? 0 : 1,
            isset($meta['fmt']) && $meta['fmt'] !== '' ? mb_substr((string) $meta['fmt'], 0, 16) : null,
            isset($meta['ctype']) && $meta['ctype'] !== '' ? mb_substr((string) $meta['ctype'], 0, 128) : null,
            isset($meta['bytes']) ? max(0, (int) $meta['bytes']) : null,
            $extra ? mb_substr((string) json_encode($extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 500) : null,
        ]);
        if (random_int(1, 200) === 1) {
            $keep = request_log_retention();
            if ($keep > 0) {
                $p->exec("DELETE FROM request_log WHERE id < (
                    SELECT id FROM (SELECT id FROM request_log ORDER BY id DESC LIMIT 1 OFFSET $keep) t
                )");
            }
        }
    } catch (Throwable $e) {
        error_log('submw log_request: ' . $e->getMessage());
    }
}

function reqlog_pages_enabled() { return setting('reqlog_log_pages', '0') === '1'; }

function reqlog_addsub_count($body, $format) {
    $body = (string) $body;
    if ($body === '') return 0;
    if ($format === 'base64') {
        $raw = base64_decode(preg_replace('~\s+~', '', $body), true);
        if ($raw === false || $raw === '') $raw = $body;
        return count(array_filter(preg_split('~\r?\n~', $raw), fn($l) => strpos($l, '://') !== false));
    }
    return 0;
}

function reqlog_detect_fmt($ctype, $path, $ua = '') {
    $ct  = strtolower(trim(explode(';', (string) $ctype)[0]));
    $seg = path_segments($path);
    $suf = strtolower((string) end($seg));
    $u   = strtolower((string) $ua);
    if ($ct === 'text/html') return 'page';
    if (in_array($suf, ['sing-box', 'singbox', 'sing_box'], true)) return 'singbox';
    if (in_array($suf, ['clash', 'clash-meta', 'mihomo', 'yaml', 'yml'], true)) return 'clash';
    if (in_array($suf, ['wireguard', 'wg', 'awg', 'amneziawg'], true)) return 'wg';
    if (in_array($suf, ['json', 'v2ray-json', 'xray'], true)) return 'json';
    if ($ct === 'application/yaml' || $ct === 'text/yaml' || $ct === 'application/x-yaml') return 'clash';
    if ($ct === 'application/json') {
        if (strpos($u, 'sing-box') !== false || strpos($u, 'sfa') !== false || strpos($u, 'sfi') !== false) return 'singbox';
        return 'json';
    }
    if (strpos($u, 'mihomo') !== false || strpos($u, 'clash') !== false || strpos($u, 'flclash') !== false || strpos($u, 'verge') !== false) return 'clash';
    if (strpos($u, 'sing-box') !== false) return 'singbox';
    if ($ct === 'text/plain' || $ct === '') return 'base64';
    return 'other';
}

function reqlog_fmt_label($fmt, $short = false) {
    $map = [
        'base64'  => 'base64',
        'json'    => 'json',
        'clash'   => 'clash (yaml)',
        'singbox' => 'sing-box',
        'wg'      => 'wireguard',
        'page'    => $short ? 'страница' : 'страница подписки',
        'other'   => 'другой',
    ];
    return $map[(string) $fmt] ?? '';
}

// Модель и ОС клиент шлёт отдельными заголовками (панель показывает их в списке
// устройств), а в User-Agent их кладут далеко не все — поэтому берём из заголовков,
// а разбор UA оставляем запасным вариантом.
function reqlog_device($ua_vals = []) {
    $pick = function ($key, $limit) use ($ua_vals) {
        $sk = 'HTTP_' . strtoupper(str_replace('-', '_', $key));
        $v = trim((string) ($_SERVER[$sk] ?? ''));
        if ($v === '') $v = trim((string) ($ua_vals[$key] ?? ''));
        $v = trim(preg_replace('/[\x00-\x1F\x7F]/', '', $v));
        return $v === '' ? '' : mb_substr($v, 0, $limit);
    };
    $out = [];
    $m = $pick('x-device-model', 40);
    $o = $pick('x-device-os', 24);
    $v = $pick('x-ver-os', 16);
    if ($m !== '') $out['m'] = $m;
    if ($o !== '') $out['o'] = $o;
    if ($v !== '') $out['v'] = $v;
    return $out;
}

function reqlog_device_label($dv) {
    if (!is_array($dv)) return '';
    $os = trim((string) ($dv['o'] ?? ''));
    $ver = trim((string) ($dv['v'] ?? ''));
    if ($os !== '' && $ver !== '' && stripos($os, $ver) === false) $os .= ' ' . $ver;
    elseif ($os === '' && $ver !== '') $os = $ver;
    $parts = array_values(array_filter([trim((string) ($dv['m'] ?? '')), $os], fn($x) => $x !== ''));
    return implode(' · ', $parts);
}

function reqlog_os_norm($s) {
    $s = strtolower(trim((string) $s));
    if ($s === '') return '';
    if (preg_match('~^(ios|iphone|ipad|ipados|tvos|visionos)~', $s)) return 'ios';
    if (strpos($s, 'android') !== false) return 'android';
    if (preg_match('~(windows|win32|win64|macos|mac os|darwin|linux|desktop)~', $s) || $s === 'pc' || $s === 'win' || $s === 'mac') return 'desktop';
    return '';
}

function reqlog_client($ua) {
    $ua = trim((string) $ua);
    $out = ['app' => '', 'dev' => '', 'key' => '', 'ver' => '', 'os' => ''];
    if ($ua === '') return $out;
    if (is_browser_ua($ua)) {
        $names = ['Edg' => 'Edge', 'OPR' => 'Opera', 'YaBrowser' => 'Яндекс.Браузер', 'Firefox' => 'Firefox', 'Chrome' => 'Chrome', 'Safari' => 'Safari'];
        foreach ($names as $needle => $label) {
            if (preg_match('~' . preg_quote($needle, '~') . '/(\d+)~i', $ua, $m)) { $out['app'] = $label . ' ' . $m[1]; break; }
        }
        if ($out['app'] === '') $out['app'] = 'Браузер';
        $os = '';
        if (preg_match('~Windows NT 10~i', $ua)) $os = 'Windows 10/11';
        elseif (preg_match('~Mac OS X~i', $ua)) $os = 'macOS';
        elseif (preg_match('~Android~i', $ua)) $os = 'Android';
        elseif (preg_match('~iPhone|iPad|CPU OS~i', $ua)) $os = 'iOS';
        elseif (preg_match('~Linux~i', $ua)) $os = 'Linux';
        $out['dev'] = trim($os . ($os !== '' ? ' · ' : '') . 'браузер');
        return $out;
    }
    $pretty = ['happ' => 'Happ', 'incy' => 'INCY', 'v2rayng' => 'v2rayNG', 'v2rayn' => 'v2rayN', 'sing-box' => 'sing-box',
               'mihomo' => 'Mihomo', 'clash.meta' => 'mihomo', 'clash-verge' => 'Clash Verge', 'clash-nyanpasu' => 'Clash Nyanpasu',
               'flclash' => 'FlClash', 'flclashx' => 'FlClashX', 'hiddify' => 'Hiddify', 'hiddifynext' => 'Hiddify',
               'hiddifynextx' => 'Hiddify (Xray)', 'streisand' => 'Streisand', 'shadowrocket' => 'Shadowrocket',
               'stash' => 'Stash', 'nekobox' => 'NekoBox', 'nekoray' => 'NekoRay', 'karing' => 'Karing', 'throne' => 'Throne',
               'exclave' => 'Exclave', 'v2box' => 'V2Box', 'foxray' => 'FoXray', 'loon' => 'Loon', 'surge' => 'Surge',
               'quantumult' => 'Quantumult', 'husi' => 'Husi', 'matsuri' => 'Matsuri', 'outline' => 'Outline',
               'wireguard' => 'WireGuard', 'sfa' => 'sing-box (Android)', 'sfi' => 'sing-box (iOS)', 'sfm' => 'sing-box (macOS)',
               'sft' => 'sing-box (tvOS)', 'koala-clash' => 'Koala Clash', 'clodclash' => 'Clod Clash',
               'clashmetaforandroid' => 'Clash Meta for Android', 'clashx' => 'ClashX Meta', 'v2raya' => 'v2rayA',
               'clash-meta/rabbithole' => 'RabbitHole'];
    $name = ''; $key = ''; $ver = ''; $os = '';
    if (preg_match('~^FlClash ?X/v?([0-9][0-9A-Za-z._-]*)~i', $ua, $m)) {
        $name = 'FlClashX'; $key = 'flclashx'; $ver = $m[1];
    } elseif (preg_match('~^([A-Za-z0-9._-]+)/([A-Za-z][A-Za-z0-9]{1,14})/v?([0-9][0-9A-Za-z._-]*)~', $ua, $m)) {
        $name = $m[1]; $key = strtolower($m[1]); $ver = $m[3]; $os = reqlog_os_norm($m[2]);
    } elseif (preg_match('~^([A-Za-z0-9._-]+)/v?([0-9][0-9A-Za-z._-]*)/([A-Za-z][A-Za-z0-9]{1,14})~', $ua, $m)) {
        $name = $m[1]; $key = strtolower($m[1]); $ver = $m[2]; $os = reqlog_os_norm($m[3]);
    } elseif (preg_match('~^([A-Za-z0-9._-]+)/([A-Za-z][A-Za-z0-9._-]{1,29})\s*\(~', $ua, $m)) {
        $name = $m[2]; $key = strtolower($m[1]) . '/' . strtolower($m[2]);
    } elseif (preg_match('~^([A-Za-z0-9._-]+)/v?([0-9][0-9A-Za-z._-]*)~', $ua, $m)) {
        $name = $m[1]; $key = strtolower($m[1]); $ver = $m[2];
    } elseif (preg_match('~^([A-Za-z0-9._-]+)~', $ua, $m)) {
        $name = $m[1]; $key = strtolower($m[1]);
    }
    if ($os === '' && preg_match('~\bplatform/([A-Za-z]{2,15})~i', $ua, $m)) $os = reqlog_os_norm($m[1]);
    $parts = [];
    if (preg_match('~\(([^)]{2,160})\)~', $ua, $m)) {
        foreach (explode(';', $m[1]) as $p) {
            $p = trim($p);
            if ($p === '' || preg_match('~^\d+$~', $p)) continue;
            if (preg_match('~^(build|language|locale|scale|sing-box|mihomo|clash|prefer|com\.)~i', $p)) continue;
            if (preg_match('~^[a-z-]+_[A-Z]{2}$~', $p)) continue;
            $parts[] = mb_substr($p, 0, 40);
        }
    }
    if ($os === '' && $parts) $os = reqlog_os_norm($parts[0]);
    $out['key'] = $key;
    $out['ver'] = $ver;
    $out['os']  = $os;
    $out['dev'] = implode(' · ', array_slice($parts, 0, 2));
    if ($name !== '') $out['app'] = trim(($pretty[$key] ?? $name) . ($ver !== '' ? ' ' . $ver : ''));
    return $out;
}

function reqlog_meta($row) {
    $m = json_decode((string) ($row['meta'] ?? ''), true);
    return is_array($m) ? $m : [];
}

function reqlog_is_real($grabbed_headers, $decision, $short_ov) {
    if (is_array($grabbed_headers) && isset($grabbed_headers['subscription-userinfo'])) return true;
    if (in_array($decision, ['blocked', 'expired', 'grace'], true)) return true;
    if (!empty($short_ov)) return true;
    return false;
}

function reqlog_today_stats() {
    $out = ['today_users' => 0, 'today_devices' => 0, 'total_devices' => 0, 'label' => date('d.m.Y')];
    if (!($p = db())) return $out;
    ensure_reqlog_hwid();
    $tzoff    = isset($_COOKIE['tzoff']) ? max(-720, min(840, (int) $_COOKIE['tzoff'])) * 60 : (int) date('Z');
    $nowLocal = time() + $tzoff;
    $dayStart = $nowLocal - ($nowLocal % 86400) - $tzoff;
    $out['label'] = gmdate('d.m.Y', $nowLocal);
    try {
        $st = $p->prepare("SELECT COUNT(DISTINCT short_uuid) FROM request_log WHERE short_uuid IS NOT NULL AND is_app = 1 AND decision <> 'browser' AND " . sql_epoch('ts') . " >= ?");
        $st->execute([$dayStart]); $out['today_users'] = (int) $st->fetchColumn();
        $st = $p->prepare("SELECT COUNT(DISTINCT hwid) FROM request_log WHERE hwid IS NOT NULL AND hwid <> '' AND " . sql_epoch('ts') . " >= ?");
        $st->execute([$dayStart]); $out['today_devices'] = (int) $st->fetchColumn();
        $out['total_devices'] = (int) $p->query("SELECT COUNT(DISTINCT hwid) FROM request_log WHERE hwid IS NOT NULL AND hwid <> ''")->fetchColumn();
    } catch (Throwable $e) {}
    return $out;
}

function reqlog_day_start() {
    $tzoff    = isset($_COOKIE['tzoff']) ? max(-720, min(840, (int) $_COOKIE['tzoff'])) * 60 : (int) date('Z');
    $nowLocal = time() + $tzoff;
    return $nowLocal - ($nowLocal % 86400) - $tzoff;
}

function reqlog_filters($src = null) {
    $src = is_array($src) ? $src : $_GET;
    $dec = (string) ($src['rl_dec'] ?? '');
    if (!in_array($dec, ['normal', 'blocked', 'grace', 'expired', 'error'], true)) $dec = '';
    $fmt = (string) ($src['rl_fmt'] ?? '');
    if (!in_array($fmt, ['base64', 'json', 'clash', 'singbox', 'wg', 'page', 'other'], true)) $fmt = '';
    $hours = (int) ($src['rl_hours'] ?? 24);
    if (!in_array($hours, [0, 1, 24, 168], true)) $hours = 24;
    return ['dec' => $dec, 'fmt' => $fmt, 'hours' => $hours, 'q' => trim((string) ($src['rl_q'] ?? ''))];
}

function reqlog_where(array $f, array $name2short = []) {
    $conds = ["decision <> 'browser'"];
    $args  = [];
    if ($f['dec'] !== '')  { $conds[] = 'decision = ?'; $args[] = $f['dec']; }
    if ($f['fmt'] !== '')  { $conds[] = 'fmt = ?';      $args[] = $f['fmt']; }
    if ($f['hours'] > 0)   { $conds[] = sql_epoch('ts') . ' >= ?'; $args[] = time() - $f['hours'] * 3600; }
    if ($f['q'] !== '') {
        $like = '%' . strtr($f['q'], ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
        $or   = ["short_uuid LIKE ? ESCAPE '!'", "ip LIKE ? ESCAPE '!'", "hwid LIKE ? ESCAPE '!'"];
        $args[] = $like; $args[] = $like; $args[] = $like;
        $hits = [];
        $needle = mb_strtolower($f['q']);
        foreach ($name2short as $nm => $su) {
            if ($nm !== '' && mb_strpos(mb_strtolower($nm), $needle) !== false) $hits[] = $su;
        }
        $hits = array_slice(array_unique($hits), 0, 200);
        if ($hits) {
            $or[] = 'short_uuid IN (' . implode(',', array_fill(0, count($hits), '?')) . ')';
            foreach ($hits as $h) $args[] = $h;
        }
        $conds[] = '(' . implode(' OR ', $or) . ')';
    }
    return [implode(' AND ', $conds), $args];
}

function reqlog_fetch(array $f, array $name2short = [], $limit = 300) {
    if (!($p = db())) return [];
    ensure_reqlog_hwid();
    [$where, $args] = reqlog_where($f, $name2short);
    $rows = [];
    try {
        $st = $p->prepare('SELECT *, ' . sql_epoch('ts') . ' AS ts_epoch FROM request_log WHERE ' . $where . ' ORDER BY id DESC LIMIT ' . (int) $limit);
        $st->execute($args);
        foreach ($st as $r) $rows[] = $r;
    } catch (Throwable $e) { error_log('submw reqlog_fetch: ' . $e->getMessage()); }
    return reqlog_collapse($rows);
}

function reqlog_overview() {
    $out = ['total' => 0, 'blocked' => 0, 'blocked_users' => 0, 'hourly' => array_fill(0, 24, 0), 'peak' => 0, 'peak_h' => 0];
    if (!($p = db())) return $out;
    ensure_reqlog_hwid();
    $from = time() - 86400;
    $ep   = sql_epoch('ts');
    try {
        $st = $p->prepare("SELECT COUNT(*) FROM request_log WHERE decision <> 'browser' AND $ep >= ?");
        $st->execute([$from]); $out['total'] = (int) $st->fetchColumn();
        $st = $p->prepare("SELECT COUNT(*) AS c, COUNT(DISTINCT short_uuid) AS u FROM request_log WHERE decision = 'blocked' AND $ep >= ?");
        $st->execute([$from]); $r = $st->fetch();
        $out['blocked'] = (int) ($r['c'] ?? 0); $out['blocked_users'] = (int) ($r['u'] ?? 0);
        $st = $p->prepare("SELECT ($ep / 3600) AS h, COUNT(*) AS c FROM request_log WHERE decision <> 'browser' AND $ep >= ? GROUP BY h");
        $st->execute([$from]);
        $base = intdiv(time(), 3600) - 23;
        foreach ($st as $r) {
            $i = (int) $r['h'] - $base;
            if ($i >= 0 && $i < 24) $out['hourly'][$i] = (int) $r['c'];
        }
        foreach ($out['hourly'] as $i => $c) if ($c > $out['peak']) { $out['peak'] = $c; $out['peak_h'] = ($base + $i) * 3600; }
    } catch (Throwable $e) { error_log('submw reqlog_overview: ' . $e->getMessage()); }
    return $out;
}

function reqlog_user_index() {
    $out = [];
    if (!($p = db())) return $out;
    ensure_reqlog_hwid();
    $ep = sql_epoch('ts');
    try {
        $st = $p->prepare("SELECT short_uuid, COUNT(*) AS c, COUNT(DISTINCT hwid) AS d FROM request_log
                           WHERE short_uuid IS NOT NULL AND short_uuid <> '' AND decision <> 'browser' AND $ep >= ? GROUP BY short_uuid");
        $st->execute([time() - 86400]);
        foreach ($st as $r) $out[(string) $r['short_uuid']] = ['day' => (int) $r['c'], 'dev' => (int) $r['d'], 'first' => 0];
        foreach ($p->query("SELECT short_uuid, MIN($ep) AS f FROM request_log WHERE short_uuid IS NOT NULL AND short_uuid <> '' GROUP BY short_uuid") as $r) {
            $su = (string) $r['short_uuid'];
            if (isset($out[$su])) $out[$su]['first'] = (int) $r['f'];
        }
    } catch (Throwable $e) { error_log('submw reqlog_user_index: ' . $e->getMessage()); }
    return $out;
}

function reqlog_history(array $rows, $limit = 8) {
    $out = [];
    foreach ($rows as $r) {
        $su = (string) ($r['short_uuid'] ?? '');
        if ($su === '') continue;
        if (!isset($out[$su])) $out[$su] = [];
        if (count($out[$su]) < $limit) $out[$su][] = (string) ($r['decision'] ?? '');
    }
    return $out;
}

function reqlog_collapse(array $rows) {
    $out = [];
    foreach ($rows as $r) {
        $n = count($out);
        $su = (string) ($r['short_uuid'] ?? '');
        if ($n > 0 && $su !== '') {
            $p = $out[$n - 1];
            $same_ts = ((int) ($r['ts_epoch'] ?? 0) > 0)
                ? ((int) $r['ts_epoch'] === (int) ($p['ts_epoch'] ?? 0))
                : ((string) ($r['ts'] ?? '') === (string) ($p['ts'] ?? ''));
            if ($su === (string) ($p['short_uuid'] ?? '')
                && $same_ts
                && (string) ($r['decision'] ?? '') === (string) ($p['decision'] ?? '')) {
                $out[$n - 1]['dup'] = (int) ($p['dup'] ?? 1) + 1;
                continue;
            }
        }
        $r['dup'] = max(1, (int) ($r['dup'] ?? 1));
        $out[] = $r;
    }
    return $out;
}

function reqlog_plural($n) {
    $n = abs((int) $n) % 100; $n1 = $n % 10;
    if ($n > 10 && $n < 20) return 'обновлений';
    if ($n1 === 1) return 'обновление';
    if ($n1 > 1 && $n1 < 5) return 'обновления';
    return 'обновлений';
}

// --- Снимок состояния пользователя и «до → после» ---
//
// Панель кладёт в payload user-вебхука полное состояние пользователя, поэтому
// снимок стоит ноль обращений к API. «До» берём из предыдущей записи журнала по
// тому же shortUuid: так у события видно не только что оно было, но и что именно
// поменялось. Расход трафика в дифф не входит — он растёт сам по себе и забивал
// бы список; в снимке он есть.

function whlog_ensure_meta() {
    static $done = false;
    if ($done) return;
    $done = true;
    if (!($p = db())) return;
    if (setting('whlog_meta_col', '') === '1') return;
    try { $p->exec('ALTER TABLE webhook_log ADD COLUMN meta ' . (db_driver() === 'mysql' ? 'MEDIUMTEXT' : 'TEXT') . ' NULL'); } catch (Throwable $e) {}
    try {
        $p->exec(db_driver() === 'mysql'
            ? 'ALTER TABLE webhook_log ADD INDEX idx_wh_short (short_uuid)'
            : 'CREATE INDEX IF NOT EXISTS idx_wh_short ON webhook_log(short_uuid)');
    } catch (Throwable $e) {}
    set_setting('whlog_meta_col', '1');
}

function whlog_snap_fields() { return ['sq', 'tl', 'ts', 'exp', 'hw', 'ex', 'tag', 'rst', 'rev']; }

function whlog_field_label($k) {
    $m = [
        'sq'   => 'Внутренние сквады',
        'tl'   => 'Лимит трафика',
        'ts'   => 'Стратегия сброса',
        'exp'  => 'Истекает',
        'hw'   => 'Лимит устройств',
        'ex'   => 'Внешний сквад',
        'tag'  => 'Тег',
        'rst'  => 'Сброс трафика',
        'rev'  => 'Подписка отозвана',
        'used' => 'Израсходовано',
        'upd'  => 'Изменён в панели',
    ];
    return $m[$k] ?? $k;
}

function whlog_snapshot($data) {
    if (!is_array($data)) return null;
    $sq = [];
    foreach (($data['activeInternalSquads'] ?? []) as $s) {
        if (count($sq) >= 20) break;
        if (is_array($s)) $sq[] = ['u' => (string) ($s['uuid'] ?? ''), 'n' => (string) ($s['name'] ?? '')];
        elseif (is_string($s) && $s !== '') $sq[] = ['u' => $s, 'n' => ''];
    }
    $tr  = is_array($data['userTraffic'] ?? null) ? $data['userTraffic'] : [];
    $str = function ($v) { return ($v === null || $v === '') ? null : (string) $v; };
    $int = function ($v) { return $v === null ? null : (int) $v; };
    $snap = [
        'sq'   => $sq,
        'tl'   => array_key_exists('trafficLimitBytes', $data) ? $int($data['trafficLimitBytes']) : null,
        'ts'   => $str($data['trafficLimitStrategy'] ?? null),
        'exp'  => $str($data['expireAt'] ?? null),
        'hw'   => array_key_exists('hwidDeviceLimit', $data) ? $int($data['hwidDeviceLimit']) : null,
        'ex'   => $str($data['externalSquadUuid'] ?? null),
        'tag'  => $str($data['tag'] ?? null),
        'rst'  => $str($data['lastTrafficResetAt'] ?? null),
        'rev'  => $str($data['subRevokedAt'] ?? null),
        'used' => isset($tr['usedTrafficBytes']) ? (int) $tr['usedTrafficBytes'] : null,
        'upd'  => $str($data['updatedAt'] ?? null),
    ];
    foreach ($snap as $k => $v) { if ($v === null || $v === []) unset($snap[$k]); }
    return $snap ?: null;
}

function whlog_squad_ids($sq) {
    $out = [];
    foreach ((array) $sq as $s) { if (is_array($s) && (string) ($s['u'] ?? '') !== '') $out[] = (string) $s['u']; }
    sort($out);
    return $out;
}

function whlog_squad_names($sq) {
    $out = [];
    foreach ((array) $sq as $s) {
        if (!is_array($s)) continue;
        $out[] = (string) ($s['n'] ?? '') !== '' ? (string) $s['n'] : (string) ($s['u'] ?? '');
    }
    sort($out);
    return $out;
}

function whlog_diff($before, $after) {
    if (!is_array($before) || !is_array($after)) return [];
    $out = [];
    foreach (whlog_snap_fields() as $k) {
        $b = $before[$k] ?? null;
        $a = $after[$k] ?? null;
        if ($k === 'sq') {
            if (whlog_squad_ids($b) === whlog_squad_ids($a)) continue;
            $out['sq'] = [whlog_squad_names($b), whlog_squad_names($a)];
            continue;
        }
        if ($b === $a) continue;
        $out[$k] = [$b, $a];
    }
    return $out;
}

function whlog_prev_snapshot($short) {
    if ((string) $short === '' || !($p = db())) return null;
    try {
        $st = $p->prepare("SELECT meta FROM webhook_log WHERE short_uuid = ? AND meta IS NOT NULL AND meta <> '' ORDER BY id DESC LIMIT 1");
        $st->execute([(string) $short]);
        $v = $st->fetchColumn();
        if (!is_string($v) || $v === '') return null;
        $j = json_decode($v, true);
        return isset($j['s']) && is_array($j['s']) ? $j['s'] : null;
    } catch (Throwable $e) { return null; }
}

// --- Журнал наших записей в панель ---
//
// Прослойка пишет в панель тремя вызовами (PATCH пользователя, сброс трафика,
// удаление устройства). Журнал нужен, чтобы у изменения был автор: событие от
// панели само по себе не говорит, чьё оно, а по этому журналу видно, что PATCH
// с ровно такими полями только что ушёл от нас.

function panel_write_field_map() {
    return [
        'activeInternalSquads' => 'sq',
        'trafficLimitBytes'    => 'tl',
        'trafficLimitStrategy' => 'ts',
        'expireAt'             => 'exp',
        'hwidDeviceLimit'      => 'hw',
        'externalSquadUuid'    => 'ex',
        'tag'                  => 'tag',
    ];
}

function log_panel_write($short, $ref, $op, $src, $body, $ok, $http_code = 0, $error = '') {
    ensure_panel_write_log();
    if (!($p = db())) return;
    $body = is_array($body) ? $body : [];
    $map  = panel_write_field_map();
    $keys = [];
    foreach (array_keys($body) as $k) { if (isset($map[$k])) $keys[] = $map[$k]; }
    if ($op === 'reset_traffic') $keys[] = 'rst';
    try {
        $st = $p->prepare('INSERT INTO panel_write_log (short_uuid, ref_key, ref_val, op, src, fields, body, ok, http_code, error) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $st->execute([
            (string) $short !== '' ? mb_substr((string) $short, 0, 191) : null,
            is_array($ref) ? mb_substr((string) ($ref['key'] ?? ''), 0, 16) : null,
            is_array($ref) ? mb_substr((string) ($ref['val'] ?? ''), 0, 191) : null,
            mb_substr((string) $op, 0, 32),
            (string) $src !== '' ? mb_substr((string) $src, 0, 32) : null,
            $keys ? mb_substr(implode(',', $keys), 0, 255) : null,
            $body ? mb_substr((string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 1000) : null,
            $ok ? 1 : 0,
            $http_code ? (int) $http_code : null,
            (string) $error !== '' ? mb_substr((string) $error, 0, 255) : null,
        ]);
        if (random_int(1, 100) === 1) {
            $p->exec("DELETE FROM panel_write_log WHERE id < (
                SELECT id FROM (SELECT id FROM panel_write_log ORDER BY id DESC LIMIT 1 OFFSET 20000) t
            )");
        }
    } catch (Throwable $e) { error_log('submw log_panel_write: ' . $e->getMessage()); }
}

// Наш ли это PATCH: ищем свою удачную запись по тому же shortUuid за последние
// $sec секунд и сверяем набор полей. Совпадение по времени без совпадения по
// полям — это уже чужое изменение, приехавшее следом за нашим.
function panel_write_recent($short, $sec = 300) {
    ensure_panel_write_log();
    if ((string) $short === '' || !($p = db())) return null;
    try {
        $st = $p->prepare('SELECT src, fields, op FROM panel_write_log WHERE short_uuid = ? AND ok = 1 AND ' . sql_epoch('ts') . ' >= ? ORDER BY id DESC LIMIT 3');
        $st->execute([(string) $short, time() - (int) $sec]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        return $rows ?: null;
    } catch (Throwable $e) { return null; }
}

function whlog_attribute(array $diff, $short) {
    if (!$diff) return null;
    $rows = panel_write_recent($short);
    if (!$rows) return 0;
    $changed = array_keys($diff);
    foreach ($rows as $r) {
        $sent = array_filter(explode(',', (string) ($r['fields'] ?? '')));
        if (!array_diff($changed, $sent)) return ['src' => (string) ($r['src'] ?? ''), 'op' => (string) ($r['op'] ?? '')];
    }
    return 0;
}

// --- Обезличивание для выгрузки ---
//
// Журнал уезжает из админки файлом: в нём shortUuid — это фактически пароль от
// подписки, а имя пользователя и uuid сквадов — чужие данные. Заменяем их
// короткой солёной меткой: она стабильна внутри одной выгрузки, поэтому строки
// по-прежнему можно сопоставлять между собой, но восстановить исходник нельзя.
function whlog_mask_id($v, $prefix = 'id') {
    $v = (string) $v;
    if ($v === '') return '';
    static $salt = null;
    if ($salt === null) $salt = bin2hex(random_bytes(8));
    return $prefix . ':' . substr(hash('sha256', $salt . $v), 0, 8);
}

function whlog_mask_diff($diff) {
    if (!is_array($diff)) return $diff;
    if (isset($diff['ex']) && is_array($diff['ex'])) {
        foreach ($diff['ex'] as $i => $v) $diff['ex'][$i] = ($v === null || $v === '') ? $v : whlog_mask_id($v, 'ex');
    }
    return $diff;
}

function whlog_is_date($k) { return $k === 'exp' || $k === 'rst' || $k === 'rev'; }

function whlog_epoch($v) {
    if ($v === null || $v === '') return 0;
    $t = strtotime((string) $v);
    return $t === false ? 0 : (int) $t;
}

function whlog_fmt_value($k, $v) {
    if ($v === null || $v === '') return '—';
    if (is_array($v)) return $v ? implode(', ', array_map('strval', $v)) : '—';
    if ($k === 'tl' || $k === 'used') {
        $n = (float) $v;
        if ($n <= 0) return $k === 'tl' ? 'без лимита' : '0';
        $u = ['Б', 'КиБ', 'МиБ', 'ГиБ', 'ТиБ'];
        $i = 0;
        while ($n >= 1024 && $i < count($u) - 1) { $n /= 1024; $i++; }
        return ($i === 0 ? (string) (int) $n : number_format($n, 2, '.', ' ')) . ' ' . $u[$i];
    }
    return (string) $v;
}

function whlog_meta($row) {
    $m = json_decode((string) ($row['meta'] ?? ''), true);
    if (!is_array($m)) return null;
    return [
        's'  => is_array($m['s'] ?? null) ? $m['s'] : null,
        'd'  => is_array($m['d'] ?? null) ? $m['d'] : [],
        'mw' => array_key_exists('mw', $m) ? (int) $m['mw'] : null,
        'src' => (string) ($m['src'] ?? ''),
    ];
}

function whlog_mask_snapshot($snap) {
    if (!is_array($snap)) return $snap;
    if (isset($snap['sq']) && is_array($snap['sq'])) {
        foreach ($snap['sq'] as $i => $s) {
            if (is_array($s) && (string) ($s['u'] ?? '') !== '') $snap['sq'][$i]['u'] = whlog_mask_id($s['u'], 'sq');
        }
    }
    if (!empty($snap['ex'])) $snap['ex'] = whlog_mask_id($snap['ex'], 'ex');
    return $snap;
}

function log_webhook($event, $short_uuid, $username, $status, $sig_ok, $action, $data = null) {
    if (!($p = db())) return;
    whlog_ensure_meta();
    $meta = null;
    try {
        $snap = whlog_snapshot($data);
        if ($snap !== null) {
            $m = ['s' => $snap];
            $diff = whlog_diff(whlog_prev_snapshot($short_uuid), $snap);
            if ($diff) {
                $m['d'] = $diff;
                $who = whlog_attribute($diff, $short_uuid);
                if (is_array($who)) { $m['mw'] = 1; if ($who['src'] !== '') $m['src'] = $who['src']; }
                elseif ($who === 0) $m['mw'] = 0;
            }
            $enc = json_encode($m, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (is_string($enc) && strlen($enc) <= 8000) $meta = $enc;
        }
    } catch (Throwable $e) { error_log('submw whlog snapshot: ' . $e->getMessage()); }
    try {
        $stmt = $p->prepare(
            'INSERT INTO webhook_log (event, short_uuid, username, status, sig_ok, action, meta)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$event, $short_uuid, $username, $status, $sig_ok ? 1 : 0, $action, $meta]);
        if (random_int(1, 100) === 1) {
            $p->exec("DELETE FROM webhook_log WHERE id < (
                SELECT id FROM (SELECT id FROM webhook_log ORDER BY id DESC LIMIT 1 OFFSET 20000) t
            )");
        }
    } catch (Throwable $e) {
        error_log('submw log_webhook: ' . $e->getMessage());
    }
}

function log_forward($event, $target, $http_code, $ok, $error = '') {
    ensure_forward_log();
    if (!($p = db())) return;
    try {
        $stmt = $p->prepare(
            'INSERT INTO forward_log (event, target, http_code, ok, error) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $event !== null ? mb_substr((string) $event, 0, 64) : null,
            mb_substr((string) $target, 0, 255),
            $http_code !== null ? (int) $http_code : null,
            $ok ? 1 : 0,
            $error !== '' ? mb_substr((string) $error, 0, 255) : null,
        ]);
        if (random_int(1, 100) === 1) {
            $p->exec("DELETE FROM forward_log WHERE id < (
                SELECT id FROM (SELECT id FROM forward_log ORDER BY id DESC LIMIT 1 OFFSET 5000) t
            )");
        }
    } catch (Throwable $e) { error_log('submw log_forward: ' . $e->getMessage()); }
}

function parse_expire_from_userinfo($userinfo) {
    if (!$userinfo) return null;
    if (preg_match('/expire\s*=\s*(\d+)/i', $userinfo, $m)) {
        $v = (int) $m[1];
        return $v > 0 ? $v : null;
    }
    return null;
}

function path_segments($path) {
    $segs = array_filter(explode('/', trim((string) $path, '/')), fn($s) => $s !== '');
    return array_values($segs);
}

function client_ip() {
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR'] as $h) {
        if (!empty($_SERVER[$h])) {
            $ip = trim(explode(',', $_SERVER[$h])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? '';
}
