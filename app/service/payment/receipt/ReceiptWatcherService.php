<?php

namespace app\service\payment\receipt;

use app\common\base\BaseService;
use app\model\payment\PayOrder;
use app\model\payment\PaymentChannel;
use app\repository\payment\config\PaymentChannelRepository;
use app\repository\payment\config\PaymentPluginConfRepository;
use app\repository\payment\config\PaymentTypeRepository;
use app\repository\payment\trade\PayOrderRepository;
use support\Log;
use support\Redis;
use RuntimeException;
use Throwable;

/**
 * 网页流水监听调度服务。
 *
 * Webman 只维护哪些账号需要查询，以及查询结果进入哪个支付通道；真正登录平台和抓流水
 * 由独立的 receipt_watcher 工具完成。
 */
class ReceiptWatcherService extends BaseService
{
    /**
     * Redis Key 协议常量。
     *
     * 这些名称必须与 Python receipt_watcher 保持一致，详细含义见
     * watcher/docs/COMMON_CONSTANTS.md。修改时需要同步两端代码并清理旧 Redis 数据。
     */
    private const ACCOUNTS_KEY = 'receipt_watcher_accounts';
    private const QUERY_ACCOUNTS_KEY = 'receipt_watcher_query_accounts';
    private const PRELOGIN_ACCOUNTS_KEY = 'receipt_watcher_prelogin_accounts';
    private const ACCOUNT_STREAM_KEY = 'receipt_watcher_account_stream';
    private const ACCOUNT_ENQUEUED_KEY_PREFIX = 'receipt_watcher_account_enqueued_';
    private const PRELOGIN_ENQUEUED_KEY_PREFIX = 'receipt_watcher_prelogin_enqueued_';
    private const ACCOUNT_TASK_KEY_PREFIX = 'receipt_watcher_account_';
    private const ORDERS_KEY_PREFIX = 'receipt_watcher_orders_';
    private const LOCK_KEY_PREFIX = 'receipt_watcher_lock_';
    private const FLOW_SEEN_KEY_PREFIX = 'receipt_watcher_flow_seen_';
    private const FLOW_LOCK_KEY_PREFIX = 'receipt_watcher_flow_lock_';
    private const TASK_TYPE_QUERY = 'query';
    private const TASK_TYPE_PRELOGIN = 'prelogin';

    /**
     * 构造方法。
     *
     * @param PaymentChannelRepository $paymentChannelRepository 支付通道仓库
     * @param PaymentPluginConfRepository $paymentPluginConfRepository 支付插件配置仓库
     * @param PaymentTypeRepository $paymentTypeRepository 支付方式仓库
     * @param PayOrderRepository $payOrderRepository 支付单仓库
     * @param ReceiptWatcherLicenseService $receiptWatcherLicenseService 网页监听配置服务
     */
    public function __construct(
        protected PaymentChannelRepository $paymentChannelRepository,
        protected PaymentPluginConfRepository $paymentPluginConfRepository,
        protected PaymentTypeRepository $paymentTypeRepository,
        protected PayOrderRepository $payOrderRepository,
        protected ReceiptWatcherLicenseService $receiptWatcherLicenseService
    ) {
    }

    /**
     * 刷新可监听账号缓存。
     *
     * @return array<string, int> 执行摘要
     */
    public function refreshChannelCache(): array
    {
        $pluginCodes = $this->supportedPluginCodes();
        if ($pluginCodes === []) {
            $this->clearQueryTasks();
            $this->clearPreloginTasks();
            Redis::del(self::ACCOUNTS_KEY);
            return [
                'accounts' => 0,
                'channels' => 0,
            ];
        }

        $channels = $this->paymentChannelRepository->listReceiptWatcherChannels($pluginCodes);

        $configIds = $channels->pluck('api_config_id')->map(fn ($id): int => (int) $id)->filter()->unique()->values()->all();
        $payTypeIds = $channels->pluck('pay_type_id')->map(fn ($id): int => (int) $id)->filter()->unique()->values()->all();
        $configs = $this->paymentPluginConfRepository->listByIds($configIds)->keyBy('id');
        $payTypes = $this->paymentTypeRepository->listByIds($payTypeIds)->keyBy('id');

        $accounts = [];
        foreach ($channels as $channel) {
            $config = $configs->get((int) $channel->api_config_id);
            if (!$config || (string) $config->plugin_code !== (string) $channel->plugin_code) {
                continue;
            }

            $payType = $payTypes->get((int) $channel->pay_type_id);
            $accountKey = $this->accountKey((string) $channel->plugin_code, (int) $channel->api_config_id);
            if (!isset($accounts[$accountKey])) {
                $pluginConfig = (array) ($config->config ?? []);
                $accounts[$accountKey] = [
                    'account_key' => $accountKey,
                    'plugin_code' => (string) $channel->plugin_code,
                    'api_config_id' => (int) $channel->api_config_id,
                    'merchant_id' => (int) $config->merchant_id,
                    'config' => $pluginConfig,
                    'query_interval_seconds' => $this->queryIntervalSeconds($pluginConfig),
                    'channels' => [],
                    'refreshed_at' => time(),
                ];
            }

            $accounts[$accountKey]['channels'][] = [
                'channel_id' => (int) $channel->id,
                'merchant_id' => (int) $channel->merchant_id,
                'name' => (string) $channel->name,
                'pay_type_id' => (int) $channel->pay_type_id,
                'pay_type' => (string) ($payType->code ?? ''),
                'pay_type_name' => (string) ($payType->name ?? ''),
                'terminal_no' => (string) (($accounts[$accountKey]['config']['receipt_terminal_no'] ?? '') ?: ''),
            ];
        }

        Redis::del(self::ACCOUNTS_KEY);
        foreach ($accounts as $accountKey => $account) {
            Redis::hSet(self::ACCOUNTS_KEY, $accountKey, $this->jsonEncode($account));
        }
        $this->removeStaleQueryTasks(array_keys($accounts));
        $this->syncPreloginTasks(array_keys($accounts));

        return [
            'accounts' => count($accounts),
            'channels' => array_sum(array_map(static fn (array $account): int => count($account['channels']), $accounts)),
        ];
    }

    /**
     * 同步待支付订单到 Redis 查询任务。
     *
     * @param int $limit 扫描订单数量
     * @return array<string, int> 执行摘要
     */
    public function syncPendingOrders(int $limit = 500): array
    {
        if (!$this->watcherEnabled()) {
            $this->clearQueryTasks();
            $this->clearPreloginTasks();
            return [
                'scanned' => 0,
                'accounts' => 0,
                'orders' => 0,
            ];
        }

        $accounts = $this->accountMap();
        if ($accounts === []) {
            $this->refreshChannelCache();
            $accounts = $this->accountMap();
        }
        if ($accounts === []) {
            $this->clearQueryTasks();
            return [
                'scanned' => 0,
                'accounts' => 0,
                'orders' => 0,
            ];
        }

        $pluginCodes = array_values(array_unique(array_map(static fn (array $account): string => (string) $account['plugin_code'], $accounts)));
        $orders = $this->payOrderRepository->listReceiptWatcherPendingOrders($pluginCodes, $this->now(), $limit);

        $ordersByAccount = [];
        foreach ($orders as $order) {
            $accountKey = $this->accountKey((string) $order->plugin_code, (int) $order->api_config_id);
            if (!isset($accounts[$accountKey])) {
                continue;
            }

            $ordersByAccount[$accountKey][] = [
                'pay_no' => (string) $order->pay_no,
                'channel_id' => (int) $order->channel_id,
                'pay_type_id' => (int) $order->pay_type_id,
                'pay_type' => (string) ($order->pay_type ?? ''),
                'pay_amount' => (int) $order->pay_amount,
                'channel_order_no' => (string) ($order->channel_order_no ?? ''),
                'channel_trade_no' => (string) ($order->channel_trade_no ?? ''),
                'request_at' => (string) $order->request_at,
                'expire_at' => (string) ($order->expire_at ?? ''),
                'created_at' => (string) $order->created_at,
                'ext_json' => $this->receiptOrderExtJson($order),
            ];
        }

        foreach ($accounts as $accountKey => $account) {
            $accountOrders = $ordersByAccount[$accountKey] ?? [];
            if ($accountOrders === []) {
                $this->removeAccountQueryTask($accountKey);
                continue;
            }

            $this->storeAccountOrders($account, $accountOrders);
        }

        return [
            'scanned' => count($orders),
            'accounts' => count($ordersByAccount),
            'orders' => array_sum(array_map('count', $ordersByAccount)),
        ];
    }

    /**
     * 将到期账号查询任务投放到 Redis 账号任务流。
     *
     * @param int $limit 单轮最多投放账号数
     * @return array<string, int> 执行摘要
     */
    public function dispatchDueAccountTasks(int $limit = 100): array
    {
        if (!$this->watcherEnabled()) {
            return [
                'due' => 0,
                'queued' => 0,
                'stale' => 0,
                'locked' => 0,
                'deduped' => 0,
            ];
        }

        $now = time();
        $accountKeys = $this->redisRaw(
            'ZRANGEBYSCORE',
            self::QUERY_ACCOUNTS_KEY,
            '-inf',
            (string) $now,
            'LIMIT',
            '0',
            (string) max(1, $limit)
        );
        if (!is_array($accountKeys) || $accountKeys === []) {
            return [
                'due' => 0,
                'queued' => 0,
                'stale' => 0,
                'locked' => 0,
                'deduped' => 0,
            ];
        }

        $summary = [
            'due' => count($accountKeys),
            'queued' => 0,
            'stale' => 0,
            'locked' => 0,
            'deduped' => 0,
        ];
        foreach ($accountKeys as $rawAccountKey) {
            $accountKey = (string) $rawAccountKey;
            $task = $this->accountTask($accountKey);
            $orderCount = (int) Redis::hLen($this->ordersKey($accountKey));
            if ($task === null || $orderCount <= 0) {
                $summary['stale']++;
                $this->removeAccountQueryTask($accountKey);
                continue;
            }

            if ((bool) Redis::exists($this->lockKey($accountKey))) {
                $summary['locked']++;
                continue;
            }

            $enqueuedKey = $this->accountEnqueuedKey($accountKey);
            $enqueuedTtl = $this->accountEnqueuedTtl($task);
            $enqueued = $this->redisRaw('SET', $enqueuedKey, (string) $now, 'NX', 'EX', (string) $enqueuedTtl);
            if ($enqueued !== true && strtoupper((string) $enqueued) !== 'OK') {
                $summary['deduped']++;
                continue;
            }

            try {
                $streamId = $this->redisRaw(
                    'XADD',
                    self::ACCOUNT_STREAM_KEY,
                    'MAXLEN',
                    '~',
                    '10000',
                    '*',
                    'task_type',
                    self::TASK_TYPE_QUERY,
                    'account_key',
                    $accountKey,
                    'plugin_code',
                    (string) ($task['plugin_code'] ?? ''),
                    'api_config_id',
                    (string) ((int) ($task['api_config_id'] ?? 0)),
                    'order_count',
                    (string) $orderCount,
                    'due_at',
                    (string) $now,
                    'enqueued_at',
                    (string) $now,
                    'trace_id',
                    bin2hex(random_bytes(8))
                );
                if ($streamId === false || $streamId === null || $streamId === '') {
                    throw new RuntimeException('Redis XADD 返回空结果');
                }
                $summary['queued']++;
            } catch (Throwable $e) {
                Redis::del($enqueuedKey);
                Log::warning('[ReceiptWatcherService] 投放账号查询任务失败：' . $e->getMessage());
            }
        }

        return $summary;
    }

    /**
     * 将到期账号预登录任务投放到 Redis 账号任务流。
     *
     * 预登录只维护第三方平台登录态，不要求账号下存在待支付订单，也不会确认订单。
     *
     * @param int $limit 单轮最多投放账号数
     * @return array<string, int> 执行摘要
     */
    public function dispatchDuePreloginAccountTasks(int $limit = 100): array
    {
        if (!$this->watcherEnabled()) {
            return [
                'due' => 0,
                'queued' => 0,
                'stale' => 0,
                'locked' => 0,
                'deduped' => 0,
            ];
        }

        $accounts = $this->accountMap();
        if ($accounts === []) {
            $this->refreshChannelCache();
            $accounts = $this->accountMap();
        }
        if ($accounts === []) {
            $this->clearPreloginTasks();
            return [
                'due' => 0,
                'queued' => 0,
                'stale' => 0,
                'locked' => 0,
                'deduped' => 0,
            ];
        }
        if ((int) Redis::zCard(self::PRELOGIN_ACCOUNTS_KEY) <= 0) {
            $this->syncPreloginTasks(array_keys($accounts));
        }

        $now = time();
        $accountKeys = $this->redisRaw(
            'ZRANGEBYSCORE',
            self::PRELOGIN_ACCOUNTS_KEY,
            '-inf',
            (string) $now,
            'LIMIT',
            '0',
            (string) max(1, $limit)
        );
        if (!is_array($accountKeys) || $accountKeys === []) {
            return [
                'due' => 0,
                'queued' => 0,
                'stale' => 0,
                'locked' => 0,
                'deduped' => 0,
            ];
        }

        $summary = [
            'due' => count($accountKeys),
            'queued' => 0,
            'stale' => 0,
            'locked' => 0,
            'deduped' => 0,
        ];
        $interval = $this->preloginIntervalSeconds();
        $retryMax = $this->loginRetryMax();
        foreach ($accountKeys as $rawAccountKey) {
            $accountKey = (string) $rawAccountKey;
            $account = $accounts[$accountKey] ?? null;
            if (!is_array($account)) {
                $summary['stale']++;
                $this->removePreloginTask($accountKey);
                continue;
            }

            if ((bool) Redis::exists($this->lockKey($accountKey))) {
                $summary['locked']++;
                continue;
            }

            $enqueuedKey = $this->preloginEnqueuedKey($accountKey);
            $enqueued = $this->redisRaw('SET', $enqueuedKey, (string) $now, 'NX', 'EX', (string) $this->preloginEnqueuedTtl($interval));
            if ($enqueued !== true && strtoupper((string) $enqueued) !== 'OK') {
                $summary['deduped']++;
                continue;
            }

            try {
                $streamId = $this->redisRaw(
                    'XADD',
                    self::ACCOUNT_STREAM_KEY,
                    'MAXLEN',
                    '~',
                    '10000',
                    '*',
                    'task_type',
                    self::TASK_TYPE_PRELOGIN,
                    'account_key',
                    $accountKey,
                    'plugin_code',
                    (string) ($account['plugin_code'] ?? ''),
                    'api_config_id',
                    (string) ((int) ($account['api_config_id'] ?? 0)),
                    'prelogin_interval_seconds',
                    (string) $interval,
                    'login_retry_max',
                    (string) $retryMax,
                    'due_at',
                    (string) $now,
                    'enqueued_at',
                    (string) $now,
                    'trace_id',
                    bin2hex(random_bytes(8))
                );
                if ($streamId === false || $streamId === null || $streamId === '') {
                    throw new RuntimeException('Redis XADD 返回空结果');
                }
                $summary['queued']++;
            } catch (Throwable $e) {
                Redis::del($enqueuedKey);
                Log::warning('[ReceiptWatcherService] 投放账号预登录任务失败：' . $e->getMessage());
            }
        }

        return $summary;
    }

    /**
     * 支付单进入终态后清理对应账号查询任务。
     *
     * @param PayOrder $payOrder 支付单
     * @return void
     */
    public function cleanupPayOrder(PayOrder $payOrder): void
    {
        $channel = $this->paymentChannelRepository->find((int) $payOrder->channel_id);
        if (!$channel || (int) $channel->api_config_id <= 0) {
            return;
        }

        $accountKey = $this->accountKey((string) $channel->plugin_code, (int) $channel->api_config_id);
        $ordersKey = $this->ordersKey($accountKey);
        Redis::hDel($ordersKey, (string) $payOrder->pay_no);

        if ((int) Redis::hLen($ordersKey) <= 0) {
            $this->removeAccountQueryTask($accountKey);
            return;
        }

        $taskKey = $this->accountTaskKey($accountKey);
        $raw = Redis::get($taskKey);
        $meta = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
        if (is_array($meta)) {
            $meta['order_count'] = (int) Redis::hLen($ordersKey);
            Redis::setEx($taskKey, max(60, (int) Redis::ttl($taskKey)), $this->jsonEncode($meta));
        }
    }

    /**
     * 根据流水支付方式解析具体通道。
     *
     * @param string $pluginCode 插件编码
     * @param int $apiConfigId 插件配置ID
     * @param string $payTypeCode 支付方式编码
     * @return PaymentChannel|null 支付通道
     */
    public function resolveChannelForFlow(string $pluginCode, int $apiConfigId, string $payTypeCode): ?PaymentChannel
    {
        return $this->paymentChannelRepository->findReceiptFlowChannel($pluginCode, $apiConfigId, $payTypeCode);
    }

    /**
     * 判断流水是否已经处理成功。
     *
     * @param string $pluginCode 插件编码
     * @param int $apiConfigId 插件配置ID
     * @param array<string, mixed> $record 流水记录
     * @return bool 是否已处理
     */
    public function isFlowSeen(string $pluginCode, int $apiConfigId, array $record): bool
    {
        return (bool) Redis::exists($this->flowSeenKey($pluginCode, $apiConfigId, $record));
    }

    /**
     * 标记流水已处理成功。
     *
     * @param string $pluginCode 插件编码
     * @param int $apiConfigId 插件配置ID
     * @param array<string, mixed> $record 流水记录
     * @return void
     */
    public function markFlowSeen(string $pluginCode, int $apiConfigId, array $record): void
    {
        Redis::setEx($this->flowSeenKey($pluginCode, $apiConfigId, $record), 30 * 86400, '1');
    }

    /**
     * 获取流水处理锁。
     *
     * @param string $pluginCode 插件编码
     * @param int $apiConfigId 插件配置ID
     * @param array<string, mixed> $record 流水记录
     * @return string|null 锁令牌
     */
    public function acquireFlowLock(string $pluginCode, int $apiConfigId, array $record): ?string
    {
        $key = $this->flowLockKey($pluginCode, $apiConfigId, $record);
        $token = bin2hex(random_bytes(8));
        if (!Redis::setNx($key, $token)) {
            return null;
        }

        Redis::expire($key, 30);
        return $token;
    }

    /**
     * 释放流水处理锁。
     *
     * @param string $pluginCode 插件编码
     * @param int $apiConfigId 插件配置ID
     * @param array<string, mixed> $record 流水记录
     * @param string $token 锁令牌
     * @return void
     */
    public function releaseFlowLock(string $pluginCode, int $apiConfigId, array $record, string $token): void
    {
        $key = $this->flowLockKey($pluginCode, $apiConfigId, $record);
        if ((string) Redis::get($key) === $token) {
            Redis::del($key);
        }
    }

    /**
     * 获取支持网页流水监听的插件编码。
     *
     * @return array<int, string> 插件编码列表
     */
    private function supportedPluginCodes(): array
    {
        $raw = (string) sys_config('receipt_watcher_plugin_codes', '');
        $parts = preg_split('/[\s,，;；]+/', $raw) ?: [];
        $codes = [];
        foreach ($parts as $part) {
            $code = trim((string) $part);
            if ($code !== '') {
                $codes[] = $code;
            }
        }

        return $this->receiptWatcherLicenseService->filterWatcherCapablePluginCodes(array_values(array_unique($codes)));
    }

    /**
     * 判断网页流水监听是否启用。
     *
     * @return bool 是否启用
     */
    private function watcherEnabled(): bool
    {
        $value = strtolower(trim((string) sys_config('receipt_watcher_enabled', '0')));

        return in_array($value, ['1', 'true', 'yes', 'on', 'enabled'], true);
    }

    /**
     * 读取账号缓存。
     *
     * @return array<string, array<string, mixed>> 账号映射
     */
    private function accountMap(): array
    {
        $raw = Redis::hGetAll(self::ACCOUNTS_KEY);
        if (!is_array($raw) || $raw === []) {
            return [];
        }

        $accounts = [];
        foreach ($raw as $key => $value) {
            $decoded = is_string($value) ? json_decode($value, true) : null;
            if (is_array($decoded)) {
                $accounts[(string) $key] = $decoded;
            }
        }

        return $accounts;
    }

    /**
     * 读取账号查询任务元信息。
     *
     * @param string $accountKey 账号键
     * @return array<string, mixed>|null 任务元信息
     */
    private function accountTask(string $accountKey): ?array
    {
        $raw = Redis::get($this->accountTaskKey($accountKey));
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * 写入账号订单与查询任务。
     *
     * @param array<string, mixed> $account 账号缓存
     * @param array<int, array<string, mixed>> $orders 订单列表
     * @return void
     */
    private function storeAccountOrders(array $account, array $orders): void
    {
        $accountKey = (string) $account['account_key'];
        $ordersKey = $this->ordersKey($accountKey);
        Redis::del($ordersKey);
        foreach ($orders as $order) {
            Redis::hSet($ordersKey, (string) $order['pay_no'], $this->jsonEncode($order));
        }

        $expireAt = $this->maxExpireTimestamp($orders);
        $ttl = max(60, $expireAt - time() + 60);
        $meta = [
            'account_key' => $accountKey,
            'plugin_code' => (string) $account['plugin_code'],
            'api_config_id' => (int) $account['api_config_id'],
            'query_interval_seconds' => $this->queryIntervalSeconds((array) ($account['config'] ?? [])),
            'order_count' => count($orders),
            'expire_at' => $expireAt,
            'updated_at' => time(),
        ];
        Redis::setEx($this->accountTaskKey($accountKey), $ttl, $this->jsonEncode($meta));
        Redis::expire($ordersKey, $ttl);

        $score = Redis::zScore(self::QUERY_ACCOUNTS_KEY, $accountKey);
        if ($score === false || $score === null) {
            Redis::zAdd(self::QUERY_ACCOUNTS_KEY, time(), $accountKey);
        }
    }

    /**
     * 移除账号查询任务。
     *
     * @param string $accountKey 账号键
     * @return void
     */
    private function removeAccountQueryTask(string $accountKey): void
    {
        Redis::zRem(self::QUERY_ACCOUNTS_KEY, $accountKey);
        Redis::del($this->accountTaskKey($accountKey));
        Redis::del($this->ordersKey($accountKey));
        Redis::del($this->accountEnqueuedKey($accountKey));
    }

    /**
     * 清理所有账号查询任务。
     *
     * @return void
     */
    private function clearQueryTasks(): void
    {
        try {
            $accountKeys = Redis::zRange(self::QUERY_ACCOUNTS_KEY, 0, -1);
            if (is_array($accountKeys)) {
                foreach ($accountKeys as $accountKey) {
                    $this->removeAccountQueryTask((string) $accountKey);
                }
            }
            Redis::del(self::QUERY_ACCOUNTS_KEY);
        } catch (Throwable $e) {
            Log::warning('[ReceiptWatcherService] 清理查询任务失败：' . $e->getMessage());
        }
    }

    /**
     * 同步账号预登录计划。
     *
     * @param array<int, string> $activeAccountKeys 当前有效账号键
     * @return void
     */
    private function syncPreloginTasks(array $activeAccountKeys): void
    {
        $this->removeStalePreloginTasks($activeAccountKeys);
        foreach ($activeAccountKeys as $accountKey) {
            $score = Redis::zScore(self::PRELOGIN_ACCOUNTS_KEY, $accountKey);
            if ($score === false || $score === null) {
                Redis::zAdd(self::PRELOGIN_ACCOUNTS_KEY, time(), $accountKey);
            }
        }
    }

    /**
     * 清理所有账号预登录计划。
     *
     * @return void
     */
    private function clearPreloginTasks(): void
    {
        try {
            $accountKeys = Redis::zRange(self::PRELOGIN_ACCOUNTS_KEY, 0, -1);
            if (is_array($accountKeys)) {
                foreach ($accountKeys as $accountKey) {
                    $this->removePreloginTask((string) $accountKey);
                }
            }
            Redis::del(self::PRELOGIN_ACCOUNTS_KEY);
        } catch (Throwable $e) {
            Log::warning('[ReceiptWatcherService] 清理预登录任务失败：' . $e->getMessage());
        }
    }

    /**
     * 移除已经不在账号缓存中的预登录计划。
     *
     * @param array<int, string> $activeAccountKeys 当前有效账号键
     * @return void
     */
    private function removeStalePreloginTasks(array $activeAccountKeys): void
    {
        $active = array_fill_keys($activeAccountKeys, true);
        $accountKeys = Redis::zRange(self::PRELOGIN_ACCOUNTS_KEY, 0, -1);
        if (!is_array($accountKeys)) {
            return;
        }

        foreach ($accountKeys as $accountKey) {
            $accountKey = (string) $accountKey;
            if (!isset($active[$accountKey])) {
                $this->removePreloginTask($accountKey);
            }
        }
    }

    /**
     * 移除账号预登录计划。
     *
     * @param string $accountKey 账号键
     * @return void
     */
    private function removePreloginTask(string $accountKey): void
    {
        Redis::zRem(self::PRELOGIN_ACCOUNTS_KEY, $accountKey);
        Redis::del($this->preloginEnqueuedKey($accountKey));
    }

    /**
     * 移除已经不在账号缓存中的查询任务。
     *
     * @param array<int, string> $activeAccountKeys 当前有效账号键
     * @return void
     */
    private function removeStaleQueryTasks(array $activeAccountKeys): void
    {
        $active = array_fill_keys($activeAccountKeys, true);
        $accountKeys = Redis::zRange(self::QUERY_ACCOUNTS_KEY, 0, -1);
        if (!is_array($accountKeys)) {
            return;
        }

        foreach ($accountKeys as $accountKey) {
            $accountKey = (string) $accountKey;
            if (!isset($active[$accountKey])) {
                $this->removeAccountQueryTask($accountKey);
            }
        }
    }

    /**
     * 计算账号订单快照的最大过期时间。
     *
     * @param array<int, array<string, mixed>> $orders 订单列表
     * @return int 最大过期时间戳
     */
    private function maxExpireTimestamp(array $orders): int
    {
        $timestamps = [];
        foreach ($orders as $order) {
            $expireAt = trim((string) ($order['expire_at'] ?? ''));
            $timestamp = $expireAt !== '' ? strtotime($expireAt) : false;
            if ($timestamp !== false && $timestamp > 0) {
                $timestamps[] = (int) $timestamp;
            }
        }

        return $timestamps === [] ? time() + 600 : max($timestamps);
    }

    /**
     * 读取插件配置中的账号查询间隔。
     *
     * @param array<string, mixed> $config 插件配置
     * @return int 查询间隔秒数
     */
    private function queryIntervalSeconds(array $config): int
    {
        return max(2, (int) ($config['receipt_watcher_query_interval_seconds'] ?? 3));
    }

    /**
     * 读取账号预登录间隔。
     *
     * @return int 预登录间隔秒数
     */
    private function preloginIntervalSeconds(): int
    {
        return max(60, (int) sys_config('receipt_watcher_prelogin_interval_seconds', 600));
    }

    /**
     * 读取单次预登录登录动作最大重试次数。
     *
     * @return int 最大重试次数
     */
    private function loginRetryMax(): int
    {
        return max(1, (int) sys_config('receipt_watcher_login_retry_max', 10));
    }

    /**
     * 提取 watcher 需要的订单扩展信息。
     *
     * 支付单 ext_json 可能包含前端承接参数、上游原始响应等内容。这里仅透传
     * `receipt_watcher` 这个固定命名空间，新增监听插件需要给 Python watcher
     * 提供订单级识别信息时，统一写入 `ext_json.receipt_watcher`。
     *
     * @param PayOrder $order 支付单
     * @return array<string, mixed> 扩展快照
     */
    private function receiptOrderExtJson(PayOrder $order): array
    {
        $extJson = (array) ($order->ext_json ?? []);
        $metadata = $extJson['receipt_watcher'] ?? [];

        return is_array($metadata) && $metadata !== [] ? ['receipt_watcher' => $metadata] : [];
    }

    /**
     * 生成平台账号缓存键。
     *
     * @param string $pluginCode 插件编码
     * @param int $apiConfigId 插件配置ID
     * @return string 账号键
     */
    private function accountKey(string $pluginCode, int $apiConfigId): string
    {
        return $this->safeKeyPart($pluginCode) . '_' . $apiConfigId;
    }

    /**
     * 生成账号任务元信息键。
     *
     * @param string $accountKey 账号键
     * @return string 账号任务键
     */
    private function accountTaskKey(string $accountKey): string
    {
        return self::ACCOUNT_TASK_KEY_PREFIX . $this->safeKeyPart($accountKey);
    }

    /**
     * 生成账号任务投放标记键。
     *
     * @param string $accountKey 账号键
     * @return string 已投放标记键
     */
    private function accountEnqueuedKey(string $accountKey): string
    {
        return self::ACCOUNT_ENQUEUED_KEY_PREFIX . $this->safeKeyPart($accountKey);
    }

    /**
     * 生成账号预登录任务投放标记键。
     *
     * @param string $accountKey 账号键
     * @return string 已投放标记键
     */
    private function preloginEnqueuedKey(string $accountKey): string
    {
        return self::PRELOGIN_ENQUEUED_KEY_PREFIX . $this->safeKeyPart($accountKey);
    }

    /**
     * 生成账号查询锁键。
     *
     * @param string $accountKey 账号键
     * @return string 账号查询锁键
     */
    private function lockKey(string $accountKey): string
    {
        return self::LOCK_KEY_PREFIX . $this->safeKeyPart($accountKey);
    }

    /**
     * 生成账号订单快照键。
     *
     * @param string $accountKey 账号键
     * @return string 订单集合键
     */
    private function ordersKey(string $accountKey): string
    {
        return self::ORDERS_KEY_PREFIX . $this->safeKeyPart($accountKey);
    }

    /**
     * 计算账号任务投放标记过期时间。
     *
     * @param array<string, mixed> $task 账号任务元信息
     * @return int 已投放标记过期时间
     */
    private function accountEnqueuedTtl(array $task): int
    {
        $interval = max(2, (int) ($task['query_interval_seconds'] ?? 3));

        return max(300, $interval * 4);
    }

    /**
     * 计算预登录任务投放标记过期时间。
     *
     * @param int $interval 预登录间隔秒数
     * @return int 已投放标记过期时间
     */
    private function preloginEnqueuedTtl(int $interval): int
    {
        return max(300, max(60, $interval) * 2);
    }

    /**
     * 生成流水已处理标记键。
     *
     * @param string $pluginCode 插件编码
     * @param int $apiConfigId 插件配置ID
     * @param array<string, mixed> $record 流水记录
     * @return string 幂等键
     */
    private function flowSeenKey(string $pluginCode, int $apiConfigId, array $record): string
    {
        return self::FLOW_SEEN_KEY_PREFIX . $this->flowIdentity($pluginCode, $apiConfigId, $record);
    }

    /**
     * 生成流水处理锁键。
     *
     * @param string $pluginCode 插件编码
     * @param int $apiConfigId 插件配置ID
     * @param array<string, mixed> $record 流水记录
     * @return string 锁键
     */
    private function flowLockKey(string $pluginCode, int $apiConfigId, array $record): string
    {
        return self::FLOW_LOCK_KEY_PREFIX . $this->flowIdentity($pluginCode, $apiConfigId, $record);
    }

    /**
     * 生成流水幂等身份。
     *
     * @param string $pluginCode 插件编码
     * @param int $apiConfigId 插件配置ID
     * @param array<string, mixed> $record 流水记录
     * @return string 流水身份
     */
    private function flowIdentity(string $pluginCode, int $apiConfigId, array $record): string
    {
        $orderNo = trim((string) ($record['order_no'] ?? ''));
        if ($orderNo === '') {
            throw new RuntimeException('流水订单号不能为空');
        }

        return $this->safeKeyPart($pluginCode . '_' . $apiConfigId . '_' . $orderNo);
    }

    /**
     * 把业务标识转换成 Redis 安全键片段。
     *
     * @param string $value 原始键片段
     * @return string 安全键片段
     */
    private function safeKeyPart(string $value): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_\\-]/', '_', $value) ?? '';

        return trim($safe, '_') !== '' ? trim($safe, '_') : 'empty';
    }

    /**
     * 执行 Redis 原生命令。
     *
     * @param string $command 命令
     * @param mixed ...$arguments 参数
     * @return mixed 命令结果
     */
    private function redisRaw(string $command, mixed ...$arguments): mixed
    {
        return Redis::connection()->rawCommand($command, ...$arguments);
    }

    /**
     * 编码 Redis 中保存的 JSON 载荷。
     *
     * @param array<string, mixed> $payload 载荷
     * @return string JSON 字符串
     */
    private function jsonEncode(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }
}
