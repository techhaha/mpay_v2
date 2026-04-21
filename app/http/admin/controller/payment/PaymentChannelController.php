<?php

namespace app\http\admin\controller\payment;

use app\common\base\BaseController;
use app\http\admin\validation\PaymentChannelValidator;
use app\service\payment\config\PaymentChannelService;
use support\Request;
use support\Response;

/**
 * 支付通道管理控制器。
 *
 * 负责支付通道的列表、详情、新增、修改和删除。
 *
 * @property PaymentChannelService $paymentChannelService 支付渠道服务
 */
class PaymentChannelController extends BaseController
{
    /**
     * 构造方法。
     *
     * @param PaymentChannelService $paymentChannelService 支付渠道服务
     * @return void
     */
    public function __construct(
        protected PaymentChannelService $paymentChannelService
    ) {
    }

    /**
     * 查询支付通道列表。
     * 
     * @param Request $request 请求对象
     * @return Response 响应对象
     */
    public function index(Request $request): Response
    {
        $data = $this->validated($request->all(), PaymentChannelValidator::class, 'index');

        return $this->page(
            $this->paymentChannelService->paginate(
                $data,
                (int) ($data['page'] ?? 1),
                (int) ($data['page_size'] ?? 10)
            )
        );
    }

    /**
     * 查询支付通道详情。
     * 
     * @param Request $request 请求对象
     * @param string $id 支付渠道ID
     * @return Response 响应对象
     */
    public function show(Request $request, string $id): Response
    {
        $data = $this->validated(['id' => (int) $id], PaymentChannelValidator::class, 'show');
        $paymentChannel = $this->paymentChannelService->findById((int) $data['id']);

        if (!$paymentChannel) {
            return $this->fail('支付通道不存在', 404);
        }

        return $this->success($paymentChannel);
    }

    /**
     * 新增支付通道。
     * 
     * @param Request $request 请求对象
     * @return Response 响应对象
     */
    public function store(Request $request): Response
    {
        $data = $this->validated($request->all(), PaymentChannelValidator::class, 'store');

        return $this->success($this->paymentChannelService->create($data));
    }

    /**
     * 修改支付通道。
     * 
     * @param Request $request 请求对象
     * @param string $id 支付渠道ID
     * @return Response 响应对象
     */
    public function update(Request $request, string $id): Response
    {
        $payload = $request->all();
        $scene = array_diff(array_keys($payload), ['status']) === [] ? 'updateStatus' : 'update';
        $data = $this->validated(
            array_merge($payload, ['id' => (int) $id]),
            PaymentChannelValidator::class,
            $scene
        );

        $paymentChannel = $this->paymentChannelService->update((int) $data['id'], $data);
        if (!$paymentChannel) {
            return $this->fail('支付通道不存在', 404);
        }

        return $this->success($paymentChannel);
    }

    /**
     * 删除支付通道。
     * 
     * @param Request $request 请求对象
     * @param string $id 支付渠道ID
     * @return Response 响应对象
     */
    public function destroy(Request $request, string $id): Response
    {
        $data = $this->validated(['id' => (int) $id], PaymentChannelValidator::class, 'destroy');

        if (!$this->paymentChannelService->delete((int) $data['id'])) {
            return $this->fail('支付通道不存在', 404);
        }

        return $this->success(true);
    }

    /**
     * 查询启用中的通道选项。
     *
     * @param Request $request 请求对象
     * @return Response 响应对象
     */
    public function options(Request $request): Response
    {
        return $this->success($this->paymentChannelService->enabledOptions());
    }

    /**
     * 查询路由编排场景下的通道选项。
     *
     * @param Request $request 请求对象
     * @return Response 响应对象
     */
    public function routeOptions(Request $request): Response
    {
        return $this->success($this->paymentChannelService->routeOptions($request->all()));
    }

    /**
     * 远程查询支付通道选择项。
     *
     * @param Request $request 请求对象
     * @return Response 响应对象
     */
    public function selectOptions(Request $request): Response
    {
        $page = max(1, (int) $request->input('page', 1));
        $pageSize = min(50, max(1, (int) $request->input('page_size', 20)));

        return $this->success($this->paymentChannelService->searchOptions($request->all(), $page, $pageSize));
    }
}





