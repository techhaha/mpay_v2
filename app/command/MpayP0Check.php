<?php

namespace app\command;

use app\common\constant\AuthConstant;
use app\common\constant\CommonConstant;
use app\common\constant\RouteConstant;
use app\common\constant\TradeConstant;
use app\common\util\FormatHelper;
use app\exception\CommandException;
use app\model\merchant\Merchant;
use app\model\merchant\MerchantAccount;
use app\model\merchant\MerchantApiCredential;
use app\model\merchant\MerchantGroup;
use app\model\payment\PaymentChannel;
use app\model\payment\PaymentPlugin;
use app\model\payment\PaymentPluginConf;
use app\model\payment\PaymentPollGroup;
use app\model\payment\PaymentPollGroupBind;
use app\model\payment\PaymentPollGroupChannel;
use app\model\payment\PaymentType;
use app\repository\payment\config\PaymentTypeRepository;
use app\repository\payment\trade\PayOrderRepository;
use app\command\support\EpayV1CommandMockPayment;
use app\service\payment\config\PaymentPluginSyncService;
use app\service\payment\order\PayOrderService;
use app\service\payment\runtime\PaymentRouteService;
use support\Redis;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * P0 主链路验收命令。
 *
 * 覆盖商户自收通道服务费冻结、扣减、释放，以及路由顺序轮询策略。
 */
#[AsCommand('mpay:p0-check', '运行 P0 主链路验收检查')]
class MpayP0Check extends Command
{
    /**
     * 执行 P0 主链路验收。
     *
     * @param InputInterface $input 命令输入
     * @param OutputInterface $output 命令输出
     * @return int 命令退出码
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<info>MPAY P0 主链路验收</info>');

        try {
            $this->refreshPlugins();
            $paymentType = $this->resolveAlipayType();
            $selfResult = $this->checkSelfChannelFunds($paymentType);
            $routeResult = $this->checkRouteRoundRobin($paymentType);

            $output->writeln(sprintf(
                '<info>[通过]</info> 自收通道资金 - 冻结=%d 扣费=%d 释放=%d 成功单=%s 关闭单=%s',
                $selfResult['freeze_fee'],
                $selfResult['deduct_fee'],
                $selfResult['release_fee'],
                $selfResult['success_pay_no'],
                $selfResult['closed_pay_no']
            ));
            $output->writeln(sprintf(
                '<info>[通过]</info> 路由轮询 - poll_group_id=%d 命中序列=%s',
                $routeResult['poll_group_id'],
                implode(' -> ', $routeResult['sequence'])
            ));
            $output->writeln('<info>汇总: 2 通过, 0 失败</info>');

            return self::SUCCESS;
        } catch (Throwable $e) {
            $output->writeln('<error>[失败]</error> ' . $this->formatThrowable($e));

            return self::FAILURE;
        }
    }

    /**
     * 验证自收通道服务费冻结、成功扣减和关闭释放。
     *
     * @param PaymentType $paymentType 支付方式
     * @return array<string, mixed> 验收结果
     */
    private function checkSelfChannelFunds(PaymentType $paymentType): array
    {
        $suffix = FormatHelper::timestamp(time(), 'YmdHis') . random_int(1000, 9999);
        $group = $this->createMerchantGroup('P0自收资金验收-' . $suffix);
        $merchant = $this->createMerchant($group, 'MP0SELF' . $suffix, 'P0自收资金验收商户');
        $this->createCredential((int) $merchant->id);
        $this->resetAccount((int) $merchant->id, 1000000);

        $pluginConf = $this->createMockEpayV1Config((int) $merchant->id, 'P0自收资金验收配置-' . $suffix);
        $channel = $this->createChannel(
            (int) $merchant->id,
            (int) $paymentType->id,
            (int) $pluginConf->id,
            'P0自收资金验收通道-' . $suffix,
            RouteConstant::CHANNEL_MODE_SELF,
            9900,
            10
        );

        /** @var PayOrderService $payOrderService */
        $payOrderService = $this->resolve(PayOrderService::class);
        /** @var PayOrderRepository $payOrderRepository */
        $payOrderRepository = $this->resolve(PayOrderRepository::class);
        $payAmount = 10000;
        $serviceFee = 100;

        $successPrepared = $payOrderService->preparePayAttempt($this->buildPaymentInput(
            (int) $merchant->id,
            (int) $paymentType->id,
            $payAmount,
            'P0S' . $suffix
        ));
        $successPayOrder = $successPrepared['pay_order'];
        $afterFreeze = $this->account((int) $merchant->id);
        $this->assertSame(1000000 - $serviceFee, (int) $afterFreeze->available_balance, '自收通道下单后可用余额未扣减冻结金额');
        $this->assertSame($serviceFee, (int) $afterFreeze->frozen_balance, '自收通道下单后冻结余额不正确');
        $this->assertSame(TradeConstant::SERVICE_FEE_STATUS_FROZEN, (int) $successPayOrder->service_fee_status, '自收通道下单后服务费状态未冻结');

        $payOrderService->markPaySuccess((string) $successPayOrder->pay_no, [
            'channel_trade_no' => 'P0CH' . $suffix,
            'channel_order_no' => 'P0CO' . $suffix,
        ]);
        $successPayOrder = $payOrderRepository->findByPayNo((string) $successPayOrder->pay_no);
        $afterDeduct = $this->account((int) $merchant->id);
        $this->assertSame(1000000 - $serviceFee, (int) $afterDeduct->available_balance, '自收通道成功后可用余额不应回补');
        $this->assertSame(0, (int) $afterDeduct->frozen_balance, '自收通道成功后冻结余额未扣减');
        $this->assertSame(TradeConstant::SERVICE_FEE_STATUS_DEDUCTED, (int) $successPayOrder->service_fee_status, '自收通道成功后服务费状态未扣除');

        $closedPrepared = $payOrderService->preparePayAttempt($this->buildPaymentInput(
            (int) $merchant->id,
            (int) $paymentType->id,
            $payAmount,
            'P0C' . $suffix
        ));
        $closedPayOrder = $closedPrepared['pay_order'];
        $afterSecondFreeze = $this->account((int) $merchant->id);
        $this->assertSame(1000000 - ($serviceFee * 2), (int) $afterSecondFreeze->available_balance, '关闭单冻结前可用余额不正确');
        $this->assertSame($serviceFee, (int) $afterSecondFreeze->frozen_balance, '关闭单冻结余额不正确');

        $payOrderService->closePayOrder((string) $closedPayOrder->pay_no, [
            'closed_at' => FormatHelper::timestamp(time()),
        ]);
        $closedPayOrder = $payOrderRepository->findByPayNo((string) $closedPayOrder->pay_no);
        $afterRelease = $this->account((int) $merchant->id);
        $this->assertSame(1000000 - $serviceFee, (int) $afterRelease->available_balance, '自收通道关闭后冻结金额未释放回可用余额');
        $this->assertSame(0, (int) $afterRelease->frozen_balance, '自收通道关闭后冻结余额未清零');
        $this->assertSame(TradeConstant::SERVICE_FEE_STATUS_RELEASED, (int) $closedPayOrder->service_fee_status, '自收通道关闭后服务费状态未释放');

        return [
            'freeze_fee' => $serviceFee,
            'deduct_fee' => $serviceFee,
            'release_fee' => $serviceFee,
            'success_pay_no' => (string) $successPayOrder->pay_no,
            'closed_pay_no' => (string) $closedPayOrder->pay_no,
        ];
    }

    /**
     * 验证路由命中和顺序轮询。
     *
     * @param PaymentType $paymentType 支付方式
     * @return array<string, mixed> 验收结果
     */
    private function checkRouteRoundRobin(PaymentType $paymentType): array
    {
        $suffix = FormatHelper::timestamp(time(), 'YmdHis') . random_int(1000, 9999);
        $group = $this->createMerchantGroup('P0路由验收-' . $suffix);
        $merchant = $this->createMerchant($group, 'MP0ROUTE' . $suffix, 'P0路由验收商户');
        $pluginConf = $this->createMockEpayV1Config(0, 'P0路由验收配置-' . $suffix);
        $firstChannel = $this->createChannel(0, (int) $paymentType->id, (int) $pluginConf->id, 'P0路由验收通道A-' . $suffix, RouteConstant::CHANNEL_MODE_COLLECT, 9900, 10);
        $secondChannel = $this->createChannel(0, (int) $paymentType->id, (int) $pluginConf->id, 'P0路由验收通道B-' . $suffix, RouteConstant::CHANNEL_MODE_COLLECT, 9900, 20);
        $pollGroup = $this->createPollGroup('P0路由验收轮询组-' . $suffix, (int) $paymentType->id, RouteConstant::ROUTE_MODE_ORDER);
        $this->createPollGroupChannel((int) $pollGroup->id, (int) $firstChannel->id, 10, 1, 0);
        $this->createPollGroupChannel((int) $pollGroup->id, (int) $secondChannel->id, 20, 1, 0);
        $this->createPollGroupBind((int) $group->id, (int) $paymentType->id, (int) $pollGroup->id);

        $cursorKey = sprintf('payment:route:round_robin:poll_group:%d', (int) $pollGroup->id);
        Redis::del($cursorKey);

        /** @var PaymentRouteService $routeService */
        $routeService = $this->resolve(PaymentRouteService::class);
        $expected = [(int) $firstChannel->id, (int) $secondChannel->id, (int) $firstChannel->id, (int) $secondChannel->id];
        $actual = [];
        for ($i = 0; $i < 4; $i++) {
            $route = $routeService->resolveByMerchantGroup((int) $group->id, (int) $paymentType->id, 10000, [
                'stat_date' => FormatHelper::timestamp(time(), 'Y-m-d'),
            ]);
            $selected = $route['selected_channel']['channel'] ?? null;
            $actual[] = (int) ($selected->id ?? 0);
        }

        if ($actual !== $expected) {
            throw new CommandException(sprintf(
                '路由轮询序列不符合预期，expected=%s actual=%s',
                implode(',', $expected),
                implode(',', $actual)
            ));
        }

        return [
            'poll_group_id' => (int) $pollGroup->id,
            'sequence' => $actual,
        ];
    }

    private function refreshPlugins(): void
    {
        /** @var PaymentPluginSyncService $service */
        $service = $this->resolve(PaymentPluginSyncService::class);
        $service->refreshFromClasses();
        PaymentPlugin::query()->updateOrCreate(
            ['code' => 'epay_v1_command_mock'],
            [
                'name' => 'ePay V1 命令测试桩',
                'class_name' => EpayV1CommandMockPayment::class,
                'config_schema' => [],
                'pay_types' => ['alipay', 'wxpay'],
                'transfer_types' => [],
                'version' => '1.0.0',
                'author' => 'MPAY',
                'link' => '',
                'allow_merchant' => CommonConstant::NO,
                'status' => CommonConstant::STATUS_ENABLED,
                'remark' => '命令行测试专用插件',
            ]
        );
    }

    private function resolveAlipayType(): PaymentType
    {
        /** @var PaymentTypeRepository $repository */
        $repository = $this->resolve(PaymentTypeRepository::class);
        $paymentType = $repository->findByCode('alipay');
        if (!$paymentType || (int) $paymentType->status !== CommonConstant::STATUS_ENABLED) {
            throw new CommandException('未找到可用的 alipay 支付方式');
        }

        return $paymentType;
    }

    private function createMerchantGroup(string $name): MerchantGroup
    {
        return MerchantGroup::query()->create([
            'group_name' => $name,
            'status' => CommonConstant::STATUS_ENABLED,
            'remark' => 'P0 主链路验收',
        ]);
    }

    private function createMerchant(MerchantGroup $group, string $merchantNo, string $name): Merchant
    {
        return Merchant::query()->create([
            'merchant_no' => $merchantNo,
            'password_hash' => password_hash('123456', PASSWORD_BCRYPT),
            'merchant_name' => $name,
            'merchant_short_name' => $name,
            'merchant_type' => 1,
            'group_id' => (int) $group->id,
            'risk_level' => 0,
            'contact_name' => 'P0验收',
            'contact_phone' => '13800000000',
            'contact_email' => 'p0-check@example.test',
            'settlement_account_name' => '',
            'settlement_account_no' => '',
            'settlement_bank_name' => '',
            'settlement_bank_branch' => '',
            'pay_status' => CommonConstant::STATUS_ENABLED,
            'settle_status' => CommonConstant::STATUS_ENABLED,
            'settle_type' => 0,
            'status' => CommonConstant::STATUS_ENABLED,
            'remark' => 'P0 主链路验收',
        ]);
    }

    private function createCredential(int $merchantId): void
    {
        MerchantApiCredential::query()->create([
            'merchant_id' => $merchantId,
            'api_key' => 'p0-check-api-key',
            'merchant_public_key' => '',
            'status' => AuthConstant::CREDENTIAL_STATUS_ENABLED,
        ]);
    }

    private function resetAccount(int $merchantId, int $availableBalance): MerchantAccount
    {
        MerchantAccount::query()->where('merchant_id', $merchantId)->delete();

        return MerchantAccount::query()->create([
            'merchant_id' => $merchantId,
            'available_balance' => $availableBalance,
            'frozen_balance' => 0,
        ]);
    }

    private function account(int $merchantId): MerchantAccount
    {
        $account = MerchantAccount::query()->where('merchant_id', $merchantId)->first();
        if (!$account) {
            throw new CommandException('商户账户不存在 merchant_id=' . $merchantId);
        }

        return $account;
    }

    private function createMockEpayV1Config(int $merchantId, string $remark): PaymentPluginConf
    {
        return PaymentPluginConf::query()->create([
            'merchant_id' => $merchantId,
            'plugin_code' => 'epay_v1_command_mock',
            'config' => [
                'api_url' => 'https://mock.epay.test/v1',
                'pid' => '900001',
                'api_key' => 'mock-v1-upstream-key-p0-check',
                'support_mapi' => true,
                'mock_jump_base_url' => 'https://mock.epay.test/v1/pay',
                'type_mapping_json' => [
                    'alipay' => 'alipay',
                    'wxpay' => 'wxpay',
                ],
            ],
            'settlement_cycle_type' => TradeConstant::SETTLEMENT_CYCLE_OTHER,
            'settlement_cutoff_time' => '',
            'remark' => $remark,
        ]);
    }

    private function createChannel(
        int $merchantId,
        int $payTypeId,
        int $pluginConfId,
        string $name,
        int $channelMode,
        int $splitRateBp,
        int $sortNo
    ): PaymentChannel {
        return PaymentChannel::query()->create([
            'merchant_id' => $merchantId,
            'name' => $name,
            'split_rate_bp' => $splitRateBp,
            'cost_rate_bp' => 0,
            'channel_mode' => $channelMode,
            'pay_type_id' => $payTypeId,
            'plugin_code' => 'epay_v1_command_mock',
            'api_config_id' => $pluginConfId,
            'daily_limit_amount' => 0,
            'daily_limit_count' => 0,
            'min_amount' => 1,
            'max_amount' => 0,
            'remark' => 'P0 主链路验收',
            'status' => CommonConstant::STATUS_ENABLED,
            'sort_no' => $sortNo,
        ]);
    }

    private function createPollGroup(string $name, int $payTypeId, int $routeMode): PaymentPollGroup
    {
        return PaymentPollGroup::query()->create([
            'group_name' => $name,
            'pay_type_id' => $payTypeId,
            'route_mode' => $routeMode,
            'status' => CommonConstant::STATUS_ENABLED,
            'remark' => 'P0 主链路验收',
        ]);
    }

    private function createPollGroupChannel(int $pollGroupId, int $channelId, int $sortNo, int $weight, int $isDefault): PaymentPollGroupChannel
    {
        return PaymentPollGroupChannel::query()->create([
            'poll_group_id' => $pollGroupId,
            'channel_id' => $channelId,
            'sort_no' => $sortNo,
            'weight' => $weight,
            'is_default' => $isDefault,
            'status' => CommonConstant::STATUS_ENABLED,
            'remark' => 'P0 主链路验收',
        ]);
    }

    private function createPollGroupBind(int $merchantGroupId, int $payTypeId, int $pollGroupId): PaymentPollGroupBind
    {
        return PaymentPollGroupBind::query()->create([
            'merchant_group_id' => $merchantGroupId,
            'pay_type_id' => $payTypeId,
            'poll_group_id' => $pollGroupId,
            'status' => CommonConstant::STATUS_ENABLED,
            'remark' => 'P0 主链路验收',
        ]);
    }

    /**
     * 构建支付发起参数。
     *
     * @param int $merchantId 商户ID
     * @param int $payTypeId 支付方式ID
     * @param int $payAmount 支付金额
     * @param string $merchantOrderNo 商户订单号
     * @return array<string, mixed> 支付参数
     */
    private function buildPaymentInput(int $merchantId, int $payTypeId, int $payAmount, string $merchantOrderNo): array
    {
        return [
            'merchant_id' => $merchantId,
            'merchant_order_no' => $merchantOrderNo,
            'pay_type_id' => $payTypeId,
            'pay_amount' => $payAmount,
            'subject' => 'P0 主链路验收',
            'body' => 'P0 主链路验收',
            'notify_url' => 'http://127.0.0.1:1/p0-check-notify',
            'return_url' => 'https://mock.return.test/p0-check',
            'client_ip' => '127.0.0.1',
            'device' => 'pc',
            'ext_json' => [
                '_submit_type' => 'api',
            ],
        ];
    }

    /**
     * 从容器解析对象。
     *
     * @param class-string $class 类名
     * @return object 对象
     */
    private function resolve(string $class): object
    {
        $instance = container_get($class);
        if (!is_object($instance)) {
            throw new CommandException('无法解析 ' . $class);
        }

        return $instance;
    }

    private function assertSame(int $expected, int $actual, string $message): void
    {
        if ($expected !== $actual) {
            throw new CommandException(sprintf('%s，expected=%d actual=%d', $message, $expected, $actual));
        }
    }

    private function formatThrowable(Throwable $e): string
    {
        $data = method_exists($e, 'getData') ? $e->getData() : [];
        $suffix = $data ? ' ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';

        return $e::class . '：' . $e->getMessage() . $suffix;
    }
}
