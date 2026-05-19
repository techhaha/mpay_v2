<?php

namespace app\common\interface;

/**
 * 支付用户身份需求声明接口。
 *
 * 公众号、JSAPI、小程序等支付产品在真正下单前需要先取得当前用户的
 * openid、buyer_id 等平台用户标识。插件实现该接口后，只负责根据当前通道
 * 配置、支付环境和已传扩展参数判断是否还缺少用户身份；具体缓存、授权跳转
 * 和继续发起支付由平台统一处理。
 */
interface PaymentIdentityRequirementInterface
{
    /**
     * 检查当前订单是否需要先获取第三方平台用户身份。
     *
     * 返回 null 表示当前环境可直接下单；返回数组表示需要进入身份获取流程。
     * 建议返回字段：
     * - provider：平台标识，如 wxpay、alipay。
     * - product：插件最终倾向使用的支付产品，如 mp、mini。
     * - identity_field：回填到 extra.payment 的字段名，如 openid、buyer_id。
     * - app_id：授权使用的应用 AppID。
     * - scope：授权 scope。
     * - auth_type：授权类型，如 wechat_oauth、mini_program、alipay_oauth。
     * - message：面向前端展示的提示。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed>|null 身份需求信息
     */
    public function identityRequirement(array $order): ?array;
}
