<?php

namespace app\model\merchant;

use app\common\base\BaseModel;

/**
 * 商户分组模型。
 * 用于路由编排、策略绑定和通道分配。
 */
class MerchantGroup extends BaseModel
{
    protected $table = 'ma_merchant_group';

    protected $fillable = [
        'group_name',
        'status',
        'remark',
    ];

    protected $casts = [
        'status' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}

