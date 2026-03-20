<?php

namespace app\http\admin\controller;

use app\common\base\BaseController;
use app\models\Merchant;
use app\models\MerchantApp;
use app\models\PaymentChannel;
use app\models\PaymentMethod;
use app\repositories\PaymentMethodRepository;
use app\repositories\PaymentOrderRepository;
use app\services\PayOrderService;
use support\Request;
use support\Response;

/**
 * 订单管理
 */
class OrderController extends BaseController
{
    public function __construct(
        protected PaymentOrderRepository $orderRepository,
        protected PaymentMethodRepository $methodRepository,
        protected PayOrderService $payOrderService,
    ) {
    }

    /**
     * GET /adminapi/order/list
     */
    public function list(Request $request)
    {
        $page = (int)$request->get('page', 1);
        $pageSize = (int)$request->get('page_size', 10);
        $filters = $this->buildListFilters($request);

        $paginator = $this->orderRepository->searchPaginate($filters, $page, $pageSize);
        $items = [];
        foreach ($paginator->items() as $row) {
            $items[] = $this->formatOrderRow($row);
        }

        return $this->success([
            'list' => $items,
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'size' => $paginator->perPage(),
        ]);
    }

    /**
     * GET /adminapi/order/detail?id=1 或 order_id=P...
     */
    public function detail(Request $request)
    {
        $id = (int)$request->get('id', 0);
        $orderId = trim((string)$request->get('order_id', ''));

        if ($id > 0) {
            $row = $this->orderRepository->find($id);
        } elseif ($orderId !== '') {
            $row = $this->orderRepository->findByOrderId($orderId);
        } else {
            return $this->fail('参数错误', 400);
        }

        if (!$row) {
            return $this->fail('订单不存在', 404);
        }

        return $this->success($this->formatOrderRow($row));
    }

    /**
     * GET /adminapi/order/export
     */
    public function export(Request $request): Response
    {
        $limit = 5000;
        $filters = $this->buildListFilters($request);
        $rows = $this->orderRepository->searchList($filters, $limit);

        $merchantIds = [];
        $merchantAppIds = [];
        $methodIds = [];
        $channelIds = [];
        $items = [];

        foreach ($rows as $row) {
            $item = $this->formatOrderRow($row);
            $items[] = $item;
            if (!empty($item['merchant_id'])) {
                $merchantIds[] = (int)$item['merchant_id'];
            }
            if (!empty($item['merchant_app_id'])) {
                $merchantAppIds[] = (int)$item['merchant_app_id'];
            }
            if (!empty($item['method_id'])) {
                $methodIds[] = (int)$item['method_id'];
            }
            if (!empty($item['channel_id'])) {
                $channelIds[] = (int)$item['channel_id'];
            }
        }

        $merchantMap = Merchant::query()
            ->whereIn('id', array_values(array_unique($merchantIds)))
            ->get(['id', 'merchant_no', 'merchant_name'])
            ->keyBy('id');
        $merchantAppMap = MerchantApp::query()
            ->whereIn('id', array_values(array_unique($merchantAppIds)))
            ->get(['id', 'app_id', 'app_name'])
            ->keyBy('id');
        $methodMap = PaymentMethod::query()
            ->whereIn('id', array_values(array_unique($methodIds)))
            ->get(['id', 'method_code', 'method_name'])
            ->keyBy('id');
        $channelMap = PaymentChannel::query()
            ->whereIn('id', array_values(array_unique($channelIds)))
            ->get(['id', 'chan_code', 'chan_name'])
            ->keyBy('id');

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, [
            '系统单号',
            '商户单号',
            '商户编号',
            '商户名称',
            '应用APPID',
            '应用名称',
            '支付方式编码',
            '支付方式名称',
            '通道编码',
            '通道名称',
            '订单金额',
            '实收金额',
            '手续费',
            '币种',
            '订单状态',
            '路由结果',
            '路由模式',
            '策略名称',
            '通道单号',
            '通道交易号',
            '通知状态',
            '通知次数',
            '客户端IP',
            '商品标题',
            '创建时间',
            '支付时间',
            '路由错误',
        ]);

        foreach ($items as $item) {
            $merchant = $merchantMap->get((int)($item['merchant_id'] ?? 0));
            $merchantApp = $merchantAppMap->get((int)($item['merchant_app_id'] ?? 0));
            $method = $methodMap->get((int)($item['method_id'] ?? 0));
            $channel = $channelMap->get((int)($item['channel_id'] ?? 0));

            fputcsv($stream, [
                (string)($item['order_id'] ?? ''),
                (string)($item['mch_order_no'] ?? ''),
                (string)($merchant->merchant_no ?? ''),
                (string)($merchant->merchant_name ?? ''),
                (string)($merchantApp->app_id ?? ''),
                (string)($merchantApp->app_name ?? ''),
                (string)($method->method_code ?? ''),
                (string)($method->method_name ?? ''),
                (string)($channel->chan_code ?? $item['route_channel_code'] ?? ''),
                (string)($channel->chan_name ?? $item['route_channel_name'] ?? ''),
                (string)($item['amount'] ?? '0.00'),
                (string)($item['real_amount'] ?? '0.00'),
                (string)($item['fee'] ?? '0.00'),
                (string)($item['currency'] ?? ''),
                $this->statusText((int)($item['status'] ?? 0)),
                (string)($item['route_source_text'] ?? ''),
                (string)($item['route_mode_text'] ?? ''),
                (string)($item['route_policy_name'] ?? ''),
                (string)($item['chan_order_no'] ?? ''),
                (string)($item['chan_trade_no'] ?? ''),
                $this->notifyStatusText((int)($item['notify_stat'] ?? 0)),
                (string)($item['notify_cnt'] ?? '0'),
                (string)($item['client_ip'] ?? ''),
                (string)($item['subject'] ?? ''),
                (string)($item['created_at'] ?? ''),
                (string)($item['pay_at'] ?? ''),
                (string)($item['route_error']['message'] ?? ''),
            ]);
        }

        rewind($stream);
        $content = stream_get_contents($stream) ?: '';
        fclose($stream);

        $filename = 'orders-' . date('Ymd-His') . '.csv';
        return response($content, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename*=UTF-8''" . rawurlencode($filename),
            'X-Export-Count' => (string)count($items),
            'X-Export-Limit' => (string)$limit,
            'X-Export-Limited' => count($items) >= $limit ? '1' : '0',
        ]);
    }

    /**
     * POST /adminapi/order/refund
     * - order_id: 系统订单号
     * - refund_amount: 退款金额
     */
    public function refund(Request $request)
    {
        $orderId = trim((string)$request->post('order_id', ''));
        $refundAmount = (float)$request->post('refund_amount', 0);
        $refundReason = trim((string)$request->post('refund_reason', ''));

        try {
            $result = $this->payOrderService->refundOrder([
                'order_id' => $orderId,
                'refund_amount' => $refundAmount,
                'refund_reason' => $refundReason,
            ]);
            return $this->success($result, '退款发起成功');
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 400);
        }
    }

    private function formatOrderRow(object $row): array
    {
        $data = method_exists($row, 'toArray') ? $row->toArray() : (array)$row;
        $extra = is_array($data['extra'] ?? null) ? $data['extra'] : [];
        $routing = is_array($extra['routing'] ?? null) ? $extra['routing'] : null;
        $routeError = is_array($extra['route_error'] ?? null) ? $extra['route_error'] : null;

        $data['routing'] = $routing;
        $data['route_error'] = $routeError;
        $data['route_candidates'] = is_array($routing['candidates'] ?? null) ? $routing['candidates'] : [];
        $data['route_policy_name'] = (string)($routing['policy']['policy_name'] ?? '');
        $data['route_source'] = (string)($routing['source'] ?? '');
        $data['route_source_text'] = $this->routeSourceText($routing, $routeError);
        $data['route_mode_text'] = $this->routeModeText((string)($routing['route_mode'] ?? ''));
        $data['route_channel_name'] = (string)($routing['selected_channel_name'] ?? '');
        $data['route_channel_code'] = (string)($routing['selected_channel_code'] ?? '');
        $data['route_state'] = $routeError
            ? 'error'
            : ($routing ? (string)($routing['source'] ?? 'unknown') : 'none');

        return $data;
    }

    private function buildListFilters(Request $request): array
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
            'route_state' => trim((string)$request->get('route_state', '')),
            'route_policy_name' => trim((string)$request->get('route_policy_name', '')),
            'status' => $request->get('status', ''),
            'order_id' => trim((string)$request->get('order_id', '')),
            'mch_order_no' => trim((string)$request->get('mch_order_no', '')),
            'created_from' => trim((string)$request->get('created_from', '')),
            'created_to' => trim((string)$request->get('created_to', '')),
        ];
    }

    private function statusText(int $status): string
    {
        return match ($status) {
            0 => '待支付',
            1 => '成功',
            2 => '失败',
            3 => '关闭',
            default => (string)$status,
        };
    }

    private function notifyStatusText(int $notifyStatus): string
    {
        return $notifyStatus === 1 ? '已通知' : '待通知';
    }

    private function routeSourceText(?array $routing, ?array $routeError): string
    {
        if ($routeError) {
            return '路由失败';
        }

        return match ((string)($routing['source'] ?? '')) {
            'policy' => '策略命中',
            'fallback' => '回退选择',
            default => '未记录',
        };
    }

    private function routeModeText(string $routeMode): string
    {
        return match ($routeMode) {
            'priority' => '优先级',
            'weight' => '权重分流',
            'failover' => '主备切换',
            'sort' => '排序兜底',
            default => $routeMode ?: '-',
        };
    }
}

