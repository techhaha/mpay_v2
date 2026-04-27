<?php

namespace app\listener;

use app\service\system\config\SystemConfigRuntimeService;
use support\Log;

/**
 * 系统配置变更监听器。
 *
 * @property SystemConfigRuntimeService $systemConfigRuntimeService 系统配置运行时服务
 */
class SystemConfigChangedListener
{
    /**
     * 构造方法。
     *
     * @param SystemConfigRuntimeService $systemConfigRuntimeService 系统配置运行时服务
     * @return void
     */
    public function __construct(
        protected SystemConfigRuntimeService $systemConfigRuntimeService
    ) {
    }

    /**
     * 刷新系统配置运行时缓存。
     *
     * @param array $payload 请求载荷
     * @param string $eventName 事件名称
     * @return void
     */
    public function refreshRuntimeCache(array $payload = [], string $eventName = ''): void
    {
        try {
            $this->systemConfigRuntimeService->refresh();
        } catch (\Throwable $e) {
            Log::warning(sprintf(
                '[SystemConfigChangedListener] 系统配置运行时缓存刷新失败 event=%s group_code=%s error=%s',
                $eventName,
                (string) ($payload['group_code'] ?? ''),
                $e->getMessage()
            ));
        }
    }
}



