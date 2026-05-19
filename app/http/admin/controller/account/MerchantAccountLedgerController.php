<?php

namespace app\http\admin\controller\account;

use app\common\base\BaseController;
use app\http\admin\validation\MerchantAccountLedgerValidator;
use app\service\account\ledger\MerchantAccountLedgerService;
use support\Request;
use support\Response;

/**
 * 商户账户流水控制器。
 *
 * @property MerchantAccountLedgerService $merchantAccountLedgerService 商户账户流水服务
 */
class MerchantAccountLedgerController extends BaseController
{
    /**
     * 构造方法。
     *
     * @param MerchantAccountLedgerService $merchantAccountLedgerService 商户账户流水服务
     * @return void
     */
    public function __construct(
        protected MerchantAccountLedgerService $merchantAccountLedgerService
    ) {
    }

    /**
     * 查询账户流水列表。
     *
     * @param Request $request 请求对象
     * @return Response 响应对象
     */
    public function index(Request $request): Response
    {
        $data = $this->validated($request->all(), MerchantAccountLedgerValidator::class, 'index');

        return $this->page(
            $this->merchantAccountLedgerService->paginate(
                $data,
                (int) ($data['page'] ?? 1),
                (int) ($data['page_size'] ?? 10)
            )
        );
    }

    /**
     * 导出账户流水 CSV。
     *
     * @param Request $request 请求对象
     * @return Response 响应对象
     */
    public function export(Request $request): Response
    {
        return $this->merchantAccountLedgerService->exportCsv($request->all());
    }

    /**
     * 查询账户流水详情。
     *
     * @param Request $request 请求对象
     * @param string $id 商户账户流水ID
     * @return Response 响应对象
     */
    public function show(Request $request, string $id): Response
    {
        $data = $this->validated(['id' => (int) $id], MerchantAccountLedgerValidator::class, 'show');
        $ledger = $this->merchantAccountLedgerService->findById((int) $data['id']);

        if (!$ledger) {
            return $this->fail('账户流水不存在', 404);
        }

        return $this->success($ledger);
    }
}





