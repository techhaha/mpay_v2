-- 系统配置表
CREATE TABLE IF NOT EXISTS `ma_system_config` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `config_key` varchar(100) NOT NULL DEFAULT '' COMMENT '配置项键名（唯一标识，直接使用字段名）',
  `config_value` text COMMENT '配置项值（支持字符串、数字、JSON等）',
  `created_at` datetime DEFAULT NULL COMMENT '创建时间',
  `updated_at` datetime DEFAULT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_config_key` (`config_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='系统配置表';

