SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `ma_callback_inbox` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `event_key` char(40) NOT NULL DEFAULT '' COMMENT '幂等事件键（SHA1）',
  `plugin_code` varchar(32) NOT NULL DEFAULT '' COMMENT '插件编码',
  `order_id` varchar(32) NOT NULL DEFAULT '' COMMENT '系统订单号',
  `chan_trade_no` varchar(128) NOT NULL DEFAULT '' COMMENT '渠道交易号',
  `payload` json DEFAULT NULL COMMENT '回调原始数据',
  `process_status` tinyint(1) NOT NULL DEFAULT 0 COMMENT '处理状态：0-待处理 1-已处理',
  `processed_at` datetime DEFAULT NULL COMMENT '处理完成时间',
  `created_at` datetime DEFAULT NULL COMMENT '创建时间',
  `updated_at` datetime DEFAULT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_event_key` (`event_key`),
  KEY `idx_order_id` (`order_id`),
  KEY `idx_plugin_code` (`plugin_code`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='支付回调幂等收件箱';

