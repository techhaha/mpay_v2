<?php

namespace app\http\admin\validation;

use support\validation\Validator;

/**
 * 商户资金冻结明细参数校验器。
 */
class MerchantFundFreezeValidator extends Validator
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
        'freeze_type' => 'sometimes|integer|in:1,2,3',
        'status' => 'sometimes|integer|in:1,2',
        'page' => 'sometimes|integer|min:1',
        'page_size' => 'sometimes|integer|min:1|max:100',
    ];

    /**
     * 字段别名
     *
     * @var array
     */
    protected array $attributes = [
        'id' => '冻结明细ID',
        'keyword' => '关键词',
        'merchant_id' => '所属商户',
        'freeze_type' => '冻结类型',
        'status' => '状态',
        'page' => '页码',
        'page_size' => '每页条数',
    ];

    /**
     * 校验场景
     *
     * @var array
     */
    protected array $scenes = [
        'index' => ['keyword', 'merchant_id', 'freeze_type', 'status', 'page', 'page_size'],
        'show' => ['id'],
    ];
}
