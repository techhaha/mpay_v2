<?php

declare(strict_types=1);

namespace app\common\sdk\wxpay;

use app\common\constant\FileConstant;

/**
 * 微信支付 SDK 配置对象。
 *
 * 该类只负责保存、读取和校验微信支付调用所需的基础配置，不参与项目支付单逻辑。
 *
 * 常用配置项：
 * - api_version：v3 或 v2，默认 v3。
 * - mode：merchant 或 partner，默认 merchant。
 * - app_id：公众号、APP 或小程序 AppID。
 * - mch_id：商户号；服务商模式下为服务商商户号 sp_mchid。
 * - sub_mch_id：服务商模式下的子商户号。
 * - sub_app_id：服务商模式下的子商户 AppID，按产品需要填写。
 * - serial_no：V3 商户 API 证书序列号。
 * - private_key：V3 商户 API 私钥内容。
 * - private_key_path：V3 商户 API 私钥文件路径；private_key 为空时读取。
 * - api_v3_key：V3 APIv3 密钥，用于回调解密。
 * - wechatpay_public_key：微信支付平台公钥内容，用于验签。
 * - platform_cert_path：微信支付平台证书路径；未配置平台公钥时读取证书验签。
 * - api_key：V2 商户 API 密钥。
 * - v2_sign_type：V2 签名类型，MD5 或 HMAC-SHA256，默认 HMAC-SHA256。
 * - cert_path/key_path：V2 退款等双向证书接口使用的 apiclient_cert.pem 与 apiclient_key.pem。
 */
class WxpayConfig
{
    public const MODE_MERCHANT = 'merchant';
    public const MODE_PARTNER = 'partner';

    public const GATEWAY_V3 = 'https://api.mch.weixin.qq.com';
    public const GATEWAY_V2 = 'https://api.mch.weixin.qq.com';

    /**
     * 原始配置数组。
     *
     * @var array<string, mixed>
     */
    private array $config;

    /**
     * V3 商户 API 私钥缓存。
     *
     * @var string|null
     */
    private ?string $privateKey = null;

    /**
     * V3 微信支付平台公钥或平台证书内容缓存。
     *
     * @var string|null
     */
    private ?string $platformPublicKeyOrCert = null;

    /**
     * 构造方法。
     *
     * @param array<string, mixed> $config 配置数组
     */
    public function __construct(array $config)
    {
        $this->config = $config;
        $this->validate();
    }

    /**
     * 从数组创建配置对象。
     *
     * @param array<string, mixed> $config 配置数组
     * @return self 配置对象
     */
    public static function fromArray(array $config): self
    {
        return new self($config);
    }

    /**
     * 获取 API 版本。
     *
     * @return string v2 或 v3
     */
    public function apiVersion(): string
    {
        return strtolower($this->string('api_version', WxpayClient::API_VERSION_V3));
    }

    /**
     * 是否 V3 接口模式。
     *
     * @return bool 是否 V3
     */
    public function isV3(): bool
    {
        return $this->apiVersion() === WxpayClient::API_VERSION_V3;
    }

    /**
     * 是否 V2 接口模式。
     *
     * @return bool 是否 V2
     */
    public function isV2(): bool
    {
        return $this->apiVersion() === WxpayClient::API_VERSION_V2;
    }

    /**
     * 获取接入模式。
     *
     * @return string merchant 或 partner
     */
    public function mode(): string
    {
        return strtolower($this->string('mode', self::MODE_MERCHANT));
    }

    /**
     * 是否服务商模式。
     *
     * @return bool 是否服务商模式
     */
    public function isPartner(): bool
    {
        return $this->mode() === self::MODE_PARTNER;
    }

    /**
     * 获取应用 AppID。
     *
     * @return string AppID
     */
    public function appId(): string
    {
        return $this->string('app_id');
    }

    /**
     * 获取商户号。
     *
     * 服务商模式下该字段为服务商商户号 sp_mchid，也是 V3 Authorization 头中的 mchid。
     *
     * @return string 商户号
     */
    public function mchId(): string
    {
        return $this->string('mch_id');
    }

    /**
     * 获取服务商模式下的子商户号。
     *
     * @return string 子商户号
     */
    public function subMchId(): string
    {
        return $this->string('sub_mch_id');
    }

    /**
     * 获取服务商模式下的子商户 AppID。
     *
     * @return string 子商户 AppID
     */
    public function subAppId(): string
    {
        return $this->string('sub_app_id');
    }

    /**
     * 获取 V3 商户 API 证书序列号。
     *
     * @return string 证书序列号
     */
    public function serialNo(): string
    {
        return $this->string('serial_no');
    }

    /**
     * 获取 V3 商户 API 私钥内容。
     *
     * private_key 优先；为空时读取 private_key_path 指向的文件。
     *
     * @return string 私钥内容
     */
    public function privateKey(): string
    {
        if ($this->privateKey === null) {
            $this->privateKey = $this->configuredContent('private_key', 'private_key_path');
        }

        return $this->privateKey;
    }

    /**
     * 获取 V3 APIv3 密钥。
     *
     * @return string APIv3 密钥
     */
    public function apiV3Key(): string
    {
        return $this->string('api_v3_key');
    }

    /**
     * 获取微信支付平台公钥或平台证书内容。
     *
     * wechatpay_public_key 优先；为空时读取 platform_cert_path 指向的证书文件。
     *
     * @return string 平台公钥或平台证书内容
     */
    public function platformPublicKeyOrCert(): string
    {
        if ($this->platformPublicKeyOrCert === null) {
            $this->platformPublicKeyOrCert = $this->configuredContent('wechatpay_public_key', 'platform_cert_path');
        }

        return $this->platformPublicKeyOrCert;
    }

    /**
     * 获取 V2 商户 API 密钥。
     *
     * @return string API 密钥
     */
    public function apiKey(): string
    {
        return $this->string('api_key');
    }

    /**
     * 获取 V2 签名类型。
     *
     * @return string MD5 或 HMAC-SHA256
     */
    public function v2SignType(): string
    {
        return strtoupper($this->string('v2_sign_type', 'HMAC-SHA256'));
    }

    /**
     * 获取 V3 网关地址。
     *
     * @return string V3 网关
     */
    public function gatewayV3(): string
    {
        return rtrim($this->string('gateway_v3', self::GATEWAY_V3), '/');
    }

    /**
     * 获取 V2 网关地址。
     *
     * @return string V2 网关
     */
    public function gatewayV2(): string
    {
        return rtrim($this->string('gateway_v2', self::GATEWAY_V2), '/');
    }

    /**
     * 是否使用微信支付 V2 沙箱路径。
     *
     * V2 沙箱接口路径会增加 /sandboxnew 前缀；V3 当前按 gateway_v3 显式配置区分。
     *
     * @return bool 是否沙箱
     */
    public function sandbox(): bool
    {
        return $this->bool('sandbox');
    }

    /**
     * 获取请求总超时时间。
     *
     * @return int 秒
     */
    public function timeout(): int
    {
        return max(1, $this->int('timeout', 10));
    }

    /**
     * 获取连接超时时间。
     *
     * @return int 秒
     */
    public function connectTimeout(): int
    {
        return max(1, $this->int('connect_timeout', 5));
    }

    /**
     * 是否校验 HTTPS 证书。
     *
     * 当前本地开发环境经常缺少 CA 根证书，默认关闭；生产环境可显式配置 verify=true。
     *
     * @return bool 是否校验证书
     */
    public function verifyPeer(): bool
    {
        return $this->bool('verify', false);
    }

    /**
     * 是否校验 V3 响应签名。
     *
     * 只有配置了 wechatpay_public_key 或 platform_cert_path 时才会实际验签。
     *
     * @return bool 是否验签响应
     */
    public function verifyResponse(): bool
    {
        return $this->bool('verify_response', false);
    }

    /**
     * 获取 V2 双向证书 cert 文件路径。
     *
     * @return string 可读路径，未配置时为空
     */
    public function certPath(): string
    {
        return $this->configuredPath('cert_path');
    }

    /**
     * 获取 V2 双向证书 key 文件路径。
     *
     * @return string 可读路径，未配置时为空
     */
    public function keyPath(): string
    {
        return $this->configuredPath('key_path');
    }

    /**
     * 读取布尔配置。
     *
     * @param string $key 配置键
     * @param bool $default 默认值
     * @return bool 布尔值
     */
    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->config[$key] ?? $default;
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * 校验基础配置完整性。
     *
     * @return void
     */
    private function validate(): void
    {
        if (!in_array($this->apiVersion(), [WxpayClient::API_VERSION_V2, WxpayClient::API_VERSION_V3], true)) {
            throw new WxpaySdkException('微信支付 api_version 必须是 v2 或 v3');
        }
        if (!in_array($this->mode(), [self::MODE_MERCHANT, self::MODE_PARTNER], true)) {
            throw new WxpaySdkException('微信支付 mode 必须是 merchant 或 partner');
        }
        if ($this->appId() === '') {
            throw new WxpaySdkException('微信支付 app_id 不能为空');
        }
        if ($this->mchId() === '') {
            throw new WxpaySdkException('微信支付 mch_id 不能为空');
        }
        if ($this->isPartner() && $this->subMchId() === '') {
            throw new WxpaySdkException('微信支付服务商模式必须配置 sub_mch_id');
        }
        if ($this->isV3()) {
            if ($this->serialNo() === '') {
                throw new WxpaySdkException('微信支付 V3 必须配置 serial_no');
            }
            if ($this->privateKey() === '') {
                throw new WxpaySdkException('微信支付 V3 必须配置 private_key 或 private_key_path');
            }
        }
        if ($this->isV2()) {
            if ($this->apiKey() === '') {
                throw new WxpaySdkException('微信支付 V2 必须配置 api_key');
            }
            if (!in_array($this->v2SignType(), ['MD5', 'HMAC-SHA256'], true)) {
                throw new WxpaySdkException('微信支付 V2 v2_sign_type 必须是 MD5 或 HMAC-SHA256');
            }
        }
    }

    /**
     * 读取字符串配置。
     *
     * @param string $key 配置键
     * @param string $default 默认值
     * @return string 字符串配置
     */
    private function string(string $key, string $default = ''): string
    {
        return trim((string) ($this->config[$key] ?? $default));
    }

    /**
     * 读取整数配置。
     *
     * @param string $key 配置键
     * @param int $default 默认值
     * @return int 整数配置
     */
    private function int(string $key, int $default): int
    {
        return (int) ($this->config[$key] ?? $default);
    }

    /**
     * 读取“内容或路径”配置。
     *
     * @param string $contentKey 内容字段
     * @param string $pathKey 路径字段
     * @return string 内容
     */
    private function configuredContent(string $contentKey, string $pathKey): string
    {
        $content = $this->string($contentKey);
        if ($content !== '') {
            return $content;
        }

        $path = $this->configuredPath($pathKey);
        if ($path === '') {
            return '';
        }

        $value = file_get_contents($path);
        if ($value === false) {
            throw new WxpaySdkException(sprintf('读取微信支付文件失败：%s', $path));
        }

        return trim($value);
    }

    /**
     * 读取配置中的文件路径。
     *
     * @param string $key 配置键
     * @return string 可读路径，不存在时返回空字符串
     */
    private function configuredPath(string $key): string
    {
        $path = $this->string($key);
        if ($path === '') {
            return '';
        }

        $resolvedPath = $this->resolveReadablePath($path);
        if ($resolvedPath === '') {
            throw new WxpaySdkException(sprintf('微信支付文件不可读：%s', $path));
        }

        return $resolvedPath;
    }

    /**
     * 将常见项目相对路径解析为可读绝对路径。
     *
     * @param string $path 原始路径
     * @return string 可读路径，不可读时返回空字符串
     */
    private function resolveReadablePath(string $path): string
    {
        foreach ($this->pathCandidates($path) as $candidate) {
            if (is_file($candidate) && is_readable($candidate)) {
                return $candidate;
            }
        }

        return '';
    }

    /**
     * 生成路径候选列表。
     *
     * @param string $path 原始路径
     * @return array<int, string> 候选路径
     */
    private function pathCandidates(string $path): array
    {
        $path = trim(str_replace('\\', '/', $path));
        if ($path === '') {
            return [];
        }

        $candidates = [$this->normalizePath($path)];
        if ($this->isAbsolutePath($path)) {
            return array_values(array_unique($candidates));
        }

        $relativePath = trim($path, '/');
        if (str_starts_with($relativePath, FileConstant::LOCAL_PRIVATE_DIR . '/') && function_exists('runtime_path')) {
            $candidates[] = runtime_path($relativePath);
        }
        if (str_starts_with($relativePath, FileConstant::LOCAL_PUBLIC_DIR . '/') && function_exists('public_path')) {
            $candidates[] = public_path($relativePath);
        }
        if (str_starts_with($relativePath, 'runtime/') && function_exists('base_path')) {
            $candidates[] = base_path($relativePath);
        }
        if (function_exists('base_path')) {
            $candidates[] = base_path($relativePath);
        }

        return array_values(array_unique(array_map([$this, 'normalizePath'], $candidates)));
    }

    /**
     * 判断是否为绝对路径。
     *
     * @param string $path 路径
     * @return bool 是否绝对路径
     */
    private function isAbsolutePath(string $path): bool
    {
        return preg_match('/^[A-Za-z]:\//', $path) === 1
            || str_starts_with($path, '/')
            || str_starts_with($path, '//');
    }

    /**
     * 标准化路径分隔符。
     *
     * @param string $path 路径
     * @return string 标准路径
     */
    private function normalizePath(string $path): string
    {
        return str_replace('/', DIRECTORY_SEPARATOR, $path);
    }
}
