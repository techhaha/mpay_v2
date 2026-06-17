<?php

declare(strict_types=1);

namespace app\common\trait;

use app\common\constant\FileConstant;
use app\common\constant\PaymentPluginStatusConstant;
use app\common\util\FormatHelper;
use app\exception\PaymentException;
use app\model\payment\PayOrder;
use app\repository\payment\config\PaymentChannelRepository;
use app\repository\payment\config\PaymentTypeRepository;
use app\repository\payment\trade\PayOrderRepository;
use support\Cache;
use support\Db;
use support\Log;
use support\Request;
use support\Response;

/**
 * 网页流水码牌收款插件公共行为。
 *
 * 插件类只声明 `$paymentInfo` 基础信息和平台能力；本 trait 负责通用配置表单、
 * 收银台承接、金额/备注识别、流水定位和标准通知结果。
 */
trait WebReceiptPaymentTrait
{
    /**
     * 构造方法只接收容器注入的仓库依赖。
     */
    public function __construct(
        private readonly PayOrderRepository $payOrderRepository,
        private readonly PaymentChannelRepository $paymentChannelRepository,
        private readonly PaymentTypeRepository $paymentTypeRepository
    ) {}

    /**
     * 发起码牌收款，准备识别金额或备注码并返回收银台承接参数。
     *
     * @param array<string, mixed> $order 支付单快照
     * @return array<string, mixed>
     */
    public function pay(array $order): array
    {
        $payNo = (string) $order['pay_no'];
        $mode = $this->receiptMatchMode();
        $prepared = $mode === 'remark'
            ? $this->prepareRemarkReceipt($payNo)
            : $this->prepareAmountReceipt($payNo);

        $qrcode = trim((string) $this->getConfig('receipt_qrcode_content', ''));
        $image = trim((string) $this->getConfig('receipt_qrcode_image', ''));
        if ($qrcode === '' && $image === '') {
            throw new PaymentException($this->getName() . '插件未配置收款码', 40200);
        }

        $params = [
            '_page' => 'receiptQrcode',
            'amount' => FormatHelper::amount((int) $prepared['pay_amount']),
            'original_amount' => FormatHelper::amount((int) $prepared['original_amount']),
            'receipt_match_mode' => $mode,
            'receipt_valid_seconds' => $this->receiptValidSeconds(),
            'expire_at' => (string) $prepared['expire_at'],
            'expire_at_timestamp' => (int) strtotime((string) $prepared['expire_at']),
            'description' => $mode === 'remark'
                ? '请扫码付款，并在付款备注中填写识别码。'
                : '请扫码付款，并按页面金额完成付款。',
        ];
        if ($mode === 'remark') {
            $params['remark_code'] = (string) $prepared['remark_code'];
            $params['tips'] = '付款备注：' . (string) $prepared['remark_code'];
        }

        if ($qrcode !== '') {
            $params['qrcode'] = $qrcode;
        }
        if ($image !== '') {
            $params['qrcode_image'] = $image;
        }

        return $this->payResult($params, $payNo, (string) ($order['pay_type_code'] ?? ''));
    }

    /**
     * 网页流水场景无实时查单接口，主动查询保持待支付状态。
     *
     * @param array<string, mixed> $order 支付单快照
     * @return array<string, mixed>
     */
    public function query(array $order): array
    {
        return [
            'success' => true,
            'status' => PaymentPluginStatusConstant::PENDING,
            'channel_order_no' => (string) ($order['channel_order_no'] ?? $order['pay_no'] ?? ''),
            'channel_trade_no' => (string) ($order['channel_trade_no'] ?? $order['pay_no'] ?? ''),
            'message' => '等待 receipt_watcher 查询' . $this->getName() . '流水',
        ];
    }

    /**
     * 码牌收款不需要通知上游关单。
     *
     * @param array<string, mixed> $order 支付单快照
     * @return array<string, mixed>
     */
    public function close(array $order): array
    {
        return [
            'success' => true,
            'msg' => $this->getName() . '收款无需上游关单',
        ];
    }

    /**
     * 码牌收款没有统一退款 API。
     *
     * @param array<string, mixed> $order 支付单快照
     */
    public function refund(array $order): array
    {
        throw new PaymentException($this->getName() . '收款不支持接口退款', 40200);
    }

    /**
     * 兼容标准 HTTP 回调入口，队列链路通常直接调用 `notifyPayload()`。
     *
     * @return array<string, mixed>
     */
    public function notify(Request $request): array
    {
        return $this->notifyPayload((array) $request->all());
    }

    /**
     * 队列通知第一步：只定位 MPAY 支付单号。
     *
     * @param array<string, mixed> $payload receipt_watcher 投递的标准流水
     * @return array{pay_no: string}
     */
    public function channelNotifyPayload(array $payload): array
    {
        return ['pay_no' => $this->locatePayNo($payload)];
    }

    /**
     * 队列通知第二步：把已定位的流水归一为标准支付成功结果。
     *
     * 返回前会恢复金额变动模式下临时改写的订单金额。
     *
     * @param array<string, mixed> $payload receipt_watcher 投递的标准流水
     * @return array<string, mixed>
     */
    public function notifyPayload(array $payload): array
    {
        $record = $this->recordPayload($payload);
        $payNo = $this->locatePayNo($payload);
        $tradeNo = $this->channelTradeNo($record);
        $notifiedAmount = isset($record['price']) ? $this->moneyToCents((string) $record['price']) : null;

        $this->restoreOriginalPayAmount($payNo, $record, $tradeNo, $notifiedAmount);

        return [
            'status' => PaymentPluginStatusConstant::SUCCESS,
            'pay_no' => $payNo,
            'message' => 'receipt_watcher 已确认' . $this->getName() . '收款流水',
            'channel_order_no' => $tradeNo,
            'channel_trade_no' => $tradeNo,
            'channel_status' => 'receipt_watcher_received',
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
     * 返回网页码牌插件通用配置表单。
     *
     * @return array<int, array<string, mixed>>
     */
    public function getConfigSchema(): array
    {
        return $this->receiptConfigSchema();
    }

    /**
     * 生成后台配置表单；无备注平台固定为金额模式。
     *
     * @return array<int, array<string, mixed>>
     */
    private function receiptConfigSchema(): array
    {
        $schema = [];
        if ($this->supportsReceiptRemark()) {
            $schema[] = [
                'type' => 'radio',
                'field' => 'receipt_match_mode',
                'title' => '订单匹配模式',
                'value' => 'amount',
                'options' => [
                    ['label' => '金额变动', 'value' => 'amount'],
                    ['label' => '付款备注', 'value' => 'remark'],
                ],
                'control' => [
                    [
                        'rule' => ['amount_offset_max'],
                        'value' => 'amount',
                        'method' => 'display',
                    ],
                ],
                'validate' => [
                    ['required' => true, 'message' => '订单匹配模式不能为空'],
                ],
            ];
        } else {
            $schema[] = [
                'type' => 'hidden',
                'field' => 'receipt_match_mode',
                'value' => 'amount',
            ];
        }

        return array_merge($schema, [
            [
                'type' => 'inputNumber',
                'field' => 'receipt_valid_seconds',
                'title' => '识别有效期(秒)',
                'value' => 300,
                'props' => [
                    'min' => 60,
                    'max' => 1800,
                    'step' => 60,
                ],
            ],
            [
                'type' => 'inputNumber',
                'field' => 'amount_offset_max',
                'title' => '最大金额偏移(分)',
                'value' => 99,
                'props' => [
                    'min' => 0,
                    'max' => 99,
                    'step' => 1,
                ],
            ],
            [
                'type' => 'inputNumber',
                'field' => 'receipt_watcher_query_interval_seconds',
                'title' => '账号查询间隔(秒)',
                'value' => 3,
                'props' => [
                    'min' => 2,
                    'max' => 60,
                    'step' => 1,
                ],
            ],
            [
                'type' => 'input',
                'field' => 'watcher_username',
                'title' => '平台登录账号',
                'value' => '',
                'props' => [
                    'placeholder' => '请输入' . $this->getName() . '登录账号',
                ],
                'validate' => [
                    ['required' => true, 'message' => '平台登录账号不能为空'],
                ],
            ],
            [
                'type' => 'password',
                'field' => 'watcher_password',
                'title' => '平台登录密码',
                'value' => '',
                'props' => [
                    'placeholder' => '请输入' . $this->getName() . '登录密码',
                ],
                'validate' => [
                    ['required' => true, 'message' => '平台登录密码不能为空'],
                ],
            ],
            [
                'type' => 'input',
                'field' => 'receipt_merchant_name',
                'title' => '码牌商户名',
                'value' => '',
                'props' => [
                    'placeholder' => '单商户可留空；多商户账号建议填写，监听工具会切换或校验目标商户',
                ],
            ],
            [
                'type' => 'input',
                'field' => 'receipt_account_no',
                'title' => '收款账号标识',
                'value' => '',
                'props' => [
                    'placeholder' => '单商户可留空；多商户/多门店账号填写平台商户号、门店ID或商户编号',
                ],
            ],
            [
                'type' => 'input',
                'field' => 'receipt_terminal_no',
                'title' => '收款终端号',
                'value' => '',
                'props' => [
                    'placeholder' => '单终端可留空；多个码牌/终端/收款单时建议填写，用于过滤平台流水',
                ],
            ],
            ...$this->receiptExtraConfigSchema(),
            [
                'type' => 'textarea',
                'field' => 'receipt_qrcode_content',
                'title' => '收款码内容',
                'value' => '',
                'props' => [
                    'placeholder' => '可填写二维码解析后的内容，优先用于收银台展示',
                    'rows' => 4,
                ],
            ],
            [
                'type' => 'upload',
                'field' => 'receipt_qrcode_image',
                'title' => '收款码图片',
                'value' => '',
                'props' => [
                    'fileUpload' => [
                        'selectorType' => 'image',
                        'scene' => FileConstant::SCENE_IMAGE,
                        'visibility' => FileConstant::VISIBILITY_PUBLIC,
                        'getKey' => 'url',
                        'accept' => '.jpg,.jpeg,.png,.gif,.webp,.bmp,.svg',
                        'listType' => 'picture-card',
                        'showFileList' => true,
                        'imagePreview' => true,
                        'limit' => 1,
                        'multiple' => false,
                    ],
                    'tip' => '上传收款码图片，未配置收款码内容时用于收银台展示。',
                ],
            ],
        ]);
    }

    /**
     * 平台专属配置扩展字段。
     *
     * 默认插件不需要扩展；确有平台业务参数时由插件类覆盖。
     *
     * @return array<int, array<string, mixed>>
     */
    protected function receiptExtraConfigSchema(): array
    {
        return [];
    }

    /**
     * 组装 `receiptQrcode` 收银台承接返回。
     *
     * @param array<string, mixed> $params 承接参数
     * @param string $payNo 支付单号
     * @param string $payType 支付方式
     * @return array<string, mixed>
     */
    private function payResult(array $params, string $payNo, string $payType): array
    {
        $payTypes = $this->getEnabledPayTypes();
        $payType = trim($payType) !== '' ? trim($payType) : (string) ($payTypes[0] ?? 'alipay');

        return [
            'pay_page' => 'page',
            'pay_type' => $payType,
            'pay_product' => 'receipt_plate',
            'pay_action' => 'web_watcher',
            'pay_params' => $params,
            'chan_order_no' => $payNo,
            'chan_trade_no' => '',
        ];
    }

    /**
     * 当前平台是否支持付款备注模式。
     */
    private function supportsReceiptRemark(): bool
    {
        return (bool) ($this->paymentInfo['receipt_supports_remark'] ?? true);
    }

    /**
     * 当前配置的订单匹配模式。
     */
    private function receiptMatchMode(): string
    {
        if (!$this->supportsReceiptRemark()) {
            return 'amount';
        }

        return (string) $this->getConfig('receipt_match_mode', 'amount') === 'remark' ? 'remark' : 'amount';
    }

    /**
     * 当前码牌识别有效期，最低 60 秒。
     */
    private function receiptValidSeconds(): int
    {
        return max(60, (int) $this->getConfig('receipt_valid_seconds', 300));
    }

    /**
     * 金额模式最大偏移分值，限制在 0 到 99 分。
     */
    private function amountOffsetMax(): int
    {
        return min(99, max(0, (int) $this->getConfig('amount_offset_max', 99)));
    }

    /**
     * 准备金额变动识别信息。
     *
     * 同账号有效订单不能重复使用同一付款金额，分配时始终取最小可用偏移。
     *
     * @return array<string, mixed>
     */
    private function prepareAmountReceipt(string $payNo): array
    {
        return $this->withAccountLock(function () use ($payNo): array {
            return Db::transaction(function () use ($payNo): array {
                $payOrder = $this->lockedPayOrder($payNo);
                $originalAmount = $this->originalAmount($payOrder);
                $expireAt = $this->expireAt();
                $usedAmounts = $this->payOrderRepository->listUsedReceiptAmounts(
                    $this->receiptChannelIds(),
                    $payNo,
                    date('Y-m-d H:i:s')
                );

                $used = array_fill_keys($usedAmounts, true);
                $receiptAmount = 0;
                for ($offset = 0; $offset <= $this->amountOffsetMax(); $offset++) {
                    $candidate = $originalAmount + $offset;
                    if (!isset($used[$candidate])) {
                        $receiptAmount = $candidate;
                        break;
                    }
                }

                if ($receiptAmount <= 0) {
                    throw new PaymentException('当前账号可用金额偏移已用尽', 40200, [
                        'pay_no' => $payNo,
                        'api_config_id' => (int) $this->getConfig('api_config_id'),
                    ]);
                }

                $this->persistReceiptMeta($payOrder, [
                    'platform' => $this->getCode(),
                    'mode' => 'amount',
                    'original_amount' => $originalAmount,
                    'receipt_amount' => $receiptAmount,
                    'offset_amount' => $receiptAmount - $originalAmount,
                    'expire_at' => $expireAt,
                ]);

                return [
                    'original_amount' => $originalAmount,
                    'pay_amount' => $receiptAmount,
                    'expire_at' => $expireAt,
                ];
            });
        });
    }

    /**
     * 准备付款备注识别信息。
     *
     * 备注码用于定位候选订单，最终确认仍会校验金额和支付时间。
     *
     * @return array<string, mixed>
     */
    private function prepareRemarkReceipt(string $payNo): array
    {
        return $this->withAccountLock(function () use ($payNo): array {
            return Db::transaction(function () use ($payNo): array {
                $payOrder = $this->lockedPayOrder($payNo);
                $originalAmount = $this->originalAmount($payOrder);
                $expireAt = $this->expireAt();
                $remarkCode = $this->allocateRemarkCode($payNo);

                $this->persistReceiptMeta($payOrder, [
                    'platform' => $this->getCode(),
                    'mode' => 'remark',
                    'original_amount' => $originalAmount,
                    'receipt_amount' => $originalAmount,
                    'remark_code' => $remarkCode,
                    'expire_at' => $expireAt,
                ]);

                return [
                    'original_amount' => $originalAmount,
                    'pay_amount' => $originalAmount,
                    'remark_code' => $remarkCode,
                    'expire_at' => $expireAt,
                ];
            });
        });
    }

    /**
     * 锁定支付单，避免并发分配或恢复时读写错位。
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
     * 保存本次码牌识别元数据。
     *
     * 金额模式会临时改写 `pay_amount`；原始金额保存在 `ext_json.personal_receipt`。
     *
     * @param array<string, mixed> $meta
     */
    private function persistReceiptMeta(PayOrder $payOrder, array $meta): void
    {
        $payOrder->pay_amount = (int) $meta['receipt_amount'];
        $payOrder->expire_at = (string) $meta['expire_at'];
        $extJson = (array) ($payOrder->ext_json ?? []);
        $extJson['personal_receipt'] = $meta;
        $payOrder->ext_json = $extJson;
        $payOrder->save();
    }

    /**
     * 读取订单原始金额，优先使用识别元数据里的记录。
     */
    private function originalAmount(PayOrder $payOrder): int
    {
        $extJson = (array) ($payOrder->ext_json ?? []);
        $receiptMeta = (array) ($extJson['personal_receipt'] ?? []);
        $originalAmount = (int) ($receiptMeta['original_amount'] ?? 0);

        return $originalAmount > 0 ? $originalAmount : (int) $payOrder->pay_amount;
    }

    /**
     * 支付确认前恢复订单原始金额，并保存平台流水快照。
     *
     * 变动后的付款金额只用于识别订单，不能进入业务统计。
     *
     * @param array<string, mixed> $record
     */
    private function restoreOriginalPayAmount(string $payNo, array $record, string $tradeNo, ?int $notifiedAmount): void
    {
        Db::transaction(function () use ($payNo, $record, $tradeNo, $notifiedAmount): void {
            $payOrder = $this->lockedPayOrder($payNo);
            $extJson = (array) ($payOrder->ext_json ?? []);
            $receiptMeta = (array) ($extJson['personal_receipt'] ?? []);
            $originalAmount = (int) ($receiptMeta['original_amount'] ?? 0);

            if ($originalAmount > 0) {
                $payOrder->pay_amount = $originalAmount;
            }

            $receiptMeta['notified_at'] = $this->paidAtFromRecord($record) ?? date('Y-m-d H:i:s');
            $receiptMeta['channel_trade_no'] = $tradeNo;
            $receiptMeta['record'] = $record;
            if ($notifiedAmount !== null) {
                $receiptMeta['notified_amount'] = $notifiedAmount;
            }
            $extJson['personal_receipt'] = $receiptMeta;
            $payOrder->ext_json = $extJson;
            $payOrder->save();
        });
    }

    /**
     * 根据标准流水定位支付单号。
     *
     * 优先用第三方流水号命中幂等订单，再按当前模式匹配。
     *
     * @param array<string, mixed> $payload
     */
    private function locatePayNo(array $payload): string
    {
        $record = $this->recordPayload($payload);
        $directPayNo = $this->locatePayNoByChannelOrder($record);
        if ($directPayNo !== '') {
            return $directPayNo;
        }

        return $this->receiptMatchMode() === 'remark'
            ? $this->locatePayNoByRemark($record)
            : $this->locatePayNoByAmount($record);
    }

    /**
     * 通过已保存的第三方流水号定位支付单。
     *
     * @param array<string, mixed> $record
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
     * 金额模式定位订单。
     *
     * 匹配范围限定在同插件配置、同支付方式、同识别金额和订单有效期内。
     *
     * @param array<string, mixed> $record
     */
    private function locatePayNoByAmount(array $record): string
    {
        $amount = $this->moneyToCents((string) ($record['price'] ?? ''));
        $paidAt = $this->paidAtTimestamp($record);
        if ($paidAt === null) {
            throw new PaymentException('金额流水支付时间不能为空', 40200, ['record' => $record]);
        }

        $orders = $this->payOrderRepository->listMutableReceiptOrdersByAmount(
            $this->receiptChannelIds(),
            $amount,
            $this->payTypeIdFromRecord($record),
            date('Y-m-d H:i:s'),
            ['pay_no', 'request_at', 'expire_at']
        )
            ->filter(fn (PayOrder $payOrder): bool => $this->paidAtInOrderWindow($payOrder, $paidAt))
            ->values();
        if ($orders->isEmpty()) {
            throw new PaymentException('金额流水未匹配到支付单', 40200, [
                'amount' => FormatHelper::amount($amount),
                'paid_at' => date('Y-m-d H:i:s', $paidAt),
                'record' => $record,
            ]);
        }

        if ($orders->count() > 1) {
            Log::warning(sprintf(
                '[%s] 金额流水匹配到多笔订单，按时间最近选择 amount=%s count=%d',
                static::class,
                FormatHelper::amount($amount),
                $orders->count()
            ));
        }

        return $this->closestPayNo($orders->all(), $paidAt);
    }

    /**
     * 备注模式定位订单。
     *
     * 备注码只缩小候选范围，确认时必须同时校验金额和支付时间。
     *
     * @param array<string, mixed> $record
     */
    private function locatePayNoByRemark(array $record): string
    {
        $remarkCode = $this->remarkCodeFromRecord($record);
        $amount = $this->moneyToCents((string) ($record['price'] ?? ''));
        $paidAt = $this->paidAtTimestamp($record);
        if ($paidAt === null) {
            throw new PaymentException('备注流水支付时间不能为空', 40200, ['record' => $record]);
        }

        $payNo = (string) Cache::get($this->remarkCacheKey($remarkCode), '');
        if ($payNo !== '') {
            $payOrder = $this->payOrderRepository->findByPayNo($payNo, ['pay_no', 'pay_amount', 'request_at', 'expire_at', 'ext_json']);
            if (!$payOrder || !$this->remarkOrderMatchesFlow($payOrder, $amount, $paidAt)) {
                throw new PaymentException('付款备注匹配的支付单金额或时间不一致', 40200, [
                    'pay_no' => $payNo,
                    'amount' => FormatHelper::amount($amount),
                    'paid_at' => date('Y-m-d H:i:s', $paidAt),
                    'record' => $record,
                ]);
            }

            return (string) $payOrder->pay_no;
        }

        $candidates = $this->payOrderRepository->listMutableReceiptOrders(
            $this->receiptChannelIds(),
            date('Y-m-d H:i:s'),
            ['pay_no', 'pay_amount', 'request_at', 'expire_at', 'ext_json']
        )
            ->filter(function (PayOrder $payOrder) use ($remarkCode, $amount, $paidAt): bool {
                $extJson = (array) ($payOrder->ext_json ?? []);
                $receiptMeta = (array) ($extJson['personal_receipt'] ?? []);
                return (string) ($receiptMeta['remark_code'] ?? '') === $remarkCode
                    && $this->remarkOrderMatchesFlow($payOrder, $amount, $paidAt);
            })
            ->values();

        if ($candidates->isEmpty()) {
            throw new PaymentException('付款备注已失效、金额不一致或不在订单有效期内', 40200, [
                'remark_code' => $remarkCode,
                'amount' => FormatHelper::amount($amount),
                'paid_at' => date('Y-m-d H:i:s', $paidAt),
                'record' => $record,
            ]);
        }

        if ($candidates->count() > 1) {
            Log::warning(sprintf(
                '[%s] 备注流水匹配到多笔订单，按时间最近选择 remark=%s count=%d',
                static::class,
                $remarkCode,
                $candidates->count()
            ));
        }

        return $this->closestPayNo($candidates->all(), $paidAt);
    }

    /**
     * 多个候选订单命中时，选择创建时间最接近流水时间的一笔。
     *
     * @param array<int, PayOrder> $orders
     */
    private function closestPayNo(array $orders, ?int $paidAt): string
    {
        if ($paidAt === null) {
            return (string) $orders[0]->pay_no;
        }

        usort($orders, static function (PayOrder $left, PayOrder $right) use ($paidAt): int {
            $leftTime = strtotime((string) $left->request_at) ?: 0;
            $rightTime = strtotime((string) $right->request_at) ?: 0;

            return abs($leftTime - $paidAt) <=> abs($rightTime - $paidAt);
        });

        return (string) $orders[0]->pay_no;
    }

    /**
     * 判断流水支付时间是否落在订单识别窗口内。
     */
    private function paidAtInOrderWindow(PayOrder $payOrder, int $paidAt): bool
    {
        $requestAt = strtotime((string) $payOrder->request_at) ?: 0;
        $expireAt = strtotime((string) $payOrder->expire_at) ?: 0;

        return $requestAt > 0
            && $expireAt > 0
            && $paidAt >= $requestAt
            && $paidAt <= $expireAt;
    }

    /**
     * 备注模式必须同时满足原始金额和有效期。
     */
    private function remarkOrderMatchesFlow(PayOrder $payOrder, int $amount, int $paidAt): bool
    {
        return $amount === $this->originalAmount($payOrder)
            && $this->paidAtInOrderWindow($payOrder, $paidAt);
    }

    /**
     * 标准流水可能直接传入，也可能包在 `record` 字段里。
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function recordPayload(array $payload): array
    {
        return isset($payload['record']) && is_array($payload['record'])
            ? $payload['record']
            : $payload;
    }

    /**
     * 将流水支付方式编码转换为系统支付方式 ID。
     *
     * @param array<string, mixed> $record
     */
    private function payTypeIdFromRecord(array $record): int
    {
        $payType = trim((string) ($record['pay_type'] ?? ''));
        if ($payType === '') {
            return 0;
        }

        $type = $this->paymentTypeRepository->findByCode($payType, ['id']);
        return $type ? (int) $type->id : 0;
    }

    /**
     * 查询同一插件配置下的所有通道。
     *
     * 同一个码牌账号可能挂载多个支付方式通道，流水匹配需要覆盖这些通道。
     *
     * @return array<int, int>
     */
    private function receiptChannelIds(): array
    {
        $ids = $this->paymentChannelRepository->idsByPluginConfig(
            (string) $this->getConfig('plugin_code', $this->getCode()),
            (int) $this->getConfig('api_config_id')
        );

        return $ids !== [] ? $ids : [(int) $this->getConfig('channel_id')];
    }

    /**
     * 分配 4 位付款备注码，并写入有效期缓存。
     */
    private function allocateRemarkCode(string $payNo): string
    {
        for ($i = 0; $i < 30; $i++) {
            $code = (string) random_int(1000, 9999);
            $key = $this->remarkCacheKey($code);
            if (!Cache::has($key)) {
                Cache::set($key, $payNo, $this->receiptValidSeconds());
                return $code;
            }
        }

        throw new PaymentException('付款备注码已用尽，请稍后重试', 40200);
    }

    /**
     * 同账号分配收款标识时加短锁，避免并发订单拿到相同标识。
     */
    private function withAccountLock(callable $callback): mixed
    {
        $key = 'mpay_receipt_lock_' . $this->accountKey();
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

        throw new PaymentException('当前收款账号正在分配收款标识，请稍后重试', 40200);
    }

    /**
     * 当前识别有效期截止时间。
     */
    private function expireAt(): string
    {
        return date('Y-m-d H:i:s', time() + $this->receiptValidSeconds());
    }

    /**
     * 从流水备注中提取 4 位付款备注码。
     *
     * @param array<string, mixed> $record
     */
    private function remarkCodeFromRecord(array $record): string
    {
        $remark = trim((string) ($record['remark'] ?? ''));
        if (preg_match('/(?<!\d)(\d{4})(?!\d)/', $remark, $matches) !== 1) {
            throw new PaymentException('流水备注未识别到付款备注码', 40200, ['remark' => $remark]);
        }

        return (string) $matches[1];
    }

    /**
     * 将金额字符串转换为分。
     */
    private function moneyToCents(string $money): int
    {
        $money = trim($money);
        if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $money)) {
            throw new PaymentException('流水金额格式不合法', 40200, ['money' => $money]);
        }

        [$integer, $fraction] = array_pad(explode('.', $money, 2), 2, '');
        return (int) $integer * 100 + (int) str_pad(substr($fraction, 0, 2), 2, '0');
    }

    /**
     * 付款备注码缓存键。
     */
    private function remarkCacheKey(string $code): string
    {
        return 'mpay_receipt_remark_' . $this->accountKey() . '_' . $code;
    }

    /**
     * 当前插件配置对应的收款账号隔离键。
     */
    private function accountKey(): string
    {
        return preg_replace(
            '/[^A-Za-z0-9_\-]/',
            '_',
            (string) $this->getConfig('plugin_code', $this->getCode()) . '_' . (int) $this->getConfig('api_config_id')
        ) ?: $this->getCode() . '_0';
    }

    /**
     * 读取平台流水号作为渠道流水号。
     *
     * @param array<string, mixed> $record
     */
    private function channelTradeNo(array $record): string
    {
        $orderNo = trim((string) ($record['order_no'] ?? ''));
        if ($orderNo === '') {
            throw new PaymentException('流水订单号不能为空', 40200, ['record' => $record]);
        }

        return substr($orderNo, 0, 64);
    }

    /**
     * 读取流水支付时间并格式化为系统时间字符串。
     *
     * @param array<string, mixed> $record
     */
    private function paidAtFromRecord(array $record): ?string
    {
        $timestamp = $this->paidAtTimestamp($record);

        return $timestamp !== null ? date('Y-m-d H:i:s', $timestamp) : null;
    }

    /**
     * 将流水支付时间转换为秒级时间戳。
     *
     * @param array<string, mixed> $record
     */
    private function paidAtTimestamp(array $record): ?int
    {
        $paidAt = $record['paid_at'] ?? null;
        if (is_numeric($paidAt)) {
            $timestamp = (int) $paidAt;
            return $timestamp > 10000000000 ? (int) floor($timestamp / 1000) : $timestamp;
        }

        $text = trim((string) $paidAt);
        if ($text === '') {
            return null;
        }

        $timestamp = strtotime($text);
        return $timestamp !== false ? $timestamp : null;
    }
}
