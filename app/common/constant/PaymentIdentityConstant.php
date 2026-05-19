<?php

namespace app\common\constant;

/**
 * 支付身份流程固定值。
 *
 * 用于统一 openid、buyer_id 等第三方用户身份预处理流程的状态值和响应字段。
 */
final class PaymentIdentityConstant
{
    /**
     * 需要先完成第三方用户身份授权。
     */
    public const STATUS_REQUIRED = 'identity_required';

    /**
     * 身份流程布尔标记字段。
     */
    public const FIELD_REQUIRED = 'identity_required';

    /**
     * 身份承接页地址字段。
     */
    public const FIELD_IDENTITY_URL = 'identity_url';

    /**
     * 身份流程续跑 token 字段。
     */
    public const FIELD_RESUME_TOKEN = 'resume_token';
}
