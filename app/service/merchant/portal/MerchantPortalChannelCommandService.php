<?php

namespace app\service\merchant\portal;

use app\common\base\BaseService;
use app\common\constant\CommonConstant;
use app\common\constant\RouteConstant;
use app\exception\PaymentException;
use app\model\payment\PaymentChannel;
use app\model\payment\PaymentPlugin;
use app\model\payment\PaymentPluginConf;
use app\repository\payment\config\PaymentChannelRepository;
use app\repository\payment\config\PaymentPluginConfRepository;
use app\repository\payment\config\PaymentPluginRepository;
use app\repository\payment\config\PaymentTypeRepository;

/**
 * 商户门户通道配置命令服务。
 *
 * 负责商户端插件配置、通道配置的新增修改删除，并集中校验商户归属与插件授权。
 */
class MerchantPortalChannelCommandService extends BaseService
{
    public function __construct(
        protected MerchantPortalSupportService $supportService,
        protected PaymentPluginRepository $paymentPluginRepository,
        protected PaymentPluginConfRepository $paymentPluginConfRepository,
        protected PaymentChannelRepository $paymentChannelRepository,
        protected PaymentTypeRepository $paymentTypeRepository
    ) {
    }

    /**
     * 商户端允许使用的插件选项。
     *
     * @return array 插件选项和支付方式
     */
    public function createMeta(): array
    {
        $plugins = $this->paymentPluginRepository->merchantEnabledList([
            'code',
            'name',
            'config_schema',
            'pay_types',
        ])->map(function (PaymentPlugin $plugin): array {
            return $this->pluginOption($plugin);
        })->values()->all();

        return [
            'plugins' => $plugins,
            'pay_types' => $this->supportService->enabledPayTypeOptions(),
        ];
    }

    /**
     * 查询商户插件配置列表。
     *
     * @param array $filters 筛选条件
     * @param int $merchantId 商户ID
     * @param int $page 页码
     * @param int $pageSize 每页条数
     * @return array 列表数据
     */
    public function pluginConfigs(array $filters, int $merchantId, int $page, int $pageSize): array
    {
        $merchant = $this->supportService->merchantSummary($merchantId);

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
            ->where('c.merchant_id', $merchantId);

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->where(function ($builder) use ($keyword) {
                $builder->where('c.plugin_code', 'like', '%' . $keyword . '%')
                    ->orWhere('p.name', 'like', '%' . $keyword . '%')
                    ->orWhere('c.remark', 'like', '%' . $keyword . '%');
            });
        }

        $pluginCode = trim((string) ($filters['plugin_code'] ?? ''));
        if ($pluginCode !== '') {
            $query->where('c.plugin_code', $pluginCode);
        }

        $paginator = $query
            ->orderByDesc('c.id')
            ->paginate(max(1, $pageSize), ['*'], 'page', max(1, $page));

        $paginator->getCollection()->transform(function ($row) {
            $row->config_masked = $this->maskSensitiveData((array) ($row->config ?? []));
            $row->config = $row->config_masked;
            $row->settlement_cycle_type_text = $this->textFromMap((int) $row->settlement_cycle_type, [
                0 => 'D0',
                1 => 'D1',
                2 => 'D7',
                3 => 'T1',
                4 => 'OTHER',
            ]);
            $row->created_at_text = $this->formatDateTime($row->created_at ?? null);
            $row->updated_at_text = $this->formatDateTime($row->updated_at ?? null);
            $row->is_writable = true;
            $row->source_type = 'merchant';
            $row->source_text = '自建配置';

            return $row;
        });

        return [
            'merchant' => $merchant,
            'plugins' => $this->createMeta()['plugins'],
            'list' => $paginator->items(),
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'size' => $paginator->perPage(),
        ];
    }

    /**
     * 查询商户插件配置详情。
     *
     * @param int $merchantId 商户ID
     * @param int $id 配置ID
     * @return PaymentPluginConf|null 配置
     */
    public function pluginConfigDetail(int $merchantId, int $id): ?PaymentPluginConf
    {
        $model = $this->paymentPluginConfRepository->findByMerchantAndId($merchantId, $id);
        if ($model) {
            $model->config_masked = $this->maskSensitiveData((array) ($model->config ?? []));
        }

        return $model;
    }

    /**
     * 新增商户插件配置。
     *
     * @param int $merchantId 商户ID
     * @param array $data 写入数据
     * @return PaymentPluginConf 配置
     */
    public function createPluginConfig(int $merchantId, array $data): PaymentPluginConf
    {
        $payload = $this->normalizePluginConfigPayload($merchantId, $data);
        $this->assertMerchantPluginAllowed((string) $payload['plugin_code']);

        return $this->paymentPluginConfRepository->create($payload);
    }

    /**
     * 修改商户插件配置。
     *
     * @param int $merchantId 商户ID
     * @param int $id 配置ID
     * @param array $data 写入数据
     * @return PaymentPluginConf|null 配置
     */
    public function updatePluginConfig(int $merchantId, int $id, array $data): ?PaymentPluginConf
    {
        $model = $this->paymentPluginConfRepository->findByMerchantAndId($merchantId, $id);
        if (!$model) {
            return null;
        }

        $payload = $this->normalizePluginConfigPayload($merchantId, $data);
        $this->assertMerchantPluginAllowed((string) $payload['plugin_code']);

        $model->fill($payload);
        $model->save();

        return $model->refresh();
    }

    /**
     * 删除商户插件配置。
     *
     * @param int $merchantId 商户ID
     * @param int $id 配置ID
     * @return bool 是否删除
     */
    public function deletePluginConfig(int $merchantId, int $id): bool
    {
        $model = $this->paymentPluginConfRepository->findByMerchantAndId($merchantId, $id);
        if (!$model) {
            return false;
        }

        if ($this->paymentChannelRepository->existsBy([
            'merchant_id' => $merchantId,
            'api_config_id' => $id,
        ])) {
            throw new PaymentException('该配置已被通道使用，不能删除', 40241);
        }

        return (bool) $model->delete();
    }

    /**
     * 商户插件配置下拉选项。
     *
     * @param int $merchantId 商户ID
     * @param string $pluginCode 插件编码
     * @return array 配置选项
     */
    public function pluginConfigOptions(int $merchantId, string $pluginCode = ''): array
    {
        $query = $this->paymentPluginConfRepository->query()
            ->from('ma_payment_plugin_conf as c')
            ->leftJoin('ma_payment_plugin as p', 'c.plugin_code', '=', 'p.code')
            ->select(['c.id', 'c.plugin_code'])
            ->selectRaw("COALESCE(NULLIF(p.name, ''), c.plugin_code) AS plugin_name")
            ->where('c.merchant_id', $merchantId)
            ->orderByDesc('c.id');

        $pluginCode = trim($pluginCode);
        if ($pluginCode !== '') {
            $query->where('c.plugin_code', $pluginCode);
        }

        return $query->get()->map(function ($row): array {
            return [
                'label' => sprintf('%s（%d）', (string) $row->plugin_name, (int) $row->id),
                'value' => (int) $row->id,
                'plugin_code' => (string) $row->plugin_code,
                'plugin_name' => (string) $row->plugin_name,
            ];
        })->values()->all();
    }

    /**
     * 新增商户通道。
     *
     * @param int $merchantId 商户ID
     * @param array $data 写入数据
     * @return PaymentChannel 通道
     */
    public function createChannel(int $merchantId, array $data): PaymentChannel
    {
        $payload = $this->normalizeChannelPayload($merchantId, $data);
        $this->assertChannelWritable($merchantId, $payload);
        $this->assertChannelNameUnique($merchantId, (string) $payload['name']);

        return $this->paymentChannelRepository->create($payload);
    }

    /**
     * 修改商户通道。
     *
     * @param int $merchantId 商户ID
     * @param int $id 通道ID
     * @param array $data 写入数据
     * @return PaymentChannel|null 通道
     */
    public function updateChannel(int $merchantId, int $id, array $data): ?PaymentChannel
    {
        $model = $this->paymentChannelRepository->findByMerchantAndId($merchantId, $id);
        if (!$model) {
            return null;
        }
        $this->assertSelfChannel($model);

        $payload = $this->normalizeChannelPayload($merchantId, $data);
        $this->assertChannelWritable($merchantId, $payload);
        $this->assertChannelNameUnique($merchantId, (string) $payload['name'], $id);

        $model->fill($payload);
        $model->save();

        return $model->refresh();
    }

    /**
     * 删除商户通道。
     *
     * @param int $merchantId 商户ID
     * @param int $id 通道ID
     * @return bool 是否删除
     */
    public function deleteChannel(int $merchantId, int $id): bool
    {
        $model = $this->paymentChannelRepository->findByMerchantAndId($merchantId, $id);
        if (!$model) {
            return false;
        }
        $this->assertSelfChannel($model);

        return (bool) $model->delete();
    }

    /**
     * 根据插件编码查询商户端可用插件结构。
     *
     * @param string $pluginCode 插件编码
     * @return array 配置结构
     */
    public function pluginSchema(string $pluginCode): array
    {
        $plugin = $this->assertMerchantPluginAllowed($pluginCode);

        return [
            'config_schema' => is_array($plugin->config_schema) ? array_values($plugin->config_schema) : [],
        ];
    }

    private function normalizePluginConfigPayload(int $merchantId, array $data): array
    {
        return [
            'merchant_id' => $merchantId,
            'plugin_code' => trim((string) ($data['plugin_code'] ?? '')),
            'config' => is_array($data['config'] ?? null) ? $data['config'] : [],
            'settlement_cycle_type' => (int) ($data['settlement_cycle_type'] ?? 1),
            'settlement_cutoff_time' => trim((string) ($data['settlement_cutoff_time'] ?? '23:59:59')) ?: '23:59:59',
            'remark' => trim((string) ($data['remark'] ?? '')),
        ];
    }

    private function normalizeChannelPayload(int $merchantId, array $data): array
    {
        return [
            'merchant_id' => $merchantId,
            'name' => trim((string) ($data['name'] ?? '')),
            'split_rate_bp' => 10000,
            'cost_rate_bp' => 0,
            'channel_mode' => RouteConstant::CHANNEL_MODE_SELF,
            'pay_type_id' => (int) ($data['pay_type_id'] ?? 0),
            'plugin_code' => trim((string) ($data['plugin_code'] ?? '')),
            'api_config_id' => (int) ($data['api_config_id'] ?? 0),
            'daily_limit_amount' => max(0, (int) ($data['daily_limit_amount'] ?? 0)),
            'daily_limit_count' => max(0, (int) ($data['daily_limit_count'] ?? 0)),
            'min_amount' => max(0, (int) ($data['min_amount'] ?? 0)),
            'max_amount' => max(0, (int) ($data['max_amount'] ?? 0)),
            'remark' => trim((string) ($data['remark'] ?? '')),
            'status' => (int) ($data['status'] ?? CommonConstant::STATUS_ENABLED),
            'sort_no' => max(0, (int) ($data['sort_no'] ?? 0)),
        ];
    }

    private function assertChannelWritable(int $merchantId, array $payload): void
    {
        if ((string) $payload['name'] === '') {
            throw new PaymentException('通道名称不能为空', 40242);
        }

        $plugin = $this->assertMerchantPluginAllowed((string) $payload['plugin_code']);
        $config = $this->paymentPluginConfRepository->findByMerchantAndId($merchantId, (int) $payload['api_config_id']);
        if (!$config || (string) $config->plugin_code !== (string) $payload['plugin_code']) {
            throw new PaymentException('插件配置不存在或不属于当前插件', 40243);
        }

        $payType = $this->paymentTypeRepository->find((int) $payload['pay_type_id']);
        if (!$payType) {
            throw new PaymentException('支付方式不存在', 40244);
        }

        $payTypes = is_array($plugin->pay_types) ? $plugin->pay_types : [];
        $payTypeCodes = array_values(array_filter(array_map(static fn ($item) => trim((string) $item), $payTypes)));
        if (!in_array((string) $payType->code, $payTypeCodes, true)) {
            throw new PaymentException('支付插件不支持当前支付方式', 40245);
        }

        if ((int) $payload['max_amount'] > 0 && (int) $payload['min_amount'] > (int) $payload['max_amount']) {
            throw new PaymentException('单笔最小金额不能大于最大金额', 40246);
        }
    }

    private function assertChannelNameUnique(int $merchantId, string $name, int $ignoreId = 0): void
    {
        if ($this->paymentChannelRepository->existsByMerchantName($merchantId, $name, $ignoreId)) {
            throw new PaymentException('通道名称已存在', 40247);
        }

        if ($this->paymentChannelRepository->existsByName($name, $ignoreId)) {
            throw new PaymentException('通道名称已被占用，请换一个名称', 40248);
        }
    }

    private function assertMerchantPluginAllowed(string $pluginCode): PaymentPlugin
    {
        $plugin = $this->paymentPluginRepository->findMerchantAllowed($pluginCode);
        if (!$plugin) {
            throw new PaymentException('该支付插件未开放给商户端使用', 40240, [
                'plugin_code' => $pluginCode,
            ]);
        }

        return $plugin;
    }

    private function assertSelfChannel(PaymentChannel $channel): void
    {
        if ((int) $channel->channel_mode !== RouteConstant::CHANNEL_MODE_SELF) {
            throw new PaymentException('系统分配通道不允许商户端修改', 40249);
        }
    }

    private function pluginOption(PaymentPlugin $plugin): array
    {
        return [
            'label' => sprintf('%s（%s）', (string) $plugin->name, (string) $plugin->code),
            'value' => (string) $plugin->code,
            'code' => (string) $plugin->code,
            'name' => (string) $plugin->name,
            'pay_types' => is_array($plugin->pay_types) ? array_values($plugin->pay_types) : [],
            'config_schema' => is_array($plugin->config_schema) ? array_values($plugin->config_schema) : [],
        ];
    }
}
