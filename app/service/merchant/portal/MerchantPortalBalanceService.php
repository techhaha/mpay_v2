<?php

namespace app\service\merchant\portal;

use app\common\base\BaseService;
use app\service\account\funds\MerchantAccountService;
use app\service\account\ledger\MerchantAccountLedgerService;

/**
 * 商户门户余额服务。
 */
class MerchantPortalBalanceService extends BaseService
{
    public function __construct(
        protected MerchantPortalSupportService $supportService,
        protected MerchantAccountService $merchantAccountService,
        protected MerchantAccountLedgerService $merchantAccountLedgerService
    ) {
    }

    public function withdrawableBalance(int $merchantId): array
    {
        $merchant = $this->supportService->merchantSummary($merchantId);
        $snapshot = $this->merchantAccountService->getBalanceSnapshot($merchantId);

        $snapshot['available_balance_text'] = $this->supportService->formatAmount((int) ($snapshot['available_balance'] ?? 0));
        $snapshot['frozen_balance_text'] = $this->supportService->formatAmount((int) ($snapshot['frozen_balance'] ?? 0));
        $snapshot['withdrawable_balance_text'] = $snapshot['available_balance_text'];

        return [
            'merchant' => $merchant,
            'snapshot' => $snapshot,
        ];
    }

    public function balanceFlows(array $filters, int $merchantId, int $page, int $pageSize): array
    {
        $filters['merchant_id'] = $merchantId;
        $paginator = $this->merchantAccountLedgerService->paginate($filters, $page, $pageSize);

        return [
            'merchant' => $this->supportService->merchantSummary($merchantId),
            'snapshot' => $this->withdrawableBalance($merchantId)['snapshot'],
            'list' => $paginator->items(),
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'size' => $paginator->perPage(),
        ];
    }
}
