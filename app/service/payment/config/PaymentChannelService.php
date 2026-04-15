<?php

namespace app\service\payment\config;

use app\common\base\BaseService;
use app\model\payment\PaymentChannel;

/**
 * 支付通道门面服务。
 */
class PaymentChannelService extends BaseService
{
    public function __construct(
        protected PaymentChannelQueryService $queryService,
        protected PaymentChannelCommandService $commandService
    ) {
    }

    public function enabledOptions(): array
    {
        return $this->queryService->enabledOptions();
    }

    public function searchOptions(array $filters = [], int $page = 1, int $pageSize = 20): array
    {
        return $this->queryService->searchOptions($filters, $page, $pageSize);
    }

    public function routeOptions(array $filters = []): array
    {
        return $this->queryService->routeOptions($filters);
    }

    public function paginate(array $filters = [], int $page = 1, int $pageSize = 10)
    {
        return $this->queryService->paginate($filters, $page, $pageSize);
    }

    public function findById(int $id): ?PaymentChannel
    {
        return $this->queryService->findById($id);
    }

    public function create(array $data): PaymentChannel
    {
        return $this->commandService->create($data);
    }

    public function update(int $id, array $data): ?PaymentChannel
    {
        return $this->commandService->update($id, $data);
    }

    public function delete(int $id): bool
    {
        return $this->commandService->delete($id);
    }
}
