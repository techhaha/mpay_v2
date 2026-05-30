<?php

namespace app\service\payment\receipt;

use app\common\base\BaseService;

/**
 * receipt_watcher 授权配置服务。
 *
 * Webman 只保存离线授权码，并把系统配置刷新到 `system_config:all` 缓存。
 * 正式授权验签和插件拦截由 Python watcher 二进制完成。
 */
class ReceiptWatcherLicenseService extends BaseService
{
    /**
     * 当前生产二进制包含的网页流水监听插件。
     */
    private const PRODUCTION_PLUGIN_CODES = [
        'alipay_bill_receipt',
        'fubei_receipt',
        'haike_maqian_receipt',
        'lakala_receipt',
        'postar_receipt',
        'shouqianba_receipt',
        'tianque_receipt',
        'yisheng_receipt',
        'yeepay_boss_receipt',
    ];

    /**
     * 读取 Webman 侧授权配置状态。
     *
     * 这里不验签授权码，避免把授权公钥和最终授权边界放进可编辑源码。
     *
     * @return array<string, mixed> 授权配置状态
     */
    public function status(): array
    {
        $licenseCode = $this->licenseCode();
        $configured = $licenseCode !== '';

        return [
            'enforced' => true,
            'status' => $configured ? 'configured' : 'free',
            'status_text' => $configured ? '授权码已配置' : '未配置授权码',
            'message' => $configured
                ? '授权码已写入系统配置缓存，最终验签结果以 watcher 心跳为准。'
                : '未配置 receipt_watcher 授权码，正式 watcher 仅开放内置免费插件。',
            'license_id' => '',
            'edition' => '',
            'site_url' => $this->siteUrl(),
            'expires_at' => 0,
            'grace_days' => 7,
            'grace_until' => 0,
            'authorized_plugins' => self::PRODUCTION_PLUGIN_CODES,
            'free_plugin_codes' => [],
            'blocked_plugins' => [],
            'updated_at' => time(),
        ];
    }

    /**
     * 根据授权过滤插件清单。
     *
     * Webman 不作为最终授权边界，因此这里只做格式归一化，不拦截配置。
     *
     * @param array<int, string> $configuredCodes 后台启用插件
     * @return array<int, string> 可写入账号缓存的插件
     */
    public function authorizedPluginCodes(array $configuredCodes): array
    {
        return $this->normalizePluginCodes($configuredCodes);
    }

    /**
     * 判断插件是否是受 watcher 授权控制的监听插件。
     *
     * @param string $pluginCode 插件编码
     * @return bool 是否监听插件
     */
    public function isReceiptWatcherPlugin(string $pluginCode): bool
    {
        return in_array(trim($pluginCode), self::PRODUCTION_PLUGIN_CODES, true);
    }

    /**
     * 判断插件当前是否授权可用。
     *
     * Webman 源码可被客户修改，不能依赖这里做授权边界；正式 watcher 会再次验签并跳过未授权任务。
     *
     * @param string $pluginCode 插件编码
     * @return bool 是否允许 Webman 配置和投放
     */
    public function isPluginAuthorized(string $pluginCode): bool
    {
        return true;
    }

    /**
     * 兼容旧调度入口。
     *
     * 离线授权码方案不再下发授权快照或绑定上下文，watcher 直接读取 `system_config:all`。
     *
     * @return void
     */
    public function publishRuntimeSnapshot(): void
    {
    }

    /**
     * 读取授权码。
     *
     * @return string 授权码
     */
    private function licenseCode(): string
    {
        return trim((string) sys_config('receipt_watcher_license_code', ''));
    }

    /**
     * 读取站点 URL。
     *
     * @return string 站点 URL
     */
    private function siteUrl(): string
    {
        return rtrim(trim((string) sys_config('site_url', '')), '/');
    }

    /**
     * 标准化插件编码。
     *
     * @param array<int, mixed> $codes 插件编码
     * @return array<int, string> 标准插件编码
     */
    private function normalizePluginCodes(array $codes): array
    {
        $result = [];
        foreach ($codes as $code) {
            $code = trim((string) $code);
            if ($code !== '' && in_array($code, self::PRODUCTION_PLUGIN_CODES, true)) {
                $result[] = $code;
            }
        }

        return array_values(array_unique($result));
    }
}
