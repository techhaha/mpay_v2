<?php

namespace app\service\merchant\portal;

use app\common\base\BaseService;

/**
 * 商户门户资料服务。
 *
 * @property MerchantPortalProfileQueryService $queryService 查询服务
 * @property MerchantPortalProfileCommandService $commandService 命令服务
 */
class MerchantPortalProfileService extends BaseService
{
    /**
     * 构造方法。
     *
     * @param MerchantPortalProfileQueryService $queryService 查询服务
     * @param MerchantPortalProfileCommandService $commandService 命令服务
     */
    public function __construct(
        protected MerchantPortalProfileQueryService $queryService,
        protected MerchantPortalProfileCommandService $commandService
    ) {
    }

    /**
     * 查询商户门户资料。
     *
     * @param int $merchantId 商户ID
     * @return array 资料数据
     */
    public function profile(int $merchantId): array
    {
        return $this->queryService->profile($merchantId);
    }

    /**
     * 更新商户门户资料。
     *
     * @param int $merchantId 商户ID
     * @param array $data 资料数据
     * @return array 更新后的资料数据
     */
    public function updateProfile(int $merchantId, array $data): array
    {
        return $this->commandService->updateProfile($merchantId, $data);
    }

    /**
     * 修改商户门户密码。
     *
     * @param int $merchantId 商户ID
     * @param array $data 密码数据
     * @return array 密码修改结果
     */
    public function changePassword(int $merchantId, array $data): array
    {
        return $this->commandService->changePassword($merchantId, $data);
    }
}


