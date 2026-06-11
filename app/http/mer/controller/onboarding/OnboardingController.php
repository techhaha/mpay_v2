<?php

namespace app\http\mer\controller\onboarding;

use app\common\base\BaseController;
use app\common\constant\OnboardingConstant;
use app\http\mer\validation\OnboardingApplicationValidator;
use app\service\payment\onboarding\MerchantChannelOnboardingService;
use app\service\payment\onboarding\PaymentOnboardingConfigService;
use support\Request;
use support\Response;

/**
 * 商户端在线签约进件控制器。
 */
class OnboardingController extends BaseController
{
    public function __construct(
        protected PaymentOnboardingConfigService $configService,
        protected MerchantChannelOnboardingService $onboardingService
    ) {
    }

    /**
     * 获取商户可申请的在线签约渠道。
     */
    public function channels(Request $request): Response
    {
        return $this->success([
            'channels' => $this->configService->merchantChannels(),
        ]);
    }

    /**
     * 查询商户可见进件渠道的卡 BIN 信息。
     */
    public function cardBin(Request $request, string $id): Response
    {
        $data = $this->validated(array_merge($request->all(), ['id' => (int) $id]), OnboardingApplicationValidator::class, 'cardBin');

        return $this->success($this->configService->cardBin((int) $data['id'], (string) $data['card_no'], true));
    }

    /**
     * 获取当前商户的进件申请列表。
     */
    public function index(Request $request): Response
    {
        $data = $this->validated($request->all(), OnboardingApplicationValidator::class, 'index');

        return $this->page($this->onboardingService->merchantPaginate(
            $this->currentMerchantId($request),
            $data,
            (int) ($data['page'] ?? 1),
            (int) ($data['page_size'] ?? 10)
        ));
    }

    /**
     * 获取当前商户自己的进件申请详情。
     */
    public function show(Request $request, string $id): Response
    {
        $data = $this->validated(['id' => (int) $id], OnboardingApplicationValidator::class, 'show');
        $row = $this->onboardingService->findForMerchant($this->currentMerchantId($request), (int) $data['id']);

        return $row ? $this->success($row) : $this->fail('进件申请不存在', 404);
    }

    /**
     * 创建或更新当前商户的进件申请。
     */
    public function store(Request $request): Response
    {
        $data = $this->validated($request->all(), OnboardingApplicationValidator::class, 'store');

        return $this->success($this->onboardingService->createForMerchant(
            $this->currentMerchantId($request),
            $this->currentMerchantNo($request),
            $data
        ));
    }

    /**
     * 商户提交平台审核。
     */
    public function submit(Request $request, string $id): Response
    {
        $data = $this->validated(['id' => (int) $id], OnboardingApplicationValidator::class, 'action');
        $row = $this->onboardingService->submitForMerchant($this->currentMerchantId($request), (int) $data['id']);

        return $row ? $this->success($row) : $this->fail('进件申请不存在', 404);
    }

    /**
     * 商户查询自己的上游进件状态。
     */
    public function query(Request $request, string $id): Response
    {
        $data = $this->validated(['id' => (int) $id], OnboardingApplicationValidator::class, 'action');
        // 查询上游前先确认申请归属，后续 service 方法可被后台复用。
        if (!$this->onboardingService->findForMerchant($this->currentMerchantId($request), (int) $data['id'])) {
            return $this->fail('进件申请不存在', 404);
        }
        $row = $this->onboardingService->queryUpstream((int) $data['id'], OnboardingConstant::OPERATOR_MERCHANT, $this->currentMerchantId($request));

        return $row ? $this->success($row) : $this->fail('进件申请不存在', 404);
    }

    /**
     * 商户取消自己的进件申请。
     */
    public function cancel(Request $request, string $id): Response
    {
        $data = $this->validated(['id' => (int) $id], OnboardingApplicationValidator::class, 'action');
        // 取消也先在控制器层确认归属，避免通用 service 方法被商户端越权调用。
        if (!$this->onboardingService->findForMerchant($this->currentMerchantId($request), (int) $data['id'])) {
            return $this->fail('进件申请不存在', 404);
        }
        $row = $this->onboardingService->cancel((int) $data['id'], OnboardingConstant::OPERATOR_MERCHANT, $this->currentMerchantId($request));

        return $row ? $this->success($row) : $this->fail('进件申请不存在', 404);
    }
}
