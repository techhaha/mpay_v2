<?php

namespace app\http\admin\controller\system;

use app\common\base\BaseController;
use app\service\bootstrap\SystemBootstrapService;
use support\Request;
use support\Response;

/**
 * 管理后台系统数据控制器。
 *
 * @property SystemBootstrapService $systemBootstrapService 系统引导服务
 */
class SystemController extends BaseController
{
    /**
 * 构造方法。
     *
     * @param SystemBootstrapService $systemBootstrapService 系统引导服务
     * @return void
     */
    public function __construct(
        protected SystemBootstrapService $systemBootstrapService
    ) {
    }

    /**
     * 获取系统菜单树
     *
     * @param Request $request 请求对象
     * @return Response 响应对象
     */
    public function menuTree(Request $request): Response
    {
        return $this->success($this->systemBootstrapService->getMenuTree('admin'));
    }

    /**
     * 获取系统字典项
     *
     * @param Request $request 请求对象
     * @return Response 响应对象
     */
    public function dictItems(Request $request): Response
    {
        return $this->success($this->systemBootstrapService->getDictItems((string) $request->get('code', '')));
    }
}






