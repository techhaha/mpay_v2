<?php

namespace app\service\merchant\auth;

use app\common\base\BaseService;
use app\common\constant\CommonConstant;
use app\common\util\JwtTokenManager;
use app\exception\ValidationException;
use app\model\merchant\Merchant;
use app\repository\merchant\credential\MerchantApiCredentialRepository;
use app\repository\merchant\base\MerchantRepository;
use app\service\merchant\portal\MerchantPortalSupportService;

/**
 * 商户认证服务。
 *
 * @property MerchantRepository $merchantRepository 商户仓库
 * @property MerchantApiCredentialRepository $merchantApiCredentialRepository 商户 API 凭证仓库
 * @property MerchantPortalSupportService $merchantPortalSupportService 商户门户支持服务
 * @property JwtTokenManager $jwtTokenManager jwtToken管理器
 */
class MerchantAuthService extends BaseService
{
    /**
     * 构造方法。
     *
     * @param MerchantRepository $merchantRepository 商户仓库
     * @param MerchantApiCredentialRepository $merchantApiCredentialRepository 商户 API 凭证仓库
     * @param MerchantPortalSupportService $merchantPortalSupportService 商户门户支持服务
     * @param JwtTokenManager $jwtTokenManager jwtToken管理器
     * @return void
     */
    public function __construct(
        protected MerchantRepository $merchantRepository,
        protected MerchantApiCredentialRepository $merchantApiCredentialRepository,
        protected MerchantPortalSupportService $merchantPortalSupportService,
        protected JwtTokenManager $jwtTokenManager
    ) {
    }

    /**
     * 获取当前登录商户的资料。
     *
     * @param int $merchantId 商户ID
     * @param string $merchantNo 商户号
     * @return array{merchant_id: int, merchant_no: string, merchant: array<string, mixed>, user: array<string, mixed>, roles: array<int, string>, permissions: array<int, string>} 商户资料
     */
    public function profile(int $merchantId, string $merchantNo = ''): array
    {
        $merchant = $this->merchantPortalSupportService->merchantSummary($merchantId);
        $credential = $merchantId > 0 ? $this->merchantApiCredentialRepository->findByMerchantId($merchantId) : null;

        $isCredentialEnabled = (int) ($credential->status ?? 0) === 1;
        $user = [
            'id' => $merchantId,
            'deptId' => (string) ($merchant['merchant_group_id'] ?? 0),
            'deptName' => (string) ($merchant['merchant_group_name'] ?? '未分组'),
            'userName' => (string) ($merchant['merchant_no'] !== '' ? $merchant['merchant_no'] : trim($merchantNo)),
            'nickName' => (string) ($merchant['merchant_name'] ?? '商户账号'),
            'email' => (string) ($merchant['contact_email'] ?? ''),
            'phone' => (string) ($merchant['contact_phone'] ?? ''),
            'sex' => 2,
            'avatar' => '',
            'status' => (int) ($merchant['status'] ?? 1),
            'description' => '商户主体账号（商户号 + 密码）',
            'roles' => [
                [
                    'code' => 'common',
                    'name' => '普通用户',
                    'admin' => false,
                    'disabled' => false,
                ],
            ],
            'loginIp' => (string) ($merchant['last_login_ip'] ?? ''),
            'loginDate' => (string) ($merchant['last_login_at'] ?? ''),
            'createBy' => '系统',
            'createTime' => (string) ($merchant['created_at'] ?? ''),
            'updateBy' => null,
            'updateTime' => (string) ($merchant['updated_at'] ?? ''),
            'admin' => false,
            'credential_status' => (int) ($credential->status ?? 0),
            'credential_status_text' => $isCredentialEnabled ? '已开通' : '未开通',
            'credential_last_used_at' => (string) ($credential->last_used_at ?? ''),
            'password_updated_at' => (string) ($merchant['password_updated_at'] ?? ''),
        ];

        return [
            'merchant_id' => $merchantId,
            'merchant_no' => (string) ($merchant['merchant_no'] !== '' ? $merchant['merchant_no'] : trim($merchantNo)),
            'merchant' => $merchant,
            'user' => $user,
            'roles' => ['common'],
            'permissions' => [],
        ];
    }

    /**
     * 校验商户登录 token，并返回商户与登录态信息。
     *
     * @param string $token 登录令牌
     * @param string $ip 请求 IP
     * @param string $userAgent 用户代理
     * @return array{merchant: Merchant, credential: \app\model\merchant\MerchantApiCredential|null}|null 登录态
     */
    public function authenticateToken(string $token, string $ip = '', string $userAgent = ''): ?array
    {
        $result = $this->jwtTokenManager->verify('merchant', $token, $ip, $userAgent);
        if ($result === null) {
            return null;
        }

        $merchantId = (int) ($result['session']['merchant_id'] ?? $result['claims']['merchant_id'] ?? 0);
        if ($merchantId <= 0) {
            return null;
        }

        /** @var Merchant|null $merchant */
        $merchant = $this->merchantRepository->find($merchantId);
        if (!$merchant || (int) $merchant->status !== CommonConstant::STATUS_ENABLED) {
            return null;
        }

        $credential = $this->merchantApiCredentialRepository->findByMerchantId($merchantId);

        return [
            'merchant' => $merchant,
            'credential' => $credential,
        ];
    }

    /**
     * 校验商户登录凭证并签发 JWT。
     *
     * @param string $merchantNo 商户号
     * @param string $password 密码
     * @param string $ip 请求 IP
     * @param string $userAgent 用户代理
     * @return array{token: string, expires_in: int, merchant: Merchant, credential: array{status: int, sign_type: int, last_used_at: mixed}|null} 登录结果
     * @throws ValidationException
     */
    public function authenticateCredentials(string $merchantNo, string $password, string $ip = '', string $userAgent = ''): array
    {
        $merchantNo = trim($merchantNo);
        $password = trim($password);
        if ($merchantNo === '' || $password === '') {
            throw new ValidationException('商户号或密码错误');
        }

        /** @var Merchant|null $merchant */
        $merchant = $this->merchantRepository->findByMerchantNo($merchantNo);
        if (!$merchant || (int) $merchant->status !== CommonConstant::STATUS_ENABLED) {
            throw new ValidationException('商户号或密码错误');
        }

        if (!password_verify($password, (string) $merchant->password_hash)) {
            throw new ValidationException('商户号或密码错误');
        }

        $this->merchantRepository->updateById((int) $merchant->id, [
            'last_login_at' => $this->now(),
            'last_login_ip' => trim($ip),
        ]);

        return $this->issueToken((int) $merchant->id, 86400, $ip, $userAgent);
    }

    /**
     * 撤销当前商户登录 token。
     *
     * @param string $token 登录令牌
     * @return bool 是否撤销成功
     */
    public function revokeToken(string $token): bool
    {
        return $this->jwtTokenManager->revoke('merchant', $token);
    }

    /**
     * 签发新的商户登录 token。
     *
     * @param int $merchantId 商户ID
     * @param int $ttlSeconds 过期秒数
     * @param string $ip 请求 IP
     * @param string $userAgent 用户代理
     * @return array{token: string, expires_in: int, merchant: Merchant, credential: array{status: int, sign_type: int, last_used_at: mixed}|null} 登录结果
     * @throws ValidationException
     */
    public function issueToken(int $merchantId, int $ttlSeconds = 86400, string $ip = '', string $userAgent = ''): array
    {
        /** @var Merchant|null $merchant */
        $merchant = $this->merchantRepository->find($merchantId);
        if (!$merchant) {
            throw new ValidationException('商户不存在');
        }

        $credential = $this->merchantApiCredentialRepository->findByMerchantId($merchantId);

        $issued = $this->jwtTokenManager->issue('merchant', [
            'sub' => (string) $merchantId,
            'merchant_id' => (int) $merchant->id,
            'merchant_no' => (string) $merchant->merchant_no,
        ], [
            'merchant_id' => (int) $merchant->id,
            'merchant_no' => (string) $merchant->merchant_no,
            'last_login_ip' => $ip,
            'user_agent' => $userAgent,
        ], $ttlSeconds);

        return [
            'token' => $issued['token'],
            'expires_in' => $issued['expires_in'],
            'merchant' => $merchant,
            'credential' => $credential ? [
                'status' => (int) ($credential->status ?? 0),
                'sign_type' => (int) ($credential->sign_type ?? 0),
                'last_used_at' => $credential->last_used_at,
            ] : null,
        ];
    }
}






