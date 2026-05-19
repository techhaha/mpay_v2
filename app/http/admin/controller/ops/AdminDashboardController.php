<?php

namespace app\http\admin\controller\ops;

use app\common\base\BaseController;
use app\service\ops\dashboard\AdminDashboardService;
use support\Request;
use support\Response;

/**
 * 管理后台运营首页控制器。
 */
class AdminDashboardController extends BaseController
{
    /**
     * 构造方法。
     *
     * @param AdminDashboardService $adminDashboardService 运营首页聚合服务
     * @return void
     */
    public function __construct(
        protected AdminDashboardService $adminDashboardService
    ) {
    }

    /**
     * 查询运营首页总览。
     *
     * @param Request $request 请求对象
     * @return Response 响应对象
     */
    public function overview(Request $request): Response
    {
        return $this->success($this->adminDashboardService->overview());
    }
}
