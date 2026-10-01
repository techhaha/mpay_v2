<?php

declare(strict_types=1);

namespace app\common\payment;

use app\common\base\BasePayment;
use app\common\constant\PaymentPluginStatusConstant;
use app\common\constant\PaymentPluginTypeConstant;
use app\common\constant\TradeConstant;
use app\common\interface\ChannelNotifyPayloadInterface;
use app\common\interface\PaymentInterface;
use app\common\interface\PayPluginInterface;
use app\common\util\FormatHelper;
use app\exception\PaymentDefinitiveException;
use app\exception\PaymentException;
use app\exception\PaymentUncertainException;
use app\exception\UnsupportedPaymentOperationException;
use app\model\payment\PayOrder;
use app\repository\payment\config\PaymentChannelRepository;
use app\repository\payment\config\PaymentTypeRepository;
use app\repository\payment\trade\PayOrderRepository;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use support\Db;
use support\Redis;
use support\Request;
use support\Response;
use Throwable;

/**
 * 付呗后台固定金额、限制一笔的收款单插件。
 *
 * 使用 Go 已验证会话调用签名网关建单，以收款单标题中的完整 pay_no 定位流水。
 * receiptId 用于支付链接和收款单来源校验；订单状态及商户通知交给支付核心。
 */
class FubeiCashierReceiptPayment extends BasePayment implements PaymentInterface, PayPluginInterface, ChannelNotifyPayloadInterface
{
    private const GATEWAY = 'https://b.51fubei.com/api/gateway';
    private const ACTION = 'receipt.web.receipt.add';
    private const CASH_URL = 'https://receipt.51fubei.com/receipt/new?receiptId=';
    private const JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    /** @var array<string, mixed> */
    protected array $paymentInfo = [
        'code' => 'fubei_cashier_receipt',
        'name' => '付呗收款单收款',
        'plugin_type' => PaymentPluginTypeConstant::TYPE_BACKEND,
        'author' => 'MPAY',
        'link' => 'https://b.51fubei.com/',
        'version' => '1.0.0',
        'pay_types' => ['alipay', 'wxpay', 'unionpay'],
        'transfer_types' => [],
        'receipt_watcher' => ['runtime' => 'direct', 'prelogin_supported' => true],
    ];

    private ?string $lockKey = null;
    private ?string $lockToken = null;

    public function __construct(
        private readonly PayOrderRepository $payOrderRepository,
        private readonly PaymentChannelRepository $paymentChannelRepository,
        private readonly PaymentTypeRepository $paymentTypeRepository
    ) {
    }

    /** @return list<array<string, mixed>> 后台账号及目标门店配置，UID 由登录自动取得 */
    public function getConfigSchema(): array
    {
        return [
            [
                'type' => 'input',
                'field' => 'watcher_username',
                'title' => '后台登录账号',
                'value' => '',
                'props' => ['placeholder' => '请输入付呗商户后台登录账号'],
                'validate' => [['required' => true, 'message' => '后台登录账号不能为空']],
            ],
            [
                'type' => 'password',
                'field' => 'watcher_password',
                'title' => '后台登录密码',
                'value' => '',
                'props' => ['placeholder' => '请输入付呗商户后台登录密码'],
                'validate' => [['required' => true, 'message' => '后台登录密码不能为空']],
            ],
            [
                'type' => 'input',
                'field' => 'receipt_account_no',
                'title' => '收款门店 ID',
                'value' => '',
                'props' => ['placeholder' => '请输入门店 ID，只有一个门店时可留空'],
            ],
            [
                'type' => 'input',
                'field' => 'receipt_merchant_name',
                'title' => '收款门店名称',
                'value' => '',
                'props' => ['placeholder' => '请输入精确门店名称，只有一个门店时可留空'],
            ],
            [
                'type' => 'inputNumber',
                'field' => 'receipt_valid_seconds',
                'title' => '收款有效期(秒)',
                'value' => 300,
                'props' => [
                    'placeholder' => '请输入60至1800秒，仅控制本地订单有效期',
                    'min' => 60,
                    'max' => 1800,
                    'step' => 1,
                    'precision' => 0,
                ],
            ],
            [
                'type' => 'inputNumber',
                'field' => 'receipt_watcher_query_interval_seconds',
                'title' => '账号查询间隔(秒)',
                'value' => 5,
                'props' => ['placeholder' => '请输入2至60秒', 'min' => 2, 'max' => 60, 'step' => 1],
            ],
            [
                'type' => 'switch',
                'field' => 'proxy_enabled',
                'title' => '启用 IP 代理',
                'value' => false,
                'control' => [['rule' => ['receipt_proxy_url'], 'value' => true, 'method' => 'display']],
            ],
            [
                'type' => 'password',
                'field' => 'receipt_proxy_url',
                'title' => 'IP 代理地址',
                'value' => '',
                'props' => ['placeholder' => '请输入http://用户名:密码@主机:端口'],
            ],
        ];
    }

    /**
     * 一次网关请求创建收款单，取得 receiptId 后本地拼接二维码。
     *
     * 创建意向及本地有效期先落库；不确定请求不得重复发送。
     * 配置只收窄本地订单窗口，不向付呗发送有效期或关闭请求。
     *
     * @param array<string, mixed> $order 标准订单，amount 为整数分
     * @return array<string, mixed> 标准 pending 结果
     */
    public function pay(array $order): array
    {
        $payNo = (string) ($order['pay_no'] ?? '');
        $amount = $order['amount'] ?? null;
        $payType = (string) ($order['pay_type_code'] ?? '');
        if (preg_match('/^PAY[0-9]{20}$/D', $payNo) !== 1 || !is_int($amount) || $amount <= 0
            || !in_array($payType, $this->getEnabledPayTypes(), true)) {
            throw new PaymentDefinitiveException('付呗收款单支付单号、金额或支付方式无效', 40200);
        }
        $session = $this->validatedSession();
        return $this->withPayLock($payNo, function () use ($order, $payNo, $amount, $payType, $session): array {
            $meta = $this->prepareCreation($payNo, $amount, $payType, $session);
            if ($meta['phase'] === 'creating') {
                try {
                    $meta['receipt_id'] = $this->createReceipt($payNo, $amount, $session);
                } catch (PaymentDefinitiveException $e) {
                    $meta['phase'] = 'rejected';
                    $this->persistResult($payNo, $meta);
                    throw $e;
                } catch (Throwable) {
                    throw $this->uncertain('付呗收款单创建结果不确定，禁止重复创建', $meta);
                }
                $meta['phase'] = 'ready';
                $this->persistResult($payNo, $meta);
            }
            return $this->pendingPaymentResult($order, [
                'pay_type' => $payType,
                'pay_product' => 'scan',
                'pay_action' => self::ACTION,
                'channel_context' => $meta,
                'pay_page' => 'page',
                'pay_params' => [
                    '_page' => 'receiptQrcode',
                    'qrcode' => self::CASH_URL . rawurlencode($meta['receipt_id']),
                    'amount' => FormatHelper::amount($amount),
                    'original_amount' => FormatHelper::amount($amount),
                    'receipt_match_mode' => 'dynamic_qrcode',
                    'expire_at_timestamp' => $this->timeValue($meta['expire_at']),
                    'server_time_timestamp' => $this->now(),
                    'description' => '请扫码完成付款。',
                ],
            ]);
        });
    }

    /** @param array<string, mixed> $order 标准查单参数，仅等待汇总流水 */
    public function query(array $order): array
    {
        return [
            'status' => PaymentPluginStatusConstant::PENDING,
            'pay_no' => (string) ($order['pay_no'] ?? ''),
            'paid_amount' => null,
            'chan_order_no' => (string) ($order['chan_order_no'] ?? ''),
            'chan_trade_no' => (string) ($order['chan_trade_no'] ?? ''),
            'message' => '等待付呗收款单流水监听',
        ];
    }

    public function close(array $order): array
    {
        throw new UnsupportedPaymentOperationException('付呗收款单暂不支持上游关单', 40200);
    }

    public function refund(array $order): array
    {
        throw new UnsupportedPaymentOperationException('付呗收款单暂不支持接口退款', 40200);
    }

    public function notify(Request $request): array
    {
        return $this->notifyPayload((array) $request->all());
    }

    /** @param array<string, mixed> $payload 六字段流水；remark 为标题而非用户备注 */
    public function channelNotifyPayload(array $payload): array
    {
        [$order] = $this->matchedFlow($payload);
        return ['pay_no' => (string) $order->pay_no];
    }

    /** @param array<string, mixed> $payload 已经进入核心通知链路的 watcher 消息 */
    public function notifyPayload(array $payload): array
    {
        [$order, $record, $amount] = $this->matchedFlow($payload);
        return [
            'status' => PaymentPluginStatusConstant::SUCCESS,
            'pay_no' => (string) $order->pay_no,
            'paid_amount' => $amount,
            'chan_order_no' => $record['order_no'],
            'chan_trade_no' => $record['order_no'],
            'paid_at' => $record['paid_at'],
            'channel_status' => '1',
            'message' => '付呗收款单流水精确匹配',
        ];
    }

    public function notifySuccess(): string|Response { return 'success'; }
    public function notifyFail(): string|Response { return 'fail'; }

    /**
     * 仅使用标题定位订单，再核对配置、金额、付款时间和已保存创建事实。
     *
     * @param array<string, mixed> $payload 标准队列消息
     * @return array{0:PayOrder,1:array<string,string>,2:int}
     * @throws PaymentException 不匹配、重复绑定或不可确认时抛出
     */
    private function matchedFlow(array $payload): array
    {
        if (($payload['plugin_code'] ?? '') !== $this->getCode()
            || (int) ($payload['api_config_id'] ?? 0) !== (int) $this->getConfig('api_config_id')) {
            throw new PaymentException('付呗收款单流水配置不匹配', 40200);
        }
        $record = $payload['record'] ?? null;
        if (!is_array($record)) {
            throw new PaymentException('付呗收款单缺少标准流水', 40200);
        }
        foreach (['order_no', 'pay_type', 'price', 'channel', 'remark', 'paid_at'] as $field) {
            if (!is_string($record[$field] ?? null)) {
                throw new PaymentException('付呗收款单流水字段类型无效', 40200);
            }
        }
        $payNo = $record['remark'];
        if (preg_match('/^PAY[0-9]{20}$/D', $payNo) !== 1 || !$this->validId($record['order_no'])
            || !$this->validId($record['channel']) || !in_array($record['pay_type'], $this->getEnabledPayTypes(), true)) {
            throw new PaymentException('付呗收款单标题、交易号或支付方式无效', 40200);
        }
        $channels = $this->paymentChannelRepository->idsByPluginConfig($this->getCode(), (int) $this->getConfig('api_config_id'));
        $order = $this->payOrderRepository->findByPayNo($payNo);
        $type = $this->paymentTypeRepository->findByCode($record['pay_type'], ['id']);
        if (!$order || !$type || (string) $order->plugin_code !== $this->getCode()
            || !in_array((int) $order->channel_id, $channels, true) || (int) $order->pay_type_id !== (int) $type->id) {
            throw new PaymentException('付呗收款单流水未匹配当前配置的支付单', 40200);
        }
        $amount = $this->yuanToCents($record['price']);
        $paidAt = $this->timeValue($record['paid_at']);
        $start = $this->timeValue($order->request_at ?? $order->created_at);
        $end = $this->timeValue($order->expire_at);
        $ext = (array) ($order->ext_json ?? []);
        $context = (array) ($ext['payment_context'] ?? []);
        $receipt = (array) ($context['channel_context'] ?? []);
        $meta = (array) ($ext['receipt_watcher'] ?? []);
        if ($amount !== (int) $order->pay_amount || $start <= 0 || $end <= $start || $paidAt < $start || $paidAt >= $end
            || ($context['pay_product'] ?? '') !== 'scan' || ($context['pay_action'] ?? '') !== self::ACTION
            || ($meta['plugin_code'] ?? '') !== $this->getCode() || ($meta['phase'] ?? '') !== 'ready'
            || ($meta['title'] ?? '') !== $payNo || ($meta['pay_no'] ?? '') !== $payNo
            || ($meta['pay_type'] ?? '') !== $record['pay_type']
            || (int) ($meta['api_config_id'] ?? 0) !== (int) $this->getConfig('api_config_id')
            || ($meta['amount'] ?? null) !== $amount || !$this->validId($meta['uid'] ?? null)
            || !$this->validId($meta['store_id'] ?? null) || ($meta['receipt_id'] ?? '') !== $record['channel']
            || $this->timeValue($meta['expire_at'] ?? null) !== $end) {
            throw new PaymentException('付呗收款单流水与创建快照不一致', 40200);
        }
        foreach (['identity', 'receipt_id', 'title', 'amount', 'uid', 'store_id', 'expire_at', 'api_config_id'] as $field) {
            if (!array_key_exists($field, $receipt) || $receipt[$field] !== $meta[$field]) {
                throw new PaymentException('付呗收款单支付上下文不一致', 40200);
            }
        }
        $bound = $this->payOrderRepository->findByReceiptChannelOrder($channels, $record['order_no'], ['pay_no']);
        if ($bound && (string) $bound->pay_no !== $payNo) {
            throw new PaymentException('付呗收款单流水已绑定其他支付单', 40200);
        }
        foreach (['channel_order_no', 'channel_trade_no'] as $field) {
            $existing = (string) ($order->{$field} ?? '');
            if ($existing !== '' && $existing !== $record['order_no']) {
                throw new PaymentException('付呗收款单已绑定不同流水', 40200);
            }
        }
        if ((int) $order->status === TradeConstant::ORDER_STATUS_SUCCESS) {
            if ((string) $order->channel_order_no !== $record['order_no'] || (string) $order->channel_trade_no !== $record['order_no']) {
                throw new PaymentException('付呗收款单重复流水与成功事实不符', 40200);
            }
        } elseif (!in_array((int) $order->status, TradeConstant::orderMutableStatuses(), true)) {
            throw new PaymentException('付呗收款单状态不可确认', 40200);
        }
        return [$order, $record, $amount];
    }

    /**
     * 读取并校验统一共享会话，不把 Redis 滑动 TTL 当成平台令牌有效期。
     *
     * @return array<string, mixed> 当前账号、代理和门店对应的已验证会话
     */
    protected function validatedSession(): array
    {
        $payload = $this->loadEnvelope();
        $shared = $payload['shared_data'] ?? null;
        $private = $payload['session_data'] ?? null;
        $enabled = $this->getConfig('proxy_enabled', false);
        if (!is_bool($enabled)) {
            throw new PaymentDefinitiveException('付呗代理开关必须为布尔值', 40200);
        }
        $fingerprint = $this->fingerprint();
        if (($payload['schema_version'] ?? null) !== 1 || ($payload['plugin_code'] ?? '') !== $this->getCode()
            || ($payload['account_key'] ?? '') !== $this->accountKey() || ($payload['runtime'] ?? '') !== 'direct'
            || !is_string($payload['config_hash'] ?? null) || $payload['config_hash'] === ''
            || (int) ($payload['expires_at'] ?? 0) <= $this->now() || !is_array($shared) || !is_array($private)
            || ($shared['config_fingerprint'] ?? '') !== $fingerprint
            || ($shared['proxy_enabled'] ?? null) !== $enabled
            || (int) ($shared['api_config_id'] ?? 0) !== (int) $this->getConfig('api_config_id')
            || (int) ($shared['last_probe_at'] ?? 0) <= 0 || (int) $shared['last_probe_at'] > $this->now()
            || (int) ($shared['verified_until'] ?? 0) <= $this->now()
            || ((int) ($shared['token_expires_at'] ?? 0) > 0 && (int) $shared['token_expires_at'] <= $this->now())
            || !$this->validId($shared['uid'] ?? null) || !$this->validId($shared['store_id'] ?? null)) {
            throw new PaymentDefinitiveException('付呗收款单缺少当前配置的有效登录态，请等待监听工具探测', 40200);
        }
        foreach (['access_token', 'jsid', 'uid', 'store_id', 'store_name', 'credential_hash', 'config_fingerprint'] as $key) {
            if (!is_string($shared[$key] ?? null) || $shared[$key] === ''
                || ($private[$key] ?? null) !== $shared[$key] || strpbrk($shared[$key], "\r\n") !== false) {
                throw new PaymentDefinitiveException('付呗收款单共享会话不完整或正在刷新', 40200);
            }
        }
        if (str_contains($shared['jsid'], ';')) {
            throw new PaymentDefinitiveException('付呗收款单 Cookie 格式无效', 40200);
        }
        if (filter_var($shared['store_id'], FILTER_VALIDATE_INT) === false) {
            throw new PaymentDefinitiveException('付呗收款单门店 ID 超出支持范围', 40200);
        }
        return $shared;
    }

    /** @return array<string, mixed> 原始 Session 信封，仅在服务端消费 */
    protected function loadEnvelope(): array
    {
        try {
            $raw = Redis::get('receipt_watcher_session_' . $this->accountKey());
            $data = is_string($raw) ? json_decode($raw, true, 512, JSON_THROW_ON_ERROR) : [];
            return is_array($data) ? $data : [];
        } catch (Throwable) {
            throw new PaymentDefinitiveException('付呗收款单会话读取失败', 40200);
        }
    }

    /**
     * 持久化首次创建意向，重入只复用确定成功的 receiptId。
     * 首次创建取原订单截止时间和配置截止时间的较早值，重入不重新计时。
     *
     * @param array<string, mixed> $session 已验证共享会话
     * @return array<string, mixed> 不含令牌的创建快照
     */
    private function prepareCreation(string $payNo, int $amount, string $payType, array $session): array
    {
        $validSeconds = $this->validSeconds();
        $this->assertLease();
        return $this->withLockedOrder($payNo, function (PayOrder $order) use ($payNo, $amount, $payType, $session, $validSeconds): array {
            if ((string) $order->plugin_code !== $this->getCode()
                || (int) $order->channel_id !== (int) $this->getConfig('channel_id')
                || (int) $order->pay_amount !== $amount
                || !in_array((int) $order->status, TradeConstant::orderMutableStatuses(), true)
                || $this->timeValue($order->expire_at) <= $this->now()) {
                throw new PaymentDefinitiveException('付呗收款单不属于当前通道或已结束', 40200);
            }
            $ext = (array) ($order->ext_json ?? []);
            $existing = (array) ($ext['receipt_watcher'] ?? []);
            $receipt = (array) ($ext['payment_context']['channel_context'] ?? []);
            $identity = hash('sha256', json_encode([$payNo, $amount, $payType, $this->accountKey(),
                $session['config_fingerprint'], $session['uid'], $session['store_id']], self::JSON_FLAGS));
            if ($existing !== []) {
                if (($existing['identity'] ?? '') !== $identity || ($existing['title'] ?? '') !== $payNo
                    || ($existing['amount'] ?? null) !== $amount || ($existing['plugin_code'] ?? '') !== $this->getCode()) {
                    throw $this->uncertain('付呗收款单已有不同创建标识，禁止覆盖', $existing);
                }
                if (($existing['phase'] ?? '') === 'rejected') {
                    throw new PaymentDefinitiveException('付呗收款单创建已被明确拒绝', 40200);
                }
                // 核心可能已归档带已知 ID 的保存失败异常；只恢复完整一致的成功事实。
                if (($existing['phase'] ?? '') === 'creating' && ($receipt['phase'] ?? '') === 'ready'
                    && $this->validId($receipt['receipt_id'] ?? null)) {
                    $restored = array_replace($existing, ['phase' => 'ready', 'receipt_id' => $receipt['receipt_id']]);
                    $same = count($restored) === count($receipt);
                    foreach ($restored as $field => $value) {
                        $same = $same && array_key_exists($field, $receipt) && $receipt[$field] === $value;
                    }
                    if ($same) {
                        $existing = $restored;
                        $ext['receipt_watcher'] = $existing;
                        $order->ext_json = $ext;
                        $this->assertLease();
                        $order->save();
                    }
                }
                if (($existing['phase'] ?? '') !== 'ready' || !$this->validId($existing['receipt_id'] ?? null)) {
                    throw $this->uncertain('付呗收款单创建结果不确定，禁止重复创建', $existing);
                }
                return $existing;
            }
            foreach (['receipt_id', 'title', 'identity', 'phase'] as $field) {
                if (!empty($receipt[$field])) {
                    throw $this->uncertain('付呗收款单已有渠道上下文但创建记录缺失', $receipt);
                }
            }
            $end = min($this->timeValue($order->expire_at), $this->now() + $validSeconds);
            $expireAt = (new DateTimeImmutable('@' . $end))
                ->setTimezone(new DateTimeZone('Asia/Shanghai'))->format('Y-m-d H:i:s');
            $meta = [
                'plugin_code' => $this->getCode(), 'api_config_id' => (int) $this->getConfig('api_config_id'),
                'pay_no' => $payNo, 'title' => $payNo, 'amount' => $amount, 'pay_type' => $payType,
                'receipt_id' => null, 'phase' => 'creating', 'uid' => $session['uid'],
                'store_id' => $session['store_id'], 'identity' => $identity,
                'expire_at' => $expireAt,
            ];
            $ext['receipt_watcher'] = $meta;
            $order->expire_at = $expireAt;
            $order->ext_json = $ext;
            $this->assertLease();
            $order->save();
            return $meta;
        });
    }

    /** @param array<string, mixed> $meta 已返回的页面 ID 或明确拒绝事实 */
    private function persistResult(string $payNo, array $meta): void
    {
        try {
            $this->assertLease();
            $this->withLockedOrder($payNo, function (PayOrder $order) use ($meta): array {
                $ext = (array) ($order->ext_json ?? []);
                $existing = (array) ($ext['receipt_watcher'] ?? []);
                if (($existing['identity'] ?? '') !== $meta['identity'] || ($existing['phase'] ?? '') !== 'creating') {
                    throw $this->uncertain('付呗收款单保存发生冲突', $meta);
                }
                $ext['receipt_watcher'] = $meta;
                $order->ext_json = $ext;
                $this->assertLease();
                $order->save();
                return $meta;
            });
        } catch (Throwable) {
            throw $this->uncertain('付呗收款单结果保存失败，禁止重复创建', $meta);
        }
    }

    /**
     * 在事务中取得支付单行锁，统一承载创建快照的读写。
     *
     * @param callable(PayOrder):array<string,mixed> $callback 持锁执行的本地操作，不包含平台外呼
     * @return array<string, mixed>
     */
    protected function withLockedOrder(string $payNo, callable $callback): array
    {
        return Db::transaction(function () use ($payNo, $callback): array {
            $order = $this->payOrderRepository->findForUpdateByPayNo($payNo);
            if (!$order) {
                throw new PaymentDefinitiveException('付呗收款单对应支付单不存在', 40200);
            }
            return $callback($order);
        });
    }

    /**
     * 按 HAR 创建固定金额收款单；金额以精确十进制 JSON 数字发送。
     *
     * @param array<string, mixed> $session 已验证会话
     * @throws PaymentUncertainException 网络或响应不确定时禁止重发
     */
    private function createReceipt(string $payNo, int $amount, array $session): string
    {
        $payload = [
            'mobileCheck' => 0, 'needUerPay' => 1, 'storeId' => (int) $session['store_id'],
            'reuseCustomerFormValue' => 0, 'receiptTitle' => $payNo,
            'receiptExplain' => '', 'receiptRichText' => '', 'isLimit' => 1, 'limitCount' => 1,
            'receiptType' => 1, 'receiptItem' => [], 'receiptCreateType' => 2,
            'receiptForm' => [], 'templateCreateType' => 0,
        ];
        // 仅金额在 JSON 边界写入十进制数字，避免先转 float 造成分位精度丢失。
        $content = substr(json_encode($payload, self::JSON_FLAGS), 0, -1)
            . ',"receiptMoney":' . intdiv($amount, 100) . '.' . str_pad((string) ($amount % 100), 2, '0', STR_PAD_LEFT) . '}';
        $signParams = ['appid' => 'CASHIER-DIGITAL', 'content' => $content, 'method' => self::ACTION,
            'salt' => 'fXchxBDq', 'version' => '1.0.0'];
        $sign = md5(str_replace('\\', '', json_encode($signParams, self::JSON_FLAGS)));
        unset($signParams['salt']);
        $signParams['sign'] = $sign;
        $this->assertLease();
        try {
            $response = $this->request('POST', self::GATEWAY, [
                'headers' => [
                    'access-token' => $session['access_token'], 'Cookie' => 'JSID=' . $session['jsid'],
                    'Origin' => 'https://b.51fubei.com', 'Referer' => 'https://b.51fubei.com/',
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome Safari/537.36',
                    'requestid' => bin2hex(random_bytes(16)), 'traceid' => bin2hex(random_bytes(16)),
                    'Accept' => 'application/json', 'Content-Type' => 'application/x-www-form-urlencoded;charset=UTF-8',
                ],
                'body' => http_build_query($signParams, '', '&', PHP_QUERY_RFC1738),
                'verify' => false, 'proxy' => $this->proxyUrl(), 'http_errors' => false,
                'allow_redirects' => false, 'timeout' => 20, 'connect_timeout' => 10,
            ]);
            $status = $response->getStatusCode();
            if ($status === 401 || $status === 403) {
                $this->revokeShared($session['access_token']);
                throw new PaymentDefinitiveException('付呗收款单登录态失效或无创建权限', 40200);
            }
            $body = $response->getBody()->read(2097153);
            if ($status !== 200 || strlen($body) > 2097152) {
                throw new PaymentUncertainException('付呗收款单创建响应异常', 40200);
            }
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (PaymentDefinitiveException|PaymentUncertainException $e) {
            throw $e;
        } catch (Throwable) {
            throw new PaymentUncertainException('付呗收款单创建请求或响应无法确认', 40200);
        }
        if (($data['success'] ?? null) === false) {
            if ((string) ($data['errorCode'] ?? '') === '1003') {
                $this->revokeShared($session['access_token']);
            }
            throw new PaymentDefinitiveException('付呗收款单创建被平台拒绝', 40200);
        }
        $id = $data['data']['receiptId'] ?? null;
        if (($data['success'] ?? null) !== true || !$this->validId($id)) {
            throw new PaymentUncertainException('付呗收款单未返回成功状态及有效 receiptId', 40200);
        }
        return $id;
    }

    /** 撤销同一旧令牌的共享权限，避免覆盖 watcher 已刷新的会话。 */
    private function revokeShared(string $token): void
    {
        $script = <<<'LUA'
local raw = redis.call('GET', KEYS[1])
if not raw then return 0 end
local ok, p = pcall(cjson.decode, raw)
if not ok or type(p.shared_data) ~= 'table' or p.shared_data.access_token ~= ARGV[1] then return 0 end
p.shared_data = {}
redis.call('SET', KEYS[1], cjson.encode(p), 'KEEPTTL')
return 1
LUA;
        $this->redisRaw('EVAL', $script, 1, 'receipt_watcher_session_' . $this->accountKey(), $token);
    }

    /** @param callable():array<string,mixed> $callback 支付单级持锁建单流程 */
    private function withPayLock(string $payNo, callable $callback): array
    {
        $key = 'mpay_fubei_cashier_lock_' . $this->accountKey() . '_' . $payNo;
        $token = bin2hex(random_bytes(16));
        $result = $this->redisRaw('SET', $key, $token, 'NX', 'EX', 90);
        if ($result !== true && strtoupper((string) $result) !== 'OK') {
            throw new PaymentUncertainException('付呗收款单正在创建，请稍后重试', 40200);
        }
        $this->lockKey = $key;
        $this->lockToken = $token;
        try {
            return $callback();
        } finally {
            $this->lockKey = $this->lockToken = null;
            $this->redisRaw('EVAL',
                "if redis.call('GET', KEYS[1]) == ARGV[1] then return redis.call('DEL', KEYS[1]) else return 0 end",
                1, $key, $token);
        }
    }

    /** 外呼前及本地持久化前检查、续租，失去所有权的执行者不得继续。 */
    private function assertLease(): void
    {
        if ($this->lockKey === null || $this->lockToken === null
            || (int) $this->redisRaw('EVAL',
                "if redis.call('GET', KEYS[1]) == ARGV[1] then return redis.call('EXPIRE', KEYS[1], 90) else return 0 end",
                1, $this->lockKey, $this->lockToken) !== 1) {
            throw new PaymentUncertainException('付呗收款单建单锁已失效', 40200);
        }
    }

    protected function redisRaw(string $command, mixed ...$arguments): mixed
    {
        try {
            return Redis::connection()->rawCommand($command, ...$arguments);
        } catch (Throwable) {
            throw new PaymentUncertainException('付呗收款单 Redis 操作失败', 40200);
        }
    }

    /** @param array<string,mixed> $meta 非敏感创建上下文 */
    private function uncertain(string $message, array $meta): PaymentUncertainException
    {
        return new PaymentUncertainException($message, 40200, [
            'pay_product' => 'scan', 'pay_action' => self::ACTION, 'channel_context' => $meta,
        ]);
    }

    private function accountKey(): string { return $this->getCode() . '_' . (int) $this->getConfig('api_config_id'); }
    protected function now(): int { return time(); }

    /** 校验本地收款窗口秒数，不表示平台收款单会自动到期。 */
    private function validSeconds(): int
    {
        $seconds = $this->getConfig('receipt_valid_seconds', 300);
        if (!is_int($seconds) || $seconds < 60 || $seconds > 1800) {
            throw new PaymentDefinitiveException('付呗收款有效期必须为60至1800秒的整数', 40200);
        }
        return $seconds;
    }

    private function fingerprint(): string
    {
        $username = trim((string) $this->getConfig('watcher_username', ''));
        $password = (string) $this->getConfig('watcher_password', '');
        if ($username === '' || $password === '') {
            throw new PaymentDefinitiveException('付呗收款单账号或密码未配置', 40200);
        }
        $credentialHash = hash('sha256', hash('sha256', $username) . ':' . hash('sha256', $password));
        $enabled = $this->getConfig('proxy_enabled', false) === true;
        return hash('sha256', json_encode([$credentialHash,
            trim((string) $this->getConfig('receipt_account_no', '')),
            trim((string) $this->getConfig('receipt_merchant_name', '')),
            $enabled, $enabled ? hash('sha256', $this->proxyUrl()) : ''], self::JSON_FLAGS));
    }

    private function proxyUrl(): string
    {
        if ($this->getConfig('proxy_enabled', false) !== true) {
            return '';
        }
        $value = trim((string) $this->getConfig('receipt_proxy_url', ''));
        $parts = parse_url($value);
        if (!is_array($parts) || empty($parts['host']) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || isset($parts['query']) || isset($parts['fragment']) || !in_array($parts['path'] ?? '', ['', '/'], true)) {
            throw new PaymentDefinitiveException('付呗收款单代理地址无效', 40200);
        }
        return $value;
    }

    private function validId(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[1-9][0-9]{0,63}$/D', $value) === 1;
    }

    /** 将标准两位小数元字符串精确转分，拒绝溢出及舍入。 */
    private function yuanToCents(string $value): int
    {
        if (preg_match('/^(0|[1-9][0-9]*)\.([0-9]{2})$/D', $value, $parts) !== 1) {
            throw new PaymentException('付呗收款单流水金额格式无效', 40200);
        }
        $whole = filter_var($parts[1], FILTER_VALIDATE_INT);
        if ($whole === false || $whole > intdiv(PHP_INT_MAX - (int) $parts[2], 100)) {
            throw new PaymentException('付呗收款单金额超限', 40200);
        }
        return $whole * 100 + (int) $parts[2];
    }

    /** 本地订单时间使用上海时区，仅接受秒级格式或日期对象。 */
    private function timeValue(mixed $value): int
    {
        if ($value instanceof DateTimeInterface) {
            return $value->getTimestamp();
        }
        if (!is_string($value) || $value === '') {
            return 0;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone('Asia/Shanghai'));
        return $date !== false && $date->format('Y-m-d H:i:s') === $value ? $date->getTimestamp() : 0;
    }
}
