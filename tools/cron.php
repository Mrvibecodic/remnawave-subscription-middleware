<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    if (PHP_SAPI === 'cgi-fcgi' && !isset($_SERVER['REQUEST_METHOD'])) { echo "Нужен php-cli, а не php-cgi\n"; exit(1); }
    http_response_code(404);
    exit;
}

// Планировщик из консоли, раз в минуту от пользователя сайта:
// * * * * * php /путь/к/прослойке/tools/cron.php        (-v — напечатать, что выполнилось)

$_SERVER['REQUEST_URI'] = '/';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require dirname(__DIR__) . '/lib.php';
require_once dirname(__DIR__) . '/lib/cron.php';

// В Docker цикл entrypoint и так идёт от www-data, а сохранённый пользователь сайта мог остаться от прежней установки.
if (!submw_in_docker()) {
    $site = cron_site_uid();
    if (!cron_cli_ok(cron_euid(), $site)) {
        $name = cron_user_name($site);
        fwrite(STDERR, 'Запускайте от пользователя сайта ' . $name . ': crontab -u ' . $name . " -e\n");
        exit(1);
    }
}

$r = cron_run(submw_in_docker() ? 'docker' : 'cli');
if (empty($r['ok'])) {
    fwrite(STDERR, 'submw cron: ' . ($r['err'] ?? 'не выполнен') . "\n");
    exit(1);
}
if (in_array('-v', $argv, true)) {
    foreach ($r['ran'] as $name => $t) echo $name . ': ' . ($t['ok'] ? 'ок' : 'ошибка ' . $t['err']) . ', ' . $t['ms'] . " мс\n";
    if (!$r['ran']) echo ($r['skip'] ?? 'задач по сроку нет') . "\n";
}
