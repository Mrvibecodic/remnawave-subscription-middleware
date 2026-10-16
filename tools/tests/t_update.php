<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Апдейтер идёт против мок-GitHub: адреса GitHub в копии update.php подменяются до загрузки модулей.
$root = dirname(__DIR__, 2);
if ((string) getenv('SUBMW_TEST_BOX') === '' || realpath((string) getenv('SUBMW_TEST_BOX')) !== realpath($root)) {
    fwrite(STDERR, "Тесты запускаются только через php tools/test.php\n");
    exit(2);
}
$sock = stream_socket_server('tcp://127.0.0.1:0');
$port = (int) substr((string) strrchr((string) stream_socket_get_name($sock, false), ':'), 1);
fclose($sock);

$touch = ['lib/update.php', 'lib/junk.php', 'lib.php', 'admin/assets/cis.js', 'lib/zz_gone.php', 'tools/zz_added.txt', 'lib/zz_ren_a.txt', 'lib/zz_ren_b.txt'];
$pristine = [];
foreach ($touch as $rel) $pristine[$rel] = is_file($root . '/' . $rel) ? file_get_contents($root . '/' . $rel) : null;
register_shutdown_function(static function () use ($root, $pristine) {
    foreach ($pristine as $rel => $data) {
        if ($data === null) @unlink($root . '/' . $rel);
        else file_put_contents($root . '/' . $rel, $data);
    }
});
file_put_contents($root . '/lib/update.php', str_replace(
    ['https://api.github.com/repos/', 'https://raw.githubusercontent.com/'],
    ['http://127.0.0.1:' . $port . '/api/repos/', 'http://127.0.0.1:' . $port . '/raw/'],
    (string) $pristine['lib/update.php']
));

require __DIR__ . '/_boot.php';

function tu_leftovers(string $dir): array
{
    $out = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $f) {
        if (substr($f->getFilename(), -11) === '.submw-new') $out[] = (string) $f;
    }
    return $out;
}

// Подмена файла
$d = $tt_root . '/data/place';
mkdir($d . '/src', 0700, true);
file_put_contents($d . '/src/new', 'новое');
file_put_contents($d . '/dst.php', 'старое');
chmod($d . '/dst.php', 0640);
t_ok('подмена: удалась', update_place($d . '/src/new', $d . '/dst.php'));
t_eq('подмена: содержимое новое', file_get_contents($d . '/dst.php'), 'новое');
clearstatcache();
t_eq('подмена: права файла сохранены', fileperms($d . '/dst.php') & 0777, 0640);
t_eq('подмена: временных файлов не осталось', tu_leftovers($d), []);
t_ok('подмена: новый файл в новом каталоге', update_place($d . '/src/new', $d . '/a/b/c.php') && file_get_contents($d . '/a/b/c.php') === 'новое');
file_put_contents($d . '/real.php', 'было');
symlink($d . '/real.php', $d . '/link.php');
update_place($d . '/src/new', $d . '/link.php');
t_ok('подмена: симлинк остаётся симлинком, пишется в цель', is_link($d . '/link.php') && file_get_contents($d . '/real.php') === 'новое');

clearstatcache();
$ino = fileinode($d . '/dst.php');
update_place($d . '/src/new', $d . '/dst.php');
clearstatcache();
t_ok('подмена: свой .php меняется через rename (новый inode)', fileinode($d . '/dst.php') !== $ino);
file_put_contents($d . '/style.css', 'было');
clearstatcache();
$ino = fileinode($d . '/style.css');
update_place($d . '/src/new', $d . '/style.css');
clearstatcache();
t_ok('подмена: статика пишется поверх (тот же inode — ACL и атрибуты на месте)', fileinode($d . '/style.css') === $ino && file_get_contents($d . '/style.css') === 'новое');
if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
    file_put_contents($d . '/grp.php', 'было');
    chgrp($d . '/grp.php', 33);
    chmod($d . '/grp.php', 0640);
    update_place($d . '/src/new', $d . '/grp.php');
    clearstatcache();
    t_ok('подмена: группа файла сохранена', filegroup($d . '/grp.php') === 33 && fileowner($d . '/grp.php') === 0 && (fileperms($d . '/grp.php') & 0777) === 0640 && file_get_contents($d . '/grp.php') === 'новое');
    file_put_contents($d . '/alien.php', 'было');
    chown($d . '/alien.php', 65534);
    clearstatcache();
    $ino = fileinode($d . '/alien.php');
    update_place($d . '/src/new', $d . '/alien.php');
    clearstatcache();
    t_ok('подмена: чужой .php пишется поверх, владелец сохранён', fileowner($d . '/alien.php') === 65534 && fileinode($d . '/alien.php') === $ino && file_get_contents($d . '/alien.php') === 'новое');
    t_eq('подмена: после записи поверх временных файлов не осталось', tu_leftovers($d), []);
}

if (function_exists('pcntl_fork')) {
    $a = str_repeat('a', 400000);
    $b = str_repeat('b', 400000);
    file_put_contents($d . '/A', $a);
    file_put_contents($d . '/B', $b);
    file_put_contents($d . '/hot.php', $a);
    $pid = pcntl_fork();
    if ($pid === 0) {
        for ($i = 0; $i < 600; $i++) update_place($d . ($i % 2 ? '/A' : '/B'), $d . '/hot.php');
        // Без функций завершения родителя: они вернули бы файлы копии посреди теста.
        if (function_exists('posix_kill')) posix_kill(getmypid(), 9);
        exit(0);
    }
    $reads = 0;
    $torn  = 0;
    do {
        $s = (string) @file_get_contents($d . '/hot.php');
        $reads++;
        if ($s !== $a && $s !== $b) $torn++;
    } while (pcntl_waitpid($pid, $st, WNOHANG) === 0);
    t_ok('подмена под чтением: ни одного полуфайла', $torn === 0 && $reads > 20, "чтений {$reads}, битых {$torn}");
}

// Обновление целиком
$gh = $tt_root . '/data/gh';
$target = [
    'lib/junk.php'        => (string) $pristine['lib/junk.php'],
    'lib.php'             => (string) $pristine['lib.php'],
    'admin/assets/cis.js' => (string) $pristine['admin/assets/cis.js'],
    'tools/zz_added.txt'  => "добавлен\n",
    'lib/zz_ren_b.txt'    => "переименован\n",
];
$old = [
    'lib/junk.php'        => "<?php // старая\n",
    'lib.php'             => "<?php // старый lib\n",
    'admin/assets/cis.js' => "// старый\n",
    'lib/zz_gone.php'     => "<?php // удалят\n",
    'lib/zz_ren_a.txt'    => "переименован\n",
];
foreach ($target as $rel => $data) {
    @mkdir(dirname($gh . '/tree/' . $rel), 0700, true);
    file_put_contents($gh . '/tree/' . $rel, $data);
}
$blob = static fn(string $s) => sha1('blob ' . strlen($s) . "\0" . $s);
$files = [];
foreach (['admin/assets/cis.js', 'lib.php', 'lib/junk.php'] as $rel) $files[] = ['filename' => $rel, 'status' => 'modified', 'sha' => $blob($target[$rel])];
$files[] = ['filename' => 'tools/zz_added.txt', 'status' => 'added', 'sha' => $blob($target['tools/zz_added.txt'])];
$files[] = ['filename' => 'lib/zz_gone.php', 'status' => 'removed', 'sha' => str_repeat('0', 40)];
$files[] = ['filename' => 'lib/zz_ren_b.txt', 'status' => 'renamed', 'previous_filename' => 'lib/zz_ren_a.txt', 'sha' => $blob($target['lib/zz_ren_b.txt'])];
$files[] = ['filename' => 'config.php', 'status' => 'modified', 'sha' => str_repeat('1', 40)];
file_put_contents($gh . '/state.json', json_encode(['latest' => str_repeat('b', 40), 'files' => $files]));

$setOld = static function () use ($tt_root, $old, $target) {
    foreach ($target as $rel => $_) @unlink($tt_root . '/' . $rel);
    foreach ($old as $rel => $data) file_put_contents($tt_root . '/' . $rel, $data);
    chmod($tt_root . '/lib/junk.php', 0640);
    set_setting('installed_commit', str_repeat('a', 40));
};
$setOld();
$cfg = (string) file_get_contents($tt_root . '/config.php');
t_serve($tt_root, $tt_root . '/tools/tests/_mock_github.php', ['MOCKGH_DIR' => $gh], [], $port);

$ok = update_apply($log, $err);
t_ok('обновление: прошло', $ok === true, (string) $err);
foreach ($target as $rel => $data) t_ok('обновление: ' . $rel . ' как в новой версии', @file_get_contents($tt_root . '/' . $rel) === $data);
t_ok('обновление: удалённый файл убран', !is_file($tt_root . '/lib/zz_gone.php'));
t_ok('обновление: старое имя переименованного убрано', !is_file($tt_root . '/lib/zz_ren_a.txt'));
t_eq('обновление: config.php не тронут', file_get_contents($tt_root . '/config.php'), $cfg);
t_ok('обновление: защищённый файл пропущен в журнале', in_array('пропущен (защищён): config.php', (array) $log, true));
$pos = array_flip((array) $log);
t_ok('обновление: lib.php записан после модулей', ($pos['обновлён: lib.php'] ?? -1) > ($pos['обновлён: lib/junk.php'] ?? 99) && ($pos['обновлён: lib.php'] ?? -1) > ($pos['добавлен: tools/zz_added.txt'] ?? 99), implode(' | ', (array) $log));
clearstatcache();
t_eq('обновление: права обновлённого файла сохранены', fileperms($tt_root . '/lib/junk.php') & 0777, 0640);
t_eq('обновление: версия отмечена', (string) setting('installed_commit', ''), str_repeat('b', 40));
t_eq('обновление: временных файлов не осталось', tu_leftovers($tt_root), []);

$ok = update_rollback($rlog, $err);
t_ok('откат: прошёл', $ok === true, (string) $err);
foreach ($old as $rel => $data) t_ok('откат: ' . $rel . ' как до обновления', @file_get_contents($tt_root . '/' . $rel) === $data);
t_ok('откат: добавленные файлы убраны', !is_file($tt_root . '/tools/zz_added.txt') && !is_file($tt_root . '/lib/zz_ren_b.txt'));
t_eq('откат: версия вернулась', (string) setting('installed_commit', ''), str_repeat('a', 40));
t_eq('откат: временных файлов не осталось', tu_leftovers($tt_root), []);

$setOld();
file_put_contents($gh . '/tree/lib/junk.php', '<?php // подменённый');
$ok = update_apply($log, $err);
t_ok('подмена на пути: обновление отменено', $ok === false && strpos((string) $err, 'Контроль целостности') !== false, (string) $err);
t_eq('подмена на пути: файлы не тронуты', file_get_contents($tt_root . '/lib.php'), $old['lib.php']);

t_done();
