<?php

namespace app\http\mer\controller\system;

use app\common\base\BaseController;
use app\service\bootstrap\SystemBootstrapService;
use app\service\system\config\SystemPublicConfigService;
use support\Request;
use support\Response;

/**
 * 商户后台系统数据控制器。
 *
 * @property SystemBootstrapService $systemBootstrapService 系统引导服务
 * @property SystemPublicConfigService $systemPublicConfigService 系统公开配置服务
 */
class SystemController extends BaseController
{
    /**
     * 构造方法。
     *
     * @param SystemBootstrapService $systemBootstrapService 系统引导服务
     * @param SystemPublicConfigService $systemPublicConfigService 系统公开配置服务
     * @return void
     */
    public function __construct(
        protected SystemBootstrapService $systemBootstrapService,
        protected SystemPublicConfigService $systemPublicConfigService
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
        return $this->success($this->systemBootstrapService->getMenuTree('merchant'));
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

    /**
     * 获取商户后台公开展示配置。
     *
     * @param Request $request 请求对象
     * @return Response 响应对象
     */
    public function publicConfig(Request $request): Response
    {
        return $this->success($this->systemPublicConfigService->merchantPortal());
    }
}






