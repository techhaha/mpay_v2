<?php

namespace app\service\merchant\portal;

use app\common\base\BaseService;

/**
 * 商户门户资金与清算门面服务。
 *
 * 对外保留原有调用契约，内部委托给清算与余额子服务。
 */
class MerchantPortalFinanceService extends BaseService
{
    public function __construct(
        protected MerchantPortalSettlementService $settlementService,
        protected MerchantPortalBalanceService $balanceService
    ) {
    }

    public function settlementRecords(array $filters, int $merchantId, int $page, int $pageSize): array
    {
        return $this->settlementService->settlementRecords($filters, $merchantId, $page, $pageSize);
    }

    public function settlementRecordDetail(string $settleNo, int $merchantId): ?array
    {
        return $this->settlementService->settlementRecordDetail($settleNo, $merchantId);
    }

    public function withdrawableBalance(int $merchantId): array
    {
        return $this->balanceService->withdrawableBalance($merchantId);
    }

    public function balanceFlows(array $filters, int $merchantId, int $page, int $pageSize): array
    {
        return $this->balanceService->balanceFlows($filters, $merchantId, $page, $pageSize);
    }
}
