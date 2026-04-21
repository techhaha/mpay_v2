<?php

namespace app\service\file\storage;

use app\common\constant\FileConstant;
use app\exception\BusinessStateException;
use support\Response;

/**
 * 本地文件存储驱动。
 *
 * 负责本地文件存储和响应构造。
 */
class LocalStorageDriver extends AbstractStorageDriver
{
    /**
     * 获取本地存储引擎标识。
     *
     * @return int 存储引擎常量
     */
    public function engine(): int
    {
        return FileConstant::STORAGE_LOCAL;
    }

    /**
     * 将临时文件写入本地存储目录。
     *
     * @param string $sourcePath 待上传文件路径
     * @param array $context 上传上下文，包含 object_key、visibility、public_url 等信息
     * @return array 上传后的资产数据
     * @throws BusinessStateException
     */
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

    /**
     * 删除本地文件。
     *
     * @param array $asset 文件资产数据
     * @return bool 是否删除成功
     * @throws BusinessStateException
     */
    public function delete(array $asset): bool
    {
        $path = $this->resolveLocalAbsolutePath($asset);
        if ($path === '' || !is_file($path)) {
            return true;
        }

        if (@unlink($path)) {
            return true;
        }

        throw new BusinessStateException('本地文件删除失败');
    }

    /**
     * 构造本地文件预览响应。
     *
     * @param array $asset 文件资产数据
     * @return Response 响应对象
     */
    public function previewResponse(array $asset): Response
    {
        return $this->localPreviewResponse($asset);
    }

    /**
     * 构造本地文件下载响应。
     *
     * @param array $asset 文件资产数据
     * @return Response 响应对象
     */
    public function downloadResponse(array $asset): Response
    {
        return $this->localDownloadResponse($asset);
    }

    /**
     * 获取本地公开访问地址。
     *
     * @param array $asset 文件资产数据
     * @return string 公共 URL
     */
    public function publicUrl(array $asset): string
    {
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

    /**
     * 获取本地临时访问地址。
     *
     * @param array $asset 文件资产数据
     * @return string 临时 URL
     */
    public function temporaryUrl(array $asset): string
    {
        $visibility = (int) ($asset['visibility'] ?? FileConstant::VISIBILITY_PRIVATE);
        if ($visibility === FileConstant::VISIBILITY_PUBLIC) {
            return $this->publicUrl($asset);
        }

        $id = (int) ($asset['id'] ?? 0);

        return $id > 0 ? '/adminapi/file-asset/' . $id . '/preview' : '';
    }

    /**
     * 确保目标目录存在。
     *
     * @param string $directory 目录路径
     * @throws BusinessStateException
     */
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
