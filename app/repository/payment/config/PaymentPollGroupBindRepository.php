<?php

namespace app\repository\payment\config;

use app\common\base\BaseRepository;
use app\model\payment\PaymentPollGroupBind;

/**
 * 商户分组与轮询组绑定仓库。
 *
 * 封装路由绑定的启用记录与编排展示查询。
 */
class PaymentPollGroupBindRepository extends BaseRepository
{
    /**
     * 构造方法。
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct(new PaymentPollGroupBind());
    }

    /**
     * 根据商户分组和支付方式查询启用的绑定关系。
     *
     * @param int $merchantGroupId 商户分组ID
     * @param int $payTypeId 支付类型ID
     * @param array $columns 字段列表
     * @return PaymentPollGroupBind|null 绑定记录
     */
    public function findActiveByMerchantGroupAndPayType(int $merchantGroupId, int $payTypeId, array $columns = ['*'])
    {
        return $this->model->newQuery()
            ->where('merchant_group_id', $merchantGroupId)
            ->where('pay_type_id', $payTypeId)
            ->where('status', 1)
            ->first($columns);
    }

    /**
     * 查询商户分组下的路由绑定概览。
     *
     * @param int $merchantGroupId 商户分组ID
     * @return \Illuminate\Database\Eloquent\Collection<int, PaymentPollGroupBind> 绑定概览列表
     */
    public function listSummaryByMerchantGroupId(int $merchantGroupId)
    {
        return $this->model->newQuery()
            ->from('ma_payment_poll_group_bind as b')
            ->leftJoin('ma_payment_type as t', 'b.pay_type_id', '=', 't.id')
            ->leftJoin('ma_payment_poll_group as p', 'b.poll_group_id', '=', 'p.id')
            ->where('b.merchant_group_id', $merchantGroupId)
            ->orderBy('b.id')
            ->get([
                'b.id',
                'b.pay_type_id',
                'b.poll_group_id',
                'b.status',
                'b.remark',
                't.code as pay_type_code',
                't.name as pay_type_name',
                't.icon as pay_type_icon',
                'p.group_name as poll_group_name',
                'p.route_mode',
            ]);
    }
}




