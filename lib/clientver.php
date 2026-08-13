<?php

function clientver_enabled() { return setting('clientver_enabled', '1') === '1'; }

function clientver_ttl() { return 86400; }

function clientver_sources() {
    return ['gh' => 'GitHub', 'as' => 'App Store', 'gp' => 'Google Play', 'fd' => 'F-Droid', 'cb' => 'Codeberg', 'man' => 'вручную'];
}

function clientver_modes() {
    return ['auto' => 'сравнивать', 'build' => 'версия не определяется', 'dead' => 'проект не обновляется'];
}

function clientver_platforms() {
    return ['' => 'любая', 'android' => 'Android', 'ios' => 'iOS', 'desktop' => 'десктоп'];
}

function clientver_builtin() {
    return [
        ['k' => 'happ',                 'n' => 'Happ (iOS)',              'os' => 'ios',     'src' => 'as',  'ref' => '6504287215',                'how' => 'latest',                    'cmp' => 'auto',  'man' => '', 'on' => 1],
        ['k' => 'happ',                 'n' => 'Happ (Android)',          'os' => 'android', 'src' => 'gp',  'ref' => 'com.happproxy',             'how' => 'latest',                    'cmp' => 'auto',  'man' => '', 'on' => 1],
        ['k' => 'happ',                 'n' => 'Happ (десктоп)',          'os' => 'desktop', 'src' => 'gh',  'ref' => 'Happ-proxy/happ-desktop',   'how' => 'latest',                    'cmp' => 'auto',  'man' => '', 'on' => 1],
        ['k' => 'incy',                 'n' => 'INCY (iOS)',              'os' => 'ios',     'src' => 'as',  'ref' => '6756943388',                'how' => 'latest',                    'cmp' => 'auto',  'man' => '', 'on' => 1],
        ['k' => 'incy',                 'n' => 'INCY (десктоп)',          'os' => 'desktop', 'src' => 'gh',  'ref' => 'INCY-DEV/incy-platforms',   'how' => 'tag:desktop-v',             'cmp' => 'auto',  'man' => '', 'on' => 1],
        ['k' => 'incy',                 'n' => 'INCY (Android)',          'os' => 'android', 'src' => 'gp',  'ref' => 'llc.itdev.incy',            'how' => 'latest',                    'cmp' => 'auto',  'man' => '', 'on' => 1],
        ['k' => 'flclashx',             'n' => 'FlClashX',                'os' => '',        'src' => 'gh',  'ref' => 'pluralplay/FlClashX',       'how' => 'latest',                    'cmp' => 'auto',  'man' => '', 'on' => 1],
        ['k' => 'koala-clash',          'n' => 'Koala Clash (десктоп)',   'os' => '',        'src' => 'gh',  'ref' => 'coolcoala/koala-clash',     'how' => 'latest',                    'cmp' => 'auto',  'man' => '', 'on' => 1],
        ['k' => 'koala-clash',          'n' => 'Koala Clash (Android)',   'os' => 'android', 'src' => 'man', 'ref' => '',                          'how' => 'latest',                    'cmp' => 'auto',  'man' => '', 'on' => 1],
        ['k' => 'clodclash',            'n' => 'Clod Clash (десктоп)',    'os' => '',        'src' => 'gh',  'ref' => 'Mrvibecodic/clod-clash',    'how' => 'json:updater/latest.json',  'cmp' => 'auto',  'man' => '', 'on' => 1],
        ['k' => 'clodclash',            'n' => 'Clod Clash (Android)',    'os' => 'android', 'src' => 'gh',  'ref' => 'Mrvibecodic/clod-clash-android', 'how' => 'json:updater/latest.json', 'cmp' => 'auto', 'man' => '', 'on' => 1],
        ['k' => 'clash-meta/rabbithole', 'n' => 'RabbitHole',             'os' => '',        'src' => 'as',  'ref' => '6683309629',                'how' => 'latest',                    'cmp' => 'build', 'man' => '', 'on' => 1],
        ['k' => 'v2rayng',              'n' => 'v2rayNG',                 'os' => '',        'src' => 'gh',  'ref' => '2dust/v2rayNG',             'how' => 'latest',                    'cmp' => 'auto',  'man' => '', 'on' => 0],
        ['k' => 'v2rayn',               'n' => 'v2rayN',                  'os' => '',        'src' => 'gh',  'ref' => '2dust/v2rayN',              'how' => 'latest',                    'cmp' => 'auto',  'man' => '', 'on' => 0],
        ['k' => 'sfa',                  'n' => 'sing-box (Android)',      'os' => '',        'src' => 'gh',  'ref' => 'SagerNet/sing-box',         'how' => 'latest',                    'cmp' => 'auto',  'man' => '', 'on' => 0],
        ['k' => 'sfm',                  'n' => 'sing-box (macOS)',        'os' => '',        'src' => 'gh',  'ref' => 'SagerNet/sing-box',         'how' => 'latest',                    'cmp' => 'auto',  'man' => '', 'on' => 0],
        ['k' => 'sft',                  'n' => 'sing-box (tvOS)',         'os' => '',        'src' => 'gh',  'ref' => 'SagerNet/sing-box',         'how' => 'latest',                    'cmp' => 'auto',  'man' => '', 'on' => 0],
        ['k' => 'sfi',                  'n' => 'sing-box (iOS)',          'os' => '',        'src' => 'man', 'ref' => '',                          'how' => 'latest',                    'cmp' => 'auto',  'man' => '', 'on' => 0],
        ['k' => 'sing-box',             'n' => 'sing-box (ядро)',         'os' => '',        'src' => 'gh',  'ref' => 'SagerNet/sing-box',         'how' => 'latest',                    'cmp' => 'auto',  'man' => '', 'on' => 0],
        ['k' => 'nekobox',              'n' => 'NekoBox (Android)',       'os' => 'android', 'src' => 'gh',  'ref' => 'MatsuriDayo/NekoBoxForAndroid', 'how' => 'latest',                'cmp' => 'auto',  'man' => '', 'on' => 0],
        ['k' => 'nekobox',              'n' => 'NekoRay (десктоп)',       'os' => 'desktop', 'src' => 'man', 'ref' => '',                          'how' => 'latest',                    'cmp' => 'dead',  'man' => '', 'on' => 0],
        ['k' => 'husi',                 'n' => 'Husi',                    'os' => '',        'src' => 'cb',  'ref' => 'xchacha20-poly1305/husi',   'how' => 'latest',                    'cmp' => 'auto',  'man' => '', 'on' => 0],
        ['k' => 'exclave',              'n' => 'Exclave',                 'os' => '',        'src' => 'gh',  'ref' => 'ExclaveNetwork/Exclave',    'how' => 'latest',                    'cmp' => 'auto',  'man' => '', 'on' => 0],
        ['k' => 'throne',               'n' => 'Throne',                  'os' => '',        'src' => 'gh',  'ref' => 'throneproj/Throne',         'how' => 'latest',                    'cmp' => 'auto',  'man' => '', 'on' => 0],
        ['k' => 'karing',               'n' => 'Karing',                  'os' => '',        'src' => 'gh',  'ref' => 'KaringX/karing',            'how' => 'latest',                    'cmp' => 'auto',  'man' => '', 'on' => 0],
        ['k' => 'hiddifynext',          'n' => 'Hiddify',                 'os' => '',        'src' => 'gh',  'ref' => 'hiddify/hiddify-app',       'how' => 'latest',                    'cmp' => 'auto',  'man' => '', 'on' => 0],
        ['k' => 'hiddifynextx',         'n' => 'Hiddify (Xray)',          'os' => '',        'src' => 'gh',  'ref' => 'hiddify/hiddify-app',       'how' => 'latest',                    'cmp' => 'auto',  'man' => '', 'on' => 0],
        ['k' => 'flclash',              'n' => 'FlClash',                 'os' => '',        'src' => 'gh',  'ref' => 'chen08209/FlClash',         'how' => 'latest',                    'cmp' => 'auto',  'man' => '', 'on' => 0],
        ['k' => 'clash-verge',          'n' => 'Clash Verge Rev',         'os' => '',        'src' => 'gh',  'ref' => 'clash-verge-rev/clash-verge-rev', 'how' => 'latest',              'cmp' => 'auto',  'man' => '', 'on' => 0],
        ['k' => 'clash.meta',           'n' => 'mihomo (ядро)',           'os' => '',        'src' => 'gh',  'ref' => 'MetaCubeX/mihomo',          'how' => 'latest',                    'cmp' => 'auto',  'man' => '', 'on' => 0],
        ['k' => 'clashmetaforandroid',  'n' => 'Clash Meta for Android',  'os' => '',        'src' => 'gh',  'ref' => 'MetaCubeX/ClashMetaForAndroid', 'how' => 'latest',                'cmp' => 'auto',  'man' => '', 'on' => 0],
        ['k' => 'clashx',               'n' => 'ClashX Meta',             'os' => '',        'src' => 'gh',  'ref' => 'MetaCubeX/ClashX.Meta',     'how' => 'latest',                    'cmp' => 'auto',  'man' => '', 'on' => 0],
        ['k' => 'streisand',            'n' => 'Streisand',               'os' => '',        'src' => 'as',  'ref' => '6450534064',                'how' => 'latest',                    'cmp' => 'build', 'man' => '', 'on' => 0],
        ['k' => 'shadowrocket',         'n' => 'Shadowrocket',            'os' => '',        'src' => 'as',  'ref' => '932747118',                 'how' => 'latest',                    'cmp' => 'build', 'man' => '', 'on' => 0],
        ['k' => 'v2box',                'n' => 'V2Box',                   'os' => '',        'src' => 'as',  'ref' => '6446814690',                'how' => 'latest',                    'cmp' => 'auto',  'man' => '', 'on' => 0],
        ['k' => 'foxray',               'n' => 'FoXray',                  'os' => '',        'src' => 'man', 'ref' => '',                          'how' => 'latest',                    'cmp' => 'dead',  'man' => '', 'on' => 0],
        ['k' => 'matsuri',              'n' => 'Matsuri',                 'os' => '',        'src' => 'man', 'ref' => '',                          'how' => 'latest',                    'cmp' => 'dead',  'man' => '', 'on' => 0],
    ];
}

function clientver_options($map, $cur) {
    $out = '';
    foreach ($map as $k => $v) {
        $out .= '<option value="' . htmlspecialchars((string) $k, ENT_QUOTES, 'UTF-8') . '"'
              . ((string) $cur === (string) $k ? ' selected' : '') . '>'
              . htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8') . '</option>';
    }
    return $out;
}

function clientver_row_id($r) {
    return strtolower(trim((string) ($r['k'] ?? ''))) . '|' . strtolower(trim((string) ($r['os'] ?? '')));
}

function clientver_anchor($key, $os = '') {
    $a = preg_replace('~[^a-z0-9-]+~', '-', strtolower((string) $key) . '-' . strtolower((string) $os));
    return 'k-' . trim((string) $a, '-');
}

function clientver_clean_row($r) {
    if (!is_array($r)) return null;
    $k = strtolower(trim((string) ($r['k'] ?? '')));
    if ($k === '' || !preg_match('~^[a-z0-9._/-]{1,40}$~', $k)) return null;
    $os = strtolower(trim((string) ($r['os'] ?? '')));
    if (!array_key_exists($os, clientver_platforms())) $os = '';
    $src = (string) ($r['src'] ?? 'man');
    if (!array_key_exists($src, clientver_sources())) $src = 'man';
    $ref = trim((string) ($r['ref'] ?? ''));
    if ($src === 'as') {
        if (!preg_match('~^\d{5,12}$~', $ref)) $ref = '';
    } elseif ($src === 'gp' || $src === 'fd') {
        if (!preg_match('~^[A-Za-z][A-Za-z0-9_]*(\.[A-Za-z0-9_]+){1,9}$~', $ref) || strlen($ref) > 120) $ref = '';
    } elseif ($src === 'gh' || $src === 'cb') {
        if (!preg_match('~^[A-Za-z0-9._-]{1,39}/[A-Za-z0-9._-]{1,100}$~', $ref)) $ref = '';
    } else {
        $ref = '';
    }
    $how = trim((string) ($r['how'] ?? 'latest'));
    if (!preg_match('~^(latest|tag:[A-Za-z0-9._-]{1,20}|json:[A-Za-z0-9._-]{1,40}/[A-Za-z0-9._-]{1,40})$~', $how)) $how = 'latest';
    if (strpos($how, '..') !== false) $how = 'latest';
    $cmp = (string) ($r['cmp'] ?? 'auto');
    if (!array_key_exists($cmp, clientver_modes())) $cmp = 'auto';
    $man = trim((string) ($r['man'] ?? ''));
    if ($man !== '' && !preg_match('~^[0-9][0-9A-Za-z._-]{0,31}$~', $man)) $man = '';
    $n = trim((string) ($r['n'] ?? ''));
    if ($n === '') $n = $k;
    return [
        'k' => $k, 'n' => mb_substr($n, 0, 60), 'os' => $os, 'src' => $src,
        'ref' => $ref, 'how' => $how, 'cmp' => $cmp, 'man' => $man,
        'on' => empty($r['on']) ? 0 : 1,
    ];
}

function clientver_catalog() {
    if (isset($GLOBALS['submw_cv_cat'])) return $GLOBALS['submw_cv_cat'];
    $raw = (string) setting('clientver_catalog', '');
    $def = array_values(array_filter(clientver_builtin(), fn($r) => !empty($r['on'])));
    if (trim($raw) === '') { $GLOBALS['submw_cv_cat'] = $def; return $def; }
    $arr = json_decode($raw, true);
    if (!is_array($arr)) { $GLOBALS['submw_cv_cat'] = $def; return $def; }
    $out = [];
    $seen = [];
    foreach ($arr as $r) {
        $c = clientver_clean_row($r);
        if ($c === null) continue;
        $id = clientver_row_id($c);
        if (isset($seen[$id])) continue;
        $seen[$id] = 1;
        $out[] = $c;
    }
    $GLOBALS['submw_cv_cat'] = $out;
    return $out;
}

function clientver_save_catalog(array $rows) {
    $out = [];
    $seen = [];
    foreach ($rows as $r) {
        $c = clientver_clean_row($r);
        if ($c === null) continue;
        $id = clientver_row_id($c);
        if (isset($seen[$id])) continue;
        $seen[$id] = 1;
        $out[] = $c;
    }
    set_setting('clientver_catalog', json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $GLOBALS['submw_cv_cat'] = $out;
    // Стейт хранится по ключу k|os — записи удалённых/переименованных строк
    // иначе копятся в настройке вечно.
    $st = clientver_state();
    if (is_array($st['rows'] ?? null) && $st['rows']) {
        $ids = [];
        foreach ($out as $r) $ids[clientver_row_id($r)] = 1;
        $keep = array_intersect_key($st['rows'], $ids);
        if (count($keep) !== count($st['rows'])) { $st['rows'] = $keep; clientver_save_state($st); }
    }
    return count($out);
}

function clientver_reset_catalog() {
    set_setting('clientver_catalog', '');
    unset($GLOBALS['submw_cv_cat']);
}

function clientver_state() {
    if (isset($GLOBALS['submw_cv_state'])) return $GLOBALS['submw_cv_state'];
    $s = json_decode((string) setting('clientver_state', '{}'), true);
    if (!is_array($s)) $s = [];
    if (!isset($s['rows']) || !is_array($s['rows'])) $s['rows'] = [];
    $GLOBALS['submw_cv_state'] = $s;
    return $s;
}

function clientver_save_state($s) {
    set_setting('clientver_state', json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $GLOBALS['submw_cv_state'] = $s;
}

function clientver_norm($v) {
    $v = strtolower(trim((string) $v));
    if ($v === '') return '';
    $v = preg_replace('~^v~', '', $v);
    $v = preg_replace('~[-+].*$~', '', $v);
    $v = preg_replace('~[^0-9.].*$~', '', $v);
    $v = trim($v, '.');
    if ($v === '') return '';
    $parts = array_slice(array_filter(explode('.', $v), fn($x) => $x !== ''), 0, 3);
    return implode('.', $parts);
}

function clientver_is_build($v) {
    $n = clientver_norm($v);
    return $n !== '' && strpos($n, '.') === false;
}

function clientver_diff($cur, $latest) {
    $a = clientver_norm($cur);
    $b = clientver_norm($latest);
    if ($a === '' || $b === '') return 'unknown';
    if (version_compare($a, $b, '>=')) return version_compare($a, $b, '>') ? 'ahead' : 'ok';
    $pa = array_map('intval', array_pad(explode('.', $a), 3, 0));
    $pb = array_map('intval', array_pad(explode('.', $b), 3, 0));
    if ($pa[0] !== $pb[0] || $pa[1] !== $pb[1]) return 'minor';
    return 'patch';
}

function clientver_http($url, &$err = null, $accept = 'application/json', $headers = [], $max = 1048576) {
    $err = null;
    if (!function_exists('curl_init')) { $err = 'curl недоступен'; return null; }
    $buf = '';
    $capped = false;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_USERAGENT      => 'submw-clientver',
        CURLOPT_ENCODING       => '',
        CURLOPT_HTTPHEADER     => array_merge(['Accept: ' . $accept], $headers),
        CURLOPT_WRITEFUNCTION  => function ($c, $chunk) use (&$buf, $max, &$capped) {
            $buf .= $chunk;
            // Вернуть не-длину = оборвать передачу: без этого лимит экономит только
            // память, а хвост тела всё равно выкачивается целиком.
            if (strlen($buf) >= $max) { $capped = true; return 0; }
            return strlen($chunk);
        },
    ]);
    $ok = curl_exec($ch);
    $neterr = ($ok === false) ? curl_error($ch) : '';
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $body = $buf;
    // Свой обрыв по лимиту — не ошибка: нужного объёма уже достаточно.
    // А вот сеть, оборвавшаяся посреди тела, — ошибка, даже если что-то накачалось:
    // регулярки по обрезанной странице промахиваются молча.
    if ($ok === false && !$capped) { $err = 'сеть: ' . $neterr; return null; }
    if ($code === 403 || $code === 429) { $err = 'лимит запросов (' . $code . '), попробуйте позже'; return null; }
    if ($code === 404) { $err = 'не найдено (404) — проверьте адрес источника'; return null; }
    if ($code < 200 || $code >= 300) { $err = 'HTTP ' . $code; return null; }
    return $body;
}

function clientver_json($url, &$err = null, $accept = 'application/json') {
    $body = clientver_http($url, $err, $accept, [], 1048576);
    if ($body === null) return null;
    $j = json_decode($body, true);
    if (!is_array($j)) { $err = 'некорректный ответ источника'; return null; }
    return $j;
}

function clientver_from_gh($ref, $how, &$err = null) {
    if (strpos($how, 'json:') === 0) {
        $path = substr($how, 5);
        $j = clientver_json('https://github.com/' . $ref . '/releases/download/' . $path, $err);
        if ($j === null) return null;
        $v = trim((string) ($j['version'] ?? ''));
        if ($v === '') { $err = 'в манифесте нет поля version'; return null; }
        return ['v' => $v, 'd' => (string) ($j['pub_date'] ?? '')];
    }
    if (strpos($how, 'tag:') === 0) {
        $pref = substr($how, 4);
        $j = clientver_json('https://api.github.com/repos/' . $ref . '/releases?per_page=30', $err, 'application/vnd.github+json');
        if ($j === null) return null;
        foreach ($j as $rel) {
            if (!is_array($rel) || !empty($rel['draft'])) continue;
            $tag = (string) ($rel['tag_name'] ?? '');
            if ($tag === '' || stripos($tag, $pref) !== 0) continue;
            return ['v' => substr($tag, strlen($pref)), 'd' => (string) ($rel['published_at'] ?? '')];
        }
        $err = 'нет релизов с префиксом ' . $pref;
        return null;
    }
    $j = clientver_json('https://api.github.com/repos/' . $ref . '/releases/latest', $err, 'application/vnd.github+json');
    if ($j === null) return null;
    $tag = trim((string) ($j['tag_name'] ?? ''));
    if ($tag === '') { $err = 'в ответе нет тега релиза'; return null; }
    return ['v' => $tag, 'd' => (string) ($j['published_at'] ?? '')];
}

function clientver_from_cb($ref, &$err = null) {
    $j = clientver_json('https://codeberg.org/api/v1/repos/' . $ref . '/releases/latest', $err);
    if ($j === null) return null;
    $tag = trim((string) ($j['tag_name'] ?? ''));
    if ($tag === '') { $err = 'в ответе нет тега релиза'; return null; }
    return ['v' => $tag, 'd' => (string) ($j['published_at'] ?? '')];
}

function clientver_from_as($id, &$err = null) {
    $j = clientver_json('https://itunes.apple.com/lookup?id=' . $id . '&country=us', $err);
    if ($j === null) return null;
    $first = is_array($j['results'] ?? null) ? ($j['results'][0] ?? null) : null;
    if (!is_array($first)) { $err = 'в App Store (US) приложение не найдено'; return null; }
    $v = trim((string) ($first['version'] ?? ''));
    if ($v === '') { $err = 'в ответе нет версии'; return null; }
    return ['v' => $v, 'd' => (string) ($first['currentVersionReleaseDate'] ?? '')];
}

function clientver_from_fd($pkg, &$err = null) {
    $j = clientver_json('https://f-droid.org/api/v1/packages/' . rawurlencode($pkg), $err);
    if ($j === null) return null;
    if (!is_array($j['packages'] ?? null) || !$j['packages']) { $err = 'в F-Droid пакет не найден'; return null; }
    $want = (int) ($j['suggestedVersionCode'] ?? 0);
    $first = null;
    foreach ($j['packages'] as $p) {
        if (!is_array($p) || (string) ($p['versionName'] ?? '') === '') continue;
        if ($first === null) $first = (string) $p['versionName'];
        if ($want && (int) ($p['versionCode'] ?? 0) === $want) return ['v' => (string) $p['versionName'], 'd' => ''];
    }
    if ($first === null) { $err = 'в ответе нет версии'; return null; }
    return ['v' => $first, 'd' => ''];
}

function clientver_from_gp($pkg, &$err = null) {
    $body = clientver_http('https://play.google.com/store/apps/details?id=' . rawurlencode($pkg) . '&hl=en&gl=us',
        $err, 'text/html', ['Accept-Language: en-US,en;q=0.9'], 4194304);
    if ($body === null) return null;
    if (stripos($body, 'Varies with device') !== false && !preg_match('~\[\[\["\d~', $body)) {
        $err = 'в Google Play версия зависит от устройства';
        return null;
    }
    if (preg_match('~\[\[\["([0-9][0-9A-Za-z._-]{0,31})"\]\],\[\[\[\d~', $body, $m)) return ['v' => $m[1], 'd' => ''];
    if (preg_match('~"([0-9]+\.[0-9]+(?:\.[0-9]+)*)",null,null,null,null,null,1\]~', $body, $m)) return ['v' => $m[1], 'd' => ''];
    $err = 'не удалось разобрать страницу Google Play';
    return null;
}

function clientver_fetch($r, &$err = null) {
    $err = null;
    $src = (string) ($r['src'] ?? 'man');
    $ref = (string) ($r['ref'] ?? '');
    if ($src === 'man') {
        $v = trim((string) ($r['man'] ?? ''));
        if ($v === '') { $err = 'версия не задана вручную'; return null; }
        return ['v' => $v, 'd' => ''];
    }
    if ($ref === '') { $err = 'адрес источника не задан'; return null; }
    if ($src === 'gh') return clientver_from_gh($ref, (string) ($r['how'] ?? 'latest'), $err);
    if ($src === 'cb') return clientver_from_cb($ref, $err);
    if ($src === 'as') return clientver_from_as($ref, $err);
    if ($src === 'fd') return clientver_from_fd($ref, $err);
    if ($src === 'gp') return clientver_from_gp($ref, $err);
    $err = 'неизвестный источник';
    return null;
}

function clientver_refresh_row($r) {
    $id = clientver_row_id($r);
    $err = null;
    $res = clientver_fetch($r, $err);
    $st = clientver_state();
    $prev = is_array($st['rows'][$id] ?? null) ? $st['rows'][$id] : [];
    $st['rows'][$id] = [
        'v' => $res !== null ? (string) $res['v'] : (string) ($prev['v'] ?? ''),
        'd' => $res !== null ? (string) $res['d'] : (string) ($prev['d'] ?? ''),
        't' => time(),
        'e' => $res === null ? (string) $err : '',
    ];
    $st['checked_at'] = time();
    clientver_save_state($st);
    return $res !== null;
}

function clientver_refresh_all($deadline = 25) {
    $ok = 0; $bad = 0; $left = 0;
    $t0 = time();
    foreach (clientver_catalog() as $r) {
        if (empty($r['on']) || ($r['cmp'] ?? '') === 'dead' || ($r['src'] ?? '') === 'man') continue;
        if (time() - $t0 >= $deadline) { $left++; continue; }
        if (clientver_refresh_row($r)) $ok++; else $bad++;
    }
    return [$ok, $bad, $left];
}

function clientver_autocheck($budget = 1) {
    if (!clientver_enabled()) return 0;
    $now = time();
    $n = 0;
    foreach (clientver_catalog() as $r) {
        if ($n >= $budget) break;
        if (empty($r['on']) || ($r['cmp'] ?? '') === 'dead' || ($r['src'] ?? '') === 'man') continue;
        $st = clientver_state();
        $id = clientver_row_id($r);
        if ($now - (int) ($st['rows'][$id]['t'] ?? 0) < clientver_ttl()) continue;
        clientver_refresh_row($r);
        $n++;
    }
    return $n;
}

function clientver_firstrun($limit = 15, $deadline = 8) {
    // Первый прогон каталога идёт в рендере страницы, поэтому кроме лимита строк
    // обязателен лимит времени: один медленный источник (Play — 4 МБ HTML) иначе
    // держит вкладку до таймаута fpm. Недоопрошенные строки покажут статус
    // «источник ещё не опрошен» и доедут следующими заходами или кнопкой.
    if (!clientver_enabled()) return 0;
    $n = 0;
    $t0 = time();
    foreach (clientver_catalog() as $r) {
        if ($n >= $limit || time() - $t0 >= $deadline) break;
        if (empty($r['on']) || ($r['cmp'] ?? '') === 'dead' || ($r['src'] ?? '') === 'man') continue;
        if (clientver_row_checked($r) > 0) continue;
        clientver_refresh_row($r);
        $n++;
    }
    return $n;
}

function clientver_latest($r) {
    if (($r['src'] ?? '') === 'man') return trim((string) ($r['man'] ?? ''));
    $st = clientver_state();
    return (string) ($st['rows'][clientver_row_id($r)]['v'] ?? '');
}

function clientver_row_err($r) {
    $st = clientver_state();
    return (string) ($st['rows'][clientver_row_id($r)]['e'] ?? '');
}

function clientver_row_checked($r) {
    if (($r['src'] ?? '') === 'man') return 0;
    $st = clientver_state();
    return (int) ($st['rows'][clientver_row_id($r)]['t'] ?? 0);
}

function clientver_find($key, $os) {
    $key = strtolower(trim((string) $key));
    if ($key === '') return null;
    $os = strtolower(trim((string) $os));
    $wild = null;
    foreach (clientver_catalog() as $r) {
        if ($r['k'] !== $key) continue;
        if ($r['os'] === $os) return $r;
        if ($r['os'] === '' && $wild === null) $wild = $r;
    }
    return $wild;
}

function clientver_status($key, $ver, $os = '') {
    $out = ['s' => 'none', 'latest' => '', 'name' => '', 'cur' => (string) $ver, 'anchor' => ''];
    if (!clientver_enabled()) return $out;
    $r = clientver_find($key, $os);
    if ($r === null || empty($r['on'])) return $out;
    $out['name'] = (string) $r['n'];
    $out['anchor'] = clientver_anchor($r['k'], $r['os']);
    if (($r['cmp'] ?? '') === 'dead') { $out['s'] = 'dead'; return $out; }
    $out['latest'] = clientver_latest($r);
    if (($r['cmp'] ?? '') === 'build' || $ver === '' || clientver_is_build($ver)) { $out['s'] = 'nover'; return $out; }
    if ($out['latest'] === '') {
        if (($r['src'] ?? '') === 'man') { $out['s'] = 'manual'; return $out; }
        $out['s'] = clientver_row_checked($r) ? 'unknown' : 'wait';
        return $out;
    }
    $out['s'] = clientver_diff($ver, $out['latest']);
    return $out;
}

function clientver_label($s) {
    $map = [
        'ok'      => 'актуальная версия',
        'ahead'   => 'новее известной — предрелиз',
        'patch'   => 'вышло обновление',
        'minor'   => 'версия сильно отстала',
        'dead'    => 'проект не обновляется',
        'nover'   => 'версия не определяется',
        'wait'    => 'источник ещё не опрошен',
        'manual'  => 'версия не задана вручную',
        'unknown' => 'актуальную версию узнать не удалось',
    ];
    return $map[$s] ?? '';
}

function clientver_seen($hours = 168, $limit = 300) {
    $out = [];
    if (!($p = db())) return $out;
    try {
        $st = $p->prepare("SELECT user_agent, MAX(meta) m, COUNT(*) c, MAX(" . sql_epoch('ts') . ") last FROM request_log WHERE user_agent IS NOT NULL AND user_agent <> '' AND decision <> 'browser' AND " . sql_epoch('ts') . " >= ? GROUP BY user_agent ORDER BY c DESC");
        $st->execute([time() - max(1, (int) $hours) * 3600]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return $out; }
    $i = 0;
    foreach ($rows as $row) {
        if ($i >= $limit) break;
        $cl = reqlog_client((string) $row['user_agent']);
        if (($cl['key'] ?? '') === '') continue;
        $os = (string) $cl['os'];
        if ($os === '') {
            $mt = json_decode((string) ($row['m'] ?? ''), true);
            if (is_array($mt)) $os = reqlog_os_norm((string) ($mt['dv']['o'] ?? ''));
        }
        $id = $cl['key'] . '|' . $os;
        if (!isset($out[$id])) {
            // Имя без хвоста-версии: reqlog_client() приклеивает версию к названию,
            // а имя строки каталога с версией протухает с первым же обновлением.
            $app = (string) $cl['app'];
            $cv  = (string) ($cl['ver'] ?? '');
            if ($cv !== '' && substr($app, -strlen(' ' . $cv)) === ' ' . $cv) $app = substr($app, 0, -strlen(' ' . $cv));
            $out[$id] = ['key' => $cl['key'], 'os' => $os, 'app' => $cl['app'], 'name' => $app, 'vers' => [], 'n' => 0, 'last' => 0];
            $i++;
        }
        $out[$id]['n'] += (int) $row['c'];
        $out[$id]['last'] = max($out[$id]['last'], (int) $row['last']);
        $v = (string) ($cl['ver'] ?? '');
        if ($v !== '' && !in_array($v, $out[$id]['vers'], true) && count($out[$id]['vers']) < 6) $out[$id]['vers'][] = $v;
    }
    return $out;
}

function clientver_builtin_find($key, $os) {
    $key = strtolower(trim((string) $key));
    $os  = strtolower(trim((string) $os));
    $wild = null;
    foreach (clientver_builtin() as $r) {
        if ($r['k'] !== $key) continue;
        if ($r['os'] === $os) return $r;
        if ($r['os'] === '' && $wild === null) $wild = $r;
    }
    return $wild;
}

function clientver_unknown_seen($hours = 168) {
    $out = [];
    foreach (clientver_seen($hours) as $id => $s) {
        if (clientver_find($s['key'], $s['os']) !== null) continue;
        $b = clientver_builtin_find($s['key'], $s['os']);
        if ($b !== null) $b['on'] = 1;
        $nm = (string) ($s['name'] !== '' ? $s['name'] : $s['key']);
        $s['pick'] = $b ?? ['k' => $s['key'], 'n' => $nm, 'os' => $s['os'], 'src' => 'man', 'ref' => '', 'how' => 'latest', 'cmp' => 'auto', 'man' => '', 'on' => 1];
        $out[$id] = $s;
    }
    return $out;
}

function clientver_outdated($hours = 24) {
    if (!clientver_enabled()) return 0;
    $n = 0;
    foreach (clientver_seen($hours) as $s) {
        foreach ($s['vers'] as $v) {
            $st = clientver_status($s['key'], $v, $s['os']);
            if ($st['s'] === 'patch' || $st['s'] === 'minor') { $n++; break; }
        }
    }
    return $n;
}
