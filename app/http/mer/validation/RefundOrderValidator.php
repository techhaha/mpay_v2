<?php

namespace app\http\mer\validation;

use support\validation\Validator;

/**
 * 退款订单列表参数校验器。
 *
 * 仅供商户后台使用。
 */
class RefundOrderValidator extends Validator
{
    /**
     * 校验规则
     *
     * @var array
     */
    protected array $rules = [
        'search_field' => 'sometimes|string|in:refund_no,pay_no,biz_no,trace_no,merchant_order_no,merchant_refund_no,channel_request_no,channel_refund_no',
        'keyword' => 'sometimes|string|max:128',
        'merchant_id' => 'sometimes|integer|min:1',
        'pay_type_id' => 'sometimes|integer|min:1',
        'status' => 'sometimes|integer|in:0,1,2,3,4',
        'channel_mode' => 'sometimes|integer|in:0,1',
        'page' => 'sometimes|integer|min:1',
        'page_size' => 'sometimes|integer|min:1|max:100',
    ];

    /**
     * 字段别名
     *
     * @var array
     */
    protected array $attributes = [
        'search_field' => '搜索字段',
        'keyword' => '关键字',
        'merchant_id' => '商户ID',
        'pay_type_id' => '支付方式',
        'status' => '退款状态',
        'channel_mode' => '通道模式',
        'page' => '页码',
        'page_size' => '每页条数',
    ];

    /**
     * 校验场景
     *
     * @var array
     */
    protected array $scenes = [
        'index' => ['search_field', 'keyword', 'merchant_id', 'pay_type_id', 'status', 'channel_mode', 'page', 'page_size'],
    ];
}

