<?php

namespace app\service\payment\config;

use app\common\base\BaseService;
use app\exception\PaymentException;
use app\model\payment\PaymentPollGroup;
use app\repository\payment\config\PaymentPollGroupRepository;

/**
 * 支付轮询组命令服务。
 */
class PaymentPollGroupCommandService extends BaseService
{
    public function __construct(
        protected PaymentPollGroupRepository $paymentPollGroupRepository
    ) {
    }

    public function create(array $data): PaymentPollGroup
    {
        $this->assertGroupNameUnique((string) ($data['group_name'] ?? ''));
        return $this->paymentPollGroupRepository->create($data);
    }

    public function update(int $id, array $data): ?PaymentPollGroup
    {
        $this->assertGroupNameUnique((string) ($data['group_name'] ?? ''), $id);
        if (!$this->paymentPollGroupRepository->updateById($id, $data)) {
            return null;
        }

        return $this->paymentPollGroupRepository->find($id);
    }

    public function delete(int $id): bool
    {
        return $this->paymentPollGroupRepository->deleteById($id);
    }

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
