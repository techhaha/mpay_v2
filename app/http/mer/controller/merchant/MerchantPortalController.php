<?php

namespace app\http\mer\controller\merchant;

use app\common\base\BaseController;
use app\http\mer\validation\MerchantPortalValidator;
use app\service\merchant\portal\MerchantPortalService;
use support\Request;
use support\Response;

/**
 * 商户后台基础页面控制器。
 *
 * 统一承接当前商户可见的资料、通道、路由、凭证、清算和资金页面数据。
 *
 * @property MerchantPortalService $merchantPortalService 商户门户服务
 */
class MerchantPortalController extends BaseController
{
    /**
 * 构造方法。
     *
     * @param MerchantPortalService $merchantPortalService 商户门户服务
     * @return void
     */
    public function __construct(
        protected MerchantPortalService $merchantPortalService
    ) {
    }

    /**
     * 当前商户资料。
     *
     * @param Request $request 请求对象
     * @return Response 响应对象
     */
    public function profile(Request $request): Response
    {
        $merchantId = $this->currentMerchantId($request);
        if ($merchantId <= 0) {
            return $this->fail('未获取到当前商户信息', 401);
        }

        return $this->success($this->merchantPortalService->profile($merchantId));
    }

    /**
     * 更新当前商户资料。
     *
     * @param Request $request 请求对象
     * @return Response 响应对象
     */
    public function updateProfile(Request $request): Response
    {
        $merchantId = $this->currentMerchantId($request);
        if ($merchantId <= 0) {
            return $this->fail('未获取到当前商户信息', 401);
        }

        $data = $this->validated($this->payload($request), MerchantPortalValidator::class, 'profileUpdate');

        return $this->success($this->merchantPortalService->updateProfile($merchantId, $data));
    }

    /**
     * 修改当前商户登录密码。
     *
     * @param Request $request 请求对象
     * @return Response 响应对象
     */
    public function changePassword(Request $request): Response
    {
        $merchantId = $this->currentMerchantId($request);
        if ($merchantId <= 0) {
            return $this->fail('未获取到当前商户信息', 401);
        }

        $data = $this->validated($this->payload($request), MerchantPortalValidator::class, 'passwordUpdate');

        return $this->success($this->merchantPortalService->changePassword($merchantId, $data));
    }

    /**
     * 我的通道列表。
     *
     * @param Request $request 请求对象
     * @return Response 响应对象
     */
    public function myChannels(Request $request): Response
    {
        $merchantId = $this->currentMerchantId($request);
        if ($merchantId <= 0) {
            return $this->fail('未获取到当前商户信息', 401);
        }

        $payload = $this->payload($request);
        $page = max(1, (int) ($payload['page'] ?? 1));
        $pageSize = max(1, (int) ($payload['page_size'] ?? 10));

        return $this->success($this->merchantPortalService->myChannels($payload, $merchantId, $page, $pageSize));
    }

    public function channelCreateMeta(Request $request): Response
    {
        return $this->success($this->merchantPortalService->channelCreateMeta());
    }

    public function createChannel(Request $request): Response
    {
        $merchantId = $this->currentMerchantId($request);
        if ($merchantId <= 0) {
            return $this->fail('未获取到当前商户信息', 401);
        }

        $data = $this->validated($this->payload($request), MerchantPortalValidator::class, 'channelStore');

        return $this->success($this->merchantPortalService->createChannel($merchantId, $data));
    }

    public function updateChannel(Request $request, string $id): Response
    {
        $merchantId = $this->currentMerchantId($request);
        if ($merchantId <= 0) {
            return $this->fail('未获取到当前商户信息', 401);
        }

        $data = $this->validated(
            array_merge($this->payload($request), ['id' => (int) $id]),
            MerchantPortalValidator::class,
            'channelUpdate'
        );

        $channel = $this->merchantPortalService->updateChannel($merchantId, (int) $data['id'], $data);
        if (!$channel) {
            return $this->fail('通道不存在', 404);
        }

        return $this->success($channel);
    }

    public function deleteChannel(Request $request, string $id): Response
    {
        $merchantId = $this->currentMerchantId($request);
        if ($merchantId <= 0) {
            return $this->fail('未获取到当前商户信息', 401);
        }

        $data = $this->validated(['id' => (int) $id], MerchantPortalValidator::class, 'channelDestroy');
        if (!$this->merchantPortalService->deleteChannel($merchantId, (int) $data['id'])) {
            return $this->fail('通道不存在', 404);
        }

        return $this->success(true);
    }

    public function pluginConfigs(Request $request): Response
    {
        $merchantId = $this->currentMerchantId($request);
        if ($merchantId <= 0) {
            return $this->fail('未获取到当前商户信息', 401);
        }

        $payload = $this->validated($this->payload($request), MerchantPortalValidator::class, 'pluginConfigIndex');
        $page = max(1, (int) ($payload['page'] ?? 1));
        $pageSize = max(1, (int) ($payload['page_size'] ?? 10));

        return $this->success($this->merchantPortalService->pluginConfigs($payload, $merchantId, $page, $pageSize));
    }

    public function pluginConfigDetail(Request $request, string $id): Response
    {
        $merchantId = $this->currentMerchantId($request);
        if ($merchantId <= 0) {
            return $this->fail('未获取到当前商户信息', 401);
        }

        $data = $this->validated(['id' => (int) $id], MerchantPortalValidator::class, 'pluginConfigShow');
        $config = $this->merchantPortalService->pluginConfigDetail($merchantId, (int) $data['id']);
        if (!$config) {
            return $this->fail('插件配置不存在', 404);
        }

        return $this->success($config);
    }

    public function createPluginConfig(Request $request): Response
    {
        $merchantId = $this->currentMerchantId($request);
        if ($merchantId <= 0) {
            return $this->fail('未获取到当前商户信息', 401);
        }

        $data = $this->validated($this->payload($request), MerchantPortalValidator::class, 'pluginConfigStore');

        return $this->success($this->merchantPortalService->createPluginConfig($merchantId, $data));
    }

    public function updatePluginConfig(Request $request, string $id): Response
    {
        $merchantId = $this->currentMerchantId($request);
        if ($merchantId <= 0) {
            return $this->fail('未获取到当前商户信息', 401);
        }

        $data = $this->validated(
            array_merge($this->payload($request), ['id' => (int) $id]),
            MerchantPortalValidator::class,
            'pluginConfigUpdate'
        );

        $config = $this->merchantPortalService->updatePluginConfig($merchantId, (int) $data['id'], $data);
        if (!$config) {
            return $this->fail('插件配置不存在', 404);
        }

        return $this->success($config);
    }

    public function deletePluginConfig(Request $request, string $id): Response
    {
        $merchantId = $this->currentMerchantId($request);
        if ($merchantId <= 0) {
            return $this->fail('未获取到当前商户信息', 401);
        }

        $data = $this->validated(['id' => (int) $id], MerchantPortalValidator::class, 'pluginConfigDestroy');
        if (!$this->merchantPortalService->deletePluginConfig($merchantId, (int) $data['id'])) {
            return $this->fail('插件配置不存在', 404);
        }

        return $this->success(true);
    }

    public function pluginConfigOptions(Request $request): Response
    {
        $merchantId = $this->currentMerchantId($request);
        if ($merchantId <= 0) {
            return $this->fail('未获取到当前商户信息', 401);
        }

        return $this->success([
            'configs' => $this->merchantPortalService->pluginConfigOptions($merchantId, (string) $request->get('plugin_code', '')),
        ]);
    }

    public function pluginSchema(Request $request, string $code): Response
    {
        return $this->success($this->merchantPortalService->pluginSchema($code));
    }

    /**
     * 获取路由解析结果。
     *
     * @param Request $request 请求对象
     * @return Response 响应对象
     */
    public function routePreview(Request $request): Response
    {
        $merchantId = $this->currentMerchantId($request);
        if ($merchantId <= 0) {
            return $this->fail('未获取到当前商户信息', 401);
        }

        $rawPayload = $this->payload($request);
        if (empty($rawPayload['pay_type_id']) || empty($rawPayload['pay_amount'])) {
            return $this->success($this->merchantPortalService->routePreview($merchantId, 0, 0));
        }

        $payload = $this->validated($rawPayload, MerchantPortalValidator::class, 'routePreview');
        $payTypeId = (int) ($payload['pay_type_id'] ?? 0);
        $payAmount = (int) ($payload['pay_amount'] ?? 0);
        $statDate = trim((string) ($payload['stat_date'] ?? ''));

        return $this->success($this->merchantPortalService->routePreview($merchantId, $payTypeId, $payAmount, $statDate));
    }

    /**
     * 当前商户路由偏好配置。
     *
     * @param Request $request 请求对象
     * @return Response 响应对象
     */
    public function routeConfig(Request $request): Response
    {
        $merchantId = $this->currentMerchantId($request);
        if ($merchantId <= 0) {
            return $this->fail('未获取到当前商户信息', 401);
        }

        return $this->success($this->merchantPortalService->routeConfig($merchantId));
    }

    /**
     * 保存当前商户路由偏好配置。
     *
     * @param Request $request 请求对象
     * @return Response 响应对象
     */
    public function updateRouteConfig(Request $request): Response
    {
        $merchantId = $this->currentMerchantId($request);
        if ($merchantId <= 0) {
            return $this->fail('未获取到当前商户信息', 401);
        }

        $payload = $this->validated($this->payload($request), MerchantPortalValidator::class, 'routeConfigUpdate');

        return $this->success($this->merchantPortalService->saveRouteConfig($merchantId, $payload));
    }

    /**
     * 当前商户 API 凭证。
     *
     * @param Request $request 请求对象
     * @return Response 响应对象
     */
    public function apiCredential(Request $request): Response
    {
        $merchantId = $this->currentMerchantId($request);
        if ($merchantId <= 0) {
            return $this->fail('未获取到当前商户信息', 401);
        }

        return $this->success($this->merchantPortalService->apiCredential($merchantId));
    }

    /**
     * 生成或重置接口凭证。
     *
     * @param Request $request 请求对象
     * @return Response 响应对象
     */
    public function issueCredential(Request $request): Response
    {
        $merchantId = $this->currentMerchantId($request);
        if ($merchantId <= 0) {
            return $this->fail('未获取到当前商户信息', 401);
        }

        $data = $this->validated($this->payload($request), MerchantPortalValidator::class, 'issueCredential');

        return $this->success($this->merchantPortalService->issueCredential($merchantId, $data));
    }

    /**
     * 当前商户的清算记录列表。
     *
     * @param Request $request 请求对象
     * @return Response 响应对象
     */
    public function settlementRecords(Request $request): Response
    {
        $merchantId = $this->currentMerchantId($request);
        if ($merchantId <= 0) {
            return $this->fail('未获取到当前商户信息', 401);
        }

        $payload = $this->payload($request);
        $page = max(1, (int) ($payload['page'] ?? 1));
        $pageSize = max(1, (int) ($payload['page_size'] ?? 10));

        return $this->success($this->merchantPortalService->settlementRecords($payload, $merchantId, $page, $pageSize));
    }

    /**
     * 当前商户的清算记录详情。
     *
     * @param Request $request 请求对象
     * @param string $settleNo 结算单号
     * @return Response 响应对象
     */
    public function settlementRecordShow(Request $request, string $settleNo): Response
    {
        $merchantId = $this->currentMerchantId($request);
        if ($merchantId <= 0) {
            return $this->fail('未获取到当前商户信息', 401);
        }

        $detail = $this->merchantPortalService->settlementRecordDetail($settleNo, $merchantId);
        if (!$detail) {
            return $this->fail('清算记录不存在', 404);
        }

        return $this->success($detail);
    }

    /**
     * 当前商户可提现余额快照。
     *
     * @param Request $request 请求对象
     * @return Response 响应对象
     */
    public function withdrawableBalance(Request $request): Response
    {
        $merchantId = $this->currentMerchantId($request);
        if ($merchantId <= 0) {
            return $this->fail('未获取到当前商户信息', 401);
        }

        return $this->success($this->merchantPortalService->withdrawableBalance($merchantId));
    }

    /**
     * 当前商户资金流水列表。
     *
     * @param Request $request 请求对象
     * @return Response 响应对象
     */
    public function balanceFlows(Request $request): Response
    {
        $merchantId = $this->currentMerchantId($request);
        if ($merchantId <= 0) {
            return $this->fail('未获取到当前商户信息', 401);
        }

        $payload = $this->payload($request);
        $page = max(1, (int) ($payload['page'] ?? 1));
        $pageSize = max(1, (int) ($payload['page_size'] ?? 10));

        return $this->success($this->merchantPortalService->balanceFlows($payload, $merchantId, $page, $pageSize));
    }
}





