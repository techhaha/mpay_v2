<?php

namespace app\service\payment\config;

use app\common\base\BaseService;
use app\exception\PaymentException;
use app\model\payment\PaymentPollGroupBind;
use app\repository\merchant\base\MerchantGroupRepository;
use app\repository\payment\config\PaymentPollGroupBindRepository;
use app\repository\payment\config\PaymentPollGroupRepository;

/**
 * 商户分组路由绑定服务。
 */
class PaymentPollGroupBindService extends BaseService
{
    public function __construct(
        protected PaymentPollGroupBindRepository $paymentPollGroupBindRepository,
        protected MerchantGroupRepository $merchantGroupRepository,
        protected PaymentPollGroupRepository $paymentPollGroupRepository
    ) {
    }

    /**
     * 分页查询商户分组路由绑定。
     */
    public function paginate(array $filters = [], int $page = 1, int $pageSize = 10)
    {
        $query = $this->paymentPollGroupBindRepository->query()
            ->from('ma_payment_poll_group_bind as b')
            ->leftJoin('ma_merchant_group as mg', 'mg.id', '=', 'b.merchant_group_id')
            ->leftJoin('ma_payment_type as t', 't.id', '=', 'b.pay_type_id')
            ->leftJoin('ma_payment_poll_group as pg', 'pg.id', '=', 'b.poll_group_id')
            ->select([
                'b.id',
                'b.merchant_group_id',
                'b.pay_type_id',
                'b.poll_group_id',
                'b.status',
                'b.remark',
                'b.created_at',
                'b.updated_at',
                'mg.group_name as merchant_group_name',
                't.name as pay_type_name',
                'pg.group_name as poll_group_name',
                'pg.route_mode',
            ]);

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->where(function ($builder) use ($keyword) {
                $builder->where('mg.group_name', 'like', '%' . $keyword . '%')
                    ->orWhere('t.name', 'like', '%' . $keyword . '%')
                    ->orWhere('pg.group_name', 'like', '%' . $keyword . '%');
            });
        }

        if (($merchantGroupId = (int) ($filters['merchant_group_id'] ?? 0)) > 0) {
            $query->where('b.merchant_group_id', $merchantGroupId);
        }

        if (($payTypeId = (int) ($filters['pay_type_id'] ?? 0)) > 0) {
            $query->where('b.pay_type_id', $payTypeId);
        }

        if (($pollGroupId = (int) ($filters['poll_group_id'] ?? 0)) > 0) {
            $query->where('b.poll_group_id', $pollGroupId);
        }

        if (array_key_exists('status', $filters) && $filters['status'] !== '') {
            $query->where('b.status', (int) $filters['status']);
        }

        return $query
            ->orderByDesc('b.id')
            ->paginate(max(1, $pageSize), ['*'], 'page', max(1, $page));
    }

    public function findById(int $id): ?PaymentPollGroupBind
    {
        return $this->paymentPollGroupBindRepository->find($id);
    }

    public function create(array $data): PaymentPollGroupBind
    {
        $this->assertBindingUnique((int) $data['merchant_group_id'], (int) $data['pay_type_id']);
        $this->assertPollGroupMatchesPayType($data);

        return $this->paymentPollGroupBindRepository->create($this->normalizePayload($data));
    }

    public function update(int $id, array $data): ?PaymentPollGroupBind
    {
        $current = $this->paymentPollGroupBindRepository->find($id);
        if (!$current) {
            return null;
        }

        $merchantGroupId = (int) ($data['merchant_group_id'] ?? $current->merchant_group_id);
        $payTypeId = (int) ($data['pay_type_id'] ?? $current->pay_type_id);
        $this->assertBindingUnique($merchantGroupId, $payTypeId, $id);
        $this->assertPollGroupMatchesPayType(array_merge($current->toArray(), $data));

        if (!$this->paymentPollGroupBindRepository->updateById($id, $this->normalizePayload($data))) {
            return null;
        }

        return $this->paymentPollGroupBindRepository->find($id);
    }

    public function delete(int $id): bool
    {
        return $this->paymentPollGroupBindRepository->deleteById($id);
    }

    private function normalizePayload(array $data): array
    {
        return [
            'merchant_group_id' => (int) $data['merchant_group_id'],
            'pay_type_id' => (int) $data['pay_type_id'],
            'poll_group_id' => (int) $data['poll_group_id'],
            'status' => (int) ($data['status'] ?? 1),
            'remark' => trim((string) ($data['remark'] ?? '')),
        ];
    }

    private function assertBindingUnique(int $merchantGroupId, int $payTypeId, int $ignoreId = 0): void
    {
        $query = $this->paymentPollGroupBindRepository->query()
            ->where('merchant_group_id', $merchantGroupId)
            ->where('pay_type_id', $payTypeId);

        if ($ignoreId > 0) {
            $query->where('id', '<>', $ignoreId);
        }

        if ($query->exists()) {
            throw new PaymentException('当前商户分组与支付方式已绑定轮询组', 40232, [
                'merchant_group_id' => $merchantGroupId,
                'pay_type_id' => $payTypeId,
            ]);
        }
    }

    private function assertPollGroupMatchesPayType(array $data): void
    {
        $pollGroupId = (int) ($data['poll_group_id'] ?? 0);
        $payTypeId = (int) ($data['pay_type_id'] ?? 0);

        $pollGroup = $this->paymentPollGroupRepository->find($pollGroupId);
        if (!$pollGroup) {
            return;
        }

        if ((int) $pollGroup->pay_type_id !== $payTypeId) {
            throw new PaymentException('轮询组与支付方式不一致', 40233, [
                'poll_group_id' => $pollGroupId,
                'pay_type_id' => $payTypeId,
            ]);
        }
    }
}
