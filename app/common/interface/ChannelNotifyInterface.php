<?php
declare(strict_types=1);

namespace app\common\interface;

use support\Request;

/**
 * HTTP 通道级通知定位接口。
 *
 * 适用于 SmsForwarder 这类通过 /api/pay/{chanId}/notify 进入的 HTTP 通知。
 * 通知内容通常不携带本系统支付单号，服务层先调用此方法定位 pay_no，
 * 定位成功后继续把同一个 Request 交给插件 notify() 走标准回调流程。
 */
interface ChannelNotifyInterface
{
    /**
     * 根据 HTTP 通道级通知定位支付单。
     *
     * @param Request $request 请求对象
     * @return array{pay_no:string} 定位结果
     */
    public function channelNotify(Request $request): array;
}
