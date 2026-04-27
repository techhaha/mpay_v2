<?php

namespace app\service\merchant\security;

use app\common\base\BaseService;
use app\common\constant\AuthConstant;
use app\common\constant\CommonConstant;
use app\common\util\RsaKeyPairGenerator;
use app\exception\ResourceNotFoundException;
use app\exception\ValidationException;
use app\model\merchant\Merchant;
use app\model\merchant\MerchantApiCredential;
use app\repository\merchant\credential\MerchantApiCredentialRepository;
use app\repository\merchant\base\MerchantRepository;

/**
 * 商户对外接口凭证服务。
 *
 * 负责接口凭证发放、查询和最近使用时间更新。
 *
 * @property MerchantRepository $merchantRepository 商户仓库
 * @property MerchantApiCredentialRepository $merchantApiCredentialRepository 商户 API 凭证仓库
 * @property MerchantApiCredentialQueryService $merchantApiCredentialQueryService 商户 API 凭证查询服务
 */
class MerchantApiCredentialService extends BaseService
{
    /**
     * 构造方法。
     *
     * @param MerchantRepository $merchantRepository 商户仓库
     * @param MerchantApiCredentialRepository $merchantApiCredentialRepository 商户 API 凭证仓库
     * @param MerchantApiCredentialQueryService $merchantApiCredentialQueryService 商户 API 凭证查询服务
     */
    public function __construct(
        protected MerchantRepository $merchantRepository,
        protected MerchantApiCredentialRepository $merchantApiCredentialRepository,
        protected MerchantApiCredentialQueryService $merchantApiCredentialQueryService
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
        return $this->merchantApiCredentialQueryService->paginate($filters, $page, $pageSize);
    }

    /**
     * 为商户生成并保存一份新的 V1 接口凭证。
     *
     * 返回值是明文接口凭证值，只会在调用时完整出现一次，后续仅保存脱敏展示。
     *
     * @param int $merchantId 商户ID
     * @return string 新接口凭证
     * @throws ResourceNotFoundException
     */
    public function issueCredential(int $merchantId): string
    {
        $result = $this->issueCredentialBundle($merchantId, [
            'rotate_v1' => true,
            'rotate_v2' => false,
        ]);

        return (string) ($result['credential_value'] ?? '');
    }

    /**
     * 为商户生成一组接口凭证。
     *
     * 该方法可同时重置 V1 API Key 和 V2 RSA 密钥对，适合管理后台的自动生成场景。
     * 生成后的私钥只在返回结果里出现一次，不会落库。
     *
     * @param int $merchantId 商户ID
     * @param array<string, mixed> $options 生成选项
     * @return array<string, mixed> 凭证数据和生成结果
     * @throws ResourceNotFoundException
     * @throws ValidationException
     */
    public function issueCredentialBundle(int $merchantId, array $options = []): array
    {
        $merchant = $this->merchantRepository->find($merchantId);
        if (!$merchant) {
            throw new ResourceNotFoundException('商户不存在', ['merchant_id' => $merchantId]);
        }

        $current = $this->merchantApiCredentialRepository->findByMerchantId($merchantId);
        $rotateV1 = array_key_exists('rotate_v1', $options) ? (bool) $options['rotate_v1'] : true;
        $rotateV2 = array_key_exists('rotate_v2', $options) ? (bool) $options['rotate_v2'] : true;
        if (!$rotateV1 && !$rotateV2) {
            throw new ValidationException('请至少选择一种要生成的凭证类型');
        }

        $signType = (int) ($options['sign_type'] ?? ($current?->sign_type ?? AuthConstant::API_SIGN_TYPE_MD5));
        $status = (int) ($options['status'] ?? ($current?->status ?? AuthConstant::CREDENTIAL_STATUS_ENABLED));
        $credentialValue = $rotateV1 ? $this->generateCredentialValue() : trim((string) ($current?->api_key ?? ''));
        $merchantPrivateKey = '';
        $merchantPublicKey = trim((string) ($current?->merchant_public_key ?? ''));

        if ($rotateV2) {
            $pair = RsaKeyPairGenerator::generate();
            $merchantPrivateKey = $pair['private_key'];
            $merchantPublicKey = $pair['public_key'];
        }

        $credential = $this->merchantApiCredentialRepository->updateOrCreate(
            ['merchant_id' => $merchantId],
            [
                'merchant_id' => $merchantId,
                'sign_type' => $signType,
                'status' => $status,
                'api_key' => $credentialValue,
                'merchant_public_key' => $merchantPublicKey,
            ]
        );

        return [
            'merchant' => $merchant,
            'credential' => $credential,
            'credential_value' => $credentialValue,
            'merchant_private_key' => $merchantPrivateKey,
            'generated' => [
                'rotate_v1' => $rotateV1,
                'rotate_v2' => $rotateV2,
                'api_key' => $rotateV1 ? $credentialValue : '',
                'merchant_private_key' => $merchantPrivateKey,
                'merchant_public_key' => $merchantPublicKey,
            ],
        ];
    }

    /**
     * 查询商户 API 凭证详情。
     *
     * @param int $id 商户 API 凭证ID
     * @return MerchantApiCredential|null 凭证模型
     */
    public function findById(int $id): ?MerchantApiCredential
    {
        return $this->merchantApiCredentialQueryService->findById($id);
    }

    /**
     * 查询商户对应的接口凭证详情。
     *
     * @param int $merchantId 商户ID
     * @return MerchantApiCredential|null 凭证模型
     */
    public function findByMerchantId(int $merchantId): ?MerchantApiCredential
    {
        return $this->merchantApiCredentialQueryService->findByMerchantId($merchantId);
    }

    /**
     * 新增或更新商户 API 凭证。
     *
     * 如果商户已有凭证，则转为更新；否则创建新记录。
     *
     * @param array $data 凭证数据
     * @return MerchantApiCredential 凭证模型
     * @throws ResourceNotFoundException
     */
    public function create(array $data): MerchantApiCredential
    {
        $merchantId = (int) ($data['merchant_id'] ?? 0);
        $merchant = $this->merchantRepository->find($merchantId);
        if (!$merchant) {
            throw new ResourceNotFoundException('商户不存在', ['merchant_id' => $merchantId]);
        }

        $current = $this->merchantApiCredentialRepository->findByMerchantId($merchantId);
        if ($current) {
            // 同一商户只保留一份凭证，已有记录时优先走更新，避免重复创建。
            $updated = $this->update((int) $current->id, $data);
            if ($updated) {
                return $updated;
            }
        }

        $apiKey = trim((string) ($data['api_key'] ?? ''));
        $merchantPublicKey = trim((string) ($data['merchant_public_key'] ?? ''));
        if ($apiKey === '' && $merchantPublicKey === '') {
            throw new ValidationException('请至少填写 V1 API Key 或 V2 商户 RSA 公钥');
        }

        return $this->merchantApiCredentialRepository->create($this->normalizePayload($data, false));
    }

    /**
     * 修改商户 API 凭证。
     *
     * @param int $id 商户 API 凭证ID
     * @param array $data 凭证数据
     * @return MerchantApiCredential|null 凭证模型
     */
    public function update(int $id, array $data): ?MerchantApiCredential
    {
        $current = $this->merchantApiCredentialRepository->find($id);
        if (!$current) {
            return null;
        }

        $payload = $this->normalizePayload($data, true, $current);
        if (!$this->merchantApiCredentialRepository->updateById($id, $payload)) {
            return null;
        }

        return $this->findById($id);
    }

    /**
     * 删除商户 API 凭证。
     *
     * @param int $id 商户 API 凭证ID
     * @return bool 是否删除成功
     */
    public function delete(int $id): bool
    {
        return $this->merchantApiCredentialRepository->deleteById($id);
    }

    /**
     * 使用商户 ID 和接口凭证直接进行身份校验。
     *
     * 该方法用于兼容 ePay 风格的查询接口，不涉及签名串验签。
     *
     * @param int $merchantId 商户ID
     * @param string $key 接口凭证
     * @return array{merchant: Merchant, credential: MerchantApiCredential} 商户和凭证数据
     * @throws ValidationException
     * @throws ResourceNotFoundException
     */
    public function authenticateByKey(int $merchantId, string $key): array
    {
        if ($merchantId <= 0 || $key === '') {
            throw new ValidationException('pid/key 参数缺失');
        }

        /** @var Merchant|null $merchant */
        $merchant = $this->merchantRepository->find($merchantId);
        if (!$merchant || (int) $merchant->status !== CommonConstant::STATUS_ENABLED) {
            throw new ResourceNotFoundException('商户不存在', ['merchant_id' => $merchantId]);
        }

        /** @var MerchantApiCredential|null $credential */
        $credential = $this->merchantApiCredentialRepository->findByMerchantId($merchantId);
        if (!$credential || (int) $credential->status !== AuthConstant::CREDENTIAL_STATUS_ENABLED) {
            throw new ValidationException('商户 API 凭证未开通');
        }

        // 同样使用常量时间比较，避免明文 key 对比暴露额外信息。
        if (!hash_equals((string) $credential->api_key, $key)) {
            throw new ValidationException('商户 API 凭证错误');
        }

        $credential->last_used_at = $this->now();
        $credential->save();

        return [
            'merchant' => $merchant,
            'credential' => $credential,
        ];
    }

    /**
     * 整理写入字段。
     *
     * @param array $data 凭证数据
     * @param bool $isUpdate 是否更新
     * @param MerchantApiCredential|null $current 当前凭证
     * 更新场景下，空字符串视为“不修改”，避免手动配置时误清空已有密钥。
     * `sign_type` 在当前阶段只作为展示/默认接入说明，不再作为 V1/V2 互斥开关。
     *
     * @return array{merchant_id: int, sign_type: int, status: int, api_key?: string} 标准化后的写入数据
     */
    private function normalizePayload(array $data, bool $isUpdate, ?MerchantApiCredential $current = null): array
    {
        // 更新场景下以现有记录的 merchant_id 为准，避免把凭证误挂到别的商户。
        $merchantId = (int) ($current?->merchant_id ?? ($data['merchant_id'] ?? 0));
        $currentSignType = (int) ($current?->sign_type ?? AuthConstant::API_SIGN_TYPE_MD5);
        $currentStatus = (int) ($current?->status ?? AuthConstant::CREDENTIAL_STATUS_ENABLED);
        $payload = [
            'merchant_id' => $merchantId,
            'sign_type' => (int) ($data['sign_type'] ?? $currentSignType),
            'status' => (int) ($data['status'] ?? $currentStatus),
        ];

        $apiKey = trim((string) ($data['api_key'] ?? ''));
        if ($apiKey !== '') {
            $payload['api_key'] = $apiKey;
        }

        if (array_key_exists('merchant_public_key', $data)) {
            $merchantPublicKey = trim((string) ($data['merchant_public_key'] ?? ''));
            if ($merchantPublicKey !== '' || !$isUpdate) {
                $payload['merchant_public_key'] = $merchantPublicKey;
            }
        }

        return $payload;
    }

    /**
     * 生成新的接口凭证值。
     *
     * @return string 接口凭证
     */
    private function generateCredentialValue(): string
    {
        return bin2hex(random_bytes(16));
    }

}






