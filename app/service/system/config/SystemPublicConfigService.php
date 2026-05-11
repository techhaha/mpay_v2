<?php

namespace app\service\system\config;

use app\common\base\BaseService;

/**
 * 系统公开配置服务。
 *
 * 只整理前端可以安全读取的展示类配置，不返回密钥、对象存储凭证等敏感信息。
 */
class SystemPublicConfigService extends BaseService
{
    private const DEFAULT_SITE_LOGO = '/assets/brand/mpay-logo.svg';
    private const DEFAULT_SITE_LOGO_COMPACT = '/assets/brand/mpay-mark.svg';

    /**
     * 管理后台展示配置。
     *
     * @return array<string, mixed>
     */
    public function adminPortal(): array
    {
        return $this->portalConfig('admin_portal_name', '支付中台管理后台');
    }

    /**
     * 商户后台展示配置。
     *
     * @return array<string, mixed>
     */
    public function merchantPortal(): array
    {
        return array_replace($this->portalConfig('merchant_portal_name', '支付中台商户后台'), [
            'merchant_announcement_enabled' => $this->boolConfig('merchant_announcement_enabled', false),
            'merchant_announcement' => $this->textConfig('merchant_announcement'),
        ]);
    }

    /**
     * 收银台展示配置。
     *
     * @return array<string, mixed>
     */
    public function cashier(): array
    {
        $siteName = $this->textConfig('site_name', 'MPAY 支付中台');
        $siteLogo = $this->textConfig('site_logo', self::DEFAULT_SITE_LOGO);
        $cashierLogo = $this->textConfig('cashier_logo');

        return [
            'enabled' => $this->boolConfig('cashier_enabled', true),
            'site_name' => $siteName,
            'title' => $this->textConfig('cashier_title', 'MPAY 收银台'),
            'logo' => $cashierLogo !== '' ? $cashierLogo : $siteLogo,
            'notice_enabled' => $this->boolConfig('cashier_notice_enabled', true),
            'notice' => $this->textConfig('cashier_notice', '确认支付方式后，系统会创建本次支付尝试并跳转支付页。'),
            'show_merchant_name' => $this->boolConfig('cashier_show_merchant_name', true),
            'show_order_no' => $this->boolConfig('cashier_show_order_no', true),
            'show_pay_type_desc' => $this->boolConfig('cashier_show_pay_type_desc', true),
            'poll_interval_seconds' => $this->intConfig('cashier_poll_interval_seconds', 2, 1, 60),
            'poll_timeout_seconds' => $this->intConfig('cashier_poll_timeout_seconds', 300, 30, 3600),
            'customer_service_enabled' => $this->boolConfig('customer_service_enabled', false),
            'customer_service_name' => $this->textConfig('customer_service_name'),
            'customer_service_phone' => $this->textConfig('customer_service_phone'),
            'customer_service_email' => $this->textConfig('customer_service_email'),
        ];
    }

    /**
     * 按端整理门户通用展示配置。
     *
     * @param string $portalNameKey 门户名称配置 key
     * @param string $portalNameDefault 门户名称默认值
     * @return array<string, mixed>
     */
    private function portalConfig(string $portalNameKey, string $portalNameDefault): array
    {
        return [
            'site_name' => $this->textConfig('site_name', 'MPAY 支付中台'),
            'site_url' => rtrim($this->textConfig('site_url'), '/'),
            'site_logo' => $this->textConfig('site_logo', self::DEFAULT_SITE_LOGO),
            'site_logo_compact' => $this->textConfig('site_logo_compact', self::DEFAULT_SITE_LOGO_COMPACT),
            'portal_name' => $this->textConfig($portalNameKey, $portalNameDefault),
            'customer_service_enabled' => $this->boolConfig('customer_service_enabled', false),
            'customer_service_name' => $this->textConfig('customer_service_name'),
            'customer_service_phone' => $this->textConfig('customer_service_phone'),
            'customer_service_email' => $this->textConfig('customer_service_email'),
        ];
    }

    /**
     * 读取文本配置。
     *
     * @param string $key 配置键
     * @param string $default 默认值
     * @return string 文本值
     */
    private function textConfig(string $key, string $default = ''): string
    {
        $value = trim((string) sys_config($key, $default));

        return $value !== '' ? $value : $default;
    }

    /**
     * 读取布尔配置。
     *
     * @param string $key 配置键
     * @param bool $default 默认值
     * @return bool 布尔值
     */
    private function boolConfig(string $key, bool $default): bool
    {
        $value = strtolower(trim((string) sys_config($key, $default ? '1' : '0')));

        return in_array($value, ['1', 'true', 'yes', 'on', 'enabled'], true);
    }

    /**
     * 读取整数配置。
     *
     * @param string $key 配置键
     * @param int $default 默认值
     * @param int $min 最小值
     * @param int $max 最大值
     * @return int 整数值
     */
    private function intConfig(string $key, int $default, int $min, int $max): int
    {
        $value = (int) sys_config($key, $default);

        return min($max, max($min, $value));
    }
}
