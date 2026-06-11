<?php

namespace app\command;

use app\common\constant\CommonConstant;
use app\common\constant\RouteConstant;
use app\common\constant\NotifyConstant;
use app\common\constant\PaymentPluginStatusConstant;
use app\common\util\RsaKeyPairGenerator;
use app\model\payment\PayOrder;
use app\model\payment\PaymentChannel;
use app\model\payment\PaymentPlugin;
use app\service\payment\epay\Md5Signer;
use app\service\payment\epay\RsaSigner;
use app\service\payment\order\PayOrderCallbackService;
use app\service\payment\order\PaymentPluginNotifyResultValidator;
use app\service\payment\order\PaymentPluginPayResultValidator;
use app\service\payment\runtime\NotifyService;
use app\service\payment\runtime\PaymentRouteResolverService;
use app\service\payment\transfer\TransferService;
use app\service\system\config\SystemConfigRuntimeService;
use ReflectionClass;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * MPAY 主链路轻量单元测试命令。
 *
 * 不依赖真实第三方通道和数据库写入，用于固定签名、插件契约、金额解析与路由过滤等核心规则。
 */
#[AsCommand('mpay:unit-test', '运行 MPAY 主链路轻量单元测试')]
class MpayUnitTest extends Command
{
    /**
     * @var array<int, string>
     */
    private array $failures = [];

    /**
     * 执行测试。
     *
     * @param InputInterface $input 命令输入
     * @param OutputInterface $output 输出对象
     * @return int 命令退出码
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $cases = [
            'epay.md5_signer' => fn () => $this->testMd5Signer(),
            'epay.rsa_signer' => fn () => $this->testRsaSigner(),
            'plugin.pay_result_contract' => fn () => $this->testPaymentPluginPayResultContract(),
            'plugin.notify_result_contract' => fn () => $this->testPaymentPluginNotifyResultContract(),
            'transfer.money_parser' => fn () => $this->testTransferMoneyParser(),
            'route.amount_and_daily_limit' => fn () => $this->testRouteAmountAndDailyLimit(),
            'route.default_channel_selection' => fn () => $this->testRouteDefaultChannelSelection(),
            'route.reject_reasons' => fn () => $this->testRouteRejectReasons(),
            'callback.payload_contract' => fn () => $this->testCallbackPayloadContract(),
            'callback.duplicate_request_hash' => fn () => $this->testCallbackDuplicateRequestHash(),
            'notify.retry_policy' => fn () => $this->testNotifyRetryPolicy(),
            'security.sensitive_masking' => fn () => $this->testSensitiveMasking(),
        ];

        $passed = 0;
        foreach ($cases as $name => $case) {
            try {
                $case();
                $passed++;
                $output->writeln(sprintf('<info>[通过]</info> %s', $name));
            } catch (Throwable $e) {
                $this->failures[] = sprintf('%s: %s', $name, $e->getMessage());
                $output->writeln(sprintf('<error>[失败]</error> %s - %s', $name, $e->getMessage()));
            }
        }

        $output->writeln(sprintf('汇总: %d 通过, %d 失败', $passed, count($this->failures)));
        foreach ($this->failures as $failure) {
            $output->writeln('<error>- ' . $failure . '</error>');
        }

        return $this->failures === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * ePay V1 MD5 签名规则。
     *
     * @return void
     */
    private function testMd5Signer(): void
    {
        $signer = new Md5Signer();
        $params = [
            'b' => '2',
            'a' => '1',
            'empty' => '',
            'sign_type' => 'MD5',
            'sign' => 'old',
        ];

        $signature = $signer->sign($params, 'secret');
        $this->assertSame(md5('a=1&b=2secret'), $signature, 'MD5 签名原文排序或字段排除不符合预期');
        $this->assertTrue($signer->verify($params, strtoupper($signature), 'secret'), 'MD5 验签应忽略大小写');
    }

    /**
     * ePay V2 RSA 签名规则。
     *
     * @return void
     */
    private function testRsaSigner(): void
    {
        $pair = RsaKeyPairGenerator::generate(1024);
        $signer = new RsaSigner();
        $params = [
            'pid' => '10001',
            'money' => '10.00',
            'out_trade_no' => 'T202605170001',
            'sign_type' => 'RSA',
        ];

        $signature = $signer->sign($params, $pair['private_key']);
        $this->assertTrue($signer->verify($params, $signature, $pair['public_key']), 'RSA 签名后应能用同组公钥验签');
        $tampered = $params;
        $tampered['money'] = '10.01';
        $this->assertFalse($signer->verify($tampered, $signature, $pair['public_key']), 'RSA 篡改参数后不应验签通过');
    }

    /**
     * 插件下单返回契约。
     *
     * @return void
     */
    private function testPaymentPluginPayResultContract(): void
    {
        $valid = [
            'pay_page' => 'qrcode',
            'pay_type' => 'alipay',
            'pay_product' => 'scan',
            'pay_action' => 'qrcode',
            'pay_params' => ['qrcode' => 'https://example.test/pay'],
            'chan_order_no' => 'C202605170001',
        ];
        $result = PaymentPluginPayResultValidator::make($valid)->withScene('pay_result')->validate();
        $this->assertSame('qrcode', (string) $result['pay_page'], '插件下单有效返回应通过校验');

        $invalid = $valid;
        $invalid['chan_order_no'] = '';
        $this->assertThrows(
            fn () => PaymentPluginPayResultValidator::make($invalid)->withScene('pay_result')->validate(),
            '非 error/html/ok 的承接页必须返回渠道订单号'
        );
    }

    /**
     * 插件回调返回契约。
     *
     * @return void
     */
    private function testPaymentPluginNotifyResultContract(): void
    {
        $valid = [
            'status' => 'success',
            'pay_no' => 'P202605170001',
            'channel_order_no' => 'C202605170001',
            'channel_trade_no' => 'T202605170001',
            'paid_at' => '2026-05-17 12:00:00',
        ];
        $result = PaymentPluginNotifyResultValidator::make($valid)->withScene('notify_result')->validate();
        $this->assertSame('success', (string) $result['status'], '插件回调有效返回应通过校验');

        $invalid = $valid;
        $invalid['status'] = 'done';
        $this->assertThrows(
            fn () => PaymentPluginNotifyResultValidator::make($invalid)->withScene('notify_result')->validate(),
            '插件回调状态只能是 success/failed/pending'
        );
    }

    /**
     * 转账金额字符串解析。
     *
     * @return void
     */
    private function testTransferMoneyParser(): void
    {
        $service = (new ReflectionClass(TransferService::class))->newInstanceWithoutConstructor();
        $parse = $this->privateMethod(TransferService::class, 'parseMoneyToAmount');

        $this->assertSame(1, $parse->invoke($service, '0.01'), '0.01 应解析为 1 分');
        $this->assertSame(1000, $parse->invoke($service, '10'), '10 应解析为 1000 分');
        $this->assertSame(1203, $parse->invoke($service, '12.03'), '12.03 应解析为 1203 分');
        $this->assertSame(0, $parse->invoke($service, '12.345'), '超过两位小数应视为非法');
        $this->assertSame(0, $parse->invoke($service, 'abc'), '非数字金额应视为非法');
    }

    /**
     * 路由金额区间与日限额过滤。
     *
     * @return void
     */
    private function testRouteAmountAndDailyLimit(): void
    {
        $resolver = (new ReflectionClass(PaymentRouteResolverService::class))->newInstanceWithoutConstructor();
        $amountAllowed = $this->privateMethod(PaymentRouteResolverService::class, 'isAmountAllowed');
        $dailyAllowed = $this->privateMethod(PaymentRouteResolverService::class, 'isDailyLimitAllowed');

        $channel = $this->channel([
            'id' => 1,
            'min_amount' => 100,
            'max_amount' => 10000,
            'daily_limit_amount' => 20000,
            'daily_limit_count' => 2,
        ]);

        $this->assertTrue($amountAllowed->invoke($resolver, $channel, 100), '等于最小金额应允许');
        $this->assertFalse($amountAllowed->invoke($resolver, $channel, 99), '低于最小金额应拒绝');
        $this->assertFalse($amountAllowed->invoke($resolver, $channel, 10001), '高于最大金额应拒绝');

        $stat = (object) ['pay_amount' => 15000, 'pay_success_count' => 1];
        $this->assertTrue($dailyAllowed->invoke($resolver, $channel, 5000, '2026-05-17', $stat), '未超过日金额和日笔数应允许');
        $this->assertFalse($dailyAllowed->invoke($resolver, $channel, 5001, '2026-05-17', $stat), '超过日金额应拒绝');

        $stat = (object) ['pay_amount' => 1000, 'pay_success_count' => 2];
        $this->assertFalse($dailyAllowed->invoke($resolver, $channel, 100, '2026-05-17', $stat), '超过日笔数应拒绝');
    }

    /**
     * 默认通道选择规则。
     *
     * @return void
     */
    private function testRouteDefaultChannelSelection(): void
    {
        $resolver = (new ReflectionClass(PaymentRouteResolverService::class))->newInstanceWithoutConstructor();
        $sort = $this->privateMethod(PaymentRouteResolverService::class, 'sortCandidates');
        $select = $this->privateMethod(PaymentRouteResolverService::class, 'selectDefaultChannel');

        $candidates = [
            $this->candidate(2, 0, 1, 10),
            $this->candidate(1, 1, 2, 10),
            $this->candidate(3, 0, 0, 10),
        ];

        $ordered = $sort->invoke($resolver, $candidates, RouteConstant::ROUTE_MODE_FIRST_AVAILABLE);
        $selected = $select->invoke($resolver, $ordered);

        $this->assertSame(1, (int) $selected['channel']->id, '默认启用通道应优先被选择');
    }

    /**
     * 路由候选过滤原因。
     *
     * @return void
     */
    private function testRouteRejectReasons(): void
    {
        $resolver = (new ReflectionClass(PaymentRouteResolverService::class))->newInstanceWithoutConstructor();
        $reject = $this->privateMethod(PaymentRouteResolverService::class, 'resolveCandidateRejectReasons');
        $channel = $this->channel([
            'id' => 10,
            'status' => CommonConstant::STATUS_DISABLED,
            'pay_type_id' => 2,
            'plugin_code' => 'mock',
            'min_amount' => 100,
            'max_amount' => 200,
        ]);
        $plugin = new PaymentPlugin([
            'code' => 'mock',
            'status' => CommonConstant::STATUS_DISABLED,
            'pay_types' => ['wechat'],
        ]);

        $reasons = $reject->invoke($resolver, $channel, $plugin, 1, 'alipay', 50, '2026-05-17', null);

        $this->assertContains('通道已禁用', $reasons, '禁用通道应给出过滤原因');
        $this->assertContains('通道支付方式不匹配', $reasons, '支付方式不匹配应给出过滤原因');
        $this->assertContains('插件已禁用', $reasons, '禁用插件应给出过滤原因');
        $this->assertTrue(
            count(array_filter($reasons, static fn (string $reason): bool => str_contains($reason, '金额不在通道范围内'))) === 1,
            '金额区间不匹配应给出过滤原因'
        );
    }

    /**
     * 回调载荷处理契约。
     *
     * @return void
     */
    private function testCallbackPayloadContract(): void
    {
        $service = (new ReflectionClass(PayOrderCallbackService::class))->newInstanceWithoutConstructor();
        $build = $this->privateMethod(PayOrderCallbackService::class, 'buildCallbackPayload');
        $payOrder = new PayOrder();
        $payOrder->forceFill([
            'pay_no' => 'P202605170001',
            'channel_id' => 12,
        ]);

        $payload = $build->invoke($service, $payOrder, ['raw' => 'payload'], [
            'status' => PaymentPluginStatusConstant::SUCCESS,
            'channel_order_no' => 'CO202605170001',
            'channel_trade_no' => 'CT202605170001',
            'paid_at' => '2026-05-17 12:00:00',
        ]);

        $this->assertSame(true, $payload['success'], '成功回调载荷 success 应为 true');
        $this->assertSame(NotifyConstant::VERIFY_STATUS_SUCCESS, (int) $payload['verify_status'], '成功解析的回调应标记验签成功');
        $this->assertSame(NotifyConstant::PROCESS_STATUS_SUCCESS, (int) $payload['process_status'], '成功回调应标记处理成功');
        $this->assertSame('CO202605170001', (string) $payload['channel_order_no'], '回调载荷应保留渠道订单号');

        $pending = $build->invoke($service, $payOrder, [], [
            'status' => PaymentPluginStatusConstant::PENDING,
            'channel_order_no' => 'CO202605170002',
            'channel_trade_no' => 'CT202605170002',
        ]);
        $this->assertSame(NotifyConstant::PROCESS_STATUS_PENDING, (int) $pending['process_status'], '处理中回调应保持待处理状态');

        $failed = $build->invoke($service, $payOrder, [], [
            'status' => PaymentPluginStatusConstant::FAILED,
            'channel_order_no' => 'CO202605170003',
            'channel_trade_no' => 'CT202605170003',
            'channel_error_code' => 'FAIL',
            'channel_error_msg' => '支付失败',
        ]);
        $this->assertSame(false, $failed['success'], '失败回调载荷 success 应为 false');
        $this->assertSame(NotifyConstant::PROCESS_STATUS_FAILED, (int) $failed['process_status'], '失败回调应标记处理失败');
        $this->assertSame('FAIL', (string) $failed['channel_error_code'], '失败回调应保留渠道错误码');
    }

    /**
     * 重复回调请求摘要稳定性。
     *
     * @return void
     */
    private function testCallbackDuplicateRequestHash(): void
    {
        $service = (new ReflectionClass(NotifyService::class))->newInstanceWithoutConstructor();
        $hash = $this->privateMethod(NotifyService::class, 'payloadHash');
        $payload = [
            'trade_no' => 'P202605170001',
            'money' => '10.00',
            'nested' => ['a' => 1],
        ];

        $first = $hash->invoke($service, $payload);
        $second = $hash->invoke($service, $payload);
        $changedPayload = $payload;
        $changedPayload['money'] = '10.01';
        $changed = $hash->invoke($service, $changedPayload);

        $this->assertSame($first, $second, '相同回调载荷应生成相同 request_hash，便于识别重复通知');
        $this->assertFalse($first === $changed, '不同回调载荷不应生成相同 request_hash');
    }

    /**
     * 商户通知重试策略。
     *
     * @return void
     */
    private function testNotifyRetryPolicy(): void
    {
        $service = (new ReflectionClass(NotifyService::class))->newInstanceWithoutConstructor();
        $this->setObjectProperty($service, 'systemConfigRuntimeService', new class extends SystemConfigRuntimeService {
            public function __construct()
            {
            }

            public function get(string $configKey, string|int|float|bool|null $default = '', bool $refresh = false): string
            {
                return match ($configKey) {
                    'pay_notify_retry_interval' => '10',
                    'pay_notify_retry_limit' => '3',
                    default => (string) $default,
                };
            }
        });

        $nextRetryAt = $this->privateMethod(NotifyService::class, 'nextRetryAt');
        $retryLimit = $this->privateMethod(NotifyService::class, 'retryLimit');

        $this->assertSame(3, $retryLimit->invoke($service), '通知最大重试次数应读取系统配置');
        $this->assertDelayBetween($nextRetryAt->invoke($service, 0), 55, 70, '首次入队默认应约 60 秒后可重试');
        $this->assertDelayBetween($nextRetryAt->invoke($service, 1), 595, 610, '首次失败后应按基础间隔重试');
        $this->assertDelayBetween($nextRetryAt->invoke($service, 2), 1795, 1810, '第二次失败后应按三倍基础间隔重试');
        $this->assertDelayBetween($nextRetryAt->invoke($service, 3), 3595, 3610, '更高次数失败后应按六倍基础间隔重试');
    }

    /**
     * 敏感数据递归脱敏。
     *
     * @return void
     */
    private function testSensitiveMasking(): void
    {
        $masked = \app\common\util\FormatHelper::maskSensitiveData([
            'app_id' => 'appid-001',
            'app_secret' => 'secret-value-123456',
            'nested' => [
                'private_key' => 'abcdefghijklmnopqrstuvwxyz',
                'notify_url' => 'https://example.test/notify',
            ],
        ]);

        $this->assertSame('appid-001', (string) $masked['app_id'], '非敏感字段不应被脱敏');
        $this->assertSame('secr****3456', (string) $masked['app_secret'], 'secret 字段应脱敏保留首尾');
        $this->assertSame('abcd****wxyz', (string) $masked['nested']['private_key'], '嵌套 private_key 应脱敏');
        $this->assertSame('https://example.test/notify', (string) $masked['nested']['notify_url'], '普通 URL 字段不应被脱敏');
    }

    /**
     * 构造通道模型。
     *
     * @param array<string, mixed> $attributes 属性
     * @return PaymentChannel
     */
    private function channel(array $attributes): PaymentChannel
    {
        $channel = new PaymentChannel();
        $channel->forceFill($attributes + [
            'id' => 1,
            'status' => CommonConstant::STATUS_ENABLED,
            'pay_type_id' => 1,
            'plugin_code' => 'mock',
            'daily_limit_amount' => 0,
            'daily_limit_count' => 0,
            'min_amount' => 0,
            'max_amount' => 0,
        ]);
        $channel->id = (int) ($attributes['id'] ?? 1);

        return $channel;
    }

    /**
     * 构造路由候选。
     *
     * @param int $channelId 通道ID
     * @param int $isDefault 是否默认
     * @param int $sortNo 排序号
     * @param int $weight 权重
     * @return array<string, mixed>
     */
    private function candidate(int $channelId, int $isDefault, int $sortNo, int $weight): array
    {
        return [
            'channel' => $this->channel(['id' => $channelId]),
            'is_default' => $isDefault,
            'sort_no' => $sortNo,
            'weight' => $weight,
        ];
    }

    /**
     * 获取可调用私有方法。
     *
     * @param string $class 类名
     * @param string $method 方法名
     * @return \ReflectionMethod
     */
    private function privateMethod(string $class, string $method): \ReflectionMethod
    {
        $reflection = new ReflectionClass($class);
        $methodReflection = $reflection->getMethod($method);
        $methodReflection->setAccessible(true);

        return $methodReflection;
    }

    /**
     * 设置对象属性。
     *
     * @param object $object 对象
     * @param string $property 属性名
     * @param mixed $value 属性值
     * @return void
     */
    private function setObjectProperty(object $object, string $property, mixed $value): void
    {
        $reflection = new ReflectionClass($object);
        while (!$reflection->hasProperty($property) && $reflection->getParentClass()) {
            $reflection = $reflection->getParentClass();
        }

        $propertyReflection = $reflection->getProperty($property);
        $propertyReflection->setAccessible(true);
        $propertyReflection->setValue($object, $value);
    }

    /**
     * 断言相等。
     *
     * @param mixed $expected 期望值
     * @param mixed $actual 实际值
     * @param string $message 错误消息
     * @return void
     */
    private function assertSame(mixed $expected, mixed $actual, string $message): void
    {
        if ($expected !== $actual) {
            throw new \RuntimeException($message . sprintf('，期望 %s，实际 %s', var_export($expected, true), var_export($actual, true)));
        }
    }

    /**
     * 断言为真。
     *
     * @param bool $actual 实际值
     * @param string $message 错误消息
     * @return void
     */
    private function assertTrue(bool $actual, string $message): void
    {
        if (!$actual) {
            throw new \RuntimeException($message);
        }
    }

    /**
     * 断言为假。
     *
     * @param bool $actual 实际值
     * @param string $message 错误消息
     * @return void
     */
    private function assertFalse(bool $actual, string $message): void
    {
        if ($actual) {
            throw new \RuntimeException($message);
        }
    }

    /**
     * 断言包含。
     *
     * @param mixed $needle 期望元素
     * @param array $haystack 实际集合
     * @param string $message 错误消息
     * @return void
     */
    private function assertContains(mixed $needle, array $haystack, string $message): void
    {
        if (!in_array($needle, $haystack, true)) {
            throw new \RuntimeException($message);
        }
    }

    /**
     * 断言时间延迟在区间内。
     *
     * @param string $datetime 目标时间
     * @param int $min 最小秒数
     * @param int $max 最大秒数
     * @param string $message 错误消息
     * @return void
     */
    private function assertDelayBetween(string $datetime, int $min, int $max, string $message): void
    {
        $delay = strtotime($datetime) - time();
        if ($delay < $min || $delay > $max) {
            throw new \RuntimeException($message . sprintf('，实际延迟 %d 秒', $delay));
        }
    }

    /**
     * 断言会抛出异常。
     *
     * @param callable $callback 待执行逻辑
     * @param string $message 错误消息
     * @return void
     */
    private function assertThrows(callable $callback, string $message): void
    {
        try {
            $callback();
        } catch (Throwable) {
            return;
        }

        throw new \RuntimeException($message);
    }
}
