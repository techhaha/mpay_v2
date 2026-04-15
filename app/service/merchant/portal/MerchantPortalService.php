<?php

namespace app\service\merchant\portal;

use app\common\base\BaseService;

/**
 * 商户后台基础页面服务门面。
 *
 * 仅保留控制器依赖的统一入口，具体能力拆到资料、通道、凭证和资金子服务。
 */
class MerchantPortalService extends BaseService
{
    public function __construct(
        protected MerchantPortalProfileService $profileService,
        protected MerchantPortalChannelService $channelService,
        protected MerchantPortalCredentialService $credentialService,
        protected MerchantPortalFinanceService $financeService
    ) {
    }

    public function profile(int $merchantId): array
    {
        return $this->profileService->profile($merchantId);
    }

    public function updateProfile(int $merchantId, array $data): array
    {
        return $this->profileService->updateProfile($merchantId, $data);
    }

    public function changePassword(int $merchantId, array $data): array
    {
        return $this->profileService->changePassword($merchantId, $data);
    }

    public function myChannels(array $filters, int $merchantId, int $page, int $pageSize): array
    {
        return $this->channelService->myChannels($filters, $merchantId, $page, $pageSize);
    }

    public function routePreview(int $merchantId, int $payTypeId, int $payAmount, string $statDate = ''): array
    {
        return $this->channelService->routePreview($merchantId, $payTypeId, $payAmount, $statDate);
    }

    public function apiCredential(int $merchantId): array
    {
        return $this->credentialService->apiCredential($merchantId);
    }

    public function issueCredential(int $merchantId): array
    {
        return $this->credentialService->issueCredential($merchantId);
    }

    public function settlementRecords(array $filters, int $merchantId, int $page, int $pageSize): array
    {
        return $this->financeService->settlementRecords($filters, $merchantId, $page, $pageSize);
    }

    public function settlementRecordDetail(string $settleNo, int $merchantId): ?array
    {
        return $this->financeService->settlementRecordDetail($settleNo, $merchantId);
    }

    public function withdrawableBalance(int $merchantId): array
    {
        return $this->financeService->withdrawableBalance($merchantId);
    }

    public function balanceFlows(array $filters, int $merchantId, int $page, int $pageSize): array
    {
        return $this->financeService->balanceFlows($filters, $merchantId, $page, $pageSize);
    }
}
