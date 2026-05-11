<?php

namespace app\common\constant;

/**
 * 支付订单后台操作常量。
 *
 * 操作码是后端与管理前端之间的展示协议，前端只根据本类对应的
 * actions 结果渲染按钮，不再自行硬编码订单状态规则。
 */
final class PayOrderActionConstant
{
    /**
     * 手动补单。
     */
    public const MANUAL_SUCCESS = 'manual_success';

    /**
     * 重新通知商户。
     */
    public const RENOTIFY = 'renotify';

    /**
     * 主动查询上游。
     */
    public const ACTIVE_QUERY = 'active_query';

    /**
     * API 退款。
     */
    public const API_REFUND = 'api_refund';

    /**
     * 手动退款。
     */
    public const MANUAL_REFUND = 'manual_refund';

    /**
     * 冻结订单。
     */
    public const FREEZE = 'freeze';

    /**
     * 解冻订单。
     */
    public const UNFREEZE = 'unfreeze';

    /**
     * 订单未冻结。
     */
    public const FREEZE_STATUS_NORMAL = 0;

    /**
     * 订单已冻结。
     */
    public const FREEZE_STATUS_FROZEN = 1;

    /**
     * 获取后台操作文案。
     *
     * @return array<string, string> 操作文案映射
     */
    public static function actionLabelMap(): array
    {
        return [
            self::MANUAL_SUCCESS => '手动补单',
            self::RENOTIFY => '重新通知',
            self::ACTIVE_QUERY => '主动查询',
            self::API_REFUND => 'API退款',
            self::MANUAL_REFUND => '手动退款',
            self::FREEZE => '冻结订单',
            self::UNFREEZE => '解冻订单',
        ];
    }

    /**
     * 获取冻结状态文案。
     *
     * @return array<int, string> 冻结状态文案映射
     */
    public static function freezeStatusMap(): array
    {
        return [
            self::FREEZE_STATUS_NORMAL => '正常',
            self::FREEZE_STATUS_FROZEN => '已冻结',
        ];
    }
}
