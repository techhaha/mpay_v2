<?php

namespace app\http\admin\controller\system;

use app\common\base\BaseController;
use app\http\admin\validation\AdminUserValidator;
use app\service\system\user\AdminUserService;
use support\Request;
use support\Response;

/**
 * 管理员用户管理控制器。
 *
 * 负责管理员账号的列表、详情、新增、修改和删除。
 *
 * @property AdminUserService $adminUserService 管理用户服务
 */
class AdminUserController extends BaseController
{
    /**
     * 构造方法。
     *
     * @param AdminUserService $adminUserService 管理用户服务
     * @return void
     */
    public function __construct(
        protected AdminUserService $adminUserService
    ) {
    }

    /**
     * 查询管理员用户列表。
     * 
     * @param Request $request 请求对象
     * @return Response 响应对象
     */
    public function index(Request $request): Response
    {
        $data = $this->validated($request->all(), AdminUserValidator::class, 'index');

        return $this->page(
            $this->adminUserService->paginate(
                $data,
                (int) ($data['page'] ?? 1),
                (int) ($data['page_size'] ?? 10)
            )
        );
    }

    /**
     * 查询管理员用户详情。
     * 
     * @param Request $request 请求对象
     * @param string $id 管理用户ID
     * @return Response 响应对象
     */
    public function show(Request $request, string $id): Response
    {
        $data = $this->validated(['id' => (int) $id], AdminUserValidator::class, 'show');
        $adminUser = $this->adminUserService->findById((int) $data['id']);

        if (!$adminUser) {
            return $this->fail('管理员用户不存在', 404);
        }

        return $this->success($adminUser);
    }

    /**
     * 新增管理员用户。
     * 
     * @param Request $request 请求对象
     * @return Response 响应对象
     */
    public function store(Request $request): Response
    {
        $data = $this->validated($request->all(), AdminUserValidator::class, 'store');

        return $this->success($this->adminUserService->create($data));
    }

    /**
     * 修改管理员用户。
     * 
     * @param Request $request 请求对象
     * @param string $id 管理用户ID
     * @return Response 响应对象
     */
    public function update(Request $request, string $id): Response
    {
        $data = $this->validated(
            array_merge($request->all(), ['id' => (int) $id]),
            AdminUserValidator::class,
            'update'
        );

        $adminUser = $this->adminUserService->update((int) $data['id'], $data);
        if (!$adminUser) {
            return $this->fail('管理员用户不存在', 404);
        }

        return $this->success($adminUser);
    }

    /**
     * 删除管理员用户。
     * 
     * @param Request $request 请求对象
     * @param string $id 管理用户ID
     * @return Response 响应对象
     */
    public function destroy(Request $request, string $id): Response
    {
        $data = $this->validated(['id' => (int) $id], AdminUserValidator::class, 'destroy');
        $adminUser = $this->adminUserService->findById((int) $data['id']);

        if (!$adminUser) {
            return $this->fail('管理员用户不存在', 404);
        }

        if ((int) $adminUser->is_super === 1) {
            return $this->fail('超级管理员不允许删除');
        }

        if ((int) $data['id'] === $this->currentAdminId($request)) {
            return $this->fail('不允许删除当前登录用户');
        }

        if (!$this->adminUserService->delete((int) $data['id'])) {
            return $this->fail('管理员用户删除失败');
        }

        return $this->success(true);
    }
}





