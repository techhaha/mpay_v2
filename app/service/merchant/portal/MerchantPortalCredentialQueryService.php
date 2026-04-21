<?php

namespace app\service\merchant\portal;

use app\common\base\BaseService;
use app\common\constant\CommonConstant;
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
            'merchant' => $merchant,
            'has_credential' => $credential !== null,
            'credential' => $credential ? $this->formatCredential($credential, $merchant) : null,
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
        $signType = (int) $credential->sign_type;
        $status = (int) $credential->status;

        return [
            'id' => (int) $credential->id,
            'merchant_id' => (int) $credential->merchant_id,
            'merchant_no' => (string) ($merchant['merchant_no'] ?? ''),
            'merchant_name' => (string) ($merchant['merchant_name'] ?? ''),
            'sign_type' => $signType,
            'sign_type_text' => $this->supportService->signTypeText($signType),
            'api_key_preview' => $this->maskCredentialValue((string) $credential->api_key),
            'status' => $status,
            'status_text' => (string) (CommonConstant::statusMap()[$status] ?? '未知'),
            'last_used_at' => $this->formatDateTime($credential->last_used_at ?? null),
            'created_at' => $this->formatDateTime($credential->created_at ?? null),
            'updated_at' => $this->formatDateTime($credential->updated_at ?? null),
        ];
    }
}


