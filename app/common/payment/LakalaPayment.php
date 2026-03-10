<?php

namespace app\common\payment;

use app\common\contracts\AbstractPayPlugin;

/**
 * 拉卡拉支付插件示例
 *
 * 支持多个支付方式：alipay、wechat、unionpay
 */
class LakalaPayment extends AbstractPayPlugin
{
    public static function getCode(): string
    {
        return 'lakala';
    }

    public static function getName(): string
    {
        return '拉卡拉支付';
    }

    /**
     * 支持多个支付方式
     */
    public static function getSupportedMethods(): array
    {
        return ['alipay', 'wechat', 'unionpay'];
    }

    /**
     * 根据支付方式返回支持的产品
     */
    public static function getSupportedProducts(string $methodCode): array
    {
        return match ($methodCode) {
            'alipay' => [
                ['code' => 'alipay_h5', 'name' => '支付宝H5', 'device_type' => 'H5'],
                ['code' => 'alipay_life', 'name' => '支付宝生活号', 'device_type' => 'ALIPAY_CLIENT'],
                ['code' => 'alipay_app', 'name' => '支付宝APP', 'device_type' => 'ALIPAY_CLIENT'],
                ['code' => 'alipay_qr', 'name' => '支付宝扫码', 'device_type' => 'PC'],
            ],
            'wechat' => [
                ['code' => 'wechat_jsapi', 'name' => '微信JSAPI', 'device_type' => 'WECHAT'],
                ['code' => 'wechat_h5', 'name' => '微信H5', 'device_type' => 'H5'],
                ['code' => 'wechat_native', 'name' => '微信扫码', 'device_type' => 'PC'],
                ['code' => 'wechat_app', 'name' => '微信APP', 'device_type' => 'H5'],
            ],
            'unionpay' => [
                ['code' => 'unionpay_h5', 'name' => '云闪付H5', 'device_type' => 'H5'],
                ['code' => 'unionpay_app', 'name' => '云闪付APP', 'device_type' => 'H5'],
            ],
            default => [],
        };
    }

    /**
     * 获取配置Schema
     */
    public static function getConfigSchema(string $methodCode): array
    {
        $baseFields = [
            ['field' => 'merchant_id', 'label' => '商户号', 'type' => 'input', 'required' => true],
            ['field' => 'secret_key', 'label' => '密钥', 'type' => 'input', 'required' => true],
            ['field' => 'api_url', 'label' => '接口地址', 'type' => 'input', 'required' => true],
        ];

        // 根据支付方式添加特定字段
        if ($methodCode === 'alipay') {
            $baseFields[] = ['field' => 'alipay_app_id', 'label' => '支付宝AppId', 'type' => 'input'];
        } elseif ($methodCode === 'wechat') {
            $baseFields[] = ['field' => 'wechat_app_id', 'label' => '微信AppId', 'type' => 'input'];
        }

        return ['fields' => $baseFields];
    }

    /**
     * 统一下单
     */
    public function unifiedOrder(array $orderData, array $channelConfig, string $requestEnv): array
    {
        // 1. 从通道已开通产品中选择（根据环境）
        $enabledProducts = $channelConfig['enabled_products'] ?? [];
        $allProducts     = static::getSupportedProducts($this->currentMethod);
        $productCode     = $this->selectProductByEnv($enabledProducts, $requestEnv, $allProducts);

        if (!$productCode) {
            throw new \RuntimeException('当前环境无可用支付产品');
        }

        // 2. 根据当前支付方式和产品调用不同的接口
        // 这里简化处理，实际应调用拉卡拉的API
        return match ($this->currentMethod) {
            'alipay'   => $this->createAlipayOrder($orderData, $channelConfig, $productCode),
            'wechat'   => $this->createWechatOrder($orderData, $channelConfig, $productCode),
            'unionpay' => $this->createUnionpayOrder($orderData, $channelConfig, $productCode),
            default    => throw new \RuntimeException('未初始化的支付方式'),
        };
    }

    /**
     * 查询订单
     */
    public function query(array $orderData, array $channelConfig): array
    {
        // TODO: 实现查询逻辑
        return ['status' => 'PENDING'];
    }

    /**
     * 退款
     */
    public function refund(array $refundData, array $channelConfig): array
    {
        // TODO: 实现退款逻辑
        return ['status' => 'SUCCESS'];
    }

    /**
     * 解析回调
     */
    public function parseNotify(array $requestData, array $channelConfig): array
    {
        // TODO: 实现回调解析和验签
        return [
            'status'          => 'SUCCESS',
            'pay_order_id'    => $requestData['out_trade_no'] ?? '',
            'channel_trade_no'=> $requestData['trade_no'] ?? '',
            'amount'          => $requestData['total_amount'] ?? 0,
        ];
    }

    private function createAlipayOrder(array $orderData, array $config, string $productCode): array
    {
        // TODO: 调用拉卡拉的支付宝接口
        return [
            'product_code'    => $productCode,
            'channel_order_no'=> '',
            'pay_params'      => [
                'type' => 'redirect',
                'url'  => 'https://example.com/pay?order=' . $orderData['pay_order_id'],
            ],
        ];
    }

    private function createWechatOrder(array $orderData, array $config, string $productCode): array
    {
        // TODO: 调用拉卡拉的微信接口
        return [
            'product_code'    => $productCode,
            'channel_order_no'=> '',
            'pay_params'      => [
                'type'      => 'jsapi',
                'appId'     => $config['wechat_app_id'] ?? '',
                'timeStamp' => time(),
                'nonceStr'  => uniqid(),
                'package'   => 'prepay_id=xxx',
                'signType'  => 'MD5',
                'paySign'   => 'xxx',
            ],
        ];
    }

    private function createUnionpayOrder(array $orderData, array $config, string $productCode): array
    {
        // TODO: 调用拉卡拉的云闪付接口
        return [
            'product_code'    => $productCode,
            'channel_order_no'=> '',
            'pay_params'      => [
                'type' => 'redirect',
                'url'  => 'https://example.com/unionpay?order=' . $orderData['pay_order_id'],
            ],
        ];
    }
}


