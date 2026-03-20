<?php

namespace app\services\api;

use app\validation\EpayValidator;
use support\Request;

/**
 * Epay 协议层服务
 *
 * 负责协议参数提取、校验和协议结果映射，不承载支付核心业务。
 */
class EpayProtocolService
{
    public function __construct(
        protected EpayService $epayService
    ) {
    }

    /**
     * 处理 submit.php 请求
     *
     * @return array{response_type:string,url?:string,html?:string,form?:array}
     */
    public function handleSubmit(Request $request): array
    {
        $data = match ($request->method()) {
            'GET' => $request->get(),
            'POST' => $request->post(),
            default => $request->all(),
        };

        $params = EpayValidator::make($data)
            ->withScene('submit')
            ->validate();

        $result = $this->epayService->submit($params, $request);
        $payParams = $result['pay_params'] ?? [];

        if (($payParams['type'] ?? '') === 'redirect' && !empty($payParams['url'])) {
            return [
                'response_type' => 'redirect',
                'url' => $payParams['url'],
            ];
        }

        if (($payParams['type'] ?? '') === 'form') {
            if (!empty($payParams['html'])) {
                return [
                    'response_type' => 'form_html',
                    'html' => $payParams['html'],
                ];
            }

            return [
                'response_type' => 'form_params',
                'form' => $payParams,
            ];
        }

        return [
            'response_type' => 'error',
        ];
    }

    /**
     * 处理 mapi.php 请求
     */
    public function handleMapi(Request $request): array
    {
        $params = EpayValidator::make($request->post())
            ->withScene('mapi')
            ->validate();

        return $this->epayService->mapi($params, $request);
    }

    /**
     * 处理 api.php 请求
     */
    public function handleApi(Request $request): array
    {
        $data = array_merge($request->get(), $request->post());
        $act = strtolower((string)($data['act'] ?? ''));

        if ($act === 'order') {
            $params = EpayValidator::make($data)
                ->withScene('api_order')
                ->validate();
            return $this->epayService->api($params);
        }

        if ($act === 'refund') {
            $params = EpayValidator::make($data)
                ->withScene('api_refund')
                ->validate();
            return $this->epayService->api($params);
        }

        return [
            'code' => 0,
            'msg' => '不支持的操作类型',
        ];
    }
}

