<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Тесты идут на копии кода во временном каталоге: config.php и база установки не трогаются.
// php tools/test.php [часть имени теста] [--keep] [-v]

$src    = dirname(__DIR__);
$keep   = in_array('--keep', $argv, true);
$loud   = in_array('-v', $argv, true);
$filter = '';
foreach (array_slice($argv, 1) as $a) {
    if ($a !== '--keep' && $a !== '-v') $filter = $a;
}

$box = rtrim(sys_get_temp_dir(), '/\\') . '/submw-test-' . bin2hex(random_bytes(4));
$skip = ['.git', '.github', 'data', 'backups', '.backups', 'docs', 'config.php', 'install.json'];

function tt_copy(string $from, string $to, array $skip, bool $top = true): void
{
    if (!is_dir($to) && !mkdir($to, 0700, true)) throw new RuntimeException("не создать {$to}");
    foreach (scandir($from) ?: [] as $name) {
        if ($name === '.' || $name === '..') continue;
        if ($top && in_array($name, $skip, true)) continue;
        $a = $from . '/' . $name;
        $b = $to . '/' . $name;
        if (is_link($a)) continue;
        if (is_dir($a)) tt_copy($a, $b, $skip, false);
        elseif (!copy($a, $b)) throw new RuntimeException("не скопировать {$a}");
    }
}

function tt_rm(string $dir): void
{
    if (!is_dir($dir)) return;
    foreach (scandir($dir) ?: [] as $name) {
        if ($name === '.' || $name === '..') continue;
        $p = $dir . '/' . $name;
        if (is_dir($p) && !is_link($p)) tt_rm($p);
        else @unlink($p);
    }
    @rmdir($dir);
}

tt_copy($src, $box, $skip);
mkdir($box . '/data', 0700, true);
file_put_contents($box . '/config.php', "<?php\nreturn ['installed' => true, 'db' => ['driver' => 'sqlite', 'path' => __DIR__ . '/data/submw.sqlite'], 'admin_user' => 'admin', 'admin_pass_hash' => password_hash('test', PASSWORD_DEFAULT)];\n");

$tests = [];
foreach (glob($src . '/tools/tests/t_*.php') ?: [] as $f) $tests[] = 'tools/tests/' . basename($f);
if (extension_loaded('sodium')) {
    $tests[] = 'tools/chan_selftest.php';
    $tests[] = 'tools/app_selftest.php';
}
sort($tests);
if ($filter !== '') $tests = array_values(array_filter($tests, static fn($t) => strpos($t, $filter) !== false));

echo 'PHP ' . PHP_VERSION . ', каталог ' . $box . "\n";
$bad = [];
$t0  = microtime(true);
foreach ($tests as $t) {
    $env = getenv();
    $env['SUBMW_TEST_BOX'] = $box;
    unset($env['SUBMW_DOCKER']);
    $proc = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_reporting=-1', $box . '/' . $t], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $box, $env);
    if (!is_resource($proc)) { $bad[] = $t; echo "ПРОВАЛ {$t}: не запустился\n"; continue; }
    $out  = stream_get_contents($pipes[1]);
    $err  = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    $ok   = $code === 0 && trim($err) === '';
    echo ($ok ? 'ок     ' : 'ПРОВАЛ ') . $t . "\n";
    if (!$ok) {
        $bad[] = $t;
        foreach (explode("\n", rtrim($out . "\n" . $err)) as $line) {
            if ($line !== '' && strpos($line, '  ок ') !== 0) echo '       ' . $line . "\n";
        }
    } elseif ($loud) {
        echo rtrim($out) . "\n";
    }
}

if ($keep) echo "Каталог оставлен: {$box}\n";
else tt_rm($box);

printf("\nТестов: %d, провалов: %d, %.1f с\n", count($tests), count($bad), microtime(true) - $t0);
exit($bad ? 1 : 0);
