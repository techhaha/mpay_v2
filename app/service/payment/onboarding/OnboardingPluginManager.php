<?php

namespace app\service\payment\onboarding;

use app\common\base\BaseService;
use app\common\interface\OnboardingPluginInterface;
use app\common\interface\PayPluginInterface;
use app\exception\PaymentException;
use app\model\payment\PaymentPluginOnboardingConf;
use app\repository\payment\config\PaymentPluginRepository;

/**
 * 进件插件运行时管理器。
 */
class OnboardingPluginManager extends BaseService
{
    public function __construct(
        protected PaymentPluginRepository $paymentPluginRepository
    ) {
    }

    /**
     * 按插件进件配置创建插件实例。
     *
     * @param PaymentPluginOnboardingConf $config 进件配置
     * @return OnboardingPluginInterface&PayPluginInterface 插件实例
     * @throws PaymentException
     */
    public function createByConfig(PaymentPluginOnboardingConf $config): OnboardingPluginInterface&PayPluginInterface
    {
        $pluginCode = (string) $config->plugin_code;
        $plugin = $this->paymentPluginRepository->findByCode($pluginCode);
        if (!$plugin) {
            throw new PaymentException('进件插件不存在', 404, [
                'plugin_code' => $pluginCode,
            ]);
        }

        if ((int) $plugin->status !== 1) {
            throw new PaymentException('进件插件已禁用', 40270, [
                'plugin_code' => $pluginCode,
            ]);
        }

        $className = 'app\\common\\payment\\' . (string) $plugin->class_name;
        if (!class_exists($className)) {
            throw new PaymentException('进件插件类不存在', 40271, [
                'plugin_code' => $pluginCode,
                'class_name' => $className,
            ]);
        }

        $instance = container_make($className, []);
        // 进件能力独立于支付能力，运行时必须同时满足插件生命周期和进件接口契约。
        if (!$instance instanceof PayPluginInterface || !$instance instanceof OnboardingPluginInterface) {
            throw new PaymentException('插件未实现进件能力接口', 40272, [
                'plugin_code' => $pluginCode,
            ]);
        }

        $runtimeConfig = is_array($config->config) ? $config->config : [];
        // 把配置上下文注入插件，插件只读取凭证和当前进件渠道标识，不访问业务表。
        $runtimeConfig['onboarding_config_id'] = (int) $config->id;
        $runtimeConfig['onboarding_config_name'] = (string) $config->name;
        $instance->init($runtimeConfig);

        return $instance;
    }
}
