<?php

namespace app\service\merchant\security;

use app\common\base\BaseService;
use app\common\constant\AuthConstant;
use app\common\constant\CommonConstant;
use app\exception\ResourceNotFoundException;
use app\exception\ValidationException;
use app\model\merchant\Merchant;
use app\model\merchant\MerchantApiCredential;
use app\repository\merchant\credential\MerchantApiCredentialRepository;
use app\repository\merchant\base\MerchantRepository;

/**
 * 商户对外接口凭证与签名校验服务。
 *
 * 负责外部支付接口的签名验证、接口凭证发放和最近使用时间更新。
 */
class MerchantApiCredentialService extends BaseService
{
    /**
     * 构造函数，注入对应依赖。
     */
    public function __construct(
        protected MerchantRepository $merchantRepository,
        protected MerchantApiCredentialRepository $merchantApiCredentialRepository,
        protected MerchantApiCredentialQueryService $merchantApiCredentialQueryService
    ) {
    }

    /**
     * 分页查询商户接口凭证。
     */
    public function paginate(array $filters = [], int $page = 1, int $pageSize = 10)
    {
        return $this->merchantApiCredentialQueryService->paginate($filters, $page, $pageSize);
    }

    /**
     * 校验外部支付接口的 MD5 签名。
     *
     * @return array{merchant:\app\model\merchant\Merchant,credential:\app\model\merchant\MerchantApiCredential}
     */
    public function verifyMd5Sign(array $payload): array
    {
        $merchantId = (int) ($payload['pid'] ?? $payload['merchant_id'] ?? 0);
        $sign = trim((string) ($payload['sign'] ?? ''));
        $signType = strtoupper((string) ($payload['sign_type'] ?? 'MD5'));
        $providedKey = trim((string) ($payload['key'] ?? ''));

        if ($merchantId <= 0 || $sign === '') {
            throw new ValidationException('pid/sign 参数缺失');
        }

        if ($signType !== 'MD5') {
            throw new ValidationException('仅支持 MD5 签名');
        }

        /** @var Merchant|null $merchant */
        $merchant = $this->merchantRepository->find($merchantId);
        if (!$merchant || (int) $merchant->status !== CommonConstant::STATUS_ENABLED) {
            throw new ResourceNotFoundException('商户不存在', ['merchant_id' => $merchantId]);
        }

        /** @var MerchantApiCredential|null $credential */
        $credential = $this->merchantApiCredentialRepository->findByMerchantId($merchantId);
        if (!$credential || (int) $credential->status !== AuthConstant::LOGIN_STATUS_ENABLED) {
            throw new ValidationException('商户接口凭证未开通');
        }

        if ($providedKey !== '' && !hash_equals((string) $credential->api_key, $providedKey)) {
            throw new ValidationException('商户接口凭证错误');
        }

        $params = $payload;
        unset($params['sign'], $params['sign_type'], $params['key']);
        foreach ($params as $paramKey => $paramValue) {
            if ($paramValue === '' || $paramValue === null) {
                unset($params[$paramKey]);
            }
        }
        ksort($params);

        $key = (string) $credential->api_key;
        $query = [];
        foreach ($params as $paramKey => $paramValue) {
            $query[] = $paramKey . '=' . $paramValue;
        }
        $base = implode('&', $query) . $key;
        $expected = md5($base);

        if (!hash_equals(strtolower($expected), strtolower($sign))) {
            throw new ValidationException('签名验证失败');
        }

        $credential->last_used_at = $this->now();
        $credential->save();

        return [
            'merchant' => $merchant,
            'credential' => $credential,
        ];
    }

    /**
     * 为商户生成并保存一份新的接口凭证。
     *
     * 返回值是明文接口凭证值，只会在调用时完整出现一次，后续仅保存脱敏展示。
     */
    public function issueCredential(int $merchantId): string
    {
        $merchant = $this->merchantRepository->find($merchantId);
        if (!$merchant) {
            throw new ResourceNotFoundException('商户不存在', ['merchant_id' => $merchantId]);
        }

        $credentialValue = $this->generateCredentialValue();
        $this->merchantApiCredentialRepository->updateOrCreate(
            ['merchant_id' => $merchantId],
            [
                'merchant_id' => $merchantId,
                'sign_type' => AuthConstant::API_SIGN_TYPE_MD5,
                'api_key' => $credentialValue,
                'status' => AuthConstant::LOGIN_STATUS_ENABLED,
            ]
        );

        return $credentialValue;
    }

    /**
     * 查询商户接口凭证详情。
     */
    public function findById(int $id): ?MerchantApiCredential
    {
        return $this->merchantApiCredentialQueryService->findById($id);
    }

    /**
     * 查询商户对应的接口凭证详情。
     */
    public function findByMerchantId(int $merchantId): ?MerchantApiCredential
    {
        return $this->merchantApiCredentialQueryService->findByMerchantId($merchantId);
    }

    /**
     * 新增或更新商户接口凭证。
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
            $updated = $this->update((int) $current->id, $data);
            if ($updated) {
                return $updated;
            }
        }

        return $this->merchantApiCredentialRepository->create($this->normalizePayload($data, false));
    }

    /**
     * 修改商户接口凭证。
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
     * 删除商户接口凭证。
     */
    public function delete(int $id): bool
    {
        return $this->merchantApiCredentialRepository->deleteById($id);
    }

    /**
     * 使用商户 ID 和接口凭证直接进行身份校验。
     *
     * 该方法用于兼容 epay 风格的查询接口，不涉及签名串验签。
     *
     * @return array{merchant:\app\model\merchant\Merchant,credential:\app\model\merchant\MerchantApiCredential}
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
        if (!$credential || (int) $credential->status !== AuthConstant::LOGIN_STATUS_ENABLED) {
            throw new ValidationException('商户接口凭证未开通');
        }

        if (!hash_equals((string) $credential->api_key, $key)) {
            throw new ValidationException('商户接口凭证错误');
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
     */
    private function normalizePayload(array $data, bool $isUpdate, ?MerchantApiCredential $current = null): array
    {
        $merchantId = (int) ($current?->merchant_id ?? ($data['merchant_id'] ?? 0));
        $payload = [
            'merchant_id' => $merchantId,
            'sign_type' => (int) ($data['sign_type'] ?? AuthConstant::API_SIGN_TYPE_MD5),
            'status' => (int) ($data['status'] ?? AuthConstant::LOGIN_STATUS_ENABLED),
        ];

        $apiKey = trim((string) ($data['api_key'] ?? ''));
        if ($apiKey !== '') {
            $payload['api_key'] = $apiKey;
        } elseif (!$isUpdate) {
            $payload['api_key'] = $this->generateCredentialValue();
        }

        return $payload;
    }

    /**
     * 生成新的接口凭证值。
     */
    private function generateCredentialValue(): string
    {
        return bin2hex(random_bytes(16));
    }

}

