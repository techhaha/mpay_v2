<?php

namespace app\services;

use app\common\base\BaseService;

class ChannelRoutePolicyService extends BaseService
{
    private const CONFIG_KEY = 'channel_route_policies';

    public function __construct(
        protected SystemConfigService $configService
    ) {
    }

    public function list(): array
    {
        $raw = $this->configService->getValue(self::CONFIG_KEY, '[]');

        if (is_array($raw)) {
            $policies = $raw;
        } else {
            $decoded = json_decode((string)$raw, true);
            $policies = is_array($decoded) ? $decoded : [];
        }

        usort($policies, function (array $left, array $right) {
            return strcmp((string)($right['updated_at'] ?? ''), (string)($left['updated_at'] ?? ''));
        });

        return $policies;
    }

    public function save(array $policyData): array
    {
        $policies = $this->list();
        $id = trim((string)($policyData['id'] ?? ''));
        $now = date('Y-m-d H:i:s');

        $stored = [
            'id' => $id !== '' ? $id : $this->generateId(),
            'policy_name' => trim((string)($policyData['policy_name'] ?? '')),
            'merchant_id' => (int)($policyData['merchant_id'] ?? 0),
            'merchant_app_id' => (int)($policyData['merchant_app_id'] ?? 0),
            'method_code' => trim((string)($policyData['method_code'] ?? '')),
            'plugin_code' => trim((string)($policyData['plugin_code'] ?? '')),
            'route_mode' => trim((string)($policyData['route_mode'] ?? 'priority')),
            'status' => (int)($policyData['status'] ?? 1),
            'circuit_breaker_threshold' => max(0, min(100, (int)($policyData['circuit_breaker_threshold'] ?? 50))),
            'failover_cooldown' => max(0, (int)($policyData['failover_cooldown'] ?? 10)),
            'remark' => trim((string)($policyData['remark'] ?? '')),
            'items' => array_values($policyData['items'] ?? []),
            'updated_at' => $now,
        ];

        $found = false;
        foreach ($policies as &$policy) {
            if (($policy['id'] ?? '') !== $stored['id']) {
                continue;
            }

            $stored['created_at'] = $policy['created_at'] ?? $now;
            $policy = $stored;
            $found = true;
            break;
        }
        unset($policy);

        if (!$found) {
            $stored['created_at'] = $now;
            $policies[] = $stored;
        }

        $this->configService->setValue(self::CONFIG_KEY, $policies);

        return $stored;
    }

    public function delete(string $id): bool
    {
        $id = trim($id);
        if ($id === '') {
            return false;
        }

        $policies = $this->list();
        $filtered = array_values(array_filter($policies, function (array $policy) use ($id) {
            return ($policy['id'] ?? '') !== $id;
        }));

        if (count($filtered) === count($policies)) {
            return false;
        }

        $this->configService->setValue(self::CONFIG_KEY, $filtered);
        return true;
    }

    private function generateId(): string
    {
        return 'rp_' . date('YmdHis') . mt_rand(1000, 9999);
    }
}
