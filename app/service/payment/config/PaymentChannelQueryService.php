<?php

namespace app\service\payment\config;

use app\common\base\BaseService;
use app\common\constant\CommonConstant;
use app\model\payment\PaymentChannel;
use app\repository\payment\config\PaymentChannelRepository;

/**
 * 支付通道查询与选项拼装服务。
 *
 * 负责支付通道列表、详情、下拉选项和路由候选数据的查询拼装。
 *
 * @property PaymentChannelRepository $paymentChannelRepository 支付渠道仓库
 */
class PaymentChannelQueryService extends BaseService
{
    /**
     * 构造方法。
     *
     * @param PaymentChannelRepository $paymentChannelRepository 支付渠道仓库
     * @return void
     */
    public function __construct(
        protected PaymentChannelRepository $paymentChannelRepository
    ) {
    }

    /**
     * 获取启用支付通道选项。
     *
     * @return array<int, array{label: string, value: int}> 启用通道选项
     */
    public function enabledOptions(): array
    {
        return $this->paymentChannelRepository->query()
            ->from('ma_payment_channel as c')
            ->where('c.status', CommonConstant::STATUS_ENABLED)
            ->orderBy('c.sort_no')
            ->orderByDesc('c.id')
            ->get([
                'c.id',
                'c.name',
            ])
            ->map(function (PaymentChannel $channel): array {
                return [
                    'label' => sprintf('%s（%d）', (string) $channel->name, (int) $channel->id),
                    'value' => (int) $channel->id,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * 搜索支付通道选项。
     *
     * @param array $filters 筛选条件
     * @param int $page 页码
     * @param int $pageSize 每页条数
     * @return array{list: array<int, array{label: string, value: int, merchant_id: int, merchant_no: string, merchant_name: string, channel_mode: int, pay_type_id: int, pay_type_name: string, plugin_code: string}>, total: int, page: int, size: int} 通道搜索结果
     */
    public function searchOptions(array $filters = [], int $page = 1, int $pageSize = 20): array
    {
        $query = $this->paymentChannelRepository->query()
            ->from('ma_payment_channel as c')
            ->leftJoin('ma_merchant as m', 'c.merchant_id', '=', 'm.id')
            ->leftJoin('ma_payment_type as t', 'c.pay_type_id', '=', 't.id')
            ->select([
                'c.id',
                'c.name',
                'c.merchant_id',
                'c.channel_mode',
                'c.pay_type_id',
                'c.plugin_code',
                'c.status',
            ])
            ->selectRaw("COALESCE(m.merchant_no, '') AS merchant_no")
            ->selectRaw("COALESCE(m.merchant_name, '') AS merchant_name")
            ->selectRaw("COALESCE(t.name, '') AS pay_type_name");

        $ids = $this->normalizeIds($filters['ids'] ?? []);
        if (!empty($ids)) {
            // 显式传 ID 时，直接按 ID 集合返回，避免再叠加其他筛选条件影响回显。
            $query->whereIn('c.id', $ids);
        } else {
            // 选择器默认只给启用通道，避免把已停用的历史数据混进后台下拉框。
            $query->where('c.status', CommonConstant::STATUS_ENABLED);

            $keyword = trim((string) ($filters['keyword'] ?? ''));
            if ($keyword !== '') {
                // 关键词同时支持通道、插件和商户维度搜索，方便后台快速定位路由节点。
                $query->where(function ($builder) use ($keyword) {
                    $builder->where('c.name', 'like', '%' . $keyword . '%')
                        ->orWhere('c.plugin_code', 'like', '%' . $keyword . '%')
                        ->orWhere('m.merchant_no', 'like', '%' . $keyword . '%')
                        ->orWhere('m.merchant_name', 'like', '%' . $keyword . '%');
                });
            }

            if (($payTypeId = (int) ($filters['pay_type_id'] ?? 0)) > 0) {
                $query->where('c.pay_type_id', $payTypeId);
            }

            if (array_key_exists('merchant_id', $filters) && $filters['merchant_id'] !== '' && $filters['merchant_id'] !== null) {
                $query->where('c.merchant_id', (int) $filters['merchant_id']);
            }

            if (array_key_exists('channel_mode', $filters) && $filters['channel_mode'] !== '' && $filters['channel_mode'] !== null) {
                $query->where('c.channel_mode', (int) $filters['channel_mode']);
            }

            $excludeIds = $this->normalizeIds($filters['exclude_ids'] ?? []);
            if (!empty($excludeIds)) {
                // 编排时经常要排除当前已选项，这里提供反选列表避免重复挂载同一通道。
                $query->whereNotIn('c.id', $excludeIds);
            }
        }

        $paginator = $query
            ->orderBy('c.sort_no')
            ->orderByDesc('c.id')
            ->paginate(max(1, $pageSize), ['*'], 'page', max(1, $page));

        return [
            'list' => collect($paginator->items())
                ->map(function ($channel): array {
                    return [
                        'label' => sprintf('%s（%d）', (string) $channel->name, (int) $channel->id),
                        'value' => (int) $channel->id,
                        'merchant_id' => (int) $channel->merchant_id,
                        'merchant_no' => (string) ($channel->merchant_no ?? ''),
                        'merchant_name' => (string) ($channel->merchant_name ?? ''),
                        'channel_mode' => (int) $channel->channel_mode,
                        'pay_type_id' => (int) $channel->pay_type_id,
                        'pay_type_name' => (string) ($channel->pay_type_name ?? ''),
                        'plugin_code' => (string) $channel->plugin_code,
                    ];
                })
                ->values()
                ->all(),
            'total' => (int) $paginator->total(),
            'page' => (int) $paginator->currentPage(),
            'size' => (int) $paginator->perPage(),
        ];
    }

    /**
     * 获取支付通道路由候选选项。
     *
     * @param array $filters 筛选条件
     * @return array<int, array{label: string, value: int, merchant_id: int, channel_mode: int, pay_type_id: int, plugin_code: string, pay_type_name: string}> 路由候选选项
     */
    public function routeOptions(array $filters = []): array
    {
        $query = $this->paymentChannelRepository->query()
            ->from('ma_payment_channel as c')
            ->leftJoin('ma_payment_type as t', 'c.pay_type_id', '=', 't.id')
            ->where('c.status', CommonConstant::STATUS_ENABLED)
            ->select([
                'c.id',
                'c.name',
                'c.merchant_id',
                'c.channel_mode',
                'c.pay_type_id',
                'c.plugin_code',
            ])
            ->selectRaw("COALESCE(t.name, '') AS pay_type_name");

        if (($payTypeId = (int) ($filters['pay_type_id'] ?? 0)) > 0) {
            $query->where('c.pay_type_id', $payTypeId);
        }

        if (array_key_exists('merchant_id', $filters) && $filters['merchant_id'] !== '') {
            // 路由预览/编排时会按商户分组筛选通道，这里直接用商户 ID 限定范围。
            $query->where('c.merchant_id', (int) $filters['merchant_id']);
        }

        return $query
            ->orderBy('c.sort_no')
            ->orderByDesc('c.id')
            ->get()
            ->map(function (PaymentChannel $channel): array {
                return [
                    'label' => sprintf('%s（%d）', (string) $channel->name, (int) $channel->id),
                    'value' => (int) $channel->id,
                    'merchant_id' => (int) $channel->merchant_id,
                    'channel_mode' => (int) $channel->channel_mode,
                    'pay_type_id' => (int) $channel->pay_type_id,
                    'plugin_code' => (string) $channel->plugin_code,
                    'pay_type_name' => (string) ($channel->pay_type_name ?? ''),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * 分页查询支付通道。
     *
     * @param array $filters 筛选条件
     * @param int $page 页码
     * @param int $pageSize 每页条数
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator 分页结果
     */
    public function paginate(array $filters = [], int $page = 1, int $pageSize = 10)
    {
        $query = $this->paymentChannelRepository->query()
            ->from('ma_payment_channel as c')
            ->leftJoin('ma_merchant as m', 'c.merchant_id', '=', 'm.id')
            ->select([
                'c.*',
            ])
            ->selectRaw("COALESCE(m.merchant_no, '') AS merchant_no")
            ->selectRaw("COALESCE(m.merchant_name, '') AS merchant_name");

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            // 列表页的搜索同时覆盖通道名、插件编码和商户信息，便于运营一次性查到整条链路。
            $query->where(function ($builder) use ($keyword) {
                $builder->where('c.name', 'like', '%' . $keyword . '%')
                    ->orWhere('c.plugin_code', 'like', '%' . $keyword . '%')
                    ->orWhere('m.merchant_no', 'like', '%' . $keyword . '%')
                    ->orWhere('m.merchant_name', 'like', '%' . $keyword . '%');
            });
        }

        if (array_key_exists('merchant_id', $filters) && $filters['merchant_id'] !== '') {
            $query->where('c.merchant_id', (int) $filters['merchant_id']);
        }

        if (($payTypeId = (int) ($filters['pay_type_id'] ?? 0)) > 0) {
            $query->where('c.pay_type_id', $payTypeId);
        }

        $pluginCode = trim((string) ($filters['plugin_code'] ?? ''));
        if ($pluginCode !== '') {
            $query->where('c.plugin_code', 'like', '%' . $pluginCode . '%');
        }

        if (array_key_exists('channel_mode', $filters) && $filters['channel_mode'] !== '') {
            $query->where('c.channel_mode', (int) $filters['channel_mode']);
        }

        if (array_key_exists('status', $filters) && $filters['status'] !== '') {
            $query->where('c.status', (int) $filters['status']);
        }

        return $query
            ->orderBy('c.sort_no')
            ->orderByDesc('c.id')
            ->paginate(max(1, $pageSize), ['*'], 'page', max(1, $page));
    }

    /**
     * 按 ID 查询支付通道。
     *
     * @param int $id 支付通道ID
     * @return PaymentChannel|null 支付通道模型
     */
    public function findById(int $id): ?PaymentChannel
    {
        return $this->paymentChannelRepository->find($id);
    }

    /**
     * 归一化 ID 列表。
     *
     * @param array|string|int $ids 通道ID或ID列表
     * @return array<int, int> ID 列表
     */
    private function normalizeIds(array|string|int $ids): array
    {
        if (is_string($ids)) {
            $ids = array_filter(array_map('trim', explode(',', $ids)));
        } elseif (!is_array($ids)) {
            $ids = [$ids];
        }

        // 下拉/搜索参数有时是字符串、有时是数组，统一压成正整数列表后再查询。
        return array_values(array_filter(array_map(static fn ($id) => (int) $id, $ids), static fn ($id) => $id > 0));
    }
}


