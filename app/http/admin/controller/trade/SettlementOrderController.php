<?php

namespace app\http\admin\controller\trade;

use app\common\base\BaseController;
use app\exception\BusinessStateException;
use app\exception\ResourceNotFoundException;
use app\http\admin\validation\SettlementOrderValidator;
use app\service\payment\settlement\SettlementOrderQueryService;
use app\service\payment\settlement\SettlementService;
use support\Request;
use support\Response;

/**
 * 清算订单控制器。
 *
 * @property SettlementOrderQueryService $settlementOrderQueryService 结算订单查询服务
 * @property SettlementService $settlementService 清算服务
 */
class SettlementOrderController extends BaseController
{
    /**
     * 构造方法。
     *
     * @param SettlementOrderQueryService $settlementOrderQueryService 结算订单查询服务
     * @param SettlementService $settlementService 清算服务
     * @return void
     */
    public function __construct(
        protected SettlementOrderQueryService $settlementOrderQueryService,
        protected SettlementService $settlementService
    ) {
    }

    /**
     * 查询清算订单列表。
     *
     * @param Request $request 请求对象
     * @return Response 响应对象
     */
    public function index(Request $request): Response
    {
        $data = $this->validated($request->all(), SettlementOrderValidator::class, 'index');

        $paginator = $this->settlementOrderQueryService->paginate(
            $data,
            (int) ($data['page'] ?? 1),
            (int) ($data['page_size'] ?? 10)
        );

        return $this->success(
            [
                'list' => $paginator->items(),
                'total' => $paginator->total(),
                'page' => $paginator->currentPage(),
                'size' => $paginator->perPage(),
                'summary' => $this->settlementOrderQueryService->summary($data),
            ]
        );
    }

    /**
     * 查询清算订单详情。
     *
     * @param Request $request 请求对象
     * @param string $settleNo 结算单号
     * @return Response 响应对象
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

    /**
     * 清算入账。
     *
     * @param Request $request 请求对象
     * @param string $settleNo 清算单号
     * @return Response 响应对象
     */
    public function complete(Request $request, string $settleNo): Response
    {
        $data = $this->validated(['settle_no' => $settleNo], SettlementOrderValidator::class, 'show');

        try {
            return $this->success($this->settlementService->completeSettlement((string) $data['settle_no']));
        } catch (ResourceNotFoundException $e) {
            return $this->fail($e->getMessage(), $e->getCode());
        } catch (BusinessStateException $e) {
            return $this->fail($e->getMessage(), $e->getCode());
        }
    }

    /**
     * 标记清算失败。
     *
     * @param Request $request 请求对象
     * @param string $settleNo 清算单号
     * @return Response 响应对象
     */
    public function markFailed(Request $request, string $settleNo): Response
    {
        $data = $this->validated(
            array_merge($request->all(), ['settle_no' => $settleNo]),
            SettlementOrderValidator::class,
            'fail'
        );

        try {
            return $this->success($this->settlementService->failSettlement(
                (string) $data['settle_no'],
                (string) ($data['reason'] ?? '')
            ));
        } catch (ResourceNotFoundException $e) {
            return $this->fail($e->getMessage(), $e->getCode());
        } catch (BusinessStateException $e) {
            return $this->fail($e->getMessage(), $e->getCode());
        }
    }
}





