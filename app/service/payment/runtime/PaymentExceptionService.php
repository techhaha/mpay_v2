<?php

namespace app\service\payment\runtime;

use app\common\base\BaseService;
use app\common\constant\PaymentExceptionConstant;
use app\exception\BalanceInsufficientException;
use app\model\payment\PayOrder;
use app\model\payment\PaymentExceptionRecord;
use app\repository\payment\runtime\PaymentExceptionRecordRepository;

/**
 * 管理支付业务异常的当前处置状态。
 *
 * 异常记录用于后台发现和处置少量非标准业务结果，不替代支付单的渠道错误字段，
 * 也不改变支付单真实状态。重复支付异常同时保留退款归属事实，处置完成后仍用于隔离正常业务退款。
 *
 * @property PaymentExceptionRecordRepository $exceptionRepository 支付异常记录仓库
 */
class PaymentExceptionService extends BaseService
{
    /**
     * 在可信迟到成功事务中登记自收服务费欠费。
     *
     * 调用方持有支付单锁；异常与成功、费用 DUE 状态一起提交或回滚。
     * detail_json 仅保留本次余额检查的证据，不放入订单 ext_json，也不创建自动追缴任务。
     */
    public function openPayFeeDueInCurrentTransaction(PayOrder $payOrder, BalanceInsufficientException $e): PaymentExceptionRecord
    {
        $record = $this->exceptionRepository->findForUpdateBySubjectType(
            PaymentExceptionConstant::SUBJECT_PAY,
            (string) $payOrder->pay_no,
            PaymentExceptionConstant::TYPE_PAY_SERVICE_FEE_DUE
        );
        $data = [
            'biz_no' => (string) $payOrder->biz_no,
            'merchant_id' => (int) $payOrder->merchant_id,
            'severity' => PaymentExceptionConstant::SEVERITY_WARNING,
            'status' => PaymentExceptionConstant::STATUS_OPEN,
            'source_type' => 'SYSTEM',
            'source_id' => 0,
            'summary' => '支付已成功，自收服务费余额不足，待充值后人工补扣',
            'detail_json' => [
                'service_fee_amount' => (int) $payOrder->service_fee_amount,
                'available_amount' => (int) ($e->getData()['available_amount'] ?? 0),
                'channel_id' => (int) $payOrder->channel_id,
                'reason' => $e->getMessage(),
            ],
            'detected_at' => $record?->detected_at ?: $this->now(),
            'resolution' => '', 'resolution_ref_no' => '', 'resolver_id' => 0, 'resolved_at' => null,
        ];
        if (!$record) {
            $record = $this->exceptionRepository->create([
                'exception_no' => $this->generateNo('EXC'),
                'subject_type' => PaymentExceptionConstant::SUBJECT_PAY,
                'subject_no' => (string) $payOrder->pay_no,
                'exception_type' => PaymentExceptionConstant::TYPE_PAY_SERVICE_FEE_DUE,
            ] + $data);
        } else {
            $record->fill($data);
            $record->save();
        }
        return $record->refresh();
    }

    /** 在已锁定支付单的全额补扣事务中关闭欠费异常；重复销项不更新原处置证据。 */
    public function resolvePayFeeDueInCurrentTransaction(string $payNo, int $adminId): bool
    {
        $record = $this->exceptionRepository->findForUpdateBySubjectType(
            PaymentExceptionConstant::SUBJECT_PAY,
            $payNo,
            PaymentExceptionConstant::TYPE_PAY_SERVICE_FEE_DUE
        );
        if (!$record || !PaymentExceptionConstant::isOpen((int) $record->status)) {
            return false;
        }
        $record->status = PaymentExceptionConstant::STATUS_RESOLVED;
        $record->resolution = '自收通道服务费已全额补扣';
        $record->resolution_ref_no = $payNo;
        $record->resolver_id = $adminId;
        $record->resolved_at = $this->now();
        $record->save();
        return true;
    }

    /**
     * 构造方法。
     *
     * @param PaymentExceptionRecordRepository $exceptionRepository 支付异常记录仓库
     */
    public function __construct(
        protected PaymentExceptionRecordRepository $exceptionRepository
    ) {
    }

    /**
     * 判断支付单是否属于异常重复收款。
     *
     * PAY_LATE_DUPLICATE 记录表示支付归属事实，不按处置状态过滤；足额返还后已解决的
     * 记录仍用于排除正常业务退款累计和商户退款通知。其他异常类型不影响该判断。
     *
     * @param string $payNo MPAY 支付单号
     * @return bool 是否存在重复支付事实
     */
    public function isLateDuplicatePayment(string $payNo): bool
    {
        return $this->exceptionRepository->findBySubjectType(
            PaymentExceptionConstant::SUBJECT_PAY,
            $payNo,
            PaymentExceptionConstant::TYPE_PAY_LATE_DUPLICATE,
            ['id']
        ) !== null;
    }

    /**
     * 登记晚到的重复支付异常。
     *
     * 调用方必须已开启支付成功事务。本方法锁定同一支付单、同一异常类型的记录，
     * 首次发现时创建记录，再次发现时重新打开并更新证据快照。
     *
     * @param PayOrder $payOrder 已确认真实成功的支付单
     * @param int $previousStatus 支付成功前的平台状态
     * @param string $sourceType 异常来源类型
     * @param int $sourceId 异常来源记录 ID
     * @return PaymentExceptionRecord 最新异常记录
     */
    public function openLateDuplicateInCurrentTransaction(
        PayOrder $payOrder,
        int $previousStatus,
        string $sourceType = 'SYSTEM',
        int $sourceId = 0
    ): PaymentExceptionRecord {
        $record = $this->exceptionRepository->findForUpdateBySubjectType(
            PaymentExceptionConstant::SUBJECT_PAY,
            (string) $payOrder->pay_no,
            PaymentExceptionConstant::TYPE_PAY_LATE_DUPLICATE
        );
        $detectedAt = $record?->detected_at ?: $this->now();
        $data = [
            'biz_no' => (string) $payOrder->biz_no,
            'merchant_id' => (int) $payOrder->merchant_id,
            'severity' => PaymentExceptionConstant::SEVERITY_HIGH,
            'status' => PaymentExceptionConstant::STATUS_OPEN,
            'source_type' => mb_strcut($sourceType, 0, 32, 'UTF-8'),
            'source_id' => max(0, $sourceId),
            'summary' => '业务单已由其他支付单完成，本次真实成功款等待退款处置',
            'detail_json' => [
                'previous_status' => $previousStatus,
                'pay_amount' => (int) $payOrder->pay_amount,
                'channel_type' => (int) $payOrder->channel_type,
                'channel_id' => (int) $payOrder->channel_id,
                'channel_trade_no' => (string) $payOrder->channel_trade_no,
            ],
            'resolution' => '',
            'resolution_ref_no' => '',
            'resolver_id' => 0,
            'detected_at' => $detectedAt,
            'resolved_at' => null,
        ];

        if (!$record) {
            /** @var PaymentExceptionRecord $record */
            $record = $this->exceptionRepository->create([
                'exception_no' => $this->generateNo('EXC'),
                'subject_type' => PaymentExceptionConstant::SUBJECT_PAY,
                'subject_no' => (string) $payOrder->pay_no,
                'exception_type' => PaymentExceptionConstant::TYPE_PAY_LATE_DUPLICATE,
            ] + $data);
        } else {
            $record->fill($data);
            $record->save();
        }

        return $record->refresh();
    }

    /**
     * 关闭已完成退款的重复支付异常。
     *
     * 调用方必须已开启退款成功事务。仅处于待处理或处理中状态的异常可以关闭，
     * 已关闭或不存在时返回 false，避免重复写入处置结果。
     *
     * @param string $payNo 支付单号
     * @param string $refundNo 完成处置的退款单号
     * @param int $refundedAmount 已退款金额，单位为分
     * @return bool 是否完成异常状态转换
     */
    public function resolveLateDuplicateInCurrentTransaction(
        string $payNo,
        string $refundNo,
        int $refundedAmount
    ): bool {
        $record = $this->exceptionRepository->findForUpdateBySubjectType(
            PaymentExceptionConstant::SUBJECT_PAY,
            $payNo,
            PaymentExceptionConstant::TYPE_PAY_LATE_DUPLICATE
        );
        if (!$record || !PaymentExceptionConstant::isOpen((int) $record->status)) {
            return false;
        }

        $record->status = PaymentExceptionConstant::STATUS_RESOLVED;
        $record->resolution = '重复支付已足额退款';
        $record->resolution_ref_no = $refundNo;
        $record->resolver_id = 0;
        $record->resolved_at = $this->now();
        $detail = (array) ($record->detail_json ?? []);
        $detail['refunded_amount'] = $refundedAmount;
        $record->detail_json = $detail;
        $record->save();

        return true;
    }
}
