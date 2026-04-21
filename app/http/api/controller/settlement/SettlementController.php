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
 * 负责清算单创建、查询和清算终态推进。
 *
 * @property SettlementService $settlementService 结算服务
 */
class SettlementController extends BaseController
{
    /**
     * 构造方法。
     *
     * @param SettlementService $settlementService 结算服务
     * @return void
     */
    public function __construct(
        protected SettlementService $settlementService,
    ) {
    }

    /**
     * 创建清算单。
     *
     * 会把传入的清算明细和汇总一起交给清算生命周期服务落库。
     *
     * @param Request $request 请求对象
     * @return Response 响应对象
     */
    public function create(Request $request): Response
    {
        $data = $this->validated($request->all(), SettlementCreateValidator::class, 'store');
        $items = (array) ($data['items'] ?? []);

        return $this->success($this->settlementService->createSettlementOrder($data, $items));
    }

    /**
     * 查询清算单详情。
     *
     * 用于查看批次金额、状态和关联支付单明细。
     *
     * @param Request $request 请求对象
     * @param string $settleNo 结算单号
     * @return Response 响应对象
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
     * 标记清算成功。
     *
     * 会触发商户余额入账，并同步清算单、清算明细和关联支付单状态。
     *
     * @param Request $request 请求对象
     * @param string $settleNo 结算单号
     * @return Response 响应对象
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
     * 标记清算失败。
     *
     * 仅在清算批次未成功入账时使用，用于把批次推进到失败终态并保留原因。
     *
     * @param Request $request 请求对象
     * @param string $settleNo 结算单号
     * @return Response 响应对象
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




