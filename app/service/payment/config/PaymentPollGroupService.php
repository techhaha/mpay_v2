<?php

namespace app\service\payment\config;

use app\common\base\BaseService;
use app\model\payment\PaymentPollGroup;

/**
 * 支付轮询组门面服务。
 */
class PaymentPollGroupService extends BaseService
{
    public function __construct(
        protected PaymentPollGroupQueryService $queryService,
        protected PaymentPollGroupCommandService $commandService
    ) {
    }

    public function paginate(array $filters = [], int $page = 1, int $pageSize = 10)
    {
        return $this->queryService->paginate($filters, $page, $pageSize);
    }

    public function enabledOptions(array $filters = []): array
    {
        return $this->queryService->enabledOptions($filters);
    }

    public function findById(int $id): ?PaymentPollGroup
    {
        return $this->queryService->findById($id);
    }

    public function create(array $data): PaymentPollGroup
    {
        return $this->commandService->create($data);
    }

    public function update(int $id, array $data): ?PaymentPollGroup
    {
        return $this->commandService->update($id, $data);
    }

    public function delete(int $id): bool
    {
        return $this->commandService->delete($id);
    }
}
