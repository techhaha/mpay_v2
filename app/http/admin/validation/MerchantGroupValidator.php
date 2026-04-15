<?php

namespace app\http\admin\validation;

use support\validation\Validator;

/**
 * 商户分组参数校验器。
 *
 * 用于校验商户分组的查询和增删改参数。
 */
class MerchantGroupValidator extends Validator
{
    protected array $rules = [
        'id' => 'sometimes|integer|min:1',
        'keyword' => 'sometimes|string|max:128',
        'group_name' => 'sometimes|string|min:2|max:128',
        'status' => 'sometimes|integer|in:0,1',
        'remark' => 'nullable|string|max:255',
        'page' => 'sometimes|integer|min:1',
        'page_size' => 'sometimes|integer|min:1|max:100',
    ];

    protected array $attributes = [
        'id' => '分组ID',
        'keyword' => '关键字',
        'group_name' => '分组名称',
        'status' => '状态',
        'remark' => '备注',
        'page' => '页码',
        'page_size' => '每页条数',
    ];

    protected array $scenes = [
        'index' => ['keyword', 'group_name', 'status', 'page', 'page_size'],
        'store' => ['group_name', 'status', 'remark'],
        'update' => ['id', 'group_name', 'status', 'remark'],
        'show' => ['id'],
        'destroy' => ['id'],
    ];

    public function sceneStore(): static
    {
        return $this->appendRules([
            'group_name' => 'required|string|min:2|max:128',
            'status' => 'required|integer|in:0,1',
        ]);
    }

    public function sceneUpdate(): static
    {
        return $this->appendRules([
            'id' => 'required|integer|min:1',
            'group_name' => 'required|string|min:2|max:128',
            'status' => 'required|integer|in:0,1',
        ]);
    }

    public function sceneShow(): static
    {
        return $this->appendRules([
            'id' => 'required|integer|min:1',
        ]);
    }

    public function sceneDestroy(): static
    {
        return $this->sceneShow();
    }
}
