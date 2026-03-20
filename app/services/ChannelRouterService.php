<?php

namespace app\services;

use app\common\base\BaseService;
use app\exceptions\NotFoundException;
use app\models\PaymentChannel;
use app\repositories\PaymentChannelRepository;
use app\repositories\PaymentMethodRepository;
use app\repositories\PaymentOrderRepository;

/**
 * 通道路由服务
 *
 * 负责根据商户、应用、支付方式和已保存策略选择可用通道，
 * 同时也为后台提供策略草稿预览能力，保证预览和真实下单尽量一致。
 */
class ChannelRouterService extends BaseService
{
    private const HEALTH_LOOKBACK_DAYS = 7;

    public function __construct(
        protected PaymentChannelRepository $channelRepository,
        protected PaymentMethodRepository $methodRepository,
        protected PaymentOrderRepository $orderRepository,
        protected ChannelRoutePolicyService $routePolicyService,
    ) {
    }

    /**
     * 向后兼容：只返回选中的通道模型。
     */
    public function chooseChannel(int $merchantId, int $merchantAppId, int $methodId, float $amount = 0): PaymentChannel
    {
        $decision = $this->chooseChannelWithDecision($merchantId, $merchantAppId, $methodId, $amount);
        return $decision['channel'];
    }

    /**
     * 返回完整路由决策信息，便于下单链路记录调度痕迹。
     *
     * @return array{
     *   channel:PaymentChannel,
     *   source:string,
     *   route_mode:string,
     *   policy:?array,
     *   candidates:array<int, array<string, mixed>>
     * }
     */
    public function chooseChannelWithDecision(int $merchantId, int $merchantAppId, int $methodId, float $amount = 0): array
    {
        $routingContext = $this->loadRoutingContexts($merchantId, $merchantAppId, $methodId, $amount);
        $method = $routingContext['method'];
        $contexts = $routingContext['contexts'];

        $decision = $this->chooseByPolicy(
            $merchantId,
            $merchantAppId,
            (string)$method->method_code,
            $contexts
        );

        if ($decision !== null) {
            return $decision;
        }

        $decision = $this->chooseFallback($contexts);
        if ($decision !== null) {
            return $decision;
        }

        throw new NotFoundException(
            $this->buildNoChannelMessage($merchantId, $merchantAppId, (string)$method->method_name, $contexts)
        );
    }

    /**
     * 预览一个尚未保存的策略草稿在当前真实通道环境下会如何命中。
     */
    public function previewPolicyDraft(
        int $merchantId,
        int $merchantAppId,
        int $methodId,
        array $policy,
        float $amount = 0
    ): array {
        $routingContext = $this->loadRoutingContexts($merchantId, $merchantAppId, $methodId, $amount);
        $method = $routingContext['method'];
        $contexts = $routingContext['contexts'];
        $previewPolicy = $this->normalizePreviewPolicy($policy, $merchantId, $merchantAppId, (string)$method->method_code);
        $evaluation = $this->evaluatePolicy($previewPolicy, $contexts);

        $selectedChannel = null;
        if ($evaluation['selected_candidate'] !== null) {
            $selectedContext = $contexts[(int)$evaluation['selected_candidate']['channel_id']] ?? null;
            if ($selectedContext !== null) {
                /** @var PaymentChannel $channel */
                $channel = $selectedContext['channel'];
                $selectedChannel = [
                    'id' => (int)$channel->id,
                    'chan_code' => (string)$channel->chan_code,
                    'chan_name' => (string)$channel->chan_name,
                ];
            }
        }

        return [
            'matched' => $selectedChannel !== null,
            'source' => 'preview',
            'route_mode' => (string)($previewPolicy['route_mode'] ?? 'priority'),
            'policy' => $this->buildPolicyMeta($previewPolicy),
            'selected_channel' => $selectedChannel,
            'candidates' => $evaluation['candidates'],
            'summary' => [
                'candidate_count' => count($evaluation['candidates']),
                'available_count' => count($evaluation['available_candidates']),
                'blocked_count' => count($evaluation['candidates']) - count($evaluation['available_candidates']),
            ],
            'message' => $selectedChannel !== null ? '本次模拟已命中策略通道' : '当前策略下没有可用通道',
        ];
    }

    private function chooseByPolicy(int $merchantId, int $merchantAppId, string $methodCode, array $contexts): ?array
    {
        return $this->chooseByPolicies(
            $merchantId,
            $merchantAppId,
            $methodCode,
            $contexts,
            $this->routePolicyService->list()
        );
    }

    private function chooseFallback(array $contexts): ?array
    {
        $candidates = [];
        foreach ($contexts as $context) {
            /** @var PaymentChannel $channel */
            $channel = $context['channel'];
            $candidates[] = [
                'channel_id' => (int)$channel->id,
                'chan_code' => (string)$channel->chan_code,
                'chan_name' => (string)$channel->chan_name,
                'available' => $context['available'],
                'reasons' => $context['reasons'],
                'priority' => (int)$channel->sort,
                'weight' => 100,
                'role' => 'normal',
                'health_score' => $context['health_score'],
                'success_rate' => $context['success_rate'],
            ];
        }

        $availableCandidates = array_values(array_filter($candidates, fn(array $item) => (bool)$item['available']));
        if ($availableCandidates === []) {
            return null;
        }

        usort($availableCandidates, function (array $left, array $right) {
            if (($left['priority'] ?? 0) === ($right['priority'] ?? 0)) {
                if (($right['health_score'] ?? 0) === ($left['health_score'] ?? 0)) {
                    return ($right['success_rate'] ?? 0) <=> ($left['success_rate'] ?? 0);
                }
                return ($right['health_score'] ?? 0) <=> ($left['health_score'] ?? 0);
            }
            return ($left['priority'] ?? 0) <=> ($right['priority'] ?? 0);
        });

        $selectedCandidate = $availableCandidates[0];
        $selectedContext = $contexts[(int)$selectedCandidate['channel_id']] ?? null;
        if (!$selectedContext) {
            return null;
        }

        return [
            'channel' => $selectedContext['channel'],
            'source' => 'fallback',
            'route_mode' => 'sort',
            'policy' => null,
            'candidates' => $candidates,
        ];
    }

    private function loadRoutingContexts(int $merchantId, int $merchantAppId, int $methodId, float $amount): array
    {
        $method = $this->methodRepository->find($methodId);
        if (!$method) {
            throw new NotFoundException("未找到支付方式：{$methodId}");
        }

        $channels = $this->channelRepository->searchList([
            'merchant_id' => $merchantId,
            'merchant_app_id' => $merchantAppId,
            'method_id' => $methodId,
        ]);

        if ($channels->isEmpty()) {
            throw new NotFoundException(
                "未找到可用的支付通道：商户ID={$merchantId}, 应用ID={$merchantAppId}, 支付方式ID={$methodId}"
            );
        }

        $todayRange = $this->getDateRange(1);
        $recentRange = $this->getDateRange(self::HEALTH_LOOKBACK_DAYS);
        $channelIds = [];
        foreach ($channels as $channel) {
            $channelIds[] = (int)$channel->id;
        }

        $todayStatsMap = $this->orderRepository->aggregateByChannel($channelIds, [
            'merchant_id' => $merchantId,
            'merchant_app_id' => $merchantAppId,
            'method_id' => $methodId,
            'created_from' => $todayRange['created_from'],
            'created_to' => $todayRange['created_to'],
        ]);
        $recentStatsMap = $this->orderRepository->aggregateByChannel($channelIds, [
            'merchant_id' => $merchantId,
            'merchant_app_id' => $merchantAppId,
            'method_id' => $methodId,
            'created_from' => $recentRange['created_from'],
            'created_to' => $recentRange['created_to'],
        ]);

        $contexts = [];
        foreach ($channels as $channel) {
            $contexts[(int)$channel->id] = $this->buildChannelContext(
                $channel,
                $todayStatsMap[(int)$channel->id] ?? [],
                $recentStatsMap[(int)$channel->id] ?? [],
                $amount
            );
        }

        return [
            'method' => $method,
            'contexts' => $contexts,
        ];
    }

    private function buildChannelContext(PaymentChannel $channel, array $todayStats, array $recentStats, float $amount): array
    {
        $reasons = [];
        $status = (int)$channel->status;

        $todayOrders = (int)($todayStats['total_orders'] ?? 0);
        $todaySuccessAmount = round((float)($todayStats['success_amount'] ?? 0), 2);
        $recentTotalOrders = (int)($recentStats['total_orders'] ?? 0);
        $recentSuccessOrders = (int)($recentStats['success_orders'] ?? 0);
        $recentPendingOrders = (int)($recentStats['pending_orders'] ?? 0);
        $recentFailOrders = (int)($recentStats['fail_orders'] ?? 0);

        $dailyLimit = (float)$channel->daily_limit;
        $dailyCount = (int)$channel->daily_cnt;
        $minAmount = $channel->min_amount === null ? null : (float)$channel->min_amount;
        $maxAmount = $channel->max_amount === null ? null : (float)$channel->max_amount;

        if ($status !== 1) {
            $reasons[] = '通道已禁用';
        }
        if ($amount > 0 && $minAmount !== null && $amount < $minAmount) {
            $reasons[] = '低于最小支付金额';
        }
        if ($amount > 0 && $maxAmount !== null && $maxAmount > 0 && $amount > $maxAmount) {
            $reasons[] = '超过最大支付金额';
        }
        if ($dailyLimit > 0 && $todaySuccessAmount + max(0, $amount) > $dailyLimit) {
            $reasons[] = '超出单日限额';
        }
        if ($dailyCount > 0 && $todayOrders + 1 > $dailyCount) {
            $reasons[] = '超出单日笔数限制';
        }

        $successRate = $recentTotalOrders > 0 ? round($recentSuccessOrders / $recentTotalOrders * 100, 2) : 0;
        $dailyLimitUsageRate = $dailyLimit > 0 ? round(min(100, ($todaySuccessAmount / $dailyLimit) * 100), 2) : null;
        $healthScore = $this->calculateHealthScore(
            $status,
            $recentTotalOrders,
            $recentSuccessOrders,
            $recentPendingOrders,
            $recentFailOrders,
            $dailyLimitUsageRate
        );

        return [
            'channel' => $channel,
            'available' => $reasons === [],
            'reasons' => $reasons,
            'success_rate' => $successRate,
            'health_score' => $healthScore,
            'today_orders' => $todayOrders,
            'today_success_amount' => $todaySuccessAmount,
        ];
    }

    private function calculateHealthScore(
        int $status,
        int $totalOrders,
        int $successOrders,
        int $pendingOrders,
        int $failOrders,
        ?float $todayLimitUsageRate
    ): int {
        if ($status !== 1) {
            return 0;
        }

        if ($totalOrders === 0) {
            return 60;
        }

        $successRate = $totalOrders > 0 ? ($successOrders / $totalOrders * 100) : 0;
        $healthScore = 90;

        if ($successRate < 95) {
            $healthScore -= 10;
        }
        if ($successRate < 80) {
            $healthScore -= 15;
        }
        if ($successRate < 60) {
            $healthScore -= 20;
        }
        if ($failOrders > 0) {
            $healthScore -= min(15, $failOrders * 3);
        }
        if ($pendingOrders > max(3, (int)floor($successOrders / 2))) {
            $healthScore -= 10;
        }
        if ($todayLimitUsageRate !== null && $todayLimitUsageRate >= 90) {
            $healthScore -= 20;
        } elseif ($todayLimitUsageRate !== null && $todayLimitUsageRate >= 75) {
            $healthScore -= 10;
        }

        return max(0, min(100, $healthScore));
    }

    private function chooseByPolicies(
        int $merchantId,
        int $merchantAppId,
        string $methodCode,
        array $contexts,
        array $policies
    ): ?array {
        $matchedPolicies = array_values(array_filter($policies, function (array $policy) use (
            $merchantId,
            $merchantAppId,
            $methodCode
        ) {
            if ((int)($policy['status'] ?? 0) !== 1) {
                return false;
            }
            if (($policy['method_code'] ?? '') !== $methodCode) {
                return false;
            }

            $policyMerchantId = (int)($policy['merchant_id'] ?? 0);
            if ($policyMerchantId > 0 && $policyMerchantId !== $merchantId) {
                return false;
            }

            $policyAppId = (int)($policy['merchant_app_id'] ?? 0);
            if ($policyAppId > 0 && $policyAppId !== $merchantAppId) {
                return false;
            }

            return is_array($policy['items'] ?? null) && $policy['items'] !== [];
        }));

        if ($matchedPolicies === []) {
            return null;
        }

        usort($matchedPolicies, function (array $left, array $right) {
            $leftScore = $this->calculatePolicySpecificity($left);
            $rightScore = $this->calculatePolicySpecificity($right);
            if ($leftScore === $rightScore) {
                return strcmp((string)($right['updated_at'] ?? ''), (string)($left['updated_at'] ?? ''));
            }
            return $rightScore <=> $leftScore;
        });

        foreach ($matchedPolicies as $policy) {
            $evaluation = $this->evaluatePolicy($policy, $contexts);
            if ($evaluation['selected_candidate'] === null) {
                continue;
            }

            $selectedContext = $contexts[(int)$evaluation['selected_candidate']['channel_id']] ?? null;
            if (!$selectedContext) {
                continue;
            }

            return [
                'channel' => $selectedContext['channel'],
                'source' => 'policy',
                'route_mode' => (string)($policy['route_mode'] ?? 'priority'),
                'policy' => $this->buildPolicyMeta($policy),
                'candidates' => $evaluation['candidates'],
            ];
        }

        return null;
    }

    private function normalizePolicyItems(array $items): array
    {
        $normalized = [];
        foreach ($items as $index => $item) {
            $normalized[] = [
                'channel_id' => (int)($item['channel_id'] ?? 0),
                'role' => (string)($item['role'] ?? ($index === 0 ? 'primary' : 'backup')),
                'weight' => max(0, (int)($item['weight'] ?? 100)),
                'priority' => max(1, (int)($item['priority'] ?? ($index + 1))),
            ];
        }

        usort($normalized, function (array $left, array $right) {
            if (($left['priority'] ?? 0) === ($right['priority'] ?? 0)) {
                if (($left['role'] ?? '') === ($right['role'] ?? '')) {
                    return ($right['weight'] ?? 0) <=> ($left['weight'] ?? 0);
                }

                return ($left['role'] ?? '') === 'primary' ? -1 : 1;
            }

            return ($left['priority'] ?? 0) <=> ($right['priority'] ?? 0);
        });

        return $normalized;
    }

    private function evaluatePolicy(array $policy, array $contexts): array
    {
        $items = $this->normalizePolicyItems($policy['items'] ?? []);
        $candidates = [];

        foreach ($items as $item) {
            $channelId = (int)($item['channel_id'] ?? 0);
            $context = $contexts[$channelId] ?? null;
            if (!$context) {
                $candidates[] = [
                    'channel_id' => $channelId,
                    'chan_code' => '',
                    'chan_name' => '',
                    'available' => false,
                    'reasons' => ['通道不存在或不属于当前应用'],
                    'priority' => (int)($item['priority'] ?? 1),
                    'weight' => (int)($item['weight'] ?? 100),
                    'role' => (string)($item['role'] ?? 'backup'),
                    'health_score' => 0,
                    'success_rate' => 0,
                ];
                continue;
            }

            /** @var PaymentChannel $channel */
            $channel = $context['channel'];
            $pluginCode = trim((string)($policy['plugin_code'] ?? ''));
            $policyReasons = [];
            if ($pluginCode !== '' && (string)$channel->plugin_code !== $pluginCode) {
                $policyReasons[] = '插件与策略限定不匹配';
            }

            $available = $context['available'] && $policyReasons === [];
            $candidates[] = [
                'channel_id' => (int)$channel->id,
                'chan_code' => (string)$channel->chan_code,
                'chan_name' => (string)$channel->chan_name,
                'available' => $available,
                'reasons' => $available ? [] : array_values(array_unique(array_merge($context['reasons'], $policyReasons))),
                'priority' => (int)($item['priority'] ?? 1),
                'weight' => (int)($item['weight'] ?? 100),
                'role' => (string)($item['role'] ?? 'backup'),
                'health_score' => $context['health_score'],
                'success_rate' => $context['success_rate'],
            ];
        }

        $availableCandidates = array_values(array_filter($candidates, fn(array $item) => (bool)$item['available']));
        $selectedCandidate = $availableCandidates === []
            ? null
            : $this->pickCandidateByMode($availableCandidates, (string)($policy['route_mode'] ?? 'priority'));

        return [
            'candidates' => $candidates,
            'available_candidates' => $availableCandidates,
            'selected_candidate' => $selectedCandidate,
        ];
    }

    private function pickCandidateByMode(array $candidates, string $routeMode): array
    {
        usort($candidates, function (array $left, array $right) {
            if (($left['priority'] ?? 0) === ($right['priority'] ?? 0)) {
                if (($left['role'] ?? '') === ($right['role'] ?? '')) {
                    return ($right['weight'] ?? 0) <=> ($left['weight'] ?? 0);
                }

                return ($left['role'] ?? '') === 'primary' ? -1 : 1;
            }

            return ($left['priority'] ?? 0) <=> ($right['priority'] ?? 0);
        });

        if ($routeMode !== 'weight') {
            return $candidates[0];
        }

        $totalWeight = 0;
        foreach ($candidates as $candidate) {
            $totalWeight += max(0, (int)($candidate['weight'] ?? 0));
        }

        if ($totalWeight <= 0) {
            return $candidates[0];
        }

        $cursor = mt_rand(1, $totalWeight);
        foreach ($candidates as $candidate) {
            $cursor -= max(0, (int)($candidate['weight'] ?? 0));
            if ($cursor <= 0) {
                return $candidate;
            }
        }

        return $candidates[0];
    }

    private function calculatePolicySpecificity(array $policy): int
    {
        $score = 0;
        if ((int)($policy['merchant_id'] ?? 0) > 0) {
            $score += 10;
        }
        if ((int)($policy['merchant_app_id'] ?? 0) > 0) {
            $score += 20;
        }
        if (trim((string)($policy['plugin_code'] ?? '')) !== '') {
            $score += 5;
        }

        return $score;
    }

    private function buildPolicyMeta(array $policy): array
    {
        return [
            'id' => (string)($policy['id'] ?? ''),
            'policy_name' => (string)($policy['policy_name'] ?? ''),
            'plugin_code' => (string)($policy['plugin_code'] ?? ''),
            'circuit_breaker_threshold' => (int)($policy['circuit_breaker_threshold'] ?? 0),
            'failover_cooldown' => (int)($policy['failover_cooldown'] ?? 0),
        ];
    }

    private function buildNoChannelMessage(int $merchantId, int $merchantAppId, string $methodName, array $contexts): string
    {
        $messages = [];
        foreach ($contexts as $context) {
            /** @var PaymentChannel $channel */
            $channel = $context['channel'];
            $reasonText = $context['reasons'] === [] ? '无可用原因记录' : implode('、', $context['reasons']);
            $messages[] = sprintf('%s（%s）：%s', (string)$channel->chan_name, (string)$channel->chan_code, $reasonText);
        }

        usort($messages, fn(string $left, string $right) => strcmp($left, $right));
        $messages = array_slice($messages, 0, 3);

        $suffix = $messages === [] ? '' : '，原因：' . implode('；', $messages);

        return sprintf(
            '未找到可用的支付通道：商户ID=%d，应用ID=%d，支付方式=%s%s',
            $merchantId,
            $merchantAppId,
            $methodName,
            $suffix
        );
    }

    private function getDateRange(int $days): array
    {
        $days = max(1, $days);
        return [
            'created_from' => date('Y-m-d 00:00:00', strtotime('-' . ($days - 1) . ' days')),
            'created_to' => date('Y-m-d H:i:s'),
        ];
    }

    private function normalizePreviewPolicy(array $policy, int $merchantId, int $merchantAppId, string $methodCode): array
    {
        $routeMode = trim((string)($policy['route_mode'] ?? 'priority'));
        if (!in_array($routeMode, ['priority', 'weight', 'failover'], true)) {
            $routeMode = 'priority';
        }

        return [
            'id' => trim((string)($policy['id'] ?? 'preview_policy')),
            'policy_name' => trim((string)($policy['policy_name'] ?? '策略草稿')),
            'merchant_id' => $merchantId,
            'merchant_app_id' => $merchantAppId,
            'method_code' => $methodCode,
            'plugin_code' => trim((string)($policy['plugin_code'] ?? '')),
            'route_mode' => $routeMode,
            'status' => 1,
            'circuit_breaker_threshold' => max(0, min(100, (int)($policy['circuit_breaker_threshold'] ?? 50))),
            'failover_cooldown' => max(0, (int)($policy['failover_cooldown'] ?? 10)),
            'items' => $this->normalizePolicyItems($policy['items'] ?? []),
            'updated_at' => date('Y-m-d H:i:s'),
        ];
    }
}
