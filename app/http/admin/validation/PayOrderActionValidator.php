<?php

namespace app\http\admin\validation;

use support\validation\Validator;

/**
 * 支付订单后台操作参数校验器。
 *
 * 操作接口只做字段形态校验，状态、金额和冻结等业务规则由服务层加锁后最终判断。
 */
class PayOrderActionValidator extends Validator
{
    /**
     * 校验规则。
     *
     * @var array
     */
    protected array $rules = [
        'pay_no' => 'required|string|max:64',
        'reason' => 'sometimes|string|max:255',
        'refund_amount' => 'sometimes|integer|min:1',
        'paid_amount' => 'sometimes|integer|min:1',
        'money' => 'sometimes|string|max:32|regex:/^\d+(\.\d{1,2})?$/',
        'channel_order_no' => 'sometimes|string|max:64',
        'channel_trade_no' => 'sometimes|string|max:64',
        'paid_at' => 'sometimes|date_format:Y-m-d H:i:s',
    ];

    /**
     * 字段别名。
     *
     * @var array
     */
    protected array $attributes = [
        'pay_no' => '支付单号',
        'reason' => '操作原因',
        'refund_amount' => '退款金额',
        'paid_amount' => '实付金额',
        'money' => '金额',
        'channel_order_no' => '渠道订单号',
        'channel_trade_no' => '渠道交易号',
        'paid_at' => '支付时间',
    ];

    /**
     * 校验场景。
     *
     * @var array
     */
    protected array $scenes = [
        'actions' => ['pay_no'],
        'renotify' => ['pay_no', 'reason'],
        'active_query' => ['pay_no'],
        'api_refund' => ['pay_no'],
        'manual_refund' => ['pay_no', 'reason', 'refund_amount', 'money'],
        'manual_success' => ['pay_no', 'reason'],
        'freeze' => ['pay_no', 'reason'],
        'unfreeze' => ['pay_no', 'reason'],
    ];

    /**
     * 根据场景补充动态规则。
     *
     * webman/validation 只会按 scenes 截取字段规则，不会自动调用 sceneXxx 方法，
     * 因此需要在 rules() 里把高风险动作的必填原因显式补上。
     *
     * @return array<string, mixed> 校验规则
     */
    public function rules(): array
    {
        $rules = parent::rules();
        if (in_array($this->scene(), ['manual_refund', 'manual_success', 'freeze', 'unfreeze'], true)) {
            $rules['reason'] = 'required|string|max:255';
        }

        return $rules;
    }
}
