<?php
// Правила ответа панели (Response Rules): чтение и отправка через API панели,
// снимки перед отправкой, проверка черновика, тестер панели и карта клиентов по логу запросов.

function pr_operators() {
    return ['EQUALS', 'NOT_EQUALS', 'CONTAINS', 'NOT_CONTAINS', 'STARTS_WITH', 'NOT_STARTS_WITH', 'ENDS_WITH', 'NOT_ENDS_WITH', 'REGEX', 'NOT_REGEX'];
}

function pr_types() {
    return ['XRAY_BASE64', 'XRAY_JSON', 'MIHOMO', 'STASH', 'CLASH', 'SINGBOX', 'BROWSER', 'BLOCK', 'STATUS_CODE_404', 'STATUS_CODE_451', 'SOCKET_DROP'];
}

function pr_type_label($t) {
    $m = ['XRAY_BASE64' => 'Список ссылок (base64)', 'XRAY_JSON' => 'Полный конфиг Xray', 'MIHOMO' => 'Mihomo', 'STASH' => 'Stash', 'CLASH' => 'Clash', 'SINGBOX' => 'sing-box',
          'BROWSER' => 'Страница подписки', 'BLOCK' => 'Запретить (403)', 'STATUS_CODE_404' => 'Не найдено (404)', 'STATUS_CODE_451' => 'Недоступно по закону (451)', 'SOCKET_DROP' => 'Оборвать соединение'];
    return $m[$t] ?? (string) $t;
}

function pr_type_kind($t) {
    if ($t === 'BROWSER') return 'page';
    if (in_array($t, ['BLOCK', 'STATUS_CODE_404', 'STATUS_CODE_451', 'SOCKET_DROP'], true)) return 'deny';
    return 'cfg';
}

// Версия панели, с которой поле модификации существует; старая панель молча выкидывает незнакомые ключи.
function pr_mod_min() {
    return ['headers' => '2.2.0', 'subscriptionTemplate' => '2.2.0', 'ignoreHostXrayJsonTemplate' => '2.6.1', 'applyHeadersToEnd' => '2.6.2',
            'ignoreServeJsonAtBaseSubscription' => '2.7.3', 'additionalExtendedClientsRegex' => '2.7.3', 'disableHwidCheck' => '2.8.0',
            'encryption' => '2.8.0', 'excludeHostsByTags' => '2.8.0', 'respondWithRemarks' => '3.3.0'];
}

function pr_mod_keys() { return array_keys(pr_mod_min()); }

function pr_mod_label($k) {
    $m = ['headers' => 'заголовки', 'subscriptionTemplate' => 'шаблон', 'ignoreHostXrayJsonTemplate' => 'без шаблона у сервера', 'applyHeadersToEnd' => 'заголовки последними',
          'ignoreServeJsonAtBaseSubscription' => 'оставить base64', 'additionalExtendedClientsRegex' => 'свои расширенные клиенты', 'disableHwidCheck' => 'без лимита устройств',
          'encryption' => 'шифрование', 'excludeHostsByTags' => 'скрыть серверы с тегами', 'respondWithRemarks' => 'сообщения вместо серверов'];
    return $m[$k] ?? (string) $k;
}

// Заголовки, которые ставит сама панель; правило с applyHeadersToEnd их перебьёт.
function pr_panel_headers() {
    return ['content-disposition', 'subscription-userinfo', 'subscription-refill-date', 'x-hwid-active', 'x-hwid-limit', 'x-hwid-max-devices-reached', 'x-hwid-not-supported', 'announce', 'content-type'];
}

function pr_header_token_ok($s) {
    return is_string($s) && $s !== '' && preg_match('/^[!#$%&\'*+.0-9A-Z^_`a-z|~-]+$/', $s) === 1;
}

function pr_ver_lt($ver, $min) {
    $ver = ltrim(trim((string) $ver), 'vV');
    if ($ver === '' || !preg_match('~^\d+\.\d+~', $ver)) return false;
    return version_compare($ver, $min, '<');
}

function pr_hash($rules) {
    return $rules === null ? '' : md5(json_encode($rules, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

// Свежие настройки подписки панели; заодно обновляют снимок «subset» для карточки пользователя.
function pr_state(&$error = '') {
    $error = '';
    $s = panel_ref('subset', 0, $error);
    if (!is_array($s) || ($s['uuid'] ?? '') === '') { if ($error === '') $error = 'Панель не вернула настройки подписки'; return null; }
    $s['hash'] = pr_hash($s['rules'] ?? null);
    $s['n'] = is_array($s['rules']['rules'] ?? null) ? count($s['rules']['rules']) : 0;
    return $s;
}

// Права токена по последней проверке «Права API-токена»: ok | miss | unknown.
function pr_scopes() {
    $out = ['update' => 'unknown', 'tester' => 'unknown', 'ts' => 0];
    $c = function_exists('tokscope_cached') ? tokscope_cached() : null;
    if (!is_array($c)) return $out;
    $out['ts'] = (int) ($c['ts'] ?? 0);
    foreach ($c['rows'] ?? [] as $r) {
        $st = (string) ($r['st'] ?? '');
        $v = $st === 'ok' ? 'ok' : ($st === 'miss' ? 'miss' : 'unknown');
        if (($r['s'] ?? '') === 'subscription-settings:update') $out['update'] = $v;
        if (($r['s'] ?? '') === 'system:test-srr-matcher') $out['tester'] = $v;
    }
    return $out;
}

// ---------- нормализация и проверка ----------

// Приводит черновик к форме панели: только известные ключи, нужные типы. Незнакомые ключи — в $unknown.
function pr_normalize($in, array &$unknown = []) {
    $unknown = [];
    $cfg = ['version' => '1'];
    if (!is_array($in)) return $cfg + ['rules' => []];
    foreach (array_keys($in) as $k) if (!in_array($k, ['version', 'settings', 'rules'], true)) $unknown[] = (string) $k;
    if (isset($in['version'])) $cfg['version'] = is_string($in['version']) ? $in['version'] : (string) $in['version'];
    if (array_key_exists('settings', $in)) {
        $st = is_array($in['settings']) ? $in['settings'] : [];
        foreach (array_keys($st) as $k) if ($k !== 'disableSubscriptionAccessByPath') $unknown[] = 'settings.' . $k;
        $cfg['settings'] = [];
        if (array_key_exists('disableSubscriptionAccessByPath', $st)) $cfg['settings']['disableSubscriptionAccessByPath'] = (bool) $st['disableSubscriptionAccessByPath'];
    }
    $cfg['rules'] = [];
    $rulesIn = is_array($in['rules'] ?? null) ? $in['rules'] : [];
    foreach (array_values($rulesIn) as $i => $r) {
        if (!is_array($r)) { $cfg['rules'][] = []; continue; }
        $o = [];
        $known = ['name', 'description', 'enabled', 'operator', 'conditions', 'responseType', 'responseModifications'];
        foreach (array_keys($r) as $k) if (!in_array($k, $known, true)) $unknown[] = 'rules[' . $i . '].' . $k;
        $o['name'] = isset($r['name']) ? (string) $r['name'] : '';
        if (array_key_exists('description', $r) && $r['description'] !== null) $o['description'] = (string) $r['description'];
        $o['enabled'] = array_key_exists('enabled', $r) ? (bool) $r['enabled'] : true;
        $o['operator'] = isset($r['operator']) ? strtoupper((string) $r['operator']) : 'AND';
        $o['conditions'] = [];
        foreach ((array) ($r['conditions'] ?? []) as $j => $c) {
            if (!is_array($c)) continue;
            foreach (array_keys($c) as $k) if (!in_array($k, ['headerName', 'operator', 'value', 'caseSensitive'], true)) $unknown[] = 'rules[' . $i . '].conditions[' . $j . '].' . $k;
            $o['conditions'][] = [
                'headerName'    => (string) ($c['headerName'] ?? ''),
                'operator'      => strtoupper((string) ($c['operator'] ?? '')),
                'value'         => (string) ($c['value'] ?? ''),
                'caseSensitive' => array_key_exists('caseSensitive', $c) ? (bool) $c['caseSensitive'] : true,
            ];
        }
        $o['responseType'] = isset($r['responseType']) ? strtoupper((string) $r['responseType']) : '';
        if (is_array($r['responseModifications'] ?? null)) {
            $m = $r['responseModifications'];
            $mo = [];
            foreach (array_keys($m) as $k) if (!in_array($k, pr_mod_keys(), true)) $unknown[] = 'rules[' . $i . '].responseModifications.' . $k;
            if (array_key_exists('headers', $m)) {
                $mo['headers'] = [];
                foreach ((array) $m['headers'] as $h) if (is_array($h)) $mo['headers'][] = ['key' => (string) ($h['key'] ?? ''), 'value' => (string) ($h['value'] ?? '')];
            }
            foreach (['applyHeadersToEnd', 'ignoreHostXrayJsonTemplate', 'ignoreServeJsonAtBaseSubscription', 'disableHwidCheck'] as $k) {
                if (array_key_exists($k, $m) && $m[$k] !== null) $mo[$k] = (bool) $m[$k];
            }
            if (array_key_exists('subscriptionTemplate', $m) && $m['subscriptionTemplate'] !== null) $mo['subscriptionTemplate'] = (string) $m['subscriptionTemplate'];
            foreach (['additionalExtendedClientsRegex', 'excludeHostsByTags', 'respondWithRemarks'] as $k) {
                if (array_key_exists($k, $m) && $m[$k] !== null) $mo[$k] = array_values(array_map('strval', (array) $m[$k]));
            }
            if (array_key_exists('encryption', $m) && is_array($m['encryption'])) {
                $mo['encryption'] = ['method' => (string) ($m['encryption']['method'] ?? ''), 'key' => trim((string) ($m['encryption']['key'] ?? ''))];
            }
            if ($mo) $o['responseModifications'] = $mo;
        }
        $cfg['rules'][] = $o;
    }
    return $cfg;
}

// Черновик в том виде, в каком он уходит в панель: пустые модификации убраны.
function pr_for_panel(array $cfg) {
    foreach ($cfg['rules'] as $i => $r) {
        if (isset($r['responseModifications'])) {
            $m = $r['responseModifications'];
            if (isset($m['headers']) && !$m['headers']) unset($m['headers']);
            foreach (['additionalExtendedClientsRegex', 'excludeHostsByTags', 'respondWithRemarks'] as $k) if (isset($m[$k]) && !$m[$k]) unset($m[$k]);
            if (isset($m['subscriptionTemplate']) && $m['subscriptionTemplate'] === '') unset($m['subscriptionTemplate']);
            if (isset($m['encryption']) && ($m['encryption']['method'] === '' || $m['encryption']['key'] === '')) unset($m['encryption']);
            if ($m) $cfg['rules'][$i]['responseModifications'] = $m; else unset($cfg['rules'][$i]['responseModifications']);
        }
        if (isset($r['description']) && $r['description'] === '') unset($cfg['rules'][$i]['description']);
    }
    $cfg['rules'] = array_values($cfg['rules']);
    if (isset($cfg['settings']) && is_array($cfg['settings']) && !$cfg['settings']) unset($cfg['settings']);
    return $cfg;
}

function pr_regex_ok($pattern) {
    $d = '~';
    $p = str_replace($d, '\\' . $d, (string) $pattern);
    return @preg_match($d . $p . $d, '') !== false;
}

// $ctx: ver (версия панели), custom_remarks (bool), serve_json (bool), hwid_on (bool).
// Возвращает errors (панель отклонит или сломает выдачу) и warnings (примет, но сработает не так, как задумано).
function pr_validate(array $cfg, array $ctx = []) {
    $err = []; $warn = [];
    $E = function ($rule, $code, $msg, $fix = null) use (&$err) { $err[] = ['rule' => $rule, 'code' => $code, 'msg' => $msg, 'fix' => $fix]; };
    $W = function ($rule, $code, $msg, $fix = null) use (&$warn) { $warn[] = ['rule' => $rule, 'code' => $code, 'msg' => $msg, 'fix' => $fix]; };
    $ver = (string) ($ctx['ver'] ?? '');
    $b64 = function_exists('rules_b64_headers') ? rules_b64_headers() : ['profile-title', 'announce'];

    if (($cfg['version'] ?? '') !== '1') $E(null, 'version', 'Версия формата должна быть строкой "1"');
    $rules = is_array($cfg['rules'] ?? null) ? $cfg['rules'] : [];
    if (!$rules) $W(null, 'empty', 'Список правил пуст — панель ответит 403 на любой запрос подписки');
    if (!empty($cfg['settings']['disableSubscriptionAccessByPath']) && pr_ver_lt($ver, '2.6.2')) $W(null, 'ver', 'Закрытие ссылок /json, /mihomo появилось в панели 2.6.2, у вас ' . $ver . ' — панель выкинет эту настройку');

    $names = [];
    $catchAll = null;
    foreach ($rules as $i => $r) {
        $nm = trim((string) ($r['name'] ?? ''));
        if ($nm === '') $E($i, 'name', 'Правило ' . ($i + 1) . ': нет имени');
        elseif (mb_strlen($nm) > 50) $E($i, 'name', '«' . mb_substr($nm, 0, 30) . '…»: имя длиннее 50 символов');
        if (isset($r['description']) && $r['description'] !== '' && mb_strlen($r['description']) > 250) $E($i, 'desc', '«' . $nm . '»: описание длиннее 250 символов');
        if (!in_array($r['operator'] ?? '', ['AND', 'OR'], true)) $E($i, 'op', '«' . $nm . '»: связка условий должна быть «все» (AND) или «любое» (OR)');
        if (!in_array($r['responseType'] ?? '', pr_types(), true)) $E($i, 'type', '«' . $nm . '»: не выбрано, что отдать');
        if ($nm !== '') { if (isset($names[$nm])) $W($i, 'dup', '«' . $nm . '»: такое имя уже есть у правила ' . ($names[$nm] + 1) . ' — в истории запросов их не различить'); else $names[$nm] = $i; }

        foreach ((array) ($r['conditions'] ?? []) as $j => $c) {
            $hn = (string) ($c['headerName'] ?? '');
            $op = (string) ($c['operator'] ?? '');
            $val = (string) ($c['value'] ?? '');
            $cs = !empty($c['caseSensitive']);
            $where = '«' . $nm . '», условие ' . ($j + 1);
            if (!pr_header_token_ok($hn)) $E($i, 'cond_hdr', $where . ': имя заголовка «' . $hn . '» — только латиница, цифры, «-» и «_»');
            if (!in_array($op, pr_operators(), true)) $E($i, 'cond_op', $where . ': неизвестный оператор');
            if ($val === '') $E($i, 'cond_val', $where . ': пустое значение — панель такое не принимает');
            elseif (mb_strlen($val) > 255) $E($i, 'cond_val', $where . ': значение длиннее 255 символов');
            if (in_array($op, ['REGEX', 'NOT_REGEX'], true) && $val !== '') {
                if (!pr_regex_ok($val)) $W($i, 'cond_re', $where . ': регулярное выражение не компилируется — панель отклонит его при сохранении');
                if (!$cs && preg_match('~\\\\[DSWB]|\[[^\]]*[A-Z]-[a-z][^\]]*\]~', $val)) {
                    $W($i, 'cond_re_ci', $where . ': без учёта регистра панель опускает и сам шаблон — \\D, \\S, \\W, \\B и диапазоны вроде [A-z] меняют смысл, условие перестанет срабатывать', ['op' => 'cs', 'rule' => $i, 'cond' => $j]);
                }
                if (preg_match('~\(\?[a-z]+\)~', $val)) $W($i, 'cond_re_flag', $where . ': встроенные флаги вида (?i) в панели не работают — она отклонит выражение; используйте «без учёта регистра»');
            }
        }

        $m = is_array($r['responseModifications'] ?? null) ? $r['responseModifications'] : [];
        $deny = pr_type_kind((string) ($r['responseType'] ?? '')) === 'deny';
        foreach ($m as $k => $v) {
            $min = pr_mod_min()[$k] ?? '';
            if ($min !== '' && pr_ver_lt($ver, $min)) $W($i, 'ver', '«' . $nm . '»: «' . pr_mod_label($k) . '» появилось в панели ' . $min . ', у вас ' . $ver . ' — панель молча выкинет это поле');
        }
        if ($deny && $m) {
            $extra = array_diff(array_keys($m), ['headers', 'applyHeadersToEnd']);
            if ($extra) $W($i, 'deny_mods', '«' . $nm . '»: при отказе работают только заголовки; ' . implode(', ', array_map('pr_mod_label', $extra)) . ' — ничего не сделают');
            if (!empty($m['applyHeadersToEnd']) && !empty($m['headers'])) $W($i, 'deny_end', '«' . $nm . '»: при отказе заголовки «последними» не отправляются — уберите эту галочку, чтобы заголовки ушли');
        }
        foreach ((array) ($m['headers'] ?? []) as $j => $h) {
            $key = (string) ($h['key'] ?? ''); $val = (string) ($h['value'] ?? '');
            $kl = strtolower($key);
            if (!pr_header_token_ok($key)) $E($i, 'hdr_key', '«' . $nm . '»: имя заголовка «' . $key . '» — только латиница, цифры, «-» и «_»');
            if ($val === '') $E($i, 'hdr_val', '«' . $nm . '»: у заголовка ' . $key . ' пустое значение');
            elseif (preg_match('~[^\x20-\x7E\t]~', $val)) {
                $fix = in_array($kl, $b64, true) ? ['op' => 'b64', 'rule' => $i, 'hdr' => $j] : null;
                $E($i, 'hdr_ascii', '«' . $nm . '»: значение заголовка ' . $key . ' содержит ' . (preg_match('~[\r\n]~', $val) ? 'перевод строки' : 'нелатиницу') . ' — панель будет отвечать ошибкой 500 на каждый запрос' . ($fix ? '. Закодируйте значение' : '. Оставьте только латиницу'), $fix);
            }
            if (strpos($val, '{{') !== false) $W($i, 'hdr_var', '«' . $nm . '»: в заголовках правил переменные {{…}} не подставляются — ' . $key . ' уйдёт как есть');
            if (!empty($m['applyHeadersToEnd']) && in_array($kl, ['subscription-userinfo', 'content-disposition', 'subscription-refill-date'], true)) $W($i, 'hdr_end', '«' . $nm . '»: ' . $key . ' «последним» перебьёт заголовок панели — клиент не увидит трафик и дату окончания');
            if (empty($m['applyHeadersToEnd']) && in_array($kl, pr_panel_headers(), true)) $W($i, 'hdr_lose', '«' . $nm . '»: заголовок ' . $key . ' панель ставит сама и перебьёт ваш; чтобы победил ваш — включите «ставить последними»');
            if ($kl === 'content-type') $W($i, 'hdr_ct', '«' . $nm . '»: content-type правилом не поменять — панель ставит его после всех заголовков');
        }
        if (array_key_exists('subscriptionTemplate', $m) && trim((string) $m['subscriptionTemplate']) === '') $E($i, 'tpl', '«' . $nm . '»: имя шаблона пустое — уберите поле или выберите шаблон');
        foreach ((array) ($m['additionalExtendedClientsRegex'] ?? []) as $re) {
            if ((string) $re === '') $E($i, 'ext_re', '«' . $nm . '»: пустое выражение в «свои расширенные клиенты»');
            elseif (!pr_regex_ok($re)) $W($i, 'ext_re', '«' . $nm . '»: выражение «' . $re . '» не компилируется — панель отклонит его');
        }
        if (isset($m['encryption'])) {
            $em = (string) ($m['encryption']['method'] ?? ''); $ek = (string) ($m['encryption']['key'] ?? '');
            if (!in_array($em, ['age1', 'age1pq1'], true)) $E($i, 'enc', '«' . $nm . '»: способ шифрования — age1 или age1pq1');
            if ($ek === '') $E($i, 'enc', '«' . $nm . '»: пустой ключ шифрования');
            elseif (strpos($ek, 'age1') !== 0) $W($i, 'enc_key', '«' . $nm . '»: ключ не похож на age (должен начинаться с age1…) — панель не проверит его и отдаст пустую подписку');
        }
        if (array_key_exists('excludeHostsByTags', $m)) {
            $tags = (array) $m['excludeHostsByTags'];
            if (!$tags) $E($i, 'tags', '«' . $nm . '»: список тегов пуст — уберите поле или добавьте тег');
            foreach ($tags as $t) {
                if (!preg_match('~^[A-Z0-9_:]+$~', (string) $t) || strlen((string) $t) > 36) $E($i, 'tags', '«' . $nm . '»: тег «' . $t . '» — только заглавные латинские буквы, цифры, «_» и «:», до 36 символов');
            }
        }
        if (!empty($m['respondWithRemarks'])) {
            if (array_key_exists('custom_remarks', $ctx) && !$ctx['custom_remarks']) $W($i, 'remarks_off', '«' . $nm . '»: сообщения вместо серверов не сработают — в панели выключены свои ремарки (настройки подписки → Custom remarks)');
            foreach ($m['respondWithRemarks'] as $line) {
                if (is_string($line) && strpos($line, '{"f') === 0) $W($i, 'remarks_raw', '«' . $nm . '»: строка, начинающаяся с {"f, уйдёт клиенту как готовый сервер, а не как текст');
            }
        }
        if (!empty($m['ignoreServeJsonAtBaseSubscription']) && array_key_exists('serve_json', $ctx) && !$ctx['serve_json']) $W($i, 'serve_off', '«' . $nm . '»: «оставить base64» ни на что не влияет — в панели отдача JSON на базовой ссылке и так выключена');
        if (!empty($m['disableHwidCheck']) && array_key_exists('hwid_on', $ctx) && !$ctx['hwid_on']) $W($i, 'hwid_off', '«' . $nm . '»: «без лимита устройств» ни на что не влияет — проверка устройств в панели выключена');

        if (!empty($r['enabled']) && !($r['conditions'] ?? []) && $catchAll === null) $catchAll = $i;
    }
    if ($rules) {
        if ($catchAll === null) $W(null, 'no_catchall', 'Нет правила без условий в конце — кто не подошёл ни под одно, получит 403');
        else {
            $after = [];
            foreach ($rules as $i => $r) if ($i > $catchAll && !empty($r['enabled'])) $after[] = '«' . ($r['name'] ?? '') . '»';
            if ($after) $W($catchAll, 'unreachable', 'Правила ниже «' . ($rules[$catchAll]['name'] ?? '') . '» никогда не сработают — оно подходит всем: ' . implode(', ', $after), ['op' => 'move_up', 'rule' => $catchAll]);
        }
    }
    return ['errors' => $err, 'warnings' => $warn];
}

// Кодирует значение заголовка в base64:… (так Happ и Hiddify читают нелатиницу).
function pr_b64_value($v) {
    $v = (string) $v;
    if (strpos($v, 'base64:') === 0) return $v;
    return 'base64:' . base64_encode($v);
}

// ---------- снимки ----------

function pr_snap_keep() { return 30; }

function pr_snap_ddl($drv) {
    if ($drv === 'mysql') {
        return ["CREATE TABLE IF NOT EXISTS panel_rules_snap (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            ts INT NOT NULL, kind VARCHAR(8) NOT NULL DEFAULT 'auto', note VARCHAR(191) NULL,
            n INT NOT NULL DEFAULT 0, panel_upd VARCHAR(40) NULL, hash CHAR(32) NOT NULL, body MEDIUMTEXT NOT NULL,
            PRIMARY KEY (id), KEY idx_prs_ts (ts)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"];
    }
    return ["CREATE TABLE IF NOT EXISTS panel_rules_snap (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ts INTEGER NOT NULL, kind TEXT NOT NULL DEFAULT 'auto', note TEXT NULL,
            n INTEGER NOT NULL DEFAULT 0, panel_upd TEXT NULL, hash TEXT NOT NULL, body TEXT NOT NULL
        )", "CREATE INDEX IF NOT EXISTS idx_prs_ts ON panel_rules_snap(ts)"];
}

function pr_snap_ensure() {
    static $ok = null;
    if ($ok !== null) return $ok;
    if (!($p = db())) return $ok = false;
    if (setting('pr_snap_table', '') === '1') return $ok = true;
    try { foreach (pr_snap_ddl(db_driver()) as $sql) $p->exec($sql); set_setting('pr_snap_table', '1'); $ok = true; }
    catch (Throwable $e) { error_log('submw panel_rules_snap table: ' . $e->getMessage()); $ok = false; }
    return $ok;
}

function pr_snap_add($rules, $kind, $note = '', $panelUpd = '') {
    if (!is_array($rules) || !pr_snap_ensure() || !($p = db())) return 0;
    $body = json_encode($rules, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    try {
        $st = $p->prepare('INSERT INTO panel_rules_snap (ts, kind, note, n, panel_upd, hash, body) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $st->execute([time(), $kind === 'manual' ? 'manual' : 'auto', $note !== '' ? mb_substr($note, 0, 191) : null, count($rules['rules'] ?? []), $panelUpd !== '' ? mb_substr($panelUpd, 0, 40) : null, pr_hash($rules), $body]);
        $id = (int) $p->lastInsertId();
        $ids = $p->query('SELECT id FROM panel_rules_snap ORDER BY id DESC')->fetchAll(PDO::FETCH_COLUMN);
        if (count($ids) > pr_snap_keep()) {
            $old = array_slice($ids, pr_snap_keep());
            $p->prepare('DELETE FROM panel_rules_snap WHERE id IN (' . implode(',', array_fill(0, count($old), '?')) . ')')->execute($old);
        }
        return $id;
    } catch (Throwable $e) { error_log('submw panel_rules_snap add: ' . $e->getMessage()); return 0; }
}

function pr_snap_list() {
    if (!pr_snap_ensure() || !($p = db())) return [];
    try {
        $rows = $p->query('SELECT id, ts, kind, note, n, panel_upd, hash FROM panel_rules_snap ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) { $r['id'] = (int) $r['id']; $r['ts'] = (int) $r['ts']; $r['n'] = (int) $r['n']; }
        return $rows;
    } catch (Throwable $e) { return []; }
}

function pr_snap_get($id) {
    if (!pr_snap_ensure() || !($p = db())) return null;
    try {
        $st = $p->prepare('SELECT id, ts, kind, note, n, panel_upd, hash, body FROM panel_rules_snap WHERE id = ?');
        $st->execute([(int) $id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) return null;
        $r['rules'] = json_decode((string) $r['body'], true);
        unset($r['body']);
        $r['id'] = (int) $r['id']; $r['ts'] = (int) $r['ts']; $r['n'] = (int) $r['n'];
        return is_array($r['rules']) ? $r : null;
    } catch (Throwable $e) { return null; }
}

function pr_snap_delete($id) {
    if (!pr_snap_ensure() || !($p = db())) return false;
    try { return $p->prepare('DELETE FROM panel_rules_snap WHERE id = ?')->execute([(int) $id]); } catch (Throwable $e) { return false; }
}

// Разница по правилам между $from (старое) и $to (новое): добавлено / удалено / изменено по имени.
function pr_diff($from, $to) {
    $idx = function ($cfg) {
        $out = [];
        foreach ((array) (is_array($cfg) ? ($cfg['rules'] ?? []) : []) as $i => $r) {
            if (!is_array($r)) continue;
            $k = (string) ($r['name'] ?? ('#' . $i));
            while (isset($out[$k])) $k .= ' ';
            $out[$k] = ['i' => $i, 'r' => $r];
        }
        return $out;
    };
    $a = $idx($from); $b = $idx($to);
    $added = []; $removed = []; $changed = [];
    foreach ($b as $k => $v) if (!isset($a[$k])) $added[] = rtrim($k);
    foreach ($a as $k => $v) if (!isset($b[$k])) $removed[] = rtrim($k);
    foreach ($b as $k => $v) {
        if (!isset($a[$k])) continue;
        $ra = $a[$k]['r']; $rb = $v['r'];
        $fields = [];
        foreach (['enabled' => 'вкл/выкл', 'operator' => 'связка условий', 'conditions' => 'условия', 'responseType' => 'тип ответа', 'description' => 'описание'] as $f => $lbl) {
            if (json_encode($ra[$f] ?? null) !== json_encode($rb[$f] ?? null)) $fields[] = $lbl . ($f === 'responseType' ? ': ' . pr_type_label((string) ($ra[$f] ?? '—')) . ' → ' . pr_type_label((string) ($rb[$f] ?? '—')) : '');
        }
        $ma = (array) ($ra['responseModifications'] ?? []); $mb = (array) ($rb['responseModifications'] ?? []);
        foreach (array_unique(array_merge(array_keys($ma), array_keys($mb))) as $mk) {
            if (json_encode($ma[$mk] ?? null) !== json_encode($mb[$mk] ?? null)) $fields[] = pr_mod_label($mk);
        }
        if ($a[$k]['i'] !== $v['i'] && !$fields) $fields[] = 'позиция ' . ($a[$k]['i'] + 1) . ' → ' . ($v['i'] + 1);
        if ($fields) $changed[] = ['name' => rtrim($k), 'fields' => $fields];
    }
    $sa = !empty($from['settings']['disableSubscriptionAccessByPath']); $sb = !empty($to['settings']['disableSubscriptionAccessByPath']);
    return ['added' => $added, 'removed' => $removed, 'changed' => $changed, 'settings' => $sa !== $sb];
}

// ---------- отправка ----------

// $baseHash — хеш правил панели на момент открытия вкладки; расхождение = правили параллельно, не отправляем.
function pr_push(array $cfg, $baseHash, $src = 'form') {
    $e = '';
    $st = pr_state($e);
    if ($st === null) return ['ok' => false, 'error' => 'Не удалось прочитать правила панели: ' . $e];
    if ($baseHash !== '' && $baseHash !== $st['hash']) return ['ok' => false, 'conflict' => true, 'state' => pr_state_public($st), 'error' => 'В панели правила изменились с момента открытия'];
    $ctx = pr_ctx($st);
    $v = pr_validate($cfg, $ctx);
    if ($v['errors']) return ['ok' => false, 'error' => 'В черновике есть ошибки', 'errors' => $v['errors'], 'warnings' => $v['warnings']];
    $send = pr_for_panel($cfg);
    if (is_array($st['rules'])) pr_snap_add($st['rules'], 'auto', $src === 'restore' ? 'перед восстановлением' : 'перед отправкой', (string) $st['upd']);
    [$ok, $code, $data, $err] = remnawave_api_request('PATCH', '/api/subscription-settings', ['uuid' => $st['uuid'], 'responseRules' => $send]);
    if (!$ok) {
        $msg = function_exists('panel_err_text') ? panel_err_text($data, $code, $err) : ($err ?: ('HTTP ' . $code));
        return ['ok' => false, 'error' => 'Панель не приняла правила: ' . $msg, 'code' => $code];
    }
    set_setting('pr_pushed_ts', (string) time());
    $e2 = '';
    $after = pr_state($e2);
    return ['ok' => true, 'state' => $after !== null ? pr_state_public($after) : null, 'warnings' => $v['warnings']];
}

function pr_state_public(array $st) {
    return ['uuid' => $st['uuid'], 'upd' => $st['upd'], 'hash' => $st['hash'], 'rules' => $st['rules'], 'n' => $st['n'],
            'serve_json' => (bool) $st['serve_json'], 'custom_remarks' => (bool) $st['custom_remarks'], 'hwid' => $st['hwid'] ?? null, 'ts' => time()];
}

function pr_ctx(array $st) {
    $hw = $st['hwid'] ?? null;
    return ['ver' => panel_version(), 'custom_remarks' => (bool) $st['custom_remarks'], 'serve_json' => (bool) $st['serve_json'],
            'hwid_on' => is_array($hw) ? !empty($hw['enabled']) : true];
}

// ---------- тестер панели ----------

// $headers — ['user-agent' => '…', 'x-device-os' => '…', …]; конфиг уходит в теле, панель сопоставляет с заголовками запроса.
function pr_test(array $cfg, array $headers, &$error = '') {
    $error = '';
    $send = pr_for_panel($cfg);
    $hl = [];
    $hasAccept = false;
    foreach ($headers as $k => $v) {
        $k = strtolower(trim((string) $k)); $v = trim((string) $v);
        if (!pr_header_token_ok($k) || $v === '' || preg_match('~[\r\n]~', $v)) continue;
        if (in_array($k, ['authorization', 'content-type', 'content-length', 'host', 'cookie', 'x-api-key', 'transfer-encoding', 'content-encoding', 'connection', 'expect', 'upgrade'], true)) continue;
        if ($k === 'accept') $hasAccept = true;
        $hl[] = $k . ': ' . $v;
    }
    if (!$hasAccept) $hl[] = 'Accept: */*';
    [$ok, $code, $data, $err] = remnawave_api_request('POST', '/api/system/testers/srr-matcher', ['responseRules' => $send], ['headers' => $hl, 'timeout' => 15]);
    if (!$ok) {
        $msg = is_array($data) ? (string) ($data['message'] ?? '') : '';
        if ($code === 500 && $msg !== '') $error = 'Панель отклонила черновик: ' . $msg;
        elseif ($code === 403) $error = 'У API-токена нет права system:test-srr-matcher';
        else $error = $err ?: ('HTTP ' . $code);
        return null;
    }
    $r = is_array($data) ? ($data['response'] ?? $data) : [];
    $mr = is_array($r['matchedRule'] ?? null) ? $r['matchedRule'] : null;
    $idx = null;
    if ($mr !== null) {
        foreach ($send['rules'] as $i => $rule) {
            if (($rule['name'] ?? '') === ($mr['name'] ?? '') && json_encode($rule['conditions'] ?? []) === json_encode($mr['conditions'] ?? [])) { $idx = $i; break; }
        }
        if ($idx === null) foreach ($send['rules'] as $i => $rule) if (($rule['name'] ?? '') === ($mr['name'] ?? '')) { $idx = $i; break; }
    }
    $oh = $r['outputHeaders'] ?? [];
    $outHeaders = [];
    if (is_array($oh)) {
        foreach ($oh as $k => $v) {
            if (is_array($v) && isset($v['key'])) $outHeaders[(string) $v['key']] = (string) ($v['value'] ?? '');
            elseif (is_string($k)) $outHeaders[$k] = (string) $v;
        }
    }
    return ['matched' => !empty($r['matched']), 'idx' => $idx, 'name' => $mr['name'] ?? null, 'type' => (string) ($r['responseType'] ?? ''), 'headers' => $outHeaders, 'input' => is_array($r['inputHeaders'] ?? null) ? $r['inputHeaders'] : []];
}

// ---------- клиенты по логу запросов ----------

function pr_browser_ua() {
    return 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36';
}

// Группы «приложение × ОС» за $hours часов: настоящий UA последнего запроса, заголовки устройства, версии, число запросов.
function pr_clients($hours = 168, $limit = 60) {
    $hours = max(1, min(720, (int) $hours));
    $key = 'pr_clients_' . $hours;
    $c = admin_cache_get($key, 300);
    if (is_array($c) && isset($c['groups'])) return $c;
    $groups = [];
    if ($p = db()) {
        try {
            $st = $p->prepare("SELECT user_agent, COUNT(*) c, MAX(" . sql_epoch('ts') . ") last, MAX(meta) m, MAX(hwid) hw FROM request_log WHERE user_agent IS NOT NULL AND user_agent <> '' AND decision NOT IN ('browser', 'error') AND " . sql_epoch('ts') . " >= ? GROUP BY user_agent ORDER BY c DESC LIMIT 400");
            $st->execute([time() - $hours * 3600]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { $rows = []; }
        foreach ($rows as $row) {
            $ua = (string) $row['user_agent'];
            if (function_exists('is_browser_ua') && is_browser_ua($ua)) continue;
            $cl = reqlog_client($ua);
            $k = (string) ($cl['key'] ?? '');
            if ($k === '') continue;
            $mt = json_decode((string) ($row['m'] ?? ''), true);
            $dv = is_array($mt) && is_array($mt['dv'] ?? null) ? $mt['dv'] : [];
            $os = (string) $cl['os'];
            if ($os === '') $os = reqlog_os_norm((string) ($dv['o'] ?? ''));
            $id = $k . '|' . $os;
            $last = (int) $row['last'];
            if (!isset($groups[$id])) {
                $app = (string) $cl['app'];
                $cv = (string) ($cl['ver'] ?? '');
                if ($cv !== '' && substr($app, -strlen(' ' . $cv)) === ' ' . $cv) $app = substr($app, 0, -strlen(' ' . $cv));
                $groups[$id] = ['id' => $id, 'key' => $k, 'name' => $app, 'os' => $os, 'ua' => $ua, 'dv' => [], 'hwid' => false, 'n' => 0, 'last' => 0, 'vers' => [], 'has_os' => false, 'has_model' => false];
            }
            $g = &$groups[$id];
            $g['n'] += (int) $row['c'];
            if ($last > $g['last']) { $g['last'] = $last; $g['ua'] = $ua; if ($dv) $g['dv'] = $dv; }
            if (!empty($row['hw'])) $g['hwid'] = true;
            if (!empty($dv['o'])) $g['has_os'] = true;
            if (!empty($dv['m'])) $g['has_model'] = true;
            $v = (string) ($cl['ver'] ?? '');
            if ($v === '') $v = reqlog_appver((string) ($dv['av'] ?? ''));
            if ($v !== '' && !in_array($v, $g['vers'], true) && count($g['vers']) < 6) $g['vers'][] = $v;
            unset($g);
        }
    }
    uasort($groups, fn($a, $b) => [$b['n'], $a['name'], $a['os']] <=> [$a['n'], $b['name'], $b['os']]);
    $groups = array_slice(array_values($groups), 0, $limit);
    $total = array_sum(array_column($groups, 'n'));
    $groups[] = ['id' => 'browser|', 'key' => 'browser', 'name' => 'Браузер', 'os' => '', 'ua' => pr_browser_ua(), 'dv' => [], 'hwid' => false, 'n' => 0, 'last' => 0, 'vers' => [], 'has_os' => false, 'has_model' => false, 'synthetic' => true];
    $out = ['ts' => time(), 'hours' => $hours, 'total' => $total, 'groups' => $groups];
    admin_cache_put($key, $out);
    return $out;
}

// Кто из приложений реально присылает заголовки устройства (для подсказки у условий по ОС / HWID / модели).
function pr_header_presence($hours = 168) {
    $has = [];
    foreach (pr_clients($hours)['groups'] as $g) {
        if (!empty($g['synthetic'])) continue;
        $nm = (string) $g['name'];
        foreach (['os' => 'has_os', 'hwid' => 'hwid', 'model' => 'has_model'] as $k => $f) {
            $has[$k][$nm] = !empty($has[$k][$nm]) || !empty($g[$f]);
        }
    }
    $out = [];
    foreach (['os', 'hwid', 'model'] as $k) {
        $out[$k] = ['yes' => [], 'no' => []];
        foreach ($has[$k] ?? [] as $nm => $b) $out[$k][$b ? 'yes' : 'no'][] = $nm;
    }
    return $out;
}

// Заголовки запроса для группы клиентов — такие, какими их видит панель за прослойкой.
function pr_group_headers(array $g) {
    $h = ['user-agent' => (string) $g['ua']];
    $h['accept'] = !empty($g['synthetic']) ? 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8' : '*/*';
    $dv = is_array($g['dv'] ?? null) ? $g['dv'] : [];
    if (!empty($dv['o'])) $h['x-device-os'] = (string) $dv['o'];
    if (!empty($dv['v'])) $h['x-ver-os'] = (string) $dv['v'];
    if (!empty($dv['m'])) $h['x-device-model'] = (string) $dv['m'];
    if (!empty($g['hwid'])) $h['x-hwid'] = 'submw-map-' . substr(md5((string) $g['id']), 0, 16);
    return $h;
}

// Карта клиентов: по одному запросу к тестеру панели на группу. Ответы тестера хранятся по группам, пока не
// изменятся правила; цифры каждый раз пересчитываются по свежему логу, группы без ответа отмечаются как непроверенные.
// $mode: '' — считать, если ответов нет или они неполные; 'force' — пересчитать всё; 'cached' — только сохранённое (null, если нет).
function pr_map(array $cfg, $hours = 168, &$error = '', $mode = '') {
    $error = '';
    $send = pr_for_panel($cfg);
    $cl = pr_clients($hours);
    $groups = $cl['groups'];
    $cfgHash = md5(json_encode($send, JSON_UNESCAPED_UNICODE));
    $mkeys = [];
    foreach ($groups as $i => $g) $mkeys[$i] = md5($g['id'] . '|' . json_encode(pr_group_headers($g), JSON_UNESCAPED_UNICODE));
    $c = $mode === 'force' ? null : admin_cache_get('pr_map', 86400 * 365);
    $saved = is_array($c) && ($c['cfg_hash'] ?? '') === $cfgHash && is_array($c['match'] ?? null) ? $c : null;
    $missing = $saved === null ? count($groups) : count(array_filter($mkeys, static fn($k) => !isset($saved['match'][$k])));
    if ($saved !== null && ($mode === 'cached' || $missing === 0)) return pr_map_build($send, $cl, $mkeys, $saved['match'], (int) $saved['ts']);
    if ($mode === 'cached') return null;
    $base = remnawave_url(); $token = remnawave_token();
    if ($base === '' || $token === '') { $error = 'Не заданы URL панели или API-токен'; return null; }
    $body = json_encode(['responseRules' => $send], JSON_UNESCAPED_UNICODE);
    $common = ['Authorization: Bearer ' . $token, 'Content-Type: application/json'];
    if (strpos($base, 'http://') === 0) { $common[] = 'x-forwarded-proto: https'; $common[] = 'x-forwarded-for: 127.0.0.1'; }
    $common = panel_auth_headers($common);
    $path = '/api/system/testers/srr-matcher';
    $results = [];
    $batch = 8;
    for ($off = 0; $off < count($groups); $off += $batch) {
        $slice = array_slice($groups, $off, $batch, true);
        $mh = curl_multi_init();
        $hs = [];
        foreach ($slice as $i => $g) {
            $h = $common;
            foreach (pr_group_headers($g) as $k => $v) $h[] = $k . ': ' . $v;
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $base . $path, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_SSL_VERIFYPEER => api_tls_verify(), CURLOPT_SSL_VERIFYHOST => api_tls_verify() ? 2 : 0,
                CURLOPT_CUSTOMREQUEST => 'POST', CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $h,
            ]);
            curl_multi_add_handle($mh, $ch);
            $hs[$i] = $ch;
        }
        do {
            $mrc = curl_multi_exec($mh, $active);
            if ($active && curl_multi_select($mh, 1.0) === -1) usleep(10000);
        } while ($active && $mrc === CURLM_OK);
        foreach ($hs as $i => $ch) {
            $resp = curl_multi_getcontent($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $cerr = curl_error($ch);
            if (function_exists('apistat_hit')) apistat_hit('POST', $path, $cerr !== '' ? 0 : $code, curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000);
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
            $data = json_decode((string) $resp, true);
            if ($cerr !== '' || $code < 200 || $code >= 300) {
                $msg = is_array($data) ? (string) ($data['message'] ?? '') : '';
                $error = $code === 500 && $msg !== '' ? 'Панель отклонила черновик: ' . $msg : ($code === 403 ? 'У API-токена нет права system:test-srr-matcher' : ($cerr !== '' ? $cerr : 'HTTP ' . $code));
                curl_multi_close($mh);
                return null;
            }
            $r = is_array($data) ? ($data['response'] ?? $data) : [];
            $mr = is_array($r['matchedRule'] ?? null) ? $r['matchedRule'] : null;
            $idx = null;
            if ($mr !== null) foreach ($send['rules'] as $ri => $rule) if (($rule['name'] ?? '') === ($mr['name'] ?? '') && json_encode($rule['conditions'] ?? []) === json_encode($mr['conditions'] ?? [])) { $idx = $ri; break; }
            if ($mr !== null && $idx === null) foreach ($send['rules'] as $ri => $rule) if (($rule['name'] ?? '') === ($mr['name'] ?? '')) { $idx = $ri; break; }
            $results[$i] = ['matched' => !empty($r['matched']), 'idx' => $idx, 'type' => (string) ($r['responseType'] ?? '')];
        }
        curl_multi_close($mh);
    }
    $match = [];
    foreach ($results as $i => $res) $match[$mkeys[$i]] = $res;
    $ts = time();
    admin_cache_put('pr_map', ['ts' => $ts, 'cfg_hash' => $cfgHash, 'match' => $match]);
    return pr_map_build($send, $cl, $mkeys, $match, $ts);
}

// Раскладка групп по ступеням из сохранённых ответов тестера и свежих счётчиков лога.
function pr_map_build(array $send, array $cl, array $mkeys, array $match, $ts) {
    $byRule = []; $none = []; $untested = []; $caught = 0;
    foreach ($cl['groups'] as $i => $g) {
        $res = $match[$mkeys[$i]] ?? null;
        $gi = ['id' => $g['id'], 'name' => $g['name'], 'os' => $g['os'], 'n' => (int) $g['n'], 'vers' => $g['vers'], 'ua' => $g['ua'], 'synthetic' => !empty($g['synthetic']), 'type' => (string) ($res['type'] ?? '')];
        if ($res === null) {
            $untested[] = $gi;
        } elseif (!empty($res['matched']) && $res['idx'] !== null) {
            if (!isset($byRule[$res['idx']])) $byRule[$res['idx']] = ['n' => 0, 'groups' => []];
            $byRule[$res['idx']]['n'] += $gi['n'];
            $byRule[$res['idx']]['groups'][] = $gi;
            $caught += $gi['n'];
        } else {
            $none[] = $gi;
        }
    }
    $total = (int) $cl['total'];
    // Сколько запросов доходит до каждой ступени: всё, что не поймали правила выше.
    $reach = []; $left = $total;
    foreach ($send['rules'] as $ri => $rule) { $reach[$ri] = $left; $left -= (int) ($byRule[$ri]['n'] ?? 0); }
    return ['ts' => (int) $ts, 'hours' => (int) $cl['hours'], 'clients_ts' => (int) $cl['ts'], 'total' => $total, 'groups_n' => count($cl['groups']), 'caught' => $caught,
            'by_rule' => $byRule, 'none' => $none, 'none_n' => array_sum(array_column($none, 'n')), 'untested' => $untested, 'untested_n' => array_sum(array_column($untested, 'n')),
            'reach' => $reach, 'cfg_hash' => md5(json_encode($send, JSON_UNESCAPED_UNICODE))];
}

// Запросы по прямым ссылкам /json, /mihomo и т.п. за $days дней — что сломается, если их закрыть.
function pr_paths_stat($days = 30) {
    $c = admin_cache_get('pr_paths', 600);
    if (is_array($c)) return $c;
    $out = ['n' => 0, 'apps' => [], 'days' => $days];
    if ($p = db()) {
        try {
            $since = time() - $days * 86400;
            $like = "(path LIKE '%/json' OR path LIKE '%/v2ray-json' OR path LIKE '%/mihomo' OR path LIKE '%/stash' OR path LIKE '%/clash' OR path LIKE '%/singbox')";
            $st = $p->prepare("SELECT user_agent, COUNT(*) c FROM request_log WHERE path IS NOT NULL AND " . $like . " AND decision NOT IN ('browser', 'error') AND " . sql_epoch('ts') . " >= ? GROUP BY user_agent ORDER BY c DESC LIMIT 50");
            $st->execute([$since]);
            $apps = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $out['n'] += (int) $row['c'];
                $cl = reqlog_client((string) $row['user_agent']);
                $nm = (string) ($cl['app'] ?? '');
                if ($nm === '') $nm = mb_substr((string) $row['user_agent'], 0, 30);
                $nm = preg_replace('~\s+\S*\d\S*$~', '', $nm) ?: $nm;
                $apps[$nm] = ($apps[$nm] ?? 0) + (int) $row['c'];
            }
            arsort($apps);
            foreach (array_slice($apps, 0, 4, true) as $nm => $n) $out['apps'][] = ['name' => $nm, 'n' => $n];
        } catch (Throwable $e) {}
    }
    admin_cache_put('pr_paths', $out);
    return $out;
}

// ---------- справочники панели ----------

function pr_tags(&$error = '') {
    $error = '';
    $hosts = panel_ref('hosts', 600, $error);
    $out = [];
    foreach ((array) $hosts as $h) foreach ((array) ($h['tags'] ?? []) as $t) { $t = (string) $t; if ($t !== '') $out[$t] = ($out[$t] ?? 0) + 1; }
    ksort($out);
    $list = [];
    foreach ($out as $t => $n) $list[] = ['tag' => $t, 'n' => $n];
    return $list;
}

function pr_templates(&$error = '') {
    $error = '';
    $c = admin_cache_get('pr_templates', 600);
    if (is_array($c) && isset($c['list'])) return $c['list'];
    $list = remnawave_sub_templates($error);
    if ($error === '') admin_cache_put('pr_templates', ['list' => $list]);
    return $list;
}

// Поиск пользователя для правил «конкретный пользователь»: по имени или по shortUuid.
function pr_user_find($q, &$error = '') {
    $error = '';
    $q = trim((string) $q);
    if ($q === '') { $error = 'Пустой запрос'; return null; }
    $u = null;
    if (preg_match('~^[A-Za-z0-9_-]{6,64}$~', $q)) { $e = ''; $u = remnawave_get_user_by_short($q, $e); }
    if (!is_array($u)) { $e = ''; $u = remnawave_get_user_by_username($q, $e); if (!is_array($u)) { $error = 'Пользователь не найден'; return null; } }
    return ['username' => (string) ($u['username'] ?? ''), 'short' => (string) ($u['shortUuid'] ?? ''), 'uuid' => (string) ($u['uuid'] ?? ''), 'status' => (string) ($u['status'] ?? '')];
}

function pr_user_devices($ref, &$error = '') {
    $error = '';
    $out = [];
    foreach (remnawave_user_hwids($ref, $error) as $d) {
        if (!is_array($d) || empty($d['hwid'])) continue;
        $out[] = ['hwid' => (string) $d['hwid'], 'platform' => (string) ($d['platform'] ?? ''), 'model' => (string) ($d['deviceModel'] ?? ''), 'os' => (string) ($d['osVersion'] ?? ''), 'ua' => (string) ($d['userAgent'] ?? '')];
    }
    return $out;
}

// Каталог приложений для условий: ключ, подпись, с чего начинается User-Agent.
function pr_app_catalog() {
    $out = [];
    foreach (client_catalog() as $c) {
        if (empty($c['rk'])) continue;
        $out[] = ['key' => (string) $c['rk'], 'label' => (string) $c['label'], 'ua' => (string) $c['ua'], 'sample' => (string) ($c['sample'] ?? ''), 'group' => (string) ($c['rg'] ?? 'other')];
    }
    return $out;
}

// Известные заголовки для «что поменять», из каталога вкладки «Правила ответа» прослойки.
function pr_known_headers() {
    if (!function_exists('app_headers_catalog')) return [];
    $out = [];
    foreach (app_headers_catalog() as $grp => $items) foreach ((array) $items as $it) {
        if (!is_array($it) || empty($it['name'])) continue;
        $out[] = ['name' => (string) $it['name'], 'group' => (string) $grp, 'note' => (string) ($it['note'] ?? ''), 'ex' => (string) ($it['ex'] ?? '')];
    }
    return $out;
}

function pr_vars() {
    return ['USERNAME', 'ID', 'SHORT_UUID', 'SUBSCRIPTION_URL', 'EMAIL', 'TELEGRAM_ID', 'TAG', 'DESCRIPTION', 'STATUS', 'DAYS_LEFT', 'EXPIRE_UNIX',
            'TRAFFIC_USED', 'TRAFFIC_LEFT', 'TOTAL_TRAFFIC', 'TRAFFIC_USED_BYTES', 'TRAFFIC_LEFT_BYTES', 'TOTAL_TRAFFIC_BYTES', 'LIFETIME_USED_BYTES',
            'RESET_STRATEGY', 'CREATED_AT_UNIX', 'LAST_TRAFFIC_RESET_AT', 'NEXT_TRAFFIC_RESET_AT', 'SS_HWID_LIMIT'];
}
