<?php
// Ключ-ссылка админки из консоли: без аргументов печатает состояние и ссылку, --on включает, --new перевыпускает, --off выключает.
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
if ($op === '--new' || $op === '--on') {
    $was = admin_gate_key() !== '';
    admin_gate_new();
    echo $was ? "Ключ перевыпущен, старая ссылка не работает.\n" : "Ключ включён: без ссылки /admin/ показывает приманку.\n";
} elseif (admin_gate_key() === '') {
    echo "Ключ выключен: /admin/ открывается по обычному адресу. Включить: php tools/admin-gate.php --on\n";
    exit(0);
}
echo admin_gate_link(), "\n";
