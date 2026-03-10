<?php

namespace app\http\api\middleware;

use Webman\MiddlewareInterface;
use Webman\Http\Request;
use Webman\Http\Response;
use app\exceptions\UnauthorizedException;
use app\repositories\MerchantAppRepository;

/**
 * OpenAPI 签名认证中间件
 * 
 * 验证 AppId + 签名
 */
class EpayAuthMiddleware implements MiddlewareInterface
{
    protected MerchantAppRepository $merchantAppRepository;
    
    public function __construct()
    {
        // 延迟加载，避免循环依赖
        $this->merchantAppRepository = new MerchantAppRepository();
    }
    
    public function process(Request $request, callable $handler): Response
    {
        $appId = $request->header('X-App-Id', '') ?: ($request->post('app_id', '') ?: $request->get('app_id', ''));
        $timestamp = $request->header('X-Timestamp', '') ?: ($request->post('timestamp', '') ?: $request->get('timestamp', ''));
        $nonce = $request->header('X-Nonce', '') ?: ($request->post('nonce', '') ?: $request->get('nonce', ''));
        $signature = $request->header('X-Signature', '') ?: ($request->post('signature', '') ?: $request->get('signature', ''));
        
        if (empty($appId) || empty($timestamp) || empty($nonce) || empty($signature)) {
            throw new UnauthorizedException('缺少认证参数');
        }
        
        // 验证时间戳（5分钟内有效）
        if (abs(time() - (int)$timestamp) > 300) {
            throw new UnauthorizedException('请求已过期');
        }
        
        // 查询应用
        $app = $this->merchantAppRepository->findByAppId($appId);
        if (!$app) {
            throw new UnauthorizedException('应用不存在或已禁用');
        }
        
        // 验证签名
        $method = $request->method();
        $path = $request->path();
        $body = $request->rawBody();
        $bodySha256 = hash('sha256', $body);
        
        $signString = "app_id={$appId}&timestamp={$timestamp}&nonce={$nonce}&method={$method}&path={$path}&body_sha256={$bodySha256}";
        $expectedSignature = hash_hmac('sha256', $signString, $app->app_secret);
        
        if (!hash_equals($expectedSignature, $signature)) {
            throw new UnauthorizedException('签名验证失败');
        }
        
        // 将应用信息注入到请求对象
        $request->app = $app;
        $request->merchantId = $app->merchant_id;
        $request->appId = $app->id;
        
        return $handler($request);
    }
}

