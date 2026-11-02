<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Вкладка «Обновление» в Docker: что образ знает о себе, с чем сверяется и что показывает. GitHub — мок.
$root = dirname(__DIR__, 2);
if ((string) getenv('SUBMW_TEST_BOX') === '' || realpath((string) getenv('SUBMW_TEST_BOX')) !== realpath($root)) {
    fwrite(STDERR, "Тесты запускаются только через php tools/test.php\n");
    exit(2);
}
$port = (static function (): int {
    $s = stream_socket_server('tcp://127.0.0.1:0');
    $p = (int) substr((string) strrchr((string) stream_socket_get_name($s, false), ':'), 1);
    fclose($s);
    return $p;
})();
$orig = (string) file_get_contents($root . '/lib/update.php');
register_shutdown_function(static function () use ($root, $orig) { file_put_contents($root . '/lib/update.php', $orig); });
file_put_contents($root . '/lib/update.php', str_replace('https://api.github.com/repos/', 'http://127.0.0.1:' . $port . '/api/repos/', $orig));

require __DIR__ . '/_boot.php';

$gh = $tt_root . '/data/gh';
@mkdir($gh, 0700, true);
$A = str_repeat('a', 40);
$B = str_repeat('b', 40);
$gh_set = static function (array $st) use ($gh) { file_put_contents($gh . '/state.json', json_encode($st)); };
$hits = static function () use ($gh): array { return array_values(array_filter(explode("\n", (string) @file_get_contents($gh . '/hits.log')))); };
$gh_set(['latest' => $B, 'releases' => ['v1.10.0'], 'files' => [['filename' => 'lib/junk.php', 'status' => 'modified', 'sha' => $B]]]);
t_serve($tt_root, $tt_root . '/tools/tests/_mock_github.php', ['MOCKGH_DIR' => $gh], [], $port);

$env = static function (?string $docker, ?string $ver, ?string $ref): void {
    foreach (['SUBMW_DOCKER' => $docker, 'SUBMW_VERSION' => $ver, 'SUBMW_REF' => $ref] as $k => $v) putenv($v === null ? $k : $k . '=' . $v);
};
$fresh = static function (): void { set_setting('update_state', '{}'); };
set_setting('update_branch', 'main');

// Файловая установка — как раньше.
$env(null, null, null);
set_setting('installed_commit', $A);
t_eq('файлы: ветка из настройки', update_branch(), 'main');
t_eq('файлы: подпись версии', update_version_label(), ['aaaaaaa', 'main']);
t_ok('файлы: смена ветки работает', update_set_branch('dev', $e) === true && update_branch() === 'dev', (string) $e);
set_setting('update_branch', 'main');
$fresh();
update_refresh($e);
t_ok('файлы: новые коммиты видны', update_available() === true, (string) $e);

// Старый образ без отметки ветки — молчит и в GitHub не ходит.
$env('1', $A, null);
$fresh();
$n = count($hits());
update_autocheck();
update_autocheck(3600);
t_eq('старый образ: запросов к GitHub нет', count($hits()), $n);
t_ok('старый образ: проверка не проводится', update_docker_checkable() === false && update_refresh($e) === null && update_available() === false);
t_eq('старый образ: подпись версии', update_version_label(), ['aaaaaaa', '']);
t_ok('старый образ: смена ветки запрещена', update_set_branch('dev', $e) === false && setting('update_branch') === 'main');
$env('1', 'dev', '');
t_ok('ручная сборка без отметок: проверка не проводится', update_docker_checkable() === false);

// Релизный образ.
$env('1', $A, 'v1.10.0');
$fresh();
t_eq('релиз: подпись версии', update_version_label(), ['v1.10.0', '']);
update_autocheck();
t_eq('релиз: спрашивается список релизов', array_slice($hits(), -1), ['/api/repos/Mrvibecodic/remnawave-subscription-middleware/releases']);
t_ok('релиз: установлена последняя — обновления нет', update_available() === false && update_state()['latest_tag'] === 'v1.10.0');
$n = count($hits());
update_autocheck();
update_autocheck(3600);
t_eq('релиз: повторные проверки в пределах срока не ходят в GitHub', count($hits()), $n);
$gh_set(['latest' => $B, 'releases' => ['v1.11.0', 'v1.10.0']]);
update_refresh($e);
t_ok('релиз: вышел новый — обновление есть', update_available() === true);
t_ok('релиз: ветка main в GitHub не спрашивается', !in_array('/api/repos/Mrvibecodic/remnawave-subscription-middleware/commits/main', array_slice($hits(), $n), true));

$env('1', $B, 'v1.11.0');
t_ok('релиз: после замены образа старый результат не показывается', update_available() === false);
$n = count($hits());
update_autocheck();
t_eq('релиз: новый образ проверяется сразу', count($hits()), $n + 1);
t_ok('релиз: на новом образе обновления нет', update_available() === false);

$gh_set(['latest' => $B, 'releases' => ['v1.9.12', 'v1.11.0']]);
update_refresh($e);
t_ok('релиз: версии сравниваются числами, а не строкой', update_available() === false && update_state()['latest_tag'] === 'v1.11.0');
$gh_set(['latest' => $B, 'releases' => ['~v1.13.0', 'v1.12.0', 'v1.11.0']]);
update_refresh($e);
t_ok('релиз: 1.12 новее 1.11, пререлиз не в счёт', update_available() === true && update_state()['latest_tag'] === 'v1.12.0');
$gh_set(['latest' => $B, 'releases' => ['v2.0.0', 'v1.11.0']]);
update_refresh($e);
$st = update_state();
t_ok('релиз: новая основная версия не зажигает обновление для :v1', update_available() === false && $st['latest_tag'] === 'v1.11.0' && $st['newest_tag'] === 'v2.0.0');
$gh_set(['latest' => $B, 'releases' => ['v2.0.0', 'v1.12.0', 'v1.11.0']]);
update_refresh($e);
t_ok('релиз: при вышедшей v2 обновление внутри v1 видно', update_available() === true && update_state()['latest_tag'] === 'v1.12.0');
$gh_set(['latest' => $B, 'releases' => []]);
update_refresh($e);
t_ok('релиз: пустой список релизов — ошибка, прошлый результат сохранён', (update_state()['last_err'] ?? '') !== '' && update_state()['latest_tag'] === 'v1.12.0');

$gh_set(['latest' => $B]);
$env('1', $A, 'v1.12.0');
update_autocheck();
$st = update_state();
t_ok('релиз: GitHub не ответил — ошибка записана, обновления нет', update_available() === false && ($st['last_err'] ?? '') !== '' && ($st['img'] ?? '') === update_docker_img());
$n = count($hits());
update_autocheck();
update_autocheck(3600);
t_eq('релиз: после неудачи проверка не повторяется на каждом заходе', count($hits()), $n);

// Образ ветки.
$gh_set(['latest' => $B, 'releases' => ['v1.10.0'], 'files' => [['filename' => 'lib/junk.php', 'status' => 'modified', 'sha' => $B]]]);
$env('1', $A, 'dev');
$fresh();
t_eq('ветка: ветка из образа, не из настройки', update_branch(), 'dev');
t_eq('ветка: подпись версии', update_version_label(), ['aaaaaaa', 'dev']);
update_autocheck();
t_ok('ветка: спрашивается голова dev', in_array('/api/repos/Mrvibecodic/remnawave-subscription-middleware/commits/dev', $hits(), true));
t_ok('ветка: новые коммиты видны', update_available() === true && (int) update_state()['ahead_by'] === 1);
$env('1', $B, 'dev');
t_ok('ветка: после замены образа старый результат не показывается', update_available() === false);
update_autocheck();
t_ok('ветка: на свежем образе обновления нет', update_available() === false && update_state()['installed_sha'] === $B);
$env('1', $A, 'main');
update_autocheck();
t_ok('ветка: образ latest/main сверяется с main', in_array('/api/repos/Mrvibecodic/remnawave-subscription-middleware/commits/main', $hits(), true) && update_available() === true);

// Вкладка и шапка админки в Docker (там config.php лежит в data/).
file_put_contents($tt_root . '/data/config.php', str_replace("__DIR__ . '/data/submw.sqlite'", "__DIR__ . '/submw.sqlite'", (string) file_get_contents($tt_root . '/config.php')));
$tab = static function (array $e, array $gst, array $ust = []) use ($tt_root, $gh, $gh_set): array {
    $gh_set($gst);
    set_setting('update_state', json_encode((object) $ust));
    $mw  = t_serve($tt_root, $tt_root . '/tools/tests/_router.php', ['SUBMW_DOCKER' => '1'] + $e + ['MOCKGH_DIR' => $gh], ['opcache.enable' => '0']);
    $jar = t_admin_login($mw);
    [$c, , $p] = t_http('GET', $mw . '/admin/index.php?tab=update', [], null, $jar);
    return [$c, $p];
};
[$c, $p] = $tab(['SUBMW_VERSION' => $A, 'SUBMW_REF' => 'v1.10.0'], ['latest' => $B, 'releases' => ['v1.11.0', 'v1.10.0']]);
t_ok('вкладка, релиз: открывается', $c === 200 && strpos($p, '</html>') !== false, 'код ' . $c);
t_ok('вкладка, релиз: версия и новый релиз', strpos($p, 'Установлен релиз</span> <b>v1.10.0</b>') !== false && strpos($p, 'Доступна <b>v1.11.0</b>') !== false && strpos($p, '/releases/tag/v1.11.0') !== false);
t_ok('вкладка, релиз: шапка показывает релиз', strpos($p, 'Версия <code>v1.10.0</code><span class="hbtn-dot"') !== false);
t_ok('вкладка: нет кнопки проверки и выбора ветки', strpos($p, 'value="update_check"') === false && strpos($p, 'value="update_switch_branch"') === false);
t_ok('вкладка: команда с именем сервиса submw', strpos($p, 'docker compose pull submw') !== false && strpos($p, 'remnawave-subscription-middleware</div>') === false);

[$c, $p] = $tab(['SUBMW_VERSION' => $A, 'SUBMW_REF' => 'v1.10.0'], ['latest' => $B, 'releases' => ['v1.10.0']]);
t_ok('вкладка, релиз: последняя версия', $c === 200 && strpos($p, 'Установлена последняя версия.') !== false && strpos($p, 'Доступна <b>') === false && strpos($p, 'hbtn-dot" title="Доступно обновление"') === false);

[$c, $p] = $tab(['SUBMW_VERSION' => $A, 'SUBMW_REF' => 'v1.10.0'], ['latest' => $B, 'releases' => ['v2.0.0', 'v1.10.0']]);
t_ok('вкладка, релиз: новая основная версия — подсказка без точки в меню', strpos($p, 'Вышла новая основная версия <b>v2.0.0</b>') !== false && strpos($p, 'смените тег образа на <code>:v2</code>') !== false && strpos($p, 'линейки v1.x') !== false && strpos($p, 'hbtn-dot" title="Доступно обновление"') === false);

$n = count($hits());
[$c, $p] = $tab(['SUBMW_VERSION' => $A, 'SUBMW_REF' => 'v1.10.0'], ['latest' => $B, 'releases' => ['v1.11.0']], ['img' => 'v1.10.0@' . $A, 'checked_at' => time() - 7200, 'last_err' => 'Сеть: timeout']);
t_ok('вкладка: после неудачной проверки открытие не ходит в GitHub', $c === 200 && count($hits()) === $n && strpos($p, 'Не удалось проверить: Сеть: timeout') !== false);
[$c, $p] = $tab(['SUBMW_VERSION' => $A, 'SUBMW_REF' => 'v1.10.0'], ['latest' => $B], ['img' => 'v1.10.0@' . $A, 'checked_at' => time() - 7200, 'last_err' => 'Сеть: timeout', 'latest_tag' => 'v1.11.0', 'newest_tag' => 'v1.11.0']);
t_ok('вкладка: при ошибке виден и прошлый результат, как у точки в меню', strpos($p, 'Не удалось проверить') !== false && strpos($p, 'Доступна <b>v1.11.0</b>') !== false && strpos($p, 'hbtn-dot" title="Доступно обновление"') !== false);
[$c, $p] = $tab(['SUBMW_VERSION' => $A, 'SUBMW_REF' => 'v1.10.0'], ['latest' => $B, 'releases' => ['v1.11.0']], ['img' => 'v1.10.0@' . $A, 'checked_at' => time() - 7200, 'last_err' => '', 'latest_tag' => 'v1.10.0', 'newest_tag' => 'v1.10.0']);
t_ok('вкладка: после удачной проверки открытие через час проверяет заново', $c === 200 && count($hits()) === $n + 1 && strpos($p, 'Доступна <b>v1.11.0</b>') !== false);

[$c, $p] = $tab(['SUBMW_VERSION' => $A, 'SUBMW_REF' => 'dev'], ['latest' => $B, 'files' => [['filename' => 'lib/junk.php', 'status' => 'modified', 'sha' => $B]]]);
t_ok('вкладка, ветка: коммиты и шапка', $c === 200 && strpos($p, 'Образ ветки</span> <b>dev</b>') !== false && strpos($p, 'новых коммитов: 1') !== false && strpos($p, 'Версия <code>aaaaaaa</code> (dev)') !== false);

[$c, $p] = $tab(['SUBMW_VERSION' => $A, 'SUBMW_REF' => ''], ['latest' => $B, 'releases' => ['v1.11.0']]);
t_ok('вкладка, старый образ: без проверки и без ложного обновления', $c === 200 && strpos($p, 'собран без отметки ветки или релиза') !== false && strpos($p, 'Доступна') === false && strpos($p, 'новых коммитов') === false && strpos($p, 'Версия <code>aaaaaaa</code></a>') !== false);

set_setting('go_url', 'http://submw-go:8090');
[$c, $p] = $tab(['SUBMW_VERSION' => $A, 'SUBMW_REF' => 'v1.10.0'], ['latest' => $B, 'releases' => ['v1.10.0']]);
$mwd = t_serve($tt_root, $tt_root . '/tools/tests/_router.php', ['SUBMW_DOCKER' => '1', 'SUBMW_VERSION' => $A, 'SUBMW_REF' => 'v1.10.0', 'MOCKGH_DIR' => $gh], ['opcache.enable' => '0']);
[$cs, , $ps] = t_http('GET', $mwd . '/admin/index.php?tab=sysinfo', [], null, t_admin_login($mwd));
t_ok('«О системе» в Docker: веб-сервер помечен внутренним', $cs === 200 && strpos($ps, '· внутренний</span>') !== false, 'код ' . $cs);
t_ok('вкладка, Go установлен: команда обновляет и Go', $c === 200 && strpos($p, 'docker compose pull submw submw-go') !== false && strpos($p, 'docker compose up -d submw submw-go') !== false);
$env(null, null, null);
$mw  = t_serve($tt_root, $tt_root . '/tools/tests/_router.php', ['MOCKGH_DIR' => $gh], ['opcache.enable' => '0']);
$jar = t_admin_login($mw);
[$c, , $p] = t_http('GET', $mw . '/admin/index.php?tab=update', [], null, $jar);
t_ok('вкладка без Docker, Go установлен: как обновить Go', $c === 200 && strpos($p, 'не трогает Go-сервис') !== false && strpos($p, '/opt/submw-go') !== false && strpos($p, '</html>') !== false, 'код ' . $c);
[$cs, , $ps] = t_http('GET', $mw . '/admin/index.php?tab=sysinfo', [], null, $jar);
t_ok('«О системе» без Docker: без пометки «внутренний»', $cs === 200 && strpos($ps, '· внутренний</span>') === false && strpos($ps, 'Веб-сервер') !== false, 'код ' . $cs);
set_setting('go_url', '');
t_done();
