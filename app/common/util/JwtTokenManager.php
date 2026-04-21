<?php

namespace app\common\util;

use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;
use support\Redis;
use Throwable;

/**
 * JWT 工具类，负责签发、验证和撤销登录态。
 *
 * 设计说明：
 * - JWT 只负责承载身份声明，不保存业务权限细节。
 * - Redis 保存会话态，支持主动注销、过期控制和最近访问时间更新。
 * - guard 用于区分不同登录域，例如管理员和商户。
 * - Redis Key 推荐由配置中的前缀 + jti 组成，例如：
 *   `mpay:auth:admin:{jti}`、`mpay:auth:merchant:{jti}`。
 */
class JwtTokenManager
{
    /**
     * 签发 JWT，并把会话态写入 Redis。
     *
     * @param string $guard 登录域
     * @param array<string, mixed> $claims JWT 声明
     * @param array<string, mixed> $sessionData 会话数据
     * @param int|null $ttlSeconds 过期秒数
     * @return array{token:string,expires_in:int,jti:string,claims:array<string, mixed>,session:array<string, mixed>} 签发结果
     */
    public function issue(string $guard, array $claims, array $sessionData, ?int $ttlSeconds = null): array
    {
        $guardConfig = $this->guardConfig($guard);
        $this->assertHmacSecretLength($guard, (string) $guardConfig['secret']);
        $ttlSeconds = max(60, $ttlSeconds ?? (int) $guardConfig['ttl']);

        $now = time();
        $jti = bin2hex(random_bytes(16));
        $payload = array_merge([
            'iss' => (string) config('auth.issuer', 'mpay'),
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + $ttlSeconds,
            'jti' => $jti,
            'guard' => $guard,
        ], $claims);

        $token = JWT::encode($payload, (string) $guardConfig['secret'], 'HS256');

        $session = array_merge($sessionData, [
            'guard' => $guard,
            'jti' => $jti,
            'issued_at' => FormatHelper::timestamp($now),
            'expires_at' => FormatHelper::timestamp($now + $ttlSeconds),
        ]);

        $this->storeSession($guard, $jti, $session, $ttlSeconds);

        return [
            'token' => $token,
            'expires_in' => $ttlSeconds,
            'jti' => $jti,
            'claims' => $payload,
            'session' => $session,
        ];
    }

    /**
     * 验证 JWT，并恢复对应的 Redis 会话数据。
     *
     * 说明：
     * - 先校验签名和过期时间。
     * - 再通过 jti 反查 Redis 会话，确保 token 仍然有效。
     * - 每次命中会刷新最近访问时间。
     *
     * @param string $guard 登录域
     * @param string $token JWT 字符串
     * @param string $ip 最近访问 IP
     * @param string $userAgent 用户Agent
     * @return array{claims:array<string, mixed>,session:array<string, mixed>}|null 验证结果
     */
    public function verify(string $guard, string $token, string $ip = '', string $userAgent = ''): ?array
    {
        $payload = $this->decode($guard, $token);
        if ($payload === null) {
            return null;
        }

        $jti = (string) ($payload['jti'] ?? '');
        if ($jti === '') {
            return null;
        }

        $session = $this->session($guard, $jti);
        if ($session === null) {
            return null;
        }

        $now = time();
        $expiresAt = (int) ($payload['exp'] ?? 0);
        $ttl = max(1, $expiresAt - $now);

        $session['last_used_at'] = FormatHelper::timestamp($now);
        if ($ip !== '') {
            $session['last_used_ip'] = $ip;
        }
        if ($userAgent !== '') {
            $session['user_agent'] = $userAgent;
        }

        $this->storeSession($guard, $jti, $session, $ttl);

        return [
            'claims' => $payload,
            'session' => $session,
        ];
    }

    /**
     * 通过 token 撤销登录态。
     *
     * 适用于主动退出登录场景。
     *
     * @param string $guard 登录域
     * @param string $token JWT 字符串
     * @return bool 是否已撤销
     */
    public function revoke(string $guard, string $token): bool
    {
        $payload = $this->decode($guard, $token);
        if ($payload === null) {
            return false;
        }

        $jti = (string) ($payload['jti'] ?? '');
        if ($jti === '') {
            return false;
        }

        return (bool) Redis::connection()->del($this->sessionKey($guard, $jti));
    }

    /**
     * 通过 jti 直接撤销登录态。
     *
     * 适用于已经掌握会话标识但没有原始 token 的补偿清理场景。
     *
     * @param string $guard 登录域
     * @param string $jti 会话标识
     * @return bool 是否已撤销
     */
    public function revokeByJti(string $guard, string $jti): bool
    {
        if ($jti === '') {
            return false;
        }

        return (bool) Redis::connection()->del($this->sessionKey($guard, $jti));
    }

    /**
     * 根据 jti 获取会话数据。
     *
     * 返回值来自 Redis，若已过期或不存在则返回 null。
     *
     * @param string $guard 登录域
     * @param string $jti 会话标识
     * @return array<string, mixed>|null 会话数据
     */
    public function session(string $guard, string $jti): ?array
    {
        $raw = Redis::connection()->get($this->sessionKey($guard, $jti));
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $session = json_decode($raw, true);
        return is_array($session) ? $session : null;
    }

    /**
     * 解码并校验 JWT。
     *
     * 只做签名、过期和 guard 校验，不处理 Redis 会话。
     *
     * @param string $guard 登录域
     * @param string $token JWT 字符串
     * @return array<string, mixed>|null JWT 载荷
     */
    protected function decode(string $guard, string $token): ?array
    {
        $guardConfig = $this->guardConfig($guard);
        $this->assertHmacSecretLength($guard, (string) $guardConfig['secret']);

        try {
            JWT::$leeway = (int) config('auth.leeway', 30);
            $payload = JWT::decode($token, new Key((string) $guardConfig['secret'], 'HS256'));
        } catch (ExpiredException|SignatureInvalidException|Throwable) {
            return null;
        }

        $data = json_decode(json_encode($payload, JSON_UNESCAPED_UNICODE), true);
        if (!is_array($data)) {
            return null;
        }

        if (($data['guard'] ?? '') !== $guard) {
            return null;
        }

        return $data;
    }

    /**
     * 将会话数据写入 Redis，并设置 TTL。
     *
     * @param string $guard 登录域
     * @param string $jti 会话标识
     * @param array<string, mixed> $session 会话数据
     * @param int $ttlSeconds 过期秒数
     * @return void
     */
    protected function storeSession(string $guard, string $jti, array $session, int $ttlSeconds): void
    {
        Redis::connection()->setEx(
            $this->sessionKey($guard, $jti),
            max(60, $ttlSeconds),
            json_encode($session, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * 构造 Redis 会话键。
     *
     * 最终格式由 guard 对应的 redis_prefix 加上 jti 组成。
     *
     * @param string $guard 登录域
     * @param string $jti 会话标识
     * @return string Redis 会话键
     */
    protected function sessionKey(string $guard, string $jti): string
    {
        return $this->guardConfig($guard)['redis_prefix'] . $jti;
    }

    /**
     * 获取指定认证域的配置。
     *
     * @param string $guard 登录域
     * @return array<string, mixed> 认证配置
     * @throws \InvalidArgumentException
     */
    protected function guardConfig(string $guard): array
    {
        $guards = (array) config('auth.guards', []);
        if (!isset($guards[$guard])) {
            throw new \InvalidArgumentException("Unknown auth guard: {$guard}");
        }

        return $guards[$guard];
    }

    /**
     * 校验 HS256 密钥长度，避免 firebase/php-jwt 抛出底层异常。
     *
     * @param string $guard 登录域
     * @param string $secret 密钥
     * @return void
     * @throws RuntimeException
     */
    protected function assertHmacSecretLength(string $guard, string $secret): void
    {
        if (strlen($secret) >= 32) {
            return;
        }

        $envNames = match ($guard) {
            'admin' => 'AUTH_ADMIN_JWT_SECRET or AUTH_JWT_SECRET',
            'merchant' => 'AUTH_MERCHANT_JWT_SECRET or AUTH_JWT_SECRET',
            default => 'the configured JWT secret',
        };

        throw new \RuntimeException(sprintf(
            'JWT secret for guard "%s" is too short for HS256. Please set %s to at least 32 ASCII characters.',
            $guard,
            $envNames
        ));
    }
}


