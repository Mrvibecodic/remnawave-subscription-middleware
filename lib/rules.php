<?php

function rules_protected_headers() {
    return ['content-length', 'content-encoding', 'transfer-encoding', 'connection',
            'host', 'content-type', 'subscription-userinfo', 'content-disposition'];
}

function rules_header_ok($name) {
    if (!preg_match('/^[A-Za-z0-9-]+$/', (string) $name)) return false;
    return !in_array(strtolower($name), rules_protected_headers(), true);
}

function rules_b64_headers() {
    return ['profile-title', 'announce'];
}

function rules_encode_value($name, $value) {
    if ($value === '' || strpos($value, 'base64:') === 0) return $value;
    if (!preg_match('/[^\x20-\x7E]/', $value)) return $value;
    if (!in_array(strtolower($name), rules_b64_headers(), true)) return $value;
    return 'base64:' . base64_encode($value);
}

function rules_client_catalog() {
    return [
        ['key' => 'koala',        'label' => 'Koala Clash',         'ua' => 'koala',        'group' => 'popular'],
        ['key' => 'flclashx',     'label' => 'FlClashX',            'ua' => 'flclashx',     'group' => 'popular'],
        ['key' => 'happ',         'label' => 'Happ',                'ua' => 'happ',         'group' => 'popular'],
        ['key' => 'incy',         'label' => 'INCY',                'ua' => 'incy',         'group' => 'popular'],
        ['key' => 'flclash',      'label' => 'FlClash',             'ua' => 'flclash',      'group' => 'other'],
        ['key' => 'clashverge',   'label' => 'Clash Verge',         'ua' => 'verge',        'group' => 'other'],
        ['key' => 'clashmeta',    'label' => 'Clash Meta / Mihomo', 'ua' => 'mihomo',       'group' => 'other'],
        ['key' => 'v2rayng',      'label' => 'v2rayNG',             'ua' => 'v2rayng',      'group' => 'other'],
        ['key' => 'v2rayn',       'label' => 'v2rayN',              'ua' => 'v2rayn',       'group' => 'other'],
        ['key' => 'streisand',    'label' => 'Streisand',           'ua' => 'streisand',    'group' => 'other'],
        ['key' => 'nekobox',      'label' => 'NekoBox',             'ua' => 'nekobox',      'group' => 'other'],
        ['key' => 'singbox',      'label' => 'sing-box',            'ua' => 'sing-box',     'group' => 'other'],
        ['key' => 'karing',       'label' => 'Karing',              'ua' => 'karing',       'group' => 'other'],
        ['key' => 'stash',        'label' => 'Stash',               'ua' => 'stash',        'group' => 'other'],
        ['key' => 'shadowrocket', 'label' => 'Shadowrocket',        'ua' => 'shadowrocket', 'group' => 'other'],
        ['key' => 'hiddify',      'label' => 'Hiddify',             'ua' => 'hiddify',      'group' => 'other'],
    ];
}

function app_headers_catalog() {
    return [
        'Happ — стандарт (и большинство клиентов)' => [
            ['name' => 'profile-title',           'note' => 'Имя профиля подписки (до 25 символов; кириллица — авто base64).', 'ex' => 'My VPN'],
            ['name' => 'support-url',             'note' => 'Ссылка на поддержку (для t.me — иконка Telegram).', 'ex' => 'https://t.me/your_support'],
            ['name' => 'announce',                'note' => 'Анонс/объявление в клиенте (кириллица — авто base64).', 'ex' => 'Профилактика до 18:00'],
            ['name' => 'profile-update-interval', 'note' => 'Интервал авто-обновления подписки, часов (у Koala — минуты!).', 'ex' => '12'],
            ['name' => 'profile-web-page-url',    'note' => 'Ссылка на веб-страницу подписки.', 'ex' => 'https://example.com'],
            ['name' => 'routing-enable',          'note' => '0 — выключить routing в приложении.', 'ex' => '0'],
        ],
        'Happ — премиум (нужен providerid)' => [
            ['name' => 'providerid',              'note' => 'Provider ID с happ-proxy.com — без него остальной премиум не работает.', 'ex' => '5f3a9c2e10b8'],
            ['name' => 'change-user-agent',       'note' => 'Свой User-Agent при запросе подписки.', 'ex' => 'Mozilla/5.0 ... Chrome/135.0'],
            ['name' => 'sub-info-text',           'note' => 'Текст инфо-блока в приложении (до 200 симв.).', 'ex' => 'Спасибо, что с нами!'],
            ['name' => 'sub-info-color',          'note' => 'Цвет инфо-блока.', 'ex' => 'blue'],
            ['name' => 'sub-info-button-text',    'note' => 'Текст кнопки инфо-блока (до 25 симв.).', 'ex' => 'Продлить'],
            ['name' => 'sub-info-button-link',    'note' => 'Ссылка/диплинк кнопки инфо-блока.', 'ex' => 'https://t.me/your_bot'],
            ['name' => 'sub-expire',              'note' => '1 — показывать блок об истечении (за 3 дня и после).', 'ex' => '1'],
            ['name' => 'sub-expire-button-link',  'note' => 'Ссылка кнопки «Renew» в блоке истечения.', 'ex' => 'https://t.me/your_bot'],
            ['name' => 'new-url',                 'note' => 'Полная замена URL подписки.', 'ex' => 'https://new.example.com/sub/abc'],
            ['name' => 'new-domain',              'note' => 'Сменить только домен подписки.', 'ex' => 'new.example.com'],
            ['name' => 'fallback-url',            'note' => 'Резервный URL подписки (при ошибке основного).', 'ex' => 'https://backup.example.com/sub/abc'],
            ['name' => 'hide-settings',           'note' => '1 — скрыть/запретить просмотр конфигов серверов.', 'ex' => '1'],
            ['name' => 'subscription-always-hwid-enable', 'note' => '1 — запретить пользователю отключать HWID.', 'ex' => '1'],
            ['name' => 'notification-subs-expire','note' => '1 — авто-уведомления об истечении подписки.', 'ex' => '1'],
        ],
        'FlClashX' => [
            ['name' => 'flclashx-servicename',    'note' => 'Название сервиса в виджете ServiceInfo.', 'ex' => 'My VPN'],
            ['name' => 'flclashx-servicelogo',    'note' => 'Логотип png/svg по URL (с flclashx-servicename).', 'ex' => 'https://example.com/logo.svg'],
            ['name' => 'flclashx-background',     'note' => 'URL фонового изображения приложения.', 'ex' => 'https://example.com/bg.jpg'],
            ['name' => 'flclashx-hex',            'note' => 'Тема приложения: HEX[:вариант][:pureblack].', 'ex' => 'FF5733:vibrant'],
            ['name' => 'flclashx-widgets',        'note' => 'Порядок виджетов на дашборде.', 'ex' => 'announce,metainfo,outboundModeV2,networkDetection'],
            ['name' => 'flclashx-view',           'note' => 'Вид страницы прокси.', 'ex' => 'type:list; sort:delay; layout:tight'],
            ['name' => 'flclashx-custom',         'note' => 'Когда применять стиль: add или update.', 'ex' => 'update'],
            ['name' => 'flclashx-denywidgets',    'note' => 'true — запретить пользователю править дашборд.', 'ex' => 'true'],
            ['name' => 'flclashx-serverinfo',     'note' => 'Имя прокси-группы для виджета смены сервера.', 'ex' => 'Proxy'],
            ['name' => 'flclashx-settings',       'note' => 'Настройки через подписку (перечислить нужные).', 'ex' => 'minimize, autostart, autoupdate'],
            ['name' => 'flclashx-globalmode',     'note' => 'false — скрыть настройки режима прокси.', 'ex' => 'false'],
        ],
        'Koala Clash / форки Verge' => [
            ['name' => 'profile-logo',            'note' => 'Логотип профиля (URL png/svg); koala кеширует.', 'ex' => 'https://example.com/logo.svg'],
            ['name' => 'custom-css',              'note' => 'Кастомный CSS оформления (URL на .css).', 'ex' => 'https://example.com/theme.css'],
            ['name' => 'global-mode',             'note' => 'Глобальный режим (любое значение, КРОМЕ ровно «false»).', 'ex' => 'true'],
            ['name' => 'x-hwid-limit',            'note' => 'true — koala показывает экран «лимит устройств HWID».', 'ex' => 'true'],
            ['name' => 'x-hwid-max-devices-reached', 'note' => 'Альтернатива x-hwid-limit: true → тот же экран лимита.', 'ex' => 'true'],
        ],
    ];
}

function rules_sanitize_input($arr) {
    $cat = [];
    foreach (rules_client_catalog() as $c) $cat[$c['key']] = $c['ua'];
    $clean = [];
    if (!is_array($arr)) return $clean;
    foreach ($arr as $r) {
        if (!is_array($r)) continue;
        $client    = trim((string) ($r['client'] ?? ''));
        $os        = strtolower(trim((string) ($r['os'] ?? '')));
        $osmap     = ['android' => 'android', 'ios' => 'ios', 'windows' => 'windows', 'macos' => 'mac', 'linux' => 'linux'];
        if (!isset($osmap[$os])) $os = '';
        $ua_custom = trim((string) ($r['ua_custom'] ?? ''));

        if ($client === 'all')         { $ua = ''; $os = ''; }
        elseif ($client === 'custom')  { $ua = $ua_custom; }
        else                           { $ua = (string) ($cat[$client] ?? ''); }

        $conds = [];
        if ($ua !== '') $conds[] = ['header' => 'user-agent',  'op' => 'CONTAINS', 'value' => $ua, 'ci' => true];
        if ($os !== '') $conds[] = ['header' => 'x-device-os', 'op' => 'CONTAINS', 'value' => $osmap[$os], 'ci' => true];

        $hdrs = [];
        foreach ((array) ($r['headers'] ?? []) as $h) {
            if (!is_array($h)) continue;
            $k = trim((string) ($h['key'] ?? ''));
            if ($k === '') continue;
            $hdrs[] = ['key' => $k, 'value' => (string) ($h['value'] ?? '')];
        }

        $name = trim((string) ($r['name'] ?? ''));
        if ($name === '') {
            $name = $client === 'all' ? 'Все клиенты'
                  : ($client === 'custom' ? ($ua_custom !== '' ? $ua_custom : 'Своё') : $client);
        }

        $clean[] = [
            'name'       => $name,
            'enabled'    => !empty($r['enabled']),
            'client'     => $client,
            'os'         => $os,
            'ua_custom'  => $ua_custom,
            'operator'   => 'AND',
            'conditions' => $conds,
            'headers'    => $hdrs,
        ];
    }
    return $clean;
}

function rules_save_from_json($json) {
    $clean = rules_sanitize_input(json_decode((string) $json, true));
    set_setting('response_rules', json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    return $clean;
}

function rules_migrate_legacy() {
    if (setting('rules_migrated', '0') === '1') return;
    $old  = app_headers_all();
    $hdrs = [];
    foreach ($old as $t) {
        if (empty($t['enabled']) || $t['name'] === '' || $t['value'] === '') continue;
        $hdrs[] = ['key' => $t['name'], 'value' => $t['value']];
    }
    if ($hdrs) {
        $rules = json_decode((string) setting('response_rules', '[]'), true);
        if (!is_array($rules)) $rules = [];
        array_unshift($rules, [
            'name' => 'Все клиенты', 'enabled' => true, 'client' => 'all',
            'os' => '', 'ua_custom' => '', 'operator' => 'AND', 'conditions' => [], 'headers' => $hdrs,
        ]);
        set_setting('response_rules', json_encode($rules, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        set_setting('app_headers', '[]');
    }
    set_setting('rules_migrated', '1');
}

function response_rules_all() {
    $arr = json_decode((string) setting('response_rules', '[]'), true);
    if (!is_array($arr)) return [];
    $out = [];
    foreach ($arr as $r) {
        if (!is_array($r)) continue;
        $op = strtoupper((string) ($r['operator'] ?? 'AND'));
        if ($op !== 'OR') $op = 'AND';

        $conds = [];
        foreach ((array) ($r['conditions'] ?? []) as $c) {
            if (!is_array($c)) continue;
            $h = trim((string) ($c['header'] ?? ''));
            if ($h === '') continue;
            $conds[] = [
                'header' => $h,
                'op'     => strtoupper((string) ($c['op'] ?? 'CONTAINS')),
                'value'  => (string) ($c['value'] ?? ''),
                'ci'     => array_key_exists('ci', $c) ? !empty($c['ci']) : true,
            ];
        }

        $hdrs = [];
        foreach ((array) ($r['headers'] ?? []) as $h) {
            if (!is_array($h)) continue;
            $k = trim((string) ($h['key'] ?? ''));
            if ($k === '') continue;
            $hdrs[] = ['key' => $k, 'value' => (string) ($h['value'] ?? '')];
        }

        $out[] = [
            'name'       => trim((string) ($r['name'] ?? '')),
            'enabled'    => !empty($r['enabled']),
            'client'     => trim((string) ($r['client'] ?? '')),
            'os'         => trim((string) ($r['os'] ?? '')),
            'ua_custom'  => trim((string) ($r['ua_custom'] ?? '')),
            'operator'   => $op,
            'conditions' => $conds,
            'headers'    => $hdrs,
        ];
    }
    return $out;
}

function rules_req_header($name, $override = []) {
    $lname = strtolower($name);
    foreach ($override as $k => $v) {
        if (strtolower($k) === $lname) return (string) $v;
    }
    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            if (strtolower($k) === $lname) return (string) $v;
        }
    }
    $sk = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    if (isset($_SERVER[$sk])) return (string) $_SERVER[$sk];
    return null;
}

function rules_op_match($hay, $op, $needle, $ci) {
    if ($hay === null) return false;
    if ($op === 'REGEX' || $op === 'NOT_REGEX') {
        $re = '~' . str_replace('~', '\~', (string) $needle) . '~u' . ($ci ? 'i' : '');
        $m  = (@preg_match($re, $hay) === 1);
        return $op === 'REGEX' ? $m : !$m;
    }
    if ($ci) { $hay = mb_strtolower($hay); $needle = mb_strtolower($needle); }
    $needle = (string) $needle;
    switch ($op) {
        case 'EQUALS':          return $hay === $needle;
        case 'NOT_EQUALS':      return $hay !== $needle;
        case 'CONTAINS':        return $needle !== '' && mb_strpos($hay, $needle) !== false;
        case 'NOT_CONTAINS':    return !($needle !== '' && mb_strpos($hay, $needle) !== false);
        case 'STARTS_WITH':     return $needle !== '' && mb_strpos($hay, $needle) === 0;
        case 'NOT_STARTS_WITH': return !($needle !== '' && mb_strpos($hay, $needle) === 0);
        case 'ENDS_WITH':       return $needle !== '' && substr($hay, -strlen($needle)) === $needle;
        case 'NOT_ENDS_WITH':   return !($needle !== '' && substr($hay, -strlen($needle)) === $needle);
    }
    return false;
}

function rule_matches($rule, $override = []) {
    $conds = $rule['conditions'];
    if (!$conds) return true;
    $op = $rule['operator'];
    foreach ($conds as $c) {
        $hv = rules_req_header($c['header'], $override);
        $m  = rules_op_match($hv, $c['op'], $c['value'], $c['ci']);
        if ($op === 'AND' && !$m) return false;
        if ($op === 'OR'  && $m)  return true;
    }
    return $op === 'AND';
}

function response_headers_final($override = []) {
    $merged = app_headers_send();
    foreach (response_rules_all() as $rule) {
        if (!$rule['enabled']) continue;
        if (!rule_matches($rule, $override)) continue;
        foreach ($rule['headers'] as $h) {
            $value = str_replace(["\r", "\n"], '', $h['value']);
            if ($value === '') continue;
            $merged[$h['key']] = $value;
        }
    }
    $out = [];
    foreach ($merged as $k => $v) {
        if (rules_header_ok($k)) $out[$k] = rules_encode_value($k, $v);
    }
    return $out;
}

function rules_test($override = []) {
    $matched = [];
    foreach (response_rules_all() as $rule) {
        if (!$rule['enabled']) continue;
        if (rule_matches($rule, $override)) $matched[] = $rule['name'] !== '' ? $rule['name'] : $rule['client'];
    }
    return ['matched' => $matched, 'headers' => response_headers_final($override)];
}

function emit_response_headers($override = []) {
    foreach (response_headers_final($override) as $name => $value) {
        header($name . ': ' . $value);
    }
}
