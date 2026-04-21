<?php

namespace app\service\file\storage;

use app\common\constant\FileConstant;
use app\exception\BusinessStateException;
use app\exception\ResourceNotFoundException;
use AlibabaCloud\Oss\V2 as Oss;
use support\Response;
use Throwable;

/**
 * 阿里云 OSS 文件存储驱动。
 *
 * 负责对象上传、删除、公开地址生成和预签名访问。
 */
class OssStorageDriver extends AbstractStorageDriver
{
    /**
     * 获取 OSS 存储引擎标识。
     *
     * @return int 存储引擎常量
     */
    public function engine(): int
    {
        return FileConstant::STORAGE_ALIYUN_OSS;
    }

    /**
     * 将本地临时文件上传到 OSS。
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

        $config = $this->storageConfigService->ossConfig();
        foreach (['region', 'bucket', 'access_key_id', 'access_key_secret'] as $key) {
            if (trim((string) ($config[$key] ?? '')) === '') {
                throw new BusinessStateException('阿里云 OSS 存储配置未完整');
            }
        }

        $client = $this->client($config);
        $objectKey = (string) ($context['object_key'] ?? '');
        $visibility = (int) ($context['visibility'] ?? FileConstant::VISIBILITY_PRIVATE);

        /** @var Oss\Models\PutObjectRequest $request */
        $request = new Oss\Models\PutObjectRequest(
            bucket: (string) $config['bucket'],
            key: $objectKey
        );
        $request->body = Oss\Utils::streamFor(fopen($sourcePath, 'rb'));

        $client->putObject($request);

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
     * 删除 OSS 对象。
     *
     * @param array $asset 文件资产数据
     * @return bool 是否删除成功
     * @throws BusinessStateException
     */
    public function delete(array $asset): bool
    {
        $config = $this->storageConfigService->ossConfig();
        if (trim((string) ($config['bucket'] ?? '')) === '') {
            throw new BusinessStateException('阿里云 OSS 存储配置未完整');
        }

        $objectKey = (string) ($asset['object_key'] ?? '');
        if ($objectKey === '') {
            return true;
        }

        $client = $this->client($config);

        /** @var Oss\Models\DeleteObjectRequest $request */
        $request = new Oss\Models\DeleteObjectRequest(
            bucket: (string) $config['bucket'],
            key: $objectKey
        );
        $client->deleteObject($request);

        return true;
    }

    /**
     * 构造 OSS 文件预览响应。
     *
     * @param array $asset 文件资产数据
     * @return Response 响应对象
     */
    public function previewResponse(array $asset): Response
    {
        return $this->responseFromObject($asset, false);
    }

    /**
     * 构造 OSS 文件下载响应。
     *
     * @param array $asset 文件资产数据
     * @return Response 响应对象
     */
    public function downloadResponse(array $asset): Response
    {
        return $this->responseFromObject($asset, true);
    }

    /**
     * 获取 OSS 公开访问地址。
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

        $config = $this->storageConfigService->ossConfig();
        $objectKey = (string) ($asset['object_key'] ?? '');
        if ($objectKey === '') {
            return '';
        }

        $customDomain = trim((string) ($config['public_domain'] ?? ''));
        if ($customDomain !== '') {
            return rtrim($customDomain, '/') . '/' . ltrim($objectKey, '/');
        }

        $endpoint = trim((string) ($config['endpoint'] ?? ''));
        $bucket = trim((string) ($config['bucket'] ?? ''));
        if ($endpoint === '' || $bucket === '') {
            return '';
        }

        $endpoint = preg_replace('#^https?://#i', '', $endpoint) ?: $endpoint;

        return 'https://' . $bucket . '.' . ltrim($endpoint, '/') . '/' . ltrim($objectKey, '/');
    }

    /**
     * 获取 OSS 预签名访问地址。
     *
     * @param array $asset 文件资产数据
     * @return string 临时 URL
     */
    public function temporaryUrl(array $asset): string
    {
        $config = $this->storageConfigService->ossConfig();
        if (trim((string) ($config['bucket'] ?? '')) === '' || trim((string) ($config['region'] ?? '')) === '') {
            return '';
        }

        try {
            $client = $this->client($config);
            $objectKey = (string) ($asset['object_key'] ?? '');
            if ($objectKey === '') {
                return '';
            }

            /** @var Oss\Models\GetObjectRequest $request */
            $request = new Oss\Models\GetObjectRequest(
                bucket: (string) $config['bucket'],
                key: $objectKey
            );
            $result = $client->presign($request);

            return (string) ($result->url ?? '');
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * 创建 OSS 客户端。
     *
     * @param array $config 存储配置
     * @return Oss\Client OSS 客户端
     */
    private function client(array $config): Oss\Client
    {
        /** @var Oss\Credentials\StaticCredentialsProvider $provider */
        $provider = new Oss\Credentials\StaticCredentialsProvider(
            accessKeyId: (string) $config['access_key_id'],
            accessKeySecret: (string) $config['access_key_secret']
        );

        $cfg = Oss\Config::loadDefault();
        $cfg->setCredentialsProvider(credentialsProvider: $provider);
        $cfg->setRegion(region: (string) $config['region']);

        $endpoint = trim((string) ($config['endpoint'] ?? ''));
        if ($endpoint !== '') {
            $cfg->setEndpoint(endpoint: $endpoint);
        }

        return new Oss\Client($cfg);
    }

    /**
     * 根据 OSS 对象内容构造预览或下载响应。
     *
     * @param array $asset 文件资产数据
     * @param bool $attachment 是否下载附件
     * @return Response 响应对象
     * @throws ResourceNotFoundException
     */
    private function responseFromObject(array $asset, bool $attachment): Response
    {
        $config = $this->storageConfigService->ossConfig();
        $bucket = trim((string) ($config['bucket'] ?? ''));
        $objectKey = (string) ($asset['object_key'] ?? '');
        if ($bucket === '' || $objectKey === '') {
            throw new ResourceNotFoundException('文件不存在');
        }

        try {
            $client = $this->client($config);
            /** @var Oss\Models\GetObjectRequest $request */
            $request = new Oss\Models\GetObjectRequest(
                bucket: $bucket,
                key: $objectKey
            );
            $result = $client->getObject($request);
            $body = (string) $result->body->getContents();
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
