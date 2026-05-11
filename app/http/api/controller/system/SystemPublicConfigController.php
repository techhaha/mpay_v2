<?php

namespace app\http\api\controller\system;

use app\common\base\BaseController;
use app\service\system\config\SystemPublicConfigService;
use support\Request;
use support\Response;

/**
 * 公开系统配置控制器。
 */
class SystemPublicConfigController extends BaseController
{
    /**
     * 构造方法。
     *
     * @param SystemPublicConfigService $systemPublicConfigService 系统公开配置服务
     */
    public function __construct(
        protected SystemPublicConfigService $systemPublicConfigService
    ) {
    }

    /**
     * 查询收银台公开配置。
     *
     * @param Request $request 请求对象
     * @return Response 响应对象
     */
    public function cashier(Request $request): Response
    {
        return $this->success($this->systemPublicConfigService->cashier());
    }
}
