<?php

namespace app\service\payment\config;

use app\common\base\BaseService;
use app\model\payment\PaymentPollGroup;
use app\repository\payment\config\PaymentPollGroupRepository;

/**
 * 支付轮询组查询服务。
 */
class PaymentPollGroupQueryService extends BaseService
{
    public function __construct(
        protected PaymentPollGroupRepository $paymentPollGroupRepository
    ) {
    }

    public function paginate(array $filters = [], int $page = 1, int $pageSize = 10)
    {
        $query = $this->paymentPollGroupRepository->query();

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->where('group_name', 'like', '%' . $keyword . '%');
        }

        $groupName = trim((string) ($filters['group_name'] ?? ''));
        if ($groupName !== '') {
            $query->where('group_name', 'like', '%' . $groupName . '%');
        }

        if (($payTypeId = (int) ($filters['pay_type_id'] ?? 0)) > 0) {
            $query->where('pay_type_id', $payTypeId);
        }

        if (array_key_exists('route_mode', $filters) && $filters['route_mode'] !== '') {
            $query->where('route_mode', (int) $filters['route_mode']);
        }

        if (array_key_exists('status', $filters) && $filters['status'] !== '') {
            $query->where('status', (int) $filters['status']);
        }

        return $query
            ->orderByDesc('id')
            ->paginate(max(1, $pageSize), ['*'], 'page', max(1, $page));
    }

    public function enabledOptions(array $filters = []): array
    {
        $query = $this->paymentPollGroupRepository->query()
            ->where('status', 1);

        if (($payTypeId = (int) ($filters['pay_type_id'] ?? 0)) > 0) {
            $query->where('pay_type_id', $payTypeId);
        }

        return $query
            ->orderBy('group_name')
            ->orderByDesc('id')
            ->get(['id', 'group_name', 'pay_type_id', 'route_mode'])
            ->map(function (PaymentPollGroup $pollGroup): array {
                return [
                    'label' => sprintf('%s（%d）', (string) $pollGroup->group_name, (int) $pollGroup->id),
                    'value' => (int) $pollGroup->id,
                    'pay_type_id' => (int) $pollGroup->pay_type_id,
                    'route_mode' => (int) $pollGroup->route_mode,
                ];
            })
            ->values()
            ->all();
    }

    public function findById(int $id): ?PaymentPollGroup
    {
        return $this->paymentPollGroupRepository->find($id);
    }
}
