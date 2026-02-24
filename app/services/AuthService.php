<?php

namespace app\services;

use app\common\base\BaseService;
use app\common\utils\JwtUtil;
use app\exceptions\{BadRequestException, ForbiddenException, UnauthorizedException};
use app\repositories\UserRepository;
use support\Cache;

/**
 * 认证服务
 *
 * 处理登录、token 生成等认证相关业务
 */
class AuthService extends BaseService
{
    public function __construct(
        protected UserRepository $userRepository,
        protected CaptchaService $captchaService
    ) {
    }

    /**
     * 用户登录
     *
     * 登录成功后返回 token，前端使用该 token 通过 Authorization 请求头访问需要认证的接口
     *
     * @param string $username 用户名
     * @param string $password 密码
     * @param string $verifyCode 验证码
     * @param string $captchaId 验证码ID
     * @return array ['token' => string]
     */
    public function login(string $username, string $password, string $verifyCode, string $captchaId): array
    {
        // 1. 校验验证码
        if (!$this->captchaService->validate($captchaId, $verifyCode)) {
            throw new BadRequestException('验证码错误或已失效');
        }

        // 2. 查询用户
        $user = $this->userRepository->findByUserName($username);
        if (!$user) {
            throw new UnauthorizedException('账号或密码错误');
        }

        // 3. 校验密码
        if (!$this->validatePassword($password, $user->password)) {
            throw new UnauthorizedException('账号或密码错误');
        }

        // 4. 检查用户状态
        if ($user->status !== 1) {
            throw new ForbiddenException('账号已被禁用');
        }

        // 5. 生成 JWT token（包含用户ID、用户名、昵称等信息）
        $token = $this->generateToken($user);

        // 6. 将 token 信息存入 Redis（用于后续刷新、黑名单等）
        $this->cacheToken($token, $user->id);

        // 7. 更新用户最后登录信息
        $this->updateLoginInfo($user);

        // 返回 token，前端使用该 token 访问需要认证的接口
        return [
            'token' => $token,
        ];
    }

    /**
     * 校验密码
     *
     * @param string $password 明文密码
     * @param string|null $hash 数据库中的密码hash
     * @return bool
     */
    private function validatePassword(string $password, ?string $hash): bool
    {
        // 如果数据库密码为空，允许使用默认密码（仅用于开发/演示）
        if ($hash === null || $hash === '') {
            // 开发环境：允许 admin/123456 和 common/123456 无密码登录
            // 生产环境应移除此逻辑
            return in_array($password, ['123456'], true);
        }

        return password_verify($password, $hash);
    }

    /**
     * 生成 JWT token
     *
     * @param \app\models\User $user
     * @return string
     */
    private function generateToken($user): string
    {
        $payload = [
            'user_id' => $user->id,
            'user_name' => $user->user_name,
            'nick_name' => $user->nick_name,
        ];

        return JwtUtil::generateToken($payload);
    }

    /**
     * 将 token 信息缓存到 Redis
     *
     * @param string $token
     * @param int $userId
     */
    private function cacheToken(string $token, int $userId): void
    {
        $key = JwtUtil::getCachePrefix() . $token;
        $data = [
            'user_id' => $userId,
            'created_at' => time(),
        ];
        Cache::set($key, $data, JwtUtil::getTtl());
    }

    /**
     * 更新用户登录信息
     *
     * @param \app\models\User $user
     */
    private function updateLoginInfo($user): void
    {
        // 获取客户端真实IP（优先使用 x-real-ip，其次 x-forwarded-for，最后 remoteIp）
        $request = request();
        $ip = $request->header('x-real-ip', '') 
            ?: ($request->header('x-forwarded-for', '') ? explode(',', $request->header('x-forwarded-for', ''))[0] : '')
            ?: $request->getRemoteIp();
        
        $user->login_ip = trim($ip);
        $user->login_at = date('Y-m-d H:i:s');
        $user->save();
    }
}

