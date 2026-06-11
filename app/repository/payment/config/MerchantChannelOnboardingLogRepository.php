<?php

namespace app\repository\payment\config;

use app\common\base\BaseRepository;
use app\model\payment\MerchantChannelOnboardingLog;

/**
 * 商户支付渠道进件日志仓库。
 */
class MerchantChannelOnboardingLogRepository extends BaseRepository
{
    public function __construct()
    {
        parent::__construct(new MerchantChannelOnboardingLog());
    }
}
