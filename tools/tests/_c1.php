<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Клиентская сторона канала c1 для тестов (docs/chan.md): запрос, ответ, отчёт.

function c1_request(string $token, ?string $spPublic, array $fields, ?int $now = null): array
{
    $now   = $now ?? time();
    $psk   = chan_psk($token);
    $kid   = chan_kid($psk, chan_epoch($now));
    [$eph, $ephPub] = chan_keygen();
    $dh    = $spPublic !== null ? sodium_crypto_scalarmult($eph, $spPublic) : '';
    $nonce = chan_b64(random_bytes(16));
    $json  = json_encode(['v' => 1, 't' => $now, 'n' => $nonce] + $fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $key   = chan_hkdf($psk . $dh, $kid, 'req' . $ephPub);
    $ct    = sodium_crypto_aead_chacha20poly1305_ietf_encrypt($json, 'c1' . $kid . $ephPub, str_repeat("\0", 12), $key);
    $spid  = $spPublic !== null ? chan_spid($spPublic) : '0';
    $ctx   = ['kid' => $kid, 'psk' => $psk, 'dh' => $dh, 'eph' => $eph, 'ephPub' => $ephPub, 'nonce' => $nonce];

    return ['/c1/' . $kid . '/' . $spid . '/' . chan_b64($ephPub . $ct), $ctx];
}

function c1_open(array $ctx, string $wire): ?array
{
    $raw = chan_unb64(trim($wire));
    if ($raw === null || strlen($raw) < 48) return null;
    $sEph   = substr($raw, 0, 32);
    $shared = sodium_crypto_scalarmult($ctx['eph'], $sEph);
    $key    = chan_hkdf($ctx['psk'] . $shared . $ctx['dh'], $ctx['kid'], 'res' . $ctx['ephPub']);
    $plain  = sodium_crypto_aead_chacha20poly1305_ietf_decrypt(substr($raw, 32), 'c1r' . $ctx['kid'] . $ctx['ephPub'] . $sEph, str_repeat("\0", 12), $key);
    if ($plain === false) return null;
    $p = json_decode($plain, true);

    return is_array($p) && ($p['n'] ?? '') === $ctx['nonce'] ? $p : null;
}

function c1_report(array $ctx, string $json): string
{
    return chan_report_seal($ctx, (string) gzencode($json, 6));
}
