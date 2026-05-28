<?php

namespace app\http\admin\validation;

use support\validation\Validator;

/**
 * 轮询组通道编排参数校验器。
 *
 * 用于校验轮询组与通道关系的查询和增删改参数。
 */
class PaymentPollGroupChannelValidator extends Validator
{
    /**
     * 校验规则
     *
     * @var array
     */
    protected array $rules = [
        'id' => 'sometimes|integer|min:1',
        'keyword' => 'sometimes|string|max:128',
        'poll_group_id' => 'sometimes|integer|min:1|exists:ma_payment_poll_group,id',
        'channel_id' => 'sometimes|integer|min:1|exists:ma_payment_channel,id',
        'status' => 'sometimes|integer|in:0,1',
        'sort_no' => 'nullable|integer|min:0',
        'weight' => 'nullable|integer|min:1',
        'is_default' => 'sometimes|integer|in:0,1',
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
        'id' => '编排ID',
        'keyword' => '关键字',
        'poll_group_id' => '轮询组',
        'channel_id' => '支付通道',
        'status' => '通道编排状态',
        'sort_no' => '排序',
        'weight' => '权重',
        'is_default' => '默认通道',
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
        'index' => ['keyword', 'poll_group_id', 'channel_id', 'status', 'page', 'page_size'],
        'store' => ['poll_group_id', 'channel_id', 'sort_no', 'weight', 'is_default', 'status', 'remark'],
        'update' => ['id', 'poll_group_id', 'channel_id', 'sort_no', 'weight', 'is_default', 'status', 'remark'],
        'updateStatus' => ['id', 'status'],
        'show' => ['id'],
        'destroy' => ['id'],
    ];

    /**
     * 根据场景返回轮询组通道校验规则。
     *
     * @return array 校验规则
     */
    public function rules(): array
    {
        $rules = parent::rules();

        return match ($this->scene()) {
            'store' => array_merge($rules, [
                'poll_group_id' => 'required|integer|min:1|exists:ma_payment_poll_group,id',
                'channel_id' => 'required|integer|min:1|exists:ma_payment_channel,id',
            ]),
            'update' => array_merge($rules, [
                'id' => 'required|integer|min:1',
                'poll_group_id' => 'required|integer|min:1|exists:ma_payment_poll_group,id',
                'channel_id' => 'required|integer|min:1|exists:ma_payment_channel,id',
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
