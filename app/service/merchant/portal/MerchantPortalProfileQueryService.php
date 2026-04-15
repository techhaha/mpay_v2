<?php

namespace app\service\merchant\portal;

use app\common\base\BaseService;

/**
 * 商户门户资料查询服务。
 */
class MerchantPortalProfileQueryService extends BaseService
{
    public function __construct(
        protected MerchantPortalSupportService $supportService
    ) {
    }

    public function profile(int $merchantId): array
    {
        return [
            'merchant' => $this->supportService->merchantSummary($merchantId),
            'pay_types' => $this->supportService->enabledPayTypeOptions(),
        ];
    }
}
