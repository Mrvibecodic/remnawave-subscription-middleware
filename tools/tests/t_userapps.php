<?php
declare(strict_types=1);

require __DIR__ . '/_boot.php';
require_once $tt_root . '/lib/connmap.php';
require_once $tt_root . '/lib/userapps.php';

$state = $tt_root . '/data/mock_state.json';
$mlog  = $tt_root . '/data/mock.log';
$uu = static fn(int $i) => sprintf('00000000-0000-4000-8000-%012d', $i);
$dev = static fn(string $hw, string $ua, string $pl = '', string $ov = '', string $m = '', string $upd = '2026-11-10T12:00:00.000Z') => ['hwid' => $hw, 'platform' => $pl, 'osVersion' => $ov, 'deviceModel' => $m, 'userAgent' => $ua, 'createdAt' => '2026-10-01T00:00:00.000Z', 'updatedAt' => $upd];
$base = [
    'users' => ['AbCdEfGhIjKlMnOp' => ['username' => 'anna'], 'SecondUserShort1' => ['username' => 'boris'], 'ThirdUserShort22' => ['username' => 'vera']],
    'hwdev' => [
        $uu(0) => [$dev('h1', 'Happ/3.4.1', 'Android', '15', 'Pixel 8'), $dev('h2', 'Happ/3.2.0', 'iOS', '18.1', 'iPhone 15')],
        $uu(1) => [$dev('h3', 'v2rayNG/1.10.7', 'Android', '14', 'SM-S921B'), $dev('h4', 'FlClashX/v0.4.2 Platform/windows', '', '', ''), $dev('h5', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36', 'Windows', '11', '')],
        $uu(2) => [$dev('h6', 'Happ/3.4.1', 'tvOS', '18', 'Apple TV'), $dev('h7', '', 'macOS', '15.5', 'MacBook Air'), $dev('h8', "Streisand/1.6.64\x07", 'iOS', '17.7', str_repeat('я', 80))],
    ],
];
$put = static function (array $more = []) use ($state, $base) { file_put_contents($state, json_encode($more + $base)); };
$put();
$panel = t_serve($tt_root . '/tools', $tt_root . '/tools/mock_panel.php', ['MOCK_STATE' => $state, 'MOCK_LOG' => $mlog]);
t_settings(['sub_source' => 'panel', 'remnawave_url' => $panel, 'remnawave_api_key' => 'test-token', 'geoip_auto' => '0']);
set_setting('update_state', json_encode(['checked_at' => time()]));
clientver_save_catalog([
    ['k' => 'happ', 'os' => 'android', 'src' => 'man', 'man' => '3.4.1', 'on' => 1],
    ['k' => 'happ', 'os' => 'ios', 'src' => 'man', 'man' => '3.6.0', 'on' => 1],
    ['k' => 'v2rayng', 'os' => '', 'src' => 'man', 'man' => '1.10.8', 'on' => 1],
    ['k' => 'flclashx', 'os' => 'desktop', 'src' => 'man', 'man' => '0.4.2', 'on' => 1],
]);
$calls = static fn(string $ep = '/api/hwid/devices') => substr_count((string) @file_get_contents($mlog), 'GET ' . $ep . "\n");
$age = static function (int $sec) { $c = cmap_cache_get('pref_hwdev'); $c['ts'] -= $sec; if (isset($c['e'])) $c['e'] -= $sec; cmap_cache_put('pref_hwdev', $c); };
$rows = static function (array $v): array {
    $o = [];
    foreach ($v['D'] as $r) $o[] = $v['A'][$r[0]][1] . '|' . $r[1] . '|' . $r[2] . '|' . $r[3] . '|' . $v['U'][$r[4]][0];
    sort($o);
    return $o;
};

// Первое открытие: один обход панели, разбор приложений, платформ, версий и владельцев
$v = uapps_view($e);
t_eq('первое открытие — один запрос списка устройств', [$calls(), $e], [1, '']);
t_eq('приложение, платформа, статус версии, версия, владелец', $rows($v), [
    'FlClashX|pc|0|0.4.2|boris',
    'Happ|android|0|3.4.1|anna',
    'Happ|ios|2|3.2.0|anna',
    'Happ|tv|2|3.4.1|vera',
    'Streisand|ios|3|1.6.64|vera',
    'v2rayNG|android|1|1.10.7|boris',
    '|macos|3||vera',
    '|windows|3||boris',
]);
$byHw = [];
foreach ($v['D'] as $r) $byHw[$r[5]] = $r;
t_eq('устройство: модель, ОС с версией, время последнего запроса подписки', [$byHw['Pixel 8'][6], $byHw['Pixel 8'][7]], ['Android 15', strtotime('2026-11-10T12:00:00.000Z')]);
t_eq('владелец: имя, shortUuid, id', $v['U'][$byHw['Pixel 8'][4]], ['anna', 'AbCdEfGhIjKlMnOp', 1]);
$long = array_values(array_filter($v['D'], static fn($r) => $r[3] === '1.6.64'))[0];
t_ok('длинная модель обрезана, управляющие символы убраны', mb_strlen($long[5]) === 48 && $v['A'][$long[0]][1] === 'Streisand');
t_eq('срок снимка и признак сравнения версий', [$v['ttl'], $v['cv']], [UAPPS_TTL, true]);

// Повторное открытие в пределах срока — панель не трогаем; каталог версий применяется сразу
$v2 = uapps_view($e);
t_eq('повторное открытие — из снимка, без запроса к панели', [$calls(), $v2['ts']], [1, $v['ts']]);
clientver_save_catalog([['k' => 'happ', 'os' => '', 'src' => 'man', 'man' => '3.4.1', 'on' => 1]]);
t_eq('каталог версий изменили — статусы другие, панель не опрашивалась', [$rows(uapps_view($e))[2], $calls()], ['Happ|ios|2|3.2.0|anna', 1]);
set_setting('clientver_enabled', '0');
$v3 = uapps_view($e);
t_eq('сравнение версий выключено — у всех «нет эталона»', [$v3['cv'], array_unique(array_column($v3['D'], 2))], [false, [3]]);
set_setting('clientver_enabled', '1');

// Снимок устарел — следующий обход при открытии; новый пользователь, которого нет в снимке пользователей
$age(UAPPS_TTL + 5);
$more = $base;
$more['users']['FourthUserShort3'] = ['username' => 'gleb'];
$more['hwdev'][$uu(3)] = [$dev('h9', 'v2rayNG/1.10.8', 'Android', '15', 'Redmi Note 13')];
file_put_contents($state, json_encode($more));
$u0 = $calls('/api/users');
$v4 = uapps_view($e);
t_eq('снимок старше срока — новый обход', [$calls(), count($v4['D'])], [2, 9]);
t_eq('владелец новее свежего снимка пользователей — без имени, панель лишний раз не опрашивается', [in_array(['', '', 4], $v4['U'], true), $calls('/api/users') - $u0], [true, 0]);
$age(UAPPS_TTL + 5);
$pu = admin_cache_get('pref_users', PHP_INT_MAX); $pu['ts'] -= CMAP_TTL + 5; admin_cache_put('pref_users', $pu); unset($GLOBALS['submw_pusers_memo']);
$v4 = uapps_view($e);
t_ok('снимок пользователей устарел — имя взято перечитанным списком, один раз', in_array(['gleb', 'FourthUserShort3', 4], $v4['U'], true) && $calls('/api/users') - $u0 === 1);
$age(UAPPS_TTL + 5);
$u1 = $calls('/api/users');
uapps_view($e);
t_eq('все владельцы уже в снимке — список пользователей не перечитывается', $calls('/api/users') - $u1, 0);

// Панель недоступна: остаётся прошлый список, повтор не раньше паузы
$age(UAPPS_TTL + 5);
file_put_contents($state, json_encode(['hwdev_fail' => true] + $more));
$n0 = $calls();
$v5 = uapps_view($e);
t_ok('панель не ответила — прошлый список и ошибка', count($v5['D']) === 9 && $e !== '' && $calls() - $n0 === 1, $e);
uapps_view($e);
t_eq('неудача запомнена — второй раз в панель не идём', [$calls() - $n0, $e !== ''], [1, true]);
$age(PANEL_REF_RETRY + 5);
uapps_view($e);
t_eq('пауза прошла — новая попытка', $calls() - $n0, 2);

// Без снимка и без панели — null и причина
$tt_pdo->exec("DELETE FROM admin_cache WHERE k = 'pref_hwdev'");
t_ok('снимка нет, панель не ответила — нечего показать', uapps_view($e) === null && $e !== '');

// Много устройств: несколько страниц; обрыв посреди обхода — неполный список не сохраняется
$tt_pdo->exec("DELETE FROM admin_cache WHERE k = 'pref_hwdev'");
$many = $base;
$many['hwdev'] = [$uu(0) => []];
for ($i = 0; $i < 600; $i++) $many['hwdev'][$uu(0)][] = $dev('m' . $i, 'Happ/3.4.' . ($i % 3), 'Android', '15', 'Pixel 8');
file_put_contents($state, json_encode($many));
$n1 = $calls();
$v6 = uapps_view($e);
t_eq('600 устройств — три страницы по 250', [count($v6['D']), count($v6['U']), $calls() - $n1], [600, 1, 3]);
$age(UAPPS_TTL + 5);
file_put_contents($state, json_encode(['hwdev_fail_at' => 250] + $many));
$v7 = uapps_view($e);
t_ok('обрыв на второй странице — остаётся прошлый полный список', count($v7['D']) === 600 && $e !== '', $e);

// Пустая панель и смена панели
$tt_pdo->exec("DELETE FROM admin_cache WHERE k = 'pref_hwdev'");
file_put_contents($state, json_encode(['hwdev' => []] + $base));
$v8 = uapps_view($e);
t_eq('устройств нет — пустой список без ошибки', [$v8['D'], $e], [[], '']);
panel_snapshots_drop();
t_eq('сменили панель — снимок устройств убран', cmap_cache_get('pref_hwdev'), null);

// Админка
$put();
$tt_pdo->exec("DELETE FROM admin_cache WHERE k = 'pref_hwdev'");
$mw = t_serve($tt_root, $tt_root . '/tools/tests/_router.php');
$jar = t_admin_login($mw);
$n2 = $calls();
$u2 = $calls('/api/users');
[$code, , $page] = t_http('GET', $mw . '/admin/index.php?tab=users&view=apps', [], null, $jar);
t_ok('вкладка «Приложения» открывается', $code === 200 && strpos($page, 'id="ua"') !== false && strpos($page, '</html>') !== false);
t_ok('сегмент «Список / Карта / Приложения»', strpos($page, 'class="on" href="?tab=users&amp;view=apps">Приложения</a>') !== false && strpos($page, 'href="?tab=users">Список</a>') !== false);
t_eq('сама страница панель не опрашивает', [$calls() - $n2, $calls('/api/users') - $u2], [0, 0]);
[$code, , $j] = t_http('GET', $mw . '/admin/index.php?ajax=uapps', [], null, $jar);
$j = json_decode($j, true);
t_eq('ajax: список при открытии', [$code, $j['ok'] ?? null, count($j['D'] ?? []), $j['ttl'] ?? null, $calls() - $n2], [200, true, 8, UAPPS_TTL, 1]);
[, $hd, $z] = t_http('GET', $mw . '/admin/index.php?ajax=uapps', ['accept-encoding' => 'gzip'], null, $jar);
t_eq('ajax: повтор — из снимка', [json_decode($z, true)['ts'] ?? (json_decode((string) @gzdecode($z), true)['ts'] ?? 0), $calls() - $n2], [$j['ts'], 1]);
[, , $x] = t_http('GET', $mw . '/admin/index.php?ajax=uapps');
t_ok('ajax без входа — список не отдаётся', strpos($x, '"D"') === false);
[$code, , $list] = t_http('GET', $mw . '/admin/index.php?tab=users', [], null, $jar);
t_ok('список пользователей ведёт на «Приложения»', $code === 200 && strpos($list, 'view=apps">Приложения</a>') !== false && strpos($list, 'id="ux"') !== false);
t_ok('карточка по ссылке открывается на «Устройствах»', strpos($list, "ct=dev(&|$)/.test(location.search)?'dev':'sess'") !== false);
[, , $map] = t_http('GET', $mw . '/admin/index.php?tab=users&view=map', [], null, $jar);
t_ok('карта подключений ведёт на «Приложения»', strpos($map, 'view=apps">Приложения</a>') !== false);
file_put_contents($state, json_encode(['hwdev_fail' => true] + $base));
$tt_pdo->exec("DELETE FROM admin_cache WHERE k = 'pref_hwdev'");
[$code, , $f] = t_http('GET', $mw . '/admin/index.php?ajax=uapps', [], null, $jar);
$f = json_decode($f, true);
t_ok('ajax: панель недоступна и снимка нет — отказ с причиной', $code === 200 && ($f['ok'] ?? null) === false && ($f['error'] ?? '') !== '');
t_eq('в «Запросах к панели» запрос подписан', apistat_src_label('ajax:uapps'), 'Админка, фоновый запрос: приложения пользователей');

t_done();
