<?php

// Не справился Go — его работу делает PHP, как раньше.

const GO_BRIDGE = 1;
const GO_FORGET = 7 * 86400;
const GO_DOC_URL = 'https://mrvibecodic.github.io/subm/ru/docs/go-service/';

function go_off() { return setting('go_off', '0') === '1'; }

function go_url_set() { return rtrim(trim((string) setting('go_url', '')), '/'); }

function go_url_default() {
    return (function_exists('submw_in_docker') ? submw_in_docker() : getenv('SUBMW_DOCKER') === '1') ? 'http://submw-go:8090' : 'http://127.0.0.1:8090';
}

function go_url() {
    $u = go_url_set();
    return $u !== '' ? $u : go_url_default();
}

function go_url_norm($u, &$err = '') {
    $err = '';
    $u = rtrim(trim((string) $u), '/');
    if ($u === '') return '';
    $p = parse_url($u);
    if (!is_array($p) || !in_array(strtolower((string) ($p['scheme'] ?? '')), ['http', 'https'], true) || (string) ($p['host'] ?? '') === ''
        || isset($p['user']) || isset($p['pass']) || isset($p['query']) || isset($p['fragment']) || trim((string) ($p['path'] ?? ''), '/') !== '') {
        $err = 'Адрес Go-сервиса — вида http://хост:порт';
        return null;
    }
    return strtolower($p['scheme']) . '://' . $p['host'] . (isset($p['port']) ? ':' . (int) $p['port'] : '');
}

function go_secret() {
    $s = (string) setting('go_secret', '');
    return preg_match('~^[A-Za-z0-9]{32}$~', $s) ? $s : go_secret_rotate();
}

function go_secret_rotate() {
    $a = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    $s = '';
    for ($i = 0; $i < 32; $i++) $s .= $a[random_int(0, 61)];
    set_setting('go_secret', $s);
    return $s;
}

function go_state() {
    $s = json_decode((string) setting('go_state', ''), true);
    return is_array($s) ? $s : [];
}

function go_state_put(array $s) {
    set_setting('go_state', json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
}

// Go «знаком», если адрес задан руками или сервис уже отвечал: тогда его молчание — тревога, а не норма.
// Не отвечает неделю при пустом адресе — считаем, что его убрали.
function go_known(?array $s = null) {
    if (go_url_set() !== '') return true;
    $s = $s ?? go_state();
    return (int) ($s['seen'] ?? 0) > 0 && time() - (int) ($s['ok_ts'] ?? 0) < GO_FORGET;
}

function go_wanted() { return !go_off(); }

// $fail — Go ответил, но дело не сделал (панель из его сети не открывается): сервис есть, опрос всё равно за PHP.
function go_mark_ok(array $info, ?array $fail = null) {
    $p = go_state();
    $now = time();
    $first = (int) ($p['seen'] ?? 0) === 0;
    $s = ['ok' => $fail === null, 'ts' => $now, 'ok_ts' => $now, 'seen' => $first ? $now : (int) $p['seen'], 'url' => go_url(),
          'v' => mb_substr((string) ($info['v'] ?? ''), 0, 40), 'commit' => substr((string) ($info['commit'] ?? ''), 0, 40),
          'bridge' => (int) ($info['bridge'] ?? 0), 'err' => '', 'detail' => '', 'fail_since' => 0, 'probe' => (int) ($p['probe'] ?? 0),
          'caps' => null, 'caps_of' => ''];
    // Что умеет Go — знает только его hello. Ответ на работу (в нём есть hits) оставляет прежнее, если Go тот же;
    // сменился образ — неизвестно, пока Go не спросят снова.
    $ver = $s['v'] . '|' . $s['commit'];
    if (!array_key_exists('hits', $info)) {
        $s['caps'] = array_values(array_filter(array_map('strval', (array) ($info['caps'] ?? []))));
        $s['caps_of'] = $ver;
    } elseif (is_array($p['caps'] ?? null) && (string) ($p['caps_of'] ?? '') === $ver) {
        $s['caps'] = $p['caps'];
        $s['caps_of'] = $ver;
    }
    if ($fail !== null) {
        $s['err'] = (string) $fail[0];
        $s['detail'] = mb_substr((string) $fail[1], 0, 200);
        $s['fail_since'] = empty($p['ok']) && (int) ($p['fail_since'] ?? 0) > 0 ? (int) $p['fail_since'] : $now;
    }
    go_state_put($s);
    // Первое появление Go: интервал по умолчанию — минута. Один раз, дальше настройка за админом.
    if ($first && setting('go_every_done', '') !== '1') {
        if (in_array((string) setting('sess_every', ''), ['', '120'], true)) set_setting('sess_every', '60');
        set_setting('go_every_done', '1');
    }
}

// Go, который о своих умениях не рассказывал (старый образ или ещё не спрошен), считается не умеющим.
function go_can($cap) {
    $c = go_state()['caps'] ?? null;
    return is_array($c) && in_array((string) $cap, $c, true);
}

// Без «Сессий» Go больше никто не проверяет — вернулся ли он, спрашивает список пользователей, не чаще раза в 5 минут.
function go_probe() {
    if (!go_known()) return;
    $s = go_state();
    if (time() - (int) ($s['probe'] ?? 0) < 300) return;
    $s['probe'] = time();
    go_state_put($s);
    go_hello($e);
}

function go_mark_fail($kind, $detail) {
    $s = go_state();
    $now = time();
    $s['fail_since'] = !empty($s['ok']) || (int) ($s['fail_since'] ?? 0) === 0 ? $now : (int) $s['fail_since'];
    $s['ok'] = false;
    $s['ts'] = $now;
    $s['url'] = go_url();
    $s['err'] = (string) $kind;
    $s['detail'] = mb_substr((string) $detail, 0, 200);
    go_state_put($s);
}

function go_err_text($kind, $detail = '') {
    $d = (string) $detail;
    switch ((string) $kind) {
        case 'down':     return 'не отвечает' . ($d !== '' ? ': ' . $d : '');
        case 'secret':   return 'секрет не подходит — впишите текущий в Go-контейнер';
        case 'nosecret': return 'в Go-контейнере не задан секрет (SUBMW_GO_SECRET)';
        case 'bridge':   return 'версия Go-сервиса не подходит к прослойке — обновите образ Go';
        case 'panel':    return 'не достучался до панели' . ($d !== '' ? ': ' . $d : '');
        case 'bad':      return 'Go вернул ошибку' . ($d !== '' ? ': ' . $d : '');
        case 'nocap':    return 'Go-сервис старой версии этого не умеет — обновите образ Go';
    }
    return $d !== '' ? $d : 'ещё не проверялся';
}

// Прокси из окружения не используется: сервис всегда локальный.
function go_call($method, $path, ?array $body, $timeout, &$kind = '', &$detail = '') {
    $kind = '';
    $detail = '';
    if (!function_exists('curl_init')) { $kind = 'down'; $detail = 'нет curl'; return null; }
    $h = ['Authorization: Bearer ' . go_secret(), 'Accept: application/json'];
    $opt = [
        CURLOPT_URL               => go_url() . $path,
        CURLOPT_RETURNTRANSFER    => true,
        CURLOPT_CONNECTTIMEOUT_MS => 1500,
        CURLOPT_TIMEOUT_MS        => (int) max(2000, $timeout * 1000),
        CURLOPT_CUSTOMREQUEST     => $method,
        CURLOPT_FOLLOWLOCATION    => false,
        CURLOPT_PROXY             => '',
        CURLOPT_NOPROXY           => '*',
    ];
    if ($body !== null) {
        $h[] = 'Content-Type: application/json';
        $opt[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }
    $opt[CURLOPT_HTTPHEADER] = $h;
    $ch = curl_init();
    curl_setopt_array($ch, $opt);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $errno = curl_errno($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false || $errno) {
        $kind = 'down';
        $detail = [28 => 'нет ответа за ' . (int) round($timeout) . ' с', 6 => 'адрес не найден', 7 => 'соединение отклонено'][$errno] ?? $err;
        return null;
    }
    $j = json_decode((string) $raw, true);
    // Свой адрес Go не знает: образ старше прослойки. Сам Go при этом исправен.
    if ($code === 404 && !is_array($j) && $path !== '/v1/hello') { $kind = 'nocap'; return null; }
    if ($code === 401 && is_array($j) && ($j['err'] ?? '') === 'secret') { $kind = 'secret'; return null; }
    if ($code === 503 && is_array($j) && ($j['err'] ?? '') === 'nosecret') { $kind = 'nosecret'; return null; }
    // Свой ответ Go всегда несёт версию моста: без неё по адресу отвечает что-то другое.
    if (!is_array($j) || !isset($j['bridge'])) { $kind = 'down'; $detail = 'по адресу отвечает не Go-сервис (HTTP ' . $code . ')'; return null; }
    if ((int) $j['bridge'] !== GO_BRIDGE) { $kind = 'bridge'; return null; }
    if ($code < 200 || $code >= 300 || empty($j['ok'])) { $kind = 'bad'; $detail = (string) ($j['err'] ?? ('HTTP ' . $code)); return null; }
    return $j;
}

function go_hello(&$err = '') {
    $err = '';
    $j = go_call('GET', '/v1/hello', null, 3, $kind, $detail);
    if ($j === null) {
        if ($kind !== 'down' || go_known()) go_mark_fail($kind, $detail);
        $err = go_err_text($kind, $detail);
        return null;
    }
    go_mark_ok($j);
    return $j;
}

function go_sess_tpl(callable $f) {
    return str_replace('__ID__', '{id}', (string) $f('__ID__'));
}

// Опрос нод через Go. null — Go не справился (причина в $err), иначе результаты по нодам в том же виде,
// что у sess_jobs_wait: массив результата, false — не ответила, null — не стартовала.
function go_sess_poll(array $nodes, $until, &$err = '', &$first = '', &$reqs = 0, &$retried = []) {
    $err = '';
    $first = '';
    $reqs = 0;
    $flipped = false;
    $ids = array_map('strval', array_keys($nodes));
    // Токен панели уходит только сервису, который уже назвался Go-сервисом: по адресу по умолчанию может слушать чужая программа.
    if (empty(go_state()['ok'])) {
        // Незнакомый Go ищем не чаще раза в 5 минут: иначе молчащий чужой порт съедал бы время каждого опроса.
        // Отметка — отдельно от go_state: без Go о нём ничего не хранится.
        if (!go_known()) {
            if (time() - (int) setting('go_seek', '0') < 300) { $err = go_err_text('down', 'не найден'); return null; }
            set_setting('go_seek', (string) time());
        }
        if (go_call('GET', '/v1/hello', null, 3, $kind, $detail) === null) {
            if ($kind !== 'down' || go_known()) go_mark_fail($kind, $detail);
            $err = go_err_text($kind, $detail);
            return null;
        }
    }
    while (true) {
        $left = $until - microtime(true);
        if ($left < 1.5) { $err = go_err_text('down', 'не хватило времени'); return null; }
        $P = sess_paths();
        $body = [
            'panel'     => ['url' => remnawave_url(), 'token' => remnawave_token(), 'cookie' => remnawave_cookie(), 'xkey' => remnawave_xapikey(), 'tls' => api_tls_verify()],
            'start'     => go_sess_tpl($P['node']),
            'result'    => go_sess_tpl($P['node_res']),
            'nodes'     => $ids,
            'budget_ms' => (int) (($left - 0.5) * 1000),
        ];
        $j = go_call('POST', '/v1/sessions/poll', $body, $left + 1, $kind, $detail);
        if ($j === null) {
            if ($kind !== 'down' || go_known()) go_mark_fail($kind, $detail);
            $err = go_err_text($kind, $detail);
            return null;
        }
        foreach ((array) ($j['hits'] ?? []) as $h) {
            if (!is_array($h)) continue;
            $reqs++;
            if (function_exists('apistat_hit')) apistat_hit((string) ($h['m'] ?? 'GET'), (string) ($h['p'] ?? ''), (int) ($h['c'] ?? 0), (float) ($h['ms'] ?? 0), 'go:sessions');
        }
        // Go жив, но панель из его сети не открывается — PHP попробует сам; это настройка сети, а не сбой Go.
        if ((string) ($j['panel_down'] ?? '') !== '') {
            go_mark_ok($j, ['panel', (string) $j['panel_down']]);
            $err = go_err_text('panel', (string) $j['panel_down']);
            return null;
        }
        $out = [];
        $nf = false;
        $retried = [];
        foreach ($ids as $id) {
            $r = is_array($j['nodes'][$id] ?? null) ? $j['nodes'][$id] : ['st' => 'timeout'];
            $st = (string) ($r['st'] ?? '');
            if (!empty($r['rt'])) $retried[] = (string) ($nodes[$id]['n'] ?? $id);
            if ($st === 'ok' && is_array($r['result'] ?? null)) { $out[$id] = $r['result']; continue; }
            $out[$id] = false;
            if (!empty($r['nf'])) $nf = true;
            if ($st === 'fail' && $first === '' && (string) ($r['err'] ?? '') !== '') $first = (string) $r['err'];
        }
        go_mark_ok($j);
        // Семейство адресов угадано неверно (версия панели в кэше устарела) — второе пробуем один раз, как PHP.
        if ($nf && !$flipped) {
            $flipped = true;
            sess_api_flip();
            $first = '';
            continue;
        }
        return $out;
    }
}

// Связь с Go не шифруется: годится только внутри сервера — localhost или имя контейнера Docker без точки.
function go_url_local($u) {
    $h = strtolower(trim((string) parse_url((string) $u, PHP_URL_HOST), '[]'));
    return $h === '' || $h === 'localhost' || $h === '::1' || strpos($h, '127.') === 0 || (strpos($h, '.') === false && strpos($h, ':') === false);
}

function go_view() {
    $s = go_state();
    $known = go_known($s);
    $mode = go_off() ? 'off' : (!empty($s['ok']) ? 'go' : ($known ? 'warn' : 'none'));
    return [
        'mode'    => $mode,
        'url'     => go_url(),
        'url_set' => go_url_set(),
        'url_def' => go_url_default(),
        'v'       => (string) ($s['v'] ?? ''),
        'commit'  => substr((string) ($s['commit'] ?? ''), 0, 12),
        'bridge'  => (int) ($s['bridge'] ?? 0) === GO_BRIDGE,
        'ts'      => (int) ($s['ts'] ?? 0),
        'ok_ts'   => (int) ($s['ok_ts'] ?? 0),
        'since'   => (int) ($s['fail_since'] ?? 0),
        'kind'    => !empty($s['ok']) ? '' : (string) ($s['err'] ?? ''),
        'err'     => !empty($s['ok']) ? '' : go_err_text((string) ($s['err'] ?? ''), (string) ($s['detail'] ?? '')),
        'docker'  => function_exists('submw_in_docker') && submw_in_docker(),
        'remote'  => !go_url_local(go_url()),
    ];
}

// Список пользователей панели через Go. null — Go не используется или не справился (причина в $err), тогда список
// собирает PHP. $fields — какие поля пользователя нужны месту вызова; null — объект целиком.
function go_users_list(?array $fields, &$err = '', $budget = 60, $force = false) {
    $err = '';
    if (!go_wanted()) return null;
    if (empty(go_state()['ok'])) go_probe();
    if (empty(go_state()['ok'])) return null;
    if (!is_array(go_state()['caps'] ?? null)) go_hello($e);
    if (!go_can('users')) { $err = empty(go_state()['ok']) ? go_err_text((string) (go_state()['err'] ?? ''), (string) (go_state()['detail'] ?? '')) : go_err_text('nocap'); return null; }
    if (!$force && go_users_blocked()) { $err = 'сверка с Go не совпала'; return null; }
    if (remnawave_url() === '' || remnawave_token() === '') return null;
    $body = [
        'panel'     => ['url' => remnawave_url(), 'token' => remnawave_token(), 'cookie' => remnawave_cookie(), 'xkey' => remnawave_xapikey(), 'tls' => api_tls_verify()],
        'fields'    => $fields === null ? null : array_values(array_map('strval', $fields)),
        'budget_ms' => (int) (max(2, $budget) * 1000),
    ];
    $t0 = microtime(true);
    $j = go_call('POST', '/v1/users/list', $body, max(2, $budget) + 5, $kind, $detail);
    if ($j === null) {
        if ($kind === 'nocap') {
            $s = go_state();
            $s['caps'] = array_values(array_diff((array) ($s['caps'] ?? []), ['users']));
            go_state_put($s);
        } elseif (in_array($kind, ['down', 'secret', 'nosecret', 'bridge'], true)) go_mark_fail($kind, $detail);
        $err = go_err_text($kind, $detail);
        if (!$force) go_users_note(['eng' => 'fb', 'err' => $err]);
        return null;
    }
    foreach ((array) ($j['hits'] ?? []) as $h) {
        if (is_array($h) && function_exists('apistat_hit')) apistat_hit((string) ($h['m'] ?? 'GET'), (string) ($h['p'] ?? ''), (int) ($h['c'] ?? 0), (float) ($h['ms'] ?? 0), 'go:users');
    }
    if ((string) ($j['panel_down'] ?? '') !== '') {
        go_mark_ok($j, ['panel', (string) $j['panel_down']]);
        $err = go_err_text('panel', (string) $j['panel_down']);
        if (!$force) go_users_note(['eng' => 'fb', 'err' => $err]);
        return null;
    }
    $users = $j['users'] ?? null;
    if (!is_array($users)) {
        $err = go_err_text('bad', 'нет списка');
        if (!$force) go_users_note(['eng' => 'fb', 'err' => $err]);
        return null;
    }
    if (!$force) go_users_note(['eng' => 'go', 'ms' => (int) round((microtime(true) - $t0) * 1000), 'pages' => (int) ($j['pages'] ?? 0), 'n' => count($users), 'rt' => !empty($j['rt']) ? 1 : 0]);
    return $users;
}

function go_users_ready() {
    $s = go_state();
    return go_wanted() && !empty($s['ok']) && (!is_array($s['caps'] ?? null) || go_can('users')) && !go_users_blocked();
}

function go_users_state() {
    $s = json_decode((string) setting('go_users', ''), true);
    return is_array($s) ? $s : [];
}

function go_users_note(array $s) {
    set_setting('go_users', json_encode(['ts' => time()] + $s, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
}

function go_users_blocked() {
    $b = json_decode((string) setting('go_users_block', ''), true);
    return is_array($b) && (int) ($b['ts'] ?? 0) > 0 ? $b : null;
}

// Сверка: тот же список адресов подписок от Go и от PHP. Пользователя могли создать между обходами — тогда
// Go спрашивается ещё раз, и совпадение с любым из двух его ответов засчитывается.
// true — совпало, false — не совпало (Go отключается для списка), null — сверить не удалось.
function go_users_compare(&$detail = '') {
    $detail = '';
    $sig = static function (array $su) { sort($su, SORT_STRING); return count($su) . ':' . sha1(implode("\n", $su)); };
    $go = static function () {
        $l = go_users_list(['shortUuid'], $e, 90, true);
        if ($l === null) return [null, $e];
        $su = [];
        foreach ($l as $u) $su[] = is_array($u) ? (string) ($u['shortUuid'] ?? '') : '';
        return [$su, ''];
    };
    [$a, $e1] = $go();
    if ($a === null) { $detail = 'Go: ' . $e1; return null; }
    $p = [];
    $e2 = '';
    remnawave_all_users_php($e2, static function (array $page) use (&$p) {
        foreach ($page as $u) $p[] = is_array($u) ? (string) ($u['shortUuid'] ?? '') : '';
    });
    if ($e2 !== '') { $detail = 'PHP: ' . $e2; return null; }
    $ok = $sig($a) === $sig($p);
    if (!$ok) {
        [$b] = $go();
        $ok = $b !== null && $sig($b) === $sig($p);
    }
    $now = time();
    set_setting('go_users_check', json_encode(['ts' => $now, 'ok' => $ok ? 1 : 0, 'go' => count($a), 'php' => count($p)]));
    if ($ok) { set_setting('go_users_block', ''); return true; }
    $detail = 'у Go ' . count($a) . ', у PHP ' . count($p) . ' пользователей';
    set_setting('go_users_block', json_encode(['ts' => $now, 'detail' => $detail], JSON_UNESCAPED_UNICODE));
    return false;
}

function go_users_view() {
    $s = go_users_state();
    $c = json_decode((string) setting('go_users_check', ''), true);
    $b = go_users_blocked();
    return [
        'eng'   => $b ? 'block' : (string) ($s['eng'] ?? ''),
        'ts'    => (int) ($s['ts'] ?? 0),
        'ms'    => (int) ($s['ms'] ?? 0),
        'pages' => (int) ($s['pages'] ?? 0),
        'n'     => (int) ($s['n'] ?? 0),
        'err'   => (string) ($s['err'] ?? ''),
        'rt'    => !empty($s['rt']),
        'cap'   => is_array(go_state()['caps'] ?? null) ? go_can('users') : null,
        'check' => is_array($c) ? ['ts' => (int) ($c['ts'] ?? 0), 'ok' => !empty($c['ok'])] : null,
        'block' => $b ? ['ts' => (int) $b['ts'], 'detail' => (string) ($b['detail'] ?? '')] : null,
    ];
}
