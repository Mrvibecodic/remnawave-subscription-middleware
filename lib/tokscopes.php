<?php

function tokscope_catalog() {
    $rnd = 'submw-probe-' . bin2hex(random_bytes(4));
    $grace = grace_squad_enabled();
    $pool = squadconf_any();
    return [
        ['s' => 'system:metadata', 'm' => 'GET', 'p' => '/api/system/metadata', 'w' => 'Версия панели — без неё ломаются грейс и операции с пользователями', 'need' => true],
        ['s' => 'system:configuration', 'm' => 'GET', 'p' => '/api/system/configuration', 'w' => 'Настройки панели: «Подключение», «Вебхуки», длина shortUuid (панель 3.2.0+)', 'need' => panel_supports_config()],
        ['s' => 'system:stats', 'm' => 'GET', 'p' => '/api/system/stats', 'w' => 'Статистика панели на «О системе»', 'need' => true],
        ['s' => 'nodes:list', 'm' => 'GET', 'p' => '/api/nodes', 'w' => 'Счётчик нод на «О системе»; страна узла на «Статистике» Clod Clash', 'need' => true],
        ['s' => 'hosts:list', 'm' => 'GET', 'p' => '/api/hosts', 'w' => 'Адрес хоста → узел и его страна на «Статистике» Clod Clash', 'need' => rep_enabled()],
        ['s' => 'users:list', 'm' => 'GET', 'p' => '/api/users?size=1&start=0', 'w' => 'Список «Пользователи», имена в «Оверрайдах» и «Логе запросов»', 'need' => true],
        ['s' => 'users:by-short-uuid', 'm' => 'GET', 'p' => '/api/users/by-short-uuid/' . $rnd, 'w' => 'Пользователь по ссылке: грейс, доп. конфиги, слияние, имена в логе вебхуков', 'need' => true],
        ['s' => 'users:by-username', 'm' => 'GET', 'p' => '/api/users/by-username/' . $rnd, 'w' => 'Поиск по имени: слияние подписок, WG / AWG', 'need' => addsub_enabled() || $pool],
        ['s' => 'users:update', 'm' => 'PATCH', 'p' => '/api/users', 'b' => '[]', 'w' => 'Грейс-сквад: перевод в грейс и возврат (запись)', 'need' => $grace],
        ['s' => 'users:reset-traffic', 'm' => 'POST', 'p' => '/api/users/submw-probe/actions/reset-traffic', 'b' => '[]', 'w' => 'Грейс-сквад: сброс трафика на входе и выходе (запись)', 'need' => $grace && (grace_traffic_bytes() > 0 || grace_reset_traffic_on_exit())],
        ['s' => 'hwid-user-devices:list', 'm' => 'GET', 'p' => '/api/hwid/devices?size=1&start=0', 'w' => 'Подсчёт устройств для пула WG / AWG', 'need' => $pool],
        ['s' => 'hwid-user-devices:list-by-user', 'm' => 'GET', 'p' => '/api/hwid/devices/submw-probe', 'w' => 'Устройства пользователя во вкладке «Пользователи»', 'need' => true],
        ['s' => 'hwid-user-devices:delete', 'm' => 'POST', 'p' => '/api/hwid/devices/delete', 'b' => '[]', 'w' => 'Кнопка «удалить устройство» (запись)', 'need' => false],
        ['s' => 'internal-squads:list', 'm' => 'GET', 'p' => '/api/internal-squads', 'w' => 'Списки сквадов в настройках', 'need' => true],
        ['s' => 'external-squads:list', 'm' => 'GET', 'p' => '/api/external-squads', 'w' => 'Внешний сквад для грейса', 'need' => $grace],
        ['s' => 'subscription-template:list', 'm' => 'GET', 'p' => '/api/subscription-templates', 'w' => 'Поиск шаблона xray-json для доп. конфигов', 'need' => $pool && squadconf_xray_json_enabled()],
        ['s' => 'subscription-template:get', 'm' => 'GET', 'p' => '/api/subscription-templates/submw-probe', 'w' => 'Тело шаблона xray-json для доп. конфигов', 'need' => $pool && squadconf_xray_json_enabled()],
        ['s' => 'subscriptions:by-short-uuid-protected', 'm' => 'GET', 'p' => '/api/subscriptions/by-short-uuid/' . $rnd, 'w' => 'Хосты панели и серверы второй подписки для «Ключей на странице»', 'need' => pagekeys_active() && (pagekeys_with_panel() || (pagekeys_with_addsub() && addsub_enabled()))],
    ];
}

function tokscope_classify($code, $body) {
    $code = (int) $code;
    if ($code === 0) return 'err';
    if ($code === 401) return 'auth';
    if ($code === 403) return 'miss';
    if ($code === 404) {
        $j = json_decode((string) $body, true);
        if (!is_array($j)) return 'na';
        if (preg_match('~^Cannot (GET|POST|PATCH|PUT|DELETE) ~', (string) ($j['message'] ?? ''))) return 'na';
        return 'ok';
    }
    if ($code === 429 || $code >= 500) return 'err';
    return 'ok';
}

function tokscope_probe(array $only = []) {
    $base = remnawave_url();
    $token = remnawave_token();
    if ($base === '' || $token === '') return ['ok' => false, 'msg' => 'Не заданы URL панели или API-токен', 'rows' => [], 'ts' => time()];
    $headers = ['Authorization: Bearer ' . $token, 'Accept: application/json'];
    if (strpos($base, 'http://') === 0) {
        $headers[] = 'x-forwarded-proto: https';
        $headers[] = 'x-forwarded-for: 127.0.0.1';
    }
    $headers = panel_auth_headers($headers);
    $cat = tokscope_catalog();
    if ($only) $cat = array_values(array_filter($cat, fn($r) => in_array($r['s'], $only, true)));
    $mh = curl_multi_init();
    $hs = [];
    foreach ($cat as $i => $r) {
        $h = $headers;
        $ch = curl_init();
        $opt = [
            CURLOPT_URL            => $base . $r['p'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => api_tls_verify(),
            CURLOPT_SSL_VERIFYHOST => api_tls_verify() ? 2 : 0,
            CURLOPT_CUSTOMREQUEST  => $r['m'],
        ];
        if (isset($r['b'])) {
            $h[] = 'Content-Type: application/json';
            $opt[CURLOPT_POSTFIELDS] = $r['b'];
        }
        $opt[CURLOPT_HTTPHEADER] = $h;
        curl_setopt_array($ch, $opt);
        curl_multi_add_handle($mh, $ch);
        $hs[$i] = $ch;
    }
    do {
        $mrc = curl_multi_exec($mh, $active);
        if ($active && curl_multi_select($mh, 1.0) === -1) usleep(10000);
    } while ($active && $mrc === CURLM_OK);
    $res = [];
    while (($mi = curl_multi_info_read($mh)) !== false) {
        foreach ($hs as $i => $ch) if ($mi['handle'] === $ch) $res[$i] = (int) $mi['result'];
    }
    $rows = [];
    foreach ($cat as $i => $r) {
        $ch = $hs[$i];
        $body = curl_multi_getcontent($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        if ($err === '' && ($res[$i] ?? CURLE_OK) !== CURLE_OK) $err = curl_strerror($res[$i]);
        if ($err === '' && $code === 0) $err = 'нет ответа';
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
        $st = $err !== '' ? 'err' : tokscope_classify($code, $body);
        $rows[] = ['s' => $r['s'], 'w' => $r['w'], 'need' => (bool) $r['need'], 'st' => $st, 'code' => $code, 'e' => $err !== '' ? mb_substr($err, 0, 120) : ''];
    }
    curl_multi_close($mh);
    $n = count($rows);
    $cnt = array_count_values(array_column($rows, 'st'));
    $msg = '';
    if ($n && ($cnt['auth'] ?? 0) === $n) $msg = 'Панель не принимает токен (HTTP 401) — проверьте сам токен';
    elseif ($n && ($cnt['err'] ?? 0) === $n) $msg = 'Панель не ответила — проверьте URL панели';
    elseif ($n > 3 && ($cnt['miss'] ?? 0) === $n) $msg = 'Отказано во всех запросах (HTTP 403) — либо у токена нет ни одного из нужных прав, либо запросы режет прокси перед панелью (проверьте X-Api-Key)';
    return ['ok' => $msg === '', 'msg' => $msg, 'rows' => $rows, 'ts' => time(), 'ver' => panel_version()];
}

function tokscope_run() {
    $r = tokscope_probe();
    if ($r['rows']) set_setting('tokscopes_json', json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    return $r;
}

function tokscope_cached() {
    $j = json_decode((string) setting('tokscopes_json', ''), true);
    if (!is_array($j) || empty($j['rows']) || !is_array($j['rows'])) return null;
    $need = array_column(tokscope_catalog(), 'need', 's');
    foreach ($j['rows'] as $i => $r) {
        if (isset($r['s']) && array_key_exists($r['s'], $need)) $j['rows'][$i]['need'] = (bool) $need[$r['s']];
    }
    return $j;
}

function tokscope_missing(array $res, $need_only) {
    $out = [];
    foreach ($res['rows'] ?? [] as $r) {
        if (($r['st'] ?? '') !== 'miss') continue;
        if ($need_only && empty($r['need'])) continue;
        $out[] = $r['s'];
    }
    return $out;
}
