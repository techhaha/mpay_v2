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

/**
 * ePay mapi 兼容层烟雾测试命令。
 *
 * 用于验证真实商户、路由、插件配置和 mapi 调用是否连通，并输出落库后的订单快照。
 */
#[AsCommand('epay:mapi', '运行 ePay mapi 兼容接口烟雾测试')]
class EpayMapiTest extends Command
{
    /**
     * 配置命令参数。
     *
     * @return void
     */
    protected function configure(): void
    {
        $this
            ->setDescription('自动读取真实商户、路由和插件配置，测试 ePay mapi 是否能正常调用并返回可用结果。')
            ->addOption('live', null, InputOption::VALUE_NONE, '使用真实数据库并发起实际 mapi 调用')
            ->addOption('merchant-id', null, InputOption::VALUE_OPTIONAL, '指定商户 ID')
            ->addOption('merchant-no', null, InputOption::VALUE_OPTIONAL, '指定商户号')
            ->addOption('type', null, InputOption::VALUE_OPTIONAL, '支付方式编码，默认 alipay', 'alipay')
            ->addOption('money', null, InputOption::VALUE_OPTIONAL, '支付金额，单位元，默认 1.00', '1.00')
            ->addOption('device', null, InputOption::VALUE_OPTIONAL, '设备类型，默认 pc', 'pc')
            ->addOption('out-trade-no', null, InputOption::VALUE_OPTIONAL, '商户订单号，默认自动生成');
    }

    /**
     * 执行 ePay mapi 烟雾测试。
     *
     * @param InputInterface $input 命令输入
     * @param OutputInterface $output 输出对象
     * @return int 命令退出码
     */
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
                $merchant,
                $credential,
                $paymentType,
                $outTradeNo,
                $money,
                $device,
                $siteUrl
            );
            /** @var EpayController $controller */
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

    /**
     * 确保烟雾测试依赖可解析。
     *
     * @return void
     */
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
     * 发现可用于测试的商户、凭证和路由上下文。
     *
     * @param int $merchantIdOption 商户 ID 选项
     * @param string $merchantNoOption 商户编号选项
     * @param string $typeCode 支付方式编码
     * @return array 上下文数据
     * @throws RuntimeException
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
            throw new RuntimeException('商户未开通有效 API 凭证: ' . $merchant->merchant_no);
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

    /**
     * 挑选可用商户。
     *
     * @param int $merchantIdOption 商户 ID 选项
     * @param string $merchantNoOption 商户编号选项
     * @return Merchant 商户记录
     * @throws RuntimeException
     */
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

    /**
     * 查询商户凭证
     *
     * @param int $merchantId 商户ID
     * @return MerchantApiCredential|null 商户 API 凭证
     */
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
     * 构建路由快照。
     *
     * @param int $merchantGroupId 商户分组ID
     * @param int $payTypeId 支付类型ID
     * @return array|null 路由快照
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

    /**
     * 构建 ePay mapi 请求载荷。
     *
     * @param Merchant $merchant 商户
     * @param MerchantApiCredential $credential 商户 API 凭证
     * @param PaymentType $paymentType 支付类型
     * @param string $merchantOrderNo 商户订单号
     * @param string $money 金额
     * @param string $device 设备类型
     * @param string $siteUrl 站点地址
     * @return array 请求载荷
     */
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

    /**
     * 根据响应和订单快照判定测试结果。
     *
     * @param array $responseData 响应数据
     * @param array $orderSnapshot 订单快照
     * @return string 判定结果
     */
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

    /**
     * 输出路由快照。
     *
     * @param OutputInterface $output 输出对象
     * @param array $route 路由快照
     * @return void
     */
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

    /**
     * 输出测试结果。
     *
     * @param OutputInterface $output 输出对象
     * @param array $payload 请求载荷
     * @param array $responseData 响应数据
     * @param array $orderSnapshot 订单快照
     * @return void
     */
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

    /**
     * 归纳支付参数快照。
     *
     * @param array $snapshot 支付参数快照
     * @return array 归纳结果
     */
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

    /**
     * 获取路由模式名称。
     *
     * @param int $routeMode 路由模式
     * @return string 路由模式名称
     */
    private function routeModeLabel(int $routeMode): string
    {
        return RouteConstant::routeModeMap()[$routeMode] ?? '未知';
    }

    /**
     * 获取通道模式名称。
     *
     * @param int $channelMode 通道模式
     * @return string 通道模式名称
     */
    private function channelModeLabel(int $channelMode): string
    {
        return RouteConstant::channelModeMap()[$channelMode] ?? '未知';
    }

    /**
     * 获取订单状态名称。
     *
     * @param int $status 状态
     * @return string 订单状态名称
     */
    private function orderStatusLabel(int $status): string
    {
        return TradeConstant::orderStatusMap()[$status] ?? '未知';
    }

    /**
     * 生成商户订单号。
     *
     * @param string $base 基础订单号
     * @return string 商户订单号
     */
    private function buildMerchantOrderNo(string $base): string
    {
        $base = trim($base);
        if ($base !== '') {
            return substr($base, 0, 64);
        }

        return 'EPAY-MAPI-' . FormatHelper::timestamp(time(), 'YmdHis') . random_int(1000, 9999);
    }

    /**
     * 对载荷进行 MD5 签名。
     *
     * @param array $payload 请求载荷
     * @param string $key 商户密钥
     * @return string 签名结果
     */
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

    /**
     * 构建模拟请求对象。
     *
     * @param array $payload 请求载荷
     * @return Request 请求对象
     */
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

    /**
     * 加载订单快照。
     *
     * @param int $merchantId 商户ID
     * @param string $merchantOrderNo 商户订单号
     * @return array 订单快照
     */
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

    /**
     * 解析站点地址。
     *
     * @return string 站点地址
     */
    private function resolveSiteUrl(): string
    {
        $siteUrl = trim((string) sys_config('site_url'));
        return $siteUrl !== '' ? rtrim($siteUrl, '/') : 'http://localhost:8787';
    }

    /**
     * 归一化金额字符串。
     *
     * @param string $money 金额
     * @return string 金额字符串
     * @throws RuntimeException
     */
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

    /**
     * 归一化设备类型。
     *
     * @param string $device 设备类型
     * @return string 设备类型
     */
    private function normalizeDevice(string $device): string
    {
        $device = strtolower(trim($device));
        return $device !== '' ? $device : 'pc';
    }

    /**
     * 解析响应体。
     *
     * @param string $body 响应体
     * @return array 解析结果
     */
    private function decodeResponse(string $body): array
    {
        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : ['raw' => $body];
    }

    /**
     * 将值转为字符串。
     *
     * @param mixed $value 可转为字符串的值
     * @return string 可展示字符串
     */
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

    /**
     * 限制字符串长度。
     *
     * @param string $value 待截断文本
     * @param int $length 最大长度
     * @return string 截断后的字符串
     */
    private function limitString(string $value, int $length): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        return strlen($value) <= $length ? $value : substr($value, 0, $length) . '...';
    }

    /**
     * 归一化空白字符。
     *
     * @param string $value 待归一化文本
     * @return string 归一化结果
     */
    private function normalizeWhitespace(string $value): string
    {
        return preg_replace('/\s+/', ' ', trim($value)) ?: '';
    }

    /**
     * 格式化 JSON。
     *
     * @param mixed $value 可编码为 JSON 的值
     * @return string JSON 文本
     */
    private function formatJson(mixed $value): string
    {
        return FormatHelper::json($value);
    }

    /**
     * 格式化异常文本。
     *
     * @param Throwable $e 异常
     * @return string 文本结果
     */
    private function formatThrowable(\Throwable $e): string
    {
        $data = method_exists($e, 'getData') ? $e->getData() : [];
        $suffix = is_array($data) && $data !== [] ? ' ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';

        return $e::class . ': ' . $e->getMessage() . $suffix;
    }

    /**
     * 读取字符串选项。
     *
     * @param InputInterface $input 命令输入
     * @param string $name 选项名称
     * @param string $default 默认值
     * @return string 选项值
     */
    private function optionString(InputInterface $input, string $name, string $default = ''): string
    {
        $value = $input->getOption($name);
        return $value === null || $value === false ? $default : (is_string($value) ? $value : (string) $value);
    }

    /**
     * 读取整数选项。
     *
     * @param InputInterface $input 命令输入
     * @param string $name 选项名称
     * @param int $default 默认值
     * @return int 选项值
     */
    private function optionInt(InputInterface $input, string $name, int $default = 0): int
    {
        $value = $input->getOption($name);
        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * 读取布尔选项。
     *
     * @param InputInterface $input 命令输入
     * @param string $name 选项名称
     * @param bool $default 默认值
     * @return bool 布尔值
     */
    private function optionBool(InputInterface $input, string $name, bool $default = false): bool
    {
        $value = $input->getOption($name);
        if ($value === null || $value === '') {
            return $default;
        }

        $filtered = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

        return $filtered === null ? $default : $filtered;
    }

    /**
     * 从容器中解析指定类实例。
     *
     * @param string $class 类名
     * @return object 对象实例
     * @throws RuntimeException
     */
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
