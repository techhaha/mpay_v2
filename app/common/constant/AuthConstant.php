<?php

namespace app\common\constant;

/**
 * 认证相关常量。
 *
 * 统一管理登录域、令牌状态、签名类型等枚举值。
 */
final class AuthConstant
{
    /**
     * 管理员登录域。
     */
    public const GUARD_ADMIN = 1;

    /**
     * 商户登录域。
     */
    public const GUARD_MERCHANT = 2;

    /**
     * JWT 签名算法。
     */
    public const JWT_ALG_HS256 = 'HS256';

    /**
     * 令牌禁用状态。
     */
    public const TOKEN_STATUS_DISABLED = 0;

    /**
     * 令牌启用状态。
     */
    public const TOKEN_STATUS_ENABLED = 1;

    /**
     * 登录禁用状态。
     */
    public const LOGIN_STATUS_DISABLED = 0;

    /**
     * 登录启用状态。
     */
    public const LOGIN_STATUS_ENABLED = 1;

    /**
     * API 签名类型：MD5。
     */
    public const API_SIGN_TYPE_MD5 = 0;

    /**
     * 获取签名类型映射。
     *
     * @return array<int, string> 签名类型名称表
     */
    public static function signTypeMap(): array
    {
        return [
            self::API_SIGN_TYPE_MD5 => 'MD5',
        ];
    }

    /**
     * 获取登录域映射。
     *
     * @return array<int, string> 登录域名称表
     */
    public static function guardMap(): array
    {
        return [
            self::GUARD_ADMIN => 'admin',
            self::GUARD_MERCHANT => 'merchant',
        ];
    }
}




