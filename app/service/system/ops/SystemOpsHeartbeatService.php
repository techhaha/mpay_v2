<?php

namespace app\service\system\ops;

use app\common\base\BaseService;

/**
 * 系统运维心跳服务。
 *
 * 长驻进程通过轻量文件心跳上报运行状态，避免监控页依赖数据库或 Redis。
 * 心跳是观测能力，写入失败不能反向影响支付、监听等业务进程。
 */
class SystemOpsHeartbeatService extends BaseService
{
    /**
     * 写入进程心跳。
     *
     * 使用按进程名拆分的 JSON 文件，便于监控页读取，也方便人工排查 runtime 状态。
     *
     * @param string $name 进程名称
     * @param array<string, mixed> $payload 心跳内容
     * @return void
     */
    public function report(string $name, array $payload = []): void
    {
        $name = trim($name);
        if ($name === '') {
            return;
        }

        $directory = $this->heartbeatDirectory();
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            return;
        }

        $now = time();
        $data = [
            'name' => $name,
            'pid' => (int) ($payload['pid'] ?? getmypid()),
            'last_tick_at' => $now,
            'last_tick_at_text' => date('Y-m-d H:i:s', $now),
            'payload' => $payload,
        ];

        @file_put_contents(
            $directory . DIRECTORY_SEPARATOR . $this->safeName($name) . '.json',
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '{}',
            LOCK_EX
        );
    }

    /**
     * 读取指定进程心跳。
     *
     * @param string $name 进程名称
     * @return array<string, mixed>|null
     */
    public function get(string $name): ?array
    {
        $path = $this->heartbeatDirectory() . DIRECTORY_SEPARATOR . $this->safeName($name) . '.json';
        if (!is_file($path)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? $data : null;
    }

    /**
     * 读取所有进程心跳。
     *
     * 返回值按原始进程名索引，调用方可以直接与 config/process.php 的进程名匹配。
     *
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        $items = [];
        foreach (glob($this->heartbeatDirectory() . DIRECTORY_SEPARATOR . '*.json') ?: [] as $path) {
            $data = json_decode((string) file_get_contents($path), true);
            if (is_array($data) && isset($data['name'])) {
                $items[(string) $data['name']] = $data;
            }
        }

        return $items;
    }

    /**
     * 心跳文件目录。
     *
     * @return string 心跳目录
     */
    private function heartbeatDirectory(): string
    {
        return runtime_path('ops' . DIRECTORY_SEPARATOR . 'heartbeat');
    }

    /**
     * 将进程名转换为安全文件名。
     *
     * @param string $name 进程名称
     * @return string 安全文件名
     */
    private function safeName(string $name): string
    {
        return preg_replace('/[^a-zA-Z0-9_.-]/', '_', $name) ?: 'process';
    }
}
