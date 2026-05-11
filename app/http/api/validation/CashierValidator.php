<?php

namespace app\http\api\validation;

use support\validation\Validator;

/**
 * 收银台请求验证器。
 *
 * 定义收银台上下文查询与确认支付场景规则。
 */
class CashierValidator extends Validator
{
    protected array $rules = [
        'biz_no' => 'required|string|max:32',
        'pay_no' => 'required|string|max:32',
        'type' => 'nullable|string|max:32',
    ];

    protected array $attributes = [
        'biz_no' => '业务单号',
        'pay_no' => '支付单号',
        'type' => '支付方式',
    ];

    protected array $scenes = [
        'context' => ['biz_no'],
        'confirm' => ['biz_no', 'type'],
        'pay_order' => ['pay_no'],
        'pay_order_status' => ['pay_no'],
    ];

    /**
     * 收银台上下文场景。
     *
     * @return static
     */
    public function sceneContext(): static
    {
        return $this->appendRules([
            'biz_no' => 'required|string|max:32',
        ]);
    }

    /**
     * 收银台确认场景。
     *
     * @return static
     */
    public function sceneConfirm(): static
    {
        return $this->appendRules([
            'biz_no' => 'required|string|max:32',
            'type' => 'required|string|max:32',
        ]);
    }

    /**
     * 支付页详情场景。
     *
     * @return static
     */
    public function scenePayOrder(): static
    {
        return $this->appendRules([
            'pay_no' => 'required|string|max:32',
        ]);
    }

    /**
     * 支付状态查询场景。
     *
     * @return static
     */
    public function scenePayOrderStatus(): static
    {
        return $this->appendRules([
            'pay_no' => 'required|string|max:32',
        ]);
    }
}
