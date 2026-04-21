<?php

namespace app\service\payment\config;

use app\common\base\BaseService;
use app\model\payment\PaymentPollGroup;

/**
 * 支付轮询组服务。
 *
 * @property PaymentPollGroupQueryService $queryService 查询服务
 * @property PaymentPollGroupCommandService $commandService 命令服务
 */
class PaymentPollGroupService extends BaseService
{
    /**
     * 构造方法。
     *
     * @param PaymentPollGroupQueryService $queryService 查询服务
     * @param PaymentPollGroupCommandService $commandService 命令服务
     * @return void
     */
    public function __construct(
        protected PaymentPollGroupQueryService $queryService,
        protected PaymentPollGroupCommandService $commandService
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
        return $this->queryService->paginate($filters, $page, $pageSize);
    }

    /**
     * 获取启用支付轮询组选项。
     *
     * @param array $filters 筛选条件
     * @return array<int, array<string, mixed>> 启用轮询组选项
     */
    public function enabledOptions(array $filters = []): array
    {
        return $this->queryService->enabledOptions($filters);
    }

    /**
     * 按 ID 查询支付轮询组。
     *
     * @param int $id 轮询组ID
     * @return PaymentPollGroup|null 轮询组模型
     */
    public function findById(int $id): ?PaymentPollGroup
    {
        return $this->queryService->findById($id);
    }

    /**
     * 新增支付轮询组。
     *
     * @param array $data 写入数据
     * @return PaymentPollGroup 新增后的轮询组模型
     */
    public function create(array $data): PaymentPollGroup
    {
        return $this->commandService->create($data);
    }

    /**
     * 更新支付轮询组。
     *
     * @param int $id 轮询组ID
     * @param array $data 写入数据
     * @return PaymentPollGroup|null 更新后的轮询组模型
     */
    public function update(int $id, array $data): ?PaymentPollGroup
    {
        return $this->commandService->update($id, $data);
    }

    /**
     * 删除支付轮询组。
     *
     * @param int $id 轮询组ID
     * @return bool 是否删除成功
     */
    public function delete(int $id): bool
    {
        return $this->commandService->delete($id);
    }
}


