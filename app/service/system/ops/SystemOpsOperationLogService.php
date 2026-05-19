<?php

namespace app\service\system\ops;

use app\common\base\BaseService;

/**
 * 系统运维操作日志服务。
 *
 * 现阶段先落 runtime 文件，后续权限与审计中心完善后可平滑迁移到审计表。
 * 这里记录的是“命令已提交”的操作链路，不替代完整审计中心。
 */
class SystemOpsOperationLogService extends BaseService
{
    /**
     * 记录运维操作。
     *
     * 采用 JSONL 追加写入，便于后台展示最近记录，也便于人工 grep 排查。
     *
     * @param array<string, mixed> $data 日志数据
     * @return array<string, mixed>
     */
    public function record(array $data): array
    {
        $directory = dirname($this->logPath());
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            return $data;
        }

        $row = array_merge([
            'op_no' => $this->operationNo(),
            'created_at' => date('Y-m-d H:i:s'),
            'action' => '',
            'admin_id' => 0,
            'ip' => '',
            'user_agent' => '',
            'reason' => '',
            'status' => 'accepted',
            'message' => '',
            'command' => '',
            'output_file' => '',
        ], $data);

        @file_put_contents(
            $this->logPath(),
            (json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}') . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );

        return $row;
    }

    /**
     * 读取最近操作记录。
     *
     * 监控页只需要最近记录，最多限制 100 条，避免 runtime 日志过大时拖慢请求。
     *
     * @param int $limit 条数
     * @return array<int, array<string, mixed>>
     */
    public function latest(int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        if (!is_file($this->logPath())) {
            return [];
        }

        $lines = file($this->logPath(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $lines = array_slice($lines, -$limit);
        $rows = [];

        foreach (array_reverse($lines) as $line) {
            $row = json_decode($line, true);
            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * 运维操作日志文件。
     *
     * @return string 日志文件路径
     */
    private function logPath(): string
    {
        return runtime_path('ops' . DIRECTORY_SEPARATOR . 'system_ops.log');
    }

    /**
     * 生成运维操作编号。
     *
     * @return string 操作编号
     */
    private function operationNo(): string
    {
        return 'OPS' . date('YmdHis') . random_int(1000, 9999);
    }
}
