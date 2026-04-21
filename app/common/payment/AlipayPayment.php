<?php

declare(strict_types=1);

namespace app\common\payment;

use app\common\base\BasePayment;
use app\common\constant\FileConstant;
use app\common\interface\PaymentInterface;
use app\common\interface\PayPluginInterface;
use app\common\util\FormatHelper;
use app\exception\PaymentException;
use Psr\Http\Message\ResponseInterface;
use support\Request;
use support\Response;
use Yansongda\Pay\Pay;
use Yansongda\Supports\Collection;

/**
 * 支付宝支付插件。
 *
 * 基于 `yansongda/pay` 封装支付宝直连能力，支持网页、H5、APP、小程序、刷卡、扫码和转账。
 *
 * 通道配置：`app_id`、`app_secret_cert`、`app_public_cert_path`、`alipay_public_cert_path`、
 * `alipay_root_cert_path`、`mode`（0 正式 / 1 沙箱）。
 *
 * 证书字段通过上传选择器保存 `object_key`，初始化时会自动解析成本地可读路径。
 */
class AlipayPayment extends BasePayment implements PaymentInterface, PayPluginInterface
{
    private const PRODUCT_WEB      = 'alipay_web';
    private const PRODUCT_H5       = 'alipay_h5';
    private const PRODUCT_APP      = 'alipay_app';
    private const PRODUCT_MINI     = 'alipay_mini';
    private const PRODUCT_POS      = 'alipay_pos';
    private const PRODUCT_SCAN     = 'alipay_scan';
    private const PRODUCT_TRANSFER = 'alipay_transfer';

    private const DEFAULT_ENABLED_PRODUCTS = [
        self::PRODUCT_H5,
    ];

    private const PRODUCT_ACTION_MAP = [
        self::PRODUCT_WEB => 'web',
        self::PRODUCT_H5 => 'h5',
        self::PRODUCT_APP => 'app',
        self::PRODUCT_MINI => 'mini',
        self::PRODUCT_POS => 'pos',
        self::PRODUCT_SCAN => 'scan',
        self::PRODUCT_TRANSFER => 'transfer',
    ];

    private const ACTION_PRODUCT_MAP = [
        'web' => self::PRODUCT_WEB,
        'h5' => self::PRODUCT_H5,
        'app' => self::PRODUCT_APP,
        'mini' => self::PRODUCT_MINI,
        'pos' => self::PRODUCT_POS,
        'scan' => self::PRODUCT_SCAN,
        'transfer' => self::PRODUCT_TRANSFER,
    ];

    /**
     * 插件元信息。
     *
     * @var array<string, mixed>
     */
    protected array $paymentInfo = [
        'code'           => 'alipay',
        'name'           => '支付宝直连',
        'author'         => '技术老胡',
        'link'           => 'https://www.baidu.com',
        'version'        => '1.0.0',
        'pay_types'      => ['alipay', 'alipay_app'],
        'transfer_types' => ['alipay', 'alipay_app'],
        'config_schema'  => [
            ["type" => "input", "field" => "app_id", "title" => "应用ID", "value" => "", "props" => ["placeholder" => "请输入应用ID"], "validate" => [["required" => true, "message" => "应用ID不能为空"]]],
            ["type" => "textarea", "field" => "app_secret_cert", "title" => "应用私钥", "value" => "", "props" => ["placeholder" => "请输入应用私钥", "rows" => 4], "validate" => [["required" => true, "message" => "应用私钥不能为空"]]],
            [
                "type" => "upload",
                "field" => "app_public_cert_path",
                "title" => "应用公钥证书",
                "value" => "",
                "props" => [
                    "fileUpload" => [
                        "selectorType" => "file",
                        "scene" => FileConstant::SCENE_CERTIFICATE,
                        "isLocal" => true,
                        "isPublic" => false,
                        "getKey" => "object_key",
                    ],
                ],
                "validate" => [["required" => true, "message" => "应用公钥证书不能为空"]],
            ],
            [
                "type" => "upload",
                "field" => "alipay_public_cert_path",
                "title" => "支付宝公钥证书",
                "value" => "",
                "props" => [
                    "fileUpload" => [
                        "selectorType" => "file",
                        "scene" => FileConstant::SCENE_CERTIFICATE,
                        "isLocal" => true,
                        "isPublic" => false,
                        "getKey" => "object_key",
                    ],
                ],
                "validate" => [["required" => true, "message" => "支付宝公钥证书不能为空"]],
            ],
            [
                "type" => "upload",
                "field" => "alipay_root_cert_path",
                "title" => "支付宝根证书",
                "value" => "",
                "props" => [
                    "fileUpload" => [
                        "selectorType" => "file",
                        "scene" => FileConstant::SCENE_CERTIFICATE,
                        "isLocal" => true,
                        "isPublic" => false,
                        "getKey" => "object_key",
                    ],
                ],
                "validate" => [["required" => true, "message" => "支付宝根证书不能为空"]],
            ],
            [
                "type" => "checkbox",
                "field" => "enabled_products",
                "title" => "已开通产品",
                "value" => self::DEFAULT_ENABLED_PRODUCTS,
                "options" => [
                    ["value" => self::PRODUCT_WEB, "label" => "web - 网页支付"],
                    ["value" => self::PRODUCT_H5, "label" => "h5 - H5 支付"],
                    ["value" => self::PRODUCT_APP, "label" => "app - APP 支付"],
                    ["value" => self::PRODUCT_MINI, "label" => "mini - 小程序支付"],
                    ["value" => self::PRODUCT_POS, "label" => "pos - 刷卡支付"],
                    ["value" => self::PRODUCT_SCAN, "label" => "scan - 扫码支付"],
                    ["value" => self::PRODUCT_TRANSFER, "label" => "transfer - 账户转账"],
                ],
                "validate" => [["required" => true, "message" => "请至少选择一个已开通产品"]],
            ],
            ["type" => "select", "field" => "mode", "title" => "环境", "value" => "0", "props" => ["placeholder" => "请选择环境"], "options" => [["value" => "0", "label" => "正式"], ["value" => "1", "label" => "沙箱"]]],
        ],
    ];

    /**
     * 初始化支付宝插件。
     *
     * @param array $channelConfig 渠道配置
     * @return void
     */
    public function init(array $channelConfig): void
    {
        parent::init($channelConfig);
        $config = [
            'alipay' => [
                'default' => [
                    'app_id'                  => $this->getConfig('app_id', ''),
                    'app_secret_cert'         => $this->getConfig('app_secret_cert', ''),
                    'app_public_cert_path'    => runtime_path((string) $this->getConfig('app_public_cert_path', '')),
                    'alipay_public_cert_path' => runtime_path((string) $this->getConfig('alipay_public_cert_path', '')),
                    'alipay_root_cert_path'   => runtime_path((string) $this->getConfig('alipay_root_cert_path', '')),
                    'notify_url'              => $this->getConfig('notify_url', ''),
                    'return_url'              => $this->getConfig('return_url', ''),
                    'mode'                    => (int)($this->getConfig('mode', Pay::MODE_NORMAL)),
                ],
            ],
        ];
        Pay::config(array_merge($config, ['_force' => true]));
    }

    /**
     * 根据订单上下文选择支付宝产品。
     *
     * @param array $order 订单上下文
     * @param bool $validateEnabled 是否校验已开通产品
     * @return string 产品编码
     * @throws PaymentException
     */
    private function chooseProduct(array $order, bool $validateEnabled = true): string
    {
        $enabled = $this->normalizeEnabledProducts($this->channelConfig['enabled_products'] ?? self::DEFAULT_ENABLED_PRODUCTS);
        $explicit = $this->resolveExplicitProduct($order);
        if ($explicit !== null) {
            if ($validateEnabled && !in_array($explicit, $enabled, true)) {
                throw new PaymentException('支付宝产品未开通：' . $this->productAction($explicit), 402);
            }

            return $explicit;
        }

        $env = strtolower((string) ($order['_env'] ?? $order['device'] ?? 'pc'));
        $map = [
            'pc' => self::PRODUCT_WEB,
            'web' => self::PRODUCT_WEB,
            'desktop' => self::PRODUCT_WEB,
            'mobile' => self::PRODUCT_H5,
            'h5' => self::PRODUCT_H5,
            'wechat' => self::PRODUCT_H5,
            'qq' => self::PRODUCT_H5,
            'alipay' => self::PRODUCT_APP,
            'app' => self::PRODUCT_APP,
            'mini' => self::PRODUCT_MINI,
            'pos' => self::PRODUCT_POS,
            'scan' => self::PRODUCT_SCAN,
            'transfer' => self::PRODUCT_TRANSFER,
        ];
        $prefer = $map[$env] ?? self::PRODUCT_WEB;

        $payTypeCode = strtolower((string) ($order['pay_type_code'] ?? $order['type_code'] ?? ''));
        if ($payTypeCode === 'alipay_app') {
            $prefer = self::PRODUCT_APP;
        }

        if (!$validateEnabled) {
            return $prefer;
        }

        return in_array($prefer, $enabled, true) ? $prefer : ($enabled[0] ?? self::PRODUCT_WEB);
    }

    /**
     * 标准化已开通产品列表。
     *
     * @param array|string|null $products 已开通产品配置
     * @return array 标准化后的产品编码列表
     */
    private function normalizeEnabledProducts(mixed $products): array
    {
        if (is_string($products)) {
            $decoded = json_decode($products, true);
            $products = is_array($decoded) ? $decoded : [$products];
        }

        if (!is_array($products)) {
            return self::DEFAULT_ENABLED_PRODUCTS;
        }

        $normalized = [];
        foreach ($products as $product) {
            $value = strtolower(trim((string) $product));
            if ($value !== '') {
                $normalized[] = $value;
            }
        }

        $normalized = array_values(array_unique($normalized));

        return $normalized !== [] ? $normalized : self::DEFAULT_ENABLED_PRODUCTS;
    }

    /**
     * 解析显式指定的产品。
     *
     * @param array $order 订单上下文
     * @return string|null 产品编码
     */
    private function resolveExplicitProduct(array $order): ?string
    {
        $context = $this->collectOrderContext($order);
        $candidates = [
            $context['pay_product'] ?? null,
            $context['product'] ?? null,
            $context['alipay_product'] ?? null,
            $context['pay_action'] ?? null,
            $context['action'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            $product = $this->normalizeProductCode($candidate);
            if ($product !== null) {
                return $product;
            }
        }

        return null;
    }

    /**
     * 归一化产品编码。
     *
     * @param mixed $value 原始产品标识
     * @return string|null 标准化后的产品编码
     */
    private function normalizeProductCode(mixed $value): ?string
    {
        $value = strtolower(trim((string) $value));
        if ($value === '') {
            return null;
        }

        if (isset(self::ACTION_PRODUCT_MAP[$value])) {
            return self::ACTION_PRODUCT_MAP[$value];
        }

        if (isset(self::PRODUCT_ACTION_MAP[$value])) {
            return $value;
        }

        return null;
    }

    /**
     * 获取产品对应的动作名。
     *
     * @param string $product 产品编码
     * @return string 动作名
     */
    private function productAction(string $product): string
    {
        return self::PRODUCT_ACTION_MAP[$product] ?? $product;
    }

    /**
     * 合并订单上下文。
     *
     * @param array $order 订单上下文
     * @return array 合并后的上下文
     */
    private function collectOrderContext(array $order): array
    {
        $context = $order;
        $extra = isset($order['extra']) && is_array($order['extra']) ? $order['extra'] : [];
        if ($extra !== []) {
            $context = array_merge($context, $extra);
        }

        $param = $this->normalizeParamBag($context['param'] ?? null);
        if ($param !== []) {
            $context = array_merge($context, $param);
        }

        return $context;
    }

    /**
     * 标准化参数包。
     *
     * @param array|string|null $param 原始参数包
     * @return array<string, mixed> 参数数组
     */
    private function normalizeParamBag(mixed $param): array
    {
        if (is_array($param)) {
            return $param;
        }

        if (is_string($param) && $param !== '') {
            $decoded = json_decode($param, true);
            if (is_array($decoded)) {
                return $decoded;
            }

            parse_str($param, $parsed);
            if (is_array($parsed) && $parsed !== []) {
                return $parsed;
            }
        }

        return [];
    }

    /**
     * 构建基础下单参数。
     *
     * @param array $params 原始参数
     * @return array 基础下单参数
     */
    private function buildBasePayParams(array $params): array
    {
        $base = [
            'out_trade_no' => (string) ($params['out_trade_no'] ?? ''),
            'total_amount' => FormatHelper::amount((int) ($params['amount'] ?? 0)),
            'subject' => (string) ($params['subject'] ?? ''),
        ];

        $body = (string) ($params['body'] ?? '');
        if ($body !== '') {
            $base['body'] = $body;
        }

        $returnUrl = (string) ($params['_return_url'] ?? '');
        if ($returnUrl !== '') {
            $base['_return_url'] = $returnUrl;
        }

        $notifyUrl = (string) ($params['_notify_url'] ?? '');
        if ($notifyUrl !== '') {
            $base['_notify_url'] = $notifyUrl;
        }

        return $base;
    }

    /**
     * 从集合中提取首个非空值。
     *
     * @param Collection $result 结果集合
     * @param array $keys 候选键
     * @param mixed $default 默认值
     * @return mixed 提取到的首个非空值
     */
    private function extractCollectionValue(Collection $result, array $keys, mixed $default = ''): mixed
    {
        foreach ($keys as $key) {
            $value = $result->get($key);
            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return $default;
    }

    /**
     * 发起支付宝下单。
     *
     * @param array $order 订单上下文
     * @return array 下单结果
     * @throws PaymentException
     */
    public function pay(array $order): array
    {
        $orderId   = (string) ($order['order_id'] ?? $order['pay_no'] ?? '');
        $amount    = (int) ($order['amount'] ?? 0);
        $subject   = (string)($order['subject'] ?? '');
        $body      = (string)($order['body'] ?? '');
        $extra     = $order['extra'] ?? [];
        $returnUrl = (string) ($order['return_url'] ?? $extra['return_url'] ?? $this->getConfig('return_url', ''));
        $notifyUrl = (string) ($order['callback_url'] ?? $this->getConfig('notify_url', ''));

        if ($orderId === '' || $amount <= 0 || $subject === '') {
            throw new PaymentException('支付宝下单参数不完整', 402);
        }

        $params = $this->buildBasePayParams([
            'out_trade_no' => $orderId,
            'amount' => $amount,
            'subject' => $subject,
            'body' => $body,
            '_return_url' => $returnUrl,
            '_notify_url' => $notifyUrl,
        ]);

        $product = $this->chooseProduct($order);

        try {
            return match ($product) {
                self::PRODUCT_WEB      => $this->doWeb($params),
                self::PRODUCT_H5       => $this->doH5($params),
                self::PRODUCT_SCAN     => $this->doScan($params),
                self::PRODUCT_APP      => $this->doApp($params),
                self::PRODUCT_MINI     => $this->doMini($params, $order),
                self::PRODUCT_POS      => $this->doPos($params, $order),
                self::PRODUCT_TRANSFER => $this->doTransfer($params, $order),
                default            => throw new PaymentException('不支持的支付宝产品：' . $product, 402),
            };
        } catch (PaymentException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new PaymentException('支付宝下单失败：' . $e->getMessage(), 402, ['order_id' => $orderId]);
        }
    }

    /**
     * 发起网页支付。
     *
     * @param array $params 基础参数
     * @return array 下单结果
     */
    private function doWeb(array $params): array
    {
        $response = Pay::alipay()->web($params);
        $body     = $response instanceof ResponseInterface ? (string)$response->getBody() : '';
        return [
            'pay_product' => self::PRODUCT_WEB,
            'pay_action' => $this->productAction(self::PRODUCT_WEB),
            'pay_params'    => [
                'type' => 'form',
                'method' => 'POST',
                'action' => '',
                'html' => $body,
                'pay_product' => self::PRODUCT_WEB,
                'pay_action' => $this->productAction(self::PRODUCT_WEB),
            ],
            'chan_order_no' => $params['out_trade_no'],
            'chan_trade_no' => '',
        ];
    }

    /**
     * 发起 H5 支付。
     *
     * @param array $params 基础参数
     * @return array 下单结果
     */
    private function doH5(array $params): array
    {
        $returnUrl = $params['_return_url'] ?? $this->getConfig('return_url', '');
        if ($returnUrl !== '') {
            $params['quit_url'] = $returnUrl;
        }
        $response = Pay::alipay()->h5($params);
        $body     = $response instanceof ResponseInterface ? (string)$response->getBody() : '';
        return [
            'pay_product' => self::PRODUCT_H5,
            'pay_action' => $this->productAction(self::PRODUCT_H5),
            'pay_params'    => [
                'type' => 'form',
                'method' => 'POST',
                'action' => '',
                'html' => $body,
                'pay_product' => self::PRODUCT_H5,
                'pay_action' => $this->productAction(self::PRODUCT_H5),
            ],
            'chan_order_no' => $params['out_trade_no'],
            'chan_trade_no' => '',
        ];
    }

    /**
     * 发起扫码支付。
     *
     * @param array $params 基础参数
     * @return array 下单结果
     */
    private function doScan(array $params): array
    {
        /** @var Collection $result */
        $result = Pay::alipay()->scan($params);
        $qrCode = $result->get('qr_code', '');
        return [
            'pay_product' => self::PRODUCT_SCAN,
            'pay_action' => $this->productAction(self::PRODUCT_SCAN),
            'pay_params'    => [
                'type' => 'qrcode',
                'qrcode_url' => $qrCode,
                'qrcode_data' => $qrCode,
                'pay_product' => self::PRODUCT_SCAN,
                'pay_action' => $this->productAction(self::PRODUCT_SCAN),
            ],
            'chan_order_no' => $params['out_trade_no'],
            'chan_trade_no' => $result->get('trade_no', ''),
        ];
    }

    /**
     * 发起 APP 支付。
     *
     * @param array $params 基础参数
     * @return array 下单结果
     */
    private function doApp(array $params): array
    {
        /** @var Collection $result */
        $result    = Pay::alipay()->app($params);
        $orderStr  = $result->get('order_string', '');
        return [
            'pay_product' => self::PRODUCT_APP,
            'pay_action' => $this->productAction(self::PRODUCT_APP),
            'pay_params'    => [
                'type' => 'jsapi',
                'order_str' => $orderStr,
                'urlscheme' => $orderStr,
                'pay_product' => self::PRODUCT_APP,
                'pay_action' => $this->productAction(self::PRODUCT_APP),
            ],
            'chan_order_no' => $params['out_trade_no'],
            'chan_trade_no' => $result->get('trade_no', ''),
        ];
    }

    /**
     * 发起小程序支付。
     *
     * @param array $params 基础参数
     * @param array $order 订单上下文
     * @return array 下单结果
     * @throws PaymentException
     */
    private function doMini(array $params, array $order): array
    {
        $context = $this->collectOrderContext($order);
        $buyerId = trim((string) ($context['buyer_id'] ?? ''));
        if ($buyerId === '') {
            throw new PaymentException('支付宝小程序支付缺少 buyer_id', 402);
        }

        $miniParams = array_merge($params, [
            'buyer_id' => $buyerId,
        ]);

        /** @var Collection $result */
        $result = Pay::alipay()->mini($miniParams);
        $tradeNo = (string) $this->extractCollectionValue($result, ['trade_no', 'order_id', 'out_trade_no'], '');

        return [
            'pay_product' => self::PRODUCT_MINI,
            'pay_action' => $this->productAction(self::PRODUCT_MINI),
            'pay_params' => [
                'type' => 'mini',
                'trade_no' => $tradeNo,
                'buyer_id' => $buyerId,
                'pay_product' => self::PRODUCT_MINI,
                'pay_action' => $this->productAction(self::PRODUCT_MINI),
                'raw' => $result->all(),
            ],
            'chan_order_no' => $params['out_trade_no'],
            'chan_trade_no' => $tradeNo,
        ];
    }

    /**
     * 发起刷卡支付。
     *
     * @param array $params 基础参数
     * @param array $order 订单上下文
     * @return array 下单结果
     * @throws PaymentException
     */
    private function doPos(array $params, array $order): array
    {
        $context = $this->collectOrderContext($order);
        $authCode = trim((string) ($context['auth_code'] ?? ''));
        if ($authCode === '') {
            throw new PaymentException('支付宝刷卡支付缺少 auth_code', 402);
        }

        $posParams = array_merge($params, [
            'auth_code' => $authCode,
        ]);

        /** @var Collection $result */
        $result = Pay::alipay()->pos($posParams);
        $tradeNo = (string) $this->extractCollectionValue($result, ['trade_no', 'order_id', 'out_trade_no'], '');

        return [
            'pay_product' => self::PRODUCT_POS,
            'pay_action' => $this->productAction(self::PRODUCT_POS),
            'pay_params' => [
                'type' => 'pos',
                'trade_no' => $tradeNo,
                'auth_code' => $authCode,
                'pay_product' => self::PRODUCT_POS,
                'pay_action' => $this->productAction(self::PRODUCT_POS),
                'raw' => $result->all(),
            ],
            'chan_order_no' => $params['out_trade_no'],
            'chan_trade_no' => $tradeNo,
        ];
    }

    /**
     * 发起转账。
     *
     * @param array $params 基础参数
     * @param array $order 订单上下文
     * @return array 下单结果
     * @throws PaymentException
     */
    private function doTransfer(array $params, array $order): array
    {
        $context = $this->collectOrderContext($order);
        $payeeInfo = $this->normalizeParamBag($context['payee_info'] ?? null);
        if ($payeeInfo === []) {
            throw new PaymentException('支付宝转账缺少 payee_info', 402);
        }

        $transferParams = [
            'out_biz_no' => $params['out_trade_no'],
            'trans_amount' => $params['total_amount'],
            'payee_info' => $payeeInfo,
        ];

        $notifyUrl = (string) ($params['_notify_url'] ?? '');
        if ($notifyUrl !== '') {
            $transferParams['_notify_url'] = $notifyUrl;
        }

        $orderTitle = trim((string) ($context['order_title'] ?? $context['subject'] ?? ''));
        if ($orderTitle !== '') {
            $transferParams['order_title'] = $orderTitle;
        }

        $remark = trim((string) ($context['remark'] ?? $context['body'] ?? ''));
        if ($remark !== '') {
            $transferParams['remark'] = $remark;
        }

        /** @var Collection $result */
        $result = Pay::alipay()->transfer($transferParams);
        $tradeNo = (string) $this->extractCollectionValue($result, ['trade_no', 'order_id', 'out_biz_no'], '');

        return [
            'pay_product' => self::PRODUCT_TRANSFER,
            'pay_action' => $this->productAction(self::PRODUCT_TRANSFER),
            'pay_params' => [
                'type' => 'transfer',
                'trade_no' => $tradeNo,
                'out_biz_no' => $transferParams['out_biz_no'],
                'trans_amount' => $transferParams['trans_amount'],
                'payee_info' => $payeeInfo,
                'pay_product' => self::PRODUCT_TRANSFER,
                'pay_action' => $this->productAction(self::PRODUCT_TRANSFER),
                'raw' => $result->all(),
            ],
            'chan_order_no' => $params['out_trade_no'],
            'chan_trade_no' => $tradeNo,
        ];
    }

    /**
     * 查询支付宝订单状态。
     *
     * @param array $order 订单上下文
     * @return array 查询结果
     * @throws PaymentException
     */
    public function query(array $order): array
    {
        $product = $this->chooseProduct($order, false);
        $action = $this->productAction($product);
        $outTradeNo = (string) ($order['chan_order_no'] ?? $order['order_id'] ?? $order['out_trade_no'] ?? '');
        $queryParams = $action === 'transfer'
            ? ['out_biz_no' => $outTradeNo, '_action' => $action]
            : ['out_trade_no' => $outTradeNo, '_action' => $action];

        try {
            /** @var Collection $result */
            $result = Pay::alipay()->query($queryParams);
            $tradeStatus = (string) $result->get('trade_status', $result->get('status', ''));
            $tradeNo = (string) $this->extractCollectionValue($result, ['trade_no', 'order_id', 'out_biz_no'], '');
            $totalAmount = (string) $this->extractCollectionValue($result, ['total_amount', 'trans_amount', 'amount'], '0');
            $status = match ($action) {
                'transfer' => in_array($tradeStatus, ['SUCCESS', 'PAY_SUCCESS', 'SUCCESSFUL'], true) ? 'success' : $tradeStatus,
                default => in_array($tradeStatus, ['TRADE_SUCCESS', 'TRADE_FINISHED'], true) ? 'success' : $tradeStatus,
            };

            return [
                'pay_product' => $product,
                'pay_action' => $action,
                'status'        => $status,
                'chan_trade_no' => $tradeNo,
                'pay_amount'    => (int) round(((float) $totalAmount) * 100),
            ];
        } catch (\Throwable $e) {
            throw new PaymentException('支付宝查询失败：' . $e->getMessage(), 402);
        }
    }

    /**
     * 关闭支付宝订单。
     *
     * @param array $order 订单上下文
     * @return array 关闭结果
     * @throws PaymentException
     */
    public function close(array $order): array
    {
        $product = $this->chooseProduct($order, false);
        $action = $this->productAction($product);
        if ($action === 'transfer') {
            throw new PaymentException('支付宝转账不支持关单', 402);
        }

        $outTradeNo = (string) ($order['chan_order_no'] ?? $order['order_id'] ?? $order['out_trade_no'] ?? '');
        $closeParams = [
            'out_trade_no' => $outTradeNo,
            '_action' => $action,
        ];

        try {
            Pay::alipay()->close($closeParams);
            return ['success' => true, 'msg' => '关闭成功', 'pay_product' => $product, 'pay_action' => $action];
        } catch (\Throwable $e) {
            throw new PaymentException('支付宝关单失败：' . $e->getMessage(), 402);
        }
    }

    /**
     * 发起支付宝退款。
     *
     * @param array $order 订单上下文
     * @return array 退款结果
     * @throws PaymentException
     */
    public function refund(array $order): array
    {
        $product = $this->chooseProduct($order, false);
        $action = $this->productAction($product);
        $outTradeNo   = (string) ($order['chan_order_no'] ?? $order['order_id'] ?? $order['out_trade_no'] ?? '');
        $refundAmount = (int) ($order['refund_amount'] ?? 0);
        $refundNo     = (string) ($order['refund_no'] ?? (($order['order_id'] ?? 'refund') . '_' . time()));
        $refundReason = (string)($order['refund_reason'] ?? '');

        if ($outTradeNo === '' || $refundAmount <= 0) {
            throw new PaymentException('退款参数不完整', 402);
        }

        $params = [
            $action === 'transfer' ? 'out_biz_no' : 'out_trade_no' => $outTradeNo,
            'refund_amount' => FormatHelper::amount($refundAmount),
            'out_request_no' => $refundNo,
            '_action' => $action,
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
                    'success' => true,
                    'pay_product' => $product,
                    'pay_action' => $action,
                    'chan_refund_no' => (string) $this->extractCollectionValue($result, ['trade_no', 'refund_no', 'out_request_no'], $refundNo),
                    'msg' => '退款成功',
                ];
            }
            throw new PaymentException($subMsg ?: '退款失败', 402);
        } catch (PaymentException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new PaymentException('支付宝退款失败：' . $e->getMessage(), 402);
        }
    }

    /**
     * 解析支付宝回调通知。
     *
     * @param Request $request 请求对象
     * @return array 回调结果
     * @throws PaymentException
     */
    public function notify(Request $request): array
    {
        $params = array_merge($request->get(), $request->post());

        try {
            /** @var Collection $result */
            $result      = Pay::alipay()->callback($params);
            $tradeStatus = $result->get('trade_status', '');
            $outTradeNo  = $result->get('out_trade_no', '');
            $tradeNo     = $result->get('trade_no', '');
            $totalAmount = (string) $result->get('total_amount', '0');
            $paidAt      = (string) $result->get('gmt_payment', '');

            if (!in_array($tradeStatus, ['TRADE_SUCCESS', 'TRADE_FINISHED'], true)) {
                throw new PaymentException('回调状态异常：' . $tradeStatus, 402);
            }

            return [
                'success'       => true,
                'status'        => 'success',
                'pay_order_id'  => $outTradeNo,
                'chan_order_no' => $outTradeNo,
                'chan_trade_no' => $tradeNo,
                'amount'        => (int) round(((float) $totalAmount) * 100),
                'paid_at'       => $paidAt !== '' ? (FormatHelper::timestamp((int) strtotime($paidAt)) ?: null) : null,
            ];
        } catch (PaymentException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new PaymentException('支付宝回调验签失败：' . $e->getMessage(), 402);
        }
    }

    /**
     * 返回回调成功响应。
     *
     * @return string|Response 响应内容
     */
    public function notifySuccess(): string|Response
    {
        return 'success';
    }

    /**
     * 返回回调失败响应。
     *
     * @return string|Response 响应内容
     */
    public function notifyFail(): string|Response
    {
        return 'fail';
    }
}



