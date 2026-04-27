<?php

namespace app\service\payment\runtime;

use app\common\base\BaseService;

/**
 * 支付路由服务。
 *
 * @property PaymentRouteResolverService $resolverService 路由解析服务
 */
class PaymentRouteService extends BaseService
{
    /**
     * 构造方法。
     *
     * @param PaymentRouteResolverService $resolverService 路由解析服务
     * @return void
     */
    public function __construct(
        protected PaymentRouteResolverService $resolverService
    ) {
    }

    /**
     * 按商户分组和支付方式解析路由。
     *
     * @param int $merchantGroupId 商户分组ID
     * @param int $payTypeId 支付类型ID
     * @param int $payAmount 支付金额（分）
     * @param array $context 路由上下文，例如统计日期、额外筛选条件
     * @return array 路由解析结果
     */
    public function resolveByMerchantGroup(int $merchantGroupId, int $payTypeId, int $payAmount, array $context = []): array
    {
        return $this->resolverService->resolveByMerchantGroup($merchantGroupId, $payTypeId, $payAmount, $context);
    }

    /**
     * 预览商户可用支付方式。
     *
     * @param int $merchantGroupId 商户分组ID
     * @param int $payAmount 支付金额（分）
     * @param array $context 路由上下文
     * @return array<int, array<string, mixed>> 可用支付方式列表
     */
    public function previewAvailablePayTypes(int $merchantGroupId, int $payAmount, array $context = []): array
    {
        return $this->resolverService->previewAvailablePayTypes($merchantGroupId, $payAmount, $context);
    }
}


