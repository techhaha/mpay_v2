<?php

namespace app\service\payment\runtime;

use app\common\base\BaseService;
use app\common\constant\CommonConstant;
use app\common\constant\RouteConstant;
use app\common\util\FormatHelper;
use app\exception\BusinessStateException;
use app\exception\ResourceNotFoundException;
use app\exception\ValidationException;
use Throwable;
use app\model\payment\PaymentChannel;
use app\model\payment\PaymentPollGroup;
use app\repository\merchant\base\MerchantPolicyRepository;
use app\repository\ops\stat\ChannelDailyStatRepository;
use app\repository\payment\config\PaymentChannelRepository;
use app\repository\payment\config\PaymentPollGroupBindRepository;
use app\repository\payment\config\PaymentPollGroupChannelRepository;
use app\repository\payment\config\PaymentPollGroupRepository;
use app\repository\payment\config\PaymentPluginRepository;
use app\repository\payment\config\PaymentTypeRepository;
use support\Redis;

/**
 * 支付路由解析服务。
 *
 * 负责商户分组、轮询组、支付类型和支付通道之间的筛选、排序与最终选择。
 *
 * @property PaymentPollGroupBindRepository $bindRepository 绑定仓库
 * @property PaymentPollGroupRepository $pollGroupRepository 轮询分组仓库
 * @property PaymentPollGroupChannelRepository $pollGroupChannelRepository 轮询分组渠道仓库
 * @property PaymentChannelRepository $channelRepository 渠道仓库
 * @property ChannelDailyStatRepository $channelDailyStatRepository 渠道日统计仓库
 * @property PaymentPluginRepository $paymentPluginRepository 支付插件仓库
 * @property PaymentTypeRepository $paymentTypeRepository 支付类型仓库
 * @property MerchantPolicyRepository $merchantPolicyRepository 商户策略仓库
 */
class PaymentRouteResolverService extends BaseService
{
    /**
     * 构造方法。
     *
     * @param PaymentPollGroupBindRepository $bindRepository 绑定仓库
     * @param PaymentPollGroupRepository $pollGroupRepository 轮询分组仓库
     * @param PaymentPollGroupChannelRepository $pollGroupChannelRepository 轮询分组渠道仓库
     * @param PaymentChannelRepository $channelRepository 渠道仓库
     * @param ChannelDailyStatRepository $channelDailyStatRepository 渠道日统计仓库
     * @param PaymentPluginRepository $paymentPluginRepository 支付插件仓库
     * @param PaymentTypeRepository $paymentTypeRepository 支付类型仓库
     * @param MerchantPolicyRepository $merchantPolicyRepository 商户策略仓库
     * @return void
     */
    public function __construct(
        protected PaymentPollGroupBindRepository $bindRepository,
        protected PaymentPollGroupRepository $pollGroupRepository,
        protected PaymentPollGroupChannelRepository $pollGroupChannelRepository,
        protected PaymentChannelRepository $channelRepository,
        protected ChannelDailyStatRepository $channelDailyStatRepository,
        protected PaymentPluginRepository $paymentPluginRepository,
        protected PaymentTypeRepository $paymentTypeRepository,
        protected MerchantPolicyRepository $merchantPolicyRepository
    ) {
    }

    /**
     * 按商户分组和支付方式解析路由。
     *
     * 先读取有效的商户分组绑定和轮询组，再按支付类型、插件支持、金额区间和日限额过滤候选通道，
     * 最后依据轮询组策略选出实际使用的通道。
     *
     * @param int $merchantGroupId 商户分组ID
     * @param int $payTypeId 支付类型ID
     * @param int $payAmount 支付金额（分）
     * @param array $context 路由上下文，支持传入 `stat_date` 等辅助参数
     * @return array 路由解析结果
     * @throws ValidationException
     * @throws ResourceNotFoundException
     * @throws BusinessStateException
     */
    public function resolveByMerchantGroup(int $merchantGroupId, int $payTypeId, int $payAmount, array $context = []): array
    {
        if ($merchantGroupId <= 0 || $payTypeId <= 0 || $payAmount <= 0) {
            throw new ValidationException('路由参数不合法');
        }

        // 先锁定商户分组与支付方式的绑定，再进入正式通道选路。
        $bind = $this->bindRepository->findActiveByMerchantGroupAndPayType($merchantGroupId, $payTypeId);
        if (!$bind) {
            if ((int) ($context['merchant_id'] ?? 0) <= 0) {
                throw new ResourceNotFoundException('路由不存在', [
                    'merchant_group_id' => $merchantGroupId,
                    'pay_type_id' => $payTypeId,
                ]);
            }

            $route = $this->resolveRouteSelection(
                $merchantGroupId,
                0,
                $payTypeId,
                $payAmount,
                $context
            );
            $route['bind'] = null;

            return $route;
        }

        $route = $this->resolveRouteSelection(
            $merchantGroupId,
            (int) $bind->poll_group_id,
            $payTypeId,
            $payAmount,
            $context
        );
        $route['bind'] = $bind;

        return $route;
    }

    /**
     * 预览商户可用支付方式。
     *
     * 这里会遍历所有启用中的支付方式，并复用正式路由解析逻辑筛出真正可用的方式。
     *
     * @param int $merchantGroupId 商户分组ID
     * @param int $payAmount 支付金额（分）
     * @param array $context 路由上下文
     * @return array<int, array<string, mixed>> 可用支付方式列表
     */
    public function previewAvailablePayTypes(int $merchantGroupId, int $payAmount, array $context = []): array
    {
        if ($merchantGroupId <= 0 || $payAmount <= 0) {
            return [];
        }

        // 预览阶段只拿绑定摘要，不先把所有通道明细一次性拉出来。
        $merchantId = (int) ($context['merchant_id'] ?? 0);
        $bindRows = $this->bindRepository->listSummaryByMerchantGroupId($merchantGroupId);
        $payTypeIds = $bindRows
            ->filter(fn ($row): bool => (int) ($row->status ?? 0) === CommonConstant::STATUS_ENABLED)
            ->pluck('pay_type_id')
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->values()
            ->all();

        if ($merchantId > 0) {
            $selfPayTypeIds = $this->channelRepository->query()
                ->where('merchant_id', $merchantId)
                ->where('channel_mode', RouteConstant::CHANNEL_MODE_SELF)
                ->pluck('pay_type_id')
                ->map(fn ($id): int => (int) $id)
                ->filter(fn (int $id): bool => $id > 0)
                ->values()
                ->all();
            $payTypeIds = array_merge($payTypeIds, $selfPayTypeIds);
        }

        $payTypeIds = array_values(array_unique($payTypeIds));
        if ($payTypeIds === []) {
            return [];
        }

        $available = [];
        foreach ($payTypeIds as $payTypeId) {
            try {
                // 每个可用支付方式仍然复用正式选路逻辑，只是最终结果被压成前端可展示摘要。
                $route = $this->resolveByMerchantGroup(
                    $merchantGroupId,
                    $payTypeId,
                    $payAmount,
                    $context
                );
            } catch (Throwable) {
                continue;
            }

            $selected = $route['selected_channel'];
            /** @var PaymentChannel $channel */
            $channel = $selected['channel'];
            $paymentType = $this->paymentTypeRepository->find($payTypeId);
            $available[] = [
                'pay_type_id' => $payTypeId,
                'code' => (string) ($paymentType->code ?? ''),
                'name' => (string) ($paymentType->name ?? ''),
                'icon' => (string) ($paymentType->icon ?? ''),
                'selected_channel_id' => (int) $channel->id,
                'selected_channel_name' => (string) $channel->name,
                'selected_channel_mode' => (int) $channel->channel_mode,
            ];
        }

        return $available;
    }

    /**
     * 解析指定轮询组与支付方式的可用通道路由。
     *
     * 该方法负责轮询组、候选通道、插件和统计数据的加载与过滤，并返回排序后的候选集和最终选中通道。
     *
     * @param int $merchantGroupId 商户分组ID
     * @param int $pollGroupId 轮询组ID
     * @param int $payTypeId 支付类型ID
     * @param int $payAmount 支付金额（分）
     * @param array $context 路由上下文，支持传入 `stat_date` 等辅助参数
     * @return array{
     *     poll_group: PaymentPollGroup|null,
     *     candidates: array<int, array<string, mixed>>,
     *     selected_channel: array<string, mixed>
     * }
     * @throws ValidationException
     * @throws ResourceNotFoundException
     * @throws BusinessStateException
     */
    private function resolveRouteSelection(int $merchantGroupId, int $pollGroupId, int $payTypeId, int $payAmount, array $context = []): array
    {
        if ($merchantGroupId <= 0 || $payTypeId <= 0 || $payAmount <= 0) {
            throw new ValidationException('路由参数不合法');
        }

        $pollGroup = null;
        $candidateRows = collect();
        $merchantId = (int) ($context['merchant_id'] ?? 0);
        $routePreference = $this->merchantRoutePreference($merchantId, $payTypeId);
        if ($pollGroupId > 0) {
            /** @var PaymentPollGroup|null $pollGroup */
            $pollGroup = $this->pollGroupRepository->find($pollGroupId);
            if (!$pollGroup || (int) $pollGroup->status !== CommonConstant::STATUS_ENABLED) {
                throw new ResourceNotFoundException('路由不存在', [
                    'merchant_group_id' => $merchantGroupId,
                    'pay_type_id' => $payTypeId,
                    'poll_group_id' => $pollGroupId,
                ]);
            }

            $candidateRows = $this->pollGroupChannelRepository->listByPollGroupId((int) $pollGroup->id);
        }

        if (!$routePreference['use_platform_channels']) {
            $candidateRows = collect();
        }

        $candidateRows = $this->mergeMerchantSelfCandidates($candidateRows, $payTypeId, $merchantId, $pollGroupId);
        if ($candidateRows->isEmpty()) {
            throw new BusinessStateException('支付通道不可用', [
                'poll_group_id' => $pollGroup ? (int) $pollGroup->id : 0,
                'merchant_id' => $merchantId,
            ]);
        }

        // 先把轮询组里的候选通道、插件和渠道统计一次性取齐，再做逐层过滤。
        $channelIds = $candidateRows->pluck('channel_id')->all();
        $channels = $this->channelRepository->query()
            ->whereIn('id', $channelIds)
            ->get()
            ->keyBy('id');
        $pluginCodes = $channels->pluck('plugin_code')->filter()->unique()->values()->all();
        $plugins = [];
        if (!empty($pluginCodes)) {
            $plugins = $this->paymentPluginRepository->query()
                ->whereIn('code', $pluginCodes)
                ->get()
                ->keyBy('code')
                ->all();
        }
        $paymentType = $this->paymentTypeRepository->find($payTypeId);
        if (!$paymentType || (int) $paymentType->status !== CommonConstant::STATUS_ENABLED) {
            throw new ValidationException('支付方式不支持');
        }
        $payTypeCode = trim((string) ($paymentType->code ?? ''));
        $statDate = $context['stat_date'] ?? FormatHelper::timestamp(time(), 'Y-m-d');
        $payAmount = (int) $payAmount;
        $eligible = [];
        $rejected = [];

        foreach ($candidateRows as $row) {
            $channelId = (int) $row->channel_id;

            /** @var PaymentChannel|null $channel */
            $channel = $channels->get($channelId);
            $plugin = $channel ? ($plugins[(string) $channel->plugin_code] ?? null) : null;
            $stat = $channel ? $this->channelDailyStatRepository->findByChannelAndDate($channelId, $statDate) : null;
            $isMerchantSelfCandidate = (string) ($row->source_type ?? '') === 'merchant_self';
            $rejectReasons = $this->resolveCandidateRejectReasons(
                $channel,
                $plugin,
                $payTypeId,
                $payTypeCode,
                $payAmount,
                $statDate,
                $stat,
                $isMerchantSelfCandidate
            );
            $candidate = [
                'channel' => $channel,
                'poll_group_channel' => $row,
                'daily_stat' => $stat,
                'health_score' => (int) ($stat->health_score ?? 0),
                'success_rate_bp' => (int) ($stat->success_rate_bp ?? 0),
                'avg_latency_ms' => (int) ($stat->avg_latency_ms ?? 0),
                'weight' => max(1, (int) $row->weight),
                'is_default' => $this->isRouteDefaultCandidate($row, $routePreference),
                'sort_no' => (int) $row->sort_no,
                'available' => empty($rejectReasons),
                'reject_reasons' => $rejectReasons,
                'reject_reason_text' => implode('；', $rejectReasons),
                'channel_id' => $channelId,
            ];

            if (!empty($rejectReasons)) {
                $rejected[] = $candidate;
                continue;
            }

            $eligible[] = $candidate;
        }

        if (empty($eligible)) {
            if (!empty($context['_preview'])) {
                return [
                    'available' => false,
                    'reason' => '支付通道不可用',
                    'poll_group' => $pollGroup,
                    'candidates' => [],
                    'rejected_candidates' => $rejected,
                    'selected_channel' => null,
                ];
            }

            throw new BusinessStateException('支付通道不可用', [
                'poll_group_id' => $pollGroup ? (int) $pollGroup->id : 0,
                'merchant_group_id' => $merchantGroupId,
                'pay_type_id' => $payTypeId,
            ]);
        }

        $routeMode = $routePreference['route_mode'] !== null
            ? (int) $routePreference['route_mode']
            : ($pollGroup ? (int) $pollGroup->route_mode : RouteConstant::ROUTE_MODE_FIRST_AVAILABLE);
        $cursorKey = $this->routeCursorKey($merchantId, $payTypeId, $pollGroup ? (int) $pollGroup->id : 0);
        // 剩余候选再按轮询组策略排序，最终只从排序结果里挑一条。
        $ordered = $this->sortCandidates($eligible, $routeMode);
        $selected = $this->selectChannel($ordered, $routeMode, $cursorKey);

        return [
            'available' => true,
            'poll_group' => $pollGroup,
            'route_preference' => $routePreference,
            'candidates' => $ordered,
            'rejected_candidates' => $rejected,
            'selected_channel' => $selected,
        ];
    }

    /**
     * 把当前商户自建自收通道合并进候选集。
     *
     * @param mixed $candidateRows 轮询组候选行集合
     * @param int $payTypeId 支付方式ID
     * @param int $merchantId 当前商户ID
     * @param int $pollGroupId 轮询组ID
     * @return mixed 合并后的候选行集合
     */
    private function mergeMerchantSelfCandidates($candidateRows, int $payTypeId, int $merchantId, int $pollGroupId)
    {
        if ($merchantId <= 0) {
            return $candidateRows;
        }

        $existingChannelIds = $candidateRows
            ->filter(fn ($row): bool => (string) ($row->source_type ?? '') === 'merchant_self')
            ->pluck('channel_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $selfChannels = $this->channelRepository->query()
            ->where('merchant_id', $merchantId)
            ->where('channel_mode', RouteConstant::CHANNEL_MODE_SELF)
            ->where('pay_type_id', $payTypeId)
            ->orderBy('sort_no')
            ->orderBy('id')
            ->get(['id', 'sort_no']);

        foreach ($selfChannels as $channel) {
            $channelId = (int) $channel->id;
            if (in_array($channelId, $existingChannelIds, true)) {
                continue;
            }

            $candidateRows->push((object) [
                'poll_group_id' => $pollGroupId,
                'channel_id' => $channelId,
                'sort_no' => (int) $channel->sort_no,
                'weight' => 1,
                'is_default' => 0,
                'status' => CommonConstant::STATUS_ENABLED,
                'source_type' => 'merchant_self',
            ]);
            $existingChannelIds[] = $channelId;
        }

        return $candidateRows;
    }

    /**
     * 读取当前商户在指定支付方式下的路由偏好。
     *
     * @param int $merchantId 商户ID
     * @param int $payTypeId 支付方式ID
     * @return array{configured: bool, use_platform_channels: bool, route_mode: int|null, default_channel_id: int}
     */
    private function merchantRoutePreference(int $merchantId, int $payTypeId): array
    {
        $default = [
            'configured' => false,
            'use_platform_channels' => true,
            'route_mode' => null,
            'default_channel_id' => 0,
        ];

        if ($merchantId <= 0 || $payTypeId <= 0) {
            return $default;
        }

        $policy = $this->merchantPolicyRepository->findByMerchantId($merchantId, ['route_policy_json']);
        $routePolicy = is_array($policy?->route_policy_json) ? $policy->route_policy_json : [];
        $payTypePolicies = is_array($routePolicy['pay_types'] ?? null) ? $routePolicy['pay_types'] : [];
        $item = $payTypePolicies[(string) $payTypeId] ?? $payTypePolicies[$payTypeId] ?? null;
        if (!is_array($item)) {
            return $default;
        }

        $routeMode = array_key_exists('route_mode', $item) ? (int) $item['route_mode'] : null;
        if (!in_array($routeMode, array_keys(RouteConstant::routeModeMap()), true)) {
            $routeMode = null;
        }

        return [
            'configured' => true,
            'use_platform_channels' => $this->truthy($item['use_platform_channels'] ?? true),
            'route_mode' => $routeMode,
            'default_channel_id' => max(0, (int) ($item['default_channel_id'] ?? 0)),
        ];
    }

    /**
     * 判断当前候选是否应作为商户偏好的默认通道。
     *
     * @param object $row 候选通道行
     * @param array<string, mixed> $routePreference 商户路由偏好
     * @return int 默认标记
     */
    private function isRouteDefaultCandidate(object $row, array $routePreference): int
    {
        $defaultChannelId = (int) ($routePreference['default_channel_id'] ?? 0);
        if ($defaultChannelId > 0) {
            return (int) $row->channel_id === $defaultChannelId ? 1 : 0;
        }

        return (int) $row->is_default;
    }

    /**
     * 路由顺序轮询游标键。
     *
     * @param int $merchantId 商户ID
     * @param int $payTypeId 支付方式ID
     * @param int $pollGroupId 轮询组ID
     * @return string 游标键
     */
    private function routeCursorKey(int $merchantId, int $payTypeId, int $pollGroupId): string
    {
        if ($merchantId > 0) {
            return sprintf('merchant:%d:pay_type:%d', $merchantId, $payTypeId);
        }

        return sprintf('poll_group:%d', $pollGroupId);
    }

    /**
     * 解析布尔配置。
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

    /**
     * 生成通道被过滤的原因。
     *
     * @param PaymentChannel|null $channel 渠道
     * @param object|null $plugin 插件
     * @param int $payTypeId 支付方式ID
     * @param string $payTypeCode 支付方式编码
     * @param int $payAmount 支付金额，单位分
     * @param string $statDate 统计日期
     * @param object|null $stat 当日统计
     * @param bool $isMerchantSelfCandidate 是否为当前商户自建候选
     * @return array<int, string> 过滤原因列表
     */
    private function resolveCandidateRejectReasons(
        ?PaymentChannel $channel,
        ?object $plugin,
        int $payTypeId,
        string $payTypeCode,
        int $payAmount,
        string $statDate,
        ?object $stat,
        bool $isMerchantSelfCandidate = false
    ): array {
        if (!$channel) {
            return ['通道配置不存在'];
        }

        $reasons = [];
        if (
            !$isMerchantSelfCandidate
            && ((int) $channel->merchant_id !== 0 || (int) $channel->channel_mode !== RouteConstant::CHANNEL_MODE_COLLECT)
        ) {
            $reasons[] = '商户自建通道不能由平台路由分配';
        }
        if ((int) $channel->status !== CommonConstant::STATUS_ENABLED) {
            $reasons[] = '通道已禁用';
        }
        if ((int) $channel->pay_type_id !== $payTypeId) {
            $reasons[] = '通道支付方式不匹配';
        }
        if (!$plugin) {
            $reasons[] = '插件不存在';
        } elseif ((int) $plugin->status !== CommonConstant::STATUS_ENABLED) {
            $reasons[] = '插件已禁用';
        } else {
            $pluginPayTypes = is_array($plugin->pay_types) ? $plugin->pay_types : [];
            $pluginPayTypes = array_values(array_filter(array_map(static fn ($item) => trim((string) $item), $pluginPayTypes)));
            if ($payTypeCode === '' || !in_array($payTypeCode, $pluginPayTypes, true)) {
                $reasons[] = '插件不支持该支付方式';
            }
        }
        if (!$this->isAmountAllowed($channel, $payAmount)) {
            $reasons[] = sprintf(
                '金额不在通道范围内（最小 %s，最大 %s）',
                (int) $channel->min_amount > 0 ? FormatHelper::amount((int) $channel->min_amount) : '不限',
                (int) $channel->max_amount > 0 ? FormatHelper::amount((int) $channel->max_amount) : '不限'
            );
        }
        if (!$this->isDailyLimitAllowed($channel, $payAmount, $statDate, $stat)) {
            $reasons[] = '超过通道单日限额或限笔';
        }

        return $reasons;
    }

    /**
     * 判断通道是否满足金额区间。
     *
     * @param PaymentChannel $channel 渠道
     * @param int $payAmount 支付金额（分）
     * @return bool 是否可用
     */
    private function isAmountAllowed(PaymentChannel $channel, int $payAmount): bool
    {
        if ((int) $channel->min_amount > 0 && $payAmount < (int) $channel->min_amount) {
            return false;
        }

        if ((int) $channel->max_amount > 0 && $payAmount > (int) $channel->max_amount) {
            return false;
        }

        return true;
    }

    /**
     * 判断通道是否满足日限额和日成功笔数。
     *
     * @param PaymentChannel $channel 渠道
     * @param int $payAmount 支付金额（分）
     * @param string $statDate 统计日期
     * @param object|null $stat 当日统计数据
     * @return bool 是否可用
     */
    private function isDailyLimitAllowed(PaymentChannel $channel, int $payAmount, string $statDate, ?object $stat = null): bool
    {
        if ((int) $channel->daily_limit_amount <= 0 && (int) $channel->daily_limit_count <= 0) {
            return true;
        }

        $stat ??= $this->channelDailyStatRepository->findByChannelAndDate((int) $channel->id, $statDate);
        $currentAmount = (int) ($stat->pay_amount ?? 0);
        $currentCount = (int) ($stat->pay_success_count ?? 0);

        if ((int) $channel->daily_limit_amount > 0 && $currentAmount + $payAmount > (int) $channel->daily_limit_amount) {
            return false;
        }

        if ((int) $channel->daily_limit_count > 0 && $currentCount + 1 > (int) $channel->daily_limit_count) {
            return false;
        }

        return true;
    }

    /**
     * 按路由模式整理候选通道顺序。
     *
     * @param array $candidates 候选通道列表
     * @param int $routeMode 路由模式
     * @return array 排序后的候选列表
     */
    private function sortCandidates(array $candidates, int $routeMode): array
    {
        usort($candidates, function (array $left, array $right) use ($routeMode) {
            // 第一可用模式下先把默认通道排到前面，其余模式再按排序号和主键做稳定排序。
            if (
                $routeMode === RouteConstant::ROUTE_MODE_FIRST_AVAILABLE
                && (int) $left['is_default'] !== (int) $right['is_default']
            ) {
                return (int) $right['is_default'] <=> (int) $left['is_default'];
            }

            if ((int) $left['sort_no'] !== (int) $right['sort_no']) {
                return (int) $left['sort_no'] <=> (int) $right['sort_no'];
            }

            return (int) $left['channel']->id <=> (int) $right['channel']->id;
        });

        return $candidates;
    }

    /**
     * 根据路由模式选择最终通道。
     *
     * @param array $candidates 候选通道列表
     * @param int $routeMode 路由模式
     * @param int $pollGroupId 轮询分组ID
     * @return array 选中的通道候选
     */
    private function selectChannel(array $candidates, int $routeMode, string $cursorKey): array
    {
        if (count($candidates) === 1) {
            return $candidates[0];
        }

        return match ($routeMode) {
            RouteConstant::ROUTE_MODE_WEIGHTED => $this->selectWeightedChannel($candidates),
            RouteConstant::ROUTE_MODE_ORDER => $this->selectSequentialChannel($candidates, $cursorKey),
            RouteConstant::ROUTE_MODE_FIRST_AVAILABLE => $this->selectDefaultChannel($candidates),
            default => $candidates[0],
        };
    }

    /**
     * 按权重随机选择通道。
     *
     * @param array $candidates 候选通道列表
     * @return array 选中的通道候选
     */
    private function selectWeightedChannel(array $candidates): array
    {
        $totalWeight = array_sum(array_map(static fn (array $item) => max(1, (int) $item['weight']), $candidates));
        $random = random_int(1, max(1, $totalWeight));

        foreach ($candidates as $candidate) {
            $random -= max(1, (int) $candidate['weight']);
            if ($random <= 0) {
                return $candidate;
            }
        }

        return $candidates[0];
    }

    /**
     * 按轮询游标顺序选择通道。
     *
     * @param array $candidates 候选通道列表
     * @param string $cursorKey 轮询游标键
     * @return array 选中的通道候选
     */
    private function selectSequentialChannel(array $candidates, string $cursorKey): array
    {
        if ($cursorKey === '') {
            return $candidates[0];
        }

        try {
            // 用 Redis 维护跨进程共享的轮询游标，避免每个 PHP 进程各选各的。
            $redisKey = sprintf('payment:route:round_robin:%s', $cursorKey);
            $cursor = (int) Redis::incr($redisKey);
            // 游标保留一个较长的生命周期，避免 Redis 清理后轮询顺序完全丢失。
            Redis::expire($redisKey, 30 * 86400);
            // Redis 自增从 1 开始，这里转成 0 基索引后再对候选集取模。
            $index = max(0, ($cursor - 1) % count($candidates));

            return $candidates[$index] ?? $candidates[0];
        } catch (\Throwable) {
            // Redis 不可用时降级成首个候选，保证路由还能继续往下走。
            return $candidates[0];
        }
    }

    /**
     * 优先返回默认通道，否则返回首个候选。
     *
     * @param array $candidates 候选通道列表
     * @return array 选中的通道候选
     */
    private function selectDefaultChannel(array $candidates): array
    {
        foreach ($candidates as $candidate) {
            if ((int) ($candidate['is_default'] ?? 0) === 1) {
                return $candidate;
            }
        }

        return $candidates[0];
    }
}
