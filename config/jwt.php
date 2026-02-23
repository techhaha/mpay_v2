<?php

/**
 * JWT 配置
 */
return [
    // JWT 密钥（请在生产环境修改为强随机字符串）
    'secret' => env('JWT_SECRET', 'mpay-admin-secret-key-change-in-production'),
    
    // Token 有效期（秒），默认 2 小时
    'ttl' => (int)env('JWT_TTL', 7200),
    
    // 加密算法
    'alg' => env('JWT_ALG', 'HS256'),
    
    // Token 缓存前缀（用于 Redis 存储）
    'cache_prefix' => env('JWT_CACHE_PREFIX', 'token_'),
];

