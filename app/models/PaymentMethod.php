<?php

namespace app\models;

use app\common\base\BaseModel;

/**
 * 支付方式模型
 *
 * 对应表：ma_pay_method
 */
class PaymentMethod extends BaseModel
{
    protected $table = 'ma_pay_type';

    protected $fillable = [
        'type',
        'name',
        'icon',
        'sort',
        'status',
    ];

    public $timestamps = true;

    protected $appends = ['method_code', 'method_name'];

    protected $casts = [
        'sort' => 'integer',
        'status' => 'integer',
    ];

    public function getMethodCodeAttribute()
    {
        return $this->attributes['type'] ?? null;
    }

    public function setMethodCodeAttribute($value): void
    {
        $this->attributes['type'] = (string)$value;
    }

    public function getMethodNameAttribute()
    {
        return $this->attributes['name'] ?? null;
    }

    public function setMethodNameAttribute($value): void
    {
        $this->attributes['name'] = (string)$value;
    }
}
