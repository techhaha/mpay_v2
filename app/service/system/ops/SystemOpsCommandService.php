<?php

namespace app\service\system\ops;

use app\common\base\BaseService;
use RuntimeException;

/**
 * 系统运维命令服务。
 *
 * 只允许白名单动作，避免管理后台变成任意命令执行入口。
 */
class SystemOpsCommandService extends BaseService
{
    /**
     * 运维动作白名单。
     *
     * 控制器传入的 action 必须命中这里，避免把管理后台做成任意命令执行入口。
     */
    private const ACTIONS = [
        'reload' => '平滑重载',
        'restart' => '重启服务',
    ];

    /**
     * Linux 后台命令执行器优先级。
     */
    private const LINUX_EXECUTORS = ['proc_open', 'exec', 'shell_exec'];

    /**
     * 构造方法。
     *
     * @param SystemOpsOperationLogService $operationLogService 系统运维操作日志服务
     * @return void
     */
    public function __construct(
        protected SystemOpsOperationLogService $operationLogService
    ) {
    }

    /**
     * 执行运维动作。
     *
     * 这里只负责校验、落操作记录并异步提交命令，不等待 Webman 完成重载或重启。
     * 页面后续通过刷新总览观察进程心跳和 PID 变化。
     *
     * @param string $action 动作
     * @param int $adminId 管理员 ID
     * @param array<string, mixed> $context 请求上下文
     * @return array<string, mixed>
     */
    public function execute(string $action, int $adminId, array $context = []): array
    {
        if (!isset(self::ACTIONS[$action])) {
            throw new RuntimeException('不支持的运维动作');
        }

        $reason = trim((string) ($context['reason'] ?? ''));
        if ($action === 'restart' && $reason === '') {
            throw new RuntimeException('重启服务必须填写操作原因');
        }

        $this->ensureCommandAvailable();
        $this->ensureLock();

        $outputFile = runtime_path('ops' . DIRECTORY_SEPARATOR . 'webman-' . $action . '-' . date('YmdHis') . '.log');
        $command = $this->buildCommand($action);
        $row = $this->operationLogService->record([
            'action' => $action,
            'action_text' => self::ACTIONS[$action],
            'admin_id' => $adminId,
            'ip' => (string) ($context['ip'] ?? ''),
            'user_agent' => (string) ($context['user_agent'] ?? ''),
            'reason' => $reason,
            'status' => 'accepted',
            'message' => self::ACTIONS[$action] . '指令已提交',
            'command' => implode(' ', array_map([$this, 'quoteForDisplay'], $command)),
            'output_file' => $outputFile,
        ]);

        if (DIRECTORY_SEPARATOR === '\\') {
            $this->dispatchWindowsSignal($action, $outputFile, $row);
        } else {
            $this->dispatch($command, $outputFile);
        }

        return $row;
    }

    /**
     * 校验 Webman 命令入口和后台提交能力。
     *
     * @return void
     * @throws RuntimeException
     */
    private function ensureCommandAvailable(): void
    {
        $entry = DIRECTORY_SEPARATOR === '\\'
            ? base_path(false) . DIRECTORY_SEPARATOR . 'windows.php'
            : base_path(false) . DIRECTORY_SEPARATOR . 'webman';
        if (!is_file($entry)) {
            throw new RuntimeException('未找到 Webman 命令入口');
        }

        if (DIRECTORY_SEPARATOR !== '\\' && $this->availableExecutor() === '') {
            throw new RuntimeException('当前 PHP 环境禁用了 proc_open、exec、shell_exec，无法提交后台运维命令');
        }
    }

    /**
     * 短时间锁定运维命令提交。
     *
     * 重启/重载是高风险动作，文件锁用于防止页面重复点击造成连续提交。
     * 20 秒后视为陈旧锁，避免异常退出后永久阻塞。
     *
     * @return void
     * @throws RuntimeException
     */
    private function ensureLock(): void
    {
        $lock = $this->lockFile();
        $directory = dirname($lock);
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException('无法创建运维运行目录');
        }

        clearstatcache(true, $lock);
        if (is_file($lock) && time() - (int) filemtime($lock) < 20) {
            throw new RuntimeException('已有运维指令正在提交，请稍后再试');
        }

        file_put_contents($lock, (string) time(), LOCK_EX);
    }

    /**
     * 构建白名单命令参数。
     *
     * Linux 下 restart 使用 -d 后台模式，Windows 开发环境交给 start /B 处理。
     *
     * @param string $action 动作
     * @return array<int, string>
     */
    private function buildCommand(string $action): array
    {
        $command = [
            PHP_BINARY,
            DIRECTORY_SEPARATOR === '\\'
                ? base_path(false) . DIRECTORY_SEPARATOR . 'windows.php'
                : base_path(false) . DIRECTORY_SEPARATOR . 'webman',
            $action,
        ];

        if ($action === 'restart' && DIRECTORY_SEPARATOR === '/') {
            $command[] = '-d';
        }

        return $command;
    }

    /**
     * Windows 下通过控制文件通知 windows.php 管理进程重启子进程。
     *
     * Workerman Windows 模式不支持 `php webman reload/restart`，因此按钮动作写入控制文件，
     * 由正在运行的 windows.php 主循环接收后重启已托管的子进程。
     *
     * @param string $action 动作
     * @param string $outputFile 输出文件
     * @param array<string, mixed> $operation 操作记录
     * @return void
     */
    private function dispatchWindowsSignal(string $action, string $outputFile, array $operation): void
    {
        $directory = dirname($outputFile);
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException('无法创建命令输出目录');
        }

        $payload = [
            'op_no' => (string) ($operation['op_no'] ?? ''),
            'action' => $action,
            'action_text' => self::ACTIONS[$action],
            'created_at' => date('Y-m-d H:i:s'),
            'reason' => (string) ($operation['reason'] ?? ''),
            'output_file' => $outputFile,
        ];
        $content = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($content === false || file_put_contents($this->windowsControlFile(), $content . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('写入 Windows 运维控制文件失败');
        }

        file_put_contents(
            $outputFile,
            sprintf("[%s] Windows control signal submitted: %s\r\n", date('Y-m-d H:i:s'), self::ACTIONS[$action]),
            FILE_APPEND | LOCK_EX
        );
    }

    /**
     * 异步提交命令并把输出写入 runtime/ops。
     *
     * 请求线程不读取命令输出，避免重启过程中阻塞后台接口。
     *
     * @param array<int, string> $command 命令数组
     * @param string $outputFile 输出文件
     * @return void
     * @throws RuntimeException
     */
    private function dispatch(array $command, string $outputFile): void
    {
        $directory = dirname($outputFile);
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException('无法创建命令输出目录');
        }

        $commandLine = implode(' ', array_map([$this, 'quoteForShell'], $command));
        $output = $this->quoteForShell($outputFile);
        $backgroundCommand = DIRECTORY_SEPARATOR === '\\'
            ? 'start /B "" ' . $commandLine . ' > ' . $output . ' 2>&1'
            : $commandLine . ' > ' . $output . ' 2>&1 &';

        if (DIRECTORY_SEPARATOR !== '\\') {
            $executor = $this->availableExecutor();
            if ($executor === '') {
                throw new RuntimeException('当前 PHP 环境禁用了 proc_open、exec、shell_exec，无法提交后台运维命令');
            }
            if (!$this->dispatchLinuxCommand($backgroundCommand, $executor)) {
                throw new RuntimeException('运维命令提交失败，执行器：' . $executor);
            }
            return;
        }

        $null = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
        $process = @proc_open($backgroundCommand, [
            0 => ['file', $null, 'r'],
            1 => ['file', $null, 'a'],
            2 => ['file', $null, 'a'],
        ], $pipes, base_path(false));
        if (!is_resource($process)) {
            throw new RuntimeException('运维命令提交失败');
        }

        $exitCode = proc_close($process);
        if ($exitCode !== 0 && $exitCode !== -1) {
            throw new RuntimeException('运维命令提交失败');
        }
    }

    /**
     * 获取可用的 Linux 后台命令执行器。
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
     * 使用指定执行器提交 Linux 后台命令。
     *
     * @param string $command 命令行
     * @param string $executor 执行器
     * @return bool 是否提交成功
     */
    private function dispatchLinuxCommand(string $command, string $executor): bool
    {
        if ($executor === 'exec') {
            @exec($command, $output, $exitCode);
            return $exitCode === 0;
        }

        if ($executor === 'shell_exec') {
            $result = @shell_exec($command . ' ; echo MPAY_EXIT:$?');
            return is_string($result) && str_contains($result, 'MPAY_EXIT:0');
        }

        $null = '/dev/null';
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
     * 运维命令提交锁文件。
     *
     * @return string 锁文件路径
     */
    private function lockFile(): string
    {
        return runtime_path('ops' . DIRECTORY_SEPARATOR . 'system_ops_command.lock');
    }

    /**
     * Windows 运维控制文件。
     *
     * @return string 控制文件路径
     */
    private function windowsControlFile(): string
    {
        return runtime_path('ops' . DIRECTORY_SEPARATOR . 'windows_control.json');
    }

    /**
     * 按当前系统转义命令参数。
     *
     * @param string $value 参数值
     * @return string 转义后的参数
     */
    private function quoteForShell(string $value): string
    {
        if (DIRECTORY_SEPARATOR !== '\\') {
            return escapeshellarg($value);
        }

        return '"' . str_replace('"', '\"', $value) . '"';
    }

    /**
     * 生成操作日志里便于阅读的命令文本。
     *
     * @param string $value 参数值
     * @return string 展示文本
     */
    private function quoteForDisplay(string $value): string
    {
        return str_contains($value, ' ') ? '"' . $value . '"' : $value;
    }
}
