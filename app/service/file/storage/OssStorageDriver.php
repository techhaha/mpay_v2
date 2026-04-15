<?php

namespace app\service\file\storage;

use app\common\constant\FileConstant;
use app\exception\BusinessStateException;
use AlibabaCloud\Oss\V2 as Oss;
use support\Response;
use Throwable;

/**
 * 阿里云 OSS 文件存储驱动。
 */
class OssStorageDriver extends AbstractStorageDriver
{
    public function engine(): int
    {
        return FileConstant::STORAGE_ALIYUN_OSS;
    }

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
        $request = new Oss\Models\PutObjectRequest(
            bucket: (string) $config['bucket'],
            key: $objectKey
        );
        $request->body = Oss\Utils::streamFor(fopen($sourcePath, 'rb'));

        $client->putObject($request);

        $publicUrl = $this->publicUrl([
            'object_key' => $objectKey,
        ]);
        $visibility = (int) ($context['visibility'] ?? FileConstant::VISIBILITY_PRIVATE);

        return [
            'storage_engine' => $this->engine(),
            'object_key' => $objectKey,
            'url' => $visibility === FileConstant::VISIBILITY_PUBLIC ? $publicUrl : '',
            'public_url' => $publicUrl,
        ];
    }

    public function delete(array $asset): bool
    {
        $config = $this->storageConfigService->ossConfig();
        if (trim((string) ($config['bucket'] ?? '')) === '') {
            return false;
        }

        $objectKey = (string) ($asset['object_key'] ?? '');
        if ($objectKey === '') {
            return true;
        }

        $client = $this->client($config);
        $request = new Oss\Models\DeleteObjectRequest(
            bucket: (string) $config['bucket'],
            key: $objectKey
        );
        $client->deleteObject($request);

        return true;
    }

    public function previewResponse(array $asset): Response
    {
        $url = $this->publicUrl($asset);
        if ($url !== '') {
            return redirect($url);
        }

        return $this->responseFromObject($asset, false);
    }

    public function downloadResponse(array $asset): Response
    {
        return $this->responseFromObject($asset, true);
    }

    public function publicUrl(array $asset): string
    {
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

    public function temporaryUrl(array $asset): string
    {
        $config = $this->storageConfigService->ossConfig();
        if (trim((string) ($config['bucket'] ?? '')) === '' || trim((string) ($config['region'] ?? '')) === '') {
            return $this->publicUrl($asset);
        }

        try {
            $client = $this->client($config);
            $objectKey = (string) ($asset['object_key'] ?? '');
            if ($objectKey === '') {
                return '';
            }

            $request = new Oss\Models\GetObjectRequest(
                bucket: (string) $config['bucket'],
                key: $objectKey
            );
            $result = $client->presign($request);

            return (string) ($result->url ?? '');
        } catch (Throwable) {
            return $this->publicUrl($asset);
        }
    }

    private function client(array $config): Oss\Client
    {
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

    private function responseFromObject(array $asset, bool $attachment): Response
    {
        $config = $this->storageConfigService->ossConfig();
        $bucket = trim((string) ($config['bucket'] ?? ''));
        $objectKey = (string) ($asset['object_key'] ?? '');
        if ($bucket === '' || $objectKey === '') {
            return response('文件不存在', 404);
        }

        try {
            $client = $this->client($config);
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
            return response('文件不存在', 404);
        }
    }
}
