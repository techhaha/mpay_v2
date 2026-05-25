<?php

declare(strict_types=1);

namespace app\common\payment;

use app\common\base\BasePayment;
use app\common\constant\FileConstant;
use app\common\constant\PaymentPluginStatusConstant;
use app\common\interface\ChannelNotifyPayloadInterface;
use app\common\interface\PaymentInterface;
use app\common\interface\PayPluginInterface;
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
 * 支付宝账单收款监听插件。
 *
 * 适用场景：
 * - 商户使用个人支付宝收款码收款。
 * - 支付宝不会把这类线下收款主动回调到本系统。
 * - Python receipt_watcher 通过支付宝账务明细接口查询流水，再把归一化流水投递到 Redis 队列。
 *
 * 职责边界：
 * - 本插件负责生成收银台二维码承接参数、分配金额/备注识别信息、根据流水定位支付单。
 * - Webman 队列服务负责调用 channelNotifyPayload() 定位 pay_no，再调用 notifyPayload() 取得标准通知结果。
 * - 支付宝接口签名、验签和分页查询属于 receipt_watcher 平台适配逻辑。
 *
 * 金额口径：
 * - 金额变动只用于识别订单，系统统计仍以原始订单金额为准。
 * - notifyPayload() 确认流水前会把支付单金额恢复为原始金额，并把实际付款金额写入 ext_json。
 */
class AlipayBillReceiptPayment extends BasePayment implements PaymentInterface, PayPluginInterface, ChannelNotifyPayloadInterface
{
    /**
     * 构造方法。
     *
     * 插件内保留业务判断，数据读取统一交给仓库，避免在插件里散落模型查询。
     *
     * @param PayOrderRepository $payOrderRepository 支付单仓库
     * @param PaymentChannelRepository $paymentChannelRepository 支付通道仓库
     * @param PaymentTypeRepository $paymentTypeRepository 支付方式仓库
     */
    public function __construct(
        private readonly PayOrderRepository $payOrderRepository,
        private readonly PaymentChannelRepository $paymentChannelRepository,
        private readonly PaymentTypeRepository $paymentTypeRepository
    ) {
    }

    /**
     * 插件元信息和后台配置表单。
     *
     * 配置中只保存业务所需字段。支付宝账务明细接口固定使用密钥模式，接口地址、
     * 签名参数组装和响应验签由 receipt_watcher 平台适配类维护。
     *
     * @var array<string, mixed>
     */
    protected array $paymentInfo = [
        'code' => 'alipay_bill_receipt',
        'name' => '支付宝账单收款',
        'author' => 'MPAY',
        'version' => '1.0.0',
        'pay_types' => ['alipay'],
        'transfer_types' => [],
        'config_schema' => [
            [
                'type' => 'input',
                'field' => 'app_id',
                'title' => '支付宝应用 AppID',
                'value' => '',
                'validate' => [
                    ['required' => true, 'message' => '支付宝应用 AppID 不能为空'],
                ],
            ],
            [
                'type' => 'textarea',
                'field' => 'private_key',
                'title' => '应用私钥',
                'value' => '',
                'props' => [
                    'rows' => 5,
                    'placeholder' => '请输入 RSA2 应用私钥',
                ],
                'validate' => [
                    ['required' => true, 'message' => '应用私钥不能为空'],
                ],
            ],
            [
                'type' => 'textarea',
                'field' => 'alipay_public_key',
                'title' => '支付宝公钥',
                'value' => '',
                'props' => [
                    'rows' => 4,
                    'placeholder' => '请输入支付宝公钥，用于账单接口响应验签',
                ],
                'validate' => [
                    ['required' => true, 'message' => '支付宝公钥不能为空'],
                ],
            ],
            [
                'type' => 'input',
                'field' => 'bill_user_id',
                'title' => '支付宝用户ID',
                'value' => '',
                'props' => [
                    'placeholder' => '请输入 2088 开头的支付宝用户 ID',
                ],
                'validate' => [
                    ['required' => true, 'message' => '支付宝用户ID不能为空'],
                ],
            ],
            [
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
                'type' => 'textarea',
                'field' => 'receipt_qrcode_content',
                'title' => '支付宝收款码内容',
                'value' => '',
                'props' => [
                    'placeholder' => '可填写支付宝收款码解析后的内容，优先用于收银台展示',
                    'rows' => 4,
                ],
            ],
            [
                'type' => 'upload',
                'field' => 'receipt_qrcode_image',
                'title' => '支付宝收款码图片',
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
        ],
    ];

    /**
     * 发起支付宝账单收款。
     *
     * 这里不会调用上游 API，只做三件事：
     * 1. 根据配置选择金额变动或付款备注模式。
     * 2. 写入本次识别所需的订单元数据和过期时间。
     * 3. 返回收银台 `receiptQrcode` 页面需要的二维码、金额、备注码和倒计时参数。
     *
     * @param array<string, mixed> $order 标准插件下单参数
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
            throw new PaymentException('支付宝账单收款插件未配置收款码', 40200);
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
     * 主动查单由 receipt_watcher 完成，这里保持支付中。
     *
     * 支付运行时的主动查单会调用插件 query()，但账单流水由 receipt_watcher 查询。
     * 真正的流水查询由 Python receipt_watcher 负责，本方法只返回 pending，避免误推进状态。
     *
     * @param array<string, mixed> $order 订单参数
     * @return array<string, mixed>
     */
    public function query(array $order): array
    {
        return [
            'success' => true,
            'status' => PaymentPluginStatusConstant::PENDING,
            'channel_order_no' => (string) ($order['channel_order_no'] ?? $order['pay_no'] ?? ''),
            'channel_trade_no' => (string) ($order['channel_trade_no'] ?? $order['pay_no'] ?? ''),
            'message' => '等待 receipt_watcher 查询支付宝账单流水',
        ];
    }

    /**
     * 支付宝账单收款无上游关单接口。
     *
     * @param array<string, mixed> $order 订单参数
     * @return array<string, mixed>
     */
    public function close(array $order): array
    {
        return [
            'success' => true,
            'msg' => '支付宝账单收款无需上游关单',
        ];
    }

    /**
     * 支付宝账单收款不支持接口退款。
     *
     * @param array<string, mixed> $order 订单参数
     * @return array<string, mixed>
     */
    public function refund(array $order): array
    {
        throw new PaymentException('支付宝账单收款不支持接口退款', 40200);
    }

    /**
     * HTTP 手工通知入口。
     *
     * 该插件正式链路是 Redis 队列数组载荷。这里保留 HTTP notify() 是为了人工重放
     * 或调试同一份归一化流水，内部直接复用 notifyPayload()，不再单独维护两套解析逻辑。
     *
     * @param Request $request 请求对象
     * @return array<string, mixed>
     */
    public function notify(Request $request): array
    {
        return $this->notifyPayload((array) $request->all());
    }

    /**
     * 根据归一化流水定位支付单。
     *
     * ChannelNotifyPayloadInterface 的第一阶段：只确认这条流水对应哪个 pay_no。
     * 不在这里推进订单状态，也不写回调日志，后续由服务层再调用 notifyPayload()。
     *
     * @param array<string, mixed> $payload 通知载荷
     * @return array{pay_no:string}
     */
    public function channelNotifyPayload(array $payload): array
    {
        return ['pay_no' => $this->locatePayNo($payload)];
    }

    /**
     * 解析归一化流水为标准支付成功通知。
     *
     * ChannelNotifyPayloadInterface 的第二阶段：生成标准插件通知结果。
     * 服务层会校验返回结构，并复用统一的订单状态推进、回调日志和商户通知链路。
     *
     * 注意：金额变动模式下，支付单 pay_amount 在这里恢复为原始金额；流水中的实际付款金额
     * 仅写入 ext_json.personal_receipt.notified_amount 供排查。
     *
     * @param array<string, mixed> $payload 通知载荷
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
            'message' => 'receipt_watcher 已确认支付宝账单流水',
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
     * 组装收银台承接返回。
     *
     * pay_product/pay_action 用于前端和日志识别这是支付宝账单流水监听场景；
     * chan_order_no 暂用系统支付单号，因为平台真实流水号要等 receipt_watcher 查询后才知道。
     *
     * @param array<string, mixed> $params 承接参数
     * @param string $payNo 支付单号
     * @param string $payType 支付方式
     * @return array<string, mixed>
     */
    private function payResult(array $params, string $payNo, string $payType): array
    {
        $payType = trim($payType) !== '' ? trim($payType) : 'alipay';

        return [
            'pay_page' => 'page',
            'pay_type' => $payType,
            'pay_product' => 'alipay_bill_receipt',
            'pay_action' => 'bill_watcher',
            'pay_params' => $params,
            'chan_order_no' => $payNo,
            'chan_trade_no' => '',
        ];
    }

    /**
     * 读取订单匹配模式。
     *
     * 只允许 `amount` 和 `remark` 两种模式，非法配置值按 `amount` 处理。
     *
     * @return string 匹配模式
     */
    private function receiptMatchMode(): string
    {
        return (string) $this->getConfig('receipt_match_mode', 'amount') === 'remark' ? 'remark' : 'amount';
    }

    /**
     * 读取收款识别有效期。
     *
     * 有效期用于订单过期时间、金额占用窗口、备注码缓存时间。
     *
     * @return int 有效期秒数
     */
    private function receiptValidSeconds(): int
    {
        return max(60, (int) $this->getConfig('receipt_valid_seconds', 300));
    }

    /**
     * 读取最大金额偏移。
     *
     * 单位是分。默认最多 +0.99，超过后直接失败，不继续扩大金额范围。
     *
     * @return int 最大金额偏移，单位分
     */
    private function amountOffsetMax(): int
    {
        return min(99, max(0, (int) $this->getConfig('amount_offset_max', 99)));
    }

    /**
     * 准备金额变动收款参数。
     *
     * 同一收款账号内，查找有效期内已经占用的金额，从原始金额开始按 0.01 递增寻找
     * 最小可用金额。例如 10.01 已过期时，新的订单可以重新使用 10.01。
     *
     * @param string $payNo 支付单号
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
                    'platform' => 'alipay_bill_receipt',
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
     * 准备备注收款参数。
     *
     * 备注模式不改变实际付款金额，只为本次订单生成 4 位识别码。
     * receipt_watcher 查询到流水后，插件会从 remark 字段中提取该识别码定位订单。
     *
     * @param string $payNo 支付单号
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
                    'platform' => 'alipay_bill_receipt',
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
     * 加锁读取支付单。
     *
     * pay() 准备识别信息和 notifyPayload() 恢复原始金额都需要在事务内锁定同一支付单，
     * 避免并发通知或重复发起导致金额和扩展信息交叉覆盖。
     *
     * @param string $payNo 支付单号
     * @return PayOrder
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
     * 写入收款识别元数据。
     *
     * personal_receipt 是该类插件的专用扩展分区：
     * - original_amount 保存业务原始金额。
     * - receipt_amount 保存当次页面提示付款金额。
     * - offset_amount 保存金额偏移。
     * - remark_code 保存备注识别码。
     * - expire_at 保存本次识别有效期。
     *
     * @param PayOrder $payOrder 支付单
     * @param array<string, mixed> $meta 元数据
     * @return void
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
     * 读取原始订单金额。
     *
     * 支付单可能已被金额变动模式临时改成识别金额，因此优先从
     * ext_json.personal_receipt.original_amount 取业务原始金额。
     *
     * @param PayOrder $payOrder 支付单
     * @return int 原始订单金额，单位分
     */
    private function originalAmount(PayOrder $payOrder): int
    {
        $extJson = (array) ($payOrder->ext_json ?? []);
        $receiptMeta = (array) ($extJson['personal_receipt'] ?? []);
        $originalAmount = (int) ($receiptMeta['original_amount'] ?? 0);

        return $originalAmount > 0 ? $originalAmount : (int) $payOrder->pay_amount;
    }

    /**
     * 通知识别完成后恢复支付单金额。
     *
     * 这是金额变动模式的关键收口：流水确认后先把 pay_amount 恢复为 original_amount，
     * 再返回标准成功结果，确保后续账户入账、清算、统计都按业务订单原始金额计算。
     *
     * @param string $payNo 支付单号
     * @param array<string, mixed> $record 流水记录
     * @param string $tradeNo 第三方流水号
     * @param int|null $notifiedAmount 实际付款金额
     * @return void
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
     * 定位支付单号。
     *
     * 匹配顺序：
     * 1. 如果流水带平台订单号，优先按 channel_order_no/channel_trade_no 匹配。
     * 2. 否则按当前插件配置走付款备注或金额变动模式。
     *
     * @param array<string, mixed> $payload 通知载荷
     * @return string 支付单号
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
     * 通过平台流水号定位支付单。
     *
     * 已经成功处理过的流水会把平台订单号回填到 channel_order_no/channel_trade_no，
     * 后续重复流水可以优先命中这里，减少金额或备注重复判断的不确定性。
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
     * 通过流水金额定位支付单。
     *
     * 只查同一插件配置账号下仍在有效期内的可变状态订单。若支付方式字段存在，
     * 会进一步按支付方式过滤。多条候选时，选取离流水支付时间最近的订单并记录日志。
     *
     * @param array<string, mixed> $record 流水记录
     * @return string 支付单号
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
                '[AlipayBillReceiptPayment] 金额流水匹配到多笔订单，按时间最近选择 amount=%s count=%d',
                FormatHelper::amount($amount),
                $orders->count()
            ));
        }

        return $this->closestPayNo($orders->all(), $paidAt);
    }

    /**
     * 通过流水备注定位支付单。
     *
     * 优先读取备注码缓存；缓存失效时，再回查有效订单 ext_json 中的 remark_code，
     * 用于处理缓存短暂不可用或重启后仍在订单有效期内的情况。
     *
     * @param array<string, mixed> $record 流水记录
     * @return string 支付单号
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
                '[AlipayBillReceiptPayment] 备注流水匹配到多笔订单，按时间最近选择 remark=%s count=%d',
                $remarkCode,
                $candidates->count()
            ));
        }

        return $this->closestPayNo($candidates->all(), $paidAt);
    }

    /**
     * 从候选订单中选择离流水支付时间最近的一笔。
     *
     * 该策略用于处理同一金额或同一备注码匹配到多笔有效订单的情况。
     *
     * @param array<int, PayOrder> $orders 候选订单
     * @param int|null $paidAt 支付时间戳
     * @return string 支付单号
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
     * 判断流水支付时间是否落在订单有效期内。
     *
     * 金额识别只认订单发起后、过期前的流水，避免历史同金额流水误确认当前订单。
     *
     * @param PayOrder $payOrder 支付单
     * @param int $paidAt 流水支付时间戳
     * @return bool 是否在有效期内
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
     * 判断备注流水是否满足订单金额和有效期。
     *
     * 备注码只负责定位候选订单，实际确认还必须校验付款金额等于订单原始金额。
     *
     * @param PayOrder $payOrder 支付单
     * @param int $amount 流水金额，单位分
     * @param int $paidAt 流水支付时间戳
     * @return bool 是否匹配
     */
    private function remarkOrderMatchesFlow(PayOrder $payOrder, int $amount, int $paidAt): bool
    {
        return $amount === $this->originalAmount($payOrder)
            && $this->paidAtInOrderWindow($payOrder, $paidAt);
    }

    /**
     * 从队列消息中提取归一化流水记录。
     *
     * receipt_watcher 队列通常传 `{record: {...}}`；HTTP 手工重放时也允许直接传流水字段。
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
     * 根据流水支付方式编码解析支付方式 ID。
     *
     * 支付宝账单流水固定为支付宝支付方式，保留 pay_type 解析用于对齐监听插件统一结构。
     *
     * @param array<string, mixed> $record 流水记录
     * @return int 支付方式ID
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
     * 获取当前收款账号对应的所有通道 ID。
     *
     * 同一个支付宝账单插件配置可以绑定多个通道，金额占用和流水匹配必须在同一
     * api_config_id 的所有通道内进行，而不是只看当前支付方式通道。
     *
     * @return array<int, int> 当前账号关联的通道ID
     */
    private function receiptChannelIds(): array
    {
        $ids = $this->paymentChannelRepository->idsByPluginConfig(
            (string) $this->getConfig('plugin_code', 'alipay_bill_receipt'),
            (int) $this->getConfig('api_config_id')
        );

        return $ids !== [] ? $ids : [(int) $this->getConfig('channel_id')];
    }

    /**
     * 申请 4 位备注码。
     *
     * 备注码按账号维度加缓存，避免同一个收款账号的有效订单拿到相同识别码。
     *
     * @param string $payNo 支付单号
     * @return string 备注码
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
     * 执行账号级互斥锁。
     *
     * 金额变动和备注码都按账号维度分配。同一账号并发发起支付时必须串行处理，
     * 否则可能出现两个订单拿到相同金额或相同备注码。
     *
     * @param callable $callback 回调
     * @return mixed
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
     * 计算本次识别过期时间。
     *
     * @return string 过期时间
     */
    private function expireAt(): string
    {
        return date('Y-m-d H:i:s', time() + $this->receiptValidSeconds());
    }

    /**
     * 从流水备注中提取 4 位识别码。
     *
     * @param array<string, mixed> $record 流水记录
     * @return string 备注码
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
     * 将平台金额字符串转换为分。
     *
     * @param string $money 金额文本
     * @return int 金额，单位分
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
     * 生成备注码缓存键。
     *
     * @param string $code 备注码
     * @return string 缓存键
     */
    private function remarkCacheKey(string $code): string
    {
        return 'mpay_receipt_remark_' . $this->accountKey() . '_' . $code;
    }

    /**
     * 生成账号维度业务键。
     *
     * 使用插件编码和 api_config_id，确保同一个收款账号下的多个支付方式共用金额和备注池。
     *
     * @return string 账号键
     */
    private function accountKey(): string
    {
        return preg_replace(
            '/[^A-Za-z0-9_\\-]/',
            '_',
            (string) $this->getConfig('plugin_code', 'alipay_bill_receipt') . '_' . (int) $this->getConfig('api_config_id')
        ) ?: 'alipay_bill_receipt_0';
    }

    /**
     * 生成渠道交易号。
     *
     * receipt_watcher 已保证支付宝账单流水的 order_no 固定来自 alipay_order_no。
     *
     * @param array<string, mixed> $record 流水记录
     * @return string 第三方流水号
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
     * 从流水中解析支付时间。
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
     * 从流水中解析支付时间戳。
     *
     * 支持秒级时间戳、毫秒级时间戳和可被 strtotime 识别的时间字符串。
     *
     * @param array<string, mixed> $record 流水记录
     * @return int|null 支付时间戳
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
