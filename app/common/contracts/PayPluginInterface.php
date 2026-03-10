<?php

namespace app\common\contracts;

/**
 * 支付插件接口
 * 
 * 所有支付插件必须实现此接口
 */
interface PayPluginInterface
{
    /**
     * 获取插件代码（唯一标识）
     * 
     * @return string
     */
    public static function getCode(): string;
    
    /**
     * 获取插件名称
     * 
     * @return string
     */
    public static function getName(): string;
    
    /**
     * 获取插件支持的支付方式列表
     * 
     * @return array<string> 支付方式代码数组，如 ['alipay', 'wechat']
     */
    public static function getSupportedMethods(): array;
    
    /**
     * 获取指定支付方式支持的产品列表
     * 
     * @param string $methodCode 支付方式代码
     * @return array<string, string> 产品代码 => 产品名称
     */
    public static function getSupportedProducts(string $methodCode): array;
    
    /**
     * 获取指定支付方式的配置表单结构
     * 
     * @param string $methodCode 支付方式代码
     * @return array 表单字段定义数组
     */
    public static function getConfigSchema(string $methodCode): array;
    
    /**
     * 初始化插件（切换到指定支付方式）
     * 
     * @param string $methodCode 支付方式代码
     * @param array $channelConfig 通道配置
     * @return void
     */
    public function init(string $methodCode, array $channelConfig): void;
    
    /**
     * 统一下单
     * 
     * @param array $orderData 订单数据
     * @param array $channelConfig 通道配置
     * @param string $requestEnv 请求环境（PC/H5/WECHAT/ALIPAY_CLIENT）
     * @return array 支付结果，包含：
     *   - product_code: 选择的产品代码
     *   - channel_order_no: 渠道订单号（如果有）
     *   - pay_params: 支付参数（根据产品类型不同，结构不同）
     */
    public function unifiedOrder(array $orderData, array $channelConfig, string $requestEnv): array;
    
    /**
     * 查询订单
     * 
     * @param array $orderData 订单数据（至少包含 pay_order_id 或 channel_order_no）
     * @param array $channelConfig 通道配置
     * @return array 订单状态信息
     */
    public function query(array $orderData, array $channelConfig): array;
    
    /**
     * 退款
     * 
     * @param array $refundData 退款数据
     * @param array $channelConfig 通道配置
     * @return array 退款结果
     */
    public function refund(array $refundData, array $channelConfig): array;
    
    /**
     * 解析回调通知
     * 
     * @param array $requestData 回调请求数据
     * @param array $channelConfig 通道配置
     * @return array 解析结果，包含：
     *   - status: 订单状态（SUCCESS/FAIL/PENDING）
     *   - pay_order_id: 系统订单号
     *   - channel_trade_no: 渠道交易号
     *   - amount: 支付金额
     *   - pay_time: 支付时间
     */
    public function parseNotify(array $requestData, array $channelConfig): array;
}

