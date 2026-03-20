<?php

namespace app\http\admin\controller;

use app\common\base\BaseController;
use app\repositories\PaymentPluginRepository;
use support\Request;

/**
 * 插件注册表管理（ma_pay_plugin）
 *
 * 注意：与 /channel/plugin/* 的“插件能力读取（schema/products）”不同，这里负责维护插件注册表本身。
 */
class PayPluginController extends BaseController
{
    public function __construct(
        protected PaymentPluginRepository $pluginRepository,
    ) {
    }

    /**
     * GET /adminapi/pay-plugin/list
     */
    public function list(Request $request)
    {
        $page = (int)$request->get('page', 1);
        $pageSize = (int)$request->get('page_size', 10);

        $filters = [
            'status' => $request->get('status', ''),
            'plugin_code' => trim((string)$request->get('plugin_code', '')),
            'plugin_name' => trim((string)$request->get('plugin_name', '')),
        ];

        $paginator = $this->pluginRepository->searchPaginate($filters, $page, $pageSize);
        return $this->page($paginator);
    }

    /**
     * POST /adminapi/pay-plugin/save
     */
    public function save(Request $request)
    {
        $data = $request->post();

        $pluginCode = trim((string)($data['plugin_code'] ?? ''));
        $pluginName = trim((string)($data['plugin_name'] ?? ''));
        $className = trim((string)($data['class_name'] ?? ''));
        $status = (int)($data['status'] ?? 1);

        if ($pluginCode === '' || $pluginName === '') {
            return $this->fail('插件编码与名称不能为空', 400);
        }

        if ($className === '') {
            // 默认约定类名
            $className = ucfirst($pluginCode) . 'Payment';
        }

        $this->pluginRepository->upsertByCode($pluginCode, [
            'name' => $pluginName,
            'class_name' => $className,
            'status' => $status,
        ]);

        return $this->success(null, '保存成功');
    }

    /**
     * POST /adminapi/pay-plugin/toggle
     */
    public function toggle(Request $request)
    {
        $pluginCode = trim((string)$request->post('plugin_code', ''));
        $status = $request->post('status', null);

        if ($pluginCode === '' || $status === null) {
            return $this->fail('参数错误', 400);
        }

        $ok = $this->pluginRepository->updateStatus($pluginCode, (int)$status);
        return $ok ? $this->success(null, '操作成功') : $this->fail('操作失败', 500);
    }
}

