<?php
declare(strict_types=1);

require __DIR__ . '/_boot.php';

$state = $tt_root . '/data/mock_state.json';
file_put_contents($state, json_encode(['users' => ['AbCdEfGhIjKlMnOp' => ['status' => 'ACTIVE']]]));
$panel = t_serve($tt_root . '/tools', $tt_root . '/tools/mock_panel.php', ['MOCK_STATE' => $state]);
t_settings(['sub_source' => 'panel', 'remnawave_url' => $panel, 'remnawave_api_key' => 'test-token', 'geoip_auto' => '0']);
// Проверка обновлений ходит на GitHub — в тесте она уже «была».
set_setting('update_state', json_encode(['checked_at' => time()]));

$mw  = t_serve($tt_root, $tt_root . '/tools/tests/_router.php', [], ['opcache.enable' => '0']);
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

[, , $page] = t_http('GET', $mw . '/admin/index.php?tab=sysinfo', [], null, $jar);
t_ok('OPcache выключен — предупреждение на «О системе»', strpos($page, '<b>OPcache выключен.</b>') !== false);

if (extension_loaded('Zend OPcache')) {
    $mw2 = t_serve($tt_root, $tt_root . '/tools/tests/_router.php', [], ['opcache.enable' => '1']);
    $jar2 = t_admin_login($mw2);
    [, , $page] = t_http('GET', $mw2 . '/admin/index.php?tab=sysinfo', [], null, $jar2);
    t_ok('OPcache включён — предупреждения нет', strpos($page, 'Окружение') !== false && strpos($page, 'OPcache выключен') === false);
    @mkdir($tt_root . '/data/opcache', 0700);
    $mw3 = t_serve($tt_root, $tt_root . '/tools/tests/_router.php', [], ['opcache.enable' => '1', 'opcache.file_cache' => $tt_root . '/data/opcache', 'opcache.file_cache_only' => '1']);
    $jar3 = t_admin_login($mw3);
    [, , $page] = t_http('GET', $mw3 . '/admin/index.php?tab=sysinfo', [], null, $jar3);
    t_ok('только файловый кэш OPcache — предупреждения нет', strpos($page, 'Окружение') !== false && strpos($page, 'OPcache выключен') === false);
}

t_done();
