<?php

namespace app\service\payment\config;

use app\common\base\BaseService;
use app\common\constant\RouteConstant;
use app\exception\PaymentException;
use app\model\payment\PaymentPollGroupChannel;
use app\repository\payment\config\PaymentChannelRepository;
use app\repository\payment\config\PaymentPollGroupChannelRepository;
use app\repository\payment\config\PaymentPollGroupRepository;

/**
 * 轮询组通道编排服务。
 *
 * 负责维护轮询组内通道的顺序、权重、默认通道以及支付方式一致性。
 *
 * @property PaymentPollGroupChannelRepository $paymentPollGroupChannelRepository 支付轮询分组渠道仓库
 * @property PaymentPollGroupRepository $paymentPollGroupRepository 支付轮询分组仓库
 * @property PaymentChannelRepository $paymentChannelRepository 支付渠道仓库
 */
class PaymentPollGroupChannelService extends BaseService
{
    /**
     * 构造方法。
     *
     * @param PaymentPollGroupChannelRepository $paymentPollGroupChannelRepository 支付轮询分组渠道仓库
     * @param PaymentPollGroupRepository $paymentPollGroupRepository 支付轮询分组仓库
     * @param PaymentChannelRepository $paymentChannelRepository 支付渠道仓库
     * @return void
     */
    public function __construct(
        protected PaymentPollGroupChannelRepository $paymentPollGroupChannelRepository,
        protected PaymentPollGroupRepository $paymentPollGroupRepository,
        protected PaymentChannelRepository $paymentChannelRepository
    ) {
    }

    /**
     * 分页查询轮询组通道编排。
     *
     * @param array $filters 筛选条件
     * @param int $page 页码
     * @param int $pageSize 每页条数
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator 分页结果
     */
    public function paginate(array $filters = [], int $page = 1, int $pageSize = 10)
    {
        $query = $this->paymentPollGroupChannelRepository->query()
            ->from('ma_payment_poll_group_channel as pgc')
            ->leftJoin('ma_payment_poll_group as pg', 'pg.id', '=', 'pgc.poll_group_id')
            ->leftJoin('ma_payment_channel as c', 'c.id', '=', 'pgc.channel_id')
            ->leftJoin('ma_payment_type as t', 't.id', '=', 'pg.pay_type_id')
            ->select([
                'pgc.id',
                'pgc.poll_group_id',
                'pgc.channel_id',
                'pgc.sort_no',
                'pgc.weight',
                'pgc.is_default',
                'pgc.status',
                'pgc.remark',
                'pgc.created_at',
                'pgc.updated_at',
                'pg.group_name as poll_group_name',
                'pg.pay_type_id',
                'c.name as channel_name',
                'c.merchant_id',
                'c.channel_mode',
                'c.plugin_code',
                't.name as pay_type_name',
            ]);

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->where(function ($builder) use ($keyword) {
                $builder->where('pg.group_name', 'like', '%' . $keyword . '%')
                    ->orWhere('c.name', 'like', '%' . $keyword . '%')
                    ->orWhere('c.plugin_code', 'like', '%' . $keyword . '%');
            });
        }

        if (($pollGroupId = (int) ($filters['poll_group_id'] ?? 0)) > 0) {
            $query->where('pgc.poll_group_id', $pollGroupId);
        }

        if (($channelId = (int) ($filters['channel_id'] ?? 0)) > 0) {
            $query->where('pgc.channel_id', $channelId);
        }

        if (array_key_exists('status', $filters) && $filters['status'] !== '') {
            $query->where('pgc.status', (int) $filters['status']);
        }

        return $query
            ->orderBy('pgc.poll_group_id')
            ->orderBy('pgc.sort_no')
            ->orderByDesc('pgc.id')
            ->paginate(max(1, $pageSize), ['*'], 'page', max(1, $page));
    }

    /**
     * 按 ID 查询轮询组通道编排。
     *
     * @param int $id 编排ID
     * @return PaymentPollGroupChannel|null 编排模型
     */
    public function findById(int $id): ?PaymentPollGroupChannel
    {
        return $this->paymentPollGroupChannelRepository->find($id);
    }

    /**
     * 创建轮询组通道编排。
     *
     * @param array $data 写入数据
     * @return PaymentPollGroupChannel 新增后的编排模型
     * @throws PaymentException
     */
    public function create(array $data): PaymentPollGroupChannel
    {
        $this->assertPairUnique((int) $data['poll_group_id'], (int) $data['channel_id']);
        $this->assertChannelMatchesPollGroup($data);
        $payload = $this->normalizePayload(array_merge($current->toArray(), $data));

        return $this->transaction(function () use ($payload) {
            // 一个轮询组只能有一个默认通道，新增默认项前先清理掉其他默认标记。
            if ((int) ($payload['is_default'] ?? 0) === 1) {
                $this->paymentPollGroupChannelRepository->clearDefaultExcept((int) $payload['poll_group_id']);
            }

            return $this->paymentPollGroupChannelRepository->create($payload);
        });
    }

    /**
     * 更新轮询组通道编排。
     *
     * @param int $id 编排ID
     * @param array $data 写入数据
     * @return PaymentPollGroupChannel|null 更新后的编排模型
     * @throws PaymentException
     */
    public function update(int $id, array $data): ?PaymentPollGroupChannel
    {
        $current = $this->paymentPollGroupChannelRepository->find($id);
        if (!$current) {
            return null;
        }

        $pollGroupId = (int) ($data['poll_group_id'] ?? $current->poll_group_id);
        $channelId = (int) ($data['channel_id'] ?? $current->channel_id);
        $this->assertPairUnique($pollGroupId, $channelId, $id);
        $this->assertChannelMatchesPollGroup(array_merge($current->toArray(), $data));

        $payload = $this->normalizePayload($data);

        return $this->transaction(function () use ($id, $payload) {
            // 更新成默认通道时，同样先把本轮询组的其他默认项清空。
            if ((int) ($payload['is_default'] ?? 0) === 1) {
                $this->paymentPollGroupChannelRepository->clearDefaultExcept((int) $payload['poll_group_id'], $id);
            }

            if (!$this->paymentPollGroupChannelRepository->updateById($id, $payload)) {
                return null;
            }

            return $this->paymentPollGroupChannelRepository->find($id);
        });
    }

    /**
     * 删除轮询组通道编排。
     *
     * @param int $id 编排ID
     * @return bool 是否删除成功
     */
    public function delete(int $id): bool
    {
        return $this->paymentPollGroupChannelRepository->deleteById($id);
    }

    /**
     * 标准化编排写入数据。
     *
     * @param array $data 写入数据
     * @return array<string, mixed> 标准化后的数据
     */
    private function normalizePayload(array $data): array
    {
        return [
            'poll_group_id' => (int) $data['poll_group_id'],
            'channel_id' => (int) $data['channel_id'],
            'sort_no' => (int) ($data['sort_no'] ?? 0),
            // 权重至少为 1，避免轮询时出现 0 权重通道导致随机分配失真。
            'weight' => max(1, (int) ($data['weight'] ?? 100)),
            'is_default' => (int) ($data['is_default'] ?? 0),
            'status' => (int) ($data['status'] ?? 1),
            'remark' => trim((string) ($data['remark'] ?? '')),
        ];
    }

    /**
     * 校验轮询组与通道的组合唯一性。
     *
     * @param int $pollGroupId 轮询组ID
     * @param int $channelId 通道ID
     * @param int $ignoreId 排除的编排ID
     * @return void
     * @throws PaymentException
     */
    private function assertPairUnique(int $pollGroupId, int $channelId, int $ignoreId = 0): void
    {
        $query = $this->paymentPollGroupChannelRepository->query()
            ->where('poll_group_id', $pollGroupId)
            ->where('channel_id', $channelId);

        if ($ignoreId > 0) {
            $query->where('id', '<>', $ignoreId);
        }

        if ($query->exists()) {
            throw new PaymentException('该轮询组已添加当前支付通道', 40230, [
                'poll_group_id' => $pollGroupId,
                'channel_id' => $channelId,
            ]);
        }
    }

    /**
     * 校验通道支付方式与轮询组支付方式一致。
     *
     * @param array $data 写入数据
     * @return void
     * @throws PaymentException
     */
    private function assertChannelMatchesPollGroup(array $data): void
    {
        $pollGroupId = (int) ($data['poll_group_id'] ?? 0);
        $channelId = (int) ($data['channel_id'] ?? 0);

        $pollGroup = $this->paymentPollGroupRepository->find($pollGroupId);
        $channel = $this->paymentChannelRepository->find($channelId);

        if (!$pollGroup || !$channel) {
            return;
        }

        // 管理后台的轮询组是商户分组级平台路由，只能编排平台代收通道。
        // 商户自建通道只允许在当前商户发起支付时进入二次候选，不能被分配给其他商户。
        if ((int) $channel->merchant_id !== 0 || (int) $channel->channel_mode !== RouteConstant::CHANNEL_MODE_COLLECT) {
            throw new PaymentException('轮询组只能配置平台代收通道，不能分配商户自建通道', 40232, [
                'poll_group_id' => $pollGroupId,
                'channel_id' => $channelId,
                'merchant_id' => (int) $channel->merchant_id,
            ]);
        }

        // 轮询组和通道必须属于同一支付方式，否则排序再正确也会在运行时被路由规则拦下。
        if ((int) $pollGroup->pay_type_id !== (int) $channel->pay_type_id) {
            throw new PaymentException('轮询组与支付通道的支付方式不一致', 40231, [
                'poll_group_id' => $pollGroupId,
                'channel_id' => $channelId,
            ]);
        }
    }
}

