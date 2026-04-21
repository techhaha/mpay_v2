<?php

namespace app\service\file\storage;

use app\common\constant\FileConstant;
use app\exception\BusinessStateException;
use app\exception\ResourceNotFoundException;
use Qcloud\Cos\Client as CosClient;
use support\Response;
use Throwable;

/**
 * 腾讯云 COS 文件存储驱动。
 *
 * 负责对象上传、删除、公开地址生成和对象内容响应。
 */
class CosStorageDriver extends AbstractStorageDriver
{
    /**
     * 获取 COS 存储引擎标识。
     *
     * @return int 存储引擎常量
     */
    public function engine(): int
    {
        return FileConstant::STORAGE_TENCENT_COS;
    }

    /**
     * 将本地临时文件上传到 COS。
     *
     * @param string $sourcePath 待上传文件路径
     * @param array $context 上传上下文，包含 object_key、visibility 等信息
     * @return array 上传后的资产数据
     * @throws BusinessStateException
     */
    public function storeFromPath(string $sourcePath, array $context): array
    {
        if (!is_file($sourcePath)) {
            throw new BusinessStateException('待上传文件不存在');
        }

        $config = $this->storageConfigService->cosConfig();
        foreach (['region', 'bucket', 'secret_id', 'secret_key'] as $key) {
            if (trim((string) ($config[$key] ?? '')) === '') {
                throw new BusinessStateException('腾讯云 COS 存储配置未完整');
            }
        }

        $client = $this->client($config);
        $objectKey = (string) ($context['object_key'] ?? '');
        $visibility = (int) ($context['visibility'] ?? FileConstant::VISIBILITY_PRIVATE);
        $client->putObject([
            'Bucket' => (string) $config['bucket'],
            'Key' => $objectKey,
            'Body' => fopen($sourcePath, 'rb'),
        ]);

        $publicUrl = $this->publicUrl([
            'visibility' => $visibility,
            'object_key' => $objectKey,
        ]);

        return [
            'storage_engine' => $this->engine(),
            'object_key' => $objectKey,
            'url' => $visibility === FileConstant::VISIBILITY_PUBLIC ? $publicUrl : '',
            'public_url' => $visibility === FileConstant::VISIBILITY_PUBLIC ? $publicUrl : '',
        ];
    }

    /**
     * 删除 COS 对象。
     *
     * @param array $asset 文件资产数据
     * @return bool 是否删除成功
     * @throws BusinessStateException
     */
    public function delete(array $asset): bool
    {
        $config = $this->storageConfigService->cosConfig();
        if (trim((string) ($config['bucket'] ?? '')) === '') {
            throw new BusinessStateException('腾讯云 COS 存储配置未完整');
        }

        $objectKey = (string) ($asset['object_key'] ?? '');
        if ($objectKey === '') {
            return true;
        }

        $client = $this->client($config);
        $client->deleteObject([
            'Bucket' => (string) $config['bucket'],
            'Key' => $objectKey,
        ]);

        return true;
    }

    /**
     * 构造 COS 文件预览响应。
     *
     * @param array $asset 文件资产数据
     * @return Response 响应对象
     */
    public function previewResponse(array $asset): Response
    {
        return $this->responseFromObject($asset, false);
    }

    /**
     * 构造 COS 文件下载响应。
     *
     * @param array $asset 文件资产数据
     * @return Response 响应对象
     */
    public function downloadResponse(array $asset): Response
    {
        return $this->responseFromObject($asset, true);
    }

    /**
     * 获取 COS 公开访问地址。
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

        $publicUrl = trim((string) ($asset['url'] ?? $asset['public_url'] ?? ''));
        if ($publicUrl !== '') {
            return $publicUrl;
        }

        $config = $this->storageConfigService->cosConfig();
        $objectKey = (string) ($asset['object_key'] ?? '');
        if ($objectKey === '') {
            return '';
        }

        $customDomain = trim((string) ($config['public_domain'] ?? ''));
        if ($customDomain !== '') {
            return rtrim($customDomain, '/') . '/' . ltrim($objectKey, '/');
        }

        $region = trim((string) ($config['region'] ?? ''));
        $bucket = trim((string) ($config['bucket'] ?? ''));
        if ($region === '' || $bucket === '') {
            return '';
        }

        return 'https://' . $bucket . '.cos.' . $region . '.myqcloud.com/' . ltrim($objectKey, '/');
    }

    /**
     * 获取 COS 临时访问地址。
     *
     * @param array $asset 文件资产数据
     * @return string 临时 URL
     */
    public function temporaryUrl(array $asset): string
    {
        $config = $this->storageConfigService->cosConfig();
        if (trim((string) ($config['bucket'] ?? '')) === '' || trim((string) ($config['region'] ?? '')) === '') {
            return '';
        }

        try {
            $client = $this->client($config);
            $objectKey = (string) ($asset['object_key'] ?? '');
            if ($objectKey === '') {
                return '';
            }

            return $client->getObjectUrl(
                (string) $config['bucket'],
                $objectKey
            );
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * 创建 COS 客户端。
     *
     * @param array $config 存储配置
     * @return CosClient COS 客户端
     */
    private function client(array $config): CosClient
    {
        return new CosClient([
            'region' => (string) $config['region'],
            'credentials' => [
                'secretId' => (string) $config['secret_id'],
                'secretKey' => (string) $config['secret_key'],
            ],
        ]);
    }

    /**
     * 根据 COS 对象内容构造预览或下载响应。
     *
     * @param array $asset 文件资产数据
     * @param bool $attachment 是否下载附件
     * @return Response 响应对象
     * @throws ResourceNotFoundException
     */
    private function responseFromObject(array $asset, bool $attachment): Response
    {
        $config = $this->storageConfigService->cosConfig();
        $bucket = trim((string) ($config['bucket'] ?? ''));
        $objectKey = (string) ($asset['object_key'] ?? '');
        if ($bucket === '' || $objectKey === '') {
            throw new ResourceNotFoundException('文件不存在');
        }

        try {
            $client = $this->client($config);
            $result = $client->getObject([
                'Bucket' => $bucket,
                'Key' => $objectKey,
            ]);

            $body = '';
            if (is_string($result)) {
                $body = $result;
            } elseif (is_object($result) && method_exists($result, '__toString')) {
                $body = (string) $result;
            } elseif (is_array($result)) {
                $body = (string) ($result['Body'] ?? $result['body'] ?? ''); 
            }

            $mimeType = (string) ($asset['mime_type'] ?? 'application/octet-stream');

            if ($attachment) {
                return $this->downloadBodyResponse($body, (string) ($asset['original_name'] ?? basename($objectKey)), $mimeType);
            }

            return $this->bodyResponse($body, $mimeType);
        } catch (Throwable) {
            throw new ResourceNotFoundException('文件不存在');
        }
    }
}
