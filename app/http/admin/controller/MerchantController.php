<?php

namespace app\http\admin\controller;

use app\common\base\BaseController;
use app\repositories\MerchantRepository;
use support\Request;

/**
 * 商户管理
 */
class MerchantController extends BaseController
{
    public function __construct(
        protected MerchantRepository $merchantRepository,
    ) {
    }

    /**
     * GET /adminapi/merchant/list
     */
    public function list(Request $request)
    {
        $page = (int)$request->get('page', 1);
        $pageSize = (int)$request->get('page_size', 10);

        $filters = [
            'status' => $request->get('status', ''),
            'merchant_no' => trim((string)$request->get('merchant_no', '')),
            'merchant_name' => trim((string)$request->get('merchant_name', '')),
        ];

        $paginator = $this->merchantRepository->searchPaginate($filters, $page, $pageSize);
        return $this->page($paginator);
    }

    /**
     * GET /adminapi/merchant/detail?id=1
     */
    public function detail(Request $request)
    {
        $id = (int)$request->get('id', 0);
        if ($id <= 0) {
            return $this->fail('商户ID不能为空', 400);
        }

        $row = $this->merchantRepository->find($id);
        if (!$row) {
            return $this->fail('商户不存在', 404);
        }

        return $this->success($row);
    }

    /**
     * POST /adminapi/merchant/save
     */
    public function save(Request $request)
    {
        $data = $request->post();
        $id = (int)($data['id'] ?? 0);

        $merchantNo = trim((string)($data['merchant_no'] ?? ''));
        $merchantName = trim((string)($data['merchant_name'] ?? ''));
        $fundsMode = trim((string)($data['funds_mode'] ?? 'direct'));
        $status = (int)($data['status'] ?? 1);

        if ($merchantNo === '' || $merchantName === '') {
            return $this->fail('商户号与商户名称不能为空', 400);
        }

        if (!in_array($fundsMode, ['direct', 'wallet', 'hybrid'], true)) {
            return $this->fail('资金模式不合法', 400);
        }

        if ($id > 0) {
            $this->merchantRepository->updateById($id, [
                'merchant_no' => $merchantNo,
                'merchant_name' => $merchantName,
                'funds_mode' => $fundsMode,
                'status' => $status,
            ]);
        } else {
            $exists = $this->merchantRepository->findByMerchantNo($merchantNo);
            if ($exists) {
                return $this->fail('商户号已存在', 400);
            }

            $this->merchantRepository->create([
                'merchant_no' => $merchantNo,
                'merchant_name' => $merchantName,
                'funds_mode' => $fundsMode,
                'status' => $status,
            ]);
        }

        return $this->success(null, '保存成功');
    }

    /**
     * POST /adminapi/merchant/toggle
     */
    public function toggle(Request $request)
    {
        $id = (int)$request->post('id', 0);
        $status = $request->post('status', null);

        if ($id <= 0 || $status === null) {
            return $this->fail('参数错误', 400);
        }

        $ok = $this->merchantRepository->updateById($id, ['status' => (int)$status]);
        return $ok ? $this->success(null, '操作成功') : $this->fail('操作失败', 500);
    }
}

