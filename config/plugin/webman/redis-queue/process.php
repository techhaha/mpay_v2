<?php
// Redis queue workers depend on the installed Redis configuration. During the
// first install boot, only the web installer should run.
if (!is_file(base_path() . '/runtime/install.lock')) {
    return [];
}

return [
    'consumer'  => [
        'handler'     => Webman\RedisQueue\Process\Consumer::class,
        'count'       => 8, // 可以设置多进程同时消费
        'constructor' => [
            // 消费者类目录
            'consumer_dir' => app_path() . '/queue/redis'
        ]
    ]
];
