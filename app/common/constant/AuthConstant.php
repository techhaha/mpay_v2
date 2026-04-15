<?php

namespace app\common\constant;

/**
 * 认证相关常量。
 *
 * 统一管理登录域、令牌状态、签名类型等枚举值。
 */
final class AuthConstant
{
    public const GUARD_ADMIN = 1;
    public const GUARD_MERCHANT = 2;

    public const JWT_ALG_HS256 = 'HS256';

    public const TOKEN_STATUS_DISABLED = 0;
    public const TOKEN_STATUS_ENABLED = 1;

    public const LOGIN_STATUS_DISABLED = 0;
    public const LOGIN_STATUS_ENABLED = 1;

    public const API_SIGN_TYPE_MD5 = 0;

    public static function signTypeMap(): array
    {
        return [
            self::API_SIGN_TYPE_MD5 => 'MD5',
        ];
    }

    public static function guardMap(): array
    {
        return [
            self::GUARD_ADMIN => 'admin',
            self::GUARD_MERCHANT => 'merchant',
        ];
    }
}
