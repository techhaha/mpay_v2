<?php

namespace app\http\admin\controller\ops;

use app\common\base\BaseController;
use app\http\admin\validation\ChannelDailyStatValidator;
use app\service\ops\stat\ChannelDailyStatService;
use support\Request;
use support\Response;

/**
 * 通道日统计控制器。
 */
class ChannelDailyStatController extends BaseController
{
    /**
     * 构造函数，注入通道日统计服务。
     */
    public function __construct(
        protected ChannelDailyStatService $channelDailyStatService
    ) {
    }

    /**
     * 查询通道日统计列表。
     */
    public function index(Request $request): Response
    {
        $data = $this->validated($request->all(), ChannelDailyStatValidator::class, 'index');

        return $this->page(
            $this->channelDailyStatService->paginate(
                $data,
                (int) ($data['page'] ?? 1),
                (int) ($data['page_size'] ?? 10)
            )
        );
    }

    /**
     * 查询通道日统计详情。
     */
    public function show(Request $request, string $id): Response
    {
        $data = $this->validated(['id' => (int) $id], ChannelDailyStatValidator::class, 'show');
        $stat = $this->channelDailyStatService->findById((int) $data['id']);

        if (!$stat) {
            return $this->fail('通道日统计不存在', 404);
        }

        return $this->success($stat);
    }
}
