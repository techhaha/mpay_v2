<?php

/**
 * 增加商户支付渠道进件模块表结构。
 */
return new class {
    public string $version = '202606030001';
    public string $name = 'merchant_channel_onboarding';

    /**
     * 执行迁移。
     *
     * @param \PDO $pdo 数据库连接
     * @return void
     */
    public function up(\PDO $pdo): void
    {
        // 插件表扩展只记录“是否具备进件能力”，不和 pay_types / transfer_types 混用。
        if (!$this->columnExists($pdo, 'ma_payment_plugin', 'onboarding_types')) {
            $pdo->exec(
                "ALTER TABLE `ma_payment_plugin` " .
                "ADD COLUMN `onboarding_types` json DEFAULT NULL COMMENT '进件主体类型声明' AFTER `transfer_types`"
            );
        }

        // 进件能力详情包含表单 schema、配置 schema、产品范围和 OCR 占位信息。
        if (!$this->columnExists($pdo, 'ma_payment_plugin', 'onboarding_info')) {
            $pdo->exec(
                "ALTER TABLE `ma_payment_plugin` " .
                "ADD COLUMN `onboarding_info` json DEFAULT NULL COMMENT '进件能力元信息' AFTER `onboarding_types`"
            );
        }

        // 进件配置表对应后台“商户可申请的进件渠道”，同一插件可创建多条独立配置。
        if (!$this->tableExists($pdo, 'ma_payment_plugin_onboarding_conf')) {
            $pdo->exec(<<<'SQL'
CREATE TABLE `ma_payment_plugin_onboarding_conf` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `plugin_code` varchar(32) NOT NULL DEFAULT '' COMMENT '插件编码',
  `name` varchar(100) NOT NULL DEFAULT '' COMMENT '进件配置/渠道名称',
  `config` json DEFAULT NULL COMMENT '进件接口配置',
  `subject_types` json DEFAULT NULL COMMENT '允许主体类型',
  `apply_products` json DEFAULT NULL COMMENT '允许申请产品',
  `rate_config` json DEFAULT NULL COMMENT '后台预设费率与结算参数',
  `merchant_visible` tinyint NOT NULL DEFAULT 1 COMMENT '商户端是否可见：0-否,1-是',
  `status` tinyint NOT NULL DEFAULT 1 COMMENT '状态：0-禁用,1-启用',
  `sort_no` int NOT NULL DEFAULT 0 COMMENT '排序，越小越靠前',
  `description` text DEFAULT NULL COMMENT '商户端展示说明',
  `remark` varchar(500) NOT NULL DEFAULT '' COMMENT '备注',
  `created_at` datetime DEFAULT NULL COMMENT '创建时间',
  `updated_at` datetime DEFAULT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_plugin_status` (`plugin_code`, `status`),
  KEY `idx_visible_status_sort` (`merchant_visible`, `status`, `sort_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='支付插件进件配置表'
SQL);
        }

        // 申请表保存平台审核和上游签约结果；签约成功不自动生成支付通道。
        if (!$this->tableExists($pdo, 'ma_merchant_channel_onboarding')) {
            $pdo->exec(<<<'SQL'
CREATE TABLE `ma_merchant_channel_onboarding` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `onboarding_no` varchar(32) NOT NULL DEFAULT '' COMMENT '进件申请单号',
  `merchant_id` bigint unsigned NOT NULL DEFAULT 0 COMMENT '所属商户ID',
  `merchant_no` varchar(64) NOT NULL DEFAULT '' COMMENT '商户编号',
  `onboarding_config_id` bigint unsigned NOT NULL DEFAULT 0 COMMENT '插件进件配置ID',
  `plugin_code` varchar(32) NOT NULL DEFAULT '' COMMENT '插件编码',
  `subject_type` varchar(32) NOT NULL DEFAULT '' COMMENT '主体类型',
  `apply_products` json DEFAULT NULL COMMENT '申请产品',
  `form_data` json DEFAULT NULL COMMENT '进件表单数据',
  `file_assets` json DEFAULT NULL COMMENT '文件资产引用',
  `rate_config` json DEFAULT NULL COMMENT '费率与结算参数快照',
  `status` tinyint NOT NULL DEFAULT 0 COMMENT '进件状态',
  `platform_audit_msg` varchar(1000) NOT NULL DEFAULT '' COMMENT '平台审核意见',
  `upstream_apply_id` varchar(128) NOT NULL DEFAULT '' COMMENT '上游申请单号',
  `upstream_contract_id` varchar(128) NOT NULL DEFAULT '' COMMENT '上游合同号',
  `upstream_merchant_no` varchar(128) NOT NULL DEFAULT '' COMMENT '上游商户号',
  `upstream_terminal_no` varchar(128) NOT NULL DEFAULT '' COMMENT '上游终端号',
  `upstream_status` varchar(64) NOT NULL DEFAULT '' COMMENT '上游状态',
  `upstream_message` varchar(1000) NOT NULL DEFAULT '' COMMENT '上游消息',
  `submitted_at` datetime DEFAULT NULL COMMENT '商户提交时间',
  `reviewed_at` datetime DEFAULT NULL COMMENT '平台审核时间',
  `upstream_submitted_at` datetime DEFAULT NULL COMMENT '提交上游时间',
  `signed_at` datetime DEFAULT NULL COMMENT '签约成功时间',
  `cancelled_at` datetime DEFAULT NULL COMMENT '取消时间',
  `remark` varchar(500) NOT NULL DEFAULT '' COMMENT '备注',
  `created_at` datetime DEFAULT NULL COMMENT '创建时间',
  `updated_at` datetime DEFAULT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_onboarding_no` (`onboarding_no`),
  KEY `idx_merchant_config_status` (`merchant_id`, `onboarding_config_id`, `status`),
  KEY `idx_plugin_status` (`plugin_code`, `status`),
  KEY `idx_upstream_apply_id` (`upstream_apply_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='商户支付渠道进件申请表'
SQL);
        }

        // 日志表只保存处理摘要和脱敏扩展信息，避免落完整上游报文。
        if (!$this->tableExists($pdo, 'ma_merchant_channel_onboarding_log')) {
            $pdo->exec(<<<'SQL'
CREATE TABLE `ma_merchant_channel_onboarding_log` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `onboarding_id` bigint unsigned NOT NULL DEFAULT 0 COMMENT '进件申请ID',
  `onboarding_no` varchar(32) NOT NULL DEFAULT '' COMMENT '进件申请单号',
  `merchant_id` bigint unsigned NOT NULL DEFAULT 0 COMMENT '所属商户ID',
  `onboarding_config_id` bigint unsigned NOT NULL DEFAULT 0 COMMENT '插件进件配置ID',
  `plugin_code` varchar(32) NOT NULL DEFAULT '' COMMENT '插件编码',
  `action` varchar(64) NOT NULL DEFAULT '' COMMENT '动作',
  `operator_type` varchar(32) NOT NULL DEFAULT '' COMMENT '操作人类型',
  `operator_id` bigint unsigned NOT NULL DEFAULT 0 COMMENT '操作人ID',
  `operator_name` varchar(100) NOT NULL DEFAULT '' COMMENT '操作人名称',
  `request_no` varchar(64) NOT NULL DEFAULT '' COMMENT '请求流水号',
  `upstream_apply_id` varchar(128) NOT NULL DEFAULT '' COMMENT '上游申请单号',
  `upstream_status` varchar(64) NOT NULL DEFAULT '' COMMENT '上游状态',
  `result_status` varchar(32) NOT NULL DEFAULT '' COMMENT '处理结果',
  `result_code` varchar(64) NOT NULL DEFAULT '' COMMENT '结果编码',
  `message` varchar(1000) NOT NULL DEFAULT '' COMMENT '摘要消息',
  `summary` json DEFAULT NULL COMMENT '摘要扩展信息',
  `created_at` datetime DEFAULT NULL COMMENT '创建时间',
  `updated_at` datetime DEFAULT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_onboarding_id` (`onboarding_id`),
  KEY `idx_onboarding_no` (`onboarding_no`),
  KEY `idx_merchant_id` (`merchant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='商户支付渠道进件日志表'
SQL);
        }
    }

    /**
     * 判断数据表是否存在，用于保证迁移重复执行安全。
     */
    private function tableExists(\PDO $pdo, string $table): bool
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $statement->execute([$table]);

        return (int) $statement->fetchColumn() > 0;
    }

    /**
     * 判断字段是否存在，用于兼容已有库升级场景。
     */
    private function columnExists(\PDO $pdo, string $table, string $column): bool
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $statement->execute([$table, $column]);

        return (int) $statement->fetchColumn() > 0;
    }
};
