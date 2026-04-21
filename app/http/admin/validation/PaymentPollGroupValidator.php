<?php

namespace app\http\admin\validation;

use support\validation\Validator;

/**
 * 支付轮询组参数校验器。
 *
 * 用于校验轮询组的查询和增删改参数。
 */
class PaymentPollGroupValidator extends Validator
{
    /**
     * 校验规则
     *
     * @var array
     */
    protected array $rules = [
        'id' => 'sometimes|integer|min:1',
        'keyword' => 'sometimes|string|max:128',
        'group_name' => 'sometimes|string|min:2|max:128',
        'pay_type_id' => 'sometimes|integer|min:1|exists:ma_payment_type,id',
        'route_mode' => 'sometimes|integer|in:0,1,2',
        'status' => 'sometimes|integer|in:0,1',
        'remark' => 'nullable|string|max:255',
        'page' => 'sometimes|integer|min:1',
        'page_size' => 'sometimes|integer|min:1|max:100',
    ];

    /**
     * 字段别名
     *
     * @var array
     */
    protected array $attributes = [
        'id' => '轮询组ID',
        'keyword' => '关键字',
        'group_name' => '轮询组名称',
        'pay_type_id' => '支付方式',
        'route_mode' => '路由模式',
        'status' => '轮询组状态',
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
        'index' => ['keyword', 'group_name', 'pay_type_id', 'route_mode', 'status', 'page', 'page_size'],
        'store' => ['group_name', 'pay_type_id', 'route_mode', 'status', 'remark'],
        'update' => ['id', 'group_name', 'pay_type_id', 'route_mode', 'status', 'remark'],
        'updateStatus' => ['id', 'status'],
        'show' => ['id'],
        'destroy' => ['id'],
    ];

    /**
     * 根据场景返回支付轮询组校验规则。
     *
     * @return array 校验规则
     */
    public function rules(): array
    {
        $rules = parent::rules();

        return match ($this->scene()) {
            'store' => array_merge($rules, [
                'group_name' => 'required|string|min:2|max:128',
                'pay_type_id' => 'required|integer|min:1|exists:ma_payment_type,id',
                'route_mode' => 'required|integer|in:0,1,2',
                'status' => 'required|integer|in:0,1',
            ]),
            'update' => array_merge($rules, [
                'id' => 'required|integer|min:1',
                'group_name' => 'required|string|min:2|max:128',
                'pay_type_id' => 'required|integer|min:1|exists:ma_payment_type,id',
                'route_mode' => 'required|integer|in:0,1,2',
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



