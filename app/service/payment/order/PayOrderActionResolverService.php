<?php

namespace app\service\payment\order;

use app\common\base\BaseService;
use app\common\constant\PayOrderActionConstant;
use app\common\constant\TradeConstant;
use app\model\payment\BizOrder;
use app\model\payment\PayOrder;

/**
 * 支付订单后台可操作项计算服务。
 *
 * 本服务只基于已经查询出的订单行做内存计算，不做逐行查库、不实例化插件，
 * 避免管理后台列表因为按钮规则引入额外数据库压力。
 */
class PayOrderActionResolverService extends BaseService
{
    /**
     * 构造方法。
     *
     * @param PayOrderRiskControlService $riskControlService 支付单风控服务
     * @return void
     */
    public function __construct(
        protected PayOrderRiskControlService $riskControlService
    ) {
    }

    /**
     * 批量补齐列表行可操作项。
     *
     * @param array<int, array<string, mixed>> $rows 支付单列表行
     * @return array<int, array<string, mixed>> 带操作项的列表行
     */
    public function resolveForRows(array $rows): array
    {
        foreach ($rows as $index => $row) {
            $rows[$index] = $this->resolveForRow($row);
        }

        return $rows;
    }

    /**
     * 补齐单行可操作项。
     *
     * @param array<string, mixed> $row 支付单行
     * @return array<string, mixed> 带操作项的支付单行
     */
    public function resolveForRow(array $row): array
    {
        $actions = $this->buildActions($row);

        $row['actions'] = $actions;
        $row['enabled_actions'] = array_values(array_map(
            static fn (array $action): string => (string) $action['code'],
            array_filter($actions, static fn (array $action): bool => (bool) $action['enabled'])
        ));
        $row['freeze_info'] = $this->riskControlService->freezeInfo($row);
        $row['is_frozen'] = (bool) $row['freeze_info']['is_frozen'];
        $row['refundable_amount'] = $this->refundableAmount($row);
        $row['refundable_amount_text'] = $this->formatAmount((int) $row['refundable_amount']);

        return $row;
    }

    /**
     * 根据模型计算可操作项。
     *
     * @param PayOrder $payOrder 支付单
     * @param BizOrder|null $bizOrder 业务单
     * @return array<string, mixed> 带操作项的轻量订单行
     */
    public function resolveForPayOrder(PayOrder $payOrder, ?BizOrder $bizOrder = null): array
    {
        return $this->resolveForRow([
            'pay_no' => (string) $payOrder->pay_no,
            'biz_no' => (string) $payOrder->biz_no,
            'status' => (int) $payOrder->status,
            'channel_id' => (int) $payOrder->channel_id,
            'plugin_code' => (string) ($payOrder->plugin_code ?? ''),
            'pay_amount' => (int) $payOrder->pay_amount,
            'biz_refund_amount' => (int) ($bizOrder?->refund_amount ?? 0),
            'notify_url' => (string) ($payOrder->notify_url ?? ''),
            'biz_notify_url' => (string) ($bizOrder?->notify_url ?? ''),
            'ext_json' => (array) ($payOrder->ext_json ?? []),
        ]);
    }

    /**
     * 构建全部操作按钮。
     *
     * @param array<string, mixed> $row 支付单行
     * @return array<int, array<string, mixed>> 操作项
     */
    private function buildActions(array $row): array
    {
        $frozen = $this->riskControlService->isFrozen($row);
        $status = (int) ($row['status'] ?? -1);
        $isSuccess = $status === TradeConstant::ORDER_STATUS_SUCCESS;
        $hasNotifyUrl = trim((string) ($row['notify_url'] ?? '')) !== ''
            || trim((string) ($row['biz_notify_url'] ?? '')) !== '';
        $pluginCode = trim((string) ($row['plugin_code'] ?? ''));
        if ($pluginCode === '') {
            $pluginCode = trim((string) ($row['channel_plugin_code'] ?? ''));
        }
        $hasChannel = (int) ($row['channel_id'] ?? 0) > 0 && $pluginCode !== '';
        $refundableAmount = $this->refundableAmount($row);

        return [
            $this->action(
                PayOrderActionConstant::MANUAL_SUCCESS,
                !$frozen && !$isSuccess && in_array($status, [
                    TradeConstant::ORDER_STATUS_CREATED,
                    TradeConstant::ORDER_STATUS_PAYING,
                    TradeConstant::ORDER_STATUS_FAILED,
                    TradeConstant::ORDER_STATUS_CLOSED,
                    TradeConstant::ORDER_STATUS_TIMEOUT,
                ], true),
                $frozen ? '订单已冻结' : ($isSuccess ? '订单已成功，无需补单' : ''),
                true,
                true
            ),
            $this->action(
                PayOrderActionConstant::RENOTIFY,
                !$frozen && $isSuccess && $hasNotifyUrl,
                $this->disabledReason($frozen, $isSuccess, $hasNotifyUrl, '只有成功订单可以重新通知', '订单未配置 notify_url'),
                false,
                false
            ),
            $this->action(
                PayOrderActionConstant::ACTIVE_QUERY,
                !$frozen && !$isSuccess && $hasChannel,
                $frozen ? '订单已冻结' : ($isSuccess ? '订单已成功，无需查单' : ($hasChannel ? '' : '订单缺少通道或插件信息')),
                false,
                false
            ),
            $this->action(
                PayOrderActionConstant::API_REFUND,
                !$frozen && $isSuccess && $hasChannel && $refundableAmount > 0,
                $this->refundDisabledReason($frozen, $isSuccess, $hasChannel, $refundableAmount),
                true,
                true
            ),
            $this->action(
                PayOrderActionConstant::MANUAL_REFUND,
                !$frozen && $isSuccess && $refundableAmount > 0,
                $frozen ? '订单已冻结' : ($isSuccess ? ($refundableAmount > 0 ? '' : '订单暂无可退余额') : '只有成功订单可以退款'),
                true,
                true
            ),
            $this->action(
                PayOrderActionConstant::FREEZE,
                !$frozen,
                $frozen ? '订单已冻结' : '',
                true,
                true
            ),
            $this->action(
                PayOrderActionConstant::UNFREEZE,
                $frozen,
                $frozen ? '' : '订单未冻结',
                false,
                true
            ),
        ];
    }

    /**
     * 构建单个操作项。
     *
     * @param string $code 操作码
     * @param bool $enabled 是否可用
     * @param string $reason 禁用原因
     * @param bool $danger 是否危险操作
     * @param bool $confirm 是否需要确认
     * @return array<string, mixed> 操作项
     */
    private function action(string $code, bool $enabled, string $reason = '', bool $danger = false, bool $confirm = false): array
    {
        return [
            'code' => $code,
            'label' => PayOrderActionConstant::actionLabelMap()[$code] ?? $code,
            'enabled' => $enabled,
            'reason' => $enabled ? '' : $reason,
            'danger' => $danger,
            'confirm' => $confirm,
        ];
    }

    /**
     * 计算列表展示用可退余额。
     *
     * 列表只用业务单累计退款金额做轻量估算；真正创建退款时会重新锁定支付单和
     * 退款单，按 CREATED/PROCESSING/SUCCESS 退款单重新计算可退余额。
     *
     * @param array<string, mixed> $row 支付单行
     * @return int 可退金额，单位分
     */
    private function refundableAmount(array $row): int
    {
        return max(0, (int) ($row['pay_amount'] ?? 0) - (int) ($row['biz_refund_amount'] ?? 0));
    }

    /**
     * 生成通知禁用原因。
     *
     * @param bool $frozen 是否冻结
     * @param bool $isSuccess 是否成功
     * @param bool $hasNotifyUrl 是否有通知地址
     * @param string $statusReason 状态原因
     * @param string $urlReason 地址原因
     * @return string 禁用原因
     */
    private function disabledReason(bool $frozen, bool $isSuccess, bool $hasNotifyUrl, string $statusReason, string $urlReason): string
    {
        if ($frozen) {
            return '订单已冻结';
        }

        if (!$isSuccess) {
            return $statusReason;
        }

        return $hasNotifyUrl ? '' : $urlReason;
    }

    /**
     * 生成退款禁用原因。
     *
     * @param bool $frozen 是否冻结
     * @param bool $isSuccess 是否成功
     * @param bool $hasChannel 是否有通道
     * @param int $refundableAmount 可退金额
     * @return string 禁用原因
     */
    private function refundDisabledReason(bool $frozen, bool $isSuccess, bool $hasChannel, int $refundableAmount): string
    {
        if ($frozen) {
            return '订单已冻结';
        }

        if (!$isSuccess) {
            return '只有成功订单可以退款';
        }

        if (!$hasChannel) {
            return '订单缺少通道或插件信息';
        }

        return $refundableAmount > 0 ? '' : '订单暂无可退余额';
    }
}
