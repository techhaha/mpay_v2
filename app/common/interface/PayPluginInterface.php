<?php
declare(strict_types=1);

namespace app\common\interface;

/**
 * 支付插件基础契约接口。
 *
 * 职责边界：
 * - `PayPluginInterface`：插件生命周期和元信息，用于后台展示、配置和路由。
 * - `PaymentInterface`：支付动作能力，用于下单、查询、关单、退款和回调。
 *
 * 约定：
 * - `init()` 会在每次发起支付或退款前由服务层调用，用于注入该通道对应的插件配置。
 * - 元信息方法应为纯读取，不要依赖外部状态或数据库。
 */
interface PayPluginInterface
{
    /**
     * 初始化插件，注入通道配置。
     *
     * 典型来源：`ma_payment_plugin_conf.config`，并由服务层额外合并通道信息、支付方式声明等上下文。
     * 插件应在这里完成：缓存配置、初始化 SDK/HTTP 客户端等。
     *
     * @param array $channelConfig 渠道配置
     * @return void
     */
    public function init(array $channelConfig): void;

    /**
     * 获取插件代码。
     *
     * @return string 插件代码
     */
    public function getCode(): string;

    /**
     * 获取插件名称。
     *
     * @return string 插件名称
     */
    public function getName(): string;

    /**
     * 获取作者名称。
     *
     * @return string 作者名称
     */
    public function getAuthorName(): string;

    /**
     * 获取作者链接。
     *
     * @return string 作者链接
     */
    public function getAuthorLink(): string;

    /**
     * 获取版本号。
     *
     * @return string 版本号
     */
    public function getVersion(): string;

    /**
     * 获取插件类型。
     *
     * @return int 插件类型，见 PaymentPluginTypeConstant
     */
    public function getPluginType(): int;

    /**
     * 获取插件声明支持的支付方式编码。
     *
     * @return array 支持的支付方式编码
     */
    public function getEnabledPayTypes(): array;

    /**
     * 获取插件声明支持的转账方式编码。
     *
     * @return array 支持的转账方式编码
     */
    public function getEnabledTransferTypes(): array;

    /**
     * 获取插件配置结构。
     *
     * @return array 配置结构
     */
    public function getConfigSchema(): array;
}






