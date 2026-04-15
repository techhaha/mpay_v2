<?php

namespace app\http\admin\controller\payment;

use app\common\base\BaseController;
use app\http\admin\validation\PaymentPollGroupValidator;
use app\service\payment\config\PaymentPollGroupService;
use support\Request;
use support\Response;

/**
 * 支付轮询组管理控制器。
 *
 * 负责轮询组的列表、详情、新增、修改和删除。
 */
class PaymentPollGroupController extends BaseController
{
    /**
     * 构造函数，注入轮询组服务。
     */
    public function __construct(
        protected PaymentPollGroupService $paymentPollGroupService
    ) {
    }

    /**
     * GET /admin/payment-poll-groups
     *
     * 查询轮询组列表。
     */
    public function index(Request $request): Response
    {
        $data = $this->validated($request->all(), PaymentPollGroupValidator::class, 'index');

        return $this->page(
            $this->paymentPollGroupService->paginate(
                $data,
                (int) ($data['page'] ?? 1),
                (int) ($data['page_size'] ?? 10)
            )
        );
    }

    /**
     * GET /admin/payment-poll-groups/{id}
     *
     * 查询轮询组详情。
     */
    public function show(Request $request, string $id): Response
    {
        $data = $this->validated(['id' => (int) $id], PaymentPollGroupValidator::class, 'show');
        $paymentPollGroup = $this->paymentPollGroupService->findById((int) $data['id']);

        if (!$paymentPollGroup) {
            return $this->fail('轮询组不存在', 404);
        }

        return $this->success($paymentPollGroup);
    }

    /**
     * POST /admin/payment-poll-groups
     *
     * 新增轮询组。
     */
    public function store(Request $request): Response
    {
        $data = $this->validated($request->all(), PaymentPollGroupValidator::class, 'store');

        return $this->success($this->paymentPollGroupService->create($data));
    }

    /**
     * PUT /admin/payment-poll-groups/{id}
     *
     * 修改轮询组。
     */
    public function update(Request $request, string $id): Response
    {
        $payload = $request->all();
        $scene = array_diff(array_keys($payload), ['status']) === [] ? 'updateStatus' : 'update';
        $data = $this->validated(
            array_merge($payload, ['id' => (int) $id]),
            PaymentPollGroupValidator::class,
            $scene
        );

        $paymentPollGroup = $this->paymentPollGroupService->update((int) $data['id'], $data);
        if (!$paymentPollGroup) {
            return $this->fail('轮询组不存在', 404);
        }

        return $this->success($paymentPollGroup);
    }

    /**
     * DELETE /admin/payment-poll-groups/{id}
     *
     * 删除轮询组。
     */
    public function destroy(Request $request, string $id): Response
    {
        $data = $this->validated(['id' => (int) $id], PaymentPollGroupValidator::class, 'destroy');

        if (!$this->paymentPollGroupService->delete((int) $data['id'])) {
            return $this->fail('轮询组不存在', 404);
        }

        return $this->success(true);
    }

    /**
     * 查询轮询组下拉选项。
     */
    public function options(Request $request): Response
    {
        return $this->success($this->paymentPollGroupService->enabledOptions($request->all()));
    }
}
