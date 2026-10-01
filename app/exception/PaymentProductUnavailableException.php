<?php

declare(strict_types=1);

namespace app\exception;

/**
 * 支付产品不可用异常。
 *
 * 适用于本地未开通、插件未实现，或明确确认上游未开通产品且未创建订单的场景。
 * 普通路由可据此尝试下一个通道，插件自动选择也可尝试下一个本地候选产品。
 */
class PaymentProductUnavailableException extends PaymentDefinitiveException
{
}
