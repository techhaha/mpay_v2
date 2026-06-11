<?php

namespace app\http\admin\controller\merchant;

use app\common\base\BaseController;
use app\common\constant\OnboardingConstant;
use app\http\admin\validation\MerchantChannelOnboardingValidator;
use app\service\payment\onboarding\MerchantChannelOnboardingService;
use support\Request;
use support\Response;

/**
 * 管理后台商户支付渠道进件控制器。
 */
class MerchantChannelOnboardingController extends BaseController
{
    public function __construct(
        protected MerchantChannelOnboardingService $service
    ) {
    }

    /**
     * 获取后台商户进件申请列表。
     */
    public function index(Request $request): Response
    {
        $data = $this->validated($request->all(), MerchantChannelOnboardingValidator::class, 'index');

        return $this->page($this->service->adminPaginate($data, (int) ($data['page'] ?? 1), (int) ($data['page_size'] ?? 10)));
    }

    /**
     * 获取后台商户进件申请详情。
     */
    public function show(Request $request, string $id): Response
    {
        $data = $this->validated(['id' => (int) $id], MerchantChannelOnboardingValidator::class, 'show');
        $row = $this->service->findForAdmin((int) $data['id']);

        return $row ? $this->success($row) : $this->fail('进件申请不存在', 404);
    }

    /**
     * 后台手动创建商户进件申请。
     */
    public function store(Request $request): Response
    {
        $data = $this->validated($request->all(), MerchantChannelOnboardingValidator::class, 'store');

        return $this->success($this->service->createForAdmin($data, $this->currentAdminId($request)));
    }

    /**
     * 审核商户提交的平台预审申请。
     */
    public function review(Request $request, string $id): Response
    {
        $data = $this->validated(array_merge($request->all(), ['id' => (int) $id]), MerchantChannelOnboardingValidator::class, 'review');
        $row = $this->service->review((int) $data['id'], $data, $this->currentAdminId($request));

        return $row ? $this->success($row) : $this->fail('进件申请不存在', 404);
    }

    /**
     * 提交进件申请到上游服务商。
     */
    public function submit(Request $request, string $id): Response
    {
        $data = $this->validated(['id' => (int) $id], MerchantChannelOnboardingValidator::class, 'action');
        $row = $this->service->submitUpstream((int) $data['id'], $this->currentAdminId($request));

        return $row ? $this->success($row) : $this->fail('进件申请不存在', 404);
    }

    /**
     * 查询并同步上游进件状态。
     */
    public function query(Request $request, string $id): Response
    {
        $data = $this->validated(['id' => (int) $id], MerchantChannelOnboardingValidator::class, 'action');
        $row = $this->service->queryUpstream((int) $data['id'], OnboardingConstant::OPERATOR_ADMIN, $this->currentAdminId($request));

        return $row ? $this->success($row) : $this->fail('进件申请不存在', 404);
    }

    /**
     * 提交上游进件复议。
     */
    public function reconsider(Request $request, string $id): Response
    {
        $data = $this->validated(['id' => (int) $id], MerchantChannelOnboardingValidator::class, 'action');
        $row = $this->service->reconsiderUpstream((int) $data['id'], $this->currentAdminId($request));

        return $row ? $this->success($row) : $this->fail('进件申请不存在', 404);
    }

    /**
     * 后台手动绑定已签约的上游商户信息。
     */
    public function manualBind(Request $request, string $id): Response
    {
        $data = $this->validated(array_merge($request->all(), ['id' => (int) $id]), MerchantChannelOnboardingValidator::class, 'manualBind');
        $row = $this->service->manualBind((int) $data['id'], $data, $this->currentAdminId($request));

        return $row ? $this->success($row) : $this->fail('进件申请不存在', 404);
    }

    /**
     * 取消商户进件申请。
     */
    public function cancel(Request $request, string $id): Response
    {
        $data = $this->validated(['id' => (int) $id], MerchantChannelOnboardingValidator::class, 'action');
        $row = $this->service->cancel((int) $data['id'], OnboardingConstant::OPERATOR_ADMIN, $this->currentAdminId($request));

        return $row ? $this->success($row) : $this->fail('进件申请不存在', 404);
    }
}
