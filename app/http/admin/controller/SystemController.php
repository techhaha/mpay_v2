<?php

namespace app\http\admin\controller;

use app\common\base\BaseController;
use app\services\SystemConfigService;
use app\services\SystemSettingService;
use support\Db;
use support\Request;

class SystemController extends BaseController
{
    public function __construct(
        protected SystemSettingService $settingService,
        protected SystemConfigService $configService
    ) {
    }

    public function getDict(Request $request, string $code = '')
    {
        $data = $this->settingService->getDict($code);
        return $this->success($data);
    }

    public function getTabsConfig()
    {
        return $this->success($this->settingService->getTabs());
    }

    public function getFormConfig(Request $request, string $tabKey)
    {
        return $this->success($this->settingService->getFormConfig($tabKey));
    }

    public function submitConfig(Request $request, string $tabKey)
    {
        $formData = $request->post();
        if (empty($formData)) {
            return $this->fail('submitted data is empty', 400);
        }

        $this->settingService->saveFormConfig($tabKey, $formData);
        return $this->success(null, 'saved');
    }

    public function logFiles()
    {
        $logDir = runtime_path('logs');
        if (!is_dir($logDir)) {
            return $this->success([]);
        }

        $items = [];
        foreach (glob($logDir . DIRECTORY_SEPARATOR . '*.log') ?: [] as $file) {
            if (!is_file($file)) {
                continue;
            }

            $items[] = [
                'name' => basename($file),
                'size' => filesize($file) ?: 0,
                'updated_at' => date('Y-m-d H:i:s', filemtime($file) ?: time()),
            ];
        }

        usort($items, fn ($a, $b) => strcmp((string)$b['updated_at'], (string)$a['updated_at']));

        return $this->success($items);
    }

    public function logSummary()
    {
        $logDir = runtime_path('logs');
        if (!is_dir($logDir)) {
            return $this->success([
                'total_files' => 0,
                'total_size' => 0,
                'latest_file' => '',
                'categories' => [],
            ]);
        }

        $files = glob($logDir . DIRECTORY_SEPARATOR . '*.log') ?: [];
        $categoryStats = [];
        $totalSize = 0;
        $latestFile = '';
        $latestTime = 0;

        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }

            $size = filesize($file) ?: 0;
            $updatedAt = filemtime($file) ?: 0;
            $name = basename($file);
            $category = $this->resolveLogCategory($name);

            $totalSize += $size;
            if (!isset($categoryStats[$category])) {
                $categoryStats[$category] = [
                    'category' => $category,
                    'file_count' => 0,
                    'total_size' => 0,
                ];
            }

            $categoryStats[$category]['file_count']++;
            $categoryStats[$category]['total_size'] += $size;

            if ($updatedAt >= $latestTime) {
                $latestTime = $updatedAt;
                $latestFile = $name;
            }
        }

        return $this->success([
            'total_files' => count($files),
            'total_size' => $totalSize,
            'latest_file' => $latestFile,
            'categories' => array_values($categoryStats),
        ]);
    }

    public function logContent(Request $request)
    {
        $file = basename(trim((string)$request->get('file', '')));
        $lines = max(20, min(1000, (int)$request->get('lines', 200)));
        $keyword = trim((string)$request->get('keyword', ''));
        $level = strtoupper(trim((string)$request->get('level', '')));
        if ($file === '') {
            return $this->fail('file is required', 400);
        }

        $logDir = runtime_path('logs');
        $fullPath = realpath($logDir . DIRECTORY_SEPARATOR . $file);
        $realLogDir = realpath($logDir);

        if (!$fullPath || !$realLogDir || !str_starts_with($fullPath, $realLogDir) || !is_file($fullPath)) {
            return $this->fail('log file not found', 404);
        }

        $contentLines = file($fullPath, FILE_IGNORE_NEW_LINES);
        if (!is_array($contentLines)) {
            return $this->fail('failed to read log file', 500);
        }

        if ($keyword !== '') {
            $contentLines = array_values(array_filter($contentLines, static function ($line) use ($keyword) {
                return stripos($line, $keyword) !== false;
            }));
        }

        if ($level !== '') {
            $contentLines = array_values(array_filter($contentLines, static function ($line) use ($level) {
                return stripos(strtoupper($line), $level) !== false;
            }));
        }

        $matchedLineCount = count($contentLines);
        $tail = array_slice($contentLines, -$lines);
        return $this->success([
            'file' => $file,
            'size' => filesize($fullPath) ?: 0,
            'updated_at' => date('Y-m-d H:i:s', filemtime($fullPath) ?: time()),
            'line_count' => $matchedLineCount,
            'keyword' => $keyword,
            'level' => $level,
            'lines' => $tail,
            'content' => implode(PHP_EOL, $tail),
        ]);
    }

    public function noticeOverview()
    {
        $config = $this->configService->getValues([
            'smtp_host',
            'smtp_port',
            'smtp_ssl',
            'smtp_username',
            'smtp_password',
            'from_email',
            'from_name',
        ]);

        $taskSummary = Db::table('ma_notify_task')
            ->selectRaw(
                'COUNT(*) AS total_tasks,
                SUM(CASE WHEN status = \'PENDING\' THEN 1 ELSE 0 END) AS pending_tasks,
                SUM(CASE WHEN status = \'SUCCESS\' THEN 1 ELSE 0 END) AS success_tasks,
                SUM(CASE WHEN status = \'FAIL\' THEN 1 ELSE 0 END) AS fail_tasks,
                MAX(last_notify_at) AS last_notify_at'
            )
            ->first();

        $orderSummary = Db::table('ma_pay_order')
            ->selectRaw(
                'SUM(CASE WHEN status = 1 AND notify_stat = 0 THEN 1 ELSE 0 END) AS notify_pending_orders,
                SUM(CASE WHEN status = 1 AND notify_stat = 1 THEN 1 ELSE 0 END) AS notified_orders'
            )
            ->first();

        $requiredKeys = ['smtp_host', 'smtp_port', 'smtp_username', 'smtp_password', 'from_email'];
        $configuredCount = 0;
        foreach ($requiredKeys as $key) {
            if (!empty($config[$key])) {
                $configuredCount++;
            }
        }

        return $this->success([
            'config' => [
                'smtp_host' => (string)($config['smtp_host'] ?? ''),
                'smtp_port' => (string)($config['smtp_port'] ?? ''),
                'smtp_ssl' => in_array(strtolower((string)($config['smtp_ssl'] ?? '')), ['1', 'true', 'yes', 'on'], true),
                'smtp_username' => $this->maskString((string)($config['smtp_username'] ?? '')),
                'from_email' => (string)($config['from_email'] ?? ''),
                'from_name' => (string)($config['from_name'] ?? ''),
                'configured_fields' => $configuredCount,
                'required_fields' => count($requiredKeys),
                'is_ready' => $configuredCount === count($requiredKeys),
            ],
            'tasks' => [
                'total_tasks' => (int)($taskSummary->total_tasks ?? 0),
                'pending_tasks' => (int)($taskSummary->pending_tasks ?? 0),
                'success_tasks' => (int)($taskSummary->success_tasks ?? 0),
                'fail_tasks' => (int)($taskSummary->fail_tasks ?? 0),
                'last_notify_at' => (string)($taskSummary->last_notify_at ?? ''),
            ],
            'orders' => [
                'notify_pending_orders' => (int)($orderSummary->notify_pending_orders ?? 0),
                'notified_orders' => (int)($orderSummary->notified_orders ?? 0),
            ],
        ]);
    }

    public function noticeTest(Request $request)
    {
        $config = $this->configService->getValues([
            'smtp_host',
            'smtp_port',
            'smtp_ssl',
            'smtp_username',
            'smtp_password',
            'from_email',
        ]);

        $missingFields = [];
        foreach (['smtp_host', 'smtp_port', 'smtp_username', 'smtp_password', 'from_email'] as $field) {
            if (empty($config[$field])) {
                $missingFields[] = $field;
            }
        }

        if ($missingFields !== []) {
            return $this->fail('missing config fields: ' . implode(', ', $missingFields), 400);
        }

        $host = (string)$config['smtp_host'];
        $port = (int)$config['smtp_port'];
        $useSsl = in_array(strtolower((string)($config['smtp_ssl'] ?? '')), ['1', 'true', 'yes', 'on'], true);
        $transport = ($useSsl ? 'ssl://' : 'tcp://') . $host . ':' . $port;

        $errno = 0;
        $errstr = '';
        $connection = @stream_socket_client($transport, $errno, $errstr, 5, STREAM_CLIENT_CONNECT);

        if (!is_resource($connection)) {
            return $this->fail('smtp connection failed: ' . ($errstr !== '' ? $errstr : 'unknown error'), 500);
        }

        stream_set_timeout($connection, 3);
        $banner = fgets($connection, 512) ?: '';
        fclose($connection);

        return $this->success([
            'transport' => $transport,
            'banner' => trim($banner),
            'checked_at' => date('Y-m-d H:i:s'),
            'note' => 'only smtp connectivity and basic config were verified; no test email was sent',
        ], 'smtp connection ok');
    }

    private function resolveLogCategory(string $fileName): string
    {
        $name = strtolower($fileName);
        if (str_contains($name, 'pay') || str_contains($name, 'notify')) {
            return 'payment';
        }
        if (str_contains($name, 'queue') || str_contains($name, 'job')) {
            return 'queue';
        }
        if (str_contains($name, 'error') || str_contains($name, 'exception')) {
            return 'error';
        }
        if (str_contains($name, 'admin') || str_contains($name, 'system')) {
            return 'system';
        }

        return 'other';
    }

    private function maskString(string $value): string
    {
        $length = strlen($value);
        if ($length <= 4) {
            return $value === '' ? '' : str_repeat('*', $length);
        }

        return substr($value, 0, 2) . str_repeat('*', max(2, $length - 4)) . substr($value, -2);
    }
}
