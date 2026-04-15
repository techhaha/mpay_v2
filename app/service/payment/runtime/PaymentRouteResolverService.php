<?php

namespace app\service\payment\runtime;

use app\common\base\BaseService;
use app\common\constant\CommonConstant;
use app\common\constant\RouteConstant;
use app\common\util\FormatHelper;
use app\exception\BusinessStateException;
use app\exception\ResourceNotFoundException;
use app\exception\ValidationException;
use app\model\payment\PaymentChannel;
use app\model\payment\PaymentPollGroup;
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
 * 负责商户分组 -> 轮询组 -> 支付通道的编排与选择。
 */
class PaymentRouteResolverService extends BaseService
{
    public function __construct(
        protected PaymentPollGroupBindRepository $bindRepository,
        protected PaymentPollGroupRepository $pollGroupRepository,
        protected PaymentPollGroupChannelRepository $pollGroupChannelRepository,
        protected PaymentChannelRepository $channelRepository,
        protected ChannelDailyStatRepository $channelDailyStatRepository,
        protected PaymentPluginRepository $paymentPluginRepository,
        protected PaymentTypeRepository $paymentTypeRepository
    ) {
    }

    /**
     * 按商户分组和支付方式解析路由。
     *
     * @return array{bind:mixed,poll_group:mixed,candidates:array,selected_channel:array}
     */
    public function resolveByMerchantGroup(int $merchantGroupId, int $payTypeId, int $payAmount, array $context = []): array
    {
        if ($merchantGroupId <= 0 || $payTypeId <= 0 || $payAmount <= 0) {
            throw new ValidationException('路由参数不合法');
        }

        $bind = $this->bindRepository->findActiveByMerchantGroupAndPayType($merchantGroupId, $payTypeId);
        if (!$bind) {
            throw new ResourceNotFoundException('路由不存在', [
                'merchant_group_id' => $merchantGroupId,
                'pay_type_id' => $payTypeId,
            ]);
        }

        /** @var PaymentPollGroup|null $pollGroup */
        $pollGroup = $this->pollGroupRepository->find((int) $bind->poll_group_id);
        if (!$pollGroup || (int) $pollGroup->status !== CommonConstant::STATUS_ENABLED) {
            throw new ResourceNotFoundException('路由不存在', [
                'merchant_group_id' => $merchantGroupId,
                'pay_type_id' => $payTypeId,
                'poll_group_id' => (int) ($bind->poll_group_id ?? 0),
            ]);
        }

        $candidateRows = $this->pollGroupChannelRepository->listByPollGroupId((int) $pollGroup->id);
        if ($candidateRows->isEmpty()) {
            throw new BusinessStateException('支付通道不可用', [
                'poll_group_id' => (int) $pollGroup->id,
            ]);
        }

        $channelIds = $candidateRows->pluck('channel_id')->all();
        $channels = $this->channelRepository->query()
            ->whereIn('id', $channelIds)
            ->where('status', CommonConstant::STATUS_ENABLED)
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
        $payTypeCode = trim((string) ($paymentType->code ?? ''));

        $statDate = $context['stat_date'] ?? FormatHelper::timestamp(time(), 'Y-m-d');
        $payAmount = (int) $payAmount;
        $eligible = [];

        foreach ($candidateRows as $row) {
            $channelId = (int) $row->channel_id;

            /** @var PaymentChannel|null $channel */
            $channel = $channels->get($channelId);
            if (!$channel) {
                continue;
            }

            if ((int) $channel->pay_type_id !== $payTypeId) {
                continue;
            }

            $plugin = $plugins[(string) $channel->plugin_code] ?? null;
            if (!$plugin || (int) $plugin->status !== CommonConstant::STATUS_ENABLED) {
                continue;
            }

            $pluginPayTypes = is_array($plugin->pay_types) ? $plugin->pay_types : [];
            $pluginPayTypes = array_values(array_filter(array_map(static fn ($item) => trim((string) $item), $pluginPayTypes)));
            if ($payTypeCode === '' || !in_array($payTypeCode, $pluginPayTypes, true)) {
                continue;
            }

            if (!$this->isAmountAllowed($channel, $payAmount)) {
                continue;
            }

            $stat = $this->channelDailyStatRepository->findByChannelAndDate($channelId, $statDate);
            if (!$this->isDailyLimitAllowed($channel, $payAmount, $statDate, $stat)) {
                continue;
            }

            $eligible[] = [
                'channel' => $channel,
                'poll_group_channel' => $row,
                'daily_stat' => $stat,
                'health_score' => (int) ($stat->health_score ?? 0),
                'success_rate_bp' => (int) ($stat->success_rate_bp ?? 0),
                'avg_latency_ms' => (int) ($stat->avg_latency_ms ?? 0),
                'weight' => max(1, (int) $row->weight),
                'is_default' => (int) $row->is_default,
                'sort_no' => (int) $row->sort_no,
            ];
        }

        if (empty($eligible)) {
            throw new BusinessStateException('支付通道不可用', [
                'poll_group_id' => (int) $pollGroup->id,
                'merchant_group_id' => $merchantGroupId,
                'pay_type_id' => $payTypeId,
            ]);
        }

        $routeMode = (int) $pollGroup->route_mode;
        $ordered = $this->sortCandidates($eligible, $routeMode);
        $selected = $this->selectChannel($ordered, $routeMode, (int) $pollGroup->id);

        return [
            'bind' => $bind,
            'poll_group' => $pollGroup,
            'candidates' => $ordered,
            'selected_channel' => $selected,
        ];
    }

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

    private function sortCandidates(array $candidates, int $routeMode): array
    {
        usort($candidates, function (array $left, array $right) use ($routeMode) {
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

    private function selectChannel(array $candidates, int $routeMode, int $pollGroupId): array
    {
        if (count($candidates) === 1) {
            return $candidates[0];
        }

        return match ($routeMode) {
            RouteConstant::ROUTE_MODE_WEIGHTED => $this->selectWeightedChannel($candidates),
            RouteConstant::ROUTE_MODE_ORDER => $this->selectSequentialChannel($candidates, $pollGroupId),
            RouteConstant::ROUTE_MODE_FIRST_AVAILABLE => $this->selectDefaultChannel($candidates),
            default => $candidates[0],
        };
    }

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

    private function selectSequentialChannel(array $candidates, int $pollGroupId): array
    {
        if ($pollGroupId <= 0) {
            return $candidates[0];
        }

        try {
            $cursorKey = sprintf('payment:route:round_robin:%d', $pollGroupId);
            $cursor = (int) Redis::incr($cursorKey);
            Redis::expire($cursorKey, 30 * 86400);
            $index = max(0, ($cursor - 1) % count($candidates));

            return $candidates[$index] ?? $candidates[0];
        } catch (\Throwable) {
            return $candidates[0];
        }
    }

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
