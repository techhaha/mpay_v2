<?php

namespace app\http\admin\controller;

use app\common\base\BaseController;
use support\Request;

/**
 * 系统控制器
 */
class SystemController extends BaseController
{
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
        // 获取所有字典数据
        $allDicts = config('dict', []);
        
        // 如果指定了 code，则只返回对应的字典
        if (!empty($code)) {
            // 将数组转换为以 code 为键的关联数组，便于快速查找
            $dictsByCode = array_column($allDicts, null, 'code');
            $dict = $dictsByCode[$code] ?? null;
            
            if ($dict === null) {
                return $this->fail('未找到指定的字典：' . $code, 404);
            }
            return $this->success($dict);
        }
        
        // 返回所有字典
        return $this->success($allDicts);
    }
}

