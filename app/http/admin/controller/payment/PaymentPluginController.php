<?php

namespace app\http\admin\controller\payment;

use app\common\base\BaseController;
use app\http\admin\validation\PaymentPluginValidator;
use app\service\payment\config\PaymentPluginService;
use support\Request;
use support\Response;

/**
 * 支付插件管理控制器。
 *
 * 负责插件字典的列表、详情、刷新同步和状态备注维护。
 */
class PaymentPluginController extends BaseController
{
    /**
     * 构造函数，注入支付插件服务。
     */
    public function __construct(
        protected PaymentPluginService $paymentPluginService
    ) {
    }

    /**
     * 查询支付插件列表。
     */
    public function index(Request $request): Response
    {
        $data = $this->validated($request->all(), PaymentPluginValidator::class, 'index');
        $page = max(1, (int) ($data['page'] ?? 1));
        $pageSize = max(1, (int) ($data['page_size'] ?? 10));

        return $this->page($this->paymentPluginService->paginate($data, $page, $pageSize));
    }

    /**
     * 查询支付插件详情。
     */
    public function show(Request $request, string $code): Response
    {
        $data = $this->validated(['code' => $code], PaymentPluginValidator::class, 'show');
        $paymentPlugin = $this->paymentPluginService->findByCode((string) $data['code']);

        if (!$paymentPlugin) {
            return $this->fail('支付插件不存在', 404);
        }

        return $this->success($paymentPlugin);
    }

    /**
     * 修改支付插件。
     */
    public function update(Request $request, string $code): Response
    {
        $payload = $request->all();
        $scene = array_diff(array_keys($payload), ['status']) === [] ? 'updateStatus' : 'update';
        $data = $this->validated(
            array_merge($payload, ['code' => $code]),
            PaymentPluginValidator::class,
            $scene
        );

        $paymentPlugin = $this->paymentPluginService->update((string) $data['code'], $data);
        if (!$paymentPlugin) {
            return $this->fail('支付插件不存在', 404);
        }

        return $this->success($paymentPlugin);
    }

    /**
     * 从插件目录刷新同步支付插件。
     */
    public function refresh(Request $request): Response
    {
        return $this->success($this->paymentPluginService->refreshFromClasses());
    }

    /**
     * 查询支付插件下拉选项。
     */
    public function options(Request $request): Response
    {
        return $this->success([
            'plugins' => $this->paymentPluginService->enabledOptions(),
        ]);
    }

    /**
     * 远程查询支付插件选项。
     */
    public function selectOptions(Request $request): Response
    {
        $data = $this->validated($request->all(), PaymentPluginValidator::class, 'selectOptions');
        $page = max(1, (int) ($data['page'] ?? 1));
        $pageSize = min(50, max(1, (int) ($data['page_size'] ?? 20)));

        return $this->success($this->paymentPluginService->searchOptions($data, $page, $pageSize));
    }

    /**
     * 查询通道配置场景下的插件选项。
     */
    public function channelOptions(Request $request): Response
    {
        return $this->success([
            'plugins' => $this->paymentPluginService->channelOptions(),
        ]);
    }

    /**
     * 查询插件配置结构。
     */
    public function schema(Request $request, string $code): Response
    {
        $data = $this->validated(['code' => $code], PaymentPluginValidator::class, 'show');

        return $this->success($this->paymentPluginService->getSchema((string) $data['code']));
    }
}
