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
 * 负责商户外部接口签名校验、接口凭证发放和最近使用时间更新。
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
     * 校验外部支付接口的 MD5 签名。
     *
     * 会先校验商户和接口凭证是否存在，再按签名规则计算并比对请求签名。
     *
     * @param array $payload 请求载荷
     * @return array{merchant: Merchant, credential: MerchantApiCredential} 校验通过后的商户和凭证数据
     * @throws ValidationException
     * @throws ResourceNotFoundException
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
            throw new ValidationException('商户 API 凭证未开通');
        }

        if ($providedKey !== '' && !hash_equals((string) $credential->api_key, $providedKey)) {
            throw new ValidationException('商户 API 凭证错误');
        }

        // 签名字段本身不参与原文拼接，只保留业务参数。
        $params = $payload;
        unset($params['sign'], $params['sign_type'], $params['key']);
        // 过滤空值并按键名排序，保证不同参数顺序下得到同一签名串。
        foreach ($params as $paramKey => $paramValue) {
            if ($paramValue === '' || $paramValue === null) {
                unset($params[$paramKey]);
            }
        }
        ksort($params);

        $key = (string) $credential->api_key;
        $query = [];
        // 旧版 ePay 采用 `a=1&b=2` 再拼接 key 的方式验签，这里保持兼容。
        foreach ($params as $paramKey => $paramValue) {
            $query[] = $paramKey . '=' . $paramValue;
        }
        $base = implode('&', $query) . $key;
        $expected = md5($base);

        // 使用常量时间比较，避免签名对比被时序差异放大。
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
     *
     * @param int $merchantId 商户ID
     * @return string 新接口凭证
     * @throws ResourceNotFoundException
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
        if (!$credential || (int) $credential->status !== AuthConstant::LOGIN_STATUS_ENABLED) {
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
     * @return array{merchant_id: int, sign_type: int, status: int, api_key?: string} 标准化后的写入数据
     */
    private function normalizePayload(array $data, bool $isUpdate, ?MerchantApiCredential $current = null): array
    {
        // 更新场景下以现有记录的 merchant_id 为准，避免把凭证误挂到别的商户。
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
            // 新增凭证时如果前端没有传入明文 key，就自动补一份随机值。
            $payload['api_key'] = $this->generateCredentialValue();
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






