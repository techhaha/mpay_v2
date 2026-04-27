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
     * @param array $options 生成选项
     * @return array 凭证数据
     */
    public function issueCredential(int $merchantId, array $options = []): array
    {
        $merchant = $this->supportService->merchantSummary($merchantId);
        $result = $this->merchantApiCredentialService->issueCredentialBundle($merchantId, $options);
        $credentialValue = (string) ($result['credential_value'] ?? '');
        $merchantPrivateKey = (string) ($result['merchant_private_key'] ?? '');
        $generated = (array) ($result['generated'] ?? []);
        // 凭证明文只在发放当次返回一次，随后再查库只拿脱敏后的展示结构。
        $credential = $this->merchantApiCredentialRepository->findByMerchantId($merchantId);

        return [
            'merchant' => $this->formatMerchant($merchant),
            'integration' => $this->supportService->apiIntegrationInfo($merchant),
            'credential_value' => $credentialValue,
            'merchant_private_key' => $merchantPrivateKey,
            'generated' => $generated,
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
     * @param \app\model\merchant\MerchantApiCredential $credential 凭证
     * @param array $merchant 商户摘要
     * @return array 展示数据
     */
    private function formatCredential(\app\model\merchant\MerchantApiCredential $credential, array $merchant): array
    {
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
            'status' => (int) $credential->status,
            'status_text' => (string) ($credential->status ? '启用' : '禁用'),
        ];
    }
}
