<?php

namespace app\common\utils;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class JwtUtil
{
    /**
     * 生成 JWT
     */
    public static function generateToken(array $payloadBase): string
    {
        $config = config('jwt', []);
        $secret = $config['secret'] ?? 'mpay-secret';
        $ttl = (int)($config['ttl'] ?? 7200);
        $alg = $config['alg'] ?? 'HS256';

        $now = time();
        $payload = array_merge($payloadBase, [
            'iat' => $now,
            'exp' => $now + $ttl,
        ]);

        return JWT::encode($payload, $secret, $alg);
    }

    /**
     * 解析 JWT
     */
    public static function parseToken(string $token): array
    {
        $config = config('jwt', []);
        $secret = $config['secret'] ?? 'mpay-secret';
        $alg = $config['alg'] ?? 'HS256';

        $decoded = JWT::decode($token, new Key($secret, $alg));
        return json_decode(json_encode($decoded, JSON_UNESCAPED_UNICODE), true) ?: [];
    }

    /**
     * 获取 ttl（秒）
     */
    public static function getTtl(): int
    {
        $config = config('jwt', []);
        return (int)($config['ttl'] ?? 7200);
    }

    /**
     * 获取缓存前缀
     */
    public static function getCachePrefix(): string
    {
        $config = config('jwt', []);
        return $config['cache_prefix'] ?? 'token_';
    }
}


