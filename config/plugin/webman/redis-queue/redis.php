<?php
return [
    'default' => [
        'host' => 'redis://' . env('REDIS_HOST', '127.0.0.1') . ':' . env('REDIS_PORT', 6379),
        'options' => [
            'auth' => env('REDIS_PASSWORD', ''),
            'db' => env('QUEUE_REDIS_DATABASE', 0),
            'prefix' => env('QUEUE_REDIS_PREFIX', 'ma:queue:'),
            'max_attempts'  => 5,
            'retry_seconds' => 5,
        ],
        // Connection pool, supports only Swoole or Swow drivers.
        'pool' => [
            'max_connections' => 5,
            'min_connections' => 1,
            'wait_timeout' => 3,
            'idle_timeout' => 60,
            'heartbeat_interval' => 50,
        ]
    ],
];
