<?php
declare(strict_types=1);

require __DIR__ . '/_boot.php';

$state = $tt_root . '/data/mock_state.json';
$mlog  = $tt_root . '/data/mock.log';
$N1 = '11111111-1111-4111-8111-111111111111';
$N2 = '22222222-2222-4222-8222-222222222222';
$GB = 1073741824;
$mock = static function (array $usage, array $extra = []) use ($state, $N1, $N2) {
    file_put_contents($state, json_encode([
        'users' => ['AbCdEfGhIjKlMnOp' => ['status' => 'ACTIVE']],
        'nodes' => [$N1 => ['name' => 'Amsterdam', 'cc' => 'NL'], $N2 => ['name' => 'Frankfurt', 'cc' => 'DE', 'color' => 'bad']],
        'hosts' => [['remark' => 'NL · WS', 'address' => 'ams.example.net', 'nodes' => [$N1]], ['remark' => 'Германия', 'address' => 'fra.example.net', 'nodes' => []]],
        'usage' => $usage,
    ] + $extra));
};
$mock(['1' => [$N1 => [$GB, 0, 2 * $GB], $N2 => [0, 512 * 1048576]]]);
$panel = t_serve($tt_root . '/tools', $tt_root . '/tools/mock_panel.php', ['MOCK_STATE' => $state, 'MOCK_LOG' => $mlog]);
t_settings(['sub_source' => 'panel', 'remnawave_url' => $panel, 'remnawave_api_key' => 'test-token', 'geoip_auto' => '0']);
set_setting('update_state', json_encode(['checked_at' => time()]));
$mw = t_serve($tt_root, $tt_root . '/tools/tests/_router.php');
$logHas = static fn(string $needle) => strpos((string) @file_get_contents($mlog), $needle) !== false;

t_eq('период вне списка — 30 дней', usage_days(12), 30);
t_eq('каталог прав знает про трафик', in_array(usage_scope(), array_column(tokscope_catalog(), 's'), true), true);

$e = '';
$d = usage_fetch('1', 30, $e);
t_ok('30 дней: ровно 30 суток, период и время ответа', is_array($d) && count($d['days']) === 30 && count($d['tot']) === 30 && $d['d'] === 30 && $d['ts'] > 0, $e);
t_ok('запрос к панели с датами start/end', $logHas('USAGE 1 ' . gmdate('Y-m-d', time() - 29 * 86400) . ' ' . gmdate('Y-m-d')));
t_eq('серверы отсортированы по объёму, сверху больший', array_column($d['series'], 'node'), [$N1, $N2]);
t_eq('итог по дням — сумма по серверам', $d['tot'][0] + $d['tot'][1], 1 * $GB + 512 * 1048576);
t_eq('сумма за период совпадает с итогом', $d['sum'], array_sum($d['tot']));
t_eq('имена хостов панели у ноды, нода без хоста — пусто', [$d['series'][0]['h'], $d['series'][1]['h']], [['NL · WS'], []]);
t_eq('имя и страна ноды', [$d['series'][1]['name'], $d['series'][1]['cc']], ['Frankfurt', 'DE']);
t_eq('цвет панели берётся только валидным hex', [$d['series'][0]['c'], $d['series'][1]['c']], ['#888888', '']);

$d = usage_fetch('1', 7, $e);
t_ok('7 дней', is_array($d) && count($d['days']) === 7 && $d['d'] === 7, $e);
t_ok('дни — даты', (bool) preg_match('~^\d{4}-\d{2}-\d{2}$~', $d['days'][0]));
$d = usage_fetch('00000000-0000-4000-8000-000000000000', 90, $e);
t_ok('нет данных — пустые серверы и нулевая сумма, 90 суток', is_array($d) && $d['series'] === [] && $d['sum'] === 0 && count($d['days']) === 90, $e);
t_ok('пустой идентификатор — ошибка без запроса', usage_fetch('', 30, $e) === null && $e !== '');

$mock([], ['no_usage' => 1]);
t_ok('панель без эндпоинта — понятная ошибка', usage_fetch('1', 30, $e) === null && strpos($e, 'не отдаёт статистику') !== false, $e);
$mock(['1' => [$N1 => [$GB]]]);

// Админка: вкладка и ajax
$jar = t_admin_login($mw);
[$c, , $page] = t_http('GET', $mw . '/admin/index.php?tab=users', [], null, $jar);
t_ok('конфиг вкладки с правом и периодами', $c === 200 && (bool) preg_match('~"usage":\{"sc":"bandwidth-stats:user-usage","opts":\[7,30,90\]\}~', $page));
[$c, , $b] = t_http('GET', $mw . '/admin/index.php?ajax=usage&rv=1&d=7', [], null, $jar);
$j = json_decode($b, true);
t_ok('ajax usage: дни, серверы, сумма', $c === 200 && !empty($j['ok']) && count($j['days']) === 7 && count($j['series']) === 1 && $j['sum'] === 7 * $GB, $b);
[$c, , $b] = t_http('GET', $mw . '/admin/index.php?ajax=usage&rv=&d=7', [], null, $jar);
$j = json_decode($b, true);
t_ok('ajax usage без идентификатора — ошибка', $c === 200 && empty($j['ok']) && $j['error'] !== '', $b);
[, , $b2] = t_http('GET', $mw . '/admin/index.php?ajax=usage&rv=1&d=7');
t_ok('ajax без входа не отдаёт данные', strpos($b2, '"days"') === false);

t_done();
