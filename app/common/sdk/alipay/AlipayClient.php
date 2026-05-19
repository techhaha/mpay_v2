<?php

declare(strict_types=1);

namespace app\common\sdk\alipay;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * 支付宝轻量 OpenAPI 客户端。
 *
 * 设计目标：
 * - 不依赖支付宝官方厚重 SDK，只覆盖 MPAY 当前支付插件需要的基础能力。
 * - 统一处理公共参数、RSA2 加签、证书模式序列号、响应验签和表单/orderString 生成。
 * - SDK 不关心项目支付单、渠道配置、订单状态推进；这些由后续支付插件负责转换。
 *
 * 当前覆盖的支付产品：
 * - 当面付付款码：alipay.trade.pay
 * - 订单码支付：alipay.trade.precreate
 * - JSAPI 支付：alipay.trade.create
 * - APP 支付：alipay.trade.app.pay
 * - 手机网站支付：alipay.trade.wap.pay
 * - 电脑网站支付：alipay.trade.page.pay
 *
 * 当前覆盖的通用交易动作：
 * - 查询订单：alipay.trade.query
 * - 关闭订单：alipay.trade.close
 * - 退款订单：alipay.trade.refund
 * - 退款查询：alipay.trade.fastpay.refund.query
 * - 小程序授权换取用户身份：alipay.system.oauth.token
 */
class AlipayClient
{
    public const METHOD_SYSTEM_OAUTH_TOKEN = 'alipay.system.oauth.token';
    public const METHOD_TRADE_PAY = 'alipay.trade.pay';
    public const METHOD_TRADE_PRECREATE = 'alipay.trade.precreate';
    public const METHOD_TRADE_CREATE = 'alipay.trade.create';
    public const METHOD_TRADE_APP_PAY = 'alipay.trade.app.pay';
    public const METHOD_TRADE_WAP_PAY = 'alipay.trade.wap.pay';
    public const METHOD_TRADE_PAGE_PAY = 'alipay.trade.page.pay';
    public const METHOD_TRADE_QUERY = 'alipay.trade.query';
    public const METHOD_TRADE_CLOSE = 'alipay.trade.close';
    public const METHOD_TRADE_REFUND = 'alipay.trade.refund';
    public const METHOD_TRADE_REFUND_QUERY = 'alipay.trade.fastpay.refund.query';

    public const PRODUCT_FACE_TO_FACE = 'FACE_TO_FACE_PAYMENT';
    public const PRODUCT_QR_CODE = 'QR_CODE_OFFLINE';
    public const PRODUCT_JSAPI = 'JSAPI_PAY';
    public const PRODUCT_APP = 'QUICK_MSECURITY_PAY';
    public const PRODUCT_WAP = 'QUICK_WAP_WAY';
    public const PRODUCT_PAGE = 'FAST_INSTANT_TRADE_PAY';

    /**
     * 支付宝 SDK 配置。
     *
     * @var AlipayConfig
     */
    private AlipayConfig $config;

    /**
     * 网关请求客户端。
     *
     * @var Client|null
     */
    private ?Client $httpClient = null;

    /**
     * 应用公钥证书序列号缓存。
     *
     * @var string|null
     */
    private ?string $appCertSn = null;

    /**
     * 支付宝根证书序列号缓存。
     *
     * @var string|null
     */
    private ?string $alipayRootCertSn = null;

    /**
     * 支付宝验签公钥缓存。
     *
     * 密钥模式直接使用 alipay_public_key，证书模式从支付宝公钥证书中提取。
     *
     * @var string|null
     */
    private ?string $alipayPublicKey = null;

    /**
     * 构造方法。
     *
     * @param AlipayConfig|array<string, mixed> $config 配置对象或配置数组
     */
    public function __construct(AlipayConfig|array $config)
    {
        $this->config = is_array($config) ? AlipayConfig::fromArray($config) : $config;
    }

    /**
     * 当面付：付款码支付。
     *
     * 适用于商家扫描用户付款码的线下收银场景。
     * 必填业务参数通常包括 out_trade_no、subject、total_amount、auth_code。
     * 本方法会默认补充 product_code=FACE_TO_FACE_PAYMENT、scene=bar_code。
     *
     * @param array<string, mixed> $bizContent 业务参数
     * @param array<string, mixed> $options 公共参数覆盖项，如 notify_url、app_auth_token
     * @return AlipayResponse 支付宝响应
     */
    public function faceToFacePay(array $bizContent, array $options = []): AlipayResponse
    {
        $bizContent = $this->withDefaults($bizContent, [
            'product_code' => self::PRODUCT_FACE_TO_FACE,
            'scene' => 'bar_code',
        ]);

        return $this->execute(self::METHOD_TRADE_PAY, $bizContent, $options);
    }

    /**
     * 订单码支付：预创建二维码订单。
     *
     * 适用于用户扫描商家订单二维码完成支付的场景。
     * 支付宝成功响应中通常包含 qr_code，插件可用该字符串生成二维码。
     * 本方法会默认补充 product_code=QR_CODE_OFFLINE。
     *
     * @param array<string, mixed> $bizContent 业务参数
     * @param array<string, mixed> $options 公共参数覆盖项
     * @return AlipayResponse 支付宝响应
     */
    public function precreate(array $bizContent, array $options = []): AlipayResponse
    {
        $bizContent = $this->withDefaults($bizContent, [
            'product_code' => self::PRODUCT_QR_CODE,
        ]);

        return $this->execute(self::METHOD_TRADE_PRECREATE, $bizContent, $options);
    }

    /**
     * JSAPI 支付：创建交易。
     *
     * 适用于支付宝小程序内通过 my.tradePay 拉起收银台。
     * 支付宝成功响应中通常包含 trade_no，前端 my.tradePay 需要使用该值。
     * 本方法会默认补充 product_code=JSAPI_PAY。
     *
     * @param array<string, mixed> $bizContent 业务参数
     * @param array<string, mixed> $options 公共参数覆盖项
     * @return AlipayResponse 支付宝响应
     */
    public function jsapiCreate(array $bizContent, array $options = []): AlipayResponse
    {
        $bizContent = $this->withDefaults($bizContent, [
            'product_code' => self::PRODUCT_JSAPI,
        ]);

        return $this->execute(self::METHOD_TRADE_CREATE, $bizContent, $options);
    }

    /**
     * APP 支付：生成移动端 SDK 使用的 orderString。
     *
     * 该接口不直接请求支付宝网关，而是按支付宝规则生成已签名的参数串。
     * 商户服务端把 order_string 返回给 APP，APP 使用支付宝客户端 SDK 拉起支付。
     * 本方法会默认补充 product_code=QUICK_MSECURITY_PAY。
     *
     * @param array<string, mixed> $bizContent 业务参数
     * @param array<string, mixed> $options 公共参数覆盖项，如 notify_url
     * @return array<string, mixed> order_string、params
     */
    public function appPay(array $bizContent, array $options = []): array
    {
        $bizContent = $this->withDefaults($bizContent, [
            'product_code' => self::PRODUCT_APP,
        ]);

        return $this->sdkExecute(self::METHOD_TRADE_APP_PAY, $bizContent, $options);
    }

    /**
     * 手机网站支付：生成跳转支付宝收银台的表单 HTML。
     *
     * 默认使用 POST 表单提交，调用方可以通过 options.http_method=GET 改为 GET 跳转。
     * 本方法会默认补充 product_code=QUICK_WAP_WAY。
     *
     * @param array<string, mixed> $bizContent 业务参数
     * @param array<string, mixed> $options 公共参数覆盖项，如 notify_url、return_url
     * @return array<string, mixed> method、url、html、params
     */
    public function wapPay(array $bizContent, array $options = []): array
    {
        $bizContent = $this->withDefaults($bizContent, [
            'product_code' => self::PRODUCT_WAP,
        ]);

        return $this->pageExecute(self::METHOD_TRADE_WAP_PAY, $bizContent, $options);
    }

    /**
     * 电脑网站支付：生成跳转支付宝收银台的表单 HTML。
     *
     * 默认使用 POST 表单提交，调用方可以通过 options.http_method=GET 改为 GET 跳转。
     * 本方法会默认补充 product_code=FAST_INSTANT_TRADE_PAY。
     *
     * @param array<string, mixed> $bizContent 业务参数
     * @param array<string, mixed> $options 公共参数覆盖项，如 notify_url、return_url
     * @return array<string, mixed> method、url、html、params
     */
    public function pagePay(array $bizContent, array $options = []): array
    {
        $bizContent = $this->withDefaults($bizContent, [
            'product_code' => self::PRODUCT_PAGE,
        ]);

        return $this->pageExecute(self::METHOD_TRADE_PAGE_PAY, $bizContent, $options);
    }

    /**
     * 查询交易。
     *
     * 常用业务参数为 out_trade_no 或 trade_no，二选一。
     *
     * @param array<string, mixed> $bizContent 业务参数
     * @param array<string, mixed> $options 公共参数覆盖项
     * @return AlipayResponse 支付宝响应
     */
    public function query(array $bizContent, array $options = []): AlipayResponse
    {
        return $this->execute(self::METHOD_TRADE_QUERY, $bizContent, $options);
    }

    /**
     * 关闭交易。
     *
     * 常用业务参数为 out_trade_no 或 trade_no，二选一。
     *
     * @param array<string, mixed> $bizContent 业务参数
     * @param array<string, mixed> $options 公共参数覆盖项
     * @return AlipayResponse 支付宝响应
     */
    public function close(array $bizContent, array $options = []): AlipayResponse
    {
        return $this->execute(self::METHOD_TRADE_CLOSE, $bizContent, $options);
    }

    /**
     * 发起退款。
     *
     * 常用业务参数包括 out_trade_no/trade_no、refund_amount、out_request_no。
     *
     * @param array<string, mixed> $bizContent 业务参数
     * @param array<string, mixed> $options 公共参数覆盖项
     * @return AlipayResponse 支付宝响应
     */
    public function refund(array $bizContent, array $options = []): AlipayResponse
    {
        return $this->execute(self::METHOD_TRADE_REFUND, $bizContent, $options);
    }

    /**
     * 查询退款。
     *
     * 常用业务参数包括 out_trade_no/trade_no、out_request_no。
     *
     * @param array<string, mixed> $bizContent 业务参数
     * @param array<string, mixed> $options 公共参数覆盖项
     * @return AlipayResponse 支付宝响应
     */
    public function refundQuery(array $bizContent, array $options = []): AlipayResponse
    {
        return $this->execute(self::METHOD_TRADE_REFUND_QUERY, $bizContent, $options);
    }

    /**
     * 小程序授权：使用 my.getAuthCode 返回的授权码换取用户身份。
     *
     * 支付宝 JSAPI 支付需要在 alipay.trade.create 中传 buyer_id 或 buyer_open_id。
     * 小程序端先调用 my.getAuthCode，本方法再通过 alipay.system.oauth.token
     * 换取 user_id/open_id，插件层会分别映射为 buyer_id/buyer_open_id。
     *
     * @param string $authCode 小程序 my.getAuthCode 返回的 authCode
     * @param array<string, mixed> $options 公共参数覆盖项
     * @return AlipayResponse 支付宝响应
     */
    public function oauthToken(string $authCode, array $options = []): AlipayResponse
    {
        return $this->executeParams(self::METHOD_SYSTEM_OAUTH_TOKEN, [
            'grant_type' => 'authorization_code',
            'code' => $authCode,
        ], $options);
    }

    /**
     * 调用支付宝普通 OpenAPI。
     *
     * 适用于请求后支付宝直接返回 JSON 响应的接口，例如支付、预创建、查询、关单、退款。
     *
     * @param string $method 支付宝接口方法名
     * @param array<string, mixed> $bizContent 业务参数
     * @param array<string, mixed> $options 公共参数覆盖项
     * @return AlipayResponse 支付宝响应对象
     */
    public function execute(string $method, array $bizContent, array $options = []): AlipayResponse
    {
        $params = $this->signedParams($method, $bizContent, $options);
        $rawBody = $this->post($params);
        $decoded = json_decode($rawBody, true);
        if (!is_array($decoded)) {
            throw new AlipaySdkException('支付宝响应不是有效 JSON');
        }

        $verified = $this->verifyGatewayResponse($method, $rawBody, $decoded);

        return new AlipayResponse($method, $rawBody, $decoded, $verified);
    }

    /**
     * 调用非 biz_content 形态的支付宝 OpenAPI。
     *
     * 例如 alipay.system.oauth.token 的业务参数直接位于公共参数同级，
     * 不能包进 biz_content，否则支付宝会判定参数缺失。
     *
     * @param string $method 支付宝接口方法名
     * @param array<string, mixed> $apiParams 接口业务参数
     * @param array<string, mixed> $options 公共参数覆盖项
     * @return AlipayResponse 支付宝响应对象
     */
    public function executeParams(string $method, array $apiParams, array $options = []): AlipayResponse
    {
        $params = $this->signedApiParams($method, $apiParams, $options);
        $rawBody = $this->post($params);
        $decoded = json_decode($rawBody, true);
        if (!is_array($decoded)) {
            throw new AlipaySdkException('支付宝响应不是有效 JSON');
        }

        $verified = $this->verifyGatewayResponse($method, $rawBody, $decoded);

        return new AlipayResponse($method, $rawBody, $decoded, $verified);
    }

    /**
     * 生成移动端 SDK 使用的已签名参数串。
     *
     * 对应官方 SDK 的 sdkExecute 形态，主要用于 alipay.trade.app.pay。
     *
     * @param string $method 支付宝接口方法名
     * @param array<string, mixed> $bizContent 业务参数
     * @param array<string, mixed> $options 公共参数覆盖项
     * @return array<string, mixed> order_string、params
     */
    public function sdkExecute(string $method, array $bizContent, array $options = []): array
    {
        $params = $this->signedParams($method, $bizContent, $options);
        $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);

        return [
            'order_string' => $query,
            'params' => $params,
        ];
    }

    /**
     * 生成网页支付表单。
     *
     * 对应官方 SDK 的 pageExecute 形态，主要用于 wap.pay 和 page.pay。
     *
     * @param string $method 支付宝接口方法名
     * @param array<string, mixed> $bizContent 业务参数
     * @param array<string, mixed> $options 公共参数覆盖项
     * @return array<string, mixed> method、url、html、params
     */
    public function pageExecute(string $method, array $bizContent, array $options = []): array
    {
        $httpMethod = strtoupper((string) ($options['http_method'] ?? 'POST'));
        $params = $this->signedParams($method, $bizContent, $options);
        $url = $this->config->gateway() . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $html = $httpMethod === 'GET'
            ? $this->buildGetRedirectHtml($url)
            : $this->buildPostFormHtml($this->config->gateway(), $params);

        return [
            'method' => $httpMethod,
            'url' => $url,
            'html' => $html,
            'params' => $params,
        ];
    }

    /**
     * 验证支付宝异步通知签名。
     *
     * 通知验签规则与请求签名不同：待验签参数需要排除 sign 和 sign_type。
     *
     * @param array<string, mixed> $params 通知参数
     * @return bool 是否验签通过
     */
    public function verifyNotify(array $params): bool
    {
        $sign = (string) ($params['sign'] ?? '');
        if ($sign === '') {
            return false;
        }

        $content = AlipaySigner::signContent($params, true);

        return AlipaySigner::verify($content, $sign, $this->alipayPublicKey());
    }

    /**
     * 解析支付宝异步通知。
     *
     * 本方法只做 SDK 层可信解析，不推进支付单状态。
     * 后续支付插件仍需校验 out_trade_no、total_amount、trade_status 等业务字段。
     *
     * @param array<string, mixed> $params 通知参数
     * @param bool $checkAppId 是否校验 app_id 与当前配置一致
     * @return array<string, mixed> 标准化通知摘要
     */
    public function parseNotify(array $params, bool $checkAppId = true): array
    {
        if (!$this->verifyNotify($params)) {
            throw new AlipaySdkException('支付宝异步通知验签失败');
        }
        if ($checkAppId && (string) ($params['app_id'] ?? '') !== $this->config->appId()) {
            throw new AlipaySdkException('支付宝异步通知 app_id 不匹配');
        }

        return [
            'verified' => true,
            'app_id' => (string) ($params['app_id'] ?? ''),
            'out_trade_no' => (string) ($params['out_trade_no'] ?? ''),
            'trade_no' => (string) ($params['trade_no'] ?? ''),
            'trade_status' => (string) ($params['trade_status'] ?? ''),
            'total_amount' => (string) ($params['total_amount'] ?? ''),
            'receipt_amount' => (string) ($params['receipt_amount'] ?? ''),
            'buyer_id' => (string) ($params['buyer_id'] ?? ''),
            'buyer_open_id' => (string) ($params['buyer_open_id'] ?? ''),
            'gmt_payment' => (string) ($params['gmt_payment'] ?? ''),
            'raw' => $params,
        ];
    }

    /**
     * 生成已签名的支付宝请求参数。
     *
     * 该方法对外开放，方便插件调试、单元测试，或对未封装的新接口临时调用。
     *
     * @param string $method 支付宝接口方法名
     * @param array<string, mixed> $bizContent 业务参数
     * @param array<string, mixed> $options 公共参数覆盖项
     * @return array<string, string> 已签名参数
     */
    public function signedParams(string $method, array $bizContent, array $options = []): array
    {
        $params = $this->systemParams($method, $options);
        $params['biz_content'] = json_encode($bizContent, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($params['biz_content'] === false) {
            throw new AlipaySdkException('支付宝 biz_content JSON 编码失败');
        }

        $params['sign'] = AlipaySigner::sign(
            AlipaySigner::signContent($params),
            $this->config->privateKey()
        );

        return $params;
    }

    /**
     * 生成非 biz_content 形态接口的已签名参数。
     *
     * @param string $method 支付宝接口方法名
     * @param array<string, mixed> $apiParams 接口业务参数
     * @param array<string, mixed> $options 公共参数覆盖项
     * @return array<string, string> 已签名参数
     */
    public function signedApiParams(string $method, array $apiParams, array $options = []): array
    {
        $params = $this->systemParams($method, $options);
        foreach ($apiParams as $key => $value) {
            $text = trim((string) $value);
            if ($text !== '') {
                $params[(string) $key] = $text;
            }
        }

        $params['sign'] = AlipaySigner::sign(
            AlipaySigner::signContent($params),
            $this->config->privateKey()
        );

        return $params;
    }

    /**
     * 生成支付宝公共请求参数。
     *
     * 证书模式会自动计算 app_cert_sn 和 alipay_root_cert_sn。
     *
     * @param string $method 支付宝接口方法名
     * @param array<string, mixed> $options 公共参数覆盖项
     * @return array<string, string> 公共请求参数
     */
    private function systemParams(string $method, array $options): array
    {
        $params = [
            'app_id' => $this->config->appId(),
            'method' => $method,
            'format' => $this->config->format(),
            'charset' => $this->config->charset(),
            'sign_type' => $this->config->signType(),
            'timestamp' => (string) ($options['timestamp'] ?? date('Y-m-d H:i:s')),
            'version' => $this->config->version(),
        ];

        foreach (['notify_url', 'return_url'] as $key) {
            $value = trim((string) ($options[$key] ?? ''));
            if ($value !== '') {
                $params[$key] = $value;
            }
        }

        $appAuthToken = trim((string) ($options['app_auth_token'] ?? $this->config->appAuthToken()));
        if ($appAuthToken !== '') {
            $params['app_auth_token'] = $appAuthToken;
        }

        if ($this->config->isCertMode()) {
            $params['app_cert_sn'] = $this->appCertSn();
            $params['alipay_root_cert_sn'] = $this->alipayRootCertSn();
        }

        return $params;
    }

    /**
     * 以 POST 表单方式请求支付宝网关。
     *
     * @param array<string, string> $params 已签名请求参数
     * @return string 原始响应体
     */
    private function post(array $params): string
    {
        try {
            $response = $this->httpClient()->request('POST', $this->config->gateway(), [
                'body' => http_build_query($params, '', '&', PHP_QUERY_RFC3986),
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded;charset=' . $this->config->charset(),
                ],
            ]);
        } catch (GuzzleException $e) {
            throw new AlipaySdkException('支付宝请求失败：' . $e->getMessage(), previous: $e);
        }

        $httpCode = $response->getStatusCode();
        if ($httpCode < 200 || $httpCode >= 300) {
            throw new AlipaySdkException('支付宝 HTTP 状态异常：' . $httpCode);
        }

        return (string) $response->getBody();
    }

    /**
     * 获取支付宝网关 HTTP 客户端。
     *
     * 当前开发环境使用 phpstudy，暂时关闭 SSL 证书校验，避免本地 CA 根证书缺失阻断沙箱联调。
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
                'verify' => false,
            ]);
        }

        return $this->httpClient;
    }

    /**
     * 验证支付宝网关 JSON 响应签名。
     *
     * 支付宝响应验签使用的是原始 JSON 中 xxx_response 节点对应的 JSON 字符串，
     * 因此这里从原始响应体中截取签名原文，避免重新 json_encode 导致字段顺序或转义差异。
     *
     * @param string $method 支付宝接口方法名
     * @param string $rawBody 原始响应体
     * @param array<string, mixed> $decoded 解码后的响应
     * @return bool 是否验签通过
     */
    private function verifyGatewayResponse(string $method, string $rawBody, array $decoded): bool
    {
        if (!$this->config->verifyResponse()) {
            return false;
        }

        $sign = (string) ($decoded['sign'] ?? '');
        if ($sign === '') {
            if ($this->config->strictResponseSign()) {
                throw new AlipaySdkException('支付宝响应缺少 sign');
            }
            return false;
        }

        $responseKey = str_replace('.', '_', $method) . '_response';
        $content = $this->extractResponseSignContent($rawBody, $responseKey);
        if ($content === '' && isset($decoded['error_response'])) {
            $content = $this->extractResponseSignContent($rawBody, 'error_response');
        }
        if ($content === '') {
            throw new AlipaySdkException('提取支付宝响应验签内容失败');
        }
        if (!AlipaySigner::verify($content, $sign, $this->alipayPublicKey())) {
            throw new AlipaySdkException('支付宝响应验签失败');
        }

        return true;
    }

    /**
     * 从支付宝原始 JSON 中提取响应节点内容。
     *
     * 该方法只做轻量 JSON 片段扫描，目标是保留支付宝返回的原始字节序列用于验签。
     *
     * @param string $rawBody 原始响应体
     * @param string $responseKey 响应节点名
     * @return string 响应节点 JSON 片段
     */
    private function extractResponseSignContent(string $rawBody, string $responseKey): string
    {
        $key = '"' . $responseKey . '"';
        $pos = strpos($rawBody, $key);
        if ($pos === false) {
            return '';
        }

        $colon = strpos($rawBody, ':', $pos + strlen($key));
        if ($colon === false) {
            return '';
        }

        $start = $colon + 1;
        while ($start < strlen($rawBody) && ctype_space($rawBody[$start])) {
            $start++;
        }
        if ($start >= strlen($rawBody) || !in_array($rawBody[$start], ['{', '['], true)) {
            return '';
        }

        $open = $rawBody[$start];
        $close = $open === '{' ? '}' : ']';
        $depth = 0;
        $inString = false;
        $escaped = false;
        $length = strlen($rawBody);

        for ($i = $start; $i < $length; $i++) {
            $char = $rawBody[$i];
            if ($escaped) {
                $escaped = false;
                continue;
            }
            if ($char === '\\' && $inString) {
                $escaped = true;
                continue;
            }
            if ($char === '"') {
                $inString = !$inString;
                continue;
            }
            if ($inString) {
                continue;
            }
            if ($char === $open) {
                $depth++;
            } elseif ($char === $close) {
                $depth--;
                if ($depth === 0) {
                    return substr($rawBody, $start, $i - $start + 1);
                }
            }
        }

        return '';
    }

    /**
     * 获取应用公钥证书序列号。
     *
     * @return string 应用公钥证书序列号
     */
    private function appCertSn(): string
    {
        if ($this->appCertSn === null) {
            $this->appCertSn = AlipayCertificate::appCertSn($this->config->appCertContent());
        }

        return $this->appCertSn;
    }

    /**
     * 获取支付宝根证书序列号。
     *
     * @return string 支付宝根证书序列号
     */
    private function alipayRootCertSn(): string
    {
        if ($this->alipayRootCertSn === null) {
            $this->alipayRootCertSn = AlipayCertificate::alipayRootCertSn($this->config->alipayRootCertContent());
        }

        return $this->alipayRootCertSn;
    }

    /**
     * 获取支付宝验签公钥。
     *
     * @return string 支付宝公钥
     */
    private function alipayPublicKey(): string
    {
        if ($this->alipayPublicKey === null) {
            $this->alipayPublicKey = $this->config->isCertMode()
                ? AlipayCertificate::publicKeyFromCert($this->config->alipayCertContent())
                : $this->config->alipayPublicKey();
        }

        return $this->alipayPublicKey;
    }

    /**
     * 给业务参数补充默认值。
     *
     * 用户显式传入的字段优先级更高，避免覆盖插件特殊场景配置。
     *
     * @param array<string, mixed> $bizContent 原始业务参数
     * @param array<string, mixed> $defaults 默认业务参数
     * @return array<string, mixed> 合并后的业务参数
     */
    private function withDefaults(array $bizContent, array $defaults): array
    {
        return $bizContent + $defaults;
    }

    /**
     * 生成自动提交的 POST 表单 HTML。
     *
     * @param string $action 表单提交地址
     * @param array<string, string> $params 表单参数
     * @return string HTML
     */
    private function buildPostFormHtml(string $action, array $params): string
    {
        $inputs = [];
        foreach ($params as $key => $value) {
            $inputs[] = sprintf(
                '<input type="hidden" name="%s" value="%s">',
                htmlspecialchars($key, ENT_QUOTES, 'UTF-8'),
                htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8')
            );
        }

        return '<form id="alipay_submit_form" name="alipay_submit_form" method="post" action="'
            . htmlspecialchars($action, ENT_QUOTES, 'UTF-8')
            . '">' . implode('', $inputs)
            . '<input type="submit" value="submit" style="display:none"></form>'
            . '<script>document.forms["alipay_submit_form"].submit();</script>';
    }

    /**
     * 生成 GET 跳转 HTML。
     *
     * @param string $url 跳转地址
     * @return string HTML
     */
    private function buildGetRedirectHtml(string $url): string
    {
        return '<script>window.location.href='
            . json_encode($url, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            . ';</script>';
    }
}
