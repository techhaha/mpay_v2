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
    private const ACCOUNTS_KEY = 'receipt_watcher_accounts';
    private const QUERY_ACCOUNTS_KEY = 'receipt_watcher_query_accounts';
    private const ACCOUNT_TASK_KEY_PREFIX = 'receipt_watcher_account_';
    private const ORDERS_KEY_PREFIX = 'receipt_watcher_orders_';
    private const FLOW_SEEN_KEY_PREFIX = 'receipt_watcher_flow_seen_';
    private const FLOW_LOCK_KEY_PREFIX = 'receipt_watcher_flow_lock_';

    /**
     * 构造方法。
     *
     * @param PaymentChannelRepository $paymentChannelRepository 支付通道仓库
     * @param PaymentPluginConfRepository $paymentPluginConfRepository 支付插件配置仓库
     * @param PaymentTypeRepository $paymentTypeRepository 支付方式仓库
     * @param PayOrderRepository $payOrderRepository 支付单仓库
     */
    public function __construct(
        protected PaymentChannelRepository $paymentChannelRepository,
        protected PaymentPluginConfRepository $paymentPluginConfRepository,
        protected PaymentTypeRepository $paymentTypeRepository,
        protected PayOrderRepository $payOrderRepository
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

        return array_values(array_unique($codes));
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
     * @param array<string, mixed> $config 插件配置
     * @return int 查询间隔秒数
     */
    private function queryIntervalSeconds(array $config): int
    {
        return max(2, (int) ($config['receipt_watcher_query_interval_seconds'] ?? 3));
    }

    /**
     * @param string $pluginCode 插件编码
     * @param int $apiConfigId 插件配置ID
     * @return string 账号键
     */
    private function accountKey(string $pluginCode, int $apiConfigId): string
    {
        return $this->safeKeyPart($pluginCode) . '_' . $apiConfigId;
    }

    /**
     * @param string $accountKey 账号键
     * @return string 账号任务键
     */
    private function accountTaskKey(string $accountKey): string
    {
        return self::ACCOUNT_TASK_KEY_PREFIX . $this->safeKeyPart($accountKey);
    }

    /**
     * @param string $accountKey 账号键
     * @return string 订单集合键
     */
    private function ordersKey(string $accountKey): string
    {
        return self::ORDERS_KEY_PREFIX . $this->safeKeyPart($accountKey);
    }

    /**
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
     * @param string $value 原始键片段
     * @return string 安全键片段
     */
    private function safeKeyPart(string $value): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_\\-]/', '_', $value) ?? '';

        return trim($safe, '_') !== '' ? trim($safe, '_') : 'empty';
    }

    /**
     * @param array<string, mixed> $payload 载荷
     * @return string JSON 字符串
     */
    private function jsonEncode(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }
}
