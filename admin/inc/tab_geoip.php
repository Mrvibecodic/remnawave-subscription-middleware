<?php $gi_st = geoip_status(); ?>
<style>
.gi-grid{display:grid;grid-template-columns:1fr 1fr;gap:.55rem;margin-top:.7rem}
.gi-grid .set-row{margin:0}
.gi-grid .set-row>select{flex:0 1 auto;min-width:0;max-width:19rem}
@media(max-width:1000px){.gi-grid{grid-template-columns:1fr}}
.gi-tbl td:first-child{width:2.2rem}
</style>
<div class="card">
    <h2 style="margin-top:0;font-size:1rem">Базы GeoIP</h2>
    <p class="muted">По ним прослойка определяет страну, провайдера и регион адреса: в сессиях и аномалиях пользователей и в статистике Clod Clash. Базы лежат локально, запросов наружу при проверке адреса нет.</p>
    <form method="post" data-autosave>
        <input type="hidden" name="csrf" value="<?= h($token) ?>">
        <input type="hidden" name="action" value="save_geoip">
        <div class="gi-grid">
        <div class="set-row">
            <div class="set-info"><div class="set-t">Обновлять базы сами</div><div class="set-d">С зеркала ip-location-db — раз в неделю, DB-IP — раз в месяц. Выключите, если кладёте свои файлы.</div></div>
            <label class="switch"><input type="checkbox" name="geoip_auto" <?= geoip_auto() ? 'checked' : '' ?>><span class="sl"></span></label>
        </div>
        <div class="set-row">
            <div class="set-info"><div class="set-t">База городов</div><div class="set-d">Регион и координаты адреса — для регионов в сессиях и карты в статистике. GeoLite2 — около 45 МБ, DB-IP — 60 МБ скачивания и 130 на диске. Выключение удаляет файлы.</div></div>
            <label class="switch"><input type="checkbox" name="geoip_city" <?= geoip_city_on() ? 'checked' : '' ?>><span class="sl"></span></label>
        </div>
        <div class="set-row">
            <div class="set-info"><div class="set-t">База стран и регионов</div><div class="set-d">GeoLite2 с зеркала ip-location-db точнее по регионам, обновляется дважды в неделю. DB-IP Lite — раз в месяц. Смена источника перекачивает базы.</div></div>
            <select name="geoip_geo_src"><?php foreach (geoip_geo_srcs() as $gs_k => $gs_l): ?><option value="<?= h($gs_k) ?>" <?= geoip_geo_src() === $gs_k ? 'selected' : '' ?>><?= h($gs_l) ?></option><?php endforeach; ?></select>
        </div>
        <div class="set-row">
            <div class="set-info"><div class="set-t">База провайдеров (AS)</div><div class="set-d">origin-asn собрана из данных регистраторов и BGP, обновляется ежедневно, без регистрации и условий. DB-IP ASN Lite — раз в месяц, имена провайдеров беднее.</div></div>
            <select name="geoip_asn_src"><?php foreach (geoip_asn_srcs() as $gs_k => $gs_l): ?><option value="<?= h($gs_k) ?>" <?= geoip_asn_src() === $gs_k ? 'selected' : '' ?>><?= h($gs_l) ?></option><?php endforeach; ?></select>
        </div>
        </div>
    </form>
    <table class="logtbl gi-tbl" style="margin-top:1rem">
        <tbody>
        <?php foreach (['country' => 'Страна', 'asn' => 'Автономная система (провайдер)', 'city' => 'Города и координаты (регионы)', 'city6' => 'Города и координаты, IPv6'] as $gk => $gt): $g = $gi_st[$gk]; if (($gk === 'city' && !geoip_city_on() && !$g['ok']) || ($gk === 'city6' && !(geoip_city_on() && geoip_geo_src() !== 'dbip') && !$g['ok'])) continue; ?>
        <tr><td><?= $g['ok'] ? '✅' : '⚠️' ?></td>
            <td><?= h($gt) ?> — <?php if ($g['ok']): ?><code><?= h($g['type']) ?></code>, сборка <?= $g['built'] > 0 ? h(gmdate('Y-m-d', (int) $g['built'])) : '—' ?><?php else: ?>базы нет<?= is_file($g['path']) ? ', файл не читается' : '' ?><?php endif; ?>
                <div class="muted" style="font-size:.78rem"><code><?= h($g['path']) ?></code></div></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p class="muted" style="font-size:.8rem;margin-top:.6rem">Без баз сессии и отчёты всё равно пишутся, но страна, провайдер и регион у этих записей останутся пустыми. Свои файлы MaxMind DB (например, GeoLite2-Country, GeoLite2-ASN и GeoLite2-City) кладите по путям выше под теми же именами. Базы по умолчанию — с зеркала <a href="https://github.com/sapics/ip-location-db" target="_blank" rel="noopener">ip-location-db</a>: страна и города — GeoLite2 (данные <a href="https://www.maxmind.com" target="_blank" rel="noopener">MaxMind</a>), провайдеры — origin-asn (PDDL); запасной источник — <a href="https://db-ip.com" target="_blank" rel="noopener">IP Geolocation by DB-IP</a>, CC BY 4.0.</p>
    <form method="post" style="margin-top:.6rem">
        <input type="hidden" name="csrf" value="<?= h($token) ?>">
        <input type="hidden" name="action" value="geoip_update">
        <button type="submit" class="btn ghost">↻ Скачать базы сейчас</button>
    </form>
</div>
