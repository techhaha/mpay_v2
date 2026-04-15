<?php
declare(strict_types=1);

namespace app\common\interface;

/**
 * 支付插件“基础契约”接口
 *
 * 职责边界：
 * - `PayPluginInterface`：插件生命周期 + 元信息（后台可展示/可配置/可路由）。
 * - `PaymentInterface`：支付动作能力（下单/查询/关单/退款/回调）。
 *
 * 约定：
 * - `init()` 会在每次发起支付/退款等动作前由服务层调用，用于注入该通道对应的插件配置。
 * - 元信息方法应为“纯读取”，不要依赖外部状态或数据库。
 */
interface PayPluginInterface
{
    /**
     * 初始化插件（注入通道配置）
     *
     * 典型来源：`ma_payment_plugin_conf.config`，并由服务层额外合并通道信息、支付方式声明等上下文。
     * 插件应在这里完成：缓存配置、初始化 SDK/HTTP 客户端等。
     *
     * @param array<string, mixed> $channelConfig
     */
    public function init(array $channelConfig): void;

    /** 插件代码（与 ma_payment_plugin.code 对应） */
    public function getCode(): string;

    /** 插件名称（用于后台展示） */
    public function getName(): string;

    /** 插件作者名称（用于后台展示） */
    public function getAuthorName(): string;

    /** 插件作者链接（用于后台展示） */
    public function getAuthorLink(): string;

    /** 插件版本号（用于后台展示） */
    public function getVersion(): string;

    /**
     * 插件声明支持的支付方式编码
     *
     * @return array<string>
     */
    public function getEnabledPayTypes(): array;

    /** 插件声明支持的转账方式编码 */
    public function getEnabledTransferTypes(): array;

    /**
     * 插件配置结构（用于后台渲染表单/校验）
     *
     * @return array<string, mixed>
     */
    public function getConfigSchema(): array;
}

