<?php
declare(strict_types=1);

require __DIR__ . '/_boot.php';

// Таблица сессий из прошлой версии — без колонки активности: миграция добавляет её, старые строки остаются с 0.
$drv = db_driver();
$tt_pdo->exec('DROP TABLE IF EXISTS conn_sess');
$old = str_replace([', act_ts INT UNSIGNED NOT NULL DEFAULT 0', ', act_ts INTEGER NOT NULL DEFAULT 0'], '', sess_ddl($drv)[0]);
t_ok('старая схема без колонки активности', strpos($old, 'act_ts') === false);
$tt_pdo->exec($old);
$tt_pdo->prepare('INSERT INTO conn_sess (uid, node, ip, cc, asn, org, sub, start_ts, last_ts, n) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)')
    ->execute([9, 'old-node', '10.0.0.1', '', 0, '', '', time() - 99999, time() - 99999]);
t_ok('миграция проходит', sess_ensure());
t_ok('колонка активности добавлена', in_array('act_ts', db_table_columns($tt_pdo, $drv, 'conn_sess'), true));
t_eq('старая строка — активность неизвестна', (int) $tt_pdo->query('SELECT act_ts FROM conn_sess WHERE uid = 9')->fetchColumn(), 0);

// Разбор времени из ответа панели
$now = time();
t_eq('время из ответа', sess_seen_ts(['ip' => '1.1.1.1', 'lastSeen' => gmdate('Y-m-d\TH:i:s.000\Z', $now - 300)], $now), $now - 300);
t_eq('1970 от старой ноды — неизвестно', sess_seen_ts(['ip' => '1.1.1.1', 'lastSeen' => '1970-01-01T00:00:00.000Z'], $now), 0);
t_eq('без поля — неизвестно', sess_seen_ts(['ip' => '1.1.1.1'], $now), 0);
t_eq('мусор — неизвестно', sess_seen_ts(['ip' => '1.1.1.1', 'lastSeen' => 'вчера'], $now), 0);
t_eq('IP строкой (панель 2.x) — неизвестно', sess_seen_ts('1.1.1.1', $now), 0);
t_eq('часы ноды впереди — не позже «сейчас»', sess_seen_ts(['ip' => '1.1.1.1', 'lastSeen' => gmdate('Y-m-d\TH:i:s.000\Z', $now + 3600)], $now), $now);

// Сбор: активность пишется в сессию и не откатывается назад
$state = $tt_root . '/data/mock_state.json';
$N1 = '11111111-1111-4111-8111-111111111111';
$mock = static function (array $conns) use ($state, $N1) {
    file_put_contents($state, json_encode(['users' => ['AbCdEfGhIjKlMnOp' => ['status' => 'ACTIVE']], 'nodes' => [$N1 => ['name' => 'Amsterdam', 'cc' => 'NL']], 'conns' => $conns]));
};
$mock([]);
$panel = t_serve($tt_root . '/tools', $tt_root . '/tools/mock_panel.php', ['MOCK_STATE' => $state]);
t_settings(['sub_source' => 'panel', 'remnawave_url' => $panel, 'remnawave_api_key' => 'test-token', 'geoip_auto' => '0', 'sess_enabled' => '1', 'sess_every' => '120']);
$act = static function (string $ip) use ($tt_pdo) { $s = $tt_pdo->prepare('SELECT act_ts FROM conn_sess WHERE uid = 1 AND ip = ? ORDER BY id DESC'); $s->execute([$ip]); return (int) $s->fetchColumn(); };

$t1 = time() - 600;
$mock([$N1 => ['1' => [['ip' => '1.2.3.4', 'ls' => $t1], ['ip' => '5.5.5.5', 'ls' => 0], '6.6.6.6']]]);
t_eq('опрос без ошибок', sess_collect(microtime(true) + 10), '');
t_eq('активность записана', $act('1.2.3.4'), $t1);
t_eq('1970 — активность неизвестна', $act('5.5.5.5'), 0);
t_ok('время без явного значения — «сейчас»', $act('6.6.6.6') >= time() - 5);

$mock([$N1 => ['1' => [['ip' => '1.2.3.4', 'ls' => $t1 - 300], ['ip' => '5.5.5.5', 'ls' => 0], '6.6.6.6']]]);
sess_collect(microtime(true) + 10);
t_eq('более старое время не откатывает активность', $act('1.2.3.4'), $t1);

$t2 = time() - 60;
$mock([$N1 => ['1' => [['ip' => '1.2.3.4', 'ls' => $t2], ['ip' => '5.5.5.5', 'ls' => 0], '6.6.6.6']]]);
sess_collect(microtime(true) + 10);
t_eq('новое время — активность обновлена, сессия та же', [$act('1.2.3.4'), (int) $tt_pdo->query("SELECT COUNT(*) FROM conn_sess WHERE uid = 1 AND ip = '1.2.3.4'")->fetchColumn()], [$t2, 1]);

$rows = array_column(sess_user_rows(1), 'a', 'ip');
t_eq('карточка получает активность', [$rows['1.2.3.4'] ?? null, $rows['5.5.5.5'] ?? null], [$t2, 0]);

t_done();
