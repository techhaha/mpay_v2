<?php

namespace app\service\payment\config;

use app\common\base\BaseService;
use app\exception\PaymentException;
use app\model\payment\PaymentPluginConf;
use app\repository\payment\config\PaymentPluginConfRepository;
use app\repository\payment\config\PaymentPluginRepository;

/**
 * 支付插件配置服务。
 *
 * 负责插件公共配置的增删改查和下拉选项输出。
 */
class PaymentPluginConfService extends BaseService
{
    public function __construct(
        protected PaymentPluginConfRepository $paymentPluginConfRepository,
        protected PaymentPluginRepository $paymentPluginRepository
    ) {
    }

    /**
     * 分页查询插件配置。
     */
    public function paginate(array $filters = [], int $page = 1, int $pageSize = 10)
    {
        $query = $this->paymentPluginConfRepository->query()
            ->from('ma_payment_plugin_conf as c')
            ->leftJoin('ma_payment_plugin as p', 'c.plugin_code', '=', 'p.code')
            ->select([
                'c.id',
                'c.plugin_code',
                'c.config',
                'c.settlement_cycle_type',
                'c.settlement_cutoff_time',
                'c.remark',
                'c.created_at',
                'c.updated_at',
            ])
            ->selectRaw("COALESCE(NULLIF(p.name, ''), c.plugin_code) AS plugin_name");

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->where(function ($builder) use ($keyword) {
                $builder->where('c.plugin_code', 'like', '%' . $keyword . '%')
                    ->orWhere('c.remark', 'like', '%' . $keyword . '%')
                    ->orWhere('p.name', 'like', '%' . $keyword . '%');
            });
        }

        $pluginCode = trim((string) ($filters['plugin_code'] ?? ''));
        if ($pluginCode !== '') {
            $query->where('c.plugin_code', $pluginCode);
        }

        return $query
            ->orderByDesc('c.id')
            ->paginate(max(1, $pageSize), ['*'], 'page', max(1, $page));
    }

    /**
     * 按 ID 查询插件配置。
     */
    public function findById(int $id): ?PaymentPluginConf
    {
        return $this->paymentPluginConfRepository->find($id);
    }

    /**
     * 新增插件配置。
     */
    public function create(array $data): PaymentPluginConf
    {
        $payload = $this->normalizePayload($data);
        $this->assertPluginExists((string) $payload['plugin_code']);

        return $this->paymentPluginConfRepository->create($payload);
    }

    /**
     * 修改插件配置。
     */
    public function update(int $id, array $data): ?PaymentPluginConf
    {
        $payload = $this->normalizePayload($data);
        $this->assertPluginExists((string) $payload['plugin_code']);

        if (!$this->paymentPluginConfRepository->updateById($id, $payload)) {
            return null;
        }

        return $this->paymentPluginConfRepository->find($id);
    }

    /**
     * 删除插件配置。
     */
    public function delete(int $id): bool
    {
        return $this->paymentPluginConfRepository->deleteById($id);
    }

    /**
     * 查询插件配置下拉选项。
     */
    public function options(?string $pluginCode = null): array
    {
        $pluginCode = trim((string) $pluginCode);

        $query = $this->paymentPluginConfRepository->query()
            ->from('ma_payment_plugin_conf as c')
            ->leftJoin('ma_payment_plugin as p', 'c.plugin_code', '=', 'p.code')
            ->select([
                'c.id',
                'c.plugin_code',
            ])
            ->selectRaw("COALESCE(NULLIF(p.name, ''), c.plugin_code) AS plugin_name")
            ->orderByDesc('c.id');

        if ($pluginCode !== '') {
            $query->where('c.plugin_code', $pluginCode);
        }

        return $query->get()->map(function ($item): array {
            $pluginName = trim((string) ($item->plugin_name ?? ''));
            $pluginCode = trim((string) ($item->plugin_code ?? ''));
            $label = $pluginName !== '' ? $pluginName : $pluginCode;

            return [
                'label' => sprintf('%s（%d）', $label, (int) $item->id),
                'value' => (int) $item->id,
                'plugin_code' => $pluginCode,
                'plugin_name' => $pluginName !== '' ? $pluginName : $pluginCode,
            ];
        })->values()->all();
    }

    /**
     * 远程查询插件配置选择项。
     */
    public function searchOptions(array $filters = [], int $page = 1, int $pageSize = 20): array
    {
        $query = $this->paymentPluginConfRepository->query()
            ->from('ma_payment_plugin_conf as c')
            ->leftJoin('ma_payment_plugin as p', 'c.plugin_code', '=', 'p.code')
            ->select([
                'c.id',
                'c.plugin_code',
            ])
            ->selectRaw("COALESCE(NULLIF(p.name, ''), c.plugin_code) AS plugin_name")
            ->orderByDesc('c.id');

        $ids = $filters['ids'] ?? [];
        if (is_array($ids) && $ids !== []) {
            $query->whereIn('c.id', array_map('intval', $ids));
        } else {
            $pluginCode = trim((string) ($filters['plugin_code'] ?? ''));
            if ($pluginCode !== '') {
                $query->where('c.plugin_code', $pluginCode);
            }

            $keyword = trim((string) ($filters['keyword'] ?? ''));
            if ($keyword !== '') {
                $query->where(function ($builder) use ($keyword) {
                    $builder->where('c.plugin_code', 'like', '%' . $keyword . '%')
                        ->orWhere('p.name', 'like', '%' . $keyword . '%')
                        ->orWhere('c.remark', 'like', '%' . $keyword . '%');

                    if (ctype_digit($keyword)) {
                        $builder->orWhere('c.id', (int) $keyword);
                    }
                });
            }
        }

        $paginator = $query->paginate(max(1, $pageSize), ['*'], 'page', max(1, $page));

        return [
            'list' => collect($paginator->items())->map(function ($item): array {
                $pluginName = trim((string) ($item->plugin_name ?? ''));
                $pluginCode = trim((string) ($item->plugin_code ?? ''));
                $label = $pluginName !== '' ? $pluginName : $pluginCode;

                return [
                    'label' => sprintf('%s（%d）', $label, (int) $item->id),
                    'value' => (int) $item->id,
                    'plugin_code' => $pluginCode,
                    'plugin_name' => $pluginName !== '' ? $pluginName : $pluginCode,
                ];
            })->values()->all(),
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'size' => $paginator->perPage(),
        ];
    }

    /**
     * 标准化写入数据。
     */
    private function normalizePayload(array $data): array
    {
        return [
            'plugin_code' => trim((string) ($data['plugin_code'] ?? '')),
            'config' => is_array($data['config'] ?? null) ? $data['config'] : [],
            'settlement_cycle_type' => (int) ($data['settlement_cycle_type'] ?? 1),
            'settlement_cutoff_time' => trim((string) ($data['settlement_cutoff_time'] ?? '23:59:59')) ?: '23:59:59',
            'remark' => trim((string) ($data['remark'] ?? '')),
        ];
    }

    /**
     * 校验插件是否存在。
     */
    private function assertPluginExists(string $pluginCode): void
    {
        if ($pluginCode === '') {
            throw new PaymentException('插件编码不能为空', 40230);
        }

        if (!$this->paymentPluginRepository->findByCode($pluginCode)) {
            throw new PaymentException('支付插件不存在', 40231, [
                'plugin_code' => $pluginCode,
            ]);
        }
    }
}
