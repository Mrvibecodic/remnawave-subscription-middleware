<?php
declare(strict_types=1);

require __DIR__ . '/_boot.php';

$p = db();

// Апгрейд: таблица чата без колонок сайта и учётной записи — колонки добавляются сами, повторный вызов ничего не ломает.
$p->exec('ALTER TABLE chat_sessions DROP COLUMN site');
$p->exec('ALTER TABLE chat_sessions DROP COLUMN sub_user');
$p->exec('ALTER TABLE chat_sessions DROP COLUMN sub_short');
set_setting('chat_tables', '1');
set_setting('chat_site_col', '');
set_setting('chat_acct_col', '');
$p->exec("INSERT INTO chat_sessions (token, ip) VALUES ('" . str_repeat('a', 32) . "', '10.0.0.1')");
t_ok('апгрейд: до миграции колонки нет', !db_has_cols($p, 'chat_sessions', ['site']));
t_ok('апгрейд: таблицы готовы', ensure_chat_tables());
t_ok('апгрейд: колонка добавлена', db_has_cols($p, 'chat_sessions', ['site']));
t_eq('апгрейд: флаг выставлен', setting('chat_site_col', ''), '1');
t_eq('апгрейд: старый диалог цел и без сайта', $p->query("SELECT site FROM chat_sessions WHERE ip = '10.0.0.1'")->fetchColumn(), null);
$l = chat_sessions_list(10);
t_ok('апгрейд: список диалогов отдаёт пустой сайт', count($l) === 1 && array_key_exists('site', $l[0]) && $l[0]['site'] === null);
t_ok('апгрейд: колонки учётной записи добавлены тем же проходом', db_has_cols($p, 'chat_sessions', ['sub_user', 'sub_short']) && setting('chat_acct_col', '') === '1');
t_ok('апгрейд: у старого диалога учётной записи нет', $l[0]['sub_user'] === null && $l[0]['sub_short'] === null);
// Колонки добавить не удалось (нет прав на ALTER): чат работает, метка просто не пишется.
set_setting('chat_acct_col', '');
$old = chat_session_create('10.0.0.2', 'ua', 'https://sub.example.org', ['short' => 'AbCd', 'user' => 'alice']);
$l = chat_sessions_list(10);
t_ok('без колонок учётной записи: диалог создаётся, список отдаёт пустую метку', is_array($old) && $old['sub_user'] === null && count($l) === 2 && $l[0]['sub_user'] === null && $l[1]['sub_short'] === null);
set_setting('chat_acct_col', '1');
$p->exec('DELETE FROM chat_sessions');

// Адрес сайта приводится к виду, в котором браузер шлёт Origin.
foreach ([
    'example.com'                    => 'https://example.com',
    ' HTTPS://Example.COM/shop?a=1 ' => 'https://example.com',
    'http://example.com'             => 'http://example.com',
    'https://example.com:443'        => 'https://example.com',
    'http://example.com:80/'         => 'http://example.com',
    'https://example.com:8443'       => 'https://example.com:8443',
    'http://localhost:3000'          => 'http://localhost:3000',
    'https://[::1]:8443'             => '',
    'sub.shop.example.com/'          => 'https://sub.shop.example.com',
    ''                               => '',
    'ftp://example.com'              => '',
    'javascript:alert(1)'            => '',
    'https://user:pw@example.com'    => '',
    'https://exa mple.com'           => '',
    'https://example.com;x'          => '',
    "https://example.com\nx"         => '',
    'https://*.example.com'          => '',
    'intranet'                       => '',
    'example.'                       => '',
    '.example.com'                   => '',
    'localhost'                      => 'https://localhost',
    '192.168.1.10:8080'              => 'https://192.168.1.10:8080',
    "https://example.com 'self'"     => '',
] as $in => $want) {
    t_eq('адрес сайта: ' . json_encode($in, JSON_UNESCAPED_UNICODE), chat_embed_origin($in), $want);
}
if (function_exists('idn_to_ascii')) t_eq('адрес сайта: кириллический домен', chat_embed_origin('пример.рф'), 'https://xn--e1afmkfd.xn--p1ai');
[$ok, $bad] = chat_embed_parse_sites("example.com\nhttps://example.com/\n  shop.example.com, ftp://x.y ;; http://a.b:8080\n???");
t_eq('список сайтов: без повторов, в порядке ввода', $ok, ['https://example.com', 'https://shop.example.com', 'http://a.b:8080']);
t_eq('список сайтов: непонятные строки названы', $bad, ['ftp://x.y', '???']);
t_eq('список сайтов: не больше 50', count(chat_embed_parse_sites(implode("\n", array_map(static fn($i) => "s{$i}.example.com", range(1, 80))))[0]), 50);

$site  = 'https://shop.example.com';
$other = 'https://blog.example.com';
$evil  = 'https://evil.example.net';
$mw = t_serve($tt_root, $tt_root . '/tools/tests/_router.php', [], ['opcache.enable' => '0']);
$form = ['content-type' => 'application/x-www-form-urlencoded'];
$api = static function (string $q, array $h = [], ?string $body = null) use ($mw): array {
    [$c, $hd, $b] = t_http($body === null ? 'GET' : 'POST', $mw . '/chat.php' . $q, $h, $body);
    return [$c, json_decode($b, true), $hd];
};
$e = '&e=' . rawurlencode($site);
$cn = 'submw_chat_e' . substr(hash('sha256', $site), 0, 8);

// По умолчанию всё выключено.
[$c, $h, $b] = t_http('GET', $mw . '/chat.php?widget=1');
t_ok('выключено: загрузчик пустой и не кэшируется', $c === 200 && strpos($b, 'iframe') === false && ($h['cache-control'] ?? '') === 'no-store');
[$c, $h] = t_http('GET', $mw . '/chat.php?embed=1&site=' . rawurlencode($site));
t_ok('выключено: страница виджета закрыта', $c === 403 && strpos($h['content-security-policy'] ?? '', "frame-ancestors 'none'") !== false);
[$c, $j] = $api('?api=open' . $e, $form, '');
t_ok('выключено: запросы виджета отклоняются', $c === 403 && ($j['error'] ?? '') === 'embed off');
[$c, $j] = $api('?api=open', $form, '');
t_ok('выключено: чат заглушки отвечает как раньше', $c === 403 && ($j['error'] ?? '') === 'chat disabled');

// Включён только внешний виджет, чат на заглушке выключен.
t_settings(['chat_embed_enabled' => '1', 'chat_embed_sites' => "{$site}\n{$other}", 'chat_embed_design' => 'lk', 'chat_greeting' => 'Здравствуйте!', 'chat_agent_name' => 'Оператор <b>"А"</b>', 'chat_widget_preset' => '2', 'chat_widget_position' => 'left']);
[$c, $h, $b] = t_http('GET', $mw . '/chat.php?widget=1');
t_ok('загрузчик: отдаётся скриптом', $c === 200 && strpos($h['content-type'] ?? '', 'application/javascript') === 0 && strpos($b, "createElement('iframe')") !== false);
t_ok('загрузчик: кэшируется ненадолго', ($h['cache-control'] ?? '') === 'public, max-age=300');
t_ok('загрузчик: слушает только свою страницу', strpos($b, 'e.origin!==org||e.source!==f.contentWindow') !== false);
[, , $b3] = t_http('GET', $mw . '/chat.php?widget=1', ['host' => 'evil.example.net']);
t_ok('загрузчик: кэшируемый ответ не зависит от заголовка Host', $b3 === $b && strpos($b, (string) parse_url($mw, PHP_URL_PORT)) === false);
[$c2, , $b2] = t_http('GET', $mw . '/chat.php?widget=lkmin');
t_ok('загрузчик: один и тот же для любого дизайна, дизайн берёт из своего адреса', $c2 === 200 && $b2 === $b && strpos($b, "searchParams.get('widget')") !== false);

[$c, $h, $b] = t_http('GET', $mw . '/chat.php?embed=1&site=' . rawurlencode($site));
$csp = $h['content-security-policy'] ?? '';
t_eq('страница виджета: открывается для разрешённого сайта', $c, 200);
t_ok('страница виджета: встраивать можно только на этот сайт', (bool) preg_match('~frame-ancestors https://shop\.example\.com$~', $csp), $csp);
t_ok('страница виджета: X-Frame-Options не ставится', !isset($h['x-frame-options']));
t_ok('страница виджета: не кэшируется и не отдаёт адрес наружу', ($h['cache-control'] ?? '') === 'no-store' && ($h['referrer-policy'] ?? '') === 'no-referrer');
t_ok('страница виджета: скрипт и стили разрешены только свои', preg_match("~script-src 'nonce-([^']+)'; style-src 'nonce-\\1'~", $csp, $m) && substr_count($b, 'nonce="' . htmlspecialchars($m[1], ENT_QUOTES) . '"') === 2 && substr_count($b, '<script') === 1 && substr_count($b, '<style') === 1);
t_ok('страница виджета: без встроенных style-атрибутов и обработчиков', !preg_match('~\sstyle="|\son[a-z]+="~', $b));
t_ok('страница виджета: выбранный дизайн, вид кнопки и сторона', strpos($b, 'class="w d-lk pos-left pre2"') !== false);
t_ok('страница виджета: имя агента экранировано', strpos($b, 'Оператор &lt;b&gt;&quot;А&quot;&lt;/b&gt;') !== false && strpos($b, '<b>"А"</b>') === false);
t_ok('страница виджета: сайт попал в настройки скрипта', strpos($b, '"site":"https:\/\/shop.example.com"') !== false);
foreach (chat_embed_designs() as $d) {
    t_settings(['chat_embed_design' => $d]);
    [$c, , $b] = t_http('GET', $mw . '/chat.php?embed=1&site=' . rawurlencode($other));
    t_ok('страница виджета: дизайн ' . $d, $c === 200 && strpos($b, 'class="w d-' . $d . ' ') !== false);
}
t_settings(['chat_embed_design' => 'нет такого']);
[, , $b] = t_http('GET', $mw . '/chat.php?embed=1&site=' . rawurlencode($site));
t_ok('страница виджета: неизвестный дизайн — первый', strpos($b, 'class="w d-rw ') !== false);
t_settings(['chat_embed_design' => 'rw']);
foreach (['lk' => 'lk', 'lkmin' => 'lkmin', 'rw' => 'rw', '1' => 'rw', 'x"><script>' => 'rw', '' => 'rw'] as $d => $want) {
    [$c, , $b] = t_http('GET', $mw . '/chat.php?embed=1&site=' . rawurlencode($site) . '&d=' . rawurlencode((string) $d));
    t_ok('страница виджета: дизайн из кода вставки ' . json_encode((string) $d) . ' → ' . $want, $c === 200 && strpos($b, 'class="w d-' . $want . ' ') !== false && strpos($b, '<script>') === false);
}
t_ok('само раскрытие: по умолчанию через 2 секунды', chat_embed_autoopen() === 2 && strpos($b, '"auto":2') !== false);
foreach (['0' => 0, '5' => 5, '-3' => 0, '999' => 60, 'abc' => 0] as $v => $want) {
    t_settings(['chat_embed_autoopen' => (string) $v]);
    unset($GLOBALS['submw_settings_cache']);
    [, , $b] = t_http('GET', $mw . '/chat.php?embed=1&site=' . rawurlencode($site));
    t_ok('само раскрытие: настройка ' . json_encode((string) $v) . ' → ' . $want, chat_embed_autoopen() === $want && strpos($b, '"auto":' . $want . ',') !== false);
}
foreach (['чужой сайт' => $evil, 'без сайта' => '', 'сайт с другой схемой' => 'http://shop.example.com', 'сайт с другим портом' => 'https://shop.example.com:8443'] as $name => $s) {
    [$c, $h] = t_http('GET', $mw . '/chat.php?embed=1&site=' . rawurlencode($s));
    t_ok('страница виджета: ' . $name . ' — отказ', $c === 403 && strpos($h['content-security-policy'] ?? '', "frame-ancestors 'none'") !== false);
}

[$c, $j] = $api('?api=open', $form, '');
t_ok('чат заглушки выключен — без метки сайта запросы не идут', $c === 403 && ($j['error'] ?? '') === 'chat disabled');
foreach (['чужой сайт' => $evil, 'пустой' => '', 'мусор' => "x'\"><"] as $name => $s) {
    [$c, $j] = $api('?api=open&e=' . rawurlencode($s), $form, '');
    t_ok('запрос виджета: ' . $name . ' — отказ', $c === 403 && ($j['error'] ?? '') === 'embed off');
}
foreach (['e[]=' . rawurlencode($site) => 'embed off', 'e[a][b]=1' => 'embed off'] as $qs => $want) {
    [$c, $j] = $api('?api=open&' . $qs, $form, '');
    t_ok('запрос виджета: массив вместо сайта (' . $qs . ') — отказ без ошибок PHP', $c === 403 && ($j['error'] ?? '') === $want);
}
foreach (['embed=1&site[]=' . rawurlencode($site) => 403, 'embed[]=1&site=' . rawurlencode($site) => 403, 'embed=1&site=' . rawurlencode($site) . '&d[]=lk' => 200] as $qs => $want) {
    [$c, , $b] = t_http('GET', $mw . '/chat.php?' . $qs);
    t_ok('страница виджета: массив в параметрах (' . $qs . ') — код ' . $want . ' без ошибок PHP', $c === $want && ($want !== 200 || strpos($b, 'class="w d-rw ') !== false));
}
[$c, $j] = $api('?api=send' . $e, $form + ['sec-fetch-site' => 'cross-site'], 'body=x');
t_ok('запрос виджета с чужой страницы — отказ', $c === 403 && ($j['error'] ?? '') === 'origin');
[$c, $j] = $api('?api=send' . $e, $form + ['sec-fetch-site' => 'same-site'], 'body=x');
t_ok('запрос виджета с соседнего поддомена — отказ', $c === 403 && ($j['error'] ?? '') === 'origin');
t_eq('отклонённые запросы диалогов не создали', (int) $p->query('SELECT COUNT(*) FROM chat_sessions')->fetchColumn(), 0);

[$c, $j] = $api('?api=open' . $e, $form + ['sec-fetch-site' => 'same-origin'], '');
t_ok('виджет: нового посетителя встречает приветствие', $c === 200 && $j['ok'] === true && $j['exists'] === false && $j['greeting'] === 'Здравствуйте!');
[$c, $j, $h] = $api('?api=send' . $e, $form + ['sec-fetch-site' => 'same-origin', 'x-forwarded-proto' => 'https'], 'body=' . rawurlencode('Привет с сайта'));
$tok = (string) ($j['token'] ?? '');
t_ok('виджет: первое сообщение создаёт диалог и отдаёт ключ', $c === 200 && $j['ok'] === true && (bool) preg_match('/^[a-f0-9]{32}$/', $tok));
$ck = $h['set-cookie'] ?? '';
t_ok('виджет: cookie для стороннего сайта', strpos($ck, $cn . '=' . $tok . ';') === 0 && strpos($ck, 'SameSite=None') !== false && strpos($ck, 'Secure') !== false && strpos($ck, 'Partitioned') !== false && strpos($ck, 'HttpOnly') !== false, $ck);
$row = $p->query('SELECT * FROM chat_sessions ORDER BY id DESC LIMIT 1')->fetch();
t_eq('виджет: у диалога записан сайт', $row['site'] ?? null, $site);
t_eq('виджет: список диалогов показывает сайт', chat_sessions_list(10)[0]['site'] ?? null, $site);
t_ok('виджет: сайт виден оператору в Telegram', strpos(chat_tg_format_history($row, chat_messages_last((int) $row['id'], 20)), "🌐 shop.example.com") !== false);

[$c, $j] = $api('?api=open' . $e, $form + ['x-chat-token' => $tok], '');
t_ok('виджет: диалог находится по ключу без cookie', $c === 200 && $j['exists'] === true && $j['token'] === $tok && count($j['messages']) === 2 && $j['messages'][1]['body'] === 'Привет с сайта');
[$c, $j] = $api('?api=open' . $e, $form + ['cookie' => $cn . '=' . $tok], '');
t_ok('виджет: диалог находится по cookie без ключа', $c === 200 && $j['exists'] === true && count($j['messages']) === 2);
[$c, $j] = $api('?api=open&e=' . rawurlencode($other), $form + ['cookie' => $cn . '=' . $tok], '');
t_ok('виджет: cookie одного сайта не открывает диалог на другом', $c === 200 && $j['exists'] === false);
[$c, $j] = $api('?api=open' . $e, $form + ['cookie' => 'submw_chat=' . $tok], '');
t_ok('виджет: cookie чата заглушки не подходит', $c === 200 && $j['exists'] === false);
[$c, $j] = $api('?api=open' . $e, $form + ['x-chat-token' => str_repeat('0', 32), 'cookie' => $cn . '=' . $tok], '');
t_ok('виджет: негодный ключ не подменяется cookie', $c === 200 && $j['exists'] === false);
chat_add_message((int) $row['id'], 'agent', 'admin', 'Ответ оператора');
[$c, $j] = $api('?api=poll&after=0' . $e, ['x-chat-token' => $tok]);
t_ok('виджет: ответ оператора приходит опросом', $c === 200 && $j['ok'] === true && end($j['messages'])['body'] === 'Ответ оператора' && end($j['messages'])['sender'] === 'agent');
[$c, $j, $h] = $api('?api=send' . $e, $form + ['x-chat-token' => $tok, 'x-forwarded-proto' => 'https'], 'body=' . rawurlencode('Второе'));
t_ok('виджет: следующее сообщение идёт в тот же диалог', $c === 200 && $j['token'] === $tok && !isset($h['set-cookie']) && (int) $p->query('SELECT COUNT(*) FROM chat_sessions')->fetchColumn() === 1);
[$c, $j, $h] = $api('?api=send' . $e, $form, 'body=' . rawurlencode('Без https'));
t_ok('виджет: без HTTPS cookie не ставится, ключ отдаётся', $c === 200 && !isset($h['set-cookie']) && preg_match('/^[a-f0-9]{32}$/', (string) ($j['token'] ?? '')) === 1);
chat_session_delete((int) $row['id']);
[$c, $j] = $api('?api=poll&after=0' . $e, ['x-chat-token' => $tok]);
t_ok('виджет: удалённый оператором диалог сбрасывается', $c === 200 && ($j['gone'] ?? false) === true);
[$c, $j] = $api('?api=send' . '&e=' . rawurlencode($other), $form, 'body=' . rawurlencode('С другого сайта'));
t_eq('виджет: второй разрешённый сайт получает свою метку', $p->query('SELECT site FROM chat_sessions ORDER BY id DESC LIMIT 1')->fetchColumn(), $other);

// Сайт убрали из списка — его виджет перестаёт работать, остальные живут.
t_settings(['chat_embed_sites' => $other]);
[$c] = $api('?api=open' . $e, $form + ['x-chat-token' => $tok], '');
t_eq('сайт убран из списка: запросы отклоняются', $c, 403);
[$c] = t_http('GET', $mw . '/chat.php?embed=1&site=' . rawurlencode($site));
t_eq('сайт убран из списка: страница закрыта', $c, 403);
[$c] = t_http('GET', $mw . '/chat.php?embed=1&site=' . rawurlencode($other));
t_eq('сайт убран из списка: другой сайт работает', $c, 200);
t_settings(['chat_embed_sites' => '']);
[$c] = t_http('GET', $mw . '/chat.php?embed=1&site=' . rawurlencode($other));
t_eq('пустой список сайтов: виджет закрыт для всех', $c, 403);

// Чат на заглушке ведёт себя как раньше и о внешнем виджете не знает.
t_settings(['chat_enabled' => '1', 'chat_embed_enabled' => '0']);
$p->exec('DELETE FROM chat_sessions');
[$c, $j, $h] = $api('?api=send', $form + ['sec-fetch-site' => 'cross-site'], 'body=' . rawurlencode('С заглушки'));
$ck = $h['set-cookie'] ?? '';
t_ok('заглушка: ответ без ключа', $c === 200 && $j === ['ok' => true, 'id' => $j['id']]);
t_ok('заглушка: прежняя cookie', strpos($ck, 'submw_chat=') === 0 && stripos($ck, 'SameSite=Lax') !== false && stripos($ck, 'Partitioned') === false, $ck);
t_eq('заглушка: диалог без сайта', $p->query('SELECT site FROM chat_sessions ORDER BY id DESC LIMIT 1')->fetchColumn(), null);
preg_match('/submw_chat=([a-f0-9]{32})/', $ck, $m);
[$c, $j] = $api('?api=open', $form + ['x-chat-token' => str_repeat('0', 32), 'cookie' => 'submw_chat=' . $m[1]], '');
t_ok('заглушка: заголовок с ключом не учитывается', $c === 200 && $j['exists'] === true);
[$c, $j] = $api('?api=open' . $e, $form, '');
t_ok('заглушка включена, виджет выключен: запросы виджета отклоняются', $c === 403 && ($j['error'] ?? '') === 'embed off');
[, , $b] = t_http('GET', $mw . '/');
t_ok('заглушка: встроенный чат на месте и в прежнем виде', strpos($b, 'id="swcWrap"') !== false && strpos($b, "var API = '/chat.php';") !== false && strpos($b, 'd-rw') === false);

// Чат на странице подписки: прослойка сама вставляет виджет, свой домен в список сайтов не вписывают.
t_settings(['chat_enabled' => '0', 'chat_embed_enabled' => '0', 'chat_embed_sites' => '', 'chat_subpage' => '0']);
$page_html = "<!doctype html><html><head><title>x</title></head><body><div id=\"root\"></div><script>var s='</body>';</script></body></html>";
t_eq('страница подписки: выключено — HTML не меняется', chat_subpage_apply($page_html), $page_html);
t_settings(['chat_subpage' => '1']);
unset($GLOBALS['submw_settings_cache']);
$tag = '<script src="/chat.php?widget=rw" async></script>';
t_eq('страница подписки: виджет встаёт перед последним </body>', chat_subpage_apply($page_html), substr($page_html, 0, -14) . $tag . '</body></html>');
t_eq('страница подписки: тег в верхнем регистре', chat_subpage_apply('<HTML><BODY>x</BODY></HTML>'), '<HTML><BODY>x' . $tag . '</BODY></HTML>');
t_eq('страница подписки: без </body> HTML не меняется', chat_subpage_apply('{"not":"html"}'), '{"not":"html"}');
t_eq('страница подписки: не строка — без изменений', chat_subpage_apply(false), false);
t_settings(['chat_subpage_design' => 'lkmin']);
unset($GLOBALS['submw_settings_cache']);
t_ok('страница подписки: выбранный дизайн в адресе скрипта', strpos(chat_subpage_apply($page_html), '/chat.php?widget=lkmin"') !== false);
t_settings(['chat_subpage_design' => 'rw']);

$own = 'https://sub.example.org';
[$c, $h, $b] = t_http('GET', $mw . '/chat.php?widget=rw');
t_ok('страница подписки: загрузчик работает и без виджета для других сайтов', $c === 200 && strpos($b, "createElement('iframe')") !== false);
[$c, $h] = t_http('GET', $mw . '/chat.php?embed=1&site=' . rawurlencode($own));
t_ok('страница подписки: окно отдаётся только для вставки на своём домене', $c === 200 && (bool) preg_match("~frame-ancestors 'self'$~", $h['content-security-policy'] ?? ''), $h['content-security-policy'] ?? '');
$eo = '&e=' . rawurlencode($own);
[$c, $j] = $api('?api=open' . $eo, $form + ['origin' => $own], '');
t_ok('страница подписки: запрос окна со своего домена принят', $c === 200 && $j['ok'] === true && $j['exists'] === false);
[$c, $j] = $api('?api=open' . $eo, $form + ['origin' => 'HTTPS://Sub.Example.Org:443'], '');
t_ok('страница подписки: Origin сравнивается в едином виде', $c === 200 && $j['ok'] === true);
foreach (['без Origin' => [], 'Origin другого сайта' => ['origin' => $evil], 'Origin с другим портом' => ['origin' => $own . ':8443'], 'Origin null' => ['origin' => 'null']] as $name => $hh) {
    [$c, $j] = $api('?api=open' . $eo, $form + $hh, '');
    t_ok('страница подписки: ' . $name . ' — отказ', $c === 403 && ($j['error'] ?? '') === 'embed off');
}
[$c, $j] = $api('?api=open&e=' . rawurlencode($evil), $form + ['origin' => $own], '');
t_ok('страница подписки: чужой сайт под своим Origin — отказ', $c === 403);
[$c, $j] = $api('?api=send' . $eo, $form + ['origin' => $own, 'sec-fetch-site' => 'cross-site'], 'body=x');
t_ok('страница подписки: запрос с чужой страницы — отказ', $c === 403 && ($j['error'] ?? '') === 'origin');
[$c, $j] = $api('?api=send' . $eo, $form + ['origin' => $own, 'sec-fetch-site' => 'same-origin'], 'body=' . rawurlencode('Со страницы подписки'));
$tok2 = (string) ($j['token'] ?? '');
t_ok('страница подписки: сообщение создаёт диалог', $c === 200 && $j['ok'] === true && $tok2 !== '');
t_eq('страница подписки: у диалога сайт — домен прослойки', $p->query('SELECT site FROM chat_sessions ORDER BY id DESC LIMIT 1')->fetchColumn(), $own);
[$c, $j] = $api('?api=poll&after=0' . $eo, $form + ['origin' => $own, 'x-chat-token' => $tok2], '');
t_ok('страница подписки: опрос окна (POST) отдаёт переписку', $c === 200 && $j['ok'] === true && end($j['messages'])['body'] === 'Со страницы подписки');

// Учётная запись страницы подписки: метка подписана, диалог получает её только со своей страницы.
unset($GLOBALS['submw_settings_cache']);
$key = chat_subpage_key();
t_ok('учётная запись: ключ подписи создаётся один раз', (bool) preg_match('/^[a-f0-9]{64}$/', $key) && chat_subpage_key() === $key && setting('chat_subpage_key', '') === $key);
$panel = static fn(array $r): string => '<!doctype html><html><body><div id="sbpg" data-panel="' . base64_encode(json_encode(['response' => $r])) . '"></div><div id="root"></div></body></html>';
$alice = ['isFound' => true, 'user' => ['shortUuid' => 'AbCdEfGhIjKlMnOp', 'username' => 'alice_01', 'isActive' => true], 'links' => []];
t_eq('учётная запись: читается из страницы', chat_subpage_owner($panel($alice)), ['short' => 'AbCdEfGhIjKlMnOp', 'user' => 'alice_01']);
t_eq('учётная запись: подписка истекла — запись та же', chat_subpage_owner($panel(['user' => ['shortUuid' => 'Zy', 'username' => 'bob', 'isActive' => false]])), ['short' => 'Zy', 'user' => 'bob']);
t_eq('учётная запись: без имени остаётся короткий идентификатор', chat_subpage_owner($panel(['user' => ['shortUuid' => 'Zy', 'username' => null]])), ['short' => 'Zy', 'user' => '']);
foreach ([
    'подписка не найдена'       => $panel(['isFound' => false, 'user' => ['shortUuid' => 'Zy', 'username' => 'bob']]),
    'нет пользователя'          => $panel(['isFound' => true]),
    'пустой идентификатор'      => $panel(['user' => ['shortUuid' => ' ', 'username' => 'bob']]),
    'идентификатор не строка'   => $panel(['user' => ['shortUuid' => ['x'], 'username' => 'bob']]),
    'данные не JSON'            => '<body><div id="sbpg" data-panel="' . base64_encode('not json') . '"></div></body>',
    'данные не base64'          => '<body><div id="sbpg" data-panel="@@@"></div></body>',
    'страница без блока данных' => $page_html,
    'не строка'                 => false,
] as $name => $html) t_eq('учётная запись: ' . $name . ' — метки нет', chat_subpage_owner($html), null);

$now = 1800000000;
$ut = chat_subpage_token('AbCdEfGhIjKlMnOp', 'alice_01', $now);
t_ok('метка: только символы, безопасные для адреса и атрибута', (bool) preg_match('~^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]{43}$~', $ut), $ut);
t_eq('метка: читается обратно', chat_subpage_ident($ut, $now), ['short' => 'AbCdEfGhIjKlMnOp', 'user' => 'alice_01']);
t_eq('метка: за секунду до конца суток ещё годна', chat_subpage_ident($ut, $now + 86400), ['short' => 'AbCdEfGhIjKlMnOp', 'user' => 'alice_01']);
t_eq('метка: через сутки не принимается', chat_subpage_ident($ut, $now + 86401), null);
t_eq('метка: имя кириллицей', chat_subpage_ident(chat_subpage_token('Zy', 'Вася Пупкин', $now), $now), ['short' => 'Zy', 'user' => 'Вася Пупкин']);
[$pl, $sg] = explode('.', $ut);
$forged = chat_b64u("AbCdEfGhIjKlMnOp\nadmin\n" . ($now + 86400));
foreach ([
    'чужая учётная запись под старой подписью' => $forged . '.' . $sg,
    'продлённый срок'                          => chat_b64u("AbCdEfGhIjKlMnOp\nalice_01\n" . ($now + 99999999)) . '.' . $sg,
    'испорченная подпись'                      => $pl . '.' . strrev($sg),
    'подпись другим ключом'                    => $forged . '.' . chat_b64u(hash_hmac('sha256', "AbCdEfGhIjKlMnOp\nadmin\n" . ($now + 86400), str_repeat('0', 64), true)),
    'без подписи'                              => $pl,
    'пустая'                                   => '',
    'мусор'                                    => "x'\"><.y",
    'массив'                                   => [$ut],
    'null'                                     => null,
    'слишком длинная'                          => str_repeat('A', 700) . '.' . $sg,
] as $name => $bad) t_eq('метка: ' . $name . ' — не принимается', chat_subpage_ident($bad, $now), null);
t_eq('метка: перевод строки в имени — метка не выдаётся', chat_subpage_token('Zy', "bob\nadmin"), '');
t_eq('метка: пустой идентификатор — метка не выдаётся', chat_subpage_token('', 'bob'), '');
t_eq('метка: слишком длинный идентификатор — метка не выдаётся', chat_subpage_token(str_repeat('a', 65), 'bob'), '');

$out = chat_subpage_apply($panel($alice));
t_ok('страница подписки: метка учётной записи стоит в теге виджета', (bool) preg_match('~<script src="/chat\.php\?widget=rw" data-u="([A-Za-z0-9_.-]+)" async></script></body></html>$~', $out, $mm) && chat_subpage_ident($mm[1]) === ['short' => 'AbCdEfGhIjKlMnOp', 'user' => 'alice_01']);
t_eq('страница подписки: остальная страница не тронута', preg_replace('~<script src="/chat\.php[^>]*></script>~', '', $out), $panel($alice));
t_eq('страница подписки: без данных учётной записи тег прежний', chat_subpage_apply($page_html), substr($page_html, 0, -14) . $tag . '</body></html>');

$ua_tok = chat_subpage_token('AbCdEfGhIjKlMnOp', 'alice_01');
$ub_tok = chat_subpage_token('ZyXwVuTsRqPoNmLk', 'bob_02');
[$c, , $b] = t_http('GET', $mw . '/chat.php?widget=rw');
t_ok('загрузчик: метку берёт из своего тега и передаёт окну', $c === 200 && strpos($b, "cs.getAttribute('data-u')") !== false && strpos($b, "'&u='+au") !== false);
[$c, , $b] = t_http('GET', $mw . '/chat.php?embed=1&site=' . rawurlencode($own) . '&u=' . $ua_tok);
t_ok('окно: верная метка уходит в настройки окна, диалог хранится отдельно для учётной записи', $c === 200 && strpos($b, '"u":"' . $ua_tok . '","ak":"' . substr(hash('sha256', 'AbCdEfGhIjKlMnOp'), 0, 8) . '"') !== false);
foreach (['подделанная' => $forged . '.' . $sg, 'мусор' => '%22%3E%3Cscript%3E', 'массив' => null] as $name => $bad) {
    [$c, , $b] = t_http('GET', $mw . '/chat.php?embed=1&site=' . rawurlencode($own) . ($bad === null ? '&u[]=x' : '&u=' . $bad));
    t_ok('окно: ' . $name . ' метка — окно работает без учётной записи', $c === 200 && strpos($b, '"u":"","ak":""') !== false && strpos($b, '<script>') === false);
}
[$c, $h] = t_http('GET', $mw . '/chat.php?embed=1&site=' . rawurlencode($own), ['sec-fetch-site' => 'same-origin']);
t_eq('окно: страница своего домена получает окно', $c, 200);
foreach (['cross-site', 'same-site', 'none'] as $sfs) {
    [$c, $h] = t_http('GET', $mw . '/chat.php?embed=1&site=' . rawurlencode($own) . '&u=' . $ua_tok, ['sec-fetch-site' => $sfs]);
    t_ok('окно: запрос не со своей страницы (' . $sfs . ') — отказ', $c === 403 && strpos($h['content-security-policy'] ?? '', "frame-ancestors 'none'") !== false);
}

$p->exec('DELETE FROM chat_sessions');
$so = $form + ['origin' => $own, 'sec-fetch-site' => 'same-origin', 'x-forwarded-proto' => 'https'];
$last = static fn() => $p->query('SELECT site, sub_user, sub_short FROM chat_sessions ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
[$c, $j, $h] = $api('?api=send' . $eo . '&u=' . $ua_tok, $so, 'body=' . rawurlencode('Пишет alice'));
$ta = (string) ($j['token'] ?? '');
t_ok('учётная запись: первое сообщение со страницы подписки создаёт диалог', $c === 200 && $ta !== '');
t_eq('учётная запись: диалог подписан учётной записью панели', $last(), ['site' => $own, 'sub_user' => 'alice_01', 'sub_short' => 'AbCdEfGhIjKlMnOp']);
$cna = 'submw_chat_e' . substr(hash('sha256', $own . "\nAbCdEfGhIjKlMnOp"), 0, 8);
t_ok('учётная запись: cookie диалога своя у каждой учётной записи', strpos($h['set-cookie'] ?? '', $cna . '=' . $ta . ';') === 0 && $cna !== 'submw_chat_e' . substr(hash('sha256', $own), 0, 8), $h['set-cookie'] ?? '');
[$c, $j] = $api('?api=open' . $eo . '&u=' . $ua_tok, $form + ['origin' => $own, 'cookie' => $cna . '=' . $ta], '');
t_ok('учётная запись: по своей cookie диалог открывается', $c === 200 && $j['exists'] === true && count($j['messages']) === 2);
[$c, $j] = $api('?api=open' . $eo . '&u=' . $ub_tok, $form + ['origin' => $own, 'cookie' => $cna . '=' . $ta], '');
t_ok('учётная запись: страница другой подписки чужой диалог по cookie не подтягивает', $c === 200 && $j['exists'] === false);
[$c, $j] = $api('?api=open' . $eo, $form + ['origin' => $own, 'cookie' => $cna . '=' . $ta], '');
t_ok('учётная запись: страница без метки чужой диалог по cookie не подтягивает', $c === 200 && $j['exists'] === false);
[$c, $j] = $api('?api=send' . $eo . '&u=' . $ub_tok, $so, 'body=' . rawurlencode('Пишет bob'));
$tb = (string) ($j['token'] ?? '');
t_ok('учётная запись: у второй подписки свой диалог', $c === 200 && $tb !== '' && $tb !== $ta && $last() === ['site' => $own, 'sub_user' => 'bob_02', 'sub_short' => 'ZyXwVuTsRqPoNmLk']);
[$c, $j] = $api('?api=send' . $eo . '&u=' . $ub_tok, $so + ['x-chat-token' => $ta], 'body=' . rawurlencode('Ещё от alice'));
t_ok('учётная запись: метка уже подписанного диалога не меняется', $c === 200 && $p->query("SELECT sub_user FROM chat_sessions WHERE token = '$ta'")->fetchColumn() === 'alice_01');
[$c, $j] = $api('?api=send' . $eo . '&u=' . $forged . '.' . $sg, $so, 'body=' . rawurlencode('Подделка'));
$tf = (string) ($j['token'] ?? '');
t_ok('учётная запись: подделанная метка — диалог создаётся без учётной записи', $c === 200 && $tf !== '' && $last() === ['site' => $own, 'sub_user' => null, 'sub_short' => null]);
[$c, $j] = $api('?api=send' . $eo . '&u[]=x', $so, 'body=' . rawurlencode('Массив'));
t_ok('учётная запись: массив вместо метки — без ошибок PHP, без учётной записи', $c === 200 && $j['ok'] === true && $last()['sub_user'] === null);
[$c, $j] = $api('?api=send' . $eo . '&u=' . $ua_tok, $so + ['x-chat-token' => $tf], 'body=' . rawurlencode('Метка появилась позже'));
t_ok('учётная запись: диалог без метки получает её со следующим сообщением со своей страницы', $c === 200 && $p->query("SELECT sub_user FROM chat_sessions WHERE token = '$tf'")->fetchColumn() === 'alice_01');
$row = array_values(array_filter(chat_sessions_list(10), static fn($r) => $r['token'] === $tb))[0];
t_ok('учётная запись: список диалогов для админки отдаёт её', $row['sub_user'] === 'bob_02' && $row['sub_short'] === 'ZyXwVuTsRqPoNmLk');
// Метка действует только на домене самой прослойки: окно на чужом сайте из списка шлёт запросы с тем же Origin, но сайт у него другой.
t_settings(['chat_embed_enabled' => '1', 'chat_embed_sites' => $site]);
[$c, $j] = $api('?api=send' . $e . '&u=' . $ua_tok, $form + ['origin' => $own, 'sec-fetch-site' => 'same-origin'], 'body=' . rawurlencode('С чужого сайта с меткой'));
t_ok('учётная запись: на чужом сайте метка не действует', $c === 200 && $last() === ['site' => $site, 'sub_user' => null, 'sub_short' => null], json_encode([$c, $j, $last()]));
[$c, $j] = $api('?api=send' . $eo . '&u=' . $ua_tok, $form + ['origin' => $site, 'sec-fetch-site' => 'same-origin'], 'body=x');
t_eq('учётная запись: чужой сайт под видом домена прослойки — отказ', $c, 403);
t_settings(['chat_embed_enabled' => '0', 'chat_embed_sites' => '']);

t_settings(['chat_subpage' => '0']);
[$c, , $b] = t_http('GET', $mw . '/chat.php?embed=1&site=' . rawurlencode($own) . '&u=' . $ua_tok);
t_eq('страница подписки: выключили — метка окно не открывает', $c, 403);
[$c] = $api('?api=open' . $eo, $form + ['origin' => $own, 'x-chat-token' => $tok2], '');
t_eq('страница подписки: выключили — запросы окна отклоняются', $c, 403);
[$c] = t_http('GET', $mw . '/chat.php?embed=1&site=' . rawurlencode($own));
t_eq('страница подписки: выключили — окно закрыто', $c, 403);

// Страница подписки из контейнера subscription-page проходит через прослойку: виджет вставляется по пути.
file_put_contents($tt_root . '/data/mock_subpage.php', '<?php
$html = "<!doctype html><html><head><title>sub</title></head><body><div id=\"root\"></div></body></html>";
if (strpos($_SERVER["REQUEST_URI"], "/assets/") === 0) { header("Content-Type: application/javascript"); echo "var a=\"</body>\";"; return; }
if (strpos($_SERVER["REQUEST_URI"], "/WithUserData") === 0) $html = str_replace("<div id=\"root\">", "<div id=\"sbpg\" data-panel=\"" . base64_encode(json_encode(["response" => ["isFound" => true, "user" => ["shortUuid" => "WithUserData", "username" => "carol_03"]]])) . "\"></div><div id=\"root\">", $html);
header("ETag: \"v1\""); header("Content-Type: text/html; charset=utf-8");
if (($_SERVER["HTTP_IF_NONE_MATCH"] ?? "") === "\"v1\"") { http_response_code(304); return; }
echo $html;');
$sp = t_serve($tt_root . '/data', $tt_root . '/data/mock_subpage.php');
$src_html = '<!doctype html><html><head><title>sub</title></head><body><div id="root"></div></body></html>';
t_settings(['sub_source' => 'panel', 'subpage_external_url' => $sp, 'pagekeys_mode' => 'off']);
$ua = ['user-agent' => 'Mozilla/5.0 (X11; Linux x86_64) Chrome/130.0 Safari/537.36'];
[$c, $h, $b] = t_http('GET', $mw . '/AbCdEfGhIjKlMnOp', $ua);
t_ok('через прослойку, чат выключен: страница как у источника, ETag на месте', $c === 200 && $b === $src_html && ($h['etag'] ?? '') === '"v1"');
[$c, , $b] = t_http('GET', $mw . '/AbCdEfGhIjKlMnOp', $ua + ['if-none-match' => '"v1"']);
t_eq('через прослойку, чат выключен: условный запрос доходит до источника (304)', $c, 304);
t_settings(['chat_subpage' => '1']);
[$c, $h, $b] = t_http('GET', $mw . '/AbCdEfGhIjKlMnOp', $ua);
t_ok('через прослойку, чат включён: виджет вставлен, ETag убран', $c === 200 && $b === str_replace('</body>', $tag . '</body>', $src_html) && !isset($h['etag']));
[$c, , $b] = t_http('GET', $mw . '/AbCdEfGhIjKlMnOp', $ua + ['if-none-match' => '"v1"']);
t_ok('через прослойку, чат включён: браузер не остаётся со старой страницей из кэша', $c === 200 && strpos($b, $tag) !== false);
[$c, , $b] = t_http('GET', $mw . '/assets/app.js', $ua);
t_ok('через прослойку: файлы страницы не трогаются', $c === 200 && $b === 'var a="</body>";');
[$c, , $b] = t_http('GET', $mw . '/WithUserData', $ua);
t_ok('через прослойку: в теге виджета метка учётной записи этой страницы', $c === 200 && (bool) preg_match('~<script src="/chat\.php\?widget=rw" data-u="([A-Za-z0-9_.-]+)" async></script></body></html>$~', $b, $mm) && chat_subpage_ident($mm[1]) === ['short' => 'WithUserData', 'user' => 'carol_03']);
t_settings(['chat_subpage' => '0', 'sub_source' => 'mirror', 'subpage_external_url' => '']);

// Настройки в админке.
set_setting('update_state', json_encode(['checked_at' => time()]));
t_settings(['geoip_auto' => '0']);
$jar = t_admin_login($mw);
[$c, , $page] = t_http('GET', $mw . '/admin/index.php?tab=chat', [], null, $jar);
t_ok('админка: блок виджета на вкладке чата', $c === 200 && strpos($page, 'name="chat_embed_enabled"') !== false && strpos($page, 'name="chat_embed_sites"') !== false && substr_count($page, 'name="chat_embed_design"') === 3);
t_ok('админка: код для вставки с выбранным дизайном', strpos($page, htmlspecialchars('<script src="https://' . parse_url($mw, PHP_URL_HOST) . ':' . parse_url($mw, PHP_URL_PORT) . '/chat.php?widget=rw" async></script>', ENT_QUOTES)) !== false);
t_ok('админка: поле времени само раскрытия', strpos($page, 'name="chat_embed_autoopen"') !== false);
t_ok('админка: выключатель чата на странице подписки', strpos($page, 'name="chat_subpage"') !== false && strpos($page, 'name="chat_subpage_design"') !== false);
t_ok('админка: у диалога со страницы подписки подписана учётная запись', strpos($page, '"sub_user":"bob_02"') !== false && strpos($page, 'function cbAcct(s)') !== false && strpos($page, 'Учётная запись в панели. Диалог начат с её страницы подписки') !== false);
[$c, , $r] = t_http('GET', $mw . '/admin/index.php?ajax=chat_sessions', [], null, $jar);
t_ok('админка: обновление списка диалогов отдаёт учётную запись', $c === 200 && in_array('alice_01', array_column(json_decode($r, true)['sessions'], 'sub_user'), true));
preg_match('~name="csrf" value="([0-9a-f]+)"~', $page, $m);
$save = static function (array $f) use ($mw, $jar, $m, $form): string {
    [, , $r] = t_http('POST', $mw . '/admin/index.php', $form, http_build_query(['csrf' => $m[1], 'action' => 'save_chat_cfg', 'xhr' => '1', 'chat_poll_interval' => '4'] + $f), $jar);
    unset($GLOBALS['submw_settings_cache']);
    return (string) (json_decode($r, true)['msg'] ?? '');
};
$msg = $save(['chat_embed_enabled' => 'on', 'chat_embed_design' => 'lkmin', 'chat_embed_sites' => "Shop.Example.com/path\r\nhttps://blog.example.com\r\nне сайт!\r\nshop.example.com"]);
t_eq('админка: сайты сохранены в едином виде', setting('chat_embed_sites', ''), "https://shop.example.com\nhttps://blog.example.com");
t_ok('админка: непонятные строки названы в сообщении', strpos($msg, 'не похоже на адрес сайта, пропущено: не, сайт!') !== false, $msg);
t_eq('админка: виджет включён', setting('chat_embed_enabled', ''), '1');
t_eq('админка: дизайн сохранён', setting('chat_embed_design', ''), 'lkmin');
t_eq('админка: без поля времени само раскрытие — 2 секунды', setting('chat_embed_autoopen', ''), '2');
$save(['chat_embed_enabled' => 'on', 'chat_embed_design' => 'lkmin', 'chat_embed_sites' => 'shop.example.com', 'chat_embed_autoopen' => '400']);
t_eq('админка: время само раскрытия ограничено минутой', setting('chat_embed_autoopen', ''), '60');
$save(['chat_embed_enabled' => 'on', 'chat_embed_design' => 'lkmin', 'chat_embed_sites' => "shop.example.com\nblog.example.com", 'chat_embed_autoopen' => '0']);
t_eq('админка: само раскрытие выключается нулём', setting('chat_embed_autoopen', ''), '0');
[, , $page] = t_http('GET', $mw . '/admin/index.php?tab=chat', [], null, $jar);
t_ok('админка: код для вставки следует за выбранным дизайном', strpos($page, '/chat.php?widget=lkmin&quot; async') !== false);
$save(['chat_embed_sites' => '', 'chat_subpage' => 'on', 'chat_subpage_design' => 'lk']);
t_ok('админка: чат на странице подписки включается отдельно от виджета', setting('chat_subpage', '') === '1' && setting('chat_subpage_design', '') === 'lk' && setting('chat_embed_enabled', '') === '0');
$save(['chat_embed_sites' => '', 'chat_subpage_design' => ['x']]);
t_ok('админка: чат на странице подписки выключается, чужой дизайн не принимается', setting('chat_subpage', '') === '0' && setting('chat_subpage_design', '') === 'rw');
$save(['chat_embed_enabled' => 'on', 'chat_embed_design' => '<script>', 'chat_embed_sites' => 'shop.example.com']);
t_eq('админка: чужое значение дизайна не принимается', setting('chat_embed_design', ''), 'rw');
$save(['chat_embed_enabled' => 'on', 'chat_embed_design' => ['lk'], 'chat_embed_sites' => ['evil.example.net']]);
t_ok('админка: массивы в полях виджета не принимаются', setting('chat_embed_design', '') === 'rw' && setting('chat_embed_sites', '') === '');
$save(['chat_embed_enabled' => 'on', 'chat_embed_design' => 'rw', 'chat_embed_sites' => 'shop.example.com']);
$save(['chat_enabled' => 'on']);
t_ok('админка: форма без полей виджета его не трогает', setting('chat_embed_enabled', '') === '1' && setting('chat_embed_sites', '') === 'https://shop.example.com');
$save(['chat_embed_sites' => 'shop.example.com']);
t_eq('админка: выключатель снимается', setting('chat_embed_enabled', ''), '0');
[, , $r] = t_http('POST', $mw . '/admin/index.php', $form, http_build_query(['csrf' => 'bad', 'action' => 'save_chat_cfg', 'xhr' => '1', 'chat_embed_enabled' => 'on', 'chat_embed_sites' => 'evil.example.net']), $jar);
unset($GLOBALS['submw_settings_cache']);
t_ok('админка: без CSRF настройки виджета не меняются', setting('chat_embed_enabled', '') === '0' && setting('chat_embed_sites', '') === 'https://shop.example.com');
[, , $r] = t_http('GET', $mw . '/admin/index.php?ajax=chat_sessions', [], null, $jar);
t_ok('админка: список диалогов отдаёт поле сайта', array_key_exists('site', json_decode($r, true)['sessions'][0] ?? []));

t_done();
