<?php

namespace app\service\merchant\portal;

use app\common\base\BaseService;

/**
 * 商户门户 API 凭证服务。
 *
 * @property MerchantPortalCredentialQueryService $queryService 查询服务
 * @property MerchantPortalCredentialCommandService $commandService 命令服务
 */
class MerchantPortalCredentialService extends BaseService
{
    /**
     * 构造方法。
     *
     * @param MerchantPortalCredentialQueryService $queryService 查询服务
     * @param MerchantPortalCredentialCommandService $commandService 命令服务
     */
    public function __construct(
        protected MerchantPortalCredentialQueryService $queryService,
        protected MerchantPortalCredentialCommandService $commandService
    ) {
    }

    /**
     * 查询商户 API 凭证。
     *
     * @param int $merchantId 商户ID
     * @return array 凭证数据
     */
    public function apiCredential(int $merchantId): array
    {
        return $this->queryService->apiCredential($merchantId);
    }

    /**
     * 生成或重置商户 API 凭证。
     *
     * @param int $merchantId 商户ID
     * @param array $options 生成选项
     * @return array 凭证数据
     */
    public function issueCredential(int $merchantId, array $options = []): array
    {
        return $this->commandService->issueCredential($merchantId, $options);
    }
}
