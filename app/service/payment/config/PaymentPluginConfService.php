<?php

namespace app\service\payment\config;

use app\common\base\BaseService;
use app\common\constant\EventConstant;
use app\common\constant\PaymentPluginTypeConstant;
use app\exception\PaymentException;
use app\model\payment\PaymentPluginConf;
use app\repository\payment\config\PaymentPluginConfRepository;
use app\repository\payment\config\PaymentPluginRepository;
use Webman\Event\Event;

/**
 * 支付插件配置服务。
 *
 * 负责支付插件公共配置的增删改查、下拉选项输出以及插件存在性校验。
 *
 * @property PaymentPluginConfRepository $paymentPluginConfRepository 支付插件配置仓库
 * @property PaymentPluginRepository $paymentPluginRepository 支付插件仓库
 */
class PaymentPluginConfService extends BaseService
{
    /**
     * 构造方法。
     *
     * @param PaymentPluginConfRepository $paymentPluginConfRepository 支付插件配置仓库
     * @param PaymentPluginRepository $paymentPluginRepository 支付插件仓库
     * @return void
     */
    public function __construct(
        protected PaymentPluginConfRepository $paymentPluginConfRepository,
        protected PaymentPluginRepository $paymentPluginRepository
    ) {
    }

    /**
     * 分页查询插件配置。
     *
     * @param array $filters 筛选条件
     * @param int $page 页码
     * @param int $pageSize 每页条数
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator 分页结果
     */
    public function paginate(array $filters = [], int $page = 1, int $pageSize = 10)
    {
        $query = $this->paymentPluginConfRepository->query()
            ->from('ma_payment_plugin_conf as c')
            ->leftJoin('ma_payment_plugin as p', 'c.plugin_code', '=', 'p.code')
            ->select([
                'c.id',
                'c.merchant_id',
                'c.plugin_code',
                'c.config',
                'c.settlement_cycle_type',
                'c.settlement_cutoff_time',
                'c.remark',
                'c.created_at',
                'c.updated_at',
            ])
            ->selectRaw("COALESCE(NULLIF(p.name, ''), c.plugin_code) AS plugin_name")
            ->selectRaw('COALESCE(p.plugin_type, 1) AS plugin_type');

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            // 列表页关键词同时覆盖插件编码、备注和插件名称，方便后台快速定位配置记录。
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

        $merchantId = trim((string) ($filters['merchant_id'] ?? ''));
        if ($merchantId !== '') {
            $query->where('c.merchant_id', (int) $merchantId);
        }

        $pluginType = (int) ($filters['plugin_type'] ?? 0);
        if (PaymentPluginTypeConstant::isValid($pluginType)) {
            $query->where('p.plugin_type', $pluginType);
        }

        $paginator = $query
            ->orderByDesc('c.id')
            ->paginate(max(1, $pageSize), ['*'], 'page', max(1, $page));

        $paginator->getCollection()->transform(function ($row) {
            $row->is_writable = (int) $row->merchant_id === 0;
            $row->source_type = (int) $row->merchant_id === 0 ? 'platform' : 'merchant';
            $row->source_text = (int) $row->merchant_id === 0 ? '平台配置' : '商户自建';
            $row->plugin_type = (int) ($row->plugin_type ?? PaymentPluginTypeConstant::TYPE_DIRECT);
            $row->plugin_type_text = PaymentPluginTypeConstant::label((int) $row->plugin_type);
            $row->config_masked = $this->maskSensitiveData((array) ($row->config ?? []));
            $row->config = $row->config_masked;

            return $row;
        });

        return $paginator;
    }

    /**
     * 按 ID 查询插件配置。
     *
     * @param int $id 支付插件配置ID
     * @return PaymentPluginConf|null 插件配置模型
     */
    public function findById(int $id): ?PaymentPluginConf
    {
        $row = $this->paymentPluginConfRepository->find($id);
        if ($row) {
            $row->is_writable = (int) $row->merchant_id === 0;
            $row->source_type = (int) $row->merchant_id === 0 ? 'platform' : 'merchant';
            $row->source_text = (int) $row->merchant_id === 0 ? '平台配置' : '商户自建';
            $row->config_masked = $this->maskSensitiveData((array) ($row->config ?? []));
        }

        return $row;
    }

    /**
     * 新增插件配置。
     *
     * @param array $data 写入数据
     * @return PaymentPluginConf 新增后的插件配置模型
     * @throws PaymentException
     */
    public function create(array $data): PaymentPluginConf
    {
        $this->assertPlatformPayload($data);
        $payload = $this->normalizePayload($data);
        $this->assertPluginExists((string) $payload['plugin_code']);

        $config = $this->paymentPluginConfRepository->create($payload);
        $this->dispatchWatcherConfigChanged('create', $config);

        return $config;
    }

    /**
     * 修改插件配置。
     *
     * @param int $id 支付插件配置ID
     * @param array $data 写入数据
     * @return PaymentPluginConf|null 更新后的插件配置模型
     * @throws PaymentException
     */
    public function update(int $id, array $data): ?PaymentPluginConf
    {
        $current = $this->paymentPluginConfRepository->find($id);
        if (!$current) {
            return null;
        }

        $this->assertPlatformConfig($current);
        $this->assertPlatformPayload($data, false);
        $payload = $this->normalizePayload($data);
        $this->assertPluginExists((string) $payload['plugin_code']);

        if (!$this->paymentPluginConfRepository->updateById($id, $payload)) {
            return null;
        }

        $config = $this->paymentPluginConfRepository->find($id);
        if ($config) {
            $this->dispatchWatcherConfigChanged('update', $config);
        }

        return $config;
    }

    /**
     * 删除插件配置。
     *
     * @param int $id 支付插件配置ID
     * @return bool 是否删除成功
     */
    public function delete(int $id): bool
    {
        $config = $this->paymentPluginConfRepository->find($id);
        if (!$config) {
            return false;
        }

        $this->assertPlatformConfig($config);
        $deleted = $this->paymentPluginConfRepository->deleteById($id);
        if ($deleted && $config) {
            $this->dispatchWatcherConfigChanged('delete', $config);
        }

        return $deleted;
    }

    /**
     * 查询插件配置下拉选项。
     *
     * @param string|null $pluginCode 插件编码
     * @return array<int, array{label: string, value: int, plugin_code: string, plugin_name: string}> 配置选项
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
            ->where('c.merchant_id', 0)
            ->orderByDesc('c.id');

        if ($pluginCode !== '') {
            // 如果前端已经明确指定插件编码，就只回这个插件下的配置选项。
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
     * 搜索插件配置选择项。
     *
     * @param array $filters 筛选条件
     * @param int $page 页码
     * @param int $pageSize 每页条数
     * @return array{list: array<int, array{label: string, value: int, plugin_code: string, plugin_name: string}>, total: int, page: int, size: int} 配置搜索结果
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
            ->where('c.merchant_id', 0)
            ->orderByDesc('c.id');

        $ids = $filters['ids'] ?? [];
        if (is_array($ids) && $ids !== []) {
            // 显式传 ID 时优先按配置主键回显，避免关键词过滤把已选项漏掉。
            $query->whereIn('c.id', array_map('intval', $ids));
        } else {
            $pluginCode = trim((string) ($filters['plugin_code'] ?? ''));
            if ($pluginCode !== '') {
                // 插件编码是配置项的一级过滤条件，先收窄到单个插件。
                $query->where('c.plugin_code', $pluginCode);
            }

            $keyword = trim((string) ($filters['keyword'] ?? ''));
            if ($keyword !== '') {
                // 数字关键词既可以按配置 ID 查，也可以按编码或备注查。
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
     * 标准化插件配置写入数据。
     *
     * @param array $data 写入数据
     * @return array<string, mixed> 标准化后的数据
     */
    private function normalizePayload(array $data): array
    {
        return [
            'plugin_code' => trim((string) ($data['plugin_code'] ?? '')),
            'merchant_id' => 0,
            // 配置内容统一按数组保存，外部传入非数组时直接回退为空数组。
            'config' => is_array($data['config'] ?? null) ? $data['config'] : [],
            // 默认结算周期按日配置，截止时间默认按当天 23:59:59 收口。
            'settlement_cycle_type' => (int) ($data['settlement_cycle_type'] ?? 1),
            'settlement_cutoff_time' => trim((string) ($data['settlement_cutoff_time'] ?? '23:59:59')) ?: '23:59:59',
            'remark' => trim((string) ($data['remark'] ?? '')),
        ];
    }

    /**
     * 校验后台插件配置写入只能操作平台配置。
     *
     * @param array $data 写入数据
     * @param bool $requireFields 是否要求完整字段
     * @return void
     * @throws PaymentException
     */
    private function assertPlatformPayload(array $data, bool $requireFields = true): void
    {
        if (($requireFields || array_key_exists('merchant_id', $data)) && (int) ($data['merchant_id'] ?? 0) !== 0) {
            throw new PaymentException('管理后台只能维护平台插件配置，商户插件配置请到商户后台处理', 40232);
        }
    }

    /**
     * 校验插件配置是否允许被管理后台写操作修改。
     *
     * @param PaymentPluginConf $config 插件配置
     * @return void
     * @throws PaymentException
     */
    private function assertPlatformConfig(PaymentPluginConf $config): void
    {
        if ((int) $config->merchant_id !== 0) {
            throw new PaymentException('商户插件配置只允许查看，不能在管理后台修改', 40233, [
                'config_id' => (int) $config->id,
                'merchant_id' => (int) $config->merchant_id,
            ]);
        }
    }

    /**
     * 校验插件是否存在。
     *
     * @param string $pluginCode 插件编码
     * @return void
     * @throws PaymentException
     */
    private function assertPluginExists(string $pluginCode): void
    {
        if ($pluginCode === '') {
            throw new PaymentException('插件编码不能为空', 40230);
        }

        // 插件配置必须挂到已存在的插件定义上，避免配置和实际实现脱节。
        if (!$this->paymentPluginRepository->findByCode($pluginCode)) {
            throw new PaymentException('支付插件不存在', 40231, [
                'plugin_code' => $pluginCode,
            ]);
        }
    }

    /**
     * 发送网页流水监听配置刷新事件。
     *
     * @param string $action 操作
     * @param PaymentPluginConf $config 插件配置
     * @return void
     */
    private function dispatchWatcherConfigChanged(string $action, PaymentPluginConf $config): void
    {
        Event::dispatch(EventConstant::PAYMENT_RECEIPT_WATCHER_CONFIG_CHANGED, [
            'source' => 'payment_plugin_conf',
            'action' => $action,
            'api_config_id' => (int) $config->id,
            'plugin_code' => (string) $config->plugin_code,
        ]);
    }
}
