<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }


require __DIR__ . '/../lib/chan.php';

$token = 'a7Kd93mQz1Lp0Xr8';
$psk   = chan_psk($token);
$epoch = 20678;
$kid   = chan_kid($psk, $epoch);

$spSecret  = str_repeat("\x01", 32);
$spPublic  = sodium_crypto_box_publickey_from_secretkey($spSecret);
$ephSecret = str_repeat("\x02", 32);
$ephPublic = sodium_crypto_box_publickey_from_secretkey($ephSecret);
$srvSecret = str_repeat("\x03", 32);

$dh = sodium_crypto_scalarmult($ephSecret, $spPublic);

$head = '{"v":1,"t":1786500000,"n":"AAECAwQFBgcICQoLDA0ODw","hwid":"3f9c1d2e","os":"windows","pad":"';
$plain = $head . str_repeat('.', CHAN_REQ_PAD_BLOCK - strlen($head) - 2) . '"}';
if (strlen($plain) !== CHAN_REQ_PAD_BLOCK) {
    fwrite(STDERR, "внутренняя ошибка: длина запроса " . strlen($plain) . "\n");
    exit(1);
}

$keyReq = chan_hkdf($psk . $dh, $kid, 'req' . $ephPublic);
$cipher = sodium_crypto_aead_chacha20poly1305_ietf_encrypt(
    $plain,
    'c1' . $kid . $ephPublic,
    str_repeat("\0", 12),
    $keyReq
);
$blob = chan_b64($ephPublic . $cipher);

$keyReq0 = chan_hkdf($psk, $kid, 'req' . $ephPublic);
$cipher0 = sodium_crypto_aead_chacha20poly1305_ietf_encrypt(
    $plain,
    'c1' . $kid . $ephPublic,
    str_repeat("\0", 12),
    $keyReq0
);

$ctx = [
    'token'  => $token,
    'kid'    => $kid,
    'psk'    => $psk,
    'dh'     => $dh,
    'ephPub' => $ephPublic,
    'nonce'  => 'AAECAwQFBgcICQoLDA0ODw',
];
$meta     = ['announce' => ['Тест'], 'profile-update-interval' => ['12']];
$body     = "proxies: []\n";
$sealedAt = 1786500000;
$status   = 200;
$sealed   = chan_seal($ctx, $meta, $body, $spPublic, false, $status, $srvSecret, $sealedAt);

$binary   = "proxies: \xff\xfe\n";
$sealedB  = chan_seal($ctx, $meta, $binary, $spPublic, false, $status, $srvSecret, $sealedAt);

$repHead  = '{"v":1,"t":1786500000,"n":"BAECAwQFBgcICQoLDA0ODw","op":"rep","hwid":"3f9c1d2e","os":"windows","pad":"';
$repPlain = $repHead . str_repeat('.', CHAN_REQ_PAD_BLOCK - strlen($repHead) - 2) . '"}';
$repCipher = sodium_crypto_aead_chacha20poly1305_ietf_encrypt(
    $repPlain,
    'c1' . $kid . $ephPublic,
    str_repeat("\0", 12),
    $keyReq
);
$repBlob = chan_b64($ephPublic . $repCipher);
$repJson = '{"v":1,"platform":"pc","client":"0.0.0","from":1786496400,"to":1786500000,'
    . '"nodes":{"n1":{"name":"Узел","type":"vless","server":"node.example.com","port":443}},'
    . '"networks":{"0123456789abcdef":{"kind":"wifi","hours":[{"h":1786496400,"ip4":"203.0.113.7",'
    . '"ping":{"n1":{"n":3,"fail":1,"b":[1,1,0,0,0,0]}},"use":{"n1":{"up":10,"down":20,"min":5}},'
    . '"freeze":{"n1":{"verdict":"ok","status":200,"at":1786497000}}}]}}}';
$repGz   = gzencode($repJson, 9);
$repCtx  = $ctx + [];
$repBody = chan_report_seal($repCtx, $repGz);

if ($sealed === null || $sealedB === null) {
    fwrite(STDERR, "внутренняя ошибка: ответ не собрался\n");
    exit(1);
}

$vectors = [
    'note'          => 'протокол c1, генератор tools/chan_vectors.php',
    'token'         => $token,
    'psk'           => bin2hex($psk),
    'epoch'         => $epoch,
    'kid'           => $kid,
    'sp_secret'     => bin2hex($spSecret),
    'sp_public'     => bin2hex($spPublic),
    'spid'          => chan_spid($spPublic),
    'eph_secret'    => bin2hex($ephSecret),
    'eph_public'    => bin2hex($ephPublic),
    'dh'            => bin2hex($dh),
    'req_pad_block' => CHAN_REQ_PAD_BLOCK,
    'nonce_len'     => CHAN_NONCE_LEN,
    'request'       => [
        'plain'       => $plain,
        'key_pinned'  => bin2hex($keyReq),
        'blob_pinned' => $blob,
        'key_first'   => bin2hex($keyReq0),
        'blob_first'  => chan_b64($ephPublic . $cipher0),
        'path_pinned' => '/c1/' . $kid . '/' . chan_spid($spPublic) . '/' . $blob,
    ],
    'report'        => [
        'pad_block'  => CHAN_REP_PAD_BLOCK,
        'plain'      => $repPlain,
        'blob'       => $repBlob,
        'path'       => '/c1/' . $kid . '/' . chan_spid($spPublic) . '/' . $repBlob,
        'key'        => bin2hex(chan_report_key($repCtx)),
        'json'       => $repJson,
        'gzip'       => base64_encode($repGz),
        'frame_len'  => strlen(chan_report_frame($repGz)),
        'body'       => $repBody,
    ],
    'response'      => [
        'srv_secret' => bin2hex($srvSecret),
        'body'       => $sealed,
        'body_binary' => $sealedB,
        'expect'     => [
            'meta_announce' => 'Тест',
            'config'        => $body,
            'config_binary' => base64_encode($binary),
            'nonce'         => 'AAECAwQFBgcICQoLDA0ODw',
            't'             => $sealedAt,
            'st'            => $status,
        ],
    ],
];

file_put_contents(
    __DIR__ . '/../lib/chan_vectors.json',
    json_encode($vectors, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"
);

echo "kid = {$kid}\nspid = " . chan_spid($spPublic) . "\nзапрос = " . strlen($plain) . " байт\nответ = " . strlen($sealed) . " символов\n";
