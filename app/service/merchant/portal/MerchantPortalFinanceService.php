<?php

namespace app\service\merchant\portal;

use app\common\base\BaseService;

/**
 * 商户门户资金与清算服务。
 *
 * @property MerchantPortalSettlementService $settlementService 结算服务
 * @property MerchantPortalBalanceService $balanceService 余额服务
 */
class MerchantPortalFinanceService extends BaseService
{
    /**
     * 构造方法。
     *
     * @param MerchantPortalSettlementService $settlementService 结算服务
     * @param MerchantPortalBalanceService $balanceService 余额服务
     */
    public function __construct(
        protected MerchantPortalSettlementService $settlementService,
        protected MerchantPortalBalanceService $balanceService
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
        return $this->settlementService->settlementRecords($filters, $merchantId, $page, $pageSize);
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
        return $this->settlementService->settlementRecordDetail($settleNo, $merchantId);
    }

    /**
     * 查询商户可提现余额。
     *
     * @param int $merchantId 商户ID
     * @return array 余额数据
     */
    public function withdrawableBalance(int $merchantId): array
    {
        return $this->balanceService->withdrawableBalance($merchantId);
    }

    /**
     * 查询商户资金流水。
     *
     * @param array $filters 筛选条件
     * @param int $merchantId 商户ID
     * @param int $page 页码
     * @param int $pageSize 每页条数
     * @return array 流水列表
     */
    public function balanceFlows(array $filters, int $merchantId, int $page, int $pageSize): array
    {
        return $this->balanceService->balanceFlows($filters, $merchantId, $page, $pageSize);
    }
}



