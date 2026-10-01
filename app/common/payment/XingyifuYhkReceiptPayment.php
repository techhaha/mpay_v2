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
use support\Log;
use support\Redis;
use support\Request;
use support\Response;
use Throwable;

/**
 * 星驿付商户后台固定金额收款单支付插件。
 *
 * 使用 watcher 共享的后台 Bearer 创建、装修、发布并读取二维码，页面标题无损映射支付单号，匹配
 * Go watcher 的列表流水。该类不分配金额或备注码，不推进订单状态及商户回调。
 */
class XingyifuYhkReceiptPayment extends BasePayment implements PaymentInterface, PayPluginInterface, ChannelNotifyPayloadInterface
{
    private const API_URL = 'https://yhk.postar.cn';
    private const RECEIPT_PATH = '/v1/payfly/pf2/renovation/';
    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/132.0.0.0 Safari/537.36';

    /**
     * 插件身份与后台动态码监听能力。
     *
     * @var array<string, mixed>
     */
    protected array $paymentInfo = [
        'code' => 'xingyifu_yhk_receipt',
        'name' => '星驿付后台动态收款单',
        'plugin_type' => PaymentPluginTypeConstant::TYPE_BACKEND,
        'author' => 'MPAY',
        'link' => 'https://yhk.postar.cn/',
        'version' => '1.0.0',
        'pay_types' => ['alipay', 'wxpay', 'unionpay'],
        'transfer_types' => [],
        'receipt_watcher' => ['runtime' => 'direct', 'prelogin_supported' => true],
    ];

    private ?string $payLockKey = null;
    private ?string $payLockToken = null;
    private ?string $payDeptId = null;

    public function __construct(
        private readonly PayOrderRepository $payOrderRepository,
        private readonly PaymentChannelRepository $paymentChannelRepository,
        private readonly PaymentTypeRepository $paymentTypeRepository
    ) {
    }

    /**
     * 提供后台账号和动态码配置，代理字段只在布尔开关开启时显示。
     *
     * @return list<array<string, mixed>> 配置表单，不包含静态码或备注匹配字段
     */
    public function getConfigSchema(): array
    {
        return [
            [
                'type' => 'input',
                'field' => 'watcher_username',
                'title' => '后台登录账号',
                'value' => '',
                'props' => [
                    'placeholder' => '请输入星驿付商户后台登录账号',
                ],
                'validate' => [
                    ['required' => true, 'message' => '后台登录账号不能为空'],
                ],
            ],
            [
                'type' => 'password',
                'field' => 'watcher_password',
                'title' => '后台登录密码',
                'value' => '',
                'props' => [
                    'placeholder' => '请输入星驿付商户后台登录密码',
                ],
                'validate' => [
                    ['required' => true, 'message' => '后台登录密码不能为空'],
                ],
            ],
            [
                'type' => 'inputNumber',
                'field' => 'receipt_valid_seconds',
                'title' => '收款有效期(秒)',
                'value' => 300,
                'props' => [
                    'placeholder' => '请输入60至1800秒',
                    'min' => 60,
                    'max' => 1800,
                    'step' => 60,
                ],
            ],
            [
                'type' => 'inputNumber',
                'field' => 'receipt_watcher_query_interval_seconds',
                'title' => '账号查询间隔(秒)',
                'value' => 5,
                'props' => [
                    'placeholder' => '请输入2至60秒',
                    'min' => 2,
                    'max' => 60,
                    'step' => 1,
                ],
            ],
            [
                'type' => 'switch',
                'field' => 'proxy_enabled',
                'title' => '启用 IP 代理',
                'value' => false,
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
                    'placeholder' => '请输入http://用户名:密码@主机:端口',
                ],
            ],
        ];
    }

    /**
     * 创建固定金额收款单并返回标准待支付结果。
     *
     * 正常流程只执行创建、装修、发布、获取二维码四次请求。变更请求在外呼前
     * 保存进行中阶段；结果不确定时不盲目重发，二维码读取失败可按已知页面重试。
     *
     * @param array<string, mixed> $order 标准下单参数，amount 单位为整数分
     * @return array<string, mixed> 标准 pending 结果，不含令牌
     * @throws PaymentDefinitiveException 参数、Session、归属或有效期不符合要求时抛出
     * @throws PaymentUncertainException 无法确认创建进度或外呼结果时抛出
     */
    public function pay(array $order): array
    {
        $payNo = (string) ($order['pay_no'] ?? '');
        $amount = $order['amount'] ?? null;
        $payType = (string) ($order['pay_type_code'] ?? '');
        if (preg_match('/^PAY[0-9]{20}$/D', $payNo) !== 1 || !is_int($amount) || $amount <= 0
            || !in_array($payType, $this->getEnabledPayTypes(), true)) {
            throw new PaymentDefinitiveException('驿汇客支付单号必须为PAY加20位数字，金额或支付方式不符合要求', 40200);
        }
        $session = $this->validatedSession();

        $title = $this->receiptTitle($payNo);
        return $this->withPayLock($payNo, function () use ($order, $payNo, $title, $amount, $payType, $session): array {
            $this->payDeptId = $session['dept_id'];
            $identity = hash('sha256', json_encode([$payNo, $title, $amount, $payType, $this->accountKey(),
                $session['credential_hash'], $session['dept_id'], $session['proxy_enabled'], $session['proxy_hash']], JSON_THROW_ON_ERROR));
            $progress = $this->loadProgress($payNo);
            if ($progress === []) {
                $progress = $this->recoverMissingProgress($payNo, $amount, $payType, $identity, $session['dept_id']);
            }
            if ($progress !== [] && ($progress['identity'] ?? '') !== $identity) {
                throw new PaymentUncertainException('驿汇客已存在不同配置的建单进度，禁止重新创建', 40200);
            }
            if (in_array($progress['phase'] ?? '', ['creating', 'decorating', 'publishing'], true)) {
                $progress = $this->restoreKnownReceipt($payNo, $progress);
                if (in_array($progress['phase'] ?? '', ['creating', 'decorating', 'publishing'], true)) {
                    throw $this->uncertain('驿汇客变更请求结果不确定，禁止盲目重发', $progress);
                }
            }
            if (($progress['phase'] ?? '') === 'rejected') {
                throw new PaymentDefinitiveException('驿汇客收款单创建已被明确拒绝', 40200);
            }
            if ($progress === []) {
                $window = $this->prepareWindow($payNo, $amount);
                $progress = array_merge($window, ['identity' => $identity, 'phase' => 'creating',
                    'amount' => $amount, 'pay_no' => $payNo, 'title' => $title, 'dept_id' => $session['dept_id']]);
                $this->saveProgress($payNo, $progress);
                $this->persistReceiptMetadata($payNo, $progress);
                try {
                    $progress['ren_id'] = $this->receiptId($this->receiptRequest('addRenPage', $this->receiptPayload($payNo, $amount, $window)));
                } catch (PaymentDefinitiveException $e) {
                    $progress['phase'] = 'rejected';
                    $this->saveProgress($payNo, $progress);
                    throw $e;
                } catch (Throwable) {
                    throw $this->uncertain('驿汇客创建结果无法确认，禁止重复建单', $progress);
                }
                $progress['phase'] = 'created';
                try {
                    $this->persistReceiptMetadata($payNo, $progress);
                    $this->saveProgress($payNo, $progress);
                } catch (Throwable) {
                    throw $this->uncertain('驿汇客已返回页面 ID，但进度保存失败', $progress);
                }
            }
            if ($this->timeValue($progress['expire_at'] ?? null) <= $this->nowTimestamp()) {
                throw new PaymentDefinitiveException('驿汇客动态收款码已过期', 40200);
            }
            try {
                $progress = $this->resumeReceipt($payNo, $progress);
            } catch (PaymentDefinitiveException|PaymentUncertainException $e) {
                throw $e;
            } catch (Throwable) {
                throw $this->uncertain('驿汇客收款单后续进度无法确认', $progress);
            }
            return $this->pendingPaymentResult($order, [
                'pay_type' => $payType, 'pay_product' => 'scan', 'pay_action' => 'addRenPage',
                'channel_context' => $this->receiptContext($payNo, $progress),
                'pay_page' => 'page',
                'pay_params' => [
                    '_page' => 'receiptQrcode', 'qrcode' => $progress['qrcode'],
                    'amount' => FormatHelper::amount($amount), 'original_amount' => FormatHelper::amount($amount),
                    'receipt_match_mode' => 'dynamic_qrcode', 'expire_at' => $progress['expire_at'],
                    'expire_at_timestamp' => $this->timeValue($progress['expire_at']),
                    'receipt_valid_seconds' => $this->validSeconds(), 'description' => '请扫码完成付款。',
                ],
            ]);
        });
    }

    /**
     * 恢复已知页面的剩余步骤，不再次创建或额外查询装修详情。
     *
     * @param string $payNo 完整本地支付单号
     * @param array<string, mixed> $progress 已知页面、阶段和原始有效期
     * @return array<string, mixed> 含支付 URL 的 complete 进度
     */
    private function resumeReceipt(string $payNo, array $progress): array
    {
        $renId = $this->receiptId($progress['ren_id'] ?? null);
        if (($progress['phase'] ?? '') === 'created') {
            $progress = $this->mutateReceipt($payNo, $progress, 'renEdit',
                $this->decorationPayload($renId, (int) $progress['amount']), 'decorating', 'decorated');
        }
        if (($progress['phase'] ?? '') === 'decorated') {
            $progress = $this->mutateReceipt($payNo, $progress, 'status',
                ['renId' => $renId, 'status' => '1'], 'publishing', 'published');
        }
        if (($progress['phase'] ?? '') === 'published') {
            try {
                $data = $this->receiptRequest('qrcode/' . rawurlencode($progress['dept_id']) . '/' . rawurlencode((string) $renId));
                $url = is_string($data) ? trim($data) : '';
                if (!$this->validReceiptUrl($url)) {
                    throw $this->uncertain('驿汇客未返回有效支付链接', $progress);
                }
                $progress['qrcode'] = $url;
                $progress['phase'] = 'complete';
                $this->saveProgress($payNo, $progress);
            } catch (PaymentDefinitiveException $e) {
                throw $e;
            } catch (Throwable) {
                throw $this->uncertain('驿汇客二维码读取结果无法确认，可按已知页面恢复', $progress);
            }
        }
        if (($progress['phase'] ?? '') !== 'complete' || !is_string($progress['qrcode'] ?? null) || $progress['qrcode'] === '') {
            throw $this->uncertain('驿汇客建单进度无效', $progress);
        }
        return $progress;
    }

    /**
     * 保存变更进行中标记，再执行同一页面的装修或发布请求。
     *
     * 明确拒绝时保留上一个稳定阶段供恢复；通信或响应不确定时保留进行中标记，
     * 不把接口可能已经完成的变更当作安全重试。
     *
     * @param array<string, mixed> $progress 外呼前的稳定创建进度
     * @param array<string, mixed> $params 与平台协议类型一致的 JSON 参数
     * @return array<string, mixed> 成功后的稳定进度
     */
    private function mutateReceipt(string $payNo, array $progress, string $action, array $params, string $pending, string $done): array
    {
        $previous = $progress['phase'];
        $progress['phase'] = $pending;
        $this->saveProgress($payNo, $progress);
        try {
            $this->receiptRequest($action, $params);
        } catch (PaymentDefinitiveException $e) {
            $progress['phase'] = $previous;
            $this->saveProgress($payNo, $progress);
            throw $e;
        } catch (Throwable) {
            throw $this->uncertain('驿汇客变更请求结果无法确认，禁止盲目重发', $progress);
        }
        $progress['phase'] = $done;
        try {
            $this->saveProgress($payNo, $progress);
        } catch (Throwable) {
            throw $this->uncertain('驿汇客变更已成功，但进度保存失败', $progress);
        }
        return $progress;
    }

    /**
     * 等待账号级 watcher 查询，不为单笔订单重复扫描上游列表。
     *
     * @param array<string, mixed> $order 标准查单参数
     * @return array<string, mixed> 标准 pending 结果
     */
    public function query(array $order): array
    {
        return ['status' => PaymentPluginStatusConstant::PENDING, 'pay_no' => (string) ($order['pay_no'] ?? ''),
            'paid_amount' => null, 'chan_order_no' => (string) ($order['chan_order_no'] ?? ''),
            'chan_trade_no' => (string) ($order['chan_trade_no'] ?? ''), 'message' => '等待驿汇客 watcher 查询流水'];
    }

    /**
     * 拒绝未验证的上游交易关单操作。
     *
     * 收款单停用不等于已发起交易关闭，首版不返回虚构的 closed 结果。
     *
     * @throws UnsupportedPaymentOperationException 当前协议未提供已验证交易关单能力
     */
    public function close(array $order): array
    {
        throw new UnsupportedPaymentOperationException('驿汇客动态码不提供上游交易关单', 40200);
    }

    /**
     * 拒绝缺少终态协议的接口退款。
     *
     * @throws UnsupportedPaymentOperationException 参考接口不能证明退款最终完成
     */
    public function refund(array $order): array
    {
        throw new UnsupportedPaymentOperationException('驿汇客动态码首版不提供接口退款', 40200);
    }

    /**
     * 拒绝公开 HTTP 入口的未验签流水通知。
     *
     * @throws UnsupportedPaymentOperationException 收款确认只允许内部 watcher 队列入口
     */
    public function notify(Request $request): array
    {
        throw new UnsupportedPaymentOperationException('驿汇客仅接受 watcher 队列流水通知', 40200);
    }

    /**
     * 根据收款单标题精确定位支付单，返回前校验金额、支付方式、归属及时间。
     *
     * @param array<string, mixed> $payload 现有队列载荷，record.channel 为完整 pay_no
     * @return array{pay_no: string} 已通过校验的支付单号
     * @throws PaymentException 流水与支付单不一致或无法唯一确认时抛出
     */
    public function channelNotifyPayload(array $payload): array
    {
        [$payOrder] = $this->matchedFlow($payload);
        return ['pay_no' => (string) $payOrder->pay_no];
    }

    /**
     * 将已匹配列表流水转换为标准成功事实，不直接写入订单状态。
     *
     * @param array<string, mixed> $payload 内部 watcher 标准流水载荷
     * @return array<string, mixed> 实付金额为整数分，两个渠道单号均为列表 orderId
     * @throws PaymentException 金额、归属、状态或渠道单号不一致时抛出
     */
    public function notifyPayload(array $payload): array
    {
        [$payOrder, $record, $amount] = $this->matchedFlow($payload);
        return ['status' => PaymentPluginStatusConstant::SUCCESS, 'pay_no' => (string) $payOrder->pay_no,
            'paid_amount' => $amount, 'chan_order_no' => $record['order_no'], 'chan_trade_no' => $record['order_no'],
            'channel_status' => 'receipt_watcher_received', 'paid_at' => $record['paid_at'],
            'message' => '驿汇客动态收款单流水已精确匹配'];
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
     * 校验标准流水与支付尝试，禁止金额兜底及同流水关联另一订单。
     *
     * @param array<string, mixed> $payload 标准队列消息
     * @return array{PayOrder, array<string, string>, int} 支付单、流水及整数分金额
     */
    private function matchedFlow(array $payload): array
    {
        if (($payload['plugin_code'] ?? '') !== $this->getCode()
            || (int) ($payload['api_config_id'] ?? 0) !== (int) $this->getConfig('api_config_id', 0)) {
            throw new PaymentException('驿汇客流水插件配置不匹配', 40200);
        }
        $record = $payload['record'] ?? null;
        if (!is_array($record)) {
            throw new PaymentException('驿汇客缺少标准流水 record', 40200);
        }
        foreach (['order_no', 'pay_type', 'price', 'channel', 'remark', 'paid_at'] as $field) {
            if (!is_string($record[$field] ?? null)) {
                throw new PaymentException('驿汇客标准流水字段类型无效', 40200);
            }
        }
        $payNo = $record['channel'];
        if (preg_match('/^PAY[0-9]{20}$/D', $payNo) !== 1 || $record['order_no'] === '' || strlen($record['order_no']) > 64
            || !in_array($record['pay_type'], $this->getEnabledPayTypes(), true)) {
            throw new PaymentException('驿汇客流水订单号、标题或支付方式无效', 40200);
        }
        $channelIds = $this->paymentChannelRepository->idsByPluginConfig($this->getCode(), (int) $this->getConfig('api_config_id'));
        $payOrder = $this->payOrderRepository->findByPayNo($payNo);
        $payType = $this->paymentTypeRepository->findByCode($record['pay_type'], ['id']);
        if (!$payOrder || !$payType || (string) $payOrder->plugin_code !== $this->getCode()
            || !in_array((int) $payOrder->channel_id, $channelIds, true) || (int) $payOrder->pay_type_id !== (int) $payType->id) {
            throw new PaymentException('驿汇客流水未匹配同配置同支付方式的支付单', 40200);
        }
        $amount = $this->yuanToCents($record['price']);
        $paidAt = $this->timeValue($record['paid_at']);
        $start = $this->timeValue($payOrder->request_at ?? $payOrder->created_at);
        $end = $this->timeValue($payOrder->expire_at);
        if ($amount !== (int) $payOrder->pay_amount || $start <= 0 || $end <= $start || $paidAt < $start || $paidAt >= $end) {
            throw new PaymentException('驿汇客流水金额或支付时间不符合订单', 40200);
        }
        $extJson = (array) ($payOrder->ext_json ?? []);
        $context = (array) ($extJson['payment_context'] ?? []);
        $receipt = (array) ($context['channel_context'] ?? []);
        if (($context['pay_product'] ?? '') !== 'scan' || ($context['pay_action'] ?? '') !== 'addRenPage'
            || ($receipt['pay_no'] ?? '') !== $payNo || ($receipt['title'] ?? '') !== $this->receiptTitle($payNo) || (int) ($receipt['api_config_id'] ?? 0) !== (int) $this->getConfig('api_config_id')
            || (int) ($receipt['amount'] ?? 0) !== $amount || $this->timeValue($receipt['expire_at'] ?? null) !== $end
            || $this->timeValue($receipt['effective_at'] ?? null) <= 0 || $this->timeValue($receipt['effective_at']) > $paidAt
            || preg_match('/^[1-9][0-9]*$/D', (string) ($receipt['ren_id'] ?? '')) !== 1
            || preg_match('/^[1-9][0-9]*$/D', (string) ($receipt['dept_id'] ?? '')) !== 1) {
            throw new PaymentException('驿汇客流水缺少对应动态收款单上下文', 40200);
        }
        $metadata = (array) ($extJson['receipt_watcher'] ?? []);
        if (($metadata['plugin_code'] ?? '') !== $this->getCode() || ($metadata['pay_no'] ?? '') !== $payNo
            || (string) ($metadata['ren_id'] ?? '') !== (string) $receipt['ren_id']
            || ($metadata['dept_id'] ?? '') !== $receipt['dept_id']
            || ($metadata['identity'] ?? '') !== ($receipt['identity'] ?? '')
            || ($metadata['title'] ?? '') !== $receipt['title'] || ($metadata['amount'] ?? null) !== $amount
            || ($metadata['effective_at'] ?? '') !== $receipt['effective_at']
            || ($metadata['expire_at'] ?? '') !== $receipt['expire_at']) {
            throw new PaymentException('驿汇客创建快照与支付上下文不一致', 40200);
        }
        $bound = $this->payOrderRepository->findByReceiptChannelOrder($channelIds, $record['order_no'], ['pay_no']);
        if ($bound && (string) $bound->pay_no !== $payNo) {
            throw new PaymentException('驿汇客流水已关联其他支付单', 40200);
        }
        $status = (int) $payOrder->status;
        foreach (['channel_order_no', 'channel_trade_no'] as $field) {
            $existing = (string) ($payOrder->{$field} ?? '');
            if ($existing !== '' && $existing !== $record['order_no']) {
                throw new PaymentException('驿汇客支付单已绑定不同流水', 40200);
            }
        }
        if ($status === TradeConstant::ORDER_STATUS_SUCCESS) {
            if ((string) $payOrder->channel_order_no !== $record['order_no'] || (string) $payOrder->channel_trade_no !== $record['order_no']) {
                throw new PaymentException('驿汇客重复流水不符合已成功支付单', 40200);
            }
        } elseif (!in_array($status, TradeConstant::orderMutableStatuses(), true) || $this->nowTimestamp() >= $end) {
            throw new PaymentException('驿汇客支付单不可确认或已过期', 40200);
        }
        return [$payOrder, $record, $amount];
    }

    /**
     * 验证共享后台令牌及其商户、凭证摘要和真实流水验证窗口。
     *
     * Redis TTL 不代表平台令牌期限，可信窗口到期时等待 watcher 探测，不在下单时
     * 增加接口请求。旧小程序 Session 缺少后台令牌与商户上下文，不能复用。
     *
     * @return array<string, mixed> 当前配置经过流水探测的 shared_data
     * @throws PaymentDefinitiveException 信封、凭证、商户或有效期不匹配时抛出
     */
    protected function validatedSession(): array
    {
        $payload = $this->loadSessionEnvelope();
        $shared = $payload['shared_data'] ?? null;
        $private = $payload['session_data'] ?? null;
        $token = is_array($shared) ? ($shared['yhk_token'] ?? null) : null;
        $username = trim((string) $this->getConfig('watcher_username', ''));
        $password = trim((string) $this->getConfig('watcher_password', ''));
        $credentialHash = hash('sha256', hash('sha256', $username) . ':' . hash('sha256', $password));
        $now = $this->nowTimestamp();
        if ($username === '' || $password === '' || ($payload['schema_version'] ?? null) !== 1
            || ($payload['plugin_code'] ?? '') !== $this->getCode() || ($payload['account_key'] ?? '') !== $this->accountKey()
            || ($payload['runtime'] ?? '') !== 'direct' || !is_string($payload['config_hash'] ?? null) || $payload['config_hash'] === ''
            || !is_array($private) || !is_array($shared) || !is_string($token) || $token === ''
            || strpbrk($token, "\r\n") !== false || ($private['yhk_token'] ?? '') !== $token
            || ($shared['credential_hash'] ?? '') !== $credentialHash
            || !is_string($shared['dept_id'] ?? null) || preg_match('/^[1-9][0-9]*$/D', $shared['dept_id']) !== 1
            || ($private['dept_id'] ?? '') !== $shared['dept_id']
            || (int) ($shared['api_config_id'] ?? 0) !== (int) $this->getConfig('api_config_id', 0)
            || (int) ($payload['expires_at'] ?? 0) <= $now || (int) ($shared['last_probe_at'] ?? 0) <= 0
            || (int) ($shared['last_probe_at'] ?? 0) > $now || (int) ($shared['verified_until'] ?? 0) <= $now
            || ((int) ($shared['token_expires_at'] ?? 0) > 0 && (int) $shared['token_expires_at'] <= $now)) {
            throw new PaymentDefinitiveException('驿汇客缺少当前配置的有效后台登录态，请等待监听工具探测', 40200);
        }
        $enabled = $this->getConfig('proxy_enabled', false) === true;
        $proxyHash = $enabled ? hash('sha256', $this->proxyUrl()) : '';
        if (($shared['proxy_enabled'] ?? null) !== $enabled || ($shared['proxy_hash'] ?? '') !== $proxyHash) {
            throw new PaymentDefinitiveException('驿汇客登录态代理配置已变更，请重新探测', 40200);
        }
        $fingerprint = hash('sha256', json_encode([$credentialHash, $this->getConfig('receipt_valid_seconds', 300),
            $this->getConfig('receipt_watcher_query_interval_seconds', 5), $enabled, $proxyHash], JSON_THROW_ON_ERROR));
        if (($shared['config_fingerprint'] ?? '') !== $fingerprint) {
            throw new PaymentDefinitiveException('驿汇客登录态业务配置摘要不一致，请重新探测', 40200);
        }
        return $shared;
    }

    /**
     * 读取统一 Redis Session，读取失败不暴露内容。
     *
     * @return array<string, mixed> Session 信封，不直接作为令牌有效的证明
     */
    protected function loadSessionEnvelope(): array
    {
        try {
            $raw = Redis::get('receipt_watcher_session_' . $this->accountKey());
            return is_string($raw) && $raw !== '' ? (array) json_decode($raw, true, 512, JSON_THROW_ON_ERROR) : [];
        } catch (Throwable) {
            throw new PaymentDefinitiveException('驿汇客登录态读取失败', 40200);
        }
    }
    /**
     * 请求商户后台收款单接口，保留原始数字参数并校验 HTTP 与业务成功状态。
     *
     * @param string $action renovation 下的固定动作及必要查询参数
     * @param array<string, mixed>|null $params JSON 请求体；null 使用原接口的空 POST 请求体
     * @return mixed 已通过 HTTP 和业务状态校验的 data
     * @throws PaymentDefinitiveException HTTP 鉴权拒绝或业务明确拒绝时抛出
     * @throws PaymentUncertainException 通信及响应事实无法确认时抛出，不暴露原始响应
     */
    protected function receiptRequest(string $action, ?array $params = null): mixed
    {
        $this->assertPayLease();
        $session = $this->validatedSession();
        if ($this->payDeptId !== null && $session['dept_id'] !== $this->payDeptId) {
            throw new PaymentDefinitiveException('驿汇客建单期间商户上下文已变更', 40200);
        }
        $options = [
            'headers' => ['Authorization' => 'Bearer ' . $session['yhk_token'], 'client-type' => 'A',
                'x-secret-flg' => 'rnrn', 'polarday-version' => 'DEFAULT',
                'Content-Type' => 'application/json;charset=UTF-8', 'User-Agent' => self::USER_AGENT,
                'Referer' => self::API_URL . '/payfly-receipt'],
            'verify' => false, 'http_errors' => false, 'allow_redirects' => false, 'timeout' => 10, 'connect_timeout' => 10,
            'proxy' => $this->proxyUrl(),
        ];
        if ($params === null) {
            $options['body'] = '';
        } else {
            $options['json'] = $params;
        }
        try {
            $response = $this->request('POST', self::API_URL . self::RECEIPT_PATH . $action, $options);
        } catch (Throwable $e) {
            $transportCode = 'unknown';
            if ($e instanceof PaymentException) {
                $error = $e->getData()['error'] ?? null;
                if (is_string($error) && preg_match('/^cURL error ([0-9]{1,3}):/', $error, $matches) === 1) {
                    $transportCode = $matches[1];
                }
            }
            $this->recordReceiptFailure($action, 0, ['transport_code' => $transportCode]);
            throw new PaymentUncertainException('驿汇客接口通信结果不确定', 40200);
        }
        $status = $response->getStatusCode();
        if ($status === 401) {
            $this->recordReceiptFailure($action, $status);
            $this->rejectSession($session['yhk_token']);
            throw new PaymentDefinitiveException('驿汇客后台登录态失效，请等待监听工具恢复', 40200);
        }
        if ($status === 403) {
            $rejected = [];
            try {
                $body = $response->getBody()->read(2097153);
                $decoded = strlen($body) <= 2097152 ? json_decode($body, true, 512, JSON_THROW_ON_ERROR) : null;
                $rejected = is_array($decoded) ? $decoded : [];
            } catch (Throwable) {
                // HTTP 权限拒绝不要求正文为 JSON，不能据此把有效令牌判为失效。
            }
            $this->recordReceiptFailure($action, $status, $rejected);
            if (($rejected['code'] ?? null) === 401 || ($rejected['code'] ?? null) === '401') {
                $this->rejectSession($session['yhk_token']);
                throw new PaymentDefinitiveException('驿汇客后台登录态失效，请等待监听工具恢复', 40200);
            }
            $this->suspendSession($session['yhk_token']);
            throw new PaymentDefinitiveException('驿汇客后台权限被拒绝，请核对平台授权', 40200);
        }
        if ($status < 200 || $status >= 300) {
            $this->recordReceiptFailure($action, $status);
            throw new PaymentUncertainException('驿汇客接口 HTTP 状态异常', 40200);
        }
        try {
            $body = $response->getBody()->read(2097153);
            if (strlen($body) > 2097152) {
                throw new PaymentUncertainException('驿汇客响应超过限制', 40200);
            }
            $result = json_decode($body, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (Throwable) {
            $this->recordReceiptFailure($action, $status);
            throw new PaymentUncertainException('驿汇客响应解析失败', 40200);
        }
        if (!is_array($result) || (!is_int($result['code'] ?? null) && !is_string($result['code'] ?? null))) {
            $this->recordReceiptFailure($action, $status);
            throw new PaymentUncertainException('驿汇客响应缺少业务状态', 40200);
        }
        if ((string) $result['code'] === '401') {
            $this->recordReceiptFailure($action, $status, $result);
            $this->rejectSession($session['yhk_token']);
            throw new PaymentDefinitiveException('驿汇客后台登录态失效，请等待监听工具恢复', 40200);
        }
        if ((string) $result['code'] === '403') {
            $this->recordReceiptFailure($action, $status, $result);
            $this->suspendSession($session['yhk_token']);
            throw new PaymentDefinitiveException('驿汇客后台权限被拒绝，请核对平台授权', 40200);
        }
        if ((string) $result['code'] !== '200') {
            $this->recordReceiptFailure($action, $status, $result);
            throw new PaymentUncertainException('驿汇客接口业务状态未成功，变更结果无法确认', 40200);
        }
        return $result['data'] ?? null;
    }

    /**
     * 记录收款单失败阶段与受限响应摘要，不保存请求头、正文或原始异常。
     *
     * 业务说明只提取明确的错误类别；其他文本不进入日志，避免平台回显凭证。
     * traceId 仅接受 UUID 格式，诊断写入失败不影响原来的异常和重试语义。
     *
     * @param string $action renovation 接口动作，日志仅保留固定阶段名
     * @param int $status HTTP 状态，通信结果未知时为 0
     * @param array<string, mixed> $result 响应或通信摘要，仅提取 code、msg、traceId 和 transport_code
     */
    private function recordReceiptFailure(string $action, int $status, array $result = []): void
    {
        $stage = explode('/', $action, 2)[0];
        if (!in_array($stage, ['addRenPage', 'renEdit', 'status', 'qrcode'], true)) {
            $stage = 'unknown';
        }
        $code = is_int($result['code'] ?? null) || is_string($result['code'] ?? null)
            ? (string) $result['code'] : '';
        if (preg_match('/^[0-9]{1,8}$/D', $code) !== 1) {
            $code = 'unknown';
        }
        $traceId = is_string($result['traceId'] ?? null) ? $result['traceId'] : '';
        if (preg_match('/^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/Di', $traceId) !== 1) {
            $traceId = '';
        }
        $transportCode = is_string($result['transport_code'] ?? null) ? $result['transport_code'] : '';
        if (preg_match('/^[0-9]{1,3}$/D', $transportCode) !== 1) {
            $transportCode = '';
        }
        $message = is_string($result['msg'] ?? null) ? $result['msg'] : '';
        $hints = $message === '页面名称长度在1-20个字符之间' ? [$message] : [];
        foreach (['参数', '时间', '金额', '标题', '重复', '权限', '令牌', '登录', '过期', '限流', '系统异常', '系统错误'] as $hint) {
            if (str_contains($message, $hint)) {
                $hints[] = $hint;
            }
        }
        try {
            $this->writeReceiptFailure(['plugin_code' => $this->getCode(), 'stage' => $stage,
                'http_status' => $status, 'code' => $code, 'transport_code' => $transportCode, 'message_hint' => implode('、', $hints),
                'trace_id' => $traceId]);
        } catch (Throwable) {
        }
    }

    /**
     * 写入已脱敏的平台失败诊断，不增加接口请求。
     *
     * @param array{plugin_code: string, stage: string, http_status: int, code: string, transport_code: string, message_hint: string, trace_id: string} $diagnostic 受限摘要
     */
    protected function writeReceiptFailure(array $diagnostic): void
    {
        Log::warning('驿汇客收款单接口失败', $diagnostic);
    }

    /**
     * 将固定 PAY 前缀的支付单号无损映射为二十位页面标题。
     *
     * 仅接受系统生成的 PAY 加二十位数字格式；不截断数字或处理其他前缀。
     * Go 根据订单快照使用相同规则匹配，并在标准 channel 中恢复完整支付单号。
     *
     * @throws PaymentDefinitiveException 单号不符合已确认的系统格式时抛出
     */
    private function receiptTitle(string $payNo): string
    {
        if (preg_match('/^PAY[0-9]{20}$/D', $payNo) !== 1) {
            throw new PaymentDefinitiveException('驿汇客无法无损映射支付单号', 40200);
        }
        return substr($payNo, 3);
    }

    /**
     * 构造商户后台草稿组件，只有该接口边界把整数分转换为数字元。
     *
     * @param string $payNo 完整支付单号，移除固定 PAY 前缀后作为页面标题
     * @param int $amount 固定金额，单位分
     * @param array<string, mixed> $window 上海时区的秒级生效与截止时间
     * @return array<string, mixed> addRenPage 请求体，不添加未验证的 deptId 参数
     */
    protected function receiptPayload(string $payNo, int $amount, array $window): array
    {
        $yuan = $amount / 100;
        $encoded = json_encode($yuan, JSON_THROW_ON_ERROR);
        if (preg_match('/^(0|[1-9][0-9]*)(?:\.([0-9]{1,2}))?$/D', $encoded, $parts) !== 1
            || $this->yuanToCents($parts[1] . '.' . str_pad($parts[2] ?? '', 2, '0')) !== $amount) {
            throw new PaymentDefinitiveException('驿汇客金额无法精确编码为草稿数字元', 40200);
        }
        return [
            'confirmTwice' => 0,
            'copInfos' => [[
                'id' => '', 'type' => 'fixedPrice',
                'data' => ['title' => '支付金额', 'value' => $yuan],
                'options' => ['required' => false, 'screen' => false, 'likeQuery' => false,
                    'length' => null, 'copMin' => 1, 'copMax' => 1, 'copLimit' => false,
                    'timeType' => null, 'timeFormat' => null, 'rcStatus' => 1, 'copAboutAmt' => 1],
            ]],
            'renTitle' => $this->receiptTitle($payNo), 'useTemplate' => 0,
            'renExpirationDate' => $window['expire_at'], 'renEffectiveDate' => $window['effective_at'],
        ];
    }

    /**
     * 构造固定金额装修控件，父子组件金额均为整数分。
     *
     * 参数类型保持平台协议：父 composite 为字符串，子 composite 为整数，
     * copCustom 必须编码成空对象；固定金额由组件类型约束，不改写 copReadonly。
     *
     * @param int|string $renId 已创建的收款页面 ID
     * @param int $amount 原始固定金额，单位分
     * @return array<string, mixed> renEdit 请求体
     */
    protected function decorationPayload(int|string $renId, int $amount): array
    {
        $common = [
            'copHtmlClass' => null, 'copLabel' => '支付金额', 'copPrompt' => '',
            'copDefaultValue' => $amount, 'copValue' => $amount, 'copValueType' => 1,
            'copRequire' => 0, 'copQuery' => 0, 'copLikeQuery' => 0, 'copReadonly' => 0,
            'copSize' => null, 'copAboutAmt' => 1, 'copResourceShared' => 0,
            'copChecklist' => 1, 'copCustom' => (object) [],
        ];
        return ['copInfos' => [array_merge([
            'childCops' => [array_merge(['composite' => 1, 'copType' => '1'], $common, ['copId' => ''])],
            'composite' => '2', 'copType' => '105',
        ], $common, ['rcStatus' => 1])], 'renId' => $renId];
    }

    /**
     * 在短事务内收窄本地支付单有效期，网络请求在事务外执行。
     *
     * @param string $payNo 本地支付单号
     * @param int $amount 原始订单金额，单位分；不会修改业务金额
     * @return array{effective_at: string, expire_at: string} 上海时区的创建窗口
     */
    protected function prepareWindow(string $payNo, int $amount): array
    {
        return Db::transaction(function () use ($payNo, $amount): array {
            $order = $this->payOrderRepository->findForUpdateByPayNo($payNo);
            if (!$order || (string) $order->plugin_code !== $this->getCode()
                || (int) $order->channel_id !== (int) $this->getConfig('channel_id', 0)
                || (int) $order->pay_amount !== $amount || !in_array((int) $order->status, TradeConstant::orderMutableStatuses(), true)) {
                throw new PaymentDefinitiveException('驿汇客支付单归属、金额或状态不允许创建', 40200);
            }
            $now = $this->nowTimestamp();
            $end = $now + $this->validSeconds();
            $existing = $this->timeValue($order->expire_at);
            if ($existing > 0) {
                $end = min($end, $existing);
            }
            if ($end <= $now) {
                throw new PaymentDefinitiveException('驿汇客支付单已过期', 40200);
            }
            $order->expire_at = $this->datetime($end);
            $order->save();
            return ['effective_at' => $this->datetime($now), 'expire_at' => $this->datetime($end)];
        });
    }

    /**
     * 原子删除被本次请求拒绝的后台 Session，不删除刚刷新的另一份令牌。
     *
     * @param string $token 本次外呼使用的令牌，仅传给 Redis 比较，不写入日志
     */
    protected function rejectSession(string $token): void
    {
        $script = <<<'LUA'
local raw = redis.call('GET', KEYS[1])
if not raw then return 0 end
local p = cjson.decode(raw)
if not p.shared_data or p.shared_data.yhk_token ~= ARGV[1] then return 0 end
if not p.session_data or p.session_data.yhk_token ~= ARGV[1] then return 0 end
return redis.call('DEL', KEYS[1])
LUA;
        $this->redisRaw('EVAL', $script, 1, 'receipt_watcher_session_' . $this->accountKey(), $token);
    }

    /**
     * 原子撤销权限被拒绝令牌的共享有效态，保留私有凭证及原滑动 TTL。
     *
     * 仅操作仍与本次外呼一致的私有和共享令牌，不覆盖 watcher 刚刷新的登录态。
     *
     * @param string $token 本次使用的令牌，不进入日志或订单
     */
    protected function suspendSession(string $token): void
    {
        $script = <<<'LUA'
local raw = redis.call('GET', KEYS[1])
if not raw then return 0 end
local p = cjson.decode(raw)
if not p.shared_data or p.shared_data.yhk_token ~= ARGV[1] then return 0 end
if not p.session_data or p.session_data.yhk_token ~= ARGV[1] then return 0 end
local ttl = redis.call('PTTL', KEYS[1])
p.shared_data = {}
p.session_data.last_probe_at = 0
p.session_data.verified_until = 0
p.session_data.validation_state = 'credentials_only'
if ttl > 0 then
    return redis.call('SET', KEYS[1], cjson.encode(p), 'PX', ttl)
elseif ttl == -1 then
    return redis.call('SET', KEYS[1], cjson.encode(p))
end
return 0
LUA;
        $this->redisRaw('EVAL', $script, 1, 'receipt_watcher_session_' . $this->accountKey(), $token);
    }

    private function proxyUrl(): string
    {
        if ($this->getConfig('proxy_enabled', false) !== true) {
            return '';
        }
        $text = trim((string) $this->getConfig('receipt_proxy_url', ''));
        $parts = parse_url($text);
        if (!is_array($parts) || empty($parts['host']) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || isset($parts['query']) || isset($parts['fragment']) || !in_array($parts['path'] ?? '', ['', '/'], true)) {
            throw new PaymentDefinitiveException('驿汇客代理地址无效，请填写完整 HTTP 或 HTTPS 地址', 40200);
        }
        return $text;
    }

    private function receiptId(mixed $id): int|string
    {
        if ((!is_int($id) && !is_string($id)) || preg_match('/^[1-9][0-9]*$/D', (string) $id) !== 1) {
            throw new PaymentUncertainException('驿汇客响应缺少有效 renId', 40200);
        }
        return $id;
    }

    /**
     * 提取后续精确校验所需的非展示上下文。
     *
     * @param array<string, mixed> $progress 创建进度，不包含令牌或完整上游响应
     * @return array<string, mixed> 固定收款单标识、金额及有效期
     */
    private function receiptContext(string $payNo, array $progress): array
    {
        return ['identity' => $progress['identity'] ?? '', 'ren_id' => $progress['ren_id'] ?? null, 'title' => $progress['title'] ?? '', 'pay_no' => $payNo,
            'api_config_id' => (int) $this->getConfig('api_config_id', 0), 'amount' => (int) ($progress['amount'] ?? 0),
            'effective_at' => $progress['effective_at'] ?? '', 'expire_at' => $progress['expire_at'] ?? '',
            'dept_id' => $progress['dept_id'] ?? '', 'phase' => $progress['phase'] ?? ''];
    }

    /**
     * 构造含最小恢复上下文的不确定异常，不透传原始错误及凭证。
     *
     * @param array<string, mixed> $progress 已知的最小收款单上下文
     */
    private function uncertain(string $message, array $progress): PaymentUncertainException
    {
        return new PaymentUncertainException($message, 40200, ['pay_product' => 'scan', 'pay_action' => 'addRenPage',
            'channel_context' => $this->receiptContext((string) ($progress['pay_no'] ?? ''), $progress)]);
    }
    /**
     * 使用支付单级 Redis 租约串行创建，释放时原子校验所有者。
     *
     * @param string $payNo 本地支付单号
     * @param callable(): array<string, mixed> $callback 持锁的建单流程
     * @return array<string, mixed> 标准下单结果
     * @throws PaymentUncertainException 无法取得锁或确认租约所有权时抛出
     */
    protected function withPayLock(string $payNo, callable $callback): array
    {
        $key = 'mpay_xingyifu_yhk_pay_lock_' . $this->accountKey() . '_' . $payNo;
        $token = bin2hex(random_bytes(16));
        $locked = $this->redisRaw('SET', $key, $token, 'NX', 'EX', 90);
        if ($locked !== true && strtoupper((string) $locked) !== 'OK') {
            throw new PaymentUncertainException('驿汇客支付单正在创建，请稍后重试', 40200);
        }
        $this->payLockKey = $key;
        $this->payLockToken = $token;
        try {
            return $callback();
        } finally {
            $this->payLockKey = null;
            $this->payLockToken = null;
            $this->payDeptId = null;
            $script = "if redis.call('GET', KEYS[1]) == ARGV[1] then return redis.call('DEL', KEYS[1]) else return 0 end";
            $this->redisRaw('EVAL', $script, 1, $key, $token);
        }
    }

    /**
     * 在外呼及写创建进度前原子确认并延长租约，禁止过期锁的旧请求继续建单。
     */
    protected function assertPayLease(): void
    {
        if ($this->payLockKey === null || $this->payLockToken === null) {
            throw new PaymentUncertainException('驿汇客建单流程未持有支付单锁', 40200);
        }
        $script = "if redis.call('GET', KEYS[1]) == ARGV[1] then return redis.call('EXPIRE', KEYS[1], 90) else return 0 end";
        if ((int) $this->redisRaw('EVAL', $script, 1, $this->payLockKey, $this->payLockToken) !== 1) {
            throw new PaymentUncertainException('驿汇客支付单锁已失效，停止后续建单操作', 40200);
        }
    }

    /** @return array<string, mixed> 创建进度；损坏的缓存不能按不存在处理后重复创建 */
    protected function loadProgress(string $payNo): array
    {
        $raw = $this->redisRaw('GET', $this->progressKey($payNo));
        if ($raw === null || $raw === false) {
            return [];
        }
        try {
            $data = json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            if (!is_array($data) || $data === []) {
                throw new PaymentUncertainException('驿汇客创建进度结构无效', 40200);
            }
            return $data;
        } catch (Throwable) {
            throw new PaymentUncertainException('驿汇客创建进度读取失败，禁止重新创建', 40200);
        }
    }

    /**
     * 保存短期建单状态，保留到订单到期后一小时；失效订单不能靠缓存到期重新创建。
     *
     * @param string $payNo 本地支付单号
     * @param array<string, mixed> $progress 包含阶段、原始窗口和已知 renId 的进度
     */
    protected function saveProgress(string $payNo, array $progress): void
    {
        $this->assertPayLease();
        $ttl = max(3600, $this->timeValue($progress['expire_at'] ?? null) - $this->nowTimestamp() + 3600);
        $raw = json_encode($progress, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $saved = $this->redisRaw('SET', $this->progressKey($payNo), $raw, 'EX', $ttl);
        if ($saved !== true && strtoupper((string) $saved) !== 'OK') {
            throw new PaymentUncertainException('驿汇客创建进度保存失败', 40200);
        }
    }

    protected function redisRaw(string $command, mixed ...$arguments): mixed
    {
        try {
            return Redis::connection()->rawCommand($command, ...$arguments);
        } catch (Throwable) {
            throw new PaymentUncertainException('驿汇客 Redis 操作失败', 40200);
        }
    }

    private function progressKey(string $payNo): string
    {
        return 'mpay_xingyifu_yhk_create_' . $this->accountKey() . '_' . $payNo;
    }

    protected function accountKey(): string
    {
        return $this->getCode() . '_' . (int) $this->getConfig('api_config_id', 0);
    }

    protected function nowTimestamp(): int
    {
        return time();
    }

    private function validSeconds(): int
    {
        $seconds = $this->getConfig('receipt_valid_seconds', 300);
        if (!is_int($seconds) || $seconds < 60 || $seconds > 1800) {
            throw new PaymentDefinitiveException('驿汇客收款有效期必须为六十到一千八百秒的整数', 40200);
        }
        return $seconds;
    }

    /** 金额输入限定两位小数元字符串，转换过程中不使用浮点或舍入。 */
    private function yuanToCents(string $text): int
    {
        if (preg_match('/^(0|[1-9][0-9]*)\.([0-9]{2})$/D', $text, $parts) !== 1) {
            throw new PaymentException('驿汇客流水金额不是两位小数元字符串', 40200);
        }
        $whole = filter_var($parts[1], FILTER_VALIDATE_INT);
        $fraction = (int) $parts[2];
        if ($whole === false || $whole > intdiv(PHP_INT_MAX - $fraction, 100)) {
            throw new PaymentException('驿汇客流水金额超限', 40200);
        }
        $cents = $whole * 100 + $fraction;
        if ($cents <= 0) {
            throw new PaymentException('驿汇客流水金额必须为正数', 40200);
        }
        return $cents;
    }

    /** 严格解析上海时区秒级时间；缺失及无效时间返回零，不猜测其他格式。 */
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
    /**
     * 从核心已持久化的不确定上下文恢复页面 ID 和稳定阶段，避免重复变更。
     *
     * @param string $payNo 本地支付单号
     * @param array<string, mixed> $progress 外呼前保存的进行中阶段
     * @return array<string, mixed> 无精确稳定上下文时保留原进行中阶段，不猜测平台结果
     */
    private function restoreKnownReceipt(string $payNo, array $progress): array
    {
        $order = $this->payOrderRepository->findByPayNo($payNo);
        $ext = (array) ($order->ext_json ?? []);
        $context = (array) ($ext['payment_context'] ?? []);
        $receipt = (array) ($context['channel_context'] ?? []);
        $id = $receipt['ren_id'] ?? null;
        $targets = match ($progress['phase'] ?? '') {
            'creating' => ['created', 'decorated', 'published', 'complete'],
            'decorating' => ['decorated', 'published', 'complete'],
            'publishing' => ['published', 'complete'],
            default => [],
        };
        if (!$order || (string) $order->plugin_code !== $this->getCode()
            || (int) $order->channel_id !== (int) $this->getConfig('channel_id')
            || ($context['pay_product'] ?? '') !== 'scan' || ($context['pay_action'] ?? '') !== 'addRenPage'
            || ($receipt['pay_no'] ?? '') !== $payNo || ($receipt['title'] ?? '') !== $this->receiptTitle($payNo) || (int) ($receipt['api_config_id'] ?? 0) !== (int) $this->getConfig('api_config_id')
            || ($receipt['amount'] ?? null) !== ($progress['amount'] ?? null)
            || ($receipt['effective_at'] ?? '') !== ($progress['effective_at'] ?? '')
            || ($receipt['expire_at'] ?? '') !== ($progress['expire_at'] ?? '')
            || ($receipt['dept_id'] ?? '') !== ($progress['dept_id'] ?? '')
            || ($receipt['identity'] ?? '') !== ($progress['identity'] ?? '')
            || (isset($progress['ren_id']) && (string) $progress['ren_id'] !== (string) $id)
            || !in_array($receipt['phase'] ?? '', $targets, true)
            || (!is_int($id) && !is_string($id)) || preg_match('/^[1-9][0-9]*$/D', (string) $id) !== 1) {
            return $progress;
        }
        $progress['ren_id'] = $id;
        $progress['phase'] = $receipt['phase'];
        if ($progress['phase'] === 'complete') {
            $url = $ext['presentation']['pay_params']['qrcode'] ?? null;
            if (is_string($url) && $this->validReceiptUrl($url)) {
                $progress['qrcode'] = $url;
            } else {
                $progress['phase'] = 'published';
            }
        }
        $this->persistReceiptMetadata($payNo, $progress);
        $this->saveProgress($payNo, $progress);
        return $progress;
    }

    /**
     * 从本地已发布上下文恢复丢失的缓存，未知创建记录不得当作首次下单。
     *
     * 核心在调用插件前会预写 provisional context；空 channel_context 或只有
     * 固定空字段时不代表已经创建收款单。已完成上下文只复用展示链接，已发布
     * 上下文只允许读取二维码；其他阶段不自动重复变更。
     *
     * @return array<string, mixed> 无创建记录返回空数组，其余返回只读可恢复进度
     * @throws PaymentUncertainException 已有记录不完整、归属不符或变更结果未知时抛出
     */
    private function recoverMissingProgress(string $payNo, int $amount, string $payType, string $identity, string $deptId): array
    {
        $order = $this->payOrderRepository->findByPayNo($payNo);
        $ext = (array) ($order->ext_json ?? []);
        $context = (array) ($ext['payment_context'] ?? []);
        $metadata = (array) ($ext['receipt_watcher'] ?? []);
        $receipt = (array) ($context['channel_context'] ?? []);
        if ($metadata === [] && !$this->hasReceiptCreationEvidence($receipt)) {
            return [];
        }
        $known = $receipt !== [] ? $receipt : $metadata;
        if (!$order || (string) $order->plugin_code !== $this->getCode()
            || (int) $order->channel_id !== (int) $this->getConfig('channel_id')
            || (int) $order->pay_amount !== $amount || !in_array((int) $order->status, TradeConstant::orderMutableStatuses(), true)
            || ($context['pay_product'] ?? '') !== 'scan' || ($context['pay_action'] ?? '') !== 'addRenPage'
            || (isset($context['pay_type']) && $context['pay_type'] !== $payType)
            || ($receipt['pay_no'] ?? '') !== $payNo || ($receipt['title'] ?? '') !== $this->receiptTitle($payNo)
            || ($receipt['identity'] ?? '') !== $identity || ($receipt['amount'] ?? null) !== $amount
            || ($receipt['dept_id'] ?? '') !== $deptId
            || (int) ($receipt['api_config_id'] ?? 0) !== (int) $this->getConfig('api_config_id')
            || $this->timeValue($receipt['effective_at'] ?? null) <= 0
            || $this->timeValue($receipt['effective_at']) > $this->nowTimestamp()
            || $this->timeValue($receipt['expire_at'] ?? null) !== $this->timeValue($order->expire_at)
            || preg_match('/^[1-9][0-9]*$/D', (string) ($receipt['ren_id'] ?? '')) !== 1
            || !in_array($receipt['phase'] ?? '', ['published', 'complete'], true)) {
            throw $this->uncertain('驿汇客已有创建记录但缓存丢失，禁止重新创建或重复变更', $known);
        }
        $progress = $this->receiptContext($payNo, $receipt);
        $progress['phase'] = 'published';
        $url = $ext['presentation']['pay_params']['qrcode'] ?? null;
        if ($receipt['phase'] === 'complete' && is_string($url) && $this->validReceiptUrl($url)) {
            $progress['qrcode'] = $url;
            $progress['phase'] = 'complete';
        }
        $this->persistReceiptMetadata($payNo, $progress);
        $this->saveProgress($payNo, $progress);
        return $progress;
    }

    /**
     * 判断核心上下文是否包含可能已送达平台的创建事实。
     *
     * provisional context 可能在异常归档时被补成固定字段结构，但所有业务字段
     * 仍为空；这种占位数据不能阻止首次创建。真实创建意向会先写入
     * receipt_watcher，因此该命名空间非空时由调用方直接进入防重校验。
     *
     * @param array<string, mixed> $receipt 支付核心保存的渠道上下文
     */
    private function hasReceiptCreationEvidence(array $receipt): bool
    {
        if ((int) ($receipt['amount'] ?? 0) > 0 || trim((string) ($receipt['ren_id'] ?? '')) !== '') {
            return true;
        }

        foreach (['pay_no', 'title', 'identity', 'dept_id', 'effective_at', 'expire_at', 'phase'] as $field) {
            if (trim((string) ($receipt[$field] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    private function validReceiptUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
            || ($parts['host'] ?? '') !== 'yjf.postar.cn' || ($parts['path'] ?? '') !== '/minapayfly'
            || isset($parts['user']) || isset($parts['pass']) || empty($parts['query'])) {
            return false;
        }
        parse_str($parts['query'], $query);
        return is_string($query['codePlate'] ?? null) && $query['codePlate'] !== '';
    }

    /**
     * 在现有 watcher 命名空间保存创建标识，不修改金额或订单状态。
     *
     * 创建前保留意向，取得 renId 后补齐页面 ID；用于防止缓存丢失重复建单并供
     * Go 核对收款页面。数据不包含令牌、密码、展示参数或调度租约。
     *
     * @param array<string, mixed> $progress 已绑定支付单、商户和原始窗口的创建资料
     * @throws PaymentUncertainException 订单归属改变或已有标识冲突时抛出
     */
    protected function persistReceiptMetadata(string $payNo, array $progress): void
    {
        $this->assertPayLease();
        Db::transaction(function () use ($payNo, $progress): void {
            $order = $this->payOrderRepository->findForUpdateByPayNo($payNo);
            if (!$order || (string) $order->plugin_code !== $this->getCode()
                || (int) $order->channel_id !== (int) $this->getConfig('channel_id')
                || (int) $order->pay_amount !== (int) $progress['amount']
                || !in_array((int) $order->status, TradeConstant::orderMutableStatuses(), true)
                || $this->timeValue($order->expire_at) !== $this->timeValue($progress['expire_at'])) {
                throw $this->uncertain('驿汇客创建标识无法写入当前支付单', $progress);
            }
            $metadata = $this->receiptContext($payNo, $progress);
            unset($metadata['phase']);
            $metadata['plugin_code'] = $this->getCode();
            if (isset($metadata['ren_id'])) {
                $metadata['ren_id'] = (string) $metadata['ren_id'];
            }
            $ext = (array) ($order->ext_json ?? []);
            $existing = (array) ($ext['receipt_watcher'] ?? []);
            if ($existing !== [] && (($existing['identity'] ?? '') !== $metadata['identity']
                || (isset($existing['ren_id']) && (string) $existing['ren_id'] !== (string) ($metadata['ren_id'] ?? '')))) {
                throw $this->uncertain('驿汇客已有不同的收款页面标识，禁止覆盖', $progress);
            }
            $this->assertPayLease();
            $ext['receipt_watcher'] = $metadata;
            $order->ext_json = $ext;
            $order->save();
        });
    }
}
