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
use app\common\sdk\alipay\AlipayClient;
use app\common\sdk\alipay\AlipayResponse;
use app\common\sdk\alipay\AlipaySdkException;
use app\common\util\FormatHelper;
use app\exception\PaymentException;
use support\Request;
use support\Response;

/**
 * 支付宝官方 API 支付插件。
 *
 * 该插件用于 MPAY 通过支付宝 OpenAPI 发起官方支付产品请求，底层调用
 * app/common/sdk/alipay 目录下的轻量 SDK。
 */
class AlipayApiPayment extends BasePayment implements PaymentInterface, PayPluginInterface, PaymentIdentityRequirementInterface
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
     * 插件元信息和后台配置表单。
     *
     * @var array<string, mixed>
     */
    protected array $paymentInfo = [
        'code' => 'alipay_api',
        'name' => '支付宝官方API支付',
        'plugin_type' => PaymentPluginTypeConstant::TYPE_DIRECT,
        'author' => 'MPAY',
        'version' => '1.0.0',
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
                'props' => ['placeholder' => '选填；需要指定收款账号时填写'],
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
            'auth_type' => 'alipay_mini',
            'identity_field' => 'buyer_id',
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
                throw new PaymentException($e->getMessage(), 40200);
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
     * 解析本次下单可尝试的支付宝产品。
     *
     * 外部 API 不直接指定支付宝产品，插件只根据当前支付环境生成候选产品，并从通道已勾选
     * 的产品中保留可承接项。实际支付时会按顺序尝试，产品权限类错误才进入下一个兜底产品。
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

        throw new PaymentException('当前支付宝通道没有开通适合该支付环境的产品', 40200, [
            'env' => $this->paymentEnv($order),
            'candidate_products' => $candidates,
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
        $enabledProducts = $this->enabledProducts();
        foreach ($this->identityProductCandidates($order) as $product) {
            if (in_array($product, $enabledProducts, true)) {
                return $product;
            }
        }

        return '';
    }

    /**
     * 根据环境生成支付宝身份流程候选产品。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<int, string> 产品标识列表
     */
    private function identityProductCandidates(array $order): array
    {
        $env = $this->paymentEnv($order);
        if ($env === EpayProtocolConstant::DEVICE_ALIPAY) {
            return [self::PRODUCT_MINI, self::PRODUCT_H5, self::PRODUCT_WEB, self::PRODUCT_SCAN];
        }

        if (in_array($env, [
            EpayProtocolConstant::DEVICE_MOBILE,
            EpayProtocolConstant::DEVICE_QQ,
            EpayProtocolConstant::DEVICE_WECHAT,
            EpayProtocolConstant::DEVICE_JUMP,
        ], true)) {
            return [self::PRODUCT_H5, self::PRODUCT_MINI, self::PRODUCT_WEB, self::PRODUCT_SCAN];
        }

        return $this->productCandidates($order);
    }

    /**
     * 根据支付环境生成支付宝产品候选列表。
     *
     * 排在前面的产品更符合当前环境，后面的产品作为兜底承接方案。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<int, string>
     */
    private function productCandidates(array $order): array
    {
        $payment = $this->paymentPayload($order);
        if (trim((string) ($payment['auth_code'] ?? '')) !== '') {
            return [self::PRODUCT_POS, self::PRODUCT_SCAN, self::PRODUCT_WEB, self::PRODUCT_H5];
        }

        $env = $this->paymentEnv($order);
        $candidates = match ($env) {
            EpayProtocolConstant::DEVICE_MOBILE,
            EpayProtocolConstant::DEVICE_QQ,
            EpayProtocolConstant::DEVICE_WECHAT,
            EpayProtocolConstant::DEVICE_ALIPAY => [self::PRODUCT_H5, self::PRODUCT_MINI, self::PRODUCT_WEB, self::PRODUCT_SCAN],
            EpayProtocolConstant::DEVICE_JUMP => [self::PRODUCT_H5, self::PRODUCT_MINI, self::PRODUCT_WEB, self::PRODUCT_SCAN],
            default => [self::PRODUCT_WEB, self::PRODUCT_SCAN, self::PRODUCT_H5],
        };

        if ($env === EpayProtocolConstant::DEVICE_ALIPAY && $this->hasMiniPayload($payment)) {
            array_unshift($candidates, self::PRODUCT_MINI);
        }

        return array_values(array_unique($candidates));
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
        $opAppId = $this->firstText($payment['sub_appid'] ?? '', $payment['op_app_id'] ?? '', $this->configText('mini_app_id'));
        $buyer = $this->firstText($payment['sub_openid'] ?? '', $payment['buyer_open_id'] ?? '', $payment['buyer_id'] ?? '');

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
     * @return array<string, mixed>
     */
    private function baseBizContent(array $order): array
    {
        $biz = [
            'out_trade_no' => (string) $order['pay_no'],
            'total_amount' => FormatHelper::amount((int) $order['amount']),
            'subject' => mb_strcut((string) $order['subject'], 0, 256, 'UTF-8'),
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

        $extra = (array) ($order['extra'] ?? []);
        $merchant = (array) ($extra['merchant'] ?? []);
        $param = trim((string) ($merchant['param'] ?? ''));
        if ($param !== '') {
            $biz['passback_params'] = rawurlencode($param);
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
        $products = $this->resolveProducts($order);
        $attempts = [];

        foreach ($products as $index => $product) {
            try {
                return $this->payByProduct($product, $order);
            } catch (PaymentException $e) {
                $attempts[] = $this->productAttempt($product, $e);
                $isLast = $index === count($products) - 1;
                if ($isLast || !$this->shouldFallbackProduct($e)) {
                    throw $this->withProductAttempts($e, $attempts);
                }
            }
        }

        throw new PaymentException('当前支付宝通道没有可用支付产品', 40200, [
            'env' => $this->paymentEnv($order),
            'enabled_products' => $this->enabledProducts(),
        ]);
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
     * 判断当前支付宝错误是否允许继续尝试下一个产品。
     *
     * 只对产品未签约、接口权限不足这类“当前产品不可用”的错误做兜底；
     * 付款码错误、金额错误、签名错误等真实业务错误必须直接暴露。
     *
     * @param PaymentException $e 支付异常
     * @return bool 是否可以继续兜底
     */
    private function shouldFallbackProduct(PaymentException $e): bool
    {
        $data = $this->exceptionData($e);
        $errorCode = strtoupper((string) ($data['channel_error_code'] ?? ''));
        $message = strtoupper($e->getMessage());

        if (in_array($errorCode, [
            'ACQ.ACCESS_FORBIDDEN',
            'ACCESS_FORBIDDEN',
            'ISV.INSUFFICIENT-ISV-PERMISSIONS',
            'INSUFFICIENT-ISV-PERMISSIONS',
        ], true)) {
            return true;
        }

        foreach (['ACCESS_FORBIDDEN', 'INSUFFICIENT-ISV-PERMISSIONS', '权限不足', '无权限', '未签约'] as $keyword) {
            if (str_contains($message, strtoupper($keyword))) {
                return true;
            }
        }

        return false;
    }

    /**
     * 记录单次产品尝试结果。
     *
     * @param string $product 产品标识
     * @param PaymentException $e 支付异常
     * @return array<string, mixed>
     */
    private function productAttempt(string $product, PaymentException $e): array
    {
        $data = $this->exceptionData($e);

        return [
            'product' => $product,
            'message' => $e->getMessage(),
            'channel_error_code' => (string) ($data['channel_error_code'] ?? ''),
        ];
    }

    /**
     * 在最终异常中附加已尝试产品，方便后台定位兜底链路。
     *
     * @param PaymentException $e 原始异常
     * @param array<int, array<string, mixed>> $attempts 已尝试产品
     * @return PaymentException 附带尝试记录的异常
     */
    private function withProductAttempts(PaymentException $e, array $attempts): PaymentException
    {
        $data = $this->exceptionData($e);
        $data['product_attempts'] = $attempts;

        return new PaymentException($e->getMessage(), (int) $e->getCode() ?: 40200, $data);
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

        $biz = $this->baseBizContent($order);
        $biz['auth_code'] = $authCode;

        $response = $this->callAlipay(fn () => $this->client()->faceToFacePay($biz, $this->requestOptions($order)));
        if (!$response->success() && $response->code() !== '10003') {
            $this->throwAlipayFailure($response, '支付宝刷卡支付失败');
        }

        $data = $response->data();

        return [
            'pay_page' => $response->success() ? 'ok' : 'page',
            'pay_type' => 'alipay',
            'pay_product' => self::PRODUCT_POS,
            'pay_action' => 'scan',
            'pay_params' => [
                '_page' => 'page',
                'params' => $response->success() ? '支付成功' : '等待用户确认支付',
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
            $this->baseBizContent($order),
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
        $biz = $this->baseBizContent($order);
        $biz['op_app_id'] = $this->firstText($payment['sub_appid'] ?? '', $payment['op_app_id'] ?? '', $this->configText('mini_app_id'));

        $buyerOpenId = $this->firstText($payment['sub_openid'] ?? '', $payment['buyer_open_id'] ?? '');
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
        $result = $this->callAlipay(fn () => $this->client()->appPay($this->baseBizContent($order), $this->requestOptions($order)));
        $orderString = (string) ($result['order_string'] ?? '');
        if ($orderString === '') {
            throw new PaymentException('支付宝 APP 支付未生成 order_string', 40200, [
                'result' => $result,
            ]);
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
                'raw' => $result,
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
        $biz = $this->baseBizContent($order);
        $quitUrl = trim((string) ($order['return_url'] ?? ''));
        if ($quitUrl !== '') {
            $biz['quit_url'] = $quitUrl;
        }

        $result = $this->callAlipay(fn () => $this->client()->wapPay($biz, [
            ...$this->requestOptions($order),
            'http_method' => 'GET',
        ]));
        $url = (string) ($result['url'] ?? '');
        if ($url === '') {
            throw new PaymentException('支付宝 H5 支付未生成跳转地址', 40200, [
                'result' => $result,
            ]);
        }

        return [
            'pay_page' => 'jump',
            'pay_type' => 'alipay',
            'pay_product' => self::PRODUCT_H5,
            'pay_action' => 'jump',
            'pay_params' => [
                'url' => $url,
                'html' => (string) ($result['html'] ?? ''),
                'description' => '正在跳转支付宝 H5 支付。',
                'raw' => $result,
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
        $biz = $this->baseBizContent($order);

        $result = $this->callAlipay(fn () => $this->client()->pagePay($biz, [
            ...$this->requestOptions($order),
            'http_method' => 'GET',
        ]));
        $url = (string) ($result['url'] ?? '');
        if ($url === '') {
            throw new PaymentException('支付宝网页支付未生成跳转地址', 40200, [
                'result' => $result,
            ]);
        }

        return [
            'pay_page' => 'jump',
            'pay_type' => 'alipay',
            'pay_product' => self::PRODUCT_WEB,
            'pay_action' => 'jump',
            'pay_params' => [
                'url' => $url,
                'html' => (string) ($result['html'] ?? ''),
                'description' => '正在跳转支付宝网页支付。',
                'raw' => $result,
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
        throw new PaymentException(
            $response->subMsg() !== '' ? $response->subMsg() : ($response->msg() !== '' ? $response->msg() : $message),
            40200,
            [
                'channel_error_code' => $response->subCode() !== '' ? $response->subCode() : $response->code(),
                'response' => $response->toArray(),
            ]
        );
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
        $tradeNo = $this->firstText(
            $order['trade_no'] ?? '',
            $order['chan_trade_no'] ?? '',
            $order['channel_trade_no'] ?? ''
        );
        if ($tradeNo !== '') {
            return ['trade_no' => $tradeNo];
        }

        $outTradeNo = $this->firstText(
            $order['out_trade_no'] ?? '',
            $order['chan_order_no'] ?? '',
            $order['channel_order_no'] ?? '',
            $order['pay_no'] ?? ''
        );
        if ($outTradeNo === '') {
            throw new PaymentException('支付宝交易标识不能为空', 40200);
        }

        return ['out_trade_no' => $outTradeNo];
    }

    /**
     * 主动查单状态映射。
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
            throw new PaymentException($e->getMessage(), 40200);
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
            return [
                'success' => false,
                'msg' => $response->subMsg() !== '' ? $response->subMsg() : $response->msg(),
                'raw_data' => $response->toArray(),
            ];
        }

        $data = $response->data();
        $tradeStatus = strtoupper((string) ($data['trade_status'] ?? ''));
        $status = $this->tradeStatus($tradeStatus);

        return [
            'success' => true,
            'status' => $status,
            'channel_order_no' => (string) ($data['out_trade_no'] ?? $order['pay_no'] ?? ''),
            'channel_trade_no' => (string) ($data['trade_no'] ?? ''),
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

        return [
            'success' => $response->success(),
            'msg' => $response->success() ? 'success' : ($response->subMsg() !== '' ? $response->subMsg() : $response->msg()),
            'raw_data' => $response->toArray(),
        ];
    }

    /**
     * 发起支付宝退款。
     *
     * @param array<string, mixed> $order 标准插件退款参数
     * @return array<string, mixed>
     */
    public function refund(array $order): array
    {
        $payload = $this->tradeIdentity($order);
        $payload['refund_amount'] = FormatHelper::amount((int) $order['refund_amount']);

        $requestNo = $this->firstText(
            $order['out_refund_no'] ?? '',
            $order['refund_no'] ?? '',
            $order['channel_request_no'] ?? ''
        );
        if ($requestNo !== '') {
            $payload['out_request_no'] = $requestNo;
        }

        $reason = trim((string) ($order['refund_reason'] ?? ''));
        if ($reason !== '') {
            $payload['refund_reason'] = mb_strcut($reason, 0, 256, 'UTF-8');
        }

        $response = $this->callAlipay(fn () => $this->client()->refund($payload));

        return [
            'success' => $response->success(),
            'msg' => $response->success() ? 'success' : ($response->subMsg() !== '' ? $response->subMsg() : $response->msg()),
            'chan_refund_no' => (string) ($response->data()['trade_no'] ?? ''),
            'out_request_no' => $requestNo,
            'raw_data' => $response->toArray(),
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
        $payload = (array) $request->all();
        $parsed = $this->callAlipay(fn () => $this->client()->parseNotify($payload, true));

        $outTradeNo = (string) ($parsed['out_trade_no'] ?? '');
        $tradeNo = (string) ($parsed['trade_no'] ?? '');
        if ($outTradeNo === '') {
            throw new PaymentException('支付宝异步通知缺少 out_trade_no', 40200);
        }

        $tradeStatus = strtoupper((string) ($parsed['trade_status'] ?? ''));
        $status = $this->notifyStatus($tradeStatus);

        return [
            'status' => $status,
            'message' => $tradeStatus,
            'channel_order_no' => $outTradeNo,
            'channel_trade_no' => $tradeNo !== '' ? $tradeNo : $outTradeNo,
            'channel_status' => $tradeStatus,
            'paid_at' => $status === PaymentPluginStatusConstant::SUCCESS ? ($parsed['gmt_payment'] ?? null) : null,
        ];
    }

    /**
     * 返回支付宝通知成功应答。
     */
    public function notifySuccess(): string|Response
    {
        return 'success';
    }

    /**
     * 返回支付宝通知失败应答。
     */
    public function notifyFail(): string|Response
    {
        return 'fail';
    }
}
