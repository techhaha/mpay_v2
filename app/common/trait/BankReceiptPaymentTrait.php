<?php

declare(strict_types=1);

namespace app\common\trait;

use app\common\constant\PaymentPluginStatusConstant;
use app\common\util\FormatHelper;
use app\exception\PaymentDefinitiveException;
use app\exception\PaymentException;
use app\exception\UnsupportedPaymentOperationException;
use app\model\payment\PayOrder;
use app\repository\payment\trade\PayOrderRepository;
use DateTimeImmutable;
use DateTimeZone;
use DOMDocument;
use DOMXPath;
use support\Db;
use support\Request;
use support\Response;

/**
 * 银行公众号收款插件的公共支付和通知流程。
 *
 * 各银行提供固定公众号身份、银行正文解析及交易时间来源选择。此 trait 负责
 * 收款金额分配、消息发送方与卡尾号核对、唯一订单匹配和业务金额恢复；
 * 表单验签与 XML 结构检查也在此收口，但不读取微信数据库。
 */
trait BankReceiptPaymentTrait
{
    public function __construct(
        private readonly PayOrderRepository $payOrderRepository
    ) {
    }

    abstract protected function bankName(): string;

    abstract protected function officialAccountId(): string;

    abstract protected function officialAccountName(): string;

    /**
     * 解析银行 appmsg 的标题和描述。
     *
     * 仅明确的入账返回金额、卡尾号和插件选定的事件时间；明确的出账或普通消息
     * 返回 null。是否识别标题、资金方向及缺失交易时间时的回退由各银行决定。
     *
     * @return array{amount:int,card_tail:string,event_at:int}|null
     */
    abstract protected function parseBankDescription(string $title, string $description, int $messageTime): ?array;

    /** @return list<array<string, mixed>> 银行收款通道通用配置表单 */
    public function getConfigSchema(): array
    {
        return [
            [
                'type' => 'input',
                'field' => 'account_name',
                'title' => '收款户名',
                'value' => '',
                'validate' => [['required' => true, 'message' => '收款户名不能为空']],
            ],
            [
                'type' => 'input',
                'field' => 'card_number',
                'title' => '收款银行卡号',
                'value' => '',
                'validate' => [['required' => true, 'message' => '收款银行卡号不能为空']],
            ],
            [
                'type' => 'inputNumber',
                'field' => 'receipt_valid_seconds',
                'title' => '识别有效期(秒)',
                'value' => 300,
                'props' => ['min' => 60, 'max' => 1800, 'step' => 60],
            ],
            [
                'type' => 'inputNumber',
                'field' => 'amount_offset_max',
                'title' => '最大金额偏移(分)',
                'value' => 99,
                'props' => ['min' => 0, 'max' => 99, 'step' => 1],
            ],
            [
                'type' => 'password',
                'field' => 'webhook_secret',
                'title' => '监听通知密钥',
                'value' => '',
                'validate' => [['required' => true, 'message' => '监听通知密钥不能为空']],
            ],
            [
                'type' => 'inputNumber',
                'field' => 'webhook_time_tolerance',
                'title' => '通知时间容差(秒)',
                'value' => 300,
                'props' => ['min' => 30, 'max' => 1800, 'step' => 30],
            ],
        ];
    }

    /**
     * 在当前通道内分配收款识别信息并返回通用银行卡承接页。
     *
     * 仅按金额匹配：临时修改支付单应付金额，原业务金额保存在 personal_receipt。
     * 银行到账通知不含付款备注，因此不分配或读取备注识别码。
     *
     * @param array<string, mixed> $order 标准插件下单参数，金额单位分
     * @return array<string, mixed> 标准待支付结果
     * @throws PaymentDefinitiveException 配置无效或金额标识耗尽时抛出
     */
    public function pay(array $order): array
    {
        $accountName = trim((string) $this->getConfig('account_name', ''));
        $cardNumber = $this->configuredCardNumber();
        if ($accountName === '' || mb_strlen($accountName) > 100) {
            throw new PaymentDefinitiveException('银行卡收款户名配置无效', 40200);
        }
        $prepared = $this->prepareReceipt((string) ($order['pay_no'] ?? ''));
        $params = [
            '_page' => 'bankTransfer',
            'bank_name' => $this->bankName(),
            'account_name' => $accountName,
            'card_number' => $cardNumber,
            'amount' => FormatHelper::amount($prepared['pay_amount']),
            'original_amount' => FormatHelper::amount($prepared['original_amount']),
            'expire_at_timestamp' => $prepared['expire_at_seconds'],
        ];

        return $this->pendingPaymentResult($order, [
            'pay_page' => 'page',
            'pay_type' => 'bank',
            'pay_product' => 'receipt',
            'pay_action' => 'official_account_webhook',
            'pay_params' => $params,
        ]);
    }

    /**
     * 验证银行入账通知并定位唯一支付单。
     *
     * @return array{pay_no:string} 已匹配的支付单号
     * @throws PaymentException 非入账消息不能定位支付单时抛出
     */
    public function channelNotify(Request $request): array
    {
        $receipt = $this->receiptFromRequest($request);
        if ($receipt === null) {
            throw new PaymentException('非银行卡入账消息不能确认支付', 40200);
        }

        return ['pay_no' => $this->locatePayNo($receipt)];
    }

    /** @param array<string, mixed> $order 标准查单参数 */
    public function query(array $order): array
    {
        return [
            'status' => PaymentPluginStatusConstant::PENDING,
            'pay_no' => (string) ($order['pay_no'] ?? ''),
            'paid_amount' => null,
            'chan_order_no' => (string) ($order['chan_order_no'] ?? ''),
            'chan_trade_no' => (string) ($order['chan_trade_no'] ?? ''),
            'message' => '等待' . $this->officialAccountName() . '到账通知',
        ];
    }

    /** @param array<string, mixed> $order 标准关单参数 */
    public function close(array $order): array
    {
        return [
            'status' => PaymentPluginStatusConstant::CLOSED,
            'pay_no' => (string) ($order['pay_no'] ?? ''),
            'chan_order_no' => (string) ($order['chan_order_no'] ?? ''),
            'chan_trade_no' => (string) ($order['chan_trade_no'] ?? ''),
            'message' => '银行卡收款没有上游订单可关闭',
        ];
    }

    /** @param array<string, mixed> $order 标准退款参数 */
    public function refund(array $order): array
    {
        throw new UnsupportedPaymentOperationException('银行卡收款不支持接口退款', 40200);
    }

    /**
     * 重新验签、重新匹配订单，并在返回成功事实前恢复业务原金额。
     *
     * @return array<string, mixed> 标准支付成功结果，实付金额单位分
     * @throws PaymentException 通知不可信、匹配不唯一或金额不一致时抛出
     */
    public function notify(Request $request): array
    {
        $receipt = $this->receiptFromRequest($request);
        if ($receipt === null) {
            throw new PaymentException('非银行卡入账消息不能确认支付', 40200);
        }
        $payNo = $this->locatePayNo($receipt);
        $paidAmount = $this->restoreOriginalPayAmount($payNo, $receipt);

        return [
            'status' => PaymentPluginStatusConstant::SUCCESS,
            'pay_no' => $payNo,
            'paid_amount' => $paidAmount,
            'message' => $this->officialAccountName() . '入账通知',
            'chan_order_no' => $receipt['trade_no'],
            'chan_trade_no' => $receipt['trade_no'],
            'channel_status' => 'official_account_credit',
            'paid_at' => (new DateTimeImmutable('@' . $receipt['event_at']))
                ->setTimezone(new DateTimeZone('Asia/Shanghai'))->format('Y-m-d H:i:s'),
        ];
    }

    public function notifySuccess(): string|Response
    {
        return '200';
    }

    public function notifyFail(): string|Response
    {
        return '400';
    }

    private function configuredCardNumber(): string
    {
        $number = preg_replace('/[\s-]+/', '', (string) $this->getConfig('card_number', '')) ?? '';
        if (preg_match('/^\d{12,19}$/', $number) !== 1) {
            throw new PaymentDefinitiveException('收款银行卡号配置无效', 40200);
        }
        return $number;
    }

    private function channelId(): int
    {
        $id = (int) $this->getConfig('channel_id', 0);
        if ($id <= 0) {
            throw new PaymentDefinitiveException('银行卡收款通道无效', 40200);
        }
        return $id;
    }

    /**
     * 在通道行锁和支付单事务内分配可复用的识别标识。
     *
     * @return array{original_amount:int,pay_amount:int,expire_at_seconds:int}
     */
    private function prepareReceipt(string $payNo): array
    {
        $channelId = $this->channelId();
        if ($payNo === '') {
            throw new PaymentDefinitiveException('支付单号为空', 40200);
        }
        return Db::transaction(function () use ($payNo, $channelId): array {
            if (!Db::table('ma_payment_channel')->where('id', $channelId)->lockForUpdate()->first(['id'])) {
                throw new PaymentDefinitiveException('收款通道不存在', 40200);
            }
            $order = $this->payOrderRepository->findForUpdateByPayNo($payNo);
            if (!$order || (int) $order->channel_id !== $channelId) {
                throw new PaymentDefinitiveException('支付单不存在或不属于当前通道', 40200);
            }
            $ext = (array) ($order->ext_json ?? []);
            $previous = (array) ($ext['personal_receipt'] ?? []);
            $original = (int) ($previous['original_amount'] ?? $order->pay_amount);
            if ($original <= 0) {
                throw new PaymentDefinitiveException('支付金额必须大于零', 40200);
            }
            $now = time();
            if (($previous['bank_code'] ?? '') === $this->getCode()
                && (int) ($previous['expire_at_seconds'] ?? 0) > $now) {
                return [
                    'original_amount' => $original,
                    'pay_amount' => (int) $previous['receipt_amount'],
                    'expire_at_seconds' => (int) $previous['expire_at_seconds'],
                ];
            }
            $expires = $now + min(1800, max(60, (int) $this->getConfig('receipt_valid_seconds', 300)));
            $used = $this->payOrderRepository->listUsedReceiptAmounts(
                [$channelId], $payNo, date('Y-m-d H:i:s', $now)
            );
            $payAmount = $this->allocateReceiptAmount($original, $used);
            $expireAt = date('Y-m-d H:i:s', $expires);
            $order->pay_amount = $payAmount;
            $order->expire_at = $expireAt;
            $ext['personal_receipt'] = [
                'bank_code' => $this->getCode(),
                'original_amount' => $original,
                'receipt_amount' => $payAmount,
                'issued_at_seconds' => $now,
                'expire_at_seconds' => $expires,
            ];
            $order->ext_json = $ext;
            $order->save();

            return [
                'original_amount' => $original,
                'pay_amount' => $payAmount,
                'expire_at_seconds' => $expires,
            ];
        });
    }

    /** @param list<int> $usedAmounts 已占用金额，单位分 */
    private function allocateReceiptAmount(int $originalAmount, array $usedAmounts): int
    {
        $used = array_fill_keys($usedAmounts, true);
        $maxOffset = min(99, max(0, (int) $this->getConfig('amount_offset_max', 99)));
        for ($offset = 0; $offset <= $maxOffset; $offset++) {
            $candidate = $originalAmount + $offset;
            if (!isset($used[$candidate])) {
                return $candidate;
            }
        }
        throw new PaymentDefinitiveException('当前通道可用金额偏移已用尽', 40200);
    }

    /**
     * 校验监听工具的四字段表单并返回公众号消息。
     *
     * 签名原文为毫秒 timestamp、换行、secret，HMAC 密钥也是 secret。
     * 验签不代替对消息发送方、银行入账事实和支付单归属的后续检查。
     *
     * @return array<string, mixed> 已通过表单和公众号来源检查的事件
     * @throws PaymentException 签名、时间、事件或公众号来源不符合协议时抛出
     */
    private function verifiedWebhookPayload(Request $request): array
    {
        $fields = (array) $request->all();
        $timestamp = trim((string) ($fields['timestamp'] ?? ''));
        $sign = trim((string) ($fields['sign'] ?? ''));
        $secret = trim((string) $this->getConfig('webhook_secret', ''));
        if (preg_match('/^\d{13}$/', $timestamp) !== 1 || $sign === '' || $secret === '') {
            throw new PaymentException('监听通知签名参数不完整', 40200);
        }
        $tolerance = min(1800, max(30, (int) $this->getConfig('webhook_time_tolerance', 300)));
        if (abs(time() - intdiv((int) $timestamp, 1000)) > $tolerance) {
            throw new PaymentException('监听通知时间已失效', 40200);
        }
        $expected = base64_encode(hash_hmac('sha256', $timestamp . "\n" . $secret, $secret, true));
        if (!hash_equals($expected, rawurldecode($sign))) {
            throw new PaymentException('监听通知签名校验失败', 40200);
        }
        $content = (string) ($fields['content'] ?? '');
        if ($content === '' || strlen($content) > 2 * 1024 * 1024) {
            throw new PaymentException('监听通知内容为空或过大', 40200);
        }
        $payload = json_decode($content, true);
        if (!is_array($payload) || array_is_list($payload)
            || ($payload['event'] ?? '') !== 'wxtool.officialAccount.message') {
            throw new PaymentException('监听通知不是公众号完整消息', 40200);
        }
        $id = $this->officialAccountId();
        if ((string) ($fields['from'] ?? '') !== $id
            || (string) ($payload['account']['username'] ?? '') !== $id
            || (string) ($payload['message']['username'] ?? '') !== $id
            || (string) ($payload['message']['source'] ?? '') !== 'message'
            || ($payload['message']['isFallback'] ?? false) !== false) {
            throw new PaymentException('通知来源不是' . $this->officialAccountName() . '的完整消息', 40200);
        }
        return $payload;
    }

    /**
     * 从已验签的原始 XML 提取银行入账事实；出账和非交易消息返回 null。
     *
     * @return array{amount:int,card_tail:string,event_at:int,trade_no:string,message_time:int}|null
     */
    private function receiptFromRequest(Request $request): ?array
    {
        $payload = $this->verifiedWebhookPayload($request);
        $message = (array) $payload['message'];
        if (($message['direction'] ?? '') === 'outgoing') {
            return null;
        }
        if (($message['direction'] ?? '') !== 'incoming'
            || (string) ($message['senderUsername'] ?? '') !== $this->officialAccountId()) {
            throw new PaymentException('公众号消息发送方无法验证', 40200);
        }
        if ((int) ($message['localType'] ?? 0) !== 21474836529) {
            return null;
        }
        $messageTime = (int) ($message['createTime'] ?? 0);
        if ($messageTime <= 0 || $messageTime > time() + 300) {
            throw new PaymentException('公众号消息时间无效', 40200);
        }
        [$title, $description] = $this->appMessage((string) ($message['content'] ?? ''));
        $receipt = $this->parseBankDescription($title, $description, $messageTime);
        if ($receipt === null) {
            return null;
        }
        if ($receipt['amount'] <= 0 || preg_match('/^\d{4}$/', $receipt['card_tail']) !== 1) {
            throw new PaymentException('银行入账金额或卡尾号无效', 40200);
        }
        if (!hash_equals(substr($this->configuredCardNumber(), -4), $receipt['card_tail'])) {
            throw new PaymentException('银行入账卡尾号与当前通道不一致', 40200);
        }
        $receipt['trade_no'] = $this->messageTradeNo($payload, $message, $messageTime);
        $receipt['message_time'] = $messageTime;
        return $receipt;
    }

    /**
     * 使用微信消息身份生成重投稳定、不同消息互异的本地渠道编号。
     *
     * messageKey 必须与同条完整消息的公众号和游标字段一致；投递路由和重试
     * 次数不参与编号，避免同一消息送往不同通道时生成不同交易事实。
     */
    private function messageTradeNo(array $payload, array $message, int $messageTime): string
    {
        $key = (string) ($payload['delivery']['messageKey'] ?? '');
        $parts = explode(':', $key);
        if (count($parts) !== 5
            || !ctype_digit($parts[0]) || (int) $parts[0] <= 0
            || $parts[1] !== $this->officialAccountId()
            || !ctype_digit($parts[2]) || !ctype_digit($parts[4])
            || $parts[2] !== (string) ($message['sortSeq'] ?? '')
            || $parts[3] !== (string) $messageTime
            || $parts[4] !== (string) ($message['localId'] ?? '')) {
            throw new PaymentException('公众号消息缺少一致的消息标识', 40200);
        }
        $time = (new DateTimeImmutable('@' . $messageTime))
            ->setTimezone(new DateTimeZone('Asia/Shanghai'))->format('YmdHis');
        return 'BR' . $time . substr(hash('sha256', $key), 0, 16);
    }

    /**
     * 提取唯一的 appmsg 标题和描述，拒绝外部实体及歧义结构。
     *
     * @return array{0:string,1:string} 标题和描述
     * @throws PaymentException 消息过大、XML 不合法或字段不唯一时抛出
     */
    private function appMessage(string $content): array
    {
        if ($content === '' || strlen($content) > 2 * 1024 * 1024
            || stripos($content, '<!DOCTYPE') !== false
            || stripos($content, '<!ENTITY') !== false) {
            throw new PaymentException('银行公众号消息 XML 不合法', 40200);
        }
        $old = libxml_use_internal_errors(true);
        try {
            $xml = new DOMDocument();
            if (!$xml->loadXML($content, LIBXML_NONET | LIBXML_NOBLANKS)) {
                throw new PaymentException('银行公众号消息 XML 解析失败', 40200);
            }
            $xpath = new DOMXPath($xml);
            $titles = $xpath->query('/msg/appmsg/title');
            $descriptions = $xpath->query('/msg/appmsg/des');
            if ($titles === false || $descriptions === false
                || $titles->length !== 1 || $descriptions->length !== 1) {
                throw new PaymentException('银行公众号消息缺少唯一标题或描述', 40200);
            }
            return [trim($titles->item(0)->textContent), trim($descriptions->item(0)->textContent)];
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($old);
        }
    }

    /**
     * 从独立描述行读取唯一字段，拒绝重复字段造成的方向或金额歧义。
     *
     * @throws PaymentException 字段缺失或重复时抛出
     */
    protected function bankField(string $description, string $label): string
    {
        $value = $this->optionalBankField($description, $label);
        if ($value === null) {
            throw new PaymentException('银行交易提醒字段缺失或重复：' . $label, 40200);
        }
        return $value;
    }

    /**
     * 读取可缺省的银行描述字段；已出现但为空或重复仍拒绝。
     *
     * @throws PaymentException 字段为空或重复时抛出
     */
    protected function optionalBankField(string $description, string $label): ?string
    {
        $values = [];
        foreach (preg_split('/\R/u', $description) ?: [] as $line) {
            if (preg_match('/^\s*' . preg_quote($label, '/') . '\s*[:：]\s*(.*?)\s*$/u', $line, $match) === 1) {
                $values[] = trim($match[1]);
            }
        }
        if ($values === []) {
            return null;
        }
        if (count($values) !== 1 || $values[0] === '') {
            throw new PaymentException('银行交易提醒字段缺失或重复：' . $label, 40200);
        }
        return $values[0];
    }

    /** 将银行金额文本精确转为整数分，不使用浮点数。 */
    protected function bankAmount(string $value): int
    {
        $value = trim($value);
        if (preg_match('/^(?:\d{1,3}(?:,\d{3})+|\d+)(?:\.\d{1,2})?$/', $value) !== 1) {
            throw new PaymentException('银行入账金额格式不合法', 40200);
        }
        $value = str_replace(',', '', $value);
        [$yuan, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        if (strlen($yuan) > 12) {
            throw new PaymentException('银行入账金额超出支持范围', 40200);
        }
        $cents = (int) $yuan * 100 + (int) str_pad($fraction, 2, '0');
        if ($cents <= 0) {
            throw new PaymentException('银行入账金额必须大于零', 40200);
        }
        return $cents;
    }

    /** 将各插件已解析的中国标准时间分量转换为 Unix 秒。 */
    protected function bankTimestamp(int $year, int $month, int $day, int $hour, int $minute, int $second): int
    {
        if (!checkdate($month, $day, $year) || $hour > 23 || $minute > 59 || $second > 59) {
            throw new PaymentException('银行交易时间超出有效范围', 40200);
        }
        $value = DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            sprintf('%04d-%02d-%02d %02d:%02d:%02d', $year, $month, $day, $hour, $minute, $second),
            new DateTimeZone('Asia/Shanghai')
        );
        if ($value === false) {
            throw new PaymentException('银行交易时间无法解析', 40200);
        }
        return $value->getTimestamp();
    }

    /**
     * 先查已确认的消息指纹，再从当前通道有效订单中唯一匹配应付金额。
     *
     * @param array{amount:int,card_tail:string,event_at:int,trade_no:string,message_time:int} $receipt
     * @throws PaymentException 候选订单不是唯一或识别窗口不符时抛出
     */
    private function locatePayNo(array $receipt): string
    {
        $channelId = $this->channelId();
        $existing = $this->payOrderRepository->findByReceiptChannelOrder(
            [$channelId], $receipt['trade_no'], ['pay_no', 'pay_amount', 'ext_json']
        );
        if ($existing && $this->receiptMatchesOrder($existing, $receipt)) {
            return (string) $existing->pay_no;
        }
        $orders = $this->payOrderRepository->listMutableReceiptOrders(
            [$channelId], date('Y-m-d H:i:s'), ['pay_no', 'pay_amount', 'ext_json']
        );
        $matches = [];
        foreach ($orders as $order) {
            if ($this->receiptMatchesOrder($order, $receipt)) {
                $matches[] = (string) $order->pay_no;
            }
        }
        if (count($matches) !== 1) {
            throw new PaymentException('银行入账未匹配到唯一有效支付单', 40200, [
                'matched_count' => count($matches),
            ]);
        }
        return $matches[0];
    }

    /**
     * 同时校验原金额、可见应付金额和生成标识时的有效窗口。
     *
     * @param array<string, mixed> $receipt 已解析的银行入账事实
     */
    private function receiptMatchesOrder(PayOrder $order, array $receipt): bool
    {
        $meta = (array) (((array) ($order->ext_json ?? []))['personal_receipt'] ?? []);
        if (($meta['bank_code'] ?? '') !== $this->getCode()
            || (int) ($meta['original_amount'] ?? 0) <= 0
            || (int) ($meta['receipt_amount'] ?? 0) !== $receipt['amount']) {
            return false;
        }
        $storedAmount = (int) $order->pay_amount;
        if ($storedAmount !== (int) $meta['receipt_amount']
            && $storedAmount !== (int) $meta['original_amount']) {
            return false;
        }
        $storedTradeNo = (string) ($meta['notified_trade_no'] ?? '');
        if ($storedTradeNo !== '' && !hash_equals($storedTradeNo, $receipt['trade_no'])) {
            return false;
        }
        $issued = (int) ($meta['issued_at_seconds'] ?? 0);
        $expires = (int) ($meta['expire_at_seconds'] ?? 0);
        if ($issued <= 0 || $expires <= $issued
            || $receipt['event_at'] < $issued - 59
            || $receipt['event_at'] > $expires
            || $receipt['message_time'] < $issued
            || $receipt['message_time'] > $expires + 300) {
            return false;
        }
        return true;
    }

    /**
     * 在支付单锁内恢复原业务金额，避免偏移金额污染业务统计。
     *
     * @param array<string, mixed> $receipt 已解析且唯一匹配的入账事实
     * @return int 原业务金额，单位分
     */
    private function restoreOriginalPayAmount(string $payNo, array $receipt): int
    {
        return Db::transaction(function () use ($payNo, $receipt): int {
            $order = $this->payOrderRepository->findForUpdateByPayNo($payNo);
            if (!$order) {
                throw new PaymentException('银行入账与支付单不一致', 40200);
            }
            $original = $this->applyReceiptToOrder($order, $receipt);
            $order->save();
            return $original;
        });
    }

    /**
     * 验证银行通知归属并恢复原业务金额；调用方负责在锁内保存。
     *
     * 重复的同一通知可再次得到相同原金额，另一条通知不能覆盖已绑定指纹。
     *
     * @param array<string, mixed> $receipt 已解析的银行入账事实
     * @return int 原业务金额，单位分
     */
    private function applyReceiptToOrder(PayOrder $order, array $receipt): int
    {
        if ((int) $order->channel_id !== $this->channelId()
            || !$this->receiptMatchesOrder($order, $receipt)) {
            throw new PaymentException('银行入账与支付单不一致', 40200);
        }
        $ext = (array) ($order->ext_json ?? []);
        $meta = (array) ($ext['personal_receipt'] ?? []);
        $original = (int) $meta['original_amount'];
        $order->pay_amount = $original;
        $meta['notified_trade_no'] = $receipt['trade_no'];
        $ext['personal_receipt'] = $meta;
        $order->ext_json = $ext;
        return $original;
    }
}
