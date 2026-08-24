<?php

function ensure_grace_table() {
    static $done = false;
    if ($done) return;
    $done = true;
    if (!($p = db())) return;
    try {
        if (db_driver() === 'mysql') {
            $p->exec("CREATE TABLE IF NOT EXISTS grace_users (
                short_uuid VARCHAR(191) NOT NULL, user_uuid VARCHAR(191) NOT NULL, username VARCHAR(191) NULL,
                orig_squads MEDIUMTEXT NULL, orig_traffic_bytes BIGINT NOT NULL DEFAULT 0,
                orig_traffic_strategy VARCHAR(32) NOT NULL DEFAULT 'NO_RESET', orig_expire VARCHAR(40) NULL,
                orig_hwid_limit INT NULL, orig_external_squad VARCHAR(191) NULL, grace_until INT NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (short_uuid)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } else {
            $p->exec("CREATE TABLE IF NOT EXISTS grace_users (
                short_uuid TEXT NOT NULL PRIMARY KEY, user_uuid TEXT NOT NULL, username TEXT NULL,
                orig_squads TEXT NULL, orig_traffic_bytes INTEGER NOT NULL DEFAULT 0,
                orig_traffic_strategy TEXT NOT NULL DEFAULT 'NO_RESET', orig_expire TEXT NULL,
                orig_hwid_limit INTEGER NULL, orig_external_squad TEXT NULL, grace_until INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )");
        }
    } catch (Throwable $e) { error_log('submw grace table: ' . $e->getMessage()); }
    try { $p->exec('ALTER TABLE grace_users ADD COLUMN orig_external_squad ' . (db_driver() === 'mysql' ? 'VARCHAR(191)' : 'TEXT') . ' NULL'); } catch (Throwable $e) {}
}

function grace_iso($ts) { return gmdate('Y-m-d\TH:i:s.000\Z', (int) $ts); }

function grace_announce_normalize($raw) {
    $raw   = str_replace(["\r\n", "\r"], "\n", (string) $raw);
    $lines = array_map(fn($l) => trim($l), explode("\n", $raw));
    while ($lines && $lines[0] === '') array_shift($lines);
    while ($lines && end($lines) === '') array_pop($lines);
    return mb_substr(implode("\n", $lines), 0, 200);
}

function grace_find($short) {
    ensure_grace_table();
    if (!($p = db()) || $short === '') return null;
    try {
        $st = $p->prepare("SELECT * FROM grace_users WHERE short_uuid = ?");
        $st->execute([$short]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    } catch (Throwable $e) { return null; }
}

function grace_delete($short) {
    if (!($p = db()) || $short === '') return;
    try { $p->prepare("DELETE FROM grace_users WHERE short_uuid = ?")->execute([$short]); }
    catch (Throwable $e) {}
}

function grace_save($short, $uuid, $username, array $squads, $bytes, $strategy, $orig_expire, $hwid_limit, $orig_external_squad, $grace_until) {
    ensure_grace_table();
    if (!($p = db())) return;
    try {
        $cols = "INSERT INTO grace_users (short_uuid, user_uuid, username, orig_squads, orig_traffic_bytes, orig_traffic_strategy, orig_expire, orig_hwid_limit, orig_external_squad, grace_until) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ";
        if (db_driver() === 'mysql') {
            $st = $p->prepare($cols . "ON DUPLICATE KEY UPDATE user_uuid=VALUES(user_uuid), username=VALUES(username), orig_squads=VALUES(orig_squads), orig_traffic_bytes=VALUES(orig_traffic_bytes), orig_traffic_strategy=VALUES(orig_traffic_strategy), orig_expire=VALUES(orig_expire), orig_hwid_limit=VALUES(orig_hwid_limit), orig_external_squad=VALUES(orig_external_squad), grace_until=VALUES(grace_until)");
        } else {
            $st = $p->prepare($cols . "ON CONFLICT(short_uuid) DO UPDATE SET user_uuid=excluded.user_uuid, username=excluded.username, orig_squads=excluded.orig_squads, orig_traffic_bytes=excluded.orig_traffic_bytes, orig_traffic_strategy=excluded.orig_traffic_strategy, orig_expire=excluded.orig_expire, orig_hwid_limit=excluded.orig_hwid_limit, orig_external_squad=excluded.orig_external_squad, grace_until=excluded.grace_until");
        }
        $st->execute([$short, $uuid, $username, json_encode(array_values($squads)), (int) $bytes, (string) $strategy, (string) $orig_expire, ($hwid_limit === null ? null : (int) $hwid_limit), ($orig_external_squad === null ? null : (string) $orig_external_squad), (int) $grace_until]);
    } catch (Throwable $e) { error_log('submw grace save: ' . $e->getMessage()); }
}

function grace_squads_from_user($u) {
    $out = [];
    foreach (($u['activeInternalSquads'] ?? []) as $s) {
        if (is_array($s) && !empty($s['uuid'])) $out[] = (string) $s['uuid'];
        elseif (is_string($s) && $s !== '')     $out[] = $s;
    }
    return $out;
}

// --- Идентификатор пользователя в строке грейса ---
//
// В колонке user_uuid лежит идентификатор, которым панель пользовалась в момент
// ухода в грейс: UUID (панель 2.x) или числовой id (панель 3.x). Что именно —
// однозначно видно по самому значению, UUID никогда не состоит из одних цифр,
// поэтому отдельная колонка под тип не нужна (см. rw_ref_coerce в lib/api.php).
//
// Между уходом в грейс и возвратом админ мог обновить панель до 3.x — тогда
// сохранённый UUID протух. Такие строки чиним лениво: если точно знаем, что панель
// мажора 3, перерезолвим id по short_uuid заранее; если версия неизвестна — ловим
// 400/404 от PATCH и перерезолвим по факту (см. grace_patch).

function grace_set_ref($short, $ref) {
    if (!($p = db()) || (string) $short === '' || !rw_ref_ok($ref)) return;
    try { $p->prepare('UPDATE grace_users SET user_uuid = ? WHERE short_uuid = ?')->execute([(string) $ref['val'], (string) $short]); }
    catch (Throwable $e) { error_log('submw grace set ref: ' . $e->getMessage()); }
}

// Перезапрашивает пользователя по short_uuid и переписывает идентификатор в строке.
function grace_ref_resolve($short, &$err = '', &$http_code = 0) {
    $err = ''; $http_code = 0;
    $short = (string) $short;
    if ($short === '') { $err = 'Пустой shortUuid'; return null; }
    $u = remnawave_get_user_by_short($short, $err, $http_code);
    if (!is_array($u)) return null;
    $ref = rw_user_ref($u);
    if (!rw_ref_ok($ref)) { $err = 'В ответе панели нет идентификатора пользователя'; return null; }
    grace_set_ref($short, $ref);
    return $ref;
}

// $existing по ссылке: свежий идентификатор кладём обратно в массив, иначе
// следующий вызов для той же строки полез бы за ним в панель заново.
function grace_ref(&$existing, &$err = '') {
    $err = '';
    $short = (string) ($existing['short_uuid'] ?? '');
    $ref   = rw_ref_coerce((string) ($existing['user_uuid'] ?? ''));
    if (rw_ref_ok($ref) && !($ref['key'] === 'uuid' && panel_api_v3())) return $ref;
    $fresh = grace_ref_resolve($short, $err);
    if ($fresh) $existing['user_uuid'] = (string) $fresh['val'];
    return $fresh ?: $ref;
}

// PATCH пользователя из строки грейса с одним авто-ретраем на протухший идентификатор.
function grace_patch(&$existing, array $patch, &$err = '') {
    $err = '';
    $short = (string) ($existing['short_uuid'] ?? '');
    $ref = grace_ref($existing, $err);
    if (!rw_ref_ok($ref)) { $err = $err ?: 'Нет идентификатора пользователя'; return false; }

    $code = 0; $err = '';
    if (remnawave_update_user($ref, $patch, $err, $code)) return true;
    if (!in_array((int) $code, [400, 404], true)) return false;

    $re = '';
    $ref2 = grace_ref_resolve($short, $re);
    if (!$ref2 || $ref2['val'] === $ref['val']) return false;
    $existing['user_uuid'] = (string) $ref2['val'];
    error_log('submw grace: идентификатор ' . $short . ' перерезолвлен (' . $ref['key'] . ' -> ' . $ref2['key'] . '), повтор PATCH');
    $err = '';
    return remnawave_update_user($ref2, $patch, $err);
}

// Сброс счётчика трафика на выходе из грейса. К этому моменту в счётчике лежит
// то, что человек скачал за грейс (на входе в грейс счётчик обнуляется, если задана
// грейсовая квота), — без сброса этот расход съедал бы часть следующего оплаченного
// периода, а при упоре в квоту панель ещё и держала бы статус LIMITED.
// Зовём только ПОСЛЕ удачного PATCH и перед удалением строки: иначе зависший грейс
// сбрасывал бы трафик на каждом ретрае, раздавая его тому, кто не платил.
function grace_exit_reset_traffic(&$existing) {
    if (!grace_reset_traffic_on_exit()) return;
    $short = (string) ($existing['short_uuid'] ?? '');
    $e   = '';
    $ref = grace_ref($existing, $e);
    if (!rw_ref_ok($ref)) { error_log('submw grace exit reset-traffic: ' . ($e ?: 'нет идентификатора пользователя') . ' (short=' . $short . ')'); return; }
    $re = '';
    if (!remnawave_reset_traffic($ref, $re)) error_log('submw grace exit reset-traffic: ' . $re . ' (short=' . $short . ')');
}

function grace_restore($existing) {
    if (!is_array($existing)) return false;
    $short  = (string) ($existing['short_uuid'] ?? '');
    $squads = json_decode((string) ($existing['orig_squads'] ?? ''), true);
    if (!is_array($squads)) $squads = [];
    $squads = array_values(array_filter($squads, fn($s) => is_string($s) && $s !== ''));
    if (!$squads) { error_log('submw grace end: empty orig_squads for ' . $short); return false; }

    $full = [
        'activeInternalSquads'  => $squads,
        'trafficLimitBytes'     => (int) $existing['orig_traffic_bytes'],
        'trafficLimitStrategy'  => (string) $existing['orig_traffic_strategy'],
        'hwidDeviceLimit'       => ($existing['orig_hwid_limit'] === null ? null : (int) $existing['orig_hwid_limit']),
    ];
    // Панель отклоняет expireAt в прошлом («Expiration date cannot be in the past» —
    // проверка есть и в 2.x, и в 3.x). У истёкшего пользователя исходная дата почти
    // всегда в прошлом, поэтому полный патч раньше всегда падал и восстановление
    // сваливалось в фолбэк «только сквады» — лимиты трафика и устройств не
    // возвращались. Просроченную дату просто не шлём: срок грейса к этому моменту
    // тоже вышел, так что пользователь и без неё становится истёкшим.
    if (!empty($existing['orig_expire'])) {
        $oe = strtotime((string) $existing['orig_expire']);
        if ($oe !== false && $oe > time() + 60) $full['expireAt'] = (string) $existing['orig_expire'];
    }
    if (array_key_exists('orig_external_squad', $existing) && $existing['orig_external_squad'] !== null) {
        $full['externalSquadUuid'] = ($existing['orig_external_squad'] === '' ? null : (string) $existing['orig_external_squad']);
    }

    $e = '';
    if (grace_patch($existing, $full, $e)) { grace_exit_reset_traffic($existing); grace_delete($short); return true; }
    error_log('submw grace end: ' . $e . ' (short=' . $short . '), retrying squads-only');

    $e2 = '';
    if (grace_patch($existing, ['activeInternalSquads' => $squads], $e2)) {
        error_log('submw grace end: squads-only restore ok for ' . $short);
        grace_exit_reset_traffic($existing);
        grace_delete($short);
        return true;
    }
    error_log('submw grace end: squads-only restore failed for ' . $short . ': ' . $e2);
    return false;
}

function grace_restore_due($short) {
    if ($short === '' || remnawave_url() === '' || remnawave_token() === '') return false;
    $existing = grace_find($short);
    if (!$existing || (int) $existing['grace_until'] > time()) return false;
    return grace_restore($existing);
}

// $allow_start — можно ли ЗАВОДИТЬ новый грейс. Существующую строку (grace_active /
// grace_ended) обрабатываем на любом событии, а старт разрешён только на настоящем
// user.expired. Иначе петля: restore-PATCH в конце грейса порождает user.modified
// со status=EXPIRED, строка grace_users уже удалена — и юзеру, только что вышедшему
// из грейса, тут же выдавался новый. Панель шлёт user.modified на PATCH и в 2.x,
// и в 3.x, так что без флага цикл повторялся бы каждые grace_days бесконечно.
function grace_on_expired($short, $username = null, $allow_start = true) {
    if ($short === '') return 'grace_off';
    $existing = grace_find($short);

    if ($existing) {
        if ((int) $existing['grace_until'] > time()) return 'grace_active';
        return grace_restore($existing) ? 'grace_ended' : 'grace_err';
    }

    if (!$allow_start) return 'grace_off';
    if (!grace_squad_active()) return 'grace_off';

    $e = '';
    $u = remnawave_get_user_by_short($short, $e);
    // Панель 3.x не отдаёт uuid — идентификатор берём через rw_user_ref (uuid или id).
    $ref = rw_user_ref($u);
    if (!is_array($u) || !rw_ref_ok($ref)) { error_log('submw grace start get: ' . $e); return 'grace_err'; }
    $squads      = grace_squads_from_user($u);
    if (!$squads) { error_log('submw grace start: empty squads for ' . $short . ', skipping grace'); return 'grace_off'; }
    if (count($squads) === 1 && $squads[0] === grace_squad_uuid()) { error_log('submw grace start: user already only in grace squad ' . $short . ', skipping grace'); return 'grace_off'; }
    $bytes       = (int) ($u['trafficLimitBytes'] ?? 0);
    $strategy    = (string) ($u['trafficLimitStrategy'] ?? 'NO_RESET');
    $orig_expire = (string) ($u['expireAt'] ?? '');
    $hwid_orig   = array_key_exists('hwidDeviceLimit', $u) ? $u['hwidDeviceLimit'] : null;
    $ext_orig    = grace_external_active() ? (string) ($u['externalSquadUuid'] ?? '') : null;
    $grace_until = time() + grace_days() * 86400;

    grace_save($short, $ref['val'], $username, $squads, $bytes, $strategy, $orig_expire, $hwid_orig, $ext_orig, $grace_until);

    if (grace_traffic_bytes() > 0) {
        $re = '';
        remnawave_reset_traffic($ref, $re);
        if ($re !== '') error_log('submw grace reset-traffic: ' . $re);
    }

    $patch = [
        'status'                => 'ACTIVE',
        'activeInternalSquads'  => [grace_squad_uuid()],
        'trafficLimitBytes'     => grace_traffic_bytes(),
        'trafficLimitStrategy'  => grace_traffic_strategy(),
        'expireAt'              => grace_iso($grace_until),
    ];
    $gh = grace_hwid_limit_raw();
    if ($gh !== '') $patch['hwidDeviceLimit'] = (int) $gh;
    if (grace_external_active()) $patch['externalSquadUuid'] = grace_external_squad_uuid();
    $e = '';
    $ok = remnawave_update_user($ref, $patch, $e);
    if (!$ok) { grace_delete($short); error_log('submw grace start patch: ' . $e); return 'grace_err'; }
    return 'grace_started';
}

function grace_on_renew($short, $new_expire_str) {
    if ($short === '') return false;
    $existing = grace_find($short);
    if (!$existing) return false;
    $new_ts      = $new_expire_str ? strtotime((string) $new_expire_str) : false;
    $grace_until = (int) $existing['grace_until'];
    if ($new_ts === false || $new_ts <= $grace_until) return false;

    $squads = json_decode((string) $existing['orig_squads'], true);
    if (!is_array($squads)) $squads = [];
    $corrected = time() + ($new_ts - $grace_until);
    $patch = [
        'status'                => 'ACTIVE',
        'activeInternalSquads'  => $squads,
        'trafficLimitBytes'     => (int) $existing['orig_traffic_bytes'],
        'trafficLimitStrategy'  => (string) $existing['orig_traffic_strategy'],
        'hwidDeviceLimit'       => ($existing['orig_hwid_limit'] === null ? null : (int) $existing['orig_hwid_limit']),
        'expireAt'              => grace_iso($corrected),
    ];
    if (array_key_exists('orig_external_squad', $existing) && $existing['orig_external_squad'] !== null) {
        $patch['externalSquadUuid'] = ($existing['orig_external_squad'] === '' ? null : (string) $existing['orig_external_squad']);
    }
    $e = '';
    $ok = grace_patch($existing, $patch, $e);
    if (!$ok) { error_log('submw grace renew: ' . $e); return false; }
    grace_exit_reset_traffic($existing);
    grace_delete($short);
    return true;
}

function grace_cleanup($short) { grace_delete($short); }

// Разовый прогон по всем строкам грейса: перерезолвить идентификатор пользователя
// через by-short-uuid. Нужен после обновления панели до 3.x, чтобы не ждать события
// по каждому юзеру, и чтобы было видно строки, которых в панели уже нет.
// $limit ограничивает число обращений к панели за один прогон, а не число строк:
// записи, чей идентификатор уже нужного вида, пропускаются без запроса, поэтому
// повторный прогон продвигается дальше, а не топчется на первых строках.
function grace_refresh_refs($limit = 200) {
    ensure_grace_table();
    $out = ['total' => 0, 'updated' => 0, 'same' => 0, 'missing' => 0, 'errors' => 0, 'left' => 0, 'error' => '', 'error_net' => ''];
    if (remnawave_url() === '' || remnawave_token() === '') { $out['error'] = 'Не заданы URL панели или API-токен'; return $out; }
    if (!($p = db())) { $out['error'] = 'Нет связи с БД'; return $out; }
    try {
        $st = $p->query('SELECT short_uuid, user_uuid FROM grace_users ORDER BY grace_until ASC');
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { $out['error'] = 'Ошибка чтения таблицы грейса'; return $out; }

    $major = panel_major();
    $want  = $major >= 3 ? 'id' : ($major > 0 ? 'uuid' : '');
    $calls = 0; $err_streak = 0;
    foreach ($rows as $r) {
        $out['total']++;
        $short = (string) ($r['short_uuid'] ?? '');
        $old   = (string) ($r['user_uuid'] ?? '');
        $ref   = rw_ref_coerce($old);
        // Версия панели известна и идентификатор уже нужного вида — трогать нечего.
        if ($want !== '' && rw_ref_ok($ref) && $ref['key'] === $want) { $out['same']++; continue; }
        // Три сетевые ошибки подряд — панель лежит: дальше не долбимся, остаток
        // уходит в left, повторный клик продолжит с этого же места.
        if ($calls >= $limit || $err_streak >= 3) { $out['left']++; continue; }
        $calls++;
        $err = ''; $code = 0;
        $fresh = grace_ref_resolve($short, $err, $code);
        if ($fresh) {
            $err_streak = 0;
            if ((string) $fresh['val'] !== $old) $out['updated']++;
            else $out['same']++;
            continue;
        }
        // «Не найдено» — только честный 404. Таймаут, 5xx и обрыв связи — это
        // недоступность панели, а не мёртвая запись: считаем отдельно.
        if ($code === 404) {
            $out['missing']++;
            $err_streak = 0;
        } else {
            $out['errors']++;
            $err_streak++;
            if ($out['error_net'] === '') $out['error_net'] = $err;
        }
    }
    return $out;
}

function grace_retry_pending($limit = 2) {
    if (remnawave_url() === '' || remnawave_token() === '') return;
    ensure_grace_table();
    if (!($p = db())) return;
    try {
        $st = $p->prepare('SELECT short_uuid, username FROM grace_users WHERE grace_until < ? ORDER BY grace_until ASC LIMIT ' . (int) $limit);
        $st->execute([time() - 120]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return; }
    foreach ($rows as $r) {
        // allow_start=false: ретрай только ДОВОДИТ зависшие грейсы до восстановления;
        // если строка исчезла между SELECT и вызовом — новый грейс отсюда не заводим.
        $g = grace_on_expired((string) $r['short_uuid'], $r['username'] ?? null, false);
        if ($g === 'grace_ended') delete_override('shortuuid', (string) $r['short_uuid'], 'webhook');
    }
}

function grace_is_active($short) {
    static $memo = [];
    if ($short === '' || !grace_squad_active()) return false;
    if (array_key_exists($short, $memo)) return $memo[$short];
    $r = grace_find($short);
    return $memo[$short] = ($r && (int) $r['grace_until'] > time());
}
