<?php

declare(strict_types=1);

namespace app\common\sdk\wxpay;

/**
 * 微信支付 V2 XML 编解码工具。
 *
 * 微信支付 V2 接口使用 XML 作为请求和响应格式，字段值通常放在 CDATA 中。
 * 该工具只负责安全地在数组和 XML 字符串之间转换，不参与签名和业务校验。
 */
class WxpayXml
{
    /**
     * 将数组编码为微信支付 V2 XML。
     *
     * @param array<string, mixed> $data 待编码数据
     * @return string XML 字符串
     */
    public static function encode(array $data): string
    {
        $xml = '<xml>';
        foreach ($data as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            if (is_array($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }

            $xml .= sprintf(
                '<%1$s><![CDATA[%2$s]]></%1$s>',
                htmlspecialchars((string) $key, ENT_XML1 | ENT_QUOTES, 'UTF-8'),
                self::escapeCdata((string) $value)
            );
        }

        return $xml . '</xml>';
    }

    /**
     * 将微信支付 V2 XML 解码为数组。
     *
     * @param string $xml XML 字符串
     * @return array<string, string> 解码结果
     */
    public static function decode(string $xml): array
    {
        $xml = trim($xml);
        if ($xml === '') {
            return [];
        }

        $previous = libxml_use_internal_errors(true);
        $element = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($element === false) {
            $message = $errors[0]->message ?? '未知 XML 错误';
            throw new WxpaySdkException('微信支付 XML 解析失败：' . trim($message));
        }

        $decoded = json_decode(json_encode($element, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}', true);
        if (!is_array($decoded)) {
            throw new WxpaySdkException('微信支付 XML 转数组失败');
        }

        $result = [];
        foreach ($decoded as $key => $value) {
            if (is_array($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            $result[(string) $key] = (string) $value;
        }

        return $result;
    }

    /**
     * 转义 CDATA 结束标记，避免字段值破坏 XML 结构。
     *
     * @param string $value 原始字段值
     * @return string CDATA 安全字段值
     */
    private static function escapeCdata(string $value): string
    {
        return str_replace(']]>', ']]]]><![CDATA[>', $value);
    }
}
