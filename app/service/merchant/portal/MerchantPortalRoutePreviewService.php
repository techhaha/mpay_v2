<?php

namespace app\service\merchant\portal;

use app\common\base\BaseService;
use app\common\constant\CommonConstant;
use app\common\constant\RouteConstant;
use app\common\util\FormatHelper;
use app\service\payment\runtime\PaymentRouteService;
use Throwable;

/**
 * 商户门户路由解析服务。
 *
 * 负责根据商户分组、支付方式和金额解析路由结果。
 *
 * @property MerchantPortalSupportService $supportService 支持服务
 * @property PaymentRouteService $paymentRouteService 支付路由服务
 */
class MerchantPortalRoutePreviewService extends BaseService
{
    /**
     * 构造方法。
     *
     * @param MerchantPortalSupportService $supportService 支持服务
     * @param PaymentRouteService $paymentRouteService 支付路由服务
     */
    public function __construct(
        protected MerchantPortalSupportService $supportService,
        protected PaymentRouteService $paymentRouteService
    ) {
    }

    /**
     * 获取当前商户的路由解析结果。
     *
     * @param int $merchantId 商户ID
     * @param int $payTypeId 支付类型ID
     * @param int $payAmount 支付金额
     * @param string $statDate 统计日期
     * @return array 路由解析数据
     */
    public function routePreview(int $merchantId, int $payTypeId, int $payAmount, string $statDate = ''): array
    {
        // 先拿商户摘要，后面即使路由解析失败，也还能返回基础商户信息给前端展示。
        $merchant = $this->supportService->merchantSummary($merchantId);
        $statDate = trim($statDate) !== '' ? trim($statDate) : FormatHelper::timestamp(time(), 'Y-m-d');

        // 先组一个可直接渲染的基础响应结构，失败时只需要改 reason 和可用状态。
        $response = [
            'merchant' => $merchant,
            'pay_types' => $this->supportService->enabledPayTypeOptions(),
            'pay_type_id' => $payTypeId,
            'pay_amount' => $payAmount,
            'pay_amount_text' => $this->formatAmount($payAmount),
            'stat_date' => $statDate,
            'available' => false,
            'reason' => '请选择支付方式和金额后解析路由',
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
            $response['reason'] = '当前商户未配置商户分组，无法解析路由';
            return $response;
        }

        try {
            // 只有基础条件满足时，才进入完整的路由解析流程。
            $resolved = $this->paymentRouteService->resolveByMerchantGroup(
                (int) $merchant['merchant_group_id'],
                $payTypeId,
                $payAmount,
                ['stat_date' => $statDate]
            );

            $response['available'] = true;
            $response['reason'] = '路由解析成功';
            // 把模型和仓库返回的对象统一整理成前端可直接展示的数组结构。
            $response['bind'] = $this->normalizeBind($resolved['bind'] ?? null);
            $response['poll_group'] = $this->normalizePollGroup($resolved['poll_group'] ?? null);
            $response['selected_channel'] = $this->normalizePreviewCandidate($resolved['selected_channel'] ?? null);

            $response['candidates'] = array_values(array_map(
                fn (array $item) => $this->normalizePreviewCandidate($item),
                (array) ($resolved['candidates'] ?? [])
            ));
        } catch (Throwable $e) {
            // 解析异常只影响路由结果，不影响基础信息展示，因此这里只回填失败原因。
            $response['reason'] = $e->getMessage();
        }

        return $response;
    }

    /**
     * 标准化商户分组与支付方式绑定数据。
     *
     * @param array|object|null $bind 绑定数据
     * @return array|null 标准化结果
     */
    private function normalizeBind(array|object|null $bind): ?array
    {
        $data = $this->normalizeModel($bind);
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
            'created_at' => $this->formatDateTime($data['created_at'] ?? null),
            'updated_at' => $this->formatDateTime($data['updated_at'] ?? null),
        ];
    }

    /**
     * 标准化轮询分组数据。
     *
     * @param array|object|null $pollGroup 轮询分组
     * @return array|null 标准化结果
     */
    private function normalizePollGroup(array|object|null $pollGroup): ?array
    {
        $data = $this->normalizeModel($pollGroup);
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
            'created_at' => $this->formatDateTime($data['created_at'] ?? null),
            'updated_at' => $this->formatDateTime($data['updated_at'] ?? null),
        ];
    }

    /**
     * 标准化路由候选数据。
     *
     * @param array|object|null $candidate 候选数据
     * @return array|null 标准化结果
     */
    private function normalizePreviewCandidate(array|object|null $candidate): ?array
    {
        $data = is_array($candidate) ? $candidate : $this->normalizeModel($candidate);
        if ($data === null) {
            return null;
        }

        // 一个候选项会同时带出通道、轮询关系和日统计三层数据，后面统一整理成展示结构。
        $channel = $this->normalizeModel($data['channel'] ?? null) ?? [];
        $pollGroupChannel = $this->normalizeModel($data['poll_group_channel'] ?? null) ?? [];
        $dailyStat = $this->normalizeModel($data['daily_stat'] ?? null) ?? [];

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
            // 统计指标同时返回原始值和格式化文本，前端可以直接展示而不再二次换算。
            'health_score' => (int) ($dailyStat['health_score'] ?? 0),
            'health_score_text' => (string) ($dailyStat['health_score'] ?? 0),
            'success_rate_bp' => (int) ($dailyStat['success_rate_bp'] ?? 0),
            'success_rate_text' => $this->formatRate((int) ($dailyStat['success_rate_bp'] ?? 0)),
            'avg_latency_ms' => (int) ($dailyStat['avg_latency_ms'] ?? 0),
            'avg_latency_text' => $this->formatLatency((int) ($dailyStat['avg_latency_ms'] ?? 0)),
            'split_rate_bp' => (int) ($channel['split_rate_bp'] ?? 0),
            'split_rate_text' => $this->formatRate((int) ($channel['split_rate_bp'] ?? 0)),
            'cost_rate_bp' => (int) ($channel['cost_rate_bp'] ?? 0),
            'cost_rate_text' => $this->formatRate((int) ($channel['cost_rate_bp'] ?? 0)),
            'daily_limit_amount' => (int) ($channel['daily_limit_amount'] ?? 0),
            'daily_limit_amount_text' => $this->formatAmountOrUnlimited((int) ($channel['daily_limit_amount'] ?? 0)),
            'daily_limit_count' => (int) ($channel['daily_limit_count'] ?? 0),
            'daily_limit_count_text' => $this->formatCountOrUnlimited((int) ($channel['daily_limit_count'] ?? 0)),
            'min_amount' => (int) ($channel['min_amount'] ?? 0),
            'min_amount_text' => $this->formatAmountOrUnlimited((int) ($channel['min_amount'] ?? 0)),
            'max_amount' => (int) ($channel['max_amount'] ?? 0),
            'max_amount_text' => $this->formatAmountOrUnlimited((int) ($channel['max_amount'] ?? 0)),
            'remark' => (string) ($channel['remark'] ?? ''),
            'created_at' => $this->formatDateTime($channel['created_at'] ?? null),
            'updated_at' => $this->formatDateTime($channel['updated_at'] ?? null),
        ];
    }
}
