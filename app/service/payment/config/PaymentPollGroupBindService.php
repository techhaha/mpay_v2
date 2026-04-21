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
 *
 * 负责把商户分组和支付方式绑定到指定轮询组，并校验轮询组与支付方式的匹配关系。
 *
 * @property PaymentPollGroupBindRepository $paymentPollGroupBindRepository 支付轮询分组绑定仓库
 * @property MerchantGroupRepository $merchantGroupRepository 商户分组仓库
 * @property PaymentPollGroupRepository $paymentPollGroupRepository 支付轮询分组仓库
 */
class PaymentPollGroupBindService extends BaseService
{
    /**
     * 构造方法。
     *
     * @param PaymentPollGroupBindRepository $paymentPollGroupBindRepository 支付轮询分组绑定仓库
     * @param MerchantGroupRepository $merchantGroupRepository 商户分组仓库
     * @param PaymentPollGroupRepository $paymentPollGroupRepository 支付轮询分组仓库
     * @return void
     */
    public function __construct(
        protected PaymentPollGroupBindRepository $paymentPollGroupBindRepository,
        protected MerchantGroupRepository $merchantGroupRepository,
        protected PaymentPollGroupRepository $paymentPollGroupRepository
    ) {
    }

    /**
     * 分页查询商户分组路由绑定。
     *
     * @param array $filters 筛选条件
     * @param int $page 页码
     * @param int $pageSize 每页条数
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator 分页结果
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

    /**
     * 按 ID 查询路由绑定。
     *
     * @param int $id 绑定ID
     * @return PaymentPollGroupBind|null 绑定模型
     */
    public function findById(int $id): ?PaymentPollGroupBind
    {
        return $this->paymentPollGroupBindRepository->find($id);
    }

    /**
     * 创建路由绑定。
     *
     * @param array $data 写入数据
     * @return PaymentPollGroupBind 新增后的绑定模型
     * @throws PaymentException
     */
    public function create(array $data): PaymentPollGroupBind
    {
        $this->assertBindingUnique((int) $data['merchant_group_id'], (int) $data['pay_type_id']);
        $this->assertPollGroupMatchesPayType($data);

        return $this->paymentPollGroupBindRepository->create($this->normalizePayload($data));
    }

    /**
     * 更新路由绑定。
     *
     * @param int $id 绑定ID
     * @param array $data 写入数据
     * @return PaymentPollGroupBind|null 更新后的绑定模型
     * @throws PaymentException
     */
    public function update(int $id, array $data): ?PaymentPollGroupBind
    {
        $current = $this->paymentPollGroupBindRepository->find($id);
        if (!$current) {
            return null;
        }

        // 更新时要以现有记录为底，把未传的分组和支付方式补齐后再做唯一性校验。
        $merchantGroupId = (int) ($data['merchant_group_id'] ?? $current->merchant_group_id);
        $payTypeId = (int) ($data['pay_type_id'] ?? $current->pay_type_id);
        $this->assertBindingUnique($merchantGroupId, $payTypeId, $id);
        $this->assertPollGroupMatchesPayType(array_merge($current->toArray(), $data));

        if (!$this->paymentPollGroupBindRepository->updateById($id, $this->normalizePayload($data))) {
            return null;
        }

        return $this->paymentPollGroupBindRepository->find($id);
    }

    /**
     * 删除路由绑定。
     *
     * @param int $id 绑定ID
     * @return bool 是否删除成功
     */
    public function delete(int $id): bool
    {
        return $this->paymentPollGroupBindRepository->deleteById($id);
    }

    /**
     * 标准化路由绑定写入数据。
     *
     * @param array $data 写入数据
     * @return array<string, mixed> 标准化后的数据
     */
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

    /**
     * 校验商户分组与支付方式的绑定唯一性。
     *
     * @param int $merchantGroupId 商户分组ID
     * @param int $payTypeId 支付方式ID
     * @param int $ignoreId 排除的绑定ID
     * @return void
     * @throws PaymentException
     */
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

    /**
     * 校验轮询组与支付方式是否一致。
     *
     * @param array $data 写入数据
     * @return void
     * @throws PaymentException
     */
    private function assertPollGroupMatchesPayType(array $data): void
    {
        $pollGroupId = (int) ($data['poll_group_id'] ?? 0);
        $payTypeId = (int) ($data['pay_type_id'] ?? 0);

        // 轮询组和支付方式必须保持一致；轮询组缺失时交给上层必填校验处理。
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


