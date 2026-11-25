<?php

// Общие помощники по заголовкам ответа: base64 для кириллицы (анонс грейса, «Правила ответа панели») и справочник заголовков клиентов.

function rules_b64_headers() {
    return ['profile-title', 'announce'];
}

function rules_encode_value($name, $value) {
    if ($value === '' || strpos($value, 'base64:') === 0) return $value;
    if (!preg_match('/[^\x20-\x7E]/', $value)) return $value;
    if (!in_array(strtolower($name), rules_b64_headers(), true)) return $value;
    return 'base64:' . base64_encode($value);
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

// Вкладка «Правила ответа по приложению» удалена — её настройки и скрытый пункт меню из прежних версий убираем один раз; проверка бесплатна, settings уже в памяти.
function rules_settings_cleanup() {
    $nh = json_decode((string) setting('nav_hidden', ''), true);
    if (is_array($nh) && is_array($nh['t'] ?? null) && in_array('rules', $nh['t'], true)) {
        $nh['t'] = array_values(array_diff($nh['t'], ['rules']));
        set_setting('nav_hidden', json_encode($nh));
    }
    $keys = array_values(array_intersect(['response_rules', 'rules_migrated', 'app_headers'], array_keys((array) ($GLOBALS['submw_settings_cache'] ?? []))));
    if (!$keys || !($p = db())) return;
    try {
        $p->prepare('DELETE FROM settings WHERE k IN (' . implode(',', array_fill(0, count($keys), '?')) . ')')->execute($keys);
        foreach ($keys as $k) unset($GLOBALS['submw_settings_cache'][$k]);
    } catch (Throwable $e) {}
}
