<?php

namespace app\service\merchant\policy;

use app\common\base\BaseService;
use app\exception\ResourceNotFoundException;
use app\model\merchant\MerchantPolicy;
use app\repository\merchant\base\MerchantPolicyRepository;
use app\repository\merchant\base\MerchantRepository;

/**
 * 商户策略服务。
 */
class MerchantPolicyService extends BaseService
{
    public function __construct(
        protected MerchantPolicyRepository $merchantPolicyRepository,
        protected MerchantRepository $merchantRepository
    ) {
    }

    /**
     * 分页查询商户策略列表。
     */
    public function paginate(array $filters = [], int $page = 1, int $pageSize = 10)
    {
        $query = $this->merchantRepository->query()
            ->from('ma_merchant as m')
            ->leftJoin('ma_merchant_group as g', 'm.group_id', '=', 'g.id')
            ->leftJoin('ma_merchant_policy as p', 'm.id', '=', 'p.merchant_id')
            ->select([
                'm.id as merchant_id',
                'm.merchant_no',
                'm.merchant_name',
                'm.merchant_short_name',
                'm.group_id',
                'm.status as merchant_status',
                'g.group_name',
                'p.id',
                'p.settlement_cycle_override',
                'p.auto_payout',
                'p.min_settlement_amount',
                'p.retry_policy_json',
                'p.route_policy_json',
                'p.fee_rule_override_json',
                'p.risk_policy_json',
                'p.remark',
                'p.created_at',
                'p.updated_at',
            ]);

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->where(function ($builder) use ($keyword) {
                $builder->where('m.merchant_no', 'like', '%' . $keyword . '%')
                    ->orWhere('m.merchant_name', 'like', '%' . $keyword . '%')
                    ->orWhere('m.merchant_short_name', 'like', '%' . $keyword . '%')
                    ->orWhere('g.group_name', 'like', '%' . $keyword . '%');
            });
        }

        if (($merchantId = (int) ($filters['merchant_id'] ?? 0)) > 0) {
            $query->where('m.id', $merchantId);
        }

        if (($groupId = (int) ($filters['group_id'] ?? 0)) > 0) {
            $query->where('m.group_id', $groupId);
        }

        if (($hasPolicy = (string) ($filters['has_policy'] ?? '')) !== '') {
            if ((int) $hasPolicy === 1) {
                $query->whereNotNull('p.id');
            } else {
                $query->whereNull('p.id');
            }
        }

        if (($settlementCycle = (string) ($filters['settlement_cycle_override'] ?? '')) !== '') {
            $query->where('p.settlement_cycle_override', (int) $settlementCycle);
        }

        if (($autoPayout = (string) ($filters['auto_payout'] ?? '')) !== '') {
            $query->where('p.auto_payout', (int) $autoPayout);
        }

        $paginator = $query
            ->orderByDesc('m.id')
            ->paginate(max(1, $pageSize), ['*'], 'page', max(1, $page));

        $paginator->getCollection()->transform(function ($row) {
            $row->has_policy = $row->id ? 1 : 0;
            $row->has_policy_text = $row->id ? '已配置' : '未配置';
            $row->settlement_cycle_text = $this->settlementCycleText((int) ($row->settlement_cycle_override ?? 0));
            $row->auto_payout_text = (int) ($row->auto_payout ?? 0) === 1 ? '是' : '否';
            $row->min_settlement_amount_text = $this->formatAmount((int) ($row->min_settlement_amount ?? 0));
            $row->has_retry_policy = !empty((array) ($row->retry_policy_json ?? []));
            $row->has_route_policy = !empty((array) ($row->route_policy_json ?? []));
            $row->has_fee_rule_override = !empty((array) ($row->fee_rule_override_json ?? []));
            $row->has_risk_policy = !empty((array) ($row->risk_policy_json ?? []));

            return $row;
        });

        return $paginator;
    }

    /**
     * 查询单个商户的策略。
     */
    public function findByMerchantId(int $merchantId): ?MerchantPolicy
    {
        return $this->merchantPolicyRepository->findByMerchantId($merchantId);
    }

    /**
     * 保存商户策略。
     */
    public function saveByMerchantId(int $merchantId, array $data): MerchantPolicy
    {
        if (!$this->merchantRepository->find($merchantId)) {
            throw new ResourceNotFoundException('商户不存在', ['merchant_id' => $merchantId]);
        }

        return $this->merchantPolicyRepository->updateOrCreate(
            ['merchant_id' => $merchantId],
            [
                'merchant_id' => $merchantId,
                'settlement_cycle_override' => (int) ($data['settlement_cycle_override'] ?? 1),
                'auto_payout' => (int) ($data['auto_payout'] ?? 0),
                'min_settlement_amount' => (int) ($data['min_settlement_amount'] ?? 0),
                'retry_policy_json' => $data['retry_policy_json'] ?? [],
                'route_policy_json' => $data['route_policy_json'] ?? [],
                'fee_rule_override_json' => $data['fee_rule_override_json'] ?? [],
                'risk_policy_json' => $data['risk_policy_json'] ?? [],
                'remark' => (string) ($data['remark'] ?? ''),
            ]
        );
    }

    /**
     * 删除商户策略。
     */
    public function deleteByMerchantId(int $merchantId): bool
    {
        return $this->merchantPolicyRepository->deleteWhere(['merchant_id' => $merchantId]) > 0;
    }

    private function settlementCycleText(int $value): string
    {
        return match ($value) {
            0 => 'D0',
            1 => 'D1',
            2 => 'D7',
            3 => 'T1',
            4 => 'OTHER',
            default => '未设置',
        };
    }

}

