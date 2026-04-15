<?php

namespace app\http\admin\controller\ops;

use app\common\base\BaseController;
use app\http\admin\validation\PayCallbackLogValidator;
use app\service\ops\log\PayCallbackLogService;
use support\Request;
use support\Response;

/**
 * 支付回调日志控制器。
 */
class PayCallbackLogController extends BaseController
{
    /**
     * 构造函数，注入支付回调日志服务。
     */
    public function __construct(
        protected PayCallbackLogService $payCallbackLogService
    ) {
    }

    /**
     * 查询支付回调日志列表。
     */
    public function index(Request $request): Response
    {
        $data = $this->validated($request->all(), PayCallbackLogValidator::class, 'index');

        return $this->page(
            $this->payCallbackLogService->paginate(
                $data,
                (int) ($data['page'] ?? 1),
                (int) ($data['page_size'] ?? 10)
            )
        );
    }

    /**
     * 查询支付回调日志详情。
     */
    public function show(Request $request, string $id): Response
    {
        $data = $this->validated(['id' => (int) $id], PayCallbackLogValidator::class, 'show');
        $log = $this->payCallbackLogService->findById((int) $data['id']);

        if (!$log) {
            return $this->fail('支付回调日志不存在', 404);
        }

        return $this->success($log);
    }
}
