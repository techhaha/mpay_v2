<?php

namespace app\http\api\controller;

use app\common\base\BaseController;
use app\services\api\EpayProtocolService;
use support\Request;
use support\Response;

/**
 * 易支付控制器
 */
class EpayController extends BaseController
{
    public function __construct(
        protected EpayProtocolService $epayProtocolService
    ) {
    }

    /**
     * 页面跳转支付
     */
    public function submit(Request $request)
    {
        try {
            $result = $this->epayProtocolService->handleSubmit($request);
            $type = $result['response_type'] ?? '';

            if ($type === 'redirect' && !empty($result['url'])) {
                return redirect($result['url']);
            }

            if ($type === 'form_html') {
                return response((string)($result['html'] ?? ''))
                    ->withHeaders(['Content-Type' => 'text/html; charset=UTF-8']);
            }

            if ($type === 'form_params') {
                return $this->renderForm((array)($result['form'] ?? []));
            }

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
        try {
            return json($this->epayProtocolService->handleMapi($request));
        } catch (\Throwable $e) {
            return json([
                'code' => 0,
                'msg' => $e->getMessage(),
            ]);
        }
    }

    /**
     * API接口
     */
    public function api(Request $request)
    {
        try {
            return json($this->epayProtocolService->handleApi($request));
        } catch (\Throwable $e) {
            return json([
                'code' => 0,
                'msg' => $e->getMessage(),
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
