<?php

declare(strict_types=1);

namespace app\common\payment;

use app\common\base\BasePayment;
use app\common\constant\PaymentPluginStatusConstant;
use app\common\constant\PaymentPluginTypeConstant;
use app\common\interface\PaymentInterface;
use app\common\interface\PayPluginInterface;
use app\common\sdk\chinaums\ChinaumsClient;
use app\common\sdk\chinaums\ChinaumsSdkException;
use app\common\trait\DirectPaymentProductSelectorTrait;
use app\exception\PaymentDefinitiveException;
use app\exception\PaymentException;
use app\exception\PaymentProductUnavailableException;
use app\exception\PaymentUncertainException;
use app\model\payment\PayOrder;
use app\repository\payment\trade\PayOrderRepository;
use DateTimeImmutable;
use JsonException;
use support\Request;
use support\Response;

/**
 * 银联商务开放平台支付插件。
 *
 * 对接银联商务开放平台的扫码、H5、H5 转小程序、查单、关单和退款接口。
 * 插件负责保存并复用下单产品、billDate、qrCodeId 等渠道上下文，同时在通知中校验
 * 商户、终端、订单、金额和目标支付体系；订单状态推进仍由平台支付服务负责。
 */
class ChinaumsApiPayment extends BasePayment implements PaymentInterface, PayPluginInterface
{
    use DirectPaymentProductSelectorTrait;

    private const PRODUCT_ALIPAY_SCAN = 'alipay_scan';
    private const PRODUCT_ALIPAY_H5 = 'alipay_h5';
    private const PRODUCT_WXPAY_SCAN = 'wxpay_scan';
    private const PRODUCT_WXPAY_H5 = 'wxpay_h5';
    private const PRODUCT_WXPAY_MINI_H5 = 'wxpay_mini_h5';
    private const PRODUCT_BANK_SCAN = 'bank_scan';

    private const INST_MID_QR = 'QRPAYDEFAULT';
    private const INST_MID_H5 = 'H5DEFAULT';

    private const PATH_QR_CREATE = '/v1/netpay/bills/get-qrcode';
    private const PATH_QR_QUERY = '/v1/netpay/bills/query';
    private const PATH_QR_CLOSE = '/v1/netpay/bills/close-qrcode';
    private const PATH_QR_REFUND = '/v1/netpay/bills/refund';
    private const PATH_H5_QUERY = '/v1/netpay/query';
    private const PATH_H5_CLOSE = '/v1/netpay/close';
    private const PATH_H5_REFUND = '/v1/netpay/refund';

    private ?ChinaumsClient $client = null;

    /**
     * 插件元信息。
     *
     * 配置表单由 getConfigSchema() 动态生成，以便按通道声明实际开通的产品。
     *
     * @var array<string, mixed>
     */
    protected array $paymentInfo = [
        'code' => 'chinaums_api',
        'name' => '银联商务开放平台支付',
        'plugin_type' => PaymentPluginTypeConstant::TYPE_DIRECT,
        'author' => 'MPAY',
        'version' => '1.1.0',
        'pay_types' => ['alipay', 'wxpay', 'bank'],
        'transfer_types' => [],
        'config_schema' => [],
    ];

    /**
     * 构造银联商务支付插件。
     *
     * @param PayOrderRepository $payOrderRepository 支付单仓库
     */
    public function __construct(private readonly PayOrderRepository $payOrderRepository)
    {
    }

    /**
     * 获取插件配置表单。
     *
     * @return array<int, array<string, mixed>>
     */
    public function getConfigSchema(): array
    {
        return [
            [
                'type' => 'input',
                'field' => 'app_id',
                'title' => '开放平台 AppId',
                'value' => '',
                'validate' => [['required' => true, 'message' => 'AppId不能为空']],
            ],
            [
                'type' => 'password',
                'field' => 'app_key',
                'title' => '开放平台 AppKey',
                'value' => '',
                'validate' => [['required' => true, 'message' => 'AppKey不能为空']],
            ],
            [
                'type' => 'input',
                'field' => 'merchant_no',
                'title' => '商户号 mid',
                'value' => '',
                'validate' => [['required' => true, 'message' => '商户号不能为空']],
            ],
            [
                'type' => 'input',
                'field' => 'terminal_no',
                'title' => '终端号 tid',
                'value' => '',
                'validate' => [['required' => true, 'message' => '终端号不能为空']],
            ],
            [
                'type' => 'password',
                'field' => 'communication_key',
                'title' => '异步通知通讯密钥',
                'value' => '',
                'validate' => [['required' => true, 'message' => '通讯密钥不能为空']],
            ],
            [
                'type' => 'input',
                'field' => 'msg_source_id',
                'title' => '订单来源编号',
                'value' => '',
                'props' => ['placeholder' => '银联商务分配/备案的4位来源编号'],
                'validate' => [['required' => true, 'message' => '来源编号不能为空']],
            ],
            [
                'type' => 'checkbox',
                'field' => 'enabled_products',
                'title' => '已开通并完成实测的产品',
                'value' => [],
                'options' => [
                    ['label' => '支付宝扫码', 'value' => self::PRODUCT_ALIPAY_SCAN],
                    ['label' => '支付宝 H5', 'value' => self::PRODUCT_ALIPAY_H5],
                    ['label' => '微信扫码', 'value' => self::PRODUCT_WXPAY_SCAN],
                    ['label' => '微信 H5', 'value' => self::PRODUCT_WXPAY_H5],
                    ['label' => '微信 H5 转小程序（独立产品）', 'value' => self::PRODUCT_WXPAY_MINI_H5],
                    ['label' => '云闪付扫码', 'value' => self::PRODUCT_BANK_SCAN],
                ],
                'validate' => [['required' => true, 'message' => '至少选择一个已开通且完成实测的产品']],
            ],
            [
                'type' => 'input',
                'field' => 'h5_app_name',
                'title' => '微信 H5 商户应用名称',
                'value' => '',
                'props' => ['placeholder' => '仅微信 H5 产品使用'],
            ],
            [
                'type' => 'input',
                'field' => 'h5_app_url',
                'title' => '微信 H5 支付域名/应用 URL',
                'value' => '',
                'props' => ['placeholder' => '需在渠道侧完成备案，必须使用 HTTPS'],
            ],
            [
                'type' => 'switch',
                'field' => 'sandbox',
                'title' => '测试环境',
                'value' => false,
                'props' => ['checkedText' => '测试', 'uncheckedText' => '生产'],
            ],
            [
                'type' => 'input',
                'field' => 'api_base_url',
                'title' => '自定义 HTTPS 网关',
                'value' => '',
                'props' => ['placeholder' => '留空使用银联商务官方生产/测试网关'],
            ],
        ];
    }

    /**
     * 初始化插件。
     *
     * @param array<string, mixed> $channelConfig 通道配置
     */
    public function init(array $channelConfig): void
    {
        parent::init($channelConfig);
        $this->client = null;

        foreach (['app_id', 'app_key', 'merchant_no', 'terminal_no', 'communication_key'] as $field) {
            if ($this->configText($field) === '') {
                throw new PaymentException('银联商务配置缺少 ' . $field, 40200);
            }
        }
        if (preg_match('/^[A-Za-z0-9]{4}$/', $this->configText('msg_source_id')) !== 1) {
            throw new PaymentException('银联商务订单来源编号必须是4位字母或数字', 40200);
        }

        $supported = [
            self::PRODUCT_ALIPAY_SCAN,
            self::PRODUCT_ALIPAY_H5,
            self::PRODUCT_WXPAY_SCAN,
            self::PRODUCT_WXPAY_H5,
            self::PRODUCT_WXPAY_MINI_H5,
            self::PRODUCT_BANK_SCAN,
        ];
        $enabled = $this->enabledProducts();
        if ($enabled === [] || array_diff($enabled, $supported) !== []) {
            throw new PaymentException('银联商务已开通产品配置为空或包含未知产品', 40200, [
                'enabled_products' => $enabled,
                'supported_products' => $supported,
            ]);
        }

        if (in_array(self::PRODUCT_WXPAY_H5, $enabled, true)) {
            $appUrl = strtolower($this->configText('h5_app_url'));
            if ($this->configText('h5_app_name') === '' || !str_starts_with($appUrl, 'https://')) {
                throw new PaymentException('微信 H5 必须配置应用名称和已备案的 HTTPS 应用 URL', 40200);
            }
        }

        $customGateway = strtolower($this->configText('api_base_url'));
        if ($customGateway !== '' && !str_starts_with($customGateway, 'https://')) {
            throw new PaymentException('银联商务自定义网关必须使用 HTTPS', 40200);
        }
    }

    /**
     * 发起支付。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed> 标准支付结果
     */
    public function pay(array $order): array
    {
        $payType = strtolower(trim((string) ($order['pay_type_code'] ?? '')));

        // 该产品不是普通移动端兜底：调用方明确指定后要么使用它，要么在本地失败，
        // 不能因未开通而悄悄换成微信 H5/扫码，造成客户端承接语义变化。
        if ($payType === 'wxpay' && $this->requestedPaymentMethod($order) === 'urlscheme') {
            return $this->h5PayByType($order, $payType, true);
        }

        $handlers = [
            'h5' => [
                'products' => ['alipay' => self::PRODUCT_ALIPAY_H5, 'wxpay' => self::PRODUCT_WXPAY_H5],
                'handler' => fn (): array => $this->h5PayByType($order, $payType, false),
            ],
            'jump' => [
                'products' => ['alipay' => self::PRODUCT_ALIPAY_H5, 'wxpay' => self::PRODUCT_WXPAY_H5],
                'handler' => fn (): array => $this->h5PayByType($order, $payType, false),
            ],
            'qrcode' => [
                'products' => [
                    'bank' => self::PRODUCT_BANK_SCAN,
                    'wxpay' => self::PRODUCT_WXPAY_SCAN,
                    'alipay' => self::PRODUCT_ALIPAY_SCAN,
                ],
                'handler' => fn (): array => $this->qrcodePayByType($order, $payType),
            ],
        ];

        return $this->executeDirectPaymentProduct($order, $handlers, '银联商务');
    }

    /**
     * 查询支付订单。
     *
     * @param array<string, mixed> $order 标准插件查单参数
     * @return array<string, mixed> 标准支付状态结果
     */
    public function query(array $order): array
    {
        $product = $this->orderProduct($order);
        $isH5 = $this->isH5Product($product);
        $channelOrderNo = $this->channelOrderNo($order);
        $params = $this->baseRequest() + ['instMid' => $isH5 ? self::INST_MID_H5 : self::INST_MID_QR];

        if ($isH5) {
            $params['merOrderId'] = $channelOrderNo;
            $data = $this->execute(self::PATH_H5_QUERY, $params, 'H5查单');
            $channelStatus = strtoupper(trim((string) ($data['status'] ?? '')));
            $status = match ($channelStatus) {
                'TRADE_SUCCESS', 'TRADE_REFUND' => PaymentPluginStatusConstant::SUCCESS,
                'TRADE_CLOSED' => PaymentPluginStatusConstant::CLOSED,
                'NEW_ORDER', 'UNKNOWN', 'WAIT_BUYER_PAY' => PaymentPluginStatusConstant::PENDING,
                default => throw $this->unknownStatus('H5查单', $channelStatus, $order),
            };
            $responseOrderNo = $this->requiredText($data['merOrderId'] ?? '', '银联商务H5查单缺少 merOrderId');
        } else {
            $params['billNo'] = $channelOrderNo;
            $params['billDate'] = $this->orderBillDate($order);
            $data = $this->execute(self::PATH_QR_QUERY, $params, '扫码查单');
            $channelStatus = strtoupper(trim((string) ($data['billStatus'] ?? '')));
            $status = match ($channelStatus) {
                'PAID', 'REFUND' => PaymentPluginStatusConstant::SUCCESS,
                'CLOSED' => PaymentPluginStatusConstant::CLOSED,
                'UNPAID' => PaymentPluginStatusConstant::PENDING,
                default => throw $this->unknownStatus('扫码查单', $channelStatus, $order),
            };
            $responseOrderNo = $this->requiredText($data['billNo'] ?? '', '银联商务扫码查单缺少 billNo');
        }

        $this->assertEquals('订单号', $responseOrderNo, $channelOrderNo, $this->orderPayNo($order));
        $this->assertResponseIdentity($data, '查单', true);
        $amount = $this->integerCents($data['totalAmount'] ?? null, '银联商务查单金额');
        $expectedAmount = $this->orderAmount($order);
        if ($amount !== $expectedAmount) {
            throw new PaymentException('银联商务查单金额与支付单不一致', 40200, [
                'pay_no' => $this->orderPayNo($order),
                'response_amount' => $amount,
                'order_amount' => $expectedAmount,
            ]);
        }
        $this->assertCnyIfPresent($data, '银联商务查单');

        $detail = $this->billPayment($data, false);
        $channelTradeNo = $this->firstText(
            $data['targetOrderId'] ?? '',
            $detail['targetOrderId'] ?? '',
            $data['seqId'] ?? ''
        );
        if ($status === PaymentPluginStatusConstant::SUCCESS) {
            if ($channelTradeNo === '') {
                throw new PaymentException('银联商务成功查单缺少渠道交易号', 40200, [
                    'pay_no' => $this->orderPayNo($order),
                ]);
            }
            $this->assertTargetSystem($product, $this->firstText($data['targetSys'] ?? '', $detail['targetSys'] ?? ''), '查单');
        }

        return [
            'status' => $status,
            'pay_no' => $this->orderPayNo($order),
            'paid_amount' => $status === PaymentPluginStatusConstant::SUCCESS ? $amount : null,
            'chan_order_no' => $channelOrderNo,
            'chan_trade_no' => $channelTradeNo,
            'channel_status' => $channelStatus,
            'message' => $channelStatus,
            'paid_at' => $status === PaymentPluginStatusConstant::SUCCESS
                ? $this->normalizeTime($this->firstText($data['payTime'] ?? '', $detail['payTime'] ?? ''))
                : null,
            'raw_data' => $this->responseSummary($isH5 ? self::PATH_H5_QUERY : self::PATH_QR_QUERY, $data),
        ];
    }

    /**
     * 关闭支付订单。
     *
     * @param array<string, mixed> $order 标准插件关单参数
     * @return array<string, mixed> 标准关单结果
     */
    public function close(array $order): array
    {
        $product = $this->orderProduct($order);
        $isH5 = $this->isH5Product($product);
        $params = $this->baseRequest() + ['instMid' => $isH5 ? self::INST_MID_H5 : self::INST_MID_QR];

        if ($isH5) {
            $params['merOrderId'] = $this->channelOrderNo($order);
            $data = $this->execute(self::PATH_H5_CLOSE, $params, 'H5关单', true);
            $status = strtoupper(trim((string) ($data['status'] ?? '')));
            if ($status !== 'TRADE_CLOSED') {
                throw new PaymentException('银联商务H5关单未返回 TRADE_CLOSED', 40200, [
                    'pay_no' => $this->orderPayNo($order),
                    'channel_status' => $status,
                ]);
            }
        } else {
            $qrCodeId = $this->orderQrCodeId($order);
            if ($qrCodeId === '') {
                throw new PaymentException('银联商务扫码关单缺少官方 qrCodeId，不能伪造关单请求', 40200, [
                    'pay_no' => $this->orderPayNo($order),
                ]);
            }
            $params['qrCodeId'] = $qrCodeId;
            $data = $this->execute(self::PATH_QR_CLOSE, $params, '扫码关单', true);
            $status = strtoupper(trim((string) ($data['billStatus'] ?? 'CLOSED')));
            if ($status !== 'CLOSED') {
                throw new PaymentException('银联商务扫码关单未返回关闭状态', 40200, [
                    'pay_no' => $this->orderPayNo($order),
                    'channel_status' => $status,
                ]);
            }
        }
        $this->assertResponseIdentity($data, '关单', false);

        return [
            'status' => PaymentPluginStatusConstant::CLOSED,
            'pay_no' => $this->orderPayNo($order),
            'chan_order_no' => $this->channelOrderNo($order),
            'chan_trade_no' => trim((string) ($order['chan_trade_no'] ?? '')),
            'channel_status' => $status,
            'message' => '银联商务订单已关闭',
        ];
    }

    /**
     * 发起退款。
     *
     * @param array<string, mixed> $order 标准插件退款参数
     * @return array<string, mixed> 标准退款结果
     */
    public function refund(array $order): array
    {
        $product = $this->orderProduct($order);
        $isH5 = $this->isH5Product($product);
        $refundNo = trim((string) ($order['refund_no'] ?? ''));
        $refundAmount = $this->integerCents($order['refund_amount'] ?? null, '银联商务退款金额');
        if ($refundNo === '') {
            throw new PaymentDefinitiveException('银联商务退款单号不能为空', 40200);
        }
        $channelRefundNo = $this->configText('msg_source_id') . $refundNo;
        $this->assertChannelOrderFormat($channelRefundNo, '退款单号');

        $params = $this->baseRequest() + [
            'instMid' => $isH5 ? self::INST_MID_H5 : self::INST_MID_QR,
            'refundOrderId' => $channelRefundNo,
            'refundAmount' => $refundAmount,
        ];
        if ($isH5) {
            $params['merOrderId'] = $this->channelOrderNo($order);
            $path = self::PATH_H5_REFUND;
        } else {
            $params['billNo'] = $this->channelOrderNo($order);
            $params['billDate'] = $this->orderBillDate($order);
            $path = self::PATH_QR_REFUND;
        }

        $data = $this->execute($path, $params, '退款', true);
        $this->assertResponseIdentity($data, '退款', false);
        $responseRefundNo = $this->requiredText($data['refundOrderId'] ?? '', '银联商务退款响应缺少 refundOrderId');
        $this->assertEquals('退款单号', $responseRefundNo, $channelRefundNo, $this->orderPayNo($order));
        $responseAmount = $this->integerCents($data['refundAmount'] ?? null, '银联商务退款响应金额');
        if ($responseAmount !== $refundAmount) {
            throw new PaymentException('银联商务退款响应金额不匹配', 40200, [
                'refund_no' => $refundNo,
                'refund_amount' => $refundAmount,
                'response_refund_amount' => $responseAmount,
            ]);
        }
        $this->assertCnyIfPresent($data, '银联商务退款');

        $channelStatus = strtoupper(trim((string) ($isH5
            ? ($data['status'] ?? '')
            : ($data['refundStatus'] ?? $data['status'] ?? ''))));
        return match ($channelStatus) {
            'TRADE_SUCCESS', 'SUCCESS' => [
                'status' => PaymentPluginStatusConstant::SUCCESS,
                'refund_no' => $refundNo,
                'pay_no' => $this->orderPayNo($order),
                'message' => '银联商务退款成功',
                'chan_refund_no' => $responseRefundNo,
                'refund_amount' => $responseAmount,
                'channel_status' => $channelStatus,
            ],
            'PROCESSING', 'PENDING', 'UNKNOWN' => [
                'status' => PaymentPluginStatusConstant::PENDING,
                'refund_no' => $refundNo,
                'pay_no' => $this->orderPayNo($order),
                'message' => '银联商务退款处理中',
                'chan_refund_no' => $responseRefundNo,
                'refund_amount' => $responseAmount,
                'channel_status' => $channelStatus,
            ],
            'FAIL', 'FAILED', 'TRADE_CLOSED' => throw new PaymentDefinitiveException('银联商务退款失败', 40200, [
                'refund_no' => $refundNo,
                'refund_status' => $channelStatus,
            ]),
            default => throw new PaymentUncertainException('银联商务退款返回未知状态', 40200, [
                'refund_no' => $refundNo,
                'refund_status' => $channelStatus,
            ]),
        };
    }

    /**
     * 解析并校验支付通知。
     *
     * 验签后根据 instMid 选择订单字段和成功状态，并使用支付单中的产品快照校验
     * 通知所属支付体系，避免跨产品或跨通道通知推进订单。
     *
     * @param Request $request 支付通知请求
     * @return array<string, mixed> 标准支付通知结果
     */
    public function notify(Request $request): array
    {
        $payload = (array) $request->post();
        if ($payload === [] || !$this->client()->verifyNotify($payload)) {
            throw new PaymentException('银联商务回调验签失败', 40200);
        }

        $instMid = strtoupper(trim((string) ($payload['instMid'] ?? '')));
        if (!in_array($instMid, [self::INST_MID_H5, self::INST_MID_QR], true)) {
            throw new PaymentException('银联商务回调 instMid 不受支持', 40200, ['inst_mid' => $instMid]);
        }
        $isH5 = $instMid === self::INST_MID_H5;
        $channelOrderNo = $this->requiredText(
            $isH5 ? ($payload['merOrderId'] ?? '') : ($payload['billNo'] ?? ''),
            '银联商务回调缺少渠道订单号'
        );
        $payNo = $this->payNoFromChannelOrderNo($channelOrderNo);
        $payOrder = $this->payOrderRepository->findByPayNo($payNo, [
            'pay_no', 'pay_amount', 'channel_id', 'channel_order_no', 'channel_trade_no', 'ext_json',
        ]);
        if (!$payOrder instanceof PayOrder) {
            throw new PaymentException('银联商务回调对应的支付单不存在', 40200, ['pay_no' => $payNo]);
        }

        $channelId = (int) $this->getConfig('channel_id', 0);
        if ($channelId <= 0 || (int) $payOrder->channel_id !== $channelId) {
            throw new PaymentException('银联商务回调支付单不属于当前通道', 40200, ['pay_no' => $payNo]);
        }
        $storedChannelOrderNo = trim((string) ($payOrder->channel_order_no ?? ''));
        $expectedChannelOrderNo = $storedChannelOrderNo !== ''
            ? $storedChannelOrderNo
            : $this->configText('msg_source_id') . $payNo;
        $this->assertEquals('渠道订单号', $channelOrderNo, $expectedChannelOrderNo, $payNo);

        $this->assertEquals('商户号 mid', $payload['mid'] ?? '', $this->configText('merchant_no'), $payNo);
        $this->assertEquals('终端号 tid', $payload['tid'] ?? '', $this->configText('terminal_no'), $payNo);
        $status = strtoupper(trim((string) ($isH5 ? ($payload['status'] ?? '') : ($payload['billStatus'] ?? ''))));
        $requiredSuccess = $isH5 ? 'TRADE_SUCCESS' : 'PAID';
        if ($status !== $requiredSuccess) {
            throw new PaymentException('银联商务仅允许处理明确支付成功的通知', 40200, [
                'pay_no' => $payNo,
                'channel_status' => $status,
            ]);
        }

        $product = $this->payOrderProduct($payOrder);
        if ($this->isH5Product($product) !== $isH5) {
            throw new PaymentException('银联商务回调 instMid 与下单产品不一致', 40200, [
                'pay_no' => $payNo,
                'pay_product' => $product,
                'inst_mid' => $instMid,
            ]);
        }

        $amount = $this->integerCents($payload['totalAmount'] ?? null, '银联商务回调金额');
        if ($amount !== (int) $payOrder->pay_amount) {
            throw new PaymentException('银联商务回调金额与支付单金额不一致', 40200, [
                'pay_no' => $payNo,
                'notify_amount' => $amount,
                'order_amount' => (int) $payOrder->pay_amount,
            ]);
        }
        $this->assertCnyIfPresent($payload, '银联商务回调');

        $detail = $this->billPayment($payload, !$isH5);
        $targetSystem = $this->firstText($payload['targetSys'] ?? '', $detail['targetSys'] ?? '');
        $this->assertTargetSystem($product, $targetSystem, '回调');
        $channelTradeNo = $this->firstText(
            $payload['targetOrderId'] ?? '',
            $detail['targetOrderId'] ?? '',
            $payload['seqId'] ?? '',
            $detail['seqId'] ?? ''
        );
        if ($channelTradeNo === '') {
            throw new PaymentException('银联商务成功回调缺少渠道交易号', 40200, ['pay_no' => $payNo]);
        }
        $storedTradeNo = trim((string) ($payOrder->channel_trade_no ?? ''));
        if ($storedTradeNo !== '' && !hash_equals($storedTradeNo, $channelTradeNo)) {
            throw new PaymentException('银联商务重复回调渠道交易号不一致', 40200, ['pay_no' => $payNo]);
        }

        return [
            'status' => PaymentPluginStatusConstant::SUCCESS,
            'pay_no' => $payNo,
            'paid_amount' => $amount,
            'message' => $status,
            'chan_order_no' => $channelOrderNo,
            'chan_trade_no' => $channelTradeNo,
            'channel_status' => $status,
            'paid_at' => $this->normalizeTime($this->firstText($payload['payTime'] ?? '', $detail['payTime'] ?? '')),
        ];
    }

    /**
     * 返回渠道要求的支付通知成功应答。
     *
     * @return string|Response 成功应答
     */
    public function notifySuccess(): string|Response
    {
        return 'SUCCESS';
    }

    /**
     * 返回渠道要求的支付通知失败应答。
     *
     * @return string|Response 失败应答
     */
    public function notifyFail(): string|Response
    {
        return 'FAILED';
    }

    /**
     * 按支付方式发起 H5 支付。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param string $payType 平台支付方式编码
     * @param bool $mini 是否明确请求微信 H5 转小程序产品
     * @return array<string, mixed> 标准支付结果
     */
    private function h5PayByType(array $order, string $payType, bool $mini): array
    {
        return match ($payType) {
            'alipay' => $this->h5Pay($order, self::PRODUCT_ALIPAY_H5, '/v1/netpay/trade/h5-pay', false),
            'wxpay' => $mini
                ? $this->h5Pay($order, self::PRODUCT_WXPAY_MINI_H5, '/v1/netpay/wxpay/h5-to-minipay', false)
                : $this->h5Pay($order, self::PRODUCT_WXPAY_H5, '/v1/netpay/wxpay/h5-pay', true),
            default => throw new PaymentProductUnavailableException('银联商务当前支付方式不支持H5产品', 40200, [
                'channel_error_code' => 'PRODUCT_NOT_OPEN',
            ]),
        };
    }

    /**
     * 按支付方式发起二维码支付。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param string $payType 平台支付方式编码
     * @return array<string, mixed> 标准支付结果
     */
    private function qrcodePayByType(array $order, string $payType): array
    {
        return match ($payType) {
            'bank' => $this->qrcodePay($order, self::PRODUCT_BANK_SCAN),
            'wxpay' => $this->qrcodePay($order, self::PRODUCT_WXPAY_SCAN),
            'alipay' => $this->qrcodePay($order, self::PRODUCT_ALIPAY_SCAN),
            default => throw new PaymentException('银联商务不支持当前扫码支付方式', 40200),
        };
    }

    /**
     * 发起二维码支付。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param string $product 银联商务产品编码
     * @return array<string, mixed> 标准支付结果
     */
    private function qrcodePay(array $order, string $product): array
    {
        $this->ensureProduct($product);
        $billDate = date('Y-m-d');
        $params = $this->baseOrder($order) + [
            'instMid' => self::INST_MID_QR,
            'billNo' => $this->channelOrderNo($order),
            'billDate' => $billDate,
            'billDesc' => mb_strcut((string) ($order['subject'] ?? ''), 0, 127, 'UTF-8'),
        ];
        $data = $this->execute(self::PATH_QR_CREATE, $params, '扫码下单', true);
        $qrcode = $this->requiredText($data['billQRCode'] ?? '', '银联商务扫码下单未返回二维码内容');
        $qrCodeId = $this->qrCodeIdFromUrl($qrcode);

        return $this->pendingPaymentResult($order, [
            'pay_page' => 'qrcode',
            'pay_type' => (string) $order['pay_type_code'],
            'pay_product' => $product,
            'pay_action' => 'get-qrcode',
            'pay_params' => array_filter([
                'qrcode' => $qrcode,
                'qr_code_id' => $qrCodeId,
                'bill_date' => $billDate,
            ], static fn (mixed $value): bool => $value !== ''),
            'channel_context' => array_filter([
                'qr_code_id' => $qrCodeId,
                'bill_date' => $billDate,
            ], static fn (mixed $value): bool => $value !== ''),
            'chan_order_no' => $this->channelOrderNo($order),
            'chan_trade_no' => '',
        ]);
    }

    /**
     * 构建 OPEN-FORM-PARAM 标准表单响应。
     *
     * content/signature 保留上游原文并由前端表单提交，避免后端拼接 query URL 改变参数内容。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param string $product 银联商务产品编码
     * @param string $path 渠道 H5 下单路径
     * @param bool $wechatH5 是否补充微信 H5 场景参数
     * @return array<string, mixed> 标准支付结果
     */
    private function h5Pay(array $order, string $product, string $path, bool $wechatH5): array
    {
        $this->ensureProduct($product);
        $params = $this->baseOrder($order) + [
            'instMid' => self::INST_MID_H5,
            'merOrderId' => $this->channelOrderNo($order),
            'orderDesc' => mb_strcut((string) ($order['subject'] ?? ''), 0, 127, 'UTF-8'),
        ];
        if ($wechatH5) {
            $params['sceneType'] = 'AND_WAP';
            $params['merAppName'] = $this->configText('h5_app_name');
            $params['merAppId'] = $this->configText('h5_app_url');
        }

        try {
            $payload = $this->client()->formParameters($params);
            $action = $this->client()->endpoint($path);
        } catch (ChinaumsSdkException $e) {
            throw new PaymentDefinitiveException('银联商务H5下单参数生成失败：' . $e->getMessage(), 40200);
        }

        return $this->pendingPaymentResult($order, [
            'pay_page' => 'jump',
            'pay_type' => (string) $order['pay_type_code'],
            'pay_product' => $product,
            'pay_action' => 'h5-form',
            'pay_params' => [
                'method' => 'post',
                'action' => $action,
                'payload' => $payload,
            ],
            'chan_order_no' => $this->channelOrderNo($order),
            'chan_trade_no' => '',
        ]);
    }

    /**
     * 构建渠道基础订单参数。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed> 渠道基础订单参数
     */
    private function baseOrder(array $order): array
    {
        $amount = $this->orderAmount($order);
        $callbackUrl = trim((string) ($order['callback_url'] ?? ''));
        $returnUrl = trim((string) ($order['return_url'] ?? ''));
        if ($callbackUrl === '' || $returnUrl === '') {
            throw new PaymentException('银联商务下单回调地址和返回地址不能为空', 40200, [
                'pay_no' => $this->orderPayNo($order),
            ]);
        }

        return $this->baseRequest() + [
            'totalAmount' => $amount,
            'notifyUrl' => $callbackUrl,
            'returnUrl' => $returnUrl,
            'clientIp' => trim((string) ($order['client_ip'] ?? '')),
        ];
    }

    /**
     * 构建渠道公共请求参数。
     *
     * @return array<string, string>
     */
    private function baseRequest(): array
    {
        try {
            $msgId = bin2hex(random_bytes(16));
        } catch (\Throwable) {
            throw new PaymentException('银联商务请求流水生成失败', 40200);
        }

        return [
            'msgId' => $msgId,
            'requestTimestamp' => date('Y-m-d H:i:s'),
            'mid' => $this->configText('merchant_no'),
            'tid' => $this->configText('terminal_no'),
        ];
    }

    /**
     * 调用渠道接口。
     *
     * 变更类请求发生传输异常时结果不确定，渠道明确失败时才抛出确定失败异常。
     *
     * @param string $path 渠道接口路径
     * @param array<string, mixed> $params 请求参数
     * @param string $scene 业务场景说明
     * @param bool $mutation 是否为产生渠道状态变更的请求
     * @return array<string, mixed> 已通过渠道成功码校验的响应
     */
    private function execute(string $path, array $params, string $scene, bool $mutation = false): array
    {
        try {
            $data = $this->client()->request($path, $params);
        } catch (ChinaumsSdkException $e) {
            $exception = $mutation ? PaymentUncertainException::class : PaymentException::class;
            throw new $exception('银联商务' . $scene . '失败：' . $e->getMessage(), 40200, [
                'channel_error_code' => 'CHINAUMS_SDK_ERROR',
            ]);
        }
        if (strtoupper(trim((string) ($data['errCode'] ?? ''))) !== 'SUCCESS') {
            $exception = $mutation ? PaymentDefinitiveException::class : PaymentException::class;
            throw new $exception('银联商务' . $scene . '失败：' . $this->firstText(
                $data['errMsg'] ?? '',
                $data['errInfo'] ?? '',
                '渠道返回失败'
            ), 40200, [
                'channel_error_code' => trim((string) ($data['errCode'] ?? 'CHINAUMS_API_ERROR')),
                'response' => $this->responseSummary($path, $data),
            ]);
        }

        return $data;
    }

    /**
     * 获取当前通道的银联商务客户端。
     *
     * @return ChinaumsClient
     */
    private function client(): ChinaumsClient
    {
        if ($this->client === null) {
            $this->client = new ChinaumsClient([
                'app_id' => $this->configText('app_id'),
                'app_key' => $this->configText('app_key'),
                'communication_key' => $this->configText('communication_key'),
                'sandbox' => $this->configBool('sandbox'),
                'api_base_url' => $this->configText('api_base_url'),
            ]);
        }

        return $this->client;
    }

    /**
     * 获取渠道订单号。
     *
     * @param array<string, mixed> $order 标准插件订单参数
     * @return string 渠道订单号
     */
    private function channelOrderNo(array $order): string
    {
        $channelOrderNo = $this->firstText(
            $order['chan_order_no'] ?? '',
            $this->configText('msg_source_id') . $this->orderPayNo($order)
        );
        $this->assertChannelOrderFormat($channelOrderNo, '订单号');

        return $channelOrderNo;
    }

    /**
     * 从渠道订单号中还原平台支付单号。
     *
     * 渠道订单号固定以当前通道的来源编号开头，先校验前缀可避免跨来源通知误匹配。
     *
     * @param string $channelOrderNo 渠道订单号
     * @return string 平台支付单号
     */
    private function payNoFromChannelOrderNo(string $channelOrderNo): string
    {
        $source = $this->configText('msg_source_id');
        if (!str_starts_with($channelOrderNo, $source)) {
            throw new PaymentException('银联商务回调订单号来源编号不匹配', 40200);
        }
        $payNo = substr($channelOrderNo, strlen($source));
        if ($payNo === '') {
            throw new PaymentException('银联商务回调订单号缺少 MPAY 支付单号', 40200);
        }

        return $payNo;
    }

    /**
     * 校验银联商务渠道订单号格式。
     *
     * @param string $value 渠道订单号
     * @param string $field 字段说明
     * @return void
     */
    private function assertChannelOrderFormat(string $value, string $field): void
    {
        $length = strlen($value);
        if ($length <= 6 || $length >= 32 || preg_match('/^[A-Za-z0-9]+$/', $value) !== 1) {
            throw new PaymentException('银联商务' . $field . '必须为7至31位字母或数字', 40200);
        }
    }

    /**
     * 获取平台支付单号。
     *
     * @param array<string, mixed> $order 标准插件订单参数
     * @return string 平台支付单号
     */
    private function orderPayNo(array $order): string
    {
        $payNo = trim((string) ($order['pay_no'] ?? ''));
        if ($payNo === '') {
            throw new PaymentException('银联商务请求缺少支付单号', 40200);
        }

        return $payNo;
    }

    /**
     * 获取订单金额。
     *
     * @param array<string, mixed> $order 标准插件订单参数
     * @return int 订单金额，单位分
     */
    private function orderAmount(array $order): int
    {
        return $this->integerCents($order['amount'] ?? null, '银联商务订单金额');
    }

    /**
     * 读取正整数分金额。
     *
     * @param mixed $value 待校验金额
     * @param string $scene 金额字段说明
     * @return int 金额，单位分
     */
    private function integerCents(mixed $value, string $scene): int
    {
        if (is_int($value)) {
            $amount = $value;
        } elseif (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            if (strlen($value) > strlen((string) PHP_INT_MAX)) {
                throw new PaymentException($scene . '超过系统整数范围', 40200);
            }
            $amount = (int) $value;
        } else {
            throw new PaymentException($scene . '必须是整数分', 40200);
        }
        if ($amount <= 0) {
            throw new PaymentException($scene . '必须大于0分', 40200);
        }

        return $amount;
    }

    /**
     * 获取订单支付产品。
     *
     * @param array<string, mixed> $order 标准插件订单参数
     * @return string 下单产品快照
     */
    private function orderProduct(array $order): string
    {
        $product = trim((string) ($order['pay_product'] ?? ''));
        if ($product === '') {
            throw new PaymentException('银联商务支付单缺少下单产品快照', 40200, [
                'pay_no' => $this->orderPayNo($order),
            ]);
        }
        $this->assertSupportedProduct($product);

        return $product;
    }

    /**
     * 从支付单扩展信息读取下单产品快照。
     *
     * @param PayOrder $payOrder 支付单
     * @return string 下单产品快照
     */
    private function payOrderProduct(PayOrder $payOrder): string
    {
        $extra = (array) ($payOrder->ext_json ?? []);
        $context = (array) ($extra['payment_context'] ?? []);
        $product = trim((string) ($context['pay_product'] ?? ''));
        if ($product === '') {
            throw new PaymentException('银联商务支付单缺少下单产品快照', 40200, [
                'pay_no' => (string) $payOrder->pay_no,
            ]);
        }
        $this->assertSupportedProduct($product);

        return $product;
    }

    /**
     * 校验产品属于插件支持范围。
     *
     * @param string $product 银联商务产品编码
     * @return void
     */
    private function assertSupportedProduct(string $product): void
    {
        if (!in_array($product, [
            self::PRODUCT_ALIPAY_SCAN,
            self::PRODUCT_ALIPAY_H5,
            self::PRODUCT_WXPAY_SCAN,
            self::PRODUCT_WXPAY_H5,
            self::PRODUCT_WXPAY_MINI_H5,
            self::PRODUCT_BANK_SCAN,
        ], true)) {
            throw new PaymentException('银联商务支付单产品不受支持', 40200, ['pay_product' => $product]);
        }
    }

    private function isH5Product(string $product): bool
    {
        return in_array($product, [
            self::PRODUCT_ALIPAY_H5,
            self::PRODUCT_WXPAY_H5,
            self::PRODUCT_WXPAY_MINI_H5,
        ], true);
    }

    /**
     * 获取渠道订单日期。
     *
     * @param array<string, mixed> $order 标准插件订单参数
     * @return string 原扫码订单日期
     */
    private function orderBillDate(array $order): string
    {
        $channelContext = (array) ($order['channel_context'] ?? []);
        $date = trim((string) ($channelContext['bill_date'] ?? ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            throw new PaymentException('银联商务扫码订单缺少下单时的 billDate，不能猜测原交易日期', 40200, [
                'pay_no' => $this->orderPayNo($order),
            ]);
        }

        return $date;
    }

    /**
     * 获取渠道二维码标识。
     *
     * @param array<string, mixed> $order 标准插件订单参数
     * @return string 渠道二维码标识；无法提取时返回空字符串
     */
    private function orderQrCodeId(array $order): string
    {
        $channelContext = (array) ($order['channel_context'] ?? []);

        return $this->firstText(
            $channelContext['qr_code_id'] ?? '',
            $this->qrCodeIdFromUrl((string) ($channelContext['qrcode'] ?? ''))
        );
    }

    private function qrCodeIdFromUrl(string $url): string
    {
        $query = parse_url(trim($url), PHP_URL_QUERY);
        if (!is_string($query) || $query === '') {
            return '';
        }
        parse_str($query, $params);

        return $this->firstText($params['id'] ?? '', $params['qrCodeId'] ?? '', $params['qrcodeId'] ?? '');
    }

    /**
     * 校验渠道响应身份。
     *
     * @param array<string, mixed> $data 渠道响应
     * @param string $scene 业务场景说明
     * @param bool $required 是否要求响应必须返回商户和终端标识
     * @return void
     */
    private function assertResponseIdentity(array $data, string $scene, bool $required): void
    {
        foreach (['mid' => 'merchant_no', 'tid' => 'terminal_no'] as $field => $config) {
            $actual = trim((string) ($data[$field] ?? ''));
            if (!$required && $actual === '') {
                continue;
            }
            $this->assertEquals($scene . ' ' . $field, $actual, $this->configText($config), '');
        }
    }

    private function assertEquals(string $field, mixed $actual, string $expected, string $payNo): void
    {
        $actual = trim((string) $actual);
        if ($actual === '' || $expected === '' || !hash_equals($expected, $actual)) {
            throw new PaymentException('银联商务' . $field . '不匹配', 40200, array_filter([
                'pay_no' => $payNo,
                'field' => $field,
            ]));
        }
    }

    /**
     * 校验响应中的可选币种。
     *
     * @param array<string, mixed> $data 渠道响应
     * @param string $scene 业务场景说明
     * @return void
     */
    private function assertCnyIfPresent(array $data, string $scene): void
    {
        foreach (['currency', 'currencyCode', 'currency_code'] as $field) {
            if (!array_key_exists($field, $data) || trim((string) $data[$field]) === '') {
                continue;
            }
            $currency = strtoupper(trim((string) $data[$field]));
            if (!in_array($currency, ['CNY', 'RMB', '156'], true)) {
                throw new PaymentException($scene . '币种不是人民币', 40200, [
                    'field' => $field,
                    'currency' => $currency,
                ]);
            }
        }
    }

    /**
     * 校验渠道目标支付体系与下单产品一致。
     *
     * @param string $product 下单产品快照
     * @param string $targetSystem 渠道目标支付体系
     * @param string $scene 业务场景说明
     * @return void
     */
    private function assertTargetSystem(string $product, string $targetSystem, string $scene): void
    {
        $normalized = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $targetSystem) ?? '');
        $matched = match ($product) {
            self::PRODUCT_ALIPAY_SCAN, self::PRODUCT_ALIPAY_H5 => str_contains($normalized, 'ALIPAY'),
            self::PRODUCT_WXPAY_SCAN, self::PRODUCT_WXPAY_H5, self::PRODUCT_WXPAY_MINI_H5 =>
                str_contains($normalized, 'WXPAY') || str_contains($normalized, 'WECHAT'),
            self::PRODUCT_BANK_SCAN => str_contains($normalized, 'UNIONPAY')
                || str_contains($normalized, 'CLOUDPAY')
                || str_contains($normalized, 'UAC'),
            default => false,
        };
        if ($normalized === '' || !$matched) {
            throw new PaymentException('银联商务' . $scene . '支付体系与下单产品不一致', 40200, [
                'pay_product' => $product,
                'target_system' => $targetSystem,
            ]);
        }
    }

    /**
     * 解析账单支付信息。
     *
     * @param array<string, mixed> $payload 渠道响应或通知载荷
     * @param bool $required 是否必须存在账单支付信息
     * @return array<string, mixed> 账单支付信息
     */
    private function billPayment(array $payload, bool $required): array
    {
        $value = $payload['billPayment'] ?? null;
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && trim($value) !== '') {
            try {
                $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new PaymentException('银联商务 billPayment 不是合法 JSON', 40200);
            }
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        if ($required) {
            throw new PaymentException('银联商务扫码成功回调缺少 billPayment', 40200);
        }

        return [];
    }

    /**
     * 构造未知渠道状态异常。
     *
     * @param string $scene 业务场景说明
     * @param string $status 未识别的渠道状态
     * @param array<string, mixed> $order 标准插件订单参数
     * @return PaymentException 未知状态异常
     */
    private function unknownStatus(string $scene, string $status, array $order): PaymentException
    {
        return new PaymentException('银联商务' . $scene . '返回未知状态', 40200, [
            'pay_no' => $this->orderPayNo($order),
            'channel_status' => $status,
        ]);
    }

    /**
     * 构建渠道响应摘要。
     *
     * @param string $path 渠道接口路径
     * @param array<string, mixed> $data 渠道响应
     * @return array<string, mixed> 已剔除敏感字段的响应摘要
     */
    private function responseSummary(string $path, array $data): array
    {
        $fields = array_values(array_filter(array_map('strval', array_keys($data)), static function (string $field): bool {
            return !in_array($field, [
                'sign', 'signature', 'authorization', 'appId', 'mid', 'tid', 'billPayment', 'buyerId', 'buyerUsername',
            ], true);
        }));
        sort($fields);

        return array_filter([
            'path' => $path,
            'err_code' => trim((string) ($data['errCode'] ?? '')),
            'status' => $this->firstText($data['status'] ?? '', $data['billStatus'] ?? '', $data['refundStatus'] ?? ''),
            'order_no' => $this->firstText($data['merOrderId'] ?? '', $data['billNo'] ?? ''),
            'trade_no' => trim((string) ($data['targetOrderId'] ?? '')),
            'refund_order_id' => trim((string) ($data['refundOrderId'] ?? '')),
            'response_fields' => $fields,
        ], static fn (mixed $value): bool => $value !== '' && $value !== []);
    }

    /**
     * 归一化渠道支付时间。
     *
     * 14 位数字按 YmdHis 转换；其他非空格式保留原值，避免擅自猜测渠道时区或格式。
     *
     * @param string $value 渠道时间文本
     * @return string|null 归一化时间
     */
    private function normalizeTime(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^\d{14}$/', $value) === 1) {
            $time = DateTimeImmutable::createFromFormat('!YmdHis', $value);
            if ($time instanceof DateTimeImmutable) {
                return $time->format('Y-m-d H:i:s');
            }
        }

        return $value;
    }

    private function requestedPaymentMethod(array $order): string
    {
        $extra = (array) ($order['extra'] ?? []);
        $payment = $extra['payment'] ?? [];

        return is_array($payment) ? strtolower(trim((string) ($payment['method'] ?? ''))) : '';
    }

    private function ensureProduct(string $product): void
    {
        if (!in_array($product, $this->enabledProducts(), true)) {
            throw new PaymentProductUnavailableException('当前银联商务通道未开启该支付产品', 40200, [
                'channel_error_code' => 'PRODUCT_NOT_OPEN',
                'product' => $product,
            ]);
        }
    }

    /**
     * 获取已启用支付产品。
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

        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            $products
        ))));
    }

    private function configText(string $key): string
    {
        return trim((string) $this->getConfig($key, ''));
    }

    private function configBool(string $key, bool $default = false): bool
    {
        $value = $this->getConfig($key, $default);
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }

    private function requiredText(mixed $value, string $message): string
    {
        $text = trim((string) $value);
        if ($text === '') {
            throw new PaymentException($message, 40200);
        }

        return $text;
    }

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
