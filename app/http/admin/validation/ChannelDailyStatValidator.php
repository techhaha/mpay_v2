<?php

namespace app\http\admin\validation;

use support\validation\Validator;

/**
 * 通道日统计参数校验器。
 */
class ChannelDailyStatValidator extends Validator
{
    protected array $rules = [
        'id' => 'required|integer|min:1',
        'keyword' => 'sometimes|string|max:128',
        'merchant_id' => 'sometimes|integer|min:1',
        'channel_id' => 'sometimes|integer|min:1',
        'stat_date' => 'sometimes|date_format:Y-m-d',
        'page' => 'sometimes|integer|min:1',
        'page_size' => 'sometimes|integer|min:1|max:100',
    ];

    protected array $attributes = [
        'id' => '统计ID',
        'keyword' => '关键词',
        'merchant_id' => '所属商户',
        'channel_id' => '所属通道',
        'stat_date' => '统计日期',
        'page' => '页码',
        'page_size' => '每页条数',
    ];

    protected array $scenes = [
        'index' => ['keyword', 'merchant_id', 'channel_id', 'stat_date', 'page', 'page_size'],
        'show' => ['id'],
    ];
}
