<?php

return [
    'gender' => [
        'name' => '性别',
        'code' => 'gender',
        'description' => '这是一个性别字典',
        'list' => [
            ['name' => '女', 'value' => 0],
            ['name' => '男', 'value' => 1],
            ['name' => '其它', 'value' => 2],
        ],
    ],
    'status' => [
        'name' => '通用状态',
        'code' => 'status',
        'description' => '通用启用禁用状态字典',
        'list' => [
            ['name' => '禁用', 'value' => 0],
            ['name' => '启用', 'value' => 1],
        ],
    ],
    'task_status' => [
        'name' => '任务状态',
        'code' => 'task_status',
        'description' => '任务执行状态字典',
        'list' => [
            ['name' => '待通知', 'value' => 0],
            ['name' => '成功', 'value' => 1],
            ['name' => '失败', 'value' => 2],
        ],
    ],
    'merchant_type' => [
        'name' => '商户类型',
        'code' => 'merchant_type',
        'description' => '商户主体类型字典',
        'list' => [
            ['name' => '个人', 'value' => 0],
            ['name' => '企业', 'value' => 1],
            ['name' => '其它', 'value' => 2],
        ],
    ],
    'risk_level' => [
        'name' => '风控等级',
        'code' => 'risk_level',
        'description' => '商户风控等级字典',
        'list' => [
            ['name' => '低', 'value' => 0],
            ['name' => '中', 'value' => 1],
            ['name' => '高', 'value' => 2],
        ],
    ],
    'channel_mode' => [
        'name' => '通道模式',
        'code' => 'channel_mode',
        'description' => '支付通道模式字典',
        'list' => [
            ['name' => '代收', 'value' => 0],
            ['name' => '自收', 'value' => 1],
        ],
    ],
    'route_mode' => [
        'name' => '路由模式',
        'code' => 'route_mode',
        'description' => '支付路由模式字典',
        'list' => [
            ['name' => '顺序依次轮询', 'value' => 0],
            ['name' => '权重随机轮询', 'value' => 1],
            ['name' => '默认启用通道', 'value' => 2],
        ],
    ],
    'settlement_cycle_type' => [
        'name' => '结算周期',
        'code' => 'settlement_cycle_type',
        'description' => '结算周期字典',
        'list' => [
            ['name' => 'D0', 'value' => 0],
            ['name' => 'D1', 'value' => 1],
            ['name' => 'D7', 'value' => 2],
            ['name' => 'T1', 'value' => 3],
            ['name' => 'OTHER', 'value' => 4],
        ],
    ],
    'pay_order_status' => [
        'name' => '支付订单状态',
        'code' => 'pay_order_status',
        'description' => '支付订单状态字典',
        'list' => [
            ['name' => '待创建', 'value' => 0],
            ['name' => '支付中', 'value' => 1],
            ['name' => '成功', 'value' => 2],
            ['name' => '失败', 'value' => 3],
            ['name' => '关闭', 'value' => 4],
            ['name' => '超时', 'value' => 5],
        ],
    ],
    'refund_order_status' => [
        'name' => '退款订单状态',
        'code' => 'refund_order_status',
        'description' => '退款订单状态字典',
        'list' => [
            ['name' => '待创建', 'value' => 0],
            ['name' => '处理中', 'value' => 1],
            ['name' => '成功', 'value' => 2],
            ['name' => '失败', 'value' => 3],
            ['name' => '关闭', 'value' => 4],
        ],
    ],
    'settlement_order_status' => [
        'name' => '清算订单状态',
        'code' => 'settlement_order_status',
        'description' => '清算订单状态字典',
        'list' => [
            ['name' => '无', 'value' => 0],
            ['name' => '待清算', 'value' => 1],
            ['name' => '已清算', 'value' => 2],
            ['name' => '已冲正', 'value' => 3],
        ],
    ],
    'callback_status' => [
        'name' => '回调状态',
        'code' => 'callback_status',
        'description' => '异步回调处理状态字典',
        'list' => [
            ['name' => '待处理', 'value' => 0],
            ['name' => '成功', 'value' => 1],
            ['name' => '失败', 'value' => 2],
        ],
    ],
    'callback_type' => [
        'name' => '回调类型',
        'code' => 'callback_type',
        'description' => '支付回调类型字典',
        'list' => [
            ['name' => '异步通知', 'value' => 0],
            ['name' => '同步返回', 'value' => 1],
        ],
    ],
    'notify_type' => [
        'name' => '通知类型',
        'code' => 'notify_type',
        'description' => '渠道通知类型字典',
        'list' => [
            ['name' => '异步通知', 'value' => 0],
            ['name' => '查单', 'value' => 1],
        ],
    ],
    'verify_status' => [
        'name' => '验签状态',
        'code' => 'verify_status',
        'description' => '通知验签状态字典',
        'list' => [
            ['name' => '未知', 'value' => 0],
            ['name' => '成功', 'value' => 1],
            ['name' => '失败', 'value' => 2],
        ],
    ],
    'process_status' => [
        'name' => '处理状态',
        'code' => 'process_status',
        'description' => '通知处理状态字典',
        'list' => [
            ['name' => '待处理', 'value' => 0],
            ['name' => '成功', 'value' => 1],
            ['name' => '失败', 'value' => 2],
        ],
    ],
    'ledger_biz_type' => [
        'name' => '流水业务类型',
        'code' => 'ledger_biz_type',
        'description' => '商户账户流水业务类型字典',
        'list' => [
            ['name' => '支付冻结', 'value' => 0],
            ['name' => '支付扣费', 'value' => 1],
            ['name' => '支付释放', 'value' => 2],
            ['name' => '清算入账', 'value' => 3],
            ['name' => '退款冲正', 'value' => 4],
            ['name' => '人工调整', 'value' => 5],
        ],
    ],
    'ledger_event_type' => [
        'name' => '流水事件类型',
        'code' => 'ledger_event_type',
        'description' => '商户账户流水事件类型字典',
        'list' => [
            ['name' => '创建', 'value' => 0],
            ['name' => '成功', 'value' => 1],
            ['name' => '失败', 'value' => 2],
            ['name' => '冲正', 'value' => 3],
        ],
    ],
    'ledger_direction' => [
        'name' => '流水方向',
        'code' => 'ledger_direction',
        'description' => '商户账户流水方向字典',
        'list' => [
            ['name' => '入账', 'value' => 0],
            ['name' => '出账', 'value' => 1],
        ],
    ],
];
