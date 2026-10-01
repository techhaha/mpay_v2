<?php

declare(strict_types=1);

namespace app\common\base;

use app\common\constant\PaymentPluginTypeConstant;
use app\common\constant\PaymentPluginStatusConstant;
use app\common\interface\PayPluginInterface;
use app\common\interface\PaymentInterface;
use app\exception\PaymentException;
use app\exception\PaymentDefinitiveException;
use app\exception\PaymentUncertainException;
use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;

/**
 * 支付插件基类。
 *
 * 负责通道配置、插件元信息、标准支付结果和 HTTP 请求等公共能力；订单号、金额和
 * 回调地址等业务参数由各插件动作的标准入参提供，不写入通道配置。
 */
abstract class BasePayment implements PayPluginInterface, PaymentInterface
{
    /**
     * 插件元信息。
     *
     * @var array<string, mixed>
     */
    protected array $paymentInfo = [];

    /**
     * 进件能力元信息。
     *
     * 与支付、转账能力分离，供后台进件配置和商户端在线签约页读取。
     *
     * @var array<string, mixed>
     */
    protected array $onboardingInfo = [];

    /**
     * 由运行时注入的通道配置。
     *
     * @var array<string, mixed>
     */
    protected array $channelConfig = [];

    private ?Client $httpClient = null;

    /** @var Closure(string):void|null 当前下单的产品请求前持久化入口 */
    private ?Closure $payProductPreflight = null;

    /**
     * 在本次支付中注入外呼前产品持久化动作；未声明多产品的插件保持原下单行为。
     *
     * 插件选品仍由自身完成；在核心派发时，持久化事务提交后才能执行选中产品的请求。
     * 回调只在本次 pay() 调用期间有效，防止长驻进程把订单上下文带入下一笔支付。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param callable(string):void $persist 产品持久化动作
     * @return array<string, mixed> 插件原始标准结果
     */
    final public function payWithProductPreflight(array $order, callable $persist): array
    {
        $this->payProductPreflight = Closure::fromCallable($persist);
        try {
            return $this->pay($order);
        } finally {
            $this->payProductPreflight = null;
        }
    }

    /** 在调用已选产品处理器前完成本地持久化；直接调用插件时不触发数据库操作。 */
    final protected function preparePayProduct(string $product): void
    {
        if ($this->payProductPreflight !== null) {
            ($this->payProductPreflight)($product);
        }
    }

    /** 自动兜底不得在已预写产品且可能发出请求之后重新选择其他产品。 */
    final protected function hasPayProductPreflight(): bool
    {
        return $this->payProductPreflight !== null;
    }

    /**
     * 初始化插件，加载通道配置并创建 HTTP 客户端。
     *
     * @param array<string, mixed> $channelConfig 通道配置
     */
    public function init(array $channelConfig): void
    {
        $this->channelConfig = $channelConfig;
        $this->httpClient = new Client([
            'timeout' => 10,
            'connect_timeout' => 10,
            'verify' => true,
            'http_errors' => false,
        ]);
    }

    /**
     * 获取通道配置项。
     *
     * @param string $key 配置键
     * @param mixed $default 默认值
     * @return mixed 通道配置值
     */
    protected function getConfig(string $key, mixed $default = null): mixed
    {
        return $this->channelConfig[$key] ?? $default;
    }

    /**
     * 获取插件代码（唯一标识）。
     *
     * @return string 插件代码
     */
    public function getCode(): string
    {
        return $this->paymentInfo['code'] ?? '';
    }

    /**
     * 获取插件名称。
     *
     * @return string 插件名称
     */
    public function getName(): string
    {
        return $this->paymentInfo['name'] ?? '';
    }

    /**
     * 获取作者名称。
     *
     * @return string 作者名称
     */
    public function getAuthorName(): string
    {
        return $this->paymentInfo['author'] ?? '';
    }

    /**
     * 获取作者链接。
     *
     * @return string 作者链接
     */
    public function getAuthorLink(): string
    {
        return $this->paymentInfo['link'] ?? '';
    }

    /**
     * 获取版本号。
     *
     * @return string 版本号
     */
    public function getVersion(): string
    {
        return $this->paymentInfo['version'] ?? '';
    }

    /**
     * 获取插件类型。
     *
     * 插件类型只作为后台筛选和运营识别字段，不参与支付路由和资金归属判断。
     *
     * @return int 插件类型
     */
    public function getPluginType(): int
    {
        $type = (int) ($this->paymentInfo['plugin_type'] ?? PaymentPluginTypeConstant::TYPE_DIRECT);

        return PaymentPluginTypeConstant::isValid($type) ? $type : PaymentPluginTypeConstant::TYPE_DIRECT;
    }

    /**
     * 获取插件支持的支付方式列表。
     *
     * @return array<int, string> 支持的支付方式编码
     */
    public function getEnabledPayTypes(): array
    {
        return $this->paymentInfo['pay_types'] ?? [];
    }

    /**
     * 获取插件支持的转账方式列表。
     *
     * @return array<int, string> 支持的转账方式编码
     */
    public function getEnabledTransferTypes(): array
    {
        return $this->paymentInfo['transfer_types'] ?? [];
    }

    /**
     * 获取插件配置表单结构（用于后台配置界面）。
     *
     * @return array<int, array<string, mixed>> 配置表单结构
     */
    public function getConfigSchema(): array
    {
        return $this->paymentInfo['config_schema'] ?? [];
    }

    /**
     * 获取网页流水监听运行能力。
     *
     * 该能力来源于 paymentInfo.receipt_watcher，仅供实现 ChannelNotifyPayloadInterface
     * 的插件声明运行时和预登录支持情况。
     *
     * @return array<string, mixed> 网页流水监听能力
     */
    public function receiptWatcherInfo(): array
    {
        $info = $this->paymentInfo['receipt_watcher'] ?? [];

        return is_array($info) ? $info : [];
    }

    /**
     * 获取进件主体类型声明。
     *
     * @return array<int, string> 主体类型编码
     */
    public function getOnboardingTypes(): array
    {
        $types = $this->onboardingInfo['types'] ?? [];

        return is_array($types) ? array_values(array_filter(array_map('strval', $types))) : [];
    }

    /**
     * 获取进件能力完整元信息。
     *
     * @return array<string, mixed> 进件元信息
     */
    public function getOnboardingInfo(): array
    {
        return $this->onboardingInfo;
    }

    /**
     * 获取商户/后台进件资料表单结构。
     *
     * @return array<int, mixed> 表单结构
     */
    public function getOnboardingFormSchema(): array
    {
        $schema = $this->onboardingInfo['form_schema'] ?? [];

        return is_array($schema) ? array_values($schema) : [];
    }

    /**
     * 获取进件接口配置表单结构。
     *
     * @return array<int, mixed> 配置表单结构
     */
    public function getOnboardingConfigSchema(): array
    {
        $schema = $this->onboardingInfo['config_schema'] ?? [];

        return is_array($schema) ? array_values($schema) : [];
    }

    /**
     * 构造等待用户承接或渠道异步确认的标准支付结果。
     *
     * 调用方必须明确提供支付产品和承接字段，本方法不判断渠道业务状态。
     *
     * @param array<string, mixed> $order 标准支付参数
     * @param array<string, mixed> $result 插件已完成映射的支付结果字段
     * @return array<string, mixed> 标准支付结果
     */
    protected function pendingPaymentResult(array $order, array $result): array
    {
        return [
            'status' => PaymentPluginStatusConstant::PENDING,
            'pay_no' => (string) ($order['pay_no'] ?? ''),
            'paid_amount' => null,
            'chan_order_no' => (string) ($result['chan_order_no'] ?? ''),
            'chan_trade_no' => (string) ($result['chan_trade_no'] ?? ''),
            'pay_type' => (string) ($result['pay_type'] ?? ''),
            'pay_product' => (string) ($result['pay_product'] ?? ''),
            'pay_action' => (string) ($result['pay_action'] ?? ''),
            'channel_context' => (array) ($result['channel_context'] ?? []),
            'presentation' => [
                'pay_page' => (string) ($result['pay_page'] ?? ''),
                'pay_type' => (string) ($result['pay_type'] ?? ''),
                'pay_product' => (string) ($result['pay_product'] ?? ''),
                'pay_action' => (string) ($result['pay_action'] ?? ''),
                'pay_params' => (array) ($result['pay_params'] ?? []),
            ],
        ];
    }

    /**
     * 构造渠道已明确支付成功的标准支付结果。
     *
     * 成功金额必须由插件依据渠道协议明确传入，不能由公共流程猜测。
     *
     * @param array<string, mixed> $order 标准支付参数
     * @param array<string, mixed> $result 插件已完成映射的支付结果字段
     * @return array<string, mixed> 标准支付结果
     * @throws PaymentDefinitiveException 缺少明确实付金额时抛出
     */
    protected function successfulPaymentResult(array $order, array $result): array
    {
        if (!array_key_exists('paid_amount', $result) || !is_int($result['paid_amount']) || $result['paid_amount'] < 0) {
            throw new PaymentDefinitiveException('渠道成功结果缺少有效实付金额', 40200);
        }

        return [
            'status' => PaymentPluginStatusConstant::SUCCESS,
            'pay_no' => (string) ($order['pay_no'] ?? ''),
            'paid_amount' => $result['paid_amount'],
            'chan_order_no' => (string) ($result['chan_order_no'] ?? ''),
            'chan_trade_no' => (string) ($result['chan_trade_no'] ?? ''),
            'pay_type' => (string) ($result['pay_type'] ?? ''),
            'pay_product' => (string) ($result['pay_product'] ?? ''),
            'pay_action' => (string) ($result['pay_action'] ?? ''),
            'channel_context' => (array) ($result['channel_context'] ?? []),
        ];
    }

    /**
     * 请求支付渠道 API。
     *
     * @param string $method 请求方法
     * @param string $url 请求地址
     * @param array<string, mixed> $options 请求选项
     * @return ResponseInterface 响应对象
     * @throws PaymentException 插件未初始化时抛出
     * @throws PaymentUncertainException 渠道通信结果无法确认时抛出
     */
    protected function request(string $method, string $url, array $options = []): ResponseInterface
    {
        if ($this->httpClient === null) {
            throw new PaymentException('支付插件未初始化，请先调用 init()');
        }

        try {
            return $this->httpClient->request($method, $url, $options);
        } catch (GuzzleException $e) {
            throw new PaymentUncertainException('渠道请求结果不确定', 40200, [
                'method' => $method,
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
