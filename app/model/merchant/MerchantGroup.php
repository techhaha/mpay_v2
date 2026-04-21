<?php

namespace app\model\merchant;

use app\common\base\BaseModel;

/**
 * 商户分组模型。
 * 用于路由编排、策略绑定和通道分配。
 */
class MerchantGroup extends BaseModel
{
    /**
     * 数据表名
     *
     * @var mixed
     */
    protected $table = 'ma_merchant_group';

    /**
     * 可批量赋值字段
     *
     * @var mixed
     */
    protected $fillable = [
        'group_name',
        'status',
        'remark',
    ];

    /**
     * 字段类型转换配置
     *
     * @var mixed
     */
    protected $casts = [
        'status' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}



