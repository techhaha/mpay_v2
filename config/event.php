<?php

return [
    'system.config.changed' => [app\listener\SystemConfigChangedListener::class, 'refreshRuntimeCache'],
];
