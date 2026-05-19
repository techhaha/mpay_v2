<?php

namespace app\service\merchant\portal;

use app\common\base\BaseService;

/**
 * 商户后台门户服务。
 *
 * @property MerchantPortalProfileService $profileService 资料服务
 * @property MerchantPortalChannelService $channelService 渠道服务
 * @property MerchantPortalCredentialService $credentialService 凭证服务
 * @property MerchantPortalFinanceService $financeService 财务服务
 */
class MerchantPortalService extends BaseService
{
    /**
     * 构造方法。
     *
     * @param MerchantPortalProfileService $profileService 资料服务
     * @param MerchantPortalChannelService $channelService 渠道服务
     * @param MerchantPortalCredentialService $credentialService 凭证服务
     * @param MerchantPortalFinanceService $financeService 财务服务
     */
    public function __construct(
        protected MerchantPortalProfileService $profileService,
        protected MerchantPortalChannelService $channelService,
        protected MerchantPortalCredentialService $credentialService,
        protected MerchantPortalFinanceService $financeService
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
        return $this->profileService->profile($merchantId);
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
        return $this->profileService->updateProfile($merchantId, $data);
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
        return $this->profileService->changePassword($merchantId, $data);
    }

    /**
     * 查询当前商户已开通的渠道。
     *
     * @param array $filters 筛选条件
     * @param int $merchantId 商户ID
     * @param int $page 页码
     * @param int $pageSize 每页条数
     * @return array 渠道列表
     */
    public function myChannels(array $filters, int $merchantId, int $page, int $pageSize): array
    {
        return $this->channelService->myChannels($filters, $merchantId, $page, $pageSize);
    }

    public function channelCreateMeta(): array
    {
        return $this->channelService->createMeta();
    }

    public function pluginConfigs(array $filters, int $merchantId, int $page, int $pageSize): array
    {
        return $this->channelService->pluginConfigs($filters, $merchantId, $page, $pageSize);
    }

    public function pluginConfigDetail(int $merchantId, int $id)
    {
        return $this->channelService->pluginConfigDetail($merchantId, $id);
    }

    public function createPluginConfig(int $merchantId, array $data)
    {
        return $this->channelService->createPluginConfig($merchantId, $data);
    }

    public function updatePluginConfig(int $merchantId, int $id, array $data)
    {
        return $this->channelService->updatePluginConfig($merchantId, $id, $data);
    }

    public function deletePluginConfig(int $merchantId, int $id): bool
    {
        return $this->channelService->deletePluginConfig($merchantId, $id);
    }

    public function pluginConfigOptions(int $merchantId, string $pluginCode = ''): array
    {
        return $this->channelService->pluginConfigOptions($merchantId, $pluginCode);
    }

    public function createChannel(int $merchantId, array $data)
    {
        return $this->channelService->createChannel($merchantId, $data);
    }

    public function updateChannel(int $merchantId, int $id, array $data)
    {
        return $this->channelService->updateChannel($merchantId, $id, $data);
    }

    public function deleteChannel(int $merchantId, int $id): bool
    {
        return $this->channelService->deleteChannel($merchantId, $id);
    }

    public function pluginSchema(string $pluginCode): array
    {
        return $this->channelService->pluginSchema($pluginCode);
    }

    /**
     * 获取商户路由解析结果。
     *
     * @param int $merchantId 商户ID
     * @param int $payTypeId 支付类型ID
     * @param int $payAmount 支付金额
     * @param string $statDate 统计日期
     * @return array 路由解析数据
     */
    public function routePreview(int $merchantId, int $payTypeId, int $payAmount, string $statDate = ''): array
    {
        return $this->channelService->routePreview($merchantId, $payTypeId, $payAmount, $statDate);
    }

    /**
     * 查询商户路由偏好配置。
     *
     * @param int $merchantId 商户ID
     * @return array 配置数据
     */
    public function routeConfig(int $merchantId): array
    {
        return $this->channelService->routeConfig($merchantId);
    }

    /**
     * 保存商户路由偏好配置。
     *
     * @param int $merchantId 商户ID
     * @param array $payload 配置数据
     * @return array 保存后的配置
     */
    public function saveRouteConfig(int $merchantId, array $payload): array
    {
        return $this->channelService->saveRouteConfig($merchantId, $payload);
    }

    /**
     * 查询商户 API 凭证。
     *
     * @param int $merchantId 商户ID
     * @return array 凭证数据
     */
    public function apiCredential(int $merchantId): array
    {
        return $this->credentialService->apiCredential($merchantId);
    }

    /**
     * 生成或重置商户门户接口凭证。
     *
     * @param int $merchantId 商户ID
     * @param array $options 生成选项
     * @return array 凭证数据
     */
    public function issueCredential(int $merchantId, array $options = []): array
    {
        return $this->credentialService->issueCredential($merchantId, $options);
    }

    /**
     * 查询商户结算记录。
     *
     * @param array $filters 筛选条件
     * @param int $merchantId 商户ID
     * @param int $page 页码
     * @param int $pageSize 每页条数
     * @return array 结算记录列表
     */
    public function settlementRecords(array $filters, int $merchantId, int $page, int $pageSize): array
    {
        return $this->financeService->settlementRecords($filters, $merchantId, $page, $pageSize);
    }

    /**
     * 查询商户结算记录详情。
     *
     * @param string $settleNo 结算单号
     * @param int $merchantId 商户ID
     * @return array|null 结算详情
     */
    public function settlementRecordDetail(string $settleNo, int $merchantId): ?array
    {
        return $this->financeService->settlementRecordDetail($settleNo, $merchantId);
    }

    /**
     * 查询商户可提现余额。
     *
     * @param int $merchantId 商户ID
     * @return array 余额数据
     */
    public function withdrawableBalance(int $merchantId): array
    {
        return $this->financeService->withdrawableBalance($merchantId);
    }

    /**
     * 查询商户资金流水。
     *
     * @param array $filters 筛选条件
     * @param int $merchantId 商户ID
     * @param int $page 页码
     * @param int $pageSize 每页条数
     * @return array 流水列表
     */
    public function balanceFlows(array $filters, int $merchantId, int $page, int $pageSize): array
    {
        return $this->financeService->balanceFlows($filters, $merchantId, $page, $pageSize);
    }
}
