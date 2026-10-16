<?php
// Роутер для php -S в тестах — как try_files у nginx: файл отдаётся сам, остальное идёт в index.php.
if (PHP_SAPI !== 'cli-server') { http_response_code(404); exit; }
$root = dirname(__DIR__, 2);
$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
if ($path !== '/' && strpos($path, '..') === false && (is_file($root . $path) || is_dir($root . $path))) return false;
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $root . '/index.php';
require $root . '/index.php';
