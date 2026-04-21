<?php

namespace app\exception;

use Webman\Exception\BusinessException;

/**
 * 通知重试次数超限异常。
 */
class NotifyRetryExceededException extends BusinessException
{
    /**
     * 构造方法。
     *
     * @param string $notifyNo 通知号
     * @param array $data 数据
     * @return void
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





