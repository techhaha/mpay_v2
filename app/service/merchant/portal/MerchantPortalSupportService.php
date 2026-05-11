<?php

namespace app\service\merchant\portal;

use app\common\base\BaseService;
use app\exception\ResourceNotFoundException;
use app\repository\merchant\base\MerchantRepository;
use app\service\merchant\MerchantService;
use app\service\payment\config\PaymentTypeService;

/**
 * 商户门户支持服务。
 *
 * 统一承接商户门户里复用的商户摘要、支付方式和展示整理能力。
 *
 * @property MerchantService $merchantService 商户服务
 * @property MerchantRepository $merchantRepository 商户仓库
 * @property PaymentTypeService $paymentTypeService 支付类型服务
 */
class MerchantPortalSupportService extends BaseService
{
    /**
     * 构造方法。
     *
     * @param MerchantService $merchantService 商户服务
     * @param MerchantRepository $merchantRepository 商户仓库
     * @param PaymentTypeService $paymentTypeService 支付类型服务
     */
    public function __construct(
        protected MerchantService $merchantService,
        protected MerchantRepository $merchantRepository,
        protected PaymentTypeService $paymentTypeService
    ) {
    }

    /**
     * 当前商户基础资料摘要。
     *
     * @param int $merchantId 商户ID
     * @return array 商户摘要
     * @throws ResourceNotFoundException
     */
    public function merchantSummary(int $merchantId): array
    {
        $this->merchantService->ensureMerchantEnabled($merchantId);

        $row = $this->merchantRepository->query()
            ->from('ma_merchant as m')
            ->leftJoin('ma_merchant_group as g', 'm.group_id', '=', 'g.id')
            ->select([
                'm.id',
                'm.merchant_no',
                'm.merchant_name',
                'm.merchant_short_name',
                'm.merchant_type',
                'm.group_id',
                'm.risk_level',
                'm.contact_name',
                'm.contact_phone',
                'm.contact_email',
                'm.settlement_account_name',
                'm.settlement_account_no',
                'm.settlement_bank_name',
                'm.settlement_bank_branch',
                'm.status',
                'm.last_login_at',
                'm.last_login_ip',
                'm.password_updated_at',
                'm.remark',
                'm.created_at',
                'm.updated_at',
            ])
            ->selectRaw("COALESCE(g.group_name, '未分组') AS merchant_group_name")
            ->selectRaw("COALESCE(m.settlement_account_name, '') AS settlement_account_name_text")
            ->selectRaw("CASE WHEN m.settlement_account_no IS NULL OR m.settlement_account_no = '' THEN '' ELSE CONCAT(LEFT(m.settlement_account_no, 4), '****', RIGHT(m.settlement_account_no, 4)) END AS settlement_account_no_masked")
            ->selectRaw("COALESCE(m.settlement_bank_name, '') AS settlement_bank_name_text")
            ->selectRaw("COALESCE(m.settlement_bank_branch, '') AS settlement_bank_branch_text")
            ->selectRaw("CASE m.merchant_type WHEN 0 THEN '个人' WHEN 1 THEN '企业' ELSE '其他' END AS merchant_type_text")
            ->selectRaw("CASE m.risk_level WHEN 0 THEN '低' WHEN 1 THEN '中' WHEN 2 THEN '高' ELSE '未知' END AS risk_level_text")
            ->selectRaw("CASE m.status WHEN 0 THEN '停用' WHEN 1 THEN '启用' ELSE '未知' END AS status_text")
            ->where('m.id', $merchantId)
            ->first();

        if (!$row) {
            throw new ResourceNotFoundException('商户不存在', ['merchant_id' => $merchantId]);
        }

        return [
            'id' => (int) $row->id,
            'merchant_id' => (int) $row->id,
            'merchant_no' => (string) $row->merchant_no,
            'merchant_name' => (string) $row->merchant_name,
            'merchant_short_name' => (string) $row->merchant_short_name,
            'merchant_type' => (int) $row->merchant_type,
            'merchant_type_text' => (string) $row->merchant_type_text,
            'merchant_group_id' => (int) $row->group_id,
            'merchant_group_name' => (string) $row->merchant_group_name,
            'risk_level' => (int) $row->risk_level,
            'risk_level_text' => (string) $row->risk_level_text,
            'contact_name' => (string) $row->contact_name,
            'contact_phone' => (string) $row->contact_phone,
            'contact_email' => (string) $row->contact_email,
            'settlement_account_name' => (string) $row->settlement_account_name,
            'settlement_account_no' => (string) $row->settlement_account_no,
            'settlement_bank_name' => (string) $row->settlement_bank_name,
            'settlement_bank_branch' => (string) $row->settlement_bank_branch,
            'settlement_account_name_text' => (string) $row->settlement_account_name_text,
            'settlement_account_no_masked' => (string) $row->settlement_account_no_masked,
            'settlement_bank_name_text' => (string) $row->settlement_bank_name_text,
            'settlement_bank_branch_text' => (string) $row->settlement_bank_branch_text,
            'status' => (int) $row->status,
            'status_text' => (string) $row->status_text,
            'last_login_at' => $this->formatDateTime($row->last_login_at ?? null),
            'last_login_ip' => (string) ($row->last_login_ip ?? ''),
            'password_updated_at' => $this->formatDateTime($row->password_updated_at ?? null),
            'remark' => (string) $row->remark,
            'created_at' => $this->formatDateTime($row->created_at ?? null),
            'updated_at' => $this->formatDateTime($row->updated_at ?? null),
        ];
    }

    /**
     * 获取启用的支付方式选项。
     *
     * @return array 支付方式选项
     */
    public function enabledPayTypeOptions(): array
    {
        return array_values(array_filter(
            $this->paymentTypeService->enabledOptions(),
            static function (array $option): bool {
                $label = trim((string) ($option['label'] ?? ''));
                $value = trim((string) ($option['value'] ?? ''));

                return $label !== '' && $label !== $value;
            }
        ));
    }

    /**
     * 商户开放接口对接信息。
     *
     * @param array $merchant 商户摘要
     * @return array<string, mixed> 对接信息
     */
    public function apiIntegrationInfo(array $merchant): array
    {
        $baseUrl = rtrim((string) sys_config('site_url', ''), '/');

        return [
            'base_url' => $baseUrl,
            'merchant_id' => (int) ($merchant['merchant_id'] ?? $merchant['id'] ?? 0),
        ];
    }

    /**
     * 根据支付方式 ID 获取名称。
     *
     * @param int $payTypeId 支付类型ID
     * @return string 支付方式名称
     */
    public function paymentTypeName(int $payTypeId): string
    {
        foreach ($this->paymentTypeService->enabledOptions() as $option) {
            if ((int) ($option['value'] ?? 0) === $payTypeId) {
                return (string) ($option['label'] ?? '');
            }
        }

        return $payTypeId > 0 ? '未知' : '';
    }

}
