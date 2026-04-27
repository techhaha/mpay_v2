<?php

namespace app\service\merchant\portal;

use app\common\base\BaseService;

/**
 * 商户门户通道服务。
 *
 * @property MerchantPortalChannelQueryService $queryService 查询服务
 * @property MerchantPortalChannelCommandService $commandService 命令服务
 * @property MerchantPortalRoutePreviewService $routePreviewService 路由解析服务
 */
class MerchantPortalChannelService extends BaseService
{
    /**
     * 构造方法。
     *
     * @param MerchantPortalChannelQueryService $queryService 查询服务
     * @param MerchantPortalChannelCommandService $commandService 命令服务
     * @param MerchantPortalRoutePreviewService $routePreviewService 路由解析服务
     */
    public function __construct(
        protected MerchantPortalChannelQueryService $queryService,
        protected MerchantPortalChannelCommandService $commandService,
        protected MerchantPortalRoutePreviewService $routePreviewService
    ) {
    }

    /**
     * 查询当前商户已开通的渠道。
     *
     * @param array $filters 筛选条件
     * @param int $merchantId 商户ID
     * @param int $page 页码
     * @param int $pageSize 每页条数
     * @return array 渠道列表数据
     */
    public function myChannels(array $filters, int $merchantId, int $page, int $pageSize): array
    {
        return $this->queryService->myChannels($filters, $merchantId, $page, $pageSize);
    }

    /**
     * 获取商户渠道路由解析结果。
     *
     * @param int $merchantId 商户ID
     * @param int $payTypeId 支付类型ID
     * @param int $payAmount 支付金额
     * @param string $statDate 统计日期
     * @return array 路由解析数据
     */
    public function routePreview(int $merchantId, int $payTypeId, int $payAmount, string $statDate = ''): array
    {
        return $this->routePreviewService->routePreview($merchantId, $payTypeId, $payAmount, $statDate);
    }

    public function createMeta(): array
    {
        return $this->commandService->createMeta();
    }

    public function pluginConfigs(array $filters, int $merchantId, int $page, int $pageSize): array
    {
        return $this->commandService->pluginConfigs($filters, $merchantId, $page, $pageSize);
    }

    public function createPluginConfig(int $merchantId, array $data)
    {
        return $this->commandService->createPluginConfig($merchantId, $data);
    }

    public function updatePluginConfig(int $merchantId, int $id, array $data)
    {
        return $this->commandService->updatePluginConfig($merchantId, $id, $data);
    }

    public function deletePluginConfig(int $merchantId, int $id): bool
    {
        return $this->commandService->deletePluginConfig($merchantId, $id);
    }

    public function pluginConfigOptions(int $merchantId, string $pluginCode = ''): array
    {
        return $this->commandService->pluginConfigOptions($merchantId, $pluginCode);
    }

    public function createChannel(int $merchantId, array $data)
    {
        return $this->commandService->createChannel($merchantId, $data);
    }

    public function updateChannel(int $merchantId, int $id, array $data)
    {
        return $this->commandService->updateChannel($merchantId, $id, $data);
    }

    public function deleteChannel(int $merchantId, int $id): bool
    {
        return $this->commandService->deleteChannel($merchantId, $id);
    }

    public function pluginSchema(string $pluginCode): array
    {
        return $this->commandService->pluginSchema($pluginCode);
    }
}
