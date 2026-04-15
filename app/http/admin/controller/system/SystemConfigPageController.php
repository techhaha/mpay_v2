<?php

namespace app\http\admin\controller\system;

use app\common\base\BaseController;
use app\http\admin\validation\SystemConfigPageValidator;
use app\service\system\config\SystemConfigPageService;
use support\Request;
use support\Response;

class SystemConfigPageController extends BaseController
{
    public function __construct(
        protected SystemConfigPageService $systemConfigPageService
    ) {
    }

    public function index(Request $request): Response
    {
        return $this->success($this->systemConfigPageService->tabs());
    }

    public function show(Request $request, string $groupCode): Response
    {
        $data = $this->validated(['group_code' => $groupCode], SystemConfigPageValidator::class, 'show');

        return $this->success($this->systemConfigPageService->detail((string) $data['group_code']));
    }

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
