<?php

namespace app\http\admin\controller\payment;

use app\common\base\BaseController;
use app\http\admin\validation\PaymentPollGroupChannelValidator;
use app\service\payment\config\PaymentPollGroupChannelService;
use support\Request;
use support\Response;

/**
 * 轮询组通道编排控制器。
 *
 * @property PaymentPollGroupChannelService $paymentPollGroupChannelService 支付轮询分组渠道服务
 */
class PaymentPollGroupChannelController extends BaseController
{
    /**
 * 构造方法。
     *
     * @param PaymentPollGroupChannelService $paymentPollGroupChannelService 支付轮询分组渠道服务
     * @return void
     */
    public function __construct(
        protected PaymentPollGroupChannelService $paymentPollGroupChannelService
    ) {
    }

    /**
     * 查询支付轮询分组渠道列表
     *
     * @param Request $request 请求对象
     * @return Response 响应对象
     */
    public function index(Request $request): Response
    {
        $data = $this->validated($request->all(), PaymentPollGroupChannelValidator::class, 'index');

        return $this->page(
            $this->paymentPollGroupChannelService->paginate(
                $data,
                (int) ($data['page'] ?? 1),
                (int) ($data['page_size'] ?? 10)
            )
        );
    }

    /**
     * 查询支付轮询分组渠道详情
     *
     * @param Request $request 请求对象
     * @param string $id 支付轮询分组渠道ID
     * @return Response 响应对象
     */
    public function show(Request $request, string $id): Response
    {
        $data = $this->validated(['id' => (int) $id], PaymentPollGroupChannelValidator::class, 'show');
        $row = $this->paymentPollGroupChannelService->findById((int) $data['id']);
        if (!$row) {
            return $this->fail('轮询组通道编排不存在', 404);
        }

        return $this->success($row);
    }

    /**
     * 新增支付轮询分组渠道
     *
     * @param Request $request 请求对象
     * @return Response 响应对象
     */
    public function store(Request $request): Response
    {
        $data = $this->validated($request->all(), PaymentPollGroupChannelValidator::class, 'store');

        return $this->success($this->paymentPollGroupChannelService->create($data));
    }

    /**
     * 更新支付轮询分组渠道
     *
     * @param Request $request 请求对象
     * @param string $id 支付轮询分组渠道ID
     * @return Response 响应对象
     */
    public function update(Request $request, string $id): Response
    {
        $payload = $request->all();
        $scene = array_diff(array_keys($payload), ['status']) === [] ? 'updateStatus' : 'update';
        $data = $this->validated(
            array_merge($payload, ['id' => (int) $id]),
            PaymentPollGroupChannelValidator::class,
            $scene
        );

        $row = $this->paymentPollGroupChannelService->update((int) $data['id'], $data);
        if (!$row) {
            return $this->fail('轮询组通道编排不存在', 404);
        }

        return $this->success($row);
    }

    /**
     * 删除支付轮询分组渠道
     *
     * @param Request $request 请求对象
     * @param string $id 支付轮询分组渠道ID
     * @return Response 响应对象
     */
    public function destroy(Request $request, string $id): Response
    {
        $data = $this->validated(['id' => (int) $id], PaymentPollGroupChannelValidator::class, 'destroy');
        if (!$this->paymentPollGroupChannelService->delete((int) $data['id'])) {
            return $this->fail('轮询组通道编排不存在', 404);
        }

        return $this->success(true);
    }
}





