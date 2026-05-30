<?php

declare(strict_types=1);

namespace app\common\sdk\lakala;

use RuntimeException;

/**
 * 拉卡拉轻量 SDK 异常。
 *
 * SDK 只抛出技术或渠道请求异常，支付插件负责转换为平台 PaymentException。
 */
class LakalaSdkException extends RuntimeException
{
}
