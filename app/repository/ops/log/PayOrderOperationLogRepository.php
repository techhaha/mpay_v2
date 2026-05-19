<?php

namespace app\repository\ops\log;

use app\common\base\BaseRepository;
use app\model\admin\PayOrderOperationLog;

/**
 * 支付订单后台操作日志仓库。
 */
class PayOrderOperationLogRepository extends BaseRepository
{
    /**
     * 构造方法。
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct(new PayOrderOperationLog());
    }

    /**
     * 查询指定支付单的后台操作日志。
     *
     * @param string $payNo 支付单号
     * @param array $columns 字段列表
     * @return \Illuminate\Database\Eloquent\Collection<int, PayOrderOperationLog>
     */
    public function listByPayNo(string $payNo, array $columns = ['*'])
    {
        return $this->model->newQuery()
            ->where('pay_no', $payNo)
            ->orderByDesc('id')
            ->get($columns);
    }
}
