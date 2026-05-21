<?php

namespace app\listener;

/**
 * 安装完成监听器。
 */
class InstallCompletedListener
{
    /**
     * Linux 后台命令执行器优先级。
     */
    private const LINUX_EXECUTORS = ['proc_open', 'exec', 'shell_exec'];

    /**
     * 安装完成后提交服务重启通知。
     *
     * @param array<string, mixed> $payload 事件数据
     * @param string $eventName 事件名称
     * @return void
     */
    public function requestRestart(array $payload = [], string $eventName = ''): void
    {
        $restart = $this->restartPayload($payload, $eventName);
        $this->writeRestartRequired($restart);

        if (DIRECTORY_SEPARATOR === '\\') {
            $this->writeWindowsControl($restart);
            return;
        }

        $this->dispatchLinuxRestart($restart);
    }

    /**
     * 构建重启通知数据。
     *
     * @param array<string, mixed> $payload 事件数据
     * @param string $eventName 事件名称
     * @return array<string, mixed>
     */
    private function restartPayload(array $payload, string $eventName): array
    {
        return [
            'event' => $eventName,
            'action' => 'restart',
            'action_text' => '安装完成自动重启服务',
            'reason' => '安装已写入 .env，需要重启 Webman 重新读取配置',
            'created_at' => date('Y-m-d H:i:s'),
            'installed_at' => (string) ($payload['installed_at'] ?? date('Y-m-d H:i:s')),
            'status' => 'pending',
        ];
    }

    /**
     * 写入重启需求通知文件。
     *
     * @param array<string, mixed> $payload 通知数据
     * @return void
     */
    private function writeRestartRequired(array $payload): void
    {
        $path = runtime_path('install' . DIRECTORY_SEPARATOR . 'restart_required.json');
        $this->writeJson($path, $payload);
    }

    /**
     * Windows 下通知 windows.php 主循环重启子进程。
     *
     * @param array<string, mixed> $payload 通知数据
     * @return void
     */
    private function writeWindowsControl(array $payload): void
    {
        $payload['status'] = 'submitted';
        $payload['output_file'] = runtime_path('ops' . DIRECTORY_SEPARATOR . 'install-restart-' . date('YmdHis') . '.log');
        $this->writeRestartRequired($payload);
        $this->writeJson(runtime_path('ops' . DIRECTORY_SEPARATOR . 'windows_control.json'), $payload);
    }

    /**
     * Linux 下异步提交 restart 命令。
     *
     * @param array<string, mixed> $payload 通知数据
     * @return void
     */
    private function dispatchLinuxRestart(array $payload): void
    {
        $entry = base_path(false) . DIRECTORY_SEPARATOR . 'webman';
        if (!is_file($entry)) {
            $payload['status'] = 'failed';
            $payload['message'] = '未找到 Webman 命令入口';
            $this->writeRestartRequired($payload);
            return;
        }

        $executor = $this->availableExecutor();
        if ($executor === '') {
            $payload['status'] = 'failed';
            $payload['message'] = '当前 PHP 环境禁用了 proc_open、exec、shell_exec，无法自动提交重启命令';
            $this->writeRestartRequired($payload);
            return;
        }

        $outputFile = runtime_path('ops' . DIRECTORY_SEPARATOR . 'install-restart-' . date('YmdHis') . '.log');
        $directory = dirname($outputFile);
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            $payload['status'] = 'failed';
            $payload['message'] = '无法创建命令输出目录';
            $this->writeRestartRequired($payload);
            return;
        }

        $payload['status'] = 'submitted';
        $payload['output_file'] = $outputFile;
        $payload['executor'] = $executor;
        $this->writeRestartRequired($payload);

        $command = sprintf(
            '(sleep 1; %s %s restart -d) > %s 2>&1 &',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($entry),
            escapeshellarg($outputFile)
        );

        if (!$this->dispatchBackgroundCommand($command, $executor)) {
            $payload['status'] = 'failed';
            $payload['message'] = '自动重启命令提交失败，执行器：' . $executor;
            $this->writeRestartRequired($payload);
        }
    }

    /**
     * 获取可用的后台命令执行器。
     *
     * @return string 执行器名称，空字符串表示不可用
     */
    private function availableExecutor(): string
    {
        foreach (self::LINUX_EXECUTORS as $function) {
            if ($this->functionEnabled($function)) {
                return $function;
            }
        }

        return '';
    }

    /**
     * 检测函数是否可用。
     *
     * @param string $function 函数名
     * @return bool
     */
    private function functionEnabled(string $function): bool
    {
        $disabled = array_filter(array_map('trim', explode(',', (string) ini_get('disable_functions'))));
        return function_exists($function) && !in_array($function, $disabled, true);
    }

    /**
     * 提交后台命令。
     *
     * @param string $command 命令行
     * @param string $executor 执行器
     * @return bool 是否提交成功
     */
    private function dispatchBackgroundCommand(string $command, string $executor): bool
    {
        if ($executor === 'exec') {
            @exec($command, $output, $exitCode);
            return $exitCode === 0;
        }

        if ($executor === 'shell_exec') {
            $result = @shell_exec($command . ' ; echo MPAY_EXIT:$?');
            return is_string($result) && str_contains($result, 'MPAY_EXIT:0');
        }

        $null = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
        $process = @proc_open($command, [
            0 => ['file', $null, 'r'],
            1 => ['file', $null, 'a'],
            2 => ['file', $null, 'a'],
        ], $pipes, base_path(false));

        if (!is_resource($process)) {
            return false;
        }

        $exitCode = proc_close($process);
        return $exitCode === 0 || $exitCode === -1;
    }

    /**
     * 写入 JSON 文件。
     *
     * @param string $path 文件路径
     * @param array<string, mixed> $payload 数据
     * @return void
     */
    private function writeJson(string $path, array $payload): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            return;
        }

        $content = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($content !== false) {
            @file_put_contents($path, $content . PHP_EOL, LOCK_EX);
        }
    }
}
