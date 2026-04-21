<?php

namespace app\service\payment\config;

use app\common\base\BaseService;
use app\exception\PaymentException;
use app\model\payment\PaymentPollGroup;
use app\repository\payment\config\PaymentPollGroupRepository;

/**
 * 支付轮询组命令服务。
 *
 * @property PaymentPollGroupRepository $paymentPollGroupRepository 支付轮询分组仓库
 */
class PaymentPollGroupCommandService extends BaseService
{
    /**
     * 构造方法。
     *
     * @param PaymentPollGroupRepository $paymentPollGroupRepository 支付轮询分组仓库
     * @return void
     */
    public function __construct(
        protected PaymentPollGroupRepository $paymentPollGroupRepository
    ) {
    }

    /**
     * 创建支付轮询组。
     *
     * @param array $data 写入数据
     * @return PaymentPollGroup 新增后的轮询组模型
     * @throws PaymentException
     */
    public function create(array $data): PaymentPollGroup
    {
        // 新增前先确保轮询组名称不冲突，避免后台同时出现两个同名配置。
        $this->assertGroupNameUnique((string) ($data['group_name'] ?? ''));
        return $this->paymentPollGroupRepository->create($data);
    }

    /**
     * 更新支付轮询组。
     *
     * @param int $id 轮询组ID
     * @param array $data 写入数据
     * @return PaymentPollGroup|null 更新后的轮询组模型
     * @throws PaymentException
     */
    public function update(int $id, array $data): ?PaymentPollGroup
    {
        // 更新时同样要排除自身后再做唯一性判断，防止修改回原名时误报冲突。
        $this->assertGroupNameUnique((string) ($data['group_name'] ?? ''), $id);
        if (!$this->paymentPollGroupRepository->updateById($id, $data)) {
            return null;
        }

        return $this->paymentPollGroupRepository->find($id);
    }

    /**
     * 删除支付轮询组。
     *
     * @param int $id 轮询组ID
     * @return bool 是否删除成功
     */
    public function delete(int $id): bool
    {
        return $this->paymentPollGroupRepository->deleteById($id);
    }

    /**
     * 校验轮询组名称唯一。
     *
     * @param string $groupName 轮询组名称
     * @param int $ignoreId 排除的轮询组ID
     * @return void
     * @throws PaymentException
     */
    private function assertGroupNameUnique(string $groupName, int $ignoreId = 0): void
    {
        $groupName = trim($groupName);
        if ($groupName === '') {
            return;
        }

        if ($this->paymentPollGroupRepository->existsByGroupName($groupName, $ignoreId)) {
            throw new PaymentException('轮询组名称已存在', 40234, [
                'group_name' => $groupName,
                'ignore_id' => $ignoreId,
            ]);
        }
    }
}



