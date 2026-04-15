<?php

declare(strict_types=1);

namespace app\common\base;

use app\common\interface\PayPluginInterface;
use app\exception\PaymentException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;
use support\Log;

/**
 * 支付插件基类（建议所有插件继承）
 *
 * 目标：把“插件共性”集中在这里，具体渠道差异留给子类实现 `PaymentInterface`。
 *
 * 生命周期：
 * - 服务层会在每次动作前调用 `init($channelConfig)` 注入该通道配置。
 * - 子类可在 `init()` 中配置第三方 SDK（例如 yansongda/pay）或读取必填参数。
 *
 * 约定：
 * - 这里的 `$channelConfig` 来源通常是 `ma_payment_plugin_conf.config`，并附带通道维度上下文。
 * - 业务级入参（如订单号、金额、回调地址等）不要混进 `$channelConfig`，应从 `pay()` 的 `$order` 参数获取。
 */
abstract class BasePayment implements PayPluginInterface
{
    /**
     * 插件元信息（子类必须覆盖）
     *
     * 常用字段：
     * - code/name：后台展示与标识
     * - pay_types：声明支持的支付方式编码（如 alipay/wechat）
     * - config_schema：后台配置表单结构（fields...）
     * - 包含：code, name, author, link, pay_types, transfer_types, config_schema 等
     *
     * @var array<string, mixed>
     */
    protected array $paymentInfo = [];

    /**
     * 通道配置（由 init 注入）
     *
     * 建议是“纯配置”：商户号/密钥/网关地址/产品开关等。
     *
     * @var array<string, mixed>
     */
    protected array $channelConfig = [];

    /** HTTP 请求客户端（GuzzleHttp） */
    private ?Client $httpClient = null;

    // ==================== 初始化 ====================

    /**
     * 初始化插件，加载通道配置并创建 HTTP 客户端
     *
     * @param array<string, mixed> $channelConfig 通道配置（商户号、密钥等）
     * @return void
     */
    public function init(array $channelConfig): void
    {
        $this->channelConfig = $channelConfig;
        $this->httpClient    = new Client([
            'timeout'         => 10,
            'connect_timeout' => 10,
            'verify'          => true,
            'http_errors'     => false,
        ]);
    }

    /**
     * 获取通道配置项
     *
     * @param string $key    配置键
     * @param mixed  $default 默认值（键不存在时返回）
     * @return mixed
     */
    protected function getConfig(string $key, mixed $default = null): mixed
    {
        return $this->channelConfig[$key] ?? $default;
    }

    // ==================== 插件元信息 ====================

    /** 获取插件代码（唯一标识） */
    public function getCode(): string
    {
        return $this->paymentInfo['code'] ?? '';
    }

    /** 获取插件名称 */
    public function getName(): string
    {
        return $this->paymentInfo['name'] ?? '';
    }

    /** 获取作者名称 */
    public function getAuthorName(): string
    {
        return $this->paymentInfo['author'] ?? '';
    }

    /** 获取作者链接 */
    public function getAuthorLink(): string
    {
        return $this->paymentInfo['link'] ?? '';
    }

    /** 获取版本号 */
    public function getVersion(): string
    {
        return $this->paymentInfo['version'] ?? '';
    }

    // ==================== 能力声明 ====================

    /**
     * 获取插件支持的支付方式列表
     *
     * @return array<string> 支付方式代码数组，如 ['alipay', 'wechat']
     */
    public function getEnabledPayTypes(): array
    {
        return $this->paymentInfo['pay_types'] ?? [];
    }

    /**
     * 获取插件支持的转账方式列表
     *
     * @return array<string> 转账方式代码数组
     */
    public function getEnabledTransferTypes(): array
    {
        return $this->paymentInfo['transfer_types'] ?? [];
    }

    /**
     * 获取插件配置表单结构（用于后台配置界面）
     *
     * @return array<string, mixed> 表单字段定义数组
     */
    public function getConfigSchema(): array
    {
        return $this->paymentInfo['config_schema'] ?? [];
    }

    // ==================== HTTP 请求 ====================

    /**
     * 统一 HTTP 请求（对外调用支付渠道 API）
     *
     * @param string               $method  请求方法（GET/POST/PUT/DELETE 等）
     * @param string               $url     请求地址
     * @param array<string, mixed> $options Guzzle 请求选项（headers、json、form_params 等）
     * @return ResponseInterface
     * @throws PaymentException 未调用 init() 或渠道请求失败时
     */
    protected function request(string $method, string $url, array $options = []): ResponseInterface
    {
        if ($this->httpClient === null) {
            throw new PaymentException('支付插件未初始化，请先调用 init()');
        }

        try {
            return $this->httpClient->request($method, $url, $options);
        } catch (GuzzleException $e) {
            Log::error(sprintf('[BasePayment] HTTP 请求失败: %s %s, error=%s', $method, $url, $e->getMessage()));
            throw new PaymentException('渠道请求失败：' . $e->getMessage(), 402, ['method' => $method, 'url' => $url]);
        }
    }
}
