<?php

namespace app\service\install;

use app\common\base\BaseService;

/**
 * 安装锁服务。
 */
class InstallLockService extends BaseService
{
    /**
     * 获取安装锁文件路径。
     *
     * @return string 锁文件路径
     */
    public function lockPath(): string
    {
        return base_path(false) . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'install.lock';
    }

    /**
     * 判断安装锁是否存在。
     *
     * @return bool 是否已安装
     */
    public function exists(): bool
    {
        return is_file($this->lockPath());
    }

    /**
     * 写入安装锁。
     *
     * @param array<string, mixed> $payload 安装锁内容
     * @return void
     */
    public function write(array $payload): void
    {
        $directory = dirname($this->lockPath());
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new \RuntimeException('创建安装锁目录失败');
        }

        $content = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($content === false || file_put_contents($this->lockPath(), $content . PHP_EOL, LOCK_EX) === false) {
            throw new \RuntimeException('写入安装锁失败');
        }
    }
}
