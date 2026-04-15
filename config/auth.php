<?php

/**
 * 认证配置。
 *
 * 说明：
 * - JWT 负责身份声明。
 * - Redis 负责会话态存储。
 * - Redis Key 推荐格式：
 *   - `mpay:auth:admin:{jti}`
 *   - `mpay:auth:merchant:{jti}`
 */
return [
    'issuer' => env('AUTH_JWT_ISSUER', 'mpay'),
    'leeway' => (int) env('AUTH_JWT_LEEWAY', 30),
    'guards' => [
        'admin' => [
            'secret' => env('AUTH_ADMIN_JWT_SECRET', env('AUTH_JWT_SECRET', 'change-me-jwt-secret-use-at-least-32-chars')),
            'ttl' => (int) env('AUTH_ADMIN_JWT_TTL', 86400),
            'redis_prefix' => env('AUTH_ADMIN_JWT_REDIS_PREFIX', 'mpay:auth:admin:'),
        ],
        'merchant' => [
            'secret' => env('AUTH_MERCHANT_JWT_SECRET', env('AUTH_JWT_SECRET', 'change-me-jwt-secret-use-at-least-32-chars')),
            'ttl' => (int) env('AUTH_MERCHANT_JWT_TTL', 86400),
            'redis_prefix' => env('AUTH_MERCHANT_JWT_REDIS_PREFIX', 'mpay:auth:merchant:'),
        ],
    ],
];
