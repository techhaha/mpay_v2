<?php

declare(strict_types=1);

namespace app\common\sdk\wxpay;

use RuntimeException;

/**
 * 微信支付轻量 SDK 异常。
 *
 * SDK 内部配置缺失、签名失败、XML/JSON 解析失败、HTTP 请求失败、
 * 回调解密失败等场景统一抛出该异常。后续支付插件接入时可捕获该异常，
 * 并转换为项目统一的支付异常或通道错误信息。
 */
class WxpaySdkException extends RuntimeException
{
}
