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

-- 5) 插件注册表（按项目约定：app\\common\\payment\\{Code}Payment）
INSERT INTO `ma_pay_plugin` (`plugin_code`, `plugin_name`, `class_name`, `status`, `created_at`, `updated_at`)
VALUES ('lakala', '拉卡拉（示例）', 'app\\\\common\\\\payment\\\\LakalaPayment', 1, NOW(), NOW())
ON DUPLICATE KEY UPDATE
  `plugin_name` = VALUES(`plugin_name`),
  `class_name`  = VALUES(`class_name`),
  `status`      = VALUES(`status`),
  `updated_at`  = NOW();

