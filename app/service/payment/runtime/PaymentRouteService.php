<?php

namespace app\service\payment\runtime;

use app\common\base\BaseService;

/**
 * 支付路由门面服务。
 *
 * 对外保留原有调用契约，内部委托给路由解析服务。
 */
class PaymentRouteService extends BaseService
{
    public function __construct(
        protected PaymentRouteResolverService $resolverService
    ) {
    }

    /**
     * 按商户分组和支付方式解析路由。
     */
    public function resolveByMerchantGroup(int $merchantGroupId, int $payTypeId, int $payAmount, array $context = []): array
    {
        return $this->resolverService->resolveByMerchantGroup($merchantGroupId, $payTypeId, $payAmount, $context);
    }
}
