<?php

namespace app\models;

use app\common\base\BaseModel;

/**
 * 商户模型
 */
class Merchant extends BaseModel
{
    protected $table = 'ma_mer';
    
    protected $fillable = [
        'merchant_no',
        'merchant_name',
        'balance',
        'email',
        'funds_mode',
        'status',
        'remark',
        'extra',
    ];
    
    public $timestamps = true;
    
    protected $casts = [
        'balance' => 'decimal:2',
        'status' => 'integer',
        'extra' => 'array',
    ];
}

