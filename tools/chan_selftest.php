<?php
declare(strict_types=1);

/**
 * Самопроверка защищённого канала по общим векторам.
 *
 * Гоняется одной командой: `php tools/chan_selftest.php`. Стенда не требует.
 * Те же векторы проходят клиент ПК (Rust) и Android (Go) — если здесь зелено,
 * а на клиенте нет, расхождение в клиенте, и наоборот.
 *
 * Кроме совпадения с векторами проверяется главное свойство канала: НИ ОДНА
 * неудача не должна отличаться от любой другой. Всё, что ниже возвращает null,
 * снаружи обязано выглядеть одинаково.
 */

require __DIR__ . '/../lib/chan.php';

$vectors = json_decode((string) file_get_contents(__DIR__ . '/../lib/chan_vectors.json'), true);
$failed  = 0;

function check(string $name, $got, $want): void
{
    global $failed;

    if ($got === $want) {
        echo "  ок    {$name}\n";

        return;
    }

    $failed++;
    $short = static fn($v) => strlen((string) var_export($v, true)) > 200
        ? substr((string) var_export($v, true), 0, 200) . '…'
        : var_export($v, true);
    echo "  ПРОВАЛ {$name}\n    получено: " . $short($got) . "\n    ожидалось: " . $short($want) . "\n";
}

echo "Самопроверка канала c1\n";

if (!extension_loaded('sodium')) {
    echo "  ПРОВАЛ расширение sodium не загружено — канал работать не будет\n";
    exit(1);
}

$token = $vectors['token'];
$psk   = chan_psk($token);

check('psk', bin2hex($psk), $vectors['psk']);
check('kid', chan_kid($psk, $vectors['epoch']), $vectors['kid']);

$spSecret = hex2bin($vectors['sp_secret']);
$spPublic = sodium_crypto_box_publickey_from_secretkey($spSecret);

check('публичный ключ прослойки', bin2hex($spPublic), $vectors['sp_public']);
check('отпечаток ключа', chan_spid($spPublic), $vectors['spid']);

$at     = (int) json_decode($vectors['request']['plain'], true)['t'];
$lookup = static fn(string $kid): ?string => $kid === $vectors['kid'] ? $token : null;
$keys   = [$vectors['spid'] => $spSecret];

// --- разбор запроса ---------------------------------------------------------

$ctx = chan_open($vectors['kid'], $vectors['spid'], $vectors['request']['blob_pinned'], $lookup, $keys, $at);
check('запрос разобран', $ctx !== null, true);

if ($ctx !== null) {
    check('поля запроса', $ctx['req']['hwid'], json_decode($vectors['request']['plain'], true)['hwid']);
    check('запрос выровнен клиентом', strlen($vectors['request']['plain']) % (int) $vectors['req_pad_block'], 0);

    // Ответ на тех же ключах обязан совпасть с вектором символ в символ.
    $sealed = chan_seal(
        $ctx,
        ['announce' => ['Тест'], 'profile-update-interval' => ['12']],
        $vectors['response']['expect']['config'],
        $spPublic,
        false,
        (int) $vectors['response']['expect']['st'],
        hex2bin($vectors['response']['srv_secret']),
        (int) $vectors['response']['expect']['t']
    );
    check('ответ', $sealed, $vectors['response']['body']);

    // Тело, которого не бывает в UTF-8: json_encode на нём возвращает false,
    // и раньше это был фатал вместо ответа. Теперь тело едет полем body_b64.
    $sealedB = chan_seal(
        $ctx,
        ['announce' => ['Тест'], 'profile-update-interval' => ['12']],
        (string) base64_decode($vectors['response']['expect']['config_binary'], true),
        $spPublic,
        false,
        (int) $vectors['response']['expect']['st'],
        hex2bin($vectors['response']['srv_secret']),
        (int) $vectors['response']['expect']['t']
    );
    check('ответ с двоичным телом', $sealedB, $vectors['response']['body_binary']);
}

// Первый контакт: ключ прослойки ещё не закреплён, DH в деривации не участвует.
$first = chan_open($vectors['kid'], '0', $vectors['request']['blob_first'], $lookup, $keys, $at);
check('первый контакт разобран', $first !== null, true);

// --- всё, что обязано выглядеть одинаково -----------------------------------

check('неизвестная метка', chan_open('AAAAAAAAAAAA', '0', $vectors['request']['blob_first'], static fn() => null, []), null);

check(
    'протухший запрос',
    chan_open($vectors['kid'], '0', $vectors['request']['blob_first'], $lookup, [], 2000000000),
    null
);

check(
    'неизвестный отпечаток ключа',
    chan_open($vectors['kid'], 'ZZZZZZ', $vectors['request']['blob_pinned'], $lookup, $keys, $at),
    null
);

// Вырожденная точка кривой: sodium на ней бросает исключение, и без перехвата
// это был бы 500 вместо отказа — то есть ответ на вопрос «метка живая?».
$lowOrder = chan_b64(str_repeat("\0", 32) . str_repeat("\1", 32));
check('вырожденный ключ в запросе', chan_open($vectors['kid'], $vectors['spid'], $lowOrder, $lookup, $keys, $at), null);
check('вырожденный ключ в ответе', chan_seal(
    ['token' => $token, 'kid' => $vectors['kid'], 'psk' => $psk, 'dh' => '', 'ephPub' => str_repeat("\0", 32), 'nonce' => str_repeat('A', CHAN_NONCE_LEN)],
    [],
    'body',
    $spPublic
), null);

check('мусор вместо шифротекста', chan_open($vectors['kid'], '0', 'AAAA', $lookup, $keys, $at), null);
check('слишком длинный blob', chan_open($vectors['kid'], '0', str_repeat('A', CHAN_MAX_BLOB + 1), $lookup, $keys, $at), null);

// --- разбор пути ------------------------------------------------------------

check('путь', chan_route($vectors['request']['path_pinned']), ['', $vectors['kid'], $vectors['spid'], $vectors['request']['blob_pinned']]);
check('путь со строкой запроса', chan_route('/c1/' . $vectors['kid'] . '/0/AAAA?fmt=clash'), ['', $vectors['kid'], '0', 'AAAA']);
check('путь в подкаталоге', chan_route('/api/sub/c1/' . $vectors['kid'] . '/0/AAAA'), ['/api/sub', $vectors['kid'], '0', 'AAAA']);
check('чужой путь', chan_route('/' . $token), null);

// --- выравнивание ответа ----------------------------------------------------

$ragged = 0;
for ($n = 0; $n < 8000; $n += 137) {
    $out = chan_seal($ctx, ['content-type' => ['text/plain']], str_repeat('x', $n), $spPublic, true);
    if ($out === null || strlen($out) % 4096 !== 0) $ragged++;
}
check('длина ответа кратна 4096', $ragged, 0);

echo $failed === 0 ? "\nВсё сошлось.\n" : "\nПровалов: {$failed}\n";
exit($failed === 0 ? 0 : 1);
