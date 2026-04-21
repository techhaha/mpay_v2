<?php

namespace app\service\account\funds;

use app\common\base\BaseService;
use app\model\merchant\MerchantAccount;
use app\model\merchant\MerchantAccountLedger;

/**
 * 商户余额服务。
 *
 * @property MerchantAccountQueryService $queryService 查询服务
 * @property MerchantAccountCommandService $commandService 命令服务
 */
class MerchantAccountService extends BaseService
{
    /**
     * 构造方法。
     *
     * @param MerchantAccountQueryService $queryService 查询服务
     * @param MerchantAccountCommandService $commandService 命令服务
     * @return void
     */
    public function __construct(
        protected MerchantAccountQueryService $queryService,
        protected MerchantAccountCommandService $commandService
    ) {
    }

    /**
     * 分页查询商户账户列表。
     *
     * @param array $filters 筛选条件
     * @param int $page 页码
     * @param int $pageSize 每页条数
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator 分页结果
     */
    public function paginate(array $filters = [], int $page = 1, int $pageSize = 10)
    {
        return $this->queryService->paginate($filters, $page, $pageSize);
    }

    /**
     * 获取商户账户总览。
     *
     * @return array 总览数据
     */
    public function summary(): array
    {
        return $this->queryService->summary();
    }

    /**
     * 获取或创建商户账户。
     *
     * @param int $merchantId 商户ID
     * @return MerchantAccount 账户记录
     */
    public function ensureAccount(int $merchantId): MerchantAccount
    {
        return $this->commandService->ensureAccount($merchantId);
    }

    /**
     * 在当前事务中获取或创建商户账户。
     *
     * @param int $merchantId 商户ID
     * @return MerchantAccount 账户记录
     */
    public function ensureAccountInCurrentTransaction(int $merchantId): MerchantAccount
    {
        return $this->commandService->ensureAccountInCurrentTransaction($merchantId);
    }

    /**
     * 冻结可用余额。
     *
     * @param int $merchantId 商户ID
     * @param int $amount 金额（分）
     * @param string $bizNo 业务单号
     * @param string $idempotencyKey 幂等键
     * @param array $extJson 扩展字段
     * @param string $traceNo 追踪号
     * @return MerchantAccountLedger 流水记录
     */
    public function freezeAmount(int $merchantId, int $amount, string $bizNo, string $idempotencyKey, array $extJson = [], string $traceNo = ''): MerchantAccountLedger
    {
        return $this->commandService->freezeAmount($merchantId, $amount, $bizNo, $idempotencyKey, $extJson, $traceNo);
    }

    /**
     * 在当前事务中冻结可用余额。
     *
     * @param int $merchantId 商户ID
     * @param int $amount 金额（分）
     * @param string $bizNo 业务单号
     * @param string $idempotencyKey 幂等键
     * @param array $extJson 扩展字段
     * @param string $traceNo 追踪号
     * @return MerchantAccountLedger 流水记录
     */
    public function freezeAmountInCurrentTransaction(int $merchantId, int $amount, string $bizNo, string $idempotencyKey, array $extJson = [], string $traceNo = ''): MerchantAccountLedger
    {
        return $this->commandService->freezeAmountInCurrentTransaction($merchantId, $amount, $bizNo, $idempotencyKey, $extJson, $traceNo);
    }

    /**
     * 扣减冻结余额。
     *
     * @param int $merchantId 商户ID
     * @param int $amount 金额（分）
     * @param string $bizNo 业务单号
     * @param string $idempotencyKey 幂等键
     * @param array $extJson 扩展字段
     * @param string $traceNo 追踪号
     * @return MerchantAccountLedger 流水记录
     */
    public function deductFrozenAmount(int $merchantId, int $amount, string $bizNo, string $idempotencyKey, array $extJson = [], string $traceNo = ''): MerchantAccountLedger
    {
        return $this->commandService->deductFrozenAmount($merchantId, $amount, $bizNo, $idempotencyKey, $extJson, $traceNo);
    }

    /**
     * 在当前事务中扣减冻结余额。
     *
     * @param int $merchantId 商户ID
     * @param int $amount 金额（分）
     * @param string $bizNo 业务单号
     * @param string $idempotencyKey 幂等键
     * @param array $extJson 扩展字段
     * @param string $traceNo 追踪号
     * @return MerchantAccountLedger 流水记录
     */
    public function deductFrozenAmountInCurrentTransaction(int $merchantId, int $amount, string $bizNo, string $idempotencyKey, array $extJson = [], string $traceNo = ''): MerchantAccountLedger
    {
        return $this->commandService->deductFrozenAmountInCurrentTransaction($merchantId, $amount, $bizNo, $idempotencyKey, $extJson, $traceNo);
    }

    /**
     * 释放冻结余额。
     *
     * @param int $merchantId 商户ID
     * @param int $amount 金额（分）
     * @param string $bizNo 业务单号
     * @param string $idempotencyKey 幂等键
     * @param array $extJson 扩展字段
     * @param string $traceNo 追踪号
     * @return MerchantAccountLedger 流水记录
     */
    public function releaseFrozenAmount(int $merchantId, int $amount, string $bizNo, string $idempotencyKey, array $extJson = [], string $traceNo = ''): MerchantAccountLedger
    {
        return $this->commandService->releaseFrozenAmount($merchantId, $amount, $bizNo, $idempotencyKey, $extJson, $traceNo);
    }

    /**
     * 在当前事务中释放冻结余额。
     *
     * @param int $merchantId 商户ID
     * @param int $amount 金额（分）
     * @param string $bizNo 业务单号
     * @param string $idempotencyKey 幂等键
     * @param array $extJson 扩展字段
     * @param string $traceNo 追踪号
     * @return MerchantAccountLedger 流水记录
     */
    public function releaseFrozenAmountInCurrentTransaction(int $merchantId, int $amount, string $bizNo, string $idempotencyKey, array $extJson = [], string $traceNo = ''): MerchantAccountLedger
    {
        return $this->commandService->releaseFrozenAmountInCurrentTransaction($merchantId, $amount, $bizNo, $idempotencyKey, $extJson, $traceNo);
    }

    /**
     * 增加可用余额。
     *
     * @param int $merchantId 商户ID
     * @param int $amount 金额（分）
     * @param string $bizNo 业务单号
     * @param string $idempotencyKey 幂等键
     * @param array $extJson 扩展字段
     * @param string $traceNo 追踪号
     * @return MerchantAccountLedger 流水记录
     */
    public function creditAvailableAmount(int $merchantId, int $amount, string $bizNo, string $idempotencyKey, array $extJson = [], string $traceNo = ''): MerchantAccountLedger
    {
        return $this->commandService->creditAvailableAmount($merchantId, $amount, $bizNo, $idempotencyKey, $extJson, $traceNo);
    }

    /**
     * 在当前事务中增加可用余额。
     *
     * @param int $merchantId 商户ID
     * @param int $amount 金额（分）
     * @param string $bizNo 业务单号
     * @param string $idempotencyKey 幂等键
     * @param array $extJson 扩展字段
     * @param string $traceNo 追踪号
     * @return MerchantAccountLedger 流水记录
     */
    public function creditAvailableAmountInCurrentTransaction(int $merchantId, int $amount, string $bizNo, string $idempotencyKey, array $extJson = [], string $traceNo = ''): MerchantAccountLedger
    {
        return $this->commandService->creditAvailableAmountInCurrentTransaction($merchantId, $amount, $bizNo, $idempotencyKey, $extJson, $traceNo);
    }

    /**
     * 扣减可用余额。
     *
     * @param int $merchantId 商户ID
     * @param int $amount 金额（分）
     * @param string $bizNo 业务单号
     * @param string $idempotencyKey 幂等键
     * @param array $extJson 扩展字段
     * @param string $traceNo 追踪号
     * @return MerchantAccountLedger 流水记录
     */
    public function debitAvailableAmount(int $merchantId, int $amount, string $bizNo, string $idempotencyKey, array $extJson = [], string $traceNo = ''): MerchantAccountLedger
    {
        return $this->commandService->debitAvailableAmount($merchantId, $amount, $bizNo, $idempotencyKey, $extJson, $traceNo);
    }

    /**
     * 在当前事务中扣减可用余额。
     *
     * @param int $merchantId 商户ID
     * @param int $amount 金额（分）
     * @param string $bizNo 业务单号
     * @param string $idempotencyKey 幂等键
     * @param array $extJson 扩展字段
     * @param string $traceNo 追踪号
     * @return MerchantAccountLedger 流水记录
     */
    public function debitAvailableAmountInCurrentTransaction(int $merchantId, int $amount, string $bizNo, string $idempotencyKey, array $extJson = [], string $traceNo = ''): MerchantAccountLedger
    {
        return $this->commandService->debitAvailableAmountInCurrentTransaction($merchantId, $amount, $bizNo, $idempotencyKey, $extJson, $traceNo);
    }

    /**
     * 获取余额快照。
     *
     * @param int $merchantId 商户ID
     * @return array 快照数据
     */
    public function getBalanceSnapshot(int $merchantId): array
    {
        return $this->queryService->getBalanceSnapshot($merchantId);
    }

    /**
     * 按ID查询商户账户。
     *
     * @param int $id 商户账户ID
     * @return MerchantAccount|null 账户记录
     */
    public function findById(int $id): ?MerchantAccount
    {
        return $this->queryService->findById($id);
    }
}


