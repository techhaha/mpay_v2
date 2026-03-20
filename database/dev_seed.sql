-- 开发环境初始化数据（可重复执行）
SET NAMES utf8mb4;

-- 1) 管理员（若已有则跳过）
INSERT INTO `ma_admin` (`user_name`, `password`, `nick_name`, `status`, `created_at`)
VALUES ('admin', NULL, '超级管理员', 1, NOW())
ON DUPLICATE KEY UPDATE
  `nick_name` = VALUES(`nick_name`),
  `status`    = VALUES(`status`);

-- 2) 商户
INSERT INTO `ma_merchant` (`merchant_no`, `merchant_name`, `funds_mode`, `status`, `created_at`, `updated_at`)
VALUES ('M001', '测试商户', 'direct', 1, NOW(), NOW())
ON DUPLICATE KEY UPDATE
  `merchant_name` = VALUES(`merchant_name`),
  `funds_mode`    = VALUES(`funds_mode`),
  `status`        = VALUES(`status`),
  `updated_at`    = NOW();

-- 3) 商户应用（pid=app_id 约定：这里 app_id 使用纯数字字符串，方便易支付测试）
INSERT INTO `ma_merchant_app` (`merchant_id`, `api_type`, `app_id`, `app_secret`, `app_name`, `status`, `created_at`, `updated_at`)
SELECT m.id, 'epay', '1001', 'dev_secret_1001', '测试应用-易支付', 1, NOW(), NOW()
FROM `ma_merchant` m
WHERE m.merchant_no = 'M001'
ON DUPLICATE KEY UPDATE
  `app_secret` = VALUES(`app_secret`),
  `app_name`   = VALUES(`app_name`),
  `status`     = VALUES(`status`),
  `updated_at` = NOW();

-- 4) 支付方式
INSERT INTO `ma_pay_method` (`method_code`, `method_name`, `icon`, `sort`, `status`, `created_at`, `updated_at`) VALUES
('alipay',  '支付宝',    '', 1, 1, NOW(), NOW()),
('wechat',  '微信支付',  '', 2, 1, NOW(), NOW()),
('unionpay','云闪付',    '', 3, 1, NOW(), NOW())
ON DUPLICATE KEY UPDATE
  `method_name` = VALUES(`method_name`),
  `icon`        = VALUES(`icon`),
  `sort`        = VALUES(`sort`),
  `status`      = VALUES(`status`),
  `updated_at`  = NOW();

-- 5) 插件注册表（按项目约定：类名短写，如 AlipayPayment）
INSERT INTO `ma_pay_plugin` (`code`, `name`, `class_name`, `status`, `created_at`, `updated_at`)
VALUES 
  ('lakala', '拉卡拉（示例）', 'LakalaPayment', 1, NOW(), NOW()),
  ('alipay',  '支付宝直连',   'AlipayPayment', 1, NOW(), NOW())
ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`),
  `class_name`  = VALUES(`class_name`),
  `status`      = VALUES(`status`),
  `updated_at`  = NOW();
 
-- 6) 系统配置（开发环境默认配置，可根据需要修改）
INSERT INTO `ma_system_config` (`config_key`, `config_value`, `created_at`, `updated_at`) VALUES
('site_name',              'Mpay',                NOW(), NOW()),
('site_description',       '码支付',              NOW(), NOW()),
('site_logo',              '',                    NOW(), NOW()),
('icp_number',             '',                    NOW(), NOW()),
('site_status',            '1',                   NOW(), NOW()),
('page_size',              '10',                  NOW(), NOW()),
('enable_permission',      '1',                   NOW(), NOW()),
('session_timeout',        '15',                  NOW(), NOW()),
('password_min_length',    '8',                   NOW(), NOW()),
('require_strong_password','1',                   NOW(), NOW()),
('max_login_attempts',     '5',                   NOW(), NOW()),
('lockout_duration',       '30',                  NOW(), NOW()),
('smtp_host',              'smtp.example.com',    NOW(), NOW()),
('smtp_port',              '465',                 NOW(), NOW()),
('smtp_ssl',               '1',                   NOW(), NOW()),
('smtp_username',          'noreply@example.com', NOW(), NOW()),
('smtp_password',          'dev_smtp_password',   NOW(), NOW()),
('from_email',             'noreply@example.com', NOW(), NOW()),
('from_name',              'Mpay',                NOW(), NOW())
ON DUPLICATE KEY UPDATE
  `config_value` = VALUES(`config_value`),
  `updated_at`   = NOW();

-- 7) 支付通道（为测试商户 M001 / 应用 1001 初始化拉卡拉通道）
INSERT INTO `ma_pay_channel` (
  `merchant_id`,
  `merchant_app_id`,
  `chan_code`,
  `chan_name`,
  `plugin_code`,
  `method_id`,
  `config_json`,
  `split_ratio`,
  `chan_cost`,
  `chan_mode`,
  `daily_limit`,
  `daily_cnt`,
  `min_amount`,
  `max_amount`,
  `status`,
  `sort`,
  `created_at`,
  `updated_at`
)
SELECT
  m.id        AS merchant_id,
  app.id      AS merchant_app_id,
  'lakala_alipay' AS chan_code,
  '拉卡拉-支付宝' AS chan_name,
  'lakala'    AS plugin_code,
  pm.id       AS method_id,
  JSON_OBJECT('notify_url', 'https://example.com/notify') AS config_json,
  100.00      AS split_ratio,
  0.00        AS chan_cost,
  'wallet'    AS chan_mode,
  0.00        AS daily_limit,
  0           AS daily_cnt,
  0.01        AS min_amount,
  NULL        AS max_amount,
  1           AS status,
  10          AS sort,
  NOW()       AS created_at,
  NOW()       AS updated_at
FROM `ma_merchant` m
JOIN `ma_merchant_app` app ON app.merchant_id = m.id AND app.app_id = '1001'
JOIN `ma_pay_method` pm ON pm.method_code = 'alipay'
ON DUPLICATE KEY UPDATE
  `chan_name`   = VALUES(`chan_name`),
  `plugin_code` = VALUES(`plugin_code`),
  `method_id`   = VALUES(`method_id`),
  `config_json` = VALUES(`config_json`),
  `split_ratio` = VALUES(`split_ratio`),
  `chan_cost`   = VALUES(`chan_cost`),
  `chan_mode`   = VALUES(`chan_mode`),
  `daily_limit` = VALUES(`daily_limit`),
  `daily_cnt`   = VALUES(`daily_cnt`),
  `min_amount`  = VALUES(`min_amount`),
  `max_amount`  = VALUES(`max_amount`),
  `status`      = VALUES(`status`),
  `sort`        = VALUES(`sort`),
  `updated_at`  = NOW();

INSERT INTO `ma_pay_channel` (
  `merchant_id`,
  `merchant_app_id`,
  `chan_code`,
  `chan_name`,
  `plugin_code`,
  `method_id`,
  `config_json`,
  `split_ratio`,
  `chan_cost`,
  `chan_mode`,
  `daily_limit`,
  `daily_cnt`,
  `min_amount`,
  `max_amount`,
  `status`,
  `sort`,
  `created_at`,
  `updated_at`
)
SELECT
  m.id        AS merchant_id,
  app.id      AS merchant_app_id,
  'lakala_wechat' AS chan_code,
  '拉卡拉-微信支付' AS chan_name,
  'lakala'    AS plugin_code,
  pm.id       AS method_id,
  JSON_OBJECT('notify_url', 'https://example.com/notify') AS config_json,
  100.00      AS split_ratio,
  0.00        AS chan_cost,
  'wallet'    AS chan_mode,
  0.00        AS daily_limit,
  0           AS daily_cnt,
  0.01        AS min_amount,
  NULL        AS max_amount,
  1           AS status,
  20          AS sort,
  NOW()       AS created_at,
  NOW()       AS updated_at
FROM `ma_merchant` m
JOIN `ma_merchant_app` app ON app.merchant_id = m.id AND app.app_id = '1001'
JOIN `ma_pay_method` pm ON pm.method_code = 'wechat'
ON DUPLICATE KEY UPDATE
  `chan_name`   = VALUES(`chan_name`),
  `plugin_code` = VALUES(`plugin_code`),
  `method_id`   = VALUES(`method_id`),
  `config_json` = VALUES(`config_json`),
  `split_ratio` = VALUES(`split_ratio`),
  `chan_cost`   = VALUES(`chan_cost`),
  `chan_mode`   = VALUES(`chan_mode`),
  `daily_limit` = VALUES(`daily_limit`),
  `daily_cnt`   = VALUES(`daily_cnt`),
  `min_amount`  = VALUES(`min_amount`),
  `max_amount`  = VALUES(`max_amount`),
  `status`      = VALUES(`status`),
  `sort`        = VALUES(`sort`),
  `updated_at`  = NOW();