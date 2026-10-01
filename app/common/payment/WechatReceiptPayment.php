<?php

declare(strict_types=1);

namespace app\common\payment;

use app\common\base\BasePayment;
use app\common\constant\PaymentPluginTypeConstant;
use app\common\constant\FileConstant;
use app\common\constant\PaymentPluginStatusConstant;
use app\common\interface\ChannelNotifyInterface;
use app\common\interface\PaymentInterface;
use app\common\interface\PayPluginInterface;
use app\common\util\FormatHelper;
use app\exception\PaymentException;
use app\exception\UnsupportedPaymentOperationException;
use app\model\payment\PayOrder;
use app\repository\payment\trade\PayOrderRepository;
use DateTimeImmutable;
use DateTimeZone;
use DOMDocument;
use DOMXPath;
use support\Cache;
use support\Db;
use support\Request;
use support\Response;

/**
 * 微信个人收款监听插件。
 *
 * 面向 SmsForwarder 手机通知栏与 wxtool PC 公众号监听场景，不对接微信官方支付 API。
 *
 * 典型流程：
 * 1. pay() 返回个人微信收款码，并按配置分配“金额变动”或“付款备注”识别信息。
 * 2. 两类监听工具都向 /api/pay/{chanId}/notify 提交签名表单，插件先定位 pay_no。
 * 3. 服务层确认支付单后再调用 notify()，由插件重新验签、恢复原始金额并返回标准结果。
 *
 * 金额口径：
 * - 变动后的金额只用于通知反查订单，不作为业务单统计金额。
 * - 备注模式下，备注码只负责定位候选支付单，通知金额也必须等于订单原始金额。
 * - 通知确认后会把支付单金额恢复到原始金额，并把实际付款金额写入 ext_json。
 */
class WechatReceiptPayment extends BasePayment implements PaymentInterface, PayPluginInterface, ChannelNotifyInterface
{
    private const WECHAT_PAY_OFFICIAL_ID = 'gh_3dfda90e39d6';

    private const WECHAT_RECEIPT_ASSISTANT_ID = 'gh_f0a92aa7146c';

    /** @var array<string, string> wxtool 允许投递的公众号 ID 与名称。 */
    private const WXTOOL_ACCOUNTS = [
        self::WECHAT_PAY_OFFICIAL_ID => '微信支付',
        self::WECHAT_RECEIPT_ASSISTANT_ID => '微信收款助手',
    ];

    private const WXTOOL_EVENT = 'wxtool.officialAccount.message';

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
     * 这里配置的是个人收款监听所需信息：匹配模式、识别有效期、金额偏移、通知密钥和收款码。
     * `sms_forwarder_secret` 保留稳定字段名，SmsForwarder 与 wxtool 使用同一签名密钥。
     *
     * @var array<string, mixed>
     */
    protected array $paymentInfo = [
        'code' => 'wxpay_receipt',
        'name' => '微信个人收款监听',
        'plugin_type' => PaymentPluginTypeConstant::TYPE_HANGUP,
        'author' => 'MPAY',
        'version' => '1.0.0',
        'pay_types' => ['wxpay'],
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
                'title' => '监听通知密钥',
                'value' => '',
                'props' => [
                    'placeholder' => '须与 SmsForwarder 或 wxtool 通知通道的密钥一致',
                ],
                'validate' => [
                    ['required' => true, 'message' => '监听通知密钥不能为空'],
                ],
            ],
            [
                'type' => 'inputNumber',
                'field' => 'sms_forwarder_time_tolerance',
                'title' => '通知时间容差(秒)',
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
                'title' => '微信收款码内容',
                'value' => '',
                'props' => [
                    'placeholder' => '可填写微信收款码解码后的内容，优先用于二维码承接页',
                    'rows' => 4,
                ],
            ],
            [
                'type' => 'upload',
                'field' => 'receipt_qrcode_image',
                'title' => '微信收款码图片',
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
     * 发起个人收款。
     *
     * 不调用微信官方支付接口，只准备收银台二维码承接参数。
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
            throw new PaymentException('微信个人收款插件未配置收款码', 40200);
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
                ? '请使用微信扫码，并在付款备注中填写识别码。'
                : '请使用微信扫码，并按页面金额完成付款。',
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

        return $this->pendingPaymentResult($order, $this->payResult('page', $params, $payNo));
    }

    /**
     * 通道级通知定位支付单。
     *
     * 此入口识别 SmsForwarder 或 wxtool 协议，验签并定位 pay_no，但不直接推进支付状态。
     * 服务层完成幂等编排后会调用 notify() 重新校验通知并生成标准支付结果。
     *
     * @param Request $request 请求对象
     * @return array{pay_no:string}
     */
    public function channelNotify(Request $request): array
    {
        $payload = $this->verifiedReceiptPayload($request);

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
            'status' => PaymentPluginStatusConstant::PENDING,
            'pay_no' => (string) ($order['pay_no'] ?? ''),
            'paid_amount' => null,
            'chan_order_no' => (string) ($order['chan_order_no'] ?? ''),
            'chan_trade_no' => (string) ($order['chan_trade_no'] ?? ''),
            'message' => '个人收款监听通道等待手机或 PC 监听通知',
        ];
    }

    /**
     * 结束本地个人收款等待状态。
     *
     * 通知栏监听没有上游订单可关闭，因此该结果只表达本地终止等待。
     *
     * @param array<string, mixed> $order 订单参数
     * @return array<string, mixed>
     */
    public function close(array $order): array
    {
        return [
            'status' => PaymentPluginStatusConstant::CLOSED,
            'pay_no' => (string) ($order['pay_no'] ?? ''),
            'chan_order_no' => (string) ($order['chan_order_no'] ?? ''),
            'chan_trade_no' => (string) ($order['chan_trade_no'] ?? ''),
            'message' => '个人收款监听通道无需上游关单',
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
        throw new UnsupportedPaymentOperationException('微信个人收款监听不支持接口退款', 40200);
    }

    /**
     * 解析并校验手机通知栏或 PC 公众号通知。
     *
     * 这是第二阶段：服务层确认 pay_no 后调用。插件再次校验通知并恢复原始金额，
     * 然后返回统一的支付成功结果给核心支付流程。
     *
     * @param Request $request 回调请求
     * @return array<string, mixed>
     */
    public function notify(Request $request): array
    {
        $payload = $this->verifiedReceiptPayload($request);
        ['msg' => $content] = $this->wechatNotificationFromPayload($payload);
        $tradeNo = $this->channelTradeNo($payload);
        $payNo = $this->locatePayNo($payload);
        $notifiedAmount = $this->amountFromPayload($payload);

        $paidAmount = $this->restoreOriginalPayAmount($payNo, $payload, $tradeNo, $notifiedAmount);

        return [
            'status' => PaymentPluginStatusConstant::SUCCESS,
            'pay_no' => $payNo,
            'paid_amount' => $paidAmount,
            'message' => mb_strcut(preg_replace('/\s+/', ' ', $content) ?? $content, 0, 180, 'UTF-8'),
            'chan_order_no' => $tradeNo,
            'chan_trade_no' => $tradeNo,
            'channel_status' => ($payload['_receipt_source'] ?? '') === 'wxtool'
                ? 'wxtool_received'
                : 'sms_forwarder_received',
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
            'pay_type' => 'wxpay',
            'pay_product' => 'receipt',
            'pay_action' => 'sms_forwarder',
            'pay_params' => $params,
            'chan_order_no' => '',
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
     * @return int 原始业务金额，单位分
     */
    private function restoreOriginalPayAmount(string $payNo, array $payload, string $tradeNo, ?int $notifiedAmount): int
    {
        return Db::transaction(function () use ($payNo, $payload, $tradeNo, $notifiedAmount): int {
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

            return $originalAmount > 0 ? $originalAmount : (int) $payOrder->pay_amount;
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
     * 验证监听工具表单并归一化为现有微信通知结构。
     *
     * 两类工具共用 timestamp、sign、content 和 from 四字段表单及同一 HMAC 密钥。
     * SmsForwarder 的 content 是通知栏 JSON；wxtool 的 content 是公众号完整消息事件。
     * 归一化后，订单匹配与金额恢复不区分通知来自手机还是 PC。
     *
     * @return array<string, mixed> 可供现有订单匹配逻辑使用的微信通知载荷
     * @throws PaymentException 签名、来源、消息身份或收款模板无效时抛出
     */
    private function verifiedReceiptPayload(Request $request): array
    {
        $fields = $this->requestPayload($request);
        $content = (string) ($fields['content'] ?? '');
        if ($content === '' || strlen($content) > 2 * 1024 * 1024) {
            throw new PaymentException('监听通知内容为空或过大', 40200);
        }

        $decoded = json_decode($content, true);
        if (is_array($decoded) && !array_is_list($decoded)
            && ($decoded['event'] ?? '') === self::WXTOOL_EVENT) {
            return $this->verifiedWxtoolPayload($fields, $decoded);
        }

        return $this->verifiedSmsForwarderPayload($fields);
    }

    /**
     * 校验 SmsForwarder 通知栏载荷。
     *
     * @param array<string, mixed> $payload 四字段表单
     * @return array<string, mixed> 原 SmsForwarder 载荷
     */
    private function verifiedSmsForwarderPayload(array $payload): array
    {
        $this->assertSignedPayload($payload);
        $from = trim((string) ($payload['from'] ?? ''));
        if ($from !== '' && $from !== 'com.tencent.mm') {
            throw new PaymentException('非微信通知来源', 40200, ['from' => $from]);
        }

        $this->wechatNotificationFromPayload($payload);
        return $payload;
    }

    /**
     * 校验 wxtool 公众号完整消息并转换为通知栏等价结构。
     *
     * 这里只接受“微信支付”和“微信收款助手”两个固定公众号、incoming appmsg
     * 以及已确认的收款模板。消息游标组成稳定渠道流水号，投递路由和重试次数
     * 不参与编号。
     *
     * @param array<string, mixed> $fields 四字段表单
     * @param array<string, mixed> $payload wxtool content 事件
     * @return array<string, mixed> 归一化微信通知载荷
     */
    private function verifiedWxtoolPayload(array $fields, array $payload): array
    {
        $this->assertSignedPayload($fields);
        $accountId = (string) ($fields['from'] ?? '');
        $accountName = self::WXTOOL_ACCOUNTS[$accountId] ?? '';
        $message = (array) ($payload['message'] ?? []);
        if ($accountName === ''
            || (string) ($payload['account']['username'] ?? '') !== $accountId
            || (string) ($message['username'] ?? '') !== $accountId
            || (string) ($message['source'] ?? '') !== 'message'
            || ($message['isFallback'] ?? false) !== false) {
            throw new PaymentException('PC 监听通知不是受支持公众号的完整消息', 40200);
        }
        if ((string) ($message['direction'] ?? '') !== 'incoming'
            || (string) ($message['senderUsername'] ?? '') !== $accountId) {
            throw new PaymentException('PC 监听通知的公众号发送方无法验证', 40200);
        }
        $messageTime = (int) ($message['createTime'] ?? 0);
        if ($messageTime <= 0 || $messageTime > time() + 300) {
            throw new PaymentException('PC 监听通知的消息时间无效', 40200);
        }
        $appMessage = $this->wxtoolAppMessage((string) ($message['content'] ?? ''));
        if (!hash_equals($accountId, $appMessage['from_username'])) {
            throw new PaymentException('PC 监听通知的 XML 发送方不一致', 40200);
        }

        $receipt = $this->wxtoolReceipt($accountId, $appMessage, $messageTime);
        $normalizedContent = json_encode([
            'title' => $accountName,
            'msg' => $appMessage['title'] . "\n" . $appMessage['description'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($normalizedContent === false) {
            throw new PaymentException('PC 监听通知内容无法归一化', 40200);
        }

        return [
            'from' => $accountId,
            'timestamp' => (string) ($receipt['event_at'] * 1000),
            'content' => $normalizedContent,
            '_receipt_source' => 'wxtool',
            '_receipt_scene' => $receipt['scene'],
            '_payer_remark' => $receipt['payer_remark'],
            '_notified_amount' => $receipt['amount'],
            '_channel_trade_no' => $this->wxtoolTradeNo($payload, $message, $accountId, $messageTime),
        ];
    }

    /**
     * 校验监听表单签名和投递时间。
     *
     * 签名原文为毫秒 timestamp、换行和 secret，HMAC-SHA256 密钥也是 secret；
     * Base64 结果由发送端 URL 编码。该规则与 SmsForwarder 和 wxtool 当前协议一致。
     *
     * @param array<string, mixed> $fields 四字段表单
     */
    private function assertSignedPayload(array $fields): void
    {
        $timestamp = trim((string) ($fields['timestamp'] ?? ''));
        $sign = trim((string) ($fields['sign'] ?? ''));
        $secret = trim((string) $this->getConfig('sms_forwarder_secret', ''));
        if (preg_match('/^\d{13}$/', $timestamp) !== 1 || $sign === '' || $secret === '') {
            throw new PaymentException('监听通知签名参数不完整', 40200);
        }

        $tolerance = min(1800, max(30, (int) $this->getConfig('sms_forwarder_time_tolerance', 300)));
        if (abs(time() - intdiv((int) $timestamp, 1000)) > $tolerance) {
            throw new PaymentException('监听通知时间已失效', 40200);
        }

        $expected = base64_encode(hash_hmac('sha256', $timestamp . "\n" . $secret, $secret, true));
        if (!hash_equals($expected, rawurldecode($sign))) {
            throw new PaymentException('监听通知签名校验失败', 40200);
        }
    }

    /**
     * 提取 wxtool appmsg 的消息身份和明细键值。
     *
     * @return array{title:string,description:string,from_username:string,fields:array<string,string>}
     */
    private function wxtoolAppMessage(string $content): array
    {
        if ($content === '' || strlen($content) > 2 * 1024 * 1024
            || stripos($content, '<!DOCTYPE') !== false
            || stripos($content, '<!ENTITY') !== false) {
            throw new PaymentException('PC 监听公众号消息 XML 不合法', 40200);
        }

        $old = libxml_use_internal_errors(true);
        try {
            $xml = new DOMDocument();
            if (!$xml->loadXML($content, LIBXML_NONET | LIBXML_NOBLANKS)) {
                throw new PaymentException('PC 监听公众号消息 XML 解析失败', 40200);
            }
            $xpath = new DOMXPath($xml);
            $titles = $xpath->query('/msg/appmsg/title');
            $descriptions = $xpath->query('/msg/appmsg/des');
            $senders = $xpath->query('/msg/fromusername');
            if ($titles === false || $descriptions === false || $senders === false
                || $titles->length !== 1 || $descriptions->length !== 1 || $senders->length !== 1) {
                throw new PaymentException('PC 监听公众号消息缺少唯一消息身份', 40200);
            }

            $fields = [];
            $lines = $xpath->query(
                '/msg/appmsg/mmreader/template_detail/line_content/topline'
                . ' | /msg/appmsg/mmreader/template_detail/line_content/lines/line'
            );
            if ($lines === false || $lines->length === 0) {
                throw new PaymentException('PC 监听公众号消息缺少收款明细', 40200);
            }
            foreach ($lines as $line) {
                $keys = $xpath->query('./key/word', $line);
                $values = $xpath->query('./value/word', $line);
                if ($keys === false || $values === false
                    || $keys->length !== 1 || $values->length !== 1) {
                    throw new PaymentException('PC 监听公众号消息明细结构不一致', 40200);
                }
                $key = trim($keys->item(0)->textContent);
                if ($key === '' || array_key_exists($key, $fields)) {
                    throw new PaymentException('PC 监听公众号消息明细字段为空或重复', 40200);
                }
                $fields[$key] = trim($values->item(0)->textContent);
            }

            return [
                'title' => trim($titles->item(0)->textContent),
                'description' => trim($descriptions->item(0)->textContent),
                'from_username' => trim($senders->item(0)->textContent),
                'fields' => $fields,
            ];
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($old);
        }
    }

    /**
     * 按固定公众号模板确认收款场景、金额和业务到账时间。
     *
     * 个人码和店员通知可以携带“付款方备注”；经营码和赞赏码中的“备注”是系统
     * 状态或汇总，不作为付款备注。标题金额必须与结构化“收款金额”一致。
     *
     * @param array{title:string,description:string,from_username:string,
     *     fields:array<string,string>} $appMessage appmsg 结构
     * @return array{amount:int,event_at:int,scene:string,payer_remark:string}
     */
    private function wxtoolReceipt(
        string $accountId,
        array $appMessage,
        int $messageTime
    ): array {
        $title = $appMessage['title'];
        $fields = $appMessage['fields'];
        if ($accountId === self::WECHAT_PAY_OFFICIAL_ID) {
            $titlePattern = '/^二维码赞赏到账(\d+(?:\.\d{1,2})?)元(?:$|[（(])/u';
            $scene = 'reward';
        } elseif ($accountId === self::WECHAT_RECEIPT_ASSISTANT_ID) {
            if (array_key_exists('收款店铺', $fields)) {
                $titlePattern = '/^微信支付收款(\d+(?:\.\d{1,2})?)元(?:$|[（(])/u';
                $scene = 'business';
                $status = (string) ($fields['备注'] ?? '');
                if (!str_contains($status, '收款成功') || !str_contains($status, '经营账户')) {
                    throw new PaymentException('经营码消息未确认收款成功', 40200);
                }
            } elseif (str_starts_with($title, '[店员消息]')) {
                $titlePattern = '/^\[店员消息\]收款到账(\d+(?:\.\d{1,2})?)元$/u';
                $scene = 'clerk';
                $explanation = (string) ($fields['说明'] ?? '');
                if (!str_contains($explanation, '已存入店长')
                    || !str_contains($explanation, '零钱')) {
                    throw new PaymentException('店员消息未确认收款成功', 40200);
                }
            } else {
                $titlePattern = '/^微信支付收款(\d+(?:\.\d{1,2})?)元(?:$|[（(])/u';
                $scene = 'personal';
                $status = (string) ($fields['备注'] ?? '');
                if (!str_contains($status, '收款成功') || !str_contains($status, '零钱')) {
                    throw new PaymentException('个人码消息未确认收款成功', 40200);
                }
            }
        } else {
            throw new PaymentException('PC 监听消息的公众号不受支持', 40200);
        }
        if (preg_match($titlePattern, $title, $titleMatch) !== 1
            || preg_match('/^[¥￥]\s*(\d+(?:\.\d{1,2})?)\s*(?:元)?$/u', (string) ($fields['收款金额'] ?? ''), $amountMatch) !== 1) {
            throw new PaymentException('PC 监听消息不是支持的微信收款模板', 40200);
        }

        $titleAmount = $this->moneyToCents((string) $titleMatch[1]);
        $fieldAmount = $this->moneyToCents((string) $amountMatch[1]);
        if ($titleAmount <= 0 || $titleAmount !== $fieldAmount) {
            throw new PaymentException('PC 监听消息的标题金额与收款金额不一致', 40200);
        }

        $eventAt = $messageTime;
        if ($accountId === self::WECHAT_PAY_OFFICIAL_ID) {
            $paidAt = (string) ($fields['到账时间'] ?? '');
            if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $paidAt) !== 1) {
                throw new PaymentException('微信支付收款消息缺少到账时间', 40200);
            }
            $date = DateTimeImmutable::createFromFormat(
                '!Y-m-d H:i:s',
                $paidAt,
                new DateTimeZone('Asia/Shanghai')
            );
            if (!$date || $date->format('Y-m-d H:i:s') !== $paidAt) {
                throw new PaymentException('微信支付收款消息的到账时间无效', 40200);
            }
            $eventAt = $date->getTimestamp();
        }

        return [
            'amount' => $titleAmount,
            'event_at' => $eventAt,
            'scene' => $scene,
            'payer_remark' => trim((string) ($fields['付款方备注'] ?? '')),
        ];
    }

    /**
     * 使用公众号消息身份生成稳定渠道流水号。
     *
     * messageKey 必须与公众号和三个消息游标字段完全一致；同一消息重投时编号不变。
     */
    private function wxtoolTradeNo(
        array $payload,
        array $message,
        string $accountId,
        int $messageTime
    ): string {
        $key = (string) ($payload['delivery']['messageKey'] ?? '');
        $parts = explode(':', $key);
        if (count($parts) !== 5
            || !ctype_digit($parts[0]) || (int) $parts[0] <= 0
            || $parts[1] !== $accountId
            || !ctype_digit($parts[2]) || !ctype_digit($parts[4])
            || $parts[2] !== (string) ($message['sortSeq'] ?? '')
            || $parts[3] !== (string) $messageTime
            || $parts[4] !== (string) ($message['localId'] ?? '')) {
            throw new PaymentException('PC 监听公众号消息缺少一致的消息标识', 40200);
        }

        $time = (new DateTimeImmutable('@' . $messageTime))
            ->setTimezone(new DateTimeZone('Asia/Shanghai'))
            ->format('YmdHis');
        return 'WX' . $time . substr(hash('sha256', $key), 0, 16);
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
     * 备注模式下通过缓存中的 4 位备注码和通知金额定位支付单。
     *
     * @param array<string, mixed> $payload 通知载荷
     * @return string 支付单号
     */
    private function locatePayNoByRemark(array $payload): string
    {
        $remarkCode = $this->remarkFromPayload($payload);
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
     * 从归一化 content JSON 中提取微信通知标题和正文。
     *
     * @param array<string, mixed> $payload 通知载荷
     * @return array{title:string,msg:string}
     */
    private function wechatNotificationFromPayload(array $payload): array
    {
        $content = json_decode((string) ($payload['content'] ?? ''), true);
        if (!is_array($content)) {
            throw new PaymentException('微信收款通知内容格式不合法', 40200);
        }

        $title = trim((string) ($content['title'] ?? ''));
        $msg = trim((string) ($content['msg'] ?? ''));
        if ($title === '' || $msg === '') {
            throw new PaymentException('微信收款通知标题或正文为空', 40200);
        }

        return ['title' => $title, 'msg' => $msg];
    }

    /**
     * 从微信通知载荷中按标题类型提取收款金额。
     *
     * @param array<string, mixed> $payload 通知载荷
     * @return int 金额，单位分
     */
    private function amountFromPayload(array $payload): int
    {
        if (array_key_exists('_notified_amount', $payload)) {
            $amount = (int) $payload['_notified_amount'];
            if ($amount <= 0) {
                throw new PaymentException('PC 监听通知金额无效', 40200);
            }
            return $amount;
        }

        ['title' => $title, 'msg' => $msg] = $this->wechatNotificationFromPayload($payload);
        if (!in_array($title, ['微信支付', '微信收款助手', '微信收款商业版'], true)) {
            throw new PaymentException('未支持的微信通知标题', 40200, ['title' => $title]);
        }

        if (preg_match('/(?:到账|收款|收款到账)\s*[¥￥]?\s*(\d+(?:\.\d{1,2})?)\s*(?:元)?/u', $msg, $matches) === 1) {
            return $this->moneyToCents((string) $matches[1]);
        }

        throw new PaymentException('通知内容未识别到收款金额', 40200, ['content' => mb_strcut($msg, 0, 180, 'UTF-8')]);
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
     * 从对应监听协议提取订单备注码。
     *
     * wxtool 只使用结构化“付款方备注”，避免把经营码或赞赏码的系统“备注”
     * 当作用户输入；SmsForwarder 继续兼容既有通知栏文本解析。
     */
    private function remarkFromPayload(array $payload): string
    {
        if (($payload['_receipt_source'] ?? '') === 'wxtool') {
            $remark = trim((string) ($payload['_payer_remark'] ?? ''));
            if (preg_match('/^\d{4}$/', $remark) !== 1) {
                throw new PaymentException('PC 收款通知没有有效的 4 位付款备注', 40200);
            }
            return $remark;
        }

        ['msg' => $content] = $this->wechatNotificationFromPayload($payload);
        return $this->remarkFromContent($content);
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
     * 读取监听工具提供的稳定渠道交易号。
     *
     * @param array<string, mixed> $payload 通知载荷
     * @return string 渠道交易号
     */
    private function channelTradeNo(array $payload): string
    {
        $tradeNo = (string) ($payload['_channel_trade_no'] ?? '');
        if ($tradeNo !== '') {
            return $tradeNo;
        }

        return 'SF' . substr(hash('sha256', (string) ($payload['from'] ?? '') . '|' . (string) $payload['timestamp'] . '|' . (string) $payload['content']), 0, 30);
    }

    /**
     * 从归一化毫秒时间戳提取支付时间。
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
