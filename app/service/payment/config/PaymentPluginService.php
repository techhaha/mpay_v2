<?php

namespace app\service\payment\config;

use app\common\base\BaseService;
use app\common\constant\PaymentPluginTypeConstant;
use app\exception\PaymentException;
use app\model\payment\PaymentPlugin;
use app\repository\payment\config\PaymentPluginRepository;

/**
 * 支付插件管理服务。
 *
 * 负责插件目录同步、插件列表查询，以及 JSON 字段写入前的归一化。
 *
 * @property PaymentPluginRepository $paymentPluginRepository 支付插件仓库
 * @property PaymentPluginSyncService $paymentPluginSyncService 支付插件同步服务
 */
class PaymentPluginService extends BaseService
{
    /**
     * 构造方法。
     *
     * @param PaymentPluginRepository $paymentPluginRepository 支付插件仓库
     * @param PaymentPluginSyncService $paymentPluginSyncService 支付插件同步服务
     * @return void
     */
    public function __construct(
        protected PaymentPluginRepository $paymentPluginRepository,
        protected PaymentPluginSyncService $paymentPluginSyncService
    ) {
    }

    /**
     * 分页查询支付插件。
     *
     * @param array $filters 筛选条件
     * @param int $page 页码
     * @param int $pageSize 每页条数
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator 分页结果
     */
    public function paginate(array $filters = [], int $page = 1, int $pageSize = 10)
    {
        $query = $this->paymentPluginRepository->query();

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->where(function ($builder) use ($keyword) {
                $builder->where('code', 'like', '%' . $keyword . '%')
                    ->orWhere('name', 'like', '%' . $keyword . '%')
                    ->orWhere('class_name', 'like', '%' . $keyword . '%');
            });
        }

        $code = trim((string) ($filters['code'] ?? ''));
        if ($code !== '') {
            $query->where('code', 'like', '%' . $code . '%');
        }

        $name = trim((string) ($filters['name'] ?? ''));
        if ($name !== '') {
            $query->where('name', 'like', '%' . $name . '%');
        }

        if (array_key_exists('status', $filters) && $filters['status'] !== '') {
            $query->where('status', (int) $filters['status']);
        }

        $pluginType = (int) ($filters['plugin_type'] ?? 0);
        if (PaymentPluginTypeConstant::isValid($pluginType)) {
            $query->where('plugin_type', $pluginType);
        }

        $paginator = $query
            ->orderBy('code')
            ->paginate(max(1, $pageSize), ['*'], 'page', max(1, $page));

        $paginator->getCollection()->transform(function (PaymentPlugin $plugin): PaymentPlugin {
            $this->appendTypeText($plugin);

            return $plugin;
        });

        return $paginator;
    }

    /**
     * 查询启用中的支付插件选项。
     *
     * @return array<int, array{label: string, value: string, code: string, name: string, plugin_type: int, plugin_type_text: string}> 启用插件选项
     */
    public function enabledOptions(): array
    {
        return $this->paymentPluginRepository->enabledList(['code', 'name', 'plugin_type'])
            ->map(function (PaymentPlugin $plugin): array {
                return [
                    'label' => sprintf('%s（%s）', (string) $plugin->name, (string) $plugin->code),
                    'value' => (string) $plugin->code,
                    'code' => (string) $plugin->code,
                    'name' => (string) $plugin->name,
                    'plugin_type' => (int) $plugin->plugin_type,
                    'plugin_type_text' => PaymentPluginTypeConstant::label((int) $plugin->plugin_type),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * 搜索支付插件选择项。
     *
     * @param array $filters 筛选条件
     * @param int $page 页码
     * @param int $pageSize 每页条数
     * @return array{list: array<int, array{label: string, value: string, code: string, name: string, plugin_type: int, plugin_type_text: string, pay_types: array<int, string>}>, total: int, page: int, size: int} 插件搜索结果
     */
    public function searchOptions(array $filters = [], int $page = 1, int $pageSize = 20): array
    {
        $query = $this->paymentPluginRepository->query()
            ->where('status', 1)
            ->select(['code', 'name', 'plugin_type', 'pay_types'])
            ->orderBy('code');

        $ids = $filters['ids'] ?? [];
        if (is_array($ids) && $ids !== []) {
            // 显式传 ID 时优先按编码集合回显，避免关键词过滤把手工选择项漏掉。
            $query->whereIn('code', array_values(array_filter(array_map('strval', $ids))));
        } else {
            $keyword = trim((string) ($filters['keyword'] ?? ''));
            if ($keyword !== '') {
                $query->where(function ($builder) use ($keyword) {
                    $builder->where('code', 'like', '%' . $keyword . '%')
                        ->orWhere('name', 'like', '%' . $keyword . '%');
                });
            }

            $payTypeCode = trim((string) ($filters['pay_type_code'] ?? ''));
            if ($payTypeCode !== '') {
                // 如果前端按支付方式筛选，就只保留 pay_types 中包含该编码的插件。
                $query->whereJsonContains('pay_types', $payTypeCode);
            }

            $pluginType = (int) ($filters['plugin_type'] ?? 0);
            if (PaymentPluginTypeConstant::isValid($pluginType)) {
                $query->where('plugin_type', $pluginType);
            }
        }

        $paginator = $query->paginate(max(1, $pageSize), ['*'], 'page', max(1, $page));

        return [
            'list' => collect($paginator->items())->map(function (PaymentPlugin $plugin): array {
                return [
                    'label' => sprintf('%s（%s）', (string) $plugin->name, (string) $plugin->code),
                    'value' => (string) $plugin->code,
                    'code' => (string) $plugin->code,
                    'name' => (string) $plugin->name,
                    'plugin_type' => (int) $plugin->plugin_type,
                    'plugin_type_text' => PaymentPluginTypeConstant::label((int) $plugin->plugin_type),
                    'pay_types' => is_array($plugin->pay_types) ? array_values($plugin->pay_types) : [],
                ];
            })->values()->all(),
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'size' => $paginator->perPage(),
        ];
    }

    /**
     * 查询通道配置场景使用的支付插件选项。
     *
     * @return array<int, array{label: string, value: string, code: string, name: string, plugin_type: int, plugin_type_text: string, pay_types: array<int, string>}> 通道配置选项
     */
    public function channelOptions(): array
    {
        // 通道配置场景只需要启用中的插件，并且要带上支付方式集合供前端联动展示。
        return $this->paymentPluginRepository->enabledList([
            'code',
            'name',
            'plugin_type',
            'pay_types',
        ])
            ->map(function (PaymentPlugin $plugin): array {
                return [
                    'label' => sprintf('%s（%s）', (string) $plugin->name, (string) $plugin->code),
                    'value' => (string) $plugin->code,
                    'code' => (string) $plugin->code,
                    'name' => (string) $plugin->name,
                    'plugin_type' => (int) $plugin->plugin_type,
                    'plugin_type_text' => PaymentPluginTypeConstant::label((int) $plugin->plugin_type),
                    'pay_types' => is_array($plugin->pay_types) ? array_values($plugin->pay_types) : [],
                ];
            })
            ->values()
            ->all();
    }

    /**
     * 查询声明进件能力的启用插件选项。
     *
     * @return array<int, array<string, mixed>> 进件插件选项
     */
    public function onboardingOptions(): array
    {
        return $this->paymentPluginRepository->enabledList([
            'code',
            'name',
            'plugin_type',
            'onboarding_types',
            'onboarding_info',
        ])
            ->filter(function (PaymentPlugin $plugin): bool {
                // 只展示已启用且声明进件能力的插件，是否启用以插件表状态为准。
                return is_array($plugin->onboarding_types) && $plugin->onboarding_types !== [];
            })
            ->map(function (PaymentPlugin $plugin): array {
                return [
                    'label' => sprintf('%s（%s）', (string) $plugin->name, (string) $plugin->code),
                    'value' => (string) $plugin->code,
                    'code' => (string) $plugin->code,
                    'name' => (string) $plugin->name,
                    'plugin_type' => (int) $plugin->plugin_type,
                    'plugin_type_text' => PaymentPluginTypeConstant::label((int) $plugin->plugin_type),
                    'onboarding_types' => is_array($plugin->onboarding_types) ? array_values($plugin->onboarding_types) : [],
                    'onboarding_info' => is_array($plugin->onboarding_info) ? $plugin->onboarding_info : [],
                ];
            })
            ->values()
            ->all();
    }

    /**
     * 按插件编码查询插件。
     *
     * @param string $code 插件编码
     * @return PaymentPlugin|null 插件模型
     */
    public function findByCode(string $code): ?PaymentPlugin
    {
        return $this->paymentPluginRepository->findByCode($code);
    }

    /**
     * 查询插件配置结构。
     *
     * @param string $code 插件编码
     * @return array{config_schema: array<int, mixed>} 配置结构
     * @throws PaymentException
     */
    public function getSchema(string $code): array
    {
        $plugin = $this->paymentPluginRepository->findByCode($code);
        if (!$plugin) {
            throw new PaymentException('支付插件不存在', 404, [
                'plugin_code' => $code,
            ]);
        }

        return [
            'config_schema' => is_array($plugin->config_schema) ? array_values($plugin->config_schema) : [],
        ];
    }

    /**
     * 查询插件进件 Schema。
     *
     * @param string $code 插件编码
     * @return array<string, mixed> 进件 Schema
     * @throws PaymentException
     */
    public function getOnboardingSchema(string $code): array
    {
        $plugin = $this->paymentPluginRepository->findByCode($code);
        if (!$plugin) {
            throw new PaymentException('支付插件不存在', 404, [
                'plugin_code' => $code,
            ]);
        }

        $info = is_array($plugin->onboarding_info) ? $plugin->onboarding_info : [];

        return [
            // 前端进件配置页分别消费主体范围、接口配置 schema 和申请表单 schema。
            'onboarding_types' => is_array($plugin->onboarding_types) ? array_values($plugin->onboarding_types) : [],
            'onboarding_info' => $info,
            'config_schema' => is_array($info['config_schema'] ?? null) ? array_values($info['config_schema']) : [],
            'form_schema' => is_array($info['form_schema'] ?? null) ? array_values($info['form_schema']) : [],
            'products' => is_array($info['products'] ?? null) ? array_values($info['products']) : [],
        ];
    }

    /**
     * 更新支付插件。
     *
     * @param string $code 插件编码
     * @param array $data 写入数据
     * @return PaymentPlugin|null 更新后的插件模型
     */
    public function update(string $code, array $data): ?PaymentPlugin
    {
        $payload = [];
        // 插件元信息由文件同步维护，后台这里只允许调整状态和备注，避免人工改动覆盖同步结果。
        if (array_key_exists('status', $data)) {
            $payload['status'] = (int) $data['status'];
        }

        if (array_key_exists('allow_merchant', $data)) {
            $payload['allow_merchant'] = (int) $data['allow_merchant'];
        }

        if (array_key_exists('remark', $data)) {
            $payload['remark'] = trim((string) $data['remark']);
        }

        if ($payload === []) {
            return $this->paymentPluginRepository->findByCode($code);
        }

        if (!$this->paymentPluginRepository->updateByKey($code, $payload)) {
            return null;
        }

        return $this->paymentPluginRepository->findByCode($code);
    }

    /**
     * 从插件目录刷新并同步支付插件定义。
     *
     * @return array{count: int, plugins: array<int, PaymentPlugin>} 同步结果
     */
    public function refreshFromClasses(): array
    {
        return $this->paymentPluginSyncService->refreshFromClasses();
    }

    /**
     * 为插件模型追加类型展示文案。
     *
     * @param PaymentPlugin $plugin 插件模型
     * @return void
     */
    private function appendTypeText(PaymentPlugin $plugin): void
    {
        $plugin->plugin_type_text = PaymentPluginTypeConstant::label((int) $plugin->plugin_type);
    }
}
