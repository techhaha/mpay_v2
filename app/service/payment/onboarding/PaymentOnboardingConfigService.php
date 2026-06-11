<?php

namespace app\service\payment\onboarding;

use app\common\base\BaseService;
use app\common\constant\OnboardingConstant;
use app\exception\PaymentException;
use app\model\payment\PaymentPlugin;
use app\model\payment\PaymentPluginOnboardingConf;
use app\repository\payment\config\MerchantChannelOnboardingRepository;
use app\repository\payment\config\PaymentPluginOnboardingConfRepository;
use app\repository\payment\config\PaymentPluginRepository;

/**
 * 支付插件进件配置服务。
 */
class PaymentOnboardingConfigService extends BaseService
{
    public function __construct(
        protected PaymentPluginOnboardingConfRepository $configRepository,
        protected PaymentPluginRepository $paymentPluginRepository,
        protected MerchantChannelOnboardingRepository $onboardingRepository,
        protected OnboardingPluginManager $pluginManager
    ) {
    }

    /**
     * 分页查询插件进件配置。
     *
     * @param array<string, mixed> $filters 查询条件
     * @param int $page 页码
     * @param int $pageSize 每页条数
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function paginate(array $filters = [], int $page = 1, int $pageSize = 10)
    {
        $query = $this->configRepository->query()
            ->from('ma_payment_plugin_onboarding_conf as c')
            ->leftJoin('ma_payment_plugin as p', 'c.plugin_code', '=', 'p.code')
            ->select([
                'c.id',
                'c.plugin_code',
                'c.name',
                'c.config',
                'c.subject_types',
                'c.apply_products',
                'c.rate_config',
                'c.merchant_visible',
                'c.status',
                'c.sort_no',
                'c.description',
                'c.remark',
                'c.created_at',
                'c.updated_at',
            ])
            ->selectRaw("COALESCE(NULLIF(p.name, ''), c.plugin_code) AS plugin_name")
            ->selectRaw('COALESCE(p.plugin_type, 1) AS plugin_type')
            ->selectRaw('p.onboarding_info AS plugin_onboarding_info');

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->where(function ($builder) use ($keyword) {
                $builder->where('c.name', 'like', '%' . $keyword . '%')
                    ->orWhere('c.plugin_code', 'like', '%' . $keyword . '%')
                    ->orWhere('p.name', 'like', '%' . $keyword . '%')
                    ->orWhere('c.remark', 'like', '%' . $keyword . '%');
            });
        }

        $pluginCode = trim((string) ($filters['plugin_code'] ?? ''));
        if ($pluginCode !== '') {
            $query->where('c.plugin_code', $pluginCode);
        }

        foreach (['status', 'merchant_visible'] as $field) {
            if (array_key_exists($field, $filters) && $filters[$field] !== '') {
                $query->where('c.' . $field, (int) $filters[$field]);
            }
        }

        $paginator = $query
            ->orderBy('c.sort_no')
            ->orderByDesc('c.id')
            ->paginate(max(1, $pageSize), ['*'], 'page', max(1, $page));

        $paginator->getCollection()->transform(fn ($row) => $this->decorateRow($row));

        return $paginator;
    }

    /**
     * 查询进件配置详情。
     *
     * @param int $id 配置ID
     * @return PaymentPluginOnboardingConf|null
     */
    public function findById(int $id): ?PaymentPluginOnboardingConf
    {
        $row = $this->configRepository->find($id);
        if ($row) {
            $this->appendConfigTexts($row);
            // 详情保留原始 config 给编辑表单回填，同时提供脱敏字段给页面展示。
            $row->config_masked = $this->maskSensitiveData((array) ($row->config ?? []));
        }

        return $row;
    }

    /**
     * 创建插件进件配置。
     *
     * @param array<string, mixed> $data 请求数据
     * @return PaymentPluginOnboardingConf
     * @throws PaymentException
     */
    public function create(array $data): PaymentPluginOnboardingConf
    {
        $payload = $this->normalizePayload($data);
        $plugin = $this->assertPluginSupportsOnboarding((string) $payload['plugin_code']);
        $payload['config'] = $this->pruneConfigBySchema((array) $payload['config'], $plugin);
        $this->assertSubjectTypes($payload, $plugin);
        $this->assertProducts($payload, $plugin);
        $this->assertRequiredConfigComplete($payload, $plugin);

        return $this->configRepository->create($payload);
    }

    /**
     * 更新插件进件配置。
     *
     * @param int $id 配置ID
     * @param array<string, mixed> $data 请求数据
     * @return PaymentPluginOnboardingConf|null
     * @throws PaymentException
     */
    public function update(int $id, array $data): ?PaymentPluginOnboardingConf
    {
        $current = $this->configRepository->find($id);
        if (!$current) {
            return null;
        }

        $payload = $this->normalizePayload($data);
        $plugin = $this->assertPluginSupportsOnboarding((string) $payload['plugin_code']);
        $payload['config'] = $this->pruneConfigBySchema((array) $payload['config'], $plugin);
        $this->assertSubjectTypes($payload, $plugin);
        $this->assertProducts($payload, $plugin);
        $this->assertRequiredConfigComplete($payload, $plugin);

        if (!$this->configRepository->updateById($id, $payload)) {
            return null;
        }

        return $this->configRepository->find($id);
    }

    /**
     * 删除插件进件配置。
     *
     * 已有申请引用的配置不允许删除，避免申请详情无法还原配置上下文。
     *
     * @param int $id 配置ID
     * @return bool
     * @throws PaymentException
     */
    public function delete(int $id): bool
    {
        if (!$this->configRepository->find($id)) {
            return false;
        }

        if ($this->onboardingRepository->countBy(['onboarding_config_id' => $id]) > 0) {
            throw new PaymentException('已有进件申请使用该配置，不能删除', 40280);
        }

        return $this->configRepository->deleteById($id);
    }

    /**
     * 商户端可见进件渠道。
     *
     * @return array<int, array<string, mixed>>
     */
    public function merchantChannels(): array
    {
        return $this->configRepository->query()
            ->from('ma_payment_plugin_onboarding_conf as c')
            ->join('ma_payment_plugin as p', 'c.plugin_code', '=', 'p.code')
            ->where('c.status', 1)
            ->where('c.merchant_visible', 1)
            ->where('p.status', 1)
            ->whereNotNull('p.onboarding_types')
            ->select([
                'c.id',
                'c.plugin_code',
                'c.name',
                'c.subject_types',
                'c.apply_products',
                'c.rate_config',
                'c.description',
                'c.sort_no',
                'p.name as plugin_name',
                'p.onboarding_info',
            ])
            ->orderBy('c.sort_no')
            ->orderByDesc('c.id')
            ->get()
            ->map(function ($row): array {
                // 表单 schema 来自插件声明，配置只负责裁剪商户可申请的主体和产品范围。
                $info = $this->decodeJson($row->onboarding_info ?? []);
                $products = $this->normalizeProducts((array) ($row->apply_products ?? []), $info);
                return [
                    'id' => (int) $row->id,
                    'plugin_code' => (string) $row->plugin_code,
                    'plugin_name' => (string) ($row->plugin_name ?: $row->plugin_code),
                    'name' => (string) $row->name,
                    'subject_types' => array_values((array) ($row->subject_types ?? [])),
                    'subject_type_options' => $this->subjectTypeOptions((array) ($row->subject_types ?? [])),
                    'apply_products' => array_values((array) ($row->apply_products ?? [])),
                    'product_options' => $products,
                    'rate_config' => is_array($row->rate_config) ? $row->rate_config : [],
                    'description' => (string) ($row->description ?? ''),
                    'form_schema' => is_array($info['form_schema'] ?? null) ? array_values($info['form_schema']) : [],
                    'ocr_enabled' => false,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * 获取商户端可见的单个进件渠道。
     *
     * @param int $id 配置ID
     * @return array<string, mixed>|null
     */
    public function getMerchantChannel(int $id): ?array
    {
        foreach ($this->merchantChannels() as $channel) {
            if ((int) $channel['id'] === $id) {
                return $channel;
            }
        }

        return null;
    }

    /**
     * 查询进件配置对应插件的卡 BIN 信息。
     *
     * @param int $configId 进件配置 ID
     * @param string $cardNo 银行卡号
     * @param bool $merchantVisibleOnly 是否只允许商户可见配置
     * @return array<string, mixed>
     */
    public function cardBin(int $configId, string $cardNo, bool $merchantVisibleOnly = false): array
    {
        $config = $merchantVisibleOnly
            ? $this->configRepository->findMerchantVisible($configId)
            : $this->configRepository->findEnabled($configId);
        if (!$config) {
            throw new PaymentException('进件配置不可用', 40305);
        }

        $plugin = $this->pluginManager->createByConfig($config);
        if (!method_exists($plugin, 'cardBin')) {
            throw new PaymentException('当前进件插件不支持卡 BIN 查询', 40310);
        }

        return $plugin->cardBin(['card_no' => $cardNo]);
    }

    /**
     * 归一化进件配置入库字段。
     *
     * @param array<string, mixed> $data 请求数据
     * @return array<string, mixed>
     */
    private function normalizePayload(array $data): array
    {
        return [
            'plugin_code' => trim((string) ($data['plugin_code'] ?? '')),
            'name' => trim((string) ($data['name'] ?? '')),
            'config' => is_array($data['config'] ?? null) ? $data['config'] : [],
            'subject_types' => $this->stringList($data['subject_types'] ?? []),
            'apply_products' => $this->stringList($data['apply_products'] ?? []),
            'rate_config' => is_array($data['rate_config'] ?? null) ? $data['rate_config'] : [],
            'merchant_visible' => (int) ($data['merchant_visible'] ?? 1),
            'status' => (int) ($data['status'] ?? 1),
            'sort_no' => (int) ($data['sort_no'] ?? 0),
            'description' => trim((string) ($data['description'] ?? '')),
            'remark' => trim((string) ($data['remark'] ?? '')),
        ];
    }

    /**
     * 确认插件存在并声明了进件能力。
     *
     * @throws PaymentException
     */
    private function assertPluginSupportsOnboarding(string $pluginCode): PaymentPlugin
    {
        if ($pluginCode === '') {
            throw new PaymentException('插件编码不能为空', 40281);
        }

        $plugin = $this->paymentPluginRepository->findByCode($pluginCode);
        if (!$plugin) {
            throw new PaymentException('支付插件不存在', 40282, ['plugin_code' => $pluginCode]);
        }

        if (!is_array($plugin->onboarding_types) || $plugin->onboarding_types === []) {
            throw new PaymentException('该插件未声明进件能力', 40283, ['plugin_code' => $pluginCode]);
        }

        return $plugin;
    }

    /**
     * 校验配置选择的主体范围未超出插件声明。
     *
     * @param array<string, mixed> $payload 归一化后的配置
     * @throws PaymentException
     */
    private function assertSubjectTypes(array $payload, PaymentPlugin $plugin): void
    {
        if ((array) $payload['subject_types'] === []) {
            throw new PaymentException('请选择进件主体范围', 40286);
        }

        $allowed = array_values((array) $plugin->onboarding_types);
        foreach ((array) $payload['subject_types'] as $type) {
            if (!in_array($type, $allowed, true)) {
                throw new PaymentException('进件主体类型不在插件声明范围内', 40284, [
                    'subject_type' => $type,
                ]);
            }
        }
    }

    /**
     * 校验配置选择的产品范围未超出插件声明。
     *
     * @param array<string, mixed> $payload 归一化后的配置
     * @throws PaymentException
     */
    private function assertProducts(array $payload, PaymentPlugin $plugin): void
    {
        if ((array) $payload['apply_products'] === []) {
            throw new PaymentException('请选择可申请产品', 40287);
        }

        $info = is_array($plugin->onboarding_info) ? $plugin->onboarding_info : [];
        $productCodes = array_map(static fn ($item): string => (string) ($item['value'] ?? $item['code'] ?? ''), (array) ($info['products'] ?? []));
        $productCodes = array_values(array_filter($productCodes));
        if ($productCodes === []) {
            return;
        }

        foreach ((array) $payload['apply_products'] as $product) {
            if (!in_array($product, $productCodes, true)) {
                throw new PaymentException('申请产品不在插件声明范围内', 40285, [
                    'product' => $product,
                ]);
            }
        }
    }

    /**
     * 校验插件进件接口配置中的 required 字段。
     *
     * @param array<string, mixed> $payload 归一化后的配置
     * @throws PaymentException
     */
    private function assertRequiredConfigComplete(array $payload, PaymentPlugin $plugin): void
    {
        $info = is_array($plugin->onboarding_info) ? $plugin->onboarding_info : [];
        $schema = is_array($info['config_schema'] ?? null) ? $info['config_schema'] : [];
        $config = is_array($payload['config'] ?? null) ? $payload['config'] : [];

        foreach ($this->flattenSchemaRules($schema) as $rule) {
            $field = trim((string) ($rule['field'] ?? ''));
            if ($field === '' || !is_array($rule['validate'] ?? null)) {
                continue;
            }

            $requiredRule = $this->requiredValidateRule((array) $rule['validate']);
            if ($requiredRule === null) {
                continue;
            }

            if ($this->isEmptyFormValue($config[$field] ?? null)) {
                $message = trim((string) ($requiredRule['message'] ?? ''));
                if ($message === '') {
                    $message = '请填写' . (string) ($rule['title'] ?? $field);
                }

                throw new PaymentException($message, 40288, ['field' => $field]);
            }
        }
    }

    /**
     * 按插件最新进件配置 schema 裁剪提交值。
     *
     * 前端编辑时可能会带回旧字段，后端保存前再次收口，保证业务配置只落当前 schema 字段。
     *
     * @param array<string, mixed> $config 提交的配置值
     * @param PaymentPlugin $plugin 插件定义
     * @return array<string, mixed> 裁剪后的配置值
     */
    private function pruneConfigBySchema(array $config, PaymentPlugin $plugin): array
    {
        $info = is_array($plugin->onboarding_info) ? $plugin->onboarding_info : [];
        $schema = is_array($info['config_schema'] ?? null) ? $info['config_schema'] : [];
        $allowedFields = [];
        foreach ($this->flattenSchemaRules($schema) as $rule) {
            $field = trim((string) ($rule['field'] ?? ''));
            if ($field !== '') {
                $allowedFields[] = $field;
            }
        }

        return array_intersect_key($config, array_flip(array_unique($allowedFields)));
    }

    /**
     * 展开 form-create schema，便于统一读取嵌套字段。
     *
     * @param array<int, array<string, mixed>> $rules 插件表单 schema
     * @return array<int, array<string, mixed>>
     */
    private function flattenSchemaRules(array $rules): array
    {
        $flat = [];
        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                continue;
            }

            $flat[] = $rule;
            if (is_array($rule['children'] ?? null)) {
                $flat = array_merge($flat, $this->flattenSchemaRules((array) $rule['children']));
            }
        }

        return $flat;
    }

    /**
     * 读取字段上的 required 校验规则。
     *
     * @param array<int, array<string, mixed>> $validateRules form-create 校验规则
     * @return array<string, mixed>|null
     */
    private function requiredValidateRule(array $validateRules): ?array
    {
        foreach ($validateRules as $rule) {
            if (is_array($rule) && ($rule['required'] ?? false) === true) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * 判断接口配置字段是否为空，兼容普通输入和上传组件返回值。
     */
    private function isEmptyFormValue(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }
        if (is_string($value)) {
            return trim($value) === '';
        }
        if (is_array($value)) {
            if ($value === []) {
                return true;
            }

            $keys = array_keys($value);
            $isList = $keys === range(0, count($value) - 1);
            if ($isList) {
                foreach ($value as $item) {
                    if (!$this->isEmptyFormValue($item)) {
                        return false;
                    }
                }

                return true;
            }

            foreach (['value', 'url', 'path', 'id', 'file_id', 'preview_url'] as $key) {
                if (array_key_exists($key, $value) && !$this->isEmptyFormValue($value[$key])) {
                    return false;
                }
            }

            return false;
        }

        return false;
    }

    /**
     * 装饰列表行。
     */
    private function decorateRow($row)
    {
        $row->config_masked = $this->maskSensitiveData($this->decodeJson($row->config ?? []));
        // 列表不暴露接口凭证明文，编辑详情接口再返回原始配置。
        $row->config = $row->config_masked;
        $row->plugin_onboarding_info = $this->decodeJson($row->plugin_onboarding_info ?? []);
        $this->appendConfigTexts($row);

        return $row;
    }

    /**
     * 追加状态、可见性和主体范围文案。
     */
    private function appendConfigTexts($row): void
    {
        $row->status_text = (int) ($row->status ?? 0) === 1 ? '启用' : '禁用';
        $row->merchant_visible_text = (int) ($row->merchant_visible ?? 0) === 1 ? '可见' : '隐藏';
        $row->subject_type_text = implode('、', array_map(
            fn ($type): string => OnboardingConstant::subjectTypeMap()[(string) $type] ?? (string) $type,
            (array) ($row->subject_types ?? [])
        ));
    }

    /**
     * @return array<int, array{label: string, value: string}>
     */
    private function subjectTypeOptions(array $types): array
    {
        return array_values(array_map(
            fn ($type): array => [
                'label' => OnboardingConstant::subjectTypeMap()[(string) $type] ?? (string) $type,
                'value' => (string) $type,
            ],
            $types
        ));
    }

    /**
     * 根据配置选择的产品裁剪插件声明的产品选项。
     *
     * @param array<int, string> $selected 配置允许的产品编码
     * @param array<string, mixed> $info 插件进件声明
     * @return array<int, array<string, mixed>>
     */
    private function normalizeProducts(array $selected, array $info): array
    {
        $all = is_array($info['products'] ?? null) ? $info['products'] : [];
        if ($selected === []) {
            return array_values($all);
        }

        return array_values(array_filter($all, function ($item) use ($selected): bool {
            $value = (string) ($item['value'] ?? $item['code'] ?? '');

            return $value !== '' && in_array($value, $selected, true);
        }));
    }

    /**
     * 将逗号字符串或数组归一化为字符串数组。
     *
     * @return array<int, string>
     */
    private function stringList(mixed $value): array
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(static fn ($item): string => trim((string) $item), $value)));
    }

    /**
     * 兼容数据库 JSON 字符串和模型 array cast 两种形态。
     *
     * @return array<string, mixed>
     */
    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }
}
