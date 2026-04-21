<?php

namespace app\http\admin\validation;

use support\validation\Validator;

/**
 * 支付方式参数校验器。
 *
 * 用于校验支付方式列表查询和增删改参数。
 */
class PaymentTypeValidator extends Validator
{
    /**
     * 校验规则
     *
     * @var array
     */
    protected array $rules = [
        'id' => 'sometimes|integer|min:1',
        'keyword' => 'sometimes|string|max:128',
        'code' => 'sometimes|string|alpha_dash|min:2|max:32',
        'name' => 'sometimes|string|min:2|max:50',
        'icon' => 'nullable|string|max:255',
        'sort_no' => 'nullable|integer|min:0',
        'status' => 'sometimes|integer|in:0,1',
        'remark' => 'nullable|string|max:500',
        'page' => 'sometimes|integer|min:1',
        'page_size' => 'sometimes|integer|min:1|max:100',
    ];

    /**
     * 字段别名
     *
     * @var array
     */
    protected array $attributes = [
        'id' => '支付方式ID',
        'keyword' => '关键字',
        'code' => '支付方式编码',
        'name' => '支付方式名称',
        'icon' => '图标',
        'sort_no' => '排序',
        'status' => '支付方式状态',
        'remark' => '备注',
        'page' => '页码',
        'page_size' => '每页条数',
    ];

    /**
     * 校验场景
     *
     * @var array
     */
    protected array $scenes = [
        'index' => ['keyword', 'code', 'name', 'status', 'page', 'page_size'],
        'store' => ['code', 'name', 'icon', 'sort_no', 'status', 'remark'],
        'update' => ['id', 'code', 'name', 'icon', 'sort_no', 'status', 'remark'],
        'updateStatus' => ['id', 'status'],
        'show' => ['id'],
        'destroy' => ['id'],
    ];

    /**
     * 根据场景返回支付类型校验规则。
     *
     * @return array 校验规则
     */
    public function rules(): array
    {
        $rules = parent::rules();

        return match ($this->scene()) {
            'store' => array_merge($rules, [
                'code' => 'required|string|alpha_dash|min:2|max:32',
                'name' => 'required|string|min:2|max:50',
                'status' => 'required|integer|in:0,1',
            ]),
            'update' => array_merge($rules, [
                'id' => 'required|integer|min:1',
                'code' => 'required|string|alpha_dash|min:2|max:32',
                'name' => 'required|string|min:2|max:50',
                'status' => 'required|integer|in:0,1',
            ]),
            'updateStatus' => array_merge($rules, [
                'id' => 'required|integer|min:1',
                'status' => 'required|integer|in:0,1',
            ]),
            'show', 'destroy' => array_merge($rules, [
                'id' => 'required|integer|min:1',
            ]),
            default => $rules,
        };
    }
}

