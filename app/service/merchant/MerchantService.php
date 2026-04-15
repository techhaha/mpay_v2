<?php

namespace app\service\merchant;

use app\common\base\BaseService;
use app\model\merchant\Merchant;
use app\model\merchant\MerchantGroup;
use app\model\merchant\MerchantPolicy;

/**
 * 商户基础服务门面。
 *
 * 仅保留现有控制器和其他服务依赖的统一入口。
 */
class MerchantService extends BaseService
{
    public function __construct(
        protected MerchantQueryService $queryService,
        protected MerchantCommandService $commandService,
        protected MerchantOverviewQueryService $overviewQueryService
    ) {
    }

    public function paginate(array $filters = [], int $page = 1, int $pageSize = 10)
    {
        return $this->queryService->paginate($filters, $page, $pageSize);
    }

    public function paginateWithGroupOptions(array $filters = [], int $page = 1, int $pageSize = 10): array
    {
        return $this->queryService->paginateWithGroupOptions($filters, $page, $pageSize);
    }

    public function enabledOptions(): array
    {
        return $this->queryService->enabledOptions();
    }

    public function searchOptions(array $filters = [], int $page = 1, int $pageSize = 20): array
    {
        return $this->queryService->searchOptions($filters, $page, $pageSize);
    }

    public function findById(int $merchantId): ?object
    {
        return $this->queryService->findById($merchantId);
    }

    public function create(array $data): Merchant
    {
        return $this->commandService->create($data);
    }

    public function createWithDetail(array $data): ?object
    {
        $merchant = $this->create($data);
        $detail = $this->findById((int) $merchant->id);
        if ($detail && isset($merchant->plain_password)) {
            $detail->plain_password = (string) $merchant->plain_password;
        }

        return $detail ?? $merchant;
    }

    public function update(int $merchantId, array $data): ?Merchant
    {
        return $this->commandService->update($merchantId, $data);
    }

    public function updateWithDetail(int $merchantId, array $data): ?object
    {
        $merchant = $this->update($merchantId, $data);
        if (!$merchant) {
            return null;
        }

        return $this->findById($merchantId);
    }

    public function delete(int $merchantId): bool
    {
        return $this->commandService->delete($merchantId);
    }

    public function resetPassword(int $merchantId, string $password): Merchant
    {
        return $this->commandService->resetPassword($merchantId, $password);
    }

    public function verifyPassword(Merchant $merchant, string $password): bool
    {
        return $this->commandService->verifyPassword($merchant, $password);
    }

    public function touchLoginMeta(int $merchantId, string $ip = ''): void
    {
        $this->commandService->touchLoginMeta($merchantId, $ip);
    }

    public function issueCredential(int $merchantId): array
    {
        return $this->commandService->issueCredential($merchantId);
    }

    public function overview(int $merchantId): array
    {
        return $this->overviewQueryService->overview($merchantId);
    }

    public function findEnabledMerchantByNo(string $merchantNo): Merchant
    {
        return $this->commandService->findEnabledMerchantByNo($merchantNo);
    }

    public function ensureMerchantEnabled(int $merchantId): Merchant
    {
        return $this->commandService->ensureMerchantEnabled($merchantId);
    }

    public function ensureMerchantGroupEnabled(int $groupId): MerchantGroup
    {
        return $this->commandService->ensureMerchantGroupEnabled($groupId);
    }

    public function findPolicy(int $merchantId): ?MerchantPolicy
    {
        return $this->queryService->findPolicy($merchantId);
    }
}
