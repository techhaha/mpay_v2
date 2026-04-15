<?php

namespace app\http\admin\controller\ops;

use app\common\base\BaseController;
use app\http\admin\validation\ChannelNotifyLogValidator;
use app\service\ops\log\ChannelNotifyLogService;
use support\Request;
use support\Response;

/**
 * 渠道通知日志控制器。
 */
class ChannelNotifyLogController extends BaseController
{
    /**
     * 构造函数，注入渠道通知日志服务。
     */
    public function __construct(
        protected ChannelNotifyLogService $channelNotifyLogService
    ) {
    }

    /**
     * 查询渠道通知日志列表。
     */
    public function index(Request $request): Response
    {
        $data = $this->validated($request->all(), ChannelNotifyLogValidator::class, 'index');

        return $this->page(
            $this->channelNotifyLogService->paginate(
                $data,
                (int) ($data['page'] ?? 1),
                (int) ($data['page_size'] ?? 10)
            )
        );
    }

    /**
     * 查询渠道通知日志详情。
     */
    public function show(Request $request, string $id): Response
    {
        $data = $this->validated(['id' => (int) $id], ChannelNotifyLogValidator::class, 'show');
        $log = $this->channelNotifyLogService->findById((int) $data['id']);

        if (!$log) {
            return $this->fail('渠道通知日志不存在', 404);
        }

        return $this->success($log);
    }
}
