<?php

namespace app\http\admin\controller\payment;

use app\common\base\BaseController;
use app\http\admin\validation\PaymentOnboardingConfigValidator;
use app\service\payment\onboarding\PaymentOnboardingConfigService;
use support\Request;
use support\Response;

/**
 * 支付插件进件配置控制器。
 */
class PaymentOnboardingConfigController extends BaseController
{
    public function __construct(
        protected PaymentOnboardingConfigService $service
    ) {
    }

    /**
     * 获取插件进件配置列表。
     */
    public function index(Request $request): Response
    {
        $data = $this->validated($request->all(), PaymentOnboardingConfigValidator::class, 'index');

        return $this->page($this->service->paginate($data, (int) ($data['page'] ?? 1), (int) ($data['page_size'] ?? 10)));
    }

    /**
     * 获取插件进件配置详情。
     */
    public function show(Request $request, string $id): Response
    {
        $data = $this->validated(['id' => (int) $id], PaymentOnboardingConfigValidator::class, 'show');
        $row = $this->service->findById((int) $data['id']);

        return $row ? $this->success($row) : $this->fail('进件配置不存在', 404);
    }

    /**
     * 查询插件进件配置的卡 BIN 信息。
     */
    public function cardBin(Request $request, string $id): Response
    {
        $data = $this->validated(array_merge($request->all(), ['id' => (int) $id]), PaymentOnboardingConfigValidator::class, 'cardBin');

        return $this->success($this->service->cardBin((int) $data['id'], (string) $data['card_no']));
    }

    /**
     * 创建插件进件配置。
     */
    public function store(Request $request): Response
    {
        $data = $this->validated($request->all(), PaymentOnboardingConfigValidator::class, 'store');

        return $this->success($this->service->create($data));
    }

    /**
     * 更新插件进件配置。
     */
    public function update(Request $request, string $id): Response
    {
        $data = $this->validated(array_merge($request->all(), ['id' => (int) $id]), PaymentOnboardingConfigValidator::class, 'update');
        $row = $this->service->update((int) $data['id'], $data);

        return $row ? $this->success($row) : $this->fail('进件配置不存在', 404);
    }

    /**
     * 删除插件进件配置。
     */
    public function destroy(Request $request, string $id): Response
    {
        $data = $this->validated(['id' => (int) $id], PaymentOnboardingConfigValidator::class, 'destroy');

        return $this->success($this->service->delete((int) $data['id']));
    }
}
