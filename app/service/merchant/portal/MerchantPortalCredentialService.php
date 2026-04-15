<?php

namespace app\service\merchant\portal;

use app\common\base\BaseService;

/**
 * 商户门户接口凭证门面服务。
 */
class MerchantPortalCredentialService extends BaseService
{
    public function __construct(
        protected MerchantPortalCredentialQueryService $queryService,
        protected MerchantPortalCredentialCommandService $commandService
    ) {
    }

    public function apiCredential(int $merchantId): array
    {
        return $this->queryService->apiCredential($merchantId);
    }

    public function issueCredential(int $merchantId): array
    {
        return $this->commandService->issueCredential($merchantId);
    }
}
