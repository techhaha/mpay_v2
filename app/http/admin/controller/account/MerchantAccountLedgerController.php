<?php

namespace app\http\admin\controller\account;

use app\common\base\BaseController;
use app\http\admin\validation\MerchantAccountLedgerValidator;
use app\service\account\ledger\MerchantAccountLedgerService;
use support\Request;
use support\Response;

/**
 * 商户账户流水控制器。
 */
class MerchantAccountLedgerController extends BaseController
{
    /**
     * 构造函数，注入账户流水服务。
     */
    public function __construct(
        protected MerchantAccountLedgerService $merchantAccountLedgerService
    ) {
    }

    /**
     * 查询账户流水列表。
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
     * 查询账户流水详情。
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
