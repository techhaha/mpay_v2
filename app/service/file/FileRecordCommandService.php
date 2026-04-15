<?php

namespace app\service\file;

use app\common\base\BaseService;
use app\exception\BusinessStateException;
use app\exception\ValidationException;
use app\repository\file\FileRecordRepository;
use app\service\file\storage\StorageManager;
use support\Request;
use Webman\Http\UploadFile;

/**
 * 文件命令服务。
 */
class FileRecordCommandService extends BaseService
{
    public function __construct(
        protected FileRecordRepository $fileRecordRepository,
        protected FileRecordQueryService $fileRecordQueryService,
        protected StorageManager $storageManager,
        protected StorageConfigService $storageConfigService
    ) {
    }

    public function upload(UploadFile $file, array $data, int $createdBy = 0, string $createdByName = ''): array
    {
        $this->assertFileUpload($file);

        $sourcePath = $file->getPathname();
        try {
            $scene = $this->storageConfigService->normalizeScene($data['scene'] ?? null, (string) $file->getUploadName(), (string) $file->getUploadMimeType());
            $visibility = $this->storageConfigService->normalizeVisibility($data['visibility'] ?? null, $scene);
            $engine = $this->storageConfigService->defaultEngine();

            $result = $this->storageManager->storeFromPath(
                $sourcePath,
                (string) $file->getUploadName(),
                $scene,
                $visibility,
                $engine,
                null,
                'upload'
            );

            try {
                $asset = $this->fileRecordRepository->create([
                    'scene' => (int) $result['scene'],
                    'source_type' => (int) $result['source_type'],
                    'visibility' => (int) $result['visibility'],
                    'storage_engine' => (int) $result['storage_engine'],
                    'original_name' => (string) $result['original_name'],
                    'file_name' => (string) $result['file_name'],
                    'file_ext' => (string) $result['file_ext'],
                    'mime_type' => (string) $result['mime_type'],
                    'size' => (int) $result['size'],
                    'md5' => (string) $result['md5'],
                    'object_key' => (string) $result['object_key'],
                    'url' => (string) $result['url'],
                    'source_url' => (string) ($result['source_url'] ?? ''),
                    'created_by' => $createdBy,
                    'created_by_name' => $createdByName,
                ]);
            } catch (\Throwable $e) {
                $this->storageManager->delete($result);
                throw $e;
            }

            return $this->fileRecordQueryService->formatModel($asset);
        } finally {
            if (is_file($sourcePath)) {
                @unlink($sourcePath);
            }
        }
    }

    public function importRemote(string $remoteUrl, array $data, int $createdBy = 0, string $createdByName = ''): array
    {
        $remoteUrl = trim($remoteUrl);
        if ($remoteUrl === '') {
            throw new ValidationException('远程图片地址不能为空');
        }

        $download = $this->downloadRemoteFile($remoteUrl, (int) ($data['scene'] ?? 0));
        try {
            $scene = $this->storageConfigService->normalizeScene($data['scene'] ?? null, $download['name'], $download['mime_type']);
            $visibility = $this->storageConfigService->normalizeVisibility($data['visibility'] ?? null, $scene);
            $engine = $this->storageConfigService->defaultEngine();

            $result = $this->storageManager->storeFromPath(
                $download['path'],
                $download['name'],
                $scene,
                $visibility,
                $engine,
                $remoteUrl,
                'remote_url'
            );

            try {
                $asset = $this->fileRecordRepository->create([
                    'scene' => (int) $result['scene'],
                    'source_type' => (int) $result['source_type'],
                    'visibility' => (int) $result['visibility'],
                    'storage_engine' => (int) $result['storage_engine'],
                    'original_name' => (string) $result['original_name'],
                    'file_name' => (string) $result['file_name'],
                    'file_ext' => (string) $result['file_ext'],
                    'mime_type' => (string) $result['mime_type'],
                    'size' => (int) $result['size'],
                    'md5' => (string) $result['md5'],
                    'object_key' => (string) $result['object_key'],
                    'url' => (string) $result['url'],
                    'source_url' => $remoteUrl,
                    'created_by' => $createdBy,
                    'created_by_name' => $createdByName,
                ]);
            } catch (\Throwable $e) {
                $this->storageManager->delete($result);
                throw $e;
            }

            return $this->fileRecordQueryService->formatModel($asset);
        } finally {
            if (is_file($download['path'])) {
                @unlink($download['path']);
            }
        }
    }

    public function delete(int $id): bool
    {
        $asset = $this->fileRecordRepository->findById($id);
        if (!$asset) {
            return false;
        }

        $this->storageManager->delete($this->fileRecordQueryService->formatModel($asset));

        return $this->fileRecordRepository->deleteById($id);
    }

    private function assertFileUpload(UploadFile $file): void
    {
        if (!$file->isValid()) {
            throw new ValidationException('上传文件无效');
        }

        $sizeLimit = $this->storageConfigService->uploadMaxSizeBytes();
        $size = (int) $file->getSize();
        if ($size > $sizeLimit) {
            throw new BusinessStateException('文件大小超过系统限制');
        }

        $extension = strtolower((string) $file->getUploadExtension());
        if ($extension === '') {
            $extension = strtolower((string) pathinfo((string) $file->getUploadName(), PATHINFO_EXTENSION));
        }

        if ($extension !== '' && !in_array($extension, $this->storageConfigService->allowedExtensions(), true)) {
            throw new BusinessStateException('文件类型暂不支持');
        }
    }

    private function downloadRemoteFile(string $remoteUrl, int $scene = 0): array
    {
        if (!filter_var($remoteUrl, FILTER_VALIDATE_URL)) {
            throw new ValidationException('远程图片地址格式不正确');
        }

        $scheme = strtolower((string) parse_url($remoteUrl, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new ValidationException('仅支持 http 或 https 远程地址');
        }

        $host = (string) parse_url($remoteUrl, PHP_URL_HOST);
        if ($host === '') {
            throw new ValidationException('远程图片地址格式不正确');
        }

        if (filter_var($host, FILTER_VALIDATE_IP) && Request::isIntranetIp($host)) {
            throw new BusinessStateException('远程地址不允许访问内网资源');
        }

        $ip = gethostbyname($host);
        if ($ip !== $host && Request::isIntranetIp($ip)) {
            throw new BusinessStateException('远程地址不允许访问内网资源');
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'file_asset_');
        if ($tempPath === false) {
            throw new BusinessStateException('创建临时文件失败');
        }

        $mimeType = 'application/octet-stream';
        $downloadName = basename((string) parse_url($remoteUrl, PHP_URL_PATH));
        if ($downloadName === '') {
            $downloadName = 'remote-file';
        }

        $ch = curl_init($remoteUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_USERAGENT => 'MPay File Asset Downloader',
        ]);

        $body = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $effectiveUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);

        curl_close($ch);

        if ($body === false || $httpCode >= 400) {
            @unlink($tempPath);
            throw new BusinessStateException($error !== '' ? $error : '远程文件下载失败');
        }

        if ($effectiveUrl !== '') {
            $effectiveHost = (string) parse_url($effectiveUrl, PHP_URL_HOST);
            if ($effectiveHost !== '') {
                $effectiveIp = gethostbyname($effectiveHost);
                if ($effectiveIp !== $effectiveHost && Request::isIntranetIp($effectiveIp)) {
                    @unlink($tempPath);
                    throw new BusinessStateException('远程地址重定向到了内网资源');
                }
            }
        }

        if ($contentType !== '') {
            $mimeType = trim(explode(';', $contentType)[0]);
        }

        if (strlen((string) $body) > $this->storageConfigService->remoteDownloadLimitBytes()) {
            @unlink($tempPath);
            throw new BusinessStateException('远程文件大小超过系统限制');
        }

        if (file_put_contents($tempPath, (string) $body) === false) {
            @unlink($tempPath);
            throw new BusinessStateException('远程文件写入失败');
        }

        $size = is_file($tempPath) ? (int) filesize($tempPath) : 0;
        $name = $downloadName;
        $ext = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
        if ($ext === '') {
            $ext = match (true) {
                str_starts_with($mimeType, 'image/jpeg') => 'jpg',
                str_starts_with($mimeType, 'image/png') => 'png',
                str_starts_with($mimeType, 'image/gif') => 'gif',
                str_starts_with($mimeType, 'image/webp') => 'webp',
                str_starts_with($mimeType, 'image/svg') => 'svg',
                str_starts_with($mimeType, 'text/plain') => 'txt',
                str_starts_with($mimeType, 'application/json') => 'json',
                str_starts_with($mimeType, 'application/xml') => 'xml',
                default => '',
            };
            if ($ext !== '') {
                $name .= '.' . $ext;
            }
        }

        return [
            'path' => $tempPath,
            'name' => $name,
            'mime_type' => $mimeType,
            'size' => $size,
            'scene' => $scene,
        ];
    }
}
