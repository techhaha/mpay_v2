-- ============================================
-- 支付系统核心表结构（优化版）
-- ============================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- =======================
-- 1. 商户表
-- =======================
DROP TABLE IF EXISTS `ma_merchant`;
CREATE TABLE `ma_merchant` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `merchant_no` varchar(32) NOT NULL DEFAULT '' COMMENT '商户号（唯一，对外标识）',
  `merchant_name` varchar(100) NOT NULL DEFAULT '' COMMENT '商户名称',
  `funds_mode` varchar(20) NOT NULL DEFAULT 'direct' COMMENT '资金模式：direct-直连, wallet-归集, hybrid-混合',
  `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '状态：0-禁用, 1-启用',
  `created_at` datetime DEFAULT NULL COMMENT '创建时间',
  `updated_at` datetime DEFAULT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_merchant_no` (`merchant_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='商户表';

-- =======================
-- 2. 商户应用表
-- =======================
DROP TABLE IF EXISTS `ma_merchant_app`;
CREATE TABLE `ma_merchant_app` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `merchant_id` bigint unsigned NOT NULL DEFAULT 0 COMMENT '商户ID',
  `api_type` varchar(32) NOT NULL DEFAULT 'default' COMMENT '接口类型：openapi, epay, custom 等',
  `app_id` varchar(64) NOT NULL DEFAULT '' COMMENT '应用ID',
  `app_secret` varchar(128) NOT NULL DEFAULT '' COMMENT '应用密钥',
  `app_name` varchar(100) NOT NULL DEFAULT '' COMMENT '应用名称',
  `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '状态：0-禁用, 1-启用',
  `created_at` datetime DEFAULT NULL COMMENT '创建时间',
  `updated_at` datetime DEFAULT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_app_id` (`app_id`),
  KEY `idx_merchant_id` (`merchant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='商户应用表';

-- =======================
-- 3. 支付方式字典表
-- =======================
DROP TABLE IF EXISTS `ma_pay_method`;
CREATE TABLE `ma_pay_method` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `method_code` varchar(32) NOT NULL DEFAULT '' COMMENT '支付方式编码，如 alipay,wechat',
  `method_name` varchar(50) NOT NULL DEFAULT '' COMMENT '支付方式名称',
  `icon` varchar(255) NOT NULL DEFAULT '' COMMENT '图标',
  `sort` int(11) NOT NULL DEFAULT 0 COMMENT '排序',
  `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '状态：0-禁用, 1-启用',
  `created_at` datetime DEFAULT NULL COMMENT '创建时间',
  `updated_at` datetime DEFAULT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_method_code` (`method_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='支付方式字典表';

-- =======================
-- 4. 支付插件注册表
-- =======================
DROP TABLE IF EXISTS `ma_pay_plugin`;
CREATE TABLE `ma_pay_plugin` (
  `plugin_code` varchar(32) NOT NULL DEFAULT '' COMMENT '插件编码（主键）',
  `plugin_name` varchar(50) NOT NULL DEFAULT '' COMMENT '插件名称',
  `class_name` varchar(255) NOT NULL DEFAULT '' COMMENT '插件类名（完整命名空间）',
  `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '状态：0-禁用, 1-启用',
  `created_at` datetime DEFAULT NULL COMMENT '创建时间',
  `updated_at` datetime DEFAULT NULL COMMENT '更新时间',
  PRIMARY KEY (`plugin_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='支付插件注册表';

-- =======================
-- 5. 支付通道表
-- =======================
DROP TABLE IF EXISTS `ma_pay_channel`;
CREATE TABLE `ma_pay_channel` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `merchant_id` bigint unsigned NOT NULL DEFAULT 0 COMMENT '商户ID（冗余，方便统计）',
  `merchant_app_id` bigint unsigned NOT NULL DEFAULT 0 COMMENT '商户应用ID（关联 ma_merchant_app.id）',
  `chan_code` varchar(32) NOT NULL DEFAULT '' COMMENT '通道编码（唯一）',
  `chan_name` varchar(100) NOT NULL DEFAULT '' COMMENT '通道显示名称',
  `plugin_code` varchar(32) NOT NULL DEFAULT '' COMMENT '支付插件编码',
  `method_id` bigint unsigned NOT NULL DEFAULT 0 COMMENT '支付方式ID（关联 ma_pay_method.id）',
  `config_json` json DEFAULT NULL COMMENT '通道插件配置参数（JSON，对应插件配置，包括 enabled_products 等）',
  `split_ratio` decimal(5,2) NOT NULL DEFAULT 100.00 COMMENT '分成比例（%）',
  `chan_cost` decimal(5,2) NOT NULL DEFAULT 0.00 COMMENT '通道成本（%）',
  `chan_mode` varchar(50) NOT NULL DEFAULT 'wallet' COMMENT '通道模式：wallet-入余额, direct-直连到商户',
  `daily_limit` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '单日限额（元，0表示不限制）',
  `daily_cnt` int(11) NOT NULL DEFAULT 0 COMMENT '单日限笔（0表示不限制）',
  `min_amount` decimal(12,2) DEFAULT NULL COMMENT '单笔最小金额（元，NULL表示不限制）',
  `max_amount` decimal(12,2) DEFAULT NULL COMMENT '单笔最大金额（元，NULL表示不限制）',
  `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '状态：0-禁用, 1-启用',
  `sort` int(11) NOT NULL DEFAULT 0 COMMENT '排序，越小优先级越高',
  `created_at` datetime DEFAULT NULL COMMENT '创建时间',
  `updated_at` datetime DEFAULT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_chan_code` (`chan_code`),
  KEY `idx_mch_app_method` (`merchant_id`,`merchant_app_id`,`method_id`,`status`,`sort`),
  KEY `idx_plugin_method` (`plugin_code`,`method_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='支付通道表';

-- =======================
-- 6. 支付订单表
-- =======================
DROP TABLE IF EXISTS `ma_pay_order`;
CREATE TABLE `ma_pay_order` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `order_id` varchar(32) NOT NULL DEFAULT '' COMMENT '支付订单号（系统生成，唯一）',
  `merchant_id` bigint unsigned NOT NULL DEFAULT 0 COMMENT '商户ID',
  `merchant_app_id` bigint unsigned NOT NULL DEFAULT 0 COMMENT '商户应用ID',
  `mch_order_no` varchar(64) NOT NULL DEFAULT '' COMMENT '商户订单号（幂等）',
  `method_id` bigint unsigned NOT NULL DEFAULT 0 COMMENT '支付方式ID（关联 ma_pay_method.id）',
  `channel_id` bigint unsigned NOT NULL DEFAULT 0 COMMENT '支付通道ID（关联 ma_pay_channel.id）',
  `amount` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '订单金额（元）',
  `real_amount` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '实际支付金额（元，扣除手续费后）',
  `fee` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '手续费（元，可选，用于对账）',
  `currency` varchar(3) NOT NULL DEFAULT 'CNY' COMMENT '币种，如 CNY',
  `subject` varchar(255) NOT NULL DEFAULT '' COMMENT '订单标题',
  `body` varchar(500) NOT NULL DEFAULT '' COMMENT '订单描述',
  `status` tinyint(1) NOT NULL DEFAULT 0 COMMENT '订单状态：0-PENDING,1-SUCCESS,2-FAIL,3-CLOSED',
  `chan_order_no` varchar(128) NOT NULL DEFAULT '' COMMENT '渠道订单号（渠道返回）',
  `chan_trade_no` varchar(128) NOT NULL DEFAULT '' COMMENT '渠道交易号（部分渠道有）',
  `pay_at` datetime DEFAULT NULL COMMENT '支付时间',
  `expire_at` datetime DEFAULT NULL COMMENT '订单过期时间',
  `client_ip` varchar(50) NOT NULL DEFAULT '' COMMENT '客户端IP',
  `notify_stat` tinyint(1) NOT NULL DEFAULT 0 COMMENT '商户通知状态：0-未通知,1-已通知成功',
  `notify_cnt` int(11) NOT NULL DEFAULT 0 COMMENT '通知次数',
  `extra` json DEFAULT NULL COMMENT '扩展字段（JSON，存储支付参数、退款信息等）',
  `created_at` datetime DEFAULT NULL COMMENT '创建时间',
  `updated_at` datetime DEFAULT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_order_id` (`order_id`),
  UNIQUE KEY `uk_mch_order` (`merchant_id`,`merchant_app_id`,`mch_order_no`),
  KEY `idx_mch_app_created` (`merchant_id`,`merchant_app_id`,`created_at`),
  KEY `idx_method_id` (`method_id`),
  KEY `idx_channel_id` (`channel_id`),
  KEY `idx_status_created` (`status`,`created_at`),
  KEY `idx_pay_at` (`pay_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='支付订单表';

-- =======================
-- 7. 支付回调日志表
-- =======================
DROP TABLE IF EXISTS `ma_pay_callback_log`;
CREATE TABLE `ma_pay_callback_log` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `order_id` varchar(32) NOT NULL DEFAULT '' COMMENT '支付订单号（系统订单号）',
  `channel_id` bigint unsigned NOT NULL DEFAULT 0 COMMENT '通道ID（关联 ma_pay_channel.id）',
  `callback_type` varchar(20) NOT NULL DEFAULT '' COMMENT '回调类型：notify-异步通知, return-同步返回',
  `request_data` text COMMENT '请求原始数据（完整回调参数）',
  `verify_status` tinyint(1) NOT NULL DEFAULT 0 COMMENT '验签状态：0-失败,1-成功',
  `process_status` tinyint(1) NOT NULL DEFAULT 0 COMMENT '处理状态：0-未处理,1-已处理',
  `process_result` text COMMENT '处理结果（JSON或文本）',
  `created_at` datetime DEFAULT NULL COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_order_created` (`order_id`,`created_at`),
  KEY `idx_channel_created` (`channel_id`,`created_at`),
  KEY `idx_callback_type` (`callback_type`),
  KEY `idx_verify_status` (`verify_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='支付回调日志表';

-- =======================
-- 8. 商户通知任务表
-- =======================
DROP TABLE IF EXISTS `ma_notify_task`;
CREATE TABLE `ma_notify_task` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `order_id` varchar(32) NOT NULL DEFAULT '' COMMENT '支付订单号（系统订单号）',
  `merchant_id` bigint unsigned NOT NULL DEFAULT 0 COMMENT '商户ID',
  `merchant_app_id` bigint unsigned NOT NULL DEFAULT 0 COMMENT '商户应用ID',
  `notify_url` varchar(255) NOT NULL DEFAULT '' COMMENT '通知地址',
  `notify_data` text COMMENT '通知数据（JSON格式）',
  `status` varchar(20) NOT NULL DEFAULT 'PENDING' COMMENT '状态：PENDING-待通知,SUCCESS-成功,FAIL-失败',
  `retry_cnt` int(11) NOT NULL DEFAULT 0 COMMENT '重试次数',
  `next_retry_at` datetime DEFAULT NULL COMMENT '下次重试时间',
  `last_notify_at` datetime DEFAULT NULL COMMENT '最后通知时间',
  `last_response` text COMMENT '最后响应内容',
  `created_at` datetime DEFAULT NULL COMMENT '创建时间',
  `updated_at` datetime DEFAULT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_order_id` (`order_id`),
  KEY `idx_status_retry` (`status`,`next_retry_at`),
  KEY `idx_mch_app` (`merchant_id`,`merchant_app_id`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='商户通知任务表';

-- =======================
-- 9. 系统配置表
-- =======================
DROP TABLE IF EXISTS `ma_system_config`;
CREATE TABLE IF NOT EXISTS `ma_system_config` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `config_key` varchar(100) NOT NULL DEFAULT '' COMMENT '配置项键名（唯一标识，直接使用字段名）',
  `config_value` text COMMENT '配置项值（支持字符串、数字、JSON等）',
  `created_at` datetime DEFAULT NULL COMMENT '创建时间',
  `updated_at` datetime DEFAULT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_config_key` (`config_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='系统配置表';

-- =======================
-- 10. 初始化基础数据
-- =======================

-- 初始化支付方式字典
INSERT INTO `ma_pay_method` (`method_code`, `method_name`, `icon`, `sort`, `status`) VALUES
('alipay',  '支付宝',    '', 1, 1),
('wechat',  '微信支付',  '', 2, 1),
('unionpay','云闪付',    '', 3, 1)
ON DUPLICATE KEY UPDATE
  `method_name` = VALUES(`method_name`),
  `icon`        = VALUES(`icon`),
  `sort`        = VALUES(`sort`),
  `status`      = VALUES(`status`);

-- =======================
-- 11. 管理员用户表（ma_admin）
-- =======================

DROP TABLE IF EXISTS `ma_admin`;
CREATE TABLE `ma_admin` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `user_name` varchar(50) NOT NULL DEFAULT '' COMMENT '用户名（登录账号，唯一）',
  `password` varchar(255) DEFAULT NULL COMMENT '登录密码hash（NULL 或空表示使用默认开发密码）',
  `nick_name` varchar(50) NOT NULL DEFAULT '' COMMENT '昵称',
  `avatar` varchar(255) NOT NULL DEFAULT '' COMMENT '头像地址',
  `mobile` varchar(20) NOT NULL DEFAULT '' COMMENT '手机号',
  `email` varchar(100) NOT NULL DEFAULT '' COMMENT '邮箱',
  `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '状态：0-禁用, 1-启用',
  `login_ip` varchar(45) NOT NULL DEFAULT '' COMMENT '最后登录IP',
  `login_at` datetime DEFAULT NULL COMMENT '最后登录时间',
  `created_at` datetime DEFAULT NULL COMMENT '创建时间',
  `updated_at` datetime DEFAULT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_name` (`user_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='管理员用户表';

-- 初始化一个超级管理员账号（开发环境默认密码 123456，对应 AuthService::validatePassword 逻辑）
INSERT INTO `ma_admin` (`user_name`, `password`, `nick_name`, `status`, `created_at`)
VALUES ('admin', NULL, '超级管理员', 1, NOW())
ON DUPLICATE KEY UPDATE
  `nick_name` = VALUES(`nick_name`),
  `status`    = VALUES(`status`);

SET FOREIGN_KEY_CHECKS = 1;