<?php

namespace app\model\merchant;

use app\common\base\BaseModel;

/**
 * 商户对外接口凭证模型。
 * 保存商户 API 凭证、商户公钥、启用状态和最近使用时间。
 */
class MerchantApiCredential extends BaseModel
{
    /**
     * 数据表名
     *
     * @var mixed
     */
    protected $table = 'ma_merchant_api_credential';

    /**
     * 可批量赋值字段
     *
     * @var mixed
     */
    protected $fillable = [
        'merchant_id',
        'api_key',
        'merchant_public_key',
        'status',
        'last_used_at',
    ];

    /**
     * 隐藏字段
     *
     * @var mixed
     */
    protected $hidden = [
        'api_key',
    ];

    /**
     * 字段类型转换配置
     *
     * @var mixed
     */
    protected $casts = [
        'merchant_id' => 'integer',
        'status' => 'integer',
        'last_used_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
