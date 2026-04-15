<?php

namespace app\command;

use app\common\constant\RouteConstant;
use app\common\constant\TradeConstant;
use app\common\util\FormatHelper;
use app\http\api\controller\adapter\EpayController;
use app\model\merchant\Merchant;
use app\model\merchant\MerchantApiCredential;
use app\model\payment\PaymentChannel;
use app\model\payment\PaymentPollGroup;
use app\model\payment\PaymentType;
use app\repository\merchant\base\MerchantRepository;
use app\repository\merchant\credential\MerchantApiCredentialRepository;
use app\repository\payment\config\PaymentChannelRepository;
use app\repository\payment\config\PaymentPollGroupBindRepository;
use app\repository\payment\config\PaymentPollGroupChannelRepository;
use app\repository\payment\config\PaymentPollGroupRepository;
use app\repository\payment\config\PaymentTypeRepository;
use app\repository\payment\trade\BizOrderRepository;
use app\repository\payment\trade\PayOrderRepository;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use support\Request;

#[AsCommand('epay:mapi', '运行 ePay mapi 兼容接口烟雾测试')]
class EpayMapiTest extends Command
{
    protected function configure(): void
    {
        $this
            ->setDescription('自动读取真实商户、路由和插件配置，测试 ePay mapi 是否正常调用并返回结果。')
            ->addOption('live', null, InputOption::VALUE_NONE, '使用真实数据库并发起实际 mapi 调用')
            ->addOption('merchant-id', null, InputOption::VALUE_OPTIONAL, '指定商户 ID')
            ->addOption('merchant-no', null, InputOption::VALUE_OPTIONAL, '指定商户号')
            ->addOption('type', null, InputOption::VALUE_OPTIONAL, '支付方式编码，默认 alipay', 'alipay')
            ->addOption('money', null, InputOption::VALUE_OPTIONAL, '支付金额，单位元，默认 1.00', '1.00')
            ->addOption('device', null, InputOption::VALUE_OPTIONAL, '设备类型，默认 pc', 'pc')
            ->addOption('out-trade-no', null, InputOption::VALUE_OPTIONAL, '商户订单号，默认自动生成');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<info>epay mapi 烟雾测试</info>');

        if (!$this->optionBool($input, 'live', false)) {
            $this->ensureDependencies();
            $output->writeln('<info>[通过]</info> 依赖检查正常，使用 --live 才会真正发起 mapi 请求。');

            return self::SUCCESS;
        }

        try {
            $typeCode = trim($this->optionString($input, 'type', 'alipay'));
            $money = $this->normalizeMoney($this->optionString($input, 'money', '1.00'));
            $device = $this->normalizeDevice($this->optionString($input, 'device', 'pc'));
            $merchantIdOption = $this->optionInt($input, 'merchant-id', 0);
            $merchantNoOption = trim($this->optionString($input, 'merchant-no', ''));
            $outTradeNo = $this->buildMerchantOrderNo(trim($this->optionString($input, 'out-trade-no', '')));

            $context = $this->discoverContext($merchantIdOption, $merchantNoOption, $typeCode);
            $merchant = $context['merchant'];
            $credential = $context['credential'];
            $paymentType = $context['payment_type'];
            $route = $context['route'];
            $siteUrl = $this->resolveSiteUrl();

            $output->writeln(sprintf(
                '商户: id=%d no=%s name=%s group_id=%d',
                (int) $merchant->id,
                (string) $merchant->merchant_no,
                (string) $merchant->merchant_name,
                (int) $merchant->group_id
            ));
            $output->writeln(sprintf(
                '接口凭证: %s',
                FormatHelper::maskCredentialValue((string) $credential->api_key)
            ));
            $output->writeln(sprintf(
                '支付方式: %s(%d)  金额: %s  设备: %s',
                (string) $paymentType->code,
                (int) $paymentType->id,
                $money,
                $device
            ));
            $this->writeRouteSnapshot($output, $route);

            $payload = $this->buildPayload(
                merchant: $merchant,
                credential: $credential,
                paymentType: $paymentType,
                merchantOrderNo: $outTradeNo,
                money: $money,
                device: $device,
                siteUrl: $siteUrl
            );
            $controller = $this->resolve(EpayController::class);
            $response = $controller->mapi($this->buildRequest($payload));
            $responseData = $this->decodeResponse($response->rawBody());
            $orderSnapshot = $this->loadOrderSnapshot((int) $merchant->id, $outTradeNo);

            $this->writeAttempt($output, $payload, $responseData, $orderSnapshot);

            $status = $this->classifyAttempt($responseData, $orderSnapshot);
            return $status === 'pass' ? self::SUCCESS : self::FAILURE;
        } catch (\Throwable $e) {
            $output->writeln('<error>[失败]</error> ' . $this->formatThrowable($e));

            return self::FAILURE;
        }
    }

    private function ensureDependencies(): void
    {
        $this->resolve(EpayController::class);
        $this->resolve(MerchantRepository::class);
        $this->resolve(MerchantApiCredentialRepository::class);
        $this->resolve(PaymentTypeRepository::class);
        $this->resolve(PaymentPollGroupBindRepository::class);
        $this->resolve(PaymentPollGroupRepository::class);
        $this->resolve(PaymentPollGroupChannelRepository::class);
        $this->resolve(PaymentChannelRepository::class);
        $this->resolve(BizOrderRepository::class);
        $this->resolve(PayOrderRepository::class);
    }

    /**
     * @return array{merchant:Merchant,credential:MerchantApiCredential,payment_type:PaymentType,route:array}
     */
    private function discoverContext(int $merchantIdOption, string $merchantNoOption, string $typeCode): array
    {
        /** @var PaymentTypeRepository $paymentTypeRepository */
        $paymentTypeRepository = $this->resolve(PaymentTypeRepository::class);
        $paymentType = $paymentTypeRepository->findByCode($typeCode);
        if (!$paymentType || (int) $paymentType->status !== 1) {
            throw new RuntimeException('未找到可用的支付方式: ' . $typeCode);
        }

        $merchant = $this->pickMerchant($merchantIdOption, $merchantNoOption);
        $credential = $this->findMerchantCredential((int) $merchant->id);
        if (!$credential) {
            throw new RuntimeException('商户未开通有效接口凭证: ' . $merchant->merchant_no);
        }

        $route = $this->buildRouteSnapshot((int) $merchant->group_id, (int) $paymentType->id);
        if ($route === null) {
            throw new RuntimeException('商户未配置可用路由: ' . $merchant->merchant_no);
        }

        return [
            'merchant' => $merchant,
            'credential' => $credential,
            'payment_type' => $paymentType,
            'route' => $route,
        ];
    }

    private function pickMerchant(int $merchantIdOption, string $merchantNoOption): Merchant
    {
        /** @var MerchantRepository $merchantRepository */
        $merchantRepository = $this->resolve(MerchantRepository::class);

        if ($merchantIdOption > 0) {
            $merchant = $merchantRepository->find($merchantIdOption);
            if (!$merchant || (int) $merchant->status !== 1) {
                throw new RuntimeException('指定商户不存在或未启用: ' . $merchantIdOption);
            }

            if ($merchantNoOption !== '' && (string) $merchant->merchant_no !== $merchantNoOption) {
                throw new RuntimeException('merchant-id 和 merchant-no 不匹配。');
            }

            return $merchant;
        }

        if ($merchantNoOption !== '') {
            $merchant = $merchantRepository->findByMerchantNo($merchantNoOption);
            if (!$merchant || (int) $merchant->status !== 1) {
                throw new RuntimeException('指定商户不存在或未启用: ' . $merchantNoOption);
            }

            return $merchant;
        }

        $merchant = $merchantRepository->enabledList(['id', 'merchant_no', 'merchant_name', 'group_id', 'status'])->first();
        if (!$merchant) {
            throw new RuntimeException('未找到启用中的真实商户。');
        }

        return $merchant;
    }

    private function findMerchantCredential(int $merchantId): ?MerchantApiCredential
    {
        /** @var MerchantApiCredentialRepository $repository */
        $repository = $this->resolve(MerchantApiCredentialRepository::class);
        $credential = $repository->findByMerchantId($merchantId);
        if (!$credential || (int) $credential->status !== 1) {
            return null;
        }

        return $credential;
    }

    /**
     * @return array{bind:mixed,poll_group:PaymentPollGroup,candidates:array<int,array<string,mixed>>}|null
     */
    private function buildRouteSnapshot(int $merchantGroupId, int $payTypeId): ?array
    {
        /** @var PaymentPollGroupBindRepository $bindRepository */
        $bindRepository = $this->resolve(PaymentPollGroupBindRepository::class);
        /** @var PaymentPollGroupRepository $pollGroupRepository */
        $pollGroupRepository = $this->resolve(PaymentPollGroupRepository::class);
        /** @var PaymentPollGroupChannelRepository $pollGroupChannelRepository */
        $pollGroupChannelRepository = $this->resolve(PaymentPollGroupChannelRepository::class);
        /** @var PaymentChannelRepository $channelRepository */
        $channelRepository = $this->resolve(PaymentChannelRepository::class);

        $bind = $bindRepository->findActiveByMerchantGroupAndPayType($merchantGroupId, $payTypeId);
        if (!$bind) {
            return null;
        }

        $pollGroup = $pollGroupRepository->find((int) $bind->poll_group_id);
        if (!$pollGroup || (int) $pollGroup->status !== 1) {
            return null;
        }

        $candidateRows = $pollGroupChannelRepository->listByPollGroupId((int) $pollGroup->id);
        if ($candidateRows->isEmpty()) {
            return null;
        }

        $channelIds = $candidateRows->pluck('channel_id')->all();
        $channels = $channelRepository->query()
            ->whereIn('id', $channelIds)
            ->where('status', 1)
            ->get()
            ->keyBy('id');

        $candidates = [];
        foreach ($candidateRows as $row) {
            $channel = $channels->get((int) $row->channel_id);
            if (!$channel) {
                continue;
            }

            if ((int) $channel->pay_type_id !== $payTypeId) {
                continue;
            }

            $candidates[] = [
                'channel' => $channel,
                'poll_group_channel' => $row,
            ];
        }

        if ($candidates === []) {
            return null;
        }

        return [
            'bind' => $bind,
            'poll_group' => $pollGroup,
            'candidates' => $candidates,
        ];
    }

    private function buildPayload(
        Merchant $merchant,
        MerchantApiCredential $credential,
        PaymentType $paymentType,
        string $merchantOrderNo,
        string $money,
        string $device,
        string $siteUrl
    ): array {
        $siteUrl = rtrim($siteUrl, '/');
        $payload = [
            'pid' => (int) $merchant->id,
            'key' => (string) $credential->api_key,
            'type' => (string) $paymentType->code,
            'out_trade_no' => $merchantOrderNo,
            'notify_url' => $siteUrl . '/epay/mapi/notify',
            'return_url' => $siteUrl . '/epay/mapi/return',
            'name' => trim(sprintf('mpay epay mapi smoke %s', (string) $merchant->merchant_name)),
            'money' => $money,
            'clientip' => '127.0.0.1',
            'device' => $device,
            'sign_type' => 'MD5',
        ];
        $payload['sign'] = $this->signPayload($payload, (string) $credential->api_key);

        return $payload;
    }

    private function classifyAttempt(array $responseData, array $orderSnapshot): string
    {
        $responseCode = (int) ($responseData['code'] ?? 0);
        $payOrder = $orderSnapshot['pay_order'] ?? null;
        $bizOrder = $orderSnapshot['biz_order'] ?? null;

        if ($responseCode !== 1) {
            return $payOrder ? 'fail' : 'skip';
        }

        return ($payOrder && $bizOrder) ? 'pass' : 'fail';
    }

    private function writeRouteSnapshot(OutputInterface $output, array $route): void
    {
        /** @var PaymentPollGroup $pollGroup */
        $pollGroup = $route['poll_group'];
        $candidates = $route['candidates'];

        $output->writeln(sprintf(
            '路由: group_id=%d group_name=%s mode=%s',
            (int) $pollGroup->id,
            (string) $pollGroup->group_name,
            $this->routeModeLabel((int) $pollGroup->route_mode)
        ));
        $output->writeln(sprintf('  候选通道: %d 个', count($candidates)));
        foreach ($candidates as $item) {
            /** @var PaymentChannel $channel */
            $channel = $item['channel'];
            $pollGroupChannel = $item['poll_group_channel'];
            $output->writeln(sprintf(
                '  - channel_id=%d name=%s default=%s sort_no=%d weight=%d mode=%s pay_type_id=%d plugin=%s',
                (int) $channel->id,
                (string) $channel->name,
                (int) $pollGroupChannel->is_default === 1 ? 'yes' : 'no',
                (int) $pollGroupChannel->sort_no,
                (int) $pollGroupChannel->weight,
                $this->channelModeLabel((int) $channel->channel_mode),
                (int) $channel->pay_type_id,
                (string) $channel->plugin_code
            ));
        }
    }

    private function writeAttempt(OutputInterface $output, array $payload, array $responseData, array $orderSnapshot): void
    {
        $status = $this->classifyAttempt($responseData, $orderSnapshot);
        $label = match ($status) {
            'pass' => '<info>[通过]</info>',
            'skip' => '<comment>[跳过]</comment>',
            default => '<error>[失败]</error>',
        };
        $payOrder = $orderSnapshot['pay_order'] ?? [];
        $bizOrder = $orderSnapshot['biz_order'] ?? [];
        $channel = $orderSnapshot['channel'] ?? [];
        $paymentType = $orderSnapshot['payment_type'] ?? [];

        $output->writeln(sprintf('%s mapi - out_trade_no=%s', $label, $payload['out_trade_no']));
        $output->writeln(sprintf(
            '  请求: pid=%d type=%s money=%s device=%s clientip=%s',
            (int) $payload['pid'],
            (string) $payload['type'],
            (string) $payload['money'],
            (string) $payload['device'],
            (string) $payload['clientip']
        ));
        $output->writeln(sprintf(
            '  响应: code=%s msg=%s',
            (string) ($responseData['code'] ?? ''),
            (string) ($responseData['msg'] ?? '')
        ));
        foreach (['trade_no', 'payurl', 'origin_payurl', 'qrcode', 'urlscheme'] as $key) {
            if (!isset($responseData[$key]) || $responseData[$key] === '') {
                continue;
            }
            $output->writeln(sprintf('  返回: %s=%s', $key, $this->stringifyValue($responseData[$key])));
        }

        if (!$bizOrder || !$payOrder) {
            $output->writeln('  订单: 未创建或未查到业务单/支付单');
            return;
        }

        $output->writeln(sprintf(
            '  业务单: biz_no=%s status=%s active_pay_no=%s attempt_count=%d',
            (string) ($bizOrder['biz_no'] ?? ''),
            $this->orderStatusLabel((int) ($bizOrder['status'] ?? 0)),
            (string) ($bizOrder['active_pay_no'] ?? ''),
            (int) ($bizOrder['attempt_count'] ?? 0)
        ));
        $output->writeln(sprintf(
            '  支付单: pay_no=%s status=%s channel_id=%d channel=%s plugin=%s pay_type=%s',
            (string) ($payOrder['pay_no'] ?? ''),
            $this->orderStatusLabel((int) ($payOrder['status'] ?? 0)),
            (int) ($payOrder['channel_id'] ?? 0),
            (string) ($channel['name'] ?? ''),
            (string) ($payOrder['plugin_code'] ?? ''),
            (string) ($paymentType['code'] ?? '')
        ));
        $output->writeln(sprintf(
            '  支付单状态: channel_request_no=%s channel_order_no=%s channel_trade_no=%s',
            (string) ($payOrder['channel_request_no'] ?? ''),
            (string) ($payOrder['channel_order_no'] ?? ''),
            (string) ($payOrder['channel_trade_no'] ?? '')
        ));
        $output->writeln(sprintf(
            '  失败信息: code=%s msg=%s',
            (string) ($payOrder['channel_error_code'] ?? ''),
            (string) ($payOrder['channel_error_msg'] ?? '')
        ));

        $extJson = (array) ($payOrder['ext_json'] ?? []);
        $summary = $this->summarizePayParamsSnapshot((array) ($extJson['pay_params_snapshot'] ?? []));
        if ($summary !== []) {
            $output->writeln('  插件返回:');
            $output->writeln('    ' . $this->formatJson($summary));
        }
    }

    private function summarizePayParamsSnapshot(array $snapshot): array
    {
        if ($snapshot === []) {
            return [];
        }

        $summary = ['type' => (string) ($snapshot['type'] ?? '')];
        if (isset($snapshot['pay_product'])) {
            $summary['pay_product'] = (string) $snapshot['pay_product'];
        }
        if (isset($snapshot['pay_action'])) {
            $summary['pay_action'] = (string) $snapshot['pay_action'];
        }

        switch ((string) ($snapshot['type'] ?? '')) {
            case 'form':
                $html = $this->stringifyValue($snapshot['html'] ?? '');
                $summary['html_length'] = strlen($html);
                $summary['html_head'] = $this->limitString($this->normalizeWhitespace($html), 160);
                break;
            case 'qrcode':
                $summary['qrcode_url'] = $this->stringifyValue($snapshot['qrcode_url'] ?? $snapshot['qrcode_data'] ?? '');
                break;
            case 'urlscheme':
                $summary['urlscheme'] = $this->stringifyValue($snapshot['urlscheme'] ?? $snapshot['order_str'] ?? '');
                break;
            case 'url':
                $summary['payurl'] = $this->stringifyValue($snapshot['payurl'] ?? '');
                $summary['origin_payurl'] = $this->stringifyValue($snapshot['origin_payurl'] ?? '');
                break;
            default:
                if (isset($snapshot['raw']) && is_array($snapshot['raw'])) {
                    $summary['raw_keys'] = array_values(array_map('strval', array_keys($snapshot['raw'])));
                }
                break;
        }

        return $summary;
    }

    private function routeModeLabel(int $routeMode): string
    {
        return RouteConstant::routeModeMap()[$routeMode] ?? '未知';
    }

    private function channelModeLabel(int $channelMode): string
    {
        return RouteConstant::channelModeMap()[$channelMode] ?? '未知';
    }

    private function orderStatusLabel(int $status): string
    {
        return TradeConstant::orderStatusMap()[$status] ?? '未知';
    }

    private function buildMerchantOrderNo(string $base): string
    {
        $base = trim($base);
        if ($base !== '') {
            return substr($base, 0, 64);
        }

        return 'EPAY-MAPI-' . FormatHelper::timestamp(time(), 'YmdHis') . random_int(1000, 9999);
    }

    private function signPayload(array $payload, string $key): string
    {
        $params = $payload;
        unset($params['sign'], $params['sign_type'], $params['key']);
        foreach ($params as $paramKey => $paramValue) {
            if ($paramValue === '' || $paramValue === null) {
                unset($params[$paramKey]);
            }
        }

        ksort($params);
        $query = [];
        foreach ($params as $paramKey => $paramValue) {
            $query[] = $paramKey . '=' . $paramValue;
        }

        return md5(implode('&', $query) . $key);
    }

    private function buildRequest(array $payload): Request
    {
        $body = http_build_query($payload, '', '&', PHP_QUERY_RFC1738);
        $siteUrl = $this->resolveSiteUrl();
        $host = parse_url($siteUrl, PHP_URL_HOST) ?: 'localhost';
        $port = parse_url($siteUrl, PHP_URL_PORT);
        $hostHeader = $port ? sprintf('%s:%s', $host, $port) : $host;

        $rawRequest = implode("\r\n", [
            'POST /mapi.php HTTP/1.1',
            'Host: ' . $hostHeader,
            'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
            'Content-Length: ' . strlen($body),
            'Connection: close',
            '',
            $body,
        ]);

        return new Request($rawRequest);
    }

    private function loadOrderSnapshot(int $merchantId, string $merchantOrderNo): array
    {
        /** @var BizOrderRepository $bizOrderRepository */
        $bizOrderRepository = $this->resolve(BizOrderRepository::class);
        /** @var PayOrderRepository $payOrderRepository */
        $payOrderRepository = $this->resolve(PayOrderRepository::class);
        /** @var PaymentChannelRepository $channelRepository */
        $channelRepository = $this->resolve(PaymentChannelRepository::class);
        /** @var PaymentTypeRepository $typeRepository */
        $typeRepository = $this->resolve(PaymentTypeRepository::class);

        $bizOrder = $bizOrderRepository->findByMerchantAndOrderNo($merchantId, $merchantOrderNo);
        $payOrder = $bizOrder ? $payOrderRepository->findLatestByBizNo((string) $bizOrder->biz_no) : null;
        $channel = $payOrder ? $channelRepository->find((int) $payOrder->channel_id) : null;
        $paymentType = $payOrder ? $typeRepository->find((int) $payOrder->pay_type_id) : null;

        return [
            'biz_order' => $bizOrder ? $bizOrder->toArray() : null,
            'pay_order' => $payOrder ? $payOrder->toArray() : null,
            'channel' => $channel ? $channel->toArray() : null,
            'payment_type' => $paymentType ? $paymentType->toArray() : null,
        ];
    }

    private function resolveSiteUrl(): string
    {
        $siteUrl = trim((string) sys_config('site_url'));
        return $siteUrl !== '' ? rtrim($siteUrl, '/') : 'http://localhost:8787';
    }

    private function normalizeMoney(string $money): string
    {
        $money = trim($money);
        if ($money === '') {
            return '1.00';
        }

        if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $money)) {
            throw new RuntimeException('money 参数不合法: ' . $money);
        }

        return number_format((float) $money, 2, '.', '');
    }

    private function normalizeDevice(string $device): string
    {
        $device = strtolower(trim($device));
        return $device !== '' ? $device : 'pc';
    }

    private function decodeResponse(string $body): array
    {
        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : ['raw' => $body];
    }

    private function stringifyValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_string($value)) {
            return trim($value);
        }
        if (is_int($value) || is_float($value) || is_bool($value)) {
            return (string) $value;
        }
        if (is_array($value) || is_object($value)) {
            $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return $json !== false ? $json : '';
        }

        return (string) $value;
    }

    private function limitString(string $value, int $length): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        return strlen($value) <= $length ? $value : substr($value, 0, $length) . '...';
    }

    private function normalizeWhitespace(string $value): string
    {
        return preg_replace('/\s+/', ' ', trim($value)) ?: '';
    }

    private function formatJson(mixed $value): string
    {
        return FormatHelper::json($value);
    }

    private function formatThrowable(\Throwable $e): string
    {
        $data = method_exists($e, 'getData') ? $e->getData() : [];
        $suffix = is_array($data) && $data !== [] ? ' ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';

        return $e::class . ': ' . $e->getMessage() . $suffix;
    }

    private function optionString(InputInterface $input, string $name, string $default = ''): string
    {
        $value = $input->getOption($name);
        return $value === null || $value === false ? $default : (is_string($value) ? $value : (string) $value);
    }

    private function optionInt(InputInterface $input, string $name, int $default = 0): int
    {
        $value = $input->getOption($name);
        return is_numeric($value) ? (int) $value : $default;
    }

    private function optionBool(InputInterface $input, string $name, bool $default = false): bool
    {
        $value = $input->getOption($name);
        if ($value === null || $value === '') {
            return $default;
        }

        $filtered = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

        return $filtered === null ? $default : $filtered;
    }

    private function resolve(string $class): object
    {
        try {
            $instance = container_make($class, []);
        } catch (\Throwable $e) {
            throw new RuntimeException("无法解析 {$class}: " . $e->getMessage(), 0, $e);
        }

        if (!is_object($instance)) {
            throw new RuntimeException("解析后的 {$class} 不是对象。");
        }

        return $instance;
    }
}
