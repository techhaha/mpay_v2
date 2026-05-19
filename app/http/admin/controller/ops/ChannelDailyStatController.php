<?php

namespace app\http\admin\controller\ops;

use app\common\base\BaseController;
use app\http\admin\validation\ChannelDailyStatValidator;
use app\service\ops\stat\ChannelDailyStatService;
use support\Request;
use support\Response;

/**
 * 通道日统计控制器。
 *
 * @property ChannelDailyStatService $channelDailyStatService 渠道日统计服务
 */
class ChannelDailyStatController extends BaseController
{
    /**
     * 构造方法。
     *
     * @param ChannelDailyStatService $channelDailyStatService 渠道日统计服务
     * @return void
     */
    public function __construct(
        protected ChannelDailyStatService $channelDailyStatService
    ) {
    }

    /**
     * 查询通道日统计列表。
     *
     * @param Request $request 请求对象
     * @return Response 响应对象
     */
    public function index(Request $request): Response
    {
        $data = $this->validated($request->all(), ChannelDailyStatValidator::class, 'index');
        $paginator = $this->channelDailyStatService->paginate(
            $data,
            (int) ($data['page'] ?? 1),
            (int) ($data['page_size'] ?? 10)
        );

        return $this->success([
            'list' => $paginator->items(),
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'size' => $paginator->perPage(),
            'summary' => $this->channelDailyStatService->summary($data),
        ]);
    }

    /**
     * 查询通道日统计详情。
     *
     * @param Request $request 请求对象
     * @param string $id 渠道日统计ID
     * @return Response 响应对象
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





