<?php

namespace app\common\contracts;

use app\common\contracts\PayPluginInterface;
use support\Log;
use Webman\Http\Request;

/**
 * 支付插件抽象基类
 * 
 * 提供通用的环境检测、HTTP请求、日志记录等功能
 */
abstract class AbstractPayPlugin implements PayPluginInterface
{
    /**
     * 环境常量
     */
    const ENV_PC = 'PC';
    const ENV_H5 = 'H5';
    const ENV_WECHAT = 'WECHAT';
    const ENV_ALIPAY_CLIENT = 'ALIPAY_CLIENT';
    
    /**
     * 当前支付方式
     */
    protected string $currentMethod = '';
    
    /**
     * 当前通道配置
     */
    protected array $currentConfig = [];
    
    /**
     * 初始化插件（切换到指定支付方式）
     */
    public function init(string $methodCode, array $channelConfig): void
    {
        if (!in_array($methodCode, static::getSupportedMethods())) {
            throw new \RuntimeException("插件不支持支付方式：{$methodCode}");
        }
        $this->currentMethod = $methodCode;
        $this->currentConfig = $channelConfig;
    }
    
    /**
     * 检测请求环境
     * 
     * @param Request $request
     * @return string 环境代码（PC/H5/WECHAT/ALIPAY_CLIENT）
     */
    protected function detectEnvironment(Request $request): string
    {
        $ua = strtolower($request->header('User-Agent', ''));
        
        // 支付宝客户端
        if (strpos($ua, 'alipayclient') !== false) {
            return self::ENV_ALIPAY_CLIENT;
        }
        
        // 微信内浏览器
        if (strpos($ua, 'micromessenger') !== false) {
            return self::ENV_WECHAT;
        }
        
        // 移动设备
        $mobileKeywords = ['mobile', 'android', 'iphone', 'ipad', 'ipod', 'blackberry', 'windows phone'];
        foreach ($mobileKeywords as $keyword) {
            if (strpos($ua, $keyword) !== false) {
                return self::ENV_H5;
            }
        }
        
        // 默认PC
        return self::ENV_PC;
    }
    
    /**
     * 根据环境选择产品
     * 
     * @param array $enabledProducts 已启用的产品列表
     * @param string $env 环境代码
     * @param array $allProducts 所有可用产品（产品代码 => 产品名称）
     * @return string|null 选择的产品代码，如果没有匹配则返回null
     */
    protected function selectProductByEnv(array $enabledProducts, string $env, array $allProducts): ?string
    {
        // 环境到产品的映射规则（子类可以重写此方法实现自定义逻辑）
        $envProductMap = [
            self::ENV_PC => ['pc', 'web', 'wap'],
            self::ENV_H5 => ['h5', 'wap', 'mobile'],
            self::ENV_WECHAT => ['jsapi', 'wechat', 'h5'],
            self::ENV_ALIPAY_CLIENT => ['app', 'alipay', 'h5'],
        ];
        
        $candidates = $envProductMap[$env] ?? [];
        
        // 优先匹配已启用的产品
        foreach ($candidates as $candidate) {
            if (in_array($candidate, $enabledProducts)) {
                return $candidate;
            }
        }
        
        // 如果没有匹配，返回第一个已启用的产品
        if (!empty($enabledProducts)) {
            return $enabledProducts[0];
        }
        
        return null;
    }
    
    /**
     * HTTP POST JSON请求
     * 
     * @param string $url 请求URL
     * @param array $data 请求数据
     * @param array $headers 额外请求头
     * @return array 响应数据（已解析JSON）
     */
    protected function httpPostJson(string $url, array $data, array $headers = []): array
    {
        $headers['Content-Type'] = 'application/json';
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data, JSON_UNESCAPED_UNICODE));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->buildHeaders($headers));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            throw new \RuntimeException("HTTP请求失败：{$error}");
        }
        
        if ($httpCode !== 200) {
            throw new \RuntimeException("HTTP请求失败，状态码：{$httpCode}");
        }
        
        $result = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException("JSON解析失败：" . json_last_error_msg());
        }
        
        return $result;
    }
    
    /**
     * HTTP POST Form请求
     * 
     * @param string $url 请求URL
     * @param array $data 请求数据
     * @param array $headers 额外请求头
     * @return string 响应内容
     */
    protected function httpPostForm(string $url, array $data, array $headers = []): string
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->buildHeaders($headers));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            throw new \RuntimeException("HTTP请求失败：{$error}");
        }
        
        if ($httpCode !== 200) {
            throw new \RuntimeException("HTTP请求失败，状态码：{$httpCode}");
        }
        
        return $response;
    }
    
    /**
     * 构建HTTP请求头数组
     * 
     * @param array $headers 请求头数组
     * @return array
     */
    private function buildHeaders(array $headers): array
    {
        $result = [];
        foreach ($headers as $key => $value) {
            $result[] = "{$key}: {$value}";
        }
        return $result;
    }
    
    /**
     * 记录请求日志
     * 
     * @param string $action 操作名称
     * @param array $data 请求数据
     * @param mixed $response 响应数据
     * @return void
     */
    protected function logRequest(string $action, array $data, $response = null): void
    {
        $logData = [
            'plugin' => static::getCode(),
            'method' => $this->currentMethod,
            'action' => $action,
            'request' => $data,
            'response' => $response,
            'time' => date('Y-m-d H:i:s'),
        ];
        
        Log::debug('支付插件请求', $logData);
    }
}

