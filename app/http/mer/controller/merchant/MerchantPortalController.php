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
 */
class MerchantPortalController extends BaseController
{
    public function __construct(
        protected MerchantPortalService $merchantPortalService
    ) {
    }

    /**
     * 当前商户资料。
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

    /**
     * 路由预览。
     */
    public function routePreview(Request $request): Response
    {
        $merchantId = $this->currentMerchantId($request);
        if ($merchantId <= 0) {
            return $this->fail('未获取到当前商户信息', 401);
        }

        $payload = $this->validated($this->payload($request), MerchantPortalValidator::class, 'routePreview');
        $payTypeId = (int) ($payload['pay_type_id'] ?? 0);
        $payAmount = (int) ($payload['pay_amount'] ?? 0);
        $statDate = trim((string) ($payload['stat_date'] ?? ''));

        return $this->success($this->merchantPortalService->routePreview($merchantId, $payTypeId, $payAmount, $statDate));
    }

    /**
     * 当前商户接口凭证。
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
     */
    public function issueCredential(Request $request): Response
    {
        $merchantId = $this->currentMerchantId($request);
        if ($merchantId <= 0) {
            return $this->fail('未获取到当前商户信息', 401);
        }

        return $this->success($this->merchantPortalService->issueCredential($merchantId));
    }

    /**
     * 当前商户的清算记录列表。
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
