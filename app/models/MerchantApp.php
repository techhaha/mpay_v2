<?php

namespace app\models;

use app\common\base\BaseModel;

/**
 * 商户应用模型
 */
class MerchantApp extends BaseModel
{
    protected $table = 'ma_merchant_app';
    
    protected $fillable = [
        'merchant_id',
        'api_type',
        'app_id',
        'app_secret',
        'app_name',
        'status',
    ];
    
    public $timestamps = true;
    
    protected $casts = [
        'merchant_id' => 'integer',
        'status' => 'integer',
    ];
}

