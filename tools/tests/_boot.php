<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Тест пересоздаёт базу: запуск разрешён только в копии, которую собрал tools/test.php.
$tt_root = dirname(__DIR__, 2);
$tt_box  = (string) getenv('SUBMW_TEST_BOX');
if ($tt_box === '' || realpath($tt_box) !== realpath($tt_root)) {
    fwrite(STDERR, "Тесты запускаются только через php tools/test.php\n");
    exit(2);
}

foreach (glob($tt_root . '/data/submw.sqlite*') ?: [] as $f) unlink($f);
foreach (glob($tt_root . '/data/*.lock') ?: [] as $f) unlink($f);

$_SERVER['REQUEST_URI'] = '/';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require $tt_root . '/lib.php';

$tt_pdo = db();
if (!$tt_pdo) { fwrite(STDERR, "нет базы\n"); exit(2); }
if (db_driver() === 'mysql') {
    $tt_pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ($tt_pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) $tt_pdo->exec('DROP TABLE `' . str_replace('`', '', (string) $t) . '`');
    $tt_pdo->exec('SET FOREIGN_KEY_CHECKS=1');
}
foreach (install_statements() as $s) $tt_pdo->exec($s);
foreach (install_seed_values() as $k => $v) set_setting($k, $v);

$tt_failed = 0;

function t_eq(string $name, $got, $want): void
{
    global $tt_failed;
    if ($got === $want) { echo "  ок    {$name}\n"; return; }
    $tt_failed++;
    $s = static fn($v) => mb_strimwidth(var_export($v, true), 0, 400, '…');
    echo "  ПРОВАЛ {$name}\n    получено:  " . $s($got) . "\n    ожидалось: " . $s($want) . "\n";
}

function t_ok(string $name, bool $cond, string $more = ''): void
{
    global $tt_failed;
    if ($cond) { echo "  ок    {$name}\n"; return; }
    $tt_failed++;
    echo "  ПРОВАЛ {$name}" . ($more !== '' ? "\n    " . $more : '') . "\n";
}

function t_settings(array $kv): void
{
    foreach ($kv as $k => $v) set_setting($k, (string) $v);
}

function t_done(): void
{
    global $tt_failed, $tt_logs;
    foreach ($tt_logs ?? [] as $f) {
        foreach (@file($f) ?: [] as $line) {
            if (preg_match('~PHP (Warning|Notice|Deprecated|Fatal|Parse)~', $line)) {
                $tt_failed++;
                echo '  ПРОВАЛ ошибка PHP на сервере: ' . trim($line) . "\n";
            }
        }
    }
    exit($tt_failed === 0 ? 0 : 1);
}

function t_port(): int
{
    $s = stream_socket_server('tcp://127.0.0.1:0', $no, $er);
    $name = stream_socket_get_name($s, false);
    fclose($s);
    return (int) substr((string) strrchr((string) $name, ':'), 1);
}

// Встроенный сервер PHP в фоне; гасится при выходе теста, его ошибки PHP валят тест.
function t_serve(string $docroot, string $router, array $env = [], array $ini = [], int $port = 0): string
{
    $port = $port ?: t_port();
    $e = getenv();
    foreach ($env as $k => $v) $e[$k] = (string) $v;
    $log  = dirname(__DIR__, 2) . '/data/serve-' . $port . '.log';
    $GLOBALS['tt_logs'][] = $log;
    $cmd  = [PHP_BINARY, '-d', 'log_errors=1', '-d', 'error_reporting=-1'];
    foreach ($ini as $k => $v) { $cmd[] = '-d'; $cmd[] = $k . '=' . $v; }
    $proc = proc_open(array_merge($cmd, ['-S', '127.0.0.1:' . $port, '-t', $docroot, $router]), [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $docroot, $e);
    register_shutdown_function(static function () use ($proc) { @proc_terminate($proc); @proc_close($proc); });
    $url = 'http://127.0.0.1:' . $port;
    for ($i = 0; $i < 100; $i++) {
        $c = @fsockopen('127.0.0.1', $port, $no, $er, 0.1);
        if ($c) { fclose($c); return $url; }
        usleep(50000);
    }
    throw new RuntimeException('сервер не поднялся на ' . $url);
}

function t_http(string $method, string $url, array $headers = [], ?string $body = null, string $jar = ''): array
{
    $ch = curl_init($url);
    if ($jar !== '') curl_setopt_array($ch, [CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar]);
    $h = [];
    foreach ($headers as $k => $v) $h[] = $k . ': ' . $v;
    $resp = [];
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $h,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HEADERFUNCTION => static function ($c, $line) use (&$resp) {
            $p = explode(':', $line, 2);
            if (count($p) === 2) $resp[strtolower(trim($p[0]))] = trim($p[1]);
            return strlen($line);
        },
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $out  = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$code, $resp, is_string($out) ? $out : ''];
}

// Вход в админку (пароль тестовой копии — test); возвращает файл cookie.
function t_admin_login(string $base): string
{
    $jar = dirname(__DIR__, 2) . '/data/cookies-' . bin2hex(random_bytes(3)) . '.txt';
    [, , $b] = t_http('GET', $base . '/admin/index.php', [], null, $jar);
    if (!preg_match('~name="csrf" value="([0-9a-f]+)"~', $b, $m)) throw new RuntimeException('нет формы входа');
    [$c, $h] = t_http('POST', $base . '/admin/index.php', ['content-type' => 'application/x-www-form-urlencoded'], http_build_query(['csrf' => $m[1], 'user' => 'admin', 'pass' => 'test']), $jar);
    if ($c !== 302) throw new RuntimeException('вход не удался: ' . $c);
    return $jar;
}
