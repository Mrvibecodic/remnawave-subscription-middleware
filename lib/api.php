<?php

function panel_auth_headers(array $headers) {
    $cookie = remnawave_cookie();
    if ($cookie !== '') {
        $found = false;
        foreach ($headers as $i => $h) {
            if (stripos($h, 'cookie:') === 0) { $headers[$i] = rtrim($h, "; \t") . '; ' . $cookie; $found = true; break; }
        }
        if (!$found) $headers[] = 'Cookie: ' . $cookie;
    }
    $xkey = remnawave_xapikey();
    if ($xkey !== '') {
        foreach ($headers as $i => $h) {
            if (stripos($h, 'x-api-key:') === 0) unset($headers[$i]);
        }
        $headers[] = 'X-Api-Key: ' . $xkey;
        $headers = array_values($headers);
    }
    return $headers;
}

function remnawave_api_get($path) {
    $base  = remnawave_url();
    $token = remnawave_token();
    if ($base === '' || $token === '') {
        return [false, 0, null, 'Не заданы URL панели или API-токен'];
    }
    $url = $base . '/' . ltrim($path, '/');
    $headers = [
        'Authorization: Bearer ' . $token,
        'Accept: application/json',
    ];
    if (strpos($base, 'http://') === 0) {
        $headers[] = 'x-forwarded-proto: https';
        $headers[] = 'x-forwarded-for: 127.0.0.1';
    }
    $headers = panel_auth_headers($headers);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => api_tls_verify(),
        CURLOPT_SSL_VERIFYHOST => api_tls_verify() ? 2 : 0,
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($err) return [false, $code, null, $err];
    $json = json_decode($body, true);
    if ($code < 200 || $code >= 300) {
        return [false, $code, $json, 'HTTP ' . $code];
    }
    return [true, $code, $json, ''];
}

function remnawave_all_users(&$error = '') {
    $error = '';
    $all = [];
    $start = 0; $size = 250; $guard = 0;
    do {
        [$ok, $code, $data, $e] = remnawave_api_get("/api/users?size={$size}&start={$start}");
        if (!$ok) { $error = $e ?: ('HTTP ' . $code); break; }
        $resp  = $data['response'] ?? $data;
        $users = $resp['users'] ?? (is_array($resp) ? $resp : []);
        $total = (int) ($resp['total'] ?? count($users));
        if (!is_array($users)) $users = [];
        foreach ($users as $u) $all[] = $u;
        $start += $size;
        $guard++;
    } while (count($all) < $total && $guard < 40 && count($users) > 0);
    return $all;
}

function remnawave_api_request($method, $path, $body = null) {
    $base  = remnawave_url();
    $token = remnawave_token();
    if ($base === '' || $token === '') return [false, 0, null, 'Не заданы URL панели или API-токен'];
    $url = $base . '/' . ltrim($path, '/');
    $headers = ['Authorization: Bearer ' . $token, 'Accept: application/json'];
    if (strpos($base, 'http://') === 0) {
        $headers[] = 'x-forwarded-proto: https';
        $headers[] = 'x-forwarded-for: 127.0.0.1';
    }
    $headers = panel_auth_headers($headers);

    $opt = [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => api_tls_verify(),
        CURLOPT_SSL_VERIFYHOST => api_tls_verify() ? 2 : 0,
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
    ];
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        $opt[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
    }
    $opt[CURLOPT_HTTPHEADER] = $headers;

    $ch = curl_init();
    curl_setopt_array($ch, $opt);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($err) return [false, $code, null, $err];
    $data = json_decode($resp, true);
    if ($code < 200 || $code >= 300) return [false, $code, $data, 'HTTP ' . $code];
    return [true, $code, $data, ''];
}

// --- Идентификатор пользователя: uuid (панель 2.x) или числовой id (панель 3.x) ---
//
// В панели 2.x у пользователя есть и uuid, и числовой id, но PATCH /api/users и
// hwid-эндпоинты принимают там только uuid. В 3.x поле uuid удалено совсем, и всё
// работает по числовому id. Поэтому правило — uuid вперёд: есть uuid, значит панель
// 2.x и обращаемся по нему; нет uuid — панель 3.x, обращаемся по id. Знать версию
// панели для этого не нужно, признак берётся из самого объекта пользователя.
//
// Формат ссылки: ['key' => 'uuid'|'id', 'val' => '...'].

function rw_ref($key, $val) {
    $key = ($key === 'id') ? 'id' : 'uuid';
    $val = trim((string) $val);
    if ($key === 'id' && !preg_match('/^\d+$/', $val)) $val = '';
    return ['key' => $key, 'val' => $val];
}

function rw_ref_ok($ref) {
    return is_array($ref) && isset($ref['val']) && (string) $ref['val'] !== '';
}

// Ссылка по объекту пользователя из ответа панели.
function rw_user_ref($u) {
    if (!is_array($u)) return rw_ref('uuid', '');
    $uuid = isset($u['uuid']) ? trim((string) $u['uuid']) : '';
    if ($uuid !== '') return rw_ref('uuid', $uuid);
    $id = isset($u['id']) ? trim((string) $u['id']) : '';
    if ($id !== '') return rw_ref('id', $id);
    return rw_ref('uuid', '');
}

// Приведение «сырого» значения к ссылке: только цифры — это id, иначе uuid.
// UUID никогда не состоит из одних цифр, так что догадка однозначна.
function rw_ref_coerce($x) {
    if (is_array($x)) return rw_ref($x['key'] ?? 'uuid', $x['val'] ?? '');
    $s = trim((string) $x);
    return preg_match('/^\d+$/', $s) ? rw_ref('id', $s) : rw_ref('uuid', $s);
}

// Значение для тела запроса: id уходит числом, uuid — строкой.
function rw_ref_body_value($ref) {
    return $ref['key'] === 'id' ? (int) $ref['val'] : (string) $ref['val'];
}

function remnawave_user_hwids($ref, &$error = '') {
    $error = '';
    $ref = rw_ref_coerce($ref);
    if (!rw_ref_ok($ref)) { $error = 'Пустой идентификатор пользователя'; return []; }
    [$ok, $code, $data, $e] = remnawave_api_request('GET', '/api/hwid/devices/' . rawurlencode($ref['val']));
    if (!$ok) { $error = $e ?: ('HTTP ' . $code); return []; }
    $resp = $data['response'] ?? $data;
    $dev  = $resp['devices'] ?? (isset($resp[0]) ? $resp : []);
    return is_array($dev) ? $dev : [];
}

function remnawave_delete_hwid($ref, $hwid) {
    $ref = rw_ref_coerce($ref);
    if (!rw_ref_ok($ref)) return [false, 0, null, 'Пустой идентификатор пользователя'];
    return remnawave_api_request('POST', '/api/hwid/devices/delete', [
        ($ref['key'] === 'id' ? 'userId' : 'userUuid') => rw_ref_body_value($ref),
        'hwid' => $hwid,
    ]);
}

function remnawave_hwid_top_users(&$error = '') {
    $error = '';
    $all = [];
    // У top-users предел size = 100 (и в 2.x, и в 3.x) — прежние 250 отклонялись.
    $start = 0; $size = 100; $guard = 0;
    do {
        [$ok, $code, $data, $e] = remnawave_api_get("/api/hwid/devices/top-users?size={$size}&start={$start}");
        if (!$ok) { $error = $e ?: ('HTTP ' . $code); break; }
        $resp = $data['response'] ?? $data;
        $rows = $resp['users'] ?? (is_array($resp) ? $resp : []);
        $total = (int) ($resp['total'] ?? count($rows));
        if (!is_array($rows)) $rows = [];
        foreach ($rows as $r) $all[] = $r;
        $start += $size;
        $guard++;
    } while (count($all) < $total && $guard < 150 && count($rows) > 0);
    return $all;
}

function remnawave_hwid_all_devices(&$error = '') {
    $error = '';
    $all = [];
    $start = 0; $size = 250; $guard = 0;
    do {
        [$ok, $code, $data, $e] = remnawave_api_get("/api/hwid/devices?size={$size}&start={$start}");
        if (!$ok) { $error = $e ?: ('HTTP ' . $code); break; }
        $resp = $data['response'] ?? $data;
        $rows = $resp['devices'] ?? (is_array($resp) ? $resp : []);
        $total = (int) ($resp['total'] ?? count($rows));
        if (!is_array($rows)) $rows = [];
        foreach ($rows as $r) $all[] = $r;
        $start += $size;
        $guard++;
    } while (count($all) < $total && $guard < 200 && count($rows) > 0);
    return $all;
}

function remnawave_internal_squads(&$error = '') {
    $error = '';
    [$ok, $code, $data, $e] = remnawave_api_get('/api/internal-squads');
    if (!$ok) { $error = $e ?: ('HTTP ' . $code); return []; }
    $resp = $data['response'] ?? $data;
    $list = $resp['internalSquads'] ?? (is_array($resp) ? $resp : []);
    $out = [];
    if (is_array($list)) foreach ($list as $s) {
        if (!is_array($s) || empty($s['uuid'])) continue;
        $out[] = ['uuid' => (string) $s['uuid'], 'name' => (string) ($s['name'] ?? $s['uuid']), 'members' => (int) ($s['info']['membersCount'] ?? 0)];
    }
    return $out;
}

function remnawave_external_squads(&$error = '') {
    $error = '';
    [$ok, $code, $data, $e] = remnawave_api_get('/api/external-squads');
    if (!$ok) { $error = $e ?: ('HTTP ' . $code); return []; }
    $resp = $data['response'] ?? $data;
    $list = $resp['externalSquads'] ?? (is_array($resp) ? $resp : []);
    $out = [];
    if (is_array($list)) foreach ($list as $s) {
        if (!is_array($s) || empty($s['uuid'])) continue;
        $out[] = ['uuid' => (string) $s['uuid'], 'name' => (string) ($s['name'] ?? $s['uuid']), 'members' => (int) ($s['info']['membersCount'] ?? 0)];
    }
    return $out;
}

function remnawave_sub_templates(&$error = '') {
    $error = '';
    [$ok, $code, $data, $e] = remnawave_api_get('/api/subscription-templates');
    if (!$ok) { $error = $e ?: ('HTTP ' . $code); return []; }
    $resp = $data['response'] ?? $data;
    $list = $resp['templates'] ?? (is_array($resp) ? $resp : []);
    if (!is_array($list)) { $error = 'Неожиданный ответ /api/subscription-templates'; return []; }
    $out = [];
    foreach ($list as $t) {
        if (!is_array($t) || empty($t['uuid'])) continue;
        $out[] = [
            'uuid' => (string) $t['uuid'],
            'name' => (string) ($t['name'] ?? ''),
            'type' => (string) ($t['templateType'] ?? ''),
            'pos'  => (int) ($t['viewPosition'] ?? 0),
        ];
    }
    return $out;
}

// Список шаблонов панель отдаёт без содержимого, поэтому за телом нужен второй
// запрос по uuid.
function remnawave_sub_template_json($uuid, &$error = '') {
    $error = '';
    $uuid = trim((string) $uuid);
    if ($uuid === '') { $error = 'Пустой uuid шаблона'; return null; }
    [$ok, $code, $data, $e] = remnawave_api_get('/api/subscription-templates/' . rawurlencode($uuid));
    if (!$ok) { $error = $e ?: ('HTTP ' . $code); return null; }
    $resp = $data['response'] ?? $data;
    $tpl = is_array($resp) ? ($resp['templateJson'] ?? null) : null;
    if (!is_array($tpl) || !$tpl) { $error = 'Шаблон без templateJson'; return null; }
    return $tpl;
}

// $http_code по ссылке: вызывающему нужно отличать 404 (пользователя больше
// нет) от сетевой ошибки или 5xx (панель недоступна, запись живая).
function remnawave_get_user_by_short($shortUuid, &$error = '', &$http_code = 0) {
    $error = ''; $http_code = 0;
    if ($shortUuid === '') { $error = 'Пустой shortUuid'; return null; }
    [$ok, $code, $data, $e] = remnawave_api_request('GET', '/api/users/by-short-uuid/' . rawurlencode($shortUuid));
    $http_code = (int) $code;
    if (!$ok) { $error = $e ?: ('HTTP ' . $code); return null; }
    $resp = $data['response'] ?? $data;
    return is_array($resp) ? $resp : null;
}

function remnawave_get_user_by_username($username, &$error = '') {
    $error = '';
    $username = (string) $username;
    if ($username === '') { $error = 'Пустой username'; return null; }
    [$ok, $code, $data, $e] = remnawave_api_request('GET', '/api/users/by-username/' . rawurlencode($username));
    if (!$ok) { $error = $e ?: ('HTTP ' . $code); return null; }
    $resp = $data['response'] ?? $data;
    if (is_array($resp) && isset($resp['users']) && is_array($resp['users'])) $resp = $resp['users'][0] ?? null;
    elseif (is_array($resp) && isset($resp[0]) && is_array($resp[0])) $resp = $resp[0];
    return is_array($resp) ? $resp : null;
}

// Кто из нашего кода сейчас пишет в панель. Сам PATCH знает только идентификатор
// пользователя, а разбирать журнал потом надо по shortUuid и по причине, поэтому
// вызывающий выставляет контекст перед обращением к панели.
function api_ctx($src = null, $short = null) {
    static $ctx = ['src' => '', 'short' => ''];
    if ($src !== null) $ctx = ['src' => (string) $src, 'short' => (string) $short];
    return $ctx;
}

// $http_code возвращается по ссылке: грейсу нужно отличить 400/404 (протухший
// идентификатор — стоит перерезолвить и повторить) от прочих ошибок.
function remnawave_update_user($ref, array $fields, &$error = '', &$http_code = 0) {
    $error = ''; $http_code = 0;
    $ref = rw_ref_coerce($ref);
    if (!rw_ref_ok($ref)) { $error = 'Пустой идентификатор пользователя'; return false; }
    $body = array_merge([$ref['key'] => rw_ref_body_value($ref)], $fields);
    [$ok, $code, $data, $e] = remnawave_api_request('PATCH', '/api/users', $body);
    $http_code = (int) $code;
    if (!$ok) $error = $e ?: ('HTTP ' . $code . ' ' . json_encode($data, JSON_UNESCAPED_UNICODE));
    if (function_exists('log_panel_write')) {
        $ctx = api_ctx();
        log_panel_write($ctx['short'], $ref, 'patch', $ctx['src'], $fields, $ok, (int) $code, $error);
    }
    return $ok ? true : false;
}

function remnawave_reset_traffic($ref, &$error = '') {
    $error = '';
    $ref = rw_ref_coerce($ref);
    if (!rw_ref_ok($ref)) { $error = 'Пустой идентификатор пользователя'; return false; }
    [$ok, $code, $data, $e] = remnawave_api_request('POST', '/api/users/' . rawurlencode($ref['val']) . '/actions/reset-traffic');
    if (!$ok) $error = $e ?: ('HTTP ' . $code);
    if (function_exists('log_panel_write')) {
        $ctx = api_ctx();
        log_panel_write($ctx['short'], $ref, 'reset_traffic', $ctx['src'], [], $ok, (int) $code, $error);
    }
    return $ok ? true : false;
}


// --- Версия панели ---
//
// GET /api/system/metadata есть в панели начиная с 2.5.0, то есть на всём
// поддерживаемом диапазоне. Ответ кэшируется в настройках: версия нужна для бейджа
// в шапке и как подсказка грейсу (см. grace_ref в lib/grace.php), но никогда не
// должна блокировать выдачу подписки — при недоступности работаем по форме объекта
// пользователя (rw_user_ref).
function panel_min_supported() { return '2.7.4'; }

function remnawave_panel_meta($maxAge = 600, &$error = '') {
    $error = '';
    $now = time();
    $prev = json_decode((string) setting('panelmeta_json', ''), true);
    if (!is_array($prev)) $prev = [];
    if ($maxAge > 0) {
        $ts = (int) setting('panelmeta_ts', '0');
        if ($ts > 0 && ($now - $ts) <= $maxAge && $prev) {
            $prev['cached'] = true;
            $prev['age'] = $now - $ts;
            return $prev;
        }
    }
    $out = [
        'ok' => false, 'ts' => $now, 'cached' => false, 'age' => 0, 'error' => '',
        'version' => '', 'build_time' => '', 'build_number' => '',
        'commit' => '', 'branch' => '', 'commit_url' => '',
    ];
    [$ok, $code, $data, $e] = remnawave_api_get('/api/system/metadata');
    if (!$ok) {
        $error = $e ?: ('HTTP ' . $code);
        $out['error'] = $error;
        // Последнюю известную версию не теряем — показываем её как устаревшую,
        // это полезнее пустого прочерка при кратковременной недоступности панели.
        $out['version'] = (string) ($prev['version'] ?? '');
        $out['stale']   = $out['version'] !== '';
    } else {
        $resp = $data['response'] ?? $data;
        if (is_array($resp)) {
            $out['ok']           = true;
            $out['version']      = trim((string) ($resp['version'] ?? ''));
            $out['build_time']   = trim((string) ($resp['build']['time'] ?? ''));
            $out['build_number'] = trim((string) ($resp['build']['number'] ?? ''));
            $out['commit']       = trim((string) ($resp['git']['backend']['commitSha'] ?? ''));
            $out['branch']       = trim((string) ($resp['git']['backend']['branch'] ?? ''));
            $out['commit_url']   = trim((string) ($resp['git']['backend']['commitUrl'] ?? ''));
        } else {
            $error = 'Неожиданный ответ /api/system/metadata';
            $out['error'] = $error;
        }
    }
    set_setting('panelmeta_json', json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    set_setting('panelmeta_ts', (string) $now);
    return $out;
}

// Чтение из кэша без обращения к панели — безопасно звать на горячем пути.
function panel_meta_cached() {
    $c = json_decode((string) setting('panelmeta_json', ''), true);
    return is_array($c) ? $c : [];
}

function panel_version() {
    $c = panel_meta_cached();
    return trim((string) ($c['version'] ?? ''));
}

// 0 — версия неизвестна (нет связи, нет токена, панель ещё не опрашивалась).
function panel_major() {
    $v = panel_version();
    return preg_match('/^\s*v?(\d+)/', $v, $m) ? (int) $m[1] : 0;
}

// true только когда точно знаем, что панель мажора 3+. Неизвестность — не «да».
function panel_api_v3() { return panel_major() >= 3; }

function panel_version_supported() {
    $v = panel_version();
    if ($v === '') return true;
    return version_compare(ltrim($v, 'vV'), panel_min_supported(), '>=');
}

// --- Конфигурация панели ---
//
// GET /api/system/configuration появился только в 3.2.0, поэтому запрос делается
// строго по версии из кэша меты: неизвестная версия — не «да», обращения нет.
// Ответ кэшируется в настройках, на горячем пути читается panel_config_cached()
// без похода в панель. Ничего критичного отсюда не берётся: любое поле может
// остаться пустым, и прослойка обязана работать как раньше.
function panel_config_min() { return '3.2.0'; }

function panel_supports_config() {
    $v = panel_version();
    if ($v === '') return false;
    return version_compare(ltrim($v, 'vV'), panel_config_min(), '>=');
}

function panel_config_cached() {
    $c = json_decode((string) setting('panelconf_json', ''), true);
    return is_array($c) ? $c : [];
}

function panel_config_num_list($v) {
    if (!is_array($v)) return null;
    $out = [];
    foreach ($v as $x) if (is_numeric($x)) $out[] = 0 + $x;
    return $out;
}

// Строковые "false"/"0"/"" от панели должны читаться как false, а не как
// непустая строка: иначе выключенный вебхук показался бы включённым.
function panel_config_bool($v) {
    if ($v === null) return null;
    if (is_string($v)) {
        $s = strtolower(trim($v));
        if ($s === '' || $s === 'false' || $s === '0' || $s === 'no' || $s === 'off') return false;
        return true;
    }
    if (is_array($v) || is_object($v)) return null;
    return (bool) $v;
}

// SUB_PUBLIC_DOMAIN панели может прийти со схемой и путём — прослойке нужен
// голый домен, ровно в том виде, в каком он лежит в target_domain.
function panel_config_domain($v) {
    if (!is_string($v) && !is_numeric($v)) return '';
    $s = strtolower(trim((string) $v));
    if ($s === '') return '';
    $s = preg_replace('~^[a-z][a-z0-9+.-]*://~', '', $s);
    $p = strpos($s, '/');
    if ($p !== false) $s = substr($s, 0, $p);
    return trim($s, '/');
}

function panel_config_value_keys() {
    return ['webhook', 'bandwidth_usage', 'not_connected_after', 'expiration_notifications',
            'clean_usage_history', 'disable_user_usage_records', 'disable_srh_records',
            'export_to_redis_stream', 'short_uuid_length', 'user_usage_ignore_below_bytes',
            'sub_public_domain'];
}

function panel_config_from_response($resp, array $out) {
    $nt = is_array($resp['notifications'] ?? null) ? $resp['notifications'] : [];
    $sv = is_array($resp['service'] ?? null) ? $resp['service'] : [];
    $ms = is_array($resp['misc'] ?? null) ? $resp['misc'] : [];
    $out['ok']                            = true;
    $out['webhook']                       = panel_config_bool($nt['webhook'] ?? null);
    $out['bandwidth_usage']               = panel_config_num_list($nt['bandwidthUsage'] ?? null);
    $out['not_connected_after']           = panel_config_num_list($nt['notConnectedAfter'] ?? null);
    $out['expiration_notifications']      = panel_config_num_list($nt['expirationNotifications'] ?? null);
    $out['clean_usage_history']           = panel_config_bool($sv['cleanUsageHistory'] ?? null);
    $out['disable_user_usage_records']    = panel_config_bool($sv['disableUserUsageRecords'] ?? null);
    $out['disable_srh_records']           = panel_config_bool($sv['disableSrhRecords'] ?? null);
    $out['export_to_redis_stream']        = panel_config_bool($sv['exportToRedisStream'] ?? null);
    $out['short_uuid_length']             = (int) ($ms['shortUuidLength'] ?? 0);
    $out['user_usage_ignore_below_bytes'] = isset($ms['userUsageIgnoreBelowBytes']) ? (int) $ms['userUsageIgnoreBelowBytes'] : null;
    $out['sub_public_domain']             = panel_config_domain($ms['subPublicDomain'] ?? '');
    return $out;
}

function remnawave_panel_config($maxAge = 600, &$error = '') {
    $error = '';
    $now = time();
    $prev = panel_config_cached();
    $out = [
        'ok' => false, 'ts' => $now, 'cached' => false, 'age' => 0, 'error' => '',
        'supported' => panel_supports_config(),
        'webhook' => null,
        'bandwidth_usage' => null,
        'not_connected_after' => null,
        'expiration_notifications' => null,
        'clean_usage_history' => null,
        'disable_user_usage_records' => null,
        'disable_srh_records' => null,
        'export_to_redis_stream' => null,
        'short_uuid_length' => 0,
        'user_usage_ignore_below_bytes' => null,
        'sub_public_domain' => '',
    ];
    if (!$out['supported']) {
        // Панель ниже 3.2.0 либо версия ещё не получена — запроса не делаем и
        // прежний кэш не трогаем: он пригодится, если версию просто не успели узнать.
        return $out;
    }
    if ($maxAge > 0) {
        $ts = (int) setting('panelconf_ts', '0');
        if ($ts > 0 && ($now - $ts) <= $maxAge && $prev) {
            $prev['cached'] = true;
            $prev['age'] = $now - $ts;
            return $prev;
        }
    }
    [$ok, $code, $data, $e] = remnawave_api_get('/api/system/configuration');
    $resp = $ok ? ($data['response'] ?? $data) : null;
    if ($ok && is_array($resp)) {
        $out = panel_config_from_response($resp, $out);
    } else {
        // Ни ошибка сети, ни мусор вместо JSON не должны стирать уже известные
        // значения: домен подписки и длина shortUuid переезжают из прошлого кэша.
        $error = $ok ? 'Неожиданный ответ /api/system/configuration' : ($e ?: ('HTTP ' . $code));
        $out['error'] = $error;
        foreach (panel_config_value_keys() as $k) {
            if (array_key_exists($k, $prev)) $out[$k] = $prev[$k];
        }
        $out['stale'] = (bool) $prev;
    }
    set_setting('panelconf_json', json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    set_setting('panelconf_ts', (string) $now);
    return $out;
}

// Отпечаток значимых полей: по нему админка понимает, что настройки панели
// изменились, и перерисовывает блок. Время запроса в отпечаток не входит.
function panel_config_sig($c) {
    if (!is_array($c) || !$c) return '';
    $v = [];
    foreach (panel_config_value_keys() as $k) $v[$k] = $c[$k] ?? null;
    return md5(json_encode($v, JSON_UNESCAPED_UNICODE));
}

// Домен подписки панели (SUB_PUBLIC_DOMAIN) — пусто, если панель старая или молчит.
function panel_sub_public_domain() {
    $c = panel_config_cached();
    return panel_config_domain($c['sub_public_domain'] ?? '');
}

// Длина shortUuid в панели. 0 — неизвестна; значения вне разумного диапазона
// игнорируются, чтобы кривой ответ не превратил живые подписки в «мусор».
function panel_short_uuid_len() {
    $c = panel_config_cached();
    $n = (int) ($c['short_uuid_length'] ?? 0);
    return ($n >= 8 && $n <= 64) ? $n : 0;
}

// true/false — вебхуки в панели включены/выключены, null — неизвестно.
function panel_webhook_enabled() {
    $c = panel_config_cached();
    $v = $c['webhook'] ?? null;
    return $v === null ? null : (bool) $v;
}

function remnawave_system_stats($maxAge = 45, &$error = '') {
    $error = '';
    $now = time();
    if ($maxAge > 0) {
        $ts = (int) setting('panelstats_ts', '0');
        if ($ts > 0 && ($now - $ts) <= $maxAge) {
            $c = json_decode((string) setting('panelstats_json', ''), true);
            if (is_array($c)) { $c['cached'] = true; $c['age'] = $now - $ts; return $c; }
        }
    }
    $out = [
        'ok' => false, 'ts' => $now, 'cached' => false, 'age' => 0, 'error' => '',
        'users'  => ['ACTIVE' => null, 'LIMITED' => null, 'EXPIRED' => null, 'DISABLED' => null, 'total' => null],
        'online' => ['now' => null, 'day' => null, 'week' => null],
        'nodes'  => ['online' => null, 'total' => null],
    ];
    [$ok, $code, $data, $e] = remnawave_api_get('/api/system/stats');
    if (!$ok) { $error = $e ?: ('HTTP ' . $code); $out['error'] = $error; return $out; }
    $resp = $data['response'] ?? $data;
    $sc = $resp['users']['statusCounts'] ?? ($resp['statusCounts'] ?? null);
    if (is_array($sc)) foreach (['ACTIVE', 'LIMITED', 'EXPIRED', 'DISABLED'] as $k) if (isset($sc[$k])) $out['users'][$k] = (int) $sc[$k];
    $tot = $resp['users']['totalUsers'] ?? ($resp['totalUsers'] ?? null);
    if ($tot !== null) $out['users']['total'] = (int) $tot;
    elseif (is_array($sc)) { $s2 = 0; foreach ($sc as $v) $s2 += (int) $v; $out['users']['total'] = $s2; }
    $os = $resp['onlineStats'] ?? ($resp['users']['onlineStats'] ?? ($resp['stats']['onlineStats'] ?? null));
    if (is_array($os)) {
        if (isset($os['onlineNow'])) $out['online']['now'] = (int) $os['onlineNow'];
        if (isset($os['lastDay']))   $out['online']['day']  = (int) $os['lastDay'];
        if (isset($os['lastWeek']))  $out['online']['week'] = (int) $os['lastWeek'];
    }
    $no = $resp['nodes']['totalOnline'] ?? ($resp['nodesOnline'] ?? null);
    if ($no !== null) $out['nodes']['online'] = (int) $no;
    $out['ok'] = true;
    [$nok, , $nd, ] = remnawave_api_get('/api/nodes');
    if ($nok) {
        $nr = $nd['response'] ?? $nd;
        $list = is_array($nr) ? ($nr['nodes'] ?? (isset($nr[0]) ? $nr : [])) : [];
        if (is_array($list) && $list) {
            $out['nodes']['total'] = count($list);
            $on = 0; $flag = false;
            foreach ($list as $n) {
                if (!is_array($n)) continue;
                if (array_key_exists('isConnected', $n)) { $flag = true; if (!empty($n['isConnected'])) $on++; }
                elseif (array_key_exists('isNodeOnline', $n)) { $flag = true; if (!empty($n['isNodeOnline'])) $on++; }
            }
            if ($flag) $out['nodes']['online'] = $on;
        }
    }
    set_setting('panelstats_json', json_encode($out, JSON_UNESCAPED_UNICODE));
    set_setting('panelstats_ts', (string) $now);
    return $out;
}
