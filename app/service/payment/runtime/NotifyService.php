<?php

namespace app\service\payment\runtime;

use app\common\base\BaseService;
use app\common\constant\NotifyConstant;
use app\exception\ResourceNotFoundException;
use app\exception\ValidationException;
use app\common\util\FormatHelper;
use app\model\admin\ChannelNotifyLog;
use app\model\payment\NotifyTask;
use app\model\admin\PayCallbackLog;
use app\repository\ops\log\ChannelNotifyLogRepository;
use app\repository\payment\notify\NotifyTaskRepository;
use app\repository\ops\log\PayCallbackLogRepository;
use app\service\system\config\SystemConfigRuntimeService;

/**
 * 通知服务。
 *
 * 负责渠道通知日志、支付回调日志和商户通知任务的统一管理，核心目标是去重、留痕和可重试。
 *
 * @property ChannelNotifyLogRepository $channelNotifyLogRepository 渠道通知日志仓库
 * @property PayCallbackLogRepository $payCallbackLogRepository 支付回调日志仓库
 * @property NotifyTaskRepository $notifyTaskRepository 通知任务仓库
 */
class NotifyService extends BaseService
{
    /**
     * 构造方法。
     *
     * @param ChannelNotifyLogRepository $channelNotifyLogRepository 渠道通知日志仓库
     * @param PayCallbackLogRepository $payCallbackLogRepository 支付回调日志仓库
     * @param NotifyTaskRepository $notifyTaskRepository 通知任务仓库
     * @param SystemConfigRuntimeService $systemConfigRuntimeService 系统配置运行时服务
     * @return void
     */
    public function __construct(
        protected ChannelNotifyLogRepository $channelNotifyLogRepository,
        protected PayCallbackLogRepository $payCallbackLogRepository,
        protected NotifyTaskRepository $notifyTaskRepository,
        protected SystemConfigRuntimeService $systemConfigRuntimeService
    ) {
    }

    /**
     * 记录渠道通知日志。
     *
     * 同一通道、通知类型和业务单号只保留一条重复记录。
     *
     * @param array $input 通知数据
     * @return ChannelNotifyLog 渠道通知日志
     * @throws ValidationException
     */
    public function recordChannelNotify(array $input): ChannelNotifyLog
    {
        $channelId = (int) ($input['channel_id'] ?? 0);
        $notifyType = (int) ($input['notify_type'] ?? NotifyConstant::NOTIFY_TYPE_ASYNC);
        $bizNo = trim((string) ($input['biz_no'] ?? ''));

        if ($channelId <= 0 || $bizNo === '') {
            throw new ValidationException('渠道通知入参不完整', [
                'channel_id' => $channelId,
                'biz_no' => $bizNo,
            ]);
        }

        // 同一业务单如果已经记录过相同类型的通知，就直接复用旧日志，避免重复落库。
        if ($duplicate = $this->channelNotifyLogRepository->findDuplicate($channelId, $notifyType, $bizNo)) {
            return $duplicate;
        }

        return $this->channelNotifyLogRepository->create([
            'notify_no' => (string) ($input['notify_no'] ?? $this->generateNo('CNL')),
            'channel_id' => $channelId,
            'notify_type' => $notifyType,
            'biz_no' => $bizNo,
            'pay_no' => (string) ($input['pay_no'] ?? ''),
            'channel_request_no' => (string) ($input['channel_request_no'] ?? ''),
            'channel_trade_no' => (string) ($input['channel_trade_no'] ?? ''),
            'raw_payload' => $input['raw_payload'] ?? [],
            'verify_status' => (int) ($input['verify_status'] ?? NotifyConstant::VERIFY_STATUS_UNKNOWN),
            'process_status' => (int) ($input['process_status'] ?? NotifyConstant::PROCESS_STATUS_PENDING),
            'retry_count' => (int) ($input['retry_count'] ?? 0),
            'next_retry_at' => $input['next_retry_at'] ?? null,
            'last_error' => (string) ($input['last_error'] ?? ''),
        ]);
    }

    /**
     * 记录支付回调日志。
     *
     * 渠道回调是排障证据，每次请求都要留痕；重复识别交给 request_hash，
     * 不在日志层吞掉后续通知。
     *
     * @param array $input 回调数据
     * @return PayCallbackLog 支付回调日志
     * @throws ValidationException
     */
    public function recordPayCallback(array $input): PayCallbackLog
    {
        $payNo = trim((string) ($input['pay_no'] ?? ''));
        if ($payNo === '') {
            throw new ValidationException('pay_no 不能为空', ['pay_no' => $payNo]);
        }

        $callbackType = (int) ($input['callback_type'] ?? NotifyConstant::CALLBACK_TYPE_ASYNC);
        $requestData = $input['request_data'] ?? [];

        return $this->payCallbackLogRepository->create([
            'pay_no' => $payNo,
            'channel_id' => (int) ($input['channel_id'] ?? 0),
            'callback_type' => $callbackType,
            'request_data' => $requestData,
            'request_hash' => $this->payloadHash($requestData),
            'verify_status' => (int) ($input['verify_status'] ?? NotifyConstant::VERIFY_STATUS_UNKNOWN),
            'process_status' => (int) ($input['process_status'] ?? NotifyConstant::PROCESS_STATUS_PENDING),
            'process_result' => $input['process_result'] ?? [],
            'created_at' => $input['created_at'] ?? $this->now(),
        ]);
    }

    /**
     * 创建商户通知任务。
     *
     * 通常用于支付成功、退款成功或清算完成后的商户异步通知。
     *
     * @param array $input 通知任务数据
     * @return NotifyTask 通知任务
     */
    public function enqueueMerchantNotify(array $input): NotifyTask
    {
        $eventType = (string) ($input['event_type'] ?? NotifyConstant::EVENT_PAY_SUCCESS);
        $refNo = (string) ($input['ref_no'] ?? $input['pay_no'] ?? '');
        if ($refNo === '') {
            throw new ValidationException('通知事件引用单号不能为空');
        }

        $existing = $this->notifyTaskRepository->findByEventRef($eventType, $refNo);
        if ($existing) {
            return $existing;
        }

        return $this->notifyTaskRepository->create([
            'notify_no' => (string) ($input['notify_no'] ?? $this->generateNo('NTF')),
            'event_type' => $eventType,
            'ref_no' => $refNo,
            'merchant_id' => (int) ($input['merchant_id'] ?? 0),
            'merchant_group_id' => (int) ($input['merchant_group_id'] ?? 0),
            'biz_no' => (string) ($input['biz_no'] ?? ''),
            'pay_no' => (string) ($input['pay_no'] ?? ''),
            'notify_url' => (string) ($input['notify_url'] ?? ''),
            'notify_data' => $input['notify_data'] ?? [],
            'status' => (int) ($input['status'] ?? NotifyConstant::TASK_STATUS_PENDING),
            'retry_count' => (int) ($input['retry_count'] ?? 0),
            'next_retry_at' => $input['next_retry_at'] ?? $this->nextRetryAt(0),
            'last_notify_at' => $input['last_notify_at'] ?? null,
            'last_response' => (string) ($input['last_response'] ?? ''),
        ]);
    }

    /**
     * 标记商户通知成功。
     *
     * 成功后会刷新最后通知时间和响应内容。
     *
     * @param string $notifyNo 通知号
     * @param array $input 附加数据
     * @return NotifyTask 通知任务
     * @throws ResourceNotFoundException
     */
    public function markTaskSuccess(string $notifyNo, array $input = []): NotifyTask
    {
        $task = $this->notifyTaskRepository->findByNotifyNo($notifyNo);
        if (!$task) {
            throw new ResourceNotFoundException('通知任务不存在', ['notify_no' => $notifyNo]);
        }

        $task->status = NotifyConstant::TASK_STATUS_SUCCESS;
        $task->last_notify_at = $input['last_notify_at'] ?? $this->now();
        $task->last_response = (string) ($input['last_response'] ?? '');
        $task->save();

        return $task->refresh();
    }

    /**
     * 标记商户通知失败并计算下次重试时间。
     *
     * 失败后会累计重试次数，并根据退避策略生成下一次重试时间。
     *
     * @param string $notifyNo 通知号
     * @param array $input 附加数据
     * @return NotifyTask 通知任务
     * @throws ResourceNotFoundException
     */
    public function markTaskFailed(string $notifyNo, array $input = []): NotifyTask
    {
        $task = $this->notifyTaskRepository->findByNotifyNo($notifyNo);
        if (!$task) {
            throw new ResourceNotFoundException('通知任务不存在', ['notify_no' => $notifyNo]);
        }

        // 每次失败都累计一次重试，并根据新的次数重新计算下一次触发时间。
        $retryCount = (int) $task->retry_count + 1;
        $task->status = NotifyConstant::TASK_STATUS_FAILED;
        $task->retry_count = $retryCount;
        $task->last_notify_at = $input['last_notify_at'] ?? $this->now();
        $task->last_response = (string) ($input['last_response'] ?? '');
        $task->next_retry_at = $retryCount >= $this->retryLimit() ? null : $this->nextRetryAt($retryCount);
        $task->save();

        return $task->refresh();
    }

    /**
     * 获取待重试任务。
     *
     * @return iterable 待重试任务集合
     */
    public function listRetryableTasks(): iterable
    {
        return $this->notifyTaskRepository->listRetryable(NotifyConstant::TASK_STATUS_FAILED);
    }

    /**
     * 根据重试次数计算下次重试时间。
     *
     * 使用简单的指数退避思路控制重试频率。
     *
     * @param int $retryCount 重试次数
     * @return string 下次重试时间
     */
    private function nextRetryAt(int $retryCount): string
    {
        $retryCount = max(0, $retryCount);
        $baseDelay = $this->retryIntervalMinutes() * 60;
        $delay = match (true) {
            $retryCount <= 0 => 60,
            $retryCount === 1 => $baseDelay,
            $retryCount === 2 => $baseDelay * 3,
            default => $baseDelay * 6,
        };

        return FormatHelper::timestamp(time() + $delay);
    }

    /**
     * 获取商户通知最大重试次数。
     *
     * @return int 最大重试次数
     */
    private function retryLimit(): int
    {
        return max(1, (int) $this->systemConfigRuntimeService->get('pay_notify_retry_limit', 3));
    }

    /**
     * 获取商户通知重试基础间隔。
     *
     * @return int 基础间隔，单位分钟
     */
    private function retryIntervalMinutes(): int
    {
        return max(1, (int) $this->systemConfigRuntimeService->get('pay_notify_retry_interval', 10));
    }

    /**
     * 生成稳定的载荷摘要，用于后台识别重复通知。
     *
     * @param mixed $payload 原始载荷
     * @return string SHA-256 摘要
     */
    private function payloadHash(mixed $payload): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);

        return hash('sha256', $json !== false ? $json : serialize($payload));
    }
}






