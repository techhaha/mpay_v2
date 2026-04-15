<?php

namespace app\repository\payment\config;

use app\common\base\BaseRepository;
use app\model\payment\PaymentChannel;

/**
 * 支付通道仓库。
 */
class PaymentChannelRepository extends BaseRepository
{
    /**
     * 构造函数，注入对应模型。
     */
    public function __construct()
    {
        parent::__construct(new PaymentChannel());
    }

    /**
     * 查询指定商户启用的支付通道。
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
     * 统计商户名下的支付通道概览。
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
     */
    public function countByMerchantId(int $merchantId): int
    {
        return (int) $this->model->newQuery()
            ->where('merchant_id', $merchantId)
            ->count();
    }
}
