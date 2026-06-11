<?php

namespace app\http\api\controller\payment;

use app\common\base\BaseController;
use app\service\payment\onboarding\MerchantChannelOnboardingService;
use support\Request;
use support\Response;

/**
 * 公开进件回调控制器。
 */
class PaymentOnboardingNotifyController extends BaseController
{
    public function __construct(
        protected MerchantChannelOnboardingService $service
    ) {
    }

    /**
     * 接收上游进件通知。
     *
     * 路由携带 pluginCode 和 configId，服务层会据此实例化对应进件插件完成验签和解析。
     */
    public function notify(Request $request, string $pluginCode, string $configId): Response
    {
        $body = $this->service->handleNotify((string) $pluginCode, (int) $configId, $request);
        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            return json($decoded);
        }

        return response($body);
    }
}
