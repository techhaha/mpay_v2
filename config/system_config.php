<?php

use app\common\constant\FileConstant;

$switchProps = [
    'checkedValue' => '1',
    'uncheckedValue' => '0',
    'checkedText' => '启用',
    'uncheckedText' => '禁用',
];

return [
    'platform' => [
        'title' => '平台门户',
        'icon' => 'icon-settings',
        'description' => '平台公开展示信息。站点 URL 会用于支付页、收银台、回调地址和公开文件访问前缀。',
        'sort' => 1,
        'disabled' => false,
        'rules' => [
            [
                'type' => 'input',
                'field' => 'site_name',
                'title' => '平台名称',
                'value' => '码支付',
                'props' => [
                    'placeholder' => '请输入平台名称',
                ],
                'validate' => [
                    ['required' => true, 'message' => '平台名称不能为空'],
                ],
            ],
            [
                'type' => 'input',
                'field' => 'site_url',
                'title' => '站点 URL',
                'value' => '',
                'props' => [
                    'placeholder' => '请输入站点 URL，例如 https://pay.example.com',
                ],
                'validate' => [
                    ['required' => true, 'message' => '站点 URL 不能为空'],
                ],
            ],
            [
                'type' => 'input',
                'field' => 'admin_portal_name',
                'title' => '管理后台名称',
                'value' => '管理后台',
                'props' => [
                    'placeholder' => '请输入管理后台显示名称',
                ],
            ],
            [
                'type' => 'input',
                'field' => 'merchant_portal_name',
                'title' => '商户后台名称',
                'value' => '商户后台',
                'props' => [
                    'placeholder' => '请输入商户后台显示名称',
                ],
            ],
            [
                'type' => 'switch',
                'field' => 'customer_service_enabled',
                'title' => '客服信息',
                'value' => '0',
                'props' => $switchProps,
                'control' => [
                    [
                        'rule' => [
                            'customer_service_name',
                            'customer_service_phone',
                            'customer_service_email',
                        ],
                        'value' => '1',
                        'method' => 'if',
                    ],
                ],
            ],
            [
                'type' => 'input',
                'field' => 'customer_service_name',
                'title' => '客服名称',
                'value' => '',
                'props' => [
                    'placeholder' => '请输入客服名称',
                ],
            ],
            [
                'type' => 'input',
                'field' => 'customer_service_phone',
                'title' => '客服电话',
                'value' => '',
                'props' => [
                    'placeholder' => '请输入客服电话',
                ],
            ],
            [
                'type' => 'input',
                'field' => 'customer_service_email',
                'title' => '客服邮箱',
                'value' => '',
                'props' => [
                    'placeholder' => '请输入客服邮箱',
                ],
            ],
            [
                'type' => 'switch',
                'field' => 'merchant_announcement_enabled',
                'title' => '商户公告',
                'value' => '0',
                'props' => $switchProps,
                'control' => [
                    [
                        'rule' => [
                            'merchant_announcement',
                        ],
                        'value' => '1',
                        'method' => 'if',
                    ],
                ],
            ],
            [
                'type' => 'textarea',
                'field' => 'merchant_announcement',
                'title' => '公告内容',
                'value' => '',
                'props' => [
                    'placeholder' => '请输入展示给商户端的公告内容',
                    'autoSize' => [
                        'minRows' => 3,
                        'maxRows' => 6,
                    ],
                ],
            ],
        ],
    ],
    'cashier' => [
        'title' => '收银台',
        'icon' => 'icon-mobile',
        'description' => '控制公开收银台入口、页面展示和支付状态轮询参数。',
        'sort' => 2,
        'disabled' => false,
        'rules' => [
            [
                'type' => 'switch',
                'field' => 'cashier_enabled',
                'title' => '启用收银台',
                'value' => '1',
                'props' => $switchProps,
                'control' => [
                    [
                        'rule' => [
                            'cashier_title',
                            'cashier_notice_enabled',
                            'cashier_notice',
                            'cashier_show_merchant_name',
                            'cashier_show_order_no',
                            'cashier_show_pay_type_desc',
                            'cashier_poll_interval_seconds',
                            'cashier_poll_timeout_seconds',
                        ],
                        'value' => '1',
                        'method' => 'if',
                    ],
                ],
            ],
            [
                'type' => 'input',
                'field' => 'cashier_title',
                'title' => '收银台标题',
                'value' => '收银台',
                'props' => [
                    'placeholder' => '请输入收银台标题',
                ],
            ],
            [
                'type' => 'switch',
                'field' => 'cashier_notice_enabled',
                'title' => '收银台提示',
                'value' => '1',
                'props' => $switchProps,
                'control' => [
                    [
                        'rule' => [
                            'cashier_notice',
                        ],
                        'value' => '1',
                        'method' => 'display',
                    ],
                ],
            ],
            [
                'type' => 'textarea',
                'field' => 'cashier_notice',
                'title' => '提示内容',
                'value' => '确认支付方式后，系统会创建本次支付尝试并跳转支付页。',
                'props' => [
                    'placeholder' => '请输入收银台提示内容',
                    'autoSize' => [
                        'minRows' => 2,
                        'maxRows' => 4,
                    ],
                ],
            ],
            [
                'type' => 'switch',
                'field' => 'cashier_show_merchant_name',
                'title' => '展示商户名称',
                'value' => '1',
                'props' => $switchProps,
            ],
            [
                'type' => 'switch',
                'field' => 'cashier_show_order_no',
                'title' => '展示订单号',
                'value' => '1',
                'props' => $switchProps,
            ],
            [
                'type' => 'switch',
                'field' => 'cashier_show_pay_type_desc',
                'title' => '展示支付方式说明',
                'value' => '1',
                'props' => $switchProps,
            ],
            [
                'type' => 'inputNumber',
                'field' => 'cashier_poll_interval_seconds',
                'title' => '状态轮询间隔(秒)',
                'value' => 2,
                'props' => [
                    'placeholder' => '请输入状态轮询间隔',
                    'min' => 1,
                    'max' => 60,
                    'step' => 1,
                ],
            ],
            [
                'type' => 'inputNumber',
                'field' => 'cashier_poll_timeout_seconds',
                'title' => '状态轮询超时(秒)',
                'value' => 300,
                'props' => [
                    'placeholder' => '请输入状态轮询超时时间',
                    'min' => 30,
                    'max' => 3600,
                    'step' => 1,
                ],
            ],
        ],
    ],
    'storage' => [
        'title' => '文件存储',
        'icon' => 'icon-storage',
        'description' => '控制后台上传、远程导入、证书文件和对象存储驱动。',
        'sort' => 3,
        'disabled' => false,
        'rules' => [
            [
                'type' => 'radio',
                'field' => 'file_storage_default_engine',
                'title' => '默认存储引擎',
                'value' => '1',
                'options' => [
                    ['label' => '本地存储', 'value' => (string) FileConstant::STORAGE_LOCAL],
                    ['label' => '阿里云 OSS', 'value' => (string) FileConstant::STORAGE_ALIYUN_OSS],
                    ['label' => '腾讯云 COS', 'value' => (string) FileConstant::STORAGE_TENCENT_COS],
                ],
                'control' => [
                    [
                        'rule' => [
                            '__storage_local_divider__',
                            'file_storage_local_public_base_url',
                            'file_storage_local_public_dir',
                            'file_storage_local_private_dir',
                        ],
                        'value' => (string) FileConstant::STORAGE_LOCAL,
                        'method' => 'display',
                    ],
                    [
                        'rule' => [
                            '__storage_aliyun_oss_divider__',
                            'file_storage_aliyun_oss_region',
                            'file_storage_aliyun_oss_endpoint',
                            'file_storage_aliyun_oss_bucket',
                            'file_storage_aliyun_oss_access_key_id',
                            'file_storage_aliyun_oss_access_key_secret',
                            'file_storage_aliyun_oss_public_domain',
                        ],
                        'value' => (string) FileConstant::STORAGE_ALIYUN_OSS,
                        'method' => 'display',
                    ],
                    [
                        'rule' => [
                            '__storage_tencent_cos_divider__',
                            'file_storage_tencent_cos_region',
                            'file_storage_tencent_cos_bucket',
                            'file_storage_tencent_cos_secret_id',
                            'file_storage_tencent_cos_secret_key',
                            'file_storage_tencent_cos_public_domain',
                        ],
                        'value' => (string) FileConstant::STORAGE_TENCENT_COS,
                        'method' => 'display',
                    ],
                ],
            ],
            [
                'type' => 'inputNumber',
                'field' => 'file_storage_upload_max_size_mb',
                'title' => '上传大小限制(MB)',
                'value' => 20,
                'props' => [
                    'placeholder' => '请输入上传大小限制',
                    'min' => 1,
                    'max' => 1024,
                    'step' => 1,
                ],
            ],
            [
                'type' => 'inputNumber',
                'field' => 'file_storage_remote_download_limit_mb',
                'title' => '远程下载限制(MB)',
                'value' => 10,
                'props' => [
                    'placeholder' => '请输入远程下载限制',
                    'min' => 1,
                    'max' => 1024,
                    'step' => 1,
                ],
            ],
            [
                'type' => 'a-divider',
                'field' => '__storage_local_divider__',
                'title' => '本地存储',
                'value' => '',
                'props' => [
                    'orientation' => 'left',
                    'margin' => 16,
                ],
                'children' => [
                    '本地存储',
                ],
            ],
            [
                'type' => 'input',
                'field' => 'file_storage_local_public_base_url',
                'title' => '本地公开 Base URL',
                'value' => '',
                'props' => [
                    'placeholder' => '请输入本地公开访问前缀，留空时自动使用站点 URL',
                ],
            ],
            [
                'type' => 'input',
                'field' => 'file_storage_local_public_dir',
                'title' => '本地公开目录',
                'value' => 'storage/uploads',
                'props' => [
                    'placeholder' => '请输入本地公开目录，例如 storage/uploads',
                ],
            ],
            [
                'type' => 'input',
                'field' => 'file_storage_local_private_dir',
                'title' => '本地私有目录',
                'value' => 'storage/private',
                'props' => [
                    'placeholder' => '请输入本地私有目录，例如 storage/private',
                ],
            ],
            [
                'type' => 'a-divider',
                'field' => '__storage_aliyun_oss_divider__',
                'title' => '阿里云 OSS',
                'value' => '',
                'props' => [
                    'orientation' => 'left',
                    'margin' => 16,
                ],
                'children' => [
                    '阿里云 OSS',
                ],
            ],
            [
                'type' => 'input',
                'field' => 'file_storage_aliyun_oss_region',
                'title' => '阿里云 OSS Region',
                'value' => '',
                'props' => [
                    'placeholder' => '请输入阿里云 OSS Region，例如 oss-cn-hangzhou',
                ],
            ],
            [
                'type' => 'input',
                'field' => 'file_storage_aliyun_oss_endpoint',
                'title' => '阿里云 OSS Endpoint',
                'value' => '',
                'props' => [
                    'placeholder' => '请输入阿里云 OSS Endpoint',
                ],
            ],
            [
                'type' => 'input',
                'field' => 'file_storage_aliyun_oss_bucket',
                'title' => '阿里云 OSS Bucket',
                'value' => '',
                'props' => [
                    'placeholder' => '请输入阿里云 OSS Bucket',
                ],
            ],
            [
                'type' => 'input',
                'field' => 'file_storage_aliyun_oss_access_key_id',
                'title' => '阿里云 OSS Access Key ID',
                'value' => '',
                'props' => [
                    'placeholder' => '请输入阿里云 OSS Access Key ID',
                ],
            ],
            [
                'type' => 'password',
                'field' => 'file_storage_aliyun_oss_access_key_secret',
                'title' => '阿里云 OSS Access Key Secret',
                'value' => '',
                'props' => [
                    'placeholder' => '请输入阿里云 OSS Access Key Secret',
                ],
            ],
            [
                'type' => 'input',
                'field' => 'file_storage_aliyun_oss_public_domain',
                'title' => '阿里云 OSS 公开域名',
                'value' => '',
                'props' => [
                    'placeholder' => '请输入阿里云 OSS 公开域名，可选',
                ],
            ],
            [
                'type' => 'a-divider',
                'field' => '__storage_tencent_cos_divider__',
                'title' => '腾讯云 COS',
                'value' => '',
                'props' => [
                    'orientation' => 'left',
                    'margin' => 16,
                ],
                'children' => [
                    '腾讯云 COS',
                ],
            ],
            [
                'type' => 'input',
                'field' => 'file_storage_tencent_cos_region',
                'title' => '腾讯云 COS Region',
                'value' => '',
                'props' => [
                    'placeholder' => '请输入腾讯云 COS Region，例如 ap-shanghai',
                ],
            ],
            [
                'type' => 'input',
                'field' => 'file_storage_tencent_cos_bucket',
                'title' => '腾讯云 COS Bucket',
                'value' => '',
                'props' => [
                    'placeholder' => '请输入腾讯云 COS Bucket',
                ],
            ],
            [
                'type' => 'input',
                'field' => 'file_storage_tencent_cos_secret_id',
                'title' => '腾讯云 COS SecretId',
                'value' => '',
                'props' => [
                    'placeholder' => '请输入腾讯云 COS SecretId',
                ],
            ],
            [
                'type' => 'password',
                'field' => 'file_storage_tencent_cos_secret_key',
                'title' => '腾讯云 COS SecretKey',
                'value' => '',
                'props' => [
                    'placeholder' => '请输入腾讯云 COS SecretKey',
                ],
            ],
            [
                'type' => 'input',
                'field' => 'file_storage_tencent_cos_public_domain',
                'title' => '腾讯云 COS 公开域名',
                'value' => '',
                'props' => [
                    'placeholder' => '请输入腾讯云 COS 公开域名，可选',
                ],
            ],
        ],
    ],
    'payment_order' => [
        'title' => '支付订单',
        'icon' => 'icon-safe',
        'description' => '控制支付单时效、重复尝试和全局金额边界。商户、通道、路由自身的规则仍以对应菜单配置为准。',
        'sort' => 4,
        'disabled' => false,
        'rules' => [
            [
                'type' => 'switch',
                'field' => 'pay_order_timeout_enabled',
                'title' => '订单超时',
                'value' => '1',
                'props' => $switchProps,
                'control' => [
                    [
                        'rule' => [
                            'pay_order_expire_minutes',
                        ],
                        'value' => '1',
                        'method' => 'display',
                    ],
                ],
            ],
            [
                'type' => 'inputNumber',
                'field' => 'pay_order_expire_minutes',
                'title' => '订单有效期(分钟)',
                'value' => 30,
                'props' => [
                    'placeholder' => '请输入订单有效期',
                    'min' => 1,
                    'max' => 1440,
                    'step' => 1,
                ],
            ],
            [
                'type' => 'switch',
                'field' => 'pay_order_failed_retry_enabled',
                'title' => '失败后允许重试',
                'value' => '1',
                'props' => $switchProps,
            ],
            [
                'type' => 'switch',
                'field' => 'pay_order_attempt_limit_enabled',
                'title' => '支付尝试次数限制',
                'value' => '1',
                'props' => $switchProps,
                'control' => [
                    [
                        'rule' => [
                            'pay_order_attempt_limit',
                        ],
                        'value' => '1',
                        'method' => 'display',
                    ],
                ],
            ],
            [
                'type' => 'inputNumber',
                'field' => 'pay_order_attempt_limit',
                'title' => '最大尝试次数',
                'value' => 5,
                'props' => [
                    'placeholder' => '请输入同一业务单最大支付尝试次数',
                    'min' => 1,
                    'max' => 50,
                    'step' => 1,
                ],
            ],
            [
                'type' => 'switch',
                'field' => 'pay_order_amount_limit_enabled',
                'title' => '支付金额全局限制',
                'value' => '0',
                'props' => $switchProps,
                'control' => [
                    [
                        'rule' => [
                            'pay_order_min_amount_yuan',
                            'pay_order_max_amount_yuan',
                        ],
                        'value' => '1',
                        'method' => 'display',
                    ],
                ],
            ],
            [
                'type' => 'inputNumber',
                'field' => 'pay_order_min_amount_yuan',
                'title' => '最小支付金额(元)',
                'value' => 0.01,
                'props' => [
                    'placeholder' => '请输入最小支付金额',
                    'min' => 0.01,
                    'max' => 99999999,
                    'step' => 0.01,
                    'precision' => 2,
                ],
            ],
            [
                'type' => 'inputNumber',
                'field' => 'pay_order_max_amount_yuan',
                'title' => '最大支付金额(元，0不限)',
                'value' => 0,
                'props' => [
                    'placeholder' => '请输入最大支付金额，0 表示不限',
                    'min' => 0,
                    'max' => 99999999,
                    'step' => 0.01,
                    'precision' => 2,
                ],
            ],
        ],
    ],
    'notify' => [
        'title' => '通知回调',
        'icon' => 'icon-notification',
        'description' => '控制平台向商户发送异步通知的任务策略，以及渠道回调日志是否留痕。',
        'sort' => 5,
        'disabled' => false,
        'rules' => [
            [
                'type' => 'switch',
                'field' => 'pay_notify_enabled',
                'title' => '商户通知',
                'value' => '1',
                'props' => $switchProps,
                'control' => [
                    [
                        'rule' => [
                            'pay_notify_retry_limit',
                            'pay_notify_retry_interval',
                            'pay_notify_request_timeout_seconds',
                        ],
                        'value' => '1',
                        'method' => 'display',
                    ],
                ],
            ],
            [
                'type' => 'inputNumber',
                'field' => 'pay_notify_retry_limit',
                'title' => '通知重试次数',
                'value' => 3,
                'props' => [
                    'placeholder' => '请输入通知重试次数',
                    'min' => 1,
                    'max' => 20,
                    'step' => 1,
                ],
            ],
            [
                'type' => 'inputNumber',
                'field' => 'pay_notify_retry_interval',
                'title' => '通知重试间隔(分钟)',
                'value' => 10,
                'props' => [
                    'placeholder' => '请输入通知重试间隔',
                    'min' => 1,
                    'max' => 1440,
                    'step' => 1,
                ],
            ],
            [
                'type' => 'inputNumber',
                'field' => 'pay_notify_request_timeout_seconds',
                'title' => '通知请求超时(秒)',
                'value' => 10,
                'props' => [
                    'placeholder' => '请输入商户通知请求超时时间',
                    'min' => 1,
                    'max' => 60,
                    'step' => 1,
                ],
            ],
            [
                'type' => 'switch',
                'field' => 'pay_callback_log_enabled',
                'title' => '记录渠道回调日志',
                'value' => '1',
                'props' => $switchProps,
            ],
        ],
    ],
    'runtime' => [
        'title' => '运行维护',
        'icon' => 'icon-thunderbolt',
        'description' => '后台定时维护任务参数。关闭总开关后不再扫描通知重试、订单超时和主动查单。',
        'sort' => 6,
        'disabled' => false,
        'rules' => [
            [
                'type' => 'switch',
                'field' => 'pay_runtime_enabled',
                'title' => '启用维护任务',
                'value' => '1',
                'props' => $switchProps,
                'control' => [
                    [
                        'rule' => [
                            '__runtime_notify_divider__',
                            'pay_notify_retry_scan_interval_seconds',
                            'pay_notify_retry_batch_size',
                            '__runtime_timeout_divider__',
                            'pay_order_timeout_scan_interval_seconds',
                            'pay_order_timeout_batch_size',
                            '__runtime_query_divider__',
                            'pay_active_query_enabled',
                            'pay_active_query_interval_seconds',
                            'pay_active_query_min_age_seconds',
                            'pay_active_query_batch_size',
                            '__runtime_receipt_watcher_divider__',
                            'receipt_watcher_enabled',
                            'receipt_watcher_plugin_codes',
                            'receipt_watcher_order_scan_interval_seconds',
                            'receipt_watcher_order_scan_batch_size',
                        ],
                        'value' => '1',
                        'method' => 'if',
                    ],
                ],
            ],
            [
                'type' => 'a-divider',
                'field' => '__runtime_notify_divider__',
                'title' => '通知重试任务',
                'value' => '',
                'props' => [
                    'orientation' => 'left',
                    'margin' => 16,
                ],
                'children' => [
                    '通知重试任务',
                ],
            ],
            [
                'type' => 'inputNumber',
                'field' => 'pay_notify_retry_scan_interval_seconds',
                'title' => '通知重试扫描间隔(秒)',
                'value' => 60,
                'props' => [
                    'placeholder' => '请输入通知重试扫描间隔',
                    'min' => 5,
                    'max' => 3600,
                    'step' => 1,
                ],
            ],
            [
                'type' => 'inputNumber',
                'field' => 'pay_notify_retry_batch_size',
                'title' => '通知重试批量',
                'value' => 100,
                'props' => [
                    'placeholder' => '请输入每轮最多处理通知数',
                    'min' => 1,
                    'max' => 1000,
                    'step' => 1,
                ],
            ],
            [
                'type' => 'a-divider',
                'field' => '__runtime_timeout_divider__',
                'title' => '超时订单任务',
                'value' => '',
                'props' => [
                    'orientation' => 'left',
                    'margin' => 16,
                ],
                'children' => [
                    '超时订单任务',
                ],
            ],
            [
                'type' => 'inputNumber',
                'field' => 'pay_order_timeout_scan_interval_seconds',
                'title' => '超时订单扫描间隔(秒)',
                'value' => 60,
                'props' => [
                    'placeholder' => '请输入超时订单扫描间隔',
                    'min' => 5,
                    'max' => 3600,
                    'step' => 1,
                ],
            ],
            [
                'type' => 'inputNumber',
                'field' => 'pay_order_timeout_batch_size',
                'title' => '超时订单处理批量',
                'value' => 100,
                'props' => [
                    'placeholder' => '请输入每轮最多处理超时订单数',
                    'min' => 1,
                    'max' => 1000,
                    'step' => 1,
                ],
            ],
            [
                'type' => 'a-divider',
                'field' => '__runtime_query_divider__',
                'title' => '主动查单任务',
                'value' => '',
                'props' => [
                    'orientation' => 'left',
                    'margin' => 16,
                ],
                'children' => [
                    '主动查单任务',
                ],
            ],
            [
                'type' => 'switch',
                'field' => 'pay_active_query_enabled',
                'title' => '主动查单',
                'value' => '1',
                'props' => $switchProps,
                'control' => [
                    [
                        'rule' => [
                            'pay_active_query_interval_seconds',
                            'pay_active_query_min_age_seconds',
                            'pay_active_query_batch_size',
                        ],
                        'value' => '1',
                        'method' => 'display',
                    ],
                ],
            ],
            [
                'type' => 'inputNumber',
                'field' => 'pay_active_query_interval_seconds',
                'title' => '主动查单间隔(秒)',
                'value' => 60,
                'props' => [
                    'placeholder' => '请输入主动查单间隔',
                    'min' => 10,
                    'max' => 3600,
                    'step' => 1,
                ],
            ],
            [
                'type' => 'inputNumber',
                'field' => 'pay_active_query_min_age_seconds',
                'title' => '主动查单等待时间(秒)',
                'value' => 60,
                'props' => [
                    'placeholder' => '支付拉起后至少等待多少秒再查单',
                    'min' => 1,
                    'max' => 3600,
                    'step' => 1,
                ],
            ],
            [
                'type' => 'inputNumber',
                'field' => 'pay_active_query_batch_size',
                'title' => '主动查单批量',
                'value' => 50,
                'props' => [
                    'placeholder' => '请输入每轮最多查单数',
                    'min' => 1,
                    'max' => 1000,
                    'step' => 1,
                ],
            ],
            [
                'type' => 'a-divider',
                'field' => '__runtime_receipt_watcher_divider__',
                'title' => '网页流水监听',
                'value' => '',
                'props' => [
                    'orientation' => 'left',
                    'margin' => 16,
                ],
                'children' => [
                    '网页流水监听',
                ],
            ],
            [
                'type' => 'switch',
                'field' => 'receipt_watcher_enabled',
                'title' => '启用网页流水监听',
                'value' => '0',
                'props' => $switchProps,
                'control' => [
                    [
                        'rule' => [
                            'receipt_watcher_plugin_codes',
                            'receipt_watcher_order_scan_interval_seconds',
                            'receipt_watcher_order_scan_batch_size',
                        ],
                        'value' => '1',
                        'method' => 'display',
                    ],
                ],
            ],
            [
                'type' => 'textarea',
                'field' => 'receipt_watcher_plugin_codes',
                'title' => '监听插件标识',
                'value' => "shouqianba_receipt\npostar_receipt",
                'props' => [
                    'placeholder' => '请输入支持网页流水监听的插件标识，多个用逗号或换行分隔',
                    'autoSize' => [
                        'minRows' => 2,
                        'maxRows' => 4,
                    ],
                ],
            ],
            [
                'type' => 'inputNumber',
                'field' => 'receipt_watcher_order_scan_interval_seconds',
                'title' => '订单扫描间隔(秒)',
                'value' => 3,
                'props' => [
                    'placeholder' => '请输入待支付订单扫描间隔',
                    'min' => 2,
                    'max' => 60,
                    'step' => 1,
                ],
            ],
            [
                'type' => 'inputNumber',
                'field' => 'receipt_watcher_order_scan_batch_size',
                'title' => '订单扫描批量',
                'value' => 500,
                'props' => [
                    'placeholder' => '请输入每轮最多扫描订单数',
                    'min' => 1,
                    'max' => 5000,
                    'step' => 1,
                ],
            ],
        ],
    ],
    'channel_test' => [
        'title' => '通道测试',
        'icon' => 'icon-apps',
        'description' => '后台发起通道测试时使用的默认商户上下文和跳转地址。',
        'sort' => 7,
        'disabled' => false,
        'rules' => [
            [
                'type' => 'switch',
                'field' => 'channel_test_enabled',
                'title' => '启用通道测试',
                'value' => '1',
                'props' => $switchProps,
                'control' => [
                    [
                        'rule' => [
                            'channel_test_merchant_id',
                            'channel_test_return_url',
                            'channel_test_notify_url',
                            'channel_test_debug_log_enabled',
                        ],
                        'value' => '1',
                        'method' => 'display',
                    ],
                ],
            ],
            [
                'type' => 'inputNumber',
                'field' => 'channel_test_merchant_id',
                'title' => '测试商户ID',
                'value' => null,
                'props' => [
                    'placeholder' => '请输入后台通道测试使用的商户ID',
                    'min' => 1,
                    'step' => 1,
                ],
            ],
            [
                'type' => 'input',
                'field' => 'channel_test_return_url',
                'title' => '测试返回地址',
                'value' => '',
                'props' => [
                    'placeholder' => '留空时收银台显示 OK 支付成功页',
                ],
            ],
            [
                'type' => 'input',
                'field' => 'channel_test_notify_url',
                'title' => '测试通知地址',
                'value' => '',
                'props' => [
                    'placeholder' => '请输入测试异步通知地址，留空不通知商户',
                ],
            ],
            [
                'type' => 'switch',
                'field' => 'channel_test_debug_log_enabled',
                'title' => '记录测试调试日志',
                'value' => '0',
                'props' => $switchProps,
            ],
        ],
    ],
];
