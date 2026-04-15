<?php

namespace app\http\api\controller\settlement;

use app\common\base\BaseController;
use app\exception\ResourceNotFoundException;
use app\http\api\validation\SettlementActionValidator;
use app\http\api\validation\SettlementCreateValidator;
use app\service\payment\settlement\SettlementService;
use support\Request;
use support\Response;

/**
 * 清算接口控制器。
 *
 * 负责清算单创建、查询和清算状态推进。
 */
class SettlementController extends BaseController
{
    /**
     * 构造函数，注入清算相关依赖。
     */
    public function __construct(
        protected SettlementService $settlementService,
    ) {
    }

    /**
     * 创建清结算单。
     */
    public function create(Request $request): Response
    {
        $data = $this->validated($request->all(), SettlementCreateValidator::class, 'store');
        $items = (array) ($data['items'] ?? []);

        return $this->success($this->settlementService->createSettlementOrder($data, $items));
    }

    /**
     * 查询清结算单详情。
     */
    public function show(Request $request, string $settleNo): Response
    {
        try {
            return $this->success($this->settlementService->detail($settleNo));
        } catch (ResourceNotFoundException) {
            return $this->fail('清算单不存在', 404);
        }
    }

    /**
     * 标记清结算成功。
     */
    public function complete(Request $request, string $settleNo): Response
    {
        $this->validated(
            array_merge($request->all(), ['settle_no' => $settleNo]),
            SettlementActionValidator::class,
            'complete'
        );

        return $this->success($this->settlementService->completeSettlement($settleNo));
    }

    /**
     * 标记清结算失败。
     */
    public function failSettlement(Request $request, string $settleNo): Response
    {
        $data = $this->validated(
            array_merge($request->all(), ['settle_no' => $settleNo]),
            SettlementActionValidator::class,
            'fail'
        );

        return $this->success($this->settlementService->failSettlement($settleNo, (string) ($data['reason'] ?? '')));
    }
}
