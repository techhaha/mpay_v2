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
 *
 * @property PaymentPollGroupService $paymentPollGroupService 支付轮询分组服务
 */
class PaymentPollGroupController extends BaseController
{
    /**
     * 构造方法。
     *
     * @param PaymentPollGroupService $paymentPollGroupService 支付轮询分组服务
     * @return void
     */
    public function __construct(
        protected PaymentPollGroupService $paymentPollGroupService
    ) {
    }

    /**
     * 查询轮询组列表。
     * 
     * @param Request $request 请求对象
     * @return Response 响应对象
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
     * 查询轮询组详情。
     * 
     * @param Request $request 请求对象
     * @param string $id 支付轮询分组ID
     * @return Response 响应对象
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
     * 新增轮询组。
     * 
     * @param Request $request 请求对象
     * @return Response 响应对象
     */
    public function store(Request $request): Response
    {
        $data = $this->validated($request->all(), PaymentPollGroupValidator::class, 'store');

        return $this->success($this->paymentPollGroupService->create($data));
    }

    /**
     * 修改轮询组。
     * 
     * @param Request $request 请求对象
     * @param string $id 支付轮询分组ID
     * @return Response 响应对象
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
     * 删除轮询组。
     * 
     * @param Request $request 请求对象
     * @param string $id 支付轮询分组ID
     * @return Response 响应对象
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
     *
     * @param Request $request 请求对象
     * @return Response 响应对象
     */
    public function options(Request $request): Response
    {
        return $this->success($this->paymentPollGroupService->enabledOptions($request->all()));
    }
}





