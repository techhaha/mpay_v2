<?php

namespace app\service\account\funds;

use app\common\base\BaseService;
use app\model\merchant\MerchantAccount;
use app\model\merchant\MerchantAccountLedger;

/**
 * 商户余额门面服务。
 *
 * 对外保留原有调用契约，内部委托给查询和命令两个子服务。
 */
class MerchantAccountService extends BaseService
{
    public function __construct(
        protected MerchantAccountQueryService $queryService,
        protected MerchantAccountCommandService $commandService
    ) {
    }

    public function paginate(array $filters = [], int $page = 1, int $pageSize = 10)
    {
        return $this->queryService->paginate($filters, $page, $pageSize);
    }

    public function summary(): array
    {
        return $this->queryService->summary();
    }

    public function ensureAccount(int $merchantId): MerchantAccount
    {
        return $this->commandService->ensureAccount($merchantId);
    }

    public function ensureAccountInCurrentTransaction(int $merchantId): MerchantAccount
    {
        return $this->commandService->ensureAccountInCurrentTransaction($merchantId);
    }

    public function freezeAmount(int $merchantId, int $amount, string $bizNo, string $idempotencyKey, array $extJson = [], string $traceNo = ''): MerchantAccountLedger
    {
        return $this->commandService->freezeAmount($merchantId, $amount, $bizNo, $idempotencyKey, $extJson, $traceNo);
    }

    public function freezeAmountInCurrentTransaction(int $merchantId, int $amount, string $bizNo, string $idempotencyKey, array $extJson = [], string $traceNo = ''): MerchantAccountLedger
    {
        return $this->commandService->freezeAmountInCurrentTransaction($merchantId, $amount, $bizNo, $idempotencyKey, $extJson, $traceNo);
    }

    public function deductFrozenAmount(int $merchantId, int $amount, string $bizNo, string $idempotencyKey, array $extJson = [], string $traceNo = ''): MerchantAccountLedger
    {
        return $this->commandService->deductFrozenAmount($merchantId, $amount, $bizNo, $idempotencyKey, $extJson, $traceNo);
    }

    public function deductFrozenAmountInCurrentTransaction(int $merchantId, int $amount, string $bizNo, string $idempotencyKey, array $extJson = [], string $traceNo = ''): MerchantAccountLedger
    {
        return $this->commandService->deductFrozenAmountInCurrentTransaction($merchantId, $amount, $bizNo, $idempotencyKey, $extJson, $traceNo);
    }

    public function releaseFrozenAmount(int $merchantId, int $amount, string $bizNo, string $idempotencyKey, array $extJson = [], string $traceNo = ''): MerchantAccountLedger
    {
        return $this->commandService->releaseFrozenAmount($merchantId, $amount, $bizNo, $idempotencyKey, $extJson, $traceNo);
    }

    public function releaseFrozenAmountInCurrentTransaction(int $merchantId, int $amount, string $bizNo, string $idempotencyKey, array $extJson = [], string $traceNo = ''): MerchantAccountLedger
    {
        return $this->commandService->releaseFrozenAmountInCurrentTransaction($merchantId, $amount, $bizNo, $idempotencyKey, $extJson, $traceNo);
    }

    public function creditAvailableAmount(int $merchantId, int $amount, string $bizNo, string $idempotencyKey, array $extJson = [], string $traceNo = ''): MerchantAccountLedger
    {
        return $this->commandService->creditAvailableAmount($merchantId, $amount, $bizNo, $idempotencyKey, $extJson, $traceNo);
    }

    public function creditAvailableAmountInCurrentTransaction(int $merchantId, int $amount, string $bizNo, string $idempotencyKey, array $extJson = [], string $traceNo = ''): MerchantAccountLedger
    {
        return $this->commandService->creditAvailableAmountInCurrentTransaction($merchantId, $amount, $bizNo, $idempotencyKey, $extJson, $traceNo);
    }

    public function debitAvailableAmount(int $merchantId, int $amount, string $bizNo, string $idempotencyKey, array $extJson = [], string $traceNo = ''): MerchantAccountLedger
    {
        return $this->commandService->debitAvailableAmount($merchantId, $amount, $bizNo, $idempotencyKey, $extJson, $traceNo);
    }

    public function debitAvailableAmountInCurrentTransaction(int $merchantId, int $amount, string $bizNo, string $idempotencyKey, array $extJson = [], string $traceNo = ''): MerchantAccountLedger
    {
        return $this->commandService->debitAvailableAmountInCurrentTransaction($merchantId, $amount, $bizNo, $idempotencyKey, $extJson, $traceNo);
    }

    public function getBalanceSnapshot(int $merchantId): array
    {
        return $this->queryService->getBalanceSnapshot($merchantId);
    }

    public function findById(int $id): ?MerchantAccount
    {
        return $this->queryService->findById($id);
    }
}
