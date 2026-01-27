<?php

namespace app\common\base;

use support\Log;

/**
 * 服务基础类
 * - 提供日志记录能力
 * - 预留事务、事件发布等扩展点
 */
abstract class BaseService
{
    /**
     * 记录日志
     */
    protected function log(string $level, string $message, array $context = []): void
    {
        $logMessage = sprintf('[%s] %s', static::class, $message);
        Log::log($level, $logMessage, $context);
    }

    /**
     * 记录信息日志
     */
    protected function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    /**
     * 记录警告日志
     */
    protected function warning(string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    /**
     * 记录错误日志
     */
    protected function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    /**
     * 记录调试日志
     */
    protected function debug(string $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }
}


