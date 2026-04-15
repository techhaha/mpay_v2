<?php

namespace app\http\admin\controller\payment;

use app\common\base\BaseController;
use app\http\admin\validation\PaymentPluginConfValidator;
use app\service\payment\config\PaymentPluginConfService;
use support\Request;
use support\Response;

/**
 * 支付插件配置控制器。
 *
 * 负责插件公共配置的列表、详情、增删改和选项输出。
 */
class PaymentPluginConfController extends BaseController
{
    public function __construct(
        protected PaymentPluginConfService $paymentPluginConfService
    ) {
    }

    public function index(Request $request): Response
    {
        $data = $this->validated($request->all(), PaymentPluginConfValidator::class, 'index');
        $page = max(1, (int) ($data['page'] ?? 1));
        $pageSize = max(1, (int) ($data['page_size'] ?? 10));

        return $this->page($this->paymentPluginConfService->paginate($data, $page, $pageSize));
    }

    public function show(Request $request, string $id): Response
    {
        $data = $this->validated(['id' => (int) $id], PaymentPluginConfValidator::class, 'show');
        $pluginConf = $this->paymentPluginConfService->findById((int) $data['id']);

        if (!$pluginConf) {
            return $this->fail('插件配置不存在', 404);
        }

        return $this->success($pluginConf);
    }

    public function store(Request $request): Response
    {
        $data = $this->validated($request->all(), PaymentPluginConfValidator::class, 'store');

        return $this->success($this->paymentPluginConfService->create($data));
    }

    public function update(Request $request, string $id): Response
    {
        $data = $this->validated(
            array_merge($request->all(), ['id' => (int) $id]),
            PaymentPluginConfValidator::class,
            'update'
        );

        $pluginConf = $this->paymentPluginConfService->update((int) $data['id'], $data);
        if (!$pluginConf) {
            return $this->fail('插件配置不存在', 404);
        }

        return $this->success($pluginConf);
    }

    public function destroy(Request $request, string $id): Response
    {
        $data = $this->validated(['id' => (int) $id], PaymentPluginConfValidator::class, 'destroy');

        if (!$this->paymentPluginConfService->delete((int) $data['id'])) {
            return $this->fail('插件配置不存在', 404);
        }

        return $this->success(true);
    }

    public function options(Request $request): Response
    {
        $data = $this->validated($request->all(), PaymentPluginConfValidator::class, 'options');

        return $this->success([
            'configs' => $this->paymentPluginConfService->options((string) ($data['plugin_code'] ?? '')),
        ]);
    }

    /**
     * 远程查询插件配置选项。
     */
    public function selectOptions(Request $request): Response
    {
        $data = $this->validated($request->all(), PaymentPluginConfValidator::class, 'selectOptions');
        $page = max(1, (int) ($data['page'] ?? 1));
        $pageSize = min(50, max(1, (int) ($data['page_size'] ?? 20)));

        return $this->success($this->paymentPluginConfService->searchOptions($data, $page, $pageSize));
    }
}
