<?php
declare(strict_types=1);

require __DIR__ . '/_boot.php';

$p = db();
rep_ensure();
$rep = array_merge(rep_derived_tables(), ['rep_raw']);

function rc_fill(PDO $p, array $tables): void
{
    foreach ($tables as $t) {
        $p->exec($t === 'rep_raw' ? "INSERT INTO rep_raw (ts, short_uuid, body) VALUES (1, 'aa', 'x')" : (db_driver() === 'mysql' ? "INSERT INTO $t () VALUES ()" : "INSERT INTO $t DEFAULT VALUES"));
    }
}

function rc_count(PDO $p, string $t): int { return (int) $p->query("SELECT COUNT(*) FROM $t")->fetchColumn(); }

rc_fill($p, $rep);
$p->exec("INSERT INTO overrides (match_type, match_value, reason, note) VALUES ('short_uuid', 'keep-me', 'blocked', '')");
t_settings(['rep_enabled' => '1', 'rep_keep_days' => '45', 'rep_total' => '12', 'rep_seen' => '3', 'rep_rb' => '', 'update_state' => json_encode(['checked_at' => time()])]);
$other = rc_count($p, 'overrides');

$mw = t_serve($tt_root, $tt_root . '/tools/tests/_router.php', [], ['opcache.enable' => '0']);
$jar = t_admin_login($mw);
[$c, , $page] = t_http('GET', $mw . '/admin/index.php?tab=reports', [], null, $jar);
t_ok('у «Пересобрать статистику» есть «Очистить статистику» с окном подтверждения', $c === 200 && strpos($page, 'id="rvClear"') !== false && strpos($page, 'id="rvClrDlg"') !== false && strpos($page, '</html>') !== false);
preg_match('~name="csrf" value="([0-9a-f]+)"~', $page, $m);
$csrf = $m[1] ?? '';
$post = static function (array $f) use ($mw, $jar, $csrf): string {
    t_http('POST', $mw . '/admin/index.php', ['content-type' => 'application/x-www-form-urlencoded'], http_build_query($f + ['csrf' => $csrf]), $jar);
    [, , $b] = t_http('GET', $mw . '/admin/index.php?tab=reports', [], null, $jar);
    return preg_match('~id="flashMsg" data-msg="([^"]*)"~', $b, $mm) ? html_entity_decode($mm[1], ENT_QUOTES) : '';
};

$fl = $post(['action' => 'rep_clear', 'word' => 'да']);
$left = array_sum(array_map(static fn($t) => rc_count($p, $t), $rep));
t_ok('без слова «очистить» ничего не удаляется', $left === count($rep) && strpos($fl, 'не очищена') !== false, $fl);
t_http('POST', $mw . '/admin/index.php', ['content-type' => 'application/x-www-form-urlencoded'], http_build_query(['action' => 'rep_clear', 'word' => 'очистить', 'csrf' => 'bad']), $jar);
t_eq('без CSRF ничего не удаляется', array_sum(array_map(static fn($t) => rc_count($p, $t), $rep)), count($rep));

set_setting('rep_rb', '0');
set_setting('rep_rb_need', '1');
$fl = $post(['action' => 'rep_clear', 'word' => '  Очистить ']);
unset($GLOBALS['submw_settings_cache']);
foreach ($rep as $t) t_eq('пусто: ' . $t, rc_count($p, $t), 0);
t_ok('сообщение об очистке', strpos($fl, 'Статистика Clod Clash очищена') !== false, $fl);
t_eq('остальные данные не тронуты', rc_count($p, 'overrides'), $other);
t_ok('настройки приёма остались', setting('rep_enabled') === '1' && setting('rep_keep_days') === '45');
t_ok('пересборка остановлена, баннер о ней снят', !rep_rebuild_active() && !rep_rebuild_need());
t_eq('значок новых отчётов погашен', rep_unseen(), 0);
[$c, , $page] = t_http('GET', $mw . '/admin/index.php?tab=reports', [], null, $jar);
t_ok('вкладка после очистки открывается', $c === 200 && strpos($page, '</html>') !== false && strpos($page, 'Пока нет ни одного') !== false);

rc_fill($p, ['rep_raw']);
t_ok('напрямую: очистка работает и на повторе', rep_clear_all($err) && rep_clear_all($err) && rc_count($p, 'rep_raw') === 0, $err);

t_done();
