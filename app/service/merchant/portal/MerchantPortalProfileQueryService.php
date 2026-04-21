<?php

namespace app\service\merchant\portal;

use app\common\base\BaseService;

/**
 * 商户门户资料查询服务。
 *
 * @property MerchantPortalSupportService $supportService 支持服务
 */
class MerchantPortalProfileQueryService extends BaseService
{
    /**
     * 构造方法。
     *
     * @param MerchantPortalSupportService $supportService 支持服务
     */
    public function __construct(
        protected MerchantPortalSupportService $supportService
    ) {
    }

    /**
     * 查询商户门户资料页数据。
     *
     * @param int $merchantId 商户ID
     * @return array 页面数据
     */
    public function profile(int $merchantId): array
    {
        return [
            'merchant' => $this->supportService->merchantSummary($merchantId),
            'pay_types' => $this->supportService->enabledPayTypeOptions(),
        ];
    }
}



