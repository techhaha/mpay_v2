<?php

namespace app\http\admin\controller;

use app\common\base\BaseController;
use app\services\SystemSettingService;
use support\Request;

/**
 * 系统控制器
 */
class SystemController extends BaseController
{
    public function __construct(
        protected SystemSettingService $settingService
    ) {
    }
    /**
     * GET /system/getDict
     * GET /system/getDict/{code}
     * 
     * 获取字典数据
     * 支持通过路由参数 code 查询指定字典，不传则返回所有字典
     * 
     * 示例：
     * GET /adminapi/system/getDict          - 返回所有字典
     * GET /adminapi/system/getDict/gender    - 返回性别字典
     * GET /adminapi/system/getDict/status    - 返回状态字典
     */
    public function getDict(Request $request, string $code = '')
    {
        $data = $this->settingService->getDict($code);
        return $this->success($data);
    }

    /**
     * GET /system/base-config/tabs
     *
     * 获取所有Tab配置
     * 由 SystemSettingService 负责读取配置和缓存
     */
    public function getTabsConfig()
    {
        $tabs = $this->settingService->getTabs();
        return $this->success($tabs);
    }

    /**
     * GET /system/base-config/form/{tabKey}
     *
     * 获取指定Tab的表单配置
     * 从 SystemSettingService 获取合并后的配置
     */
    public function getFormConfig(Request $request, string $tabKey)
    {
        $formConfig = $this->settingService->getFormConfig($tabKey);
        return $this->success($formConfig);
    }

    /**
     * POST /system/base-config/submit/{tabKey}
     *
     * 提交表单数据
     * 接收表单数据，直接使用字段名（fieldName）作为 config_key 保存到数据库
     */
    public function submitConfig(Request $request, string $tabKey)
    {
        $formData = $request->post();

        if (empty($formData)) {
            return $this->fail('提交数据不能为空', 400);
        }

        $this->settingService->saveFormConfig($tabKey, $formData);
        return $this->success(null, '保存成功');
    }
}

