<?php

namespace app\service\merchant;

use app\common\base\BaseService;
use app\common\constant\CommonConstant;
use app\common\constant\RouteConstant;
use app\exception\ResourceNotFoundException;
use app\repository\account\balance\MerchantAccountRepository;
use app\repository\merchant\credential\MerchantApiCredentialRepository;
use app\repository\payment\config\PaymentChannelRepository;
use app\repository\payment\config\PaymentPollGroupBindRepository;
use app\repository\payment\settlement\SettlementOrderRepository;
use app\repository\payment\trade\PayOrderRepository;

/**
 * 商户总览查询服务。
 *
 * 负责拼装商户资料、接口凭证、资金、路由、通道以及最近交易和清结算的总览数据。
 *
 * @property MerchantQueryService $merchantQueryService 商户查询服务
 * @property MerchantAccountRepository $merchantAccountRepository 商户账户仓库
 * @property MerchantApiCredentialRepository $merchantApiCredentialRepository 商户 API 凭证仓库
 * @property PaymentChannelRepository $paymentChannelRepository 支付渠道仓库
 * @property PaymentPollGroupBindRepository $paymentPollGroupBindRepository 支付轮询分组绑定仓库
 * @property PayOrderRepository $payOrderRepository 支付订单仓库
 * @property SettlementOrderRepository $settlementOrderRepository 清结算订单仓库
 */
class MerchantOverviewQueryService extends BaseService
{
    /**
     * 构造方法。
     *
     * @param MerchantQueryService $merchantQueryService 商户查询服务
     * @param MerchantAccountRepository $merchantAccountRepository 商户账户仓库
     * @param MerchantApiCredentialRepository $merchantApiCredentialRepository 商户 API 凭证仓库
     * @param PaymentChannelRepository $paymentChannelRepository 支付渠道仓库
     * @param PaymentPollGroupBindRepository $paymentPollGroupBindRepository 支付轮询分组绑定仓库
     * @param PayOrderRepository $payOrderRepository 支付订单仓库
     * @param SettlementOrderRepository $settlementOrderRepository 清结算订单仓库
     */
    public function __construct(
        protected MerchantQueryService $merchantQueryService,
        protected MerchantAccountRepository $merchantAccountRepository,
        protected MerchantApiCredentialRepository $merchantApiCredentialRepository,
        protected PaymentChannelRepository $paymentChannelRepository,
        protected PaymentPollGroupBindRepository $paymentPollGroupBindRepository,
        protected PayOrderRepository $payOrderRepository,
        protected SettlementOrderRepository $settlementOrderRepository
    ) {
    }

    /**
     * 查询商户总览。
     *
     * @param int $merchantId 商户ID
     * @return array 总览数据
     * @throws ResourceNotFoundException
     */
    public function overview(int $merchantId): array
    {
        $merchant = $this->merchantQueryService->findById($merchantId);
        if (!$merchant) {
            throw new ResourceNotFoundException('商户不存在', ['merchant_id' => $merchantId]);
        }

        $account = $this->merchantAccountRepository->findByMerchantId($merchantId);
        $credential = $this->merchantApiCredentialRepository->findByMerchantId($merchantId);
        $channelSummary = $this->paymentChannelRepository->summaryByMerchantId($merchantId);

        $bindSummary = [];
        if ((int) $merchant->group_id > 0) {
            $bindSummary = $this->paymentPollGroupBindRepository
                ->listSummaryByMerchantGroupId((int) $merchant->group_id)
                ->map(function ($row) {
                    $row->status_text = (int) $row->status === CommonConstant::STATUS_ENABLED ? '启用' : '禁用';
                    $routeModeMap = RouteConstant::routeModeMap();
                    $row->route_mode_text = (string) ($routeModeMap[(int) ($row->route_mode ?? -1)] ?? '未知');

                    return $row;
                })
                ->all();
        }

        $recentPayOrders = $this->payOrderRepository
            ->recentByMerchantId($merchantId, 5)
            ->map(function ($row) {
                $row->pay_amount_text = $this->formatAmount((int) $row->pay_amount);
                $row->status_text = match ((int) $row->status) {
                    0 => '待创建',
                    1 => '支付中',
                    2 => '成功',
                    3 => '失败',
                    4 => '关闭',
                    5 => '超时',
                    default => '未知',
                };

                return $row;
            })
            ->all();

        $recentSettlements = $this->settlementOrderRepository
            ->recentByMerchantId($merchantId, 5)
            ->map(function ($row) {
                $row->net_amount_text = $this->formatAmount((int) $row->net_amount);
                $row->status_text = match ((int) $row->status) {
                    0 => '待处理',
                    1 => '处理中',
                    2 => '成功',
                    3 => '失败',
                    4 => '已冲正',
                    default => '未知',
                };

                return $row;
            })
            ->all();

        return [
            'merchant' => $merchant,
            'access' => [
                'login_identity' => (string) $merchant->merchant_no,
                'login_mode_text' => '商户号 + 密码',
                'has_credential' => $credential !== null,
                'credential_enabled' => (int) ($credential->status ?? 0) === CommonConstant::STATUS_ENABLED,
                'credential_status_text' => (int) ($credential->status ?? 0) === CommonConstant::STATUS_ENABLED ? '已开通' : '未开通',
                'sign_type_text' => $this->textFromMap((int) ($credential->sign_type ?? 0), \app\common\constant\AuthConstant::signTypeMap()),
                'credential_last_used_at' => $this->formatDateTime($credential->last_used_at ?? null),
            ],
            'route' => [
                'merchant_group_id' => (int) $merchant->group_id,
                'merchant_group_name' => (string) ($merchant->group_name ?? '未分组'),
                'bind_count' => count($bindSummary),
                'binds' => $bindSummary,
            ],
            'funds' => [
                'has_account' => $account !== null,
                'available_balance' => (int) ($account->available_balance ?? 0),
                'available_balance_text' => $this->formatAmount((int) ($account->available_balance ?? 0)),
                'frozen_balance' => (int) ($account->frozen_balance ?? 0),
                'frozen_balance_text' => $this->formatAmount((int) ($account->frozen_balance ?? 0)),
            ],
            'channels' => [
                'total_count' => (int) ($channelSummary->total_count ?? 0),
                'enabled_count' => (int) ($channelSummary->enabled_count ?? 0),
                'self_count' => (int) ($channelSummary->self_count ?? 0),
            ],
            'recent_pay_orders' => $recentPayOrders,
            'recent_settlements' => $recentSettlements,
        ];
    }
}



