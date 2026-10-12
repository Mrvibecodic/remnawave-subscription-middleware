<?php
declare(strict_types=1);

const APP_VERSION      = 1;
const APP_SALT         = 'submw-a1';
const APP_PAIR_SALT    = 'submw-a1-pair';
const APP_SKEW         = 300;
const APP_NONCE_TTL    = 600;
const APP_NONCE_LEN    = 22;
const APP_REQ_SMALL    = 512;
const APP_REQ_LIMIT    = 4096;
const APP_BLOCK        = 3072;
const APP_MAX_RAW      = 8388608;
const APP_FIELD_MAX    = 128;
const APP_CODE_LEN     = 12;
const APP_CODE_ABC     = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

function app_max_wire(): int
{
    return intdiv(APP_MAX_RAW * 4 + 2, 3);
}

function app_code_new(): string
{
    $out = '';
    for ($i = 0; $i < APP_CODE_LEN; $i++) {
        $out .= APP_CODE_ABC[random_int(0, 31)];
    }

    return $out;
}

function app_code_norm(string $code): ?string
{
    $code = strtoupper(preg_replace('~[\s-]+~', '', $code) ?? '');
    $code = strtr($code, ['I' => '1', 'L' => '1', 'O' => '0']);
    if (strlen($code) !== APP_CODE_LEN || strspn($code, APP_CODE_ABC) !== APP_CODE_LEN) {
        return null;
    }

    return $code;
}

function app_code_fmt(string $code): string
{
    return implode('-', str_split($code, 4));
}

function app_kdev(string $shared, string $did): string
{
    return chan_hkdf($shared, APP_SALT, 'dev' . $did);
}

function app_kid(string $kdev, int $epoch): string
{
    return chan_b64(substr(hash_hmac('sha256', 'kid|' . $epoch, $kdev, true), 0, 9));
}

function app_pad_len(int $raw, int $block): int
{
    return ($block - ($raw % $block)) % $block;
}

function app_frame(string $json, string $body, int $block): string
{
    $frame = pack('N', strlen($json)) . $json . pack('N', strlen($body)) . $body;

    return $frame . str_repeat("\0", app_pad_len(32 + strlen($frame) + 16, $block));
}

function app_req_block(int $raw): int
{
    return $raw <= APP_REQ_LIMIT ? APP_REQ_SMALL : APP_BLOCK;
}

function app_frame_parse(string $plain): ?array
{
    $len = strlen($plain);
    if ($len < 8) {
        return null;
    }
    $jl = unpack('N', substr($plain, 0, 4))[1];
    if ($jl < 2 || 8 + $jl > $len) {
        return null;
    }
    $bl = unpack('N', substr($plain, 4 + $jl, 4))[1];
    if (8 + $jl + $bl > $len) {
        return null;
    }
    $data = json_decode(substr($plain, 4, $jl), true, 16);
    if (!is_array($data)) {
        return null;
    }

    return [$data, substr($plain, 8 + $jl, $bl)];
}

function app_json(array $payload): ?string
{
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

    return $json === false ? null : $json;
}

function app_scrub($value, int $max = APP_FIELD_MAX): string
{
    if (!is_string($value)) {
        return '';
    }

    return mb_substr(strtr($value, ["\r" => '', "\n" => '', "\0" => '']), 0, $max);
}

function app_check_head(array $req, int $now, ?string &$why): bool
{
    if (($req['v'] ?? 0) !== APP_VERSION) {
        $why = 'json';

        return false;
    }
    $ts = $req['t'] ?? 0;
    if (!is_int($ts) || $ts <= 0 || abs($now - $ts) > APP_SKEW) {
        $why = 'time';

        return false;
    }
    $n = $req['n'] ?? '';
    if (!is_string($n) || strlen($n) !== APP_NONCE_LEN || preg_match('~[^A-Za-z0-9_-]~', $n)) {
        $why = 'nonce';

        return false;
    }
    $op = $req['op'] ?? '';
    if (!is_string($op) || !preg_match('~^[a-z]{2,8}$~', $op)) {
        $why = 'op';

        return false;
    }

    return true;
}

function app_wire_raw(string $wire): ?string
{
    if ($wire === '' || strlen($wire) > app_max_wire()) {
        return null;
    }
    $raw = chan_unb64($wire);
    if ($raw === null || strlen($raw) < 32 + 16 + 8) {
        return null;
    }

    return $raw;
}

function app_open(string $kid, string $spid, string $wire, callable $lookup, array $keys, ?int $now = null, ?string &$why = null): ?array
{
    $now = $now ?? time();
    $why = null;

    $raw = app_wire_raw($wire);
    if ($raw === null || strlen($kid) !== 12) {
        $why = 'blob';

        return null;
    }
    if (!isset($keys[$spid])) {
        $why = 'spid';

        return null;
    }
    $hit = $lookup($kid, $keys[$spid], $spid);
    if (!is_array($hit) || !isset($hit['kdev'])) {
        $why = 'kid';

        return null;
    }

    $eph = substr($raw, 0, 32);
    $dh  = chan_x25519($keys[$spid], $eph);
    if ($dh === null) {
        $why = 'dh';

        return null;
    }
    $key = chan_hkdf($hit['kdev'] . $dh, $kid, 'req' . $eph);

    try {
        $plain = sodium_crypto_aead_chacha20poly1305_ietf_decrypt(substr($raw, 32), 'a1' . $kid . $eph, str_repeat("\0", 12), $key);
    } catch (Throwable $e) {
        $plain = false;
    }
    if ($plain === false) {
        $why = 'aead';

        return null;
    }

    $frame = app_frame_parse($plain);
    if ($frame === null) {
        $why = 'frame';

        return null;
    }
    [$req, $body] = $frame;
    if (!app_check_head($req, $now, $why)) {
        return null;
    }

    return [
        'kid'   => $kid,
        'spid'  => $spid,
        'kdev'  => $hit['kdev'],
        'dev'   => $hit,
        'eph'   => $eph,
        'nonce' => $req['n'],
        'req'   => $req,
        'body'  => $body,
    ];
}

function app_seal(array $ctx, array $fields, string $body, int $status = 200, ?string $ephSecret = null, ?int $now = null): ?string
{
    [$secret, $public] = $ephSecret === null
        ? chan_keygen()
        : [$ephSecret, sodium_crypto_box_publickey_from_secretkey($ephSecret)];

    $shared = chan_x25519($secret, (string) $ctx['eph']);
    if ($shared === null) {
        return null;
    }

    $json = app_json(['v' => APP_VERSION, 't' => $now ?? time(), 'n' => $ctx['nonce'], 'st' => $status] + $fields);
    if ($json === null) {
        return null;
    }

    if (isset($ctx['code'])) {
        $key = chan_hkdf($ctx['code'] . $shared, APP_PAIR_SALT, 'res' . $ctx['eph']);
        $aad = 'a1pr' . $ctx['eph'] . $public;
    } else {
        $key = chan_hkdf($ctx['kdev'] . $shared, $ctx['kid'], 'res' . $ctx['eph']);
        $aad = 'a1r' . $ctx['kid'] . $ctx['eph'] . $public;
    }

    $cipher = sodium_crypto_aead_chacha20poly1305_ietf_encrypt(app_frame($json, $body, APP_BLOCK), $aad, str_repeat("\0", 12), $key);

    return chan_b64($public . $cipher);
}

function app_pair_open(string $wire, array $codes, ?int $now = null, ?string &$why = null): ?array
{
    $now = $now ?? time();
    $why = null;

    $raw = app_wire_raw($wire);
    if ($raw === null) {
        $why = 'blob';

        return null;
    }
    $eph    = substr($raw, 0, 32);
    $cipher = substr($raw, 32);

    $plain = false;
    $hit   = null;
    foreach ($codes as $code) {
        $code = (string) $code;
        try {
            $plain = sodium_crypto_aead_chacha20poly1305_ietf_decrypt($cipher, 'a1p' . $eph, str_repeat("\0", 12), chan_hkdf($code, APP_PAIR_SALT, 'req' . $eph));
        } catch (Throwable $e) {
            $plain = false;
        }
        if ($plain !== false) {
            $hit = $code;
            break;
        }
    }
    if ($hit === null) {
        $why = 'code';

        return null;
    }

    $frame = app_frame_parse((string) $plain);
    if ($frame === null) {
        $why = 'frame';

        return null;
    }
    [$req] = $frame;
    if (!app_check_head($req, $now, $why)) {
        return null;
    }
    if ($req['op'] !== 'pair') {
        $why = 'op';

        return null;
    }
    $dpub = is_string($req['dpub'] ?? null) ? chan_unb64($req['dpub']) : null;
    if ($dpub === null || strlen($dpub) !== 32 || chan_x25519(str_repeat("\x05", 32), $dpub) === null) {
        $why = 'dpub';

        return null;
    }

    return [
        'code'  => $hit,
        'eph'   => $eph,
        'nonce' => $req['n'],
        'req'   => $req,
        'dpub'  => $dpub,
    ];
}

function app_route(string $uri): ?array
{
    $path = $uri;
    foreach (['#', '?'] as $cut) {
        $at = strpos($path, $cut);
        if ($at !== false) {
            $path = substr($path, 0, $at);
        }
    }

    if (!preg_match('~^(.*)/a1/([A-Za-z0-9_-]{12}|0)/([A-Za-z0-9_-]{6}|0)$~', $path, $m)) {
        return null;
    }
    if (($m[2] === '0') !== ($m[3] === '0')) {
        return null;
    }

    return [$m[1], $m[2], $m[3]];
}
