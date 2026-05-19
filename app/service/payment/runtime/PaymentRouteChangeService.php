<?php

namespace app\service\payment\runtime;

use app\common\base\BaseService;
use app\repository\payment\config\PaymentChannelRepository;
use app\repository\payment\config\PaymentPollGroupBindRepository;
use app\repository\payment\config\PaymentPollGroupChannelRepository;
use app\repository\payment\config\PaymentPollGroupRepository;

/**
 * 路由配置变更视图服务。
 *
 * 现阶段不引入完整审计表，先基于路由相关配置表的 created_at/updated_at 提供最近变更入口。
 */
class PaymentRouteChangeService extends BaseService
{
    public function __construct(
        protected PaymentPollGroupRepository $pollGroupRepository,
        protected PaymentPollGroupBindRepository $bindRepository,
        protected PaymentPollGroupChannelRepository $pollGroupChannelRepository,
        protected PaymentChannelRepository $channelRepository
    ) {
    }

    /**
     * 查询最近路由相关配置变更。
     *
     * @param array<string, mixed> $filters 筛选条件
     * @param int $limit 返回条数
     * @return array<int, array<string, mixed>> 最近变更
     */
    public function recent(array $filters = [], int $limit = 20): array
    {
        $limit = min(100, max(1, $limit));
        $payTypeId = (int) ($filters['pay_type_id'] ?? 0);
        $merchantGroupId = (int) ($filters['merchant_group_id'] ?? 0);

        $rows = array_merge(
            $this->recentPollGroups($payTypeId, $limit),
            $this->recentBinds($merchantGroupId, $payTypeId, $limit),
            $this->recentPollGroupChannels($payTypeId, $limit),
            $this->recentChannels($payTypeId, $limit)
        );

        usort($rows, static function (array $left, array $right) {
            return strcmp((string) ($right['changed_at'] ?? ''), (string) ($left['changed_at'] ?? ''));
        });

        return array_slice($rows, 0, $limit);
    }

    /**
     * 最近轮询组变更。
     */
    private function recentPollGroups(int $payTypeId, int $limit): array
    {
        $query = $this->pollGroupRepository->query()
            ->from('ma_payment_poll_group as pg')
            ->leftJoin('ma_payment_type as t', 't.id', '=', 'pg.pay_type_id')
            ->select(['pg.id', 'pg.group_name', 'pg.pay_type_id', 'pg.route_mode', 'pg.status', 'pg.created_at', 'pg.updated_at'])
            ->selectRaw("COALESCE(t.name, '') AS pay_type_name");

        if ($payTypeId > 0) {
            $query->where('pg.pay_type_id', $payTypeId);
        }

        return $query->orderByDesc('pg.updated_at')->limit($limit)->get()->map(function ($row) {
            return $this->formatRow('轮询组', (int) $row->id, (string) $row->group_name, [
                '支付方式' => (string) $row->pay_type_name,
                '路由模式' => (string) $row->route_mode,
                '状态' => (string) $row->status,
            ], $row->created_at, $row->updated_at);
        })->all();
    }

    /**
     * 最近商户分组绑定变更。
     */
    private function recentBinds(int $merchantGroupId, int $payTypeId, int $limit): array
    {
        $query = $this->bindRepository->query()
            ->from('ma_payment_poll_group_bind as b')
            ->leftJoin('ma_merchant_group as g', 'g.id', '=', 'b.merchant_group_id')
            ->leftJoin('ma_payment_type as t', 't.id', '=', 'b.pay_type_id')
            ->leftJoin('ma_payment_poll_group as pg', 'pg.id', '=', 'b.poll_group_id')
            ->select(['b.id', 'b.merchant_group_id', 'b.pay_type_id', 'b.poll_group_id', 'b.status', 'b.created_at', 'b.updated_at'])
            ->selectRaw("COALESCE(g.group_name, '') AS merchant_group_name")
            ->selectRaw("COALESCE(t.name, '') AS pay_type_name")
            ->selectRaw("COALESCE(pg.group_name, '') AS poll_group_name");

        if ($merchantGroupId > 0) {
            $query->where('b.merchant_group_id', $merchantGroupId);
        }
        if ($payTypeId > 0) {
            $query->where('b.pay_type_id', $payTypeId);
        }

        return $query->orderByDesc('b.updated_at')->limit($limit)->get()->map(function ($row) {
            return $this->formatRow('分组绑定', (int) $row->id, (string) $row->merchant_group_name, [
                '支付方式' => (string) $row->pay_type_name,
                '轮询组' => (string) $row->poll_group_name,
                '状态' => (string) $row->status,
            ], $row->created_at, $row->updated_at);
        })->all();
    }

    /**
     * 最近轮询组通道变更。
     */
    private function recentPollGroupChannels(int $payTypeId, int $limit): array
    {
        $query = $this->pollGroupChannelRepository->query()
            ->from('ma_payment_poll_group_channel as pgc')
            ->leftJoin('ma_payment_poll_group as pg', 'pg.id', '=', 'pgc.poll_group_id')
            ->leftJoin('ma_payment_channel as c', 'c.id', '=', 'pgc.channel_id')
            ->select(['pgc.id', 'pgc.poll_group_id', 'pgc.channel_id', 'pgc.sort_no', 'pgc.weight', 'pgc.is_default', 'pgc.status', 'pgc.created_at', 'pgc.updated_at'])
            ->selectRaw("COALESCE(pg.group_name, '') AS poll_group_name")
            ->selectRaw("COALESCE(c.name, '') AS channel_name");

        if ($payTypeId > 0) {
            $query->where('pg.pay_type_id', $payTypeId);
        }

        return $query->orderByDesc('pgc.updated_at')->limit($limit)->get()->map(function ($row) {
            return $this->formatRow('通道编排', (int) $row->id, (string) $row->channel_name, [
                '轮询组' => (string) $row->poll_group_name,
                '排序' => (string) $row->sort_no,
                '权重' => (string) $row->weight,
                '默认' => (int) $row->is_default === 1 ? '是' : '否',
                '状态' => (string) $row->status,
            ], $row->created_at, $row->updated_at);
        })->all();
    }

    /**
     * 最近通道配置变更。
     */
    private function recentChannels(int $payTypeId, int $limit): array
    {
        $query = $this->channelRepository->query()
            ->from('ma_payment_channel as c')
            ->leftJoin('ma_payment_type as t', 't.id', '=', 'c.pay_type_id')
            ->select(['c.id', 'c.name', 'c.pay_type_id', 'c.plugin_code', 'c.status', 'c.created_at', 'c.updated_at'])
            ->selectRaw("COALESCE(t.name, '') AS pay_type_name");

        if ($payTypeId > 0) {
            $query->where('c.pay_type_id', $payTypeId);
        }

        return $query->orderByDesc('c.updated_at')->limit($limit)->get()->map(function ($row) {
            return $this->formatRow('支付通道', (int) $row->id, (string) $row->name, [
                '支付方式' => (string) $row->pay_type_name,
                '插件' => (string) $row->plugin_code,
                '状态' => (string) $row->status,
            ], $row->created_at, $row->updated_at);
        })->all();
    }

    /**
     * 格式化变更行。
     */
    private function formatRow(string $objectType, int $objectId, string $objectName, array $summary, mixed $createdAt, mixed $updatedAt): array
    {
        $createdAtText = $this->formatDateTime($createdAt);
        $updatedAtText = $this->formatDateTime($updatedAt);

        return [
            'object_type' => $objectType,
            'object_id' => $objectId,
            'object_name' => $objectName,
            'change_type' => $createdAtText === $updatedAtText ? '创建' : '更新',
            'summary' => $summary,
            'summary_text' => implode('；', array_map(
                static fn ($key, $value) => $key . '：' . $value,
                array_keys($summary),
                $summary
            )),
            'changed_at' => (string) ($updatedAt ?? $createdAt ?? ''),
            'changed_at_text' => $updatedAtText ?: $createdAtText,
        ];
    }
}
