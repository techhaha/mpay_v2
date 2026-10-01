<?php

declare(strict_types=1);

namespace app\common\payment;

use app\common\base\BasePayment;
use app\common\constant\PaymentPluginStatusConstant;
use app\common\constant\PaymentPluginTypeConstant;
use app\common\interface\ChannelNotifyPayloadInterface;
use app\common\sdk\lakalajfy\LakalaJfyClient;
use app\exception\PaymentDefinitiveException;
use app\exception\PaymentException;
use app\exception\UnsupportedPaymentOperationException;
use app\model\payment\PayOrder;
use app\repository\payment\config\PaymentChannelRepository;
use app\repository\payment\config\PaymentTypeRepository;
use app\repository\payment\trade\PayOrderRepository;
use DateTimeImmutable;
use DateTimeZone;
use support\Request;
use support\Response;

/**
 * 适配缴费易动态二维码和拉卡拉工作台批量流水通知。
 *
 * 仅按已保存的 payOrderNo 定位订单，再校验金额、支付方式和付款时间；不分配识别
 * 金额、不生成备注码。订单推进和商户回调由核心服务负责，插件不自行修改支付状态。
 */
class LakalaJfyReceiptPayment extends BasePayment implements ChannelNotifyPayloadInterface
{
    /** @var array<string, mixed> */
    protected array $paymentInfo = [
        'code' => 'lakala_jfy_receipt',
        'name' => '拉卡拉缴费易动态码收款',
        'plugin_type' => PaymentPluginTypeConstant::TYPE_BACKEND,
        'author' => 'MPAY',
        'version' => '1.0.0',
        'pay_types' => ['alipay', 'wxpay', 'unionpay'],
        'transfer_types' => [],
        'receipt_watcher' => ['runtime' => 'direct', 'prelogin_supported' => true],
    ];

    private PayOrderRepository $orders;
    private PaymentChannelRepository $channels;
    private PaymentTypeRepository $types;
    private LakalaJfyClient $client;

    public function __construct(
        ?PayOrderRepository $orders = null,
        ?PaymentChannelRepository $channels = null,
        ?PaymentTypeRepository $types = null,
        ?LakalaJfyClient $client = null
    ) {
        $this->orders = $orders ?? new PayOrderRepository();
        $this->channels = $channels ?? new PaymentChannelRepository();
        $this->types = $types ?? new PaymentTypeRepository();
        $this->client = $client ?? new LakalaJfyClient();
    }

    /**
     * 声明缴费易下单、商户工作台监听及代理的业务配置。
     *
     * 首版固定动态码，使用内置通用金额模板；表单不包含静态码、模板上传、
     * 金额偏移或备注码匹配选项。
     *
     * @return list<array<string, mixed>> form-create 配置规则
     */
    public function getConfigSchema(): array
    {
        return [
            [
                'type' => 'input',
                'field' => 'jfy_merch_id',
                'title' => '缴费易商户 ID',
                'value' => '',
                'props' => [
                    'placeholder' => '请输入缴费易收款链接中的 pid 参数',
                ],
                'validate' => [
                    ['required' => true, 'message' => '缴费易商户 ID 不能为空'],
                ],
            ],
            [
                'type' => 'input',
                'field' => 'jfy_cashier_name',
                'title' => '缴费易收银台名称',
                'value' => '',
                'props' => [
                    'placeholder' => '例如：通用模板',
                ],
                'validate' => [
                    ['required' => true, 'message' => '收银台名称不能为空'],
                ],
            ],
            [
                'type' => 'input',
                'field' => 'watcher_username',
                'title' => '拉卡拉工作台账号',
                'value' => '',
                'props' => [
                    'placeholder' => '请输入拉卡拉商户工作台登录账号',
                ],
                'validate' => [
                    ['required' => true, 'message' => '工作台账号不能为空'],
                ],
            ],
            [
                'type' => 'password',
                'field' => 'watcher_password',
                'title' => '拉卡拉工作台密码',
                'value' => '',
                'props' => [
                    'placeholder' => '请输入拉卡拉商户工作台登录密码',
                ],
                'validate' => [
                    ['required' => true, 'message' => '工作台密码不能为空'],
                ],
            ],
            [
                'type' => 'input',
                'field' => 'receipt_account_no',
                'title' => '拉卡拉商户号',
                'value' => '',
                'props' => [
                    'placeholder' => '请输入拉卡拉工作台商户号',
                ],
                'validate' => [
                    ['required' => true, 'message' => '拉卡拉商户号不能为空'],
                ],
            ],
            [
                'type' => 'input',
                'field' => 'receipt_merchant_name',
                'title' => '工作台商户名',
                'value' => '',
                'props' => [
                    'placeholder' => '请输入工作台显示的完整商户名称',
                ],
                'validate' => [
                    ['required' => true, 'message' => '工作台商户名不能为空'],
                ],
            ],
            [
                'type' => 'input',
                'field' => 'receipt_terminal_no',
                'title' => '收款终端号',
                'value' => '',
                'props' => [
                    'placeholder' => '可选，不限制收款终端时留空',
                ],
            ],
            [
                'type' => 'inputNumber',
                'field' => 'receipt_watcher_query_interval_seconds',
                'title' => '查询间隔（秒）',
                'value' => 3,
                'props' => [
                    'min' => 3,
                    'max' => 300,
                    'step' => 1,
                    'placeholder' => '3 至 300 秒',
                ],
            ],
            [
                'type' => 'switch',
                'field' => 'proxy_enabled',
                'title' => '启用 IP 代理',
                'value' => false,
                'props' => [
                    'checkedText' => '开启',
                    'uncheckedText' => '关闭',
                ],
                'control' => [
                    [
                        'rule' => ['receipt_proxy_url'],
                        'value' => true,
                        'method' => 'display',
                    ],
                ],
            ],
            [
                'type' => 'password',
                'field' => 'receipt_proxy_url',
                'title' => 'IP 代理地址',
                'value' => '',
                'props' => [
                    'placeholder' => '例如：http://用户名:密码@主机:端口',
                ],
            ],
        ];
    }

    /**
     * 请求缴费易生成带原始金额的动态收款码。
     *
     * 本地 pay_no 完整写入渠道备注，返回 pending 与确定的 payOrderNo，由派发层保存
     * 渠道订单号及承接参数；备注只用于关联信息留存，不参与当前订单定位。
     *
     * @param array<string, mixed> $order 标准支付参数，amount 使用整数分
     * @return array<string, mixed> 标准待支付结果
     * @throws PaymentDefinitiveException 参数或监听配置无效时抛出
     * @throws \app\exception\PaymentUncertainException 上游下单结果无法确认时抛出
     */
    public function pay(array $order): array
    {
        $payType = (string) ($order['pay_type_code'] ?? '');
        if (!in_array($payType, $this->getEnabledPayTypes(), true)
            || !isset($order['amount']) || !is_int($order['amount']) || $order['amount'] <= 0
        ) {
            throw new PaymentDefinitiveException('缴费易下单金额或支付方式无效', 40200);
        }
        foreach (['watcher_username', 'watcher_password', 'receipt_account_no', 'receipt_merchant_name'] as $key) {
            if (trim((string) $this->getConfig($key, '')) === '') {
                throw new PaymentDefinitiveException('缴费易工作台监听配置不完整', 40200);
            }
        }
        $payNo = $order['pay_no'] ?? null;
        if (!is_string($payNo)) {
            throw new PaymentDefinitiveException('缴费易下单本地支付单号无效', 40200);
        }
        $data = $this->client->createQrcode($this->channelConfig, $order['amount'], $payNo);

        return $this->pendingPaymentResult($order, [
            'pay_type' => $payType,
            'pay_product' => 'dynamic_qrcode',
            'pay_action' => 'cashier_pay',
            'chan_order_no' => $data['pay_order_no'],
            'chan_trade_no' => '',
            'channel_context' => ['pay_order_no' => $data['pay_order_no']],
            'pay_page' => 'qrcode',
            'pay_params' => ['qrcode' => $data['pay_url']],
        ]);
    }

    /**
     * 保持待支付状态，由 Go watcher 批量流水通知提供成功事实。
     *
     * 本入口不访问单笔查单接口，避免收银台轮询增加平台请求压力。
     *
     * @param array<string, mixed> $order 标准查单参数
     * @return array<string, mixed> 标准待支付状态
     */
    public function query(array $order): array
    {
        return [
            'status' => PaymentPluginStatusConstant::PENDING,
            'pay_no' => (string) ($order['pay_no'] ?? ''),
            'paid_amount' => null,
            'chan_order_no' => (string) ($order['chan_order_no'] ?? ''),
            'chan_trade_no' => (string) ($order['chan_trade_no'] ?? ''),
            'message' => '等待缴费易工作台批量流水通知',
        ];
    }

    /** @throws UnsupportedPaymentOperationException 当前适配协议不提供关单能力 */
    public function close(array $order): array
    {
        throw new UnsupportedPaymentOperationException('缴费易动态码插件不支持渠道关单', 40200);
    }

    /** @throws UnsupportedPaymentOperationException 当前适配协议不提供退款能力 */
    public function refund(array $order): array
    {
        throw new UnsupportedPaymentOperationException('缴费易动态码插件不支持渠道退款', 40200);
    }

    /** @throws UnsupportedPaymentOperationException 成功通知只接受现有流水队列数组载荷 */
    public function notify(Request $request): array
    {
        throw new UnsupportedPaymentOperationException('缴费易动态码插件不接受 HTTP 支付通知', 40200);
    }

    /**
     * 按当前插件配置中的平台订单号精确定位支付单。
     *
     * 不采信载荷中提供的本地 pay_no，不用金额或备注寻找其他候选订单。
     *
     * @param array<string, mixed> $payload 现有队列信封，包含 record 标准流水
     * @return array{pay_no: string} 当前配置归属的支付单号
     * @throws PaymentException 归属不正确或平台订单号未匹配时抛出
     */
    public function channelNotifyPayload(array $payload): array
    {
        return ['pay_no' => (string) $this->matchingOrder($payload)->pay_no];
    }

    /**
     * 校验精确订单的金额、实际支付方式和付款时间后返回成功事实。
     *
     * 仅映射已验证的真实渠道流水号；没有该字段时留空，不把订单号冒充交易流水号。
     * 本方法不更新数据库订单状态，也不补查平台详情。
     *
     * @param array<string, mixed> $payload watcher 已归一化的成功流水队列信封
     * @return array<string, mixed> 标准支付成功通知，paid_amount 使用整数分
     * @throws PaymentException 金额、支付方式、终端或时间校验失败时抛出
     */
    public function notifyPayload(array $payload): array
    {
        $order = $this->matchingOrder($payload);
        $record = $payload['record'];
        $amount = $this->moneyToCents($record['price'] ?? null);
        $type = (string) ($record['pay_type'] ?? '');
        if (!in_array($type, ['alipay', 'wxpay', 'unionpay'], true)) {
            throw new PaymentException('缴费易流水支付方式无效', 40200);
        }
        $dictionary = $this->types->findByCode($type);
        if (!$dictionary || (int) $dictionary->id !== (int) $order->pay_type_id || $amount !== (int) $order->pay_amount) {
            throw new PaymentException('缴费易流水金额或支付方式与订单不一致', 40200);
        }
        $paidAt = $this->timestamp((string) ($record['paid_at'] ?? ''));
        $start = $this->timestamp((string) ($order->request_at ?: $order->created_at));
        $end = $this->timestamp((string) $order->expire_at);
        if ($end < $start || $paidAt < $start || $paidAt > $end) {
            throw new PaymentException('缴费易流水付款时间不在订单有效期内', 40200);
        }
        $terminal = trim((string) $this->getConfig('receipt_terminal_no', ''));
        if ($terminal !== '' && (string) ($record['channel'] ?? '') !== $terminal) {
            throw new PaymentException('缴费易流水终端与配置不一致', 40200);
        }
        $tradeNo = $record['chan_trade_no'] ?? '';
        if (!is_string($tradeNo) || strlen($tradeNo) > 64) {
            throw new PaymentException('缴费易渠道流水号格式无效', 40200);
        }

        return [
            'status' => PaymentPluginStatusConstant::SUCCESS,
            'pay_no' => (string) $order->pay_no,
            'paid_amount' => $amount,
            'chan_order_no' => (string) $record['order_no'],
            'chan_trade_no' => $tradeNo,
            'channel_status' => 'receipt_watcher_received',
            'paid_at' => (string) $record['paid_at'],
            'message' => '缴费易平台订单号及付款信息校验成功',
        ];
    }

    public function notifySuccess(): string|Response
    {
        return 'success';
    }

    public function notifyFail(): string|Response
    {
        return 'fail';
    }

    /**
     * 在同插件、同配置所属通道内查找并核对缴费易订单号。
     *
     * 仓库允许查询订单号或交易号，本插件额外要求精确命中 channel_order_no。
     *
     * @param array<string, mixed> $payload 标准流水队列信封
     * @throws PaymentException 信封归属、配置或订单号不一致时抛出
     */
    private function matchingOrder(array $payload): PayOrder
    {
        $configId = (int) $this->getConfig('api_config_id', 0);
        if (($payload['plugin_code'] ?? '') !== $this->getCode()
            || (int) ($payload['api_config_id'] ?? 0) !== $configId || $configId <= 0
            || !is_array($payload['record'] ?? null)
        ) {
            throw new PaymentException('缴费易流水信封归属或结构无效', 40200);
        }
        $no = $payload['record']['order_no'] ?? null;
        if (!is_string($no) || trim($no) === '') {
            throw new PaymentException('缴费易流水缺少平台订单号', 40200);
        }
        $ids = $this->channels->idsByPluginConfig($this->getCode(), $configId);
        $order = $this->orders->findByReceiptChannelOrder($ids, $no);
        if (!$order || (string) $order->plugin_code !== $this->getCode()
            || !in_array((int) $order->channel_id, $ids, true)
            || (string) $order->channel_order_no !== $no
        ) {
            throw new PaymentException('缴费易流水未精确匹配当前配置的支付单', 40200);
        }
        return $order;
    }

    /**
     * 将流水元金额转成整数分，拒绝多余小数、负值和整数溢出。
     *
     * @throws PaymentException 流水金额不是精确有效的正数时抛出
     */
    private function moneyToCents(mixed $value): int
    {
        if (!is_string($value) || preg_match('/^(0|[1-9]\d*)(?:\.(\d{1,2}))?$/', $value, $parts) !== 1
            || strlen($parts[1]) > strlen((string) intdiv(PHP_INT_MAX, 100))
            || (int) $parts[1] > intdiv(PHP_INT_MAX - 99, 100)
        ) {
            throw new PaymentException('缴费易流水金额格式无效', 40200);
        }
        $amount = (int) $parts[1] * 100 + (int) str_pad($parts[2] ?? '', 2, '0');
        if ($amount <= 0) {
            throw new PaymentException('缴费易流水金额必须大于零', 40200);
        }
        return $amount;
    }

    /**
     * 严格解析标准流水及订单时间，统一使用上海时区。
     *
     * @throws PaymentException 缺少时间或时间格式无效时抛出
     */
    private function timestamp(string $value): int
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone('Asia/Shanghai'));
        if (!$date || $date->format('Y-m-d H:i:s') !== $value) {
            throw new PaymentException('缴费易订单或流水时间无效', 40200);
        }
        return $date->getTimestamp();
    }
}
