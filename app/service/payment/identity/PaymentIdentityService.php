<?php

namespace app\service\payment\identity;

use app\common\base\BaseService;
use app\common\constant\PaymentIdentityConstant;
use app\common\interface\PaymentIdentityRequirementInterface;
use app\common\sdk\alipay\AlipayClient;
use app\common\sdk\alipay\AlipaySdkException;
use app\exception\ValidationException;
use app\model\merchant\Merchant;
use app\model\payment\PaymentChannel;
use app\repository\payment\config\PaymentTypeRepository;
use app\service\payment\runtime\PaymentPluginManager;
use GuzzleHttp\Client;
use support\Cache;
use Throwable;

/**
 * 支付用户身份流程服务。
 *
 * 该服务只处理“先获取 openid/buyer_id，再继续支付”的平台公共流程：
 * 1. 让支付插件声明当前订单是否缺少用户身份；
 * 2. 使用 support\Cache 暂存原始支付上下文；
 * 3. 生成授权跳转地址和续跑 token；
 * 4. 身份回填后恢复原始下单参数，交回支付发起服务继续处理。
 */
class PaymentIdentityService extends BaseService
{
    /**
     * 身份流程缓存前缀。
     */
    private const CACHE_PREFIX = 'mpay_payment_identity_';

    /**
     * 身份流程有效期，单位：秒。
     */
    private const CACHE_TTL = 600;

    /**
     * 构造方法。
     *
     * @param PaymentPluginManager $paymentPluginManager 支付插件管理器
     * @param PaymentTypeRepository $paymentTypeRepository 支付方式仓库
     */
    public function __construct(
        protected PaymentPluginManager $paymentPluginManager,
        protected PaymentTypeRepository $paymentTypeRepository
    ) {
    }

    /**
     * 检查当前支付尝试是否需要先获取第三方用户身份。
     *
     * 只有收银台等明确开启 identity_flow 的入口才会进入该逻辑，避免接口直连
     * 场景因为无法交互授权而改变原有行为。
     *
     * @param array<string, mixed> $input 支付发起参数
     * @param Merchant $merchant 商户模型
     * @param PaymentChannel $channel 已选支付通道
     * @param array<string, mixed> $route 路由上下文
     * @return array<string, mixed>|null 身份流程响应或 null
     */
    public function inspect(array $input, Merchant $merchant, PaymentChannel $channel, array $route): ?array
    {
        if (empty($input['identity_flow'])) {
            return null;
        }

        $plugin = $this->paymentPluginManager->createByChannel($channel, (int) $input['pay_type_id']);
        if (!$plugin instanceof PaymentIdentityRequirementInterface) {
            return null;
        }

        $requirement = $plugin->identityRequirement(
            $this->buildPluginPayPayload($input, $merchant)
        );
        if ($requirement === null) {
            return null;
        }

        return $this->remember($input, $channel, $route, $requirement);
    }

    /**
     * 读取身份流程缓存上下文。
     *
     * @param string $token 身份流程 token
     * @return array<string, mixed> 缓存上下文
     */
    public function context(string $token): array
    {
        $token = trim($token);
        if ($token === '') {
            throw new ValidationException('身份流程 token 不能为空');
        }

        $context = Cache::get($this->cacheKey($token));
        if (!is_array($context)) {
            throw new ValidationException('身份流程已过期，请重新发起支付');
        }

        return $context;
    }

    /**
     * 获取前端身份承接页可展示的身份流程上下文。
     *
     * @param string $token 身份流程 token
     * @return array<string, mixed> 前端可见上下文
     */
    public function publicContext(string $token): array
    {
        $context = $this->context($token);
        $requirement = $this->publicRequirement((array) ($context['requirement'] ?? []));

        return [
            'status' => PaymentIdentityConstant::STATUS_REQUIRED,
            PaymentIdentityConstant::FIELD_REQUIRED => true,
            'provider' => (string) ($requirement['provider'] ?? ''),
            'auth_type' => (string) ($requirement['auth_type'] ?? ''),
            'message' => (string) ($requirement['message'] ?? '请先完成用户身份授权'),
            PaymentIdentityConstant::FIELD_RESUME_TOKEN => $token,
            'auth_url' => $this->buildAuthUrl($token, (array) ($context['requirement'] ?? [])),
            'expires_in' => max(0, self::CACHE_TTL - (time() - (int) ($context['created_at'] ?? time()))),
        ];
    }

    /**
     * 删除身份流程缓存。
     *
     * @param string $token 身份流程 token
     * @return void
     */
    public function forget(string $token): void
    {
        $token = trim($token);
        if ($token !== '') {
            Cache::delete($this->cacheKey($token));
        }
    }

    /**
     * 从请求参数中提取可回填到支付扩展里的身份字段。
     *
     * @param array<string, mixed> $input 请求参数
     * @param array<string, mixed> $context 身份流程缓存上下文
     * @return array<string, string> 身份字段
     */
    public function identityFromInput(array $input, array $context = []): array
    {
        $fields = [
            'openid',
            'sub_openid',
            'wx_openid',
            'mini_openid',
            'buyer_id',
            'buyer_open_id',
            'sub_appid',
            'op_app_id',
        ];
        $identity = [];

        foreach ($fields as $field) {
            $value = trim((string) ($input[$field] ?? ''));
            if ($value !== '') {
                $identity[$field] = $value;
            }
        }

        if ($context !== []) {
            $identity = [
                ...$identity,
                ...$this->identityFromPlatformCode($context, $input, $identity),
            ];
        }

        return $identity;
    }

    /**
     * 将用户身份回填到原始支付参数。
     *
     * @param array<string, mixed> $context 身份流程缓存上下文
     * @param array<string, string> $identity 用户身份字段
     * @return array<string, mixed> 可继续发起支付的参数
     */
    public function restoreInput(array $context, array $identity): array
    {
        $input = (array) ($context['input'] ?? []);
        if ($input === []) {
            throw new ValidationException('身份流程上下文缺少原始支付参数');
        }

        $requirement = (array) ($context['requirement'] ?? []);
        $identityField = trim((string) ($requirement['identity_field'] ?? ''));
        $identityValue = $this->firstText(
            $identity[$identityField] ?? '',
            $identity['openid'] ?? '',
            $identity['sub_openid'] ?? '',
            $identity['mini_openid'] ?? '',
            $identity['buyer_open_id'] ?? '',
            $identity['buyer_id'] ?? ''
        );

        if ($identityField !== '' && $identityValue !== '') {
            $identity[$identityField] = $identityValue;
        }

        if ($identity === []) {
            throw new ValidationException('缺少第三方用户身份信息');
        }

        $input['ext_json'] = (array) ($input['ext_json'] ?? []);
        $payment = (array) ($input['ext_json']['payment'] ?? []);
        foreach ($identity as $field => $value) {
            $payment[$field] = $value;
        }
        $input['ext_json']['payment'] = $payment;

        return $input;
    }

    /**
     * 根据微信网页授权 code 换取 openid。
     *
     * @param string $token 身份流程 token
     * @param string $code 微信授权 code
     * @return array{context: array<string, mixed>, identity: array<string, string>} 授权结果
     */
    public function wechatIdentity(string $token, string $code): array
    {
        $context = $this->context($token);
        $requirement = (array) ($context['requirement'] ?? []);
        if (($requirement['provider'] ?? '') !== 'wxpay') {
            throw new ValidationException('当前身份流程不是微信支付授权');
        }

        $appId = trim((string) ($requirement['app_id'] ?? ''));
        $appSecret = trim((string) ($requirement['_app_secret'] ?? ''));
        $code = trim($code);
        if ($appId === '' || $appSecret === '') {
            throw new ValidationException('微信网页授权需要配置公众号 AppSecret');
        }
        if ($code === '') {
            throw new ValidationException('微信授权 code 不能为空');
        }

        $payload = $this->requestWechatOpenid($appId, $appSecret, $code);
        $openid = trim((string) ($payload['openid'] ?? ''));
        if ($openid === '') {
            throw new ValidationException('微信授权未返回 openid', ['response' => $payload]);
        }

        $identity = ['openid' => $openid];
        $unionid = trim((string) ($payload['unionid'] ?? ''));
        if ($unionid !== '') {
            $identity['unionid'] = $unionid;
        }

        return [
            'context' => $context,
            'identity' => $identity,
        ];
    }

    /**
     * 根据小程序端传入的临时授权 code 换取可下单身份。
     *
     * @param array<string, mixed> $context 身份流程上下文
     * @param array<string, mixed> $input 请求参数
     * @param array<string, string> $currentIdentity 已直接传入的身份字段
     * @return array<string, string> 通过平台接口换取的身份字段
     */
    private function identityFromPlatformCode(array $context, array $input, array $currentIdentity): array
    {
        $requirement = (array) ($context['requirement'] ?? []);
        $provider = (string) ($requirement['provider'] ?? '');
        $product = (string) ($requirement['product'] ?? '');
        $authType = (string) ($requirement['auth_type'] ?? '');

        if ($provider === 'wxpay' && $product === 'mini' && $authType === 'mini_program' && empty($currentIdentity['mini_openid'])) {
            $code = $this->firstText($input['wx_login_code'] ?? '', $input['mini_code'] ?? '', $input['code'] ?? '');
            if ($code !== '') {
                return $this->wechatMiniIdentity($requirement, $code);
            }
        }

        if ($provider === 'alipay' && $product === 'mini' && in_array($authType, ['alipay_mini', 'alipay_oauth'], true)
            && $this->firstText($currentIdentity['buyer_id'] ?? '', $currentIdentity['buyer_open_id'] ?? '') === '') {
            $authCode = $this->firstText($input['alipay_auth_code'] ?? '', $input['auth_code'] ?? '', $input['code'] ?? '');
            if ($authCode !== '') {
                return $this->alipayMiniIdentity($requirement, $authCode);
            }
        }

        return [];
    }

    /**
     * 微信小程序登录 code 换取 openid。
     *
     * @param array<string, mixed> $requirement 身份需求
     * @param string $code wx.login 返回的 code
     * @return array<string, string> 身份字段
     */
    private function wechatMiniIdentity(array $requirement, string $code): array
    {
        $appId = trim((string) ($requirement['app_id'] ?? ''));
        $appSecret = trim((string) ($requirement['_app_secret'] ?? ''));
        if ($appId === '' || $appSecret === '') {
            throw new ValidationException('微信小程序授权需要配置小程序 AppSecret');
        }

        try {
            $client = new Client(['timeout' => 10, 'verify' => false]);
            $response = $client->get('https://api.weixin.qq.com/sns/jscode2session', [
                'query' => [
                    'appid' => $appId,
                    'secret' => $appSecret,
                    'js_code' => $code,
                    'grant_type' => 'authorization_code',
                ],
            ]);
            $payload = json_decode((string) $response->getBody(), true);
        } catch (Throwable $e) {
            throw new ValidationException('请求微信小程序登录失败：' . $e->getMessage());
        }

        if (!is_array($payload)) {
            throw new ValidationException('微信小程序登录响应格式错误');
        }
        if (isset($payload['errcode'])) {
            throw new ValidationException((string) ($payload['errmsg'] ?? '微信小程序登录失败'), [
                'errcode' => $payload['errcode'],
            ]);
        }

        $openid = trim((string) ($payload['openid'] ?? ''));
        if ($openid === '') {
            throw new ValidationException('微信小程序登录未返回 openid', ['response' => $payload]);
        }

        $identity = [
            'mini_openid' => $openid,
        ];
        $unionid = trim((string) ($payload['unionid'] ?? ''));
        if ($unionid !== '') {
            $identity['unionid'] = $unionid;
        }

        return $identity;
    }

    /**
     * 支付宝小程序 authCode 换取 buyer_id/buyer_open_id。
     *
     * @param array<string, mixed> $requirement 身份需求
     * @param string $authCode my.getAuthCode 返回的 authCode
     * @return array<string, string> 身份字段
     */
    private function alipayMiniIdentity(array $requirement, string $authCode): array
    {
        $sdkConfig = (array) ($requirement['_alipay_config'] ?? []);
        if ($sdkConfig === []) {
            throw new ValidationException('支付宝小程序授权缺少 SDK 配置');
        }

        try {
            $response = (new AlipayClient($sdkConfig))->oauthToken($authCode);
        } catch (AlipaySdkException $e) {
            throw new ValidationException('请求支付宝小程序授权失败：' . $e->getMessage());
        }

        $data = $response->data();
        $buyerId = $this->firstText($data['user_id'] ?? '', $data['buyer_id'] ?? '');
        $buyerOpenId = $this->firstText($data['open_id'] ?? '', $data['buyer_open_id'] ?? '');
        if ($buyerId === '' && $buyerOpenId === '') {
            throw new ValidationException((string) ($data['sub_msg'] ?? $data['msg'] ?? '支付宝小程序授权未返回用户身份'), [
                'response' => $response->toArray(),
            ]);
        }

        $identity = [];
        if ($buyerId !== '') {
            $identity['buyer_id'] = $buyerId;
        }
        if ($buyerOpenId !== '') {
            $identity['buyer_open_id'] = $buyerOpenId;
        }

        return $identity;
    }

    /**
     * 缓存身份流程上下文并生成前端响应。
     *
     * @param array<string, mixed> $input 支付发起参数
     * @param PaymentChannel $channel 支付通道
     * @param array<string, mixed> $route 路由上下文
     * @param array<string, mixed> $requirement 身份需求
     * @return array<string, mixed> 身份流程响应
     */
    private function remember(array $input, PaymentChannel $channel, array $route, array $requirement): array
    {
        $token = bin2hex(random_bytes(16));
        $context = [
            'token' => $token,
            'input' => $input,
            'channel_id' => (int) $channel->id,
            'route' => $this->publicRouteSnapshot($route),
            'requirement' => $requirement,
            'created_at' => time(),
        ];

        Cache::set($this->cacheKey($token), $context, self::CACHE_TTL);

        $publicRequirement = $this->publicRequirement($requirement);

        return [
            'status' => PaymentIdentityConstant::STATUS_REQUIRED,
            PaymentIdentityConstant::FIELD_REQUIRED => true,
            'message' => (string) ($publicRequirement['message'] ?? '请先完成用户身份授权'),
            PaymentIdentityConstant::FIELD_RESUME_TOKEN => $token,
            PaymentIdentityConstant::FIELD_IDENTITY_URL => $this->identityPageUrl($token),
            'expires_in' => self::CACHE_TTL,
        ];
    }

    /**
     * 构建插件身份判断用的标准下单参数。
     *
     * @param array<string, mixed> $input 支付发起参数
     * @param Merchant $merchant 商户模型
     * @return array<string, mixed> 插件下单参数
     */
    private function buildPluginPayPayload(array $input, Merchant $merchant): array
    {
        $paymentType = $this->paymentTypeRepository->find((int) $input['pay_type_id']);

        return [
            'pay_no' => '',
            'order_id' => '',
            'biz_no' => '',
            'trace_no' => '',
            'channel_request_no' => '',
            'merchant_id' => (int) $input['merchant_id'],
            'merchant_no' => (string) ($merchant->merchant_no ?? ''),
            'pay_type_id' => (int) $input['pay_type_id'],
            'pay_type_code' => (string) ($paymentType->code ?? ''),
            'amount' => (int) $input['pay_amount'],
            'subject' => (string) ($input['subject'] ?? ''),
            'body' => (string) ($input['body'] ?? ''),
            'callback_url' => '',
            'notify_url' => (string) ($input['notify_url'] ?? ''),
            'return_url' => (string) ($input['return_url'] ?? ''),
            'client_ip' => (string) ($input['client_ip'] ?? ''),
            '_env' => (string) (($input['device'] ?? '') ?: 'pc'),
            'extra' => (array) ($input['ext_json'] ?? []),
        ];
    }

    /**
     * 生成微信网页授权或支付宝授权地址。
     *
     * @param string $token 身份流程 token
     * @param array<string, mixed> $requirement 身份需求
     * @return string 授权地址，不适用时为空
     */
    private function buildAuthUrl(string $token, array $requirement): string
    {
        $provider = (string) ($requirement['provider'] ?? '');
        $authType = (string) ($requirement['auth_type'] ?? '');
        $appId = trim((string) ($requirement['app_id'] ?? ''));
        $scope = trim((string) ($requirement['scope'] ?? ''));

        if ($provider === 'wxpay' && $authType === 'wechat_oauth' && $appId !== '' && trim((string) ($requirement['_app_secret'] ?? '')) !== '') {
            $redirectUri = $this->siteUrl('/api/cashier/identity/wechat-callback');
            $query = http_build_query([
                'appid' => $appId,
                'redirect_uri' => $redirectUri,
                'response_type' => 'code',
                'scope' => $scope !== '' ? $scope : 'snsapi_base',
                'state' => $token,
            ]);

            return 'https://open.weixin.qq.com/connect/oauth2/authorize?' . $query . '#wechat_redirect';
        }

        if ($provider === 'wxpay' && $authType === 'mini_program') {
            return $this->wechatMiniLaunchUrl($token, $requirement);
        }

        if ($provider === 'alipay' && in_array($authType, ['alipay_mini', 'alipay_oauth'], true)) {
            return $this->alipayMiniUrlScheme($token, $requirement);
        }

        return '';
    }

    /**
     * 生成微信小程序唤起地址。
     *
     * 微信内打开优先使用 URL Link；普通浏览器优先使用 URL Scheme。
     *
     * @param string $token 身份流程 token
     * @param array<string, mixed> $requirement 身份需求
     * @return string 小程序唤起地址
     */
    private function wechatMiniLaunchUrl(string $token, array $requirement): string
    {
        $launchType = trim((string) ($requirement['mini_launch_type'] ?? ''));
        if ($launchType === 'url_link') {
            return $this->wechatMiniUrlLink($token, $requirement);
        }

        return $this->wechatMiniUrlScheme($token, $requirement);
    }

    /**
     * 生成微信小程序 URL Scheme。
     *
     * @param string $token 身份流程 token
     * @param array<string, mixed> $requirement 身份需求
     * @return string 小程序唤起地址
     */
    private function wechatMiniUrlScheme(string $token, array $requirement): string
    {
        $appId = trim((string) ($requirement['app_id'] ?? ''));
        $appSecret = trim((string) ($requirement['_app_secret'] ?? ''));
        if ($appId === '' || $appSecret === '') {
            return '';
        }

        $payload = [
            'jump_wxa' => $this->wechatMiniJumpWxa($token, $requirement),
            'is_expire' => true,
            'expire_time' => time() + self::CACHE_TTL,
        ];
        $accessToken = $this->wechatAccessToken($appId, $appSecret);

        try {
            $client = new Client(['timeout' => 10, 'verify' => false]);
            $response = $client->post('https://api.weixin.qq.com/wxa/generatescheme', [
                'query' => ['access_token' => $accessToken],
                'json' => $payload,
            ]);
            $result = json_decode((string) $response->getBody(), true);
        } catch (Throwable $e) {
            throw new ValidationException('生成微信小程序唤起地址失败：' . $e->getMessage());
        }

        if (!is_array($result)) {
            throw new ValidationException('微信小程序唤起地址响应格式错误');
        }
        if ((int) ($result['errcode'] ?? 0) !== 0) {
            throw new ValidationException((string) ($result['errmsg'] ?? '生成微信小程序唤起地址失败'), [
                'errcode' => $result['errcode'] ?? '',
            ]);
        }

        $openlink = trim((string) ($result['openlink'] ?? ''));
        if ($openlink === '') {
            throw new ValidationException('微信小程序 URL Scheme 响应未返回 openlink', ['response' => $result]);
        }

        return $openlink;
    }

    /**
     * 生成微信小程序 URL Link。
     *
     * @param string $token 身份流程 token
     * @param array<string, mixed> $requirement 身份需求
     * @return string 小程序唤起地址
     */
    private function wechatMiniUrlLink(string $token, array $requirement): string
    {
        $appId = trim((string) ($requirement['app_id'] ?? ''));
        $appSecret = trim((string) ($requirement['_app_secret'] ?? ''));
        if ($appId === '' || $appSecret === '') {
            return '';
        }

        $payload = [
            ...$this->wechatMiniJumpWxa($token, $requirement),
            'is_expire' => true,
            'expire_time' => time() + self::CACHE_TTL,
        ];
        $accessToken = $this->wechatAccessToken($appId, $appSecret);

        try {
            $client = new Client(['timeout' => 10, 'verify' => false]);
            $response = $client->post('https://api.weixin.qq.com/wxa/generate_urllink', [
                'query' => ['access_token' => $accessToken],
                'json' => $payload,
            ]);
            $result = json_decode((string) $response->getBody(), true);
        } catch (Throwable $e) {
            throw new ValidationException('生成微信小程序 URL Link 失败：' . $e->getMessage());
        }

        if (!is_array($result)) {
            throw new ValidationException('微信小程序 URL Link 响应格式错误');
        }
        if ((int) ($result['errcode'] ?? 0) !== 0) {
            throw new ValidationException((string) ($result['errmsg'] ?? '生成微信小程序 URL Link 失败'), [
                'errcode' => $result['errcode'] ?? '',
            ]);
        }

        $urlLink = trim((string) ($result['url_link'] ?? ''));
        if ($urlLink === '') {
            throw new ValidationException('微信小程序 URL Link 响应未返回 url_link', ['response' => $result]);
        }

        return $urlLink;
    }

    /**
     * 构造微信小程序跳转参数。
     *
     * @param string $token 身份流程 token
     * @param array<string, mixed> $requirement 身份需求
     * @return array<string, string>
     */
    private function wechatMiniJumpWxa(string $token, array $requirement): array
    {
        $jumpWxa = [
            'query' => http_build_query([
                PaymentIdentityConstant::FIELD_RESUME_TOKEN => $token,
                'source' => 'mpay',
            ], '', '&', PHP_QUERY_RFC3986),
            'env_version' => $this->wechatMiniEnvVersion((string) ($requirement['env_version'] ?? 'release')),
        ];
        $path = trim((string) ($requirement['mini_path'] ?? ''));
        if ($path !== '') {
            $jumpWxa['path'] = ltrim($path, '/');
        }

        return $jumpWxa;
    }

    /**
     * 生成支付宝小程序唤起地址。
     *
     * @param string $token 身份流程 token
     * @param array<string, mixed> $requirement 身份需求
     * @return string 小程序唤起地址
     */
    private function alipayMiniUrlScheme(string $token, array $requirement): string
    {
        $appId = trim((string) ($requirement['app_id'] ?? ''));
        if ($appId === '') {
            return '';
        }

        $params = ['appId' => $appId];
        $path = trim((string) ($requirement['mini_path'] ?? ''));
        if ($path !== '') {
            $params['page'] = ltrim($path, '/');
        }
        $params['query'] = http_build_query([
            PaymentIdentityConstant::FIELD_RESUME_TOKEN => $token,
            'source' => 'mpay',
        ], '', '&', PHP_QUERY_RFC3986);

        return 'alipays://platformapi/startapp?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * 获取微信小程序接口调用凭据。
     *
     * @param string $appId 小程序 AppID
     * @param string $appSecret 小程序 AppSecret
     * @return string access_token
     */
    private function wechatAccessToken(string $appId, string $appSecret): string
    {
        $cacheKey = self::CACHE_PREFIX . 'wechat_access_token_' . md5($appId);
        $cached = Cache::get($cacheKey);
        if (is_string($cached) && trim($cached) !== '') {
            return trim($cached);
        }

        try {
            $client = new Client(['timeout' => 10, 'verify' => false]);
            $response = $client->get('https://api.weixin.qq.com/cgi-bin/token', [
                'query' => [
                    'grant_type' => 'client_credential',
                    'appid' => $appId,
                    'secret' => $appSecret,
                ],
            ]);
            $payload = json_decode((string) $response->getBody(), true);
        } catch (Throwable $e) {
            throw new ValidationException('请求微信小程序 access_token 失败：' . $e->getMessage());
        }

        if (!is_array($payload)) {
            throw new ValidationException('微信小程序 access_token 响应格式错误');
        }
        if (isset($payload['errcode'])) {
            throw new ValidationException((string) ($payload['errmsg'] ?? '请求微信小程序 access_token 失败'), [
                'errcode' => $payload['errcode'],
            ]);
        }

        $accessToken = trim((string) ($payload['access_token'] ?? ''));
        if ($accessToken === '') {
            throw new ValidationException('微信小程序 access_token 为空', ['response' => $payload]);
        }

        Cache::set($cacheKey, $accessToken, max(60, (int) ($payload['expires_in'] ?? 7200) - 300));

        return $accessToken;
    }

    /**
     * 标准化微信小程序版本值。
     *
     * @param string $value 原始版本值
     * @return string 微信接口支持的版本值
     */
    private function wechatMiniEnvVersion(string $value): string
    {
        $value = strtolower(trim($value));

        return in_array($value, ['release', 'trial', 'develop'], true) ? $value : 'release';
    }

    /**
     * 请求微信网页授权接口换取 openid。
     *
     * @param string $appId 公众号 AppID
     * @param string $appSecret 公众号 AppSecret
     * @param string $code 授权 code
     * @return array<string, mixed> 微信响应
     */
    private function requestWechatOpenid(string $appId, string $appSecret, string $code): array
    {
        try {
            $client = new Client(['timeout' => 10, 'verify' => false]);
            $response = $client->get('https://api.weixin.qq.com/sns/oauth2/access_token', [
                'query' => [
                    'appid' => $appId,
                    'secret' => $appSecret,
                    'code' => $code,
                    'grant_type' => 'authorization_code',
                ],
            ]);
            $payload = json_decode((string) $response->getBody(), true);
        } catch (Throwable $e) {
            throw new ValidationException('请求微信网页授权失败：' . $e->getMessage());
        }

        if (!is_array($payload)) {
            throw new ValidationException('微信网页授权响应格式错误');
        }
        if (isset($payload['errcode'])) {
            throw new ValidationException((string) ($payload['errmsg'] ?? '微信网页授权失败'), [
                'errcode' => $payload['errcode'],
            ]);
        }

        return $payload;
    }

    /**
     * 过滤不能返回到前端的敏感字段。
     *
     * @param array<string, mixed> $requirement 身份需求
     * @return array<string, mixed> 前端可见身份需求
     */
    private function publicRequirement(array $requirement): array
    {
        foreach (array_keys($requirement) as $key) {
            if (str_starts_with((string) $key, '_')) {
                unset($requirement[$key]);
            }
        }
        unset($requirement['app_secret'], $requirement['secret']);

        return $requirement;
    }

    /**
     * 简化路由上下文，便于排查身份流程来源。
     *
     * @param array<string, mixed> $route 路由上下文
     * @return array<string, mixed> 路由快照
     */
    private function publicRouteSnapshot(array $route): array
    {
        $selected = (array) ($route['selected_channel'] ?? []);

        return [
            'direct' => (bool) ($selected['direct'] ?? false),
            'poll_group_id' => (int) (($route['poll_group']->id ?? 0)),
        ];
    }

    /**
     * 生成缓存键。
     *
     * @param string $token 身份流程 token
     * @return string 缓存键
     */
    private function cacheKey(string $token): string
    {
        return self::CACHE_PREFIX . $token;
    }

    /**
     * 生成站内完整地址。
     *
     * @param string $path 站内路径
     * @return string 完整地址
     */
    private function siteUrl(string $path): string
    {
        return rtrim((string) sys_config('site_url'), '/') . '/' . ltrim($path, '/');
    }

    /**
     * 获取身份承接页站内路径。
     *
     * @param string $token 身份流程 token
     * @return string 站内路径
     */
    private function identityPagePath(string $token): string
    {
        return '/cashier/identity/' . rawurlencode($token);
    }

    /**
     * 获取身份承接页完整地址。
     *
     * @param string $token 身份流程 token
     * @return string 完整地址
     */
    private function identityPageUrl(string $token): string
    {
        return $this->siteUrl($this->identityPagePath($token));
    }

    /**
     * 返回第一个非空字符串。
     *
     * @param mixed ...$values 候选值
     * @return string 非空字符串
     */
    private function firstText(mixed ...$values): string
    {
        foreach ($values as $value) {
            $text = trim((string) $value);
            if ($text !== '') {
                return $text;
            }
        }

        return '';
    }
}
