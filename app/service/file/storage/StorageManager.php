<?php

namespace app\service\file\storage;

use app\common\constant\FileConstant;
use app\service\file\StorageConfigService;
use support\Response;

/**
 * 文件存储驱动管理器。
 */
class StorageManager
{
    public function __construct(
        protected StorageConfigService $storageConfigService,
        protected LocalStorageDriver $localStorageDriver,
        protected OssStorageDriver $ossStorageDriver,
        protected CosStorageDriver $cosStorageDriver,
        protected RemoteUrlStorageDriver $remoteUrlStorageDriver
    ) {
    }

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

    public function delete(array $asset): bool
    {
        return $this->resolveDriver((int) ($asset['storage_engine'] ?? FileConstant::STORAGE_LOCAL))
            ->delete($asset);
    }

    public function previewResponse(array $asset): Response
    {
        return $this->resolveDriver((int) ($asset['storage_engine'] ?? FileConstant::STORAGE_LOCAL))
            ->previewResponse($asset);
    }

    public function downloadResponse(array $asset): Response
    {
        return $this->resolveDriver((int) ($asset['storage_engine'] ?? FileConstant::STORAGE_LOCAL))
            ->downloadResponse($asset);
    }

    public function publicUrl(array $asset): string
    {
        return $this->resolveDriver((int) ($asset['storage_engine'] ?? FileConstant::STORAGE_LOCAL))
            ->publicUrl($asset);
    }

    public function temporaryUrl(array $asset): string
    {
        return $this->resolveDriver((int) ($asset['storage_engine'] ?? FileConstant::STORAGE_LOCAL))
            ->temporaryUrl($asset);
    }

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

    private function buildPublicUrlByEngine(int $engine, int $visibility, string $objectKey): string
    {
        if ($engine === FileConstant::STORAGE_LOCAL && $visibility === FileConstant::VISIBILITY_PUBLIC) {
            return $this->storageConfigService->buildLocalPublicUrl($objectKey);
        }

        return '';
    }

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
