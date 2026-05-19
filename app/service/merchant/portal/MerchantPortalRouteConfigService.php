<?php

namespace app\service\merchant\portal;

use app\common\base\BaseService;
use app\common\constant\CommonConstant;
use app\common\constant\RouteConstant;
use app\exception\PaymentException;
use app\repository\merchant\base\MerchantPolicyRepository;
use app\repository\payment\config\PaymentChannelRepository;
use app\repository\payment\config\PaymentPollGroupBindRepository;
use app\repository\payment\config\PaymentPollGroupChannelRepository;

/**
 * 商户路由偏好配置服务。
 *
 * 使用 ma_merchant_policy.route_policy_json 保存商户端可维护的轻量路由偏好。
 */
class MerchantPortalRouteConfigService extends BaseService
{
    public function __construct(
        protected MerchantPortalSupportService $supportService,
        protected MerchantPolicyRepository $merchantPolicyRepository,
        protected PaymentChannelRepository $paymentChannelRepository,
        protected PaymentPollGroupBindRepository $pollGroupBindRepository,
        protected PaymentPollGroupChannelRepository $pollGroupChannelRepository
    ) {
    }

    /**
     * 查询当前商户的路由偏好配置。
     *
     * @param int $merchantId 商户ID
     * @return array<string, mixed> 配置数据
     */
    public function settings(int $merchantId): array
    {
        $merchant = $this->supportService->merchantSummary($merchantId);
        $policy = $this->routePolicy($merchantId);
        $payTypeOptions = $this->supportService->enabledPayTypeOptions();
        $channelOptions = $this->visibleChannelOptions($merchantId, (int) ($merchant['merchant_group_id'] ?? 0));

        return [
            'merchant' => $merchant,
            'route_modes' => $this->routeModeOptions(),
            'items' => array_values(array_map(function (array $payType) use ($policy, $channelOptions): array {
                $payTypeId = (int) ($payType['value'] ?? $payType['id'] ?? 0);
                $item = $policy[(string) $payTypeId] ?? [];

                return [
                    'pay_type_id' => $payTypeId,
                    'pay_type_code' => (string) ($payType['code'] ?? ''),
                    'pay_type_name' => (string) ($payType['label'] ?? $payType['name'] ?? ''),
                    'use_platform_channels' => $this->truthy($item['use_platform_channels'] ?? true) ? 1 : 0,
                    'route_mode' => $this->validRouteMode($item['route_mode'] ?? RouteConstant::ROUTE_MODE_ORDER),
                    'default_channel_id' => max(0, (int) ($item['default_channel_id'] ?? 0)),
                    'default_channel_options' => $channelOptions[$payTypeId] ?? [],
                ];
            }, $payTypeOptions)),
        ];
    }

    /**
     * 保存当前商户的路由偏好配置。
     *
     * @param int $merchantId 商户ID
     * @param array<string, mixed> $payload 表单数据
     * @return array<string, mixed> 保存后的配置数据
     */
    public function save(int $merchantId, array $payload): array
    {
        $merchant = $this->supportService->merchantSummary($merchantId);
        $payTypeIds = array_map(
            static fn (array $item): int => (int) ($item['value'] ?? $item['id'] ?? 0),
            $this->supportService->enabledPayTypeOptions()
        );
        $payTypeIds = array_values(array_filter(array_unique($payTypeIds), static fn (int $id): bool => $id > 0));
        $channelOptions = $this->visibleChannelOptions($merchantId, (int) ($merchant['merchant_group_id'] ?? 0));
        $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
        $normalized = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $payTypeId = (int) ($item['pay_type_id'] ?? 0);
            if ($payTypeId <= 0 || !in_array($payTypeId, $payTypeIds, true)) {
                throw new PaymentException('支付方式不支持配置路由偏好', 40260, ['pay_type_id' => $payTypeId]);
            }

            $usePlatformChannels = $this->truthy($item['use_platform_channels'] ?? true);
            $defaultChannelId = max(0, (int) ($item['default_channel_id'] ?? 0));
            $defaultOption = $this->findChannelOption($channelOptions[$payTypeId] ?? [], $defaultChannelId);
            if ($defaultChannelId > 0 && $defaultOption === null) {
                throw new PaymentException('默认通道不属于当前商户可用通道', 40261, [
                    'pay_type_id' => $payTypeId,
                    'channel_id' => $defaultChannelId,
                ]);
            }
            if ($defaultChannelId > 0 && !$usePlatformChannels && (string) ($defaultOption['source_type'] ?? '') === 'system') {
                throw new PaymentException('已关闭平台通道时不能选择系统分配通道作为默认通道', 40262, [
                    'pay_type_id' => $payTypeId,
                    'channel_id' => $defaultChannelId,
                ]);
            }

            $normalized[(string) $payTypeId] = [
                'use_platform_channels' => $usePlatformChannels ? 1 : 0,
                'route_mode' => $this->validRouteMode($item['route_mode'] ?? RouteConstant::ROUTE_MODE_ORDER),
                'default_channel_id' => $defaultChannelId,
            ];
        }

        $existing = $this->merchantPolicyRepository->findByMerchantId($merchantId);
        $routePolicy = is_array($existing?->route_policy_json) ? $existing->route_policy_json : [];
        $routePolicy['pay_types'] = $normalized;

        $this->merchantPolicyRepository->updateOrCreate(
            ['merchant_id' => $merchantId],
            [
                'merchant_id' => $merchantId,
                'settlement_cycle_override' => (int) ($existing->settlement_cycle_override ?? 1),
                'auto_payout' => (int) ($existing->auto_payout ?? 0),
                'min_settlement_amount' => (int) ($existing->min_settlement_amount ?? 0),
                'retry_policy_json' => is_array($existing?->retry_policy_json) ? $existing->retry_policy_json : [],
                'route_policy_json' => $routePolicy,
                'fee_rule_override_json' => is_array($existing?->fee_rule_override_json) ? $existing->fee_rule_override_json : [],
                'risk_policy_json' => is_array($existing?->risk_policy_json) ? $existing->risk_policy_json : [],
                'remark' => (string) ($existing->remark ?? ''),
            ]
        );

        return $this->settings($merchantId);
    }

    /**
     * 读取路由策略中按支付方式保存的配置。
     *
     * @param int $merchantId 商户ID
     * @return array<string, array<string, mixed>> 路由策略
     */
    private function routePolicy(int $merchantId): array
    {
        $policy = $this->merchantPolicyRepository->findByMerchantId($merchantId, ['route_policy_json']);
        $routePolicy = is_array($policy?->route_policy_json) ? $policy->route_policy_json : [];

        return is_array($routePolicy['pay_types'] ?? null) ? $routePolicy['pay_types'] : [];
    }

    /**
     * 当前商户可见通道按支付方式分组。
     *
     * @param int $merchantId 商户ID
     * @param int $merchantGroupId 商户分组ID
     * @return array<int, array<int, array<string, mixed>>> 通道选项
     */
    private function visibleChannelOptions(int $merchantId, int $merchantGroupId): array
    {
        $assignedChannelIds = $this->assignedChannelIds($merchantGroupId);
        $query = $this->paymentChannelRepository->query()
            ->from('ma_payment_channel as c')
            ->where('c.status', CommonConstant::STATUS_ENABLED)
            ->where(function ($builder) use ($merchantId, $assignedChannelIds) {
                $builder->where(function ($inner) use ($merchantId) {
                    $inner->where('c.merchant_id', $merchantId)
                        ->where('c.channel_mode', RouteConstant::CHANNEL_MODE_SELF);
                });

                if ($assignedChannelIds !== []) {
                    $builder->orWhere(function ($inner) use ($assignedChannelIds) {
                        $inner->whereIn('c.id', $assignedChannelIds)
                            ->where('c.merchant_id', 0)
                            ->where('c.channel_mode', RouteConstant::CHANNEL_MODE_COLLECT);
                    });
                }
            })
            ->orderBy('c.pay_type_id')
            ->orderBy('c.sort_no')
            ->orderBy('c.id')
            ->get(['c.id', 'c.name', 'c.pay_type_id', 'c.merchant_id', 'c.channel_mode']);

        $grouped = [];
        foreach ($query as $channel) {
            $payTypeId = (int) $channel->pay_type_id;
            $grouped[$payTypeId][] = [
                'label' => sprintf('%s（%d）', (string) $channel->name, (int) $channel->id),
                'value' => (int) $channel->id,
                'source_type' => (int) $channel->merchant_id === 0 ? 'system' : 'merchant',
                'source_text' => (int) $channel->merchant_id === 0 ? '系统分配' : '自建通道',
            ];
        }

        return $grouped;
    }

    /**
     * 获取商户分组路由分配的通道 ID。
     *
     * @param int $merchantGroupId 商户分组ID
     * @return array<int, int> 通道 ID
     */
    private function assignedChannelIds(int $merchantGroupId): array
    {
        if ($merchantGroupId <= 0) {
            return [];
        }

        $pollGroupIds = $this->pollGroupBindRepository->listSummaryByMerchantGroupId($merchantGroupId)
            ->filter(fn ($row): bool => (int) ($row->status ?? 0) === CommonConstant::STATUS_ENABLED)
            ->pluck('poll_group_id')
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($pollGroupIds === []) {
            return [];
        }

        $channelIds = [];
        foreach ($pollGroupIds as $pollGroupId) {
            foreach ($this->pollGroupChannelRepository->listByPollGroupId((int) $pollGroupId, ['channel_id', 'status']) as $row) {
                if ((int) ($row->status ?? 0) !== CommonConstant::STATUS_ENABLED) {
                    continue;
                }
                $channelId = (int) ($row->channel_id ?? 0);
                if ($channelId > 0) {
                    $channelIds[] = $channelId;
                }
            }
        }

        return array_values(array_unique($channelIds));
    }

    /**
     * 路由模式选项。
     *
     * @return array<int, array{label: string, value: int}>
     */
    private function routeModeOptions(): array
    {
        return array_map(
            static fn (int $value, string $label): array => ['label' => $label, 'value' => $value],
            array_keys(RouteConstant::routeModeMap()),
            array_values(RouteConstant::routeModeMap())
        );
    }

    /**
     * 校验路由模式。
     *
     * @param mixed $value 路由模式
     * @return int 路由模式
     */
    private function validRouteMode(mixed $value): int
    {
        $mode = (int) $value;

        return in_array($mode, array_keys(RouteConstant::routeModeMap()), true)
            ? $mode
            : RouteConstant::ROUTE_MODE_ORDER;
    }

    /**
     * 查找默认通道选项。
     *
     * @param array<int, array<string, mixed>> $options 通道选项
     * @param int $channelId 通道ID
     * @return array<string, mixed>|null 通道选项
     */
    private function findChannelOption(array $options, int $channelId): ?array
    {
        if ($channelId <= 0) {
            return null;
        }

        foreach ($options as $option) {
            if ((int) ($option['value'] ?? 0) === $channelId) {
                return $option;
            }
        }

        return null;
    }

    /**
     * 转换布尔配置。
     *
     * @param mixed $value 原始值
     * @return bool 布尔值
     */
    private function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on', 'enabled'], true);
    }
}
