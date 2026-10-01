<?php

namespace app\common\constant;

/**
 * ePay 协议固定值。
 */
final class EpayProtocolConstant
{
    /**
     * 旧版 ePay 协议版本。
     */
    public const VERSION_V1 = 'v1';

    /**
     * 新版 ePay 协议版本。
     */
    public const VERSION_V2 = 'v2';

    /**
     * 页面跳转提交。
     */
    public const SUBMIT_TYPE_PAGE = 'page';

    /**
     * API 直连提交。
     */
    public const SUBMIT_TYPE_API = 'api';

    /** V2 退款失败或关闭。 */
    public const V2_REFUND_STATUS_FAILED = 0;

    /** V2 退款已完成。 */
    public const V2_REFUND_STATUS_SUCCESS = 1;

    /** V2 退款待派发或处理中，不代表资金已退回。 */
    public const V2_REFUND_STATUS_PENDING = 2;

    /**
     * 电脑浏览器。
     */
    public const DEVICE_PC = 'pc';

    /**
     * 手机浏览器。
     */
    public const DEVICE_MOBILE = 'mobile';

    /**
     * 手机 QQ 内浏览器。
     */
    public const DEVICE_QQ = 'qq';

    /**
     * 微信内浏览器。
     */
    public const DEVICE_WECHAT = 'wechat';

    /**
     * 支付宝客户端。
     */
    public const DEVICE_ALIPAY = 'alipay';

    /**
     * 仅返回支付跳转 URL。
     */
    public const DEVICE_JUMP = 'jump';

    /**
     * V1 支持的设备类型。
     *
     * @return array<int, string>
     */
    public static function v1Devices(): array
    {
        return [
            self::DEVICE_PC,
            self::DEVICE_MOBILE,
            self::DEVICE_QQ,
            self::DEVICE_WECHAT,
            self::DEVICE_ALIPAY,
            self::DEVICE_JUMP,
        ];
    }

    /**
     * V2 支持的设备类型。
     *
     * @return array<int, string>
     */
    public static function v2Devices(): array
    {
        return [
            self::DEVICE_PC,
            self::DEVICE_MOBILE,
            self::DEVICE_QQ,
            self::DEVICE_WECHAT,
            self::DEVICE_ALIPAY,
        ];
    }
}
