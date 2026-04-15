<?php

namespace app\http\admin\controller\payment;

use app\common\base\BaseController;
use app\http\admin\validation\PaymentTypeValidator;
use app\service\payment\config\PaymentTypeService;
use support\Request;
use support\Response;

/**
 * 支付方式管理控制器。
 *
 * 负责支付方式字典的列表、详情、新增、修改、删除和选项输出。
 */
class PaymentTypeController extends BaseController
{
    /**
     * 构造函数，注入支付方式服务。
     */
    public function __construct(
        protected PaymentTypeService $paymentTypeService
    ) {
    }

    /**
     * 查询支付方式列表。
     */
    public function index(Request $request): Response
    {
        $data = $this->validated($request->all(), PaymentTypeValidator::class, 'index');
        $page = max(1, (int) ($data['page'] ?? 1));
        $pageSize = max(1, (int) ($data['page_size'] ?? 10));

        return $this->page($this->paymentTypeService->paginate($data, $page, $pageSize));
    }

    /**
     * 查询支付方式详情。
     */
    public function show(Request $request, string $id): Response
    {
        $data = $this->validated(['id' => (int) $id], PaymentTypeValidator::class, 'show');
        $paymentType = $this->paymentTypeService->findById((int) $data['id']);

        if (!$paymentType) {
            return $this->fail('支付方式不存在', 404);
        }

        return $this->success($paymentType);
    }

    /**
     * 新增支付方式。
     */
    public function store(Request $request): Response
    {
        $data = $this->validated($request->all(), PaymentTypeValidator::class, 'store');

        return $this->success($this->paymentTypeService->create($data));
    }

    /**
     * 修改支付方式。
     */
    public function update(Request $request, string $id): Response
    {
        $payload = $request->all();
        $scene = array_diff(array_keys($payload), ['status']) === [] ? 'updateStatus' : 'update';
        $data = $this->validated(
            array_merge($payload, ['id' => (int) $id]),
            PaymentTypeValidator::class,
            $scene
        );

        $paymentType = $this->paymentTypeService->update((int) $data['id'], $data);
        if (!$paymentType) {
            return $this->fail('支付方式不存在', 404);
        }

        return $this->success($paymentType);
    }

    /**
     * 删除支付方式。
     */
    public function destroy(Request $request, string $id): Response
    {
        $data = $this->validated(['id' => (int) $id], PaymentTypeValidator::class, 'destroy');

        if (!$this->paymentTypeService->delete((int) $data['id'])) {
            return $this->fail('支付方式不存在', 404);
        }

        return $this->success(true);
    }

    /**
     * 查询支付方式下拉选项。
     */
    public function options(Request $request): Response
    {
        return $this->success($this->paymentTypeService->enabledOptions());
    }
}
