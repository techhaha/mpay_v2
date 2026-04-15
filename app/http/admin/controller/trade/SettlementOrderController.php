<?php

namespace app\http\admin\controller\trade;

use app\common\base\BaseController;
use app\exception\ResourceNotFoundException;
use app\http\admin\validation\SettlementOrderValidator;
use app\service\payment\settlement\SettlementOrderQueryService;
use support\Request;
use support\Response;

/**
 * 清算订单控制器。
 */
class SettlementOrderController extends BaseController
{
    /**
     * 构造函数，注入清算订单服务。
     */
    public function __construct(
        protected SettlementOrderQueryService $settlementOrderQueryService
    ) {
    }

    /**
     * 查询清算订单列表。
     */
    public function index(Request $request): Response
    {
        $data = $this->validated($request->all(), SettlementOrderValidator::class, 'index');

        return $this->page(
            $this->settlementOrderQueryService->paginate(
                $data,
                (int) ($data['page'] ?? 1),
                (int) ($data['page_size'] ?? 10)
            )
        );
    }

    /**
     * 查询清算订单详情。
     */
    public function show(Request $request, string $settleNo): Response
    {
        $data = $this->validated(['settle_no' => $settleNo], SettlementOrderValidator::class, 'show');
        try {
            return $this->success($this->settlementOrderQueryService->detail((string) $data['settle_no']));
        } catch (ResourceNotFoundException) {
            return $this->fail('清算订单不存在', 404);
        }
    }
}
