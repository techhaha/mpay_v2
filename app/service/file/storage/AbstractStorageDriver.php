<?php

namespace app\service\file\storage;

use app\common\constant\FileConstant;
use app\exception\ResourceNotFoundException;
use app\service\file\StorageConfigService;
use support\Response;

/**
 * 文件存储驱动抽象基类。
 *
 * 提供文件存储驱动公共能力。
 *
 * @property-read StorageConfigService $storageConfigService 存储配置服务
 */
abstract class AbstractStorageDriver implements StorageDriverInterface
{
    /**
     * 注入存储配置服务。
     *
     * @param StorageConfigService $storageConfigService 存储配置服务
     */
    public function __construct(
        protected StorageConfigService $storageConfigService
    ) {
    }

    /**
     * 从资产数组中读取指定字段。
     *
     * @param array<string, mixed> $asset 文件资产数据
     * @param string $key 字段名
     * @param mixed $default 默认值
     * @return mixed 资产字段值
     */
    protected function assetValue(array $asset, string $key, mixed $default = null): mixed
    {
        return $asset[$key] ?? $default;
    }

    /**
     * 解析本地存储文件的绝对路径。
     *
     * @param array $asset 文件资产数据
     * @return string 绝对路径
     */
    protected function resolveLocalAbsolutePath(array $asset): string
    {
        $objectKey = trim((string) $this->assetValue($asset, 'object_key', ''));
        if ($objectKey === '') {
            return '';
        }

        $visibility = (int) $this->assetValue($asset, 'visibility', FileConstant::VISIBILITY_PRIVATE);

        return $this->storageConfigService->buildLocalAbsolutePath($visibility, $objectKey);
    }

    /**
     * 构造字符串响应。
     *
     * @param string $body 响应内容
     * @param string $mimeType MIME 类型
     * @param int $status HTTP 状态码
     * @param array $headers 额外响应头
     * @return Response 响应对象
     */
    protected function bodyResponse(string $body, string $mimeType = 'application/octet-stream', int $status = 200, array $headers = []): Response
    {
        $responseHeaders = array_merge([
            'Content-Type' => $mimeType !== '' ? $mimeType : 'application/octet-stream',
        ], $headers);

        return response($body, $status, $responseHeaders);
    }

    /**
     * 构造文件下载响应。
     *
     * @param string $body 响应内容
     * @param string $downloadName 下载文件名
     * @param string $mimeType MIME 类型
     * @return Response 响应对象
     */
    protected function downloadBodyResponse(string $body, string $downloadName, string $mimeType = 'application/octet-stream'): Response
    {
        $response = $this->bodyResponse($body, $mimeType, 200, [
            'Content-Disposition' => 'attachment; filename="' . str_replace(['"', "\r", "\n", "\0"], '', $downloadName) . '"',
        ]);

        return $response;
    }

    /**
     * 根据本地路径构造预览或下载响应。
     *
     * @param string $path 本地文件路径
     * @param string $downloadName 下载文件名
     * @param bool $attachment 是否下载附件
     * @return Response 响应对象
     */
    protected function responseFromPath(string $path, string $downloadName = '', bool $attachment = false): Response
    {
        if ($attachment) {
            return response()->download($path, $downloadName);
        }

        return response()->file($path);
    }

    /**
     * 构造本地文件预览响应。
     *
     * @param array $asset 文件资产数据
     * @return Response 响应对象
     * @throws ResourceNotFoundException
     */
    protected function localPreviewResponse(array $asset): Response
    {
        $path = $this->resolveLocalAbsolutePath($asset);
        if ($path === '' || !is_file($path)) {
            throw new ResourceNotFoundException('文件不存在');
        }

        return $this->responseFromPath($path);
    }

    /**
     * 构造本地文件下载响应。
     *
     * @param array $asset 文件资产数据
     * @return Response 响应对象
     * @throws ResourceNotFoundException
     */
    protected function localDownloadResponse(array $asset): Response
    {
        $path = $this->resolveLocalAbsolutePath($asset);
        if ($path === '' || !is_file($path)) {
            throw new ResourceNotFoundException('文件不存在');
        }

        return $this->responseFromPath($path, (string) $this->assetValue($asset, 'original_name', basename($path)), true);
    }

    /**
     * 根据文件场景返回目录前缀。
     *
     * @param int $scene 文件场景
     * @return string 目录前缀
     */
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
