<?php

namespace app\http\admin\controller;

use app\common\base\BaseController;
use app\repositories\PaymentMethodRepository;
use support\Request;

/**
 * 支付方式管理
 */
class PayMethodController extends BaseController
{
    public function __construct(
        protected PaymentMethodRepository $methodRepository,
    ) {
    }

    /**
     * GET /adminapi/pay-method/list
     */
    public function list(Request $request)
    {
        $page = (int)$request->get('page', 1);
        $pageSize = (int)$request->get('page_size', 10);

        $filters = [
            'status' => $request->get('status', ''),
            'method_code' => trim((string)$request->get('method_code', '')),
            'method_name' => trim((string)$request->get('method_name', '')),
        ];

        $paginator = $this->methodRepository->searchPaginate($filters, $page, $pageSize);
        return $this->page($paginator);
    }

    /**
     * POST /adminapi/pay-method/save
     */
    public function save(Request $request)
    {
        $data = $request->post();
        $id = (int)($data['id'] ?? 0);

        $code = trim((string)($data['method_code'] ?? ''));
        $name = trim((string)($data['method_name'] ?? ''));
        $icon = trim((string)($data['icon'] ?? ''));
        $sort = (int)($data['sort'] ?? 0);
        $status = (int)($data['status'] ?? 1);

        if ($code === '' || $name === '') {
            return $this->fail('支付方式编码与名称不能为空', 400);
        }

        if ($id > 0) {
            $this->methodRepository->updateById($id, [
                'type' => $code,
                'name' => $name,
                'icon' => $icon,
                'sort' => $sort,
                'status' => $status,
            ]);
        } else {
            $exists = $this->methodRepository->findAnyByCode($code);
            if ($exists) {
                return $this->fail('支付方式编码已存在', 400);
            }
            $this->methodRepository->create([
                'type' => $code,
                'name' => $name,
                'icon' => $icon,
                'sort' => $sort,
                'status' => $status,
            ]);
        }

        return $this->success(null, '保存成功');
    }

    /**
     * POST /adminapi/pay-method/toggle
     */
    public function toggle(Request $request)
    {
        $id = (int)$request->post('id', 0);
        $status = $request->post('status', null);

        if ($id <= 0 || $status === null) {
            return $this->fail('参数错误', 400);
        }

        $ok = $this->methodRepository->updateById($id, ['status' => (int)$status]);
        return $ok ? $this->success(null, '操作成功') : $this->fail('操作失败', 500);
    }
}

