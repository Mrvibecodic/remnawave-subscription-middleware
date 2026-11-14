<?php
// Тот же роутер, но админка считает, что её открыли в приложении (через туннель a1).
define('SUBMW_APP_TUNNEL', 1);
return require __DIR__ . '/_router.php';
