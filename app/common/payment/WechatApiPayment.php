<?php

declare(strict_types=1);

namespace app\common\payment;

use app\common\base\BasePayment;
use app\common\constant\PaymentPluginTypeConstant;
use app\common\constant\EpayProtocolConstant;
use app\common\constant\FileConstant;
use app\common\constant\PaymentPluginStatusConstant;
use app\common\interface\PaymentIdentityRequirementInterface;
use app\common\interface\PaymentInterface;
use app\common\interface\PayPluginInterface;
use app\common\sdk\wxpay\WxpayClient;
use app\common\sdk\wxpay\WxpayResponse;
use app\common\sdk\wxpay\WxpaySdkException;
use app\exception\PaymentException;
use support\Request;
use support\Response;

/**
 * 微信支付官方 API 支付插件。
 *
 * 该插件用于 MPAY 通过微信支付官方接口发起支付请求，底层调用
 * app/common/sdk/wxpay 目录下的轻量 SDK。
 */
class WechatApiPayment extends BasePayment implements PaymentInterface, PayPluginInterface, PaymentIdentityRequirementInterface
{
    private const PRODUCT_MP = 'mp';
    private const PRODUCT_H5 = 'h5';
    private const PRODUCT_APP = 'app';
    private const PRODUCT_MINI = 'mini';
    private const PRODUCT_SCAN = 'scan';

    /**
     * 按产品缓存微信支付 SDK 客户端。
     *
     * @var array<string, WxpayClient>
     */
    private array $clients = [];

    /**
     * 插件元信息和后台配置表单。
     *
     * @var array<string, mixed>
     */
    protected array $paymentInfo = [
        'code' => 'wechat_api',
        'name' => '微信官方API支付',
        'plugin_type' => PaymentPluginTypeConstant::TYPE_DIRECT,
        'author' => 'MPAY',
        'version' => '1.0.0',
        'pay_types' => ['wxpay'],
        'transfer_types' => [],
        'config_schema' => [],
    ];

    /**
     * 获取插件配置结构。
     *
     * 配置人需要明确选择接口版本、接入模式、已开通产品，并按产品补齐 AppID、
     * H5 场景等必要配置。插件下单时会先按环境选择产品，再校验产品是否已勾选。
     *
     * @return array<int, array<string, mixed>>
     */
    public function getConfigSchema(): array
    {
        return [
            [
                'type' => 'radio',
                'field' => 'api_version',
                'title' => '接口版本',
                'value' => WxpayClient::API_VERSION_V3,
                'options' => [
                    ['label' => 'V3接口', 'value' => WxpayClient::API_VERSION_V3],
                    ['label' => 'V2接口', 'value' => WxpayClient::API_VERSION_V2],
                ],
                'control' => [
                    $this->versionControl(WxpayClient::API_VERSION_V3, ['serial_no', 'private_key', 'api_v3_key', 'platform_cert_path']),
                    $this->versionControl(WxpayClient::API_VERSION_V3, ['serial_no', 'private_key', 'api_v3_key', 'platform_cert_path'], 'required'),
                    $this->versionControl(WxpayClient::API_VERSION_V2, ['api_key', 'cert_path', 'key_path', 'sandbox']),
                    $this->versionControl(WxpayClient::API_VERSION_V2, ['api_key'], 'required'),
                ],
                'validate' => [
                    ['required' => true, 'message' => '接口版本不能为空'],
                ],
            ],
            [
                'type' => 'radio',
                'field' => 'mode',
                'title' => '接入模式',
                'value' => 'merchant',
                'options' => [
                    ['label' => '普通商户', 'value' => 'merchant'],
                    ['label' => '服务商', 'value' => 'partner'],
                ],
                'control' => [
                    $this->modeControl('merchant', ['app_id'], 'required'),
                    $this->modeControl('partner', ['sub_mch_id', 'sp_app_id']),
                    $this->modeControl('partner', ['sub_mch_id', 'sp_app_id'], 'required'),
                ],
                'validate' => [
                    ['required' => true, 'message' => '接入模式不能为空'],
                ],
            ],
            [
                'type' => 'input',
                'field' => 'mch_id',
                'title' => '微信支付商户号',
                'value' => '',
                'props' => ['placeholder' => '普通商户填商户号；服务商模式填服务商商户号'],
                'validate' => [
                    ['required' => true, 'message' => '微信支付商户号不能为空'],
                ],
            ],
            [
                'type' => 'input',
                'field' => 'sub_mch_id',
                'title' => '子商户号',
                'value' => '',
                'props' => ['placeholder' => '服务商模式必填'],
            ],
            [
                'type' => 'input',
                'field' => 'sp_app_id',
                'title' => '服务商AppID',
                'value' => '',
                'props' => ['placeholder' => '服务商模式下作为 sp_appid 使用'],
            ],
            [
                'type' => 'input',
                'field' => 'app_id',
                'title' => '默认AppID',
                'value' => '',
                'props' => ['placeholder' => '未配置产品专用 AppID 时使用'],
            ],
            [
                'type' => 'input',
                'field' => 'serial_no',
                'title' => '商户API证书序列号(V3)',
                'value' => '',
            ],
            [
                'type' => 'textarea',
                'field' => 'private_key',
                'title' => '商户API私钥(V3)',
                'value' => '',
                'props' => ['rows' => 5],
            ],
            [
                'type' => 'password',
                'field' => 'api_v3_key',
                'title' => 'APIv3密钥(V3)',
                'value' => '',
            ],
            [
                'type' => 'upload',
                'field' => 'platform_cert_path',
                'title' => '微信支付平台证书(V3)',
                'value' => '',
                'props' => $this->uploadProps('.crt,.cer,.pem'),
            ],
            [
                'type' => 'password',
                'field' => 'api_key',
                'title' => 'API密钥(V2)',
                'value' => '',
            ],
            [
                'type' => 'upload',
                'field' => 'cert_path',
                'title' => '商户API证书文件(V2退款)',
                'value' => '',
                'props' => $this->uploadProps('.crt,.cer,.pem'),
            ],
            [
                'type' => 'upload',
                'field' => 'key_path',
                'title' => '商户API证书私钥文件(V2退款)',
                'value' => '',
                'props' => $this->uploadProps('.key,.pem'),
            ],
            [
                'type' => 'checkbox',
                'field' => 'enabled_products',
                'title' => '已开通微信支付产品',
                'value' => [],
                'options' => $this->productOptions(),
                'control' => [
                    $this->productControl(self::PRODUCT_MP, ['mp_app_id', 'mp_app_secret']),
                    $this->productControl(self::PRODUCT_APP, ['app_app_id']),
                    $this->productControl(self::PRODUCT_MINI, ['mini_app_id', 'mini_app_secret', 'mini_launch_path', 'mini_env_version']),
                    $this->productControl(self::PRODUCT_MINI, ['mini_app_id', 'mini_app_secret'], 'required'),
                    $this->productControl(self::PRODUCT_H5, ['h5_info_type', 'h5_app_name', 'h5_app_url']),
                    $this->productControl(self::PRODUCT_H5, ['h5_info_type'], 'required'),
                ],
                'validate' => [
                    ['required' => true, 'message' => '请至少勾选一个已开通微信支付产品'],
                ],
            ],
            [
                'type' => 'input',
                'field' => 'mp_app_id',
                'title' => '公众号AppID',
                'value' => '',
                'props' => ['placeholder' => 'JSAPI支付使用；为空时使用默认 AppID'],
            ],
            [
                'type' => 'password',
                'field' => 'mp_app_secret',
                'title' => '公众号AppSecret',
                'value' => '',
                'props' => ['placeholder' => '收银台微信内自动授权获取 openid 时使用'],
            ],
            [
                'type' => 'input',
                'field' => 'app_app_id',
                'title' => 'APP应用AppID',
                'value' => '',
                'props' => ['placeholder' => 'APP支付使用；为空时使用默认 AppID'],
            ],
            [
                'type' => 'input',
                'field' => 'mini_app_id',
                'title' => '小程序AppID',
                'value' => '',
                'props' => ['placeholder' => '小程序支付使用；为空时使用默认 AppID'],
            ],
            [
                'type' => 'password',
                'field' => 'mini_app_secret',
                'title' => '小程序AppSecret',
                'value' => '',
                'props' => ['placeholder' => '生成小程序 URL Scheme、code 换 openid 时使用'],
            ],
            [
                'type' => 'input',
                'field' => 'mini_launch_path',
                'title' => '小程序承接页面路径',
                'value' => '',
                'props' => ['placeholder' => '例如 pages/pay/index；留空打开小程序首页'],
            ],
            [
                'type' => 'select',
                'field' => 'mini_env_version',
                'title' => '小程序版本',
                'value' => 'release',
                'options' => [
                    ['label' => '正式版', 'value' => 'release'],
                    ['label' => '体验版', 'value' => 'trial'],
                    ['label' => '开发版', 'value' => 'develop'],
                ],
            ],
            [
                'type' => 'input',
                'field' => 'h5_info_type',
                'title' => 'H5场景类型',
                'value' => 'Wap',
                'props' => ['placeholder' => '例如 Wap、Android、IOS'],
            ],
            [
                'type' => 'input',
                'field' => 'h5_app_name',
                'title' => 'H5应用名称',
                'value' => '',
                'props' => ['placeholder' => '选填；微信支付 H5 场景信息'],
            ],
            [
                'type' => 'input',
                'field' => 'h5_app_url',
                'title' => 'H5网站URL',
                'value' => '',
                'props' => ['placeholder' => '选填；微信支付 H5 场景信息'],
            ],
            [
                'type' => 'switch',
                'field' => 'sandbox',
                'title' => 'V2沙箱环境',
                'value' => false,
            ],
        ];
    }

    /**
     * 初始化插件。
     *
     * 每次通道配置注入后都重置 SDK 客户端缓存，避免复用上一通道的密钥或证书。
     *
     * @param array<string, mixed> $channelConfig 通道配置
     * @return void
     */
    public function init(array $channelConfig): void
    {
        parent::init($channelConfig);
        $this->clients = [];
    }

    /**
     * 声明微信 JSAPI/小程序支付是否需要先获取 openid。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed>|null 身份需求
     */
    public function identityRequirement(array $order): ?array
    {
        $product = $this->resolveIdentityProduct($order);
        if (!in_array($product, [self::PRODUCT_MP, self::PRODUCT_MINI], true)) {
            return null;
        }

        $env = $this->paymentEnv($order);
        $payment = $this->paymentPayload($order);
        $openid = $product === self::PRODUCT_MINI
            ? $this->firstText($payment['mini_openid'] ?? '', $this->openidFromPayload($payment))
            : $this->openidFromPayload($payment);
        if ($openid !== '') {
            return null;
        }

        return [
            'provider' => 'wxpay',
            'product' => $product,
            'auth_type' => $product === self::PRODUCT_MP ? 'wechat_oauth' : 'mini_program',
            'identity_field' => $product === self::PRODUCT_MP ? 'openid' : 'mini_openid',
            'app_id' => $this->productAppId($product),
            'scope' => 'snsapi_base',
            '_app_secret' => $product === self::PRODUCT_MP ? $this->configText('mp_app_secret') : $this->configText('mini_app_secret'),
            'mini_path' => $product === self::PRODUCT_MINI ? $this->configText('mini_launch_path') : '',
            'env_version' => $product === self::PRODUCT_MINI ? $this->miniEnvVersion() : '',
            'mini_launch_type' => $product === self::PRODUCT_MINI
                ? ($env === EpayProtocolConstant::DEVICE_WECHAT ? 'url_link' : 'url_scheme')
                : '',
            'message' => $product === self::PRODUCT_MP
                ? '微信 JSAPI 支付需要先获取当前用户 openid'
                : '微信小程序支付需要先获取当前用户 mini_openid',
        ];
    }

    /**
     * 发起微信支付。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed>
     */
    public function pay(array $order): array
    {
        $products = $this->resolveProducts($order);
        $attempts = [];

        foreach ($products as $index => $product) {
            try {
                return $this->payByProduct($product, $order);
            } catch (PaymentException $e) {
                $attempts[] = [
                    'product' => $product,
                    'message' => $e->getMessage(),
                    'channel_error_code' => (string) (($this->exceptionData($e))['channel_error_code'] ?? ''),
                ];
                if ($index === count($products) - 1 || !$this->shouldFallbackProduct($e)) {
                    $data = $this->exceptionData($e);
                    $data['product_attempts'] = $attempts;
                    throw new PaymentException($e->getMessage(), (int) $e->getCode() ?: 40200, $data);
                }
            }
        }

        throw new PaymentException('当前微信支付通道没有可用支付产品', 40200);
    }

    /**
     * 按指定产品发起支付。
     *
     * @param string $product 产品标识
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed>
     */
    private function payByProduct(string $product, array $order): array
    {
        return match ($product) {
            self::PRODUCT_MP => $this->payMp($order),
            self::PRODUCT_H5 => $this->payH5($order),
            self::PRODUCT_APP => $this->payApp($order),
            self::PRODUCT_MINI => $this->payMini($order),
            self::PRODUCT_SCAN => $this->payScan($order),
            default => throw new PaymentException('不支持的微信支付产品：' . $product, 40200),
        };
    }

    /**
     * JSAPI 支付。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed>
     */
    private function payMp(array $order): array
    {
        $result = $this->callWxpay(fn () => $this->client(self::PRODUCT_MP)->jsapiPay(
            $this->wxOrder(self::PRODUCT_MP, $order)
        ));
        $this->ensurePrepaySuccess($result, '微信 JSAPI 支付下单失败');

        return [
            'pay_page' => 'jsapi',
            'pay_type' => 'wxpay',
            'pay_product' => self::PRODUCT_MP,
            'pay_action' => 'jsapi',
            'pay_params' => $this->withRaw($result['pay_params'] ?? [], $result, '请在微信内打开，页面会自动调起微信支付。'),
            'chan_order_no' => (string) $order['pay_no'],
            'chan_trade_no' => '',
        ];
    }

    /**
     * H5 支付。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed>
     */
    private function payH5(array $order): array
    {
        $result = $this->callWxpay(fn () => $this->client(self::PRODUCT_H5)->h5Pay(
            $this->wxOrder(self::PRODUCT_H5, $order)
        ));
        $this->ensurePrepaySuccess($result, '微信 H5 支付下单失败');

        $url = (string) (($result['pay_params'] ?? [])['url'] ?? '');
        if ($url === '') {
            throw new PaymentException('微信 H5 支付未返回 h5_url', 40200, ['result' => $result]);
        }

        return [
            'pay_page' => 'jump',
            'pay_type' => 'wxpay',
            'pay_product' => self::PRODUCT_H5,
            'pay_action' => 'jump',
            'pay_params' => [
                'url' => $url,
                'description' => '正在跳转微信 H5 支付。',
                'raw' => $result,
            ],
            'chan_order_no' => (string) $order['pay_no'],
            'chan_trade_no' => '',
        ];
    }

    /**
     * APP 支付。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed>
     */
    private function payApp(array $order): array
    {
        $result = $this->callWxpay(fn () => $this->client(self::PRODUCT_APP)->appPay(
            $this->wxOrder(self::PRODUCT_APP, $order)
        ));
        $this->ensurePrepaySuccess($result, '微信 APP 支付下单失败');

        return [
            'pay_page' => 'page',
            'pay_type' => 'wxpay',
            'pay_product' => self::PRODUCT_APP,
            'pay_action' => 'app',
            'pay_params' => [
                '_page' => 'page',
                'params' => $result['pay_params'] ?? [],
                'description' => 'APP 支付仅供原生商户 App 调用微信支付 SDK 使用，网页收银台不会自动唤起。',
                'raw' => $result,
            ],
            'chan_order_no' => (string) $order['pay_no'],
            'chan_trade_no' => '',
        ];
    }

    /**
     * 小程序支付。
     *
     * 当前收银台先预留独立承接页标识，由后续小程序容器页面实现 wx.requestPayment。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed>
     */
    private function payMini(array $order): array
    {
        $result = $this->callWxpay(fn () => $this->client(self::PRODUCT_MINI)->miniPay(
            $this->wxOrder(self::PRODUCT_MINI, $order)
        ));
        $this->ensurePrepaySuccess($result, '微信小程序支付下单失败');

        return [
            'pay_page' => 'page',
            'pay_type' => 'wxpay',
            'pay_product' => self::PRODUCT_MINI,
            'pay_action' => 'mini',
            'pay_params' => [
                '_page' => 'wechatMini',
                'request_payment' => (array) (($result['pay_params'] ?? [])['request_payment'] ?? []),
                'app_id' => (string) (($result['pay_params'] ?? [])['app_id'] ?? $this->productAppId(self::PRODUCT_MINI)),
                'description' => '小程序支付参数已生成，请在小程序容器中调用 wx.requestPayment。',
                'raw' => $result,
            ],
            'chan_order_no' => (string) $order['pay_no'],
            'chan_trade_no' => '',
        ];
    }

    /**
     * Native 支付。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed>
     */
    private function payScan(array $order): array
    {
        $result = $this->callWxpay(fn () => $this->client(self::PRODUCT_SCAN)->nativePay(
            $this->wxOrder(self::PRODUCT_SCAN, $order)
        ));
        $this->ensurePrepaySuccess($result, '微信 Native 支付下单失败');

        $qrcode = (string) (($result['pay_params'] ?? [])['code_url'] ?? '');
        if ($qrcode === '') {
            throw new PaymentException('微信 Native 支付未返回 code_url', 40200, ['result' => $result]);
        }

        return [
            'pay_page' => 'qrcode',
            'pay_type' => 'wxpay',
            'pay_product' => self::PRODUCT_SCAN,
            'pay_action' => 'qrcode',
            'pay_params' => [
                'qrcode' => $qrcode,
                'raw' => $result,
            ],
            'chan_order_no' => (string) $order['pay_no'],
            'chan_trade_no' => '',
        ];
    }

    /**
     * 查询微信支付订单。
     *
     * @param array<string, mixed> $order 标准插件查单参数
     * @return array<string, mixed>
     */
    public function query(array $order): array
    {
        $response = $this->callWxpay(function () use ($order): WxpayResponse {
            $transactionId = $this->transactionId($order);
            if ($transactionId !== '') {
                return $this->client($this->queryProduct())->queryByTransactionId($transactionId);
            }

            return $this->client($this->queryProduct())->queryByOutTradeNo($this->outTradeNo($order));
        });

        if (!$response->success()) {
            return [
                'success' => false,
                'msg' => $response->message(),
                'raw_data' => $response->toArray(),
            ];
        }

        $data = $response->data();
        $tradeState = strtoupper((string) ($data['trade_state'] ?? ''));
        $status = $this->tradeStatus($tradeState);

        return [
            'success' => true,
            'status' => $status,
            'channel_order_no' => (string) ($data['out_trade_no'] ?? $order['pay_no'] ?? ''),
            'channel_trade_no' => (string) ($data['transaction_id'] ?? ''),
            'channel_status' => $tradeState,
            'message' => (string) ($data['trade_state_desc'] ?? $data['return_msg'] ?? ''),
            'paid_at' => $status === PaymentPluginStatusConstant::SUCCESS ? $this->wechatTime($data['success_time'] ?? $data['time_end'] ?? null) : null,
            'raw_data' => $response->toArray(),
        ];
    }

    /**
     * 关闭微信支付订单。
     *
     * @param array<string, mixed> $order 标准插件关单参数
     * @return array<string, mixed>
     */
    public function close(array $order): array
    {
        $response = $this->callWxpay(fn () => $this->client($this->queryProduct())->close($this->outTradeNo($order)));

        return [
            'success' => $response->success(),
            'msg' => $response->success() ? 'success' : $response->message(),
            'raw_data' => $response->toArray(),
        ];
    }

    /**
     * 发起微信支付退款。
     *
     * @param array<string, mixed> $order 标准插件退款参数
     * @return array<string, mixed>
     */
    public function refund(array $order): array
    {
        $payload = $this->refundPayload($order);
        $response = $this->callWxpay(fn () => $this->client($this->queryProduct())->refund($payload));
        $data = $response->data();

        return [
            'success' => $response->success(),
            'msg' => $response->success() ? 'success' : $response->message(),
            'chan_refund_no' => (string) ($data['refund_id'] ?? ''),
            'out_request_no' => (string) ($payload['out_refund_no'] ?? ''),
            'raw_data' => $response->toArray(),
        ];
    }

    /**
     * 解析微信支付异步通知。
     *
     * @param Request $request 回调请求
     * @return array<string, mixed>
     */
    public function notify(Request $request): array
    {
        if ($this->apiVersion() === WxpayClient::API_VERSION_V2) {
            return $this->notifyV2($request);
        }

        return $this->notifyV3($request);
    }

    /**
     * 返回微信支付通知成功应答。
     */
    public function notifySuccess(): string|Response
    {
        if ($this->apiVersion() === WxpayClient::API_VERSION_V2) {
            return '<xml><return_code><![CDATA[SUCCESS]]></return_code><return_msg><![CDATA[OK]]></return_msg></xml>';
        }

        return json_encode(['code' => 'SUCCESS', 'message' => '成功'], JSON_UNESCAPED_UNICODE);
    }

    /**
     * 返回微信支付通知失败应答。
     */
    public function notifyFail(): string|Response
    {
        if ($this->apiVersion() === WxpayClient::API_VERSION_V2) {
            return '<xml><return_code><![CDATA[FAIL]]></return_code><return_msg><![CDATA[FAIL]]></return_msg></xml>';
        }

        return json_encode(['code' => 'FAIL', 'message' => '失败'], JSON_UNESCAPED_UNICODE);
    }

    /**
     * 解析本次下单可尝试的微信支付产品。
     *
     * 外部 API 不直接指定微信产品，插件根据当前支付环境和必要上下文生成候选产品，
     * 再从通道已勾选的产品中保留可承接项。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<int, string> 产品标识列表
     */
    private function resolveProducts(array $order): array
    {
        $candidates = $this->productCandidates($order);
        $enabledProducts = $this->enabledProducts();
        $products = array_values(array_filter(
            $candidates,
            static fn (string $product): bool => in_array($product, $enabledProducts, true)
        ));

        if ($products !== []) {
            return $products;
        }

        throw new PaymentException('当前微信支付通道没有开通适合该支付环境的产品', 40200, [
            'env' => $this->paymentEnv($order),
            'candidate_products' => $candidates,
            'enabled_products' => $enabledProducts,
        ]);
    }

    /**
     * 解析身份流程需要关注的微信支付产品。
     *
     * 身份流程只在更适合当前环境的产品已开通时触发。例如微信内打开时，
     * 已开通 JSAPI 就先取 openid；如果未开通 JSAPI，则继续走 Native 兜底。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return string 产品标识
     */
    private function resolveIdentityProduct(array $order): string
    {
        $enabledProducts = $this->enabledProducts();
        foreach ($this->identityProductCandidates($order) as $product) {
            if (in_array($product, $enabledProducts, true)) {
                return $product;
            }
        }

        return '';
    }

    /**
     * 根据环境生成身份流程候选产品。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<int, string> 产品标识列表
     */
    private function identityProductCandidates(array $order): array
    {
        $payment = $this->paymentPayload($order);
        $env = $this->paymentEnv($order);

        if ($this->hasMiniPayload($payment) || $this->firstText($payment['is_mini'] ?? '', $payment['mini_app_id'] ?? '') !== '') {
            return [self::PRODUCT_MINI, self::PRODUCT_MP, self::PRODUCT_SCAN];
        }

        if ($env === EpayProtocolConstant::DEVICE_WECHAT) {
            return [self::PRODUCT_MP, self::PRODUCT_MINI, self::PRODUCT_SCAN];
        }

        if (in_array($env, [
            EpayProtocolConstant::DEVICE_MOBILE,
            EpayProtocolConstant::DEVICE_QQ,
            EpayProtocolConstant::DEVICE_ALIPAY,
            EpayProtocolConstant::DEVICE_JUMP,
        ], true)) {
            return [self::PRODUCT_H5, self::PRODUCT_MINI, self::PRODUCT_SCAN];
        }

        return $this->productCandidates($order);
    }

    /**
     * 根据支付环境生成微信支付产品候选列表。
     *
     * 排在前面的产品更符合当前环境，后面的产品作为兜底承接方案。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<int, string>
     */
    private function productCandidates(array $order): array
    {
        $payment = $this->paymentPayload($order);
        $env = $this->paymentEnv($order);

        if ($this->hasMiniPayload($payment)) {
            return [self::PRODUCT_MINI, self::PRODUCT_MP, self::PRODUCT_SCAN];
        }

        if ($env === EpayProtocolConstant::DEVICE_WECHAT) {
            return $this->hasOpenid($payment)
                ? [self::PRODUCT_MP, self::PRODUCT_MINI, self::PRODUCT_SCAN]
                : [self::PRODUCT_MP, self::PRODUCT_MINI, self::PRODUCT_SCAN];
        }

        return match ($env) {
            EpayProtocolConstant::DEVICE_MOBILE,
            EpayProtocolConstant::DEVICE_QQ,
            EpayProtocolConstant::DEVICE_ALIPAY => [self::PRODUCT_H5, self::PRODUCT_MINI, self::PRODUCT_SCAN],
            EpayProtocolConstant::DEVICE_JUMP => [self::PRODUCT_H5, self::PRODUCT_MINI, self::PRODUCT_SCAN],
            default => [self::PRODUCT_SCAN, self::PRODUCT_H5],
        };
    }

    /**
     * 获取当前支付环境。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return string 环境标识
     */
    private function paymentEnv(array $order): string
    {
        $env = strtolower(trim((string) ($order['_env'] ?? EpayProtocolConstant::DEVICE_PC)));

        return in_array($env, EpayProtocolConstant::v1Devices(), true) ? $env : EpayProtocolConstant::DEVICE_PC;
    }

    /**
     * 获取支付扩展载荷。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed>
     */
    private function paymentPayload(array $order): array
    {
        $extra = (array) ($order['extra'] ?? []);
        $payment = $extra['payment'] ?? [];

        return is_array($payment) ? $payment : [];
    }

    /**
     * 判断是否具备公众号 JSAPI 支付必要 openid。
     *
     * @param array<string, mixed> $payment 支付扩展载荷
     * @return bool 是否存在 openid
     */
    private function hasOpenid(array $payment): bool
    {
        return $this->openidFromPayload($payment) !== '';
    }

    /**
     * 判断是否具备小程序支付上下文。
     *
     * @param array<string, mixed> $payment 支付扩展载荷
     * @return bool 是否可优先尝试小程序支付
     */
    private function hasMiniPayload(array $payment): bool
    {
        return $this->firstText($payment['mini_openid'] ?? '', $payment['is_mini'] ?? '') !== ''
            || ($this->firstText($payment['mini_app_id'] ?? '', $this->configText('mini_app_id')) !== ''
                && $this->openidFromPayload($payment) !== '');
    }

    /**
     * 构造微信下单请求参数。
     *
     * @param string $product 产品标识
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed> 官方下单参数
     */
    private function wxOrder(string $product, array $order): array
    {
        return $this->apiVersion() === WxpayClient::API_VERSION_V3
            ? $this->wxOrderV3($product, $order)
            : $this->wxOrderV2($product, $order);
    }

    /**
     * 构造 V3 下单请求参数。
     *
     * @param string $product 产品标识
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed>
     */
    private function wxOrderV3(string $product, array $order): array
    {
        $product = $this->normalizeProduct($product);
        $payload = [
            'description' => mb_strcut((string) $order['subject'], 0, 127, 'UTF-8'),
            'out_trade_no' => (string) $order['pay_no'],
            'notify_url' => (string) $order['callback_url'],
            'amount' => [
                'total' => (int) $order['amount'],
                'currency' => 'CNY',
            ],
        ];

        if (in_array($product, [self::PRODUCT_MP, self::PRODUCT_MINI], true)) {
            $payload['payer'] = $this->payerPayload($product, $this->paymentPayload($order));
        }

        if ($product === self::PRODUCT_H5) {
            $payload['scene_info'] = $this->h5SceneInfo($order);
        }

        return $payload;
    }

    /**
     * 构造 V2 统一下单请求参数。
     *
     * @param string $product 产品标识
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed>
     */
    private function wxOrderV2(string $product, array $order): array
    {
        $product = $this->normalizeProduct($product);
        $payload = [
            'body' => mb_strcut((string) $order['subject'], 0, 127, 'UTF-8'),
            'out_trade_no' => (string) $order['pay_no'],
            'total_fee' => (int) $order['amount'],
            'notify_url' => (string) $order['callback_url'],
            'spbill_create_ip' => $this->clientIp($order),
        ];

        if (in_array($product, [self::PRODUCT_MP, self::PRODUCT_MINI], true)) {
            $this->appendV2Openid($payload, $product, $this->paymentPayload($order));
        }

        if ($product === self::PRODUCT_H5) {
            $payload['scene_info'] = json_encode($this->h5SceneInfo($order), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return $payload;
    }

    /**
     * 构造 JSAPI/小程序 payer 参数。
     *
     * @param string $product 产品标识
     * @param array<string, mixed> $payment 支付扩展载荷
     * @return array<string, string>
     */
    private function payerPayload(string $product, array $payment): array
    {
        $openid = $product === self::PRODUCT_MINI
            ? $this->firstText($payment['mini_openid'] ?? '', $this->openidFromPayload($payment))
            : $this->openidFromPayload($payment);
        if ($openid === '') {
            throw new PaymentException('微信 JSAPI/小程序支付必须传入 openid', 40200);
        }

        if ($this->configText('mode', 'merchant') === 'partner') {
            return $this->partnerSubAppId($product) !== ''
                ? ['sub_openid' => $openid]
                : ['sp_openid' => $openid];
        }

        return ['openid' => $openid];
    }

    /**
     * 追加 V2 JSAPI openid 参数。
     *
     * @param array<string, mixed> $payload V2 下单参数
     * @param string $product 产品标识
     * @param array<string, mixed> $payment 支付扩展载荷
     * @return void
     */
    private function appendV2Openid(array &$payload, string $product, array $payment): void
    {
        $openid = $product === self::PRODUCT_MINI
            ? $this->firstText($payment['mini_openid'] ?? '', $this->openidFromPayload($payment))
            : $this->openidFromPayload($payment);
        if ($openid === '') {
            throw new PaymentException('微信 JSAPI/小程序支付必须传入 openid', 40200);
        }

        if ($this->configText('mode', 'merchant') === 'partner') {
            $payload[$this->partnerSubAppId($product) !== '' ? 'sub_openid' : 'openid'] = $openid;
            return;
        }

        $payload['openid'] = $openid;
    }

    /**
     * 从支付扩展载荷中提取 openid。
     *
     * @param array<string, mixed> $payment 支付扩展载荷
     * @return string openid
     */
    private function openidFromPayload(array $payment): string
    {
        return $this->firstText(
            $payment['openid'] ?? '',
            $payment['sub_openid'] ?? '',
            $payment['buyer_open_id'] ?? '',
            $payment['wx_openid'] ?? ''
        );
    }

    /**
     * 构造 H5 场景信息。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed>
     */
    private function h5SceneInfo(array $order): array
    {
        $scene = [
            'payer_client_ip' => $this->clientIp($order),
            'h5_info' => [
                'type' => $this->configText('h5_info_type', 'Wap'),
            ],
        ];

        $appName = $this->configText('h5_app_name');
        if ($appName !== '') {
            $scene['h5_info']['app_name'] = $appName;
        }

        $appUrl = $this->configText('h5_app_url');
        if ($appUrl !== '') {
            $scene['h5_info']['app_url'] = $appUrl;
        }

        return $scene;
    }

    /**
     * 获取客户端 IP。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return string IP 地址
     */
    private function clientIp(array $order): string
    {
        $payment = $this->paymentPayload($order);

        return $this->firstText(
            $payment['clientip'] ?? '',
            $payment['client_ip'] ?? '',
            $order['client_ip'] ?? '',
            $_SERVER['REMOTE_ADDR'] ?? '',
            '127.0.0.1'
        );
    }

    /**
     * 给承接参数附加 raw 和说明。
     *
     * @param mixed $params 原始承接参数
     * @param array<string, mixed> $result SDK 下单结果
     * @param string $description 页面说明
     * @return array<string, mixed>
     */
    private function withRaw(mixed $params, array $result, string $description): array
    {
        $params = is_array($params) ? $params : [];
        $params['description'] = $description;
        $params['raw'] = $result;

        return $params;
    }

    /**
     * 校验微信下单响应成功。
     *
     * @param array<string, mixed> $result SDK 下单结果
     * @param string $message 失败提示
     * @return void
     */
    private function ensurePrepaySuccess(array $result, string $message): void
    {
        if (($result['success'] ?? false) === true) {
            return;
        }

        $response = (array) ($result['response'] ?? []);
        throw new PaymentException(
            (string) ($response['message'] ?? $message),
            40200,
            [
                'channel_error_code' => (string) ($response['code'] ?? ''),
                'response' => $response,
            ]
        );
    }

    /**
     * 判断当前错误是否允许继续尝试下一个产品。
     *
     * 只对产品权限或商户未开通类错误做兜底。
     *
     * @param PaymentException $e 支付异常
     * @return bool 是否允许兜底
     */
    private function shouldFallbackProduct(PaymentException $e): bool
    {
        $data = $this->exceptionData($e);
        $errorCode = strtoupper((string) ($data['channel_error_code'] ?? ''));
        $message = strtoupper($e->getMessage());

        foreach (['NO_AUTH', 'NOT_PERMITTED', 'MCH_NOT_EXISTS', 'PARAM_ERROR'] as $code) {
            if (str_contains($errorCode, $code)) {
                return true;
            }
        }

        foreach (['权限', '未开通', '未配置', '商户号不存在', 'APPID_MCHID_NOT_MATCH'] as $keyword) {
            if (str_contains($message, strtoupper($keyword))) {
                return true;
            }
        }

        return false;
    }

    /**
     * 读取支付异常附加数据。
     *
     * @param PaymentException $e 支付异常
     * @return array<string, mixed>
     */
    private function exceptionData(PaymentException $e): array
    {
        $data = method_exists($e, 'getData') ? $e->getData() : [];

        return is_array($data) ? $data : [];
    }

    /**
     * 执行 SDK 调用并转换异常。
     *
     * @param callable $callback SDK 调用闭包
     * @return mixed SDK 返回值
     */
    private function callWxpay(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (WxpaySdkException $e) {
            throw new PaymentException($e->getMessage(), 40200);
        }
    }

    /**
     * 构造退款请求参数。
     *
     * @param array<string, mixed> $order 标准插件退款参数
     * @return array<string, mixed> 微信退款参数
     */
    private function refundPayload(array $order): array
    {
        $outRefundNo = $this->firstText(
            $order['out_refund_no'] ?? '',
            $order['refund_no'] ?? '',
            $order['channel_request_no'] ?? ''
        );
        if ($outRefundNo === '') {
            throw new PaymentException('微信退款必须传入退款单号', 40200);
        }

        $refundAmount = (int) ($order['refund_amount'] ?? 0);
        $totalAmount = (int) ($order['amount'] ?? $order['total_amount'] ?? 0);
        if ($refundAmount <= 0 || $totalAmount <= 0) {
            throw new PaymentException('微信退款金额和订单金额必须大于 0', 40200);
        }

        if ($this->apiVersion() === WxpayClient::API_VERSION_V3) {
            $payload = [
                'out_trade_no' => $this->outTradeNo($order),
                'out_refund_no' => $outRefundNo,
                'amount' => [
                    'refund' => $refundAmount,
                    'total' => $totalAmount,
                    'currency' => 'CNY',
                ],
            ];
        } else {
            $payload = [
                'out_trade_no' => $this->outTradeNo($order),
                'out_refund_no' => $outRefundNo,
                'total_fee' => $totalAmount,
                'refund_fee' => $refundAmount,
            ];
        }

        $reason = trim((string) ($order['refund_reason'] ?? ''));
        if ($reason !== '') {
            $payload[$this->apiVersion() === WxpayClient::API_VERSION_V3 ? 'reason' : 'refund_desc'] = mb_strcut($reason, 0, 80, 'UTF-8');
        }

        return $payload;
    }

    /**
     * 解析 V3 支付通知。
     *
     * @param Request $request 回调请求
     * @return array<string, mixed>
     */
    private function notifyV3(Request $request): array
    {
        $body = $request->rawBody();
        $headers = [
            'Wechatpay-Timestamp' => $request->header('wechatpay-timestamp', ''),
            'Wechatpay-Nonce' => $request->header('wechatpay-nonce', ''),
            'Wechatpay-Signature' => $request->header('wechatpay-signature', ''),
            'Wechatpay-Serial' => $request->header('wechatpay-serial', ''),
        ];
        $data = $this->callWxpay(fn () => $this->client($this->queryProduct())->parseV3Notify($headers, $body));
        $tradeState = strtoupper((string) ($data['trade_state'] ?? ''));
        $outTradeNo = (string) ($data['out_trade_no'] ?? '');
        $transactionId = (string) ($data['transaction_id'] ?? '');
        if ($outTradeNo === '') {
            throw new PaymentException('微信支付 V3 通知缺少 out_trade_no', 40200);
        }

        $status = $this->notifyStatus($tradeState);

        return [
            'status' => $status,
            'message' => (string) ($data['trade_state_desc'] ?? $tradeState),
            'channel_order_no' => $outTradeNo,
            'channel_trade_no' => $transactionId !== '' ? $transactionId : $outTradeNo,
            'channel_status' => $tradeState,
            'paid_at' => $status === PaymentPluginStatusConstant::SUCCESS ? $this->wechatTime($data['success_time'] ?? null) : null,
        ];
    }

    /**
     * 解析 V2 支付通知。
     *
     * @param Request $request 回调请求
     * @return array<string, mixed>
     */
    private function notifyV2(Request $request): array
    {
        $data = $this->callWxpay(fn () => $this->client($this->queryProduct())->parseV2Notify($request->rawBody()));
        $resultCode = strtoupper((string) ($data['result_code'] ?? $data['return_code'] ?? ''));
        $outTradeNo = (string) ($data['out_trade_no'] ?? '');
        $transactionId = (string) ($data['transaction_id'] ?? '');
        if ($outTradeNo === '') {
            throw new PaymentException('微信支付 V2 通知缺少 out_trade_no', 40200);
        }

        $status = $resultCode === 'SUCCESS'
            ? PaymentPluginStatusConstant::SUCCESS
            : PaymentPluginStatusConstant::FAILED;

        return [
            'status' => $status,
            'message' => (string) ($data['err_code_des'] ?? $resultCode),
            'channel_order_no' => $outTradeNo,
            'channel_trade_no' => $transactionId !== '' ? $transactionId : $outTradeNo,
            'channel_status' => $resultCode,
            'paid_at' => $status === PaymentPluginStatusConstant::SUCCESS ? $this->wechatTime($data['time_end'] ?? null) : null,
        ];
    }

    /**
     * 构造查单/关单/退款使用的客户端产品。
     *
     * 这些接口不关心实际承接页产品，只需要一个可正确初始化的 SDK 客户端。
     *
     * @return string 产品标识
     */
    private function queryProduct(): string
    {
        $enabled = $this->enabledProducts();

        return $enabled[0] ?? self::PRODUCT_SCAN;
    }

    /**
     * 获取商户订单号。
     *
     * @param array<string, mixed> $order 标准插件订单参数
     * @return string 商户订单号
     */
    private function outTradeNo(array $order): string
    {
        $outTradeNo = $this->firstText(
            $order['out_trade_no'] ?? '',
            $order['chan_order_no'] ?? '',
            $order['channel_order_no'] ?? '',
            $order['pay_no'] ?? ''
        );
        if ($outTradeNo === '') {
            throw new PaymentException('微信支付商户订单号不能为空', 40200);
        }

        return $outTradeNo;
    }

    /**
     * 获取微信支付订单号。
     *
     * @param array<string, mixed> $order 标准插件订单参数
     * @return string 微信支付订单号
     */
    private function transactionId(array $order): string
    {
        return $this->firstText(
            $order['transaction_id'] ?? '',
            $order['trade_no'] ?? '',
            $order['chan_trade_no'] ?? '',
            $order['channel_trade_no'] ?? ''
        );
    }

    /**
     * 主动查单状态映射。
     */
    private function tradeStatus(string $tradeState): string
    {
        return match ($tradeState) {
            'SUCCESS' => PaymentPluginStatusConstant::SUCCESS,
            'CLOSED', 'REVOKED' => PaymentPluginStatusConstant::CLOSED,
            'PAYERROR' => PaymentPluginStatusConstant::FAILED,
            default => PaymentPluginStatusConstant::PENDING,
        };
    }

    /**
     * 异步通知状态映射。
     */
    private function notifyStatus(string $tradeState): string
    {
        return match ($tradeState) {
            'SUCCESS' => PaymentPluginStatusConstant::SUCCESS,
            'CLOSED', 'REVOKED', 'PAYERROR' => PaymentPluginStatusConstant::FAILED,
            default => PaymentPluginStatusConstant::PENDING,
        };
    }

    /**
     * 标准化微信时间。
     *
     * V3 通常返回 ISO8601，V2 通常返回 yyyyMMddHHmmss。
     *
     * @param mixed $value 原始时间
     * @return string|null 标准时间
     */
    private function wechatTime(mixed $value): ?string
    {
        $time = trim((string) $value);
        if ($time === '') {
            return null;
        }
        if (preg_match('/^\d{14}$/', $time) === 1) {
            return sprintf(
                '%s-%s-%s %s:%s:%s',
                substr($time, 0, 4),
                substr($time, 4, 2),
                substr($time, 6, 2),
                substr($time, 8, 2),
                substr($time, 10, 2),
                substr($time, 12, 2)
            );
        }

        return str_replace('T', ' ', preg_replace('/([+-]\d{2}:\d{2}|Z)$/', '', $time) ?? $time);
    }

    /**
     * 支付产品多选项。
     *
     * @return array<int, array{label:string,value:string}>
     */
    private function productOptions(): array
    {
        return [
            ['label' => 'JSAPI支付', 'value' => self::PRODUCT_MP],
            ['label' => 'APP支付', 'value' => self::PRODUCT_APP],
            ['label' => 'H5支付', 'value' => self::PRODUCT_H5],
            ['label' => 'Native支付', 'value' => self::PRODUCT_SCAN],
            ['label' => '小程序支付', 'value' => self::PRODUCT_MINI],
        ];
    }

    /**
     * 构造接口版本字段联动规则。
     *
     * V3 和 V2 的密钥、证书、签名方式完全不同，因此配置字段按版本切换显示和必填。
     *
     * @param string $version 接口版本
     * @param array<int, string> $fields 被控制字段
     * @param string $method 控制方法
     * @return array<string, mixed>
     */
    private function versionControl(string $version, array $fields, string $method = 'display'): array
    {
        return [
            'value' => $version,
            'method' => $method,
            'rule' => $fields,
        ];
    }

    /**
     * 构造接入模式字段联动规则。
     *
     * @param string $mode 接入模式
     * @param array<int, string> $fields 被控制字段
     * @param string $method 控制方法
     * @return array<string, mixed>
     */
    private function modeControl(string $mode, array $fields, string $method = 'display'): array
    {
        return [
            'value' => $mode,
            'method' => $method,
            'rule' => $fields,
        ];
    }

    /**
     * 构造产品多选字段联动规则。
     *
     * form-create 的 on 条件一次只能判断一个值，这里用 handle 让一个字段可以被多个产品共同控制。
     *
     * @param string|array<int, string> $products 触发显示的产品
     * @param array<int, string> $fields 被控制字段
     * @param string $method 控制方法
     * @return array<string, mixed>
     */
    private function productControl(string|array $products, array $fields, string $method = 'display'): array
    {
        $products = array_values((array) $products);
        $productsJson = json_encode($products, JSON_UNESCAPED_SLASHES);

        return [
            'handle' => '$FN:function(val){var products=' . $productsJson . ';return Array.isArray(val) && products.some(function(item){return val.indexOf(item) > -1;});}',
            'method' => $method,
            'rule' => $fields,
        ];
    }

    /**
     * 构造后台上传字段属性。
     *
     * 上传成功后保存文件资产的 object_key，插件运行时再按项目本地存储规则拼接绝对路径。
     *
     * @param string $accept 允许选择的文件后缀
     * @param int $scene 文件场景
     * @return array<string, mixed>
     */
    private function uploadProps(string $accept, int $scene = FileConstant::SCENE_CERTIFICATE): array
    {
        return [
            'fileUpload' => [
                'scene' => $scene,
                'visibility' => FileConstant::VISIBILITY_PRIVATE,
                'storageEngine' => FileConstant::STORAGE_LOCAL,
                'getKey' => 'object_key',
                'accept' => $accept,
                'limit' => 1,
                'multiple' => false,
                'showFileList' => true,
            ],
        ];
    }

    /**
     * 获取 SDK 客户端。
     *
     * 微信不同产品可能使用不同 AppID，按产品缓存客户端，避免 AppID 串用。
     *
     * @param string $product 产品标识
     * @return WxpayClient SDK 客户端
     */
    private function client(string $product): WxpayClient
    {
        $product = $this->normalizeProduct($product);
        if (!isset($this->clients[$product])) {
            try {
                $this->clients[$product] = new WxpayClient($this->sdkConfig($product));
            } catch (WxpaySdkException $e) {
                throw new PaymentException($e->getMessage(), 40200);
            }
        }

        return $this->clients[$product];
    }

    /**
     * 构造 SDK 配置。
     *
     * @param string $product 产品标识
     * @return array<string, mixed>
     */
    private function sdkConfig(string $product): array
    {
        $apiVersion = $this->apiVersion();
        $mode = $this->configText('mode', 'merchant');
        $productAppId = $this->productAppId($product);

        return [
            'api_version' => $apiVersion,
            'mode' => $mode,
            'app_id' => $mode === 'partner' ? $this->configText('sp_app_id', $this->configText('app_id')) : $productAppId,
            'mch_id' => $this->configText('mch_id'),
            'sub_mch_id' => $this->configText('sub_mch_id'),
            'sub_app_id' => $mode === 'partner' ? $this->partnerSubAppId($product) : '',
            'serial_no' => $apiVersion === WxpayClient::API_VERSION_V3 ? $this->configText('serial_no') : '',
            'private_key' => $apiVersion === WxpayClient::API_VERSION_V3 ? $this->configText('private_key') : '',
            'api_v3_key' => $apiVersion === WxpayClient::API_VERSION_V3 ? $this->configText('api_v3_key') : '',
            'platform_cert_path' => $apiVersion === WxpayClient::API_VERSION_V3 ? $this->uploadedPrivateFilePath($this->configText('platform_cert_path')) : '',
            'api_key' => $apiVersion === WxpayClient::API_VERSION_V2 ? $this->configText('api_key') : '',
            'v2_sign_type' => $apiVersion === WxpayClient::API_VERSION_V2 ? 'HMAC-SHA256' : '',
            'cert_path' => $apiVersion === WxpayClient::API_VERSION_V2 ? $this->uploadedPrivateFilePath($this->configText('cert_path')) : '',
            'key_path' => $apiVersion === WxpayClient::API_VERSION_V2 ? $this->uploadedPrivateFilePath($this->configText('key_path')) : '',
            'sandbox' => $apiVersion === WxpayClient::API_VERSION_V2 && $this->configBool('sandbox'),
        ];
    }

    /**
     * 获取当前接口版本。
     */
    private function apiVersion(): string
    {
        $version = strtolower($this->configText('api_version', WxpayClient::API_VERSION_V3));

        return in_array($version, [WxpayClient::API_VERSION_V2, WxpayClient::API_VERSION_V3], true)
            ? $version
            : WxpayClient::API_VERSION_V3;
    }

    /**
     * 获取产品 AppID。
     *
     * @param string $product 产品标识
     * @return string AppID
     */
    private function productAppId(string $product): string
    {
        return match ($this->normalizeProduct($product)) {
            self::PRODUCT_MP => $this->firstText($this->configText('mp_app_id'), $this->configText('app_id')),
            self::PRODUCT_APP => $this->firstText($this->configText('app_app_id'), $this->configText('app_id')),
            self::PRODUCT_MINI => $this->firstText($this->configText('mini_app_id'), $this->configText('app_id')),
            default => $this->configText('app_id'),
        };
    }

    /**
     * 获取小程序 URL Scheme 打开的版本。
     *
     * @return string 小程序版本标识
     */
    private function miniEnvVersion(): string
    {
        $version = $this->configText('mini_env_version', 'release');

        return in_array($version, ['release', 'trial', 'develop'], true) ? $version : 'release';
    }

    /**
     * 获取服务商模式下的子商户 AppID。
     *
     * 只有明确配置了产品专用 AppID 时才传 sub_appid，避免把服务商 AppID 误传成子商户 AppID。
     *
     * @param string $product 产品标识
     * @return string 子商户 AppID
     */
    private function partnerSubAppId(string $product): string
    {
        return match ($this->normalizeProduct($product)) {
            self::PRODUCT_MP => $this->configText('mp_app_id'),
            self::PRODUCT_APP => $this->configText('app_app_id'),
            self::PRODUCT_MINI => $this->configText('mini_app_id'),
            default => '',
        };
    }

    /**
     * 将上传组件保存的本地私有 object_key 转换为本机绝对路径。
     *
     * @param string $path 上传组件写入配置的 object_key 或绝对路径
     * @return string 可读取的本机路径
     */
    private function uploadedPrivateFilePath(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path));
        if ($path === '') {
            return '';
        }

        if (preg_match('/^[A-Za-z]:\//', $path) === 1 || str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return str_replace('/', DIRECTORY_SEPARATOR, $path);
        }

        return runtime_path(trim($path, '/'));
    }

    /**
     * 获取已确认开通的微信支付产品。
     *
     * @return array<int, string>
     */
    private function enabledProducts(): array
    {
        $products = $this->getConfig('enabled_products', []);
        if (is_string($products)) {
            $decoded = json_decode($products, true);
            $products = is_array($decoded) ? $decoded : explode(',', $products);
        }
        if (!is_array($products)) {
            return [];
        }

        $products = array_filter(array_map(
            static fn ($value): string => trim((string) $value),
            $products
        ));

        return array_values(array_filter(array_map(
            fn (string $value): string => $this->normalizeProduct($value),
            $products
        )));
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
            'jsapi' => self::PRODUCT_MP,
            'native' => self::PRODUCT_SCAN,
            'mweb' => self::PRODUCT_H5,
            'wap' => self::PRODUCT_H5,
        ];
        $product = $aliases[$product] ?? $product;

        if (!in_array($product, [
            self::PRODUCT_MP,
            self::PRODUCT_H5,
            self::PRODUCT_APP,
            self::PRODUCT_MINI,
            self::PRODUCT_SCAN,
        ], true)) {
            throw new PaymentException('不支持的微信支付产品：' . $product, 40200);
        }

        return $product;
    }

    /**
     * 读取字符串配置。
     */
    private function configText(string $key, string $default = ''): string
    {
        return trim((string) $this->getConfig($key, $default));
    }

    /**
     * 读取布尔配置。
     */
    private function configBool(string $key, bool $default = false): bool
    {
        $value = $this->getConfig($key, $default);
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * 从多个候选值中取第一个非空文本。
     *
     * @param mixed ...$values 候选值
     * @return string 非空文本
     */
    private function firstText(mixed ...$values): string
    {
        foreach ($values as $value) {
            $text = trim((string) $value);
            if ($text !== '') {
                return $text;
            }
        }

        return '';
    }
}
