-- Current schema alignment for the updated merchant / merchant-app model.
-- Target DB: MySQL 5.7+

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- =========================================================
-- 1. Expand ma_mer for admin + merchant backend scenarios
-- =========================================================
ALTER TABLE `ma_mer`
    ADD COLUMN `merchant_short_name` varchar(60) NOT NULL DEFAULT '' COMMENT '商户简称' AFTER `merchant_name`,
    ADD COLUMN `merchant_type` varchar(20) NOT NULL DEFAULT 'company' COMMENT '商户类型：company/individual/other' AFTER `merchant_short_name`,
    ADD COLUMN `group_code` varchar(32) NOT NULL DEFAULT '' COMMENT '商户分组编码' AFTER `merchant_type`,
    ADD COLUMN `legal_name` varchar(100) NOT NULL DEFAULT '' COMMENT '法人姓名' AFTER `funds_mode`,
    ADD COLUMN `contact_name` varchar(50) NOT NULL DEFAULT '' COMMENT '联系人姓名' AFTER `legal_name`,
    ADD COLUMN `contact_phone` varchar(20) NOT NULL DEFAULT '' COMMENT '联系人手机号' AFTER `contact_name`,
    ADD COLUMN `contact_email` varchar(100) NOT NULL DEFAULT '' COMMENT '联系人邮箱' AFTER `contact_phone`,
    ADD COLUMN `website` varchar(255) NOT NULL DEFAULT '' COMMENT '商户官网' AFTER `contact_email`,
    ADD COLUMN `province` varchar(50) NOT NULL DEFAULT '' COMMENT '省份' AFTER `website`,
    ADD COLUMN `city` varchar(50) NOT NULL DEFAULT '' COMMENT '城市' AFTER `province`,
    ADD COLUMN `address` varchar(255) NOT NULL DEFAULT '' COMMENT '详细地址' AFTER `city`,
    ADD COLUMN `callback_domain` varchar(255) NOT NULL DEFAULT '' COMMENT '回调域名' AFTER `address`,
    ADD COLUMN `callback_ip_whitelist` text COMMENT '回调IP白名单' AFTER `callback_domain`,
    ADD COLUMN `risk_level` varchar(20) NOT NULL DEFAULT 'standard' COMMENT '风控等级：low/standard/high' AFTER `callback_ip_whitelist`,
    ADD COLUMN `settlement_mode` varchar(20) NOT NULL DEFAULT 'auto' COMMENT '结算方式：auto/manual' AFTER `risk_level`,
    ADD COLUMN `settlement_cycle` varchar(20) NOT NULL DEFAULT 't1' COMMENT '结算周期：d0/t1/manual' AFTER `settlement_mode`,
    ADD COLUMN `settlement_account_name` varchar(100) NOT NULL DEFAULT '' COMMENT '结算账户名' AFTER `settlement_cycle`,
    ADD COLUMN `settlement_account_no` varchar(100) NOT NULL DEFAULT '' COMMENT '结算账户号' AFTER `settlement_account_name`,
    ADD COLUMN `settlement_bank_name` varchar(100) NOT NULL DEFAULT '' COMMENT '结算银行名称' AFTER `settlement_account_no`,
    ADD COLUMN `settlement_bank_branch` varchar(100) NOT NULL DEFAULT '' COMMENT '结算支行名称' AFTER `settlement_bank_name`,
    ADD COLUMN `single_limit` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '单笔限额（元）' AFTER `settlement_bank_branch`,
    ADD COLUMN `daily_limit` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '日限额（元）' AFTER `single_limit`,
    ADD COLUMN `remark` varchar(500) NOT NULL DEFAULT '' COMMENT '备注' AFTER `daily_limit`,
    ADD COLUMN `extra` json DEFAULT NULL COMMENT '扩展字段(JSON)' AFTER `remark`;

ALTER TABLE `ma_mer`
    ADD KEY `idx_group_code` (`group_code`),
    ADD KEY `idx_contact_phone` (`contact_phone`),
    ADD KEY `idx_status` (`status`);

-- =========================================================
-- 2. Expand ma_pay_app for merchant app backend settings
-- =========================================================
ALTER TABLE `ma_pay_app`
    ADD COLUMN `package_code` varchar(32) NOT NULL DEFAULT '' COMMENT '商户套餐编码' AFTER `app_name`,
    ADD COLUMN `notify_url` varchar(255) NOT NULL DEFAULT '' COMMENT '异步通知地址' AFTER `package_code`,
    ADD COLUMN `return_url` varchar(255) NOT NULL DEFAULT '' COMMENT '同步跳转地址' AFTER `notify_url`,
    ADD COLUMN `callback_mode` varchar(20) NOT NULL DEFAULT 'server' COMMENT '回调模式：server/server+page/manual' AFTER `return_url`,
    ADD COLUMN `sign_type` varchar(20) NOT NULL DEFAULT 'md5' COMMENT '签名方式' AFTER `callback_mode`,
    ADD COLUMN `order_expire_minutes` int(11) NOT NULL DEFAULT 30 COMMENT '订单超时时间（分钟）' AFTER `sign_type`,
    ADD COLUMN `callback_retry_limit` int(11) NOT NULL DEFAULT 6 COMMENT '回调重试次数' AFTER `order_expire_minutes`,
    ADD COLUMN `ip_whitelist` text COMMENT 'IP白名单' AFTER `callback_retry_limit`,
    ADD COLUMN `amount_min` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '单笔最小金额' AFTER `ip_whitelist`,
    ADD COLUMN `amount_max` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '单笔最大金额' AFTER `amount_min`,
    ADD COLUMN `daily_limit` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '日限额' AFTER `amount_max`,
    ADD COLUMN `notify_enabled` tinyint(1) NOT NULL DEFAULT 1 COMMENT '是否开启通知：0-否,1-是' AFTER `daily_limit`,
    ADD COLUMN `remark` varchar(500) NOT NULL DEFAULT '' COMMENT '备注' AFTER `notify_enabled`,
    ADD COLUMN `extra` json DEFAULT NULL COMMENT '扩展字段(JSON)' AFTER `remark`;

ALTER TABLE `ma_pay_app`
    ADD KEY `idx_api_type` (`api_type`),
    ADD KEY `idx_status` (`status`);

-- =========================================================
-- 3. Merchant backend user table
-- =========================================================
CREATE TABLE IF NOT EXISTS `ma_mer_user` (
    `id` bigint unsigned NOT NULL AUTO_INCREMENT COMMENT '主键ID',
    `mer_id` bigint unsigned NOT NULL DEFAULT 0 COMMENT '商户ID',
    `username` varchar(50) NOT NULL DEFAULT '' COMMENT '登录账号',
    `password` varchar(255) DEFAULT NULL COMMENT '登录密码hash',
    `nick_name` varchar(50) NOT NULL DEFAULT '' COMMENT '昵称',
    `avatar` varchar(255) NOT NULL DEFAULT '' COMMENT '头像地址',
    `mobile` varchar(20) NOT NULL DEFAULT '' COMMENT '手机号',
    `email` varchar(100) NOT NULL DEFAULT '' COMMENT '邮箱',
    `role_code` varchar(32) NOT NULL DEFAULT 'owner' COMMENT '角色编码',
    `is_owner` tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否商户主账号：0-否,1-是',
    `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '状态：0-禁用,1-启用',
    `login_ip` varchar(45) NOT NULL DEFAULT '' COMMENT '最后登录IP',
    `login_at` datetime DEFAULT NULL COMMENT '最后登录时间',
    `created_at` datetime DEFAULT NULL COMMENT '创建时间',
    `updated_at` datetime DEFAULT NULL COMMENT '更新时间',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_username` (`username`),
    KEY `idx_mer_id` (`mer_id`),
    KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='商户后台用户表';

-- =========================================================
-- 4. Compatibility views for legacy code paths
-- =========================================================
DROP VIEW IF EXISTS `ma_merchant`;
CREATE VIEW `ma_merchant` AS
SELECT
    `id`,
    `merchant_no`,
    `merchant_name`,
    `merchant_short_name`,
    `merchant_type`,
    `group_code`,
    `funds_mode`,
    `legal_name`,
    `contact_name`,
    `contact_phone`,
    `contact_email`,
    `website`,
    `province`,
    `city`,
    `address`,
    `callback_domain`,
    `callback_ip_whitelist`,
    `risk_level`,
    `settlement_mode`,
    `settlement_cycle`,
    `settlement_account_name`,
    `settlement_account_no`,
    `settlement_bank_name`,
    `settlement_bank_branch`,
    `single_limit`,
    `daily_limit`,
    `status`,
    `remark`,
    `extra`,
    `created_at`,
    `updated_at`
FROM `ma_mer`;

DROP VIEW IF EXISTS `ma_merchant_app`;
CREATE VIEW `ma_merchant_app` AS
SELECT
    `id`,
    `mer_id` AS `merchant_id`,
    `api_type`,
    `app_code` AS `app_id`,
    `app_secret`,
    `app_name`,
    `package_code`,
    `notify_url`,
    `return_url`,
    `callback_mode`,
    `sign_type`,
    `order_expire_minutes`,
    `callback_retry_limit`,
    `ip_whitelist`,
    `amount_min`,
    `amount_max`,
    `daily_limit`,
    `notify_enabled`,
    `status`,
    `remark`,
    `extra`,
    `created_at`,
    `updated_at`
FROM `ma_pay_app`;

DROP VIEW IF EXISTS `ma_pay_method`;
CREATE VIEW `ma_pay_method` AS
SELECT
    `id`,
    `type` AS `method_code`,
    `name` AS `method_name`,
    `icon`,
    `sort`,
    `status`,
    `created_at`,
    `updated_at`
FROM `ma_pay_type`;

SET FOREIGN_KEY_CHECKS = 1;
