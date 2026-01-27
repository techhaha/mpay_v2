<?php
return  [
    'default' => getenv('DB_CONNECTION') ?? 'mysql',
    'connections' => [
        'mysql' => [
            'driver'      => getenv('DB_DRIVER') ?? 'mysql',
            'host'        => getenv('DB_HOST') ?? '127.0.0.1',
            'port'        => getenv('DB_PORT') ?? '3306',
            'database'    => getenv('DB_DATABASE') ?? '',
            'username'    => getenv('DB_USERNAME') ?? '',
            'password'    => getenv('DB_PASSWORD') ?? '',
            'charset'     => 'utf8mb4',
            'collation'   => 'utf8mb4_general_ci',
            'prefix'      => getenv('DB_PREFIX') ?? '',
            'strict'      => true,
            'engine'      => null,
            'options'   => [
                PDO::ATTR_EMULATE_PREPARES => false, // Must be false for Swoole and Swow drivers.
            ],
            'pool' => [
                'max_connections' => 5,
                'min_connections' => 1,
                'wait_timeout' => 3,
                'idle_timeout' => 60,
                'heartbeat_interval' => 50,
            ],
        ],
    ],
];