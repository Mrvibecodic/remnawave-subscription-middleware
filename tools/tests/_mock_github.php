<?php
// Мок GitHub для тестов апдейтера: /api/repos/<repo>/commits|compare|releases и /raw/<repo>/<sha>/<путь>.
if (PHP_SAPI !== 'cli-server') { http_response_code(404); exit; }
$dir   = (string) getenv('MOCKGH_DIR');
$state = json_decode((string) @file_get_contents($dir . '/state.json'), true) ?: [];
$path  = rawurldecode((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH));
file_put_contents($dir . '/hits.log', $path . "\n", FILE_APPEND);

if (preg_match('~^/api/repos/[^/]+/[^/]+/releases$~', $path)) {
    if (!isset($state['releases'])) { http_response_code(500); exit; }
    header('content-type: application/json');
    echo json_encode(array_map(static fn($t) => ['tag_name' => ltrim($t, '~'), 'prerelease' => $t[0] === '~', 'draft' => false], $state['releases']));
    exit;
}

if (preg_match('~^/api/repos/[^/]+/[^/]+/commits/~', $path)) {
    header('content-type: application/json');
    echo json_encode(['sha' => $state['latest'], 'commit' => ['message' => 'тестовая версия', 'committer' => ['date' => '2026-10-16T00:00:00Z']]]);
    exit;
}
if (preg_match('~^/api/repos/[^/]+/[^/]+/compare/([0-9a-f]{40})\.\.\.([0-9a-f]{40})$~', $path, $m)) {
    header('content-type: application/json');
    if ($m[1] === $m[2]) { echo json_encode(['ahead_by' => 0, 'total_commits' => 0, 'commits' => [], 'files' => []]); exit; }
    echo json_encode(['ahead_by' => 1, 'total_commits' => 1, 'commits' => [['sha' => $m[2], 'commit' => ['message' => 'тестовая версия']]], 'files' => $state['files'] ?? []]);
    exit;
}
if (preg_match('~^/raw/[^/]+/[^/]+/[0-9a-f]{40}/(.+)$~', $path, $m) && strpos($m[1], '..') === false && is_file($dir . '/tree/' . $m[1])) {
    readfile($dir . '/tree/' . $m[1]);
    exit;
}
http_response_code(404);
echo '{"message":"Not Found"}';
