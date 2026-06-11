<?php

namespace app\service\payment\receipt;

use app\common\base\BaseService;
use app\common\constant\CommonConstant;
use app\common\interface\ChannelNotifyPayloadInterface;
use app\model\payment\PaymentPlugin;
use app\repository\payment\config\PaymentPluginRepository;

/**
 * receipt_watcher 授权码配置和插件能力识别服务。
 *
 * Webman 只保存离线授权码，并把系统配置刷新到 `system_config:all` 缓存。
 * 正式授权验签和插件拦截由 Python watcher 二进制完成。
 */
class ReceiptWatcherLicenseService extends BaseService
{
    /**
     * 构造方法。
     *
     * @param PaymentPluginRepository $paymentPluginRepository 支付插件仓库
     * @return void
     */
    public function __construct(
        protected PaymentPluginRepository $paymentPluginRepository
    ) {
    }

    /**
     * 读取 Webman 侧授权码配置状态。
     *
     * 这里不验签授权码，避免把授权公钥和最终授权边界放进可编辑源码。
     *
     * @return array<string, mixed> 授权码配置状态
     */
    public function status(): array
    {
        $licenseCode = $this->licenseCode();
        $configured = $licenseCode !== '';
        $watcherCapableCodes = $this->watcherCapablePluginCodes();

        return [
            'status' => $configured ? 'configured' : 'empty',
            'status_text' => $configured ? '授权码已配置' : '未配置授权码',
            'message' => $configured
                ? '授权码已写入系统配置缓存，最终验签结果以 watcher 心跳为准。'
                : '未配置 receipt_watcher 授权码。',
            'site_url' => $this->siteUrl(),
            'site_domain' => $this->siteDomain(),
            'watcher_capable_plugins' => $watcherCapableCodes,
            'updated_at' => time(),
        ];
    }

    /**
     * 按 Webman 插件能力过滤后台配置的监听插件清单。
     *
     * `receipt_watcher_plugin_codes` 是业务启用开关；这里仅排除不存在、已禁用、
     * 或未实现 ChannelNotifyPayloadInterface 的插件，避免普通支付插件进入账号任务。
     *
     * @param array<int, string> $configuredCodes 后台启用插件
     * @return array<int, string> 可写入账号缓存的插件
     */
    public function filterWatcherCapablePluginCodes(array $configuredCodes): array
    {
        $watcherPluginCodeSet = array_fill_keys($this->watcherCapablePluginCodes(), true);
        $result = [];
        foreach ($this->normalizePluginCodes($configuredCodes) as $code) {
            if (isset($watcherPluginCodeSet[$code])) {
                $result[] = $code;
            }
        }

        return $result;
    }

    /**
     * 判断插件是否具备网页流水监听能力。
     *
     * @param string $pluginCode 插件编码
     * @return bool 是否监听插件
     */
    public function supportsReceiptWatcher(string $pluginCode): bool
    {
        $pluginCode = trim($pluginCode);
        if ($pluginCode === '') {
            return false;
        }

        return $this->pluginRecordSupportsReceiptWatcher(
            $this->paymentPluginRepository->findByCode($pluginCode, ['code', 'class_name', 'status'])
        );
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
     * 从站点 URL 中提取授权绑定域名。
     *
     * @return string 站点域名
     */
    private function siteDomain(): string
    {
        $siteUrl = $this->siteUrl();
        if ($siteUrl === '') {
            return '';
        }

        if (!str_contains($siteUrl, '://')) {
            $siteUrl = 'https://' . $siteUrl;
        }

        $host = parse_url($siteUrl, PHP_URL_HOST);
        return is_string($host) ? strtolower(trim($host, '.')) : '';
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
            if ($code !== '') {
                $result[] = $code;
            }
        }

        return array_values(array_unique($result));
    }

    /**
     * 获取已启用且具备网页流水监听能力的插件编码。
     *
     * 监听插件能力由插件实现类声明，不再在授权服务里重复维护名单。
     *
     * @return array<int, string> 插件编码
     */
    private function watcherCapablePluginCodes(): array
    {
        $result = [];
        foreach ($this->paymentPluginRepository->enabledList(['code', 'class_name', 'status']) as $plugin) {
            if (!$plugin instanceof PaymentPlugin) {
                continue;
            }
            if ($this->pluginRecordSupportsReceiptWatcher($plugin)) {
                $result[] = (string) $plugin->code;
            }
        }

        return array_values(array_unique($result));
    }

    /**
     * 判断插件实现类是否支持网页流水监听载荷。
     *
     * @param PaymentPlugin|null $plugin 插件记录
     * @return bool 是否支持
     */
    private function pluginRecordSupportsReceiptWatcher(?PaymentPlugin $plugin): bool
    {
        if (!$plugin || (int) $plugin->status !== CommonConstant::STATUS_ENABLED) {
            return false;
        }

        $className = $this->resolvePluginClassName((string) $plugin->class_name);
        if ($className === '' || !class_exists($className)) {
            return false;
        }

        return is_subclass_of($className, ChannelNotifyPayloadInterface::class);
    }

    /**
     * 规范化插件类名。
     *
     * @param string $className 类名
     * @return string 完整类名
     */
    private function resolvePluginClassName(string $className): string
    {
        $className = trim($className);
        if ($className === '') {
            return '';
        }

        if (str_contains($className, '\\')) {
            return $className;
        }

        return 'app\\common\\payment\\' . $className;
    }
}
