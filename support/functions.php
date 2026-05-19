<?php

use app\service\system\config\SystemConfigRuntimeService;
use support\Container;

if (!function_exists('container_get')) {
    /**
     * 从容器中获取实例。
     *
     * 用于无运行时构造参数、可复用的服务。当前项目使用 PHP-DI 容器，
     * get 会按容器规则解析依赖并复用同一个实例。
     *
     * @param string $name 类名或接口名
     * @return mixed
     */
    function container_get(string $name): mixed
    {
        return Container::get($name);
    }
}

if (!function_exists('container_make')) {
    /**
     * 从容器中创建新实例。
     *
     * 用于需要每次独立实例化的对象，例如携带运行时配置的支付插件。
     * 业务服务默认优先使用 container_get。
     *
     * @param string $name 类名或接口名
     * @param array $parameters 构造参数
     * @return mixed
     */
    function container_make(string $name, array $parameters = []): mixed
    {
        return Container::make($name, $parameters);
    }
}

if (!function_exists('sys_config')) {
    /**
     * 获取系统配置项。
     *
     * 值来源于 ma_system_config，并通过 SystemConfigRuntimeService 读取缓存。
     * 系统配置值统一按字符串存储，业务侧按需转换类型。
     *
     * @param string $key 配置键名（config_key）
     * @param mixed $default 默认值
     * @param bool $refresh 是否强制刷新缓存后再读取
     * @return mixed
     */
    function sys_config(string $key, mixed $default = null, bool $refresh = false): mixed
    {
        /** @var SystemConfigRuntimeService $service */
        $service = Container::get(SystemConfigRuntimeService::class);

        return $service->get($key, $default, $refresh);
    }
}
