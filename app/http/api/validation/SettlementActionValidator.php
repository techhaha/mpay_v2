<?php

namespace app\http\api\validation;

use support\validation\Validator;

/**
 * 清算动作参数校验器。
 *
 * 用于校验清算成功和失败动作的公共参数。
 */
class SettlementActionValidator extends Validator
{
    protected array $rules = [
        'settle_no' => 'required|string|min:1|max:64|exists:ma_settlement_order,settle_no',
        'reason' => 'nullable|string|max:255',
        'ext_json' => 'nullable|array',
    ];

    protected array $attributes = [
        'settle_no' => '清算单号',
        'reason' => '原因',
        'ext_json' => '扩展信息',
    ];

    protected array $scenes = [
        'complete' => ['settle_no'],
        'fail' => ['settle_no', 'reason', 'ext_json'],
    ];
}
