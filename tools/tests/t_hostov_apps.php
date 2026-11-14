<?php
declare(strict_types=1);

require __DIR__ . '/_boot.php';

$SU = 'AbCdEfGhIjKlMnOp';
$H = ['uuid' => 'h-de', 'name' => 'DE', 'rmk' => 'DE', 'addr' => 'de1', 'apat' => 'de1', 'port' => 443, 'sec' => 'reality', 'trk' => 'tcp', 'proto' => 'vless', 'sni' => '', 'snipat' => '', 'fp' => 'chrome'];
$UA_H = 'Happ/3.6.2/ios CFNetwork/1568 Darwin/24.0';
$UA_H2 = 'Happ/3.7.0/android';
$UA_F = 'FlClash/0.8.85 clash-verge Platform/android';
$link = 'vless://u@de1:443?security=reality&type=tcp&fp=chrome#DE';
// устройства: два телефона с одним клиентом — разные устройства
$HW = ['happ' => 'hw-1', 'happ2' => 'hw-2', 'v2rayng' => 'hw-3', 'flclash' => 'hw-4', 'sfa' => 'hw-6', 'streisand' => 'hw-7'];
$KK = static fn(string $n) => hostov_dev_key($HW[$n]);
$NM = array_flip(array_map('hostov_dev_key', $HW));
$rows = static fn() => hostov_rows_for($SU, true);
$fp = static function (string $hw) use ($rows, $link): string {
    [$b] = hostov_apply($link, 'base64', hostov_split(hostov_pick($rows(), $hw))[0]);
    return preg_match('~fp=(\w+)~', $b, $m) ? $m[1] : '';
};

// Устройство запроса узнаётся по HWID
t_eq('ключ устройства: регистр и пробелы HWID не важны, без HWID ключа нет', [hostov_dev_key('HW-1') === hostov_dev_key(' hw-1 '), strlen(hostov_dev_key('hw-1')), hostov_dev_key(''), hostov_dev_key('hw-1') !== hostov_dev_key('hw-2')], [true, 32, '', true]);
$di = hostov_dev_info(['hwid' => 'hw-1', 'deviceModel' => 'iPhone 15 Pro', 'platform' => 'iOS', 'userAgent' => $UA_H]);
$df = hostov_dev_info(['hwid' => 'hw-4', 'platform' => 'Android', 'userAgent' => $UA_F]);
t_eq('устройство: ключ, подпись, клиент и что ядру не отправить', [$di['k'], $di['l'], $di['a'], (array) $di['no'], $df['l'], array_keys((array) $df['no']), hostov_dev_info(['hwid' => 'x'])['a'], hostov_dev_info([])['k']],
    [hostov_dev_key('hw-1'), 'iPhone 15 Pro', 'Happ 3.6.2', [], 'Android', ['frag', 'noise'], '', '']);

// Общая подмена и подмена на устройство живут рядом
t_ok('общая подмена', hostov_save($SU, $H, 'fp', 'firefox', 0, 1, 'u', $e), (string) $e);
t_ok('подмена на устройство', hostov_save($SU, $H, 'fp', 'safari', 0, 1, 'u', $e, null, $w, [['k' => $KK('happ'), 'l' => 'Happ']]), (string) $e);
t_eq('две строки на один хост и параметр', count($rows()), 2);
t_eq('своему устройству — своя; другому устройству с тем же клиентом и остальным — общая', [$fp('hw-1'), $fp('HW-1'), $fp('hw-2'), $fp('hw-4'), $fp('')], ['safari', 'safari', 'firefox', 'firefox', 'firefox']);
t_ok('повторное сохранение правит ту же строку', hostov_save($SU, $H, 'fp', 'randomized', 0, 1, 'u', $e, null, $w, [['k' => $KK('happ'), 'l' => 'Happ']]) && count($rows()) === 2 && $fp('hw-1') === 'randomized');
t_ok('ключ не похож на ключ устройства', !hostov_save($SU, $H, 'fp', 'safari', 0, 1, 'u', $e, null, $w, [['k' => 'Bad Key!']]) && $e !== '');
t_ok('несколько устройств — одним сохранением', hostov_save($SU, $H, 'frag', 'std', 0, 1, 'u', $e, null, $w, [['k' => $KK('happ'), 'l' => 'Happ'], ['k' => $KK('v2rayng'), 'l' => 'v2rayNG']]) && count($rows()) === 4, (string) $e);
$v = array_values(array_filter(hostov_list($SU), static fn($x) => $x['p'] === 'frag'));
t_eq('в списке видно устройство и время создания', [array_map(static fn($k) => $NM[$k] ?? $k, array_column($v, 'ak')), array_column($v, 'al'), $v[0]['cr'] > 0], [['happ', 'v2rayng'], ['Happ', 'v2rayNG'], true]);


// Подмена на устройство не уходит клиенту, чьё ядро параметр не понимает, — даже в ссылках; общая ведёт себя как раньше
$fr = array_values(array_filter($rows(), static fn($r) => $r['param'] === 'frag' && $r['app_k'] === $KK('happ')))[0];
[$b, $h] = hostov_apply($link, 'base64', [$fr], 'SFA/1.11.0 (sing-box 1.11.0)');
t_eq('на устройство + клиент sing-box: ссылка как была, отметка «не применима»', [$b, count($h), $h[0][5], $h[0][7]], [$link, 1, 1, 'sing-box']);
[$b, $h] = hostov_apply($link, 'base64', [$fr], $UA_H);
t_ok('на устройство + клиент Xray: маска в ссылке', strpos($b, 'fm=') !== false && count($h) === 1 && $h[0][5] === 0, $b);
[$b, $h] = hostov_apply($link, 'base64', [['app_k' => ''] + $fr], 'SFA/1.11.0 (sing-box 1.11.0)');
t_ok('общая подмена в ссылках — как до правки, для любого клиента', strpos($b, 'fm=') !== false && $h[0][5] === 0, $b);
[$b3] = hostov_apply($link, 'base64', [['app_k' => ''] + $fr]);
t_eq('старый вызов без клиента даёт то же тело', $b3, $b);

// Уровень логов — параметр всей подписки
t_ok('уровень логов сохраняется без хоста', hostov_save($SU, [], 'log', 'debug', 0, 1, 'u', $e), (string) $e);
t_ok('чужое значение уровня', !hostov_save($SU, [], 'log', 'trace', 0, 1, 'u', $e));
[$hr, $lr] = hostov_split(hostov_pick($rows(), 'hw-4'));
t_eq('строка подписки отделена от подмен хостов', [count($hr), count($lr), $lr[0]['param'] ?? ''], [1, 1, 'log']);
[, $h] = hostov_apply($link, 'base64', $rows());
t_ok('в сопоставление хостов уровень логов не идёт', !array_filter($h, static fn($x) => $x[4] === 'log'));

$x = json_encode([['remarks' => 'A', 'outbounds' => [['protocol' => 'vless']], 'stats' => new stdClass()], ['remarks' => 'B', 'log' => ['access' => 'none', 'logLevel' => 'warning'], 'outbounds' => []], ['note' => 'не конфиг']]);
[$b, $h] = hostov_apply_log($x, 'base64', $lr);
$o = json_decode($b);
t_eq('xray-json: уровень в каждом конфиге, ключ шаблона сохранён', [$o[0]->log->loglevel ?? null, $o[1]->log->logLevel ?? null, $o[1]->log->access ?? null, isset($o[1]->log->loglevel), isset($o[2]->log)], ['debug', 'debug', 'none', false, false]);
t_ok('xray-json: пустой объект остался объектом', strpos($b, '"stats":{}') !== false, $b);
t_eq('xray-json: попадание и «было»', [count($h), $h[0][2], $h[0][3], $h[0][4], $h[0][5]], [1, '', 'debug', 'log', 0]);
[$b] = hostov_apply_log('{"outbounds":[{"protocol":"vless"}],"log":{"loglevel":"info"}}', 'base64', [['param' => 'log', 'val' => 'off', 'host_uuid' => '', 'id' => 1]]);
t_eq('xray-json: один конфиг, выключить', json_decode($b)->log->loglevel ?? null, 'none');

[$b, $h] = hostov_apply_log("mixed-port: 7890\nlog-level: info\nproxies:\n  - name: a\n", 'clash', $lr);
t_eq('mihomo: строка заменена, остальное не тронуто', [$b, $h[0][2]], ["mixed-port: 7890\nlog-level: debug\nproxies:\n  - name: a\n", 'info']);
[$b, $h] = hostov_apply_log("mixed-port: 7890\r\nproxies:\r\n  - name: a\r\n    log-level: x\r\n", 'clash', $lr);
t_eq('mihomo: строки нет — добавлена первой, вложенная не задета', [$b, $h[0][2]], ["log-level: debug\r\nmixed-port: 7890\r\nproxies:\r\n  - name: a\r\n    log-level: x\r\n", '']);
[$b, $h] = hostov_apply_log("log-level: silent\nproxies: []\n", 'clash', [['param' => 'log', 'val' => 'off', 'host_uuid' => '', 'id' => 1]]);
t_eq('mihomo: выключить и «было»', [$b, $h[0][2]], ["log-level: silent\nproxies: []\n", 'off']);

$sb = '{"log":{"level":"debug","disabled":true,"timestamp":true},"outbounds":[{"type":"vless","tag":"a","server":"a","server_port":443}],"route":{}}';
[$b, $h] = hostov_apply_log($sb, 'base64', [['param' => 'log', 'val' => 'warning', 'host_uuid' => '', 'id' => 1]]);
$o = json_decode($b);
t_eq('sing-box: уровень и включение логов', [$o->log->level ?? null, $o->log->disabled ?? null, $o->log->timestamp ?? null, $h[0][2], strpos($b, '"route":{}') !== false], ['warn', false, true, 'off', true]);
[$b] = hostov_apply_log($sb, 'base64', [['param' => 'log', 'val' => 'off', 'host_uuid' => '', 'id' => 1]]);
t_eq('sing-box: выключить — уровень не трогается', [json_decode($b)->log->disabled ?? null, json_decode($b)->log->level ?? null], [true, 'debug']);
[$b, $h] = hostov_apply_log('{"outbounds":[{"type":"vless","server":"a","server_port":443}]}', 'base64', $lr);
t_eq('sing-box: секции логов не было', [json_decode($b)->log->level ?? null, $h[0][2]], ['debug', '']);

[$b, $h] = hostov_apply_log('[{"outbounds":[{"type":"vless","server":"a","server_port":1}]},{"outbounds":[{"protocol":"vless"}]}]', 'base64', $lr);
$o = json_decode($b);
t_eq('массив: конфиг не Xray пропущен', [isset($o[0]->log), $o[1]->log->loglevel ?? null], [false, 'debug']);
foreach (['просто текст', '<html><body>502</body></html>', '{"outbounds":"нет"}', '[]', '[1,2]', 'null', "\x00\xFF"] as $junk) {
    [$b, $h] = hostov_apply_log($junk, 'base64', $lr);
    t_eq('не конфиг и не ссылки — тело как было, отметки нет: ' . substr(json_encode($junk, JSON_INVALID_UTF8_SUBSTITUTE), 0, 24), [$b, $h], [$junk, []]);
}
[$b, $h] = hostov_apply_log("proxies: []\n", 'clash', []);
t_eq('подмены уровня логов нет — тело как было', [$b, $h], ["proxies: []\n", []]);
[$b, $h] = hostov_apply_log($link, 'clash', $lr);
t_eq('клиент mihomo получил не конфиг: тело как было', [$b, $h], [$link, []]);
[$b] = hostov_apply_log("\xEF\xBB\xBF---\nproxies: []\n", 'clash', $lr);
t_eq('mihomo: строка встаёт после BOM и начала документа', $b, "\xEF\xBB\xBF---\nlog-level: debug\nproxies: []\n");
[$b, $h] = hostov_apply_log($link, 'base64', $lr);
t_eq('ссылки: тело как было, отметка «не применима»', [$b, $h[0][5], $h[0][7]], [$link, 1, 'ссылки']);
[$b, $h] = hostov_apply_log('{"message":"oops"', 'base64', $lr);
t_eq('битый JSON: тело как было', [$b, $h], ['{"message":"oops"', []]);
[$b, $h] = hostov_apply_log('{"message":"не конфиг"}', 'base64', $lr);
t_eq('JSON не конфиг: тело как было', [$b, $h], ['{"message":"не конфиг"}', []]);

// «Было» запоминается из самой выдачи, отметки — у своих строк
[$b, $h] = hostov_apply_log("log-level: info\nproxies: []\n", 'clash', $lr);
hostov_note($lr, $h, 'FlClash 0.8.85 · Android');
$l = array_values(array_filter(hostov_list($SU), static fn($x) => $x['p'] === 'log'));
t_eq('уровень логов: было, статус, приложение', [$l[0]['pv'], $l[0]['st'], $l[0]['la'], $l[0]['n']], ['info', 'ok', 'FlClash 0.8.85 · Android', '']);
[$b, $h] = hostov_apply_log($link, 'base64', $lr);
db()->exec('UPDATE host_ovr SET vat = vat - 5, last_hit = last_hit - 5');
hostov_note($lr, $h, 'v2rayNG 1.10.5 · Android');
$l = array_values(array_filter(hostov_list($SU), static fn($x) => $x['p'] === 'log'));
t_eq('уровень логов: в ссылках не применима', [$l[0]['st'], $l[0]['naf']], ['na', 'ссылки']);
$picked = hostov_split(hostov_pick($rows(), 'hw-4'))[0];
t_ok('чужому устройству отметка «не было» не ставится', !array_filter($picked, static fn($r) => (string) $r['app_k'] !== ''));

// Лог запросов: последняя созданная подмена — первой, подмена на приложение помечена
$m = hostov_log_meta([['A', 'chrome', 'firefox', 'fp', 0, '', '', 0, 10], ['B', 'chrome', 'safari', 'fp', 0, '', '', 1, 30], ['', 'info', 'debug', 'log', 0, '', '', 0, 20]]);
t_eq('лог: порядок по времени создания', [$m['c'], array_column($m['h'], 0), $m['h'][0][7] ?? null, count($m['h'][2])], [3, ['B', '', 'A'], 1, 3]);
$m = hostov_log_meta([['A', 'chrome', 'firefox'], ['B', 'нет', 'Обычная', 'frag', 0, 'packets tlshello', '']]);
t_eq('лог: записи прежнего формата читаются', [$m['c'], count($m['h'][0]), $m['h'][1][3]], [2, 3, 'frag']);

// Что какому ядру можно отправить
t_eq('применимость по ядру', [hostov_fits('fp', 'mihomo'), hostov_fits('log', 'sing-box'), hostov_fits('frag', 'xray'), hostov_fits('noise', ''), hostov_fits('frag', 'mihomo') !== '', hostov_fits('noise', 'sing-box') !== ''], ['', '', '', '', true, true]);

// Смена «кому»
$id = static fn(string $p, string $n) => (int) (array_values(array_filter(hostov_list($SU), static fn($x) => $x['p'] === $p && $x['ak'] === ($n === '' ? '' : $KK($n))))[0]['id'] ?? 0);
$cr = (int) db()->query('SELECT created_at FROM host_ovr WHERE id = ' . $id('fp', ''))->fetchColumn();
db()->exec('UPDATE host_ovr SET created_at = created_at - 50 WHERE id = ' . $id('fp', ''));
t_ok('общая → на устройство', hostov_rescope([$id('fp', '')], [['k' => $KK('flclash'), 'l' => 'FlClash']], $e), (string) $e);
t_eq('значение и время создания остались, общей больше нет', [$fp('hw-4'), $fp(''), (int) db()->query("SELECT created_at FROM host_ovr WHERE id = " . $id('fp', 'flclash'))->fetchColumn()], ['firefox', 'chrome', $cr - 50]);
t_ok('на устройство, у которого своя подмена, нельзя', !hostov_rescope([$id('fp', 'flclash')], [['k' => $KK('happ'), 'l' => 'Happ']], $e) && strpos($e, 'Happ') !== false, (string) $e);
t_ok('устройства → общая', hostov_rescope([$id('frag', 'happ'), $id('frag', 'v2rayng')], [], $e) && $id('frag', '') > 0 && $id('frag', 'happ') === 0 && $id('frag', 'v2rayng') === 0, (string) $e);
t_ok('общая → два устройства, прежние отметки у оставшегося не сбрасываются', hostov_rescope([$id('fp', 'flclash')], [['k' => $KK('flclash'), 'l' => 'FlClash'], ['k' => $KK('streisand'), 'l' => 'Streisand']], $e) && $id('fp', 'streisand') > 0, (string) $e);
t_ok('общая уже есть — вторую не сделать', hostov_save($SU, $H, 'fp', 'safari', 0, 1, 'u', $e) && !hostov_rescope([$id('fp', 'streisand')], [], $e) && $e !== '', (string) $e);
t_ok('чужие строки вместе не меняются', !hostov_rescope([$id('fp', ''), $id('log', '')], [], $e));
t_ok('счётчик действующих подмен', (int) setting('hostov_n', '0') === count(hostov_list($SU)));

// ---- через мок-панель: устройства пользователя, выдача по User-Agent, уровень логов в каждом формате ----
hostov_purge_user($SU);
db()->exec('DELETE FROM request_log');
$B = 'QrStUvWxYz012345';
$uuid = '00000000-0000-4000-8000-000000000000';
$yaml = "mixed-port: 7890\nlog-level: info\nproxies:\n  - name: DE\n    type: vless\n    server: de1\n    port: 443\n    tls: true\n    client-fingerprint: chrome\n    reality-opts:\n      public-key: k\nproxy-groups:\n  - name: G\n    type: select\n    proxies:\n      - DE\n";
$xj = json_encode([['remarks' => 'DE', 'stats' => new stdClass(), 'outbounds' => [['tag' => 'proxy', 'protocol' => 'vless', 'settings' => ['vnext' => [['address' => 'de1', 'port' => 443]]], 'streamSettings' => ['network' => 'tcp', 'security' => 'reality', 'realitySettings' => ['fingerprint' => 'chrome']]]]]], JSON_UNESCAPED_SLASHES);
$sbj = json_encode(['log' => ['level' => 'debug', 'disabled' => true, 'timestamp' => true], 'outbounds' => [['type' => 'vless', 'tag' => 'DE', 'server' => 'de1', 'server_port' => 443, 'tls' => ['enabled' => true, 'reality' => ['enabled' => true], 'utls' => ['enabled' => true, 'fingerprint' => 'chrome']]]], 'route' => ['rules' => []]], JSON_UNESCAPED_SLASHES);
$state = $tt_root . '/data/mock_state.json';
file_put_contents($state, json_encode([
    'users' => [$SU => ['status' => 'ACTIVE', 'bodies' => ['base64' => base64_encode($link), 'clash' => $yaml, 'singbox' => $sbj, 'xray' => $xj]], $B => ['status' => 'ACTIVE']],
    'version' => '3.4.8',
    'hosts' => [['remark' => 'DE', 'address' => 'de1']],
    'ckeys' => ['1' => [$link]],
    'hwdev' => [$uuid => [
        ['hwid' => 'hw-1', 'platform' => 'iOS', 'osVersion' => '18.1', 'deviceModel' => 'iPhone 15 Pro', 'userAgent' => $UA_H, 'createdAt' => '2026-01-01T00:00:00.000Z', 'updatedAt' => '2026-02-01T00:00:00.000Z'],
        ['hwid' => 'hw-2', 'platform' => 'Android', 'osVersion' => '15', 'deviceModel' => 'Pixel 9', 'userAgent' => $UA_H2, 'createdAt' => '2026-01-02T00:00:00.000Z', 'updatedAt' => '2026-01-02T00:00:00.000Z'],
        ['hwid' => 'hw-3', 'platform' => 'Android', 'osVersion' => '14', 'deviceModel' => 'SM-S921B', 'userAgent' => 'v2rayNG/1.10.5', 'createdAt' => '2026-01-03T00:00:00.000Z', 'updatedAt' => '2026-01-03T00:00:00.000Z'],
        ['hwid' => 'hw-4', 'platform' => 'Android', 'osVersion' => '15', 'deviceModel' => 'Pixel 8', 'userAgent' => 'FlClash/0.8.85 Platform/android', 'createdAt' => '2026-01-04T00:00:00.000Z', 'updatedAt' => '2026-01-04T00:00:00.000Z'],
        ['hwid' => 'hw-5', 'platform' => 'Windows', 'osVersion' => '11', 'deviceModel' => 'PC', 'userAgent' => '', 'createdAt' => '2026-01-05T00:00:00.000Z', 'updatedAt' => '2026-01-05T00:00:00.000Z'],
    ]],
], JSON_UNESCAPED_UNICODE));
$plog = $tt_root . '/data/mock_panel.log';
$panel = t_serve($tt_root . '/tools', $tt_root . '/tools/mock_panel.php', ['MOCK_STATE' => $state, 'MOCK_LOG' => $plog]);
t_settings(['sub_source' => 'panel', 'remnawave_url' => $panel, 'remnawave_api_key' => 'test-token', 'geoip_auto' => '0']);
set_setting('update_state', json_encode(['checked_at' => time()]));
$mw = t_serve($tt_root, $tt_root . '/tools/tests/_router.php', [], ['opcache.enable' => '0']);
$HWU = [];
$sub = static function (string $ua, string $su = '') use ($mw, $SU, &$HWU): string { [, , $b] = t_http('GET', $mw . '/' . ($su ?: $SU), ['User-Agent' => $ua] + (isset($HWU[$ua]) ? ['x-hwid' => $HWU[$ua]] : [])); return $b; };
$UA_C = 'FlClash/0.8.85 Platform/android';
$UA_S = 'SFA/1.11.0 (sing-box 1.11.0)';
$UA_V = 'v2rayNG/1.10.5';
// у каждого клиента теста своё устройство; у SFA устройства в панели нет
$HWU = [$UA_H => 'hw-1', $UA_C => 'hw-4', $UA_S => 'hw-6', $UA_V => 'hw-3'];

$base = [$sub($UA_H), $sub($UA_C), $sub($UA_S), $sub($UA_V)];
t_eq('без подмен: тела панели байт в байт', $base, [$xj, $yaml, $sbj, base64_encode($link)]);

$jar = t_admin_login($mw);
[, , $page] = t_http('GET', $mw . '/admin/index.php?tab=users', [], null, $jar);
preg_match('~"csrf":"([0-9a-f]+)"~', $page, $cm);
$csrf = $cm[1] ?? '';
t_ok('карточка: уровни логов переданы на страницу', strpos($page, '"hvlv":["off","error","warning","info","debug"]') !== false);
$post = static function (string $a, array $f) use ($mw, $jar, $csrf) {
    [$code, , $r] = t_http('POST', $mw . '/admin/index.php?ajax=' . $a, ['content-type' => 'application/x-www-form-urlencoded'], http_build_query(['csrf' => $csrf] + $f), $jar);
    return json_decode($r, true) ?: ['code' => $code];
};
// выбор «кому» — те же устройства, что на вкладке «Устройства», с клиентом и тем, что его ядру не отправить
[, , $j] = t_http('GET', $mw . '/admin/index.php?ajax=hwids&uuid=' . $uuid, [], null, $jar);
$j = json_decode($j, true) ?: [];
t_eq('устройства: у каждого свой ключ, подпись и клиент', array_map(static fn($d) => [$d['hv']['l'] ?? null, $d['hv']['a'] ?? null, $d['hv']['k'] ?? null, array_keys((array) ($d['hv']['no'] ?? []))], $j['devices'] ?? []),
    [['iPhone 15 Pro', 'Happ 3.6.2', hostov_dev_key('hw-1'), []], ['Pixel 9', 'Happ 3.7.0', hostov_dev_key('hw-2'), []], ['SM-S921B', 'v2rayNG 1.10.5', hostov_dev_key('hw-3'), []], ['Pixel 8', 'FlClash 0.8.85', hostov_dev_key('hw-4'), ['frag', 'noise']], ['PC', '', hostov_dev_key('hw-5'), []]]);
t_ok('устройства: прежние поля панели на месте', ($j['ok'] ?? false) && ($j['devices'][0]['hwid'] ?? '') === 'hw-1' && ($j['devices'][0]['userAgent'] ?? '') === $UA_H);

[, , $hj] = t_http('GET', $mw . '/admin/index.php?ajax=hv_hosts&uid=1', [], null, $jar);
$host = json_decode($hj, true)['hosts'][0] ?? [];
$r1 = $post('hv_save', ['su' => $SU, 'uid' => 1, 'un' => 'user0', 'host' => json_encode($host), 'p' => 'fp', 'v' => 'firefox', 'till' => 0]);
$r2 = $post('hv_save', ['su' => $SU, 'uid' => 1, 'un' => 'user0', 'host' => json_encode($host), 'p' => 'fp', 'v' => 'safari', 'till' => 0, 'apps' => json_encode([['k' => $KK('happ'), 'l' => 'Happ'], ['k' => $KK('flclash'), 'l' => 'FlClash']])]);
$r3 = $post('hv_save', ['su' => $SU, 'uid' => 1, 'un' => 'user0', 'p' => 'log', 'v' => 'debug', 'till' => 0]);
$r4 = $post('hv_save', ['su' => $SU, 'uid' => 1, 'un' => 'user0', 'p' => 'log', 'v' => 'off', 'till' => 0, 'apps' => json_encode([['k' => $KK('sfa'), 'l' => 'SFA']])]);
t_ok('ajax: общая, на два приложения, уровень логов общий и на приложение', ($r1['ok'] ?? false) && ($r2['ok'] ?? false) && ($r3['ok'] ?? false) && ($r4['ok'] ?? false) && count($r4['hv'] ?? []) === 5, json_encode([$r1, $r2, $r3, $r4], JSON_UNESCAPED_UNICODE));
t_ok('ajax: кривой список приложений не сохраняется', !($post('hv_save', ['su' => $SU, 'uid' => 1, 'un' => 'user0', 'p' => 'log', 'v' => 'info', 'till' => 0, 'apps' => '[{"k":"../x y"}]'])['ok'] ?? false) && count(hostov_list($SU)) === 5);

$x = json_decode($sub($UA_H), true);
t_eq('Happ (Xray JSON): своя подмена отпечатка и общий уровень логов', [$x[0]['outbounds'][0]['streamSettings']['realitySettings']['fingerprint'] ?? '', $x[0]['log']['loglevel'] ?? '', isset($x[0]['stats'])], ['safari', 'debug', true]);
$y = $sub($UA_C);
t_ok('FlClash (mihomo): своя подмена и общий уровень логов', strpos($y, "\nlog-level: debug\n") !== false && strpos($y, 'client-fingerprint: safari') !== false && substr_count($y, 'log-level') === 1, $y);
$sb = json_decode($sub($UA_S), true);
t_eq('SFA (sing-box): общий отпечаток, свой уровень логов — выключить', [$sb['outbounds'][0]['tls']['utls']['fingerprint'] ?? '', $sb['log']['disabled'] ?? null, $sb['log']['level'] ?? ''], ['firefox', true, 'debug']);
sleep(1);
t_eq('v2rayNG (ссылки): общий отпечаток, уровня логов в ссылках нет', (string) base64_decode($sub($UA_V)), str_replace('fp=chrome', 'fp=firefox', $link));

$m = json_decode((string) db()->query("SELECT meta FROM request_log WHERE short_uuid = '{$SU}' AND user_agent LIKE 'Happ%' ORDER BY id DESC LIMIT 1")->fetchColumn(), true)['hv'] ?? null;
t_ok('лог запросов: обе подмены, последняя созданная первой, пометка «на приложение»', is_array($m) && $m['c'] === 2 && $m['h'][0][3] === 'log' && $m['h'][0][2] === 'debug' && ($m['h'][1][7] ?? 0) === 1 && $m['h'][1][2] === 'safari', json_encode($m, JSON_UNESCAPED_UNICODE));
$m = json_decode((string) db()->query("SELECT meta FROM request_log WHERE short_uuid = '{$SU}' AND user_agent LIKE 'v2rayNG%' ORDER BY id DESC LIMIT 1")->fetchColumn(), true)['hv'] ?? null;
t_ok('лог запросов: в ссылках уровень логов помечен неприменимым', is_array($m) && $m['c'] === 2 && count(array_filter($m['h'], static fn($h) => ($h[3] ?? 'fp') === 'log' && ($h[4] ?? 0) === 1)) === 1, json_encode($m, JSON_UNESCAPED_UNICODE));
[, , $lp] = t_http('GET', $mw . '/admin/index.php?tab=reqlog', [], null, $jar);
t_ok('лог запросов: карточка — «Подмены», первая на виду, остальные свёрнуты', strpos($lp, '>Подмены<') !== false && strpos($lp, 'class="hv-xd"') !== false && strpos($lp, 'Подмена хостов') === false && strpos($lp, 'уровень логов') !== false);

$ok = false;
for ($i = 0; $i < 30 && !$ok; $i++) {
    $l = hostov_list($SU);
    $ok = count(array_filter($l, static fn($o) => $o['lh'] !== null || $o['st'] === 'na')) === 5;
    if (!$ok) usleep(100000);
}
$stt = [];
foreach ($l as $o) $stt[$o['p'] . ':' . ($NM[$o['ak']] ?? $o['ak'])] = [$o['st'], $o['pv']];
t_eq('отметки: у каждой строки свои, «было» уровня логов — из выдачи своего приложения', [$stt['log:'] ?? null, $stt['log:sfa'] ?? null, $stt['fp:happ'][0] ?? '', $stt['fp:flclash'][0] ?? '', $stt['fp:'][0] ?? ''], [['na', 'info'], ['ok', 'off'], 'ok', 'ok', 'ok']);

// смена «кому», срок и снятие — одним запросом на все строки подмены
$ids = static fn(string $p, bool $app) => implode(',', array_column(array_filter(hostov_list($SU), static fn($o) => $o['p'] === $p && ($o['ak'] !== '') === $app), 'id'));
$r = $post('hv_set', ['id' => $ids('fp', true), 'till' => time() + 3600]);
t_ok('срок — обеим строкам подмены', ($r['ok'] ?? false) && count(array_filter($r['hv'], static fn($o) => $o['p'] === 'fp' && $o['ak'] !== '' && $o['till'] > time())) === 2, json_encode($r, JSON_UNESCAPED_UNICODE));
$r = $post('hv_scope', ['id' => $ids('fp', true), 'apps' => json_encode([['k' => $KK('happ'), 'l' => 'Happ']])]);
t_ok('смена «кому»: FlClash убран', ($r['ok'] ?? false) && strpos($sub($UA_C), 'client-fingerprint: firefox') !== false && json_decode($sub($UA_H), true)[0]['outbounds'][0]['streamSettings']['realitySettings']['fingerprint'] === 'safari', json_encode($r, JSON_UNESCAPED_UNICODE));
$r = $post('hv_scope', ['id' => $ids('fp', true), 'apps' => '[]']);
t_ok('смена «кому»: общая уже есть — отказ с причиной', !($r['ok'] ?? false) && ($r['error'] ?? '') !== '', json_encode($r, JSON_UNESCAPED_UNICODE));
$r = $post('hv_scope', ['id' => $ids('fp', true) . ',' . $ids('log', false), 'apps' => '[]']);
t_ok('смена «кому»: разные подмены одним запросом — отказ', !($r['ok'] ?? false));
[$code] = t_http('POST', $mw . '/admin/index.php?ajax=hv_scope', ['content-type' => 'application/x-www-form-urlencoded'], http_build_query(['csrf' => 'bad', 'id' => $ids('log', true), 'apps' => '[]']), $jar);
t_eq('смена «кому»: без CSRF не пишет', $code, 400);
$r = $post('hv_del', ['id' => $ids('log', false) . ',' . $ids('log', true)]);
t_ok('снятие нескольких строк', ($r['ok'] ?? false) && !array_filter($r['hv'], static fn($o) => $o['p'] === 'log') && $sub($UA_S) !== $sbj && json_decode($sub($UA_S), true)['log'] === ['level' => 'debug', 'disabled' => true, 'timestamp' => true], json_encode($r, JSON_UNESCAPED_UNICODE));

// вторая подписка: уровень логов её пользователя в конфиг основного не идёт
db()->exec('DELETE FROM host_ovr');
hostov_recount();
t_eq('подмены сняты — тела снова как в панели', [$sub($UA_H), $sub($UA_C), $sub($UA_S), $sub($UA_V)], $base);
// подмена только на одно устройство: остальным устройствам условный запрос отвечает 304, как без подмен
hostov_save($SU, $host, 'fp', 'safari', 0, 1, 'user0', $e, null, $w, [['k' => $KK('happ'), 'l' => 'Happ']]);
[, $hh] = t_http('GET', $mw . '/' . $SU, ['User-Agent' => $UA_V, 'x-hwid' => 'hw-3']);
[$c1] = t_http('GET', $mw . '/' . $SU, ['User-Agent' => $UA_V, 'x-hwid' => 'hw-3', 'If-None-Match' => $hh['etag'] ?? '']);
[$c2, , $b2] = t_http('GET', $mw . '/' . $SU, ['User-Agent' => $UA_H, 'x-hwid' => 'hw-1', 'If-None-Match' => '"' . md5($xj) . '"']);
// второй телефон с тем же клиентом — другое устройство: ему тело панели и 304
[$c3, , $b3] = t_http('GET', $mw . '/' . $SU, ['User-Agent' => $UA_H2, 'x-hwid' => 'hw-2']);
[$c4] = t_http('GET', $mw . '/' . $SU, ['User-Agent' => $UA_H2, 'x-hwid' => 'hw-2', 'If-None-Match' => '"' . md5($xj) . '"']);
[$c5, , $b5] = t_http('GET', $mw . '/' . $SU, ['User-Agent' => $UA_H]);
t_eq('то же приложение на другом устройстве и запрос без HWID — тело панели', [$c3, $b3 === $xj, $c4, $c5, $b5 === $xj], [200, true, 304, 200, true]);
t_eq('подмена на одно устройство: чужому — 304 и etag панели, своему — тело с подменой', [isset($hh['etag']), $c1, $c2, strpos($b2, '"fingerprint":"safari"') !== false], [true, 304, 200, true]);
db()->exec('DELETE FROM host_ovr');
hostov_recount();
set_setting('addsub_enabled', '1');
addsub_map_set($SU, $panel . '/api/sub/' . $B);
hostov_save($B, [], 'log', 'debug', 0, 2, 'user1', $e);
$y = $sub($UA_C);
t_ok('вторая подписка: серверы добавлены, уровень логов — как у основного', strpos($y, $B . '-0') !== false && strpos($y, "\nlog-level: info\n") !== false && strpos($y, 'log-level: debug') === false, $y);
t_ok('у самого второго пользователя уровень логов действует', strpos($sub($UA_C, $B), 'log-level: debug') === 0);

t_done();
