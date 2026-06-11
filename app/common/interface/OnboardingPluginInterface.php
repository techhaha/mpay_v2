<?php
declare(strict_types=1);

namespace app\common\interface;

use support\Request;

/**
 * 服务商进件能力接口。
 *
 * 支付插件通过该接口声明并承接进件动作；申请单生命周期、审核、日志和页面交互
 * 仍由平台服务层负责。
 */
interface OnboardingPluginInterface
{
    /**
     * 提交进件资料到上游。
     *
     * @param array<string, mixed> $payload 标准进件上下文
     * @return array<string, mixed> 标准进件结果
     */
    public function submitOnboarding(array $payload): array;

    /**
     * 查询上游进件状态。
     *
     * @param array<string, mixed> $payload 标准查询上下文
     * @return array<string, mixed> 标准查询结果
     */
    public function queryOnboarding(array $payload): array;

    /**
     * 取消上游进件申请。
     *
     * @param array<string, mixed> $payload 标准取消上下文
     * @return array<string, mixed> 标准取消结果
     */
    public function cancelOnboarding(array $payload): array;

    /**
     * 解析并验证上游进件通知。
     *
     * @param Request $request 请求对象
     * @return array<string, mixed> 标准通知结果
     */
    public function notifyOnboarding(Request $request): array;
}
