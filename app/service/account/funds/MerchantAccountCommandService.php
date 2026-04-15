<?php

namespace app\service\account\funds;

use app\common\base\BaseService;
use app\common\constant\LedgerConstant;
use app\exception\BalanceInsufficientException;
use app\exception\ConflictException;
use app\exception\ValidationException;
use app\model\merchant\MerchantAccount;
use app\model\merchant\MerchantAccountLedger;
use app\repository\account\balance\MerchantAccountRepository;
use app\repository\account\ledger\MerchantAccountLedgerRepository;

/**
 * 商户账户命令服务。
 *
 * 只负责账户创建、冻结、扣减、释放和入账等资金变更。
 */
class MerchantAccountCommandService extends BaseService
{
    public function __construct(
        protected MerchantAccountRepository $accountRepository,
        protected MerchantAccountLedgerRepository $ledgerRepository
    ) {
    }

    /**
     * 获取或创建商户账户。
     */
    public function ensureAccount(int $merchantId): MerchantAccount
    {
        return $this->transactionRetry(function () use ($merchantId) {
            return $this->ensureAccountInCurrentTransaction($merchantId);
        });
    }

    /**
     * 在当前事务中获取或创建商户账户。
     */
    public function ensureAccountInCurrentTransaction(int $merchantId): MerchantAccount
    {
        $account = $this->accountRepository->findForUpdateByMerchantId($merchantId);
        if ($account) {
            return $account;
        }

        $this->accountRepository->create([
            'merchant_id' => $merchantId,
            'available_balance' => 0,
            'frozen_balance' => 0,
        ]);

        $account = $this->accountRepository->findForUpdateByMerchantId($merchantId);
        if (!$account) {
            throw new ValidationException('商户账户创建失败', ['merchant_id' => $merchantId]);
        }

        return $account;
    }

    public function freezeAmount(int $merchantId, int $amount, string $bizNo, string $idempotencyKey, array $extJson = [], string $traceNo = ''): MerchantAccountLedger
    {
        return $this->transactionRetry(function () use ($merchantId, $amount, $bizNo, $idempotencyKey, $extJson, $traceNo) {
            return $this->freezeAmountInCurrentTransaction($merchantId, $amount, $bizNo, $idempotencyKey, $extJson, $traceNo);
        });
    }

    public function freezeAmountInCurrentTransaction(int $merchantId, int $amount, string $bizNo, string $idempotencyKey, array $extJson = [], string $traceNo = ''): MerchantAccountLedger
    {
        $this->assertPositiveAmount($amount);
        if ($idempotencyKey === '') {
            throw new ValidationException('幂等键不能为空');
        }

        if ($existing = $this->findLedgerByIdempotencyKey($idempotencyKey)) {
            $this->assertLedgerMatch($existing, LedgerConstant::BIZ_TYPE_PAY_FREEZE, $bizNo, $amount, LedgerConstant::DIRECTION_OUT);
            return $existing;
        }

        $account = $this->ensureAccountInCurrentTransaction($merchantId);
        if ((int) $account->available_balance < $amount) {
            throw new BalanceInsufficientException($merchantId, $amount, (int) $account->available_balance);
        }

        $availableBefore = (int) $account->available_balance;
        $frozenBefore = (int) $account->frozen_balance;

        $account->available_balance = $availableBefore - $amount;
        $account->frozen_balance = $frozenBefore + $amount;
        $account->save();

        return $this->createLedger([
            'merchant_id' => $merchantId,
            'biz_type' => LedgerConstant::BIZ_TYPE_PAY_FREEZE,
            'biz_no' => $bizNo,
            'trace_no' => $this->normalizeTraceNo($traceNo, $bizNo),
            'event_type' => LedgerConstant::EVENT_TYPE_CREATE,
            'direction' => LedgerConstant::DIRECTION_OUT,
            'amount' => $amount,
            'available_before' => $availableBefore,
            'available_after' => (int) $account->available_balance,
            'frozen_before' => $frozenBefore,
            'frozen_after' => (int) $account->frozen_balance,
            'idempotency_key' => $idempotencyKey,
            'remark' => $extJson['remark'] ?? '余额冻结',
            'ext_json' => $extJson,
        ]);
    }

    public function deductFrozenAmount(int $merchantId, int $amount, string $bizNo, string $idempotencyKey, array $extJson = [], string $traceNo = ''): MerchantAccountLedger
    {
        return $this->transactionRetry(function () use ($merchantId, $amount, $bizNo, $idempotencyKey, $extJson, $traceNo) {
            return $this->deductFrozenAmountInCurrentTransaction($merchantId, $amount, $bizNo, $idempotencyKey, $extJson, $traceNo);
        });
    }

    public function deductFrozenAmountInCurrentTransaction(int $merchantId, int $amount, string $bizNo, string $idempotencyKey, array $extJson = [], string $traceNo = ''): MerchantAccountLedger
    {
        $this->assertPositiveAmount($amount);
        if ($idempotencyKey === '') {
            throw new ValidationException('幂等键不能为空');
        }

        if ($existing = $this->findLedgerByIdempotencyKey($idempotencyKey)) {
            $this->assertLedgerMatch($existing, LedgerConstant::BIZ_TYPE_PAY_DEDUCT, $bizNo, $amount, LedgerConstant::DIRECTION_OUT);
            return $existing;
        }

        $account = $this->ensureAccountInCurrentTransaction($merchantId);
        if ((int) $account->frozen_balance < $amount) {
            throw new ValidationException('冻结余额不足', [
                'merchant_id' => $merchantId,
                'amount' => $amount,
                'frozen_balance' => (int) $account->frozen_balance,
            ]);
        }

        $availableBefore = (int) $account->available_balance;
        $frozenBefore = (int) $account->frozen_balance;

        $account->frozen_balance = $frozenBefore - $amount;
        $account->save();

        return $this->createLedger([
            'merchant_id' => $merchantId,
            'biz_type' => LedgerConstant::BIZ_TYPE_PAY_DEDUCT,
            'biz_no' => $bizNo,
            'trace_no' => $this->normalizeTraceNo($traceNo, $bizNo),
            'event_type' => LedgerConstant::EVENT_TYPE_SUCCESS,
            'direction' => LedgerConstant::DIRECTION_OUT,
            'amount' => $amount,
            'available_before' => $availableBefore,
            'available_after' => (int) $account->available_balance,
            'frozen_before' => $frozenBefore,
            'frozen_after' => (int) $account->frozen_balance,
            'idempotency_key' => $idempotencyKey,
            'remark' => $extJson['remark'] ?? '余额扣减',
            'ext_json' => $extJson,
        ]);
    }

    public function releaseFrozenAmount(int $merchantId, int $amount, string $bizNo, string $idempotencyKey, array $extJson = [], string $traceNo = ''): MerchantAccountLedger
    {
        return $this->transactionRetry(function () use ($merchantId, $amount, $bizNo, $idempotencyKey, $extJson, $traceNo) {
            return $this->releaseFrozenAmountInCurrentTransaction($merchantId, $amount, $bizNo, $idempotencyKey, $extJson, $traceNo);
        });
    }

    public function releaseFrozenAmountInCurrentTransaction(int $merchantId, int $amount, string $bizNo, string $idempotencyKey, array $extJson = [], string $traceNo = ''): MerchantAccountLedger
    {
        $this->assertPositiveAmount($amount);
        if ($idempotencyKey === '') {
            throw new ValidationException('幂等键不能为空');
        }

        if ($existing = $this->findLedgerByIdempotencyKey($idempotencyKey)) {
            $this->assertLedgerMatch($existing, LedgerConstant::BIZ_TYPE_PAY_RELEASE, $bizNo, $amount, LedgerConstant::DIRECTION_IN);
            return $existing;
        }

        $account = $this->ensureAccountInCurrentTransaction($merchantId);
        if ((int) $account->frozen_balance < $amount) {
            throw new ValidationException('冻结余额不足', [
                'merchant_id' => $merchantId,
                'amount' => $amount,
                'frozen_balance' => (int) $account->frozen_balance,
            ]);
        }

        $availableBefore = (int) $account->available_balance;
        $frozenBefore = (int) $account->frozen_balance;

        $account->available_balance = $availableBefore + $amount;
        $account->frozen_balance = $frozenBefore - $amount;
        $account->save();

        return $this->createLedger([
            'merchant_id' => $merchantId,
            'biz_type' => LedgerConstant::BIZ_TYPE_PAY_RELEASE,
            'biz_no' => $bizNo,
            'trace_no' => $this->normalizeTraceNo($traceNo, $bizNo),
            'event_type' => LedgerConstant::EVENT_TYPE_REVERSE,
            'direction' => LedgerConstant::DIRECTION_IN,
            'amount' => $amount,
            'available_before' => $availableBefore,
            'available_after' => (int) $account->available_balance,
            'frozen_before' => $frozenBefore,
            'frozen_after' => (int) $account->frozen_balance,
            'idempotency_key' => $idempotencyKey,
            'remark' => $extJson['remark'] ?? '冻结余额释放',
            'ext_json' => $extJson,
        ]);
    }

    public function creditAvailableAmount(int $merchantId, int $amount, string $bizNo, string $idempotencyKey, array $extJson = [], string $traceNo = ''): MerchantAccountLedger
    {
        return $this->transactionRetry(function () use ($merchantId, $amount, $bizNo, $idempotencyKey, $extJson, $traceNo) {
            return $this->creditAvailableAmountInCurrentTransaction($merchantId, $amount, $bizNo, $idempotencyKey, $extJson, $traceNo);
        });
    }

    public function creditAvailableAmountInCurrentTransaction(int $merchantId, int $amount, string $bizNo, string $idempotencyKey, array $extJson = [], string $traceNo = ''): MerchantAccountLedger
    {
        $this->assertPositiveAmount($amount);
        if ($idempotencyKey === '') {
            throw new ValidationException('幂等键不能为空');
        }

        if ($existing = $this->findLedgerByIdempotencyKey($idempotencyKey)) {
            $this->assertLedgerMatch($existing, LedgerConstant::BIZ_TYPE_SETTLEMENT_CREDIT, $bizNo, $amount, LedgerConstant::DIRECTION_IN);
            return $existing;
        }

        $account = $this->ensureAccountInCurrentTransaction($merchantId);
        $availableBefore = (int) $account->available_balance;
        $frozenBefore = (int) $account->frozen_balance;

        $account->available_balance = $availableBefore + $amount;
        $account->save();

        return $this->createLedger([
            'merchant_id' => $merchantId,
            'biz_type' => LedgerConstant::BIZ_TYPE_SETTLEMENT_CREDIT,
            'biz_no' => $bizNo,
            'trace_no' => $this->normalizeTraceNo($traceNo, $bizNo),
            'event_type' => LedgerConstant::EVENT_TYPE_SUCCESS,
            'direction' => LedgerConstant::DIRECTION_IN,
            'amount' => $amount,
            'available_before' => $availableBefore,
            'available_after' => (int) $account->available_balance,
            'frozen_before' => $frozenBefore,
            'frozen_after' => (int) $account->frozen_balance,
            'idempotency_key' => $idempotencyKey,
            'remark' => $extJson['remark'] ?? '清算入账',
            'ext_json' => $extJson,
        ]);
    }

    public function debitAvailableAmount(int $merchantId, int $amount, string $bizNo, string $idempotencyKey, array $extJson = [], string $traceNo = ''): MerchantAccountLedger
    {
        return $this->transactionRetry(function () use ($merchantId, $amount, $bizNo, $idempotencyKey, $extJson, $traceNo) {
            return $this->debitAvailableAmountInCurrentTransaction($merchantId, $amount, $bizNo, $idempotencyKey, $extJson, $traceNo);
        });
    }

    public function debitAvailableAmountInCurrentTransaction(int $merchantId, int $amount, string $bizNo, string $idempotencyKey, array $extJson = [], string $traceNo = ''): MerchantAccountLedger
    {
        $this->assertPositiveAmount($amount);
        if ($idempotencyKey === '') {
            throw new ValidationException('幂等键不能为空');
        }

        if ($existing = $this->findLedgerByIdempotencyKey($idempotencyKey)) {
            $this->assertLedgerMatch($existing, LedgerConstant::BIZ_TYPE_REFUND_REVERSE, $bizNo, $amount, LedgerConstant::DIRECTION_OUT);
            return $existing;
        }

        $account = $this->ensureAccountInCurrentTransaction($merchantId);
        if ((int) $account->available_balance < $amount) {
            throw new BalanceInsufficientException($merchantId, $amount, (int) $account->available_balance);
        }

        $availableBefore = (int) $account->available_balance;
        $frozenBefore = (int) $account->frozen_balance;

        $account->available_balance = $availableBefore - $amount;
        $account->save();

        return $this->createLedger([
            'merchant_id' => $merchantId,
            'biz_type' => LedgerConstant::BIZ_TYPE_REFUND_REVERSE,
            'biz_no' => $bizNo,
            'trace_no' => $this->normalizeTraceNo($traceNo, $bizNo),
            'event_type' => LedgerConstant::EVENT_TYPE_REVERSE,
            'direction' => LedgerConstant::DIRECTION_OUT,
            'amount' => $amount,
            'available_before' => $availableBefore,
            'available_after' => (int) $account->available_balance,
            'frozen_before' => $frozenBefore,
            'frozen_after' => (int) $account->frozen_balance,
            'idempotency_key' => $idempotencyKey,
            'remark' => $extJson['remark'] ?? '余额冲减',
            'ext_json' => $extJson,
        ]);
    }

    private function createLedger(array $data): MerchantAccountLedger
    {
        $data['ledger_no'] = $data['ledger_no'] ?? $this->generateNo('LG');
        $data['trace_no'] = trim((string) ($data['trace_no'] ?? $data['biz_no'] ?? ''));
        $data['created_at'] = $data['created_at'] ?? $this->now();

        return $this->ledgerRepository->create($data);
    }

    private function findLedgerByIdempotencyKey(string $idempotencyKey): ?MerchantAccountLedger
    {
        return $this->ledgerRepository->findByIdempotencyKey($idempotencyKey);
    }

    private function assertPositiveAmount(int $amount): void
    {
        if ($amount <= 0) {
            throw new ValidationException('金额必须大于 0');
        }
    }

    private function assertLedgerMatch(MerchantAccountLedger $ledger, int $bizType, string $bizNo, int $amount, int $direction): void
    {
        if ((int) $ledger->biz_type !== $bizType || (int) $ledger->amount !== $amount || (string) $ledger->biz_no !== $bizNo || (int) $ledger->direction !== $direction) {
            throw new ConflictException('幂等冲突', [
                'ledger_no' => (string) $ledger->ledger_no,
                'biz_type' => $bizType,
                'biz_no' => $bizNo,
            ]);
        }
    }

    private function normalizeTraceNo(string $traceNo, string $bizNo): string
    {
        $traceNo = trim($traceNo);
        if ($traceNo !== '') {
            return $traceNo;
        }

        return $bizNo;
    }
}
