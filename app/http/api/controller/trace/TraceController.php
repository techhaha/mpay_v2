<?php

namespace app\http\api\controller\trace;

use app\common\base\BaseController;
use app\http\api\validation\TraceQueryValidator;
use app\service\payment\trace\TradeTraceService;
use support\Request;
use support\Response;

/**
 * 统一追踪查询控制器。
 */
class TraceController extends BaseController
{
    public function __construct(
        protected TradeTraceService $tradeTraceService
    ) {
    }

    /**
     * 查询指定追踪号对应的完整交易链路。
     */
    public function show(Request $request, string $traceNo): Response
    {
        $data = $this->validated(
            array_merge($request->all(), ['trace_no' => $traceNo]),
            TraceQueryValidator::class,
            'show'
        );

        $result = $this->tradeTraceService->queryByTraceNo((string) $data['trace_no']);
        if (empty($result)) {
            return $this->fail('追踪单不存在', 404);
        }

        return $this->success($result);
    }
}
