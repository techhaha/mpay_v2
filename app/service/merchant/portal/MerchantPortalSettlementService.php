<?php

namespace app\service\merchant\portal;

use app\common\base\BaseService;
use app\exception\ResourceNotFoundException;
use app\service\payment\settlement\SettlementOrderQueryService;

/**
 * 商户门户清算服务。
 */
class MerchantPortalSettlementService extends BaseService
{
    public function __construct(
        protected MerchantPortalSupportService $supportService,
        protected SettlementOrderQueryService $settlementOrderQueryService
    ) {
    }

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
