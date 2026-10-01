<?php

declare(strict_types=1);

namespace app\common\sdk\lakalajfy;

use app\exception\PaymentDefinitiveException;
use app\exception\PaymentUncertainException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;

/**
 * 封装缴费易收银台动态下单的 JSON 传输和结果解析。
 *
 * 不使用商户工作台 token，也不查询流水；业务拒绝与请求结果不确定分别抛出异常。
 * 金额入参使用整数分，渠道报文使用精确的元金额字符串。
 */
class LakalaJfyClient
{
    private const PAY_URL = 'https://jfyconsole.lakala.com/order/api/cashier/pay';

    private Client $http;

    public function __construct(?Client $http = null)
    {
        $this->http = $http ?? new Client([
            'timeout' => 10,
            'connect_timeout' => 10,
            'http_errors' => false,
            'proxy' => '',
        ]);
    }

    /**
     * 创建动态收款码并提取唯一平台订单号。
     *
     * 请求不带凭证或伪造 IP 请求头；开启代理时必须提供合法代理地址。
     * 为适配未配置 CA 文件的服务器，缴费易下单固定关闭 Guzzle CA 校验，但支付地址
     * 仍只接受 HTTPS。
     * 响应未知、超时或缺少支付链接时不能自动重试，以免重复创建上游订单。
     *
     * @param array<string, mixed> $config 商户、收银台名称和代理配置
     * @param int $amount 实付金额，单位分
     * @param string $payNo 本地支付单号，完整写入渠道 remark，不作为当前匹配依据
     * @return array{pay_url: string, pay_order_no: string} 确定创建成功的支付载体
     * @throws PaymentDefinitiveException 本地参数不合法或渠道明确返回业务拒绝时抛出
     * @throws PaymentUncertainException 请求结果无法确认时抛出
     */
    public function createQrcode(array $config, int $amount, string $payNo): array
    {
        $options = [
            'json' => $this->buildPayload($config, $amount, $payNo),
            'verify' => false,
            'http_errors' => false,
            'proxy' => '',
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json;charset=utf-8',
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/86.0.4240.198 Safari/537.36',
            ],
        ];
        $proxy = $this->proxyUrl($config);
        if ($proxy !== '') {
            $options['proxy'] = $proxy;
        }

        try {
            $response = $this->http->request('POST', self::PAY_URL, $options);
        } catch (GuzzleException $e) {
            throw new PaymentUncertainException('缴费易下单请求失败或超时，结果不确定', 40200);
        }

        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            throw new PaymentUncertainException('缴费易下单 HTTP 响应异常，结果不确定', 40200, [
                'http_status' => $response->getStatusCode(),
            ]);
        }
        try {
            $result = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new PaymentUncertainException('缴费易下单响应不是有效 JSON', 40200);
        }
        if (!is_array($result) || !isset($result['code']) || (!is_string($result['code']) && !is_int($result['code']))) {
            throw new PaymentUncertainException('缴费易下单响应缺少业务状态', 40200);
        }
        if ((string) $result['code'] !== '200') {
            throw new PaymentDefinitiveException('缴费易下单接口拒绝请求', 40200);
        }
        $payUrl = $result['data']['payUrl'] ?? null;
        if (!is_string($payUrl) || trim($payUrl) === '') {
            throw new PaymentUncertainException('缴费易下单成功响应缺少支付地址', 40200);
        }

        return ['pay_url' => $payUrl, 'pay_order_no' => $this->payOrderNo($payUrl)];
    }

    /**
     * 使用通用金额模板构造缴费易渠道报文。
     *
     * orderTemplateData 沿用原缴费易插件的固定 number 金额项，key、origin 和设置
     * 字段不接受配置覆盖；仅金额与收银台名称随本次请求变化。
     *
     * @param array<string, mixed> $config 商户 ID 和收银台名称
     * @param int $amount 金额，单位分
     * @param string $payNo 本地支付单号，原样作为渠道备注，不拼接业务文字
     * @return array<string, mixed> 渠道 JSON 请求体
     * @throws PaymentDefinitiveException 配置或金额无效时抛出，请求尚未发送
     */
    public function buildPayload(array $config, int $amount, string $payNo): array
    {
        $merchant = trim((string) ($config['jfy_merch_id'] ?? ''));
        $cashier = trim((string) ($config['jfy_cashier_name'] ?? ''));
        if ($amount <= 0 || $merchant === '' || $cashier === '') {
            throw new PaymentDefinitiveException('缴费易商户、收银台名称或金额不完整', 40200);
        }
        if ($payNo === '' || strlen($payNo) > 64 || preg_match('/[\x00-\x20\x7f]/', $payNo) === 1) {
            throw new PaymentDefinitiveException('缴费易下单本地支付单号无效', 40200);
        }
        $money = intdiv($amount, 100) . '.' . str_pad((string) ($amount % 100), 2, '0', STR_PAD_LEFT);
        $template = [
            'key' => 1747822239290,
            'type' => 'number',
            'index' => 0,
            'label' => '支付金额',
            'value' => $money,
            'origin' => 'number17478222392900',
            'options' => [
                'label' => '支付金额',
                'content' => $money,
                'required' => true,
                'labelAlign' => '',
            ],
            'displayName' => '金额类型',
            'formItemFlag' => false,
            'settingsTitle' => '金额类型设置',
            'marginLeftRight' => 10,
            'marginTopBottom' => 5,
            'cashierTemplateName' => $cashier,
            'state' => true,
        ];

        return [
            'merchId' => $merchant,
            'tradeAmount' => $money,
            'remark' => $payNo,
            'orderTemplateData' => [$template],
        ];
    }

    /**
     * 从真实支付链接解析唯一 payOrderNo，不扫描其他订单号字段。
     *
     * 支持整体编码的查询串；拒绝重复参数和数组参数，避免 parse_str 静默覆盖。
     *
     * @throws PaymentUncertainException 已收到下单响应但无法精确解析平台订单号时抛出
     */
    public function payOrderNo(string $payUrl): string
    {
        $parts = parse_url($payUrl);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
            throw new PaymentUncertainException('缴费易支付地址格式无效', 40200);
        }
        $query = (string) ($parts['query'] ?? '');
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $count = 0;
            foreach (explode('&', $query) as $pair) {
                $key = urldecode(explode('=', $pair, 2)[0]);
                if ($key === 'payOrderNo' || str_starts_with($key, 'payOrderNo[')) {
                    $count++;
                }
            }
            parse_str($query, $values);
            if ($count > 0) {
                $no = $values['payOrderNo'] ?? null;
                if ($count !== 1 || !is_string($no) || trim($no) === '' || strlen($no) > 64
                    || preg_match('/[\x00-\x20\x7f]/', $no) === 1
                ) {
                    throw new PaymentUncertainException('缴费易支付地址平台订单号不唯一或无效', 40200);
                }
                return $no;
            }
            $decoded = urldecode($query);
            if ($decoded === $query) {
                break;
            }
            $query = $decoded;
        }
        throw new PaymentUncertainException('缴费易支付地址缺少 payOrderNo', 40200);
    }

    /**
     * 读取明确启用的真实出口代理。
     *
     * 关闭时不解析旧代理值；开启但地址无效时拒绝发送请求，不静默使用本机出口。
     *
     * @param array<string, mixed> $config 标准布尔开关和代理地址
     * @throws PaymentDefinitiveException 开启代理但地址非法时抛出
     */
    public function proxyUrl(array $config): string
    {
        if (($config['proxy_enabled'] ?? false) !== true) {
            return '';
        }
        $proxy = trim((string) ($config['receipt_proxy_url'] ?? ''));
        $parts = parse_url($proxy);
        if (!is_array($parts) || empty($parts['host'])
            || !in_array($parts['scheme'] ?? '', ['http', 'https', 'socks5', 'socks5h'], true)
        ) {
            throw new PaymentDefinitiveException('缴费易已开启代理但代理地址无效', 40200);
        }
        return $proxy;
    }
}
