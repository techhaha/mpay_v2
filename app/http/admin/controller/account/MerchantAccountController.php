<?php

namespace app\http\admin\controller\account;

use app\common\base\BaseController;
use app\http\admin\validation\MerchantAccountValidator;
use app\service\account\funds\MerchantAccountService;
use support\Request;
use support\Response;

/**
 * 商户账户控制器。
 *
 * @property MerchantAccountService $merchantAccountService 商户账户服务
 */
class MerchantAccountController extends BaseController
{
    /**
     * 构造方法。
     *
     * @param MerchantAccountService $merchantAccountService 商户账户服务
     * @return void
     */
    public function __construct(
        protected MerchantAccountService $merchantAccountService
    ) {
    }

    /**
     * 查询商户账户列表。
     *
     * @param Request $request 请求对象
     * @return Response 响应对象
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
     *
     * @param Request $request 请求对象
     * @return Response 响应对象
     */
    public function summary(Request $request): Response
    {
        return $this->success($this->merchantAccountService->summary());
    }

    /**
     * 查询商户账户详情。
     *
     * @param Request $request 请求对象
     * @param string $id 商户账户ID
     * @return Response 响应对象
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






