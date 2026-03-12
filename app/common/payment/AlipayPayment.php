<?php

declare(strict_types=1);

namespace app\common\payment;

use app\common\base\BasePayment;
use app\common\contracts\PaymentInterface;
use app\exceptions\PaymentException;
use Psr\Http\Message\ResponseInterface;
use support\Request;
use Yansongda\Pay\Pay;
use Yansongda\Supports\Collection;

/**
 * 支付宝支付插件（基于 yansongda/pay ~3.7）
 *
 * 支持：web（电脑网站）、h5（手机网站）、scan（扫码）、app（APP 支付）
 *
 * 通道配置：app_id, app_secret_cert, app_public_cert_path, alipay_public_cert_path,
 * alipay_root_cert_path, notify_url, return_url, mode(0正式/1沙箱)
 */
class AlipayPayment extends BasePayment implements PaymentInterface
{
    protected array $paymentInfo = [
        'code'           => 'alipay',
        'name'           => '支付宝直连',
        'author'         => '',
        'link'           => '',
        'pay_types'      => ['alipay'],
        'transfer_types' => [],
        'config_schema'  => [
            'fields' => [
                ['field' => 'app_id', 'label' => '应用ID', 'type' => 'text', 'required' => true],
                ['field' => 'app_secret_cert', 'label' => '应用私钥', 'type' => 'textarea', 'required' => true],
                ['field' => 'app_public_cert_path', 'label' => '应用公钥证书路径', 'type' => 'text', 'required' => true],
                ['field' => 'alipay_public_cert_path', 'label' => '支付宝公钥证书路径', 'type' => 'text', 'required' => true],
                ['field' => 'alipay_root_cert_path', 'label' => '支付宝根证书路径', 'type' => 'text', 'required' => true],
                ['field' => 'notify_url', 'label' => '异步通知地址', 'type' => 'text', 'required' => true],
                ['field' => 'return_url', 'label' => '同步跳转地址', 'type' => 'text', 'required' => false],
                ['field' => 'mode', 'label' => '环境', 'type' => 'select', 'options' => [['value' => '0', 'label' => '正式'], ['value' => '1', 'label' => '沙箱']]],
            ],
        ],
    ];

    private const PRODUCT_WEB  = 'alipay_web';
    private const PRODUCT_H5   = 'alipay_h5';
    private const PRODUCT_SCAN = 'alipay_scan';
    private const PRODUCT_APP  = 'alipay_app';

    public function init(array $channelConfig): void
    {
        parent::init($channelConfig);
        Pay::config([
            'alipay' => [
                'default' => [
                    'app_id'                  => $this->getConfig('app_id', ''),
                    'app_secret_cert'         => $this->getConfig('app_secret_cert', ''),
                    'app_public_cert_path'    => $this->getConfig('app_public_cert_path', ''),
                    'alipay_public_cert_path' => $this->getConfig('alipay_public_cert_path', ''),
                    'alipay_root_cert_path'   => $this->getConfig('alipay_root_cert_path', ''),
                    'notify_url'              => $this->getConfig('notify_url', ''),
                    'return_url'              => $this->getConfig('return_url', ''),
                    'mode'                    => (int)($this->getConfig('mode', Pay::MODE_NORMAL)),
                ],
            ],
        ]);
    }

    private function chooseProduct(array $order): string
    {
        $enabled = $this->channelConfig['enabled_products'] ?? ['alipay_web', 'alipay_h5', 'alipay_scan'];
        $env     = $order['_env'] ?? 'pc';
        $map     = ['pc' => self::PRODUCT_WEB, 'h5' => self::PRODUCT_H5, 'alipay' => self::PRODUCT_APP];
        $prefer  = $map[$env] ?? self::PRODUCT_WEB;
        return in_array($prefer, $enabled, true) ? $prefer : ($enabled[0] ?? self::PRODUCT_WEB);
    }

    public function pay(array $order): array
    {
        $orderId   = $order['order_id'] ?? $order['mch_no'] ?? '';
        $amount    = (float)($order['amount'] ?? 0);
        $subject   = (string)($order['subject'] ?? '');
        $extra     = $order['extra'] ?? [];
        $returnUrl = $extra['return_url'] ?? $this->getConfig('return_url', '');
        $notifyUrl = $this->getConfig('notify_url', '');

        $params = [
            'out_trade_no' => $orderId,
            'total_amount' => sprintf('%.2f', $amount),
            'subject'      => $subject,
        ];
        if ($returnUrl !== '') {
            $params['_return_url'] = $returnUrl;
        }
        if ($notifyUrl !== '') {
            $params['_notify_url'] = $notifyUrl;
        }

        $product = $this->chooseProduct($order);

        try {
            return match ($product) {
                self::PRODUCT_WEB  => $this->doWeb($params),
                self::PRODUCT_H5   => $this->doH5($params),
                self::PRODUCT_SCAN => $this->doScan($params),
                self::PRODUCT_APP  => $this->doApp($params),
                default            => throw new PaymentException('不支持的支付宝产品：' . $product, 402),
            };
        } catch (PaymentException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new PaymentException('支付宝下单失败：' . $e->getMessage(), 402, ['order_id' => $orderId]);
        }
    }

    private function doWeb(array $params): array
    {
        $response = Pay::alipay()->web($params);
        $body     = $response instanceof ResponseInterface ? (string)$response->getBody() : '';
        return [
            'pay_params'    => ['type' => 'form', 'method' => 'POST', 'action' => '', 'html' => $body],
            'chan_order_no' => $params['out_trade_no'],
            'chan_trade_no' => '',
        ];
    }

    private function doH5(array $params): array
    {
        $returnUrl = $params['_return_url'] ?? $this->getConfig('return_url', '');
        if ($returnUrl !== '') {
            $params['quit_url'] = $returnUrl;
        }
        $response = Pay::alipay()->h5($params);
        $body     = $response instanceof ResponseInterface ? (string)$response->getBody() : '';
        return [
            'pay_params'    => ['type' => 'form', 'method' => 'POST', 'action' => '', 'html' => $body],
            'chan_order_no' => $params['out_trade_no'],
            'chan_trade_no' => '',
        ];
    }

    private function doScan(array $params): array
    {
        /** @var Collection $result */
        $result = Pay::alipay()->scan($params);
        $qrCode = $result->get('qr_code', '');
        return [
            'pay_params'    => ['type' => 'qrcode', 'qrcode_url' => $qrCode, 'qrcode_data' => $qrCode],
            'chan_order_no' => $params['out_trade_no'],
            'chan_trade_no' => $result->get('trade_no', ''),
        ];
    }

    private function doApp(array $params): array
    {
        /** @var Collection $result */
        $result    = Pay::alipay()->app($params);
        $orderStr  = $result->get('order_string', '');
        return [
            'pay_params'    => ['type' => 'jsapi', 'order_str' => $orderStr, 'urlscheme' => $orderStr],
            'chan_order_no' => $params['out_trade_no'],
            'chan_trade_no' => $result->get('trade_no', ''),
        ];
    }

    public function query(array $order): array
    {
        $outTradeNo = $order['chan_order_no'] ?? $order['order_id'] ?? '';

        try {
            /** @var Collection $result */
            $result       = Pay::alipay()->query(['out_trade_no' => $outTradeNo]);
            $tradeStatus  = $result->get('trade_status', '');
            $tradeNo      = $result->get('trade_no', '');
            $totalAmount  = (float)$result->get('total_amount', 0);
            $status       = in_array($tradeStatus, ['TRADE_SUCCESS', 'TRADE_FINISHED'], true) ? 'success' : $tradeStatus;

            return [
                'status'        => $status,
                'chan_trade_no' => $tradeNo,
                'pay_amount'    => $totalAmount,
            ];
        } catch (\Throwable $e) {
            throw new PaymentException('支付宝查询失败：' . $e->getMessage(), 402);
        }
    }

    public function close(array $order): array
    {
        $outTradeNo = $order['chan_order_no'] ?? $order['order_id'] ?? '';

        try {
            Pay::alipay()->close(['out_trade_no' => $outTradeNo]);
            return ['success' => true, 'msg' => '关闭成功'];
        } catch (\Throwable $e) {
            throw new PaymentException('支付宝关单失败：' . $e->getMessage(), 402);
        }
    }

    public function refund(array $order): array
    {
        $outTradeNo   = $order['chan_order_no'] ?? $order['order_id'] ?? '';
        $refundAmount = (float)($order['refund_amount'] ?? 0);
        $refundNo     = $order['refund_no'] ?? $order['order_id'] . '_' . time();
        $refundReason = (string)($order['refund_reason'] ?? '');

        if ($outTradeNo === '' || $refundAmount <= 0) {
            throw new PaymentException('退款参数不完整', 402);
        }

        $params = [
            'out_trade_no'    => $outTradeNo,
            'refund_amount'   => sprintf('%.2f', $refundAmount),
            'out_request_no'  => $refundNo,
        ];
        if ($refundReason !== '') {
            $params['refund_reason'] = $refundReason;
        }

        try {
            /** @var Collection $result */
            $result = Pay::alipay()->refund($params);
            $code   = $result->get('code');
            $subMsg = $result->get('sub_msg', '');

            if ($code === '10000' || $code === 10000) {
                return [
                    'success'       => true,
                    'chan_refund_no'=> $result->get('trade_no', $refundNo),
                    'msg'           => '退款成功',
                ];
            }
            throw new PaymentException($subMsg ?: '退款失败', 402);
        } catch (PaymentException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new PaymentException('支付宝退款失败：' . $e->getMessage(), 402);
        }
    }

    public function notify(Request $request): array
    {
        $params = array_merge($request->get(), $request->post());

        try {
            /** @var Collection $result */
            $result      = Pay::alipay()->callback($params);
            $tradeStatus = $result->get('trade_status', '');
            $outTradeNo  = $result->get('out_trade_no', '');
            $tradeNo     = $result->get('trade_no', '');
            $totalAmount = (float)$result->get('total_amount', 0);

            if (!in_array($tradeStatus, ['TRADE_SUCCESS', 'TRADE_FINISHED'], true)) {
                throw new PaymentException('回调状态异常：' . $tradeStatus, 402);
            }

            return [
                'status'       => 'success',
                'pay_order_id' => $outTradeNo,
                'chan_trade_no'=> $tradeNo,
                'amount'       => $totalAmount,
            ];
        } catch (PaymentException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new PaymentException('支付宝回调验签失败：' . $e->getMessage(), 402);
        }
    }
}
