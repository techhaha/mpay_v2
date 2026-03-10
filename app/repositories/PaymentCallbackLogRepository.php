<?php

namespace app\repositories;

use app\common\base\BaseRepository;
use app\models\PaymentCallbackLog;

/**
 * 支付回调日志仓储
 */
class PaymentCallbackLogRepository extends BaseRepository
{
    public function __construct()
    {
        parent::__construct(new PaymentCallbackLog());
    }

    public function createLog(array $data): PaymentCallbackLog
    {
        return $this->model->newQuery()->create($data);
    }
}
