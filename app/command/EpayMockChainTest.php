<?php

namespace app\command;

use app\common\constant\AuthConstant;
use app\common\constant\CommonConstant;
use app\common\constant\RouteConstant;
use app\common\constant\TradeConstant;
use app\common\util\FormatHelper;
use app\common\util\RsaKeyPairGenerator;
use app\exception\CommandException;
use app\model\admin\PayCallbackLog;
use app\model\merchant\Merchant;
use app\model\merchant\MerchantAccount;
use app\model\merchant\MerchantApiCredential;
use app\model\merchant\MerchantGroup;
use app\model\payment\BizOrder;
use app\model\payment\NotifyTask;
use app\model\payment\PaymentChannel;
use app\model\payment\PaymentPlugin;
use app\model\payment\PaymentPluginConf;
use app\model\payment\PaymentPollGroup;
use app\model\payment\PaymentPollGroupBind;
use app\model\payment\PaymentPollGroupChannel;
use app\model\payment\PaymentType;
use app\model\payment\RefundOrder;
use app\repository\payment\config\PaymentTypeRepository;
use app\repository\payment\trade\BizOrderRepository;
use app\repository\payment\trade\PayOrderRepository;
use app\repository\payment\trade\RefundOrderRepository;
use app\service\payment\config\PaymentPluginSyncService;
use app\service\payment\epay\EpayV1ProtocolService;
use app\service\payment\epay\EpayV2ProtocolService;
use app\service\payment\epay\EpaySignerManager;
use app\service\payment\order\PayOrderService;
use app\command\support\EpayV1CommandMockPayment;
use app\command\support\EpayV2CommandMockPayment;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use support\Request;
use support\Response;
use Throwable;

/**
 * ePay V1 / V2 mock 全链路测试命令。
 *
 * 自动写入测试商户、接口凭证、插件配置、通道和轮询组，
 * 再使用 epay_v1 / epay_v2 插件的 mock 上游能力完整跑一遍支付链路。
 */
#[AsCommand('epay:mock-chain', '运行 epay_v1 / epay_v2 mock 全链路测试')]
class EpayMockChainTest extends Command
{
    private string $logFile = '';

    protected function configure(): void
    {
        $this
            ->setDescription('自动写入测试配置并运行 epay_v1 / epay_v2 mock 全链路测试。')
            ->addOption('only', null, InputOption::VALUE_OPTIONAL, '只运行指定协议：v1 / v2 / all', 'all');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $only = strtolower(trim($this->optionString($input, 'only', 'all')));
        if (!in_array($only, ['all', 'v1', 'v2'], true)) {
            $output->writeln('<error>--only 只支持 v1 / v2 / all</error>');
            return self::FAILURE;
        }

        $this->initLogFile();
        $this->log($output, '开始执行 mock 全链路测试');
        $this->log($output, '日志文件: ' . $this->logFile);

        try {
            $this->refreshPlugins($output);
            $paymentType = $this->resolveAlipayType();
            $summary = [];

            if ($only === 'all' || $only === 'v1') {
                $summary['v1'] = $this->runV1Chain($output, $paymentType);
            }

            if ($only === 'all' || $only === 'v2') {
                $summary['v2'] = $this->runV2Chain($output, $paymentType);
            }

            $this->logJson($output, '最终汇总', $summary);
            $this->log($output, 'mock 全链路测试完成');

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->log($output, '[失败] ' . $this->formatThrowable($e));

            return self::FAILURE;
        }
    }

    /**
     * 运行 V1 全链路。
     *
     * @param OutputInterface $output 输出对象
     * @param PaymentType $paymentType 支付方式
     * @return array<string, mixed>
     */
    private function runV1Chain(OutputInterface $output, PaymentType $paymentType): array
    {
        $this->log($output, '====== V1 mock 链路开始 ======');
        $context = $this->ensureV1Context($output, $paymentType);
        /** @var Merchant $merchant */
        $merchant = $context['merchant'];
        /** @var MerchantApiCredential $credential */
        $credential = $context['credential'];

        $submitOutTradeNo = $this->buildOrderNo('V1SUB');
        $submitPayload = [
            'pid' => (int) $merchant->id,
            'type' => 'alipay',
            'out_trade_no' => $submitOutTradeNo,
            'notify_url' => 'http://127.0.0.1:1/mock-notify/v1-submit',
            'return_url' => 'https://mock.return.test/v1-submit',
            'name' => 'V1 页面跳转支付测试',
            'money' => '1.00',
            'param' => 'v1-submit',
            'sign_type' => AuthConstant::API_SIGN_NAME_MD5,
        ];
        $submitPayload['sign'] = $this->signMd5Payload($submitPayload, (string) $credential->api_key);
        $submitResponse = $this->resolve(EpayV1ProtocolService::class)->submit($submitPayload, $this->buildRequest($submitPayload, '/submit.php'));
        $submitSnapshot = $this->loadOrderSnapshot((int) $merchant->id, $submitOutTradeNo);
        $this->assertTrue(isset($submitSnapshot['pay_order']), 'V1 submit 未创建支付单');
        $this->logJson($output, 'V1 submit 响应', $this->describeHttpResponse($submitResponse));
        $this->logJson($output, 'V1 submit 订单快照', $this->formatOrderSnapshot($submitSnapshot));

        $mapiOutTradeNo = $this->buildOrderNo('V1MAP');
        $mapiPayload = [
            'pid' => (int) $merchant->id,
            'type' => 'alipay',
            'out_trade_no' => $mapiOutTradeNo,
            'notify_url' => 'http://127.0.0.1:1/mock-notify/v1-mapi',
            'return_url' => 'https://mock.return.test/v1-mapi',
            'name' => 'V1 接口支付测试',
            'money' => '1.00',
            'param' => 'v1-mapi',
            'clientip' => '127.0.0.1',
            'device' => 'pc',
            'sign_type' => AuthConstant::API_SIGN_NAME_MD5,
        ];
        $mapiPayload['sign'] = $this->signMd5Payload($mapiPayload, (string) $credential->api_key);
        $mapiResponse = $this->resolve(EpayV1ProtocolService::class)->mapi($mapiPayload, $this->buildRequest($mapiPayload, '/mapi.php'));
        $this->assertTrue((int) ($mapiResponse['code'] ?? 0) === 1, 'V1 mapi 返回失败');
        $mapiSnapshot = $this->loadOrderSnapshot((int) $merchant->id, $mapiOutTradeNo);
        /** @var \app\model\payment\PayOrder $mapiPayOrder */
        $mapiPayOrder = $mapiSnapshot['pay_order'];
        /** @var BizOrder $mapiBizOrder */
        $mapiBizOrder = $mapiSnapshot['biz_order'];
        $this->logJson($output, 'V1 mapi 响应', $mapiResponse);
        $this->logJson($output, 'V1 mapi 订单快照', $this->formatOrderSnapshot($mapiSnapshot));

        $v1CallbackPayload = $this->buildV1CallbackPayload($merchant, $mapiBizOrder, $mapiPayOrder, (string) $context['upstream_key']);
        $v1CallbackAck = $this->resolve(PayOrderService::class)->handlePluginCallback(
            (string) $mapiPayOrder->pay_no,
            $this->buildRequest($v1CallbackPayload, '/api/pay/' . $mapiPayOrder->pay_no . '/callback')
        );
        $mapiAfterCallback = $this->loadOrderSnapshot((int) $merchant->id, $mapiOutTradeNo);
        $this->assertTrue((int) $mapiAfterCallback['pay_order']->status === TradeConstant::ORDER_STATUS_SUCCESS, 'V1 回调后支付单未成功');
        $this->log($output, 'V1 callback ACK: ' . $this->stringifyCallbackResult($v1CallbackAck));
        $this->logJson($output, 'V1 callback 订单快照', $this->formatOrderSnapshot($mapiAfterCallback));
        $this->logJson($output, 'V1 callback 日志', $this->formatCallbackLog($this->loadLatestPayCallbackLog((string) $mapiPayOrder->pay_no)));
        $this->logJson($output, 'V1 商户通知任务', $this->formatNotifyTask($this->loadLatestNotifyTask((string) $mapiPayOrder->pay_no)));

        $queryMerchantResponse = $this->callV1Api([
            'act' => 'query',
            'pid' => (int) $merchant->id,
            'key' => (string) $credential->api_key,
        ]);
        $this->assertTrue((int) ($queryMerchantResponse['code'] ?? 0) === 1, 'V1 api query 返回失败');
        $this->logJson($output, 'V1 api query', $queryMerchantResponse);

        $queryOrderResponse = $this->callV1Api([
            'act' => 'order',
            'pid' => (int) $merchant->id,
            'key' => (string) $credential->api_key,
            'trade_no' => (string) $mapiPayOrder->pay_no,
        ]);
        $this->assertTrue((int) ($queryOrderResponse['code'] ?? 0) === 1, 'V1 api order 返回失败');
        $this->logJson($output, 'V1 api order', $queryOrderResponse);

        $queryOrdersResponse = $this->callV1Api([
            'act' => 'orders',
            'pid' => (int) $merchant->id,
            'key' => (string) $credential->api_key,
            'page' => 1,
            'limit' => 5,
        ]);
        $this->assertTrue((int) ($queryOrdersResponse['code'] ?? 0) === 1, 'V1 api orders 返回失败');
        $this->logJson($output, 'V1 api orders', $queryOrdersResponse);

        $refundResponse = $this->callV1Api([
            'act' => 'refund',
            'pid' => (int) $merchant->id,
            'key' => (string) $credential->api_key,
            'trade_no' => (string) $mapiPayOrder->pay_no,
            'money' => '1.00',
        ]);
        $this->assertTrue((int) ($refundResponse['code'] ?? 0) === 1, 'V1 api refund 返回失败');
        $refundOrder = $this->loadRefundOrderByPayNo((string) $mapiPayOrder->pay_no);
        $this->assertTrue($refundOrder instanceof RefundOrder, 'V1 退款单未落库');
        $this->assertTrue((int) $refundOrder->status === TradeConstant::REFUND_STATUS_SUCCESS, 'V1 退款单未成功');
        $this->logJson($output, 'V1 api refund', $refundResponse);
        $this->logJson($output, 'V1 refund 订单快照', $this->formatRefundOrder($refundOrder));

        $summary = [
            'merchant_id' => (int) $merchant->id,
            'merchant_no' => (string) $merchant->merchant_no,
            'submit_pay_no' => (string) $submitSnapshot['pay_order']->pay_no,
            'mapi_pay_no' => (string) $mapiPayOrder->pay_no,
            'mapi_status' => (int) $mapiAfterCallback['pay_order']->status,
            'refund_no' => (string) $refundOrder->refund_no,
            'refund_status' => (int) $refundOrder->status,
        ];
        $this->logJson($output, 'V1 汇总', $summary);
        $this->log($output, '====== V1 mock 链路完成 ======');

        return $summary;
    }

    /**
     * 运行 V2 全链路。
     *
     * @param OutputInterface $output 输出对象
     * @param PaymentType $paymentType 支付方式
     * @return array<string, mixed>
     */
    private function runV2Chain(OutputInterface $output, PaymentType $paymentType): array
    {
        $this->log($output, '====== V2 mock 链路开始 ======');
        $context = $this->ensureV2Context($output, $paymentType);
        /** @var Merchant $merchant */
        $merchant = $context['merchant'];
        $merchantPrivateKey = (string) $context['merchant_private_key'];
        $upstreamPlatformPrivateKey = (string) $context['upstream_platform_private_key'];
        $platformPublicKey = $this->resolvePlatformPublicKey();

        $submitOutTradeNo = $this->buildOrderNo('V2SUB');
        $submitPayload = $this->buildSignedV2Payload([
            'pid' => (int) $merchant->id,
            'type' => 'alipay',
            'out_trade_no' => $submitOutTradeNo,
            'notify_url' => 'http://127.0.0.1:1/mock-notify/v2-submit',
            'return_url' => 'https://mock.return.test/v2-submit',
            'name' => 'V2 页面跳转支付测试',
            'money' => '1.00',
            'param' => 'v2-submit',
        ], $merchantPrivateKey);
        $submitResponse = $this->resolve(EpayV2ProtocolService::class)->submit($submitPayload, $this->buildRequest($submitPayload, '/api/pay/submit'));
        $submitSnapshot = $this->loadOrderSnapshot((int) $merchant->id, $submitOutTradeNo);
        $this->assertTrue(isset($submitSnapshot['pay_order']), 'V2 submit 未创建支付单');
        /** @var \app\model\payment\PayOrder $submitPayOrder */
        $submitPayOrder = $submitSnapshot['pay_order'];
        $this->logJson($output, 'V2 submit 响应', $this->describeHttpResponse($submitResponse));
        $this->logJson($output, 'V2 submit 订单快照', $this->formatOrderSnapshot($submitSnapshot));

        $closeResponse = $this->callV2JsonEndpoint('close', $this->buildSignedV2Payload([
            'pid' => (int) $merchant->id,
            'trade_no' => (string) $submitPayOrder->pay_no,
        ], $merchantPrivateKey));
        $closeVerify = $this->verifyV2ResponseSignature($closeResponse, $platformPublicKey);
        $this->assertTrue((int) ($closeResponse['code'] ?? -1) === 0, 'V2 close 返回失败');
        $this->assertTrue($closeVerify['passed'], 'V2 close 响应验签失败');
        $submitAfterClose = $this->loadOrderSnapshot((int) $merchant->id, $submitOutTradeNo);
        $this->assertTrue((int) $submitAfterClose['pay_order']->status === TradeConstant::ORDER_STATUS_CLOSED, 'V2 submit 单未关闭');
        $this->logJson($output, 'V2 close 响应', $closeResponse);
        $this->logJson($output, 'V2 close 订单快照', $this->formatOrderSnapshot($submitAfterClose));

        $createOutTradeNo = $this->buildOrderNo('V2CRT');
        $createResponse = $this->callV2JsonEndpoint('create', $this->buildSignedV2Payload([
            'pid' => (int) $merchant->id,
            'type' => 'alipay',
            'method' => 'web',
            'out_trade_no' => $createOutTradeNo,
            'notify_url' => 'http://127.0.0.1:1/mock-notify/v2-create',
            'return_url' => 'https://mock.return.test/v2-create',
            'name' => 'V2 API 支付测试',
            'money' => '1.00',
            'param' => 'v2-create',
            'clientip' => '127.0.0.1',
            'device' => 'pc',
        ], $merchantPrivateKey));
        $createVerify = $this->verifyV2ResponseSignature($createResponse, $platformPublicKey);
        $this->assertTrue((int) ($createResponse['code'] ?? -1) === 0, 'V2 create 返回失败');
        $this->assertTrue($createVerify['passed'], 'V2 create 响应验签失败');
        $createSnapshot = $this->loadOrderSnapshot((int) $merchant->id, $createOutTradeNo);
        /** @var \app\model\payment\PayOrder $createPayOrder */
        $createPayOrder = $createSnapshot['pay_order'];
        /** @var BizOrder $createBizOrder */
        $createBizOrder = $createSnapshot['biz_order'];
        $this->logJson($output, 'V2 create 响应', $createResponse);
        $this->logJson($output, 'V2 create 订单快照', $this->formatOrderSnapshot($createSnapshot));

        $v2CallbackPayload = $this->buildV2CallbackPayload($merchant, $createBizOrder, $createPayOrder, $upstreamPlatformPrivateKey);
        $v2CallbackAck = $this->resolve(PayOrderService::class)->handlePluginCallback(
            (string) $createPayOrder->pay_no,
            $this->buildRequest($v2CallbackPayload, '/api/pay/' . $createPayOrder->pay_no . '/callback')
        );
        $createAfterCallback = $this->loadOrderSnapshot((int) $merchant->id, $createOutTradeNo);
        $this->assertTrue((int) $createAfterCallback['pay_order']->status === TradeConstant::ORDER_STATUS_SUCCESS, 'V2 回调后支付单未成功');
        $this->log($output, 'V2 callback ACK: ' . $this->stringifyCallbackResult($v2CallbackAck));
        $this->logJson($output, 'V2 callback 订单快照', $this->formatOrderSnapshot($createAfterCallback));
        $this->logJson($output, 'V2 callback 日志', $this->formatCallbackLog($this->loadLatestPayCallbackLog((string) $createPayOrder->pay_no)));
        $this->logJson($output, 'V2 商户通知任务', $this->formatNotifyTask($this->loadLatestNotifyTask((string) $createPayOrder->pay_no)));

        $queryResponse = $this->callV2JsonEndpoint('query', $this->buildSignedV2Payload([
            'pid' => (int) $merchant->id,
            'trade_no' => (string) $createPayOrder->pay_no,
        ], $merchantPrivateKey));
        $queryVerify = $this->verifyV2ResponseSignature($queryResponse, $platformPublicKey);
        $this->assertTrue((int) ($queryResponse['code'] ?? -1) === 0, 'V2 query 返回失败');
        $this->assertTrue($queryVerify['passed'], 'V2 query 响应验签失败');
        $this->assertTrue((int) ($queryResponse['status'] ?? 0) === 1, 'V2 query 状态不正确');
        $this->logJson($output, 'V2 query 响应', $queryResponse);

        $merchantInfoResponse = $this->callV2JsonEndpoint('merchantInfo', $this->buildSignedV2Payload([
            'pid' => (int) $merchant->id,
        ], $merchantPrivateKey));
        $merchantInfoVerify = $this->verifyV2ResponseSignature($merchantInfoResponse, $platformPublicKey);
        $this->assertTrue((int) ($merchantInfoResponse['code'] ?? -1) === 0, 'V2 merchantInfo 返回失败');
        $this->assertTrue($merchantInfoVerify['passed'], 'V2 merchantInfo 响应验签失败');
        $this->logJson($output, 'V2 merchantInfo 响应', $merchantInfoResponse);

        $merchantOrdersResponse = $this->callV2JsonEndpoint('merchantOrders', $this->buildSignedV2Payload([
            'pid' => (int) $merchant->id,
            'offset' => 0,
            'limit' => 5,
        ], $merchantPrivateKey));
        $merchantOrdersVerify = $this->verifyV2ResponseSignature($merchantOrdersResponse, $platformPublicKey);
        $this->assertTrue((int) ($merchantOrdersResponse['code'] ?? -1) === 0, 'V2 merchantOrders 返回失败');
        $this->assertTrue($merchantOrdersVerify['passed'], 'V2 merchantOrders 响应验签失败');
        $this->logJson($output, 'V2 merchantOrders 响应', $merchantOrdersResponse);

        $outRefundNo = $this->buildRefundNo('V2REF');
        $refundResponse = $this->callV2JsonEndpoint('refund', $this->buildSignedV2Payload([
            'pid' => (int) $merchant->id,
            'trade_no' => (string) $createPayOrder->pay_no,
            'money' => '1.00',
            'out_refund_no' => $outRefundNo,
        ], $merchantPrivateKey));
        $refundVerify = $this->verifyV2ResponseSignature($refundResponse, $platformPublicKey);
        $this->assertTrue((int) ($refundResponse['code'] ?? -1) === 0, 'V2 refund 返回失败');
        $this->assertTrue($refundVerify['passed'], 'V2 refund 响应验签失败');
        $refundOrder = $this->loadRefundOrderByMerchantRefundNo((int) $merchant->id, $outRefundNo);
        $this->assertTrue($refundOrder instanceof RefundOrder, 'V2 退款单未落库');
        $this->assertTrue((int) $refundOrder->status === TradeConstant::REFUND_STATUS_SUCCESS, 'V2 退款单未成功');
        $this->logJson($output, 'V2 refund 响应', $refundResponse);
        $this->logJson($output, 'V2 refund 订单快照', $this->formatRefundOrder($refundOrder));

        $refundQueryResponse = $this->callV2JsonEndpoint('refundQuery', $this->buildSignedV2Payload([
            'pid' => (int) $merchant->id,
            'out_refund_no' => $outRefundNo,
        ], $merchantPrivateKey));
        $refundQueryVerify = $this->verifyV2ResponseSignature($refundQueryResponse, $platformPublicKey);
        $this->assertTrue((int) ($refundQueryResponse['code'] ?? -1) === 0, 'V2 refundQuery 返回失败');
        $this->assertTrue($refundQueryVerify['passed'], 'V2 refundQuery 响应验签失败');
        $this->assertTrue((int) ($refundQueryResponse['status'] ?? 0) === 1, 'V2 refundQuery 状态不正确');
        $this->logJson($output, 'V2 refundQuery 响应', $refundQueryResponse);

        $summary = [
            'merchant_id' => (int) $merchant->id,
            'merchant_no' => (string) $merchant->merchant_no,
            'submit_pay_no' => (string) $submitPayOrder->pay_no,
            'submit_status' => (int) $submitAfterClose['pay_order']->status,
            'create_pay_no' => (string) $createPayOrder->pay_no,
            'create_status' => (int) $createAfterCallback['pay_order']->status,
            'refund_no' => (string) $refundOrder->refund_no,
            'refund_status' => (int) $refundOrder->status,
        ];
        $this->logJson($output, 'V2 汇总', $summary);
        $this->log($output, '====== V2 mock 链路完成 ======');

        return $summary;
    }

    /**
     * 确保 V1 测试环境。
     *
     * @param OutputInterface $output 输出对象
     * @param PaymentType $paymentType 支付方式
     * @return array<string, mixed>
     */
    private function ensureV1Context(OutputInterface $output, PaymentType $paymentType): array
    {
        $group = $this->ensureMerchantGroup('支付链路测试-V1');
        $merchant = $this->ensureMerchant([
            'merchant_no' => 'MCHAINMOCKV1',
            'merchant_name' => '支付链路测试商户-V1',
            'merchant_short_name' => '链路测试V1',
            'group_id' => (int) $group->id,
            'remark' => '命令行 mock 全链路测试商户',
        ]);
        $this->ensureMerchantAccount((int) $merchant->id);

        $credential = MerchantApiCredential::query()->updateOrCreate(
            ['merchant_id' => (int) $merchant->id],
            [
                'merchant_id' => (int) $merchant->id,
                'api_key' => 'mock-v1-api-key-20260423',
                'merchant_public_key' => '',
                'status' => AuthConstant::CREDENTIAL_STATUS_ENABLED,
            ]
        );

        $pluginConf = $this->ensurePluginConf('epay_v1_command_mock', '支付链路测试-V1', [
            'api_url' => 'https://mock.epay.test/v1',
            'pid' => '900001',
            'api_key' => 'mock-v1-upstream-key-20260423',
            'support_mapi' => true,
            'mock_jump_base_url' => 'https://mock.epay.test/v1/pay',
            'type_mapping_json' => [
                'alipay' => 'alipay',
                'wxpay' => 'wxpay',
            ],
        ]);
        $channel = $this->ensureChannel(
            (int) $merchant->id,
            (int) $paymentType->id,
            'epay_v1_command_mock',
            (int) $pluginConf->id,
            '支付链路测试-V1-支付宝通道'
        );
        $pollGroup = $this->ensurePollGroup('支付链路测试路由-V1', (int) $paymentType->id);
        $this->ensurePollGroupChannel((int) $pollGroup->id, (int) $channel->id);
        $this->ensurePollGroupBind((int) $group->id, (int) $paymentType->id, (int) $pollGroup->id);

        $summary = [
            'merchant_id' => (int) $merchant->id,
            'merchant_no' => (string) $merchant->merchant_no,
            'group_id' => (int) $group->id,
            'plugin_conf_id' => (int) $pluginConf->id,
            'channel_id' => (int) $channel->id,
            'poll_group_id' => (int) $pollGroup->id,
        ];
        $this->logJson($output, 'V1 测试环境', $summary);

        return [
            'merchant' => $merchant,
            'credential' => $credential,
            'upstream_key' => 'mock-v1-upstream-key-20260423',
        ];
    }

    /**
     * 确保 V2 测试环境。
     *
     * @param OutputInterface $output 输出对象
     * @param PaymentType $paymentType 支付方式
     * @return array<string, mixed>
     */
    private function ensureV2Context(OutputInterface $output, PaymentType $paymentType): array
    {
        $group = $this->ensureMerchantGroup('支付链路测试-V2');
        $merchant = $this->ensureMerchant([
            'merchant_no' => 'MCHAINMOCKV2',
            'merchant_name' => '支付链路测试商户-V2',
            'merchant_short_name' => '链路测试V2',
            'group_id' => (int) $group->id,
            'remark' => '命令行 mock 全链路测试商户',
        ]);
        $this->ensureMerchantAccount((int) $merchant->id);

        $merchantPair = RsaKeyPairGenerator::generate();
        $upstreamMerchantPair = RsaKeyPairGenerator::generate();
        $upstreamPlatformPair = RsaKeyPairGenerator::generate();

        $credential = MerchantApiCredential::query()->updateOrCreate(
            ['merchant_id' => (int) $merchant->id],
            [
                'merchant_id' => (int) $merchant->id,
                'api_key' => 'mock-v2-api-key-20260423',
                'merchant_public_key' => $merchantPair['public_key'],
                'status' => AuthConstant::CREDENTIAL_STATUS_ENABLED,
            ]
        );

        $merchantPrivateKeyPath = $this->writePemFile('epay/mock-chain/' . $merchant->merchant_no . '-merchant-private.pem', $merchantPair['private_key']);
        $pluginConf = $this->ensurePluginConf('epay_v2_command_mock', '支付链路测试-V2', [
            'api_url' => 'https://mock.epay.test/v2',
            'pid' => '900002',
            'merchant_private_key' => $upstreamMerchantPair['private_key'],
            'platform_public_key' => $upstreamPlatformPair['public_key'],
            'support_api' => true,
            'mock_jump_base_url' => 'https://mock.epay.test/v2/pay',
            'type_mapping_json' => [
                'alipay' => 'alipay',
                'wxpay' => 'wxpay',
                'unionpay' => 'bank',
            ],
        ]);
        $channel = $this->ensureChannel(
            (int) $merchant->id,
            (int) $paymentType->id,
            'epay_v2_command_mock',
            (int) $pluginConf->id,
            '支付链路测试-V2-支付宝通道'
        );
        $pollGroup = $this->ensurePollGroup('支付链路测试路由-V2', (int) $paymentType->id);
        $this->ensurePollGroupChannel((int) $pollGroup->id, (int) $channel->id);
        $this->ensurePollGroupBind((int) $group->id, (int) $paymentType->id, (int) $pollGroup->id);

        $summary = [
            'merchant_id' => (int) $merchant->id,
            'merchant_no' => (string) $merchant->merchant_no,
            'group_id' => (int) $group->id,
            'plugin_conf_id' => (int) $pluginConf->id,
            'channel_id' => (int) $channel->id,
            'poll_group_id' => (int) $pollGroup->id,
            'merchant_private_key_path' => $merchantPrivateKeyPath,
        ];
        $this->logJson($output, 'V2 测试环境', $summary);

        return [
            'merchant' => $merchant,
            'credential' => $credential,
            'merchant_private_key' => $merchantPair['private_key'],
            'merchant_private_key_path' => $merchantPrivateKeyPath,
            'upstream_platform_private_key' => $upstreamPlatformPair['private_key'],
        ];
    }

    /**
     * 刷新插件定义。
     */
    private function refreshPlugins(OutputInterface $output): void
    {
        /** @var PaymentPluginSyncService $service */
        $service = $this->resolve(PaymentPluginSyncService::class);
        $result = $service->refreshFromClasses();
        $this->ensureCommandMockPlugins();
        $this->logJson($output, '插件同步结果', [
            'count' => (int) ($result['count'] ?? 0),
        ]);
    }

    /**
     * 注册仅供命令测试使用的支付插件。
     */
    private function ensureCommandMockPlugins(): void
    {
        foreach ([
            [
                'code' => 'epay_v1_command_mock',
                'name' => 'ePay V1 命令测试桩',
                'class_name' => EpayV1CommandMockPayment::class,
                'pay_types' => ['alipay', 'wxpay'],
            ],
            [
                'code' => 'epay_v2_command_mock',
                'name' => 'ePay V2 命令测试桩',
                'class_name' => EpayV2CommandMockPayment::class,
                'pay_types' => ['alipay', 'wxpay', 'qqpay'],
            ],
        ] as $row) {
            PaymentPlugin::query()->updateOrCreate(
                ['code' => $row['code']],
                [
                    'name' => $row['name'],
                    'class_name' => $row['class_name'],
                    'config_schema' => [],
                    'pay_types' => $row['pay_types'],
                    'transfer_types' => [],
                    'version' => '1.0.0',
                    'author' => 'MPAY',
                    'link' => '',
                    'allow_merchant' => CommonConstant::NO,
                    'status' => CommonConstant::STATUS_ENABLED,
                    'remark' => '命令行测试专用插件',
                ]
            );
        }
    }

    /**
     * 解析支付宝支付方式。
     */
    private function resolveAlipayType(): PaymentType
    {
        /** @var PaymentTypeRepository $repository */
        $repository = $this->resolve(PaymentTypeRepository::class);
        $paymentType = $repository->findByCode('alipay');
        if (!$paymentType || (int) $paymentType->status !== CommonConstant::STATUS_ENABLED) {
            throw new CommandException('未找到可用的 alipay 支付方式');
        }

        return $paymentType;
    }

    /**
     * 确保商户分组存在。
     */
    private function ensureMerchantGroup(string $groupName): MerchantGroup
    {
        $group = MerchantGroup::query()->where('group_name', $groupName)->first();
        if (!$group) {
            $group = new MerchantGroup();
            $group->group_name = $groupName;
        }

        $group->status = CommonConstant::STATUS_ENABLED;
        $group->remark = '命令行 mock 全链路测试';
        $group->save();

        return $group->refresh();
    }

    /**
     * 确保商户存在。
     *
     * @param array<string, mixed> $data 商户数据
     */
    private function ensureMerchant(array $data): Merchant
    {
        $merchant = Merchant::query()->where('merchant_no', (string) $data['merchant_no'])->first();
        if (!$merchant) {
            $merchant = new Merchant();
            $merchant->merchant_no = (string) $data['merchant_no'];
        }

        $merchant->password_hash = password_hash('123456', PASSWORD_BCRYPT);
        $merchant->merchant_name = (string) $data['merchant_name'];
        $merchant->merchant_short_name = (string) $data['merchant_short_name'];
        $merchant->merchant_type = 0;
        $merchant->group_id = (int) $data['group_id'];
        $merchant->risk_level = 0;
        $merchant->contact_name = 'Mock Tester';
        $merchant->contact_phone = '13800000000';
        $merchant->contact_email = 'mock@example.test';
        $merchant->settlement_account_name = 'Mock Tester';
        $merchant->settlement_account_no = '6222020202020202020';
        $merchant->settlement_bank_name = 'Mock Bank';
        $merchant->settlement_bank_branch = 'Mock Branch';
        $merchant->status = CommonConstant::STATUS_ENABLED;
        $merchant->pay_status = CommonConstant::STATUS_ENABLED;
        $merchant->settle_status = CommonConstant::STATUS_ENABLED;
        $merchant->settle_type = 4;
        $merchant->last_login_ip = '';
        $merchant->password_updated_at = date('Y-m-d H:i:s');
        $merchant->remark = (string) ($data['remark'] ?? '');
        $merchant->save();

        return $merchant->refresh();
    }

    /**
     * 确保商户账户存在。
     */
    private function ensureMerchantAccount(int $merchantId): MerchantAccount
    {
        $account = MerchantAccount::query()->where('merchant_id', $merchantId)->first();
        if (!$account) {
            $account = new MerchantAccount();
            $account->merchant_id = $merchantId;
        }

        $account->available_balance = 100000000;
        $account->frozen_balance = 0;
        $account->save();

        return $account->refresh();
    }

    /**
     * 确保插件配置存在。
     *
     * @param array<string, mixed> $config 配置内容
     */
    private function ensurePluginConf(string $pluginCode, string $remark, array $config): PaymentPluginConf
    {
        $pluginConf = PaymentPluginConf::query()
            ->where('plugin_code', $pluginCode)
            ->where('remark', $remark)
            ->first();

        if (!$pluginConf) {
            $pluginConf = new PaymentPluginConf();
            $pluginConf->plugin_code = $pluginCode;
        }

        $pluginConf->config = $config;
        $pluginConf->settlement_cycle_type = TradeConstant::SETTLEMENT_CYCLE_D1;
        $pluginConf->settlement_cutoff_time = '23:59:59';
        $pluginConf->remark = $remark;
        $pluginConf->save();

        return $pluginConf->refresh();
    }

    /**
     * 确保支付通道存在。
     */
    private function ensureChannel(int $merchantId, int $payTypeId, string $pluginCode, int $pluginConfId, string $name): PaymentChannel
    {
        $channel = PaymentChannel::query()->where('name', $name)->first();
        if (!$channel) {
            $channel = new PaymentChannel();
            $channel->name = $name;
        }

        $channel->merchant_id = $merchantId;
        $channel->split_rate_bp = 10000;
        $channel->cost_rate_bp = 0;
        $channel->channel_mode = RouteConstant::CHANNEL_MODE_COLLECT;
        $channel->pay_type_id = $payTypeId;
        $channel->plugin_code = $pluginCode;
        $channel->api_config_id = $pluginConfId;
        $channel->daily_limit_amount = 0;
        $channel->daily_limit_count = 0;
        $channel->min_amount = 0;
        $channel->max_amount = 0;
        $channel->remark = '命令行 mock 全链路测试通道';
        $channel->status = CommonConstant::STATUS_ENABLED;
        $channel->sort_no = 0;
        $channel->save();

        return $channel->refresh();
    }

    /**
     * 确保轮询组存在。
     */
    private function ensurePollGroup(string $groupName, int $payTypeId): PaymentPollGroup
    {
        $pollGroup = PaymentPollGroup::query()->where('group_name', $groupName)->first();
        if (!$pollGroup) {
            $pollGroup = new PaymentPollGroup();
            $pollGroup->group_name = $groupName;
        }

        $pollGroup->pay_type_id = $payTypeId;
        $pollGroup->route_mode = RouteConstant::ROUTE_MODE_ORDER;
        $pollGroup->status = CommonConstant::STATUS_ENABLED;
        $pollGroup->remark = '命令行 mock 全链路测试路由组';
        $pollGroup->save();

        return $pollGroup->refresh();
    }

    /**
     * 确保轮询组通道编排存在。
     */
    private function ensurePollGroupChannel(int $pollGroupId, int $channelId): PaymentPollGroupChannel
    {
        $row = PaymentPollGroupChannel::query()
            ->where('poll_group_id', $pollGroupId)
            ->where('channel_id', $channelId)
            ->first();
        if (!$row) {
            $row = new PaymentPollGroupChannel();
            $row->poll_group_id = $pollGroupId;
            $row->channel_id = $channelId;
        }

        $row->sort_no = 0;
        $row->weight = 100;
        $row->is_default = CommonConstant::YES;
        $row->status = CommonConstant::STATUS_ENABLED;
        $row->remark = '命令行 mock 全链路测试编排';
        $row->save();

        PaymentPollGroupChannel::query()
            ->where('poll_group_id', $pollGroupId)
            ->where('channel_id', '<>', $channelId)
            ->update(['is_default' => 0]);

        return $row->refresh();
    }

    /**
     * 确保轮询组绑定存在。
     */
    private function ensurePollGroupBind(int $merchantGroupId, int $payTypeId, int $pollGroupId): PaymentPollGroupBind
    {
        $bind = PaymentPollGroupBind::query()
            ->where('merchant_group_id', $merchantGroupId)
            ->where('pay_type_id', $payTypeId)
            ->first();
        if (!$bind) {
            $bind = new PaymentPollGroupBind();
            $bind->merchant_group_id = $merchantGroupId;
            $bind->pay_type_id = $payTypeId;
        }

        $bind->poll_group_id = $pollGroupId;
        $bind->status = CommonConstant::STATUS_ENABLED;
        $bind->remark = '命令行 mock 全链路测试绑定';
        $bind->save();

        return $bind->refresh();
    }

    /**
     * 调用 V1 兼容 API。
     *
     * @param array<string, mixed> $payload 请求参数
     * @return array<string, mixed>
     */
    private function callV1Api(array $payload): array
    {
        return $this->resolve(EpayV1ProtocolService::class)->api($payload);
    }

    /**
     * 调用 V2 JSON 接口。
     *
     * @param string $action 动作名
     * @param array<string, mixed> $payload 请求参数
     * @return array<string, mixed>
     */
    private function callV2JsonEndpoint(string $action, array $payload): array
    {
        /** @var EpayV2ProtocolService $service */
        $service = $this->resolve(EpayV2ProtocolService::class);
        $request = $this->buildRequest($payload, match ($action) {
            'create' => '/api/pay/create',
            'query' => '/api/pay/query',
            'refund' => '/api/pay/refund',
            'refundQuery' => '/api/pay/refundquery',
            'close' => '/api/pay/close',
            'merchantInfo' => '/api/merchant/info',
            'merchantOrders' => '/api/merchant/orders',
            default => throw new CommandException('不支持的 V2 动作: ' . $action),
        });

        return match ($action) {
            'create' => $service->create($payload, $request),
            'query' => $service->query($payload),
            'refund' => $service->refund($payload),
            'refundQuery' => $service->refundQuery($payload),
            'close' => $service->close($payload),
            'merchantInfo' => $service->merchantInfo($payload),
            'merchantOrders' => $service->merchantOrders($payload),
            default => throw new CommandException('不支持的 V2 动作: ' . $action),
        };
    }

    /**
     * 构建表单请求。
     *
     * @param array<string, mixed> $payload 请求参数
     * @param string $path 路径
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
     * 构建 V2 已签名载荷。
     *
     * @param array<string, mixed> $payload 原始参数
     * @return array<string, mixed>
     */
    private function buildSignedV2Payload(array $payload, string $privateKey): array
    {
        /** @var EpaySignerManager $signerManager */
        $signerManager = $this->resolve(EpaySignerManager::class);
        $payload['timestamp'] = (string) time();
        $payload['sign_type'] = AuthConstant::API_SIGN_NAME_RSA;
        $signPayload = $payload;
        unset($signPayload['sign'], $signPayload['sign_type']);
        $payload['sign'] = $signerManager->sign($signPayload, AuthConstant::API_SIGN_NAME_RSA, $privateKey);

        return $payload;
    }

    /**
     * 校验 V2 响应签名。
     *
     * @param array<string, mixed> $responseData 响应参数
     * @return array{passed: bool, message: string}
     */
    private function verifyV2ResponseSignature(array $responseData, string $platformPublicKey): array
    {
        /** @var EpaySignerManager $signerManager */
        $signerManager = $this->resolve(EpaySignerManager::class);
        $sign = trim((string) ($responseData['sign'] ?? ''));
        if ($sign === '') {
            return ['passed' => false, 'message' => '响应缺少 sign'];
        }

        $verifyPayload = $responseData;
        unset($verifyPayload['sign'], $verifyPayload['sign_type']);

        if (!$signerManager->verify($verifyPayload, (string) ($responseData['sign_type'] ?? AuthConstant::API_SIGN_NAME_RSA), $sign, $platformPublicKey)) {
            return ['passed' => false, 'message' => '响应验签失败'];
        }

        return ['passed' => true, 'message' => 'success'];
    }

    /**
     * 生成 V1 MD5 签名。
     *
     * @param array<string, mixed> $payload 参数
     */
    private function signMd5Payload(array $payload, string $key): string
    {
        /** @var EpaySignerManager $signerManager */
        $signerManager = $this->resolve(EpaySignerManager::class);

        return $signerManager->sign($payload, AuthConstant::API_SIGN_NAME_MD5, $key);
    }

    /**
     * 构建 V1 回调参数。
     *
     * @return array<string, mixed>
     */
    private function buildV1CallbackPayload(Merchant $merchant, BizOrder $bizOrder, $payOrder, string $upstreamKey): array
    {
        $payload = [
            'pid' => (int) $merchant->id,
            'trade_no' => (string) $payOrder->channel_order_no,
            'api_trade_no' => (string) ($payOrder->channel_trade_no ?: $payOrder->channel_order_no),
            'out_trade_no' => (string) $bizOrder->merchant_order_no,
            'type' => 'alipay',
            'name' => (string) $bizOrder->subject,
            'money' => FormatHelper::amount((int) $payOrder->pay_amount),
            'trade_status' => 'TRADE_SUCCESS',
            'param' => (string) (((array) ($bizOrder->ext_json['merchant'] ?? []))['param'] ?? ''),
            'endtime' => FormatHelper::dateTime($this->nowDateTime()),
            'sign_type' => AuthConstant::API_SIGN_NAME_MD5,
        ];
        $signPayload = $payload;
        unset($signPayload['sign'], $signPayload['sign_type']);
        $payload['sign'] = $this->signMd5Payload($signPayload, $upstreamKey);

        return $payload;
    }

    /**
     * 构建 V2 回调参数。
     *
     * @return array<string, mixed>
     */
    private function buildV2CallbackPayload(Merchant $merchant, BizOrder $bizOrder, $payOrder, string $mockPlatformPrivateKey): array
    {
        /** @var EpaySignerManager $signerManager */
        $signerManager = $this->resolve(EpaySignerManager::class);
        $payload = [
            'pid' => (int) $merchant->id,
            'trade_no' => (string) $payOrder->channel_order_no,
            'api_trade_no' => (string) ($payOrder->channel_trade_no ?: $payOrder->channel_order_no),
            'out_trade_no' => (string) $bizOrder->merchant_order_no,
            'type' => 'alipay',
            'name' => (string) $bizOrder->subject,
            'money' => FormatHelper::amount((int) $payOrder->pay_amount),
            'trade_status' => 'TRADE_SUCCESS',
            'param' => (string) (((array) ($bizOrder->ext_json['merchant'] ?? []))['param'] ?? ''),
            'timestamp' => (string) time(),
            'endtime' => FormatHelper::dateTime($this->nowDateTime()),
            'sign_type' => AuthConstant::API_SIGN_NAME_RSA,
        ];
        $signPayload = $payload;
        unset($signPayload['sign'], $signPayload['sign_type']);
        $payload['sign'] = $signerManager->sign($signPayload, AuthConstant::API_SIGN_NAME_RSA, $mockPlatformPrivateKey);

        return $payload;
    }

    /**
     * 加载订单快照。
     *
     * @return array{biz_order: BizOrder|null, pay_order: mixed, refund_order: RefundOrder|null}
     */
    private function loadOrderSnapshot(int $merchantId, string $merchantOrderNo): array
    {
        /** @var BizOrderRepository $bizOrderRepository */
        $bizOrderRepository = $this->resolve(BizOrderRepository::class);
        /** @var PayOrderRepository $payOrderRepository */
        $payOrderRepository = $this->resolve(PayOrderRepository::class);
        /** @var RefundOrderRepository $refundOrderRepository */
        $refundOrderRepository = $this->resolve(RefundOrderRepository::class);

        $bizOrder = $bizOrderRepository->findByMerchantAndOrderNo($merchantId, $merchantOrderNo);
        $payOrder = $bizOrder ? $payOrderRepository->findLatestByBizNo((string) $bizOrder->biz_no) : null;
        $refundOrder = $payOrder ? $refundOrderRepository->findByPayNo((string) $payOrder->pay_no) : null;

        return [
            'biz_order' => $bizOrder,
            'pay_order' => $payOrder,
            'refund_order' => $refundOrder,
        ];
    }

    /**
     * 根据支付单号加载最新回调日志。
     */
    private function loadLatestPayCallbackLog(string $payNo): ?PayCallbackLog
    {
        return PayCallbackLog::query()->where('pay_no', $payNo)->orderByDesc('id')->first();
    }

    /**
     * 根据支付单号加载最新商户通知任务。
     */
    private function loadLatestNotifyTask(string $payNo): ?NotifyTask
    {
        return NotifyTask::query()->where('pay_no', $payNo)->orderByDesc('id')->first();
    }

    /**
     * 根据支付单号加载退款单。
     */
    private function loadRefundOrderByPayNo(string $payNo): ?RefundOrder
    {
        /** @var RefundOrderRepository $repository */
        $repository = $this->resolve(RefundOrderRepository::class);
        return $repository->findByPayNo($payNo);
    }

    /**
     * 根据商户退款单号加载退款单。
     */
    private function loadRefundOrderByMerchantRefundNo(int $merchantId, string $merchantRefundNo): ?RefundOrder
    {
        /** @var RefundOrderRepository $repository */
        $repository = $this->resolve(RefundOrderRepository::class);
        return $repository->findByMerchantRefundNo($merchantId, $merchantRefundNo);
    }

    /**
     * 输出订单快照。
     *
     * @param array{biz_order: BizOrder|null, pay_order: mixed, refund_order: RefundOrder|null} $snapshot
     * @return array<string, mixed>
     */
    private function formatOrderSnapshot(array $snapshot): array
    {
        /** @var BizOrder|null $bizOrder */
        $bizOrder = $snapshot['biz_order'];
        $payOrder = $snapshot['pay_order'];
        /** @var RefundOrder|null $refundOrder */
        $refundOrder = $snapshot['refund_order'];

        return [
            'biz_order' => $bizOrder ? [
                'biz_no' => (string) $bizOrder->biz_no,
                'merchant_order_no' => (string) $bizOrder->merchant_order_no,
                'status' => (int) $bizOrder->status,
                'active_pay_no' => (string) ($bizOrder->active_pay_no ?? ''),
                'paid_amount' => (int) ($bizOrder->paid_amount ?? 0),
                'refund_amount' => (int) ($bizOrder->refund_amount ?? 0),
            ] : null,
            'pay_order' => $payOrder ? [
                'pay_no' => (string) $payOrder->pay_no,
                'biz_no' => (string) $payOrder->biz_no,
                'status' => (int) $payOrder->status,
                'channel_order_no' => (string) ($payOrder->channel_order_no ?? ''),
                'channel_trade_no' => (string) ($payOrder->channel_trade_no ?? ''),
                'callback_status' => (int) ($payOrder->callback_status ?? 0),
                'refund_status' => (int) ($payOrder->refund_status ?? 0),
                'ext_json' => (array) ($payOrder->ext_json ?? []),
            ] : null,
            'refund_order' => $refundOrder ? $this->formatRefundOrder($refundOrder) : null,
        ];
    }

    /**
     * 格式化退款单。
     *
     * @return array<string, mixed>
     */
    private function formatRefundOrder(RefundOrder $refundOrder): array
    {
        return [
            'refund_no' => (string) $refundOrder->refund_no,
            'merchant_refund_no' => (string) $refundOrder->merchant_refund_no,
            'pay_no' => (string) $refundOrder->pay_no,
            'status' => (int) $refundOrder->status,
            'channel_refund_no' => (string) ($refundOrder->channel_refund_no ?? ''),
            'refund_amount' => (int) ($refundOrder->refund_amount ?? 0),
        ];
    }

    /**
     * 格式化回调日志。
     *
     * @return array<string, mixed>|null
     */
    private function formatCallbackLog(?PayCallbackLog $log): ?array
    {
        if (!$log) {
            return null;
        }

        return [
            'pay_no' => (string) $log->pay_no,
            'verify_status' => (int) $log->verify_status,
            'process_status' => (int) $log->process_status,
            'created_at' => FormatHelper::dateTime($log->created_at),
        ];
    }

    /**
     * 格式化商户通知任务。
     *
     * @return array<string, mixed>|null
     */
    private function formatNotifyTask(?NotifyTask $task): ?array
    {
        if (!$task) {
            return null;
        }

        return [
            'notify_no' => (string) $task->notify_no,
            'status' => (int) $task->status,
            'retry_count' => (int) $task->retry_count,
            'notify_url' => (string) $task->notify_url,
            'last_response' => (string) ($task->last_response ?? ''),
        ];
    }

    /**
     * 描述 HTTP 响应。
     *
     * @return array<string, mixed>
     */
    private function describeHttpResponse(Response $response): array
    {
        $body = method_exists($response, 'rawBody')
            ? $response->rawBody()
            : (method_exists($response, 'getContent') ? (string) $response->getContent() : '');
        $location = method_exists($response, 'getHeader') ? $response->getHeader('Location') : null;
        if (is_array($location)) {
            $location = $location[0] ?? null;
        }

        return [
            'status' => method_exists($response, 'getStatusCode') ? $response->getStatusCode() : 200,
            'location' => $location,
            'body' => $this->limitString((string) $body, 300),
        ];
    }

    /**
     * 输出 callback 返回值。
     */
    private function stringifyCallbackResult(string|Response $result): string
    {
        if ($result instanceof Response) {
            return FormatHelper::json($this->describeHttpResponse($result));
        }

        return trim($result);
    }

    /**
     * 解析 JSON 响应。
     *
     * @return array<string, mixed>
     */
    private function decodeJsonResponse(string $body): array
    {
        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : ['raw' => $body];
    }

    /**
     * 解析站点地址。
     */
    private function resolveSiteUrl(): string
    {
        $siteUrl = trim((string) sys_config('site_url'));
        return $siteUrl !== '' ? rtrim($siteUrl, '/') : 'http://localhost:8787';
    }

    /**
     * 解析平台公钥。
     */
    private function resolvePlatformPublicKey(): string
    {
        $publicKey = trim((string) config('epay.v2.platform_public_key', ''));
        if ($publicKey === '') {
            throw new CommandException('平台公钥未配置');
        }

        return $publicKey;
    }

    /**
     * 写入 PEM 文件。
     */
    private function writePemFile(string $relativePath, string $content): string
    {
        $path = runtime_path($relativePath);
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        file_put_contents($path, trim($content) . PHP_EOL);

        return $path;
    }

    /**
     * 构建订单号。
     */
    private function buildOrderNo(string $prefix): string
    {
        return strtoupper($prefix) . date('YmdHis') . substr(md5((string) microtime(true) . $prefix), 0, 8);
    }

    /**
     * 构建退款单号。
     */
    private function buildRefundNo(string $prefix): string
    {
        return strtoupper($prefix) . date('YmdHis') . substr(md5((string) microtime(true) . $prefix . 'refund'), 0, 8);
    }

    /**
     * 当前时间对象。
     */
    private function nowDateTime(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now');
    }

    /**
     * 断言条件。
     */
    private function assertTrue(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new CommandException($message);
        }
    }

    /**
     * 初始化日志文件。
     */
    private function initLogFile(): void
    {
        $dir = runtime_path('logs/epay-mock-chain');
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $this->logFile = $dir . DIRECTORY_SEPARATOR . 'epay-mock-chain-' . date('Ymd-His') . '.log';
    }

    /**
     * 输出文本日志。
     */
    private function log(OutputInterface $output, string $message): void
    {
        $line = '[' . date('Y-m-d H:i:s') . '] ' . $message;
        $output->writeln($line);
        file_put_contents($this->logFile, $line . PHP_EOL, FILE_APPEND);
    }

    /**
     * 输出 JSON 日志。
     *
     * @param mixed $data 任意数据
     */
    private function logJson(OutputInterface $output, string $title, mixed $data): void
    {
        $this->log($output, $title . ':');
        $json = FormatHelper::json($data);
        foreach (preg_split('/\r\n|\r|\n/', $json) ?: [] as $line) {
            $this->log($output, '  ' . $line);
        }
    }

    /**
     * 截断文本。
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
     * 格式化异常。
     */
    private function formatThrowable(Throwable $e): string
    {
        $data = method_exists($e, 'getData') ? $e->getData() : [];
        $suffix = is_array($data) && $data !== []
            ? ' ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : '';

        return $e::class . '：' . $e->getMessage() . $suffix;
    }

    /**
     * 读取字符串选项。
     */
    private function optionString(InputInterface $input, string $name, string $default = ''): string
    {
        $value = $input->getOption($name);
        return $value === null || $value === false ? $default : (is_string($value) ? $value : (string) $value);
    }

    /**
     * 解析容器实例。
     */
    private function resolve(string $class): object
    {
        try {
            $instance = container_get($class);
        } catch (Throwable $e) {
            throw new CommandException('无法解析 ' . $class, 50002, $e);
        }

        if (!is_object($instance)) {
            throw new CommandException('解析结果不是对象: ' . $class);
        }

        return $instance;
    }
}
