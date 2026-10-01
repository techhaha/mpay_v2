<?php

declare(strict_types=1);

namespace app\common\payment;

use app\common\base\BasePayment;
use app\common\constant\FileConstant;
use app\common\constant\PaymentPluginStatusConstant;
use app\common\constant\PaymentPluginTypeConstant;
use app\common\interface\ChannelNotifyPayloadInterface;
use app\common\interface\PaymentInterface;
use app\common\interface\PayPluginInterface;
use app\common\util\FormatHelper;
use app\exception\PaymentException;
use app\exception\UnsupportedPaymentOperationException;
use app\model\payment\PayOrder;
use app\repository\payment\config\PaymentChannelRepository;
use app\repository\payment\config\PaymentTypeRepository;
use app\repository\payment\trade\PayOrderRepository;
use support\Cache;
use support\Db;
use support\Redis;
use support\Request;
use support\Response;

/**
 * 天阙 Pretran 二维码牌网页流水监听插件。
 *
 * 负责生成码牌收银台参数，并将 receipt_watcher 投递的列表流水补查为详情后匹配本地支付单。
 * 支付确认依赖网页流水而非标准支付回调，不提供实时查单或统一退款 API，关单只结束本地等待状态。
 */
class TianquePretranReceiptPayment extends BasePayment implements PaymentInterface, PayPluginInterface, ChannelNotifyPayloadInterface
{
    private const MODE_AMOUNT = 'amount';
    private const MODE_REMARK = 'remark';
    private const MODE_DYNAMIC_QRCODE = 'dynamic_qrcode';
    private const API_BASE_URL = 'https://pretran.tianquetech.com';
    private const SESSION_KEY_PREFIX = 'receipt_watcher_session_';
    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/132.0.0.0 Safari/537.36 MicroMessenger/7.0.20.1781(0x6700143B) NetType/WIFI MiniProgramEnv/Windows WindowsWechat/WMPF WindowsWechat(0x63090a13) UnifiedPCWindowsWechat(0xf2541a35) XWEB/20001';

    /**
     * 插件基础信息和支持的收款方式。
     *
     * @var array<string, mixed>
     */
    protected array $paymentInfo = [
        'code' => 'tianque_pretran_receipt',
        'name' => '天阙Pretran码牌收款',
        'plugin_type' => PaymentPluginTypeConstant::TYPE_BACKEND,
        'author' => 'MPAY',
        'version' => '1.0.0',
        'pay_types' => ['alipay', 'wxpay', 'unionpay'],
        'transfer_types' => [],
        'receipt_watcher' => [
            'runtime' => 'direct',
            'prelogin_supported' => true,
        ],
    ];

    /**
     * 构造码牌流水匹配所需的仓储依赖。
     *
     * @param PayOrderRepository $payOrderRepository 支付单候选、锁定与金额变更仓储
     * @param PaymentChannelRepository $paymentChannelRepository 插件配置关联通道查询仓储
     * @param PaymentTypeRepository $paymentTypeRepository 流水支付方式解析仓储
     */
    public function __construct(
        private readonly PayOrderRepository $payOrderRepository,
        private readonly PaymentChannelRepository $paymentChannelRepository,
        private readonly PaymentTypeRepository $paymentTypeRepository
    ) {
    }

    /**
     * 返回天阙Pretran码牌插件配置表单。
     *
     * @return array<int, array<string, mixed>>
     */
    public function getConfigSchema(): array
    {
        return [
            [
                'type' => 'radio',
                'field' => 'receipt_match_mode',
                'title' => '订单匹配模式',
                'value' => self::MODE_AMOUNT,
                'options' => [
                    ['label' => '金额变动', 'value' => self::MODE_AMOUNT],
                    ['label' => '付款备注', 'value' => self::MODE_REMARK],
                    ['label' => '动态收款码', 'value' => self::MODE_DYNAMIC_QRCODE],
                ],
                'control' => [
                    [
                        'rule' => ['amount_offset_max', 'receipt_qrcode_content', 'receipt_qrcode_image'],
                        'value' => self::MODE_AMOUNT,
                        'method' => 'display',
                    ],
                    [
                        'rule' => ['receipt_qrcode_content', 'receipt_qrcode_image'],
                        'value' => self::MODE_REMARK,
                        'method' => 'display',
                    ],
                ],
                'validate' => [
                    ['required' => true, 'message' => '订单匹配模式不能为空'],
                ],
            ],
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
                    'placeholder' => '填写平台登录密码，监听工具会按接口要求加密提交',
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
                    'placeholder' => '多商户账号建议填写，监听工具会精确匹配目标商户',
                ],
            ],
            [
                'type' => 'input',
                'field' => 'receipt_account_no',
                'title' => '收款账号标识',
                'value' => '',
                'props' => [
                    'placeholder' => '建议填写天阙 mno，避免同手机号多商户串账',
                ],
            ],
            [
                'type' => 'input',
                'field' => 'receipt_terminal_no',
                'title' => '收款终端号',
                'value' => '',
                'props' => [
                    'placeholder' => '可选，按详情 deviceNo 校验；必要时也可填 extend5',
                ],
            ],
            [
                'type' => 'switch',
                'field' => 'proxy_enabled',
                'title' => '启用IP代理',
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
                'title' => 'IP代理地址',
                'value' => '',
                'props' => [
                    'placeholder' => '例如 user:pass@host:port 或 http://user:pass@host:port',
                ],
            ],
            [
                'type' => 'textarea',
                'field' => 'receipt_qrcode_content',
                'title' => '收款码内容',
                'value' => '',
                'props' => [
                    'placeholder' => '金额变动/付款备注模式必填其一，优先用于收银台展示',
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
                    'tip' => '金额变动/付款备注模式用于展示静态码牌，动态收款码模式会自动生成二维码。',
                ],
            ],
        ];
    }

    /**
     * 发起码牌收款，按匹配模式准备收银台承接参数。
     *
     * @param array<string, mixed> $order 支付单快照
     *
     * @return array<string, mixed> 标准页面待支付结果
     */
    public function pay(array $order): array
    {
        $payNo = (string) $order['pay_no'];
        $mode = $this->receiptMatchMode();

        if ($mode === self::MODE_DYNAMIC_QRCODE) {
            $prepared = $this->prepareDynamicReceipt($payNo);
            $qrcode = (string) $prepared['qrcode'];
            $image = '';
        } else {
            $prepared = $mode === self::MODE_REMARK
                ? $this->prepareRemarkReceipt($payNo)
                : $this->prepareAmountReceipt($payNo);
            $qrcode = trim((string) $this->getConfig('receipt_qrcode_content', ''));
            $image = trim((string) $this->getConfig('receipt_qrcode_image', ''));
            if ($qrcode === '' && $image === '') {
                throw new PaymentException($this->getName() . '插件未配置收款码', 40200);
            }
        }

        $params = [
            '_page' => 'receiptQrcode',
            'amount' => FormatHelper::amount((int) $prepared['pay_amount']),
            'original_amount' => FormatHelper::amount((int) $prepared['original_amount']),
            'receipt_match_mode' => $mode,
            'receipt_valid_seconds' => $this->receiptValidSeconds(),
            'expire_at' => (string) $prepared['expire_at'],
            'expire_at_timestamp' => (int) strtotime((string) $prepared['expire_at']),
            'server_time_timestamp' => time(),
            'description' => match ($mode) {
                self::MODE_REMARK => '请扫码付款，并在付款备注中填写识别码。',
                self::MODE_DYNAMIC_QRCODE => '请扫码付款，系统已自动生成本单专属收款码。',
                default => '请扫码付款，并按页面金额完成付款。',
            },
        ];
        if (isset($prepared['remark_code'])) {
            $params['remark_code'] = (string) $prepared['remark_code'];
            $params['tips'] = '付款备注：' . (string) $prepared['remark_code'];
        }
        if ($qrcode !== '') {
            $params['qrcode'] = $qrcode;
        }
        if ($image !== '') {
            $params['qrcode_image'] = $image;
        }
        if (isset($prepared['raw'])) {
            $params['raw'] = $prepared['raw'];
        }

        return $this->pendingPaymentResult(
            $order,
            $this->payResult($params, $payNo, (string) ($order['pay_type_code'] ?? ''))
        );
    }

    /**
     * 网页流水场景无实时查单接口，主动查询保持待支付状态。
     *
     * @param array<string, mixed> $order 支付单快照
     *
     * @return array<string, mixed> 不确认终态的标准待支付结果
     */
    public function query(array $order): array
    {
        return [
            'status' => PaymentPluginStatusConstant::PENDING,
            'pay_no' => (string) ($order['pay_no'] ?? ''),
            'paid_amount' => null,
            'chan_order_no' => (string) ($order['chan_order_no'] ?? ''),
            'chan_trade_no' => (string) ($order['chan_trade_no'] ?? ''),
            'message' => '等待 receipt_watcher 查询' . $this->getName() . '流水',
        ];
    }

    /**
     * 结束本地码牌收款等待状态。
     *
     * 网页流水侧没有上游订单可关闭，因此此结果只表达本地终止等待，不代表调用了渠道关单接口。
     *
     * @param array<string, mixed> $order 支付单快照
     *
     * @return array<string, mixed> 标准本地关单结果
     */
    public function close(array $order): array
    {
        return [
            'status' => PaymentPluginStatusConstant::CLOSED,
            'pay_no' => (string) ($order['pay_no'] ?? ''),
            'chan_order_no' => (string) ($order['chan_order_no'] ?? ''),
            'chan_trade_no' => (string) ($order['chan_trade_no'] ?? ''),
            'message' => $this->getName() . '收款无需上游关单',
        ];
    }

    /**
     * 码牌收款没有统一退款 API。
     *
     * @param array<string, mixed> $order 支付单快照
     *
     * @return array<string, mixed>
     */
    public function refund(array $order): array
    {
        throw new UnsupportedPaymentOperationException($this->getName() . '收款不支持接口退款', 40200);
    }

    /**
     * 兼容标准 HTTP 回调入口，实际处理统一交给 notifyPayload()。
     *
     * @param Request $request HTTP 回调请求
     *
     * @return array<string, mixed> 标准支付通知结果
     */
    public function notify(Request $request): array
    {
        return $this->notifyPayload((array) $request->all());
    }

    /**
     * 队列通知第一步：补查流水详情并定位唯一的 MPAY 支付单号。
     *
     * 定位结果短暂缓存，供同一通知后续的状态归一化复用，避免重复查询 Pretran 详情。
     *
     * @param array<string, mixed> $payload receipt_watcher 投递的标准流水
     *
     * @return array{pay_no:string}
     */
    public function channelNotifyPayload(array $payload): array
    {
        $resolved = $this->resolveFlow($payload);
        Cache::set($this->flowResolveCacheKey($resolved['record']), $resolved, 90);

        return ['pay_no' => (string) $resolved['pay_no']];
    }

    /**
     * 队列通知第二步：把已确认的流水归一为标准支付成功结果。
     *
     * 返回前会恢复金额变动模式下临时改写的订单金额。
     *
     * @param array<string, mixed> $payload receipt_watcher 投递的标准流水
     *
     * @return array<string, mixed> 标准支付成功通知结果
     */
    public function notifyPayload(array $payload): array
    {
        $record = $this->recordPayload($payload);
        $cached = Cache::get($this->flowResolveCacheKey($record));
        $resolved = is_array($cached) ? $cached : $this->resolveFlow($payload);
        $payNo = (string) $resolved['pay_no'];
        $detail = (array) $resolved['detail'];
        $tradeNo = $this->channelTradeNo($detail);
        $notifiedAmount = $this->moneyToCents((string) ($detail['price'] ?? ''));

        $paidAmount = $this->restoreOriginalPayAmount($payNo, $detail, $tradeNo, $notifiedAmount);

        return [
            'status' => PaymentPluginStatusConstant::SUCCESS,
            'pay_no' => $payNo,
            'paid_amount' => $paidAmount,
            'message' => '天阙Pretran流水详情备注已确认收款',
            'chan_order_no' => $tradeNo,
            'chan_trade_no' => $tradeNo,
            'channel_status' => 'receipt_watcher_received',
            'paid_at' => $this->paidAtFromRecord($detail),
        ];
    }

    /**
     * 返回渠道通知成功响应。
     */
    public function notifySuccess(): string|Response
    {
        return 'success';
    }

    /**
     * 返回渠道通知失败响应。
     */
    public function notifyFail(): string|Response
    {
        return 'fail';
    }

    /**
     * 准备金额变动模式的收款金额。
     *
     * 同一收款账号下串行分配未占用的分金额偏移，并在支付单快照中同时保存原金额和实收识别金额。
     *
     * @param string $payNo 本地支付单号
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
                    throw new PaymentException('当前账号可用金额偏移已用尽', 40200);
                }

                $this->persistReceiptMeta($payOrder, [
                    'platform' => $this->getCode(),
                    'mode' => self::MODE_AMOUNT,
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
     * 准备付款备注模式的备注码。
     *
     * @param string $payNo 本地支付单号
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
                    'mode' => self::MODE_REMARK,
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
     * 准备动态收款码模式的二维码。
     *
     * @param string $payNo 本地支付单号
     *
     * @return array<string, mixed>
     */
    private function prepareDynamicReceipt(string $payNo): array
    {
        return $this->withAccountLock(function () use ($payNo): array {
            $payOrder = $this->payOrderRepository->findByPayNo($payNo);
            if (!$payOrder) {
                throw new PaymentException('支付单不存在', 40402, ['pay_no' => $payNo]);
            }

            $originalAmount = $this->originalAmount($payOrder);
            $expireAt = $this->expireAt();
            $remarkCode = $this->allocateRemarkCode($payNo);
            $data = $this->createDynamicQrcode($originalAmount, $remarkCode);
            $qrcode = trim((string) ($data['qrCodeUrl'] ?? ''));
            $uuid = trim((string) ($data['uuid'] ?? ''));
            if ($qrcode === '' || $uuid === '') {
                throw new PaymentException('天阙动态收款码响应缺少二维码或UUID', 40200, ['response' => $data]);
            }

            Db::transaction(function () use ($payNo, $originalAmount, $expireAt, $remarkCode, $qrcode, $uuid, $data): void {
                $payOrder = $this->lockedPayOrder($payNo);
                $this->persistReceiptMeta($payOrder, [
                    'platform' => $this->getCode(),
                    'mode' => self::MODE_DYNAMIC_QRCODE,
                    'original_amount' => $originalAmount,
                    'receipt_amount' => $originalAmount,
                    'remark_code' => $remarkCode,
                    'dynamic_uuid' => $uuid,
                    'dynamic_qrcode_url' => $qrcode,
                    'expire_at' => $expireAt,
                    'raw' => [
                        'uuid' => $uuid,
                        'amt' => (string) ($data['amt'] ?? ''),
                    ],
                ]);
            });

            return [
                'original_amount' => $originalAmount,
                'pay_amount' => $originalAmount,
                'remark_code' => $remarkCode,
                'expire_at' => $expireAt,
                'qrcode' => $qrcode,
                'raw' => [
                    'uuid' => $uuid,
                    'amt' => (string) ($data['amt'] ?? ''),
                ],
            ];
        });
    }

    /**
     * 根据列表流水补查详情并定位支付单。
     *
     * @param array<string, mixed> $payload receipt_watcher 投递的标准流水
     *
     * @return array{pay_no:string, record:array<string,mixed>, detail:array<string,mixed>}
     */
    private function resolveFlow(array $payload): array
    {
        $record = $this->recordPayload($payload);
        $candidates = $this->candidateOrdersByListRecord($record);
        if ($candidates === []) {
            throw new PaymentException('天阙Pretran列表流水未匹配到活跃订单', 40200, ['record' => $record]);
        }

        $detail = $this->queryFlowDetail($record);
        $payNo = $this->selectPayNoByDetail($candidates, $detail);

        return [
            'pay_no' => $payNo,
            'record' => $record,
            'detail' => $detail,
        ];
    }

    /**
     * 根据列表流水筛选候选支付单。
     *
     * 列表字段只用于按通道、金额、支付方式、有效期和订单模式缩小范围，最终确认仍依赖详情。
     *
     * @param array<string, mixed> $record 标准流水记录
     *
     * @return array<int, PayOrder>
     */
    private function candidateOrdersByListRecord(array $record): array
    {
        $amount = $this->moneyToCents((string) ($record['price'] ?? ''));
        $paidAt = $this->paidAtTimestamp($record);
        if ($paidAt === null) {
            throw new PaymentException('天阙Pretran列表流水支付时间不能为空', 40200, ['record' => $record]);
        }

        $orders = $this->payOrderRepository->listMutableReceiptOrdersByAmount(
            $this->receiptChannelIds(),
            $amount,
            $this->payTypeIdFromRecord($record),
            date('Y-m-d H:i:s'),
            ['pay_no', 'pay_amount', 'pay_type_id', 'request_at', 'expire_at', 'ext_json']
        )
            ->filter(fn (PayOrder $payOrder): bool => $this->paidAtInOrderWindow($payOrder, $paidAt)
                && $this->orderMode($payOrder) === $this->receiptMatchMode())
            ->values();

        return $orders->all();
    }

    /**
     * 根据详情备注和金额从候选单中确认唯一支付单。
     *
     * @param array<int, PayOrder> $candidates 候选支付单列表
     * @param array<string, mixed> $detail 流水详情
     */
    private function selectPayNoByDetail(array $candidates, array $detail): string
    {
        $mode = $this->receiptMatchMode();
        $amount = $this->moneyToCents((string) ($detail['price'] ?? ''));
        $paidAt = $this->paidAtTimestamp($detail);
        if ($paidAt === null) {
            throw new PaymentException('天阙Pretran详情流水支付时间不能为空', 40200, ['detail' => $detail]);
        }
        $payTypeId = $this->payTypeIdFromRecord($detail);
        $remark = trim((string) ($detail['remark'] ?? ''));

        $matched = array_values(array_filter($candidates, function (PayOrder $payOrder) use ($mode, $amount, $paidAt, $payTypeId, $remark): bool {
            if (!$this->paidAtInOrderWindow($payOrder, $paidAt)) {
                return false;
            }
            if ($payTypeId > 0 && (int) $payOrder->pay_type_id !== $payTypeId) {
                return false;
            }

            $expectedAmount = $mode === self::MODE_AMOUNT
                ? (int) $payOrder->pay_amount
                : $this->originalAmount($payOrder);
            if ($amount !== $expectedAmount) {
                return false;
            }

            if ($mode !== self::MODE_AMOUNT) {
                $remarkCode = $this->orderRemarkCode($payOrder);
                return $remarkCode !== '' && str_contains($remark, $remarkCode);
            }

            return true;
        }));

        if (count($matched) !== 1) {
            throw new PaymentException('天阙Pretran详情流水未唯一匹配支付单', 40200, [
                'matched_count' => count($matched),
                'mode' => $mode,
                'detail' => $detail,
            ]);
        }

        return (string) $matched[0]->pay_no;
    }

    /**
     * 通过列表 UUID 查询流水详情并归一化字段。
     *
     * 详情必须是收款成功状态，并通过已配置终端号约束后才能进入订单匹配。
     *
     * @param array<string, mixed> $record 标准流水记录
     *
     * @return array<string, mixed>
     */
    private function queryFlowDetail(array $record): array
    {
        $uuid = trim((string) ($record['order_no'] ?? ''));
        if ($uuid === '') {
            throw new PaymentException('天阙Pretran列表流水缺少UUID', 40200, ['record' => $record]);
        }

        $pretranPayType = $this->pretranPayType((string) ($record['pay_type'] ?? ''));
        if ($pretranPayType === '') {
            throw new PaymentException('天阙Pretran流水支付方式不支持详情查询', 40200, ['record' => $record]);
        }

        $data = $this->pretranRequest('POST', '/api/tranInfo/queryTranInfo', [
            'form_params' => [
                'uuid' => $uuid,
                'payType' => $pretranPayType,
                'tranIdent' => '',
            ],
        ]);
        $body = (array) ($data['data'] ?? []);
        $voInfo = (array) ($body['voInfo'] ?? []);
        if ($voInfo === []) {
            throw new PaymentException('天阙Pretran流水详情为空', 40200, ['response' => $data]);
        }

        $orderNo = trim((string) ($voInfo['orderNo'] ?? ''));
        $detailUuid = trim((string) ($voInfo['uuid'] ?? $uuid));
        $payType = $this->normalizePayType((string) ($voInfo['payType'] ?? ''));
        $price = $this->amountText($voInfo['amt'] ?? '');
        $paidAt = trim((string) ($voInfo['payTime'] ?? ''));
        $channelCandidates = array_values(array_unique(array_filter(array_map(
            static fn ($value): string => trim((string) $value),
            [
                $voInfo['deviceNo'] ?? '',
                $voInfo['extend5'] ?? '',
            ]
        ))));
        $channel = (string) ($channelCandidates[0] ?? '');

        if ($payType === '' || $price === '' || $paidAt === '') {
            throw new PaymentException('天阙Pretran流水详情关键字段缺失', 40200, ['voInfo' => $voInfo]);
        }
        if (!$this->detailSucceeded($voInfo)) {
            throw new PaymentException('天阙Pretran流水详情不是收款成功状态', 40200, ['voInfo' => $voInfo]);
        }
        if (!$this->terminalAllowed($channelCandidates)) {
            throw new PaymentException('天阙Pretran流水终端号不匹配', 40200, [
                'expected' => (string) $this->getConfig('receipt_terminal_no', ''),
                'actual' => $channelCandidates,
            ]);
        }

        return [
            'order_no' => $orderNo !== '' ? $orderNo : $detailUuid,
            'flow_uuid' => $detailUuid,
            'pay_type' => $payType,
            'price' => $price,
            'channel' => $channel,
            'remark' => trim((string) ($voInfo['tranExtend'] ?? '')),
            'paid_at' => $paidAt,
            'raw' => [
                'uuid' => $detailUuid,
                'orderNo' => $orderNo,
                'mno' => (string) ($voInfo['mno'] ?? ''),
                'deviceNo' => (string) ($voInfo['deviceNo'] ?? ''),
                'extend5' => (string) ($voInfo['extend5'] ?? ''),
                'storeName' => (string) ($voInfo['storeName'] ?? ''),
                'merName' => (string) ($voInfo['merName'] ?? ''),
                'transactionId' => (string) ($voInfo['transactionId'] ?? ''),
                'tranSts' => (string) ($voInfo['tranSts'] ?? ''),
                'rpMsg' => (string) ($voInfo['rpMsg'] ?? ''),
            ],
        ];
    }

    /**
     * 确认收款后恢复原始订单金额并保存流水摘要。
     *
     * @param string $payNo 本地支付单号
     * @param array<string, mixed> $record 已确认的流水详情
     * @param string $tradeNo 渠道流水号
     * @param int $notifiedAmount 流水实收分金额
     */
    private function restoreOriginalPayAmount(string $payNo, array $record, string $tradeNo, int $notifiedAmount): int
    {
        return Db::transaction(function () use ($payNo, $record, $tradeNo, $notifiedAmount): int {
            $payOrder = $this->lockedPayOrder($payNo);
            $extJson = (array) ($payOrder->ext_json ?? []);
            $receiptMeta = (array) ($extJson['personal_receipt'] ?? []);
            $originalAmount = (int) ($receiptMeta['original_amount'] ?? 0);
            if ($originalAmount > 0) {
                $payOrder->pay_amount = $originalAmount;
            }

            $receiptMeta['notified_at'] = $this->paidAtFromRecord($record) ?? date('Y-m-d H:i:s');
            $receiptMeta['channel_trade_no'] = $tradeNo;
            $receiptMeta['notified_amount'] = $notifiedAmount;
            $receiptMeta['record'] = $record;
            $extJson['personal_receipt'] = $receiptMeta;
            $payOrder->ext_json = $extJson;
            $payOrder->save();

            return $originalAmount > 0 ? $originalAmount : (int) $payOrder->pay_amount;
        });
    }

    /**
     * 保存码牌识别信息到支付单扩展数据。
     *
     * @param PayOrder $payOrder 已锁定的支付单
     * @param array<string, mixed> $meta 码牌识别信息
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
     * 锁定并读取支付单。
     *
     * @param string $payNo 本地支付单号
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
     * 读取支付单原始业务金额。
     *
     * @param PayOrder $payOrder 支付单
     */
    private function originalAmount(PayOrder $payOrder): int
    {
        $extJson = (array) ($payOrder->ext_json ?? []);
        $receiptMeta = (array) ($extJson['personal_receipt'] ?? []);
        $originalAmount = (int) ($receiptMeta['original_amount'] ?? 0);

        return $originalAmount > 0 ? $originalAmount : (int) $payOrder->pay_amount;
    }

    /**
     * 读取支付单创建时使用的匹配模式。
     *
     * @param PayOrder $payOrder 支付单
     */
    private function orderMode(PayOrder $payOrder): string
    {
        $extJson = (array) ($payOrder->ext_json ?? []);
        $receiptMeta = (array) ($extJson['personal_receipt'] ?? []);

        return (string) ($receiptMeta['mode'] ?? $this->receiptMatchMode());
    }

    /**
     * 读取支付单付款备注码。
     *
     * @param PayOrder $payOrder 支付单
     */
    private function orderRemarkCode(PayOrder $payOrder): string
    {
        $extJson = (array) ($payOrder->ext_json ?? []);
        $receiptMeta = (array) ($extJson['personal_receipt'] ?? []);

        return trim((string) ($receiptMeta['remark_code'] ?? ''));
    }

    /**
     * 创建动态收款二维码。
     *
     * @param int $amount 本地分金额
     * @param string $remarkCode 本单付款备注码
     *
     * @return array<string, mixed>
     */
    private function createDynamicQrcode(int $amount, string $remarkCode): array
    {
        $response = $this->pretranRequest('POST', '/api/amtQRCode/createQRCode', [
            'json' => [
                'amt' => FormatHelper::amount($amount),
                'remark' => $remarkCode,
            ],
        ]);

        return (array) ($response['data'] ?? []);
    }

    /**
     * 请求 Pretran 接口并校验响应。
     *
     * 请求强制启用 TLS 证书校验，并使用 receipt_watcher 同步的短期登录态；代理仅在配置显式启用时生效。
     *
     * @param string $method HTTP 方法
     * @param string $path Pretran 接口路径
     * @param array<string, mixed> $options Guzzle 请求参数
     *
     * @return array<string, mixed> 业务成功的 JSON 响应
     */
    private function pretranRequest(string $method, string $path, array $options): array
    {
        $session = $this->redisSession();
        $headers = [
            'accept' => 'application/json, text/plain, */*',
            'channel' => 'WELIFE-WECHAT-MINI',
            'token' => (string) $session['token'],
            'user-agent' => self::USER_AGENT,
            'referer' => 'https://servicewechat.com/wxf502fd842e95fa85/44/page-frame.html',
        ];
        $options['headers'] = array_merge($headers, (array) ($options['headers'] ?? []));
        if (isset($options['json'])) {
            $options['headers']['content-type'] = 'application/json';
        }
        if (isset($options['form_params'])) {
            $options['headers']['content-type'] = 'application/x-www-form-urlencoded';
        }
        $options['verify'] = true;
        $proxy = $this->proxyUrl();
        if ($proxy !== '') {
            $options['proxy'] = $proxy;
        }

        $response = $this->request($method, self::API_BASE_URL . $path, $options);
        $statusCode = $response->getStatusCode();
        $body = (string) $response->getBody();
        if ($statusCode === 401) {
            throw new PaymentException('天阙Pretran登录态已过期，请等待监听工具刷新', 40200, [
                'status_code' => $statusCode,
                'body' => mb_strcut($body, 0, 200, 'UTF-8'),
            ]);
        }
        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new PaymentException('天阙Pretran接口响应不是JSON', 40200, ['body' => mb_strcut($body, 0, 500, 'UTF-8')]);
        }
        if ((string) ($data['code'] ?? '') !== '0000' || ($data['success'] ?? true) === false) {
            throw new PaymentException('天阙Pretran接口返回失败：' . (string) ($data['msg'] ?? '渠道返回失败'), 40200, [
                'code' => (string) ($data['code'] ?? ''),
                'msg' => (string) ($data['msg'] ?? ''),
            ]);
        }

        return $data;
    }

    /**
     * 读取监听工具同步的 Redis 登录态。
     *
     * 缺少 token 或登录态已过期时拒绝继续调用 Pretran，等待监听工具刷新。
     *
     * @return array<string, mixed>
     */
    private function redisSession(): array
    {
        $raw = Redis::get(self::SESSION_KEY_PREFIX . $this->accountKey());
        $payload = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        $session = is_array($payload) ? ($payload['shared_data'] ?? null) : null;
        if (!is_array($session)) {
            throw new PaymentException('天阙Pretran登录态不存在，请先启动监听工具预登录', 40200);
        }
        $token = trim((string) ($session['token'] ?? ''));
        if ($token === '') {
            throw new PaymentException('天阙Pretran登录态缺少token，请等待监听工具刷新', 40200);
        }
        $expiresAt = (int) ($session['expires_at'] ?? 0);
        if ($expiresAt > 0 && $expiresAt <= time()) {
            throw new PaymentException('天阙Pretran登录态已过期，请等待监听工具刷新', 40200);
        }

        return $session;
    }

    /**
     * 返回收银台页面承接参数。
     *
     * @param array<string, mixed> $params 页面展示参数
     * @param string $payNo 本地支付单号
     * @param string $payType 标准支付方式代码
     *
     * @return array<string, mixed> 标准页面承接参数
     */
    private function payResult(array $params, string $payNo, string $payType): array
    {
        $payTypes = $this->getEnabledPayTypes();
        $payType = trim($payType) !== '' ? trim($payType) : (string) ($payTypes[0] ?? 'alipay');

        return [
            'pay_page' => 'page',
            'pay_type' => $payType,
            'pay_product' => 'receipt_plate',
            'pay_action' => $this->receiptMatchMode(),
            'pay_params' => $params,
            'chan_order_no' => '',
            'chan_trade_no' => '',
        ];
    }

    /**
     * 读取并校验订单匹配模式。
     */
    private function receiptMatchMode(): string
    {
        $mode = (string) $this->getConfig('receipt_match_mode', self::MODE_AMOUNT);

        return in_array($mode, [self::MODE_AMOUNT, self::MODE_REMARK, self::MODE_DYNAMIC_QRCODE], true)
            ? $mode
            : self::MODE_AMOUNT;
    }

    /**
     * 读取码牌识别有效期。
     */
    private function receiptValidSeconds(): int
    {
        return max(60, (int) $this->getConfig('receipt_valid_seconds', 300));
    }

    /**
     * 读取金额变动模式最大偏移。
     */
    private function amountOffsetMax(): int
    {
        return min(99, max(0, (int) $this->getConfig('amount_offset_max', 99)));
    }

    /**
     * 计算本次码牌识别过期时间。
     */
    private function expireAt(): string
    {
        return date('Y-m-d H:i:s', time() + $this->receiptValidSeconds());
    }

    /**
     * 分配 4 位付款备注码。
     *
     * @param string $payNo 本地支付单号
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
     * 按收款账号串行执行识别信息分配。
     *
     * 缓存锁使用随机令牌校验所有权，避免一个请求释放另一个请求刚取得的锁。
     *
     * @param callable $callback 持有账号锁时执行的分配逻辑
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
     * 获取当前插件配置关联的通道 ID。
     *
     * 优先返回共享同一插件配置的全部通道，使流水候选范围覆盖真实账号复用关系。
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
     * 根据流水支付方式解析支付方式 ID。
     *
     * @param array<string, mixed> $record 标准流水记录
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
     * 判断流水支付时间是否落在订单识别窗口内。
     *
     * @param PayOrder $payOrder 支付单候选
     * @param int $paidAt 流水支付时间戳
     */
    private function paidAtInOrderWindow(PayOrder $payOrder, int $paidAt): bool
    {
        $requestAt = strtotime((string) $payOrder->request_at) ?: 0;
        $expireAt = strtotime((string) $payOrder->expire_at) ?: 0;

        return $requestAt > 0 && $expireAt > 0 && $paidAt >= $requestAt && $paidAt <= $expireAt;
    }

    /**
     * 读取载荷中的归一化流水记录。
     *
     * @param array<string, mixed> $payload receipt_watcher 投递载荷
     *
     * @return array<string, mixed>
     */
    private function recordPayload(array $payload): array
    {
        return isset($payload['record']) && is_array($payload['record'])
            ? $payload['record']
            : $payload;
    }

    /**
     * 将金额文本转换为分。
     *
     * @param string $money 最多两位小数的非负元金额
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
     * 将平台金额字段规整为两位小数文本。
     *
     * @param mixed $value 平台金额原值
     */
    private function amountText(mixed $value): string
    {
        $text = trim((string) $value);
        if ($text === '') {
            return '';
        }
        if (!is_numeric($text)) {
            return '';
        }

        return number_format((float) $text, 2, '.', '');
    }

    /**
     * 从流水记录读取标准支付时间。
     *
     * @param array<string, mixed> $record 标准流水记录或详情
     */
    private function paidAtFromRecord(array $record): ?string
    {
        $timestamp = $this->paidAtTimestamp($record);

        return $timestamp !== null ? date('Y-m-d H:i:s', $timestamp) : null;
    }

    /**
     * 从流水记录读取支付时间戳。
     *
     * 秒、毫秒时间戳及可解析时间文本会归一为秒级时间戳。
     *
     * @param array<string, mixed> $record 标准流水记录或详情
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

    /**
     * 将平台支付方式转换为系统支付方式。
     *
     * @param string $value 平台支付方式代码
     */
    private function normalizePayType(string $value): string
    {
        return match (strtoupper(trim($value))) {
            'WECHAT' => 'wxpay',
            'ALIPAY' => 'alipay',
            'UNIONPAY' => 'unionpay',
            default => '',
        };
    }

    /**
     * 将系统支付方式转换为平台详情查询参数。
     *
     * @param string $payType 标准支付方式代码
     */
    private function pretranPayType(string $payType): string
    {
        return match ($payType) {
            'wxpay' => 'WECHAT',
            'alipay' => 'ALIPAY',
            'unionpay' => 'UNIONPAY',
            default => '',
        };
    }

    /**
     * 校验流水终端号是否匹配配置。
     *
     * 未配置终端号时不施加此约束；配置后任一详情候选字段匹配即可。
     *
     * @param array<int, string> $channels 流水终端号候选值
     */
    private function terminalAllowed(array $channels): bool
    {
        $expected = trim((string) $this->getConfig('receipt_terminal_no', ''));
        if ($expected === '') {
            return true;
        }

        return in_array($expected, $channels, true);
    }

    /**
     * 判断流水详情是否为收款成功。
     *
     * @param array<string, mixed> $voInfo 平台流水详情
     */
    private function detailSucceeded(array $voInfo): bool
    {
        $payStsString = trim((string) ($voInfo['payStsString'] ?? ''));
        $tranSts = trim((string) ($voInfo['tranSts'] ?? ''));

        return $payStsString === '收款成功' && $tranSts === '0';
    }

    /**
     * 读取用于回填的渠道流水号。
     *
     * @param array<string, mixed> $record 标准流水记录或详情
     */
    private function channelTradeNo(array $record): string
    {
        $orderNo = trim((string) ($record['order_no'] ?? $record['flow_uuid'] ?? ''));
        if ($orderNo === '') {
            throw new PaymentException('流水订单号不能为空', 40200, ['record' => $record]);
        }

        return substr($orderNo, 0, 64);
    }

    /**
     * 生成付款备注码缓存键。
     *
     * @param string $code 四位付款备注码
     */
    private function remarkCacheKey(string $code): string
    {
        return 'mpay_receipt_remark_' . $this->accountKey() . '_' . $code;
    }

    /**
     * 生成流水定位结果缓存键。
     *
     * @param array<string, mixed> $record 标准流水记录
     */
    private function flowResolveCacheKey(array $record): string
    {
        return 'mpay_tianque_pretran_flow_' . $this->accountKey() . '_' . preg_replace(
            '/[^A-Za-z0-9_\-]/',
            '_',
            (string) ($record['order_no'] ?? '')
        );
    }

    /**
     * 生成当前收款账号的稳定标识。
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
     * 读取布尔型插件配置。
     *
     * @param string $key 配置键
     */
    private function configBool(string $key): bool
    {
        $value = $this->getConfig($key, false);
        if (is_string($value)) {
            $value = strtolower(trim($value));
        }

        return in_array($value, [true, 1, '1', 'true'], true);
    }

    /**
     * 读取启用后的代理地址。
     */
    private function proxyUrl(): string
    {
        if (!$this->configBool('proxy_enabled')) {
            return '';
        }
        $value = trim((string) $this->getConfig('receipt_proxy_url', ''));
        if ($value === '') {
            return '';
        }

        return preg_match('#^[a-z][a-z0-9+\-.]*://#i', $value) === 1 ? $value : 'http://' . $value;
    }
}
