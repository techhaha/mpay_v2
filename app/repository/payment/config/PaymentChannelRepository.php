<?php

namespace app\repository\payment\config;

use app\common\base\BaseRepository;
use app\common\constant\CommonConstant;
use app\model\payment\PaymentChannel;

/**
 * 支付通道基础查询仓库。
 *
 * 提供商户通道的启用列表、单条查询和统计概览等基础读方法。
 */
class PaymentChannelRepository extends BaseRepository
{
    /**
     * 构造方法。
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct(new PaymentChannel());
    }

    /**
     * 查询指定商户启用的支付通道。
     *
     * @param int $merchantId 商户ID
     * @param array $columns 字段列表
     * @return \Illuminate\Database\Eloquent\Collection<int, PaymentChannel> 启用通道列表
     */
    public function enabledByMerchantId(int $merchantId, array $columns = ['*'])
    {
        return $this->model->newQuery()
            ->where('merchant_id', $merchantId)
            ->where('status', 1)
            ->orderBy('sort_no')
            ->get($columns);
    }

    /**
     * 根据商户 ID 和通道 ID 查询通道。
     *
     * @param int $merchantId 商户ID
     * @param int $channelId 渠道ID
     * @param array $columns 字段列表
     * @return PaymentChannel|null 通道记录
     */
    public function findByMerchantAndId(int $merchantId, int $channelId, array $columns = ['*'])
    {
        return $this->model->newQuery()
            ->where('merchant_id', $merchantId)
            ->whereKey($channelId)
            ->first($columns);
    }

    /**
     * 判断通道名称是否已存在。
     *
     * @param string $name 通道名称
     * @param int $ignoreId 需要排除的记录ID
     * @return bool 是否存在
     */
    public function existsByName(string $name, int $ignoreId = 0): bool
    {
        $query = $this->model->newQuery()
            ->where('name', $name);

        if ($ignoreId > 0) {
            $query->where('id', '<>', $ignoreId);
        }

        return $query->exists();
    }

    /**
     * 判断指定商户的通道名称是否已存在。
     *
     * @param int $merchantId 商户ID
     * @param string $name 通道名称
     * @param int $ignoreId 需要排除的记录ID
     * @return bool 是否存在
     */
    public function existsByMerchantName(int $merchantId, string $name, int $ignoreId = 0): bool
    {
        $query = $this->model->newQuery()
            ->where('merchant_id', $merchantId)
            ->where('name', $name);

        if ($ignoreId > 0) {
            $query->where('id', '<>', $ignoreId);
        }

        return $query->exists();
    }

    /**
     * 统计商户名下的支付通道概览。
     *
     * @param int $merchantId 商户ID
     * @return object{total_count:int, enabled_count:int, self_count:int} 通道统计概览
     */
    public function summaryByMerchantId(int $merchantId): object
    {
        return $this->model->newQuery()
            ->selectRaw('COUNT(*) AS total_count')
            ->selectRaw('SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END) AS enabled_count')
            ->selectRaw('SUM(CASE WHEN channel_mode = 1 THEN 1 ELSE 0 END) AS self_count')
            ->where('merchant_id', $merchantId)
            ->first();
    }

    /**
     * 统计商户下的支付通道数量。
     *
     * @param int $merchantId 商户ID
     * @return int 通道数量
     */
    public function countByMerchantId(int $merchantId): int
    {
        return (int) $this->model->newQuery()
            ->where('merchant_id', $merchantId)
            ->count();
    }

    /**
     * 查询网页流水监听可用通道。
     *
     * @param array<int, string> $pluginCodes 支持监听的插件编码
     * @param array $columns 字段列表
     * @return \Illuminate\Database\Eloquent\Collection<int, PaymentChannel> 通道列表
     */
    public function listReceiptWatcherChannels(array $pluginCodes, array $columns = ['*'])
    {
        $pluginCodes = array_values(array_filter(array_map(static fn ($code): string => trim((string) $code), $pluginCodes)));
        if ($pluginCodes === []) {
            return $this->model->newCollection();
        }

        return $this->model->newQuery()
            ->where('status', CommonConstant::STATUS_ENABLED)
            ->where('api_config_id', '>', 0)
            ->whereIn('plugin_code', $pluginCodes)
            ->orderBy('api_config_id')
            ->orderBy('id')
            ->get($columns);
    }

    /**
     * 查询指定插件配置下的通道 ID。
     *
     * @param string $pluginCode 插件编码
     * @param int $apiConfigId 插件配置ID
     * @return array<int, int> 通道 ID 列表
     */
    public function idsByPluginConfig(string $pluginCode, int $apiConfigId): array
    {
        if (trim($pluginCode) === '' || $apiConfigId <= 0) {
            return [];
        }

        return $this->model->newQuery()
            ->where('plugin_code', $pluginCode)
            ->where('api_config_id', $apiConfigId)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * 根据插件配置和支付方式解析流水对应通道。
     *
     * @param string $pluginCode 插件编码
     * @param int $apiConfigId 插件配置ID
     * @param string $payTypeCode 支付方式编码
     * @return PaymentChannel|null 支付通道
     */
    public function findReceiptFlowChannel(string $pluginCode, int $apiConfigId, string $payTypeCode): ?PaymentChannel
    {
        $pluginCode = trim($pluginCode);
        $payTypeCode = trim($payTypeCode);
        if ($pluginCode === '' || $apiConfigId <= 0) {
            return null;
        }

        $query = $this->model->newQuery()
            ->from('ma_payment_channel as c')
            ->where('c.plugin_code', $pluginCode)
            ->where('c.api_config_id', $apiConfigId)
            ->where('c.status', CommonConstant::STATUS_ENABLED)
            ->orderBy('c.sort_no')
            ->orderBy('c.id');

        if ($payTypeCode !== '') {
            $query->join('ma_payment_type as t', 'c.pay_type_id', '=', 't.id')
                ->where('t.code', $payTypeCode);
        }

        /** @var PaymentChannel|null $channel */
        $channel = $query->first(['c.*']);
        return $channel;
    }
}

