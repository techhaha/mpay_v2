<?php

return [
    // JWT 密钥，生产环境建议从 .env 读取
    'secret' => getenv('JWT_SECRET') ?: 'mpay-secret',
    // 过期时间（秒）
    'ttl' => (int)(getenv('JWT_TTL') ?: 7200),
    // 签名算法
    'alg' => getenv('JWT_ALG') ?: 'HS256',
];


