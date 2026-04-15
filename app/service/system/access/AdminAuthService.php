<?php

namespace app\service\system\access;

use app\common\base\BaseService;
use app\common\constant\AuthConstant;
use app\common\constant\CommonConstant;
use app\common\util\JwtTokenManager;
use app\exception\ValidationException;
use app\model\admin\AdminUser;
use app\repository\system\user\AdminUserRepository;

/**
 * 管理员认证服务。
 *
 * 负责管理员账号校验、JWT 签发、登录态校验和主动注销。
 */
class AdminAuthService extends BaseService
{
    /**
     * 构造函数，注入对应依赖。
     */
    public function __construct(
        protected AdminUserRepository $adminUserRepository,
        protected JwtTokenManager $jwtTokenManager
    ) {
    }

    /**
     * 校验中间件传入的管理员登录 token。
     */
    public function authenticateToken(string $token, string $ip = '', string $userAgent = ''): ?AdminUser
    {
        $result = $this->jwtTokenManager->verify('admin', $token, $ip, $userAgent);
        if ($result === null) {
            return null;
        }

        $adminId = (int) ($result['session']['admin_id'] ?? $result['claims']['sub'] ?? 0);
        if ($adminId <= 0) {
            return null;
        }

        /** @var AdminUser|null $admin */
        $admin = $this->adminUserRepository->find($adminId);
        if (!$admin || (int) $admin->status !== CommonConstant::STATUS_ENABLED) {
            return null;
        }

        return $admin;
    }

    /**
     * 校验管理员账号密码并签发 JWT。
     */
    public function authenticateCredentials(string $username, string $password, string $ip = '', string $userAgent = ''): array
    {
        $admin = $this->adminUserRepository->findByUsername($username);
        if (!$admin || (int) $admin->status !== CommonConstant::STATUS_ENABLED) {
            throw new ValidationException('管理员账号或密码错误');
        }

        if (!password_verify($password, (string) $admin->password_hash)) {
            throw new ValidationException('管理员账号或密码错误');
        }

        $admin->last_login_at = $this->now();
        $admin->last_login_ip = $ip;
        $admin->save();

        return $this->issueToken((int) $admin->id, 86400, $ip, $userAgent);
    }

    /**
     * 撤销当前管理员登录 token。
     */
    public function revokeToken(string $token): bool
    {
        return $this->jwtTokenManager->revoke('admin', $token);
    }

    /**
     * 签发新的管理员登录 token。
     */
    public function issueToken(int $adminId, int $ttlSeconds = 86400, string $ip = '', string $userAgent = ''): array
    {
        /** @var AdminUser|null $admin */
        $admin = $this->adminUserRepository->find($adminId);
        if (!$admin) {
            throw new ValidationException('管理员不存在');
        }

        $issued = $this->jwtTokenManager->issue('admin', [
            'sub' => (string) $adminId,
            'admin_id' => $adminId,
            'username' => (string) $admin->username,
            'is_super' => (int) $admin->is_super,
        ], [
            'admin_id' => $adminId,
            'admin_username' => (string) $admin->username,
            'real_name' => (string) $admin->real_name,
            'is_super' => (int) $admin->is_super,
            'last_login_ip' => $ip,
            'user_agent' => $userAgent,
        ], $ttlSeconds);

        return [
            'token' => $issued['token'],
            'expires_in' => $issued['expires_in'],
            'admin' => $admin,
        ];
    }
}
