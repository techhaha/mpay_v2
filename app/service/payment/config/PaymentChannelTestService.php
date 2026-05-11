<?php

namespace app\service\payment\config;

use app\common\base\BaseService;
use app\common\constant\EpayProtocolConstant;
use app\common\util\FormatHelper;
use app\exception\PaymentException;
use app\exception\ValidationException;
use app\model\payment\PayOrder;
use app\model\payment\PaymentChannel;
use app\repository\payment\config\PaymentChannelRepository;
use app\repository\payment\trade\BizOrderRepository;
use app\repository\payment\trade\PayOrderRepository;
use app\service\payment\order\PayOrderService;
use support\Log;

/**
 * 支付通道测试服务。
 *
 * 后台测试直接指定当前通道创建真实支付单并调起插件，不参与商户路由选择。
 */
class PaymentChannelTestService extends BaseService
{
    public function __construct(
        protected PaymentChannelRepository $paymentChannelRepository,
        protected BizOrderRepository $bizOrderRepository,
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

        /** @var PaymentChannel|null $channel */
        $channel = $this->paymentChannelRepository->find($channelId);
        if (!$channel) {
            throw new ValidationException('支付通道不存在', ['channel_id' => $channelId]);
        }

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

        $merchantOrderNo = $this->generateNo('CHTEST' . $channelId);

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
                'device' => 'pc',
                'ext_json' => [
                    '_submit_type' => EpayProtocolConstant::SUBMIT_TYPE_PAGE,
                ],
            ], $channel);
        } catch (PaymentException $e) {
            $payOrder = $this->findPayOrderByMerchantOrderNo($merchantId, $merchantOrderNo);
            if ($payOrder) {
                return $this->formatTestResult($payOrder, $e->getMessage());
            }

            throw $e;
        }

        /** @var PayOrder $payOrder */
        $payOrder = $attempt['pay_order'];
        $result = $this->formatTestResult($payOrder);
        $this->writeDebugLog($result);

        return $result;
    }

    /**
     * 根据测试商户订单号查询支付单。
     *
     * @param int $merchantId 商户ID
     * @param string $merchantOrderNo 商户订单号
     * @return PayOrder|null 支付单
     */
    private function findPayOrderByMerchantOrderNo(int $merchantId, string $merchantOrderNo): ?PayOrder
    {
        $bizOrder = $this->bizOrderRepository->findByMerchantAndOrderNo($merchantId, $merchantOrderNo);
        if (!$bizOrder) {
            return null;
        }

        return $this->payOrderRepository->findLatestByBizNo((string) $bizOrder->biz_no);
    }

    /**
     * 格式化测试结果。
     *
     * @param PayOrder $payOrder 支付单
     * @param string $errorMessage 错误消息，非空时返回给后台提示，承接页从订单快照读取错误
     * @return array<string, mixed> 测试订单与支付页信息
     */
    private function formatTestResult(PayOrder $payOrder, string $errorMessage = ''): array
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
            'error_msg' => $errorMessage,
        ];
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
        $configuredUrl = trim((string) sys_config('channel_test_return_url', ''));
        if ($configuredUrl !== '') {
            return $configuredUrl;
        }

        $siteUrl = rtrim((string) sys_config('site_url'), '/');

        return $siteUrl !== '' ? $siteUrl . '/payment' : '';
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
