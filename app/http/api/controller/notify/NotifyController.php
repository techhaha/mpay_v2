<?php

namespace app\http\api\controller\notify;

use app\common\base\BaseController;
use app\http\api\validation\NotifyChannelValidator;
use app\http\api\validation\NotifyMerchantValidator;
use app\service\payment\runtime\NotifyService;
use support\Request;
use support\Response;

/**
 * 通知记录控制器。
 *
 * 负责渠道通知日志和商户通知任务的接入，不承担真实业务回调处理。
 *
 * @property NotifyService $notifyService 通知服务
 */
class NotifyController extends BaseController
{
    /**
     * 构造方法。
     *
     * @param NotifyService $notifyService 通知服务
     * @return void
     */
    public function __construct(
        protected NotifyService $notifyService
    ) {
    }

    /**
     * 记录渠道通知日志。
     *
     * 用于保存外部渠道发来的通知原文，便于后续排查和审计。
     *
     * @param Request $request 请求对象
     * @return Response 响应对象
     */
    public function channel(Request $request): Response
    {
        $data = $this->validated($request->all(), NotifyChannelValidator::class, 'store');

        return $this->success($this->notifyService->recordChannelNotify($data));
    }

    /**
     * 创建商户通知任务。
     *
     * 由支付或结算完成后触发，把通知任务交给异步通知链路处理。
     *
     * @param Request $request 请求对象
     * @return Response 响应对象
     */
    public function merchant(Request $request): Response
    {
        $data = $this->validated($request->all(), NotifyMerchantValidator::class, 'store');

        return $this->success($this->notifyService->enqueueMerchantNotify($data));
    }
}






