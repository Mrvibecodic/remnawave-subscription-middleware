<?php

// Страна, регион, город и автономная система по IP для отчётов и сессий. По умолчанию — API
// ipinfo.io с кэшем по подсетям (ниже, geonet_*). Второй вариант — файлы MaxMind DB
// (.mmdb) с зеркала ip-location-db: страна и города — GeoLite2 (дважды в неделю, города
// двумя файлами: IPv4 и IPv6), AS — origin-asn (PDDL, из RIR/BGP, ежедневно); качаются раз
// в неделю. По выбору и как запасной источник — DB-IP Lite раз в месяц. У зеркала схема
// плоская (country_code, state1, latitude), у DB-IP/MaxMind — вложенная; читаются обе.
// Читатель формата свой, без composer.

const GEOIP_MAX_DEPTH = 32;
const GEOIP_MAX_ITEMS = 20000;
const GEOIP_MAX_BYTES = 1048576;
const GEOIP_MAX_TOTAL = 4194304;

final class SubmwMmdb
{
    private $fh;
    private int $nodeCount;
    private int $recordSize;
    private int $nodeBytes;
    private int $treeSize;
    private int $dataStart;
    private int $ipv4Start = -1;
    private int $budget = 0;
    private int $spent = 0;
    public array $meta = [];

    public function __construct(string $path)
    {
        $fh = @fopen($path, 'rb');
        if ($fh === false) throw new RuntimeException('не открывается');
        $this->fh = $fh;

        $size = (int) (fstat($fh)['size'] ?? 0);
        $tail = min($size, 131072);
        fseek($fh, $size - $tail);
        $buf = (string) fread($fh, $tail);
        $at  = strrpos($buf, "\xAB\xCD\xEFMaxMind.com");
        if ($at === false) throw new RuntimeException('не файл MaxMind DB');
        $metaStart = $size - $tail + $at + 14;

        $this->dataStart = $metaStart;
        $this->budget = GEOIP_MAX_ITEMS;
        $this->spent  = 0;
        [$meta] = $this->decode($metaStart, 0);
        if (!is_array($meta)) throw new RuntimeException('битые метаданные');
        $this->meta = $meta;

        $this->nodeCount  = (int) ($meta['node_count'] ?? 0);
        $this->recordSize = (int) ($meta['record_size'] ?? 0);
        if (!in_array($this->recordSize, [24, 28, 32], true) || $this->nodeCount <= 0) {
            throw new RuntimeException('неподдерживаемое дерево');
        }
        $this->nodeBytes = intdiv($this->recordSize * 2, 8);
        $this->treeSize  = $this->nodeBytes * $this->nodeCount;
        if ($this->treeSize + 16 > $metaStart) throw new RuntimeException('битое дерево');
        $this->dataStart = $this->treeSize + 16;
    }

    public function __destruct()
    {
        if (is_resource($this->fh)) fclose($this->fh);
    }

    public function type(): string
    {
        return (string) ($this->meta['database_type'] ?? '');
    }

    public function built(): int
    {
        return (int) ($this->meta['build_epoch'] ?? 0);
    }

    public function get(string $ip)
    {
        $packed = @inet_pton($ip);
        if ($packed === false) return null;

        $bits = strlen($packed) * 8;
        $node = 0;
        if ($bits === 32 && (int) ($this->meta['ip_version'] ?? 4) === 6) {
            $node = $this->ipv4Start();
        }

        for ($i = 0; $i < $bits && $node < $this->nodeCount; $i++) {
            $bit  = (ord($packed[$i >> 3]) >> (7 - ($i & 7))) & 1;
            $node = $this->record($node, $bit);
        }

        if ($node <= $this->nodeCount) return null;

        $offset = $node - $this->nodeCount + $this->treeSize;
        if ($offset < $this->dataStart) return null;
        $this->budget = GEOIP_MAX_ITEMS;
        $this->spent  = 0;
        [$value] = $this->decode($offset, 0);

        return $value;
    }

    private function ipv4Start(): int
    {
        if ($this->ipv4Start >= 0) return $this->ipv4Start;
        $node = 0;
        for ($i = 0; $i < 96 && $node < $this->nodeCount; $i++) {
            $node = $this->record($node, 0);
        }

        return $this->ipv4Start = $node;
    }

    private function record(int $node, int $bit): int
    {
        fseek($this->fh, $node * $this->nodeBytes);
        $b = (string) fread($this->fh, $this->nodeBytes);
        if (strlen($b) !== $this->nodeBytes) return $this->nodeCount;

        switch ($this->recordSize) {
            case 24:
                $o = $bit ? 3 : 0;
                return (ord($b[$o]) << 16) | (ord($b[$o + 1]) << 8) | ord($b[$o + 2]);
            case 28:
                if ($bit === 0) {
                    return ((ord($b[3]) & 0xF0) << 20) | (ord($b[0]) << 16) | (ord($b[1]) << 8) | ord($b[2]);
                }
                return ((ord($b[3]) & 0x0F) << 24) | (ord($b[4]) << 16) | (ord($b[5]) << 8) | ord($b[6]);
            default:
                return unpack('N', substr($b, $bit ? 4 : 0, 4))[1];
        }
    }

    private function bytes(int $at, int $n): string
    {
        if ($n <= 0) return '';
        if ($n > GEOIP_MAX_BYTES) throw new RuntimeException('слишком длинное значение');
        $this->spent += $n;
        if ($this->spent > GEOIP_MAX_TOTAL) throw new RuntimeException('слишком большая запись');
        fseek($this->fh, $at);
        $b = (string) fread($this->fh, $n);
        if (strlen($b) !== $n) throw new RuntimeException('обрыв файла');

        return $b;
    }

    private static function uint(string $b): int
    {
        $v = 0;
        $n = strlen($b);
        for ($i = 0; $i < $n; $i++) $v = ($v << 8) | ord($b[$i]);

        return $v;
    }

    // Возвращает [значение, позиция за ним]. Указатель разворачивается, но позиция
    // продолжения — сразу за самим указателем, как требует формат.
    private function decode(int $at, int $depth)
    {
        if ($depth > GEOIP_MAX_DEPTH || --$this->budget < 0) {
            throw new RuntimeException('слишком сложная запись');
        }

        $ctrl = ord($this->bytes($at, 1));
        $at++;
        $type = $ctrl >> 5;

        if ($type === 1) {
            $ss  = ($ctrl >> 3) & 0x3;
            $vvv = $ctrl & 0x7;
            $b   = $this->bytes($at, $ss + 1);
            $at += $ss + 1;
            switch ($ss) {
                case 0: $ptr = ($vvv << 8) | ord($b[0]); break;
                case 1: $ptr = (($vvv << 16) | self::uint($b)) + 2048; break;
                case 2: $ptr = (($vvv << 24) | self::uint($b)) + 526336; break;
                default: $ptr = self::uint($b); break;
            }
            [$value] = $this->decode($this->dataStart + $ptr, $depth + 1);

            return [$value, $at];
        }

        if ($type === 0) {
            $type = 7 + ord($this->bytes($at, 1));
            $at++;
            if ($type < 8) throw new RuntimeException('неверный расширенный тип');
        }

        $size = $ctrl & 0x1F;
        if ($size >= 29) {
            $extra = $size - 28;
            $b     = $this->bytes($at, $extra);
            $at   += $extra;
            $size  = [1 => 29, 2 => 285, 3 => 65821][$extra] + self::uint($b);
        }

        switch ($type) {
            case 2:
                return [$this->bytes($at, $size), $at + $size];
            case 3:
                return [unpack('E', $this->bytes($at, 8))[1], $at + 8];
            case 4:
                return [$this->bytes($at, $size), $at + $size];
            case 5:
            case 6:
            case 9:
            case 10:
                if ($size > 16) throw new RuntimeException('неверное целое');
                $b = $this->bytes($at, $size);
                if ($size > 7 && ltrim(substr($b, 0, $size - 7), "\0") !== '') {
                    return ['0x' . bin2hex($b), $at + $size];
                }
                return [self::uint($b), $at + $size];
            case 8:
                $v = self::uint($this->bytes($at, $size));
                if ($size === 4 && $v >= 0x80000000) $v -= 0x100000000;
                return [$v, $at + $size];
            case 7:
                $out = [];
                for ($i = 0; $i < $size; $i++) {
                    [$k, $at] = $this->decode($at, $depth + 1);
                    if (!is_string($k)) throw new RuntimeException('ключ не строка');
                    [$v, $at] = $this->decode($at, $depth + 1);
                    $out[$k] = $v;
                }
                return [$out, $at];
            case 11:
                $out = [];
                for ($i = 0; $i < $size; $i++) {
                    [$v, $at] = $this->decode($at, $depth + 1);
                    $out[] = $v;
                }
                return [$out, $at];
            case 14:
                return [$size !== 0, $at];
            case 15:
                return [unpack('G', $this->bytes($at, 4))[1], $at + 4];
            default:
                throw new RuntimeException('неизвестный тип ' . $type);
        }
    }
}

function geoip_dir() { return dirname(default_db_path()) . '/geoip'; }

function geoip_files() {
    return ['country' => geoip_dir() . '/country.mmdb', 'asn' => geoip_dir() . '/asn.mmdb', 'city' => geoip_dir() . '/city.mmdb', 'city6' => geoip_dir() . '/city6.mmdb'];
}

function geoip_auto() { return setting('geoip_auto', '1') === '1'; }

const GEOIP_MIRROR = 'https://github.com/sapics/ip-location-db/releases/download/latest/';

function geoip_asn_srcs() { return ['origin' => 'origin-asn (ip-location-db)', 'dbip' => 'DB-IP ASN Lite']; }

function geoip_asn_src() {
    $v = (string) setting('geoip_asn_src', 'origin');
    return isset(geoip_asn_srcs()[$v]) ? $v : 'origin';
}

function geoip_geo_srcs() { return ['geolite2' => 'GeoLite2 (ip-location-db)', 'dbip' => 'DB-IP Lite']; }

function geoip_geo_src() {
    $v = (string) setting('geoip_geo_src', 'geolite2');
    return isset(geoip_geo_srcs()[$v]) ? $v : 'geolite2';
}

function geoip_src_of($which) { return $which === 'asn' ? geoip_asn_src() : geoip_geo_src(); }

// Откуда скачан каждый файл — чтобы смена источника перекачала базу, не дожидаясь срока.
function geoip_have() {
    $v = json_decode((string) setting('geoip_have', ''), true);
    $out = is_array($v) ? $v : [];
    $asn = (string) setting('geoip_asn_have', '');
    if ($asn !== '' && !isset($out['asn'])) $out['asn'] = $asn;
    return $out;
}

function geoip_have_set($which, $src) {
    $h = geoip_have();
    $h[$which] = $src;
    set_setting('geoip_have', json_encode($h));
}

// Кандидаты на скачивание по порядку: [url, gz]. Чексум на зеркале отстаёт от файла на сутки,
// поэтому целостность проверяется чтением базы и контрольным поиском.
function geoip_sources($which) {
    $dbip = [];
    if ($which !== 'city6') {
        foreach ([gmdate('Y-m'), gmdate('Y-m', strtotime('first day of last month'))] as $month) $dbip[] = [geoip_dbip_url($which, $month), true];
    }
    $mirror = ['asn' => 'origin-asn.mmdb', 'country' => 'geolite2-country.mmdb', 'city' => 'geolite2-city-ipv4.mmdb', 'city6' => 'geolite2-city-ipv6.mmdb'];
    if (isset($mirror[$which]) && geoip_src_of($which) !== 'dbip') return array_merge([[GEOIP_MIRROR . $mirror[$which], false]], $dbip);
    return $dbip;
}

// Контрольный адрес Google: база AS обязана дать AS15169, страны и города — US.
// Пустой или битый файл — не замена рабочему.
function geoip_sane(SubmwMmdb $db, $which) {
    $ip = $which === 'city6' ? '2001:4860:4860::8888' : '8.8.8.8';
    try { $rec = $db->get($ip); } catch (Throwable $e) { return false; }
    if (!is_array($rec)) return false;
    if ($which === 'asn') return (int) ($rec['autonomous_system_number'] ?? 0) === 15169;
    return geoip_cc($rec) === 'US';
}

function geoip_city_on() { return setting('geoip_city', '1') === '1'; }

function geoip_city_ready() {
    if (geoip_ipinfo()) return true;
    return geoip_city_on() && geoip_reader('city') !== null;
}

// Есть ли откуда брать страну и провайдера.
function geoip_geo_ready() {
    return geoip_ipinfo() || geoip_reader('asn') !== null || geoip_reader('country') !== null;
}

function geoip_city_live() {
    if (!function_exists('db') || !($p = db())) return geoip_city_on();
    try {
        $st = $p->prepare('SELECT v FROM settings WHERE k = ?');
        $st->execute(['geoip_city']);
        $v = $st->fetchColumn();
    } catch (Throwable $e) { return geoip_city_on(); }

    return $v === false ? true : (string) $v === '1';
}

function geoip_wanted() {
    $w = ['country' => 'country', 'asn' => 'asn'];
    if (geoip_city_on()) {
        $w['city'] = 'city';
        if (geoip_geo_src() !== 'dbip') $w['city6'] = 'city6';
    }

    return $w;
}

function geoip_city_drop($only6 = false) {
    $f = geoip_files();
    $list = [$f['city6'], geoip_dir() . '/.city6.part'];
    if (!$only6) $list = array_merge($list, [$f['city'], geoip_dir() . '/.city.part', geoip_dir() . '/.city.part.gz']);
    foreach ($list as $x) if (is_file($x)) @unlink($x);
}

function geoip_cc($rec) {
    if (!is_array($rec)) return '';
    $cc = $rec['country']['iso_code'] ?? ($rec['registered_country']['iso_code'] ?? ($rec['country_code'] ?? ''));
    return is_string($cc) && preg_match('~^[A-Z]{2}$~', $cc) ? $cc : '';
}

function geoip_loc($rec) {
    if (!is_array($rec)) return '';
    $src = is_array($rec['location'] ?? null) ? $rec['location'] : $rec;
    $la = $src['latitude'] ?? null;
    $lo = $src['longitude'] ?? null;
    if (!is_numeric($la) || !is_numeric($lo)) return '';
    $la = (float) $la;
    $lo = (float) $lo;
    if ($la < -90 || $la > 90 || $lo < -180 || $lo > 180 || ($la == 0.0 && $lo == 0.0)) return '';

    return sprintf('%.2f,%.2f', $la, $lo);
}

function geoip_sub($rec) {
    $n = is_array($rec) ? ($rec['subdivisions'][0]['names']['en'] ?? ($rec['state1'] ?? '')) : '';
    if (!is_string($n)) return '';
    $n = trim(preg_replace('~[\x00-\x1F\x7F]~', '', $n));

    return mb_substr($n, 0, 64);
}

function geoip_reader($which) {
    $open = &$GLOBALS['submw_geoip_open'];
    if (!is_array($open)) $open = [];
    if (array_key_exists($which, $open)) return $open[$which];
    $path = geoip_files()[$which] ?? '';
    $open[$which] = null;
    if ($path === '' || !is_file($path)) return null;
    try { $open[$which] = new SubmwMmdb($path); }
    catch (Throwable $e) { error_log('submw geoip ' . $which . ': ' . $e->getMessage()); }

    return $open[$which];
}

// После подмены файлов в этом же процессе открытые базы и кэш поиска устарели.
function geoip_reset() {
    $GLOBALS['submw_geoip_open'] = [];
    $GLOBALS['submw_geoip_lc'] = [];
    $GLOBALS['submw_geonet_memo'] = [];
}

// Страна (ISO-код), номер и название AS, регион, город, координаты. Пустые поля — источник не знает.
// wait — подсеть ждёт ответа ipinfo: такие пустые поля временные, заглушкой их не закрывать.
// $prio — очередь уточнения: 1 сессии, 2 статистика.
function geoip_lookup($ip, $prio = 2) {
    $cache = &$GLOBALS['submw_geoip_lc'];
    if (!is_array($cache)) $cache = [];
    $ip = (string) $ip;
    if (isset($cache[$ip])) return $cache[$ip];
    if (geoip_ipinfo()) {
        $out = geonet_lookup($ip, $prio);
        // Пока ipinfo не ответил, страна и провайдер — из локальных баз: сводки по часам и аномалии пишутся сразу.
        if ($out['wait']) {
            $l = geoip_lookup_local($ip);
            foreach (['cc', 'asn', 'org', 'loc', 'sub'] as $f) if (empty($out[$f])) $out[$f] = $l[$f];
        }
        if (count($cache) < 4096) $cache[$ip] = $out;
        return $out;
    }
    $out = geoip_lookup_local($ip);
    if (count($cache) < 4096) $cache[$ip] = $out;

    return $out;
}

function geoip_lookup_local($ip) {
    $out = ['cc' => '', 'asn' => 0, 'org' => '', 'loc' => '', 'sub' => '', 'city' => '', 'wait' => false];
    foreach (['country', 'asn', 'city'] as $which) {
        if ($which === 'city' && !geoip_city_on()) continue;
        // Города с зеркала лежат двумя файлами: IPv6 — отдельно.
        if ($which === 'city' && strpos($ip, ':') !== false && geoip_reader('city6') !== null) $which = 'city6';
        $r = geoip_reader($which);
        if ($r === null) continue;
        try { $rec = $r->get($ip); } catch (Throwable $e) { $rec = null; }
        if (!is_array($rec)) continue;
        if ($out['loc'] === '') $out['loc'] = geoip_loc($rec);
        if ($out['sub'] === '') $out['sub'] = geoip_sub($rec);
        if ($out['cc'] === '') $out['cc'] = geoip_cc($rec);
        if ($out['asn'] === 0) {
            $asn = $rec['autonomous_system_number'] ?? 0;
            if (is_int($asn) && $asn > 0) {
                $out['asn'] = $asn;
                $org = $rec['autonomous_system_organization'] ?? '';
                $out['org'] = is_string($org) ? mb_substr($org, 0, 128) : '';
            }
        }
    }

    return $out;
}

function geoip_status() {
    $out = [];
    foreach (geoip_files() as $which => $path) {
        $row = ['path' => $path, 'ok' => false, 'type' => '', 'built' => 0, 'mtime' => 0, 'size' => 0];
        if (is_file($path)) {
            $row['mtime'] = (int) @filemtime($path);
            $row['size']  = (int) @filesize($path);
            $r = geoip_reader($which);
            if ($r !== null) {
                $row['ok']    = true;
                $row['type']  = $r->type();
                $row['built'] = $r->built();
            }
        }
        $out[$which] = $row;
    }

    return $out;
}

function geoip_dbip_url($which, $month) {
    return 'https://download.db-ip.com/free/dbip-' . $which . '-lite-' . $month . '.mmdb.gz';
}

// Скачивает свежие базы. Источники пробуются по порядку (у DB-IP — текущий месяц, затем
// прошлый: в первые дни месяца новый выпуск может ещё не лежать). Файл заменяется только
// целиком и только если он открывается как MaxMind DB нужного вида.
function geoip_update(&$err = '', $only = null) {
    $err = '';
    $dir = geoip_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) { $err = 'не создаётся папка ' . $dir; return false; }
    $lock = @fopen($dir . '/.lock', 'c');
    if ($lock && !flock($lock, LOCK_EX | LOCK_NB)) { fclose($lock); $err = 'обновление уже идёт'; return false; }
    @set_time_limit(1800);
    set_setting('geoip_try', (string) time());

    $want = geoip_wanted();
    if (is_array($only)) $want = array_intersect_key($want, array_flip($only));
    $done = [];
    $errs = [];
    foreach ($want as $which => $remote) {
        $last = '';
        foreach (geoip_sources($remote) as [$url, $gz]) {
            $tmp = $dir . '/.' . $which . '.part';
            $e   = '';
            if (!geoip_fetch($url, $tmp, $gz, $e)) { $last = $e; continue; }
            try {
                $probe = new SubmwMmdb($tmp);
                $kind  = strtolower($probe->type());
                $sane  = geoip_sane($probe, $which);
                unset($probe);
            } catch (Throwable $t) { $kind = ''; $sane = false; $last = 'скачанный файл не читается: ' . $t->getMessage(); }
            if ($kind === '' || strpos($kind, $which === 'city6' ? 'city' : $which) === false || !$sane) {
                @unlink($tmp);
                if ($kind !== '') $last = $sane ? 'скачан не тот файл: ' . $kind : 'база ' . basename($url) . ' не отвечает на контрольный адрес';
                continue;
            }
            if (($which === 'city' || $which === 'city6') && !geoip_city_live()) { @unlink($tmp); break; }
            if (!@rename($tmp, geoip_files()[$which])) { @unlink($tmp); $last = 'не заменяется файл базы'; continue; }
            $from = strpos($url, 'db-ip.com') !== false ? 'dbip' : ($which === 'asn' ? 'origin' : 'geolite2');
            geoip_have_set($which, $from);
            // Города DB-IP — один файл на оба семейства: отдельный IPv6-файл от зеркала больше не нужен.
            if ($which === 'city' && $from === 'dbip') geoip_city_drop(true);
            $done[$which] = true;
            $last = '';
            break;
        }
        if ($last !== '') $errs[] = $last;
    }
    if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
    if (isset($done['country'], $done['asn'])) set_setting('geoip_ts', (string) time());
    if ($done) { geoip_reset(); if (function_exists('sess_regeo')) sess_regeo(); }
    $err = implode('; ', $errs);

    return count($done) === count($want);
}

function geoip_fetch($url, $dest, $gz = true, &$err = '') {
    $err = '';
    $raw = $gz ? $dest . '.gz' : $dest;
    $fh = @fopen($raw, 'wb');
    if ($fh === false) { $err = 'не пишется временный файл'; return false; }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FILE           => $fh,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT        => 600,
        CURLOPT_FAILONERROR    => true,
        CURLOPT_MAXFILESIZE_LARGE => 256 * 1048576,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT      => 'remnawave-subscription-middleware',
    ]);
    $ok   = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    if (!$ok) $err = 'скачивание ' . basename($url) . ': ' . ($code > 0 ? 'HTTP ' . $code : curl_error($ch));
    curl_close($ch);
    fclose($fh);
    if (!$ok) { @unlink($raw); return false; }
    if (!$gz) {
        if ((int) @filesize($dest) < 1024) { @unlink($dest); $err = 'пустой файл ' . basename($url); return false; }
        return true;
    }

    $in  = @gzopen($raw, 'rb');
    $out = @fopen($dest, 'wb');
    if ($in === false || $out === false) {
        if ($in) gzclose($in);
        if ($out) fclose($out);
        @unlink($raw);
        $err = 'не распаковывается ' . basename($url);
        return false;
    }
    $total = 0;
    while (!gzeof($in)) {
        $chunk = gzread($in, 1048576);
        if ($chunk === false) break;
        $total += strlen($chunk);
        if ($total > 512 * 1048576) break;
        fwrite($out, $chunk);
    }
    gzclose($in);
    fclose($out);
    @unlink($raw);
    if ($total === 0 || $total > 512 * 1048576) { @unlink($dest); $err = 'пустой или огромный файл ' . basename($url); return false; }

    return true;
}

// С зеркала — раз в 7 дней, DB-IP — раз в 35; попытка не чаще раза в 6 часов. Зовётся после ответа клиенту.
// Скачивание баз после отдачи страницы: под php-fpm ответ завершается, под
// mod_php буферы выталкиваются — страница у человека уже есть, а соединение
// живёт, пока базы качаются; уход со страницы скачивание не обрывает.
function geoip_update_after_response() {
    if (session_status() === PHP_SESSION_ACTIVE) @session_write_close();
    @ignore_user_abort(true);
    if (function_exists('fastcgi_finish_request')) {
        @fastcgi_finish_request();
    } else {
        while (ob_get_level() > 0) @ob_end_flush();
        @flush();
    }
    // Без планировщика очередь ipinfo разбирает открытая админка — понемногу и только когда ответ уже ушёл:
    // под cgi-fcgi страница ждала бы конца скрипта, там очередь двигает опрос вкладки «Геобазы».
    if (geoip_ipinfo() && !(function_exists('cron_alive') && cron_alive()) && (function_exists('fastcgi_finish_request') || PHP_SAPI === 'apache2handler')) geonet_step(microtime(true) + 5, 12);
    geoip_maybe_update();
    if (function_exists('rep_purge_maybe')) rep_purge_maybe();
}

function geoip_maybe_update() {
    if (!geoip_auto()) return;
    $now = time();
    if ($now - (int) setting('geoip_try', '0') < 6 * 3600) return;
    $need = [];
    $have = geoip_have();
    // С ipinfo локально нужны только страна и провайдер — на время, пока подсеть ждёт ответа.
    $want = geoip_ipinfo() ? array_intersect_key(geoip_wanted(), ['country' => 1, 'asn' => 1]) : geoip_wanted();
    foreach ($want as $which => $remote) {
        $f = geoip_files()[$which];
        // Файл без записи об источнике — скачан старой версией или положен вручную: его не трогаем до истечения срока.
        $src = $have[$which] ?? '';
        $max = ($src === '' || $src === 'dbip') ? 35 * 86400 : 7 * 86400;
        if (!is_file($f) || $now - (int) @filemtime($f) >= $max || ($src !== '' && $src !== geoip_src_of($which))) $need[] = $which;
    }
    if (!$need) return;
    $err = '';
    if (!geoip_update($err, $need)) error_log('submw geoip update: ' . $err);
}

// ---------------------------------------------------------------------------------------------
// ipinfo.io. Публичный API без токена отдаёт город и регион, но с одного IP сервера — около
// 1000 запросов в сутки. Поэтому ответ кэшируется на подсеть (/24 IPv4, /48 IPv6) в geo_net,
// а наружу ходит только планировщик (без него — открытая админка) в пределах суточного лимита.
// Поиск по адресу ничего не ждёт: неизвестная подсеть встаёт в очередь, гео дописывается
// в сессии и отчёты, когда придёт ответ. Подсеть, которая снова попалась через 30 дней, уточняется заново.

const GEONET_REFRESH = 2592000;
const GEONET_KEEP_DAYS = 180;

function geoip_modes() { return ['ipinfo' => 'ipinfo.io — по API', 'local' => 'Локальные базы (MaxMind DB)']; }

// Без явного выбора — локальные базы: обновившиеся установки не начинают молча отправлять адреса клиентов наружу.
// Новым ставит ipinfo мастер установки; где очередь ipinfo уже работала (geonet_init), остаётся ipinfo.
function geoip_mode() { return (string) setting('geoip_mode', setting('geonet_init', '') === '1' ? 'ipinfo' : 'local') === 'ipinfo' ? 'ipinfo' : 'local'; }

function geoip_ipinfo() { return geoip_mode() === 'ipinfo'; }

function ipinfo_token() { return trim((string) setting('ipinfo_token', '')); }

function ipinfo_cap_max() { return ipinfo_token() !== '' ? 1000000 : 1000; }

function ipinfo_cap() { return max(10, min(ipinfo_cap_max(), (int) setting('ipinfo_cap', '900'))); }

// Тесты подменяют адрес на мок; вне копии tools/test.php переменная не действует.
function ipinfo_base() {
    $t = getenv('SUBMW_TEST_IPINFO');
    return is_string($t) && $t !== '' && (string) getenv('SUBMW_TEST_BOX') !== '' ? $t : 'https://ipinfo.io/';
}

// Ключ подсети; частные, служебные и пустые адреса гео не имеют — ''.
function geonet_key($ip) {
    $ip = trim((string) $ip);
    if (filter_var($ip, FILTER_VALIDATE_IP) === false) return '';
    $bin = @inet_pton($ip);
    if ($bin === false) return '';
    if (strlen($bin) === 16 && substr($bin, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
        $bin = substr($bin, 12);
        $ip = (string) inet_ntop($bin);
    }
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) return '';
    if (strlen($bin) === 4) return 'v4:' . implode('.', array_slice(unpack('C4', $bin), 0, 3));

    return 'v6:' . bin2hex(substr($bin, 0, 6));
}

// Диапазон текстовых адресов [от, до), заведомо покрывающий подсеть, — для поиска по индексу; точное совпадение — по geonet_key.
// IPv6 записан в сжатом виде, поэтому берём только первые два блока (второй — если он не нулевой).
function geonet_range($k) {
    if (strncmp($k, 'v4:', 3) === 0) return [substr($k, 3) . '.', substr($k, 3) . '/'];
    $h1 = ltrim(substr($k, 3, 4), '0');
    $h2 = ltrim(substr($k, 7, 4), '0');
    $pre = ($h1 === '' ? '0' : $h1) . ':' . ($h2 !== '' ? $h2 . ':' : '');

    return [$pre, substr($pre, 0, -1) . ';'];
}

// Индексы по адресу в сессиях и отчётах — для дописывания ответов ipinfo. Таблицы появляются с функциями, поэтому создаём по мере появления.
function geonet_idx_ensure($p) {
    if (setting('geonet_idx', '') === '1') return;
    $my = db_driver() === 'mysql';
    $all = true;
    foreach (['idx_cs_ip' => ['conn_sess', 'ip'], 'idx_rep_net_ip_ip' => ['rep_net_ip', 'ip'], 'idx_rep_state_ip4' => ['rep_state', 'ip4'], 'idx_rep_state_ip6' => ['rep_state', 'ip6']] as $name => [$t, $col]) {
        if (!db_table_exists($p, db_driver(), $t)) { $all = false; continue; }
        try { $p->exec($my ? "CREATE INDEX $name ON $t ($col)" : "CREATE INDEX IF NOT EXISTS $name ON $t ($col)"); }
        catch (Throwable $e) { if (stripos($e->getMessage(), 'Duplicate key name') === false) { $all = false; error_log('submw geo_net index ' . $name . ': ' . $e->getMessage()); } }
    }
    if ($all) set_setting('geonet_idx', '1');
}

function geonet_ddl($drv) {
    if ($drv === 'mysql') {
        return ["CREATE TABLE IF NOT EXISTS geo_net (
            k VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            ip VARCHAR(45) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
            cc CHAR(2) NOT NULL DEFAULT '', sub VARCHAR(64) NOT NULL DEFAULT '', city VARCHAR(64) NOT NULL DEFAULT '',
            loc VARCHAR(24) NOT NULL DEFAULT '', asn INT UNSIGNED NOT NULL DEFAULT 0, org VARCHAR(128) NOT NULL DEFAULT '',
            ts INT UNSIGNED NOT NULL DEFAULT 0, seen INT UNSIGNED NOT NULL DEFAULT 0,
            want TINYINT UNSIGNED NOT NULL DEFAULT 0, prio TINYINT UNSIGNED NOT NULL DEFAULT 2,
            fails TINYINT UNSIGNED NOT NULL DEFAULT 0, rq TINYINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (k), KEY idx_gn_want (want, prio), KEY idx_gn_rq (rq), KEY idx_gn_seen (seen)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"];
    }
    return [
        "CREATE TABLE IF NOT EXISTS geo_net (
            k TEXT NOT NULL PRIMARY KEY, ip TEXT NOT NULL DEFAULT '',
            cc TEXT NOT NULL DEFAULT '', sub TEXT NOT NULL DEFAULT '', city TEXT NOT NULL DEFAULT '', loc TEXT NOT NULL DEFAULT '',
            asn INTEGER NOT NULL DEFAULT 0, org TEXT NOT NULL DEFAULT '', ts INTEGER NOT NULL DEFAULT 0, seen INTEGER NOT NULL DEFAULT 0,
            want INTEGER NOT NULL DEFAULT 0, prio INTEGER NOT NULL DEFAULT 2, fails INTEGER NOT NULL DEFAULT 0, rq INTEGER NOT NULL DEFAULT 0
        )",
        'CREATE INDEX IF NOT EXISTS idx_gn_want ON geo_net(want, prio)',
        'CREATE INDEX IF NOT EXISTS idx_gn_rq ON geo_net(rq)',
        'CREATE INDEX IF NOT EXISTS idx_gn_seen ON geo_net(seen)',
    ];
}

function geonet_ensure() {
    static $ok = null;
    if ($ok !== null) return $ok;
    if (!function_exists('db') || !($p = db())) return false;
    // Поиск идёт и из запросов клиентов — DDL там не гоняем, флаг в настройках.
    if (setting('geonet_tables', '') === '1') return $ok = true;
    // DDL в MySQL неявно коммитит открытую транзакцию вызывающего — внутри неё таблицу не создаём.
    if ($p->inTransaction()) return false;
    try { foreach (geonet_ddl(db_driver()) as $sql) $p->exec($sql); set_setting('geonet_tables', '1'); $ok = true; }
    catch (Throwable $e) { error_log('submw geo_net table: ' . $e->getMessage()); $ok = false; }
    return $ok;
}

function geonet_ins_sql() { return (db_driver() === 'mysql' ? 'INSERT IGNORE' : 'INSERT OR IGNORE') . ' INTO geo_net (k, ip, seen, want, prio, rq) VALUES (?, ?, ?, 1, ?, ?)'; }

function geonet_lookup($ip, $prio = 2) {
    $out = ['cc' => '', 'asn' => 0, 'org' => '', 'loc' => '', 'sub' => '', 'city' => '', 'wait' => false];
    $k = geonet_key($ip);
    if ($k === '' || !geonet_ensure() || !($p = db())) return $out;
    $now = time();
    $prio = max(1, min(3, (int) $prio));
    $memo = &$GLOBALS['submw_geonet_memo'];
    if (!is_array($memo)) $memo = [];
    try {
        if (!array_key_exists($k, $memo)) {
            $st = $p->prepare('SELECT cc, sub, city, loc, asn, org, ts, seen, want, prio FROM geo_net WHERE k = ?');
            $st->execute([$k]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            $st->closeCursor();
            if (count($memo) >= 4096) $memo = [];
            $memo[$k] = $r ?: null;
        }
        $r = $memo[$k];
        if ($r === null) {
            $p->prepare(geonet_ins_sql())->execute([$k, (string) $ip, $now, $prio, 0]);
            $memo[$k] = ['cc' => '', 'sub' => '', 'city' => '', 'loc' => '', 'asn' => 0, 'org' => '', 'ts' => 0, 'seen' => $now, 'want' => 1, 'prio' => $prio];
            $out['wait'] = true;
            return $out;
        }
        $ts = (int) $r['ts'];
        $want = (int) $r['want'];
        if ($want === 0 && $ts > 0 && $now - $ts >= GEONET_REFRESH) {
            $p->prepare('UPDATE geo_net SET want = 1, prio = ?, seen = ? WHERE k = ?')->execute([$prio, $now, $k]);
            $memo[$k]['want'] = 1; $memo[$k]['prio'] = $prio; $memo[$k]['seen'] = $now;
        } elseif ($want === 1 && $prio < (int) $r['prio']) {
            $p->prepare('UPDATE geo_net SET prio = ?, seen = ? WHERE k = ?')->execute([$prio, $now, $k]);
            $memo[$k]['prio'] = $prio; $memo[$k]['seen'] = $now;
        } elseif ($now - (int) $r['seen'] >= 86400) {
            $p->prepare('UPDATE geo_net SET seen = ? WHERE k = ?')->execute([$now, $k]);
            $memo[$k]['seen'] = $now;
        }
    } catch (Throwable $e) {
        error_log('submw geo_net lookup: ' . $e->getMessage());
        return $out;
    }
    $out['wait'] = $ts === 0;
    foreach (['cc', 'sub', 'city', 'loc', 'org'] as $f) $out[$f] = (string) $r[$f];
    $out['asn'] = (int) $r['asn'];

    return $out;
}

// Города подсетей для показа рядом с регионом: город в таблицах сессий не хранится.
function geonet_cities(array $ips) {
    $out = [];
    if (!geoip_ipinfo() || !$ips || !geonet_ensure() || !($p = db())) return $out;
    $by = [];
    foreach ($ips as $ip) { $k = geonet_key($ip); if ($k !== '') $by[$k][] = (string) $ip; }
    foreach (array_chunk(array_keys($by), 200) as $chunk) {
        try {
            $st = $p->prepare('SELECT k, city FROM geo_net WHERE city <> \'\' AND k IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')');
            $st->execute($chunk);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) foreach ($by[$r['k']] as $ip) $out[$ip] = (string) $r['city'];
        } catch (Throwable $e) { break; }
    }

    return $out;
}

function ipinfo_parse(array $j) {
    $clean = static function ($v, $n) { return mb_substr(trim((string) preg_replace('~[\x00-\x1F\x7F]~u', '', is_scalar($v) ? (string) $v : '')), 0, $n); };
    $cc = strtoupper($clean($j['country'] ?? ($j['country_code'] ?? ''), 3));
    if (!preg_match('~^[A-Z]{2}$~', $cc)) $cc = '';
    $asn = 0;
    $org = '';
    if (preg_match('~^AS(\d{1,10})\b\s*(.*)$~i', $clean($j['org'] ?? '', 200), $m)) {
        $asn = (int) $m[1];
        $org = $m[2];
    } elseif (isset($j['asn'])) {
        $a = is_array($j['asn']) ? ($j['asn']['asn'] ?? '') : $j['asn'];
        if (preg_match('~(\d{1,10})~', $clean($a, 20), $m)) $asn = (int) $m[1];
        $org = is_array($j['asn']) ? ($j['asn']['name'] ?? '') : ($j['as_name'] ?? '');
    }
    if ($asn < 1 || $asn > 4294967295) { $asn = 0; $org = ''; }
    $loc = '';
    if (preg_match('~^\s*(-?\d{1,3}(?:\.\d+)?)\s*,\s*(-?\d{1,3}(?:\.\d+)?)\s*$~', $clean($j['loc'] ?? '', 40), $m)) $loc = geoip_loc(['latitude' => (float) $m[1], 'longitude' => (float) $m[2]]);

    return ['cc' => $cc, 'sub' => $clean($j['region'] ?? '', 64), 'city' => $clean($j['city'] ?? '', 64), 'loc' => $loc, 'asn' => $asn, 'org' => $clean($org, 128)];
}

function ipinfo_stat() {
    $s = function_exists('admin_cache_get') ? admin_cache_get('ipinfo_stat', PHP_INT_MAX) : null;
    $s = is_array($s) ? $s : [];
    $keep = ['block' => 0, 'last_ok' => 0, 'last_err' => '', 'err_ts' => 0, 'last_lim' => 0, 'noreg' => 0];
    $s += $keep;
    // Сутки — по UTC: когда именно ipinfo обнуляет свой счётчик, не документировано, отказ по лимиту ставит паузу сам.
    if ((string) ($s['day'] ?? '') !== gmdate('Y-m-d')) $s = ['day' => gmdate('Y-m-d'), 'n' => 0, 'ok' => 0, 'fail' => 0, 'lim' => 0] + array_intersect_key($s, $keep);

    return $s;
}

function ipinfo_stat_put(array $s) { if (function_exists('admin_cache_put')) admin_cache_put('ipinfo_stat', $s); }

// Параллельно, по несколько адресов: [ключ => [code, body, err]].
function ipinfo_fetch(array $ips, $timeout) {
    $tok = ipinfo_token();
    $mh = curl_multi_init();
    $hs = [];
    foreach ($ips as $k => $ip) {
        $ch = curl_init(ipinfo_base() . $ip . '/json' . ($tok !== '' ? '?token=' . rawurlencode($tok) : ''));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => min(4, $timeout),
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'remnawave-subscription-middleware',
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        curl_multi_add_handle($mh, $ch);
        $hs[$k] = $ch;
    }
    do {
        $st = curl_multi_exec($mh, $run);
        if ($run && curl_multi_select($mh, 1.0) === -1) usleep(20000);
    } while ($run && $st === CURLM_OK);
    $out = [];
    foreach ($hs as $k => $ch) {
        $body = curl_multi_getcontent($ch);
        $out[$k] = ['code' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'body' => is_string($body) ? substr($body, 0, 65536) : '', 'err' => (string) curl_error($ch)];
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);

    return $out;
}

// Разбирает очередь до дедлайна и суточного лимита. Отказ по лимиту (429) или неверный токен — пауза на час;
// ни один адрес пачки не ответил (сеть, 5xx) — пауза 10 минут без штрафа адресам; адрес, трижды
// не ответивший при живом ipinfo, откладывается на месяц.
function geonet_step($deadline, $max = 120) {
    $res = ['n' => 0, 'ok' => 0, 'err' => '', 'pause' => ''];
    if (!geoip_ipinfo() || !geonet_ensure() || !($p = db())) return $res;
    if (!function_exists('curl_multi_init')) { $res['err'] = 'нет PHP-расширения curl'; return $res; }
    $lock = @fopen(dirname(default_db_path()) . '/.geonet.lock', 'c');
    if ($lock && !flock($lock, LOCK_EX | LOCK_NB)) { fclose($lock); return $res; }
    $s = null;
    $resolved = [];
    try {
        geonet_idx_ensure($p);
        if (setting('geonet_init', '') !== '1') {
            set_setting('geonet_init', '1');
            geonet_recheck_start(true, $deadline - 5);
        }
        $s = ipinfo_stat();
        if ((int) $s['block'] > time()) { $res['pause'] = 'block'; return $res; }
        $tried = [];
        $upOk = $p->prepare('UPDATE geo_net SET cc = ?, sub = ?, city = ?, loc = ?, asn = ?, org = ?, ts = ?, want = 0, fails = 0, rq = 0 WHERE k = ?');
        $upFail = $p->prepare('UPDATE geo_net SET fails = ? WHERE k = ?');
        $upGive = $p->prepare('UPDATE geo_net SET fails = 0, want = 0, rq = 0, ts = CASE WHEN ts = 0 THEN ? ELSE ts END WHERE k = ?');
        while ($res['n'] < $max && microtime(true) < $deadline - 1.5) {
            $left = ipinfo_cap() - (int) $s['n'];
            if ($left <= 0) { $res['pause'] = 'cap'; break; }
            $take = min(4, $left, $max - $res['n']);
            $q = $p->query('SELECT k, ip, fails FROM geo_net WHERE want = 1 ORDER BY prio, seen DESC LIMIT ' . (int) ($take + count($tried)));
            $rows = [];
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) if (!isset($tried[$r['k']]) && count($rows) < $take) $rows[] = $r;
            if (!$rows) break;
            $ips = [];
            foreach ($rows as $r) { $ips[$r['k']] = (string) $r['ip']; $tried[$r['k']] = 1; }
            $got = ipinfo_fetch($ips, (int) max(2, min(6, floor($deadline - microtime(true) - 0.5))));
            $res['n'] += count($got);
            $s['n'] += count($got);
            $stop = '';
            $bad = [];
            $soft = [];
            $now = time();
            $tok = ipinfo_token() !== '';
            foreach ($rows as $r) {
                $k = $r['k'];
                $g = $got[$k] ?? ['code' => 0, 'body' => '', 'err' => 'нет ответа'];
                $j = $g['code'] === 200 ? json_decode($g['body'], true) : null;
                if (is_array($j) && (isset($j['ip']) || isset($j['bogon']))) {
                    $d = empty($j['bogon']) ? ipinfo_parse($j) : ['cc' => '', 'sub' => '', 'city' => '', 'loc' => '', 'asn' => 0, 'org' => ''];
                    // Токен тарифа без городов (Lite) отдаёт только страну: такой ответ не сохраняем, чтобы не затереть регионы на месяц.
                    if ($tok && $d['cc'] !== '' && $d['sub'] === '' && $d['city'] === '') {
                        $s['noreg']++;
                        if ($s['noreg'] >= 5) { $stop = 'noreg'; continue; }
                        $soft[] = $r;
                        continue;
                    }
                    if ($d['sub'] !== '' || $d['city'] !== '') $s['noreg'] = 0;
                    $upOk->execute([$d['cc'], $d['sub'], $d['city'], $d['loc'], $d['asn'], $d['org'], $now, $k]);
                    $resolved[$k] = $d;
                    $s['ok']++;
                    $s['last_ok'] = $now;
                    $res['ok']++;
                    continue;
                }
                if ($g['code'] === 429) { $stop = 'lim'; continue; }
                if ($g['code'] === 401 || $g['code'] === 403) { $stop = 'auth'; continue; }
                $s['fail']++;
                $s['err_ts'] = $now;
                $s['last_err'] = $g['code'] > 0 ? 'HTTP ' . $g['code'] : ($g['err'] !== '' ? mb_substr($g['err'], 0, 160) : 'пустой ответ');
                $bad[] = $r;
            }
            if ($stop === 'lim') {
                $s['lim']++;
                $s['last_lim'] = $now;
                $s['block'] = $now + 3600;
                $res['pause'] = 'block';
                break;
            }
            if ($stop === 'noreg') {
                $s['fail']++;
                $s['err_ts'] = $now;
                $s['last_err'] = 'с этим токеном ipinfo не отдаёт регион и город — удалите токен';
                $s['block'] = $now + 3600;
                $res['pause'] = 'block';
                break;
            }
            if ($stop === 'auth') {
                $s['fail']++;
                $s['err_ts'] = $now;
                $s['last_err'] = ipinfo_token() !== '' ? 'ipinfo не принял токен' : 'ipinfo отказал в доступе';
                $s['block'] = $now + 3600;
                $res['pause'] = 'block';
                break;
            }
            if ($bad && count($bad) === count($rows)) {
                $s['block'] = $now + 600;
                $res['pause'] = 'block';
                $res['err'] = 'ipinfo не отвечает: ' . $s['last_err'];
                break;
            }
            foreach (array_merge($bad, $soft) as $r) {
                $f = (int) $r['fails'] + 1;
                if ($f >= 3) $upGive->execute([$now, $r['k']]);
                else $upFail->execute([$f, $r['k']]);
            }
        }
    } catch (Throwable $e) {
        $res['err'] = 'гео: ' . $e->getMessage();
        error_log('submw geo_net step: ' . $e->getMessage());
    } finally {
        if (is_array($s)) ipinfo_stat_put($s);
        if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
    }
    if ($resolved) {
        geoip_reset();
        geonet_apply($resolved);
    }

    return $res;
}

// Дописывает ответы ipinfo в уже записанные сессии и адреса отчётов. Пустое у ipinfo не стирает прежнее.
// Сводки по IP и по часам не трогаем: страну и провайдера в них уже записала локальная база, регион уточнит пересборка статистики.
function geonet_apply(array $resolved) {
    if (!$resolved || !($p = db())) return 0;
    $rng = [];
    foreach (array_keys($resolved) as $k) $rng[implode("\n", geonet_range($k))] = geonet_range($k);
    $specs = [
        ['conn_sess', 'ip', ['cc', 'asn', 'org', 'sub'], ''],
        ['rep_net_ip', 'ip', ['cc', 'asn', 'org', 'loc', 'sub'], ''],
        ['rep_state', 'ip4', ['cc', 'asn', 'org', 'loc', 'sub'], " AND ip4 <> ''"],
        ['rep_state', 'ip6', ['cc', 'asn', 'org', 'loc', 'sub'], " AND ip4 = '' AND ip6 <> ''"],
    ];
    $n = 0;
    foreach ($specs as [$t, $col, $fields, $extra]) {
        $st = null;
        try { $st = $p->prepare("SELECT id, $col AS gip, " . implode(', ', $fields) . " FROM $t WHERE $col >= ? AND $col < ?$extra"); } catch (Throwable $e) { continue; }
        foreach ($rng as [$lo, $hi]) {
            $todo = [];
            try {
                $st->execute([$lo, $hi]);
                while (($r = $st->fetch(PDO::FETCH_ASSOC)) !== false) {
                    $d = $resolved[geonet_key($r['gip'])] ?? null;
                    if ($d === null) continue;
                    $set = [];
                    foreach ($fields as $f) {
                        if ($f === 'org' && (int) $d['asn'] === 0) continue;
                        $v = $d[$f];
                        if ($v === '' || $v === 0) continue;
                        if ((string) $r[$f] !== (string) $v) $set[$f] = $v;
                    }
                    if ($set) $todo[] = [(int) $r['id'], $set];
                }
            } catch (Throwable $e) { break; }
            if (!$todo) continue;
            try {
                $p->beginTransaction();
                $prep = [];
                foreach ($todo as [$id, $set]) {
                    $sig = implode(',', array_keys($set));
                    if (!isset($prep[$sig])) $prep[$sig] = $p->prepare("UPDATE $t SET " . implode(', ', array_map(static fn($f) => "$f = ?", array_keys($set))) . ' WHERE id = ?');
                    $prep[$sig]->execute(array_merge(array_values($set), [$id]));
                    $n++;
                }
                $p->commit();
            } catch (Throwable $e) {
                try { $p->rollBack(); } catch (Throwable $e2) {}
                error_log('submw geo_net apply ' . $t . ': ' . $e->getMessage());
            }
        }
    }
    if ($n > 0 && function_exists('admin_cache_put')) admin_cache_put('sess_mx', []);

    return $n;
}

// Ставит в очередь все подсети, что есть в сессиях, отчётах и в кэше за 30 дней. С $deadline — сколько успеет:
// остальные подсети уточнятся, когда адрес попадётся снова.
function geonet_recheck_start($auto = false, $deadline = 0.0) {
    if (!geonet_ensure() || !($p = db())) return 0;
    $late = static fn() => $deadline > 0 && microtime(true) > $deadline;
    @set_time_limit(300);
    $now = time();
    $keys = [];
    $srcs = [
        ['SELECT DISTINCT ip FROM conn_sess', []],
        ['SELECT DISTINCT ip FROM rep_net_ip', []],
        ["SELECT DISTINCT ip4 FROM rep_state WHERE ip4 <> ''", []],
        ["SELECT DISTINCT ip6 FROM rep_state WHERE ip6 <> ''", []],
        ['SELECT ip FROM geo_net WHERE seen >= ?', [$now - GEONET_REFRESH]],
    ];
    foreach ($srcs as [$sql, $args]) {
        try {
            $st = $p->prepare($sql);
            $st->execute($args);
            while (($ip = $st->fetchColumn()) !== false) {
                $k = geonet_key($ip);
                if ($k !== '' && !isset($keys[$k])) $keys[$k] = (string) $ip;
                if (count($keys) >= 200000 || (count($keys) % 500 === 0 && $late())) break 2;
            }
        } catch (Throwable $e) {}
    }
    $queued = 0;
    try {
        $ins = $p->prepare(geonet_ins_sql());
        foreach (array_chunk($keys, 200, true) as $chunk) {
            if ($late()) break;
            $p->beginTransaction();
            foreach ($chunk as $k => $ip) $ins->execute([$k, $ip, $now, 3, 1]);
            $up = $p->prepare('UPDATE geo_net SET rq = 1, prio = CASE WHEN want = 1 AND prio < 3 THEN prio ELSE 3 END, want = 1 WHERE k IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')');
            $up->execute(array_keys($chunk));
            $p->commit();
            $queued += count($chunk);
        }
    } catch (Throwable $e) {
        try { $p->rollBack(); } catch (Throwable $e2) {}
        error_log('submw geo_net recheck: ' . $e->getMessage());
        return 0;
    }
    if ($queued > 0) admin_cache_put('geonet_rc', ['ts' => $now, 'total' => $queued, 'auto' => $auto ? 1 : 0, 'end' => 0]);

    return $queued;
}

// Остановка: что уже знает гео — снимается с очереди; что гео не имеет вовсе — остаётся обычной очередью.
function geonet_recheck_stop() {
    if (!geonet_ensure() || !($p = db())) return;
    try { $p->exec('UPDATE geo_net SET want = CASE WHEN ts > 0 AND prio = 3 THEN 0 ELSE want END, prio = CASE WHEN prio = 3 THEN 2 ELSE prio END, rq = 0 WHERE rq = 1'); }
    catch (Throwable $e) { error_log('submw geo_net recheck stop: ' . $e->getMessage()); }
    admin_cache_put('geonet_rc', []);
}

function geonet_purge_maybe() {
    if (time() - (int) setting('geonet_purge_ts', '0') < 86400) return;
    set_setting('geonet_purge_ts', (string) time());
    if (geonet_ensure() && function_exists('gc_purge')) gc_purge('geo_net', GEONET_KEEP_DAYS, 2.0);
}

function cron_task_geo($ctx) {
    if (!geoip_ipinfo()) return '';
    $r = geonet_step(min(microtime(true) + 25, $ctx['deadline'] - 2));
    geonet_purge_maybe();

    return $r['err'];
}

// Для вкладки «Геобазы»: счётчик суток, очередь, кэш и ход перепроверки.
function geonet_view() {
    $s = ipinfo_stat();
    $now = time();
    $cap = ipinfo_cap();
    $v = [
        'mode' => geoip_mode(), 'cap' => $cap, 'cap_max' => ipinfo_cap_max(), 'tok' => ipinfo_token() !== '',
        'used' => (int) $s['n'], 'ok' => (int) $s['ok'], 'fail' => (int) $s['fail'], 'lim' => (int) $s['lim'],
        'block' => max(0, (int) $s['block'] - $now), 'err' => (string) $s['last_err'],
        'err_ago' => (int) $s['err_ts'] > 0 ? $now - (int) $s['err_ts'] : -1,
        'lim_ago' => (int) $s['last_lim'] > 0 ? $now - (int) $s['last_lim'] : -1,
        'ok_ago' => (int) $s['last_ok'] > 0 ? $now - (int) $s['last_ok'] : -1,
        'reset' => 86400 - ($now % 86400),
        'alive' => function_exists('cron_alive') && cron_alive(),
        'q' => ['1' => 0, '2' => 0, '3' => 0], 'cached' => 0, 'rc' => null,
    ];
    if (!geonet_ensure() || !($p = db())) return $v;
    try {
        foreach ($p->query('SELECT prio, COUNT(*) AS c FROM geo_net WHERE want = 1 GROUP BY prio')->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $k = (string) max(1, min(3, (int) $r['prio']));
            $v['q'][$k] += (int) $r['c'];
        }
        $v['cached'] = (int) $p->query('SELECT COUNT(*) FROM geo_net WHERE ts > 0')->fetchColumn();
        $rc = admin_cache_get('geonet_rc', PHP_INT_MAX);
        if (is_array($rc) && (int) ($rc['total'] ?? 0) > 0) {
            $left = (int) $p->query('SELECT COUNT(*) FROM geo_net WHERE rq = 1')->fetchColumn();
            if ($left === 0 && (int) ($rc['end'] ?? 0) === 0) { $rc['end'] = $now; admin_cache_put('geonet_rc', $rc); }
            $total = max((int) $rc['total'], $left);
            $queued = array_sum($v['q']);
            $today = max(0, $cap - $v['used']);
            // Перед перепроверкой в очереди стоят сессии и статистика — они тоже тратят лимит.
            $need = $left > 0 ? $queued : 0;
            $days = $need <= $today ? 0 : (int) ceil(($need - $today) / $cap);
            $v['rc'] = ['total' => $total, 'left' => $left, 'done' => $total - $left, 'ago' => $now - (int) $rc['ts'],
                        'auto' => (int) ($rc['auto'] ?? 0), 'end_ago' => (int) ($rc['end'] ?? 0) > 0 ? $now - (int) $rc['end'] : -1, 'days' => $days];
        }
    } catch (Throwable $e) {}

    return $v;
}
