<?php
/**
 * Start file for windows
 */
chdir(__DIR__);
require_once __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;
use support\App;
use Workerman\Worker;

ini_set('display_errors', 'on');
error_reporting(E_ALL);

if (class_exists('Dotenv\Dotenv') && file_exists(base_path() . '/.env')) {
    if (method_exists('Dotenv\Dotenv', 'createUnsafeImmutable')) {
        Dotenv::createUnsafeImmutable(base_path())->load();
    } else {
        Dotenv::createMutable(base_path())->load();
    }
}

App::loadAllConfig(['route']);

$errorReporting = config('app.error_reporting');
if (isset($errorReporting)) {
    error_reporting($errorReporting);
}

$runtimeProcessPath = runtime_path() . DIRECTORY_SEPARATOR . 'windows';
$runtimeRunPath = $runtimeProcessPath . DIRECTORY_SEPARATOR . 'run_' . getmypid() . '_' . date('YmdHis');
$paths = [
    $runtimeProcessPath,
    $runtimeRunPath,
    runtime_path('logs'),
    runtime_path('views')
];
foreach ($paths as $path) {
    if (!is_dir($path)) {
        mkdir($path, 0777, true);
    }
}
cleanup_windows_runtime($runtimeProcessPath);

$processFiles = [];
if (config('server.listen')) {
    $processFiles[] = __DIR__ . DIRECTORY_SEPARATOR . 'start.php';
}
foreach (config('process', []) as $processName => $config) {
    $processFiles[] = write_process_file($runtimeRunPath, $processName, '');
}

foreach (config('plugin', []) as $firm => $projects) {
    foreach ($projects as $name => $project) {
        if (!is_array($project)) {
            continue;
        }
        foreach ($project['process'] ?? [] as $processName => $config) {
            $processFiles[] = write_process_file($runtimeRunPath, $processName, "$firm.$name");
        }
    }
    foreach ($projects['process'] ?? [] as $processName => $config) {
        $processFiles[] = write_process_file($runtimeRunPath, $processName, $firm);
    }
}

function cleanup_windows_runtime(string $runtimeProcessPath): void
{
    foreach (glob($runtimeProcessPath . DIRECTORY_SEPARATOR . 'start_*.php') ?: [] as $processFile) {
        @unlink($processFile);
    }

    foreach (glob($runtimeProcessPath . DIRECTORY_SEPARATOR . 'run_*') ?: [] as $runPath) {
        if (!is_dir($runPath) || time() - (int) filemtime($runPath) < 86400) {
            continue;
        }

        foreach (glob($runPath . DIRECTORY_SEPARATOR . 'start_*.php') ?: [] as $processFile) {
            @unlink($processFile);
        }
        @rmdir($runPath);
    }
}

function write_process_file($runtimeProcessPath, $processName, $firm): string
{
    $processParam = $firm ? "plugin.$firm.$processName" : $processName;
    $configParam = $firm ? "config('plugin.$firm.process')['$processName']" : "config('process')['$processName']";
    $autoloadFile = var_export(base_path(false) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php', true);
    $fileContent = <<<EOF
<?php
require_once $autoloadFile;

use Workerman\Worker;
use Workerman\Connection\TcpConnection;
use Webman\Config;
use support\App;

ini_set('display_errors', 'on');
error_reporting(E_ALL);

if (is_callable('opcache_reset')) {
    opcache_reset();
}

if (!\$appConfigFile = config_path('app.php')) {
    throw new RuntimeException('Config file not found: app.php');
}
\$appConfig = require \$appConfigFile;
if (\$timezone = \$appConfig['default_timezone'] ?? '') {
    date_default_timezone_set(\$timezone);
}

App::loadAllConfig(['route']);

worker_start('$processParam', $configParam);

if (DIRECTORY_SEPARATOR != "/") {
    Worker::\$logFile = config('server')['log_file'] ?? Worker::\$logFile;
    TcpConnection::\$defaultMaxPackageSize = config('server')['max_package_size'] ?? 10*1024*1024;
}

Worker::runAll();

EOF;
    $processFile = $runtimeProcessPath . DIRECTORY_SEPARATOR . "start_$processParam.php";
    file_put_contents($processFile, $fileContent);
    return $processFile;
}

if ($monitorConfig = config('process.monitor.constructor')) {
    $monitorHandler = config('process.monitor.handler');
    $monitor = new $monitorHandler(...array_values($monitorConfig));
}

function popen_processes($processFiles)
{
    $cmd = '"' . PHP_BINARY . '" ' . implode(' ', $processFiles);
    $descriptorspec = [STDIN, STDOUT, STDOUT];
    $resource = proc_open($cmd, $descriptorspec, $pipes, null, null, ['bypass_shell' => true]);
    if (!$resource) {
        exit("Can not execute $cmd\r\n");
    }
    return $resource;
}

function read_windows_control(string $controlFile): ?array
{
    if (!is_file($controlFile)) {
        return null;
    }

    $payload = json_decode((string) file_get_contents($controlFile), true);
    @unlink($controlFile);

    return is_array($payload) && in_array((string) ($payload['action'] ?? ''), ['reload', 'restart'], true)
        ? $payload
        : null;
}

function restart_windows_processes($resource, array $processFiles, array $control = [])
{
    $status = proc_get_status($resource);
    $pid = (int) ($status['pid'] ?? 0);
    $actionText = (string) ($control['action_text'] ?? '重载服务');

    echo sprintf("[%s] %s，正在重启 Windows 子进程...\r\n", date('Y-m-d H:i:s'), $actionText);
    if ($pid > 0) {
        shell_exec("taskkill /F /T /PID $pid");
    }
    proc_close($resource);

    $newResource = popen_processes($processFiles);
    echo sprintf("[%s] Windows 子进程已重新拉起。\r\n", date('Y-m-d H:i:s'));

    $outputFile = (string) ($control['output_file'] ?? '');
    if ($outputFile !== '') {
        @file_put_contents(
            $outputFile,
            sprintf("[%s] Windows child processes restarted by windows.php.\r\n", date('Y-m-d H:i:s')),
            FILE_APPEND | LOCK_EX
        );
    }

    return $newResource;
}

$resource = popen_processes($processFiles);
$controlFile = runtime_path('ops' . DIRECTORY_SEPARATOR . 'windows_control.json');
echo "\r\n";
while (1) {
    sleep(1);
    if ($control = read_windows_control($controlFile)) {
        $resource = restart_windows_processes($resource, $processFiles, $control);
        continue;
    }
    if (!empty($monitor) && $monitor->checkAllFilesChange()) {
        $resource = restart_windows_processes($resource, $processFiles, [
            'action_text' => '文件变更触发重载',
        ]);
    }
}
