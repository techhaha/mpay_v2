<?php

namespace app\service\merchant\portal;

use app\common\base\BaseService;

/**
 * 商户门户通道门面服务。
 *
 * 对外保留原有调用契约，内部委托给通道查询和路由预览子服务。
 */
class MerchantPortalChannelService extends BaseService
{
    public function __construct(
        protected MerchantPortalChannelQueryService $queryService,
        protected MerchantPortalRoutePreviewService $routePreviewService
    ) {
    }

    public function myChannels(array $filters, int $merchantId, int $page, int $pageSize): array
    {
        return $this->queryService->myChannels($filters, $merchantId, $page, $pageSize);
    }

    public function routePreview(int $merchantId, int $payTypeId, int $payAmount, string $statDate = ''): array
    {
        return $this->routePreviewService->routePreview($merchantId, $payTypeId, $payAmount, $statDate);
    }
}
