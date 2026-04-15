<?php

namespace app\service\payment\config;

use app\common\base\BaseService;
use app\common\constant\CommonConstant;
use app\model\payment\PaymentChannel;
use app\repository\payment\config\PaymentChannelRepository;

/**
 * 支付通道查询服务。
 */
class PaymentChannelQueryService extends BaseService
{
    public function __construct(
        protected PaymentChannelRepository $paymentChannelRepository
    ) {
    }

    public function enabledOptions(): array
    {
        return $this->paymentChannelRepository->query()
            ->from('ma_payment_channel as c')
            ->where('c.status', CommonConstant::STATUS_ENABLED)
            ->orderBy('c.sort_no')
            ->orderByDesc('c.id')
            ->get([
                'c.id',
                'c.name',
            ])
            ->map(function (PaymentChannel $channel): array {
                return [
                    'label' => sprintf('%s（%d）', (string) $channel->name, (int) $channel->id),
                    'value' => (int) $channel->id,
                ];
            })
            ->values()
            ->all();
    }

    public function searchOptions(array $filters = [], int $page = 1, int $pageSize = 20): array
    {
        $query = $this->paymentChannelRepository->query()
            ->from('ma_payment_channel as c')
            ->leftJoin('ma_merchant as m', 'c.merchant_id', '=', 'm.id')
            ->leftJoin('ma_payment_type as t', 'c.pay_type_id', '=', 't.id')
            ->select([
                'c.id',
                'c.name',
                'c.merchant_id',
                'c.channel_mode',
                'c.pay_type_id',
                'c.plugin_code',
                'c.status',
            ])
            ->selectRaw("COALESCE(m.merchant_no, '') AS merchant_no")
            ->selectRaw("COALESCE(m.merchant_name, '') AS merchant_name")
            ->selectRaw("COALESCE(t.name, '') AS pay_type_name");

        $ids = $this->normalizeIds($filters['ids'] ?? []);
        if (!empty($ids)) {
            $query->whereIn('c.id', $ids);
        } else {
            $query->where('c.status', CommonConstant::STATUS_ENABLED);

            $keyword = trim((string) ($filters['keyword'] ?? ''));
            if ($keyword !== '') {
                $query->where(function ($builder) use ($keyword) {
                    $builder->where('c.name', 'like', '%' . $keyword . '%')
                        ->orWhere('c.plugin_code', 'like', '%' . $keyword . '%')
                        ->orWhere('m.merchant_no', 'like', '%' . $keyword . '%')
                        ->orWhere('m.merchant_name', 'like', '%' . $keyword . '%');
                });
            }

            if (($payTypeId = (int) ($filters['pay_type_id'] ?? 0)) > 0) {
                $query->where('c.pay_type_id', $payTypeId);
            }

            if (array_key_exists('merchant_id', $filters) && $filters['merchant_id'] !== '' && $filters['merchant_id'] !== null) {
                $query->where('c.merchant_id', (int) $filters['merchant_id']);
            }

            if (array_key_exists('channel_mode', $filters) && $filters['channel_mode'] !== '' && $filters['channel_mode'] !== null) {
                $query->where('c.channel_mode', (int) $filters['channel_mode']);
            }

            $excludeIds = $this->normalizeIds($filters['exclude_ids'] ?? []);
            if (!empty($excludeIds)) {
                $query->whereNotIn('c.id', $excludeIds);
            }
        }

        $paginator = $query
            ->orderBy('c.sort_no')
            ->orderByDesc('c.id')
            ->paginate(max(1, $pageSize), ['*'], 'page', max(1, $page));

        return [
            'list' => collect($paginator->items())
                ->map(function ($channel): array {
                    return [
                        'label' => sprintf('%s（%d）', (string) $channel->name, (int) $channel->id),
                        'value' => (int) $channel->id,
                        'merchant_id' => (int) $channel->merchant_id,
                        'merchant_no' => (string) ($channel->merchant_no ?? ''),
                        'merchant_name' => (string) ($channel->merchant_name ?? ''),
                        'channel_mode' => (int) $channel->channel_mode,
                        'pay_type_id' => (int) $channel->pay_type_id,
                        'pay_type_name' => (string) ($channel->pay_type_name ?? ''),
                        'plugin_code' => (string) $channel->plugin_code,
                    ];
                })
                ->values()
                ->all(),
            'total' => (int) $paginator->total(),
            'page' => (int) $paginator->currentPage(),
            'size' => (int) $paginator->perPage(),
        ];
    }

    public function routeOptions(array $filters = []): array
    {
        $query = $this->paymentChannelRepository->query()
            ->from('ma_payment_channel as c')
            ->leftJoin('ma_payment_type as t', 'c.pay_type_id', '=', 't.id')
            ->where('c.status', CommonConstant::STATUS_ENABLED)
            ->select([
                'c.id',
                'c.name',
                'c.merchant_id',
                'c.channel_mode',
                'c.pay_type_id',
                'c.plugin_code',
            ])
            ->selectRaw("COALESCE(t.name, '') AS pay_type_name");

        if (($payTypeId = (int) ($filters['pay_type_id'] ?? 0)) > 0) {
            $query->where('c.pay_type_id', $payTypeId);
        }

        if (array_key_exists('merchant_id', $filters) && $filters['merchant_id'] !== '') {
            $query->where('c.merchant_id', (int) $filters['merchant_id']);
        }

        return $query
            ->orderBy('c.sort_no')
            ->orderByDesc('c.id')
            ->get()
            ->map(function (PaymentChannel $channel): array {
                return [
                    'label' => sprintf('%s（%d）', (string) $channel->name, (int) $channel->id),
                    'value' => (int) $channel->id,
                    'merchant_id' => (int) $channel->merchant_id,
                    'channel_mode' => (int) $channel->channel_mode,
                    'pay_type_id' => (int) $channel->pay_type_id,
                    'plugin_code' => (string) $channel->plugin_code,
                    'pay_type_name' => (string) ($channel->pay_type_name ?? ''),
                ];
            })
            ->values()
            ->all();
    }

    public function paginate(array $filters = [], int $page = 1, int $pageSize = 10)
    {
        $query = $this->paymentChannelRepository->query()
            ->from('ma_payment_channel as c')
            ->leftJoin('ma_merchant as m', 'c.merchant_id', '=', 'm.id')
            ->select([
                'c.*',
            ])
            ->selectRaw("COALESCE(m.merchant_no, '') AS merchant_no")
            ->selectRaw("COALESCE(m.merchant_name, '') AS merchant_name");

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->where(function ($builder) use ($keyword) {
                $builder->where('c.name', 'like', '%' . $keyword . '%')
                    ->orWhere('c.plugin_code', 'like', '%' . $keyword . '%')
                    ->orWhere('m.merchant_no', 'like', '%' . $keyword . '%')
                    ->orWhere('m.merchant_name', 'like', '%' . $keyword . '%');
            });
        }

        if (array_key_exists('merchant_id', $filters) && $filters['merchant_id'] !== '') {
            $query->where('c.merchant_id', (int) $filters['merchant_id']);
        }

        if (($payTypeId = (int) ($filters['pay_type_id'] ?? 0)) > 0) {
            $query->where('c.pay_type_id', $payTypeId);
        }

        $pluginCode = trim((string) ($filters['plugin_code'] ?? ''));
        if ($pluginCode !== '') {
            $query->where('c.plugin_code', 'like', '%' . $pluginCode . '%');
        }

        if (array_key_exists('channel_mode', $filters) && $filters['channel_mode'] !== '') {
            $query->where('c.channel_mode', (int) $filters['channel_mode']);
        }

        if (array_key_exists('status', $filters) && $filters['status'] !== '') {
            $query->where('c.status', (int) $filters['status']);
        }

        return $query
            ->orderBy('c.sort_no')
            ->orderByDesc('c.id')
            ->paginate(max(1, $pageSize), ['*'], 'page', max(1, $page));
    }

    public function findById(int $id): ?PaymentChannel
    {
        return $this->paymentChannelRepository->find($id);
    }

    private function normalizeIds(array|string|int $ids): array
    {
        if (is_string($ids)) {
            $ids = array_filter(array_map('trim', explode(',', $ids)));
        } elseif (!is_array($ids)) {
            $ids = [$ids];
        }

        return array_values(array_filter(array_map(static fn ($id) => (int) $id, $ids), static fn ($id) => $id > 0));
    }
}
