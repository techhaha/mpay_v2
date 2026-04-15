<?php

namespace app\service\payment\config;

use app\common\base\BaseService;
use app\exception\PaymentException;
use app\model\payment\PaymentPlugin;
use app\repository\payment\config\PaymentPluginRepository;

/**
 * 支付插件管理服务。
 *
 * 负责插件目录同步、插件列表查询，以及 JSON 字段写入前的归一化。
 */
class PaymentPluginService extends BaseService
{
    /**
     * 构造函数，注入支付插件仓库。
     */
    public function __construct(
        protected PaymentPluginRepository $paymentPluginRepository,
        protected PaymentPluginSyncService $paymentPluginSyncService
    ) {
    }

    /**
     * 分页查询支付插件。
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

        return $query
            ->orderBy('code')
            ->paginate(max(1, $pageSize), ['*'], 'page', max(1, $page));
    }

    /**
     * 查询启用中的支付插件选项。
     */
    public function enabledOptions(): array
    {
        return $this->paymentPluginRepository->enabledList(['code', 'name'])
            ->map(function (PaymentPlugin $plugin): array {
                return [
                    'label' => sprintf('%s（%s）', (string) $plugin->name, (string) $plugin->code),
                    'value' => (string) $plugin->code,
                    'code' => (string) $plugin->code,
                    'name' => (string) $plugin->name,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * 远程查询支付插件选择项。
     */
    public function searchOptions(array $filters = [], int $page = 1, int $pageSize = 20): array
    {
        $query = $this->paymentPluginRepository->query()
            ->where('status', 1)
            ->select(['code', 'name', 'pay_types'])
            ->orderBy('code');

        $ids = $filters['ids'] ?? [];
        if (is_array($ids) && $ids !== []) {
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
                $query->whereJsonContains('pay_types', $payTypeCode);
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
     */
    public function channelOptions(): array
    {
        return $this->paymentPluginRepository->enabledList([
            'code',
            'name',
            'pay_types',
        ])
            ->map(function (PaymentPlugin $plugin): array {
                return [
                    'label' => sprintf('%s（%s）', (string) $plugin->name, (string) $plugin->code),
                    'value' => (string) $plugin->code,
                    'code' => (string) $plugin->code,
                    'name' => (string) $plugin->name,
                    'pay_types' => is_array($plugin->pay_types) ? array_values($plugin->pay_types) : [],
                ];
            })
            ->values()
            ->all();
    }

    /**
     * 按插件编码查询插件。
     */
    public function findByCode(string $code): ?PaymentPlugin
    {
        return $this->paymentPluginRepository->findByCode($code);
    }

    /**
     * 查询插件配置结构。
     *
     * @return array<string, mixed>
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
     * 更新支付插件。
     */
    public function update(string $code, array $data): ?PaymentPlugin
    {
        $payload = [];
        if (array_key_exists('status', $data)) {
            $payload['status'] = (int) $data['status'];
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
     */
    public function refreshFromClasses(): array
    {
        return $this->paymentPluginSyncService->refreshFromClasses();
    }
}
