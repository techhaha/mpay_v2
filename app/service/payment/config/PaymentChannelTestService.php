<?php

namespace app\service\payment\config;

use app\common\base\BaseService;
use app\common\constant\TradeConstant;
use app\common\constant\EpayProtocolConstant;
use app\common\constant\PaymentIdentityConstant;
use app\common\constant\RouteConstant;
use app\common\util\FormatHelper;
use app\exception\ValidationException;
use app\model\payment\PayOrder;
use app\model\payment\PaymentChannel;
use app\repository\payment\config\PaymentChannelRepository;
use app\repository\payment\config\PaymentPluginConfRepository;
use app\repository\payment\trade\PayOrderRepository;
use app\service\payment\order\PayOrderService;
use support\Log;
use Webman\Exception\BusinessException;

/**
 * 支付通道测试服务。
 *
 * 后台测试直接指定当前通道创建真实支付单并调起插件，不参与商户路由选择。
 */
class PaymentChannelTestService extends BaseService
{
    /**
     * 管理后台通道测试订单号前缀。
     */
    private const ADMIN_ORDER_PREFIX = 'CHTEST';

    /**
     * 商户后台自建通道测试订单号前缀。
     */
    private const MERCHANT_ORDER_PREFIX = 'MCHTEST';

    public function __construct(
        protected PaymentChannelRepository $paymentChannelRepository,
        protected PaymentPluginConfRepository $paymentPluginConfRepository,
        protected PayOrderRepository $payOrderRepository,
        protected PayOrderService $payOrderService
    ) {
    }

    /**
     * 发起通道测试支付。
     *
     * @param int $channelId 支付通道ID
     * @param array<string, mixed> $data 测试入参
     * @return array<string, mixed> 测试订单与支付页信息
     */
    public function submit(int $channelId, array $data): array
    {
        if (!$this->boolConfig('channel_test_enabled', true)) {
            throw new ValidationException('通道测试已关闭');
        }

        $merchantId = (int) sys_config('channel_test_merchant_id', 0, true);
        if ($merchantId <= 0) {
            throw new ValidationException('请先在系统配置中填写测试商户ID');
        }

        $channel = $this->resolveAdminChannel($channelId);

        return $this->submitByChannel($merchantId, $channel, $data, self::ADMIN_ORDER_PREFIX);
    }

    /**
     * 发起商户自建通道测试支付。
     *
     * 商户后台只能测试当前商户自己的自收通道，测试仍会创建真实支付单。
     *
     * @param int $merchantId 当前商户ID
     * @param int $channelId 支付通道ID
     * @param array<string, mixed> $data 测试入参
     * @return array<string, mixed> 测试订单与支付页信息
     */
    public function submitForMerchant(int $merchantId, int $channelId, array $data): array
    {
        if (!$this->boolConfig('channel_test_enabled', true)) {
            throw new ValidationException('通道测试已关闭');
        }
        if ($merchantId <= 0) {
            throw new ValidationException('未获取到当前商户信息');
        }

        $channel = $this->resolveMerchantChannel($merchantId, $channelId);

        return $this->submitByChannel($merchantId, $channel, $data, self::MERCHANT_ORDER_PREFIX);
    }

    /**
     * 按指定通道创建测试支付单。
     *
     * @param int $merchantId 测试商户ID
     * @param PaymentChannel $channel 指定测试通道
     * @param array<string, mixed> $data 测试入参
     * @param string $orderPrefix 测试订单号前缀
     * @return array<string, mixed> 测试订单与支付页信息
     */
    private function submitByChannel(int $merchantId, PaymentChannel $channel, array $data, string $orderPrefix): array
    {
        $channelId = (int) $channel->id;

        $money = trim((string) ($data['money'] ?? ''));
        $payAmount = $this->parseMoneyToAmount($money);
        if ($payAmount <= 0) {
            throw new ValidationException('测试金额不合法');
        }

        $subject = trim((string) ($data['name'] ?? ''));
        if ($subject === '') {
            throw new ValidationException('商品名称不能为空');
        }

        $this->assertChannelAmountAllowed($channel, $payAmount);

        $merchantOrderNo = $this->generateNo($orderPrefix . $channelId);

        try {
            $attempt = $this->payOrderService->preparePayAttemptByChannel([
                'merchant_id' => $merchantId,
                'merchant_order_no' => $merchantOrderNo,
                'pay_type_id' => (int) $channel->pay_type_id,
                'pay_amount' => $payAmount,
                'subject' => $subject,
                'body' => $subject,
                'notify_url' => $this->buildDefaultNotifyUrl(),
                'return_url' => $this->buildDefaultReturnUrl(),
                'client_ip' => trim((string) ($data['client_ip'] ?? '')),
                'device' => $this->resolveTestDevice($data),
                'ext_json' => [
                    '_submit_type' => EpayProtocolConstant::SUBMIT_TYPE_PAGE,
                ],
                'identity_flow' => true,
            ], $channel);
        } catch (BusinessException $e) {
            $payNo = (string) ($e->getData()['pay_no'] ?? '');
            if ($payNo === '') {
                throw $e;
            }

            // 异常已定位真实支付单，直接返回承接地址；数据库故障时不能依赖再次回查。
            $pagePath = $this->buildPaymentPagePath($payNo);
            return [
                'pay_no' => $payNo,
                'merchant_id' => $merchantId,
                'channel_id' => $channelId,
                'money' => FormatHelper::amount($payAmount),
                'payment_page_path' => $pagePath,
                'payment_page_url' => $this->buildSiteUrl($pagePath),
                'error_msg' => $e->getMessage(),
            ];
        }

        if (($attempt['status'] ?? '') === PaymentIdentityConstant::STATUS_REQUIRED) {
            return $this->formatIdentityTestResult($attempt, $merchantId, $channel, $payAmount);
        }

        /** @var PayOrder $payOrder */
        $payOrder = $attempt['pay_order'];
        $result = $this->formatTestResult($payOrder);
        $this->writeDebugLog($result);

        return $result;
    }

    /**
     * 查询后台通道测试记录。
     *
     * 通道测试会创建真实支付单，记录直接从测试单号前缀回查，避免再维护一张状态同步表。
     *
     * @param array<string, mixed> $filters 筛选条件
     * @param int $page 页码
     * @param int $pageSize 每页条数
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator 分页结果
     */
    public function records(array $filters = [], int $page = 1, int $pageSize = 10)
    {
        return $this->recordsByScope(self::ADMIN_ORDER_PREFIX, null, $filters, $page, $pageSize);
    }

    /**
     * 查询商户自建通道测试记录。
     *
     * @param int $merchantId 当前商户ID
     * @param int $channelId 通道ID
     * @param array<string, mixed> $filters 筛选条件
     * @param int $page 页码
     * @param int $pageSize 每页条数
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator 分页结果
     */
    public function merchantRecords(int $merchantId, int $channelId, array $filters = [], int $page = 1, int $pageSize = 10)
    {
        if ($merchantId <= 0) {
            throw new ValidationException('未获取到当前商户信息');
        }

        $channel = $this->resolveMerchantChannel($merchantId, $channelId);
        $filters['channel_id'] = (int) $channel->id;

        return $this->recordsByScope(self::MERCHANT_ORDER_PREFIX, $merchantId, $filters, $page, $pageSize);
    }

    /**
     * 按测试单号前缀查询测试记录。
     *
     * @param string $orderPrefix 测试单号前缀
     * @param int|null $merchantId 指定商户ID，空表示不限制
     * @param array<string, mixed> $filters 筛选条件
     * @param int $page 页码
     * @param int $pageSize 每页条数
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator 分页结果
     */
    private function recordsByScope(string $orderPrefix, ?int $merchantId, array $filters, int $page, int $pageSize)
    {
        $query = $this->payOrderRepository->query()
            ->from('ma_pay_order as po')
            ->leftJoin('ma_biz_order as bo', 'bo.biz_no', '=', 'po.biz_no')
            ->leftJoin('ma_merchant as m', 'm.id', '=', 'po.merchant_id')
            ->leftJoin('ma_payment_channel as c', 'c.id', '=', 'po.channel_id')
            ->leftJoin('ma_payment_type as t', 't.id', '=', 'po.pay_type_id')
            ->where('bo.merchant_order_no', 'like', $orderPrefix . '%')
            ->select([
                'po.id',
                'po.pay_no',
                'po.biz_no',
                'po.merchant_id',
                'po.channel_id',
                'po.pay_type_id',
                'po.pay_amount',
                'po.status',
                'po.channel_order_no',
                'po.channel_trade_no',
                'po.channel_error_code',
                'po.channel_error_msg',
                'po.request_at',
                'po.paid_at',
                'po.failed_at',
                'po.created_at',
                'bo.merchant_order_no',
                'bo.subject',
            ])
            ->selectRaw("COALESCE(m.merchant_no, '') AS merchant_no")
            ->selectRaw("COALESCE(m.merchant_name, '') AS merchant_name")
            ->selectRaw("COALESCE(c.name, '') AS channel_name")
            ->selectRaw("COALESCE(c.plugin_code, '') AS channel_plugin_code")
            ->selectRaw("COALESCE(t.name, '') AS pay_type_name");

        if ($merchantId !== null) {
            $query->where('po.merchant_id', $merchantId);
        }

        $channelId = (string) ($filters['channel_id'] ?? '');
        if ($channelId !== '') {
            $query->where('po.channel_id', (int) $channelId);
        }

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->where(function ($builder) use ($keyword) {
                $builder->where('po.pay_no', 'like', '%' . $keyword . '%')
                    ->orWhere('bo.merchant_order_no', 'like', '%' . $keyword . '%')
                    ->orWhere('c.name', 'like', '%' . $keyword . '%')
                    ->orWhere('c.plugin_code', 'like', '%' . $keyword . '%');
            });
        }

        $paginator = $query
            ->orderByDesc('po.id')
            ->paginate(max(1, $pageSize), ['*'], 'page', max(1, $page));

        $paginator->getCollection()->transform(function ($row) {
            return $this->decorateRecord($row);
        });

        return $paginator;
    }

    /**
     * 解析管理后台可测试的通道。
     *
     * @param int $channelId 通道ID
     * @return PaymentChannel 平台代收通道
     */
    private function resolveAdminChannel(int $channelId): PaymentChannel
    {
        /** @var PaymentChannel|null $channel */
        $channel = $this->paymentChannelRepository->find($channelId);
        if (!$channel) {
            throw new ValidationException('支付通道不存在', ['channel_id' => $channelId]);
        }
        if ((int) $channel->merchant_id !== 0 || (int) $channel->channel_mode !== RouteConstant::CHANNEL_MODE_COLLECT) {
            throw new ValidationException('商户自建通道不能在管理后台测试', [
                'channel_id' => $channelId,
                'merchant_id' => (int) $channel->merchant_id,
            ]);
        }

        return $channel;
    }

    /**
     * 解析商户后台可测试的自建通道。
     *
     * @param int $merchantId 当前商户ID
     * @param int $channelId 通道ID
     * @return PaymentChannel 商户自收通道
     */
    private function resolveMerchantChannel(int $merchantId, int $channelId): PaymentChannel
    {
        /** @var PaymentChannel|null $channel */
        $channel = $this->paymentChannelRepository->findByMerchantAndId($merchantId, $channelId);
        if (!$channel) {
            throw new ValidationException('支付通道不存在', ['channel_id' => $channelId]);
        }
        if ((int) $channel->channel_mode !== RouteConstant::CHANNEL_MODE_SELF) {
            throw new ValidationException('系统分配通道不允许在商户端测试', [
                'channel_id' => $channelId,
                'channel_mode' => (int) $channel->channel_mode,
            ]);
        }

        $configId = (int) $channel->api_config_id;
        if ($configId <= 0) {
            throw new ValidationException('当前通道未绑定商户插件配置', ['channel_id' => $channelId]);
        }

        $config = $this->paymentPluginConfRepository->findByMerchantAndId($merchantId, $configId);
        if (!$config || (string) $config->plugin_code !== (string) $channel->plugin_code) {
            throw new ValidationException('当前通道绑定的插件配置无权使用', [
                'channel_id' => $channelId,
                'api_config_id' => $configId,
            ]);
        }

        return $channel;
    }

    /**
     * 格式化测试结果。
     *
     * @param PayOrder $payOrder 支付单
     * @return array<string, mixed> 测试订单与支付页信息
     */
    private function formatTestResult(PayOrder $payOrder): array
    {
        $payNo = (string) $payOrder->pay_no;
        $pagePath = $this->buildPaymentPagePath($payNo);

        return [
            'pay_no' => $payNo,
            'biz_no' => (string) $payOrder->biz_no,
            'merchant_id' => (int) $payOrder->merchant_id,
            'channel_id' => (int) $payOrder->channel_id,
            'channel_order_no' => (string) ($payOrder->channel_order_no ?? ''),
            'money' => FormatHelper::amount((int) $payOrder->pay_amount),
            'payment_page_path' => $pagePath,
            'payment_page_url' => $this->buildSiteUrl($pagePath),
            'error_msg' => '',
        ];
    }

    /**
     * 格式化需要身份授权的通道测试结果。
     *
     * 微信 JSAPI、微信/支付宝小程序等产品需要先获取用户身份，此时不会立即创建支付单，
     * 后台测试页应跳转到身份承接页，授权完成后再恢复原始测试参数继续创建支付单。
     *
     * @param array<string, mixed> $attempt 身份流程结果
     * @param int $merchantId 测试商户ID
     * @param PaymentChannel $channel 当前测试通道
     * @param int $payAmount 测试金额，单位分
     * @return array<string, mixed> 测试身份流程信息
     */
    private function formatIdentityTestResult(array $attempt, int $merchantId, PaymentChannel $channel, int $payAmount): array
    {
        $result = [
            'pay_no' => '',
            'biz_no' => '',
            'merchant_id' => $merchantId,
            'channel_id' => (int) $channel->id,
            'channel_order_no' => '',
            'money' => FormatHelper::amount($payAmount),
            'payment_page_path' => '',
            'payment_page_url' => '',
            'error_msg' => '',
        ];

        return array_merge($result, $attempt);
    }

    /**
     * 格式化测试记录。
     *
     * @param object $row 测试订单记录
     * @return object 格式化后的记录
     */
    private function decorateRecord(object $row): object
    {
        $statusMap = TradeConstant::orderStatusMap();
        $row->money_text = FormatHelper::amount((int) $row->pay_amount);
        $row->status_text = (string) ($statusMap[(int) $row->status] ?? '未知');
        $row->result_summary = trim((string) ($row->channel_error_msg ?? '')) !== ''
            ? (string) $row->channel_error_msg
            : ($row->status_text === '成功' ? '支付成功' : '已创建测试订单');
        $row->created_at_text = $this->formatDateTime($row->created_at ?? null);
        $row->request_at_text = $this->formatDateTime($row->request_at ?? null);
        $row->paid_at_text = $this->formatDateTime($row->paid_at ?? null);
        $row->failed_at_text = $this->formatDateTime($row->failed_at ?? null);

        return $row;
    }

    /**
     * 按后台测试请求的真实 User-Agent 解析支付环境。
     *
     * 通道测试仍走收银台页面，但支付单 device 必须反映发起测试的浏览器环境。
     *
     * @param array<string, mixed> $data 测试入参
     * @return string 支付环境
     */
    private function resolveTestDevice(array $data): string
    {
        $device = strtolower(trim((string) ($data['device'] ?? 'auto')));
        if ($device !== '' && $device !== 'auto' && in_array($device, EpayProtocolConstant::v1Devices(), true)) {
            return $device;
        }

        $userAgent = strtolower(trim((string) ($data['user_agent'] ?? '')));

        if (str_contains($userAgent, 'alipayclient')) {
            return EpayProtocolConstant::DEVICE_ALIPAY;
        }
        if (str_contains($userAgent, 'micromessenger')) {
            return EpayProtocolConstant::DEVICE_WECHAT;
        }
        if (str_contains($userAgent, ' qq/') || str_contains($userAgent, 'mqqbrowser')) {
            return EpayProtocolConstant::DEVICE_QQ;
        }
        if (preg_match('/mobile|android|iphone|ipad|ipod|windows phone/i', $userAgent) === 1) {
            return EpayProtocolConstant::DEVICE_MOBILE;
        }

        return EpayProtocolConstant::DEVICE_PC;
    }

    /**
     * 校验测试金额是否落在通道金额区间内。
     *
     * @param PaymentChannel $channel 支付通道
     * @param int $payAmount 支付金额，单位分
     * @return void
     */
    private function assertChannelAmountAllowed(PaymentChannel $channel, int $payAmount): void
    {
        if ((int) $channel->min_amount > 0 && $payAmount < (int) $channel->min_amount) {
            throw new ValidationException('测试金额低于通道最小金额', [
                'min_amount' => FormatHelper::amount((int) $channel->min_amount),
            ]);
        }

        if ((int) $channel->max_amount > 0 && $payAmount > (int) $channel->max_amount) {
            throw new ValidationException('测试金额高于通道最大金额', [
                'max_amount' => FormatHelper::amount((int) $channel->max_amount),
            ]);
        }
    }

    /**
     * 将元金额转成分。
     *
     * @param string $money 金额字符串
     * @return int 金额分值，非法时返回 0
     */
    private function parseMoneyToAmount(string $money): int
    {
        $money = trim($money);
        if ($money === '' || !preg_match('/^\d+(?:\.\d{1,2})?$/', $money)) {
            return 0;
        }

        [$integer, $fraction] = array_pad(explode('.', $money, 2), 2, '');
        $fraction = str_pad($fraction, 2, '0');

        return ((int) $integer) * 100 + (int) substr($fraction, 0, 2);
    }

    /**
     * 构建支付页相对路径。
     *
     * @param string $payNo 支付单号
     * @return string 支付页路径
     */
    private function buildPaymentPagePath(string $payNo): string
    {
        return '/payment/' . rawurlencode($payNo);
    }

    /**
     * 构建站点完整地址。
     *
     * @param string $path 站内路径
     * @return string URL
     */
    private function buildSiteUrl(string $path): string
    {
        $siteUrl = rtrim((string) sys_config('site_url'), '/');

        return $siteUrl !== '' ? $siteUrl . $path : $path;
    }

    /**
     * 构建默认同步跳转地址。
     *
     * @return string 默认同步跳转地址
     */
    private function buildDefaultReturnUrl(): string
    {
        return trim((string) sys_config('channel_test_return_url', ''));
    }

    /**
     * 构建测试异步通知地址。
     *
     * @return string 测试通知地址
     */
    private function buildDefaultNotifyUrl(): string
    {
        return trim((string) sys_config('channel_test_notify_url', ''));
    }

    /**
     * 按配置记录测试调试日志。
     *
     * @param array<string, mixed> $result 测试结果
     * @return void
     */
    private function writeDebugLog(array $result): void
    {
        if (!$this->boolConfig('channel_test_debug_log_enabled', false)) {
            return;
        }

        Log::info('[PaymentChannelTest] 通道测试支付已创建 ' . json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * 读取布尔配置。
     *
     * @param string $key 配置键
     * @param bool $default 默认值
     * @return bool 布尔值
     */
    private function boolConfig(string $key, bool $default): bool
    {
        $value = strtolower(trim((string) sys_config($key, $default ? '1' : '0')));

        return in_array($value, ['1', 'true', 'yes', 'on', 'enabled'], true);
    }
}
