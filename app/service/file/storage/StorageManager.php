<?php

namespace app\service\file\storage;

use app\common\constant\FileConstant;
use app\service\file\StorageConfigService;
use support\Response;

/**
 * 文件存储驱动管理器。
 *
 * 负责根据存储引擎分发文件操作。
 *
 * @property StorageConfigService $storageConfigService 存储配置服务
 * @property LocalStorageDriver $localStorageDriver 本地存储驱动
 * @property OssStorageDriver $ossStorageDriver oss存储驱动
 * @property CosStorageDriver $cosStorageDriver cos存储驱动
 * @property RemoteUrlStorageDriver $remoteUrlStorageDriver remoteUrl存储驱动
 */
class StorageManager
{
    /**
     * 构造方法。
     *
     * @param StorageConfigService $storageConfigService 存储配置服务
     * @param LocalStorageDriver $localStorageDriver 本地存储驱动
     * @param OssStorageDriver $ossStorageDriver oss存储驱动
     * @param CosStorageDriver $cosStorageDriver cos存储驱动
     * @param RemoteUrlStorageDriver $remoteUrlStorageDriver remoteUrl存储驱动
     * @return void
     */
    public function __construct(
        protected StorageConfigService $storageConfigService,
        protected LocalStorageDriver $localStorageDriver,
        protected OssStorageDriver $ossStorageDriver,
        protected CosStorageDriver $cosStorageDriver,
        protected RemoteUrlStorageDriver $remoteUrlStorageDriver
    ) {
    }

    /**
     * 构建存储上下文。
     *
     * @param string $sourcePath 源文件路径
     * @param string $originalName 原始文件名
     * @param int|null $scene 场景
     * @param int|null $visibility 可见性
     * @param int|null $engine 存储引擎
     * @param string|null $sourceUrl 源地址
     * @param string $sourceType 来源类型
     * @return array 上下文数据
     */
    public function buildContext(
        string $sourcePath,
        string $originalName,
        ?int $scene = null,
        ?int $visibility = null,
        ?int $engine = null,
        ?string $sourceUrl = null,
        string $sourceType = 'upload'
    ): array {
        $mimeType = $this->guessMimeType($sourcePath, $originalName);
        $scene = $this->storageConfigService->normalizeScene($scene, $originalName, $mimeType);
        $visibility = $this->storageConfigService->normalizeVisibility($visibility, $scene);
        $engine = $this->storageConfigService->normalizeEngine($engine ?? $this->storageConfigService->defaultEngine());
        $ext = strtolower(trim(pathinfo($originalName, PATHINFO_EXTENSION)));
        if ($ext === '') {
            $ext = strtolower((string) pathinfo($sourcePath, PATHINFO_EXTENSION));
        }

        $objectKey = $this->storageConfigService->buildObjectKey($scene, $visibility, $ext);
        $publicUrl = $this->buildPublicUrlByEngine($engine, $visibility, $objectKey);

        return [
            'scene' => $scene,
            'visibility' => $visibility,
            'storage_engine' => $engine,
            'source_type' => $sourceType === 'remote_url' ? FileConstant::SOURCE_REMOTE_URL : FileConstant::SOURCE_UPLOAD,
            'source_url' => (string) ($sourceUrl ?? ''),
            'original_name' => $originalName,
            'file_name' => basename($objectKey),
            'file_ext' => $ext,
            'mime_type' => $mimeType,
            'size' => is_file($sourcePath) ? (int) filesize($sourcePath) : 0,
            'md5' => is_file($sourcePath) ? (string) md5_file($sourcePath) : '',
            'object_key' => $objectKey,
            'public_url' => $publicUrl,
        ];
    }

    /**
     * 从文件路径保存文件。
     *
     * @param string $sourcePath 源文件路径
     * @param string $originalName 原始文件名
     * @param int|null $scene 场景
     * @param int|null $visibility 可见性
     * @param int|null $engine 存储引擎
     * @param string|null $sourceUrl 源地址
     * @param string $sourceType 来源类型
     * @return array 保存结果
     */
    public function storeFromPath(
        string $sourcePath,
        string $originalName,
        ?int $scene = null,
        ?int $visibility = null,
        ?int $engine = null,
        ?string $sourceUrl = null,
        string $sourceType = 'upload'
    ): array {
        $context = $this->buildContext($sourcePath, $originalName, $scene, $visibility, $engine, $sourceUrl, $sourceType);
        $driver = $this->resolveDriver((int) $context['storage_engine']);

        return array_merge($context, $driver->storeFromPath($sourcePath, $context));
    }

    /**
     * 删除存储对象。
     *
     * @param array $asset 文件记录
     * @return bool 是否删除成功
     */
    public function delete(array $asset): bool
    {
        return $this->resolveDriver((int) ($asset['storage_engine'] ?? FileConstant::STORAGE_LOCAL))
            ->delete($asset);
    }

    /**
     * 获取预览响应。
     *
     * @param array $asset 文件记录
     * @return Response 响应对象
     */
    public function previewResponse(array $asset): Response
    {
        return $this->resolveDriver((int) ($asset['storage_engine'] ?? FileConstant::STORAGE_LOCAL))
            ->previewResponse($asset);
    }

    /**
     * 获取下载响应。
     *
     * @param array $asset 文件记录
     * @return Response 响应对象
     */
    public function downloadResponse(array $asset): Response
    {
        return $this->resolveDriver((int) ($asset['storage_engine'] ?? FileConstant::STORAGE_LOCAL))
            ->downloadResponse($asset);
    }

    /**
     * 获取公开访问 URL。
     *
     * @param array $asset 文件记录
     * @return string 访问 URL
     */
    public function publicUrl(array $asset): string
    {
        return $this->resolveDriver((int) ($asset['storage_engine'] ?? FileConstant::STORAGE_LOCAL))
            ->publicUrl($asset);
    }

    /**
     * 获取临时访问 URL。
     *
     * @param array $asset 文件记录
     * @return string 访问 URL
     */
    public function temporaryUrl(array $asset): string
    {
        return $this->resolveDriver((int) ($asset['storage_engine'] ?? FileConstant::STORAGE_LOCAL))
            ->temporaryUrl($asset);
    }

    /**
     * 解析对应的存储驱动。
     *
     * @param int $engine 存储引擎
     * @return StorageDriverInterface 存储驱动
     */
    public function resolveDriver(int $engine): StorageDriverInterface
    {
        return match ($engine) {
            FileConstant::STORAGE_LOCAL => $this->localStorageDriver,
            FileConstant::STORAGE_ALIYUN_OSS => $this->ossStorageDriver,
            FileConstant::STORAGE_TENCENT_COS => $this->cosStorageDriver,
            FileConstant::STORAGE_REMOTE_URL => $this->remoteUrlStorageDriver,
            default => $this->localStorageDriver,
        };
    }

    /**
     * 按存储引擎构建公开访问 URL。
     *
     * @param int $engine 存储引擎
     * @param int $visibility 可见性
     * @param string $objectKey 对象键
     * @return string 访问 URL
     */
    private function buildPublicUrlByEngine(int $engine, int $visibility, string $objectKey): string
    {
        if ($engine === FileConstant::STORAGE_LOCAL && $visibility === FileConstant::VISIBILITY_PUBLIC) {
            return $this->storageConfigService->buildLocalPublicUrl($objectKey);
        }

        return '';
    }

    /**
     * 估算 MIME 类型。
     *
     * @param string $sourcePath 源文件路径
     * @param string $originalName 原始文件名
     * @return string MIME 类型
     */
    private function guessMimeType(string $sourcePath, string $originalName): string
    {
        $mimeType = '';
        if (is_file($sourcePath) && function_exists('mime_content_type')) {
            $detected = @mime_content_type($sourcePath);
            if (is_string($detected)) {
                $mimeType = trim($detected);
            }
        }

        if ($mimeType !== '') {
            return $mimeType;
        }

        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        return match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            'bmp' => 'image/bmp',
            'txt', 'log', 'md', 'ini', 'conf', 'yml', 'yaml' => 'text/plain',
            'json' => 'application/json',
            'xml' => 'application/xml',
            'csv' => 'text/csv',
            'pem' => 'application/x-pem-file',
            'crt', 'cer' => 'application/x-x509-ca-cert',
            'key' => 'application/octet-stream',
            default => 'application/octet-stream',
        };
    }
}


