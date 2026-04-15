<?php

namespace app\service\file\storage;

use app\common\constant\FileConstant;
use app\exception\BusinessStateException;
use support\Response;

/**
 * 本地文件存储驱动。
 */
class LocalStorageDriver extends AbstractStorageDriver
{
    public function engine(): int
    {
        return FileConstant::STORAGE_LOCAL;
    }

    public function storeFromPath(string $sourcePath, array $context): array
    {
        if (!is_file($sourcePath)) {
            throw new BusinessStateException('待上传文件不存在');
        }

        $visibility = (int) ($context['visibility'] ?? FileConstant::VISIBILITY_PRIVATE);
        $objectKey = (string) ($context['object_key'] ?? '');
        $absolutePath = $this->storageConfigService->buildLocalAbsolutePath($visibility, $objectKey);
        $publicUrl = (string) ($context['public_url'] ?? '');

        if ($objectKey === '' || $absolutePath === '') {
            throw new BusinessStateException('文件存储路径无效');
        }

        $this->ensureDirectory(dirname($absolutePath));

        if (@rename($sourcePath, $absolutePath) === false) {
            if (!@copy($sourcePath, $absolutePath)) {
                throw new BusinessStateException('本地文件保存失败');
            }

            @unlink($sourcePath);
        }

        @chmod($absolutePath, 0666 & ~umask());

        return [
            'storage_engine' => $this->engine(),
            'object_key' => $objectKey,
            'url' => $visibility === FileConstant::VISIBILITY_PUBLIC ? $publicUrl : '',
            'public_url' => $publicUrl,
        ];
    }

    public function delete(array $asset): bool
    {
        $path = $this->resolveLocalAbsolutePath($asset);
        if ($path === '' || !is_file($path)) {
            return true;
        }

        return @unlink($path);
    }

    public function previewResponse(array $asset): Response
    {
        return $this->localPreviewResponse($asset);
    }

    public function downloadResponse(array $asset): Response
    {
        return $this->localDownloadResponse($asset);
    }

    public function publicUrl(array $asset): string
    {
        $url = trim((string) ($asset['url'] ?? $asset['public_url'] ?? ''));
        if ($url !== '') {
            return $url;
        }

        $visibility = (int) ($asset['visibility'] ?? FileConstant::VISIBILITY_PRIVATE);
        if ($visibility !== FileConstant::VISIBILITY_PUBLIC) {
            return '';
        }

        $objectKey = trim((string) ($asset['object_key'] ?? ''));
        if ($objectKey === '') {
            return '';
        }

        return $this->storageConfigService->buildLocalPublicUrl($objectKey);
    }

    public function temporaryUrl(array $asset): string
    {
        $url = trim((string) ($asset['url'] ?? $asset['public_url'] ?? ''));
        if ($url !== '') {
            return $url;
        }

        $visibility = (int) ($asset['visibility'] ?? FileConstant::VISIBILITY_PRIVATE);
        if ($visibility === FileConstant::VISIBILITY_PUBLIC) {
            return $this->publicUrl($asset);
        }

        $id = (int) ($asset['id'] ?? 0);

        return $id > 0 ? '/adminapi/file-asset/' . $id . '/preview' : '';
    }

    private function ensureDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        if (!@mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new BusinessStateException('文件目录创建失败');
        }
    }
}
