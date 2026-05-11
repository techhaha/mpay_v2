<?php

namespace app\http\admin\controller\system;

use app\common\base\BaseController;
use app\http\admin\validation\SystemConfigPageValidator;
use app\service\system\config\SystemConfigPageService;
use support\Request;
use support\Response;

/**
 * 系统配置页面控制器
 *
 * @property SystemConfigPageService $systemConfigPageService 系统配置页面服务
 */
class SystemConfigPageController extends BaseController
{
    /**
     * 构造方法。
     *
     * @param SystemConfigPageService $systemConfigPageService 系统配置页面服务
     * @return void
     */
    public function __construct(
        protected SystemConfigPageService $systemConfigPageService
    ) {
    }

    /**
     * 查询系统配置页面列表
     *
     * @param Request $request 请求对象
     * @return Response 响应对象
     */
    public function index(Request $request): Response
    {
        return $this->success($this->systemConfigPageService->tabs());
    }

    /**
     * 查询系统配置页面详情
     *
     * @param Request $request 请求对象
     * @param string $groupCode 分组Code
     * @return Response 响应对象
     */
    public function show(Request $request, string $groupCode): Response
    {
        $data = $this->validated(['group_code' => $groupCode], SystemConfigPageValidator::class, 'show');

        return $this->success($this->systemConfigPageService->detail((string) $data['group_code']));
    }

    /**
     * 新增系统配置页面
     *
     * @param Request $request 请求对象
     * @param string $groupCode 分组Code
     * @return Response 响应对象
     */
    public function store(Request $request, string $groupCode): Response
    {
        $data = $this->validated(
            array_merge($this->payload($request), ['group_code' => $groupCode]),
            SystemConfigPageValidator::class,
            'store'
        );

        return $this->success(
            $this->systemConfigPageService->save((string) $data['group_code'], (array) ($data['values'] ?? []))
        );
    }
}





