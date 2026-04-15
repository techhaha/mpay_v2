<?php

namespace app\service\file\storage;

use app\common\constant\FileConstant;
use app\exception\BusinessStateException;
use Qcloud\Cos\Client as CosClient;
use support\Response;
use Throwable;

/**
 * 腾讯云 COS 文件存储驱动。
 */
class CosStorageDriver extends AbstractStorageDriver
{
    public function engine(): int
    {
        return FileConstant::STORAGE_TENCENT_COS;
    }

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
        $client->putObject([
            'Bucket' => (string) $config['bucket'],
            'Key' => $objectKey,
            'Body' => fopen($sourcePath, 'rb'),
        ]);

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
        $config = $this->storageConfigService->cosConfig();
        if (trim((string) ($config['bucket'] ?? '')) === '') {
            return false;
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

    public function temporaryUrl(array $asset): string
    {
        $config = $this->storageConfigService->cosConfig();
        if (trim((string) ($config['bucket'] ?? '')) === '' || trim((string) ($config['region'] ?? '')) === '') {
            return $this->publicUrl($asset);
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
            return $this->publicUrl($asset);
        }
    }

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

    private function responseFromObject(array $asset, bool $attachment): Response
    {
        $config = $this->storageConfigService->cosConfig();
        $bucket = trim((string) ($config['bucket'] ?? ''));
        $objectKey = (string) ($asset['object_key'] ?? '');
        if ($bucket === '' || $objectKey === '') {
            return response('文件不存在', 404);
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
            return response('文件不存在', 404);
        }
    }
}
