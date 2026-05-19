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
     * 导出商户账户 CSV。
     *
     * @param array $filters 筛选条件
     * @return \support\Response CSV 响应
     */
    public function exportCsv(array $filters = [])
    {
        $rows = $this->queryService->paginate($filters, 1, 5000)->items();
        $csvRows = [['商户号', '商户名称', '商户分组', '可用余额', '冻结余额', '创建时间', '更新时间']];
        foreach ($rows as $row) {
            $csvRows[] = [
                (string) ($row->merchant_no ?? ''),
                (string) ($row->merchant_name ?? ''),
                (string) ($row->merchant_group_name ?? ''),
                (string) ($row->available_balance_text ?? ''),
                (string) ($row->frozen_balance_text ?? ''),
                (string) ($row->created_at ?? ''),
                (string) ($row->updated_at ?? ''),
            ];
        }

        return $this->csvResponse($csvRows, 'merchant-accounts-' . date('YmdHis') . '.csv');
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
     * 获取账户、流水和冻结明细完整对账视图。
     *
     * @param array $filters 筛选条件
     * @return array 对账结果
     */
    public function reconciliation(array $filters = []): array
    {
        return $this->queryService->reconciliation($filters);
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
     * 在当前事务中冻结风控资金。
     *
     * @param int $merchantId 商户ID
     * @param int $amount 金额（分）
     * @param string $bizNo 业务单号
     * @param string $idempotencyKey 幂等键
     * @param array $extJson 扩展字段
     * @param string $traceNo 追踪号
     * @return MerchantAccountLedger 流水记录
     */
    public function freezeRiskAmountInCurrentTransaction(int $merchantId, int $amount, string $bizNo, string $idempotencyKey, array $extJson = [], string $traceNo = ''): MerchantAccountLedger
    {
        return $this->commandService->freezeRiskAmountInCurrentTransaction($merchantId, $amount, $bizNo, $idempotencyKey, $extJson, $traceNo);
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
     * 在当前事务中释放风控冻结资金。
     *
     * @param int $merchantId 商户ID
     * @param int $amount 金额（分）
     * @param string $bizNo 业务单号
     * @param string $idempotencyKey 幂等键
     * @param array $extJson 扩展字段
     * @param string $traceNo 追踪号
     * @return MerchantAccountLedger 流水记录
     */
    public function releaseRiskFrozenAmountInCurrentTransaction(int $merchantId, int $amount, string $bizNo, string $idempotencyKey, array $extJson = [], string $traceNo = ''): MerchantAccountLedger
    {
        return $this->commandService->releaseRiskFrozenAmountInCurrentTransaction($merchantId, $amount, $bizNo, $idempotencyKey, $extJson, $traceNo);
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
     * 在当前事务中扣减自收通道支付平台服务费。
     *
     * @param int $merchantId 商户ID
     * @param int $amount 金额（分）
     * @param string $bizNo 业务单号
     * @param string $idempotencyKey 幂等键
     * @param array $extJson 扩展字段
     * @param string $traceNo 追踪号
     * @return MerchantAccountLedger 流水记录
     */
    public function debitPayFeeAmountInCurrentTransaction(int $merchantId, int $amount, string $bizNo, string $idempotencyKey, array $extJson = [], string $traceNo = ''): MerchantAccountLedger
    {
        return $this->commandService->debitPayFeeAmountInCurrentTransaction($merchantId, $amount, $bizNo, $idempotencyKey, $extJson, $traceNo);
    }

    /**
     * 在当前事务中扣减转账本金。
     *
     * @param int $merchantId 商户ID
     * @param int $amount 金额（分）
     * @param string $bizNo 业务单号
     * @param string $idempotencyKey 幂等键
     * @param array $extJson 扩展字段
     * @param string $traceNo 追踪号
     * @return MerchantAccountLedger 流水记录
     */
    public function debitTransferAmountInCurrentTransaction(int $merchantId, int $amount, string $bizNo, string $idempotencyKey, array $extJson = [], string $traceNo = ''): MerchantAccountLedger
    {
        return $this->commandService->debitTransferAmountInCurrentTransaction($merchantId, $amount, $bizNo, $idempotencyKey, $extJson, $traceNo);
    }

    /**
     * 在当前事务中扣减转账手续费。
     *
     * @param int $merchantId 商户ID
     * @param int $amount 金额（分）
     * @param string $bizNo 业务单号
     * @param string $idempotencyKey 幂等键
     * @param array $extJson 扩展字段
     * @param string $traceNo 追踪号
     * @return MerchantAccountLedger 流水记录
     */
    public function debitTransferFeeInCurrentTransaction(int $merchantId, int $amount, string $bizNo, string $idempotencyKey, array $extJson = [], string $traceNo = ''): MerchantAccountLedger
    {
        return $this->commandService->debitTransferFeeInCurrentTransaction($merchantId, $amount, $bizNo, $idempotencyKey, $extJson, $traceNo);
    }

    /**
     * 在当前事务中释放失败转账已扣金额。
     *
     * @param int $merchantId 商户ID
     * @param int $amount 金额（分）
     * @param string $bizNo 业务单号
     * @param string $idempotencyKey 幂等键
     * @param array $extJson 扩展字段
     * @param string $traceNo 追踪号
     * @return MerchantAccountLedger 流水记录
     */
    public function releaseTransferAmountInCurrentTransaction(int $merchantId, int $amount, string $bizNo, string $idempotencyKey, array $extJson = [], string $traceNo = ''): MerchantAccountLedger
    {
        return $this->commandService->releaseTransferAmountInCurrentTransaction($merchantId, $amount, $bizNo, $idempotencyKey, $extJson, $traceNo);
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

    /**
     * 构建 CSV 下载响应。
     *
     * @param array<int, array<int, string>> $rows CSV 行
     * @param string $filename 文件名
     * @return \support\Response 响应
     */
    private function csvResponse(array $rows, string $filename)
    {
        $fp = fopen('php://temp', 'r+');
        foreach ($rows as $row) {
            fputcsv($fp, $row);
        }
        rewind($fp);
        $body = "\xEF\xBB\xBF" . stream_get_contents($fp);
        fclose($fp);

        return response($body, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . str_replace(['"', "\r", "\n", "\0"], '', $filename) . '"',
        ]);
    }
}
