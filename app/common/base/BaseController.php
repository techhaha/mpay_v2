<?php

namespace app\common\base;

use app\exception\ValidationException;
use support\Context;
use support\Request;
use support\Response;

/**
 * HTTP 层基础控制器。
 *
 * 统一提供响应封装、参数校验、请求上下文读取等通用能力。
 */
class BaseController
{
    /**
     * 返回成功响应。
     *
     * @param mixed $data 响应数据
     * @param string $message 响应消息
     * @param int $code 响应码
     * @return Response 响应对象
     */
    protected function success(mixed $data = null, string $message = '操作成功', int $code = 200): Response
    {
        return json([
            'code' => $code,
            'msg' => $message,
            'data' => $data,
        ]);
    }

    /**
     * 返回失败响应。
     *
     * @param string $message 响应消息
     * @param int $code 响应码
     * @param mixed $data 响应数据
     * @return Response 响应对象
     */
    protected function fail(string $message = '操作失败', int $code = 500, mixed $data = null): Response
    {
        return json([
            'code' => $code,
            'msg' => $message,
            'data' => $data,
        ]);
    }

    /**
     * 返回统一分页响应。
     *
     * @param mixed $paginator 分页器实例
     * @return Response 响应对象
     */
    protected function page(mixed $paginator): Response
    {
        if (!is_object($paginator) || !method_exists($paginator, 'items')) {
            return $this->success([
                'list' => [],
                'total' => 0,
                'page' => 1,
                'size' => 10,
            ]);
        }

        return $this->success([
            'list' => $paginator->items(),
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'size' => $paginator->perPage(),
        ]);
    }

    /**
     * 通过校验器类验证请求数据。
     *
     * @param array $data 请求数据
     * @param string $validatorClass 校验器类
     * @param string|null $scene 校验场景
     * @return array 校验后的数据
     */
    protected function validated(array $data, string $validatorClass, ?string $scene = null): array
    {
        $validator = $validatorClass::make($data);

        if ($scene !== null) {
            $validator = $validator->withScene($scene);
        }

        return $validator
            ->withException(ValidationException::class)
            ->validate();
    }

    /**
     * 获取中间件预处理后的标准化参数。
     *
     * @param Request $request 请求对象
     * @return array 标准化参数
     */
    protected function payload(Request $request): array
    {
        $payload = (array) $request->all();
        $normalized = Context::get('mpay.normalized_input', []);

        if (is_array($normalized) && !empty($normalized)) {
            $payload = array_replace($payload, $normalized);
        }

        return $payload;
    }

    /**
     * 读取请求属性。
     *
     * @param Request $request 请求对象
     * @param string $key 属性名
     * @param mixed $default 默认值
     * @return mixed 请求上下文值
     */
    protected function requestAttribute(Request $request, string $key, mixed $default = null): mixed
    {
        return Context::get($key, $default);
    }

    /**
     * 获取中间件注入的当前管理员 ID。
     *
     * @param Request $request 请求对象
     * @return int 当前管理员ID
     */
    protected function currentAdminId(Request $request): int
    {
        return (int) $this->requestAttribute($request, 'auth.admin_id', 0);
    }

    /**
     * 获取中间件注入的当前商户 ID。
     *
     * @param Request $request 请求对象
     * @return int 当前商户ID
     */
    protected function currentMerchantId(Request $request): int
    {
        return (int) $this->requestAttribute($request, 'auth.merchant_id', 0);
    }

    /**
     * 获取中间件注入的当前商户编号。
     *
     * @param Request $request 请求对象
     * @return string 当前商户编号
     */
    protected function currentMerchantNo(Request $request): string
    {
        return (string) $this->requestAttribute($request, 'auth.merchant_no', '');
    }

}





