<?php

namespace app\http\admin\controller\merchant;

use app\common\base\BaseController;
use app\http\admin\validation\MerchantApiCredentialValidator;
use app\service\merchant\security\MerchantApiCredentialService;
use support\Request;
use support\Response;

/**
 * 商户 API 凭证管理控制器。
 *
 * @property MerchantApiCredentialService $merchantApiCredentialService 商户 API 凭证服务
 */
class MerchantApiCredentialController extends BaseController
{
    /**
     * 构造方法。
     *
     * @param MerchantApiCredentialService $merchantApiCredentialService 商户 API 凭证服务
     * @return void
     */
    public function __construct(
        protected MerchantApiCredentialService $merchantApiCredentialService
    ) {
    }

    /**
     * 查询商户 API 凭证列表。
     *
     * @param Request $request 请求对象
     * @return Response 响应对象
     */
    public function index(Request $request): Response
    {
        $data = $this->validated($request->all(), MerchantApiCredentialValidator::class, 'index');

        return $this->page(
            $this->merchantApiCredentialService->paginate(
                $data,
                (int) ($data['page'] ?? 1),
                (int) ($data['page_size'] ?? 10)
            )
        );
    }

    /**
     * 查询商户 API 凭证详情。
     *
     * @param Request $request 请求对象
     * @param string $id 商户 API 凭证ID
     * @return Response 响应对象
     */
    public function show(Request $request, string $id): Response
    {
        $data = $this->validated(['id' => (int) $id], MerchantApiCredentialValidator::class, 'show');
        $credential = $this->merchantApiCredentialService->findById((int) $data['id']);

        if (!$credential) {
            return $this->fail('商户 API 凭证不存在', 404);
        }

        return $this->success($credential);
    }

    /**
     * 新增商户 API 凭证。
     *
     * @param Request $request 请求对象
     * @return Response 响应对象
     */
    public function store(Request $request): Response
    {
        $data = $this->validated($request->all(), MerchantApiCredentialValidator::class, 'store');

        return $this->success($this->merchantApiCredentialService->create($data));
    }

    /**
     * 修改商户 API 凭证。
     *
     * @param Request $request 请求对象
     * @param string $id 商户 API 凭证ID
     * @return Response 响应对象
     */
    public function update(Request $request, string $id): Response
    {
        $data = $this->validated(
            array_merge($request->all(), ['id' => (int) $id]),
            MerchantApiCredentialValidator::class,
            'update'
        );

        $credential = $this->merchantApiCredentialService->update((int) $data['id'], $data);
        if (!$credential) {
            return $this->fail('商户 API 凭证不存在', 404);
        }

        return $this->success($credential);
    }

    /**
     * 删除商户 API 凭证。
     *
     * @param Request $request 请求对象
     * @param string $id 商户 API 凭证ID
     * @return Response 响应对象
     */
    public function destroy(Request $request, string $id): Response
    {
        $data = $this->validated(['id' => (int) $id], MerchantApiCredentialValidator::class, 'destroy');
        $credential = $this->merchantApiCredentialService->findById((int) $data['id']);

        if (!$credential) {
            return $this->fail('商户 API 凭证不存在', 404);
        }

        if (!$this->merchantApiCredentialService->delete((int) $data['id'])) {
            return $this->fail('商户 API 凭证删除失败');
        }

        return $this->success(true);
    }
}






