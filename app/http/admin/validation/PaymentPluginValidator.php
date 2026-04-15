<?php

namespace app\http\admin\validation;

use support\validation\Validator;

/**
 * 支付插件参数校验器。
 *
 * 用于校验支付插件的查询、详情和状态备注更新参数。
 */
class PaymentPluginValidator extends Validator
{
    protected array $rules = [
        'code' => 'sometimes|string|alpha_dash|min:2|max:32',
        'status' => 'sometimes|integer|in:0,1',
        'remark' => 'nullable|string|max:500',
        'keyword' => 'sometimes|string|max:128',
        'name' => 'sometimes|string|max:50',
        'page' => 'sometimes|integer|min:1',
        'page_size' => 'sometimes|integer|min:1|max:100',
        'pay_type_code' => 'sometimes|string|max:32',
        'ids' => 'sometimes|array',
    ];

    protected array $attributes = [
        'code' => '插件编码',
        'name' => '插件名称',
        'status' => '状态',
        'remark' => '备注',
        'keyword' => '关键字',
        'page' => '页码',
        'page_size' => '每页条数',
        'pay_type_code' => '支付方式编码',
        'ids' => '插件编码集合',
    ];

    protected array $scenes = [
        'index' => ['keyword', 'code', 'name', 'status', 'page', 'page_size'],
        'update' => ['code', 'status', 'remark'],
        'updateStatus' => ['code', 'status'],
        'show' => ['code'],
        'selectOptions' => ['keyword', 'page', 'page_size', 'pay_type_code', 'ids'],
    ];
}
