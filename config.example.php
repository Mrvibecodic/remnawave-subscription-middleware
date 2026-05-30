<?php

return [
    'installed' => true,

    'db' => [
        'driver' => 'sqlite',
        'path'   => __DIR__ . '/data/submw.sqlite',
    ],

    'admin_user'      => 'admin',
    'admin_pass_hash' => '$2y$10$REPLACE_WITH_REAL_HASH',

    'admin_cookie_secret' => 'CHANGE_ME_LONG_RANDOM_STRING',
];
