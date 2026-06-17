<?php

namespace app\command;

use app\common\payment\EpayV2Payment;
use app\exception\CommandException;
use app\service\payment\config\PaymentChannelTestService;
use GuzzleHttp\Client;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use support\Db;
use Throwable;

/**
 * ePay V2 外部通道页面支付联调命令。
 *
 * 覆盖 epay_v2 插件页面跳转支付：创建后台通道测试单、检查 POST 承接快照、
 * 可选真实提交上游页面支付并用插件查单验证响应签名。
 */
#[AsCommand('epay:v2-channel-test', '测试 epay_v2 外部通道页面支付签名链路')]
class EpayV2ChannelTest extends Command
{
    protected function configure(): void
    {
        $this
            ->setDescription('创建 epay_v2 通道测试单，验证页面支付 POST 承接和查单响应验签。')
            ->addOption('channel-id', null, InputOption::VALUE_OPTIONAL, '支付通道 ID', '8')
            ->addOption('money', null, InputOption::VALUE_OPTIONAL, '测试金额，单位元', '1.00')
            ->addOption('name', null, InputOption::VALUE_OPTIONAL, '商品名称', '支付测试')
            ->addOption('device', null, InputOption::VALUE_OPTIONAL, '设备类型', 'pc')
            ->addOption('submit-upstream', null, InputOption::VALUE_NONE, '真实 POST 提交到上游支付页并查单')
            ->addOption('query-wait', null, InputOption::VALUE_OPTIONAL, '提交上游后等待秒数再查单', '0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $channelId = max(1, (int) $this->optionString($input, 'channel-id', '8'));
        $submitUpstream = (bool) $input->getOption('submit-upstream');

        try {
            $result = $this->createChannelTestOrder($input, $channelId);
            $payNo = (string) ($result['pay_no'] ?? '');
            if ($payNo === '') {
                throw new CommandException('通道测试未返回支付单号');
            }

            $snapshot = $this->loadSnapshot($payNo);
            $presentation = (array) ($snapshot['presentation'] ?? []);
            $payParams = (array) ($presentation['pay_params'] ?? []);
            $method = strtolower(trim((string) ($payParams['method'] ?? '')));
            $action = trim((string) ($payParams['action'] ?? $payParams['url'] ?? ''));
            $payload = (array) ($payParams['payload'] ?? []);

            $output->writeln('<info>epay V2 通道支付测试</info>');
            $output->writeln('pay_no=' . $payNo);
            $output->writeln('payment_page_url=' . (string) ($result['payment_page_url'] ?? ''));
            $output->writeln('plugin_code=' . (string) ($snapshot['plugin_code'] ?? ''));
            $output->writeln('api_config_id=' . (string) ($snapshot['api_config_id'] ?? ''));
            $output->writeln('pay_page=' . (string) ($presentation['pay_page'] ?? ''));
            $output->writeln('method=' . $method);
            $output->writeln('action=' . $action);
            $output->writeln('payload_keys=' . implode(',', array_keys($payload)));
            $output->writeln('sign_type=' . (string) ($payload['sign_type'] ?? ''));
            $output->writeln('sign_len=' . strlen((string) ($payload['sign'] ?? '')));

            if ((string) ($snapshot['plugin_code'] ?? '') !== 'epay_v2') {
                throw new CommandException('当前通道不是 epay_v2');
            }
            if ($method !== 'post') {
                throw new CommandException('epay_v2 页面支付必须使用 POST 表单承接，当前 method=' . ($method ?: '<empty>'));
            }
            if ($action === '' || $payload === []) {
                throw new CommandException('POST 承接参数不完整');
            }

            if (!$submitUpstream) {
                $output->writeln('<comment>未传 --submit-upstream，仅完成本地承接快照检查。</comment>');
                $output->writeln('<info>[通过]</info> epay_v2 页面支付已生成 POST 承接参数。');

                return self::SUCCESS;
            }

            $submitResult = $this->submitUpstream($action, $payload);
            $output->writeln('upstream_status=' . $submitResult['status']);
            $output->writeln('upstream_location=' . $submitResult['location']);
            $output->writeln('upstream_text=' . $submitResult['text']);

            if (str_contains($submitResult['text'], 'RSA签名校验失败')) {
                throw new CommandException('上游页面支付仍提示 RSA签名校验失败');
            }

            $waitSeconds = max(0, (int) $this->optionString($input, 'query-wait', '0'));
            if ($waitSeconds > 0) {
                sleep($waitSeconds);
            }

            $queryResult = $this->queryUpstream($payNo, (array) ($snapshot['config'] ?? []));
            $rawData = (array) ($queryResult['raw_data'] ?? []);
            $output->writeln('query_success=' . (!empty($queryResult['success']) ? 'true' : 'false'));
            $output->writeln('query_msg=' . (string) ($queryResult['msg'] ?? $queryResult['message'] ?? ''));
            $output->writeln('query_status=' . (string) ($queryResult['status'] ?? ''));
            $output->writeln('query_channel_status=' . (string) ($queryResult['channel_status'] ?? ''));
            $output->writeln('query_channel_order_no=' . (string) ($queryResult['channel_order_no'] ?? ''));
            $output->writeln('query_raw_code=' . (string) ($rawData['code'] ?? ''));
            $output->writeln('query_raw_msg=' . (string) ($rawData['msg'] ?? ''));
            $output->writeln('query_raw_sign_type=' . (string) ($rawData['sign_type'] ?? ''));
            $output->writeln('query_raw_sign_len=' . strlen((string) ($rawData['sign'] ?? '')));

            if (empty($queryResult['success']) || (int) ($rawData['code'] ?? -1) !== 0) {
                throw new CommandException('上游查单未成功: ' . (string) ($rawData['msg'] ?? $queryResult['msg'] ?? 'unknown'));
            }

            $output->writeln('<info>[通过]</info> 上游页面支付未再出现 RSA 验签错误，查单响应验签通过。');

            return self::SUCCESS;
        } catch (Throwable $e) {
            $output->writeln('<error>[失败]</error> ' . $e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function createChannelTestOrder(InputInterface $input, int $channelId): array
    {
        /** @var PaymentChannelTestService $service */
        $service = container_get(PaymentChannelTestService::class);

        return $service->submit($channelId, [
            'money' => $this->optionString($input, 'money', '1.00'),
            'name' => $this->optionString($input, 'name', '支付测试'),
            'device' => $this->optionString($input, 'device', 'pc'),
            'client_ip' => '127.0.0.1',
            'user_agent' => 'Mozilla/5.0 MPAY epay:v2-channel-test',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function loadSnapshot(string $payNo): array
    {
        $row = Db::table('ma_pay_order as po')
            ->join('ma_payment_channel as ch', 'ch.id', '=', 'po.channel_id')
            ->join('ma_payment_plugin_conf as pc', 'pc.id', '=', 'ch.api_config_id')
            ->where('po.pay_no', $payNo)
            ->select([
                'po.plugin_code',
                'po.ext_json',
                'ch.api_config_id',
                'pc.config',
            ])
            ->first();

        if (!$row) {
            throw new CommandException('支付单不存在: ' . $payNo);
        }

        return [
            'plugin_code' => (string) ($row->plugin_code ?? ''),
            'api_config_id' => (int) ($row->api_config_id ?? 0),
            'presentation' => (array) ($this->decodeJson((string) ($row->ext_json ?? ''))['presentation'] ?? []),
            'config' => $this->decodeJson((string) ($row->config ?? '')),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{status: int, location: string, text: string}
     */
    private function submitUpstream(string $action, array $payload): array
    {
        $response = (new Client([
            'timeout' => 10,
            'connect_timeout' => 10,
            'http_errors' => false,
            'allow_redirects' => false,
        ]))->post($action, [
            'form_params' => $payload,
            'headers' => [
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'User-Agent' => 'Mozilla/5.0 MPAY epay:v2-channel-test',
            ],
        ]);

        return [
            'status' => $response->getStatusCode(),
            'location' => $response->getHeaderLine('Location'),
            'text' => mb_strcut($this->htmlText((string) $response->getBody()), 0, 300, 'UTF-8'),
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function queryUpstream(string $payNo, array $config): array
    {
        $payment = new EpayV2Payment();
        $payment->init($config);

        return $payment->query([
            'pay_no' => $payNo,
            'chan_order_no' => $payNo,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(string $json): array
    {
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function htmlText(string $html): string
    {
        $html = preg_replace('/<style\b[^>]*>.*?<\/style>/is', ' ', $html) ?? $html;
        $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', ' ', $html) ?? $html;
        $html = str_replace(['</p>', '</div>', '</h1>', '</h2>', '</h3>', '<br>', '<br/>', '<br />'], "\n", $html);
        $text = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return preg_replace('/[ \t\r\n]+/', ' ', $text) ?? $text;
    }

    private function optionString(InputInterface $input, string $name, string $default = ''): string
    {
        $value = $input->getOption($name);

        return $value === null ? $default : trim((string) $value);
    }
}
