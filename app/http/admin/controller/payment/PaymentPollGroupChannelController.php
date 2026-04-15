<?php

namespace app\http\admin\controller\payment;

use app\common\base\BaseController;
use app\http\admin\validation\PaymentPollGroupChannelValidator;
use app\service\payment\config\PaymentPollGroupChannelService;
use support\Request;
use support\Response;

/**
 * 轮询组通道编排控制器。
 */
class PaymentPollGroupChannelController extends BaseController
{
    public function __construct(
        protected PaymentPollGroupChannelService $paymentPollGroupChannelService
    ) {
    }

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

    public function show(Request $request, string $id): Response
    {
        $data = $this->validated(['id' => (int) $id], PaymentPollGroupChannelValidator::class, 'show');
        $row = $this->paymentPollGroupChannelService->findById((int) $data['id']);
        if (!$row) {
            return $this->fail('轮询组通道编排不存在', 404);
        }

        return $this->success($row);
    }

    public function store(Request $request): Response
    {
        $data = $this->validated($request->all(), PaymentPollGroupChannelValidator::class, 'store');

        return $this->success($this->paymentPollGroupChannelService->create($data));
    }

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

    public function destroy(Request $request, string $id): Response
    {
        $data = $this->validated(['id' => (int) $id], PaymentPollGroupChannelValidator::class, 'destroy');
        if (!$this->paymentPollGroupChannelService->delete((int) $data['id'])) {
            return $this->fail('轮询组通道编排不存在', 404);
        }

        return $this->success(true);
    }
}
