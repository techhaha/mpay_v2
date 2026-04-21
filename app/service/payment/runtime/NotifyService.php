<?php

namespace app\service\payment\runtime;

use app\common\base\BaseService;
use app\common\constant\NotifyConstant;
use app\common\util\FormatHelper;
use app\model\admin\ChannelNotifyLog;
use app\model\payment\NotifyTask;
use app\model\admin\PayCallbackLog;
use app\repository\ops\log\ChannelNotifyLogRepository;
use app\repository\payment\notify\NotifyTaskRepository;
use app\repository\ops\log\PayCallbackLogRepository;

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
     * @return void
     */
    public function __construct(
        protected ChannelNotifyLogRepository $channelNotifyLogRepository,
        protected PayCallbackLogRepository $payCallbackLogRepository,
        protected NotifyTaskRepository $notifyTaskRepository
    ) {
    }

    /**
     * 记录渠道通知日志。
     *
     * 同一通道、通知类型和业务单号只保留一条重复记录。
     *
     * @param array $input 通知数据
     * @return ChannelNotifyLog 渠道通知日志
     * @throws InvalidArgumentException
     */
    public function recordChannelNotify(array $input): ChannelNotifyLog
    {
        $channelId = (int) ($input['channel_id'] ?? 0);
        $notifyType = (int) ($input['notify_type'] ?? NotifyConstant::NOTIFY_TYPE_ASYNC);
        $bizNo = trim((string) ($input['biz_no'] ?? ''));

        if ($channelId <= 0 || $bizNo === '') {
            throw new \InvalidArgumentException('渠道通知入参不完整');
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
     * 以支付单号 + 回调类型作为去重依据。
     *
     * @param array $input 回调数据
     * @return PayCallbackLog 支付回调日志
     * @throws InvalidArgumentException
     */
    public function recordPayCallback(array $input): PayCallbackLog
    {
        $payNo = trim((string) ($input['pay_no'] ?? ''));
        if ($payNo === '') {
            throw new \InvalidArgumentException('pay_no 不能为空');
        }

        $callbackType = (int) ($input['callback_type'] ?? NotifyConstant::CALLBACK_TYPE_ASYNC);
        $logs = $this->payCallbackLogRepository->listByPayNo($payNo);
        foreach ($logs as $log) {
            // 同一支付单的同一类型回调只保留一条，后续重复请求直接返回已有日志。
            if ((int) $log->callback_type === $callbackType) {
                return $log;
            }
        }

        return $this->payCallbackLogRepository->create([
            'pay_no' => $payNo,
            'channel_id' => (int) ($input['channel_id'] ?? 0),
            'callback_type' => $callbackType,
            'request_data' => $input['request_data'] ?? [],
            'verify_status' => (int) ($input['verify_status'] ?? NotifyConstant::VERIFY_STATUS_UNKNOWN),
            'process_status' => (int) ($input['process_status'] ?? NotifyConstant::PROCESS_STATUS_PENDING),
            'process_result' => $input['process_result'] ?? [],
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
        return $this->notifyTaskRepository->create([
            'notify_no' => (string) ($input['notify_no'] ?? $this->generateNo('NTF')),
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
     * @throws InvalidArgumentException
     */
    public function markTaskSuccess(string $notifyNo, array $input = []): NotifyTask
    {
        $task = $this->notifyTaskRepository->findByNotifyNo($notifyNo);
        if (!$task) {
            throw new \InvalidArgumentException('通知任务不存在');
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
     * @throws InvalidArgumentException
     */
    public function markTaskFailed(string $notifyNo, array $input = []): NotifyTask
    {
        $task = $this->notifyTaskRepository->findByNotifyNo($notifyNo);
        if (!$task) {
            throw new \InvalidArgumentException('通知任务不存在');
        }

        // 每次失败都累计一次重试，并根据新的次数重新计算下一次触发时间。
        $retryCount = (int) $task->retry_count + 1;
        $task->status = NotifyConstant::TASK_STATUS_FAILED;
        $task->retry_count = $retryCount;
        $task->last_notify_at = $input['last_notify_at'] ?? $this->now();
        $task->last_response = (string) ($input['last_response'] ?? '');
        $task->next_retry_at = $this->nextRetryAt($retryCount);
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
        $delay = match (true) {
            $retryCount <= 0 => 60,
            $retryCount === 1 => 300,
            $retryCount === 2 => 900,
            default => 1800,
        };

        return FormatHelper::timestamp(time() + $delay);
    }
}






