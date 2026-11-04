<?php
declare(strict_types=1);

require __DIR__ . '/_boot.php';

$P = wgnoise_presets();
$e = '';
t_eq('пресет finalmask разбирается', wgnoise_parse('fm', $P['fm'], $e), ['noise' => [['rand' => '10-20', 'delay' => '10-16']]]);
t_eq('пресет freedom разбирается', wgnoise_parse('fr', $P['fr'], $e), [['type' => 'rand', 'packet' => '10-20', 'delay' => '10-16']]);
t_eq('finalmask: маска целиком', wgnoise_parse('fm', '{"type":"noise","settings":{"reset":"30-60","noise":[{"rand":5}]}}', $e), ['noise' => [['rand' => 5]], 'reset' => '30-60']);
t_eq('finalmask: packet байтами и exp', wgnoise_parse('fm', '[{"packet":[1,2,255]},{"type":"exp","packet":"<t><r 8>","delay":3}]', $e), ['noise' => [['packet' => [1, 2, 255]], ['type' => 'exp', 'packet' => '<t><r 8>', 'delay' => 3]]]);
t_eq('freedom: outbound целиком', wgnoise_parse('fr', '{"protocol":"freedom","settings":{"noises":[{"type":"hex","packet":"0d0a","applyTo":"ipv4"}]}}', $e), [['type' => 'hex', 'packet' => '0d0a', 'applyTo' => 'ipv4']]);
t_eq('freedom: base64 без паддинга', is_array(wgnoise_parse('fr', '[{"type":"base64","packet":"YWJj-_"}]', $e)), true);

$wgo = ['tag' => 'warp', 'protocol' => 'wireguard', 'settings' => ['secretKey' => 'x'], 'streamSettings' => ['sockopt' => ['dialerProxy' => 'noise-out']]];
$fro = ['tag' => 'noise-out', 'protocol' => 'freedom', 'settings' => ['domainStrategy' => 'AsIs', 'noises' => [['type' => 'hex', 'packet' => 'ce00', 'delay' => '1-2'], ['type' => 'rand', 'packet' => '23-911', 'delay' => '1-3']]]];
$fnz = $fro['settings']['noises'];
$full = json_encode(['outbounds' => [$wgo, $fro], 'routing' => ['rules' => []]], JSON_PRETTY_PRINT);
t_eq('из конфига: целиком', wgnoise_parse('fr', $full, $e), $fnz);
t_eq('из конфига: кусок "outbounds": […] }', wgnoise_parse('fr', substr(trim($full), 1), $e), $fnz);
t_eq('из конфига: кусок без закрывающей скобки', wgnoise_parse('fr', '"outbounds": ' . json_encode([$wgo, $fro]) . ',', $e), $fnz);
t_eq('из конфига: список outbounds', wgnoise_parse('fr', json_encode([$wgo, $fro]), $e), $fnz);
t_eq('из конфига: массив серверов Happ', wgnoise_parse('fr', json_encode([['remarks' => 'a', 'outbounds' => [$wgo, $fro]]]), $e), $fnz);
t_eq('из конфига: один freedom', wgnoise_parse('fr', json_encode($fro), $e), $fnz);
$wfm = $wgo; $wfm['streamSettings'] = ['finalmask' => ['udp' => [['type' => 'noise', 'settings' => ['noise' => [['rand' => '23-911']]]]]]];
t_eq('из конфига: finalmask у WG', wgnoise_parse('fm', json_encode(['outbounds' => [$wfm]]), $e), ['noise' => [['rand' => '23-911']]]);
t_ok('из конфига: не тот формат — подсказка', wgnoise_parse('fm', $full, $e) === null && mb_strpos($e, '«Старый»') !== false, $e);
t_ok('из конфига: шума нет', wgnoise_parse('fr', json_encode(['outbounds' => [$wgo]]), $e) === null && mb_strpos($e, 'не найден') !== false, $e);
t_eq('сохраняется только шум', wgnoise_text('fr', wgnoise_parse('fr', $full, $e)), json_encode($fnz, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

$bad = [
    ['fm', $P['fr'], 'старом формате'],
    ['fr', $P['fm'], 'нового формата'],
    ['fm', '{"script":"x"}', 'script'],
    ['fm', '[{"rand":"10-20","packet":"YQ=="}]', 'rand и packet'],
    ['fm', '[{"delay":5}]', 'rand или packet'],
    ['fm', '[{"randRange":"0-300","rand":4}]', 'randRange'],
    ['fr', '[{"type":"rand","packet":"10 - 20"}]', 'нужна длина'],
    ['fr', '[{"type":"rand","packet":"0"}]', 'нужна длина'],
    ['fr', '[{"type":"hex","packet":"zz"}]', 'не hex'],
    ['fr', '[{"type":"rand","packet":"5","applyTo":"tcp"}]', 'applyTo'],
    ['fr', '[]', 'непустой'],
    ['fr', '{"a":1}', 'непустой'],
    ['fr', 'нет', 'не JSON'],
    ['fr', json_encode(array_fill(0, 33, ['type' => 'rand', 'packet' => '5'])), '32'],
];
foreach ($bad as [$m, $j, $want]) {
    $r = wgnoise_parse($m, $j, $e);
    t_ok("отказ {$m}: {$want}", $r === null && mb_strpos($e, $want) !== false, $e);
}

squadconf_ensure();
wglease_ensure();
$S1 = '11111111-1111-1111-1111-111111111111';
$S2 = '22222222-2222-2222-2222-222222222222';
t_eq('пул: uuid', wgnoise_pool_ok($S1), true);
t_eq('пул: ручная привязка', wgnoise_pool_ok('__manual__'), true);
t_eq('пул: мусор', wgnoise_pool_ok('x;drop'), false);

$conf = "[Interface]\nPrivateKey = aGVsbG9oZWxsb2hlbGxvaGVsbG9oZWxsb2hlbGxvMDA=\nAddress = 10.8.0.2/32\n\n[Peer]\nPublicKey = d29ybGR3b3JsZHdvcmxkd29ybGR3b3JsZHdvcmxkMDA=\nEndpoint = 203.0.113.5:51820\nAllowedIPs = 0.0.0.0/0\n";
$pn = squadconf_parse_any($conf);
t_eq('конфиг WG разобран', $pn['type'] ?? '', 'wireguard');
squadconf_add([$S1], 'wireguard', 'DE', $conf, json_encode($pn), '');
squadconf_add([$S1, $S2], 'wireguard', 'NL', $conf, json_encode($pn), '');
t_settings(['squad_xray_json_inject' => '1']);

$tpl = ['remarks' => 'srv', 'outbounds' => [
    ['tag' => 'proxy', 'protocol' => 'vless', 'settings' => new stdClass()],
    ['tag' => 'direct', 'protocol' => 'freedom'],
    ['tag' => 'block', 'protocol' => 'blackhole'],
], 'routing' => ['rules' => [['outboundTag' => 'proxy', 'port' => '0-65535']]], 'stats' => new stdClass()];
$arr = json_encode([$tpl], JSON_UNESCAPED_SLASHES);
$_SERVER['HTTP_USER_AGENT'] = 'Happ/3.0';
$prof = squadconf_profile($arr, 'base64', 'Happ/3.0', '');

function nz_run(string $body, array $prof, array $squads): array {
    $cfgs = wglease_select('shortA', '', $squads, $prof);
    $out = squadconf_inject($body, 'base64', $cfgs, $prof, $sent);
    return [$out, json_decode($out, true), $cfgs];
}

[$base, , $cfgs] = nz_run($arr, $prof, [$S1]);
t_eq('выдача: два WG из пула', count($cfgs), 2);
t_eq('выдача: у конфигов отмечен пул', array_column($cfgs, '_pool'), [$S1, $S1]);
t_ok('выдача без шума: WG без streamSettings', strpos($base, 'streamSettings') === false && strpos($base, 'wg-noise') === false);

t_settings(['wgnoise_' . $S1 => json_encode(['m' => 'off', 'j' => $P['fr']])]);
t_eq('шум выключен с текстом: байт в байт', nz_run($arr, $prof, [$S1])[0], $base);
t_settings(['wgnoise_' . $S1 => '']);
t_eq('пустая настройка: байт в байт', nz_run($arr, $prof, [$S1])[0], $base);

t_settings(['wgnoise_' . $S1 => json_encode(['m' => 'fm', 'j' => $P['fm']])]);
[$o, $d] = nz_run($arr, $prof, [$S1]);
$wg = $d[1]['outbounds'][0];
t_eq('finalmask: WG — proxy', [$wg['tag'], $wg['protocol']], ['proxy', 'wireguard']);
t_eq('finalmask: маска noise у WG', $wg['streamSettings'] ?? null, ['finalmask' => ['udp' => [['type' => 'noise', 'settings' => ['noise' => [['rand' => '10-20', 'delay' => '10-16']]]]]]]);
t_eq('finalmask: лишних outbound нет', array_column($d[1]['outbounds'], 'tag'), ['proxy', 'direct', 'block']);
t_ok('finalmask: stats остался объектом', strpos($o, '"stats":{}') !== false);
t_eq('finalmask: исходный сервер не тронут', $d[0], json_decode($arr, true)[0]);

t_settings(['wgnoise_' . $S1 => json_encode(['m' => 'fr', 'j' => $P['fr']])]);
[$o, $d] = nz_run($arr, $prof, [$S1]);
$ob = $d[1]['outbounds'];
$tag = $ob[0]['streamSettings']['sockopt']['dialerProxy'] ?? '';
t_ok('freedom: dialerProxy на тег шума', str_starts_with($tag, 'wg-noise-'));
$fr = array_values(array_filter($ob, fn($x) => ($x['tag'] ?? '') === $tag));
t_eq('freedom: outbound с noises', $fr[0] ?? null, ['tag' => $tag, 'protocol' => 'freedom', 'settings' => ['noises' => [['type' => 'rand', 'packet' => '10-20', 'delay' => '10-16']]]]);
t_eq('freedom: во втором сервере тоже', $d[2]['outbounds'][0]['streamSettings']['sockopt']['dialerProxy'] ?? '', $tag);

$one = json_encode($tpl, JSON_UNESCAPED_SLASHES);
$d = json_decode(nz_run($one, squadconf_profile($one, 'base64', 'Happ/3.0', ''), [$S1])[0], true);
$tags = array_column($d['outbounds'], 'tag');
t_eq('один объект: freedom шума добавлен один раз', count(array_keys($tags, $tag, true)), 1);
t_eq('один объект: оба WG через шум', count(array_filter($d['outbounds'], fn($x) => ($x['streamSettings']['sockopt']['dialerProxy'] ?? '') === $tag)), 2);

t_settings(['wgnoise_' . $S1 => '', 'wgnoise_' . $S2 => json_encode(['m' => 'fm', 'j' => $P['fm']])]);
[, $d, $cfgs] = nz_run($arr, $prof, [$S2]);
t_eq('пул S2: выдан только общий конфиг', array_column($cfgs, 'name'), ['NL']);
t_ok('пул S2: шум S2', isset($d[1]['outbounds'][0]['streamSettings']['finalmask']));
[, $d] = nz_run($arr, $prof, [$S1]);
t_ok('тот же конфиг из S1: без шума S2', !isset($d[1]['outbounds'][0]['streamSettings']) && !isset($d[2]['outbounds'][0]['streamSettings']));

t_eq('без пула: шум по сквадам конфига', wgnoise_for_cfg(['type' => 'wireguard', 'squads' => json_encode([$S1, $S2])])['m'] ?? null, 'fm');
t_eq('AWG шум не получает', wgnoise_for_cfg(['type' => 'amneziawg', '_pool' => $S2]), null);

$links = base64_encode("vless://a@b:1#x");
$lp = squadconf_profile($links, 'base64', 'Happ/3.0', '');
$out = squadconf_inject($links, 'base64', wglease_select('shortA', '', [$S2], $lp), $lp, $sent);
t_ok('base64: шум не попадает', strpos((string) base64_decode($out), 'noise') === false && strpos((string) base64_decode($out), 'wireguard://') !== false);

squadconf_add(['__manual__'], 'wireguard', 'MAN', $conf, json_encode($pn), '');
$state = $tt_root . '/data/mock_state.json';
file_put_contents($state, json_encode(['squads' => [['uuid' => $S1, 'name' => 'Germany', 'members' => 3], ['uuid' => $S2, 'name' => 'Nether', 'members' => 2]]]));
$panel = t_serve($tt_root . '/tools', $tt_root . '/tools/mock_panel.php', ['MOCK_STATE' => $state, 'MOCK_LOG' => $tt_root . '/data/mock_panel.log']);
t_settings(['sub_source' => 'panel', 'remnawave_url' => $panel, 'remnawave_api_key' => 'test-token', 'geoip_auto' => '0', 'update_state' => json_encode(['checked_at' => time()])]);
$mw  = t_serve($tt_root, $tt_root . '/tools/tests/_router.php', [], ['opcache.enable' => '0']);
$jar = t_admin_login($mw);
[$c, , $page] = t_http('GET', $mw . '/admin/index.php?tab=wg_pool', [], null, $jar);
t_ok('вкладка WG: открывается', $c === 200 && strpos($page, '</html>') !== false, 'код ' . $c);
t_eq('вкладка WG: кнопка шума у пулов и ручной привязки', substr_count($page, 'class="wb sm wgx-nzb'), 3);
t_ok('вкладка WG: включённый шум подсвечен', preg_match('~wgx-nzb on" data-nz="' . $S2 . '"~', $page) === 1);
t_ok('вкладка WG: окно шума и пресеты', strpos($page, 'id="wgxNzModal"') !== false && strpos($page, 'var NZP = {"fm"') !== false);
preg_match('~name="csrf" value="([0-9a-f]+)"~', $page, $m);
$post = static function (array $f) use ($mw, $jar, $m): array {
    [, , $b] = t_http('POST', $mw . '/admin/index.php', ['content-type' => 'application/x-www-form-urlencoded'], http_build_query($f + ['csrf' => $m[1], 'action' => 'save_pool_noise', 'xhr' => '1']), $jar);
    return json_decode($b, true) ?: [];
};
$r = $post(['pool' => $S1, 'nz_mode' => 'fr', 'nz_json' => $P['fr']]);
unset($GLOBALS['submw_settings_cache']);
t_ok('сохранение: старый формат', !empty($r['ok']) && wgnoise_stored($S1)['m'] === 'fr' && json_decode(wgnoise_stored($S1)['j'], true) === json_decode($P['fr'], true), json_encode($r, JSON_UNESCAPED_UNICODE));
$r = $post(['pool' => $S2, 'nz_mode' => 'fr', 'nz_json' => $full]);
unset($GLOBALS['submw_settings_cache']);
t_ok('сохранение: конфиг целиком — в настройке только шум', !empty($r['ok']) && json_decode(wgnoise_stored($S2)['j'], true) === $fnz, json_encode($r, JSON_UNESCAPED_UNICODE));
$post(['pool' => $S2, 'nz_mode' => 'fm', 'nz_json' => $P['fm']]);
$r = $post(['pool' => $S1, 'nz_mode' => 'fm', 'nz_json' => $P['fr']]);
unset($GLOBALS['submw_settings_cache']);
t_ok('сохранение: кривой JSON — ошибка, прежнее на месте', empty($r['ok']) && mb_strpos((string) ($r['error'] ?? ''), 'старом формате') !== false && wgnoise_stored($S1)['m'] === 'fr', json_encode($r, JSON_UNESCAPED_UNICODE));
$r = $post(['pool' => '../x', 'nz_mode' => 'fm', 'nz_json' => $P['fm']]);
t_ok('сохранение: чужой пул — отказ', empty($r['ok']));
$r = $post(['pool' => '__manual__', 'nz_mode' => 'off', 'nz_json' => '']);
unset($GLOBALS['submw_settings_cache']);
t_ok('сохранение: выключение пустым — настройка пуста', !empty($r['ok']) && setting('wgnoise___manual__', 'x') === '');
[$c] = t_http('POST', $mw . '/admin/index.php', ['content-type' => 'application/x-www-form-urlencoded'], http_build_query(['csrf' => 'bad', 'action' => 'save_pool_noise', 'pool' => $S2, 'nz_mode' => 'off', 'xhr' => '1']), $jar);
unset($GLOBALS['submw_settings_cache']);
t_ok('сохранение: без CSRF не меняет', wgnoise_stored($S2)['m'] === 'fm');

t_done();
