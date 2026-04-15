<?php

namespace app\service\merchant\portal;

use app\common\base\BaseService;
use app\exception\ResourceNotFoundException;
use app\exception\ValidationException;
use app\repository\merchant\base\MerchantRepository;

/**
 * 商户门户资料命令服务。
 */
class MerchantPortalProfileCommandService extends BaseService
{
    public function __construct(
        protected MerchantPortalSupportService $supportService,
        protected MerchantRepository $merchantRepository
    ) {
    }

    public function updateProfile(int $merchantId, array $data): array
    {
        $merchant = $this->merchantRepository->find($merchantId);
        if (!$merchant) {
            throw new ResourceNotFoundException('商户不存在', ['merchant_id' => $merchantId]);
        }

        $this->merchantRepository->updateById($merchantId, [
            'merchant_short_name' => trim((string) ($data['merchant_short_name'] ?? $merchant->merchant_short_name)),
            'contact_name' => trim((string) ($data['contact_name'] ?? $merchant->contact_name)),
            'contact_phone' => trim((string) ($data['contact_phone'] ?? $merchant->contact_phone)),
            'contact_email' => trim((string) ($data['contact_email'] ?? $merchant->contact_email)),
            'settlement_account_name' => trim((string) ($data['settlement_account_name'] ?? $merchant->settlement_account_name)),
            'settlement_account_no' => trim((string) ($data['settlement_account_no'] ?? $merchant->settlement_account_no)),
            'settlement_bank_name' => trim((string) ($data['settlement_bank_name'] ?? $merchant->settlement_bank_name)),
            'settlement_bank_branch' => trim((string) ($data['settlement_bank_branch'] ?? $merchant->settlement_bank_branch)),
        ]); 

        return [
            'merchant' => $this->supportService->merchantSummary($merchantId),
            'pay_types' => $this->supportService->enabledPayTypeOptions(),
        ];
    }

    public function changePassword(int $merchantId, array $data): array
    {
        $merchant = $this->merchantRepository->find($merchantId);
        if (!$merchant) {
            throw new ResourceNotFoundException('商户不存在', ['merchant_id' => $merchantId]);
        }

        $currentPassword = trim((string) ($data['current_password'] ?? ''));
        $newPassword = trim((string) ($data['password'] ?? ''));

        if (!password_verify($currentPassword, (string) $merchant->password_hash)) {
            throw new ValidationException('当前密码不正确');
        }

        $this->merchantRepository->updateById($merchantId, [
            'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
            'password_updated_at' => $this->now(),
        ]);

        return [
            'updated' => true,
            'password_updated_at' => $this->supportService->formatDateTime($this->now()),
        ];
    }
}
