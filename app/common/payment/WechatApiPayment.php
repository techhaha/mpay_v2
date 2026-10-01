<?php

declare(strict_types=1);

namespace app\common\payment;

use app\common\base\BasePayment;
use app\common\constant\PaymentPluginTypeConstant;
use app\common\constant\EpayProtocolConstant;
use app\common\constant\FileConstant;
use app\common\constant\PaymentMethodConstant;
use app\common\constant\PaymentPluginStatusConstant;
use app\common\interface\PaymentIdentityRequirementInterface;
use app\common\interface\PaymentInterface;
use app\common\interface\PayPluginInterface;
use app\common\interface\RefundQueryInterface;
use app\common\sdk\wxpay\WxpayClient;
use app\common\sdk\wxpay\WxpayResponse;
use app\common\sdk\wxpay\WxpaySdkException;
use app\exception\PaymentDefinitiveException;
use app\exception\PaymentException;
use app\exception\PaymentProductUnavailableException;
use app\exception\PaymentUncertainException;
use app\model\payment\PayOrder;
use app\repository\payment\trade\PayOrderRepository;
use support\Request;
use support\Response;
use Throwable;

/**
 * 微信支付官方 API 支付插件。
 *
 * 负责 V2/V3、普通商户/服务商模式下的 JSAPI、H5、APP、小程序和 Native 支付适配，
 * 并完成查单、关单、退款以及通知验签后的订单归属、产品、商户身份和金额校验。
 */
class WechatApiPayment extends BasePayment implements PaymentInterface, PayPluginInterface, PaymentIdentityRequirementInterface, RefundQueryInterface
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
        'version' => '1.1.0',
        'pay_types' => ['wxpay'],
        'transfer_types' => [],
        'config_schema' => [],
    ];

    /**
     * 构造方法。
     *
     * 回调验签通过后由插件读取支付单，完成微信订单号、金额和通道归属校验；
     * 核心回调服务仍只负责编排和生命周期推进。
     *
     * @param PayOrderRepository $payOrderRepository 支付单仓库
     */
    public function __construct(
        private readonly PayOrderRepository $payOrderRepository
    ) {}

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
                    $this->versionControl(WxpayClient::API_VERSION_V3, ['serial_no', 'private_key', 'api_v3_key', 'wechatpay_public_key_id', 'wechatpay_public_key_path', 'platform_cert_path']),
                    $this->versionControl(WxpayClient::API_VERSION_V3, ['serial_no', 'private_key', 'api_v3_key'], 'required'),
                    $this->versionControl(WxpayClient::API_VERSION_V2, ['api_key', 'previous_api_key', 'v2_sign_type', 'cert_path', 'key_path', 'sandbox']),
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
                'props' => array_replace($this->uploadProps('.crt,.cer,.pem'), [
                    'tip' => '平台证书与微信支付公钥二选一；新商户通常使用微信支付公钥。',
                ]),
            ],
            [
                'type' => 'input',
                'field' => 'wechatpay_public_key_id',
                'title' => '微信支付公钥ID(V3)',
                'value' => '',
                'props' => ['placeholder' => '例如 PUB_KEY_ID_...；上传微信支付公钥时必填'],
            ],
            [
                'type' => 'upload',
                'field' => 'wechatpay_public_key_path',
                'title' => '微信支付公钥文件(V3)',
                'value' => '',
                'props' => array_replace($this->uploadProps('.pem'), [
                    'tip' => '从微信支付商户平台下载的公钥文件，不是商户 API 公钥。',
                ]),
            ],
            [
                'type' => 'password',
                'field' => 'api_key',
                'title' => 'APIv2密钥(V2)',
                'value' => '',
            ],
            [
                'type' => 'password',
                'field' => 'previous_api_key',
                'title' => '上一把APIv2密钥(V2)',
                'value' => '',
                'props' => ['placeholder' => '仅密钥轮换的15天过渡期填写，只用于通知验签'],
            ],
            [
                'type' => 'radio',
                'field' => 'v2_sign_type',
                'title' => 'V2出站签名方式',
                'value' => 'HMAC-SHA256',
                'options' => [
                    ['label' => 'HMAC-SHA256', 'value' => 'HMAC-SHA256'],
                    ['label' => 'MD5', 'value' => 'MD5'],
                ],
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
     * 身份需求按最终可用产品及其 AppID 作用域生成，已有对应 openid 时不重复触发授权。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     *
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
            ? $this->firstText($payment['mini_openid'] ?? '')
            : $this->openidFromPayload($payment);
        if ($openid !== '') {
            return null;
        }

        return [
            'provider' => 'wxpay',
            'product' => $product,
            'channel_product' => $product,
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
     * 候选产品按当前环境排序；经核心预写产品的真实支付不跨产品重试。
     * 执行异常携带实际选中产品；结果不确定或未知异常立即停止兜底。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     *
     * @return array<string, mixed> 标准待支付结果
     */
    public function pay(array $order): array
    {
        $products = $this->resolveProducts($order);
        $attempts = [];

        foreach ($products as $index => $product) {
            $this->preparePayProduct($product);
            try {
                return $this->pendingPaymentResult($order, $this->payByProduct($product, $order));
            } catch (PaymentException $e) {
                $attempts[] = [
                    'product' => $product,
                    'message' => $e->getMessage(),
                    'channel_error_code' => (string) (($this->exceptionData($e))['channel_error_code'] ?? ''),
                ];
                if ($this->hasPayProductPreflight() || $index === count($products) - 1 || !$this->shouldFallbackProduct($e)) {
                    $data = $this->exceptionData($e);
                    $data['pay_type'] = 'wxpay';
                    $data['pay_product'] = $product;
                    $data['product_attempts'] = $attempts;
                    $exception = $e::class;
                    throw new $exception($e->getMessage(), (int) $e->getCode() ?: 40200, $data);
                }
            } catch (Throwable $e) {
                // 已进入具体产品执行，未知异常不能证明上游未建单，禁止继续下一个产品。
                throw new PaymentUncertainException($e->getMessage(), 40200, [
                    'pay_type' => 'wxpay',
                    'pay_product' => $product,
                    'channel_error_code' => 'PLUGIN_CREATE_ORDER_ERROR',
                    'exception_class' => $e::class,
                    'product_attempts' => array_merge($attempts, [[
                        'product' => $product,
                        'message' => $e->getMessage(),
                        'channel_error_code' => 'PLUGIN_CREATE_ORDER_ERROR',
                    ]]),
                ]);
            }
        }

        throw new PaymentException('当前微信支付通道没有可用支付产品', 40200);
    }

    /**
     * 按指定产品发起支付。
     *
     * @param string $product 产品标识
     * @param array<string, mixed> $order 标准插件下单参数
     *
     * @return array<string, mixed> 指定产品的标准待支付结果
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
     * 返回小程序容器调用 wx.requestPayment 所需的参数和对应 AppID，网页收银台本身不负责调起。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     *
     * @return array<string, mixed> 标准小程序页面待支付结果
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
     * 成功终态须核对商户订单号、微信订单号、币种和整数分金额。
     *
     * @param array<string, mixed> $order 标准插件查单参数
     *
     * @return array<string, mixed> 标准支付查询结果
     */
    public function query(array $order): array
    {
        $response = $this->callWxpay(function () use ($order): WxpayResponse {
            $transactionId = $this->transactionId($order);
            if ($transactionId !== '') {
                return $this->client($this->operationProduct($order))->queryByTransactionId($transactionId);
            }

            return $this->client($this->operationProduct($order))->queryByOutTradeNo($this->outTradeNo($order));
        });

        if (!$response->success()) {
            throw new PaymentException($response->message(), 40200, [
                'channel_error_code' => $response->code(),
            ]);
        }

        $data = $response->data();
        $tradeState = strtoupper((string) ($data['trade_state'] ?? ''));
        $status = $this->tradeStatus($tradeState);
        $payNo = trim((string) ($order['pay_no'] ?? ''));
        $outTradeNo = trim((string) ($data['out_trade_no'] ?? ''));
        if ($payNo === '' || !hash_equals($payNo, $outTradeNo)) {
            throw new PaymentException('微信支付查单返回订单号不匹配', 40200, ['pay_no' => $payNo]);
        }

        $transactionId = trim((string) ($data['transaction_id'] ?? ''));
        $storedTransactionId = trim((string) ($order['chan_trade_no'] ?? ''));
        if ($storedTransactionId !== '' && $transactionId !== '' && !hash_equals($storedTransactionId, $transactionId)) {
            throw new PaymentException('微信支付查单返回交易号不匹配', 40200, ['pay_no' => $payNo]);
        }

        $paidAmount = null;
        if ($status === PaymentPluginStatusConstant::SUCCESS) {
            $paidAmount = $this->queryPaidAmount($data);
            if ($paidAmount !== (int) ($order['amount'] ?? -1)) {
                throw new PaymentException('微信支付查单返回金额不匹配', 40200, ['pay_no' => $payNo]);
            }
            if ($transactionId === '') {
                throw new PaymentException('微信支付成功查单响应缺少 transaction_id', 40200, ['pay_no' => $payNo]);
            }
        }

        return [
            'status' => $status,
            'pay_no' => $payNo,
            'paid_amount' => $paidAmount,
            'chan_order_no' => $outTradeNo,
            'chan_trade_no' => $transactionId,
            'channel_status' => $tradeState,
            'message' => (string) ($data['trade_state_desc'] ?? $data['return_msg'] ?? ''),
            'paid_at' => $status === PaymentPluginStatusConstant::SUCCESS ? $this->wechatTime($data['success_time'] ?? $data['time_end'] ?? null) : null,
        ];
    }

    /**
     * 关闭微信支付订单。
     *
     * 微信返回失败时按错误码和 HTTP 状态区分确定失败与结果不确定。
     *
     * @param array<string, mixed> $order 标准插件关单参数
     *
     * @return array<string, mixed> 标准关单结果
     */
    public function close(array $order): array
    {
        $response = $this->callWxpay(fn () => $this->client($this->operationProduct($order))->close($this->outTradeNo($order)));
        if (!$response->success()) {
            $this->throwWxpayFailure($response, '微信支付关单失败');
        }

        return [
            'status' => PaymentPluginStatusConstant::CLOSED,
            'pay_no' => trim((string) ($order['pay_no'] ?? '')),
            'chan_order_no' => trim((string) ($order['chan_order_no'] ?? '')),
            'chan_trade_no' => trim((string) ($order['chan_trade_no'] ?? '')),
            'message' => 'success',
        ];
    }

    /**
     * 发起微信支付退款。
     *
     * 响应必须匹配退款单号、退款金额和可识别状态，缺失或冲突字段均按结果不确定处理。
     *
     * @param array<string, mixed> $order 标准插件退款参数
     *
     * @return array<string, mixed> 标准退款结果
     */
    public function refund(array $order): array
    {
        $payload = $this->refundPayload($order);
        $response = $this->callWxpay(fn () => $this->client($this->operationProduct($order))->refund($payload));
        if (!$response->success()) {
            $this->throwWxpayFailure($response, '微信支付退款失败');
        }

        $data = $response->data();
        $refundNo = trim((string) ($order['refund_no'] ?? ''));
        $actualRefundNo = trim((string) ($data['out_refund_no'] ?? ''));
        if ($actualRefundNo === '' || !hash_equals($refundNo, $actualRefundNo)) {
            throw new PaymentUncertainException('微信支付退款返回退款单号不匹配', 40200, ['refund_no' => $refundNo]);
        }

        $refundAmount = $this->refundResponseAmount($data);
        if ($refundAmount !== (int) ($order['refund_amount'] ?? -1)) {
            throw new PaymentUncertainException('微信支付退款返回金额不匹配', 40200, ['refund_no' => $refundNo]);
        }

        $channelStatus = strtoupper(trim((string) ($data['status'] ?? '')));
        if ($this->apiVersion() === WxpayClient::API_VERSION_V2 && $channelStatus === '') {
            // V2 result_code=SUCCESS 仅确认退款申请已受理，最终结果由 refundquery 给出。
            $channelStatus = 'PROCESSING';
        }
        if (!in_array($channelStatus, ['SUCCESS', 'PROCESSING'], true)) {
            throw new PaymentUncertainException('微信支付退款返回状态无法确认', 40200, [
                'refund_no' => $refundNo,
                'channel_status' => $channelStatus,
            ]);
        }

        return [
            'status' => $channelStatus === 'SUCCESS'
                ? PaymentPluginStatusConstant::SUCCESS
                : PaymentPluginStatusConstant::PENDING,
            'refund_no' => $refundNo,
            'pay_no' => trim((string) ($order['pay_no'] ?? '')),
            'refund_amount' => $refundAmount,
            'chan_refund_no' => trim((string) ($data['refund_id'] ?? '')),
            'channel_status' => $channelStatus,
            'message' => 'success',
        ];
    }

    /**
     * 查询微信退款状态。
     */
    public function queryRefund(array $refund): array
    {
        $refundNo = (string) $refund['refund_no'];

        $response = $this->callWxpay(
            fn () => $this->client($this->operationProduct($refund))->queryRefund($refundNo)
        );
        if (!$response->success()) {
            throw new PaymentUncertainException($response->message(), 40200, [
                'refund_no' => $refundNo,
                'channel_error_code' => $response->code(),
            ]);
        }

        $data = $response->data();
        if ($this->apiVersion() === WxpayClient::API_VERSION_V3) {
            $responseRefundNo = trim((string) ($data['out_refund_no'] ?? ''));
            $responsePayNo = trim((string) ($data['out_trade_no'] ?? ''));
            $channelRefundNo = trim((string) ($data['refund_id'] ?? ''));
            $channelStatus = strtoupper(trim((string) ($data['status'] ?? '')));
            $amount = $data['amount'] ?? null;
            $actualAmount = is_array($amount) && is_int($amount['refund'] ?? null)
                ? $amount['refund']
                : null;
            $currency = is_array($amount) ? (string) ($amount['currency'] ?? '') : '';
        } else {
            $responseRefundNo = trim((string) ($data['out_refund_no_0'] ?? ''));
            $responsePayNo = trim((string) ($data['out_trade_no'] ?? ''));
            $channelRefundNo = trim((string) ($data['refund_id_0'] ?? ''));
            $channelStatus = strtoupper(trim((string) ($data['refund_status_0'] ?? '')));
            $value = $data['refund_fee_0'] ?? null;
            $actualAmount = (is_int($value) || (is_string($value) && ctype_digit($value))) ? (int) $value : null;
            $currency = (string) ($data['fee_type'] ?? 'CNY');
        }

        $payNo = (string) $refund['pay_no'];
        $refundAmount = (int) $refund['refund_amount'];
        if ($responseRefundNo === '' || !hash_equals($refundNo, $responseRefundNo)) {
            throw new PaymentUncertainException('微信退款查询返回退款单号不匹配', 40200, ['refund_no' => $refundNo]);
        }
        if ($channelRefundNo === '') {
            throw new PaymentUncertainException('微信退款查询缺少微信退款单号', 40200, ['refund_no' => $refundNo]);
        }
        if ($responsePayNo === '' || !hash_equals($payNo, $responsePayNo)) {
            throw new PaymentUncertainException('微信退款查询返回原支付单号不匹配', 40200, ['refund_no' => $refundNo]);
        }
        if ($actualAmount === null || $actualAmount !== $refundAmount) {
            throw new PaymentUncertainException('微信退款查询返回金额不匹配', 40200, ['refund_no' => $refundNo]);
        }
        if ($currency !== 'CNY') {
            throw new PaymentUncertainException('微信退款查询返回币种不受支持', 40200, ['refund_no' => $refundNo]);
        }

        $status = match ($channelStatus) {
            'SUCCESS' => PaymentPluginStatusConstant::SUCCESS,
            'CLOSED', 'REFUNDCLOSE' => PaymentPluginStatusConstant::FAILED,
            'PROCESSING' => PaymentPluginStatusConstant::PENDING,
            // 异常退款需到微信商户平台处理，不能当作已确认失败释放业务责任。
            'ABNORMAL', 'CHANGE' => PaymentPluginStatusConstant::UNKNOWN,
            default => PaymentPluginStatusConstant::UNKNOWN,
        };

        return [
            'status' => $status,
            'refund_no' => $refundNo,
            'pay_no' => $payNo,
            'refund_amount' => $refundAmount,
            'chan_refund_no' => $channelRefundNo,
            'channel_status' => $channelStatus,
            'message' => match ($channelStatus) {
                'SUCCESS' => '微信退款成功',
                'PROCESSING' => '微信退款处理中',
                'CLOSED', 'REFUNDCLOSE' => '微信退款已关闭',
                'ABNORMAL', 'CHANGE' => '微信退款异常，请到微信商户平台核实处理',
                default => '微信退款状态尚未确认：' . $channelStatus,
            },
        ];
    }

    /**
     * 解析微信支付异步通知。
     *
     * V3 使用平台证书/公钥验签并解密资源，V2 使用 API 密钥验签；后续均执行本地订单与产品边界校验。
     *
     * @param Request $request 回调请求
     *
     * @return array<string, mixed> 标准支付通知结果
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
     *
     * @return string|Response 通知应答
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
     *
     * @return string|Response 通知应答
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
        $enabledProducts = $this->enabledProducts();
        $payment = $this->paymentPayload($order);
        $selectedProduct = trim((string) ($payment['selected_product'] ?? ''));
        if ($selectedProduct !== '') {
            if (!in_array($selectedProduct, $enabledProducts, true)) {
                throw new PaymentProductUnavailableException('身份授权产品未在当前微信支付通道开通', 40200, [
                    'selected_product' => $selectedProduct,
                    'enabled_products' => $enabledProducts,
                ]);
            }

            return [$selectedProduct];
        }

        $method = strtolower(trim((string) ($payment['method'] ?? '')));
        if ($method !== '') {
            $product = $this->explicitProduct($method);
            if (!$this->productAllowed($product, $this->paymentEnv($order))) {
                throw new PaymentException('指定微信支付方式与当前支付环境不匹配', 40200, [
                    'method' => $method,
                    'env' => $this->paymentEnv($order),
                ]);
            }
            if (!in_array($product, $enabledProducts, true)) {
                throw new PaymentProductUnavailableException('当前微信支付通道未开通指定支付产品', 40200, [
                    'method' => $method,
                    'pay_product' => $product,
                ]);
            }

            return [$product];
        }

        $candidates = $this->productCandidates($order);
        $products = array_values(array_filter(
            $candidates,
            static fn (string $product): bool => in_array($product, $enabledProducts, true)
        ));

        if ($products !== []) {
            return $products;
        }

        throw new PaymentProductUnavailableException('当前微信支付通道没有开通适合该支付环境的产品', 40200, [
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
        return $this->resolveProducts($order)[0] ?? '';
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
     * 将开放接口调用方式映射为唯一微信支付产品。
     */
    private function explicitProduct(string $method): string
    {
        return match ($method) {
            PaymentMethodConstant::WEB,
            PaymentMethodConstant::QRCODE => self::PRODUCT_SCAN,
            PaymentMethodConstant::H5,
            PaymentMethodConstant::JUMP => self::PRODUCT_H5,
            PaymentMethodConstant::JSAPI => self::PRODUCT_MP,
            PaymentMethodConstant::APP => self::PRODUCT_APP,
            PaymentMethodConstant::MINI,
            PaymentMethodConstant::URL_SCHEME => self::PRODUCT_MINI,
            PaymentMethodConstant::AUTH_CODE => throw new PaymentException('微信官方插件未实现付款码支付产品', 40200),
            default => throw new PaymentException('不支持的微信支付调用方式', 40200, ['method' => $method]),
        };
    }

    /**
     * 校验显式产品是否适合当前终端环境。
     */
    private function productAllowed(string $product, string $env): bool
    {
        return match ($product) {
            self::PRODUCT_MP => $env === EpayProtocolConstant::DEVICE_WECHAT,
            self::PRODUCT_H5 => !in_array($env, [
                EpayProtocolConstant::DEVICE_PC,
                EpayProtocolConstant::DEVICE_WECHAT,
            ], true),
            default => true,
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
            ? $this->firstText($payment['mini_openid'] ?? '')
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
            ? $this->firstText($payment['mini_openid'] ?? '')
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
        $code = strtoupper((string) ($response['code'] ?? ''));
        $exception = $this->isUncertainWxpayCode($code)
            ? PaymentUncertainException::class
            : PaymentDefinitiveException::class;
        throw new $exception(
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
     * 只认专用产品不可用异常，不根据第三方错误文案推断。
     *
     * @param PaymentException $e 支付异常
     * @return bool 是否允许兜底
     */
    private function shouldFallbackProduct(PaymentException $e): bool
    {
        return $e instanceof PaymentProductUnavailableException;
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
     * SDK 级异常无法证明渠道未受理请求，统一转换为支付结果不确定异常。
     *
     * @param callable $callback SDK 调用闭包
     *
     * @return mixed SDK 返回值
     */
    private function callWxpay(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (WxpaySdkException $e) {
            throw new PaymentUncertainException($e->getMessage(), 40200);
        }
    }

    /**
     * 将微信接口失败转换为确定失败或结果不确定异常。
     *
     * @param WxpayResponse $response 微信接口响应
     * @param string $message 响应无可用信息时的默认提示
     */
    private function throwWxpayFailure(WxpayResponse $response, string $message): never
    {
        $exception = $this->isUncertainWxpayCode($response->code())
            || $response->statusCode() >= 500
            || in_array($response->statusCode(), [408, 429], true)
                ? PaymentUncertainException::class
                : PaymentDefinitiveException::class;

        throw new $exception(
            $response->message() !== '' ? $response->message() : $message,
            40200,
            ['channel_error_code' => $response->code()]
        );
    }

    /**
     * 判断微信错误码是否表示渠道处理结果仍不确定。
     *
     * @param string $code 微信错误码
     */
    private function isUncertainWxpayCode(string $code): bool
    {
        return in_array(strtoupper(trim($code)), [
            'SYSTEMERROR',
            'BANKERROR',
            'USERPAYING',
            'FREQUENCY_LIMITED',
        ], true);
    }

    /**
     * 提取并校验微信支付查单金额。
     *
     * 微信 V3 使用 amount.total，V2 使用 total_fee；币种只在插件内部校验，
     * 不进入 MPAY 标准查询结果。
     *
     * @param array<string, mixed> $data 微信查单响应
     *
     * @return int 已校验币种的整数分金额
     */
    private function queryPaidAmount(array $data): int
    {
        if ($this->apiVersion() === WxpayClient::API_VERSION_V3) {
            $amount = $data['amount'] ?? null;
            if (!is_array($amount) || !is_int($amount['total'] ?? null)) {
                throw new PaymentException('微信支付成功查单响应缺少 amount.total', 40200);
            }
            if (strtoupper(trim((string) ($amount['currency'] ?? 'CNY'))) !== 'CNY') {
                throw new PaymentException('微信支付查单返回币种不受支持', 40200);
            }

            return $amount['total'];
        }

        if (!isset($data['total_fee']) || !is_numeric($data['total_fee'])) {
            throw new PaymentException('微信支付成功查单响应缺少 total_fee', 40200);
        }
        if (strtoupper(trim((string) ($data['fee_type'] ?? 'CNY'))) !== 'CNY') {
            throw new PaymentException('微信支付查单返回币种不受支持', 40200);
        }

        return (int) $data['total_fee'];
    }

    /**
     * 提取并校验微信退款响应金额。
     *
     * @param array<string, mixed> $data 微信退款响应
     *
     * @return int 已校验币种的整数分退款金额
     */
    private function refundResponseAmount(array $data): int
    {
        if ($this->apiVersion() === WxpayClient::API_VERSION_V3) {
            $amount = $data['amount'] ?? null;
            if (!is_array($amount) || !is_int($amount['refund'] ?? null)) {
                throw new PaymentUncertainException('微信支付退款响应缺少 amount.refund', 40200);
            }
            if (strtoupper(trim((string) ($amount['currency'] ?? 'CNY'))) !== 'CNY') {
                throw new PaymentUncertainException('微信支付退款返回币种不受支持', 40200);
            }

            return $amount['refund'];
        }

        if (!isset($data['refund_fee']) || !is_numeric($data['refund_fee'])) {
            throw new PaymentUncertainException('微信支付退款响应缺少 refund_fee', 40200);
        }
        if (strtoupper(trim((string) ($data['refund_fee_type'] ?? 'CNY'))) !== 'CNY') {
            throw new PaymentUncertainException('微信支付退款返回币种不受支持', 40200);
        }

        return (int) $data['refund_fee'];
    }

    /**
     * 构造退款请求参数。
     *
     * @param array<string, mixed> $order 标准插件退款参数
     * @return array<string, mixed> 微信退款参数
     */
    private function refundPayload(array $order): array
    {
        $outRefundNo = trim((string) ($order['refund_no'] ?? ''));
        if ($outRefundNo === '') {
            throw new PaymentException('微信退款必须传入退款单号', 40200);
        }

        $refundAmount = (int) ($order['refund_amount'] ?? 0);
        $totalAmount = (int) ($order['amount'] ?? 0);
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
     * 原始请求体和微信签名头交由 SDK 验签、解密，解析失败转为支付异常，
     * 由统一回调链记录验签和处理结果。
     *
     * @param Request $request 回调请求
     *
     * @return array<string, mixed> 标准支付通知结果
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
        try {
            $data = $this->client($this->queryProduct())->parseV3Notify($headers, $body);
        } catch (WxpaySdkException $e) {
            throw new PaymentException($e->getMessage(), 40200);
        }

        $tradeState = strtoupper((string) ($data['trade_state'] ?? ''));
        $outTradeNo = (string) ($data['out_trade_no'] ?? '');
        $transactionId = (string) ($data['transaction_id'] ?? '');
        $status = $this->notifyStatus($tradeState);
        $payOrder = $this->validateV3Notify($data, $status);

        return [
            'status' => $status,
            'pay_no' => (string) $payOrder->pay_no,
            'paid_amount' => $status === PaymentPluginStatusConstant::SUCCESS
                ? (int) (($data['amount'] ?? [])['total'] ?? 0)
                : null,
            'message' => (string) ($data['trade_state_desc'] ?? $tradeState),
            'chan_order_no' => $outTradeNo,
            'chan_trade_no' => $transactionId,
            'channel_status' => $tradeState,
            'paid_at' => $status === PaymentPluginStatusConstant::SUCCESS ? $this->wechatTime($data['success_time'] ?? null) : null,
        ];
    }

    /**
     * 解析 V2 支付通知。
     *
     * 原始 XML 交由 SDK 验签解析，解析失败转为支付异常，
     * 由统一回调链记录验签和处理结果。
     *
     * @param Request $request 回调请求
     *
     * @return array<string, mixed> 标准支付通知结果
     */
    private function notifyV2(Request $request): array
    {
        try {
            $data = $this->client($this->queryProduct())->parseV2Notify($request->rawBody());
        } catch (WxpaySdkException $e) {
            throw new PaymentException($e->getMessage(), 40200);
        }

        $resultCode = strtoupper((string) ($data['result_code'] ?? ''));
        $outTradeNo = (string) ($data['out_trade_no'] ?? '');
        $transactionId = (string) ($data['transaction_id'] ?? '');
        $status = $resultCode === 'SUCCESS'
            ? PaymentPluginStatusConstant::SUCCESS
            : PaymentPluginStatusConstant::FAILED;
        $payOrder = $this->validateV2Notify($data, $status);

        return [
            'status' => $status,
            'pay_no' => (string) $payOrder->pay_no,
            'paid_amount' => $status === PaymentPluginStatusConstant::SUCCESS
                ? (int) ($data['total_fee'] ?? 0)
                : null,
            'message' => (string) ($data['err_code_des'] ?? $resultCode),
            'chan_order_no' => $outTradeNo,
            'chan_trade_no' => $transactionId,
            'channel_status' => $resultCode,
            'paid_at' => $status === PaymentPluginStatusConstant::SUCCESS ? $this->wechatTime($data['time_end'] ?? null) : null,
        ];
    }

    /**
     * 校验 V3 支付通知中的订单、商户身份和金额。
     *
     * @param array<string, mixed> $data 解密后的微信通知
     * @param string $status 标准插件状态
     * @return PayOrder 已校验支付单
     */
    private function validateV3Notify(array $data, string $status): PayOrder
    {
        $tradeState = strtoupper(trim((string) ($data['trade_state'] ?? '')));
        if ($tradeState === '') {
            throw new PaymentException('微信支付 V3 通知缺少 trade_state', 40200);
        }

        $payOrder = $this->notifyPayOrder((string) ($data['out_trade_no'] ?? ''), 'V3');
        $product = $this->notifyProduct($payOrder, (string) ($data['trade_type'] ?? ''));
        $this->validateV3NotifyIdentity($data, $product);

        $amount = $data['amount'] ?? null;
        if (!is_array($amount)) {
            throw new PaymentException('微信支付 V3 通知缺少 amount', 40200);
        }
        $this->validateNotifyAmount(
            $payOrder,
            $amount['total'] ?? null,
            $amount['currency'] ?? 'CNY',
            'V3'
        );

        if ($status === PaymentPluginStatusConstant::SUCCESS
            && trim((string) ($data['transaction_id'] ?? '')) === '') {
            throw new PaymentException('微信支付 V3 成功通知缺少 transaction_id', 40200);
        }

        return $payOrder;
    }

    /**
     * 校验 V2 支付通知中的订单、商户身份和金额。
     *
     * @param array<string, string> $data 验签后的微信通知
     * @param string $status 标准插件状态
     * @return PayOrder 已校验支付单
     */
    private function validateV2Notify(array $data, string $status): PayOrder
    {
        $resultCode = strtoupper(trim((string) ($data['result_code'] ?? '')));
        if ($resultCode === '') {
            throw new PaymentException('微信支付 V2 通知缺少 result_code', 40200);
        }

        $payOrder = $this->notifyPayOrder((string) ($data['out_trade_no'] ?? ''), 'V2');
        $product = $this->notifyProduct($payOrder, (string) ($data['trade_type'] ?? ''));
        $this->validateV2NotifyIdentity($data, $product);

        if ($status === PaymentPluginStatusConstant::SUCCESS || array_key_exists('total_fee', $data)) {
            $this->validateNotifyAmount(
                $payOrder,
                $data['total_fee'] ?? null,
                $data['fee_type'] ?? 'CNY',
                'V2'
            );
        }

        if ($status === PaymentPluginStatusConstant::SUCCESS
            && trim((string) ($data['transaction_id'] ?? '')) === '') {
            throw new PaymentException('微信支付 V2 成功通知缺少 transaction_id', 40200);
        }

        return $payOrder;
    }

    /**
     * 根据微信商户订单号读取并校验当前通道支付单。
     *
     * @param string $outTradeNo 微信商户订单号
     * @param string $apiVersion 微信支付 API 版本
     * @return PayOrder 支付单
     */
    private function notifyPayOrder(string $outTradeNo, string $apiVersion): PayOrder
    {
        $outTradeNo = trim($outTradeNo);
        if ($outTradeNo === '') {
            throw new PaymentException('微信支付 ' . $apiVersion . ' 通知缺少 out_trade_no', 40200);
        }

        $payOrder = $this->payOrderRepository->findByPayNo(
            $outTradeNo,
            ['pay_no', 'pay_amount', 'channel_id', 'ext_json']
        );
        if (!$payOrder) {
            throw new PaymentException('微信支付通知对应的支付单不存在', 40200, ['out_trade_no' => $outTradeNo]);
        }

        $channelId = (int) $this->getConfig('channel_id', 0);
        if ($channelId > 0 && (int) $payOrder->channel_id !== $channelId) {
            throw new PaymentException('微信支付通知对应的支付单不属于当前通道', 40200, [
                'out_trade_no' => $outTradeNo,
                'channel_id' => $channelId,
                'order_channel_id' => (int) $payOrder->channel_id,
            ]);
        }

        return $payOrder;
    }

    /**
     * 从支付单下单快照读取实际支付产品，并与微信 trade_type 精确匹配。
     *
     * JSAPI 同时承载公众号和小程序支付，不能只按通道内任一 AppID 放行；这里使用
     * 下单成功后保存的 payment_context.pay_product 将通知绑定到具体产品。
     *
     * @param PayOrder $payOrder 支付单
     * @param string $tradeType 微信交易类型
     * @return string MPAY 支付产品标识
     */
    private function notifyProduct(PayOrder $payOrder, string $tradeType): string
    {
        $extJson = (array) ($payOrder->ext_json ?? []);
        $context = (array) ($extJson['payment_context'] ?? []);
        $product = trim((string) ($context['pay_product'] ?? ''));
        if ($product === '') {
            throw new PaymentException('微信支付单缺少下单产品快照', 40200, [
                'pay_no' => (string) $payOrder->pay_no,
            ]);
        }

        $product = $this->normalizeProduct($product);
        $expectedTradeType = match ($product) {
            self::PRODUCT_MP, self::PRODUCT_MINI => 'JSAPI',
            self::PRODUCT_APP => 'APP',
            self::PRODUCT_H5 => 'MWEB',
            self::PRODUCT_SCAN => 'NATIVE',
        };
        $tradeType = strtoupper(trim($tradeType));
        if (!hash_equals($expectedTradeType, $tradeType)) {
            throw new PaymentException('微信支付通知 trade_type 与支付单产品不一致', 40200, [
                'pay_no' => (string) $payOrder->pay_no,
                'pay_product' => $product,
                'trade_type' => $tradeType,
            ]);
        }

        return $product;
    }

    /**
     * 校验 V2 普通商户或服务商通知身份字段。
     *
     * @param array<string, string> $data 通知数据
     * @param string $product 支付产品标识
     * @return void
     */
    private function validateV2NotifyIdentity(array $data, string $product): void
    {
        $this->assertNotifyField('mch_id', $data['mch_id'] ?? '', $this->configText('mch_id'), 'V2');

        if ($this->configText('mode', 'merchant') === 'partner') {
            $this->assertNotifyField(
                'appid',
                $data['appid'] ?? '',
                $this->firstText($this->configText('sp_app_id'), $this->configText('app_id')),
                'V2'
            );
            $this->assertNotifyField('sub_mch_id', $data['sub_mch_id'] ?? '', $this->configText('sub_mch_id'), 'V2');
            $this->validateNotifyAppId(
                'sub_appid',
                (string) ($data['sub_appid'] ?? ''),
                $this->expectedNotifyAppIds($product, true),
                'V2'
            );
            return;
        }

        $this->validateNotifyAppId(
            'appid',
            (string) ($data['appid'] ?? ''),
            $this->expectedNotifyAppIds($product, false),
            'V2'
        );
    }

    /**
     * 校验 V3 普通商户或服务商通知身份字段。
     *
     * @param array<string, mixed> $data 通知数据
     * @param string $product 支付产品标识
     * @return void
     */
    private function validateV3NotifyIdentity(array $data, string $product): void
    {
        if ($this->configText('mode', 'merchant') === 'partner') {
            $this->assertNotifyField(
                'sp_appid',
                $data['sp_appid'] ?? '',
                $this->firstText($this->configText('sp_app_id'), $this->configText('app_id')),
                'V3'
            );
            $this->assertNotifyField('sp_mchid', $data['sp_mchid'] ?? '', $this->configText('mch_id'), 'V3');
            $this->assertNotifyField('sub_mchid', $data['sub_mchid'] ?? '', $this->configText('sub_mch_id'), 'V3');
            $this->validateNotifyAppId(
                'sub_appid',
                (string) ($data['sub_appid'] ?? ''),
                $this->expectedNotifyAppIds($product, true),
                'V3'
            );
            return;
        }

        $this->assertNotifyField('mchid', $data['mchid'] ?? '', $this->configText('mch_id'), 'V3');
        $this->validateNotifyAppId(
            'appid',
            (string) ($data['appid'] ?? ''),
            $this->expectedNotifyAppIds($product, false),
            'V3'
        );
    }

    /**
     * 校验通知金额和币种，MPAY 与微信支付均使用整数分。
     *
     * @param PayOrder $payOrder 支付单
     * @param mixed $total 通知总金额
     * @param mixed $currency 通知币种
     * @param string $apiVersion 微信支付 API 版本
     * @return void
     */
    private function validateNotifyAmount(PayOrder $payOrder, mixed $total, mixed $currency, string $apiVersion): void
    {
        $totalText = trim((string) $total);
        if ($totalText === '' || preg_match('/^\d+$/', $totalText) !== 1) {
            throw new PaymentException('微信支付 ' . $apiVersion . ' 通知金额格式无效', 40200);
        }

        $notifyAmount = (int) $totalText;
        $orderAmount = (int) $payOrder->pay_amount;
        if ($notifyAmount !== $orderAmount) {
            throw new PaymentException('微信支付通知金额与支付单金额不一致', 40200, [
                'pay_no' => (string) $payOrder->pay_no,
                'notify_amount' => $notifyAmount,
                'order_amount' => $orderAmount,
            ]);
        }

        $currency = strtoupper(trim((string) $currency));
        if (($currency === '' ? 'CNY' : $currency) !== 'CNY') {
            throw new PaymentException('微信支付通知币种不是 CNY', 40200, ['currency' => $currency]);
        }
    }

    /**
     * 校验单个商户身份字段。
     *
     * @param string $field 字段名
     * @param mixed $actual 通知字段值
     * @param string $expected 通道配置值
     * @param string $apiVersion 微信支付 API 版本
     * @return void
     */
    private function assertNotifyField(string $field, mixed $actual, string $expected, string $apiVersion): void
    {
        $actual = trim((string) $actual);
        if ($expected === '' || !hash_equals($expected, $actual)) {
            throw new PaymentException('微信支付 ' . $apiVersion . ' 通知 ' . $field . ' 与通道配置不一致', 40200);
        }
    }

    /**
     * 校验通知 AppID。服务商 Native/H5 未提交 sub_appid 时允许通知不返回该字段。
     *
     * @param string $field 字段名
     * @param string $actual 通知 AppID
     * @param array<int, string> $expectedAppIds 允许的 AppID
     * @param string $apiVersion 微信支付 API 版本
     * @return void
     */
    private function validateNotifyAppId(string $field, string $actual, array $expectedAppIds, string $apiVersion): void
    {
        $actual = trim($actual);
        if ($expectedAppIds === []) {
            if ($actual !== '') {
                throw new PaymentException('微信支付 ' . $apiVersion . ' 通知包含未配置的 ' . $field, 40200);
            }
            return;
        }

        if ($actual === '' || !in_array($actual, $expectedAppIds, true)) {
            throw new PaymentException('微信支付 ' . $apiVersion . ' 通知 ' . $field . ' 与支付产品配置不一致', 40200);
        }
    }

    /**
     * 获取支付单实际产品对应的唯一 AppID。
     *
     * @param string $product 支付产品标识
     * @param bool $partnerSubAppId 是否读取服务商子商户 AppID
     * @return array<int, string>
     */
    private function expectedNotifyAppIds(string $product, bool $partnerSubAppId): array
    {
        $appId = $partnerSubAppId
            ? $this->partnerSubAppId($product)
            : $this->productAppId($product);

        return $appId === '' ? [] : [$appId];
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
     * 读取原支付实际使用的微信产品。
     *
     * 查单、关单和退款必须沿用支付单 payment_context.pay_product，不能使用当前
     * enabled_products 的第一项代替，否则多产品通道可能选到错误 AppID。
     */
    private function operationProduct(array $order): string
    {
        $product = trim((string) ($order['pay_product'] ?? ''));
        if (!in_array($product, [
            self::PRODUCT_MP,
            self::PRODUCT_H5,
            self::PRODUCT_APP,
            self::PRODUCT_MINI,
            self::PRODUCT_SCAN,
        ], true)) {
            throw new PaymentException('微信支付订单缺少有效支付产品上下文', 40200, [
                'pay_no' => (string) ($order['pay_no'] ?? ''),
                'pay_product' => $product,
            ]);
        }

        return $product;
    }

    /**
     * 获取商户订单号。
     *
     * @param array<string, mixed> $order 标准插件订单参数
     * @return string 商户订单号
     */
    private function outTradeNo(array $order): string
    {
        // 下单异常尚未回写渠道单号时，用实际下单发送的 pay_no 查询原订单。
        $outTradeNo = trim((string) ($order['chan_order_no'] ?? '')) ?: (string) $order['pay_no'];
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
        return trim((string) ($order['chan_trade_no'] ?? ''));
    }

    /**
     * 主动查单状态映射。
     *
     * @param string $tradeState 微信交易状态
     * @return string MPAY 标准状态
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
     *
     * @param string $tradeState 微信交易状态
     * @return string MPAY 标准状态
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
            'wechatpay_public_key_id' => $apiVersion === WxpayClient::API_VERSION_V3 ? $this->configText('wechatpay_public_key_id') : '',
            'wechatpay_public_key_path' => $apiVersion === WxpayClient::API_VERSION_V3 ? $this->uploadedPrivateFilePath($this->configText('wechatpay_public_key_path')) : '',
            'platform_cert_path' => $apiVersion === WxpayClient::API_VERSION_V3 ? $this->uploadedPrivateFilePath($this->configText('platform_cert_path')) : '',
            'verify_response' => $apiVersion === WxpayClient::API_VERSION_V3,
            'api_key' => $apiVersion === WxpayClient::API_VERSION_V2 ? $this->configText('api_key') : '',
            'previous_api_key' => $apiVersion === WxpayClient::API_VERSION_V2 ? $this->configText('previous_api_key') : '',
            'v2_sign_type' => $apiVersion === WxpayClient::API_VERSION_V2 ? $this->configText('v2_sign_type', 'HMAC-SHA256') : '',
            'cert_path' => $apiVersion === WxpayClient::API_VERSION_V2 ? $this->uploadedPrivateFilePath($this->configText('cert_path')) : '',
            'key_path' => $apiVersion === WxpayClient::API_VERSION_V2 ? $this->uploadedPrivateFilePath($this->configText('key_path')) : '',
            'sandbox' => $apiVersion === WxpayClient::API_VERSION_V2 && $this->configBool('sandbox'),
        ];
    }

    /**
     * 获取当前接口版本。
     *
     * @return string 微信支付 API 版本
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
     * 将上传组件保存的运行时相对路径转换为本机路径，并兼容已保存的绝对路径配置。
     *
     * 本方法只负责路径形态转换；文件存在性、可读性及证书内容由 SDK 初始化阶段校验。
     *
     * @param string $path 上传组件写入配置的 object_key 或绝对路径
     *
     * @return string SDK 使用的本机路径
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
     *
     * @param string $key 配置键
     * @param string $default 默认值
     * @return string 配置值
     */
    private function configText(string $key, string $default = ''): string
    {
        return trim((string) $this->getConfig($key, $default));
    }

    /**
     * 读取布尔配置。
     *
     * @param string $key 配置键
     * @param bool $default 默认值
     * @return bool 配置值
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
