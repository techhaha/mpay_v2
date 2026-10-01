<?php

declare(strict_types=1);

namespace app\common\constant;

/**
 * 支付调用方式固定值。
 *
 * 调用方式描述商户希望插件使用的支付形态，不等同于插件产品编码，
 * 也不等同于收银台 `presentation.pay_page` 承接类型。
 */
final class PaymentMethodConstant
{
    public const WEB = 'web';
    public const H5 = 'h5';
    public const JUMP = 'jump';
    public const JSAPI = 'jsapi';
    public const APP = 'app';
    public const QRCODE = 'qrcode';
    public const MINI = 'mini';
    public const URL_SCHEME = 'urlscheme';
    public const AUTH_CODE = 'auth_code';

    /**
     * 返回开放接口允许的支付调用方式。
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return [
            self::WEB,
            self::H5,
            self::JUMP,
            self::JSAPI,
            self::APP,
            self::QRCODE,
            self::MINI,
            self::URL_SCHEME,
            self::AUTH_CODE,
        ];
    }
}
