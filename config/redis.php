<?php
/**
 * MPay V2 支付系统 - Redis配置
 * Redis 6.0+ 缓存和队列配置
 */

return [
    // 默认Redis连接
    'default' => [
        'host' => env('REDIS_HOST', '127.0.0.1'),
        'password' => env('REDIS_PASSWORD', ''),
        'port' => env('REDIS_PORT', 6379),
        'database' => env('REDIS_DATABASE', 0),
        'pool' => [
            'max_connections' => env('REDIS_POOL_MAX_CONNECTIONS', 20),
            'min_connections' => env('REDIS_POOL_MIN_CONNECTIONS', 5),
            'wait_timeout' => env('REDIS_POOL_WAIT_TIMEOUT', 3),
            'idle_timeout' => env('REDIS_POOL_IDLE_TIMEOUT', 60),
            'heartbeat_interval' => env('REDIS_POOL_HEARTBEAT_INTERVAL', 50),
        ],
        'options' => [
            'prefix' => env('CACHE_PREFIX', 'mpay_v2_'),
        ],
    ],
    
    // 缓存专用Redis连接
    'cache' => [
        'host' => env('REDIS_CACHE_HOST', env('REDIS_HOST', '127.0.0.1')),
        'password' => env('REDIS_CACHE_PASSWORD', env('REDIS_PASSWORD', '')),
        'port' => env('REDIS_CACHE_PORT', env('REDIS_PORT', 6379)),
        'database' => env('REDIS_CACHE_DATABASE', 1),
        'pool' => [
            'max_connections' => 15,
            'min_connections' => 3,
            'wait_timeout' => 3,
            'idle_timeout' => 60,
            'heartbeat_interval' => 50,
        ],
        'options' => [
            'prefix' => env('CACHE_PREFIX', 'mpay_v2_cache_'),
        ],
    ],
    
    // 队列专用Redis连接
    'queue' => [
        'host' => env('REDIS_QUEUE_HOST', env('REDIS_HOST', '127.0.0.1')),
        'password' => env('REDIS_QUEUE_PASSWORD', env('REDIS_PASSWORD', '')),
        'port' => env('REDIS_QUEUE_PORT', env('REDIS_PORT', 6379)),
        'database' => env('REDIS_QUEUE_DATABASE', 2),
        'pool' => [
            'max_connections' => 10,
            'min_connections' => 2,
            'wait_timeout' => 3,
            'idle_timeout' => 60,
            'heartbeat_interval' => 50,
        ],
        'options' => [
            'prefix' => env('QUEUE_PREFIX', 'mpay_v2_queue_'),
        ],
    ],
    
    // 会话存储Redis连接
    'session' => [
        'host' => env('REDIS_SESSION_HOST', env('REDIS_HOST', '127.0.0.1')),
        'password' => env('REDIS_SESSION_PASSWORD', env('REDIS_PASSWORD', '')),
        'port' => env('REDIS_SESSION_PORT', env('REDIS_PORT', 6379)),
        'database' => env('REDIS_SESSION_DATABASE', 3),
        'pool' => [
            'max_connections' => 10,
            'min_connections' => 2,
            'wait_timeout' => 3,
            'idle_timeout' => 60,
            'heartbeat_interval' => 50,
        ],
        'options' => [
            'prefix' => 'mpay_v2_session_',
        ],
    ],
    
    // JWT黑名单Redis连接
    'jwt_blacklist' => [
        'host' => env('REDIS_JWT_HOST', env('REDIS_HOST', '127.0.0.1')),
        'password' => env('REDIS_JWT_PASSWORD', env('REDIS_PASSWORD', '')),
        'port' => env('REDIS_JWT_PORT', env('REDIS_PORT', 6379)),
        'database' => env('REDIS_JWT_DATABASE', 4),
        'pool' => [
            'max_connections' => 5,
            'min_connections' => 1,
            'wait_timeout' => 3,
            'idle_timeout' => 60,
            'heartbeat_interval' => 50,
        ],
        'options' => [
            'prefix' => 'mpay_v2_jwt_blacklist_',
        ],
    ],
];
