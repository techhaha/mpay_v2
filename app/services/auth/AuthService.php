<?php

namespace app\services\auth;

use app\common\base\BaseService;
use app\exceptions\AuthFailedException;
use app\exceptions\UnauthorizedException;
use app\repositories\AdminUserRepository;
use support\Redis;

class AuthService extends BaseService
{
    public function __construct(
        protected AdminUserRepository $userRepository
    ) {
    }

    /**
     * 登录，返回 JWT token
     */
    public function login(string $username, string $password, $verifyCode = null): string
    {
        $user = $this->userRepository->findByUsername($username);
        if (!$user) {
            throw new AuthFailedException();
        }

        // 当前阶段使用明文模拟密码校验
        if ($password !== '123456') {
            throw new AuthFailedException();
        }

        $payload = [
            'uid' => $user['id'],
            'username' => $user['userName'],
            'roles' => $user['roles'] ?? [],
        ];

        $token = JwtService::generateToken($payload);

        // 写入 Redis 会话，key 使用 token，方便快速失效
        $ttl = JwtService::getTtl();
        $key = 'mpay:auth:token:' . $token;
        $redis = Redis::connection('default');
        $redis->setex($key, $ttl, json_encode($payload, JSON_UNESCAPED_UNICODE));

        return $token;
    }

    /**
     * 根据 token 获取用户信息（对齐前端 mock 返回结构）
     */
    public function getUserInfo(string $token, $id = null): array
    {
        if ($token === '') {
            throw new UnauthorizedException();
        }

        $redis = Redis::connection('default');
        $key = 'mpay:auth:token:' . $token;
        $session = $redis->get($key);
        if (!$session) {
            // 尝试从 JWT 解出（例如服务重启后 Redis 丢失的情况）
            $payload = JwtService::parseToken($token);
        } else {
            $payload = json_decode($session, true) ?: [];
        }

        if (empty($payload['uid']) && empty($payload['username'])) {
            throw new UnauthorizedException();
        }

        // 对齐 mock：如果有 id 参数则按 id 查，否则用 payload 中 uid 查
        if ($id !== null && $id !== '') {
            $user = $this->userRepository->findById((int)$id);
        } else {
            $user = $this->userRepository->findById((int)($payload['uid'] ?? 0));
        }

        if (!$user) {
            throw new UnauthorizedException();
        }

        // 角色信息
        $roleInfo = $this->userRepository->getRoleInfoByCodes($user['roles'] ?? []);
        $user['roles'] = $roleInfo;
        $roleCodes = array_map(static fn($item) => $item['code'], $roleInfo);

        // 权限信息
        if (in_array('admin', $roleCodes, true)) {
            $permissions = ['*:*:*'];
        } else {
            $permissions = $this->userRepository->getPermissionsByRoleCodes($roleCodes);
        }

        return [
            'user' => $user,
            'roles' => $roleCodes,
            'permissions' => $permissions,
        ];
    }
}


