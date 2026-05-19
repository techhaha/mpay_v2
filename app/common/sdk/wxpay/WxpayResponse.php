<?php

declare(strict_types=1);

namespace app\common\sdk\wxpay;

/**
 * 微信支付接口响应对象。
 *
 * V3 接口返回 JSON，V2 接口返回 XML。该对象统一封装 HTTP 状态、原始响应、
 * 解码后的业务数据和成功判断，方便支付插件保存 raw 信息或转换为项目标准返回。
 */
class WxpayResponse
{
    /**
     * 微信支付 API 版本。
     *
     * @var string
     */
    private string $apiVersion;

    /**
     * 请求方法或 V2 接口名。
     *
     * @var string
     */
    private string $method;

    /**
     * 请求路径。
     *
     * @var string
     */
    private string $path;

    /**
     * HTTP 状态码。
     *
     * @var int
     */
    private int $statusCode;

    /**
     * 原始响应体。
     *
     * @var string
     */
    private string $rawBody;

    /**
     * 解码后的响应数据。
     *
     * @var array<string, mixed>
     */
    private array $data;

    /**
     * HTTP 响应头。
     *
     * @var array<string, array<int, string>>
     */
    private array $headers;

    /**
     * 构造方法。
     *
     * @param string $apiVersion API 版本，v2 或 v3
     * @param string $method 请求方法或接口名
     * @param string $path 请求路径
     * @param int $statusCode HTTP 状态码
     * @param string $rawBody 原始响应体
     * @param array<string, mixed> $data 解码响应
     * @param array<string, array<int, string>> $headers 响应头
     */
    public function __construct(
        string $apiVersion,
        string $method,
        string $path,
        int $statusCode,
        string $rawBody,
        array $data,
        array $headers = []
    ) {
        $this->apiVersion = $apiVersion;
        $this->method = $method;
        $this->path = $path;
        $this->statusCode = $statusCode;
        $this->rawBody = $rawBody;
        $this->data = $data;
        $this->headers = $headers;
    }

    /**
     * 获取 API 版本。
     *
     * @return string v2 或 v3
     */
    public function apiVersion(): string
    {
        return $this->apiVersion;
    }

    /**
     * 获取请求方法或接口名。
     *
     * @return string 请求方法或接口名
     */
    public function method(): string
    {
        return $this->method;
    }

    /**
     * 获取请求路径。
     *
     * @return string 请求路径
     */
    public function path(): string
    {
        return $this->path;
    }

    /**
     * 获取 HTTP 状态码。
     *
     * @return int HTTP 状态码
     */
    public function statusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * 获取原始响应体。
     *
     * @return string 原始响应体
     */
    public function rawBody(): string
    {
        return $this->rawBody;
    }

    /**
     * 获取解码后的响应数据。
     *
     * @return array<string, mixed> 响应数据
     */
    public function data(): array
    {
        return $this->data;
    }

    /**
     * 获取响应头。
     *
     * @return array<string, array<int, string>> 响应头
     */
    public function headers(): array
    {
        return $this->headers;
    }

    /**
     * 判断接口业务是否成功。
     *
     * V3 接口以 2xx HTTP 状态作为成功判断；错误响应中通常包含 code/message。
     * V2 接口需要 return_code 与 result_code 同时为 SUCCESS。
     *
     * @return bool 是否成功
     */
    public function success(): bool
    {
        if ($this->apiVersion === WxpayClient::API_VERSION_V2) {
            return ($this->data['return_code'] ?? '') === 'SUCCESS'
                && ($this->data['result_code'] ?? 'SUCCESS') === 'SUCCESS';
        }

        return $this->statusCode >= 200 && $this->statusCode < 300;
    }

    /**
     * 获取错误码或状态码。
     *
     * @return string 错误码
     */
    public function code(): string
    {
        if ($this->apiVersion === WxpayClient::API_VERSION_V2) {
            return (string) ($this->data['err_code'] ?? $this->data['return_code'] ?? '');
        }

        return (string) ($this->data['code'] ?? $this->statusCode);
    }

    /**
     * 获取错误说明。
     *
     * @return string 错误说明
     */
    public function message(): string
    {
        if ($this->apiVersion === WxpayClient::API_VERSION_V2) {
            return (string) ($this->data['err_code_des'] ?? $this->data['return_msg'] ?? '');
        }

        return (string) ($this->data['message'] ?? '');
    }

    /**
     * 转换为数组。
     *
     * @return array<string, mixed> 响应摘要
     */
    public function toArray(): array
    {
        return [
            'api_version' => $this->apiVersion,
            'method' => $this->method,
            'path' => $this->path,
            'status_code' => $this->statusCode,
            'success' => $this->success(),
            'code' => $this->code(),
            'message' => $this->message(),
            'data' => $this->data,
            'raw_body' => $this->rawBody,
        ];
    }
}
