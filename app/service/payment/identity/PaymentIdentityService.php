<?php

namespace app\service\payment\identity;

use app\common\base\BaseService;
use app\common\constant\CommonConstant;
use app\common\constant\PaymentIdentityConstant;
use app\common\constant\RouteConstant;
use app\common\interface\PaymentIdentityRequirementInterface;
use app\common\sdk\alipay\AlipayClient;
use app\common\sdk\alipay\AlipaySdkException;
use app\exception\PaymentProductUnavailableException;
use app\exception\ValidationException;
use app\model\merchant\Merchant;
use app\model\payment\PaymentChannel;
use app\repository\merchant\base\MerchantRepository;
use app\repository\payment\config\PaymentChannelRepository;
use app\repository\payment\config\PaymentTypeRepository;
use app\service\payment\runtime\PaymentPluginManager;
use GuzzleHttp\Client;
use support\Cache;
use support\Redis;
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
     * 身份续跑独占锁有效期，单位：秒。
     */
    private const CLAIM_TTL = 120;

    /**
     * 支持的授权类型及其身份字段白名单。
     *
     * @var array<string, array{provider:string, fields:array<int, string>}>
     */
    private const AUTH_CONTRACTS = [
        'wechat_oauth' => ['provider' => 'wxpay', 'fields' => ['openid', 'sub_openid']],
        'mini_program' => ['provider' => 'wxpay', 'fields' => ['mini_openid']],
        'alipay_oauth' => ['provider' => 'alipay', 'fields' => ['buyer_id', 'buyer_open_id']],
        'alipay_mini' => ['provider' => 'alipay', 'fields' => ['buyer_id', 'buyer_open_id']],
        'unionpay_user_auth' => ['provider' => 'unionpay', 'fields' => ['unionpay_auth_code', 'unionpay_user_id']],
    ];

    /**
     * 构造方法。
     *
     * @param PaymentPluginManager $paymentPluginManager 支付插件管理器
     * @param PaymentTypeRepository $paymentTypeRepository 支付方式仓库
     * @param PaymentChannelRepository $paymentChannelRepository 支付通道仓库
     * @param MerchantRepository $merchantRepository 商户仓库
     */
    public function __construct(
        protected PaymentPluginManager $paymentPluginManager,
        protected PaymentTypeRepository $paymentTypeRepository,
        protected PaymentChannelRepository $paymentChannelRepository,
        protected MerchantRepository $merchantRepository
    ) {
    }

    /**
     * 检查当前支付尝试是否需要先获取第三方用户身份。
     *
     * 缺身份且入口开启 identity_flow 时，返回授权承接地址；没有交互标记则明确拒绝，
     * 不携带空身份继续下单。商户已提交当前产品身份时无需授权。
     *
     * @param array<string, mixed> $input 支付发起参数
     * @param Merchant $merchant 商户模型
     * @param PaymentChannel $channel 已选支付通道
     * @param array<string, mixed> $route 路由上下文
     * @return array<string, mixed>|null 身份流程响应或 null
     */
    public function inspect(array $input, Merchant $merchant, PaymentChannel $channel, array $route): ?array
    {
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
        if (empty($input['identity_flow'])) {
            throw new PaymentProductUnavailableException('当前支付产品需要先完成用户身份授权', 40200, [
                'channel_id' => (int) $channel->id,
                'provider' => (string) ($requirement['provider'] ?? ''),
                'product' => (string) ($requirement['channel_product'] ?? $requirement['product'] ?? ''),
            ]);
        }

        return $this->remember($input, $channel, $route, $this->validateRequirement($requirement));
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
        $privateRequirement = $this->privateRequirement($context);
        $requirement = $this->publicRequirement($privateRequirement);
        $authUrl = $this->buildAuthUrl($token, $privateRequirement);
        if ($authUrl === '') {
            throw new ValidationException('当前支付产品无法生成身份授权地址');
        }

        return [
            'status' => PaymentIdentityConstant::STATUS_REQUIRED,
            PaymentIdentityConstant::FIELD_REQUIRED => true,
            'provider' => (string) ($requirement['provider'] ?? ''),
            'auth_type' => (string) ($requirement['auth_type'] ?? ''),
            'message' => (string) ($requirement['message'] ?? '请先完成用户身份授权'),
            PaymentIdentityConstant::FIELD_RESUME_TOKEN => $token,
            'auth_url' => $authUrl,
            'expires_in' => max(0, self::CACHE_TTL - (time() - (int) ($context['created_at'] ?? time()))),
        ];
    }

    /**
     * 独占身份续跑 token，避免同一授权结果被并发消费。
     *
     * @return array{driver:string,key:string,owner:string,handle?:resource}
     */
    public function claim(string $token): array
    {
        $this->context($token);
        $key = self::CACHE_PREFIX . 'claim_' . hash('sha256', $token);
        $owner = bin2hex(random_bytes(16));

        if ((string) config('cache.default', 'file') === 'redis') {
            $result = Redis::connection()->rawCommand('SET', $key, $owner, 'EX', self::CLAIM_TTL, 'NX');
            if ($result !== true && strtoupper((string) $result) !== 'OK') {
                throw new ValidationException('身份授权结果正在处理中，请勿重复提交');
            }

            return ['driver' => 'redis', 'key' => $key, 'owner' => $owner];
        }

        $directory = runtime_path('locks/payment_identity');
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new ValidationException('身份流程锁目录不可用');
        }
        $handle = fopen($directory . DIRECTORY_SEPARATOR . hash('sha256', $token) . '.lock', 'c+');
        if ($handle === false || !flock($handle, LOCK_EX | LOCK_NB)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new ValidationException('身份授权结果正在处理中，请勿重复提交');
        }

        return ['driver' => 'file', 'key' => $key, 'owner' => $owner, 'handle' => $handle];
    }

    /**
     * 释放身份续跑 token 的独占锁。
     *
     * @param array{driver:string,key:string,owner:string,handle?:resource} $claim
     * @return void
     */
    public function releaseClaim(array $claim): void
    {
        if (($claim['driver'] ?? '') === 'redis') {
            $script = <<<'LUA'
if redis.call('get', KEYS[1]) == ARGV[1] then
    return redis.call('del', KEYS[1])
end
return 0
LUA;
            Redis::connection()->rawCommand('EVAL', $script, 1, (string) $claim['key'], (string) $claim['owner']);
            return;
        }

        $handle = $claim['handle'] ?? null;
        if (is_resource($handle)) {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
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
        if ($context === []) {
            throw new ValidationException('身份恢复缺少支付上下文');
        }

        // 公共恢复入口只接受平台一次性授权码。商户已有的 openid/userid
        // 必须通过最初的签名支付请求提交，不能由浏览器直接声明最终身份。
        return $this->identityFromPlatformCode($context, $input, []);
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
        $identityValue = $this->identityValueForRequirement($requirement, $identity);

        if ($identityField !== '' && $identityValue === '') {
            throw new ValidationException('缺少当前支付产品要求的第三方用户身份', [
                'provider' => (string) ($requirement['provider'] ?? ''),
                'product' => (string) ($requirement['product'] ?? ''),
                'identity_field' => $identityField,
            ]);
        }
        if ($identityField !== '') {
            $input['ext_json'] = (array) ($input['ext_json'] ?? []);
            $payment = (array) ($input['ext_json']['payment'] ?? []);
            $payment[$identityField] = $identityValue;
            $payment['selected_product'] = (string) $requirement['channel_product'];
            // 回填授权时的 AppID，配置在授权后变化时由插件作用域检查拒绝，不能换应用支付。
            $appField = match ($requirement['provider']) {
                'wxpay' => 'sub_appid',
                'alipay' => 'op_app_id',
                default => null,
            };
            $appId = (string) ($requirement['app_id'] ?? '');
            if ($appField !== null && $appId !== '') {
                $requestAppId = (string) ($payment[$appField] ?? '');
                if ($requestAppId !== '' && !hash_equals($appId, $requestAppId)) {
                    throw new ValidationException('原支付请求的用户身份应用与授权应用不一致');
                }
                $payment[$appField] = $appId;
            }
            $input['ext_json']['payment'] = $payment;
        }

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
        $requirement = $this->privateRequirement($context);
        if (($requirement['provider'] ?? '') !== 'wxpay'
            || ($requirement['auth_type'] ?? '') !== 'wechat_oauth') {
            throw new ValidationException('当前身份流程不是微信公众号授权');
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
            throw new ValidationException('微信授权未返回 openid', $this->providerErrorSummary($payload));
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
     * 根据支付宝生活号网页授权 auth_code 换取 buyer_id/buyer_open_id。
     *
     * @param string $token 身份流程 token
     * @param string $authCode 支付宝授权码
     * @return array{context: array<string, mixed>, identity: array<string, string>} 授权结果
     */
    public function alipayIdentity(string $token, string $authCode): array
    {
        $context = $this->context($token);
        $requirement = $this->privateRequirement($context);
        if (($requirement['provider'] ?? '') !== 'alipay'
            || ($requirement['auth_type'] ?? '') !== 'alipay_oauth') {
            throw new ValidationException('当前身份流程不是支付宝生活号授权');
        }

        $authCode = trim($authCode);
        if ($authCode === '') {
            throw new ValidationException('支付宝授权码不能为空');
        }

        return [
            'context' => $context,
            'identity' => $this->alipayMiniIdentity($requirement, $authCode),
        ];
    }

    /**
     * 校验云闪付授权回调并提取一次性授权码。
     *
     * @param array<string, mixed> $input 回调参数
     * @return array{context:array<string, mixed>,identity:array<string, string>}
     */
    public function unionpayIdentity(string $token, array $input): array
    {
        $context = $this->context($token);
        $requirement = $this->privateRequirement($context);
        if (($requirement['provider'] ?? '') !== 'unionpay'
            || ($requirement['auth_type'] ?? '') !== 'unionpay_user_auth') {
            throw new ValidationException('当前身份流程不是云闪付用户授权');
        }

        $responseCode = strtoupper(trim((string) ($input['respCode'] ?? '')));
        if ($responseCode !== '00') {
            throw new ValidationException('云闪付用户授权失败', ['resp_code' => $responseCode]);
        }
        $authCode = trim((string) ($input['userAuthCode'] ?? ''));
        if ($authCode === '') {
            throw new ValidationException('云闪付用户授权未返回授权码');
        }

        return [
            'context' => $context,
            'identity' => ['unionpay_auth_code' => $authCode],
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
        $requirement = $this->privateRequirement($context);
        $provider = (string) ($requirement['provider'] ?? '');
        $authType = (string) ($requirement['auth_type'] ?? '');

        // 插件 product 使用自身稳定产品码（如 wxpay_mini），身份交换只应依赖
        // 已声明的 provider/auth_type，不能把产品码硬编码成通用字符串 mini。
        if ($provider === 'wxpay' && $authType === 'mini_program' && empty($currentIdentity['mini_openid'])) {
            $code = $this->firstText($input['wx_login_code'] ?? '', $input['mini_code'] ?? '', $input['code'] ?? '');
            if ($code !== '') {
                return $this->wechatMiniIdentity($requirement, $code);
            }
        }

        if ($provider === 'alipay' && in_array($authType, ['alipay_mini', 'alipay_oauth'], true)
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
            $client = new Client(['timeout' => 10, 'verify' => true]);
            $response = $client->get('https://api.weixin.qq.com/sns/jscode2session', [
                'query' => [
                    'appid' => $appId,
                    'secret' => $appSecret,
                    'js_code' => $code,
                    'grant_type' => 'authorization_code',
                ],
            ]);
            $payload = json_decode((string) $response->getBody(), true);
        } catch (Throwable) {
            throw new ValidationException('请求微信小程序登录失败');
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
            throw new ValidationException('微信小程序登录未返回 openid', $this->providerErrorSummary($payload));
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
     * 支付宝小程序或生活号 authCode 换取 buyer_id/buyer_open_id。
     *
     * @param array<string, mixed> $requirement 身份需求
     * @param string $authCode my.getAuthCode 返回的 authCode
     * @return array<string, string> 身份字段
     */
    private function alipayMiniIdentity(array $requirement, string $authCode): array
    {
        $sdkConfig = (array) ($requirement['_alipay_config'] ?? []);
        if ($sdkConfig === []) {
            throw new ValidationException('支付宝授权缺少 SDK 配置');
        }

        try {
            $response = (new AlipayClient($sdkConfig))->oauthToken($authCode);
        } catch (AlipaySdkException) {
            throw new ValidationException('请求支付宝授权失败');
        }

        $data = $response->data();
        $buyerId = $this->firstText($data['user_id'] ?? '', $data['buyer_id'] ?? '');
        $buyerOpenId = $this->firstText($data['open_id'] ?? '', $data['buyer_open_id'] ?? '');
        if ($buyerId === '' && $buyerOpenId === '') {
            throw new ValidationException(
                (string) ($data['sub_msg'] ?? $data['msg'] ?? '支付宝授权未返回用户身份'),
                $this->providerErrorSummary($response->toArray())
            );
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
        $publicRequirement = $this->publicRequirement($requirement);
        // 授权开始即固定产品，回调重新加载配置时不能被新开通的更优产品替换。
        $input['ext_json']['payment']['selected_product'] = (string) $requirement['channel_product'];
        $context = [
            'token' => $token,
            'input' => $input,
            'channel_id' => (int) $channel->id,
            'route' => $this->publicRouteSnapshot($route),
            'requirement' => $publicRequirement,
            'requirement_fingerprint' => $this->requirementFingerprint($requirement),
            'created_at' => time(),
        ];

        Cache::set($this->cacheKey($token), $context, self::CACHE_TTL);

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

        if ($provider === 'alipay' && $authType === 'alipay_oauth' && $appId !== '') {
            $redirectUri = $this->siteUrl('/api/cashier/identity/alipay-callback');
            $sdkConfig = (array) ($requirement['_alipay_config'] ?? []);
            $sandbox = filter_var($sdkConfig['sandbox'] ?? false, FILTER_VALIDATE_BOOL);
            $gateway = $sandbox
                ? 'https://openauth.alipaydev.com/oauth2/publicAppAuthorize.htm'
                : 'https://openauth.alipay.com/oauth2/publicAppAuthorize.htm';

            return $gateway . '?' . http_build_query([
                'app_id' => $appId,
                'scope' => $scope !== '' ? $scope : 'auth_base',
                'redirect_uri' => $redirectUri,
                'state' => $token,
            ], '', '&', PHP_QUERY_RFC3986);
        }

        if ($provider === 'alipay' && $authType === 'alipay_mini') {
            return $this->alipayMiniUrlScheme($token, $requirement);
        }

        if ($provider === 'unionpay' && $authType === 'unionpay_user_auth') {
            $redirectUri = $this->siteUrl('/api/cashier/identity/unionpay-callback?state=' . rawurlencode($token));

            return 'https://qr.95516.com/qrcGtwWeb-web/api/userAuth?' . http_build_query([
                'version' => '1.0.0',
                'redirectUrl' => $redirectUri,
            ], '', '&', PHP_QUERY_RFC3986);
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
            $client = new Client(['timeout' => 10, 'verify' => true]);
            $response = $client->post('https://api.weixin.qq.com/wxa/generatescheme', [
                'query' => ['access_token' => $accessToken],
                'json' => $payload,
            ]);
            $result = json_decode((string) $response->getBody(), true);
        } catch (Throwable) {
            throw new ValidationException('生成微信小程序唤起地址失败');
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
            throw new ValidationException('微信小程序 URL Scheme 响应未返回 openlink', $this->providerErrorSummary($result));
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
            $client = new Client(['timeout' => 10, 'verify' => true]);
            $response = $client->post('https://api.weixin.qq.com/wxa/generate_urllink', [
                'query' => ['access_token' => $accessToken],
                'json' => $payload,
            ]);
            $result = json_decode((string) $response->getBody(), true);
        } catch (Throwable) {
            throw new ValidationException('生成微信小程序 URL Link 失败');
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
            throw new ValidationException('微信小程序 URL Link 响应未返回 url_link', $this->providerErrorSummary($result));
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
            $client = new Client(['timeout' => 10, 'verify' => true]);
            $response = $client->get('https://api.weixin.qq.com/cgi-bin/token', [
                'query' => [
                    'grant_type' => 'client_credential',
                    'appid' => $appId,
                    'secret' => $appSecret,
                ],
            ]);
            $payload = json_decode((string) $response->getBody(), true);
        } catch (Throwable) {
            throw new ValidationException('请求微信小程序 access_token 失败');
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
            throw new ValidationException('微信小程序 access_token 为空', $this->providerErrorSummary($payload));
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
            $client = new Client(['timeout' => 10, 'verify' => true]);
            $response = $client->get('https://api.weixin.qq.com/sns/oauth2/access_token', [
                'query' => [
                    'appid' => $appId,
                    'secret' => $appSecret,
                    'code' => $code,
                    'grant_type' => 'authorization_code',
                ],
            ]);
            $payload = json_decode((string) $response->getBody(), true);
        } catch (Throwable) {
            throw new ValidationException('请求微信网页授权失败');
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
     * 重新从当前通道加载授权私密配置，并确认授权期间关键配置未变化。
     *
     * @param array<string, mixed> $context 身份流程上下文
     * @return array<string, mixed> 完整身份需求
     */
    private function privateRequirement(array $context): array
    {
        $input = (array) ($context['input'] ?? []);
        $channelId = (int) ($context['channel_id'] ?? 0);
        $merchantId = (int) ($input['merchant_id'] ?? 0);
        $payTypeId = (int) ($input['pay_type_id'] ?? 0);
        if ($channelId <= 0 || $merchantId <= 0 || $payTypeId <= 0) {
            throw new ValidationException('身份流程上下文不完整');
        }

        /** @var PaymentChannel|null $channel */
        $channel = $this->paymentChannelRepository->find($channelId);
        /** @var Merchant|null $merchant */
        $merchant = $this->merchantRepository->find($merchantId);
        $isPlatformChannel = $channel
            && (int) $channel->merchant_id === 0
            && (int) $channel->channel_mode === RouteConstant::CHANNEL_MODE_COLLECT;
        $isMerchantChannel = $channel
            && (int) $channel->merchant_id === $merchantId
            && (int) $channel->channel_mode === RouteConstant::CHANNEL_MODE_SELF;
        if (!$channel
            || !$merchant
            || (int) $channel->status !== CommonConstant::STATUS_ENABLED
            || (int) $channel->pay_type_id !== $payTypeId
            || (!$isPlatformChannel && !$isMerchantChannel)) {
            throw new ValidationException('身份流程对应的商户或支付通道不存在');
        }

        $plugin = $this->paymentPluginManager->createByChannel($channel, $payTypeId);
        if (!$plugin instanceof PaymentIdentityRequirementInterface) {
            throw new ValidationException('当前支付插件不支持身份授权续跑');
        }
        $requirement = $plugin->identityRequirement($this->buildPluginPayPayload($input, $merchant));
        if ($requirement === null) {
            throw new ValidationException('支付通道身份配置已变化，请重新发起支付');
        }
        $requirement = $this->validateRequirement($requirement);

        $expected = trim((string) ($context['requirement_fingerprint'] ?? ''));
        $actual = $this->requirementFingerprint($requirement);
        if ($expected !== '' && !hash_equals($expected, $actual)) {
            throw new ValidationException('支付通道身份配置已变化，请重新发起支付');
        }

        return $requirement;
    }

    /**
     * 验证并标准化插件声明的身份需求。
     *
     * @param array<string, mixed> $requirement 原始身份需求
     * @return array<string, mixed> 标准身份需求
     */
    private function validateRequirement(array $requirement): array
    {
        foreach (['provider', 'product', 'channel_product', 'auth_type', 'identity_field', 'app_id', 'scope', 'message'] as $field) {
            if (array_key_exists($field, $requirement)) {
                $requirement[$field] = trim((string) $requirement[$field]);
            }
        }

        $authType = (string) ($requirement['auth_type'] ?? '');
        $contract = self::AUTH_CONTRACTS[$authType] ?? null;
        if ($contract === null || ($requirement['provider'] ?? '') !== $contract['provider']) {
            throw new ValidationException('支付插件声明了不支持的身份授权类型', [
                'provider' => (string) ($requirement['provider'] ?? ''),
                'auth_type' => $authType,
            ]);
        }

        if ((string) ($requirement['channel_product'] ?? '') === '') {
            throw new ValidationException('支付插件身份需求缺少 channel_product', [
                'provider' => (string) ($requirement['provider'] ?? ''),
                'product' => (string) ($requirement['product'] ?? ''),
            ]);
        }

        $identityField = (string) ($requirement['identity_field'] ?? '');
        if (!in_array($identityField, $contract['fields'], true)) {
            throw new ValidationException('支付插件身份字段与授权类型不匹配', [
                'auth_type' => $authType,
                'identity_field' => $identityField,
            ]);
        }

        $aliases = $requirement['identity_aliases'] ?? [];
        if (!is_array($aliases)) {
            throw new ValidationException('支付插件 identity_aliases 必须是数组');
        }
        $aliases = array_values(array_unique(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            $aliases
        ))));
        foreach ($aliases as $alias) {
            if ($alias === $identityField || !in_array($alias, $contract['fields'], true)) {
                throw new ValidationException('支付插件身份字段别名无效', [
                    'identity_field' => $identityField,
                    'identity_alias' => $alias,
                ]);
            }
        }
        $requirement['identity_aliases'] = $aliases;
        unset($requirement['_strict_identity_field']);

        if (in_array($authType, ['wechat_oauth', 'mini_program', 'alipay_oauth', 'alipay_mini'], true)
            && ($requirement['app_id'] ?? '') === '') {
            throw new ValidationException('身份授权缺少应用 AppID', ['auth_type' => $authType]);
        }
        if (in_array($authType, ['wechat_oauth', 'mini_program'], true)
            && trim((string) ($requirement['_app_secret'] ?? '')) === '') {
            throw new ValidationException('微信身份授权缺少 AppSecret', ['auth_type' => $authType]);
        }
        if (in_array($authType, ['alipay_oauth', 'alipay_mini'], true)
            && (array) ($requirement['_alipay_config'] ?? []) === []) {
            throw new ValidationException('支付宝身份授权缺少 SDK 配置', ['auth_type' => $authType]);
        }

        return $requirement;
    }

    /**
     * 计算身份需求中授权配置的稳定摘要。
     *
     * @param array<string, mixed> $requirement 身份需求
     * @return string 配置摘要
     */
    private function requirementFingerprint(array $requirement): string
    {
        $stable = [];
        foreach ([
            'provider',
            'product',
            'channel_product',
            'auth_type',
            'identity_field',
            'identity_aliases',
            'app_id',
            'scope',
            'mini_path',
            'mini_launch_type',
            'env_version',
        ] as $field) {
            $stable[$field] = $requirement[$field] ?? null;
        }

        return hash('sha256', json_encode($stable, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * 只保留第三方错误诊断字段，避免令牌和用户身份进入异常上下文。
     *
     * @param array<string, mixed> $payload 第三方响应
     * @return array<string, string|int> 安全诊断摘要
     */
    private function providerErrorSummary(array $payload): array
    {
        $summary = [];
        foreach (['code', 'sub_code', 'msg', 'sub_msg', 'errcode', 'errmsg', 'request_id', 'trace_id'] as $field) {
            $value = $payload[$field] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') {
                $summary[$field] = is_int($value) ? $value : trim((string) $value);
            }
        }

        return $summary;
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
     * 按插件声明严格选择可回填身份，避免跨平台或把小程序 openid 冒充公众号 openid。
     *
     * @param array<string, mixed> $requirement 插件身份需求
     * @param array<string, string> $identity 已取得身份
     * @return string 匹配的身份值
     */
    private function identityValueForRequirement(array $requirement, array $identity): string
    {
        $field = trim((string) ($requirement['identity_field'] ?? ''));
        if ($field === '') {
            return '';
        }

        $candidates = [$field];
        foreach ((array) ($requirement['identity_aliases'] ?? []) as $alias) {
            $alias = trim((string) $alias);
            if ($alias !== '' && !in_array($alias, $candidates, true)) {
                $candidates[] = $alias;
            }
        }

        foreach ($candidates as $candidate) {
            $value = trim((string) ($identity[$candidate] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
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
        $siteUrl = rtrim(trim((string) sys_config('site_url')), '/');
        $scheme = strtolower((string) parse_url($siteUrl, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true) || filter_var($siteUrl, FILTER_VALIDATE_URL) === false) {
            throw new ValidationException('站点地址配置无效，无法生成身份授权回调地址');
        }

        return $siteUrl . '/' . ltrim($path, '/');
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
