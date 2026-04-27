<?php

namespace app\http\admin\controller\ops;

use app\common\base\BaseController;
use app\http\admin\validation\MerchantNotifyTaskValidator;
use app\service\ops\log\MerchantNotifyTaskService;
use support\Request;
use support\Response;

/**
 * 商户通知任务控制器。
 *
 * @property MerchantNotifyTaskService $merchantNotifyTaskService 商户通知任务服务
 */
class MerchantNotifyTaskController extends BaseController
{
    /**
     * 构造方法。
     *
     * @param MerchantNotifyTaskService $merchantNotifyTaskService 商户通知任务服务
     * @return void
     */
    public function __construct(
        protected MerchantNotifyTaskService $merchantNotifyTaskService
    ) {
    }

    /**
     * 查询商户通知任务列表。
     *
     * @param Request $request 请求对象
     * @return Response 响应对象
     */
    public function index(Request $request): Response
    {
        $data = $this->validated($request->all(), MerchantNotifyTaskValidator::class, 'index');

        return $this->page(
            $this->merchantNotifyTaskService->paginate(
                $data,
                (int) ($data['page'] ?? 1),
                (int) ($data['page_size'] ?? 10)
            )
        );
    }

    /**
     * 查询商户通知任务详情。
     *
     * @param Request $request 请求对象
     * @param string $notifyNo 通知号
     * @return Response 响应对象
     */
    public function show(Request $request, string $notifyNo): Response
    {
        $data = $this->validated(['notify_no' => $notifyNo], MerchantNotifyTaskValidator::class, 'show');
        $task = $this->merchantNotifyTaskService->findByNotifyNo((string) $data['notify_no']);

        if (!$task) {
            return $this->fail('商户通知任务不存在', 404);
        }

        return $this->success($task);
    }

    /**
     * 手动重试商户通知任务。
     *
     * @param Request $request 请求对象
     * @param string $notifyNo 通知号
     * @return Response 响应对象
     */
    public function retry(Request $request, string $notifyNo): Response
    {
        $data = $this->validated(['notify_no' => $notifyNo], MerchantNotifyTaskValidator::class, 'retry');
        $task = $this->merchantNotifyTaskService->retry((string) $data['notify_no']);

        if (!$task) {
            return $this->fail('商户通知任务不存在', 404);
        }

        return $this->success($task, '商户通知任务已执行重试');
    }
}
