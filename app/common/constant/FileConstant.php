<?php

namespace app\common\constant;

/**
 * 文件相关常量。
 */
final class FileConstant
{
    public const SOURCE_UPLOAD = 1;
    public const SOURCE_REMOTE_URL = 2;

    public const VISIBILITY_PUBLIC = 1;
    public const VISIBILITY_PRIVATE = 2;

    public const SCENE_IMAGE = 1;
    public const SCENE_CERTIFICATE = 2;
    public const SCENE_TEXT = 3;
    public const SCENE_OTHER = 4;

    public const STORAGE_LOCAL = 1;
    public const STORAGE_ALIYUN_OSS = 2;
    public const STORAGE_TENCENT_COS = 3;
    public const STORAGE_REMOTE_URL = 4;

    public const CONFIG_DEFAULT_ENGINE = 'file_storage_default_engine';
    public const CONFIG_LOCAL_PUBLIC_BASE_URL = 'file_storage_local_public_base_url';
    public const CONFIG_LOCAL_PUBLIC_DIR = 'file_storage_local_public_dir';
    public const CONFIG_LOCAL_PRIVATE_DIR = 'file_storage_local_private_dir';
    public const CONFIG_UPLOAD_MAX_SIZE_MB = 'file_storage_upload_max_size_mb';
    public const CONFIG_REMOTE_DOWNLOAD_LIMIT_MB = 'file_storage_remote_download_limit_mb';
    public const CONFIG_ALLOWED_EXTENSIONS = 'file_storage_allowed_extensions';
    public const CONFIG_OSS_ENDPOINT = 'file_storage_aliyun_oss_endpoint';
    public const CONFIG_OSS_BUCKET = 'file_storage_aliyun_oss_bucket';
    public const CONFIG_OSS_ACCESS_KEY_ID = 'file_storage_aliyun_oss_access_key_id';
    public const CONFIG_OSS_ACCESS_KEY_SECRET = 'file_storage_aliyun_oss_access_key_secret';
    public const CONFIG_OSS_PUBLIC_DOMAIN = 'file_storage_aliyun_oss_public_domain';
    public const CONFIG_OSS_REGION = 'file_storage_aliyun_oss_region';
    public const CONFIG_COS_REGION = 'file_storage_tencent_cos_region';
    public const CONFIG_COS_BUCKET = 'file_storage_tencent_cos_bucket';
    public const CONFIG_COS_SECRET_ID = 'file_storage_tencent_cos_secret_id';
    public const CONFIG_COS_SECRET_KEY = 'file_storage_tencent_cos_secret_key';
    public const CONFIG_COS_PUBLIC_DOMAIN = 'file_storage_tencent_cos_public_domain';

    public static function sourceTypeMap(): array
    {
        return [
            self::SOURCE_UPLOAD => '上传',
            self::SOURCE_REMOTE_URL => '远程导入',
        ];
    }

    public static function visibilityMap(): array
    {
        return [
            self::VISIBILITY_PUBLIC => '公开',
            self::VISIBILITY_PRIVATE => '私有',
        ];
    }

    public static function sceneMap(): array
    {
        return [
            self::SCENE_IMAGE => '图片',
            self::SCENE_CERTIFICATE => '证书',
            self::SCENE_TEXT => '文本',
            self::SCENE_OTHER => '其他',
        ];
    }

    public static function storageEngineMap(): array
    {
        return [
            self::STORAGE_LOCAL => '本地存储',
            self::STORAGE_ALIYUN_OSS => '阿里云 OSS',
            self::STORAGE_TENCENT_COS => '腾讯云 COS',
            self::STORAGE_REMOTE_URL => '远程引用',
        ];
    }

    public static function selectableStorageEngineMap(): array
    {
        return [
            self::STORAGE_LOCAL => '本地存储',
            self::STORAGE_ALIYUN_OSS => '阿里云 OSS',
            self::STORAGE_TENCENT_COS => '腾讯云 COS',
        ];
    }

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

    public static function defaultAllowedExtensions(): array
    {
        return array_keys(self::imageExtensionMap() + self::certificateExtensionMap() + self::textExtensionMap());
    }
}
