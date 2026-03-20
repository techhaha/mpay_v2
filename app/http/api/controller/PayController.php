<?php

namespace app\http\api\controller;

use app\common\base\BaseController;
use app\services\PayNotifyService;
use app\services\PluginService;
use support\Request;
use support\Response;

/**
 * 支付控制器（OpenAPI）
 */
class PayController extends BaseController
{
    public function __construct(
        protected PayNotifyService $payNotifyService,
        protected PluginService $pluginService
    ) {
    }

    /**
     * 创建订单
     */
    public function create(Request $request)
    {
        return $this->fail('not implemented', 501);
    }

    /**
     * 查询订单
     */
    public function query(Request $request)
    {
        return $this->fail('not implemented', 501);
    }

    /**
     * 关闭订单
     */
    public function close(Request $request)
    {
        return $this->fail('not implemented', 501);
    }

    /**
     * 订单退款
     */
    public function refund(Request $request)
    {
        return $this->fail('not implemented', 501);
    }

    /**
     * 异步通知
     */
    public function notify(Request $request, string $pluginCode)
    {
        try {
            $plugin = $this->pluginService->getPluginInstance($pluginCode);
            $result = $this->payNotifyService->handleNotify($pluginCode, $request);
            $ackSuccess = method_exists($plugin, 'notifySuccess') ? $plugin->notifySuccess() : 'success';
            $ackFail = method_exists($plugin, 'notifyFail') ? $plugin->notifyFail() : 'fail';

            if (!($result['ok'] ?? false)) {
                return $ackFail instanceof Response ? $ackFail : response((string)$ackFail);
            }

            return $ackSuccess instanceof Response ? $ackSuccess : response((string)$ackSuccess);
        } catch (\Throwable $e) {
            return response('fail');
        }
    }
}
