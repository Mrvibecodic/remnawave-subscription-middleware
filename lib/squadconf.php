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
            if (db_has_cols($p, 'squad_configs', ['squads'])) set_setting('sqcfg_squads_col', '1');
        }
        if (setting('sqcfg_grp_col', '') !== '1') {
            try { $p->exec('ALTER TABLE squad_configs ADD COLUMN grp ' . (db_driver() === 'mysql' ? 'VARCHAR(64)' : 'TEXT') . ' NULL'); } catch (Throwable $e) {}
            if (db_has_cols($p, 'squad_configs', ['grp'])) set_setting('sqcfg_grp_col', '1');
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
    $res = ['ok' => false, 'type' => 'unknown', 'version' => '', 'iface' => [], 'peer' => [], 'clients' => [], 'warnings' => [], 'notes' => []];
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

    $has_obf = false;
    foreach (array_merge(awg_keys_base(), awg_keys_v15(), awg_keys_v3()) as $f) if (isset($res['iface'][$f]) && $res['iface'][$f] !== '') { $has_obf = true; break; }
    $res['type'] = $has_obf ? 'amneziawg' : 'wireguard';
    if ($res['type'] === 'amneziawg') {
        $res['version'] = awg_version($res);
        if (!empty($res['iface']['HeaderProtectionKey'])) {
            foreach (['S1', 'S2', 'S3', 'S4'] as $f) {
                if ((int) ($res['iface'][$f] ?? 0) < 12) { $res['warnings'][] = 'С HeaderProtectionKey значения S1–S4 должны быть не меньше 12 — проверьте, что сервер настроен так же.'; break; }
            }
        }
    }

    $missing = false;
    foreach (['PrivateKey', 'Address'] as $f) if (empty($res['iface'][$f])) { $res['warnings'][] = "В [Interface] нет обязательного поля $f."; $missing = true; }
    foreach (['PublicKey', 'Endpoint'] as $f) if (empty($res['peer'][$f])) { $res['warnings'][] = "В [Peer] нет обязательного поля $f."; $missing = true; }

    if ($res['type'] === 'amneziawg') {
        $res['clients'] = squadconf_awg_audience($res['version']);
        $res['notes'][] = 'AmneziaWG уходит клиентам: ' . implode(', ', $res['clients']) . '. Официальные Xray и sing-box AmneziaWG не поддерживают.';
    } elseif ($res['type'] === 'wireguard') {
        $res['clients'] = array_values(array_map(fn($r) => $r['label'] . ($r['wg'] ? '' : ' (если включено)'), array_filter(squadconf_delivery_matrix(), fn($r) => $r['core'] !== 'свой' && ($r['wg'] || $r['wg_toggle']))));
        $res['notes'][] = 'sing-box: только актуальная версия (1.11+) — WG отдаётся новым форматом endpoints; в сборках до 1.11 узел не подхватится.';
    }

    $res['ok'] = in_array($res['type'], ['wireguard', 'amneziawg'], true) && !$missing;
    return $res;
}

function awg_field_map() {
    static $m = null;
    if ($m !== null) return $m;
    $m = [];
    foreach (['Jc', 'Jmin', 'Jmax', 'S1', 'S2', 'S3', 'S4'] as $k) $m[$k] = ['int', ''];
    foreach (['H1', 'H2', 'H3', 'H4'] as $k) $m[$k] = ['raw', ''];
    foreach (['I1', 'I2', 'I3', 'I4', 'I5'] as $k) $m[$k] = ['str', ''];
    foreach (['J1', 'J2', 'J3'] as $k) $m[$k] = ['str', '1.5'];
    $m['Itime'] = ['int', '1.5'];
    $m['HeaderProtectionKey'] = ['key', '3'];
    foreach (['ContentPaddingAddition', 'RekeyAfterTime', 'RekeyTimeout', 'RejectAfterTime', 'KeepaliveTimeout', 'MaxHandshakeAttempts'] as $k) $m[$k] = ['range', '3'];
    foreach (['RandomTrailers', 'DisableCookies'] as $k) $m[$k] = ['bool', '3'];
    return $m;
}

function awg_field_name($conf, $sep) { return strtolower((string) preg_replace('~(?<=[a-z0-9])(?=[A-Z])~', $sep, $conf)); }

function awg_keys_of($gen) { return array_keys(array_filter(awg_field_map(), fn($f) => $f[1] === $gen)); }

function awg_keys_base() { return awg_keys_of(''); }

function awg_keys_v15() { return awg_keys_of('1.5'); }

function awg_keys_v3() { return awg_keys_of('3'); }

function awg_opts($parsed, $target) {
    $if = is_array($parsed['iface'] ?? null) ? $parsed['iface'] : [];
    $gens = $target === 'throne' ? ['', '3'] : (in_array(awg_version($parsed), ['3.0', '3.1'], true) ? ['', '3'] : ['', '1.5']);
    $out = [];
    foreach (awg_field_map() as $k => [$type, $gen]) {
        if (!in_array($gen, $gens, true) || !isset($if[$k]) || trim((string) $if[$k]) === '') continue;
        if ($type === 'bool') { if (awg_bool($if[$k])) $out[$k] = [$type, true]; continue; }
        if ($type === 'int') { $out[$k] = [$type, (int) $if[$k]]; continue; }
        $out[$k] = [$type, $type === 'range' ? str_replace(' ', '', (string) $if[$k]) : trim((string) $if[$k])];
    }
    return $out;
}

function awg_has($if, array $keys) {
    foreach ($keys as $k) if (isset($if[$k]) && trim((string) $if[$k]) !== '') return true;
    return false;
}

function awg_version($parsed) {
    if (!is_array($parsed) || ($parsed['type'] ?? '') !== 'amneziawg') return '';
    $if = is_array($parsed['iface'] ?? null) ? $parsed['iface'] : [];
    if (awg_has($if, awg_keys_v3())) return awg_has($if, ['RandomTrailers', 'DisableCookies']) ? '3.1' : '3.0';
    foreach (['H1', 'H2', 'H3', 'H4'] as $f) if (strpos((string) ($if[$f] ?? ''), '-') !== false) return '2.0';
    if (awg_has($if, ['S3', 'S4'])) return '2.0';
    if (awg_has($if, array_merge(awg_keys_v15(), ['I1', 'I2', 'I3', 'I4', 'I5']))) return '1.5';
    return '1.0';
}

function awg_client_min($client, $ver) {
    $v3 = $ver === '3.0' || $ver === '3.1';
    if ($client === 'mihomo') return $v3 ? '1.19.30' : (($ver === '1.5' || $ver === '2.0') ? '1.19.15' : '');
    if ($client === 'throne') return $v3 ? '1.2.2' : '1.1.5';
    return '';
}

function squadconf_join_ru(array $a) { return count($a) > 1 ? implode(', ', array_slice($a, 0, -1)) . ' и ' . end($a) : (string) ($a[0] ?? ''); }

function squadconf_incy_awg_platforms() { return ['ios' => 'iOS', 'ipados' => 'iPadOS', 'android' => 'Android']; }

function squadconf_delivery_matrix() {
    $rows = [
        ['label' => 'Clash YAML', 'core' => 'mihomo', 'who' => 'mihomo', 'tail' => ' (формат Clash)', 'min' => 'mihomo', 'kinds' => ['clash' => 'mihomo']],
        ['label' => 'JSON sing-box', 'core' => 'sing-box', 'who' => '', 'min' => '', 'kinds' => ['singbox' => 'sing-box']],
        ['label' => 'JSON xray', 'core' => 'Xray', 'who' => '', 'min' => '', 'kinds' => ['xray' => 'Happ/1']],
        ['label' => 'Ссылки', 'core' => 'Xray', 'who' => '', 'min' => '', 'kinds' => ['links' => 'v2rayNG/1']],
        ['label' => 'Throne', 'core' => 'свой', 'who' => 'Throne', 'min' => 'throne', 'kinds' => ['links' => squadconf_ua_sample('throne'), 'singbox' => squadconf_ua_sample('throne')]],
        ['label' => 'INCY', 'core' => 'свой', 'who' => 'INCY на ' . squadconf_join_ru(array_values(squadconf_incy_awg_platforms())), 'note' => 'на ' . squadconf_join_ru(array_values(squadconf_incy_awg_platforms())), 'min' => '', 'kinds' => ['links' => squadconf_ua_sample('incy'), 'xray' => squadconf_ua_sample('incy')]],
    ];
    foreach ($rows as &$r) {
        $r['wg'] = []; $r['wg_toggle'] = false; $r['awg'] = [];
        foreach ($r['kinds'] as $kind => $ua) {
            $cap = squadconf_kind_caps(squadconf_client($ua), $kind);
            if ($cap['wg'] !== '') $r['wg'][$kind] = $cap['wg'];
            if ($cap['wg_toggle']) $r['wg_toggle'] = true;
            if ($cap['awg'] !== '') $r['awg'][$kind] = $cap['awg_enc'];
        }
    }
    unset($r);
    return $rows;
}

function squadconf_ua_sample($ua) {
    foreach (client_catalog() as $c) if ($c['ua'] === $ua) return (string) ($c['sample'] ?? $c['ua']);
    return (string) $ua;
}

function awg_client_ranges($client) {
    $groups = [];
    foreach (['1.0', '1.5', '2.0', '3.0'] as $v) {
        $mn = awg_client_min($client, $v);
        $n = count($groups);
        if ($n && $groups[$n - 1][1] === $mn) $groups[$n - 1][2] = $v;
        else $groups[] = [$v, $mn, $v];
    }
    $out = [];
    foreach ($groups as [$from, $mn, $to]) {
        if ($mn === '') continue;
        $lbl = $from === '3.0' ? '3.x' : ($from === $to ? $from : $from . '–' . ($to === '3.0' ? '3.x' : $to));
        $out[] = $lbl . ' — с ' . $mn;
    }
    return implode(', ', $out);
}

function squadconf_awg_audience($ver) {
    $out = [];
    foreach (squadconf_delivery_matrix() as $r) {
        if (!$r['awg'] || $r['who'] === '') continue;
        $mn = $r['min'] !== '' ? awg_client_min($r['min'], $ver) : '';
        $out[] = $r['who'] . ($mn !== '' ? ' ' . $mn . '+' : '') . ($r['tail'] ?? '');
    }
    return $out;
}

function awg_ver_class($label) { return $label === 'WG' ? 'wv-wg' : (strpos((string) $label, 'AWG 3') === 0 ? 'wv-3' : 'wv-2'); }

function squadconf_uniq_label($c, array $names, $default, array $taken = []) {
    $nm = (isset($c['name']) && trim((string) $c['name']) !== '') ? trim((string) $c['name']) : $default;
    $base = $nm; $i = 1;
    while (in_array($nm, $names, true) || in_array($nm, $taken, true)) { $i++; $nm = $base . ' ' . $i; }
    return $nm;
}

function awg_ver_rank($v) { return ['1.0' => 1, '1.5' => 2, '2.0' => 3, '3.0' => 4, '3.1' => 5][(string) $v] ?? 0; }

function awg_bool($v) { return in_array(strtolower(trim((string) $v)), ['1', 'true', 'yes', 'on'], true); }

function awg_summary($parsed) {
    if (!is_array($parsed)) return '';
    if ($parsed['type'] === 'amneziawg') return 'AmneziaWG ' . awg_version($parsed);
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
    if (in_array(awg_version($parsed), ['3.0', '3.1'], true)) $opt[] = ['version', '3'];
    foreach (awg_opts($parsed, 'clash') as $k => [$type, $v]) {
        $opt[] = [awg_field_name($k, '-'), $type === 'int' ? (string) $v : ($type === 'bool' ? 'true' : ($type === 'raw' ? (string) $v : yaml_q((string) $v)))];
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
    $q[] = 'publickey=' . strtr((string) $pe['PublicKey'], ['+' => '%2B', '/' => '%2F']);
    if (!empty($pe['PresharedKey'])) $q[] = 'presharedkey=' . strtr((string) $pe['PresharedKey'], ['+' => '%2B', '/' => '%2F']);
    if (!empty($if['MTU'])) $q[] = 'mtu=' . (int) $if['MTU'];
    if (!empty($pe['PersistentKeepalive'])) $q[] = 'keepalive=' . (int) $pe['PersistentKeepalive'];
    return 'wireguard://' . strtr($pk, ['+' => '%2B', '/' => '%2F']) . '@' . $host . ':' . (int) $port . '?' . implode('&', $q) . '#' . rawurlencode($name);
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
                st VARCHAR(32) NULL,
                ts INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (su)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } else {
            $p->exec("CREATE TABLE IF NOT EXISTS squad_cache (
                su TEXT NOT NULL PRIMARY KEY,
                squads TEXT NULL,
                st TEXT NULL,
                ts INTEGER NOT NULL DEFAULT 0
            )");
        }
        if (setting('sqcache_st_col', '') !== '1') {
            try { $p->exec('ALTER TABLE squad_cache ADD COLUMN st ' . (db_driver() === 'mysql' ? 'VARCHAR(32)' : 'TEXT') . ' NULL'); } catch (Throwable $e) {}
            if (db_has_cols($p, 'squad_cache', ['st'])) set_setting('sqcache_st_col', '1');
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

function squadconf_user_state($short) {
    static $memo = [];
    $short = trim((string) $short);
    if ($short === '') return ['squads' => [], 'status' => ''];
    if (isset($memo[$short])) return $memo[$short];
    $none = ['squads' => [], 'status' => ''];
    if (remnawave_url() === '' || remnawave_token() === '') return $none;
    squadconf_cache_ensure();
    if (!($p = db())) return $none;
    $now = time();
    $row = null;
    try {
        $st = $p->prepare('SELECT squads, st, ts FROM squad_cache WHERE su = ?');
        $st->execute([$short]);
        $row = $st->fetch();
        $st->closeCursor();
    } catch (Throwable $e) {}
    $from_row = function ($r) {
        $a = json_decode((string) ($r['squads'] ?? ''), true);
        return ['squads' => is_array($a) ? $a : [], 'status' => strtoupper(trim((string) ($r['st'] ?? '')))];
    };
    if ($row && ($now - (int) $row['ts'] < 300)) return $memo[$short] = $from_row($row);
    $e = ''; $hc = 0;
    $u = remnawave_get_user_by_short($short, $e, $hc);
    if (!is_array($u) && (int) $hc === 404) {
        if ($row) return $memo[$short] = $none;
        try {
            if (db_driver() === 'mysql') {
                $st = $p->prepare('INSERT INTO squad_cache (su, squads, st, ts) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE squads = VALUES(squads), st = VALUES(st), ts = VALUES(ts)');
            } else {
                $st = $p->prepare('INSERT INTO squad_cache (su, squads, st, ts) VALUES (?, ?, ?, ?) ON CONFLICT(su) DO UPDATE SET squads = excluded.squads, st = excluded.st, ts = excluded.ts');
            }
            $st->execute([$short, '[]', '', $now]);
            if (random_int(1, 200) === 1) $p->prepare("DELETE FROM squad_cache WHERE ts < ? AND squads = '[]' AND (st IS NULL OR st = '')")->execute([$now - 86400]);
        } catch (Throwable $e2) {}
        return $memo[$short] = $none;
    }
    if (!is_array($u)) return $memo[$short] = ($row ? $from_row($row) : $none);
    $squads = function_exists('grace_squads_from_user') ? grace_squads_from_user($u) : [];
    $status = strtoupper(trim((string) ($u['status'] ?? '')));
    try {
        if (db_driver() === 'mysql') {
            $st = $p->prepare('INSERT INTO squad_cache (su, squads, st, ts) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE squads = VALUES(squads), st = VALUES(st), ts = VALUES(ts)');
        } else {
            $st = $p->prepare('INSERT INTO squad_cache (su, squads, st, ts) VALUES (?, ?, ?, ?) ON CONFLICT(su) DO UPDATE SET squads = excluded.squads, st = excluded.st, ts = excluded.ts');
        }
        $st->execute([$short, json_encode(array_values($squads)), $status, $now]);
    } catch (Throwable $e2) {}
    return $memo[$short] = ['squads' => $squads, 'status' => $status];
}

function squadconf_user_squads($short) {
    return squadconf_user_state($short)['squads'];
}

function squadconf_user_inactive($short) {
    if (trim((string) $short) === '') return false;
    if (!squadconf_any() && !addsub_enabled()) return false;
    $st = squadconf_user_state($short)['status'];
    return $st !== '' && $st !== 'ACTIVE';
}

function squadconf_inject_clash($body, array $configs, array &$sent) {
    $s = ltrim((string) $body);
    if ($s === '' || $s[0] === '{' || $s[0] === '[') return $body;
    if (!preg_match('~(^|\n)\s*(proxies|proxy-groups|proxy-providers|mixed-port|port|mode)\s*:~i', $s)) return $body;
    $blocks = []; $names = []; $ids = [];
    foreach ($configs as $c) {
        $pn = json_decode((string) ($c['parsed'] ?? ''), true);
        if (!is_array($pn)) continue;
        $t = $pn['type'] ?? '';
        if (!in_array($t, ['amneziawg', 'wireguard', 'vless'], true)) continue;
        $nm = squadconf_uniq_label($c, $names, $t === 'vless' ? 'VLESS' : ($t === 'wireguard' ? 'WireGuard' : 'AmneziaWG'));
        $blk = $t === 'vless' ? vless_to_clash($pn, $nm) : awg_to_clash($pn, $nm);
        if ($blk === '') continue;
        $blocks[] = $blk; $names[] = $nm; $ids[] = (int) $c['id'];
    }
    if (!$blocks) return $body;
    $out = clash_insert_proxies($body, $blocks, $names);
    if ($out !== $body) $sent = array_merge($sent, $ids);
    return $out;
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
    if ($addr !== '') $q[] = 'local_address=' . str_replace(',', '-', $addr);
    if (($parsed['type'] ?? '') === 'amneziawg') {
        $q[] = 'enable_amnezia=true';
        foreach (awg_opts($parsed, 'throne') as $k => [$type, $v]) {
            $val = $type === 'int' ? (string) $v : ($type === 'bool' ? 'true' : ($type === 'raw' ? (string) $v : ($type === 'key' ? squadconf_wgkey((string) $v) : rawurlencode((string) $v))));
            $q[] = awg_field_name($k, '_') . '=' . $val;
        }
    }
    $q[] = 'public_key=' . squadconf_wgkey((string) $pe['PublicKey']);
    if (!empty($pe['PresharedKey'])) $q[] = 'pre_shared_key=' . squadconf_wgkey((string) $pe['PresharedKey']);
    if (!empty($if['MTU'])) $q[] = 'mtu=' . (int) $if['MTU'];
    if (!empty($pe['PersistentKeepalive'])) $q[] = 'persistent_keepalive_interval=' . (int) $pe['PersistentKeepalive'];
    return 'wg://' . $host . ':' . (int) $port . '?' . implode('&', $q) . '#' . rawurlencode($name);
}

function client_catalog() {
    return [
        ['ua' => 'mihomo',       'label' => 'Clash Meta / Mihomo', 'core' => 'mihomo',   'rk' => 'clashmeta',    'rg' => 'other'],
        ['ua' => 'clash',        'label' => 'Clash',               'core' => 'mihomo'],
        ['ua' => 'verge',        'label' => 'Clash Verge',         'core' => 'mihomo',   'rk' => 'clashverge',   'rg' => 'other'],
        ['ua' => 'flclash',      'label' => 'FlClash',             'core' => 'mihomo',   'rk' => 'flclash',      'rg' => 'other'],
        ['ua' => 'flclashx',     'label' => 'FlClashX',            'core' => 'mihomo',   'rk' => 'flclashx',     'rg' => 'popular'],
        ['ua' => 'koala',        'label' => 'Koala Clash',         'core' => 'mihomo',   'rk' => 'koala',        'rg' => 'popular'],
        ['ua' => 'stash',        'label' => 'Stash',               'core' => 'mihomo',   'rk' => 'stash',        'rg' => 'other'],
        ['ua' => 'throne',       'label' => 'Throne',              'core' => 'sing-box', 'sample' => 'Throne/99.0.0'],
        ['ua' => 'happ',         'label' => 'Happ',                'core' => 'xray',     'rk' => 'happ',         'rg' => 'popular'],
        ['ua' => 'incy',         'label' => 'INCY',                'core' => 'xray',     'rk' => 'incy',         'rg' => 'popular', 'sample' => 'INCY/99/ios'],
        ['ua' => 'v2rayng',      'label' => 'v2rayNG',             'core' => 'xray',     'rk' => 'v2rayng',      'rg' => 'other'],
        ['ua' => 'v2rayn',       'label' => 'v2rayN',              'core' => 'xray',     'rk' => 'v2rayn',       'rg' => 'other'],
        ['ua' => 'v2raytun',     'label' => 'v2RayTun',            'core' => 'xray'],
        ['ua' => 'v2box',        'label' => 'V2Box',               'core' => 'xray'],
        ['ua' => 'foxray',       'label' => 'FoXray',              'core' => 'xray'],
        ['ua' => 'shadowrocket', 'label' => 'Shadowrocket',        'core' => 'xray',     'rk' => 'shadowrocket', 'rg' => 'other'],
        ['ua' => 'sing-box',     'label' => 'sing-box',            'core' => 'sing-box', 'rk' => 'singbox',      'rg' => 'other'],
        ['ua' => 'nekobox',      'label' => 'NekoBox',             'core' => 'sing-box', 'rk' => 'nekobox',      'rg' => 'other'],
        ['ua' => 'nekoray',      'label' => 'NekoRay',             'core' => 'sing-box'],
        ['ua' => 'hiddify',      'label' => 'Hiddify',             'core' => 'sing-box', 'rk' => 'hiddify',      'rg' => 'other'],
        ['ua' => 'streisand',    'label' => 'Streisand',           'core' => 'sing-box', 'rk' => 'streisand',    'rg' => 'other'],
        ['ua' => 'karing',       'label' => 'Karing',              'core' => 'sing-box', 'rk' => 'karing',       'rg' => 'other'],
    ];
}

function squadconf_ua_match($ua) {
    $ua = strtolower(trim((string) $ua));
    if ($ua === '') return null;
    $best = null; $gen = null;
    foreach (client_catalog() as $c) {
        if (strpos($ua, $c['ua']) === false) continue;
        if (in_array($c['ua'], ['mihomo', 'clash', 'sing-box'], true)) { if ($gen === null) $gen = $c; continue; }
        if ($best === null || strlen($c['ua']) > strlen($best['ua'])) $best = $c;
    }
    return $best ?? $gen;
}

function squadconf_ua_core($ua) {
    $m = squadconf_ua_match($ua);
    return $m ? (string) $m['core'] : '';
}

function squadconf_client($ua, $os = '') {
    $ua = trim((string) $ua);
    if (preg_match('~^throne/(\d+(?:\.\d+){1,3}[\w.\-]*)~i', $ua, $m)) {
        $max = '';
        foreach (['3.1', '2.0'] as $av) if (version_compare($m[1], awg_client_min('throne', $av), '>=')) { $max = $av; break; }
        return ['wg' => 'wg', 'awg' => ['links' => [$max, 'wg'], 'singbox' => [$max, 'amnezia_wg']]];
    }
    if (preg_match('~^incy/([^/\s]+)(?:/([^/\s;()]+))?~i', $ua, $m)) {
        $pl = strtolower(trim((string) (($m[2] ?? '') !== '' ? $m[2] : $os)));
        $max = isset(squadconf_incy_awg_platforms()[$pl]) ? '3.1' : '';
        return ['wg' => 'wireguard', 'awg' => ['links' => [$max, 'incy_uri'], 'xray' => [$max, 'incy_box']]];
    }
    $core = squadconf_ua_core($ua);
    return ['wg' => 'wireguard', 'awg' => ['clash' => [($core === '' || $core === 'mihomo') ? '3.1' : '', 'clash']]];
}

function squadconf_pattern_awg($pattern) {
    $p = squadconf_except_ua($pattern);
    if ($p === '') return false;
    $hit = false;
    foreach (client_catalog() as $c) {
        if (strpos($c['ua'], $p) === false && strpos($p, $c['ua']) === false) continue;
        $hit = true;
        foreach (squadconf_client(squadconf_ua_sample($c['ua']))['awg'] as [$max]) if ($max !== '') return true;
    }
    return !$hit;
}

function squadconf_kind_caps(array $cl, $kind) {
    $wg = ['clash' => 'clash', 'links' => $cl['wg'], 'singbox' => 'singbox', 'xray' => 'xray', 'xray1' => 'xray'][$kind] ?? '';
    $toggle = $wg === 'xray' && !squadconf_xray_json_enabled();
    [$max, $enc] = $cl['awg'][$kind] ?? ['', ''];
    return ['wg' => $toggle ? '' : $wg, 'wg_toggle' => $toggle, 'awg' => $max, 'awg_enc' => $max === '' ? '' : $enc];
}

function squadconf_body_kind($body, $format) {
    if ($format === 'clash') return 'clash';
    $trim = ltrim((string) $body);
    if ($trim === '' || ($trim[0] !== '[' && $trim[0] !== '{')) return 'links';
    $o = json_decode((string) $body, true);
    if (squadconf_is_singbox($o)) return 'singbox';
    return (is_array($o) && array_is_list($o)) ? 'xray' : 'xray1';
}

function squadconf_except_flags($ua) {
    $ua = strtolower((string) $ua);
    $f = ['no_awg' => false, 'no_wg' => false];
    if ($ua === '') return $f;
    foreach (squadconf_wg_except() as $r) {
        if (strpos($ua, $r['ua']) === false) continue;
        $f['no_awg'] = true;
        if ($r['block'] === 'all') $f['no_wg'] = true;
    }
    return $f;
}

function squadconf_profile($body, $format, $ua = null, $os = null) {
    $ua = $ua ?? (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    $os = $os ?? (string) ($_SERVER['HTTP_X_DEVICE_OS'] ?? '');
    $kind = squadconf_body_kind($body, $format);
    $cap = squadconf_kind_caps(squadconf_client($ua, $os), $kind);
    $ex = squadconf_except_flags($ua);
    return ['kind' => $kind, 'wg' => $ex['no_wg'] ? '' : $cap['wg'], 'awg' => $ex['no_awg'] ? '' : $cap['awg'], 'awg_enc' => $ex['no_awg'] ? '' : $cap['awg_enc']];
}

function squadconf_type_ok($c, array $prof) {
    $t = (string) ($c['type'] ?? '');
    if ($t === 'vless') return true;
    if ($t === 'wireguard') return $prof['wg'] !== '';
    return $t === 'amneziawg' && $prof['awg'] !== '';
}

function squadconf_cfg_ok($c, array $prof) {
    if (!squadconf_type_ok($c, $prof)) return false;
    if ((string) ($c['type'] ?? '') !== 'amneziawg') return true;
    $pn = json_decode((string) ($c['parsed'] ?? ''), true);
    return awg_ver_rank(awg_version(is_array($pn) ? $pn : [])) <= awg_ver_rank($prof['awg']);
}

function squadconf_wg_except() {
    static $memo = null;
    if ($memo !== null) return $memo;
    squadconf_migrate_v3();
    $a = json_decode((string) setting('wg_ua_except', ''), true);
    return $memo = is_array($a) ? squadconf_except_norm($a) : [];
}

function squadconf_wg_except_migrate($json) {
    $a = $json !== '' ? json_decode($json, true) : null;
    $res = ['keep' => [], 'covered' => [], 'lost' => []];
    if (!is_array($a)) return $res;
    $seen = [];
    foreach ($a as $r) {
        if (!is_array($r)) continue;
        $ua = squadconf_except_ua($r['ua'] ?? '');
        if ($ua === '' || isset($seen[$ua])) continue;
        $seen[$ua] = true;
        $def = squadconf_ua_rules_v1_default($ua);
        $cur = [!empty($r['no_awg']) ? 1 : 0, !empty($r['no_wg']) ? 1 : 0];
        if ($def !== null && $def === $cur) continue;
        if ($cur[1]) { $res['keep'][] = ['ua' => $ua, 'block' => 'all']; continue; }
        $cap = squadconf_pattern_awg($ua);
        if ($cur[0] && $cap) $res['keep'][] = ['ua' => $ua, 'block' => 'awg'];
        elseif ($cur[0]) $res['covered'][] = $ua;
        elseif (!$cap) $res['lost'][] = $ua;
    }
    return $res;
}

function squadconf_ua_rules_v1_default($ua) {
    $off = ['mihomo', 'clash', 'verge', 'flclash', 'flclashx', 'koala', 'stash', 'throne'];
    $on = ['happ', 'incy', 'v2rayng', 'v2rayn', 'v2raytun', 'v2box', 'foxray', 'shadowrocket', 'sing-box', 'nekobox', 'nekoray', 'hiddify', 'streisand', 'karing'];
    if (in_array($ua, $off, true)) return [0, 0];
    if (in_array($ua, $on, true)) return [1, 0];
    return null;
}

function squadconf_migrate_claim($p, $key) {
    $now = time();
    try { if ($p->prepare('INSERT INTO settings (k, v) VALUES (?, ?)')->execute([$key, 'run:' . $now])) return true; } catch (Throwable $e) {}
    try {
        $st = $p->prepare('SELECT v FROM settings WHERE k = ?');
        $st->execute([$key]);
        $v = (string) $st->fetchColumn();
        $st->closeCursor();
        if (strpos($v, 'run:') !== 0 || (int) substr($v, 4) > $now - 600) return false;
        $u = $p->prepare('UPDATE settings SET v = ? WHERE k = ? AND v = ?');
        $u->execute(['run:' . $now, $key, $v]);
        return $u->rowCount() === 1;
    } catch (Throwable $e) { return false; }
}

function squadconf_migrate_v3() {
    static $done = false;
    if ($done) return;
    $done = true;
    if (setting('wg_v3_mig', '') === '1') return;
    $p = db();
    if (!$p || !squadconf_migrate_claim($p, 'wg_v3_mig')) return;
    $note = [];
    $m = squadconf_wg_except_migrate((string) setting('ua_delivery_rules', ''));
    if ((string) setting('wg_ua_except', '') === '') set_setting('wg_ua_except', json_encode(squadconf_except_norm($m['keep']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    if ($m['keep']) $note[] = 'Ваши правила по User-Agent перенесены в «Исключения»: ' . implode(', ', array_map(fn($r) => $r['ua'] . ($r['block'] === 'all' ? ' (без WG и AWG)' : ' (без AWG)'), $m['keep'])) . '. Проверьте список внизу страницы.';
    if ($m['covered']) $note[] = 'Правила «без AWG» для ' . implode(', ', $m['covered']) . ' больше не нужны: эти клиенты не на mihomo и AWG не получают автоматически.';
    if ($m['lost']) $note[] = 'Разрешение AWG для ' . implode(', ', $m['lost']) . ' больше не действует: их ядро AmneziaWG не поддерживает, они получают только WireGuard.';
    $retyped = 0; $v3 = 0; $awg_ids = [];
    squadconf_ensure();
    try {
        $rows = $p->query('SELECT id, type, raw, parsed FROM squad_configs')->fetchAll();
        $up = $p->prepare('UPDATE squad_configs SET type = ?, parsed = ? WHERE id = ?');
        foreach ($rows as $c) {
            $t = (string) ($c['type'] ?? '');
            if (!in_array($t, ['wireguard', 'amneziawg'], true)) continue;
            $pn = squadconf_parse_any((string) $c['raw']);
            if (!is_array($pn) || !in_array($pn['type'] ?? '', ['wireguard', 'amneziawg'], true)) continue;
            if ($pn['type'] !== $t) $retyped++;
            $ver = awg_version($pn);
            if ($ver === '3.0' || $ver === '3.1') $v3++;
            if ($pn['type'] === 'amneziawg') $awg_ids[(int) $c['id']] = true;
            $js = json_encode($pn, JSON_UNESCAPED_UNICODE);
            if ($pn['type'] !== $t || $js !== (string) ($c['parsed'] ?? '')) $up->execute([$pn['type'], $js, (int) $c['id']]);
        }
    } catch (Throwable $e) {
        error_log('submw wg migrate: ' . $e->getMessage());
        return;
    }
    if ($awg_ids) $note[] = 'AmneziaWG теперь уходит только тем, кто его поднимет: ' . implode(', ', squadconf_awg_audience('1.0')) . '. Остальные получают только WireGuard; слоты AWG, которые они занимали, вернутся в пул сами через ' . wglease_reclaim_days() . ' дн.';
    if ($retyped) $note[] = 'Конфигов, распознанных заново как AmneziaWG: ' . $retyped . '. Раньше они уходили как обычный WireGuard и не подключались.';
    if ($v3) $note[] = 'Конфигов AmneziaWG 3.x: ' . $v3 . '. Теперь они уходят со всеми полями 3.x — их получат ' . implode(', ', squadconf_awg_audience('3.0')) . '.';
    if ($note) set_setting('wg_v3_mig_note', json_encode($note, JSON_UNESCAPED_UNICODE));
    set_setting('wg_v3_mig', '1');
}

function squadconf_client_label($ua) {
    $m = squadconf_ua_match($ua);
    if ($m) return (string) $m['label'];
    $t = trim((string) preg_split('~[/\s]~', trim((string) $ua))[0]);
    return $t !== '' ? mb_substr($t, 0, 24) : '';
}

function squadconf_except_ua($ua) { return is_string($ua) || is_numeric($ua) ? strtolower(mb_substr(trim((string) $ua), 0, 60)) : ''; }

function squadconf_except_norm(array $rows) {
    $out = []; $seen = [];
    foreach ($rows as $r) {
        if (!is_array($r)) continue;
        $ua = squadconf_except_ua($r['ua'] ?? '');
        if ($ua === '' || isset($seen[$ua])) continue;
        $seen[$ua] = true;
        $out[] = ['ua' => $ua, 'block' => (($r['block'] ?? '') === 'all') ? 'all' : 'awg'];
    }
    return $out;
}

function squadconf_wg_except_from_post($uas, $blocks) {
    $rows = [];
    foreach ((array) $uas as $i => $ua) $rows[] = ['ua' => $ua, 'block' => is_string($blocks[$i] ?? null) ? $blocks[$i] : 'awg'];
    return squadconf_except_norm($rows);
}

function squadconf_inject_base64($body, array $configs, array $prof, array &$sent) {
    $decoded = base64_decode(trim((string) $body), true);
    if ($decoded === false || $decoded === '') return $body;
    $uris = []; $names = []; $ids = [];
    foreach ($configs as $c) {
        $pn = json_decode((string) ($c['parsed'] ?? ''), true);
        if (!is_array($pn)) continue;
        $t = $pn['type'] ?? '';
        if (!in_array($t, ['vless', 'wireguard', 'amneziawg'], true)) continue;
        $nm = squadconf_uniq_label($c, $names, ['vless' => 'VLESS', 'wireguard' => 'WireGuard', 'amneziawg' => 'AmneziaWG'][$t]);
        $enc = $t === 'vless' ? 'vless' : ($t === 'wireguard' ? $prof['wg'] : $prof['awg_enc']);
        if ($enc === 'vless') $u = vless_relabel_uri((string) $c['raw'], $nm);
        elseif ($enc === 'wg') $u = wg_to_uri_wg($pn, $nm);
        elseif ($enc === 'wireguard') $u = wg_to_uri($pn, $nm);
        elseif ($enc === 'incy_uri') $u = awg_to_uri_incy((string) $c['raw'], $nm);
        else $u = '';
        if ($u === '') continue;
        $uris[] = $u; $names[] = $nm; $ids[] = (int) $c['id'];
    }
    if (!$uris) return $body;
    $sep = (strpos($decoded, "\r\n") !== false) ? "\r\n" : "\n";
    $decoded = rtrim($decoded, "\r\n") . $sep . implode($sep, $uris);
    $sent = array_merge($sent, $ids);
    return base64_encode($decoded);
}

function awg_conf_b64url($raw) {
    $raw = trim(str_replace(["\r\n", "\r"], "\n", (string) $raw));
    return $raw === '' ? '' : rtrim(strtr(base64_encode($raw . "\n"), '+/', '-_'), '=');
}

function awg_to_uri_incy($raw, $name) {
    $b = awg_conf_b64url($raw);
    return $b === '' ? '' : 'amneziawg://' . $b . '#' . rawurlencode((string) $name);
}

function squadconf_inject_incy($body, array $configs, array &$sent) {
    $obj = json_decode((string) $body);
    if (!is_array($obj)) return $body;
    $servers = []; $names = []; $ids = [];
    foreach ($configs as $c) {
        $pn = json_decode((string) ($c['parsed'] ?? ''), true);
        if (!is_array($pn) || ($pn['type'] ?? '') !== 'amneziawg') continue;
        $b = awg_conf_b64url((string) ($c['raw'] ?? ''));
        if ($b === '') continue;
        $nm = squadconf_uniq_label($c, $names, 'AmneziaWG');
        $servers[] = ['name' => $nm, 'config' => $b]; $names[] = $nm; $ids[] = (int) $c['id'];
    }
    if (!$servers) return $body;
    $obj[] = ['type' => 'amneziawg', 'version' => 1, 'servers' => $servers];
    $enc = json_encode($obj, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($enc === false) return $body;
    $sent = array_merge($sent, $ids);
    return $enc;
}

function awg_singbox_opts($parsed) {
    $o = [];
    foreach (awg_opts($parsed, 'throne') as $k => [$type, $v]) $o[awg_field_name($k, '_')] = $v;
    return $o;
}

function squadconf_singbox_endpoint($parsed, $tag, $amnezia = false) {
    if (!is_array($parsed) || !in_array($parsed['type'] ?? '', $amnezia ? ['wireguard', 'amneziawg'] : ['wireguard'], true)) return null;
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
    if (($parsed['type'] ?? '') === 'amneziawg') { $am = awg_singbox_opts($parsed); if ($am) $o['amnezia_wg'] = $am; }
    return $o;
}

function squadconf_is_singbox($obj) {
    if (!is_array($obj) || !isset($obj['outbounds']) || !is_array($obj['outbounds'])) return false;
    if (isset($obj['routing']) || isset($obj['policy']) || isset($obj['stats']) || isset($obj['inbounds'][0]['protocol'])) return false;
    return isset($obj['route']) || isset($obj['endpoints']) || isset($obj['experimental']) || isset($obj['log']['level']) || isset($obj['inbounds'][0]['type']);
}

function squadconf_inject_singbox($body, array $configs, array $prof, array &$sent) {
    if (!squadconf_is_singbox(json_decode((string) $body, true))) return $body;
    $obj = json_decode((string) $body);
    if (!is_object($obj) || !isset($obj->outbounds) || !is_array($obj->outbounds)) return $body;
    $existing = [];
    foreach ($obj->outbounds as $o) if (is_object($o) && isset($o->tag)) $existing[] = (string) $o->tag;
    if (isset($obj->endpoints) && is_array($obj->endpoints)) {
        foreach ($obj->endpoints as $e) if (is_object($e) && isset($e->tag)) $existing[] = (string) $e->tag;
    }
    $added = []; $names = []; $ids = [];
    $am = $prof['awg_enc'] === 'amnezia_wg';
    foreach ($configs as $c) {
        $pn = json_decode((string) ($c['parsed'] ?? ''), true);
        if (!is_array($pn)) continue;
        $t = $pn['type'] ?? '';
        if (!in_array($t, $am ? ['wireguard', 'amneziawg', 'vless'] : ['wireguard', 'vless'], true)) continue;
        $nm = squadconf_uniq_label($c, $names, ['vless' => 'VLESS', 'wireguard' => 'WireGuard', 'amneziawg' => 'AmneziaWG'][$t], $existing);
        if ($t === 'vless') {
            $ob = vless_to_singbox($pn, $nm);
            if (!$ob) continue;
            $obj->outbounds[] = $ob;
        } else {
            $ep = squadconf_singbox_endpoint($pn, $nm, $am);
            if (!$ep) continue;
            if (!isset($obj->endpoints) || !is_array($obj->endpoints)) $obj->endpoints = [];
            $obj->endpoints[] = $ep;
        }
        $added[] = $nm; $names[] = $nm; $ids[] = (int) $c['id'];
    }
    if (!$added) return $body;
    foreach ($obj->outbounds as $o) {
        if (is_object($o) && in_array(($o->type ?? ''), ['selector', 'urltest'], true) && isset($o->outbounds) && is_array($o->outbounds)) {
            foreach ($added as $nm) if (!in_array($nm, $o->outbounds, true)) $o->outbounds[] = $nm;
        }
    }
    $enc = json_encode($obj, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($enc === false) return $body;
    $sent = array_merge($sent, $ids);
    return $enc;
}

function squadconf_xray_json_enabled() { return setting('squad_xray_json_inject', '0') === '1'; }

function squadconf_xray_tpl_name() {
    $n = trim((string) setting('squad_xray_tpl_name', ''));
    return $n === '' ? 'Default' : $n;
}

function squadconf_xray_tpl_ttl() { return 600; }

function squadconf_xray_tpl_cached() {
    $c = json_decode((string) setting('sqcfg_xtpl', ''));
    if (!is_object($c)) return [];
    return [
        'name' => (string) ($c->name ?? ''),
        'ts'   => (int) ($c->ts ?? 0),
        'err'  => (string) ($c->err ?? ''),
        'tpl'  => (isset($c->tpl) && is_object($c->tpl)) ? $c->tpl : null,
    ];
}

function squadconf_xray_tpl_fetch($name, &$error = '') {
    $error = '';
    if (remnawave_url() === '' || remnawave_token() === '') {
        $error = 'Не заданы URL панели или API-токен';
        return null;
    }
    $e = '';
    $list = remnawave_sub_templates($e);
    if ($e !== '') { $error = $e; return null; }
    $uuid = '';
    foreach ($list as $t) {
        if (strcasecmp((string) $t['type'], 'XRAY_JSON') !== 0) continue;
        if (strcasecmp(trim((string) $t['name']), $name) === 0) { $uuid = $t['uuid']; break; }
    }
    if ($uuid === '') { $error = 'Шаблон xray-json «' . $name . '» в панели не найден'; return null; }
    $tpl = remnawave_sub_template_json($uuid, $e);
    if (!is_object($tpl)) { $error = $e ?: 'Пустое тело шаблона'; return null; }
    return $tpl;
}

function squadconf_xray_tpl($maxAge = null, &$error = '') {
    $error = '';
    if ($maxAge === null) $maxAge = squadconf_xray_tpl_ttl();
    $name = squadconf_xray_tpl_name();
    $now = time();
    $c = squadconf_xray_tpl_cached();
    if (($c['name'] ?? '') === $name && (int) ($c['ts'] ?? 0) > 0 && ($now - (int) $c['ts']) <= $maxAge) {
        $error = (string) ($c['err'] ?? '');
        $tpl = $c['tpl'] ?? null;
        return (is_object($tpl) && get_object_vars($tpl)) ? $tpl : null;
    }
    $tpl = squadconf_xray_tpl_fetch($name, $error);
    set_setting('sqcfg_xtpl', json_encode(
        ['name' => $name, 'ts' => $now, 'err' => $error, 'tpl' => $tpl],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    ));
    return $tpl;
}

function squadconf_xray_tpl_drop() { set_setting('sqcfg_xtpl', ''); }

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

function squadconf_inject($body, $format, array $configs, array $prof, &$sent = null) {
    $sent = [];
    $configs = array_values(array_filter($configs, fn($c) => squadconf_cfg_ok($c, $prof)));
    if (!$configs) return $body;
    try {
        if ($prof['kind'] === 'clash') return squadconf_inject_clash($body, $configs, $sent);
        if ($prof['kind'] === 'links') return squadconf_inject_base64($body, $configs, $prof, $sent);
        if ($prof['kind'] === 'singbox') return squadconf_inject_singbox($body, $configs, $prof, $sent);
        $plain = array_values(array_filter($configs, fn($c) => (string) ($c['type'] ?? '') !== 'amneziawg'));
        if ($plain && squadconf_xray_json_enabled()) $body = squadconf_inject_xray_json($body, $plain, $sent);
        if ($prof['awg_enc'] === 'incy_box') $body = squadconf_inject_incy($body, $configs, $sent);
        return $body;
    } catch (Throwable $e) { error_log('submw squadconf inject: ' . $e->getMessage()); $sent = []; return $body; }
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
    unset($el->observatory, $el->burstObservatory, $el->remnawave, $el->meta);
    if (isset($el->routing) && is_object($el->routing)) {
        unset($el->routing->balancers);
        if (isset($el->routing->rules) && is_array($el->routing->rules)) {
            foreach ($el->routing->rules as $r) {
                if (is_object($r) && isset($r->balancerTag)) { unset($r->balancerTag); $r->outboundTag = 'proxy'; }
            }
        }
    }
}

function squadconf_inject_xray_json($body, array $configs, array &$sent) {
    $obj = json_decode((string) $body);
    if (!is_array($obj) && !is_object($obj)) return $body;

    $items = []; $names = [];
    foreach ($configs as $c) {
        $pn = json_decode((string) ($c['parsed'] ?? ''), true);
        if (!is_array($pn) || !in_array($pn['type'] ?? '', ['wireguard', 'vless'], true)) continue;
        $nm = squadconf_uniq_label($c, $names, ($pn['type'] ?? '') === 'vless' ? 'VLESS' : 'WireGuard');
        $items[] = ['pn' => $pn, 'name' => $nm, 'id' => (int) $c['id']]; $names[] = $nm;
    }
    if (!$items) return $body;

    if (is_array($obj)) {
        foreach ($obj as $el) {
            if (!is_object($el) || !isset($el->outbounds) || !is_array($el->outbounds)) return $body;
        }
        $tpl = null;
        $def = squadconf_xray_tpl();
        if (is_object($def)) $tpl = json_decode(json_encode($def, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        if (!is_object($tpl)) {
            foreach ($obj as $el) { if (is_object($el) && isset($el->outbounds) && is_array($el->outbounds)) { $tpl = $el; break; } }
        }
        if (!is_object($tpl)) return $body;
        $ids = [];
        foreach ($items as $it) {
            $wg = xray_outbound_any($it['pn'], 'proxy');
            if (!$wg) continue;
            $el = json_decode(json_encode($tpl));
            xray_tpl_make_single($el, $wg);
            $el->remarks = $it['name'];
            $obj[] = $el;
            $ids[] = $it['id'];
        }
    } else {
        if (!isset($obj->outbounds) || !is_array($obj->outbounds)) return $body;
        $ids = [];
        foreach ($items as $it) { $wg = xray_outbound_any($it['pn'], ''); if ($wg) { $obj->outbounds[] = $wg; $ids[] = $it['id']; } }
    }
    $enc = json_encode($obj, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($enc === false) return $body;
    $sent = array_merge($sent, $ids);
    return $enc;
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

function squadconf_batch_items($files_json, $raw_batch) {
    $items = [];
    $fj = json_decode((string) $files_json, true);
    if (is_array($fj)) {
        foreach ($fj as $f) {
            if (!is_array($f) || !is_string($f['c'] ?? null) || trim($f['c']) === '') continue;
            $items[] = [trim((string) preg_replace('/\.[A-Za-z0-9]+$/', '', (string) ($f['n'] ?? ''))), $f['c']];
        }
    }
    if (trim((string) $raw_batch) !== '') {
        foreach (preg_split('/(?=\[Interface\])/i', (string) $raw_batch) as $blk) {
            if (trim($blk) !== '') $items[] = ['', $blk];
        }
    }
    return $items;
}

function squadconf_batch_rows(array $items, $prefix, $limit = 200) {
    $rows = []; $auto = 0;
    foreach (array_slice($items, 0, $limit) as [$lbl, $raw]) {
        $src = $lbl;
        $pn = squadconf_parse_any($raw);
        $t = (string) ($pn['type'] ?? '');
        $ok = !empty($pn['ok']) && in_array($t, ['wireguard', 'amneziawg'], true);
        if ($ok && $lbl === '') { $auto++; $lbl = ($t === 'amneziawg' ? 'AWG' : 'WG') . ' ' . $auto; }
        if ($lbl !== '' && trim((string) $prefix) !== '') $lbl = trim((string) $prefix) . ' · ' . $lbl;
        $rows[] = [
            'src'    => $src,
            'name'   => $lbl === '' ? '' : mb_substr(squadconf_flag_label($lbl), 0, 191),
            'raw'    => $raw,
            'parsed' => $pn,
            'ok'     => $ok,
            'ver'    => $t === 'amneziawg' ? 'AWG ' . awg_version($pn) : ($t === 'wireguard' ? 'WG' : ''),
            'warn'   => (string) (($pn['warnings'] ?? [])[0] ?? ($ok ? '' : 'Не похоже на WireGuard или AmneziaWG.')),
        ];
    }
    return ['rows' => $rows, 'cut' => max(0, count($items) - $limit)];
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

function squadconf_country_map() {
    static $m = null;
    if ($m === null) $m = ['нидерланды'=>'NL','голландия'=>'NL','netherlands'=>'NL','the netherlands'=>'NL','holland'=>'NL','германия'=>'DE','germany'=>'DE','deutschland'=>'DE','сша'=>'US','америка'=>'US','соединенные штаты'=>'US','usa'=>'US','united states'=>'US','america'=>'US','великобритания'=>'GB','британия'=>'GB','англия'=>'GB','шотландия'=>'GB','united kingdom'=>'GB','great britain'=>'GB','britain'=>'GB','england'=>'GB','scotland'=>'GB','европа'=>'EU','евросоюз'=>'EU','ес'=>'EU','europe'=>'EU','european union'=>'EU','франция'=>'FR','france'=>'FR','финляндия'=>'FI','finland'=>'FI','швеция'=>'SE','sweden'=>'SE','норвегия'=>'NO','norway'=>'NO','дания'=>'DK','denmark'=>'DK','польша'=>'PL','poland'=>'PL','чехия'=>'CZ','czechia'=>'CZ','czech'=>'CZ','австрия'=>'AT','austria'=>'AT','швейцария'=>'CH','switzerland'=>'CH','италия'=>'IT','italy'=>'IT','испания'=>'ES','spain'=>'ES','португалия'=>'PT','portugal'=>'PT','ирландия'=>'IE','ireland'=>'IE','бельгия'=>'BE','belgium'=>'BE','люксембург'=>'LU','luxembourg'=>'LU','лихтенштейн'=>'LI','liechtenstein'=>'LI','монако'=>'MC','monaco'=>'MC','андорра'=>'AD','andorra'=>'AD','сан марино'=>'SM','san marino'=>'SM','россия'=>'RU','russia'=>'RU','украина'=>'UA','ukraine'=>'UA','беларусь'=>'BY','белоруссия'=>'BY','belarus'=>'BY','казахстан'=>'KZ','kazakhstan'=>'KZ','узбекистан'=>'UZ','uzbekistan'=>'UZ','киргизия'=>'KG','кыргызстан'=>'KG','kyrgyzstan'=>'KG','таджикистан'=>'TJ','tajikistan'=>'TJ','туркменистан'=>'TM','turkmenistan'=>'TM','монголия'=>'MN','mongolia'=>'MN','турция'=>'TR','turkey'=>'TR','türkiye'=>'TR','turkiye'=>'TR','оаэ'=>'AE','эмираты'=>'AE','объединенные арабские эмираты'=>'AE','uae'=>'AE','emirates'=>'AE','united arab emirates'=>'AE','саудовская аравия'=>'SA','saudi arabia'=>'SA','катар'=>'QA','qatar'=>'QA','бахрейн'=>'BH','bahrain'=>'BH','кувейт'=>'KW','kuwait'=>'KW','оман'=>'OM','oman'=>'OM','израиль'=>'IL','israel'=>'IL','иордания'=>'JO','jordan'=>'JO','ливан'=>'LB','lebanon'=>'LB','иран'=>'IR','iran'=>'IR','ирак'=>'IQ','iraq'=>'IQ','канада'=>'CA','canada'=>'CA','мексика'=>'MX','mexico'=>'MX','бразилия'=>'BR','brazil'=>'BR','аргентина'=>'AR','argentina'=>'AR','чили'=>'CL','chile'=>'CL','колумбия'=>'CO','colombia'=>'CO','перу'=>'PE','peru'=>'PE','венесуэла'=>'VE','venezuela'=>'VE','эквадор'=>'EC','ecuador'=>'EC','уругвай'=>'UY','uruguay'=>'UY','парагвай'=>'PY','paraguay'=>'PY','боливия'=>'BO','bolivia'=>'BO','панама'=>'PA','panama'=>'PA','коста рика'=>'CR','costa rica'=>'CR','куба'=>'CU','cuba'=>'CU','доминикана'=>'DO','доминиканская республика'=>'DO','dominican republic'=>'DO','пуэрто рико'=>'PR','puerto rico'=>'PR','ямайка'=>'JM','jamaica'=>'JM','япония'=>'JP','japan'=>'JP','корея'=>'KR','южная корея'=>'KR','korea'=>'KR','south korea'=>'KR','китай'=>'CN','china'=>'CN','гонконг'=>'HK','hong kong'=>'HK','hongkong'=>'HK','макао'=>'MO','macau'=>'MO','macao'=>'MO','тайвань'=>'TW','taiwan'=>'TW','сингапур'=>'SG','singapore'=>'SG','индия'=>'IN','india'=>'IN','индонезия'=>'ID','indonesia'=>'ID','вьетнам'=>'VN','vietnam'=>'VN','viet nam'=>'VN','таиланд'=>'TH','тайланд'=>'TH','thailand'=>'TH','малайзия'=>'MY','malaysia'=>'MY','филиппины'=>'PH','philippines'=>'PH','камбоджа'=>'KH','cambodia'=>'KH','лаос'=>'LA','laos'=>'LA','мьянма'=>'MM','myanmar'=>'MM','пакистан'=>'PK','pakistan'=>'PK','бангладеш'=>'BD','bangladesh'=>'BD','шри ланка'=>'LK','sri lanka'=>'LK','непал'=>'NP','nepal'=>'NP','австралия'=>'AU','australia'=>'AU','новая зеландия'=>'NZ','new zealand'=>'NZ','юар'=>'ZA','south africa'=>'ZA','египет'=>'EG','egypt'=>'EG','марокко'=>'MA','morocco'=>'MA','тунис'=>'TN','tunisia'=>'TN','алжир'=>'DZ','algeria'=>'DZ','нигерия'=>'NG','nigeria'=>'NG','кения'=>'KE','kenya'=>'KE','гана'=>'GH','ghana'=>'GH','эфиопия'=>'ET','ethiopia'=>'ET','танзания'=>'TZ','tanzania'=>'TZ','сербия'=>'RS','serbia'=>'RS','черногория'=>'ME','montenegro'=>'ME','босния'=>'BA','босния и герцеговина'=>'BA','bosnia'=>'BA','bosnia and herzegovina'=>'BA','македония'=>'MK','северная македония'=>'MK','macedonia'=>'MK','north macedonia'=>'MK','албания'=>'AL','albania'=>'AL','румыния'=>'RO','romania'=>'RO','болгария'=>'BG','bulgaria'=>'BG','венгрия'=>'HU','hungary'=>'HU','греция'=>'GR','greece'=>'GR','латвия'=>'LV','latvia'=>'LV','литва'=>'LT','lithuania'=>'LT','эстония'=>'EE','estonia'=>'EE','исландия'=>'IS','iceland'=>'IS','гренландия'=>'GL','greenland'=>'GL','молдова'=>'MD','молдавия'=>'MD','moldova'=>'MD','грузия'=>'GE','georgia'=>'GE','армения'=>'AM','armenia'=>'AM','азербайджан'=>'AZ','azerbaijan'=>'AZ','кипр'=>'CY','cyprus'=>'CY','мальта'=>'MT','malta'=>'MT','словакия'=>'SK','slovakia'=>'SK','словения'=>'SI','slovenia'=>'SI','хорватия'=>'HR','croatia'=>'HR'];
    return $m;
}

function squadconf_flag_codes() {
    return ['AE','AM','AR','AT','AU','AZ','BE','BG','BR','BY','CA','CH','CL','CN','CY','CZ','DE','DK','EE','EG','ES','EU','FI','FR','GB','GE','GR','HK','HR','HU','ID','IE','IL','IN','IS','IT','JP','KR','KZ','LT','LU','LV','MD','MT','MX','MY','NL','NO','NZ','PL','PT','RO','RS','RU','SE','SG','SI','SK','TH','TR','TW','UA','US','VN','ZA'];
}

function squadconf_flag_iso($s) {
    $s = trim((string) $s);
    if ($s === '') return '';
    $m = squadconf_country_map();
    $low = str_replace('ё', 'е', mb_strtolower($s, 'UTF-8'));
    foreach ([$low, str_replace('-', ' ', $low)] as $v) {
        $w = preg_split('/\s+/u', trim($v));
        for ($n = 3; $n >= 1; $n--) {
            if (count($w) < $n) continue;
            $k = implode(' ', array_slice($w, 0, $n));
            if (isset($m[$k])) return $m[$k];
        }
    }
    $codes = array_flip(squadconf_flag_codes());
    foreach ([$s, str_replace('-', ' ', $s)] as $v) {
        $w = preg_split('/\s+/u', trim($v));
        $c = (string) ($w[0] ?? '');
        if ($c === 'UK') $c = 'GB';
        if (preg_match('/^[A-Z]{2}$/', $c) && isset($codes[$c])) return $c;
    }
    return '';
}

function squadconf_flag_label($s) {
    $t = trim((string) $s);
    if ($t === '' || preg_match('/^[\x{1F1E6}-\x{1F1FF}]{2}/u', $t)) return $t;
    $iso = squadconf_flag_iso($t);
    if ($iso === '') return $t;
    return mb_chr(0x1F1E6 + ord($iso[0]) - 65, 'UTF-8') . mb_chr(0x1F1E6 + ord($iso[1]) - 65, 'UTF-8') . ' ' . $t;
}
