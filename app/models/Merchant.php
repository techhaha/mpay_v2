<?php

namespace app\models;

use app\common\base\BaseModel;

/**
 * 商户模型
 */
class Merchant extends BaseModel
{
    protected $table = 'ma_merchant';
    
    protected $fillable = [
        'merchant_no',
        'merchant_name',
        'funds_mode',
        'status',
    ];
    
    public $timestamps = true;
    
    protected $casts = [
        'status' => 'integer',
    ];
}

