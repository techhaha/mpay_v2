<?php

namespace app\http\admin\validation;

use support\validation\Validator;

/**
 * 商户账户流水参数校验器。
 */
class MerchantAccountLedgerValidator extends Validator
{
    protected array $rules = [
        'id' => 'required|integer|min:1',
        'keyword' => 'sometimes|string|max:128',
        'merchant_id' => 'sometimes|integer|min:1',
        'biz_type' => 'sometimes|integer|min:0',
        'event_type' => 'sometimes|integer|min:0',
        'direction' => 'sometimes|integer|in:0,1',
        'page' => 'sometimes|integer|min:1',
        'page_size' => 'sometimes|integer|min:1|max:100',
    ];

    protected array $attributes = [
        'id' => '流水ID',
        'keyword' => '关键词',
        'merchant_id' => '所属商户',
        'biz_type' => '业务类型',
        'event_type' => '事件类型',
        'direction' => '方向',
        'page' => '页码',
        'page_size' => '每页条数',
    ];

    protected array $scenes = [
        'index' => ['keyword', 'merchant_id', 'biz_type', 'event_type', 'direction', 'page', 'page_size'],
        'show' => ['id'],
    ];
}
