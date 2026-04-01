<?php

namespace app\repositories;

use app\common\base\BaseRepository;
use app\models\PaymentChannel;

class PaymentChannelRepository extends BaseRepository
{
    public function __construct()
    {
        parent::__construct(new PaymentChannel());
    }

    public function findAvailableChannel(int $merchantId, int $merchantAppId, int $methodId): ?PaymentChannel
    {
        return $this->model->newQuery()
            ->where('mer_id', $merchantId)
            ->where('app_id', $merchantAppId)
            ->where('pay_type_id', $methodId)
            ->where('status', 1)
            ->orderBy('sort', 'asc')
            ->first();
    }

    public function findByChanCode(string $chanCode): ?PaymentChannel
    {
        return $this->model->newQuery()
            ->where('chan_code', $chanCode)
            ->first();
    }

    public function searchPaginate(array $filters = [], int $page = 1, int $pageSize = 10)
    {
        $query = $this->buildSearchQuery($filters);
        $query->orderBy('sort', 'asc')->orderByDesc('id');

        return $query->paginate($pageSize, ['*'], 'page', $page);
    }

    public function searchList(array $filters = [])
    {
        return $this->buildSearchQuery($filters)
            ->orderBy('sort', 'asc')
            ->orderByDesc('id')
            ->get();
    }

    private function buildSearchQuery(array $filters = [])
    {
        $query = $this->model->newQuery();

        if (!empty($filters['merchant_id'])) {
            $query->where('mer_id', (int)$filters['merchant_id']);
        }
        if (!empty($filters['merchant_app_id'])) {
            $query->where('app_id', (int)$filters['merchant_app_id']);
        }
        if (!empty($filters['method_id'])) {
            $query->where('pay_type_id', (int)$filters['method_id']);
        }
        if (($filters['status'] ?? '') !== '' && $filters['status'] !== null) {
            $query->where('status', (int)$filters['status']);
        }
        if (!empty($filters['plugin_code'])) {
            $query->where('plugin_code', (string)$filters['plugin_code']);
        }
        if (!empty($filters['chan_code'])) {
            $query->where('chan_code', 'like', '%' . $filters['chan_code'] . '%');
        }
        if (!empty($filters['chan_name'])) {
            $query->where('chan_name', 'like', '%' . $filters['chan_name'] . '%');
        }

        return $query;
    }
}
