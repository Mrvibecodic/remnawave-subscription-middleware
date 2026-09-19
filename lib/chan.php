<?php
declare(strict_types=1);


const CHAN_VERSION   = 1;
const CHAN_SALT      = 'clod-chan-v1';
const CHAN_SKEW      = 300;
const CHAN_NONCE_TTL = 600;
const CHAN_PAD_BLOCK = 3072;
const CHAN_MAX_BLOB  = 4096;
const CHAN_NONCE_LEN = 22;
const CHAN_FIELD_MAX = 1024;

const CHAN_REQ_PAD_BLOCK = 512;

function chan_b64(string $raw): string
{
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

function chan_unb64(string $text): ?string
{
    if ($text === '' || preg_match('~[^A-Za-z0-9_-]~', $text)) {
        return null;
    }
    $raw = base64_decode(strtr($text, '-_', '+/'), true);

    return $raw === false ? null : $raw;
}

function chan_psk(string $token): string
{
    return hash_hkdf('sha256', $token, 32, 'psk', CHAN_SALT);
}

function chan_epoch(?int $now = null): int
{
    return intdiv($now ?? time(), 86400);
}

function chan_kid(string $psk, int $epoch): string
{
    return chan_b64(substr(hash_hmac('sha256', 'kid|' . $epoch, $psk, true), 0, 9));
}

function chan_kids(string $token, ?int $now = null): array
{
    $psk   = chan_psk($token);
    $epoch = chan_epoch($now);

    return [
        $epoch - 1 => chan_kid($psk, $epoch - 1),
        $epoch     => chan_kid($psk, $epoch),
        $epoch + 1 => chan_kid($psk, $epoch + 1),
    ];
}

function chan_spid(string $publicKey): string
{
    return substr(chan_b64(hash('sha256', $publicKey, true)), 0, 6);
}

function chan_keygen(): array
{
    $secret = random_bytes(32);

    return [$secret, sodium_crypto_box_publickey_from_secretkey($secret)];
}

function chan_hkdf(string $ikm, string $salt, string $info): string
{
    return hash_hkdf('sha256', $ikm, 32, $info, $salt);
}

function chan_x25519(string $secret, string $public): ?string
{
    if (strlen($secret) !== 32 || strlen($public) !== 32) {
        return null;
    }

    try {
        return sodium_crypto_scalarmult($secret, $public);
    } catch (Throwable $e) {
        return null;
    }
}

function chan_scrub($value): string
{
    if (!is_string($value)) {
        return '';
    }

    return substr(strtr($value, ["\r" => '', "\n" => '', "\0" => '']), 0, CHAN_FIELD_MAX);
}

function chan_open(string $kid, string $spid, string $blob, callable $lookup, array $keys, ?int $now = null, ?string &$why = null): ?array
{
    $now = $now ?? time();
    $why = null;

    if (strlen($blob) > CHAN_MAX_BLOB || strlen($kid) !== 12) {
        $why = 'blob';

        return null;
    }

    $raw = chan_unb64($blob);
    if ($raw === null || strlen($raw) < 32 + 16 + 1) {
        $why = 'blob';

        return null;
    }

    $token = $lookup($kid);
    if ($token === null || $token === '') {
        $why = 'kid';

        return null;
    }

    $ephPub = substr($raw, 0, 32);
    $cipher = substr($raw, 32);

    $dh = '';
    if ($spid !== '0') {
        if (!isset($keys[$spid])) {
            $why = 'spid';

            return null;
        }
        $dh = chan_x25519($keys[$spid], $ephPub);
        if ($dh === null) {
            $why = 'dh';

            return null;
        }
    }

    $psk = chan_psk($token);
    $key = chan_hkdf($psk . $dh, $kid, 'req' . $ephPub);

    try {
        $plain = sodium_crypto_aead_chacha20poly1305_ietf_decrypt(
            $cipher,
            'c1' . $kid . $ephPub,
            str_repeat("\0", 12),
            $key
        );
    } catch (Throwable $e) {
        $why = 'aead';

        return null;
    }
    if ($plain === false) {
        $why = 'aead';

        return null;
    }

    $req = json_decode($plain, true);
    if (!is_array($req) || ($req['v'] ?? 0) !== CHAN_VERSION) {
        $why = 'json';

        return null;
    }

    $ts = (int)($req['t'] ?? 0);
    if ($ts <= 0 || abs($now - $ts) > CHAN_SKEW) {
        $why = 'time';

        return null;
    }

    $nonce = is_string($req['n'] ?? null) ? $req['n'] : '';
    if (strlen($nonce) !== CHAN_NONCE_LEN || preg_match('~[^A-Za-z0-9_-]~', $nonce)) {
        $why = 'nonce';

        return null;
    }

    foreach (['hwid', 'os', 'osv', 'model', 'ua', 'acc', 'q'] as $field) {
        $req[$field] = chan_scrub($req[$field] ?? '');
    }

    return [
        'token'  => $token,
        'kid'    => $kid,
        'psk'    => $psk,
        'dh'     => $dh,
        'ephPub' => $ephPub,
        'nonce'  => $nonce,
        'req'    => $req,
        'plain'  => $plain,
    ];
}

function chan_request_headers(array $ctx): array
{
    $req = $ctx['req'] ?? [];
    $out = [
        'x-hwid'         => (string)($req['hwid']  ?? ''),
        'x-device-os'    => (string)($req['os']    ?? ''),
        'x-ver-os'       => (string)($req['osv']   ?? ''),
        'x-device-model' => (string)($req['model'] ?? ''),
        'user-agent'     => (string)($req['ua']    ?? ''),
        'accept'         => (string)($req['acc']   ?? ''),
    ];

    return array_filter($out, static fn(string $v): bool => $v !== '');
}

function chan_request_query(array $ctx): string
{
    return (string)($ctx['req']['q'] ?? '');
}

function chan_seal(array $ctx, array $meta, string $body, string $spPublic, bool $pad = true, int $status = 200, ?string $ephSecret = null, ?int $now = null): ?string
{
    [$secret, $public] = $ephSecret === null
        ? chan_keygen()
        : [$ephSecret, sodium_crypto_box_publickey_from_secretkey($ephSecret)];

    $shared = chan_x25519($secret, (string)$ctx['ephPub']);
    if ($shared === null) {
        return null;
    }

    $key = chan_hkdf($ctx['psk'] . $shared . $ctx['dh'], $ctx['kid'], 'res' . $ctx['ephPub']);

    $payload = [
        'v'    => CHAN_VERSION,
        't'    => $now ?? time(),
        'n'    => $ctx['nonce'],
        'st'   => $status,
        'sp'   => chan_b64($spPublic),
        'meta' => (object)$meta,
        'body' => $body,
    ];

    $plain = chan_json($payload);
    if ($plain === null) {
        return null;
    }

    if ($pad) {
        $need = (CHAN_PAD_BLOCK - ((strlen($plain) + 48 + 9) % CHAN_PAD_BLOCK)) % CHAN_PAD_BLOCK;
        if ((strlen($plain) + 48) % CHAN_PAD_BLOCK !== 0) {
            $padded = chan_json($payload + ['pad' => str_repeat('.', $need)]);
            if ($padded === null) {
                return null;
            }
            $plain = $padded;
        }
    }

    $cipher = sodium_crypto_aead_chacha20poly1305_ietf_encrypt(
        $plain,
        'c1r' . $ctx['kid'] . $ctx['ephPub'] . $public,
        str_repeat("\0", 12),
        $key
    );

    return chan_b64($public . $cipher);
}

function chan_json(array $payload): ?string
{
    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    $json = json_encode($payload, $flags);
    if ($json !== false) {
        return $json;
    }

    if (isset($payload['body']) && is_string($payload['body'])) {
        $payload['body_b64'] = chan_b64($payload['body']);
        $payload['body']     = '';
    }

    $meta = (array)($payload['meta'] ?? []);
    foreach ($meta as $name => $values) {
        foreach ((array)$values as $i => $value) {
            $value = (string)$value;
            if (!chan_utf8($value)) {
                $meta[$name][$i] = '=?b64?' . chan_b64($value);
            }
        }
    }
    $payload['meta'] = (object)$meta;

    $json = json_encode($payload, $flags);
    if ($json !== false) {
        return $json;
    }

    $payload['meta'] = (object)[];
    $json = json_encode($payload, $flags);

    return $json === false ? null : $json;
}

function chan_utf8(string $s): bool
{
    return $s === '' || preg_match('//u', $s) === 1;
}

function chan_route(string $uri): ?array
{
    $path = $uri;
    foreach (['#', '?'] as $cut) {
        $at = strpos($path, $cut);
        if ($at !== false) {
            $path = substr($path, 0, $at);
        }
    }

    if (!preg_match('~^(.*)/c1/([A-Za-z0-9_-]{12})/([A-Za-z0-9_-]{1,8})/([A-Za-z0-9_-]+)$~', $path, $m)) {
        return null;
    }

    return [$m[1], $m[2], $m[3], $m[4]];
}
