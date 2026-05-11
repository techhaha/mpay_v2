<?php

namespace app\repository\payment\trade;

use app\common\base\BaseRepository;
use app\common\constant\TradeConstant;
use app\model\payment\PayOrder;

/**
 * 支付单基础查询仓库。
 *
 * 封装支付单号、业务单号、追踪号和商户请求号等常用查询方法。
 */
class PayOrderRepository extends BaseRepository
{
    /**
     * 构造方法。
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct(new PayOrder());
    }

    /**
     * 根据支付单号查询支付单。
     *
     * @param string $payNo 支付单号
     * @param array $columns 字段列表
     * @return PayOrder|null 支付单记录
     */
    public function findByPayNo(string $payNo, array $columns = ['*'])
    {
        return $this->model->newQuery()
            ->where('pay_no', $payNo)
            ->first($columns);
    }

    /**
     * 根据追踪号查询支付单。
     *
     * @param string $traceNo 追踪号
     * @param array $columns 字段列表
     * @return PayOrder|null 支付单记录
     */
    public function findByTraceNo(string $traceNo, array $columns = ['*'])
    {
        return $this->model->newQuery()
            ->where('trace_no', $traceNo)
            ->first($columns);
    }

    /**
     * 根据追踪号查询支付单列表。
     *
     * @param string $traceNo 追踪号
     * @param array $columns 字段列表
     * @return \Illuminate\Database\Eloquent\Collection<int, PayOrder> 支付单列表
     */
    public function listByTraceNo(string $traceNo, array $columns = ['*'])
    {
        return $this->model->newQuery()
            ->where('trace_no', $traceNo)
            ->orderByDesc('attempt_no')
            ->orderByDesc('id')
            ->get($columns);
    }

    /**
     * 根据业务单号查询支付单列表。
     *
     * @param string $bizNo 业务单号
     * @param array $columns 字段列表
     * @return \Illuminate\Database\Eloquent\Collection<int, PayOrder> 支付单列表
     */
    public function listByBizNo(string $bizNo, array $columns = ['*'])
    {
        return $this->model->newQuery()
            ->where('biz_no', $bizNo)
            ->orderByDesc('attempt_no')
            ->orderByDesc('id')
            ->get($columns);
    }

    /**
     * 根据业务单号查询最新支付单。
     *
     * @param string $bizNo 业务单号
     * @param array $columns 字段列表
     * @return PayOrder|null 支付单记录
     */
    public function findLatestByBizNo(string $bizNo, array $columns = ['*'])
    {
        return $this->model->newQuery()
            ->where('biz_no', $bizNo)
            ->orderByDesc('attempt_no')
            ->first($columns);
    }

    /**
     * 根据商户和渠道请求号查询支付单。
     *
     * @param int $merchantId 商户ID
     * @param string $channelRequestNo 渠道Request号
     * @param array $columns 字段列表
     * @return PayOrder|null 支付单记录
     */
    public function findByChannelRequestNo(int $merchantId, string $channelRequestNo, array $columns = ['*'])
    {
        return $this->model->newQuery()
            ->where('merchant_id', $merchantId)
            ->where('channel_request_no', $channelRequestNo)
            ->first($columns);
    }

    /**
     * 根据支付单号加锁查询支付单。
     *
     * @param string $payNo 支付单号
     * @param array $columns 字段列表
     * @return PayOrder|null 支付单记录
     */
    public function findForUpdateByPayNo(string $payNo, array $columns = ['*'])
    {
        return $this->model->newQuery()
            ->where('pay_no', $payNo)
            ->lockForUpdate()
            ->first($columns);
    }

    /**
     * 根据追踪号加锁查询支付单。
     *
     * @param string $traceNo 追踪号
     * @param array $columns 字段列表
     * @return PayOrder|null 支付单记录
     */
    public function findForUpdateByTraceNo(string $traceNo, array $columns = ['*'])
    {
        return $this->model->newQuery()
            ->where('trace_no', $traceNo)
            ->lockForUpdate()
            ->first($columns);
    }

    /**
     * 根据业务单号加锁查询最新支付单。
     *
     * @param string $bizNo 业务单号
     * @param array $columns 字段列表
     * @return PayOrder|null 支付单记录
     */
    public function findLatestForUpdateByBizNo(string $bizNo, array $columns = ['*'])
    {
        return $this->model->newQuery()
            ->where('biz_no', $bizNo)
            ->orderByDesc('attempt_no')
            ->lockForUpdate()
            ->first($columns);
    }

    /**
     * 查询已过期但还未进入终态的支付单。
     *
     * @param string $now 当前时间
     * @param int $limit 限制条数
     * @return \Illuminate\Database\Eloquent\Collection<int, PayOrder> 支付单列表
     */
    public function listExpiredMutable(string $now, int $limit = 100)
    {
        return $this->model->newQuery()
            ->whereIn('status', TradeConstant::orderMutableStatuses())
            ->whereNotNull('expire_at')
            ->where('expire_at', '<=', $now)
            ->orderBy('expire_at')
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->get();
    }

    /**
     * 查询需要主动查单的支付中订单。
     *
     * @param string $before 最早拉起时间
     * @param int $limit 限制条数
     * @return \Illuminate\Database\Eloquent\Collection<int, PayOrder> 支付单列表
     */
    public function listPayingForActiveQuery(string $before, int $limit = 50)
    {
        return $this->model->newQuery()
            ->where('status', TradeConstant::ORDER_STATUS_PAYING)
            ->where('request_at', '<=', $before)
            ->where(function ($query) {
                $query->whereNull('expire_at')
                    ->orWhere('expire_at', '>', date('Y-m-d H:i:s'));
            })
            ->orderBy('request_at')
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->get();
    }

    /**
     * 查询商户最近支付单列表，用于总览展示。
     *
     * @param int $merchantId 商户ID
     * @param int $limit 限制条数
     * @return \Illuminate\Database\Eloquent\Collection<int, PayOrder> 最近支付单列表
     */
    public function recentByMerchantId(int $merchantId, int $limit = 5)
    {
        return $this->model->newQuery()
            ->from('ma_pay_order as po')
            ->leftJoin('ma_payment_type as t', 'po.pay_type_id', '=', 't.id')
            ->leftJoin('ma_payment_channel as c', 'po.channel_id', '=', 'c.id')
            ->where('po.merchant_id', $merchantId)
            ->orderByDesc('po.id')
            ->limit(max(1, $limit))
            ->get([
                'po.pay_no',
                'po.pay_amount',
                'po.status',
                'po.created_at',
                't.name as pay_type_name',
                'c.name as channel_name',
            ]);
    }

    /**
     * 查询网页流水监听需要关注的待支付订单。
     *
     * @param array<int, string> $pluginCodes 插件编码列表
     * @param string $now 当前时间
     * @param int $limit 限制条数
     * @return \Illuminate\Database\Eloquent\Collection<int, PayOrder> 支付单列表
     */
    public function listReceiptWatcherPendingOrders(array $pluginCodes, string $now, int $limit = 500)
    {
        $pluginCodes = array_values(array_filter(array_map(static fn ($code): string => trim((string) $code), $pluginCodes)));
        if ($pluginCodes === []) {
            return $this->model->newCollection();
        }

        return $this->model->newQuery()
            ->from('ma_pay_order as po')
            ->join('ma_payment_channel as c', 'po.channel_id', '=', 'c.id')
            ->leftJoin('ma_payment_type as t', 'po.pay_type_id', '=', 't.id')
            ->whereIn('po.status', TradeConstant::orderMutableStatuses())
            ->whereIn('c.plugin_code', $pluginCodes)
            ->where('c.status', 1)
            ->where(function ($query) use ($now): void {
                $query->whereNull('po.expire_at')
                    ->orWhere('po.expire_at', '>', $now);
            })
            ->orderBy('po.request_at')
            ->orderBy('po.id')
            ->limit(max(1, $limit))
            ->get([
                'po.id',
                'po.pay_no',
                'po.channel_id',
                'po.pay_type_id',
                'po.pay_amount',
                'po.channel_order_no',
                'po.channel_trade_no',
                'po.request_at',
                'po.expire_at',
                'po.created_at',
                'c.plugin_code',
                'c.api_config_id',
                't.code as pay_type',
            ]);
    }

    /**
     * 查询同一收款账号下已占用的识别金额。
     *
     * @param array<int, int> $channelIds 通道ID列表
     * @param string $excludePayNo 排除的支付单号
     * @param string $now 当前时间
     * @return array<int, int> 已占用金额列表，单位分
     */
    public function listUsedReceiptAmounts(array $channelIds, string $excludePayNo, string $now): array
    {
        $channelIds = array_values(array_unique(array_filter(array_map('intval', $channelIds))));
        if ($channelIds === []) {
            return [];
        }

        return $this->model->newQuery()
            ->whereIn('channel_id', $channelIds)
            ->where('pay_no', '<>', $excludePayNo)
            ->whereIn('status', TradeConstant::orderMutableStatuses())
            ->where('expire_at', '>', $now)
            ->lockForUpdate()
            ->pluck('pay_amount')
            ->map(fn ($amount): int => (int) $amount)
            ->all();
    }

    /**
     * 根据第三方流水号匹配支付单。
     *
     * @param array<int, int> $channelIds 通道ID列表
     * @param string $orderNo 第三方流水号
     * @param array $columns 字段列表
     * @return PayOrder|null 支付单
     */
    public function findByReceiptChannelOrder(array $channelIds, string $orderNo, array $columns = ['*']): ?PayOrder
    {
        $channelIds = array_values(array_unique(array_filter(array_map('intval', $channelIds))));
        $orderNo = trim($orderNo);
        if ($channelIds === [] || $orderNo === '') {
            return null;
        }

        return $this->model->newQuery()
            ->whereIn('channel_id', $channelIds)
            ->where(function ($query) use ($orderNo): void {
                $query->where('channel_order_no', $orderNo)
                    ->orWhere('channel_trade_no', $orderNo);
            })
            ->orderByDesc('id')
            ->first($columns);
    }

    /**
     * 根据金额查询同一收款账号下有效待支付订单。
     *
     * @param array<int, int> $channelIds 通道ID列表
     * @param int $amount 金额，单位分
     * @param int $payTypeId 支付方式ID
     * @param string $now 当前时间
     * @param array $columns 字段列表
     * @return \Illuminate\Database\Eloquent\Collection<int, PayOrder> 支付单列表
     */
    public function listMutableReceiptOrdersByAmount(array $channelIds, int $amount, int $payTypeId, string $now, array $columns = ['*'])
    {
        $channelIds = array_values(array_unique(array_filter(array_map('intval', $channelIds))));
        if ($channelIds === []) {
            return $this->model->newCollection();
        }

        $query = $this->model->newQuery()
            ->whereIn('channel_id', $channelIds)
            ->where('pay_amount', $amount)
            ->whereIn('status', TradeConstant::orderMutableStatuses())
            ->where('expire_at', '>', $now);

        if ($payTypeId > 0) {
            $query->where('pay_type_id', $payTypeId);
        }

        return $query->get($columns);
    }

    /**
     * 查询同一收款账号下有效待支付订单，用于备注匹配。
     *
     * @param array<int, int> $channelIds 通道ID列表
     * @param string $now 当前时间
     * @param array $columns 字段列表
     * @return \Illuminate\Database\Eloquent\Collection<int, PayOrder> 支付单列表
     */
    public function listMutableReceiptOrders(array $channelIds, string $now, array $columns = ['*'])
    {
        $channelIds = array_values(array_unique(array_filter(array_map('intval', $channelIds))));
        if ($channelIds === []) {
            return $this->model->newCollection();
        }

        return $this->model->newQuery()
            ->whereIn('channel_id', $channelIds)
            ->whereIn('status', TradeConstant::orderMutableStatuses())
            ->where('expire_at', '>', $now)
            ->get($columns);
    }
}


