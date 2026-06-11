<?php

namespace app\repository\payment\config;

use app\common\base\BaseRepository;
use app\model\payment\PaymentPluginOnboardingConf;

/**
 * 支付插件进件配置仓库。
 */
class PaymentPluginOnboardingConfRepository extends BaseRepository
{
    public function __construct()
    {
        parent::__construct(new PaymentPluginOnboardingConf());
    }

    public function findEnabled(int $id): ?PaymentPluginOnboardingConf
    {
        return $this->query()
            ->whereKey($id)
            ->where('status', 1)
            ->first();
    }

    public function findMerchantVisible(int $id): ?PaymentPluginOnboardingConf
    {
        return $this->query()
            ->whereKey($id)
            ->where('status', 1)
            ->where('merchant_visible', 1)
            ->first();
    }
}
