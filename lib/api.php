<?php

function panel_cookie_header(array $headers) {
    $cookie = remnawave_cookie();
    if ($cookie === '') return $headers;
    foreach ($headers as $i => $h) {
        if (stripos($h, 'cookie:') === 0) { $headers[$i] = rtrim($h, "; \t") . '; ' . $cookie; return $headers; }
    }
    $headers[] = 'Cookie: ' . $cookie;
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
    $cookie = remnawave_cookie();
    if ($cookie !== '') $headers[] = 'Cookie: ' . $cookie;

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
    $cookie = remnawave_cookie();
    if ($cookie !== '') $headers[] = 'Cookie: ' . $cookie;

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
    // Панель 3.x ограничивает size у top-users сотней; на 2.x меньший размер тоже валиден.
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

function remnawave_get_user_by_short($shortUuid, &$error = '') {
    $error = '';
    if ($shortUuid === '') { $error = 'Пустой shortUuid'; return null; }
    [$ok, $code, $data, $e] = remnawave_api_request('GET', '/api/users/by-short-uuid/' . rawurlencode($shortUuid));
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

// $http_code возвращается по ссылке: грейсу нужно отличить 400/404 (протухший
// идентификатор — стоит перерезолвить и повторить) от прочих ошибок.
function remnawave_update_user($ref, array $fields, &$error = '', &$http_code = 0) {
    $error = ''; $http_code = 0;
    $ref = rw_ref_coerce($ref);
    if (!rw_ref_ok($ref)) { $error = 'Пустой идентификатор пользователя'; return false; }
    $body = array_merge([$ref['key'] => rw_ref_body_value($ref)], $fields);
    [$ok, $code, $data, $e] = remnawave_api_request('PATCH', '/api/users', $body);
    $http_code = (int) $code;
    if (!$ok) { $error = $e ?: ('HTTP ' . $code . ' ' . json_encode($data, JSON_UNESCAPED_UNICODE)); return false; }
    return true;
}

function remnawave_reset_traffic($ref, &$error = '') {
    $error = '';
    $ref = rw_ref_coerce($ref);
    if (!rw_ref_ok($ref)) { $error = 'Пустой идентификатор пользователя'; return false; }
    [$ok, $code, $data, $e] = remnawave_api_request('POST', '/api/users/' . rawurlencode($ref['val']) . '/actions/reset-traffic');
    if (!$ok) { $error = $e ?: ('HTTP ' . $code); return false; }
    return true;
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
