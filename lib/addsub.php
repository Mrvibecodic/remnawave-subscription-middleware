<?php

function addsub_enabled() { return setting('addsub_enabled', '0') === '1'; }

function addsub_suffix() {
    $s = trim((string) setting('addsub_username_suffix', '_addsub'));
    return $s !== '' ? $s : '_addsub';
}

function addsub_cache_ttl() { return max(30, (int) (setting('addsub_cache_ttl', '600') ?: 600)); }

function addsub_label() { return trim((string) setting('addsub_label', '')); }

function addsub_stub_on_traffic() { return setting('addsub_stub_on_traffic', '1') === '1'; }

function addsub_stub_label() {
    $s = trim((string) setting('addsub_stub_label', ''));
    return $s !== '' ? $s : 'Трафик доп-сервера истёк';
}

function addsub_xray_enabled() { return setting('addsub_merge_xray', '0') === '1'; }

function addsub_ensure() {
    static $done = false;
    if ($done) return;
    $done = true;
    if (!($p = db())) return;
    try {
        if (db_driver() === 'mysql') {
            $p->exec("CREATE TABLE IF NOT EXISTS addsub_map (
                main_short VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                add_url TEXT NOT NULL,
                note VARCHAR(191) NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (main_short)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $p->exec("CREATE TABLE IF NOT EXISTS addsub_cache (
                main_short VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                add_url TEXT NULL,
                ts INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (main_short)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } else {
            $p->exec("CREATE TABLE IF NOT EXISTS addsub_map (
                main_short TEXT NOT NULL PRIMARY KEY,
                add_url TEXT NOT NULL,
                note TEXT NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )");
            $p->exec("CREATE TABLE IF NOT EXISTS addsub_cache (
                main_short TEXT NOT NULL PRIMARY KEY,
                add_url TEXT NULL,
                ts INTEGER NOT NULL DEFAULT 0
            )");
        }
    } catch (Throwable $e) { error_log('submw addsub ensure: ' . $e->getMessage()); }
}

function addsub_map_get($short) {
    $short = trim((string) $short);
    if ($short === '' || !($p = db())) return '';
    addsub_ensure();
    try {
        $st = $p->prepare('SELECT add_url FROM addsub_map WHERE main_short = ?');
        $st->execute([$short]);
        $v = $st->fetchColumn();
        return $v !== false ? (string) $v : '';
    } catch (Throwable $e) { return ''; }
}

function addsub_map_set($short, $url, $note = '') {
    $short = trim((string) $short); $url = trim((string) $url);
    if ($short === '' || $url === '' || !($p = db())) return false;
    addsub_ensure();
    try {
        if (db_driver() === 'mysql') {
            $st = $p->prepare('INSERT INTO addsub_map (main_short, add_url, note) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE add_url = VALUES(add_url), note = VALUES(note)');
        } else {
            $st = $p->prepare('INSERT INTO addsub_map (main_short, add_url, note) VALUES (?, ?, ?) ON CONFLICT(main_short) DO UPDATE SET add_url = excluded.add_url, note = excluded.note');
        }
        $ok = $st->execute([$short, $url, ($note !== '' ? mb_substr((string) $note, 0, 191) : null)]);
        addsub_cache_drop($short);
        return $ok;
    } catch (Throwable $e) { error_log('submw addsub map_set: ' . $e->getMessage()); return false; }
}

function addsub_map_del($short) {
    $short = trim((string) $short);
    if ($short === '' || !($p = db())) return false;
    addsub_ensure();
    try { $p->prepare('DELETE FROM addsub_map WHERE main_short = ?')->execute([$short]); addsub_cache_drop($short); return true; }
    catch (Throwable $e) { return false; }
}

function addsub_map_all() {
    if (!($p = db())) return [];
    addsub_ensure();
    try { $out = []; foreach ($p->query('SELECT * FROM addsub_map ORDER BY created_at DESC, main_short') as $r) $out[] = $r; return $out; }
    catch (Throwable $e) { return []; }
}

function addsub_cache_get($short) {
    $short = trim((string) $short);
    if ($short === '' || !($p = db())) return ['hit' => false, 'url' => null];
    addsub_ensure();
    try {
        $st = $p->prepare('SELECT add_url, ts FROM addsub_cache WHERE main_short = ?');
        $st->execute([$short]);
        $row = $st->fetch();
        if ($row && (time() - (int) $row['ts'] < addsub_cache_ttl())) {
            $u = $row['add_url'];
            return ['hit' => true, 'url' => ($u === null || $u === '') ? null : (string) $u];
        }
    } catch (Throwable $e) {}
    return ['hit' => false, 'url' => null];
}

function addsub_cache_put($short, $url) {
    $short = trim((string) $short);
    if ($short === '' || !($p = db())) return;
    addsub_ensure();
    try {
        if (db_driver() === 'mysql') {
            $st = $p->prepare('INSERT INTO addsub_cache (main_short, add_url, ts) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE add_url = VALUES(add_url), ts = VALUES(ts)');
        } else {
            $st = $p->prepare('INSERT INTO addsub_cache (main_short, add_url, ts) VALUES (?, ?, ?) ON CONFLICT(main_short) DO UPDATE SET add_url = excluded.add_url, ts = excluded.ts');
        }
        $st->execute([$short, ($url !== null && $url !== '' ? (string) $url : null), time()]);
    } catch (Throwable $e) {}
}

function addsub_cache_drop($short) {
    $short = trim((string) $short);
    if ($short === '' || !($p = db())) return;
    addsub_ensure();
    try { $p->prepare('DELETE FROM addsub_cache WHERE main_short = ?')->execute([$short]); }
    catch (Throwable $e) {}
}

function addsub_build_sub_url($short) {
    $short = (string) $short;
    if ($short === '') return '';
    $enc = rawurlencode($short);
    if (function_exists('subpage_active') && subpage_active()) {
        $base = remnawave_url();
        return $base === '' ? '' : $base . '/api/sub/' . $enc;
    }
    $dom = target_domain();
    return $dom === '' ? '' : 'https://' . $dom . '/' . $enc;
}

function addsub_resolve($short) {
    $short = trim((string) $short);
    if ($short === '') return null;

    $manual = addsub_map_get($short);
    if ($manual !== '') return ['url' => $manual, 'mode' => 'manual'];

    $c = addsub_cache_get($short);
    if ($c['hit']) return $c['url'] === null ? null : ['url' => $c['url'], 'mode' => 'auto'];

    if (remnawave_url() === '' || remnawave_token() === '') return null;

    $err = '';
    $userA = remnawave_get_user_by_short($short, $err);
    if (!is_array($userA)) return null;
    $uname = (string) ($userA['username'] ?? '');
    if ($uname === '') { addsub_cache_put($short, null); return null; }

    $suf = addsub_suffix();
    if ($suf !== '' && substr($uname, -strlen($suf)) === $suf) { addsub_cache_put($short, null); return null; }

    $userB = remnawave_get_user_by_username($uname . $suf, $err);
    if (!is_array($userB)) { addsub_cache_put($short, null); return null; }

    $status = strtoupper((string) ($userB['status'] ?? ''));
    if ($status === 'DISABLED' || $status === 'EXPIRED') { addsub_cache_put($short, null); return null; }

    $shortB = (string) ($userB['shortUuid'] ?? '');
    if ($shortB === '') { addsub_cache_put($short, null); return null; }

    $url = addsub_build_sub_url($shortB);
    if ($url === '') { addsub_cache_put($short, null); return null; }

    addsub_cache_put($short, $url);
    return ['url' => $url, 'mode' => 'auto'];
}

function addsub_parse_userinfo($v) {
    $out = ['up' => 0.0, 'down' => 0.0, 'total' => 0.0];
    foreach (explode(';', (string) $v) as $part) {
        $kv = explode('=', trim($part), 2);
        if (count($kv) !== 2) continue;
        $k = strtolower(trim($kv[0])); $n = (float) trim($kv[1]);
        if ($k === 'upload') $out['up'] = $n;
        elseif ($k === 'download') $out['down'] = $n;
        elseif ($k === 'total') $out['total'] = $n;
    }
    return $out;
}

function addsub_traffic_exhausted($info) {
    if (!is_array($info)) return false;
    $total = (float) ($info['total'] ?? 0);
    if ($total <= 0) return false;
    return ((float) ($info['up'] ?? 0) + (float) ($info['down'] ?? 0)) >= $total;
}

function addsub_fetch_body($url) {
    $url = trim((string) $url);
    if ($url === '') return [null, null];
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'submw';
    $headers = ['Accept: */*', 'User-Agent: ' . $ua];
    $dos = $_SERVER['HTTP_X_DEVICE_OS'] ?? '';
    if ($dos !== '') $headers[] = 'x-device-os: ' . $dos;
    if (strpos($url, 'http://') === 0) {
        $headers[] = 'x-forwarded-proto: https';
        $headers[] = 'x-forwarded-for: 127.0.0.1';
    }
    if (function_exists('client_ip')) $headers[] = 'x-remnawave-real-ip: ' . client_ip();

    $info = null;
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => proxy_timeout(),
        CURLOPT_SSL_VERIFYPEER => api_tls_verify(),
        CURLOPT_SSL_VERIFYHOST => api_tls_verify() ? 2 : 0,
        CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
        CURLOPT_ENCODING       => '',
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_HEADER         => false,
        CURLOPT_HEADERFUNCTION => function ($curl, $h) use (&$info) {
            $t = trim($h);
            $parts = explode(':', $t, 2);
            if (count($parts) === 2 && strtolower(trim($parts[0])) === 'subscription-userinfo') {
                $info = addsub_parse_userinfo(trim($parts[1]));
            }
            return strlen($h);
        },
    ]);
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($err || $code < 200 || $code >= 300) return [null, null];
    return [(string) $body, $info];
}

function addsub_label_uri($line, $label) {
    if ($label === '') return $line;
    $hash = strpos($line, '#');
    if ($hash === false) return $line . '#' . rawurlencode($label);
    $rem = rawurldecode(substr($line, $hash + 1));
    return substr($line, 0, $hash) . '#' . rawurlencode($label . ' ' . $rem);
}

function addsub_merge($a, $b, $format) {
    if (!is_string($a) || $a === '' || !is_string($b) || $b === '') return $a;
    try {
        if ($format === 'clash') return addsub_merge_clash($a, $b);
        $t = ltrim($b);
        if ($t === '' || ($t[0] !== '[' && $t[0] !== '{')) return addsub_merge_base64($a, $b);
        $ob = json_decode($b, true);
        if (squadconf_is_singbox($ob)) return addsub_merge_singbox($a, $b);
        if (addsub_xray_enabled()) return addsub_merge_xray($a, $b);
        return $a;
    } catch (Throwable $e) { error_log('submw addsub merge: ' . $e->getMessage()); return $a; }
}

function addsub_merge_base64($a, $b) {
    $da = base64_decode(trim($a), true);
    if ($da === false) return $a;
    $db = base64_decode(trim($b), true);
    if ($db === false || $db === '') return $a;
    $label = addsub_label();
    $existing = [];
    foreach (preg_split('/\r\n|\r|\n/', $da) as $ln) { $ln = trim($ln); if ($ln !== '') $existing[$ln] = true; }
    $add = [];
    foreach (preg_split('/\r\n|\r|\n/', $db) as $ln) {
        $ln = trim($ln);
        if ($ln === '' || strpos($ln, '://') === false) continue;
        $ln2 = addsub_label_uri($ln, $label);
        if (isset($existing[$ln]) || isset($existing[$ln2])) continue;
        $existing[$ln2] = true;
        $add[] = $ln2;
    }
    if (!$add) return $a;
    $sep = (strpos($da, "\r\n") !== false) ? "\r\n" : "\n";
    return base64_encode(rtrim($da, "\r\n") . $sep . implode($sep, $add));
}

function addsub_clash_item_name($lines) {
    foreach ($lines as $ln) {
        if (preg_match('/^\s*-\s*\{.*?\bname:\s*("?)([^",}]+)\1/', $ln, $m)) return trim($m[2]);
        if (preg_match('/^\s*-\s*name:\s*("?)(.*?)\1\s*$/', $ln, $m)) return trim($m[2]);
        if (preg_match('/^\s*name:\s*("?)(.*?)\1\s*$/', $ln, $m)) return trim($m[2]);
    }
    return '';
}

function addsub_clash_rename($lines, $newname) {
    $q = yaml_q($newname);
    foreach ($lines as $i => $ln) {
        if (preg_match('/^(\s*-\s*\{.*?\bname:\s*)("?)([^",}]+)(\2)(.*)$/', $ln, $m)) { $lines[$i] = $m[1] . $q . $m[5]; return $lines; }
        if (preg_match('/^(\s*-\s*name:\s*).*$/', $ln, $m)) { $lines[$i] = $m[1] . $q; return $lines; }
        if (preg_match('/^(\s*name:\s*).*$/', $ln, $m)) { $lines[$i] = $m[1] . $q; return $lines; }
    }
    return $lines;
}

function addsub_clash_names($yaml) {
    $out = [];
    foreach (preg_split('/\r\n|\r|\n/', (string) $yaml) as $ln) {
        if (preg_match('/^\s*-\s*\{.*?\bname:\s*("?)([^",}]+)\1/', $ln, $m)) { $out[trim($m[2])] = true; continue; }
        if (preg_match('/^\s*-\s*name:\s*("?)(.*?)\1\s*$/', $ln, $m)) { $out[trim($m[2])] = true; }
    }
    return $out;
}

function addsub_clash_extract($yaml) {
    $lines = preg_split('/\r\n|\r|\n/', (string) $yaml);
    $in = false; $items = []; $cur = null; $lit = null;
    foreach ($lines as $line) {
        if (!$in) {
            if (preg_match('/^proxies:\s*\[\s*\]\s*$/', $line)) return [[], []];
            if (preg_match('/^proxies:\s*$/', $line)) $in = true;
            continue;
        }
        if ($line !== '' && preg_match('/^\S/', $line)) break;
        if (preg_match('/^(\s*)-\s/', $line, $m)) {
            $ind = strlen($m[1]);
            if ($lit === null) $lit = $ind;
            if ($ind === $lit) {
                if ($cur !== null) $items[] = $cur;
                $cur = [$line];
                continue;
            }
        }
        if ($cur !== null) $cur[] = $line;
    }
    if ($cur !== null) $items[] = $cur;

    $blocks = []; $names = [];
    foreach ($items as $it) {
        $name = addsub_clash_item_name($it);
        if ($name === '') continue;
        $blocks[] = implode("\n", $it);
        $names[] = $name;
    }
    return [$blocks, $names];
}

function addsub_merge_clash($a, $b) {
    [$blocks, $names] = addsub_clash_extract($b);
    if (!$blocks) return $a;
    $label = addsub_label();
    $existing = addsub_clash_names($a);
    $fb = []; $fn = [];
    foreach ($blocks as $i => $blk) {
        $nm = $names[$i];
        if ($label !== '') {
            $nm2 = $label . ' ' . $nm;
            $blk = implode("\n", addsub_clash_rename(explode("\n", $blk), $nm2));
            $nm = $nm2;
        }
        if (isset($existing[$nm])) continue;
        $existing[$nm] = true;
        $fb[] = $blk; $fn[] = $nm;
    }
    if (!$fb) return $a;
    return clash_insert_proxies($a, $fb, $fn);
}

function addsub_singbox_is_node($o) {
    if (!is_array($o)) return false;
    $t = (string) ($o['type'] ?? '');
    if ($t === '' || !isset($o['tag'])) return false;
    return !in_array($t, ['selector', 'urltest', 'direct', 'block', 'dns'], true);
}

function addsub_merge_singbox($a, $b) {
    $oa = json_decode($a, true);
    $ob = json_decode($b, true);
    if (!squadconf_is_singbox($oa) || !is_array($ob) || !isset($ob['outbounds']) || !is_array($ob['outbounds'])) return $a;
    $label = addsub_label();
    $existing = [];
    foreach ($oa['outbounds'] as $o) if (is_array($o) && isset($o['tag'])) $existing[(string) $o['tag']] = true;
    $added = [];
    foreach ($ob['outbounds'] as $o) {
        if (!addsub_singbox_is_node($o)) continue;
        $tag = (string) $o['tag'];
        if ($label !== '') $tag = $label . ' ' . $tag;
        $base = $tag; $i = 1;
        while (isset($existing[$tag])) { $i++; $tag = $base . ' ' . $i; }
        $o['tag'] = $tag;
        $existing[$tag] = true;
        $oa['outbounds'][] = $o;
        $added[] = $tag;
    }
    if (!$added) return $a;
    foreach ($oa['outbounds'] as &$o) {
        if (is_array($o) && in_array(($o['type'] ?? ''), ['selector', 'urltest'], true) && isset($o['outbounds']) && is_array($o['outbounds'])) {
            foreach ($added as $tg) if (!in_array($tg, $o['outbounds'], true)) $o['outbounds'][] = $tg;
        }
    }
    unset($o);
    $enc = json_encode($oa, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return $enc === false ? $a : $enc;
}

function addsub_xray_is_node($o) {
    if (!is_array($o)) return false;
    $proto = (string) ($o['protocol'] ?? '');
    if ($proto === '') return false;
    return !in_array($proto, ['freedom', 'blackhole', 'dns'], true);
}

function addsub_xray_collect($ob) {
    $nodes = [];
    if (isset($ob['outbounds']) && is_array($ob['outbounds'])) {
        foreach ($ob['outbounds'] as $o) if (addsub_xray_is_node($o)) $nodes[] = $o;
        return $nodes;
    }
    $isList = ($ob !== [] && array_keys($ob) === range(0, count($ob) - 1));
    if ($isList) {
        foreach ($ob as $el) {
            if (is_array($el) && isset($el['outbounds']) && is_array($el['outbounds'])) {
                foreach ($el['outbounds'] as $o) if (addsub_xray_is_node($o)) $nodes[] = $o;
            }
        }
    }
    return $nodes;
}

function addsub_merge_xray($a, $b) {
    $oa = json_decode($a, true);
    $ob = json_decode($b, true);
    if (!is_array($oa) || !is_array($ob)) return $a;
    $nodes = addsub_xray_collect($ob);
    if (!$nodes) return $a;
    if (isset($oa['outbounds']) && is_array($oa['outbounds'])) {
        foreach ($nodes as $n) $oa['outbounds'][] = $n;
    } else {
        $isList = ($oa !== [] && array_keys($oa) === range(0, count($oa) - 1));
        if (!$isList) return $a;
        $done = false;
        foreach ($oa as $k => $el) {
            if (is_array($el) && isset($el['outbounds']) && is_array($el['outbounds'])) {
                foreach ($nodes as $n) $oa[$k]['outbounds'][] = $n;
                $done = true;
            }
        }
        if (!$done) return $a;
    }
    $enc = json_encode($oa, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return $enc === false ? $a : $enc;
}

function addsub_inject_stub($a, $format, $label) {
    if (!is_string($a) || $a === '' || $label === '') return $a;
    try {
        if ($format === 'clash') return addsub_stub_clash($a, $label);
        $t = ltrim($a);
        if ($t === '' || ($t[0] !== '[' && $t[0] !== '{')) return addsub_stub_base64($a, $label);
        $obj = json_decode($a, true);
        if (squadconf_is_singbox($obj)) return addsub_stub_singbox($a, $label);
        if (addsub_xray_enabled()) return addsub_stub_xray($a, $label);
        return $a;
    } catch (Throwable $e) { error_log('submw addsub stub: ' . $e->getMessage()); return $a; }
}

function addsub_stub_base64($a, $label) {
    $da = base64_decode(trim($a), true);
    if ($da === false) return $a;
    $line = 'vless://00000000-0000-0000-0000-000000000000@0.0.0.0:1?security=none&type=tcp&encryption=none&flow=#' . rawurlencode($label);
    $sep = (strpos($da, "\r\n") !== false) ? "\r\n" : "\n";
    return base64_encode(rtrim($da, "\r\n") . $sep . $line);
}

function addsub_stub_clash($a, $label) {
    $block = '  - {name: ' . yaml_q($label) . ', type: ss, server: 127.0.0.1, port: 1, cipher: aes-128-gcm, password: "1", udp: false}';
    return clash_insert_proxies($a, [$block], [$label]);
}

function addsub_stub_singbox($a, $label) {
    $oa = json_decode($a, true);
    if (!squadconf_is_singbox($oa)) return $a;
    $tag = $label; $existing = [];
    foreach ($oa['outbounds'] as $o) if (is_array($o) && isset($o['tag'])) $existing[(string) $o['tag']] = true;
    $base = $tag; $i = 1;
    while (isset($existing[$tag])) { $i++; $tag = $base . ' ' . $i; }
    $oa['outbounds'][] = [
        'type'        => 'shadowsocks',
        'tag'         => $tag,
        'server'      => '127.0.0.1',
        'server_port' => 1,
        'method'      => 'aes-128-gcm',
        'password'    => '1',
    ];
    foreach ($oa['outbounds'] as &$o) {
        if (is_array($o) && in_array(($o['type'] ?? ''), ['selector', 'urltest'], true) && isset($o['outbounds']) && is_array($o['outbounds'])) {
            if (!in_array($tag, $o['outbounds'], true)) $o['outbounds'][] = $tag;
        }
    }
    unset($o);
    $enc = json_encode($oa, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return $enc === false ? $a : $enc;
}

function addsub_stub_xray($a, $label) {
    $oa = json_decode($a, true);
    if (!is_array($oa)) return $a;
    $node = ['protocol' => 'blackhole', 'settings' => (object) [], 'tag' => $label];
    if (isset($oa['outbounds']) && is_array($oa['outbounds'])) {
        $oa['outbounds'][] = $node;
    } else {
        $isList = ($oa !== [] && array_keys($oa) === range(0, count($oa) - 1));
        if (!$isList) return $a;
        foreach ($oa as $k => $el) if (is_array($el) && isset($el['outbounds']) && is_array($el['outbounds'])) $oa[$k]['outbounds'][] = $node;
    }
    $enc = json_encode($oa, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return $enc === false ? $a : $enc;
}
