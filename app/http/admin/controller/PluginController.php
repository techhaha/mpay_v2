<?php

namespace app\http\admin\controller;

use app\common\base\BaseController;
use app\services\PluginService;
use support\Request;

/**
 * 插件管理控制器
 */
class PluginController extends BaseController
{
    public function __construct(
        protected PluginService $pluginService
    ) {
    }

    /**
     * 获取所有可用插件列表
     * GET /adminapi/channel/plugins
     */
    public function plugins()
    {
        $plugins = $this->pluginService->listPlugins();
        return $this->success($plugins);
    }

    /**
     * 获取插件配置Schema
     * GET /adminapi/channel/plugin/config-schema
     */
    public function configSchema(Request $request)
    {
        $pluginCode = $request->get('plugin_code', '');
        $methodCode = $request->get('method_code', '');

        if (empty($pluginCode) || empty($methodCode)) {
            return $this->fail('插件编码和支付方式不能为空', 400);
        }

        try {
            $schema = $this->pluginService->getConfigSchema($pluginCode, $methodCode);
            return $this->success($schema);
        } catch (\Throwable $e) {
            return $this->fail('获取配置Schema失败：' . $e->getMessage(), 400);
        }
    }

    /**
     * 获取插件支持的支付产品列表
     * GET /adminapi/channel/plugin/products
     */
    public function products(Request $request)
    {
        $pluginCode = $request->get('plugin_code', '');
        $methodCode = $request->get('method_code', '');

        if (empty($pluginCode) || empty($methodCode)) {
            return $this->fail('插件编码和支付方式不能为空', 400);
        }

        try {
            $products = $this->pluginService->getSupportedProducts($pluginCode, $methodCode);
            return $this->success($products);
        } catch (\Throwable $e) {
            return $this->fail('获取产品列表失败：' . $e->getMessage(), 400);
        }
    }
}

