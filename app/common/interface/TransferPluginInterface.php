<?php

declare(strict_types=1);

namespace app\common\interface;

/**
 * 支付插件的可选转账能力接口。
 *
 * 支付插件默认不要求支持转账；只有显式实现本接口，并在插件元信息中声明
 * transfer_types 的插件，才会被转账链路选中。
 */
interface TransferPluginInterface
{
    /**
     * 发起转账。
     *
     * @param array<string, mixed> $order 转账订单参数
     * @return array<string, mixed> 转账结果
     */
    public function transfer(array $order): array;

    /**
     * 查询转账状态。
     *
     * @param array<string, mixed> $order 转账订单参数
     * @return array<string, mixed> 查询结果
     */
    public function transferQuery(array $order): array;

    /**
     * 查询转账余额。
     *
     * @param array<string, mixed> $order 查询参数
     * @return array<string, mixed> 余额结果
     */
    public function transferBalance(array $order): array;
}
