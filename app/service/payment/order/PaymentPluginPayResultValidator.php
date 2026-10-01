<?php

declare(strict_types=1);

namespace app\service\payment\order;

use Closure;
use support\validation\Validator;
use Webman\Validation\Factory\ValidationFactory;

/**
 * 校验插件下单返回的支付事实和承接结构。
 *
 * 固定承接字段按 pay_page 校验，第三方 JSAPI 字段由插件核验；自定义参数保持原值。
 * 此处失败不代表上游未建单，派发层按结果不确定处理，不释放资金占用。
 */
class PaymentPluginPayResultValidator extends Validator
{
    protected array $rules = [
        'status' => 'required|string|in:pending,success',
        'pay_no' => 'required|string|max:64',
        'paid_amount' => 'required_if:status,success|nullable|integer|min:0',
        'chan_order_no' => 'nullable|string|max:64',
        'chan_trade_no' => 'nullable|string|max:64',
        'pay_type' => 'required|string|max:32',
        'pay_product' => 'required|string|max:64',
        'pay_action' => 'nullable|string|max:64',
        'channel_context' => 'nullable|array',
        'presentation' => 'required_if:status,pending|nullable|array',
        'presentation.pay_page' => 'required_if:status,pending|required_with:presentation|nullable|string|in:qrcode,html,jump,jsapi,urlscheme,page|max:32',
        'presentation.pay_type' => 'required_if:status,pending|required_with:presentation|nullable|string|max:32|same:pay_type',
        'presentation.pay_product' => 'required_if:status,pending|required_with:presentation|nullable|string|max:64|same:pay_product',
        'presentation.pay_action' => 'required_if:status,pending|required_with:presentation|nullable|string|max:64|same:pay_action',
        'presentation.pay_params' => ['bail', 'required_if:status,pending', 'required_with:presentation.pay_page', 'nullable', 'array'],
    ];

    protected array $attributes = [
        'status' => '支付状态',
        'pay_no' => '支付单号',
        'paid_amount' => '实付金额',
        'chan_order_no' => '渠道订单号',
        'chan_trade_no' => '渠道交易号',
        'pay_type' => '支付方式',
        'pay_product' => '渠道支付产品',
        'pay_action' => '渠道支付动作',
        'channel_context' => '渠道后续操作上下文',
        'presentation' => '收银台承接信息',
        'presentation.pay_page' => '承接页类型',
        'presentation.pay_type' => '支付方式',
        'presentation.pay_product' => '支付产品',
        'presentation.pay_action' => '支付动作',
        'presentation.pay_params' => '支付参数',
    ];

    protected array $scenes = [
        'pay_result' => [
            'status',
            'pay_no',
            'paid_amount',
            'chan_order_no',
            'chan_trade_no',
            'pay_type',
            'pay_product',
            'pay_action',
            'channel_context',
            'presentation',
            'presentation.pay_page',
            'presentation.pay_type',
            'presentation.pay_product',
            'presentation.pay_action',
            'presentation.pay_params',
        ],
    ];

    /**
     * 按承接类型追加固定字段规则，不约束其他组件参数。
     *
     * Laravel 在 validated() 中会裁剪具有子规则的数组，因此局部校验只读取错误，
     * 不用局部 validated() 重建 pay_params，也不修改全局验证工厂的数组保留策略。
     *
     * @return array<string, mixed> 当前场景的完整规则
     */
    public function rules(): array
    {
        $rules = parent::rules();
        $payPage = data_get($this->data(), 'presentation.pay_page');
        $paramsRules = match ($payPage) {
            'qrcode' => ['qrcode' => 'required|string'],
            'html' => ['html' => 'required|string'],
            'urlscheme' => ['urlscheme' => 'required|string'],
            'page' => ['_page' => 'required|string'],
            'jump' => ['method' => 'sometimes|string|in:get,post'] + (
                data_get($this->data(), 'presentation.pay_params.method') === 'post'
                    ? ['action' => 'required|string', 'payload' => 'present|array']
                    : ['url' => 'required|string']
            ),
            default => [],
        };

        $rules['presentation.pay_params'][] = static function (string $attribute, array $value, Closure $fail) use ($payPage, $paramsRules): void {
            if ($payPage === 'jsapi' && array_is_list($value)) {
                $fail('JSAPI 支付参数必须为非空键值对象');
                return;
            }
            if ($payPage === 'jump' && ($value['method'] ?? '') === 'post'
                && is_array($value['payload'] ?? null) && $value['payload'] !== [] && array_is_list($value['payload'])) {
                $fail('POST 支付表单参数必须为键值对象');
                return;
            }
            if ($paramsRules === []) {
                return;
            }

            $validator = ValidationFactory::getFactory()->make($value, $paramsRules, [], [
                'qrcode' => '二维码内容', 'html' => '支付页面 HTML', 'urlscheme' => '应用跳转地址',
                '_page' => '承接组件名', 'method' => '支付跳转方式', 'url' => '支付跳转地址',
                'action' => 'POST 支付提交地址', 'payload' => 'POST 支付表单参数',
            ]);
            if ($validator->fails()) {
                $fail($validator->errors()->first());
            }
        };

        return $rules;
    }
}
