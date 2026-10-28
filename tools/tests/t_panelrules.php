<?php
declare(strict_types=1);

require __DIR__ . '/_boot.php';

$C = static fn(string $h, string $op, string $v, bool $cs = true) => ['headerName' => $h, 'operator' => $op, 'value' => $v, 'caseSensitive' => $cs];
$R = static fn(string $n, array $cond, string $t, ?array $mods = null, string $op = 'AND') => ['name' => $n, 'enabled' => true, 'operator' => $op, 'conditions' => $cond, 'responseType' => $t] + ($mods !== null ? ['responseModifications' => $mods] : []);
$cfg = static fn(array $rules, array $settings = []) => ['version' => '1', 'settings' => $settings, 'rules' => $rules];
$codes = static fn(array $list) => array_values(array_unique(array_map(static fn($x) => $x['code'], $list)));
$ctx = ['ver' => '3.4.7', 'custom_remarks' => false, 'serve_json' => true, 'hwid_on' => true];

echo "— нормализация\n";
$u = [];
$n = pr_normalize(['version' => '1', 'foo' => 1, 'rules' => [['name' => 'A', 'enabled' => 'yes', 'operator' => 'and', 'conditions' => [['headerName' => 'User-Agent', 'operator' => 'starts_with', 'value' => 'Happ/']], 'responseType' => 'xray_json', 'responseModification' => ['x' => 1], 'responseModifications' => ['headers' => [['key' => 'a', 'value' => 'b']], 'zzz' => 1]]]], $u);
t_eq('незнакомые ключи собраны', $u, ['foo', 'rules[0].responseModification', 'rules[0].responseModifications.zzz']);
t_eq('операторы и типы приведены к верхнему регистру', [$n['rules'][0]['operator'], $n['rules'][0]['conditions'][0]['operator'], $n['rules'][0]['responseType']], ['AND', 'STARTS_WITH', 'XRAY_JSON']);
t_eq('enabled стал bool', $n['rules'][0]['enabled'], true);
$fp = pr_for_panel(pr_normalize($cfg([$R('A', [], 'XRAY_BASE64', ['headers' => [], 'excludeHostsByTags' => [], 'subscriptionTemplate' => ''])]), $u));
t_ok('пустые модификации не уходят в панель', !isset($fp['rules'][0]['responseModifications']) && !isset($fp['settings']));

echo "— валидатор: ошибки\n";
$v = pr_validate(pr_normalize($cfg([$R('', [$C('user agent', 'FOO', '')], 'NOPE')]), $u), $ctx);
t_eq('пустое имя, тип, плохой заголовок, оператор, значение', $codes($v['errors']), ['name', 'type', 'cond_hdr', 'cond_op', 'cond_val']);
$v = pr_validate(pr_normalize($cfg([$R('H', [], 'XRAY_JSON', ['headers' => [['key' => 'profile-title', 'value' => 'Мой VPN'], ['key' => 'x-a', 'value' => "a\nb"], ['key' => 'x-b', 'value' => 'ok']]])]), $u), $ctx);
t_eq('кириллица и перевод строки в заголовках — ошибка', $codes($v['errors']), ['hdr_ascii']);
t_eq('у profile-title есть исправление base64', $v['errors'][0]['fix'], ['op' => 'b64', 'rule' => 0, 'hdr' => 0]);
t_eq('у x-a исправления нет', $v['errors'][1]['fix'], null);
t_eq('base64 кодирует с префиксом', pr_b64_value('Мой VPN'), 'base64:' . base64_encode('Мой VPN'));
$v = pr_validate(pr_normalize($cfg([$R('T', [], 'XRAY_JSON', ['excludeHostsByTags' => ['ok_1', 'TOO', str_repeat('A', 37)], 'encryption' => ['method' => 'age2', 'key' => ''], 'subscriptionTemplate' => ' '])]), $u), $ctx);
t_eq('теги, шифрование, пустой шаблон', $codes($v['errors']), ['tpl', 'enc', 'tags']);
t_eq('первая ошибка тега — про строчные буквы', substr_count(json_encode($v['errors'], JSON_UNESCAPED_UNICODE), 'tags'), 2);

echo "— валидатор: предупреждения\n";
$v = pr_validate(pr_normalize($cfg([])), $ctx);
t_eq('пустой список', $codes($v['warnings']), ['empty']);
$v = pr_validate(pr_normalize($cfg([$R('A', [$C('user-agent', 'STARTS_WITH', 'Happ/')], 'XRAY_JSON')])), $ctx);
t_eq('нет правила всем', $codes($v['warnings']), ['no_catchall']);
$v = pr_validate(pr_normalize($cfg([$R('All', [], 'XRAY_BASE64'), $R('Late', [$C('user-agent', 'CONTAINS', 'x')], 'BLOCK'), $R('All', [], 'BLOCK')])), $ctx);
t_eq('недостижимые и дубль имени', $codes($v['warnings']), ['dup', 'unreachable']);
t_eq('недостижимые перечислены', strpos($v['warnings'][1]['msg'], '«Late», «All»') !== false, true);
$v = pr_validate(pr_normalize($cfg([$R('S', [$C('user-agent', 'REGEX', '^stash\D', false)], 'STASH'), $R('Z', [], 'XRAY_BASE64')])), $ctx);
t_eq('ловушка регистра в regex', $codes($v['warnings']), ['cond_re_ci']);
t_eq('исправление — учитывать регистр', $v['warnings'][0]['fix'], ['op' => 'cs', 'rule' => 0, 'cond' => 0]);
$v = pr_validate(pr_normalize($cfg([$R('F', [$C('user-agent', 'REGEX', '(?i)happ')], 'XRAY_JSON'), $R('Z', [], 'XRAY_BASE64')])), $ctx);
t_eq('встроенный флаг (?i)', $codes($v['warnings']), ['cond_re_flag']);
$v = pr_validate(pr_normalize($cfg([$R('M', [], 'XRAY_BASE64', ['respondWithRemarks' => ['Работы', '{"f":1}'], 'disableHwidCheck' => true, 'ignoreServeJsonAtBaseSubscription' => true])])), ['ver' => '3.1.2', 'custom_remarks' => false, 'serve_json' => false, 'hwid_on' => false]);
t_eq('старая панель, ремарки выключены, настройки панели', $codes($v['warnings']), ['ver', 'remarks_off', 'remarks_raw', 'serve_off', 'hwid_off']);
$v = pr_validate(pr_normalize($cfg([$R('D', [], 'BLOCK', ['headers' => [['key' => 'x', 'value' => '1']], 'applyHeadersToEnd' => true, 'subscriptionTemplate' => 'G'])])), $ctx);
t_eq('отказ с лишними модификациями', $codes($v['warnings']), ['deny_mods', 'deny_end']);
$v = pr_validate(pr_normalize($cfg([$R('H', [], 'XRAY_JSON', ['headers' => [['key' => 'subscription-userinfo', 'value' => 'x'], ['key' => 'Content-Type', 'value' => 'y'], ['key' => 'x-u', 'value' => '{{USERNAME}}']], 'applyHeadersToEnd' => true])])), $ctx);
t_eq('заголовки панели, content-type, переменные', $codes($v['warnings']), ['hdr_end', 'hdr_ct', 'hdr_var']);
$v = pr_validate(pr_normalize($cfg([$R('X', [], 'XRAY_BASE64')], ['disableSubscriptionAccessByPath' => true])), ['ver' => '2.6.1']);
t_eq('закрытие путей на 2.6.1', $codes($v['warnings']), ['ver']);
t_eq('версия панели неизвестна — предупреждений о версии нет', $codes(pr_validate(pr_normalize($cfg([$R('M', [], 'XRAY_BASE64', ['respondWithRemarks' => ['a']])])), ['ver' => ''])['warnings']), []);

echo "— сравнение снимков\n";
$a = $cfg([$R('A', [], 'XRAY_BASE64'), $R('B', [$C('user-agent', 'CONTAINS', 'x')], 'MIHOMO'), $R('Old', [], 'BLOCK')]);
$b = $cfg([$R('New', [], 'STASH'), $R('B', [$C('user-agent', 'CONTAINS', 'y')], 'XRAY_JSON', ['headers' => [['key' => 'a', 'value' => 'b']]]), $R('A', [], 'XRAY_BASE64')]);
$d = pr_diff($a, $b);
t_eq('добавлено / удалено', [$d['added'], $d['removed']], [['New'], ['Old']]);
t_eq('изменено B: условия, тип, заголовки', $d['changed'][0], ['name' => 'B', 'fields' => ['условия', 'тип ответа: Mihomo → Полный конфиг Xray', 'заголовки']]);
t_eq('A только сдвинулось', $d['changed'][1]['fields'], ['позиция 1 → 3']);
t_eq('одинаковые — пусто', pr_diff($a, $a), ['added' => [], 'removed' => [], 'changed' => [], 'settings' => false]);

echo "— мок-панель: чтение, снимки, отправка, тестер, карта\n";
$state = $tt_root . '/data/mock_state.json';
$mlog  = $tt_root . '/data/mock.log';
$base = [
    'users' => ['AbCdEfGhIjKlMnOp' => ['status' => 'ACTIVE', 'username' => 'ivan']],
    'hosts' => [['remark' => 'NL', 'address' => 'a.example.net', 'nodes' => [], 'tags' => ['EU', 'TEST']], ['remark' => 'DE', 'address' => 'b.example.net', 'nodes' => [], 'tags' => ['EU']]],
    'hwid'  => ['enabled' => true, 'limit' => 2],
    'version' => '3.4.7', 'custom_remarks' => false, 'serve_json' => true,
];
$mock = static function (array $over = []) use ($state, $base) { file_put_contents($state, json_encode(array_replace($base, $over), JSON_UNESCAPED_UNICODE)); };
$mock();
$panel = t_serve($tt_root . '/tools', $tt_root . '/tools/mock_panel.php', ['MOCK_STATE' => $state, 'MOCK_LOG' => $mlog]);
t_settings(['sub_source' => 'panel', 'remnawave_url' => $panel, 'remnawave_api_key' => 'test-token', 'geoip_auto' => '0']);
set_setting('panelmeta_json', json_encode(['version' => '3.4.7']));
$hits = static function () use ($mlog) { $out = []; foreach (preg_split('~\R~', trim((string) @file_get_contents($mlog))) ?: [] as $l) if ($l !== '') $out[$l] = ($out[$l] ?? 0) + 1; return $out; };
$cut = static function () use ($mlog) { @file_put_contents($mlog, ''); };

$e = '';
$st = pr_state($e);
t_eq('прочитали настройки панели', [$e, $st['uuid'], $st['n'], $st['serve_json'], $st['custom_remarks']], ['', 'a1b2c3d4-0000-4000-8000-000000000001', 6, true, false]);
t_eq('снимок subset для карточки пользователя обновился', remnawave_hwid_settings($e, 600)['enabled'] ?? null, true);
t_eq('теги хостов с числом серверов', pr_tags($e), [['tag' => 'EU', 'n' => 2], ['tag' => 'TEST', 'n' => 1]]);
t_eq('шаблоны', array_map(static fn($t) => $t['name'] . '/' . $t['type'], pr_templates($e)), ['Default/XRAY_JSON', 'Default/MIHOMO', 'Gaming/XRAY_JSON']);
t_eq('пользователь по имени', pr_user_find('ivan', $e)['short'] ?? null, 'AbCdEfGhIjKlMnOp');
t_eq('пользователь по shortUuid', pr_user_find('AbCdEfGhIjKlMnOp', $e)['username'] ?? null, 'ivan');

$draft = pr_normalize($cfg([$R('Happ', [$C('user-agent', 'STARTS_WITH', 'Happ/')], 'XRAY_JSON', ['subscriptionTemplate' => 'Gaming']), $R('Остальные', [], 'XRAY_BASE64')]), $u);
$r = pr_push($draft, 'wrong-hash');
t_eq('расхождение хеша — конфликт, в панель не ушло', [$r['ok'], $r['conflict'] ?? false, $hits()['PATCH /api/subscription-settings'] ?? 0], [false, true, 0]);
$bad = $draft; $bad['rules'][0]['responseModifications']['headers'] = [['key' => 'profile-title', 'value' => 'Привет']];
$r = pr_push($bad, $st['hash']);
t_eq('ошибки черновика — не отправляем', [$r['ok'], $codes($r['errors'])], [false, ['hdr_ascii']]);
t_eq('снимков пока нет', pr_snap_list(), []);
$cut();
$r = pr_push($draft, $st['hash']);
t_eq('отправка прошла', [$r['ok'], $r['state']['n'] ?? null], [true, 2]);
t_eq('PATCH ушёл один раз', $hits()['PATCH /api/subscription-settings'] ?? 0, 1);
$snaps = pr_snap_list();
t_eq('автоснимок старых правил перед отправкой', [count($snaps), $snaps[0]['kind'], $snaps[0]['note'], $snaps[0]['n'], $snaps[0]['hash'] === $st['hash']], [1, 'auto', 'перед отправкой', 6, true]);
$s2 = pr_state($e);
t_eq('панель хранит новое', [$s2['n'], $s2['rules']['rules'][0]['responseModifications']['subscriptionTemplate'] ?? null], [2, 'Gaming']);
t_ok('updatedAt поменялся', $s2['upd'] !== $st['upd']);
$snap = pr_snap_get($snaps[0]['id']);
$d = pr_diff($snap['rules'], $s2['rules']);
t_eq('разница снимок → панель', [count($d['added']), count($d['removed'])], [2, 6]);
// Восстановление: снимок уходит в панель, текущее тоже сохраняется.
$r = pr_push(pr_normalize($snap['rules'], $u), $s2['hash'], 'restore');
t_eq('восстановили 6 правил', [$r['ok'], pr_state($e)['n']], [true, 6]);
t_eq('перед восстановлением — снимок двух правил', [pr_snap_list()[0]['note'], pr_snap_list()[0]['n']], ['перед восстановлением', 2]);
$mock(['srr_forbid' => true]);
$r = pr_push($draft, pr_state($e)['hash']);
t_eq('403 панели — понятная ошибка', [$r['ok'], $r['code'] ?? 0], [false, 403]);
$mock();

$cut();
$t = pr_test($draft, ['user-agent' => 'Happ/3.4.1 Android', 'x-device-os' => 'Android'], $e);
t_eq('тестер: Happ попал в первое правило', [$e, $t['matched'], $t['idx'], $t['type']], ['', true, 0, 'XRAY_JSON']);
t_eq('тестер видит наши заголовки, Accept не json', [$t['input']['user-agent'] ?? null, $t['input']['accept'] ?? null, $t['input']['x-device-os'] ?? null], ['Happ/3.4.1 Android', '*/*', 'Android']);
$t = pr_test($draft, ['user-agent' => 'v2rayNG/1.10.16'], $e);
t_eq('тестер: v2rayNG — в «Остальные»', [$t['idx'], $t['type']], [1, 'XRAY_BASE64']);
$t = pr_test(pr_normalize($cfg([$R('Only', [$C('user-agent', 'STARTS_WITH', 'Happ/')], 'XRAY_JSON')]), $u), ['user-agent' => 'Stash/3.1.0'], $e);
t_eq('тестер: ничего не совпало', [$t['matched'], $t['idx']], [false, null]);
$t = pr_test(pr_normalize($cfg([$R('Bad', [$C('user-agent', 'REGEX', '(')], 'XRAY_JSON')]), $u), ['user-agent' => 'x'], $e);
t_eq('тестер: битый regex — ошибка панели с текстом', [$t, strpos($e, 'Панель отклонила черновик') === 0], [null, true]);
t_eq('shortUuid пробрасывается служебным заголовком', pr_test(pr_normalize($cfg([$R('U', [$C('x-remnawave-injected-short-uuid', 'EQUALS', 'AbCdEfGhIjKlMnOp')], 'STASH'), $R('Z', [], 'XRAY_BASE64')]), $u), ['user-agent' => 'Happ/1', 'x-remnawave-injected-short-uuid' => 'AbCdEfGhIjKlMnOp'], $e)['idx'], 0);
$h = $hits();
t_eq('каждая проверка — один запрос к тестеру', $h['POST /api/system/testers/srr-matcher'] ?? 0, 5);

// Лог запросов → группы клиентов → карта через тестер.
$p = db();
$ins = $p->prepare('INSERT INTO request_log (ts, ip, short_uuid, path, user_agent, decision, hwid, is_app, meta) VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?)');
$now = gmdate('Y-m-d H:i:s');
$rows = [
    ['Happ/3.4.1 Android', 'hw1', json_encode(['dv' => ['o' => 'Android', 'm' => 'Pixel']])], ['Happ/3.4.1 Android', 'hw2', json_encode(['dv' => ['o' => 'Android']])], ['Happ/3.3.0 Android', null, json_encode(['dv' => ['o' => 'Android']])],
    ['Happ/3.4.0 (Windows)', 'hw3', json_encode(['dv' => ['o' => 'Windows']])],
    ['v2rayNG/1.10.16', null, null], ['v2rayNG/1.9.42', null, null], ['v2rayNG/1.10.16', null, null], ['v2rayNG/1.10.16', null, null],
    ['Stash/3.1.0 iOS', null, null],
    ['Mozilla/5.0 (Windows NT 10.0) Chrome/130', null, null],
];
foreach ($rows as [$ua, $hw, $meta]) $ins->execute([$now, '1.2.3.4', 'AbCdEfGhIjKlMnOp', 'sub/AbCdEfGhIjKlMnOp', $ua, 'normal', $hw, $meta]);
$ins->execute([$now, '1.2.3.4', 'AbCdEfGhIjKlMnOp', 'sub/AbCdEfGhIjKlMnOp/json', 'v2rayN/7.13.8', 'normal', null, null]);
$cl = pr_clients(168);
$names = array_map(static fn($g) => $g['name'] . '|' . $g['os'] . '|' . $g['n'], $cl['groups']);
t_eq('группы приложение × ОС, браузер последним', $names, ['v2rayNG||4', 'Happ|android|3', 'Happ|desktop|1', 'Stash||1', 'v2rayN||1', 'Браузер||0']);
t_eq('у группы — настоящий UA и заголовки устройства', [$cl['groups'][1]['ua'], $cl['groups'][1]['dv']['o'] ?? null, $cl['groups'][1]['hwid'], $cl['groups'][1]['vers']], ['Happ/3.4.1 Android', 'Android', true, ['3.4.1', '3.3.0']]);
t_eq('всего запросов без браузера', $cl['total'], 10);
$pres = pr_header_presence(168);
t_eq('кто присылает ОС', [$pres['os']['yes'], $pres['os']['no']], [['Happ'], ['v2rayNG', 'Stash', 'v2rayN']]);
t_eq('статистика прямых ссылок', [pr_paths_stat(30)['n'], pr_paths_stat(30)['apps'][0]['name'] ?? null], [1, 'v2rayN']);
$cut();
$map = pr_map($draft, 168, $e);
t_eq('карта: Happ поймал 4, остальные 6, браузер — тоже в «Остальные»', [$e, $map['by_rule'][0]['n'], $map['by_rule'][1]['n'], count($map['by_rule'][1]['groups']), $map['none_n']], ['', 4, 6, 4, 0]);
t_eq('до второй ступени доходит 6', $map['reach'], [0 => 10, 1 => 6]);
$tester = static function () use ($hits) { $n = 0; foreach ($hits() as $k => $c) if (strpos($k, 'POST /api/system/testers/srr-matcher') === 0) $n += $c; return $n; };
t_eq('одна проверка на группу', $tester(), 6);
$cut();
pr_map($draft, 168, $e);
t_eq('повтор — из кэша, без запросов', $tester(), 0);
$map2 = pr_map(pr_normalize($cfg([$R('Only', [$C('user-agent', 'STARTS_WITH', 'Happ/')], 'XRAY_JSON')]), $u), 168, $e);
t_eq('без правила «всем» — кто получит 403', [$map2['none_n'], array_map(static fn($g) => $g['name'], $map2['none'])], [6, ['v2rayNG', 'Stash', 'v2rayN', 'Браузер']]);
$mock(['srr_tester_forbid' => true]);
t_eq('нет права на тестер — понятная ошибка', [pr_map($draft, 168, $e, 'force'), $e], [null, 'У API-токена нет права system:test-srr-matcher']);
$mock();

t_done();
