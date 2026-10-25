<?php
declare(strict_types=1);

require __DIR__ . '/_boot.php';

$state = $tt_root . '/data/mock_state.json';
file_put_contents($state, json_encode(['users' => ['AbCdEfGhIjKlMnOp' => ['status' => 'ACTIVE']], 'nodes' => [], 'conns' => []]));
$panel = t_serve($tt_root . '/tools', $tt_root . '/tools/mock_panel.php', ['MOCK_STATE' => $state]);
t_settings(['sub_source' => 'panel', 'remnawave_url' => $panel, 'remnawave_api_key' => 'test-token', 'geoip_auto' => '0']);
set_setting('update_state', json_encode(['checked_at' => time()]));
$mw = t_serve($tt_root, $tt_root . '/tools/tests/_router.php');

$rows = static fn(string $where = '1=1') => $tt_pdo->query("SELECT src, method, ep, n, err, ms FROM api_stat_hour WHERE $where ORDER BY src, ep")->fetchAll(PDO::FETCH_ASSOC);

// Нормализация адресов
t_eq('uuid, числа и shortUuid заменяются', [apistat_ep('/api/users/by-short-uuid/AbCdEfGhIjKlMnOp'), apistat_ep('/api/hwid/devices/12345'), apistat_ep('/api/connections/by-node/11111111-1111-4111-8111-111111111111'), apistat_ep('/api/users?size=250&start=0'), apistat_ep('/api/subscription-settings'), apistat_ep('/api/users/submw-probe/actions/enable')],
    ['/api/users/by-short-uuid/{x}', '/api/hwid/devices/{x}', '/api/connections/by-node/{x}', '/api/users', '/api/subscription-settings', '/api/users/submw-probe/actions/enable']);

// Вызовы копятся в памяти и пишутся одной пачкой
apistat_src('cron:test');
remnawave_api_get('/api/users?size=1&start=0');
remnawave_api_get('/api/users/by-short-uuid/AbCdEfGhIjKlMnOp');
remnawave_api_get('/api/users/by-short-uuid/NoSuchUser000001');
remnawave_api_get('/api/nope');
t_eq('до завершения запроса в базе пусто', apistat_ensure() ? count($rows()) : -1, 0);
apistat_flush();
$r = $rows();
t_eq('после сброса — строки по адресам одной группы', array_map(static fn($x) => $x['src'] . ' ' . $x['method'] . ' ' . $x['ep'] . ' n=' . $x['n'] . ' err=' . $x['err'], $r),
    ['cron:test GET /api/nope n=1 err=1', 'cron:test GET /api/users n=1 err=0', 'cron:test GET /api/users/by-short-uuid/{x} n=2 err=1']);
t_ok('время ответа накоплено', (int) $r[1]['ms'] >= 0 && (int) $r[2]['ms'] >= (int) $r[1]['ms'] * 0);
remnawave_api_get('/api/users?size=1&start=0');
apistat_flush();
t_eq('повторный сброс складывает в ту же строку часа', (int) $rows("ep = '/api/users'")[0]['n'], 2);
t_eq('пустой буфер ничего не пишет', (static function () use ($rows) { apistat_flush(); return count($rows()); })(), 3);
apistat_expect(404);
remnawave_api_get('/api/users/by-short-uuid/NoSuchUser000002');
remnawave_api_get('/api/users/by-short-uuid/NoSuchUser000003');
apistat_flush();
t_eq('ожидаемый 404 — не ошибка, и только для одного следующего запроса', (int) $rows("ep = '/api/users/by-short-uuid/{x}'")[0]['err'], 2);

// Точки входа: подписка, мусорный адрес, админка
t_http('GET', $mw . '/AbCdEfGhIjKlMnOp', ['User-Agent' => 'v2rayN/7.0']);
t_http('GET', $mw . '/wp-login.php');
$jar = t_admin_login($mw);
t_http('GET', $mw . '/admin/index.php?tab=users', [], null, $jar);
t_http('GET', $mw . '/admin/index.php?ajax=ua_meta', [], null, $jar);
usleep(300000);
$srcs = array_count_values(array_column($rows(), 'src'));
t_ok('подписка — проксирование посчитано как «sub»', isset($srcs['sub']), json_encode($srcs));
t_ok('неизвестный адрес ушёл в панель как «junk»', isset($srcs['junk']), json_encode($srcs));
t_ok('вкладка админки и фоновый запрос помечены своими источниками', isset($srcs['admin:users']) && isset($srcs['ajax:ua_meta']), json_encode($srcs));
t_eq('проксирование подписки — отдельный адрес', count($rows("src = 'sub' AND ep = '/api/sub/{x}'")), 1);

// Срез для блока
$v = apistat_view(72);
t_ok('срез: итоги и группы', $v['total'] >= 8 && $v['hours'] === 72 && count($v['series']) === 72 && $v['groups'][0]['n'] >= $v['groups'][count($v['groups']) - 1]['n']);
t_eq('подписи групп', [apistat_src_label('sub'), apistat_src_label('cron:grace'), apistat_src_label('ajax:ua_meta')], ['Запросы подписки', 'Планировщик: проверки грейса', 'Админка, фоновый запрос: справочники карточки (сквады, лимиты)']);
t_ok('срез ограничен неделей', apistat_view(9999)['hours'] === 168);

// Чистка
$tt_pdo->prepare('INSERT INTO api_stat_hour (h, src, method, ep, n, err, ms) VALUES (?, ?, ?, ?, 5, 0, 0)')->execute([intdiv(time(), 3600) * 3600 - 9 * 86400, 'old', 'GET', '/api/x']);
apistat_prune();
t_eq('строки старше недели удалены', count($rows("src = 'old'")), 0);
t_ok('таблица есть у «Базы данных»', isset(gc_tables()['api_stat_hour']));

// Блок на «О системе»
[$c, , $page] = t_http('GET', $mw . '/admin/index.php?tab=sysinfo&seg=mw&api_h=24', [], null, $jar);
t_ok('блок «Запросы к панели» на «О системе»', $c === 200 && strpos($page, 'Запросы к панели') !== false && strpos($page, 'Запросы подписки') !== false && strpos($page, '/api/sub/{x}') !== false, (string) $c);

t_done();
