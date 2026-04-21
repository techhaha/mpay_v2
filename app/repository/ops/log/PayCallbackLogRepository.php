<?php

namespace app\repository\ops\log;

use app\common\base\BaseRepository;
use app\model\admin\PayCallbackLog;

/**
 * 支付回调日志仓库。
 *
 * 封装按支付单号查询回调日志列表。
 */
class PayCallbackLogRepository extends BaseRepository
{
    /**
     * 构造方法。
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct(new PayCallbackLog());
    }

    /**
     * 根据支付单号查询回调日志列表。
     *
     * @param string $payNo 支付单号
     * @param array $columns 字段列表
     * @return \Illuminate\Database\Eloquent\Collection<int, PayCallbackLog> 回调日志列表
     */
    public function listByPayNo(string $payNo, array $columns = ['*'])
    {
        return $this->model->newQuery()
            ->where('pay_no', $payNo)
            ->orderByDesc('id')
            ->get($columns);
    }
}






