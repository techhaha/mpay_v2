<?php

declare(strict_types=1);

namespace app\common\payment;

use app\common\base\BasePayment;
use app\common\constant\PaymentPluginStatusConstant;
use app\common\constant\PaymentPluginTypeConstant;
use app\common\interface\ChannelNotifyPayloadInterface;
use app\common\interface\PaymentInterface;
use app\common\interface\PayPluginInterface;
use app\common\util\FormatHelper;
use app\exception\PaymentException;
use app\model\payment\PayOrder;
use app\repository\payment\config\PaymentChannelRepository;
use app\repository\payment\trade\PayOrderRepository;
use support\Cache;
use support\Db;
use support\Request;
use support\Response;

/**
 * USDT TRC20 地址入账监听插件。
 *
 * 后端只负责换算应付 USDT、分配收款地址和匹配 watcher 投递的链上入账流水；
 * 链上查询由 receipt_watcher 的 TronGrid 适配器完成。
 */
class UsdtTrc20ReceiptPayment extends BasePayment implements PaymentInterface, PayPluginInterface, ChannelNotifyPayloadInterface
{
    private const CODE = 'usdt_trc20_receipt';
    private const NETWORK = 'TRC20';
    private const TOKEN_SYMBOL = 'USDT';
    private const DEFAULT_USDT_CONTRACT = 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t';
    private const TOKEN_DECIMALS = 6;
    private const DISPLAY_DECIMALS = 3;
    private const DISPLAY_UNIT_ATOMIC = 1000;
    private const DEFAULT_OFFSET_MAX = 999;
    private const EXT_KEY = 'receipt_watcher';

    /**
     * 插件基础信息。
     *
     * @var array<string, mixed>
     */
    protected array $paymentInfo = [
        'code' => self::CODE,
        'name' => 'USDT TRC20 收款',
        'plugin_type' => PaymentPluginTypeConstant::TYPE_BACKEND,
        'author' => 'MPAY',
        'version' => '1.0.0',
        'pay_types' => ['usdt'],
        'transfer_types' => [],
    ];

    /**
     * 构造方法。
     *
     * @param PayOrderRepository $payOrderRepository 支付单仓库
     * @param PaymentChannelRepository $paymentChannelRepository 支付通道仓库
     */
    public function __construct(
        private readonly PayOrderRepository $payOrderRepository,
        private readonly PaymentChannelRepository $paymentChannelRepository
    ) {
    }

    /**
     * 发起 USDT TRC20 收款。
     *
     * @param array<string, mixed> $order 支付单快照
     * @return array<string, mixed> 下单结果
     */
    public function pay(array $order): array
    {
        $payNo = (string) $order['pay_no'];
        $prepared = $this->prepareUsdtReceipt($payNo);

        $params = [
            '_page' => 'usdtTrc20',
            'network' => self::NETWORK,
            'token_symbol' => self::TOKEN_SYMBOL,
            'receive_address' => $prepared['receive_address'],
            'qrcode' => $prepared['receive_address'],
            'amount' => $prepared['display_amount'],
            'usdt_amount' => $prepared['display_amount'],
            'usdt_amount_atomic' => $prepared['amount_atomic'],
            'original_amount' => FormatHelper::amount((int) $prepared['original_amount']),
            'usdt_cny_rate' => $prepared['usdt_cny_rate'],
            'receipt_valid_seconds' => $this->receiptValidSeconds(),
            'expire_at' => (string) $prepared['expire_at'],
            'expire_at_timestamp' => (int) strtotime((string) $prepared['expire_at']),
            'server_time_timestamp' => time(),
            'description' => '请使用 TRC20 网络按页面金额转入 USDT。',
            'tips' => '转账金额必须与页面显示完全一致，网络请选择 TRC20。',
        ];

        return [
            'pay_page' => 'page',
            'pay_type' => (string) ($order['pay_type_code'] ?? 'usdt'),
            'pay_product' => 'trc20',
            'pay_action' => 'address',
            'pay_params' => $params,
            'chan_order_no' => $payNo,
            'chan_trade_no' => '',
        ];
    }

    /**
     * 主动查单由 receipt_watcher 完成。
     *
     * @param array<string, mixed> $order 支付单快照
     * @return array<string, mixed> 查询结果
     */
    public function query(array $order): array
    {
        return [
            'success' => true,
            'status' => PaymentPluginStatusConstant::PENDING,
            'channel_order_no' => (string) ($order['channel_order_no'] ?? $order['pay_no'] ?? ''),
            'channel_trade_no' => (string) ($order['channel_trade_no'] ?? ''),
            'message' => '等待 receipt_watcher 查询 USDT TRC20 入账流水',
        ];
    }

    /**
     * USDT 地址收款不需要通知上游关单。
     *
     * @param array<string, mixed> $order 支付单快照
     * @return array<string, mixed> 关闭结果
     */
    public function close(array $order): array
    {
        return [
            'success' => true,
            'msg' => 'USDT TRC20 收款无需上游关单',
        ];
    }

    /**
     * 链上收款不支持接口退款。
     *
     * @param array<string, mixed> $order 支付单快照
     * @return array<string, mixed> 退款结果
     */
    public function refund(array $order): array
    {
        throw new PaymentException('USDT TRC20 收款不支持接口退款', 40200);
    }

    /**
     * 兼容 HTTP 手工重放入口。
     *
     * @param Request $request 请求对象
     * @return array<string, mixed> 回调结果
     */
    public function notify(Request $request): array
    {
        return $this->notifyPayload((array) $request->all());
    }

    /**
     * 根据链上流水定位支付单。
     *
     * @param array<string, mixed> $payload watcher 投递载荷
     * @return array{pay_no:string} 定位结果
     */
    public function channelNotifyPayload(array $payload): array
    {
        return ['pay_no' => $this->locatePayNo($payload)];
    }

    /**
     * 生成标准支付成功通知。
     *
     * @param array<string, mixed> $payload watcher 投递载荷
     * @return array<string, mixed> 插件通知结果
     */
    public function notifyPayload(array $payload): array
    {
        $record = $this->recordPayload($payload);
        $payNo = $this->locatePayNo($payload);
        $tradeNo = $this->channelTradeNo($record);
        $this->persistNotifyMeta($payNo, $record, $tradeNo);

        return [
            'status' => PaymentPluginStatusConstant::SUCCESS,
            'pay_no' => $payNo,
            'message' => 'receipt_watcher 已确认 USDT TRC20 入账流水',
            'channel_order_no' => $tradeNo,
            'channel_trade_no' => $tradeNo,
            'channel_status' => 'trc20_confirmed',
            'paid_at' => $this->paidAtFromRecord($record),
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
     * 返回后台配置表单。
     *
     * @return array<int, array<string, mixed>>
     */
    public function getConfigSchema(): array
    {
        return [
            [
                'type' => 'textarea',
                'field' => 'trc20_addresses',
                'title' => 'TRC20 收款地址池',
                'value' => '',
                'props' => [
                    'rows' => 6,
                    'placeholder' => "一行一个 TRC20 地址\n地址池未占满时优先独占地址",
                ],
                'validate' => [
                    ['required' => true, 'message' => '请填写 TRC20 收款地址池'],
                ],
            ],
            [
                'type' => 'inputNumber',
                'field' => 'usdt_cny_rate',
                'title' => 'USDT 汇率',
                'value' => 7,
                'props' => [
                    'min' => 0.000001,
                    'step' => 0.01,
                    'precision' => 6,
                    'tip' => '填写 1 USDT 等于多少人民币。系统按 人民币金额 / 汇率 向上保留 3 位小数。',
                ],
                'validate' => [
                    ['required' => true, 'message' => '请填写 USDT 汇率'],
                ],
            ],
            [
                'type' => 'input',
                'field' => 'usdt_contract_address',
                'title' => 'USDT 合约地址',
                'value' => self::DEFAULT_USDT_CONTRACT,
                'props' => [
                    'placeholder' => self::DEFAULT_USDT_CONTRACT,
                ],
            ],
            [
                'type' => 'input',
                'field' => 'trongrid_base_url',
                'title' => 'TronGrid 地址',
                'value' => 'https://api.trongrid.io',
                'props' => [
                    'placeholder' => 'https://api.trongrid.io',
                ],
            ],
            [
                'type' => 'password',
                'field' => 'trongrid_api_key',
                'title' => 'TronGrid API Key',
                'value' => '',
                'props' => [
                    'tip' => '可为空；为空时使用公共限流额度。',
                ],
            ],
            [
                'type' => 'inputNumber',
                'field' => 'receipt_valid_seconds',
                'title' => '订单有效期(秒)',
                'value' => 300,
                'props' => [
                    'min' => 60,
                    'step' => 30,
                ],
            ],
            [
                'type' => 'inputNumber',
                'field' => 'receipt_watcher_query_interval_seconds',
                'title' => '账号查询间隔(秒)',
                'value' => 3,
                'props' => [
                    'min' => 2,
                    'step' => 1,
                ],
            ],
            [
                'type' => 'inputNumber',
                'field' => 'usdt_amount_offset_max',
                'title' => '金额偏移最大步数',
                'value' => self::DEFAULT_OFFSET_MAX,
                'props' => [
                    'min' => 0,
                    'max' => 999,
                    'step' => 1,
                    'tip' => '地址池全部占用后，按 0.001000 USDT 递增，默认最多 999 步。',
                ],
            ],
        ];
    }

    /**
     * 分配 USDT 收款地址和金额。
     *
     * @param string $payNo 支付单号
     * @return array<string, mixed> 收款参数
     */
    private function prepareUsdtReceipt(string $payNo): array
    {
        return $this->withAccountLock(function () use ($payNo): array {
            return Db::transaction(function () use ($payNo): array {
                $payOrder = $this->lockedPayOrder($payNo);
                $originalAmount = $this->originalAmount($payOrder);
                $baseAtomic = $this->cnyCentsToUsdtAtomic($originalAmount);
                $expireAt = $this->expireAt();
                $selection = $this->selectAddressAndAmount($payNo, $baseAtomic);
                $tokenContract = $this->tokenContract();
                $rate = $this->rateText();

                $meta = [
                    'platform' => self::CODE,
                    'network' => self::NETWORK,
                    'token_symbol' => self::TOKEN_SYMBOL,
                    'token_contract' => $tokenContract,
                    'token_decimals' => self::TOKEN_DECIMALS,
                    'receive_address' => $selection['address'],
                    'original_amount' => $originalAmount,
                    'cny_amount' => FormatHelper::amount($originalAmount),
                    'usdt_cny_rate' => $rate,
                    'base_amount_atomic' => $baseAtomic,
                    'amount_atomic' => $selection['amount_atomic'],
                    'amount' => $this->atomicToUsdtText($selection['amount_atomic']),
                    'display_amount' => $this->atomicToDisplayText($selection['amount_atomic']),
                    'offset_atomic' => $selection['amount_atomic'] - $baseAtomic,
                    'offset_step_atomic' => self::DISPLAY_UNIT_ATOMIC,
                    'expire_at' => $expireAt,
                ];

                $this->persistReceiptMeta($payOrder, $meta);

                return [
                    'original_amount' => $originalAmount,
                    'token_contract' => $tokenContract,
                    'receive_address' => $selection['address'],
                    'amount_atomic' => $selection['amount_atomic'],
                    'amount' => $meta['amount'],
                    'display_amount' => $meta['display_amount'],
                    'usdt_cny_rate' => $rate,
                    'expire_at' => $expireAt,
                ];
            });
        });
    }

    /**
     * 选择地址和唯一 USDT 金额。
     *
     * @param string $payNo 当前支付单号
     * @param int $baseAtomic 基础 USDT 原子金额
     * @return array{address:string, amount_atomic:int}
     */
    private function selectAddressAndAmount(string $payNo, int $baseAtomic): array
    {
        $addresses = $this->addressPool();
        $active = $this->activeUsdtReceipts($payNo);
        $activeByAddress = [];
        foreach ($active as $receipt) {
            $address = (string) ($receipt['receive_address'] ?? '');
            if ($address === '') {
                continue;
            }
            $activeByAddress[$address][] = (int) ($receipt['amount_atomic'] ?? 0);
        }

        foreach ($addresses as $address) {
            if (!isset($activeByAddress[$address])) {
                return ['address' => $address, 'amount_atomic' => $baseAtomic];
            }
        }

        usort($addresses, static function (string $left, string $right) use ($activeByAddress): int {
            return count($activeByAddress[$left] ?? []) <=> count($activeByAddress[$right] ?? []);
        });

        $maxOffset = $this->amountOffsetMax();
        foreach ($addresses as $address) {
            $used = array_fill_keys($activeByAddress[$address] ?? [], true);
            for ($offset = 0; $offset <= $maxOffset; $offset++) {
                $candidate = $baseAtomic + ($offset * self::DISPLAY_UNIT_ATOMIC);
                if (!isset($used[$candidate])) {
                    return ['address' => $address, 'amount_atomic' => $candidate];
                }
            }
        }

        throw new PaymentException('当前 USDT 地址池可用金额偏移已用尽', 40200, [
            'api_config_id' => (int) $this->getConfig('api_config_id'),
            'max_offset' => $maxOffset,
        ]);
    }

    /**
     * 查询当前账号下有效订单的 USDT 收款信息。
     *
     * @param string $excludePayNo 排除的支付单号
     * @return array<int, array<string, mixed>> 收款信息列表
     */
    private function activeUsdtReceipts(string $excludePayNo): array
    {
        $orders = $this->payOrderRepository->listMutableReceiptOrders(
            $this->receiptChannelIds(),
            date('Y-m-d H:i:s'),
            ['pay_no', 'ext_json']
        );
        $receipts = [];
        foreach ($orders as $order) {
            if ((string) $order->pay_no === $excludePayNo) {
                continue;
            }

            $extJson = (array) ($order->ext_json ?? []);
            $receipt = (array) ($extJson[self::EXT_KEY] ?? []);
            if ($receipt !== []) {
                $receipts[] = $receipt;
            }
        }

        return $receipts;
    }

    /**
     * 写入 USDT 收款元数据。
     *
     * @param PayOrder $payOrder 支付单
     * @param array<string, mixed> $meta 元数据
     * @return void
     */
    private function persistReceiptMeta(PayOrder $payOrder, array $meta): void
    {
        $payOrder->expire_at = (string) $meta['expire_at'];
        $extJson = (array) ($payOrder->ext_json ?? []);
        $extJson[self::EXT_KEY] = $meta;
        $payOrder->ext_json = $extJson;
        $payOrder->save();
    }

    /**
     * 通知成功后写回链上流水信息。
     *
     * @param string $payNo 支付单号
     * @param array<string, mixed> $record 流水记录
     * @param string $tradeNo 链上交易哈希
     * @return void
     */
    private function persistNotifyMeta(string $payNo, array $record, string $tradeNo): void
    {
        Db::transaction(function () use ($payNo, $record, $tradeNo): void {
            $payOrder = $this->lockedPayOrder($payNo);
            $extJson = (array) ($payOrder->ext_json ?? []);
            $receipt = (array) ($extJson[self::EXT_KEY] ?? []);
            $receipt['txid'] = $tradeNo;
            $receipt['notified_at'] = $this->paidAtFromRecord($record) ?? date('Y-m-d H:i:s');
            $receipt['notified_amount_atomic'] = $this->recordAmountAtomic($record);
            $receipt['from_address'] = (string) ($record['from_address'] ?? '');
            $receipt['to_address'] = (string) ($record['to_address'] ?? '');
            $receipt['record'] = $record;
            $extJson[self::EXT_KEY] = $receipt;
            $payOrder->ext_json = $extJson;
            $payOrder->save();
        });
    }

    /**
     * 通过链上流水定位支付单号。
     *
     * @param array<string, mixed> $payload watcher 投递载荷
     * @return string 支付单号
     */
    private function locatePayNo(array $payload): string
    {
        $record = $this->recordPayload($payload);
        $direct = $this->locatePayNoByChannelOrder($record);
        if ($direct !== '') {
            return $direct;
        }

        $amountAtomic = $this->recordAmountAtomic($record);
        $toAddress = $this->normalizeAddress((string) ($record['to_address'] ?? ''));
        $contract = $this->normalizeAddress((string) ($record['token_contract'] ?? $record['contract_address'] ?? ''));
        $expectedContract = $this->normalizeAddress($this->tokenContract());
        $paidAt = $this->paidAtTimestamp($record);
        if ($toAddress === '' || $paidAt === null) {
            throw new PaymentException('USDT 流水缺少收款地址或支付时间', 40200, ['record' => $record]);
        }
        if ($contract !== '' && $contract !== $expectedContract) {
            throw new PaymentException('USDT 流水合约地址不匹配', 40200, ['record' => $record]);
        }

        $orders = $this->payOrderRepository->listMutableReceiptOrders(
            $this->receiptChannelIds(),
            date('Y-m-d H:i:s'),
            ['pay_no', 'request_at', 'expire_at', 'ext_json']
        )
            ->filter(function (PayOrder $payOrder) use ($toAddress, $expectedContract, $amountAtomic, $paidAt): bool {
                $extJson = (array) ($payOrder->ext_json ?? []);
                $receipt = (array) ($extJson[self::EXT_KEY] ?? []);

                return $this->normalizeAddress((string) ($receipt['receive_address'] ?? '')) === $toAddress
                    && $this->normalizeAddress((string) ($receipt['token_contract'] ?? '')) === $expectedContract
                    && (int) ($receipt['amount_atomic'] ?? 0) === $amountAtomic
                    && $this->paidAtInOrderWindow($payOrder, $paidAt);
            })
            ->values();

        if ($orders->isEmpty()) {
            throw new PaymentException('USDT 流水未匹配到支付单', 40200, [
                'to_address' => $toAddress,
                'amount' => $this->atomicToUsdtText($amountAtomic),
                'paid_at' => date('Y-m-d H:i:s', $paidAt),
                'record' => $record,
            ]);
        }

        return $this->closestPayNo($orders->all(), $paidAt);
    }

    /**
     * 通过 txid 定位已经处理过的支付单。
     *
     * @param array<string, mixed> $record 流水记录
     * @return string 支付单号
     */
    private function locatePayNoByChannelOrder(array $record): string
    {
        $orderNo = trim((string) ($record['order_no'] ?? ''));
        if ($orderNo === '') {
            return '';
        }

        $order = $this->payOrderRepository->findByReceiptChannelOrder($this->receiptChannelIds(), $orderNo, ['pay_no']);

        return $order ? (string) $order->pay_no : '';
    }

    /**
     * 读取链上流水中的 USDT 原子金额。
     *
     * @param array<string, mixed> $record 流水记录
     * @return int USDT 原子金额，6 位小数
     */
    private function recordAmountAtomic(array $record): int
    {
        $rawAtomic = trim((string) ($record['amount_atomic'] ?? ''));
        if ($rawAtomic !== '' && preg_match('/^\d+$/', $rawAtomic) === 1) {
            return (int) $rawAtomic;
        }

        return $this->usdtTextToAtomic((string) ($record['price'] ?? ''));
    }

    /**
     * 从队列载荷中取流水记录。
     *
     * @param array<string, mixed> $payload 通知载荷
     * @return array<string, mixed> 流水记录
     */
    private function recordPayload(array $payload): array
    {
        return isset($payload['record']) && is_array($payload['record'])
            ? $payload['record']
            : $payload;
    }

    /**
     * 锁定支付单。
     *
     * @param string $payNo 支付单号
     * @return PayOrder 支付单
     */
    private function lockedPayOrder(string $payNo): PayOrder
    {
        $payOrder = $this->payOrderRepository->findForUpdateByPayNo($payNo);
        if (!$payOrder) {
            throw new PaymentException('支付单不存在', 40402, ['pay_no' => $payNo]);
        }

        return $payOrder;
    }

    /**
     * 读取原始人民币订单金额。
     *
     * @param PayOrder $payOrder 支付单
     * @return int 金额，单位分
     */
    private function originalAmount(PayOrder $payOrder): int
    {
        $extJson = (array) ($payOrder->ext_json ?? []);
        $receipt = (array) ($extJson[self::EXT_KEY] ?? []);
        $originalAmount = (int) ($receipt['original_amount'] ?? 0);

        return $originalAmount > 0 ? $originalAmount : (int) $payOrder->pay_amount;
    }

    /**
     * 人民币分金额换算为 USDT 原子金额。
     *
     * @param int $cnyCents 人民币金额，单位分
     * @return int USDT 原子金额，6 位小数
     */
    private function cnyCentsToUsdtAtomic(int $cnyCents): int
    {
        $rateMicroCny = $this->decimalToInteger($this->rateText(), 6);
        if ($rateMicroCny <= 0) {
            throw new PaymentException('USDT 汇率必须大于 0', 40200);
        }

        $thousandUnits = $this->ceilDiv($cnyCents * 10000000, $rateMicroCny);
        return $thousandUnits * self::DISPLAY_UNIT_ATOMIC;
    }

    /**
     * 把 USDT 文本转换为 6 位原子金额。
     *
     * @param string $value USDT 金额文本
     * @return int 原子金额
     */
    private function usdtTextToAtomic(string $value): int
    {
        $text = trim($value);
        if (!preg_match('/^\d+(?:\.\d{1,6})?$/', $text)) {
            throw new PaymentException('USDT 流水金额格式不合法', 40200, ['amount' => $value]);
        }

        return $this->decimalToInteger($text, self::TOKEN_DECIMALS);
    }

    /**
     * 十进制文本转换为定点整数。
     *
     * @param string $value 十进制文本
     * @param int $scale 小数位
     * @return int 定点整数
     */
    private function decimalToInteger(string $value, int $scale): int
    {
        $text = trim($value);
        if (!preg_match('/^\d+(?:\.\d+)?$/', $text)) {
            throw new PaymentException('数字格式不合法', 40200, ['value' => $value]);
        }

        [$integer, $fraction] = array_pad(explode('.', $text, 2), 2, '');
        return ((int) $integer * (10 ** $scale)) + (int) str_pad(substr($fraction, 0, $scale), $scale, '0');
    }

    /**
     * 整数向上除法。
     *
     * @param int $dividend 被除数
     * @param int $divisor 除数
     * @return int 结果
     */
    private function ceilDiv(int $dividend, int $divisor): int
    {
        if ($divisor <= 0) {
            throw new PaymentException('除数必须大于 0', 40200);
        }

        return intdiv($dividend + $divisor - 1, $divisor);
    }

    /**
     * USDT 原子金额格式化为 6 位小数。
     *
     * @param int $atomic 原子金额
     * @return string 金额文本
     */
    private function atomicToUsdtText(int $atomic): string
    {
        $integer = intdiv($atomic, 1000000);
        $fraction = $atomic % 1000000;

        return sprintf('%d.%06d', $integer, $fraction);
    }

    /**
     * USDT 原子金额格式化为页面展示的 3 位小数。
     *
     * @param int $atomic 原子金额
     * @return string 展示金额
     */
    private function atomicToDisplayText(int $atomic): string
    {
        $integer = intdiv($atomic, 1000000);
        $fraction = intdiv($atomic % 1000000, self::DISPLAY_UNIT_ATOMIC);

        return sprintf('%d.%03d', $integer, $fraction);
    }

    /**
     * 读取地址池。
     *
     * @return array<int, string> 地址列表
     */
    private function addressPool(): array
    {
        $raw = (string) $this->getConfig('trc20_addresses', '');
        $parts = preg_split('/[\r\n,，;；\s]+/', $raw) ?: [];
        $addresses = [];
        foreach ($parts as $part) {
            $address = $this->normalizeAddress($part);
            if ($address !== '' && preg_match('/^T[1-9A-HJ-NP-Za-km-z]{33}$/', $address) === 1) {
                $addresses[] = $address;
            }
        }

        $addresses = array_values(array_unique($addresses));
        if ($addresses === []) {
            throw new PaymentException('USDT TRC20 收款地址池为空或格式不正确', 40200);
        }

        return $addresses;
    }

    /**
     * 读取 USDT 合约地址。
     *
     * @return string 合约地址
     */
    private function tokenContract(): string
    {
        $contract = $this->normalizeAddress((string) $this->getConfig('usdt_contract_address', self::DEFAULT_USDT_CONTRACT));

        return $contract !== '' ? $contract : self::DEFAULT_USDT_CONTRACT;
    }

    /**
     * 读取汇率文本。
     *
     * @return string 汇率
     */
    private function rateText(): string
    {
        return trim((string) $this->getConfig('usdt_cny_rate', '7'));
    }

    /**
     * 读取订单有效期。
     *
     * @return int 有效期秒数
     */
    private function receiptValidSeconds(): int
    {
        return max(60, (int) $this->getConfig('receipt_valid_seconds', 300));
    }

    /**
     * 读取金额偏移最大步数。
     *
     * @return int 最大偏移步数
     */
    private function amountOffsetMax(): int
    {
        return min(999, max(0, (int) $this->getConfig('usdt_amount_offset_max', self::DEFAULT_OFFSET_MAX)));
    }

    /**
     * 计算订单过期时间。
     *
     * @return string 过期时间
     */
    private function expireAt(): string
    {
        return date('Y-m-d H:i:s', time() + $this->receiptValidSeconds());
    }

    /**
     * 账号级互斥锁。
     *
     * @param callable $callback 回调
     * @return mixed 回调结果
     */
    private function withAccountLock(callable $callback): mixed
    {
        $key = 'mpay_usdt_receipt_lock_' . $this->accountKey();
        $token = bin2hex(random_bytes(8));

        for ($i = 0; $i < 20; $i++) {
            if (!Cache::has($key)) {
                Cache::set($key, $token, 10);
            }

            if ((string) Cache::get($key) === $token) {
                try {
                    return $callback();
                } finally {
                    if ((string) Cache::get($key) === $token) {
                        Cache::delete($key);
                    }
                }
            }
            usleep(50000);
        }

        throw new PaymentException('当前 USDT 收款账号正在分配收款标识，请稍后重试', 40200);
    }

    /**
     * 当前账号关联的通道 ID。
     *
     * @return array<int, int> 通道ID列表
     */
    private function receiptChannelIds(): array
    {
        $ids = $this->paymentChannelRepository->idsByPluginConfig(
            (string) $this->getConfig('plugin_code', self::CODE),
            (int) $this->getConfig('api_config_id')
        );

        return $ids !== [] ? $ids : [(int) $this->getConfig('channel_id')];
    }

    /**
     * 账号维度业务键。
     *
     * @return string 账号键
     */
    private function accountKey(): string
    {
        return preg_replace(
            '/[^A-Za-z0-9_\\-]/',
            '_',
            (string) $this->getConfig('plugin_code', self::CODE) . '_' . (int) $this->getConfig('api_config_id')
        ) ?: self::CODE . '_0';
    }

    /**
     * 选择离链上支付时间最近的候选订单。
     *
     * @param array<int, PayOrder> $orders 候选订单
     * @param int $paidAt 支付时间戳
     * @return string 支付单号
     */
    private function closestPayNo(array $orders, int $paidAt): string
    {
        usort($orders, static function (PayOrder $left, PayOrder $right) use ($paidAt): int {
            $leftTime = strtotime((string) $left->request_at) ?: 0;
            $rightTime = strtotime((string) $right->request_at) ?: 0;

            return abs($leftTime - $paidAt) <=> abs($rightTime - $paidAt);
        });

        return (string) $orders[0]->pay_no;
    }

    /**
     * 判断链上支付时间是否落在订单窗口内。
     *
     * @param PayOrder $payOrder 支付单
     * @param int $paidAt 支付时间戳
     * @return bool 是否匹配
     */
    private function paidAtInOrderWindow(PayOrder $payOrder, int $paidAt): bool
    {
        $requestAt = strtotime((string) $payOrder->request_at) ?: 0;
        $expireAt = strtotime((string) $payOrder->expire_at) ?: 0;

        return $requestAt > 0 && $expireAt > 0 && $paidAt >= $requestAt && $paidAt <= $expireAt;
    }

    /**
     * 从流水读取支付时间。
     *
     * @param array<string, mixed> $record 流水记录
     * @return int|null 秒级时间戳
     */
    private function paidAtTimestamp(array $record): ?int
    {
        $paidAt = trim((string) ($record['paid_at'] ?? ''));
        if ($paidAt === '') {
            return null;
        }

        $timestamp = strtotime($paidAt);
        return $timestamp !== false ? $timestamp : null;
    }

    /**
     * 从流水读取支付时间文本。
     *
     * @param array<string, mixed> $record 流水记录
     * @return string|null 支付时间
     */
    private function paidAtFromRecord(array $record): ?string
    {
        $timestamp = $this->paidAtTimestamp($record);

        return $timestamp !== null ? date('Y-m-d H:i:s', $timestamp) : null;
    }

    /**
     * 生成链上交易号。
     *
     * @param array<string, mixed> $record 流水记录
     * @return string 交易号
     */
    private function channelTradeNo(array $record): string
    {
        $orderNo = trim((string) ($record['order_no'] ?? ''));
        if ($orderNo === '') {
            throw new PaymentException('USDT 流水 txid 不能为空', 40200, ['record' => $record]);
        }

        return substr($orderNo, 0, 64);
    }

    /**
     * 标准化 TRC20 地址。
     *
     * @param string $address 地址
     * @return string 地址
     */
    private function normalizeAddress(string $address): string
    {
        return trim($address);
    }
}
