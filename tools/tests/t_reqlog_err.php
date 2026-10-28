<?php
declare(strict_types=1);

require __DIR__ . '/_boot.php';
require __DIR__ . '/_c1.php';

// Сбои панели и защищённого канала в логе запросов: пишутся только по известным подпискам.

$state = $tt_root . '/data/mock_state.json';
$mlog  = $tt_root . '/data/mock.log';
$users = ['AbCdEfGhIjKlMnOp' => ['status' => 'ACTIVE'], 'QrStUvWxYz012345' => ['status' => 'ACTIVE']];
$put   = static function (array $u) use ($state): void { file_put_contents($state, json_encode(['users' => $u])); };
$put($users);
@unlink($mlog);

$panel = t_serve($tt_root . '/tools', $tt_root . '/tools/mock_panel.php', ['MOCK_STATE' => $state, 'MOCK_LOG' => $mlog]);
t_settings(['sub_source' => 'panel', 'remnawave_url' => $panel, 'remnawave_api_key' => 'test-token', 'chan_enabled' => '1', 'geoip_auto' => '0', 'proxy_timeout' => '3']);
$sodium = extension_loaded('sodium');
if ($sodium) t_ok('индекс меток собран с мок-панели', chan_index_rebuild(true) === true);
$mw = t_serve($tt_root, $tt_root . '/tools/tests/_router.php');
$ua = 'ClashMeta/1.19.0 mihomo';

$rows = static function (string $short): array {
    $st = db()->prepare('SELECT decision, meta FROM request_log WHERE short_uuid = ? ORDER BY id');
    $st->execute([$short]);
    $out = [];
    foreach ($st as $r) $out[] = [(string) $r['decision'], json_decode((string) $r['meta'], true) ?: []];
    return $out;
};
$last  = static function (string $short) use ($rows): array { $r = $rows($short); return $r ? end($r) : ['', []]; };
$count = static fn(): int => (int) db()->query('SELECT COUNT(*) FROM request_log')->fetchColumn();

// Незнакомые токены: панель отвечает 404/502, строк нет.
[$c] = t_http('GET', $mw . '/NoSuchShortUuid0', ['User-Agent' => $ua]);
t_eq('незнакомый токен, панель 404: код клиенту', $c, 404);
$put($users + ['Zz9Yy8Xx7Ww6Vv5U' => ['status' => 'ACTIVE', 'mode' => 'apidown']]);
[$c, , $b] = t_http('GET', $mw . '/Zz9Yy8Xx7Ww6Vv5U', ['User-Agent' => $ua]);
t_eq('незнакомый токен, панель 502: код проброшен', $c, 502);
t_eq('незнакомые токены: лог пуст', $count(), 0);

// Известный по успешной выдаче.
[$c] = t_http('GET', $mw . '/AbCdEfGhIjKlMnOp', ['User-Agent' => $ua, 'x-hwid' => 'hw-1']);
t_eq('успешная выдача: normal', $last('AbCdEfGhIjKlMnOp')[0], 'normal');
t_ok('успешная выдача: chan=0 в meta не пишется', !isset($last('AbCdEfGhIjKlMnOp')[1]['chan']));

$put(['AbCdEfGhIjKlMnOp' => ['status' => 'ACTIVE', 'mode' => 'apidown']] + $users);
[$c, , $b] = t_http('GET', $mw . '/AbCdEfGhIjKlMnOp', ['User-Agent' => $ua, 'x-hwid' => 'hw-1']);
t_eq('панель 502 по известному: клиенту 502, как раньше', $c, 502);
t_eq('панель 502 по известному: тело панели проброшено', $b, 'Bad Gateway');
[$d, $m] = $last('AbCdEfGhIjKlMnOp');
t_eq('панель 502 по известному: в логе error, а не normal', $d, 'error');
t_eq('панель 502 по известному: код и причина в meta', $m['er'] ?? null, ['c' => 502, 'r' => 'http']);
t_ok('панель 502 по известному: устройство (hwid) записано', (int) db()->query("SELECT COUNT(*) FROM request_log WHERE decision = 'error' AND hwid = 'hw-1'")->fetchColumn() === 1);

$put(['AbCdEfGhIjKlMnOp' => ['status' => 'ACTIVE', 'mode' => '404']] + $users);
[$c] = t_http('GET', $mw . '/AbCdEfGhIjKlMnOp', ['User-Agent' => $ua]);
t_eq('панель 404 без маскировки: код клиенту', $c, 404);
t_eq('панель 404 без маскировки: error с кодом', $last('AbCdEfGhIjKlMnOp')[1]['er'] ?? null, ['c' => 404, 'r' => 'http']);
t_settings(['mask_notfound' => '1']);
$n = count($rows('AbCdEfGhIjKlMnOp'));
[$c] = t_http('GET', $mw . '/AbCdEfGhIjKlMnOp', ['User-Agent' => $ua]);
t_ok('панель 404 с маскировкой: error с кодом', $c === 404 && count($rows('AbCdEfGhIjKlMnOp')) === $n + 1 && ($last('AbCdEfGhIjKlMnOp')[1]['er'] ?? null) === ['c' => 404, 'r' => 'http']);
t_settings(['mask_notfound' => '0']);

// Известный только по индексу меток канала (ни одного запроса ещё не было).
$put(['QrStUvWxYz012345' => ['status' => 'ACTIVE', 'mode' => 'apidown']] + $users);
[$c] = t_http('GET', $mw . '/QrStUvWxYz012345', ['User-Agent' => $ua]);
if ($sodium) t_eq('панель 502 по токену из индекса меток: error', $last('QrStUvWxYz012345')[0], 'error');
$put($users);

// Панель недоступна: отказ соединения.
t_settings(['remnawave_url' => 'http://127.0.0.1:1']);
$n = $count();
[$c] = t_http('GET', $mw . '/AbCdEfGhIjKlMnOp', ['User-Agent' => $ua]);
t_eq('панель недоступна: клиенту 502', $c, 502);
[$d, $m] = $last('AbCdEfGhIjKlMnOp');
t_ok('панель недоступна: error с причиной connect', $d === 'error' && ($m['er'] ?? null) === ['c' => 0, 'r' => 'connect'], json_encode($m));
[$c] = t_http('GET', $mw . '/NoSuchShortUuid0', ['User-Agent' => $ua]);
t_eq('панель недоступна, незнакомый токен: строки нет', $count(), $n + 1);
t_settings(['remnawave_url' => $panel]);

// Давняя выдача не делает токен известным: строки удалённых в панели не копятся.
db()->exec("UPDATE request_log SET ts = '2000-01-01 00:00:00' WHERE short_uuid = 'AbCdEfGhIjKlMnOp'");
if ($sodium) db()->exec("DELETE FROM chan_kid WHERE short_uuid = 'AbCdEfGhIjKlMnOp'");
$n = $count();
$put(['AbCdEfGhIjKlMnOp' => ['status' => 'ACTIVE', 'mode' => '404']] + $users);
[$c] = t_http('GET', $mw . '/AbCdEfGhIjKlMnOp', ['User-Agent' => $ua]);
t_ok('выдача старше 30 дней: токен незнаком, строки нет', $c === 404 && $count() === $n);
db()->exec("UPDATE request_log SET ts = CURRENT_TIMESTAMP WHERE short_uuid = 'AbCdEfGhIjKlMnOp'");
$put($users);
if ($sodium) chan_index_rebuild(true);

// Браузер на странице при лежащей панели — только вместе с логом страниц.
$put(['AbCdEfGhIjKlMnOp' => ['status' => 'ACTIVE', 'mode' => 'apidown']] + $users);
$n = $count();
[$c] = t_http('GET', $mw . '/AbCdEfGhIjKlMnOp', ['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/120.0', 'Accept' => 'text/html']);
t_ok('браузер, панель 502, лог страниц выключен: строки нет', $c === 502 && $count() === $n);
t_settings(['reqlog_log_pages' => '1']);
[$c] = t_http('GET', $mw . '/AbCdEfGhIjKlMnOp', ['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/120.0', 'Accept' => 'text/html']);
t_ok('браузер, панель 502, лог страниц включён: error', $c === 502 && $count() === $n + 1 && $last('AbCdEfGhIjKlMnOp')[0] === 'error');
t_settings(['reqlog_log_pages' => '0']);
$put($users);

// Сводки: ошибки не считаются обновлением подписки, но считаются в сводке ошибок.
t_eq('KPI «обновили подписку»: только успешные', (int) reqlog_today_stats()['today_users'], 1);
t_ok('сводка: число ошибок за сутки', (int) reqlog_overview()['errors'] >= 4);

if (!$sodium) {
    echo "  пропуск: канал c1 — в этой сборке PHP нет sodium\n";
    t_done();
}

$fields = ['hwid' => 'hw-chan-1', 'os' => 'Windows', 'osv' => '10', 'model' => 'PC', 'ua' => $ua, 'acc' => '*/*'];
[$path, $ctx] = c1_request('AbCdEfGhIjKlMnOp', null, $fields);
[$c, , $b] = t_http('GET', $mw . $path);
$r = c1_open($ctx, $b);
t_eq('c1: успех внутри', $r['st'] ?? null, 200);
[$d, $m] = $last('AbCdEfGhIjKlMnOp');
t_ok('c1: успех помечен каналом', $d === 'normal' && (int) ($m['chan'] ?? 0) === 1, json_encode($m));

$n = count($rows('AbCdEfGhIjKlMnOp'));
[$c] = t_http('GET', $mw . $path);
[$d, $m] = $last('AbCdEfGhIjKlMnOp');
t_ok('c1 повтор: снаружи 404, в логе error chan:replay', $c === 404 && count($rows('AbCdEfGhIjKlMnOp')) === $n + 1 && $d === 'error' && ($m['er']['r'] ?? '') === 'chan:replay' && (int) ($m['chan'] ?? 0) === 1, json_encode($m));
t_ok('c1 повтор: UA из конверта', (string) db()->query("SELECT user_agent FROM request_log ORDER BY id DESC LIMIT 1")->fetchColumn() === $ua);
t_ok('c1 повтор: путь без блоба', str_ends_with((string) db()->query("SELECT path FROM request_log ORDER BY id DESC LIMIT 1")->fetchColumn(), '/…'));

[$path] = c1_request('AbCdEfGhIjKlMnOp', random_bytes(32), $fields);
$n = count($rows('AbCdEfGhIjKlMnOp'));
[$c] = t_http('GET', $mw . $path);
[$d, $m] = $last('AbCdEfGhIjKlMnOp');
t_ok('c1 чужой ключ прослойки: error chan:spid', $c === 404 && count($rows('AbCdEfGhIjKlMnOp')) === $n + 1 && ($m['er']['r'] ?? '') === 'chan:spid', json_encode($m));
$lr = db()->query('SELECT user_agent, is_app FROM request_log ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: [];
t_ok('c1 чужой ключ прослойки: внешний UA не записан, строка не браузерная', (string) ($lr['user_agent'] ?? '') === '' && (int) ($lr['is_app'] ?? 0) === 1, json_encode($lr));

$n = $count();
[$path] = c1_request('NotInPanelAtAll0', null, $fields);
[$c] = t_http('GET', $mw . $path);
t_eq('c1 с чужой подпиской: 404', $c, 404);
t_eq('c1 с чужой подпиской: строки нет', $count(), $n);
t_eq('c1 с чужой подпиской: «c1» не стал подпиской', count($rows('c1')), 0);

$put(['AbCdEfGhIjKlMnOp' => ['status' => 'ACTIVE', 'mode' => 'apidown']] + $users);
[$path, $ctx] = c1_request('AbCdEfGhIjKlMnOp', null, $fields);
[$c, , $b] = t_http('GET', $mw . $path);
$r = c1_open($ctx, $b);
[$d, $m] = $last('AbCdEfGhIjKlMnOp');
t_ok('c1, панель 502: снаружи 200, внутри 502', $c === 200 && ($r['st'] ?? null) === 502);
t_ok('c1, панель 502: error с каналом и кодом', $d === 'error' && ($m['er'] ?? null) === ['c' => 502, 'r' => 'http'] && (int) ($m['chan'] ?? 0) === 1, json_encode($m));
$put($users);

// Админка: кнопка «Только ошибки», метки причин, счётчик в автообновлении.
$jar = t_admin_login($mw);
[$c, , $page] = t_http('GET', $mw . '/admin/index.php?tab=reqlog', [], null, $jar);
t_ok('лог: кнопка «Только ошибки» со счётчиком', $c === 200 && preg_match('~class="btn ghost rl-err has" href="\?tab=reqlog&amp;rl_dec=error"~', $page) === 1 && preg_match('~id="rlErrN">(\d+)</b>~', $page, $mm) === 1 && (int) $mm[1] >= 4, substr($page, 0, 0));
[$c, , $page] = t_http("GET", $mw . "/admin/index.php?tab=reqlog&rl_dec=error&rl_hours=0", [], null, $jar);
t_ok('лог, только ошибки: кнопка включена и снимает фильтр', strpos($page, 'class="btn ghost rl-err on has" href="?tab=reqlog&amp;rl_hours=0"') !== false);
t_ok('лог, только ошибки: метки причин в строках', substr_count($page, '<span class="tag-x">502</span>') >= 2 && strpos($page, '<span class="tag-x">сеть</span>') !== false && strpos($page, '<span class="tag-x">канал</span>') !== false);
t_ok('лог, только ошибки: расшифровка и канал в раскрытой строке', strpos($page, 'защищённый канал: повтор запроса') !== false && strpos($page, '<span class="l">Канал</span><span class="v">защищённый (c1)</span>') !== false);
t_ok('лог, только ошибки: normal не показан', strpos($page, '<span class="tag normal">normal</span>') === false);
[$c, , $b] = t_http('GET', $mw . '/admin/index.php?tab=reqlog&ajax=reqlog', [], null, $jar);
$j = json_decode($b, true);
t_ok('лог: счётчик ошибок в ответе автообновления', $c === 200 && isset($j['kpi']['errors']) && (int) $j['kpi']['errors'] >= 4);

t_done();
