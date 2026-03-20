<?php

namespace app\repositories;

use app\common\base\BaseRepository;
use app\models\PaymentPlugin;

/**
 * 支付插件仓储
 */
class PaymentPluginRepository extends BaseRepository
{
    public function __construct()
    {
        parent::__construct(new PaymentPlugin());
    }

    public function getActivePlugins()
    {
        return $this->model->newQuery()
            ->where('status', 1)
            ->get([
                'code',
                'name',
                'class_name',
                'pay_types',
                'transfer_types',
                'config_schema',
            ]);
    }

    public function findActiveByCode(string $pluginCode): ?PaymentPlugin
    {
        return $this->model->newQuery()
            ->where('code', $pluginCode)
            ->where('status', 1)
            ->first();
    }

    /**
     * 后台按编码查询（不过滤状态）
     */
    public function findByCode(string $pluginCode): ?PaymentPlugin
    {
        return $this->model->newQuery()
            ->where('code', $pluginCode)
            ->first();
    }

    /**
     * 后台列表：支持筛选与模糊搜索
     */
    public function searchPaginate(array $filters = [], int $page = 1, int $pageSize = 10)
    {
        $query = $this->model->newQuery();

        if (($filters['status'] ?? '') !== '' && $filters['status'] !== null) {
            $query->where('status', (int)$filters['status']);
        }
        if (!empty($filters['plugin_code'])) {
            $query->where('code', 'like', '%' . $filters['plugin_code'] . '%');
        }
        if (!empty($filters['plugin_name'])) {
            $query->where('name', 'like', '%' . $filters['plugin_name'] . '%');
        }

        $query->orderByDesc('created_at');
        return $query->paginate($pageSize, ['*'], 'page', $page);
    }

    /**
     * 后台保存：存在则更新，不存在则创建
     */
    public function upsertByCode(string $pluginCode, array $data): PaymentPlugin
    {
        $row = $this->findByCode($pluginCode);
        if ($row) {
            $this->model->newQuery()
                ->where('code', $pluginCode)
                ->update($data);
            return $this->findByCode($pluginCode) ?: $row;
        }

        $data['code'] = $pluginCode;
        /** @var PaymentPlugin $created */
        $created = $this->create($data);
        return $created;
    }

    public function updateStatus(string $pluginCode, int $status): bool
    {
        return (bool)$this->model->newQuery()
            ->where('code', $pluginCode)
            ->update(['status' => $status]);
    }
}
