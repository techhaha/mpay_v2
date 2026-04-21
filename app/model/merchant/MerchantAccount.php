<?php

namespace app\model\merchant;

use app\common\base\BaseModel;

/**
 * 商户余额账户模型。
 * 仅保存可提现余额、冻结余额和时间戳。
 */
class MerchantAccount extends BaseModel
{
    /**
     * 数据表名
     *
     * @var mixed
     */
    protected $table = 'ma_merchant_account';

    /**
     * 可批量赋值字段
     *
     * @var mixed
     */
    protected $fillable = [
        'merchant_id',
        'available_balance',
        'frozen_balance',
    ];

    /**
     * 字段类型转换配置
     *
     * @var mixed
     */
    protected $casts = [
        'merchant_id' => 'integer',
        'available_balance' => 'integer',
        'frozen_balance' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}


