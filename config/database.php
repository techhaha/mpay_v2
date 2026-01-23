<?php
/**
 * MPay V2 支付系统 - 数据库配置
 * 基于webman标准配置格式，支持MySQL 5.7+
 * 
 * 配置说明：
 * - 字符集：utf8mb4（支持完整的UTF-8字符集，包括emoji）
 * - 排序规则：utf8mb4_unicode_ci（Unicode标准排序）
 * - 连接池：支持连接池管理，提高性能（仅支持swoole/swow驱动）
 * - 读写分离：支持主从数据库配置
 */

return [
    // 默认数据库连接
    'default' => env('DB_CONNECTION', 'mysql'),
    
    // 数据库连接配置
    'connections' => [
        // 主数据库配置
        'mysql' => [
            'driver' => 'mysql',
            'host' => env('DB_HOST', '192.168.31.200'),
            'port' => env('DB_PORT', 3306),
            'database' => env('DB_DATABASE', 'mpay_v2'),
            'username' => env('DB_USERNAME', 'mpay_v2'),
            'password' => env('DB_PASSWORD', 'pXfNWELALrwAAt88'),
            'unix_socket' => '',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => env('DB_PREFIX', ''),
            'strict' => true,
            'engine' => 'InnoDB',
            'pool' => [
                'max_connections' => env('DB_POOL_MAX_CONNECTIONS', 20),
                'min_connections' => env('DB_POOL_MIN_CONNECTIONS', 3),
                'wait_timeout' => env('DB_POOL_WAIT_TIMEOUT', 3),
                'idle_timeout' => env('DB_POOL_IDLE_TIMEOUT', 60),
                'heartbeat_interval' => env('DB_POOL_HEARTBEAT_INTERVAL', 50),
            ],
        ],
        
        // 读库配置（主从分离）
        'mysql_read' => [
            'driver' => 'mysql',
            'host' => env('DB_READ_HOST', env('DB_HOST', '192.168.31.200')),
            'port' => env('DB_READ_PORT', env('DB_PORT', 3306)),
            'database' => env('DB_READ_DATABASE', env('DB_DATABASE', 'mpay_v2')),
            'username' => env('DB_READ_USERNAME', env('DB_USERNAME', 'mpay_v2')),
            'password' => env('DB_READ_PASSWORD', env('DB_PASSWORD', 'pXfNWELALrwAAt88')),
            'unix_socket' => '',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => env('DB_PREFIX', ''),
            'strict' => true,
            'engine' => 'InnoDB',
            'pool' => [
                'max_connections' => 15,
                'min_connections' => 2,
                'wait_timeout' => 3,
                'idle_timeout' => 60,
                'heartbeat_interval' => 50,
            ],
        ],
        
        // 写库配置（主从分离）
        'mysql_write' => [
            'driver' => 'mysql',
            'host' => env('DB_WRITE_HOST', env('DB_HOST', '192.168.31.200')),
            'port' => env('DB_WRITE_PORT', env('DB_PORT', 3306)),
            'database' => env('DB_WRITE_DATABASE', env('DB_DATABASE', 'mpay_v2')),
            'username' => env('DB_WRITE_USERNAME', env('DB_USERNAME', 'mpay_v2')),
            'password' => env('DB_WRITE_PASSWORD', env('DB_PASSWORD', 'pXfNWELALrwAAt88')),
            'unix_socket' => '',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => env('DB_PREFIX', ''),
            'strict' => true,
            'engine' => 'InnoDB',
            'pool' => [
                'max_connections' => 10,
                'min_connections' => 2,
                'wait_timeout' => 3,
                'idle_timeout' => 60,
                'heartbeat_interval' => 50,
            ],
        ],
    ],
];