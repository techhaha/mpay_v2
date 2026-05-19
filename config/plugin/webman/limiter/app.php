<?php

use support\limiter\RateLimitException;

return [
    'enable' => true,
    'driver' => 'redis', // auto, apcu, memory, redis
    'stores' => [
        'redis' => [
            'connection' => 'default',
        ]
    ],
    // 这些ip的请求不做频率限制
    'ip_whitelist' => [],
    'exception' => RateLimitException::class
];
