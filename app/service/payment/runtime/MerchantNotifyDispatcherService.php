<?php

namespace app\service\payment\runtime;

use app\common\base\BaseService;
use app\common\constant\AuthConstant;
use app\common\constant\EpayProtocolConstant;
use app\common\constant\EventConstant;
use app\common\constant\NotifyConstant;
use app\common\util\FormatHelper;
use app\exception\ResourceNotFoundException;
use app\exception\ValidationException;
use app\model\payment\BizOrder;
use app\model\payment\NotifyTask;
use app\model\payment\PayOrder;
use app\model\payment\RefundOrder;
use app\model\payment\SettlementOrder;
use app\repository\merchant\credential\MerchantApiCredentialRepository;
use app\repository\payment\notify\NotifyTaskRepository;
use app\repository\payment\trade\BizOrderRepository;
use app\repository\payment\trade\PayOrderRepository;
use app\service\payment\config\PaymentTypeService;
use app\service\payment\epay\EpaySignerManager;
use app\service\payment\order\PayOrderRiskControlService;
use GuzzleHttp\Client;
use RuntimeException;
use support\Log;
use Throwable;
use Webman\Event\Event;

/**
 * 商户异步通知派发服务。
 *
 * 负责生成 ePay 兼容通知参数、入队通知任务并实际向商户 notify_url 发起回调。
 */
class MerchantNotifyDispatcherService extends BaseService
{
    private Client $httpClient;

    public function __construct(
        protected NotifyService $notifyService,
        protected NotifyTaskRepository $notifyTaskRepository,
        protected BizOrderRepository $bizOrderRepository,
        protected PayOrderRepository $payOrderRepository,
        protected MerchantApiCredentialRepository $merchantApiCredentialRepository,
        protected PaymentTypeService $paymentTypeService,
        protected EpaySignerManager $signerManager,
        protected PaymentQueueService $paymentQueueService,
        protected PayOrderRiskControlService $payOrderRiskControlService
    ) {
        $this->httpClient = new Client([
            'timeout' => 10,
            'connect_timeout' => 10,
            'verify' => true,
            'http_errors' => false,
        ]);
    }

    /**
     * 为支付成功创建通知任务。
     *
     * @param PayOrder $payOrder 支付单
     * @param BizOrder|null $bizOrder 业务单
     * @return NotifyTask|null 通知任务；没有 notify_url 时返回 null
     * @throws ValidationException
     */
    public function enqueuePaySuccess(PayOrder $payOrder, ?BizOrder $bizOrder = null): ?NotifyTask
    {
        if (!$this->merchantNotifyEnabled()) {
            return null;
        }

        if ($this->payOrderRiskControlService->isFrozen($payOrder)) {
            return null;
        }

        $bizOrder ??= $this->bizOrderRepository->findByBizNo((string) $payOrder->biz_no);
        $notifyUrl = trim((string) ($payOrder->notify_url ?: ($bizOrder?->notify_url ?? '')));
        if ($notifyUrl === '') {
            return null;
        }

        $task = $this->notifyService->enqueueMerchantNotify([
            'merchant_id' => (int) $payOrder->merchant_id,
            'merchant_group_id' => (int) $payOrder->merchant_group_id,
            'event_type' => NotifyConstant::EVENT_PAY_SUCCESS,
            'ref_no' => (string) $payOrder->pay_no,
            'biz_no' => (string) $payOrder->biz_no,
            'pay_no' => (string) $payOrder->pay_no,
            'notify_url' => $notifyUrl,
            'notify_data' => $this->buildPaySuccessPayload($payOrder, $bizOrder),
            'status' => NotifyConstant::TASK_STATUS_PENDING,
        ]);

        return $task;
    }

    /**
     * 后台手动重新创建支付成功通知任务。
     *
     * 与自动通知不同，手动重新通知不复用历史 notify_task，避免成功过的任务被
     * dispatchTask 幂等返回，导致后台点击后没有真正再次通知商户。
     *
     * @param PayOrder $payOrder 支付单
     * @param BizOrder|null $bizOrder 业务单
     * @param int $adminId 管理员ID
     * @param string $reason 操作原因
     * @return NotifyTask|null 通知任务；没有 notify_url 时返回 null
     */
    public function enqueueManualPaySuccess(PayOrder $payOrder, ?BizOrder $bizOrder = null, int $adminId = 0, string $reason = ''): ?NotifyTask
    {
        if (!$this->merchantNotifyEnabled()) {
            return null;
        }

        $this->payOrderRiskControlService->assertNotFrozen($payOrder, '重新通知');

        $bizOrder ??= $this->bizOrderRepository->findByBizNo((string) $payOrder->biz_no);
        $notifyUrl = trim((string) ($payOrder->notify_url ?: ($bizOrder?->notify_url ?? '')));
        if ($notifyUrl === '') {
            return null;
        }

        return $this->notifyService->enqueueMerchantNotify([
            'merchant_id' => (int) $payOrder->merchant_id,
            'merchant_group_id' => (int) $payOrder->merchant_group_id,
            'event_type' => NotifyConstant::EVENT_PAY_SUCCESS,
            'ref_no' => (string) $payOrder->pay_no,
            'biz_no' => (string) $payOrder->biz_no,
            'pay_no' => (string) $payOrder->pay_no,
            'notify_url' => $notifyUrl,
            'notify_data' => $this->buildPaySuccessPayload($payOrder, $bizOrder),
            'status' => NotifyConstant::TASK_STATUS_PENDING,
        ], false);
    }

    /**
     * 为支付成功创建通知任务，并立即尝试派发一次。
     *
     * @param PayOrder $payOrder 支付单
     * @param BizOrder|null $bizOrder 业务单
     * @return NotifyTask|null 通知任务；没有 notify_url 时返回 null
     * @throws ValidationException
     */
    public function enqueueAndDispatchPaySuccess(PayOrder $payOrder, ?BizOrder $bizOrder = null): ?NotifyTask
    {
        $task = $this->enqueuePaySuccess($payOrder, $bizOrder);
        return $task ? $this->dispatchTask($task) : null;
    }

    /**
     * 为退款成功创建通知任务。
     *
     * @param RefundOrder $refundOrder 退款单
     * @return NotifyTask|null 通知任务；没有 notify_url 时返回 null
     */
    public function enqueueRefundSuccess(RefundOrder $refundOrder): ?NotifyTask
    {
        if (!$this->merchantNotifyEnabled()) {
            return null;
        }

        $bizOrder = $this->bizOrderRepository->findByBizNo((string) $refundOrder->biz_no);
        $payOrder = $this->payOrderRepository->findByPayNo((string) $refundOrder->pay_no);
        if ($payOrder && $this->payOrderRiskControlService->isFrozen($payOrder)) {
            return null;
        }

        $notifyUrl = trim((string) ($payOrder?->notify_url ?: ($bizOrder?->notify_url ?? '')));
        if ($notifyUrl === '') {
            return null;
        }

        $task = $this->notifyService->enqueueMerchantNotify([
            'merchant_id' => (int) $refundOrder->merchant_id,
            'merchant_group_id' => (int) $refundOrder->merchant_group_id,
            'event_type' => NotifyConstant::EVENT_REFUND_SUCCESS,
            'ref_no' => (string) $refundOrder->refund_no,
            'biz_no' => (string) $refundOrder->biz_no,
            'pay_no' => (string) $refundOrder->pay_no,
            'notify_url' => $notifyUrl,
            'notify_data' => $this->buildRefundSuccessPayload($refundOrder, $payOrder, $bizOrder),
            'status' => NotifyConstant::TASK_STATUS_PENDING,
        ]);

        return $task;
    }

    /**
     * 为退款成功创建通知任务，并立即尝试派发一次。
     *
     * @param RefundOrder $refundOrder 退款单
     * @return NotifyTask|null 通知任务；没有 notify_url 时返回 null
     */
    public function enqueueAndDispatchRefundSuccess(RefundOrder $refundOrder): ?NotifyTask
    {
        $task = $this->enqueueRefundSuccess($refundOrder);
        return $task ? $this->dispatchTask($task) : null;
    }

    /**
     * 为清算完成创建通知任务。
     *
     * 当前清算单只有在 ext_json.notify_url 明确存在时才通知商户。
     *
     * @param SettlementOrder $settlementOrder 清算单
     * @return NotifyTask|null 通知任务；没有 notify_url 时返回 null
     */
    public function enqueueSettlementSuccess(SettlementOrder $settlementOrder): ?NotifyTask
    {
        if (!$this->merchantNotifyEnabled()) {
            return null;
        }

        $extJson = (array) ($settlementOrder->ext_json ?? []);
        $notifyUrl = trim((string) ($extJson['notify_url'] ?? ''));
        if ($notifyUrl === '') {
            return null;
        }

        $task = $this->notifyService->enqueueMerchantNotify([
            'merchant_id' => (int) $settlementOrder->merchant_id,
            'merchant_group_id' => (int) $settlementOrder->merchant_group_id,
            'event_type' => NotifyConstant::EVENT_SETTLEMENT_SUCCESS,
            'ref_no' => (string) $settlementOrder->settle_no,
            'biz_no' => '',
            'pay_no' => '',
            'notify_url' => $notifyUrl,
            'notify_data' => $this->buildSettlementSuccessPayload($settlementOrder),
            'status' => NotifyConstant::TASK_STATUS_PENDING,
        ]);

        return $task;
    }

    /**
     * 为清算完成创建通知任务，并立即尝试派发一次。
     *
     * 当前清算单只有在 ext_json.notify_url 明确存在时才通知商户。
     *
     * @param SettlementOrder $settlementOrder 清算单
     * @return NotifyTask|null 通知任务；没有 notify_url 时返回 null
     */
    public function enqueueAndDispatchSettlementSuccess(SettlementOrder $settlementOrder): ?NotifyTask
    {
        $task = $this->enqueueSettlementSuccess($settlementOrder);
        return $task ? $this->dispatchTask($task) : null;
    }

    /**
     * 派发单个通知任务。
     *
     * @param NotifyTask|string $task 通知任务模型或通知号
     * @param bool $throwOnFailure 通知失败时是否抛出异常
     * @return NotifyTask 最新通知任务
     * @throws ResourceNotFoundException
     */
    public function dispatchTask(NotifyTask|string $task, bool $throwOnFailure = false): NotifyTask
    {
        $task = $this->resolveTask($task);
        if ((int) $task->status === NotifyConstant::TASK_STATUS_SUCCESS) {
            return $task;
        }

        if (!$this->merchantNotifyEnabled()) {
            $task->last_response = '商户通知已关闭，暂停派发';
            $task->save();

            return $task->refresh();
        }

        if ($this->pauseTaskIfPayOrderFrozen($task)) {
            return $task->refresh();
        }

        $eventName = EventConstant::MERCHANT_NOTIFY_FAILED;
        $failureMessage = '';
        try {
            $timeout = $this->intConfig('pay_notify_request_timeout_seconds', 10, 1, 60);
            $response = $this->httpClient->request('GET', (string) $task->notify_url, [
                'query' => (array) ($task->notify_data ?? []),
                'timeout' => $timeout,
                'connect_timeout' => $timeout,
            ]);
            $body = trim((string) $response->getBody());

            if (strtolower($body) === NotifyConstant::MERCHANT_SUCCESS_RESPONSE) {
                $task = $this->notifyService->markTaskSuccess((string) $task->notify_no, [
                    'last_notify_at' => $this->now(),
                    'last_response' => $this->truncateResponse($body),
                ]);
                $eventName = EventConstant::MERCHANT_NOTIFY_SUCCEEDED;
            } else {
                $failureMessage = $body !== '' ? $body : '商户未返回 success';
                $task = $this->notifyService->markTaskFailed((string) $task->notify_no, [
                    'last_notify_at' => $this->now(),
                    'last_response' => $this->truncateResponse($failureMessage),
                ]);
            }
        } catch (Throwable $e) {
            $failureMessage = $e->getMessage();
            Log::warning(sprintf(
                '[MerchantNotify] 派发失败 notify_no=%s pay_no=%s error=%s',
                (string) $task->notify_no,
                (string) $task->pay_no,
                $e->getMessage()
            ));

            $task = $this->notifyService->markTaskFailed((string) $task->notify_no, [
                'last_notify_at' => $this->now(),
                'last_response' => $this->truncateResponse($e->getMessage()),
            ]);
        }

        $this->dispatchNotifyTaskEvent($eventName, $task);
        if ($throwOnFailure && (int) $task->status !== NotifyConstant::TASK_STATUS_SUCCESS) {
            throw new RuntimeException($failureMessage !== '' ? $failureMessage : '商户通知失败');
        }

        return $task;
    }

    /**
     * 批量投递到期重试任务。
     *
     * 到期任务只重新入队，不在维护进程里同步请求商户，避免定时进程被
     * 商户 notify_url 的网络耗时拖住。
     *
     * @param int $limit 最大处理数量
     * @return int 实际投递数量
     */
    public function dispatchRetryableTasks(int $limit = 100): int
    {
        if (!$this->merchantNotifyEnabled()) {
            return 0;
        }

        $limit = max(1, $limit);
        $count = 0;

        foreach ($this->notifyService->listRetryableTasks() as $task) {
            if ($count >= $limit) {
                break;
            }

            try {
                $this->paymentQueueService->sendMerchantNotify((string) $task->notify_no);
                $count++;
            } catch (Throwable $e) {
                Log::warning(sprintf(
                    '[MerchantNotify] 重试任务投递队列失败 notify_no=%s error=%s',
                    (string) $task->notify_no,
                    $e->getMessage()
                ));
            }
        }

        return $count;
    }

    /**
     * 构建支付成功通知参数。
     *
     * @param PayOrder $payOrder 支付单
     * @param BizOrder|null $bizOrder 业务单
     * @return array<string, mixed>
     * @throws ValidationException
     */
    private function buildPaySuccessPayload(PayOrder $payOrder, ?BizOrder $bizOrder = null): array
    {
        return match ($this->resolveProtocolVersion($payOrder, $bizOrder)) {
            EpayProtocolConstant::VERSION_V1 => $this->buildV1PaySuccessPayload($payOrder, $bizOrder),
            EpayProtocolConstant::VERSION_V2 => $this->buildV2PaySuccessPayload($payOrder, $bizOrder),
            default => throw new ValidationException('订单未记录协议版本，无法发送商户通知'),
        };
    }

    /**
     * 构建退款成功通知参数。
     *
     * @param RefundOrder $refundOrder 退款单
     * @param PayOrder|null $payOrder 支付单
     * @param BizOrder|null $bizOrder 业务单
     * @return array<string, mixed>
     */
    private function buildRefundSuccessPayload(RefundOrder $refundOrder, ?PayOrder $payOrder = null, ?BizOrder $bizOrder = null): array
    {
        $payOrder ??= $this->payOrderRepository->findByPayNo((string) $refundOrder->pay_no);
        $bizOrder ??= $this->bizOrderRepository->findByBizNo((string) $refundOrder->biz_no);
        $payload = $payOrder
            ? $this->buildBasePaySuccessPayload($payOrder, $bizOrder)
            : [
                'pid' => (int) $refundOrder->merchant_id,
                'trade_no' => (string) $refundOrder->pay_no,
                'out_trade_no' => (string) ($bizOrder?->merchant_order_no ?? ''),
                'money' => FormatHelper::amount((int) $refundOrder->refund_amount),
            ];

        $payload['trade_status'] = NotifyConstant::EVENT_REFUND_SUCCESS;
        $payload['refund_no'] = (string) $refundOrder->refund_no;
        $payload['out_refund_no'] = (string) $refundOrder->merchant_refund_no;
        $payload['refundmoney'] = FormatHelper::amount((int) $refundOrder->refund_amount);
        $payload['reducemoney'] = FormatHelper::amount((int) ($bizOrder?->refund_amount ?? $refundOrder->refund_amount));
        $payload['endtime'] = FormatHelper::dateTime($refundOrder->succeeded_at ?: $this->now());

        return $this->signEventPayload($payload, $this->resolveProtocolVersion($payOrder, $bizOrder), (int) $refundOrder->merchant_id);
    }

    /**
     * 构建清算完成通知参数。
     *
     * @param SettlementOrder $settlementOrder 清算单
     * @return array<string, mixed>
     */
    private function buildSettlementSuccessPayload(SettlementOrder $settlementOrder): array
    {
        $payload = [
            'pid' => (int) $settlementOrder->merchant_id,
            'trade_status' => NotifyConstant::EVENT_SETTLEMENT_SUCCESS,
            'settle_no' => (string) $settlementOrder->settle_no,
            'cycle_type' => (int) $settlementOrder->cycle_type,
            'cycle_key' => (string) $settlementOrder->cycle_key,
            'money' => FormatHelper::amount((int) $settlementOrder->accounted_amount),
            'gross_money' => FormatHelper::amount((int) $settlementOrder->gross_amount),
            'fee_money' => FormatHelper::amount((int) $settlementOrder->fee_amount),
            'endtime' => FormatHelper::dateTime($settlementOrder->completed_at ?: $this->now()),
        ];
        $extJson = (array) ($settlementOrder->ext_json ?? []);
        $protocol = strtolower(trim((string) ($extJson['_protocol_version'] ?? EpayProtocolConstant::VERSION_V2)));

        return $this->signEventPayload($payload, $protocol, (int) $settlementOrder->merchant_id);
    }

    /**
     * 解析协议版本。
     *
     * @param PayOrder $payOrder 支付单
     * @param BizOrder|null $bizOrder 业务单
     * @return string
     */
    private function resolveProtocolVersion(?PayOrder $payOrder = null, ?BizOrder $bizOrder = null): string
    {
        $payExtJson = (array) (($payOrder?->ext_json) ?? []);
        $bizExtJson = (array) (($bizOrder?->ext_json) ?? []);
        $version = strtolower(trim((string) ($payExtJson['_protocol_version'] ?? $bizExtJson['_protocol_version'] ?? '')));

        return in_array($version, [EpayProtocolConstant::VERSION_V1, EpayProtocolConstant::VERSION_V2], true) ? $version : '';
    }

    /**
     * 构建 V1 成功通知。
     *
     * @param PayOrder $payOrder 支付单
     * @param BizOrder|null $bizOrder 业务单
     * @return array<string, mixed>
     * @throws ValidationException
     */
    private function buildV1PaySuccessPayload(PayOrder $payOrder, ?BizOrder $bizOrder = null): array
    {
        $credential = $this->merchantApiCredentialRepository->findByMerchantId((int) $payOrder->merchant_id);
        $apiKey = trim((string) ($credential?->api_key ?? ''));
        if ($apiKey === '') {
            throw new ValidationException('商户 API Key 未配置，无法发送 V1 通知');
        }

        $payload = $this->buildBasePaySuccessPayload($payOrder, $bizOrder);
        $payload['trade_status'] = NotifyConstant::EPAY_TRADE_STATUS_SUCCESS;
        $payload['sign_type'] = AuthConstant::API_SIGN_NAME_MD5;
        $payload['sign'] = $this->signerManager->sign($this->signPayload($payload), AuthConstant::API_SIGN_NAME_MD5, $apiKey);

        return $payload;
    }

    /**
     * 构建 V2 成功通知。
     *
     * @param PayOrder $payOrder 支付单
     * @param BizOrder|null $bizOrder 业务单
     * @return array<string, mixed>
     * @throws ValidationException
     */
    private function buildV2PaySuccessPayload(PayOrder $payOrder, ?BizOrder $bizOrder = null): array
    {
        $privateKey = trim((string) config('epay.v2.platform_private_key', ''));
        if ($privateKey === '') {
            throw new ValidationException('平台 RSA 私钥未配置，无法发送 V2 通知');
        }

        $signType = (string) config('epay.v2.sign_type', AuthConstant::API_SIGN_NAME_RSA);
        $payload = $this->buildBasePaySuccessPayload($payOrder, $bizOrder);
        $payload['trade_status'] = NotifyConstant::EPAY_TRADE_STATUS_SUCCESS;
        $payload['addtime'] = FormatHelper::dateTime($payOrder->created_at);
        $payload['endtime'] = FormatHelper::dateTime($payOrder->paid_at ?: $this->now());
        $payload['timestamp'] = (string) time();
        $payload['sign_type'] = $signType;
        $payload['sign'] = $this->signerManager->sign($this->signPayload($payload), $signType, $privateKey);

        return $payload;
    }

    /**
     * 按协议签名事件通知参数。
     *
     * @param array<string, mixed> $payload 通知参数
     * @param string $protocol 协议版本
     * @param int $merchantId 商户ID
     * @return array<string, mixed>
     */
    private function signEventPayload(array $payload, string $protocol, int $merchantId): array
    {
        if ($protocol === EpayProtocolConstant::VERSION_V1) {
            $credential = $this->merchantApiCredentialRepository->findByMerchantId($merchantId);
            $apiKey = trim((string) ($credential?->api_key ?? ''));
            if ($apiKey === '') {
                throw new ValidationException('商户 API Key 未配置，无法发送 V1 通知');
            }

            $payload['sign_type'] = AuthConstant::API_SIGN_NAME_MD5;
            $payload['sign'] = $this->signerManager->sign($this->signPayload($payload), AuthConstant::API_SIGN_NAME_MD5, $apiKey);

            return $payload;
        }

        $privateKey = trim((string) config('epay.v2.platform_private_key', ''));
        if ($privateKey === '') {
            throw new ValidationException('平台 RSA 私钥未配置，无法发送 V2 通知');
        }

        $signType = (string) config('epay.v2.sign_type', AuthConstant::API_SIGN_NAME_RSA);
        $payload['timestamp'] = (string) time();
        $payload['sign_type'] = $signType;
        $payload['sign'] = $this->signerManager->sign($this->signPayload($payload), $signType, $privateKey);

        return $payload;
    }

    /**
     * 构建 V1/V2 共用通知参数。
     *
     * @param PayOrder $payOrder 支付单
     * @param BizOrder|null $bizOrder 业务单
     * @return array<string, mixed>
     */
    private function buildBasePaySuccessPayload(PayOrder $payOrder, ?BizOrder $bizOrder = null): array
    {
        $bizOrder ??= $this->bizOrderRepository->findByBizNo((string) $payOrder->biz_no);
        $bizExtJson = (array) (($bizOrder?->ext_json) ?? []);
        $merchantExt = (array) ($bizExtJson['merchant'] ?? []);

        $payload = [
            'pid' => (int) $payOrder->merchant_id,
            'trade_no' => (string) $payOrder->pay_no,
            'out_trade_no' => (string) ($bizOrder?->merchant_order_no ?? ''),
            'type' => $this->paymentTypeService->resolveCodeById((int) $payOrder->pay_type_id),
            'name' => (string) ($bizOrder?->subject ?? ''),
            'money' => FormatHelper::amount((int) $payOrder->pay_amount),
        ];

        $param = $this->stringifyValue($merchantExt['param'] ?? '');
        if ($param !== '') {
            $payload['param'] = $param;
        }

        $buyer = $this->stringifyValue($merchantExt['buyer'] ?? '');
        if ($buyer !== '') {
            $payload['buyer'] = $buyer;
        }

        return $payload;
    }

    /**
     * 规整待签名参数。
     *
     * @param array<string, mixed> $payload 原始参数
     * @return array<string, mixed>
     */
    private function signPayload(array $payload): array
    {
        $params = $payload;
        unset($params['sign'], $params['sign_type']);

        return $params;
    }

    /**
     * 解析通知任务。
     *
     * @param NotifyTask|string $task 通知任务模型或通知号
     * @return NotifyTask
     * @throws ResourceNotFoundException
     */
    private function resolveTask(NotifyTask|string $task): NotifyTask
    {
        if ($task instanceof NotifyTask) {
            return $task;
        }

        $taskModel = $this->notifyTaskRepository->findByNotifyNo($task);
        if (!$taskModel) {
            throw new ResourceNotFoundException('通知任务不存在', ['notify_no' => $task]);
        }

        return $taskModel;
    }

    /**
     * 支付单冻结时暂停商户通知。
     *
     * 已经入队但尚未派发的任务也要在这里兜底拦截，避免冻结后队列消费者继续
     * 请求商户。任务保持原状态，解冻后可通过后台重新通知。
     *
     * @param NotifyTask $task 通知任务
     * @return bool 是否已暂停
     */
    private function pauseTaskIfPayOrderFrozen(NotifyTask $task): bool
    {
        $payNo = trim((string) ($task->pay_no ?? ''));
        if ($payNo === '') {
            return false;
        }

        $payOrder = $this->payOrderRepository->findByPayNo($payNo);
        if (!$payOrder || !$this->payOrderRiskControlService->isFrozen($payOrder)) {
            return false;
        }

        $task->last_response = '支付单已冻结，暂停商户通知';
        $task->save();

        return true;
    }

    /**
     * 发送商户通知任务事件。
     *
     * @param string $eventName 事件名称
     * @param NotifyTask $task 通知任务
     * @return void
     */
    private function dispatchNotifyTaskEvent(string $eventName, NotifyTask $task): void
    {
        Event::dispatch($eventName, [
            'notify_no' => (string) $task->notify_no,
            'event_type' => (string) $task->event_type,
            'ref_no' => (string) $task->ref_no,
            'pay_no' => (string) ($task->pay_no ?? ''),
            'notify_task' => $task,
        ]);
    }

    /**
     * 规范化任意值为字符串。
     *
     * @param mixed $value 原始值
     * @return string
     */
    private function stringifyValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_array($value) || is_object($value)) {
            $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return $json !== false ? $json : '';
        }

        return trim((string) $value);
    }

    /**
     * 截断响应内容，避免把超长 HTML 整段塞进日志字段。
     *
     * @param string $body 响应体
     * @param int $length 最大长度
     * @return string
     */
    private function truncateResponse(string $body, int $length = 1000): string
    {
        $body = trim($body);
        if ($body === '') {
            return '';
        }

        return mb_strlen($body) > $length ? mb_substr($body, 0, $length) : $body;
    }

    /**
     * 商户通知是否启用。
     *
     * @return bool 是否启用
     */
    private function merchantNotifyEnabled(): bool
    {
        return $this->boolConfig('pay_notify_enabled', true);
    }

    /**
     * 读取布尔配置。
     *
     * @param string $key 配置键
     * @param bool $default 默认值
     * @return bool 布尔值
     */
    private function boolConfig(string $key, bool $default): bool
    {
        $value = strtolower(trim((string) sys_config($key, $default ? '1' : '0')));

        return in_array($value, ['1', 'true', 'yes', 'on', 'enabled'], true);
    }

    /**
     * 读取整数配置。
     *
     * @param string $key 配置键
     * @param int $default 默认值
     * @param int $min 最小值
     * @param int $max 最大值
     * @return int 整数值
     */
    private function intConfig(string $key, int $default, int $min, int $max): int
    {
        $value = (int) sys_config($key, $default);

        return min($max, max($min, $value));
    }
}
