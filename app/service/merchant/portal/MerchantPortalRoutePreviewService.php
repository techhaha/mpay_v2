<?php

namespace app\service\merchant\portal;

use app\common\base\BaseService;
use app\common\constant\CommonConstant;
use app\common\constant\RouteConstant;
use app\common\util\FormatHelper;
use app\service\payment\runtime\PaymentRouteService;
use Throwable;

/**
 * 商户门户路由预览服务。
 */
class MerchantPortalRoutePreviewService extends BaseService
{
    public function __construct(
        protected MerchantPortalSupportService $supportService,
        protected PaymentRouteService $paymentRouteService
    ) {
    }

    /**
     * 预览当前商户的路由选择结果。
     */
    public function routePreview(int $merchantId, int $payTypeId, int $payAmount, string $statDate = ''): array
    {
        $merchant = $this->supportService->merchantSummary($merchantId);
        $statDate = trim($statDate) !== '' ? trim($statDate) : FormatHelper::timestamp(time(), 'Y-m-d');

        $response = [
            'merchant' => $merchant,
            'pay_types' => $this->supportService->enabledPayTypeOptions(),
            'pay_type_id' => $payTypeId,
            'pay_amount' => $payAmount,
            'pay_amount_text' => $this->supportService->formatAmount($payAmount),
            'stat_date' => $statDate,
            'available' => false,
            'reason' => '请选择支付方式和金额后预览路由',
            'merchant_group_id' => (int) ($merchant['merchant_group_id'] ?? 0),
            'merchant_group_name' => (string) ($merchant['merchant_group_name'] ?? ''),
            'bind' => null,
            'poll_group' => null,
            'selected_channel' => null,
            'candidates' => [],
        ];

        if ($payTypeId <= 0 || $payAmount <= 0) {
            return $response;
        }

        if ((int) $merchant['merchant_group_id'] <= 0) {
            $response['reason'] = '当前商户未配置商户分组，无法预览路由';
            return $response;
        }

        try {
            $resolved = $this->paymentRouteService->resolveByMerchantGroup(
                (int) $merchant['merchant_group_id'],
                $payTypeId,
                $payAmount,
                ['stat_date' => $statDate]
            );

            $response['available'] = true;
            $response['reason'] = '路由预览成功';
            $response['bind'] = $this->normalizeBind($resolved['bind'] ?? null);
            $response['poll_group'] = $this->normalizePollGroup($resolved['poll_group'] ?? null);
            $response['selected_channel'] = $this->normalizePreviewCandidate($resolved['selected_channel'] ?? null);

            $response['candidates'] = array_values(array_map(
                fn (array $item) => $this->normalizePreviewCandidate($item),
                (array) ($resolved['candidates'] ?? [])
            ));
        } catch (Throwable $e) {
            $response['reason'] = $e->getMessage() !== '' ? $e->getMessage() : '路由预览失败';
        }

        return $response;
    }

    private function normalizeBind(mixed $bind): ?array
    {
        $data = $this->supportService->normalizeModel($bind);
        if ($data === null) {
            return null;
        }

        $status = (int) ($data['status'] ?? 0);

        return [
            'merchant_group_id' => (int) ($data['merchant_group_id'] ?? 0),
            'pay_type_id' => (int) ($data['pay_type_id'] ?? 0),
            'poll_group_id' => (int) ($data['poll_group_id'] ?? 0),
            'status' => $status,
            'status_text' => (string) (CommonConstant::statusMap()[$status] ?? '未知'),
            'remark' => (string) ($data['remark'] ?? ''),
            'created_at' => $this->supportService->formatDateTime($data['created_at'] ?? null),
            'updated_at' => $this->supportService->formatDateTime($data['updated_at'] ?? null),
        ];
    }

    private function normalizePollGroup(mixed $pollGroup): ?array
    {
        $data = $this->supportService->normalizeModel($pollGroup);
        if ($data === null) {
            return null;
        }

        $routeMode = (int) ($data['route_mode'] ?? 0);
        $status = (int) ($data['status'] ?? 0);

        return [
            'id' => (int) ($data['id'] ?? 0),
            'group_name' => (string) ($data['group_name'] ?? ''),
            'pay_type_id' => (int) ($data['pay_type_id'] ?? 0),
            'route_mode' => $routeMode,
            'route_mode_text' => (string) (RouteConstant::routeModeMap()[$routeMode] ?? '未知'),
            'status' => $status,
            'status_text' => (string) (CommonConstant::statusMap()[$status] ?? '未知'),
            'remark' => (string) ($data['remark'] ?? ''),
            'created_at' => $this->supportService->formatDateTime($data['created_at'] ?? null),
            'updated_at' => $this->supportService->formatDateTime($data['updated_at'] ?? null),
        ];
    }

    private function normalizePreviewCandidate(mixed $candidate): ?array
    {
        $data = is_array($candidate) ? $candidate : $this->supportService->normalizeModel($candidate);
        if ($data === null) {
            return null;
        }

        $channel = $this->supportService->normalizeModel($data['channel'] ?? null) ?? [];
        $pollGroupChannel = $this->supportService->normalizeModel($data['poll_group_channel'] ?? null) ?? [];
        $dailyStat = $this->supportService->normalizeModel($data['daily_stat'] ?? null) ?? [];

        $channelMode = (int) ($channel['channel_mode'] ?? 0);
        $status = (int) ($channel['status'] ?? 0);
        $payTypeId = (int) ($channel['pay_type_id'] ?? 0);

        return [
            'channel_id' => (int) ($channel['id'] ?? 0),
            'channel_name' => (string) ($channel['name'] ?? ''),
            'pay_type_id' => $payTypeId,
            'pay_type_name' => $this->supportService->paymentTypeName($payTypeId),
            'channel_mode' => $channelMode,
            'channel_mode_text' => (string) (RouteConstant::channelModeMap()[$channelMode] ?? '未知'),
            'status' => $status,
            'status_text' => (string) (CommonConstant::statusMap()[$status] ?? '未知'),
            'plugin_code' => (string) ($channel['plugin_code'] ?? ''),
            'sort_no' => (int) ($pollGroupChannel['sort_no'] ?? 0),
            'weight' => (int) ($pollGroupChannel['weight'] ?? 0),
            'is_default' => (int) ($pollGroupChannel['is_default'] ?? 0),
            'health_score' => (int) ($dailyStat['health_score'] ?? 0),
            'health_score_text' => (string) ($dailyStat['health_score'] ?? 0),
            'success_rate_bp' => (int) ($dailyStat['success_rate_bp'] ?? 0),
            'success_rate_text' => $this->supportService->formatRate((int) ($dailyStat['success_rate_bp'] ?? 0)),
            'avg_latency_ms' => (int) ($dailyStat['avg_latency_ms'] ?? 0),
            'avg_latency_text' => $this->supportService->formatLatency((int) ($dailyStat['avg_latency_ms'] ?? 0)),
            'split_rate_bp' => (int) ($channel['split_rate_bp'] ?? 0),
            'split_rate_text' => $this->supportService->formatRate((int) ($channel['split_rate_bp'] ?? 0)),
            'cost_rate_bp' => (int) ($channel['cost_rate_bp'] ?? 0),
            'cost_rate_text' => $this->supportService->formatRate((int) ($channel['cost_rate_bp'] ?? 0)),
            'daily_limit_amount' => (int) ($channel['daily_limit_amount'] ?? 0),
            'daily_limit_amount_text' => $this->supportService->formatAmountOrUnlimited((int) ($channel['daily_limit_amount'] ?? 0)),
            'daily_limit_count' => (int) ($channel['daily_limit_count'] ?? 0),
            'daily_limit_count_text' => $this->supportService->formatCountOrUnlimited((int) ($channel['daily_limit_count'] ?? 0)),
            'min_amount' => (int) ($channel['min_amount'] ?? 0),
            'min_amount_text' => $this->supportService->formatAmountOrUnlimited((int) ($channel['min_amount'] ?? 0)),
            'max_amount' => (int) ($channel['max_amount'] ?? 0),
            'max_amount_text' => $this->supportService->formatAmountOrUnlimited((int) ($channel['max_amount'] ?? 0)),
            'remark' => (string) ($channel['remark'] ?? ''),
            'created_at' => $this->supportService->formatDateTime($channel['created_at'] ?? null),
            'updated_at' => $this->supportService->formatDateTime($channel['updated_at'] ?? null),
        ];
    }
}
