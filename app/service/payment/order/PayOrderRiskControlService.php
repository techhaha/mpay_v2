<?php

namespace app\service\payment\order;

use app\common\base\BaseService;
use app\common\constant\FundFreezeConstant;
use app\common\constant\PayOrderActionConstant;
use app\exception\BusinessStateException;
use app\exception\ResourceNotFoundException;
use app\exception\ValidationException;
use app\model\merchant\MerchantFundFreeze;
use app\model\payment\PayOrder;
use app\repository\account\freeze\MerchantFundFreezeRepository;
use app\repository\payment\trade\PayOrderRepository;
use app\service\account\funds\MerchantAccountService;

/**
 * 支付订单风控操作服务。
 *
 * 订单冻结以资金冻结明细为准，同步更新商户账户余额和账户流水，保证冻结账务可对账。
 */
class PayOrderRiskControlService extends BaseService
{
    /**
     * 构造方法。
     *
     * @param PayOrderRepository $payOrderRepository 支付单仓库
     * @param MerchantFundFreezeRepository $fundFreezeRepository 资金冻结仓库
     * @param MerchantAccountService $merchantAccountService 商户账户服务
     * @return void
     */
    public function __construct(
        protected PayOrderRepository $payOrderRepository,
        protected MerchantFundFreezeRepository $fundFreezeRepository,
        protected MerchantAccountService $merchantAccountService
    ) {
    }

    /**
     * 判断支付单是否存在有效冻结。
     *
     * @param PayOrder|array<string, mixed>|null $payOrder 支付单模型或列表行
     * @return bool 是否冻结
     */
    public function isFrozen(PayOrder|array|null $payOrder): bool
    {
        if (is_array($payOrder) && array_key_exists('freeze_no', $payOrder)) {
            return trim((string) ($payOrder['freeze_no'] ?? '')) !== '';
        }

        $payNo = $this->extractPayNo($payOrder);
        if ($payNo === '') {
            return false;
        }

        return $this->fundFreezeRepository->existsActiveByPayNo($payNo, $this->now());
    }

    /**
     * 获取冻结展示信息。
     *
     * @param PayOrder|array<string, mixed>|null $payOrder 支付单模型或列表行
     * @return array<string, mixed> 冻结信息
     */
    public function freezeInfo(PayOrder|array|null $payOrder): array
    {
        if (is_array($payOrder) && trim((string) ($payOrder['freeze_no'] ?? '')) !== '') {
            return $this->freezeInfoFromRow($payOrder);
        }

        $payNo = $this->extractPayNo($payOrder);
        $freeze = $payNo !== '' ? $this->fundFreezeRepository->firstActiveByPayNo($payNo, $this->now()) : null;
        if (!$freeze) {
            return $this->normalFreezeInfo();
        }

        $status = PayOrderActionConstant::FREEZE_STATUS_FROZEN;
        $freezeType = (int) $freeze->freeze_type;

        return [
            'status' => $status,
            'status_text' => PayOrderActionConstant::freezeStatusMap()[$status] ?? '已冻结',
            'is_frozen' => true,
            'freeze_no' => (string) $freeze->freeze_no,
            'freeze_type' => $freezeType,
            'freeze_type_text' => FundFreezeConstant::typeMap()[$freezeType] ?? '未知',
            'amount' => (int) $freeze->remaining_amount,
            'amount_text' => $this->formatAmount((int) $freeze->remaining_amount),
            'reason' => (string) ($freeze->reason ?? ''),
            'admin_id' => (int) ($freeze->admin_id ?? 0),
            'available_at' => (string) ($freeze->available_at ?? ''),
            'available_at_text' => $this->formatDateTime($freeze->available_at ?? null, '—'),
            'frozen_at' => (string) ($freeze->frozen_at ?? ''),
            'frozen_at_text' => $this->formatDateTime($freeze->frozen_at ?? null, '—'),
            'unfreeze_reason' => (string) ($freeze->release_reason ?? ''),
            'unfrozen_by' => (int) ($freeze->released_by ?? 0),
            'unfrozen_at' => (string) ($freeze->released_at ?? ''),
            'unfrozen_at_text' => $this->formatDateTime($freeze->released_at ?? null, '—'),
        ];
    }

    /**
     * 从列表查询行拼装冻结展示信息。
     *
     * @param array<string, mixed> $row 支付单查询行
     * @return array<string, mixed> 冻结信息
     */
    private function freezeInfoFromRow(array $row): array
    {
        $status = PayOrderActionConstant::FREEZE_STATUS_FROZEN;
        $freezeType = (int) ($row['freeze_type'] ?? 0);
        $amount = (int) ($row['freeze_remaining_amount'] ?? 0);

        return [
            'status' => $status,
            'status_text' => PayOrderActionConstant::freezeStatusMap()[$status] ?? '已冻结',
            'is_frozen' => true,
            'freeze_no' => (string) ($row['freeze_no'] ?? ''),
            'freeze_type' => $freezeType,
            'freeze_type_text' => FundFreezeConstant::typeMap()[$freezeType] ?? '未知',
            'amount' => $amount,
            'amount_text' => $this->formatAmount($amount),
            'reason' => (string) ($row['freeze_reason'] ?? ''),
            'admin_id' => (int) ($row['freeze_admin_id'] ?? 0),
            'available_at' => (string) ($row['freeze_available_at'] ?? ''),
            'available_at_text' => $this->formatDateTime($row['freeze_available_at'] ?? null, '—'),
            'frozen_at' => (string) ($row['frozen_at'] ?? ''),
            'frozen_at_text' => $this->formatDateTime($row['frozen_at'] ?? null, '—'),
            'unfreeze_reason' => (string) ($row['unfreeze_reason'] ?? ''),
            'unfrozen_by' => (int) ($row['unfrozen_by'] ?? 0),
            'unfrozen_at' => (string) ($row['unfrozen_at'] ?? ''),
            'unfrozen_at_text' => $this->formatDateTime($row['unfrozen_at'] ?? null, '—'),
        ];
    }

    /**
     * 冻结支付单关联资金。
     *
     * @param string $payNo 支付单号
     * @param array<string, mixed> $input 冻结参数
     * @param int $adminId 管理员ID
     * @return PayOrder 支付单模型
     */
    public function freeze(string $payNo, array $input = [], int $adminId = 0): PayOrder
    {
        $payNo = trim($payNo);
        if ($payNo === '') {
            throw new ValidationException('pay_no 不能为空');
        }

        return $this->transactionRetry(function () use ($payNo, $input, $adminId): PayOrder {
            $payOrder = $this->payOrderRepository->findForUpdateByPayNo($payNo);
            if (!$payOrder) {
                throw new ResourceNotFoundException('支付单不存在', ['pay_no' => $payNo]);
            }

            if ($this->fundFreezeRepository->firstActiveForUpdateByPayNo($payNo, $this->now())) {
                return $payOrder->refresh();
            }

            $reason = trim((string) ($input['reason'] ?? ''));
            if ($reason === '') {
                throw new ValidationException('冻结原因不能为空');
            }

            $amount = $this->resolveFreezeAmount($input, $payOrder);
            $availableAt = $this->resolveAvailableAt($input);
            $freezeNo = $this->generateNo('FRZ');
            $traceNo = (string) ($payOrder->trace_no ?: $payOrder->pay_no);

            // 同一事务内先移动账户余额，再落冻结明细；任一失败都会整体回滚，保证账平。
            $this->merchantAccountService->freezeRiskAmountInCurrentTransaction(
                (int) $payOrder->merchant_id,
                $amount,
                $freezeNo,
                'RISK_FREEZE:' . $freezeNo,
                [
                    'freeze_no' => $freezeNo,
                    'pay_no' => (string) $payOrder->pay_no,
                    'biz_no' => (string) $payOrder->biz_no,
                    'remark' => '风控冻结支付单资金',
                ],
                $traceNo
            );

            $this->fundFreezeRepository->create([
                'freeze_no' => $freezeNo,
                'merchant_id' => (int) $payOrder->merchant_id,
                'biz_no' => (string) $payOrder->biz_no,
                'pay_no' => (string) $payOrder->pay_no,
                'trace_no' => $traceNo,
                'freeze_type' => FundFreezeConstant::TYPE_PAY_ORDER,
                'freeze_amount' => $amount,
                'remaining_amount' => $amount,
                'status' => FundFreezeConstant::STATUS_ACTIVE,
                'reason' => $reason,
                'admin_id' => $adminId,
                'available_at' => $availableAt,
                'frozen_at' => $this->now(),
                'release_reason' => '',
                'released_by' => 0,
                'released_at' => null,
            ]);

            return $payOrder->refresh();
        });
    }

    /**
     * 解冻支付单关联资金。
     *
     * @param string $payNo 支付单号
     * @param array<string, mixed> $input 解冻参数
     * @param int $adminId 管理员ID
     * @return PayOrder 支付单模型
     */
    public function unfreeze(string $payNo, array $input = [], int $adminId = 0): PayOrder
    {
        $payNo = trim($payNo);
        if ($payNo === '') {
            throw new ValidationException('pay_no 不能为空');
        }

        return $this->transactionRetry(function () use ($payNo, $input, $adminId): PayOrder {
            $payOrder = $this->payOrderRepository->findForUpdateByPayNo($payNo);
            if (!$payOrder) {
                throw new ResourceNotFoundException('支付单不存在', ['pay_no' => $payNo]);
            }

            $freeze = $this->fundFreezeRepository->firstActiveForUpdateByPayNo($payNo, $this->now());
            if (!$freeze) {
                return $payOrder->refresh();
            }

            $reason = trim((string) ($input['reason'] ?? ''));
            if ($reason === '') {
                throw new ValidationException('解冻原因不能为空');
            }

            $amount = (int) $freeze->remaining_amount;
            if ($amount <= 0) {
                $this->markFreezeReleased($freeze, $reason, $adminId);
                return $payOrder->refresh();
            }

            $this->merchantAccountService->releaseRiskFrozenAmountInCurrentTransaction(
                (int) $freeze->merchant_id,
                $amount,
                (string) $freeze->freeze_no,
                'RISK_RELEASE:' . (string) $freeze->freeze_no,
                [
                    'freeze_no' => (string) $freeze->freeze_no,
                    'pay_no' => (string) $freeze->pay_no,
                    'biz_no' => (string) $freeze->biz_no,
                    'remark' => '风控冻结释放',
                ],
                (string) ($freeze->trace_no ?: $freeze->pay_no)
            );

            $this->markFreezeReleased($freeze, $reason, $adminId);

            return $payOrder->refresh();
        });
    }

    /**
     * 确认支付单未被冻结。
     *
     * @param PayOrder|array<string, mixed>|string $payOrder 支付单、列表行或支付单号
     * @param string $operation 当前操作文案
     * @return void
     */
    public function assertNotFrozen(PayOrder|array|string $payOrder, string $operation): void
    {
        if (is_string($payOrder)) {
            $payNo = trim($payOrder);
            $payOrder = $payNo !== '' ? $this->payOrderRepository->findByPayNo($payNo) : null;
            if (!$payOrder) {
                throw new ResourceNotFoundException('支付单不存在', ['pay_no' => $payNo]);
            }
        }

        if (!$this->isFrozen($payOrder)) {
            return;
        }

        throw new BusinessStateException('支付单已冻结，禁止' . $operation, [
            'pay_no' => $this->extractPayNo($payOrder),
            'freeze_info' => $this->freezeInfo($payOrder),
        ]);
    }

    /**
     * 返回未冻结展示信息。
     *
     * @return array<string, mixed> 冻结信息
     */
    private function normalFreezeInfo(): array
    {
        $status = PayOrderActionConstant::FREEZE_STATUS_NORMAL;

        return [
            'status' => $status,
            'status_text' => PayOrderActionConstant::freezeStatusMap()[$status] ?? '正常',
            'is_frozen' => false,
            'freeze_no' => '',
            'freeze_type' => 0,
            'freeze_type_text' => '',
            'amount' => 0,
            'amount_text' => $this->formatAmount(0),
            'reason' => '',
            'admin_id' => 0,
            'available_at' => '',
            'available_at_text' => '—',
            'frozen_at' => '',
            'frozen_at_text' => '—',
            'unfreeze_reason' => '',
            'unfrozen_by' => 0,
            'unfrozen_at' => '',
            'unfrozen_at_text' => '—',
        ];
    }

    /**
     * 标记冻结记录已释放。
     *
     * @param MerchantFundFreeze $freeze 冻结记录
     * @param string $reason 解冻原因
     * @param int $adminId 管理员ID
     * @return void
     */
    private function markFreezeReleased(MerchantFundFreeze $freeze, string $reason, int $adminId): void
    {
        $freeze->remaining_amount = 0;
        $freeze->status = FundFreezeConstant::STATUS_RELEASED;
        $freeze->release_reason = $reason;
        $freeze->released_by = $adminId;
        $freeze->released_at = $this->now();
        $freeze->save();
    }

    /**
     * 解析冻结金额。
     *
     * 未传金额时默认冻结当前支付单金额；传入 `freeze_amount` 表示分，传入 `money` 表示元。
     *
     * @param array<string, mixed> $input 输入参数
     * @param PayOrder $payOrder 支付单
     * @return int 冻结金额，单位分
     */
    private function resolveFreezeAmount(array $input, PayOrder $payOrder): int
    {
        if (array_key_exists('freeze_amount', $input) && (int) $input['freeze_amount'] > 0) {
            return (int) $input['freeze_amount'];
        }

        $money = trim((string) ($input['money'] ?? ''));
        if ($money !== '') {
            if (!preg_match('/^\d+(\.\d{1,2})?$/', $money)) {
                throw new ValidationException('冻结金额格式不正确');
            }

            [$yuan, $cent] = array_pad(explode('.', $money, 2), 2, '0');
            return ((int) $yuan * 100) + (int) str_pad($cent, 2, '0');
        }

        $amount = (int) $payOrder->pay_amount;
        if ($amount <= 0) {
            throw new ValidationException('冻结金额必须大于 0');
        }

        return $amount;
    }

    /**
     * 解析最早可释放时间。
     *
     * @param array<string, mixed> $input 输入参数
     * @return string|null 可释放时间
     */
    private function resolveAvailableAt(array $input): ?string
    {
        $value = trim((string) ($input['available_at'] ?? $input['release_at'] ?? ''));
        if ($value === '') {
            return null;
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            throw new ValidationException('可释放时间格式不正确');
        }

        return date('Y-m-d H:i:s', $timestamp);
    }

    /**
     * 提取支付单号。
     *
     * @param PayOrder|array<string, mixed>|null $payOrder 支付单模型或列表行
     * @return string 支付单号
     */
    private function extractPayNo(PayOrder|array|null $payOrder): string
    {
        if ($payOrder instanceof PayOrder) {
            return (string) $payOrder->pay_no;
        }

        if (is_array($payOrder)) {
            return (string) ($payOrder['pay_no'] ?? '');
        }

        return '';
    }
}
