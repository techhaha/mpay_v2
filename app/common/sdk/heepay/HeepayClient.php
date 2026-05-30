<?php

declare(strict_types=1);

namespace app\common\sdk\heepay;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use SimpleXMLElement;

/**
 * 汇付宝支付网关客户端。
 */
class HeepayClient
{
    private const PAY_URL = 'https://pay.Heepay.com/Payment/Index.aspx';
    private const REFUND_URL = 'https://pay.heepay.com/API/Payment/PaymentRefund.aspx';

    /**
     * SDK 配置。
     *
     * @var array<string, string>
     */
    private array $config;

    /**
     * HTTP 客户端。
     */
    private Client $httpClient;

    /**
     * 构造方法。
     *
     * @param array<string, string> $config SDK 配置
     */
    public function __construct(array $config)
    {
        $this->config = $config;
        $this->httpClient = new Client([
            'timeout' => 15,
            'connect_timeout' => 10,
            'http_errors' => false,
            'verify' => true,
        ]);
    }

    /**
     * 生成支付跳转地址。
     *
     * @param array<string, mixed> $payload 支付参数
     */
    public function payUrl(array $payload, bool $bank = false): string
    {
        $payload = $bank ? $this->bankPayload($payload) : $this->payPayload($payload);
        return self::PAY_URL . '?' . http_build_query($payload);
    }

    /**
     * 申请退款。
     *
     * @param array<string, mixed> $payload 退款参数
     * @return array<string, mixed>
     */
    public function refund(array $payload): array
    {
        if ((int) $payload['refund_amount'] === (int) $payload['amount']) {
            $params = [
                'version' => '1',
                'agent_id' => $this->config['agent_id'],
                'agent_bill_id' => (string) $payload['pay_no'],
                'notify_url' => (string) $payload['notify_url'],
                'sign_type' => 'MD5',
            ];
            $signContent = 'agent_bill_id=' . $params['agent_bill_id'] . '&agent_id=' . $params['agent_id'] . '&key=' . $this->config['refund_key'] . '&notify_url=' . $params['notify_url'] . '&version=' . $params['version'];
        } else {
            $refundDetails = $payload['pay_no'] . ',' . number_format(((int) $payload['refund_amount']) / 100, 2, '.', '') . ',' . $payload['refund_no'];
            $params = [
                'version' => '1',
                'agent_id' => $this->config['agent_id'],
                'refund_details' => $refundDetails,
                'notify_url' => (string) $payload['notify_url'],
                'sign_type' => 'MD5',
            ];
            $signContent = 'agent_id=' . $params['agent_id'] . '&key=' . $this->config['refund_key'] . '&notify_url=' . $params['notify_url'] . '&refund_details=' . $refundDetails . '&version=' . $params['version'];
        }
        $params['sign'] = md5(strtolower($signContent));

        try {
            $response = $this->httpClient->post(self::REFUND_URL, [
                'form_params' => $params,
            ]);
        } catch (GuzzleException $e) {
            throw new HeepaySdkException('汇付宝退款请求失败：' . $e->getMessage(), 0, $e);
        }

        $xml = mb_convert_encoding((string) $response->getBody(), 'UTF-8', 'GBK');
        $element = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NOCDATA);
        if (!$element instanceof SimpleXMLElement) {
            throw new HeepaySdkException('汇付宝退款响应解析失败');
        }

        return (array) json_decode(json_encode($element, JSON_UNESCAPED_UNICODE), true);
    }

    /**
     * 校验支付回调签名。
     *
     * @param array<string, mixed> $payload 回调参数
     */
    public function verifyNotify(array $payload): bool
    {
        $signContent = 'result=' . ($payload['result'] ?? '')
            . '&agent_id=' . ($payload['agent_id'] ?? '')
            . '&jnet_bill_no=' . ($payload['jnet_bill_no'] ?? '')
            . '&agent_bill_id=' . ($payload['agent_bill_id'] ?? '')
            . '&pay_type=' . ($payload['pay_type'] ?? '')
            . '&pay_amt=' . ($payload['pay_amt'] ?? '')
            . '&remark=' . ($payload['remark'] ?? '')
            . '&key=' . $this->config['pay_key'];

        return hash_equals(md5($signContent), (string) ($payload['sign'] ?? ''));
    }

    /**
     * 标准支付参数。
     *
     * @param array<string, mixed> $payload 原始参数
     * @return array<string, mixed>
     */
    private function payPayload(array $payload): array
    {
        $params = [
            'version' => '1',
            'pay_type' => (string) $payload['pay_type'],
            'agent_id' => $this->config['agent_id'],
            'agent_bill_id' => (string) $payload['pay_no'],
            'agent_bill_time' => date('YmdHis'),
            'pay_amt' => number_format(((int) $payload['amount']) / 100, 2, '.', ''),
            'notify_url' => (string) $payload['notify_url'],
            'return_url' => (string) $payload['return_url'],
            'user_ip' => str_replace('.', '_', (string) $payload['client_ip']),
            'goods_name' => mb_convert_encoding(mb_strcut((string) $payload['subject'], 0, 127, 'UTF-8'), 'GBK', 'UTF-8'),
            'sign_type' => 'MD5',
        ];
        if ($this->config['ref_agent_id'] !== '') {
            $params['ref_agent_id'] = $this->config['ref_agent_id'];
        }
        if ($this->config['bank_id'] !== '') {
            $params['bank_id'] = $this->pickBankId($this->config['bank_id']);
        }

        $signContent = 'version=' . $params['version']
            . '&agent_id=' . $params['agent_id']
            . '&agent_bill_id=' . $params['agent_bill_id']
            . '&agent_bill_time=' . $params['agent_bill_time']
            . '&pay_type=' . $params['pay_type']
            . '&pay_amt=' . $params['pay_amt']
            . '&notify_url=' . $params['notify_url']
            . '&return_url=' . $params['return_url']
            . '&user_ip=' . $params['user_ip']
            . '&key=' . $this->config['pay_key'];
        if (($params['ref_agent_id'] ?? '') !== '') {
            $signContent .= '&ref_agent_id=' . $params['ref_agent_id'];
        }
        $params['sign'] = md5($signContent);

        return $params;
    }

    /**
     * 网银支付参数。
     *
     * @param array<string, mixed> $payload 原始参数
     * @return array<string, mixed>
     */
    private function bankPayload(array $payload): array
    {
        $params = $this->payPayload($payload);
        $params['version'] = '3';
        $params['pay_type'] = '20';
        $params['pay_code'] = '0';
        $params['bank_card_type'] = '-1';
        $signContent = 'version=' . $params['version']
            . '&agent_id=' . $params['agent_id']
            . '&agent_bill_id=' . $params['agent_bill_id']
            . '&agent_bill_time=' . $params['agent_bill_time']
            . '&pay_type=' . $params['pay_type']
            . '&pay_amt=' . $params['pay_amt']
            . '&notify_url=' . $params['notify_url']
            . '&return_url=' . $params['return_url']
            . '&user_ip=' . $params['user_ip']
            . '&bank_card_type=' . $params['bank_card_type']
            . '&key=' . $this->config['pay_key'];
        if (($params['ref_agent_id'] ?? '') !== '') {
            $signContent .= '&ref_agent_id=' . $params['ref_agent_id'];
        }
        $params['sign'] = md5($signContent);

        return $params;
    }

    /**
     * 多上游商户号随机选择一个。
     */
    private function pickBankId(string $bankId): string
    {
        if (!str_contains($bankId, ',')) {
            return $bankId;
        }

        $items = array_values(array_filter(array_map('trim', explode(',', $bankId))));
        return $items[array_rand($items)] ?? '';
    }
}
