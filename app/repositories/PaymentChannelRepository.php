<?php

namespace app\repositories;

use app\common\base\BaseRepository;
use app\models\PaymentChannel;

/**
 * 支付通道仓储
 */
class PaymentChannelRepository extends BaseRepository
{
    public function __construct()
    {
        parent::__construct(new PaymentChannel());
    }

    /**
     * 根据商户、应用、支付方式查找可用通道
     */
    public function findAvailableChannel(int $merchantId, int $merchantAppId, int $methodId): ?PaymentChannel
    {
        return $this->model->newQuery()
            ->where('merchant_id', $merchantId)
            ->where('merchant_app_id', $merchantAppId)
            ->where('method_id', $methodId)
            ->where('status', 1)
            ->orderBy('sort', 'asc')
            ->first();
    }

    public function findByChanCode(string $chanCode): ?PaymentChannel
    {
        return $this->model->newQuery()
            ->where('chan_code', $chanCode)
            ->first();
    }
}
