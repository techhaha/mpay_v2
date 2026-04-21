<?php

namespace app\http\admin\controller\merchant;

use app\common\base\BaseController;
use app\http\admin\validation\MerchantPolicyValidator;
use app\service\merchant\policy\MerchantPolicyService;
use support\Request;
use support\Response;

/**
 * 商户策略控制器。
 *
 * @property MerchantPolicyService $merchantPolicyService 商户策略服务
 */
class MerchantPolicyController extends BaseController
{
    /**
     * 构造方法。
     *
     * @param MerchantPolicyService $merchantPolicyService 商户策略服务
     * @return void
     */
    public function __construct(
        protected MerchantPolicyService $merchantPolicyService
    ) {
    }

    /**
     * 查询商户策略列表。
     *
     * @param Request $request 请求对象
     * @return Response 响应对象
     */
    public function index(Request $request): Response
    {
        $data = $this->validated($request->all(), MerchantPolicyValidator::class, 'index');

        return $this->page(
            $this->merchantPolicyService->paginate(
                $data,
                (int) ($data['page'] ?? 1),
                (int) ($data['page_size'] ?? 10)
            )
        );
    }

    /**
     * 查询商户策略详情。
     *
     * @param Request $request 请求对象
     * @param string $merchantId 商户ID
     * @return Response 响应对象
     */
    public function show(Request $request, string $merchantId): Response
    {
        $data = $this->validated(['merchant_id' => (int) $merchantId], MerchantPolicyValidator::class, 'show');

        return $this->success($this->merchantPolicyService->findByMerchantId((int) $data['merchant_id']));
    }

    /**
     * 新增商户策略。
     *
     * @param Request $request 请求对象
     * @return Response 响应对象
     */
    public function store(Request $request): Response
    {
        $data = $this->validated($request->all(), MerchantPolicyValidator::class, 'store');

        return $this->success($this->merchantPolicyService->saveByMerchantId((int) $data['merchant_id'], $data));
    }

    /**
     * 更新商户策略。
     *
     * @param Request $request 请求对象
     * @param string $merchantId 商户ID
     * @return Response 响应对象
     */
    public function update(Request $request, string $merchantId): Response
    {
        $data = $this->validated(
            array_merge($request->all(), ['merchant_id' => (int) $merchantId]),
            MerchantPolicyValidator::class,
            'update'
        );

        return $this->success($this->merchantPolicyService->saveByMerchantId((int) $data['merchant_id'], $data));
    }

    /**
     * 删除商户策略。
     *
     * @param Request $request 请求对象
     * @param string $merchantId 商户ID
     * @return Response 响应对象
     */
    public function destroy(Request $request, string $merchantId): Response
    {
        $data = $this->validated(['merchant_id' => (int) $merchantId], MerchantPolicyValidator::class, 'show');

        return $this->success($this->merchantPolicyService->deleteByMerchantId((int) $data['merchant_id']));
    }
}






