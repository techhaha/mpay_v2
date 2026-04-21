<?php

namespace app\http\admin\validation;

use support\validation\Validator;

/**
 * 管理员用户参数校验器。
 */
class AdminUserValidator extends Validator
{
    /**
     * 校验规则
     *
     * @var array
     */
    protected array $rules = [
        'id' => 'sometimes|integer|min:1',
        'keyword' => 'sometimes|string|max:128',
        'username' => 'sometimes|string|alpha_dash|min:2|max:32',
        'password' => 'nullable|string|min:6|max:64',
        'real_name' => 'sometimes|string|min:2|max:50',
        'mobile' => 'nullable|string|max:20',
        'email' => 'nullable|email|max:100',
        'is_super' => 'sometimes|integer|in:0,1',
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
        'id' => '管理员ID',
        'keyword' => '关键词',
        'username' => '登录账号',
        'password' => '登录密码',
        'real_name' => '真实姓名',
        'mobile' => '手机号',
        'email' => '邮箱',
        'is_super' => '超级管理员',
        'status' => '管理员状态',
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
        'index' => ['keyword', 'status', 'is_super', 'page', 'page_size'],
        'store' => ['username', 'password', 'real_name', 'mobile', 'email', 'is_super', 'status', 'remark'],
        'update' => ['id', 'username', 'password', 'real_name', 'mobile', 'email', 'is_super', 'status', 'remark'],
        'show' => ['id'],
        'destroy' => ['id'],
    ];

    /**
     * 配置新增管理员场景规则。
     *
     * @return static 校验器实例
     */
    public function sceneStore(): static
    {
        return $this->appendRules([
            'username' => 'required|string|alpha_dash|min:2|max:32',
            'password' => 'required|string|min:6|max:64',
            'real_name' => 'required|string|min:2|max:50',
            'is_super' => 'required|integer|in:0,1',
            'status' => 'required|integer|in:0,1',
        ]);
    }

    /**
     * 配置更新管理员场景规则。
     *
     * @return static 校验器实例
     */
    public function sceneUpdate(): static
    {
        return $this->appendRules([
            'id' => 'required|integer|min:1',
            'username' => 'required|string|alpha_dash|min:2|max:32',
            'real_name' => 'required|string|min:2|max:50',
            'is_super' => 'required|integer|in:0,1',
            'status' => 'required|integer|in:0,1',
        ]);
    }

    /**
     * 配置管理员详情场景规则。
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
     * 配置删除管理员场景规则。
     *
     * @return static 校验器实例
     */
    public function sceneDestroy(): static
    {
        return $this->sceneShow();
    }
}

