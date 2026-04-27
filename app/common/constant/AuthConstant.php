<?php

namespace app\common\constant;

/**
 * 认证相关常量。
 *
 * 统一管理登录域、会话状态、接口凭证状态和开放接口签名类型。
 */
final class AuthConstant
{
    /**
     * 管理员登录域。
     */
    public const GUARD_ADMIN = 'admin';

    /**
     * 商户登录域。
     */
    public const GUARD_MERCHANT = 'merchant';

    /**
     * JWT 签名算法。
     */
    public const JWT_ALGORITHM_HS256 = 'HS256';

    /**
     * 会话令牌禁用状态。
     */
    public const TOKEN_STATUS_DISABLED = 0;

    /**
     * 会话令牌启用状态。
     */
    public const TOKEN_STATUS_ENABLED = 1;

    /**
     * 接口凭证禁用状态。
     */
    public const CREDENTIAL_STATUS_DISABLED = 0;

    /**
     * 接口凭证启用状态。
     */
    public const CREDENTIAL_STATUS_ENABLED = 1;

    /**
     * API 签名类型：MD5。
     */
    public const API_SIGN_TYPE_MD5 = 0;

    /**
     * API 签名类型：SHA256WithRSA。
     */
    public const API_SIGN_TYPE_SHA256_WITH_RSA = 1;

    /**
     * API 签名类型名称：MD5。
     */
    public const API_SIGN_NAME_MD5 = 'MD5';

    /**
     * API 签名类型名称：SHA256WithRSA。
     */
    public const API_SIGN_NAME_SHA256_WITH_RSA = 'SHA256WithRSA';

    /**
     * API 签名类型归一化名称：SHA256WITHRSA。
     */
    public const API_SIGN_NORMALIZED_SHA256_WITH_RSA = 'SHA256WITHRSA';

    /**
     * 获取签名类型映射。
     *
     * @return array<int, string> 签名类型名称表
     */
    public static function signTypeMap(): array
    {
        return [
            self::API_SIGN_TYPE_MD5 => self::API_SIGN_NAME_MD5,
            self::API_SIGN_TYPE_SHA256_WITH_RSA => self::API_SIGN_NAME_SHA256_WITH_RSA,
        ];
    }

    /**
     * 获取接口凭证状态映射。
     *
     * @return array<int, string> 接口凭证状态名称表
     */
    public static function credentialStatusMap(): array
    {
        return [
            self::CREDENTIAL_STATUS_ENABLED => '启用',
            self::CREDENTIAL_STATUS_DISABLED => '禁用',
        ];
    }

    /**
     * 获取登录域映射。
     *
     * @return array<string, string> 登录域名称表
     */
    public static function guardMap(): array
    {
        return [
            self::GUARD_ADMIN => '管理后台',
            self::GUARD_MERCHANT => '商户后台',
        ];
    }
}


