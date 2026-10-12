<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require __DIR__ . '/../lib/chan.php';
require __DIR__ . '/../lib/appchan.php';
require __DIR__ . '/app_client.php';

$v      = json_decode((string) file_get_contents(__DIR__ . '/../lib/app_vectors.json'), true);
$failed = 0;

function check(string $name, $got, $want): void
{
    global $failed;

    if ($got === $want) {
        echo "  ок    {$name}\n";

        return;
    }

    $failed++;
    $short = static fn($x) => strlen((string) var_export($x, true)) > 200
        ? substr((string) var_export($x, true), 0, 200) . '…'
        : var_export($x, true);
    echo "  ПРОВАЛ {$name}\n    получено: " . $short($got) . "\n    ожидалось: " . $short($want) . "\n";
}

echo "Самопроверка канала приложения a1\n";

if (!extension_loaded('sodium')) {
    echo "  ПРОВАЛ расширение sodium не загружено — канал работать не будет\n";
    exit(1);
}

$spSecret = hex2bin($v['sp_secret']);
$spPublic = sodium_crypto_box_publickey_from_secretkey($spSecret);
$devPub   = hex2bin($v['dev_public']);
$didRaw   = (string) chan_unb64($v['did']);
$shared   = (string) chan_x25519($spSecret, $devPub);
$kdev     = app_kdev($shared, $didRaw);
$at       = (int) $v['at'];

check('публичный ключ прослойки', bin2hex($spPublic), $v['sp_public']);
check('отпечаток ключа', chan_spid($spPublic), $v['spid']);
check('общий секрет со стороны прослойки', bin2hex($shared), $v['shared']);
check('ключ устройства', bin2hex($kdev), $v['kdev']);
check('метка суток', app_kid($kdev, (int) $v['epoch']), $v['kid']);
check('путь', app_route($v['path']), ['', $v['kid'], $v['spid']]);

$keys   = [$v['spid'] => $spSecret];
$lookup = static function (string $kid, string $secret) use ($v, $devPub, $didRaw): ?array {
    $kd = app_kdev((string) chan_x25519($secret, $devPub), $didRaw);
    for ($e = (int) $v['epoch'] - 1; $e <= (int) $v['epoch'] + 1; $e++) {
        if (hash_equals(app_kid($kd, $e), $kid)) {
            return ['kdev' => $kd, 'did' => $v['did']];
        }
    }

    return null;
};

$ctx = app_open($v['kid'], $v['spid'], $v['request']['hi_wire'], $lookup, $keys, $at);
check('запрос hi разобран', $ctx !== null, true);
if ($ctx !== null) {
    check('операция', $ctx['req']['op'], 'hi');
    check('поле запроса', $ctx['req']['name'], 'Pixel 8');
    check('тело пустое', $ctx['body'], '');
    check('запрос hi выровнен на 512', strlen((string) chan_unb64($v['request']['hi_wire'])) % 512, 0);

    $res = app_seal($ctx, $v['response']['fields'], (string) base64_decode($v['response']['body'], true), 200, hex2bin($v['response']['srv_secret']), $at);
    check('ответ', $res, $v['response']['wire']);
    check('ответ кратен 4096 символам', strlen((string) $res) % 4096, 0);
    $back = appc_open((string) $res, hex2bin($v['eph_secret']), $kdev, $v['kid']);
    check('ответ читается клиентом', $back !== null, true);
    if ($back !== null) {
        check('поле ответа', $back[0]['x'], 'Тест');
        check('код ответа', $back[0]['st'], 200);
        check('эхо метки запроса', $back[0]['n'], $ctx['nonce']);
        check('двоичное тело ответа', $back[1], (string) base64_decode($v['response']['body'], true));
    }
}

$web = app_open($v['kid'], $v['spid'], $v['request']['web_wire'], $lookup, $keys, $at);
check('запрос web с телом', $web !== null && $web['body'] === (string) base64_decode($v['request']['web_body'], true), true);
check('заголовок внутри запроса', $web['req']['h']['cookie'] ?? null, 'submw_admin=abc');

$big = app_open($v['kid'], $v['spid'], $v['request']['big_wire'], $lookup, $keys, $at);
check('большой запрос', $big !== null && strlen($big['body']) === 5000, true);
check('большой запрос выровнен на 3072', strlen((string) chan_unb64($v['request']['big_wire'])) % 3072, 0);

check('чужая метка', app_open('AAAAAAAAAAAA', $v['spid'], $v['request']['hi_wire'], $lookup, $keys, $at), null);
check('чужой отпечаток', app_open($v['kid'], 'ZZZZZZ', $v['request']['hi_wire'], $lookup, $keys, $at), null);
check('протухший запрос', app_open($v['kid'], $v['spid'], $v['request']['hi_wire'], $lookup, $keys, $at + 301), null);
$bad = $v['request']['hi_wire'];
$bad[60] = $bad[60] === 'A' ? 'B' : 'A';
check('подменённый запрос', app_open($v['kid'], $v['spid'], $bad, $lookup, $keys, $at), null);
check('мусор', app_open($v['kid'], $v['spid'], 'AAAA', $lookup, $keys, $at), null);
$low = chan_b64(str_repeat("\0", 32) . str_repeat("\1", 64));
check('вырожденный ключ', app_open($v['kid'], $v['spid'], $low, $lookup, $keys, $at), null);
check('слишком длинный запрос', app_open($v['kid'], $v['spid'], str_repeat('A', app_max_wire() + 1), $lookup, $keys, $at), null);
$other = sodium_crypto_box_publickey_from_secretkey(str_repeat("\x09", 32));
$why = null;
$lookOther = static fn(string $kid, string $secret) => $lookup($kid, $secret) === null ? null : ['kdev' => app_kdev((string) chan_x25519($secret, $other), $didRaw)];
check('чужой ключ устройства', app_open($v['kid'], $v['spid'], $v['request']['hi_wire'], $lookOther, $keys, $at, $why), null);
check('причина — не расшифровалось', $why, 'aead');

$p   = $v['pair'];
$pc  = app_pair_open($p['wire'], ['ZZZZZZZZZZZZ', $p['code']], $at);
check('сопряжение разобрано', $pc !== null, true);
if ($pc !== null) {
    check('ключ телефона из сопряжения', bin2hex($pc['dpub']), $v['dev_public']);
    check('код сопряжения', $pc['code'], $p['code']);
    $pr = app_seal($pc, ['did' => $v['did'], 'sp' => chan_b64($spPublic)], '', 200, hex2bin($p['srv_secret']), $at);
    check('ответ сопряжения', $pr, $p['res_wire']);
    $pb = appc_open((string) $pr, hex2bin($p['eph_secret']), null, null, $p['code']);
    check('ответ сопряжения читается', $pb[0]['did'] ?? null, $v['did']);
}
check('неверный код', app_pair_open($p['wire'], ['ZZZZZZZZZZZZ'], $at), null);
check('сопряжение протухло', app_pair_open($p['wire'], [$p['code']], $at + 600), null);
check('путь сопряжения', app_route($p['path']), ['', '0', '0']);
check('путь с префиксом', app_route('/sub/a1/' . $v['kid'] . '/' . $v['spid'] . '?x=1'), ['/sub', $v['kid'], $v['spid']]);
check('половинчатый путь', app_route('/a1/0/' . $v['spid']), null);
check('чужой путь', app_route('/a1/' . $v['kid']), null);

check('код с дефисами', app_code_norm($p['code_shown']), $p['code']);
check('код строчными и с похожими буквами', app_code_norm('k7m3-q9x2-abcd'), 'K7M3Q9X2ABCD');
check('I и O читаются как 1 и 0', app_code_norm('IOIO-IOIO-IOIO'), '101010101010');
check('буква U в коде', app_code_norm('UUUU-UUUU-UUUU'), null);
check('короткий код', app_code_norm('ABCD'), null);
check('новый код в алфавите', app_code_norm(app_code_new()) !== null, true);

$ragged = 0;
for ($n = 0; $n < 9000; $n += 211) {
    $out = app_seal($ctx ?? ['kid' => $v['kid'], 'kdev' => $kdev, 'eph' => $other, 'nonce' => 'x'], ['h' => []], str_repeat('x', $n));
    if ($out === null || strlen($out) % 4096 !== 0) $ragged++;
}
check('длина ответа кратна 4096', $ragged, 0);
check('рамка: обрезанная длина', app_frame_parse(pack('N', 100) . '{}'), null);
check('рамка: не JSON', app_frame_parse(pack('N', 2) . 'xx' . pack('N', 0)), null);

echo $failed === 0 ? "\nВсё сошлось.\n" : "\nПровалов: {$failed}\n";
exit($failed === 0 ? 0 : 1);
