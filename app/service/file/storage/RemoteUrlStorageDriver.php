<?php

namespace app\service\file\storage;

use app\common\constant\FileConstant;
use app\exception\BusinessStateException;
use support\Response;

/**
 * 远程引用驱动。
 */
class RemoteUrlStorageDriver extends AbstractStorageDriver
{
    public function engine(): int
    {
        return FileConstant::STORAGE_REMOTE_URL;
    }

    public function storeFromPath(string $sourcePath, array $context): array
    {
        throw new BusinessStateException('远程引用模式不支持直接上传，请先下载后再入库');
    }

    public function delete(array $asset): bool
    {
        return true;
    }

    public function previewResponse(array $asset): Response
    {
        $url = (string) ($asset['source_url'] ?? $asset['url'] ?? '');
        if ($url === '') {
            return response('文件不存在', 404);
        }

        return redirect($url);
    }

    public function downloadResponse(array $asset): Response
    {
        return $this->previewResponse($asset);
    }

    public function publicUrl(array $asset): string
    {
        return (string) ($asset['source_url'] ?? $asset['url'] ?? '');
    }

    public function temporaryUrl(array $asset): string
    {
        return (string) ($asset['source_url'] ?? $asset['url'] ?? '');
    }
}
