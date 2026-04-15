<?php

namespace app\service\file\storage;

use support\Response;

/**
 * 文件存储驱动接口。
 */
interface StorageDriverInterface
{
    public function engine(): int;

    public function storeFromPath(string $sourcePath, array $context): array;

    public function delete(array $asset): bool;

    public function previewResponse(array $asset): Response;

    public function downloadResponse(array $asset): Response;

    public function publicUrl(array $asset): string;

    public function temporaryUrl(array $asset): string;
}
