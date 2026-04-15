<?php

namespace app\http\mer\controller\trade;

use app\common\base\BaseController;
use app\http\mer\validation\PayOrderValidator;
use app\service\payment\order\PayOrderService;
use support\Request;
use support\Response;

/**
 * 商户后台支付订单控制器。
 *
 * 商户后台只能看到当前登录商户自己的支付订单。
 */
class PayOrderController extends BaseController
{
    /**
     * 构造函数，注入支付订单服务。
     */
    public function __construct(
        protected PayOrderService $payOrderService
    ) {
    }

    /**
     * 查询当前商户的支付订单列表。
     */
    public function index(Request $request): Response
    {
        $merchantId = $this->currentMerchantId($request);
        if ($merchantId <= 0) {
            return $this->fail('未获取到当前商户信息', 401);
        }

        $data = $this->validated($request->all(), PayOrderValidator::class, 'index');
        $data['merchant_id'] = $merchantId;
        $page = max(1, (int) ($data['page'] ?? 1));
        $pageSize = max(1, (int) ($data['page_size'] ?? 10));

        return $this->success($this->payOrderService->paginate($data, $page, $pageSize, $merchantId));
    }
}

