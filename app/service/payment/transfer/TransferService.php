<?php

namespace app\service\payment\transfer;

use app\common\base\BaseService;
use app\common\constant\CommonConstant;
use app\common\constant\PaymentRecoveryTaskConstant;
use app\common\constant\TransferConstant;
use app\common\interface\TransferPluginInterface;
use app\exception\ConflictException;
use app\exception\PaymentDefinitiveException;
use app\exception\ResourceNotFoundException;
use app\exception\ValidationException;
use app\model\merchant\Merchant;
use app\model\payment\PaymentChannel;
use app\model\payment\TransferOrder;
use app\repository\account\balance\MerchantAccountRepository;
use app\repository\payment\config\PaymentChannelRepository;
use app\repository\payment\trade\TransferOrderRepository;
use app\service\account\funds\MerchantAccountService;
use app\service\payment\runtime\PaymentPluginManager;
use app\service\payment\runtime\PaymentQueueService;
use app\service\payment\runtime\PaymentRecoveryTaskService;
use support\Log;
use Throwable;

/**
 * 转账服务。
 *
 * 负责 ePay 转账入口的参数校验、幂等创建、商户余额扣减、通道路由、
 * 队列派发上游转账请求和非终态查单同步。
 */
class TransferService extends BaseService
{
    /**
     * 自动查单最大次数。
     */
    private const QUERY_MAX_ATTEMPTS = 5;

    /**
     * 自动查单退避间隔。
     */
    private const QUERY_DELAY_SECONDS = [60, 120, 300, 600, 900];

    /**
     * 转账派发认领租约秒数。
     */
    private const DISPATCH_LEASE_SECONDS = 90;

    /**
     * 构造方法。
     *
     * @param MerchantAccountRepository $merchantAccountRepository 商户账户仓库
     * @param TransferOrderRepository $transferOrderRepository 转账单仓库
     * @param PaymentChannelRepository $paymentChannelRepository 支付通道仓库
     * @param PaymentPluginManager $paymentPluginManager 支付插件管理器
     * @param MerchantAccountService $merchantAccountService 商户账户服务
     * @param PaymentQueueService $paymentQueueService 支付队列服务
     * @param PaymentRecoveryTaskService $recoveryTaskService 支付恢复任务服务
     * @return void
     */
    public function __construct(
        protected MerchantAccountRepository $merchantAccountRepository,
        protected TransferOrderRepository $transferOrderRepository,
        protected PaymentChannelRepository $paymentChannelRepository,
        protected PaymentPluginManager $paymentPluginManager,
        protected MerchantAccountService $merchantAccountService,
        protected PaymentQueueService $paymentQueueService,
        protected PaymentRecoveryTaskService $recoveryTaskService
    ) {
    }

    /**
     * 创建并发起转账单。
     *
     * @param Merchant $merchant 商户
     * @param array<string, mixed> $input 请求参数
     * @return array<string, mixed>
     */
    public function submit(Merchant $merchant, array $input): array
    {
        $type = trim((string) ($input['type'] ?? ''));
        $account = trim((string) ($input['account'] ?? ''));
        $name = trim((string) ($input['name'] ?? ''));
        $money = trim((string) ($input['money'] ?? ''));
        $amount = $this->parseMoneyToAmount($money);

        if ($type === '') {
            throw new ValidationException('type 不能为空');
        }
        if ($account === '' || $name === '') {
            throw new ValidationException('account/name 不能为空');
        }
        if ($amount <= 0) {
            throw new ValidationException('money 参数不合法');
        }

        $merchantId = (int) $merchant->id;
        $outBizNo = trim((string) ($input['out_biz_no'] ?? ''));
        if ($outBizNo !== '') {
            $existing = $this->transferOrderRepository->findByOutBizNo($merchantId, $outBizNo);
            if ($existing) {
                if ((int) $existing->amount !== $amount) {
                    throw new ConflictException('幂等冲突', [
                        'biz_no' => (string) $existing->biz_no,
                        'out_biz_no' => $outBizNo,
                    ]);
                }

                return $this->formatTransferOrder($this->syncTransferOrderIfNeeded($existing));
            }
        }

        [$channel] = $this->resolveTransferChannelAndPlugin($merchantId, $type, (int) ($input['channel_id'] ?? 0));
        $transferRate = $this->resolveTransferRate();
        $costAmount = (int) floor($amount * $transferRate);
        $bizNo = $this->generateNo('TRF');
        $traceNo = $this->generateNo('TRC');
        $totalDebit = $amount + $costAmount;
        $extJson = (array) ($input['ext_json'] ?? []);
        $created = false;

        /** @var TransferOrder $transferOrder */
        $transferOrder = $this->transactionRetry(function () use (
            $merchant,
            $merchantId,
            $outBizNo,
            $type,
            $account,
            $name,
            $amount,
            $costAmount,
            $totalDebit,
            $bizNo,
            $traceNo,
            $channel,
            $input,
            $extJson,
            &$created
        ): TransferOrder {
            if ($outBizNo !== '') {
                $existing = $this->transferOrderRepository->findForUpdateByOutBizNo($merchantId, $outBizNo);
                if ($existing instanceof TransferOrder) {
                    return $existing;
                }
            }

            if ($totalDebit > 0) {
                $this->merchantAccountService->debitTransferAmountInCurrentTransaction(
                    $merchantId,
                    $amount,
                    $bizNo,
                    'TRANSFER_DEDUCT:' . $bizNo,
                    [
                        'remark' => '转账本金扣减',
                        'out_biz_no' => $outBizNo,
                    ],
                    $traceNo
                );

                if ($costAmount > 0) {
                    $this->merchantAccountService->debitTransferFeeInCurrentTransaction(
                        $merchantId,
                        $costAmount,
                        $bizNo,
                        'TRANSFER_FEE:' . $bizNo,
                        [
                            'remark' => '转账手续费扣减',
                            'out_biz_no' => $outBizNo,
                        ],
                        $traceNo
                    );
                }
            }

            $createdOrder = $this->transferOrderRepository->create([
                'biz_no' => $bizNo,
                'trace_no' => $traceNo,
                'merchant_id' => $merchantId,
                'merchant_group_id' => (int) ($merchant->group_id ?? 0),
                'out_biz_no' => $outBizNo !== '' ? $outBizNo : $this->generateNo('OBN'),
                'type' => $type,
                'account' => $account,
                'name' => $name,
                'amount' => $amount,
                'cost_amount' => $costAmount,
                'remark' => (string) ($input['remark'] ?? ''),
                'bookid' => (string) ($input['bookid'] ?? ''),
                'channel_id' => (int) $channel->id,
                'channel_request_no' => $this->generateNo('TRQ'),
                'status' => TransferConstant::TRANSFER_STATUS_PROCESSING,
                'request_at' => $this->now(),
                'processing_at' => $this->now(),
                'ext_json' => $extJson,
            ]);
            $this->recoveryTaskService->scheduleInCurrentTransaction(
                PaymentRecoveryTaskConstant::TYPE_TRANSFER_DISPATCH,
                $bizNo,
                0,
                0,
                10,
                true
            );
            $created = true;

            return $createdOrder;
        });

        if (!$created) {
            return $this->formatTransferOrder($this->syncTransferOrderIfNeeded($transferOrder));
        }

        $this->enqueueTransferDispatchSafely($transferOrder);

        return $this->formatTransferOrder($transferOrder->refresh());
    }

    /**
     * 查询转账单，并在非终态时尝试主动同步一次通道状态。
     *
     * @param Merchant $merchant 商户
     * @param array<string, mixed> $input 请求参数
     * @return array<string, mixed>
     */
    public function query(Merchant $merchant, array $input): array
    {
        $order = $this->resolveTransferOrder($merchant, $input);
        return $this->formatTransferOrder($this->syncTransferOrderIfNeeded($order));
    }

    /**
     * 查询转账余额。
     *
     * @param Merchant $merchant 商户
     * @return array<string, mixed>
     */
    public function balance(Merchant $merchant): array
    {
        $account = $this->merchantAccountRepository->findByMerchantId((int) $merchant->id);
        return [
            'available_money' => $this->formatAmount((int) ($account->available_balance ?? 0)),
            'transfer_rate' => number_format($this->resolveTransferRate(), 2, '.', ''),
        ];
    }

    /**
     * 队列中派发转账到上游通道。
     *
     * @param string $bizNo 转账单号
     * @return TransferOrder 最新转账单
     */
    public function dispatchQueuedTransfer(string $bizNo): TransferOrder
    {
        $order = $this->resolveTransferOrderByBizNo($bizNo);
        if (TransferConstant::isTerminalStatus((int) $order->status)) {
            $this->recoveryTaskService->complete(PaymentRecoveryTaskConstant::TYPE_TRANSFER_DISPATCH, $bizNo);
            return $order;
        }

        $task = $this->recoveryTaskService->claim(
            PaymentRecoveryTaskConstant::TYPE_TRANSFER_DISPATCH,
            $bizNo,
            self::DISPATCH_LEASE_SECONDS
        );
        if (!$task) {
            return $this->resolveTransferOrderByBizNo($bizNo);
        }

        try {
            [$channel, $plugin] = $this->resolveTransferChannelAndPlugin(
                (int) $order->merchant_id,
                (string) $order->type,
                (int) $order->channel_id
            );
            unset($channel);

            $latest = $this->dispatchTransfer($order, $plugin);
            $this->scheduleInitialTransferQueryIfNeeded($latest);
            $this->recoveryTaskService->succeed($task);

            return $latest;
        } catch (Throwable $e) {
            $this->recoveryTaskService->retry($task, 60, $e->getMessage());
            throw $e;
        }
    }

    /**
     * 队列中查询转账上游状态。
     *
     * @param string $bizNo 转账单号
     * @param int $attempt 当前查单次数
     * @return TransferOrder 最新转账单
     */
    public function queryQueuedTransfer(string $bizNo, int $attempt = 0): TransferOrder
    {
        $order = $this->resolveTransferOrderByBizNo($bizNo);
        if (TransferConstant::isTerminalStatus((int) $order->status)) {
            $this->recoveryTaskService->complete(PaymentRecoveryTaskConstant::TYPE_TRANSFER_QUERY, $bizNo);
            return $order;
        }

        $task = $this->recoveryTaskService->claim(
            PaymentRecoveryTaskConstant::TYPE_TRANSFER_QUERY,
            $bizNo
        );
        if (!$task) {
            return $order;
        }

        try {
            $latest = $this->syncTransferOrderIfNeeded($order);
            if (TransferConstant::isTerminalStatus((int) $latest->status)) {
                $this->recoveryTaskService->succeed($task);
                return $latest;
            }

            $currentAttempt = (int) $task->retry_count + 1;
            $delay = $this->resolveTransferQueryDelay((int) $task->retry_count);
            $this->recoveryTaskService->retry($task, $delay, (string) $latest->channel_error_msg);
            if ($currentAttempt < self::QUERY_MAX_ATTEMPTS) {
                $this->enqueueTransferQuerySafely($bizNo, $currentAttempt + 1, $delay);
            } else {
                Log::warning(sprintf(
                    '[TransferService] 转账自动查单达到上限 biz_no=%s attempt=%d',
                    $bizNo,
                    $currentAttempt
                ));
            }

            return $latest;
        } catch (Throwable $e) {
            $delay = $this->resolveTransferQueryDelay((int) $task->retry_count);
            $this->recoveryTaskService->retry($task, $delay, $e->getMessage());
            throw $e;
        }
    }

    /**
     * 扫描并重新投递未可靠派发的处理中转账单。
     *
     * 转账单本身保存完整可重建载荷和派发状态，作为轻量本地 outbox。即使数据库提交后
     * Redis 暂时不可用，运行时进程也能重新投递；重复消息由本地租约和稳定上游业务号共同保护。
     *
     * @param int $limit 单次扫描数量
     * @param int $minAgeSeconds 至少等待秒数
     * @return array<string, int> 恢复摘要
     */
    public function recoverPendingTransferDispatches(int $limit = 100, int $minAgeSeconds = 60): array
    {
        $summary = [
            'scanned' => 0,
            'dispatched' => 0,
            'skipped' => 0,
            'failed' => 0,
        ];
        foreach ($this->recoveryTaskService->listDueRefs(
            PaymentRecoveryTaskConstant::TYPE_TRANSFER_DISPATCH,
            max(1, $limit)
        ) as $bizNo) {
            $summary['scanned']++;
            try {
                $beforeStatus = (int) $this->resolveTransferOrderByBizNo($bizNo)->status;
                $latest = $this->dispatchQueuedTransfer($bizNo);
                if (!TransferConstant::isTerminalStatus($beforeStatus)
                    && ((int) $latest->status !== $beforeStatus || (string) $latest->channel_error_code !== '')) {
                    $summary['dispatched']++;
                } else {
                    $summary['skipped']++;
                }
            } catch (Throwable $e) {
                Log::warning(sprintf(
                    '[TransferService] 转账派发恢复失败 biz_no=%s error=%s',
                    $bizNo,
                    $e->getMessage()
                ));
                $summary['failed']++;
            }
        }

        return $summary;
    }

    /**
     * 恢复未可靠投递或消费的转账主动查单任务。
     *
     * @param int $limit 单次扫描数量
     * @param int $minAgeSeconds 至少等待秒数
     * @return array<string, int> 恢复摘要
     */
    public function recoverPendingTransferQueries(int $limit = 100, int $minAgeSeconds = 60): array
    {
        $summary = [
            'scanned' => 0,
            'queued' => 0,
            'failed' => 0,
        ];
        foreach ($this->recoveryTaskService->listDueRefs(
            PaymentRecoveryTaskConstant::TYPE_TRANSFER_QUERY,
            max(1, $limit)
        ) as $bizNo) {
            $summary['scanned']++;
            try {
                $this->queryQueuedTransfer($bizNo);
                $summary['queued']++;
            } catch (Throwable $e) {
                Log::warning(sprintf(
                    '[TransferService] 转账查单恢复失败 biz_no=%s error=%s',
                    $bizNo,
                    $e->getMessage()
                ));
                $summary['failed']++;
            }
        }

        return $summary;
    }

    /**
     * 选择可处理当前转账类型的通道和插件。
     *
     * @param int $merchantId 商户ID
     * @param string $type 转账类型
     * @param int $channelId 指定通道ID，0 表示自动选择
     * @return array{0: PaymentChannel, 1: TransferPluginInterface}
     */
    private function resolveTransferChannelAndPlugin(int $merchantId, string $type, int $channelId = 0): array
    {
        $query = $this->paymentChannelRepository->query()
            ->where('status', CommonConstant::STATUS_ENABLED)
            ->whereIn('merchant_id', [0, $merchantId])
            ->orderBy('sort_no')
            ->orderBy('id');

        if ($channelId > 0) {
            $query->whereKey($channelId);
        }

        $channels = $query->get();
        foreach ($channels as $channel) {
            try {
                $plugin = $this->paymentPluginManager->createTransferByChannel($channel, false);
            } catch (Throwable) {
                continue;
            }

            if (!$plugin instanceof TransferPluginInterface) {
                continue;
            }

            $transferTypes = array_values(array_filter(array_map(static fn ($item) => trim((string) $item), $plugin->getEnabledTransferTypes())));
            if (!in_array($type, $transferTypes, true)) {
                continue;
            }

            return [$channel, $plugin];
        }

        throw new ValidationException('没有可用的转账通道', [
            'type' => $type,
            'channel_id' => $channelId,
        ]);
    }

    /**
     * 根据转账单号解析转账单。
     *
     * @param string $bizNo 转账单号
     * @return TransferOrder 转账单
     */
    private function resolveTransferOrderByBizNo(string $bizNo): TransferOrder
    {
        $order = $this->transferOrderRepository->findByBizNo($bizNo);
        if (!$order) {
            throw new ResourceNotFoundException('转账单不存在', ['biz_no' => $bizNo]);
        }

        return $order;
    }

    /**
     * 请求上游插件发起转账。
     *
     * @param TransferOrder $order 转账单
     * @param TransferPluginInterface $plugin 转账插件
     * @return TransferOrder 最新转账单
     */
    private function dispatchTransfer(TransferOrder $order, TransferPluginInterface $plugin): TransferOrder
    {
        try {
            $result = $plugin->transfer($this->buildPluginTransferPayload($order));
            return $this->applyPluginTransferResult($order, $result);
        } catch (PaymentDefinitiveException $e) {
            Log::warning(sprintf(
                '[TransferService] 转账被上游明确拒绝 biz_no=%s error=%s',
                (string) $order->biz_no,
                $e->getMessage()
            ));

            return $this->markTransferFailed($order, $e->getMessage() ?: '转账被上游明确拒绝');
        } catch (Throwable $e) {
            Log::warning(sprintf(
                '[TransferService] 转账请求结果未知 biz_no=%s error=%s',
                (string) $order->biz_no,
                $e->getMessage()
            ));

            return $this->markTransferUncertain($order, $e->getMessage() ?: '转账请求结果未知');
        }
    }

    /**
     * 非终态转账单主动查单并同步一次状态。
     *
     * @param TransferOrder $order 转账单
     * @return TransferOrder 最新转账单
     */
    private function syncTransferOrderIfNeeded(TransferOrder $order): TransferOrder
    {
        if (TransferConstant::isTerminalStatus((int) $order->status) || (int) $order->channel_id <= 0) {
            return $order;
        }

        try {
            [$channel, $plugin] = $this->resolveTransferChannelAndPlugin((int) $order->merchant_id, (string) $order->type, (int) $order->channel_id);
            unset($channel);
            $result = $plugin->transferQuery($this->buildPluginTransferPayload($order));
            return $this->applyPluginTransferResult($order, $result);
        } catch (Throwable $e) {
            Log::warning(sprintf(
                '[TransferService] 转账查单失败 biz_no=%s error=%s',
                (string) $order->biz_no,
                $e->getMessage()
            ));

            return $order;
        }
    }

    /**
     * 在首次派发后创建可靠查单任务，Redis 队列只用于加速唤醒。
     */
    private function scheduleInitialTransferQueryIfNeeded(TransferOrder $order): void
    {
        if (TransferConstant::isTerminalStatus((int) $order->status)) {
            $this->recoveryTaskService->complete(
                PaymentRecoveryTaskConstant::TYPE_TRANSFER_QUERY,
                (string) $order->biz_no
            );
            return;
        }

        $delay = $this->resolveTransferQueryDelay(0);
        $this->recoveryTaskService->schedule(
            PaymentRecoveryTaskConstant::TYPE_TRANSFER_QUERY,
            (string) $order->biz_no,
            $delay,
            self::QUERY_MAX_ATTEMPTS,
            0,
            true
        );
        $this->enqueueTransferQuerySafely((string) $order->biz_no, 1, $delay);
    }

    /**
     * 最佳努力投递转账查单消息；投递失败时数据库任务仍会到期执行。
     */
    private function enqueueTransferQuerySafely(string $bizNo, int $attempt, int $delay): bool
    {
        try {
            $success = $this->paymentQueueService->sendTransferQuery($bizNo, $attempt, max(0, $delay));
            if ($success) {
                return true;
            }
            $error = 'Redis 队列返回投递失败';
        } catch (Throwable $e) {
            $error = $e->getMessage() ?: 'Redis 队列投递异常';
        }

        Log::warning(sprintf(
            '[TransferService] 转账查单消息投递失败 biz_no=%s attempt=%d error=%s',
            $bizNo,
            $attempt,
            $error
        ));

        return false;
    }

    /**
     * 应用插件转账结果。
     *
     * @param TransferOrder $order 转账单
     * @param array<string, mixed> $result 插件结果
     * @return TransferOrder 最新转账单
     */
    private function applyPluginTransferResult(TransferOrder $order, array $result): TransferOrder
    {
        $status = $this->normalizePluginStatus($result);
        if ($status === TransferConstant::TRANSFER_STATUS_FAILED) {
            return $this->markTransferFailed($order, (string) ($result['msg'] ?? $result['message'] ?? '转账失败'), $result);
        }

        if ($status === TransferConstant::TRANSFER_STATUS_SUCCESS) {
            return $this->transactionRetry(function () use ($order, $result): TransferOrder {
                $latest = $this->transferOrderRepository->findForUpdateByBizNo((string) $order->biz_no);
                if (!$latest || TransferConstant::isTerminalStatus((int) $latest->status)) {
                    return $latest ?: $order;
                }

                $latest->status = TransferConstant::TRANSFER_STATUS_SUCCESS;
                $latest->succeeded_at = $result['succeeded_at'] ?? $this->now();
                $latest->channel_order_no = $this->firstText($result, ['channel_order_no', 'chan_order_no', 'orderid']);
                $latest->channel_trade_no = $this->firstText($result, ['channel_trade_no', 'chan_trade_no', 'trade_no']);
                $latest->channel_error_code = '';
                $latest->channel_error_msg = '';
                $latest->ext_json = array_merge((array) $latest->ext_json, [
                    'plugin_result' => $this->buildPluginResultSnapshot($result),
                ]);
                $latest->save();
                $this->completeTransferRecoveryTasksInCurrentTransaction((string) $latest->biz_no);

                return $latest->refresh();
            });
        }

        return $this->transactionRetry(function () use ($order, $result): TransferOrder {
            $latest = $this->transferOrderRepository->findForUpdateByBizNo((string) $order->biz_no);
            if (!$latest || TransferConstant::isTerminalStatus((int) $latest->status)) {
                return $latest ?: $order;
            }

            $latest->status = TransferConstant::TRANSFER_STATUS_PROCESSING;
            $latest->channel_order_no = $this->firstText($result, ['channel_order_no', 'chan_order_no', 'orderid']) ?: $latest->channel_order_no;
            $latest->channel_trade_no = $this->firstText($result, ['channel_trade_no', 'chan_trade_no', 'trade_no']) ?: $latest->channel_trade_no;
            $latest->ext_json = array_merge((array) $latest->ext_json, [
                'plugin_result' => $this->buildPluginResultSnapshot($result),
            ]);
            $latest->save();

            return $latest->refresh();
        });
    }

    /**
     * 标记转账失败并释放已扣商户余额。
     *
     * @param TransferOrder $order 转账单
     * @param string $message 失败原因
     * @param array<string, mixed> $result 插件结果
     * @return TransferOrder 最新转账单
     */
    private function markTransferFailed(TransferOrder $order, string $message, array $result = []): TransferOrder
    {
        return $this->transactionRetry(function () use ($order, $message, $result): TransferOrder {
            $latest = $this->transferOrderRepository->findForUpdateByBizNo((string) $order->biz_no);
            if (!$latest || TransferConstant::isTerminalStatus((int) $latest->status)) {
                return $latest ?: $order;
            }

            $releaseAmount = (int) $latest->amount + (int) $latest->cost_amount;
            if ($releaseAmount > 0) {
                $this->merchantAccountService->releaseTransferAmountInCurrentTransaction(
                    (int) $latest->merchant_id,
                    $releaseAmount,
                    (string) $latest->biz_no,
                    'TRANSFER_RELEASE:' . (string) $latest->biz_no,
                    [
                        'remark' => '转账失败释放',
                        'message' => $message,
                    ],
                    (string) ($latest->trace_no ?: $latest->biz_no)
                );
            }

            $latest->status = TransferConstant::TRANSFER_STATUS_FAILED;
            $latest->failed_at = $this->now();
            $latest->channel_error_code = mb_strcut(
                $this->firstText($result, ['channel_error_code', 'code']),
                0,
                64,
                'UTF-8'
            );
            $latest->channel_error_msg = mb_strcut($message, 0, 255, 'UTF-8');
            $latest->ext_json = array_merge((array) $latest->ext_json, [
                'plugin_result' => $this->buildPluginResultSnapshot($result),
            ]);
            $latest->save();
            $this->completeTransferRecoveryTasksInCurrentTransaction((string) $latest->biz_no);

            return $latest->refresh();
        });
    }

    /**
     * 记录转账请求结果未知，保留处理中状态和已扣资金等待主动查单。
     *
     * 网络超时、响应解析失败和验签失败都不能证明上游未受理，因此这里绝不释放
     * 转账本金与手续费。只有明确失败结果或 PaymentDefinitiveException 才能进入失败终态。
     *
     * @param TransferOrder $order 转账单
     * @param string $message 不确定原因
     * @return TransferOrder 最新转账单
     */
    private function markTransferUncertain(TransferOrder $order, string $message): TransferOrder
    {
        return $this->transactionRetry(function () use ($order, $message): TransferOrder {
            $latest = $this->transferOrderRepository->findForUpdateByBizNo((string) $order->biz_no);
            if (!$latest || TransferConstant::isTerminalStatus((int) $latest->status)) {
                return $latest ?: $order;
            }

            $summary = mb_strcut($message, 0, 255, 'UTF-8');
            $latest->status = TransferConstant::TRANSFER_STATUS_PROCESSING;
            $latest->channel_error_code = 'TRANSFER_RESULT_UNCERTAIN';
            $latest->channel_error_msg = $summary;
            $latest->save();

            return $latest->refresh();
        });
    }

    /**
     * 在转账终态事务内同时收口派发和查单任务。
     */
    private function completeTransferRecoveryTasksInCurrentTransaction(string $bizNo): void
    {
        foreach ([
            PaymentRecoveryTaskConstant::TYPE_TRANSFER_DISPATCH,
            PaymentRecoveryTaskConstant::TYPE_TRANSFER_QUERY,
        ] as $taskType) {
            $this->recoveryTaskService->completeInCurrentTransaction($taskType, $bizNo);
        }
    }

    /**
     * 安全投递转账派发消息。
     *
     * 数据库已经提交后，Redis 投递失败不能再向调用方抛出为“下单失败”；失败快照
     * 会由运行时维护进程继续扫描恢复。
     *
     * @param TransferOrder $order 转账单
     * @param int $delay 延迟秒数
     * @return bool 是否投递成功
     */
    private function enqueueTransferDispatchSafely(TransferOrder $order, int $delay = 0): bool
    {
        $success = false;
        $error = '';

        try {
            $success = $this->paymentQueueService->sendTransferDispatch((string) $order->biz_no, max(0, $delay));
            if (!$success) {
                $error = 'Redis 队列返回投递失败';
            }
        } catch (Throwable $e) {
            $error = $e->getMessage() ?: 'Redis 队列投递异常';
        }

        if (!$success) {
            Log::warning(sprintf(
                '[TransferService] 转账派发消息投递失败 biz_no=%s error=%s',
                (string) $order->biz_no,
                $error
            ));
        }

        return $success;
    }

    /**
     * 解析转账单。
     *
     * @param Merchant $merchant 商户
     * @param array<string, mixed> $input 请求参数
     * @return TransferOrder
     */
    private function resolveTransferOrder(Merchant $merchant, array $input): TransferOrder
    {
        $merchantId = (int) $merchant->id;
        $bizNo = trim((string) ($input['biz_no'] ?? ''));
        $outBizNo = trim((string) ($input['out_biz_no'] ?? ''));

        if ($bizNo !== '') {
            $order = $this->transferOrderRepository->findByBizNo($bizNo);
            if (!$order || (int) $order->merchant_id !== $merchantId) {
                throw new ResourceNotFoundException('转账单不存在', ['biz_no' => $bizNo]);
            }

            return $order;
        }

        if ($outBizNo !== '') {
            $order = $this->transferOrderRepository->findByOutBizNo($merchantId, $outBizNo);
            if (!$order) {
                throw new ResourceNotFoundException('转账单不存在', ['out_biz_no' => $outBizNo]);
            }

            return $order;
        }

        throw new ValidationException('biz_no/out_biz_no 不能为空');
    }

    /**
     * 格式化转账单。
     *
     * @param TransferOrder $order 转账单
     * @return array<string, mixed>
     */
    private function formatTransferOrder(TransferOrder $order): array
    {
        return [
            'status' => (int) $order->status,
            'errmsg' => (string) ($order->channel_error_msg ?? ''),
            'biz_no' => (string) $order->biz_no,
            'out_biz_no' => (string) $order->out_biz_no,
            'orderid' => (string) ($order->channel_order_no ?: $order->biz_no),
            'paydate' => $this->formatDateTime($order->succeeded_at ?? null, ''),
            'amount' => $this->formatAmount((int) $order->amount),
            'cost_money' => $this->formatAmount((int) $order->cost_amount),
            'remark' => (string) $order->remark,
        ];
    }

    /**
     * 构建插件转账请求载荷。
     *
     * @param TransferOrder $order 转账单
     * @return array<string, mixed> 插件转账参数
     */
    private function buildPluginTransferPayload(TransferOrder $order): array
    {
        return [
            'transfer_no' => (string) $order->biz_no,
            'biz_no' => (string) $order->biz_no,
            'out_biz_no' => (string) $order->out_biz_no,
            'type' => (string) $order->type,
            'account' => (string) $order->account,
            'name' => (string) $order->name,
            'amount' => (int) $order->amount,
            'money' => $this->formatAmount((int) $order->amount),
            'cost_amount' => (int) $order->cost_amount,
            'remark' => (string) $order->remark,
            'bookid' => (string) $order->bookid,
            'channel_request_no' => (string) $order->channel_request_no,
            'channel_order_no' => (string) ($order->channel_order_no ?? ''),
            'channel_trade_no' => (string) ($order->channel_trade_no ?? ''),
            'trace_no' => (string) ($order->trace_no ?: $order->biz_no),
            'extra' => (array) ($order->ext_json ?? []),
        ];
    }

    /**
     * 归一化插件转账状态。
     *
     * @param array<string, mixed> $result 插件结果
     * @return int 平台转账状态
     */
    private function normalizePluginStatus(array $result): int
    {
        $statusText = strtolower(trim((string) ($result['status'] ?? $result['trade_status'] ?? $result['channel_status'] ?? '')));
        if (in_array($statusText, ['success', 'succeeded', 'finished', 'paid'], true)) {
            return TransferConstant::TRANSFER_STATUS_SUCCESS;
        }

        if (in_array($statusText, ['failed', 'fail', 'closed', 'error'], true)) {
            return TransferConstant::TRANSFER_STATUS_FAILED;
        }

        $statusCode = (int) ($result['status_code'] ?? -1);
        if ($statusCode === TransferConstant::TRANSFER_STATUS_SUCCESS) {
            return TransferConstant::TRANSFER_STATUS_SUCCESS;
        }
        if ($statusCode === TransferConstant::TRANSFER_STATUS_FAILED) {
            return TransferConstant::TRANSFER_STATUS_FAILED;
        }

        return TransferConstant::TRANSFER_STATUS_PROCESSING;
    }

    /**
     * 构建插件结果快照。
     *
     * raw_data 可能包含完整上游响应，快照落库时剔除以降低日志和数据表压力。
     *
     * @param array<string, mixed> $result 插件结果
     * @return array<string, mixed> 可落库结果
     */
    private function buildPluginResultSnapshot(array $result): array
    {
        unset($result['raw_data']);
        return $result;
    }

    /**
     * 从多个候选字段中取第一个非空文本。
     *
     * @param array<string, mixed> $data 数据
     * @param array<int, string> $keys 候选字段
     * @return string 字段值
     */
    private function firstText(array $data, array $keys): string
    {
        foreach ($keys as $key) {
            $value = $data[$key] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return '';
    }

    /**
     * 解析转账费率。
     *
     * @return float 转账手续费率
     */
    private function resolveTransferRate(): float
    {
        $rate = (string) config('epay.v2.transfer_rate', '0.01');
        $rate = trim($rate);
        if ($rate === '' || !is_numeric($rate)) {
            return 0.01;
        }

        $floatRate = (float) $rate;
        return $floatRate > 0 ? $floatRate : 0.01;
    }

    /**
     * 解析下一次转账查单延迟。
     *
     * @param int $attempt 当前查单次数
     * @return int 延迟秒数
     */
    private function resolveTransferQueryDelay(int $attempt): int
    {
        $index = max(0, min($attempt, count(self::QUERY_DELAY_SECONDS) - 1));

        return self::QUERY_DELAY_SECONDS[$index];
    }

    /**
     * 金额字符串转分。
     *
     * @param string $money 金额字符串
     * @return int
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
}
