<?php
$rv_f      = rep_view_filters($_GET);
$rv_now    = time();
$rv_since  = rep_view_since($rv_f, $rv_now);
$rv_tot    = rep_view_totals($rv_f, $rv_since);
$rv_series = rep_view_series($rv_f, $rv_since, $rv_now);
$rv_cc     = rep_view_countries($rv_f, $rv_since);
$rv_isps   = rep_view_isps($rv_f, $rv_since);
$rv_nodes  = rep_view_nodes($rv_f, $rv_since);
$rv_client = rep_view_client($rv_f['q']);
$rv_panel  = rep_panel_index();
$rep_st    = rep_stats();
$rep_geo   = geoip_status();
$rv_node   = null;
foreach ($rv_nodes as $n) if ($n['nkey'] === $rv_f['node']) $rv_node = $n;
if ($rv_f['node'] !== '' && $rv_node === null) {
    foreach (rep_view_rows('SELECT nkey, type, server, port, name FROM rep_node WHERE nkey = ?', [$rv_f['node']]) as $n) {
        $rv_node = ['nkey' => $n['nkey'], 'name' => $n['name'], 'type' => $n['type'], 'server' => $n['server'], 'port' => (int) $n['port']];
    }
}
$rv_node_names = [];
foreach (rep_view_rows('SELECT nkey, name, server FROM rep_node', []) as $n) $rv_node_names[$n['nkey']] = $n['name'] !== '' ? $n['name'] : $n['server'];

$rv_url = function (array $over = []) use ($rv_f) {
    $q = ['tab' => 'reports'] + array_filter(array_merge(['p' => $rv_f['p'], 'kind' => $rv_f['kind'], 'plat' => $rv_f['plat'], 'cc' => $rv_f['cc'], 'node' => $rv_f['node']], $over), static fn($v) => $v !== '' && $v !== null);

    return '?' . http_build_query($q);
};
$rv_pct = static fn($x) => $x === null ? '—' : (($x * 100 < 10 ? number_format($x * 100, 1, ',', ' ') : (string) round($x * 100)) . ' %');
$rv_num = static fn($n) => number_format((int) $n, 0, ',', ' ');
$rv_bytes = static function ($b) {
    $b = (int) $b;
    foreach (['Б', 'КБ', 'МБ', 'ГБ', 'ТБ'] as $i => $u) {
        if ($b < 1024 ** ($i + 1) || $u === 'ТБ') return ($i === 0 ? $b : number_format($b / 1024 ** $i, $b / 1024 ** $i < 10 ? 1 : 0, ',', ' ')) . ' ' . $u;
    }
};
$rv_med = static fn($m) => [-1 => '—', 0 => '< 100 мс', 1 => '100–200 мс', 2 => '200–400 мс', 3 => '400–800 мс', 4 => '0,8–1,5 с', 5 => '> 1,5 с'][$m] ?? '—';
$rv_qfail = static function ($x) {
    if ($x === null) return 'q0';
    if ($x < .01) return 'q1';
    if ($x < .03) return 'q2';
    if ($x < .10) return 'q3';
    if ($x < .25) return 'q4';
    return 'q5';
};
$rv_qmed = static fn($m) => $m < 0 ? 'q0' : 'q' . min(5, $m + 1);
$rv_qfrz = static function ($s) {
    $all = $s['fok'] + $s['ffr'] + $s['fdd'];
    if ($all === 0) return 'q0';
    $bad = ($s['ffr'] + $s['fdd']) / $all;
    if ($bad === 0) return 'q1';
    if ($bad < .10) return 'q2';
    if ($bad < .25) return 'q3';
    if ($bad < .50) return 'q4';
    return 'q5';
};
$rv_frz = static function ($s) {
    if ($s['fok'] + $s['ffr'] + $s['fdd'] === 0) return '<span class="muted">—</span>';
    $out = [];
    if ($s['ffr'] > 0) $out[] = '<span class="rq q4" title="Режется">✂ ' . (int) $s['ffr'] . '</span>';
    if ($s['fdd'] > 0) $out[] = '<span class="rq q5" title="Не отвечает">✕ ' . (int) $s['fdd'] . '</span>';
    if ($s['fok'] > 0) $out[] = '<span class="rq q1" title="Работает">✓ ' . (int) $s['fok'] . '</span>';

    return implode(' ', $out);
};
$rv_flag = static function ($cc) {
    if (!preg_match('~^[A-Z]{2}$~', (string) $cc)) return '';

    return mb_chr(0x1F1E6 + ord($cc[0]) - 65) . mb_chr(0x1F1E6 + ord($cc[1]) - 65);
};
$rv_panel_of = static function ($server) use ($rv_panel) {
    $hit = $rv_panel[strtolower((string) $server)] ?? [];

    return is_array($hit) ? $hit : [];
};

$rv_map = [];
foreach ($rv_cc as $cc => $s) {
    $rv_map[$cc] = ['pn' => $s['pn'], 'fail' => $s['fail'], 'med' => $s['med'], 'fok' => $s['fok'], 'ffr' => $s['ffr'], 'fdd' => $s['fdd'],
        'q' => ['fail' => $rv_qfail($s['fail']), 'med' => $rv_qmed($s['med']), 'frz' => $rv_qfrz($s)]];
}

$rv_max = 1;
foreach ($rv_series['points'] as $pt) $rv_max = max($rv_max, $pt['pn']);
?>
    <style>
    .rv-flt{display:flex;flex-wrap:wrap;gap:.5rem;align-items:center}
    .rv-flt select{width:auto;height:38px;min-height:38px;padding-top:0;padding-bottom:0;font-size:.84rem}
    .rv-seg{display:inline-flex;border:1px solid var(--line);border-radius:10px;overflow:hidden}
    .rv-seg a{padding:.45rem .8rem;font-size:.84rem;color:var(--text);text-decoration:none;border-left:1px solid var(--line)}
    .rv-seg a:first-child{border-left:0}
    .rv-seg a.on{background:var(--accent-light);color:var(--accent-text);font-weight:600}
    .rv-chip{display:inline-flex;align-items:center;gap:.35rem;padding:.3rem .6rem;border:1px solid var(--accent);border-radius:999px;background:var(--accent-light);color:var(--accent-text);font-size:.82rem;text-decoration:none}
    .rv-kpis{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:.7rem;margin:1rem 0 0}
    .rv-kpi{border:1px solid var(--line);background:var(--bg2);border-radius:12px;padding:.75rem .9rem;display:flex;flex-direction:column;gap:.15rem}
    .rv-kpi .k{font-size:.72rem;color:var(--muted);text-transform:uppercase;letter-spacing:.04em}
    .rv-kpi .v{font-size:1.45rem;font-weight:700;color:var(--text-strong);font-variant-numeric:tabular-nums;line-height:1.2}
    .rv-kpi .d{font-size:.76rem;color:var(--muted)}
    @media(max-width:1100px){.rv-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}}
    .rq{display:inline-flex;align-items:center;gap:.2rem;padding:.08rem .45rem;border-radius:999px;font-size:.78rem;font-weight:600;font-variant-numeric:tabular-nums;white-space:nowrap}
    .rq.q0{color:var(--muted)}
    .rq.q1{background:rgba(16,185,129,.16);color:#10b981}
    .rq.q2{background:rgba(132,204,22,.16);color:#65a30d}
    .rq.q3{background:rgba(245,158,11,.18);color:#d97706}
    .rq.q4{background:rgba(249,115,22,.18);color:#ea580c}
    .rq.q5{background:rgba(239,68,68,.18);color:#ef4444}
    .rv-chart{width:100%;height:170px;display:block}
    .rv-chart .ok{fill:var(--accent);opacity:.55}
    .rv-chart .bad{fill:#ef4444;opacity:.85}
    .rv-chart .ax{fill:var(--muted);font-size:10px}
    .rv-chart .gl{stroke:var(--line);stroke-width:1}
    .rv-mapw{position:relative}
    .rv-map{width:100%;height:auto;display:block}
    .rv-map path{stroke:var(--bg);stroke-width:.5;fill:var(--line);transition:opacity .15s}
    .rv-map path.has{cursor:pointer}
    .rv-map path.has:hover{opacity:.75}
    .rv-map path.q1{fill:#10b981}.rv-map path.q2{fill:#84cc16}.rv-map path.q3{fill:#f59e0b}.rv-map path.q4{fill:#f97316}.rv-map path.q5{fill:#ef4444}
    .rv-map path.sel{stroke:var(--text-strong);stroke-width:1.4}
    .rv-tip{position:absolute;pointer-events:none;background:var(--card);border:1px solid var(--line);border-radius:10px;padding:.5rem .65rem;font-size:.8rem;box-shadow:0 6px 22px rgba(0,0,0,.2);display:none;z-index:5;min-width:11rem}
    .rv-tip b{color:var(--text-strong)}
    .rv-legend{display:flex;gap:.6rem;flex-wrap:wrap;align-items:center;font-size:.78rem;color:var(--muted);margin-top:.5rem}
    .rv-legend i{display:inline-block;width:.8rem;height:.8rem;border-radius:3px;margin-right:.25rem;vertical-align:-1px}
    .rv-tbl{width:100%;font-size:.86rem}
    .rv-tbl th{font-size:.71rem;white-space:nowrap}
    .rv-tbl td{vertical-align:top}
    .rv-tbl .num{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}
    .rv-tbl .sub{display:block;color:var(--muted);font-size:.76rem}
    .rv-tbl code{font-size:.8rem}
    .rv-tbl .ct-time{white-space:nowrap}
    .rv-tbl details summary{cursor:pointer;list-style:none}
    .rv-tbl details summary::-webkit-details-marker{display:none}
    .rv-ips{margin:.4rem 0 .2rem;width:100%;font-size:.8rem}
    .rv-ips td{padding:.25rem .4rem;border-top:1px solid var(--line)}
    .rv-wrap{overflow-x:auto}
    .rv-wrap .rv-tbl thead th{position:static}
    .rv-node a{color:var(--text-strong);font-weight:600;text-decoration:none}
    .rv-node a:hover{color:var(--accent-text)}
    .ctg{display:grid;grid-template-columns:1fr 1fr;gap:.55rem;margin-top:.7rem}
    .ctg .set-row{margin:0}
    @media(max-width:1000px){.ctg{grid-template-columns:1fr}}
    </style>

<?php if (!rep_enabled()): ?>
    <div class="warn">Приём отчётов выключен — новые данные не приходят. Включается ниже, в блоке <a href="#repSettings">«Приём отчётов»</a><?= chan_enabled() ? '' : ', и нужен включённый <a href="?tab=clod">защищённый канал</a>' ?>.</div>
<?php endif; ?>

    <div class="card">
        <form method="get" class="rv-flt">
            <input type="hidden" name="tab" value="reports">
            <?php foreach (['cc', 'node'] as $k): if ($rv_f[$k] !== ''): ?><input type="hidden" name="<?= $k ?>" value="<?= h($rv_f[$k]) ?>"><?php endif; endforeach; ?>
            <span class="rv-seg">
                <?php foreach ([1 => '24 часа', 7 => '7 дней', 30 => '30 дней'] as $p => $pt): ?>
                <a class="<?= $rv_f['p'] === $p ? 'on' : '' ?>" href="<?= h($rv_url(['p' => $p])) ?>"><?= h($pt) ?></a>
                <?php endforeach; ?>
            </span>
            <input type="hidden" name="p" value="<?= (int) $rv_f['p'] ?>">
            <select name="kind" onchange="this.form.submit()">
                <option value="">Все сети</option>
                <?php foreach (REP_VIEW_KINDS as $k => $kt): ?><option value="<?= h($k) ?>"<?= $rv_f['kind'] === $k ? ' selected' : '' ?>><?= h($kt) ?></option><?php endforeach; ?>
            </select>
            <select name="plat" onchange="this.form.submit()">
                <option value="">Все платформы</option>
                <?php foreach (REP_VIEW_PLATS as $k => $kt): ?><option value="<?= h($k) ?>"<?= $rv_f['plat'] === $k ? ' selected' : '' ?>><?= h($kt) ?></option><?php endforeach; ?>
            </select>
            <?php if ($rv_f['cc'] !== ''): ?><a class="rv-chip" href="<?= h($rv_url(['cc' => ''])) ?>" title="Снять фильтр"><?= $rv_flag($rv_f['cc']) ?> <span data-ccname="<?= h($rv_f['cc']) ?>"><?= h($rv_f['cc']) ?></span> ✕</a><?php endif; ?>
            <?php if ($rv_f['node'] !== ''): ?><a class="rv-chip" href="<?= h($rv_url(['node' => ''])) ?>" title="Снять фильтр">Узел: <?= h($rv_node['name'] ?? $rv_f['node']) ?> ✕</a><?php endif; ?>
        </form>

        <div class="rv-kpis">
            <div class="rv-kpi"><span class="k">Устройств</span><span class="v"><?= $rv_num($rv_tot['devices']) ?></span><span class="d">прислали отчёт за период</span></div>
            <div class="rv-kpi"><span class="k">Пинги</span><span class="v"><?= $rv_num($rv_tot['pn']) ?></span><span class="d">неудачных <span class="rq <?= $rv_qfail($rv_tot['fail']) ?>"><?= h($rv_pct($rv_tot['fail'])) ?></span></span></div>
            <div class="rv-kpi"><span class="k">Медианный пинг</span><span class="v" style="font-size:1.15rem"><?= h($rv_med($rv_tot['med'])) ?></span><span class="d">по <?= $rv_num($rv_tot['nodes']) ?> узлам</span></div>
            <div class="rv-kpi"><span class="k">Проверка 16–20</span><span class="v" style="font-size:1.05rem"><?= $rv_frz($rv_tot) ?></span><span class="d">режется · не отвечает · работает</span></div>
            <div class="rv-kpi"><span class="k">Трафик через узлы</span><span class="v"><?= h($rv_bytes($rv_tot['bytes'])) ?></span><span class="d">у клиентов с отчётами</span></div>
        </div>
    </div>

    <div class="card">
        <h2 style="margin-top:0;font-size:1rem">Пинги по <?= $rv_series['step'] === 3600 ? 'часам' : 'суткам' ?><?= $rv_node ? ' — ' . h($rv_node['name'] !== '' ? $rv_node['name'] : $rv_node['server']) : '' ?></h2>
        <?php
        $pts = $rv_series['points'];
        $cnt = max(1, count($pts));
        $W = 1000; $H = 150; $bw = $W / $cnt;
        ?>
        <svg class="rv-chart" viewBox="0 0 <?= $W ?> <?= $H + 18 ?>" preserveAspectRatio="none">
            <line class="gl" x1="0" y1="<?= $H ?>" x2="<?= $W ?>" y2="<?= $H ?>"/>
            <?php foreach ($pts as $i => $pt):
                if ($pt['pn'] <= 0) continue;
                $x = $i * $bw + $bw * .1;
                $w = max(1, $bw * .8);
                $hOk = ($pt['pn'] - $pt['pf']) / $rv_max * ($H - 6);
                $hBad = $pt['pf'] / $rv_max * ($H - 6);
                $tt = date($rv_series['step'] === 3600 ? 'd.m H:00' : 'd.m', $pt['t']) . ' — пингов ' . $pt['pn'] . ', неудачных ' . $pt['pf'] . ($pt['fail'] !== null ? ' (' . $rv_pct($pt['fail']) . ')' : '') . ', медиана ' . $rv_med($pt['med']);
            ?>
            <g><title><?= h($tt) ?></title>
                <rect class="ok" x="<?= round($x, 1) ?>" y="<?= round($H - $hOk - $hBad, 1) ?>" width="<?= round($w, 1) ?>" height="<?= round($hOk, 1) ?>"/>
                <?php if ($hBad > 0): ?><rect class="bad" x="<?= round($x, 1) ?>" y="<?= round($H - $hBad, 1) ?>" width="<?= round($w, 1) ?>" height="<?= round(max($hBad, 1), 1) ?>"/><?php endif; ?>
            </g>
            <?php endforeach; ?>
            <?php
            $marks = $rv_series['step'] === 3600 ? ($rv_f['p'] === 1 ? 6 : 24) : 5;
            foreach ($pts as $i => $pt):
                if ($i % $marks !== 0 || $i * $bw > $W - 40) continue; ?>
            <text class="ax" x="<?= round($i * $bw + 2, 1) ?>" y="<?= $H + 13 ?>"><?= h(date($rv_series['step'] === 3600 && $rv_f['p'] === 1 ? 'H:00' : 'd.m', $pt['t'])) ?></text>
            <?php endforeach; ?>
        </svg>
        <div class="rv-legend"><span><i style="background:var(--accent);opacity:.55"></i>удачные</span><span><i style="background:#ef4444"></i>неудачные</span><span>Наведите на столбец — подробности.</span></div>
    </div>

    <div class="card">
        <div class="loghead"><h2>Карта клиентов</h2>
            <div class="rv-seg" id="rvMetric">
                <a href="#" class="on" data-m="fail">Неудачные пинги</a><a href="#" data-m="med">Медианный пинг</a><a href="#" data-m="frz">Проверка 16–20</a>
            </div>
        </div>
        <p class="muted" style="font-size:.82rem">Страна — по внешнему адресу клиента, с которого сделаны замеры. Нажмите на страну, чтобы посмотреть её узлы и провайдеров.</p>
        <div class="rv-mapw" id="rvMapW">
            <svg class="rv-map" id="rvMap" xmlns="http://www.w3.org/2000/svg"></svg>
            <div class="rv-tip" id="rvTip"></div>
        </div>
        <div class="rv-legend" id="rvLegend"></div>
    </div>

    <div class="card">
        <div class="loghead"><h2>Провайдеры клиентов (<?= count($rv_isps) ?>)</h2></div>
        <p class="muted" style="font-size:.82rem">Провайдер — номер автономной системы (AS) по адресу клиента; название из базы GeoIP может ошибаться, номер AS и адрес — нет. Нажмите на строку, чтобы увидеть адреса.<?= $rv_f['plat'] !== '' ? ' Фильтр по платформе к провайдерам не применяется.' : '' ?></p>
        <?php if (!$rv_isps): ?>
        <p class="muted">Нет данных за период.</p>
        <?php else: ?>
        <div class="rv-wrap"><table class="logtbl rv-tbl">
            <thead><tr><th>Страна</th><th>Провайдер (AS)</th><th class="num">Адресов</th><th class="num">Устройств</th><th class="num">Пингов</th><th class="num">Неудачи</th><th>Медиана</th><th>16–20</th><th class="num">Трафик</th></tr></thead>
            <tbody>
            <?php foreach ($rv_isps as $isp): ?>
            <tr>
                <td><a href="<?= h($rv_url(['cc' => $isp['cc']])) ?>" style="text-decoration:none"><?= $rv_flag($isp['cc']) ?> <span data-ccname="<?= h($isp['cc']) ?>"><?= h($isp['cc'] ?: '—') ?></span></a></td>
                <td><details><summary><b><?= $isp['asn'] > 0 ? 'AS' . (int) $isp['asn'] : 'AS —' ?></b> <?= h($isp['org'] ?: '—') ?> <span class="muted">▾</span></summary>
                    <table class="rv-ips"><tbody>
                    <?php foreach ($isp['ips'] as $ip): ?>
                    <tr><td><code><?= h($ip['ip']) ?></code><span class="sub"><?= $isp['asn'] > 0 ? 'AS' . (int) $isp['asn'] : '' ?> <?= h($ip['org']) ?></span></td>
                        <td class="num"><?= $rv_num($ip['pn']) ?></td>
                        <td><span class="rq <?= $rv_qfail($ip['fail']) ?>"><?= h($rv_pct($ip['fail'])) ?></span></td>
                        <td><span class="rq <?= $rv_qmed($ip['med']) ?>"><?= h($rv_med($ip['med'])) ?></span></td>
                        <td><?= $rv_frz($ip) ?></td>
                        <td class="muted"><?= h(date('d.m', $ip['last'])) ?></td></tr>
                    <?php endforeach; ?>
                    </tbody></table></details></td>
                <td class="num"><?= $rv_num(count($isp['ips'])) ?></td>
                <td class="num"><?= $rv_num($isp['devices']) ?></td>
                <td class="num"><?= $rv_num($isp['pn']) ?></td>
                <td class="num"><span class="rq <?= $rv_qfail($isp['fail']) ?>"><?= h($rv_pct($isp['fail'])) ?></span></td>
                <td><span class="rq <?= $rv_qmed($isp['med']) ?>"><?= h($rv_med($isp['med'])) ?></span></td>
                <td><?= $rv_frz($isp) ?></td>
                <td class="num"><?= h($rv_bytes($isp['bytes'])) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="loghead"><h2>Узлы (<?= count($rv_nodes) ?>)</h2></div>
        <p class="muted" style="font-size:.82rem">Сверху — узлы с наибольшей долей неудачных пингов. «Хуже всего» — страна и провайдер клиентов, у которых этому узлу хуже всего (от <?= REP_VIEW_MIN_PINGS ?> пингов). Нажмите на узел — график и провайдеры только по нему.</p>
        <?php if (!$rv_nodes): ?>
        <p class="muted">Нет данных за период.</p>
        <?php else: ?>
        <div class="rv-wrap"><table class="logtbl rv-tbl">
            <thead><tr><th>Узел</th><th>В панели</th><th class="num">Пингов</th><th class="num">Неудачи</th><th>Медиана</th><th>16–20</th><th class="num">Трафик</th><th>Хуже всего</th></tr></thead>
            <tbody>
            <?php foreach ($rv_nodes as $n): $pnl = $rv_panel_of($n['server']); ?>
            <tr>
                <td class="rv-node"><a href="<?= h($rv_url(['node' => $n['nkey']])) ?>"><?= h($n['name'] !== '' ? $n['name'] : $n['server']) ?></a>
                    <span class="sub"><?= h($n['type']) ?> · <code><?= h($n['server']) ?>:<?= (int) $n['port'] ?></code></span></td>
                <td><?php if ($pnl): foreach ($pnl as $pn): ?><div><?= $rv_flag($pn['cc']) ?> <?= h($pn['name']) ?></div><?php endforeach; else: ?><span class="muted">—</span><?php endif; ?></td>
                <td class="num"><?= $rv_num($n['pn']) ?></td>
                <td class="num"><span class="rq <?= $rv_qfail($n['fail']) ?>"><?= h($rv_pct($n['fail'])) ?></span></td>
                <td><span class="rq <?= $rv_qmed($n['med']) ?>"><?= h($rv_med($n['med'])) ?></span></td>
                <td><?= $rv_frz($n) ?></td>
                <td class="num"><?= h($rv_bytes($n['bytes'])) ?></td>
                <td><?php if ($n['worst'] && $n['worst']['fail'] > 0): ?><?= $rv_flag($n['worst']['cc']) ?> AS<?= (int) $n['worst']['asn'] ?> <span class="rq <?= $rv_qfail($n['worst']['fail']) ?>"><?= h($rv_pct($n['worst']['fail'])) ?></span><?php else: ?><span class="muted">—</span><?php endif; ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2 style="margin-top:0;font-size:1rem">Клиент</h2>
        <p class="muted" style="font-size:.82rem">Последнее, что прислало устройство: в какой сети и с какого адреса оно мерило каждый узел. Поиск по shortUuid подписки или HWID устройства.</p>
        <form method="get" class="rv-flt">
            <input type="hidden" name="tab" value="reports">
            <input type="hidden" name="p" value="<?= (int) $rv_f['p'] ?>">
            <input type="text" name="q" value="<?= h($rv_f['q']) ?>" placeholder="shortUuid или HWID" style="max-width:22rem">
            <button type="submit" class="btn">Найти</button>
        </form>
        <?php if ($rv_f['q'] !== ''): ?>
            <?php if (!$rv_client): ?>
            <p class="muted" style="margin-top:.8rem">Отчётов от этого клиента нет.</p>
            <?php else: ?>
            <div class="rv-wrap" style="margin-top:.8rem"><table class="logtbl rv-tbl">
                <thead><tr><th>Устройство</th><th>Сеть</th><th>Адрес и провайдер</th><th>Узел</th><th class="num">Пингов</th><th class="num">Неудачи</th><th>Медиана</th><th>16–20</th><th>Когда</th></tr></thead>
                <tbody>
                <?php foreach ($rv_client as $r):
                    $pn = (int) $r['pn']; $fail = $pn >= REP_VIEW_MIN_PINGS ? (int) $r['pf'] / $pn : null; ?>
                <tr>
                    <td><code><?= h($r['short_uuid']) ?></code><span class="sub"><?= h(mb_strimwidth((string) $r['hwid'], 0, 24, '…')) ?></span></td>
                    <td><?= h(REP_VIEW_KINDS[$r['kind']] ?? $r['kind']) ?><span class="sub"><code><?= h($r['net']) ?></code></span></td>
                    <td><?= $rv_flag($r['cc']) ?> <code><?= h($r['ip4'] !== '' ? $r['ip4'] : ($r['ip6'] !== '' ? $r['ip6'] : '—')) ?></code><?php if ($r['ip4'] !== '' && $r['ip6'] !== ''): ?><span class="sub"><code><?= h($r['ip6']) ?></code></span><?php endif; ?><span class="sub"><?= (int) $r['asn'] > 0 ? 'AS' . (int) $r['asn'] : '' ?> <?= h($r['org']) ?></span></td>
                    <td><a href="<?= h($rv_url(['node' => $r['nkey'], 'q' => ''])) ?>"><?= h($rv_node_names[$r['nkey']] ?? $r['nkey']) ?></a></td>
                    <td class="num"><?= $rv_num($pn) ?></td>
                    <td class="num"><span class="rq <?= $rv_qfail($fail) ?>"><?= h($rv_pct($fail)) ?></span></td>
                    <td><span class="rq <?= $rv_qmed((int) $r['pmed']) ?>"><?= h($rv_med((int) $r['pmed'])) ?></span></td>
                    <td><?php
                        $v = (string) $r['verdict'];
                        echo $v === '' ? '<span class="muted">—</span>' : '<span class="rq ' . ($v === 'ok' ? 'q1' : ($v === 'frozen' ? 'q4' : 'q5')) . '">' . h(['ok' => 'работает', 'frozen' => 'режется', 'dead' => 'не отвечает'][$v] ?? $v) . ((int) $r['vstatus'] > 0 ? ' · ' . (int) $r['vstatus'] : '') . '</span>';
                    ?></td>
                    <td class="ct-time muted" data-ts="<?= (int) $r['last_seen'] ?>"><?= h(date('Y-m-d H:i', (int) $r['last_seen'])) ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <div class="card" id="repSettings">
        <h2 style="margin-top:0;font-size:1rem">Приём отчётов</h2>
        <p class="muted">Клиенты Clod Clash с защищённой подпиской после планового обновления, не чаще раза в 6 часов, присылают отчёт: итоги проверки 16–20, пинги узлов, трафик по узлам, тип сети (Wi-Fi, мобильная, провод) и внешний IP, с которого делались замеры. Отчёт едет только по защищённому каналу. Пока тумблер выключен, прослойка отвечает клиенту «не принимаю», и он ничего не теряет — данные полежат у него до следующей попытки.</p>
        <form method="post" data-autosave>
            <input type="hidden" name="csrf" value="<?= h($token) ?>">
            <input type="hidden" name="action" value="save_clod_rep">
            <div class="ctg">
            <div class="set-row">
                <div class="set-info"><div class="set-t">Принимать отчёты</div><div class="set-d">Работает только при включённом главном выключателе защищённого канала.</div></div>
                <label class="switch"><input type="checkbox" name="rep_enabled" <?= setting('rep_enabled', '0') === '1' ? 'checked' : '' ?> <?= chan_ext_ok() ? '' : 'disabled' ?>><span class="sl"></span></label>
            </div>
            <div class="set-row">
                <div class="set-info"><div class="set-t">Сколько дней хранить</div><div class="set-d">Старше — удаляется само. От 7 до 365.</div></div>
                <input type="number" name="rep_keep_days" min="7" max="365" value="<?= (int) rep_keep_days() ?>">
            </div>
            <div class="set-row">
                <div class="set-info"><div class="set-t">Обновлять базы GeoIP сами</div><div class="set-d">Раз в месяц скачиваются бесплатные DB-IP Lite (страна и AS). Выключите, если кладёте свои файлы.</div></div>
                <label class="switch"><input type="checkbox" name="geoip_auto" <?= geoip_auto() ? 'checked' : '' ?>><span class="sl"></span></label>
            </div>
            </div>
        </form>
        <table class="logtbl" style="margin-top:1rem">
            <tbody>
            <tr><td style="width:2.2rem"><?= (int) $rep_st['week'] > 0 ? '✅' : '—' ?></td>
                <td>Устройств с отчётами: за сутки <b><?= (int) $rep_st['day'] ?></b>, за неделю <b><?= (int) $rep_st['week'] ?></b><?php if ((int) $rep_st['last'] > 0): ?> · последний отчёт <span class="ct-time" data-ts="<?= (int) $rep_st['last'] ?>"><?= h(date('Y-m-d H:i', (int) $rep_st['last'])) ?></span><?php endif; ?></td></tr>
            <?php foreach (['country' => 'Страна', 'asn' => 'Автономная система (провайдер)'] as $gk => $gt): $g = $rep_geo[$gk]; ?>
            <tr><td><?= $g['ok'] ? '✅' : '⚠️' ?></td>
                <td><?= h($gt) ?> — <?php if ($g['ok']): ?><code><?= h($g['type']) ?></code>, сборка <?= $g['built'] > 0 ? h(gmdate('Y-m-d', (int) $g['built'])) : '—' ?><?php else: ?>базы нет<?= is_file($g['path']) ? ', файл не читается' : '' ?><?php endif; ?>
                    <div class="muted" style="font-size:.78rem"><code><?= h($g['path']) ?></code></div></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p class="muted" style="font-size:.8rem;margin-top:.6rem">Без базы отчёты всё равно принимаются, но страна и провайдер у этих записей останутся пустыми. Свои файлы MaxMind DB (например, GeoLite2-Country или GeoLite2-City и GeoLite2-ASN) кладите по путям выше под теми же именами. Базы по умолчанию: <a href="https://db-ip.com" target="_blank" rel="noopener">IP Geolocation by DB-IP</a>, лицензия CC BY 4.0.</p>
        <form method="post" style="margin-top:.6rem">
            <input type="hidden" name="csrf" value="<?= h($token) ?>">
            <input type="hidden" name="action" value="clod_geoip_update">
            <button type="submit" class="btn ghost">↻ Скачать базы DB-IP сейчас</button>
        </form>
    </div>


    <script src="assets/world.js?v=<?= substr(@md5_file(__DIR__ . '/../assets/world.js') ?: '0', 0, 10) ?>"></script>
    <script>
    (function () {
        var data = <?= json_encode($rv_map, JSON_UNESCAPED_UNICODE) ?>;
        var sel = <?= json_encode($rv_f['cc']) ?>;
        var base = <?= json_encode($rv_url(['cc' => 'XX'])) ?>;
        var names = null;
        try { names = new Intl.DisplayNames(['ru'], {type: 'region'}); } catch (e) {}
        var nm = function (cc) { try { return names ? names.of(cc) : cc; } catch (e) { return cc; } };
        document.querySelectorAll('[data-ccname]').forEach(function (el) { var cc = el.getAttribute('data-ccname'); if (/^[A-Z]{2}$/.test(cc)) el.textContent = nm(cc); });

        var W = window.SUBMW_WORLD, svg = document.getElementById('rvMap'), tip = document.getElementById('rvTip'), wrap = document.getElementById('rvMapW');
        if (!W || !svg) return;
        svg.setAttribute('viewBox', '0 0 ' + W.w + ' ' + W.h);
        var NS = 'http://www.w3.org/2000/svg', metric = 'fail', paths = {};
        Object.keys(W.c).forEach(function (cc) {
            var p = document.createElementNS(NS, 'path');
            p.setAttribute('d', W.c[cc]);
            p.dataset.cc = cc;
            if (data[cc]) p.classList.add('has');
            if (cc === sel) p.classList.add('sel');
            svg.appendChild(p);
            paths[cc] = p;
        });
        var legends = {
            fail: ['< 1 %', '1–3 %', '3–10 %', '10–25 %', '≥ 25 %'],
            med: ['< 100 мс', '100–200 мс', '200–400 мс', '400–800 мс', '> 0,8 с'],
            frz: ['всё работает', '< 10 % проблем', '10–25 %', '25–50 %', '≥ 50 %']
        };
        var colors = ['#10b981', '#84cc16', '#f59e0b', '#f97316', '#ef4444'];
        function paint() {
            Object.keys(paths).forEach(function (cc) {
                var p = paths[cc];
                p.classList.remove('q1', 'q2', 'q3', 'q4', 'q5');
                if (data[cc] && data[cc].q[metric] !== 'q0') p.classList.add(data[cc].q[metric]);
            });
            document.getElementById('rvLegend').innerHTML = legends[metric].map(function (t, i) { return '<span><i style="background:' + colors[i] + '"></i>' + t + '</span>'; }).join('') + '<span><i style="background:var(--line)"></i>нет данных</span>';
        }
        var med = ['< 100 мс', '100–200 мс', '200–400 мс', '400–800 мс', '0,8–1,5 с', '> 1,5 с'];
        svg.addEventListener('mousemove', function (e) {
            var cc = e.target.dataset && e.target.dataset.cc;
            if (!cc) { tip.style.display = 'none'; return; }
            var d = data[cc], r = wrap.getBoundingClientRect();
            tip.innerHTML = '<b>' + nm(cc) + '</b>' + (d
                ? '<br>Пингов: ' + d.pn.toLocaleString('ru') + '<br>Неудачных: ' + (d.fail === null ? '—' : (d.fail * 100).toFixed(1).replace('.', ',') + ' %')
                  + '<br>Медиана: ' + (d.med >= 0 ? med[d.med] : '—') + '<br>16–20: режется ' + d.ffr + ', не отвечает ' + d.fdd + ', работает ' + d.fok
                : '<br><span class="muted">нет отчётов</span>');
            tip.style.display = 'block';
            var x = e.clientX - r.left + 14, y = e.clientY - r.top + 14;
            if (x + tip.offsetWidth > r.width) x = e.clientX - r.left - tip.offsetWidth - 10;
            tip.style.left = x + 'px';
            tip.style.top = y + 'px';
        });
        svg.addEventListener('mouseleave', function () { tip.style.display = 'none'; });
        svg.addEventListener('click', function (e) {
            var cc = e.target.dataset && e.target.dataset.cc;
            if (cc && data[cc]) location.href = base.replace('cc=XX', 'cc=' + cc);
        });
        document.querySelectorAll('#rvMetric a').forEach(function (a) {
            a.addEventListener('click', function (e) {
                e.preventDefault();
                metric = a.dataset.m;
                document.querySelectorAll('#rvMetric a').forEach(function (b) { b.classList.toggle('on', b === a); });
                paint();
            });
        });
        paint();
    })();
    </script>
