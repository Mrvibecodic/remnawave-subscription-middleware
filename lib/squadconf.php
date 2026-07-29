<?php

function squadconf_ensure() {
    static $done = false;
    if ($done) return;
    $done = true;
    if (!($p = db())) return;
    try {
        if (db_driver() === 'mysql') {
            $p->exec("CREATE TABLE IF NOT EXISTS squad_configs (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                squad_uuid VARCHAR(64) NOT NULL,
                type VARCHAR(32) NOT NULL DEFAULT 'amneziawg',
                name VARCHAR(191) NULL,
                enabled TINYINT(1) NOT NULL DEFAULT 1,
                raw MEDIUMTEXT NOT NULL,
                parsed MEDIUMTEXT NULL,
                grp VARCHAR(64) NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_squad (squad_uuid)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } else {
            $p->exec("CREATE TABLE IF NOT EXISTS squad_configs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                squad_uuid TEXT NOT NULL,
                type TEXT NOT NULL DEFAULT 'amneziawg',
                name TEXT NULL,
                enabled INTEGER NOT NULL DEFAULT 1,
                raw TEXT NOT NULL,
                parsed TEXT NULL,
                grp TEXT NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )");
            $p->exec("CREATE INDEX IF NOT EXISTS idx_squad_cfg ON squad_configs(squad_uuid)");
        }
        if (setting('sqcfg_squads_col', '') !== '1') {
            try { $p->exec('ALTER TABLE squad_configs ADD COLUMN squads ' . (db_driver() === 'mysql' ? 'MEDIUMTEXT' : 'TEXT') . ' NULL'); } catch (Throwable $e) {}
            set_setting('sqcfg_squads_col', '1');
        }
        if (setting('sqcfg_grp_col', '') !== '1') {
            try { $p->exec('ALTER TABLE squad_configs ADD COLUMN grp ' . (db_driver() === 'mysql' ? 'VARCHAR(64)' : 'TEXT') . ' NULL'); } catch (Throwable $e) {}
            set_setting('sqcfg_grp_col', '1');
        }
    } catch (Throwable $e) { error_log('submw squadconf ensure: ' . $e->getMessage()); }
}

function squadconf_squads_of($row) {
    $s = (string) ($row['squads'] ?? '');
    if ($s !== '') {
        $a = json_decode($s, true);
        if (is_array($a)) { $a = array_values(array_filter(array_map('strval', $a), fn($x) => $x !== '')); if ($a) return $a; }
    }
    $u = (string) ($row['squad_uuid'] ?? '');
    return $u !== '' ? [$u] : [];
}

function squadconf_by_ids(array $ids) {
    squadconf_ensure();
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($x) => $x > 0)));
    if (!$ids || !($p = db())) return [];
    try {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = $p->prepare("SELECT * FROM squad_configs WHERE id IN ($in)");
        $st->execute($ids);
        return $st->fetchAll();
    } catch (Throwable $e) { return []; }
}

function squadconf_all() {
    squadconf_ensure();
    if (!($p = db())) return [];
    try {
        $out = [];
        foreach ($p->query('SELECT * FROM squad_configs ORDER BY squad_uuid, id') as $r) $out[] = $r;
        return $out;
    } catch (Throwable $e) { return []; }
}

function squadconf_for_squads(array $squad_uuids) {
    squadconf_ensure();
    $user = array_flip(array_values(array_filter(array_map('strval', $squad_uuids), fn($s) => $s !== '')));
    if (!$user || !($p = db())) return [];
    $out = [];
    try {
        foreach ($p->query('SELECT * FROM squad_configs WHERE enabled = 1 ORDER BY id') as $r) {
            foreach (squadconf_squads_of($r) as $sq) {
                if (isset($user[$sq])) { $out[] = $r; break; }
            }
        }
    } catch (Throwable $e) { error_log('submw squadconf for_squads: ' . $e->getMessage()); }
    return $out;
}

function squadconf_add($squad_uuids, $type, $name, $raw, $parsed, $grp = '') {
    squadconf_ensure();
    $squad_uuids = array_values(array_filter(array_unique(array_map('strval', (array) $squad_uuids)), fn($s) => trim($s) !== ''));
    $raw = (string) $raw;
    if (!($p = db()) || !$squad_uuids || trim($raw) === '') return false;
    $grp = trim((string) $grp);
    try {
        $st = $p->prepare('INSERT INTO squad_configs (squad_uuid, squads, type, name, raw, parsed, grp) VALUES (?, ?, ?, ?, ?, ?, ?)');
        return $st->execute([
            $squad_uuids[0],
            json_encode(array_values($squad_uuids), JSON_UNESCAPED_SLASHES),
            mb_substr((string) $type, 0, 32),
            ($name !== '' ? mb_substr((string) $name, 0, 191) : null),
            $raw,
            ($parsed !== '' ? (string) $parsed : null),
            ($grp !== '' ? mb_substr($grp, 0, 64) : null),
        ]);
    } catch (Throwable $e) { error_log('submw squadconf add: ' . $e->getMessage()); return false; }
}

// $grp === null → группу не трогаем (для массовых правок параметров конфига).
function squadconf_set_group(array $ids, $grp) {
    squadconf_ensure();
    $ids = array_values(array_filter(array_map('intval', $ids), fn($i) => $i > 0));
    if (!($p = db()) || !$ids) return 0;
    $g = trim((string) $grp);
    $in = implode(',', array_fill(0, count($ids), '?'));
    try {
        $st = $p->prepare("UPDATE squad_configs SET grp = ? WHERE id IN ($in)");
        $st->execute(array_merge([$g !== '' ? mb_substr($g, 0, 64) : null], $ids));
        return $st->rowCount();
    } catch (Throwable $e) { error_log('submw squadconf set_group: ' . $e->getMessage()); return 0; }
}

function squadconf_delete($id) {
    squadconf_ensure();
    $id = (int) $id;
    if (!($p = db()) || $id <= 0) return false;
    try { return $p->prepare('DELETE FROM squad_configs WHERE id = ?')->execute([$id]); }
    catch (Throwable $e) { error_log('submw squadconf delete: ' . $e->getMessage()); return false; }
}

function squadconf_toggle($id, $enabled) {
    squadconf_ensure();
    $id = (int) $id;
    if (!($p = db()) || $id <= 0) return false;
    try { return $p->prepare('UPDATE squad_configs SET enabled = ? WHERE id = ?')->execute([$enabled ? 1 : 0, $id]); }
    catch (Throwable $e) { error_log('submw squadconf toggle: ' . $e->getMessage()); return false; }
}

function squadconf_update($id, $squad_uuids, $type, $name, $raw, $parsed, $grp = null) {
    squadconf_ensure();
    $id = (int) $id;
    $squad_uuids = array_values(array_filter(array_unique(array_map('strval', (array) $squad_uuids)), fn($s) => trim($s) !== ''));
    $raw = (string) $raw;
    if (!($p = db()) || $id <= 0 || !$squad_uuids || trim($raw) === '') return false;
    try {
        if ($grp === null) {
            $st = $p->prepare('UPDATE squad_configs SET squad_uuid = ?, squads = ?, type = ?, name = ?, raw = ?, parsed = ? WHERE id = ?');
            return $st->execute([
                $squad_uuids[0],
                json_encode(array_values($squad_uuids), JSON_UNESCAPED_SLASHES),
                mb_substr((string) $type, 0, 32),
                ($name !== '' ? mb_substr((string) $name, 0, 191) : null),
                $raw,
                ($parsed !== '' ? (string) $parsed : null),
                $id,
            ]);
        }
        $g = trim((string) $grp);
        $st = $p->prepare('UPDATE squad_configs SET squad_uuid = ?, squads = ?, type = ?, name = ?, raw = ?, parsed = ?, grp = ? WHERE id = ?');
        return $st->execute([
            $squad_uuids[0],
            json_encode(array_values($squad_uuids), JSON_UNESCAPED_SLASHES),
            mb_substr((string) $type, 0, 32),
            ($name !== '' ? mb_substr((string) $name, 0, 191) : null),
            $raw,
            ($parsed !== '' ? (string) $parsed : null),
            ($g !== '' ? mb_substr($g, 0, 64) : null),
            $id,
        ]);
    } catch (Throwable $e) { error_log('submw squadconf update: ' . $e->getMessage()); return false; }
}

function awg_split_list($v) {
    $out = [];
    foreach (explode(',', (string) $v) as $part) {
        $part = trim($part);
        if ($part !== '') $out[] = $part;
    }
    return $out;
}

function awg_parse_conf($raw) {
    $res = ['ok' => false, 'type' => 'unknown', 'version' => '', 'iface' => [], 'peer' => [], 'clients' => [], 'warnings' => []];
    $raw = (string) $raw;
    if (stripos(ltrim($raw), 'vpn://') === 0) {
        $res['warnings'][] = 'Это контейнер AmneziaVPN (vpn://), а не клиентский конфиг. Нужен .conf с секциями [Interface] и [Peer].';
        return $res;
    }
    $section = '';
    foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || $line[0] === ';') continue;
        if ($line[0] === '[') { $section = strtolower(trim($line, "[] \t")); continue; }
        $pos = strpos($line, '=');
        if ($pos === false) continue;
        $k = trim(substr($line, 0, $pos));
        $v = trim(substr($line, $pos + 1));
        if ($section === 'interface') $res['iface'][$k] = $v;
        elseif ($section === 'peer') $res['peer'][$k] = $v;
    }
    if (!$res['iface'] || !$res['peer']) {
        $res['warnings'][] = 'Не найдены секции [Interface] и [Peer] — это не похоже на WireGuard/AmneziaWG .conf.';
        return $res;
    }

    $obf = ['Jc', 'Jmin', 'Jmax', 'S1', 'S2', 'S3', 'S4', 'H1', 'H2', 'H3', 'H4', 'I1', 'I2', 'I3', 'I4', 'I5'];
    $has_obf = false;
    foreach ($obf as $f) if (isset($res['iface'][$f]) && $res['iface'][$f] !== '') { $has_obf = true; break; }
    $res['type'] = $has_obf ? 'amneziawg' : 'wireguard';

    $h_range = false;
    foreach (['H1', 'H2', 'H3', 'H4'] as $f) if (!empty($res['iface'][$f]) && strpos($res['iface'][$f], '-') !== false) $h_range = true;
    $has_s34 = (!empty($res['iface']['S3']) || !empty($res['iface']['S4']));
    $has_i = false;
    foreach (['I1', 'I2', 'I3', 'I4', 'I5'] as $f) if (!empty($res['iface'][$f])) $has_i = true;
    if ($res['type'] === 'amneziawg') {
        if ($h_range || $has_s34) $res['version'] = '2.0';
        elseif ($has_i) $res['version'] = '1.5';
        else $res['version'] = '1.0';
    }

    $missing = false;
    foreach (['PrivateKey', 'Address'] as $f) if (empty($res['iface'][$f])) { $res['warnings'][] = "В [Interface] нет обязательного поля $f."; $missing = true; }
    foreach (['PublicKey', 'Endpoint'] as $f) if (empty($res['peer'][$f])) { $res['warnings'][] = "В [Peer] нет обязательного поля $f."; $missing = true; }

    if ($res['type'] === 'amneziawg') {
        $res['clients'] = ['Mihomo / Clash.Meta', 'Throne (wg://)'];
        $res['warnings'][] = 'AmneziaWG: работает в Mihomo (clash) и в клиентах с wg://-AmneziaWG (Throne и др.). В v2rayNG (wireguard://), xray и sing-box — нет (там нет amnezia-обфускации).';
    } elseif ($res['type'] === 'wireguard') {
        $res['clients'] = ['Mihomo / Clash.Meta', 'base64-клиенты (v2rayNG и др.)', 'sing-box 1.11+'];
        $res['warnings'][] = 'sing-box: только актуальная версия (1.11+) — WG отдаётся новым форматом endpoints; в сборках до 1.11 узел не подхватится.';
    }

    $res['ok'] = in_array($res['type'], ['wireguard', 'amneziawg'], true) && !$missing;
    return $res;
}

function awg_summary($parsed) {
    if (!is_array($parsed)) return '';
    if ($parsed['type'] === 'amneziawg') return 'AmneziaWG ' . ($parsed['version'] ?: '');
    if ($parsed['type'] === 'wireguard') return 'WireGuard';
    return 'неизвестный формат';
}

function awg_to_clash($parsed, $name) {
    if (!is_array($parsed) || !in_array($parsed['type'] ?? '', ['amneziawg', 'wireguard'], true)) return '';
    $if = $parsed['iface']; $pe = $parsed['peer'];
    $ep = (string) ($pe['Endpoint'] ?? '');
    $host = $ep; $port = '';
    if (($pos = strrpos($ep, ':')) !== false) { $host = substr($ep, 0, $pos); $port = substr($ep, $pos + 1); }
    $host = trim($host, '[]');

    $addr = awg_split_list($if['Address'] ?? '');
    $ip4 = ''; $ip6 = '';
    foreach ($addr as $a) { if (strpos($a, ':') !== false) { if ($ip6 === '') $ip6 = $a; } elseif ($ip4 === '') $ip4 = $a; }

    $allowed = awg_split_list($pe['AllowedIPs'] ?? '0.0.0.0/0, ::/0');
    $dns = awg_split_list($if['DNS'] ?? '');

    $L = [];
    $L[] = '  - name: ' . yaml_q($name);
    $L[] = '    type: wireguard';
    $L[] = '    server: ' . $host;
    if ($port !== '') $L[] = '    port: ' . (int) $port;
    if ($ip4 !== '') $L[] = '    ip: ' . $ip4;
    if ($ip6 !== '') $L[] = '    ipv6: ' . $ip6;
    $L[] = '    private-key: ' . yaml_q($if['PrivateKey'] ?? '');
    $L[] = '    public-key: ' . yaml_q($pe['PublicKey'] ?? '');
    if (!empty($pe['PresharedKey'])) $L[] = '    pre-shared-key: ' . yaml_q($pe['PresharedKey']);
    $L[] = '    allowed-ips: [' . implode(', ', array_map('yaml_q', $allowed)) . ']';
    if ($dns) $L[] = '    dns: [' . implode(', ', array_map('yaml_q', $dns)) . ']';
    if (!empty($if['MTU'])) $L[] = '    mtu: ' . (int) $if['MTU'];
    $L[] = '    udp: true';
    $ka = (int) ($pe['PersistentKeepalive'] ?? 25);
    if ($ka > 0) $L[] = '    persistent-keepalive: ' . $ka;

    $opt = [];
    foreach (['Jc' => 'jc', 'Jmin' => 'jmin', 'Jmax' => 'jmax', 'S1' => 's1', 'S2' => 's2', 'S3' => 's3', 'S4' => 's4'] as $src => $dst) {
        if (isset($if[$src]) && $if[$src] !== '') $opt[] = [$dst, (string) (int) $if[$src]];
    }
    foreach (['H1' => 'h1', 'H2' => 'h2', 'H3' => 'h3', 'H4' => 'h4'] as $src => $dst) {
        if (isset($if[$src]) && $if[$src] !== '') $opt[] = [$dst, (string) $if[$src]];
    }
    foreach (['I1' => 'i1', 'I2' => 'i2', 'I3' => 'i3', 'I4' => 'i4', 'I5' => 'i5'] as $src => $dst) {
        if (!empty($if[$src])) $opt[] = [$dst, yaml_q((string) $if[$src])];
    }
    if ($opt) {
        $L[] = '    amnezia-wg-option:';
        foreach ($opt as $kv) $L[] = '      ' . $kv[0] . ': ' . $kv[1];
    }
    return implode("\n", $L);
}

function wg_to_uri($parsed, $name) {
    if (!is_array($parsed) || ($parsed['type'] ?? '') !== 'wireguard') return '';
    $if = $parsed['iface']; $pe = $parsed['peer'];
    $ep = (string) ($pe['Endpoint'] ?? '');
    $host = $ep; $port = '';
    if (($pos = strrpos($ep, ':')) !== false) { $host = substr($ep, 0, $pos); $port = substr($ep, $pos + 1); }
    $host = trim($host, '[]');
    $pk = (string) ($if['PrivateKey'] ?? '');
    if ($pk === '' || $host === '' || $port === '' || empty($pe['PublicKey'])) return '';
    $q = [];
    $addr = str_replace(' ', '', (string) ($if['Address'] ?? ''));
    if ($addr !== '') $q[] = 'address=' . $addr;
    $q[] = 'publickey=' . (string) $pe['PublicKey'];
    if (!empty($pe['PresharedKey'])) $q[] = 'presharedkey=' . (string) $pe['PresharedKey'];
    if (!empty($if['MTU'])) $q[] = 'mtu=' . (int) $if['MTU'];
    if (!empty($pe['PersistentKeepalive'])) $q[] = 'keepalive=' . (int) $pe['PersistentKeepalive'];
    return 'wireguard://' . $pk . '@' . $host . ':' . (int) $port . '?' . implode('&', $q) . '#' . rawurlencode($name);
}

function squadconf_any() {
    static $cached = null;
    if ($cached !== null) return $cached;
    squadconf_ensure();
    $cached = false;
    if (!($p = db())) return false;
    try { $cached = (bool) $p->query('SELECT 1 FROM squad_configs WHERE enabled = 1 LIMIT 1')->fetchColumn(); }
    catch (Throwable $e) { $cached = false; }
    return $cached;
}

function squadconf_cache_ensure() {
    static $done = false;
    if ($done) return;
    $done = true;
    if (!($p = db())) return;
    try {
        if (db_driver() === 'mysql') {
            $p->exec("CREATE TABLE IF NOT EXISTS squad_cache (
                su VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                squads TEXT NULL,
                ts INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (su)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } else {
            $p->exec("CREATE TABLE IF NOT EXISTS squad_cache (
                su TEXT NOT NULL PRIMARY KEY,
                squads TEXT NULL,
                ts INTEGER NOT NULL DEFAULT 0
            )");
        }
    } catch (Throwable $e) { error_log('submw squad_cache ensure: ' . $e->getMessage()); }
}

function squadconf_cache_drop($short) {
    $short = trim((string) $short);
    if ($short === '' || !($p = db())) return;
    squadconf_cache_ensure();
    try { $p->prepare('DELETE FROM squad_cache WHERE su = ?')->execute([$short]); }
    catch (Throwable $e) {}
}

function squadconf_user_squads($short) {
    $short = trim((string) $short);
    if ($short === '') return [];
    if (remnawave_url() === '' || remnawave_token() === '') return [];
    squadconf_cache_ensure();
    if (!($p = db())) return [];
    $now = time();
    $row = null;
    try {
        $st = $p->prepare('SELECT squads, ts FROM squad_cache WHERE su = ?');
        $st->execute([$short]);
        $row = $st->fetch();
    } catch (Throwable $e) {}
    if ($row && ($now - (int) $row['ts'] < 300)) {
        $a = json_decode((string) $row['squads'], true);
        return is_array($a) ? $a : [];
    }
    $e = '';
    $u = remnawave_get_user_by_short($short, $e);
    if (!is_array($u)) {
        if ($row) { $a = json_decode((string) $row['squads'], true); return is_array($a) ? $a : []; }
        return [];
    }
    $squads = function_exists('grace_squads_from_user') ? grace_squads_from_user($u) : [];
    try {
        if (db_driver() === 'mysql') {
            $st = $p->prepare('INSERT INTO squad_cache (su, squads, ts) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE squads = VALUES(squads), ts = VALUES(ts)');
        } else {
            $st = $p->prepare('INSERT INTO squad_cache (su, squads, ts) VALUES (?, ?, ?) ON CONFLICT(su) DO UPDATE SET squads = excluded.squads, ts = excluded.ts');
        }
        $st->execute([$short, json_encode(array_values($squads)), $now]);
    } catch (Throwable $e2) {}
    return $squads;
}

function squadconf_inject_clash($body, array $configs) {
    // Тело должно быть YAML-подпиской Clash, а не base64/JSON/страницей ошибки —
    // иначе не дописываем ничего, чтобы не испортить ответ.
    $s = ltrim((string) $body);
    if ($s === '' || $s[0] === '{' || $s[0] === '[') return $body;
    if (!preg_match('~(^|\n)\s*(proxies|proxy-groups|proxy-providers|mixed-port|port|mode)\s*:~i', $s)) return $body;
    $blocks = []; $names = [];
    foreach ($configs as $c) {
        $pn = json_decode((string) ($c['parsed'] ?? ''), true);
        if (!is_array($pn)) continue;
        $t = $pn['type'] ?? '';
        if (!in_array($t, ['amneziawg', 'wireguard', 'vless'], true)) continue;
        $def = $t === 'vless' ? 'VLESS' : ($t === 'wireguard' ? 'WireGuard' : 'AmneziaWG');
        $nm = ($c['name'] !== null && trim((string) $c['name']) !== '') ? trim((string) $c['name']) : $def;
        $base = $nm; $i = 1;
        while (in_array($nm, $names, true)) { $i++; $nm = $base . ' ' . $i; }
        $blk = $t === 'vless' ? vless_to_clash($pn, $nm) : awg_to_clash($pn, $nm);
        if ($blk === '') continue;
        $blocks[] = $blk; $names[] = $nm;
    }
    if (!$blocks) return $body;
    return clash_insert_proxies($body, $blocks, $names);
}

function squadconf_wgkey($v) { return str_replace('=', '%3D', (string) $v); }

function wg_to_uri_wg($parsed, $name) {
    if (!is_array($parsed) || !in_array($parsed['type'] ?? '', ['wireguard', 'amneziawg'], true)) return '';
    $if = $parsed['iface']; $pe = $parsed['peer'];
    $ep = (string) ($pe['Endpoint'] ?? '');
    $host = $ep; $port = '';
    if (($pos = strrpos($ep, ':')) !== false) { $host = substr($ep, 0, $pos); $port = substr($ep, $pos + 1); }
    $host = trim($host, '[]');
    $pk = (string) ($if['PrivateKey'] ?? '');
    if ($pk === '' || $host === '' || $port === '' || empty($pe['PublicKey'])) return '';
    $q = ['private_key=' . squadconf_wgkey($pk)];
    $addr = str_replace(' ', '', (string) ($if['Address'] ?? ''));
    if ($addr !== '') $q[] = 'local_address=' . $addr;
    if (($parsed['type'] ?? '') === 'amneziawg') {
        $q[] = 'enable_amnezia=true';
        foreach (['Jc' => 'jc', 'Jmin' => 'jmin', 'Jmax' => 'jmax', 'S1' => 's1', 'S2' => 's2', 'S3' => 's3', 'S4' => 's4'] as $src => $dst) {
            if (isset($if[$src]) && $if[$src] !== '') $q[] = $dst . '=' . (int) $if[$src];
        }
        foreach (['H1' => 'h1', 'H2' => 'h2', 'H3' => 'h3', 'H4' => 'h4'] as $src => $dst) {
            if (isset($if[$src]) && $if[$src] !== '') $q[] = $dst . '=' . $if[$src];
        }
        foreach (['I1' => 'i1', 'I2' => 'i2', 'I3' => 'i3', 'I4' => 'i4', 'I5' => 'i5'] as $src => $dst) {
            if (!empty($if[$src])) $q[] = $dst . '=' . rawurlencode((string) $if[$src]);
        }
    }
    $q[] = 'public_key=' . squadconf_wgkey((string) $pe['PublicKey']);
    if (!empty($pe['PresharedKey'])) $q[] = 'pre_shared_key=' . squadconf_wgkey((string) $pe['PresharedKey']);
    if (!empty($if['MTU'])) $q[] = 'mtu=' . (int) $if['MTU'];
    if (!empty($pe['PersistentKeepalive'])) $q[] = 'persistent_keepalive_interval=' . (int) $pe['PersistentKeepalive'];
    return 'wg://' . $host . ':' . (int) $port . '?' . implode('&', $q) . '#' . rawurlencode($name);
}

// Единый справочник клиентов — источник и для правил выдачи (core/no_awg/no_wg),
// и для каталога «Правил ответа» (rk = ключ, rg = группа в UI). Добавлять клиента здесь одним местом.
function client_catalog() {
    return [
        ['ua' => 'mihomo',       'label' => 'Clash Meta / Mihomo', 'core' => 'mihomo',   'no_awg' => 0, 'no_wg' => 0, 'rk' => 'clashmeta',    'rg' => 'other'],
        ['ua' => 'clash',        'label' => 'Clash',               'core' => 'mihomo',   'no_awg' => 0, 'no_wg' => 0],
        ['ua' => 'verge',        'label' => 'Clash Verge',         'core' => 'mihomo',   'no_awg' => 0, 'no_wg' => 0, 'rk' => 'clashverge',   'rg' => 'other'],
        ['ua' => 'flclash',      'label' => 'FlClash',             'core' => 'mihomo',   'no_awg' => 0, 'no_wg' => 0, 'rk' => 'flclash',      'rg' => 'other'],
        ['ua' => 'flclashx',     'label' => 'FlClashX',            'core' => 'mihomo',   'no_awg' => 0, 'no_wg' => 0, 'rk' => 'flclashx',     'rg' => 'popular'],
        ['ua' => 'koala',        'label' => 'Koala Clash',         'core' => 'mihomo',   'no_awg' => 0, 'no_wg' => 0, 'rk' => 'koala',        'rg' => 'popular'],
        ['ua' => 'stash',        'label' => 'Stash',               'core' => 'mihomo',   'no_awg' => 0, 'no_wg' => 0, 'rk' => 'stash',        'rg' => 'other'],
        ['ua' => 'throne',       'label' => 'Throne',              'core' => 'sing-box', 'no_awg' => 0, 'no_wg' => 0],
        ['ua' => 'happ',         'label' => 'Happ',                'core' => 'xray',     'no_awg' => 1, 'no_wg' => 0, 'rk' => 'happ',         'rg' => 'popular'],
        ['ua' => 'incy',         'label' => 'INCY',                'core' => 'xray',     'no_awg' => 1, 'no_wg' => 0, 'rk' => 'incy',         'rg' => 'popular'],
        ['ua' => 'v2rayng',      'label' => 'v2rayNG',             'core' => 'xray',     'no_awg' => 1, 'no_wg' => 0, 'rk' => 'v2rayng',      'rg' => 'other'],
        ['ua' => 'v2rayn',       'label' => 'v2rayN',              'core' => 'xray',     'no_awg' => 1, 'no_wg' => 0, 'rk' => 'v2rayn',       'rg' => 'other'],
        ['ua' => 'v2raytun',     'label' => 'v2RayTun',            'core' => 'xray',     'no_awg' => 1, 'no_wg' => 0],
        ['ua' => 'v2box',        'label' => 'V2Box',               'core' => 'xray',     'no_awg' => 1, 'no_wg' => 0],
        ['ua' => 'foxray',       'label' => 'FoXray',              'core' => 'xray',     'no_awg' => 1, 'no_wg' => 0],
        ['ua' => 'shadowrocket', 'label' => 'Shadowrocket',        'core' => 'xray',     'no_awg' => 1, 'no_wg' => 0, 'rk' => 'shadowrocket', 'rg' => 'other'],
        ['ua' => 'sing-box',     'label' => 'sing-box',            'core' => 'sing-box', 'no_awg' => 1, 'no_wg' => 0, 'rk' => 'singbox',      'rg' => 'other'],
        ['ua' => 'nekobox',      'label' => 'NekoBox',             'core' => 'sing-box', 'no_awg' => 1, 'no_wg' => 0, 'rk' => 'nekobox',      'rg' => 'other'],
        ['ua' => 'nekoray',      'label' => 'NekoRay',             'core' => 'sing-box', 'no_awg' => 1, 'no_wg' => 0],
        ['ua' => 'hiddify',      'label' => 'Hiddify',             'core' => 'sing-box', 'no_awg' => 1, 'no_wg' => 0, 'rk' => 'hiddify',      'rg' => 'other'],
        ['ua' => 'streisand',    'label' => 'Streisand',           'core' => 'sing-box', 'no_awg' => 1, 'no_wg' => 0, 'rk' => 'streisand',    'rg' => 'other'],
        ['ua' => 'karing',       'label' => 'Karing',              'core' => 'sing-box', 'no_awg' => 1, 'no_wg' => 0, 'rk' => 'karing',       'rg' => 'other'],
    ];
}

function squadconf_ua_rules_catalog() {
    $out = [];
    foreach (client_catalog() as $c) {
        $out[] = ['ua' => $c['ua'], 'label' => $c['label'], 'core' => $c['core'], 'no_awg' => $c['no_awg'], 'no_wg' => $c['no_wg']];
    }
    return $out;
}

function squadconf_ua_rules() {
    $j = (string) setting('ua_delivery_rules', '');
    if ($j !== '') {
        $a = json_decode($j, true);
        if (is_array($a)) {
            $out = [];
            foreach ($a as $r) {
                if (!is_array($r)) continue;
                $ua = strtolower(trim((string) ($r['ua'] ?? '')));
                if ($ua === '') continue;
                $out[] = [
                    'ua'     => $ua,
                    'label'  => trim((string) ($r['label'] ?? $ua)),
                    'core'   => (string) ($r['core'] ?? ''),
                    'no_awg' => !empty($r['no_awg']) ? 1 : 0,
                    'no_wg'  => !empty($r['no_wg']) ? 1 : 0,
                ];
            }
            return $out;
        }
    }
    return squadconf_ua_rules_catalog();
}

function squadconf_ua_flags() {
    static $cache = null;
    if ($cache !== null) return $cache;
    $ua = strtolower((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    $no_awg = false; $no_wg = false;
    if ($ua !== '') {
        foreach (squadconf_ua_rules() as $r) {
            $needle = (string) $r['ua'];
            if ($needle !== '' && strpos($ua, $needle) !== false) {
                if (!empty($r['no_awg'])) $no_awg = true;
                if (!empty($r['no_wg'])) $no_wg = true;
            }
        }
    }
    return $cache = ['no_awg' => $no_awg, 'no_wg' => $no_wg];
}

function squadconf_ua_no_amnezia() { $f = squadconf_ua_flags(); return $f['no_awg']; }

function squadconf_ua_no_wg() { $f = squadconf_ua_flags(); return $f['no_wg']; }

function squadconf_inject_base64($body, array $configs) {
    $decoded = base64_decode(trim((string) $body), true);
    if ($decoded === false || $decoded === '') return $body;
    $no_amnezia = squadconf_ua_no_amnezia();
    if ($no_amnezia) {
        $scheme = 'wireguard';
    } else {
        $scheme = (strpos($decoded, 'wireguard://') !== false && strpos($decoded, 'wg://') === false) ? 'wireguard' : 'wg';
    }
    $uris = []; $names = [];
    foreach ($configs as $c) {
        $pn = json_decode((string) ($c['parsed'] ?? ''), true);
        if (!is_array($pn)) continue;
        $t = $pn['type'] ?? '';
        if ($t === 'vless') {
            $nm = ($c['name'] !== null && trim((string) $c['name']) !== '') ? trim((string) $c['name']) : 'VLESS';
            $base = $nm; $i = 1;
            while (in_array($nm, $names, true)) { $i++; $nm = $base . ' ' . $i; }
            $u = vless_relabel_uri((string) $c['raw'], $nm);
            if ($u !== '') { $uris[] = $u; $names[] = $nm; }
            continue;
        }
        if ($scheme === 'wg') { if (!in_array($t, ['wireguard', 'amneziawg'], true)) continue; }
        elseif ($t !== 'wireguard') continue;
        $nm = ($c['name'] !== null && trim((string) $c['name']) !== '') ? trim((string) $c['name']) : (($t === 'amneziawg') ? 'AmneziaWG' : 'WireGuard');
        $base = $nm; $i = 1;
        while (in_array($nm, $names, true)) { $i++; $nm = $base . ' ' . $i; }
        $u = ($scheme === 'wg') ? wg_to_uri_wg($pn, $nm) : wg_to_uri($pn, $nm);
        if ($u === '') continue;
        $uris[] = $u; $names[] = $nm;
    }
    if (!$uris) return $body;
    $sep = (strpos($decoded, "\r\n") !== false) ? "\r\n" : "\n";
    $decoded = rtrim($decoded, "\r\n") . $sep . implode($sep, $uris);
    return base64_encode($decoded);
}

function squadconf_singbox_endpoint($parsed, $tag) {
    if (!is_array($parsed) || ($parsed['type'] ?? '') !== 'wireguard') return null;
    $if = $parsed['iface']; $pe = $parsed['peer'];
    $ep = (string) ($pe['Endpoint'] ?? '');
    if ($ep === '' || empty($if['PrivateKey']) || empty($pe['PublicKey'])) return null;
    $host = $ep; $port = '';
    if (($pos = strrpos($ep, ':')) !== false) { $host = substr($ep, 0, $pos); $port = substr($ep, $pos + 1); }
    $host = trim($host, '[]');
    if ($host === '' || $port === '') return null;
    $addr = array_values(array_filter(array_map('trim', explode(',', (string) ($if['Address'] ?? '')))));
    $allowed = array_values(array_filter(array_map('trim', explode(',', (string) ($pe['AllowedIPs'] ?? '0.0.0.0/0, ::/0')))));
    $peer = [
        'address'     => $host,
        'port'        => (int) $port,
        'public_key'  => (string) $pe['PublicKey'],
        'allowed_ips' => $allowed ?: ['0.0.0.0/0', '::/0'],
    ];
    if (!empty($pe['PresharedKey'])) $peer['pre_shared_key'] = (string) $pe['PresharedKey'];
    if (!empty($pe['PersistentKeepalive'])) $peer['persistent_keepalive_interval'] = (int) $pe['PersistentKeepalive'];
    $o = [
        'type'        => 'wireguard',
        'tag'         => ($tag !== '' ? $tag : 'wg-squad'),
        'address'     => $addr ?: ['10.0.0.2/32'],
        'private_key' => (string) $if['PrivateKey'],
        'peers'       => [$peer],
    ];
    if (!empty($if['MTU'])) $o['mtu'] = (int) $if['MTU'];
    return $o;
}

function squadconf_is_singbox($obj) {
    if (!is_array($obj) || !isset($obj['outbounds']) || !is_array($obj['outbounds'])) return false;
    if (isset($obj['routing']) || isset($obj['policy']) || isset($obj['stats']) || isset($obj['inbounds'][0]['protocol'])) return false;
    return isset($obj['route']) || isset($obj['endpoints']) || isset($obj['experimental']) || isset($obj['log']['level']) || isset($obj['inbounds'][0]['type']);
}

function squadconf_inject_singbox($body, array $configs) {
    if (!squadconf_is_singbox(json_decode((string) $body, true))) return $body;
    $obj = json_decode((string) $body);
    if (!is_object($obj) || !isset($obj->outbounds) || !is_array($obj->outbounds)) return $body;
    $existing = [];
    foreach ($obj->outbounds as $o) if (is_object($o) && isset($o->tag)) $existing[] = (string) $o->tag;
    if (isset($obj->endpoints) && is_array($obj->endpoints)) {
        foreach ($obj->endpoints as $e) if (is_object($e) && isset($e->tag)) $existing[] = (string) $e->tag;
    }
    $added = []; $names = [];
    foreach ($configs as $c) {
        $pn = json_decode((string) ($c['parsed'] ?? ''), true);
        if (!is_array($pn)) continue;
        $t = $pn['type'] ?? '';
        if (!in_array($t, ['wireguard', 'vless'], true)) continue;
        $nm = ($c['name'] !== null && trim((string) $c['name']) !== '') ? trim((string) $c['name']) : ($t === 'vless' ? 'VLESS' : 'WireGuard');
        $base = $nm; $i = 1;
        while (in_array($nm, $names, true) || in_array($nm, $existing, true)) { $i++; $nm = $base . ' ' . $i; }
        if ($t === 'vless') {
            $ob = vless_to_singbox($pn, $nm);
            if (!$ob) continue;
            $obj->outbounds[] = $ob;
        } else {
            $ep = squadconf_singbox_endpoint($pn, $nm);
            if (!$ep) continue;
            if (!isset($obj->endpoints) || !is_array($obj->endpoints)) $obj->endpoints = [];
            $obj->endpoints[] = $ep;
        }
        $added[] = $nm; $names[] = $nm;
    }
    if (!$added) return $body;
    foreach ($obj->outbounds as $o) {
        if (is_object($o) && in_array(($o->type ?? ''), ['selector', 'urltest'], true) && isset($o->outbounds) && is_array($o->outbounds)) {
            foreach ($added as $nm) if (!in_array($nm, $o->outbounds, true)) $o->outbounds[] = $nm;
        }
    }
    $enc = json_encode($obj, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return $enc === false ? $body : $enc;
}

function squadconf_xray_json_enabled() { return setting('squad_xray_json_inject', '0') === '1'; }

function conf_set_param($raw, $section, $key, $value) {
    $section = strtolower($section);
    $lines = preg_split('/\r\n|\r|\n/', (string) $raw);
    $cur = ''; $done = false; $out = [];
    foreach ($lines as $ln) {
        if (preg_match('/^\s*\[([A-Za-z]+)\]/', $ln, $m)) $cur = strtolower($m[1]);
        if (!$done && $cur === $section && preg_match('/^\s*' . preg_quote($key, '/') . '\s*=/i', $ln)) {
            if ($value !== '') $out[] = $key . ' = ' . $value;
            $done = true;
            continue;
        }
        $out[] = $ln;
    }
    if (!$done && $value !== '') {
        $res = []; $ins = false;
        foreach ($out as $ln) {
            $res[] = $ln;
            if (!$ins && preg_match('/^\s*\[([A-Za-z]+)\]/', $ln, $m) && strtolower($m[1]) === $section) {
                $res[] = $key . ' = ' . $value; $ins = true;
            }
        }
        $out = $res;
    }
    return implode("\n", $out);
}

function squadconf_supported_types($body, $format) {
    $f = squadconf_ua_flags();
    $wg_ok = empty($f['no_wg']);
    $awg_ok = empty($f['no_awg']);
    $t = ['vless'];
    if ($format === 'clash') {
        if ($wg_ok) $t[] = 'wireguard';
        if ($awg_ok) $t[] = 'amneziawg';
        return $t;
    }
    $trim = ltrim((string) $body);
    $is_json = !($trim === '' || ($trim[0] !== '[' && $trim[0] !== '{'));
    if ($wg_ok) $t[] = 'wireguard';
    if (!$is_json && $awg_ok) {
        // AmneziaWG уходит клиенту только когда отдаётся схема wg:// (она несёт оба типа).
        // Если панель вернула wireguard:// без wg://, клиент получит только wireguard,
        // поэтому амнезию не бронируем — иначе адрес пула занимается впустую.
        $decoded = base64_decode(trim((string) $body), true);
        $scheme = (is_string($decoded) && strpos($decoded, 'wireguard://') !== false && strpos($decoded, 'wg://') === false) ? 'wireguard' : 'wg';
        if ($scheme === 'wg') $t[] = 'amneziawg';
    }
    return $t;
}

function squadconf_inject($body, $format, array $configs) {
    if (!$configs) return $body;
    $f = squadconf_ua_flags();
    if (!empty($f['no_wg']) || !empty($f['no_awg'])) {
        $configs = array_values(array_filter($configs, function ($c) use ($f) {
            $pn = json_decode((string) ($c['parsed'] ?? ''), true);
            $t = is_array($pn) ? ($pn['type'] ?? '') : '';
            if (!empty($f['no_wg']) && in_array($t, ['wireguard', 'amneziawg'], true)) return false;
            if (!empty($f['no_awg']) && $t === 'amneziawg') return false;
            return true;
        }));
        if (!$configs) return $body;
    }
    try {
        if ($format === 'clash') return squadconf_inject_clash($body, $configs);
        $trim = ltrim((string) $body);
        if ($trim === '' || ($trim[0] !== '[' && $trim[0] !== '{')) return squadconf_inject_base64($body, $configs);
        $obj = json_decode($body, true);
        if (squadconf_is_singbox($obj)) return squadconf_inject_singbox($body, $configs);
        if (squadconf_xray_json_enabled()) return squadconf_inject_xray_json($body, $configs);
        return $body;
    } catch (Throwable $e) { error_log('submw squadconf inject: ' . $e->getMessage()); return $body; }
}

function xray_wg_outbound($parsed, $tag) {
    if (!is_array($parsed) || ($parsed['type'] ?? '') !== 'wireguard') return null;
    $if = $parsed['iface']; $pe = $parsed['peer'];
    $ep = (string) ($pe['Endpoint'] ?? '');
    if ($ep === '' || empty($if['PrivateKey']) || empty($pe['PublicKey'])) return null;
    $addr = array_values(array_filter(array_map('trim', explode(',', (string) ($if['Address'] ?? '')))));
    $allowed = array_values(array_filter(array_map('trim', explode(',', (string) ($pe['AllowedIPs'] ?? '0.0.0.0/0, ::/0')))));
    $peer = [
        'publicKey'  => (string) $pe['PublicKey'],
        'endpoint'   => $ep,
        'allowedIPs' => $allowed ?: ['0.0.0.0/0', '::/0'],
    ];
    if (!empty($pe['PresharedKey'])) $peer['preSharedKey'] = (string) $pe['PresharedKey'];
    if (!empty($pe['PersistentKeepalive'])) $peer['keepAlive'] = (int) $pe['PersistentKeepalive'];
    $settings = [
        'secretKey' => (string) $if['PrivateKey'],
        'address'   => $addr ?: ['10.0.0.2/32'],
        'peers'     => [$peer],
        'noKernelTun' => true,
    ];
    if (!empty($if['MTU'])) $settings['mtu'] = (int) $if['MTU'];
    $o = ['protocol' => 'wireguard', 'settings' => $settings];
    if ($tag !== '') $o['tag'] = $tag;
    return $o;
}

function xray_outbound_any($pn, $tag) {
    if (is_array($pn) && ($pn['type'] ?? '') === 'vless') return vless_to_xray($pn, $tag);
    return xray_wg_outbound($pn, $tag);
}

function xray_tpl_make_single($el, $proxy) {
    $kept = [];
    if (isset($el->outbounds) && is_array($el->outbounds)) {
        foreach ($el->outbounds as $ob) {
            if (is_object($ob) && !in_array((string) ($ob->protocol ?? ''), ['freedom', 'blackhole', 'dns'], true)) continue;
            $kept[] = $ob;
        }
    }
    array_unshift($kept, $proxy);
    $el->outbounds = $kept;
    unset($el->observatory, $el->burstObservatory);
    if (isset($el->routing) && is_object($el->routing)) {
        unset($el->routing->balancers);
        if (isset($el->routing->rules) && is_array($el->routing->rules)) {
            foreach ($el->routing->rules as $r) {
                if (is_object($r) && isset($r->balancerTag)) { unset($r->balancerTag); $r->outboundTag = 'proxy'; }
            }
        }
    }
}

function squadconf_inject_xray_json($body, array $configs) {
    $obj = json_decode((string) $body);
    if (!is_array($obj) && !is_object($obj)) return $body;

    $items = []; $names = [];
    foreach ($configs as $c) {
        $pn = json_decode((string) ($c['parsed'] ?? ''), true);
        if (!is_array($pn) || !in_array($pn['type'] ?? '', ['wireguard', 'vless'], true)) continue;
        $nm = ($c['name'] !== null && trim((string) $c['name']) !== '') ? trim((string) $c['name']) : (($pn['type'] ?? '') === 'vless' ? 'VLESS' : 'WireGuard');
        $base = $nm; $i = 1;
        while (in_array($nm, $names, true)) { $i++; $nm = $base . ' ' . $i; }
        $items[] = ['pn' => $pn, 'name' => $nm]; $names[] = $nm;
    }
    if (!$items) return $body;

    if (is_array($obj)) {
        foreach ($obj as $el) {
            if (!is_object($el) || !isset($el->outbounds) || !is_array($el->outbounds)) return $body;
        }
        $tpl = null;
        foreach ($obj as $el) { if (is_object($el) && isset($el->outbounds) && is_array($el->outbounds)) { $tpl = $el; break; } }
        if ($tpl === null) return $body;
        foreach ($items as $it) {
            $wg = xray_outbound_any($it['pn'], 'proxy');
            if (!$wg) continue;
            $el = json_decode(json_encode($tpl));
            xray_tpl_make_single($el, $wg);
            $el->remarks = $it['name'];
            $obj[] = $el;
        }
    } else {
        if (!isset($obj->outbounds) || !is_array($obj->outbounds)) return $body;
        foreach ($items as $it) { $wg = xray_outbound_any($it['pn'], ''); if ($wg) $obj->outbounds[] = $wg; }
    }
    $enc = json_encode($obj, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return $enc === false ? $body : $enc;
}

function clash_insert_proxies($body, array $blocks, array $names) {
    $nl = (strpos($body, "\r\n") !== false) ? "\r\n" : "\n";
    $lines = preg_split('/\r\n|\r|\n/', (string) $body);
    $out = [];
    $injected = false;
    $seen_top = false;
    $in_list = false;
    $item_indent = null;
    $key_indent = 0;
    foreach ($lines as $line) {
        if ($in_list) {
            if (preg_match('/^(\s*)-\s/', $line, $mm) && strlen($mm[1]) >= $key_indent) {
                if ($item_indent === null) $item_indent = $mm[1];
                $out[] = $line;
                continue;
            }
            $ind = ($item_indent !== null) ? $item_indent : str_repeat(' ', $key_indent);
            foreach ($names as $n) $out[] = $ind . '- ' . yaml_q($n);
            $in_list = false;
        }
        if (!$injected && preg_match('/^proxies:\s*\[\s*\]\s*$/', $line)) {
            $out[] = 'proxies:';
            foreach ($blocks as $b) foreach (explode("\n", $b) as $bl) $out[] = $bl;
            $injected = true; $seen_top = true;
            continue;
        }
        if (!$injected && preg_match('/^proxies:\s*$/', $line)) {
            $out[] = $line;
            foreach ($blocks as $b) foreach (explode("\n", $b) as $bl) $out[] = $bl;
            $injected = true; $seen_top = true;
            continue;
        }
        if (preg_match('/^proxies:/', $line)) $seen_top = true;
        if (preg_match('/^(\s+)proxies:\s*$/', $line, $m)) {
            $out[] = $line;
            $in_list = true;
            $item_indent = null;
            $key_indent = strlen($m[1]);
            continue;
        }
        $out[] = $line;
    }
    if ($in_list) {
        $ind = ($item_indent !== null) ? $item_indent : str_repeat(' ', $key_indent);
        foreach ($names as $n) $out[] = $ind . '- ' . yaml_q($n);
    }
    if (!$injected && !$seen_top) {
        $out[] = 'proxies:';
        foreach ($blocks as $b) foreach (explode("\n", $b) as $bl) $out[] = $bl;
    }
    return implode($nl, $out);
}

function squadconf_parse_any($raw) {
    $raw = (string) $raw;
    if (stripos(ltrim($raw), 'vless://') === 0) return vless_parse($raw);
    return awg_parse_conf($raw);
}

function squadconf_summary($parsed) {
    if (is_array($parsed) && ($parsed['type'] ?? '') === 'vless') return vless_summary($parsed);
    return awg_summary($parsed);
}
