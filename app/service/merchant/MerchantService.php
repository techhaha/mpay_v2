<?php

namespace app\service\merchant;

use app\common\base\BaseService;
use app\model\merchant\Merchant;
use app\model\merchant\MerchantGroup;
use app\model\merchant\MerchantPolicy;

/**
 * 商户服务。
 *
 * @property MerchantQueryService $queryService 查询服务
 * @property MerchantCommandService $commandService 命令服务
 * @property MerchantOverviewQueryService $overviewQueryService 总览查询服务
 */
class MerchantService extends BaseService
{
    /**
     * 构造方法。
     *
     * @param MerchantQueryService $queryService 查询服务
     * @param MerchantCommandService $commandService 命令服务
     * @param MerchantOverviewQueryService $overviewQueryService 总览查询服务
     * @return void
     */
    public function __construct(
        protected MerchantQueryService $queryService,
        protected MerchantCommandService $commandService,
        protected MerchantOverviewQueryService $overviewQueryService
    ) {
    }

    /**
     * 分页查询商户列表。
     *
     * @param array $filters 筛选条件
     * @param int $page 页码
     * @param int $pageSize 每页条数
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator 分页结果
     */
    public function paginate(array $filters = [], int $page = 1, int $pageSize = 10)
    {
        return $this->queryService->paginate($filters, $page, $pageSize);
    }

    /**
     * 分页查询商户列表并附带分组选项。
     *
     * @param array $filters 筛选条件
     * @param int $page 页码
     * @param int $pageSize 每页条数
     * @return array 页面数据
     */
    public function paginateWithGroupOptions(array $filters = [], int $page = 1, int $pageSize = 10): array
    {
        return $this->queryService->paginateWithGroupOptions($filters, $page, $pageSize);
    }

    /**
     * 获取启用商户下拉选项。
     *
     * @return array 商户选项列表
     */
    public function enabledOptions(): array
    {
        return $this->queryService->enabledOptions();
    }

    /**
     * 搜索启用商户下拉选项。
     *
     * @param array $filters 筛选条件
     * @param int $page 页码
     * @param int $pageSize 每页条数
     * @return array 搜索结果
     */
    public function searchOptions(array $filters = [], int $page = 1, int $pageSize = 20): array
    {
        return $this->queryService->searchOptions($filters, $page, $pageSize);
    }

    /**
     * 按 ID 查询商户详情。
     *
     * @param int $merchantId 商户ID
     * @return object|null 商户详情
     */
    public function findById(int $merchantId): ?object
    {
        return $this->queryService->findById($merchantId);
    }

    /**
     * 创建商户。
     *
     * @param array $data 商户数据
     * @return Merchant 商户模型
     */
    public function create(array $data): Merchant
    {
        return $this->commandService->create($data);
    }

    /**
     * 创建商户并返回详情。
     *
     * @param array $data 商户数据
     * @return object|null 商户详情
     */
    public function createWithDetail(array $data): ?object
    {
        $merchant = $this->create($data);
        $detail = $this->findById((int) $merchant->id);
        if ($detail && isset($merchant->plain_password)) {
            $detail->plain_password = (string) $merchant->plain_password;
        }

        return $detail ?? $merchant;
    }

    /**
     * 更新商户。
     *
     * @param int $merchantId 商户ID
     * @param array $data 商户数据
     * @return Merchant|null 商户模型
     */
    public function update(int $merchantId, array $data): ?Merchant
    {
        return $this->commandService->update($merchantId, $data);
    }

    /**
     * 更新商户并返回详情。
     *
     * @param int $merchantId 商户ID
     * @param array $data 商户数据
     * @return object|null 商户详情
     */
    public function updateWithDetail(int $merchantId, array $data): ?object
    {
        $merchant = $this->update($merchantId, $data);
        if (!$merchant) {
            return null;
        }

        return $this->findById($merchantId);
    }

    /**
     * 删除商户。
     *
     * @param int $merchantId 商户ID
     * @return bool 是否删除成功
     */
    public function delete(int $merchantId): bool
    {
        return $this->commandService->delete($merchantId);
    }

    /**
     * 重置商户密码。
     *
     * @param int $merchantId 商户ID
     * @param string $password 新密码
     * @return Merchant 商户模型
     */
    public function resetPassword(int $merchantId, string $password): Merchant
    {
        return $this->commandService->resetPassword($merchantId, $password);
    }

    /**
     * 校验商户密码。
     *
     * @param Merchant $merchant 商户
     * @param string $password 密码
     * @return bool 是否匹配
     */
    public function verifyPassword(Merchant $merchant, string $password): bool
    {
        return $this->commandService->verifyPassword($merchant, $password);
    }

    /**
     * 更新商户登录信息。
     *
     * @param int $merchantId 商户ID
     * @param string $ip 登录 IP
     * @return void
     */
    public function touchLoginMeta(int $merchantId, string $ip = ''): void
    {
        $this->commandService->touchLoginMeta($merchantId, $ip);
    }

    /**
     * 生成或重置商户 API 凭证。
     *
     * @param int $merchantId 商户ID
     * @return array 凭证数据
     */
    public function issueCredential(int $merchantId): array
    {
        return $this->commandService->issueCredential($merchantId);
    }

    /**
     * 获取商户总览。
     *
     * @param int $merchantId 商户ID
     * @return array 总览数据
     */
    public function overview(int $merchantId): array
    {
        return $this->overviewQueryService->overview($merchantId);
    }

    /**
     * 根据商户号查询已启用商户。
     *
     * @param string $merchantNo 商户号
     * @return Merchant 商户模型
     */
    public function findEnabledMerchantByNo(string $merchantNo): Merchant
    {
        return $this->commandService->findEnabledMerchantByNo($merchantNo);
    }

    /**
     * 校验商户是否启用。
     *
     * @param int $merchantId 商户ID
     * @return Merchant 商户模型
     */
    public function ensureMerchantEnabled(int $merchantId): Merchant
    {
        return $this->commandService->ensureMerchantEnabled($merchantId);
    }

    /**
     * 校验商户分组是否启用。
     *
     * @param int $groupId 分组ID
     * @return MerchantGroup 商户分组模型
     */
    public function ensureMerchantGroupEnabled(int $groupId): MerchantGroup
    {
        return $this->commandService->ensureMerchantGroupEnabled($groupId);
    }

    /**
     * 查询商户策略。
     *
     * @param int $merchantId 商户ID
     * @return MerchantPolicy|null 商户策略模型
     */
    public function findPolicy(int $merchantId): ?MerchantPolicy
    {
        return $this->queryService->findPolicy($merchantId);
    }
}


