<?php

namespace app\services\api;

use app\common\base\BaseService;
use app\common\utils\EpayUtil;
use app\services\PayOrderService;
use app\services\PayService;
use app\repositories\{MerchantAppRepository, PaymentMethodRepository, PaymentOrderRepository};
use app\models\PaymentOrder;
use app\exceptions\{BadRequestException, NotFoundException, UnauthorizedException};
use support\Request;

/**
 * 易支付服务
 */
class EpayService extends BaseService
{
    public function __construct(
        protected PayOrderService $payOrderService,
        protected MerchantAppRepository $merchantAppRepository,
        protected PaymentOrderRepository $orderRepository,
        protected PaymentMethodRepository $methodRepository,
        protected PayService $payService,
    ) {}

    /**
     * 页面跳转支付（submit.php）
     *
     * @param array   $data    已通过验证的请求参数
     * @param Request $request 请求对象（用于环境检测）
     * @return array  包含 pay_order_id 与 pay_params
     */
    public function submit(array $data, Request $request): array
    {
        // type 在文档中可选，这里如果为空暂不支持收银台模式
        if (empty($data['type'])) {
            throw new BadRequestException('暂不支持收银台模式，请指定支付方式 type');
        }

        return $this->createOrder($data, $request);
    }

    /**
     * API 接口支付（mapi.php）
     *
     * @param array   $data
     * @param Request $request
     * @return array  符合易支付文档的返回结构
     */
    public function mapi(array $data, Request $request): array
    {
        $result = $this->createOrder($data, $request);
        $payParams = $result['pay_params'] ?? [];

        $response = [
            'code'     => 1,
            'msg'      => 'success',
            'trade_no' => $result['order_id'],
        ];

        if (!empty($payParams['type'])) {
            switch ($payParams['type']) {
                case 'redirect':
                    $response['payurl'] = $payParams['url'] ?? '';
                    break;
                case 'qrcode':
                    $response['qrcode'] = $payParams['qrcode_url'] ?? $payParams['qrcode_data'] ?? '';
                    break;
                case 'jsapi':
                    if (!empty($payParams['urlscheme'])) {
                        $response['urlscheme'] = $payParams['urlscheme'];
                    }
                    break;
                default:
                    // 不识别的类型不返回额外字段
                    break;
            }
        }

        return $response;
    }

    /**
     * API 接口（api.php）- 处理 act=order / refund 等
     *
     * @param array $data
     * @return array
     */
    public function api(array $data): array
    {
        $act = strtolower($data['act'] ?? '');

        return match ($act) {
            'order'  => $this->apiOrder($data),
            'refund' => $this->apiRefund($data),
            default  => [
                'code' => 0,
                'msg'  => '不支持的操作类型',
            ],
        };
    }

    /**
     * api.php?act=order 查询单个订单
     */
    private function apiOrder(array $data): array
    {
        $pid = (int)($data['pid'] ?? 0);
        $key = (string)($data['key'] ?? '');

        if ($pid <= 0 || $key === '') {
            throw new BadRequestException('商户参数错误');
        }

        $app = $this->merchantAppRepository->findByAppId((string)$pid);
        if (!$app || $app->app_secret !== $key) {
            throw new NotFoundException('商户不存在或密钥错误');
        }

        $tradeNo    = $data['trade_no'] ?? '';
        $outTradeNo = $data['out_trade_no'] ?? '';

        if ($tradeNo === '' && $outTradeNo === '') {
            throw new BadRequestException('系统订单号与商户订单号不能同时为空');
        }

        if ($tradeNo !== '') {
            $order = $this->orderRepository->findByOrderId($tradeNo);
        } else {
            $order = $this->orderRepository->findByMchNo($app->merchant_id, $app->id, $outTradeNo);
        }

        if (!$order) {
            throw new NotFoundException('订单不存在');
        }

        $methodCode = $this->getMethodCodeByOrder($order);

        return [
            'code'         => 1,
            'msg'          => '查询订单号成功！',
            'trade_no'     => $order->order_id,
            'out_trade_no' => $order->mch_order_no,
            'api_trade_no' => $order->chan_trade_no ?? '',
            'type'         => $methodCode,
            'pid'          => (int)$pid,
            'addtime'      => $order->created_at,
            'endtime'      => $order->pay_at,
            'name'         => $order->subject,
            'money'        => (string)$order->amount,
            'status'       => $order->status === PaymentOrder::STATUS_SUCCESS ? 1 : 0,
            'param'        => $order->extra['param'] ?? '',
            'buyer'        => '',
        ];
    }

    /**
     * api.php?act=refund 提交订单退款
     */
    private function apiRefund(array $data): array
    {
        $pid = (int)($data['pid'] ?? 0);
        $key = (string)($data['key'] ?? '');

        if ($pid <= 0 || $key === '') {
            throw new BadRequestException('商户参数错误');
        }

        $app = $this->merchantAppRepository->findByAppId((string)$pid);
        if (!$app || $app->app_secret !== $key) {
            throw new NotFoundException('商户不存在或密钥错误');
        }

        $tradeNo    = $data['trade_no'] ?? '';
        $outTradeNo = $data['out_trade_no'] ?? '';
        $money      = (float)($data['money'] ?? 0);

        if ($tradeNo === '' && $outTradeNo === '') {
            throw new BadRequestException('系统订单号与商户订单号不能同时为空');
        }
        if ($money <= 0) {
            throw new BadRequestException('退款金额必须大于0');
        }

        if ($tradeNo !== '') {
            $order = $this->orderRepository->findByOrderId($tradeNo);
        } else {
            $order = $this->orderRepository->findByMchNo($app->merchant_id, $app->id, $outTradeNo);
        }

        if (!$order) {
            throw new NotFoundException('订单不存在');
        }

        $refundResult = $this->payOrderService->refundOrder([
            'order_id'      => $order->order_id,
            'refund_amount' => $money,
        ]);

        return [
            'code' => 1,
            'msg'  => '退款成功',
        ];
    }

    /**
     * 创建订单并调用插件统一下单
     *
     * @param array   $data
     * @param Request $request
     * @return array
     */
    private function createOrder(array $data, Request $request): array
    {
        $pid = (int)($data['pid'] ?? 0);
        if ($pid <= 0) {
            throw new BadRequestException('应用ID不能为空');
        }

        // 根据 pid 映射应用（约定 pid = app_id）
        $app = $this->merchantAppRepository->findByAppId((string)$pid);
        if (!$app || $app->status !== 1) {
            throw new NotFoundException('商户应用不存在或已禁用');
        }

        // 易支付签名校验：使用 app_secret 作为 key
        $signType = strtolower((string)($data['sign_type'] ?? 'md5'));
        if ($signType !== 'md5') {
            throw new BadRequestException('不支持的签名类型：' . ($data['sign_type'] ?? ''));
        }
        if (!EpayUtil::verify($data, (string)$app->app_secret)) {
            throw new UnauthorizedException('签名验证失败');
        }

        $orderData  = [
            'mch_id'       => $app->merchant_id,
            'app_id'       => $app->id,
            'mch_order_no' => $data['out_trade_no'],
            'pay_type'     => $data['type'],
            'amount'       => sprintf('%.2f', (float)$data['money']),
            'subject'      => $data['name'],
            'body'         => $data['name'],
            'client_ip'    => $data['clientip'] ?? $request->getRemoteIp(),
            'extra'        => [
                'param'      => $data['param'] ?? '',
                'notify_url' => $data['notify_url'] ?? '',
                'return_url' => $data['return_url'] ?? '',
            ],
        ];

        // 调用通用支付服务完成通道选择与插件下单
        return $this->payService->pay($orderData, [
            'device'  => $data['device'] ?? '',
            'request' => $request,
        ]);
    }

    /**
     * 根据订单获取支付方式编码
     */
    private function getMethodCodeByOrder(PaymentOrder $order): string
    {
        $method = $this->methodRepository->find($order->method_id);
        return $method ? $method->method_code : '';
    }
}
