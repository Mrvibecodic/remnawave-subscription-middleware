<?php

// Страна и автономная система по IP для отчётов клиентов. Базы — файлы MaxMind DB
// (.mmdb): по умолчанию бесплатные DB-IP Lite (страна и ASN, CC BY 4.0), раз в
// месяц скачиваются сами; вместо них можно положить свои файлы, например GeoLite2.
// Читатель формата свой, без composer: проект зависимостей не тянет.

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
    return ['country' => geoip_dir() . '/country.mmdb', 'asn' => geoip_dir() . '/asn.mmdb'];
}

function geoip_auto() { return setting('geoip_auto', '1') === '1'; }

function geoip_reader($which) {
    static $open = [];
    if (array_key_exists($which, $open)) return $open[$which];
    $path = geoip_files()[$which] ?? '';
    $open[$which] = null;
    if ($path === '' || !is_file($path)) return null;
    try { $open[$which] = new SubmwMmdb($path); }
    catch (Throwable $e) { error_log('submw geoip ' . $which . ': ' . $e->getMessage()); }

    return $open[$which];
}

// Страна (ISO-код), номер AS и название AS. Пустые поля — база не знает или её нет.
function geoip_lookup($ip) {
    static $cache = [];
    $ip = (string) $ip;
    if (isset($cache[$ip])) return $cache[$ip];

    $out = ['cc' => '', 'asn' => 0, 'org' => ''];
    foreach (['country', 'asn'] as $which) {
        $r = geoip_reader($which);
        if ($r === null) continue;
        try { $rec = $r->get($ip); } catch (Throwable $e) { $rec = null; }
        if (!is_array($rec)) continue;
        if ($out['cc'] === '') {
            $cc = $rec['country']['iso_code'] ?? ($rec['registered_country']['iso_code'] ?? '');
            if (is_string($cc) && preg_match('~^[A-Z]{2}$~', $cc)) $out['cc'] = $cc;
        }
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

// Скачивает свежие базы DB-IP Lite. Месяц пробуется текущий, затем прошлый: в первые
// дни месяца новый выпуск может ещё не лежать. Файл заменяется только целиком и только
// если он открывается как MaxMind DB нужного вида.
function geoip_update(&$err = '') {
    $err = '';
    $dir = geoip_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) { $err = 'не создаётся папка ' . $dir; return false; }
    $lock = @fopen($dir . '/.lock', 'c');
    if ($lock && !flock($lock, LOCK_EX | LOCK_NB)) { fclose($lock); $err = 'обновление уже идёт'; return false; }
    @set_time_limit(300);
    set_setting('geoip_try', (string) time());

    $done = 0;
    $want = ['country' => 'country', 'asn' => 'asn'];
    foreach ($want as $which => $remote) {
        $ok = false;
        foreach ([gmdate('Y-m'), gmdate('Y-m', strtotime('first day of last month'))] as $month) {
            $tmp = $dir . '/.' . $which . '.part';
            if (!geoip_fetch_gz(geoip_dbip_url($remote, $month), $tmp, $e)) { $err = $e; continue; }
            try {
                $probe = new SubmwMmdb($tmp);
                $kind  = strtolower($probe->type());
                unset($probe);
            } catch (Throwable $t) { $kind = ''; $err = 'скачанный файл не читается: ' . $t->getMessage(); }
            if ($kind === '' || strpos($kind, $which === 'asn' ? 'asn' : 'country') === false) {
                @unlink($tmp);
                if ($err === '') $err = 'скачан не тот файл: ' . $kind;
                continue;
            }
            if (!@rename($tmp, geoip_files()[$which])) { @unlink($tmp); $err = 'не заменяется файл базы'; continue; }
            $ok = true;
            break;
        }
        if ($ok) $done++;
    }
    if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
    if ($done === count($want)) {
        set_setting('geoip_ts', (string) time());
        $err = '';
        return true;
    }

    return false;
}

function geoip_fetch_gz($url, $dest, &$err = '') {
    $err = '';
    $gz = $dest . '.gz';
    $fh = @fopen($gz, 'wb');
    if ($fh === false) { $err = 'не пишется временный файл'; return false; }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FILE           => $fh,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT        => 240,
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
    if (!$ok) { @unlink($gz); return false; }

    $in  = @gzopen($gz, 'rb');
    $out = @fopen($dest, 'wb');
    if ($in === false || $out === false) {
        if ($in) gzclose($in);
        if ($out) fclose($out);
        @unlink($gz);
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
    @unlink($gz);
    if ($total === 0 || $total > 512 * 1048576) { @unlink($dest); $err = 'пустой или огромный файл ' . basename($url); return false; }

    return true;
}

// Раз в 35 дней, и не чаще попытки раз в 6 часов. Зовётся после ответа клиенту.
function geoip_maybe_update() {
    if (!geoip_auto()) return;
    $now = time();
    if ($now - (int) setting('geoip_try', '0') < 6 * 3600) return;
    $fresh = $now - (int) setting('geoip_ts', '0') < 35 * 86400;
    $have  = true;
    foreach (geoip_files() as $path) if (!is_file($path)) $have = false;
    if ($fresh && $have) return;
    $err = '';
    if (!geoip_update($err)) error_log('submw geoip update: ' . $err);
}
