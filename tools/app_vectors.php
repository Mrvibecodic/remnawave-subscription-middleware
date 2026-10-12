<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require __DIR__ . '/../lib/chan.php';
require __DIR__ . '/../lib/appchan.php';
require __DIR__ . '/app_client.php';

$spSecret  = str_repeat("\x11", 32);
$spPublic  = sodium_crypto_box_publickey_from_secretkey($spSecret);
$devSecret = str_repeat("\x22", 32);
$devPublic = sodium_crypto_box_publickey_from_secretkey($devSecret);
$didRaw    = str_repeat("\x33", 16);
$did       = chan_b64($didRaw);
$shared    = sodium_crypto_scalarmult($devSecret, $spPublic);
$kdev      = app_kdev($shared, $didRaw);
$epoch     = 20740;
$at        = $epoch * 86400 + 3600;
$kid       = app_kid($kdev, $epoch);
$spid      = chan_spid($spPublic);

$ephSecret = str_repeat("\x44", 32);
$srvSecret = str_repeat("\x55", 32);
$nonce     = 'AAECAwQFBgcICQoLDA0ODw';

$hiJson = app_json(['v' => 1, 't' => $at, 'n' => $nonce, 'op' => 'hi', 'name' => 'Pixel 8', 'os' => 'android', 'osv' => '15', 'app' => '0.1.0']);
$hiWire = appc_seal($kdev, $kid, $spPublic, $hiJson, '', $ephSecret);

$webJson = app_json(['v' => 1, 't' => $at, 'n' => 'BAECAwQFBgcICQoLDA0ODw', 'op' => 'web', 'm' => 'POST', 'p' => '/admin/index.php?tab=app',
    'h' => ['content-type' => 'application/x-www-form-urlencoded', 'cookie' => 'submw_admin=abc']]);
$webBody = "csrf=x&note=%D0%A2%D0%B5%D1%81%D1%82\x00\xff";
$webWire = appc_seal($kdev, $kid, $spPublic, $webJson, $webBody, $ephSecret);

$bigBody = str_repeat("\xA5", 5000);
$bigWire = appc_seal($kdev, $kid, $spPublic, $webJson, $bigBody, $ephSecret);

$ctx = ['kid' => $kid, 'kdev' => $kdev, 'eph' => sodium_crypto_box_publickey_from_secretkey($ephSecret), 'nonce' => $nonce];
$resFields = ['sp' => chan_b64($spPublic), 'x' => 'Тест'];
$resBody   = "ok\x00\xff";
$resWire   = app_seal($ctx, $resFields, $resBody, 200, $srvSecret, $at);

$code      = 'K7M3Q9X2ABCD';
$pairEph   = str_repeat("\x66", 32);
$pairSrv   = str_repeat("\x77", 32);
$pairJson  = app_json(['v' => 1, 't' => $at, 'n' => 'CAECAwQFBgcICQoLDA0ODw', 'op' => 'pair', 'dpub' => chan_b64($devPublic), 'name' => 'Pixel 8', 'os' => 'android', 'osv' => '15', 'app' => '0.1.0']);
$pairWire  = appc_pair_seal($code, $pairJson, $pairEph);
$pairCtx   = ['code' => $code, 'eph' => sodium_crypto_box_publickey_from_secretkey($pairEph), 'nonce' => 'CAECAwQFBgcICQoLDA0ODw'];
$pairRes   = app_seal($pairCtx, ['did' => $did, 'sp' => chan_b64($spPublic)], '', 200, $pairSrv, $at);

if ($resWire === null || $pairRes === null) {
    fwrite(STDERR, "внутренняя ошибка: ответ не собрался\n");
    exit(1);
}

$vectors = [
    'note'       => 'протокол a1, генератор tools/app_vectors.php',
    'sp_secret'  => bin2hex($spSecret),
    'sp_public'  => bin2hex($spPublic),
    'spid'       => $spid,
    'dev_secret' => bin2hex($devSecret),
    'dev_public' => bin2hex($devPublic),
    'did'        => $did,
    'shared'     => bin2hex($shared),
    'kdev'       => bin2hex($kdev),
    'epoch'      => $epoch,
    'at'         => $at,
    'kid'        => $kid,
    'path'       => '/a1/' . $kid . '/' . $spid,
    'eph_secret' => bin2hex($ephSecret),
    'request'    => [
        'hi_json'  => $hiJson,
        'hi_wire'  => $hiWire,
        'web_json' => $webJson,
        'web_body' => base64_encode($webBody),
        'web_wire' => $webWire,
        'big_body' => base64_encode($bigBody),
        'big_wire' => $bigWire,
    ],
    'response'   => [
        'srv_secret' => bin2hex($srvSecret),
        'fields'     => $resFields,
        'body'       => base64_encode($resBody),
        'st'         => 200,
        'wire'       => $resWire,
    ],
    'pair'       => [
        'code'       => $code,
        'code_shown' => app_code_fmt($code),
        'eph_secret' => bin2hex($pairEph),
        'json'       => $pairJson,
        'wire'       => $pairWire,
        'srv_secret' => bin2hex($pairSrv),
        'res_wire'   => $pairRes,
        'path'       => '/a1/0/0',
    ],
];

file_put_contents(
    __DIR__ . '/../lib/app_vectors.json',
    json_encode($vectors, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"
);

echo "kid = {$kid}\nspid = {$spid}\nзапрос hi = " . strlen($hiWire) . " символов\nответ = " . strlen($resWire) . " символов\n";
