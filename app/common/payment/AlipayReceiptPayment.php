<?php

declare(strict_types=1);

namespace app\common\payment;

use app\common\base\BasePayment;
use app\common\constant\PaymentPluginStatusConstant;
use app\common\interface\ChannelNotifyInterface;
use app\common\interface\PaymentInterface;
use app\common\interface\PayPluginInterface;
use app\common\util\FormatHelper;
use app\exception\PaymentException;
use app\model\payment\PayOrder;
use app\repository\payment\trade\PayOrderRepository;
use support\Cache;
use support\Db;
use support\Request;
use support\Response;

/**
 * 支付宝个人收款监听插件。
 *
 * 面向 SmsForwarder 手机通知栏监听场景，不对接支付宝官方 API。
 *
 * 典型流程：
 * 1. pay() 返回个人支付宝收款码，并按配置分配“金额变动”或“付款备注”识别信息。
 * 2. /api/pay/{chanId}/notify 先调用 channelNotify() 定位 pay_no。
 * 3. 服务层确认支付单后再调用 notify()，由插件恢复原始金额并返回标准支付成功结果。
 *
 * 金额口径：
 * - 变动后的金额只用于通知反查订单，不作为业务单统计金额。
 * - 备注模式下，备注码只负责定位候选支付单，通知金额也必须等于订单原始金额。
 * - 通知确认后会把支付单金额恢复到原始金额，并把实际付款金额写入 ext_json。
 */
class AlipayReceiptPayment extends BasePayment implements PaymentInterface, PayPluginInterface, ChannelNotifyInterface
{
    /**
     * 构造方法。
     *
     * 支付单读取统一走仓库，插件只保留收款识别和通知解析逻辑。
     *
     * @param PayOrderRepository $payOrderRepository 支付单仓库
     */
    public function __construct(
        private readonly PayOrderRepository $payOrderRepository
    ) {
    }

    /**
     * 插件元信息和后台配置表单。
     *
     * 这里配置的是个人收款监听所需信息：匹配模式、识别有效期、金额偏移、SmsForwarder 密钥和收款码。
     *
     * @var array<string, mixed>
     */
    protected array $paymentInfo = [
        'code' => 'alipay_receipt',
        'name' => '支付宝个人收款监听',
        'author' => 'MPAY',
        'version' => '1.0.0',
        'pay_types' => ['alipay'],
        'transfer_types' => [],
        'config_schema' => [
            [
                'type' => 'radio',
                'field' => 'receipt_match_mode',
                'title' => '订单匹配模式',
                'value' => 'amount',
                'options' => [
                    ['label' => '金额变动', 'value' => 'amount'],
                    ['label' => '付款备注', 'value' => 'remark'],
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
                'type' => 'password',
                'field' => 'sms_forwarder_secret',
                'title' => 'SmsForwarder密钥',
                'value' => '',
                'props' => [
                    'placeholder' => '用于校验 SmsForwarder sign',
                ],
                'validate' => [
                    ['required' => true, 'message' => 'SmsForwarder密钥不能为空'],
                ],
            ],
            [
                'type' => 'inputNumber',
                'field' => 'sms_forwarder_time_tolerance',
                'title' => '签名时间容差(秒)',
                'value' => 300,
                'props' => [
                    'min' => 30,
                    'max' => 1800,
                    'step' => 30,
                ],
            ],
            [
                'type' => 'textarea',
                'field' => 'receipt_qrcode_content',
                'title' => '支付宝收款码内容',
                'value' => '',
                'props' => [
                    'placeholder' => '可填写支付宝收款码解码后的内容，优先用于二维码承接页',
                    'rows' => 4,
                ],
            ],
            [
                'type' => 'input',
                'field' => 'receipt_qrcode_image',
                'title' => '支付宝收款码图片',
                'value' => '',
                'props' => [
                    'placeholder' => '收款码图片 URL，未配置收款码内容时使用',
                ],
            ],
        ],
    ];

    /**
     * 发起个人收款。
     *
     * 不调用支付宝官方接口，只准备收银台二维码承接参数。
     * 金额模式会分配一个有效期内唯一金额；备注模式会分配一个 4 位备注码缓存。
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
            throw new PaymentException('支付宝个人收款插件未配置收款码', 40200);
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
                ? '请使用支付宝扫码，并在付款备注中填写识别码。'
                : '请使用支付宝扫码，并按页面金额完成付款。',
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

        return $this->payResult('page', $params, $payNo);
    }

    /**
     * 通道级通知定位支付单。
     *
     * 这里是第一阶段，只根据 SmsForwarder 内容确认 pay_no，不做支付成功处理。
     * 后续验签、幂等、订单状态流转仍由支付服务层继续调用 notify() 完成。
     *
     * @param Request $request 请求对象
     * @return array{pay_no:string}
     */
    public function channelNotify(Request $request): array
    {
        $payload = $this->verifiedSmsForwarderPayload($request);

        return ['pay_no' => $this->locatePayNo($payload)];
    }

    /**
     * 主动查单不适用于通知栏监听，保持支付中。
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
            'message' => '个人收款监听通道等待 SmsForwarder 通知',
        ];
    }

    /**
     * 个人收款无上游关单接口。
     *
     * @param array<string, mixed> $order 订单参数
     * @return array<string, mixed>
     */
    public function close(array $order): array
    {
        return [
            'success' => true,
            'msg' => '个人收款监听通道无需上游关单',
        ];
    }

    /**
     * 个人收款不支持接口退款。
     *
     * @param array<string, mixed> $order 订单参数
     * @return array<string, mixed>
     */
    public function refund(array $order): array
    {
        throw new PaymentException('支付宝个人收款监听不支持接口退款', 40200);
    }

    /**
     * 解析并校验 SmsForwarder 通知。
     *
     * 这是第二阶段：服务层确认 pay_no 后调用。插件再次校验通知并恢复原始金额，
     * 然后返回统一的支付成功结果给核心支付流程。
     *
     * @param Request $request 回调请求
     * @return array<string, mixed>
     */
    public function notify(Request $request): array
    {
        $payload = $this->verifiedSmsForwarderPayload($request);
        $content = $this->notificationTextFromPayload($payload);
        $tradeNo = $this->channelTradeNo($payload);
        $payNo = $this->locatePayNo($payload);
        $notifiedAmount = $this->amountFromPayload($payload);

        $this->restoreOriginalPayAmount($payNo, $payload, $tradeNo, $notifiedAmount);

        return [
            'status' => PaymentPluginStatusConstant::SUCCESS,
            'pay_no' => $payNo,
            'message' => mb_strcut(preg_replace('/\s+/', ' ', $content) ?? $content, 0, 180, 'UTF-8'),
            'channel_order_no' => $tradeNo,
            'channel_trade_no' => $tradeNo,
            'channel_status' => 'sms_forwarder_received',
            'paid_at' => $this->paidAtFromPayload($payload),
        ];
    }

    /**
     * 返回监听工具要求的成功应答。
     */
    public function notifySuccess(): string|Response
    {
        return '200';
    }

    /**
     * 返回监听工具要求的失败应答。
     */
    public function notifyFail(): string|Response
    {
        return '400';
    }

    /**
     * 包装个人收款承接页返回值。
     *
     * @param string $payPage 承接页类型
     * @param array<string, mixed> $params 承接参数
     * @param string $payNo 支付单号
     * @return array<string, mixed>
     */
    private function payResult(string $payPage, array $params, string $payNo): array
    {
        return [
            'pay_page' => $payPage,
            'pay_type' => 'alipay',
            'pay_product' => 'receipt',
            'pay_action' => 'sms_forwarder',
            'pay_params' => $params,
            'chan_order_no' => $payNo,
            'chan_trade_no' => '',
        ];
    }

    /**
     * 读取后台配置的订单匹配模式。
     *
     * @return string 匹配模式
     */
    private function receiptMatchMode(): string
    {
        return (string) $this->getConfig('receipt_match_mode', 'amount') === 'remark' ? 'remark' : 'amount';
    }

    /**
     * 读取识别有效期，最低 60 秒。
     *
     * @return int 有效期秒数
     */
    private function receiptValidSeconds(): int
    {
        return max(60, (int) $this->getConfig('receipt_valid_seconds', 300));
    }

    /**
     * 读取最大金额偏移，单位分。
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
     * 在同一通道锁内扫描有效期内待支付订单，始终选择最小可用偏移金额。
     * 例如 10.01 已过期时，会重新使用 10.01，而不是继续累加到 10.04。
     *
     * @param string $payNo 支付单号
     * @return array<string, mixed>
     */
    private function prepareAmountReceipt(string $payNo): array
    {
        return $this->withChannelLock(function () use ($payNo): array {
            return Db::transaction(function () use ($payNo): array {
                $payOrder = $this->lockedPayOrder($payNo);
                $originalAmount = $this->originalAmount($payOrder);
                $expireAt = $this->expireAt();
                $usedAmounts = $this->payOrderRepository->listUsedReceiptAmounts(
                    [(int) $this->getConfig('channel_id')],
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
                    throw new PaymentException('当前通道可用金额偏移已用尽', 40200, [
                        'pay_no' => $payNo,
                        'channel_id' => (int) $this->getConfig('channel_id'),
                    ]);
                }

                $this->persistReceiptMeta($payOrder, [
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
     * 为当前支付单分配 4 位备注码并写入缓存，通知时通过备注码反查 pay_no。
     *
     * @param string $payNo 支付单号
     * @return array<string, mixed>
     */
    private function prepareRemarkReceipt(string $payNo): array
    {
        return $this->withChannelLock(function () use ($payNo): array {
            return Db::transaction(function () use ($payNo): array {
                $payOrder = $this->lockedPayOrder($payNo);
                $originalAmount = $this->originalAmount($payOrder);
                $expireAt = $this->expireAt();
                $remarkCode = $this->allocateRemarkCode($payNo);

                $this->persistReceiptMeta($payOrder, [
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
     * 写入个人收款元数据。
     *
     * 金额模式会临时改写 pay_amount 作为识别金额，原始金额保存在 ext_json.personal_receipt。
     *
     * @param PayOrder $payOrder 支付单
     * @param array<string, mixed> $meta 收款元数据
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
     * 获取原始订单金额。
     *
     * 二次发起或刷新承接页时，优先从 ext_json 读取，避免把上一次偏移金额当成原始金额。
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
     * 通知识别完成后恢复支付单金额，避免变动金额进入业务单统计。
     *
     * @param string $payNo 支付单号
     * @param array<string, mixed> $payload 通知载荷
     * @param string $tradeNo 渠道交易号
     * @param int|null $notifiedAmount 通知中的实际付款金额
     * @return void
     */
    private function restoreOriginalPayAmount(string $payNo, array $payload, string $tradeNo, ?int $notifiedAmount): void
    {
        Db::transaction(function () use ($payNo, $payload, $tradeNo, $notifiedAmount): void {
            $payOrder = $this->lockedPayOrder($payNo);
            $extJson = (array) ($payOrder->ext_json ?? []);
            $receiptMeta = (array) ($extJson['personal_receipt'] ?? []);
            $originalAmount = (int) ($receiptMeta['original_amount'] ?? 0);

            if ($originalAmount > 0) {
                $payOrder->pay_amount = $originalAmount;
            }

            $receiptMeta['notified_at'] = $this->paidAtFromPayload($payload) ?? date('Y-m-d H:i:s');
            $receiptMeta['channel_trade_no'] = $tradeNo;
            if ($notifiedAmount !== null) {
                $receiptMeta['notified_amount'] = $notifiedAmount;
            }
            $extJson['personal_receipt'] = $receiptMeta;
            $payOrder->ext_json = $extJson;
            $payOrder->save();
        });
    }

    /**
     * 申请 4 位备注码。
     *
     * 备注码缓存有效期与订单识别有效期一致，同一通道下短时间内不重复。
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
     * 对同一通道的识别信息分配加锁。
     *
     * 防止并发发起支付时分配到相同金额或相同备注码。
     *
     * @param callable $callback 回调
     * @return mixed
     */
    private function withChannelLock(callable $callback): mixed
    {
        $key = 'mpay_personal_receipt_lock_' . (int) $this->getConfig('channel_id');
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

        throw new PaymentException('当前通道正在分配收款标识，请稍后重试', 40200);
    }

    /**
     * 计算本次个人收款识别的过期时间。
     *
     * @return string 过期时间
     */
    private function expireAt(): string
    {
        return date('Y-m-d H:i:s', time() + $this->receiptValidSeconds());
    }

    /**
     * 校验并读取 SmsForwarder 载荷。
     *
     * 签名规则按 SmsForwarder 文档：使用 timestamp、密钥和 HMAC-SHA256 校验 sign。
     *
     * @param Request $request 请求对象
     * @return array<string, mixed>
     */
    private function verifiedSmsForwarderPayload(Request $request): array
    {
        $payload = $this->requestPayload($request);
        $timestamp = trim((string) ($payload['timestamp'] ?? ''));
        $sign = trim((string) ($payload['sign'] ?? ''));
        $secret = (string) $this->getConfig('sms_forwarder_secret', '');
        if ($timestamp === '' || $sign === '' || $secret === '') {
            throw new PaymentException('SmsForwarder 通知签名参数不完整', 40200);
        }

        $timestampSeconds = (int) floor(((int) $timestamp) / 1000);
        $tolerance = max(30, (int) $this->getConfig('sms_forwarder_time_tolerance', 300));
        if ($timestampSeconds <= 0 || abs(time() - $timestampSeconds) > $tolerance) {
            throw new PaymentException('SmsForwarder 通知时间已失效', 40200);
        }

        $expected = base64_encode(hash_hmac('sha256', $timestamp . "\n" . $secret, $secret, true));
        if (!hash_equals($expected, rawurldecode($sign))) {
            throw new PaymentException('SmsForwarder 通知签名校验失败', 40200);
        }

        if (trim((string) ($payload['content'] ?? '')) === '') {
            throw new PaymentException('SmsForwarder 通知内容为空', 40200);
        }

        $from = trim((string) ($payload['from'] ?? ''));
        if ($from !== '' && $from !== 'com.eg.android.AlipayGphone') {
            throw new PaymentException('非支付宝通知来源', 40200, ['from' => $from]);
        }

        $this->alipayNotificationFromPayload($payload);

        return $payload;
    }

    /**
     * 读取请求载荷。
     *
     * Webman Request 已统一处理 query、form 和 JSON 请求体，这里直接使用 all()。
     *
     * @param Request $request 请求对象
     * @return array<string, mixed>
     */
    private function requestPayload(Request $request): array
    {
        return (array) $request->all();
    }

    /**
     * 金额模式下通过通知金额定位唯一支付单。
     *
     * @param array<string, mixed> $payload 通知载荷
     * @return string 支付单号
     */
    private function locatePayNoByAmount(array $payload): string
    {
        $amount = $this->amountFromPayload($payload);
        $orders = $this->payOrderRepository->listMutableReceiptOrdersByAmount(
            [(int) $this->getConfig('channel_id')],
            $amount,
            0,
            date('Y-m-d H:i:s'),
            ['pay_no']
        );

        if ($orders->count() !== 1) {
            throw new PaymentException('金额通知未匹配到唯一支付单', 40200, [
                'amount' => FormatHelper::amount($amount),
                'matched_count' => $orders->count(),
            ]);
        }

        return (string) $orders->first()->pay_no;
    }

    /**
     * 根据配置选择金额匹配或备注匹配。
     *
     * @param array<string, mixed> $payload 通知载荷
     * @return string 支付单号
     */
    private function locatePayNo(array $payload): string
    {
        return $this->receiptMatchMode() === 'remark'
            ? $this->locatePayNoByRemark($payload)
            : $this->locatePayNoByAmount($payload);
    }

    /**
     * 备注模式下通过缓存中的 4 位备注码和通知金额定位支付单。
     *
     * @param array<string, mixed> $payload 通知载荷
     * @return string 支付单号
     */
    private function locatePayNoByRemark(array $payload): string
    {
        $content = $this->notificationTextFromPayload($payload);
        $remarkCode = $this->remarkFromContent($content);
        $amount = $this->amountFromPayload($payload);
        $payNo = (string) Cache::get($this->remarkCacheKey($remarkCode), '');
        if ($payNo === '') {
            throw new PaymentException('付款备注已失效或不存在', 40200, ['remark_code' => $remarkCode]);
        }

        $payOrder = $this->payOrderRepository->findByPayNo($payNo, ['pay_no', 'pay_amount', 'ext_json']);
        if (!$payOrder || $amount !== $this->originalAmount($payOrder)) {
            throw new PaymentException('付款备注匹配的支付单金额不一致', 40200, [
                'pay_no' => $payNo,
                'remark_code' => $remarkCode,
                'amount' => FormatHelper::amount($amount),
            ]);
        }

        return $payNo;
    }

    /**
     * 从 SmsForwarder content JSON 中提取支付宝通知标题和正文。
     *
     * @param array<string, mixed> $payload 通知载荷
     * @return array{title:string,msg:string}
     */
    private function alipayNotificationFromPayload(array $payload): array
    {
        $content = json_decode((string) ($payload['content'] ?? ''), true);
        if (!is_array($content)) {
            throw new PaymentException('SmsForwarder 通知内容格式不合法', 40200);
        }

        $title = trim((string) ($content['title'] ?? ''));
        $msg = trim((string) ($content['msg'] ?? ''));
        if ($title === '') {
            throw new PaymentException('SmsForwarder 通知标题为空', 40200);
        }
        if ($title === '收款通知' && $msg === '') {
            throw new PaymentException('SmsForwarder 通知正文为空', 40200);
        }

        return ['title' => $title, 'msg' => $msg];
    }

    /**
     * 获取用于展示和备注提取的支付宝通知文本。
     *
     * @param array<string, mixed> $payload 通知载荷
     * @return string 通知文本
     */
    private function notificationTextFromPayload(array $payload): string
    {
        ['title' => $title, 'msg' => $msg] = $this->alipayNotificationFromPayload($payload);

        \support\Log::info('notificationTextFromPayload', ['title' => $title, 'msg' => $msg]);
        return $title === '收款通知' ? $msg : $title;
    }

    /**
     * 从支付宝通知载荷中提取收款金额。
     *
     * @param array<string, mixed> $payload 通知载荷
     * @return int 金额，单位分
     */
    private function amountFromPayload(array $payload): int
    {
        $content = $this->notificationTextFromPayload($payload);
        if (preg_match('/(?:到账|收款|成功收款)\s*(\d+(?:\.\d{1,2})?)\s*元/u', $content, $matches) === 1) {
            return $this->moneyToCents((string) $matches[1]);
        }

        throw new PaymentException('通知内容未识别到收款金额', 40200, ['content' => mb_strcut($content, 0, 180, 'UTF-8')]);
    }

    /**
     * 从通知文本中提取 4 位付款备注码。
     *
     * @param string $content 通知内容
     * @return string 备注码
     */
    private function remarkFromContent(string $content): string
    {
        if (preg_match('/(?:备注|留言|附言|付款备注|收款备注)[:：\s]*([0-9]{4})/u', $content, $matches) !== 1) {
            throw new PaymentException('通知内容未识别到付款备注', 40200);
        }

        return (string) $matches[1];
    }

    /**
     * 将金额文本转换为分。
     *
     * @param string $money 金额文本
     * @return int 金额，单位分
     */
    private function moneyToCents(string $money): int
    {
        if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $money)) {
            throw new PaymentException('通知金额格式不合法', 40200, ['money' => $money]);
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
        return 'mpay_personal_receipt_remark_' . (int) $this->getConfig('channel_id') . '_' . $code;
    }

    /**
     * 为 SmsForwarder 通知生成稳定的渠道交易号。
     *
     * @param array<string, mixed> $payload 通知载荷
     * @return string 渠道交易号
     */
    private function channelTradeNo(array $payload): string
    {
        return 'SF' . substr(hash('sha256', (string) ($payload['from'] ?? '') . '|' . (string) $payload['timestamp'] . '|' . (string) $payload['content']), 0, 30);
    }

    /**
     * 从 SmsForwarder 毫秒时间戳提取支付时间。
     *
     * @param array<string, mixed> $payload 通知载荷
     * @return string|null 支付时间
     */
    private function paidAtFromPayload(array $payload): ?string
    {
        $timestamp = (int) ($payload['timestamp'] ?? 0);
        return $timestamp > 0 ? date('Y-m-d H:i:s', (int) floor($timestamp / 1000)) : null;
    }

}
