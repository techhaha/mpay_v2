<?php

declare(strict_types=1);

namespace app\common\sdk\wxpay;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * 微信支付轻量客户端。
 *
 * 设计目标：
 * - 不依赖微信支付官方 SDK，只覆盖 MPAY 当前官方 API 支付插件需要的基础能力。
 * - 同时支持 V3 JSON 接口与 V2 XML 接口，调用方通过 api_version 明确选择。
 * - 统一处理商户模式/服务商模式、请求签名、调起支付参数、查单、关单、退款和回调解密。
 *
 * 当前覆盖的支付产品：
 * - JSAPI 支付：公众号内网页支付。
 * - APP 支付：APP 调起微信支付。
 * - H5 支付：手机浏览器跳转微信支付。
 * - Native 支付：电脑网站/扫码支付，返回 code_url。
 * - 小程序支付：小程序 wx.requestPayment，V3/V2 下单接口均与 JSAPI 共用。
 *
 * V2/V3 主要差异：
 * - V3 使用 JSON + Authorization 头 RSA 签名，金额单位为分，字段为 amount.total。
 * - V2 使用 XML + sign 字段，金额单位为分，字段为 total_fee。
 * - V3 小程序和 JSAPI 下单路径都是 /transactions/jsapi；V2 trade_type 都是 JSAPI。
 * - V2 退款接口 /secapi/pay/refund 需要双向证书；V3 退款接口不使用商户双向证书。
 */
class WxpayClient
{
    public const API_VERSION_V3 = 'v3';
    public const API_VERSION_V2 = 'v2';

    public const PRODUCT_JSAPI = 'jsapi';
    public const PRODUCT_APP = 'app';
    public const PRODUCT_H5 = 'h5';
    public const PRODUCT_NATIVE = 'native';
    public const PRODUCT_MINI = 'mini';

    private const V2_TRADE_TYPE_JSAPI = 'JSAPI';
    private const V2_TRADE_TYPE_APP = 'APP';
    private const V2_TRADE_TYPE_H5 = 'MWEB';
    private const V2_TRADE_TYPE_NATIVE = 'NATIVE';

    /**
     * 微信支付 SDK 配置。
     *
     * @var WxpayConfig
     */
    private WxpayConfig $config;

    /**
     * HTTP 客户端。
     *
     * @var Client|null
     */
    private ?Client $httpClient = null;

    /**
     * 构造方法。
     *
     * @param WxpayConfig|array<string, mixed> $config 配置对象或配置数组
     */
    public function __construct(WxpayConfig|array $config)
    {
        $this->config = is_array($config) ? WxpayConfig::fromArray($config) : $config;
    }

    /**
     * JSAPI 支付下单。
     *
     * V3：POST /v3/pay/transactions/jsapi，必须传 payer.openid。
     * V2：/pay/unifiedorder + trade_type=JSAPI，必须传 openid。
     *
     * @param array<string, mixed> $order 官方业务参数
     * @param array<string, mixed> $options 调用选项
     * @return array<string, mixed> 下单响应与前端调起参数
     */
    public function jsapiPay(array $order, array $options = []): array
    {
        return $this->prepay(self::PRODUCT_JSAPI, $order, $options);
    }

    /**
     * 小程序支付下单。
     *
     * V3/V2 下单接口与 JSAPI 共用，返回值中的 request_payment 可直接交给小程序调用。
     *
     * @param array<string, mixed> $order 官方业务参数
     * @param array<string, mixed> $options 调用选项
     * @return array<string, mixed> 下单响应与小程序调起参数
     */
    public function miniPay(array $order, array $options = []): array
    {
        return $this->prepay(self::PRODUCT_MINI, $order, $options);
    }

    /**
     * APP 支付下单。
     *
     * V3：POST /v3/pay/transactions/app。
     * V2：/pay/unifiedorder + trade_type=APP。
     *
     * @param array<string, mixed> $order 官方业务参数
     * @param array<string, mixed> $options 调用选项
     * @return array<string, mixed> 下单响应与 APP 调起参数
     */
    public function appPay(array $order, array $options = []): array
    {
        return $this->prepay(self::PRODUCT_APP, $order, $options);
    }

    /**
     * H5 支付下单。
     *
     * V3：POST /v3/pay/transactions/h5，成功返回 h5_url。
     * V2：/pay/unifiedorder + trade_type=MWEB，成功返回 mweb_url。
     *
     * @param array<string, mixed> $order 官方业务参数
     * @param array<string, mixed> $options 调用选项
     * @return array<string, mixed> 下单响应与 H5 跳转地址
     */
    public function h5Pay(array $order, array $options = []): array
    {
        return $this->prepay(self::PRODUCT_H5, $order, $options);
    }

    /**
     * Native 支付下单。
     *
     * V3：POST /v3/pay/transactions/native，成功返回 code_url。
     * V2：/pay/unifiedorder + trade_type=NATIVE，成功返回 code_url。
     *
     * @param array<string, mixed> $order 官方业务参数
     * @param array<string, mixed> $options 调用选项
     * @return array<string, mixed> 下单响应与二维码内容
     */
    public function nativePay(array $order, array $options = []): array
    {
        return $this->prepay(self::PRODUCT_NATIVE, $order, $options);
    }

    /**
     * 按产品下单。
     *
     * @param string $product 产品标识
     * @param array<string, mixed> $order 官方业务参数
     * @param array<string, mixed> $options 调用选项
     * @return array<string, mixed> 下单响应与调起参数
     */
    public function prepay(string $product, array $order, array $options = []): array
    {
        $product = $this->normalizeProduct($product);

        return $this->config->isV3()
            ? $this->prepayV3($product, $order, $options)
            : $this->prepayV2($product, $order, $options);
    }

    /**
     * 根据商户订单号查询订单。
     *
     * @param string $outTradeNo 商户订单号
     * @param array<string, mixed> $options 调用选项
     * @return WxpayResponse 微信支付响应
     */
    public function queryByOutTradeNo(string $outTradeNo, array $options = []): WxpayResponse
    {
        if ($this->config->isV3()) {
            $path = $this->v3TransactionBasePath() . '/out-trade-no/' . rawurlencode($outTradeNo);

            return $this->requestV3('GET', $path, [], $this->v3QueryIdentity($options));
        }

        $params = $this->v2BaseParams($options);
        $params['out_trade_no'] = $outTradeNo;

        return $this->requestV2('/pay/orderquery', $params);
    }

    /**
     * 根据微信支付订单号查询订单。
     *
     * @param string $transactionId 微信支付订单号
     * @param array<string, mixed> $options 调用选项
     * @return WxpayResponse 微信支付响应
     */
    public function queryByTransactionId(string $transactionId, array $options = []): WxpayResponse
    {
        if ($this->config->isV3()) {
            $path = $this->v3TransactionBasePath() . '/id/' . rawurlencode($transactionId);

            return $this->requestV3('GET', $path, [], $this->v3QueryIdentity($options));
        }

        $params = $this->v2BaseParams($options);
        $params['transaction_id'] = $transactionId;

        return $this->requestV2('/pay/orderquery', $params);
    }

    /**
     * 关闭订单。
     *
     * @param string $outTradeNo 商户订单号
     * @param array<string, mixed> $options 调用选项
     * @return WxpayResponse 微信支付响应
     */
    public function close(string $outTradeNo, array $options = []): WxpayResponse
    {
        if ($this->config->isV3()) {
            $path = $this->v3TransactionBasePath() . '/out-trade-no/' . rawurlencode($outTradeNo) . '/close';

            return $this->requestV3('POST', $path, $this->v3CloseIdentity($options));
        }

        $params = $this->v2BaseParams($options);
        $params['out_trade_no'] = $outTradeNo;

        return $this->requestV2('/pay/closeorder', $params);
    }

    /**
     * 发起退款。
     *
     * V3 官方参数通常包括 out_trade_no/transaction_id、out_refund_no、amount。
     * V2 官方参数通常包括 out_trade_no/transaction_id、out_refund_no、total_fee、refund_fee。
     *
     * @param array<string, mixed> $refund 官方退款参数
     * @param array<string, mixed> $options 调用选项
     * @return WxpayResponse 微信支付响应
     */
    public function refund(array $refund, array $options = []): WxpayResponse
    {
        if ($this->config->isV3()) {
            $body = $refund;
            if ($this->config->isPartner() && !isset($body['sub_mchid'])) {
                $body['sub_mchid'] = (string) ($options['sub_mch_id'] ?? $this->config->subMchId());
            }

            return $this->requestV3('POST', '/v3/refund/domestic/refunds', $body);
        }

        $params = $refund + $this->v2BaseParams($options);

        return $this->requestV2('/secapi/pay/refund', $params, true);
    }

    /**
     * 查询退款。
     *
     * @param string $outRefundNo 商户退款单号
     * @param array<string, mixed> $options 调用选项
     * @return WxpayResponse 微信支付响应
     */
    public function queryRefund(string $outRefundNo, array $options = []): WxpayResponse
    {
        if ($this->config->isV3()) {
            $query = [];
            if ($this->config->isPartner()) {
                $query['sub_mchid'] = (string) ($options['sub_mch_id'] ?? $this->config->subMchId());
            }

            return $this->requestV3(
                'GET',
                '/v3/refund/domestic/refunds/' . rawurlencode($outRefundNo),
                [],
                $query
            );
        }

        $params = $this->v2BaseParams($options);
        $params['out_refund_no'] = $outRefundNo;

        return $this->requestV2('/pay/refundquery', $params);
    }

    /**
     * 验证并解析 V3 回调通知。
     *
     * @param array<string, mixed> $headers 请求头
     * @param string $body 原始通知体
     * @return array<string, mixed> 解密后的通知数据
     */
    public function parseV3Notify(array $headers, string $body): array
    {
        if (!$this->verifyV3Message($headers, $body)) {
            throw new WxpaySdkException('微信支付 V3 通知验签失败');
        }

        if ($this->config->apiV3Key() === '') {
            throw new WxpaySdkException('微信支付 V3 通知解密必须配置 api_v3_key');
        }

        $payload = json_decode($body, true);
        if (!is_array($payload)) {
            throw new WxpaySdkException('微信支付 V3 通知不是有效 JSON');
        }

        $resource = $payload['resource'] ?? [];
        if (!is_array($resource)) {
            throw new WxpaySdkException('微信支付 V3 通知缺少 resource');
        }

        return WxpaySigner::decryptResource($resource, $this->config->apiV3Key());
    }

    /**
     * 验证 V3 响应或通知签名。
     *
     * @param array<string, mixed> $headers 响应头或请求头
     * @param string $body 原始消息体
     * @return bool 是否验签通过
     */
    public function verifyV3Message(array $headers, string $body): bool
    {
        $publicKeyOrCert = $this->config->platformPublicKeyOrCert();
        if ($publicKeyOrCert === '') {
            return false;
        }

        $timestamp = $this->headerValue($headers, 'Wechatpay-Timestamp');
        $nonce = $this->headerValue($headers, 'Wechatpay-Nonce');
        $signature = $this->headerValue($headers, 'Wechatpay-Signature');
        if ($timestamp === '' || $nonce === '' || $signature === '') {
            return false;
        }

        return WxpaySigner::verifyV3($timestamp, $nonce, $body, $signature, $publicKeyOrCert);
    }

    /**
     * 验证 V2 XML 通知。
     *
     * @param string $xml 原始 XML 通知体
     * @return array<string, string> 验签通过后的通知数组
     */
    public function parseV2Notify(string $xml): array
    {
        $data = WxpayXml::decode($xml);
        if (!WxpaySigner::verifyV2($data, $this->config->apiKey())) {
            throw new WxpaySdkException('微信支付 V2 通知验签失败');
        }

        return $data;
    }

    /**
     * 发送 V3 请求。
     *
     * @param string $method HTTP 方法
     * @param string $path 请求路径
     * @param array<string, mixed> $body 请求体
     * @param array<string, mixed> $query Query 参数
     * @return WxpayResponse 微信支付响应
     */
    public function requestV3(string $method, string $path, array $body = [], array $query = []): WxpayResponse
    {
        $method = strtoupper($method);
        $body = $this->filterEmpty($body);
        $bodyJson = $method === 'GET' ? '' : $this->jsonEncode($body);
        $pathWithQuery = $this->pathWithQuery($path, $query);
        $authorization = WxpaySigner::v3Authorization(
            $this->config->mchId(),
            $this->config->serialNo(),
            $method,
            $pathWithQuery,
            $bodyJson,
            $this->config->privateKey()
        );

        $requestOptions = [
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'Authorization' => $authorization,
                'User-Agent' => 'MPAY-Wxpay-Light-SDK',
            ],
        ];
        if ($method !== 'GET') {
            $requestOptions['body'] = $bodyJson;
        }

        try {
            $httpResponse = $this->httpClient()->request(
                $method,
                $this->config->gatewayV3() . $pathWithQuery,
                $requestOptions
            );
        } catch (GuzzleException $e) {
            throw new WxpaySdkException('微信支付 V3 请求失败：' . $e->getMessage(), previous: $e);
        }

        $rawBody = (string) $httpResponse->getBody();
        $data = $rawBody === '' ? [] : json_decode($rawBody, true);
        if (!is_array($data)) {
            throw new WxpaySdkException('微信支付 V3 响应不是有效 JSON');
        }

        $response = new WxpayResponse(
            self::API_VERSION_V3,
            $method,
            $pathWithQuery,
            $httpResponse->getStatusCode(),
            $rawBody,
            $data,
            $httpResponse->getHeaders()
        );

        if ($this->config->verifyResponse() && $this->config->platformPublicKeyOrCert() !== '') {
            if (!$this->verifyV3Message($httpResponse->getHeaders(), $rawBody)) {
                throw new WxpaySdkException('微信支付 V3 响应验签失败');
            }
        }

        return $response;
    }

    /**
     * 发送 V2 请求。
     *
     * @param string $path 请求路径
     * @param array<string, mixed> $params XML 参数
     * @param bool $requiresCert 是否需要双向证书
     * @return WxpayResponse 微信支付响应
     */
    public function requestV2(string $path, array $params, bool $requiresCert = false): WxpayResponse
    {
        $path = $this->v2Path($path);
        $params = $this->filterEmpty($params);
        $params['sign'] = WxpaySigner::signV2($params, $this->config->apiKey(), (string) ($params['sign_type'] ?? $this->config->v2SignType()));
        $xml = WxpayXml::encode($params);

        $requestOptions = [
            'body' => $xml,
            'headers' => [
                'Content-Type' => 'text/xml; charset=UTF-8',
                'User-Agent' => 'MPAY-Wxpay-Light-SDK',
            ],
        ];
        if ($requiresCert) {
            $certPath = $this->config->certPath();
            $keyPath = $this->config->keyPath();
            if ($certPath === '' || $keyPath === '') {
                throw new WxpaySdkException('微信支付 V2 双向证书接口必须配置 cert_path 和 key_path');
            }
            $requestOptions['cert'] = $certPath;
            $requestOptions['ssl_key'] = $keyPath;
        }

        try {
            $httpResponse = $this->httpClient()->request(
                'POST',
                $this->config->gatewayV2() . $path,
                $requestOptions
            );
        } catch (GuzzleException $e) {
            throw new WxpaySdkException('微信支付 V2 请求失败：' . $e->getMessage(), previous: $e);
        }

        $rawBody = (string) $httpResponse->getBody();
        $data = WxpayXml::decode($rawBody);

        return new WxpayResponse(
            self::API_VERSION_V2,
            'POST',
            $path,
            $httpResponse->getStatusCode(),
            $rawBody,
            $data,
            $httpResponse->getHeaders()
        );
    }

    /**
     * V3 支付下单。
     *
     * @param string $product 产品标识
     * @param array<string, mixed> $order 官方业务参数
     * @param array<string, mixed> $options 调用选项
     * @return array<string, mixed> 下单结果
     */
    private function prepayV3(string $product, array $order, array $options): array
    {
        $body = $this->v3OrderWithDefaults($order, $options);
        $type = $this->v3ProductType($product);
        $response = $this->requestV3('POST', $this->v3PrepayPath($type), $body);
        $data = $response->data();

        return $this->buildPrepayResult($product, self::API_VERSION_V3, $data, $response, $body);
    }

    /**
     * V2 统一下单。
     *
     * @param string $product 产品标识
     * @param array<string, mixed> $order 官方业务参数
     * @param array<string, mixed> $options 调用选项
     * @return array<string, mixed> 下单结果
     */
    private function prepayV2(string $product, array $order, array $options): array
    {
        $params = $order + $this->v2BaseParams($options);
        $params['trade_type'] = $this->v2TradeType($product);
        $response = $this->requestV2('/pay/unifiedorder', $params);
        $data = $response->data();

        return $this->buildPrepayResult($product, self::API_VERSION_V2, $data, $response, $params);
    }

    /**
     * 构建统一下单返回结构。
     *
     * @param string $product 产品标识
     * @param string $apiVersion API 版本
     * @param array<string, mixed> $data 微信支付响应数据
     * @param WxpayResponse $response 响应对象
     * @param array<string, mixed> $request 请求数据
     * @return array<string, mixed> 统一返回
     */
    private function buildPrepayResult(
        string $product,
        string $apiVersion,
        array $data,
        WxpayResponse $response,
        array $request
    ): array {
        $payParams = match ($product) {
            self::PRODUCT_JSAPI => $this->buildJsapiPayParams($apiVersion, $data, $request, false),
            self::PRODUCT_MINI => $this->buildJsapiPayParams($apiVersion, $data, $request, true),
            self::PRODUCT_APP => $this->buildAppPayParams($apiVersion, $data, $request),
            self::PRODUCT_H5 => $this->buildH5PayParams($apiVersion, $data),
            self::PRODUCT_NATIVE => $this->buildNativePayParams($data),
            default => [],
        };

        return [
            'api_version' => $apiVersion,
            'product' => $product,
            'success' => $response->success(),
            'pay_params' => $payParams,
            'data' => $data,
            'response' => $response->toArray(),
        ];
    }

    /**
     * 构建 JSAPI/小程序调起参数。
     *
     * @param string $apiVersion API 版本
     * @param array<string, mixed> $data 下单响应
     * @param array<string, mixed> $request 下单请求
     * @param bool $miniProgram 是否小程序
     * @return array<string, mixed> 调起参数
     */
    private function buildJsapiPayParams(string $apiVersion, array $data, array $request, bool $miniProgram): array
    {
        $prepayId = (string) ($data['prepay_id'] ?? '');
        if ($prepayId === '') {
            return [];
        }

        $timeStamp = (string) time();
        $nonceStr = WxpaySigner::nonceStr();
        $package = 'prepay_id=' . $prepayId;
        if ($apiVersion === self::API_VERSION_V3) {
            $paySign = WxpaySigner::jsapiPaySign(
                $this->frontendAppId($request),
                $timeStamp,
                $nonceStr,
                $package,
                $this->config->privateKey()
            );
            $signType = 'RSA';
        } else {
            $signType = $this->config->v2SignType();
            $paySign = WxpaySigner::signV2([
                'appId' => $this->frontendAppId($request),
                'timeStamp' => $timeStamp,
                'nonceStr' => $nonceStr,
                'package' => $package,
                'signType' => $signType,
            ], $this->config->apiKey(), $signType);
        }

        $params = [
            'timeStamp' => $timeStamp,
            'nonceStr' => $nonceStr,
            'package' => $package,
            'signType' => $signType,
            'paySign' => $paySign,
        ];

        return $miniProgram
            ? ['app_id' => $this->frontendAppId($request), 'request_payment' => $params]
            : ['appId' => $this->frontendAppId($request)] + $params;
    }

    /**
     * 构建 APP 调起参数。
     *
     * @param string $apiVersion API 版本
     * @param array<string, mixed> $data 下单响应
     * @param array<string, mixed> $request 下单请求
     * @return array<string, mixed> APP 调起参数
     */
    private function buildAppPayParams(string $apiVersion, array $data, array $request): array
    {
        $prepayId = (string) ($data['prepay_id'] ?? '');
        if ($prepayId === '') {
            return [];
        }

        $timeStamp = (string) time();
        $nonceStr = WxpaySigner::nonceStr();
        if ($apiVersion === self::API_VERSION_V3) {
            $sign = WxpaySigner::appPaySign(
                $this->frontendAppId($request),
                $timeStamp,
                $nonceStr,
                $prepayId,
                $this->config->privateKey()
            );
        } else {
            $sign = WxpaySigner::signV2([
                'appid' => $this->frontendAppId($request),
                'partnerid' => $this->config->mchId(),
                'prepayid' => $prepayId,
                'package' => 'Sign=WXPay',
                'noncestr' => $nonceStr,
                'timestamp' => $timeStamp,
            ], $this->config->apiKey(), $this->config->v2SignType());
        }

        return [
            'appId' => $this->frontendAppId($request),
            'partnerId' => $this->config->mchId(),
            'prepayId' => $prepayId,
            'packageValue' => 'Sign=WXPay',
            'nonceStr' => $nonceStr,
            'timeStamp' => $timeStamp,
            'sign' => $sign,
        ];
    }

    /**
     * 构建 H5 支付跳转参数。
     *
     * @param string $apiVersion API 版本
     * @param array<string, mixed> $data 下单响应
     * @return array<string, mixed> H5 跳转参数
     */
    private function buildH5PayParams(string $apiVersion, array $data): array
    {
        $url = $apiVersion === self::API_VERSION_V3
            ? (string) ($data['h5_url'] ?? '')
            : (string) ($data['mweb_url'] ?? '');

        return $url === '' ? [] : ['url' => $url, 'h5_url' => $url];
    }

    /**
     * 构建 Native 支付二维码参数。
     *
     * @param array<string, mixed> $data 下单响应
     * @return array<string, mixed> 二维码参数
     */
    private function buildNativePayParams(array $data): array
    {
        $codeUrl = (string) ($data['code_url'] ?? '');

        return $codeUrl === '' ? [] : ['code_url' => $codeUrl];
    }

    /**
     * 生成 V3 下单请求默认字段。
     *
     * @param array<string, mixed> $order 原始订单参数
     * @param array<string, mixed> $options 调用选项
     * @return array<string, mixed> 合并后的订单参数
     */
    private function v3OrderWithDefaults(array $order, array $options): array
    {
        if ($this->config->isPartner()) {
            $defaults = [
                'sp_appid' => (string) ($options['sp_appid'] ?? $this->config->appId()),
                'sp_mchid' => (string) ($options['sp_mch_id'] ?? $this->config->mchId()),
                'sub_mchid' => (string) ($options['sub_mch_id'] ?? $this->config->subMchId()),
            ];
            $subAppId = (string) ($options['sub_appid'] ?? $this->config->subAppId());
            if ($subAppId !== '') {
                $defaults['sub_appid'] = $subAppId;
            }

            return $this->filterEmpty($order + $defaults);
        }

        return $this->filterEmpty($order + [
            'appid' => (string) ($options['appid'] ?? $this->config->appId()),
            'mchid' => (string) ($options['mch_id'] ?? $this->config->mchId()),
        ]);
    }

    /**
     * 生成 V2 基础参数。
     *
     * @param array<string, mixed> $options 调用选项
     * @return array<string, mixed> 基础参数
     */
    private function v2BaseParams(array $options): array
    {
        $params = [
            'appid' => (string) ($options['appid'] ?? $this->config->appId()),
            'mch_id' => (string) ($options['mch_id'] ?? $this->config->mchId()),
            'nonce_str' => (string) ($options['nonce_str'] ?? WxpaySigner::nonceStr()),
            'sign_type' => (string) ($options['sign_type'] ?? $this->config->v2SignType()),
        ];

        if ($this->config->isPartner()) {
            $params['sub_mch_id'] = (string) ($options['sub_mch_id'] ?? $this->config->subMchId());
            $subAppId = (string) ($options['sub_appid'] ?? $this->config->subAppId());
            if ($subAppId !== '') {
                $params['sub_appid'] = $subAppId;
            }
        }

        return $this->filterEmpty($params);
    }

    /**
     * 获取 V3 交易路径基础部分。
     *
     * @return string 路径基础部分
     */
    private function v3TransactionBasePath(): string
    {
        return $this->config->isPartner()
            ? '/v3/pay/partner/transactions'
            : '/v3/pay/transactions';
    }

    /**
     * 获取 V3 下单路径。
     *
     * @param string $type V3 产品类型
     * @return string 下单路径
     */
    private function v3PrepayPath(string $type): string
    {
        return $this->v3TransactionBasePath() . '/' . $type;
    }

    /**
     * 获取 V3 查询身份参数。
     *
     * @param array<string, mixed> $options 调用选项
     * @return array<string, string> Query 身份参数
     */
    private function v3QueryIdentity(array $options): array
    {
        if ($this->config->isPartner()) {
            return [
                'sp_mchid' => (string) ($options['sp_mch_id'] ?? $this->config->mchId()),
                'sub_mchid' => (string) ($options['sub_mch_id'] ?? $this->config->subMchId()),
            ];
        }

        return [
            'mchid' => (string) ($options['mch_id'] ?? $this->config->mchId()),
        ];
    }

    /**
     * 获取 V3 关单身份参数。
     *
     * @param array<string, mixed> $options 调用选项
     * @return array<string, string> 请求体身份参数
     */
    private function v3CloseIdentity(array $options): array
    {
        return $this->v3QueryIdentity($options);
    }

    /**
     * 获取 V3 产品路径类型。
     *
     * @param string $product 产品标识
     * @return string V3 产品路径类型
     */
    private function v3ProductType(string $product): string
    {
        return match ($product) {
            self::PRODUCT_JSAPI, self::PRODUCT_MINI => 'jsapi',
            self::PRODUCT_APP => 'app',
            self::PRODUCT_H5 => 'h5',
            self::PRODUCT_NATIVE => 'native',
            default => throw new WxpaySdkException('不支持的微信支付产品：' . $product),
        };
    }

    /**
     * 获取 V2 trade_type。
     *
     * @param string $product 产品标识
     * @return string V2 trade_type
     */
    private function v2TradeType(string $product): string
    {
        return match ($product) {
            self::PRODUCT_JSAPI, self::PRODUCT_MINI => self::V2_TRADE_TYPE_JSAPI,
            self::PRODUCT_APP => self::V2_TRADE_TYPE_APP,
            self::PRODUCT_H5 => self::V2_TRADE_TYPE_H5,
            self::PRODUCT_NATIVE => self::V2_TRADE_TYPE_NATIVE,
            default => throw new WxpaySdkException('不支持的微信支付产品：' . $product),
        };
    }

    /**
     * 标准化产品标识。
     *
     * @param string $product 原始产品标识
     * @return string 标准产品标识
     */
    private function normalizeProduct(string $product): string
    {
        $product = strtolower(trim($product));
        $aliases = [
            'mp' => self::PRODUCT_JSAPI,
            'mweb' => self::PRODUCT_H5,
            'wap' => self::PRODUCT_H5,
            'scan' => self::PRODUCT_NATIVE,
            'native' => self::PRODUCT_NATIVE,
            'miniapp' => self::PRODUCT_MINI,
            'miniprogram' => self::PRODUCT_MINI,
        ];

        $product = $aliases[$product] ?? $product;
        if (!in_array($product, [
            self::PRODUCT_JSAPI,
            self::PRODUCT_APP,
            self::PRODUCT_H5,
            self::PRODUCT_NATIVE,
            self::PRODUCT_MINI,
        ], true)) {
            throw new WxpaySdkException('不支持的微信支付产品：' . $product);
        }

        return $product;
    }

    /**
     * 获取前端调起支付使用的 AppID。
     *
     * 服务商模式下优先使用 sub_appid，否则使用 sp_appid。
     *
     * @param array<string, mixed> $request 下单请求
     * @return string 前端 AppID
     */
    private function frontendAppId(array $request): string
    {
        return (string) (
            $request['sub_appid']
            ?? $request['appid']
            ?? $request['sp_appid']
            ?? $this->config->appId()
        );
    }

    /**
     * 获取 V2 实际请求路径。
     *
     * @param string $path 原始路径
     * @return string 实际路径
     */
    private function v2Path(string $path): string
    {
        $path = '/' . ltrim($path, '/');

        return $this->config->sandbox() ? '/sandboxnew' . $path : $path;
    }

    /**
     * 拼接路径和 query string。
     *
     * @param string $path 路径
     * @param array<string, mixed> $query Query 参数
     * @return string 带 query 的路径
     */
    private function pathWithQuery(string $path, array $query): string
    {
        $path = '/' . ltrim($path, '/');
        $query = $this->filterEmpty($query);
        if ($query === []) {
            return $path;
        }

        return $path . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * JSON 编码。
     *
     * @param array<string, mixed> $data 数据
     * @return string JSON 字符串
     */
    private function jsonEncode(array $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new WxpaySdkException('微信支付 JSON 编码失败');
        }

        return $json;
    }

    /**
     * 移除 null 与空字符串字段。
     *
     * @param array<string, mixed> $data 原始数据
     * @return array<string, mixed> 过滤后的数据
     */
    private function filterEmpty(array $data): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            if (is_array($value)) {
                $value = $this->filterEmpty($value);
            }
            $result[$key] = $value;
        }

        return $result;
    }

    /**
     * 获取大小写不敏感的头字段。
     *
     * @param array<string, mixed> $headers 头数组
     * @param string $name 头名称
     * @return string 头字段值
     */
    private function headerValue(array $headers, string $name): string
    {
        foreach ($headers as $key => $value) {
            if (strcasecmp((string) $key, $name) !== 0) {
                continue;
            }
            if (is_array($value)) {
                return (string) ($value[0] ?? '');
            }

            return (string) $value;
        }

        return '';
    }

    /**
     * 获取 HTTP 客户端。
     *
     * @return Client Guzzle HTTP 客户端
     */
    private function httpClient(): Client
    {
        if ($this->httpClient === null) {
            $this->httpClient = new Client([
                'timeout' => $this->config->timeout(),
                'connect_timeout' => $this->config->connectTimeout(),
                'http_errors' => false,
                'verify' => $this->config->verifyPeer(),
            ]);
        }

        return $this->httpClient;
    }
}
