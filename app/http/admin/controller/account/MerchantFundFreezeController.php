<?php

namespace app\http\admin\controller\account;

use app\common\base\BaseController;
use app\http\admin\validation\MerchantFundFreezeValidator;
use app\service\account\freeze\MerchantFundFreezeService;
use support\Request;
use support\Response;

/**
 * 商户资金冻结明细控制器。
 *
 * @property MerchantFundFreezeService $merchantFundFreezeService 资金冻结明细服务
 */
class MerchantFundFreezeController extends BaseController
{
    /**
     * 构造方法。
     *
     * @param MerchantFundFreezeService $merchantFundFreezeService 资金冻结明细服务
     * @return void
     */
    public function __construct(
        protected MerchantFundFreezeService $merchantFundFreezeService
    ) {
    }

    /**
     * 查询资金冻结明细列表。
     *
     * @param Request $request 请求对象
     * @return Response 响应对象
     */
    public function index(Request $request): Response
    {
        $data = $this->validated($request->all(), MerchantFundFreezeValidator::class, 'index');

        return $this->page(
            $this->merchantFundFreezeService->paginate(
                $data,
                (int) ($data['page'] ?? 1),
                (int) ($data['page_size'] ?? 10)
            )
        );
    }

    /**
     * 查询资金冻结对账摘要。
     *
     * @param Request $request 请求对象
     * @return Response 响应对象
     */
    public function reconciliation(Request $request): Response
    {
        return $this->success($this->merchantFundFreezeService->reconciliationSummary());
    }

    /**
     * 导出资金冻结明细 CSV。
     *
     * @param Request $request 请求对象
     * @return Response 响应对象
     */
    public function export(Request $request): Response
    {
        return $this->merchantFundFreezeService->exportCsv($request->all());
    }

    /**
     * 查询资金冻结明细详情。
     *
     * @param Request $request 请求对象
     * @param string $id 冻结明细ID
     * @return Response 响应对象
     */
    public function show(Request $request, string $id): Response
    {
        $data = $this->validated(['id' => (int) $id], MerchantFundFreezeValidator::class, 'show');
        $freeze = $this->merchantFundFreezeService->findById((int) $data['id']);

        if (!$freeze) {
            return $this->fail('资金冻结明细不存在', 404);
        }

        return $this->success($freeze);
    }
}
