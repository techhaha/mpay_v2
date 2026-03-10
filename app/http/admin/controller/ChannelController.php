<?php

namespace app\http\admin\controller;

use app\common\base\BaseController;
use app\repositories\{PaymentChannelRepository, PaymentMethodRepository};
use app\services\PluginService;
use support\Request;

/**
 * 通道管理控制器
 */
class ChannelController extends BaseController
{
    public function __construct(
        protected PaymentChannelRepository $channelRepository,
        protected PaymentMethodRepository $methodRepository,
        protected PluginService $pluginService,
    ) {
    }

    /**
     * 通道列表
     * GET /adminapi/channel/list
     */
    public function list(Request $request)
    {
        $merchantId = (int)$request->get('merchant_id', 0);
        $appId = (int)$request->get('app_id', 0);
        $methodCode = trim((string)$request->get('method_code', ''));

        $where = [];
        if ($merchantId > 0) {
            $where['merchant_id'] = $merchantId;
        }
        if ($appId > 0) {
            $where['merchant_app_id'] = $appId;
        }
        if ($methodCode !== '') {
            $method = $this->methodRepository->findByCode($methodCode);
            if ($method) {
                $where['method_id'] = $method->id;
            }
        }
        
        $page = (int)($request->get('page', 1));
        $pageSize = (int)($request->get('page_size', 10));
        
        $result = $this->channelRepository->paginate($where, $page, $pageSize);
        
        return $this->success($result);
    }
    
    /**
     * 通道详情
     * GET /adminapi/channel/detail
     */
    public function detail(Request $request)
    {
        $id = (int)$request->get('id', 0);
        if (!$id) {
            return $this->fail('通道ID不能为空', 400);
        }
        
        $channel = $this->channelRepository->find($id);
        if (!$channel) {
            return $this->fail('通道不存在', 404);
        }
        
        $methodCode = '';
        if ($channel->method_id) {
            $method = $this->methodRepository->find($channel->method_id);
            $methodCode = $method ? $method->method_code : '';
        }

        try {
            $configSchema = $this->pluginService->getConfigSchema($channel->plugin_code, $methodCode);

            // 合并当前配置值
            $currentConfig = $channel->getConfigArray();
            if (isset($configSchema['fields']) && is_array($configSchema['fields'])) {
                foreach ($configSchema['fields'] as &$field) {
                    if (isset($field['field']) && isset($currentConfig[$field['field']])) {
                        $field['value'] = $currentConfig[$field['field']];
                    }
                }
            }

            return $this->success([
                'channel'       => $channel,
                'config_schema' => $configSchema,
            ]);
        } catch (\Throwable $e) {
            return $this->success([
                'channel' => $channel,
                'config_schema' => ['fields' => []],
            ]);
        }
    }
    
    /**
     * 保存通道
     * POST /adminapi/channel/save
     */
    public function save(Request $request)
    {
        $data = $request->post();
        
        $id = (int)($data['id'] ?? 0);
        $pluginCode = $data['plugin_code'] ?? '';
        $methodCode = $data['method_code'] ?? '';
        $enabledProducts = $data['enabled_products'] ?? [];
        
        if (empty($pluginCode) || empty($methodCode)) {
            return $this->fail('插件编码和支付方式不能为空', 400);
        }
        
        // 提取配置参数（从表单字段中提取）
        try {
            $configJson = $this->pluginService->buildConfigFromForm($pluginCode, $methodCode, $data);
        } catch (\Throwable $e) {
            return $this->fail('插件不存在或配置错误：' . $e->getMessage(), 400);
        }
        
        $method = $this->methodRepository->findByCode($methodCode);
        if (!$method) {
            return $this->fail('支付方式不存在', 400);
        }

        $configWithProducts = array_merge($configJson, ['enabled_products' => is_array($enabledProducts) ? $enabledProducts : []]);

        $channelData = [
            'merchant_id' => (int)($data['merchant_id'] ?? 0),
            'merchant_app_id' => (int)($data['app_id'] ?? 0),
            'chan_code' => $data['channel_code'] ?? $data['chan_code'] ?? '',
            'chan_name' => $data['channel_name'] ?? $data['chan_name'] ?? '',
            'plugin_code' => $pluginCode,
            'method_id' => $method->id,
            'config_json' => $configWithProducts,
            'split_ratio' => isset($data['split_ratio']) ? (float)$data['split_ratio'] : 100.00,
            'chan_cost' => isset($data['channel_cost']) ? (float)$data['channel_cost'] : 0.00,
            'chan_mode' => $data['channel_mode'] ?? 'wallet',
            'daily_limit' => isset($data['daily_limit']) ? (float)$data['daily_limit'] : 0.00,
            'daily_cnt' => isset($data['daily_count']) ? (int)$data['daily_count'] : 0,
            'min_amount' => isset($data['min_amount']) && $data['min_amount'] !== '' ? (float)$data['min_amount'] : null,
            'max_amount' => isset($data['max_amount']) && $data['max_amount'] !== '' ? (float)$data['max_amount'] : null,
            'status' => (int)($data['status'] ?? 1),
            'sort' => (int)($data['sort'] ?? 0),
        ];
        
        if ($id > 0) {
            // 更新
            $this->channelRepository->updateById($id, $channelData);
        } else {
            if (empty($channelData['chan_code'])) {
                $channelData['chan_code'] = 'CH' . date('YmdHis') . mt_rand(1000, 9999);
            }
            $this->channelRepository->create($channelData);
        }
        
        return $this->success(null, '保存成功');
    }
}

