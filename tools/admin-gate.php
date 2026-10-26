<?php
// Ключ-ссылка админки из консоли: без аргументов печатает ссылку, --new перевыпускает, --off выключает.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(); }
require dirname(__DIR__) . '/lib.php';

if (!is_installed()) {
    $f = dirname(__DIR__) . '/data/install.json';
    $s = is_file($f) ? json_decode((string) @file_get_contents($f), true) : null;
    $key = is_array($s) ? (string) ($s['key'] ?? '') : '';
    echo $key !== '' ? "Прослойка ещё не установлена. Ссылка мастера: https://<домен>/admin/?{$key}\n" : "Прослойка ещё не установлена; ключ появится при первом открытии /admin/ по домену.\n";
    exit(0);
}

$op = $argv[1] ?? '';
if ($op === '--off') {
    set_setting('admin_gate_key', '');
    echo "Ключ выключен: /admin/ открывается без ссылки.\n";
    exit(0);
}
if ($op === '--new' || admin_gate_key() === '') {
    admin_gate_new();
    echo $op === '--new' ? "Ключ перевыпущен, старая ссылка не работает.\n" : "Ключ включён.\n";
}
echo admin_gate_link(), "\n";
