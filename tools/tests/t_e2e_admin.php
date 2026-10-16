<?php
declare(strict_types=1);

require __DIR__ . '/_boot.php';

$state = $tt_root . '/data/mock_state.json';
file_put_contents($state, json_encode(['users' => ['AbCdEfGhIjKlMnOp' => ['status' => 'ACTIVE']]]));
$panel = t_serve($tt_root . '/tools', $tt_root . '/tools/mock_panel.php', ['MOCK_STATE' => $state]);
t_settings(['sub_source' => 'panel', 'remnawave_url' => $panel, 'remnawave_api_key' => 'test-token', 'geoip_auto' => '0']);

$mw  = t_serve($tt_root, $tt_root . '/tools/tests/_router.php');
$jar = t_admin_login($mw);
[$c, , $b] = t_http('GET', $mw . '/admin/index.php', [], null, $jar);
t_eq('админка: главная после входа', $c, 200);
preg_match_all('~[?&]tab=([a-z_]+)~', $b, $m);
$tabs = array_values(array_unique($m[1]));
t_ok('админка: в меню есть вкладки', count($tabs) >= 10, implode(',', $tabs));
foreach ($tabs as $tab) {
    [$c, , $page] = t_http('GET', $mw . '/admin/index.php?tab=' . $tab, [], null, $jar);
    t_ok('вкладка ' . $tab . ' открывается', $c === 200 && strpos($page, '</html>') !== false, 'код ' . $c);
}

t_done();
