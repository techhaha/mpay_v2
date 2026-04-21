<?php

namespace app\service\payment\settlement;

use app\common\base\BaseService;
use app\model\payment\SettlementOrder;

/**
 * 清算服务。
 *
 * @property SettlementOrderQueryService $queryService 查询服务
 * @property SettlementLifecycleService $lifecycleService 生命周期服务
 */
class SettlementService extends BaseService
{
    /**
     * 构造方法。
     *
     * @param SettlementOrderQueryService $queryService 查询服务
     * @param SettlementLifecycleService $lifecycleService 生命周期服务
     * @return void
     */
    public function __construct(
        protected SettlementOrderQueryService $queryService,
        protected SettlementLifecycleService $lifecycleService
    ) {
    }

    /**
     * 创建清算单和明细。
     *
     * @param array $input 输入参数
     * @param array $items 清算明细
     * @return SettlementOrder 清算订单模型
     */
    public function createSettlementOrder(array $input, array $items = []): SettlementOrder
    {
        return $this->lifecycleService->createSettlementOrder($input, $items);
    }

    /**
     * 查询清算订单详情。
     *
     * @param string $settleNo 清算单号
     * @param int|null $merchantId 商户ID
     * @return array 详情结构
     */
    public function detail(string $settleNo, ?int $merchantId = null): array
    {
        return $this->queryService->detail($settleNo, $merchantId);
    }

    /**
     * 标记清算入账成功。
     *
     * @param string $settleNo 清算单号
     * @return SettlementOrder 清算订单模型
     */
    public function completeSettlement(string $settleNo): SettlementOrder
    {
        return $this->lifecycleService->completeSettlement($settleNo);
    }

    /**
     * 标记清算失败。
     *
     * @param string $settleNo 清算单号
     * @param string $reason 失败原因
     * @return SettlementOrder 清算订单模型
     */
    public function failSettlement(string $settleNo, string $reason = ''): SettlementOrder
    {
        return $this->lifecycleService->failSettlement($settleNo, $reason);
    }
}


