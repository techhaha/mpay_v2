<?php

namespace app\http\api\controller\notify;

use app\common\base\BaseController;
use app\http\api\validation\NotifyChannelValidator;
use app\http\api\validation\NotifyMerchantValidator;
use app\service\payment\runtime\NotifyService;
use support\Request;
use support\Response;

/**
 * 通知与回调记录控制器。
 *
 * 负责渠道通知日志和商户通知任务的接入。
 */
class NotifyController extends BaseController
{
    /**
     * 构造函数，注入通知服务。
     */
    public function __construct(
        protected NotifyService $notifyService
    ) {
    }

    /**
     * POST /api/notify/channel
     *
     * 记录渠道通知日志。
     */
    public function channel(Request $request): Response
    {
        $data = $this->validated($request->all(), NotifyChannelValidator::class, 'store');

        return $this->success($this->notifyService->recordChannelNotify($data));
    }

    /**
     * POST /api/notify/merchant
     *
     * 创建商户通知任务。
     */
    public function merchant(Request $request): Response
    {
        $data = $this->validated($request->all(), NotifyMerchantValidator::class, 'store');

        return $this->success($this->notifyService->enqueueMerchantNotify($data));
    }
}

