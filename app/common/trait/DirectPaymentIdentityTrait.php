<?php

declare(strict_types=1);

namespace app\common\trait;

use app\exception\PaymentDefinitiveException;

/**
 * 为已核验身份字段的直连产品声明公众号、微信小程序或支付宝 UID 授权需求。
 *
 * 宿主继承 BasePayment，并使用 DirectPaymentProductSelectorTrait。产品、上游接口和
 * 身份类型由插件确定；本 Trait 只复用应用作用域检查及声明结构，不发起授权或支付请求。
 */
trait DirectPaymentIdentityTrait
{
    /**
     * 提供当前插件确实使用的授权配置，不自动开通产品或要求所有配置必填。
     *
     * @param list<string> $authTypes wechat_oauth、mini_program、alipay_oauth
     * @return list<array<string, mixed>> 配置表单字段
     */
    private function directPaymentIdentityConfigFields(array $authTypes): array
    {
        $groups = [
            'wechat_oauth' => [
                ['type' => 'input', 'field' => 'wx_mp_app_id', 'title' => '微信公众号 AppID', 'value' => ''],
                ['type' => 'password', 'field' => 'wx_mp_app_secret', 'title' => '微信公众号 AppSecret', 'value' => '', 'tip' => '缺少公众号身份时由 MPAY 网页授权获取；商户已传正确身份无需 AppSecret。'],
            ],
            'mini_program' => [
                ['type' => 'input', 'field' => 'wx_mini_app_id', 'title' => '微信小程序 AppID', 'value' => ''],
                ['type' => 'password', 'field' => 'wx_mini_app_secret', 'title' => '微信小程序 AppSecret', 'value' => ''],
                ['type' => 'input', 'field' => 'wx_mini_launch_path', 'title' => '微信小程序支付承接路径', 'value' => 'pages/pay/index', 'tip' => '该小程序必须部署身份续跑与 requestPayment 能力；不能填写机构托管小程序路径。'],
            ],
            'alipay_oauth' => [
                ['type' => 'input', 'field' => 'alipay_oauth_app_id', 'title' => '支付宝授权应用 AppID', 'value' => ''],
                ['type' => 'textarea', 'field' => 'alipay_oauth_private_key', 'title' => '支付宝授权应用私钥', 'value' => '', 'props' => ['rows' => 5]],
                ['type' => 'textarea', 'field' => 'alipay_oauth_public_key', 'title' => '支付宝公钥', 'value' => '', 'props' => ['rows' => 4]],
                ['type' => 'switch', 'field' => 'alipay_oauth_sandbox', 'title' => '支付宝授权沙箱', 'value' => false, 'tip' => '只控制授权应用，不能与生产支付商户混用沙箱用户。'],
            ],
        ];
        $fields = [];
        foreach ($authTypes as $authType) {
            array_push($fields, ...$groups[$authType]);
        }

        return $fields;
    }

    /**
     * 读取本产品的身份和所属应用，不把其他平台或小程序身份当作兜底。
     *
     * 公众号 openid/sub_openid/wx_openid 是现有协议中的同作用域字段，同时提交时必须一致。
     * 微信商户已传身份时可仅传 sub_appid；MPAY 授权则必须使用通道配置的应用。
     * 支付宝 UID 产品只消费 buyer_id，不将应用级 buyer_open_id 替代为 UID。
     *
     * @param array<string, mixed> $order 标准插件参数
     * @param string $authType 插件已确定的身份类型
     * @return array{app_id:string,identity_field:string,identity:string}
     * @throws PaymentDefinitiveException 身份字段冲突或已声明应用与配置不一致时抛出，尚未请求上游
     */
    private function directPaymentIdentityData(array $order, string $authType): array
    {
        $payment = $this->directPaymentPayload($order);
        [$appField, $identityField, $requestAppField] = match ($authType) {
            'wechat_oauth' => ['wx_mp_app_id', 'openid', 'sub_appid'],
            'mini_program' => ['wx_mini_app_id', 'mini_openid', 'sub_appid'],
            'alipay_oauth' => ['alipay_oauth_app_id', 'buyer_id', 'op_app_id'],
        };
        $appId = (string) $this->getConfig($appField, '');
        $requestAppId = (string) ($payment[$requestAppField] ?? '');
        if ($appId !== '' && $requestAppId !== '' && !hash_equals($appId, $requestAppId)) {
            throw new PaymentDefinitiveException($this->getName() . '用户身份所属 AppID 与当前产品配置不一致', 40200);
        }
        $identity = (string) ($payment[$identityField] ?? '');
        if ($authType === 'wechat_oauth') {
            foreach (['sub_openid', 'wx_openid'] as $field) {
                $value = (string) ($payment[$field] ?? '');
                if ($identity !== '' && $value !== '' && !hash_equals($identity, $value)) {
                    throw new PaymentDefinitiveException($this->getName() . '公众号身份字段不一致：' . $field, 40200);
                }
                $identity = $identity !== '' ? $identity : $value;
            }
        }
        $appId = $appId !== '' ? $appId : $requestAppId;
        if ($authType !== 'alipay_oauth' && $identity !== '' && $appId === '') {
            throw new PaymentDefinitiveException($this->getName() . '已传微信用户身份还必须提供所属 sub_appid 或配置产品 AppID', 40200);
        }

        return ['app_id' => $appId, 'identity_field' => $identityField, 'identity' => $identity];
    }

    /**
     * 为选中的精确产品声明缺失身份；已有身份时不要求授权凭证。
     *
     * @param array<string, mixed> $order 标准插件参数
     * @param string $product 当前产品常量值
     * @param string $authType 当前产品真实使用的身份类型
     * @return array<string, mixed>|null 可由统一身份服务处理的需求
     * @throws PaymentDefinitiveException 缺身份且未配置授权凭证时抛出，不带空身份请求上游
     */
    private function directPaymentIdentityRequirement(array $order, string $product, string $authType): ?array
    {
        $identity = $this->directPaymentIdentityData($order, $authType);
        if ($identity['identity'] !== '') {
            return null;
        }
        $alipay = $authType === 'alipay_oauth';
        $mini = $authType === 'mini_program';
        $appField = $alipay ? 'alipay_oauth_app_id' : ($mini ? 'wx_mini_app_id' : 'wx_mp_app_id');
        $appId = (string) $this->getConfig($appField, '');
        $requirement = [
            'provider' => $alipay ? 'alipay' : 'wxpay',
            'product' => $mini ? 'mini' : ($alipay ? 'jsapi' : 'mp'),
            'channel_product' => $product,
            'auth_type' => $authType,
            'identity_field' => $identity['identity_field'],
            'identity_aliases' => [],
            'app_id' => $appId,
            'scope' => $alipay ? 'auth_base' : 'snsapi_base',
            'message' => $this->getName() . '当前产品需要先获取 ' . $identity['identity_field'],
        ];
        if ($alipay) {
            $privateKey = (string) $this->getConfig('alipay_oauth_private_key', '');
            $publicKey = (string) $this->getConfig('alipay_oauth_public_key', '');
            if ($appId === '' || $privateKey === '' || $publicKey === '') {
                throw new PaymentDefinitiveException($this->getName() . '缺少 buyer_id，请提交支付宝 UID 或配置支付宝授权应用及密钥', 40200);
            }
            $requirement['_alipay_config'] = [
                'mode' => 'key', 'app_id' => $appId, 'private_key' => $privateKey,
                'alipay_public_key' => $publicKey, 'sandbox' => (bool) $this->getConfig('alipay_oauth_sandbox', false),
            ];
        } else {
            $secret = (string) $this->getConfig($mini ? 'wx_mini_app_secret' : 'wx_mp_app_secret', '');
            if ($appId === '' || $secret === '') {
                throw new PaymentDefinitiveException($this->getName() . '缺少 ' . $identity['identity_field'] . '，请提交当前应用身份或配置对应 AppID/AppSecret', 40200);
            }
            $requirement['_app_secret'] = $secret;
            if ($mini) {
                $path = (string) $this->getConfig('wx_mini_launch_path', 'pages/pay/index');
                if ($path === '') {
                    throw new PaymentDefinitiveException($this->getName() . '微信小程序支付承接路径不能为空', 40200);
                }
                $requirement['mini_path'] = $path;
                $requirement['mini_launch_type'] = $order['_env'] === 'wechat' ? 'url_link' : 'url_scheme';
                $requirement['env_version'] = 'release';
            }
        }

        return $requirement;
    }
}
