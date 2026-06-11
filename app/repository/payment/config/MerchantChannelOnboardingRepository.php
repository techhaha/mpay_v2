<?php

namespace app\repository\payment\config;

use app\common\base\BaseRepository;
use app\common\constant\OnboardingConstant;
use app\model\payment\MerchantChannelOnboarding;

/**
 * 商户支付渠道进件申请仓库。
 */
class MerchantChannelOnboardingRepository extends BaseRepository
{
    public function __construct()
    {
        parent::__construct(new MerchantChannelOnboarding());
    }

    public function findByNo(string $onboardingNo): ?MerchantChannelOnboarding
    {
        return $this->query()
            ->where('onboarding_no', $onboardingNo)
            ->first();
    }

    public function findActiveByMerchantConfig(int $merchantId, int $configId): ?MerchantChannelOnboarding
    {
        return $this->query()
            ->where('merchant_id', $merchantId)
            ->where('onboarding_config_id', $configId)
            ->whereNotIn('status', OnboardingConstant::terminalStatuses())
            ->orderByDesc('id')
            ->first();
    }
}
