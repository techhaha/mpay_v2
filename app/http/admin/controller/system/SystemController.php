<?php

namespace app\http\admin\controller\system;

use app\common\base\BaseController;
use app\service\bootstrap\SystemBootstrapService;
use support\Request;
use support\Response;

/**
 * 管理后台系统数据控制器。
 */
class SystemController extends BaseController
{
    public function __construct(
        protected SystemBootstrapService $systemBootstrapService
    ) {
    }

    public function menuTree(Request $request): Response
    {
        return $this->success($this->systemBootstrapService->getMenuTree('admin'));
    }

    public function dictItems(Request $request): Response
    {
        return $this->success($this->systemBootstrapService->getDictItems((string) $request->get('code', '')));
    }
}

