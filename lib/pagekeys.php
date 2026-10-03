<?php

function pagekeys_mode() {
    $m = setting('pagekeys_mode', 'off');
    return in_array($m, ['off', 'all', 'sel'], true) ? $m : 'off';
}

function pagekeys_active() {
    return pagekeys_mode() !== 'off' && (pagekeys_with_panel() || pagekeys_with_extra() || pagekeys_with_addsub());
}

function pagekeys_with_panel() { return setting('pagekeys_panel', '1') === '1'; }

function pagekeys_with_extra() { return setting('pagekeys_extra', '1') === '1'; }

function pagekeys_with_addsub() { return setting('pagekeys_addsub', '1') === '1'; }

function pagekeys_addsub_labels() { return setting('pagekeys_addsub_labels', '1') === '1'; }

function pagekeys_squads() {
    $a = json_decode((string) setting('pagekeys_squads', '[]'), true);
    if (!is_array($a)) return [];
    return array_values(array_unique(array_filter(array_map(fn($x) => trim((string) $x), $a), fn($x) => $x !== '')));
}

function pagekeys_users_raw() { return (string) setting('pagekeys_users', ''); }

function pagekeys_users() {
    $out = [];
    foreach (preg_split('~[\s,;]+~u', pagekeys_users_raw()) as $v) {
        $v = mb_strtolower(trim($v));
        if ($v !== '') $out[$v] = true;
    }
    return $out;
}

function pagekeys_users_norm($raw) {
    $out = [];
    foreach (preg_split('~[\s,;]+~u', (string) $raw) as $v) {
        $v = mb_substr(trim($v), 0, 64);
        if ($v === '' || isset($out[$v])) continue;
        $out[$v] = true;
        if (count($out) >= 5000) break;
    }
    return implode("\n", array_keys($out));
}

function pagekeys_allowed($short, $username) {
    $mode = pagekeys_mode();
    if ($mode === 'all') return true;
    if ($mode !== 'sel') return false;
    $users = pagekeys_users();
    if ($users) {
        if ($short !== '' && isset($users[mb_strtolower($short)])) return true;
        if ($username !== '' && isset($users[mb_strtolower($username)])) return true;
    }
    $want = pagekeys_squads();
    if (!$want) return false;
    $have = squadconf_user_squads($short);
    return (bool) array_intersect($want, $have);
}

function pagekeys_panel_links($short) {
    [$ok, $code, $data, $e] = remnawave_api_get('/api/subscriptions/by-short-uuid/' . rawurlencode($short));
    if (!$ok) {
        if ((int) $code === 401 || (int) $code === 403) error_log('submw pagekeys: панель отказала в /api/subscriptions/by-short-uuid (HTTP ' . (int) $code . ') — у токена нет права subscriptions:by-short-uuid-protected');
        return [];
    }
    $links = $data['response']['links'] ?? null;
    if (!is_array($links)) return [];
    return array_values(array_filter($links, fn($l) => is_string($l) && trim($l) !== ''));
}

function pagekeys_probe() {
    if (remnawave_url() === '' || remnawave_token() === '') return ['ok' => false, 'msg' => 'не заданы URL панели или API-токен'];
    [$ok, $code, $data, $e] = remnawave_api_get('/api/subscriptions/by-short-uuid/submw-probe-' . bin2hex(random_bytes(4)));
    $code = (int) $code;
    if ($ok || $code === 404) return ['ok' => true, 'msg' => 'право есть'];
    if ($code === 401 || $code === 403) return ['ok' => false, 'msg' => 'нет права subscriptions:by-short-uuid-protected (HTTP ' . $code . ')'];
    return ['ok' => false, 'msg' => 'панель не ответила: ' . ($e !== '' ? $e : ('HTTP ' . $code))];
}

function pagekeys_extra_cfgs($short) {
    $squads = squadconf_user_squads($short);
    $out = []; $added = [];
    $push = function ($c) use (&$out, &$added) {
        $id = (int) ($c['id'] ?? 0);
        if ($id <= 0 || isset($added[$id])) return;
        $added[$id] = true; $out[] = $c;
    };
    $pooled = [];
    if ($squads) {
        foreach (squadconf_for_squads($squads) as $c) {
            $t = (string) ($c['type'] ?? '');
            foreach (squadconf_squads_of($c) as $sq) {
                if (!in_array($sq, $squads, true)) continue;
                $mode = wglease_mode($sq);
                if ($mode === 'shared' || $t === 'vless') { $push($c); break; }
                if ($mode === 'users') $pooled[(int) $c['id']][] = $sq;
            }
        }
    }
    if ($pooled && ($p = db())) {
        wglease_ensure();
        try {
            $st = $p->prepare("SELECT pool_id, config_id FROM wg_lease WHERE short_uuid = ? AND (hwid IS NULL OR hwid = '')");
            $st->execute([$short]);
            $ids = [];
            foreach ($st->fetchAll() as $r) {
                $cid = (int) $r['config_id'];
                if (isset($pooled[$cid]) && in_array((string) $r['pool_id'], $pooled[$cid], true)) $ids[] = $cid;
            }
            if ($ids) foreach (squadconf_by_ids($ids) as $c) if ((int) ($c['enabled'] ?? 0) === 1) $push($c);
        } catch (Throwable $e) { error_log('submw pagekeys lease: ' . $e->getMessage()); }
    }
    foreach (wglease_manual_for_user($short, '') as $c) $push($c);
    return $out;
}

function pagekeys_extra_links($short, array $taken) {
    $uris = [];
    $names = $taken;
    foreach (pagekeys_extra_cfgs($short) as $c) {
        $pn = json_decode((string) ($c['parsed'] ?? ''), true);
        if (!is_array($pn)) continue;
        $t = (string) ($pn['type'] ?? '');
        $label = trim((string) ($c['name'] ?? ''));
        if ($label === '') $label = $t === 'vless' ? 'VLESS' : ($t === 'amneziawg' ? 'AmneziaWG' : 'WireGuard');
        $nm = $label; $i = 1;
        while (in_array($nm, $names, true)) { $i++; $nm = $label . ' ' . $i; }
        if ($t === 'vless') $u = vless_relabel_uri((string) ($c['raw'] ?? ''), $nm);
        elseif ($t === 'wireguard') $u = wg_to_uri($pn, $nm);
        elseif ($t === 'amneziawg') $u = wg_to_uri_wg($pn, $nm);
        else $u = '';
        if ($u === '') continue;
        $uris[] = $u; $names[] = $nm;
    }
    return $uris;
}

function pagekeys_link_name($l) {
    $l = (string) $l;
    if (stripos($l, 'vmess://') === 0) {
        $j = json_decode((string) base64_decode(substr($l, 8), true), true);
        return is_array($j) ? trim((string) ($j['ps'] ?? '')) : '';
    }
    $h = strrpos($l, '#');
    return $h === false ? '' : rawurldecode(substr($l, $h + 1));
}

function pagekeys_widget_name($l) {
    $l = (string) $l;
    $h = strrpos($l, '#');
    return $h === false ? 'Unknown' : rawurldecode(substr($l, $h + 1));
}

function pagekeys_link_host($l) {
    $l = trim((string) $l);
    if (stripos($l, 'vmess://') === 0) {
        $j = json_decode((string) base64_decode(substr($l, 8), true), true);
        return is_array($j) ? trim((string) ($j['add'] ?? '')) : '';
    }
    $h = strpos($l, '#');
    if ($h !== false) $l = substr($l, 0, $h);
    if (stripos($l, 'ss://') === 0) {
        $body = substr($l, 5);
        $q = strpos($body, '?'); if ($q !== false) $body = substr($body, 0, $q);
        if (strpos($body, '@') === false) {
            $dec = base64_decode(strtr(rtrim($body, '/'), '-_', '+/'), true);
            if (is_string($dec) && ($at = strrpos($dec, '@')) !== false) {
                $hp = trim(substr($dec, $at + 1), '[]');
                $c = strrpos($hp, ':');
                return $c === false ? $hp : trim(substr($hp, 0, $c), '[]');
            }
            return '';
        }
    }
    $p = parse_url($l);
    return (is_array($p) && !empty($p['host'])) ? trim((string) $p['host'], '[]') : '';
}

function pagekeys_lines_from_body($body) {
    $body = (string) $body;
    $t = trim($body);
    if ($t === '') return [];
    $dec = base64_decode($t, true);
    if ($dec !== false && strpos($dec, '://') !== false) $t = $dec;
    elseif (strpos($t, '://') === false) return [];
    $out = [];
    foreach (preg_split('/\r\n|\r|\n/', $t) as $ln) {
        $ln = trim($ln);
        if ($ln !== '' && strpos($ln, '://') !== false) $out[] = $ln;
    }
    return $out;
}

function pagekeys_addsub_fetch($short) {
    $src = addsub_resolve($short);
    if (!$src) return null;
    $links = null; $exhausted = false;
    if (($src['mode'] ?? '') === 'auto') {
        $seg = path_segments((string) parse_url($src['url'], PHP_URL_PATH));
        $shortB = $seg ? (string) end($seg) : '';
        if ($shortB !== '') {
            [$ok, $code, $data] = remnawave_api_get('/api/subscriptions/by-short-uuid/' . rawurlencode($shortB));
            if ($ok && is_array($data['response'] ?? null)) {
                $rb = $data['response'];
                $stB = strtoupper(trim((string) ($rb['user']['userStatus'] ?? '')));
                if ($stB === 'DISABLED' || $stB === 'EXPIRED') return null;
                $lim = (float) ($rb['user']['trafficLimitBytes'] ?? 0);
                $used = (float) ($rb['user']['trafficUsedBytes'] ?? 0);
                $exhausted = $stB === 'LIMITED' || ($lim > 0 && $used >= $lim);
                $links = is_array($rb['links'] ?? null) ? array_values(array_filter($rb['links'], fn($l) => is_string($l) && trim($l) !== '')) : [];
            }
        }
    }
    if ($links === null) {
        [$body, $info] = addsub_fetch_body($src['url'], ['User-Agent' => 'submw', 'Accept' => '*/*']);
        if ($body === null || $body === '') return null;
        $exhausted = addsub_traffic_exhausted($info);
        $links = pagekeys_lines_from_body($body);
    }
    return ['links' => $links, 'exhausted' => $exhausted];
}

function pagekeys_addsub_links($short) {
    $none = ['links' => [], 'labels' => []];
    if (!addsub_enabled() || grace_is_active($short)) return $none;
    $b = pagekeys_addsub_fetch($short);
    if ($b === null) return $none;
    if ($b['exhausted']) {
        $stub = addsub_stub_on_traffic() ? addsub_stub_label() : '';
        return ['links' => [], 'labels' => $stub !== '' ? [$stub] : []];
    }
    $links = []; $labels = [];
    foreach ($b['links'] as $l) {
        $host = pagekeys_link_host($l);
        if ($host !== '' && addsub_swap_is_label_addr($host)) {
            $nm = pagekeys_link_name($l);
            if ($nm !== '' && !in_array($nm, $labels, true)) $labels[] = $nm;
            continue;
        }
        $links[] = $l;
    }
    return ['links' => $links, 'labels' => $labels];
}

function pagekeys_subtitle_script($first_name, array $labels) {
    $data = json_encode(['n' => (string) $first_name, 'l' => array_values($labels)], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    if ($data === false) return '';
    return '<script>(function(){var D=' . $data . ';'
        . 'function find(){var cs=document.querySelectorAll(".mantine-Card-root");for(var i=0;i<cs.length;i++){var c=cs[i];if(!c.querySelector(".mantine-ScrollArea-root"))continue;var sp=c.querySelectorAll("span");for(var j=0;j<sp.length;j++){if(sp[j].textContent===D.n)return c;}}return null;}'
        . 'function put(){if(document.getElementById("submw-pk-sub"))return;var c=find();if(!c)return;var t=c.querySelector(".mantine-Title-root");if(!t||!t.parentElement||!t.parentElement.parentElement)return;var g=t.parentElement;var el=document.createElement("div");el.id="submw-pk-sub";el.style.cssText="margin-top:-6px;font-size:var(--mantine-font-size-sm);line-height:1.45;color:var(--mantine-color-dimmed);overflow-wrap:anywhere";for(var k=0;k<D.l.length;k++){var p=document.createElement("div");p.textContent=D.l[k];el.appendChild(p);}g.parentElement.insertBefore(el,g.nextSibling);}'
        . 'new MutationObserver(put).observe(document.documentElement,{childList:true,subtree:true});put();'
        . '})();</script>';
}

function pagekeys_apply($html) {
    if (!is_string($html) || $html === '' || !pagekeys_active()) return $html;
    if (!preg_match('~<div\b[^>]*\bid\s*=\s*["\']sbpg["\'][^>]*>~i', $html, $tm, PREG_OFFSET_CAPTURE)) return $html;
    $tag = $tm[0][0];
    if (!preg_match('~\bdata-panel\s*=\s*"([A-Za-z0-9+/=]*)"~', $tag, $dm, PREG_OFFSET_CAPTURE)) return $html;
    $raw = base64_decode($dm[1][0], true);
    if ($raw === false || $raw === '') return $html;
    $doc = json_decode($raw);
    if (!is_object($doc) || !isset($doc->response) || !is_object($doc->response)) return $html;
    $r = $doc->response;
    if (isset($r->isFound) && $r->isFound !== true) return $html;
    $u = (isset($r->user) && is_object($r->user)) ? $r->user : null;
    if (!$u || ($u->isActive ?? null) !== true) return $html;
    $short = is_string($u->shortUuid ?? null) ? trim($u->shortUuid) : '';
    $uname = is_string($u->username ?? null) ? trim($u->username) : '';
    if ($short === '') return $html;
    $ov = find_override('shortuuid', $short);
    if ($ov && (($ov['reason'] ?? '') === 'blocked' || (($ov['reason'] ?? '') === 'expired' && ($ov['source'] ?? '') !== 'webhook'))) return $html;
    try {
        if (!pagekeys_allowed($short, $uname)) return $html;
        $orig = (isset($r->links) && is_array($r->links)) ? array_values(array_filter($r->links, fn($l) => is_string($l) && trim($l) !== '')) : [];
        $links = $orig;
        if (!$links && pagekeys_with_panel()) $links = pagekeys_panel_links($short);
        if (pagekeys_with_extra() && squadconf_any()) {
            $extra = pagekeys_extra_links($short, array_map('pagekeys_link_name', $links));
            foreach ($extra as $x) if (!in_array($x, $links, true)) $links[] = $x;
        }
        $labels = [];
        if (pagekeys_with_addsub() && addsub_enabled()) {
            $b = pagekeys_addsub_links($short);
            foreach ($b['links'] as $x) if (!in_array($x, $links, true)) $links[] = $x;
            if (pagekeys_addsub_labels()) $labels = $b['labels'];
        }
    } catch (Throwable $e) { error_log('submw pagekeys: ' . $e->getMessage()); return $html; }
    $script = ($links && $labels) ? pagekeys_subtitle_script(pagekeys_widget_name($links[0]), $labels) : '';
    if ($links === $orig && $script === '') return $html;
    $r->links = $links;
    $enc = json_encode($doc, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    if ($enc === false) return $html;
    $new_tag = substr_replace($tag, base64_encode($enc), $dm[1][1], strlen($dm[1][0]));
    $html = substr_replace($html, $new_tag, $tm[0][1], strlen($tag));
    if ($script !== '') {
        $end = strlen($new_tag);
        $close = stripos($html, '</div>', $tm[0][1] + $end);
        $html = ($close !== false) ? substr_replace($html, $script, $close + 6, 0) : $html . $script;
    }
    return $html;
}
