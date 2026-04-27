<?php

namespace app\http\admin\validation;

use support\validation\Validator;

/**
 * 商户通知任务参数校验器。
 */
class MerchantNotifyTaskValidator extends Validator
{
    /**
     * 校验规则。
     *
     * @var array
     */
    protected array $rules = [
        'notify_no' => 'sometimes|string|max:64',
        'keyword' => 'sometimes|string|max:128',
        'merchant_id' => 'sometimes|integer|min:1',
        'status' => 'sometimes|integer|in:0,1,2',
        'page' => 'sometimes|integer|min:1',
        'page_size' => 'sometimes|integer|min:1|max:100',
    ];

    /**
     * 字段别名。
     *
     * @var array
     */
    protected array $attributes = [
        'notify_no' => '通知号',
        'keyword' => '关键词',
        'merchant_id' => '所属商户',
        'status' => '任务状态',
        'page' => '页码',
        'page_size' => '每页条数',
    ];

    /**
     * 校验场景。
     *
     * @var array
     */
    protected array $scenes = [
        'index' => ['keyword', 'merchant_id', 'status', 'page', 'page_size'],
        'show' => ['notify_no'],
        'retry' => ['notify_no'],
    ];

    /**
     * 配置详情场景规则。
     *
     * @return static
     */
    public function sceneShow(): static
    {
        return $this->appendRules([
            'notify_no' => 'required|string|max:64',
        ]);
    }

    /**
     * 配置重试场景规则。
     *
     * @return static
     */
    public function sceneRetry(): static
    {
        return $this->sceneShow();
    }
}
