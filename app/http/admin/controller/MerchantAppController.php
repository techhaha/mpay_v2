<?php

namespace app\http\admin\controller;

use app\common\base\BaseController;
use app\repositories\MerchantAppRepository;
use app\repositories\MerchantRepository;
use support\Request;

/**
 * 商户应用管理
 */
class MerchantAppController extends BaseController
{
    public function __construct(
        protected MerchantAppRepository $merchantAppRepository,
        protected MerchantRepository $merchantRepository,
    ) {
    }

    /**
     * GET /adminapi/merchant-app/list
     */
    public function list(Request $request)
    {
        $page = (int)$request->get('page', 1);
        $pageSize = (int)$request->get('page_size', 10);

        $filters = [
            'merchant_id' => (int)$request->get('merchant_id', 0),
            'status' => $request->get('status', ''),
            'app_id' => trim((string)$request->get('app_id', '')),
            'app_name' => trim((string)$request->get('app_name', '')),
            'api_type' => trim((string)$request->get('api_type', '')),
        ];

        $paginator = $this->merchantAppRepository->searchPaginate($filters, $page, $pageSize);
        return $this->page($paginator);
    }

    /**
     * GET /adminapi/merchant-app/detail?id=1
     */
    public function detail(Request $request)
    {
        $id = (int)$request->get('id', 0);
        if ($id <= 0) {
            return $this->fail('应用ID不能为空', 400);
        }

        $row = $this->merchantAppRepository->find($id);
        if (!$row) {
            return $this->fail('应用不存在', 404);
        }

        return $this->success($row);
    }

    /**
     * POST /adminapi/merchant-app/save
     */
    public function save(Request $request)
    {
        $data = $request->post();
        $id = (int)($data['id'] ?? 0);

        $merchantId = (int)($data['merchant_id'] ?? 0);
        $apiType = trim((string)($data['api_type'] ?? 'epay'));
        $appId = trim((string)($data['app_id'] ?? ''));
        $appName = trim((string)($data['app_name'] ?? ''));
        $status = (int)($data['status'] ?? 1);

        if ($merchantId <= 0 || $appId === '' || $appName === '') {
            return $this->fail('商户、应用ID、应用名称不能为空', 400);
        }

        $merchant = $this->merchantRepository->find($merchantId);
        if (!$merchant) {
            return $this->fail('商户不存在', 404);
        }

        if (!in_array($apiType, ['openapi', 'epay', 'custom', 'default'], true)) {
            return $this->fail('api_type 不合法', 400);
        }

        if ($id > 0) {
            $row = $this->merchantAppRepository->find($id);
            if (!$row) {
                return $this->fail('应用不存在', 404);
            }

            // app_id 变更需校验唯一
            if ($row->app_id !== $appId) {
                $exists = $this->merchantAppRepository->findAnyByAppId($appId);
                if ($exists) {
                    return $this->fail('应用ID已存在', 400);
                }
            }

            $update = [
                'merchant_id' => $merchantId,
                'api_type' => $apiType,
                'app_id' => $appId,
                'app_name' => $appName,
                'status' => $status,
            ];

            // 可选：前端传入 app_secret 才更新
            if (!empty($data['app_secret'])) {
                $update['app_secret'] = (string)$data['app_secret'];
            }

            $this->merchantAppRepository->updateById($id, $update);
        } else {
            $exists = $this->merchantAppRepository->findAnyByAppId($appId);
            if ($exists) {
                return $this->fail('应用ID已存在', 400);
            }

            $secret = !empty($data['app_secret']) ? (string)$data['app_secret'] : $this->generateSecret();
            $this->merchantAppRepository->create([
                'merchant_id' => $merchantId,
                'api_type' => $apiType,
                'app_id' => $appId,
                'app_secret' => $secret,
                'app_name' => $appName,
                'status' => $status,
            ]);
        }

        return $this->success(null, '保存成功');
    }

    /**
     * POST /adminapi/merchant-app/reset-secret
     */
    public function resetSecret(Request $request)
    {
        $id = (int)$request->post('id', 0);
        if ($id <= 0) {
            return $this->fail('应用ID不能为空', 400);
        }

        $row = $this->merchantAppRepository->find($id);
        if (!$row) {
            return $this->fail('应用不存在', 404);
        }

        $secret = $this->generateSecret();
        $this->merchantAppRepository->updateById($id, ['app_secret' => $secret]);

        return $this->success(['app_secret' => $secret], '重置成功');
    }

    /**
     * POST /adminapi/merchant-app/toggle
     */
    public function toggle(Request $request)
    {
        $id = (int)$request->post('id', 0);
        $status = $request->post('status', null);

        if ($id <= 0 || $status === null) {
            return $this->fail('参数错误', 400);
        }

        $ok = $this->merchantAppRepository->updateById($id, ['status' => (int)$status]);
        return $ok ? $this->success(null, '操作成功') : $this->fail('操作失败', 500);
    }

    private function generateSecret(): string
    {
        $raw = random_bytes(24);
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}

