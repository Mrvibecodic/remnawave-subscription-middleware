<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

function appc_seal(string $kdev, string $kid, string $spPublic, string $json, string $body, string $ephSecret): string
{
    $ephPublic = sodium_crypto_box_publickey_from_secretkey($ephSecret);
    $key   = chan_hkdf($kdev . sodium_crypto_scalarmult($ephSecret, $spPublic), $kid, 'req' . $ephPublic);
    $block = app_req_block(32 + 8 + strlen($json) + strlen($body) + 16);
    $plain = app_frame($json, $body, $block);

    return chan_b64($ephPublic . sodium_crypto_aead_chacha20poly1305_ietf_encrypt($plain, 'a1' . $kid . $ephPublic, str_repeat("\0", 12), $key));
}

function appc_pair_seal(string $code, string $json, string $ephSecret): string
{
    $ephPublic = sodium_crypto_box_publickey_from_secretkey($ephSecret);
    $key   = chan_hkdf($code, APP_PAIR_SALT, 'req' . $ephPublic);
    $block = app_req_block(32 + 8 + strlen($json) + 16);
    $plain = app_frame($json, '', $block);

    return chan_b64($ephPublic . sodium_crypto_aead_chacha20poly1305_ietf_encrypt($plain, 'a1p' . $ephPublic, str_repeat("\0", 12), $key));
}

function appc_open(string $wire, string $ephSecret, ?string $kdev, ?string $kid, ?string $code = null): ?array
{
    $raw = chan_unb64($wire);
    if ($raw === null || strlen($raw) < 32 + 16 + 8) {
        return null;
    }
    $ephPublic = sodium_crypto_box_publickey_from_secretkey($ephSecret);
    $srvEph    = substr($raw, 0, 32);
    $shared    = sodium_crypto_scalarmult($ephSecret, $srvEph);
    if ($code !== null) {
        $key = chan_hkdf($code . $shared, APP_PAIR_SALT, 'res' . $ephPublic);
        $aad = 'a1pr' . $ephPublic . $srvEph;
    } else {
        $key = chan_hkdf($kdev . $shared, (string) $kid, 'res' . $ephPublic);
        $aad = 'a1r' . $kid . $ephPublic . $srvEph;
    }
    $plain = sodium_crypto_aead_chacha20poly1305_ietf_decrypt(substr($raw, 32), $aad, str_repeat("\0", 12), $key);
    if ($plain === false) {
        return null;
    }

    return app_frame_parse($plain);
}
