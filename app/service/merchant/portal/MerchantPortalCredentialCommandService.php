<?php

namespace app\service\merchant\portal;

use app\common\base\BaseService;
use app\repository\merchant\credential\MerchantApiCredentialRepository;
use app\service\merchant\security\MerchantApiCredentialService;

/**
 * 商户门户接口凭证命令服务。
 */
class MerchantPortalCredentialCommandService extends BaseService
{
    public function __construct(
        protected MerchantPortalSupportService $supportService,
        protected MerchantApiCredentialRepository $merchantApiCredentialRepository,
        protected MerchantApiCredentialService $merchantApiCredentialService
    ) {
    }

    public function issueCredential(int $merchantId): array
    {
        $merchant = $this->supportService->merchantSummary($merchantId);
        $credentialValue = $this->merchantApiCredentialService->issueCredential($merchantId);
        $credential = $this->merchantApiCredentialRepository->findByMerchantId($merchantId);

        return [
            'merchant' => $merchant,
            'credential_value' => $credentialValue,
            'credential' => $credential ? $this->formatCredential($credential, $merchant) : null,
        ];
    }

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
            'api_key_preview' => $this->supportService->maskCredentialValue((string) $credential->api_key),
            'status' => (int) $credential->status,
            'status_text' => (string) ($credential->status ? '启用' : '禁用'),
            'last_used_at' => $this->supportService->formatDateTime($credential->last_used_at ?? null),
            'created_at' => $this->supportService->formatDateTime($credential->created_at ?? null),
            'updated_at' => $this->supportService->formatDateTime($credential->updated_at ?? null),
        ];
    }
}
