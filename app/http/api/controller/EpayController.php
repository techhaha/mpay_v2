<?php

namespace app\http\api\controller;

use app\common\base\BaseController;
use app\services\api\EpayService;
use app\validation\EpayValidator;
use support\Request;
use support\Response;

/**
 * 易支付控制器
 */
class EpayController extends BaseController
{
    public function __construct(
        protected EpayService $epayService
    ) {}

    /**
     * 页面跳转支付
     */
    public function submit(Request $request)
    {
        $data = array_merge($request->get(), $request->post());

        try {
            // 参数校验（使用自定义 Validator + 场景）
            $params = EpayValidator::make($data)
                ->withScene('submit')
                ->validate();

            // 业务处理：创建订单并获取支付参数
            $result    = $this->epayService->submit($params, $request);
            $payParams = $result['pay_params'] ?? [];

            // 根据支付参数类型返回响应
            if (($payParams['type'] ?? '') === 'redirect' && !empty($payParams['url'])) {
                return redirect($payParams['url']);
            }

            if (($payParams['type'] ?? '') === 'form') {
                return $this->renderForm($payParams);
            }

            // 如果没有匹配的类型，返回错误
            return $this->fail('支付参数生成失败');
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    /**
     * API接口支付
     */
    public function mapi(Request $request)
    {
        $data = $request->post();

        try {
            $params = EpayValidator::make($data)
                ->withScene('mapi')
                ->validate();

            $result = $this->epayService->mapi($params, $request);

            return json($result);
        } catch (\Throwable $e) {
            return json([
                'code' => 0,
                'msg'  => $e->getMessage(),
            ]);
        }
    }

    /**
     * API接口
     */
    public function api(Request $request)
    {
        $data = array_merge($request->get(), $request->post());

        try {
            $act = strtolower($data['act'] ?? '');

            if ($act === 'order') {
                $params = EpayValidator::make($data)
                    ->withScene('api_order')
                    ->validate();
                $result = $this->epayService->api($params);
            } elseif ($act === 'refund') {
                $params = EpayValidator::make($data)
                    ->withScene('api_refund')
                    ->validate();
                $result = $this->epayService->api($params);
            } else {
                $result = [
                    'code' => 0,
                    'msg'  => '不支持的操作类型',
                ];
            }

            return json($result);
        } catch (\Throwable $e) {
            return json([
                'code' => 0,
                'msg'  => $e->getMessage(),
            ]);
        }
    }

    /**
     * 渲染表单提交 HTML（用于页面跳转支付）
     */
    private function renderForm(array $formParams): Response
    {
        $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>跳转支付</title></head><body>';
        $html .= '<form id="payForm" method="' . htmlspecialchars($formParams['method'] ?? 'POST') . '" action="' . htmlspecialchars($formParams['action'] ?? '') . '">';

        if (isset($formParams['fields']) && is_array($formParams['fields'])) {
            foreach ($formParams['fields'] as $name => $value) {
                $html .= '<input type="hidden" name="' . htmlspecialchars($name) . '" value="' . htmlspecialchars((string)$value) . '">';
            }
        }

        $html .= '</form>';
        $html .= '<script>document.getElementById("payForm").submit();</script>';
        $html .= '</body></html>';

        return response($html)->withHeaders(['Content-Type' => 'text/html; charset=UTF-8']);
    }
}
