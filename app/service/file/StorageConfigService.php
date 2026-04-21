<?php

namespace app\service\file;

use app\common\base\BaseService;
use app\common\constant\FileConstant;
use app\exception\BusinessStateException;

/**
 * 文件存储配置服务。
 *
 * 负责读取系统配置并统一整理文件场景、可见性、扩展名和存储引擎相关规则。
 */
class StorageConfigService extends BaseService
{
    /**
     * 获取默认存储引擎。
     *
     * @return int 存储引擎
     */
    public function defaultEngine(): int
    {
        return $this->normalizeSelectableEngine((int) sys_config(FileConstant::CONFIG_DEFAULT_ENGINE, FileConstant::STORAGE_LOCAL));
    }

    /**
     * 获取本地公开访问基址。
     *
     * @return string 基础地址
     * @throws BusinessStateException
     */
    public function localPublicBaseUrl(): string
    {
        $siteUrl = trim((string) sys_config('site_url', ''));
        if ($siteUrl !== '') {
            $baseUrl = trim((string) sys_config(FileConstant::CONFIG_LOCAL_PUBLIC_BASE_URL, ''));
            if ($baseUrl !== '') {
                return rtrim($baseUrl, '/');
            }

            return rtrim($siteUrl, '/');
        }

        throw new BusinessStateException('请先在系统配置中设置站点 URL');
    }

    /**
     * 获取本地公开目录。
     *
     * @return string 目录
     */
    public function localPublicDir(): string
    {
        $dir = trim((string) sys_config(FileConstant::CONFIG_LOCAL_PUBLIC_DIR, 'storage/uploads'), "/ \t\n\r\0\x0B");

        return $dir !== '' ? $dir : 'storage/uploads';
    }

    /**
     * 获取本地私有目录。
     *
     * @return string 目录
     */
    public function localPrivateDir(): string
    {
        $dir = trim((string) sys_config(FileConstant::CONFIG_LOCAL_PRIVATE_DIR, 'storage/private'), "/ \t\n\r\0\x0B");

        return $dir !== '' ? $dir : 'storage/private';
    }

    /**
     * 获取上传大小上限。
     *
     * @return int 字节数
     */
    public function uploadMaxSizeBytes(): int
    {
        $mb = max(1, (int) sys_config(FileConstant::CONFIG_UPLOAD_MAX_SIZE_MB, 20));

        return $mb * 1024 * 1024;
    }

    /**
     * 获取远程下载大小上限。
     *
     * @return int 字节数
     */
    public function remoteDownloadLimitBytes(): int
    {
        $mb = max(1, (int) sys_config(FileConstant::CONFIG_REMOTE_DOWNLOAD_LIMIT_MB, 10));

        return $mb * 1024 * 1024;
    }

    /**
     * 获取允许上传的扩展名列表。
     *
     * @return array 允许的扩展名列表
     */
    public function allowedExtensions(): array
    {
        $raw = trim((string) sys_config(FileConstant::CONFIG_ALLOWED_EXTENSIONS, implode(',', FileConstant::defaultAllowedExtensions())));
        if ($raw === '') {
            return FileConstant::defaultAllowedExtensions();
        }

        $extensions = array_filter(array_map(static fn (string $value): string => strtolower(trim($value)), explode(',', $raw)));

        return array_values(array_unique($extensions));
    }

    /**
     * 获取阿里云 OSS 配置。
     *
     * @return array OSS 配置
     */
    public function ossConfig(): array
    {
        return [
            'region' => trim((string) sys_config(FileConstant::CONFIG_OSS_REGION, '')),
            'endpoint' => trim((string) sys_config(FileConstant::CONFIG_OSS_ENDPOINT, '')),
            'bucket' => trim((string) sys_config(FileConstant::CONFIG_OSS_BUCKET, '')),
            'access_key_id' => trim((string) sys_config(FileConstant::CONFIG_OSS_ACCESS_KEY_ID, '')),
            'access_key_secret' => trim((string) sys_config(FileConstant::CONFIG_OSS_ACCESS_KEY_SECRET, '')),
            'public_domain' => trim((string) sys_config(FileConstant::CONFIG_OSS_PUBLIC_DOMAIN, '')),
        ];
    }

    /**
     * 获取腾讯云 COS 配置。
     *
     * @return array COS 配置
     */
    public function cosConfig(): array
    {
        return [
            'region' => trim((string) sys_config(FileConstant::CONFIG_COS_REGION, '')),
            'bucket' => trim((string) sys_config(FileConstant::CONFIG_COS_BUCKET, '')),
            'secret_id' => trim((string) sys_config(FileConstant::CONFIG_COS_SECRET_ID, '')),
            'secret_key' => trim((string) sys_config(FileConstant::CONFIG_COS_SECRET_KEY, '')),
            'public_domain' => trim((string) sys_config(FileConstant::CONFIG_COS_PUBLIC_DOMAIN, '')),
        ];
    }

    /**
     * 归一化文件场景。
     *
     * @param int|string|null $scene 场景
     * @param string $originalName 原始文件名
     * @param string $mimeType MIME 类型
     * @return int 场景值
     */
    public function normalizeScene(int|string|null $scene = null, string $originalName = '', string $mimeType = ''): int
    {
        $scene = (int) $scene;
        if ($scene === FileConstant::SCENE_IMAGE
            || $scene === FileConstant::SCENE_CERTIFICATE
            || $scene === FileConstant::SCENE_TEXT
            || $scene === FileConstant::SCENE_OTHER
        ) {
            return $scene;
        }

        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if ($ext !== '') {
            if (isset(FileConstant::imageExtensionMap()[$ext]) || str_starts_with(strtolower($mimeType), 'image/')) {
                return FileConstant::SCENE_IMAGE;
            }

            if (isset(FileConstant::certificateExtensionMap()[$ext])) {
                return FileConstant::SCENE_CERTIFICATE;
            }

            if (isset(FileConstant::textExtensionMap()[$ext]) || str_starts_with(strtolower($mimeType), 'text/')) {
                return FileConstant::SCENE_TEXT;
            }
        }

        return FileConstant::SCENE_OTHER;
    }

    /**
     * 归一化文件可见性。
     *
     * @param int|string|null $visibility 可见性
     * @param int $scene 场景
     * @return int 可见性值
     */
    public function normalizeVisibility(int|string|null $visibility = null, int $scene = FileConstant::SCENE_OTHER): int
    {
        $visibility = (int) $visibility;
        if ($visibility === FileConstant::VISIBILITY_PUBLIC || $visibility === FileConstant::VISIBILITY_PRIVATE) {
            return $visibility;
        }

        return $scene === FileConstant::SCENE_IMAGE
            ? FileConstant::VISIBILITY_PUBLIC
            : FileConstant::VISIBILITY_PRIVATE;
    }

    /**
     * 归一化存储引擎。
     *
     * @param int|string|null $engine 存储引擎
     * @return int 存储引擎值
     */
    public function normalizeEngine(int|string|null $engine = null): int
    {
        $engine = (int) $engine;

        return $this->normalizeSelectableEngine($engine);
    }

    /**
     * 获取场景对应的目录名。
     *
     * @param int $scene 场景
     * @return string 目录名
     */
    public function sceneFolder(int $scene): string
    {
        return match ($scene) {
            FileConstant::SCENE_IMAGE => 'image',
            FileConstant::SCENE_CERTIFICATE => 'certificate',
            FileConstant::SCENE_TEXT => 'text',
            default => 'other',
        };
    }

    /**
     * 构建对象键。
     *
     * @param int $scene 场景
     * @param int $visibility 可见性
     * @param string $extension 扩展名
     * @return string 对象键
     */
    public function buildObjectKey(int $scene, int $visibility, string $extension): string
    {
        $extension = strtolower(trim($extension, ". \t\n\r\0\x0B"));
        $timestampPath = date('Y/m/d');
        $random = bin2hex(random_bytes(8));
        $name = date('YmdHis') . '_' . $random;
        if ($extension !== '') {
            $name .= '.' . $extension;
        }

        $rootDir = $visibility === FileConstant::VISIBILITY_PUBLIC
            ? $this->localPublicDir()
            : $this->localPrivateDir();

        return trim($rootDir . '/' . $this->sceneFolder($scene) . '/' . $timestampPath . '/' . $name, '/');
    }

    /**
     * 构建本地绝对路径。
     *
     * @param int $visibility 可见性
     * @param string $objectKey 对象键
     * @return string 绝对路径
     */
    public function buildLocalAbsolutePath(int $visibility, string $objectKey): string
    {
        $root = $visibility === FileConstant::VISIBILITY_PUBLIC
            ? public_path()
            : runtime_path();
        $relativePath = trim(str_replace('\\', '/', $objectKey), '/');

        return rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    }

    /**
     * 构建本地公开访问 URL。
     *
     * @param string $objectKey 对象键
     * @return string 访问 URL
     */
    public function buildLocalPublicUrl(string $objectKey): string
    {
        $path = trim(str_replace('\\', '/', $objectKey), '/');
        $baseUrl = $this->localPublicBaseUrl();

        if ($baseUrl !== '') {
            return rtrim($baseUrl, '/') . '/' . $path;
        }

        return '/' . $path;
    }

    /**
     * 归一化可选存储引擎。
     *
     * @param int $engine 存储引擎
     * @return int 存储引擎值
     */
    private function normalizeSelectableEngine(int $engine): int
    {
        return match ($engine) {
            FileConstant::STORAGE_LOCAL,
            FileConstant::STORAGE_ALIYUN_OSS,
            FileConstant::STORAGE_TENCENT_COS => $engine,
            default => FileConstant::STORAGE_LOCAL,
        };
    }
}




