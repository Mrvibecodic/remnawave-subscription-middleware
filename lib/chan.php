<?php
declare(strict_types=1);

/**
 * Защищённый канал клиент ↔ прослойка (протокол c1). Ядро.
 *
 * Задача: посредник, который терминирует TLS (CDN), не должен видеть ни адрес
 * подписки, ни заголовки запроса (`x-hwid` и карточка устройства), ни заголовки
 * ответа (срок, трафик, лимит устройств), ни тело.
 *
 * Секрет, из которого всё выводится, — САМ ТОКЕН ПОДПИСКИ. Клиент его в сеть
 * не отправляет никогда: он выводит из него ключ и шлёт запрос по адресу
 * `<префикс>/c1/<kid>/<spid>/<blob>`, где `kid` — метка, меняющаяся каждые сутки.
 *
 * Файл сознательно не знает ничего про БД и про конвейер прослойки: поиск
 * токена по метке передаётся колбэком, ключи — массивом. Всё, что связано с
 * базой, настройками и врезками, лежит в `chanmw.php`.
 *
 * Криптография — только ext-sodium и нативный hash_hkdf, своей нет.
 * Набор один и не согласуется: X25519 + HKDF-SHA256 + ChaCha20-Poly1305.
 *
 * ГЛАВНОЕ ПРАВИЛО ФАЙЛА: ни одна ветка не бросает исключение и не падает.
 * Любая неудача — это `null`, потому что снаружи она обязана выглядеть ровно
 * так же, как обращение по мусорному пути. Отличимый 500 — это оракул: по нему
 * посредник узнаёт, что подсмотренная метка принадлежит живой подписке.
 */

const CHAN_VERSION   = 1;
const CHAN_SALT      = 'clod-chan-v1';
const CHAN_SKEW      = 300;   // допустимый разбег часов, секунд
const CHAN_NONCE_TTL = 600;   // сколько помним метку запроса, секунд — ровно два окна разбега
const CHAN_PAD_BLOCK = 3072;  // сырой ответ дополняем до кратного — в base64url это ровно 4096 символов
const CHAN_MAX_BLOB  = 4096;  // защита от мусора в пути
const CHAN_NONCE_LEN = 22;    // 16 байт в base64url без выравнивания — ровно столько шлют клиенты
const CHAN_FIELD_MAX = 1024;  // потолок на каждое поле карточки устройства

/**
 * Блок выравнивания ЗАПРОСА. Дополняет клиент — полем `pad` внутри JSON;
 * прослойка это поле просто не читает.
 *
 * Константа здесь не работает, а фиксирует договорённость: без выравнивания
 * длина адреса выдаёт длину карточки устройства, то есть модель телефона и
 * момент смены прошивки — ровно то, что канал прячет.
 */
const CHAN_REQ_PAD_BLOCK = 512;

/** base64url без выравнивания — единственный вид кодирования в протоколе. */
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

/** Ключ подписки. Токен берётся как есть, в ASCII, без нормализации. */
function chan_psk(string $token): string
{
    return hash_hkdf('sha256', $token, 32, 'psk', CHAN_SALT);
}

/** Сутки как номер эпохи: метка `kid` живёт ровно эпоху. */
function chan_epoch(?int $now = null): int
{
    return intdiv($now ?? time(), 86400);
}

/**
 * Метка подписки на сутки. Девять байт — двенадцать символов base64url.
 *
 * Меняется каждые сутки, поэтому посредник не получает стабильного
 * идентификатора: два запроса одного человека в разные дни для него не связаны.
 */
function chan_kid(string $psk, int $epoch): string
{
    return chan_b64(substr(hash_hmac('sha256', 'kid|' . $epoch, $psk, true), 0, 9));
}

/** Три метки — вчера, сегодня, завтра. Закрывает часовые пояса и сдвиг часов. */
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

/** Короткий отпечаток ключа прослойки: им клиент говорит, каким ключом считал DH. */
function chan_spid(string $publicKey): string
{
    return substr(chan_b64(hash('sha256', $publicKey, true)), 0, 6);
}

/** Новая пара ключей прослойки. Секретная половина не покидает сервер. */
function chan_keygen(): array
{
    $secret = random_bytes(32);

    return [$secret, sodium_crypto_box_publickey_from_secretkey($secret)];
}

function chan_hkdf(string $ikm, string $salt, string $info): string
{
    return hash_hkdf('sha256', $ikm, 32, $info, $salt);
}

/**
 * X25519 без исключений.
 *
 * `sodium_crypto_scalarmult()` бросает SodiumException, когда общий секрет
 * вырождается в ноль, — а такой публичный ключ ничего не стоит подставить в
 * запрос. Непойманное исключение превратилось бы в 500 там, где обязан быть
 * неотличимый отказ, и дало бы посреднику ответ на вопрос «эта метка живая?».
 */
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

/**
 * Поле карточки устройства из расшифрованного запроса — к безопасному виду.
 *
 * Раньше эти значения приходили заголовками, и их чистил веб-сервер. Теперь
 * они приезжают внутри JSON, то есть без единой проверки, а уходят в заголовки
 * запроса к панели: перевод строки внутри значения — это склейка заголовков.
 */
function chan_scrub($value): string
{
    if (!is_string($value)) {
        return '';
    }

    return substr(strtr($value, ["\r" => '', "\n" => '', "\0" => '']), 0, CHAN_FIELD_MAX);
}

/**
 * Разбор запроса.
 *
 * $lookup(string $kid): ?string — отдаёт токен подписки по метке (или null).
 * $keys — [spid => secretKey] известные ключи прослойки: текущий и, при
 * ротации, предыдущий.
 *
 * Возвращает контекст для ответа либо null. Наружу причина не уходит никогда:
 * любая неудача обязана выглядеть одинаково — как мусорный путь. Короткая
 * машинная метка кладётся в $why и нужна ровно одному месту — диагностическому
 * журналу в админке, который провайдер включает руками на время разбора.
 */
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

    // Секрет DH с долгоживущим ключом прослойки участвует, только если клиент
    // этот ключ уже закрепил — на первом контакте его ещё нет.
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

    // Метка времени: окно симметричное, потому что врут часы обеих сторон.
    $ts = (int)($req['t'] ?? 0);
    if ($ts <= 0 || abs($now - $ts) > CHAN_SKEW) {
        $why = 'time';

        return null;
    }

    // Метка запроса — ровно 16 байт в base64url. Проверка «не короче 16
    // символов» пропускала 96 бит вместо 128 и не имела верхней границы,
    // хотя дальше эта строка уходит в таблицу повторов как первичный ключ.
    $nonce = is_string($req['n'] ?? null) ? $req['n'] : '';
    if (strlen($nonce) !== CHAN_NONCE_LEN || preg_match('~[^A-Za-z0-9_-]~', $nonce)) {
        $why = 'nonce';

        return null;
    }

    // Карточка устройства дальше поедет в заголовки запроса к панели.
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
        // Открытый текст запроса как он был внутри шифра, до всякой чистки.
        // Нужен диагностическому журналу: пересобранный из массива JSON — это
        // уже не то, что прислал клиент, и разбирать по нему нечего.
        'plain'  => $plain,
    ];
}

/**
 * Заголовки опознания из расшифрованного запроса — в том виде, в каком их ждёт
 * остальной конвейер прослойки.
 *
 * Отдельная функция, чтобы врезка в `index.php` не собирала их руками: забыть
 * здесь одно имя — значит тихо сломать лимит устройств.
 */
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

/** Строка запроса из расшифрованного запроса: `fmt=clash` и прочее, без `?`. */
function chan_request_query(array $ctx): string
{
    return (string)($ctx['req']['q'] ?? '');
}

/**
 * Сборка ответа.
 *
 * $meta — заголовки, которые в открытом режиме ушли бы снаружи; здесь они
 * едут внутрь шифра. $status — код ответа, который в открытом режиме увидел бы
 * клиент: снаружи на защищённом пути всегда 200, иначе код сам по себе
 * рассказывал бы посреднику, чем кончилось дело. $spPublic — текущий публичный
 * ключ прослойки: клиент закрепит его при первом успехе и дальше будет считать
 * с ним DH.
 *
 * Эфемерная пара сервера делается на каждый ответ: даже утёкший позже токен
 * не расшифрует записанный ответ.
 *
 * Возвращает null, если ответ собрать не удалось; вызывающий обязан в этом
 * случае отдать ровно то же, что отдаёт мусорному пути.
 */
function chan_seal(array $ctx, array $meta, string $body, string $spPublic, bool $pad = true, int $status = 200, ?string $ephSecret = null, ?int $now = null): ?string
{
    // $ephSecret задаётся только тестами: в бою пара делается на каждый ответ.
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
        'n'    => $ctx['nonce'],   // эхо: ответ привязан к конкретному запросу
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
        // Дополняем ДО шифрования: снаружи должен меняться только размер
        // шифротекста, иначе по длине ответа виден размер конфига, то есть
        // число серверов у человека.
        //
        // Считаем точно: 48 байт — эфемерный ключ сервера и метка целостности,
        // 9 символов — сам ключ `,"pad":""` в JSON. Если до кратности осталось
        // меньше, чем эти 9, добираем целый блок: короче ключа не дополнить.
        // Блок выбран так, чтобы в base64url длина ответа была кратна 4096.
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

    // На проводе — base64url, а не сырые байты: так ответ проходит через любой
    // CDN и WAF, которые двоичное тело на текстовом пути иногда портят или
    // режут по типу содержимого. Цена — треть объёма, и она теряется на фоне
    // выравнивания.
    return chan_b64($public . $cipher);
}

/**
 * JSON ответа с запасным путём для не-UTF-8.
 *
 * Прослойка отдаёт тело подписки байт в байт, а JSON так не умеет: одного
 * битого байта в теле или в заголовке панели хватает, чтобы `json_encode()`
 * вернул `false`. Под `strict_types` это дальше превращалось в TypeError, то
 * есть в 500 вместо неотличимого отказа. Поэтому тело в таком случае едет
 * полем `body_b64`, а значения заголовков — в base64 с префиксом `=?b64?`.
 */
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

    // Битое имя заголовка — редкость, и добивать нечем: отдаём тело без меты.
    $payload['meta'] = (object)[];
    $json = json_encode($payload, $flags);

    return $json === false ? null : $json;
}

/** Проверка кодировки без mbstring: расширения может не быть. */
function chan_utf8(string $s): bool
{
    return $s === '' || preg_match('//u', $s) === 1;
}

/**
 * Разбор пути. Возвращает [prefix, kid, spid, blob] либо null, если это не наш путь.
 *
 * `prefix` — всё, что стояло до `/c1/`: подписка может жить в подкаталоге или
 * приезжать через `api/sub/`, и восстановленный адрес обязан это сохранить,
 * иначе после подмены пути конвейер увидит не тот запрос, что в открытом режиме.
 *
 * Строка запроса и якорь срезаются до разбора: иначе `?fmt=clash` в адресе
 * ронял маршрут в null и защищённый запрос молча уезжал на origin как мусор.
 */
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
