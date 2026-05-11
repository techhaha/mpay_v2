<?php

namespace app\command;

use app\common\constant\AuthConstant;
use app\common\constant\CommonConstant;
use app\common\constant\RouteConstant;
use app\common\constant\TradeConstant;
use app\common\util\FormatHelper;
use app\exception\CommandException;
use app\http\api\controller\epay\EpayV2Controller;
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
use app\service\payment\epay\EpaySignerManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use support\Request;

/**
 * ePay V2 核心 API 烟雾测试命令。
 *
 * 用于验证 V2 create/query/close/merchant-info/merchant-orders 的请求签名、
 * 响应签名和订单落库链路是否完整可用。
 */
#[AsCommand('epay:v2-api', '运行 ePay V2 核心 API 烟雾测试')]
class EpayV2ApiTest extends Command
{
    /**
     * 配置命令参数。
     *
     * @return void
     */
    protected function configure(): void
    {
        $this
            ->setDescription('自动读取真实商户、路由和 RSA 配置，测试 ePay V2 核心 API 是否能正常调用。')
            ->addOption('live', null, InputOption::VALUE_NONE, '使用真实数据库并发起实际 V2 API 调用')
            ->addOption('merchant-id', null, InputOption::VALUE_OPTIONAL, '指定商户 ID')
            ->addOption('merchant-no', null, InputOption::VALUE_OPTIONAL, '指定商户号')
            ->addOption('merchant-private-key', null, InputOption::VALUE_OPTIONAL, '商户 RSA 私钥内容')
            ->addOption('merchant-private-key-file', null, InputOption::VALUE_OPTIONAL, '商户 RSA 私钥文件路径')
            ->addOption('type', null, InputOption::VALUE_OPTIONAL, '支付方式编码，默认 alipay', 'alipay')
            ->addOption('method', null, InputOption::VALUE_OPTIONAL, 'V2 接口方式，默认 web', 'web')
            ->addOption('money', null, InputOption::VALUE_OPTIONAL, '支付金额，单位元，默认 1.00', '1.00')
            ->addOption('device', null, InputOption::VALUE_OPTIONAL, '设备类型，默认 pc', 'pc')
            ->addOption('out-trade-no', null, InputOption::VALUE_OPTIONAL, '商户订单号，默认自动生成')
            ->addOption('skip-close', null, InputOption::VALUE_NONE, '创建订单后跳过关单校验')
            ->addOption('refund-trade-no', null, InputOption::VALUE_OPTIONAL, '指定需要退款的 V2 平台订单号')
            ->addOption('refund-out-trade-no', null, InputOption::VALUE_OPTIONAL, '指定需要退款的商户订单号')
            ->addOption('refund-money', null, InputOption::VALUE_OPTIONAL, '退款金额，单位元')
            ->addOption('out-refund-no', null, InputOption::VALUE_OPTIONAL, '商户退款单号，默认自动生成');
    }

    /**
     * 执行 V2 核心 API 烟雾测试。
     *
     * @param InputInterface $input 命令输入
     * @param OutputInterface $output 输出对象
     * @return int 命令退出码
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<info>epay V2 核心 API 烟雾测试</info>');

        if (!$this->optionBool($input, 'live', false)) {
            $this->ensureDependencies();
            $output->writeln('<info>[通过]</info> 依赖检查正常，使用 --live 才会真正发起 V2 API 请求。');

            return self::SUCCESS;
        }

        try {
            $typeCode = trim($this->optionString($input, 'type', 'alipay'));
            $method = $this->normalizeMethod($this->optionString($input, 'method', 'web'));
            $money = $this->normalizeMoney($this->optionString($input, 'money', '1.00'));
            $device = $this->normalizeDevice($this->optionString($input, 'device', 'pc'));
            $merchantIdOption = $this->optionInt($input, 'merchant-id', 0);
            $merchantNoOption = trim($this->optionString($input, 'merchant-no', ''));
            $outTradeNo = $this->buildMerchantOrderNo(trim($this->optionString($input, 'out-trade-no', '')));
            $outRefundNo = $this->buildRefundNo(trim($this->optionString($input, 'out-refund-no', '')));
            $context = $this->discoverContext($merchantIdOption, $merchantNoOption, $typeCode);
            $merchant = $context['merchant'];
            $credential = $context['credential'];
            $paymentType = $context['payment_type'];
            $route = $context['route'];
            $merchantPrivateKey = $this->resolveMerchantPrivateKey($input, (int) $merchant->id);
            $platformPublicKey = $this->resolvePlatformPublicKey();
            $siteUrl = $this->resolveSiteUrl();

            $this->ensureKeyPairMatches($merchantPrivateKey, (string) ($credential->merchant_public_key ?? ''));

            $output->writeln(sprintf(
                '商户: id=%d no=%s name=%s group_id=%d',
                (int) $merchant->id,
                (string) $merchant->merchant_no,
                (string) $merchant->merchant_name,
                (int) $merchant->group_id
            ));
            $output->writeln(sprintf(
                'RSA: merchant_public_key=%s platform_public_key=%s',
                FormatHelper::maskCredentialValue((string) ($credential->merchant_public_key ?? ''), false),
                FormatHelper::maskCredentialValue($platformPublicKey, false)
            ));
            $output->writeln(sprintf(
                '支付方式: %s(%d)  金额: %s  设备: %s  method: %s',
                (string) $paymentType->code,
                (int) $paymentType->id,
                $money,
                $device,
                $method
            ));
            $this->writeRouteSnapshot($output, $route);

            $allPassed = true;
            $createPayload = $this->buildSignedPayload([
                'pid' => (int) $merchant->id,
                'type' => (string) $paymentType->code,
                'method' => $method,
                'out_trade_no' => $outTradeNo,
                'notify_url' => $siteUrl . '/epay/v2/notify',
                'return_url' => $siteUrl . '/epay/v2/return',
                'name' => trim(sprintf('mpay epay v2 api smoke %s', (string) $merchant->merchant_name)),
                'money' => $money,
                'clientip' => '127.0.0.1',
                'device' => $device,
                'param' => 'v2-smoke',
            ], $merchantPrivateKey);
            $createResponse = $this->callJsonEndpoint('create', $createPayload, '/api/pay/create');
            $createVerify = $this->verifySignedResponse($createResponse, $platformPublicKey);
            $orderSnapshot = $this->loadOrderSnapshot((int) $merchant->id, $outTradeNo);
            $createPassed = $this->classifyCreateAttempt($createResponse, $orderSnapshot, $createVerify['passed']);
            $allPassed = $allPassed && $createPassed;
            $this->writeCreateAttempt($output, $createPayload, $createResponse, $orderSnapshot, $createVerify, $createPassed);

            $tradeNo = (string) ($createResponse['trade_no'] ?? '');
            if ($createPassed && $tradeNo !== '') {
                $queryPayload = $this->buildSignedPayload([
                    'pid' => (int) $merchant->id,
                    'trade_no' => $tradeNo,
                ], $merchantPrivateKey);
                $queryResponse = $this->callJsonEndpoint('query', $queryPayload, '/api/pay/query');
                $queryVerify = $this->verifySignedResponse($queryResponse, $platformPublicKey);
                $queryPassed = $this->classifySimpleSuccess($queryResponse, $queryVerify['passed'], [
                    'trade_no' => $tradeNo,
                    'out_trade_no' => $outTradeNo,
                ]);
                $allPassed = $allPassed && $queryPassed;
                $this->writeSimpleAttempt($output, 'query', $queryPayload, $queryResponse, $queryVerify, $queryPassed, [
                    'trade_no',
                    'out_trade_no',
                    'status',
                    'type',
                    'money',
                ]);

                $merchantInfoPayload = $this->buildSignedPayload([
                    'pid' => (int) $merchant->id,
                ], $merchantPrivateKey);
                $merchantInfoResponse = $this->callJsonEndpoint('merchantInfo', $merchantInfoPayload, '/api/merchant/info');
                $merchantInfoVerify = $this->verifySignedResponse($merchantInfoResponse, $platformPublicKey);
                $merchantInfoPassed = $this->classifySimpleSuccess($merchantInfoResponse, $merchantInfoVerify['passed'], [
                    'pid' => (int) $merchant->id,
                ]);
                $allPassed = $allPassed && $merchantInfoPassed;
                $this->writeSimpleAttempt($output, 'merchantInfo', $merchantInfoPayload, $merchantInfoResponse, $merchantInfoVerify, $merchantInfoPassed, [
                    'pid',
                    'status',
                    'money',
                    'order_num',
                    'order_num_today',
                    'order_money_today',
                ]);

                $merchantOrdersPayload = $this->buildSignedPayload([
                    'pid' => (int) $merchant->id,
                    'offset' => 0,
                    'limit' => 5,
                    'status' => 0,
                ], $merchantPrivateKey);
                $merchantOrdersResponse = $this->callJsonEndpoint('merchantOrders', $merchantOrdersPayload, '/api/merchant/orders');
                $merchantOrdersVerify = $this->verifySignedResponse($merchantOrdersResponse, $platformPublicKey);
                $merchantOrdersPassed = (int) ($merchantOrdersResponse['code'] ?? -1) === 0
                    && $merchantOrdersVerify['passed']
                    && is_array($merchantOrdersResponse['data'] ?? null);
                $allPassed = $allPassed && $merchantOrdersPassed;
                $this->writeSimpleAttempt($output, 'merchantOrders', $merchantOrdersPayload, $merchantOrdersResponse, $merchantOrdersVerify, $merchantOrdersPassed, []);
                if (is_array($merchantOrdersResponse['data'] ?? null)) {
                    $output->writeln(sprintf('  订单条数: %d', count($merchantOrdersResponse['data'])));
                }

                if (!$this->optionBool($input, 'skip-close', false)) {
                    $closePayload = $this->buildSignedPayload([
                        'pid' => (int) $merchant->id,
                        'trade_no' => $tradeNo,
                    ], $merchantPrivateKey);
                    $closeResponse = $this->callJsonEndpoint('close', $closePayload, '/api/pay/close');
                    $closeVerify = $this->verifySignedResponse($closeResponse, $platformPublicKey);
                    $closePassed = $this->classifySimpleSuccess($closeResponse, $closeVerify['passed']);
                    $allPassed = $allPassed && $closePassed;
                    $this->writeSimpleAttempt($output, 'close', $closePayload, $closeResponse, $closeVerify, $closePassed, []);
                }
            }

            $refundTradeNo = trim($this->optionString($input, 'refund-trade-no', ''));
            $refundOutTradeNo = trim($this->optionString($input, 'refund-out-trade-no', ''));
            if ($refundTradeNo !== '' || $refundOutTradeNo !== '') {
                $refundMoney = $this->normalizeMoney($this->optionString($input, 'refund-money', $money));
                $refundPayload = [
                    'pid' => (int) $merchant->id,
                    'money' => $refundMoney,
                    'out_refund_no' => $outRefundNo,
                ];
                if ($refundTradeNo !== '') {
                    $refundPayload['trade_no'] = $refundTradeNo;
                }
                if ($refundOutTradeNo !== '') {
                    $refundPayload['out_trade_no'] = $refundOutTradeNo;
                }

                $signedRefundPayload = $this->buildSignedPayload($refundPayload, $merchantPrivateKey);
                $refundResponse = $this->callJsonEndpoint('refund', $signedRefundPayload, '/api/pay/refund');
                $refundVerify = $this->verifySignedResponse($refundResponse, $platformPublicKey);
                $refundPassed = $this->classifySimpleSuccess($refundResponse, $refundVerify['passed'], [
                    'out_refund_no' => $outRefundNo,
                ]);
                $allPassed = $allPassed && $refundPassed;
                $this->writeSimpleAttempt($output, 'refund', $signedRefundPayload, $refundResponse, $refundVerify, $refundPassed, [
                    'refund_no',
                    'out_refund_no',
                    'trade_no',
                    'money',
                    'reducemoney',
                ]);

                if ($refundPassed) {
                    $refundQueryPayload = $this->buildSignedPayload([
                        'pid' => (int) $merchant->id,
                        'out_refund_no' => $outRefundNo,
                    ], $merchantPrivateKey);
                    $refundQueryResponse = $this->callJsonEndpoint('refundQuery', $refundQueryPayload, '/api/pay/refundquery');
                    $refundQueryVerify = $this->verifySignedResponse($refundQueryResponse, $platformPublicKey);
                    $refundQueryPassed = $this->classifySimpleSuccess($refundQueryResponse, $refundQueryVerify['passed'], [
                        'out_refund_no' => $outRefundNo,
                    ]);
                    $allPassed = $allPassed && $refundQueryPassed;
                    $this->writeSimpleAttempt($output, 'refundQuery', $refundQueryPayload, $refundQueryResponse, $refundQueryVerify, $refundQueryPassed, [
                        'refund_no',
                        'out_refund_no',
                        'trade_no',
                        'status',
                        'money',
                        'reducemoney',
                    ]);
                }
            }

            return $allPassed ? self::SUCCESS : self::FAILURE;
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
        $this->resolve(EpayV2Controller::class);
        $this->resolve(EpaySignerManager::class);
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
     * @throws CommandException
     */
    private function discoverContext(int $merchantIdOption, string $merchantNoOption, string $typeCode): array
    {
        /** @var PaymentTypeRepository $paymentTypeRepository */
        $paymentTypeRepository = $this->resolve(PaymentTypeRepository::class);
        $paymentType = $paymentTypeRepository->findByCode($typeCode);
        if (!$paymentType || (int) $paymentType->status !== CommonConstant::STATUS_ENABLED) {
            throw new CommandException('未找到可用的支付方式: ' . $typeCode);
        }

        $merchant = $this->pickMerchant($merchantIdOption, $merchantNoOption);
        $credential = $this->findMerchantCredential((int) $merchant->id);
        if (!$credential) {
            throw new CommandException('商户未开通有效 API 凭证: ' . $merchant->merchant_no);
        }
        if (trim((string) ($credential->merchant_public_key ?? '')) === '') {
            throw new CommandException('商户未配置 RSA 公钥: ' . $merchant->merchant_no);
        }

        $route = $this->buildRouteSnapshot((int) $merchant->group_id, (int) $paymentType->id);
        if ($route === null) {
            throw new CommandException('商户未配置可用路由: ' . $merchant->merchant_no);
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
     * @throws CommandException
     */
    private function pickMerchant(int $merchantIdOption, string $merchantNoOption): Merchant
    {
        /** @var MerchantRepository $merchantRepository */
        $merchantRepository = $this->resolve(MerchantRepository::class);

        if ($merchantIdOption > 0) {
            $merchant = $merchantRepository->find($merchantIdOption);
            if (!$merchant || (int) $merchant->status !== CommonConstant::STATUS_ENABLED) {
                throw new CommandException('指定商户不存在或未启用: ' . $merchantIdOption);
            }

            if ($merchantNoOption !== '' && (string) $merchant->merchant_no !== $merchantNoOption) {
                throw new CommandException('商户ID与商户号不匹配。');
            }

            return $merchant;
        }

        if ($merchantNoOption !== '') {
            $merchant = $merchantRepository->findByMerchantNo($merchantNoOption);
            if (!$merchant || (int) $merchant->status !== CommonConstant::STATUS_ENABLED) {
                throw new CommandException('指定商户不存在或未启用: ' . $merchantNoOption);
            }

            return $merchant;
        }

        $merchant = $merchantRepository->enabledList(['id', 'merchant_no', 'merchant_name', 'group_id', 'status'])->first();
        if (!$merchant) {
            throw new CommandException('未找到启用中的真实商户。');
        }

        return $merchant;
    }

    /**
     * 查询商户凭证。
     *
     * @param int $merchantId 商户ID
     * @return MerchantApiCredential|null 商户 API 凭证
     */
    private function findMerchantCredential(int $merchantId): ?MerchantApiCredential
    {
        /** @var MerchantApiCredentialRepository $repository */
        $repository = $this->resolve(MerchantApiCredentialRepository::class);
        $credential = $repository->findByMerchantId($merchantId);
        if (!$credential || (int) $credential->status !== AuthConstant::CREDENTIAL_STATUS_ENABLED) {
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
        if (!$pollGroup || (int) $pollGroup->status !== CommonConstant::STATUS_ENABLED) {
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
     * 调用 V2 JSON 接口。
     *
     * @param string $action 控制器动作
     * @param array $payload 请求载荷
     * @param string $path 请求路径
     * @return array 响应数据
     */
    private function callJsonEndpoint(string $action, array $payload, string $path): array
    {
        /** @var EpayV2Controller $controller */
        $controller = $this->resolve(EpayV2Controller::class);
        $request = $this->buildRequest($payload, $path);
        $response = match ($action) {
            'create' => $controller->create($request),
            'query' => $controller->query($request),
            'refund' => $controller->refund($request),
            'refundQuery' => $controller->refundQuery($request),
            'close' => $controller->close($request),
            'merchantInfo' => $controller->merchantInfo($request),
            'merchantOrders' => $controller->merchantOrders($request),
            default => throw new CommandException('不支持的 V2 测试动作: ' . $action),
        };

        return $this->decodeResponse($response->rawBody());
    }

    /**
     * 构建签名后的请求载荷。
     *
     * @param array $payload 原始载荷
     * @param string $privateKey 商户私钥
     * @return array 签名后的载荷
     */
    private function buildSignedPayload(array $payload, string $privateKey): array
    {
        /** @var EpaySignerManager $signerManager */
        $signerManager = $this->resolve(EpaySignerManager::class);
        $payload['pid'] = (int) ($payload['pid'] ?? 0);
        $payload['timestamp'] = (string) time();
        $payload['sign_type'] = AuthConstant::API_SIGN_NAME_RSA;
        $payload['sign'] = $signerManager->sign($payload, AuthConstant::API_SIGN_NAME_RSA, $privateKey);

        return $payload;
    }

    /**
     * 校验响应签名和时间戳。
     *
     * @param array $responseData 响应数据
     * @param string $platformPublicKey 平台公钥
     * @return array{passed: bool, message: string}
     */
    private function verifySignedResponse(array $responseData, string $platformPublicKey): array
    {
        $sign = trim((string) ($responseData['sign'] ?? ''));
        if ($sign === '') {
            return ['passed' => false, 'message' => '响应缺少 sign'];
        }

        $timestamp = (int) ($responseData['timestamp'] ?? 0);
        if ($timestamp <= 0) {
            return ['passed' => false, 'message' => '响应缺少 timestamp'];
        }

        $ttl = (int) config('epay.v2.timestamp_ttl', 300);
        if (abs(time() - $timestamp) > $ttl) {
            return ['passed' => false, 'message' => '响应 timestamp 超出校验窗口'];
        }

        $signType = (string) ($responseData['sign_type'] ?? '');
        if ($signType === '') {
            return ['passed' => false, 'message' => '响应缺少 sign_type'];
        }

        /** @var EpaySignerManager $signerManager */
        $signerManager = $this->resolve(EpaySignerManager::class);
        $verifyPayload = $responseData;
        unset($verifyPayload['sign'], $verifyPayload['sign_type']);

        $passed = $signerManager->verify($verifyPayload, $signType, $sign, $platformPublicKey);

        return [
            'passed' => $passed,
            'message' => $passed ? '验签通过' : '响应验签失败',
        ];
    }

    /**
     * 判定创建订单是否成功。
     *
     * @param array $responseData 响应数据
     * @param array $orderSnapshot 订单快照
     * @param bool $verified 是否通过响应验签
     * @return bool 是否通过
     */
    private function classifyCreateAttempt(array $responseData, array $orderSnapshot, bool $verified): bool
    {
        $payOrder = $orderSnapshot['pay_order'] ?? null;
        $bizOrder = $orderSnapshot['biz_order'] ?? null;

        return (int) ($responseData['code'] ?? -1) === 0
            && $verified
            && (string) ($responseData['trade_no'] ?? '') !== ''
            && $payOrder
            && $bizOrder;
    }

    /**
     * 判定简单成功响应。
     *
     * @param array $responseData 响应数据
     * @param bool $verified 是否通过响应验签
     * @param array $expectedFields 期望字段
     * @return bool 是否通过
     */
    private function classifySimpleSuccess(array $responseData, bool $verified, array $expectedFields = []): bool
    {
        if ((int) ($responseData['code'] ?? -1) !== 0 || !$verified) {
            return false;
        }

        foreach ($expectedFields as $key => $expectedValue) {
            if ((string) ($responseData[$key] ?? '') !== (string) $expectedValue) {
                return false;
            }
        }

        return true;
    }

    /**
     * 输出创建订单结果。
     *
     * @param OutputInterface $output 输出对象
     * @param array $payload 请求载荷
     * @param array $responseData 响应数据
     * @param array $orderSnapshot 订单快照
     * @param array $verifyResult 响应验签结果
     * @param bool $passed 是否通过
     * @return void
     */
    private function writeCreateAttempt(
        OutputInterface $output,
        array $payload,
        array $responseData,
        array $orderSnapshot,
        array $verifyResult,
        bool $passed
    ): void {
        $label = $passed ? '<info>[通过]</info>' : '<error>[失败]</error>';
        $payOrder = $orderSnapshot['pay_order'] ?? [];
        $bizOrder = $orderSnapshot['biz_order'] ?? [];
        $channel = $orderSnapshot['channel'] ?? [];
        $paymentType = $orderSnapshot['payment_type'] ?? [];

        $output->writeln(sprintf('%s create - out_trade_no=%s', $label, (string) $payload['out_trade_no']));
        $output->writeln(sprintf(
            '  请求: pid=%d type=%s method=%s money=%s device=%s',
            (int) $payload['pid'],
            (string) $payload['type'],
            (string) $payload['method'],
            (string) $payload['money'],
            (string) ($payload['device'] ?? '')
        ));
        $output->writeln(sprintf(
            '  响应: code=%s msg=%s sign=%s',
            (string) ($responseData['code'] ?? ''),
            (string) ($responseData['msg'] ?? ''),
            (string) $verifyResult['message']
        ));
        $output->writeln(sprintf(
            '  返回: trade_no=%s pay_type=%s',
            (string) ($responseData['trade_no'] ?? ''),
            (string) ($responseData['pay_type'] ?? '')
        ));
        $payInfoSummary = $this->summarizePayInfo((string) ($responseData['pay_type'] ?? ''), $responseData['pay_info'] ?? null);
        if ($payInfoSummary !== '') {
            $output->writeln('  pay_info: ' . $payInfoSummary);
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
    }

    /**
     * 输出简单接口结果。
     *
     * @param OutputInterface $output 输出对象
     * @param string $name 名称
     * @param array $payload 请求载荷
     * @param array $responseData 响应数据
     * @param array $verifyResult 验签结果
     * @param bool $passed 是否通过
     * @param array $fields 需要额外输出的字段
     * @return void
     */
    private function writeSimpleAttempt(
        OutputInterface $output,
        string $name,
        array $payload,
        array $responseData,
        array $verifyResult,
        bool $passed,
        array $fields
    ): void {
        $label = $passed ? '<info>[通过]</info>' : '<error>[失败]</error>';
        $output->writeln(sprintf(
            '%s %s - pid=%d code=%s msg=%s sign=%s',
            $label,
            $name,
            (int) ($payload['pid'] ?? 0),
            (string) ($responseData['code'] ?? ''),
            (string) ($responseData['msg'] ?? ''),
            (string) $verifyResult['message']
        ));

        foreach ($fields as $field) {
            if (!array_key_exists($field, $responseData)) {
                continue;
            }

            $output->writeln(sprintf('  返回: %s=%s', $field, $this->stringifyValue($responseData[$field])));
        }
    }

    /**
     * 归纳 pay_info 展示内容。
     *
     * @param string $payType pay_type
     * @param mixed $payInfo pay_info
     * @return string 展示文本
     */
    private function summarizePayInfo(string $payType, mixed $payInfo): string
    {
        if (is_string($payInfo)) {
            return $this->limitString($this->normalizeWhitespace($payInfo), 160);
        }

        if (!is_array($payInfo)) {
            return $this->stringifyValue($payInfo);
        }

        $summary = ['pay_type' => $payType];
        foreach (['payurl', 'qrcode', 'urlscheme', 'html', 'app_id', 'nonce_str', 'package'] as $key) {
            if (!array_key_exists($key, $payInfo)) {
                continue;
            }

            $summary[$key] = $key === 'html'
                ? $this->limitString($this->normalizeWhitespace((string) $payInfo[$key]), 160)
                : $this->stringifyValue($payInfo[$key]);
        }

        if ($summary === ['pay_type' => $payType]) {
            $summary['keys'] = array_values(array_map('strval', array_keys($payInfo)));
        }

        return $this->formatJson($summary);
    }

    /**
     * 校验提供的私钥与商户后台公钥是否匹配。
     *
     * @param string $merchantPrivateKey 商户私钥
     * @param string $merchantPublicKey 商户公钥
     * @return void
     * @throws CommandException
     */
    private function ensureKeyPairMatches(string $merchantPrivateKey, string $merchantPublicKey): void
    {
        /** @var EpaySignerManager $signerManager */
        $signerManager = $this->resolve(EpaySignerManager::class);
        $probePayload = [
            'pid' => 1,
            'timestamp' => (string) time(),
        ];
        $sign = $signerManager->sign($probePayload, AuthConstant::API_SIGN_NAME_RSA, $merchantPrivateKey);
        if (!$signerManager->verify($probePayload, AuthConstant::API_SIGN_NAME_RSA, $sign, $merchantPublicKey)) {
            throw new CommandException('提供的商户私钥与后台配置的商户公钥不匹配。');
        }
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

        return 'EPAY-V2-' . FormatHelper::timestamp(time(), 'YmdHis') . random_int(1000, 9999);
    }

    /**
     * 生成商户退款单号。
     *
     * @param string $base 基础退款单号
     * @return string 商户退款单号
     */
    private function buildRefundNo(string $base): string
    {
        $base = trim($base);
        if ($base !== '') {
            return substr($base, 0, 64);
        }

        return 'EPAY-RFD-' . FormatHelper::timestamp(time(), 'YmdHis') . random_int(1000, 9999);
    }

    /**
     * 构建模拟请求对象。
     *
     * @param array $payload 请求载荷
     * @param string $path 请求路径
     * @return Request 请求对象
     */
    private function buildRequest(array $payload, string $path): Request
    {
        $body = http_build_query($payload, '', '&', PHP_QUERY_RFC1738);
        $siteUrl = $this->resolveSiteUrl();
        $host = parse_url($siteUrl, PHP_URL_HOST) ?: 'localhost';
        $port = parse_url($siteUrl, PHP_URL_PORT);
        $hostHeader = $port ? sprintf('%s:%s', $host, $port) : $host;
        $path = '/' . ltrim($path, '/');

        $rawRequest = implode("\r\n", [
            'POST ' . $path . ' HTTP/1.1',
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
     * 解析商户私钥。
     *
     * @param InputInterface $input 命令输入
     * @return string 私钥
     * @throws CommandException
     */
    private function resolveMerchantPrivateKey(InputInterface $input, int $merchantId = 0): string
    {
        $inline = trim($this->optionString($input, 'merchant-private-key', ''));
        if ($inline !== '') {
            return $inline;
        }

        $file = trim($this->optionString($input, 'merchant-private-key-file', ''));
        if ($file !== '') {
            if (!is_file($file)) {
                throw new CommandException('商户私钥文件不存在: ' . $file);
            }

            return trim((string) file_get_contents($file));
        }

        $envFile = trim((string) env('EPAY_V2_TEST_MERCHANT_PRIVATE_KEY_FILE', ''));
        if ($envFile !== '') {
            if (!is_file($envFile)) {
                throw new CommandException('环境变量指定的商户私钥文件不存在: ' . $envFile);
            }

            return trim((string) file_get_contents($envFile));
        }

        $envKey = trim((string) env('EPAY_V2_TEST_MERCHANT_PRIVATE_KEY', ''));
        if ($envKey !== '') {
            return $envKey;
        }

        if ($merchantId > 0) {
            $defaultFile = base_path(false) . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'epay' . DIRECTORY_SEPARATOR . sprintf('merchant-%d-private.pem', $merchantId);
            if (is_file($defaultFile)) {
                return trim((string) file_get_contents($defaultFile));
            }
        }

        throw new CommandException('缺少商户 RSA 私钥，请先执行 epay:v2-bootstrap，或通过 --merchant-private-key / --merchant-private-key-file / EPAY_V2_TEST_MERCHANT_PRIVATE_KEY 提供。');
    }

    /**
     * 解析平台公钥。
     *
     * @return string 平台公钥
     * @throws CommandException
     */
    private function resolvePlatformPublicKey(): string
    {
        $publicKey = trim((string) config('epay.v2.platform_public_key', ''));
        if ($publicKey === '') {
            throw new CommandException('平台 RSA 公钥未配置，无法校验 V2 响应签名。');
        }

        return $publicKey;
    }

    /**
     * 归一化金额字符串。
     *
     * @param string $money 金额
     * @return string 金额字符串
     * @throws CommandException
     */
    private function normalizeMoney(string $money): string
    {
        $money = trim($money);
        if ($money === '') {
            return '1.00';
        }

        if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $money)) {
            throw new CommandException('money 参数不合法: ' . $money);
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
     * 归一化 method。
     *
     * @param string $method 接口方式
     * @return string 接口方式
     * @throws CommandException
     */
    private function normalizeMethod(string $method): string
    {
        $method = strtolower(trim($method));
        $allowed = ['web', 'jump', 'jsapi', 'app', 'scan', 'applet'];
        if (!in_array($method, $allowed, true)) {
            throw new CommandException('method 参数不合法: ' . $method);
        }

        return $method;
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
        return $e::class . '：' . $e->getMessage() . $suffix;
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
     * @throws CommandException
     */
    private function resolve(string $class): object
    {
        try {
            $instance = container_get($class);
        } catch (\Throwable $e) {
            throw new CommandException("无法解析 {$class}。", 0, $e);
        }

        if (!is_object($instance)) {
            throw new CommandException("解析后的 {$class} 不是对象。");
        }

        return $instance;
    }
}
