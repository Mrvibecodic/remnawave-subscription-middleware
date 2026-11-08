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

function remnawave_api_get($path, $assoc = true) {
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
    if (function_exists('apistat_hit')) apistat_hit('GET', $path, $err ? 0 : $code, curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000);
    curl_close($ch);
    if ($err) return [false, $code, null, $err];
    $json = json_decode($body, $assoc);
    if ($code < 200 || $code >= 300) {
        return [false, $code, $json, 'HTTP ' . $code];
    }
    return [true, $code, $json, ''];
}

// $each — получать пользователей по страницам, не копя их: тогда функция возвращает пустой список.
// $fields — какие поля нужны месту вызова (через Go приходят только они; PHP отдаёт объект целиком).
function remnawave_all_users(&$error = '', ?callable $each = null, ?array $fields = null) {
    $error = '';
    if (function_exists('go_users_list')) {
        $gl = go_users_list($fields, $ge);
        if ($gl !== null) {
            if ($each === null) return $gl;
            foreach (array_chunk($gl, 250) as $chunk) $each($chunk);
            return [];
        }
    }
    return remnawave_all_users_php($error, $each);
}

function remnawave_all_users_php(&$error = '', ?callable $each = null) {
    $error = '';
    $all = [];
    $seen = 0;
    $start = 0; $size = 250; $guard = 0;
    do {
        [$ok, $code, $data, $e] = remnawave_api_get("/api/users?size={$size}&start={$start}");
        if (!$ok) { $error = $e ?: ('HTTP ' . $code); break; }
        $resp  = $data['response'] ?? $data;
        $users = $resp['users'] ?? (is_array($resp) ? $resp : []);
        $total = (int) ($resp['total'] ?? count($users));
        if (!is_array($users)) $users = [];
        if ($each !== null) $each($users);
        else foreach ($users as $u) $all[] = $u;
        $seen += count($users);
        $start += $size;
        $guard++;
    } while ($seen < $total && $guard < 4000 && count($users) > 0);
    if ($error === '' && $guard >= 4000 && $seen < $total) $error = 'Список пользователей получен не полностью';
    return $all;
}

// $opts['headers'] — свои заголовки «Имя: значение»; одноимённые стандартные (Accept) заменяются.
function remnawave_api_request($method, $path, $body = null, array $opts = []) {
    $base  = remnawave_url();
    $token = remnawave_token();
    if ($base === '' || $token === '') { unset($GLOBALS['submw_apistat_expect']); return [false, 0, null, 'Не заданы URL панели или API-токен']; }
    $url = $base . '/' . ltrim($path, '/');
    $headers = ['Authorization: Bearer ' . $token, 'Accept: application/json'];
    if (strpos($base, 'http://') === 0) {
        $headers[] = 'x-forwarded-proto: https';
        $headers[] = 'x-forwarded-for: 127.0.0.1';
    }
    foreach ((array) ($opts['headers'] ?? []) as $hl) {
        $hl = (string) $hl;
        $name = strtolower(trim(strstr($hl, ':', true) ?: ''));
        if ($name === '' || $name === 'authorization') continue;
        foreach ($headers as $i => $h) if (strtolower(trim(strstr($h, ':', true) ?: '')) === $name) unset($headers[$i]);
        $headers[] = $hl;
    }
    $headers = panel_auth_headers(array_values($headers));

    $opt = [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => (int) ($opts['timeout'] ?? 20),
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
    if (function_exists('apistat_hit')) apistat_hit($method, $path, $err ? 0 : $code, curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000);
    curl_close($ch);
    if ($err) return [false, $code, null, $err];
    $data = json_decode($resp, true);
    if ($code < 200 || $code >= 300) return [false, $code, $data, 'HTTP ' . $code];
    return [true, $code, $data, ''];
}


function rw_ref($key, $val) {
    $key = ($key === 'id') ? 'id' : 'uuid';
    $val = trim((string) $val);
    if ($key === 'id' && !preg_match('/^\d+$/', $val)) $val = '';
    return ['key' => $key, 'val' => $val];
}

function rw_ref_ok($ref) {
    return is_array($ref) && isset($ref['val']) && (string) $ref['val'] !== '';
}

function rw_user_ref($u) {
    if (!is_array($u)) return rw_ref('uuid', '');
    $uuid = isset($u['uuid']) ? trim((string) $u['uuid']) : '';
    if ($uuid !== '') return rw_ref('uuid', $uuid);
    $id = isset($u['id']) ? trim((string) $u['id']) : '';
    if ($id !== '') return rw_ref('id', $id);
    return rw_ref('uuid', '');
}

function rw_ref_coerce($x) {
    if (is_array($x)) return rw_ref($x['key'] ?? 'uuid', $x['val'] ?? '');
    $s = trim((string) $x);
    return preg_match('/^\d+$/', $s) ? rw_ref('id', $s) : rw_ref('uuid', $s);
}

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

// Справочники панели (ноды, хосты, сквады, настройки подписки) — один общий снимок на всех:
// кто сходил в панель первым, тот и обновил, остальные читают его, пока он не старше их maxAge.
const PANEL_REF_RETRY = 30;

function panel_ref_list($data, $key) {
    $r = is_array($data) ? ($data['response'] ?? $data) : null;
    if (!is_array($r)) return [];
    if (isset($r[$key]) && is_array($r[$key])) return $r[$key];
    return isset($r[0]) ? $r : [];
}

function panel_ref_pick(array $row, array $keys) {
    $out = [];
    foreach ($keys as $k) if (array_key_exists($k, $row)) $out[$k] = $row[$k];
    return $out;
}

function panel_ref_spec($name) {
    switch ($name) {
        case 'nodes':   return ['/api/nodes', static function ($d) {
            $out = [];
            foreach (panel_ref_list($d, 'nodes') as $n) if (is_array($n)) $out[] = panel_ref_pick($n, ['uuid', 'name', 'countryCode', 'address', 'isConnected', 'isNodeOnline', 'isDisabled', 'isConnecting']);
            return $out;
        }];
        case 'hosts':   return ['/api/hosts', static function ($d) {
            $out = [];
            foreach (panel_ref_list($d, 'hosts') as $h) {
                if (!is_array($h)) continue;
                $row = panel_ref_pick($h, ['uuid', 'remark', 'address', 'port', 'sni', 'isDisabled', 'securityLayer']);
                $row['inbound'] = (string) ($h['inbound']['configProfileInboundUuid'] ?? '');
                $row['tags'] = array_values(array_filter(array_map('strval', (array) ($h['tags'] ?? [])), 'strlen'));
                // С 3.4 — internalSquads {mode, squads}, в 3.0–3.3 — excludedInternalSquads (то же, что EXCLUDE).
                $isq = is_array($h['internalSquads'] ?? null) ? $h['internalSquads'] : ['mode' => 'EXCLUDE', 'squads' => $h['excludedInternalSquads'] ?? []];
                $row['sqm'] = strtoupper((string) ($isq['mode'] ?? '')) === 'ALLOW_ONLY' ? 'A' : 'E';
                $row['sqs'] = [];
                foreach ((array) ($isq['squads'] ?? []) as $ref) {
                    $id = is_array($ref) ? (string) ($ref['uuid'] ?? $ref['squadUuid'] ?? '') : (string) $ref;
                    if ($id !== '') $row['sqs'][] = $id;
                }
                $row['hid'] = !empty($h['isHidden']);
                $row['nodes'] = [];
                foreach ((array) ($h['nodes'] ?? []) as $ref) {
                    $id = is_array($ref) ? (string) ($ref['uuid'] ?? $ref['nodeUuid'] ?? '') : (string) $ref;
                    if ($id !== '') $row['nodes'][] = $id;
                }
                $out[] = $row;
            }
            return $out;
        }];
        case 'inbounds': return ['/api/config-profiles/inbounds', static function ($d) {
            $out = [];
            foreach (panel_ref_list($d, 'inbounds') as $in) if (is_array($in) && !empty($in['uuid'])) $out[] = panel_ref_pick($in, ['uuid', 'type', 'network', 'security']);
            return $out;
        }];
        case 'isquads': return ['/api/internal-squads', static function ($d) {
            $out = [];
            foreach (panel_ref_list($d, 'internalSquads') as $s) {
                if (!is_array($s) || empty($s['uuid'])) continue;
                $inb = [];
                foreach ((array) ($s['inbounds'] ?? []) as $in) if (is_array($in) && !empty($in['uuid'])) $inb[] = (string) $in['uuid'];
                $out[] = ['uuid' => (string) $s['uuid'], 'name' => (string) ($s['name'] ?? $s['uuid']), 'members' => (int) ($s['info']['membersCount'] ?? 0), 'inb' => $inb];
            }
            return $out;
        }];
        case 'esquads': return ['/api/external-squads', static function ($d) {
            $out = [];
            foreach (panel_ref_list($d, 'externalSquads') as $s) {
                if (!is_array($s) || empty($s['uuid'])) continue;
                $out[] = ['uuid' => (string) $s['uuid'], 'name' => (string) ($s['name'] ?? $s['uuid']), 'members' => (int) ($s['info']['membersCount'] ?? 0), 'hwid' => remnawave_hwid_settings_norm($s['hwidSettings'] ?? null)];
            }
            return $out;
        }];
        case 'subset':  return ['/api/subscription-settings', static function ($d) {
            $r = is_array($d) ? ($d['response'] ?? $d) : null;
            if (!is_array($r)) $r = [];
            return [
                'hwid'  => remnawave_hwid_settings_norm($r['hwidSettings'] ?? null),
                'uuid'  => (string) ($r['uuid'] ?? ''),
                'upd'   => (string) ($r['updatedAt'] ?? ''),
                'rules' => is_array($r['responseRules'] ?? null) ? $r['responseRules'] : null,
                'serve_json'     => !empty($r['serveJsonAtBaseSubscription']),
                'custom_remarks' => !empty($r['isShowCustomRemarks']),
                'randomize'      => !empty($r['randomizeHosts']),
            ];
        }];
    }
    return null;
}

// maxAge 0 — всегда свежий ответ панели (и он же обновляет снимок для остальных).
// При сбое панели: снимок есть и maxAge > 0 — отдаём его и PANEL_REF_RETRY с не ходим снова; иначе null.
// Снимки лежат в admin_cache: settings целиком читаются на каждом запросе подписки, а хосты — сотни КБ.
function panel_ref($name, $maxAge, &$error = '') {
    $error = '';
    $spec = panel_ref_spec($name);
    if ($spec === null) return null;
    $now = time();
    $key = 'pref_' . $name;
    $memo = &$GLOBALS['submw_pref_memo'];
    if (!is_array($memo)) { $memo = []; panel_ref_settings_cleanup(); }
    $fresh = static function ($c) use ($now, $maxAge) { return $maxAge > 0 && is_array($c) && is_array($c['d'] ?? null) && $now - (int) ($c['ts'] ?? 0) <= $maxAge; };
    $c = $memo[$name] ?? null;
    if (!$fresh($c)) $c = admin_cache_get($key, PHP_INT_MAX);
    $has = is_array($c) && is_array($c['d'] ?? null);
    if ($fresh($c)) { $memo[$name] = $c; return $c['d']; }
    if ($has && $maxAge > 0 && $now - (int) ($c['e'] ?? 0) < PANEL_REF_RETRY) { $memo[$name] = $c; $error = (string) ($c['em'] ?? 'панель недоступна'); return $c['d']; }
    if (remnawave_url() === '' || remnawave_token() === '') { $error = 'Не заданы URL панели или API-токен'; return $has && $maxAge > 0 ? $c['d'] : null; }
    // Вызовы, которым годится снимок, не дублируют идущий запрос: ждут его и берут ответ (или его ошибку) —
    // дольше собственного запроса это не длится. Принудительное обновление (maxAge 0) не ждёт.
    $lk = $maxAge > 0 ? panel_ref_lock($name) : null;
    try {
        if ($lk) {
            $etm0 = (float) ($c['etm'] ?? 0);
            $c2 = admin_cache_get($key, PHP_INT_MAX);
            if (is_array($c2) && is_array($c2['d'] ?? null)) {
                $c = $c2;
                $has = true;
                if ($fresh($c)) { $memo[$name] = $c; return $c['d']; }
            }
            if (is_array($c2) && (float) ($c2['etm'] ?? 0) > $etm0) {
                $memo[$name] = $c2;
                $error = (string) ($c2['em'] ?? 'панель недоступна');
                return $has ? $c['d'] : null;
            }
        }
        $t0 = time();
        $tm = microtime(true);
        [$ok, $code, $data, $e] = remnawave_api_get($spec[0]);
        if (!$ok) {
            $error = $e ?: ('HTTP ' . $code);
            $c = $has ? $c : ['ts' => 0, 'd' => null];
            $c['e'] = $t0;
            $c['etm'] = $tm;
            $c['em'] = $error;
            $memo[$name] = $c;
            admin_cache_put($key, $c);
            return $has && $maxAge > 0 ? $c['d'] : null;
        }
        $c = ['ts' => $t0, 'tm' => $tm, 'd' => $spec[1]($data)];
        $memo[$name] = $c;
        admin_cache_put($key, $c);
        return $c['d'];
    } finally {
        if ($lk) { flock($lk, LOCK_UN); fclose($lk); }
    }
}

// Ждём не дольше таймаута запроса к панели; ФС без flock — сразу без блокировки.
function panel_ref_lock($name, $wait = 25) {
    if (!function_exists('default_db_path')) return null;
    $f = @fopen(dirname(default_db_path()) . '/.pref-' . preg_replace('~[^a-z]~', '', (string) $name) . '.lock', 'c');
    if (!$f) return null;
    $until = microtime(true) + $wait;
    while (!flock($f, LOCK_EX | LOCK_NB, $wb)) {
        if (!$wb || microtime(true) >= $until) { fclose($f); return null; }
        usleep(50000);
    }
    return $f;
}

// Снимок, снятый прежней версией без поля $field, перечитывается один раз; неудача запоминается до конца запроса
// и не повторяется, пока действует пауза после сбоя панели.
function panel_ref_upgraded($name, $maxAge, $field) {
    $list = panel_ref($name, $maxAge);
    if (!is_array($list) || !$list || array_filter($list, static fn($r) => is_array($r) && array_key_exists($field, $r))) return $list;
    $tried = &$GLOBALS['submw_pref_upg'][$name];
    $c = admin_cache_get('pref_' . $name, PHP_INT_MAX);
    if ($tried || (is_array($c) && time() - (int) ($c['e'] ?? 0) < PANEL_REF_RETRY)) return $list;
    $tried = true;
    $fresh = panel_ref($name, 0);
    return is_array($fresh) ? $fresh : $list;
}

// Снимки прежних версий лежали в settings — убираем один раз; проверка бесплатна, settings уже в памяти.
function panel_ref_settings_cleanup() {
    setting('sess_nodes', null);
    $keys = [];
    foreach (array_keys((array) ($GLOBALS['submw_settings_cache'] ?? [])) as $k) {
        if (strpos((string) $k, 'pref_') === 0 || $k === 'sess_nodes') $keys[] = (string) $k;
    }
    if (!$keys || !($p = db())) return;
    try {
        $p->prepare('DELETE FROM settings WHERE k IN (' . implode(',', array_fill(0, count($keys), '?')) . ')')->execute($keys);
        foreach ($keys as $k) unset($GLOBALS['submw_settings_cache'][$k]);
    } catch (Throwable $e) {}
}

// Последний снимок как есть — без запроса к панели и без проверки возраста.
function panel_ref_cached($name) {
    $c = $GLOBALS['submw_pref_memo'][$name] ?? admin_cache_get('pref_' . $name, PHP_INT_MAX);
    if (!is_array($c) || !is_array($c['d'] ?? null)) return null;
    if (!is_array($GLOBALS['submw_pref_memo'] ?? null)) { $GLOBALS['submw_pref_memo'] = []; panel_ref_settings_cleanup(); }
    $GLOBALS['submw_pref_memo'][$name] = $c;
    return $c['d'];
}

function panel_ref_stamp($name) {
    $c = $GLOBALS['submw_pref_memo'][$name] ?? admin_cache_get('pref_' . $name, PHP_INT_MAX);
    return is_array($c) ? (string) ($c['tm'] ?? $c['ts'] ?? '') . '/' . count((array) ($c['d'] ?? [])) : '';
}

function panel_ref_ts($name) {
    $c = $GLOBALS['submw_pref_memo'][$name] ?? admin_cache_get('pref_' . $name, PHP_INT_MAX);
    return is_array($c) && is_array($c['d'] ?? null) ? (int) ($c['ts'] ?? 0) : 0;
}

// Список пользователей панели — один снимок на всех потребителей админки и планировщика (строки только из
// этих полей: полный объект пользователя в снимок не кладётся, иначе на большой панели он растёт в разы).
// Кто сходил в панель за полным списком, тот и обновил снимок (panel_users_feed); остальные читают его,
// пока он не старше их maxAge. Горячий путь подписки снимком не пользуется.
const PANEL_USERS_FIELDS = ['id', 'uuid', 'shortUuid', 'username', 'status', 'hwidDeviceLimit', 'expireAt', 'externalSquadUuid', 'activeInternalSquads'];
const PANEL_USERS_GZ = 262144;
const PANEL_USERS_PATCH_GAP = 5;

function panel_users_row($u) {
    if (!is_array($u)) return null;
    $r = panel_ref_pick($u, PANEL_USERS_FIELDS);
    if (array_key_exists('activeInternalSquads', $r)) {
        $sq = [];
        foreach ((array) $r['activeInternalSquads'] as $s) {
            $id = is_array($s) ? (string) ($s['uuid'] ?? '') : (string) $s;
            if ($id !== '') $sq[] = ['uuid' => $id];
        }
        $r['activeInternalSquads'] = $sq;
    }
    return $r;
}

function panel_users_key($row) {
    $su = is_array($row) ? (string) ($row['shortUuid'] ?? '') : '';
    if ($su !== '') return 'su:' . $su;
    $ref = rw_user_ref($row);
    return rw_ref_ok($ref) ? $ref['key'] . ':' . $ref['val'] : '';
}

function panel_users_src() { return md5(remnawave_url() . "\n" . remnawave_token()); }

function &panel_users_memo() {
    $memo = &$GLOBALS['submw_pusers_memo'];
    if (!is_array($memo)) {
        $memo = ['c' => null];
        // Прежние копии списка у отдельных потребителей больше не обновляются — убираем один раз.
        if (setting('pusers_v', '') !== '1' && ($p = db())) {
            try { $p->exec("DELETE FROM admin_cache WHERE k IN ('reqlog_users', 'cmap_names')"); set_setting('pusers_v', '1'); }
            catch (Throwable $e) {}
        }
    }
    return $memo;
}

// Снимок из базы — только от текущей панели; большой хранится сжатым (размер ячейки и max_allowed_packet).
function panel_users_load() {
    $c = admin_cache_get('pref_users', PHP_INT_MAX);
    if (is_array($c) && isset($c['z']) && is_string($c['z'])) {
        $j = function_exists('gzdecode') ? @gzdecode((string) base64_decode($c['z'], true)) : false;
        $c = $j !== false ? json_decode((string) $j, true) : null;
    }
    return is_array($c) && ($c['src'] ?? '') === panel_users_src() ? $c : null;
}

function panel_users_store(array $c) {
    $c['src'] = panel_users_src();
    $GLOBALS['submw_pusers_memo'] = ['c' => $c];
    $j = json_encode($c, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($j === false) return false;
    if (strlen($j) > PANEL_USERS_GZ && function_exists('gzencode')) {
        $z = gzencode($j, 3);
        if ($z !== false) return admin_cache_put('pref_users', ['z' => base64_encode($z)]);
    }
    return admin_cache_put('pref_users', $c);
}

// maxAge 0 — всегда свежий обход панели (и он же обновляет снимок). При сбое панели: снимок есть и maxAge > 0 —
// отдаём его и PANEL_REF_RETRY с не ходим снова; иначе null.
function panel_users($maxAge, &$error = '') {
    $error = '';
    $now = time();
    $memo = &panel_users_memo();
    $fresh = static function ($c) use (&$now, $maxAge) { return $maxAge > 0 && is_array($c) && is_array($c['d'] ?? null) && $now - (int) ($c['ts'] ?? 0) <= $maxAge; };
    $c = $memo['c'];
    if (!$fresh($c)) $c = panel_users_load();
    $has = is_array($c) && is_array($c['d'] ?? null);
    if ($fresh($c)) { $memo['c'] = $c; return $c['d']; }
    if ($has && $maxAge > 0 && $now - (int) ($c['e'] ?? 0) < PANEL_REF_RETRY) { $memo['c'] = $c; $error = (string) ($c['em'] ?? 'панель недоступна'); return $c['d']; }
    if (remnawave_url() === '' || remnawave_token() === '') { $error = 'Не заданы URL панели или API-токен'; return $has && $maxAge > 0 ? $c['d'] : null; }
    $lk = $maxAge > 0 ? panel_ref_lock('users') : null;
    try {
        if ($lk) {
            $now = time();
            $etm0 = (float) ($c['etm'] ?? 0);
            $c2 = panel_users_load();
            if (is_array($c2) && is_array($c2['d'] ?? null)) {
                $c = $c2;
                $has = true;
                if ($fresh($c)) { $memo['c'] = $c; return $c['d']; }
            }
            if (is_array($c2) && (float) ($c2['etm'] ?? 0) > $etm0) {
                $memo['c'] = $c2;
                $error = (string) ($c2['em'] ?? 'панель недоступна');
                return $has ? $c['d'] : null;
            }
        }
        $t0 = time();
        $tm = microtime(true);
        $rows = [];
        $e = '';
        $take = static function (array $page) use (&$rows) { foreach ($page as $u) if (($r = panel_users_row($u)) !== null) $rows[] = $r; };
        $take(remnawave_all_users($e, $take, PANEL_USERS_FIELDS));
        if ($e !== '') {
            $error = $e;
            $c = $has ? $c : ['ts' => 0, 'd' => null];
            $c['e'] = $t0;
            $c['etm'] = $tm;
            $c['em'] = $error;
            panel_users_store($c);
            return $has && $maxAge > 0 ? $c['d'] : null;
        }
        $c = ['ts' => $t0, 'tm' => $tm, 'd' => $rows];
        panel_users_store($c);
        return $rows;
    } finally {
        if ($lk) { flock($lk, LOCK_UN); fclose($lk); }
    }
}

// Полный список, который потребитель только что получил сам (вкладка «Пользователи», индекс c1, обход через Go),
// становится снимком для остальных; $ts — когда обход начался, а не закончился. Неполный список сюда не передавать.
function panel_users_feed(array $users, $ts = null) {
    $rows = [];
    foreach ($users as $u) if (($r = panel_users_row($u)) !== null) $rows[] = $r;
    panel_users_memo();
    panel_users_store(['ts' => $ts === null ? time() : (int) $ts, 'tm' => microtime(true), 'd' => $rows]);
}

// Последний снимок как есть — без запроса к панели и без проверки возраста.
function panel_users_cached() {
    $memo = &panel_users_memo();
    $c = $memo['c'] ?? panel_users_load();
    if (!is_array($c) || !is_array($c['d'] ?? null)) return null;
    $memo['c'] = $c;
    return $c['d'];
}

function panel_users_ts() {
    $memo = &panel_users_memo();
    $c = $memo['c'] ?? panel_users_load();
    return is_array($c) && is_array($c['d'] ?? null) ? (int) ($c['ts'] ?? 0) : 0;
}

// Одна строка снимка после записи: правка, создание ($drop = false) или удаление ($drop = true). Возраст снимка
// не меняется — он по-прежнему возраст всего списка. Чтение и запись — под тем же замком, что и обход, иначе
// параллельный полный обход затирается старой копией; замок занят дольше $wait с — строка доедет по возрасту
// снимка, как и раньше (из админки ждать долго нельзя). $gap > 0 — не чаще раза в $gap секунд (пачки вебхуков).
function panel_users_patch($u, $drop = false, $gap = 0, $wait = 3) {
    $row = panel_users_row($u);
    $key = panel_users_key($row);
    if ($key === '') return;
    if ($gap > 0 && time() - (int) setting('pusers_pt', '0') < $gap) return;
    $lk = panel_ref_lock('users', $wait);
    if (!$lk) return;
    try {
        panel_users_memo();
        $c = panel_users_load();
        if (!is_array($c) || !is_array($c['d'] ?? null)) return;
        if ($gap > 0) set_setting('pusers_pt', (string) time());
        $found = false;
        foreach ($c['d'] as $i => $r) {
            if (panel_users_key($r) !== $key) continue;
            if ($drop) unset($c['d'][$i]); else $c['d'][$i] = $row;
            $found = true;
            break;
        }
        if (!$found && !$drop) $c['d'][] = $row;
        elseif (!$found) return;
        $c['d'] = array_values($c['d']);
        panel_users_store($c);
    } finally {
        if ($lk) { flock($lk, LOCK_UN); fclose($lk); }
    }
}

// Сменили панель или токен — снимки справочников и пользователей от прежней панели не нужны.
function panel_snapshots_drop() {
    unset($GLOBALS['submw_pref_memo'], $GLOBALS['submw_pusers_memo']);
    if (!($p = db())) return;
    try { $p->exec("DELETE FROM admin_cache WHERE k LIKE 'pref!_%' ESCAPE '!'"); }
    catch (Throwable $e) {}
}

function remnawave_internal_squads(&$error = '', $maxAge = 0) {
    $out = panel_ref('isquads', $maxAge, $error);
    return is_array($out) ? $out : [];
}

function remnawave_external_squads(&$error = '', $maxAge = 0) {
    $out = panel_ref('esquads', $maxAge, $error);
    return is_array($out) ? $out : [];
}

function remnawave_hwid_settings_norm($h) {
    if (!is_array($h) || !array_key_exists('enabled', $h)) return null;
    return ['enabled' => (bool) $h['enabled'], 'limit' => isset($h['fallbackDeviceLimit']) ? (int) $h['fallbackDeviceLimit'] : null];
}

function remnawave_hwid_settings(&$error = '', $maxAge = 0) {
    $out = panel_ref('subset', $maxAge, $error);
    return is_array($out) ? ($out['hwid'] ?? null) : null;
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

function remnawave_sub_template_json($uuid, &$error = '') {
    $error = '';
    $uuid = trim((string) $uuid);
    if ($uuid === '') { $error = 'Пустой uuid шаблона'; return null; }
    [$ok, $code, $data, $e] = remnawave_api_get('/api/subscription-templates/' . rawurlencode($uuid), false);
    if (!$ok) { $error = $e ?: ('HTTP ' . $code); return null; }
    $resp = (is_object($data) && isset($data->response)) ? $data->response : $data;
    $tpl = is_object($resp) ? ($resp->templateJson ?? null) : null;
    if (!is_object($tpl) || !get_object_vars($tpl)) { $error = 'Шаблон без templateJson'; return null; }
    return $tpl;
}

// Ответ панели по одному пользователю помнится до конца запроса (и не дольше USER_MEMO_TTL — на случай долгого
// процесса), отдельно для каждой панели: один и тот же пользователь нужен нескольким модулям подряд (доп.
// конфиги, слияние подписок, карточка, поиск). Любая наша запись в /api/users сбрасывает memo. Помнится и
// отказ — в пределах запроса пользователь не появится, а таймаут панели второй раз ждать незачем.
const USER_MEMO_TTL = 5;

function rw_user_memo($key, ?array $put = null) {
    $m = &$GLOBALS['submw_user_memo'];
    if (!is_array($m)) $m = [];
    $key = panel_users_src() . '|' . $key;
    $now = microtime(true);
    if ($put !== null) {
        foreach ($m as $k => $v) if ($now - (float) ($v['t'] ?? 0) >= USER_MEMO_TTL) unset($m[$k]);
        $m[$key] = $put + ['t' => $now];
        return $put;
    }
    $v = $m[$key] ?? null;
    if (!is_array($v) || $now - (float) $v['t'] >= USER_MEMO_TTL) return null;
    // Ожидаемый код ответа относился к этому запросу: без похода в панель его нельзя оставить следующему.
    unset($GLOBALS['submw_apistat_expect']);
    return $v;
}

function rw_user_memo_drop() { unset($GLOBALS['submw_user_memo']); }

function remnawave_get_user_by_short($shortUuid, &$error = '', &$http_code = 0) {
    $error = ''; $http_code = 0;
    if ($shortUuid === '') { $error = 'Пустой shortUuid'; return null; }
    $k = 'su:' . $shortUuid;
    if (($m = rw_user_memo($k)) !== null) { $error = $m['e']; $http_code = $m['c']; return $m['u']; }
    [$ok, $code, $data, $e] = remnawave_api_request('GET', '/api/users/by-short-uuid/' . rawurlencode($shortUuid));
    $http_code = (int) $code;
    if (!$ok) { $error = $e ?: ('HTTP ' . $code); rw_user_memo($k, ['u' => null, 'e' => $error, 'c' => $http_code]); return null; }
    $resp = $data['response'] ?? $data;
    $u = is_array($resp) ? $resp : null;
    rw_user_memo($k, ['u' => $u, 'e' => '', 'c' => $http_code]);
    return $u;
}

function remnawave_get_user_by_username($username, &$error = '') {
    $error = '';
    $username = (string) $username;
    if ($username === '') { $error = 'Пустой username'; return null; }
    $k = 'un:' . $username;
    if (($m = rw_user_memo($k)) !== null) { $error = $m['e']; return $m['u']; }
    [$ok, $code, $data, $e] = remnawave_api_request('GET', '/api/users/by-username/' . rawurlencode($username));
    if (!$ok) { $error = $e ?: ('HTTP ' . $code); rw_user_memo($k, ['u' => null, 'e' => $error, 'c' => (int) $code]); return null; }
    $resp = $data['response'] ?? $data;
    if (is_array($resp) && isset($resp['users']) && is_array($resp['users'])) $resp = $resp['users'][0] ?? null;
    elseif (is_array($resp) && isset($resp[0]) && is_array($resp[0])) $resp = $resp[0];
    $u = is_array($resp) ? $resp : null;
    rw_user_memo($k, ['u' => $u, 'e' => '', 'c' => (int) $code]);
    return $u;
}

function api_ctx($src = null, $short = null) {
    static $ctx = ['src' => '', 'short' => ''];
    if ($src !== null) $ctx = ['src' => (string) $src, 'short' => (string) $short];
    return $ctx;
}

function remnawave_update_user($ref, array $fields, &$error = '', &$http_code = 0, &$resp = null) {
    $error = ''; $http_code = 0; $resp = null;
    rw_user_memo_drop();
    $ref = rw_ref_coerce($ref);
    if (!rw_ref_ok($ref)) { $error = 'Пустой идентификатор пользователя'; return false; }
    $body = array_merge([$ref['key'] => rw_ref_body_value($ref)], $fields);
    [$ok, $code, $data, $e] = remnawave_api_request('PATCH', '/api/users', $body);
    $http_code = (int) $code;
    $resp = $data;
    if (!$ok) $error = $e ?: ('HTTP ' . $code . ' ' . json_encode($data, JSON_UNESCAPED_UNICODE));
    if (function_exists('log_panel_write')) {
        $ctx = api_ctx();
        log_panel_write($ctx['short'], $ref, 'patch', $ctx['src'], $fields, $ok, (int) $code, $error);
    }
    return $ok ? true : false;
}

function remnawave_reset_traffic($ref, &$error = '') {
    $error = '';
    rw_user_memo_drop();
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


function panel_err_text($data, $code, $fallback = '') {
    $t = '';
    if (is_array($data)) {
        $m = $data['message'] ?? '';
        if (is_array($m)) $m = implode('; ', array_map('strval', $m));
        $t = trim((string) $m);
        $parts = [];
        foreach ((is_array($data['errors'] ?? null) ? $data['errors'] : []) as $er) {
            if (!is_array($er) || !isset($er['message'])) continue;
            $path = is_array($er['path'] ?? null) ? implode('.', $er['path']) : '';
            $parts[] = ($path !== '' ? $path . ': ' : '') . (string) $er['message'];
        }
        if ($parts) $t = trim(($t !== '' && $t !== 'Validation failed' ? $t . ' — ' : '') . implode('; ', $parts));
    }
    if ($t === '') $t = (string) $fallback !== '' ? (string) $fallback : ('HTTP ' . (int) $code);
    return $t;
}

function remnawave_user_post($ref, $action, $op, $body = null, &$error = '', &$http_code = 0, &$resp = null) {
    $error = ''; $http_code = 0; $resp = null;
    rw_user_memo_drop();
    $ref = rw_ref_coerce($ref);
    if (!rw_ref_ok($ref)) { $error = 'Пустой идентификатор пользователя'; return false; }
    [$ok, $code, $data, $e] = remnawave_api_request('POST', '/api/users/' . rawurlencode($ref['val']) . '/actions/' . $action, $body);
    $http_code = (int) $code;
    $resp = $data;
    if (!$ok) $error = panel_err_text($data, $code, $e);
    if (function_exists('log_panel_write')) {
        $ctx = api_ctx();
        log_panel_write($ctx['short'], $ref, $op, $ctx['src'], is_array($body) ? $body : [], $ok, (int) $code, $error);
    }
    return $ok ? true : false;
}

function remnawave_create_user(array $body, &$error = '', &$http_code = 0) {
    $error = ''; $http_code = 0;
    rw_user_memo_drop();
    [$ok, $code, $data, $e] = remnawave_api_request('POST', '/api/users', $body);
    $http_code = (int) $code;
    $u = $ok ? ($data['response'] ?? $data) : null;
    if (!$ok) $error = panel_err_text($data, $code, $e);
    elseif (!is_array($u) || empty($u['shortUuid'])) { $error = 'Панель не вернула созданного пользователя'; $u = null; }
    if (function_exists('log_panel_write')) {
        $ctx = api_ctx();
        $lb = $body;
        unset($lb['trojanPassword'], $lb['ssPassword'], $lb['vlessUuid']);
        log_panel_write(is_array($u) ? (string) $u['shortUuid'] : '', is_array($u) ? rw_user_ref($u) : null, 'create', $ctx['src'], $lb, $ok, (int) $code, $error);
    }
    return $u;
}

function remnawave_delete_user($ref, &$error = '', &$http_code = 0) {
    $error = ''; $http_code = 0;
    rw_user_memo_drop();
    $ref = rw_ref_coerce($ref);
    if (!rw_ref_ok($ref)) { $error = 'Пустой идентификатор пользователя'; return false; }
    [$ok, $code, $data, $e] = remnawave_api_request('DELETE', '/api/users/' . rawurlencode($ref['val']));
    $http_code = (int) $code;
    if (!$ok) $error = panel_err_text($data, $code, $e);
    if (function_exists('log_panel_write')) {
        $ctx = api_ctx();
        log_panel_write($ctx['short'], $ref, 'delete', $ctx['src'], [], $ok, (int) $code, $error);
    }
    return $ok ? true : false;
}

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

function panel_meta_cached() {
    $c = json_decode((string) setting('panelmeta_json', ''), true);
    return is_array($c) ? $c : [];
}

function panel_version() {
    $c = panel_meta_cached();
    return trim((string) ($c['version'] ?? ''));
}

function panel_major() {
    $v = panel_version();
    return preg_match('/^\s*v?(\d+)/', $v, $m) ? (int) $m[1] : 0;
}

function panel_api_v3() { return panel_major() >= 3; }

function panel_version_supported() {
    $v = panel_version();
    if ($v === '') return true;
    return version_compare(ltrim($v, 'vV'), panel_min_supported(), '>=');
}

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

function panel_config_sig($c) {
    if (!is_array($c) || !$c) return '';
    $v = [];
    foreach (panel_config_value_keys() as $k) $v[$k] = $c[$k] ?? null;
    return md5(json_encode($v, JSON_UNESCAPED_UNICODE));
}

function panel_sub_public_domain() {
    $c = panel_config_cached();
    return panel_config_domain($c['sub_public_domain'] ?? '');
}

function panel_short_uuid_len() {
    $c = panel_config_cached();
    $n = (int) ($c['short_uuid_length'] ?? 0);
    return ($n >= 8 && $n <= 64) ? $n : 0;
}

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
    // Список нод — из общего снимка не старше собственного кэша блока (его освежает и опрос сессий).
    $list = panel_ref('nodes', max(0, (int) $maxAge));
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
    set_setting('panelstats_json', json_encode($out, JSON_UNESCAPED_UNICODE));
    set_setting('panelstats_ts', (string) $now);
    return $out;
}
