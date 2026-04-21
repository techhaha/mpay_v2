<?php

namespace app\service\merchant\portal;

use app\common\base\BaseService;
use app\service\account\funds\MerchantAccountService;
use app\service\account\ledger\MerchantAccountLedgerService;

/**
 * 商户门户余额服务。
 *
 * 负责余额快照、可提现余额和资金流水页面的数据拼装。
 *
 * @property MerchantPortalSupportService $supportService 支持服务
 * @property MerchantAccountService $merchantAccountService 商户账户服务
 * @property MerchantAccountLedgerService $merchantAccountLedgerService 商户账户流水服务
 */
class MerchantPortalBalanceService extends BaseService
{
    /**
     * 构造方法。
     *
     * @param MerchantPortalSupportService $supportService 支持服务
     * @param MerchantAccountService $merchantAccountService 商户账户服务
     * @param MerchantAccountLedgerService $merchantAccountLedgerService 商户账户流水服务
     */
    public function __construct(
        protected MerchantPortalSupportService $supportService,
        protected MerchantAccountService $merchantAccountService,
        protected MerchantAccountLedgerService $merchantAccountLedgerService
    ) {
    }

    /**
     * 查询商户门户可提现余额。
     *
     * @param int $merchantId 商户ID
     * @return array 余额摘要
     */
    public function withdrawableBalance(int $merchantId): array
    {
        $merchant = $this->supportService->merchantSummary($merchantId);
        $snapshot = $this->merchantAccountService->getBalanceSnapshot($merchantId);

        $snapshot['available_balance_text'] = $this->formatAmount((int) ($snapshot['available_balance'] ?? 0));
        $snapshot['frozen_balance_text'] = $this->formatAmount((int) ($snapshot['frozen_balance'] ?? 0));
        $snapshot['withdrawable_balance_text'] = $snapshot['available_balance_text'];

        return [
            'merchant' => $merchant,
            'snapshot' => $snapshot,
        ];
    }

    /**
     * 查询商户门户资金流水。
     *
     * @param array $filters 筛选条件
     * @param int $merchantId 商户ID
     * @param int $page 页码
     * @param int $pageSize 每页条数
     * @return array 流水列表数据
     */
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


