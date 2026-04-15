<?php

namespace app\service\merchant;

use app\common\base\BaseService;
use app\common\constant\CommonConstant;
use app\model\merchant\Merchant;
use app\model\merchant\MerchantPolicy;
use app\repository\merchant\base\MerchantGroupRepository;
use app\repository\merchant\base\MerchantPolicyRepository;
use app\repository\merchant\base\MerchantRepository;

/**
 * 商户查询服务。
 *
 * 负责商户列表、详情和总览这类只读查询。
 */
class MerchantQueryService extends BaseService
{
    public function __construct(
        protected MerchantRepository $merchantRepository,
        protected MerchantGroupRepository $merchantGroupRepository,
        protected MerchantPolicyRepository $merchantPolicyRepository
    ) {
    }

    public function paginate(array $filters = [], int $page = 1, int $pageSize = 10)
    {
        $query = $this->merchantRepository->query()
            ->from('ma_merchant as m')
            ->leftJoin('ma_merchant_group as g', 'm.group_id', '=', 'g.id')
            ->select([
                'm.id',
                'm.merchant_no',
                'm.merchant_name',
                'm.merchant_short_name',
                'm.merchant_type',
                'm.group_id',
                'm.risk_level',
                'm.contact_name',
                'm.contact_phone',
                'm.contact_email',
                'm.settlement_account_name',
                'm.settlement_account_no',
                'm.settlement_bank_name',
                'm.settlement_bank_branch',
                'm.status',
                'm.last_login_at',
                'm.last_login_ip',
                'm.password_updated_at',
                'm.remark',
                'm.created_at',
                'm.updated_at',
            ])
            ->selectRaw("COALESCE(g.group_name, '未分组') AS group_name")
            ->selectRaw("CASE m.merchant_type WHEN 0 THEN '个人' WHEN 1 THEN '企业' ELSE '其他' END AS merchant_type_text")
            ->selectRaw("CASE m.risk_level WHEN 0 THEN '低' WHEN 1 THEN '中' WHEN 2 THEN '高' ELSE '未知' END AS risk_level_text")
            ->selectRaw("CASE m.status WHEN 0 THEN '禁用' WHEN 1 THEN '启用' ELSE '未知' END AS status_text");

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->where(function ($builder) use ($keyword) {
                $builder->where('m.merchant_no', 'like', '%' . $keyword . '%')
                    ->orWhere('m.merchant_name', 'like', '%' . $keyword . '%')
                    ->orWhere('m.merchant_short_name', 'like', '%' . $keyword . '%')
                    ->orWhere('m.contact_name', 'like', '%' . $keyword . '%')
                    ->orWhere('m.contact_phone', 'like', '%' . $keyword . '%')
                    ->orWhere('g.group_name', 'like', '%' . $keyword . '%');
            });
        }

        $groupId = trim((string) ($filters['group_id'] ?? ''));
        if ($groupId !== '') {
            $query->where('m.group_id', (int) $groupId);
        }

        $status = trim((string) ($filters['status'] ?? ''));
        if ($status !== '') {
            $query->where('m.status', (int) $status);
        }

        $merchantType = trim((string) ($filters['merchant_type'] ?? ''));
        if ($merchantType !== '') {
            $query->where('m.merchant_type', (int) $merchantType);
        }

        $riskLevel = trim((string) ($filters['risk_level'] ?? ''));
        if ($riskLevel !== '') {
            $query->where('m.risk_level', (int) $riskLevel);
        }

        return $query
            ->orderByDesc('m.id')
            ->paginate(max(1, $pageSize), ['*'], 'page', max(1, $page));
    }

    public function paginateWithGroupOptions(array $filters = [], int $page = 1, int $pageSize = 10): array
    {
        $paginator = $this->paginate($filters, $page, $pageSize);

        return [
            'list' => $paginator->items(),
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'size' => $paginator->perPage(),
            'groups' => $this->enabledGroupOptions(),
        ];
    }

    public function enabledOptions(): array
    {
        return $this->merchantRepository->enabledList(['id', 'merchant_no', 'merchant_name'])
            ->map(function (Merchant $merchant): array {
                return [
                    'label' => sprintf('%s（%s）', (string) $merchant->merchant_name, (string) $merchant->merchant_no),
                    'value' => (int) $merchant->id,
                ];
            })
            ->values()
            ->all();
    }

    public function enabledGroupOptions(): array
    {
        return $this->merchantGroupRepository->enabledList(['id', 'group_name'])
            ->map(static function ($group): array {
                return [
                    'label' => (string) $group->group_name,
                    'value' => (int) $group->id,
                ];
            })
            ->values()
            ->all();
    }

    public function searchOptions(array $filters = [], int $page = 1, int $pageSize = 20): array
    {
        $query = $this->merchantRepository->query()
            ->from('ma_merchant as m')
            ->where('m.status', CommonConstant::STATUS_ENABLED)
            ->select([
                'm.id',
                'm.merchant_no',
                'm.merchant_name',
                'm.merchant_short_name',
            ]);

        $ids = $this->normalizeIds($filters['ids'] ?? []);
        $keyword = trim((string) ($filters['keyword'] ?? ''));

        if (!empty($ids)) {
            $query->whereIn('m.id', $ids);
        } elseif ($keyword !== '') {
            $query->where(function ($builder) use ($keyword) {
                $builder->where('m.merchant_no', 'like', '%' . $keyword . '%')
                    ->orWhere('m.merchant_name', 'like', '%' . $keyword . '%')
                    ->orWhere('m.merchant_short_name', 'like', '%' . $keyword . '%');
            });
        }

        $paginator = $query
            ->orderByDesc('m.id')
            ->paginate(max(1, $pageSize), ['*'], 'page', max(1, $page));

        return [
            'list' => collect($paginator->items())
                ->map(function ($merchant): array {
                    return [
                        'label' => sprintf('%s（%s）', (string) $merchant->merchant_name, (string) $merchant->merchant_no),
                        'value' => (int) $merchant->id,
                        'merchant_no' => (string) $merchant->merchant_no,
                        'merchant_name' => (string) $merchant->merchant_name,
                        'merchant_short_name' => (string) ($merchant->merchant_short_name ?? ''),
                    ];
                })
                ->values()
                ->all(),
            'total' => (int) $paginator->total(),
            'page' => (int) $paginator->currentPage(),
            'size' => (int) $paginator->perPage(),
        ];
    }

    public function findById(int $merchantId): ?object
    {
        return $this->merchantRepository->query()
            ->from('ma_merchant as m')
            ->leftJoin('ma_merchant_group as g', 'm.group_id', '=', 'g.id')
            ->select([
                'm.id',
                'm.merchant_no',
                'm.merchant_name',
                'm.merchant_short_name',
                'm.merchant_type',
                'm.group_id',
                'm.risk_level',
                'm.contact_name',
                'm.contact_phone',
                'm.contact_email',
                'm.settlement_account_name',
                'm.settlement_account_no',
                'm.settlement_bank_name',
                'm.settlement_bank_branch',
                'm.status',
                'm.last_login_at',
                'm.last_login_ip',
                'm.password_updated_at',
                'm.remark',
                'm.created_at',
                'm.updated_at',
            ])
            ->selectRaw("COALESCE(g.group_name, '未分组') AS group_name")
            ->selectRaw("CASE m.merchant_type WHEN 0 THEN '个人' WHEN 1 THEN '企业' ELSE '其他' END AS merchant_type_text")
            ->selectRaw("CASE m.risk_level WHEN 0 THEN '低' WHEN 1 THEN '中' WHEN 2 THEN '高' ELSE '未知' END AS risk_level_text")
            ->selectRaw("CASE m.status WHEN 0 THEN '禁用' WHEN 1 THEN '启用' ELSE '未知' END AS status_text")
            ->where('m.id', $merchantId)
            ->first();
    }

    public function findPolicy(int $merchantId): ?MerchantPolicy
    {
        return $this->merchantPolicyRepository->findByMerchantId($merchantId);
    }

    private function normalizeIds(array|string|int $ids): array
    {
        if (is_string($ids)) {
            $ids = array_filter(array_map('trim', explode(',', $ids)));
        } elseif (!is_array($ids)) {
            $ids = [$ids];
        }

        return array_values(array_filter(array_map(static fn ($id) => (int) $id, $ids), static fn ($id) => $id > 0));
    }
}
