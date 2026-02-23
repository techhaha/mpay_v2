<?php

namespace app\services;

use app\common\base\BaseService;
use support\Cache;
use Webman\Captcha\CaptchaBuilder;

/**
 * 验证码服务
 *
 * 使用 Redis 缓存验证码信息，支持防重放和错误次数限制
 */
class CaptchaService extends BaseService
{
    private const CACHE_PREFIX = 'captcha_';
    private const EXPIRE_SECONDS = 300; // 5 分钟
    private const MAX_ERROR_TIMES = 5;

    /**
     * 生成验证码，返回 captchaId 和 base64 图片
     *
     * @return array ['captchaId' => string, 'image' => string]
     */
    public function generate(): array
    {
        // 使用 webman/captcha 生成验证码图片和文本
        $builder = new CaptchaBuilder;
        // 适配前端登录表单尺寸：110x30
        $builder->build(110, 30);

        $code = strtolower($builder->getPhrase());
        $id = bin2hex(random_bytes(16));

        $payload = [
            'code' => $code,
            'created_at' => time(),
            'error_times' => 0,
            'used' => false,
        ];

        Cache::set($this->buildKey($id), $payload, self::EXPIRE_SECONDS);

        // 获取图片二进制并转为 base64
        $imgContent = $builder->get();
        $base64 = base64_encode($imgContent ?: '');

        return [
            'captchaId' => $id,
            'image' => 'data:image/jpeg;base64,' . $base64,
        ];
    }

    /**
     * 校验验证码（基于 captchaId + code）
     *
     * @param string|null $id 验证码ID
     * @param string|null $code 用户输入的验证码
     * @return bool
     */
    public function validate(?string $id, ?string $code): bool
    {
        if ($id === null || $id === '' || $code === null || $code === '') {
            return false;
        }

        $key = $this->buildKey($id);
        $data = Cache::get($key);
        if (!$data || !is_array($data)) {
            return false;
        }

        // 已使用或错误次数过多
        if (!empty($data['used']) || ($data['error_times'] ?? 0) >= self::MAX_ERROR_TIMES) {
            Cache::delete($key);
            return false;
        }

        $expect = (string)($data['code'] ?? '');
        if ($expect === '' || strtolower($code) !== strtolower($expect)) {
            $data['error_times'] = ($data['error_times'] ?? 0) + 1;
            Cache::set($key, $data, self::EXPIRE_SECONDS);
            return false;
        }

        // 标记为已使用，防重放
        $data['used'] = true;
        Cache::set($key, $data, self::EXPIRE_SECONDS);

        return true;
    }

    /**
     * 构建缓存键
     */
    private function buildKey(string $id): string
    {
        return self::CACHE_PREFIX . $id;
    }
}

