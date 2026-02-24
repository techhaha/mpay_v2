<?php

return [
    // 系统配置更新后重新加载缓存
    'system.config.updated' => [
        [app\events\SystemConfig::class, 'reload'],
    ],
];
