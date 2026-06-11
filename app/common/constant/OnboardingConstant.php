<?php

namespace app\common\constant;

/**
 * 商户支付渠道进件常量。
 */
final class OnboardingConstant
{
    public const SUBJECT_MICRO = 'micro';
    public const SUBJECT_INDIVIDUAL = 'individual';
    public const SUBJECT_ENTERPRISE = 'enterprise';

    public const STATUS_DRAFT = 0;
    public const STATUS_PLATFORM_PENDING = 1;
    public const STATUS_PLATFORM_REJECTED = 2;
    public const STATUS_PLATFORM_APPROVED = 3;
    public const STATUS_UPSTREAM_PENDING = 4;
    public const STATUS_SIGNED = 5;
    public const STATUS_UPSTREAM_REJECTED = 6;
    public const STATUS_CANCELLED = 7;
    public const STATUS_FAILED = 8;

    public const OPERATOR_ADMIN = 'admin';
    public const OPERATOR_MERCHANT = 'merchant';
    public const OPERATOR_SYSTEM = 'system';
    public const OPERATOR_UPSTREAM = 'upstream';

    /**
     * 主体类型文本。
     *
     * @return array<string, string>
     */
    public static function subjectTypeMap(): array
    {
        return [
            self::SUBJECT_MICRO => '小微商户',
            self::SUBJECT_INDIVIDUAL => '个体工商户',
            self::SUBJECT_ENTERPRISE => '企业商户',
        ];
    }

    /**
     * 申请状态文本。
     *
     * @return array<int, string>
     */
    public static function statusMap(): array
    {
        return [
            self::STATUS_DRAFT => '草稿',
            self::STATUS_PLATFORM_PENDING => '平台待审',
            self::STATUS_PLATFORM_REJECTED => '平台驳回',
            self::STATUS_PLATFORM_APPROVED => '待提交上游',
            self::STATUS_UPSTREAM_PENDING => '上游处理中',
            self::STATUS_SIGNED => '签约成功',
            self::STATUS_UPSTREAM_REJECTED => '上游退回',
            self::STATUS_CANCELLED => '已取消',
            self::STATUS_FAILED => '处理失败',
        ];
    }

    /**
     * 终态集合。
     *
     * @return array<int, int>
     */
    public static function terminalStatuses(): array
    {
        return [
            self::STATUS_SIGNED,
            self::STATUS_CANCELLED,
            self::STATUS_FAILED,
        ];
    }

    /**
     * 判断申请是否已终态。
     */
    public static function isTerminal(int $status): bool
    {
        return in_array($status, self::terminalStatuses(), true);
    }

    /**
     * 可由商户重新提交平台审核的状态。
     *
     * @return array<int, int>
     */
    public static function merchantSubmittableStatuses(): array
    {
        return [
            self::STATUS_DRAFT,
            self::STATUS_PLATFORM_REJECTED,
            self::STATUS_UPSTREAM_REJECTED,
        ];
    }
}
