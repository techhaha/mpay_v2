<?php

namespace app\service\account\funds;

use app\common\base\BaseService;
use app\common\constant\FundFreezeConstant;
use app\common\constant\LedgerConstant;
use app\exception\BalanceInsufficientException;
use app\exception\ConflictException;
use app\exception\ValidationException;
use app\model\merchant\MerchantAccount;
use app\model\merchant\MerchantAccountLedger;
use app\repository\account\balance\MerchantAccountRepository;
use app\repository\account\freeze\MerchantFundFreezeRepository;
use app\repository\account\ledger\MerchantAccountLedgerRepository;

/**
 * 商户账户命令服务。
 *
 * 只负责账户创建、冻结、扣减、释放和入账等资金变更。
 *
 * @property MerchantAccountRepository $accountRepository 账户仓库
 * @property MerchantFundFreezeRepository $fundFreezeRepository 资金冻结仓库
 * @property MerchantAccountLedgerRepository $ledgerRepository 流水仓库
 */
class MerchantAccountCommandService extends BaseService
{
    /**
     * 构造方法。
     *
     * @param MerchantAccountRepository $accountRepository 账户仓库
     * @param MerchantFundFreezeRepository $fundFreezeRepository 资金冻结仓库
     * @param MerchantAccountLedgerRepository $ledgerRepository 流水仓库
     * @return void
     */
    public function __construct(
        protected MerchantAccountRepository $accountRepository,
        protected MerchantFundFreezeRepository $fundFreezeRepository,
        protected MerchantAccountLedgerRepository $ledgerRepository
    ) {
    }

    /**
     * 获取或创建商户账户。
     *
     * @param int $merchantId 商户ID
     * @return MerchantAccount 账户记录
     */
    public function ensureAccount(int $merchantId): MerchantAccount
    {
        return $this->transactionRetry(function () use ($merchantId) {
            return $this->ensureAccountInCurrentTransaction($merchantId);
        });
    }

    /**
     * 在当前事务中获取或创建商户账户。
     *
     * @param int $merchantId 商户ID
     * @return MerchantAccount 账户记录
     * @throws ValidationException
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
        return $this->transactionRetry(function () use ($merchantId, $amount, $bizNo, $idempotencyKey, $extJson, $traceNo) {
            return $this->freezeAmountInCurrentTransaction($merchantId, $amount, $bizNo, $idempotencyKey, $extJson, $traceNo);
        });
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
     * @throws ValidationException
     * @throws BalanceInsufficientException
     */
    public function freezeAmountInCurrentTransaction(int $merchantId, int $amount, string $bizNo, string $idempotencyKey, array $extJson = [], string $traceNo = ''): MerchantAccountLedger
    {
        return $this->freezeAvailableByBizTypeInCurrentTransaction(
            $merchantId,
            $amount,
            $bizNo,
            $idempotencyKey,
            LedgerConstant::BIZ_TYPE_PAY_FREEZE,
            $extJson['remark'] ?? '余额冻结',
            $extJson,
            $traceNo
        );
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
        return $this->freezeAvailableByBizTypeInCurrentTransaction(
            $merchantId,
            $amount,
            $bizNo,
            $idempotencyKey,
            LedgerConstant::BIZ_TYPE_RISK_FREEZE,
            $extJson['remark'] ?? '风控资金冻结',
            $extJson,
            $traceNo
        );
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
        return $this->transactionRetry(function () use ($merchantId, $amount, $bizNo, $idempotencyKey, $extJson, $traceNo) {
            return $this->deductFrozenAmountInCurrentTransaction($merchantId, $amount, $bizNo, $idempotencyKey, $extJson, $traceNo);
        });
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
     * @throws ValidationException
     */
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

        $this->reduceFundFreezeRecordIfNeeded($merchantId, $amount, $bizNo, LedgerConstant::BIZ_TYPE_PAY_DEDUCT, $extJson);

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
        ]);
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
        return $this->transactionRetry(function () use ($merchantId, $amount, $bizNo, $idempotencyKey, $extJson, $traceNo) {
            return $this->releaseFrozenAmountInCurrentTransaction($merchantId, $amount, $bizNo, $idempotencyKey, $extJson, $traceNo);
        });
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
     * @throws ValidationException
     */
    public function releaseFrozenAmountInCurrentTransaction(int $merchantId, int $amount, string $bizNo, string $idempotencyKey, array $extJson = [], string $traceNo = ''): MerchantAccountLedger
    {
        return $this->releaseFrozenByBizTypeInCurrentTransaction(
            $merchantId,
            $amount,
            $bizNo,
            $idempotencyKey,
            LedgerConstant::BIZ_TYPE_PAY_RELEASE,
            $extJson['remark'] ?? '冻结余额释放',
            $extJson,
            $traceNo
        );
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
        return $this->releaseFrozenByBizTypeInCurrentTransaction(
            $merchantId,
            $amount,
            $bizNo,
            $idempotencyKey,
            LedgerConstant::BIZ_TYPE_RISK_RELEASE,
            $extJson['remark'] ?? '风控冻结释放',
            $extJson,
            $traceNo
        );
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
        return $this->transactionRetry(function () use ($merchantId, $amount, $bizNo, $idempotencyKey, $extJson, $traceNo) {
            return $this->creditAvailableAmountInCurrentTransaction($merchantId, $amount, $bizNo, $idempotencyKey, $extJson, $traceNo);
        });
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
     * @throws ValidationException
     */
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
        ]);
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
        return $this->transactionRetry(function () use ($merchantId, $amount, $bizNo, $idempotencyKey, $extJson, $traceNo) {
            return $this->debitAvailableAmountInCurrentTransaction($merchantId, $amount, $bizNo, $idempotencyKey, $extJson, $traceNo);
        });
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
     * @throws ValidationException
     * @throws BalanceInsufficientException
     */
    public function debitAvailableAmountInCurrentTransaction(int $merchantId, int $amount, string $bizNo, string $idempotencyKey, array $extJson = [], string $traceNo = ''): MerchantAccountLedger
    {
        return $this->debitAvailableByBizTypeInCurrentTransaction(
            $merchantId,
            $amount,
            $bizNo,
            $idempotencyKey,
            LedgerConstant::BIZ_TYPE_REFUND_REVERSE,
            LedgerConstant::EVENT_TYPE_REVERSE,
            $extJson['remark'] ?? '余额冲减',
            $extJson,
            $traceNo
        );
    }

    /**
     * 在当前事务中扣减支付平台服务费。
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
        return $this->debitAvailableByBizTypeInCurrentTransaction(
            $merchantId,
            $amount,
            $bizNo,
            $idempotencyKey,
            LedgerConstant::BIZ_TYPE_PAY_DEDUCT,
            LedgerConstant::EVENT_TYPE_SUCCESS,
            $extJson['remark'] ?? '自收通道服务费扣减',
            $extJson,
            $traceNo
        );
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
        return $this->debitAvailableByBizTypeInCurrentTransaction(
            $merchantId,
            $amount,
            $bizNo,
            $idempotencyKey,
            LedgerConstant::BIZ_TYPE_TRANSFER_DEDUCT,
            LedgerConstant::EVENT_TYPE_CREATE,
            $extJson['remark'] ?? '转账本金扣减',
            $extJson,
            $traceNo
        );
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
        return $this->debitAvailableByBizTypeInCurrentTransaction(
            $merchantId,
            $amount,
            $bizNo,
            $idempotencyKey,
            LedgerConstant::BIZ_TYPE_TRANSFER_FEE,
            LedgerConstant::EVENT_TYPE_CREATE,
            $extJson['remark'] ?? '转账手续费扣减',
            $extJson,
            $traceNo
        );
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
        return $this->creditAvailableByBizTypeInCurrentTransaction(
            $merchantId,
            $amount,
            $bizNo,
            $idempotencyKey,
            LedgerConstant::BIZ_TYPE_TRANSFER_RELEASE,
            LedgerConstant::EVENT_TYPE_REVERSE,
            $extJson['remark'] ?? '转账失败释放',
            $extJson,
            $traceNo
        );
    }

    /**
     * 按指定业务类型冻结可用余额。
     *
     * @param int $merchantId 商户ID
     * @param int $amount 金额（分）
     * @param string $bizNo 业务单号
     * @param string $idempotencyKey 幂等键
     * @param int $bizType 流水业务类型
     * @param string $remark 流水备注
     * @param array $extJson 扩展字段
     * @param string $traceNo 追踪号
     * @return MerchantAccountLedger 流水记录
     */
    private function freezeAvailableByBizTypeInCurrentTransaction(
        int $merchantId,
        int $amount,
        string $bizNo,
        string $idempotencyKey,
        int $bizType,
        string $remark,
        array $extJson = [],
        string $traceNo = ''
    ): MerchantAccountLedger {
        $this->assertPositiveAmount($amount);
        if ($idempotencyKey === '') {
            throw new ValidationException('幂等键不能为空');
        }

        if ($existing = $this->findLedgerByIdempotencyKey($idempotencyKey)) {
            $this->assertLedgerMatch($existing, $bizType, $bizNo, $amount, LedgerConstant::DIRECTION_OUT);
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

        $this->createFundFreezeRecordIfNeeded($merchantId, $amount, $bizNo, $bizType, $remark, $extJson, $traceNo);

        return $this->createLedger([
            'merchant_id' => $merchantId,
            'biz_type' => $bizType,
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
            'remark' => $remark,
        ]);
    }

    /**
     * 按指定业务类型释放冻结余额。
     *
     * @param int $merchantId 商户ID
     * @param int $amount 金额（分）
     * @param string $bizNo 业务单号
     * @param string $idempotencyKey 幂等键
     * @param int $bizType 流水业务类型
     * @param string $remark 流水备注
     * @param array $extJson 扩展字段
     * @param string $traceNo 追踪号
     * @return MerchantAccountLedger 流水记录
     */
    private function releaseFrozenByBizTypeInCurrentTransaction(
        int $merchantId,
        int $amount,
        string $bizNo,
        string $idempotencyKey,
        int $bizType,
        string $remark,
        array $extJson = [],
        string $traceNo = ''
    ): MerchantAccountLedger {
        $this->assertPositiveAmount($amount);
        if ($idempotencyKey === '') {
            throw new ValidationException('幂等键不能为空');
        }

        if ($existing = $this->findLedgerByIdempotencyKey($idempotencyKey)) {
            $this->assertLedgerMatch($existing, $bizType, $bizNo, $amount, LedgerConstant::DIRECTION_IN);
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

        $this->reduceFundFreezeRecordIfNeeded($merchantId, $amount, $bizNo, $bizType, $extJson);

        return $this->createLedger([
            'merchant_id' => $merchantId,
            'biz_type' => $bizType,
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
            'remark' => $remark,
        ]);
    }

    /**
     * 按指定业务类型扣减可用余额。
     *
     * @param int $merchantId 商户ID
     * @param int $amount 金额（分）
     * @param string $bizNo 业务单号
     * @param string $idempotencyKey 幂等键
     * @param int $bizType 流水业务类型
     * @param int $eventType 流水事件类型
     * @param string $remark 流水备注
     * @param array $extJson 扩展字段
     * @param string $traceNo 追踪号
     * @return MerchantAccountLedger 流水记录
     */
    private function debitAvailableByBizTypeInCurrentTransaction(
        int $merchantId,
        int $amount,
        string $bizNo,
        string $idempotencyKey,
        int $bizType,
        int $eventType,
        string $remark,
        array $extJson = [],
        string $traceNo = ''
    ): MerchantAccountLedger {
        $this->assertPositiveAmount($amount);
        if ($idempotencyKey === '') {
            throw new ValidationException('幂等键不能为空');
        }

        if ($existing = $this->findLedgerByIdempotencyKey($idempotencyKey)) {
            $this->assertLedgerMatch($existing, $bizType, $bizNo, $amount, LedgerConstant::DIRECTION_OUT);
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
            'biz_type' => $bizType,
            'biz_no' => $bizNo,
            'trace_no' => $this->normalizeTraceNo($traceNo, $bizNo),
            'event_type' => $eventType,
            'direction' => LedgerConstant::DIRECTION_OUT,
            'amount' => $amount,
            'available_before' => $availableBefore,
            'available_after' => (int) $account->available_balance,
            'frozen_before' => $frozenBefore,
            'frozen_after' => (int) $account->frozen_balance,
            'idempotency_key' => $idempotencyKey,
            'remark' => $remark,
        ]);
    }

    /**
     * 按指定业务类型增加可用余额。
     *
     * @param int $merchantId 商户ID
     * @param int $amount 金额（分）
     * @param string $bizNo 业务单号
     * @param string $idempotencyKey 幂等键
     * @param int $bizType 流水业务类型
     * @param int $eventType 流水事件类型
     * @param string $remark 流水备注
     * @param array $extJson 扩展字段
     * @param string $traceNo 追踪号
     * @return MerchantAccountLedger 流水记录
     */
    private function creditAvailableByBizTypeInCurrentTransaction(
        int $merchantId,
        int $amount,
        string $bizNo,
        string $idempotencyKey,
        int $bizType,
        int $eventType,
        string $remark,
        array $extJson = [],
        string $traceNo = ''
    ): MerchantAccountLedger {
        $this->assertPositiveAmount($amount);
        if ($idempotencyKey === '') {
            throw new ValidationException('幂等键不能为空');
        }

        if ($existing = $this->findLedgerByIdempotencyKey($idempotencyKey)) {
            $this->assertLedgerMatch($existing, $bizType, $bizNo, $amount, LedgerConstant::DIRECTION_IN);
            return $existing;
        }

        $account = $this->ensureAccountInCurrentTransaction($merchantId);
        $availableBefore = (int) $account->available_balance;
        $frozenBefore = (int) $account->frozen_balance;

        $account->available_balance = $availableBefore + $amount;
        $account->save();

        return $this->createLedger([
            'merchant_id' => $merchantId,
            'biz_type' => $bizType,
            'biz_no' => $bizNo,
            'trace_no' => $this->normalizeTraceNo($traceNo, $bizNo),
            'event_type' => $eventType,
            'direction' => LedgerConstant::DIRECTION_IN,
            'amount' => $amount,
            'available_before' => $availableBefore,
            'available_after' => (int) $account->available_balance,
            'frozen_before' => $frozenBefore,
            'frozen_after' => (int) $account->frozen_balance,
            'idempotency_key' => $idempotencyKey,
            'remark' => $remark,
        ]);
    }

    /**
     * 为会进入账户冻结余额的业务创建冻结明细。
     *
     * @param int $merchantId 商户ID
     * @param int $amount 金额（分）
     * @param string $bizNo 业务单号
     * @param int $bizType 账户流水业务类型
     * @param string $remark 备注
     * @param array $extJson 扩展字段
     * @param string $traceNo 追踪号
     * @return void
     */
    private function createFundFreezeRecordIfNeeded(int $merchantId, int $amount, string $bizNo, int $bizType, string $remark, array $extJson = [], string $traceNo = ''): void
    {
        if ($bizType !== LedgerConstant::BIZ_TYPE_PAY_FREEZE) {
            return;
        }

        $payNo = trim((string) ($extJson['pay_no'] ?? $bizNo));
        if ($payNo === '') {
            return;
        }

        if ($this->fundFreezeRepository->firstActiveForUpdateByPayNoAndType($payNo, FundFreezeConstant::TYPE_PAY_FEE, $this->now())) {
            return;
        }

        $this->fundFreezeRepository->create([
            'freeze_no' => (string) ($extJson['freeze_no'] ?? $this->generateNo('FRZ')),
            'merchant_id' => $merchantId,
            'biz_no' => $bizNo,
            'pay_no' => $payNo,
            'trace_no' => $this->normalizeTraceNo($traceNo, $bizNo),
            'freeze_type' => FundFreezeConstant::TYPE_PAY_FEE,
            'freeze_amount' => $amount,
            'remaining_amount' => $amount,
            'status' => FundFreezeConstant::STATUS_ACTIVE,
            'reason' => $remark,
            'admin_id' => 0,
            'available_at' => null,
            'frozen_at' => $this->now(),
            'release_reason' => '',
            'released_by' => 0,
            'released_at' => null,
        ]);
    }

    /**
     * 账户冻结余额减少时，同步扣减冻结明细剩余金额。
     *
     * @param int $merchantId 商户ID
     * @param int $amount 金额（分）
     * @param string $bizNo 业务单号
     * @param int $bizType 账户流水业务类型
     * @param array $extJson 扩展字段
     * @return void
     */
    private function reduceFundFreezeRecordIfNeeded(int $merchantId, int $amount, string $bizNo, int $bizType, array $extJson = []): void
    {
        if (!in_array($bizType, [LedgerConstant::BIZ_TYPE_PAY_DEDUCT, LedgerConstant::BIZ_TYPE_PAY_RELEASE], true)) {
            return;
        }

        $payNo = trim((string) ($extJson['pay_no'] ?? $bizNo));
        if ($payNo === '') {
            return;
        }

        $freeze = $this->fundFreezeRepository->firstActiveForUpdateByPayNoAndType($payNo, FundFreezeConstant::TYPE_PAY_FEE, $this->now());
        if (!$freeze) {
            return;
        }

        $remainingAmount = max(0, (int) $freeze->remaining_amount - $amount);
        $freeze->remaining_amount = $remainingAmount;
        if ($remainingAmount === 0) {
            $freeze->status = FundFreezeConstant::STATUS_RELEASED;
            $freeze->release_reason = (string) ($extJson['remark'] ?? '支付服务费冻结释放');
            $freeze->released_by = 0;
            $freeze->released_at = $this->now();
        }
        $freeze->save();
    }

    /**
     * 创建账户流水。
     *
     * @param array $data 流水数据
     * @return MerchantAccountLedger 流水记录
     */
    private function createLedger(array $data): MerchantAccountLedger
    {
        unset($data['ext_json']);
        $data['ledger_no'] = $data['ledger_no'] ?? $this->generateNo('LG');
        $data['trace_no'] = trim((string) ($data['trace_no'] ?? $data['biz_no'] ?? ''));
        $data['created_at'] = $data['created_at'] ?? $this->now();

        return $this->ledgerRepository->create($data);
    }

    /**
     * 按幂等键查询流水。
     *
     * @param string $idempotencyKey 幂等键
     * @return MerchantAccountLedger|null 流水记录
     */
    private function findLedgerByIdempotencyKey(string $idempotencyKey): ?MerchantAccountLedger
    {
        return $this->ledgerRepository->findByIdempotencyKey($idempotencyKey);
    }

    /**
     * 校验金额必须大于 0。
     *
     * @param int $amount 金额（分）
     * @return void
     * @throws ValidationException
     */
    private function assertPositiveAmount(int $amount): void
    {
        if ($amount <= 0) {
            throw new ValidationException('金额必须大于 0');
        }
    }

    /**
     * 校验幂等流水与当前请求一致。
     *
     * @param MerchantAccountLedger $ledger 流水
     * @param int $bizType 业务类型
     * @param string $bizNo 业务单号
     * @param int $amount 金额（分）
     * @param int $direction 流向
     * @return void
     * @throws ConflictException
     */
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

    /**
     * 归一化追踪号。
     *
     * @param string $traceNo 追踪号
     * @param string $bizNo 业务单号
     * @return string 追踪号
     */
    private function normalizeTraceNo(string $traceNo, string $bizNo): string
    {
        $traceNo = trim($traceNo);
        if ($traceNo !== '') {
            return $traceNo;
        }

        return $bizNo;
    }
}


