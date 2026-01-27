<?php

/**
 * MPay V2 支付系统 - Redis配置
 * Redis 6.0+ 缓存和队列配置
 */

return [
    // 默认Redis连接
    'default' => [
        'host' => getenv('REDIS_HOST') ?? '127.0.0.1',
        'password' => getenv('REDIS_PASSWORD') ?? '',
        'port' => getenv('REDIS_PORT') ?? 6379,
        'database' => getenv('REDIS_DATABASE') ?? 0,
        'pool' => [
            'max_connections' => 20,
            'min_connections' => 5,
            'wait_timeout' => 3,
            'idle_timeout' => 60,
            'heartbeat_interval' => 50,
        ],
    ],
];
