<?php

namespace app\http\admin\controller\payment;

use app\common\base\BaseController;
use app\http\admin\validation\PaymentPollGroupBindValidator;
use app\service\payment\config\PaymentPollGroupBindService;
use support\Request;
use support\Response;

/**
 * 商户分组路由绑定控制器。
 *
 * @property PaymentPollGroupBindService $paymentPollGroupBindService 支付轮询分组绑定服务
 */
class PaymentPollGroupBindController extends BaseController
{
    /**
 * 构造方法。
     *
     * @param PaymentPollGroupBindService $paymentPollGroupBindService 支付轮询分组绑定服务
     * @return void
     */
    public function __construct(
        protected PaymentPollGroupBindService $paymentPollGroupBindService
    ) {
    }

    /**
     * 查询支付轮询分组绑定列表
     *
     * @param Request $request 请求对象
     * @return Response 响应对象
     */
    public function index(Request $request): Response
    {
        $data = $this->validated($request->all(), PaymentPollGroupBindValidator::class, 'index');

        return $this->page(
            $this->paymentPollGroupBindService->paginate(
                $data,
                (int) ($data['page'] ?? 1),
                (int) ($data['page_size'] ?? 10)
            )
        );
    }

    /**
     * 查询支付轮询分组绑定详情
     *
     * @param Request $request 请求对象
     * @param string $id 支付轮询分组绑定ID
     * @return Response 响应对象
     */
    public function show(Request $request, string $id): Response
    {
        $data = $this->validated(['id' => (int) $id], PaymentPollGroupBindValidator::class, 'show');
        $row = $this->paymentPollGroupBindService->findById((int) $data['id']);
        if (!$row) {
            return $this->fail('商户分组路由绑定不存在', 404);
        }

        return $this->success($row);
    }

    /**
     * 新增支付轮询分组绑定
     *
     * @param Request $request 请求对象
     * @return Response 响应对象
     */
    public function store(Request $request): Response
    {
        $data = $this->validated($request->all(), PaymentPollGroupBindValidator::class, 'store');

        return $this->success($this->paymentPollGroupBindService->create($data));
    }

    /**
     * 更新支付轮询分组绑定
     *
     * @param Request $request 请求对象
     * @param string $id 支付轮询分组绑定ID
     * @return Response 响应对象
     */
    public function update(Request $request, string $id): Response
    {
        $data = $this->validated(
            array_merge($request->all(), ['id' => (int) $id]),
            PaymentPollGroupBindValidator::class,
            'update'
        );

        $row = $this->paymentPollGroupBindService->update((int) $data['id'], $data);
        if (!$row) {
            return $this->fail('商户分组路由绑定不存在', 404);
        }

        return $this->success($row);
    }

    /**
     * 删除支付轮询分组绑定
     *
     * @param Request $request 请求对象
     * @param string $id 支付轮询分组绑定ID
     * @return Response 响应对象
     */
    public function destroy(Request $request, string $id): Response
    {
        $data = $this->validated(['id' => (int) $id], PaymentPollGroupBindValidator::class, 'destroy');
        if (!$this->paymentPollGroupBindService->delete((int) $data['id'])) {
            return $this->fail('商户分组路由绑定不存在', 404);
        }

        return $this->success(true);
    }
}





