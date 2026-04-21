<?php

namespace app\service\merchant\portal;

use app\common\base\BaseService;
use app\repository\merchant\credential\MerchantApiCredentialRepository;
use app\service\merchant\security\MerchantApiCredentialService;

/**
 * 商户门户 API 凭证命令服务。
 *
 * @property MerchantPortalSupportService $supportService 支持服务
 * @property MerchantApiCredentialRepository $merchantApiCredentialRepository 商户 API 凭证仓库
 * @property MerchantApiCredentialService $merchantApiCredentialService 商户 API 凭证服务
 */
class MerchantPortalCredentialCommandService extends BaseService
{
    /**
     * 构造方法。
     *
     * @param MerchantPortalSupportService $supportService 支持服务
     * @param MerchantApiCredentialRepository $merchantApiCredentialRepository 商户 API 凭证仓库
     * @param MerchantApiCredentialService $merchantApiCredentialService 商户 API 凭证服务
     */
    public function __construct(
        protected MerchantPortalSupportService $supportService,
        protected MerchantApiCredentialRepository $merchantApiCredentialRepository,
        protected MerchantApiCredentialService $merchantApiCredentialService
    ) {
    }

    /**
     * 生成或重置商户 API 凭证。
     *
     * @param int $merchantId 商户ID
     * @return array 凭证数据
     */
    public function issueCredential(int $merchantId): array
    {
        $merchant = $this->supportService->merchantSummary($merchantId);
        $credentialValue = $this->merchantApiCredentialService->issueCredential($merchantId);
        // 凭证明文只在发放当次返回一次，随后再查库只拿脱敏后的展示结构。
        $credential = $this->merchantApiCredentialRepository->findByMerchantId($merchantId);

        return [
            'merchant' => $merchant,
            'credential_value' => $credentialValue,
            'credential' => $credential ? $this->formatCredential($credential, $merchant) : null,
        ];
    }

    /**
     * 格式化接口凭证展示数据。
     *
     * @param \app\model\merchant\MerchantApiCredential $credential 凭证
     * @param array $merchant 商户摘要
     * @return array 展示数据
     */
    private function formatCredential(\app\model\merchant\MerchantApiCredential $credential, array $merchant): array
    {
        $signType = (int) $credential->sign_type;

        return [
            'id' => (int) $credential->id,
            'merchant_id' => (int) $credential->merchant_id,
            'merchant_no' => (string) ($merchant['merchant_no'] ?? ''),
            'merchant_name' => (string) ($merchant['merchant_name'] ?? ''),
            'sign_type' => $signType,
            'sign_type_text' => $this->supportService->signTypeText($signType),
            // 展示页只保留脱敏后的 key 片段，避免明文凭证再次暴露。
            'api_key_preview' => $this->maskCredentialValue((string) $credential->api_key),
            'status' => (int) $credential->status,
            'status_text' => (string) ($credential->status ? '启用' : '禁用'),
            'last_used_at' => $this->formatDateTime($credential->last_used_at ?? null),
            'created_at' => $this->formatDateTime($credential->created_at ?? null),
            'updated_at' => $this->formatDateTime($credential->updated_at ?? null),
        ];
    }
}
