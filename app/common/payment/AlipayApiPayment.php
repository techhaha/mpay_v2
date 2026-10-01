<?php

declare(strict_types=1);

namespace app\common\payment;

use app\common\base\BasePayment;
use app\common\constant\EpayProtocolConstant;
use app\common\constant\FileConstant;
use app\common\constant\PaymentMethodConstant;
use app\common\constant\PaymentPluginTypeConstant;
use app\common\constant\PaymentPluginStatusConstant;
use app\common\interface\PaymentIdentityRequirementInterface;
use app\common\interface\PaymentInterface;
use app\common\interface\PayPluginInterface;
use app\common\interface\RefundQueryInterface;
use app\common\sdk\alipay\AlipayClient;
use app\common\sdk\alipay\AlipayResponse;
use app\common\sdk\alipay\AlipaySdkException;
use app\common\util\FormatHelper;
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
 * 支付宝官方 API 支付插件。
 *
 * 该插件用于 MPAY 通过支付宝 OpenAPI 发起官方支付产品请求，底层调用
 * app/common/sdk/alipay 目录下的轻量 SDK。
 */
class AlipayApiPayment extends BasePayment implements PaymentInterface, PayPluginInterface, PaymentIdentityRequirementInterface, RefundQueryInterface
{
    private const PRODUCT_WEB = 'web';
    private const PRODUCT_H5 = 'h5';
    private const PRODUCT_APP = 'app';
    private const PRODUCT_MINI = 'mini';
    private const PRODUCT_POS = 'pos';
    private const PRODUCT_SCAN = 'scan';

    /**
     * 支付宝轻量 SDK 客户端。
     *
     * @var AlipayClient|null
     */
    private ?AlipayClient $client = null;

    /**
     * 创建支付宝支付插件。
     *
     * @param PayOrderRepository $payOrderRepository 支付单仓库
     */
    public function __construct(private readonly PayOrderRepository $payOrderRepository)
    {
    }

    /**
     * 插件元信息和后台配置表单。
     *
     * @var array<string, mixed>
     */
    protected array $paymentInfo = [
        'code' => 'alipay_api',
        'name' => '支付宝官方API支付',
        'plugin_type' => PaymentPluginTypeConstant::TYPE_DIRECT,
        'author' => 'MPAY',
        'version' => '2.0.0',
        'pay_types' => ['alipay'],
        'transfer_types' => [],
        'config_schema' => [],
    ];

    /**
     * 获取插件配置结构。
     *
     * 配置人必须明确勾选当前支付宝应用已签约的产品，插件下单时会按产品做能力校验。
     *
     * @return array<int, array<string, mixed>>
     */
    public function getConfigSchema(): array
    {
        return [
            [
                'type' => 'radio',
                'field' => 'mode',
                'title' => '加签模式',
                'value' => 'key',
                'options' => [
                    ['label' => '密钥模式', 'value' => 'key'],
                    ['label' => '证书模式', 'value' => 'cert'],
                ],
                'control' => [
                    $this->modeControl('key', ['private_key', 'alipay_public_key']),
                    $this->modeControl('key', ['private_key', 'alipay_public_key'], 'required'),
                    $this->modeControl('cert', ['private_key', 'app_cert_path', 'alipay_cert_path', 'alipay_root_cert_path']),
                    $this->modeControl('cert', ['private_key', 'app_cert_path', 'alipay_cert_path', 'alipay_root_cert_path'], 'required'),
                ],
                'validate' => [
                    ['required' => true, 'message' => '加签模式不能为空'],
                ],
            ],
            [
                'type' => 'input',
                'field' => 'app_id',
                'title' => '支付宝应用 AppID',
                'value' => '',
                'validate' => [
                    ['required' => true, 'message' => '支付宝应用 AppID 不能为空'],
                ],
            ],
            [
                'type' => 'textarea',
                'field' => 'private_key',
                'title' => '应用私钥',
                'value' => '',
                'props' => ['rows' => 5],
            ],
            [
                'type' => 'textarea',
                'field' => 'alipay_public_key',
                'title' => '支付宝公钥(密钥模式)',
                'value' => '',
                'props' => ['rows' => 4],
            ],
            [
                'type' => 'upload',
                'field' => 'app_cert_path',
                'title' => '应用公钥证书文件(证书模式)',
                'value' => '',
                'props' => $this->uploadProps('.crt,.cer,.pem'),
            ],
            [
                'type' => 'upload',
                'field' => 'alipay_cert_path',
                'title' => '支付宝公钥证书文件(证书模式)',
                'value' => '',
                'props' => $this->uploadProps('.crt,.cer,.pem'),
            ],
            [
                'type' => 'upload',
                'field' => 'alipay_root_cert_path',
                'title' => '支付宝根证书文件(证书模式)',
                'value' => '',
                'props' => $this->uploadProps('.crt,.cer,.pem'),
            ],
            [
                'type' => 'checkbox',
                'field' => 'enabled_products',
                'title' => '已开通支付宝产品',
                'value' => [],
                'options' => $this->productOptions(),
                'control' => [
                    $this->productControl([self::PRODUCT_POS, self::PRODUCT_SCAN], ['store_id', 'operator_id', 'terminal_id']),
                    $this->productControl(self::PRODUCT_MINI, ['mini_app_id', 'mini_launch_path']),
                    $this->productControl(self::PRODUCT_MINI, ['mini_app_id'], 'required'),
                ],
                'validate' => [
                    ['required' => true, 'message' => '请至少勾选一个已开通支付宝产品'],
                ],
            ],
            [
                'type' => 'input',
                'field' => 'seller_id',
                'title' => '收款支付宝用户ID',
                'value' => '',
                'props' => ['placeholder' => '支付宝商户 PID，用于异步通知 seller_id 校验'],
                'validate' => [
                    ['required' => true, 'message' => '收款支付宝用户ID不能为空'],
                ],
            ],
            [
                'type' => 'input',
                'field' => 'service_provider_id',
                'title' => '系统服务商PID',
                'value' => '',
                'props' => ['placeholder' => '选填；服务商返佣/代调用场景填写'],
            ],
            [
                'type' => 'input',
                'field' => 'store_id',
                'title' => '商户门店编号',
                'value' => '',
                'props' => ['placeholder' => '选填；刷卡支付、扫码支付等线下场景建议填写'],
            ],
            [
                'type' => 'input',
                'field' => 'operator_id',
                'title' => '商户操作员编号',
                'value' => '',
                'props' => ['placeholder' => '选填；线下收银场景使用'],
            ],
            [
                'type' => 'input',
                'field' => 'terminal_id',
                'title' => '商户终端编号',
                'value' => '',
                'props' => ['placeholder' => '选填；线下机具终端号'],
            ],
            [
                'type' => 'password',
                'field' => 'app_auth_token',
                'title' => '应用授权Token',
                'value' => '',
                'props' => ['placeholder' => '选填；服务商代调用商户应用时填写'],
            ],
            [
                'type' => 'input',
                'field' => 'mini_app_id',
                'title' => '小程序支付 AppID',
                'value' => '',
                'props' => ['placeholder' => '勾选小程序支付时建议填写'],
            ],
            [
                'type' => 'input',
                'field' => 'mini_launch_path',
                'title' => '小程序承接页面路径',
                'value' => '',
                'props' => ['placeholder' => '例如 pages/pay/index；留空打开小程序首页'],
            ],
            [
                'type' => 'switch',
                'field' => 'sandbox',
                'title' => '沙箱环境',
                'value' => false,
            ],
        ];
    }

    /**
     * 初始化插件。
     *
     * 每次通道配置注入后都重置 SDK 客户端，避免复用上一通道的密钥或证书。
     *
     * @param array<string, mixed> $channelConfig 通道配置
     * @return void
     */
    public function init(array $channelConfig): void
    {
        parent::init($channelConfig);
        $this->client = null;

        if ($this->configText('seller_id') === '') {
            throw new PaymentException('支付宝 seller_id 不能为空', 40200);
        }
        $products = $this->enabledProducts();
        $supported = [
            self::PRODUCT_WEB,
            self::PRODUCT_H5,
            self::PRODUCT_APP,
            self::PRODUCT_MINI,
            self::PRODUCT_POS,
            self::PRODUCT_SCAN,
        ];
        if ($products === [] || array_diff($products, $supported) !== []) {
            throw new PaymentException('支付宝已开通产品配置无效', 40200);
        }

        // 初始化阶段完成密钥、证书、模式冲突和文件可读性校验。
        $this->client();
    }

    /**
     * 声明支付宝 JSAPI 支付是否需要先获取 buyer_id 或 buyer_open_id。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed>|null 身份需求
     */
    public function identityRequirement(array $order): ?array
    {
        $product = $this->resolveIdentityProduct($order);
        if ($product !== self::PRODUCT_MINI) {
            return null;
        }

        $payment = $this->paymentPayload($order);
        if ($this->hasMiniPayload($payment)) {
            return null;
        }

        return [
            'provider' => 'alipay',
            'product' => self::PRODUCT_MINI,
            'channel_product' => self::PRODUCT_MINI,
            'auth_type' => 'alipay_mini',
            'identity_field' => 'buyer_id',
            'identity_aliases' => ['buyer_open_id'],
            'app_id' => $this->firstText($this->configText('mini_app_id'), $this->configText('app_id')),
            'mini_path' => $this->configText('mini_launch_path'),
            'scope' => 'auth_base',
            '_alipay_config' => $this->sdkConfig(),
            'message' => '支付宝 JSAPI 支付需要先获取当前用户 buyer_id 或 buyer_open_id',
        ];
    }

    /**
     * 支付产品多选项。
     *
     * @return array<int, array{label:string,value:string}>
     */
    private function productOptions(): array
    {
        return [
            ['label' => '当面付', 'value' => self::PRODUCT_POS],
            ['label' => '订单码支付', 'value' => self::PRODUCT_SCAN],
            ['label' => 'JSAPI支付', 'value' => self::PRODUCT_MINI],
            ['label' => 'APP支付', 'value' => self::PRODUCT_APP],
            ['label' => '手机网站支付', 'value' => self::PRODUCT_H5],
            ['label' => '电脑网站支付', 'value' => self::PRODUCT_WEB],
        ];
    }

    /**
     * 构造加签模式字段联动规则。
     *
     * 密钥模式只展示支付宝公钥，证书模式只展示三份证书；必填状态也随模式切换。
     *
     * @param string $mode 加签模式
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
     * 获取支付宝 SDK 客户端。
     *
     * @return AlipayClient SDK 客户端
     */
    private function client(): AlipayClient
    {
        if ($this->client === null) {
            try {
                $this->client = new AlipayClient($this->sdkConfig());
            } catch (AlipaySdkException $e) {
                throw new PaymentException($e->getMessage(), 40200, [
                    'channel_id' => (int) $this->getConfig('channel_id', 0),
                    'sdk_category' => $e->category(),
                    ...$e->context(),
                ]);
            }
        }

        return $this->client;
    }

    /**
     * 构造 SDK 配置。
     *
     * @return array<string, mixed>
     */
    private function sdkConfig(): array
    {
        $mode = $this->configText('mode', 'key');

        return [
            'mode' => $mode,
            'app_id' => $this->configText('app_id'),
            'private_key' => $this->configText('private_key'),
            'alipay_public_key' => $this->configText('alipay_public_key'),
            'app_cert_path' => $mode === 'cert' ? $this->uploadedPrivateFilePath($this->configText('app_cert_path')) : '',
            'alipay_cert_path' => $mode === 'cert' ? $this->uploadedPrivateFilePath($this->configText('alipay_cert_path')) : '',
            'alipay_root_cert_path' => $mode === 'cert' ? $this->uploadedPrivateFilePath($this->configText('alipay_root_cert_path')) : '',
            'sandbox' => $this->configBool('sandbox'),
            'app_auth_token' => $this->configText('app_auth_token'),
        ];
    }

    /**
     * 将上传组件保存的本地私有 object_key 转换为本机绝对路径。
     *
     * 上传、保存和文件落盘都由文件服务处理；插件只在运行时把已保存的相对路径交给 SDK 读取。
     *
     * @param string $path 上传组件写入配置的私有 object_key
     * @return string 可读取的本机路径
     */
    private function uploadedPrivateFilePath(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path));
        if ($path === '') {
            return '';
        }

        if (str_contains($path, '..') || !str_starts_with($path, FileConstant::LOCAL_PRIVATE_DIR . '/')) {
            throw new PaymentException('支付宝证书必须使用项目私有文件上传能力', 40200);
        }

        return runtime_path(trim($path, '/'));
    }

    /**
     * 获取已确认开通的支付宝产品。
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

        return array_values(array_filter(array_map(
            static fn ($value): string => trim((string) $value),
            $products
        )));
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
     * 解析本次下单唯一使用的支付宝产品。
     *
     * 外部 API 显式 method 优先；未指定时才按支付环境从已开通产品中确定唯一产品。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return string 产品标识
     */
    private function resolveProduct(array $order): string
    {
        $enabledProducts = $this->enabledProducts();
        $payment = $this->paymentPayload($order);
        $selectedProduct = trim((string) ($payment['selected_product'] ?? ''));
        if ($selectedProduct !== '') {
            if (!in_array($selectedProduct, $enabledProducts, true)) {
                throw new PaymentProductUnavailableException('身份授权产品未在当前支付宝通道开通', 40200, [
                    'selected_product' => $selectedProduct,
                    'enabled_products' => $enabledProducts,
                ]);
            }

            return $selectedProduct;
        }

        $explicit = $this->explicitProduct($order);
        if ($explicit !== '') {
            if (!in_array($explicit, $enabledProducts, true)) {
                throw new PaymentProductUnavailableException('当前支付宝通道未开通指定支付产品', 40200, [
                    'pay_product' => $explicit,
                ]);
            }

            return $explicit;
        }

        foreach ($this->productCandidates($order) as $candidate) {
            if (in_array($candidate, $enabledProducts, true)) {
                return $candidate;
            }
        }

        throw new PaymentProductUnavailableException('当前支付宝通道没有开通适合该支付环境的产品', 40200, [
            'env' => $this->paymentEnv($order),
            'candidate_products' => $this->productCandidates($order),
            'enabled_products' => $enabledProducts,
        ]);
    }

    /**
     * 解析身份流程需要关注的支付宝产品。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return string 产品标识
     */
    private function resolveIdentityProduct(array $order): string
    {
        return $this->resolveProduct($order);
    }

    /**
     * 根据支付环境生成支付宝产品候选列表。
     *
     * 列表仅用于按环境选择已开通产品；产品确定后不会因下单失败切换到其他产品。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<int, string>
     */
    private function productCandidates(array $order): array
    {
        $payment = $this->paymentPayload($order);
        if (trim((string) ($payment['auth_code'] ?? '')) !== '') {
            return [self::PRODUCT_POS];
        }

        $env = $this->paymentEnv($order);
        $candidates = match ($env) {
            EpayProtocolConstant::DEVICE_MOBILE,
            EpayProtocolConstant::DEVICE_QQ,
            EpayProtocolConstant::DEVICE_WECHAT => [self::PRODUCT_H5, self::PRODUCT_WEB, self::PRODUCT_SCAN],
            EpayProtocolConstant::DEVICE_ALIPAY => [self::PRODUCT_MINI, self::PRODUCT_H5, self::PRODUCT_WEB, self::PRODUCT_SCAN],
            EpayProtocolConstant::DEVICE_JUMP => [self::PRODUCT_H5, self::PRODUCT_WEB, self::PRODUCT_SCAN],
            default => [self::PRODUCT_WEB, self::PRODUCT_SCAN, self::PRODUCT_H5],
        };

        return array_values(array_unique($candidates));
    }

    /**
     * 将标准支付调用方式解析成唯一支付宝产品。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return string 支付宝产品标识；未显式指定时返回空字符串
     */
    private function explicitProduct(array $order): string
    {
        $payment = $this->paymentPayload($order);
        $method = strtolower(trim((string) ($payment['method'] ?? '')));
        if ($method === '') {
            return '';
        }

        $product = match ($method) {
            PaymentMethodConstant::WEB => self::PRODUCT_WEB,
            PaymentMethodConstant::H5 => self::PRODUCT_H5,
            PaymentMethodConstant::JUMP => $this->paymentEnv($order) === EpayProtocolConstant::DEVICE_PC
                ? self::PRODUCT_WEB
                : self::PRODUCT_H5,
            PaymentMethodConstant::APP => self::PRODUCT_APP,
            PaymentMethodConstant::JSAPI,
            PaymentMethodConstant::MINI,
            PaymentMethodConstant::URL_SCHEME => self::PRODUCT_MINI,
            PaymentMethodConstant::AUTH_CODE => self::PRODUCT_POS,
            PaymentMethodConstant::QRCODE => self::PRODUCT_SCAN,
            default => throw new PaymentException('不支持的支付宝支付调用方式', 40200, ['method' => $method]),
        };

        if (!$this->methodAllowed($method, $this->paymentEnv($order))) {
            throw new PaymentException('指定支付宝支付方式与当前支付环境不匹配', 40200, [
                'method' => $method,
                'env' => $this->paymentEnv($order),
            ]);
        }

        return $product;
    }

    /**
     * 校验显式调用方式是否适合当前终端环境。
     */
    private function methodAllowed(string $method, string $env): bool
    {
        return match ($method) {
            PaymentMethodConstant::WEB => $env === EpayProtocolConstant::DEVICE_PC,
            PaymentMethodConstant::H5 => !in_array($env, [
                EpayProtocolConstant::DEVICE_PC,
                EpayProtocolConstant::DEVICE_ALIPAY,
            ], true),
            PaymentMethodConstant::JSAPI => $env === EpayProtocolConstant::DEVICE_ALIPAY,
            PaymentMethodConstant::MINI,
            PaymentMethodConstant::URL_SCHEME => in_array($env, [
                EpayProtocolConstant::DEVICE_MOBILE,
                EpayProtocolConstant::DEVICE_QQ,
                EpayProtocolConstant::DEVICE_WECHAT,
                EpayProtocolConstant::DEVICE_ALIPAY,
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
        $env = strtolower(trim((string) ($order['_env'] ?? 'pc')));

        return in_array($env, EpayProtocolConstant::v1Devices(), true) ? $env : EpayProtocolConstant::DEVICE_PC;
    }

    /**
     * 判断当前载荷是否具备小程序支付必要上下文。
     *
     * @param array<string, mixed> $payment 支付扩展载荷
     * @return bool 是否可以优先尝试小程序支付
     */
    private function hasMiniPayload(array $payment): bool
    {
        $opAppId = $this->firstText($payment['op_app_id'] ?? '', $this->configText('mini_app_id'));
        $buyer = $this->firstText($payment['buyer_open_id'] ?? '', $payment['buyer_id'] ?? '');

        return $opAppId !== '' && $buyer !== '';
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
     * 构造支付宝通用 biz_content。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param string $product 支付宝产品标识
     * @return array<string, mixed>
     */
    private function baseBizContent(array $order, string $product): array
    {
        $subject = trim(preg_replace('/[\/=&#]+/u', ' ', (string) $order['subject']) ?? '');
        $subject = mb_strcut($subject, 0, 256, 'UTF-8');
        if ($subject === '') {
            throw new PaymentException('支付宝订单标题不能为空或仅包含受限字符', 40200);
        }

        $biz = [
            'out_trade_no' => (string) $order['pay_no'],
            'total_amount' => FormatHelper::amount((int) $order['amount']),
            'subject' => $subject,
            'passback_params' => rawurlencode((string) json_encode([
                'mpay_pay_product' => $product,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
        ];

        $body = trim((string) ($order['body'] ?? ''));
        if ($body !== '') {
            $biz['body'] = mb_strcut($body, 0, 128, 'UTF-8');
        }

        foreach (['seller_id', 'store_id', 'operator_id', 'terminal_id'] as $key) {
            $value = $this->configText($key);
            if ($value !== '') {
                $biz[$key] = $value;
            }
        }

        $serviceProviderId = $this->configText('service_provider_id');
        if ($serviceProviderId !== '') {
            $biz['extend_params'] = ['sys_service_provider_id' => $serviceProviderId];
        }

        return $biz;
    }

    /**
     * 构造支付宝公共请求参数覆盖项。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed>
     */
    private function requestOptions(array $order): array
    {
        return [
            'notify_url' => (string) $order['callback_url'],
            'return_url' => (string) ($order['return_url'] ?? ''),
            'app_auth_token' => $this->configText('app_auth_token'),
            'http_method' => 'POST',
        ];
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

    /**
     * 发起支付宝支付。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed>
     */
    public function pay(array $order): array
    {
        $product = $this->resolveProduct($order);
        $this->preparePayProduct($product);

        try {
            return $this->pendingPaymentResult($order, $this->payByProduct($product, $order));
        } catch (PaymentException $e) {
            // 产品来自本地选择，不允许 SDK 诊断字段覆盖；保留原异常的确定性分类。
            $exception = $e::class;
            throw new $exception($e->getMessage(), (int) ($e->getCode() ?: 40200), array_replace($e->getData(), [
                'pay_type' => 'alipay',
                'pay_product' => $product,
            ]));
        } catch (Throwable $e) {
            throw new PaymentUncertainException($e->getMessage(), 40200, [
                'pay_type' => 'alipay',
                'pay_product' => $product,
                'channel_error_code' => 'PLUGIN_CREATE_ORDER_ERROR',
                'exception_class' => $e::class,
            ]);
        }
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
            self::PRODUCT_POS => $this->payPos($order),
            self::PRODUCT_SCAN => $this->payScan($order),
            self::PRODUCT_MINI => $this->payMini($order),
            self::PRODUCT_APP => $this->payApp($order),
            self::PRODUCT_H5 => $this->payH5($order),
            self::PRODUCT_WEB => $this->payWeb($order),
            default => throw new PaymentException('不支持的支付宝支付产品：' . $product, 40200),
        };
    }

    /**
     * 刷卡支付。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed>
     */
    private function payPos(array $order): array
    {
        $payment = $this->paymentPayload($order);
        $authCode = trim((string) ($payment['auth_code'] ?? ''));
        if ($authCode === '') {
            throw new PaymentException('刷卡支付必须传入付款码 auth_code', 40200);
        }

        $biz = $this->baseBizContent($order, self::PRODUCT_POS);
        $biz['auth_code'] = $authCode;

        $response = $this->callAlipay(fn () => $this->client()->faceToFacePay($biz, $this->requestOptions($order)));
        if (!$response->success() && $response->code() !== '10003') {
            $this->throwAlipayFailure($response, '支付宝刷卡支付失败');
        }

        $data = $response->data();

        return [
            'pay_page' => 'page',
            'pay_type' => 'alipay',
            'pay_product' => self::PRODUCT_POS,
            'pay_action' => 'scan',
            'pay_params' => [
                '_page' => 'page',
                'params' => '支付请求已受理，最终结果以异步通知或主动查单为准',
                'raw' => $response->toArray(),
            ],
            'chan_order_no' => (string) ($data['out_trade_no'] ?? $order['pay_no']),
            'chan_trade_no' => (string) ($data['trade_no'] ?? ''),
        ];
    }

    /**
     * 扫码支付。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed>
     */
    private function payScan(array $order): array
    {
        $response = $this->callAlipay(fn () => $this->client()->precreate(
            $this->baseBizContent($order, self::PRODUCT_SCAN),
            $this->requestOptions($order)
        ));
        $this->ensureAlipaySuccess($response, '支付宝扫码支付预创建失败');

        $data = $response->data();
        $qrCode = (string) ($data['qr_code'] ?? '');
        if ($qrCode === '') {
            throw new PaymentException('支付宝扫码支付预创建未返回 qr_code', 40200, [
                'response' => $response->toArray(),
            ]);
        }

        return [
            'pay_page' => 'qrcode',
            'pay_type' => 'alipay',
            'pay_product' => self::PRODUCT_SCAN,
            'pay_action' => 'qrcode',
            'pay_params' => [
                'qrcode' => $qrCode,
                'raw' => $response->toArray(),
            ],
            'chan_order_no' => (string) ($data['out_trade_no'] ?? $order['pay_no']),
            'chan_trade_no' => (string) ($data['trade_no'] ?? ''),
        ];
    }

    /**
     * 小程序支付。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed>
     */
    private function payMini(array $order): array
    {
        $payment = $this->paymentPayload($order);
        $biz = $this->baseBizContent($order, self::PRODUCT_MINI);
        $biz['op_app_id'] = $this->firstText($payment['op_app_id'] ?? '', $this->configText('mini_app_id'));

        $buyerOpenId = $this->firstText($payment['buyer_open_id'] ?? '');
        $buyerId = $this->firstText($payment['buyer_id'] ?? '');
        if ($biz['op_app_id'] === '') {
            throw new PaymentException('支付宝小程序支付必须配置或传入小程序 AppID', 40200);
        }
        if ($buyerOpenId === '' && $buyerId === '') {
            throw new PaymentException('支付宝小程序支付必须传入 buyer_open_id 或 buyer_id', 40200);
        }
        if ($buyerOpenId !== '') {
            $biz['buyer_open_id'] = $buyerOpenId;
        } else {
            $biz['buyer_id'] = $buyerId;
        }

        $response = $this->callAlipay(fn () => $this->client()->jsapiCreate($biz, $this->requestOptions($order)));
        $this->ensureAlipaySuccess($response, '支付宝小程序支付创建交易失败');

        $data = $response->data();
        $tradeNo = (string) ($data['trade_no'] ?? '');
        if ($tradeNo === '') {
            throw new PaymentException('支付宝小程序支付创建交易未返回 trade_no', 40200, [
                'response' => $response->toArray(),
            ]);
        }

        return [
            'pay_page' => 'page',
            'pay_type' => 'alipay',
            'pay_product' => self::PRODUCT_MINI,
            'pay_action' => 'jsapi',
            'pay_params' => [
                '_page' => 'alipayMini',
                'tradeNO' => $tradeNo,
                'trade_no' => $tradeNo,
                'description' => '小程序支付参数已生成，请在支付宝小程序容器中调用 my.tradePay。',
                'raw' => $response->toArray(),
            ],
            'chan_order_no' => (string) ($data['out_trade_no'] ?? $order['pay_no']),
            'chan_trade_no' => $tradeNo,
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
        $result = $this->callAlipay(fn () => $this->client()->appPay(
            $this->baseBizContent($order, self::PRODUCT_APP),
            $this->requestOptions($order)
        ));
        $orderString = (string) ($result['order_string'] ?? '');
        if ($orderString === '') {
            throw new PaymentException('支付宝 APP 支付未生成 order_string', 40200);
        }

        return [
            'pay_page' => 'page',
            'pay_type' => 'alipay',
            'pay_product' => self::PRODUCT_APP,
            'pay_action' => 'app',
            'pay_params' => [
                '_page' => 'page',
                'params' => $orderString,
                'description' => 'APP 支付仅供原生商户 App 调用支付宝 SDK 使用，网页收银台不会自动唤起。',
                'order_string' => $orderString,
            ],
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
        $biz = $this->baseBizContent($order, self::PRODUCT_H5);
        $quitUrl = trim((string) ($order['return_url'] ?? ''));
        if ($quitUrl !== '') {
            $biz['quit_url'] = $quitUrl;
        }

        $result = $this->callAlipay(fn () => $this->client()->wapPay($biz, $this->requestOptions($order)));
        $url = (string) ($result['url'] ?? '');
        if ($url === '') {
            throw new PaymentException('支付宝 H5 支付未生成跳转地址', 40200);
        }

        return [
            'pay_page' => 'jump',
            'pay_type' => 'alipay',
            'pay_product' => self::PRODUCT_H5,
            'pay_action' => 'jump',
            'pay_params' => [
                'url' => $url,
                'method' => 'post',
                'action' => (string) ($result['action'] ?? ''),
                'payload' => (array) ($result['payload'] ?? []),
                'description' => '正在跳转支付宝 H5 支付。',
            ],
            'chan_order_no' => (string) $order['pay_no'],
            'chan_trade_no' => '',
        ];
    }

    /**
     * 网页支付。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed>
     */
    private function payWeb(array $order): array
    {
        $biz = $this->baseBizContent($order, self::PRODUCT_WEB);

        $result = $this->callAlipay(fn () => $this->client()->pagePay($biz, $this->requestOptions($order)));
        $url = (string) ($result['url'] ?? '');
        if ($url === '') {
            throw new PaymentException('支付宝网页支付未生成跳转地址', 40200);
        }

        return [
            'pay_page' => 'jump',
            'pay_type' => 'alipay',
            'pay_product' => self::PRODUCT_WEB,
            'pay_action' => 'jump',
            'pay_params' => [
                'url' => $url,
                'method' => 'post',
                'action' => (string) ($result['action'] ?? ''),
                'payload' => (array) ($result['payload'] ?? []),
                'description' => '正在跳转支付宝网页支付。',
            ],
            'chan_order_no' => (string) $order['pay_no'],
            'chan_trade_no' => '',
        ];
    }

    /**
     * 校验支付宝 OpenAPI 响应成功。
     *
     * @param AlipayResponse $response SDK 响应
     * @param string $message 失败提示
     * @return void
     */
    private function ensureAlipaySuccess(AlipayResponse $response, string $message): void
    {
        if (!$response->success()) {
            $this->throwAlipayFailure($response, $message);
        }
    }

    /**
     * 抛出支付宝业务失败异常。
     *
     * @param AlipayResponse $response SDK 响应
     * @param string $message 失败提示
     * @return never
     */
    private function throwAlipayFailure(AlipayResponse $response, string $message): never
    {
        $channelCode = $response->subCode() !== '' ? $response->subCode() : $response->code();
        $exception = $this->alipayErrorType($response) === 'business'
            ? PaymentDefinitiveException::class
            : PaymentUncertainException::class;

        throw new $exception(
            $response->subMsg() !== '' ? $response->subMsg() : ($response->msg() !== '' ? $response->msg() : $message),
            40200,
            [
                'channel_id' => (int) $this->getConfig('channel_id', 0),
                'channel_error_code' => $channelCode,
                'alipay_request_id' => $response->requestId(),
                'error_type' => $this->alipayErrorType($response),
            ]
        );
    }

    /**
     * 根据支付宝响应码区分业务错误、网关错误和协议错误。
     *
     * @param AlipayResponse $response 支付宝响应
     * @return string 错误类型
     */
    private function alipayErrorType(AlipayResponse $response): string
    {
        if ($response->subCode() !== '' || $response->code() === '40004') {
            return 'business';
        }

        return in_array($response->code(), ['20000', '20001'], true) ? 'gateway' : 'protocol';
    }

    /**
     * 构造支付宝交易标识。
     *
     * 支付宝接口支持 trade_no 或 out_trade_no 二选一，优先使用支付宝交易号。
     *
     * @param array<string, mixed> $order 标准插件订单参数
     * @return array<string, string>
     */
    private function tradeIdentity(array $order): array
    {
        $tradeNo = trim((string) ($order['chan_trade_no'] ?? ''));
        if ($tradeNo !== '') {
            return ['trade_no' => $tradeNo];
        }

        // 下单异常尚未回写渠道单号时，用实际下单发送的 pay_no 查询原订单。
        $outTradeNo = trim((string) ($order['chan_order_no'] ?? '')) ?: (string) $order['pay_no'];
        if ($outTradeNo === '') {
            throw new PaymentException('支付宝交易标识不能为空', 40200);
        }

        return ['out_trade_no' => $outTradeNo];
    }

    /**
     * 主动查单状态映射。
     *
     * @param string $tradeStatus 支付宝交易状态
     * @return string MPAY 标准状态
     */
    private function tradeStatus(string $tradeStatus): string
    {
        return match ($tradeStatus) {
            'TRADE_SUCCESS', 'TRADE_FINISHED' => PaymentPluginStatusConstant::SUCCESS,
            'TRADE_CLOSED' => PaymentPluginStatusConstant::CLOSED,
            default => PaymentPluginStatusConstant::PENDING,
        };
    }

    /**
     * 异步通知状态映射。
     *
     * @param string $tradeStatus 支付宝交易状态
     * @return string MPAY 标准状态
     */
    private function notifyStatus(string $tradeStatus): string
    {
        return match ($tradeStatus) {
            'TRADE_SUCCESS', 'TRADE_FINISHED' => PaymentPluginStatusConstant::SUCCESS,
            'TRADE_CLOSED' => PaymentPluginStatusConstant::FAILED,
            default => PaymentPluginStatusConstant::PENDING,
        };
    }

    /**
     * 执行 SDK 调用并转换异常。
     *
     * @param callable $callback SDK 调用闭包
     * @return mixed SDK 返回值
     */
    private function callAlipay(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (AlipaySdkException $e) {
            throw new PaymentUncertainException($e->getMessage(), 40200, [
                'channel_id' => (int) $this->getConfig('channel_id', 0),
                'sdk_category' => $e->category(),
                ...$e->context(),
            ]);
        }
    }

    /**
     * 查询支付宝交易。
     *
     * @param array<string, mixed> $order 标准插件查单参数
     * @return array<string, mixed>
     */
    public function query(array $order): array
    {
        $response = $this->callAlipay(fn () => $this->client()->query($this->tradeIdentity($order)));
        if (!$response->success()) {
            throw new PaymentException(
                $response->subMsg() !== '' ? $response->subMsg() : $response->msg(),
                40200,
                [
                    'channel_error_code' => $response->subCode() !== '' ? $response->subCode() : $response->code(),
                    'request_id' => $response->requestId(),
                ]
            );
        }

        $data = $response->data();
        $tradeStatus = strtoupper((string) ($data['trade_status'] ?? ''));
        $status = $this->tradeStatus($tradeStatus);
        $expectedPayNo = trim((string) ($order['pay_no'] ?? ''));
        $actualPayNo = trim((string) ($data['out_trade_no'] ?? ''));
        if ($expectedPayNo === '' || !hash_equals($expectedPayNo, $actualPayNo)) {
            throw new PaymentException('支付宝查单返回订单号不匹配', 40200, [
                'pay_no' => $expectedPayNo,
                'request_id' => $response->requestId(),
            ]);
        }
        if ($status === PaymentPluginStatusConstant::SUCCESS && !isset($data['total_amount'])) {
            throw new PaymentException('支付宝成功查单响应缺少 total_amount', 40200, [
                'pay_no' => $expectedPayNo,
                'request_id' => $response->requestId(),
            ]);
        }
        if (isset($data['total_amount'])) {
            $this->assertAmountMatches((string) $data['total_amount'], (int) ($order['amount'] ?? -1), '支付宝查单');
        }
        $tradeNo = trim((string) ($data['trade_no'] ?? ''));
        if ($status === PaymentPluginStatusConstant::SUCCESS && $tradeNo === '') {
            throw new PaymentException('支付宝成功查单响应缺少 trade_no', 40200, [
                'pay_no' => $expectedPayNo,
                'request_id' => $response->requestId(),
            ]);
        }
        $storedTradeNo = trim((string) ($order['chan_trade_no'] ?? ''));
        if ($storedTradeNo !== '' && $tradeNo !== '' && !hash_equals($storedTradeNo, $tradeNo)) {
            throw new PaymentException('支付宝查单返回交易号不匹配', 40200, [
                'pay_no' => $expectedPayNo,
                'request_id' => $response->requestId(),
            ]);
        }

        return [
            'status' => $status,
            'pay_no' => $expectedPayNo,
            'paid_amount' => $status === PaymentPluginStatusConstant::SUCCESS
                ? $this->amountToCents((string) $data['total_amount'])
                : null,
            'chan_order_no' => $actualPayNo,
            'chan_trade_no' => $tradeNo,
            'channel_status' => $tradeStatus,
            'message' => (string) ($data['msg'] ?? ''),
            'paid_at' => $status === PaymentPluginStatusConstant::SUCCESS ? ($data['send_pay_date'] ?? $data['gmt_payment'] ?? null) : null,
            'raw_data' => $response->toArray(),
        ];
    }

    /**
     * 关闭支付宝交易。
     *
     * @param array<string, mixed> $order 标准插件关单参数
     * @return array<string, mixed>
     */
    public function close(array $order): array
    {
        $response = $this->callAlipay(fn () => $this->client()->close($this->tradeIdentity($order)));
        if (!$response->success()) {
            $this->throwAlipayFailure($response, '支付宝关单失败');
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
     * 撤销支付宝不确定交易；该动作不用于正常退款。
     *
     * @param array<string, mixed> $order 标准插件订单参数
     * @return array<string, mixed>
     */
    public function cancel(array $order): array
    {
        $response = $this->callAlipay(fn () => $this->client()->cancel($this->tradeIdentity($order)));
        $data = $response->data();

        return [
            'success' => $response->success(),
            'msg' => $response->success() ? 'success' : ($response->subMsg() !== '' ? $response->subMsg() : $response->msg()),
            'action' => (string) ($data['action'] ?? ''),
            'retry_flag' => (string) ($data['retry_flag'] ?? ''),
            'channel_error_code' => $response->success() ? '' : ($response->subCode() !== '' ? $response->subCode() : $response->code()),
            'error_type' => $response->success() ? '' : $this->alipayErrorType($response),
            'raw_data' => $response->toArray(),
        ];
    }

    /**
     * 发起支付宝退款。
     *
     * 申请返回的 refund_fee 是累计金额，单次退款统一用原 out_request_no 查询核实。
     * 查询过早或无可信结果时保持未确认，不在请求内等待或重新申请退款。
     *
     * @param array<string, mixed> $order 标准插件退款参数
     * @return array<string, mixed>
     */
    public function refund(array $order): array
    {
        $payload = $this->tradeIdentity($order);
        $payload['refund_amount'] = FormatHelper::amount((int) $order['refund_amount']);

        $requestNo = (string) $order['refund_no'];
        $payload['out_request_no'] = $requestNo;

        $reason = trim((string) ($order['refund_reason'] ?? ''));
        if ($reason !== '') {
            $payload['refund_reason'] = mb_strcut($reason, 0, 256, 'UTF-8');
        }

        $response = $this->callAlipay(fn () => $this->client()->refund($payload));
        if (!$response->success()) {
            // 官方明确系统错误可能已退款，不能按普通业务拒绝释放退款责任。
            if ($response->subCode() === 'ACQ.SYSTEM_ERROR' || in_array($response->code(), ['20000', '20001'], true)) {
                throw new PaymentUncertainException('支付宝退款结果未确认：' . ($response->subMsg() !== '' ? $response->subMsg() : $response->msg()), 40200, [
                    'refund_no' => $requestNo,
                    'channel_error_code' => $response->subCode() ?: $response->code(),
                ]);
            }
            $this->throwAlipayFailure($response, '支付宝退款失败');
        }

        $data = $response->data();
        if ((string) ($data['out_trade_no'] ?? '') !== (string) $order['pay_no']
            || (string) ($data['trade_no'] ?? '') === ''
            || ((string) $order['chan_trade_no'] !== '' && (string) $data['trade_no'] !== (string) $order['chan_trade_no'])) {
            throw new PaymentUncertainException('支付宝退款申请返回原交易标识不匹配', 40200, ['refund_no' => $requestNo]);
        }

        return $this->queryRefund($order);
    }

    /**
     * 查询支付宝退款状态。
     *
     * 仅 REFUND_SUCCESS 及匹配的请求号、原交易和本次金额能确认成功。
     * 官方未定义其他明确终态；未返回状态可能是尚未收到请求，不推断退款失败。
     */
    public function queryRefund(array $refund): array
    {
        $refundNo = (string) $refund['refund_no'];

        $payload = $this->tradeIdentity($refund);
        $payload['out_request_no'] = $refundNo;
        $response = $this->callAlipay(fn () => $this->client()->refundQuery($payload));
        if (!$response->success()) {
            throw new PaymentUncertainException(
                $response->subMsg() !== '' ? $response->subMsg() : $response->msg(),
                40200,
                [
                    'refund_no' => $refundNo,
                    'channel_error_code' => $response->subCode() !== '' ? $response->subCode() : $response->code(),
                    'request_id' => $response->requestId(),
                ]
            );
        }

        $data = $response->data();
        $payNo = (string) $refund['pay_no'];
        $channelStatus = (string) ($data['refund_status'] ?? '');
        $success = $channelStatus === 'REFUND_SUCCESS';
        foreach (['out_trade_no' => $payNo, 'out_request_no' => $refundNo, 'trade_no' => (string) $refund['chan_trade_no']] as $field => $expected) {
            $actual = (string) ($data[$field] ?? '');
            if (($success && $expected !== '' && $actual === '')
                || ($actual !== '' && $expected !== '' && !hash_equals($expected, $actual))) {
                throw new PaymentUncertainException('支付宝退款查询返回交易或退款标识不匹配', 40200, [
                    'refund_no' => $refundNo, 'field' => $field,
                ]);
            }
        }

        $refundAmount = (int) $refund['refund_amount'];
        if ($success && !array_key_exists('refund_amount', $data)) {
            throw new PaymentUncertainException('支付宝退款成功查询缺少退款金额', 40200, [
                'refund_no' => $refundNo,
            ]);
        }
        if (array_key_exists('refund_amount', $data)) {
            $actualAmount = $this->amountToCents((string) $data['refund_amount']);
            if ($actualAmount !== $refundAmount) {
                throw new PaymentUncertainException('支付宝退款查询返回金额不匹配', 40200, [
                    'refund_no' => $refundNo,
                ]);
            }
        }
        if (array_key_exists('total_amount', $data)
            && $this->amountToCents((string) $data['total_amount']) !== (int) $refund['amount']) {
            throw new PaymentUncertainException('支付宝退款查询返回原订单金额不匹配', 40200, ['refund_no' => $refundNo]);
        }

        return [
            'status' => $success
                ? PaymentPluginStatusConstant::SUCCESS
                : PaymentPluginStatusConstant::UNKNOWN,
            'refund_no' => $refundNo,
            'pay_no' => $payNo,
            'refund_amount' => $refundAmount,
            'chan_refund_no' => (string) ($data['out_request_no'] ?? ''),
            'channel_status' => $channelStatus,
            'message' => $success ? '支付宝退款成功' : '支付宝退款状态尚未确认',
        ];
    }

    /**
     * 解析支付宝异步通知。
     *
     * @param Request $request 回调请求
     * @return array<string, mixed>
     */
    public function notify(Request $request): array
    {
        $payload = (array) $request->post();
        $parsed = $this->callAlipay(fn () => $this->client()->parseNotify($payload, true));

        $outTradeNo = (string) ($parsed['out_trade_no'] ?? '');
        if ($outTradeNo === '') {
            throw new PaymentException('支付宝异步通知缺少 out_trade_no', 40200);
        }

        $payOrder = $this->payOrderRepository->findByPayNo($outTradeNo);
        if (!$payOrder) {
            throw new PaymentException('支付宝异步通知对应支付单不存在', 40200, [
                'pay_no' => $outTradeNo,
                'channel_id' => (int) $this->getConfig('channel_id', 0),
            ]);
        }

        return $this->validatedNotifyResult($parsed, $payOrder);
    }

    /**
     * 对已验签通知执行支付单、通道、金额、商户、交易号和产品校验。
     *
     * @param array<string, mixed> $parsed SDK 已验签通知
     * @param PayOrder $payOrder 支付单
     * @return array<string, mixed>
     */
    private function validatedNotifyResult(array $parsed, PayOrder $payOrder): array
    {
        $outTradeNo = trim((string) ($parsed['out_trade_no'] ?? ''));
        $payNo = trim((string) $payOrder->pay_no);
        $context = $this->notifyDiagnosticContext($parsed, $payNo);
        if ($payNo === '' || !hash_equals($payNo, $outTradeNo)) {
            throw new PaymentException('支付宝异步通知订单号不匹配', 40200, $context);
        }
        $channelId = (int) $this->getConfig('channel_id', 0);
        if ($channelId <= 0 || (int) $payOrder->channel_id !== $channelId) {
            throw new PaymentException('支付宝异步通知支付单不属于当前通道', 40200, $context);
        }
        $sellerId = trim((string) ($parsed['seller_id'] ?? ''));
        if ($sellerId === '' || !hash_equals($this->configText('seller_id'), $sellerId)) {
            throw new PaymentException('支付宝异步通知 seller_id 不匹配', 40200, $context);
        }
        $this->assertAmountMatches(
            (string) ($parsed['total_amount'] ?? ''),
            (int) $payOrder->pay_amount,
            '支付宝异步通知',
            $context
        );
        $this->assertNotifyProduct($parsed, $payOrder);

        $tradeStatus = strtoupper((string) ($parsed['trade_status'] ?? ''));
        if (!in_array($tradeStatus, ['WAIT_BUYER_PAY', 'TRADE_SUCCESS', 'TRADE_FINISHED', 'TRADE_CLOSED'], true)) {
            throw new PaymentException('支付宝异步通知 trade_status 不受支持', 40200, $context);
        }
        $status = $this->notifyStatus($tradeStatus);
        $tradeNo = trim((string) ($parsed['trade_no'] ?? ''));
        if ($status === PaymentPluginStatusConstant::SUCCESS && $tradeNo === '') {
            throw new PaymentException('支付宝成功异步通知缺少 trade_no', 40200, $context);
        }
        $storedTradeNo = trim((string) ($payOrder->channel_trade_no ?? ''));
        if ($storedTradeNo !== '' && $tradeNo !== '' && !hash_equals($storedTradeNo, $tradeNo)) {
            throw new PaymentException('支付宝异步通知交易号不匹配', 40200, $context);
        }

        return [
            'status' => $status,
            'pay_no' => $payNo,
            'paid_amount' => $status === PaymentPluginStatusConstant::SUCCESS
                ? $this->amountToCents((string) $parsed['total_amount'])
                : null,
            'message' => $tradeStatus,
            'chan_order_no' => $outTradeNo,
            'chan_trade_no' => $tradeNo,
            'channel_status' => $tradeStatus,
            'paid_at' => $status === PaymentPluginStatusConstant::SUCCESS ? ($parsed['gmt_payment'] ?? null) : null,
        ];
    }

    /**
     * 校验支付宝元金额与支付单分金额完全一致。
     *
     * @param string $yuan 支付宝元金额
     * @param int $expectedCents 支付单分金额
     * @param string $scene 校验场景
     * @param array<string, scalar|null> $context 异常诊断上下文
     * @return void
     */
    private function assertAmountMatches(string $yuan, int $expectedCents, string $scene, array $context = []): void
    {
        try {
            $actualCents = $this->amountToCents($yuan);
        } catch (\InvalidArgumentException) {
            throw new PaymentException($scene . '金额格式无效', 40200, $context);
        }
        if ($expectedCents < 0 || $actualCents !== $expectedCents) {
            throw new PaymentException($scene . '金额不匹配', 40200, $context);
        }
    }

    /**
     * 将支付宝返回的非负十进制元金额精确转换为整数分。
     *
     * @param string $amount 支付宝元金额
     * @return int 分金额
     */
    private function amountToCents(string $amount): int
    {
        $amount = trim($amount);
        if (preg_match('/^(0|[1-9]\d*)(?:\.\d{1,2})?$/', $amount) !== 1) {
            throw new \InvalidArgumentException('支付宝金额必须是最多两位小数的非负十进制字符串');
        }

        $cents = bcmul($amount, '100', 0);
        if (bccomp($cents, (string) PHP_INT_MAX, 0) > 0) {
            throw new \InvalidArgumentException('支付宝金额超过系统整数范围');
        }

        return (int) $cents;
    }

    /**
     * 校验通知中的支付产品与支付单下单快照一致。
     *
     * @param array<string, mixed> $parsed 已验签通知参数
     * @param PayOrder $payOrder 支付单
     * @return void
     */
    private function assertNotifyProduct(array $parsed, PayOrder $payOrder): void
    {
        $extJson = (array) ($payOrder->ext_json ?? []);
        $context = (array) ($extJson['payment_context'] ?? []);
        $expected = trim((string) ($context['pay_product'] ?? ''));
        $encoded = trim((string) ($parsed['passback_params'] ?? ''));
        $decoded = $encoded !== '' ? json_decode(rawurldecode($encoded), true) : null;
        $actual = is_array($decoded) ? trim((string) ($decoded['mpay_pay_product'] ?? '')) : '';
        if ($expected === '' || $actual === '' || !hash_equals($expected, $actual)) {
            throw new PaymentException(
                '支付宝异步通知支付产品不匹配',
                40200,
                $this->notifyDiagnosticContext($parsed, (string) $payOrder->pay_no)
            );
        }
    }

    /**
     * 构造不含敏感通知字段的诊断上下文。
     *
     * @param array<string, mixed> $parsed 已验签通知参数
     * @param string $payNo MPAY 支付单号
     * @return array<string, int|string> 诊断上下文
     */
    private function notifyDiagnosticContext(array $parsed, string $payNo): array
    {
        return [
            'pay_no' => $payNo,
            'channel_id' => (int) $this->getConfig('channel_id', 0),
            'alipay_request_id' => trim((string) ($parsed['notify_id'] ?? '')),
        ];
    }

    /**
     * 返回支付宝通知成功应答。
     *
     * @return string|Response 通知应答
     */
    public function notifySuccess(): string|Response
    {
        return 'success';
    }

    /**
     * 返回支付宝通知失败应答。
     *
     * @return string|Response 通知应答
     */
    public function notifyFail(): string|Response
    {
        return 'fail';
    }
}
