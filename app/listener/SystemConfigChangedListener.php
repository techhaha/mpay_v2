<?php

namespace app\listener;

use app\service\system\config\SystemConfigRuntimeService;

class SystemConfigChangedListener
{
    public function __construct(
        protected SystemConfigRuntimeService $systemConfigRuntimeService
    ) {
    }

    public function refreshRuntimeCache(array $payload = [], string $eventName = ''): void
    {
        $this->systemConfigRuntimeService->refresh();
    }
}
