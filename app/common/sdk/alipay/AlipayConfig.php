<?php

declare(strict_types=1);

namespace app\common\sdk\alipay;

use app\common\constant\FileConstant;

/**
 * 支付宝 SDK 配置对象。
 *
 * 该类只负责保存和校验支付宝 OpenAPI 调用所需的基础配置，不参与项目支付单逻辑。
 * 配置支持两种加签模式：
 * - 密钥模式：应用私钥 + 支付宝公钥。
 * - 证书模式：应用私钥 + 应用公钥证书 + 支付宝公钥证书 + 支付宝根证书。
 *
 * 应用私钥、支付宝公钥直接传入文本内容；证书模式下的三个证书传入本地文件路径。
 */
class AlipayConfig
{
    public const MODE_KEY = 'key';
    public const MODE_CERT = 'cert';

    public const GATEWAY_PRODUCTION = 'https://openapi.alipay.com/gateway.do';
    public const GATEWAY_SANDBOX = 'https://openapi-sandbox.dl.alipaydev.com/gateway.do';

    /**
     * 原始配置数组。
     *
     * @var array<string, mixed>
     */
    private array $config;

    /**
     * 构造方法。
     *
     * 常用配置项：
     * - app_id：支付宝开放平台应用 ID。
     * - private_key：应用私钥内容。
     * - mode：key 或 cert；未配置时默认 key。
     * - alipay_public_key：密钥模式下用于验签的支付宝公钥。
     * - app_cert_path：证书模式下的应用公钥证书路径。
     * - alipay_cert_path：证书模式下的支付宝公钥证书路径。
     * - alipay_root_cert_path：证书模式下的支付宝根证书路径。
     * - sandbox：是否使用沙箱网关。
     * - gateway：自定义网关；传入后优先级高于 sandbox。
     * - app_auth_token：服务商代调用时的授权 token。
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
     * 获取支付宝应用 ID。
     *
     * @return string 应用 ID
     */
    public function appId(): string
    {
        return $this->string('app_id');
    }

    /**
     * 获取加签模式。
     *
     * @return string key 或 cert
     */
    public function mode(): string
    {
        return strtolower($this->string('mode', self::MODE_KEY));
    }

    /**
     * 获取 OpenAPI 网关地址。
     *
     * @return string 网关地址
     */
    public function gateway(): string
    {
        $gateway = $this->string('gateway', '');
        if ($gateway !== '') {
            return $gateway;
        }

        return $this->bool('sandbox') ? self::GATEWAY_SANDBOX : self::GATEWAY_PRODUCTION;
    }

    /**
     * 获取应用私钥内容。
     *
     * @return string 应用私钥
     */
    public function privateKey(): string
    {
        return $this->string('private_key');
    }

    /**
     * 获取密钥模式下的支付宝公钥。
     *
     * @return string 支付宝公钥
     */
    public function alipayPublicKey(): string
    {
        return $this->string('alipay_public_key');
    }

    /**
     * 获取应用公钥证书内容。
     *
     * @return string 应用公钥证书
     */
    public function appCertContent(): string
    {
        return $this->certificateContent('app_cert_path');
    }

    /**
     * 获取支付宝公钥证书内容。
     *
     * @return string 支付宝公钥证书
     */
    public function alipayCertContent(): string
    {
        return $this->certificateContent('alipay_cert_path');
    }

    /**
     * 获取支付宝根证书内容。
     *
     * @return string 支付宝根证书
     */
    public function alipayRootCertContent(): string
    {
        return $this->certificateContent('alipay_root_cert_path');
    }

    /**
     * 获取接口返回格式。
     *
     * @return string 返回格式，默认 JSON
     */
    public function format(): string
    {
        return strtoupper($this->string('format', 'JSON'));
    }

    /**
     * 获取字符集。
     *
     * @return string 字符集，默认 UTF-8
     */
    public function charset(): string
    {
        return $this->string('charset', 'UTF-8');
    }

    /**
     * 获取签名类型。
     *
     * 当前 SDK 只支持支付宝推荐的 RSA2。
     *
     * @return string 签名类型
     */
    public function signType(): string
    {
        return strtoupper($this->string('sign_type', 'RSA2'));
    }

    /**
     * 获取 OpenAPI 版本。
     *
     * @return string 版本号
     */
    public function version(): string
    {
        return $this->string('version', '1.0');
    }

    /**
     * 获取服务商代调用授权 token。
     *
     * @return string 授权 token
     */
    public function appAuthToken(): string
    {
        return $this->string('app_auth_token', '');
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
     * 是否验签支付宝网关同步响应。
     *
     * @return bool 是否验签
     */
    public function verifyResponse(): bool
    {
        return $this->bool('verify_response', true);
    }

    /**
     * 支付宝响应缺少 sign 时是否直接失败。
     *
     * 默认不强制，避免个别错误响应没有签名时影响错误信息读取。
     *
     * @return bool 是否强制响应签名
     */
    public function strictResponseSign(): bool
    {
        return $this->bool('strict_response_sign', false);
    }

    /**
     * 判断当前是否为证书模式。
     *
     * @return bool 是否证书模式
     */
    public function isCertMode(): bool
    {
        return $this->mode() === self::MODE_CERT;
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
        if ($this->appId() === '') {
            throw new AlipaySdkException('支付宝 app_id 不能为空');
        }
        if ($this->privateKey() === '') {
            throw new AlipaySdkException('支付宝应用私钥不能为空');
        }
        if (!in_array($this->mode(), [self::MODE_KEY, self::MODE_CERT], true)) {
            throw new AlipaySdkException('支付宝加签模式必须是 key 或 cert');
        }
        if ($this->signType() !== 'RSA2') {
            throw new AlipaySdkException('当前支付宝 SDK 仅支持 RSA2 签名');
        }
        if ($this->isCertMode()) {
            if ($this->appCertContent() === '' || $this->alipayCertContent() === '' || $this->alipayRootCertContent() === '') {
                throw new AlipaySdkException('支付宝证书模式必须配置应用公钥证书、支付宝公钥证书和支付宝根证书');
            }
            return;
        }
        if ($this->alipayPublicKey() === '') {
            throw new AlipaySdkException('支付宝密钥模式必须配置 alipay_public_key');
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
     * 读取证书文件内容。
     *
     * @param string $key 证书路径配置键
     * @return string 证书内容
     */
    private function certificateContent(string $key): string
    {
        $path = $this->string($key);
        if ($path !== '') {
            return $this->readConfiguredFile($path);
        }

        return '';
    }

    /**
     * 读取配置中的文件路径。
     *
     * @param string $path 文件路径
     * @return string 文件内容
     */
    private function readConfiguredFile(string $path): string
    {
        $resolvedPath = $this->resolveReadablePath($path);
        if ($resolvedPath === '') {
            throw new AlipaySdkException(sprintf('支付宝文件不可读：%s', $path));
        }

        return $this->readFile($resolvedPath);
    }

    /**
     * 读取文件内容。
     *
     * @param string $path 文件路径
     * @return string 文件内容
     */
    private function readFile(string $path): string
    {
        $content = file_get_contents($path);
        if ($content === false) {
            throw new AlipaySdkException(sprintf('读取支付宝文件失败：%s', $path));
        }

        return trim($content);
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
