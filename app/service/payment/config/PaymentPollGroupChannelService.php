<?php

namespace app\service\payment\config;

use app\common\base\BaseService;
use app\exception\PaymentException;
use app\model\payment\PaymentPollGroupChannel;
use app\repository\payment\config\PaymentChannelRepository;
use app\repository\payment\config\PaymentPollGroupChannelRepository;
use app\repository\payment\config\PaymentPollGroupRepository;

/**
 * 轮询组通道编排服务。
 */
class PaymentPollGroupChannelService extends BaseService
{
    public function __construct(
        protected PaymentPollGroupChannelRepository $paymentPollGroupChannelRepository,
        protected PaymentPollGroupRepository $paymentPollGroupRepository,
        protected PaymentChannelRepository $paymentChannelRepository
    ) {
    }

    /**
     * 分页查询轮询组通道编排。
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

    public function findById(int $id): ?PaymentPollGroupChannel
    {
        return $this->paymentPollGroupChannelRepository->find($id);
    }

    public function create(array $data): PaymentPollGroupChannel
    {
        $this->assertPairUnique((int) $data['poll_group_id'], (int) $data['channel_id']);
        $this->assertChannelMatchesPollGroup($data);
        $payload = $this->normalizePayload($data);

        return $this->transaction(function () use ($payload) {
            if ((int) ($payload['is_default'] ?? 0) === 1) {
                $this->paymentPollGroupChannelRepository->clearDefaultExcept((int) $payload['poll_group_id']);
            }

            return $this->paymentPollGroupChannelRepository->create($payload);
        });
    }

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
            if ((int) ($payload['is_default'] ?? 0) === 1) {
                $this->paymentPollGroupChannelRepository->clearDefaultExcept((int) $payload['poll_group_id'], $id);
            }

            if (!$this->paymentPollGroupChannelRepository->updateById($id, $payload)) {
                return null;
            }

            return $this->paymentPollGroupChannelRepository->find($id);
        });
    }

    public function delete(int $id): bool
    {
        return $this->paymentPollGroupChannelRepository->deleteById($id);
    }

    private function normalizePayload(array $data): array
    {
        return [
            'poll_group_id' => (int) $data['poll_group_id'],
            'channel_id' => (int) $data['channel_id'],
            'sort_no' => (int) ($data['sort_no'] ?? 0),
            'weight' => max(1, (int) ($data['weight'] ?? 100)),
            'is_default' => (int) ($data['is_default'] ?? 0),
            'status' => (int) ($data['status'] ?? 1),
            'remark' => trim((string) ($data['remark'] ?? '')),
        ];
    }

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

    private function assertChannelMatchesPollGroup(array $data): void
    {
        $pollGroupId = (int) ($data['poll_group_id'] ?? 0);
        $channelId = (int) ($data['channel_id'] ?? 0);

        $pollGroup = $this->paymentPollGroupRepository->find($pollGroupId);
        $channel = $this->paymentChannelRepository->find($channelId);

        if (!$pollGroup || !$channel) {
            return;
        }

        if ((int) $pollGroup->pay_type_id !== (int) $channel->pay_type_id) {
            throw new PaymentException('轮询组与支付通道的支付方式不一致', 40231, [
                'poll_group_id' => $pollGroupId,
                'channel_id' => $channelId,
            ]);
        }
    }
}
