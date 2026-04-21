<?php

namespace app\http\admin\validation;

use support\validation\Validator;

/**
 * 商户 API 凭证参数校验器。
 */
class MerchantApiCredentialValidator extends Validator
{
    /**
     * 校验规则
     *
     * @var array
     */
    protected array $rules = [
        'id' => 'sometimes|integer|min:1',
        'keyword' => 'sometimes|string|max:128',
        'merchant_id' => 'sometimes|integer|min:1|exists:ma_merchant,id',
        'sign_type' => 'sometimes|integer|in:0',
        'api_key' => 'nullable|string|max:128',
        'status' => 'sometimes|integer|in:0,1',
        'page' => 'sometimes|integer|min:1',
        'page_size' => 'sometimes|integer|min:1|max:100',
    ];

    /**
     * 字段别名
     *
     * @var array
     */
    protected array $attributes = [
        'id' => '凭证ID',
        'keyword' => '关键词',
        'merchant_id' => '所属商户',
        'sign_type' => '签名类型',
        'api_key' => '接口凭证值',
        'status' => '接口凭证状态',
        'page' => '页码',
        'page_size' => '每页条数',
    ];

    /**
     * 校验场景
     *
     * @var array
     */
    protected array $scenes = [
        'index' => ['keyword', 'merchant_id', 'status', 'page', 'page_size'],
        'store' => ['merchant_id', 'sign_type', 'api_key', 'status'],
        'update' => ['id', 'sign_type', 'api_key', 'status'],
        'show' => ['id'],
        'destroy' => ['id'],
    ];

    /**
     * 配置新增接口凭证场景规则。
     *
     * @return static 校验器实例
     */
    public function sceneStore(): static
    {
        return $this->appendRules([
            'merchant_id' => 'required|integer|min:1|exists:ma_merchant,id',
            'sign_type' => 'required|integer|in:0',
            'status' => 'required|integer|in:0,1',
        ]);
    }

    /**
     * 配置更新接口凭证场景规则。
     *
     * @return static 校验器实例
     */
    public function sceneUpdate(): static
    {
        return $this->appendRules([
            'id' => 'required|integer|min:1',
            'sign_type' => 'required|integer|in:0',
            'status' => 'required|integer|in:0,1',
        ]);
    }

    /**
     * 配置接口凭证详情场景规则。
     *
     * @return static 校验器实例
     */
    public function sceneShow(): static
    {
        return $this->appendRules([
            'id' => 'required|integer|min:1',
        ]);
    }

    /**
     * 配置删除接口凭证场景规则。
     *
     * @return static 校验器实例
     */
    public function sceneDestroy(): static
    {
        return $this->sceneShow();
    }
}
