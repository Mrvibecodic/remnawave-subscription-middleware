<?php
declare(strict_types=1);

require __DIR__ . '/_boot.php';

// Формат клиента и тела
$_SERVER['HTTP_USER_AGENT'] = 'FlClash/0.8';
$_SERVER['QUERY_STRING'] = '';
t_eq('формат: Clash-клиент по UA', detect_client_format(), 'clash');
$_SERVER['HTTP_USER_AGENT'] = 'Happ/3.0';
t_eq('формат: прочие — base64', detect_client_format(), 'base64');
$_SERVER['QUERY_STRING'] = 'target=clash';
t_eq('формат: clash в строке запроса', detect_client_format(), 'clash');
$_SERVER['QUERY_STRING'] = '';
t_eq('тело: YAML распознаётся как clash', detect_body_format("mixed-port: 7890\nproxies:\n", 'base64'), 'clash');
t_eq('тело: JSON — base64-ветка', detect_body_format('{"outbounds":[]}', 'clash'), 'base64');
t_eq('тело: base64 со ссылками при clash-клиенте', detect_body_format(base64_encode("vless://a@b:1#x"), 'clash'), 'base64');
t_eq('тело: HTML не меняет формат', detect_body_format('<html>', 'clash'), 'clash');
t_eq('clash: одинаковые имена разводятся', clash_unique(['a', 'a', 'b']), ['a', "a\u{200B}", 'b']);
t_eq('yaml: кавычки и слеши экранируются', yaml_q('a"b\\c'), '"a\\"b\\\\c"');
$body = (string) base64_decode(build_override_body('blocked', 'base64'), true);
t_ok('заглушка base64: строки-ремарки из настроек', substr_count($body, 'vless://00000000-0000-0000-0000-000000000000@0.0.0.0:1') === count(get_blocked_remarks()));
t_ok('заглушка clash: группа «Информация»', strpos(build_override_body('blocked', 'clash'), "proxy-groups:\n  - name: Информация") !== false);

// Префикс подписки и HWID из UA
t_settings(['sub_prefix_enabled' => '1', 'sub_prefix' => '/sub/']);
t_eq('префикс: снимается', sub_prefix_strip('sub/AbC'), ['AbC', true]);
t_eq('префикс: регистр не важен', sub_prefix_strip('SUB/AbC'), ['AbC', true]);
t_eq('префикс: ровно префикс', sub_prefix_strip('sub'), ['', true]);
t_eq('префикс: похожий сегмент не снимается', sub_prefix_strip('subway/AbC'), ['subway/AbC', false]);
t_settings(['sub_prefix_enabled' => '0']);
t_eq('префикс выключен: путь как есть', sub_prefix_strip('sub/AbC'), ['sub/AbC', false]);
t_eq('UA: x-hwid достаётся', ua_hwid_extract('Happ/1 (x-hwid=ABC123; os=ios)', 'x-hwid'), 'ABC123');
t_eq('UA: нет ключа — пусто', ua_hwid_extract('Happ/1', 'x-hwid'), '');

// Срок после истечения
t_settings(['expired_grace_days' => '7']);
$now = 2000000000;
t_eq('истёк 6 дней назад — ещё в окне', expired_grace_passed($now - 6 * 86400, null, $now), false);
t_eq('истёк 8 дней назад — окно прошло', expired_grace_passed($now - 8 * 86400, null, $now), true);
t_eq('без срока — по дате создания', expired_grace_passed(null, gmdate('Y-m-d H:i:s', $now - 9 * 86400), $now), true);
t_settings(['expired_grace_days' => '0']);
t_eq('окно 0 — никогда', expired_grace_passed($now - 99 * 86400, null, $now), false);

// Правила ответа
t_eq('правило: EQUALS', rules_op_match('Happ', 'EQUALS', 'Happ', false), true);
t_eq('правило: EQUALS без учёта регистра', rules_op_match('happ', 'EQUALS', 'HAPP', true), true);
t_eq('правило: CONTAINS пустой иглой — нет', rules_op_match('x', 'CONTAINS', '', false), false);
t_eq('правило: NOT_CONTAINS', rules_op_match('Streisand', 'NOT_CONTAINS', 'Happ', false), true);
t_eq('правило: STARTS_WITH', rules_op_match('v2rayNG/1.9', 'STARTS_WITH', 'v2rayNG', false), true);
t_eq('правило: ENDS_WITH', rules_op_match('a.example.com', 'ENDS_WITH', '.com', false), true);
t_eq('правило: REGEX', rules_op_match('Hiddify/2.5.7', 'REGEX', '^Hiddify/2\.', false), true);
t_eq('правило: NOT_REGEX', rules_op_match('Hiddify/3.0', 'NOT_REGEX', '^Hiddify/2\.', false), true);
t_eq('правило: нет заголовка — не совпало', rules_op_match(null, 'NOT_CONTAINS', 'x', false), false);
$rule = ['operator' => 'AND', 'conditions' => [['header' => 'user-agent', 'op' => 'CONTAINS', 'value' => 'Happ', 'ci' => true], ['header' => 'x-hwid', 'op' => 'EQUALS', 'value' => '1', 'ci' => false]]];
t_eq('правило AND: оба условия', rule_matches($rule, ['User-Agent' => 'happ/3', 'X-HWID' => '1']), true);
t_eq('правило AND: одно не сошлось', rule_matches($rule, ['User-Agent' => 'happ/3', 'X-HWID' => '2']), false);
$rule['operator'] = 'OR';
t_eq('правило OR: одного хватает', rule_matches($rule, ['User-Agent' => 'other', 'X-HWID' => '1']), true);
t_eq('правило без условий — всегда', rule_matches(['operator' => 'AND', 'conditions' => []]), true);

// Версии клиентов
t_eq('версия: v и хвост сборки', clientver_norm('v1.10.2-beta.3'), '1.10.2');
t_eq('версия: четыре части — три', clientver_norm('2.5.7.123'), '2.5.7');
t_eq('версия: номер сборки', clientver_is_build('5678'), true);
t_eq('версия: равна', clientver_diff('1.2.3', 'v1.2.3'), 'ok');
t_eq('версия: новее эталона', clientver_diff('1.3.0', '1.2.9'), 'ahead');
t_eq('версия: отстаёт на патч', clientver_diff('1.2.1', '1.2.3'), 'patch');
t_eq('версия: отстаёт на минор', clientver_diff('1.1.9', '1.2.0'), 'minor');
t_eq('версия: непонятная', clientver_diff('abc', '1.2.0'), 'unknown');

// VLESS
$r = vless_parse('vless://11111111-2222-4333-8444-555555555555@ex.com:443?type=tcp&security=reality&pbk=PUB&sid=ab&sni=www.example.com&flow=xtls-rprx-vision#%D0%A3%D0%B7%D0%B5%D0%BB');
t_ok('vless: reality разобран', $r['ok'] && $r['security'] === 'reality' && $r['pbk'] === 'PUB' && $r['sni'] === 'www.example.com' && $r['port'] === 443);
t_eq('vless: имя из #', $r['remark'], 'Узел');
t_eq('vless: fp у reality по умолчанию chrome', $r['fp'], 'chrome');
$r = vless_parse('vless://11111111-2222-4333-8444-555555555555@[2001:db8::1]:8443?type=ws&path=%2Fws&host=cdn.example.com&security=tls');
t_ok('vless: IPv6 и ws', $r['ok'] && $r['host'] === '2001:db8::1' && $r['port'] === 8443 && $r['net'] === 'ws' && $r['path'] === '/ws' && $r['hostHeader'] === 'cdn.example.com');
$r = vless_parse('vless://11111111-2222-4333-8444-555555555555@ex.com:443?security=reality');
t_ok('vless: reality без pbk — нерабочий', !$r['ok'] && in_array('Reality без pbk (publicKey) — узел нерабочий.', $r['warnings'], true));
t_ok('vless: не vless', !vless_parse('vmess://abc')['ok']);
$r = vless_parse('vless://11111111-2222-4333-8444-555555555555@ex.com:443?type=tcp&security=tls&sni=ex.com');
$c = vless_to_clash($r, 'N');
t_ok('vless → clash: тип, сервер, tls', is_string($c) && strpos($c, '  - name: "N"') === 0 && strpos($c, "\n    type: vless\n    server: ex.com\n    port: 443\n") !== false && strpos($c, "\n    tls: true\n") !== false, (string) $c);
$x = vless_to_xray($r, 'T');
t_ok('vless → xray: протокол и адрес', is_array($x) && ($x['protocol'] ?? '') === 'vless' && ($x['settings']['vnext'][0]['address'] ?? '') === 'ex.com', var_export($x, true));

t_done();
