<?php

namespace app\common\constant;

/**
 * 定义支付业务异常的主体、类型、处置状态和严重级别。
 *
 * 本类描述需要运营或系统继续处置的业务异常，不承载渠道请求错误，也不改变支付、
 * 退款、转账等业务单据的真实状态。除晚到重复支付、服务费待补扣外，其余类型仅预留名称；
 * 常量已登记不表示检测、建单和处置流程已经实现。
 */
final class PaymentExceptionConstant
{
    /**
     * 异常主体为支付单。
     */
    public const SUBJECT_PAY = 'PAY';

    /**
     * 异常主体为退款单。
     */
    public const SUBJECT_REFUND = 'REFUND';

    /**
     * 异常主体为转账单。
     */
    public const SUBJECT_TRANSFER = 'TRANSFER';

    /**
     * 异常主体为清算单。
     */
    public const SUBJECT_SETTLEMENT = 'SETTLEMENT';

    /**
     * 异常主体为商户账户。
     */
    public const SUBJECT_ACCOUNT = 'ACCOUNT';

    /**
     * 支付单真实成功，但所属业务单已由其他支付单完成，需要继续退款处置。
     */
    public const TYPE_PAY_LATE_DUPLICATE = 'PAY_LATE_DUPLICATE';

    /** 支付已确认成功，自收服务费余额不足，需要充值后人工全额补扣。 */
    public const TYPE_PAY_SERVICE_FEE_DUE = 'PAY_SERVICE_FEE_DUE';

    /**
     * 预留类型：本地支付金额与可信渠道金额不一致。
     */
    public const TYPE_PAY_AMOUNT_MISMATCH = 'PAY_AMOUNT_MISMATCH';

    /**
     * 预留类型：同一支付单关联了相互冲突的渠道订单号或渠道流水号。
     */
    public const TYPE_PAY_CHANNEL_REFERENCE_CONFLICT = 'PAY_CHANNEL_REFERENCE_CONFLICT';

    /**
     * 预留类型：支付单已经进入终态，但后续可信结果要求推进到另一终态。
     */
    public const TYPE_PAY_TERMINAL_STATUS_CONFLICT = 'PAY_TERMINAL_STATUS_CONFLICT';

    /**
     * 预留类型：累计退款金额超过支付单剩余可退金额。
     */
    public const TYPE_REFUND_AMOUNT_OVERFLOW = 'REFUND_AMOUNT_OVERFLOW';

    /**
     * 预留类型：退款单当前状态与可信渠道退款结果冲突。
     */
    public const TYPE_REFUND_STATUS_CONFLICT = 'REFUND_STATUS_CONFLICT';

    /**
     * 预留类型：退款成功后的本地账户冲减欠款超过允许处置期限。
     */
    public const TYPE_REFUND_REVERSE_OVERDUE = 'REFUND_REVERSE_OVERDUE';

    /**
     * 预留类型：转账结果长期无法确认，超过主动查单处置期限。
     */
    public const TYPE_TRANSFER_RESULT_UNCERTAIN_OVERDUE = 'TRANSFER_RESULT_UNCERTAIN_OVERDUE';

    /**
     * 预留类型：同一转账单关联了相互冲突的渠道订单号或渠道流水号。
     */
    public const TYPE_TRANSFER_CHANNEL_REFERENCE_CONFLICT = 'TRANSFER_CHANNEL_REFERENCE_CONFLICT';

    /**
     * 预留类型：清算单金额与其明细或关联交易汇总金额不一致。
     */
    public const TYPE_SETTLEMENT_AMOUNT_MISMATCH = 'SETTLEMENT_AMOUNT_MISMATCH';

    /**
     * 预留类型：账户流水与关联业务或对账汇总结果不一致。
     */
    public const TYPE_LEDGER_RECONCILIATION_MISMATCH = 'LEDGER_RECONCILIATION_MISMATCH';

    /**
     * 预留类型：账户余额与可验证的流水累计结果不一致。
     */
    public const TYPE_ACCOUNT_BALANCE_INCONSISTENCY = 'ACCOUNT_BALANCE_INCONSISTENCY';

    /**
     * 异常已发现，等待开始处置。
     */
    public const STATUS_OPEN = 0;

    /**
     * 异常正在处置，尚未形成最终结果。
     */
    public const STATUS_HANDLING = 1;

    /**
     * 异常已经解决并记录处置结果。
     */
    public const STATUS_RESOLVED = 2;

    /**
     * 异常经确认无需继续处置。
     */
    public const STATUS_IGNORED = 3;

    /**
     * 警告级异常。
     */
    public const SEVERITY_WARNING = 1;

    /**
     * 高风险异常。
     */
    public const SEVERITY_HIGH = 2;

    /**
     * 严重异常。
     */
    public const SEVERITY_CRITICAL = 3;

    /**
     * 获取已登记异常类型的名称映射。
     *
     * 映射同时包含已实现和预留类型，仅用于统一名称和展示文案，不能据此判断对应的
     * 检测或处置流程是否已经实现。
     *
     * @return array<string, string> 异常类型名称表
     */
    public static function typeMap(): array
    {
        return [
            self::TYPE_PAY_LATE_DUPLICATE => '晚到重复支付',
            self::TYPE_PAY_SERVICE_FEE_DUE => '服务费待补扣',
            self::TYPE_PAY_AMOUNT_MISMATCH => '支付金额不一致',
            self::TYPE_PAY_CHANNEL_REFERENCE_CONFLICT => '支付渠道引用冲突',
            self::TYPE_PAY_TERMINAL_STATUS_CONFLICT => '支付终态冲突',
            self::TYPE_REFUND_AMOUNT_OVERFLOW => '退款金额超出可退额度',
            self::TYPE_REFUND_STATUS_CONFLICT => '退款状态冲突',
            self::TYPE_REFUND_REVERSE_OVERDUE => '退款账户冲减逾期',
            self::TYPE_TRANSFER_RESULT_UNCERTAIN_OVERDUE => '转账结果不确定超期',
            self::TYPE_TRANSFER_CHANNEL_REFERENCE_CONFLICT => '转账渠道引用冲突',
            self::TYPE_SETTLEMENT_AMOUNT_MISMATCH => '清算金额不一致',
            self::TYPE_LEDGER_RECONCILIATION_MISMATCH => '账本对账不一致',
            self::TYPE_ACCOUNT_BALANCE_INCONSISTENCY => '账户余额不一致',
        ];
    }

    /**
     * 获取异常处置状态名称映射。
     *
     * @return array<int, string> 异常处置状态名称表
     */
    public static function statusMap(): array
    {
        return [
            self::STATUS_OPEN => '待处理',
            self::STATUS_HANDLING => '处理中',
            self::STATUS_RESOLVED => '已解决',
            self::STATUS_IGNORED => '已忽略',
        ];
    }

    /**
     * 判断异常是否仍需继续处置。
     *
     * 待处理和处理中均属于未关闭状态；已解决和已忽略均视为处置终态。
     *
     * @param int $status 异常处置状态
     * @return bool 是否仍需继续处置
     */
    public static function isOpen(int $status): bool
    {
        return in_array($status, [self::STATUS_OPEN, self::STATUS_HANDLING], true);
    }
}
