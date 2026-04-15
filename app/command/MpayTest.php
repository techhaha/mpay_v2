<?php

namespace app\command;

use app\common\constant\TradeConstant;
use app\common\util\FormatHelper;
use app\service\account\funds\MerchantAccountService;
use app\service\merchant\MerchantService;
use app\service\payment\order\PayOrderService;
use app\service\payment\order\RefundService;
use app\service\payment\settlement\SettlementService;
use app\service\payment\trace\TradeTraceService;
use app\repository\payment\trade\PayOrderRepository;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('mpay:test', '运行支付、退款、清结算、余额和追踪烟雾测试')]
class MpayTest extends Command
{
    protected function configure(): void
    {
        $this
            ->setDescription('运行支付、退款、清结算、余额和追踪烟雾测试。')
            ->addOption('payment', null, InputOption::VALUE_NONE, '仅运行支付检查')
            ->addOption('refund', null, InputOption::VALUE_NONE, '仅运行退款检查')
            ->addOption('settlement', null, InputOption::VALUE_NONE, '仅运行清结算检查')
            ->addOption('balance', null, InputOption::VALUE_NONE, '仅运行余额检查')
            ->addOption('trace', null, InputOption::VALUE_NONE, '仅运行追踪检查')
            ->addOption('all', null, InputOption::VALUE_NONE, '运行全部检查')
            ->addOption('live', null, InputOption::VALUE_NONE, '在提供测试数据时运行真实数据库检查');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $cases = $this->resolveCases($input);
        $live = (bool) $input->getOption('live');

        $output->writeln('<info>mpay 烟雾测试</info>');
        $output->writeln('模式: ' . ($live ? '真实数据' : '依赖连通性'));
        $output->writeln('测试项: ' . implode(', ', $cases));

        $summary = [];
        foreach ($cases as $case) {
            $result = match ($case) {
                'payment' => $this->checkPayment($live),
                'refund' => $this->checkRefund($live),
                'settlement' => $this->checkSettlement($live),
                'balance' => $this->checkBalance($live),
                'trace' => $this->checkTrace($live),
                default => ['status' => 'skip', 'message' => '未知测试项'],
            };

            $summary[] = $result['status'];
            $this->writeResult($output, strtoupper($case), $result['status'], $result['message']);
        }

        $failed = count(array_filter($summary, static fn (string $status) => $status === 'fail'));
        $skipped = count(array_filter($summary, static fn (string $status) => $status === 'skip'));
        $passed = count(array_filter($summary, static fn (string $status) => $status === 'pass'));

        $output->writeln(sprintf(
            '<info>汇总</info>: %d 通过, %d 跳过, %d 失败',
            $passed,
            $skipped,
            $failed
        ));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * 根据命令行选项解析需要执行的测试项。
     */
    private function resolveCases(InputInterface $input): array
    {
        $selected = [];
        foreach (['payment', 'refund', 'settlement', 'balance', 'trace'] as $case) {
            if ((bool) $input->getOption($case)) {
                $selected[] = $case;
            }
        }

        if ((bool) $input->getOption('all') || empty($selected)) {
            return ['payment', 'refund', 'settlement', 'balance', 'trace'];
        }

        return $selected;
    }

    /**
     * 检查支付链路。
     */
    private function checkPayment(bool $live): array
    {
        try {
            $service = $this->resolve(PayOrderService::class);
            $this->ensureMethod($service, 'preparePayAttempt');
            $this->ensureMethod($service, 'timeoutPayOrder');

            if (!$live) {
                return ['status' => 'pass', 'message' => '服务依赖连通性正常'];
            }

            $merchantId = $this->envInt('MPAY_TEST_PAYMENT_MERCHANT_ID');
            $payTypeId = $this->envInt('MPAY_TEST_PAYMENT_TYPE_ID');
            $payAmount = $this->envInt('MPAY_TEST_PAYMENT_AMOUNT');
            $merchantOrderNo = $this->envString('MPAY_TEST_PAYMENT_ORDER_NO', $this->generateTestNo('PAY-TEST-'));

            if ($merchantId <= 0 || $payTypeId <= 0 || $payAmount <= 0) {
                return ['status' => 'skip', 'message' => '缺少 MPAY_TEST_PAYMENT_* 测试配置'];
            }

            $result = $service->preparePayAttempt([
                'merchant_id' => $merchantId,
                'merchant_order_no' => $merchantOrderNo,
                'pay_type_id' => $payTypeId,
                'pay_amount' => $payAmount,
                'subject' => $this->envString('MPAY_TEST_PAYMENT_SUBJECT', 'mpay smoke payment'),
                'body' => $this->envString('MPAY_TEST_PAYMENT_BODY', 'mpay smoke payment'),
                'ext_json' => $this->envJson('MPAY_TEST_PAYMENT_EXT_JSON', []),
            ]);

            $payOrder = $result['pay_order'];
            $selectedChannel = $result['route']['selected_channel']['channel'] ?? null;
            $message = sprintf(
                '已创建支付单 pay_no=%s biz_no=%s channel_id=%s',
                (string) $payOrder->pay_no,
                (string) $result['biz_order']->biz_no,
                $selectedChannel ? (string) $selectedChannel->id : ''
            );

            if ($this->envBool('MPAY_TEST_PAYMENT_MARK_TIMEOUT', false)) {
                $service->timeoutPayOrder((string) $payOrder->pay_no, [
                    'timeout_at' => $this->envString('MPAY_TEST_PAYMENT_TIMEOUT_AT', FormatHelper::timestamp(time())),
                    'reason' => $this->envString('MPAY_TEST_PAYMENT_TIMEOUT_REASON', 'mpay smoke timeout'),
                ]);
                $message .= ', 已标记超时';
            } elseif ($this->envBool('MPAY_TEST_PAYMENT_MARK_SUCCESS', false)) {
                $service->markPaySuccess((string) $payOrder->pay_no, [
                    'fee_actual_amount' => $this->envInt('MPAY_TEST_PAYMENT_FEE_AMOUNT', (int) $payOrder->fee_estimated_amount),
                    'channel_trade_no' => $this->envString('MPAY_TEST_PAYMENT_CHANNEL_TRADE_NO', $this->generateTestNo('CH-')),
                    'channel_order_no' => $this->envString('MPAY_TEST_PAYMENT_CHANNEL_ORDER_NO', $this->generateTestNo('CO-')),
                ]);
                $message .= ', 已标记成功';
            }

            return ['status' => 'pass', 'message' => $message];
        } catch (\Throwable $e) {
            return ['status' => 'fail', 'message' => $this->formatThrowable($e)];
        }
    }

    /**
     * 检查退款链路。
     */
    private function checkRefund(bool $live): array
    {
        try {
            $service = $this->resolve(RefundService::class);
            $this->ensureMethod($service, 'createRefund');
            $this->ensureMethod($service, 'markRefundProcessing');
            $this->ensureMethod($service, 'retryRefund');
            $this->ensureMethod($service, 'markRefundFailed');

            if (!$live) {
                return ['status' => 'pass', 'message' => '服务依赖连通性正常'];
            }

            $payNo = $this->envString('MPAY_TEST_REFUND_PAY_NO');
            if ($payNo === '') {
                return ['status' => 'skip', 'message' => '缺少 MPAY_TEST_REFUND_PAY_NO 测试配置'];
            }

            $refund = $service->createRefund([
                'pay_no' => $payNo,
                'merchant_refund_no' => $this->envString('MPAY_TEST_REFUND_NO', $this->generateTestNo('RFD-TEST-')),
                'reason' => $this->envString('MPAY_TEST_REFUND_REASON', 'mpay smoke refund'),
                'ext_json' => $this->envJson('MPAY_TEST_REFUND_EXT_JSON', []),
            ]);

            $message = '已创建退款单 refund_no=' . (string) $refund->refund_no;

            if ($this->envBool('MPAY_TEST_REFUND_MARK_PROCESSING', false)) {
                $service->markRefundProcessing((string) $refund->refund_no, [
                    'processing_at' => $this->envString('MPAY_TEST_REFUND_PROCESSING_AT', FormatHelper::timestamp(time())),
                ]);
                $message .= ', 已标记处理中';
            } elseif ($this->envBool('MPAY_TEST_REFUND_MARK_RETRY', false)) {
                $service->retryRefund((string) $refund->refund_no, [
                    'processing_at' => $this->envString('MPAY_TEST_REFUND_RETRY_AT', FormatHelper::timestamp(time())),
                ]);
                $message .= ', 已标记重试';
            } elseif ($this->envBool('MPAY_TEST_REFUND_MARK_FAIL', false)) {
                $service->markRefundFailed((string) $refund->refund_no, [
                    'failed_at' => $this->envString('MPAY_TEST_REFUND_FAILED_AT', FormatHelper::timestamp(time())),
                    'last_error' => $this->envString('MPAY_TEST_REFUND_LAST_ERROR', 'mpay smoke refund failed'),
                ]);
                $message .= ', 已标记失败';
            } elseif ($this->envBool('MPAY_TEST_REFUND_MARK_SUCCESS', false)) {
                $service->markRefundSuccess((string) $refund->refund_no, [
                    'channel_refund_no' => $this->envString('MPAY_TEST_REFUND_CHANNEL_NO', $this->generateTestNo('CR-')),
                ]);
                $message .= ', 已标记成功';
            }

            return ['status' => 'pass', 'message' => $message];
        } catch (\Throwable $e) {
            return ['status' => 'fail', 'message' => $this->formatThrowable($e)];
        }
    }

    /**
     * 检查清结算链路。
     */
    private function checkSettlement(bool $live): array
    {
        try {
            $service = $this->resolve(SettlementService::class);
            $this->ensureMethod($service, 'createSettlementOrder');
            $this->ensureMethod($service, 'completeSettlement');
            $this->ensureMethod($service, 'failSettlement');

            if (!$live) {
                return ['status' => 'pass', 'message' => '服务依赖连通性正常'];
            }

            $merchantId = 0;
            $merchantGroupId = 0;
            $channelId = 0;
            $settleNo = $this->envString('MPAY_TEST_SETTLEMENT_NO', $this->generateTestNo('STL-TEST-'));
            $items = $this->envJson('MPAY_TEST_SETTLEMENT_ITEMS_JSON', []);

            if (empty($items)) {
                $payNo = $this->envString('MPAY_TEST_SETTLEMENT_PAY_NO');
                if ($payNo !== '') {
                    $payOrderRepository = $this->resolve(PayOrderRepository::class);
                    $payOrder = $payOrderRepository->findByPayNo($payNo);
                    if ($payOrder) {
                        $items = [[
                            'pay_no' => (string) $payOrder->pay_no,
                            'refund_no' => '',
                            'pay_amount' => (int) $payOrder->pay_amount,
                            'fee_amount' => (int) $payOrder->fee_actual_amount,
                            'refund_amount' => 0,
                            'fee_reverse_amount' => 0,
                            'net_amount' => max(0, (int) $payOrder->pay_amount - (int) $payOrder->fee_actual_amount),
                            'item_status' => TradeConstant::SETTLEMENT_STATUS_PENDING,
                        ]];
                        $merchantId = (int) $payOrder->merchant_id;
                        $merchantGroupId = (int) $payOrder->merchant_group_id;
                        $channelId = (int) $payOrder->channel_id;
                    }
                }
            }

            if ($merchantId <= 0) {
                $merchantId = $this->envInt('MPAY_TEST_SETTLEMENT_MERCHANT_ID');
            }
            if ($merchantGroupId <= 0) {
                $merchantGroupId = $this->envInt('MPAY_TEST_SETTLEMENT_MERCHANT_GROUP_ID');
            }
            if ($channelId <= 0) {
                $channelId = $this->envInt('MPAY_TEST_SETTLEMENT_CHANNEL_ID');
            }

            if ($merchantId <= 0 || $merchantGroupId <= 0 || $channelId <= 0) {
                return ['status' => 'skip', 'message' => '缺少 MPAY_TEST_SETTLEMENT_* 测试配置'];
            }

            if (empty($items)) {
                $items = [[
                    'pay_no' => '',
                    'refund_no' => '',
                    'pay_amount' => $this->envInt('MPAY_TEST_SETTLEMENT_GROSS_AMOUNT', 100),
                    'fee_amount' => $this->envInt('MPAY_TEST_SETTLEMENT_FEE_AMOUNT', 0),
                    'refund_amount' => $this->envInt('MPAY_TEST_SETTLEMENT_REFUND_AMOUNT', 0),
                    'fee_reverse_amount' => $this->envInt('MPAY_TEST_SETTLEMENT_FEE_REVERSE_AMOUNT', 0),
                    'net_amount' => $this->envInt('MPAY_TEST_SETTLEMENT_NET_AMOUNT', 100),
                    'item_status' => TradeConstant::SETTLEMENT_STATUS_PENDING,
                ]];
            }

            $settlement = $service->createSettlementOrder([
                'settle_no' => $settleNo,
                'merchant_id' => $merchantId,
                'merchant_group_id' => $merchantGroupId,
                'channel_id' => $channelId,
                'cycle_type' => $this->envInt('MPAY_TEST_SETTLEMENT_CYCLE_TYPE', TradeConstant::SETTLEMENT_CYCLE_OTHER),
                'cycle_key' => $this->envString('MPAY_TEST_SETTLEMENT_CYCLE_KEY', FormatHelper::timestamp(time(), 'Y-m-d')),
                'accounted_amount' => $this->envInt('MPAY_TEST_SETTLEMENT_ACCOUNTED_AMOUNT', 0),
                'status' => TradeConstant::SETTLEMENT_STATUS_PENDING,
                'ext_json' => $this->envJson('MPAY_TEST_SETTLEMENT_EXT_JSON', []),
            ], $items);

            $message = '已创建清结算单 settle_no=' . (string) $settlement->settle_no;

            if ($this->envBool('MPAY_TEST_SETTLEMENT_FAIL', false)) {
                $service->failSettlement(
                    (string) $settlement->settle_no,
                    $this->envString('MPAY_TEST_SETTLEMENT_FAIL_REASON', 'mpay smoke settlement fail')
                );
                $message .= ', 已标记失败';
            } elseif ($this->envBool('MPAY_TEST_SETTLEMENT_COMPLETE', false)) {
                $service->completeSettlement((string) $settlement->settle_no);
                $message .= ', 已完成入账';
            }

            return ['status' => 'pass', 'message' => $message];
        } catch (\Throwable $e) {
            return ['status' => 'fail', 'message' => $this->formatThrowable($e)];
        }
    }

    /**
     * 检查余额链路。
     */
    private function checkBalance(bool $live): array
    {
        try {
            $accountService = $this->resolve(MerchantAccountService::class);
            $this->ensureMethod($accountService, 'getBalanceSnapshot');
            $this->resolve(MerchantService::class);

            if (!$live) {
                return ['status' => 'pass', 'message' => '服务依赖连通性正常'];
            }

            $merchantId = $this->envInt('MPAY_TEST_BALANCE_MERCHANT_ID');
            if ($merchantId <= 0) {
                $merchantNo = $this->envString('MPAY_TEST_BALANCE_MERCHANT_NO');
                if ($merchantNo !== '') {
                    $merchantService = $this->resolve(MerchantService::class);
                    $merchant = $merchantService->findEnabledMerchantByNo($merchantNo);
                    $merchantId = (int) $merchant->id;
                }
            }

            if ($merchantId <= 0) {
                return ['status' => 'skip', 'message' => '缺少 MPAY_TEST_BALANCE_* 测试配置'];
            }

            $snapshot = $accountService->getBalanceSnapshot($merchantId);

            return [
                'status' => 'pass',
                'message' => sprintf(
                    '余额 merchant_id=%d 可用=%d 冻结=%d',
                    (int) $snapshot['merchant_id'],
                    (int) $snapshot['available_balance'],
                    (int) $snapshot['frozen_balance']
                ),
            ];
        } catch (\Throwable $e) {
            return ['status' => 'fail', 'message' => $this->formatThrowable($e)];
        }
    }

    /**
     * 检查统一追踪链路。
     */
    private function checkTrace(bool $live): array
    {
        try {
            $service = $this->resolve(TradeTraceService::class);
            $this->ensureMethod($service, 'queryByTraceNo');

            if (!$live) {
                return ['status' => 'pass', 'message' => '服务依赖连通性正常'];
            }

            $traceNo = $this->envString('MPAY_TEST_TRACE_NO');
            if ($traceNo === '') {
                return ['status' => 'skip', 'message' => '缺少 MPAY_TEST_TRACE_NO 测试配置'];
            }

            $result = $service->queryByTraceNo($traceNo);
            if (empty($result)) {
                return ['status' => 'fail', 'message' => '追踪结果为空'];
            }

            $message = sprintf(
                'trace_no=%s 支付=%d 退款=%d 清结算=%d 流水=%d',
                (string) ($result['resolved_trace_no'] ?? $traceNo),
                count($result['pay_orders'] ?? []),
                count($result['refund_orders'] ?? []),
                count($result['settlement_orders'] ?? []),
                count($result['account_ledgers'] ?? [])
            );

            return ['status' => 'pass', 'message' => $message];
        } catch (\Throwable $e) {
            return ['status' => 'fail', 'message' => $this->formatThrowable($e)];
        }
    }

    /**
     * 从容器中解析指定类实例。
     */
    private function resolve(string $class): object
    {
        try {
            $instance = container_make($class, []);
        } catch (\Throwable $e) {
            throw new RuntimeException("无法解析 {$class}: " . $e->getMessage(), 0, $e);
        }

        if (!is_object($instance)) {
            throw new RuntimeException("解析后的 {$class} 不是对象。");
        }

        return $instance;
    }

    /**
     * 检查实例是否包含指定方法。
     */
    private function ensureMethod(object $instance, string $method): void
    {
        if (!method_exists($instance, $method)) {
            throw new RuntimeException(sprintf('未找到方法 %s::%s。', $instance::class, $method));
        }
    }

    /**
     * 读取字符串环境变量。
     */
    private function envString(string $key, string $default = ''): string
    {
        $value = env($key, $default);

        return is_string($value) ? $value : (string) $value;
    }

    /**
     * 读取整数环境变量。
     */
    private function envInt(string $key, int $default = 0): int
    {
        $value = env($key, null);

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * 读取布尔环境变量。
     */
    private function envBool(string $key, bool $default = false): bool
    {
        $value = env($key, null);
        if ($value === null || $value === '') {
            return $default;
        }

        $filtered = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

        return $filtered === null ? $default : $filtered;
    }

    /**
     * 读取结构化环境变量。
     */
    private function envJson(string $key, array $default = []): array
    {
        $value = trim($this->envString($key));
        if ($value === '') {
            return $default;
        }

        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : $default;
    }

    /**
     * 生成测试编号。
     */
    private function generateTestNo(string $prefix): string
    {
        return $prefix . FormatHelper::timestamp(time(), 'YmdHis') . random_int(1000, 9999);
    }

    /**
     * 将异常格式化为可读文本。
     */
    private function formatThrowable(\Throwable $e): string
    {
        $data = method_exists($e, 'getData') ? $e->getData() : [];
        $suffix = $data ? ' ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';

        return $e::class . ': ' . $e->getMessage() . $suffix;
    }

    /**
     * 输出单个测试项的执行结果。
     */
    private function writeResult(OutputInterface $output, string $case, string $status, string $message): void
    {
        $label = match ($status) {
            'pass' => '<info>[通过]</info>',
            'skip' => '<comment>[跳过]</comment>',
            default => '<error>[失败]</error>',
        };

        $output->writeln(sprintf('%s %s - %s', $label, $case, $message));
    }
}

