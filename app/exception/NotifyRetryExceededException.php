<?php

namespace app\exception;

use Webman\Exception\BusinessException;

/**
 * 通知重试次数超限异常。
 */
class NotifyRetryExceededException extends BusinessException
{
    /**
     * 构造函数，组装异常信息。
     */
    public function __construct(string $notifyNo = '', array $data = [])
    {
        parent::__construct('通知重试次数超限', 40016);

        $payload = array_filter([
            'notify_no' => $notifyNo,
        ], static fn ($value) => $value !== '' && $value !== null);

        if (!empty($data)) {
            $payload = array_merge($payload, $data);
        }

        if (!empty($payload)) {
            $this->data($payload);
        }
    }
}
