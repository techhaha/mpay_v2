<?php

namespace app\http\admin\validation;

use support\validation\Validator;

/**
 * 商户分组路由绑定参数校验器。
 *
 * 用于校验商户分组在支付方式下绑定轮询组的参数。
 */
class PaymentPollGroupBindValidator extends Validator
{
    /**
     * 校验规则
     *
     * @var array
     */
    protected array $rules = [
        'id' => 'sometimes|integer|min:1',
        'keyword' => 'sometimes|string|max:128',
        'merchant_group_id' => 'sometimes|integer|min:1|exists:ma_merchant_group,id',
        'pay_type_id' => 'sometimes|integer|min:1|exists:ma_payment_type,id',
        'poll_group_id' => 'sometimes|integer|min:1|exists:ma_payment_poll_group,id',
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
        'id' => '绑定ID',
        'keyword' => '关键字',
        'merchant_group_id' => '商户分组',
        'pay_type_id' => '支付方式',
        'poll_group_id' => '轮询组',
        'status' => '分组绑定状态',
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
        'index' => ['keyword', 'merchant_group_id', 'pay_type_id', 'poll_group_id', 'status', 'page', 'page_size'],
        'store' => ['merchant_group_id', 'pay_type_id', 'poll_group_id', 'status', 'remark'],
        'update' => ['id', 'merchant_group_id', 'pay_type_id', 'poll_group_id', 'status', 'remark'],
        'show' => ['id'],
        'destroy' => ['id'],
    ];

    /**
     * 根据场景返回轮询组绑定校验规则。
     *
     * @return array 校验规则
     */
    public function rules(): array
    {
        $rules = parent::rules();

        return match ($this->scene()) {
            'store' => array_merge($rules, [
                'merchant_group_id' => 'required|integer|min:1|exists:ma_merchant_group,id',
                'pay_type_id' => 'required|integer|min:1|exists:ma_payment_type,id',
                'poll_group_id' => 'required|integer|min:1|exists:ma_payment_poll_group,id',
            ]),
            'update' => array_merge($rules, [
                'id' => 'required|integer|min:1',
                'merchant_group_id' => 'required|integer|min:1|exists:ma_merchant_group,id',
                'pay_type_id' => 'required|integer|min:1|exists:ma_payment_type,id',
                'poll_group_id' => 'required|integer|min:1|exists:ma_payment_poll_group,id',
            ]),
            'show', 'destroy' => array_merge($rules, [
                'id' => 'required|integer|min:1',
            ]),
            default => $rules,
        };
    }
}
