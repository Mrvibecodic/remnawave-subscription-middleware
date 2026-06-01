<?php

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

function remnawave_user_hwids($userUuid, &$error = '') {
    $error = '';
    if ($userUuid === '') { $error = 'Пустой UUID'; return []; }
    [$ok, $code, $data, $e] = remnawave_api_request('GET', '/api/hwid/devices/' . rawurlencode($userUuid));
    if (!$ok) { $error = $e ?: ('HTTP ' . $code); return []; }
    $resp = $data['response'] ?? $data;
    $dev  = $resp['devices'] ?? (isset($resp[0]) ? $resp : []);
    return is_array($dev) ? $dev : [];
}

function remnawave_delete_hwid($userUuid, $hwid) {
    return remnawave_api_request('POST', '/api/hwid/devices/delete', [
        'userUuid' => $userUuid,
        'hwid'     => $hwid,
    ]);
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

function remnawave_get_user_by_short($shortUuid, &$error = '') {
    $error = '';
    if ($shortUuid === '') { $error = 'Пустой shortUuid'; return null; }
    [$ok, $code, $data, $e] = remnawave_api_request('GET', '/api/users/by-short-uuid/' . rawurlencode($shortUuid));
    if (!$ok) { $error = $e ?: ('HTTP ' . $code); return null; }
    $resp = $data['response'] ?? $data;
    return is_array($resp) ? $resp : null;
}

function remnawave_update_user($uuid, array $fields, &$error = '') {
    $error = '';
    if ($uuid === '') { $error = 'Пустой UUID'; return false; }
    [$ok, $code, $data, $e] = remnawave_api_request('PATCH', '/api/users', array_merge(['uuid' => $uuid], $fields));
    if (!$ok) { $error = $e ?: ('HTTP ' . $code . ' ' . json_encode($data, JSON_UNESCAPED_UNICODE)); return false; }
    return true;
}
