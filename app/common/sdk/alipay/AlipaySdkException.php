<?php

declare(strict_types=1);

namespace app\common\sdk\alipay;

use RuntimeException;

/**
 * 支付宝轻量 SDK 异常。
 *
 * SDK 内部配置缺失、签名失败、证书解析失败、HTTP 请求失败、返回验签失败等场景统一抛出该异常。
 * 后续支付插件接入时可以捕获该异常，并转换为项目统一的 PaymentException。
 */
class AlipaySdkException extends RuntimeException
{
}
