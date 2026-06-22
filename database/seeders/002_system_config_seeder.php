<?php

/**
 * 系统配置默认值 Seeder。
 */
return new class {
    public string $name = 'system_config_seeder';

    /**
     * 安装时由用户手动配置或安装流程写入的配置项，不在 seeder 中初始化。
     */
    private const INSTALL_MANAGED_KEYS = [
        'install_status',
        'install_version',
        'install_time',
        'install_agreement_version',
        'site_name',
        'site_url',
    ];

    /**
     * 已改为代码常量维护的历史配置项。
     */
    private const OBSOLETE_KEYS = [
        'file_storage_local_public_dir',
        'file_storage_local_private_dir',
    ];

    /**
     * 基于 v2_mpay_com 初始化的系统配置默认值。
     *
     * @var array<string, array<string, string>>
     */
    private const DEFAULTS = [
        'cashier' => [
            'cashier_enabled' => '1',
            'cashier_logo' => '/assets/brand/mpay-logo.svg',
            'cashier_notice' => '确认支付方式后，系统会创建本次支付尝试并跳转支付页。',
            'cashier_notice_enabled' => '1',
            'cashier_poll_interval_seconds' => '2',
            'cashier_poll_timeout_seconds' => '300',
            'cashier_show_merchant_name' => '1',
            'cashier_show_order_no' => '1',
            'cashier_show_pay_type_desc' => '1',
            'cashier_title' => '收银台',
        ],
        'channel_test' => [
            'channel_test_debug_log_enabled' => '0',
            'channel_test_enabled' => '1',
            'channel_test_merchant_id' => '1000',
            'channel_test_notify_url' => '',
            'channel_test_return_url' => '',
        ],
        'notify' => [
            'pay_callback_log_enabled' => '1',
            'pay_notify_enabled' => '1',
            'pay_notify_request_timeout_seconds' => '10',
            'pay_notify_retry_interval' => '10',
            'pay_notify_retry_limit' => '3',
        ],
        'payment_order' => [
            'pay_order_amount_limit_enabled' => '0',
            'pay_order_attempt_limit' => '5',
            'pay_order_attempt_limit_enabled' => '1',
            'pay_order_expire_minutes' => '30',
            'pay_order_failed_retry_enabled' => '1',
            'pay_order_max_amount_yuan' => '0',
            'pay_order_min_amount_yuan' => '0.01',
            'pay_order_timeout_enabled' => '1',
        ],
        'platform' => [
            'admin_portal_name' => '管理后台',
            'customer_service_email' => '',
            'customer_service_enabled' => '0',
            'customer_service_name' => '',
            'customer_service_phone' => '',
            'merchant_announcement' => '',
            'merchant_announcement_enabled' => '0',
            'merchant_portal_name' => '商户后台',
            'site_logo' => '/assets/brand/mpay-logo.svg',
            'site_logo_compact' => '/assets/brand/mpay-mark.svg',
        ],
        'runtime' => [
            'pay_active_query_batch_size' => '50',
            'pay_active_query_enabled' => '0',
            'pay_active_query_interval_seconds' => '60',
            'pay_active_query_min_age_seconds' => '60',
            'pay_notify_retry_batch_size' => '100',
            'pay_notify_retry_scan_interval_seconds' => '60',
            'pay_order_timeout_batch_size' => '100',
            'pay_order_timeout_scan_interval_seconds' => '60',
            'pay_runtime_enabled' => '1',
            'receipt_watcher_enabled' => '0',
            'receipt_watcher_license_code' => '',
            'receipt_watcher_order_scan_batch_size' => '500',
            'receipt_watcher_order_scan_interval_seconds' => '3',
            'receipt_watcher_prelogin_interval_seconds' => '600',
            'receipt_watcher_login_retry_max' => '10',
            'receipt_watcher_plugin_codes' => "shouqianba_receipt\nfubei_receipt\nusdt_trc20_receipt",
        ],
        'storage' => [
            'file_storage_aliyun_oss_access_key_id' => '',
            'file_storage_aliyun_oss_access_key_secret' => '',
            'file_storage_aliyun_oss_bucket' => '',
            'file_storage_aliyun_oss_endpoint' => '',
            'file_storage_aliyun_oss_public_domain' => '',
            'file_storage_aliyun_oss_region' => '',
            'file_storage_default_engine' => '1',
            'file_storage_local_public_base_url' => '',
            'file_storage_remote_download_limit_mb' => '10',
            'file_storage_tencent_cos_bucket' => '',
            'file_storage_tencent_cos_public_domain' => '',
            'file_storage_tencent_cos_region' => '',
            'file_storage_tencent_cos_secret_id' => '',
            'file_storage_tencent_cos_secret_key' => '',
            'file_storage_upload_max_size_mb' => '20',
        ],
    ];

    /**
     * 写入系统配置默认值。
     *
     * @param \PDO $pdo 数据库连接
     * @param array<string, mixed> $context 安装上下文
     * @return array<string, int> 执行摘要
     */
    public function run(\PDO $pdo, array $context = []): array
    {
        $deleteStatement = $pdo->prepare(
            'DELETE FROM `ma_system_config` WHERE `config_key` IN (?, ?)'
        );
        $deleteStatement->execute(self::OBSOLETE_KEYS);
        $deleted = $deleteStatement->rowCount();

        $statement = $pdo->prepare(
            'INSERT INTO `ma_system_config` (`config_key`, `config_value`, `group_code`, `created_at`, `updated_at`) VALUES (?, ?, ?, NOW(), NOW()) ' .
            'ON DUPLICATE KEY UPDATE `config_value` = VALUES(`config_value`), `group_code` = VALUES(`group_code`), `updated_at` = NOW()'
        );
        $written = 0;

        foreach (self::DEFAULTS as $groupCode => $items) {
            foreach ($items as $key => $value) {
                if (in_array($key, self::INSTALL_MANAGED_KEYS, true)) {
                    continue;
                }
                $statement->execute([$key, $value, (string) $groupCode]);
                $written++;
            }
        }

        return ['count' => $written, 'deleted' => $deleted];
    }
};
