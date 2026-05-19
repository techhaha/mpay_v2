<?php

namespace app\service\install;

use app\common\base\BaseService;

/**
 * 安装环境检测服务。
 */
class EnvironmentCheckService extends BaseService
{
    /**
     * 检测安装所需基础环境。
     *
     * @return array{items: array<int, array<string, mixed>>, passed: bool}
     */
    public function check(): array
    {
        $items = [
            $this->item('PHP 版本 >= 8.1', version_compare(PHP_VERSION, '8.1.0', '>='), PHP_VERSION),
            $this->item('PDO MySQL 扩展', extension_loaded('pdo_mysql'), extension_loaded('pdo_mysql') ? '已启用' : '未启用'),
            $this->item('OpenSSL 扩展', extension_loaded('openssl'), extension_loaded('openssl') ? '已启用' : '未启用'),
            $this->item('JSON 扩展', extension_loaded('json'), extension_loaded('json') ? '已启用' : '未启用'),
            $this->item('mbstring 扩展', extension_loaded('mbstring'), extension_loaded('mbstring') ? '已启用' : '未启用'),
            $this->item('curl 扩展', extension_loaded('curl'), extension_loaded('curl') ? '已启用' : '未启用', 'warning'),
            $this->item('fileinfo 扩展', extension_loaded('fileinfo'), extension_loaded('fileinfo') ? '已启用' : '未启用', 'warning'),
            $this->item('Redis 扩展', extension_loaded('redis') || class_exists('Redis'), extension_loaded('redis') || class_exists('Redis') ? '已启用' : '未启用'),
            $this->item('Composer vendor', is_file(base_path(false) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php'), 'vendor/autoload.php'),
            $this->writableItem('runtime 可写', base_path(false) . DIRECTORY_SEPARATOR . 'runtime'),
            $this->writableItem('public/storage 可写', public_path('storage')),
            $this->writableItem('.env 可写或可创建', base_path(false)),
        ];

        $passed = true;
        foreach ($items as $item) {
            if (($item['status'] ?? '') === 'error') {
                $passed = false;
                break;
            }
        }

        return ['items' => $items, 'passed' => $passed];
    }

    /**
     * 构建单个检测项。
     *
     * @param string $name 检测名称
     * @param bool $ok 是否通过
     * @param string $value 展示值
     * @param string $failStatus 失败状态
     * @return array<string, mixed> 检测项
     */
    private function item(string $name, bool $ok, string $value = '', string $failStatus = 'error'): array
    {
        return [
            'name' => $name,
            'value' => $value,
            'status' => $ok ? 'success' : $failStatus,
            'message' => $ok ? '通过' : ($failStatus === 'warning' ? '建议处理' : '必须处理'),
        ];
    }

    /**
     * 构建目录可写检测项。
     *
     * @param string $name 检测名称
     * @param string $path 目录路径
     * @return array<string, mixed> 检测项
     */
    private function writableItem(string $name, string $path): array
    {
        if (!is_dir($path)) {
            $parent = dirname($path);
            $ok = is_dir($parent) && is_writable($parent);
        } else {
            $ok = is_writable($path);
        }

        return $this->item($name, $ok, $this->displayPath($path));
    }

    /**
     * 转换为适合安装页展示的相对路径。
     *
     * @param string $path 原始路径
     * @return string 展示路径
     */
    private function displayPath(string $path): string
    {
        $basePath = rtrim(str_replace('\\', '/', base_path(false)), '/');
        $normalized = str_replace('\\', '/', $path);

        if ($normalized === $basePath) {
            return '.';
        }

        if (str_starts_with($normalized, $basePath . '/')) {
            return ltrim(substr($normalized, strlen($basePath)), '/');
        }

        return $path;
    }
}
