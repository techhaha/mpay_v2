<?php

namespace app\service\merchant\portal;

use app\common\base\BaseService;
use app\common\constant\CommonConstant;
use app\common\constant\PaymentPluginTypeConstant;
use app\common\constant\RouteConstant;
use app\repository\payment\config\PaymentChannelRepository;
use app\repository\payment\config\PaymentPollGroupBindRepository;
use app\repository\payment\config\PaymentPollGroupChannelRepository;

/**
 * 商户门户通道查询服务。
 *
 * 负责商户通道列表查询和通道行格式化。
 *
 * @property MerchantPortalSupportService $supportService 支持服务
 * @property PaymentChannelRepository $paymentChannelRepository 支付渠道仓库
 */
class MerchantPortalChannelQueryService extends BaseService
{
    /**
     * 构造方法。
     *
     * @param MerchantPortalSupportService $supportService 支持服务
     * @param PaymentChannelRepository $paymentChannelRepository 支付渠道仓库
     */
    public function __construct(
        protected MerchantPortalSupportService $supportService,
        protected PaymentChannelRepository $paymentChannelRepository,
        protected PaymentPollGroupBindRepository $pollGroupBindRepository,
        protected PaymentPollGroupChannelRepository $pollGroupChannelRepository
    ) {
    }

    /**
     * 查询当前商户的通道列表。
     *
     * @param array $filters 筛选条件
     * @param int $merchantId 商户ID
     * @param int $page 页码
     * @param int $pageSize 每页条数
     * @return array 通道列表数据
     */
    public function myChannels(array $filters, int $merchantId, int $page, int $pageSize): array
    {
        $merchant = $this->supportService->merchantSummary($merchantId);
        $assignedChannelIds = $this->assignedChannelIds((int) ($merchant['merchant_group_id'] ?? 0));

        $query = $this->paymentChannelRepository->query()
            ->from('ma_payment_channel as c')
            ->leftJoin('ma_payment_type as t', 'c.pay_type_id', '=', 't.id')
            ->leftJoin('ma_payment_plugin as p', 'c.plugin_code', '=', 'p.code')
            ->select([
                'c.id',
                'c.merchant_id',
                'c.name',
                'c.split_rate_bp',
                'c.channel_mode',
                'c.pay_type_id',
                'c.plugin_code',
                'c.api_config_id',
                'c.daily_limit_amount',
                'c.daily_limit_count',
                'c.min_amount',
                'c.max_amount',
                'c.remark',
                'c.status',
                'c.sort_no',
                'c.created_at',
                'c.updated_at',
            ])
            ->selectRaw("COALESCE(t.code, '') AS pay_type_code")
            ->selectRaw("COALESCE(t.name, '') AS pay_type_name")
            ->selectRaw('COALESCE(p.plugin_type, 1) AS plugin_type')
            ->where(function ($builder) use ($merchantId, $assignedChannelIds) {
                $builder->where('c.merchant_id', $merchantId);
                if ($assignedChannelIds !== []) {
                    $builder->orWhereIn('c.id', $assignedChannelIds);
                }
            });

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->where(function ($builder) use ($keyword) {
                $builder->where('c.name', 'like', '%' . $keyword . '%')
                    ->orWhere('c.plugin_code', 'like', '%' . $keyword . '%')
                    ->orWhere('t.name', 'like', '%' . $keyword . '%');
            });
        }

        $payTypeId = trim((string) ($filters['pay_type_id'] ?? ''));
        if ($payTypeId !== '') {
            $query->where('c.pay_type_id', (int) $payTypeId);
        }

        $status = trim((string) ($filters['status'] ?? ''));
        if ($status !== '') {
            $query->where('c.status', (int) $status);
        }

        $channelMode = trim((string) ($filters['channel_mode'] ?? ''));
        if ($channelMode !== '') {
            $query->where('c.channel_mode', (int) $channelMode);
        }

        $pluginType = (int) ($filters['plugin_type'] ?? 0);
        if (PaymentPluginTypeConstant::isValid($pluginType)) {
            $query->where('p.plugin_type', $pluginType);
        }

        $paginator = $query
            ->orderBy('c.sort_no')
            ->orderByDesc('c.id')
            ->paginate(max(1, $pageSize), ['*'], 'page', max(1, $page));

        $paginator->getCollection()->transform(function ($row) use ($merchantId, $assignedChannelIds) {
            return $this->decorateChannelRow($row, $merchantId, $assignedChannelIds);
        });

        return [
            'merchant' => $merchant,
            'pay_types' => $this->supportService->enabledPayTypeOptions(),
            'list' => $paginator->items(),
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'size' => $paginator->perPage(),
        ];
    }

    /**
     * 为通道行补充文本字段。
     *
     * @param object $row 通道行
     * @param int $merchantId 当前商户ID
     * @param array<int, int> $assignedChannelIds 系统分配通道ID
     * @return object 处理后的通道行
     */
    private function decorateChannelRow(object $row, int $merchantId, array $assignedChannelIds): object
    {
        $isWritable = (int) $row->merchant_id === $merchantId && (int) $row->channel_mode === RouteConstant::CHANNEL_MODE_SELF;

        $row->channel_mode_text = (string) (RouteConstant::channelModeMap()[(int) $row->channel_mode] ?? '未知');
        $row->status_text = (string) (CommonConstant::statusMap()[(int) $row->status] ?? '未知');
        $row->split_rate_text = $this->formatRate((int) $row->split_rate_bp);
        $row->daily_limit_amount_text = $this->formatAmountOrUnlimited((int) $row->daily_limit_amount);
        $row->daily_limit_count_text = $this->formatCountOrUnlimited((int) $row->daily_limit_count);
        $row->min_amount_text = $this->formatAmountOrUnlimited((int) $row->min_amount);
        $row->max_amount_text = $this->formatAmountOrUnlimited((int) $row->max_amount);
        $row->created_at_text = $this->formatDateTime($row->created_at ?? null);
        $row->updated_at_text = $this->formatDateTime($row->updated_at ?? null);
        $row->plugin_type = (int) ($row->plugin_type ?? PaymentPluginTypeConstant::TYPE_DIRECT);
        $row->plugin_type_text = PaymentPluginTypeConstant::label((int) $row->plugin_type);
        $row->is_writable = $isWritable;
        $row->source_type = $isWritable ? 'merchant' : 'system';
        $row->source_text = $isWritable ? '自建通道' : (in_array((int) $row->id, $assignedChannelIds, true) ? '系统分配' : '系统通道');
        unset($row->cost_rate_bp, $row->cost_rate_text);

        return $row;
    }

    /**
     * 获取当前商户分组路由中已分配的通道 ID。
     *
     * @param int $merchantGroupId 商户分组ID
     * @return array<int, int> 通道ID列表
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
            foreach ($this->pollGroupChannelRepository->listByPollGroupId((int) $pollGroupId, ['channel_id']) as $row) {
                $channelId = (int) ($row->channel_id ?? 0);
                if ($channelId > 0) {
                    $channelIds[] = $channelId;
                }
            }
        }

        return array_values(array_unique($channelIds));
    }
}
