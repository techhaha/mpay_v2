<?php

namespace app\service\merchant;

use app\common\base\BaseService;
use app\common\constant\CommonConstant;
use app\exception\BusinessStateException;
use app\exception\ResourceNotFoundException;
use app\exception\ValidationException;
use app\model\merchant\Merchant;
use app\model\merchant\MerchantGroup;
use app\repository\account\balance\MerchantAccountRepository;
use app\repository\merchant\credential\MerchantApiCredentialRepository;
use app\repository\merchant\base\MerchantGroupRepository;
use app\repository\merchant\base\MerchantRepository;
use app\repository\payment\config\PaymentChannelRepository;
use app\repository\payment\settlement\SettlementOrderRepository;
use app\repository\payment\trade\BizOrderRepository;
use app\repository\payment\trade\RefundOrderRepository;
use app\service\account\funds\MerchantAccountService;
use app\service\merchant\security\MerchantApiCredentialService;

/**
 * 商户命令服务。
 *
 * 负责商户创建、更新、删除、密码和登录元数据这类写操作。
 */
class MerchantCommandService extends BaseService
{
    public function __construct(
        protected MerchantRepository $merchantRepository,
        protected MerchantGroupRepository $merchantGroupRepository,
        protected MerchantQueryService $merchantQueryService,
        protected MerchantApiCredentialService $merchantApiCredentialService,
        protected MerchantAccountRepository $merchantAccountRepository,
        protected MerchantApiCredentialRepository $merchantApiCredentialRepository,
        protected PaymentChannelRepository $paymentChannelRepository,
        protected BizOrderRepository $bizOrderRepository,
        protected RefundOrderRepository $refundOrderRepository,
        protected SettlementOrderRepository $settlementOrderRepository,
        protected MerchantAccountService $merchantAccountService
    ) {
    }

    public function create(array $data): Merchant
    {
        return $this->transaction(function () use ($data) {
            $merchantName = trim((string) ($data['merchant_name'] ?? ''));
            $contactName = trim((string) ($data['contact_name'] ?? ''));
            $contactPhone = trim((string) ($data['contact_phone'] ?? ''));
            $groupId = (int) ($data['group_id'] ?? 0);
            if ($merchantName === '') {
                throw new ValidationException('商户名称不能为空');
            }
            if ($groupId <= 0) {
                throw new ValidationException('请选择商户分组');
            }
            if ($contactName === '') {
                throw new ValidationException('联系人不能为空');
            }
            if ($contactPhone === '') {
                throw new ValidationException('联系电话不能为空');
            }
            if ($groupId > 0) {
                $this->ensureMerchantGroupEnabled($groupId);
            }

            $merchantNo = $this->generateMerchantNo();
            $plainPassword = $this->generateTemporaryPassword();

            $merchant = $this->merchantRepository->create([
                'merchant_no' => $merchantNo,
                'password_hash' => password_hash($plainPassword, PASSWORD_DEFAULT),
                'merchant_name' => $merchantName,
                'merchant_short_name' => trim((string) ($data['merchant_short_name'] ?? '')),
                'merchant_type' => (int) ($data['merchant_type'] ?? 0),
                'group_id' => $groupId,
                'risk_level' => (int) ($data['risk_level'] ?? 0),
                'contact_name' => $contactName,
                'contact_phone' => $contactPhone,
                'contact_email' => trim((string) ($data['contact_email'] ?? '')),
                'settlement_account_name' => trim((string) ($data['settlement_account_name'] ?? '')),
                'settlement_account_no' => trim((string) ($data['settlement_account_no'] ?? '')),
                'settlement_bank_name' => trim((string) ($data['settlement_bank_name'] ?? '')),
                'settlement_bank_branch' => trim((string) ($data['settlement_bank_branch'] ?? '')),
                'status' => (int) ($data['status'] ?? CommonConstant::STATUS_ENABLED),
                'password_updated_at' => $this->now(),
                'remark' => trim((string) ($data['remark'] ?? '')),
            ]);

            $merchant->plain_password = $plainPassword;

            $this->merchantAccountService->ensureAccountInCurrentTransaction((int) $merchant->id);

            return $merchant;
        });
    }

    public function update(int $merchantId, array $data): ?Merchant
    {
        $merchant = $this->merchantRepository->find($merchantId);
        if (!$merchant) {
            return null;
        }

        $groupId = array_key_exists('group_id', $data) ? (int) $data['group_id'] : (int) $merchant->group_id;
        if ($groupId > 0) {
            $this->ensureMerchantGroupEnabled($groupId);
        }

        $payload = [
            'merchant_name' => (string) ($data['merchant_name'] ?? $merchant->merchant_name),
            'merchant_short_name' => (string) ($data['merchant_short_name'] ?? $merchant->merchant_short_name),
            'merchant_type' => (int) ($data['merchant_type'] ?? $merchant->merchant_type),
            'group_id' => $groupId,
            'risk_level' => (int) ($data['risk_level'] ?? $merchant->risk_level),
            'contact_name' => (string) ($data['contact_name'] ?? $merchant->contact_name),
            'contact_phone' => (string) ($data['contact_phone'] ?? $merchant->contact_phone),
            'contact_email' => (string) ($data['contact_email'] ?? $merchant->contact_email),
            'settlement_account_name' => (string) ($data['settlement_account_name'] ?? $merchant->settlement_account_name),
            'settlement_account_no' => (string) ($data['settlement_account_no'] ?? $merchant->settlement_account_no),
            'settlement_bank_name' => (string) ($data['settlement_bank_name'] ?? $merchant->settlement_bank_name),
            'settlement_bank_branch' => (string) ($data['settlement_bank_branch'] ?? $merchant->settlement_bank_branch),
            'status' => (int) ($data['status'] ?? $merchant->status),
            'remark' => (string) ($data['remark'] ?? $merchant->remark),
        ];

        if (!$this->merchantRepository->updateById($merchantId, $payload)) {
            return null;
        }

        return $this->merchantRepository->find($merchantId);
    }

    public function delete(int $merchantId): bool
    {
        $merchant = $this->merchantRepository->find($merchantId);
        if (!$merchant) {
            throw new ResourceNotFoundException('商户不存在', ['merchant_id' => $merchantId]);
        }

        $dependencies = [
            ['count' => $this->paymentChannelRepository->countByMerchantId($merchantId), 'message' => '已配置支付通道'],
            ['count' => $this->bizOrderRepository->countByMerchantId($merchantId), 'message' => '已存在支付订单'],
            ['count' => $this->refundOrderRepository->countByMerchantId($merchantId), 'message' => '已存在退款订单'],
            ['count' => $this->settlementOrderRepository->countByMerchantId($merchantId), 'message' => '已存在清算记录'],
            ['count' => $this->merchantAccountRepository->countByMerchantId($merchantId), 'message' => '已存在资金账户'],
            ['count' => $this->merchantApiCredentialRepository->countByMerchantId($merchantId), 'message' => '已开通接口凭证'],
        ];

        foreach ($dependencies as $dependency) {
            if ((int) $dependency['count'] > 0) {
                throw new BusinessStateException("当前商户{$dependency['message']}，请先清理关联数据后再删除", [
                    'merchant_id' => $merchantId,
                    'message' => $dependency['message'],
                ]);
            }
        }

        return $this->merchantRepository->deleteById($merchantId);
    }

    public function resetPassword(int $merchantId, string $password): Merchant
    {
        $merchant = $this->merchantRepository->find($merchantId);
        if (!$merchant) {
            throw new ResourceNotFoundException('商户不存在', ['merchant_id' => $merchantId]);
        }

        $this->merchantRepository->updateById($merchantId, [
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'password_updated_at' => $this->now(),
        ]);

        return $this->merchantRepository->find($merchantId);
    }

    public function verifyPassword(Merchant $merchant, string $password): bool
    {
        return $password !== '' && password_verify($password, (string) $merchant->password_hash);
    }

    public function touchLoginMeta(int $merchantId, string $ip = ''): void
    {
        $this->merchantRepository->updateById($merchantId, [
            'last_login_at' => $this->now(),
            'last_login_ip' => trim($ip),
        ]);
    }

    public function issueCredential(int $merchantId): array
    {
        $merchant = $this->merchantQueryService->findById($merchantId);
        if (!$merchant) {
            throw new ResourceNotFoundException('商户不存在', ['merchant_id' => $merchantId]);
        }

        $credentialValue = $this->merchantApiCredentialService->issueCredential($merchantId);
        $credential = $this->merchantApiCredentialService->findByMerchantId($merchantId);

        return [
            'merchant' => $merchant,
            'credential_value' => $credentialValue,
            'credential' => $credential,
        ];
    }

    public function findEnabledMerchantByNo(string $merchantNo): Merchant
    {
        $merchant = $this->merchantRepository->findByMerchantNo($merchantNo);

        if (!$merchant) {
            throw new ResourceNotFoundException('商户不存在', ['merchant_no' => $merchantNo]);
        }

        if ((int) $merchant->status !== CommonConstant::STATUS_ENABLED) {
            throw new BusinessStateException('商户已禁用', ['merchant_no' => $merchantNo]);
        }

        return $merchant;
    }

    public function ensureMerchantEnabled(int $merchantId): Merchant
    {
        $merchant = $this->merchantRepository->find($merchantId);

        if (!$merchant) {
            throw new ResourceNotFoundException('商户不存在', ['merchant_id' => $merchantId]);
        }

        if ((int) $merchant->status !== CommonConstant::STATUS_ENABLED) {
            throw new BusinessStateException('商户已禁用', ['merchant_id' => $merchantId]);
        }

        return $merchant;
    }

    public function ensureMerchantGroupEnabled(int $groupId): MerchantGroup
    {
        $group = $this->merchantGroupRepository->find($groupId);

        if (!$group) {
            throw new ResourceNotFoundException('商户分组不存在', ['merchant_group_id' => $groupId]);
        }

        if ((int) $group->status !== CommonConstant::STATUS_ENABLED) {
            throw new BusinessStateException('商户分组已禁用', ['merchant_group_id' => $groupId]);
        }

        return $group;
    }

    private function generateMerchantNo(): string
    {
        do {
            $merchantNo = $this->generateNo('M');
        } while ($this->merchantRepository->findByMerchantNo($merchantNo) !== null);

        return $merchantNo;
    }

    /**
     * 生成商户初始临时密码。
     */
    private function generateTemporaryPassword(): string
    {
        return bin2hex(random_bytes(8));
    }
}
