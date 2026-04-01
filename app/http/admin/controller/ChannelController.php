<?php

namespace app\http\admin\controller;

use app\common\base\BaseController;
use app\repositories\PaymentChannelRepository;
use app\repositories\PaymentMethodRepository;
use app\repositories\PaymentOrderRepository;
use app\services\ChannelRoutePolicyService;
use app\services\ChannelRouterService;
use app\services\PluginService;
use support\Request;

class ChannelController extends BaseController
{
    public function __construct(
        protected PaymentChannelRepository $channelRepository,
        protected PaymentMethodRepository $methodRepository,
        protected PaymentOrderRepository $orderRepository,
        protected PluginService $pluginService,
        protected ChannelRoutePolicyService $routePolicyService,
        protected ChannelRouterService $channelRouterService,
    ) {
    }

    public function list(Request $request)
    {
        $page = max(1, (int)$request->get('page', 1));
        $pageSize = max(1, (int)$request->get('page_size', 10));
        $filters = $this->resolveChannelFilters($request, true);
        return $this->page($this->channelRepository->searchPaginate($filters, $page, $pageSize));
    }

    public function detail(Request $request)
    {
        $id = (int)$request->get('id', 0);
        if ($id <= 0) {
            return $this->fail('通道ID不能为空', 400);
        }

        $channel = $this->channelRepository->find($id);
        if (!$channel) {
            return $this->fail('通道不存在', 404);
        }

        $methodCode = '';
        if ((int)$channel->method_id > 0) {
            $method = $this->methodRepository->find((int)$channel->method_id);
            $methodCode = $method ? (string)$method->method_code : '';
        }

        try {
            $configSchema = $this->pluginService->getConfigSchema((string)$channel->plugin_code, $methodCode);
            $currentConfig = $channel->getConfigArray();
            if (isset($configSchema['fields']) && is_array($configSchema['fields'])) {
                foreach ($configSchema['fields'] as &$field) {
                    $fieldName = $field['field'] ?? '';
                    if ($fieldName !== '' && array_key_exists($fieldName, $currentConfig)) {
                        $field['value'] = $currentConfig[$fieldName];
                    }
                }
                unset($field);
            }
        } catch (\Throwable $e) {
            $configSchema = ['fields' => []];
        }

        return $this->success([
            'channel' => $channel,
            'method_code' => $methodCode,
            'config_schema' => $configSchema,
        ]);
    }

    public function save(Request $request)
    {
        $data = $request->post();
        $id = (int)($data['id'] ?? 0);
        $merchantId = (int)($data['merchant_id'] ?? 0);
        $merchantAppId = (int)($data['merchant_app_id'] ?? ($data['app_id'] ?? 0));
        $channelCode = trim((string)($data['channel_code'] ?? ($data['chan_code'] ?? '')));
        $channelName = trim((string)($data['channel_name'] ?? ($data['chan_name'] ?? '')));
        $pluginCode = trim((string)($data['plugin_code'] ?? ''));
        $methodCode = trim((string)($data['method_code'] ?? ''));
        $enabledProducts = $data['enabled_products'] ?? [];

        if ($merchantId <= 0) {
            return $this->fail('请选择所属商户', 400);
        }
        if ($merchantAppId <= 0) {
            return $this->fail('请选择所属应用', 400);
        }
        if ($channelName === '') {
            return $this->fail('请输入通道名称', 400);
        }
        if ($pluginCode === '' || $methodCode === '') {
            return $this->fail('支付插件和支付方式不能为空', 400);
        }

        $method = $this->methodRepository->findAnyByCode($methodCode);
        if (!$method) {
            return $this->fail('支付方式不存在', 400);
        }

        if ($channelCode !== '') {
            $exists = $this->channelRepository->findByChanCode($channelCode);
            if ($exists && (int)$exists->id !== $id) {
                return $this->fail('通道编码已存在', 400);
            }
        }

        try {
            $configJson = $this->pluginService->buildConfigFromForm($pluginCode, $methodCode, $data);
        } catch (\Throwable $e) {
            return $this->fail('插件不存在或配置错误：' . $e->getMessage(), 400);
        }

        $channelData = [
            'mer_id' => $merchantId,
            'app_id' => $merchantAppId,
            'chan_code' => $channelCode !== '' ? $channelCode : 'CH' . date('YmdHis') . mt_rand(1000, 9999),
            'chan_name' => $channelName,
            'plugin_code' => $pluginCode,
            'pay_type_id' => (int)$method->id,
            'config' => array_merge($configJson, [
                'enabled_products' => is_array($enabledProducts) ? array_values($enabledProducts) : [],
            ]),
            'split_ratio' => isset($data['split_ratio']) ? (float)$data['split_ratio'] : 100,
            'chan_cost' => isset($data['channel_cost']) ? (float)$data['channel_cost'] : 0,
            'chan_mode' => in_array(strtolower(trim((string)($data['channel_mode'] ?? 'wallet'))), ['1', 'direct', 'merchant'], true) ? 1 : 0,
            'daily_limit' => isset($data['daily_limit']) ? (float)$data['daily_limit'] : 0,
            'daily_cnt' => isset($data['daily_count']) ? (int)$data['daily_count'] : 0,
            'min_amount' => isset($data['min_amount']) && $data['min_amount'] !== '' ? (float)$data['min_amount'] : null,
            'max_amount' => isset($data['max_amount']) && $data['max_amount'] !== '' ? (float)$data['max_amount'] : null,
            'status' => (int)($data['status'] ?? 1),
            'sort' => (int)($data['sort'] ?? 0),
        ];

        if ($id > 0) {
            $channel = $this->channelRepository->find($id);
            if (!$channel) {
                return $this->fail('通道不存在', 404);
            }
            $this->channelRepository->updateById($id, $channelData);
        } else {
            $channel = $this->channelRepository->create($channelData);
            $id = (int)$channel->id;
        }

        return $this->success(['id' => $id], '保存成功');
    }

    public function toggle(Request $request)
    {
        $id = (int)$request->post('id', 0);
        $status = $request->post('status', null);
        if ($id <= 0 || $status === null) {
            return $this->fail('参数错误', 400);
        }
        $ok = $this->channelRepository->updateById($id, ['status' => (int)$status]);
        return $ok ? $this->success(null, '操作成功') : $this->fail('操作失败', 500);
    }

    public function monitor(Request $request)
    {
        $filters = $this->resolveChannelFilters($request);
        $days = $this->resolveDays($request->get('days', 7));
        $channels = $this->channelRepository->searchList($filters);
        if ($channels->isEmpty()) {
            return $this->success(['list' => [], 'summary' => $this->buildMonitorSummary([])]);
        }

        $orderFilters = [
            'merchant_id' => $filters['merchant_id'] ?? null,
            'merchant_app_id' => $filters['merchant_app_id'] ?? null,
            'method_id' => $filters['method_id'] ?? null,
            'created_from' => $days['created_from'],
            'created_to' => $days['created_to'],
        ];
        $channelIds = [];
        foreach ($channels as $channel) {
            $channelIds[] = (int)$channel->id;
        }
        $statsMap = $this->orderRepository->aggregateByChannel($channelIds, $orderFilters);
        $rows = [];
        foreach ($channels as $channel) {
            $rows[] = $this->buildMonitorRow($channel->toArray(), $statsMap[(int)$channel->id] ?? []);
        }

        usort($rows, function (array $left, array $right) {
            if (($right['health_score'] ?? 0) === ($left['health_score'] ?? 0)) {
                return ($left['sort'] ?? 0) <=> ($right['sort'] ?? 0);
            }
            return ($right['health_score'] ?? 0) <=> ($left['health_score'] ?? 0);
        });

        return $this->success(['list' => $rows, 'summary' => $this->buildMonitorSummary($rows)]);
    }

    public function polling(Request $request)
    {
        $filters = $this->resolveChannelFilters($request);
        $days = $this->resolveDays($request->get('days', 7));
        $channels = $this->channelRepository->searchList($filters);
        $testAmount = $request->get('test_amount', null);
        $testAmount = ($testAmount === null || $testAmount === '') ? null : (float)$testAmount;
        if ($channels->isEmpty()) {
            return $this->success(['list' => [], 'summary' => $this->buildPollingSummary([])]);
        }

        $orderFilters = [
            'merchant_id' => $filters['merchant_id'] ?? null,
            'merchant_app_id' => $filters['merchant_app_id'] ?? null,
            'method_id' => $filters['method_id'] ?? null,
            'created_from' => $days['created_from'],
            'created_to' => $days['created_to'],
        ];
        $channelIds = [];
        foreach ($channels as $channel) {
            $channelIds[] = (int)$channel->id;
        }
        $statsMap = $this->orderRepository->aggregateByChannel($channelIds, $orderFilters);
        $rows = [];
        foreach ($channels as $channel) {
            $monitorRow = $this->buildMonitorRow($channel->toArray(), $statsMap[(int)$channel->id] ?? []);
            $rows[] = $this->buildPollingRow($monitorRow, $testAmount);
        }

        $stateWeight = ['ready' => 0, 'degraded' => 1, 'blocked' => 2];
        usort($rows, function (array $left, array $right) use ($stateWeight) {
            $leftWeight = $stateWeight[$left['route_state'] ?? 'blocked'] ?? 9;
            $rightWeight = $stateWeight[$right['route_state'] ?? 'blocked'] ?? 9;
            if ($leftWeight === $rightWeight) {
                if (($right['route_score'] ?? 0) === ($left['route_score'] ?? 0)) {
                    return ($left['sort'] ?? 0) <=> ($right['sort'] ?? 0);
                }
                return ($right['route_score'] ?? 0) <=> ($left['route_score'] ?? 0);
            }
            return $leftWeight <=> $rightWeight;
        });

        foreach ($rows as $index => &$row) {
            $row['route_rank'] = $index + 1;
        }
        unset($row);

        return $this->success(['list' => $rows, 'summary' => $this->buildPollingSummary($rows)]);
    }

    public function policyList(Request $request)
    {
        $merchantId = (int)$request->get('merchant_id', 0);
        $merchantAppId = (int)$request->get('merchant_app_id', $request->get('app_id', 0));
        $methodCode = trim((string)$request->get('method_code', ''));
        $pluginCode = trim((string)$request->get('plugin_code', ''));
        $status = $request->get('status', null);

        $policies = $this->routePolicyService->list();
        $channelMap = [];
        foreach ($this->channelRepository->searchList([]) as $channel) {
            $channelMap[(int)$channel->id] = $channel->toArray();
        }

        $filtered = array_values(array_filter($policies, function (array $policy) use ($merchantId, $merchantAppId, $methodCode, $pluginCode, $status) {
            if ($merchantId > 0 && (int)($policy['merchant_id'] ?? 0) !== $merchantId) return false;
            if ($merchantAppId > 0 && (int)($policy['merchant_app_id'] ?? 0) !== $merchantAppId) return false;
            if ($methodCode !== '' && (string)($policy['method_code'] ?? '') !== $methodCode) return false;
            if ($pluginCode !== '' && (string)($policy['plugin_code'] ?? '') !== $pluginCode) return false;
            if ($status !== null && $status !== '' && (int)($policy['status'] ?? 0) !== (int)$status) return false;
            return true;
        }));

        $list = [];
        foreach ($filtered as $policy) {
            $items = [];
            foreach (($policy['items'] ?? []) as $index => $item) {
                $channelId = (int)($item['channel_id'] ?? 0);
                $channel = $channelMap[$channelId] ?? [];
                $items[] = [
                    'channel_id' => $channelId,
                    'role' => trim((string)($item['role'] ?? ($index === 0 ? 'primary' : 'backup'))),
                    'weight' => max(0, (int)($item['weight'] ?? 100)),
                    'priority' => max(1, (int)($item['priority'] ?? ($index + 1))),
                    'chan_code' => (string)($channel['chan_code'] ?? ''),
                    'chan_name' => (string)($channel['chan_name'] ?? ''),
                    'channel_status' => isset($channel['status']) ? (int)$channel['status'] : null,
                    'sort' => (int)($channel['sort'] ?? 0),
                    'plugin_code' => (string)($channel['plugin_code'] ?? ''),
                    'method_id' => (int)($channel['method_id'] ?? 0),
                    'merchant_id' => (int)($channel['merchant_id'] ?? 0),
                    'merchant_app_id' => (int)($channel['merchant_app_id'] ?? 0),
                ];
            }
            usort($items, fn(array $left, array $right) => ($left['priority'] ?? 0) <=> ($right['priority'] ?? 0));
            $policy['items'] = $items;
            $policy['channel_count'] = count($items);
            $list[] = $policy;
        }

        return $this->success([
            'list' => $list,
            'summary' => [
                'total' => count($list),
                'enabled' => count(array_filter($list, fn(array $policy) => (int)($policy['status'] ?? 0) === 1)),
            ],
        ]);
    }

    public function policySave(Request $request)
    {
        try {
            $payload = $this->preparePolicyPayload($request->post(), true);
            return $this->success($this->routePolicyService->save($payload), '保存成功');
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 400);
        }
    }

    public function policyPreview(Request $request)
    {
        try {
            $payload = $this->preparePolicyPayload($request->post(), false);
            $testAmount = $request->post('test_amount', $request->post('preview_amount', 0));
            $amount = ($testAmount === null || $testAmount === '') ? 0 : (float)$testAmount;
            $preview = $this->channelRouterService->previewPolicyDraft(
                (int)$payload['merchant_id'],
                (int)$payload['merchant_app_id'],
                (int)$payload['method_id'],
                $payload,
                $amount
            );
            return $this->success($preview);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 400);
        }
    }

    public function policyDelete(Request $request)
    {
        $id = trim((string)$request->post('id', ''));
        if ($id === '') {
            return $this->fail('策略ID不能为空', 400);
        }
        $ok = $this->routePolicyService->delete($id);
        return $ok ? $this->success(null, '删除成功') : $this->fail('策略不存在或已删除', 404);
    }

    private function preparePolicyPayload(array $data, bool $requirePolicyName = true): array
    {
        $policyName = trim((string)($data['policy_name'] ?? ''));
        $merchantId = (int)($data['merchant_id'] ?? 0);
        $merchantAppId = (int)($data['merchant_app_id'] ?? ($data['app_id'] ?? 0));
        $methodCode = trim((string)($data['method_code'] ?? ''));
        $pluginCode = trim((string)($data['plugin_code'] ?? ''));
        $routeMode = trim((string)($data['route_mode'] ?? 'priority'));
        $status = (int)($data['status'] ?? 1);
        $itemsInput = $data['items'] ?? [];

        if ($requirePolicyName && $policyName === '') throw new \InvalidArgumentException('请输入策略名称');
        if ($methodCode === '') throw new \InvalidArgumentException('请选择支付方式');
        if (!in_array($routeMode, ['priority', 'weight', 'failover'], true)) throw new \InvalidArgumentException('路由模式不合法');
        if (!is_array($itemsInput) || $itemsInput === []) throw new \InvalidArgumentException('请至少选择一个通道');
        if ($merchantId <= 0 || $merchantAppId <= 0) throw new \InvalidArgumentException('请先选择商户和应用');

        $method = $this->methodRepository->findAnyByCode($methodCode);
        if (!$method) throw new \InvalidArgumentException('支付方式不存在');

        $channelMap = [];
        foreach ($this->channelRepository->searchList([]) as $channel) {
            $channelMap[(int)$channel->id] = $channel->toArray();
        }

        $normalizedItems = [];
        $usedChannelIds = [];
        foreach ($itemsInput as $index => $item) {
            $channelId = (int)($item['channel_id'] ?? 0);
            if ($channelId <= 0) throw new \InvalidArgumentException('策略项中的通道ID不合法');
            if (in_array($channelId, $usedChannelIds, true)) throw new \InvalidArgumentException('策略中存在重复通道，请去重后再提交');

            $channel = $channelMap[$channelId] ?? null;
            if (!$channel) throw new \InvalidArgumentException('存在未找到的通道，请刷新后重试');
            if ($merchantId > 0 && (int)$channel['merchant_id'] !== $merchantId) throw new \InvalidArgumentException('策略中的通道与商户不匹配');
            if ($merchantAppId > 0 && (int)$channel['merchant_app_id'] !== $merchantAppId) throw new \InvalidArgumentException('策略中的通道与应用不匹配');
            if ((int)$channel['method_id'] !== (int)$method->id) throw new \InvalidArgumentException('策略中的通道与支付方式不匹配');
            if ($pluginCode !== '' && (string)$channel['plugin_code'] !== $pluginCode) throw new \InvalidArgumentException('策略中的通道与插件不匹配');

            $defaultRole = $routeMode === 'weight' ? 'normal' : ($index === 0 ? 'primary' : 'backup');
            $role = trim((string)($item['role'] ?? $defaultRole));
            if (!in_array($role, ['primary', 'backup', 'normal'], true)) {
                $role = $defaultRole;
            }
            $normalizedItems[] = [
                'channel_id' => $channelId,
                'role' => $role,
                'weight' => max(0, (int)($item['weight'] ?? 100)),
                'priority' => max(1, (int)($item['priority'] ?? ($index + 1))),
            ];
            $usedChannelIds[] = $channelId;
        }

        usort($normalizedItems, function (array $left, array $right) {
            if (($left['priority'] ?? 0) === ($right['priority'] ?? 0)) {
                return ($right['weight'] ?? 0) <=> ($left['weight'] ?? 0);
            }
            return ($left['priority'] ?? 0) <=> ($right['priority'] ?? 0);
        });

        foreach ($normalizedItems as $index => &$item) {
            $item['priority'] = $index + 1;
            if ($routeMode === 'weight' && $item['role'] === 'backup') {
                $item['role'] = 'normal';
            }
        }
        unset($item);

        return [
            'id' => trim((string)($data['id'] ?? '')),
            'policy_name' => $policyName !== '' ? $policyName : '策略草稿预览',
            'merchant_id' => $merchantId,
            'merchant_app_id' => $merchantAppId,
            'method_code' => $methodCode,
            'method_id' => (int)$method->id,
            'plugin_code' => $pluginCode,
            'route_mode' => $routeMode,
            'status' => $status,
            'circuit_breaker_threshold' => max(0, min(100, (int)($data['circuit_breaker_threshold'] ?? 50))),
            'failover_cooldown' => max(0, (int)($data['failover_cooldown'] ?? 10)),
            'remark' => trim((string)($data['remark'] ?? '')),
            'items' => $normalizedItems,
        ];
    }

    private function resolveChannelFilters(Request $request, bool $withKeywords = false): array
    {
        $filters = [];
        $merchantId = (int)$request->get('merchant_id', 0);
        if ($merchantId > 0) $filters['merchant_id'] = $merchantId;
        $merchantAppId = (int)$request->get('merchant_app_id', $request->get('app_id', 0));
        if ($merchantAppId > 0) $filters['merchant_app_id'] = $merchantAppId;
        $methodCode = trim((string)$request->get('method_code', ''));
        if ($methodCode !== '') {
            $method = $this->methodRepository->findAnyByCode($methodCode);
            $filters['method_id'] = $method ? (int)$method->id : -1;
        }
        $pluginCode = trim((string)$request->get('plugin_code', ''));
        if ($pluginCode !== '') $filters['plugin_code'] = $pluginCode;
        $status = $request->get('status', null);
        if ($status !== null && $status !== '') $filters['status'] = (int)$status;
        if ($withKeywords) {
            $chanCode = trim((string)$request->get('chan_code', ''));
            if ($chanCode !== '') $filters['chan_code'] = $chanCode;
            $chanName = trim((string)$request->get('chan_name', ''));
            if ($chanName !== '') $filters['chan_name'] = $chanName;
        }
        return $filters;
    }

    private function resolveDays(mixed $daysInput): array
    {
        $days = max(1, min(30, (int)$daysInput));
        return [
            'days' => $days,
            'created_from' => date('Y-m-d 00:00:00', strtotime('-' . ($days - 1) . ' days')),
            'created_to' => date('Y-m-d H:i:s'),
        ];
    }

    private function buildMonitorRow(array $channel, array $stats): array
    {
        $totalOrders = (int)($stats['total_orders'] ?? 0);
        $successOrders = (int)($stats['success_orders'] ?? 0);
        $pendingOrders = (int)($stats['pending_orders'] ?? 0);
        $failOrders = (int)($stats['fail_orders'] ?? 0);
        $closedOrders = (int)($stats['closed_orders'] ?? 0);
        $todayOrders = (int)($stats['today_orders'] ?? 0);
        $todaySuccessOrders = (int)($stats['today_success_orders'] ?? 0);
        $todaySuccessAmount = round((float)($stats['today_success_amount'] ?? 0), 2);
        $successRate = $totalOrders > 0 ? round($successOrders / $totalOrders * 100, 2) : 0;
        $dailyLimit = isset($channel['daily_limit']) ? (float)$channel['daily_limit'] : 0;
        $dailyCnt = isset($channel['daily_cnt']) ? (int)$channel['daily_cnt'] : 0;
        $todayLimitUsageRate = $dailyLimit > 0 ? round(min(100, ($todaySuccessAmount / $dailyLimit) * 100), 2) : null;
        $healthScore = 0;
        $healthLevel = 'disabled';
        $status = (int)($channel['status'] ?? 0);

        if ($status === 1) {
            if ($totalOrders === 0) {
                $healthScore = 60;
                $healthLevel = 'idle';
            } else {
                $healthScore = 90;
                if ($successRate < 95) $healthScore -= 10;
                if ($successRate < 80) $healthScore -= 15;
                if ($successRate < 60) $healthScore -= 20;
                if ($failOrders > 0) $healthScore -= min(15, $failOrders * 3);
                if ($pendingOrders > max(3, (int)floor($successOrders / 2))) $healthScore -= 10;
                if ($todayLimitUsageRate !== null && $todayLimitUsageRate >= 90) $healthScore -= 20;
                elseif ($todayLimitUsageRate !== null && $todayLimitUsageRate >= 75) $healthScore -= 10;
                $healthScore = max(0, min(100, $healthScore));
                if ($healthScore >= 80) $healthLevel = 'healthy';
                elseif ($healthScore >= 60) $healthLevel = 'warning';
                else $healthLevel = 'danger';
            }
        }

        return [
            'id' => (int)($channel['id'] ?? 0),
            'merchant_id' => (int)($channel['merchant_id'] ?? 0),
            'merchant_app_id' => (int)($channel['merchant_app_id'] ?? 0),
            'chan_code' => (string)($channel['chan_code'] ?? ''),
            'chan_name' => (string)($channel['chan_name'] ?? ''),
            'plugin_code' => (string)($channel['plugin_code'] ?? ''),
            'method_id' => (int)($channel['method_id'] ?? 0),
            'status' => $status,
            'sort' => (int)($channel['sort'] ?? 0),
            'daily_limit' => $dailyLimit > 0 ? round($dailyLimit, 2) : 0,
            'daily_cnt' => $dailyCnt > 0 ? $dailyCnt : 0,
            'min_amount' => $channel['min_amount'] === null ? null : round((float)$channel['min_amount'], 2),
            'max_amount' => $channel['max_amount'] === null ? null : round((float)$channel['max_amount'], 2),
            'total_orders' => $totalOrders,
            'success_orders' => $successOrders,
            'pending_orders' => $pendingOrders,
            'fail_orders' => $failOrders,
            'closed_orders' => $closedOrders,
            'today_orders' => $todayOrders,
            'today_success_orders' => $todaySuccessOrders,
            'total_amount' => round((float)($stats['total_amount'] ?? 0), 2),
            'success_amount' => round((float)($stats['success_amount'] ?? 0), 2),
            'today_amount' => round((float)($stats['today_amount'] ?? 0), 2),
            'today_success_amount' => $todaySuccessAmount,
            'last_order_at' => $stats['last_order_at'] ?? null,
            'last_success_at' => $stats['last_success_at'] ?? null,
            'success_rate' => $successRate,
            'today_limit_usage_rate' => $todayLimitUsageRate,
            'health_score' => $healthScore,
            'health_level' => $healthLevel,
        ];
    }

    private function buildMonitorSummary(array $rows): array
    {
        $summary = [
            'total_channels' => count($rows),
            'enabled_channels' => 0,
            'healthy_channels' => 0,
            'warning_channels' => 0,
            'danger_channels' => 0,
            'total_orders' => 0,
            'success_rate' => 0,
            'today_success_amount' => 0,
        ];
        $successOrders = 0;
        foreach ($rows as $row) {
            if ((int)($row['status'] ?? 0) === 1) $summary['enabled_channels']++;
            $level = $row['health_level'] ?? '';
            if ($level === 'healthy') $summary['healthy_channels']++;
            elseif ($level === 'warning') $summary['warning_channels']++;
            elseif ($level === 'danger') $summary['danger_channels']++;
            $summary['total_orders'] += (int)($row['total_orders'] ?? 0);
            $summary['today_success_amount'] = round($summary['today_success_amount'] + (float)($row['today_success_amount'] ?? 0), 2);
            $successOrders += (int)($row['success_orders'] ?? 0);
        }
        if ($summary['total_orders'] > 0) {
            $summary['success_rate'] = round($successOrders / $summary['total_orders'] * 100, 2);
        }
        return $summary;
    }

    private function buildPollingRow(array $monitorRow, ?float $testAmount): array
    {
        $reasons = [];
        $status = (int)($monitorRow['status'] ?? 0);
        $dailyLimit = (float)($monitorRow['daily_limit'] ?? 0);
        $dailyCnt = (int)($monitorRow['daily_cnt'] ?? 0);
        $todaySuccessAmount = (float)($monitorRow['today_success_amount'] ?? 0);
        $todayOrders = (int)($monitorRow['today_orders'] ?? 0);
        $minAmount = $monitorRow['min_amount'];
        $maxAmount = $monitorRow['max_amount'];
        $remainingDailyLimit = $dailyLimit > 0 ? round($dailyLimit - $todaySuccessAmount, 2) : null;
        $remainingDailyCount = $dailyCnt > 0 ? $dailyCnt - $todayOrders : null;
        $routeState = 'ready';

        if ($status !== 1) { $routeState = 'blocked'; $reasons[] = '通道已禁用'; }
        if ($testAmount !== null) {
            if ($minAmount !== null && $testAmount < (float)$minAmount) { $routeState = 'blocked'; $reasons[] = '低于最小支付金额'; }
            if ($maxAmount !== null && (float)$maxAmount > 0 && $testAmount > (float)$maxAmount) { $routeState = 'blocked'; $reasons[] = '超过最大支付金额'; }
        }
        if ($remainingDailyLimit !== null && $remainingDailyLimit <= 0) { $routeState = 'blocked'; $reasons[] = '单日限额已用尽'; }
        if ($remainingDailyCount !== null && $remainingDailyCount <= 0) { $routeState = 'blocked'; $reasons[] = '单日笔数已用尽'; }
        if ($routeState !== 'blocked') {
            if (($monitorRow['health_level'] ?? '') === 'warning' || ($monitorRow['health_level'] ?? '') === 'danger') { $routeState = 'degraded'; $reasons[] = '监控健康度偏低'; }
            if ((int)($monitorRow['total_orders'] ?? 0) === 0) { $routeState = 'degraded'; $reasons[] = '暂无订单样本，建议灰度'; }
            if ((float)($monitorRow['success_rate'] ?? 0) < 80 && (int)($monitorRow['total_orders'] ?? 0) > 0) { $routeState = 'degraded'; $reasons[] = '成功率偏低'; }
            if ((int)($monitorRow['pending_orders'] ?? 0) > max(3, (int)($monitorRow['success_orders'] ?? 0))) { $routeState = 'degraded'; $reasons[] = '待支付订单偏多'; }
        }

        $priorityBonus = max(0, 20 - min(20, (int)($monitorRow['sort'] ?? 0) * 2));
        $sampleBonus = (int)($monitorRow['total_orders'] ?? 0) > 0 ? min(10, (int)floor(((float)($monitorRow['success_rate'] ?? 0)) / 10)) : 5;
        $routeScore = round(max(0, min(100, ((float)($monitorRow['health_score'] ?? 0) * 0.7) + $priorityBonus + $sampleBonus)), 2);
        if ($routeState === 'degraded') $routeScore = max(0, round($routeScore - 15, 2));
        if ($routeState === 'blocked') $routeScore = 0;

        return array_merge($monitorRow, [
            'route_state' => $routeState,
            'route_rank' => 0,
            'route_score' => $routeScore,
            'remaining_daily_limit' => $remainingDailyLimit === null ? null : round(max(0, $remainingDailyLimit), 2),
            'remaining_daily_count' => $remainingDailyCount === null ? null : max(0, $remainingDailyCount),
            'reasons' => array_values(array_unique($reasons)),
        ]);
    }

    private function buildPollingSummary(array $rows): array
    {
        $summary = [
            'total_channels' => count($rows),
            'ready_channels' => 0,
            'degraded_channels' => 0,
            'blocked_channels' => 0,
            'recommended_channel' => null,
            'fallback_chain' => [],
        ];
        foreach ($rows as $row) {
            $state = $row['route_state'] ?? 'blocked';
            if ($state === 'ready') $summary['ready_channels']++;
            elseif ($state === 'degraded') $summary['degraded_channels']++;
            else $summary['blocked_channels']++;
        }
        foreach ($rows as $row) {
            if ($summary['recommended_channel'] === null && ($row['route_state'] ?? '') !== 'blocked') {
                $summary['recommended_channel'] = $row;
                continue;
            }
            if (($row['route_state'] ?? '') !== 'blocked' && count($summary['fallback_chain']) < 5) {
                $summary['fallback_chain'][] = sprintf('%s（%s）', (string)($row['chan_name'] ?? ''), (string)($row['chan_code'] ?? ''));
            }
        }
        if ($summary['recommended_channel'] !== null) {
            $recommendedId = (int)($summary['recommended_channel']['id'] ?? 0);
            if ($recommendedId > 0) {
                $summary['fallback_chain'] = [];
                foreach ($rows as $row) {
                    if ((int)($row['id'] ?? 0) === $recommendedId || ($row['route_state'] ?? '') === 'blocked') continue;
                    $summary['fallback_chain'][] = sprintf('%s（%s）', (string)($row['chan_name'] ?? ''), (string)($row['chan_code'] ?? ''));
                    if (count($summary['fallback_chain']) >= 5) break;
                }
            }
        }
        return $summary;
    }
}
