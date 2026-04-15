<?php

namespace app\service\payment\config;

use app\common\base\BaseService;
use app\exception\ValidationException;
use app\model\payment\PaymentType;
use app\repository\payment\config\PaymentTypeRepository;

/**
 * 支付方式字典服务。
 *
 * 负责支付方式的基础列表查询、新增、修改、删除和下拉选项输出。
 */
class PaymentTypeService extends BaseService
{
    /**
     * 构造函数，注入支付方式仓库。
     */
    public function __construct(
        protected PaymentTypeRepository $paymentTypeRepository
    ) {
    }

    /**
     * 分页查询支付方式。
     */
    public function paginate(array $filters = [], int $page = 1, int $pageSize = 10)
    {
        $query = $this->paymentTypeRepository->query();

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->where(function ($builder) use ($keyword) {
                $builder->where('code', 'like', '%' . $keyword . '%')
                    ->orWhere('name', 'like', '%' . $keyword . '%');
            });
        }

        $code = trim((string) ($filters['code'] ?? ''));
        if ($code !== '') {
            $query->where('code', 'like', '%' . $code . '%');
        }

        $name = trim((string) ($filters['name'] ?? ''));
        if ($name !== '') {
            $query->where('name', 'like', '%' . $name . '%');
        }

        if (array_key_exists('status', $filters) && $filters['status'] !== '') {
            $query->where('status', (int) $filters['status']);
        }

        return $query
            ->orderBy('sort_no')
            ->orderByDesc('id')
            ->paginate(max(1, $pageSize), ['*'], 'page', max(1, $page));
    }

    /**
     * 查询启用中的支付方式选项。
     */
    public function enabledOptions(): array
    {
        return $this->paymentTypeRepository->enabledList(['id', 'code', 'name'])
            ->map(function (PaymentType $paymentType): array {
                return [
                    'label' => (string) $paymentType->name,
                    'value' => (int) $paymentType->id,
                    'code' => (string) $paymentType->code,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * 解析启用中的支付方式，优先按编码匹配，未命中则取首个启用项。
     */
    public function resolveEnabledType(string $code = ''): PaymentType
    {
        $code = trim($code);
        if ($code !== '') {
            $paymentType = $this->paymentTypeRepository->findByCode($code);
            if ($paymentType && (int) $paymentType->status === 1) {
                return $paymentType;
            }
        }

        $paymentType = $this->paymentTypeRepository->enabledList()->first();
        if (!$paymentType) {
            throw new ValidationException('未配置可用支付方式');
        }

        return $paymentType;
    }

    /**
     * 根据支付方式编码查询字典。
     */
    public function findByCode(string $code): ?PaymentType
    {
        return $this->paymentTypeRepository->findByCode(trim($code));
    }

    /**
     * 根据支付方式 ID 解析支付方式编码。
     */
    public function resolveCodeById(int $id): string
    {
        $paymentType = $this->paymentTypeRepository->find($id);
        return $paymentType ? (string) $paymentType->code : '';
    }

    /**
     * 按 ID 查询支付方式。
     */
    public function findById(int $id): ?PaymentType
    {
        return $this->paymentTypeRepository->find($id);
    }

    /**
     * 新增支付方式。
     */
    public function create(array $data): PaymentType
    {
        return $this->paymentTypeRepository->create($data);
    }

    /**
     * 更新支付方式。
     */
    public function update(int $id, array $data): ?PaymentType
    {
        if (!$this->paymentTypeRepository->updateById($id, $data)) {
            return null;
        }

        return $this->paymentTypeRepository->find($id);
    }

    /**
     * 删除支付方式。
     */
    public function delete(int $id): bool
    {
        return $this->paymentTypeRepository->deleteById($id);
    }
}
