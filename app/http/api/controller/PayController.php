<?php

namespace app\http\api\controller;

use app\common\base\BaseController;
use app\services\PayOrderService;
use support\Request;

/**
 * 支付控制器（OpenAPI）
 */
class PayController extends BaseController
{
    public function __construct(
        protected PayOrderService $payOrderService
    ) {}

    /**
     * 创建订单
     */
    public function create(Request $request) {}

    /**
     * 查询订单
     */
    public function query(Request $request) {}

    /**
     * 关闭订单
     */
    public function close(Request $request) {}

    /**
     * 订单退款
     */
    public function refund(Request $request) {}
    /**
     * 异步通知
     */
    public function notify(Request $request) {}
}
