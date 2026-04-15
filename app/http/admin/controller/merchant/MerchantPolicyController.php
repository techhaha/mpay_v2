<?php

namespace app\http\admin\controller\merchant;

use app\common\base\BaseController;
use app\http\admin\validation\MerchantPolicyValidator;
use app\service\merchant\policy\MerchantPolicyService;
use support\Request;
use support\Response;

/**
 * 商户策略控制器。
 */
class MerchantPolicyController extends BaseController
{
    public function __construct(
        protected MerchantPolicyService $merchantPolicyService
    ) {
    }

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

    public function show(Request $request, string $merchantId): Response
    {
        $data = $this->validated(['merchant_id' => (int) $merchantId], MerchantPolicyValidator::class, 'show');

        return $this->success($this->merchantPolicyService->findByMerchantId((int) $data['merchant_id']));
    }

    public function store(Request $request): Response
    {
        $data = $this->validated($request->all(), MerchantPolicyValidator::class, 'store');

        return $this->success($this->merchantPolicyService->saveByMerchantId((int) $data['merchant_id'], $data));
    }

    public function update(Request $request, string $merchantId): Response
    {
        $data = $this->validated(
            array_merge($request->all(), ['merchant_id' => (int) $merchantId]),
            MerchantPolicyValidator::class,
            'update'
        );

        return $this->success($this->merchantPolicyService->saveByMerchantId((int) $data['merchant_id'], $data));
    }

    public function destroy(Request $request, string $merchantId): Response
    {
        $data = $this->validated(['merchant_id' => (int) $merchantId], MerchantPolicyValidator::class, 'show');

        return $this->success($this->merchantPolicyService->deleteByMerchantId((int) $data['merchant_id']));
    }
}

