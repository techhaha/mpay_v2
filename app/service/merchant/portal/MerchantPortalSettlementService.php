<?php

namespace app\service\merchant\portal;

use app\common\base\BaseService;
use app\exception\ResourceNotFoundException;
use app\service\payment\settlement\SettlementOrderQueryService;

/**
 * 商户门户清算服务。
 *
 * @property MerchantPortalSupportService $supportService 支持服务
 * @property SettlementOrderQueryService $settlementOrderQueryService 结算订单查询服务
 */
class MerchantPortalSettlementService extends BaseService
{
    /**
     * 构造方法。
     *
     * @param MerchantPortalSupportService $supportService 支持服务
     * @param SettlementOrderQueryService $settlementOrderQueryService 结算订单查询服务
     */
    public function __construct(
        protected MerchantPortalSupportService $supportService,
        protected SettlementOrderQueryService $settlementOrderQueryService
    ) {
    }

    /**
     * 查询商户结算记录。
     *
     * @param array $filters 筛选条件
     * @param int $merchantId 商户ID
     * @param int $page 页码
     * @param int $pageSize 每页条数
     * @return array 结算记录列表
     */
    public function settlementRecords(array $filters, int $merchantId, int $page, int $pageSize): array
    {
        $paginator = $this->settlementOrderQueryService->paginate($filters, $page, $pageSize, $merchantId);

        return [
            'merchant' => $this->supportService->merchantSummary($merchantId),
            'list' => $paginator->items(),
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'size' => $paginator->perPage(),
        ];
    }

    /**
     * 查询商户结算记录详情。
     *
     * @param string $settleNo 结算单号
     * @param int $merchantId 商户ID
     * @return array|null 结算详情
     */
    public function settlementRecordDetail(string $settleNo, int $merchantId): ?array
    {
        try {
            $detail = $this->settlementOrderQueryService->detail($settleNo, $merchantId);
        } catch (ResourceNotFoundException) {
            return null;
        }

        return [
            'merchant' => $this->supportService->merchantSummary($merchantId),
            'settlement_order' => $detail['settlement_order'] ?? null,
            'timeline' => $detail['timeline'] ?? [],
        ];
    }
}



