<?php

namespace app\http\admin\controller\account;

use app\common\base\BaseController;
use app\http\admin\validation\MerchantAccountValidator;
use app\service\account\funds\MerchantAccountService;
use support\Request;
use support\Response;

/**
 * 商户账户控制器。
 */
class MerchantAccountController extends BaseController
{
    /**
     * 构造函数，注入商户账户服务。
     */
    public function __construct(
        protected MerchantAccountService $merchantAccountService
    ) {
    }

    /**
     * 查询商户账户列表。
     */
    public function index(Request $request): Response
    {
        $data = $this->validated($request->all(), MerchantAccountValidator::class, 'index');

        return $this->page(
            $this->merchantAccountService->paginate(
                $data,
                (int) ($data['page'] ?? 1),
                (int) ($data['page_size'] ?? 10)
            )
        );
    }

    /**
     * 资金中心概览。
     */
    public function summary(Request $request): Response
    {
        return $this->success($this->merchantAccountService->summary());
    }

    /**
     * 查询商户账户详情。
     */
    public function show(Request $request, string $id): Response
    {
        $data = $this->validated(['id' => (int) $id], MerchantAccountValidator::class, 'show');
        $account = $this->merchantAccountService->findById((int) $data['id']);

        if (!$account) {
            return $this->fail('商户账户不存在', 404);
        }

        return $this->success($account);
    }
}

