<?php

namespace app\service\payment\order;

use app\common\base\BaseService;
use app\model\payment\BizOrder;
use support\Request;

/**
 * 支付单入参组装器。
 *
 * 统一处理支付相关请求中的订单展示字段、客户端上下文和扩展参数。
 */
class PaymentOrderInputAssembler extends BaseService
{
    /**
     * 组装统一订单字段。
     *
     * @param array $payload 原始入参
     * @param Request|null $request 请求对象
     * @param BizOrder|null $bizOrder 业务单
     * @param array<string, mixed> $seedExtJson 需要合并到扩展参数中的种子数据
     * @return array<string, mixed>
     */
    public function buildOrderFields(array $payload, ?Request $request = null, ?BizOrder $bizOrder = null, array $seedExtJson = []): array
    {
        // 商品标题优先用显式入参，缺失时回退到业务单快照，保证收银台恢复时展示一致。
        $subject = trim((string) ($payload['subject'] ?? $payload['name'] ?? ($bizOrder?->subject ?? '')));
        // 商品描述尽量沿用同一份展示文案，避免不同入口出现两套口径。
        $body = trim((string) ($payload['body'] ?? $payload['subject'] ?? $payload['name'] ?? ($bizOrder?->body ?? '')));
        if ($body === '') {
            $body = $subject;
        }

        return [
            'subject' => $subject,
            'body' => $body !== '' ? $body : $subject,
            'notify_url' => trim((string) ($payload['notify_url'] ?? ($bizOrder?->notify_url ?? ''))),
            'return_url' => trim((string) ($payload['return_url'] ?? ($bizOrder?->return_url ?? ''))),
            'client_ip' => $this->resolveClientIp($payload, $request, $bizOrder),
            'device' => $this->resolveDevice($payload, $bizOrder),
            'ext_json' => $this->buildExtJson($payload, $seedExtJson),
        ];
    }

    /**
     * 组装扩展参数。
     *
     * 扩展参数按职责分区：
     * - 顶层 `_protocol_version` 等强语义字段用于后台筛选和排障。
     * - `merchant` 只放商户透传字段，后续会参与商户通知回传。
     * - `payment` 只放本次支付载体需要的上下文，例如 JSAPI openid 或付款码。
     *
     * @param array $payload 原始入参
     * @param array<string, mixed> $seedExtJson 需要保留的扩展参数
     * @return array<string, mixed>
     */
    public function buildExtJson(array $payload, array $seedExtJson = []): array
    {
        $extJson = $seedExtJson;

        $merchant = array_filter([
            'param' => $payload['param'] ?? null,
            'buyer' => $payload['buyer'] ?? null,
        ], static fn ($value) => $value !== null && $value !== '');

        if ($merchant !== []) {
            $extJson['merchant'] = array_replace((array) ($extJson['merchant'] ?? []), $merchant);
        }

        $payment = array_filter([
            'method' => $payload['method'] ?? null,
            'auth_code' => $payload['auth_code'] ?? null,
            'sub_openid' => $payload['sub_openid'] ?? null,
            'sub_appid' => $payload['sub_appid'] ?? null,
        ], static fn ($value) => $value !== null && $value !== '');

        if ($payment !== []) {
            $extJson['payment'] = array_replace((array) ($extJson['payment'] ?? []), $payment);
        }

        return $extJson;
    }

    /**
     * 解析客户端 IP。
     *
     * @param array $payload 原始入参
     * @param Request|null $request 请求对象
     * @param BizOrder|null $bizOrder 业务单
     * @return string
     */
    public function resolveClientIp(array $payload, ?Request $request = null, ?BizOrder $bizOrder = null): string
    {
        // 显式传入的 clientip / client_ip 优先级最高，兼容不同协议字段名。
        $clientIp = trim((string) ($payload['clientip'] ?? ''));
        if ($clientIp !== '') {
            return $clientIp;
        }

        $clientIp = trim((string) ($payload['client_ip'] ?? ''));
        if ($clientIp !== '') {
            return $clientIp;
        }

        if ($bizOrder && trim((string) ($bizOrder->client_ip ?? '')) !== '') {
            return trim((string) $bizOrder->client_ip);
        }

        // 最后才回退到请求源 IP，避免把代理层或网关层地址误当成业务上下文。
        if ($request) {
            return trim((string) $request->getRealIp());
        }

        return '';
    }

    /**
     * 解析设备类型。
     *
     * @param array $payload 原始入参
     * @param BizOrder|null $bizOrder 业务单
     * @param string $default 默认设备类型
     * @return string
     */
    public function resolveDevice(array $payload, ?BizOrder $bizOrder = null, string $default = 'pc'): string
    {
        // 设备类型先取请求参数，再用业务单快照兜底，最后才回落默认值。
        $device = trim((string) ($payload['device'] ?? ''));
        if ($device !== '') {
            return $device;
        }

        if ($bizOrder && trim((string) ($bizOrder->device ?? '')) !== '') {
            return trim((string) $bizOrder->device);
        }

        return $default;
    }
}
