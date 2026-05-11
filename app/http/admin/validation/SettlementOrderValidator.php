<?php

namespace app\http\admin\validation;

use support\validation\Validator;

/**
 * 清算订单参数校验器。
 */
class SettlementOrderValidator extends Validator
{
    /**
     * 校验规则
     *
     * @var array
     */
    protected array $rules = [
        'settle_no' => 'required|string|max:32',
        'keyword' => 'sometimes|string|max:128',
        'merchant_id' => 'sometimes|integer|min:1',
        'channel_id' => 'sometimes|integer|min:1',
        'status' => 'sometimes|integer|min:0',
        'cycle_type' => 'sometimes|integer|min:0',
        'reason' => 'sometimes|string|max:255',
        'page' => 'sometimes|integer|min:1',
        'page_size' => 'sometimes|integer|min:1|max:100',
    ];

    /**
     * 字段别名
     *
     * @var array
     */
    protected array $attributes = [
        'settle_no' => '清算单号',
        'keyword' => '关键词',
        'merchant_id' => '所属商户',
        'channel_id' => '所属通道',
        'status' => '清算单状态',
        'cycle_type' => '结算周期类型',
        'reason' => '失败原因',
        'page' => '页码',
        'page_size' => '每页条数',
    ];

    /**
     * 校验场景
     *
     * @var array
     */
    protected array $scenes = [
        'index' => ['keyword', 'merchant_id', 'channel_id', 'status', 'cycle_type', 'page', 'page_size'],
        'show' => ['settle_no'],
        'fail' => ['settle_no', 'reason'],
    ];
}
