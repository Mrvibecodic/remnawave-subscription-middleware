<?php

// Страна, регион и автономная система по IP для отчётов и сессий. Базы — файлы MaxMind DB
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
    return geoip_city_on() && geoip_reader('city') !== null;
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
}

// Страна (ISO-код), номер AS и название AS. Пустые поля — база не знает или её нет.
function geoip_lookup($ip) {
    $cache = &$GLOBALS['submw_geoip_lc'];
    if (!is_array($cache)) $cache = [];
    $ip = (string) $ip;
    if (isset($cache[$ip])) return $cache[$ip];

    $out = ['cc' => '', 'asn' => 0, 'org' => '', 'loc' => '', 'sub' => ''];
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
    if (count($cache) < 4096) $cache[$ip] = $out;

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
    geoip_maybe_update();
    if (function_exists('rep_purge_maybe')) rep_purge_maybe();
}

function geoip_maybe_update() {
    if (!geoip_auto()) return;
    $now = time();
    if ($now - (int) setting('geoip_try', '0') < 6 * 3600) return;
    $need = [];
    $have = geoip_have();
    foreach (geoip_wanted() as $which => $remote) {
        $f = geoip_files()[$which];
        $max = geoip_src_of($which) === 'dbip' ? 35 * 86400 : 7 * 86400;
        // Файл без записи об источнике скачан старой версией — тогда источник был только DB-IP.
        if (!is_file($f) || $now - (int) @filemtime($f) >= $max || ($have[$which] ?? 'dbip') !== geoip_src_of($which)) $need[] = $which;
    }
    if (!$need) return;
    $err = '';
    if (!geoip_update($err, $need)) error_log('submw geoip update: ' . $err);
}
