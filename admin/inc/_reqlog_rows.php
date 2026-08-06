<?php

function reqlog_prepare() {
    static $cache = null;
    if ($cache !== null) return $cache;
    $f = reqlog_filters();
    $names = []; $users = []; $total_users = 0;
    $err = '';
    foreach (remnawave_all_users($err) as $u) {
        $su = (string) ($u['shortUuid'] ?? '');
        $total_users++;
        if ($su === '') continue;
        $names[$su] = (string) ($u['username'] ?? '');
        $users[$su] = [
            'status' => (string) ($u['status'] ?? ''),
            'lim'    => (isset($u['hwidDeviceLimit']) && $u['hwidDeviceLimit'] !== null && $u['hwidDeviceLimit'] !== '') ? (int) $u['hwidDeviceLimit'] : '',
        ];
    }
    $name2short = [];
    foreach ($names as $su => $nm) $name2short[$nm] = $su;
    $rows = reqlog_fetch($f, $name2short, 300);
    $ov = [];
    $ov_rows = [];
    if ($p = db()) {
        try { $ov_rows = $p->query("SELECT match_value, username, note FROM overrides WHERE match_type = 'hwid'")->fetchAll(PDO::FETCH_ASSOC); }
        catch (Throwable $e) { $ov_rows = []; }
    }
    foreach ($ov_rows as $o) {
        $o['match_type'] = 'hwid';
        if (($o['match_type'] ?? '') === 'hwid') {
            $lbl = trim((string) ($o['username'] ?? ''));
            if ($lbl === '') $lbl = trim((string) ($o['note'] ?? ''));
            $ov[mb_strtolower((string) $o['match_value'])] = $lbl;
        }
    }
    $ctx = [
        'names' => $names,
        'users' => $users,
        'idx'   => reqlog_user_index(),
        'hist'  => reqlog_history($rows),
        'ov'    => $ov,
        'base'  => ['tab' => 'reqlog'],
    ];
    $cache = [$f, $rows, $ctx, $total_users];
    return $cache;
}

function rl_dec_tag($dec, $short = false) {
    if ($dec === 'blocked') return '<span class="tag blocked" title="запрос от неразрешённого устройства">' . ($short ? 'blocked' : 'HWID blocked') . '</span>';
    if ($dec === 'grace')   return $short
        ? '<span class="tag grace" title="выдан грейс-сквад">грейс</span>'
        : '<span class="tag normal">normal <span style="opacity:.6">(грейс)</span></span>';
    if ($dec === 'expired') return '<span class="tag expired">expired</span>';
    if ($dec === 'error')   return '<span class="tag error">error</span>';
    return '<span class="tag normal">' . h($dec) . '</span>';
}

function rl_dec_why($dec, $meta) {
    if ($dec === 'blocked') return 'устройство не в списке разрешённых';
    if ($dec === 'grace')   return 'грейс-сквад активен';
    if ($dec === 'expired') return 'срок подписки вышел';
    if ($dec === 'error')   return 'панель не ответила или вернула ошибку';
    if (!empty($meta['wg']))   return 'подписка активна, добавлены конфиги пула';
    return 'подписка активна';
}

function rl_fmt_tag($fmt, $short = false) {
    $label = reqlog_fmt_label($fmt, $short);
    if ($label === '') return '<span class="dim">—</span>';
    $cls = ['base64' => 'base64', 'json' => 'json', 'clash' => 'clash', 'singbox' => 'singbox', 'wg' => 'wg', 'page' => 'page'];
    $ttl = $short && $fmt === 'page' ? ' title="страница подписки"' : '';
    return '<span class="ft ' . h($cls[$fmt] ?? 'other') . '"' . $ttl . '><span class="dt"></span>' . h($label) . '</span>';
}

function rl_as_badge($as) {
    $s = (string) ($as['s'] ?? '');
    $n = (int) ($as['n'] ?? 0);
    if ($s === 'on')   return '<span class="as on">' . ($n > 0 ? '+' . $n . ' слита' : 'слита') . '</span>';
    if ($s === 'stub') return '<span class="as stub">трафик</span>';
    if ($s === 'err')  return '<span class="as err">ошибка</span>';
    if ($s === 'off')  return '<span class="as off">выкл</span>';
    if ($s === 'no')   return '<span class="as no">нет</span>';
    return '<span class="as no">—</span>';
}

function rl_size($b) {
    $b = (int) $b;
    if ($b <= 0) return '—';
    if ($b < 1024) return $b . ' Б';
    if ($b < 1048576) return number_format($b / 1024, 1, ',', ' ') . ' КБ';
    return number_format($b / 1048576, 2, ',', ' ') . ' МБ';
}

function rl_left($ts) {
    $ts = (int) $ts;
    if ($ts <= 0) return ['—', ''];
    $d = $ts - time();
    $days = (int) floor(abs($d) / 86400);
    $hrs  = (int) floor(abs($d) / 3600);
    if ($d < 0) return [$days > 0 ? ('истёк ' . $days . ' дн. назад') : ('истёк ' . max(1, $hrs) . ' ч назад'), 'exp-bad'];
    if ($days <= 3) return [$days > 0 ? ('через ' . $days . ' дн.') : ('через ' . max(1, $hrs) . ' ч'), 'exp-soon'];
    return ['через ' . $days . ' дн.', 'exp-ok'];
}

function rl_hist_bar($list) {
    if (!$list) return '<span class="dim">—</span>';
    $out = '';
    foreach ($list as $d) {
        $c = $d === 'blocked' ? ' b' : ($d === 'grace' ? ' g' : ($d === 'expired' || $d === 'error' ? ' e' : ''));
        $out .= '<i class="h' . $c . '" title="' . h($d) . '"></i>';
    }
    return '<span class="hist">' . $out . '</span>';
}

function rl_link(array $over, array $base) {
    $q = array_filter(array_merge($base, $over), fn($v) => $v !== '' && $v !== null);
    return '?' . http_build_query($q);
}

function reqlog_render_rows(array $rows, array $ctx) {
    $names = $ctx['names'] ?? [];
    $users = $ctx['users'] ?? [];
    $idx   = $ctx['idx'] ?? [];
    $hist  = $ctx['hist'] ?? [];
    $ov    = $ctx['ov'] ?? [];
    $base  = $ctx['base'] ?? ['tab' => 'reqlog'];
    $cols  = 8;

    if (!$rows) return '<tr><td colspan="' . $cols . '" class="muted">Пусто</td></tr>';

    $max = 1;
    foreach ($rows as $r) {
        $su = (string) ($r['short_uuid'] ?? '');
        if ($su !== '' && isset($idx[$su]['day'])) $max = max($max, (int) $idx[$su]['day']);
    }

    $out = '';
    $i = 0;
    foreach ($rows as $r) {
        $i++;
        $su   = (string) ($r['short_uuid'] ?? '');
        $dec  = (string) ($r['decision'] ?? 'normal');
        $meta = reqlog_meta($r);
        $as   = is_array($meta['as'] ?? null) ? $meta['as'] : [];
        $cl   = reqlog_client((string) ($r['user_agent'] ?? ''));
        $name = $su !== '' && isset($names[$su]) ? (string) $names[$su] : '';
        $u    = $users[$su] ?? [];
        $day  = (int) ($idx[$su]['day'] ?? 0);
        $hwid = (string) ($r['hwid'] ?? '');
        $fmt  = (string) ($r['fmt'] ?? '');
        $dup  = (int) ($r['dup'] ?? 1);
        $exp  = (int) ($r['expire_ts'] ?? 0);
        [$left, $lcls] = rl_left($exp);

        $who = $name !== '' ? h($name) : ($su !== '' ? '<code>' . h($su) . '</code>' : '<span class="muted">—</span>');
        $sub = $name !== '' && $su !== '' ? '<span class="su">' . h($su) . '</span>' : '';

        $out .= '<tr class="rowb" data-i="' . $i . '">'
              . '<td><span class="tgl">›</span></td>'
              . '<td class="dim mono rl-time" data-label="Время" data-ts="' . (int) ($r['ts_epoch'] ?? 0) . '">' . h(mb_substr((string) ($r['ts'] ?? ''), 11)) . ($dup > 1 ? '<span class="rep">×' . $dup . '</span>' : '') . '</td>'
              . '<td data-label="Решение">' . rl_dec_tag($dec, true) . '</td>'
              . '<td data-label="Тип ответа">' . rl_fmt_tag($fmt, true) . '</td>'
              . '<td data-label="Доп. подписка">' . rl_as_badge($as) . '</td>'
              . '<td data-label="Пользователь"><span class="u-cell"><span class="nm">' . $who . '</span>' . $sub . '</span></td>'
              . '<td data-label="Клиент"><span class="cl"><span class="c1">' . ($cl['app'] !== '' ? h($cl['app']) : '<span class="dim">—</span>') . '</span>'
              . ($cl['dev'] !== '' ? '<span class="c2">' . h($cl['dev']) . '</span>' : '') . '</span></td>'
              . '<td data-label="Запросов / сутки"><span class="cnt"><b>' . $day . '</b><span class="bar"><i style="width:' . (int) round($day / $max * 100) . '%"></i></span></span></td>'
              . '</tr>';

        $ovl = $hwid !== '' && isset($ov[mb_strtolower($hwid)]) ? (string) $ov[mb_strtolower($hwid)] : '';
        $asn = (int) ($as['n'] ?? 0);
        $asb = (int) ($as['b'] ?? 0);
        $asm = (int) ($as['ms'] ?? 0);
        $done = [];
        if (!empty($meta['wg']))    $done[] = '+' . (int) $meta['wg'] . ' из пула WG';
        if (!empty($meta['grace'])) $done[] = 'грейс-сквад';
        if (($as['s'] ?? '') === 'on') $done[] = 'доп. подписка';

        $out .= '<tr class="row-x" data-x="' . $i . '"><td colspan="' . $cols . '"><div class="xin">'
              . '<div class="xcol"><div class="xh">Запрос</div>'
              . '<div class="xr"><span class="l">Время</span><span class="v mono rl-full" data-ts="' . (int) ($r['ts_epoch'] ?? 0) . '">' . h((string) ($r['ts'] ?? '')) . '</span></div>'
              . '<div class="xr"><span class="l">IP</span><span class="v mono">' . h((string) ($r['ip'] ?? '')) . '</span></div>'
              . '<div class="xr"><span class="l">Путь</span><span class="v mono">' . h((string) ($r['path'] ?? '')) . '</span></div>'
              . '<div class="xr"><span class="l">Тип ответа</span><span class="v">' . rl_fmt_tag($fmt) . '</span></div>'
              . '<div class="xr"><span class="l">Content-Type</span><span class="v mono">' . ((string) ($r['ctype'] ?? '') !== '' ? h((string) $r['ctype']) : '—') . '</span></div>'
              . '</div>'
              . '<div class="xcol"><div class="xh">Клиент</div>'
              . '<div class="xr"><span class="l">Приложение</span><span class="v">' . ($cl['app'] !== '' ? h($cl['app']) : '—') . '</span></div>'
              . '<div class="xr"><span class="l">Устройство</span><span class="v">' . ($cl['dev'] !== '' ? h($cl['dev']) : '<span class="dim">клиент не прислал</span>') . '</span></div>'
              . '<div class="xr"><span class="l">HWID</span><span class="v mono">' . ($hwid !== '' ? h($hwid) . ($ovl !== '' ? ' <span class="dim">· ' . h($ovl) . '</span>' : '') : '—') . '</span></div>'
              . '<div class="xr"><span class="l">User-Agent</span><span class="v mono">' . ((string) ($r['user_agent'] ?? '') !== '' ? h((string) $r['user_agent']) : '—') . '</span></div>'
              . '</div>'
              . '<div class="xcol"><div class="xh">Подписка</div>'
              . '<div class="xr"><span class="l">Пользователь</span><span class="v">' . ($name !== '' ? h($name) . ' · ' : '') . '<span class="mono">' . h($su !== '' ? $su : '—') . '</span></span></div>'
              . '<div class="xr"><span class="l">Статус</span><span class="v">' . (($u['status'] ?? '') !== '' ? '<span class="tag ' . h((string) $u['status']) . '">' . h((string) $u['status']) . '</span>' : '<span class="dim">неизвестен</span>') . '</span></div>'
              . '<div class="xr"><span class="l">Expire</span><span class="v">' . ($exp > 0 ? '<span class="mono">' . h(date('Y-m-d H:i', $exp)) . '</span> · <span class="' . $lcls . '">' . h($left) . '</span>' : '—') . '</span></div>'
              . '<div class="xr"><span class="l">Решение</span><span class="v">' . rl_dec_tag($dec) . ' — ' . h(rl_dec_why($dec, $meta)) . '</span></div>'
              . '</div>'
              . '<div class="xcol"><div class="xh">Что отдано</div>'
              . '<div class="xr"><span class="l">Формат</span><span class="v">' . rl_fmt_tag($fmt) . '</span></div>'
              . '<div class="xr"><span class="l">Размер</span><span class="v mono">' . rl_size($r['bytes'] ?? 0) . '</span></div>'
              . '<div class="xr"><span class="l">Добавлено</span><span class="v">' . ($done ? h(implode(' · ', $done)) : '<span class="dim">ничего</span>') . '</span></div>'
              . '<div class="xr"><span class="l">Грейс</span><span class="v">' . (!empty($meta['grace']) ? 'да' : 'нет') . '</span></div>'
              . '</div>'
              . '<div class="xcol"><div class="xh">Доп. подписка</div>'
              . '<div class="xr"><span class="l">Состояние</span><span class="v">' . rl_as_badge($as) . '</span></div>'
              . '<div class="xr"><span class="l">Режим</span><span class="v">' . (($as['m'] ?? '') === 'manual' ? 'ручная привязка' : (($as['m'] ?? '') === 'auto' ? 'авто (по суффиксу)' : '—')) . '</span></div>'
              . '<div class="xr"><span class="l">Вторая пара</span><span class="v mono">' . ((string) ($as['su'] ?? '') !== '' ? h((string) $as['su']) : '—') . '</span></div>'
              . '<div class="xr"><span class="l">Источник</span><span class="v mono">' . ((string) ($as['h'] ?? '') !== '' ? h((string) $as['h']) : '—') . '</span></div>'
              . '<div class="xr"><span class="l">Слито</span><span class="v">' . ($asn > 0 ? $asn . ' конфиг(ов)' : ($asb > 0 ? rl_size($asb) : '—')) . ($asm > 0 ? ' · ' . $asm . ' мс' : '') . '</span></div>'
              . '<div class="xr"><span class="l">Кэш</span><span class="v">' . (!isset($as['c']) ? '—' : ((int) $as['c'] === 1 ? 'ответ из кэша' : 'загружено при запросе')) . '</span></div>'
              . '</div>'
              . '<div class="xcol"><div class="xh">История</div>'
              . '<div class="xr"><span class="l">Последние</span><span class="v">' . rl_hist_bar($hist[$su] ?? []) . '</span></div>'
              . '<div class="xr"><span class="l">За сутки</span><span class="v">' . $day . '</span></div>'
              . '<div class="xr"><span class="l">Устройств</span><span class="v">' . (int) ($idx[$su]['dev'] ?? 0) . ((string) ($u['lim'] ?? '') !== '' ? ' <span class="dim">· лимит ' . (int) $u['lim'] . '</span>' : '') . '</span></div>'
              . '<div class="xr"><span class="l">Первый заход</span><span class="v mono">' . (!empty($idx[$su]['first']) ? h(date('Y-m-d', (int) $idx[$su]['first'])) : '—') . '</span></div>'
              . '</div>'
              . ($su !== '' ? '<div class="xacts">'
                  . '<a class="btn ghost" href="' . h(rl_link(['rl_q' => $su], $base)) . '">Фильтр по этому пользователю</a>'
                  . '<a class="btn ghost" href="?tab=users">Открыть во вкладке «Пользователи»</a>'
                  . ($hwid !== '' ? '<button type="button" class="btn ghost rl-copy" data-copy="' . h($hwid) . '">Копировать HWID</button>' : '')
                  . '<a class="btn ghost" href="?tab=addsub">Привязка доп. подписки</a>'
                  . '</div>' : '')
              . '</div></td></tr>';
    }
    return $out;
}
