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
 * 易宝老板管账固定金额收款单插件。
 *
 * 使用 Go watcher 探测过的后台会话创建单人收款单。Go 只投递汇总交易列表，
 * 本插件核对标题、收款单号和原始金额；订单状态及商户通知由支付核心推进。
 */
class YeepayBossCashierReceiptPayment extends BasePayment implements PaymentInterface, PayPluginInterface, ChannelNotifyPayloadInterface
{
    private const CREATE_URL = 'https://mp.yeepay.com/app/yjf/cashier/info';
    private const CASH_URL = 'https://cash.yeepay.com/yjf?cashierNo=';
    private const REFERER = 'https://mp.yeepay.com/mp-galaxy/mp-easy-payment/';
    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36';

    /** @var array<string, mixed> */
    protected array $paymentInfo = [
        'code' => 'yeepay_boss_cashier_receipt',
        'name' => '易宝易缴费收款单收款',
        'plugin_type' => PaymentPluginTypeConstant::TYPE_BACKEND,
        'author' => 'MPAY',
        'link' => 'https://mp.yeepay.com/',
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

    /**
     * 提供后台登录、商户选择及查询配置；代理开关使用布尔值。
     *
     * @return list<array<string, mixed>>
     */
    public function getConfigSchema(): array
    {
        return [
            [
                'type' => 'input', 'field' => 'watcher_username', 'title' => '后台登录账号', 'value' => '',
                'props' => ['placeholder' => '请输入易宝老板管账登录账号'],
                'validate' => [['required' => true, 'message' => '后台登录账号不能为空']],
            ],
            [
                'type' => 'password', 'field' => 'watcher_password', 'title' => '后台登录密码', 'value' => '',
                'props' => ['placeholder' => '请输入易宝老板管账登录密码'],
                'validate' => [['required' => true, 'message' => '后台登录密码不能为空']],
            ],
            [
                'type' => 'input', 'field' => 'receipt_account_no', 'title' => '收款商户号', 'value' => '',
                'props' => ['placeholder' => '请输入当前收款商户号'],
                'validate' => [['required' => true, 'message' => '收款商户号不能为空']],
            ],
            [
                'type' => 'input', 'field' => 'receipt_shop_no', 'title' => '门店编号', 'value' => '',
                'props' => ['placeholder' => '只有一个门店时可留空自动选择'],
            ],
            [
                'type' => 'inputNumber', 'field' => 'receipt_template_id', 'title' => '模板 ID', 'value' => null,
                'props' => ['placeholder' => '只有一个可用模板时可留空自动选择', 'min' => 1, 'step' => 1],
            ],
            [
                'type' => 'inputNumber', 'field' => 'receipt_valid_seconds', 'title' => '收款有效期(秒)', 'value' => 300,
                'props' => ['placeholder' => '请输入60至1800秒', 'min' => 60, 'max' => 1800, 'step' => 60],
            ],
            [
                'type' => 'inputNumber', 'field' => 'receipt_watcher_query_interval_seconds', 'title' => '账号查询间隔(秒)',
                'value' => 5, 'props' => ['placeholder' => '请输入2至60秒', 'min' => 2, 'max' => 60, 'step' => 1],
            ],
            [
                'type' => 'switch', 'field' => 'proxy_enabled', 'title' => '启用 IP 代理', 'value' => false,
                'control' => [['rule' => ['receipt_proxy_url'], 'value' => true, 'method' => 'display']],
            ],
            [
                'type' => 'password', 'field' => 'receipt_proxy_url', 'title' => 'IP 代理地址', 'value' => '',
                'props' => ['placeholder' => '请输入http://用户名:密码@主机:端口'],
            ],
        ];
    }

    /**
     * 创建固定金额收款单并返回二维码。
     *
     * 创建意向先持久化；平台响应不确定且没有 cashierNo 时，禁止重发创建请求。
     * 正常调用只请求一次平台，不从收款单列表读取二维码。
     *
     * @param array<string, mixed> $order 标准下单参数，amount 单位为分
     * @return array<string, mixed> 标准待支付结果
     */
    public function pay(array $order): array
    {
        $payNo = (string) ($order['pay_no'] ?? '');
        $amount = $order['amount'] ?? null;
        $payType = (string) ($order['pay_type_code'] ?? '');
        if (preg_match('/^PAY[0-9]{20}$/D', $payNo) !== 1 || !is_int($amount) || $amount <= 0
            || !in_array($payType, $this->getEnabledPayTypes(), true)) {
            throw new PaymentDefinitiveException('易宝收款单支付单号、金额或支付方式无效', 40200);
        }
        $session = $this->validatedSession();
        return $this->withPayLock($payNo, function () use ($order, $payNo, $amount, $payType, $session): array {
            $meta = $this->prepareCreation($payNo, $amount, $session);
            if (($meta['phase'] ?? '') === 'creating') {
                try {
                    $cashierNo = $this->createCashier($payNo, $amount, $meta, $session);
                } catch (PaymentDefinitiveException $e) {
                    $this->markCreationRejected($payNo, $meta);
                    throw $e;
                } catch (Throwable) {
                    throw new PaymentUncertainException('易宝收款单创建结果不确定，禁止重复创建', 40200, [
                        'pay_product' => 'scan', 'pay_action' => 'cashier/info',
                        'channel_context' => $meta,
                    ]);
                }
                $meta['cashier_no'] = $cashierNo;
                $meta['phase'] = 'ready';
                $this->persistReady($payNo, $meta);
            }
            $url = self::CASH_URL . rawurlencode((string) $meta['cashier_no']);
            return $this->pendingPaymentResult($order, [
                'pay_type' => $payType, 'pay_product' => 'scan', 'pay_action' => 'cashier/info',
                'channel_context' => $meta,
                'pay_page' => 'page',
                'pay_params' => [
                    '_page' => 'receiptQrcode', 'qrcode' => $url,
                    'amount' => FormatHelper::amount($amount),
                    'original_amount' => FormatHelper::amount($amount),
                    'receipt_match_mode' => 'dynamic_qrcode',
                    'expire_at' => $meta['expire_at'],
                    'expire_at_timestamp' => $this->timeValue($meta['expire_at']),
                    'receipt_valid_seconds' => $this->validSeconds(),
                    'description' => '请扫码完成付款。',
                ],
            ]);
        });
    }

    /** @param array<string, mixed> $order 标准查单参数 */
    public function query(array $order): array
    {
        return [
            'status' => PaymentPluginStatusConstant::PENDING, 'pay_no' => (string) ($order['pay_no'] ?? ''),
            'paid_amount' => null, 'chan_order_no' => (string) ($order['chan_order_no'] ?? ''),
            'chan_trade_no' => (string) ($order['chan_trade_no'] ?? ''),
            'message' => '等待易宝老板管账汇总流水监听',
        ];
    }

    public function close(array $order): array
    {
        throw new UnsupportedPaymentOperationException('易宝收款单暂不支持上游关单', 40200);
    }

    public function refund(array $order): array
    {
        throw new UnsupportedPaymentOperationException('易宝收款单暂不支持接口退款', 40200);
    }

    public function notify(Request $request): array
    {
        return $this->notifyPayload((array) $request->all());
    }

    /**
     * 给流水中的 20 位标题补回 PAY 前缀定位订单，再用 cashierNo 校验归属。
     *
     * @param array<string, mixed> $payload 标准 watcher 流水
     * @return array{pay_no:string}
     */
    public function channelNotifyPayload(array $payload): array
    {
        [$payOrder] = $this->matchedFlow($payload);
        return ['pay_no' => (string) $payOrder->pay_no];
    }

    /** @param array<string, mixed> $payload 标准 watcher 流水 */
    public function notifyPayload(array $payload): array
    {
        [$payOrder, $record, $amount] = $this->matchedFlow($payload);
        return [
            'status' => PaymentPluginStatusConstant::SUCCESS, 'pay_no' => (string) $payOrder->pay_no,
            'paid_amount' => $amount, 'chan_order_no' => $record['order_no'],
            'chan_trade_no' => $record['order_no'], 'paid_at' => $record['paid_at'],
            'channel_status' => 'FULLY_PAY', 'message' => '易宝收款单交易流水精确匹配',
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
     * 将交易列表中的标题、收款单号和实际支付事实与订单创建快照逐项核对。
     *
     * @param array<string, mixed> $payload 标准队列消息
     * @return array{0:object,1:array<string,string>,2:int}
     */
    private function matchedFlow(array $payload): array
    {
        if (($payload['plugin_code'] ?? '') !== $this->getCode()
            || (int) ($payload['api_config_id'] ?? 0) !== (int) $this->getConfig('api_config_id', 0)) {
            throw new PaymentException('易宝收款单流水配置不匹配', 40200);
        }
        $record = $payload['record'] ?? null;
        if (!is_array($record)) {
            throw new PaymentException('易宝收款单缺少标准流水', 40200);
        }
        foreach (['order_no', 'pay_type', 'price', 'channel', 'remark', 'paid_at'] as $field) {
            if (!is_string($record[$field] ?? null)) {
                throw new PaymentException('易宝收款单流水字段类型无效', 40200);
            }
        }
        $title = $record['remark'];
        if (preg_match('/^[0-9]{20}$/D', $title) !== 1 || $record['order_no'] === ''
            || strlen($record['order_no']) > 64
            || !in_array($record['pay_type'], $this->getEnabledPayTypes(), true)) {
            throw new PaymentException('易宝收款单交易号、标题或支付方式无效', 40200);
        }
        $payNo = 'PAY' . $title;
        $channelIds = $this->paymentChannelRepository->idsByPluginConfig($this->getCode(), (int) $this->getConfig('api_config_id', 0));
        $payOrder = $this->payOrderRepository->findByPayNo($payNo);
        $payType = $this->paymentTypeRepository->findByCode($record['pay_type'], ['id']);
        if (!$payOrder || !$payType || (string) $payOrder->plugin_code !== $this->getCode()
            || !in_array((int) $payOrder->channel_id, $channelIds, true)
            || (int) $payOrder->pay_type_id !== (int) $payType->id) {
            throw new PaymentException('易宝收款单流水不属于当前支付配置', 40200);
        }
        $amount = $this->yuanToCents($record['price']);
        $paidAt = $this->timeValue($record['paid_at']);
        $ext = (array) ($payOrder->ext_json ?? []);
        $context = (array) ($ext['payment_context'] ?? []);
        $receipt = (array) ($context['channel_context'] ?? []);
        $metadata = (array) ($ext['receipt_watcher'] ?? []);
        $start = $this->timeValue($payOrder->request_at ?? $payOrder->created_at);
        $end = $this->timeValue($payOrder->expire_at);
        if ($amount !== (int) $payOrder->pay_amount || $start <= 0 || $end <= $start
            || $paidAt < $start || $paidAt >= $end
            || ($context['pay_product'] ?? '') !== 'scan' || ($context['pay_action'] ?? '') !== 'cashier/info'
            || ($receipt['cashier_no'] ?? '') !== $record['channel']
            || ($receipt['title'] ?? '') !== $title
            || (int) ($receipt['amount'] ?? 0) !== $amount
            || (int) ($receipt['api_config_id'] ?? 0) !== (int) $this->getConfig('api_config_id', 0)
            || ($metadata['plugin_code'] ?? '') !== $this->getCode()
            || ($metadata['phase'] ?? '') !== 'ready'
            || ($metadata['cashier_no'] ?? '') !== $record['channel']
            || ($metadata['title'] ?? '') !== $title
            || ($metadata['merchant_no'] ?? '') !== (string) $this->getConfig('receipt_account_no', '')
            || ($metadata['shop_no'] ?? '') !== ($receipt['shop_no'] ?? '')
            || ($metadata['identity'] ?? '') !== ($receipt['identity'] ?? '')
            || $this->timeValue($metadata['expire_at'] ?? null) !== $end) {
            throw new PaymentException('易宝收款单流水与创建上下文不一致', 40200);
        }
        $bound = $this->payOrderRepository->findByReceiptChannelOrder($channelIds, $record['order_no'], ['pay_no']);
        if ($bound && (string) $bound->pay_no !== $payNo) {
            throw new PaymentException('易宝收款单交易已绑定其他支付单', 40200);
        }
        foreach (['channel_order_no', 'channel_trade_no'] as $field) {
            $existing = (string) ($payOrder->{$field} ?? '');
            if ($existing !== '' && $existing !== $record['order_no']) {
                throw new PaymentException('易宝收款单已绑定不同交易', 40200);
            }
        }
        $status = (int) $payOrder->status;
        if ($status === TradeConstant::ORDER_STATUS_SUCCESS) {
            if ((string) $payOrder->channel_order_no !== $record['order_no']
                || (string) $payOrder->channel_trade_no !== $record['order_no']) {
                throw new PaymentException('易宝收款单重复流水与已成功事实不符', 40200);
            }
        } elseif (!in_array($status, TradeConstant::orderMutableStatuses(), true) || time() >= $end) {
            throw new PaymentException('易宝收款单不可确认或已过期', 40200);
        }
        return [$payOrder, $record, $amount];
    }

    /**
     * 从统一 Session 的共享区提取经交易接口探测的登录态。
     *
     * @return array<string,mixed> 已绑定当前账号、商户和代理配置的会话
     */
    protected function validatedSession(): array
    {
        try {
            $raw = Redis::get('receipt_watcher_session_' . $this->accountKey());
            $payload = is_string($raw) && $raw !== '' ? json_decode($raw, true, 512, JSON_THROW_ON_ERROR) : [];
        } catch (Throwable) {
            throw new PaymentDefinitiveException('易宝收款单登录态读取失败', 40200);
        }
        $shared = is_array($payload) ? ($payload['shared_data'] ?? null) : null;
        $private = is_array($payload) ? ($payload['session_data'] ?? null) : null;
        $username = trim((string) $this->getConfig('watcher_username', ''));
        $password = (string) $this->getConfig('watcher_password', '');
        $credentialHash = hash('sha256', hash('sha256', $username) . ':' . hash('sha256', $password));
        $now = time();
        if ($username === '' || $password === '' || !is_array($payload) || !is_array($shared) || !is_array($private)
            || ($payload['schema_version'] ?? null) !== 1
            || ($payload['plugin_code'] ?? '') !== $this->getCode()
            || ($payload['account_key'] ?? '') !== $this->accountKey()
            || ($payload['runtime'] ?? '') !== 'direct'
            || !is_string($payload['config_hash'] ?? null) || $payload['config_hash'] === ''
            || (int) ($payload['expires_at'] ?? 0) <= $now
            || ($shared['credential_hash'] ?? '') !== $credentialHash
            || ($private['credential_hash'] ?? '') !== $credentialHash
            || ($private['sessionid'] ?? '') !== ($shared['x_wx_id'] ?? null)
            || !is_string($shared['x_wx_id'] ?? null) || $shared['x_wx_id'] === ''
            || strpbrk($shared['x_wx_id'], "\r\n") !== false
            || (string) ($shared['merchant_no'] ?? '') !== (string) $this->getConfig('receipt_account_no', '')
            || (string) ($private['merchant_no'] ?? '') !== (string) ($shared['merchant_no'] ?? '')
            || (string) ($private['shop_no'] ?? '') !== (string) ($shared['shop_no'] ?? '')
            || (int) ($private['template_id'] ?? 0) !== (int) ($shared['template_id'] ?? 0)
            || (string) ($private['field_id'] ?? '') !== (string) ($shared['field_id'] ?? '')
            || (string) ($shared['shop_no'] ?? '') === ''
            || (string) ($shared['shop_name'] ?? '') === ''
            || (int) ($shared['template_id'] ?? 0) <= 0
            || (string) ($shared['field_id'] ?? '') === ''
            || (int) ($shared['api_config_id'] ?? 0) !== (int) $this->getConfig('api_config_id', 0)
            || (int) ($shared['last_probe_at'] ?? 0) <= 0
            || (int) ($shared['last_probe_at'] ?? 0) > $now
            || (int) ($shared['verified_until'] ?? 0) <= $now
            || ((int) ($shared['token_expires_at'] ?? 0) > 0 && (int) $shared['token_expires_at'] <= $now)) {
            throw new PaymentDefinitiveException('易宝收款单缺少已验证的后台登录态，请等待监听工具探测', 40200);
        }
        $configuredShop = trim((string) $this->getConfig('receipt_shop_no', ''));
        $configuredTemplate = (int) $this->getConfig('receipt_template_id', 0);
        $enabled = $this->getConfig('proxy_enabled', false) === true;
        $proxyHash = $enabled ? hash('sha256', $this->proxyUrl()) : '';
        if (($configuredShop !== '' && $configuredShop !== $shared['shop_no'])
            || ($configuredTemplate > 0 && $configuredTemplate !== (int) $shared['template_id'])
            || ($shared['proxy_enabled'] ?? null) !== $enabled
            || ($shared['proxy_hash'] ?? '') !== $proxyHash) {
            throw new PaymentDefinitiveException('易宝收款单商户、门店、模板或代理配置已变化，请重新预登录', 40200);
        }
        return $shared;
    }

    /**
     * 在支付单行锁内持久化创建意向；provisional payment_context 不代表已创建。
     *
     * @param array<string,mixed> $session 经过探测的账号会话
     * @return array<string,mixed> 已绑定订单的创建快照
     */
    protected function prepareCreation(string $payNo, int $amount, array $session): array
    {
        $this->assertPayLease();
        return Db::transaction(function () use ($payNo, $amount, $session): array {
            $payOrder = $this->payOrderRepository->findForUpdateByPayNo($payNo);
            if (!$payOrder || (string) $payOrder->plugin_code !== $this->getCode()
                || (int) $payOrder->channel_id !== (int) $this->getConfig('channel_id', 0)
                || (int) $payOrder->pay_amount !== $amount
                || !in_array((int) $payOrder->status, TradeConstant::orderMutableStatuses(), true)) {
                throw new PaymentDefinitiveException('易宝收款单不属于当前支付通道或已结束', 40200);
            }
            $ext = (array) ($payOrder->ext_json ?? []);
            $existing = (array) ($ext['receipt_watcher'] ?? []);
            $identity = hash('sha256', json_encode([$payNo, $amount, $session['merchant_no'],
                $session['shop_no'], $session['template_id'], $this->accountKey(),
                $session['credential_hash'], $session['proxy_hash']], JSON_THROW_ON_ERROR));
            if ($existing !== []) {
                if (($existing['identity'] ?? '') !== $identity || ($existing['title'] ?? '') !== $this->receiptTitle($payNo)
                    || (int) ($existing['amount'] ?? 0) !== $amount) {
                    throw new PaymentUncertainException('易宝收款单已有不同的创建标识，禁止覆盖', 40200);
                }
                if (($existing['phase'] ?? '') !== 'ready' || !preg_match('/^[1-9][0-9]{1,63}$/D', (string) ($existing['cashier_no'] ?? ''))) {
                    throw new PaymentUncertainException('易宝收款单创建结果不确定，禁止重复创建', 40200);
                }
                if ($this->timeValue($existing['expire_at'] ?? null) <= time()) {
                    throw new PaymentDefinitiveException('易宝收款单已过期', 40200);
                }
                return $existing;
            }
            $start = time();
            $end = min($this->timeValue($payOrder->expire_at), $start + $this->validSeconds());
            if ($end <= $start + 30) {
                throw new PaymentDefinitiveException('易宝收款单剩余有效期不足', 40200);
            }
            $meta = [
                'plugin_code' => $this->getCode(), 'api_config_id' => (int) $this->getConfig('api_config_id', 0),
                'pay_no' => $payNo, 'title' => $this->receiptTitle($payNo), 'cashier_no' => null, 'phase' => 'creating',
                'amount' => $amount, 'merchant_no' => $session['merchant_no'],
                'shop_no' => $session['shop_no'], 'template_id' => (int) $session['template_id'],
                'effective_at' => $this->datetime($start), 'expire_at' => $this->datetime($end),
                'identity' => $identity,
            ];
            $payOrder->expire_at = $meta['expire_at'];
            $ext['receipt_watcher'] = $meta;
            $payOrder->ext_json = $ext;
            $this->assertPayLease();
            $payOrder->save();
            return $meta;
        });
    }

    /** @param array<string,mixed> $meta 已保存的创建意向 */
    protected function persistReady(string $payNo, array $meta): void
    {
        $this->assertPayLease();
        try {
            Db::transaction(function () use ($payNo, $meta): void {
                $payOrder = $this->payOrderRepository->findForUpdateByPayNo($payNo);
                $ext = (array) ($payOrder?->ext_json ?? []);
                $existing = (array) ($ext['receipt_watcher'] ?? []);
                if (!$payOrder || ($existing['identity'] ?? '') !== $meta['identity']
                    || ($existing['phase'] ?? '') !== 'creating'
                    || !in_array((int) $payOrder->status, TradeConstant::orderMutableStatuses(), true)) {
                    throw new PaymentUncertainException('易宝收款单创建标识保存冲突', 40200);
                }
                $ext['receipt_watcher'] = $meta;
                $payOrder->ext_json = $ext;
                $this->assertPayLease();
                $payOrder->save();
            });
        } catch (Throwable) {
            throw new PaymentUncertainException('易宝收款单已创建，但编号保存失败，禁止重复创建', 40200, [
                'pay_product' => 'scan', 'pay_action' => 'cashier/info', 'channel_context' => $meta,
            ]);
        }
    }

    /** @param array<string,mixed> $meta 创建意向 */
    protected function markCreationRejected(string $payNo, array $meta): void
    {
        Db::transaction(function () use ($payNo, $meta): void {
            $payOrder = $this->payOrderRepository->findForUpdateByPayNo($payNo);
            $ext = (array) ($payOrder?->ext_json ?? []);
            $existing = (array) ($ext['receipt_watcher'] ?? []);
            if ($payOrder && ($existing['identity'] ?? '') === $meta['identity']) {
                $existing['phase'] = 'rejected';
                $ext['receipt_watcher'] = $existing;
                $payOrder->ext_json = $ext;
                $payOrder->save();
            }
        });
    }

    /**
     * 按抓包字段创建固定金额收款单，成功响应 data 是收款单号。
     *
     * @param array<string,mixed> $meta 有效期与商户快照
     * @param array<string,mixed> $session 经真实接口探测的会话
     */
    protected function createCashier(string $payNo, int $amount, array $meta, array $session): string
    {
        $this->assertPayLease();
        $title = $this->receiptTitle($payNo);
        $field = [
            'fieldId' => (string) $session['field_id'], 'fieldName' => '缴费说明',
            'fieldType' => 'TEXT', 'enableModify' => false, 'fieldSort' => 1,
            'required' => false, 'fieldValue' => $title,
        ];
        $payload = [
            'amount' => FormatHelper::amount($amount), 'fieldList' => [$field],
            'merchantNo' => (string) $session['merchant_no'], 'templateId' => (int) $session['template_id'],
            'terminalInfo' => '',
            'shopInfo' => json_encode(['shopNo' => $session['shop_no'], 'shopName' => $session['shop_name']],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'model' => 'single', 'periodEnd' => $meta['expire_at'], 'periodType' => 'period', 'title' => $title,
        ];
        $options = [
            'headers' => [
                'Authorization' => 'undefined', 'X-WX-Id' => $session['x_wx_id'],
                'systemcode' => 'boss-noah', 'interaction-type' => 'ERROR_CODE',
                'yp-request-id' => 'uuid', 'Origin' => 'https://mp.yeepay.com',
                'Referer' => self::REFERER, 'User-Agent' => self::USER_AGENT,
                'Accept' => 'application/json, text/plain, */*', 'Content-Type' => 'application/json',
            ],
            'json' => $payload, 'verify' => true, 'http_errors' => false,
            'allow_redirects' => false, 'timeout' => 10, 'connect_timeout' => 10,
            'proxy' => $this->proxyUrl(),
        ];
        $cookie = (string) ($session['cookie_header'] ?? '');
        if ($cookie !== '' && strpbrk($cookie, "\r\n") === false) {
            $options['headers']['Cookie'] = $cookie;
        }
        try {
            $response = $this->request('POST', self::CREATE_URL, $options);
            $status = $response->getStatusCode();
            $body = $response->getBody()->read(2097153);
            if (strlen($body) > 2097152) {
                throw new PaymentUncertainException('易宝收款单创建响应过大', 40200);
            }
            $result = json_decode($body, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (PaymentUncertainException $e) {
            throw $e;
        } catch (Throwable) {
            throw new PaymentUncertainException('易宝收款单创建请求或响应无法确认', 40200);
        }
        if ($status === 401 || $status === 403) {
            $this->revokeSharedSession($session['x_wx_id']);
            throw new PaymentDefinitiveException('易宝收款单后台会话失效或无创建权限', 40200);
        }
        if ($status !== 200 || !is_array($result) || !is_string($result['code'] ?? null)) {
            throw new PaymentUncertainException('易宝收款单创建响应状态异常', 40200);
        }
        if ($result['code'] !== '00000') {
            $code = preg_match('/^[A-Za-z0-9_.:-]{1,64}$/D', $result['code']) === 1 ? $result['code'] : 'UNKNOWN';
            throw new PaymentDefinitiveException(
                '易宝收款单创建被平台拒绝 [' . $code . ']：' . $this->creationRejectionMessage($result, $session),
                40200,
                ['upstream_code' => $code, 'http_status' => $status]
            );
        }
        $cashierNo = $result['data'] ?? null;
        if ((!is_string($cashierNo) && !is_int($cashierNo))
            || preg_match('/^[1-9][0-9]{1,63}$/D', (string) $cashierNo) !== 1) {
            throw new PaymentUncertainException('易宝收款单创建成功但未返回有效编号', 40200);
        }
        return (string) $cashierNo;
    }

    /**
     * 移除固定 PAY 前缀以满足平台 20 字符限制，保留全部数字以便无损还原。
     */
    private function receiptTitle(string $payNo): string
    {
        if (preg_match('/^PAY([0-9]{20})$/D', $payNo, $matches) !== 1) {
            throw new PaymentDefinitiveException('易宝收款单支付单号格式无效', 40200);
        }
        return $matches[1];
    }

    /**
     * 提取平台拒绝原因，去掉会话和配置凭证后用于订单错误提示。
     *
     * @param array<string,mixed> $result 创建接口业务响应
     * @param array<string,mixed> $session 本次请求使用的共享会话
     */
    private function creationRejectionMessage(array $result, array $session): string
    {
        $message = is_string($result['message'] ?? null) ? $result['message'] : '';
        $secrets = [
            $session['x_wx_id'] ?? '', $session['cookie_header'] ?? '',
            $this->getConfig('watcher_username', ''), $this->getConfig('watcher_password', ''),
        ];
        if ($this->getConfig('proxy_enabled', false) === true) {
            $secrets[] = $this->getConfig('receipt_proxy_url', '');
        }
        foreach ($secrets as $secret) {
            if (is_string($secret) && $secret !== '') {
                $message = str_replace($secret, '[REDACTED]', $message);
            }
        }
        $message = preg_replace(
            '/\b(token|sessionid|x[-_]wx[-_]id|authorization|cookie|password|secret)\b\s*[:=]\s*[^\s,;]+/i',
            '$1=[REDACTED]',
            strip_tags($message)
        ) ?? '';
        $message = trim(preg_replace('/[\x00-\x20\x7f]+/', ' ', $message) ?? '');
        return $message === '' ? '请核对收款单参数或账号权限' : mb_strcut($message, 0, 240, 'UTF-8');
    }

    /**
     * 只撤销当前会话的共享下单权限，避免旧请求清掉 watcher 刚刷新出的会话。
     */
    private function revokeSharedSession(string $sessionId): void
    {
        $script = <<<'LUA'
local raw = redis.call('GET', KEYS[1])
if not raw then return 0 end
local ok, payload = pcall(cjson.decode, raw)
if not ok or type(payload.shared_data) ~= 'table' or payload.shared_data.x_wx_id ~= ARGV[1] then return 0 end
payload.shared_data = {}
redis.call('SET', KEYS[1], cjson.encode(payload), 'KEEPTTL')
return 1
LUA;
        $this->redisRaw('EVAL', $script, 1, 'receipt_watcher_session_' . $this->accountKey(), $sessionId);
    }

    /** @param callable():array<string,mixed> $callback 持锁建单逻辑 */
    protected function withPayLock(string $payNo, callable $callback): array
    {
        $key = 'mpay_yeepay_cashier_lock_' . $this->accountKey() . '_' . $payNo;
        $token = bin2hex(random_bytes(16));
        $locked = $this->redisRaw('SET', $key, $token, 'NX', 'EX', 90);
        if ($locked !== true && strtoupper((string) $locked) !== 'OK') {
            throw new PaymentUncertainException('易宝收款单正在创建，请稍后重试', 40200);
        }
        $this->lockKey = $key;
        $this->lockToken = $token;
        try {
            return $callback();
        } finally {
            $this->lockKey = null;
            $this->lockToken = null;
            $this->redisRaw('EVAL',
                "if redis.call('GET', KEYS[1]) == ARGV[1] then return redis.call('DEL', KEYS[1]) else return 0 end",
                1, $key, $token);
        }
    }

    private function assertPayLease(): void
    {
        if ($this->lockKey === null || $this->lockToken === null
            || (int) $this->redisRaw('EVAL',
                "if redis.call('GET', KEYS[1]) == ARGV[1] then return redis.call('EXPIRE', KEYS[1], 90) else return 0 end",
                1, $this->lockKey, $this->lockToken) !== 1) {
            throw new PaymentUncertainException('易宝收款单建单锁已失效', 40200);
        }
    }

    protected function redisRaw(string $command, mixed ...$arguments): mixed
    {
        try {
            return Redis::connection()->rawCommand($command, ...$arguments);
        } catch (Throwable) {
            throw new PaymentUncertainException('易宝收款单 Redis 操作失败', 40200);
        }
    }

    private function accountKey(): string
    {
        return $this->getCode() . '_' . (int) $this->getConfig('api_config_id', 0);
    }

    private function proxyUrl(): string
    {
        if ($this->getConfig('proxy_enabled', false) !== true) {
            return '';
        }
        $value = trim((string) $this->getConfig('receipt_proxy_url', ''));
        $parts = parse_url($value);
        if (!is_array($parts) || empty($parts['host']) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || isset($parts['query']) || isset($parts['fragment'])) {
            throw new PaymentDefinitiveException('易宝收款单代理地址无效', 40200);
        }
        return $value;
    }

    private function validSeconds(): int
    {
        $seconds = $this->getConfig('receipt_valid_seconds', 300);
        if (!is_int($seconds) || $seconds < 60 || $seconds > 1800) {
            throw new PaymentDefinitiveException('易宝收款有效期必须为60至1800秒', 40200);
        }
        return $seconds;
    }

    private function yuanToCents(string $text): int
    {
        if (preg_match('/^(0|[1-9][0-9]*)\.([0-9]{2})$/D', $text, $parts) !== 1) {
            throw new PaymentException('易宝收款单流水金额格式无效', 40200);
        }
        $whole = filter_var($parts[1], FILTER_VALIDATE_INT);
        if ($whole === false || $whole > intdiv(PHP_INT_MAX - (int) $parts[2], 100)) {
            throw new PaymentException('易宝收款单流水金额超限', 40200);
        }
        return $whole * 100 + (int) $parts[2];
    }

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

    private function datetime(int $timestamp): string
    {
        return (new DateTimeImmutable('@' . $timestamp))->setTimezone(new DateTimeZone('Asia/Shanghai'))->format('Y-m-d H:i:s');
    }
}
