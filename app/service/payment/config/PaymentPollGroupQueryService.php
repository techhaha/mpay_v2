<?php

namespace app\service\payment\config;

use app\common\base\BaseService;
use app\model\payment\PaymentPollGroup;
use app\repository\payment\config\PaymentPollGroupRepository;

/**
 * 支付轮询组查询与选项拼装服务。
 *
 * 负责轮询组列表、详情和启用选项输出。
 *
 * @property PaymentPollGroupRepository $paymentPollGroupRepository 支付轮询分组仓库
 */
class PaymentPollGroupQueryService extends BaseService
{
    /**
     * 构造方法。
     *
     * @param PaymentPollGroupRepository $paymentPollGroupRepository 支付轮询分组仓库
     * @return void
     */
    public function __construct(
        protected PaymentPollGroupRepository $paymentPollGroupRepository
    ) {
    }

    /**
     * 分页查询支付轮询组。
     *
     * @param array $filters 筛选条件
     * @param int $page 页码
     * @param int $pageSize 每页条数
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator 分页结果
     */
    public function paginate(array $filters = [], int $page = 1, int $pageSize = 10)
    {
        $query = $this->paymentPollGroupRepository->query();

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            // 轮询组列表只按组名搜索，避免把支付方式或路由模式混进模糊搜索结果里。
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

    /**
     * 获取启用支付轮询组选项。
     *
     * @param array $filters 筛选条件
     * @return array<int, array{label: string, value: int, pay_type_id: int, route_mode: int}> 启用轮询组选项
     */
    public function enabledOptions(array $filters = []): array
    {
        $query = $this->paymentPollGroupRepository->query()
            ->where('status', 1);

        if (($payTypeId = (int) ($filters['pay_type_id'] ?? 0)) > 0) {
            // 轮询组选项通常要跟支付方式联动，因此启用项会先按支付方式收窄。
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

    /**
     * 按 ID 查询轮询组。
     *
     * @param int $id 轮询组ID
     * @return PaymentPollGroup|null 轮询组模型
     */
    public function findById(int $id): ?PaymentPollGroup
    {
        return $this->paymentPollGroupRepository->find($id);
    }
}



