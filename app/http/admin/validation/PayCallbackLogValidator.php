<?php

namespace app\http\admin\validation;

use support\validation\Validator;

/**
 * 支付回调日志参数校验器。
 */
class PayCallbackLogValidator extends Validator
{
    /**
     * 校验规则
     *
     * @var array
     */
    protected array $rules = [
        'id' => 'required|integer|min:1',
        'keyword' => 'sometimes|string|max:128',
        'merchant_id' => 'sometimes|integer|min:1',
        'channel_id' => 'sometimes|integer|min:1',
        'callback_type' => 'sometimes|integer|in:0,1',
        'verify_status' => 'sometimes|integer|in:0,1,2',
        'process_status' => 'sometimes|integer|in:0,1,2',
        'page' => 'sometimes|integer|min:1',
        'page_size' => 'sometimes|integer|min:1|max:100',
    ];

    /**
     * 字段别名
     *
     * @var array
     */
    protected array $attributes = [
        'id' => '日志ID',
        'keyword' => '关键词',
        'merchant_id' => '所属商户',
        'channel_id' => '所属通道',
        'callback_type' => '回调类型',
        'verify_status' => '验签状态',
        'process_status' => '处理状态',
        'page' => '页码',
        'page_size' => '每页条数',
    ];

    /**
     * 校验场景
     *
     * @var array
     */
    protected array $scenes = [
        'index' => ['keyword', 'merchant_id', 'channel_id', 'callback_type', 'verify_status', 'process_status', 'page', 'page_size'],
        'show' => ['id'],
    ];
}


