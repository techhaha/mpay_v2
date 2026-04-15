<?php

namespace app\common\base;

use app\common\util\FormatHelper;
use support\Db;
use Throwable;

/**
 * 业务服务层基础类。
 *
 * 统一承载业务单号生成、时间获取和事务封装等通用能力。
 */
class BaseService
{
    /**
     * 生成业务单号。
     *
     * 适用于 biz_no / pay_no / refund_no / settle_no / notify_no / ledger_no 等场景。
     * 默认使用时间前缀 + 随机数，保证可读性和基本唯一性。
     */
    protected function generateNo(string $prefix = ''): string
    {
        $time = FormatHelper::timestamp(time(), 'YmdHis');
        $rand = (string) random_int(100000, 999999);

        return $prefix === '' ? $time . $rand : $prefix . $time . $rand;
    }

    /**
     * 获取当前时间字符串。
     *
     * 统一返回 `Y-m-d H:i:s` 格式，便于数据库写入和日志输出。
     */
    protected function now(): string
    {
        return FormatHelper::timestamp(time());
    }

    /**
     * 金额格式化，单位为元。
     */
    protected function formatAmount(int $amount): string
    {
        return FormatHelper::amount($amount);
    }

    /**
     * 金额格式化，0 时显示不限。
     */
    protected function formatAmountOrUnlimited(int $amount): string
    {
        return FormatHelper::amountOrUnlimited($amount);
    }

    /**
     * 次数格式化，0 时显示不限。
     */
    protected function formatCountOrUnlimited(int $count): string
    {
        return FormatHelper::countOrUnlimited($count);
    }

    /**
     * 费率格式化，单位为百分点。
     */
    protected function formatRate(int $basisPoints): string
    {
        return FormatHelper::rate($basisPoints);
    }

    /**
     * 延迟格式化。
     */
    protected function formatLatency(int $latencyMs): string
    {
        return FormatHelper::latency($latencyMs);
    }

    /**
     * 日期格式化。
     */
    protected function formatDate(mixed $value, string $emptyText = ''): string
    {
        return FormatHelper::date($value, $emptyText);
    }

    /**
     * 日期时间格式化。
     */
    protected function formatDateTime(mixed $value, string $emptyText = ''): string
    {
        return FormatHelper::dateTime($value, $emptyText);
    }

    /**
     * JSON 文本格式化。
     */
    protected function formatJson(mixed $value, string $emptyText = ''): string
    {
        return FormatHelper::json($value, $emptyText);
    }

    /**
     * 映射表文本转换。
     */
    protected function textFromMap(int $value, array $map, string $default = '未知'): string
    {
        return FormatHelper::textFromMap($value, $map, $default);
    }

    /**
     * 接口凭证明文脱敏。
     */
    protected function maskCredentialValue(string $credentialValue, bool $maskShortValue = true): string
    {
        return FormatHelper::maskCredentialValue($credentialValue, $maskShortValue);
    }

    /**
     * 将模型或对象归一化成数组。
     */
    protected function normalizeModel(mixed $value): ?array
    {
        return FormatHelper::normalizeModel($value);
    }

    /**
     * 事务封装。
     *
     * 适合单次数据库事务，不包含自动重试逻辑。
     *
     * @param callable $callback 事务体
     * @return mixed
     */
    protected function transaction(callable $callback)
    {
        return Db::transaction(function () use ($callback) {
            return $callback();
        });
    }

    /**
     * 支持重试的事务封装。
     *
     * 适合余额冻结、扣减、状态推进和幂等写入等容易发生锁冲突的场景。
     */
    protected function transactionRetry(callable $callback, int $attempts = 3, int $sleepMs = 50)
    {
        $attempts = max(1, $attempts);

        beginning:
        try {
            return $this->transaction($callback);
        } catch (Throwable $e) {
            $message = strtolower($e->getMessage());
            $retryable = str_contains($message, 'deadlock')
                || str_contains($message, 'lock wait timeout')
                || str_contains($message, 'try restarting transaction');

            if (--$attempts > 0 && $retryable) {
                if ($sleepMs > 0) {
                    usleep($sleepMs * 1000);
                }

                goto beginning;
            }

            throw $e;
        }
    }

}
