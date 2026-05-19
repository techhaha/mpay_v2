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
     *
     * @param string $prefix 单号前缀
     * @return string 单号
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
     *
     * @return string 时间字符串
     */
    protected function now(): string
    {
        return FormatHelper::timestamp(time());
    }

    /**
     * 金额格式化，单位为元。
     *
     * @param int $amount 金额（分）
     * @return string 格式化后的金额
     */
    protected function formatAmount(int $amount): string
    {
        return FormatHelper::amount($amount);
    }

    /**
     * 金额格式化，0 时显示不限。
     *
     * @param int $amount 金额（分）
     * @return string 格式化后的金额
     */
    protected function formatAmountOrUnlimited(int $amount): string
    {
        return FormatHelper::amountOrUnlimited($amount);
    }

    /**
     * 次数格式化，0 时显示不限。
     *
     * @param int $count 次数
     * @return string 格式化后的次数
     */
    protected function formatCountOrUnlimited(int $count): string
    {
        return FormatHelper::countOrUnlimited($count);
    }

    /**
     * 费率格式化，单位为百分点。
     *
     * @param int $basisPoints 基点值
     * @return string 格式化后的费率
     */
    protected function formatRate(int $basisPoints): string
    {
        return FormatHelper::rate($basisPoints);
    }

    /**
     * 延迟格式化。
     *
     * @param int $latencyMs 延迟毫秒数
     * @return string 格式化后的延迟
     */
    protected function formatLatency(int $latencyMs): string
    {
        return FormatHelper::latency($latencyMs);
    }

    /**
     * 日期格式化。
     *
     * @param mixed $value 日期时间值
     * @param string $emptyText 为空时显示文案
     * @return string 格式化后的日期
     */
    protected function formatDate(mixed $value, string $emptyText = ''): string
    {
        return FormatHelper::date($value, $emptyText);
    }

    /**
     * 日期时间格式化。
     *
     * @param mixed $value 日期时间值
     * @param string $emptyText 为空时显示文案
     * @return string 格式化后的日期时间
     */
    protected function formatDateTime(mixed $value, string $emptyText = ''): string
    {
        return FormatHelper::dateTime($value, $emptyText);
    }

    /**
     * JSON 文本格式化。
     *
     * @param mixed $value JSON 值
     * @param string $emptyText 为空时显示文案
     * @return string 格式化后的 JSON 文本
     */
    protected function formatJson(mixed $value, string $emptyText = ''): string
    {
        return FormatHelper::json($value, $emptyText);
    }

    /**
     * 映射表文本转换。
     *
     * @param int $value 待映射值
     * @param array $map 映射表
     * @param string $default 默认值
     * @return string 映射后的文本
     */
    protected function textFromMap(int $value, array $map, string $default = '未知'): string
    {
        return FormatHelper::textFromMap($value, $map, $default);
    }

    /**
     * 接口凭证明文脱敏。
     *
     * @param string $credentialValue 凭证原文
     * @param bool $maskShortValue 是否对短值也进行脱敏
     * @return string 脱敏后的文本
     */
    protected function maskCredentialValue(string $credentialValue, bool $maskShortValue = true): string
    {
        return FormatHelper::maskCredentialValue($credentialValue, $maskShortValue);
    }

    /**
     * 递归脱敏数组中的敏感字段。
     *
     * @param mixed $value 原始值
     * @return mixed 脱敏后的值
     */
    protected function maskSensitiveData(mixed $value): mixed
    {
        return FormatHelper::maskSensitiveData($value);
    }

    /**
     * 将模型或对象归一化成数组。
     *
     * @param mixed $value 模型实例、数组或可序列化对象
     * @return array|null 归一化结果
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
     * @param callable $callback 回调
     * @return mixed 回调原始返回值
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
     *
     * @param callable $callback 回调
     * @param int $attempts 重试次数
     * @param int $sleepMs 重试间隔毫秒数
     * @return mixed 回调原始返回值
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
