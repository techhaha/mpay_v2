<?php

namespace app\http\admin\controller\system;

use app\common\base\BaseController;
use app\service\system\ops\SystemOpsCommandService;
use app\service\system\ops\SystemOpsStatusService;
use RuntimeException;
use support\Request;
use support\Response;

/**
 * 系统运维监控控制器。
 *
 * 控制器保持薄层：只接收请求和返回响应，状态聚合、命令白名单和操作记录都交给服务层处理。
 */
class SystemOpsController extends BaseController
{
    /**
     * 构造方法。
     *
     * @param SystemOpsStatusService $systemOpsStatusService 系统运维状态服务
     * @param SystemOpsCommandService $systemOpsCommandService 系统运维命令服务
     * @return void
     */
    public function __construct(
        protected SystemOpsStatusService $systemOpsStatusService,
        protected SystemOpsCommandService $systemOpsCommandService
    ) {
    }

    /**
     * 获取 Webman 运行监控总览。
     *
     * @param Request $request 请求对象
     * @return Response 响应对象
     */
    public function overview(Request $request): Response
    {
        return $this->success($this->systemOpsStatusService->overview());
    }

    /**
     * 平滑重载 Webman。
     *
     * @param Request $request 请求对象
     * @return Response 响应对象
     */
    public function reload(Request $request): Response
    {
        return $this->runCommand($request, 'reload');
    }

    /**
     * 重启 Webman。
     *
     * @param Request $request 请求对象
     * @return Response 响应对象
     */
    public function restart(Request $request): Response
    {
        return $this->runCommand($request, 'restart');
    }

    /**
     * 统一提交运维命令。
     *
     * 操作原因、IP 和 UA 在这里收集，具体动作是否合法由 SystemOpsCommandService 兜底。
     *
     * @param Request $request 请求对象
     * @param string $action 运维动作
     * @return Response 响应对象
     */
    private function runCommand(Request $request, string $action): Response
    {
        try {
            $result = $this->systemOpsCommandService->execute(
                $action,
                $this->currentAdminId($request),
                [
                    'reason' => (string) $request->post('reason', ''),
                    'ip' => (string) $request->getRealIp(),
                    'user_agent' => (string) $request->header('user-agent', ''),
                ]
            );

            return $this->success($result, '运维指令已提交');
        } catch (RuntimeException $e) {
            return $this->fail($e->getMessage(), 400);
        }
    }
}
