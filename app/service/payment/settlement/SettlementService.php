<?php

namespace app\service\payment\settlement;

use app\common\base\BaseService;
use app\model\payment\SettlementOrder;

/**
 * 清算门面服务。
 *
 * 对外保留原有调用契约，内部委托给清算生命周期服务。
 */
class SettlementService extends BaseService
{
    /**
     * 构造函数，注入对应依赖。
     */
    public function __construct(
        protected SettlementOrderQueryService $queryService,
        protected SettlementLifecycleService $lifecycleService
    ) {
    }

    /**
     * 创建清算单和明细。
     */
    public function createSettlementOrder(array $input, array $items = []): SettlementOrder
    {
        return $this->lifecycleService->createSettlementOrder($input, $items);
    }

    /**
     * 查询清算订单详情。
     */
    public function detail(string $settleNo, ?int $merchantId = null): array
    {
        return $this->queryService->detail($settleNo, $merchantId);
    }

    /**
     * 清算入账成功。
     */
    public function completeSettlement(string $settleNo): SettlementOrder
    {
        return $this->lifecycleService->completeSettlement($settleNo);
    }

    /**
     * 清算失败。
     */
    public function failSettlement(string $settleNo, string $reason = ''): SettlementOrder
    {
        return $this->lifecycleService->failSettlement($settleNo, $reason);
    }
}
