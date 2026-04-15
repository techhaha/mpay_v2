<?php

namespace app\service\file\storage;

use app\common\constant\FileConstant;
use app\service\file\StorageConfigService;
use support\Response;

/**
 * 文件存储驱动抽象基类。
 */
abstract class AbstractStorageDriver implements StorageDriverInterface
{
    public function __construct(
        protected StorageConfigService $storageConfigService
    ) {
    }

    protected function assetValue(array $asset, string $key, mixed $default = null): mixed
    {
        return $asset[$key] ?? $default;
    }

    protected function resolveLocalAbsolutePath(array $asset): string
    {
        $objectKey = trim((string) $this->assetValue($asset, 'object_key', ''));
        $visibility = (int) $this->assetValue($asset, 'visibility', FileConstant::VISIBILITY_PRIVATE);
        $candidate = '';

        if ($objectKey !== '') {
            $candidate = $this->storageConfigService->buildLocalAbsolutePath($visibility, $objectKey);
            if ($candidate !== '' && is_file($candidate)) {
                return $candidate;
            }
        }

        foreach (['url', 'public_url'] as $field) {
            $url = trim((string) $this->assetValue($asset, $field, ''));
            if ($url === '') {
                continue;
            }

            $parsedPath = (string) parse_url($url, PHP_URL_PATH);
            if ($parsedPath === '') {
                continue;
            }

            $candidate = public_path() . DIRECTORY_SEPARATOR . ltrim($parsedPath, '/');
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return $candidate;
    }

    protected function bodyResponse(string $body, string $mimeType = 'application/octet-stream', int $status = 200, array $headers = []): Response
    {
        $responseHeaders = array_merge([
            'Content-Type' => $mimeType !== '' ? $mimeType : 'application/octet-stream',
        ], $headers);

        return response($body, $status, $responseHeaders);
    }

    protected function downloadBodyResponse(string $body, string $downloadName, string $mimeType = 'application/octet-stream'): Response
    {
        $response = $this->bodyResponse($body, $mimeType, 200, [
            'Content-Disposition' => 'attachment; filename="' . str_replace(['"', "\r", "\n", "\0"], '', $downloadName) . '"',
        ]);

        return $response;
    }

    protected function responseFromPath(string $path, string $downloadName = '', bool $attachment = false): Response
    {
        if ($attachment) {
            return response()->download($path, $downloadName);
        }

        return response()->file($path);
    }

    protected function localPreviewResponse(array $asset): Response
    {
        $path = $this->resolveLocalAbsolutePath($asset);
        if ($path === '' || !is_file($path)) {
            return response('文件不存在', 404);
        }

        return $this->responseFromPath($path);
    }

    protected function localDownloadResponse(array $asset): Response
    {
        $path = $this->resolveLocalAbsolutePath($asset);
        if ($path === '' || !is_file($path)) {
            return response('文件不存在', 404);
        }

        return $this->responseFromPath($path, (string) $this->assetValue($asset, 'original_name', basename($path)), true);
    }

    protected function scenePrefix(int $scene): string
    {
        return match ($scene) {
            FileConstant::SCENE_IMAGE => 'image',
            FileConstant::SCENE_CERTIFICATE => 'certificate',
            FileConstant::SCENE_TEXT => 'text',
            default => 'other',
        };
    }
}
