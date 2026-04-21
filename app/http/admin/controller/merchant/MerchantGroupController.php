<?php

namespace app\http\admin\controller\merchant;

use app\common\base\BaseController;
use app\http\admin\validation\MerchantGroupValidator;
use app\service\merchant\group\MerchantGroupService;
use support\Request;
use support\Response;

/**
 * 商户分组管理控制器。
 *
 * 负责商户分组的列表、详情、新增、修改和删除。
 *
 * @property MerchantGroupService $merchantGroupService 商户分组服务
 */
class MerchantGroupController extends BaseController
{
    /**
     * 构造方法。
     *
     * @param MerchantGroupService $merchantGroupService 商户分组服务
     * @return void
     */
    public function __construct(
        protected MerchantGroupService $merchantGroupService
    ) {
    }

    /**
     * 查询商户分组列表。
     * 
     * @param Request $request 请求对象
     * @return Response 响应对象
     */
    public function index(Request $request): Response
    {
        $data = $this->validated($request->all(), MerchantGroupValidator::class, 'index');

        return $this->page(
            $this->merchantGroupService->paginate(
                $data,
                (int) ($data['page'] ?? 1),
                (int) ($data['page_size'] ?? 10)
            )
        );
    }

    /**
     * 查询商户分组详情。
     * 
     * @param Request $request 请求对象
     * @param string $id 商户分组ID
     * @return Response 响应对象
     */
    public function show(Request $request, string $id): Response
    {
        $data = $this->validated(['id' => (int) $id], MerchantGroupValidator::class, 'show');
        $merchantGroup = $this->merchantGroupService->findById((int) $data['id']);

        if (!$merchantGroup) {
            return $this->fail('商户分组不存在', 404);
        }

        return $this->success($merchantGroup);
    }

    /**
     * 新增商户分组。
     * 
     * @param Request $request 请求对象
     * @return Response 响应对象
     */
    public function store(Request $request): Response
    {
        $data = $this->validated($request->all(), MerchantGroupValidator::class, 'store');

        return $this->success($this->merchantGroupService->create($data));
    }

    /**
     * 修改商户分组。
     * 
     * @param Request $request 请求对象
     * @param string $id 商户分组ID
     * @return Response 响应对象
     */
    public function update(Request $request, string $id): Response
    {
        $data = $this->validated(
            array_merge($request->all(), ['id' => (int) $id]),
            MerchantGroupValidator::class,
            'update'
        );

        $merchantGroup = $this->merchantGroupService->update((int) $data['id'], $data);
        if (!$merchantGroup) {
            return $this->fail('商户分组不存在', 404);
        }

        return $this->success($merchantGroup);
    }

    /**
     * 删除商户分组。
     * 
     * @param Request $request 请求对象
     * @param string $id 商户分组ID
     * @return Response 响应对象
     */
    public function destroy(Request $request, string $id): Response
    {
        $data = $this->validated(['id' => (int) $id], MerchantGroupValidator::class, 'destroy');

        if (!$this->merchantGroupService->delete((int) $data['id'])) {
            return $this->fail('商户分组不存在', 404);
        }

        return $this->success(true);
    }

    /**
     * 查询商户分组下拉选项。
     *
     * @param Request $request 请求对象
     * @return Response 响应对象
     */
    public function options(Request $request): Response
    {
        return $this->success($this->merchantGroupService->enabledOptions());
    }
}






