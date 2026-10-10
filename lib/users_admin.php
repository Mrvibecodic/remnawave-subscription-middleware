<?php

function ua_ts($v) {
    if ($v === null || $v === '') return 0;
    $t = strtotime((string) $v);
    return $t === false ? 0 : $t;
}

function ua_link($short) {
    $m = mirror_domain();
    if ($m === '' || (string) $short === '') return '';
    return 'https://' . $m . '/' . (sub_link_prefix() ? sub_prefix_seg() : (sub_link_apisub() ? 'api/sub/' : '')) . $short;
}

function ua_norm($u) {
    if (!is_array($u)) return null;
    $ref = rw_user_ref($u);
    $tr  = is_array($u['userTraffic'] ?? null) ? $u['userTraffic'] : $u;
    $sq = []; $sqn = [];
    foreach (($u['activeInternalSquads'] ?? []) as $s) {
        if (is_array($s) && !empty($s['uuid'])) { $sq[] = (string) $s['uuid']; $sqn[] = (string) ($s['name'] ?? ''); }
        elseif (is_string($s) && $s !== '') { $sq[] = $s; $sqn[] = ''; }
    }
    $su = (string) ($u['shortUuid'] ?? '');
    return [
        'rv'    => rw_ref_ok($ref) ? (string) $ref['val'] : '',
        'id'    => (string) ($u['id'] ?? ''),
        'su'    => $su,
        'un'    => (string) ($u['username'] ?? ''),
        'st'    => (string) ($u['status'] ?? ''),
        'exp'   => ua_ts($u['expireAt'] ?? null),
        'tl'    => (int) ($u['trafficLimitBytes'] ?? 0),
        'ts'    => (string) ($u['trafficLimitStrategy'] ?? 'NO_RESET'),
        'hw'    => (isset($u['hwidDeviceLimit']) && $u['hwidDeviceLimit'] !== '') ? (int) $u['hwidDeviceLimit'] : null,
        'sq'    => $sq,
        'sqn'   => $sqn,
        'ex'    => (string) ($u['externalSquadUuid'] ?? ''),
        'tag'   => (string) ($u['tag'] ?? ''),
        'desc'  => (string) ($u['description'] ?? ''),
        'tg'    => isset($u['telegramId']) && $u['telegramId'] !== null && $u['telegramId'] !== '' ? (string) $u['telegramId'] : '',
        'em'    => (string) ($u['email'] ?? ''),
        'used'  => (int) ($tr['usedTrafficBytes'] ?? 0),
        'life'  => (int) ($tr['lifetimeUsedTrafficBytes'] ?? 0),
        'on'    => ua_ts($tr['onlineAt'] ?? null),
        'first' => ua_ts($tr['firstConnectedAt'] ?? null),
        'node'  => (string) ($tr['lastConnectedNodeUuid'] ?? ''),
        'cr'    => ua_ts($u['createdAt'] ?? null),
        'upd'   => (string) ($u['updatedAt'] ?? ''),
        'lk'    => ua_link($su),
    ];
}

function ua_uuid_ok($v) { return is_string($v) && preg_match('~^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$~i', $v); }

function ua_fields(array $in, $create, &$err) {
    $err = '';
    $out = [];
    $strats = ['NO_RESET', 'DAY', 'WEEK', 'MONTH', 'MONTH_ROLLING'];
    foreach ($in as $k => $v) {
        switch ($k) {
            case 'expireAt':
                $t = is_numeric($v) ? (int) $v : ua_ts($v);
                if ($t <= time() + 30) { $err = 'Дата окончания должна быть в будущем'; return null; }
                $out[$k] = gmdate('Y-m-d\TH:i:s.000\Z', $t);
                break;
            case 'status':
                if (!in_array($v, ['ACTIVE', 'DISABLED'], true)) { $err = 'Неверный статус'; return null; }
                $out[$k] = $v;
                break;
            case 'trafficLimitBytes':
                if (!is_numeric($v) || $v < 0) { $err = 'Неверный лимит трафика'; return null; }
                $out[$k] = (int) $v;
                break;
            case 'trafficLimitStrategy':
                if (!in_array($v, $strats, true)) { $err = 'Неверная стратегия сброса'; return null; }
                $out[$k] = $v;
                break;
            case 'hwidDeviceLimit':
                if ($v === null || $v === '') { if (!$create) $out[$k] = null; break; }
                if (!is_numeric($v) || (int) $v < 0) { $err = 'Неверный лимит устройств'; return null; }
                $out[$k] = (int) $v;
                break;
            case 'activeInternalSquads':
                if (!is_array($v)) { $err = 'Неверный список сквадов'; return null; }
                $l = [];
                foreach ($v as $x) { if (!ua_uuid_ok($x)) { $err = 'Неверный сквад'; return null; } $l[] = strtolower($x); }
                $out[$k] = array_values(array_unique($l));
                break;
            case 'externalSquadUuid':
                if ($v === null || $v === '') { $out[$k] = null; break; }
                if (!ua_uuid_ok($v)) { $err = 'Неверный внешний сквад'; return null; }
                $out[$k] = strtolower($v);
                break;
            case 'description':
                $v = trim((string) $v);
                if ($v === '') { if (!$create) $out[$k] = null; break; }
                $out[$k] = mb_substr($v, 0, 1000);
                break;
            case 'tag':
                $v = strtoupper(trim((string) $v));
                if ($v === '') { $out[$k] = null; break; }
                if (!preg_match('~^[A-Z0-9_]{1,16}$~', $v)) { $err = 'Тег: до 16 символов, A–Z, 0–9 и _'; return null; }
                $out[$k] = $v;
                break;
            case 'telegramId':
                $v = trim((string) $v);
                if ($v === '') { $out[$k] = null; break; }
                if (!preg_match('~^\d{1,15}$~', $v)) { $err = 'Telegram ID — только цифры'; return null; }
                $out[$k] = (int) $v;
                break;
            case 'email':
                $v = trim((string) $v);
                if ($v === '') { $out[$k] = null; break; }
                if (!filter_var($v, FILTER_VALIDATE_EMAIL)) { $err = 'Неверный email'; return null; }
                $out[$k] = $v;
                break;
        }
    }
    return $out;
}

function ua_grace_info($short) {
    $g = grace_find($short);
    if (!$g || grace_ended($g)) return null;
    $sq = json_decode((string) ($g['orig_squads'] ?? ''), true);
    return [
        'until' => (int) $g['grace_until'],
        'oexp'  => ua_ts($g['orig_expire'] ?? null),
        'sq'    => is_array($sq) ? array_values(array_filter($sq, 'is_string')) : [],
        'tl'    => (int) ($g['orig_traffic_bytes'] ?? 0),
        'ts'    => (string) ($g['orig_traffic_strategy'] ?? 'NO_RESET'),
        'hw'    => ($g['orig_hwid_limit'] ?? null) === null ? null : (int) $g['orig_hwid_limit'],
        'ex'    => ($g['orig_external_squad'] ?? null) === null ? null : (string) $g['orig_external_squad'],
        'since' => ua_ts($g['created_at'] ?? null),
    ];
}

function ua_grace_map() {
    ensure_grace_table();
    $out = [];
    if (!($p = db())) return $out;
    try {
        foreach ($p->query('SELECT short_uuid FROM grace_users WHERE ended_ts = 0') as $r) {
            $gi = ua_grace_info((string) $r['short_uuid']);
            if ($gi) $out[(string) $r['short_uuid']] = $gi;
        }
    } catch (Throwable $e) {}
    return $out;
}

function ua_mw($short, $username = '') {
    $short = (string) $short;
    $out = ['grace' => ua_grace_info($short), 'ov' => [], 'addsub' => '', 'nolog' => nolog_is_set($short), 'wg' => ['m' => 0, 'a' => 0], 'last' => null];
    $p = db();
    if (!$p || $short === '') return $out;
    try {
        $st = $p->prepare("SELECT match_type, match_value, reason, source FROM overrides WHERE (match_type = 'shortuuid' AND match_value = ?) OR (match_type = 'username' AND LOWER(match_value) = ?)");
        $st->execute([$short, mb_strtolower((string) $username)]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out['ov'][] = ['t' => (string) $r['match_type'], 'r' => (string) $r['reason'], 's' => (string) $r['source']];
    } catch (Throwable $e) {}
    $out['addsub'] = (string) addsub_map_get($short);
    try {
        wglease_ensure();
        $st = $p->prepare('SELECT manual, COUNT(*) AS n FROM wg_lease WHERE short_uuid = ? GROUP BY manual');
        $st->execute([$short]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out['wg'][(int) $r['manual'] ? 'm' : 'a'] = (int) $r['n'];
    } catch (Throwable $e) {}
    try {
        $st = $p->prepare('SELECT ' . sql_epoch('ts') . ' AS e, user_agent, decision FROM request_log WHERE short_uuid = ? ORDER BY id DESC LIMIT 1');
        $st->execute([$short]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if ($r) { $c = reqlog_client($r['user_agent'] ?? ''); $out['last'] = ['t' => (int) $r['e'], 'app' => $c['app'], 'os' => $c['os'], 'dec' => (string) $r['decision']]; }
    } catch (Throwable $e) {}
    return $out;
}

function ua_history($short) {
    $short = (string) $short;
    $ev = [];
    $p = db();
    if (!$p || $short === '') return $ev;
    try {
        $st = $p->prepare('SELECT ' . sql_epoch('ts') . ' AS e, user_agent, decision FROM request_log WHERE short_uuid = ? ORDER BY id DESC LIMIT 12');
        $st->execute([$short]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $c = reqlog_client($r['user_agent'] ?? ''); $ev[] = ['t' => (int) $r['e'], 'k' => 'rq', 'app' => $c['app'], 'os' => $c['os'], 'dec' => (string) $r['decision']]; }
    } catch (Throwable $e) {}
    try {
        whlog_ensure_meta();
        $st = $p->prepare('SELECT ' . sql_epoch('ts') . ' AS e, event, action, meta FROM webhook_log WHERE short_uuid = ? ORDER BY id DESC LIMIT 15');
        $st->execute([$short]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $m = whlog_meta($r);
            $d = [];
            foreach (($m['d'] ?? []) as $k => $pair) {
                if (!is_array($pair)) continue;
                $fmt = function ($v) use ($k) {
                    if (is_array($v)) return implode(', ', $v) ?: '—';
                    if ($v === null || $v === '') return '—';
                    if (whlog_is_date($k)) { $t = ua_ts($v); return $t ? $t : (string) $v; }
                    return (string) $v;
                };
                $d[] = ['k' => $k, 'l' => whlog_field_label($k), 'a' => $fmt($pair[0] ?? null), 'b' => $fmt($pair[1] ?? null)];
            }
            $ev[] = ['t' => (int) $r['e'], 'k' => 'wh', 'ev' => (string) $r['event'], 'act' => (string) $r['action'], 'd' => $d, 'mw' => $m['mw'] ?? null, 'src' => (string) ($m['src'] ?? '')];
        }
    } catch (Throwable $e) {}
    try {
        ensure_panel_write_log();
        $st = $p->prepare('SELECT ' . sql_epoch('ts') . ' AS e, op, src, fields, ok, error FROM panel_write_log WHERE short_uuid = ? ORDER BY id DESC LIMIT 12');
        $st->execute([$short]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $f = array_map('whlog_field_label', array_values(array_filter(explode(',', (string) ($r['fields'] ?? '')))));
            $ev[] = ['t' => (int) $r['e'], 'k' => 'pw', 'op' => (string) $r['op'], 'src' => (string) $r['src'], 'f' => $f, 'ok' => (int) $r['ok'] === 1, 'err' => (string) ($r['error'] ?? '')];
        }
    } catch (Throwable $e) {}
    usort($ev, function ($a, $b) { return $b['t'] <=> $a['t']; });
    return array_slice($ev, 0, 30);
}

function ua_meta() {
    $e1 = $e2 = '';
    $nodes = [];
    [$ok, , $nd, ] = remnawave_api_get('/api/nodes');
    if ($ok) {
        $nr = $nd['response'] ?? $nd;
        $list = is_array($nr) ? ($nr['nodes'] ?? (isset($nr[0]) ? $nr : [])) : [];
        foreach ((array) $list as $n) if (is_array($n) && !empty($n['uuid'])) $nodes[(string) $n['uuid']] = (string) ($n['name'] ?? '');
    }
    return [
        'sq'    => remnawave_internal_squads($e1),
        'ex'    => remnawave_external_squads($e2),
        'nodes' => $nodes,
        'err'   => $e1,
    ];
}

function ua_fetch($short, &$err = '', &$code = 0) {
    $u = remnawave_get_user_by_short((string) $short, $err, $code);
    if (!is_array($u)) { $err = $code === 404 ? 'Пользователь не найден в панели' : ($err ?: 'Панель не ответила'); return null; }
    return $u;
}

function ua_after_write($short, $u) {
    squadconf_cache_drop($short);
    addsub_cache_drop($short);
    if (is_array($u) && ua_ts($u['expireAt'] ?? null) > time() && ($u['status'] ?? '') !== 'DISABLED') delete_override('shortuuid', $short, 'webhook');
}

function ua_resp_user($resp, $short) {
    $u = is_array($resp) ? ($resp['response'] ?? $resp) : null;
    if (is_array($u) && (string) ($u['shortUuid'] ?? '') !== '') return $u;
    $e = '';
    return remnawave_get_user_by_short($short, $e);
}

function ua_save($short, array $raw, $expect_upd = '', $reset = false) {
    $err = '';
    $fields = ua_fields($raw, false, $err);
    if ($fields === null) return ['ok' => false, 'error' => $err];
    if (!$fields) return ['ok' => false, 'error' => 'Нет изменений'];
    $code = 0;
    $u = ua_fetch($short, $err, $code);
    if (!$u) return ['ok' => false, 'error' => $err];
    if ($expect_upd !== '' && (string) ($u['updatedAt'] ?? '') !== $expect_upd) {
        return ['ok' => false, 'conflict' => true, 'error' => 'Пользователя изменили, пока форма была открыта', 'user' => ua_norm($u), 'mw' => ua_mw($short, (string) ($u['username'] ?? ''))];
    }
    $ref = rw_user_ref($u);
    $g = grace_find($short);
    $gk = ['expireAt', 'status', 'trafficLimitBytes', 'trafficLimitStrategy', 'hwidDeviceLimit', 'activeInternalSquads', 'externalSquadUuid'];
    $claimed = false;
    if ($g && !grace_ended($g) && ($u['status'] ?? '') === 'ACTIVE' && array_intersect(array_keys($fields), $gk)) {
        $c = grace_claim_end($short);
        if ($c === null) return ['ok' => false, 'error' => 'База данных недоступна'];
        $claimed = $c;
    }
    api_ctx('admin', $short);
    $resp = null; $e = ''; $code = 0;
    if (!remnawave_update_user($ref, $fields, $e, $code, $resp)) {
        if ($claimed) grace_unclaim_end($short);
        return ['ok' => false, 'error' => panel_err_text($resp, $code, $e)];
    }
    $warn = '';
    if ($claimed && $reset) {
        api_ctx('admin', $short);
        $re = '';
        if (!remnawave_reset_traffic($ref, $re)) $warn = 'Сохранено, но трафик не сбросился: ' . $re;
    }
    $nu = ua_resp_user($resp, $short) ?: $u;
    if ($claimed && $reset && $warn === '') { $e3 = ''; $nu = remnawave_get_user_by_short($short, $e3) ?: $nu; }
    ua_after_write($short, $nu);
    return ['ok' => true, 'user' => ua_norm($nu), 'mw' => ua_mw($short, (string) ($nu['username'] ?? '')), 'grace_closed' => $claimed, 'warn' => $warn];
}

function ua_action($short, $act) {
    $err = ''; $code = 0;
    $u = ua_fetch($short, $err, $code);
    if (!$u) return ['ok' => false, 'error' => $err];
    $ref = rw_user_ref($u);
    $un  = (string) ($u['username'] ?? '');
    api_ctx('admin', $short);
    $resp = null; $e = '';
    if ($act === 'enable' || $act === 'disable') {
        $ok = remnawave_user_post($ref, $act, $act, null, $e, $code, $resp);
    } elseif ($act === 'revoke') {
        $ok = remnawave_user_post($ref, 'revoke', 'revoke', ['revokeOnlyPasswords' => true], $e, $code, $resp);
    } elseif ($act === 'reset') {
        $ok = remnawave_reset_traffic($ref, $e);
    } elseif ($act === 'delete') {
        $ok = remnawave_delete_user($ref, $e, $code);
        if ($ok) {
            delete_override('shortuuid', $short);
            addsub_map_del($short);
            nolog_set($short, false);
            grace_cleanup($short);
            wglease_purge_user($short);
            squadconf_cache_drop($short);
            addsub_cache_drop($short);
            if (function_exists('chan_index_drop')) {
                try { chan_index_drop($short); chan_state_drop($short); rep_forget($short); }
                catch (Throwable $ex) { error_log('submw user delete chan: ' . $ex->getMessage()); }
            }
            return ['ok' => true, 'deleted' => true];
        }
    } else {
        return ['ok' => false, 'error' => 'Неизвестное действие'];
    }
    if (!$ok) return ['ok' => false, 'error' => $e ?: ('HTTP ' . $code)];
    $nu = ua_resp_user($resp, $short) ?: $u;
    if ($act === 'reset') { $e2 = ''; $nu = remnawave_get_user_by_short($short, $e2) ?: $nu; }
    ua_after_write($short, $nu);
    return ['ok' => true, 'user' => ua_norm($nu), 'mw' => ua_mw($short, $un)];
}

function ua_create(array $raw, array $extra) {
    $err = '';
    $un = trim((string) ($raw['username'] ?? ''));
    if (!preg_match('~^[a-zA-Z0-9_-]{3,36}$~', $un)) return ['ok' => false, 'error' => 'Имя: 3–36 символов, латиница, цифры, _ и -'];
    if (!isset($raw['expireAt'])) return ['ok' => false, 'error' => 'Нет даты окончания'];
    $body = ua_fields($raw, true, $err);
    if ($body === null) return ['ok' => false, 'error' => $err];
    foreach ($body as $k => $v) if ($v === null && $k !== 'externalSquadUuid') unset($body[$k]);
    if (array_key_exists('externalSquadUuid', $body) && $body['externalSquadUuid'] === null) unset($body['externalSquadUuid']);
    $body = ['username' => $un] + $body;
    $su = trim((string) ($raw['shortUuid'] ?? ''));
    if ($su !== '') { if (strlen($su) < 16 || strlen($su) > 64) return ['ok' => false, 'error' => 'shortUuid: от 16 до 64 символов']; $body['shortUuid'] = $su; }
    $vl = trim((string) ($raw['vlessUuid'] ?? ''));
    if ($vl !== '') { if (!ua_uuid_ok($vl)) return ['ok' => false, 'error' => 'VLESS UUID — неверный формат']; $body['vlessUuid'] = strtolower($vl); }
    foreach (['trojanPassword' => 'Trojan', 'ssPassword' => 'Shadowsocks'] as $k => $lbl) {
        $v = (string) ($raw[$k] ?? '');
        if ($v === '') continue;
        if (strlen($v) < 8 || strlen($v) > 32) return ['ok' => false, 'error' => 'Пароль ' . $lbl . ': от 8 до 32 символов'];
        $body[$k] = $v;
    }
    $add = trim((string) ($extra['addsub'] ?? ''));
    if ($add !== '' && !preg_match('~^https?://~i', $add)) return ['ok' => false, 'error' => 'Доп-подписка: адрес должен начинаться с http:// или https://'];
    api_ctx('admin', '');
    $code = 0;
    $u = remnawave_create_user($body, $err, $code);
    if (!$u) return ['ok' => false, 'error' => $err];
    $short = (string) $u['shortUuid'];
    $warn = '';
    if ($add !== '' && !addsub_map_set($short, $add, '')) $warn = 'Пользователь создан, но доп-подписка не привязалась';
    if (!empty($extra['nolog'])) nolog_set($short, true);
    return ['ok' => true, 'user' => ua_norm($u), 'mw' => ua_mw($short, $un), 'warn' => $warn];
}
