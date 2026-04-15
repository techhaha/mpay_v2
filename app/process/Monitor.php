<?php
/**
 * 这是 webman 框架自带的文件监控进程。
 *
 * 许可证信息与版权声明保持不变。
 */

namespace app\process;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Workerman\Timer;
use Workerman\Worker;

/**
 * 文件监控器。
 */
class Monitor
{
    /**
     * 监控路径列表。
     */
    protected array $paths = [];

    /**
     * 监控扩展名列表。
     */
    protected array $extensions = [];

    /**
     * 已加载文件列表。
     */
    protected array $loadedFiles = [];

    /**
     * 父进程 ID。
     */
    protected int $ppid = 0;

    /**
     * 暂停监控。
     */
    public static function pause(): void
    {
        file_put_contents(static::lockFile(), time());
    }

    /**
     * 恢复监控。
     */
    public static function resume(): void
    {
        clearstatcache();
        if (is_file(static::lockFile())) {
            unlink(static::lockFile());
        }
    }

    /**
     * 判断监控是否已暂停。
     */
    public static function isPaused(): bool
    {
        clearstatcache();
        return file_exists(static::lockFile());
    }

    /**
     * 锁文件路径。
     */
    protected static function lockFile(): string
    {
        return runtime_path('monitor.lock');
    }

    /**
     * 构造文件监控器。
     */
    public function __construct($monitorDir, $monitorExtensions, array $options = [])
    {
        $this->ppid = function_exists('posix_getppid') ? posix_getppid() : 0;
        static::resume();
        $this->paths = (array)$monitorDir;
        $this->extensions = $monitorExtensions;
        foreach (get_included_files() as $index => $file) {
            $this->loadedFiles[$file] = $index;
            if (strpos($file, 'webman-framework/src/support/App.php')) {
                break;
            }
        }
        if (!Worker::getAllWorkers()) {
            return;
        }
        $disableFunctions = explode(',', ini_get('disable_functions'));
        if (in_array('exec', $disableFunctions, true)) {
                    echo "\n由于 php.ini 的 disable_functions 禁用了 exec()，文件监控已关闭：" . PHP_CONFIG_FILE_PATH . "/php.ini\n";
        } else {
            if ($options['enable_file_monitor'] ?? true) {
                Timer::add(1, function () {
                    $this->checkAllFilesChange();
                });
            }
        }

        $memoryLimit = $this->getMemoryLimit($options['memory_limit'] ?? null);
        if ($memoryLimit && ($options['enable_memory_monitor'] ?? true)) {
            Timer::add(60, [$this, 'checkMemory'], [$memoryLimit]);
        }
    }

    /**
     * 检查指定路径是否有文件变化。
     */
    public function checkFilesChange($monitorDir): bool
    {
        static $lastMtime, $tooManyFilesCheck;
        if (!$lastMtime) {
            $lastMtime = time();
        }
        clearstatcache();
        if (!is_dir($monitorDir)) {
            if (!is_file($monitorDir)) {
                return false;
            }
            $iterator = [new SplFileInfo($monitorDir)];
        } else {
                // 递归遍历目录
            $dirIterator = new RecursiveDirectoryIterator($monitorDir, FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS);
            $iterator = new RecursiveIteratorIterator($dirIterator);
        }
        $count = 0;
        foreach ($iterator as $file) {
            $count ++;
            /** @var SplFileInfo $file */
            if (is_dir($file->getRealPath())) {
                continue;
            }
            // 检查修改时间
            if (in_array($file->getExtension(), $this->extensions, true) && $lastMtime < $file->getMTime()) {
                $lastMtime = $file->getMTime();
                if (DIRECTORY_SEPARATOR === '/' && isset($this->loadedFiles[$file->getRealPath()])) {
                    echo "$file 已更新，但无法重载，因为当前仅支持自动加载文件重载。\n";
                    continue;
                }
                $var = 0;
                exec('"'.PHP_BINARY . '" -l ' . $file, $out, $var);
                if ($var) {
                    continue;
                }
                // 向主进程发送 SIGUSR1 信号触发重载
                if (DIRECTORY_SEPARATOR === '/') {
                    if ($masterPid = $this->getMasterPid()) {
                        echo $file . " 已更新并触发重载\n";
                        posix_kill($masterPid, SIGUSR1);
                    } else {
                        echo "主进程已退出，无法重载\n";
                    }
                    return true;
                }
                echo $file . " 已更新并触发重载\n";
                return true;
            }
        }
        if (!$tooManyFilesCheck && $count > 1000) {
            echo "监控目录 $monitorDir 下文件过多（$count 个），文件监控会变慢\n";
            $tooManyFilesCheck = 1;
        }
        return false;
    }

    /**
     * @return int
     */
    public function getMasterPid(): int
    {
        if ($this->ppid === 0) {
            return 0;
        }
        if (function_exists('posix_kill') && !posix_kill($this->ppid, 0)) {
            echo "主进程已退出\n";
            return $this->ppid = 0;
        }
        if (PHP_OS_FAMILY !== 'Linux') {
            return $this->ppid;
        }
        $cmdline = "/proc/$this->ppid/cmdline";
        if (!is_readable($cmdline) || !($content = file_get_contents($cmdline)) || (!str_contains($content, 'WorkerMan') && !str_contains($content, 'php'))) {
            // 进程不存在
            $this->ppid = 0;
        }
        return $this->ppid;
    }

    /**
     * 检查所有监控路径是否有变化。
     */
    public function checkAllFilesChange(): bool
    {
        if (static::isPaused()) {
            return false;
        }
        foreach ($this->paths as $path) {
            if ($this->checkFilesChange($path)) {
                return true;
            }
        }
        return false;
    }

    /**
     * 检查子进程内存占用。
     */
    public function checkMemory($memoryLimit): void
    {
        if (static::isPaused() || $memoryLimit <= 0) {
            return;
        }
        $masterPid = $this->getMasterPid();
        if ($masterPid <= 0) {
            echo "Master process has gone away\n";
            return;
        }

        $childrenFile = "/proc/$masterPid/task/$masterPid/children";
        if (!is_file($childrenFile) || !($children = file_get_contents($childrenFile))) {
            return;
        }
        foreach (explode(' ', $children) as $pid) {
            $pid = (int)$pid;
            $statusFile = "/proc/$pid/status";
            if (!is_file($statusFile) || !($status = file_get_contents($statusFile))) {
                continue;
            }
            $mem = 0;
            if (preg_match('/VmRSS\s*?:\s*?(\d+?)\s*?kB/', $status, $match)) {
                $mem = $match[1];
            }
            $mem = (int)($mem / 1024);
            if ($mem >= $memoryLimit) {
                posix_kill($pid, SIGINT);
            }
        }
    }

    /**
     * 计算内存限制值。
     */
    protected function getMemoryLimit($memoryLimit): int
    {
        if ($memoryLimit === 0) {
            return 0;
        }
        $usePhpIni = false;
        if (!$memoryLimit) {
            $memoryLimit = ini_get('memory_limit');
            $usePhpIni = true;
        }

        if ($memoryLimit == -1) {
            return 0;
        }
        $unit = strtolower($memoryLimit[strlen($memoryLimit) - 1]);
        $memoryLimit = (int)$memoryLimit;
        if ($unit === 'g') {
            $memoryLimit = 1024 * $memoryLimit;
        } else if ($unit === 'k') {
            $memoryLimit = ($memoryLimit / 1024);
        } else if ($unit === 'm') {
            $memoryLimit = (int)($memoryLimit);
        } else if ($unit === 't') {
            $memoryLimit = (1024 * 1024 * $memoryLimit);
        } else {
            $memoryLimit = ($memoryLimit / (1024 * 1024));
        }
        if ($memoryLimit < 50) {
            $memoryLimit = 50;
        }
        if ($usePhpIni) {
            $memoryLimit = (0.8 * $memoryLimit);
        }
        return (int)$memoryLimit;
    }

}
