<?php
// Проверки мастера установки. Подключается только пока нет config.php: ничего не читает из settings/БД,
// все параметры приходят явно, чтобы не зависеть от рантайма прослойки.

function inst_panel_min() { return function_exists('panel_min_supported') ? panel_min_supported() : '2.7.4'; }

function inst_http($method, $url, array $headers = [], array $o = []) {
    $tls = !isset($o['tls']) || $o['tls'];
    $hdr = [];
    $ch = curl_init();
    $opt = [
        CURLOPT_URL            => $url,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_NOBODY         => $method === 'HEAD',
        CURLOPT_TIMEOUT        => $o['timeout'] ?? 15,
        CURLOPT_CONNECTTIMEOUT => $o['connect'] ?? 8,
        CURLOPT_SSL_VERIFYPEER => $tls,
        CURLOPT_SSL_VERIFYHOST => $tls ? 2 : 0,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_ENCODING       => '',
        CURLOPT_NOSIGNAL       => 1,
        // HTTP/1.1: обрыв соединения cookie-защитой тогда виден как «пустой ответ», а не ошибка потока h2.
        CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_HEADERFUNCTION => function ($c, $h) use (&$hdr) {
            $p = explode(':', $h, 2);
            if (count($p) === 2) $hdr[strtolower(trim($p[0]))] = trim($p[1]);
            return strlen($h);
        },
    ];
    if (isset($o['body'])) $opt[CURLOPT_POSTFIELDS] = $o['body'];
    curl_setopt_array($ch, $opt);
    $body  = curl_exec($ch);
    $errno = curl_errno($ch);
    $r = [
        'ok'    => $errno === 0,
        'code'  => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE),
        'body'  => is_string($body) ? $body : '',
        'hdr'   => $hdr,
        'errno' => $errno,
        'err'   => curl_error($ch),
        'ms'    => (int) round(curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000),
        'ct'    => strtolower((string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE)),
        'ip'    => (string) curl_getinfo($ch, CURLINFO_PRIMARY_IP),
    ];
    curl_close($ch);
    return $r;
}

function inst_is_html($r) {
    if (strpos($r['ct'], 'text/html') !== false) return true;
    return preg_match('~^\s*<(!doctype|html)~i', substr($r['body'], 0, 200)) === 1;
}

function inst_json($r) {
    $j = json_decode($r['body'], true);
    return is_array($j) ? $j : null;
}

function inst_host_is_local($host) {
    $h = strtolower((string) $host);
    if ($h === 'localhost' || substr($h, -10) === '.localhost') return true;
    if (filter_var($h, FILTER_VALIDATE_IP)) return true;
    if (preg_match('~^\[?[0-9a-f:]+\]?$~i', $h) && strpos($h, ':') !== false) return true;
    return false;
}

// Имя без точки или приватный IP — адрес внутри docker-сети / того же хоста.
function inst_host_is_internal($host) {
    $h = strtolower((string) $host);
    if ($h === '' ) return false;
    if (inst_host_is_local($h)) {
        if (filter_var($h, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return false;
        return true;
    }
    return strpos($h, '.') === false;
}

// Любая форма ссылки на панель → схема://хост[:порт]; одиночный query-параметр из ссылки
// установщика панели предлагается как cookie-защита.
function inst_url_parse($raw) {
    $raw = trim((string) $raw);
    $out = ['ok' => false, 'url' => '', 'host' => '', 'scheme' => '', 'port' => 0, 'cookie_hint' => '', 'path' => '', 'internal' => false, 'err' => '', 'note' => ''];
    if ($raw === '') { $out['err'] = 'Введите адрес панели.'; return $out; }
    if (!preg_match('~^[a-z][a-z0-9+.-]*://~i', $raw)) {
        $h0 = preg_replace('~[/?#].*$~', '', $raw);
        $h0 = preg_replace('~:\d+$~', '', $h0);
        $raw = (inst_host_is_internal($h0) ? 'http://' : 'https://') . $raw;
    }
    $p = parse_url($raw);
    if (!is_array($p) || empty($p['host'])) { $out['err'] = 'Не удалось разобрать адрес. Пример: https://panel.example.com или http://remnawave:3000'; return $out; }
    $scheme = strtolower($p['scheme'] ?? 'https');
    if ($scheme !== 'http' && $scheme !== 'https') { $out['err'] = 'Схема должна быть http:// или https://'; return $out; }
    $host = strtolower($p['host']);
    $port = (int) ($p['port'] ?? 0);
    $out['scheme'] = $scheme;
    $out['host']   = $host;
    $out['port']   = $port;
    $out['url']    = $scheme . '://' . $host . ($port > 0 ? ':' . $port : '');
    $out['path']   = (string) ($p['path'] ?? '');
    $out['internal'] = inst_host_is_internal($host);
    if (!empty($p['query'])) {
        parse_str($p['query'], $q);
        if (count($q) === 1) {
            $k = (string) array_key_first($q);
            $v = $q[$k];
            if (is_string($v) && $v !== '' && preg_match('~^[A-Za-z0-9_-]{2,}$~', $k)) $out['cookie_hint'] = $k . '=' . $v;
        }
    }
    if (preg_match('~/api/sub(/|$)~i', $out['path'])) $out['note'] = 'Это ссылка подписки через панель — для мастера нужен адрес самой панели; путь убран.';
    elseif ($out['path'] !== '' && $out['path'] !== '/') $out['note'] = 'Путь из ссылки убран — нужен только адрес панели.';
    $out['ok'] = true;
    return $out;
}

function inst_panel_headers(array $c, array $extra = []) {
    $h = ['Accept: application/json'];
    if (($c['token'] ?? '') !== '') $h[] = 'Authorization: Bearer ' . $c['token'];
    if (strpos((string) ($c['url'] ?? ''), 'http://') === 0) {
        $h[] = 'x-forwarded-proto: https';
        $h[] = 'x-forwarded-for: 127.0.0.1';
    }
    if (($c['cookie'] ?? '') !== '') $h[] = 'Cookie: ' . $c['cookie'];
    if (($c['xkey'] ?? '') !== '') $h[] = 'X-Api-Key: ' . $c['xkey'];
    return array_merge($h, $extra);
}

function inst_token_shape($t) {
    $t = trim((string) $t);
    if ($t === '') return '';
    if (preg_match('~^eyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$~', $t)) return '';
    if (strpos($t, '=') !== false && strpos($t, '.') === false) return 'Похоже на cookie («имя=значение»), а не на API-токен. Cookie — отдельное поле ниже.';
    if (strlen($t) < 40) return 'Слишком короткая строка для API-токена панели — возможно, это X-Api-Key или пароль. Токен создаётся в панели: Settings → API Tokens, начинается с «eyJ».';
    return 'Не похоже на API-токен панели: он выглядит как JWT (три части через точку, начинается с «eyJ»).';
}

function inst_tls_errno($n) { return in_array((int) $n, [35, 51, 53, 54, 58, 59, 60, 77, 80, 82, 83, 90, 91], true); }

// Один запрос к /api/system/metadata и диагноз по тому, что вернулось.
function inst_panel_probe(array $c, $scenario = 'standalone') {
    $u = inst_url_parse($c['url'] ?? '');
    $out = ['ok' => false, 'st' => 'err', 'code' => 0, 'version' => '', 'msg' => '', 'hint' => '', 'fix' => [], 'ms' => 0, 'url' => $u['url']];
    if (!$u['ok']) { $out['st'] = 'url'; $out['msg'] = $u['err']; return $out; }
    $c['url'] = $u['url'];
    $r = inst_http('GET', $u['url'] . '/api/system/metadata', inst_panel_headers($c), ['tls' => !empty($c['tls']), 'timeout' => 12]);
    $out['code'] = $r['code'];
    $out['ms']   = $r['ms'];
    $cookie_set = ($c['cookie'] ?? '') !== '';
    $xkey_set   = ($c['xkey'] ?? '') !== '';

    if (!$r['ok']) {
        $n = $r['errno'];
        if ($n === 6) {
            if ($u['internal'] && $scenario === 'panel') {
                $out['st'] = 'dns_net'; $out['msg'] = 'Имя «' . $u['host'] . '» не резолвится из контейнера прослойки.';
                $out['hint'] = 'Контейнер не в docker-сети панели или имя контейнера панели другое. Проверьте `networks:` в docker-compose.yml (обычно `remnawave-network`) и `docker ps` — как называется контейнер панели.';
            } elseif ($u['internal']) {
                $out['st'] = 'dns_internal'; $out['msg'] = '«' . $u['host'] . '» — адрес внутри docker-сети панели, с отдельного сервера он недоступен.';
                $out['hint'] = 'Укажите публичный адрес панели со схемой https://, например https://panel.example.com. Если панель закрыта cookie-защитой или X-Api-Key — заполните поля ниже.';
            } else {
                $out['st'] = 'dns'; $out['msg'] = 'Домен «' . $u['host'] . '» не резолвится.';
                $out['hint'] = 'Проверьте опечатку в домене и DNS-запись.';
            }
        } elseif ($n === 7) {
            if ($u['internal']) {
                $out['st'] = 'refused_internal'; $out['msg'] = '«' . $u['host'] . '» отвечает, но порт закрыт (connection refused).';
                $out['hint'] = 'Контейнер панели не запущен или слушает другой порт (у панели по умолчанию 3000). Проверьте `docker ps` и APP_PORT в .env панели.';
            } else {
                $out['st'] = 'refused'; $out['msg'] = 'Сервер отказал в соединении.';
                $out['hint'] = 'Реверс-прокси панели не слушает этот порт или схему. Проверьте https:// и что панель открывается в браузере.';
            }
        } elseif ($n === 28) {
            $out['st'] = 'timeout'; $out['msg'] = 'Панель не ответила за 12 секунд.';
            $out['hint'] = $u['internal'] ? 'Панель запускается или зависла — смотрите `docker logs remnawave`.' : 'Файрвол, Cloudflare-петля (с того же сервера к своему публичному домену) или панель лежит. Если панель на этом же сервере — используйте внутренний адрес http://remnawave:3000 или http://127.0.0.1:3000.';
        } elseif (inst_tls_errno($n)) {
            $out['st'] = 'tls'; $out['msg'] = 'Сертификат панели не прошёл проверку: ' . $r['err'];
            $out['hint'] = 'Самоподписанный или внутренний сертификат. Можно продолжить без проверки TLS (переключатель ниже) — так прослойка будет доверять любому сертификату панели.';
            $out['fix']['tls'] = 0;
        } elseif ($n === 52 || $n === 56 || $n === 55 || $n === 92) {
            if (!$cookie_set) {
                $out['st'] = 'dropped'; $out['msg'] = 'Соединение открылось, но сервер закрыл его без ответа.';
                $out['hint'] = 'Так ведёт себя cookie-защита панели (установщик панели eGames / remnawave-reverse-proxy): без секретной куки запросы обрываются. Вставьте ссылку входа в панель вида https://panel.example.com/auth/login?имя=значение — кука подставится сама, либо заполните поле «Cookie панели». Если же это домен подписки, а не панели — нужен адрес панели, где вы входите как администратор.';
            } else {
                $out['st'] = 'dropped_cookie'; $out['msg'] = 'Панель закрыла соединение, хотя cookie указана.';
                $out['hint'] = 'Кука не подходит: проверьте «имя=значение» без пробелов и кавычек — в браузере F12 → Application → Cookies → домен панели.';
            }
        } else {
            $out['st'] = 'err'; $out['msg'] = 'Ошибка соединения: ' . $r['err'];
        }
        return $out;
    }

    $j = inst_json($r);
    $code = $r['code'];
    if ($code >= 300 && $code < 400) {
        $loc = $r['hdr']['location'] ?? '';
        if ($u['scheme'] === 'http' && stripos($loc, 'https://') === 0) {
            $out['st'] = 'use_https'; $out['msg'] = 'Панель перенаправляет на https.';
            $out['hint'] = 'Укажите адрес с https://';
            $out['fix']['url'] = preg_replace('~^http://~', 'https://', $u['url']);
        } else {
            $out['st'] = 'redirect'; $out['msg'] = 'Вместо ответа API пришёл редирект' . ($loc !== '' ? ' на ' . $loc : '') . '.';
            $out['hint'] = 'Это не адрес API панели: перед панелью стоит что-то, что уводит на страницу входа. Проверьте домен и cookie-защиту.';
        }
        return $out;
    }
    if (isset($r['hdr']['cf-mitigated']) || ($code === 403 && stripos($r['body'], 'Just a moment') !== false)) {
        $out['st'] = 'cf'; $out['msg'] = 'Cloudflare показывает проверку браузера (challenge) вместо ответа панели.';
        $out['hint'] = 'Выпустите запросы прослойки из-под проверки: в Cloudflare → Security → WAF создайте правило Skip для домена панели по IP этого сервера (или по пути /api/*), либо выключите Bot Fight Mode. Другой путь — ходить к панели минуя Cloudflare, по внутреннему адресу.';
        return $out;
    }
    if ($code === 200 && $j && isset($j['response']['version'])) {
        $v = trim((string) $j['response']['version']);
        $out['version'] = $v;
        if ($v !== '' && version_compare(ltrim($v, 'vV'), inst_panel_min(), '<')) {
            $out['st'] = 'old'; $out['msg'] = 'Панель ' . $v . ' — слишком старая, прослойке нужна ' . inst_panel_min() . ' и новее.';
            $out['hint'] = 'Обновите панель Remnawave.';
            return $out;
        }
        $out['ok'] = true; $out['st'] = 'ok'; $out['msg'] = 'Панель отвечает' . ($v !== '' ? ', версия ' . $v : '') . '.';
        return $out;
    }
    if (inst_is_html($r)) {
        $b = substr($r['body'], 0, 4000);
        if (preg_match('~subscription|remnawave-subscription|<title>\s*Subscription~i', $b) || isset($r['hdr']['profile-title'])) {
            $out['st'] = 'html_subpage'; $out['msg'] = 'По этому адресу отвечает страница подписки, а не панель.';
            $out['hint'] = 'Нужен адрес самой панели (где вы входите как администратор), а не домен подписки.';
        } elseif ($code === 404 || $code === 403 || $code === 401) {
            $out['st'] = $cookie_set ? 'cookie_bad' : 'cookie'; $out['msg'] = 'Вместо API пришла HTML-страница (HTTP ' . $code . ').';
            $out['hint'] = $cookie_set ? 'Кука не принята — проверьте её значение.' : 'Похоже на cookie-защиту панели: без куки сервер отдаёт заглушку. Вставьте ссылку входа вида https://panel.example.com/auth/login?имя=значение или заполните «Cookie панели».';
        } else {
            $out['st'] = 'html'; $out['msg'] = 'По этому адресу HTML-страница (HTTP ' . $code . '), а не API панели.';
            $out['hint'] = 'Проверьте, что указан домен панели Remnawave. Если панель за прокси с авторизацией — заполните cookie или X-Api-Key.';
        }
        return $out;
    }
    if ($code === 401) {
        if (($c['token'] ?? '') === '') { $out['st'] = 'auth_empty'; $out['msg'] = 'Панель найдена, нужен API-токен.'; $out['hint'] = 'Создайте токен в панели: Settings → API Tokens.'; }
        else { $out['st'] = 'auth'; $out['msg'] = 'Панель найдена, но токен не принят (HTTP 401).'; $out['hint'] = 'Проверьте токен: он копируется целиком, без пробелов. Токен из другой панели или отозванный тоже даёт 401.'; }
        return $out;
    }
    if ($code === 403) {
        $m = (string) ($j['message'] ?? '');
        if (stripos($m, 'API-token') !== false || stripos($m, 'api token') !== false) {
            $out['st'] = 'auth_jwt'; $out['msg'] = 'Это токен сессии администратора, а не API-токен.';
            $out['hint'] = 'Создайте API-токен в панели: Settings → API Tokens — и вставьте его.';
        } elseif ($j !== null) {
            $out['st'] = 'scope'; $out['msg'] = 'Токен принят, но у него нет права system:metadata.';
            $out['hint'] = 'Выдайте токену нужные права (проще — все) в Settings → API Tokens.';
        } elseif ($xkey_set) {
            $out['st'] = 'xkey_bad'; $out['msg'] = 'Прокси перед панелью отклоняет запросы (HTTP 403) несмотря на X-Api-Key.';
            $out['hint'] = 'Проверьте ключ: Caddy → https://панель/<REMNAWAVE_CUSTOM_LOGIN_ROUTE>/auth → API-keys.';
        } else {
            $out['st'] = 'xkey'; $out['msg'] = 'Прокси перед панелью отклоняет запросы к /api (HTTP 403).';
            $out['hint'] = 'Так работает схема «Caddy with custom path» (caddy-with-auth): нужен ключ X-Api-Key из портала авторизации Caddy. Заполните поле «X-Api-Key».';
        }
        return $out;
    }
    if ($code === 404) {
        $m = (string) ($j['message'] ?? '');
        if (preg_match('~^Cannot (GET|POST)~', $m)) { $out['st'] = 'not_panel'; $out['msg'] = 'Сервер отвечает, но это не панель Remnawave (нет /api/system/metadata).'; $out['hint'] = 'Проверьте адрес — возможно, указан домен ноды или другого сервиса.'; }
        else { $out['st'] = $cookie_set ? 'cookie_bad' : 'cookie'; $out['msg'] = 'HTTP 404 без ответа API.'; $out['hint'] = $cookie_set ? 'Кука не принята — проверьте значение.' : 'Старые установки eGames без куки отдают 404. Заполните «Cookie панели» или вставьте ссылку входа с параметром.'; }
        return $out;
    }
    if ($code >= 500) {
        $out['st'] = 'upstream'; $out['msg'] = 'Реверс-прокси отвечает ошибкой ' . $code . ' вместо API панели.';
        $out['hint'] = 'Либо панель за ним не работает (`docker ps`, `docker logs remnawave` на сервере панели), либо это домен подписки, а не панели: subscription-page на запросы API отвечает именно так. Нужен адрес панели, где вы входите как администратор.';
        return $out;
    }
    $out['st'] = 'odd'; $out['msg'] = 'Неожиданный ответ HTTP ' . $code . '.';
    $out['hint'] = mb_substr(trim(strip_tags($r['body'])), 0, 160);
    return $out;
}

// Права токена: только те, без которых прослойка не работает, плюс нужные для subpage.
function inst_scopes_catalog(array $need = []) {
    $rnd = 'submw-probe-' . bin2hex(random_bytes(4));
    $sub = !empty($need['subpage']);
    return [
        ['s' => 'system:metadata',              'm' => 'GET', 'p' => '/api/system/metadata',                       'w' => 'Версия панели',                                  'need' => true],
        ['s' => 'users:list',                   'm' => 'GET', 'p' => '/api/users?size=1&start=0',                  'w' => 'Список пользователей',                           'need' => true],
        ['s' => 'users:by-short-uuid',          'm' => 'GET', 'p' => '/api/users/by-short-uuid/' . $rnd,           'w' => 'Пользователь по ссылке подписки',               'need' => true],
        ['s' => 'internal-squads:list',         'm' => 'GET', 'p' => '/api/internal-squads',                       'w' => 'Сквады',                                         'need' => true],
        ['s' => 'system:stats',                 'm' => 'GET', 'p' => '/api/system/stats',                          'w' => 'Статистика на «О системе»',                     'need' => false],
        ['s' => 'system:configuration',         'm' => 'GET', 'p' => '/api/system/configuration',                  'w' => 'Настройки панели (3.2.0+)',                     'need' => false],
        ['s' => 'nodes:list',                   'm' => 'GET', 'p' => '/api/nodes',                                 'w' => 'Ноды',                                           'need' => false],
        ['s' => 'hwid-user-devices:list-by-user','m' => 'GET', 'p' => '/api/hwid/devices/submw-probe',             'w' => 'Устройства пользователя',                       'need' => false],
        ['s' => 'users:update',                 'm' => 'PATCH','p' => '/api/users', 'b' => '[]',                    'w' => 'Грейс-сквад, правка пользователей (запись)',    'need' => false],
        ['s' => 'external-squads:list',         'm' => 'GET', 'p' => '/api/external-squads',                       'w' => 'Внешний сквад для грейса',                      'need' => false],
        ['s' => 'subscriptions:subpage-config', 'm' => 'GET', 'p' => '/api/subscriptions/subpage-config/' . $rnd, 'w' => 'Страница подписки (subscription-page)',         'need' => $sub],
        ['s' => 'subscription-page-configs:list','m' => 'GET', 'p' => '/api/subscription-page-configs/',           'w' => 'Страница подписки: список конфигов',            'need' => $sub],
        ['s' => 'subscription-page-configs:get','m' => 'GET', 'p' => '/api/subscription-page-configs/' . $rnd,    'w' => 'Страница подписки: конфиг',                     'need' => $sub],
    ];
}

function inst_scope_class($code, $body) {
    if (function_exists('tokscope_classify')) return tokscope_classify($code, $body);
    $code = (int) $code;
    if ($code === 0) return 'err';
    if ($code === 401) return 'auth';
    if ($code === 403) return 'miss';
    if ($code === 404) {
        $j = json_decode((string) $body, true);
        if (!is_array($j)) return 'na';
        return preg_match('~^Cannot (GET|POST|PATCH|PUT|DELETE) ~', (string) ($j['message'] ?? '')) ? 'na' : 'ok';
    }
    if ($code === 429 || $code >= 500) return 'err';
    return 'ok';
}

function inst_scopes_probe(array $c, array $need = []) {
    $u = inst_url_parse($c['url'] ?? '');
    if (!$u['ok'] || ($c['token'] ?? '') === '') return ['ok' => false, 'rows' => [], 'missing' => [], 'msg' => 'Нет адреса панели или токена'];
    $c['url'] = $u['url'];
    $tls = !empty($c['tls']);
    $cat = inst_scopes_catalog($need);
    $mh = curl_multi_init();
    $hs = [];
    foreach ($cat as $i => $r) {
        $h = inst_panel_headers($c);
        $ch = curl_init();
        $opt = [
            CURLOPT_URL => $u['url'] . $r['p'], CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => $tls, CURLOPT_SSL_VERIFYHOST => $tls ? 2 : 0, CURLOPT_CUSTOMREQUEST => $r['m'], CURLOPT_NOSIGNAL => 1,
        ];
        if (isset($r['b'])) { $h[] = 'Content-Type: application/json'; $opt[CURLOPT_POSTFIELDS] = $r['b']; }
        $opt[CURLOPT_HTTPHEADER] = $h;
        curl_setopt_array($ch, $opt);
        curl_multi_add_handle($mh, $ch);
        $hs[$i] = $ch;
    }
    do {
        $mrc = curl_multi_exec($mh, $active);
        if ($active && curl_multi_select($mh, 1.0) === -1) usleep(10000);
    } while ($active && $mrc === CURLM_OK);
    $rows = []; $missing = []; $errs = 0; $auth = 0;
    foreach ($cat as $i => $r) {
        $ch = $hs[$i];
        $body = curl_multi_getcontent($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
        $st = $err !== '' ? 'err' : inst_scope_class($code, $body);
        if ($st === 'err') $errs++;
        if ($st === 'auth') $auth++;
        if ($st === 'miss' && $r['need']) $missing[] = $r['s'];
        $rows[] = ['s' => $r['s'], 'w' => $r['w'], 'need' => (bool) $r['need'], 'st' => $st, 'code' => $code];
    }
    curl_multi_close($mh);
    $n = count($rows);
    $msg = '';
    if ($n && $auth === $n) $msg = 'Панель не принимает токен (HTTP 401).';
    elseif ($n && $errs === $n) $msg = 'Панель не ответила.';
    elseif ($missing) $msg = 'Токену не хватает прав: ' . implode(', ', $missing) . '. Выдайте их в Settings → API Tokens (проще — все права) и проверьте снова.';
    return ['ok' => $msg === '', 'rows' => $rows, 'missing' => $missing, 'msg' => $msg];
}

function inst_first_user(array $c) {
    $u = inst_url_parse($c['url'] ?? '');
    if (!$u['ok']) return null;
    $c['url'] = $u['url'];
    $r = inst_http('GET', $u['url'] . '/api/users?size=1&start=0', inst_panel_headers($c), ['tls' => !empty($c['tls'])]);
    $j = $r['ok'] && $r['code'] === 200 ? inst_json($r) : null;
    $list = $j['response']['users'] ?? ($j['response'] ?? null);
    if (is_array($list) && isset($list[0]) && is_array($list[0])) {
        return ['short' => (string) ($list[0]['shortUuid'] ?? ''), 'username' => (string) ($list[0]['username'] ?? '')];
    }
    return ['short' => '', 'username' => ''];
}

function inst_panel_config(array $c, $version) {
    $out = ['have' => false, 'sub_public_domain' => '', 'webhook' => null];
    if ($version === '' || version_compare(ltrim($version, 'vV'), '3.2.0', '<')) return $out;
    $u = inst_url_parse($c['url'] ?? '');
    if (!$u['ok']) return $out;
    $c['url'] = $u['url'];
    $r = inst_http('GET', $u['url'] . '/api/system/configuration', inst_panel_headers($c), ['tls' => !empty($c['tls'])]);
    $j = $r['ok'] && $r['code'] === 200 ? inst_json($r) : null;
    if (!$j || !isset($j['response'])) return $out;
    $resp = $j['response'];
    $out['have'] = true;
    $out['sub_public_domain'] = trim((string) ($resp['misc']['subPublicDomain'] ?? ''));
    $w = $resp['notifications']['webhook'] ?? null;
    if (is_bool($w)) $out['webhook'] = $w;
    elseif (is_array($w)) $out['webhook'] = !empty($w['enabled']) || !empty($w['isEnabled']);
    return $out;
}

// Ссылка подписки или домен → домен, префикс, shortUuid.
function inst_origin_parse($raw) {
    $raw = trim((string) $raw);
    $out = ['ok' => false, 'domain' => '', 'prefix' => '', 'short' => '', 'is_panel_link' => false, 'err' => ''];
    if ($raw === '') { $out['err'] = 'Укажите домен подписки панели или ссылку подписки.'; return $out; }
    if (!preg_match('~^[a-z][a-z0-9+.-]*://~i', $raw)) $raw = 'https://' . $raw;
    $p = parse_url($raw);
    if (!is_array($p) || empty($p['host'])) { $out['err'] = 'Не удалось разобрать домен.'; return $out; }
    $out['domain'] = strtolower($p['host']) . (!empty($p['port']) ? ':' . (int) $p['port'] : '');
    $segs = array_values(array_filter(explode('/', (string) ($p['path'] ?? '')), fn($s) => $s !== ''));
    if ($segs && strcasecmp($segs[0], 'api') === 0 && isset($segs[1]) && strcasecmp($segs[1], 'sub') === 0) {
        $out['is_panel_link'] = true;
        $segs = array_slice($segs, 2);
    }
    if ($segs) {
        $last = end($segs);
        if (preg_match('~^[A-Za-z0-9_-]{6,64}$~', $last)) {
            $out['short']  = $last;
            $out['prefix'] = implode('/', array_slice($segs, 0, -1));
        } else {
            $out['prefix'] = implode('/', $segs);
        }
    }
    $out['ok'] = true;
    return $out;
}

function inst_origin_probe(array $o, $tls, $own_host, $panel_host = '') {
    $out = ['ok' => false, 'st' => 'err', 'msg' => '', 'hint' => '', 'browser' => null, 'code' => 0];
    $dom = strtolower($o['domain']);
    if ($dom === strtolower((string) $own_host)) { $out['st'] = 'loop'; $out['msg'] = 'Origin совпадает с доменом прослойки — зеркало будет ходить само к себе.'; $out['hint'] = 'В режиме зеркала у origin другой домен (домен подписки панели). Если прослойка должна встать на сам домен подписки — выберите режим «тот же домен».'; return $out; }
    if ($panel_host !== '' && $dom === strtolower($panel_host)) { $out['st'] = 'panel'; $out['msg'] = 'Это домен панели, а не домен подписки.'; $out['hint'] = 'Зеркалу нужен домен, где панель отдаёт подписки (SUB_PUBLIC_DOMAIN). Если прослойка должна отдавать подписку напрямую с панели — выберите режим «тот же домен» / «подписка с панели».'; return $out; }
    if (!empty($o['is_panel_link'])) { $out['st'] = 'panel'; $out['msg'] = 'Ссылка вида /api/sub/… ведёт на панель, а не на домен подписки.'; $out['hint'] = 'Для зеркала нужна ссылка подписки с домена подписки. Или выберите режим «подписка с панели».'; return $out; }
    $base = 'https://' . $dom;
    $path = ($o['prefix'] !== '' ? '/' . $o['prefix'] : '') . ($o['short'] !== '' ? '/' . $o['short'] : '/');
    $r = inst_http('GET', $base . $path, ['User-Agent: Happ/2.0', 'Accept: */*'], ['tls' => $tls, 'timeout' => 15]);
    $out['code'] = $r['code'];
    if (!$r['ok']) {
        if ($r['errno'] === 6) { $out['st'] = 'dns'; $out['msg'] = 'Домен ' . $dom . ' не резолвится.'; }
        elseif (inst_tls_errno($r['errno'])) { $out['st'] = 'tls'; $out['msg'] = 'Сертификат origin не прошёл проверку: ' . $r['err']; $out['hint'] = 'Можно отключить проверку TLS (ниже), но для публичного домена подписки это ненормально.'; }
        else { $out['st'] = 'err'; $out['msg'] = 'Origin не отвечает: ' . $r['err']; }
        return $out;
    }
    if ($o['short'] !== '') {
        if ($r['code'] === 200 && (isset($r['hdr']['subscription-userinfo']) || isset($r['hdr']['profile-title']))) {
            $out['ok'] = true; $out['st'] = 'ok'; $out['msg'] = 'Origin отдаёт подписку Remnawave' . ($o['prefix'] !== '' ? ' с префиксом /' . $o['prefix'] : '') . '.';
        } elseif ($r['code'] === 404 || $r['code'] >= 500) {
            $out['st'] = 'nf'; $out['msg'] = 'Origin отвечает, но подписку по этой ссылке не отдал (HTTP ' . $r['code'] . ').'; $out['hint'] = 'Ссылка устарела (ключи перевыпущены), префикс не совпадает с CUSTOM_SUB_PREFIX у subscription-page, или это не домен подписки. Возьмите свежую ссылку из карточки пользователя в панели.';
        } elseif (inst_is_html($r)) {
            $out['st'] = 'html'; $out['msg'] = 'Origin отдаёт HTML-страницу вместо подписки на клиентский запрос.'; $out['hint'] = 'Так бывает, если домен ведёт не на subscription-page, а на сайт или заглушку. Проверьте ссылку в клиенте.';
        } else {
            $out['st'] = 'odd'; $out['msg'] = 'Origin ответил HTTP ' . $r['code'] . ' без заголовков подписки.'; $out['hint'] = 'Возможно, это не Remnawave или подписка закрыта.';
        }
    } else {
        $out['ok'] = true; $out['st'] = 'alive'; $out['msg'] = 'Домен отвечает (HTTP ' . $r['code'] . '). Чтобы проверить саму подписку, вставьте полную ссылку подписки любого пользователя.';
    }
    if ($out['ok'] && $o['short'] !== '') {
        $b = inst_http('GET', $base . $path, ['User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/124.0 Safari/537.36', 'Accept: text/html,application/xhtml+xml'], ['tls' => $tls, 'timeout' => 15]);
        $out['browser'] = $b['ok'] && $b['code'] === 200 && inst_is_html($b);
    }
    return $out;
}

// Контейнер subscription-page: без X-Forwarded-* он рвёт соединение, без известного shortUuid — тоже.
function inst_subpage_probe($url, $short = '') {
    $u = inst_url_parse($url);
    $out = ['ok' => false, 'st' => 'err', 'msg' => '', 'hint' => '', 'url' => $u['url']];
    if (!$u['ok']) { $out['st'] = 'url'; $out['msg'] = $u['err']; return $out; }
    $h = ['X-Forwarded-For: 127.0.0.1', 'X-Forwarded-Proto: https', 'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/124.0 Safari/537.36', 'Accept: text/html,application/xhtml+xml'];
    $r = inst_http('GET', $u['url'] . '/' . ($short !== '' ? rawurlencode($short) : ''), $h, ['tls' => false, 'timeout' => 20]);
    if (!$r['ok']) {
        if ($r['errno'] === 6) { $out['st'] = 'dns'; $out['msg'] = 'Имя «' . $u['host'] . '» не резолвится.'; $out['hint'] = 'Сервис subscription-page не в этой docker-сети или называется иначе — проверьте docker-compose.yml.'; }
        elseif ($r['errno'] === 7) { $out['st'] = 'down'; $out['msg'] = 'Контейнер subscription-page не отвечает (порт закрыт).'; $out['hint'] = 'Он перезапускается по кругу, если не может достучаться до панели: смотрите `docker compose logs remnawave-subscription-page` — обычно не заданы REMNAWAVE_PANEL_URL / REMNAWAVE_API_TOKEN или нужна EGAMES_COOKIE.'; }
        elseif ($r['errno'] === 52 || $r['errno'] === 56) {
            if ($short === '') { $out['ok'] = true; $out['st'] = 'alive'; $out['msg'] = 'Контейнер subscription-page запущен; страницу проверю, когда в панели появится хотя бы один пользователь.'; }
            else { $out['st'] = 'dropped'; $out['msg'] = 'subscription-page закрыл соединение без ответа.'; $out['hint'] = 'Он не нашёл пользователя в панели или панель ему недоступна — смотрите его логи.'; }
        } else { $out['msg'] = 'Ошибка: ' . $r['err']; }
        return $out;
    }
    if ($r['code'] === 200 && inst_is_html($r)) { $out['ok'] = true; $out['st'] = 'ok'; $out['msg'] = 'subscription-page отдаёт страницу подписки.'; }
    elseif ($r['code'] === 200) { $out['ok'] = true; $out['st'] = 'ok'; $out['msg'] = 'subscription-page отвечает.'; }
    else { $out['st'] = 'odd'; $out['msg'] = 'subscription-page ответил HTTP ' . $r['code'] . '.'; }
    return $out;
}

// Сквозной тест: подписка через публичный домен прослойки, как её запрашивает клиент.
function inst_e2e($base, $short, $prefix, $tls) {
    $out = ['ok' => false, 'st' => 'err', 'msg' => '', 'hint' => '', 'code' => 0, 'url' => ''];
    if ($short === '') { $out['st'] = 'nouser'; $out['msg'] = 'В панели пока нет пользователей — проверить подписку не на ком.'; return $out; }
    $url = rtrim($base, '/') . ($prefix !== '' ? '/' . trim($prefix, '/') : '') . '/' . rawurlencode($short);
    $out['url'] = $url;
    $r = inst_http('GET', $url, ['User-Agent: Happ/2.0', 'Accept: */*'], ['tls' => $tls, 'timeout' => 25]);
    $out['code'] = $r['code'];
    if (!$r['ok']) {
        if ($r['errno'] === 6) { $out['st'] = 'dns'; $out['msg'] = 'Домен прослойки не резолвится изнутри контейнера.'; $out['hint'] = 'DNS ещё не обновился, либо хостинг не пускает контейнер на собственный публичный адрес. Проверьте ссылку с телефона.'; }
        elseif (inst_tls_errno($r['errno'])) { $out['st'] = 'tls'; $out['msg'] = 'Сертификат домена прослойки не прошёл проверку: ' . $r['err']; $out['hint'] = 'Реверс ещё не выпустил сертификат или отдаёт не тот. Клиенты тоже его не примут.'; }
        elseif ($r['errno'] === 7 || $r['errno'] === 28) { $out['st'] = 'unreach'; $out['msg'] = 'Домен не отвечает: ' . $r['err']; $out['hint'] = 'Реверс-прокси не слушает 443 или файрвол. Если домен ещё не переключён на этот сервер — повторите проверку позже.'; }
        else { $out['msg'] = 'Ошибка: ' . $r['err']; }
        return $out;
    }
    if ($r['code'] === 200 && (isset($r['hdr']['subscription-userinfo']) || isset($r['hdr']['profile-title']))) {
        $out['ok'] = true; $out['st'] = 'ok'; $out['msg'] = 'Подписка отдаётся через домен прослойки.'; return $out;
    }
    if ($r['code'] === 502 || $r['code'] === 504) { $out['st'] = 'gateway'; $out['msg'] = 'Реверс отвечает ' . $r['code'] . '.'; $out['hint'] = 'Реверс не достучался до прослойки (порт 8080?) или прослойка — до панели.'; return $out; }
    if (inst_is_html($r)) { $out['st'] = 'html'; $out['msg'] = 'По ссылке подписки приходит HTML-страница, а не конфиг.'; $out['hint'] = 'Домен ведёт не на прослойку (старый upstream / другой сервер) или путь с префиксом не совпадает.'; return $out; }
    if ($r['code'] === 404) { $out['st'] = 'nf'; $out['msg'] = 'HTTP 404 по ссылке подписки.'; $out['hint'] = 'Префикс подписки или shortUuid не совпадают с тем, что знает панель.'; return $out; }
    $out['st'] = 'odd'; $out['msg'] = 'HTTP ' . $r['code'] . ' без заголовков подписки.';
    return $out;
}

function inst_db_probe(array $db) {
    $err = '';
    if (($db['driver'] ?? 'sqlite') === 'sqlite') {
        $dir = dirname((string) ($db['path'] ?? ''));
        if ($dir === '' || !is_dir($dir) || !is_writable($dir)) return ['ok' => false, 'msg' => 'Каталог data/ недоступен для записи: ' . $dir];
    }
    $pdo = pdo_connect($db, $err);
    if ($pdo === null) return ['ok' => false, 'msg' => 'Не удалось подключиться к БД: ' . $err];
    return ['ok' => true, 'msg' => ($db['driver'] ?? 'sqlite') === 'mysql' ? 'MySQL/MariaDB отвечает (' . ($db['host'] ?? '') . ', база ' . ($db['name'] ?? '') . ').' : 'SQLite: ' . ($db['path'] ?? '')];
}

function inst_webhook_secret_ok($s) { return preg_match('~^[A-Za-z0-9]{64}$~', (string) $s) === 1; }

function inst_webhook_secret_new() {
    $a = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $s = '';
    for ($i = 0; $i < 64; $i++) $s .= $a[random_int(0, 61)];
    return $s;
}

// Откуда открыли мастер: домен и схема с учётом реверса.
function inst_self() {
    $host = trim((string) ($_SERVER['HTTP_X_FORWARDED_HOST'] ?? ''));
    if ($host !== '') $host = trim(explode(',', $host)[0]);
    if ($host === '') $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    $host = strtolower($host);
    $bare = preg_replace('~:\d+$~', '', $host);
    $proto = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
    if ($proto === '') $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return ['host' => $host, 'bare' => $bare, 'scheme' => $proto, 'base' => $proto . '://' . $host, 'is_local' => $host === '' || inst_host_is_local($bare)];
}

function inst_env_report() {
    $ext = [];
    foreach (['curl', 'pdo_sqlite', 'pdo_mysql', 'mbstring', 'dom', 'sodium', 'json'] as $e) $ext[$e] = extension_loaded($e);
    $data = dirname(__DIR__) . '/data';
    return [
        'php'      => PHP_VERSION,
        'sapi'     => PHP_SAPI,
        'ext'      => $ext,
        'docker'   => getenv('SUBMW_DOCKER') === '1',
        'image'    => trim((string) getenv('SUBMW_VERSION')),
        'scenario' => trim((string) getenv('SUBMW_SCENARIO')),
        'data_dir' => $data,
        'data_ok'  => is_dir($data) && is_writable($data),
        'env_db'   => function_exists('submw_env_db') ? submw_env_db() : null,
    ];
}
