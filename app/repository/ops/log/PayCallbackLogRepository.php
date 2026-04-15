<?php

namespace app\repository\ops\log;

use app\common\base\BaseRepository;
use app\model\admin\PayCallbackLog;

/**
 * 支付回调日志仓库。
 */
class PayCallbackLogRepository extends BaseRepository
{
    /**
     * 构造函数，注入对应模型。
     */
    public function __construct()
    {
        parent::__construct(new PayCallbackLog());
    }

    /**
     * 根据支付单号查询回调日志列表。
     */
    public function listByPayNo(string $payNo, array $columns = ['*'])
    {
        return $this->model->newQuery()
            ->where('pay_no', $payNo)
            ->orderByDesc('id')
            ->get($columns);
    }
}


