<?php

namespace app\http\admin\validation;

use support\validation\Validator;

/**
 * 支付订单列表参数校验器。
 *
 * 仅供管理后台使用。
 */
class PayOrderValidator extends Validator
{
    /**
     * 校验规则
     *
     * @var array
     */
    protected array $rules = [
        'keyword' => 'sometimes|string|max:128',
        'merchant_id' => 'sometimes|integer|min:1',
        'pay_type_id' => 'sometimes|integer|min:1',
        'status' => 'sometimes|integer|in:0,1,2,3,4,5',
        'channel_mode' => 'sometimes|integer|in:0,1',
        'callback_status' => 'sometimes|integer|in:0,1,2',
        'page' => 'sometimes|integer|min:1',
        'page_size' => 'sometimes|integer|min:1|max:100',
    ];

    /**
     * 字段别名
     *
     * @var array
     */
    protected array $attributes = [
        'keyword' => '关键字',
        'merchant_id' => '商户ID',
        'pay_type_id' => '支付方式',
        'status' => '支付单状态',
        'channel_mode' => '通道模式',
        'callback_status' => '回调处理状态',
        'page' => '页码',
        'page_size' => '每页条数',
    ];

    /**
     * 校验场景
     *
     * @var array
     */
    protected array $scenes = [
        'index' => ['keyword', 'merchant_id', 'pay_type_id', 'status', 'channel_mode', 'callback_status', 'page', 'page_size'],
    ];
}

