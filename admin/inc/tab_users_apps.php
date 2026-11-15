    <style>
    .ua{container:uaw / inline-size}
    .ua-fr{margin:0 0 .9rem}
    .ua-fr b{color:var(--text);font-weight:600}
    .ua-k{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:.7rem;margin:0 0 .9rem}
    .ua-k>div{border:1px solid var(--line);background:var(--card);border-radius:12px;padding:.8rem 1rem}
    .ua-k b{display:block;font-size:1.7rem;line-height:1.2;color:var(--text-strong);font-variant-numeric:tabular-nums}
    .ua-k span{font-size:.88rem;color:var(--muted)}
    .ua-k .w b{color:var(--c-warn-fg)}
    .ua .seg{flex-wrap:wrap}
    .ua .seg button{display:inline-flex;align-items:center;gap:.4rem}
    .ua-g{display:grid;grid-template-columns:minmax(0,2fr) minmax(0,3fr);gap:1rem;align-items:start}
    .ua-g>.card{margin-bottom:0;min-width:0}
    .ua-ac{container:uaa / inline-size}
    .ua-uc{container:uau / inline-size}
    .ua-sub{font-weight:400;font-size:.88rem;color:var(--muted);margin-left:.4rem;font-variant-numeric:tabular-nums}
    .ua-lg{font-size:.82rem;color:var(--muted);gap:.3rem .9rem}
    .ua-lg span{display:inline-flex;align-items:center;gap:.4rem}
    .ua-dot{width:10px;height:10px;border-radius:50%;display:inline-block;flex:none}
    .ua-q0{background:color-mix(in srgb,var(--accent) 55%,var(--bg2))}.ua-q1{background:var(--c-warn-fg)}.ua-q3{background:var(--line)}
    .ua button.ua-b{background:none;color:inherit;border:0;border-radius:0;padding:0;font:inherit;text-align:left;transition:none;cursor:pointer}
    .ua button.ua-b:hover,.ua button.ua-b:active{background:none;filter:none;transform:none}
    .ua-at{display:grid;grid-template-columns:16px minmax(8rem,12.5rem) 5.5rem 3.75rem minmax(6rem,1fr) 3rem;gap:.9rem;align-items:center;box-sizing:border-box;width:100%;border-bottom:1px solid var(--line)}
    .ua button.ua-at{padding:.7rem;font-size:1rem}
    .ua-at.hd{font-size:.82rem;font-weight:600;color:var(--muted);padding:.45rem .7rem}
    .ua button.ua-at:hover{background:var(--hover2)}
    .ua button.ua-at.in{box-shadow:inset 3px 0 0 var(--accent)}
    .ua button.ua-at.sel{background:var(--accent-light)}
    .ua-at .nm{font-weight:600;color:var(--text-strong);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .ua-at .num{text-align:right;font-variant-numeric:tabular-nums}
    .ua-at .n1{font-weight:700;color:var(--text-strong)}
    .ua-at .pc{font-weight:600;color:var(--text-strong)}
    .ua-at.hd .sp2{grid-column:span 2}
    .ua-cv{display:inline-grid;place-items:center;color:var(--muted);transition:transform .15s}
    .ua-cv.op{transform:rotate(90deg)}
    .ua button.ua-so{display:inline-flex;align-items:center;gap:.15rem;white-space:nowrap;font-weight:600}
    .ua button.ua-so:hover{color:var(--text)}
    .ua button.ua-so.on{color:var(--text-strong)}
    .ua-so svg{transform:rotate(90deg);opacity:0}
    .ua-so.on svg{opacity:1}
    .ua-qb{display:flex;height:14px;border-radius:7px;overflow:hidden;gap:2px;min-width:0}
    .ua-qb i{display:block;height:100%;min-width:4px}
    .ua-px{display:grid;grid-template-columns:repeat(auto-fill,minmax(15rem,1fr));gap:.7rem 2rem;align-items:start;padding:.7rem .7rem .9rem calc(16px + 1.6rem);border-bottom:1px solid var(--line);background:var(--hover2);box-shadow:inset 3px 0 0 var(--accent)}
    .ua-pk{min-width:0;display:flex;flex-direction:column;align-items:stretch}
    .ua button.ua-pkh{display:flex;justify-content:space-between;align-items:center;box-sizing:border-box;font-size:.95rem;font-weight:600;color:var(--text-strong);padding:.32rem .5rem;border-radius:7px;margin-bottom:.15rem}
    .ua-pkh span{display:inline-flex;gap:.45rem;align-items:center}
    .ua-pkh svg{color:var(--muted)}
    .ua-pkh b{font-variant-numeric:tabular-nums}
    .ua button.ua-vr{display:grid;grid-template-columns:5.75rem minmax(0,1fr) 2.2rem;gap:.7rem;align-items:center;box-sizing:border-box;font-size:.92rem;padding:.28rem .5rem;border-radius:7px;font-variant-numeric:tabular-nums}
    .ua button.ua-pkh:hover,.ua button.ua-vr:hover{background:var(--hover)}
    .ua button.ua-pkh.sel,.ua button.ua-vr.sel{background:var(--accent-light)}
    .ua button.ua-pkh.sel{color:var(--accent-text)}
    .ua-vr .vv{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .ua-vr .num{text-align:right;color:var(--text-strong);font-weight:600}
    .ua-vb{display:block;height:10px}
    .ua-vb i{display:block;height:100%;border-radius:5px;min-width:4px}
    .ua button.ua-lk{font-size:.84rem;color:var(--accent-text);align-self:flex-start;margin-top:.15rem;padding:.2rem .5rem;border-radius:6px}
    .ua button.ua-lk:hover{background:var(--hover)}
    .ua-bc{display:flex;flex-wrap:wrap;gap:.4rem;align-items:center;margin:0 0 .7rem;font-size:.9rem;min-height:1.9rem}
    .ua button.ua-bx{display:inline-flex;align-items:center;gap:.3rem;font-weight:600;padding:.3rem .35rem .3rem .65rem;border-radius:6px;background:var(--accent-light);color:var(--accent-text)}
    .ua button.ua-bx:hover{background:var(--accent-light)}
    .ua-bx svg{opacity:.7}
    .ua-bx:hover svg{opacity:1}
    .ua-tb{display:flex;flex-wrap:wrap;gap:.5rem 1rem;align-items:center;margin:0 0 .6rem}
    .ua-tb input[type=text]{flex:1 1 12rem;width:auto}
    .ua-tb .chk{margin:0;white-space:nowrap}
    .ua .tag.ua-n{background:var(--hover);color:var(--muted)}
    .ua-un{font-weight:600;color:var(--text-strong)}
    .ua-os{color:var(--muted)}
    .ua-pi{display:inline-grid;vertical-align:-2px;margin-right:.4rem;color:var(--muted)}
    .ua-vn{font-weight:600;color:var(--text-strong)}
    .ua a.ua-o{display:inline-grid;place-items:center;width:34px;height:34px;min-height:0;padding:0;color:var(--muted)}
    .ua a.ua-o:hover{color:var(--text)}
    @container uaw (max-width:72rem){
        .ua-g{grid-template-columns:minmax(0,1fr)}
    }
    @container uaw (max-width:40rem){
        .ua-k{grid-template-columns:repeat(2,minmax(0,1fr))}
    }
    @container uaa (max-width:34rem){
        .ua-at{grid-template-columns:16px minmax(0,1.4fr) 5rem minmax(3.5rem,1fr) 3rem;gap:.6rem}
        .ua-at .u{display:none}
    }
    @container uaa (max-width:22rem){
        .ua-at{grid-template-columns:16px minmax(0,1fr) 2.5rem 3rem;gap:.4rem .6rem}
        .ua button.ua-at{padding:.65rem .4rem}
        .ua-at.hd{padding:.4rem}
        .ua-at .n1{grid-column:3 / 5}
        .ua-at .ua-qb{grid-column:2 / 4;grid-row:2}
        .ua-at .pc{grid-column:4;grid-row:2}
        .ua-at.hd .sp2{grid-column:2 / 5;grid-row:2}
        .ua-px{padding-left:1.3rem;grid-template-columns:minmax(0,1fr)}
    }
    @media(max-width:900px){.ua-g{grid-template-columns:minmax(0,1fr)}}
    @media(min-width:901px){
        .ua-uc table{table-layout:fixed}
        .ua-uc th,.ua-uc td{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
        .ua-uc th.c-u{width:20%}.ua-uc th.c-a{width:22%}.ua-uc th.c-v{width:6.6rem}.ua-uc th.c-s{width:7.2rem}.ua-uc th.c-t{width:5.4rem}.ua-uc th.c-o{width:3rem}
        .ua-uc td.ua-oc{padding:.2rem .3rem}
        @container uau (max-width:48rem){.ua-uc th,.ua-uc td{padding-left:.5rem;padding-right:.5rem}}
    }
    </style>
    <div class="seg">
        <a href="?tab=users">Список</a>
<?php if (function_exists('cmap_view') && function_exists('sess_ensure')): ?>
        <a href="?tab=users&amp;view=map">Карта подключений</a>
<?php endif; ?>
        <a class="on" href="?tab=users&amp;view=apps">Приложения</a>
    </div>
    <div class="ua" id="ua"><div class="card"><p class="muted" style="margin:0">Загружаю список устройств из панели…</p></div></div>
    <script>
    (function () {
        'use strict';
        var root = document.getElementById('ua');
        if (!root) return;
        var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]; }); };
        var p2 = function (n) { return (n < 10 ? '0' : '') + n; };
        var tFmt = function (t, f) { var d = new Date(t * 1000); return f === 't' ? p2(d.getHours()) + ':' + p2(d.getMinutes()) : p2(d.getDate()) + '.' + p2(d.getMonth() + 1) + (f === 'd' ? '' : ' ' + p2(d.getHours()) + ':' + p2(d.getMinutes())); };
        var pl = function (n, f) { var x = n % 10, y = n % 100; return n + ' ' + (x === 1 && y !== 11 ? f[0] : (x >= 2 && x <= 4 && (y < 10 || y >= 20) ? f[1] : f[2])); };
        var W_DEV = ['устройство', 'устройства', 'устройств'], W_USR = ['пользователь', 'пользователя', 'пользователей'], W_VER = ['версия', 'версии', 'версий'];
        var I = {
            chev: '<polyline points="9 6 15 12 9 18"/>',
            phone: '<rect x="6" y="2" width="12" height="20" rx="2.5"/><path d="M10.5 18h3"/>',
            pc: '<rect x="2.5" y="4" width="19" height="12" rx="2"/><path d="M8 20h8M12 16v4"/>',
            tv: '<rect x="2.5" y="7" width="19" height="13" rx="2"/><path d="M8 3l4 4 4-4"/>',
            open: '<path d="M7 17L17 7M8 7h9v9"/>',
            x: '<path d="M6 6l12 12M18 6L6 18"/>'
        };
        var ico = function (n, s) { s = s || 16; return '<svg width="' + s + '" height="' + s + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + I[n] + '</svg>'; };
        var PLK = ['android', 'ios', 'windows', 'macos', 'linux', 'pc', 'tv', 'other'];
        var PL = {android: 'Android', ios: 'iOS', windows: 'Windows', macos: 'macOS', linux: 'Linux', pc: 'ПК', tv: 'ТВ', other: 'Другое'};
        var PIC = {android: 'phone', ios: 'phone', tv: 'tv'};
        var STK = ['ok', 'patch', 'old', 'none'];
        var STN = {ok: 'Актуальная версия', patch: 'Есть обновление', old: 'Устарела', none: 'Нет эталона'};
        var STT = {ok: 'актуальная', patch: 'обновление', old: 'устарела', none: 'нет эталона'};
        var STC = {ok: 'normal', patch: 'expired', old: 'blocked', none: 'ua-n'};
        var A = [], U = [], D = [], TS = 0, TTL = 0, CV = true, ERR = '';
        var PF = '', S = {c: -1, p: '', v: null}, Q = '', OLD = false, PG = 1, SORT = 'n', VALL = {};
        // Список людей ставится рядом с тем, что нажали, — строк на странице столько, чтобы он целиком помещался в окно.
        var REM = parseFloat(getComputedStyle(document.documentElement).fontSize) || 16;
        var PS = innerWidth > 900 ? Math.max(5, Math.min(12, Math.floor((innerHeight - 64 - 17.5 * REM) / (2.85 * REM)))) : 12;

        var pic = function (p, s) { return ico(PIC[p] || 'pc', s); };
        var aname = function (i) { return A[i] && A[i][1] !== '' ? A[i][1] : 'Не распознано'; };
        var uname = function (u) { return u[0] !== '' ? u[0] : 'ID ' + u[2]; };
        var vname = function (v) { return v === '' ? 'без версии' : v; };
        var tag = function (s) { return '<span class="tag ' + STC[s] + '" data-tip="' + STN[s] + '">' + STT[s] + '</span>'; };
        var agg = function (list, kf) {
            var m = {}, o = [];
            list.forEach(function (d) {
                var k = kf(d), g = m[k];
                if (!g) { g = m[k] = {k: k, n: 0, us: {}, st: {ok: 0, patch: 0, old: 0, none: 0}, items: []}; o.push(g); }
                g.n++; g.us[d.u] = 1; g.st[d.s]++; g.items.push(d);
            });
            o.forEach(function (g) { g.u = Object.keys(g.us).length; });
            return o.sort(function (a, b) { return b.n - a.n; });
        };
        var ucount = function (list) { var m = {}; list.forEach(function (d) { m[d.u] = 1; }); return Object.keys(m).length; };
        // Новые версии выше: сравнение по числам, а не по строке.
        var vcmp = function (a, b) {
            var x = String(a).split(/[^0-9]+/), y = String(b).split(/[^0-9]+/);
            for (var i = 0; i < Math.max(x.length, y.length); i++) { var d = (parseInt(y[i], 10) || 0) - (parseInt(x[i], 10) || 0); if (d) return d; }
            return a < b ? 1 : (a > b ? -1 : 0);
        };
        var oldOf = function (g) { return g.st.patch + g.st.old; };
        var known = function (g) { return g.n - g.st.none; };
        var hasV = function () { return S.v !== null; };
        var scope = function () { return PF ? D.filter(function (d) { return d.p === PF; }) : D; };
        var selList = function () { return scope().filter(function (d) { return (S.c < 0 || d.c === S.c) && (!S.p || d.p === S.p) && (!hasV() || d.v === S.v); }); };
        var userRows = function () {
            var q = Q.toLowerCase();
            var l = selList().filter(function (d) {
                if (OLD && d.s !== 'old' && d.s !== 'patch') return false;
                if (!q) return true;
                var u = U[d.u];
                return (uname(u) + ' ' + u[1] + ' ' + d.m + ' ' + d.o + ' ' + aname(d.c) + ' ' + d.v).toLowerCase().indexOf(q) > -1;
            });
            return l.sort(function (a, b) { return uname(U[a.u]).localeCompare(uname(U[b.u]), 'ru') || aname(a.c).localeCompare(aname(b.c), 'ru'); });
        };

        var qbar = function (g) {
            var o = oldOf(g), k = g.st.ok, nn = g.st.none;
            return '<span class="ua-qb">' + (k ? '<i class="ua-q0" style="flex:' + k + '" data-tip="Актуальная версия: ' + pl(k, W_DEV) + '"></i>' : '')
                + (o ? '<i class="ua-q1" style="flex:' + o + '" data-tip="Не обновились: ' + pl(o, W_DEV) + '"></i>' : '')
                + (nn ? '<i class="ua-q3" style="flex:' + nn + '" data-tip="Нет эталона: ' + pl(nn, W_DEV) + '"></i>' : '') + '</span>';
        };
        var so = function (k, t, tip) { return '<button type="button" class="ua-b ua-so' + (SORT === k ? ' on' : '') + '" data-act="sort" data-s="' + k + '" data-tip="' + tip + '">' + t + ico('chev', 12) + '</button>'; };

        var head = function () {
            var sc = scope(), all = agg(sc, function () { return 0; })[0], o = all ? oldOf(all) : 0, kn = all ? known(all) : 0;
            var h = '';
            if (ERR) h += '<div class="warn">Панель не ответила: ' + esc(ERR) + '. Показан прошлый список.</div>';
            h += '<p class="ua-fr muted">Обновлено <b>' + tFmt(TS) + '</b>' + (Date.now() / 1000 < TS + TTL ? ', следующее — не раньше <b>' + tFmt(TS + TTL, 't') + '</b>' : '') + '. Список — устройства из лимита HWID в панели, обновляется при открытии вкладки.'
                + (D.length >= 50000 ? ' Показаны первые 50 000 устройств.' : '')
                + (CV ? '' : ' Сравнение версий выключено — включается в <a href="?tab=reqlog&amp;view=clients">«Лог запросов → Клиенты»</a>.') + '</p>';
            h += '<div class="ua-k"><div><b>' + sc.length + '</b><span>устройств</span></div><div><b>' + ucount(sc) + '</b><span>пользователей</span></div><div><b>' + agg(sc, function (d) { return d.c; }).length + '</b><span>приложений</span></div>'
                + '<div class="w"><b>' + (kn ? Math.round(o / kn * 100) + '%' : '—') + '</b><span>на старых версиях' + (kn ? ' · ' + pl(o, W_DEV) : '') + '</span></div></div>';
            var m = {};
            D.forEach(function (d) { m[d.p] = (m[d.p] || 0) + 1; });
            h += '<div class="seg"><button type="button"' + (PF === '' ? ' class="on"' : '') + ' data-act="pf" data-p="">Все ' + D.length + '</button>'
                + PLK.map(function (p) { return m[p] ? '<button type="button"' + (PF === p ? ' class="on"' : '') + ' data-act="pf" data-p="' + p + '">' + pic(p, 14) + PL[p] + ' ' + m[p] + '</button>' : ''; }).join('') + '</div>';
            return h;
        };

        var apps = function () {
            var cs = agg(scope(), function (d) { return d.c; });
            if (SORT === 'old') cs.sort(function (a, b) { var x = known(a) ? oldOf(a) / known(a) : -1, y = known(b) ? oldOf(b) / known(b) : -1; return (y - x) || (b.n - a.n); });
            var h = '<div class="card ua-ac"><div class="loghead"><h2>Приложения</h2><div class="loghead-r ua-lg"><span><i class="ua-dot ua-q0"></i>актуальная</span><span><i class="ua-dot ua-q1"></i>не обновились</span><span><i class="ua-dot ua-q3"></i>нет эталона</span></div></div>'
                + '<div class="ua-at hd"><span></span><span>Приложение</span><span class="num">' + so('n', 'Устройств', 'Сортировать по числу устройств') + '</span><span class="num u" data-tip="Пользователей с этим приложением">Польз.</span><span class="sp2">' + so('old', 'Не обновились', 'Сортировать по доле не обновившихся') + '</span></div>';
            cs.forEach(function (g) {
                var c = +g.k, inn = S.c === c, sel = inn && !S.p && !hasV(), o = oldOf(g), kn = known(g);
                h += '<button type="button" class="ua-b ua-at' + (sel ? ' sel' : '') + (inn ? ' in' : '') + '" data-act="cl" data-c="' + c + '" aria-expanded="' + (inn ? 'true' : 'false') + '"><span class="ua-cv' + (inn ? ' op' : '') + '">' + ico('chev') + '</span><span class="nm">' + esc(aname(c)) + '</span><span class="num n1">' + g.n + '</span><span class="num u">' + g.u + '</span>'
                    + qbar(g) + (kn ? '<span class="num pc" data-tip="Не на актуальной версии: ' + o + ' из ' + kn + '">' + Math.round(o / kn * 100) + '%</span>' : '<span class="num muted" data-tip="Нет эталона — версию не с чем сравнить">—</span>') + '</button>';
                if (!inn) return;
                h += '<div class="ua-px">';
                agg(g.items, function (d) { return d.p; }).sort(function (a, b) { return PLK.indexOf(a.k) - PLK.indexOf(b.k); }).forEach(function (pg) {
                    var key = c + '|' + pg.k, vs = agg(pg.items, function (d) { return d.v; }), mx = vs[0].n, all = VALL[key] || vs.length <= 6, show = all ? vs.slice() : vs.slice(0, 5);
                    if (!all && S.p === pg.k && hasV() && !show.some(function (v) { return v.k === S.v; })) vs.forEach(function (v) { if (v.k === S.v) show.push(v); });
                    var rest = vs.length - show.length, restDev = pg.n - show.reduce(function (a, v) { return a + v.n; }, 0);
                    show.sort(function (a, b) { return vcmp(a.k, b.k); });
                    h += '<div class="ua-pk"><button type="button" class="ua-b ua-pkh' + (S.p === pg.k && !hasV() ? ' sel' : '') + '" data-act="pl" data-p="' + pg.k + '" data-tip="Все версии: ' + PL[pg.k] + '"><span>' + pic(pg.k) + PL[pg.k] + '</span><b>' + pg.n + '</b></button>';
                    show.forEach(function (vg) {
                        var s0 = vg.items[0].s;
                        h += '<button type="button" class="ua-b ua-vr' + (S.p === pg.k && hasV() && S.v === vg.k ? ' sel' : '') + '" data-act="ver" data-p="' + pg.k + '" data-v="' + esc(vg.k) + '" data-tip="' + STN[s0] + ' · ' + pl(vg.n, W_DEV) + ', ' + pl(vg.u, W_USR) + '"><span class="vv">' + esc(vname(vg.k)) + '</span><span class="ua-vb"><i class="' + (s0 === 'ok' ? 'ua-q0' : (s0 === 'none' ? 'ua-q3' : 'ua-q1')) + '" style="width:' + Math.max(4, Math.round(vg.n / mx * 100)) + '%"></i></span><span class="num">' + vg.n + '</span></button>';
                    });
                    if (rest > 0) h += '<button type="button" class="ua-b ua-lk" data-act="vall" data-k="' + key + '">ещё ' + pl(rest, W_VER) + ' · ' + pl(restDev, W_DEV) + '</button>';
                    else if (vs.length > 6) h += '<button type="button" class="ua-b ua-lk" data-act="vall" data-k="' + key + '">Свернуть</button>';
                    h += '</div>';
                });
                h += '</div>';
            });
            return h + '</div>';
        };

        var crumbs = function () {
            if (S.c < 0) return '<span class="muted">Все приложения' + (PF ? ' · ' + PL[PF] : '') + '</span>';
            var h = '<button type="button" class="ua-b ua-bx" data-act="bcx" data-w="c" data-tip="Показать все приложения">' + esc(aname(S.c)) + ico('x', 13) + '</button>';
            if (S.p) h += '<button type="button" class="ua-b ua-bx" data-act="bcx" data-w="p" data-tip="Показать все платформы">' + PL[S.p] + ico('x', 13) + '</button>';
            if (hasV()) {
                var one = selList()[0];
                h += '<button type="button" class="ua-b ua-bx" data-act="bcx" data-w="v" data-tip="Показать все версии">' + esc(vname(S.v)) + ico('x', 13) + '</button>' + (one ? tag(one.s) : '');
            }
            return h;
        };

        var drawUsers = function () {
            var l = userRows(), pages = Math.max(1, Math.ceil(l.length / PS));
            if (PG > pages) PG = pages;
            document.getElementById('uaCnt').textContent = pl(l.length, W_DEV) + ' · ' + pl(ucount(l), W_USR);
            document.getElementById('uaBc').innerHTML = crumbs();
            var body = document.getElementById('uaBody');
            if (!l.length) { body.innerHTML = '<p class="muted">Никого не найдено.</p>'; return; }
            var mode = S.c < 0 ? 'full' : (hasV() ? 'none' : 'ver'), showP = !S.p && !PF, start = (PG - 1) * PS;
            var h = '<table class="logtbl"><thead><tr><th class="c-u">Пользователь</th><th>Устройство</th>' + (mode === 'full' ? '<th class="c-a">Приложение</th>' : (mode === 'ver' ? '<th class="c-v">Версия</th>' : '')) + (mode === 'none' ? '' : '<th class="c-s">Статус</th>')
                + '<th class="c-t" data-tip="Последний запрос подписки с этого устройства">Запрос</th><th class="c-o"></th></tr></thead><tbody>';
            l.slice(start, start + PS).forEach(function (d) {
                var u = U[d.u], dv = (d.m !== '' ? d.m + ' · ' : '') + (d.o !== '' ? d.o : PL[d.p]), av = aname(d.c) + ' ' + vname(d.v);
                h += '<tr><td data-tip="' + esc(uname(u) + (u[1] !== '' ? ' · ' + u[1] : '')) + '"><span class="ua-un">' + esc(uname(u)) + '</span></td>'
                    + '<td data-tip="' + esc(dv) + '">' + (showP ? '<span class="ua-pi">' + pic(d.p, 14) + '</span>' : '') + esc(d.m !== '' ? d.m : (d.o !== '' ? d.o : PL[d.p])) + (d.m !== '' && d.o !== '' ? '<span class="ua-os"> · ' + esc(d.o) + '</span>' : '') + '</td>'
                    + (mode === 'full' ? '<td data-tip="' + esc(av) + '"><span class="ua-vn">' + esc(av) + '</span></td>' : (mode === 'ver' ? '<td><span class="ua-vn">' + esc(vname(d.v)) + '</span></td>' : ''))
                    + (mode === 'none' ? '' : '<td>' + tag(d.s) + '</td>')
                    + '<td class="muted"' + (d.t ? ' data-tip="' + tFmt(d.t) + '">' + tFmt(d.t, 'd') : '>—') + '</td>'
                    + '<td class="ua-oc">' + (u[1] !== '' ? '<a class="btn ghost ua-o" href="?tab=users&amp;card=' + encodeURIComponent(u[1]) + '&amp;ct=dev" data-tip="Открыть карточку" aria-label="Открыть карточку">' + ico('open') + '</a>' : '') + '</td></tr>';
            });
            h += '</tbody></table>';
            if (pages > 1) h += '<div class="pgr-bot"><div class="pgr-nav"><button type="button" class="pgr-b" data-act="pg" data-d="-1"' + (PG <= 1 ? ' disabled' : '') + '>◀</button><span class="pgr-st">' + (start + 1) + '–' + Math.min(start + PS, l.length) + ' из ' + l.length + ' · стр. ' + PG + '/' + pages + '</span><button type="button" class="pgr-b" data-act="pg" data-d="1"' + (PG >= pages ? ' disabled' : '') + '>▶</button></div></div>';
            body.innerHTML = h;
        };

        var draw = function () {
            if (!D.length) {
                root.innerHTML = (ERR ? '<div class="warn">Панель не ответила: ' + esc(ERR) + '.</div>' : '') + '<div class="card"><p class="muted" style="margin:0">В панели нет устройств. Они появляются, когда в панели включён лимит устройств (HWID) и приложение запрашивает подписку.</p></div>';
                return;
            }
            root.innerHTML = head() + '<div class="ua-g">' + apps()
                + '<div class="card ua-uc"><div class="loghead"><h2>Пользователи<span class="ua-sub" id="uaCnt"></span></h2><div class="loghead-r"><button type="button" class="btn ghost" data-act="csv" data-tip="Скачать показанный список">CSV</button></div></div>'
                + '<div class="ua-bc" id="uaBc"></div><div class="ua-tb"><input type="text" id="uaQ" placeholder="Имя, ID или устройство" autocomplete="off" aria-label="Поиск по списку" value="' + esc(Q) + '"><label class="chk"><input type="checkbox" id="uaOld"' + (OLD ? ' checked' : '') + '> Только старые версии</label></div><div id="uaBody"></div></div></div>';
            drawUsers();
            // position:sticky здесь не работает (.rw-main — контейнер с overflow), поэтому список сдвигается к видимой части при выборе.
            var g = root.querySelector('.ua-g'), c = g.lastElementChild;
            if (getComputedStyle(g).gridTemplateColumns.split(' ').length > 1) c.style.marginTop = Math.max(0, Math.min(REM - g.getBoundingClientRect().top, g.firstElementChild.offsetHeight - c.offsetHeight)) + 'px';
        };

        // Значение, которое табличный редактор принял бы за формулу, получает апостроф.
        var csv = function () {
            var cell = function (v) { v = String(v == null ? '' : v); if (/^[=+\-@\t\r]/.test(v)) v = "'" + v; return '"' + v.replace(/"/g, '""') + '"'; };
            var out = [['Пользователь', 'shortUuid', 'Устройство', 'ОС', 'Платформа', 'Приложение', 'Версия', 'Статус версии', 'Последний запрос'].map(cell).join(';')];
            userRows().forEach(function (d) {
                var u = U[d.u];
                out.push([uname(u), u[1], d.m, d.o, PL[d.p], aname(d.c), d.v, STT[d.s], d.t ? tFmt(d.t) : ''].map(cell).join(';'));
            });
            var a = document.createElement('a');
            a.href = URL.createObjectURL(new Blob(['\uFEFF' + out.join('\r\n')], {type: 'text/csv;charset=utf-8'}));
            a.download = 'apps.csv';
            document.body.appendChild(a);
            a.click();
            a.remove();
            setTimeout(function () { URL.revokeObjectURL(a.href); }, 1000);
        };

        root.addEventListener('click', function (e) {
            var b = e.target.closest('[data-act]');
            if (!b || !root.contains(b)) return;
            var a = b.getAttribute('data-act'), d = b.dataset;
            // Блок перерисовывается целиком — после нажатия с клавиатуры фокус возвращается на ту же кнопку, иначе обход начинается заново.
            var refocus = function () {
                if (e.detail !== 0) return;
                var f = [].filter.call(root.querySelectorAll('[data-act="' + a + '"]'), function (x) { return ['c', 'p', 'v', 's', 'k', 'd'].every(function (n) { return x.dataset[n] === d[n]; }); })[0];
                if (f) f.focus({preventScroll: true});
            };
            if (a === 'csv') { csv(); return; }
            if (a === 'pg') { PG += +d.d; drawUsers(); refocus(); return; }
            if (a === 'pf') {
                PF = d.p;
                if (PF && S.p && S.p !== PF) S = {c: S.c, p: '', v: null};
                if (S.c >= 0 && !scope().some(function (x) { return x.c === S.c; })) S = {c: -1, p: '', v: null};
            }
            else if (a === 'cl') S = S.c === +d.c ? {c: -1, p: '', v: null} : {c: +d.c, p: '', v: null};
            else if (a === 'pl') S = (S.p === d.p && !hasV()) ? {c: S.c, p: '', v: null} : {c: S.c, p: d.p, v: null};
            else if (a === 'ver') S = (S.p === d.p && hasV() && S.v === d.v) ? {c: S.c, p: d.p, v: null} : {c: S.c, p: d.p, v: d.v};
            else if (a === 'bcx') S = d.w === 'c' ? {c: -1, p: '', v: null} : (d.w === 'p' ? {c: S.c, p: '', v: null} : {c: S.c, p: S.p, v: null});
            else if (a === 'sort') SORT = d.s;
            else if (a === 'vall') { if (VALL[d.k]) delete VALL[d.k]; else VALL[d.k] = 1; }
            else return;
            PG = 1;
            draw();
            refocus();
        });
        root.addEventListener('input', function (e) { if (e.target.id === 'uaQ') { Q = e.target.value.trim(); PG = 1; drawUsers(); } });
        root.addEventListener('change', function (e) { if (e.target.id === 'uaOld') { OLD = e.target.checked; PG = 1; drawUsers(); } });

        fetch('?ajax=uapps', {credentials: 'same-origin', cache: 'no-store'}).then(function (r) { return r.json(); }).catch(function () { return {ok: false, error: 'Нет связи с сервером'}; }).then(function (r) {
            if (!r || !r.ok) {
                var er = String((r && r.error) || 'панель не ответила');
                root.innerHTML = '<div class="warn">Список устройств не загрузился: ' + esc(er) + '.' + (/403/.test(er) ? ' API-токену нужно право <code>hwid-user-devices:list</code> — проверьте на вкладке <a href="?tab=connection">«Подключение»</a>.' : '') + '</div>';
                return;
            }
            A = r.A || []; U = r.U || []; TS = +r.ts || 0; TTL = +r.ttl || 0; CV = !!r.cv; ERR = String(r.error || '');
            D = (r.D || []).map(function (x) { return {c: x[0], p: PL[x[1]] ? x[1] : 'other', s: STK[x[2]] || 'none', v: String(x[3]), u: x[4], m: String(x[5]), o: String(x[6]), t: +x[7] || 0}; });
            draw();
        });
    })();
    </script>
