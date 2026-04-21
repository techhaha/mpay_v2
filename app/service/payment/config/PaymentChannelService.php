<?php

namespace app\service\payment\config;

use app\common\base\BaseService;
use app\model\payment\PaymentChannel;

/**
 * 支付通道服务。
 *
 * @property PaymentChannelQueryService $queryService 查询服务
 * @property PaymentChannelCommandService $commandService 命令服务
 */
class PaymentChannelService extends BaseService
{
    /**
     * 构造方法。
     *
     * @param PaymentChannelQueryService $queryService 查询服务
     * @param PaymentChannelCommandService $commandService 命令服务
     * @return void
     */
    public function __construct(
        protected PaymentChannelQueryService $queryService,
        protected PaymentChannelCommandService $commandService
    ) {
    }

    /**
     * 获取启用支付通道选项。
     *
     * @return array<int, array{label: string, value: int}> 启用通道选项
     */
    public function enabledOptions(): array
    {
        return $this->queryService->enabledOptions();
    }

    /**
     * 搜索支付通道选项。
     *
     * @param array $filters 筛选条件
     * @param int $page 页码
     * @param int $pageSize 每页条数
     * @return array{list: array<int, array<string, mixed>>, total: int, page: int, size: int} 通道搜索结果
     */
    public function searchOptions(array $filters = [], int $page = 1, int $pageSize = 20): array
    {
        return $this->queryService->searchOptions($filters, $page, $pageSize);
    }

    /**
     * 获取支付渠道路由选项。
     *
     * @param array $filters 筛选条件
     * @return array<int, array<string, mixed>> 路由候选选项
     */
    public function routeOptions(array $filters = []): array
    {
        return $this->queryService->routeOptions($filters);
    }

    /**
     * 分页查询支付通道。
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
     * 按 ID 查询支付通道。
     *
     * @param int $id 支付通道ID
     * @return PaymentChannel|null 支付通道模型
     */
    public function findById(int $id): ?PaymentChannel
    {
        return $this->queryService->findById($id);
    }

    /**
     * 新增支付通道。
     *
     * @param array $data 写入数据
     * @return PaymentChannel 新增后的支付通道模型
     */
    public function create(array $data): PaymentChannel
    {
        return $this->commandService->create($data);
    }

    /**
     * 更新支付通道。
     *
     * @param int $id 支付通道ID
     * @param array $data 写入数据
     * @return PaymentChannel|null 更新后的支付通道模型
     */
    public function update(int $id, array $data): ?PaymentChannel
    {
        return $this->commandService->update($id, $data);
    }

    /**
     * 删除支付通道。
     *
     * @param int $id 支付通道ID
     * @return bool 是否删除成功
     */
    public function delete(int $id): bool
    {
        return $this->commandService->delete($id);
    }
}

