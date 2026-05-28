<?php

namespace app\service\merchant\portal;

use app\common\base\BaseService;
use app\common\constant\AuthConstant;
use app\model\merchant\MerchantApiCredential;
use app\repository\merchant\credential\MerchantApiCredentialRepository;

/**
 * 商户门户 API 凭证查询服务。
 *
 * @property MerchantPortalSupportService $supportService 支持服务
 * @property MerchantApiCredentialRepository $merchantApiCredentialRepository 商户 API 凭证仓库
 */
class MerchantPortalCredentialQueryService extends BaseService
{
    /**
     * 构造方法。
     *
     * @param MerchantPortalSupportService $supportService 支持服务
     * @param MerchantApiCredentialRepository $merchantApiCredentialRepository 商户 API 凭证仓库
     */
    public function __construct(
        protected MerchantPortalSupportService $supportService,
        protected MerchantApiCredentialRepository $merchantApiCredentialRepository
    ) {
    }

    /**
     * 查询商户 API 凭证详情。
     *
     * @param int $merchantId 商户ID
     * @return array 凭证详情
     */
    public function apiCredential(int $merchantId): array
    {
        $merchant = $this->supportService->merchantSummary($merchantId);
        $credential = $this->merchantApiCredentialRepository->findByMerchantId($merchantId);

        return [
            'merchant' => $this->formatMerchant($merchant),
            'has_credential' => $credential !== null,
            'integration' => $this->supportService->apiIntegrationInfo($merchant),
            'credential' => $credential ? $this->formatCredential($credential, $merchant) : null,
        ];
    }

    /**
     * 格式化页面所需商户摘要。
     *
     * @param array $merchant 商户摘要
     * @return array<string, mixed>
     */
    private function formatMerchant(array $merchant): array
    {
        return [
            'id' => (int) ($merchant['id'] ?? $merchant['merchant_id'] ?? 0),
            'merchant_no' => (string) ($merchant['merchant_no'] ?? ''),
            'merchant_name' => (string) ($merchant['merchant_name'] ?? ''),
        ];
    }

    /**
     * 格式化接口凭证展示数据。
     *
     * @param MerchantApiCredential $credential 凭证
     * @param array $merchant 商户摘要
     * @return array 展示数据
     */
    private function formatCredential(MerchantApiCredential $credential, array $merchant): array
    {
        $status = (int) $credential->status;
        $apiKey = trim((string) $credential->api_key);
        $merchantPublicKey = trim((string) ($credential->merchant_public_key ?? ''));
        $platformPublicKey = trim((string) config('epay.v2.platform_public_key', ''));

        return [
            'id' => (int) $credential->id,
            'merchant_id' => (int) $credential->merchant_id,
            'api_key_preview' => $this->maskCredentialValue($apiKey),
            'api_key_full' => $apiKey,
            'merchant_public_key_full' => $merchantPublicKey,
            'merchant_public_key_preview' => $this->maskCredentialValue($merchantPublicKey),
            'platform_public_key_full' => $platformPublicKey,
            'platform_public_key_preview' => $this->maskCredentialValue($platformPublicKey),
            'supports_v1' => $apiKey !== '',
            'supports_v2' => $merchantPublicKey !== '' && $platformPublicKey !== '',
            'v1_status_text' => $apiKey !== '' ? '已配置' : '未配置',
            'v2_status_text' => $merchantPublicKey !== '' && $platformPublicKey !== '' ? '已配置' : '未配置',
            'status' => $status,
            'status_text' => $this->textFromMap($status, AuthConstant::credentialStatusMap()),
        ];
    }
}
