<?php

use app\common\constant\FileConstant;

return [
    'base' => [
        'title' => '基础配置',
        'icon' => 'icon-settings',
        'description' => '站点基础信息、站点 URL、页面默认值与展示文案。',
        'sort' => 1,
        'disabled' => false,
        'rules' => [
            [
                'type' => 'input',
                'field' => 'site_name',
                'title' => '站点名称',
                'value' => '',
                'props' => [
                    'placeholder' => '请输入站点名称',
                ],
                'validate' => [
                    ['required' => true, 'message' => '站点名称不能为空'],
                ],
            ],
            [
                'type' => 'textarea',
                'field' => 'site_description',
                'title' => '站点描述',
                'value' => '',
                'props' => [
                    'placeholder' => '请输入站点描述',
                    'autoSize' => [
                        'minRows' => 3,
                        'maxRows' => 6,
                    ],
                ],
            ],
            [
                'type' => 'upload',
                'field' => 'site_logo',
                'title' => '站点 Logo',
                'value' => '',
                'props' => [
                    'fileUpload' => [
                        'scene' => FileConstant::SCENE_IMAGE,
                        'visibility' => FileConstant::VISIBILITY_PUBLIC,
                        'accept' => '.jpg,.jpeg,.png,.gif,.webp,.bmp,.svg',
                        'listType' => 'picture-card',
                        'showFileList' => true,
                        'imagePreview' => true,
                        'limit' => 1,
                    ],
                    'tip' => '建议上传清晰的图片 Logo，推荐使用 PNG 或 SVG 格式。',
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
                'field' => 'site_icp',
                'title' => '备案号',
                'value' => '',
                'props' => [
                    'placeholder' => '请输入备案号',
                ],
            ],
            [
                'type' => 'select',
                'field' => 'default_page_size',
                'title' => '默认分页条数',
                'value' => '20',
                'props' => [
                    'placeholder' => '请选择默认分页条数',
                ],
                'options' => [
                    ['label' => '10', 'value' => '10'],
                    ['label' => '20', 'value' => '20'],
                    ['label' => '50', 'value' => '50'],
                    ['label' => '100', 'value' => '100'],
                ],
            ],
        ],
    ],
    'storage' => [
        'title' => '存储配置',
        'icon' => 'icon-storage',
        'description' => '文件上传、图片素材、证书和对象存储的统一配置。',
        'sort' => 2,
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
                            'file_storage_local_public_base_url',
                            'file_storage_local_public_dir',
                            'file_storage_local_private_dir',
                        ],
                        'value' => (string) FileConstant::STORAGE_LOCAL,
                        'method' => 'display',
                    ],
                    [
                        'rule' => [
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
                'type' => 'input',
                'field' => 'file_storage_upload_max_size_mb',
                'title' => '上传大小限制',
                'value' => '20',
                'props' => [
                    'placeholder' => '请输入上传大小限制，单位 MB',
                ],
            ],
            [
                'type' => 'input',
                'field' => 'file_storage_remote_download_limit_mb',
                'title' => '远程下载限制',
                'value' => '10',
                'props' => [
                    'placeholder' => '请输入远程下载限制，单位 MB',
                ],
            ],
            [
                'type' => 'input',
                'field' => 'file_storage_allowed_extensions',
                'title' => '允许扩展名',
                'value' => 'jpg,jpeg,png,gif,webp,bmp,svg,pem,crt,cer,key,p12,pfx,txt,log,csv,json,xml,md,ini,conf,yaml,yml',
                'props' => [
                    'placeholder' => '请输入允许上传的扩展名，英文逗号分隔',
                ],
            ],
            [
                'type' => 'a-divider',
                'field' => '__storage_engine_divider__',
                'title' => '存储引擎配置',
                'value' => '',
                'props' => [
                    'orientation' => 'left',
                    'margin' => 16,
                ],
                'children' => [
                    '存储引擎配置',
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
                'type' => 'input',
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
                'type' => 'input',
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
    'merchant' => [
        'title' => '商户配置',
        'icon' => 'icon-user-group',
        'description' => '商户后台展示、资料和辅助能力配置。',
        'sort' => 3,
        'disabled' => false,
        'rules' => [
            [
                'type' => 'input',
                'field' => 'merchant_service_name',
                'title' => '客服名称',
                'value' => '',
                'props' => [
                    'placeholder' => '请输入客服名称',
                ],
            ],
            [
                'type' => 'input',
                'field' => 'merchant_service_phone',
                'title' => '客服电话',
                'value' => '',
                'props' => [
                    'placeholder' => '请输入客服电话',
                ],
            ],
            [
                'type' => 'input',
                'field' => 'merchant_service_email',
                'title' => '客服邮箱',
                'value' => '',
                'props' => [
                    'placeholder' => '请输入客服邮箱',
                ],
            ],
            [
                'type' => 'textarea',
                'field' => 'merchant_notice',
                'title' => '商户公告',
                'value' => '',
                'props' => [
                    'placeholder' => '请输入商户公告',
                    'autoSize' => [
                        'minRows' => 3,
                        'maxRows' => 6,
                    ],
                ],
            ],
        ],
    ],
    'channel' => [
        'title' => '通道配置',
        'icon' => 'icon-apps',
        'description' => '通道调度、同步和基础运维参数。',
        'sort' => 4,
        'disabled' => false,
        'rules' => [
            [
                'type' => 'select',
                'field' => 'channel_sync_interval',
                'title' => '同步间隔',
                'value' => '10',
                'props' => [
                    'placeholder' => '请选择同步间隔',
                ],
                'options' => [
                    ['label' => '5 分钟', 'value' => '5'],
                    ['label' => '10 分钟', 'value' => '10'],
                    ['label' => '30 分钟', 'value' => '30'],
                    ['label' => '60 分钟', 'value' => '60'],
                ],
            ],
            [
                'type' => 'input',
                'field' => 'channel_default_timeout',
                'title' => '默认超时时间',
                'value' => '60',
                'props' => [
                    'placeholder' => '请输入默认超时时间，单位秒',
                ],
            ],
            [
                'type' => 'input',
                'field' => 'channel_retry_limit',
                'title' => '同步重试次数',
                'value' => '3',
                'props' => [
                    'placeholder' => '请输入同步重试次数',
                ],
            ],
            [
                'type' => 'textarea',
                'field' => 'channel_notice',
                'title' => '通道说明',
                'value' => '',
                'props' => [
                    'placeholder' => '请输入通道说明',
                    'autoSize' => [
                        'minRows' => 3,
                        'maxRows' => 6,
                    ],
                ],
            ],
        ],
    ],
    'payment' => [
        'title' => '支付配置',
        'icon' => 'icon-safe',
        'description' => '支付时效、通知重试和支付流程相关参数。',
        'sort' => 5,
        'disabled' => false,
        'rules' => [
            [
                'type' => 'input',
                'field' => 'pay_order_expire_minutes',
                'title' => '订单有效期',
                'value' => '30',
                'props' => [
                    'placeholder' => '请输入订单有效期，单位分钟',
                ],
                'validate' => [
                    ['required' => true, 'message' => '订单有效期不能为空'],
                ],
            ],
            [
                'type' => 'input',
                'field' => 'pay_notify_retry_limit',
                'title' => '通知重试次数',
                'value' => '3',
                'props' => [
                    'placeholder' => '请输入通知重试次数',
                ],
            ],
            [
                'type' => 'input',
                'field' => 'pay_notify_retry_interval',
                'title' => '通知重试间隔',
                'value' => '10',
                'props' => [
                    'placeholder' => '请输入通知重试间隔，单位分钟',
                ],
            ],
            [
                'type' => 'input',
                'field' => 'pay_callback_timeout_seconds',
                'title' => '回调超时',
                'value' => '60',
                'props' => [
                    'placeholder' => '请输入回调超时时间，单位秒',
                ],
            ],
        ],
    ],
    'notice' => [
        'title' => '通知配置',
        'icon' => 'icon-notification',
        'description' => '通知开关、频率和失败重试策略。',
        'sort' => 6,
        'disabled' => false,
        'rules' => [
            [
                'type' => 'select',
                'field' => 'notice_enabled',
                'title' => '通知开关',
                'value' => '1',
                'props' => [
                    'placeholder' => '请选择通知开关',
                ],
                'options' => [
                    ['label' => '禁用', 'value' => '0'],
                    ['label' => '启用', 'value' => '1'],
                ],
            ],
            [
                'type' => 'input',
                'field' => 'notice_retry_limit',
                'title' => '通知重试次数',
                'value' => '3',
                'props' => [
                    'placeholder' => '请输入通知重试次数',
                ],
            ],
            [
                'type' => 'input',
                'field' => 'notice_retry_interval',
                'title' => '通知重试间隔',
                'value' => '10',
                'props' => [
                    'placeholder' => '请输入通知重试间隔，单位分钟',
                ],
            ],
            [
                'type' => 'input',
                'field' => 'notice_webhook_url',
                'title' => '通知地址',
                'value' => '',
                'props' => [
                    'placeholder' => '请输入通知地址',
                ],
            ],
        ],
    ],
    'risk' => [
        'title' => '风控配置',
        'icon' => 'icon-fire',
        'description' => '基础风控阈值和限制策略。',
        'sort' => 7,
        'disabled' => false,
        'rules' => [
            [
                'type' => 'select',
                'field' => 'risk_enabled',
                'title' => '风控开关',
                'value' => '1',
                'props' => [
                    'placeholder' => '请选择风控开关',
                ],
                'options' => [
                    ['label' => '禁用', 'value' => '0'],
                    ['label' => '启用', 'value' => '1'],
                ],
            ],
            [
                'type' => 'input',
                'field' => 'risk_ip_limit',
                'title' => 'IP 限制阈值',
                'value' => '20',
                'props' => [
                    'placeholder' => '请输入 IP 限制阈值',
                ],
            ],
            [
                'type' => 'input',
                'field' => 'risk_order_limit',
                'title' => '订单限制阈值',
                'value' => '100',
                'props' => [
                    'placeholder' => '请输入订单限制阈值',
                ],
            ],
            [
                'type' => 'textarea',
                'field' => 'risk_notice',
                'title' => '风控说明',
                'value' => '',
                'props' => [
                    'placeholder' => '请输入风控说明',
                    'autoSize' => [
                        'minRows' => 3,
                        'maxRows' => 6,
                    ],
                ],
            ],
        ],
    ],
    'other' => [
        'title' => '其他配置',
        'icon' => 'icon-folder',
        'description' => '未归类到其它模块的补充配置。',
        'sort' => 8,
        'disabled' => false,
        'rules' => [
            [
                'type' => 'input',
                'field' => 'default_timezone',
                'title' => '默认时区',
                'value' => 'Asia/Shanghai',
                'props' => [
                    'placeholder' => '请输入默认时区',
                ],
            ],
            [
                'type' => 'input',
                'field' => 'default_currency',
                'title' => '默认币种',
                'value' => 'CNY',
                'props' => [
                    'placeholder' => '请输入默认币种',
                ],
            ],
            [
                'type' => 'textarea',
                'field' => 'system_note',
                'title' => '系统备注',
                'value' => '',
                'props' => [
                    'placeholder' => '请输入系统备注',
                    'autoSize' => [
                        'minRows' => 3,
                        'maxRows' => 6,
                    ],
                ],
            ],
        ],
    ],
];
