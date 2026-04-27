<?php

namespace app\service\merchant\security;

use app\common\base\BaseService;
use app\common\constant\AuthConstant;
use app\model\merchant\MerchantApiCredential;
use app\repository\merchant\credential\MerchantApiCredentialRepository;

/**
 * 商户 API 凭证查询服务。
 *
 * 负责凭证列表和详情展示，不承载验签和写入逻辑。
 *
 * @property MerchantApiCredentialRepository $merchantApiCredentialRepository 商户 API 凭证仓库
 */
class MerchantApiCredentialQueryService extends BaseService
{
    /**
     * 构造方法。
     *
     * @param MerchantApiCredentialRepository $merchantApiCredentialRepository 商户 API 凭证仓库
     */
    public function __construct(
        protected MerchantApiCredentialRepository $merchantApiCredentialRepository
    ) {
    }

    /**
     * 分页查询商户 API 凭证。
     *
     * @param array $filters 筛选条件
     * @param int $page 页码
     * @param int $pageSize 每页条数
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator 分页对象
     */
    public function paginate(array $filters = [], int $page = 1, int $pageSize = 10)
    {
        $query = $this->baseQuery(true);

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->where(function ($builder) use ($keyword) {
                $builder->where('m.merchant_no', 'like', '%' . $keyword . '%')
                    ->orWhere('m.merchant_name', 'like', '%' . $keyword . '%')
                    ->orWhere('c.api_key', 'like', '%' . $keyword . '%');
            });
        }

        $merchantId = (string) ($filters['merchant_id'] ?? '');
        if ($merchantId !== '') {
            $query->where('c.merchant_id', (int) $merchantId);
        }

        $status = (string) ($filters['status'] ?? '');
        if ($status !== '') {
            $query->where('c.status', (int) $status);
        }

        $paginator = $query
            ->orderByDesc('c.id')
            ->paginate(max(1, $pageSize), ['*'], 'page', max(1, $page));

        $paginator->getCollection()->transform(function ($row) {
            $row->sign_type_text = $this->textFromMap((int) $row->sign_type, AuthConstant::signTypeMap());
            $row->status_text = $this->textFromMap((int) $row->status, AuthConstant::credentialStatusMap());
            $row->platform_public_key_preview = $this->maskCredentialValue(
                trim((string) config('epay.v2.platform_public_key', '')),
                false
            );
            $row->platform_sign_type_text = (string) config('epay.v2.sign_type', AuthConstant::API_SIGN_NAME_SHA256_WITH_RSA);

            return $row;
        });

        return $paginator;
    }

    /**
     * 查询商户 API 凭证详情。
     *
     * @param int $id 商户 API 凭证ID
     * @return MerchantApiCredential|null 凭证模型
     */
    public function findById(int $id): ?MerchantApiCredential
    {
        $row = $this->baseQuery(false)->where('c.id', $id)->first();
        return $this->decorateRow($row);
    }

    /**
     * 查询商户对应的接口凭证详情。
     *
     * @param int $merchantId 商户ID
     * @return MerchantApiCredential|null 凭证模型
     */
    public function findByMerchantId(int $merchantId): ?MerchantApiCredential
    {
        $row = $this->baseQuery(false)->where('c.merchant_id', $merchantId)->first();
        return $this->decorateRow($row);
    }

    /**
     * 统一构造查询对象。
     *
     * @param bool $maskCredentialValue 是否脱敏接口凭证
     * @return \Illuminate\Database\Eloquent\Builder 查询构造器
     */
    private function baseQuery(bool $maskCredentialValue = false)
    {
        $query = $this->merchantApiCredentialRepository->query()
            ->from('ma_merchant_api_credential as c')
            ->leftJoin('ma_merchant as m', 'c.merchant_id', '=', 'm.id')
            ->select([
                'c.id',
                'c.merchant_id',
                'c.sign_type',
                'c.merchant_public_key',
                'c.status',
                'c.last_used_at',
                'c.created_at',
                'c.updated_at',
            ])
            ->selectRaw("COALESCE(m.merchant_no, '') AS merchant_no")
            ->selectRaw("COALESCE(m.merchant_name, '') AS merchant_name");

        if ($maskCredentialValue) {
            $query->selectRaw("CASE WHEN c.api_key IS NULL OR c.api_key = '' THEN '' ELSE CONCAT(LEFT(c.api_key, 4), '****', RIGHT(c.api_key, 4)) END AS api_key_preview");
            $query->selectRaw("CASE WHEN c.merchant_public_key IS NULL OR c.merchant_public_key = '' THEN '' ELSE CONCAT(LEFT(c.merchant_public_key, 12), '****', RIGHT(c.merchant_public_key, 12)) END AS merchant_public_key_preview");
        } else {
            $query->addSelect('c.api_key');
            $query->addSelect('c.merchant_public_key');
            $query->selectRaw("COALESCE(c.api_key, '') AS api_key_full");
            $query->selectRaw("COALESCE(c.merchant_public_key, '') AS merchant_public_key_full");
        }

        return $query;
    }

    /**
     * 给详情行补充展示字段。
     *
     * @param object|null $row 原始记录对象
     * @return MerchantApiCredential|null 凭证模型
     */
    private function decorateRow(mixed $row): ?MerchantApiCredential
    {
        if (!$row) {
            return null;
        }

        $row->api_key_preview = $this->maskCredentialValue((string) ($row->api_key ?? ''), false);
        $row->merchant_public_key_preview = $this->maskCredentialValue((string) ($row->merchant_public_key ?? ''), false);
        $row->sign_type_text = $this->textFromMap((int) $row->sign_type, AuthConstant::signTypeMap());
        $row->status_text = $this->textFromMap((int) $row->status, AuthConstant::credentialStatusMap());
        $row->platform_public_key_full = trim((string) config('epay.v2.platform_public_key', ''));
        $row->platform_public_key_preview = $this->maskCredentialValue((string) $row->platform_public_key_full, false);
        $row->platform_sign_type_text = (string) config('epay.v2.sign_type', AuthConstant::API_SIGN_NAME_SHA256_WITH_RSA);

        return $row;
    }
}
