<?php

namespace app\service\merchant\group;

use app\common\base\BaseService;
use app\exception\ValidationException;
use app\model\merchant\MerchantGroup;
use app\repository\merchant\base\MerchantGroupRepository;

/**
 * 商户分组管理服务。
 *
 * @property MerchantGroupRepository $merchantGroupRepository 商户分组仓库
 */
class MerchantGroupService extends BaseService
{
    /**
     * 构造方法。
     *
     * @param MerchantGroupRepository $merchantGroupRepository 商户分组仓库
     */
    public function __construct(
        protected MerchantGroupRepository $merchantGroupRepository
    ) {
    }

    /**
     * 获取启用中的商户分组选项。
     *
     * 前端筛选框直接使用 `label / value` 结构即可。
     *
     * @return array 启用分组选项
     */
    public function enabledOptions(): array
    {
        return $this->merchantGroupRepository->enabledList(['id', 'group_name'])
            ->map(function (MerchantGroup $group): array {
                return [
                    'label' => (string) $group->group_name,
                    'value' => (int) $group->id,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * 分页查询商户分组。
     *
     * @param array $filters 筛选条件
     * @param int $page 页码
     * @param int $pageSize 每页条数
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator 分页对象
     */
    public function paginate(array $filters = [], int $page = 1, int $pageSize = 10)
    {
        $query = $this->merchantGroupRepository->query();

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->where('group_name', 'like', '%' . $keyword . '%');
        }

        $groupName = trim((string) ($filters['group_name'] ?? ''));
        if ($groupName !== '') {
            $query->where('group_name', 'like', '%' . $groupName . '%');
        }

        if (array_key_exists('status', $filters) && $filters['status'] !== '') {
            $query->where('status', (int) $filters['status']);
        }

        return $query
            ->orderByDesc('id')
            ->paginate(max(1, $pageSize), ['*'], 'page', max(1, $page));
    }

    /**
     * 根据 ID 查询商户分组。
     *
     * @param int $id 商户分组ID
     * @return MerchantGroup|null 商户分组模型
     */
    public function findById(int $id): ?MerchantGroup
    {
        return $this->merchantGroupRepository->find($id);
    }

    /**
     * 新增商户分组。
     *
     * @param array $data 分组数据
     * @return MerchantGroup 商户分组模型
     */
    public function create(array $data): MerchantGroup
    {
        $this->assertGroupNameUnique((string) ($data['group_name'] ?? ''));
        return $this->merchantGroupRepository->create($data);
    }

    /**
     * 更新商户分组。
     *
     * @param int $id 商户分组ID
     * @param array $data 分组数据
     * @return MerchantGroup|null 商户分组模型
     */
    public function update(int $id, array $data): ?MerchantGroup
    {
        $this->assertGroupNameUnique((string) ($data['group_name'] ?? ''), $id);
        if (!$this->merchantGroupRepository->updateById($id, $data)) {
            return null;
        }

        return $this->merchantGroupRepository->find($id);
    }

    /**
     * 删除商户分组。
     *
     * @param int $id 商户分组ID
     * @return bool 是否删除成功
     */
    public function delete(int $id): bool
    {
        return $this->merchantGroupRepository->deleteById($id);
    }

    /**
     * 校验商户分组名称唯一。
     *
     * @param string $groupName 分组名称
     * @param int $ignoreId 忽略的分组ID
     * @return void
     * @throws ValidationException
     */
    private function assertGroupNameUnique(string $groupName, int $ignoreId = 0): void
    {
        $groupName = trim($groupName);
        if ($groupName === '') {
            return;
        }

        if ($this->merchantGroupRepository->existsByGroupName($groupName, $ignoreId)) {
            throw new ValidationException('分组名称已存在');
        }
    }
}






