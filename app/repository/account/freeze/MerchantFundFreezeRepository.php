<?php

namespace app\repository\account\freeze;

use app\common\base\BaseRepository;
use app\common\constant\FundFreezeConstant;
use app\model\merchant\MerchantFundFreeze;

/**
 * 商户资金冻结明细仓库。
 *
 * 封装“仍然影响提现/高风险动作”的有效冻结查询，避免各业务点重复拼条件。
 */
class MerchantFundFreezeRepository extends BaseRepository
{
    /**
     * 构造方法。
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct(new MerchantFundFreeze());
    }

    /**
     * 查询指定支付单当前有效冻结。
     *
     * @param string $payNo 支付单号
     * @param string $now 当前时间
     * @param array $columns 字段列表
     * @return MerchantFundFreeze|null 冻结记录
     */
    public function firstActiveByPayNo(string $payNo, string $now, array $columns = ['*'])
    {
        return $this->activeQuery($now)
            ->where('pay_no', $payNo)
            ->orderByDesc('id')
            ->first($columns);
    }

    /**
     * 加锁查询指定支付单当前有效冻结。
     *
     * @param string $payNo 支付单号
     * @param string $now 当前时间
     * @param array $columns 字段列表
     * @return MerchantFundFreeze|null 冻结记录
     */
    public function firstActiveForUpdateByPayNo(string $payNo, string $now, array $columns = ['*'])
    {
        return $this->activeQuery($now)
            ->where('pay_no', $payNo)
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first($columns);
    }

    /**
     * 加锁查询指定支付单和冻结类型的有效冻结。
     *
     * @param string $payNo 支付单号
     * @param int $freezeType 冻结类型
     * @param string $now 当前时间
     * @param array $columns 字段列表
     * @return MerchantFundFreeze|null 冻结记录
     */
    public function firstActiveForUpdateByPayNoAndType(string $payNo, int $freezeType, string $now, array $columns = ['*'])
    {
        return $this->activeQuery($now)
            ->where('pay_no', $payNo)
            ->where('freeze_type', $freezeType)
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first($columns);
    }

    /**
     * 查询指定支付单是否存在有效冻结。
     *
     * @param string $payNo 支付单号
     * @param string $now 当前时间
     * @return bool 是否存在
     */
    public function existsActiveByPayNo(string $payNo, string $now): bool
    {
        return $this->activeQuery($now)
            ->where('pay_no', $payNo)
            ->exists();
    }

    /**
     * 统计商户当前有效冻结金额。
     *
     * @param int $merchantId 商户ID
     * @param string $now 当前时间
     * @return int 有效冻结金额，单位分
     */
    public function sumActiveAmountByMerchant(int $merchantId, string $now): int
    {
        return (int) $this->activeQuery($now)
            ->where('merchant_id', $merchantId)
            ->sum('remaining_amount');
    }

    /**
     * 有效冻结查询基础条件。
     *
     * 到期时间只表示“允许释放的最早时间”，未释放前仍计入账户冻结余额。
     *
     * @param string $now 当前时间
     * @return \Illuminate\Database\Eloquent\Builder 查询构造器
     */
    public function activeQuery(string $now)
    {
        return $this->query()
            ->where('status', FundFreezeConstant::STATUS_ACTIVE)
            ->where('remaining_amount', '>', 0);
    }
}
