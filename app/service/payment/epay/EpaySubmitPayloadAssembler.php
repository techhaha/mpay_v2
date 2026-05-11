<?php

namespace app\service\payment\epay;

use app\common\base\BaseService;
use app\common\constant\EpayProtocolConstant;
use support\Request;

/**
 * ePay 提交入参组装器。
 *
 * 只在 V1/V2 协议入口使用，把外部字段转换为支付发起服务需要的标准字段。
 */
class EpaySubmitPayloadAssembler extends BaseService
{
    /**
     * 组装标准订单字段。
     *
     * @param array $payload 协议请求参数
     * @param Request $request 请求对象
     * @param array<string, mixed> $seedExtJson 协议入口写入的扩展字段
     * @return array<string, mixed>
     */
    public function buildOrderFields(array $payload, Request $request, array $seedExtJson = []): array
    {
        $subject = trim((string) $payload['name']);

        $clientIp = trim((string) ($payload['clientip'] ?? ''));
        if ($clientIp === '') {
            $clientIp = trim((string) $request->getRealIp());
        }

        $device = trim((string) ($payload['device'] ?? ''));
        if ($device === '') {
            $device = EpayProtocolConstant::DEVICE_PC;
        }

        $extJson = $seedExtJson;
        $merchant = array_filter([
            'param' => $payload['param'] ?? null,
            'buyer' => $payload['buyer'] ?? null,
        ], static fn ($value) => $value !== null && $value !== '');

        if ($merchant !== []) {
            $extJson['merchant'] = $merchant;
        }

        $payment = array_filter([
            'method' => $payload['method'] ?? null,
            'auth_code' => $payload['auth_code'] ?? null,
            'sub_openid' => $payload['sub_openid'] ?? null,
            'sub_appid' => $payload['sub_appid'] ?? null,
        ], static fn ($value) => $value !== null && $value !== '');

        if ($payment !== []) {
            $extJson['payment'] = $payment;
        }

        return [
            'subject' => $subject,
            'body' => $subject,
            'notify_url' => trim((string) $payload['notify_url']),
            'return_url' => trim((string) ($payload['return_url'] ?? '')),
            'client_ip' => $clientIp,
            'device' => $device,
            'ext_json' => $extJson,
        ];
    }

    /**
     * 解析页面提交设备类型。
     *
     * 页面跳转提交通常不带 device，需要根据请求 UA 推断为协议支持的设备类型。
     *
     * @param array $payload 协议请求参数
     * @param Request $request 请求对象
     * @param array<int, string> $allowedDevices 协议支持设备列表
     * @return string
     */
    public function resolvePageSubmitDevice(array $payload, Request $request, array $allowedDevices): string
    {
        $device = strtolower(trim((string) ($payload['device'] ?? '')));
        if ($device !== '' && in_array($device, $allowedDevices, true)) {
            return $device;
        }

        $userAgent = strtolower(trim((string) $request->header('user-agent', '')));
        $device = EpayProtocolConstant::DEVICE_PC;

        if (str_contains($userAgent, 'alipayclient')) {
            $device = EpayProtocolConstant::DEVICE_ALIPAY;
        } elseif (str_contains($userAgent, 'micromessenger')) {
            $device = EpayProtocolConstant::DEVICE_WECHAT;
        } elseif (str_contains($userAgent, ' qq/') || str_contains($userAgent, 'mqqbrowser')) {
            $device = EpayProtocolConstant::DEVICE_QQ;
        } elseif (preg_match('/mobile|android|iphone|ipad|ipod|windows phone/i', $userAgent) === 1) {
            $device = EpayProtocolConstant::DEVICE_MOBILE;
        }

        return in_array($device, $allowedDevices, true) ? $device : EpayProtocolConstant::DEVICE_PC;
    }
}
