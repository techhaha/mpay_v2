<?php

namespace app\service\merchant\portal;

use app\common\base\BaseService;
use app\common\constant\CommonConstant;
use app\common\constant\RouteConstant;
use app\repository\payment\config\PaymentChannelRepository;

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
        protected PaymentChannelRepository $paymentChannelRepository
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
        $query = $this->paymentChannelRepository->query()
            ->from('ma_payment_channel as c')
            ->leftJoin('ma_payment_type as t', 'c.pay_type_id', '=', 't.id')
            ->select([
                'c.id',
                'c.merchant_id',
                'c.name',
                'c.split_rate_bp',
                'c.cost_rate_bp',
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
            ->where('c.merchant_id', $merchantId);

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

        $paginator = $query
            ->orderBy('c.sort_no')
            ->orderByDesc('c.id')
            ->paginate(max(1, $pageSize), ['*'], 'page', max(1, $page));

        $paginator->getCollection()->transform(function ($row) {
            return $this->decorateChannelRow($row);
        });

        return [
            'merchant' => $this->supportService->merchantSummary($merchantId),
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
     * @return object 处理后的通道行
     */
    private function decorateChannelRow(object $row): object
    {
        $row->channel_mode_text = (string) (RouteConstant::channelModeMap()[(int) $row->channel_mode] ?? '未知');
        $row->status_text = (string) (CommonConstant::statusMap()[(int) $row->status] ?? '未知');
        $row->split_rate_text = $this->formatRate((int) $row->split_rate_bp);
        $row->cost_rate_text = $this->formatRate((int) $row->cost_rate_bp);
        $row->daily_limit_amount_text = $this->formatAmountOrUnlimited((int) $row->daily_limit_amount);
        $row->daily_limit_count_text = $this->formatCountOrUnlimited((int) $row->daily_limit_count);
        $row->min_amount_text = $this->formatAmountOrUnlimited((int) $row->min_amount);
        $row->max_amount_text = $this->formatAmountOrUnlimited((int) $row->max_amount);
        $row->created_at_text = $this->formatDateTime($row->created_at ?? null);
        $row->updated_at_text = $this->formatDateTime($row->updated_at ?? null);

        return $row;
    }
}


