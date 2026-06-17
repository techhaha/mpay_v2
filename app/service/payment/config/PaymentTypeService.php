<?php

namespace app\service\payment\config;

use app\common\base\BaseService;
use app\common\constant\CommonConstant;
use app\exception\ValidationException;
use app\model\payment\PaymentType;
use app\repository\payment\config\PaymentTypeRepository;

/**
 * 支付方式字典服务。
 *
 * 负责支付方式的基础列表查询、新增、修改、删除和下拉选项输出。
 *
 * @property PaymentTypeRepository $paymentTypeRepository 支付类型仓库
 */
class PaymentTypeService extends BaseService
{
    /**
     * 构造方法。
     *
     * @param PaymentTypeRepository $paymentTypeRepository 支付类型仓库
     * @return void
     */
    public function __construct(
        protected PaymentTypeRepository $paymentTypeRepository
    ) {
    }

    /**
     * 分页查询支付方式。
     *
     * @param array $filters 筛选条件
     * @param int $page 页码
     * @param int $pageSize 每页条数
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator 分页结果
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
     *
     * @return array<int, array{label: string, value: int, code: string}> 启用支付方式选项
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
     * 解析启用中的支付方式。
     *
     * 仅在显式传入编码且命中启用项时返回，未命中直接抛错，不再提供默认回退。
     *
     * @param string $code 支付方式编码
     * @return PaymentType 支付方式模型
     * @throws ValidationException
     */
    public function resolveEnabledType(string $code = ''): PaymentType
    {
        $code = trim($code);
        if ($code !== '') {
            $paymentType = $this->paymentTypeRepository->findByCode($code);
            if ($paymentType && (int) $paymentType->status === CommonConstant::STATUS_ENABLED) {
                return $paymentType;
            }
        }

        throw new ValidationException('未配置可用支付方式');
    }

    /**
     * 根据支付方式编码查询字典。
     *
     * @param string $code 支付方式编码
     * @return PaymentType|null 支付方式模型
     */
    public function findByCode(string $code): ?PaymentType
    {
        return $this->paymentTypeRepository->findByCode(trim($code));
    }

    /**
     * 根据支付方式 ID 解析支付方式编码。
     *
     * @param int $id 支付方式ID
     * @return string 支付方式编码
     */
    public function resolveCodeById(int $id): string
    {
        $paymentType = $this->paymentTypeRepository->find($id);
        return $paymentType ? (string) $paymentType->code : '';
    }

    /**
     * 按 ID 查询支付方式。
     *
     * @param int $id 支付方式ID
     * @return PaymentType|null 支付方式模型
     */
    public function findById(int $id): ?PaymentType
    {
        return $this->paymentTypeRepository->find($id);
    }

    /**
     * 新增支付方式。
     *
     * @param array $data 写入数据
     * @return PaymentType 新增后的支付方式模型
     */
    public function create(array $data): PaymentType
    {
        return $this->paymentTypeRepository->create($this->normalizePayload($data));
    }

    /**
     * 更新支付方式。
     *
     * @param int $id 支付方式ID
     * @param array $data 写入数据
     * @return PaymentType|null 更新后的支付方式模型
     */
    public function update(int $id, array $data): ?PaymentType
    {
        if (!$this->paymentTypeRepository->updateById($id, $this->normalizePayload($data, true))) {
            return null;
        }

        return $this->paymentTypeRepository->find($id);
    }

    /**
     * 删除支付方式。
     *
     * @param int $id 支付方式ID
     * @return bool 是否删除成功
     */
    public function delete(int $id): bool
    {
        return $this->paymentTypeRepository->deleteById($id);
    }

    /**
     * 标准化支付方式写入数据。
     *
     * @param array $data 写入数据
     * @param bool $partial 是否只处理传入字段
     * @return array<string, mixed> 标准化后的写入数据
     */
    private function normalizePayload(array $data, bool $partial = false): array
    {
        $payload = [];

        foreach (['code', 'name', 'icon', 'remark'] as $field) {
            if (!$partial || array_key_exists($field, $data)) {
                $payload[$field] = trim((string) ($data[$field] ?? ''));
            }
        }

        if (!$partial || array_key_exists('sort_no', $data)) {
            $payload['sort_no'] = (int) ($data['sort_no'] ?? 0);
        }

        if (!$partial || array_key_exists('status', $data)) {
            $payload['status'] = (int) ($data['status'] ?? CommonConstant::STATUS_ENABLED);
        }

        return $payload;
    }
}
