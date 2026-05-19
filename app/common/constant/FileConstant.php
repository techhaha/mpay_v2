<?php

namespace app\common\constant;

/**
 * 文件相关常量。
 *
 * 用于描述文件来源、可见性、场景、存储引擎和文件类型白名单。
 */
final class FileConstant
{
    /**
     * 上传来源。
     */
    public const SOURCE_UPLOAD = 1;

    /**
     * 远程 URL 导入来源。
     */
    public const SOURCE_REMOTE_URL = 2;

    /**
     * 公开可访问文件。
     */
    public const VISIBILITY_PUBLIC = 1;

    /**
     * 私有文件。
     */
    public const VISIBILITY_PRIVATE = 2;

    /**
     * 图片场景。
     */
    public const SCENE_IMAGE = 1;

    /**
     * 证书场景。
     */
    public const SCENE_CERTIFICATE = 2;

    /**
     * 文本场景。
     */
    public const SCENE_TEXT = 3;

    /**
     * 其他场景。
     */
    public const SCENE_OTHER = 4;

    /**
     * 本地存储引擎。
     */
    public const STORAGE_LOCAL = 1;

    /**
     * 阿里云 OSS 存储引擎。
     */
    public const STORAGE_ALIYUN_OSS = 2;

    /**
     * 腾讯云 COS 存储引擎。
     */
    public const STORAGE_TENCENT_COS = 3;

    /**
     * 远程引用存储引擎。
     */
    public const STORAGE_REMOTE_URL = 4;

    /**
     * 文件存储默认引擎配置 key。
     */
    public const CONFIG_DEFAULT_ENGINE = 'file_storage_default_engine';
    /**
     * 本地公开目录访问地址配置 key。
     */
    public const CONFIG_LOCAL_PUBLIC_BASE_URL = 'file_storage_local_public_base_url';
    /**
     * 本地公开目录路径配置 key。
     */
    public const CONFIG_LOCAL_PUBLIC_DIR = 'file_storage_local_public_dir';
    /**
     * 本地私有目录路径配置 key。
     */
    public const CONFIG_LOCAL_PRIVATE_DIR = 'file_storage_local_private_dir';
    /**
     * 上传文件大小上限配置 key，单位 MB。
     */
    public const CONFIG_UPLOAD_MAX_SIZE_MB = 'file_storage_upload_max_size_mb';
    /**
     * 远程下载大小上限配置 key，单位 MB。
     */
    public const CONFIG_REMOTE_DOWNLOAD_LIMIT_MB = 'file_storage_remote_download_limit_mb';
    /**
     * 阿里云 OSS Endpoint 配置 key。
     */
    public const CONFIG_OSS_ENDPOINT = 'file_storage_aliyun_oss_endpoint';
    /**
     * 阿里云 OSS Bucket 配置 key。
     */
    public const CONFIG_OSS_BUCKET = 'file_storage_aliyun_oss_bucket';
    /**
     * 阿里云 OSS Access Key ID 配置 key。
     */
    public const CONFIG_OSS_ACCESS_KEY_ID = 'file_storage_aliyun_oss_access_key_id';
    /**
     * 阿里云 OSS Access Key Secret 配置 key。
     */
    public const CONFIG_OSS_ACCESS_KEY_SECRET = 'file_storage_aliyun_oss_access_key_secret';
    /**
     * 阿里云 OSS 公开域名配置 key。
     */
    public const CONFIG_OSS_PUBLIC_DOMAIN = 'file_storage_aliyun_oss_public_domain';
    /**
     * 阿里云 OSS 地域配置 key。
     */
    public const CONFIG_OSS_REGION = 'file_storage_aliyun_oss_region';
    /**
     * 腾讯云 COS 地域配置 key。
     */
    public const CONFIG_COS_REGION = 'file_storage_tencent_cos_region';
    /**
     * 腾讯云 COS Bucket 配置 key。
     */
    public const CONFIG_COS_BUCKET = 'file_storage_tencent_cos_bucket';
    /**
     * 腾讯云 COS SecretId 配置 key。
     */
    public const CONFIG_COS_SECRET_ID = 'file_storage_tencent_cos_secret_id';
    /**
     * 腾讯云 COS SecretKey 配置 key。
     */
    public const CONFIG_COS_SECRET_KEY = 'file_storage_tencent_cos_secret_key';
    /**
     * 腾讯云 COS 公开域名配置 key。
     */
    public const CONFIG_COS_PUBLIC_DOMAIN = 'file_storage_tencent_cos_public_domain';

    /**
     * 获取文件来源映射。
     *
     * @return array<int, string> 来源名称表
     */
    public static function sourceTypeMap(): array
    {
        return [
            self::SOURCE_UPLOAD => '上传',
            self::SOURCE_REMOTE_URL => '远程导入',
        ];
    }

    /**
     * 获取文件可见性映射。
     *
     * @return array<int, string> 可见性名称表
     */
    public static function visibilityMap(): array
    {
        return [
            self::VISIBILITY_PUBLIC => '公开',
            self::VISIBILITY_PRIVATE => '私有',
        ];
    }

    /**
     * 获取文件场景映射。
     *
     * @return array<int, string> 场景名称表
     */
    public static function sceneMap(): array
    {
        return [
            self::SCENE_IMAGE => '图片',
            self::SCENE_CERTIFICATE => '证书',
            self::SCENE_TEXT => '文本',
            self::SCENE_OTHER => '其他',
        ];
    }

    /**
     * 获取存储引擎映射。
     *
     * @return array<int, string> 存储引擎名称表
     */
    public static function storageEngineMap(): array
    {
        return [
            self::STORAGE_LOCAL => '本地存储',
            self::STORAGE_ALIYUN_OSS => '阿里云 OSS',
            self::STORAGE_TENCENT_COS => '腾讯云 COS',
            self::STORAGE_REMOTE_URL => '远程引用',
        ];
    }

    /**
     * 获取可选存储引擎映射。
     *
     * @return array<int, string> 可选存储引擎名称表
     */
    public static function selectableStorageEngineMap(): array
    {
        return [
            self::STORAGE_LOCAL => '本地存储',
            self::STORAGE_ALIYUN_OSS => '阿里云 OSS',
            self::STORAGE_TENCENT_COS => '腾讯云 COS',
        ];
    }

    /**
     * 获取图片扩展名白名单。
     *
     * @return array<string, bool> 白名单集合
     */
    public static function imageExtensionMap(): array
    {
        return [
            'jpg' => true,
            'jpeg' => true,
            'png' => true,
            'gif' => true,
            'webp' => true,
            'bmp' => true,
            'svg' => true,
        ];
    }

    /**
     * 获取证书扩展名白名单。
     *
     * @return array<string, bool> 白名单集合
     */
    public static function certificateExtensionMap(): array
    {
        return [
            'pem' => true,
            'crt' => true,
            'cer' => true,
            'key' => true,
            'p12' => true,
            'pfx' => true,
        ];
    }

    /**
     * 获取文本扩展名白名单。
     *
     * @return array<string, bool> 白名单集合
     */
    public static function textExtensionMap(): array
    {
        return [
            'txt' => true,
            'log' => true,
            'csv' => true,
            'json' => true,
            'xml' => true,
            'md' => true,
            'ini' => true,
            'conf' => true,
            'yaml' => true,
            'yml' => true,
        ];
    }

    /**
     * 获取默认允许上传的扩展名。
     *
     * @return array<int, string> 扩展名列表
     */
    public static function defaultAllowedExtensions(): array
    {
        return array_keys(self::imageExtensionMap() + self::certificateExtensionMap() + self::textExtensionMap());
    }
}


