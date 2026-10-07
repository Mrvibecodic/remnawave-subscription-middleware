<?php

// Отчёты клиентов Clod Clash о качестве узлов. Приходят только по защищённому каналу
// (POST c1 с op=rep) и только при включённом тумблере. В отчёте — часовые ячейки по
// сетям устройства: пинги узлов корзинами, трафик по узлам, итоги проверки 16–20 и
// внешний IP, с которого делались замеры. Здесь отчёт раскладывается по сводкам:
//   rep_hour   — час × узел × страна × AS × тип сети × платформа, без пользователей и IP;
//   rep_ip_day — сутки × IP × узел × тип сети, для карты и таблицы провайдеров;
//   rep_state  — последнее состояние «подписка × устройство × сеть × узел»;
//   rep_net_ip — адреса каждой сети устройства и сколько часов с каждого мерили:
//                по ним выбирается регион устройства, а не по последнему адресу;
//   rep_node   — справочник узлов (тип + адрес + порт), rep_dev — кто и когда слал
//                и до какого часа принято: клиент, не дождавшийся ответа, шлёт те же
//                часы снова, и второй раз они не считаются.
// Сырые отчёты не хранятся.

const REP_VERSION      = 1;
const REP_MIN_GAP      = 18000;
const REP_MAX_AGE      = 8 * 86400;
const REP_MAX_NODES    = 1000;
const REP_MAX_NETWORKS = 32;
const REP_MAX_HOURS    = 512;
// Ячеек «час × узел» в одном отчёте: честный отчёт за двое суток при тысяче
// узлов — десятки тысяч; больше — собранный нарочно, чтобы завалить базу
// upsert'ами в одной транзакции.
const REP_MAX_CELLS    = 100000;
// Больше устройств у одной подписки не бывает: иначе подменой метки установки
// можно было бы обойти паузу между отчётами и грузить прослойку без конца.
const REP_MAX_DEVICES  = 50;
const REP_BUCKETS      = 6;
const REP_KINDS        = ['wifi', 'mobile', 'wired', 'other'];
const REP_VERDICTS     = ['ok' => 'fok', 'frozen' => 'ffr', 'dead' => 'fdd'];
const REP_HIST         = 48;

function rep_enabled() { return chan_enabled() && setting('rep_enabled', '0') === '1'; }

function rep_keep_days() { return max(7, min(365, (int) (setting('rep_keep_days', '90') ?: 90))); }

function rep_sum_cols() {
    return ['devs', 'pn', 'pf', 'b0', 'b1', 'b2', 'b3', 'b4', 'b5', 'up', 'down', 'mins', 'fok', 'ffr', 'fdd'];
}

function rep_ddl($drv) {
    $my   = $drv === 'mysql';
    $id   = $my ? 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'id INTEGER PRIMARY KEY AUTOINCREMENT';
    $int  = $my ? 'INT NOT NULL DEFAULT 0' : 'INTEGER NOT NULL DEFAULT 0';
    $big  = $my ? 'BIGINT UNSIGNED NOT NULL DEFAULT 0' : 'INTEGER NOT NULL DEFAULT 0';
    $a    = static fn($n) => $my ? "VARCHAR($n) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT ''" : "TEXT NOT NULL DEFAULT ''";
    $u    = static fn($n) => $my ? "VARCHAR($n) NOT NULL DEFAULT ''" : "TEXT NOT NULL DEFAULT ''";
    $tail = $my ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
    $sums = implode(', ', array_map(static fn($c) => "$c $big", rep_sum_cols()));
    $idx  = [
        'rep_hour'   => ['idx_rep_hour_h' => 'h'],
        'rep_ip_day' => ['idx_rep_ip_day_d' => 'd'],
        'rep_state'  => ['idx_rep_state_seen' => 'last_seen', 'idx_rep_state_nkey' => 'nkey'],
        'rep_dev'    => ['idx_rep_dev_last' => 'last_report'],
        'rep_net_ip' => ['idx_rep_net_ip_last' => 'last_h'],
    ];
    $keys = static function ($t) use ($my, $idx) {
        if (!$my || empty($idx[$t])) return '';
        $out = '';
        foreach ($idx[$t] as $name => $col) $out .= ", KEY $name ($col)";
        return $out;
    };

    $out = [
        "CREATE TABLE IF NOT EXISTS rep_node ($id, nkey {$a(16)}, type {$a(32)}, server {$u(255)}, port $int,
            name {$u(191)}, first_seen $int, last_seen $int, UNIQUE (nkey))$tail",
        "CREATE TABLE IF NOT EXISTS rep_dev ($id, short_uuid {$a(64)}, hwid {$u(128)}, plat {$a(16)}, client {$a(32)},
            first_seen $int, last_report $int, last_h $int, reports $int, model {$u(64)}, os {$u(48)},
            UNIQUE (short_uuid, hwid){$keys('rep_dev')})$tail",
        "CREATE TABLE IF NOT EXISTS rep_hour ($id, h $int, nkey {$a(16)}, cc {$a(2)}, asn $int, kind {$a(8)},
            plat {$a(16)}, $sums, UNIQUE (h, nkey, cc, asn, kind, plat){$keys('rep_hour')})$tail",
        "CREATE TABLE IF NOT EXISTS rep_ip_day ($id, d $int, ip {$a(45)}, nkey {$a(16)}, kind {$a(8)}, cc {$a(2)},
            asn $int, org {$u(128)}, $sums, UNIQUE (d, ip, nkey, kind){$keys('rep_ip_day')})$tail",
        "CREATE TABLE IF NOT EXISTS rep_state ($id, short_uuid {$a(64)}, hwid {$u(128)}, net {$a(16)}, nkey {$a(16)},
            kind {$a(8)}, ip4 {$a(45)}, ip6 {$a(45)}, cc {$a(2)}, asn $int, org {$u(128)}, verdict {$a(8)},
            vstatus $int, vat $int, pn $big, pf $big, pmed $int, up $big, down $big, last_seen $int,
            loc {$a(24)}, hist {$a(48)}, hist_h $int, sub {$u(64)},
            UNIQUE (short_uuid, hwid, net, nkey){$keys('rep_state')})$tail",
        "CREATE TABLE IF NOT EXISTS rep_net_ip ($id, short_uuid {$a(64)}, hwid {$u(128)}, net {$a(16)}, ip {$a(45)},
            kind {$a(8)}, cc {$a(2)}, asn $int, org {$u(128)}, loc {$a(24)}, sub {$u(64)}, hours $int, first_h $int, last_h $int,
            UNIQUE (short_uuid, hwid, net, ip){$keys('rep_net_ip')})$tail",
    ];
    if (!$my) {
        foreach ($idx as $t => $list) {
            foreach ($list as $name => $col) $out[] = "CREATE INDEX IF NOT EXISTS $name ON $t ($col)";
        }
    }

    return $out;
}

function rep_ensure() {
    static $done = null;
    if ($done !== null) return $done;
    if (!($p = db())) return $done = false;
    try {
        foreach (rep_ddl(db_driver()) as $sql) $p->exec($sql);
    } catch (Throwable $e) {
        error_log('submw rep tables: ' . $e->getMessage());
        return $done = false;
    }
    rep_add_cols($p);

    return $done = true;
}

function rep_add_cols(PDO $p) {
    $my   = db_driver() === 'mysql';
    $a    = static fn($n) => $my ? "VARCHAR($n) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT ''" : "TEXT NOT NULL DEFAULT ''";
    $u    = static fn($n) => $my ? "VARCHAR($n) NOT NULL DEFAULT ''" : "TEXT NOT NULL DEFAULT ''";
    $int  = $my ? 'INT NOT NULL DEFAULT 0' : 'INTEGER NOT NULL DEFAULT 0';
    $plan = [
        'rep_dev'   => ['model' => $u(64), 'os' => $u(48)],
        'rep_state' => ['loc' => $a(24), 'hist' => $a(48), 'hist_h' => $int, 'sub' => $u(64), 'hc' => $a(2000)],
    ];
    $ok = true;
    foreach ($plan as $t => $cols) {
        if (db_has_cols($p, $t, array_keys($cols))) continue;
        foreach ($cols as $c => $type) {
            if (db_has_cols($p, $t, [$c])) continue;
            try { $p->exec("ALTER TABLE $t ADD COLUMN $c $type"); }
            catch (Throwable $e) { $why = $e->getMessage(); }
        }
        if (!db_has_cols($p, $t, array_keys($cols))) {
            $ok = false;
            error_log('submw rep columns ' . $t . ': ' . ($why ?? 'не добавились'));
        }
    }
    $GLOBALS['submw_rep_cols'] = $ok;
}

function rep_new_cols() {
    return rep_ensure() && ($GLOBALS['submw_rep_cols'] ?? true);
}

function rep_sel(array $cols, $prefix = '') {
    $fb = ['loc' => "''", 'hist' => "''", 'hist_h' => '0', 'model' => "''", 'os' => "''", 'sub' => "''", 'hc' => "''"];
    $new = rep_new_cols();
    $out = [];
    foreach ($cols as $c) $out[] = !$new && isset($fb[$c]) ? $fb[$c] . ' AS ' . $c : $prefix . $c;

    return implode(', ', $out);
}

function rep_hist_char($pn, $pf, array $b) {
    if ($pn <= 0) return '';
    if ($pf >= $pn) return 'x';
    $m = rep_median_bucket($b);

    return $m < 0 ? '' : (string) $m;
}

function rep_hist_merge($old, $oldH, array $hours, $atLeast = 0) {
    $old    = preg_replace('~[^0-5x.]~', '.', is_string($old) ? $old : '');
    $oldH   = (int) $oldH;
    $anchor = max($oldH, (int) $atLeast);
    foreach ($hours as $h => $ch) $anchor = max($anchor, (int) $h);
    if ($anchor <= 0) return ['', 0];
    $out = array_fill(0, REP_HIST, '.');
    $len = strlen($old);
    if ($oldH > 0) {
        for ($i = 0; $i < $len; $i++) {
            $j = REP_HIST - 1 - intdiv($anchor - ($oldH - ($len - 1 - $i) * 3600), 3600);
            if ($j >= 0 && $j < REP_HIST) $out[$j] = $old[$i];
        }
    }
    foreach ($hours as $h => $ch) {
        $j = REP_HIST - 1 - intdiv($anchor - (int) $h, 3600);
        if ($j >= 0 && $j < REP_HIST && $ch !== '') $out[$j] = $ch;
    }

    return [implode('', $out), $anchor];
}

// Счётчики пингов по часам за те же 48 часов, что и полоса: «pn.pf.b0…b5» в base36
// через запятую, пустая ячейка — час без замеров. Сумма по ним — пинги, неудачи и
// медиана в rep_state, а не срез последнего отчёта.
function rep_hc_parse($hc) {
    $out = [];
    foreach (explode(',', is_string($hc) ? $hc : '') as $i => $cell) {
        if ($cell === '' || !preg_match('~^[0-9a-z]+(\.[0-9a-z]+){0,7}$~', $cell)) { $out[$i] = null; continue; }
        $v = array_map(static fn($x) => min((int) base_convert($x, 36, 10), 1000000), explode('.', $cell));
        $out[$i] = array_pad($v, 2 + REP_BUCKETS, 0);
    }

    return $out;
}

function rep_hc_cell(array $v) {
    while (count($v) > 2 && end($v) === 0) array_pop($v);

    return implode('.', array_map(static fn($x) => base_convert((string) max(0, (int) $x), 10, 36), $v));
}

function rep_hc_merge($old, $oldH, array $hours, $anchor) {
    $oldH  = (int) $oldH;
    $slots = array_fill(0, REP_HIST, null);
    $prev  = rep_hc_parse($old);
    $len   = count($prev);
    if ($oldH > 0 && $old !== '') {
        foreach ($prev as $i => $v) {
            if ($v === null) continue;
            $j = REP_HIST - 1 - intdiv($anchor - ($oldH - ($len - 1 - $i) * 3600), 3600);
            if ($j >= 0 && $j < REP_HIST) $slots[$j] = $v;
        }
    }
    foreach ($hours as $h => $x) {
        $j = REP_HIST - 1 - intdiv($anchor - (int) $h, 3600);
        if ($j < 0 || $j >= REP_HIST) continue;
        $slots[$j] = array_merge([min($x['pn'], 1000000), min($x['pf'], $x['pn'], 1000000)], array_map(static fn($n) => min($n, 1000000), $x['b']));
    }
    $pn = 0;
    $pf = 0;
    $b  = array_fill(0, REP_BUCKETS, 0);
    $first = null;
    $cells = [];
    foreach ($slots as $j => $v) {
        if ($v === null) { $cells[] = ''; continue; }
        $first = $first ?? $j;
        $pn += $v[0];
        $pf += $v[1];
        for ($i = 0; $i < REP_BUCKETS; $i++) $b[$i] += $v[2 + $i];
        $cells[] = rep_hc_cell($v);
    }

    return [$first === null ? '' : implode(',', array_slice($cells, $first)), $pn, min($pf, $pn), $b];
}

function rep_hist_old(PDO $p, $short, $hwid) {
    $out = [];
    try {
        $st = $p->prepare('SELECT net, nkey, hist, hist_h, hc FROM rep_state WHERE short_uuid = ? AND hwid = ?');
        $st->execute([(string) $short, (string) $hwid]);
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) $out[$r['net'] . '|' . $r['nkey']] = [(string) $r['hist'], (int) $r['hist_h'], (string) $r['hc']];
    } catch (Throwable $e) {}

    return $out;
}

// INSERT с обновлением при совпадении ключа: $add складываются, $set заменяются.
function rep_upsert(PDO $p, $table, array $row, array $keys, array $add, array $set = []) {
    static $cache = [];
    $cols = array_keys($row);
    $sig  = $table . '|' . implode(',', $cols) . '|' . implode(',', $add) . '|' . implode(',', $set);
    if (!isset($cache[$sig])) {
        $ph = implode(', ', array_fill(0, count($cols), '?'));
        if (db_driver() === 'mysql') {
            $up = array_merge(
                array_map(static fn($c) => "$c = $c + VALUES($c)", $add),
                array_map(static fn($c) => "$c = VALUES($c)", $set)
            );
            $sql = "INSERT INTO $table (" . implode(', ', $cols) . ") VALUES ($ph) ON DUPLICATE KEY UPDATE " . implode(', ', $up);
        } else {
            $up = array_merge(
                array_map(static fn($c) => "$c = $table.$c + excluded.$c", $add),
                array_map(static fn($c) => "$c = excluded.$c", $set)
            );
            $sql = "INSERT INTO $table (" . implode(', ', $cols) . ") VALUES ($ph) ON CONFLICT(" . implode(', ', $keys) . ") DO UPDATE SET " . implode(', ', $up);
        }
        $cache[$sig] = $p->prepare($sql);
    }
    $cache[$sig]->execute(array_values($row));
}

function rep_str($v, $max) {
    if (!is_string($v)) return '';
    $v = trim(strtr($v, ["\r" => ' ', "\n" => ' ', "\0" => '']));
    if (!mb_check_encoding($v, 'UTF-8')) return '';

    return mb_substr($v, 0, $max);
}

function rep_uint($v, $max) {
    if (!is_int($v) && !(is_float($v) && floor($v) === $v)) return 0;
    $v = (int) $v;

    return $v < 0 ? 0 : min($v, $max);
}

function rep_ip($v, $family) {
    if (!is_string($v) || $v === '') return '';
    $flags = ($family === 4 ? FILTER_FLAG_IPV4 : FILTER_FLAG_IPV6) | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
    if (filter_var($v, FILTER_VALIDATE_IP, $flags) === false) return '';
    $packed = @inet_pton($v);

    return $packed === false ? '' : (string) inet_ntop($packed);
}

function rep_node_key($type, $server, $port) {
    return substr(hash('sha256', $type . '|' . strtolower($server) . '|' . $port), 0, 12);
}

// Индекс корзины, в которую попадает середина удачных замеров; -1 — замеров нет.
function rep_median_bucket(array $b) {
    $total = array_sum($b);
    if ($total <= 0) return -1;
    $run = 0;
    foreach ($b as $i => $n) {
        $run += $n;
        if ($run * 2 >= $total) return $i;
    }

    return count($b) - 1;
}

// Чьё это устройство: x-hwid из конверта, а если клиент его не шлёт (опознание
// устройства выключено) — случайная метка установки из самого отчёта.
function rep_device($hwid, $data) {
    $hwid = rep_str((string) $hwid, 128);
    if ($hwid !== '') return $hwid;
    $dev = is_array($data) && is_string($data['dev'] ?? null) ? $data['dev'] : '';

    return preg_match('~^[0-9a-f]{16,32}$~', $dev) ? '~' . $dev : '';
}

function rep_forget($short) {
    $short = trim((string) $short);
    if ($short === '' || !rep_ensure() || !($p = db())) return;
    foreach (['rep_state', 'rep_dev'] as $t) {
        try { $p->prepare("DELETE FROM $t WHERE short_uuid = ?")->execute([$short]); }
        catch (Throwable $e) { error_log('submw rep forget: ' . $e->getMessage()); }
    }
}

function rep_dev_row($short, $hwid) {
    if (!rep_ensure() || !($p = db())) return null;
    try {
        $st = $p->prepare('SELECT last_report, last_h FROM rep_dev WHERE short_uuid = ? AND hwid = ?');
        $st->execute([(string) $short, (string) $hwid]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return null; }

    return is_array($row) ? $row : null;
}

function rep_too_soon($short, $hwid) {
    $row = rep_dev_row($short, $hwid);
    if ($row === null) return rep_devices_full($short);
    $last = (int) ($row['last_report'] ?? 0);

    return $last > 0 && time() - $last < REP_MIN_GAP;
}

// Новое устройство подписки, у которой их уже REP_MAX_DEVICES, не принимается.
function rep_devices_full($short) {
    if (!rep_ensure() || !($p = db())) return false;
    try {
        $st = $p->prepare('SELECT COUNT(*) FROM rep_dev WHERE short_uuid = ?');
        $st->execute([(string) $short]);

        return (int) $st->fetchColumn() >= REP_MAX_DEVICES;
    } catch (Throwable $e) { return false; }
}

// Разбирает отчёт и раскладывает по сводкам. $hwid — уже ключ устройства из rep_device().
// false и причина в $why — отчёт не принят целиком: проверка идёт до первой записи,
// запись — одной транзакцией. Принимаются только закрытые часы не старше 8 суток
// (с запасом 10 минут на разбег часов устройства).
function rep_ingest($short, $hwid, $data, &$why = '', $now = null, array $meta = []) {
    $why = '';
    $now = $now ?? time();
    if (!is_array($data) || ($data['v'] ?? 0) !== REP_VERSION) { $why = 'version'; return false; }
    if (!rep_ensure() || !($p = db())) { $why = 'db'; return false; }

    $short = rep_str((string) $short, 64);
    $hwid  = (string) $hwid;
    $plat  = in_array($data['platform'] ?? '', ['pc', 'android'], true) ? $data['platform'] : 'other';
    $cli   = preg_replace('~[^0-9A-Za-z.+_-]~', '', rep_str($data['client'] ?? '', 32));
    $model = rep_str($meta['model'] ?? '', 64);
    $osv   = rep_str($meta['osv'] ?? '', 24);
    $os    = rep_str(trim(rep_str($meta['os'] ?? '', 24) . ' ' . $osv), 48);

    $nodes = [];
    foreach ((is_array($data['nodes'] ?? null) ? $data['nodes'] : []) as $tok => $n) {
        if (count($nodes) >= REP_MAX_NODES) break;
        if (!is_string($tok) || !preg_match('~^[A-Za-z0-9_-]{1,16}$~', $tok) || !is_array($n)) continue;
        $type   = strtolower(rep_str($n['type'] ?? '', 32));
        $server = strtolower(rep_str($n['server'] ?? '', 255));
        $port   = rep_uint($n['port'] ?? 0, 65535);
        if ($type === '' || $server === '' || $port < 1 || !preg_match('~^[a-z0-9-]+$~', $type)) continue;
        $nodes[$tok] = [
            'nkey'   => rep_node_key($type, $server, $port),
            'type'   => $type,
            'server' => $server,
            'port'   => $port,
            'name'   => rep_str($n['name'] ?? '', 191),
        ];
    }

    // Часы не новее последнего принятого от этого устройства уже посчитаны.
    $taken  = (int) (rep_dev_row($short, $hwid)['last_h'] ?? 0);
    $last_h = $taken;
    $zero  = array_fill_keys(rep_sum_cols(), 0);
    $hours = [];
    $days  = [];
    $state = [];
    $used  = [];
    $cells_total = 0;
    $nips  = [];
    $nets  = is_array($data['networks'] ?? null) ? $data['networks'] : [];
    if (count($nets) > REP_MAX_NETWORKS) $nets = array_slice($nets, 0, REP_MAX_NETWORKS, true);

    foreach ($nets as $net => $nd) {
        if (!is_string($net) || !preg_match('~^[0-9a-f]{16}$~', $net) || !is_array($nd)) continue;
        $kind = in_array($nd['kind'] ?? '', REP_KINDS, true) ? $nd['kind'] : 'other';
        $list = is_array($nd['hours'] ?? null) ? array_slice(array_values($nd['hours']), 0, REP_MAX_HOURS) : [];

        foreach ($list as $hr) {
            if (!is_array($hr)) continue;
            $h = rep_uint($hr['h'] ?? 0, PHP_INT_MAX);
            if ($h % 3600 !== 0 || $h + 3600 > $now + 600 || $h < $now - REP_MAX_AGE || $h <= $taken) continue;
            $last_h = max($last_h, $h);

            $ip4 = rep_ip($hr['ip4'] ?? '', 4);
            $ip6 = rep_ip($hr['ip6'] ?? '', 6);
            $ip  = $ip4 !== '' ? $ip4 : $ip6;
            $geo = $ip !== '' ? geoip_lookup($ip) : ['cc' => '', 'asn' => 0, 'org' => '', 'loc' => '', 'sub' => ''];
            $d   = intdiv($h, 86400) * 86400;
            if ($ip !== '') {
                $ik = "$net|$ip";
                if (!isset($nips[$ik])) {
                    $nips[$ik] = ['net' => $net, 'ip' => $ip, 'kind' => $kind, 'cc' => (string) ($geo['cc'] ?? ''), 'asn' => (int) ($geo['asn'] ?? 0), 'org' => (string) ($geo['org'] ?? ''),
                                  'loc' => (string) ($geo['loc'] ?? ''), 'sub' => (string) ($geo['sub'] ?? ''), 'hrs' => [], 'first_h' => $h, 'last_h' => $h];
                }
                $nips[$ik]['hrs'][$h] = true;
                $nips[$ik]['first_h'] = min($nips[$ik]['first_h'], $h);
                $nips[$ik]['last_h'] = max($nips[$ik]['last_h'], $h);
            }

            $cells = [];
            foreach ((is_array($hr['ping'] ?? null) ? $hr['ping'] : []) as $tok => $v) {
                if (!isset($nodes[$tok]) || !is_array($v)) continue;
                $c = &$cells[$tok];
                $c = $c ?? $zero;
                $c['pn'] += rep_uint($v['n'] ?? 0, 1000000);
                $c['pf'] += rep_uint($v['fail'] ?? 0, 1000000);
                $b = is_array($v['b'] ?? null) ? array_values($v['b']) : [];
                for ($i = 0; $i < REP_BUCKETS; $i++) $c['b' . $i] += rep_uint($b[$i] ?? 0, 1000000);
                unset($c);
            }
            foreach ((is_array($hr['use'] ?? null) ? $hr['use'] : []) as $tok => $v) {
                if (!isset($nodes[$tok]) || !is_array($v)) continue;
                $c = &$cells[$tok];
                $c = $c ?? $zero;
                $c['up']   += rep_uint($v['up'] ?? 0, 10 ** 13);
                $c['down'] += rep_uint($v['down'] ?? 0, 10 ** 13);
                $c['mins'] += rep_uint($v['min'] ?? 0, 60);
                unset($c);
            }
            $verdicts = [];
            foreach ((is_array($hr['freeze'] ?? null) ? $hr['freeze'] : []) as $tok => $v) {
                if (!isset($nodes[$tok]) || !is_array($v)) continue;
                $col = REP_VERDICTS[$v['verdict'] ?? ''] ?? null;
                if ($col === null) continue;
                $c = &$cells[$tok];
                $c = $c ?? $zero;
                $c[$col] += 1;
                unset($c);
                $at = rep_uint($v['at'] ?? 0, PHP_INT_MAX);
                $verdicts[$tok] = [(string) $v['verdict'], rep_uint($v['status'] ?? 0, 999), $at >= $h && $at < $h + 3600 ? $at : $h];
            }

            foreach ($cells as $tok => $c) if ($cells[$tok]['pf'] > $cells[$tok]['pn']) $cells[$tok]['pf'] = $cells[$tok]['pn'];
            $cells_total += count($cells);
            if ($cells_total > REP_MAX_CELLS) { $why = 'size'; return false; }
            foreach ($cells as $tok => $c) {
                $nk = $nodes[$tok]['nkey'];
                $used[$tok] = true;

                // Один час одной сети может прийти несколькими записями — адрес
                // сменился внутри часа. Устройство в строке считается один раз.
                $hk = "$h|$nk|{$geo['cc']}|{$geo['asn']}|$kind|$plat";
                if (!isset($hours[$hk])) {
                    $hours[$hk] = ['h' => $h, 'nkey' => $nk, 'cc' => $geo['cc'], 'asn' => $geo['asn'], 'kind' => $kind, 'plat' => $plat] + $zero;
                    $hours[$hk]['devs'] = 1;
                }
                foreach ($c as $k => $n) $hours[$hk][$k] += $n;

                if ($ip !== '') {
                    $dk = "$d|$ip|$nk|$kind";
                    if (!isset($days[$dk])) {
                        $days[$dk] = ['d' => $d, 'ip' => $ip, 'nkey' => $nk, 'kind' => $kind, 'cc' => $geo['cc'], 'asn' => $geo['asn'], 'org' => $geo['org']] + $zero;
                        $days[$dk]['devs'] = 1;
                    }
                    foreach ($c as $k => $n) $days[$dk][$k] += $n;
                }

                $sk = "$net|$nk";
                if (!isset($state[$sk])) {
                    $state[$sk] = ['net' => $net, 'nkey' => $nk, 'kind' => $kind, 'ip4' => '', 'ip6' => '', 'cc' => '', 'asn' => 0, 'org' => '', 'loc' => '', 'sub' => '',
                                   'h' => -1, 'pn' => 0, 'pf' => 0, 'b' => array_fill(0, REP_BUCKETS, 0), 'up' => 0, 'down' => 0, 'v' => null, 'hb' => []];
                }
                $s = &$state[$sk];
                if ($c['pn'] > 0) {
                    $hb = &$s['hb'][$h];
                    $hb = $hb ?? ['pn' => 0, 'pf' => 0, 'b' => array_fill(0, REP_BUCKETS, 0)];
                    $hb['pn'] += $c['pn'];
                    $hb['pf'] += $c['pf'];
                    for ($i = 0; $i < REP_BUCKETS; $i++) $hb['b'][$i] += $c['b' . $i];
                    unset($hb);
                }
                $s['pn']   += $c['pn'];
                $s['pf']   += $c['pf'];
                $s['up']   += $c['up'];
                $s['down'] += $c['down'];
                for ($i = 0; $i < REP_BUCKETS; $i++) $s['b'][$i] += $c['b' . $i];
                // Записи часа идут по порядку: у позднего часа и у поздней записи
                // того же часа — нынешний адрес.
                if ($h >= $s['h']) {
                    $s['h'] = $h;
                    $s['kind'] = $kind;
                    if ($ip !== '') {
                        $s['ip4'] = $ip4;
                        $s['ip6'] = $ip6;
                        $s['cc']  = $geo['cc'];
                        $s['asn'] = $geo['asn'];
                        $s['org'] = $geo['org'];
                        $s['loc'] = (string) ($geo['loc'] ?? '');
                        $s['sub'] = (string) ($geo['sub'] ?? '');
                    }
                }
                if (isset($verdicts[$tok]) && ($s['v'] === null || $verdicts[$tok][2] >= $s['v'][2])) $s['v'] = $verdicts[$tok];
                unset($s);
            }
        }
    }

    $sums = rep_sum_cols();
    $nc   = rep_new_cols();
    $olds = $state && $nc ? rep_hist_old($p, $short, $hwid) : [];
    try {
        $p->beginTransaction();
        foreach ($nodes as $tok => $n) {
            if (!isset($used[$tok])) continue;
            rep_upsert($p, 'rep_node', $n + ['first_seen' => $now, 'last_seen' => $now], ['nkey'], [], ['type', 'server', 'port', 'name', 'last_seen']);
        }
        foreach ($hours as $row) rep_upsert($p, 'rep_hour', $row, ['h', 'nkey', 'cc', 'asn', 'kind', 'plat'], $sums);
        foreach ($days as $row) rep_upsert($p, 'rep_ip_day', $row, ['d', 'ip', 'nkey', 'kind'], $sums, ['cc', 'asn', 'org']);
        foreach ($nips as $x) {
            rep_upsert($p, 'rep_net_ip', [
                'short_uuid' => $short, 'hwid' => $hwid, 'net' => $x['net'], 'ip' => $x['ip'], 'kind' => $x['kind'], 'cc' => $x['cc'], 'asn' => $x['asn'], 'org' => $x['org'],
                'loc' => $x['loc'], 'sub' => $x['sub'], 'hours' => count($x['hrs']), 'first_h' => $x['first_h'], 'last_h' => $x['last_h'],
            ], ['short_uuid', 'hwid', 'net', 'ip'], ['hours'], ['kind', 'cc', 'asn', 'org', 'loc', 'sub', 'last_h']);
        }
        foreach ($state as $s) {
            $row = [
                'short_uuid' => $short, 'hwid' => $hwid, 'net' => $s['net'], 'nkey' => $s['nkey'], 'kind' => $s['kind'],
                'ip4' => $s['ip4'], 'ip6' => $s['ip6'], 'cc' => $s['cc'], 'asn' => $s['asn'], 'org' => $s['org'],
                'pn' => $s['pn'], 'pf' => $s['pf'], 'pmed' => rep_median_bucket($s['b']),
                'up' => $s['up'], 'down' => $s['down'], 'last_seen' => $now,
            ];
            $set = ['kind', 'pn', 'pf', 'pmed', 'up', 'down', 'last_seen'];
            if ($nc) {
                $hc = [];
                foreach ($s['hb'] as $hh => $x) $hc[$hh] = rep_hist_char($x['pn'], $x['pf'], $x['b']);
                $old = $olds[$s['net'] . '|' . $s['nkey']] ?? ['', 0, ''];
                [$row['hist'], $row['hist_h']] = rep_hist_merge($old[0], $old[1], $hc, $s['h']);
                [$row['hc'], $wpn, $wpf, $wb] = rep_hc_merge($old[2], $old[1], $s['hb'], $row['hist_h']);
                if ($row['hc'] !== '') { $row['pn'] = $wpn; $row['pf'] = $wpf; $row['pmed'] = rep_median_bucket($wb); }
                $set = array_merge($set, ['hist', 'hist_h', 'hc']);
            }
            if ($s['ip4'] !== '' || $s['ip6'] !== '') {
                $set = array_merge($set, ['ip4', 'ip6', 'cc', 'asn', 'org']);
                if ($nc) { $row['loc'] = $s['loc']; $row['sub'] = $s['sub']; $set[] = 'loc'; $set[] = 'sub'; }
            }
            if ($s['v'] !== null) {
                $row += ['verdict' => $s['v'][0], 'vstatus' => $s['v'][1], 'vat' => $s['v'][2]];
                $set  = array_merge($set, ['verdict', 'vstatus', 'vat']);
            }
            rep_upsert($p, 'rep_state', $row, ['short_uuid', 'hwid', 'net', 'nkey'], [], $set);
        }
        $drow = ['short_uuid' => $short, 'hwid' => $hwid, 'plat' => $plat, 'client' => $cli, 'first_seen' => $now, 'last_report' => $now, 'last_h' => $last_h, 'reports' => 1];
        $dset = ['plat', 'client', 'last_report', 'last_h'];
        if ($nc && $model !== '') { $drow['model'] = $model; $dset[] = 'model'; }
        if ($nc && $os !== '') { $drow['os'] = $os; $dset[] = 'os'; }
        rep_upsert($p, 'rep_dev', $drow, ['short_uuid', 'hwid'], ['reports'], $dset);
        $p->commit();
        rep_bump();
    } catch (Throwable $e) {
        if ($p->inTransaction()) $p->rollBack();
        error_log('submw rep ingest: ' . $e->getMessage());
        $why = 'db';
        return false;
    }

    return true;
}

// Счётчик принятых отчётов для конверта в шапке: всего принято и сколько было,
// когда «Статистику» открывали в последний раз.
function rep_bump() {
    if (!($p = db())) return;
    try {
        $p->exec(db_driver() === 'mysql'
            ? "INSERT INTO settings (k, v) VALUES ('rep_total', '1') ON DUPLICATE KEY UPDATE v = CAST(CAST(v AS UNSIGNED) + 1 AS CHAR)"
            : "INSERT INTO settings (k, v) VALUES ('rep_total', '1') ON CONFLICT(k) DO UPDATE SET v = CAST(CAST(v AS INTEGER) + 1 AS TEXT), updated_at = CURRENT_TIMESTAMP");
    } catch (Throwable $e) { error_log('submw rep count: ' . $e->getMessage()); }
}

function rep_unseen() {
    return max(0, (int) setting('rep_total', '0') - (int) setting('rep_seen', '0'));
}

function rep_unseen_tip($n) {
    $n = (int) $n;
    if ($n <= 0) return rep_enabled() ? 'Статистика Clod Clash — новых отчётов нет' : 'Статистика Clod Clash — приём отчётов выключен';
    $d = $n % 10;
    $h = $n % 100;
    if ($d === 1 && $h !== 11) return 'Появился ' . $n . ' новый отчёт — открыть статистику';
    if ($d >= 2 && $d <= 4 && ($h < 12 || $h > 14)) return 'Появилось ' . $n . ' новых отчёта — открыть статистику';

    return 'Появилось ' . $n . ' новых отчётов — открыть статистику';
}

function rep_mark_seen() {
    $t = (string) (int) setting('rep_total', '0');
    if ((string) setting('rep_seen', '0') !== $t) set_setting('rep_seen', $t);
}

function rep_purge($now = null) {
    if (!rep_ensure() || !($p = db())) return;
    $cut = ($now ?? time()) - rep_keep_days() * 86400;
    $plan = [['rep_hour', 'h'], ['rep_ip_day', 'd'], ['rep_state', 'last_seen'], ['rep_net_ip', 'last_h'], ['rep_node', 'last_seen'], ['rep_dev', 'last_report']];
    foreach ($plan as [$t, $col]) {
        try { $p->prepare("DELETE FROM $t WHERE $col < ?")->execute([$cut]); }
        catch (Throwable $e) { error_log('submw rep purge ' . $t . ': ' . $e->getMessage()); }
    }
}

function rep_stats($now = null) {
    $now = $now ?? time();
    $out = ['day' => 0, 'week' => 0, 'last' => 0];
    if (!rep_ensure() || !($p = db())) return $out;
    try {
        $st = $p->prepare('SELECT COUNT(*) FROM rep_dev WHERE last_report >= ?');
        $st->execute([$now - 86400]);
        $out['day'] = (int) $st->fetchColumn();
        $st->execute([$now - 7 * 86400]);
        $out['week'] = (int) $st->fetchColumn();
        $out['last'] = (int) $p->query('SELECT COALESCE(MAX(last_report), 0) FROM rep_dev')->fetchColumn();
    } catch (Throwable $e) {}

    return $out;
}
