<?php

namespace app\exception;

use Webman\Exception\BusinessException;

/**
 * 命令执行异常。
 *
 * 用于命令行工具、测试命令和脚本解析失败等场景。
 */
class CommandException extends BusinessException
{
    /**
     * 构造方法。
     *
     * @param string $message 异常消息
     * @param int $bizCode 业务码
     * @param \Throwable|null $previous 前一个异常
     * @param array<string, mixed> $data 附加数据
     * @return void
     */
    public function __construct(string $message = '命令执行失败', int $bizCode = 50002, ?\Throwable $previous = null, array $data = [])
    {
        parent::__construct($message, $bizCode, $previous);

        if ($previous !== null) {
            $data = array_merge([
                'previous_exception' => $previous::class,
                'previous_message' => $previous->getMessage(),
            ], $data);
        }

        $this->data($data);
    }

    /**
     * 获取附加数据。
     *
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        $data = $this->data();
        return is_array($data) ? $data : [];
    }
}
