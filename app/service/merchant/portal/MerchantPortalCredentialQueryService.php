<?php

namespace app\service\merchant\portal;

use app\common\base\BaseService;
use app\common\constant\CommonConstant;
use app\model\merchant\MerchantApiCredential;
use app\repository\merchant\credential\MerchantApiCredentialRepository;

/**
 * 商户门户接口凭证查询服务。
 */
class MerchantPortalCredentialQueryService extends BaseService
{
    public function __construct(
        protected MerchantPortalSupportService $supportService,
        protected MerchantApiCredentialRepository $merchantApiCredentialRepository
    ) {
    }

    public function apiCredential(int $merchantId): array
    {
        $merchant = $this->supportService->merchantSummary($merchantId);
        $credential = $this->merchantApiCredentialRepository->findByMerchantId($merchantId);

        return [
            'merchant' => $merchant,
            'has_credential' => $credential !== null,
            'credential' => $credential ? $this->formatCredential($credential, $merchant) : null,
        ];
    }

    private function formatCredential(MerchantApiCredential $credential, array $merchant): array
    {
        $signType = (int) $credential->sign_type;
        $status = (int) $credential->status;

        return [
            'id' => (int) $credential->id,
            'merchant_id' => (int) $credential->merchant_id,
            'merchant_no' => (string) ($merchant['merchant_no'] ?? ''),
            'merchant_name' => (string) ($merchant['merchant_name'] ?? ''),
            'sign_type' => $signType,
            'sign_type_text' => $this->supportService->signTypeText($signType),
            'api_key_preview' => $this->supportService->maskCredentialValue((string) $credential->api_key),
            'status' => $status,
            'status_text' => (string) (CommonConstant::statusMap()[$status] ?? '未知'),
            'last_used_at' => $this->supportService->formatDateTime($credential->last_used_at ?? null),
            'created_at' => $this->supportService->formatDateTime($credential->created_at ?? null),
            'updated_at' => $this->supportService->formatDateTime($credential->updated_at ?? null),
        ];
    }
}
