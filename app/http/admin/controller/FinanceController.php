<?php

namespace app\http\admin\controller;

use app\common\base\BaseController;
use app\repositories\PaymentMethodRepository;
use support\Db;
use support\Request;

class FinanceController extends BaseController
{
    public function __construct(
        protected PaymentMethodRepository $methodRepository,
    ) {
    }

    public function reconciliation(Request $request)
    {
        $page = (int)$request->get('page', 1);
        $pageSize = (int)$request->get('page_size', 10);
        $filters = $this->buildFilters($request);
        $baseQuery = $this->buildOrderQuery($filters);

        $summaryRow = (clone $baseQuery)
            ->selectRaw(
                'COUNT(*) AS total_orders,
                SUM(CASE WHEN o.status = 1 THEN 1 ELSE 0 END) AS success_orders,
                SUM(CASE WHEN o.status = 0 THEN 1 ELSE 0 END) AS pending_orders,
                SUM(CASE WHEN o.notify_stat = 0 THEN 1 ELSE 0 END) AS notify_pending_orders,
                COALESCE(SUM(o.amount), 0) AS total_amount,
                COALESCE(SUM(o.fee), 0) AS total_fee,
                COALESCE(SUM(o.real_amount - o.fee), 0) AS total_net_amount'
            )
            ->first();

        $paginator = (clone $baseQuery)
            ->selectRaw(
                "o.*, m.merchant_no, m.merchant_name, ma.app_id AS merchant_app_code, ma.app_name, pm.method_code, pm.method_name,
                pc.chan_code, pc.chan_name,
                COALESCE(o.real_amount - o.fee, 0) AS net_amount,
                JSON_UNQUOTE(JSON_EXTRACT(o.extra, '$.routing.policy.policy_name')) AS route_policy_name"
            )
            ->orderByDesc('o.id')
            ->paginate($pageSize, ['*'], 'page', $page);

        $items = [];
        foreach ($paginator->items() as $row) {
            $item = (array)$row;
            $item['reconcile_status'] = $this->reconcileStatus((int)($item['status'] ?? 0), (int)($item['notify_stat'] ?? 0));
            $item['reconcile_status_text'] = $this->reconcileStatusText($item['reconcile_status']);
            $items[] = $item;
        }

        return $this->success([
            'summary' => [
                'total_orders' => (int)($summaryRow->total_orders ?? 0),
                'success_orders' => (int)($summaryRow->success_orders ?? 0),
                'pending_orders' => (int)($summaryRow->pending_orders ?? 0),
                'notify_pending_orders' => (int)($summaryRow->notify_pending_orders ?? 0),
                'total_amount' => (string)($summaryRow->total_amount ?? '0.00'),
                'total_fee' => (string)($summaryRow->total_fee ?? '0.00'),
                'total_net_amount' => (string)($summaryRow->total_net_amount ?? '0.00'),
            ],
            'list' => $items,
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'size' => $paginator->perPage(),
        ]);
    }

    public function settlement(Request $request)
    {
        $page = (int)$request->get('page', 1);
        $pageSize = (int)$request->get('page_size', 10);
        $filters = $this->buildFilters($request);
        $baseQuery = $this->buildOrderQuery($filters)->where('o.status', 1);

        $summaryRow = (clone $baseQuery)
            ->selectRaw(
                'COUNT(DISTINCT o.merchant_id) AS merchant_count,
                COUNT(DISTINCT o.merchant_app_id) AS app_count,
                COUNT(*) AS success_orders,
                COALESCE(SUM(o.real_amount), 0) AS gross_amount,
                COALESCE(SUM(o.fee), 0) AS fee_amount,
                COALESCE(SUM(o.real_amount - o.fee), 0) AS net_amount,
                SUM(CASE WHEN o.notify_stat = 0 THEN 1 ELSE 0 END) AS notify_pending_orders,
                COALESCE(SUM(CASE WHEN o.notify_stat = 0 THEN o.real_amount - o.fee ELSE 0 END), 0) AS notify_pending_amount'
            )
            ->first();

        $paginator = (clone $baseQuery)
            ->selectRaw(
                'o.merchant_id, o.merchant_app_id,
                m.merchant_no, m.merchant_name, ma.app_id AS merchant_app_code, ma.app_name,
                COUNT(*) AS success_orders,
                COUNT(DISTINCT o.channel_id) AS channel_count,
                COUNT(DISTINCT o.method_id) AS method_count,
                COALESCE(SUM(o.real_amount), 0) AS gross_amount,
                COALESCE(SUM(o.fee), 0) AS fee_amount,
                COALESCE(SUM(o.real_amount - o.fee), 0) AS net_amount,
                SUM(CASE WHEN o.notify_stat = 0 THEN 1 ELSE 0 END) AS notify_pending_orders,
                COALESCE(SUM(CASE WHEN o.notify_stat = 0 THEN o.real_amount - o.fee ELSE 0 END), 0) AS notify_pending_amount,
                MAX(o.pay_at) AS last_pay_at'
            )
            ->groupBy('o.merchant_id', 'o.merchant_app_id', 'm.merchant_no', 'm.merchant_name', 'ma.app_id', 'ma.app_name')
            ->orderByRaw('SUM(o.real_amount - o.fee) DESC')
            ->paginate($pageSize, ['*'], 'page', $page);

        return $this->success([
            'summary' => [
                'merchant_count' => (int)($summaryRow->merchant_count ?? 0),
                'app_count' => (int)($summaryRow->app_count ?? 0),
                'success_orders' => (int)($summaryRow->success_orders ?? 0),
                'gross_amount' => (string)($summaryRow->gross_amount ?? '0.00'),
                'fee_amount' => (string)($summaryRow->fee_amount ?? '0.00'),
                'net_amount' => (string)($summaryRow->net_amount ?? '0.00'),
                'notify_pending_orders' => (int)($summaryRow->notify_pending_orders ?? 0),
                'notify_pending_amount' => (string)($summaryRow->notify_pending_amount ?? '0.00'),
            ],
            'list' => array_map(fn ($row) => (array)$row, $paginator->items()),
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'size' => $paginator->perPage(),
        ]);
    }

    public function fee(Request $request)
    {
        $page = (int)$request->get('page', 1);
        $pageSize = (int)$request->get('page_size', 10);
        $filters = $this->buildFilters($request);
        $baseQuery = $this->buildOrderQuery($filters);

        if (($filters['status'] ?? '') === '') {
            $baseQuery->where('o.status', 1);
        }

        $summaryRow = (clone $baseQuery)
            ->selectRaw(
                'COUNT(DISTINCT o.merchant_id) AS merchant_count,
                COUNT(DISTINCT o.channel_id) AS channel_count,
                COUNT(DISTINCT o.method_id) AS method_count,
                COUNT(*) AS order_count,
                COALESCE(SUM(o.real_amount), 0) AS total_amount,
                COALESCE(SUM(o.fee), 0) AS total_fee'
            )
            ->first();

        $paginator = (clone $baseQuery)
            ->selectRaw(
                'o.merchant_id, o.channel_id, o.method_id,
                m.merchant_no, m.merchant_name,
                pm.method_code, pm.method_name,
                pc.chan_code, pc.chan_name,
                COUNT(*) AS order_count,
                SUM(CASE WHEN o.status = 1 THEN 1 ELSE 0 END) AS success_orders,
                COALESCE(SUM(o.real_amount), 0) AS total_amount,
                COALESCE(SUM(o.fee), 0) AS total_fee,
                COALESCE(AVG(CASE WHEN o.real_amount > 0 THEN o.fee / o.real_amount ELSE NULL END), 0) AS avg_fee_rate,
                MAX(o.created_at) AS last_order_at'
            )
            ->groupBy('o.merchant_id', 'o.channel_id', 'o.method_id', 'm.merchant_no', 'm.merchant_name', 'pm.method_code', 'pm.method_name', 'pc.chan_code', 'pc.chan_name')
            ->orderByRaw('SUM(o.fee) DESC')
            ->paginate($pageSize, ['*'], 'page', $page);

        $items = [];
        foreach ($paginator->items() as $row) {
            $item = (array)$row;
            $item['avg_fee_rate_percent'] = round(((float)($item['avg_fee_rate'] ?? 0)) * 100, 4);
            $items[] = $item;
        }

        return $this->success([
            'summary' => [
                'merchant_count' => (int)($summaryRow->merchant_count ?? 0),
                'channel_count' => (int)($summaryRow->channel_count ?? 0),
                'method_count' => (int)($summaryRow->method_count ?? 0),
                'order_count' => (int)($summaryRow->order_count ?? 0),
                'total_amount' => (string)($summaryRow->total_amount ?? '0.00'),
                'total_fee' => (string)($summaryRow->total_fee ?? '0.00'),
            ],
            'list' => $items,
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'size' => $paginator->perPage(),
        ]);
    }

    public function settlementRecord(Request $request)
    {
        $page = (int)$request->get('page', 1);
        $pageSize = (int)$request->get('page_size', 10);
        $filters = $this->buildFilters($request);
        $baseQuery = $this->buildOrderQuery($filters)->where('o.status', 1);

        $summaryRow = (clone $baseQuery)
            ->selectRaw(
                "COUNT(DISTINCT CONCAT(o.merchant_app_id, '#', DATE(COALESCE(o.pay_at, o.created_at)))) AS record_count,
                COUNT(DISTINCT o.merchant_id) AS merchant_count,
                COUNT(DISTINCT o.merchant_app_id) AS app_count,
                COUNT(*) AS success_orders,
                COALESCE(SUM(o.real_amount), 0) AS gross_amount,
                COALESCE(SUM(o.fee), 0) AS fee_amount,
                COALESCE(SUM(o.real_amount - o.fee), 0) AS net_amount,
                SUM(CASE WHEN o.notify_stat = 0 THEN 1 ELSE 0 END) AS notify_pending_orders"
            )
            ->first();

        $paginator = (clone $baseQuery)
            ->selectRaw(
                "DATE(COALESCE(o.pay_at, o.created_at)) AS settlement_date,
                o.merchant_id, o.merchant_app_id,
                m.merchant_no, m.merchant_name, ma.app_id AS merchant_app_code, ma.app_name,
                COUNT(*) AS success_orders,
                COALESCE(SUM(o.real_amount), 0) AS gross_amount,
                COALESCE(SUM(o.fee), 0) AS fee_amount,
                COALESCE(SUM(o.real_amount - o.fee), 0) AS net_amount,
                SUM(CASE WHEN o.notify_stat = 0 THEN 1 ELSE 0 END) AS notify_pending_orders,
                MAX(o.pay_at) AS last_pay_at"
            )
            ->groupByRaw("DATE(COALESCE(o.pay_at, o.created_at)), o.merchant_id, o.merchant_app_id, m.merchant_no, m.merchant_name, ma.app_id, ma.app_name")
            ->orderByDesc('settlement_date')
            ->orderByRaw('SUM(o.real_amount - o.fee) DESC')
            ->paginate($pageSize, ['*'], 'page', $page);

        $items = [];
        foreach ($paginator->items() as $row) {
            $item = (array)$row;
            $item['settlement_status'] = (int)($item['notify_pending_orders'] ?? 0) > 0 ? 'pending' : 'ready';
            $item['settlement_status_text'] = $item['settlement_status'] === 'ready' ? 'ready' : 'pending_notify';
            $items[] = $item;
        }

        return $this->success([
            'summary' => [
                'record_count' => (int)($summaryRow->record_count ?? 0),
                'merchant_count' => (int)($summaryRow->merchant_count ?? 0),
                'app_count' => (int)($summaryRow->app_count ?? 0),
                'success_orders' => (int)($summaryRow->success_orders ?? 0),
                'gross_amount' => (string)($summaryRow->gross_amount ?? '0.00'),
                'fee_amount' => (string)($summaryRow->fee_amount ?? '0.00'),
                'net_amount' => (string)($summaryRow->net_amount ?? '0.00'),
                'notify_pending_orders' => (int)($summaryRow->notify_pending_orders ?? 0),
            ],
            'list' => $items,
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'size' => $paginator->perPage(),
        ]);
    }

    public function batchSettlement(Request $request)
    {
        $page = (int)$request->get('page', 1);
        $pageSize = (int)$request->get('page_size', 10);
        $filters = $this->buildFilters($request);
        $baseQuery = $this->buildOrderQuery($filters)->where('o.status', 1);

        $summaryRow = (clone $baseQuery)
            ->selectRaw(
                "COUNT(DISTINCT o.merchant_id) AS merchant_count,
                COUNT(DISTINCT o.merchant_app_id) AS app_count,
                COUNT(*) AS success_orders,
                COUNT(DISTINCT CONCAT(o.merchant_app_id, '#', DATE(COALESCE(o.pay_at, o.created_at)))) AS batch_days,
                COALESCE(SUM(CASE WHEN o.notify_stat = 1 THEN o.real_amount - o.fee ELSE 0 END), 0) AS ready_amount,
                COALESCE(SUM(CASE WHEN o.notify_stat = 0 THEN o.real_amount - o.fee ELSE 0 END), 0) AS pending_amount"
            )
            ->first();

        $paginator = (clone $baseQuery)
            ->selectRaw(
                "o.merchant_id, o.merchant_app_id,
                m.merchant_no, m.merchant_name, ma.app_id AS merchant_app_code, ma.app_name,
                COUNT(*) AS success_orders,
                COUNT(DISTINCT DATE(COALESCE(o.pay_at, o.created_at))) AS batch_days,
                COALESCE(SUM(CASE WHEN o.notify_stat = 1 THEN o.real_amount - o.fee ELSE 0 END), 0) AS ready_amount,
                COALESCE(SUM(CASE WHEN o.notify_stat = 0 THEN o.real_amount - o.fee ELSE 0 END), 0) AS pending_amount,
                SUM(CASE WHEN o.notify_stat = 0 THEN 1 ELSE 0 END) AS pending_notify_orders,
                MAX(o.pay_at) AS last_pay_at"
            )
            ->groupBy('o.merchant_id', 'o.merchant_app_id', 'm.merchant_no', 'm.merchant_name', 'ma.app_id', 'ma.app_name')
            ->orderByRaw('SUM(CASE WHEN o.notify_stat = 1 THEN o.real_amount - o.fee ELSE 0 END) DESC')
            ->paginate($pageSize, ['*'], 'page', $page);

        $items = [];
        foreach ($paginator->items() as $row) {
            $item = (array)$row;
            $item['batch_status'] = (float)($item['pending_amount'] ?? 0) > 0 ? 'pending' : 'ready';
            $item['batch_status_text'] = $item['batch_status'] === 'ready' ? 'ready_to_batch' : 'pending_notify';
            $items[] = $item;
        }

        return $this->success([
            'summary' => [
                'merchant_count' => (int)($summaryRow->merchant_count ?? 0),
                'app_count' => (int)($summaryRow->app_count ?? 0),
                'success_orders' => (int)($summaryRow->success_orders ?? 0),
                'batch_days' => (int)($summaryRow->batch_days ?? 0),
                'ready_amount' => (string)($summaryRow->ready_amount ?? '0.00'),
                'pending_amount' => (string)($summaryRow->pending_amount ?? '0.00'),
            ],
            'list' => $items,
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'size' => $paginator->perPage(),
        ]);
    }

    public function split(Request $request)
    {
        $page = (int)$request->get('page', 1);
        $pageSize = (int)$request->get('page_size', 10);
        $filters = $this->buildFilters($request);
        $baseQuery = $this->buildOrderQuery($filters)->where('o.status', 1);

        $summaryRow = (clone $baseQuery)
            ->selectRaw(
                'COUNT(DISTINCT o.channel_id) AS channel_count,
                COUNT(DISTINCT o.merchant_id) AS merchant_count,
                COUNT(*) AS order_count,
                COALESCE(SUM(o.real_amount), 0) AS gross_amount,
                COALESCE(SUM(o.real_amount - o.fee), 0) AS net_amount,
                COALESCE(SUM((o.real_amount - o.fee) * COALESCE(pc.split_ratio, 100) / 100), 0) AS merchant_share_amount,
                COALESCE(SUM((o.real_amount - o.fee) * (100 - COALESCE(pc.split_ratio, 100)) / 100), 0) AS platform_share_amount,
                COALESCE(SUM(o.real_amount * COALESCE(pc.chan_cost, 0) / 100), 0) AS channel_cost_amount'
            )
            ->first();

        $paginator = (clone $baseQuery)
            ->selectRaw(
                'o.merchant_id, o.channel_id, o.method_id,
                m.merchant_no, m.merchant_name,
                pm.method_code, pm.method_name,
                pc.chan_code, pc.chan_name,
                COALESCE(pc.split_ratio, 100) AS split_ratio,
                COALESCE(pc.chan_cost, 0) AS chan_cost,
                COUNT(*) AS order_count,
                COALESCE(SUM(o.real_amount), 0) AS gross_amount,
                COALESCE(SUM(o.fee), 0) AS fee_amount,
                COALESCE(SUM(o.real_amount - o.fee), 0) AS net_amount,
                COALESCE(SUM((o.real_amount - o.fee) * COALESCE(pc.split_ratio, 100) / 100), 0) AS merchant_share_amount,
                COALESCE(SUM((o.real_amount - o.fee) * (100 - COALESCE(pc.split_ratio, 100)) / 100), 0) AS platform_share_amount,
                COALESCE(SUM(o.real_amount * COALESCE(pc.chan_cost, 0) / 100), 0) AS channel_cost_amount,
                MAX(o.pay_at) AS last_pay_at'
            )
            ->groupBy('o.merchant_id', 'o.channel_id', 'o.method_id', 'm.merchant_no', 'm.merchant_name', 'pm.method_code', 'pm.method_name', 'pc.chan_code', 'pc.chan_name', 'pc.split_ratio', 'pc.chan_cost')
            ->orderByRaw('SUM((o.real_amount - o.fee) * COALESCE(pc.split_ratio, 100) / 100) DESC')
            ->paginate($pageSize, ['*'], 'page', $page);

        return $this->success([
            'summary' => [
                'channel_count' => (int)($summaryRow->channel_count ?? 0),
                'merchant_count' => (int)($summaryRow->merchant_count ?? 0),
                'order_count' => (int)($summaryRow->order_count ?? 0),
                'gross_amount' => (string)($summaryRow->gross_amount ?? '0.00'),
                'net_amount' => (string)($summaryRow->net_amount ?? '0.00'),
                'merchant_share_amount' => (string)($summaryRow->merchant_share_amount ?? '0.00'),
                'platform_share_amount' => (string)($summaryRow->platform_share_amount ?? '0.00'),
                'channel_cost_amount' => (string)($summaryRow->channel_cost_amount ?? '0.00'),
            ],
            'list' => array_map(fn ($row) => (array)$row, $paginator->items()),
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'size' => $paginator->perPage(),
        ]);
    }

    public function invoice(Request $request)
    {
        $page = (int)$request->get('page', 1);
        $pageSize = (int)$request->get('page_size', 10);
        $filters = $this->buildFilters($request);
        $baseQuery = $this->buildOrderQuery($filters)->where('o.status', 1);

        $summaryRow = (clone $baseQuery)
            ->selectRaw(
                'COUNT(DISTINCT o.merchant_id) AS merchant_count,
                COUNT(DISTINCT o.merchant_app_id) AS app_count,
                COUNT(*) AS success_orders,
                COALESCE(SUM(CASE WHEN o.notify_stat = 1 THEN o.real_amount - o.fee ELSE 0 END), 0) AS invoiceable_amount,
                COALESCE(SUM(CASE WHEN o.notify_stat = 0 THEN o.real_amount - o.fee ELSE 0 END), 0) AS pending_invoice_amount,
                SUM(CASE WHEN o.notify_stat = 0 THEN 1 ELSE 0 END) AS pending_notify_orders'
            )
            ->first();

        $paginator = (clone $baseQuery)
            ->selectRaw(
                'o.merchant_id, o.merchant_app_id,
                m.merchant_no, m.merchant_name, ma.app_id AS merchant_app_code, ma.app_name,
                COUNT(*) AS success_orders,
                COALESCE(SUM(o.real_amount - o.fee), 0) AS net_amount,
                COALESCE(SUM(CASE WHEN o.notify_stat = 1 THEN o.real_amount - o.fee ELSE 0 END), 0) AS invoiceable_amount,
                COALESCE(SUM(CASE WHEN o.notify_stat = 0 THEN o.real_amount - o.fee ELSE 0 END), 0) AS pending_invoice_amount,
                SUM(CASE WHEN o.notify_stat = 0 THEN 1 ELSE 0 END) AS pending_notify_orders,
                MAX(o.pay_at) AS last_pay_at'
            )
            ->groupBy('o.merchant_id', 'o.merchant_app_id', 'm.merchant_no', 'm.merchant_name', 'ma.app_id', 'ma.app_name')
            ->orderByRaw('SUM(CASE WHEN o.notify_stat = 1 THEN o.real_amount - o.fee ELSE 0 END) DESC')
            ->paginate($pageSize, ['*'], 'page', $page);

        $items = [];
        foreach ($paginator->items() as $row) {
            $item = (array)$row;
            $item['invoice_status'] = (float)($item['pending_invoice_amount'] ?? 0) > 0 ? 'pending' : 'ready';
            $item['invoice_status_text'] = $item['invoice_status'] === 'ready' ? 'ready' : 'pending_review';
            $items[] = $item;
        }

        return $this->success([
            'summary' => [
                'merchant_count' => (int)($summaryRow->merchant_count ?? 0),
                'app_count' => (int)($summaryRow->app_count ?? 0),
                'success_orders' => (int)($summaryRow->success_orders ?? 0),
                'invoiceable_amount' => (string)($summaryRow->invoiceable_amount ?? '0.00'),
                'pending_invoice_amount' => (string)($summaryRow->pending_invoice_amount ?? '0.00'),
                'pending_notify_orders' => (int)($summaryRow->pending_notify_orders ?? 0),
            ],
            'list' => $items,
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'size' => $paginator->perPage(),
        ]);
    }

    private function buildFilters(Request $request): array
    {
        $methodCode = trim((string)$request->get('method_code', ''));
        $methodId = 0;
        if ($methodCode !== '') {
            $method = $this->methodRepository->findAnyByCode($methodCode);
            $methodId = $method ? (int)$method->id : 0;
        }

        return [
            'merchant_id' => (int)$request->get('merchant_id', 0),
            'merchant_app_id' => (int)$request->get('merchant_app_id', 0),
            'method_id' => $methodId,
            'channel_id' => (int)$request->get('channel_id', 0),
            'status' => (string)$request->get('status', ''),
            'notify_stat' => (string)$request->get('notify_stat', ''),
            'order_id' => trim((string)$request->get('order_id', '')),
            'mch_order_no' => trim((string)$request->get('mch_order_no', '')),
            'created_from' => trim((string)$request->get('created_from', '')),
            'created_to' => trim((string)$request->get('created_to', '')),
        ];
    }

    private function buildOrderQuery(array $filters)
    {
        $query = Db::table('ma_pay_order as o')
            ->leftJoin('ma_merchant as m', 'm.id', '=', 'o.merchant_id')
            ->leftJoin('ma_merchant_app as ma', 'ma.id', '=', 'o.merchant_app_id')
            ->leftJoin('ma_pay_method as pm', 'pm.id', '=', 'o.method_id')
            ->leftJoin('ma_pay_channel as pc', 'pc.id', '=', 'o.channel_id');

        if (!empty($filters['merchant_id'])) {
            $query->where('o.merchant_id', (int)$filters['merchant_id']);
        }
        if (!empty($filters['merchant_app_id'])) {
            $query->where('o.merchant_app_id', (int)$filters['merchant_app_id']);
        }
        if (!empty($filters['method_id'])) {
            $query->where('o.method_id', (int)$filters['method_id']);
        }
        if (!empty($filters['channel_id'])) {
            $query->where('o.channel_id', (int)$filters['channel_id']);
        }
        if (($filters['status'] ?? '') !== '') {
            $query->where('o.status', (int)$filters['status']);
        }
        if (($filters['notify_stat'] ?? '') !== '') {
            $query->where('o.notify_stat', (int)$filters['notify_stat']);
        }
        if (!empty($filters['order_id'])) {
            $query->where('o.order_id', 'like', '%' . $filters['order_id'] . '%');
        }
        if (!empty($filters['mch_order_no'])) {
            $query->where('o.mch_order_no', 'like', '%' . $filters['mch_order_no'] . '%');
        }
        if (!empty($filters['created_from'])) {
            $query->where('o.created_at', '>=', $filters['created_from']);
        }
        if (!empty($filters['created_to'])) {
            $query->where('o.created_at', '<=', $filters['created_to']);
        }

        return $query;
    }

    private function reconcileStatus(int $status, int $notifyStat): string
    {
        if ($status === 1 && $notifyStat === 1) {
            return 'matched';
        }
        if ($status === 1 && $notifyStat === 0) {
            return 'notify_pending';
        }
        if ($status === 0) {
            return 'pending';
        }
        if ($status === 2) {
            return 'failed';
        }
        if ($status === 3) {
            return 'closed';
        }

        return 'unknown';
    }

    private function reconcileStatusText(string $status): string
    {
        return match ($status) {
            'matched' => 'matched',
            'notify_pending' => 'notify_pending',
            'pending' => 'pending',
            'failed' => 'failed',
            'closed' => 'closed',
            default => 'unknown',
        };
    }
}
