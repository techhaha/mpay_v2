<?php

declare(strict_types=1);

namespace app\common\sdk\alipay;

/**
 * 支付宝 OpenAPI 响应对象。
 *
 * 支付宝网关响应通常包含两个顶层字段：
 * - xxx_response：当前接口的业务响应节点。
 * - sign：支付宝对业务响应节点的签名。
 *
 * 该对象负责封装原始响应、业务响应节点、公共 code/msg 字段和验签结果，
 * 后续支付插件可以直接读取 data() 或 toArray() 转换为项目标准返回结构。
 */
class AlipayResponse
{
    /**
     * 支付宝接口方法名。
     *
     * @var string
     */
    private string $method;

    /**
     * 网关原始响应体。
     *
     * @var string
     */
    private string $rawBody;

    /**
     * JSON 解码后的完整响应。
     *
     * @var array<string, mixed>
     */
    private array $decoded;

    /**
     * 响应签名是否验签通过。
     *
     * @var bool
     */
    private bool $verified;

    /**
     * 构造方法。
     *
     * @param string $method 支付宝接口方法名
     * @param string $rawBody 原始响应体
     * @param array<string, mixed> $decoded 解码后的完整响应
     * @param bool $verified 是否验签通过
     */
    public function __construct(string $method, string $rawBody, array $decoded, bool $verified = false)
    {
        $this->method = $method;
        $this->rawBody = $rawBody;
        $this->decoded = $decoded;
        $this->verified = $verified;
    }

    /**
     * 获取接口方法名。
     *
     * @return string 接口方法名
     */
    public function method(): string
    {
        return $this->method;
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
     * 获取当前接口对应的响应节点名。
     *
     * @return string 响应节点名
     */
    public function responseKey(): string
    {
        return str_replace('.', '_', $this->method) . '_response';
    }

    /**
     * 获取业务响应节点。
     *
     * 如果支付宝返回 error_response，则优先返回错误节点，方便上层读取 code/sub_code。
     *
     * @return array<string, mixed> 业务响应数据
     */
    public function data(): array
    {
        $data = $this->decoded[$this->responseKey()] ?? $this->decoded['error_response'] ?? [];

        return is_array($data) ? $data : [];
    }

    /**
     * 获取完整解码响应。
     *
     * @return array<string, mixed> 完整响应
     */
    public function decoded(): array
    {
        return $this->decoded;
    }

    /**
     * 获取支付宝响应签名。
     *
     * @return string 响应签名
     */
    public function sign(): string
    {
        return (string) ($this->decoded['sign'] ?? '');
    }

    /**
     * 判断响应是否已验签通过。
     *
     * @return bool 是否验签通过
     */
    public function verified(): bool
    {
        return $this->verified;
    }

    /**
     * 判断支付宝业务响应是否成功。
     *
     * 支付宝 OpenAPI 业务成功 code 固定为 10000。
     *
     * @return bool 是否成功
     */
    public function success(): bool
    {
        return $this->code() === '10000';
    }

    /**
     * 获取支付宝公共响应 code。
     *
     * @return string code
     */
    public function code(): string
    {
        return (string) ($this->data()['code'] ?? '');
    }

    /**
     * 获取支付宝公共响应 msg。
     *
     * @return string msg
     */
    public function msg(): string
    {
        return (string) ($this->data()['msg'] ?? '');
    }

    /**
     * 获取支付宝业务错误码。
     *
     * @return string 业务错误码
     */
    public function subCode(): string
    {
        return (string) ($this->data()['sub_code'] ?? '');
    }

    /**
     * 获取支付宝业务错误说明。
     *
     * @return string 业务错误说明
     */
    public function subMsg(): string
    {
        return (string) ($this->data()['sub_msg'] ?? '');
    }

    /**
     * 转换为数组，便于插件保存 raw 或统一处理。
     *
     * @return array<string, mixed> 响应摘要
     */
    public function toArray(): array
    {
        return [
            'method' => $this->method,
            'response_key' => $this->responseKey(),
            'success' => $this->success(),
            'verified' => $this->verified,
            'code' => $this->code(),
            'msg' => $this->msg(),
            'sub_code' => $this->subCode(),
            'sub_msg' => $this->subMsg(),
            'data' => $this->data(),
            'raw' => $this->decoded,
        ];
    }
}
