<?php

namespace app\service\merchant\portal;

use app\common\base\BaseService;

/**
 * 商户门户资料门面服务。
 */
class MerchantPortalProfileService extends BaseService
{
    public function __construct(
        protected MerchantPortalProfileQueryService $queryService,
        protected MerchantPortalProfileCommandService $commandService
    ) {
    }

    public function profile(int $merchantId): array
    {
        return $this->queryService->profile($merchantId);
    }

    public function updateProfile(int $merchantId, array $data): array
    {
        return $this->commandService->updateProfile($merchantId, $data);
    }

    public function changePassword(int $merchantId, array $data): array
    {
        return $this->commandService->changePassword($merchantId, $data);
    }
}
