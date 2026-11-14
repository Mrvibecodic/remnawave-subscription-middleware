<?php
declare(strict_types=1);

require __DIR__ . '/_boot.php';

$state = $tt_root . '/data/mock_state.json';
file_put_contents($state, json_encode(['users' => ['AbCdEfGhIjKlMnOp' => ['status' => 'ACTIVE']]]));
$panel = t_serve($tt_root . '/tools', $tt_root . '/tools/mock_panel.php', ['MOCK_STATE' => $state]);
t_settings(['sub_source' => 'panel', 'remnawave_url' => $panel, 'remnawave_api_key' => 'test-token', 'geoip_auto' => '0']);
set_setting('update_state', json_encode(['checked_at' => time()]));

$docs = $tt_root . '/docs';
$page = static function (string $base, string $jar): string { return t_http('GET', $base . '/admin/index.php?tab=connection', [], null, $jar)[2]; };
$stored = static function (): string { return (string) db()->query("SELECT v FROM settings WHERE k = 'docs_note'")->fetchColumn(); };
$post = static function (string $base, string $jar, array $f): int {
    return t_http('POST', $base . '/admin/index.php?ajax=docs_note', ['content-type' => 'application/x-www-form-urlencoded'], http_build_query($f), $jar)[0];
};

$mw  = t_serve($tt_root, $tt_root . '/tools/tests/_router.php', ['SUBMW_DOCKER' => ''], ['opcache.enable' => '0']);
$jar = t_admin_login($mw);

t_ok('папки docs нет: полосы нет', strpos($page($mw, $jar), 'id="docsNote"') === false);

mkdir($docs);
file_put_contents($docs . '/big.md', str_repeat('x', 40));
file_put_contents($docs . '/small.md', 'пять!');
file_put_contents($docs . '/empty.md', '');
file_put_contents($docs . '/.hidden', 'x');
mkdir($docs . '/sub');

$b = $page($mw, $jar);
t_ok('папка есть, проверки ещё не было: полоса в разметке, но скрыта', (bool) preg_match('~<div id="docsNote"[^>]* hidden>~', $b));
t_ok('проверочный файл — самый лёгкий непустой', strpos($b, 'data-f="small.md" data-s="' . strlen('пять!') . '"') !== false);
t_ok('справка с примерами есть на странице', strpos($b, "'docs_close':") !== false && strpos($b, 'location ^~ /docs/ { deny all; }') !== false && strpos($b, 'respond @docs 403') !== false);
preg_match('~id="docsNote"[^>]*data-csrf="([0-9a-f]+)"~', $b, $m);
$csrf = $m[1] ?? '';

t_eq('без csrf состояние не пишется', $post($mw, $jar, ['st' => 'open']), 400);
t_http('POST', $mw . '/admin/index.php?ajax=docs_note', ['content-type' => 'application/x-www-form-urlencoded'], http_build_query(['csrf' => $csrf, 'st' => 'ok']));
t_eq('без входа состояние не пишется', $stored(), '');
t_eq('чужое значение не пишется', [$post($mw, $jar, ['csrf' => $csrf, 'st' => 'whatever']), $stored()], [200, '']);

t_eq('путь открыт: состояние запомнено', [$post($mw, $jar, ['csrf' => $csrf, 'st' => 'open']), $stored()], [200, 'open']);
t_ok('путь открыт: полоса видна сразу, без повторной проверки', (bool) preg_match('~<div id="docsNote"[^>]*data-csrf="[0-9a-f]+">~', $page($mw, $jar)));

t_eq('путь закрыт или полосу убрали: состояние запомнено', [$post($mw, $jar, ['csrf' => $csrf, 'st' => 'ok']), $stored()], [200, 'ok']);
t_ok('после этого полосы нет', strpos($page($mw, $jar), 'id="docsNote"') === false);
t_eq('«открыт» после «закрыт» не возвращает полосу', [$post($mw, $jar, ['csrf' => $csrf, 'st' => 'open']), $stored()], [200, 'ok']);

db()->exec("DELETE FROM settings WHERE k = 'docs_note'");
file_put_contents($tt_root . '/data/config.php', str_replace("__DIR__ . '/data/", "__DIR__ . '/", (string) file_get_contents($tt_root . '/config.php')));
$dk   = t_serve($tt_root, $tt_root . '/tools/tests/_router.php', ['SUBMW_DOCKER' => '1'], ['opcache.enable' => '0']);
$djar = t_admin_login($dk);
$dpage = $page($dk, $djar);
unlink($tt_root . '/data/config.php');
t_ok('в Docker полосы нет даже с папкой docs', strpos($dpage, '</html>') !== false && strpos($dpage, 'id="docsNote"') === false);

t_done();
