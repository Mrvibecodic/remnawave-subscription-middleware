<?php
declare(strict_types=1);

require __DIR__ . '/_boot.php';

// Чистые проверки: что защищено, что ловят наборы, как сравниваются пути.
foreach (['assets/.app-config-v2.json', 'assets/index-Ab12.js', 'assets/favicon-32x32.png', 'sub/assets/x.css', '.well-known/acme-challenge/x', 'cdn-cgi/trace',
          'favicon.ico', 'apple-touch-icon.png', 'manifest.json', 'site.webmanifest', 'locales/ru.json', '_next/static/x.js', ''] as $pth) {
    t_ok('защищено: /' . $pth, junk_protected($pth));
}
foreach (['wp-login.php', '.env', 'AbCdEfGhIjKlMnOp', 'AbCdEfGhIjKlMnOp/clash', 'robots.txt', 'admin/config.json'] as $pth) {
    t_ok('не защищено: /' . $pth, !junk_protected($pth));
}
foreach (['.env', '.env.local', 'app/.env', '.git/config', 'wp-login.php', 'wp-admin/', 'wp-admin', 'x.php', 'a/b.php5', 'phpmyadmin/index.php', 'cgi-bin/luci', 'backup.sql', 'id_rsa.key', 'xmlrpc.php'] as $pth) {
    t_ok('набор путей ловит /' . $pth, junk_set_paths_match($pth));
}
foreach (['AbCdEfGhIjKlMnOp', 'AbCdEfGhIjKlMnOp/clash', 'sub/AbCdEfGhIjKlMnOp/sing-box', 'robots.txt', 'assets/.app-config-v2.json', 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.Sfl-KxwRJSMeKKF2QT4fwpMeJf36POk6yJV_adQssw5c', 'api/v1/users', 'phpinfo'] as $pth) {
    t_ok('набор путей не трогает /' . $pth, !junk_set_paths_match($pth));
}
t_ok('боты: Googlebot', junk_set_bots_match('Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'));
t_ok('боты: zgrab', junk_set_bots_match('Mozilla/5.0 zgrab/0.x'));
$clients = ['Happ/3.6.1', 'Happ/2.1.0/ios/17.4', 'INCY/1.0.3 (iOS 18.0)', 'ClashMeta/1.19.0 mihomo', 'mihomo/1.19.0', 'clash-verge/v2.2.3', 'clash.meta', 'ClashMetaForAndroid/2.11.5.Meta',
    'ClashX/1.118.0', 'ClashX Meta/1.4.5', 'ClodClash/0.1.13 (Windows)', 'Exclave/0.13.10', 'FlClash/v0.8.80 clash-verge Platform/android', 'FlClash X/v0.2.0 Platform/windows', 'FoXray/3.1.0',
    'HiddifyNext/2.5.7 (android) like ClashMeta v2ray sing-box', 'HiddifyNextX/1.0', 'husi/0.9.12', 'karing/1.1.2.606 android', 'koala-clash/0.2.6', 'Matsuri/0.8.6', 'NekoBox/Android/1.3.4 (Prefer ClashMeta Format)',
    'SFA/1.10.1 (Android 14; sing-box 1.10.1)', 'SFI/1.10.1 (iOS 18.0; sing-box 1.10.1)', 'SFM/1.10.1 (macOS 15)', 'SFT/1.10.1', 'Shadowrocket/2070 CFNetwork/1568.100.1 Darwin/24.0.0 iPhone14,5',
    'sing-box 1.11.0', 'Streisand/1.6.37 (iOS)', 'Throne/1.0.5', 'V2Box/8.20', 'v2rayN/7.0.4', 'v2rayNG/1.9.30', 'Stash/2.6.1 Clash/1.9.0', 'Surge iOS/3048', 'Loon/797', 'Quantumult%20X/1.0.31',
    'okhttp/4.12.0', 'Dalvik/2.1.0 (Linux; U; Android 14; SM-S918B Build/UP1A)', 'Go-http-client/2.0', 'CFNetwork/1568 Darwin/24.0.0', 'curl/8.5.0', 'Wget/1.21.4',
    'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1',
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36 Edg/130.0', 'Mozilla/5.0 (Linux; Android 14) Chrome/130 YaBrowser/24.10 Mobile Safari/537.36',
    'TelegramBot (like TwitterBot)', 'WhatsApp/2.24.1', ''];
$bad = array_values(array_filter($clients, 'junk_set_bots_match'));
t_eq('боты не трогают ни одного клиента и браузер (' . count($clients) . ')', $bad, []);
$tails = ['', '/clash', '/mihomo', '/json', '/v2ray-json', '/xray-json', '/singbox', '/sing-box', '/stash', '/base64', '/outline', '/info'];
$bad = [];
foreach (['AbCdEfGhIjKlMnOp', 'sub/AbCdEfGhIjKlMnOp', 'Ab_Cd-Ef12', 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.Sfl-KxwRJSMeKKF2QT4fwpMeJf36POk6yJV_adQssw5c'] as $tok) {
    foreach ($tails as $t) if (junk_set_paths_match($tok . $t)) $bad[] = $tok . $t;
}
t_eq('набор путей не трогает ни одну ссылку подписки с хвостом клиента', $bad, []);
t_eq('путь: нормализация', [junk_path_rule_norm('/wp-admin/*'), junk_path_rule_norm('*'), junk_path_rule_norm('a*b'), junk_path_rule_norm('a b')], ['wp-admin/*', '', '', '']);
t_ok('путь: префикс и точное', junk_path_rule_match('wp-admin/x.php', 'wp-admin/*') && junk_path_rule_match('Admin/Config.json', 'admin/config.json') && !junk_path_rule_match('admin/config.json2', 'admin/config.json'));

// Правила и шлюз.
t_ok('без правил шлюз пропускает всё', junk_gate('.env', 'zgrab') === null);
$e = '';
t_ok('правило на служебный адрес страницы подписки не создаётся', junk_rule_add('path', 'assets/*', 'e404', 'cut', 0, $e) === false && strpos($e, 'странице подписки') !== false);
t_ok('правил по IP больше нет', junk_rule_add('ip', '1.2.3.4', 'e404', 'cut', 0, $e) === false);
t_ok('правило на путь', junk_rule_add('path', '/admin/config.json', 'e404', 'cut', 0, $e) === true, $e);
$g = junk_gate('admin/config.json', 'curl/8');
t_ok('путь режется', $g && $g['mode'] === 'cut' && $g['kind'] === 'path');
t_ok('правило по User-Agent в режиме «только считать»', junk_rule_add('ua', 'python-requests', '403', 'dry', 0, $e) === true);
$g = junk_gate('whatever', 'python-requests/2.31.0');
t_ok('UA: только считает', $g && $g['mode'] === 'dry');
$g = junk_gate('admin/config.json', 'python-requests/2.31.0');
t_ok('совпали оба — режущее важнее', $g && $g['mode'] === 'cut');
t_ok('ассеты не трогает даже правило по UA', junk_gate('assets/.app-config-v2.json', 'python-requests/2.31.0') === null);

db()->prepare("INSERT INTO request_log (ip, short_uuid, path, user_agent, decision) VALUES ('5.5.5.5', 'LiveTok1234567', 'LiveTok1234567', 'Happ', 'normal')")->execute();
t_ok('правило на User-Agent клиента', junk_rule_add('ua', 'Happ', 'copy404', 'cut', 0, $e) === true);
t_ok('UA: незнакомая ссылка режется', ($g = junk_gate('Unknown123456', 'Happ/3.6')) && $g['kind'] === 'ua');
t_ok('UA: живая ссылка проходит', junk_gate('LiveTok1234567/clash', 'Happ/3.6') === null);
t_ok('UA: живая ссылка под префиксом origin проходит', junk_gate('sub/LiveTok1234567', 'Happ/3.6') === null);
set_setting('junk_protect_live', '0');
t_ok('UA: без защиты живых ссылок — режется', (bool) junk_gate('LiveTok1234567', 'Happ/3.6'));
t_ok('правило на путь живой ссылки', junk_rule_add('path', 'LiveTok1234567', 'e404', 'cut', 0, $e) === true);
t_ok('путь живой ссылки не режется никогда', ($g = junk_gate('LiveTok1234567', 'Happ')) === null || $g['kind'] !== 'path');
set_setting('junk_protect_live', '1');

junk_set_save('paths', true, 'cut', 'e404');
t_ok('набор путей включён — режет', ($g = junk_gate('wp-login.php', 'x')) && $g['kind'] === 'set' && $g['mode'] === 'cut');
junk_set_save('paths', false, 'cut', 'e404');
t_ok('набор путей выключен — пропускает', junk_gate('wp-login.php', 'x') === null);

t_ok('правило со сроком', junk_rule_add('path', 'tmp/*', 'e404', 'cut', 3600, $e) === true);
db()->exec("UPDATE junk_rules SET until_ts = " . (time() - 5) . " WHERE val = 'tmp/*'");
junk_rules_rebuild();
t_ok('истёкшее правило не действует', junk_gate('tmp/x', 'x') === null);
junk_prune(db());
t_eq('истёкшее правило убрано уборкой', (int) db()->query("SELECT COUNT(*) FROM junk_rules WHERE val = 'tmp/*'")->fetchColumn(), 0);

foreach (junk_rules_all() as $r) if ($r['kind'] !== 'set') junk_rule_del((int) $r['id']);
t_eq('правила удалены', count(array_filter(junk_rules_all(), static function ($r) { return $r['kind'] !== 'set'; })), 0);

// Живой прокси: мок-панель, режим «Панель».
$state = $tt_root . '/data/mock_state.json';
$mlog  = $tt_root . '/data/mock.log';
file_put_contents($state, json_encode(['users' => ['AbCdEfGhIjKlMnOp' => ['status' => 'ACTIVE']]]));
@unlink($mlog);
$panel = t_serve($tt_root . '/tools', $tt_root . '/tools/mock_panel.php', ['MOCK_STATE' => $state, 'MOCK_LOG' => $mlog]);
t_settings(['sub_source' => 'panel', 'remnawave_url' => $panel, 'remnawave_api_key' => 'test-token', 'geoip_auto' => '0']);
$mw = t_serve($tt_root, $tt_root . '/tools/tests/_router.php', [], ['opcache.enable' => '0']);
$hits = static function (string $p) use ($mlog): int { return substr_count((string) @file_get_contents($mlog), 'GET /api/sub/' . $p . "\n"); };
$jlog = static function (): array { return db()->query('SELECT * FROM junk_log ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: []; };
$ua = ['User-Agent' => 'ClashMeta/1.19.0 mihomo'];

[$c] = t_http('GET', $mw . '/AbCdEfGhIjKlMnOp', $ua + ['X-Real-IP' => '10.1.1.1']);
t_eq('живая подписка: 200', $c, 200);
t_eq('живая подписка в мусор не пишется', (int) db()->query("SELECT COUNT(*) FROM junk_log WHERE path = 'AbCdEfGhIjKlMnOp'")->fetchColumn(), 0);

[$c, , $b] = t_http('GET', $mw . '/.env', ['User-Agent' => 'zgrab/0.x', 'X-Real-IP' => '45.155.205.233']);
$j = $jlog();
t_ok('мусор без правил уходит в панель и пишется', $c === 404 && $hits('.env') === 1 && ($j['path'] ?? '') === '.env' && $j['kind'] === 'pass' && (int) $j['code'] === 404 && $j['ip'] === '45.155.205.233' && $j['ua'] === 'zgrab/0.x');
$copy = $b;
t_ok('мусор пишется и без доп. конфигов и второй подписки', (int) db()->query("SELECT hits FROM junk_hits WHERE path = '.env'")->fetchColumn() === 1);
t_ok('копия 404 сохранена', (junk_404_copy()['body'] ?? null) === $copy);

junk_set_save('paths', true, 'cut', 'copy404');
[$c, $h, $b] = t_http('GET', $mw . '/.env', ['User-Agent' => 'zgrab/0.x', 'X-Real-IP' => '45.155.205.233']);
$j = $jlog();
t_ok('набор режет: панель не спрошена', $hits('.env') === 1);
t_ok('ответ — копия 404 панели', $c === 404 && $b === $copy && strpos($h['content-type'] ?? '', 'application/json') === 0, $c . ' ' . substr($b, 0, 80));
t_ok('отрезанный записан с правилом', $j['kind'] === 'cut' && $j['rule'] === 'Сканерские пути' && $j['resp'] === 'copy404');
set_setting('mask_notfound', '1');
[$c, , $b] = t_http('GET', $mw . '/wp-login.php', ['User-Agent' => 'zgrab/0.x', 'X-Real-IP' => '45.155.205.233']);
t_ok('«маскировать 404» — пустой 404', $c === 404 && $b === '');
set_setting('mask_notfound', '0');
junk_set_save('paths', true, 'cut', '403');
[$c] = t_http('GET', $mw . '/xmlrpc.php', ['User-Agent' => 'zgrab/0.x', 'X-Real-IP' => '45.155.205.233']);
t_eq('ответ 403', $c, 403);

junk_rule_add('ua', 'Mozilla', 'e404', 'cut', 0, $e);
junk_rule_add('ua', 'ClashMeta', 'e404', 'cut', 0, $e);
$n0 = $hits('assets/.app-config-v2.json');
[$c] = t_http('GET', $mw . '/assets/.app-config-v2.json', ['User-Agent' => 'Mozilla/5.0 Chrome/130']);
t_ok('конфиг страницы подписки идёт в панель при правиле по UA браузера', $hits('assets/.app-config-v2.json') === $n0 + 1, (string) $c);
[$c] = t_http('GET', $mw . '/AbCdEfGhIjKlMnOp', $ua + ['X-Real-IP' => '10.1.1.1']);
t_eq('живая ссылка при правиле на UA клиента проходит', $c, 200);
$n0 = $hits('ZzUnknownShort01');
[$c] = t_http('GET', $mw . '/ZzUnknownShort01', $ua + ['X-Real-IP' => '10.1.1.1']);
t_ok('незнакомая ссылка при правиле на UA режется', $c === 404 && $hits('ZzUnknownShort01') === $n0);
foreach (junk_rules_all() as $r) if ($r['kind'] !== 'set') junk_rule_del((int) $r['id']);

junk_rule_add('path', 'api/v1/*', 'e404', 'dry', 0, $e);
[$c] = t_http('GET', $mw . '/api/v1/users', ['User-Agent' => 'curl/8', 'X-Real-IP' => '185.224.128.17']);
$j = $jlog();
t_ok('«только считать»: запрос ушёл в панель, отмечено «отрезал бы»', $hits('api/v1/users') === 1 && $j['kind'] === 'dry' && $j['path'] === 'api/v1/users');
foreach (junk_rules_all() as $r) if ($r['kind'] !== 'set') junk_rule_del((int) $r['id']);

junk_set_save('paths', false, 'dry', 'copy404');
foreach (['a.txt', 'b.txt', 'c.txt'] as $f) t_http('GET', $mw . '/' . $f, ['User-Agent' => 'scan', 'X-Real-IP' => '92.118.39.56']);
t_eq('частый IP не блокируется: правил по IP нет', count(array_filter(junk_rules_all(), static function ($r) { return $r['kind'] !== 'set'; })), 0);

// Режим «Панель» со страницей подписки: браузерный мусор считается и режется до страницы подписки.
$splog = $tt_root . '/data/subpage.log';
file_put_contents($tt_root . '/data/subpage.php', '<?php file_put_contents(' . var_export($splog, true) . ', ($_SERVER["REQUEST_URI"] ?? "") . "\n", FILE_APPEND); http_response_code(404); echo "subpage 404";');
$sp = t_serve($tt_root . '/data', $tt_root . '/data/subpage.php');
t_settings(['subpage_external_url' => $sp]);
$sphits = static function (string $p) use ($splog): int { return substr_count((string) @file_get_contents($splog), '/' . $p . "\n"); };
$chrome = ['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36', 'X-Real-IP' => '45.155.205.233'];
[$c] = t_http('GET', $mw . '/wp-config.php', $chrome);
$j = $jlog();
t_ok('браузерный мусор без правил идёт на страницу подписки и пишется', $sphits('wp-config.php') === 1 && $j['path'] === 'wp-config.php' && $j['kind'] === 'pass' && (int) $j['code'] === 404);
junk_set_save('paths', true, 'cut', '403');
[$c] = t_http('GET', $mw . '/wp-config.php', $chrome);
t_ok('браузерный мусор режется набором до страницы подписки', $c === 403 && $sphits('wp-config.php') === 1 && $jlog()['kind'] === 'cut');
junk_rule_add('ua', 'Mozilla', 'e404', 'cut', 0, $e);
[$c] = t_http('GET', $mw . '/AbCdEfGhIjKlMnOp', $chrome);
t_ok('страница живой ссылки в браузере открывается при правиле на Mozilla', $sphits('AbCdEfGhIjKlMnOp') === 1);
[$c] = t_http('GET', $mw . '/assets/index-Ab12.js', $chrome);
t_ok('ассеты страницы подписки идут на неё при правиле на Mozilla', $sphits('assets/index-Ab12.js') === 1);
[$c] = t_http('GET', $mw . '/favicon.ico', $chrome);
t_ok('фавикон идёт на страницу подписки', $sphits('favicon.ico') === 1);
foreach (junk_rules_all() as $r) if ($r['kind'] !== 'set') junk_rule_del((int) $r['id']);
junk_set_save('paths', false, 'dry', 'copy404');
t_settings(['subpage_external_url' => '']);

// Защищённый канал c1 правила не трогают, даже без защиты живых ссылок.
if (extension_loaded('sodium')) {
    require __DIR__ . '/_c1.php';
    t_settings(['chan_enabled' => '1']);
    t_ok('c1: индекс меток собран', chan_index_rebuild(true) === true);
    junk_set_save('paths', true, 'cut', '403');
    junk_set_save('bots', true, 'cut', '403');
    junk_rule_add('ua', 'ClashMeta', '403', 'cut', 0, $e);
    junk_rule_add('ua', 'curl', '403', 'cut', 0, $e);
    set_setting('junk_protect_live', '0');
    [$cpath, $ctx] = c1_request('AbCdEfGhIjKlMnOp', null, ['hwid' => 'hw-junk-1', 'os' => 'Windows', 'osv' => '10', 'model' => 'PC', 'ua' => 'ClashMeta/1.19.0 mihomo', 'acc' => '*/*']);
    [$c, , $b] = t_http('GET', $mw . $cpath, ['User-Agent' => 'curl/8.5.0']);
    $r = c1_open($ctx, $b);
    t_ok('c1: подписка приходит при правилах на UA снаружи и внутри', $c === 200 && is_array($r) && ($r['st'] ?? 0) === 200 && strpos((string) ($r['body'] ?? ''), 'proxies:') === 0, 'код ' . $c . ' внутри ' . json_encode(is_array($r) ? [$r['st'] ?? null, substr((string) ($r['body'] ?? ''), 0, 40)] : null));
    set_setting('junk_protect_live', '1');
    foreach (junk_rules_all() as $r) if ($r['kind'] !== 'set') junk_rule_del((int) $r['id']);
    junk_set_save('paths', false, 'dry', 'copy404');
    junk_set_save('bots', false, 'dry', '403');
}

$ip = db()->query("SELECT * FROM junk_ip WHERE ip = '45.155.205.233'")->fetch(PDO::FETCH_ASSOC);
t_ok('сводка по IP: запросы и разные пути', (int) $ip['hits'] >= 4 && (int) $ip['paths'] >= 3);
$v = junk_view();
t_ok('экран: сводка за сутки', $v['kpi']['n'] >= 9 && $v['kpi']['cut'] >= 4 && $v['kpi']['ips'] >= 3 && count($v['hours']) === 24);

// Экран в админке.
$jar = t_admin_login($mw);
[$c, , $p] = t_http('GET', $mw . '/admin/index.php?tab=reqlog&view=junk', [], null, $jar);
t_ok('экран «Мусор» открывается', $c === 200 && strpos($p, 'id="jkReq"') !== false && strpos($p, '</html>') !== false, 'код ' . $c);
t_ok('экран: строки запросов, путей и IP', strpos($p, '<code class="ip">45.155.205.233</code>') !== false && strpos($p, '<code>/.env</code>') !== false);
t_ok('экран: нет блокировки по IP', strpos($p, 'Заблокировать') === false && strpos($p, 'Автоблок') === false && strpos($p, 'data-t="ip"') === false);
preg_match('~name="csrf" value="([0-9a-f]+)"~', $p, $m);
$post = static function (array $f) use ($mw, $jar, $m) { return t_http('POST', $mw . '/admin/index.php', ['content-type' => 'application/x-www-form-urlencoded'], http_build_query(['csrf' => $m[1]] + $f), $jar); };
$post(['action' => 'junk_rule_add', 'kind' => 'ua', 'val' => 'masscan', 'resp' => '403', 'mode' => 'cut', 'ttl' => '86400', 'ret' => 'req']);
$r = array_values(array_filter(junk_rules_all(), static function ($r) { return $r['val'] === 'masscan'; }));
t_ok('экран: правило добавляется', count($r) === 1 && $r[0]['resp'] === '403' && (int) $r[0]['until_ts'] > time() + 86000);
$post(['action' => 'junk_rule_mode', 'id' => (string) $r[0]['id']]);
t_eq('экран: режим переключается', (string) db()->query('SELECT mode FROM junk_rules WHERE id = ' . (int) $r[0]['id'])->fetchColumn(), 'dry');
$post(['action' => 'junk_rule_del', 'id' => (string) $r[0]['id']]);
t_eq('экран: правило удаляется', (int) db()->query('SELECT COUNT(*) FROM junk_rules WHERE id = ' . (int) $r[0]['id'])->fetchColumn(), 0);
$post(['action' => 'junk_cfg', 'xhr' => '1', 'set_paths_on' => '1', 'set_paths_mode' => 'cut', 'set_paths_resp' => 'zzz', 'set_bots_mode' => 'dry', 'set_bots_resp' => '403']);
unset($GLOBALS['submw_settings_cache']);
t_ok('экран: настройки сохраняются, мусор в значениях отвергается', (int) junk_set_row('paths')['active'] === 1 && junk_set_row('paths')['resp'] === 'copy404' && (int) junk_set_row('bots')['active'] === 0 && !junk_live_protect());
set_setting('junk_protect_live', '1');
[$c] = t_http('POST', $mw . '/admin/index.php', ['content-type' => 'application/x-www-form-urlencoded'], http_build_query(['csrf' => 'bad', 'action' => 'junk_clear']), $jar);
t_ok('экран: без CSRF ничего не чистится', (int) db()->query('SELECT COUNT(*) FROM junk_log')->fetchColumn() > 0);
$post(['action' => 'junk_clear']);
t_ok('экран: статистика очищается, правила остаются', (int) db()->query('SELECT COUNT(*) FROM junk_log')->fetchColumn() === 0 && junk_set_row('paths') !== null);
[$c, , $p] = t_http('GET', $mw . '/admin/index.php?tab=reqlog&view=junk', [], null, $jar);
t_ok('экран пустой открывается', $c === 200 && strpos($p, 'Пока пусто') !== false);
[$c, , $p] = t_http('GET', $mw . '/admin/index.php?tab=reqlog', [], null, $jar);
t_ok('«Лог запросов» ведёт на «Мусор»', $c === 200 && strpos($p, 'view=junk') !== false);

t_done();
